# Changelog — Mazyoud Image Align

## 2.0.1 — 2026-10-24

- **Fix: light-coloured hanger balls were cut at the top.** The ball anchor used a luminance cut
  (`ball_threshold` 205); the lit top half of a light orange / beech ball is brighter than that, so the
  "top of the ball" was found mid-ball and the crop started there. The ball is now found with the same
  colour-distance mask as the garment (orange vs grey), in the analysis pass and in the full-resolution
  refinement; `ball_threshold` only excludes pixels brighter than 250 now. Saved values ≤ 205 are migrated.
  New synthetic test case `f5-light-ball`.
- Preview header shows whether the render started from the pristine backup or from the current file
  (images aligned by 1.2.0 without a backup have no pristine original: the current file is backed up and
  used as the source, so a redo looks the same as before).

## 2.0.0 — 2026-10-24

Full review and hardening of 1.2.0. The plugin is now split into an image engine with no
WordPress dependency (`includes/class-maz-ia-engine.php`, testable from the CLI), a file layer
(`class-maz-ia-files.php`), settings, a background queue and the admin UI. Same slug, same
option names, settings from 1.2.0 are migrated (removed keys dropped).

Numbers refer to the review brief.

### A. Cropping / clipping

| # | Status | What changed |
|---|---|---|
| 1 | Fixed | New `side_min` setting (default 60 px). Scale is now `min(fill_h / bbox_h, (canvas_w − 2·side_min) / bbox_w, usable_h / bbox_h)` — the garment can never touch the sides or the bottom. A width-limited image gets the `width_limited` flag. |
| 2 | Fixed | Subject mask is a per-channel colour distance from the sampled background (`color_delta`, default 12, in settings) instead of a luminance cut, so white / cream / pastel garments on F5 are fully detected. Detection runs on a reduced copy and the bbox is mapped back with a safety margin of one analysis pixel + 1, so nothing thin is lost. Plausibility flags (`touches_edge`, `bbox_small` < 35 %, `bbox_large` > 98 %, `aspect_odd`) are stored in `_maz_flags`, shown as a "⚠ review" badge with the reasons on hover, listed in the preview modal and counted in bulk jobs. |
| 3 | Fixed | Background = per-channel median of a border ring (`ring_pct`, default 2 % of the short side) with outlier rejection (pixels further than 12 from the first median are dropped, median recomputed). More than 35 % rejected → `bg_noisy` flag. Transparent pixels are never sampled. |
| 4 | Fixed | Ball search band is a setting (`ball_band_pct`, default 70 % centred). The ball is the topmost dark run that **keeps its width for ≥ half its width in rows** (a hook wire does not), judged on its top rows only. Confidence `high` / `medium` / `none` is returned, saved in the history and shown in the preview. There is no silent fallback any more (see 28). |
| 5 | Fixed | Background is sampled from opaque pixels first; transparency is then flattened onto that colour (GD: composited straight onto the filled canvas; Imagick: `mergeImageLayers` with the sampled background). |

### B. Quality

| # | Status | What changed |
|---|---|---|
| 6 | Fixed | `max_upscale` setting (default 125 %). Scale is capped and the image flagged `upscale_capped`. |
| 7 | Decided | Output is always JPEG (sRGB, progressive, metadata stripped, quality ≥ 92). PNG / WebP / GIF / BMP / AVIF sources are converted; the pristine backup keeps the original format and "Revert" restores it, extension included. Rationale: the catalogue is photographic, one format keeps WP Rocket / CDN / thumbnail behaviour predictable. |
| 8 | Fixed | Imagick: `autoOrient()` (manual rotate/flop fallback) runs **before** the scan, then the orientation tag is reset. Non-sRGB images (CMYK, gray, embedded AdobeRGB) are converted with `transformImageColorspace(SRGB)` before scanning; profiles are stripped from the output (plain sRGB JPEG). GD: EXIF orientation is applied via `exif_read_data` + rotate/flip (GD never read profiles — unchanged). Quality cannot be set below 92 (settings sanitiser + runtime clamp). |

### C. File hygiene / duplicates

| # | Status | What changed |
|---|---|---|
| 9 | Removed | `mode=new` is gone. Replace is the only mode; redo always re-renders from the pristine backup, never from a previous output. |
| 10 | Fixed | `delete_attachment` hook removes the backup. "Revert to original" (button in the Media Library, media modal, product gallery overlay, featured-image box, and a bulk action) moves the backup back, restores mime + extension, regenerates sizes and clears `_maz_aligned` / `_maz_orig_file` / `_maz_flags`. Backup is mandatory now (`keep_backup` removed) because lossless redo depends on it. Backup path is stored relative to the uploads folder. An untracked `name-orig.ext` left by an earlier install is adopted (never duplicated) unless another attachment owns it. |
| 11 | Fixed + documented | Before replacing, every size in the metadata and the `-scaled` original are deleted, plus `file.webp`, `file.avif`, `file.ext.webp`, `file.ext.avif` sidecars written by converter plugins (Imagify, EWWW, ShortPixel, WebP Express conventions). Other plugins can add paths through the `maz_ia_generated_files` filter. The backup is never deleted by this step. `big_image_size_threshold` is disabled while regenerating so the aligned file stays the full-size image (no `-scaled` copy). |
| 12 | Fixed | 1.2.0 appended `.jpg` to the `wp_tempnam()` name, leaving the original empty temp file behind on every run. The temp file is now used as-is (Imagick writes with the `jpeg:` prefix) and moved with `rename()`, falling back to `copy()` + `unlink()` across filesystems; the temp file is removed on every error path. Verified by the test runner (temp dir compared before/after). |
| 13 | Decided | Extension change (png → jpg) is kept because the output is JPEG. Purge strategy: `rocket_clean_files()` is called with the old URL, the new URL and every removed size URL, `rocket_clean_post()` on the parent product, and the `maz_ia_purge` action lets other caches/CDNs hook in. The admin `?v=` buster stays for the admin thumbnails only. |

