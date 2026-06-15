export interface ManifestRow {
  sku: string;
  filename: string;
  order: number | string; // 0 = hero; '' for non-exported (failed/skipped)
  source: string;
  status: string;
  ai_used: boolean;
  flags: string;
}

const HEADER = ['SKU', 'filename', 'order', 'source', 'status', 'ai_used', 'flags'];

function esc(v: unknown): string {
  const s = String(v ?? '');
  return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

/** Per-run manifest: traceability + a feed for the SKU Matcher. */
export function toCsv(rows: ManifestRow[]): string {
  const lines = [HEADER.join(',')];
  for (const r of rows) {
    lines.push([r.sku, r.filename, r.order, r.source, r.status, r.ai_used, r.flags].map(esc).join(','));
  }
  return lines.join('\n') + '\n';
}
