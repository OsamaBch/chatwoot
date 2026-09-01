=== Maz Catalogue Image ===
Contributors: maz
Tags: woocommerce, catalog, product image, shop loop
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show a different product image in catalogue loops than on the single product page.

== Description ==

Each WooCommerce product gets an optional **Catalogue image**. When set, catalogue
loops — shop, category, tag, search, related products, up-sells, cross-sells and
the `[products]` shortcode — display that image instead of the featured image.
When it is not set, or the stored attachment has been deleted, the featured image
is used; no product ever renders without an image because of this plugin.

The single product page is never modified: featured image and gallery behave
exactly as WooCommerce ships them. Cart, checkout, mini-cart, order emails,
admin screens, REST responses and product feeds are also never modified.

Which image goes in the grid (packshot vs. model shot) is a per-product
editorial choice made by whoever sets the field — the plugin does not encode a
rule.

= What it deliberately does not do =

* No settings page, no options, no autoloaded options.
* No new image sizes, no image regeneration, no uploads.
* No frontend CSS or JS, no hover/flip effects.
* No custom database tables, no cron, no HTTP calls, no direct SQL.

= How it works =

The catalogue image is stored as post meta `_maz_catalog_image_id` (attachment
ID, registered with `show_in_rest` so an external sync can set it; writes
require the `edit_product` capability for that product). On the frontend the
`woocommerce_product_get_image_id` filter is attached on
`woocommerce_before_shop_loop_item` and detached on
`woocommerce_after_shop_loop_item`, so it is only ever active inside catalogue
loop items.

= Block themes =

The WooCommerce Product Collection / Products blocks do not fire the classic
loop hooks, so the plugin additionally scopes the same filter around the render
of the `woocommerce/product-image` block (via `render_block_data` /
`render_block`). This covers block-rendered archives and related-product grids
and is inert on classic themes. Verify grid behaviour on the active theme after
activation, since themes differ in how they render archives.

= Known interaction =

Image-flipper / hover-swap plugins reveal gallery image #1 on hover in the
grid. With a catalogue image set, the hover image and the resting image may now
be the same photo. Not a bug in this plugin, but worth checking on the live
theme.

== Installation ==

1. Upload the `maz-catalog-image` folder to `/wp-content/plugins/`.
2. Activate the plugin. WooCommerce must be active; the plugin no-ops cleanly
   without it.
3. Edit a product and use the **Catalogue image** box in the sidebar (below
   Product image / Product gallery).

== Frequently Asked Questions ==

= What happens if I delete the chosen attachment from the media library? =

The grid falls back to the featured image. No broken image, no PHP notice.

= Does uninstalling clean up? =

Yes. Deleting the plugin removes every `_maz_catalog_image_id` meta row.
Deactivation alone deletes nothing.

== Changelog ==

= 1.0.0 =
* Initial release: per-product catalogue image with loop-scoped filtering,
  media-library picker meta box, products-list column, REST-writable meta,
  uninstall cleanup.
