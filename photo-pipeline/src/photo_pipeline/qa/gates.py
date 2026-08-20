"""QA gates 1-4 (cheap, in order, fail fast; rejects store the gate name).

1. Exact dimensions, all four files.
2. Background: edge patches within delta-E of #EDEAE5, low variance.
3. Fill ratio within tolerance of the mode constant.
4. Zero non-background pixels in the edge-clearance band, all crops.

Gates 5-8 (delta-E2000 vs source cutout, SSIM, OCR, sharpness) run only
after these pass and are not part of this foundation.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np
import numpy.typing as npt
import pyvips

from photo_pipeline.compose.compositor import CanvasGeometry, CompositeResult
from photo_pipeline.settings import ModeName, Settings


@dataclass(frozen=True)
class GateFailure:
    gate: str
    detail: str


def _load(path_str: str) -> pyvips.Image:
    return pyvips.Image.new_from_file(path_str)


def _delta_e_map(image: pyvips.Image, settings: Settings) -> pyvips.Image:
    """Per-pixel CIE76 delta-E between the image and the background colour."""
    r, g, b = settings.canvas.background_rgb
    background = (
        pyvips.Image.black(image.width, image.height, bands=3)
        .new_from_image([r, g, b])
        .cast("uchar")
        .copy(interpretation="srgb")
    )
    return image.dE76(background)


def _edge_patches(width: int, height: int, patch: int) -> list[tuple[int, int]]:
    """Corner + edge-midpoint patch origins (8 patches)."""
    cx = (width - patch) // 2
    cy = (height - patch) // 2
    return [
        (0, 0), (width - patch, 0), (0, height - patch), (width - patch, height - patch),
        (cx, 0), (cx, height - patch), (0, cy), (width - patch, cy),
    ]


def gate1_dimensions(result: CompositeResult) -> GateFailure | None:
    zoom_g, disp_g = result.zoom_geometry, result.display_geometry
    expected = {
        result.zoom_master: (zoom_g.width, zoom_g.height),
        result.zoom_crop: (zoom_g.window_side, zoom_g.window_side),
        result.display_master: (disp_g.width, disp_g.height),
        result.display_crop: (disp_g.window_side, disp_g.window_side),
    }
    for path, (want_w, want_h) in expected.items():
        image = _load(str(path))
        if (image.width, image.height) != (want_w, want_h):
            return GateFailure(
                "gate1_dimensions",
                f"{path.name}: {image.width}x{image.height} != {want_w}x{want_h}",
            )
    return None


def gate2_background(result: CompositeResult, settings: Settings) -> GateFailure | None:
    qa = settings.qa
    for path in (result.zoom_master, result.display_master):
        image = _load(str(path))
        delta_e = _delta_e_map(image, settings)
        patches = _edge_patches(image.width, image.height, qa.background_patch_px)
        assert len(patches) == qa.background_edge_patches
        for x, y in patches:
            region = delta_e.extract_area(x, y, qa.background_patch_px, qa.background_patch_px)
            mean = float(region.avg())
            variance = float(region.deviate()) ** 2
            if mean > qa.background_max_delta_e:
                return GateFailure(
                    "gate2_background",
                    f"{path.name}: patch at ({x},{y}) mean dE {mean:.2f} > "
                    f"{qa.background_max_delta_e}",
                )
            if variance > qa.background_max_variance:
                return GateFailure(
                    "gate2_background",
                    f"{path.name}: patch at ({x},{y}) variance {variance:.2f} > "
                    f"{qa.background_max_variance}",
                )
    return None


def _garment_rows(delta_e: pyvips.Image, threshold: float) -> npt.NDArray[np.intp]:
    mask = (delta_e > threshold).cast("uchar")
    _cols, rows = mask.project()
    row_sums: npt.NDArray[np.uint32] = np.ndarray(
        buffer=rows.cast("uint").write_to_memory(),
        dtype=np.uint32,
        shape=(rows.height,),
    )
    return np.flatnonzero(row_sums)


def gate3_fill_ratio(
    result: CompositeResult, mode: ModeName, settings: Settings
) -> GateFailure | None:
    """Measured fill = garment bbox height / square window side, zoom master."""
    image = _load(str(result.zoom_master))
    delta_e = _delta_e_map(image, settings)
    rows = _garment_rows(delta_e, settings.qa.non_background_delta_e)
    if rows.size == 0:
        return GateFailure("gate3_fill_ratio", "no garment pixels found")
    measured = float(rows[-1] - rows[0] + 1) / result.zoom_geometry.window_side
    want = settings.fill_ratios.for_mode(mode)
    if abs(measured - want) > settings.qa.fill_ratio_tolerance:
        return GateFailure(
            "gate3_fill_ratio",
            f"measured {measured:.4f} vs mode constant {want:.4f} "
            f"(tolerance {settings.qa.fill_ratio_tolerance})",
        )
    return None


def _clearance_px(geometry: CanvasGeometry, settings: Settings) -> int:
    """40px at display scale, proportional at zoom (or shrunken) scale."""
    canvas = settings.canvas
    return round(
        settings.canvas.edge_clearance_display_px * geometry.width / canvas.display_width
    )


def gate4_edge_clearance(result: CompositeResult, settings: Settings) -> GateFailure | None:
    checks = (
        (result.zoom_master, result.zoom_geometry),
        (result.zoom_crop, result.zoom_geometry),
        (result.display_master, result.display_geometry),
        (result.display_crop, result.display_geometry),
    )
    threshold = settings.qa.non_background_delta_e
    for path, geometry in checks:
        image = _load(str(path))
        band = _clearance_px(geometry, settings)
        delta_e = _delta_e_map(image, settings)
        w, h = image.width, image.height
        regions = (
            delta_e.extract_area(0, 0, w, band),           # top
            delta_e.extract_area(0, h - band, w, band),    # bottom
            delta_e.extract_area(0, 0, band, h),           # left
            delta_e.extract_area(w - band, 0, band, h),    # right
        )
        for region in regions:
            if float(region.max()) > threshold:
                return GateFailure(
                    "gate4_edge_clearance",
                    f"{path.name}: non-background pixel inside {band}px clearance band",
                )
    return None


def run_gates_1_to_4(
    result: CompositeResult, mode: ModeName, settings: Settings
) -> GateFailure | None:
    """Run gates in order, fail fast; None means all four passed."""
    failure = gate1_dimensions(result)
    if failure is not None:
        return failure
    failure = gate2_background(result, settings)
    if failure is not None:
        return failure
    failure = gate3_fill_ratio(result, mode, settings)
    if failure is not None:
        return failure
    return gate4_edge_clearance(result, settings)
