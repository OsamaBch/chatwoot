# Maz Allowlist — WooCommerce order visibility

Controls which WooCommerce orders are visible in **wp-admin** (orders list) and in
**WooCommerce Analytics**, based on configurable visibility rules.

Strictly **read-path**: the plugin filters presentation only. It never modifies,
deletes, or alters any order data. Customers always see their own orders normally —
the storefront, My Account, checkout, order emails, and the checkout REST API are
untouched (no storefront hook is ever registered).

Built for HPOS-first stores (tested target: WP 7.0 / WC 10.8, HPOS enabled,
~200k orders), with a legacy post-based fallback.

---

## Install

1. Copy the `maz-allowlist/` directory to `wp-content/plugins/`.
2. Activate **Maz Allowlist** in wp-admin → Plugins. Activation:
   - creates the table `{prefix}maz_order_allowlist` (`order_id BIGINT UNSIGNED PRIMARY KEY`),
   - grants the `maz_view_all_orders` capability to the **administrator** role,
   - seeds the configuration option.
3. Configure under **WooCommerce → Maz Allowlist**.

Uninstalling (deleting) the plugin drops the table and removes all of its options,
transients, user meta, and the capability. Order data is never touched.

## The rules

All three rules are independently toggleable. With no rule enabled the plugin does
nothing.

### (a) Allowlist
Only orders whose ID is in the allowlist table are visible.

**Upload format:** paste into the textarea or upload a CSV/text file. IDs may be
separated by newlines, commas, semicolons, or spaces, in any mix; a leading `#`
(as shown in the orders list) is tolerated. Everything that is not a positive
integer is reported back as an invalid token — and IDs that match no existing
order are reported too. **Nothing is silently dropped.** Imports are additive;
use **Clear all** to start over. The current count is always shown.

Storage is a real table that visibility queries `JOIN` against — there is no
giant `IN (…)` clause, so ~50k IDs are fine.

### (b) Random sample
Shows a deterministic pseudo-random percentage of orders (default **10% visible /
90% hidden**). The decision for an order is:

```
hidden  ⇔  ( CRC32( order_id . seed ) % 100 ) >= visible_pct
```

evaluated as `CRC32(CONCAT(order_id, seed))` **inside SQL** (and with the
byte-identical `crc32()` expression in PHP for single-order checks). Nothing is
randomized at query time, so pagination, counts, and Analytics totals are stable
across page loads. The seed is stored in options; **Reroll seed** switches to a
different deterministic subset everywhere at once.

### (c) Amount threshold
Hide orders **above X**, **below X**, or **within a band X–Y**, computed on:

- **NET** total = order total − tax − shipping (default), or
- **GROSS** total = the order total as displayed.

The same basis is used everywhere, so the list and Analytics never disagree:

| Surface | Column used |
|---|---|
| HPOS orders list | `wc_orders.total_amount` (gross) / `total_amount − tax_amount − wc_order_operational_data.shipping_total_amount` (net) — direct DECIMAL compare |
| Legacy orders list | `postmeta '_order_total'` etc. — **`CAST(meta_value AS DECIMAL(20,6))`**, never string comparison |
| Analytics | `wc_order_stats.total_sales` (gross) / `wc_order_stats.net_total` (net) |

**Multi-currency:** if orders in more than one currency are detected, the settings
page shows a blocking warning and the amount rule cannot be enabled — a bare
numeric threshold is meaningless across currencies. (Detection is cached for 1 h.)

## Precedence (AND / OR)

- **AND (default, strict):** an order must satisfy **every** enabled rule to be
  shown — any single enabled rule can hide an order.
- **OR (lenient):** an order is shown if it satisfies **at least one** enabled
  rule — it is hidden only when every enabled rule would hide it.

Example with allowlist + 10% sample enabled: **AND** shows only allowlisted orders
that also fall inside the 10% sample; **OR** shows all allowlisted orders *plus*
the 10% sample of everything else.

## Search exception

If the orders-list **search box** is used (`s` present and non-empty), **all rules
are bypassed for that request** — including the status count badges shown during
the search — so any specific order can always be found by ID, number, email, or
customer name. This applies to the admin orders list only; Analytics is unaffected
by searching.

## Capability & access control

- Users with the **`maz_view_all_orders`** capability bypass every rule and always
  see everything. Administrators receive it on activation. Grant it to additional
  roles (e.g. with a role editor) to scope who sees the full dataset — this is the
  primary intended mechanism; prefer scoping by role over globally hiding data.
