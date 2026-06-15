import sharp from 'sharp';
import { config } from '../config';

export interface FrameOptions {
  outputWidth?: number;
  outputHeight?: number;
  negativeSpaceRatio?: number;
  backgroundColor?: string;
  jpegMaxKB?: number;
  jpegQualityFloor?: number;
  jpegQualityStart?: number;
}

export interface FrameResult {
  buffer: Buffer;
  width: number;
  height: number;
  bytes: number;
  quality: number;
  flags: string[];
}

/**
 * Phase 1 deterministic framing: fit the product (no distortion) into the
 * negative-space inner box, center it on a white 6:7 canvas, then JPEG-encode
 * to the target size with a quality floor. No AI. No cropping. No stretching.
 *
 * Phase 4 will replace "treat the whole image as the product" with a real
 * subject mask + solid-fill vs generative-outpaint background logic.
 */
export async function frameImage(input: Buffer, opts: FrameOptions = {}): Promise<FrameResult> {
  const outW = opts.outputWidth ?? config.outputWidth;
  const outH = opts.outputHeight ?? config.outputHeight;
  const nsr = opts.negativeSpaceRatio ?? config.negativeSpaceRatio;
  const bg = opts.backgroundColor ?? config.backgroundColor;
  const flags: string[] = [];

  const meta = await sharp(input).metadata();
  const sw = meta.width ?? outW;
  const sh = meta.height ?? outH;

  // Scale so the product fits within the negative-space box, aspect preserved.
  const innerW = Math.round(outW * nsr);
  const innerH = Math.round(outH * nsr);
  const scale = Math.min(innerW / sw, innerH / sh);
  const targetW = Math.max(1, Math.round(sw * scale));
  const targetH = Math.max(1, Math.round(sh * scale));
  if (scale > 1.5) flags.push('upscaled-source'); // soft fabric will be sharpened in phase 3

  const resized = await sharp(input).resize(targetW, targetH, { fit: 'fill' }).toBuffer();

  const left = Math.round((outW - targetW) / 2);
  const top = Math.round((outH - targetH) / 2);

  // Build the canvas once as raw RGB, then re-encode JPEG at descending quality.
  const { data, info } = await sharp({
    create: { width: outW, height: outH, channels: 3, background: bg },
  })
    .composite([{ input: resized, left, top }])
    .raw()
    .toBuffer({ resolveWithObject: true });

  const encode = (quality: number) =>
    sharp(data, { raw: { width: info.width, height: info.height, channels: info.channels } })
      .jpeg({ quality, mozjpeg: true, progressive: true })
      .toBuffer();

  const maxBytes = (opts.jpegMaxKB ?? config.jpegMaxKB) * 1024;
  const floor = opts.jpegQualityFloor ?? config.jpegQualityFloor;
  let q = opts.jpegQualityStart ?? config.jpegQualityStart;
  let out = await encode(q);
  while (out.length > maxBytes && q > floor) {
    q = Math.max(floor, q - 4);
    out = await encode(q);
  }
  if (out.length > maxBytes) {
    flags.push(`over-size (${Math.round(out.length / 1024)}KB at quality floor ${floor})`);
  }

  return { buffer: out, width: outW, height: outH, bytes: out.length, quality: q, flags };
}
