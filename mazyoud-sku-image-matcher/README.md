# Mazyoud SKU Image Matcher — developer documentation

Attaches WordPress Media Library images to WooCommerce products by parsing the
SKU (or product ID) from the image filename. Built for high-traffic production
stores: dry-run first, background processing via Action Scheduler, snapshot +
rollback, zero frontend footprint.

- Requires: WordPress 6.5+, WooCommerce 8+, PHP 8.1+
- Namespace: `Mazyoud\SkuImageMatcher` (PSR-4 style autoloader, no Composer
  dependency at runtime)
- Admin UI: **WooCommerce → SKU Image Matcher** (capability
  `manage_woocommerce`)
- Logs: **WooCommerce → Status → Logs**, source `msim`

---

## 1. Filename → SKU convention

| Filename | Result |
| --- | --- |
| `AB1234.jpg` | main (featured) image of SKU `AB1234` |
| `AB1234-1.jpg` / `AB1234_1.jpg` / `AB1234 1.jpg` | gallery position 1 |
| `AB1234-2.jpg` | gallery position 2 (positions sort ascending) |
| `AB1234 robe fille rouge-2.jpg` | gallery position 2 — extra words after the SKU are allowed |
| `ab1234.JPG` | matching is **case-insensitive** |
| `AB1234-123.jpg` | main image of SKU `AB1234-123` — the position suffix is **1–2 digits max**; longer digit runs belong to the identifier |
| `4821.jpg` | if no SKU `4821` exists: main image of **product ID** 4821 |
| `4821-2.jpg` | gallery position 2 of product ID 4821 |

Accepted separators between SKU, descriptive words and position: hyphen `-`,
underscore `_`, space ` `.

### Resolution order per file

1. **Exact full-name SKU** (interpretation ①) — the whole remaining name is
   tried as a SKU first, both before and after stripping WordPress-generated
   suffixes. This protects SKUs that end in digits (`AB12-3.jpg` where SKU
   `AB12-3` exists → main image) and SKUs that look like size suffixes
   (`MAT-100x200`).
2. **Trailing position + longest-prefix SKU match** (interpretation ②) — the
   trailing 1–2 digit position is extracted, then the remainder is resolved
   against the real SKU set: try the full remainder, then progressively strip
   trailing tokens until a known SKU matches
   (`AB1234 robe fille rouge` → `AB1234 robe fille` → `AB1234` ✓).
   Only existing SKUs can match → zero false positives, and SKUs that
   themselves contain separators always win via the longest matching prefix.
3. **Product ID fallback** — only when no SKU matched anywhere and the leading
   token is purely numeric. Matches only if a product with that ID exists
   (post type `product` — variation IDs never match). **SKU always wins over
   ID**; when a numeric SKU matches while a different product carries that ID,
   an informational warning is emitted.

If both interpretations ① and ② resolve to existing SKUs, an **ambiguity
warning** is emitted and interpretation ① (main image) is preferred.

The scan report shows, per product, which identifier type matched
(`SKU …` / `ID …`).

### Post statuses

Products in `publish` **and** `draft` are matchable (images often arrive
before publication); trash is always excluded. This applies to both the SKU
and the product-ID paths.

---

## 2. Edge cases the plugin handles

| # | Case | Behavior |
| --- | --- | --- |
| 1 | Two bare-identifier files for one SKU (`SKU.jpg` + `SKU.png`) | Most recent upload becomes featured, the other is prepended to the gallery. Warning. |
| 2 | No bare-identifier file (only `SKU-1`, `SKU-3`) | Lowest-numbered file promoted to featured (a matched product never ends up without a featured image). Warning. |
| 3 | Duplicate position (two `SKU-2` files) | Most recent wins, the other is skipped. Warning. |
| 4 | SKU ending in a number (`AB12-3.jpg`) | Interpretation ① then ②, ambiguity warning when both resolve (see above). |
| 5 | **WordPress duplicate-upload renaming** | See below — flagged, never silently assigned. |
| 6 | SKUs with special characters | The lookup set contains both the raw lowercased SKU and its `sanitize_file_name()` transform, so `AB É 12` still matches the uploaded `AB-E-12.jpg`. |
| 7 | Duplicate SKU across products (dirty data) | Hard error in the report. The plugin **never guesses**. |
| 8 | Numeric SKU equal to another product's ID | SKU wins; informational warning. |
| 9 | Draft products | Matchable (see post statuses). |
| 10 | Filename resolves to a **variation** SKU | Reported in a dedicated "variation SKU — planned for v2" category, never assigned, distinct from "not found". |
| 11 | Numeric token equals a variation ID | Never matches — product-ID matching is restricted to post type `product`. |

