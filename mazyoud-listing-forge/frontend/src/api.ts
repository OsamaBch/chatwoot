import type {
  AiProviderName,
  AppSettings,
  ConflictPolicy,
  CostTally,
  Estimate,
  ImageRecord,
  KeyStatus,
  PipelineConfig,
} from './types';

async function json<T>(res: Response): Promise<T> {
  if (!res.ok) {
    let msg = `${res.status} ${res.statusText}`;
    try {
      const body = (await res.json()) as { error?: string };
      if (body?.error) msg = body.error;
    } catch {
      /* ignore */
    }
    throw new Error(msg);
  }
  return res.json() as Promise<T>;
}

export const api = {
  async getConfig(): Promise<PipelineConfig> {
    return json(await fetch('/api/config'));
  },

  async ingest(files: File[]): Promise<{ images: ImageRecord[]; rejected: { name: string; reason: string }[] }> {
    const fd = new FormData();
    for (const f of files) fd.append('files', f, f.name);
    return json(await fetch('/api/ingest', { method: 'POST', body: fd }));
  },

  async generate(sku: string, order: string[]): Promise<{ images: ImageRecord[] }> {
    return json(
      await fetch('/api/generate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sku, order }),
      }),
    );
  },

  async exportCheck(sku: string, order: string[], outputDir: string): Promise<{ outputDir: string; conflicts: string[] }> {
    return json(
      await fetch('/api/export/check', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sku, order, outputDir }),
      }),
    );
  },

  async exportFolder(
    sku: string,
    order: string[],
    outputDir: string,
    onConflict: ConflictPolicy,
  ): Promise<{ outputDir: string; written: string[]; skipped: string[]; versioned: string[]; manifest: string }> {
    return json(
      await fetch('/api/export/folder', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sku, order, outputDir, onConflict }),
      }),
    );
  },

  async downloadZip(sku: string, order: string[], filename: string): Promise<void> {
    const res = await fetch('/api/export/zip', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ sku, order }),
    });
    if (!res.ok) throw new Error((await res.json().catch(() => ({})))?.error ?? 'ZIP export failed');
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  },

  async reset(): Promise<void> {
    await fetch('/api/reset', { method: 'POST' });
  },

  // ── Settings / provider (phase 2) ──────────────────────────────────────────
  async getSettings(): Promise<AppSettings> {
    return json(await fetch('/api/settings'));
  },

  async saveSettings(patch: {
    provider?: AiProviderName;
    geminiModelId?: string;
    openaiModelId?: string;
  }): Promise<void> {
    await json(
      await fetch('/api/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(patch),
      }),
    );
  },

  async saveKey(provider: AiProviderName, key: string): Promise<KeyStatus & { ok: boolean }> {
    return json(
      await fetch('/api/settings/key', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ provider, key }),
      }),
    );
  },

  async clearKey(provider: AiProviderName): Promise<void> {
    await fetch('/api/settings/key', {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ provider }),
    });
  },

  async testKey(provider: AiProviderName): Promise<{ ok: boolean; message: string }> {
    return json(
      await fetch('/api/settings/test', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ provider }),
      }),
    );
  },

  async estimate(order: string[]): Promise<Estimate> {
    return json(
      await fetch('/api/estimate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order }),
      }),
    );
  },

  async getCost(): Promise<CostTally> {
    return json(await fetch('/api/estimate/cost'));
  },
};
