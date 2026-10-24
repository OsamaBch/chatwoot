# Mazyoud Image Align — guide for the shop team

Makes every hanger photo sit the same way in the catalogue grid: the hanger ball is always
**50 px** from the top, the garment is centred with clean space on all sides, and bigger
garments (sets, dresses) look bigger than small ones (t-shirts).

Nothing happens automatically. You choose the garment type and press **Align**.

## Where the buttons are

| Place | What you see |
|---|---|
| **Media → Library (list view)** | An **Align** column with a type dropdown, the Align button, **Revert** (once aligned) and a ⚠ badge if the result should be checked. Tick several images and use **Bulk actions → Align as: …**. |
| **Media modal / attachment details** | Same controls under the image fields, plus the **History** of that image. |
| **Product edit screen → Product gallery** | Hover a gallery image (tap on a tablet) → dropdown + **Align** + **↺** revert. |
| **Product edit screen → Product image** | Dropdown + **Align** + **Revert** under the featured image. |

Clicking **Align** opens a **before / after preview**. Check it, then **Apply**.
(Hold **Shift** while clicking to skip the preview.)

## Garment types

The type sets how much of the frame the garment fills — this is what makes a set look bigger than a t-shirt in the grid.

| Type | Fill | Use for |
|---|---|---|
| Set (2–3 pieces) | 96 % | Full outfits on one hanger |
| Robe / dress / jumpsuit | 93 % | Long one-piece garments |
| Coat / long jacket | 93 % | Coats, parkas, long jackets |
| Jacket / hoodie | 88 % | Short jackets, hoodies, cardigans |
| Jeans / trousers | 85 % | Trousers, leggings, skirts |
| T-shirt / top / shirt | 78 % | Tops, shirts, blouses |
| Default | 88 % | When unsure |
| ✕ Not a hanger photo | — | **Mannequin, shoes, details, packshots.** Marks the image so it can never be aligned, even by a bulk action. Choose a real type again to unmark. |

Wide garments (puffer jackets, sleeves out) are automatically made a little smaller so the
sleeves never touch the edge — that's expected, and the ⚠ badge will say "Garment is wide".

## Redo, revert, backups

- **Redo**: pick another type and Align again. The plugin always restarts from the untouched
  original, so quality never degrades however many times you redo.
- **Revert**: puts the original photo back exactly as uploaded (same format), regenerates the
  thumbnails and clears the alignment. Available everywhere the Align button is, and as a bulk action.
- The original is kept once next to the file as `name-orig.ext`. It is removed automatically when the
  image is deleted from the Media Library.

## Bulk

Select images in the list view, choose **Align as: <type>** (or *mark as not a hanger photo* / *revert*).
Small selections run immediately. Larger ones are processed in the background; a progress bar at the
top of the Media Library shows ok / failed / flagged counts, with Cancel and Dismiss. You can leave the page.

## The ⚠ badge

The plugin aligned the image but something looks unusual — hover the badge to read why:
tight original framing, garment very small or very large in the frame, uneven background,
hanger ball found with medium confidence, source too small and enlargement capped. Open the
image, check the result, redo with another type or revert.

## When Align refuses

- **"No hanger ball detected"** — the photo is not a hanger photo (mannequin, shoes, flat lay) or the
  hanger is far off-centre / hidden. Mark it *Not a hanger photo*, or re-shoot.
- **"Excluded"** — the image is marked *Not a hanger photo*; pick a type to unmark.
- **"Not enough PHP memory"** — ask the developer to raise `memory_limit` (the settings page shows the need).

## Settings (Settings → Image Align, administrators)

Canvas size (2194 × 2560, 6:7), margins (top 50, bottom 60, sides 60), detection tuning, JPEG quality
(never below 92), bulk batch sizes, the fill % per type, preview on/off, keep hanger hook, require ball.

## For developers

```
php tests/run.php --src=/path/to/photos     # engine checks on real photos (GD or --engine=imagick)
php tests/run.php                           # synthetic photos
php tests/smoke-wp.php                      # file layer against WP stubs
```

Actions: `maz_ia_aligned`, `maz_ia_reverted`, `maz_ia_purge`. Filter: `maz_ia_generated_files`.
Requires PHP 7.4+, GD or Imagick. No Composer, no external services.
