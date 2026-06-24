import fs from 'fs';
import path from 'path';
import { config } from '../config';
import type { Voter, VoteRow } from '../sheets/types';

export interface WorkbookMeta {
  sheetName: string;
  originalName: string;
  uploadedAt: string;
  productCount: number;
}

/**
 * Durable, dependency-free local store for the xlsx backend.
 *
 *  - votes.jsonl   append-only vote log (the concurrency-safe store, mirrors
 *                  the original "append-only Votes tab" design)
 *  - voters.json   admin-managed login list
 *  - meta.json     info about the currently-loaded workbook
 *  - workbook.xlsx the uploaded source workbook (re-parsed on restart, and
 *                  re-opened on export so results are baked into the same file)
 *
 * Everything lives under DATA_DIR — mount it as a volume to persist across
 * container restarts. Votes are kept in memory after load; appends write
 * through to disk synchronously (atomic within Node's single-threaded loop).
 */
export class FileStore {
  private dir: string;
  private votes: VoteRow[] = [];
  private voters: Voter[] = [];
  private meta: WorkbookMeta | null = null;

  constructor(dir = config.dataDir) {
    this.dir = path.resolve(dir);
  }

  private p(name: string): string {
    return path.join(this.dir, name);
  }

  init(): void {
    fs.mkdirSync(this.dir, { recursive: true });
    this.loadVoters();
    this.loadVotes();
    this.loadMeta();
    console.log(
      `[store] data dir ${this.dir} — ${this.voters.length} voters, ${this.votes.length} votes` +
        (this.meta ? `, workbook "${this.meta.originalName}" (${this.meta.productCount} products)` : ', no workbook'),
    );
  }

  // --- voters -------------------------------------------------------------
  private loadVoters(): void {
    try {
      const raw = fs.readFileSync(this.p('voters.json'), 'utf8');
      const arr = JSON.parse(raw);
      this.voters = Array.isArray(arr)
        ? arr.map((v) => ({
            name: String(v.name ?? '').trim(),
            pin: String(v.pin ?? '').trim(),
            active: v.active !== false,
          }))
        : [];
    } catch {
      this.voters = [];
    }
  }

  private persistVoters(): void {
    this.writeAtomic('voters.json', JSON.stringify(this.voters, null, 2));
  }

  getVoters(): Voter[] {
    return this.voters.map((v) => ({ ...v }));
  }

  upsertVoter(voter: Voter): void {
    const idx = this.voters.findIndex(
      (v) => v.name.trim().toLowerCase() === voter.name.trim().toLowerCase(),
    );
    const clean: Voter = {
      name: voter.name.trim(),
      pin: voter.pin.trim(),
      active: voter.active,
    };
    if (idx >= 0) this.voters[idx] = clean;
    else this.voters.push(clean);
    this.persistVoters();
  }

  deleteVoter(name: string): void {
    this.voters = this.voters.filter(
      (v) => v.name.trim().toLowerCase() !== name.trim().toLowerCase(),
    );
    this.persistVoters();
  }

  // --- votes (append-only) -----------------------------------------------
  private loadVotes(): void {
    this.votes = [];
    let raw = '';
    try {
      raw = fs.readFileSync(this.p('votes.jsonl'), 'utf8');
    } catch {
      return;
    }
    for (const line of raw.split('\n')) {
      const t = line.trim();
      if (!t) continue;
      try {
        const v = JSON.parse(t) as VoteRow;
        if (v && v.vote_id) this.votes.push(v);
      } catch {
        /* skip malformed line */
      }
    }
  }

  appendVote(v: VoteRow): void {
    this.votes.push(v);
    fs.appendFileSync(this.p('votes.jsonl'), JSON.stringify(v) + '\n');
  }

  getVotes(): VoteRow[] {
    return this.votes.slice();
  }

  // --- meta ---------------------------------------------------------------
  private loadMeta(): void {
    try {
      this.meta = JSON.parse(fs.readFileSync(this.p('meta.json'), 'utf8'));
    } catch {
      this.meta = null;
    }
  }

  getMeta(): WorkbookMeta | null {
    return this.meta ? { ...this.meta } : null;
  }

  setMeta(meta: WorkbookMeta): void {
    this.meta = meta;
    this.writeAtomic('meta.json', JSON.stringify(meta, null, 2));
  }

  // --- workbook bytes -----------------------------------------------------
  saveWorkbook(buffer: Buffer): void {
    fs.writeFileSync(this.p('workbook.xlsx'), buffer);
  }

  readWorkbook(): Buffer | null {
    try {
      return fs.readFileSync(this.p('workbook.xlsx'));
    } catch {
      return null;
    }
  }

  hasWorkbook(): boolean {
    return fs.existsSync(this.p('workbook.xlsx'));
  }

  // --- util ---------------------------------------------------------------
  private writeAtomic(name: string, content: string): void {
    const target = this.p(name);
    const tmp = `${target}.tmp`;
    fs.writeFileSync(tmp, content);
    fs.renameSync(tmp, target);
  }
}
