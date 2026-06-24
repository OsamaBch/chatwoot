/**
 * Central configuration for the Mazyoud Collection Vote backend.
 *
 * Everything tunable lives here: env-derived settings, voting weights,
 * verdict thresholds, sheet layout constants and tab names. Tweak the
 * constants below to retune behaviour without touching business logic.
 */
import 'dotenv/config';

// ---------------------------------------------------------------------------
// Small env helpers
// ---------------------------------------------------------------------------
function env(key: string, fallback = ''): string {
  const v = process.env[key];
  return v === undefined || v === '' ? fallback : v;
}

function envInt(key: string, fallback: number): number {
  const v = process.env[key];
  if (v === undefined || v === '') return fallback;
  const n = Number.parseInt(v, 10);
  return Number.isFinite(n) ? n : fallback;
}

function envBool(key: string, fallback: boolean): boolean {
  const v = process.env[key];
  if (v === undefined || v === '') return fallback;
  return /^(1|true|yes|on)$/i.test(v.trim());
}

function envList(key: string, fallback: string[] = []): string[] {
  const v = process.env[key];
  if (v === undefined || v === '') return fallback;
  return v
    .split(',')
    .map((s) => s.trim().toLowerCase())
    .filter(Boolean);
}

// ---------------------------------------------------------------------------
// Runtime config (env-driven)
// ---------------------------------------------------------------------------
export const config = {
  // --- Google Sheet ---
  spreadsheetId: env('SPREADSHEET_ID'),
  productsTab: env('SHEET_PRODUCTS_TAB', 'AID 1'),

  // Service account: base64-encoded JSON in GOOGLE_SERVICE_ACCOUNT_JSON,
  // or a file path in GOOGLE_APPLICATION_CREDENTIALS (standard googleapis var).
  serviceAccountJsonB64: env('GOOGLE_SERVICE_ACCOUNT_JSON'),
  googleApplicationCredentials: env('GOOGLE_APPLICATION_CREDENTIALS'),

  // --- Backend selection ---
  // xlsx  = upload a workbook, store votes locally (default)
  // google = read/write the live Google Sheet
  // n8n   = webhook drop-in    demo = in-memory preview
  dataBackend: env('DATA_BACKEND', 'xlsx') as 'xlsx' | 'google' | 'n8n' | 'demo',

  // --- Local store (xlsx backend) ---
  // Directory for the append-only vote log, voters, meta, and the uploaded
  // workbook. Mount this as a volume so data survives container restarts.
  dataDir: env('DATA_DIR', 'data'),

  // Admin dashboard password (upload sheet / manage voters / export results).
  // Required to use the admin area; leave empty to disable it.
  adminPassword: env('ADMIN_PASSWORD', ''),

  // --- Sessions / auth ---
  sessionSecret: env('SESSION_SECRET', 'change-me-in-production'),
  sessionTtlSeconds: envInt('SESSION_TTL', 43200), // 12h
  hashPins: envBool('HASH_PINS', false),

  // --- HTTP ---
  port: envInt('PORT', 8080),
  appOrigin: env('APP_ORIGIN', 'https://vote.mazyoud.com'),
  nodeEnv: env('NODE_ENV', 'production'),

  // --- Images ---
  imgAllowedHosts: envList('IMG_ALLOWED_HOSTS', [
    'cbu01.alicdn.com',
    '.alicdn.com',
    '.1688.com',
  ]),
  imgCacheMaxEntries: envInt('IMG_CACHE_MAX_ENTRIES', 500),
  imgCacheMaxBytes: envInt('IMG_CACHE_MAX_BYTES', 256 * 1024 * 1024), // 256MB
  imgFetchTimeoutMs: envInt('IMG_FETCH_TIMEOUT_MS', 12000),

  // --- Feature flags ---
  inlineWriteback: envBool('INLINE_WRITEBACK', true),
  showDetails: envBool('SHOW_DETAILS', false),
  allowRevote: envBool('ALLOW_REVOTE', false),

  // --- Caching ---
  productsCacheTtlMs: envInt('PRODUCTS_CACHE_TTL', 5 * 60 * 1000), // 5 min

  // --- n8n backend (only used when DATA_BACKEND=n8n) ---
  n8nProductsUrl: env('N8N_PRODUCTS_URL'),
  n8nAppendVoteUrl: env('N8N_APPEND_VOTE_URL'),
  n8nVotesUrl: env('N8N_VOTES_URL'),

  // --- Admin ---
  // Optional shared secret to gate POST /api/refresh. If empty, refresh is
  // allowed for any authenticated voter.
  adminToken: env('ADMIN_TOKEN'),
};

