import { useRef, useState } from 'react';
import type { ImageRecord, PipelineConfig } from '../types';
import ImageCard from './ImageCard';

interface Props {
  images: ImageRecord[];
  names: Map<string, string>;
  config: PipelineConfig | null;
  onReorder: (orderedIds: string[]) => void;
  onRemove: (id: string) => void;
  onRerun: (id: string) => void;
}

export default function ResultGrid({ images, names, onReorder, onRemove, onRerun }: Props) {
  const [draggingId, setDraggingId] = useState<string | null>(null);
  const dragId = useRef<string | null>(null);

  // The first non-failed image is the hero (position 1 at export).
  const heroId = images.find((im) => im.status !== 'failed')?.id;

  const reorder = (targetId: string) => {
    const from = dragId.current;
    if (!from || from === targetId) return;
    const ids = images.map((im) => im.id);
    const fromIdx = ids.indexOf(from);
    const toIdx = ids.indexOf(targetId);
    if (fromIdx < 0 || toIdx < 0) return;
    ids.splice(toIdx, 0, ids.splice(fromIdx, 1)[0]);
    onReorder(ids);
  };

  if (images.length === 0) {
    return (
      <div className="rounded-xl border border-dashed border-neutral-200 p-10 text-center text-sm text-neutral-400">
        No images yet — drop some supplier photos above.
      </div>
    );
  }

  return (
    <div className="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-4">
      {images.map((im, i) => (
        <ImageCard
          key={im.id}
          image={im}
          position={i + 1}
          filename={names.get(im.id)}
          isHero={im.id === heroId}
          onRemove={onRemove}
          onRerun={onRerun}
          isDragging={draggingId === im.id}
          onDragStart={(id) => {
            dragId.current = id;
            setDraggingId(id);
          }}
          onDragEnter={(id) => reorder(id)}
          onDragEnd={() => {
            dragId.current = null;
            setDraggingId(null);
          }}
        />
      ))}
    </div>
  );
}
