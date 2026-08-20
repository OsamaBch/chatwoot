from __future__ import annotations

import numpy as np
import numpy.typing as npt
import pytest

from photo_pipeline.compose.bbox import compute_garment_bbox
from photo_pipeline.errors import BadSource
from photo_pipeline.settings import BboxSettings

SETTINGS = BboxSettings()


def _alpha(height: int = 1000, width: int = 1000) -> npt.NDArray[np.uint8]:
    return np.zeros((height, width), dtype=np.uint8)


def test_single_component() -> None:
    alpha = _alpha()
    alpha[300:900, 250:750] = 255
    bbox = compute_garment_bbox(alpha, SETTINGS)
    assert (bbox.left, bbox.top, bbox.width, bbox.height) == (250, 300, 500, 600)
    assert bbox.excluded_hook is None


def test_hanger_hook_excluded_from_bbox() -> None:
    alpha = _alpha()
    # Garment: 500x600 (area 300k >= 2% of 1M).
    alpha[300:900, 250:750] = 255
    # Hook: 30px wide x 250 tall above it, area 7.5k < 2% — dropped by the
    # area floor; widen the test with a big hook below.
    alpha[40:290, 485:515] = 255
    bbox = compute_garment_bbox(alpha, SETTINGS)
    assert (bbox.top, bbox.height) == (300, 600)


def test_hook_above_area_floor_still_excluded() -> None:
    tall = _alpha(2000, 1000)
    tall[1200:1900, 250:750] = 255  # garment 500x700 = 350k px >= 2% of 2M
    tall[100:1190, 480:519] = 255  # hook 39x1090 = 42.5k px >= 2% floor, 39 < 8% of 500
    bbox = compute_garment_bbox(tall, SETTINGS)
    assert bbox.excluded_hook is not None
    assert bbox.excluded_hook.width == 39
    assert (bbox.top, bbox.height) == (1200, 700)
    # Pixels stay — only the bbox ignores the hook.


def test_multi_component_union() -> None:
    alpha = _alpha()
    # Two-piece set: top and trousers, both >= 2% of image area.
    alpha[100:400, 200:700] = 255  # 500x300 = 150k
    alpha[500:900, 300:800] = 255  # 500x400 = 200k
    # A dust speck below the 2% floor must not stretch the bbox.
    alpha[950:955, 10:15] = 255
    bbox = compute_garment_bbox(alpha, SETTINGS)
    assert (bbox.left, bbox.top) == (200, 100)
    assert (bbox.width, bbox.height) == (600, 800)
    assert len(bbox.components) == 2


def test_empty_alpha_raises() -> None:
    with pytest.raises(BadSource, match="empty alpha"):
        compute_garment_bbox(_alpha(), SETTINGS)
