"""Typed settings — every tunable in CLAUDE.md lives here.

No numeric or string tunable may appear inline in pipeline code: the
pipeline reads everything from :class:`Settings` (env-overridable, prefix
``PP_``, nested delimiter ``__``).
"""

from __future__ import annotations

from enum import StrEnum
from functools import lru_cache

from pydantic import BaseModel, ConfigDict
from pydantic_settings import BaseSettings, SettingsConfigDict


class ModeName(StrEnum):
    HANGING = "hanging"
    FLATLAY = "flatlay"
    MANNEQUIN = "mannequin"
    ACCESSORY = "accessory"


class _Frozen(BaseModel):
    model_config = ConfigDict(frozen=True)


class CanvasSettings(_Frozen):
    """Geometry contract (CLAUDE.md — Geometry contract)."""

    zoom_width: int = 3600
    zoom_height: int = 4200
    display_width: int = 2400
    display_height: int = 2800
    # 6:7 aspect, expressed once so shrunken zoom masters keep it exact.
    aspect_w: int = 6
    aspect_h: int = 7
    # 1:1 window: full width, y-offset = height * (offset_num / offset_den).
    window_y_offset_num: int = 200
    window_y_offset_den: int = 2800
    background_rgb: tuple[int, int, int] = (0xED, 0xEA, 0xE5)  # #EDEAE5
    # >= this many px of pure background on all sides at display scale,
    # proportional at zoom scale, in ALL crops.
    edge_clearance_display_px: int = 40
    # Anchor: garment bbox centre -> (anchor_x_ratio * width, window centre).
    anchor_x_ratio: float = 0.5


class FillRatios(_Frozen):
    """Fill ratio (bbox height / square window), locked per mode."""

    hanging: float = 0.82
    flatlay: float = 0.84
    mannequin: float = 0.80
    accessory: float = 0.62

    def for_mode(self, mode: ModeName) -> float:
        return {
            ModeName.HANGING: self.hanging,
            ModeName.FLATLAY: self.flatlay,
            ModeName.MANNEQUIN: self.mannequin,
            ModeName.ACCESSORY: self.accessory,
        }[mode]


class BboxSettings(_Frozen):
    """Garment bbox rules (hanger-hook exclusion, multi-component union)."""

    # Alpha values strictly above this (0-255) count as garment pixels.
    alpha_threshold: int = 0
    # Exclude topmost connected component narrower than this fraction of
    # bbox width (hanger hook — pixels stay, bbox ignores).
    hook_max_width_ratio: float = 0.08
    # Row-profile fallback for the connected-hook case: a contiguous band of
    # narrow rows at the bbox top is trimmed only when its height is at most
    # this fraction of bbox height (protects tall thin objects).
    hook_max_height_ratio: float = 0.15
    # Union all components above this fraction of image area.
    min_component_area_ratio: float = 0.02


class ExportSettings(_Frozen):
    """Export quality (zoom-critical)."""

    jpeg_quality: int = 92
    # pyvips subsample_mode=off -> 4:4:4 chroma. 4:2:0 smears knit texture.
    jpeg_subsample_mode: str = "off"
    # lanczos3 on every downscale. Never sharpen.
    downscale_kernel: str = "lanczos3"
    # Strip GPS/serial/owner metadata; keep ICC profile.
    keep_icc: bool = True


class RawDevelopSettings(_Frozen):
    """rawpy (LibRaw) develop params — fixed per CLAUDE.md Stack."""

    use_camera_wb: bool = True
    no_auto_bright: bool = True
    output_color_srgb: bool = True
    output_bps: int = 16


class QaSettings(_Frozen):
    """QA gate thresholds (gates 1-8; 1-4 implemented in this foundation)."""

    # Gate 2: N edge patches within delta-E of background, low variance.
    background_edge_patches: int = 8
    background_patch_px: int = 16
    background_max_delta_e: float = 2.0
    background_max_variance: float = 4.0
    # Gate 3: fill ratio within +/- this of the mode constant.
    fill_ratio_tolerance: float = 0.02
    # Pixels farther than this delta-E from background count as non-background
    # when measuring fill (gate 3) and edge clearance (gate 4).
    non_background_delta_e: float = 2.0
    # Gates 5-8 thresholds (not yet enforced; tunables per CLAUDE.md).
    cutout_max_mean_delta_e2000: float = 3.0
    garment_min_ssim: float = 0.85
    sharpness_min_laplacian_var: float = 100.0


