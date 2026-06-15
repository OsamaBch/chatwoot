import { Router } from 'express';
import { readFile, writeFile } from 'node:fs/promises';
import { frameImage } from '../services/framing';
import { store, normalizedPath, processedPath } from '../services/store';
import { config } from '../config';

const router = Router();

/**
 * Frame a set of images (phase 1 = deterministic 6:7 white-pad). Body:
 *   { sku: string, order?: string[] }
 * `order` lets the frontend reprocess a single image (order:[id]) for "re-run".
 * Runs with bounded concurrency. Filenames are NOT assigned here — that happens
 * at export time from the final order over successful images only.
 */
router.post('/', async (req, res) => {
  const { sku, order } = req.body as { sku?: string; order?: string[] };
  if (!sku || !sku.trim()) {
    res.status(400).json({ error: 'SKU is required before generating' });
    return;
  }
  const ids = Array.isArray(order) && order.length ? order : store.all().map((r) => r.id);
  if (ids.length === 0) {
    res.status(400).json({ error: 'No images to process' });
    return;
  }

  const queue = [...ids];
  const worker = async () => {
    for (let id = queue.shift(); id !== undefined; id = queue.shift()) {
      const rec = store.get(id);
      if (!rec) continue;
      try {
        rec.status = 'framing';
        const input = await readFile(normalizedPath(id));
        const framed = await frameImage(input);
        await writeFile(processedPath(id), framed.buffer);
        rec.processedUrl = `/files/processed/${id}.jpg?ts=${Date.now()}`;
        rec.outputWidth = framed.width;
        rec.outputHeight = framed.height;
        rec.outputBytes = framed.bytes;
        rec.outputQuality = framed.quality;
        rec.flags = framed.flags;
        rec.aiUsed = false;
        rec.error = undefined;
        rec.needsReview = rec.warnings.length > 0 || framed.flags.length > 0;
        rec.status = rec.needsReview ? 'review' : 'done';
      } catch (e) {
        rec.status = 'failed';
        rec.error = e instanceof Error ? e.message : 'Framing failed';
      }
    }
  };

  const n = Math.min(config.concurrency, ids.length);
  await Promise.all(Array.from({ length: n }, () => worker()));

  res.json({ images: ids.map((id) => store.get(id)).filter(Boolean) });
});

export default router;
