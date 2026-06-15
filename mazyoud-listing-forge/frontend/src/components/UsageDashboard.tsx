import { useEffect, useState } from 'react';
import { api } from '../api';
import type { ProviderTotals, Usage } from '../types';

interface Props {
  open: boolean;
  onClose: () => void;
}

const usd = (n: number) => `$${n.toFixed(2)}`;

export default function UsageDashboard({ open, onClose }: Props) {
  const [usage, setUsage] = useState<Usage | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (open) api.getUsage().then(setUsage).catch(() => undefined);
  }, [open]);

  if (!open) return null;

  const clear = async () => {
    if (!window.confirm('Clear all consumption history?')) return;
    setBusy(true);
    try {
      setUsage(await api.clearUsage());
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-auto bg-charcoal/40 p-6" onClick={onClose}>
      <div className="my-6 w-full max-w-2xl rounded-2xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-bold">Consumption dashboard</h2>
          <div className="flex gap-2">
            <button className="btn-ghost px-2 py-1 text-xs" disabled={busy || !usage?.totals.runs} onClick={clear}>
              Clear history
            </button>
            <button className="btn-ghost px-2 py-1 text-xs" onClick={onClose}>
              Close
            </button>
          </div>
        </div>

        {!usage ? (
          <p className="text-sm text-neutral-400">Loading…</p>
        ) : (
          <>
            <div className="grid grid-cols-4 gap-3">
              <Stat label="Runs" value={String(usage.totals.runs)} />
              <Stat label="Images" value={String(usage.totals.images)} />
              <Stat label="AI calls" value={String(usage.totals.aiCalls)} />
              <Stat label="Est. spend" value={usd(usage.totals.costUSD)} accent />
            </div>

            <div className="mt-3 grid grid-cols-2 gap-3">
              <ProviderCard label="Gemini" t={usage.byProvider.gemini} />
              <ProviderCard label="OpenAI" t={usage.byProvider.openai} />
            </div>

            <h3 className="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-neutral-400">Recent runs</h3>
            {usage.runs.length === 0 ? (
              <p className="text-sm text-neutral-400">No runs yet — generate a batch to see it here.</p>
            ) : (
              <div className="overflow-hidden rounded-lg border border-neutral-200">
                <table className="w-full text-left text-xs">
                  <thead className="bg-neutral-50 text-neutral-500">
                    <tr>
                      <th className="px-3 py-2 font-medium">When</th>
                      <th className="px-3 py-2 font-medium">SKU</th>
                      <th className="px-3 py-2 font-medium">Provider</th>
                      <th className="px-3 py-2 text-right font-medium">Images</th>
                      <th className="px-3 py-2 text-right font-medium">AI</th>
                      <th className="px-3 py-2 text-right font-medium">Cost</th>
                    </tr>
                  </thead>
                  <tbody>
                    {usage.runs.map((r, i) => (
                      <tr key={i} className="border-t border-neutral-100">
                        <td className="px-3 py-2 text-neutral-500">{new Date(r.ts).toLocaleString()}</td>
                        <td className="px-3 py-2 font-medium">{r.sku || '—'}</td>
                        <td className="px-3 py-2">{r.provider}</td>
                        <td className="px-3 py-2 text-right">{r.images}</td>
                        <td className="px-3 py-2 text-right">{r.aiCalls}</td>
                        <td className="px-3 py-2 text-right">{usd(r.costUSD)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <p className="mt-3 text-[11px] text-neutral-400">
              Estimated spend uses your editable per-edit pricing in Settings. ~80% of images need no AI, so real spend is
              typically far below images × price.
            </p>
          </>
        )}
      </div>
    </div>
  );
}

function Stat({ label, value, accent }: { label: string; value: string; accent?: boolean }) {
  return (
    <div className={`rounded-xl border p-3 ${accent ? 'border-coral bg-coral/5' : 'border-neutral-200'}`}>
      <div className="text-[11px] uppercase tracking-wide text-neutral-400">{label}</div>
      <div className="text-xl font-bold">{value}</div>
    </div>
  );
}

function ProviderCard({ label, t }: { label: string; t: ProviderTotals }) {
  return (
    <div className="rounded-lg border border-neutral-200 p-3 text-sm">
      <div className="font-semibold">{label}</div>
      <div className="text-neutral-500">
        {t.aiCalls} AI calls · {usd(t.costUSD)}
      </div>
    </div>
  );
}
