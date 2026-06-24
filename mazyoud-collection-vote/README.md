# Mazyoud Collection Vote

A mobile-first, Tinder-style web app for the buying team to vote on products.
Swipe **right = keep**, **left = skip**, **up = super-like**. An admin uploads
the buying **`.xlsx`**, the team votes from their phones, and the admin sees a
live dashboard and **exports the same workbook with results baked in**.

- **No Google account needed.** Upload a workbook, vote, export. Everything runs
  in one container with a local data volume. (A live Google Sheets backend is
  still available as an option — see [below](#optional-google-sheets-backend).)
- **Card shows only the product image and the selling price** — extra metadata
  is behind an off-by-default flag.
- **No fixed product count.** Product rows are detected dynamically, so the same
  build works on a 14-product sheet and a 377-product sheet.

---

## Contents

1. [How it works](#how-it-works)
2. [Quick start (Docker)](#quick-start-docker)
3. [Local development](#local-development)
4. [The admin dashboard](#the-admin-dashboard)
5. [The .xlsx layout it expects](#the-xlsx-layout-it-expects)
6. [Configuration](#configuration)
7. [Backends](#backends)
8. [API reference](#api-reference)
9. [PIN hashing](#pin-hashing)
10. [Optional: Google Sheets backend](#optional-google-sheets-backend)
11. [Acceptance criteria](#acceptance-criteria)
12. [Troubleshooting](#troubleshooting)
13. [Extension points](#extension-points)

---

## How it works

```
[ React PWA on phones ] --HTTPS--> [ Express backend (single container) ]
        swipe UI                       API + static frontend
                                              |
                                  ┌───────────┴───────────┐
                              parse .xlsx           local data volume
                              (ExcelJS)             votes.jsonl / voters.json
                                                    workbook.xlsx / meta.json
```

All storage sits behind a `SheetsRepo` interface, so the data layer is
swappable via `DATA_BACKEND` (`xlsx` default, `google`, `n8n`, `demo`). The
**xlsx** backend:

- **Products** are parsed from the uploaded workbook (same anchor-detection /
  `=IMAGE()` / price logic as the Google path).
- **Votes** are appended to `votes.jsonl` — append-only, which keeps concurrent
  multi-phone voting safe. Undo appends an `undo` row; the aggregator nets it
  out. Nothing is ever overwritten.
- **Voters** live in `voters.json`, managed by the admin in the dashboard.
- **Export** re-opens the original workbook and writes a `Results` sheet plus
  the inline `W..Z` columns on the source sheet's anchor rows — then downloads
  it. The protected `U`/`V` columns are never touched.

```
mazyoud-collection-vote/
  server/   Express + TypeScript API, serves the built web app
    src/
      config.ts            all constants + env (tune here)
      index.ts             API routes (voter + admin) + static + /healthz
      auth.ts              voter + admin auth, JWT cookies, PIN check
      aggregate.ts         vote resolution + verdict rules
      imageProxy.ts        allow-listed image proxy + LRU cache
      store/
        fileStore.ts       append-only votes + voters + workbook (DATA_DIR)
        XlsxRepo.ts         default backend (upload/export/voter CRUD)
      xlsx/workbook.ts     ExcelJS parse + results export
      sheets/              SheetsRepo interface, Google/n8n/demo backends, parse.ts
  web/      Vite + React + TS + Tailwind PWA (voter flow + admin dashboard)
  Dockerfile docker-compose.yml Caddyfile.snippet .env.example
```

---

## Quick start (Docker)

Builds into **one image** (frontend + backend) and persists data in a volume.

```bash
cd mazyoud-collection-vote
cp .env.example .env
#  edit .env →  set ADMIN_PASSWORD and SESSION_SECRET (openssl rand -hex 32)

docker network create caddy          # once, shared with your Caddy
docker compose up -d --build
```

Add the [`Caddyfile.snippet`](Caddyfile.snippet) to your Caddyfile, point
`vote.mazyoud.com` DNS at the box, reload Caddy. Then:

1. Open `https://vote.mazyoud.com/admin`, log in with `ADMIN_PASSWORD`.
2. **Upload** your `.xlsx`, **add voters** (name + PIN).
3. Voters open `https://vote.mazyoud.com`, pick their name, enter their PIN, swipe.
4. Back in `/admin`, watch progress and **Export results ↓** any time.

> For a quick local container test without Caddy, uncomment the `ports:` block in
> `docker-compose.yml` and use `http://localhost:8080`.

Want to see the UI in 30 seconds with no upload? Run with `DATA_BACKEND=demo`
(seeds fake products + voters `Demo/0000`, `Sara/1234`, `Karim/4242`).

---

## Local development

Two terminals (Node 20+):

```bash
# 1) backend on :8080
cd server
npm install
cp ../.env.example .env       # set ADMIN_PASSWORD + SESSION_SECRET
npm run dev

# 2) frontend on :5173 (proxies /api, /img to :8080)
cd web
npm install
npm run dev                   # open http://localhost:5173  (admin: /admin)
```

Run the logic checks any time: `cd server && npm test` (23 checks covering
anchor detection at 14 & 377 products, image/price parsing, concurrent votes,
undo, resume, and every verdict rule).

---

## The admin dashboard

Reachable at **`/admin`**, gated by `ADMIN_PASSWORD`. From there you can:

- **Upload `.xlsx`** — parses products immediately; re-upload anytime to replace.
- **Manage voters** — add `name + PIN`, enable/disable, remove. Stored locally.
- **Progress** — total products, fully-decided count, per-voter tallies.
- **Ranked results** — BUY / REVIEW / SKIP with ⭐ hero and ⚠ conflict markers.
- **Export results ↓** — downloads the uploaded workbook with a rebuilt
  `Results` sheet + inline `W..Z` on anchor rows.

---

## The .xlsx layout it expects

Same layout as the Mazyoud OMS buying template, on the tab named by
`SHEET_PRODUCTS_TAB` (default `AID 1`; falls back to the first sheet). Products
are in **10-row blocks** — the first row of each block (the **anchor row**)
holds the product fields; the other 9 rows are blank in the product columns.

The app scans **column B**; **every row where B is non-empty is a product**.
Columns read (A–S only, so the protected U/V columns are never read):

| Col | Field | How it's read |
| --- | --- | --- |
| B | `=IMAGE("url")` | the URL is extracted with `IMAGE\("([^"]+)"\)` (single quotes / extra args tolerated); loaded via the image proxy |
| C | Selling Price DZD | cached numeric value (formula result), else the formatted text, else `null` |
| M | Product Link | the trimmed value → stable **`product_key`** (falls back to `row-<anchorRow>`) |
| D,E,P,Q,R,S | color / remark / category / gender / notes / title | optional; category drives the picker, title shows only in Results / behind `SHOW_DETAILS` |

> Product images must be **`=IMAGE("url")`** formulas (1688/alicdn URLs).
> Embedded/pasted images are not extracted in this version.

On **export**, the app writes back into the same workbook:

- a **Results** sheet (one ranked row per product), and
- inline columns on the source sheet's anchor rows only — **W** Votes Net,
  **X** Verdict, **Y** Conflict, **Z** Voter Breakdown (`keep:3 skip:1 super:1`).

---

## Configuration

Copy `.env.example` to `.env`. Key settings:

| Key | Default | Meaning |
| --- | --- | --- |
| `DATA_BACKEND` | `xlsx` | `xlsx` (upload) · `google` · `n8n` · `demo` |
| `DATA_DIR` | `data` | where the workbook / votes / voters are stored (mount a volume) |
| `ADMIN_PASSWORD` | — | **required** for the admin dashboard |
| `SESSION_SECRET` | — | JWT signing secret (`openssl rand -hex 32`) |
| `SESSION_TTL` | `43200` | session lifetime (seconds, 12h) |
| `HASH_PINS` | `false` | store/compare PINs as salted-sha256 |
| `APP_ORIGIN` | `https://vote.mazyoud.com` | CORS is locked to this |
| `PORT` | `8080` | listen port |
| `SHEET_PRODUCTS_TAB` | `AID 1` | which worksheet to read for products |
| `IMG_ALLOWED_HOSTS` | `cbu01.alicdn.com,.alicdn.com,.1688.com` | image-proxy allowlist (dot-prefixed = subdomains). **Add your Backblaze host.** |
| `INLINE_WRITEBACK` | `true` | write the inline W–Z columns on export |
| `SHOW_DETAILS` | `false` | show extra metadata on the card (off = image + price only) |
| `ALLOW_REVOTE` | `false` | let a voter swipe products they already voted on |
| `PRODUCTS_CACHE_TTL` | `300000` | product cache TTL (ms) — google backend only |
| `GOOGLE_*`, `N8N_*` | — | only for those backends (see below) |

Voting weights and verdict thresholds live at the top of
[`server/src/config.ts`](server/src/config.ts):

```ts
WEIGHT_KEEP = 1   WEIGHT_SUPER = 2   WEIGHT_SKIP = -1
BUY_THRESHOLD = 2          // net_score >= this -> BUY (if no conflict)
SKIP_THRESHOLD = -1        // net_score <= this -> SKIP
CONFLICT_MIN_SHARE = 0.34  // minority side >= this share of voters -> CONFLICT
```

### Verdict rules

```
net_score = keep*1 + super*2 + skip*(-1)
hero      = super >= 1
conflict  = positive>=1 AND skip>=1 AND min(positive,skip)/voters >= CONFLICT_MIN_SHARE
verdict   = BUY    if net_score >= BUY_THRESHOLD and not conflict
            SKIP   if net_score <= SKIP_THRESHOLD
            REVIEW otherwise
```

---

## Backends

| `DATA_BACKEND` | Products | Votes / voters | Notes |
| --- | --- | --- | --- |
| `xlsx` (default) | uploaded workbook | local files in `DATA_DIR` | admin uploads + exports; no external services |
| `google` | live Google Sheet | Sheets tabs | see [below](#optional-google-sheets-backend) |
| `n8n` | webhook | webhook | documented drop-in for existing n8n flows |
| `demo` | in-memory seed | in-memory | instant preview, no setup (data resets on restart) |

---

## API reference

JSON throughout. Voter auth and admin auth are separate signed-JWT httpOnly
cookies. CORS is locked to `APP_ORIGIN`; `/api/login` and `/api/vote` are
rate-limited per IP.

**Voter**

| Method & path | Body | Returns |
| --- | --- | --- |
| `POST /api/login` | `{ name, pin }` | cookie → `{ voter, votedKeys }` |
| `GET /api/products?category=` | — | `{ products:[{ product_key,row_anchor,image,price,category,gender,title,myVote }] }` |
| `GET /api/categories` | — | `{ categories }` |
| `POST /api/vote` | `{ product_key, row_anchor, vote }` | `{ ok, vote_id, aggregate }` |
| `POST /api/undo` | `{ target_vote_id }` or `{ product_key }` | `{ ok, undone, aggregate }` |
| `GET /api/progress` | — | `{ totalProducts, perVoter, fullyDecided, leaderboard }` |
| `GET /api/results` | — | `{ results }` (net_score desc) |

**Admin** (cookie from `POST /api/admin/login`)

| Method & path | Body | Returns |
| --- | --- | --- |
| `POST /api/admin/login` | `{ password }` | sets admin cookie |
| `GET /api/admin/status` | — | workbook meta + product count + voters |
| `POST /api/admin/upload` | multipart `file` (.xlsx) | `{ ok, productCount, sheetName, categories }` |
| `GET /api/admin/export` | — | streamed `.xlsx` with results |
| `GET/POST/DELETE /api/admin/voters[...]` | `{ name, pin, active }` | manage voters |

**Other**: `GET /img?u=<url>` (allow-listed image proxy), `GET /healthz`.

---

## PIN hashing

Set `HASH_PINS=true`. PINs entered in the admin dashboard are then stored as
salted-sha256 (`salt$sha256(salt+pin)`); login hashes and compares. For the
Google backend (PINs in the sheet), generate a value with:

```bash
node -e 'const c=require("crypto");const pin="1234";const s=c.randomBytes(6).toString("hex");console.log(s+"$"+c.createHash("sha256").update(s+pin).digest("hex"))'
```

---

## Optional: Google Sheets backend

Set `DATA_BACKEND=google` to read/write a live spreadsheet instead of uploading.

1. In Google Cloud, enable the **Google Sheets API** and create a **service
   account** with a **JSON key**.
2. **Share** the spreadsheet with the service account's email as **Editor**.
3. Set `SPREADSHEET_ID`, and `GOOGLE_SERVICE_ACCOUNT_JSON` (base64 of the key)
   or `GOOGLE_APPLICATION_CREDENTIALS` (path to it).

In this mode the app bootstraps `Voters` / `Votes` / `Results` tabs, reads
column B as `FORMULA` and C as `UNFORMATTED_VALUE`, appends votes atomically,
serializes writes through a mutex with 429 backoff, and mirrors results into
`W..Z` on anchor rows. Voters come from the `Voters` tab (the admin dashboard's
voter management is xlsx-only).

---

## Acceptance criteria

- ✅ Admin uploads an `.xlsx`; every product shows as a card with **only image + price**.
- ✅ Swipe right/left/up and keys → ← ↑ register keep/skip/super; **Backspace/Z** undo.
- ✅ Two voters on two phones voting the same product → two appended rows, neither overwritten.
- ✅ Undo removes the last vote from the tally.
- ✅ Logout/re-login resumes at the first un-voted product for that voter.
- ✅ Admin dashboard shows ranked BUY/REVIEW/SKIP results with ⭐ hero, ⚠ conflict, and live progress.
- ✅ **Export** downloads the workbook with a `Results` sheet + inline `W..Z` on anchor rows; **U/V untouched**.
- ✅ Works unchanged on a 377-product sheet (dynamic anchors) — covered by `npm test`.
- ✅ Data survives container restarts (volume-mounted `DATA_DIR`).
- ✅ Builds into one container, runs behind Caddy at `vote.mazyoud.com`.

---

## Troubleshooting

- **Admin login says "not configured"** — set `ADMIN_PASSWORD` in `.env` and restart.
- **Upload says "Could not parse"** — make sure products are on the
  `SHEET_PRODUCTS_TAB` sheet, with the image `=IMAGE()` formula in column B.
- **Cards show no image** — the upstream host isn't in `IMG_ALLOWED_HOSTS`
  (dot-prefixed entries match subdomains), or the images are embedded rather
  than `=IMAGE()` URLs.
- **Votes/voters reset after redeploy** — `DATA_DIR` isn't on a persistent
  volume. The provided `docker-compose.yml` mounts one (`mazyoud-data`).
- **403 on every API call from the browser** — `APP_ORIGIN` must exactly match
  the site origin (scheme + host); CORS is locked to it.

---

## Extension points (non-goals for v1)

- **AI scoring** — handled separately; `Results` / the export are the surface.
- **Embedded-image extraction** — only `=IMAGE("url")` is parsed today.
- **Identical-colourway dedup** — not yet; `product_key` is the grouping key.
