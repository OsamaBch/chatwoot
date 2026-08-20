"""CR3 ingest: magic-byte validation, embedded-preview extraction (exiftool),
EXIF orientation normalisation, rawpy develop with fixed params.

Decode once at the right size: the embedded JPEG serves barcode decode /
thumbs / segmentation input; full demosaic (``develop``) only for frames
that reach the compositor.
"""

from __future__ import annotations

import struct
import subprocess
from collections.abc import Callable
from pathlib import Path
from typing import Any

import numpy as np
import numpy.typing as npt
import rawpy

from photo_pipeline.errors import BadSource
from photo_pipeline.settings import RawDevelopSettings

_FTYP = b"ftyp"
_CR3_BRAND = b"crx "
_MIN_HEADER = 16


def validate_cr3_magic(path: Path) -> None:
    """Validate ISO-BMFF layout with major brand 'crx '.

    A CR3 starts with a box: [size:4][type:'ftyp'][major_brand:'crx '].
    Raises :class:`BadSource` on anything else — never trust the extension.
    """
    try:
        header = path.open("rb").read(_MIN_HEADER)
    except OSError as exc:
        raise BadSource(f"cannot read {path}: {exc}") from exc
    if len(header) < _MIN_HEADER:
        raise BadSource(f"{path}: file shorter than ISO-BMFF header")
    (box_size,) = struct.unpack(">I", header[0:4])
    if header[4:8] != _FTYP:
        raise BadSource(f"{path}: not ISO-BMFF (no ftyp box)")
    if box_size < _MIN_HEADER:
        raise BadSource(f"{path}: implausible ftyp box size {box_size}")
    if header[8:12] != _CR3_BRAND:
        raise BadSource(f"{path}: major brand {header[8:12]!r} is not 'crx '")


def extract_preview(path: Path, *, exiftool_bin: str = "exiftool") -> bytes:
    """Extract the largest embedded JPEG preview via exiftool."""
    validate_cr3_magic(path)
    for tag in ("-JpgFromRaw", "-PreviewImage"):
        proc = subprocess.run(  # noqa: S603 - fixed binary, no shell
            [exiftool_bin, "-b", tag, str(path)],
            capture_output=True,
            check=False,
        )
        if proc.returncode == 0 and proc.stdout[:2] == b"\xff\xd8":
            return proc.stdout
    raise BadSource(f"{path}: exiftool found no embedded JPEG preview")


def read_orientation(path: Path, *, exiftool_bin: str = "exiftool") -> int:
    """Read the EXIF orientation (1-8); 1 when absent."""
    proc = subprocess.run(  # noqa: S603 - fixed binary, no shell
        [exiftool_bin, "-n", "-s3", "-Orientation", str(path)],
        capture_output=True,
        check=False,
    )
    raw = proc.stdout.decode("ascii", errors="replace").strip()
    if proc.returncode != 0 or not raw:
        return 1
    try:
        value = int(raw)
    except ValueError:
        return 1
    return value if value in _ORIENTATION_TRANSFORMS or value == 1 else 1


type _Array = npt.NDArray[np.uint8] | npt.NDArray[np.uint16]

# EXIF orientation tag -> transform putting pixels upright, top-left first.
_ORIENTATION_TRANSFORMS: dict[int, Callable[[_Array], _Array]] = {
    2: lambda a: np.flip(a, axis=1),
    3: lambda a: np.rot90(a, 2),
    4: lambda a: np.flip(a, axis=0),
    5: lambda a: np.flip(np.rot90(a, 3), axis=1),
    6: lambda a: np.rot90(a, 3),
    7: lambda a: np.flip(np.rot90(a, 1), axis=1),
    8: lambda a: np.rot90(a, 1),
}


def normalise_orientation(image: _Array, orientation: int) -> _Array:
    """Apply the EXIF orientation (1-8) so pixels are upright, top-left first."""
    transform = _ORIENTATION_TRANSFORMS.get(orientation)
    return transform(image) if transform is not None else image


def develop(path: Path, params: RawDevelopSettings) -> npt.NDArray[np.uint16]:
    """Full demosaic with the fixed develop params:
    use_camera_wb, no_auto_bright, sRGB, 16-bit.

    rawpy already returns upright pixels (LibRaw applies the camera flip),
    so no further orientation pass is needed here.
    """
    validate_cr3_magic(path)
    if not (params.use_camera_wb and params.no_auto_bright and params.output_color_srgb):
        raise BadSource("develop params must stay fixed per CLAUDE.md")
    try:
        with rawpy.imread(str(path)) as raw:
            rgb: Any = raw.postprocess(
                use_camera_wb=params.use_camera_wb,
                no_auto_bright=params.no_auto_bright,
                output_color=rawpy.ColorSpace.sRGB,
                output_bps=params.output_bps,
            )
    except (rawpy.LibRawError, OSError) as exc:
        raise BadSource(f"{path}: LibRaw could not develop: {exc}") from exc
    return np.ascontiguousarray(rgb, dtype=np.uint16)
