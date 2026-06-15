import { mkdirSync, existsSync, rmSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import type { ImageRecord } from '../types';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// backend/src/services/store.ts -> project root is three levels up
export const PROJECT_ROOT = path.resolve(__dirname, '../../..');
export const DATA_DIR = path.resolve(__dirname, '../../.data');
export const DEFAULT_OUTPUT_DIR =
  process.env.OUTPUT_DIR && process.env.OUTPUT_DIR.trim()
    ? path.resolve(process.env.OUTPUT_DIR)
    : path.join(PROJECT_ROOT, 'output');

export const DIRS = {
  normalized: path.join(DATA_DIR, 'normalized'),
  preview: path.join(DATA_DIR, 'preview'),
  processed: path.join(DATA_DIR, 'processed'),
};

export function ensureDirs(): void {
  for (const d of [DATA_DIR, DIRS.normalized, DIRS.preview, DIRS.processed]) {
    if (!existsSync(d)) mkdirSync(d, { recursive: true });
  }
}

export function normalizedPath(id: string): string {
  return path.join(DIRS.normalized, `${id}.jpg`);
}
export function previewPath(id: string): string {
  return path.join(DIRS.preview, `${id}.jpg`);
}
export function processedPath(id: string): string {
  return path.join(DIRS.processed, `${id}.jpg`);
}

const images = new Map<string, ImageRecord>();

export const store = {
  put(rec: ImageRecord): ImageRecord {
    images.set(rec.id, rec);
    return rec;
  },
  get(id: string): ImageRecord | undefined {
    return images.get(id);
  },
  all(): ImageRecord[] {
    return [...images.values()];
  },
  delete(id: string): boolean {
    return images.delete(id);
  },
  clear(): void {
    images.clear();
  },
};

/** Wipe working files + in-memory state. Used for "New batch". */
export function clearData(): void {
  for (const d of [DIRS.normalized, DIRS.preview, DIRS.processed]) {
    if (existsSync(d)) rmSync(d, { recursive: true, force: true });
  }
  ensureDirs();
  store.clear();
}
