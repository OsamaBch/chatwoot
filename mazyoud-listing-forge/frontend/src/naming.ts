import type { ImageRecord, PipelineConfig } from './types';

// Mirror of backend/src/services/naming.ts so the grid can show live filenames
// while reordering. MUST stay in sync with the backend (export is the source of truth).
export function slugifySku(raw: string, separator = '-'): { slug: string; changed: boolean } {
  const trimmed = raw.trim();
  const slug = trimmed
    .replace(/\s+/g, separator)
    .replace(/[^a-zA-Z0-9._-]/g, '')
    .replace(new RegExp(`[${separator.replace(/[-\\\]^]/g, '\\$&')}]{2,}`, 'g'), separator)
    .replace(/^[-_.]+|[-_.]+$/g, '');
  return { slug, changed: slug !== trimmed };
}

/**
 * Preview filenames for the current order. Numbering is contiguous over images
 * that would export successfully (anything not `failed`), hero first — exactly
 * what the backend assigns at export time.
 */
export function previewNames(images: ImageRecord[], sku: string, cfg: PipelineConfig | null): Map<string, string> {
  const sep = cfg?.filenameSeparator ?? '-';
  const ext = cfg?.fileExtension ?? '.jpg';
  const { slug } = slugifySku(sku, sep);
  const map = new Map<string, string>();
  if (!slug) return map;
  let n = 0;
  for (const im of images) {
    if (im.status === 'failed') continue;
    map.set(im.id, n === 0 ? `${slug}${ext}` : `${slug}${sep}${n}${ext}`);
    n += 1;
  }
  return map;
}
