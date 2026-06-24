import {
  AGGREGATE_DEBOUNCE_MS,
  BUY_THRESHOLD,
  CONFLICT_MIN_SHARE,
  SKIP_THRESHOLD,
  WEIGHT_KEEP,
  WEIGHT_SKIP,
  WEIGHT_SUPER,
  type Verdict,
  type VoteValue,
} from './config';
import type { SheetsRepo } from './sheets/SheetsRepo';
import type { Product, ResultRow, VoteRow } from './sheets/types';

const VOTES_CACHE_TTL_MS = 1000;

export interface Tally {
  keep: number;
  skip: number;
  super: number;
  voters: number;
  net_score: number;
  positive: number;
  hero: boolean;
  conflict: boolean;
  verdict: Verdict;
  breakdown: string;
}

/**
 * Resolve each voter's authoritative vote for a single product from the raw,
 * append-only log.
 *
 * Rule: for a given (voter, product_key) the most recent keep|skip|super row
 * that has NOT been undone is that voter's vote. Undo rows (vote = "undo")
 * carry the undone vote_id in undo_target; those targets are netted out.
 */
export function resolveProductVotes(
  allVotes: VoteRow[],
  productKey: string,
): Map<string, VoteValue> {
  // Stable chronological order: ts_iso, then original append order as tiebreak.
  const rows = allVotes
    .map((v, i) => ({ v, i }))
    .filter((x) => x.v.product_key === productKey)
    .sort((a, b) => {
      if (a.v.ts_iso < b.v.ts_iso) return -1;
      if (a.v.ts_iso > b.v.ts_iso) return 1;
      return a.i - b.i;
    });

  // Collect all undone vote_ids for this product.
  const undone = new Set<string>();
  for (const { v } of rows) {
    if (v.vote === 'undo' && v.undo_target) undone.add(v.undo_target);
  }

  // Last non-undone keep|skip|super per voter wins.
  const byVoter = new Map<string, VoteValue>();
  for (const { v } of rows) {
    if (v.vote === 'undo') continue;
    if (undone.has(v.vote_id)) continue;
    if (v.vote === 'keep' || v.vote === 'skip' || v.vote === 'super') {
      byVoter.set(v.voter, v.vote);
    }
  }
  return byVoter;
}

/**
 * Resolve one voter's authoritative vote for EVERY product they touched.
 * Used for login resume (votedKeys) and the per-product `myVote` field.
 */
export function resolveVoterVotes(
  allVotes: VoteRow[],
  voter: string,
): Map<string, VoteValue> {
  const rows = allVotes
    .map((v, i) => ({ v, i }))
    .filter((x) => x.v.voter === voter)
    .sort((a, b) => {
      if (a.v.ts_iso < b.v.ts_iso) return -1;
      if (a.v.ts_iso > b.v.ts_iso) return 1;
      return a.i - b.i;
    });

  const undone = new Set<string>();
  for (const { v } of rows) {
    if (v.vote === 'undo' && v.undo_target) undone.add(v.undo_target);
  }

  const byProduct = new Map<string, VoteValue>();
  for (const { v } of rows) {
    if (v.vote === 'undo') continue;
    if (undone.has(v.vote_id)) continue;
    if (v.vote === 'keep' || v.vote === 'skip' || v.vote === 'super') {
      byProduct.set(v.product_key, v.vote);
    }
  }
  return byProduct;
}

/**
 * Find the most recent non-undone vote_id by a voter for a product (the row an
 * undo should target). Returns null when there is nothing to undo.
 */
export function lastVoteIdFor(
  allVotes: VoteRow[],
  voter: string,
  productKey: string,
): { vote_id: string; row_anchor: number } | null {
  const rows = allVotes
    .map((v, i) => ({ v, i }))
    .filter((x) => x.v.voter === voter && x.v.product_key === productKey)
    .sort((a, b) => {
      if (a.v.ts_iso < b.v.ts_iso) return -1;
      if (a.v.ts_iso > b.v.ts_iso) return 1;
      return a.i - b.i;
    });

  const undone = new Set<string>();
  for (const { v } of rows) {
    if (v.vote === 'undo' && v.undo_target) undone.add(v.undo_target);
  }

  for (let i = rows.length - 1; i >= 0; i--) {
    const v = rows[i].v;
    if (v.vote === 'undo') continue;
    if (undone.has(v.vote_id)) continue;
    if (v.vote === 'keep' || v.vote === 'skip' || v.vote === 'super') {
      return { vote_id: v.vote_id, row_anchor: v.row_anchor };
    }
  }
  return null;
}

/** Apply weights + verdict rules to a resolved per-voter vote map. */
export function tally(resolved: Map<string, VoteValue>): Tally {
  let keep = 0;
  let skip = 0;
  let sup = 0;
  for (const vote of resolved.values()) {
    if (vote === 'keep') keep++;
    else if (vote === 'skip') skip++;
    else if (vote === 'super') sup++;
  }

  const voters = keep + skip + sup;
  const net_score = keep * WEIGHT_KEEP + sup * WEIGHT_SUPER + skip * WEIGHT_SKIP;
  const positive = keep + sup;
  const hero = sup >= 1;

  const minoritySide = Math.min(positive, skip);
  const conflict =
    positive >= 1 && skip >= 1 && voters > 0 && minoritySide / voters >= CONFLICT_MIN_SHARE;

  let verdict: Verdict;
  if (net_score >= BUY_THRESHOLD && !conflict) verdict = 'BUY';
  else if (net_score <= SKIP_THRESHOLD) verdict = 'SKIP';
  else verdict = 'REVIEW';

  const breakdown = `keep:${keep} skip:${skip} super:${sup}`;

  return { keep, skip, super: sup, voters, net_score, positive, hero, conflict, verdict, breakdown };
}

