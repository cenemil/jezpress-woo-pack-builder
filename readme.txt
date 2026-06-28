===  JezPress Woo Pack Builder ===
Contributors: jezweb
Author: Jezweb
Author URI: https://jezweb.com.au
Tags: woocommerce, bundles, hampers, gift boxes, subscriptions, seasonal
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
Stable tag: 1.1.6
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

= 1.1.6 =
* Custom pack type: addon product weights are now included in WooCommerce shipping calculations
* Standard pack type: same weight calculation logic applied (base product + sum of item weights × qty)
* Shipping weight is stamped as a plain scalar on cart items via woocommerce_cart_shipping_packages so all shipping rate plugins receive the correct weight
* Added "Shipping Weight Debug" toggle in WooCommerce → Packs → Settings to show a weight breakdown panel on cart/checkout pages
* Removed file-based debug logging (WC logger); debug output is frontend-only via the debug panel
* Custom pack type: addon products are excluded from WooCommerce related products loop
* Frontend addon display: input-type addons show label (+price) then input field inline; checkbox-type addons show checkbox label (+price)
* Subtotal section hidden by default on product page; appears only when at least one addon is selected
* Added summary breakdown of base product and selected addons above the subtotal, with border separators and right-aligned prices
* Admin editor: added button-small class to standard pack item remove buttons
* Admin editor: addon field settings (Label/Type) are horizontal with block labels above inputs; Label field fills remaining width
* Admin editor: removed border-radius and heading background from addon field boxes
* Fixed woocommerce_related_products filter name (was woocommerce_related_posts — updated for WC 10.x)

= 1.1.5 =
* Fix form-table description font size in admin settings

= 1.1.4 =
* License gate: product type, product data tabs, cart/order handling, AJAX, shortcode, and template auto-injection are now disabled when license is not active; admin and license tab remain accessible

= 1.1.3 =
* Updated author and plugin URI to Jezweb (jezweb.com.au)
* License page migrated to shared admin CSS classes; enqueues admin.css via its own hook
* Added .admin-page-card-full modifier for full-width card layouts

= 1.1.2 =
* Renamed asset files (removed pack- prefix): pack-admin → admin, pack-editor → editor, pack-shortcode → shortcode
* Settings page refactored to WordPress-standard form-table layout
* Admin CSS classes migrated from jwpb-* to admin-page-* conventions

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
