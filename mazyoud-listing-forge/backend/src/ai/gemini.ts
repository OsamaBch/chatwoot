import { withRetry, redact } from './retry';
import { ProviderError, type AiProvider, type AiResult, type CleanupInput, type OutpaintInput } from './provider';

const BASE = 'https://generativelanguage.googleapis.com/v1beta';

interface GeminiResponse {
  candidates?: { content?: { parts?: { text?: string; inlineData?: { mimeType?: string; data?: string } }[] } }[];
}

/** Gemini "Nano Banana Pro" (Gemini 3 Pro Image) via the Generative Language API. */
export class GeminiProvider implements AiProvider {
  readonly name = 'gemini' as const;
  constructor(
    private readonly apiKey: string,
    public readonly modelId: string,
  ) {}

  async testKey(): Promise<{ ok: boolean; message: string }> {
    try {
      const res = await fetch(`${BASE}/models`, { headers: { 'x-goog-api-key': this.apiKey } });
      if (res.ok) return { ok: true, message: `Gemini key valid. Active model: ${this.modelId}.` };
      const body = redact(await safeText(res)).slice(0, 200);
      return { ok: false, message: `Gemini rejected the key (HTTP ${res.status}). ${body}` };
    } catch (e) {
      return { ok: false, message: `Could not reach Gemini: ${redact(errMsg(e))}` };
    }
  }

  async cleanup(input: CleanupInput): Promise<AiResult> {
    const parts: unknown[] = [
      {
        text:
          input.prompt ??
          'Remove only the watermark/logo/text inside the masked region. Keep the garment and every other pixel exactly identical — do not change its design, color, pattern, or proportions.',
      },
      { inlineData: { mimeType: 'image/png', data: input.image.toString('base64') } },
    ];
    if (input.mask) parts.push({ inlineData: { mimeType: 'image/png', data: input.mask.toString('base64') } });
    return { image: await this.generateImage(parts) };
  }

  async outpaint(input: OutpaintInput): Promise<AiResult> {
    const parts: unknown[] = [
      {
        text:
          input.prompt ??
          `Extend ONLY the background to fill the added area for a ${input.canvas.width}x${input.canvas.height} canvas. Do not add, remove, move, or alter the product in any way.`,
      },
      { inlineData: { mimeType: 'image/png', data: input.image.toString('base64') } },
    ];
    return { image: await this.generateImage(parts) };
  }

  private async generateImage(parts: unknown[]): Promise<Buffer> {
    return withRetry(async () => {
      const res = await fetch(`${BASE}/models/${this.modelId}:generateContent`, {
        method: 'POST',
        headers: { 'x-goog-api-key': this.apiKey, 'Content-Type': 'application/json' },
        body: JSON.stringify({ contents: [{ parts }] }),
      });
      if (!res.ok) {
        throw new ProviderError(`Gemini generateContent failed (HTTP ${res.status}): ${redact(await safeText(res)).slice(0, 300)}`, res.status);
      }
      const data = (await res.json()) as GeminiResponse;
      const b64 = data.candidates?.[0]?.content?.parts?.find((p) => p.inlineData?.data)?.inlineData?.data;
      if (!b64) throw new ProviderError('Gemini returned no image data', 502);
      return Buffer.from(b64, 'base64');
    });
  }
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
