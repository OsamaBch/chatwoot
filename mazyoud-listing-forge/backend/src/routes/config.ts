import { Router } from 'express';
import { config } from '../config';

const router = Router();

// Non-secret pipeline config for the frontend (single source of truth).
router.get('/', (_req, res) => {
  res.json(config);
});

export default router;
