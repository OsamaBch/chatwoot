/**
 * AiProvider — one interface, two backends (Gemini "Nano Banana Pro" + OpenAI
 * gpt-image-1). Concrete implementations live in gemini.ts / openai.ts; the
 * factory is in ./index.ts. Phase 2 wires the providers + a live "Test key";
 * the cleanup/outpaint calls are exercised by the pipeline in phase 3/4.
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

/** Error carrying an HTTP-ish status so retry logic knows what's transient. */
export class ProviderError extends Error {
  status?: number;
  constructor(message: string, status?: number) {
    super(message);
    this.name = 'ProviderError';
    this.status = status;
  }
}

/** Running tally of actual AI usage/cost for a batch (shown in the UI). */
let tally = { aiCalls: 0, costUSD: 0 };
export const costTally = {
  add(calls: number, costUSD: number): void {
    tally.aiCalls += calls;
    tally.costUSD = +(tally.costUSD + costUSD).toFixed(4);
  },
  get(): { aiCalls: number; costUSD: number } {
    return { ...tally };
  },
  reset(): void {
    tally = { aiCalls: 0, costUSD: 0 };
  },
};
