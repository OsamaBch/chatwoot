from __future__ import annotations

import logging
import struct
from pathlib import Path

import pytest
import pyvips

from conftest import rgba_to_vips, synthetic_cutout
from photo_pipeline.compose.compositor import compose_view
from photo_pipeline.db.models import SourceType
from photo_pipeline.errors import BadSource
from photo_pipeline.ingest.source import detect_source_type, ingest_for_segmentation
from photo_pipeline.settings import ModeName, Settings


def _bmff_header(brand: bytes, size: int = 24) -> bytes:
    return struct.pack(">I", size) + b"ftyp" + brand + b"\x00" * (size - 12)


def _flat_rgb(width: int, height: int, rgb: tuple[int, int, int]) -> pyvips.Image:
    r, g, b = rgb
    return (
        pyvips.Image.black(width, height, bands=3)
        .new_from_image([r, g, b])
        .cast("uchar")
        .copy(interpretation="srgb")
    )


# --- Magic-byte detection (never by extension) ---


def test_detect_cr3(tmp_path: Path) -> None:
    path = tmp_path / "a.CR3"
    path.write_bytes(_bmff_header(b"crx "))
    assert detect_source_type(path) == SourceType.RAW


def test_detect_jpeg_despite_extension(tmp_path: Path) -> None:
    path = tmp_path / "lying.CR3"  # extension lies; magic decides
    _flat_rgb(8, 8, (10, 20, 30)).jpegsave(str(path))
    assert detect_source_type(path) == SourceType.JPEG


def test_detect_png(tmp_path: Path) -> None:
    path = tmp_path / "a.png"
    _flat_rgb(8, 8, (10, 20, 30)).pngsave(str(path))
    assert detect_source_type(path) == SourceType.PNG


def test_detect_tiff_both_byte_orders(tmp_path: Path) -> None:
    little = tmp_path / "ii.tif"
    _flat_rgb(8, 8, (10, 20, 30)).tiffsave(str(little))
    assert detect_source_type(little) == SourceType.TIFF
    big = tmp_path / "mm.tif"
    big.write_bytes(b"MM\x00*" + b"\x00" * 12)
    assert detect_source_type(big) == SourceType.TIFF


def test_rejects_heic_and_junk(tmp_path: Path) -> None:
    heic = tmp_path / "h.heic"
    heic.write_bytes(_bmff_header(b"heic"))
    with pytest.raises(BadSource, match="match none"):
        detect_source_type(heic)
    junk = tmp_path / "j.jpg"
    junk.write_bytes(b"GIF89a" + b"\x00" * 16)
    with pytest.raises(BadSource, match="match none"):
        detect_source_type(junk)


# --- Non-CR3 ingest: no develop, orientation, ICC, alpha ---


def test_jpeg_orientation_normalised(tmp_path: Path, settings: Settings) -> None:
    image = _flat_rgb(100, 50, (10, 20, 30)).copy()
    image.set_type(pyvips.GValue.gint_type, "orientation", 6)
    path = tmp_path / "rot.jpg"
    image.jpegsave(str(path))
    result = ingest_for_segmentation(path, settings)
    assert result.source_type == SourceType.JPEG
    assert (result.image.width, result.image.height) == (50, 100)
    assert result.source_px == (50, 100)
    assert not result.alpha_was_present


def test_jpeg_with_embedded_icc_lands_in_srgb(tmp_path: Path, settings: Settings) -> None:
    path = tmp_path / "icc.jpg"
    _flat_rgb(32, 32, (200, 100, 50)).jpegsave(str(path), profile="srgb")
    assert pyvips.Image.new_from_file(str(path)).get_typeof("icc-profile-data") != 0
    result = ingest_for_segmentation(path, settings)
    assert result.image.interpretation == "srgb"
    assert result.image.format == "uchar"


def test_png_alpha_flattened_onto_background_and_logged(
    tmp_path: Path, settings: Settings, caplog: pytest.LogCaptureFixture
) -> None:
    rgba = synthetic_cutout(200, 240, 50, 60, 100, 120)
    path = tmp_path / "cutout.png"
    rgba_to_vips(rgba).pngsave(str(path))
    with caplog.at_level(logging.INFO, logger="photo_pipeline.ingest.source"):
        result = ingest_for_segmentation(path, settings)
    assert result.source_type == SourceType.PNG
    assert result.alpha_was_present
    assert result.image.bands == 3
    # Transparent corner became the background colour.
    assert tuple(result.image.getpoint(0, 0)) == settings.canvas.background_rgb
    # Garment pixels survived the flatten.
    assert tuple(result.image.getpoint(100, 120)) != settings.canvas.background_rgb
    assert any("alpha_flattened" in r.message for r in caplog.records)


def test_opaque_png_not_logged_as_alpha(tmp_path: Path, settings: Settings) -> None:
    path = tmp_path / "opaque.png"
    _flat_rgb(20, 20, (10, 20, 30)).pngsave(str(path))
    result = ingest_for_segmentation(path, settings)
    assert not result.alpha_was_present


def test_16bit_tiff_normalised_to_uint8(tmp_path: Path, settings: Settings) -> None:
    deep = (_flat_rgb(16, 16, (128, 64, 32)).cast("ushort", shift=True)).copy(
        interpretation="rgb16"
    )
    path = tmp_path / "deep.tif"
    deep.tiffsave(str(path))
    result = ingest_for_segmentation(path, settings)
    assert result.source_type == SourceType.TIFF
    assert result.image.format == "uchar"
    assert result.image.interpretation == "srgb"


def test_ingest_rejects_truncated_file(tmp_path: Path, settings: Settings) -> None:
    path = tmp_path / "x.jpg"
    path.write_bytes(b"\x00\x01")
    with pytest.raises(BadSource):
        ingest_for_segmentation(path, settings)


# --- Small non-RAW source at composite time (no-upscale rule) ---


def test_1200x1400_png_composites_at_native_zoom(
    tmp_path: Path, settings: Settings
) -> None:
    rgba = synthetic_cutout(1200, 1400, 300, 200, 600, 1000)
    path = tmp_path / "small.png"
    rgba_to_vips(rgba).pngsave(str(path))

    ingested = ingest_for_segmentation(path, settings)
    assert ingested.source_px == (1200, 1400)

    # Segmentation is out of scope: reuse the PNG's own alpha as the cutout.
    result = compose_view(
        rgba_to_vips(rgba), ModeName.HANGING, settings, tmp_path, "small"
    )
    # bbox height 1000, fill 0.82 -> ideal window 1219.5 -> ceil to /6 = 1224.
    assert result.scale_factor == 1.0
    assert result.zoom_native_px == 1224
    assert (result.zoom_geometry.width, result.zoom_geometry.height) == (1224, 1428)
    assert result.display_geometry == result.zoom_geometry
