# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Purpose

`jezpress-woo-pack-builder` is a proprietary Jezweb WordPress plugin that adds a custom WooCommerce product type called "Pack". Staff build packs (hampers, gift boxes, meal kits) from a product editor UI. Packs support flexible pricing, optional subscription billing, and seasonal item rotation.

**Dependencies:** WooCommerce (required). JezPress WC Subscription or WooCommerce Subscriptions (optional — subscription tab saves data but does nothing without an engine). JezPress Woo Pre-Order (optional — when active, custom-pack addon options in `JWPB_Shortcode::addon_row_label()` show a "Pre-order" badge next to the price for any addon whose product has pre-order enabled and active; no effect when absent). WordPress 5.8+, PHP 7.4+.

## No Build Step

Pure PHP plugin — no Composer, npm, or build scripts. No compilation required.

## Architecture

### Entry Point (`jezpress-woo-pack-builder.php`)
- Defines `JWPB_VERSION`, `JWPB_DIR`, `JWPB_URL` constants
- Loads `class-jwpb-updater.php` and `class-jwpb-license.php` via `require_once` **before** `plugins_loaded` so WP cron hooks fire in all contexts
- Checks WooCommerce is active at `plugins_loaded` priority 20, then loads all feature classes

### Class Overview

| File | Class | Role |
|---|---|---|
| `includes/class-jwpb-product-pack.php` | `WC_Product_Pack` | Custom WC product type extending WC_Product; price computation, getters/setters |
| `includes/class-jwpb-product-type.php` | `JWPB_Product_Type` | Static; registers WC type, product data tabs, panel HTML, meta save, script enqueue |
| `includes/class-jwpb-admin.php` | `JWPB_Admin` | Singleton; WC → Packs submenu, list table of all packs, settings tab |
| `includes/class-jwpb-cart.php` | `JWPB_Cart` | Static; seasonal snapshot, sum-mode pricing, cart item name with contents |
| `includes/class-jwpb-order.php` | `JWPB_Order` | Static; copies pack items into order item meta at checkout, and renders them in both the admin order screen and customer-facing views (emails / thank-you / My Account) |
| `includes/class-jwpb-seasonal.php` | `JWPB_Seasonal` | Static; queries seasonal product pool using configurable tag, rotates pack items |
| `includes/class-jwpb-subscription.php` | `JWPB_Subscription_Bridge` | Static; detects and routes to JezPress WC Subscription or WC Subscriptions |
| `includes/class-jwpb-ajax.php` | `JWPB_Ajax` | Static; rotate seasonal AJAX, pool count AJAX; also handles `jwpb_get_product_info` for the editor |
| `includes/class-jwpb-db.php` | `JWPB_DB` | Static; schema creation/upgrade via `dbDelta`, CRUD for `{prefix}jwpb_pack_items` |
| `includes/class-jwpb-settings.php` | `JWPB_Settings` | Static; `jwpb_settings` option storage, auto-inject shortcode on product pages |
| `includes/class-jwpb-shortcode.php` | `JWPB_Shortcode` | Static; `[jwpb_pack_contents]` shortcode; renders pack items as a linked table |
| `includes/class-jwpb-license.php` | `JWPB_License` | Singleton; full license lifecycle |
| `includes/class-jwpb-updater.php` | `JWPB_Updater` | Hooks into WP update transients to pull updates from JezPress update server |

### Pack Types

Two mutually exclusive modes controlled by `_pack_type` postmeta:

- **Standard** — admin defines fixed bundled items; all customers get the same contents. Supports pricing mode (sum-of-items or fixed override), column label overrides, and seasonal rotation.
- **Custom** — admin defines a pool of selectable addon products; customers choose from them at checkout. Uses the WooCommerce **General tab** for base price (regular + sale), so pricing, column labels, and seasonal rotation settings are hidden (standard-only). Cart price = WC base price + sum of customer-selected addon prices.

### Data Model

#### DB table: `{prefix}jwpb_pack_items` (schema version 2)

