import type { Product, ResultRow, Voter, VoteRow } from './types';

/**
 * Storage abstraction. All Google access lives behind this interface so the
 * backend (direct googleapis vs. n8n webhooks) can be swapped via DATA_BACKEND
 * without touching the API/aggregation layers.
 */
export interface SheetsRepo {
  /** Parse the source product tab (AID 1). Implementations cache internally. */
  getProducts(forceRefresh?: boolean): Promise<Product[]>;

  /** Read the Voters login list. */
  getVoters(): Promise<Voter[]>;

  /** Append a single vote row (append-only — never overwrite). */
  appendVote(v: VoteRow): Promise<void>;

  /** Read the full raw Votes log (for aggregation + resume). */
  getVotes(): Promise<VoteRow[]>;

  /** Upsert one product's computed aggregate into the Results tab. */
  upsertResult(r: ResultRow): Promise<void>;

  /** Optional inline mirror into AID 1 columns W..Z on an anchor row. */
  writeInline(
    anchor: number,
    net: number,
    verdict: string,
    conflict: boolean,
    breakdown: string,
  ): Promise<void>;

  /** Bootstrap managed tabs (Voters/Votes/Results) + header rows + W..Z headers. */
  ensureTabs(): Promise<void>;
}
