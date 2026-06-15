import sharp from 'sharp';
import convert from 'heic-convert';
import { config } from '../config';

export interface NormalizeResult {
  /** Normalized full-size JPEG: oriented, EXIF-stripped, sRGB, flattened on white. */
  buffer: Buffer;
  width: number;
  height: number;
  format: string; // original detected format
  warnings: string[];
}

function isHeic(name: string, buf: Buffer): boolean {
  if (/\.(heic|heif)$/i.test(name)) return true;
  if (buf.length > 12) {
    const brand = buf.toString('ascii', 8, 12);
    if (['heic', 'heix', 'mif1', 'msf1', 'heif', 'hevc'].includes(brand)) return true;
  }
  return false;
}

/**
 * Ingest normalization. Rejects non-images by throwing.
 * Steps: HEIC->JPEG, auto-orient via EXIF then strip EXIF, CMYK->sRGB,
 * flatten transparency onto white. Adds soft warnings (low-res / chart-looking).
 */
export async function normalizeImage(input: Buffer, originalName: string): Promise<NormalizeResult> {
  const warnings: string[] = [];
  let working = input;

  if (isHeic(originalName, input)) {
    try {
      const out = await convert({ buffer: input, format: 'JPEG', quality: 1 });
      working = Buffer.from(out);
    } catch {
      throw new Error('Failed to decode HEIC/HEIF image');
    }
  }

  let meta: sharp.Metadata;
  try {
    meta = await sharp(working).metadata();
  } catch {
    throw new Error('Not a readable image');
  }
  if (!meta.format) throw new Error('Unrecognized image format');
  const originalFormat = meta.format;

  // auto-orient (rotate by EXIF) — sharp drops metadata on output by default, so EXIF is stripped.
  // convert to sRGB (handles CMYK), then flatten any transparency onto white.
  const buffer = await sharp(working)
    .rotate()
    .toColourspace('srgb')
    .flatten({ background: config.backgroundColor })
    .jpeg({ quality: 95, mozjpeg: true })
    .toBuffer();

  const outMeta = await sharp(buffer).metadata();
  const width = outMeta.width ?? 0;
  const height = outMeta.height ?? 0;

  const longSide = Math.max(width, height);
  if (longSide < config.minSourceLongSide) {
    warnings.push(`low-resolution (${width}×${height}, long side ${longSide}px < ${config.minSourceLongSide}px)`);
  }

  // Soft chart/infographic heuristic — flagged for the Review queue, never blocks.
  try {
    const stats = await sharp(buffer).stats();
    const entropy = stats.entropy ?? 8;
    const aspect = width && height ? width / height : 1;
    if (entropy < 2.8) warnings.push('looks-like-a-chart (low visual entropy)');
    if (aspect > 2.0 || aspect < 0.5) warnings.push('unusual aspect ratio (possible size chart)');
  } catch {
    /* stats are best-effort */
  }

  return { buffer, width, height, format: originalFormat, warnings };
}
