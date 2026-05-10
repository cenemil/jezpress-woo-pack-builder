# CLAUDE.md

This file provides guidance to Claude Code when working with code in this repository.

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
| `includes/class-jwpb-admin.php` | `JWPB_Admin` | Singleton; WC → Packs submenu, list table of all packs, license tab |
| `includes/class-jwpb-cart.php` | `JWPB_Cart` | Static; seasonal snapshot, sum-mode pricing, cart item name with contents |
| `includes/class-jwpb-order.php` | `JWPB_Order` | Static; copies pack items into order item meta at checkout |
| `includes/class-jwpb-seasonal.php` | `JWPB_Seasonal` | Static; queries seasonal product pool, rotates pack items |
| `includes/class-jwpb-subscription.php` | `JWPB_Subscription_Bridge` | Static; detects and routes to JezPress WC Subscription or WC Subscriptions |
| `includes/class-jwpb-ajax.php` | `JWPB_Ajax` | Static; rotate seasonal AJAX, pool count AJAX |
| `includes/class-jwpb-license.php` | `JWPB_License` | Singleton; full license lifecycle |
| `includes/class-jwpb-updater.php` | `JWPB_Updater` | Hooks into WP update transients to pull updates from JezPress update server |

### Data Model

Pack meta keys stored on `product` post type:

| Key | Type | Notes |
|---|---|---|
| `_pack_items` | JSON string | `[{"id":123,"qty":2},...]` — disallows nesting packs |
| `_pack_pricing_mode` | string | `fixed` or `sum` |
| `_pack_price_override` | decimal string | Fixed mode only; also written to `_price` / `_regular_price` |
| `_pack_subscription_enabled` | `yes`/`no` | |
| `_pack_subscription_interval` | int | |
| `_pack_subscription_period` | string | `day/week/month/year` |
| `_pack_subscription_length` | int | 0 = indefinite |
| `_pack_seasonal_enabled` | `yes`/`no` | |
| `_pack_seasonal_count` | int | Items drawn from seasonal pool per rotation |
| `_pack_seasonal_last_rotated` | MySQL datetime | Updated by JWPB_Seasonal::rotate() |

Order item meta:

| Key | Type |
|---|---|
| `_jwpb_contents` | JSON `[{"id":X,"name":"...","sku":"...","qty":Y},...]` |

### Seasonal Pool

Products must be tagged with the WooCommerce product tag `seasonal` and have `stock_status = instock` to appear in the pool. Rotation is **manual only** — no WP Cron schedule. The "Rotate Now" button in the Seasonal tab calls `JWPB_Seasonal::rotate()` via AJAX.

### Subscription Bridge

`JWPB_Subscription_Bridge::get_engine()` checks for `JezPress_WC_Subscription` class first, then `WC_Subscriptions_Product`. Returns `'jezpress'`, `'woocommerce'`, or `null`. When null, the subscription tab still saves data to postmeta but no cart/checkout subscription logic fires.

### Admin Location

WooCommerce → **Packs** (requires `manage_woocommerce` cap). Tab navigation: **Packs** (only shown when licensed) | **License** (always visible). Unlicensed users are redirected to the License tab.

## Nonces & Capabilities

| Action | Nonce action | Cap |
|---|---|---|
| Save pack meta (WC form) | WC metabox system | `manage_woocommerce` |
| Rotate seasonal | `jwpb_rotate_{pack_id}` | `manage_woocommerce` |
| Get pool count | `jwpb_admin_nonce` | `manage_woocommerce` |
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
