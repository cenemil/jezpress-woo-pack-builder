<?php
/**
 * JWPB_Ajax — AJAX handlers.
 *
 * Product search reuses WC's built-in woocommerce_json_search_products.
 * jwpb_get_product_info returns type, price, and variation data so the JS
 * can render a full row (including variation dropdown) without extra requests.
 *
 * @package JezPress_Woo_Pack_Builder
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPB_Ajax {

	public static function init() {
		add_action( 'wp_ajax_jwpb_get_product_info', array( __CLASS__, 'get_product_info' ) );
		add_action( 'wp_ajax_jwpb_rotate_seasonal',  array( __CLASS__, 'rotate_seasonal' ) );
		add_action( 'wp_ajax_jwpb_get_pool_count',   array( __CLASS__, 'get_pool_count' ) );
		add_action( 'wp_ajax_jwpb_create_pack',      array( __CLASS__, 'create_pack' ) );
		add_action( 'wp_ajax_jwpb_get_pack_data',    array( __CLASS__, 'get_pack_data' ) );
		add_action( 'wp_ajax_jwpb_update_pack',      array( __CLASS__, 'update_pack' ) );
	}

	/**
	 * Return product type, price HTML, and variation list for a given product ID.
	 * Used by editor.js immediately after a product is selected in search.
	 *
	 * POST params: product_id (int), nonce (string: jwpb_admin_nonce)
	 *
	 * @return void
	 */
	public static function get_product_info() {
		if ( ! check_ajax_referer( 'jwpb_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'jezpress-woo-pack-builder' ) ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'jezpress-woo-pack-builder' ) ) );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product ID.', 'jezpress-woo-pack-builder' ) ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Product not found.', 'jezpress-woo-pack-builder' ) ) );
		}

		// Disallow packs nested inside packs.
		if ( 'pack' === $product->get_type() ) {
			wp_send_json_error( array( 'message' => __( 'Packs cannot be added as items within another pack.', 'jezpress-woo-pack-builder' ) ) );
		}

		$data = array(
			'product_id' => $product_id,
			'name'       => $product->get_name(),
			'sku'        => $product->get_sku(),
			'type'       => $product->get_type(),
			'price_html' => $product->get_price_html(),
			'variations' => array(),
		);

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_available_variations() as $var ) {
				$variation = wc_get_product( $var['variation_id'] );
				if ( ! $variation ) {
					continue;
				}

				$attrs = array();
				foreach ( $var['attributes'] as $attr_key => $attr_value ) {
					if ( '' === $attr_value ) {
						$attrs[] = __( 'Any', 'jezpress-woo-pack-builder' );
						continue;
					}
					$taxonomy = str_replace( 'attribute_', '', $attr_key );
					if ( taxonomy_exists( $taxonomy ) ) {
						$term    = get_term_by( 'slug', $attr_value, $taxonomy );
						$attrs[] = $term ? $term->name : $attr_value;
					} else {
						$attrs[] = $attr_value;
					}
				}

				$data['variations'][] = array(
					'id'         => $var['variation_id'],
					'label'      => implode( ' / ', $attrs ) ?: ( '#' . $var['variation_id'] ),
					'price_html' => $variation->get_price_html(),
					'sku'        => $variation->get_sku(),
				);
			}
		}

		wp_send_json_success( $data );
	}

	/**
	 * Rotate seasonal items for a pack. Writes to the DB table and returns
	 * the new item list in a format ready for JS to rebuild the table.
	 *
	 * POST params: pack_id (int), nonce (string: jwpb_rotate_{pack_id})
	 *
	 * @return void
	 */
	public static function rotate_seasonal() {
		$pack_id = absint( $_POST['pack_id'] ?? 0 );

		if ( ! $pack_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid pack ID.', 'jezpress-woo-pack-builder' ) ) );
		}

		if ( ! check_ajax_referer( 'jwpb_rotate_' . $pack_id, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'jezpress-woo-pack-builder' ) ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'jezpress-woo-pack-builder' ) ) );
		}

		$product = wc_get_product( $pack_id );
		if ( ! ( $product instanceof WC_Product_Pack ) ) {
			wp_send_json_error( array( 'message' => __( 'Not a pack product.', 'jezpress-woo-pack-builder' ) ) );
		}

		$count    = $product->get_pack_seasonal_count( 'edit' );
		$items    = JWPB_Seasonal::rotate( $pack_id, $count );
		$rotated  = get_post_meta( $pack_id, '_pack_seasonal_last_rotated', true );

		// Enrich items with product display data for JS row rendering.
		$display = array();
		foreach ( $items as $item ) {
			$p = wc_get_product( $item['product_id'] );
			$display[] = array(
				'product_id'   => $item['product_id'],
				'variation_id' => 0,
				'quantity'     => 1,
				'name'         => $p ? $p->get_name() : '#' . $item['product_id'],
				'sku'          => $p ? $p->get_sku() : '',
				'type'         => 'simple',
				'price_html'   => $p ? $p->get_price_html() : '',
				'variations'   => array(),
			);
		}

		wp_send_json_success( array(
			'items'      => $display,
			'rotated_at' => $rotated,
		) );
	}

	/**
	 * Return the count of products currently in the seasonal pool.
	 *
	 * POST params: nonce (string: jwpb_admin_nonce)
	 *
	 * @return void
	 */
	public static function get_pool_count() {
		if ( ! check_ajax_referer( 'jwpb_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'jezpress-woo-pack-builder' ) ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'jezpress-woo-pack-builder' ) ) );
		}

		wp_send_json_success( array( 'count' => JWPB_Seasonal::get_pool_count() ) );
	}

	/**
	 * Create a new pack product (draft) from the Packs admin page.
	 *
	 * POST params: pack_name (string), pricing_mode ('sum'|'fixed'),
	 *              pack_price (decimal string, fixed mode only),
	 *              nonce (string: jwpb_admin_nonce)
	 *
	 * @return void
	 */
	public static function create_pack() {
		if ( ! check_ajax_referer( 'jwpb_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'jezpress-woo-pack-builder' ) ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'jezpress-woo-pack-builder' ) ) );
		}

		$name = sanitize_text_field( wp_unslash( $_POST['pack_name'] ?? '' ) );
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Pack name is required.', 'jezpress-woo-pack-builder' ) ) );
		}

		$pricing_mode = sanitize_key( $_POST['pricing_mode'] ?? 'sum' );
		if ( ! in_array( $pricing_mode, array( 'fixed', 'sum' ), true ) ) {
			$pricing_mode = 'sum';
		}

		$product = new WC_Product_Pack();
		$product->set_name( $name );
		$product->set_status( 'draft' );
		$product->set_pack_pricing_mode( $pricing_mode );

		if ( 'fixed' === $pricing_mode ) {
			$price = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['pack_price'] ?? '' ) ) );
			if ( '' !== $price ) {
				$product->set_pack_price_override( $price );
				$product->set_regular_price( $price );
				$product->set_price( $price );
			}
		}

		$post_id = $product->save();

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Failed to create pack. Please try again.', 'jezpress-woo-pack-builder' ) ) );
		}

		$item_label = sanitize_text_field( wp_unslash( $_POST['item_label'] ?? '' ) );
		$qty_label  = sanitize_text_field( wp_unslash( $_POST['qty_label']  ?? '' ) );
		if ( '' !== $item_label ) {
			update_post_meta( $post_id, '_pack_item_label', $item_label );
		}
		if ( '' !== $qty_label ) {
			update_post_meta( $post_id, '_pack_qty_label', $qty_label );
		}

		wp_send_json_success( array(
			'post_id'  => $post_id,
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
		) );
	}

	/**
	 * Return editable fields for a pack — used to pre-fill the edit form.
	 *
	 * POST params: pack_id (int), nonce (string: jwpb_admin_nonce)
	 *
	 * @return void
	 */
	public static function get_pack_data() {
		if ( ! check_ajax_referer( 'jwpb_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'jezpress-woo-pack-builder' ) ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'jezpress-woo-pack-builder' ) ) );
		}

		$pack_id = absint( $_POST['pack_id'] ?? 0 );
		if ( ! $pack_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid pack ID.', 'jezpress-woo-pack-builder' ) ) );
		}

		$product = wc_get_product( $pack_id );
		if ( ! ( $product instanceof WC_Product_Pack ) ) {
			wp_send_json_error( array( 'message' => __( 'Not a pack product.', 'jezpress-woo-pack-builder' ) ) );
		}

		wp_send_json_success( array(
			'pack_id'      => $pack_id,
			'name'         => $product->get_name(),
			'pricing_mode' => $product->get_pack_pricing_mode( 'edit' ),
			'pack_price'   => $product->get_pack_price_override( 'edit' ),
			'item_label'   => (string) get_post_meta( $pack_id, '_pack_item_label', true ),
			'qty_label'    => (string) get_post_meta( $pack_id, '_pack_qty_label',  true ),
		) );
	}

	/**
	 * Update an existing pack's basic settings from the admin edit form.
	 *
	 * POST params: pack_id (int), pack_name (string), pricing_mode (string),
	 *              pack_price (string), item_label (string), qty_label (string),
	 *              nonce (string: jwpb_admin_nonce)
	 *
	 * @return void
	 */
	public static function update_pack() {
		if ( ! check_ajax_referer( 'jwpb_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'jezpress-woo-pack-builder' ) ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'jezpress-woo-pack-builder' ) ) );
		}

		$pack_id = absint( $_POST['pack_id'] ?? 0 );
		if ( ! $pack_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid pack ID.', 'jezpress-woo-pack-builder' ) ) );
		}

		$product = wc_get_product( $pack_id );
		if ( ! ( $product instanceof WC_Product_Pack ) ) {
			wp_send_json_error( array( 'message' => __( 'Not a pack product.', 'jezpress-woo-pack-builder' ) ) );
		}

		$name = sanitize_text_field( wp_unslash( $_POST['pack_name'] ?? '' ) );
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Pack name is required.', 'jezpress-woo-pack-builder' ) ) );
		}

		$pricing_mode = sanitize_key( $_POST['pricing_mode'] ?? 'sum' );
		if ( ! in_array( $pricing_mode, array( 'fixed', 'sum' ), true ) ) {
			$pricing_mode = 'sum';
		}

		$product->set_name( $name );
		$product->set_pack_pricing_mode( $pricing_mode );

		if ( 'fixed' === $pricing_mode ) {
			$price = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['pack_price'] ?? '' ) ) );
			if ( '' !== $price ) {
				$product->set_pack_price_override( $price );
				$product->set_regular_price( $price );
				$product->set_price( $price );
			}
		}

		$product->save();

		update_post_meta( $pack_id, '_pack_item_label', sanitize_text_field( wp_unslash( $_POST['item_label'] ?? '' ) ) );
		update_post_meta( $pack_id, '_pack_qty_label',  sanitize_text_field( wp_unslash( $_POST['qty_label']  ?? '' ) ) );

		wp_send_json_success( array(
			'redirect_url' => add_query_arg(
				array( 'tab' => 'packs', 'updated' => '1' ),
				admin_url( 'admin.php?page=jwpb-pack-builder' )
			),
		) );
	}
}
