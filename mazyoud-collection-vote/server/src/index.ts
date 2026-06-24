import crypto from 'crypto';
import fs from 'fs';
import path from 'path';
import cookieParser from 'cookie-parser';
import cors from 'cors';
import express, { type NextFunction, type Request, type Response } from 'express';
import rateLimit from 'express-rate-limit';
import {
  Aggregator,
  buildResultRow,
  lastVoteIdFor,
  resolveProductVotes,
  resolveVoterVotes,
  tally,
} from './aggregate';
import {
  authenticateVoter,
  clearSessionCookie,
  newSessionId,
  requireAuth,
  setSessionCookie,
  signSession,
} from './auth';
import { config } from './config';
import { bootstrap, createRepo } from './bootstrap';
import { imageProxyHandler } from './imageProxy';
import type { VoteValue } from './config';
import type { Product, ResultRow, VoteRow } from './sheets/types';

// ---------------------------------------------------------------------------
// Wiring
// ---------------------------------------------------------------------------
const repo = createRepo();

// In-memory product index, kept in sync with the repo's cache. The aggregator
// reads product metadata synchronously via the live closure below.
let productList: Product[] = [];
let productByKey = new Map<string, Product>();

async function refreshProducts(force = false): Promise<Product[]> {
  productList = await repo.getProducts(force);
  productByKey = new Map(productList.map((p) => [p.product_key, p]));
  return productList;
}

const aggregator = new Aggregator(repo, (k) => productByKey.get(k));

// ---------------------------------------------------------------------------
// App
// ---------------------------------------------------------------------------
const app = express();
app.set('trust proxy', 1); // behind Caddy — needed for correct client IPs

app.use(express.json({ limit: '256kb' }));
app.use(cookieParser());

const corsOrigins = new Set<string>([config.appOrigin]);
if (config.nodeEnv !== 'production') {
  corsOrigins.add('http://localhost:5173');
  corsOrigins.add('http://localhost:8080');
  corsOrigins.add('http://127.0.0.1:5173');
}
app.use(
  cors({
    origin: (origin, cb) => {
      // Same-origin / curl (no Origin header) is always fine.
      if (!origin || corsOrigins.has(origin)) return cb(null, true);
      return cb(new Error('Not allowed by CORS'));
    },
    credentials: true,
  }),
);

const loginLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  max: 30,
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: 'too_many_attempts' },
});
const voteLimiter = rateLimit({
  windowMs: 60 * 1000,
  max: 240,
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: 'rate_limited' },
});

function asyncH(
  fn: (req: Request, res: Response, next: NextFunction) => Promise<unknown>,
) {
  return (req: Request, res: Response, next: NextFunction) => {
    fn(req, res, next).catch(next);
  };
}

// ---------------------------------------------------------------------------
// Health & config
// ---------------------------------------------------------------------------
app.get('/healthz', (_req, res) => {
  res.json({ ok: true });
});

app.get('/api/config', (_req, res) => {
  res.json({
    showDetails: config.showDetails,
    allowRevote: config.allowRevote,
    appName: 'Mazyoud Collection Vote',
  });
});

// Public: voter names only (for the login dropdown — never expose pins).
app.get(
  '/api/voters',
  asyncH(async (_req, res) => {
    const voters = await repo.getVoters();
    res.json({ voters: voters.filter((v) => v.active).map((v) => v.name) });
  }),
);

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
app.post(
  '/api/login',
  loginLimiter,
  asyncH(async (req, res) => {
    const name = String(req.body?.name ?? '').trim();
    const pin = String(req.body?.pin ?? '').trim();
    if (!name || !pin) {
      res.status(400).json({ error: 'name_and_pin_required' });
      return;
    }
    const voters = await repo.getVoters();
    const voter = authenticateVoter(voters, name, pin);
    if (!voter) {
      res.status(401).json({ error: 'invalid_credentials' });
      return;
    }
    const sid = newSessionId();
    const token = signSession({ voter: voter.name, sid });
    setSessionCookie(res, token);

    const votes = await aggregator.votesCached();
    const votedKeys = [...resolveVoterVotes(votes, voter.name).keys()];
    res.json({ voter: voter.name, votedKeys });
  }),
);

app.post('/api/logout', (_req, res) => {
  clearSessionCookie(res);
  res.json({ ok: true });
});

app.get(
  '/api/me',
  requireAuth,
  asyncH(async (req, res) => {
    res.json({ voter: req.session!.voter });
  }),
);

