import type { DragEvent } from 'react';
import type { ImageRecord } from '../types';
import StatusBadge from './StatusBadge';

interface Props {
  image: ImageRecord;
  position: number; // 1-based on-screen position
  filename?: string;
  isHero: boolean;
  onRemove: (id: string) => void;
  onRerun: (id: string) => void;
  onDragStart: (id: string) => void;
  onDragEnter: (id: string) => void;
  onDragEnd: () => void;
  isDragging: boolean;
}

function kb(bytes?: number): string {
  return bytes ? `${Math.round(bytes / 1024)} KB` : '—';
}

export default function ImageCard({
  image,
  position,
  filename,
  isHero,
  onRemove,
  onRerun,
  onDragStart,
  onDragEnter,
  onDragEnd,
  isDragging,
}: Props) {
  const chips = [...image.warnings, ...image.flags];

  return (
    <div
      draggable
      onDragStart={() => onDragStart(image.id)}
      onDragEnter={() => onDragEnter(image.id)}
      onDragEnd={onDragEnd}
      onDragOver={(e: DragEvent) => e.preventDefault()}
      className={`group relative flex flex-col rounded-xl border bg-white shadow-sm transition ${
        isHero ? 'border-coral ring-2 ring-coral/30' : 'border-neutral-200'
      } ${isDragging ? 'opacity-50' : ''}`}
    >
      {/* header */}
      <div className="flex items-center justify-between gap-2 border-b border-neutral-100 px-3 py-2">
        <div className="flex items-center gap-2">
          <span className="cursor-grab select-none text-neutral-400" title="Drag to reorder">
            ⠿
          </span>
          {isHero ? (
            <span className="badge bg-coral text-white">★ Hero</span>
          ) : (
            <span className="badge bg-neutral-100 text-neutral-600">#{position}</span>
          )}
        </div>
        <div className="flex items-center gap-1.5">
          {image.aiUsed && <span className="badge bg-violet-100 text-violet-700">AI cleaned</span>}
          <StatusBadge status={image.status} />
        </div>
      </div>

      {/* before / after */}
      <div className="grid grid-cols-2 gap-px bg-neutral-100">
        <figure className="bg-white">
          <img src={image.previewUrl} alt="before" className="checker aspect-[6/7] w-full object-contain" />
          <figcaption className="px-2 py-1 text-center text-[10px] uppercase tracking-wide text-neutral-400">
            Before
          </figcaption>
        </figure>
        <figure className="bg-white">
          {image.processedUrl ? (
            <img src={image.processedUrl} alt="after" className="aspect-[6/7] w-full object-contain" />
          ) : (
            <div className="flex aspect-[6/7] w-full items-center justify-center text-[11px] text-neutral-400">
              {image.status === 'failed' ? 'Failed' : 'Not generated'}
            </div>
          )}
          <figcaption className="px-2 py-1 text-center text-[10px] uppercase tracking-wide text-neutral-400">
            After {image.outputWidth ? `· ${image.outputWidth}×${image.outputHeight}` : ''}
          </figcaption>
        </figure>
      </div>

      {/* meta */}
      <div className="flex flex-1 flex-col gap-2 p-3">
        <div className="truncate text-[11px] text-neutral-500" title={image.originalName}>
          {image.originalName} · {image.sourceWidth}×{image.sourceHeight} · {image.sourceFormat}
        </div>
        {filename && (
          <code className="truncate rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-charcoal" title={filename}>
            → {filename}
          </code>
        )}
        {image.processedUrl && (
          <div className="text-[11px] text-neutral-500">
            {kb(image.outputBytes)} · q{image.outputQuality}
          </div>
        )}
        {image.error && <div className="text-[11px] text-red-600">{image.error}</div>}
        {chips.length > 0 && (
          <div className="flex flex-wrap gap-1">
            {chips.map((c, i) => (
              <span key={i} className="badge bg-amber-50 normal-case tracking-normal text-amber-700">
                {c}
              </span>
            ))}
          </div>
        )}

        <div className="mt-auto flex items-center gap-2 pt-1">
          <button className="btn-ghost px-2 py-1 text-xs" onClick={() => onRerun(image.id)}>
            Re-run
          </button>
          <button
            className="btn-ghost px-2 py-1 text-xs text-red-600 hover:bg-red-50"
            onClick={() => onRemove(image.id)}
          >
            Remove
          </button>
        </div>
      </div>
    </div>
  );
}