// ---------------------------------------------------------------------------
// Voting weights & verdict thresholds (business rules)
// ---------------------------------------------------------------------------
export const WEIGHT_KEEP = 1;
export const WEIGHT_SUPER = 2;
export const WEIGHT_SKIP = -1;

export const BUY_THRESHOLD = 2; // net_score >= this -> BUY (if no conflict)
export const SKIP_THRESHOLD = -1; // net_score <= this -> SKIP
export const CONFLICT_MIN_SHARE = 0.34; // minority share -> CONFLICT

// ---------------------------------------------------------------------------
// Aggregation write hygiene
// ---------------------------------------------------------------------------
export const AGGREGATE_DEBOUNCE_MS = 2000; // coalesce per-product recompute bursts
export const SHEETS_MAX_RETRIES = 6; // retries on 429/5xx
export const SHEETS_BACKOFF_BASE_MS = 500; // exponential backoff base

// ---------------------------------------------------------------------------
// Managed tab names
// ---------------------------------------------------------------------------
export const TAB_VOTERS = 'Voters';
export const TAB_VOTES = 'Votes';
export const TAB_RESULTS = 'Results';

// ---------------------------------------------------------------------------
// Header rows for managed tabs (order matters — used for read + write)
// ---------------------------------------------------------------------------
export const VOTERS_HEADERS = ['name', 'pin', 'active'];

export const VOTES_HEADERS = [
  'vote_id',
  'ts_iso',
  'voter',
  'product_key',
  'row_anchor',
  'vote',
  'undo_target',
  'session_id',
];

export const RESULTS_HEADERS = [
  'product_key',
  'row_anchor',
  'title',
  'price',
  'category',
  'gender',
  'image_url',
  'keep',
  'skip',
  'super',
  'voters',
  'net_score',
  'verdict',
  'conflict',
  'hero',
  'last_updated',
];

// ---------------------------------------------------------------------------
// AID 1 (source) column layout. 0-based indices into a row array.
// Read range is A:S so we never touch the protected U/V named-range columns.
// Write-back (when INLINE_WRITEBACK) targets W..Z only, on anchor rows only.
// ---------------------------------------------------------------------------
export const COL = {
  INDEX: 0, // A
  IMAGE: 1, // B  =IMAGE("...")
  PRICE: 2, // C  Selling Price DZD (cached numeric value)
  COLOR: 3, // D
  REMARK: 4, // E
  // F..L (5..11) size/qty/cost -> ignored
  PRODUCT_LINK: 12, // M  1688 offer URL -> stable product key
  SUPPLIER: 13, // N
  SUPPLIER_LINK: 14, // O
  CATEGORY: 15, // P
  GENDER: 16, // Q
  NOTES: 17, // R
  TITLE: 18, // S
  // U/V (20/21) -> DO NOT TOUCH
} as const;

export const READ_RANGE_LAST_COL = 'S'; // inclusive read bound for AID 1

// Inline write-back columns (A=0): W=22, X=23, Y=24, Z=25
export const INLINE_COL = {
  NET: 'W',
  VERDICT: 'X',
  CONFLICT: 'Y',
  BREAKDOWN: 'Z',
} as const;

export const INLINE_HEADERS: Record<string, string> = {
  W: 'Votes Net',
  X: 'Verdict',
  Y: 'Conflict',
  Z: 'Voter Breakdown',
};

export type Verdict = 'BUY' | 'SKIP' | 'REVIEW';
export type VoteValue = 'keep' | 'skip' | 'super';
export type VoteRowValue = VoteValue | 'undo';

/** Quote a tab name for use inside an A1 range (handles spaces, e.g. "AID 1"). */
export function a1Tab(tab: string): string {
  return `'${tab.replace(/'/g, "''")}'`;
}
