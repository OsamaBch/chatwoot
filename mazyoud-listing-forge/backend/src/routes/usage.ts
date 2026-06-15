import { Router } from 'express';
import { getUsage, recordRun, clearUsage } from '../services/usage';
import { getSettings } from '../services/settings';

const router = Router();

// Full consumption dashboard data.
router.get('/', (_req, res) => res.json(getUsage()));

// Record one completed batch run (called by the UI after Generate finishes).
router.post('/record', (req, res) => {
  const { sku, images, aiCalls, costUSD } = req.body as {
    sku?: string;
    images?: number;
    aiCalls?: number;
    costUSD?: number;
  };
  const usage = recordRun({
    sku: typeof sku === 'string' ? sku : '',
    provider: getSettings().provider,
    images: Math.max(0, Math.floor(Number(images) || 0)),
    aiCalls: Math.max(0, Math.floor(Number(aiCalls) || 0)),
    costUSD: Math.max(0, Number(costUSD) || 0),
  });
  res.json(usage);
});

router.post('/clear', (_req, res) => res.json(clearUsage()));

export default router;
