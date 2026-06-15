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

  // ── Output canvas / framing ─────────────────────────────────────────────────
  /** Informational; outputWidth/outputHeight are authoritative. */
  aspectRatio: string;
  outputWidth: number;
  outputHeight: number;
  /** Product longest side as a fraction of the canvas (negative-space control). */
  negativeSpaceRatio: number;
  backgroundColor: string;

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

  aspectRatio: '6:7',
  outputWidth: 1714,
  outputHeight: 2000,
  negativeSpaceRatio: 0.82,
  backgroundColor: '#FFFFFF',

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

  enableBackblaze: false,
  enableWooUpload: false,
};

/** Keys that are safe to expose to the browser (everything here is non-secret). */
export type PublicConfig = Config;

export default config;
