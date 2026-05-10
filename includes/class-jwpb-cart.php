<?php
/**
 * JWPB_Cart — cart item handling for pack products.
 *
 * Item shape (from JWPB_DB): [ product_id, variation_id, quantity, sort_order ]
 *
 * @package JezPress_Woo_Pack_Builder
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPB_Cart {

	public static function init() {
		add_filter( 'woocommerce_add_cart_item_data',      array( __CLASS__, 'snapshot_seasonal_items' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_pack_price' ), 10, 1 );
		add_filter( 'woocommerce_cart_item_name',          array( __CLASS__, 'append_contents_summary' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation',  array( __CLASS__, 'apply_subscription_to_cart' ), 10, 2 );
	}

	/**
	 * For seasonal packs: snapshot current pack items so a mid-session rotation
	 * doesn't change what the customer sees.
	 *
	 * @param array $cart_item_data Existing cart item data.
	 * @param int   $product_id     Product being added.
	 * @return array
	 */
	public static function snapshot_seasonal_items( $cart_item_data, $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! ( $product instanceof WC_Product_Pack ) || ! $product->is_seasonal() ) {
			return $cart_item_data;
		}

		$cart_item_data['_jwpb_snapshot'] = $product->get_pack_items();

		return $cart_item_data;
	}

	/**
	 * For sum-mode packs: recompute price from constituent products/variations
	 * on every cart total recalculation.
	 *
	 * @param WC_Cart $cart
	 * @return void
	 */
	public static function set_pack_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];

			if ( ! ( $product instanceof WC_Product_Pack ) || 'sum' !== $product->get_pack_pricing_mode() ) {
				continue;
			}

			$items = isset( $cart_item['_jwpb_snapshot'] )
				? $cart_item['_jwpb_snapshot']
				: $product->get_pack_items();

			$total = 0.0;
			foreach ( $items as $item ) {
				$qty       = max( 1, (int) $item['quantity'] );
				$target_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
				$p         = wc_get_product( $target_id );

				if ( $p && $p->is_purchasable() ) {
					$total += (float) $p->get_price() * $qty;
				}
			}

			$product->set_price( $total );
		}
	}

	/**
	 * Append "Contains: …" below the pack name in cart and checkout.
	 *
	 * @param string $name          Product name HTML.
	 * @param array  $cart_item     Cart item data.
	 * @param string $cart_item_key Cart item hash.
	 * @return string
	 */
	public static function append_contents_summary( $name, $cart_item, $cart_item_key ) {
		$product = $cart_item['data'];

		if ( ! ( $product instanceof WC_Product_Pack ) ) {
			return $name;
		}

		$items = isset( $cart_item['_jwpb_snapshot'] )
			? $cart_item['_jwpb_snapshot']
			: $product->get_pack_items();

		if ( empty( $items ) ) {
			return $name;
		}

		$parts = array();
		foreach ( $items as $item ) {
			$target_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
			$p         = wc_get_product( $target_id );

			if ( ! $p ) {
				continue;
			}

			$qty = max( 1, (int) $item['quantity'] );
			$label = esc_html( $p->get_name() );

			// For variations, append attribute summary.
			if ( $p instanceof WC_Product_Variation ) {
				$attrs = array_filter( $p->get_variation_attributes() );
				if ( $attrs ) {
					$label .= ' (' . esc_html( implode( ', ', $attrs ) ) . ')';
				}
			}

			$parts[] = $qty > 1 ? $label . ' &times; ' . $qty : $label;
		}

		if ( empty( $parts ) ) {
			return $name;
		}

		$name .= '<br><small class="jwpb-cart-contents" style="font-weight:normal;color:#666;">'
			. esc_html__( 'Contains:', 'jezpress-woo-pack-builder' ) . ' '
			. implode( ', ', $parts )
			. '</small>';

		return $name;
	}

	/**
	 * Route subscription logic through the bridge when the pack is subscription-enabled.
	 *
	 * @param bool $passed     Whether add-to-cart validation passed.
	 * @param int  $product_id Product ID.
	 * @return bool
	 */
	public static function apply_subscription_to_cart( $passed, $product_id ) {
		if ( ! $passed ) {
			return $passed;
		}

		$product = wc_get_product( $product_id );

		if ( ( $product instanceof WC_Product_Pack ) && $product->is_subscription() ) {
			add_filter( 'woocommerce_add_cart_item', function ( $cart_item ) use ( $product ) {
				return JWPB_Subscription_Bridge::apply_to_cart_item( $cart_item, $product );
			} );
		}

		return $passed;
	}
}
