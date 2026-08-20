"""BiRefNet segmentation: full precision, batched 8-16, session warmed once.

Frames are segmented at ``concurrency.segmentation_px`` (1024) — never at
full resolution — and the predicted alpha is upscaled back to source
resolution. Open-weave materials (tulle/lace/mesh) additionally go through
the trimap matting path (refined at segmentation resolution, before the
upscale).
"""

from __future__ import annotations

import os
from collections.abc import Sequence
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass

import numpy as np
import numpy.typing as npt
import pyvips

from photo_pipeline.segment.onnx_session import get_session
from photo_pipeline.segment.trimap import needs_matting, refine_alpha
from photo_pipeline.settings import MaterialFamily, Settings

_UPSCALE_THREADS = os.cpu_count() or 1


@dataclass(frozen=True)
class SegmentInput:
    """One frame ready for segmentation.

    ``rgb`` may be the full-resolution frame or an already-downscaled copy
    (the stage-1 workers hand over seg-resolution frames to keep IPC cheap);
    ``out_size`` is the (width, height) the alpha must be upscaled to —
    defaults to the rgb's own size.
    """

    rgb: npt.NDArray[np.uint8]
    material_family: MaterialFamily | None = None
    out_size: tuple[int, int] | None = None


def _to_vips(array: npt.NDArray[np.uint8], bands: int) -> pyvips.Image:
    return pyvips.Image.new_from_memory(
        np.ascontiguousarray(array).tobytes(),
        array.shape[1],
        array.shape[0],
        bands,
        "uchar",
    )


def resize_for_segmentation(
    rgb: npt.NDArray[np.uint8], seg_px: int, kernel: str
) -> npt.NDArray[np.uint8]:
    """Squash the frame to seg_px x seg_px — segmentation NEVER runs at
    full resolution (the alpha is stretched back afterwards)."""
    if rgb.shape[0] == seg_px and rgb.shape[1] == seg_px:
        return rgb
    image = _to_vips(rgb, 3)
    resized = image.resize(seg_px / image.width, vscale=seg_px / image.height, kernel=kernel)
    return np.ndarray(
        buffer=resized.write_to_memory(),
        dtype=np.uint8,
        shape=(seg_px, seg_px, 3),
    ).copy()


def upscale_alpha(
    alpha_small: npt.NDArray[np.uint8], width: int, height: int, kernel: str
) -> npt.NDArray[np.uint8]:
    """Upscale the seg-resolution alpha back to source resolution."""
    if (alpha_small.shape[1], alpha_small.shape[0]) == (width, height):
        return alpha_small
    image = _to_vips(alpha_small, 1)
    resized = image.resize(width / image.width, vscale=height / image.height, kernel=kernel)
    return np.ndarray(
        buffer=resized.write_to_memory(), dtype=np.uint8, shape=(height, width)
    ).copy()


class BiRefNetSegmenter:
    """Holds the process-wide warmed session and runs batched inference."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        seg = settings.segmentation
        self._session = get_session(
            seg.model_path, seg.intra_op_threads, settings.concurrency.segmentation_px
        )
        self._input_name = self._session.get_inputs()[0].name

    def _make_batch(
        self, smalls: Sequence[npt.NDArray[np.uint8]], seg_px: int
    ) -> npt.NDArray[np.float32]:
        """Normalise + HWC->NCHW straight into the batch tensor: per-channel
        fused multiply/subtract writes contiguous planes (an order of
        magnitude faster than transpose-and-stack on big batches)."""
        seg = self._settings.segmentation
        std = np.asarray(seg.normalize_std, dtype=np.float32)
        inv = (1.0 / (255.0 * std)).astype(np.float32)
        offset = (np.asarray(seg.normalize_mean, dtype=np.float32) / std).astype(np.float32)
        batch = np.empty((len(smalls), 3, seg_px, seg_px), dtype=np.float32)
        for index, small in enumerate(smalls):
            for channel in range(3):
                np.multiply(
                    small[..., channel], inv[channel], out=batch[index, channel],
                    casting="unsafe",
                )
                batch[index, channel] -= offset[channel]
        return batch

    def segment_batch(self, frames: Sequence[SegmentInput]) -> list[npt.NDArray[np.uint8]]:
        """Segment a batch; returns per-frame uint8 alpha at out_size."""
        conc = self._settings.concurrency
        kernel = self._settings.export.downscale_kernel
        seg_px = conc.segmentation_px
        alphas: list[npt.NDArray[np.uint8]] = []
        for start in range(0, len(frames), conc.onnx_batch_max):
            chunk = frames[start : start + conc.onnx_batch_max]
            small = [resize_for_segmentation(frame.rgb, seg_px, kernel) for frame in chunk]
            batch = self._make_batch(small, seg_px)
            (logits,) = self._session.run(None, {self._input_name: batch})
            probs = 1.0 / (1.0 + np.exp(-logits[:, 0, :, :]))

            def finish(
                frame: SegmentInput,
                rgb_small: npt.NDArray[np.uint8],
                prob: npt.NDArray[np.floating],
            ) -> npt.NDArray[np.uint8]:
                alpha_small = (prob * 255.0).round().astype(np.uint8)
                if needs_matting(frame.material_family, self._settings.segmentation):
                    alpha_small = refine_alpha(
                        rgb_small, alpha_small, self._settings.segmentation
                    )
                width, height = frame.out_size or (frame.rgb.shape[1], frame.rgb.shape[0])
                return upscale_alpha(alpha_small, width, height, kernel)

            # pyvips releases the GIL: upscale the chunk's alphas in parallel.
            with ThreadPoolExecutor(max_workers=min(len(chunk), _UPSCALE_THREADS)) as tpe:
                alphas.extend(tpe.map(finish, chunk, small, probs))
        return alphas
