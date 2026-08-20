from __future__ import annotations

import numpy as np
import numpy.typing as npt
import pytest
import pyvips

from photo_pipeline.settings import Settings


@pytest.fixture(scope="session")
def settings() -> Settings:
    return Settings()


def rgba_to_vips(array: npt.NDArray[np.uint8]) -> pyvips.Image:
    height, width, bands = array.shape
    return pyvips.Image.new_from_memory(
        np.ascontiguousarray(array).tobytes(), width, height, bands, "uchar"
    ).copy(interpretation="srgb")


def synthetic_cutout(
    width: int,
    height: int,
    garment_left: int,
    garment_top: int,
    garment_width: int,
    garment_height: int,
) -> npt.NDArray[np.uint8]:
    """Deterministic RGBA cutout: transparent canvas, one navy garment
    rectangle with a fixed diagonal texture stripe pattern."""
    canvas = np.zeros((height, width, 4), dtype=np.uint8)
    ys = np.arange(garment_top, garment_top + garment_height)
    xs = np.arange(garment_left, garment_left + garment_width)
    yy, xx = np.meshgrid(ys, xs, indexing="ij")
    stripe = ((yy + xx) // 24 % 2).astype(np.uint8)
    region = canvas[
        garment_top : garment_top + garment_height,
        garment_left : garment_left + garment_width,
    ]
    region[..., 0] = 28 + stripe * 8
    region[..., 1] = 36 + stripe * 6
    region[..., 2] = 88 + stripe * 10
    region[..., 3] = 255
    return canvas
