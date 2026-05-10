<?php
/**
 * JWPB_Settings — plugin option storage and template auto-inject.
 *
 * Settings are stored as a single serialised array in wp_options under
 * the key 'jwpb_settings'. The auto-inject feature hooks a render call
 * into the appropriate WooCommerce product template action based on the
 * saved placement value — only firing on Pack type products.
 *
 * Placement values map to [WC hook, priority]:
 *   after_price       → woocommerce_single_product_summary   21
 *   after_excerpt     → woocommerce_single_product_summary   26
 *   after_add_to_cart → woocommerce_single_product_summary   31
 *   after_meta        → woocommerce_single_product_summary   41
 *   after_summary     → woocommerce_after_single_product_summary  10
 *
 * @package JezPress_Woo_Pack_Builder
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPB_Settings {

	const OPTION_KEY = 'jwpb_settings';

	/**
	 * All valid placement keys with their WC hook + priority.
	 */
	const PLACEMENTS = array(
		'after_price'       => array( 'woocommerce_single_product_summary',       21 ),
		'after_excerpt'     => array( 'woocommerce_single_product_summary',       26 ),
		'after_add_to_cart' => array( 'woocommerce_single_product_summary',       31 ),
		'after_meta'        => array( 'woocommerce_single_product_summary',       41 ),
		'after_summary'     => array( 'woocommerce_after_single_product_summary', 10 ),
	);

	public static function init() {
		add_action( 'admin_post_jwpb_save_settings', array( __CLASS__, 'handle_save' ) );

		// Register the auto-inject hook for the saved placement (frontend only).
		$placement = self::get( 'pack_contents_placement', 'none' );

		if ( 'none' !== $placement && isset( self::PLACEMENTS[ $placement ] ) ) {
			list( $hook, $priority ) = self::PLACEMENTS[ $placement ];
			add_action( $hook, array( __CLASS__, 'auto_inject' ), $priority );
		}
	}

	/**
	 * Retrieve a single setting value.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		$settings = get_option( self::OPTION_KEY, array() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Auto-inject callback — echoes the pack contents table when the current
	 * product is a Pack. Called by whichever WC hook the placement maps to.
	 *
	 * @return void
	 */
	public static function auto_inject() {
		global $product;

		if ( ! ( $product instanceof WC_Product_Pack ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo JWPB_Shortcode::render( array() );
	}

	/**
	 * Handle the settings form POST (admin-post.php).
	 *
	 * Validates, saves, then redirects back to the Settings tab.
	 *
	 * @return void
	 */
	public static function handle_save() {
		check_admin_referer( 'jwpb_save_settings' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'jezpress-woo-pack-builder' ) );
		}

		$valid     = array_merge( array( 'none' ), array_keys( self::PLACEMENTS ) );
		$placement = sanitize_key( wp_unslash( $_POST['pack_contents_placement'] ?? 'none' ) );

		if ( ! in_array( $placement, $valid, true ) ) {
			$placement = 'none';
		}

		$seasonal_tag = sanitize_title( wp_unslash( $_POST['seasonal_tag'] ?? 'seasonal' ) );
		if ( '' === $seasonal_tag ) {
			$seasonal_tag = 'seasonal';
		}

		$settings                            = get_option( self::OPTION_KEY, array() );
		$settings['pack_contents_placement'] = $placement;
		$settings['seasonal_tag']            = $seasonal_tag;
		update_option( self::OPTION_KEY, $settings );

		wp_safe_redirect(
			add_query_arg(
				array( 'tab' => 'settings', 'saved' => '1' ),
				admin_url( 'admin.php?page=jwpb-pack-builder' )
			)
		);
		exit;
	}

	/**
	 * Human-readable labels for each placement option (used by the admin UI).
	 *
	 * @return array<string,string>
	 */
	public static function placement_labels() {
		return array(
			'none'            => __( 'None — manual shortcode only',          'jezpress-woo-pack-builder' ),
			'after_price'     => __( 'After product price',                    'jezpress-woo-pack-builder' ),
			'after_excerpt'   => __( 'After short description',                'jezpress-woo-pack-builder' ),
			'after_add_to_cart' => __( 'After add-to-cart button',             'jezpress-woo-pack-builder' ),
			'after_meta'      => __( 'After product meta',                     'jezpress-woo-pack-builder' ),
			'after_summary'   => __( 'Below product summary (above tabs)',     'jezpress-woo-pack-builder' ),
		);
	}
}
