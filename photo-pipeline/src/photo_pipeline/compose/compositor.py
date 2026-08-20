"""Real-pixel compositor (Tier 0) — one input, four output files.

From a single RGBA cutout this produces, in one invocation:
zoom master, display master, and both 1:1 crops. Geometry is computed in
code, never by a model (invariant 1); the cutout is never upscaled above
1.0 of source resolution (invariant 3 — the zoom master shrinks instead,
keeping 6:7 and the relative anchor, recording ``zoom_native_px``).

pyvips stays lazy end-to-end: one write per file at the end.
"""

from __future__ import annotations

import math
from dataclasses import dataclass
from pathlib import Path

import numpy as np
import numpy.typing as npt
import pyvips

from photo_pipeline.compose.bbox import GarmentBbox, compute_garment_bbox
from photo_pipeline.errors import BadSource
from photo_pipeline.settings import ModeName, Settings


@dataclass(frozen=True)
class CanvasGeometry:
    """Resolved canvas + 1:1 window geometry for one master."""

    width: int
    height: int
    window_y: int

    @property
    def window_side(self) -> int:
        # 1:1 window: full width.
        return self.width

    @property
    def window_centre_y(self) -> float:
        return self.window_y + self.window_side / 2


@dataclass(frozen=True)
class CompositeResult:
    zoom_master: Path
    zoom_crop: Path
    display_master: Path
    display_crop: Path
    zoom_geometry: CanvasGeometry
    display_geometry: CanvasGeometry
    # Width of the shrunken zoom master when the no-upscale rule fired;
    # None when the full-size zoom master was produced.
    zoom_native_px: int | None
    # Source-cutout -> zoom-master scale actually applied (never > 1.0).
    scale_factor: float
    bbox: GarmentBbox


def zoom_scale_for_bbox(bbox_height_px: int, mode: ModeName, settings: Settings) -> float:
    """Scale that makes a bbox fill the full-size zoom window at the mode
    fill ratio. Front/back of one barcode share the LARGER bbox's scale
    (invariant 5) — compute with the larger bbox and store on the barcode.
    """
    target = settings.fill_ratios.for_mode(mode) * settings.canvas.zoom_width
    return target / bbox_height_px


def _window_y(height: int, settings: Settings) -> int:
    canvas = settings.canvas
    return round(height * canvas.window_y_offset_num / canvas.window_y_offset_den)


def _full_zoom_geometry(settings: Settings) -> CanvasGeometry:
    canvas = settings.canvas
    return CanvasGeometry(
        width=canvas.zoom_width,
        height=canvas.zoom_height,
        window_y=_window_y(canvas.zoom_height, settings),
    )


def _shrunken_zoom_geometry(
    bbox_height_px: int, mode: ModeName, settings: Settings
) -> CanvasGeometry:
    """Shrink the zoom master so scale 1.0 hits the mode fill ratio, keeping
    6:7 exactly (width rounded up to a multiple of aspect_w so the height is
    integral and the ratio exact)."""
    canvas = settings.canvas
    fill = settings.fill_ratios.for_mode(mode)
    ideal_width = bbox_height_px / fill
    width = math.ceil(ideal_width / canvas.aspect_w) * canvas.aspect_w
    height = width * canvas.aspect_h // canvas.aspect_w
    return CanvasGeometry(width=width, height=height, window_y=_window_y(height, settings))


def _background_canvas(geometry: CanvasGeometry, settings: Settings) -> pyvips.Image:
    r, g, b = settings.canvas.background_rgb
    return (
        pyvips.Image.black(geometry.width, geometry.height, bands=3)
        .new_from_image([r, g, b])
        .cast("uchar")
        .copy(interpretation="srgb")
    )


def _scaled_cutout(cutout: pyvips.Image, scale: float, settings: Settings) -> pyvips.Image:
    if scale == 1.0:
        return cutout
    return (
        cutout.premultiply()
        .resize(scale, kernel=settings.export.downscale_kernel)
        .unpremultiply()
        .cast("uchar")
    )


def _compose_master(
    cutout: pyvips.Image,
    bbox: GarmentBbox,
    scale: float,
    geometry: CanvasGeometry,
    settings: Settings,
) -> pyvips.Image:
    """Anchor the scaled garment bbox centre at (0.5 width, window centre)."""
    scaled = _scaled_cutout(cutout, scale, settings)
    target_x = geometry.width * settings.canvas.anchor_x_ratio
    target_y = geometry.window_centre_y
    paste_x = round(target_x - bbox.centre_x * scale)
    paste_y = round(target_y - bbox.centre_y * scale)
    overlay = scaled.embed(
        paste_x, paste_y, geometry.width, geometry.height, extend="background"
    )
    background = _background_canvas(geometry, settings)
    return background.composite2(overlay, "over").flatten().cast("uchar")