### WordPress duplicate-upload renaming (important!)

When you upload a file whose name already exists in that month's uploads
folder, WordPress silently renames it by appending `-1`, `-2`, … Since the
naming convention also uses trailing numbers, a re-upload of `ABC-2.jpg`
becomes `ABC-2-1.jpg` — which would wrongly parse as *SKU `ABC-2`,
gallery position 1*.

Detection: the attachment `post_title` preserves the original upload name
while `_wp_attached_file` holds the renamed file. When the stored basename
differs from the (file-sanitized) title only by a trailing `-N`, the file is
flagged as a **duplicate-rename warning** in the scan report and excluded
from the plan. The auto-attach listener applies the same check and logs a
warning instead of assigning.

Recommendation: delete the accidental duplicate from the Media Library, or
rename the file properly and re-upload.

---

## 3. Workflow

1. **Settings** (same screen): existing-images mode (Skip / Replace / Merge),
   only-unattached toggle, uploaded-since window (Today / Last 7 days),
   auto-attach toggle.
2. **Step 1 — Scan (dry run)**: builds the complete match plan, writes
   nothing. Report: matched products (+ identifier type, file counts,
   warnings), media whose SKU was not found (CSV export above 50 rows),
   skipped products, variation-SKU files, duplicate-SKU errors,
   duplicate-rename flags.
3. **Step 2 — Run**: enqueues the plan as Action Scheduler jobs (batches of
   25 products, each action bounded to ~15 s — longer batches re-enqueue
   themselves and resume). The page polls a lightweight summary endpoint
   every ~3 s; closing the tab does not stop processing.
4. **Rollback**: each run keeps a per-product snapshot (previous
   `_thumbnail_id`, `_product_image_gallery`, the previous `post_parent` of
   every touched attachment and the managed-marker state), written **before**
   any modification. "Rollback this run" restores everything via background
   jobs. Products manually edited *after* the run are flagged as conflicts
   and require an explicit "Force-restore" confirmation. The last **10 runs**
   are kept; older snapshots are purged automatically.

### Date window (deliberate safety constraint)

The scan never looks back more than 7 calendar days, evaluated in the
**site timezone** against `post_date` (`current_time()`-based cutoffs). A run
can therefore never touch the historical catalog's images — only recently
uploaded media. This limit is enforced server-side regardless of input.

### Concurrency, idempotency, recovery

- A run lock (`msim_active_run_id` option, acquired atomically via
  `add_option`) prevents two simultaneous runs; the UI disables Run while one
  is active.
- Per-product status lives on the snapshot row (`pending` → `done` /
  `failed` / `skipped`). Retried or re-enqueued jobs skip terminal products,
  and the write targets are recomputed from the stored snapshot — so a job
  killed mid-batch (timeout/OOM) is safely resumable and merge results stay
  deterministic.
- If Action Scheduler ends up with nothing queued while a run is marked
  running (e.g. a fatal killed a worker), the UI flags the run as stalled and
  offers **Recover (re-enqueue)** — idempotent by design.
- Failed products record the product ID and reason on the snapshot row, the
  run summary surfaces the failure count, and a per-run **Failures CSV** is
  available.
- In Replace mode, detached attachment IDs are never deleted; export them per
  run via the **Detached attachments CSV** for optional manual cleanup.

---

## 4. Auto-attach on upload

When enabled (default off), the `add_attachment` hook parses each newly
uploaded image and attaches it immediately:

- Single-file fast path: the full SKU set is **not** loaded; the filename's
  prefix candidates are resolved in **one** `IN()` query against
  `wc_product_meta_lookup` (longest match wins), with a product-ID existence
  check only when the leading token is numeric.
- The whole handler is wrapped in `try/catch` — a parse or DB failure can
  never break the media upload itself.
- No snapshot/undo for auto-attach events; every action is logged (`msim`
  source).
- While a manual run is active, uploads whose attachment ID is already part
  of the active plan are skipped.

Mode semantics for a single file (they cannot mirror bulk semantics exactly):

