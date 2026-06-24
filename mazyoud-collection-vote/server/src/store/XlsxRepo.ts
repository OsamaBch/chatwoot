import type { SheetsRepo } from '../sheets/SheetsRepo';
import type { Product, ResultRow, Voter, VoteRow } from '../sheets/types';
import { exportWorkbook, parseWorkbook } from '../xlsx/workbook';
import { FileStore, type WorkbookMeta } from './fileStore';

export interface IngestResult {
  productCount: number;
  sheetName: string;
  categories: string[];
}

/**
 * Default backend: products come from an uploaded .xlsx, votes/voters live in
 * the local FileStore. Conforms to SheetsRepo (so the voting + aggregation
 * paths are unchanged) and adds admin-only ingest/export/voter operations.
 */
export class XlsxRepo implements SheetsRepo {
  private products: Product[] = [];
  private sheetName = '';
  readonly store: FileStore;

  constructor(store = new FileStore()) {
    this.store = store;
  }

  // --- SheetsRepo ---------------------------------------------------------
  async getProducts(): Promise<Product[]> {
    return this.products;
  }

  async getVoters(): Promise<Voter[]> {
    return this.store.getVoters();
  }

  async appendVote(v: VoteRow): Promise<void> {
    this.store.appendVote(v);
  }

  async getVotes(): Promise<VoteRow[]> {
    return this.store.getVotes();
  }

  // Results are computed live for the dashboard and written into the workbook
  // at export time, so these are no-ops in this backend.
  async upsertResult(_r: ResultRow): Promise<void> {
    /* no-op */
  }

  async writeInline(): Promise<void> {
    /* no-op */
  }

  /** Bootstrap: open the store and re-parse any previously-uploaded workbook. */
  async ensureTabs(): Promise<void> {
    this.store.init();
    const buf = this.store.readWorkbook();
    if (buf) {
      try {
        const { products, sheetName } = await parseWorkbook(
          buf,
          this.store.getMeta()?.sheetName,
        );
        this.products = products;
        this.sheetName = sheetName;
      } catch (err) {
        console.error('[xlsx] failed to parse saved workbook:', (err as Error)?.message ?? err);
      }
    }
  }

  // --- admin extras -------------------------------------------------------
  /** Parse + persist a freshly uploaded workbook; returns a short summary. */
  async ingest(buffer: Buffer, originalName: string): Promise<IngestResult> {
    const { products, sheetName } = await parseWorkbook(buffer);
    this.products = products;
    this.sheetName = sheetName;
    this.store.saveWorkbook(buffer);
    const meta: WorkbookMeta = {
      sheetName,
      originalName,
      uploadedAt: new Date().toISOString(),
      productCount: products.length,
    };
    this.store.setMeta(meta);
    return { productCount: products.length, sheetName, categories: distinctCategories(products) };
  }

  /** Build the results-filled .xlsx from the original workbook. */
  async exportBuffer(results: ResultRow[]): Promise<Buffer> {
    const orig = this.store.readWorkbook();
    if (!orig) throw new Error('no_workbook');
    return exportWorkbook(orig, this.sheetName, results);
  }

  getMeta(): WorkbookMeta | null {
    return this.store.getMeta();
  }

  hasWorkbook(): boolean {
    return this.products.length > 0 || this.store.hasWorkbook();
  }

  upsertVoter(voter: Voter): void {
    this.store.upsertVoter(voter);
  }

  deleteVoter(name: string): void {
    this.store.deleteVoter(name);
  }
}

function distinctCategories(products: Product[]): string[] {
  const set = new Set<string>();
  for (const p of products) {
    const c = (p.category ?? '').trim();
    if (c) set.add(c);
  }
  return [...set].sort((a, b) => a.localeCompare(b));
}
