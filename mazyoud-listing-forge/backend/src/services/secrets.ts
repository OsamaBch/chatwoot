import { readFileSync, writeFileSync, existsSync, mkdirSync, chmodSync } from 'node:fs';
import path from 'node:path';
import type { AiProviderName } from '../config';
import { appDataDir } from './appdata';

/**
 * Provider API keys. Stored in a 0600 file in the OS app-data dir (never in the
 * repo, never logged). `.env` is a fallback only. The in-app field is primary.
 */
const FILE = path.join(appDataDir(), 'secrets.json');
const ENV_VAR: Record<AiProviderName, string> = { gemini: 'GEMINI_API_KEY', openai: 'OPENAI_API_KEY' };

type SecretStore = Partial<Record<AiProviderName, string>>;
export type KeySource = 'saved' | 'env' | 'none';

function readStore(): SecretStore {
  try {
    if (!existsSync(FILE)) return {};
    return JSON.parse(readFileSync(FILE, 'utf8')) as SecretStore;
  } catch {
    return {};
  }
}

function writeStore(store: SecretStore): void {
  const dir = path.dirname(FILE);
  if (!existsSync(dir)) mkdirSync(dir, { recursive: true });
  writeFileSync(FILE, JSON.stringify(store), { mode: 0o600 });
  try {
    chmodSync(FILE, 0o600);
  } catch {
    /* best effort on non-POSIX filesystems */
  }
}

/** Resolve a provider's key: saved app-data file first, then .env fallback. */
export function getKey(provider: AiProviderName): { key: string | null; source: KeySource } {
  const saved = readStore()[provider];
  if (saved && saved.trim()) return { key: saved, source: 'saved' };
  const env = process.env[ENV_VAR[provider]];
  if (env && env.trim()) return { key: env, source: 'env' };
  return { key: null, source: 'none' };
}

/** Presence + source only — NEVER the key value (safe to send to the client). */
export function keyStatus(provider: AiProviderName): { hasKey: boolean; source: KeySource } {
  const { key, source } = getKey(provider);
  return { hasKey: !!key, source };
}

export function saveKey(provider: AiProviderName, key: string): void {
  const store = readStore();
  store[provider] = key;
  writeStore(store);
}

export function clearKey(provider: AiProviderName): void {
  const store = readStore();
  delete store[provider];
  writeStore(store);
}