class TierSettings(_Frozen):
    """One rung of the escalation ladder."""

    model_snapshot: str
    resolution: str
    cost_dzd: float
    batch_cost_dzd: float | None = None
    timeout_s: float


class RoutingSettings(_Frozen):
    """Model routing — escalation ladder. Policy object, not if-statements."""

    tier1: TierSettings = TierSettings(
        model_snapshot="gemini-3.1-flash-image",
        resolution="2K",
        cost_dzd=27.0,
        batch_cost_dzd=13.0,
        timeout_s=60.0,
    )
    tier2: TierSettings = TierSettings(
        model_snapshot="gpt-image-2",
        resolution="2K",
        cost_dzd=78.0,
        batch_cost_dzd=None,
        timeout_s=360.0,  # high/2K can take 3-5 min
    )
    # Escalate to Tier 2 only after QA-gate failure on this many Tier-1 attempts.
    tier1_attempts_before_escalation: int = 2
    # A mode may be flagged tier2_default if its measured Tier-1 reject rate
    # exceeds this (allowed for mannequin).
    tier2_default_reject_rate: float = 0.25
    # Budget cap across tiers combined, enforced in the limiter BEFORE dispatch.
    budget_cap_dzd: float = 500_000.0
    # Retry 3x (exponential backoff + jitter) on RateLimited/Transport/5xx only.
    max_retries: int = 3
    retry_backoff_base_s: float = 2.0
    retry_backoff_jitter_s: float = 1.0


class ConcurrencySettings(_Frozen):
    """Performance architecture tunables."""

    # Adaptive concurrency (AIMD) per provider.
    aimd_start: int = 4
    aimd_ceiling_gemini: int = 16
    tier2_start: int = 2
    tier2_ceiling: int = 4
    # Bounded queue between Stage 1 (CPU) and Stage 2 (IO): ~2x IO concurrency.
    stage_queue_factor: int = 2
    # Segment at this size, upscale the alpha.
    segmentation_px: int = 1024
    # Batched ONNX inference.
    onnx_batch_min: int = 8
    onnx_batch_max: int = 16
    # DB writes batched: groups of ~N or every T seconds.
    db_write_batch_size: int = 50
    db_write_batch_interval_s: float = 2.0


class BarcodeSettings(_Frozen):
    """Barcode resolution + validation."""

    # Filename prefix rule: ^(\d{8,14})[_\- ]
    filename_pattern: str = r"^(\d{8,14})[_\- ]"
    # Whitelist own prefix range; supplier tags on garment labels rejected.
    prefix_whitelist: tuple[str, ...] = ("613",)


class PromptSettings(_Frozen):
    """Prompt-layer invariants (enforced server-side, case-insensitive)."""

    core_version: str = "core_v2"
    banned_age_words: tuple[str, ...] = (
        "child", "kid", "baby", "toddler", "infant", "boy", "girl",
    )
    banned_framing_words: tuple[str, ...] = (
        "crop", "margin", "aspect", "ratio", "1:1", "6:7", "resolution", "px",
    )


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_prefix="PP_", env_nested_delimiter="__", frozen=True
    )

    database_url: str = "postgresql+asyncpg://photopipe:photopipe@localhost:5432/photopipe"

    canvas: CanvasSettings = CanvasSettings()
    fill_ratios: FillRatios = FillRatios()
    bbox: BboxSettings = BboxSettings()
    export: ExportSettings = ExportSettings()
    raw_develop: RawDevelopSettings = RawDevelopSettings()
    qa: QaSettings = QaSettings()
    routing: RoutingSettings = RoutingSettings()
    concurrency: ConcurrencySettings = ConcurrencySettings()
    barcode: BarcodeSettings = BarcodeSettings()
    prompt: PromptSettings = PromptSettings()


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()
