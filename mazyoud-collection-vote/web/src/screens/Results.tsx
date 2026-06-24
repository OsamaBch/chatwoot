import { useEffect, useState } from 'react';
import { api, formatPrice } from '../api';
import type { Aggregate, Verdict } from '../types';

const VERDICT_CLS: Record<Verdict, string> = {
  BUY: 'bg-keep/20 text-keep ring-keep/40',
  SKIP: 'bg-skip/20 text-skip ring-skip/40',
  REVIEW: 'bg-super/20 text-super ring-super/40',
};

export function Results({ onBack }: { onBack: () => void }) {
  const [rows, setRows] = useState<Aggregate[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    api
      .results()
      .then((r) => setRows(r.results))
      .catch(() => setError('Could not load results.'))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="mx-auto flex h-full max-w-md flex-col px-4 py-3">
      <div className="mb-3 flex items-center justify-between">
        <h1 className="text-xl font-extrabold">Ranked results</h1>
        <button onClick={onBack} className="text-sm text-neutral-400 underline">
          back
        </button>
      </div>

      {loading && <p className="text-center text-neutral-500">Loading…</p>}
      {error && <p className="text-center text-skip">{error}</p>}

      <div className="flex-1 space-y-2 overflow-y-auto pb-6">
        {rows.map((r, i) => (
          <div
            key={r.product_key}
            className="flex items-center gap-3 rounded-2xl bg-neutral-900 p-2 ring-1 ring-white/10"
          >
            <span className="w-6 shrink-0 text-center text-sm tabular-nums text-neutral-500">
              {i + 1}
            </span>
            <div className="h-14 w-14 shrink-0 overflow-hidden rounded-xl bg-neutral-800">
              {r.image_url && (
                <img
                  src={`/img?u=${encodeURIComponent(r.image_url)}`}
                  alt=""
                  className="h-full w-full object-cover"
                  loading="lazy"
                />
              )}
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-1.5">
                <span
                  className={`rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ${VERDICT_CLS[r.verdict]}`}
                >
                  {r.verdict}
                </span>
                {r.hero && <span title="super-liked">⭐</span>}
                {r.conflict && <span title="conflict">⚠️</span>}
                <span className="ml-auto text-sm font-bold tabular-nums">
                  {r.net_score > 0 ? `+${r.net_score}` : r.net_score}
                </span>
              </div>
              <div className="mt-1 flex items-center justify-between text-xs text-neutral-400">
                <span className="truncate">
                  {formatPrice(r.price)}
                  {r.category ? ` · ${r.category}` : ''}
                </span>
                <span className="shrink-0 tabular-nums">
                  K{r.keep} · S{r.super} · X{r.skip}
                </span>
              </div>
            </div>
          </div>
        ))}
        {!loading && rows.length === 0 && (
          <p className="py-8 text-center text-neutral-500">No votes yet.</p>
        )}
      </div>
    </div>
  );
}
