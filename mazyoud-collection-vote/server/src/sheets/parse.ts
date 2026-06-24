import { COL } from '../config';
import type { Product } from './types';

/**
 * Extract the first quoted URL from a Google Sheets =IMAGE("...") formula.
 *
 * Tolerates:
 *   =IMAGE("https://x/y.jpg")
 *   =IMAGE('https://x/y.jpg')
 *   =IMAGE("https://x/y.jpg", 1)        // mode arg
 *   =IMAGE("https://x/y.jpg",4,80,80)   // explicit size args
 *   leading/trailing whitespace, mixed case (image / IMAGE)
 *
 * Returns the raw URL string, or null if no quoted argument is found.
 */
export function extractImageUrl(cell: unknown): string | null {
  if (cell == null) return null;
  const s = String(cell).trim();
  if (!s) return null;

  // Primary: a real IMAGE(...) formula — take the first quoted string.
  const m = s.match(/IMAGE\(\s*["']([^"']+)["']/i);
  if (m && m[1]) return m[1].trim();

  // Fallback: the cell might already be a bare URL (some exports flatten it).
  if (/^https?:\/\//i.test(s)) return s;

  return null;
}

/** True when a row is a product anchor (column B / image cell is non-empty). */
export function isAnchorRow(row: unknown[]): boolean {
  const b = row[COL.IMAGE];
  return b != null && String(b).trim() !== '';
}

/**
 * Read column C as a price number.
 * `unformatted` is the UNFORMATTED_VALUE render (cached numeric, e.g. 3950).
 * `formatted` is the display string fallback (e.g. "3 950 DZD").
 */
export function readPrice(unformatted: unknown, formatted: unknown): number | null {
  if (typeof unformatted === 'number' && Number.isFinite(unformatted)) {
    return unformatted;
  }
  if (typeof unformatted === 'string' && unformatted.trim() !== '') {
    const n = coercePrice(unformatted);
    if (n != null) return n;
  }
  if (formatted != null && String(formatted).trim() !== '') {
    const n = coercePrice(String(formatted));
    if (n != null) return n;
  }
  return null;
}

/** Pull a number out of a formatted price string ("3 950 DZD" -> 3950). */
function coercePrice(s: string): number | null {
  // Keep digits, separators and a sign; drop currency text & spaces.
  const cleaned = s.replace(/[^\d.,\-]/g, '').replace(/\s/g, '');
  if (!cleaned) return null;
  // If both separators exist, assume "," is thousands and "." decimal.
  let normalized = cleaned;
  if (cleaned.includes('.') && cleaned.includes(',')) {
    normalized = cleaned.replace(/,/g, '');
  } else if (cleaned.includes(',')) {
    // Lone comma: treat as thousands separator (DZD prices are integers).
    normalized = cleaned.replace(/,/g, '');
  }
  const n = Number.parseFloat(normalized);
  return Number.isFinite(n) ? n : null;
}

function cell(row: unknown[], idx: number): string {
  const v = row[idx];
  return v == null ? '' : String(v).trim();
}

function optional(row: unknown[], idx: number): string | undefined {
  const v = cell(row, idx);
  return v === '' ? undefined : v;
}

/**
 * Build the product list from the source grid.
 *
 * @param formulaRows  rows rendered with valueRenderOption=FORMULA (col B = =IMAGE("..."))
 * @param valueRows    rows rendered with valueRenderOption=UNFORMATTED_VALUE (col C = number)
 * @param formattedRows optional FORMATTED_VALUE rows (price display fallback)
 * @param headerOffset  number of header rows to skip (default 1 — header is row 1)
 *
 * Anchors are detected dynamically: every row whose column B is non-empty is a
 * product. No fixed product count or row positions are assumed, so the same code
 * works on a 14-row and a 377-row sheet.
 */
export function parseProducts(
  formulaRows: unknown[][],
  valueRows: unknown[][],
  formattedRows: unknown[][] = [],
  headerOffset = 1,
): Product[] {
  const products: Product[] = [];
  const maxLen = Math.max(formulaRows.length, valueRows.length);

  for (let i = headerOffset; i < maxLen; i++) {
    const frow = formulaRows[i] ?? [];
    const vrow = valueRows[i] ?? [];
    const fmtRow = formattedRows[i] ?? [];

    // Anchor detection is driven by the FORMULA render of column B, because
    // non-anchor rows are blank there while anchors hold the IMAGE() formula.
    if (!isAnchorRow(frow)) continue;

    const rowNumber = i + 1; // sheet rows are 1-based; row 1 is the header

    // product_key: trimmed col M; fall back to row-<anchorRow> when M is empty.
    const productLink = cell(vrow, COL.PRODUCT_LINK);
    const product_key = productLink !== '' ? productLink : `row-${rowNumber}`;

    products.push({
      product_key,
      row_anchor: rowNumber,
      image_url: extractImageUrl(frow[COL.IMAGE]),
      price: readPrice(vrow[COL.PRICE], fmtRow[COL.PRICE]),
      color: optional(vrow, COL.COLOR),
      remark: optional(vrow, COL.REMARK),
      category: optional(vrow, COL.CATEGORY),
      gender: optional(vrow, COL.GENDER),
      notes: optional(vrow, COL.NOTES),
      title: optional(vrow, COL.TITLE),
      supplier: optional(vrow, COL.SUPPLIER),
      supplier_link: optional(vrow, COL.SUPPLIER_LINK),
      product_link: productLink || undefined,
    });
  }

  return products;
}
