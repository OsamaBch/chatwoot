/**
 * AiProvider — one interface, two backends (Gemini "Nano Banana Pro" + OpenAI
 * gpt-image-1). Concrete implementations are wired in phase 2/3. Phase 1 makes
 * ZERO AI calls, so this is intentionally just the contract + a guard.
 */
import type { AiProviderName } from '../config';

export interface CleanupInput {
  /** Full image to clean (e.g. remove a watermark). */
  image: Buffer;
  /** Optional mask — only the masked region is regenerated; the rest stays pixel-identical. */
  mask?: Buffer;
  prompt?: string;
}

export interface OutpaintInput {
  image: Buffer;
  /** Region of the (larger) target canvas to fill — background only. */
  region: { left: number; top: number; width: number; height: number };
  canvas: { width: number; height: number };
  prompt?: string;
}

export interface AiResult {
  image: Buffer;
  meta?: Record<string, unknown>;
}

export interface AiProvider {
  readonly name: AiProviderName;
  readonly modelId: string;
  /** Masked inpaint clean-up. The garment MUST remain pixel-identical outside the mask. */
  cleanup(input: CleanupInput): Promise<AiResult>;
  /** Generative background outpaint ONLY — never reconstructs the garment. */
  outpaint(input: OutpaintInput): Promise<AiResult>;
  /** Cheap validation call powering the Settings "Test key" button. */
  testKey(): Promise<{ ok: boolean; message: string }>;
}

// Registered in phase 2 once keys + clients exist.
export function getProvider(): AiProvider {
  throw new Error('AI provider not configured yet — wired in phase 2.');
}
