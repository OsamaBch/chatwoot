# Mazyoud Collection Vote

A mobile-first, Tinder-style web app for the buying team to vote on products
straight from the **Mazyoud OMS buying sheet**. Swipe **right = keep**,
**left = skip**, **up = super-like**. Votes are written back into the same
Google Sheet (append-only, so several people can vote on the same products at
once), and the app rebuilds a ranked verdict per product.

- **Card shows only the product image and the selling price** — nothing else
  (extra metadata is behind an off-by-default flag).
- **No fixed product count.** Anchor rows are detected dynamically, so the same
  build works on a 14-product sheet and a 377-product sheet.
- **One container.** The Node/Express backend serves the API *and* the built
  React PWA.

---

## Table of contents

1. [Architecture](#architecture)
2. [How the sheet is used](#how-the-sheet-is-used)
3. [Google service account setup](#google-service-account-setup)
4. [The Voters tab](#the-voters-tab)
5. [Configuration](#configuration)
6. [Local development](#local-development)
7. [Deploy with Docker + Caddy](#deploy-with-docker--caddy)
8. [API reference](#api-reference)
9. [PIN hashing (optional)](#pin-hashing-optional)
10. [n8n backend (optional)](#n8n-backend-optional)
11. [Acceptance criteria](#acceptance-criteria)
12. [Troubleshooting](#troubleshooting)
13. [Extension points](#extension-points)

---

## Architecture

```
[ React PWA on phones ] --HTTPS--> [ Express backend (single container) ] --googleapis--> [ Google Sheet ]
        swipe UI                       API + static frontend                  AID 1 / Voters / Votes / Results
```

All Google access sits behind a `SheetsRepo` interface so the storage backend
can be swapped (`DATA_BACKEND=google` direct, or `n8n` webhooks).

```
mazyoud-collection-vote/
  server/   Express + TypeScript API, serves the built web app
    src/
      config.ts            all constants + env (tune here)
      index.ts             API routes + static + /healthz
      auth.ts              login, JWT cookie, PIN check
      aggregate.ts         vote resolution + verdict rules + debounced write-back
      imageProxy.ts        allow-listed image proxy + LRU cache
      bootstrap.ts         repo selection + ensureTabs + cache warm-up
      selftest.ts          pure-logic checks (npm test)
      sheets/
        types.ts SheetsRepo.ts GoogleSheetsRepo.ts N8nWebhookRepo.ts parse.ts util.ts
  web/      Vite + React + TS + Tailwind PWA
  Dockerfile docker-compose.yml Caddyfile.snippet .env.example
```

---

## How the sheet is used

### Source product tab — `AID 1` (read-only for products)

Products are laid out in **10-row blocks**. The first row of each block (the
**anchor row**) holds the product fields; the other 9 rows hold per-size
quantities and are blank in the product columns.

The app scans **column B** top-to-bottom; **every row where B is non-empty is a
product anchor**. Columns read (A–S only, so the protected U/V named-range
columns are never touched):

| Col | Field | How it's read |
| --- | --- | --- |
| B | `=IMAGE("url")` | rendered as **FORMULA**, URL extracted with `IMAGE\("([^"]+)"\)` (also tolerates single quotes / extra args) |
| C | Selling Price DZD | rendered as **UNFORMATTED_VALUE** (cached number, e.g. `3950`); falls back to the formatted value, else `null` |
| M | Product Link | trimmed → the stable **`product_key`** (falls back to `row-<anchorRow>` if empty) |
| D,E,P,Q,R,S,N,O | color / remark / category / gender / notes / title / supplier(s) | optional; only category & title are surfaced (and title only in Results / behind `SHOW_DETAILS`) |

> **Columns U–V are never written.** Inline write-back (below) only ever uses
> W–Z, on anchor rows.

### Tabs the app creates and manages

On startup the app bootstraps these tabs (with header rows) if missing:

- **Voters** — the login list you edit by hand: `name | pin | active`
- **Votes** — **append-only** raw log (this is what makes concurrent voting
  safe): `vote_id | ts_iso | voter | product_key | row_anchor | vote | undo_target | session_id`
  where `vote ∈ keep | skip | super | undo`. **Rows are only ever appended,
  never updated or deleted.**
- **Results** — one row per product, rebuilt by the aggregator:
  `product_key | row_anchor | title | price | category | gender | image_url | keep | skip | super | voters | net_score | verdict | conflict | hero | last_updated`

### Optional inline write-back to `AID 1`

With `INLINE_WRITEBACK=true` (default) the aggregator also mirrors, **only on
anchor rows**, into the free columns:

| W | X | Y | Z |
| --- | --- | --- | --- |
| Votes Net | Verdict | Conflict | Voter Breakdown (`keep:3 skip:1 super:1`) |

---

## Google service account setup

1. In the [Google Cloud Console](https://console.cloud.google.com/), create (or
   pick) a project and **enable the Google Sheets API**.
2. Create a **Service Account**, then create a **JSON key** for it and download
   it (e.g. `service-account.json`).
3. Copy the service account's email (looks like
   `something@your-project.iam.gserviceaccount.com`).
4. Open the Mazyoud spreadsheet → **Share** → add that email as **Editor**.
   (Editor is required because the app creates tabs and writes votes/results.)
5. Provide the key to the app one of two ways:
   - **Base64 (recommended for Docker):**
     ```bash
     base64 -w0 service-account.json    # Linux
     base64 -i service-account.json     # macOS
     ```
     Put the output in `GOOGLE_SERVICE_ACCOUNT_JSON`.
   - **File path:** mount the JSON into the container and set
     `GOOGLE_APPLICATION_CREDENTIALS=/path/to/service-account.json`.
6. Grab the spreadsheet id from its URL
   (`/spreadsheets/d/<SPREADSHEET_ID>/edit`) → `SPREADSHEET_ID`.

---

## The Voters tab

Add one row per voter under the header `name | pin | active`:

| name | pin | active |
| --- | --- | --- |
| Sara | 1234 | TRUE |
| Karim | 4242 | TRUE |
| Old Account | 0000 | FALSE |

- `active` may be `TRUE/FALSE`, `1/0`, `yes/no`, or blank (blank = active).
- **Format the `pin` column as plain text** if you use leading zeros (e.g.
  `0123`), so the sheet doesn't store it as the number `123`.
- v1 compares PINs as plain text (acceptable for a private internal tool on a
  private sheet). See [PIN hashing](#pin-hashing-optional) to switch to salted
  hashes.

---

## Configuration

Copy `.env.example` to `.env` and fill it in. Key settings:

| Key | Default | Meaning |
| --- | --- | --- |
| `SPREADSHEET_ID` | — | the spreadsheet id |
| `SHEET_PRODUCTS_TAB` | `AID 1` | source product tab name |
| `GOOGLE_SERVICE_ACCOUNT_JSON` | — | base64 of the SA key JSON |
| `GOOGLE_APPLICATION_CREDENTIALS` | — | …or a path to the key JSON |
| `DATA_BACKEND` | `google` | `google` (direct) or `n8n` (webhooks) |
| `SESSION_SECRET` | — | JWT signing secret (`openssl rand -hex 32`) |
| `SESSION_TTL` | `43200` | session lifetime (seconds, 12h) |
| `APP_ORIGIN` | `https://vote.mazyoud.com` | CORS is locked to this |
| `PORT` | `8080` | listen port |
| `IMG_ALLOWED_HOSTS` | `cbu01.alicdn.com,.alicdn.com,.1688.com` | image-proxy allowlist (dot-prefixed = subdomains). **Add your Backblaze B2 host.** |
| `INLINE_WRITEBACK` | `true` | mirror net/verdict/conflict/breakdown into `AID 1` W–Z |
| `SHOW_DETAILS` | `false` | show extra metadata on the card (off = image + price only) |
| `ALLOW_REVOTE` | `false` | let a voter swipe products they already voted on |
| `HASH_PINS` | `false` | compare PINs as salted-sha256 instead of plain |
| `PRODUCTS_CACHE_TTL` | `300000` | product cache TTL (ms); refresh via `POST /api/refresh` |
| `ADMIN_TOKEN` | — | optional secret to gate `POST /api/refresh` |
| `N8N_*_URL` | — | only when `DATA_BACKEND=n8n` |

Voting weights and verdict thresholds live at the top of
[`server/src/config.ts`](server/src/config.ts):

```ts
WEIGHT_KEEP = 1   WEIGHT_SUPER = 2   WEIGHT_SKIP = -1
BUY_THRESHOLD = 2        // net_score >= this -> BUY (if no conflict)
SKIP_THRESHOLD = -1      // net_score <= this -> SKIP
CONFLICT_MIN_SHARE = 0.34 // minority side >= this share of voters -> CONFLICT
```

---

## Local development

Two terminals (Node 20+):

```bash
# 1) backend on :8080
cd server
npm install
cp ../.env.example .env   # fill in SPREADSHEET_ID + credentials + SESSION_SECRET
npm run dev

# 2) frontend on :5173 (proxies /api, /img, /healthz to :8080)
cd web
npm install
npm run dev
```

Open http://localhost:5173. Run the logic checks any time:

```bash
cd server && npm test
```

---

## Deploy with Docker + Caddy

The repo builds into **one image**: the multi-stage `Dockerfile` builds the web
app, compiles the server, and ships a slim runtime that serves both.

```bash
cd mazyoud-collection-vote
cp .env.example .env        # fill it in (production values)

# Caddy reaches the container by name over a shared docker network:
docker network create caddy   # once, if it doesn't exist

docker compose up -d --build
docker compose logs -f        # watch bootstrap + listen
```

Add the [`Caddyfile.snippet`](Caddyfile.snippet) to your existing Caddyfile and
reload Caddy. Point `vote.mazyoud.com` DNS at the box; Caddy gets TLS
automatically and reverse-proxies to `mazyoud-vote:8080`.

> For a quick local container test without Caddy, uncomment the `ports:` block
> in `docker-compose.yml` and hit `http://localhost:8080`.

Health check: `GET /healthz → { "ok": true }` (also wired into the container
`HEALTHCHECK`).

---

## API reference

All JSON. Auth is a signed JWT in an `httpOnly`, `SameSite=Lax` cookie. CORS is
locked to `APP_ORIGIN`. `/api/login` and `/api/vote` are rate-limited per IP.

| Method & path | Body | Returns |
| --- | --- | --- |
| `GET /healthz` | — | `{ ok: true }` |
| `GET /api/config` | — | `{ showDetails, allowRevote, appName }` |
| `GET /api/voters` | — | `{ voters: string[] }` (active names only) |
| `POST /api/login` | `{ name, pin }` | sets cookie → `{ voter, votedKeys }` |
| `POST /api/logout` | — | `{ ok: true }` |
| `GET /api/me` | — | `{ voter }` |
| `GET /api/categories` | — | `{ categories: string[] }` |
| `GET /api/products?category=` | — | `{ products: [{ product_key, row_anchor, image, price, category, gender, title, myVote }], showDetails }` |
| `POST /api/vote` | `{ product_key, row_anchor, vote }` | `{ ok, vote_id, aggregate }` |
| `POST /api/undo` | `{ target_vote_id }` *or* `{ product_key }` | `{ ok, undone, product_key, aggregate }` |
| `GET /api/progress` | — | `{ totalProducts, perVoter, fullyDecided, leaderboard }` |
| `GET /api/results` | — | `{ results: ResultRow[] }` (net_score desc) |
| `POST /api/refresh` | — (`x-admin-token` if `ADMIN_TOKEN` set) | `{ ok, count }` |
| `GET /img?u=<url>` | — | streamed image (allow-listed hosts only) |

`image` is the proxied path `/img?u=<encoded>`. `myVote` reflects **this**
voter's current net vote so the UI can resume and show history.

### Why this is concurrency-safe

Every swipe is a single `spreadsheets.values.append` to **Votes** (atomic on
Google's side), so two phones voting the same product produce two separate rows
— no overwrite, no race. Undo appends an `undo` row referencing the original
`vote_id`; the aggregator nets it out. Aggregation resolves, per `(voter,
product_key)`, the **most recent non-undone** `keep|skip|super` row.

All **writes** are serialized through an in-process mutex and the Results/inline
write-back is **debounced ~2s per product**, with exponential backoff + retry on
HTTP 429. The append of the raw vote itself is immediate.

---

## PIN hashing (optional)

Set `HASH_PINS=true` and store each PIN in the sheet as `salt$sha256hex`, where
`sha256hex = sha256(salt + pin)`. Generate one:

```bash
node -e 'const c=require("crypto");const pin="1234";const salt=c.randomBytes(6).toString("hex");console.log(salt+"$"+c.createHash("sha256").update(salt+pin).digest("hex"))'
```

Paste the output into the voter's `pin` cell.

---

## n8n backend (optional)

Set `DATA_BACKEND=n8n` to route through your existing n8n webhooks instead of
the Sheets API:

- `N8N_PRODUCTS_URL` — returns the product list
- `N8N_APPEND_VOTE_URL` — receives a vote row to append
- `N8N_VOTES_URL` — returns the raw votes log

This is a documented drop-in; the direct Google backend is the default and the
fully-featured path (aggregation write-back and tab bootstrap are assumed to be
owned by your n8n flows in this mode; `getVoters` needs its own webhook).

---

## Acceptance criteria

- ✅ Loads `AID 1`, shows every product as a card with **only image + price**.
- ✅ Swipe right/left/up and keys → ← ↑ register keep/skip/super; **Backspace/Z** undo.
- ✅ Two voters on two phones voting the same product → two rows in **Votes**,
  neither overwritten.
- ✅ Undo removes the last vote from the tally.
- ✅ Logout/re-login resumes at the first un-voted product for that voter.
- ✅ **Results** ranks by `net_score` with BUY/REVIEW/SKIP, ⭐ hero (any
  super-like), ⚠ conflict; `INLINE_WRITEBACK` mirrors net/verdict/conflict/
  breakdown into `AID 1` W–Z on anchor rows only.
- ✅ Live progress shows per-voter counts + overall fully-decided count.
- ✅ Works unchanged on a 377-product sheet (dynamic anchors + cache) — covered
  by `npm test`.
- ✅ Survives Sheets API 429 via backoff; **never writes to U/V**.
- ✅ Builds into one container, runs behind Caddy at `vote.mazyoud.com`.

---

## Troubleshooting

- **`bootstrap failed: SPREADSHEET_ID is not set`** — fill `.env`. The server
  still starts and serves `/healthz`; it retries Sheets on the next request.
- **Images don't load / 403 from `/img`** — the upstream host isn't in
  `IMG_ALLOWED_HOSTS`. Add it (dot-prefixed entries match subdomains).
- **403 on every API call from the browser** — `APP_ORIGIN` must exactly match
  the site origin (scheme + host), since CORS is locked to it.
- **Login always fails** — check the `Voters` tab `active` flag and that the
  service account has **Editor** access. If PINs have leading zeros, format the
  `pin` column as plain text.
- **429s in logs** — expected under heavy load; the client backs off and
  retries automatically. Increase `AGGREGATE_DEBOUNCE_MS` in `config.ts` to
  coalesce more aggressively.

---

## Extension points (non-goals for v1)

- **AI scoring** — handled separately; `Results` is the integration surface.
- **Identical-colourway dedup** — not done yet. `product_key` (column M) is the
  natural grouping key to build this on.
- **Product editing** — the app only reads products and writes votes/results.
