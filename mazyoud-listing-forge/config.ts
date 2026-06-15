/**
 * config.ts — single source of truth for the Mazyoud Listing Forge pipeline.
 *
 * NO SECRETS LIVE HERE. Provider API keys are stored in the OS keychain or a
 * gitignored local app-data file (see Settings panel, phase 2) and may also be
 * supplied via .env as a fallback. This file only holds non-secret, structural
 * configuration that both the backend and (a safe subset of) the frontend use.
 *
 * The backend imports this module directly. The frontend receives the same
 * values at runtime via `GET /api/config` (so there is exactly one source).
 */

export type AiProviderName = 'gemini' | 'openai';

export interface Config {
  // ── AI provider ────────────────────────────────────────────────────────────
  // Wired in phase 2. Phase 1 performs ZERO AI calls.
  aiProvider: AiProviderName;
  /** Gemini image model — "Nano Banana Pro" (Gemini 3 Pro Image). Editable in Settings. */
  geminiModelId: string;
  /** OpenAI image model — gpt-image-1 (edits/inpaint). Editable in Settings. */
  openaiModelId: string;
  /** OpenAI image output caps ~1536px long side; larger targets get a sharp Lanczos upscale. */
  openaiMaxLongSide: number;

  // ── Output canvas / framing ─────────────────────────────────────────────────
  /** Informational; outputWidth/outputHeight are authoritative. */
  aspectRatio: string;
  outputWidth: number;
  outputHeight: number;
  /** Product longest side as a fraction of the canvas (negative-space control). */
  negativeSpaceRatio: number;
  backgroundColor: string;
  /**
   * true  → measure the product and extend the photo's own (near-uniform)
   *         background to fill the 6:7 canvas (no white bands; garment untouched).
   * false → pad with backgroundColor (white).
   */
  extendBackground: boolean;

  // ── Encoding ────────────────────────────────────────────────────────────────
  outputFormat: 'jpeg';
  /** Target file-size ceiling in KB; quality iterates down toward this. */
  jpegMaxKB: number;
  /** Quality never drops below this floor, even if over jpegMaxKB. */
  jpegQualityFloor: number;
  /** Quality to start from before iterating down. */
  jpegQualityStart: number;

  // ── Naming (MUST match the "Mazyoud SKU Image Matcher" plugin parser) ────────
  filenameSeparator: string;
  fileExtension: string;

  // ── Batch / ingest ──────────────────────────────────────────────────────────
  concurrency: number;
  /** Warn when the source's longest side is below this many pixels. */
  minSourceLongSide: number;

  // ── AI framing budget (phase 3/4) ───────────────────────────────────────────
  /** Cap on the fraction of canvas area that may be generatively outpainted. */
  maxOutpaintFraction: number;
  /** SSIM drift threshold (outside the edited region) above which we flag for review. */
  fidelityDiffThreshold: number;

  // ── AI runtime (phase 2) ────────────────────────────────────────────────────
  /** Max retries for a failed AI call. */
  aiMaxRetries: number;
  /** Base delay (ms) for exponential backoff: base * 2^attempt (+ jitter). */
  aiRetryBaseMs: number;
  /** Hard timeout per AI request (ms) so a slow provider can never hang a batch. */
  aiRequestTimeoutMs: number;
  /**
   * Auto-detect watermarks and inpaint them during Generate. OFF by default:
   * corner heuristics can mistake a real product (e.g. a hat in the corner) for a
   * badge, which is slow and risky. Prefer the manual watermark box. When off,
   * Generate is pure deterministic framing (fast, no AI, no cost).
   */
  autoDetectWatermarks: boolean;
  /** Rough per-image cost estimates (USD) — display only, editable. */
  aiPricing: { geminiPerImageUSD: number; openaiPerImageUSD: number };
  /** Batches at/above this size require an explicit confirm before generating. */
  largeBatchConfirmThreshold: number;

  // ── Feature flags (phase 5 stubs) ───────────────────────────────────────────
  enableBackblaze: boolean;
  enableWooUpload: boolean;
}

export const config: Config = {
  aiProvider: 'gemini',
  // NOTE: confirm exact, current model ids from each provider's docs in phase 2;
  // these are sane, overridable defaults so nothing is hardcoded behind a rebuild.
  geminiModelId: 'gemini-3-pro-image-preview',
  openaiModelId: 'gpt-image-1',
  openaiMaxLongSide: 1536,

  aspectRatio: '6:7',
  outputWidth: 1714,
  outputHeight: 2000,
  negativeSpaceRatio: 0.82,
  backgroundColor: '#FFFFFF',
  extendBackground: true,

  outputFormat: 'jpeg',
  jpegMaxKB: 350,
  jpegQualityFloor: 80,
  jpegQualityStart: 92,

  filenameSeparator: '-',
  fileExtension: '.jpg',

  concurrency: 3,
  minSourceLongSide: 600,

  maxOutpaintFraction: 0.25,
  fidelityDiffThreshold: 0.06,

  aiMaxRetries: 2,
  aiRetryBaseMs: 500,
  aiRequestTimeoutMs: 30000,
  autoDetectWatermarks: false,
  // Per-AI-edit estimates (editable in Settings). Note: ~80% of images need NO AI.
  // Gemini 3 Pro Image ≈ $0.134 at 2K ($0.067 batch); gpt-image-1 medium ≈ $0.04.
  aiPricing: { geminiPerImageUSD: 0.134, openaiPerImageUSD: 0.04 },
  largeBatchConfirmThreshold: 24,

  enableBackblaze: false,
  enableWooUpload: false,
};

/** Keys that are safe to expose to the browser (everything here is non-secret). */
export type PublicConfig = Config;

export default config;
