# CR3 → Consistent Product Photos — Build Spec

Version 1.0 · Mazyoud · target: children's apparel catalogue at ~6,000 SKU scale

---

## 0. The one architectural decision to make first

You wrote the request as "feed CR3 + reference → model gives back a finished photo." That design will not hold consistency at scale, for one structural reason: **no image model gives you deterministic framing.** Same prompt, same reference, two calls → the garment sits 4% higher, the shadow is 3px softer, the grey is one step warmer. Across 6,000 SKUs on a grid page, that reads as sloppy.

So the pipeline must be split:

| Stage | Who does it | Deterministic? |
|---|---|---|
| RAW develop (WB, exposure, preset) | RawTherapee/darktable CLI, fixed profile | ✅ yes |
| Cut-out (garment isolated from bg) | Segmentation model (SAM/BiRefNet/rembg) | ⚠️ mostly |
| Cleanup / shadow / hanger fix | **AI model (GPT Image / Gemini)** | ❌ no |
| Framing, canvas, negative space, 6:7 + 1:1 | **Your code (sharp / Pillow)** | ✅ yes |
| Naming, barcode, export | Your code | ✅ yes |

**Rule: the AI model never decides the crop, the canvas size, the margins, or the background colour.** It gets a transparent-background garment on a fixed canvas and is asked to do one narrow job. Everything geometric is arithmetic.

### Corollary: consider not regenerating the garment at all

For pure catalogue shots (grey BG front/back), you may not need generation. RAW → cut-out → composite onto a #EDEAE5 canvas → synthetic contact shadow → done. Cost: ~0 DZD per image, 100% consistent, 100% colour-accurate. Reserve the AI call for:

- hanger/clip removal and hole fill
- wrinkle/dust/thread cleanup
- lifestyle and flatlay variants (where you *do* want invention)

That cuts your API bill by roughly 60–70% and removes the biggest source of drift. Ship the deterministic path first, add the AI path as an optional per-template flag.

---

## 1. Geometry — the part that makes "consistent negative space" real

Fix these numbers once and never change them (changing them re-shoots your whole catalogue).

```
MASTER_CANVAS   = 2400 × 2800   (6:7)
SQUARE_WINDOW   = 2400 × 2400, y-offset 200   (centred inside the master)
BACKGROUND      = #EDEAE5  (warm light grey — locked hex, not "light grey")
```

The 1:1 is literally a window cut out of the 6:7. Because the square window is inset, the garment must be sized to fit **inside the square**, so neither crop ever clips it:

```
GARMENT_MAX_H   = 0.82 × 2400 = 1968 px
GARMENT_MAX_W   = 0.80 × 2400 = 1920 px
ANCHOR          = garment bbox centre at (1200, 1400) in master coords
```

Placement algorithm (deterministic, per image):

1. Alpha-matte the garment → bounding box of non-transparent pixels.
2. `scale = min(GARMENT_MAX_H / bbox_h, GARMENT_MAX_W / bbox_w)` — never upscale past 1.0 from source resolution.
3. Paste so bbox centre lands on ANCHOR.
4. Export 6:7 master. Export 1:1 as `crop(0, 200, 2400, 2400)`.

**Front/back pairing:** both views of the same barcode must use the *same* scale factor, computed from the larger of the two bboxes. Otherwise the back view looks like a different size garment. Store the resolved `scale` and `anchor` on the barcode record and reuse it for every view.

**Hanger-hung garments (your reference photo):** anchor on the *garment* bbox, not the hanger bbox, or a long hanger hook will push everything down. Detect the hook as the topmost connected component narrower than 8% of bbox width and exclude it from the bbox, but keep it in the pixels.

---

## 2. Model layer

### Native aspect ratios — neither provider gives you 6:7

| Provider | Model | Sizes / ratios |
|---|---|---|
| OpenAI | `gpt-image-2` (flagship, Apr 2026 snapshot), `gpt-image-1.5`, `gpt-image-1-mini` | 1024×1024, 1024×1536, 1536×1024 only |
| Google | `gemini-3.x-flash-image` family | 1:1, 3:2, 2:3, 3:4, 4:3, 4:5, 5:4, 9:16, 16:9, 21:9 |

`gpt-image-1` is being deprecated (Oct 2026) and DALL·E was removed from the API in May 2026 — don't build on either. Note also that developers have reported the Gemini `aspect_ratio` config being silently ignored on some model versions. **Always assert the returned image dimensions and reject the response if they don't match.**

Practical consequence: generate at the nearest native portrait ratio (2:3 or 3:4), then let *your* compositor place the result on the 6:7 master. Never ask the model for 6:7.

### Model abstraction

