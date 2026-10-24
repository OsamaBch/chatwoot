# Hanger-photo alignment — algorithm specification

Language-agnostic description of what `includes/class-maz-ia-engine.php` does, so the same
behaviour can be implemented in any app (Python/Pillow+NumPy, Node/sharp, Swift, etc.).
Input: one photo of a garment on a hanger, flat light-grey studio background.
Output: a JPEG of fixed size where the top of the hanger ball is always at the same height,
the garment is centred, sized by garment type, and never clipped.

## 1. Parameters (defaults)

| Name | Default | Meaning |
|---|---|---|
| `canvas_w`, `canvas_h` | 2194, 2560 | Output size (6:7). |
| `top_margin` | 50 | px from the top edge to the top of the hanger ball. |
| `bottom_min` | 60 | px always free under the hem. |
| `side_min` | 60 | px always free left/right of the garment. |
| `fill` (per garment type) | set 96, robe 93, coat 93, jacket 88, pants 85, tshirt 78, default 88 | % of the usable height the garment+hanger occupies. Chosen by a human. |
| `color_delta` | 12 | A pixel is *subject* when any RGB channel differs from the background by ≥ this. |
| `ring_pct` | 2 | Background is sampled from a border band this % of the short side wide. |
| `ball_min_pct` | 2 | Min width of the ball (% of image width). Thinner dark things (hook wire, threads) are ignored. |
| `ball_max_pct` | 14 | Wider dark shape at the top ⇒ not a ball (mannequin shoulders, shoe) ⇒ refuse. |
| `ball_band_pct` | 70 | The ball must lie inside the central 70 % of the width. |
| `ball_threshold` | 250 | Pixels brighter (luminance) than this are never ball. |
| `min_dark_px` | 3 | Subject pixels a row/column needs to count (noise filter, full-res px). |
| `edge_pad` | 4 | Ignore pixels this close to the source border. |
| `max_upscale` | 1.25 | Never enlarge the subject more than this. |
| `analysis_max` | 700 | Long side of the reduced copy used for detection. |
| `keep_hook` | on | Keep what is above the ball if it fits inside `top_margin`. |
| `require_ball` | on | Refuse when no confident ball is found. |
| `quality` | 92 | JPEG quality (one encode). |

Derived: `usable_h = canvas_h − top_margin − bottom_min`, `fill_h = usable_h × fill / 100`.

## 2. Pipeline

```
process(src, fill):
  1. load image, apply EXIF orientation, convert to sRGB (W×H)
  2. A = downscale to analysis size            (long side ≤ analysis_max; f = W / A.width)
  3. bg = sample_background(A)
  4. mask, rows, cols, runs = subject_scan(A, bg)
  5. ball = find_ball(runs)                     → y, confidence (high|medium|none)
     if confidence == none and require_ball: REFUSE ("not a hanger photo")
  6. bbox = (x0, y0=ball.y, x1, y1) in analysis px; flags = plausibility(bbox, ball)
  7. map bbox to full res with a safety margin; refine ball row at full res
  8. geometry: scale, placement, crop rectangle
  9. render: canvas filled with bg, paste resampled crop, encode JPEG
  return {bbox, ball, bg, scale, flags}
```

### 2.1 Background colour (`sample_background`)
- Take every *opaque* pixel in the border ring (`ring = round(min(w,h) × ring_pct/100)`, min 2 px).
- `m = per-channel median` of those pixels.
- Drop pixels whose max-channel distance from `m` is > 12 (shadows, props, stickers in the corners).
- `bg = per-channel median` of the remaining pixels. If > 35 % were dropped, flag `bg_noisy`.
- If the ring has < 16 opaque pixels (transparent PNG), `bg = (247,247,247)`, flag `no_bg_ring`.

### 2.2 Subject scan
For each pixel inside the pad (`pad = ceil(edge_pad / f)`):
```
d    = max(|r−bg.r|, |g−bg.g|, |b−bg.b|)
subj = alpha ≥ 128 and d ≥ color_delta
```
- `rows[y]` / `cols[x]` = count of subject pixels.
- In the centre band `[w×(50−band/2)% , w×(50+band/2)%]`, per row, find the **longest contiguous run**
  of pixels that are subject **and** `luminance < ball_threshold` (lum = 0.299r+0.587g+0.114b).
  Keep `runs[y] = (width, x0, x1)` when width ≥ `bwmin = round(w × ball_min_pct/100)` (min 3).
- `x0` = first column with `cols > min_dark`, `x1` = last + 1; `y1` = last row with `rows > min_dark` + 1;
  `y_first` = first such row. `min_dark = round(min_dark_px / f)` (min 1).