// ---------------------------------------------------------------------------
// Products
// ---------------------------------------------------------------------------
app.get(
  '/api/products',
  requireAuth,
  asyncH(async (req, res) => {
    const category = typeof req.query.category === 'string' ? req.query.category.trim() : '';
    const products = await refreshProducts(false);
    const votes = await aggregator.votesCached();
    const mine = resolveVoterVotes(votes, req.session!.voter);

    const filtered =
      category && category.toLowerCase() !== 'all'
        ? products.filter((p) => (p.category ?? '') === category)
        : products;

    const items = filtered.map((p) => ({
      product_key: p.product_key,
      row_anchor: p.row_anchor,
      image: p.image_url ? `/img?u=${encodeURIComponent(p.image_url)}` : null,
      price: p.price,
      category: p.category ?? '',
      gender: p.gender ?? '',
      title: p.title ?? '',
      // Extra metadata gated behind SHOW_DETAILS (off by default).
      ...(config.showDetails
        ? { color: p.color ?? '', remark: p.remark ?? '', notes: p.notes ?? '' }
        : {}),
      myVote: mine.get(p.product_key) ?? null,
    }));

    res.json({ products: items, showDetails: config.showDetails });
  }),
);

// Distinct categories (for the optional category picker).
app.get(
  '/api/categories',
  requireAuth,
  asyncH(async (_req, res) => {
    const products = await refreshProducts(false);
    const set = new Set<string>();
    for (const p of products) {
      const c = (p.category ?? '').trim();
      if (c) set.add(c);
    }
    res.json({ categories: [...set].sort((a, b) => a.localeCompare(b)) });
  }),
);

// ---------------------------------------------------------------------------
// Voting (append-only)
// ---------------------------------------------------------------------------
app.post(
  '/api/vote',
  requireAuth,
  voteLimiter,
  asyncH(async (req, res) => {
    const product_key = String(req.body?.product_key ?? '').trim();
    const vote = String(req.body?.vote ?? '').trim();
    const rowAnchorRaw = req.body?.row_anchor;
    const row_anchor = Number.parseInt(String(rowAnchorRaw ?? ''), 10);

    if (!product_key) {
      res.status(400).json({ error: 'product_key_required' });
      return;
    }
    if (vote !== 'keep' && vote !== 'skip' && vote !== 'super') {
      res.status(400).json({ error: 'invalid_vote' });
      return;
    }

    const anchor = Number.isFinite(row_anchor)
      ? row_anchor
      : (productByKey.get(product_key)?.row_anchor ?? 0);

    // Guard against double-voting when re-voting is disabled.
    if (!config.allowRevote) {
      const votes = await aggregator.votesCached();
      const mine = resolveVoterVotes(votes, req.session!.voter);
      if (mine.has(product_key)) {
        res
          .status(409)
          .json({ error: 'already_voted', myVote: mine.get(product_key) });
        return;
      }
    }

    const voteRow: VoteRow = {
      vote_id: crypto.randomUUID(),
      ts_iso: new Date().toISOString(),
      voter: req.session!.voter,
      product_key,
      row_anchor: anchor,
      vote,
      undo_target: '',
      session_id: req.session!.sid,
    };

    await repo.appendVote(voteRow);
    aggregator.invalidateVotes();

    // Immediate aggregate for the response; debounced write-back for hygiene.
    const aggregate = await aggregator.computeResult(product_key, anchor);
    aggregator.schedule(product_key, anchor);

    res.json({ ok: true, vote_id: voteRow.vote_id, aggregate });
  }),
);

// ---------------------------------------------------------------------------
// Undo (append an undo row referencing the last vote)
// ---------------------------------------------------------------------------
app.post(
  '/api/undo',
  requireAuth,
  voteLimiter,
  asyncH(async (req, res) => {
    const targetId = String(req.body?.target_vote_id ?? '').trim();
    const productKeyHint = String(req.body?.product_key ?? '').trim();
    const voter = req.session!.voter;

    const votes = await aggregator.votesCached();

    let target: { vote_id: string; row_anchor: number; product_key: string } | null = null;

    if (targetId) {
      const found = votes.find(
        (v) => v.vote_id === targetId && v.voter === voter,
      );
      if (found) {
        target = {
          vote_id: found.vote_id,
          row_anchor: found.row_anchor,
          product_key: found.product_key,
        };
      }
    }

    if (!target && productKeyHint) {
      const last = lastVoteIdFor(votes, voter, productKeyHint);
      if (last) {
        target = {
          vote_id: last.vote_id,
          row_anchor: last.row_anchor,
          product_key: productKeyHint,
        };
      }
    }

    if (!target) {
      res.status(400).json({ error: 'nothing_to_undo' });
      return;
    }

    const undoRow: VoteRow = {
      vote_id: crypto.randomUUID(),
      ts_iso: new Date().toISOString(),
      voter,
      product_key: target.product_key,
      row_anchor: target.row_anchor,
      vote: 'undo',
      undo_target: target.vote_id,
      session_id: req.session!.sid,
    };

    await repo.appendVote(undoRow);
    aggregator.invalidateVotes();

    const aggregate = await aggregator.computeResult(target.product_key, target.row_anchor);
    aggregator.schedule(target.product_key, target.row_anchor);

    res.json({ ok: true, undone: target.vote_id, product_key: target.product_key, aggregate });
  }),
);

