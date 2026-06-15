import { useRef, useState } from 'react';

interface Props {
  onFiles: (files: File[]) => void;
  busy?: boolean;
  disabled?: boolean;
}

const IMAGE_RE = /\.(jpe?g|png|webp|gif|tiff?|bmp|avif|heic|heif)$/i;
function filterImages(files: File[]): File[] {
  return files.filter((f) => f.type.startsWith('image/') || IMAGE_RE.test(f.name));
}

export default function DropZone({ onFiles, busy, disabled }: Props) {
  const [drag, setDrag] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);
  const dirInput = useRef<HTMLInputElement>(null);

  const handle = (list: FileList | null) => {
    const files = filterImages(Array.from(list ?? []));
    if (files.length) onFiles(files);
  };

  return (
    <div
      onDragOver={(e) => {
        e.preventDefault();
        if (!disabled) setDrag(true);
      }}
      onDragLeave={() => setDrag(false)}
      onDrop={(e) => {
        e.preventDefault();
        setDrag(false);
        if (!disabled) handle(e.dataTransfer.files);
      }}
      className={`rounded-xl border-2 border-dashed p-8 text-center transition-colors ${
        drag ? 'border-coral bg-coral/5' : 'border-neutral-300 bg-white'
      } ${disabled ? 'pointer-events-none opacity-50' : ''}`}
    >
      <p className="text-sm font-semibold">Drop supplier photos here</p>
      <p className="mt-1 text-xs text-neutral-500">
        JPEG · PNG · WEBP · TIFF · HEIC — HEIC→JPEG, auto-orient, sRGB & white-flatten on ingest
      </p>
      <div className="mt-4 flex items-center justify-center gap-2">
        <button className="btn-ghost" disabled={busy} onClick={() => fileInput.current?.click()}>
          {busy ? 'Working…' : 'Choose files'}
        </button>
        <button className="btn-ghost" disabled={busy} onClick={() => dirInput.current?.click()}>
          Choose folder
        </button>
      </div>
      <input
        ref={fileInput}
        type="file"
        multiple
        accept="image/*,.heic,.heif"
        hidden
        onChange={(e) => {
          handle(e.target.files);
          e.target.value = '';
        }}
      />
      <input
        ref={dirInput}
        type="file"
        /* @ts-expect-error non-standard folder-pick attribute, widely supported */
        webkitdirectory=""
        multiple
        hidden
        onChange={(e) => {
          handle(e.target.files);
          e.target.value = '';
        }}
      />
    </div>
  );
}