### D. Performance

| # | Status | What changed |
|---|---|---|
| 14 | Fixed | Bulk actions on more than `sync_limit` images (default 4) create a job processed by **Action Scheduler** (`maz-image-align` group) when WooCommerce provides it, otherwise a WP-Cron single event. Each run processes up to `batch_size` images (default 4) or 60 % of `max_execution_time`, saves progress after every image (resumable), and reschedules itself. Progress bar with ok / failed / flagged counts, per-image error list, Cancel and Dismiss in the Media Library. Stalled jobs (no progress for 5 min) are re-kicked when the page polls. Jobs run as the user who queued them, so per-attachment capability checks still apply. |
| 15 | Fixed | Detection runs on a ≤ 700 px analysis copy made in C (`imagescale` / `scaleImage`), i.e. ~0.5 M pixel reads instead of 5.6 M. Ball row refinement reads only a small full-res strip. Full-size work is `imagecopyresampled` / `resizeImage` (C). Measured GD: ~0.5 s for 1200 px sources, ~0.8 s for 2194×2560 on this machine. |
| 16 | Fixed | `Maz_IA_Engine::memory_check()` estimates the need (source + canvas + analysis, ×5 bytes/px, +12 MB) against `memory_limit` after `wp_raise_memory_limit('image')`, and returns a clear error ("Not enough PHP memory: image 2194×2560 needs ~70 MB, 40 MB available") instead of a white screen. The settings page shows the estimate. |
| 17 | Fixed | Product screen: `update_meta_cache('post', $ids)` primes every gallery image in one query; one `state()` call per image afterwards. |

### E. Security

| # | Status | What changed |
|---|---|---|
| 18 | Fixed | `Maz_IA_Files::user_can($id)` = `upload_files` **and** `edit_post` on that attachment. Used by every AJAX endpoint, by the bulk handler (ids that fail are dropped), by the queue (runs as the queuing user), and to decide whether controls are rendered at all. |
| 19 | Fixed | `_maz_orig_file` is stored relative to `wp_upload_dir()['basedir']`; `resolve_inside()` `realpath()`s it and refuses anything outside the uploads folder (legacy absolute values are migrated if valid, dropped otherwise). Covered by the test runner and the smoke test. |
| 20 | Verified | Both option groups are registered under one settings group with `settings_fields()`; sanitisers check `manage_options`, clamp every integer to a range, force quality ≥ 92 and reject margins that leave no room. Job ids are `[a-f0-9]` only. |
| 21 | Kept | `get_post_type() === 'attachment'` + `wp_attachment_is_image()` in the AJAX guard and in the file layer. |
| 22 | Fixed | Settings page rebuilt: every attribute `esc_attr`, every text `esc_html`, numbers cast. Admin fragments are built from escaped parts. |

### F. UX

| # | Status | What changed |
|---|---|---|
| 23 | Added | Before / after preview modal (original vs reduced render, ball confidence, scale, engine time, warnings) with Apply / Cancel for every single-image button. Preview renders to a temp file that is deleted immediately and touches nothing. Setting "Show a preview before applying" (on by default); Shift-click skips it. Bulk stays direct. |
| 24 | Added | Per-image history in `_maz_history` (date, user, action, type, engine, ms, settings hash, ball confidence, flags; last 20) shown in the attachment details, plus the Revert button everywhere. |
| 25 | Fixed | Product gallery overlay is shown on hover, on keyboard focus, always on `(hover: none)` devices, and after a tap on the tile. |
| 26 | Partly | Bulk list still has one entry per type (types are not user-editable) plus "mark as not a hanger photo" and "revert to original". |
| 27 | Not done | Grid view still uses the details panel (optional item). |

### G. Scope guards

| # | Status | What changed |
|---|---|---|
| 28 | Fixed | "✕ Not a hanger photo" option in every type dropdown (+ bulk action) sets `_maz_exclude`; excluded images are refused by the engine entrypoint, whatever the caller. With "Require ball" on (default) an image without a confident hanger ball is refused with the reason ("top dark shape is 41 % of the width — too wide for a hanger ball") and nothing is written. Picking a real type again un-excludes. |

### Other

- `tests/run.php`: CLI runner with synthetic photos (E7/F5 backgrounds, white garment, off-centre hanger, wide puffer, PNG alpha, 2194×2560 with floor shadow, small source, mannequin and shoe that must be refused). Asserts ball row = `top_margin` ± 1, no subject pixel in the side/bottom margins, canvas size, JPEG quality from the quantisation tables, no temp leftovers, exactly one backup, path validation.
- `tests/smoke-wp.php`: align → redo → preview → exclude → revert → delete against WordPress stubs (no database needed).
- Hanger hook: what sits above the ball inside the top margin is kept (`keep_hook`, default on) so the hook is not cut.
- Removed settings: `threshold`, `bg_delta` (replaced by `color_delta`), `mode`, `keep_backup`. New: `side_min`, `color_delta`, `ball_max_pct`, `ball_band_pct`, `ring_pct`, `max_upscale`, `analysis_max`, `keep_hook`, `require_ball`, `preview`, `sync_limit`, `batch_size`.
- Hooks for integrators: `maz_ia_aligned($id, $file, $result)`, `maz_ia_reverted($id, $file)`, `maz_ia_purge($id, $urls, $parent)`, filter `maz_ia_generated_files`.

## 1.2.0

Initial production version (single file).
