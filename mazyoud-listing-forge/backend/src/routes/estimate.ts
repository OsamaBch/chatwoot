import { Router } from 'express';
import { config } from '../config';
import { getSettings } from '../services/settings';
import { costTally } from '../ai';
import { store } from '../services/store';

const router = Router();

/**
 * Estimate AI usage/cost for a batch before running it. Phase 2's deterministic
 * pipeline makes 0 AI calls by default; the `forceCleanup`/`forceOutpaint` counts
 * (phase 3/4 toggles) drive the estimate. The confirm gate is live now.
 */
router.post('/', (req, res) => {
  const { order, forceCleanup = 0, forceOutpaint = 0 } = req.body as {
    order?: string[];
    forceCleanup?: number;
    forceOutpaint?: number;
  };
  const images = Array.isArray(order) && order.length ? order.length : store.all().length;
  const provider = getSettings().provider;
  const perImageUSD = provider === 'openai' ? config.aiPricing.openaiPerImageUSD : config.aiPricing.geminiPerImageUSD;
  const aiCalls = Math.max(0, forceCleanup) + Math.max(0, forceOutpaint);
  const estCostUSD = +(aiCalls * perImageUSD).toFixed(2);

  res.json({
    images,
    provider,
    aiCalls,
    estCostUSD,
    perImageUSD,
    requiresConfirm: images >= config.largeBatchConfirmThreshold || aiCalls > 0,
    note: aiCalls === 0 ? 'Deterministic framing only — AI clean-up activates in Phase 3.' : undefined,
  });
});

// Running cost tally for the current batch.
router.get('/cost', (_req, res) => res.json(costTally.get()));
router.post('/cost/reset', (_req, res) => {
  costTally.reset();
  res.json(costTally.get());
});

export default router;
