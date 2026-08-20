from __future__ import annotations

from pathlib import Path

import pytest

from photo_pipeline.barcode.resolve import (
    Frame,
    SequenceResolver,
    ean_check_digit_ok,
    validate_barcode,
)
from photo_pipeline.db.models import BarcodeSource
from photo_pipeline.errors import BarcodeQuarantine
from photo_pipeline.settings import BarcodeSettings

SETTINGS = BarcodeSettings()
VALID_EAN13 = "6130001234563"  # 613... prefix, valid check digit


def _resolver(known: set[str]) -> SequenceResolver:
    return SequenceResolver(SETTINGS, exists_in_directus=known.__contains__)


def test_check_digit() -> None:
    assert ean_check_digit_ok(VALID_EAN13)
    assert not ean_check_digit_ok("6130001234567")  # transposed/typo digit
    assert ean_check_digit_ok("4006381333931")
    assert ean_check_digit_ok("96385074")  # EAN-8
    assert not ean_check_digit_ok("6130001234561")
    assert not ean_check_digit_ok("12345")
    assert not ean_check_digit_ok("abcdefghijklm")


def test_prefix_whitelist() -> None:
    # Valid EAN but a supplier prefix — rejected.
    with pytest.raises(BarcodeQuarantine, match="prefix"):
        validate_barcode("4006381333931", SETTINGS, exists_in_directus=lambda _: True)


def test_unknown_in_directus_quarantines() -> None:
    with pytest.raises(BarcodeQuarantine, match="Directus"):
        validate_barcode(VALID_EAN13, SETTINGS, exists_in_directus=lambda _: False)


def test_filename_prefix_hit() -> None:
    resolver = _resolver({VALID_EAN13})
    resolved = resolver.resolve(Frame(Path("6130001234563_front.CR3")))
    assert resolved.code == VALID_EAN13
    assert resolved.source == BarcodeSource.FILENAME


def test_no_source_quarantines(tmp_path: Path) -> None:
    # IMG_4021.CR3: no card, no filename match, no metadata, no manual entry.
    empty = tmp_path / "IMG_4021.CR3"
    empty.write_bytes(b"\x00" * 32)
    resolver = _resolver({VALID_EAN13})
    with pytest.raises(BarcodeQuarantine, match="no barcode from any source"):
        resolver.resolve(Frame(empty))


def test_card_propagates_and_beats_filename() -> None:
    resolver = _resolver({VALID_EAN13})
    card = resolver.resolve(
        Frame(Path("card.CR3"), is_card=True, card_decode=VALID_EAN13)
    )
    assert card.source == BarcodeSource.CARD
    # Following frame has a *different* filename barcode; card wins (step 1).
    resolved = resolver.resolve(Frame(Path("6130007654327_back.CR3")))
    assert resolved.code == VALID_EAN13
    assert resolved.source == BarcodeSource.CARD


def test_undecoded_card_fails_sequence_whole() -> None:
    resolver = _resolver({VALID_EAN13})
    with pytest.raises(BarcodeQuarantine, match="did not decode"):
        resolver.resolve(Frame(Path("card.CR3"), is_card=True, card_decode=None))
    # Frames of the poisoned sequence quarantine even with a filename hit.
    with pytest.raises(BarcodeQuarantine, match="sequence fails whole"):
        resolver.resolve(Frame(Path("6130001234563_front.CR3")))
    # Next sequence starts fresh with its own card — never inherited.
    fresh = resolver.resolve(Frame(Path("card2.CR3"), is_card=True, card_decode=VALID_EAN13))
    assert fresh.code == VALID_EAN13


def test_filename_with_bad_check_digit_quarantines() -> None:
    # "6130001234567" matches the filename rule but its EAN-13 check digit
    # is wrong (should end in 3) -> quarantine, never guess.
    resolver = _resolver({VALID_EAN13, "6130001234567"})
    with pytest.raises(BarcodeQuarantine, match="check digit invalid"):
        resolver.resolve(Frame(Path("6130001234567_front.CR3")))


def test_manual_entry_last() -> None:
    resolver = _resolver({VALID_EAN13})
    resolved = resolver.resolve(
        Frame(Path("IMG_0001.CR3"), manual_entry=VALID_EAN13)
    )
    assert resolved.source == BarcodeSource.MANUAL