| Mode | Bare file (`SKU.jpg`) | Positioned file (`SKU-2.jpg`) |
| --- | --- | --- |
| Skip | Sets featured only if the slot is empty | Appends only to products without images, or whose images this plugin already manages (`_msim_managed` marker) — curated products stay untouched |
| Replace | Overwrites the featured image (gallery untouched — a single upload never wipes a gallery) | Appends if not present |
| Merge | Keeps an existing featured image and appends the file to the gallery instead | Appends if not present |

Known limitation of the single-query path: SKUs containing characters that
WordPress strips from filenames (accents, etc.) match in bulk scans (the scan
map carries `sanitize_file_name()` transforms) but may not match in the
instant auto-attach path; such files are picked up by the next manual scan.
Case-insensitivity on this path relies on MySQL's default `*_ci` collation
(the same assumption WooCommerce itself makes for SKU lookups).

For drip uploads, upload the bare `SKU.jpg` first (or simply run a manual
scan after a bulk upload — that path has full promotion/ordering logic).

---

## 5. Architecture

```
mazyoud-sku-image-matcher.php   Bootstrap: constants, autoloader, hooks, HPOS declaration
uninstall.php                   Clean uninstall (table, options, scheduled actions, marker meta)
assets/admin.{js,css}           Enqueued only on the plugin's own screen (filemtime versioning)
src/
  Plugin.php                    Context-aware wiring (admin / cron / frontend), service container
  Settings.php                  Sanitized settings (the only autoloaded option)
  Logger.php                    WC_Logger wrapper (source "msim"), never throws
  FilenameParser.php            Pure string parsing (unit-testable, no DB)
  ParsedFilename.php            Immutable parse result value object
  SkuResolver.php               All bulk SKU/ID SQL (lookup-table map + chunked IN() queries)
  MatchPlanner.php              Dry-run scan → plan + report
  Assigner.php                  Snapshot-first writes, rollback restore, auto-attach assignment
  SnapshotRepository.php        msim_snapshots table (dbDelta), per-product job status
  RunRepository.php             Run records, plan options, run lock, purge policy
  Queue.php                     Action Scheduler wrapper (batches, time budget, recovery)
  AdminPage.php                 Screen + AJAX endpoints (nonce + manage_woocommerce everywhere)
  AutoAttachListener.php        add_attachment handler (single-query fast path)
  Matching/
    MatcherInterface.php        The v2 extensibility seam
    MatchContext.php            SKU map + product-ID lookups
    MatchResult.php             Target object: product + role (+ warnings / errors)
    ParentProductSkuMatcher.php v1: longest-prefix parent-SKU matching
    ProductIdMatcher.php        v1: numeric product-ID fallback
```

### Performance design

- **SKU resolution is never per-file.** Bulk scans load the parent-product
  SKU set once per scan into an in-memory lowercase hash map (keyset-paginated
  indexed queries against `wc_product_meta_lookup`); matching is then pure
  hash lookups. This also guarantees case-insensitive matching independent of
  collation and enables the `sanitize_file_name()` transform keys. Candidate
  lists (auto-attach, variation classification) go through chunked,
  `$wpdb->prepare`d `IN()` queries (500 per chunk) against the indexed `sku`
  column.
- **Media scan**: one SQL shape joining `posts` + `postmeta` on
  `_wp_attached_file`, image mime types only, optional `post_parent = 0`,
  keyset-paginated in chunks of 5,000 rows.
- **Plans are written once** to a per-run option with `autoload = no`. The
  only autoloaded option is the small settings array. Progress polling is a
  single indexed `GROUP BY` on the snapshots table.
- **Batches**: 25 products per Action Scheduler action, ~15 s budget per
  action, post/meta caches primed per batch with `_prime_post_caches()`.
- **Zero frontend footprint**: every hook except `add_attachment` (only when
  auto-attach is enabled) is registered behind `is_admin()` — Action
  Scheduler job callbacks are additionally registered for cron/WP-CLI
  contexts, where its runner executes. Frontend page loads register nothing,
  enqueue nothing and query nothing. No wp-cron events are ever created.

### Cache correctness

After each product update the plugin calls
`wc_delete_product_transients( $product_id )` and
`clean_post_cache( $product_id )` (covers the post + meta groups in Redis /
object caches), then fires:

```php
do_action( 'msim_product_images_updated', $product_id );
```

Hook your page cache / CDN purge there, e.g.:

