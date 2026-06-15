import { config } from '../config';

export interface SlugResult {
  slug: string;
  changed: boolean;
}

/**
 * Slugify a SKU into a filename-safe stem. Preserves case (e.g. ROBE-2025-014),
 * turns whitespace into the configured separator, strips illegal characters, and
 * collapses/ trims separators. Reports whether anything changed (for a UI warning).
 */
export function slugifySku(raw: string): SlugResult {
  const sep = config.filenameSeparator;
  const trimmed = raw.trim();
  const slug = trimmed
    .replace(/\s+/g, sep) // spaces -> separator
    .replace(/[^a-zA-Z0-9._-]/g, '') // strip anything not filename-safe
    .replace(new RegExp(`[${escapeForClass(sep)}]{2,}`, 'g'), sep) // collapse repeats
    .replace(/^[-_.]+|[-_.]+$/g, ''); // trim edge punctuation
  return { slug, changed: slug !== trimmed };
}

function escapeForClass(s: string): string {
  return s.replace(/[-\\\]^]/g, '\\$&');
}

/**
 * Assign contiguous listing filenames for `count` successful images, in order.
 * Hero (index 0) = {slug}.jpg, then {slug}-1.jpg, {slug}-2.jpg, …
 * Callers pass ONLY successful images so numbering never has gaps.
 */
export function assignNames(sku: string, count: number): string[] {
  const { slug } = slugifySku(sku);
  const ext = config.fileExtension;
  const sep = config.filenameSeparator;
  const names: string[] = [];
  for (let i = 0; i < count; i++) {
    names.push(i === 0 ? `${slug}${ext}` : `${slug}${sep}${i}${ext}`);
  }
  return names;
}
