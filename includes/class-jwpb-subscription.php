<?php
/**
 * JWPB_Subscription_Bridge — compatibility shim for subscription engines.
 *
 * Detects whether JezPress WC Subscription or WooCommerce Subscriptions is
 * active and routes subscription logic through whichever is available.
 *
 * @package JezPress_Woo_Pack_Builder
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPB_Subscription_Bridge {

	/**
	 * Which engine is active: 'jezpress', 'woocommerce', or null.
	 *
	 * @return string|null
	 */
	public static function get_engine() {
		if ( class_exists( 'JezPress_WC_Subscription' ) ) {
			return 'jezpress';
		}
		if ( class_exists( 'WC_Subscriptions_Product' ) ) {
			return 'woocommerce';
		}
		return null;
	}

	/**
	 * True when any subscription engine is present.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return null !== self::get_engine();
	}

	/**
	 * Apply subscription data to a WooCommerce cart item.
	 *
	 * Returns the (possibly modified) cart item array. When no engine is
	 * active the cart item is returned unchanged so the pack still functions
	 * as a regular one-time purchase.
	 *
	 * @param array      $cart_item WC cart item data.
	 * @param WC_Product $product   The pack product.
	 * @return array
	 */
	public static function apply_to_cart_item( array $cart_item, WC_Product $product ) {
		if ( ! ( $product instanceof WC_Product_Pack ) || ! $product->is_subscription() ) {
			return $cart_item;
		}

		$engine = self::get_engine();

		if ( 'woocommerce' === $engine ) {
			$cart_item['subscription_renewal'] = false;

			// WC Subscriptions reads these keys off the product meta, so we
			// write them into the cart item's product data clone.
			$data = $cart_item['data'];
			$data->update_meta_data( '_subscription_period',          $product->get_pack_subscription_period() );
			$data->update_meta_data( '_subscription_period_interval', $product->get_pack_subscription_interval() );
			$data->update_meta_data( '_subscription_length',          $product->get_pack_subscription_length() );
		} elseif ( 'jezpress' === $engine && method_exists( 'JezPress_WC_Subscription', 'apply_to_cart_item' ) ) {
			$cart_item = JezPress_WC_Subscription::apply_to_cart_item( $cart_item, $product );
		}

		return $cart_item;
	}

	/**
	 * Human-readable billing summary, e.g. "every 2 weeks".
	 *
	 * @param WC_Product_Pack $pack Pack product.
	 * @return string
	 */
	public static function get_billing_description( WC_Product_Pack $pack ) {
		if ( ! $pack->is_subscription() ) {
			return '';
		}

		$interval = $pack->get_pack_subscription_interval();
		$period   = $pack->get_pack_subscription_period();

		$period_labels = array(
			'day'   => _n( 'day',   'days',   $interval, 'jezpress-woo-pack-builder' ),
			'week'  => _n( 'week',  'weeks',  $interval, 'jezpress-woo-pack-builder' ),
			'month' => _n( 'month', 'months', $interval, 'jezpress-woo-pack-builder' ),
			'year'  => _n( 'year',  'years',  $interval, 'jezpress-woo-pack-builder' ),
		);

		$label = isset( $period_labels[ $period ] ) ? $period_labels[ $period ] : $period;

		if ( 1 === (int) $interval ) {
			/* translators: %s: billing period e.g. "month" */
			return sprintf( __( 'every %s', 'jezpress-woo-pack-builder' ), $label );
		}

		/* translators: 1: interval number, 2: period label e.g. "2 months" */
		return sprintf( __( 'every %1$d %2$s', 'jezpress-woo-pack-builder' ), $interval, $label );
	}
}