Managed by `JWPB_DB`. Created on activation, dropped on uninstall (product postmeta is intentionally left intact on uninstall so orders are not broken). `TABLE_VERSION = 2` stored in option `jwpb_db_version`; `needs_upgrade()` triggers `create_table()` on boot if behind.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint UNSIGNED PK | |
| `pack_id` | bigint UNSIGNED | Indexed; WP post ID of the pack product |
| `product_id` | bigint UNSIGNED | |
| `variation_id` | bigint UNSIGNED | 0 for non-variable products |
| `quantity` | int UNSIGNED | 0 for addon rows |
| `sort_order` | int UNSIGNED | Set from row index on save |
| `item_role` | varchar(20) | `'standard'` (fixed items) or `'addon'` (custom pack options) |
| `input_type` | varchar(20) | `'checkbox'` or `'select'`; applies to addon rows only |

Both `save_pack_items()` and `save_addon_items()` are full replace (delete by `item_role` then insert) — not upserts. They operate independently so saving standard items never touches addon rows and vice versa.

#### Pack postmeta (stored on `product` post type)

| Key | Type | Notes |
|---|---|---|
| `_pack_type` | string | `standard` or `custom` |
| `_pack_pricing_mode` | string | `fixed` or `sum`; standard packs only |
| `_pack_price_override` | decimal string | Standard + fixed mode only; written to `_price` / `_regular_price` |
| `_pack_item_label` | string | Column header override for the shortcode table ("Product") |
| `_pack_qty_label` | string | Column header override for the shortcode table ("Quantity") |
| `_pack_subscription_enabled` | `yes`/`no` | |
| `_pack_subscription_interval` | int | |
| `_pack_subscription_period` | string | `day/week/month/year` |
| `_pack_subscription_length` | int | 0 = indefinite |
| `_pack_seasonal_enabled` | `yes`/`no` | Standard packs only |
| `_pack_seasonal_count` | int | Items drawn from seasonal pool per rotation |
| `_pack_seasonal_last_rotated` | MySQL datetime | Updated by `JWPB_Seasonal::rotate()` |

#### Order item meta

| Key | Type |
|---|---|
| `_jwpb_contents` | JSON `[{"product_id":X,"variation_id":X,"name":"...","sku":"...","quantity":Y,"variation":"..."},...]` |

##### Displaying `_jwpb_contents` — two separate paths, don't collapse them

The key is **underscore-prefixed**, and `WC_Order_Item::get_formatted_meta_data()` defaults to
`$hideprefix = '_'`, so it is stripped from every rendering that goes through
`wc_display_item_meta()`. The **only** caller that passes `''` is the admin order screen
(`html-order-item-meta.php` → `get_all_formatted_meta_data( '' )`).

That means the `woocommerce_order_item_display_meta_key` / `..._meta_value` filters
(`JWPB_Order::format_meta_key()` / `format_meta_value()`) are **admin-only**. Until 1.3.2 they
were the only renderer, so pack contents were invisible in every customer-facing view: all order
emails (including JezPress Woo Pre-Order's pre-order confirmation and release notice, which just
`do_action( 'woocommerce_email_order_details' )` like core), the thank-you page, and
My Account → order details.

Customer-facing output is `JWPB_Order::render_pack_contents()` on
**`woocommerce_order_item_meta_end`**, which fires in `emails/email-order-items.php`,
`emails/plain/email-order-items.php` and `order/order-details-item.php` — and *not* in the admin
meta view, so the two paths never double-render. It receives `$plain_text` as its 4th arg and must
keep emitting a text-only branch; HTML markup in a plain-text email is not acceptable.

Both paths format lines through `build_content_lines()` → `format_content_line( $entry, $html )` —
change line formatting there, not in one renderer, or admin and email disagree about the box
contents.

`build_content_lines()` applies the **`jwpb_order_item_content_line`** filter
(`$line, $entry, $item, $html`) to every entry. That exists for JezPress Woo Pre-Order 1.6.1+,
which appends "(Pre-order)" to the pack items still awaiting release — that state lives in *its*
order item meta (`_jwpo_bundle_pending_items`), not in `_jwpb_contents`, so this plugin can't
derive it. `$html` tells the hook whether to return escaped HTML or bare text, since the same
filter feeds the plain-text email.

Renaming the key to drop the underscore would be a simpler fix but would orphan every existing
order's contents meta. Don't.

#### Custom pack cart item data

When a custom pack is added to cart, `JWPB_Cart::enrich_cart_item_data()` reads POST field `jwpb_addon_sel` (an array keyed `"{product_id}_{variation_id}" => quantity`) and stores validated selections as `_jwpb_addon_selections` on the cart item. Each selection is cross-checked against `JWPB_DB::get_addon_items()` so customers cannot inject arbitrary product IDs.

