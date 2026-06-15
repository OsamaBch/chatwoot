import sharp from 'sharp';
import { config } from '../config';
import { withRetry, redact, fetchWithTimeout } from './retry';
import { ProviderError, type AiProvider, type AiResult, type CleanupInput, type OutpaintInput } from './provider';

const BASE = 'https://api.openai.com/v1';

/** OpenAI gpt-image-1 (edits/inpaint). ~1536px long-side cap → Lanczos upscale after. */
export class OpenAIProvider implements AiProvider {
  readonly name = 'openai' as const;
  constructor(
    private readonly apiKey: string,
    public readonly modelId: string,
  ) {}

  async testKey(): Promise<{ ok: boolean; message: string }> {
    try {
      const res = await fetchWithTimeout(`${BASE}/models`, { headers: { Authorization: `Bearer ${this.apiKey}` } }, config.aiRequestTimeoutMs);
      if (res.ok) return { ok: true, message: `OpenAI key valid. Active model: ${this.modelId}.` };
      const body = redact(await safeText(res)).slice(0, 200);
      return { ok: false, message: `OpenAI rejected the key (HTTP ${res.status}). ${body}` };
    } catch (e) {
      return { ok: false, message: `Could not reach OpenAI: ${redact(errMsg(e))}` };
    }
  }

  async cleanup(input: CleanupInput): Promise<AiResult> {
    const form = new FormData();
    form.append('model', this.modelId);
    form.append(
      'prompt',
      input.prompt ??
        'Remove only the watermark/logo/text in the masked area. Keep the garment and every other pixel identical — no change to design, color, pattern, or proportions.',
    );
    form.append('image', blob(input.image), 'image.png');
    if (input.mask) form.append('mask', blob(input.mask), 'mask.png');
    return { image: await this.edit(form) };
  }

  async outpaint(input: OutpaintInput): Promise<AiResult> {
    // Caller passes the product already placed on the larger canvas with the area
    // to fill transparent (+ optional mask). gpt-image-1 caps the long side, so we
    // upscale the result to the exact target with Lanczos if needed.
    const form = new FormData();
    form.append('model', this.modelId);
    form.append('prompt', input.prompt ?? 'Extend only the background to fill the transparent area. Never alter the product.');
    form.append('image', blob(input.image), 'image.png');
    let image = await this.edit(form);
    if (Math.max(input.canvas.width, input.canvas.height) > config.openaiMaxLongSide) {
      image = await sharp(image)
        .resize(input.canvas.width, input.canvas.height, { fit: 'fill', kernel: 'lanczos3' })
        .png()
        .toBuffer();
    }
    return { image };
  }

  private async edit(form: FormData): Promise<Buffer> {
    return withRetry(async () => {
      const res = await fetchWithTimeout(
        `${BASE}/images/edits`,
        { method: 'POST', headers: { Authorization: `Bearer ${this.apiKey}` }, body: form },
        config.aiRequestTimeoutMs,
      );
      if (!res.ok) {
        throw new ProviderError(`OpenAI images/edits failed (HTTP ${res.status}): ${redact(await safeText(res)).slice(0, 300)}`, res.status);
      }
      const data = (await res.json()) as { data?: { b64_json?: string }[] };
      const b64 = data.data?.[0]?.b64_json;
      if (!b64) throw new ProviderError('OpenAI returned no image data', 502);
      return Buffer.from(b64, 'base64');
    });
  }
}

function blob(b: Buffer): Blob {
  return new Blob([new Uint8Array(b.buffer, b.byteOffset, b.byteLength)], { type: 'image/png' });
}
function errMsg(e: unknown): string {
  return e instanceof Error ? e.message : String(e);
}
async function safeText(res: Response): Promise<string> {
  try {
    return await res.text();
  } catch {
    return '';
  }
}
