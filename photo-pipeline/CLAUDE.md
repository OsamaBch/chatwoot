# CLAUDE.md — Mazyoud Product Photo Pipeline (v3, final)

## What this is

A batch pipeline that turns Canon CR3 files into catalogue-ready product
images. Input: CR3 files + a per-mode reference image. Output per view: a
zoom master, a display master, and their 1:1 crops — named by barcode,
uploaded to Backblaze B2, recorded in Postgres.

Scale: 6,000 SKUs × 2–3 views ≈ 16,000 generations per full run. Throughput,
cost per run, and zoom-grade fabric detail are first-class requirements.

Companion docs (read before writing code; this file wins on conflict):
`docs/cr3-product-photo-tool-spec.md`, `docs/prompt-architecture-v2.md`.

## Non-negotiable invariants

1. **The model never decides geometry.** Crop, canvas, margins, background,
   scale are computed in code. Prompts ask for loose framing only. Assert
   returned dimensions; mismatch = BadOutput.
2. **One generation, four files.** Zoom master, display master, both 1:1
   crops derive from one API call per view. A second call is a bug.
3. **Never upscale the cutout above 1.0 of source resolution.** If the
   source can't fill the zoom master, shrink the zoom master (keep 6:7 and
   relative anchor), record `zoom_native_px`. Downscale is always fine.
4. **Real pixels beat generated pixels.** Tier 0 (composite, no AI) is the
   default; generation is opt-in per mode. Best zoom detail AND cheapest.
5. **Front and back of one barcode share one scale factor** (larger bbox,
   stored on the barcode record).
6. **Provenance on every asset**: core_sha, mode_id, mode_version,
   reference_sha, provider, model_snapshot, tier_reached. Pinned snapshots,
   never aliases.
7. **Idempotency key** = sha256(source_sha256 + core_sha + mode_version +
   reference_sha + model_snapshot). Unique index. Same inputs never billed
   twice, across restarts.
8. **API keys server-side only.** Never in a client bundle, response body,
   or log line.
9. **No age words in any prompt layer** (child, kid, baby, toddler, infant,
   boy, girl) — size_band carries it. Mode editor rejects them, plus
   framing words (crop|margin|aspect|ratio|1:1|6:7|resolution|px).
   Case-insensitive, whole words, enforced server-side.

## Model routing — escalation ladder

```
Tier 0  Real-pixel composite (no AI)      ~0 DZD    instant   best zoom
   ↓ only if the mode's template requires invention
Tier 1  gemini-3.1-flash-image, 2K        ~27 DZD   ~15s      batch: 13 DZD
   ↓ only after QA-gate failure on 2 Tier-1 attempts
Tier 2  gpt-image-2, quality=high, 2K     ~78 DZD   ~3min     the closer
```

- Tier 1: portrait aspect, 2K. `execution_mode=batch` for backfill (50%
  off), `live` for daily new arrivals.
- Tier 2: portrait 2K (e.g. 1152×2048 or custom with sides divisible by
  16). Never request above 2K on this model — higher is experimental.
  Client timeout 360s (high/2K can take 3–5 min).
- A mode may be flagged `tier2_default` (allowed for mannequin if its
  measured Tier-1 reject rate exceeds 25%).
- Escalation is automatic and logged: item records tier_reached and the
  gate failures that caused it.
- **PolicyRefusal never escalates and never retries** — manual queue,
  regardless of tier.
- Budget cap applies across tiers combined, enforced in the limiter
  BEFORE dispatch.
- Routing is a policy object with per-mode thresholds — config, not
  if-statements in the pipeline.
- Run report shows per tier: pass rate, cost, mean latency. Thresholds are
  tuned from this data, starting with a 100-SKU calibration batch.

## Geometry contract

```
ZOOM MASTER      3600 × 4200   (6:7)   (shrinks per invariant 3 when
DISPLAY MASTER   2400 × 2800   (6:7)    source resolution is lower)
1:1 WINDOW       full width, y-offset = height × 200/2800
BACKGROUND       #EDEAE5
ANCHOR           garment bbox centre → (0.5 width, window centre)
EDGE CLEARANCE   ≥ 40px pure background all sides at display scale,
                 proportional at zoom scale, in ALL crops
```

Fill ratio (bbox height ÷ square window), locked per mode:
`hanging 0.82 · flatlay 0.84 · mannequin 0.80 · accessory 0.62`.

Bbox rules: exclude topmost connected component narrower than 8% of bbox
width (hanger hook — pixels stay, bbox ignores); union all components above
2% of image area (multi-piece sets are one product).

## Prompt architecture

Three layers, assembled server-side: frozen core (constant in code,
`core_v2`, sha stored per asset — full text in
docs/prompt-architecture-v2.md) + mode block (DB record, versioned,
photo_lead-editable) + slot-filled line (enum-bound slots only, never free
text). Modes: hanging, flatlay, mannequin (ghost/invisible — never a
rendered figure), accessory.

## Stack

- Python 3.12, one service. uv, FastAPI, SQLAlchemy 2.0 async + Alembic,
  Postgres 16.
