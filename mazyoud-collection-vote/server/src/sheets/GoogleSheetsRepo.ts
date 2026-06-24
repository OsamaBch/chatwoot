import { google, sheets_v4 } from 'googleapis';
import {
  a1Tab,
  config,
  INLINE_HEADERS,
  READ_RANGE_LAST_COL,
  RESULTS_HEADERS,
  TAB_RESULTS,
  TAB_VOTERS,
  TAB_VOTES,
  VOTERS_HEADERS,
  VOTES_HEADERS,
} from '../config';
import { parseProducts } from './parse';
import type { SheetsRepo } from './SheetsRepo';
import type { Product, ResultRow, Voter, VoteRow } from './types';
import { Mutex, withRetry } from './util';

const SCOPES = ['https://www.googleapis.com/auth/spreadsheets'];

/** Direct googleapis implementation backed by a Google service account. */
export class GoogleSheetsRepo implements SheetsRepo {
  private sheets: sheets_v4.Sheets | null = null;
  private auth: InstanceType<typeof google.auth.GoogleAuth> | null = null;

  // All writes against the spreadsheet are serialized through this mutex to
  // stay within Sheets' per-user write quota and to keep the Results
  // read-modify-write coherent.
  private writeLock = new Mutex();

  // Product cache (refreshed on TTL or admin refresh).
  private productCache: { at: number; products: Product[] } | null = null;

  // product_key -> 1-based row number in the Results tab. Lazily loaded.
  private resultsIndex: Map<string, number> | null = null;

  private get spreadsheetId(): string {
    if (!config.spreadsheetId) {
      throw new Error('SPREADSHEET_ID is not set');
    }
    return config.spreadsheetId;
  }

  // --- auth / client ------------------------------------------------------
  private async client(): Promise<sheets_v4.Sheets> {
    if (this.sheets) return this.sheets;

    let authOptions: ConstructorParameters<typeof google.auth.GoogleAuth>[0] = {
      scopes: SCOPES,
    };

    if (config.serviceAccountJsonB64) {
      const json = Buffer.from(config.serviceAccountJsonB64, 'base64').toString('utf8');
      const credentials = JSON.parse(json);
      authOptions = { scopes: SCOPES, credentials };
    } else if (config.googleApplicationCredentials) {
      authOptions = { scopes: SCOPES, keyFile: config.googleApplicationCredentials };
    }
    // else: fall back to Application Default Credentials.

    this.auth = new google.auth.GoogleAuth(authOptions);
    // googleapis bundles a google-auth-library whose GoogleAuth default client
    // generic (AuthClient) is wider than what sheets_v4 declares (JSONClient).
    // They are runtime-compatible; bridge the dedup'd type copies here.
    this.sheets = google.sheets({
      version: 'v4',
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      auth: this.auth as any,
    });
    return this.sheets;
  }

  // --- products -----------------------------------------------------------
  async getProducts(forceRefresh = false): Promise<Product[]> {
    const now = Date.now();
    if (
      !forceRefresh &&
      this.productCache &&
      now - this.productCache.at < config.productsCacheTtlMs
    ) {
      return this.productCache.products;
    }

    const sheets = await this.client();
    const range = `${a1Tab(config.productsTab)}!A1:${READ_RANGE_LAST_COL}`;

    // Three reads over the same range with different render options:
    //  - FORMULA           -> col B =IMAGE("...") (also drives anchor detection)
    //  - UNFORMATTED_VALUE -> col C cached price number, plain text fields
    //  - FORMATTED_VALUE   -> price display fallback when the cached value is null
    const [formula, unformatted, formatted] = await Promise.all([
      withRetry(
        () =>
          sheets.spreadsheets.values.get({
            spreadsheetId: this.spreadsheetId,
            range,
            valueRenderOption: 'FORMULA',
          }),
        'products:formula',
      ),
      withRetry(
        () =>
          sheets.spreadsheets.values.get({
            spreadsheetId: this.spreadsheetId,
            range,
            valueRenderOption: 'UNFORMATTED_VALUE',
          }),
        'products:unformatted',
      ),
      withRetry(
        () =>
          sheets.spreadsheets.values.get({
            spreadsheetId: this.spreadsheetId,
            range,
            valueRenderOption: 'FORMATTED_VALUE',
          }),
        'products:formatted',
      ),
    ]);

    const products = parseProducts(
      (formula.data.values ?? []) as unknown[][],
      (unformatted.data.values ?? []) as unknown[][],
      (formatted.data.values ?? []) as unknown[][],
      1,
    );

    this.productCache = { at: now, products };
    return products;
  }

