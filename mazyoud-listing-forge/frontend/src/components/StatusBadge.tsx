import type { ImageStatus } from '../types';

const MAP: Record<ImageStatus, { label: string; cls: string }> = {
  queued: { label: 'Queued', cls: 'bg-neutral-200 text-neutral-700' },
  cleaning: { label: 'Cleaning', cls: 'bg-amber-100 text-amber-800' },
  framing: { label: 'Framing', cls: 'bg-sky-100 text-sky-800' },
  review: { label: 'Review', cls: 'bg-amber-100 text-amber-800' },
  done: { label: 'Done', cls: 'bg-emerald-100 text-emerald-800' },
  failed: { label: 'Failed', cls: 'bg-red-100 text-red-700' },
};

export default function StatusBadge({ status }: { status: ImageStatus }) {
  const s = MAP[status];
  return <span className={`badge ${s.cls}`}>{s.label}</span>;
}