/** Build a full Results row from a product + its resolved votes. */
export function buildResultRow(
  product: Product | undefined,
  productKey: string,
  fallbackAnchor: number,
  t: Tally,
  nowIso: string,
): ResultRow {
  return {
    product_key: productKey,
    row_anchor: product?.row_anchor ?? fallbackAnchor,
    title: product?.title ?? '',
    price: product?.price ?? null,
    category: product?.category ?? '',
    gender: product?.gender ?? '',
    image_url: product?.image_url ?? '',
    keep: t.keep,
    skip: t.skip,
    super: t.super,
    voters: t.voters,
    net_score: t.net_score,
    verdict: t.verdict,
    conflict: t.conflict,
    hero: t.hero,
    last_updated: nowIso,
  };
}

/**
 * Owns recompute + write-back hygiene:
 *  - a ~1s votes read cache (coalesces concurrent recomputes across products)
 *  - per-product debounce of the Results/inline write (coalesces bursts)
 *  - the actual write goes through the repo, which serializes + backs off.
 */
export class Aggregator {
  private votesCache: { at: number; votes: VoteRow[] } | null = null;
  private pendingReads: Promise<VoteRow[]> | null = null;
  private timers = new Map<string, NodeJS.Timeout>();

  constructor(
    private repo: SheetsRepo,
    private getProductByKey: (key: string) => Product | undefined,
    private now: () => string = () => new Date().toISOString(),
  ) {}

  /** Drop the cached votes so the next compute reads fresh (call after writes). */
  invalidateVotes(): void {
    this.votesCache = null;
  }

  /**
   * Public ~1s-cached votes read. Read endpoints share this so a burst of
   * concurrent requests collapses to one Sheets read. Always fresh right after
   * a write because every append calls invalidateVotes().
   */
  async votesCached(): Promise<VoteRow[]> {
    return this.getVotesCached();
  }

  private async getVotesCached(): Promise<VoteRow[]> {
    const t = Date.now();
    if (this.votesCache && t - this.votesCache.at < VOTES_CACHE_TTL_MS) {
      return this.votesCache.votes;
    }
    if (this.pendingReads) return this.pendingReads;
    this.pendingReads = this.repo
      .getVotes()
      .then((votes) => {
        this.votesCache = { at: Date.now(), votes };
        this.pendingReads = null;
        return votes;
      })
      .catch((err) => {
        this.pendingReads = null;
        throw err;
      });
    return this.pendingReads;
  }

  /** Compute the current aggregate for a product WITHOUT writing it back. */
  async computeResult(productKey: string, fallbackAnchor = 0): Promise<ResultRow> {
    const votes = await this.getVotesCached();
    const resolved = resolveProductVotes(votes, productKey);
    const t = tally(resolved);
    const product = this.getProductByKey(productKey);
    const anchor =
      product?.row_anchor ??
      (fallbackAnchor ||
        lastAnchorFor(votes, productKey) ||
        0);
    return buildResultRow(product, productKey, anchor, t, this.now());
  }

  /** Recompute and persist (Results upsert + optional inline write-back). */
  async writeResult(productKey: string, fallbackAnchor = 0): Promise<ResultRow> {
    const result = await this.computeResult(productKey, fallbackAnchor);
    await this.repo.upsertResult(result);
    await this.repo.writeInline(
      result.row_anchor,
      result.net_score,
      result.verdict,
      result.conflict,
      `keep:${result.keep} skip:${result.skip} super:${result.super}`,
    );
    return result;
  }

  /** Debounced persist: coalesce bursts of votes on the same product. */
  schedule(productKey: string, fallbackAnchor = 0): void {
    const existing = this.timers.get(productKey);
    if (existing) clearTimeout(existing);
    const timer = setTimeout(() => {
      this.timers.delete(productKey);
      this.writeResult(productKey, fallbackAnchor).catch((err) => {
        console.error(`[aggregate] write failed for ${productKey}:`, err?.message ?? err);
      });
    }, AGGREGATE_DEBOUNCE_MS);
    // Don't keep the event loop alive solely for a pending aggregate flush.
    if (typeof timer.unref === 'function') timer.unref();
    this.timers.set(productKey, timer);
  }

  /** Flush all pending debounced writes immediately (used on shutdown). */
  async flushAll(): Promise<void> {
    const keys = [...this.timers.keys()];
    for (const key of keys) {
      const t = this.timers.get(key);
      if (t) clearTimeout(t);
      this.timers.delete(key);
    }
    for (const key of keys) {
      try {
        await this.writeResult(key);
      } catch (err) {
        console.error(`[aggregate] flush failed for ${key}:`, err);
      }
    }
  }
}

function lastAnchorFor(votes: VoteRow[], productKey: string): number {
  for (let i = votes.length - 1; i >= 0; i--) {
    if (votes[i].product_key === productKey && votes[i].row_anchor) {
      return votes[i].row_anchor;
    }
  }
  return 0;
}