// ---------------------------------------------------------------------------
// Progress & results
// ---------------------------------------------------------------------------
app.get(
  '/api/progress',
  requireAuth,
  asyncH(async (_req, res) => {
    const products = await refreshProducts(false);
    const votes = await aggregator.votesCached();
    const totalProducts = products.length;
    const productKeys = new Set(products.map((p) => p.product_key));

    // Per-voter resolved counts.
    const voters = await repo.getVoters();
    const perVoter = voters
      .filter((v) => v.active)
      .map((v) => {
        const mine = resolveVoterVotes(votes, v.name);
        // Only count votes on products still present in the sheet.
        let voted = 0;
        for (const key of mine.keys()) if (productKeys.has(key)) voted++;
        return { voter: v.name, voted, remaining: Math.max(0, totalProducts - voted) };
      });

    // Fully-decided = products with at least one resolved vote from anyone.
    const fullyDecided = countProductsWithAnyVote(votes, productKeys);

    const leaderboard = [...perVoter]
      .sort((a, b) => b.voted - a.voted)
      .map((p) => ({ voter: p.voter, voted: p.voted }));

    res.json({ totalProducts, perVoter, fullyDecided, leaderboard });
  }),
);

app.get(
  '/api/results',
  requireAuth,
  asyncH(async (_req, res) => {
    const products = await refreshProducts(false);
    const votes = await aggregator.votesCached();
    const rows = products.map((p) => {
      // Compute live so the dashboard is correct even before debounced writes flush.
      const resolved = resolveProductVotes(votes, p.product_key);
      return buildLiveResult(p, resolved);
    });
    rows.sort((a, b) => b.net_score - a.net_score);
    res.json({ results: rows });
  }),
);

// ---------------------------------------------------------------------------
// Admin refresh
// ---------------------------------------------------------------------------
app.post(
  '/api/refresh',
  requireAuth,
  asyncH(async (req, res) => {
    if (config.adminToken) {
      const provided = String(req.header('x-admin-token') ?? '');
      if (provided !== config.adminToken) {
        res.status(403).json({ error: 'forbidden' });
        return;
      }
    }
    const products = await refreshProducts(true);
    res.json({ ok: true, count: products.length });
  }),
);

// ---------------------------------------------------------------------------
// Image proxy
// ---------------------------------------------------------------------------
app.get('/img', asyncH(imageProxyHandler));

// ---------------------------------------------------------------------------
// Static frontend + SPA fallback
// ---------------------------------------------------------------------------
const webDist = process.env.WEB_DIST
  ? path.resolve(process.env.WEB_DIST)
  : path.resolve(__dirname, '..', 'public');

if (fs.existsSync(path.join(webDist, 'index.html'))) {
  app.use(express.static(webDist, { index: false, maxAge: '1h' }));
  app.get('*', (req, res, next) => {
    if (
      req.path.startsWith('/api') ||
      req.path === '/img' ||
      req.path === '/healthz'
    ) {
      return next();
    }
    res.sendFile(path.join(webDist, 'index.html'));
  });
  console.log(`[static] serving web build from ${webDist}`);
} else {
  console.log(`[static] no web build at ${webDist} — running API only`);
}

// Error handler.
app.use((err: unknown, _req: Request, res: Response, _next: NextFunction) => {
  const message = (err as { message?: string })?.message ?? 'internal_error';
  if (message === 'Not allowed by CORS') {
    res.status(403).json({ error: 'cors' });
    return;
  }
  console.error('[error]', err);
  res.status(500).json({ error: 'internal_error' });
});

// ---------------------------------------------------------------------------
// Start
// ---------------------------------------------------------------------------
async function start(): Promise<void> {
  try {
    await bootstrap(repo);
    await refreshProducts(true);
  } catch (err) {
    console.error('[startup] bootstrap failed:', err);
    // Keep serving — /healthz stays up; product calls will retry against Sheets.
  }

  app.listen(config.port, () => {
    console.log(`[server] listening on :${config.port} (origin ${config.appOrigin})`);
  });
}

// Flush pending aggregate writes on shutdown.
for (const sig of ['SIGTERM', 'SIGINT'] as const) {
  process.on(sig, async () => {
    console.log(`[server] ${sig} — flushing aggregates...`);
    try {
      await aggregator.flushAll();
    } catch {
      /* ignore */
    }
    process.exit(0);
  });
}

void start();

// ---------------------------------------------------------------------------
// Local helpers (live aggregation for read endpoints)
// ---------------------------------------------------------------------------
function buildLiveResult(p: Product, resolved: Map<string, VoteValue>): ResultRow {
  const t = tally(resolved);
  return buildResultRow(p, p.product_key, p.row_anchor, t, new Date().toISOString());
}

function countProductsWithAnyVote(votes: VoteRow[], productKeys: Set<string>): number {
  let n = 0;
  for (const key of productKeys) {
    const resolved = resolveProductVotes(votes, key);
    if (resolved.size > 0) n++;
  }
  return n;
}
