# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Purpose

`jezpress-woo-pack-builder` is a proprietary JezPress WordPress plugin that adds a custom WooCommerce product type called "Pack". Staff build packs (hampers, gift boxes, meal kits) from a product editor UI. Packs support flexible pricing, optional subscription billing, and seasonal item rotation.

**Dependencies:** WooCommerce (required). JezPress WC Subscription or WooCommerce Subscriptions (optional — subscription tab saves data but does nothing without an engine). WordPress 5.8+, PHP 7.4+.

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
| `includes/class-jwpb-order.php` | `JWPB_Order` | Static; copies pack items into order item meta at checkout |
| `includes/class-jwpb-seasonal.php` | `JWPB_Seasonal` | Static; queries seasonal product pool using configurable tag, rotates pack items |
| `includes/class-jwpb-subscription.php` | `JWPB_Subscription_Bridge` | Static; detects and routes to JezPress WC Subscription or WC Subscriptions |
| `includes/class-jwpb-ajax.php` | `JWPB_Ajax` | Static; rotate seasonal AJAX, pool count AJAX; also handles `jwpb_get_product_info` for the editor |
| `includes/class-jwpb-db.php` | `JWPB_DB` | Static; schema creation/upgrade via `dbDelta`, CRUD for `{prefix}jwpb_pack_items` |
| `includes/class-jwpb-settings.php` | `JWPB_Settings` | Static; `jwpb_settings` option storage, auto-inject shortcode on product pages |
| `includes/class-jwpb-shortcode.php` | `JWPB_Shortcode` | Static; `[jwpb_pack_contents]` shortcode; renders pack items as a linked table |
| `includes/class-jwpb-license.php` | `JWPB_License` | Singleton; full license lifecycle |
| `includes/class-jwpb-updater.php` | `JWPB_Updater` | Hooks into WP update transients to pull updates from JezPress update server |

### Data Model

#### DB table: `{prefix}jwpb_pack_items`

Managed by `JWPB_DB`. Created on activation, dropped on uninstall (product postmeta is intentionally left intact on uninstall so orders are not broken).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint UNSIGNED PK | |
| `pack_id` | bigint UNSIGNED | Indexed; WP post ID of the pack product |
| `product_id` | bigint UNSIGNED | |
| `variation_id` | bigint UNSIGNED | 0 for non-variable products |
| `quantity` | int UNSIGNED | |
| `sort_order` | int UNSIGNED | Set from row index on save |

`JWPB_DB::save_pack_items()` is a full replace (delete + insert) — not an upsert.

#### Pack postmeta (stored on `product` post type)

| Key | Type | Notes |
|---|---|---|
| `_pack_pricing_mode` | string | `fixed` or `sum` |
| `_pack_price_override` | decimal string | Fixed mode only; also written to `_price` / `_regular_price` |
| `_pack_item_label` | string | Column header override for the shortcode table ("Product") |
| `_pack_qty_label` | string | Column header override for the shortcode table ("Quantity") |
| `_pack_subscription_enabled` | `yes`/`no` | |
| `_pack_subscription_interval` | int | |
| `_pack_subscription_period` | string | `day/week/month/year` |
| `_pack_subscription_length` | int | 0 = indefinite |
| `_pack_seasonal_enabled` | `yes`/`no` | |
| `_pack_seasonal_count` | int | Items drawn from seasonal pool per rotation |
| `_pack_seasonal_last_rotated` | MySQL datetime | Updated by `JWPB_Seasonal::rotate()` |

#### Order item meta

| Key | Type |
|---|---|
| `_jwpb_contents` | JSON `[{"id":X,"name":"...","sku":"...","qty":Y},...]` |

### Product Data Tabs

Pack products show two tabs in the WooCommerce product editor:

- **Pack Contents** (`jwpb_contents_data`) — pricing mode, pack price override, column label overrides, seasonal rotation toggle + fields, product search, and the items table. The General tab is hidden for pack products via `hide_if_pack` class.
- **Subscription** (`jwpb_subscription_data`) — subscription billing enable, interval, period, length.

`woocommerce_pack_add_to_cart` is hooked to `woocommerce_simple_add_to_cart` so the standard add-to-cart button renders on single product pages.

### Seasonal Pool

Products must be tagged with the configured **Seasonal Pool Tag** (stored as `seasonal_tag` in `jwpb_settings`, defaults to `"seasonal"`) and have `stock_status = instock` to appear in the pool. `JWPB_Seasonal::get_pool()` reads this setting at runtime via `JWPB_Settings::get('seasonal_tag', 'seasonal')`. Rotation is **manual only** — no WP Cron schedule. The "Rotate Now" button in the Pack Contents tab calls `JWPB_Seasonal::rotate()` via AJAX.

### Subscription Bridge

`JWPB_Subscription_Bridge::get_engine()` checks for `JezPress_WC_Subscription` class first, then `WC_Subscriptions_Product`. Returns `'jezpress'`, `'woocommerce'`, or `null`. When null, the subscription tab still saves data to postmeta but no cart/checkout subscription logic fires.

### Admin Location

WooCommerce → **Packs** (requires `manage_woocommerce` cap). Tab navigation: **Settings** | **Packs** (only shown when licensed) | **License**. Unlicensed users are redirected to the Settings tab.

### Settings (`JWPB_Settings`)

Option key `jwpb_settings` (serialised array). Current keys:

| Key | Values | Effect |
|---|---|---|
| `pack_contents_placement` | `none` / `after_price` / `after_excerpt` / `after_add_to_cart` / `after_meta` / `after_summary` | Hooks `JWPB_Shortcode::render()` into the matching WC product template action; fires only when `$product instanceof WC_Product_Pack` |
| `seasonal_tag` | any WC product tag slug | Tag used to identify the seasonal pool; defaults to `"seasonal"` |

### Frontend JS (`assets/js/pack-editor.js`)

Loaded only on the WC product edit screen (when product type is `pack`). Uses jQuery + WC's bundled Select2. Key behaviours:

- **Product search** — uses a custom `jwpb-product-search` class (not `wc-product-search`) to prevent WooCommerce auto-initialising Select2 over it. Calls WC's built-in `woocommerce_json_search_products` AJAX action with `exclude_type: 'pack'`.
- **Add row** — calls `jwpb_get_product_info` AJAX action to fetch product details (name, SKU, price HTML, variations list) and appends a DOM row.
- **Form serialise** — on `#post` submit, iterates visible rows and writes `JSON.stringify(items)` into `#jwpb-items-json` hidden field; PHP reads this field in the meta save handler.
- **Variation select** — updates the price cell from locally cached variation data (no round-trip).
- **Pricing mode toggle** — shows/hides `.jwpb-fixed-price-field` based on the selected radio.
- **Localized object** — `jwpbData` (printed by `JWPB_Product_Type`): `ajaxurl`, `adminNonce`, `searchNonce`, `existingItems[]`, `defaultType`, `i18n{}`.

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
| License activate | `jezweb_license_activate` | `manage_options` |
| License deactivate | `jezweb_license_deactivate` | `manage_options` |
| Product search (WC built-in) | `search-products` | `manage_woocommerce` |

## Releasing a New Version

1. Update `JWPB_VERSION` constant and `Version:` header in the entry point
2. Update `Stable tag:` in `readme.txt` and add a changelog entry
3. Upload via JezPress CLI (run from plugin parent directory):
   ```bash
   jezpress plugins preflight jezpress-woo-pack-builder ./jezpress-woo-pack-builder.zip
   jezpress plugins upload jezpress-woo-pack-builder ./jezpress-woo-pack-builder.zip
   ```
