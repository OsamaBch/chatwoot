import { useEffect, useRef, useState } from 'react';
import { ApiError, api, downloadExport, formatPrice, uploadWorkbook } from '../api';
import type { AdminStatus, Aggregate, Progress, Verdict } from '../types';

const VERDICT_CLS: Record<Verdict, string> = {
  BUY: 'bg-keep/20 text-keep ring-keep/40',
  SKIP: 'bg-skip/20 text-skip ring-skip/40',
  REVIEW: 'bg-super/20 text-super ring-super/40',
};

export function AdminDashboard({ onLogout }: { onLogout: () => void }) {
  const [status, setStatus] = useState<AdminStatus | null>(null);
  const [progress, setProgress] = useState<Progress | null>(null);
  const [results, setResults] = useState<Aggregate[]>([]);
  const [error, setError] = useState('');
  const [note, setNote] = useState('');
  const [uploading, setUploading] = useState(false);
  const [name, setName] = useState('');
  const [pin, setPin] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);

  const load = async () => {
    try {
      const [s, p, r] = await Promise.all([
        api.adminStatus(),
        api.progress().catch(() => null),
        api.results().catch(() => ({ results: [] })),
      ]);
      setStatus(s);
      setProgress(p);
      setResults(r.results);
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) onLogout();
      else setError('Could not load dashboard.');
    }
  };

  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const onFile = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    setError('');
    setNote('');
    try {
      const r = await uploadWorkbook(file);
      setNote(`Loaded ${r.productCount} products from "${r.sheetName}".`);
      await load();
    } catch (err) {
      const code = err instanceof ApiError ? err.code : 'upload_failed';
      if (code === 'admin_unavailable_for_backend') {
        setError(
          'Upload needs xlsx mode. Restart the server without DATA_BACKEND=demo (xlsx is the default).',
        );
      } else if (code === 'parse_failed') {
        setError('Could not parse that .xlsx — check the product layout is on the configured sheet.');
      } else if (code === 'file_too_large') {
        setError('That file is too large (max 40 MB).');
      } else {
        setError('Upload failed.');
      }
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  const addVoter = async () => {
    if (!name.trim() || !pin.trim()) return;
    setError('');
    try {
      await api.adminAddVoter(name.trim(), pin.trim());
      setName('');
      setPin('');
      await load();
    } catch {
      setError('Could not add voter.');
    }
  };

  const toggleActive = async (voterName: string, active: boolean) => {
    try {
      await api.adminAddVoter(voterName, '', active); // blank pin preserves existing
      await load();
    } catch {
      setError('Could not update voter.');
    }
  };

  const delVoter = async (voterName: string) => {
    try {
      await api.adminDeleteVoter(voterName);
      await load();
    } catch {
      setError('Could not remove voter.');
    }
  };

  const exportNow = async () => {
    setError('');
    try {
      await downloadExport();
    } catch {
      setError('Export failed.');
    }
  };

  const logout = async () => {
    try {
      await api.adminLogout();
    } catch {
      /* ignore */
    }
    onLogout();
  };

  return (
    <div className="mx-auto flex h-full max-w-md flex-col gap-4 overflow-y-auto px-4 py-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-extrabold">Admin dashboard</h1>
        <button onClick={logout} className="text-xs text-neutral-500 underline">
          logout
        </button>
      </div>

      {error && <p className="rounded-xl bg-skip/15 px-3 py-2 text-sm text-skip">{error}</p>}
      {note && <p className="rounded-xl bg-keep/15 px-3 py-2 text-sm text-keep">{note}</p>}

      {status && status.backend !== 'xlsx' && (
        <p className="rounded-xl bg-super/15 px-3 py-2 text-sm text-super">
          You're in <b>{status.backend}</b> mode (preview data). Uploading a sheet and exporting
          results need <b>xlsx</b> mode — restart the server without <code>DATA_BACKEND=demo</code>.
        </p>
      )}

      {/* Workbook */}
      <Section title="Source sheet">
        {status?.hasWorkbook && status.meta ? (
          <div className="text-sm text-neutral-300">
            <div className="font-semibold text-neutral-100">{status.meta.originalName}</div>
            <div className="text-neutral-400">
              {status.meta.productCount} products · sheet “{status.meta.sheetName}” · uploaded{' '}
              {new Date(status.meta.uploadedAt).toLocaleString()}
            </div>
          </div>
        ) : (
          <p className="text-sm text-neutral-400">No sheet uploaded yet.</p>
        )}
        <input
          ref={fileRef}
          type="file"
          accept=".xlsx"
          onChange={onFile}
          className="hidden"
          id="wb-file"
        />
        <div className="mt-3 flex gap-2">
          <label
            htmlFor="wb-file"
            className="flex-1 cursor-pointer rounded-xl bg-keep px-4 py-3 text-center text-sm font-bold active:scale-[0.99]"
          >
            {uploading ? 'Uploading…' : status?.hasWorkbook ? 'Replace .xlsx' : 'Upload .xlsx'}
          </label>
          <button
            onClick={exportNow}
            disabled={!status?.hasWorkbook}
            className="flex-1 rounded-xl bg-neutral-800 px-4 py-3 text-sm font-bold ring-1 ring-white/10 active:scale-[0.99] disabled:opacity-40"
          >
            Export results ↓
          </button>
        </div>
      </Section>

      {/* Progress */}
      {progress && (
        <Section title="Progress">
          <div className="mb-2 flex justify-between text-sm">
            <span className="text-neutral-400">Products</span>
            <span className="tabular-nums">{progress.totalProducts}</span>
          </div>
          <div className="mb-3 flex justify-between text-sm">
            <span className="text-neutral-400">Fully decided</span>
            <span className="tabular-nums">{progress.fullyDecided}</span>
          </div>
          <ul className="space-y-1">
            {progress.perVoter.map((v) => (
              <li key={v.voter} className="flex items-center justify-between text-sm">
                <span className="text-neutral-200">{v.voter}</span>
                <span className="tabular-nums text-neutral-400">
                  {v.voted} / {progress.totalProducts}
                </span>
              </li>
            ))}
            {progress.perVoter.length === 0 && (
              <li className="text-sm text-neutral-500">No voters yet.</li>
            )}
          </ul>
        </Section>
      )}

      {/* Voters */}
      <Section title="Voters">
        <ul className="mb-3 space-y-1">
          {status?.voters.map((v) => (
            <li key={v.name} className="flex items-center justify-between gap-2 text-sm">
              <span className={v.active ? 'text-neutral-100' : 'text-neutral-500 line-through'}>
                {v.name}
              </span>
              <span className="flex items-center gap-2">
                <button
                  onClick={() => toggleActive(v.name, !v.active)}
                  className="rounded-lg bg-neutral-800 px-2 py-1 text-xs ring-1 ring-white/10"
                >
                  {v.active ? 'disable' : 'enable'}
                </button>
                <button
                  onClick={() => delVoter(v.name)}
                  className="rounded-lg bg-skip/15 px-2 py-1 text-xs text-skip"
                >
                  remove
                </button>
              </span>
            </li>
          ))}
          {status && status.voters.length === 0 && (
            <li className="text-sm text-neutral-500">No voters yet — add one below.</li>
          )}
        </ul>
        <div className="flex gap-2">
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Name"
            className="min-w-0 flex-1 rounded-xl bg-neutral-800 px-3 py-2 text-sm ring-1 ring-white/10 focus:outline-none"
          />
          <input
            value={pin}
            onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))}
            placeholder="PIN"
            inputMode="numeric"
            className="w-24 rounded-xl bg-neutral-800 px-3 py-2 text-sm ring-1 ring-white/10 focus:outline-none"
          />
          <button
            onClick={addVoter}
            disabled={!name.trim() || !pin.trim()}
            className="rounded-xl bg-keep px-3 py-2 text-sm font-bold disabled:opacity-40"
          >
            add
          </button>
        </div>
      </Section>

      {/* Results */}
      <Section title="Ranked results">
        <div className="space-y-1.5">
          {results.map((r, i) => (
            <div key={r.product_key} className="flex items-center gap-2 text-sm">
              <span className="w-5 text-right text-xs tabular-nums text-neutral-500">{i + 1}</span>
              <span className={`rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ${VERDICT_CLS[r.verdict]}`}>
                {r.verdict}
              </span>
              {r.hero && <span title="super-liked">⭐</span>}
              {r.conflict && <span title="conflict">⚠️</span>}
              <span className="truncate text-neutral-400">{formatPrice(r.price)}</span>
              <span className="ml-auto shrink-0 font-bold tabular-nums">
                {r.net_score > 0 ? `+${r.net_score}` : r.net_score}
              </span>
            </div>
          ))}
          {results.length === 0 && <p className="text-sm text-neutral-500">No votes yet.</p>}
        </div>
      </Section>
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="rounded-2xl bg-neutral-900 p-4 ring-1 ring-white/10">
      <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-400">{title}</h2>
      {children}
    </section>
  );
}