### 2.3 Hanger ball (`find_ball`)
Walk `runs` from the top. For each candidate row `by` with run `(bw, rx0, rx1)`:
- Count `persist` = consecutive rows below whose run overlaps the candidate's (extend `rx0/rx1` as you go),
  up to `K = bwmin × 4` rows. Judge `maxw` = widest run **within the first `max(3, bw)` rows only**
  (below the ball come the hanger shoulders — don't let them count).
- `maxw > bwmax (= w × ball_max_pct/100)` → the top shape is too wide: confidence **none**, stop.
- `persist ≥ max(2, round(bw × 0.5))` → **high** (a round ball keeps its width for about half its diameter), stop.
- Otherwise remember the first candidate as **medium** (very flat shape, e.g. a hook arch),
  skip `persist` rows and continue looking for a *high* one further down.

Result: `ball.y` = top row of the chosen run, `ball.x0/x1`, confidence. A hook wire is rejected because its
arch is narrower than `bwmin` or does not persist; a lit light-orange ball is still found because the test
is colour distance, not darkness.

### 2.4 Plausibility flags (never block, shown to the user)
`touches_edge` (bbox within pad of the source border) · `bbox_small` (bbox_h < 35 % of h) ·
`bbox_large` (> 98 %) · `aspect_odd` (bbox w/h < 0.25 or > 2.0) · `ball_medium` ·
`content_above_ball` (`y_first` more than 20 % of h above the ball) · `bg_noisy` · `no_bg_ring`.

### 2.5 Back to full resolution
`s = ceil(f) + 1` (safety so nothing thin is lost at the reduced scale):
```
X0 = max(0, floor(x0·f) − s)   X1 = min(W, ceil(x1·f) + s)   Y1 = min(H, ceil(y1·f) + s)
```
Ball row refinement: read the full-res strip around the ball (`rows (ball.y−1)·f … (ball.y+2)·f`,
`cols ball.x0·f−s … ball.x1·f+s`) and take the **first row having a run ≥ need** of pixels that are
subject (colour distance) and `lum < ball_threshold`. Two passes:
pass 1 with `need = W × ball_min_pct/100`; compute geometry; pass 2 with
`need = canvas_w × ball_min_pct/100 / scale` (the "top of the ball" is then defined in *output* pixels, so it
lands identically whatever the source scale). Skip pass 2 if `need ≥ 0.8 × ball diameter` (tiny source).
`Y0` = refined ball row.

### 2.6 Geometry
```
bw = X1 − X0 ; bh = Y1 − Y0
scale = min( fill_h / bh,                      # size by garment type
             (canvas_w − 2·side_min) / bw,     # never touch the sides   → flag width_limited if this wins
             usable_h / bh )                   # never touch the bottom
if scale > max_upscale: scale = max_upscale ; flag upscale_capped
nw = round(bw·scale) ; nh = round(bh·scale)
dx = round((canvas_w − nw) / 2) ; dy = top_margin
```
Crop rectangle = bbox, extended **upward only** by `et = min(Y0, floor(dy / scale))` source px when
`keep_hook` is on (else `min(Y0, ceil(6/scale))`), so the hook above the ball stays and the ball's round top
is never shaved. Sides and bottom are *not* extended: those margins are clean canvas background
(no floor shadow, no props).
```
src = (X0, Y0 − et, bw, bh + et)
dst = (dx, dy − round(et·scale), nw, nh + round(et·scale))
```

### 2.7 Render
- Canvas `canvas_w × canvas_h` filled with `bg`.
- If the source has transparency, composite it over `bg` (not white).
- Resample `src → dst` with a high-quality filter (Lanczos / bicubic).
- Encode JPEG, quality ≥ 92, progressive, strip metadata/profiles (output is plain sRGB).
- Keep the output the full-size image (no further "scaled" copy).

## 3. Invariants the result must satisfy (what the tests check)
1. Output size = `canvas_w × canvas_h`.
2. Top of the ball (first row with a run ≥ 2 % of width of ball-coloured pixels inside the band) = `top_margin ± 1`.
3. No subject pixel within `side_min` of the left/right edges or `bottom_min` of the bottom.
4. Set (96 %) renders taller than T-shirt (78 %) on the same source unless width-limited or upscale-capped.
5. JPEG quality ≥ 92; redo always re-renders from the pristine original (never from a previous output).

## 4. File handling around the engine (what a host app should do)
- First alignment: copy the original once to `name-orig.ext` (pristine backup). Redo reads the backup.
- Replace the live file in place (always `.jpg`), regenerate every derived size, delete old derived files
  and any WebP/AVIF copies (next to the file or in a converter's folder), purge page/CDN caches of the old URLs.
- Revert = move the backup back, regenerate sizes, clear the alignment state.
- Delete the backup when the image itself is deleted.
- Record per image: timestamp, type, engine, settings hash, ball confidence, flags.
- Never auto-run on upload; never process an image marked "not a hanger photo"; refuse when no ball.
- Bound memory (≈ `(W·H + canvas_w·canvas_h) × 5 bytes` × 2 + 12 MB) and run bulk work in a queue.