For custom packs, `JWPB_Cart::set_pack_price()` seeds the running total from `get_post_meta($product->get_id(), '_price', true)` — reading directly from postmeta bypasses the in-memory object so the value is correct even if `set_price()` was already called earlier in the same request. Each selected addon's price × quantity is then added on top.

### Product Data Tabs

Pack products show two tabs in the WooCommerce product editor:

- **Pack Contents** (`jwpb_contents_data`) — pack type radio (Standard / Custom). Standard packs: pricing mode, pack price override, column label overrides, seasonal rotation toggle + fields, product search, items table (all in `.jwpb-standard-only` groups). Custom packs: addon product search, addon items table with input-type selector (`.jwpb-custom-only`). The General tab has `hide_if_pack` class so WC hides it for all pack types; `togglePackType()` in JS then re-shows it for custom packs so WC's native regular/sale price fields are available as the base price.
- **Subscription** (`jwpb_subscription_data`) — subscription billing enable, interval, period, length.

`woocommerce_pack_add_to_cart` is hooked to `woocommerce_simple_add_to_cart` so the standard add-to-cart button renders on single product pages.

### Seasonal Pool

Applies to **standard packs only** — the Seasonal Rotation section is hidden in the editor when pack type is Custom. Products must be tagged with the configured **Seasonal Pool Tag** (stored as `seasonal_tag` in `jwpb_settings`, defaults to `"seasonal"`) and have `stock_status = instock` to appear in the pool. `JWPB_Seasonal::get_pool()` reads this setting at runtime via `JWPB_Settings::get('seasonal_tag', 'seasonal')`. Rotation is **manual only** — no WP Cron schedule. The "Rotate Now" button in the Pack Contents tab calls `JWPB_Seasonal::rotate()` via AJAX.

### Subscription Bridge

`JWPB_Subscription_Bridge::get_engine()` checks for `JezPress_WC_Subscription` class first, then `WC_Subscriptions_Product`. Returns `'jezpress'`, `'woocommerce'`, or `null`. When null, the subscription tab still saves data to postmeta but no cart/checkout subscription logic fires.

### Admin Location

WooCommerce → **Packs** (requires `manage_woocommerce` cap). Tab navigation: **Settings** | **Packs** | **License**. Unlicensed installs are forced to the **License** tab only — Settings and Packs tabs are hidden until a valid license key is entered.

### Settings (`JWPB_Settings`)

Option key `jwpb_settings` (serialised array). Current keys:

| Key | Values | Effect |
|---|---|---|
| `pack_contents_placement` | `none` / `after_price` / `after_excerpt` / `after_add_to_cart` / `after_meta` / `after_summary` | Hooks `JWPB_Shortcode::render()` into the matching WC product template action; fires only when `$product instanceof WC_Product_Pack` |
| `seasonal_tag` | any WC product tag slug | Tag used to identify the seasonal pool; defaults to `"seasonal"` |
| `exclude_pack_items_from_related` | `true`/`false` | When enabled, all products used as items in any pack (standard or addon, any pack type) are excluded from the WooCommerce related products section |

### License Gate

The license check in the entry point (`jwpb_init()`) controls which classes load:

- **Always loaded** (regardless of license): `JWPB_DB`, `JWPB_Updater`, `JWPB_License`, `JWPB_Admin`, `JWPB_Settings`
- **License-gated** (only when `$license->is_valid()`): `WC_Product_Pack`, `JWPB_Product_Type`, `JWPB_Seasonal`, `JWPB_Subscription_Bridge`, `JWPB_Cart`, `JWPB_Order`, `JWPB_Ajax`, `JWPB_Shortcode`

This means on an unlicensed install the "pack" product type does not exist in WooCommerce at all — the product type dropdown entry, the product data tabs, AJAX handlers, shortcode, and cart/order hooks are all absent.

### Frontend JS (`assets/js/editor.js`)

Loaded only on the WC product edit screen (when product type is `pack`). Uses jQuery + WC's bundled Select2. Key behaviours:

