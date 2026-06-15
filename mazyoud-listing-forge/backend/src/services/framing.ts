import sharp from 'sharp';
import { config } from '../config';
import { detectSubject, type Rgb } from './subject';

export interface FrameResult {
  buffer: Buffer;
  width: number;
  height: number;
  bytes: number;
  quality: number;
  flags: string[];
  aiUsed: boolean;
}

/** Fills the transparent margins of an RGBA canvas (generative outpaint). */
export type OutpaintFn = (rgbaCanvas: Buffer, canvas: { width: number; height: number }) => Promise<Buffer>;

export interface FrameOpts {
  outpaint?: OutpaintFn;
}

/**
 * Subject-aware framing: measure the PRODUCT, scale it to the negative-space
 * ratio, center it on a 6:7 canvas, and fill any border needed to reach 6:7.
 *
 * Border fill:
 *  - opts.outpaint provided → generative outpaint of the BACKGROUND ONLY: the
 *    product is placed on a transparent canvas, the provider fills the margins to
 *    match the background, then the product is pasted back so it's pixel-identical
 *    (only the border is AI-generated). No duplication.
 *  - otherwise → solid background-colour fill (deterministic, no AI).
 * Many photos overfill 6:7 and just crop background (no border, no outpaint cost).
 * The garment is never cropped, stretched, or altered. No clear subject (e.g.
 * white-on-white) → whole-image framing flagged 'subject-not-detected'.
 */
export async function frameImage(input: Buffer, opts: FrameOpts = {}): Promise<FrameResult> {
  const outW = config.outputWidth;
  const outH = config.outputHeight;
  const nsr = config.negativeSpaceRatio;
  const flags: string[] = [];
  let aiUsed = false;

  const meta = await sharp(input).metadata();
  const sw = meta.width ?? outW;
  const sh = meta.height ?? outH;

  const subj = await detectSubject(input);
  let bx: number;
  let by: number;
  let bw: number;
  let bh: number;
  if (subj.found) {
    ({ left: bx, top: by, width: bw, height: bh } = subj.bbox);
    if (!subj.uniform) flags.push('non-uniform-bg');
  } else {
    bx = 0;
    by = 0;
    bw = sw;
    bh = sh;
    flags.push('subject-not-detected');
  }

  const fill: Rgb = config.extendBackground && subj.found ? subj.bg : hexToRgb(config.backgroundColor);

  // Scale so the PRODUCT's longest side hits the negative-space ratio.
  const scale = Math.min((nsr * outW) / bw, (nsr * outH) / bh);
  const scaledW = Math.max(1, Math.round(sw * scale));
  const scaledH = Math.max(1, Math.round(sh * scale));
  if (scale > 1.5) flags.push('upscaled-source');

  // Center the product's bbox on the canvas (offsets may be negative if the
  // photo's background extends past the canvas — that background is croppable).
  const left = Math.round(outW / 2 - (bx + bw / 2) * scale);
  const top = Math.round(outH / 2 - (by + bh / 2) * scale);

  const scaled = await sharp(input).resize(scaledW, scaledH, { fit: 'fill' }).toBuffer();

  const srcLeft = Math.max(0, -left);
  const srcTop = Math.max(0, -top);
  const pasteLeft = Math.max(0, left);
  const pasteTop = Math.max(0, top);
  const visW = Math.min(scaledW - srcLeft, outW - pasteLeft);
  const visH = Math.min(scaledH - srcTop, outH - pasteTop);

  const padL = pasteLeft;
  const padT = pasteTop;
  const padR = outW - pasteLeft - visW;
  const padB = outH - pasteTop - visH;
  const needsExtend = padL > 1 || padT > 1 || padR > 1 || padB > 1;

  const solidFill = (piece: Buffer): sharp.Sharp =>
    sharp({ create: { width: outW, height: outH, channels: 3, background: fill } }).composite([
      { input: piece, left: pasteLeft, top: pasteTop },
    ]);

  let pipeline: sharp.Sharp;
  if (visW > 0 && visH > 0) {
    const piece = await sharp(scaled).extract({ left: srcLeft, top: srcTop, width: visW, height: visH }).toBuffer();

    if (opts.outpaint && config.extendBackground && subj.found && needsExtend) {
      // Generative outpaint of the BACKGROUND only. We place the product on a
      // transparent canvas, let the provider fill the transparent margins to
      // match the background, then paste the product back so the garment is
      // pixel-identical (only the border is AI-generated).
      try {
        const base = await sharp({ create: { width: outW, height: outH, channels: 4, background: { r: 0, g: 0, b: 0, alpha: 0 } } })
          .composite([{ input: piece, left: pasteLeft, top: pasteTop }])
          .png()
          .toBuffer();
        let filled = await opts.outpaint(base, { width: outW, height: outH });
        const fm = await sharp(filled).metadata();
        if (fm.width !== outW || fm.height !== outH) {
          filled = await sharp(filled).resize(outW, outH, { fit: 'fill', kernel: 'lanczos3' }).toBuffer();
        }
        // paste the product back over the AI border for fidelity
        pipeline = sharp(filled).removeAlpha().composite([{ input: piece, left: pasteLeft, top: pasteTop }]);
        aiUsed = true;
      } catch {
        flags.push('outpaint-failed');
        pipeline = solidFill(piece);
      }
    } else {
      pipeline = solidFill(piece);
    }
  } else {
    pipeline = sharp({ create: { width: outW, height: outH, channels: 3, background: fill } });
  }

  const { data, info } = await pipeline.raw().toBuffer({ resolveWithObject: true });
  const encode = (quality: number) =>
    sharp(data, { raw: { width: info.width, height: info.height, channels: info.channels } })
      .jpeg({ quality, mozjpeg: true, progressive: true })
      .toBuffer();

  const maxBytes = config.jpegMaxKB * 1024;
  const floor = config.jpegQualityFloor;
  let q = config.jpegQualityStart;
  let out = await encode(q);
  while (out.length > maxBytes && q > floor) {
    q = Math.max(floor, q - 4);
    out = await encode(q);
  }
  if (out.length > maxBytes) flags.push(`over-size (${Math.round(out.length / 1024)}KB at quality floor ${floor})`);

  return { buffer: out, width: outW, height: outH, bytes: out.length, quality: q, flags, aiUsed };
}

function hexToRgb(hex: string): Rgb {
  const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex.trim());
  if (!m) return { r: 255, g: 255, b: 255 };
  return { r: parseInt(m[1], 16), g: parseInt(m[2], 16), b: parseInt(m[3], 16) };
}
