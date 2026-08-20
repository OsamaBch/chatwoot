from __future__ import annotations

import struct
from pathlib import Path

import numpy as np
import pytest

from photo_pipeline.errors import BadSource
from photo_pipeline.ingest.cr3 import (
    normalise_orientation,
    read_orientation,
    validate_cr3_magic,
)


def _write(tmp_path: Path, name: str, payload: bytes) -> Path:
    path = tmp_path / name
    path.write_bytes(payload)
    return path


def _bmff_header(brand: bytes, size: int = 24) -> bytes:
    return struct.pack(">I", size) + b"ftyp" + brand + b"\x00" * (size - 12)


def test_valid_cr3_magic(tmp_path: Path) -> None:
    path = _write(tmp_path, "ok.CR3", _bmff_header(b"crx "))
    validate_cr3_magic(path)  # does not raise


def test_rejects_non_bmff(tmp_path: Path) -> None:
    path = _write(tmp_path, "fake.CR3", b"\xff\xd8\xff\xe0" + b"\x00" * 64)
    with pytest.raises(BadSource, match="not ISO-BMFF"):
        validate_cr3_magic(path)


def test_rejects_wrong_brand(tmp_path: Path) -> None:
    # A HEIC is ISO-BMFF but not a CR3.
    path = _write(tmp_path, "img.CR3", _bmff_header(b"heic"))
    with pytest.raises(BadSource, match="not 'crx '"):
        validate_cr3_magic(path)


def test_rejects_truncated(tmp_path: Path) -> None:
    path = _write(tmp_path, "short.CR3", b"\x00\x00")
    with pytest.raises(BadSource, match="shorter"):
        validate_cr3_magic(path)


def test_orientation_defaults_to_1_without_exif(tmp_path: Path) -> None:
    path = _write(tmp_path, "no_exif.bin", b"\x00" * 32)
    assert read_orientation(path) == 1


@pytest.mark.parametrize(
    ("orientation", "expected"),
    [
        (1, [[1, 2], [3, 4]]),
        (2, [[2, 1], [4, 3]]),
        (3, [[4, 3], [2, 1]]),
        (4, [[3, 4], [1, 2]]),
        (6, [[3, 1], [4, 2]]),
        (8, [[2, 4], [1, 3]]),
    ],
)
def test_normalise_orientation(orientation: int, expected: list[list[int]]) -> None:
    image = np.array([[1, 2], [3, 4]], dtype=np.uint8)
    assert normalise_orientation(image, orientation).tolist() == expected
