import { Router } from 'express';
import archiver from 'archiver';
import { readFile, writeFile, mkdir, access } from 'node:fs/promises';
import { constants } from 'node:fs';
import path from 'node:path';
import { store, processedPath, DEFAULT_OUTPUT_DIR } from '../services/store';
import { slugifySku, assignNames } from '../services/naming';
import { toCsv, type ManifestRow } from '../services/manifest';
import { config } from '../config';
import type { ImageRecord } from '../types';

const router = Router();

type ConflictPolicy = 'overwrite' | 'skip' | 'version';

/** Images that produced a usable output, in the requested order. Failed ones drop out. */
function successfulOrdered(order: string[]): ImageRecord[] {
  return (order ?? [])
    .map((id) => store.get(id))
    .filter((r): r is ImageRecord => !!r && r.status !== 'failed' && !!r.processedUrl);
}

function resolveOutputDir(d?: string): string {
  return d && d.trim() ? path.resolve(d) : DEFAULT_OUTPUT_DIR;
}

async function fileExists(p: string): Promise<boolean> {
  try {
    await access(p, constants.F_OK);
    return true;
  } catch {
    return false;
  }
}

async function nextVersioned(dir: string, filename: string): Promise<string> {
  const ext = path.extname(filename);
  const stem = filename.slice(0, -ext.length);
  let n = 2;
  let cand = `${stem}${config.filenameSeparator}v${n}${ext}`;
  while (await fileExists(path.join(dir, cand))) {
    n += 1;
    cand = `${stem}${config.filenameSeparator}v${n}${ext}`;
  }
  return cand;
}

function rowFor(slug: string, filename: string, order: number, rec: ImageRecord, statusOverride?: string): ManifestRow {
  return {
    sku: slug,
    filename,
    order: order < 0 ? '' : order, // 0 = hero
    source: rec.originalName,
    status: statusOverride ?? rec.status,
    ai_used: rec.aiUsed,
    flags: [...rec.warnings, ...rec.flags].join('; '),
  };
}

/** Rows for everything that did NOT get exported (failed / excluded), for traceability. */
function nonExportedRows(slug: string, order: string[], succ: ImageRecord[]): ManifestRow[] {
  const succIds = new Set(succ.map((s) => s.id));
  return (order ?? [])
    .map((id) => store.get(id))
    .filter((r): r is ImageRecord => !!r && !succIds.has(r.id))
    .map((r) => rowFor(slug, '', -1, r));
}

// Which target filenames already exist? Powers the overwrite/skip/version prompt.
router.post('/check', async (req, res) => {
  const { sku, order, outputDir } = req.body as { sku: string; order: string[]; outputDir?: string };
  const dir = resolveOutputDir(outputDir);
  const succ = successfulOrdered(order);
  const names = assignNames(sku, succ.length);
  const conflicts: string[] = [];
  for (const n of names) {
    if (await fileExists(path.join(dir, n))) conflicts.push(n);
  }
  res.json({ outputDir: dir, conflicts });
});

// Stream {SKU}_listing.zip (named JPEGs + manifest CSV).
router.post('/zip', async (req, res) => {
  const { sku, order } = req.body as { sku: string; order: string[] };
  if (!sku?.trim()) {
    res.status(400).json({ error: 'SKU required' });
    return;
  }
  const succ = successfulOrdered(order);
  if (!succ.length) {
    res.status(400).json({ error: 'No successful images to export' });
    return;
  }
  const { slug } = slugifySku(sku);
  const names = assignNames(sku, succ.length);

  res.setHeader('Content-Type', 'application/zip');
  res.setHeader('Content-Disposition', `attachment; filename="${slug}_listing.zip"`);
  const archive = archiver('zip', { zlib: { level: 9 } });
  archive.on('error', (err) => res.status(500).end(String(err)));
  archive.pipe(res);

  const rows: ManifestRow[] = [];
  for (let i = 0; i < succ.length; i++) {
    const buf = await readFile(processedPath(succ[i].id));
    archive.append(buf, { name: names[i] });
    rows.push(rowFor(slug, names[i], i, succ[i]));
  }
  rows.push(...nonExportedRows(slug, order, succ));
  archive.append(toCsv(rows), { name: `${slug}_manifest.csv` });
  await archive.finalize();
});

// Write named JPEGs + manifest into a local folder, honoring the conflict policy.
router.post('/folder', async (req, res) => {
  const { sku, order, outputDir, onConflict } = req.body as {
    sku: string;
    order: string[];
    outputDir?: string;
    onConflict?: ConflictPolicy;
  };
  if (!sku?.trim()) {
    res.status(400).json({ error: 'SKU required' });
    return;
  }
  const dir = resolveOutputDir(outputDir);
  await mkdir(dir, { recursive: true });

  const succ = successfulOrdered(order);
  if (!succ.length) {
    res.status(400).json({ error: 'No successful images to export' });
    return;
  }
  const { slug } = slugifySku(sku);
  const names = assignNames(sku, succ.length);
  const policy: ConflictPolicy = onConflict ?? 'version';

  const written: string[] = [];
  const skipped: string[] = [];
  const versioned: string[] = [];
  const rows: ManifestRow[] = [];

  for (let i = 0; i < succ.length; i++) {
    let filename = names[i];
    if (await fileExists(path.join(dir, filename))) {
      if (policy === 'skip') {
        skipped.push(filename);
        rows.push(rowFor(slug, filename, i, succ[i], 'skipped'));
        continue;
      }
      if (policy === 'version') {
        filename = await nextVersioned(dir, filename);
        versioned.push(filename);
      }
      // 'overwrite' falls through and reuses the name
    }
    await writeFile(path.join(dir, filename), await readFile(processedPath(succ[i].id)));
    written.push(filename);
    rows.push(rowFor(slug, filename, i, succ[i], policy === 'version' && versioned.includes(filename) ? 'versioned' : 'written'));
  }

  rows.push(...nonExportedRows(slug, order, succ));
  const manifest = `${slug}_manifest.csv`;
  await writeFile(path.join(dir, manifest), toCsv(rows));

  res.json({ outputDir: dir, written, skipped, versioned, manifest });
});

export default router;
