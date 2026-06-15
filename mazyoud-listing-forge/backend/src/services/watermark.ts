import sharp from 'sharp';
import type { Rgb } from './subject';

export interface Box {
  left: number;
  top: number;
  width: number;
  height: number;
}

const SAMPLE_W = 320;
const DIFF_MIN = 48; // distance from global background to count as a mark
const SAT_MIN = 55; // OR strongly saturated (colored badge)
const MIN_FRAC = 0.0015; // ignore marks smaller than this fraction of the frame
const MAX_FRAC = 0.1; // bigger than this is probably the product, not a watermark
const MIN_FILL = 0.32; // masked pixels / bbox area — compact blobs only (avoids texture)

/**
 * Conservative watermark/logo/badge detector focused on the corner zones where
 * supplier badges live (e.g. a red "极速发货" block). Best-effort: it favors
 * precision over recall to avoid spending AI calls / touching real product. The
 * manual box is the reliable fallback for anything it misses.
 */
export async function detectWatermarks(input: Buffer): Promise<{ boxes: Box[] }> {
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

  // global background from the border
  const border: Rgb[] = [];
  for (let x = 0; x < w; x++) border.push(px(x, 0), px(x, h - 1));
  for (let y = 0; y < h; y++) border.push(px(0, y), px(w - 1, y));
  const bg = median(border);

  // corner ROIs (top-left, top-right, bottom-left, bottom-right)
  const rois = [
    { x0: 0, y0: 0, x1: Math.floor(w * 0.45), y1: Math.floor(h * 0.3) },
    { x0: Math.floor(w * 0.55), y0: 0, x1: w, y1: Math.floor(h * 0.3) },
    { x0: 0, y0: Math.floor(h * 0.7), x1: Math.floor(w * 0.45), y1: h },
    { x0: Math.floor(w * 0.55), y0: Math.floor(h * 0.7), x1: w, y1: h },
  ];

  const boxes: Box[] = [];
  const minPx = w * h * MIN_FRAC;
  const maxPx = w * h * MAX_FRAC;

  for (const roi of rois) {
    let count = 0;
    let minx = w;
    let miny = h;
    let maxx = 0;
    let maxy = 0;
    for (let y = roi.y0; y < roi.y1; y++) {
      for (let x = roi.x0; x < roi.x1; x++) {
        const p = px(x, y);
        const diff = Math.max(Math.abs(p.r - bg.r), Math.abs(p.g - bg.g), Math.abs(p.b - bg.b));
        const sat = Math.max(p.r, p.g, p.b) - Math.min(p.r, p.g, p.b);
        if (diff > DIFF_MIN || sat > SAT_MIN) {
          count += 1;
          if (x < minx) minx = x;
          if (x > maxx) maxx = x;
          if (y < miny) miny = y;
          if (y > maxy) maxy = y;
        }
      }
    }
    if (count < minPx || count > maxPx) continue;
    const bboxArea = (maxx - minx + 1) * (maxy - miny + 1);
    if (bboxArea <= 0 || count / bboxArea < MIN_FILL) continue; // not compact → skip

    const sx = fullW / w;
    const sy = fullH / h;
    const padX = (maxx - minx + 1) * 0.12;
    const padY = (maxy - miny + 1) * 0.12;
    const left = Math.max(0, Math.round((minx - padX) * sx));
    const top = Math.max(0, Math.round((miny - padY) * sy));
    const width = Math.min(fullW - left, Math.round((maxx - minx + 1 + 2 * padX) * sx));
    const height = Math.min(fullH - top, Math.round((maxy - miny + 1 + 2 * padY) * sy));
    boxes.push({ left, top, width, height });
  }

  return { boxes };
}

function median(px: Rgb[]): Rgb {
  const ch = (sel: (p: Rgb) => number) => {
    const a = px.map(sel).sort((x, y) => x - y);
    return a[Math.floor(a.length / 2)];
  };
  return { r: ch((p) => p.r), g: ch((p) => p.g), b: ch((p) => p.b) };
}
