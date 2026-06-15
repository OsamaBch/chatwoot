import sharp from 'sharp';

export interface Rgb {
  r: number;
  g: number;
  b: number;
}

export interface SubjectInfo {
  /** Was a clear product region found? */
  found: boolean;
  /** Product bounding box in FULL-resolution source coordinates. */
  bbox: { left: number; top: number; width: number; height: number };
  /** Sampled background colour (for seamless solid-fill extension). */
  bg: Rgb;
  /** Is the background near-uniform (so solid-fill extension is seamless)? */
  uniform: boolean;
}

const SAMPLE_W = 220; // analyse a small downscale for speed
const FG_THRESHOLD = 30; // per-channel distance from bg to count as foreground
const MIN_COMPONENT_FRAC = 0.005; // ignore foreground blobs smaller than 0.5% of the frame (specks)
const UNIFORM_MAX_DEV = 18; // mean abs deviation of border pixels below this = uniform

/**
 * Deterministic subject measurement for near-uniform studio backgrounds (no AI,
 * no model download). Samples the border for the background colour, marks pixels
 * that differ from it as foreground, and returns a percentile-trimmed bounding
 * box of the product. Used to scale the PRODUCT (not the whole photo) to the
 * negative-space ratio and to pick a seamless fill colour.
 */
export async function detectSubject(input: Buffer): Promise<SubjectInfo> {
  const meta = await sharp(input).metadata();
  const fullW = meta.width ?? 0;
  const fullH = meta.height ?? 0;

  const { data, info } = await sharp(input).resize({ width: SAMPLE_W, fit: 'inside' }).removeAlpha().raw().toBuffer({ resolveWithObject: true });
  const w = info.width;
  const h = info.height;
  const ch = info.channels;
  const px = (x: number, y: number): Rgb => {
    const i = (y * w + x) * ch;
    return { r: data[i], g: data[i + 1], b: data[i + 2] };
  };

  // border sample → background colour + uniformity
  const border: Rgb[] = [];
  for (let x = 0; x < w; x++) {
    border.push(px(x, 0), px(x, h - 1));
  }
  for (let y = 0; y < h; y++) {
    border.push(px(0, y), px(w - 1, y));
  }
  const bg = median(border);
  const uniform = meanAbsDev(border, bg) < UNIFORM_MAX_DEV;

  // foreground mask
  const mask = new Uint8Array(w * h);
  let fg = 0;
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      const p = px(x, y);
      const dist = Math.max(Math.abs(p.r - bg.r), Math.abs(p.g - bg.g), Math.abs(p.b - bg.b));
      if (dist > FG_THRESHOLD) {
        mask[y * w + x] = 1;
        fg += 1;
      }
    }
  }
  if (fg < w * h * 0.01) {
    // too little contrast (e.g. white garment on white) → detection failed
    return { found: false, bbox: { left: 0, top: 0, width: fullW, height: fullH }, bg, uniform };
  }

  // Union the bounding boxes of all sizeable connected components. This keeps
  // multiple real products (dress + hat + bag) but drops tiny specks — and is
  // robust to small stray marks. (Watermarks are removed before framing once
  // phase 3 is in the pipeline.)
  const minSize = Math.max(20, w * h * MIN_COMPONENT_FRAC);
  const box = unionLargeComponents(mask, w, h, minSize);
  if (!box) return { found: false, bbox: { left: 0, top: 0, width: fullW, height: fullH }, bg, uniform };

  const sx = fullW / w;
  const sy = fullH / h;
  const left = Math.max(0, Math.round(box.minx * sx));
  const top = Math.max(0, Math.round(box.miny * sy));
  const width = Math.min(fullW - left, Math.round((box.maxx - box.minx + 1) * sx));
  const height = Math.min(fullH - top, Math.round((box.maxy - box.miny + 1) * sy));

  return { found: width > 0 && height > 0, bbox: { left, top, width, height }, bg, uniform };
}

interface Box {
  minx: number;
  miny: number;
  maxx: number;
  maxy: number;
}

/** BFS connected components (4-connectivity); union bboxes of components ≥ minSize. */
function unionLargeComponents(mask: Uint8Array, w: number, h: number, minSize: number): Box | null {
  const visited = new Uint8Array(w * h);
  const stack: number[] = [];
  let union: Box | null = null;
  for (let start = 0; start < w * h; start++) {
    if (!mask[start] || visited[start]) continue;
    let size = 0;
    let minx = w;
    let miny = h;
    let maxx = 0;
    let maxy = 0;
    stack.length = 0;
    stack.push(start);
    visited[start] = 1;
    while (stack.length) {
      const idx = stack.pop() as number;
      const x = idx % w;
      const y = (idx / w) | 0;
      size += 1;
      if (x < minx) minx = x;
      if (x > maxx) maxx = x;
      if (y < miny) miny = y;
      if (y > maxy) maxy = y;
      if (x > 0 && mask[idx - 1] && !visited[idx - 1]) (visited[idx - 1] = 1), stack.push(idx - 1);
      if (x < w - 1 && mask[idx + 1] && !visited[idx + 1]) (visited[idx + 1] = 1), stack.push(idx + 1);
      if (y > 0 && mask[idx - w] && !visited[idx - w]) (visited[idx - w] = 1), stack.push(idx - w);
      if (y < h - 1 && mask[idx + w] && !visited[idx + w]) (visited[idx + w] = 1), stack.push(idx + w);
    }
    if (size < minSize) continue;
    union = union
      ? { minx: Math.min(union.minx, minx), miny: Math.min(union.miny, miny), maxx: Math.max(union.maxx, maxx), maxy: Math.max(union.maxy, maxy) }
      : { minx, miny, maxx, maxy };
  }
  return union;
}

function median(px: Rgb[]): Rgb {
  const ch = (sel: (p: Rgb) => number) => {
    const arr = px.map(sel).sort((a, b) => a - b);
    return arr[Math.floor(arr.length / 2)];
  };
  return { r: ch((p) => p.r), g: ch((p) => p.g), b: ch((p) => p.b) };
}

function meanAbsDev(px: Rgb[], bg: Rgb): number {
  let s = 0;
  for (const p of px) s += (Math.abs(p.r - bg.r) + Math.abs(p.g - bg.g) + Math.abs(p.b - bg.b)) / 3;
  return s / px.length;
}
