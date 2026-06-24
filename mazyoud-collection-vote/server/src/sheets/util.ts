import { SHEETS_BACKOFF_BASE_MS, SHEETS_MAX_RETRIES } from '../config';

export function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * A minimal FIFO mutex. `runExclusive` chains tasks so that all write
 * operations against a single spreadsheet execute one-at-a-time, which keeps
 * us inside the Sheets per-user write quota and avoids interleaved range
 * reads/writes during the read-modify-write of the Results tab.
 */
export class Mutex {
  private tail: Promise<unknown> = Promise.resolve();

  runExclusive<T>(task: () => Promise<T>): Promise<T> {
    const run = this.tail.then(task, task);
    // Keep the chain alive even if a task rejects.
    this.tail = run.then(
      () => undefined,
      () => undefined,
    );
    return run;
  }
}

function statusOf(err: unknown): number | undefined {
  if (!err || typeof err !== 'object') return undefined;
  const e = err as Record<string, unknown>;
  if (typeof e.code === 'number') return e.code;
  if (typeof e.status === 'number') return e.status;
  const resp = e.response as Record<string, unknown> | undefined;
  if (resp && typeof resp.status === 'number') return resp.status;
  return undefined;
}

/** Retryable: rate limiting (429) and transient server errors (5xx). */
export function isRetryable(err: unknown): boolean {
  const status = statusOf(err);
  if (status === 429) return true;
  if (status !== undefined && status >= 500 && status < 600) return true;
  // Transient network errors.
  const code = (err as { code?: string } | undefined)?.code;
  return (
    code === 'ECONNRESET' ||
    code === 'ETIMEDOUT' ||
    code === 'EAI_AGAIN' ||
    code === 'ENOTFOUND'
  );
}

/**
 * Run `fn` with exponential backoff + jitter on 429/5xx/transient errors.
 * Honors a Retry-After header when the Sheets API provides one.
 */
export async function withRetry<T>(fn: () => Promise<T>, label = 'sheets'): Promise<T> {
  let attempt = 0;
  // eslint-disable-next-line no-constant-condition
  while (true) {
    try {
      return await fn();
    } catch (err) {
      attempt++;
      if (attempt > SHEETS_MAX_RETRIES || !isRetryable(err)) {
        throw err;
      }
      const retryAfter = retryAfterMs(err);
      const backoff =
        retryAfter ?? SHEETS_BACKOFF_BASE_MS * 2 ** (attempt - 1);
      const jitter = Math.floor(deterministicJitter(attempt) * 250);
      const wait = backoff + jitter;
      console.warn(
        `[${label}] retry ${attempt}/${SHEETS_MAX_RETRIES} after ${wait}ms (${describeErr(err)})`,
      );
      await sleep(wait);
    }
  }
}

function retryAfterMs(err: unknown): number | undefined {
  const headers = (err as { response?: { headers?: Record<string, string> } })?.response
    ?.headers;
  const ra = headers?.['retry-after'];
  if (!ra) return undefined;
  const secs = Number.parseInt(ra, 10);
  return Number.isFinite(secs) ? secs * 1000 : undefined;
}

// Deterministic pseudo-jitter (Math.random is unavailable in some sandboxes
// and we want reproducible behaviour); spreads retries by attempt number.
function deterministicJitter(attempt: number): number {
  const x = Math.sin(attempt * 12.9898) * 43758.5453;
  return x - Math.floor(x);
}

function describeErr(err: unknown): string {
  const status = statusOf(err);
  const msg = (err as { message?: string })?.message ?? String(err);
  return status ? `HTTP ${status}: ${msg}` : msg;
}
