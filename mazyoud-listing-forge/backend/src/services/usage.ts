import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import type { AiProviderName } from '../config';
import { appDataDir } from './appdata';

/**
 * Persistent AI-consumption tracker for the dashboard. Survives restarts (stored
 * in app-data, outside the repo). Cumulative + a capped recent-runs history.
 */
const FILE = path.join(appDataDir(), 'usage.json');
const MAX_RUNS = 50;

export interface UsageRun {
  ts: string; // ISO timestamp
  sku: string;
  provider: AiProviderName;
  images: number;
  aiCalls: number;
  costUSD: number;
}

export interface ProviderTotals {
  aiCalls: number;
  costUSD: number;
}

export interface Usage {
  totals: { runs: number; images: number; aiCalls: number; costUSD: number };
  byProvider: Record<AiProviderName, ProviderTotals>;
  runs: UsageRun[];
}

function empty(): Usage {
  return {
    totals: { runs: 0, images: 0, aiCalls: 0, costUSD: 0 },
    byProvider: { gemini: { aiCalls: 0, costUSD: 0 }, openai: { aiCalls: 0, costUSD: 0 } },
    runs: [],
  };
}

export function getUsage(): Usage {
  try {
    if (!existsSync(FILE)) return empty();
    const parsed = JSON.parse(readFileSync(FILE, 'utf8')) as Usage;
    return { ...empty(), ...parsed };
  } catch {
    return empty();
  }
}

function write(u: Usage): void {
  const dir = path.dirname(FILE);
  if (!existsSync(dir)) mkdirSync(dir, { recursive: true });
  writeFileSync(FILE, JSON.stringify(u, null, 2));
}

export function recordRun(run: Omit<UsageRun, 'ts'>): Usage {
  const u = getUsage();
  const entry: UsageRun = { ...run, ts: new Date().toISOString() };
  u.runs.unshift(entry);
  if (u.runs.length > MAX_RUNS) u.runs.length = MAX_RUNS;
  u.totals.runs += 1;
  u.totals.images += run.images;
  u.totals.aiCalls += run.aiCalls;
  u.totals.costUSD = +(u.totals.costUSD + run.costUSD).toFixed(4);
  const p = u.byProvider[run.provider];
  p.aiCalls += run.aiCalls;
  p.costUSD = +(p.costUSD + run.costUSD).toFixed(4);
  write(u);
  return u;
}

export function clearUsage(): Usage {
  const u = empty();
  write(u);
  return u;
}
