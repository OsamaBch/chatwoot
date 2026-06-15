import { useEffect, useState } from 'react';
import { api } from '../api';
import type { AiProviderName, AppSettings, PipelineConfig } from '../types';

interface Props {
  open: boolean;
  onClose: () => void;
  config: PipelineConfig | null;
  onChanged: () => void; // tell the parent to refresh its header badge
}

type TestState = { ok: boolean; message: string } | 'testing' | null;

export default function SettingsModal({ open, onClose, config, onChanged }: Props) {
  const [settings, setSettings] = useState<AppSettings | null>(null);
  const [keyInput, setKeyInput] = useState<Record<AiProviderName, string>>({ gemini: '', openai: '' });
  const [modelInput, setModelInput] = useState<Record<AiProviderName, string>>({ gemini: '', openai: '' });
  const [test, setTest] = useState<Record<AiProviderName, TestState>>({ gemini: null, openai: null });
  const [busy, setBusy] = useState(false);

  const load = async () => {
    const s = await api.getSettings();
    setSettings(s);
    setModelInput({ gemini: s.geminiModelId, openai: s.openaiModelId });
  };

  useEffect(() => {
    if (open) {
      setTest({ gemini: null, openai: null });
      setKeyInput({ gemini: '', openai: '' });
      load().catch(() => undefined);
    }
  }, [open]);

  if (!open) return null;

  const refresh = async () => {
    await load();
    onChanged();
  };

  const changeProvider = async (provider: AiProviderName) => {
    setBusy(true);
    try {
      await api.saveSettings({ provider });
      await refresh();
    } finally {
      setBusy(false);
    }
  };

  const saveModel = async (provider: AiProviderName) => {
    setBusy(true);
    try {
      await api.saveSettings(provider === 'gemini' ? { geminiModelId: modelInput.gemini } : { openaiModelId: modelInput.openai });
      await refresh();
    } finally {
      setBusy(false);
    }
  };

  const saveKey = async (provider: AiProviderName) => {
    const key = keyInput[provider].trim();
    if (!key) return;
    setBusy(true);
    try {
      await api.saveKey(provider, key);
      setKeyInput((p) => ({ ...p, [provider]: '' }));
      await refresh();
    } finally {
      setBusy(false);
    }
  };

  const clearKey = async (provider: AiProviderName) => {
    setBusy(true);
    try {
      await api.clearKey(provider);
      setTest((p) => ({ ...p, [provider]: null }));
      await refresh();
    } finally {
      setBusy(false);
    }
  };

  const testKey = async (provider: AiProviderName) => {
    setTest((p) => ({ ...p, [provider]: 'testing' }));
    try {
      const r = await api.testKey(provider);
      setTest((p) => ({ ...p, [provider]: r }));
    } catch (e) {
      setTest((p) => ({ ...p, [provider]: { ok: false, message: e instanceof Error ? e.message : 'Test failed' } }));
    }
  };

  const providers: { id: AiProviderName; label: string; sub: string }[] = [
    { id: 'gemini', label: 'Gemini — Nano Banana Pro', sub: 'Gemini 3 Pro Image · native 2K–4K · recommended for fabric clarity' },
    { id: 'openai', label: 'OpenAI — gpt-image-1', sub: 'edits/inpaint · ~1536px long side (auto Lanczos upscale beyond)' },
  ];

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-auto bg-charcoal/40 p-6" onClick={onClose}>
      <div className="my-6 w-full max-w-2xl rounded-2xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-bold">Settings</h2>
          <button className="btn-ghost px-2 py-1 text-xs" onClick={onClose}>
            Close
          </button>
        </div>

        <section className="mb-5">
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-400">AI Provider</h3>
          <div className="space-y-3">
            {providers.map((p) => {
              const ks = settings?.keys[p.id];
              const active = settings?.provider === p.id;
              const t = test[p.id];
              return (
                <div key={p.id} className={`rounded-xl border p-3 ${active ? 'border-coral ring-1 ring-coral/30' : 'border-neutral-200'}`}>
                  <label className="flex cursor-pointer items-start gap-3">
                    <input type="radio" name="provider" className="mt-1 accent-coral" checked={!!active} disabled={busy} onChange={() => changeProvider(p.id)} />
                    <div className="flex-1">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-semibold">{p.label}</span>
                        {active && <span className="badge bg-coral text-white">Active</span>}
                        <KeyPill source={ks?.source ?? 'none'} hasKey={!!ks?.hasKey} />
                      </div>
                      <p className="text-[11px] text-neutral-500">{p.sub}</p>
                    </div>
                  </label>

                  <div className="mt-3 grid gap-2 pl-7">
                    {/* model id */}
                    <div className="flex items-center gap-2">
                      <span className="w-16 shrink-0 text-[11px] text-neutral-500">Model</span>
                      <input
                        value={modelInput[p.id]}
                        onChange={(e) => setModelInput((m) => ({ ...m, [p.id]: e.target.value }))}
                        spellCheck={false}
                        className="flex-1 rounded-lg border border-neutral-300 px-2 py-1.5 font-mono text-xs focus:border-coral focus:outline-none"
                      />
                      <button className="btn-ghost px-2 py-1 text-xs" disabled={busy} onClick={() => saveModel(p.id)}>
                        Save
                      </button>
                    </div>
                    {/* key */}
                    <div className="flex items-center gap-2">
                      <span className="w-16 shrink-0 text-[11px] text-neutral-500">API key</span>
                      <input
                        type="password"
                        value={keyInput[p.id]}
                        onChange={(e) => setKeyInput((m) => ({ ...m, [p.id]: e.target.value }))}
                        placeholder={ks?.hasKey ? `•••••••• (${ks.source})` : 'paste key (stored in OS keychain file)'}
                        spellCheck={false}
                        className="flex-1 rounded-lg border border-neutral-300 px-2 py-1.5 text-xs focus:border-coral focus:outline-none"
                      />
                      <button className="btn-ghost px-2 py-1 text-xs" disabled={busy || !keyInput[p.id].trim()} onClick={() => saveKey(p.id)}>
                        Save
                      </button>
                      <button className="btn-ghost px-2 py-1 text-xs" disabled={busy || !ks?.hasKey} onClick={() => clearKey(p.id)}>
                        Clear
                      </button>
                      <button className="btn-ghost px-2 py-1 text-xs" disabled={!ks?.hasKey || t === 'testing'} onClick={() => testKey(p.id)}>
                        {t === 'testing' ? 'Testing…' : 'Test key'}
                      </button>
                    </div>
                    {t && t !== 'testing' && (
                      <p className={`pl-[4.5rem] text-[11px] ${t.ok ? 'text-emerald-600' : 'text-red-600'}`}>
                        {t.ok ? '✓ ' : '✗ '}
                        {t.message}
                      </p>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
          <p className="mt-2 text-[11px] text-neutral-400">
            Keys are stored in a 0600 file in your OS app-data folder (never committed, never logged). <code className="rounded bg-neutral-100 px-1">.env</code> is a fallback.
          </p>
        </section>

        <section>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-400">Active pipeline config</h3>
          {config ? (
            <div className="grid grid-cols-2 gap-x-6 rounded-lg border border-neutral-200 p-3 text-sm">
              <Row label="Canvas" value={`${config.outputWidth}×${config.outputHeight} (${config.aspectRatio})`} />
              <Row label="Negative space" value={`${Math.round(config.negativeSpaceRatio * 100)}%`} />
              <Row label="JPEG target" value={`≤ ${config.jpegMaxKB} KB · floor q${config.jpegQualityFloor}`} />
              <Row label="Filename" value={`{SKU}${config.filenameSeparator}{n}${config.fileExtension}`} />
              <Row label="Concurrency" value={String(config.concurrency)} />
              <Row label="Est. cost / image" value={`$${settings?.pricing[settings.provider === 'openai' ? 'openaiPerImageUSD' : 'geminiPerImageUSD'] ?? '—'}`} />
            </div>
          ) : (
            <p className="text-sm text-neutral-400">Loading…</p>
          )}
          <p className="mt-2 text-[11px] text-neutral-400">
            Structural settings live in <code className="rounded bg-neutral-100 px-1">config.ts</code> (single source of truth).
          </p>
        </section>
      </div>
    </div>
  );
}

function KeyPill({ source, hasKey }: { source: string; hasKey: boolean }) {
  if (!hasKey) return <span className="badge bg-neutral-100 text-neutral-500">no key</span>;
  return <span className="badge bg-emerald-100 text-emerald-700">key: {source}</span>;
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between border-b border-neutral-100 py-1.5 last:border-0">
      <span className="text-neutral-500">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  );
}
