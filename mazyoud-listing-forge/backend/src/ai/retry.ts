import { config } from '../config';

interface RetryOpts {
  retries?: number;
  baseMs?: number;
  onRetry?: (attempt: number, err: unknown) => void;
}

/** Retry with exponential backoff + jitter. Only retries transient failures. */
export async function withRetry<T>(fn: () => Promise<T>, opts: RetryOpts = {}): Promise<T> {
  const retries = opts.retries ?? config.aiMaxRetries;
  const baseMs = opts.baseMs ?? config.aiRetryBaseMs;
  let lastErr: unknown;
  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      return await fn();
    } catch (err) {
      lastErr = err;
      if (attempt === retries || !isRetryable(err)) break;
      const delay = baseMs * 2 ** attempt + Math.random() * baseMs;
      opts.onRetry?.(attempt + 1, err);
      await sleep(delay);
    }
  }
  throw lastErr;
}

function isRetryable(err: unknown): boolean {
  const status = (err as { status?: number } | undefined)?.status;
  if (status === undefined) return true; // network/timeout etc.
  return status === 429 || status >= 500;
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/** fetch with a hard timeout so a slow/hanging provider can never stall a batch. */
export async function fetchWithTimeout(url: string, init: RequestInit, timeoutMs: number): Promise<Response> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { ...init, signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

/** Defense-in-depth: scrub anything key-shaped before it can reach a log or UI. */
export function redact(s: string): string {
  return s
    .replace(/AIza[0-9A-Za-z\-_]{10,}/g, 'AIza…redacted')
    .replace(/sk-[A-Za-z0-9\-_]{10,}/g, 'sk-…redacted');
}
