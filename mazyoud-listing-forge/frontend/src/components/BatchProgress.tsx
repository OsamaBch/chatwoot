import type { ImageRecord } from '../types';

interface Props {
  images: ImageRecord[];
  active: boolean;
}

export default function BatchProgress({ images, active }: Props) {
  const total = images.length;
  if (total === 0) return null;

  const count = (pred: (im: ImageRecord) => boolean) => images.filter(pred).length;
  const done = count((im) => im.status === 'done' || im.status === 'review');
  const failed = count((im) => im.status === 'failed');
  const inFlight = count((im) => im.status === 'framing' || im.status === 'cleaning');
  const settled = done + failed;
  const pct = total ? Math.round((settled / total) * 100) : 0;

  const stats: { label: string; n: number; cls: string }[] = [
    { label: 'Queued', n: count((im) => im.status === 'queued'), cls: 'text-neutral-500' },
    { label: 'Processing', n: inFlight, cls: 'text-sky-600' },
    { label: 'Done', n: count((im) => im.status === 'done'), cls: 'text-emerald-600' },
    { label: 'Review', n: count((im) => im.status === 'review'), cls: 'text-amber-600' },
    { label: 'Failed', n: failed, cls: 'text-red-600' },
  ];

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-4">
      <div className="mb-2 flex items-center justify-between text-sm">
        <span className="font-semibold">{active ? 'Processing batch…' : 'Batch'}</span>
        <span className="text-neutral-500">
          {settled}/{total} ({pct}%)
        </span>
      </div>
      <div className="h-2 w-full overflow-hidden rounded-full bg-neutral-100">
        <div
          className={`h-full rounded-full transition-all ${active ? 'bg-coral' : 'bg-emerald-500'}`}
          style={{ width: `${pct}%` }}
        />
      </div>
      <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs">
        {stats.map((s) => (
          <span key={s.label} className={s.cls}>
            <span className="font-semibold">{s.n}</span> {s.label}
          </span>
        ))}
      </div>
    </div>
  );
}
