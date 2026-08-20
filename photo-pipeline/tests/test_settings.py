from __future__ import annotations

import pytest

from photo_pipeline.idempotency import idempotency_key
from photo_pipeline.settings import ModeName, Settings


def test_geometry_contract(settings: Settings) -> None:
    canvas = settings.canvas
    assert (canvas.zoom_width, canvas.zoom_height) == (3600, 4200)
    assert (canvas.display_width, canvas.display_height) == (2400, 2800)
    assert canvas.zoom_width * canvas.aspect_h == canvas.zoom_height * canvas.aspect_w
    assert canvas.background_rgb == (0xED, 0xEA, 0xE5)
    assert canvas.edge_clearance_display_px == 40


def test_fill_ratios_locked_per_mode(settings: Settings) -> None:
    ratios = settings.fill_ratios
    assert ratios.for_mode(ModeName.HANGING) == 0.82
    assert ratios.for_mode(ModeName.FLATLAY) == 0.84
    assert ratios.for_mode(ModeName.MANNEQUIN) == 0.80
    assert ratios.for_mode(ModeName.ACCESSORY) == 0.62


def test_settings_frozen(settings: Settings) -> None:
    with pytest.raises(Exception, match="frozen"):
        settings.canvas.zoom_width = 1000  # type: ignore[misc]


def test_env_override(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("PP_QA__FILL_RATIO_TOLERANCE", "0.05")
    assert Settings().qa.fill_ratio_tolerance == 0.05


def test_idempotency_key_stable() -> None:
    a = idempotency_key("s" * 64, "c" * 64, 1, "r" * 64, "gemini-3.1-flash-image")
    b = idempotency_key("s" * 64, "c" * 64, 1, "r" * 64, "gemini-3.1-flash-image")
    c = idempotency_key("s" * 64, "c" * 64, 2, "r" * 64, "gemini-3.1-flash-image")
    assert a == b
    assert a != c
    assert len(a) == 64