  // --- voters -------------------------------------------------------------
  async getVoters(): Promise<Voter[]> {
    const sheets = await this.client();
    const res = await withRetry(
      () =>
        sheets.spreadsheets.values.get({
          spreadsheetId: this.spreadsheetId,
          range: `${a1Tab(TAB_VOTERS)}!A2:C`,
          valueRenderOption: 'FORMATTED_VALUE', // preserve pin text incl. leading zeros
        }),
      'voters',
    );
    const rows = (res.data.values ?? []) as unknown[][];
    const voters: Voter[] = [];
    for (const r of rows) {
      const name = (r[0] == null ? '' : String(r[0])).trim();
      if (!name) continue;
      const pin = (r[1] == null ? '' : String(r[1])).trim();
      const activeRaw = (r[2] == null ? '' : String(r[2])).trim();
      const active = !/^(false|0|no|n|inactive|disabled)$/i.test(activeRaw);
      voters.push({ name, pin, active });
    }
    return voters;
  }

  // --- votes (append-only) ------------------------------------------------
  async appendVote(v: VoteRow): Promise<void> {
    const sheets = await this.client();
    const row = [
      v.vote_id,
      v.ts_iso,
      v.voter,
      v.product_key,
      String(v.row_anchor),
      v.vote,
      v.undo_target ?? '',
      v.session_id ?? '',
    ];
    await this.writeLock.runExclusive(() =>
      withRetry(
        () =>
          sheets.spreadsheets.values.append({
            spreadsheetId: this.spreadsheetId,
            range: `${a1Tab(TAB_VOTES)}!A:H`,
            valueInputOption: 'RAW',
            insertDataOption: 'INSERT_ROWS',
            requestBody: { values: [row] },
          }),
        'appendVote',
      ),
    );
  }

  async getVotes(): Promise<VoteRow[]> {
    const sheets = await this.client();
    const res = await withRetry(
      () =>
        sheets.spreadsheets.values.get({
          spreadsheetId: this.spreadsheetId,
          range: `${a1Tab(TAB_VOTES)}!A2:H`,
          valueRenderOption: 'UNFORMATTED_VALUE',
        }),
      'getVotes',
    );
    const rows = (res.data.values ?? []) as unknown[][];
    const out: VoteRow[] = [];
    for (const r of rows) {
      const vote_id = str(r[0]);
      if (!vote_id) continue; // skip blank rows
      out.push({
        vote_id,
        ts_iso: str(r[1]),
        voter: str(r[2]),
        product_key: str(r[3]),
        row_anchor: int(r[4]),
        vote: str(r[5]) as VoteRow['vote'],
        undo_target: str(r[6]),
        session_id: str(r[7]),
      });
    }
    return out;
  }

  // --- results upsert -----------------------------------------------------
  async upsertResult(r: ResultRow): Promise<void> {
    const sheets = await this.client();
    const values = [
      r.product_key,
      String(r.row_anchor),
      r.title,
      r.price == null ? '' : r.price,
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
    ];

    await this.writeLock.runExclusive(async () => {
      await this.ensureResultsIndex(sheets);
      const existing = this.resultsIndex!.get(r.product_key);

      if (existing) {
        await withRetry(
          () =>
            sheets.spreadsheets.values.update({
              spreadsheetId: this.spreadsheetId,
              range: `${a1Tab(TAB_RESULTS)}!A${existing}:P${existing}`,
              valueInputOption: 'RAW',
              requestBody: { values: [values] },
            }),
          'upsertResult:update',
        );
      } else {
        const res = await withRetry(
          () =>
            sheets.spreadsheets.values.append({
              spreadsheetId: this.spreadsheetId,
              range: `${a1Tab(TAB_RESULTS)}!A:P`,
              valueInputOption: 'RAW',
              insertDataOption: 'INSERT_ROWS',
              requestBody: { values: [values] },
            }),
          'upsertResult:append',
        );
        const rowNum = parseUpdatedRow(res.data.updates?.updatedRange);
        if (rowNum) this.resultsIndex!.set(r.product_key, rowNum);
      }
    });
  }

  private async ensureResultsIndex(sheets: sheets_v4.Sheets): Promise<void> {
    if (this.resultsIndex) return;
    const res = await withRetry(
      () =>
        sheets.spreadsheets.values.get({
          spreadsheetId: this.spreadsheetId,
          range: `${a1Tab(TAB_RESULTS)}!A2:A`,
          valueRenderOption: 'UNFORMATTED_VALUE',
        }),
      'results:index',
    );
    const keys = (res.data.values ?? []) as unknown[][];
    const map = new Map<string, number>();
    keys.forEach((row, i) => {
      const key = str(row[0]);
      if (key) map.set(key, i + 2); // data starts at row 2
    });
    this.resultsIndex = map;
  }

  // --- inline write-back into AID 1 W..Z ---------------------------------
  async writeInline(
    anchor: number,
    net: number,
    verdict: string,
    conflict: boolean,
    breakdown: string,
  ): Promise<void> {
    if (!config.inlineWriteback) return;
    const sheets = await this.client();
    await this.writeLock.runExclusive(() =>
      withRetry(
        () =>
          sheets.spreadsheets.values.update({
            spreadsheetId: this.spreadsheetId,
            range: `${a1Tab(config.productsTab)}!W${anchor}:Z${anchor}`,
            valueInputOption: 'RAW',
            requestBody: { values: [[net, verdict, conflict ? 'YES' : '', breakdown]] },
          }),
        'writeInline',
      ),
    );
  }