```php
add_action( 'msim_product_images_updated', function ( $product_id ) {
    if ( function_exists( 'do_action' ) ) {
        do_action( 'litespeed_purge_post', $product_id );          // LiteSpeed
        // or your Cloudflare/Varnish purge by URL:
        // my_cdn_purge( get_permalink( $product_id ) );
    }
} );
```

### Media-offload compatibility (S3 / B2 / CDN)

The plugin never touches files on disk: it only updates `post_parent` and the
`_thumbnail_id` / `_product_image_gallery` post meta through WordPress APIs.
Offloaded attachments keep working exactly as before — URLs continue to be
filtered by your offload plugin.

---

## 6. Extending the matcher pipeline (v2 variation swatches)

The SKU-resolution pipeline is pluggable. A matcher receives parsed filename
data and returns a target object (product ID + role) — or `null` to pass:

```php
use Mazyoud\SkuImageMatcher\Matching\MatcherInterface;
use Mazyoud\SkuImageMatcher\Matching\MatchContext;
use Mazyoud\SkuImageMatcher\Matching\MatchResult;
use Mazyoud\SkuImageMatcher\ParsedFilename;

class VariationSkuMatcher implements MatcherInterface {
    public function match( ParsedFilename $parsed, MatchContext $context ) {
        // Resolve $parsed->stem / $parsed->base_prefixes against variation
        // SKUs, return MatchResult::main( $variation_id, 'sku', $sku ) for a
        // swatch image, or null to let the next matcher try.
        return null;
    }
}

add_filter( 'msim_matchers', function ( array $matchers ) {
    // Run before the product-ID fallback but after the parent-SKU matcher:
    array_splice( $matchers, 1, 0, array( new VariationSkuMatcher() ) );
    return $matchers;
} );
```

Pipeline contract:

- matchers run in array order; the **first non-null** `MatchResult` wins;
- an **error result** (e.g. `MatchResult::duplicate_sku()`) stops the
  pipeline for that file — the file is reported, never assigned;
- the same filter applies to bulk scans and the auto-attach listener.

`ParsedFilename` gives you everything precomputed: `stem` (lowercased,
WP-suffix-stripped), `raw_stem` (pre-strip), `position`, `base`,
`base_prefixes` / `stem_prefixes` (longest-first candidate lists) and
`leading_token`.

## 7. Public hooks reference

| Hook | Type | Description |
| --- | --- | --- |
| `msim_matchers` | filter | Modify the matcher pipeline (see above). |
| `msim_product_images_updated` | action | Fires after a product's images change (run, rollback or auto-attach). Args: `int $product_id`. Use for page-cache/CDN purges. |

## 8. Database schema

`{$wpdb->prefix}msim_snapshots` — created on activation via `dbDelta`,
dropped on uninstall:

| Column | Type | Notes |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED PK AI | |
| `run_id` | BIGINT UNSIGNED | indexed (`run_product` unique with product, `run_status`) |
| `product_id` | BIGINT UNSIGNED | indexed |
| `prev_thumbnail_id` | BIGINT UNSIGNED NULL | `NULL` = meta absent before the run |
| `prev_gallery` | TEXT NULL | previous `_product_image_gallery` (`NULL` = meta absent) |
| `prev_parents` | LONGTEXT NULL | JSON `{attachment_id: previous post_parent}` |
| `prev_managed` | TINYINT(1) | whether `_msim_managed` existed before |
| `new_thumbnail_id` | BIGINT UNSIGNED NULL | state the run produced (rollback conflict detection) |
| `new_gallery` | TEXT NULL | state the run produced |
| `status` | VARCHAR(20) | `pending` / `done` / `failed` / `skipped` / `rolled_back` / `rollback_conflict` |
| `error` | TEXT NULL | failure / conflict reason |
| `created_at`, `updated_at` | DATETIME | |

Options: `msim_settings` (small, autoloaded), `msim_runs`, `msim_last_run_id`,
`msim_active_run_id` (run lock), `msim_plan_{run_id}` (one per run, written
once, autoload = no), `msim_db_version`. Post meta: `_msim_managed` marker on
plugin-managed products. **Uninstall removes all of it** plus any remaining
scheduled actions.

## 9. Out of scope for v1

Variation swatch assignment (architecture ready — see §6), remote/CSV/URL
import, server folder ingestion, image optimization / thumbnail regeneration /
alt-text generation, WP-CLI commands, multisite-specific logic.
