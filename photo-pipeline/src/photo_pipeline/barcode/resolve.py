"""Barcode resolution — first hit wins, source recorded.

1. Barcode card frame at the start of each SKU sequence (embedded preview
   decode, propagated until next card; a sequence whose card doesn't decode
   fails whole — never inherit across sequences).
2. Filename prefix ``^(\\d{8,14})[_\\- ]``.
3. Metadata (XMP dc:title, IPTC ObjectName, EXIF ImageDescription).
4. Manual entry in UI (arrives as an operator-supplied value).

Every hit must pass EAN-13/EAN-8 check-digit validation, the own-prefix
whitelist (supplier tags on garment labels are rejected), and an existence
check in Directus. Anything else → quarantine, never guess.
"""

from __future__ import annotations

import re
import subprocess
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path

import numpy as np
import pyvips
import zxingcpp

from photo_pipeline.db.models import BarcodeSource
from photo_pipeline.errors import BarcodeQuarantine
from photo_pipeline.settings import BarcodeSettings

_METADATA_TAGS = ("-XMP-dc:Title", "-IPTC:ObjectName", "-EXIF:ImageDescription")


def ean_check_digit_ok(code: str) -> bool:
    """Validate an EAN-13 or EAN-8 check digit."""
    if not code.isdigit() or len(code) not in (8, 13):
        return False
    digits = [int(c) for c in code]
    payload, check = digits[:-1], digits[-1]
    # Weights 3/1 alternate from the rightmost payload digit (weight 3).
    total = sum(d * (3 if i % 2 == 0 else 1) for i, d in enumerate(reversed(payload)))
    return (10 - total % 10) % 10 == check


def validate_barcode(
    code: str,
    settings: BarcodeSettings,
    *,
    exists_in_directus: Callable[[str], bool],
) -> None:
    """Raise :class:`BarcodeQuarantine` unless the code is fully valid."""
    if not ean_check_digit_ok(code):
        raise BarcodeQuarantine(f"check digit invalid: {code}")
    if not code.startswith(tuple(settings.prefix_whitelist)):
        raise BarcodeQuarantine(f"prefix not in own range: {code}")
    if not exists_in_directus(code):
        raise BarcodeQuarantine(f"barcode not in Directus: {code}")


@dataclass(frozen=True)
class ResolvedBarcode:
    code: str
    source: BarcodeSource


@dataclass(frozen=True)
class Frame:
    """One frame of a shoot sequence, in capture order."""

    path: Path
    # True when the operator flagged this frame as a barcode card starting a
    # new SKU sequence.
    is_card: bool = False
    # Decoded value from the card's embedded preview (None = decode failed).
    card_decode: str | None = None
    manual_entry: str | None = None


def decode_card_preview(preview_jpeg: bytes) -> str | None:
    """Decode an EAN barcode from an embedded-preview JPEG (zxing-cpp)."""
    image = pyvips.Image.new_from_buffer(preview_jpeg, "", access="sequential")
    if image.bands > 1:
        image = image.colourspace("b-w")
    array = np.ndarray(
        buffer=image.write_to_memory(),
        dtype=np.uint8,
        shape=(image.height, image.width),
    )
    for result in zxingcpp.read_barcodes(array):
        if result.valid and result.text.isdigit():
            return str(result.text)
    return None


def _from_filename(path: Path, settings: BarcodeSettings) -> str | None:
    match = re.match(settings.filename_pattern, path.name)
    return match.group(1) if match else None


def _from_metadata(path: Path, *, exiftool_bin: str = "exiftool") -> str | None:
    proc = subprocess.run(  # noqa: S603 - fixed binary, no shell
        [exiftool_bin, "-s3", *_METADATA_TAGS, str(path)],
        capture_output=True,
        check=False,
    )
    if proc.returncode != 0:
        return None
    for line in proc.stdout.decode("utf-8", errors="replace").splitlines():
        candidate = line.strip()
        if candidate.isdigit() and len(candidate) in (8, 13):
            return candidate
    return None


class SequenceResolver:
    """Resolve barcodes across an ordered shoot sequence.

    Card decodes propagate to following frames until the next card. A card
    that fails to decode poisons its whole sequence (every frame until the
    next card quarantines) — barcodes are never inherited across sequences.
    """

    def __init__(
        self,
        settings: BarcodeSettings,
        *,
        exists_in_directus: Callable[[str], bool],
        exiftool_bin: str = "exiftool",
    ) -> None:
        self._settings = settings
        self._exists = exists_in_directus
        self._exiftool = exiftool_bin
        self._current_card: str | None = None
        self._sequence_poisoned = False

    def resolve(self, frame: Frame) -> ResolvedBarcode:
        if frame.is_card:
            self._current_card = None
            self._sequence_poisoned = frame.card_decode is None
            if self._sequence_poisoned:
                raise BarcodeQuarantine(f"card frame {frame.path.name} did not decode")
            assert frame.card_decode is not None
            try:
                self._validate(frame.card_decode)
            except BarcodeQuarantine:
                # A card that decodes to an invalid/unknown code poisons its
                # sequence too — following frames must not guess a SKU.
                self._sequence_poisoned = True
                raise
            self._current_card = frame.card_decode
            return ResolvedBarcode(frame.card_decode, BarcodeSource.CARD)

        # Step 1 — propagated card.
        if self._current_card is not None:
            self._validate(self._current_card)
            return ResolvedBarcode(self._current_card, BarcodeSource.CARD)
        if self._sequence_poisoned:
            raise BarcodeQuarantine(
                f"{frame.path.name}: sequence card failed to decode — sequence fails whole"
            )

        # Step 2 — filename prefix.
        code = _from_filename(frame.path, self._settings)
        if code is not None:
            self._validate(code)
            return ResolvedBarcode(code, BarcodeSource.FILENAME)

        # Step 3 — metadata.
        code = _from_metadata(frame.path, exiftool_bin=self._exiftool)
        if code is not None:
            self._validate(code)
            return ResolvedBarcode(code, BarcodeSource.METADATA)

        # Step 4 — manual entry from the operator UI.
        if frame.manual_entry is not None:
            self._validate(frame.manual_entry)
            return ResolvedBarcode(frame.manual_entry, BarcodeSource.MANUAL)

        raise BarcodeQuarantine(f"{frame.path.name}: no barcode from any source")

    def _validate(self, code: str) -> None:
        validate_barcode(code, self._settings, exists_in_directus=self._exists)
