"""Garment bbox from the cutout alpha channel.

Rules (CLAUDE.md — Geometry contract):
- exclude the topmost connected component narrower than 8% of bbox width
  (hanger hook — pixels stay, bbox ignores);
- union all components above 2% of image area (multi-piece sets are one
  product).
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np
import numpy.typing as npt
import pyvips

from photo_pipeline.errors import BadSource
from photo_pipeline.settings import BboxSettings


@dataclass(frozen=True)
class ComponentBox:
    left: int
    top: int
    width: int
    height: int
    area_px: int

    @property
    def right(self) -> int:
        return self.left + self.width

    @property
    def bottom(self) -> int:
        return self.top + self.height


@dataclass(frozen=True)
class GarmentBbox:
    left: int
    top: int
    width: int
    height: int
    components: tuple[ComponentBox, ...]
    excluded_hook: ComponentBox | None

    @property
    def centre_x(self) -> float:
        return self.left + self.width / 2

    @property
    def centre_y(self) -> float:
        return self.top + self.height / 2


def _label_components(mask: npt.NDArray[np.bool_]) -> list[ComponentBox]:
    """Connected components of a boolean mask via pyvips labelregions."""
    height, width = mask.shape
    mask_vips = pyvips.Image.new_from_memory(
        np.ascontiguousarray(mask.astype(np.uint8) * 255).tobytes(),
        width,
        height,
        1,
        "uchar",
    )
    labels_vips = mask_vips.labelregions()
    labels: npt.NDArray[np.uint32] = np.ndarray(
        buffer=labels_vips.write_to_memory(),
        dtype=np.uint32,
        shape=(height, width),
    )
    foreground_labels = np.unique(labels[mask])
    boxes: list[ComponentBox] = []
    for label in foreground_labels:
        ys, xs = np.nonzero(labels == label)
        boxes.append(
            ComponentBox(
                left=int(xs.min()),
                top=int(ys.min()),
                width=int(xs.max() - xs.min() + 1),
                height=int(ys.max() - ys.min() + 1),
                area_px=int(ys.size),
            )
        )
    return boxes


def _union(boxes: list[ComponentBox]) -> tuple[int, int, int, int]:
    left = min(b.left for b in boxes)
    top = min(b.top for b in boxes)
    right = max(b.right for b in boxes)
    bottom = max(b.bottom for b in boxes)
    return left, top, right - left, bottom - top


def compute_garment_bbox(
    alpha: npt.NDArray[np.uint8], settings: BboxSettings
) -> GarmentBbox:
    """Compute the garment bbox from an (H, W) uint8 alpha plane."""
    if alpha.ndim != 2:  # noqa: PLR2004 - 2-D is structural, not a tunable
        raise BadSource(f"alpha must be 2-D, got shape {alpha.shape}")
    mask = alpha > settings.alpha_threshold
    if not bool(mask.any()):
        raise BadSource("empty alpha: no garment pixels")

    components = _label_components(mask)
    image_area = alpha.shape[0] * alpha.shape[1]

    # Union all components above the area floor (multi-piece sets are one
    # product); if the floor eats everything, keep the largest component so
    # a small accessory still gets a bbox.
    min_area = settings.min_component_area_ratio * image_area
    kept = [c for c in components if c.area_px >= min_area]
    if not kept:
        kept = [max(components, key=lambda c: c.area_px)]

    left, top, width, height = _union(kept)

    # Hanger-hook exclusion: the topmost kept component, when narrower than
    # 8% of the union bbox width, is ignored by the bbox (pixels stay).
    excluded_hook: ComponentBox | None = None
    if len(kept) > 1:
        topmost = min(kept, key=lambda c: c.top)
        if topmost.width < settings.hook_max_width_ratio * width:
            excluded_hook = topmost
            kept = [c for c in kept if c is not topmost]
            left, top, width, height = _union(kept)

    return GarmentBbox(
        left=left,
        top=top,
        width=width,
        height=height,
        components=tuple(kept),
        excluded_hook=excluded_hook,
    )