  // --- bootstrap ----------------------------------------------------------
  async ensureTabs(): Promise<void> {
    const sheets = await this.client();

    const meta = await withRetry(
      () =>
        sheets.spreadsheets.get({
          spreadsheetId: this.spreadsheetId,
          fields: 'sheets.properties(sheetId,title)',
        }),
      'meta',
    );
    const existing = new Set(
      (meta.data.sheets ?? []).map((s) => s.properties?.title ?? ''),
    );

    // 1) Create any missing managed tabs.
    const wanted = [TAB_VOTERS, TAB_VOTES, TAB_RESULTS];
    const missing = wanted.filter((t) => !existing.has(t));
    if (missing.length > 0) {
      await this.writeLock.runExclusive(() =>
        withRetry(
          () =>
            sheets.spreadsheets.batchUpdate({
              spreadsheetId: this.spreadsheetId,
              requestBody: {
                requests: missing.map((title) => ({ addSheet: { properties: { title } } })),
              },
            }),
          'addSheets',
        ),
      );
    }

    // 2) Ensure header rows exist (write only when row 1 is empty).
    await this.ensureHeader(sheets, TAB_VOTERS, VOTERS_HEADERS);
    await this.ensureHeader(sheets, TAB_VOTES, VOTES_HEADERS);
    await this.ensureHeader(sheets, TAB_RESULTS, RESULTS_HEADERS);

    // 3) Inline write-back headers on AID 1 (W..Z), preserving custom headers.
    if (config.inlineWriteback) {
      await this.ensureInlineHeaders(sheets);
    }
  }

  private async ensureHeader(
    sheets: sheets_v4.Sheets,
    tab: string,
    headers: string[],
  ): Promise<void> {
    const lastCol = colLetter(headers.length);
    const res = await withRetry(
      () =>
        sheets.spreadsheets.values.get({
          spreadsheetId: this.spreadsheetId,
          range: `${a1Tab(tab)}!A1:${lastCol}1`,
          valueRenderOption: 'FORMATTED_VALUE',
        }),
      `header:get:${tab}`,
    );
    const row = (res.data.values?.[0] ?? []) as unknown[];
    const hasHeader = row.some((c) => c != null && String(c).trim() !== '');
    if (hasHeader) return;
    await this.writeLock.runExclusive(() =>
      withRetry(
        () =>
          sheets.spreadsheets.values.update({
            spreadsheetId: this.spreadsheetId,
            range: `${a1Tab(tab)}!A1:${lastCol}1`,
            valueInputOption: 'RAW',
            requestBody: { values: [headers] },
          }),
        `header:set:${tab}`,
      ),
    );
  }

  private async ensureInlineHeaders(sheets: sheets_v4.Sheets): Promise<void> {
    const range = `${a1Tab(config.productsTab)}!W1:Z1`;
    const res = await withRetry(
      () =>
        sheets.spreadsheets.values.get({
          spreadsheetId: this.spreadsheetId,
          range,
          valueRenderOption: 'FORMATTED_VALUE',
        }),
      'inlineHeaders:get',
    );
    const row = (res.data.values?.[0] ?? []) as unknown[];
    const cols = ['W', 'X', 'Y', 'Z'];
    const merged = cols.map((c, i) => {
      const cur = row[i] == null ? '' : String(row[i]).trim();
      return cur !== '' ? cur : INLINE_HEADERS[c];
    });
    // Only write if anything is currently empty (idempotent, preserves custom).
    const needsWrite = cols.some((_, i) => {
      const cur = row[i] == null ? '' : String(row[i]).trim();
      return cur === '';
    });
    if (!needsWrite) return;
    await this.writeLock.runExclusive(() =>
      withRetry(
        () =>
          sheets.spreadsheets.values.update({
            spreadsheetId: this.spreadsheetId,
            range,
            valueInputOption: 'RAW',
            requestBody: { values: [merged] },
          }),
        'inlineHeaders:set',
      ),
    );
  }
}

// --- helpers --------------------------------------------------------------
function str(v: unknown): string {
  return v == null ? '' : String(v).trim();
}

function int(v: unknown): number {
  if (typeof v === 'number') return Math.trunc(v);
  const n = Number.parseInt(String(v ?? '').trim(), 10);
  return Number.isFinite(n) ? n : 0;
}

/** Parse the starting row number from an A1 range like "Results!A5:P5". */
function parseUpdatedRow(range?: string | null): number | null {
  if (!range) return null;
  const afterBang = range.includes('!') ? range.split('!').pop()! : range;
  const m = afterBang.match(/^[A-Z]+(\d+)/);
  return m ? Number.parseInt(m[1], 10) : null;
}

/** 1-based column count -> column letter (1 -> A, 26 -> Z, 27 -> AA). */
function colLetter(n: number): string {
  let s = '';
  while (n > 0) {
    const r = (n - 1) % 26;
    s = String.fromCharCode(65 + r) + s;
    n = Math.floor((n - 1) / 26);
  }
  return s;
}
