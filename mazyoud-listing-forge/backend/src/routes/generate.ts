import { Router } from 'express';
import { readFile, writeFile } from 'node:fs/promises';
import { frameImage } from '../services/framing';
import { detectWatermarks, type Box } from '../services/watermark';
import { applyCleanup } from '../services/cleanup';
import { store, normalizedPath, processedPath } from '../services/store';
import { getSettings } from '../services/settings';
import { getProvider, costTally, type AiProvider } from '../ai';
import { config } from '../config';

const OUTPAINT_PROMPT =
  'Outpaint ONLY the background to fill the transparent margins, matching the existing background colour and texture exactly. Do not add, move, remove, or alter any product, and do not add any text, logos, or watermarks.';

const router = Router();

/**
 * Phase 3 pipeline per image: detect watermark/logo → masked inpaint (crop &
 * paste-back, garment untouched elsewhere) → deterministic 6:7 framing.
 *
 * Body: { sku, order?, boxes?, forceClean?, skipClean? }
 *  - boxes:      manual watermark regions (full-res px) for the image(s) in `order`
 *  - forceClean: clean even if auto-detection is uncertain (needs a region)
 *  - skipClean:  bypass AI clean-up entirely (deterministic framing only)
 * Clean-up is skipped automatically when an image has no watermark (~80%), or
 * when no API key is set (the image is then flagged for review, never charged).
 */
router.post('/', async (req, res) => {
  const { sku, order, boxes, skipClean } = req.body as {
    sku?: string;
    order?: string[];
    boxes?: Box[];
    skipClean?: boolean;
  };
  if (!sku || !sku.trim()) {
    res.status(400).json({ error: 'SKU is required before generating' });
    return;
  }
  const ids = Array.isArray(order) && order.length ? order : store.all().map((r) => r.id);
  if (ids.length === 0) {
    res.status(400).json({ error: 'No images to process' });
    return;
  }

  const settings = getSettings();
  const pricePerEdit = settings.provider === 'openai' ? settings.pricing.openaiPerImageUSD : settings.pricing.geminiPerImageUSD;
  const manualBoxes = Array.isArray(boxes) ? boxes : [];

  // Resolve the provider once (null = no key). Used for both watermark clean-up
  // and background outpaint.
  let provider: AiProvider | null = null;
  try {
    provider = getProvider();
  } catch {
    provider = null;
  }
  const outpaintFn =
    provider && config.extendBackground
      ? async (rgba: Buffer, canvas: { width: number; height: number }) =>
          (await provider!.outpaint({ image: rgba, region: { left: 0, top: 0, width: canvas.width, height: canvas.height }, canvas, prompt: OUTPAINT_PROMPT })).image
      : undefined;

  const queue = [...ids];
  const worker = async () => {
    for (let id = queue.shift(); id !== undefined; id = queue.shift()) {
      const rec = store.get(id);
      if (!rec) continue;
      const flags: string[] = [];
      let aiUsed = false;
      try {
        let img: Buffer = await readFile(normalizedPath(id));

        // ── AI clean-up (masked inpaint) — only on manually-marked regions, or
        // auto-detected ones when explicitly enabled (off by default). ──────────
        if (!skipClean) {
          const regions = manualBoxes.length
            ? manualBoxes
            : config.autoDetectWatermarks
              ? (await detectWatermarks(img)).boxes
              : [];
          if (regions.length) {
            if (provider) {
              rec.status = 'cleaning';
              const cl = await applyCleanup(img, regions, provider);
              img = cl.buffer;
              if (cl.aiCalls > 0) {
                aiUsed = true;
                costTally.add(cl.aiCalls, cl.aiCalls * pricePerEdit);
              }
              flags.push(...cl.flags);
            } else {
              // No key: never charge, surface for review.
              flags.push('watermark-detected-no-key');
            }
          }
        }

        // ── Framing (+ generative background outpaint when a key is set) ───────
        rec.status = 'framing';
        const framed = await frameImage(img, { outpaint: outpaintFn });
        if (framed.aiUsed) {
          aiUsed = true;
          costTally.add(1, pricePerEdit);
        }
        await writeFile(processedPath(id), framed.buffer);
        rec.processedUrl = `/files/processed/${id}.jpg?ts=${Date.now()}`;
        rec.outputWidth = framed.width;
        rec.outputHeight = framed.height;
        rec.outputBytes = framed.bytes;
        rec.outputQuality = framed.quality;
        rec.aiUsed = aiUsed;
        rec.error = undefined;
        rec.flags = [...flags, ...framed.flags];
        rec.needsReview = rec.warnings.length > 0 || rec.flags.length > 0;
        rec.status = rec.needsReview ? 'review' : 'done';
      } catch (e) {
        rec.status = 'failed';
        rec.error = e instanceof Error ? e.message : 'Processing failed';
      }
    }
  };

  const n = Math.min(config.concurrency, ids.length);
  await Promise.all(Array.from({ length: n }, () => worker()));

  res.json({ images: ids.map((id) => store.get(id)).filter(Boolean) });
});

export default router;