def _save_jpeg(image: pyvips.Image, path: Path, settings: Settings) -> None:
    """One write per file. JPEG q92, 4:4:4 chroma; GPS/serial/owner metadata
    stripped, ICC profile kept."""
    keep = pyvips.enums.ForeignKeep.ICC if settings.export.keep_icc else 0
    image.jpegsave(
        str(path),
        Q=settings.export.jpeg_quality,
        subsample_mode=settings.export.jpeg_subsample_mode,
        keep=keep,
    )


def compose_view(
    cutout: pyvips.Image,
    mode: ModeName,
    settings: Settings,
    out_dir: Path,
    stem: str,
    shared_scale: float | None = None,
) -> CompositeResult:
    """Produce all four files for one view from one cutout (invariant 2).

    ``shared_scale`` is the per-barcode scale (invariant 5), computed via
    :func:`zoom_scale_for_bbox` from the larger of the front/back bboxes;
    when None, this view's own bbox sets the scale.
    """
    if cutout.bands != 4:  # noqa: PLR2004 - RGBA is structural, not a tunable
        raise BadSource(f"cutout must be RGBA, got {cutout.bands} bands")

    alpha: npt.NDArray[np.uint8] = np.ndarray(
        buffer=cutout[3].write_to_memory(),
        dtype=np.uint8,
        shape=(cutout.height, cutout.width),
    )
    bbox = compute_garment_bbox(alpha, settings.bbox)

    requested = (
        shared_scale
        if shared_scale is not None
        else zoom_scale_for_bbox(bbox.height, mode, settings)
    )

    # Invariant 3: never upscale above 1.0 — shrink the zoom master instead.
    if requested > 1.0:
        scale = 1.0
        zoom_geometry = _shrunken_zoom_geometry(bbox.height, mode, settings)
        zoom_native_px: int | None = zoom_geometry.width
    else:
        scale = requested
        zoom_geometry = _full_zoom_geometry(settings)
        zoom_native_px = None

    # Render the zoom master once and keep it in memory: all four outputs
    # (its own save, both crops, the display downscale) derive from this one
    # buffer instead of re-running the resize/composite graph per sink.
    zoom = _compose_master(cutout, bbox, scale, zoom_geometry, settings).copy_memory()

    # Display master: derived from the same lazy zoom pipeline by a pure
    # lanczos3 downscale (never an upscale — a shrunken zoom master smaller
    # than the display target is served at its native size).
    canvas = settings.canvas
    if zoom_geometry.width <= canvas.display_width:
        display_geometry = zoom_geometry
        display = zoom
    else:
        display_geometry = CanvasGeometry(
            width=canvas.display_width,
            height=canvas.display_height,
            window_y=_window_y(canvas.display_height, settings),
        )
        display = zoom.resize(
            display_geometry.width / zoom_geometry.width,
            vscale=display_geometry.height / zoom_geometry.height,
            kernel=settings.export.downscale_kernel,
        ).copy_memory()  # rendered once; its save and its crop reuse the buffer
        if (display.width, display.height) != (display_geometry.width, display_geometry.height):
            raise BadSource(
                f"display resize produced {display.width}x{display.height}, "
                f"expected {display_geometry.width}x{display_geometry.height}"
            )

    zoom_crop = zoom.extract_area(
        0, zoom_geometry.window_y, zoom_geometry.window_side, zoom_geometry.window_side
    )
    display_crop = display.extract_area(
        0,
        display_geometry.window_y,
        display_geometry.window_side,
        display_geometry.window_side,
    )

    out_dir.mkdir(parents=True, exist_ok=True)
    paths = {
        "zoom_master": out_dir / f"{stem}_zoom.jpg",
        "zoom_crop": out_dir / f"{stem}_zoom_crop.jpg",
        "display_master": out_dir / f"{stem}_display.jpg",
        "display_crop": out_dir / f"{stem}_display_crop.jpg",
    }
    _save_jpeg(zoom, paths["zoom_master"], settings)
    _save_jpeg(zoom_crop, paths["zoom_crop"], settings)
    _save_jpeg(display, paths["display_master"], settings)
    _save_jpeg(display_crop, paths["display_crop"], settings)

    return CompositeResult(
        zoom_master=paths["zoom_master"],
        zoom_crop=paths["zoom_crop"],
        display_master=paths["display_master"],
        display_crop=paths["display_crop"],
        zoom_geometry=zoom_geometry,
        display_geometry=display_geometry,
        zoom_native_px=zoom_native_px,
        scale_factor=scale,
        bbox=bbox,
    )
