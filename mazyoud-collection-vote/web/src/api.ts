import type { Aggregate, AppConfig, ProductCard, Progress, Vote } from './types';

export class ApiError extends Error {
  status: number;
  code: string;
  body: unknown;
  constructor(status: number, code: string, body: unknown) {
    super(code);
    this.status = status;
    this.code = code;
    this.body = body;
  }
}

async function req<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(path, {
    credentials: 'include',
    headers: { 'content-type': 'application/json' },
    ...init,
  });
  const text = await res.text();
  const body = text ? safeJson(text) : null;
  if (!res.ok) {
    let code = `http_${res.status}`;
    if (body && typeof body === 'object' && 'error' in body) {
      code = String((body as { error: unknown }).error);
    }
    throw new ApiError(res.status, code, body);
  }
  return body as T;
}

function safeJson(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}

export const api = {
  config: () => req<AppConfig>('/api/config'),

  voters: () => req<{ voters: string[] }>('/api/voters'),

  login: (name: string, pin: string) =>
    req<{ voter: string; votedKeys: string[] }>('/api/login', {
      method: 'POST',
      body: JSON.stringify({ name, pin }),
    }),

  logout: () => req<{ ok: true }>('/api/logout', { method: 'POST' }),

  me: () => req<{ voter: string }>('/api/me'),

  categories: () => req<{ categories: string[] }>('/api/categories'),

  products: (category?: string) =>
    req<{ products: ProductCard[]; showDetails: boolean }>(
      `/api/products${category && category !== 'All' ? `?category=${encodeURIComponent(category)}` : ''}`,
    ),

  vote: (product_key: string, row_anchor: number, vote: Vote) =>
    req<{ ok: true; vote_id: string; aggregate: Aggregate }>('/api/vote', {
      method: 'POST',
      body: JSON.stringify({ product_key, row_anchor, vote }),
    }),

  undo: (opts: { target_vote_id?: string; product_key?: string }) =>
    req<{ ok: true; undone: string; product_key: string; aggregate: Aggregate }>('/api/undo', {
      method: 'POST',
      body: JSON.stringify(opts),
    }),

  progress: () => req<Progress>('/api/progress'),

  results: () => req<{ results: Aggregate[] }>('/api/results'),
};

/** Format a price the Mazyoud way: "3 950 DZD" (thin/space-grouped, no decimals). */
export function formatPrice(price: number | null): string {
  if (price == null) return '—';
  const rounded = Math.round(price);
  const grouped = rounded.toLocaleString('en-US').replace(/,/g, ' ');
  return `${grouped} DZD`;
}