```ts
interface ImageProvider {
  id: 'openai' | 'gemini'
  modelSnapshot: string        // pinned, e.g. 'gpt-image-2-2026-04-21'
  edit(input: {
    image: Buffer              // PNG, transparent bg
    mask?: Buffer
    referenceImages: Buffer[]  // Gemini accepts many; OpenAI fewer
    prompt: string
    ratio: '2:3' | '3:4' | '1:1'
  }): Promise<{ png: Buffer; requestId: string; costUsd: number }>
}
```

Pin the **snapshot**, not the alias. Store `provider + modelSnapshot + promptVersion + referenceSetHash` on every generated asset. Six months from now when you regenerate one SKU, that record is the only way to make it match its siblings.

---

## 3. The generation prompt

Two prompts. The system prompt is frozen and versioned; the user prompt is slot-filled.

### System prompt (frozen — `prompt_v1`)

```
You are a product-photography retoucher for an apparel e-commerce catalogue.

You will receive:
- INPUT IMAGE: one garment, already isolated, on a transparent or flat background.
- REFERENCE IMAGES: the locked house style. Match their lighting direction,
  shadow softness, background tone, and hanger appearance exactly.

YOUR ONLY JOB is to return the SAME garment, photographed in the house style.

MUST PRESERVE, PIXEL-FAITHFUL:
- Exact colour and tone of every fabric panel. Do not warm, cool, saturate,
  or "improve" any colour.
- Exact print, pattern, motif, stripe count and stripe width.
- Exact number, size, shape and position of buttons, zips, pockets, seams,
  topstitching, labels and trims.
- Exact silhouette, hem length, sleeve length, collar shape.
- Any visible text, logo or care label exactly as it appears. If text is
  illegible in the input, leave it illegible. Never invent legible text.

YOU MAY:
- Replace the background with a smooth, even, warm light-grey studio backdrop.
- Add one soft contact shadow beneath the garment, direction and opacity
  matching the reference.
- Remove clips, pegs, pins, tape, stands, dust, loose threads and stray hairs.
- Relax harsh creases from folding, while keeping natural fabric drape.
- Render the garment on the same wooden hanger shown in the reference, hung
  from the same point.

YOU MUST NOT:
- Add or remove any garment feature.
- Change the fit, add a body, a mannequin, a person, or a face.
- Add props, text, watermarks, borders, gradients or vignettes.
- Change the camera angle. Straight-on frontal only.
- Crop, rotate, or reframe. Fill the frame as instructed and nothing more.

The garment must occupy the frame centred, with even margins, and must not
touch any edge.

If you cannot preserve a detail faithfully, keep the input pixels rather than
inventing a plausible substitute. Fidelity beats beauty.
```

### User prompt (slot-filled)

```
Garment: {category}, {colour_family}, size band {size_band}.
View: {front|back|detail}.
Template: {template_id}.
Background: even warm light grey, no texture, no gradient.
Hanger: {hanger_spec}.
Shadow: soft contact shadow directly beneath, {shadow_opacity}% opacity.
Return one image only.
```

### Two prompt rules specific to your business

1. **Never write "child", "kid", "baby", "toddler", "girl" or "boy" in the prompt.** Children's apparel plus image-generation APIs is a reliable false-positive trigger on both providers' safety filters. Say `size band 4Y` or `small-size garment`. Keep the age taxonomy in your database, out of the prompt.
2. **Never let the model add legible text.** A hallucinated brand name on a garment you sell is a legal problem, not a cosmetic one.

---

## 4. Barcode: detection, validation, naming

### Resolution order (first hit wins, all recorded)

| Priority | Source | Notes |
|---|---|---|
| 1 | **Barcode card shot in-frame** | Photographer shoots a card with the SKU barcode as the first frame of each SKU sequence. Decode with ZBar/ZXing on the embedded JPEG preview, then propagate to every subsequent frame until the next card. This is the only method that survives a card reader, a rename, and a tired intern. |
| 2 | **Filename** | `^(\d{8,14})[_\- ]` regex. Set the prefix in EOS Utility during tethered capture. |
| 3 | **Metadata** | XMP `dc:title`, IPTC `ObjectName`, EXIF `ImageDescription`, `UserComment`, Canon `OwnerName`. Read with `exiftool -j`. Canon does not write barcodes natively — this only works if your capture software sets it. |
| 4 | **Manual entry** | UI prompt, with the frame's preview shown next to the input so the operator can see what they're labelling. |

### Validation before anything is named

- EAN-13 / EAN-8 check digit must pass. Reject silently-wrong scans.
- Barcode must exist in Directus. Unknown barcode → quarantine, don't guess.
- Barcode already has approved assets → require explicit "replace" confirmation, and version the old files rather than overwriting.
- Two different frames in one batch resolving to the same barcode + same view → flag as duplicate, keep the sharper one (Laplacian variance), don't generate both.

