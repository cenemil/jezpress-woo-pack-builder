===  JezPress Woo Pack Builder ===
Contributors: jezweb
Tags: woocommerce, bundles, hampers, gift boxes, subscriptions, seasonal
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
Stable tag: 1.1.1
License: Proprietary

Build product packs (hampers, gift boxes, meal kits) with flexible pricing, subscription billing, and seasonal rotation.

== Description ==

JezPress Woo Pack Builder adds a "Pack" product type to WooCommerce. Staff build packs from a UI in the product editor — picking products, setting quantities, choosing a pricing strategy, and optionally enabling subscription billing or seasonal item rotation.

**Key features:**

* Custom "Pack" product type — appears in all WooCommerce product contexts
* Two product data tabs: Pack Contents, Subscription
* **Pack Contents** — AJAX product search, add/remove items, set quantities, pricing mode, and seasonal rotation settings
* **Subscription** — Compatible with JezPress WC Subscription and WooCommerce Subscriptions
* **Seasonal Rotation** — Draw N products from a configurable tag-based pool on demand via "Rotate Now"
* Cart displays pack contents summary under the pack name
* Orders record pack contents as a single line item with fulfilment detail in meta
* WooCommerce → Packs admin page lists all packs with key stats
* Licensed and auto-updated via the JezPress update server

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce → Packs → License and enter your JezPress license key
4. Create packs via Products → Add New → select product type "Pack"

== Changelog ==

= 1.1.1 =
* Admin page now restricts unlicensed installs to the License tab only — Settings and Packs tabs are hidden until a valid license is entered

= 1.1.0 =
* Merged Pack Pricing and Seasonal tabs into Pack Contents tab for a streamlined editing layout
* Seasonal pool tag is now configurable in WooCommerce → Packs → Settings (defaults to "seasonal")
* Fixed add-to-cart button not rendering on pack product pages
* Pricing mode and seasonal rotation fields moved inline within Pack Contents panel
* Settings card label updated to "Settings"

= 1.0.0 =
* Initial release
