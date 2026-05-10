<?php
/**
 * Uninstall JezPress Woo Pack Builder.
 *
 * Removes plugin options and scheduled events. Does NOT delete product data
 * (pack postmeta) so orders and products are not broken by uninstall.
 *
 * @package JezPress_Woo_Pack_Builder
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove license option.
$slug        = 'jezpress-woo-pack-builder';
$option_name = 'jzwb_lic_' . substr( md5( $slug ), 0, 8 );
delete_option( $option_name );

// Clear update transient.
delete_transient( 'jwpb_update_' . md5( $slug ) );

// Clear license cron.
wp_clear_scheduled_hook( 'jwpb_license_check' );

// Clear license message transients.
delete_transient( 'jezweb_license_message_' . $slug );

// Drop the custom pack items table and remove DB version option.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-jwpb-db.php';
JWPB_DB::drop_table();
delete_option( JWPB_DB::TABLE_VERSION_OPTION );
