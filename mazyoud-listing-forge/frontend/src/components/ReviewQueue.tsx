import type { ImageRecord } from '../types';

interface Props {
  images: ImageRecord[];
  onAccept: (id: string) => void;
  onRerun: (id: string) => void;
  onExclude: (id: string) => void;
}

/** Surfaces only the exceptions: flagged (needsReview) or failed images. */
export default function ReviewQueue({ images, onAccept, onRerun, onExclude }: Props) {
  const flagged = images.filter((im) => im.needsReview || im.status === 'failed');
  if (flagged.length === 0) return null;

  return (
    <section className="rounded-xl border border-amber-200 bg-amber-50/60 p-4">
      <h2 className="mb-1 text-sm font-semibold text-amber-800">
        Review queue · {flagged.length}
      </h2>
      <p className="mb-3 text-xs text-amber-700/80">
        Only the exceptions land here — accept to keep, re-run to retry, or exclude from the export.
      </p>
      <ul className="space-y-2">
        {flagged.map((im) => (
          <li
            key={im.id}
            className="flex items-center gap-3 rounded-lg border border-amber-200 bg-white p-2"
          >
            <img src={im.previewUrl} alt="" className="checker h-12 w-12 rounded object-contain" />
            <div className="min-w-0 flex-1">
              <div className="truncate text-xs font-medium">{im.originalName}</div>
              <div className="truncate text-[11px] text-amber-700">
                {im.status === 'failed'
                  ? im.error ?? 'Failed'
                  : [...im.warnings, ...im.flags].join(' · ') || 'Flagged'}
              </div>
            </div>
            <div className="flex shrink-0 items-center gap-1">
              {im.status !== 'failed' && (
                <button className="btn-ghost px-2 py-1 text-xs" onClick={() => onAccept(im.id)}>
                  Accept
                </button>
              )}
              <button className="btn-ghost px-2 py-1 text-xs" onClick={() => onRerun(im.id)}>
                Re-run
              </button>
              <button
                className="btn-ghost px-2 py-1 text-xs text-red-600 hover:bg-red-50"
                onClick={() => onExclude(im.id)}
              >
                Exclude
              </button>
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}
