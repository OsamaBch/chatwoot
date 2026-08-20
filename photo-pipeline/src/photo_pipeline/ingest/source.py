"""Multi-format ingest: CR3, JPEG, PNG, TIFF.

Format is decided by magic bytes, never by extension. Non-CR3 inputs skip
the rawpy develop step entirely (an already-developed image is never
"re-developed") and go straight to orientation normalisation → ICC
normalisation → segmentation input. Any embedded ICC profile is converted
to sRGB on ingest so every downstream delta-E comparison lives in one
colour space; a PNG (or TIFF) alpha channel is flattened onto the
background colour before segmentation, and its presence is logged.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from pathlib import Path

import pyvips
import rawpy

from photo_pipeline.db.models import SourceType
from photo_pipeline.errors import BadSource
from photo_pipeline.ingest.cr3 import (
    extract_preview,
    is_cr3_header,
    read_orientation,
)
from photo_pipeline.logs import log_event
from photo_pipeline.settings import Settings

logger = logging.getLogger(__name__)

_MAGIC_LEN = 16
_JPEG_MAGIC = b"\xff\xd8\xff"
_PNG_MAGIC = b"\x89PNG\r\n\x1a\n"
_TIFF_MAGICS = (b"II*\x00", b"MM\x00*")


def detect_source_type(path: Path) -> SourceType:
    """Classify by magic bytes; anything unrecognised is rejected."""
    try:
        header = path.open("rb").read(_MAGIC_LEN)
    except OSError as exc:
        raise BadSource(f"cannot read {path}: {exc}") from exc
    if is_cr3_header(header):
        return SourceType.RAW
    if header[:3] == _JPEG_MAGIC:
        return SourceType.JPEG
    if header[:8] == _PNG_MAGIC:
        return SourceType.PNG
    if header[:4] in _TIFF_MAGICS:
        return SourceType.TIFF
    raise BadSource(
        f"{path}: magic bytes {header[:8]!r} match none of CR3/JPEG/PNG/TIFF"
    )


@dataclass(frozen=True)
class IngestResult:
    """Segmentation input: upright, sRGB, 3-band uint8."""

    image: pyvips.Image
    source_type: SourceType
    # Full source resolution (width, height), upright — recorded on the item;
    # the no-upscale rule keys off this via zoom_native_px at composite time.
    source_px: tuple[int, int]
    alpha_was_present: bool


# EXIF orientation -> (quarter-turns clockwise, mirror horizontally after)
# putting pixels upright; same mapping as ingest.cr3.normalise_orientation.
_ORIENT_OPS: dict[int, tuple[int, bool]] = {
    2: (0, True),
    3: (2, False),
    4: (2, True),
    5: (1, True),
    6: (1, False),
    7: (3, True),
    8: (3, False),
}


def _orient_vips(image: pyvips.Image, orientation: int) -> pyvips.Image:
    turns, mirror = _ORIENT_OPS.get(orientation, (0, False))
    for _ in range(turns):
        image = image.rot90()
    return image.fliphor() if mirror else image


def _has_icc(image: pyvips.Image) -> bool:
    return bool(image.get_typeof("icc-profile-data") != 0)


def _normalise(image: pyvips.Image, settings: Settings, source: Path) -> tuple[pyvips.Image, bool]:
    """ICC → sRGB, flatten alpha onto the background, force 3-band uint8.

    Returns the normalised image and whether an alpha channel was present.
    """
    if _has_icc(image):
        image = image.icc_transform("srgb", embedded=True, intent="relative")
    if image.interpretation != "srgb":
        image = image.colourspace("srgb")
    alpha_was_present = bool(image.hasalpha())
    if alpha_was_present:
        r, g, b = settings.canvas.background_rgb
        image = image.flatten(background=[r, g, b])
        log_event(
            logger,
            "alpha_flattened",
            source=source.name,
            background=f"#{r:02X}{g:02X}{b:02X}",
        )
    if image.format != "uchar":
        image = image.cast("uchar", shift=True)
    return image, alpha_was_present


def ingest_for_segmentation(path: Path, settings: Settings) -> IngestResult:
    """Produce the segmentation input for any accepted source format.

    CR3: embedded-preview extraction (decode once at the right size — full
    rawpy develop happens only for frames reaching the compositor), with
    orientation read from the container. JPEG/PNG/TIFF: direct load with
    ``autorot`` (develop step skipped entirely).
    """
    source_type = detect_source_type(path)

    if source_type is SourceType.RAW:
        preview = pyvips.Image.new_from_buffer(extract_preview(path), "")
        upright = _orient_vips(preview, read_orientation(path))
        image, alpha_was_present = _normalise(upright, settings, path)
        with rawpy.imread(str(path)) as raw:
            sizes = raw.sizes
        source_px = (int(sizes.width), int(sizes.height))
    else:
        try:
            loaded = pyvips.Image.new_from_file(str(path))
        except pyvips.Error as exc:
            raise BadSource(f"{path}: cannot decode: {exc}") from exc
        upright = loaded.autorot()
        image, alpha_was_present = _normalise(upright, settings, path)
        source_px = (image.width, image.height)

    return IngestResult(
        image=image,
        source_type=source_type,
        source_px=source_px,
        alpha_was_present=alpha_was_present,
    )
