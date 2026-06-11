=== Mazyoud SKU Image Matcher ===
Contributors: mazyoud
Tags: woocommerce, product images, sku, media library, bulk
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
WC requires at least: 8.0
WC tested up to: 9.8
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically attaches Media Library images to WooCommerce products by parsing the SKU (or product ID) from the image filename.

== Description ==

Upload your product photos named after their SKU, run a scan, review the dry-run report, and let the plugin assign featured images and galleries in the background — with full rollback.

**Naming convention**

* `SKU.jpg` — main (featured) image
* `SKU-1.jpg`, `SKU_1.jpg`, `SKU 1.jpg` — gallery position 1
* `SKU-2.jpg` — gallery position 2, and so on (sorted ascending)
* Extra words after the SKU are fine: `AB1234 robe fille rouge-2.jpg` still matches SKU `AB1234`, gallery position 2
* Matching is case-insensitive; the position suffix is 1–2 digits
* If no SKU matches and the leading token is a number, it is treated as a WooCommerce product ID (`4821.jpg` → product #4821). A real SKU always wins over an ID.

**Built for production**

* Dry-run scan first — nothing is written until you press Run
* Background processing via Action Scheduler (close the tab, it keeps going)
* Live progress, run history, one-click rollback to the exact prior state
* Snapshot taken before each product is touched; rollback detects products manually edited since the run and asks before overwriting them
* Hard safety window: only media uploaded today / in the last 7 days is ever scanned
* Zero frontend footprint: no scripts, styles, queries or hooks on the storefront (one lightweight upload hook when auto-attach is enabled)
* Bulk SQL throughout — built for catalogs with thousands of SKUs and large media libraries
* Optional auto-attach on upload, fully isolated so it can never break an upload
* Everything is logged to WooCommerce → Status → Logs (source: `msim`)
* HPOS compatible. Compatible with media-offload plugins (S3, etc.): only database relationships are touched, never files.

**Existing-images modes**

* **Skip** (default): never touch products that already have a featured image or gallery
* **Replace all**: overwrite featured + gallery (old attachments are detached, listed in a CSV, never deleted)
* **Merge**: keep existing images, append new ones to the gallery, never duplicate an attachment

== Frequently Asked Questions ==

= A product matched, but the report shows a warning about a "duplicate rename" — why? =

WordPress renames a re-uploaded duplicate by appending `-1`, `-2`… to the filename. A re-upload of `ABC-2.jpg` becomes `ABC-2-1.jpg`, which would wrongly parse as SKU `ABC-2`, position 1. The plugin detects this by comparing the stored filename with the attachment title and flags the file instead of guessing. Rename the file properly and re-upload, or delete the duplicate.

= What about variation images? =

v1 matches parent-product SKUs only. Files that resolve to a variation SKU are reported in their own category ("variation SKU — planned for v2") and are never assigned. The matcher pipeline is pluggable so v2 can add variation swatch support.

= Does it work with S3 / offloaded media? =

Yes. The plugin only updates `post_parent` and post meta (`_thumbnail_id`, `_product_image_gallery`). It never reads, writes, moves or deletes files on disk.

= Does it purge my page cache / CDN? =

It fires `do_action( 'msim_product_images_updated', $product_id )` after each product update so you (or your host's plugin) can hook cache purges. See README.md for an example.

== Changelog ==

= 1.0.1 =
* Private and pending products are now matchable (previously only publish and draft). New `msim_matchable_post_statuses` filter to customize the set.

= 1.0.0 =
* Initial release: dry-run scan, background runs, rollback with conflict detection, auto-attach on upload, product-ID fallback, duplicate-rename detection.

== Upgrade Notice ==

= 1.0.1 =
Adds matching for private/pending products — recommended if your store uses private products.
