from __future__ import annotations

import asyncio
from pathlib import Path

import numpy as np
import pytest

from photo_pipeline.segment import onnx_session
from photo_pipeline.segment.birefnet import (
    BiRefNetSegmenter,
    SegmentInput,
    resize_for_segmentation,
    upscale_alpha,
)
from photo_pipeline.segment.standin import write_standin_model
from photo_pipeline.segment.trimap import build_trimap, needs_matting, refine_alpha
from photo_pipeline.settings import MaterialFamily, Settings


@pytest.fixture(scope="module")
def seg_settings(tmp_path_factory: pytest.TempPathFactory) -> Settings:
    model = tmp_path_factory.mktemp("model") / "standin.onnx"
    write_standin_model(model)
    base = Settings()
    return Settings.model_validate(
        {**base.model_dump(), "segmentation": {"model_path": str(model)}}
    )


def _frame(width: int = 2000, height: int = 1500) -> np.ndarray:
    frame = np.empty((height, width, 3), dtype=np.uint8)
    frame[:] = (0xED, 0xEA, 0xE5)
    frame[300:1200, 500:1500] = (28, 36, 88)  # dark garment
    return frame


def test_session_created_exactly_once(seg_settings: Settings) -> None:
    before = onnx_session.creation_count
    first = BiRefNetSegmenter(seg_settings)
    second = BiRefNetSegmenter(seg_settings)
    assert onnx_session.creation_count == before + 1
    assert first._session is second._session


def test_segmentation_runs_at_1024_and_upscales(seg_settings: Settings) -> None:
    seg_px = seg_settings.concurrency.segmentation_px
    assert seg_px == 1024
    small = resize_for_segmentation(_frame(), seg_px, "lanczos3")
    assert small.shape == (seg_px, seg_px, 3)
    up = upscale_alpha(np.full((seg_px, seg_px), 200, np.uint8), 2000, 1500, "lanczos3")
    assert up.shape == (1500, 2000)

    segmenter = BiRefNetSegmenter(seg_settings)
    (alpha,) = segmenter.segment_batch([SegmentInput(rgb=_frame())])
    assert alpha.shape == (1500, 2000)
    # Garment core is foreground, backdrop corner is background.
    assert alpha[700, 1000] > 200
    assert alpha[50, 50] < 40


def test_batching_respects_configured_size(seg_settings: Settings) -> None:
    assert seg_settings.concurrency.onnx_batch_min >= 8
    assert seg_settings.concurrency.onnx_batch_max <= 16
    segmenter = BiRefNetSegmenter(seg_settings)
    frames = [SegmentInput(rgb=_frame(640, 480)) for _ in range(18)]  # 2 chunks
    alphas = segmenter.segment_batch(frames)
    assert len(alphas) == 18
    assert all(a.shape == (480, 640) for a in alphas)


def test_matting_path_selected_by_material() -> None:
    settings = Settings().segmentation
    assert needs_matting(MaterialFamily.TULLE, settings)
    assert needs_matting(MaterialFamily.LACE, settings)
    assert needs_matting(MaterialFamily.MESH, settings)
    assert not needs_matting(MaterialFamily.KNIT, settings)
    assert not needs_matting(None, settings)


def test_trimap_and_refinement_recover_semi_transparency() -> None:
    settings = Settings().segmentation
    # Backdrop | 60px semi-transparent tulle band | solid garment.
    width, height = 400, 300
    rgb = np.empty((height, width, 3), dtype=np.uint8)
    rgb[:] = (0xED, 0xEA, 0xE5)
    rgb[:, 200:400] = (28, 36, 88)
    rgb[:, 140:200] = (150, 150, 170)  # tulle: halfway to the backdrop
    # Coarse net is confident on garment and backdrop but unsure on the
    # open-weave band — exactly the case the trimap path exists for.
    alpha = np.zeros((height, width), dtype=np.uint8)
    alpha[:, 200:400] = 255
    alpha[:, 140:200] = 180

    trimap = build_trimap(alpha, settings)
    assert set(np.unique(trimap)) <= {0, 128, 255}

    refined = refine_alpha(rgb, alpha, settings)
    band = refined[150, 150:190]
    assert 40 < band.mean() < 220  # semi-transparent, no longer binary
    assert refined[150, 380] == 255  # solid garment stays solid
    assert refined[150, 20] == 0  # backdrop stays background


def test_stage1_bounded_queue_end_to_end(
    seg_settings: Settings, tmp_path: Path
) -> None:
    import pyvips

    from photo_pipeline.segment.stage1 import Stage1Result, run_stage1

    paths = []
    for index in range(5):
        frame = _frame(1600, 1200)
        path = tmp_path / f"f{index}.jpg"
        pyvips.Image.new_from_memory(frame.tobytes(), 1600, 1200, 3, "uchar").copy(
            interpretation="srgb"
        ).jpegsave(str(path))
        paths.append(path)

    seen: list[Stage1Result] = []

    async def consume(result: Stage1Result) -> None:
        seen.append(result)

    stats = asyncio.run(
        run_stage1(paths, seg_settings, io_concurrency=2, consume=consume, max_workers=2)
    )
    assert stats.frames == 5
    assert len(seen) == 5
    assert all(r.alpha.shape == (1200, 1600) for r in seen)
    assert all(r.source_px == (1600, 1200) for r in seen)
