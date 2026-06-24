import type {
  AdminStatus,
  Aggregate,
  AppConfig,
  ProductCard,
  Progress,
  Vote,
} from './types';

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

  // --- admin ---
  adminLogin: (password: string) =>
    req<{ ok: true }>('/api/admin/login', {
      method: 'POST',
      body: JSON.stringify({ password }),
    }),
  adminLogout: () => req<{ ok: true }>('/api/admin/logout', { method: 'POST' }),
  adminMe: () => req<{ admin: true; backend: string }>('/api/admin/me'),
  adminStatus: () => req<AdminStatus>('/api/admin/status'),
  adminAddVoter: (name: string, pin: string, active = true) =>
    req<{ ok: true }>('/api/admin/voters', {
      method: 'POST',
      body: JSON.stringify({ name, pin, active }),
    }),
  adminDeleteVoter: (name: string) =>
    req<{ ok: true }>(`/api/admin/voters/${encodeURIComponent(name)}`, {
      method: 'DELETE',
    }),
};

/** Upload a workbook (multipart). Returns a short parse summary. */
export async function uploadWorkbook(
  file: File,
): Promise<{ ok: true; productCount: number; sheetName: string; categories: string[] }> {
  const fd = new FormData();
  fd.append('file', file);
  const res = await fetch('/api/admin/upload', {
    method: 'POST',
    credentials: 'include',
    body: fd, // browser sets multipart boundary; do not set content-type
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
  return body as { ok: true; productCount: number; sheetName: string; categories: string[] };
}

/** Download the results-filled workbook (triggers a browser save). */
export async function downloadExport(): Promise<void> {
  const res = await fetch('/api/admin/export', { credentials: 'include' });
  if (!res.ok) {
    const text = await res.text();
    throw new ApiError(res.status, 'export_failed', safeJson(text));
  }
  const blob = await res.blob();
  const cd = res.headers.get('content-disposition') ?? '';
  const m = cd.match(/filename="?([^"]+)"?/);
  const filename = m ? m[1] : 'mazyoud-results.xlsx';
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

/** Resolve an image URL to a loadable src: same-origin paths pass through;
 * absolute URLs go via the allow-listed /img proxy. */
export function imgSrc(url: string | null | undefined): string | undefined {
  if (!url) return undefined;
  if (url.startsWith('/')) return url;
  return `/img?u=${encodeURIComponent(url)}`;
}

/** Format a price the Mazyoud way: "3 950 DZD" (thin/space-grouped, no decimals). */
export function formatPrice(price: number | null): string {
  if (price == null) return '—';
  const rounded = Math.round(price);
  const grouped = rounded.toLocaleString('en-US').replace(/,/g, ' ');
  return `${grouped} DZD`;
}