### Filenames

```
{barcode}_{view}_{ratio}_{seq}.jpg

6130001234567_front_6x7_01.jpg
6130001234567_front_1x1_01.jpg
6130001234567_back_6x7_01.jpg
```

Lowercase, ASCII only, no spaces. `seq` is zero-padded and stable across reruns (derived from source file hash order, not from wall-clock time).

---

## 5. CR3 handling

CR3 is Canon's ISO-BMFF RAW container. Browsers cannot decode it — this is server-side, full stop.

**Fast path (preview):** `exiftool -b -JpgFromRaw file.CR3` extracts a large embedded JPEG in ~50ms. Use it for the barcode scan, the thumbnail grid, and the operator UI.

**Quality path (develop):** `rawpy`/LibRaw or `rawtherapee-cli` with a **fixed, checked-in profile**:

```bash
rawtherapee-cli -o out/ -p /profiles/mazyoud_catalogue_v1.pp3 -j95 -Y -c in/
```

Fixed develop params, no auto anything:
`use_camera_wb=True`, `no_auto_bright=True`, `output_color=sRGB`, `output_bps=16`, `gamma=(2.222, 4.5)`.

This is worth flagging separately: if your photographers are currently applying the preset by hand, a checked-in `.pp3` (or an exported LUT) applied by CLI removes that step entirely and makes the develop bit-reproducible. That is the same bottleneck you were about to hire a person for.

**Colour accuracy:** shoot an X-Rite/ColorChecker card once per lighting setup, generate a camera profile, bake it into the `.pp3`. On cash-on-delivery, a garment that arrives a different colour than the photo is a refused parcel plus a return leg you pay for. Colour fidelity here is a margin line item, not an aesthetic one.

---

## 6. Batch engine

One API call per image, as you specified — so the queue does the heavy lifting.

```
Job  = { batch_id, created_by, provider, model_snapshot, prompt_version,
         reference_set_hash, budget_cap_usd, status }
Item = { job_id, source_sha256, barcode, view, template_id,
         idempotency_key, attempts, status, cost_usd, output_keys[] }
```

- `idempotency_key = sha256(source_sha256 + prompt_version + model_snapshot + template_id)`. Same input, same settings → never billed twice, even if the batch is re-run after a crash.
- Concurrency cap per provider (start at 4). Both providers rate-limit by TPM and RPM, not just RPS.
- Retries: 3 attempts, exponential backoff with jitter, on 429/5xx/timeout only. Never retry a content-policy refusal — route it to the manual queue.
- **Budget cap per job.** A stuck retry loop on 800 SKUs at high quality is a real bill. Hard-stop the job when `sum(cost_usd) > budget_cap_usd`.
- Partial failure is normal: a batch completes with `succeeded / failed / quarantined` counts and a resumable failed-only re-run.
- Stream files; never load a 1,000-file batch into memory.

---

## 7. Security

| Area | Requirement |
|---|---|
| API keys | Server-side env vars only. Never in the React bundle, never in a client fetch. If the browser can see the key, the tool is not secure regardless of what else you do. |
| Auth | Directus JWT / session. Role-gated: `photo_operator` can upload and generate; `photo_lead` can approve and publish; nobody else. |
| Upload | Presigned PUT to Backblaze B2 (`n8n-data-mazyoud`). Private bucket. Time-limited signed GETs for the UI, never public URLs. |
| File validation | Verify magic bytes, not the extension. CR3 = ISO-BMFF `ftyp` with brand `crx `. Cap file size (Canon CR3 runs 25–70 MB). Reject archives and anything else. |
| Prompt injection | Never concatenate operator free text into the system prompt. Only enum-bound slots (category, colour family, view, template). |
| Metadata hygiene | Strip GPS, serial number, owner name and lens data from exported JPEGs. `exiftool -all= -tagsfromfile @ -icc_profile`. Keep the ICC profile, drop everything else. |
| Cost abuse | Per-user daily image quota. Budget cap per job. Alert on anomalous spend. |
| Audit | Log who generated what, with which model snapshot and prompt version, and who approved it. |
| Provenance | Gemini output carries a SynthID watermark; OpenAI output carries C2PA metadata. Both persist. Know this before you publish AI-touched images as product photography, and be deliberate about which templates go through AI at all. |

---

## 8. QA gates (automatic, before anything reaches the approval queue)

Reject and re-queue on any failure:

