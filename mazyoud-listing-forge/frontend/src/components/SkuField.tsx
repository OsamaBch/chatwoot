interface Props {
  value: string;
  onChange: (v: string) => void;
  slug: string;
  changed: boolean;
  extension: string;
}

export default function SkuField({ value, onChange, slug, changed, extension }: Props) {
  return (
    <div>
      <label className="mb-1 block text-sm font-semibold">
        SKU <span className="text-coral">*</span>
      </label>
      <input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder="e.g. ROBE-2025-014"
        spellCheck={false}
        className="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-coral focus:outline-none focus:ring-2 focus:ring-coral/30"
      />
      {value.trim() ? (
        <p className="mt-1 text-xs text-neutral-500">
          Hero file:{' '}
          <code className="rounded bg-neutral-100 px-1 py-0.5 text-charcoal">
            {slug || '—'}
            {extension}
          </code>
          {changed && <span className="ml-2 text-amber-600">⚠ slugified for filenames</span>}
        </p>
      ) : (
        <p className="mt-1 text-xs text-neutral-400">Required before you can generate.</p>
      )}
    </div>
  );
}
