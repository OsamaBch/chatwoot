"""Golden-image tests: a fixed synthetic input must produce byte-identical
output files (sha256 manifest checked in at tests/golden/manifest.json).

Regenerate the manifest after an intentional compositor change with:
    UPDATE_GOLDEN=1 uv run pytest tests/test_golden.py
"""

from __future__ import annotations

import dataclasses
import hashlib
import json
import os
from pathlib import Path

from conftest import rgba_to_vips, synthetic_cutout
from photo_pipeline.compose.compositor import CompositeResult, compose_view
from photo_pipeline.qa.gates import run_gates_1_to_4
from photo_pipeline.settings import ModeName, Settings

MANIFEST = Path(__file__).parent / "golden" / "manifest.json"


def _sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def _compose_reference(settings: Settings, out_dir: Path) -> CompositeResult:
    """The fixed golden input: 4600x5200 cutout, garment bbox 2200x4000."""
    cutout = rgba_to_vips(synthetic_cutout(4600, 5200, 1200, 600, 2200, 4000))
    return compose_view(cutout, ModeName.HANGING, settings, out_dir, "golden")


def _compose_small_source(settings: Settings, out_dir: Path) -> CompositeResult:
    """1800px-wide source: exercises the no-upscale / zoom_native_px path."""
    cutout = rgba_to_vips(synthetic_cutout(1800, 2100, 450, 250, 900, 1600))
    return compose_view(cutout, ModeName.HANGING, settings, out_dir, "native")


def _manifest_entries(result: CompositeResult) -> dict[str, str]:
    return {
        path.name: _sha256(path)
        for path in (
            result.zoom_master,
            result.zoom_crop,
            result.display_master,
            result.display_crop,
        )
    }


def test_golden_byte_identical(settings: Settings, tmp_path: Path) -> None:
    result = _compose_reference(settings, tmp_path / "run1")
    entries = _manifest_entries(result)
    small = _compose_small_source(settings, tmp_path / "run1")
    entries.update(_manifest_entries(small))

    if os.environ.get("UPDATE_GOLDEN"):
        MANIFEST.parent.mkdir(parents=True, exist_ok=True)
        MANIFEST.write_text(json.dumps(entries, indent=2, sort_keys=True) + "\n")

    expected = json.loads(MANIFEST.read_text())
    assert entries == expected

    # And a second invocation is byte-identical to the first.
    rerun = _compose_reference(settings, tmp_path / "run2")
    assert _manifest_entries(rerun) == _manifest_entries(result)


def test_four_files_from_one_invocation(settings: Settings, tmp_path: Path) -> None:
    result = _compose_reference(settings, tmp_path)
    files = [result.zoom_master, result.zoom_crop, result.display_master, result.display_crop]
    assert all(f.exists() for f in files)
    assert len({f.name for f in files}) == 4
    # Full-size path: no shrink.
    assert result.zoom_native_px is None
    assert (result.zoom_geometry.width, result.zoom_geometry.height) == (3600, 4200)
    assert (result.display_geometry.width, result.display_geometry.height) == (2400, 2800)
    assert result.zoom_geometry.window_y == 300
    assert result.display_geometry.window_y == 200


def test_gates_1_to_4_pass_on_golden(settings: Settings, tmp_path: Path) -> None:
    result = _compose_reference(settings, tmp_path)
    assert run_gates_1_to_4(result, ModeName.HANGING, settings) is None


def test_no_upscale_shrinks_zoom_master(settings: Settings, tmp_path: Path) -> None:
    result = _compose_small_source(settings, tmp_path)
    # bbox height 1600, fill 0.82 -> ideal window 1951.2 -> ceil to /6 = 1956.
    assert result.scale_factor == 1.0
    assert result.zoom_native_px == 1956
    assert (result.zoom_geometry.width, result.zoom_geometry.height) == (1956, 2282)
    # 6:7 kept exactly.
    assert result.zoom_geometry.width * 7 == result.zoom_geometry.height * 6
    # Display master never upscales either: served at native size.
    assert result.display_geometry == result.zoom_geometry
    assert run_gates_1_to_4(result, ModeName.HANGING, settings) is None


def test_gate_catches_wrong_dimensions(settings: Settings, tmp_path: Path) -> None:
    result = _compose_reference(settings, tmp_path)
    lying = dataclasses.replace(
        result,
        zoom_geometry=dataclasses.replace(result.zoom_geometry, width=3599),
    )
    failure = run_gates_1_to_4(lying, ModeName.HANGING, settings)
    assert failure is not None
    assert failure.gate == "gate1_dimensions"
