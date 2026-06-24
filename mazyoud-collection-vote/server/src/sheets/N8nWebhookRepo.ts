import { config } from '../config';
import type { SheetsRepo } from './SheetsRepo';
import type { Product, ResultRow, Voter, VoteRow } from './types';
import { withRetry } from './util';

/**
 * Drop-in alternative backend that talks to the user's existing n8n webhooks
 * instead of the Sheets API directly. Selected with DATA_BACKEND=n8n.
 *
 * Only the three documented webhooks are wired (products / append-vote /
 * votes). The remaining methods are intentionally light: aggregation and tab
 * bootstrap are assumed to be owned by the n8n workflows in this mode. The
 * direct GoogleSheetsRepo is the default and the fully-featured path.
 */
export class N8nWebhookRepo implements SheetsRepo {
  private productCache: { at: number; products: Product[] } | null = null;

  async getProducts(forceRefresh = false): Promise<Product[]> {
    const now = Date.now();
    if (
      !forceRefresh &&
      this.productCache &&
      now - this.productCache.at < config.productsCacheTtlMs
    ) {
      return this.productCache.products;
    }
    requireUrl(config.n8nProductsUrl, 'N8N_PRODUCTS_URL');
    const data = await postJson<unknown>(config.n8nProductsUrl, {});
    const arr = Array.isArray(data) ? data : ((data as { products?: unknown[] })?.products ?? []);
    const products = (arr as Record<string, unknown>[]).map(mapProduct);
    this.productCache = { at: now, products };
    return products;
  }

  async appendVote(v: VoteRow): Promise<void> {
    requireUrl(config.n8nAppendVoteUrl, 'N8N_APPEND_VOTE_URL');
    await postJson(config.n8nAppendVoteUrl, v);
  }

  async getVotes(): Promise<VoteRow[]> {
    requireUrl(config.n8nVotesUrl, 'N8N_VOTES_URL');
    const data = await postJson<unknown>(config.n8nVotesUrl, {});
    const arr = Array.isArray(data) ? data : ((data as { votes?: unknown[] })?.votes ?? []);
    return (arr as Record<string, unknown>[]).map(mapVote);
  }

  async getVoters(): Promise<Voter[]> {
    // No dedicated voters webhook is documented; expose a clear error so the
    // operator knows to either add one or use DATA_BACKEND=google.
    throw new Error(
      'N8nWebhookRepo.getVoters is not implemented — add a voters webhook or use DATA_BACKEND=google',
    );
  }

  // Aggregation write-back is owned by n8n in this mode → no-ops.
  async upsertResult(_r: ResultRow): Promise<void> {
    /* handled by n8n workflow */
  }

  async writeInline(): Promise<void> {
    /* handled by n8n workflow */
  }

  async ensureTabs(): Promise<void> {
    /* tabs assumed to exist / managed by n8n */
  }
}

function requireUrl(url: string, name: string): void {
  if (!url) throw new Error(`${name} is required when DATA_BACKEND=n8n`);
}

async function postJson<T>(url: string, body: unknown): Promise<T> {
  return withRetry(async () => {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(body),
    });
    if (!res.ok) {
      const err = new Error(`n8n webhook ${url} -> HTTP ${res.status}`);
      (err as { status?: number }).status = res.status;
      throw err;
    }
    const text = await res.text();
    return (text ? JSON.parse(text) : null) as T;
  }, 'n8n');
}

function mapProduct(p: Record<string, unknown>): Product {
  const anchor = num(p.row_anchor ?? p.anchor ?? p.row) ?? 0;
  const link = str(p.product_link ?? p.product_key ?? p.key ?? p.link);
  return {
    product_key: str(p.product_key ?? p.key) || (link || `row-${anchor}`),
    row_anchor: anchor,
    image_url: str(p.image_url ?? p.image) || null,
    price: num(p.price),
    color: optional(p.color),
    remark: optional(p.remark),
    category: optional(p.category),
    gender: optional(p.gender),
    notes: optional(p.notes),
    title: optional(p.title),
    supplier: optional(p.supplier),
    supplier_link: optional(p.supplier_link),
    product_link: optional(p.product_link),
  };
}

function mapVote(v: Record<string, unknown>): VoteRow {
  return {
    vote_id: str(v.vote_id),
    ts_iso: str(v.ts_iso),
    voter: str(v.voter),
    product_key: str(v.product_key),
    row_anchor: num(v.row_anchor) ?? 0,
    vote: str(v.vote) as VoteRow['vote'],
    undo_target: str(v.undo_target),
    session_id: str(v.session_id),
  };
}

function str(v: unknown): string {
  return v == null ? '' : String(v).trim();
}
function optional(v: unknown): string | undefined {
  const s = str(v);
  return s === '' ? undefined : s;
}
function num(v: unknown): number | null {
  if (typeof v === 'number' && Number.isFinite(v)) return v;
  if (typeof v === 'string' && v.trim() !== '') {
    const n = Number(v);
    if (Number.isFinite(n)) return n;
  }
  return null;
}
