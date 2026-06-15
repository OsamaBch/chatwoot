import type { AiProviderName } from '../config';
import { getSettings } from '../services/settings';
import { getKey } from '../services/secrets';
import { ProviderError, type AiProvider } from './provider';
import { GeminiProvider } from './gemini';
import { OpenAIProvider } from './openai';

/** Build the active provider (or a named one) with its resolved key + model id. */
export function getProvider(name?: AiProviderName): AiProvider {
  const settings = getSettings();
  const provider = name ?? settings.provider;
  const { key } = getKey(provider);
  if (!key) throw new ProviderError(`No API key set for ${provider}. Add one in Settings.`, 401);
  return provider === 'openai'
    ? new OpenAIProvider(key, settings.openaiModelId)
    : new GeminiProvider(key, settings.geminiModelId);
}

export { ProviderError, costTally } from './provider';
export type { AiProvider, CleanupInput, OutpaintInput, AiResult } from './provider';
