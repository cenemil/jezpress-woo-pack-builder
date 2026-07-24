===  JezPress Woo Pack Builder ===
Contributors: jezweb
Author: Jezweb
Author URI: https://jezweb.com.au
Tags: woocommerce, bundles, hampers, gift boxes, subscriptions, seasonal
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
Stable tag: 1.2.0
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

= 1.2.0 =
* Custom pack addon options now show a "Pre-order" badge next to their price when the JezPress Woo Pre-Order plugin is active and that addon's product has pre-order enabled and active (soft dependency — no effect if the pre-order plugin isn't present).

= 1.1.10 =
* Custom pack type: removed the minimum addon selection requirement — customers can now add a custom pack to cart with no addons selected, paying just the WooCommerce base price
* Removed the now-unused "select at least one item" validation, error notice, and related frontend markup/styles

= 1.1.9 =
* Fixed pack contents rendering breaking Elementor layouts when a page displays a pack's contents more than once (e.g. auto-inject placement combined with a manually placed shortcode, or multiple pack products in a related/upsell loop)
* Standard pack table and custom pack addon form now guard against duplicate rendering per pack ID, instead of a single page-wide flag that only covered one custom pack and didn't cover standard packs at all
* Cart/checkout pack contents now display as a stacked list (one item per line), visually separated from the pack name, with the "Contains:" label removed

= 1.1.8 =
* Custom pack type: fixed spurious <br> elements appearing between addon product list items on product pages — template rebuilt as PHP string concatenation so rendering filters (wpautop, theme hooks) have no inter-element whitespace to convert
* Custom pack type: pack_contents_placement setting now correctly governs the addon form; setting to "None" suppresses auto-injection for both standard and custom packs
* Custom pack type: added "Before Add to Cart button (inside form)" placement option so addon inputs submit natively without JavaScript syncing
* Refactored get_addon_form_html(): removed unused price_html fetch per row, deduplicated pricing mode and base price lookups, extracted addon_row_label() helper, simplified group data structure

= 1.1.7 =
* Settings: added "Exclude Pack Items from Related Products" toggle — when enabled, all products used as items in any pack (standard or addon) are excluded from the WooCommerce related products section

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
