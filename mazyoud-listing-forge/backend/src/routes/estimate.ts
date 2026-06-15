import { Router } from 'express';
import { readFile } from 'node:fs/promises';
import { config } from '../config';
import { getSettings } from '../services/settings';
import { costTally } from '../ai';
import { store, normalizedPath } from '../services/store';
import { detectWatermarks } from '../services/watermark';

const router = Router();

/**
 * Estimate AI usage/cost BEFORE running a batch by actually detecting watermarks
 * across the images, so the confirm gate reflects real predicted spend (most
 * images have none → 0 AI cost). Cheap: detection runs on a small downscale.
 */
router.post('/', async (req, res) => {
  const { order, skipClean } = req.body as { order?: string[]; skipClean?: boolean };
  const ids = Array.isArray(order) && order.length ? order : store.all().map((r) => r.id);
  const images = ids.length;

  const settings = getSettings();
  const provider = settings.provider;
  const perImageUSD = provider === 'openai' ? settings.pricing.openaiPerImageUSD : settings.pricing.geminiPerImageUSD;

  let aiCalls = 0;
  let watermarked = 0;
  if (!skipClean) {
    for (const id of ids) {
      if (!store.get(id)) continue;
      try {
        const { boxes } = await detectWatermarks(await readFile(normalizedPath(id)));
        if (boxes.length) {
          watermarked += 1;
          aiCalls += boxes.length;
        }
      } catch {
        /* skip unreadable */
      }
    }
  }

  const estCostUSD = +(aiCalls * perImageUSD).toFixed(2);
  res.json({
    images,
    provider,
    watermarked,
    aiCalls,
    estCostUSD,
    perImageUSD,
    requiresConfirm: images >= config.largeBatchConfirmThreshold || aiCalls > 0,
    note:
      aiCalls === 0
        ? 'No watermarks detected — deterministic framing only, no AI cost.'
        : `${watermarked} image(s) look watermarked → ~${aiCalls} AI edit(s).`,
  });
});

// Running cost tally for the current batch.
router.get('/cost', (_req, res) => res.json(costTally.get()));
router.post('/cost/reset', (_req, res) => {
  costTally.reset();
  res.json(costTally.get());
});

export default router;
