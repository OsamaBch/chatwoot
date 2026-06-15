import type { PipelineConfig } from '../types';

interface Props {
  open: boolean;
  onClose: () => void;
  config: PipelineConfig | null;
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between border-b border-neutral-100 py-1.5 text-sm last:border-0">
      <span className="text-neutral-500">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  );
}

export default function SettingsModal({ open, onClose, config }: Props) {
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center bg-charcoal/40 p-6" onClick={onClose}>
      <div
        className="mt-10 w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-bold">Settings</h2>
          <button className="btn-ghost px-2 py-1 text-xs" onClick={onClose}>
            Close
          </button>
        </div>

        {/* Provider — wired in Phase 2 */}
        <section className="mb-5">
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-400">AI Provider</h3>
          <div className="rounded-lg border border-dashed border-neutral-300 bg-neutral-50 p-3">
            <div className="grid grid-cols-2 gap-2">
              <select
                disabled
                value={config?.aiProvider ?? 'gemini'}
                className="rounded-lg border border-neutral-300 bg-white px-2 py-1.5 text-sm disabled:opacity-60"
              >
                <option value="gemini">Gemini (Nano Banana Pro)</option>
                <option value="openai">OpenAI (gpt-image-1)</option>
              </select>
              <input
                disabled
                placeholder="API key — keychain (Phase 2)"
                className="rounded-lg border border-neutral-300 bg-white px-2 py-1.5 text-sm disabled:opacity-60"
                type="password"
              />
            </div>
            <div className="mt-2 flex items-center gap-2">
              <button className="btn-ghost px-2 py-1 text-xs" disabled>
                Test key
              </button>
              <button className="btn-ghost px-2 py-1 text-xs" disabled>
                Clear key
              </button>
              <span className="text-[11px] text-neutral-400">
                Masked key, keychain storage & test call land in Phase 2.
              </span>
            </div>
          </div>
        </section>

        {/* Active pipeline config (read-only, from /api/config) */}
        <section>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-400">
            Active pipeline config
          </h3>
          {config ? (
            <div className="rounded-lg border border-neutral-200 px-3">
              <Row label="Canvas" value={`${config.outputWidth}×${config.outputHeight} (${config.aspectRatio})`} />
              <Row label="Negative space" value={`${Math.round(config.negativeSpaceRatio * 100)}%`} />
              <Row label="Background" value={config.backgroundColor} />
              <Row label="JPEG target" value={`≤ ${config.jpegMaxKB} KB · floor q${config.jpegQualityFloor}`} />
              <Row label="Filename" value={`{SKU}${config.filenameSeparator}{n}${config.fileExtension}`} />
              <Row label="Concurrency" value={String(config.concurrency)} />
              <Row label="Min source long side" value={`${config.minSourceLongSide}px`} />
              <Row label="Gemini model" value={config.geminiModelId} />
              <Row label="OpenAI model" value={config.openaiModelId} />
              <Row label="Backblaze upload" value={config.enableBackblaze ? 'on' : 'off (stub)'} />
              <Row label="Woo upload" value={config.enableWooUpload ? 'on' : 'off (stub)'} />
            </div>
          ) : (
            <p className="text-sm text-neutral-400">Loading…</p>
          )}
          <p className="mt-2 text-[11px] text-neutral-400">
            Edit these in <code className="rounded bg-neutral-100 px-1">config.ts</code> (single source of truth).
          </p>
        </section>
      </div>
    </div>
  );
}
