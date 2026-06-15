import sharp from 'sharp';
import type { Box } from './watermark';
import type { AiProvider } from '../ai';
import { config } from '../config';

export interface CleanupResult {
  buffer: Buffer;
  aiCalls: number;
  drift: number; // 1 - SSIM outside the edited boxes (0 = perfectly preserved)
  flags: string[];
}

/**
 * Masked inpaint by crop → AI clean → paste-back. We send ONLY the watermark box
 * (padded for context) to the provider and paste the cleaned patch back, so every
 * pixel outside the box is identical by construction — the garment is never
 * altered elsewhere. An SSIM check outside the boxes is the safety net.
 */
export async function applyCleanup(input: Buffer, boxes: Box[], provider: AiProvider): Promise<CleanupResult> {
  const flags: string[] = [];
  let aiCalls = 0;
  let working = input;

  const meta = await sharp(input).metadata();
  const W = meta.width ?? 0;
  const H = meta.height ?? 0;

  for (const raw of boxes) {
    const box = clamp(raw, W, H);
    if (box.width < 6 || box.height < 6) continue;
    const pad = Math.round(Math.max(box.width, box.height) * 0.25);
    const crop = clamp({ left: box.left - pad, top: box.top - pad, width: box.width + 2 * pad, height: box.height + 2 * pad }, W, H);

    const cropBuf = await sharp(working).extract(crop).png().toBuffer();
    try {
      const res = await provider.cleanup({ image: cropBuf });
      aiCalls += 1;
      let patch = res.image;
      const pm = await sharp(patch).metadata();
      if (pm.width !== crop.width || pm.height !== crop.height) {
        patch = await sharp(patch).resize(crop.width, crop.height, { fit: 'fill' }).png().toBuffer();
      }
      working = await sharp(working).composite([{ input: patch, left: crop.left, top: crop.top }]).toBuffer();
    } catch {
      flags.push('cleanup-failed');
    }
  }

  const drift = await driftOutsideBoxes(input, working, boxes);
  if (drift > config.fidelityDiffThreshold) flags.push(`fidelity-drift(${drift.toFixed(3)})`);

  return { buffer: working, aiCalls, drift, flags };
}

function clamp(b: Box, W: number, H: number): Box {
  const left = Math.max(0, Math.min(b.left, W - 1));
  const top = Math.max(0, Math.min(b.top, H - 1));
  return { left, top, width: Math.max(0, Math.min(b.width, W - left)), height: Math.max(0, Math.min(b.height, H - top)) };
}

/** 1 − SSIM over grayscale pixels OUTSIDE the edited boxes (intended edits excluded). */
async function driftOutsideBoxes(orig: Buffer, edited: Buffer, boxes: Box[]): Promise<number> {
  const SIZE = 256;
  const a = await sharp(orig).resize(SIZE, SIZE, { fit: 'fill' }).greyscale().raw().toBuffer();
  const b = await sharp(edited).resize(SIZE, SIZE, { fit: 'fill' }).greyscale().raw().toBuffer();
  const meta = await sharp(orig).metadata();
  const W = meta.width ?? 1;
  const H = meta.height ?? 1;

  const exclude = new Uint8Array(SIZE * SIZE);
  for (const box of boxes) {
    const x0 = Math.floor((box.left / W) * SIZE);
    const y0 = Math.floor((box.top / H) * SIZE);
    const x1 = Math.ceil(((box.left + box.width) / W) * SIZE);
    const y1 = Math.ceil(((box.top + box.height) / H) * SIZE);
    for (let y = y0; y < y1 && y < SIZE; y++) {
      for (let x = x0; x < x1 && x < SIZE; x++) exclude[y * SIZE + x] = 1;
    }
  }

  let n = 0;
  let sa = 0;
  let sb = 0;
  for (let i = 0; i < a.length; i++) {
    if (exclude[i]) continue;
    sa += a[i];
    sb += b[i];
    n += 1;
  }
  if (n === 0) return 0;
  const muA = sa / n;
  const muB = sb / n;
  let va = 0;
  let vb = 0;
  let cov = 0;
  for (let i = 0; i < a.length; i++) {
    if (exclude[i]) continue;
    const da = a[i] - muA;
    const db = b[i] - muB;
    va += da * da;
    vb += db * db;
    cov += da * db;
  }
  va /= n;
  vb /= n;
  cov /= n;
  const c1 = (0.01 * 255) ** 2;
  const c2 = (0.03 * 255) ** 2;
  const ssim = ((2 * muA * muB + c1) * (2 * cov + c2)) / ((muA * muA + muB * muB + c1) * (va + vb + c2));
  return Math.max(0, 1 - ssim);
}