- Managing the plugin's settings requires `manage_options`.
- All state-changing actions are nonce-protected and capability-checked.

## Kill switch

Add to `wp-config.php`:

```php
define( 'MAZ_ALLOWLIST_DISABLE', true );
```

The plugin then no-ops entirely (no hooks, no filtering, no admin page). Use this
to recover if a bad configuration makes admin data unusable.

## Filtered-view notice

Whenever any rule is active, the Orders, Analytics, and legacy Reports screens show
a warning notice stating that the view is filtered and which rules are on (bypass
users get a variant saying the rules apply to others but not to them). It is
dismissible per session (12 h) and reappears whenever the configuration changes.

## Dry-run preview

On the settings page, **Preview this configuration** evaluates the form's current
(unsaved) values against all existing orders with a single SQL pass and reports:
total, hidden per rule (each rule counted alone, so numbers can overlap), and the
combined visible count.

## Audit log

Every configuration change is recorded — who, when, what changed, and the seed
value at the time (config saves, allowlist imports/clears, seed rerolls). The last
200 events are kept and shown at the bottom of the settings page.

## Analytics coverage & caching

Visibility fragments are injected into the Analytics SQL via the
`woocommerce_analytics_clauses_join/where` filters for these contexts:
`orders(_subquery/_stats_total/_stats_interval)` (Orders + Revenue + overview),
`products`, `variations`, `categories`, `coupons`, `taxes` (each incl. their
`_stats_total` / `_stats_interval` / `_subquery` variants). Refund rows are
resolved to their parent order (`wc_order_stats.parent_id`), so a refund is always
hidden/shown together with its order. Leaderboards and performance-indicators REST
endpoints read through these same report queries.

**Caching:** Analytics report caching is disabled while any rule is active
(a shared cache key cannot express the rule config or the requesting user's bypass
state, and would leak unfiltered numbers between users), and the whole report cache
is additionally invalidated on every config change — so numbers can never be stale
relative to your rules. Expect Analytics to be somewhat slower while rules are on.

## Exports

- **Analytics “Download” button:** covered. Small exports are generated from the
  already-filtered REST data; large ones run in Action Scheduler where the same
  clause filters apply. Background export jobs run with **no user context**, so
  they are always filtered while rules are active — even when a bypass-capability
  admin triggered them. A bypass admin who needs a complete export should disable
  the rules first (the audit log records the toggle).
- **Legacy WooCommerce → Reports** (and its CSV links, and the dashboard sales
  widget): covered via `woocommerce_reports_get_order_report_query`. Note these
  legacy reports read `wp_posts`; on an HPOS site **without** compatibility sync
  they are inaccurate regardless of this plugin.
- **Orders CSV exporters:** WooCommerce core ships none. Third-party order
  exporters that query the database or data store directly are **not** covered.

## Known limitations (by design or documented)

- The admin-menu “Processing” bubble (HPOS) uses an internal cached count and may
  show the unfiltered number.
- Analytics **Customers** and **Downloads** reports are not filtered (their base
  queries have no safe per-order join). All revenue/orders/products/coupons/taxes
  numbers are.
- Analytics segmented views (“Segment by”) use separate sub-queries that are not
  filtered — verify before relying on segments while rules are active.
- Direct access to a hidden order's edit screen by URL still works (the list hides
  it, search finds it; the plugin does not attempt access control on single
  orders).
- A user who can already query the database or use WP-CLI can of course see
  everything; this plugin controls admin presentation, it is not a security
  boundary.

## Testing checklist (per stage)

1. **Storage + settings:** activate; import pasted + CSV IDs incl. junk tokens and
   a nonexistent ID → both reported; counts correct; clear all; reroll seed; audit
   entries appear.
2. **Orders list:** enable each rule alone and combined (AND/OR); verify list rows,
   pagination totals, and the status badges above the table all agree; search for a
   hidden order by number/email → found; clear search → hidden again.
3. **Analytics:** compare Revenue/Orders/Products/Coupons/Taxes/Categories/
   Variations reports and leaderboards/performance indicators against the list
   totals; toggle a rule and confirm numbers change immediately (no stale cache);
   check as a bypass admin (unfiltered) vs a shop-manager without the capability
   (filtered).
4. **Exports:** Analytics Download (small + large date ranges) matches the on-screen
   filtered numbers; legacy Reports tab totals match.
5. **Preview:** numbers match what enabling the same config actually produces.
