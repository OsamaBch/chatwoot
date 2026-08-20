"""Trimap matting path for open-weave fabrics (tulle / lace / mesh).

The coarse BiRefNet alpha is hardened into a trimap (confident foreground,
confident background, unknown band), and the unknown band is re-solved
from pixel colour under a two-colour model: alpha of an unknown pixel is
its projection onto the fg-bg colour axis. Against a uniform studio
backdrop this recovers semi-transparent weave that a hard mask destroys.
"""

from __future__ import annotations

import numpy as np
import numpy.typing as npt
import pyvips

from photo_pipeline.settings import MaterialFamily, SegmentationSettings

_FG = 255
_UNKNOWN = 128
_BG = 0


def needs_matting(material: MaterialFamily | None, settings: SegmentationSettings) -> bool:
    """Trimap matting path is selected by material_family."""
    return material is not None and material in settings.matting_materials


def _rank(mask: npt.NDArray[np.uint8], radius_px: int, *, erode: bool) -> npt.NDArray[np.uint8]:
    """Grayscale erosion/dilation via a pyvips rank filter."""
    side = 2 * radius_px + 1
    image = pyvips.Image.new_from_memory(
        np.ascontiguousarray(mask).tobytes(), mask.shape[1], mask.shape[0], 1, "uchar"
    )
    index = 0 if erode else side * side - 1
    ranked = image.rank(side, side, index)
    return np.ndarray(
        buffer=ranked.write_to_memory(), dtype=np.uint8, shape=mask.shape
    ).copy()


def build_trimap(
    alpha: npt.NDArray[np.uint8], settings: SegmentationSettings
) -> npt.NDArray[np.uint8]:
    """0 = background, 128 = unknown, 255 = foreground."""
    confident = settings.trimap_confident_alpha
    fg = (alpha >= confident).astype(np.uint8) * 255
    bg = (alpha <= 255 - confident).astype(np.uint8) * 255
    fg_core = _rank(fg, settings.trimap_erode_px, erode=True)
    bg_core = _rank(bg, settings.trimap_dilate_px, erode=True)
    trimap = np.full(alpha.shape, _UNKNOWN, dtype=np.uint8)
    trimap[fg_core == _FG] = _FG
    trimap[bg_core == _FG] = _BG
    return trimap


def refine_alpha(
    rgb: npt.NDArray[np.uint8],
    alpha: npt.NDArray[np.uint8],
    settings: SegmentationSettings,
) -> npt.NDArray[np.uint8]:
    """Re-solve the unknown band of the trimap from colour.

    alpha_u = clamp( (p - bg_mean) . (fg_mean - bg_mean) / |fg-bg|^2 ),
    computed per unknown pixel. Confident regions keep 0/255.
    """
    trimap = build_trimap(alpha, settings)
    unknown = trimap == _UNKNOWN
    if not bool(unknown.any()):
        return alpha
    fg_mask = trimap == _FG
    bg_mask = trimap == _BG
    if not bool(fg_mask.any()) or not bool(bg_mask.any()):
        return alpha

    pixels = rgb.astype(np.float32)
    fg_mean = pixels[fg_mask].mean(axis=0)
    bg_mean = pixels[bg_mask].mean(axis=0)
    axis = fg_mean - bg_mean
    denom = float(axis @ axis)
    if denom == 0.0:
        return alpha

    projected = ((pixels[unknown] - bg_mean) @ axis) / denom
    refined = alpha.copy()
    refined[fg_mask] = 255
    refined[bg_mask] = 0
    refined[unknown] = np.clip(projected * 255.0, 0.0, 255.0).astype(np.uint8)
    return refined
