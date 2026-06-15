# Mazyoud Listing Forge

A local desktop image-prep studio that turns raw supplier (_fournisseur_) product
photos into clean, website-ready **6:7 listing images** for a WooCommerce
children's-clothing store.

> **Catalog fidelity is mandatory.** We only clean, sharpen/upscale, and frame the
> product. The garment itself is never altered — design, color, pattern,
> proportions, and parts stay exactly as shot. The pipeline prefers _masked
> inpaint_ over full regeneration and skips AI entirely when an image is already
> clean.

This is a standalone app and is unrelated to our other tools (_Digital Garment Twin
Studio_, _Mazyoud SKU Image Matcher_, _Mazyoud Buy Judge_). It lives in its own
folder and does not touch the surrounding repository.

---

## Status — Phase 1 ✅ (skeleton, no AI)

| Phase | Scope | State |
|------|-------|-------|
| **1** | Ingest + normalize, SKU + validation, reorder grid, slugify + contiguous numbering, overwrite/skip/version, auto-clear (not on error), ZIP, manifest CSV, **deterministic 6:7 white-pad framing** | **Done** |
| 2 | Settings panel (provider switch, masked key, keychain, "Test key"), `AiProvider` for Gemini + OpenAI, cost estimate + retry/backoff | Planned |
| 3 | Watermark detect → masked inpaint (+ manual box/brush) → texture-preserving upscale; SSIM fidelity guard | Planned |
| 4 | Subject mask + fixed negative-space; solid-fill vs generative-outpaint; mask-failure & clipped-source handling; subject-scope toggle | Planned |
| 5 | Backblaze B2 + idempotent WooCommerce media upload (behind flags) | Stubbed |

The interfaces and config keys for later phases already exist (`backend/src/ai/provider.ts`,
feature flags in `config.ts`) so wiring them up doesn't require a refactor.

---

## Stack

- **Frontend:** React + TypeScript + Vite + Tailwind. Brand: coral `#FA5B4E`, charcoal `#1F1B1C`, white canvas.
- **Backend:** Node + Express + TypeScript.
- **Deterministic image ops:** [`sharp`](https://sharp.pixelplumbing.com/) (resize, pad, composite, **mozjpeg** progressive JPEG).
- **HEIC decode:** `heic-convert`.
- **AI (phase 2+):** one `AiProvider` interface, two backends — Gemini **Nano Banana Pro** (`gemini-3-pro-image-preview`, confirm id in phase 2) and OpenAI **gpt-image-1**.

---

## Quick start

```bash
cd mazyoud-listing-forge
npm run install:all     # installs root + backend + frontend deps
npm run dev             # backend on :5174, frontend on :5173 (one command)
```

Then open **http://localhost:5173**. Vite proxies `/api` and `/files` to the
backend, so the browser only ever talks to one origin. Desktop only (test build).

Other scripts:

```bash
npm run typecheck       # tsc --noEmit for backend + frontend
npm run build           # backend (tsup) + frontend (vite) production builds
npm run smoke           # end-to-end pipeline check (normalize → frame → name), no server
```

---

## How a batch flows

1. **Enter the SKU** (e.g. `ROBE-2025-014`). _Generate_ stays disabled until it's non-empty.
2. **Drop or pick** supplier photos / a folder. On ingest each image is normalized:
   HEIC→JPEG, auto-orient via EXIF then **strip EXIF**, CMYK→sRGB, flatten
   transparency onto white. Non-images are rejected with a reason; low-res
   (< 600px long side) and chart-looking images get a soft warning.
3. **Reorder** the grid by dragging. Position 1 is the **hero** (coral border + ★ badge);
   filenames re-preview live.
4. **Generate** — deterministic framing to an exact **1714×2000 (6:7)** white canvas,
   product centered at the configured negative-space ratio (no stretch, no crop),
   then mozjpeg progressive JPEG encoded toward `≤ 350 KB` with a quality floor of 80.
   (Phase 1 makes **zero AI calls** — there is nothing to pay for.)
5. **Review queue** surfaces only the exceptions (low source, flagged, failed) for
   accept / re-run / exclude.
6. **Export** — _Download ZIP_ (`{SKU}_listing.zip`) or _Write to folder_. Filenames
   are assigned **at export time, over successful images only**, so numbering is
   always contiguous (no `-1` gap if one image failed):
   - hero → `ROBE-2025-014.jpg`
   - rest → `ROBE-2025-014-1.jpg`, `ROBE-2025-014-2.jpg`, …
   On existing files you're prompted to **overwrite / skip / version**. A per-run
   **manifest CSV** (`SKU, filename, order, source, status, ai_used, flags`) is written
   for traceability and as a feed for the SKU Matcher.

State auto-clears at the **start of a new batch** — but **not on error** (a failed
batch keeps its state so you can retry). "New batch" clears explicitly.

> The SKU separator (`-`) and extension (`.jpg`) are config and must match the
> _Mazyoud SKU Image Matcher_ plugin's parser.

---

## Configuration

All non-secret, structural settings live in a single source of truth:
[`config.ts`](./config.ts). The backend imports it directly; the frontend reads the
same values at runtime via `GET /api/config`.

Key defaults: `outputWidth 1714`, `outputHeight 2000`, `negativeSpaceRatio 0.82`,
`backgroundColor #FFFFFF`, `jpegMaxKB 350`, `jpegQualityFloor 80`,
`filenameSeparator "-"`, `fileExtension ".jpg"`, `concurrency 3`,
`minSourceLongSide 600`, `maxOutpaintFraction 0.25`, plus feature flags
`enableBackblaze`/`enableWooUpload` (both `false`).

## Secrets

**No secrets are ever committed or logged.** API keys are stored in the OS keychain
or a gitignored local app-data file (Settings panel, phase 2). `.env` is a fallback
only — copy [`.env.example`](./.env.example) to `.env` (gitignored) if you use it.

---

## Project layout

```
mazyoud-listing-forge/
├── config.ts                 # single source of truth (no secrets)
├── package.json              # root scripts (concurrently runs both)
├── .env.example
├── backend/                  # Express + TypeScript
│   └── src/
│       ├── index.ts          # server + routes + static /files
│       ├── routes/           # config · ingest · generate · export
│       ├── services/         # normalize · framing · naming · manifest · store
│       ├── ai/provider.ts    # AiProvider interface (phase 2/3)
│       └── smoke.ts          # pipeline self-test
└── frontend/                 # React + Vite + Tailwind
    └── src/
        ├── App.tsx           # single working screen
        ├── api.ts · naming.ts · types.ts
        └── components/       # SkuField · DropZone · ResultGrid · ImageCard ·
                              # BatchProgress · ReviewQueue · Settings · Conflict
```

Working files live in `backend/.data/` and exports default to `./output/` — both
gitignored.

### Note on `npm audit`

The only advisory is **dev-only**: `tsup → esbuild` (build tooling). The esbuild
advisory affects its dev-server mode, which this project does not use (`tsx` for dev,
`tsup` for builds). It does not affect the shipped app.
