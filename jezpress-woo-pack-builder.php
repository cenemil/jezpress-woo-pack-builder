<?php
/**
 * Plugin Name: JezPress Woo Pack Builder
 * Plugin URI:  https://jezweb.com.au
 * Description: Build product packs (hampers, gift boxes, meal kits) from a WP admin UI. Custom WooCommerce product type with flexible pricing, subscription billing, and seasonal item rotation.
 * Version:     1.1.3
 * Author:      Jezweb
 * Author URI:  https://jezweb.com.au
 * Text Domain: jezpress-woo-pack-builder
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * Tested up to: 6.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JWPB_VERSION', '1.1.3' );
define( 'JWPB_DIR', plugin_dir_path( __FILE__ ) );
define( 'JWPB_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook( __FILE__, 'jwpb_activate' );
register_deactivation_hook( __FILE__, 'jwpb_deactivate' );

function jwpb_activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-jwpb-db.php';
	JWPB_DB::create_table();
}

function jwpb_deactivate() {
	$license = JWPB_License::get_instance();
	if ( $license ) {
		$license->cleanup();
	}
}

// Load updater and license early — NOT gated by plugins_loaded
// because WP cron auto-updates need the update hooks outside admin context.
require_once JWPB_DIR . 'includes/class-jwpb-db.php';
require_once JWPB_DIR . 'includes/class-jwpb-updater.php';
require_once JWPB_DIR . 'includes/class-jwpb-license.php';

$_jwpb_license = JWPB_License::get_instance( __FILE__, 'jezpress-woo-pack-builder', 'JezPress Woo Pack Builder' );
$_jwpb_lic_key = $_jwpb_license->get_license_key();

$_jwpb_updater = new JWPB_Updater( __FILE__ );
$_jwpb_updater->set_slug( 'jezpress-woo-pack-builder' )
               ->set_api_url( 'https://updates.jezpress.com' );

if ( ! empty( $_jwpb_lic_key ) ) {
	$_jwpb_updater->set_license( $_jwpb_lic_key );
}
unset( $_jwpb_lic_key );

$_jwpb_updater->initialize();
unset( $_jwpb_updater );

add_action( 'plugins_loaded', 'jwpb_init', 20 );

function jwpb_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>JezPress Woo Pack Builder</strong> requires WooCommerce to be active.</p></div>';
		} );
		return;
	}

	if ( JWPB_DB::needs_upgrade() ) {
		JWPB_DB::create_table();
	}

	require_once JWPB_DIR . 'includes/class-jwpb-product-pack.php';
	require_once JWPB_DIR . 'includes/class-jwpb-product-type.php';
	require_once JWPB_DIR . 'includes/class-jwpb-seasonal.php';
	require_once JWPB_DIR . 'includes/class-jwpb-subscription.php';
	require_once JWPB_DIR . 'includes/class-jwpb-cart.php';
	require_once JWPB_DIR . 'includes/class-jwpb-order.php';
	require_once JWPB_DIR . 'includes/class-jwpb-ajax.php';
	require_once JWPB_DIR . 'includes/class-jwpb-admin.php';
	require_once JWPB_DIR . 'includes/class-jwpb-shortcode.php';
	require_once JWPB_DIR . 'includes/class-jwpb-settings.php';

	JWPB_Product_Type::init();
	JWPB_Cart::init();
	JWPB_Order::init();
	JWPB_Ajax::init();
	JWPB_Shortcode::init();
	JWPB_Settings::init();
	JWPB_Admin::get_instance();

	$license = JWPB_License::get_instance();
	if ( $license ) {
		$license->init();
	}
}