- **Product search** — uses a custom `jwpb-product-search` class (not `wc-product-search`) to prevent WooCommerce auto-initialising Select2 over it. Calls WC's built-in `woocommerce_json_search_products` AJAX action with `exclude_type: 'pack'`. Two separate search selects: `#jwpb-product-search` (standard items) and `#jwpb-addon-product-search` (addon items).
- **Add row** — calls `jwpb_get_product_info` AJAX action to fetch product details (name, SKU, price HTML, variations list) and appends a DOM row to the appropriate table.
- **Form serialise** — on `#post` submit, iterates visible rows in the standard table → `#jwpb-items-json` (`_pack_items_json`) and addon table → `#jwpb-addon-items-json` (`_pack_addon_items_json`); PHP reads both fields in the meta save handler.
- **Pack type toggle** — shows/hides `.jwpb-standard-only` and `.jwpb-custom-only` sections based on the `_pack_type` radio selection.
- **Addon input type** — each addon row has a select for `input_type` (`checkbox` or `select`), serialised into `_pack_addon_items_json`.
- **Variation select** — updates the price cell from locally cached variation data (no round-trip).
- **Pricing mode toggle** — shows/hides `.jwpb-fixed-price-field` based on the selected radio.
- **Localized object** — `jwpbData` (printed by `JWPB_Product_Type`): `ajaxurl`, `currency`, `adminNonce`, `searchNonce`, `existingItems[]`, `existingAddonItems[]`, `packType` (current saved pack type), `defaultType` (from `?jwpb_type=` GET param for new products), `i18n{}`.

### Admin JS (`assets/js/admin.js`)

Loaded only on the WC → Packs admin page (`woocommerce_page_jwpb-pack-builder` hook). Manages the slide-down create/edit form panel. Key behaviours:

- **Create pack** — calls `jwpb_create_pack` AJAX action; on success redirects to the WC product editor for the new draft.
- **Edit pack** — calls `jwpb_get_pack_data` to prefill the form, then `jwpb_update_pack` on submit; on success redirects back to the Packs list with `?updated=1`.
- **Pricing mode toggle** — same show/hide of `.jwpb-fixed-price-field` as in `editor.js`.
- **Localized object** — `jwpbAdmin` (printed by `JWPB_Admin`): `ajaxurl`, `nonce`, `currency`, `i18n{}`.

### Shortcode (`[jwpb_pack_contents]`)

Renders pack items as a two-column table (product name + qty). Accepts optional `id` attribute; auto-detects `$product` global or current singular product page when omitted. Variation items link to the parent product page with `?attribute_pa_*=slug` query params so WooCommerce pre-selects the variant. Column headers are overridable via `_pack_item_label` / `_pack_qty_label` postmeta.

## Nonces & Capabilities

| Action | Nonce action | Cap |
|---|---|---|
| Save pack meta (WC form) | WC metabox system | `manage_woocommerce` |
| Rotate seasonal | `jwpb_rotate_{pack_id}` | `manage_woocommerce` |
| Get pool count | `jwpb_admin_nonce` | `manage_woocommerce` |
| Get product info (editor) | `jwpb_admin_nonce` | `manage_woocommerce` |
| Save settings | `jwpb_save_settings` | `manage_woocommerce` |
| Create pack (admin page) | `jwpb_admin_nonce` | `manage_woocommerce` |
| Get pack data (admin page) | `jwpb_admin_nonce` | `manage_woocommerce` |
| Update pack (admin page) | `jwpb_admin_nonce` | `manage_woocommerce` |
| License activate | `jezweb_license_activate` | `manage_options` |
| License deactivate | `jezweb_license_deactivate` | `manage_options` |
| Product search (WC built-in) | `search-products` | `manage_woocommerce` |

## Releasing a New Version

1. Update `JWPB_VERSION` constant and `Version:` header in the entry point
2. Update `Stable tag:` in `readme.txt` and add a changelog entry
3. Package the ZIP (run from the plugin's parent directory):
   ```bash
   zip -r jezpress-woo-pack-builder.zip jezpress-woo-pack-builder -x "*.git*" -x "*CLAUDE.md" -x "*PLAN.md"
   ```
4. Preflight and upload via JezPress CLI:
   ```bash
   jezpress plugins preflight jezpress-woo-pack-builder ./jezpress-woo-pack-builder.zip
   jezpress plugins upload jezpress-woo-pack-builder ./jezpress-woo-pack-builder.zip
   ```
5. Notify the team via the Jezweb dev Google Chat space (see parent `plugins/CLAUDE.md` for the webhook payload format).
