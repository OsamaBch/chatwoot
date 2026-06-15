import { Router } from 'express';
import { getSettings, saveSettings } from '../services/settings';
import { keyStatus, saveKey, clearKey } from '../services/secrets';
import { getProvider } from '../ai';
import { config, type AiProviderName } from '../config';

const router = Router();

function isProvider(p: unknown): p is AiProviderName {
  return p === 'gemini' || p === 'openai';
}

// Current settings + whether each provider has a key (NEVER the key itself).
router.get('/', (_req, res) => {
  const s = getSettings();
  res.json({
    ...s,
    keys: { gemini: keyStatus('gemini'), openai: keyStatus('openai') },
    pricing: config.aiPricing,
    openaiMaxLongSide: config.openaiMaxLongSide,
  });
});

// Update provider + editable model ids.
router.post('/', (req, res) => {
  const { provider, geminiModelId, openaiModelId } = req.body as Partial<{
    provider: AiProviderName;
    geminiModelId: string;
    openaiModelId: string;
  }>;
  const next = saveSettings({
    provider: isProvider(provider) ? provider : undefined,
    geminiModelId: typeof geminiModelId === 'string' ? geminiModelId.trim() : undefined,
    openaiModelId: typeof openaiModelId === 'string' ? openaiModelId.trim() : undefined,
  });
  res.json(next);
});

// Save a key (masked in the UI; never logged here).
router.post('/key', (req, res) => {
  const { provider, key } = req.body as { provider?: string; key?: string };
  if (!isProvider(provider)) {
    res.status(400).json({ error: 'Unknown provider' });
    return;
  }
  if (!key || !key.trim()) {
    res.status(400).json({ error: 'Key is empty' });
    return;
  }
  saveKey(provider, key.trim());
  res.json({ ok: true, ...keyStatus(provider) });
});

router.delete('/key', (req, res) => {
  const { provider } = req.body as { provider?: string };
  if (!isProvider(provider)) {
    res.status(400).json({ error: 'Unknown provider' });
    return;
  }
  clearKey(provider);
  res.json({ ok: true, ...keyStatus(provider) });
});

// One cheap validation call; reports success/failure clearly.
router.post('/test', async (req, res) => {
  const { provider } = req.body as { provider?: string };
  try {
    const result = await getProvider(isProvider(provider) ? provider : undefined).testKey();
    res.json(result);
  } catch (e) {
    res.json({ ok: false, message: e instanceof Error ? e.message : 'Test failed' });
  }
});

export default router;
