import { useEffect, useMemo, useState } from 'react';
import { api } from './api';
import { previewNames, slugifySku } from './naming';
import type { ConflictPolicy, ImageRecord, PipelineConfig } from './types';
import SkuField from './components/SkuField';
import DropZone from './components/DropZone';
import ResultGrid from './components/ResultGrid';
import BatchProgress from './components/BatchProgress';
import ReviewQueue from './components/ReviewQueue';
import SettingsModal from './components/SettingsModal';
import ConflictModal from './components/ConflictModal';

type Phase = 'idle' | 'ingesting' | 'generating';
type Notice = { kind: 'info' | 'error' | 'success'; text: string };

const msg = (e: unknown) => (e instanceof Error ? e.message : String(e));

/** Bounded-concurrency runner (respects config.concurrency for live per-image progress). */
async function runPool<T>(items: T[], n: number, fn: (t: T) => Promise<void>): Promise<void> {
  let i = 0;
  const workers = Array.from({ length: Math.min(Math.max(1, n), items.length) }, async () => {
    while (i < items.length) {
      const idx = i++;
      await fn(items[idx]);
    }
  });
  await Promise.all(workers);
}

export default function App() {
  const [config, setConfig] = useState<PipelineConfig | null>(null);
  const [sku, setSku] = useState('');
  const [images, setImages] = useState<ImageRecord[]>([]);
  const [rejected, setRejected] = useState<{ name: string; reason: string }[]>([]);
  const [phase, setPhase] = useState<Phase>('idle');
  const [exported, setExported] = useState(false);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [outputDir, setOutputDir] = useState('');
  const [conflict, setConflict] = useState<{ conflicts: string[]; outputDir: string } | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);

  useEffect(() => {
    api.getConfig().then(setConfig).catch((e) => setNotice({ kind: 'error', text: `Backend not reachable: ${msg(e)}` }));
  }, []);

  const slug = useMemo(() => slugifySku(sku, config?.filenameSeparator), [sku, config]);
  const names = useMemo(() => previewNames(images, sku, config), [images, sku, config]);

  const hasImages = images.length > 0;
  const hasFailures = images.some((im) => im.status === 'failed');
  const hasSuccessful = images.some((im) => im.status === 'done' || im.status === 'review');
  const canGenerate = !!config && sku.trim() !== '' && hasImages && phase === 'idle';
  const order = () => images.map((im) => im.id);

  // ── Ingest ────────────────────────────────────────────────────────────────
  const handleFiles = async (files: File[]) => {
    setNotice(null);
    // Auto-clear at the START OF A NEW BATCH — but never when the prior batch
    // is in an error state (keep it so the user can retry).
    if (exported && hasImages && !hasFailures) {
      try {
        await api.reset();
      } catch {
        /* best-effort */
      }
      setImages([]);
      setRejected([]);
      setSku('');
      setExported(false);
    }
    setPhase('ingesting');
    try {
      const { images: ing, rejected: rej } = await api.ingest(files);
      setImages((prev) => [...prev, ...ing]);
      if (rej.length) setRejected((prev) => [...prev, ...rej]);
      if (ing.length === 0 && rej.length) setNotice({ kind: 'error', text: 'No usable images in that selection.' });
    } catch (e) {
      setNotice({ kind: 'error', text: msg(e) }); // do NOT clear state on error
    } finally {
      setPhase('idle');
    }
  };

  // ── Generate (deterministic framing) ────────────────────────────────────────
  const frameOne = async (id: string) => {
    setImages((prev) => prev.map((im) => (im.id === id ? { ...im, status: 'framing', error: undefined } : im)));
    try {
      const { images: out } = await api.generate(sku, [id]);
      const o = out[0];
      if (o) setImages((prev) => prev.map((im) => (im.id === id ? o : im)));
    } catch (e) {
      setImages((prev) => prev.map((im) => (im.id === id ? { ...im, status: 'failed', error: msg(e) } : im)));
    }
  };

  const generateAll = async () => {
    if (!canGenerate) return;
    setNotice(null);
    setPhase('generating');
    setImages((prev) => prev.map((im) => ({ ...im, status: 'queued' })));
    await runPool(order(), config?.concurrency ?? 3, frameOne);
    setExported(false);
    setPhase('idle');
  };

  const rerun = async (id: string) => {
    if (!sku.trim()) {
      setNotice({ kind: 'error', text: 'Set a SKU before generating.' });
      return;
    }
    setExported(false);
    await frameOne(id);
  };

  // ── Grid actions ────────────────────────────────────────────────────────────
  const reorder = (ids: string[]) =>
    setImages((prev) => ids.map((id) => prev.find((im) => im.id === id)!).filter(Boolean));
  const removeImage = (id: string) => setImages((prev) => prev.filter((im) => im.id !== id));
  const acceptReview = (id: string) =>
    setImages((prev) => prev.map((im) => (im.id === id ? { ...im, needsReview: false } : im)));

  // ── Export ──────────────────────────────────────────────────────────────────
  const downloadZip = async () => {
    if (!hasSuccessful) {
      setNotice({ kind: 'error', text: 'Generate images before exporting.' });
      return;
    }
    try {
      await api.downloadZip(sku, order(), `${slug.slug || 'listing'}_listing.zip`);
      setExported(true);
      setNotice({ kind: 'success', text: 'ZIP downloaded.' });
    } catch (e) {
      setNotice({ kind: 'error', text: msg(e) });
    }
  };

  const writeFolder = async () => {
    if (!hasSuccessful) {
      setNotice({ kind: 'error', text: 'Generate images before exporting.' });
      return;
    }
    try {
      const { conflicts, outputDir: dir } = await api.exportCheck(sku, order(), outputDir);
      if (conflicts.length) {
        setConflict({ conflicts, outputDir: dir });
        return;
      }
      await doFolderExport('overwrite');
    } catch (e) {
      setNotice({ kind: 'error', text: msg(e) });
    }
  };

  const doFolderExport = async (policy: ConflictPolicy) => {
    try {
      const r = await api.exportFolder(sku, order(), outputDir, policy);
      setConflict(null);
      setExported(true);
      const extra = [
        r.skipped.length ? `skipped ${r.skipped.length}` : '',
        r.versioned.length ? `versioned ${r.versioned.length}` : '',
      ]
        .filter(Boolean)
        .join(', ');
      setNotice({
        kind: 'success',
        text: `Wrote ${r.written.length} file(s) to ${r.outputDir}${extra ? ` (${extra})` : ''}. Manifest: ${r.manifest}`,
      });
    } catch (e) {
      setNotice({ kind: 'error', text: msg(e) });
    }
  };

  const newBatch = async () => {
    try {
      await api.reset();
    } catch {
      /* best-effort */
    }
    setImages([]);
    setRejected([]);
    setSku('');
    setExported(false);
    setNotice(null);
  };

  // ── Render ──────────────────────────────────────────────────────────────────
  return (
    <div className="min-h-screen pb-24">
      <header className="sticky top-0 z-30 border-b border-neutral-200 bg-white/90 backdrop-blur">
        <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-3">
          <div className="flex items-center gap-3">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-coral font-black text-white">M</div>
            <div>
              <h1 className="text-base font-bold leading-tight">Mazyoud Listing Forge</h1>
              <p className="text-[11px] text-neutral-500">Supplier photos → clean 6:7 listing images · catalog fidelity</p>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <span className="badge bg-neutral-100 text-neutral-500">AI: off · Phase 1</span>
            <button className="btn-ghost" onClick={() => setSettingsOpen(true)}>
              Settings
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-7xl space-y-5 px-6 py-6">
        {notice && (
          <div
            className={`flex items-center justify-between rounded-lg border px-4 py-2 text-sm ${
              notice.kind === 'error'
                ? 'border-red-200 bg-red-50 text-red-700'
                : notice.kind === 'success'
                  ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                  : 'border-sky-200 bg-sky-50 text-sky-700'
            }`}
          >
            <span>{notice.text}</span>
            <button className="ml-3 text-xs opacity-60 hover:opacity-100" onClick={() => setNotice(null)}>
              ✕
            </button>
          </div>
        )}

        {/* SKU + ingest */}
        <section className="grid gap-4 md:grid-cols-[320px_1fr]">
          <div className="rounded-xl border border-neutral-200 bg-white p-4">
            <SkuField
              value={sku}
              onChange={setSku}
              slug={slug.slug}
              changed={slug.changed}
              extension={config?.fileExtension ?? '.jpg'}
            />
          </div>
          <DropZone onFiles={handleFiles} busy={phase === 'ingesting'} />
        </section>

        {rejected.length > 0 && (
          <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-700">
            Rejected {rejected.length}: {rejected.map((r) => `${r.name} (${r.reason})`).join('; ')}
          </div>
        )}

        {hasImages && <BatchProgress images={images} active={phase === 'generating'} />}

        <ReviewQueue images={images} onAccept={acceptReview} onRerun={rerun} onExclude={removeImage} />

        <section className="space-y-3">
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold">
              Result grid {hasImages && <span className="text-neutral-400">· {images.length}</span>}
            </h2>
            <p className="text-[11px] text-neutral-400">Drag a card to reorder — position 1 becomes the hero.</p>
          </div>
          <ResultGrid
            images={images}
            names={names}
            config={config}
            onReorder={reorder}
            onRemove={removeImage}
            onRerun={rerun}
          />
        </section>
      </main>

      {/* Sticky action bar */}
      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-neutral-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-3 px-6 py-3">
          <button className="btn-primary" disabled={!canGenerate} onClick={generateAll} title={canGenerate ? '' : 'Enter a SKU and add images first'}>
            {phase === 'generating' ? 'Generating…' : 'Generate'}
          </button>
          <span className="text-[11px] text-neutral-400">Deterministic framing — 0 AI calls, no cost (Phase 1).</span>

          <div className="ml-auto flex items-center gap-2">
            <input
              value={outputDir}
              onChange={(e) => setOutputDir(e.target.value)}
              placeholder="output folder (blank = ./output)"
              className="w-64 rounded-lg border border-neutral-300 px-3 py-2 text-xs focus:border-coral focus:outline-none"
            />
            <button className="btn-ghost" disabled={!hasSuccessful} onClick={writeFolder}>
              Write to folder
            </button>
            <button className="btn-ghost" disabled={!hasSuccessful} onClick={downloadZip}>
              Download ZIP
            </button>
            <button className="btn-ghost" disabled={!hasImages} onClick={newBatch}>
              New batch
            </button>
          </div>
        </div>
      </div>

      <SettingsModal open={settingsOpen} onClose={() => setSettingsOpen(false)} config={config} />
      {conflict && (
        <ConflictModal
          conflicts={conflict.conflicts}
          outputDir={conflict.outputDir}
          onResolve={doFolderExport}
          onCancel={() => setConflict(null)}
        />
      )}
    </div>
  );
}