1. **Dimensions** exactly 2400×2800 and 2400×2400. (Catches the ignored-aspect-ratio bug.)
2. **Background uniformity** — sample 8 corner/edge patches; std-dev below threshold, mean within ΔE 2 of `#EDEAE5`.
3. **Fill ratio** — garment bbox height within ±2% of target. Catches drift instantly.
4. **Edge clearance** — zero non-background pixels within 40px of any edge, in *both* crops.
5. **Colour fidelity** — mean ΔE2000 between the source cut-out and the output, sampled over the garment mask, ≤ 3.0. This is the single most valuable gate you have.
6. **Structure** — SSIM between source cut-out and output over the garment region ≥ 0.85. Catches added pockets, changed button counts, redrawn prints.
7. **No new text** — OCR the output; if it finds legible text absent from the source, reject.

Anything that passes all seven goes to a human queue that is keyboard-only: `J` next, `A` approve, `R` reject-with-reason. At 6,000 SKUs, a two-click review UI costs you weeks.

---

## 9. Edge cases

### Input / RAW
- **C-RAW (lossy) and dual-pixel CR3** decode differently; some LibRaw builds choke. Fall back to the embedded JPEG preview and mark the asset lower-quality.
- **HDR PQ / HEIF-flavoured CR3** — detect and reject rather than silently producing washed-out output.
- **EXIF orientation** — auto-rotate before anything else, or half your batch is sideways.
- **Someone uploads JPEG/HEIC instead of CR3.** Accept it, but tag the asset as non-RAW so you know why its colour is off later.
- **Non-ASCII filenames** (Arabic, accented French) — normalise on ingest; never let them reach the storage key.

### Segmentation
- **White or cream garment on a white sweep** — matting collapses. Shoot light garments on a mid-grey sweep; enforce it at the shoot, not in software.
- **Tulle, lace, mesh, chiffon** — semi-transparent alpha. Standard rembg produces a hard, ugly edge. Use a trimap-based matting model for these categories, flagged by fabric field.
- **Multi-piece sets** (your reference photo is a 3-piece: vest, tee, shorts) — the bbox must cover the *whole set* as one object. Naive largest-component selection will grab the vest and drop the shorts. Union all components above a size threshold.
- **Hanger hook** clipped out of the bbox but kept in pixels (see §1).
- **Dark garment on dark shadow** — segmentation eats the shadow side of the garment.

### Model behaviour
- **Content-policy refusal** on children's apparel. Detect the refusal shape (empty image, or a text-only response), do not retry blindly, route to manual. Track the refusal rate per template — if one template refuses 5%+ of the time, its prompt has a trigger word in it.
- **Silent aspect-ratio non-compliance.** Assert dimensions; see gate 1.
- **Hallucinated garment features** — the classic failure. Gate 6 catches it.
- **Colour drift** — models routinely warm up neutrals. Gate 5 catches it. If drift is systematic, correct it post-hoc with a fixed LUT rather than by re-prompting.
- **Model deprecation mid-project.** `gpt-image-1` retires Oct 2026; Imagen retires Aug 2026. Snapshot pinning means a deprecation is a scheduled migration, not a surprise inconsistency across your catalogue.
- **Reference set swapped by someone.** Hash the reference images; if the hash changes, that's a new `reference_set_hash` and a new visual generation — surface it loudly in the UI, don't let it happen quietly.

### Barcode
- **Check-digit failure** → never auto-correct, always ask.
- **Supplier barcode on the garment's own label** decoded instead of your SKU card → whitelist your barcode prefix range; reject anything outside it.
- **Barcode card visible in the product frame** — it must be in its *own* frame, or you'll be retouching it out of 6,000 images.
- **Reused/recycled barcode across seasons** → the "already has approved assets" check is the guard.
- **Batch where the first frame's card failed to decode** — every subsequent frame inherits a wrong barcode. Require an explicit card frame per sequence and fail the whole sequence if it doesn't decode, rather than propagating from the previous sequence.

### Business
- **AI-modified images vs. what ships.** On cash-on-delivery, refusal at the door costs you the outbound and return leg. Any template where the AI can alter the garment (not just the background) should be treated as a returns risk and reviewed accordingly.
- **Cost per SKU.** At 3 views × 2 crops × 6,000 SKUs you are in five-figure-image territory. The deterministic composite path (§0) is what makes the numbers work; the AI path should be the exception you opt into per template.

---

## 10. Build order

1. CR3 → develop → cut-out → deterministic composite → 6:7 + 1:1 → barcode naming → B2. **No AI at all.** Ship this. It solves 70% of the problem at near-zero marginal cost.
2. Barcode card detection + propagation, manual fallback UI.
3. QA gates 1–4 (pure geometry — cheap, catch most failures).
4. Provider abstraction + one AI template (hanger/cleanup), behind a flag.
5. QA gates 5–7 (ΔE, SSIM, OCR).
6. Batch queue, idempotency, budget caps, resumable failures.
7. Approval UI, then the remaining templates.

Do not build 4 before 1. Every hour spent on prompt tuning before the deterministic geometry exists is an hour spent chasing a variance you were about to eliminate anyway.
