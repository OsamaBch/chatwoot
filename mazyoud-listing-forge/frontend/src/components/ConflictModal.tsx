import type { ConflictPolicy } from '../types';

interface Props {
  conflicts: string[];
  outputDir: string;
  onResolve: (policy: ConflictPolicy) => void;
  onCancel: () => void;
}

export default function ConflictModal({ conflicts, outputDir, onResolve, onCancel }: Props) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-charcoal/40 p-6" onClick={onCancel}>
      <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <h2 className="text-lg font-bold">Files already exist</h2>
        <p className="mt-1 text-sm text-neutral-500">
          {conflicts.length} file{conflicts.length > 1 ? 's' : ''} in{' '}
          <code className="rounded bg-neutral-100 px-1 text-xs">{outputDir}</code> would be overwritten:
        </p>
        <ul className="my-3 max-h-32 overflow-auto rounded-lg border border-neutral-200 bg-neutral-50 p-2 text-xs">
          {conflicts.map((c) => (
            <li key={c} className="font-mono">
              {c}
            </li>
          ))}
        </ul>
        <div className="flex flex-col gap-2">
          <button className="btn-primary" onClick={() => onResolve('version')}>
            Version (keep both, append -v2)
          </button>
          <button className="btn-ghost" onClick={() => onResolve('overwrite')}>
            Overwrite existing
          </button>
          <button className="btn-ghost" onClick={() => onResolve('skip')}>
            Skip conflicting files
          </button>
          <button className="btn-ghost border-0 text-neutral-400" onClick={onCancel}>
            Cancel
          </button>
        </div>
      </div>
    </div>
  );
}
