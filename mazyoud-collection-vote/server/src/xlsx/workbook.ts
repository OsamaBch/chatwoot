import ExcelJS from 'exceljs';
import {
  INLINE_HEADERS,
  RESULTS_HEADERS,
  config,
} from '../config';
import { parseProducts } from '../sheets/parse';
import type { Product, ResultRow } from '../sheets/types';

// We only ever read/parse columns A..S (0-based 0..18), exactly like the Google
// path, so the protected U/V named-range columns are never touched on read.
const LAST_COL_INDEX = 19;

interface CellTriple {
  formula: string; // FORMULA-equivalent (drives anchor detection + image URL)
  value: unknown; // UNFORMATTED-equivalent (cached number / underlying value)
  formatted: string; // display text (price fallback)
}

/** Normalize an ExcelJS cell into the three render-equivalents parseProducts wants. */
function cellTriple(cell: ExcelJS.Cell): CellTriple {
  const v = cell.value as unknown;
  if (v == null) return { formula: '', value: null, formatted: '' };

  if (typeof v === 'object') {
    const obj = v as Record<string, unknown>;

    // Formula cell: { formula | sharedFormula, result }
    if ('formula' in obj || 'sharedFormula' in obj) {
      const formula = String(obj.formula ?? obj.sharedFormula ?? '');
      const result = obj.result;
      return {
        formula,
        value: result ?? null,
        formatted: cell.text ?? (result == null ? '' : String(result)),
      };
    }
    // Hyperlink cell: { text, hyperlink } — prefer the target (e.g. col M link).
    if ('hyperlink' in obj) {
      const link = String(obj.hyperlink ?? obj.text ?? '');
      return { formula: link, value: link, formatted: String(obj.text ?? link) };
    }
    // Rich text / shared string.
    if ('richText' in obj) {
      const t = cell.text ?? '';
      return { formula: t, value: t, formatted: t };
    }
    if (v instanceof Date) {
      return { formula: '', value: v, formatted: cell.text ?? '' };
    }
    // Error or other object value.
    const t = cell.text ?? '';
    return { formula: t, value: t, formatted: t };
  }

  // Primitive (string | number | boolean).
  return { formula: String(v), value: v, formatted: cell.text ?? String(v) };
}

function pickWorksheet(
  wb: ExcelJS.Workbook,
  preferred?: string,
): ExcelJS.Worksheet | undefined {
  if (preferred) {
    const byName = wb.getWorksheet(preferred);
    if (byName) return byName;
  }
  const configured = wb.getWorksheet(config.productsTab);
  if (configured) return configured;
  return wb.worksheets.find((w) => w.rowCount > 0) ?? wb.worksheets[0];
}

export interface ParsedWorkbook {
  products: Product[];
  sheetName: string;
}

/** Parse an uploaded .xlsx into products using the shared parse.ts logic. */
export async function parseWorkbook(
  buffer: Buffer,
  preferredSheet?: string,
): Promise<ParsedWorkbook> {
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.load(buffer as unknown as ArrayBuffer);
  const ws = pickWorksheet(wb, preferredSheet);
  if (!ws) throw new Error('workbook has no worksheets');

  const formulaRows: unknown[][] = [];
  const valueRows: unknown[][] = [];
  const formattedRows: unknown[][] = [];

  // rowCount = index of the last row that has values (NOT actualRowCount, which
  // is the *count* of non-empty rows and would stop short on sparse 10-row-block
  // layouts where most rows are blank).
  const rowCount = ws.rowCount;
  for (let r = 1; r <= rowCount; r++) {
    const row = ws.getRow(r);
    const f = new Array(LAST_COL_INDEX).fill('');
    const val: unknown[] = new Array(LAST_COL_INDEX).fill(null);
    const fmt = new Array(LAST_COL_INDEX).fill('');
    for (let i = 0; i < LAST_COL_INDEX; i++) {
      const t = cellTriple(row.getCell(i + 1)); // ExcelJS columns are 1-based
      f[i] = t.formula;
      val[i] = t.value;
      fmt[i] = t.formatted;
    }
    formulaRows[r - 1] = f;
    valueRows[r - 1] = val;
    formattedRows[r - 1] = fmt;
  }

  const products = parseProducts(formulaRows, valueRows, formattedRows, 1);
  return { products, sheetName: ws.name };
}

/**
 * Re-open the original workbook and bake results in:
 *  - a rebuilt "Results" sheet (ranked rows)
 *  - optional inline W..Z on the source sheet's anchor rows (never U/V)
 * Returns the new .xlsx as a Buffer.
 */
export async function exportWorkbook(
  originalBuffer: Buffer,
  sheetName: string,
  results: ResultRow[],
  inline = config.inlineWriteback,
): Promise<Buffer> {
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.load(originalBuffer as unknown as ArrayBuffer);

  // 1) (Re)build the Results sheet.
  const existing = wb.getWorksheet('Results');
  if (existing) wb.removeWorksheet(existing.id);
  const rs = wb.addWorksheet('Results');
  rs.addRow(RESULTS_HEADERS);
  rs.getRow(1).font = { bold: true };
  for (const r of results) {
    rs.addRow([
      r.product_key,
      r.row_anchor,
      r.title,
      r.price ?? '',
      r.category,
      r.gender,
      r.image_url,
      r.keep,
      r.skip,
      r.super,
      r.voters,
      r.net_score,
      r.verdict,
      r.conflict ? 'YES' : '',
      r.hero ? '⭐' : '',
      r.last_updated,
    ]);
  }
  rs.columns.forEach((c) => {
    c.width = 16;
  });

  // 2) Inline mirror on the source sheet's anchor rows (W..Z only).
  if (inline) {
    const ws = wb.getWorksheet(sheetName) ?? wb.worksheets[0];
    if (ws) {
      ws.getCell('W1').value = INLINE_HEADERS.W;
      ws.getCell('X1').value = INLINE_HEADERS.X;
      ws.getCell('Y1').value = INLINE_HEADERS.Y;
      ws.getCell('Z1').value = INLINE_HEADERS.Z;
      for (const r of results) {
        const a = r.row_anchor;
        if (!a || a < 2) continue;
        ws.getCell(`W${a}`).value = r.net_score;
        ws.getCell(`X${a}`).value = r.verdict;
        ws.getCell(`Y${a}`).value = r.conflict ? 'YES' : '';
        ws.getCell(`Z${a}`).value = `keep:${r.keep} skip:${r.skip} super:${r.super}`;
      }
    }
  }

  const out = await wb.xlsx.writeBuffer();
  return Buffer.from(out);
}