- **pyvips** for all compositing — lazy pipeline, one write per file.
- **rawpy** (LibRaw): use_camera_wb, no_auto_bright, sRGB, 16-bit.
  **exiftool** for metadata + embedded preview extraction.
- **onnxruntime**: BiRefNet full precision, batched 8–16, session warmed
  once. Trimap matting path selected by material_family (tulle/lace/mesh).
- **httpx** AsyncClient, HTTP/2, one shared client per process.
- asyncio for IO + ProcessPoolExecutor for CPU, bounded queue between.
- React/TS operator UI.

## Export quality (zoom-critical)

- JPEG q92, **4:4:4 chroma** (pyvips subsample_mode=off). 4:2:0 smears
  knit texture and navy/red detail.
- lanczos3 on every downscale. Never sharpen.
- Strip GPS/serial/owner metadata; keep ICC profile.

## Performance architecture

Bottleneck is model latency, not compute. Optimise concurrency and
work-avoidance.

```
Stage 1 (CPU)  ingest → develop → segment → cutout      ProcessPool
                     ↓ bounded queue (~2× IO concurrency)
Stage 2 (IO)   route → generate → validate → composite → upload   asyncio
```

Ranked rules:
1. Idempotency skip before any work. Re-run of a completed batch = zero
   API calls, ≤60s.
2. Adaptive concurrency (AIMD) per provider: start 4, ceiling 16 for
   Gemini; Tier 2 gets its own limiter tuned to OpenAI RPM/TPM.
3. Decode once at the right size: embedded JPEG for barcode/thumbs/
   segmentation input; segment at 1024px, upscale the alpha; full demosaic
   only for frames reaching the compositor.
4. Batched ONNX inference, warm session.
5. Never block the event loop — pyvips/rawpy/onnx via run_in_executor.
6. One httpx client, HTTP/2, generous keepalive.
7. Reference image base64 encoded once per batch per mode.
8. pyvips stays lazy end-to-end.
9. DB writes batched: groups of ~50 or every 2s.
10. Concurrent multipart B2 upload streamed from the vips pipeline.
11. Gemini Batch API as a second executor behind the same ImageProvider
    interface — execution_mode live | batch, no code fork.
12. Budget cap in the limiter, pre-dispatch.

### Targets (measured by `bench` on 200 real frames — print this table)

| Metric | Target |
|---|---|
| Stage 1 throughput | ≥ 20 frames/s/core at 1024px segmentation |
| Composite (4 files + encode) | ≤ 250ms per view |
| Tier-1 throughput | ≥ 40 images/min at concurrency 8 |
| Full-run wall clock (Tier-1 live) | ≤ 7h for 16,200 calls |
| Re-run of completed batch | ≤ 60s, zero API calls |
| Peak RSS | ≤ 4 GB regardless of batch size |

Tier 2 is latency-bound (~3min/image) — report its throughput, don't
target it.

## QA gates (in order, fail fast; rejects store gate name)

1. Exact dimensions, all four files.
2. Background: 8 edge patches within ΔE 2 of #EDEAE5, low variance.
3. Fill ratio within ±2% of the mode constant.
4. Zero non-background pixels in the edge-clearance band, all crops.
5. Mean ΔE2000 source-cutout vs output over garment mask ≤ 3.0 (zoom
   master).
6. SSIM over the garment region ≥ 0.85.
7. OCR finds no legible text absent from the source.
8. Sharpness: Laplacian variance over garment region of the zoom master
   above a calibrated threshold; store sharpness_score per item.

Gates 1–4 cheap and first; 5–8 only after they pass. Gate failures drive
tier escalation per the routing ladder.

## Failure handling

- Retry 3× (exponential backoff + jitter) on RateLimited/Transport/5xx
  only. Distinct exception types: RateLimited, PolicyRefusal, Transport,
  BadOutput.
- Unknown or check-digit-invalid barcode → quarantine, never guess.
- Batches end succeeded/failed/quarantined; failed-only re-run supported.
- Barcode with approved assets → explicit replace confirmation; old files
  versioned (_02, _03), never overwritten.

## Barcode resolution (first hit wins, source recorded)

1. Barcode card frame at the start of each SKU sequence (embedded preview
   decode, propagated until next card; sequence fails whole if its card
   doesn't decode — never inherit across sequences).
2. Filename prefix ^(\d{8,14})[_\- ].
3. Metadata (XMP dc:title, IPTC ObjectName, EXIF ImageDescription).
4. Manual entry in UI with preview shown.
EAN-13/EAN-8 check digit must pass; barcode must exist in Directus;
whitelist own prefix range (supplier tags on garment labels are rejected).

## Code conventions

- mypy --strict clean, ruff clean, no bare except.
- All tunables in one typed settings module from env; no inline literals
  in the pipeline.
- Structured JSON logs, batch_id + item_id on every line.
- Golden-image tests: fixed input → byte-identical composite output.
- Fake provider with latency/failure/refusal injection; no test hits a
  real API.
