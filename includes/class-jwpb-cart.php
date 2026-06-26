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
		add_filter( 'woocommerce_add_cart_item_data',      array( __CLASS__, 'enrich_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation',  array( __CLASS__, 'validate_custom_pack' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_pack_price' ), 10, 1 );
		add_filter( 'woocommerce_cart_item_name',          array( __CLASS__, 'append_contents_summary' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation',  array( __CLASS__, 'apply_subscription_to_cart' ), 10, 2 );
	}

	/**
	 * Enrich cart item data for pack products:
	 *   - Standard seasonal packs: snapshot current items to prevent mid-session rotation changes.
	 *   - Custom packs: capture customer's addon selections from POST data.
	 *
	 * @param array $cart_item_data Existing cart item data.
	 * @param int   $product_id     Product being added.
	 * @return array
	 */
	public static function enrich_cart_item_data( $cart_item_data, $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! ( $product instanceof WC_Product_Pack ) ) {
			return $cart_item_data;
		}

		if ( $product->is_seasonal() && ! $product->is_custom() ) {
			$cart_item_data['_jwpb_snapshot'] = $product->get_pack_items();
		}

		if ( $product->is_custom() ) {
			$cart_item_data['_jwpb_addon_selections'] = self::parse_addon_selections( $product );
		}

		return $cart_item_data;
	}

	/**
	 * Validate that a custom pack has at least one addon item selected.
	 *
	 * @param bool $passed     Whether validation passed so far.
	 * @param int  $product_id Product being added.
	 * @return bool
	 */
	public static function validate_custom_pack( $passed, $product_id ) {
		if ( ! $passed ) {
			return false;
		}

		$product = wc_get_product( $product_id );

		if ( ! ( $product instanceof WC_Product_Pack ) || ! $product->is_custom() ) {
			return $passed;
		}

		$selections = self::parse_addon_selections( $product );

		if ( empty( $selections ) ) {
			wc_add_notice(
				__( 'Please select at least one item before adding this pack to your cart.', 'jezpress-woo-pack-builder' ),
				'error'
			);
			return false;
		}

		return $passed;
	}

	/**
	 * Parse and validate addon selections from POST data.
	 *
	 * Validates that each submitted key matches an admin-configured addon item
	 * for this pack, preventing customers from injecting arbitrary product IDs.
	 *
	 * @param WC_Product_Pack $product The pack product.
	 * @return array[] Validated selections: [{product_id, variation_id, quantity}]
	 */
	private static function parse_addon_selections( WC_Product_Pack $product ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST['jwpb_addon_sel'] ) && is_array( $_POST['jwpb_addon_sel'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? $_POST['jwpb_addon_sel']
			: array();

		if ( empty( $raw ) ) {
			return array();
		}

		// Build a lookup of admin-configured addon items keyed by "{product_id}_{variation_id}".
		$addon_map = array();
		foreach ( $product->get_addon_items() as $item ) {
			$key               = $item['product_id'] . '_' . $item['variation_id'];
			$addon_map[ $key ] = $item;
		}

		$selections = array();

		foreach ( $raw as $key => $qty_raw ) {
			if ( ! preg_match( '/^\d+_\d+$/', $key ) ) {
				continue;
			}
			if ( ! isset( $addon_map[ $key ] ) ) {
				continue;
			}

			$qty = absint( $qty_raw );
			if ( $qty < 1 ) {
				continue;
			}

			list( $product_id, $variation_id ) = array_map( 'intval', explode( '_', $key, 2 ) );

			$selections[] = array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'quantity'     => $qty,
			);
		}

		return $selections;
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

			if ( $product->is_custom() ) {
				$items = $cart_item['_jwpb_addon_selections'] ?? array();
			} elseif ( isset( $cart_item['_jwpb_snapshot'] ) ) {
				$items = $cart_item['_jwpb_snapshot'];
			} else {
				$items = $product->get_pack_items();
			}

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

		if ( $product->is_custom() ) {
			$items = $cart_item['_jwpb_addon_selections'] ?? array();
		} elseif ( isset( $cart_item['_jwpb_snapshot'] ) ) {
			$items = $cart_item['_jwpb_snapshot'];
		} else {
			$items = $product->get_pack_items();
		}

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
