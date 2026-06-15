import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import { config, type AiProviderName } from '../config';
import { appDataDir } from './appdata';

/** Non-secret runtime preferences (active provider + editable model ids). */
const FILE = path.join(appDataDir(), 'settings.json');

export interface RuntimeSettings {
  provider: AiProviderName;
  geminiModelId: string;
  openaiModelId: string;
}

function readFile(): Partial<RuntimeSettings> {
  try {
    if (!existsSync(FILE)) return {};
    return JSON.parse(readFileSync(FILE, 'utf8')) as Partial<RuntimeSettings>;
  } catch {
    return {};
  }
}

/** Resolution order: saved settings → .env overrides → config.ts defaults. */
export function getSettings(): RuntimeSettings {
  const f = readFile();
  const provider = (f.provider || (process.env.AI_PROVIDER as AiProviderName | undefined) || config.aiProvider);
  return {
    provider: provider === 'openai' ? 'openai' : 'gemini',
    geminiModelId: f.geminiModelId || process.env.GEMINI_MODEL_ID || config.geminiModelId,
    openaiModelId: f.openaiModelId || process.env.OPENAI_MODEL_ID || config.openaiModelId,
  };
}

export function saveSettings(patch: Partial<RuntimeSettings>): RuntimeSettings {
  const cur = getSettings();
  const next: RuntimeSettings = {
    provider: patch.provider ?? cur.provider,
    geminiModelId: patch.geminiModelId ?? cur.geminiModelId,
    openaiModelId: patch.openaiModelId ?? cur.openaiModelId,
  };
  const dir = path.dirname(FILE);
  if (!existsSync(dir)) mkdirSync(dir, { recursive: true });
  writeFileSync(FILE, JSON.stringify(next, null, 2));
  return next;
}
