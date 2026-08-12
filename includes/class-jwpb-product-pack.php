<?php
/**
 * WC_Product_Pack — custom WooCommerce product type.
 *
 * Pack items are stored in the dedicated {prefix}jwpb_pack_items table via
 * JWPB_DB. All other pack settings (pricing, subscription, seasonal, pack_type)
 * use standard WooCommerce postmeta via get_prop / set_prop.
 *
 * Two pack types:
 *   standard — fixed bundled items, same for all customers (default)
 *   custom   — admin defines selectable addon products; customers pick at checkout
 *
 * @package JezPress_Woo_Pack_Builder
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Product_Pack extends WC_Product {

	protected $extra_data = array(
		'pack_type'                  => 'standard',
		'pack_pricing_mode'          => 'sum',
		'pack_price_override'        => '',
		'pack_subscription_enabled'  => 'no',
		'pack_subscription_interval' => 1,
		'pack_subscription_period'   => 'month',
		'pack_subscription_length'   => 0,
		'pack_seasonal_enabled'      => 'no',
		'pack_seasonal_count'        => 3,
		'pack_seasonal_last_rotated' => '',
	);

	/**
	 * Final per-cart-item price for custom packs (base + selected addons), frozen
	 * by JWPB_Cart::set_pack_price(). While set, get_price() returns it directly
	 * instead of delegating to parent::get_price() — which would run WC's price
	 * filters a second time and double-convert it under a multi-currency plugin.
	 *
	 * @var string|null
	 */
	protected $resolved_cart_price = null;

	public function __construct( $product = 0 ) {
		$this->supports[] = 'ajax_add_to_cart';
		parent::__construct( $product );
	}

	public function get_type() {
		return 'pack';
	}

	// -------------------------------------------------------------------------
	// Price — custom packs use WC native price (General tab); standard packs
	// use sum-of-items or a fixed override.
	// -------------------------------------------------------------------------

	public function get_price( $context = 'view' ) {
		if ( null !== $this->resolved_cart_price ) {
			return $this->resolved_cart_price;
		}

		if ( $this->is_custom() ) {
			// WC General tab manages _price / _regular_price / _sale_price.
			// Cart adds selected addon prices on top via JWPB_Cart::set_pack_price().
			return parent::get_price( $context );
		}

		if ( 'sum' === $this->get_pack_pricing_mode() ) {
			return $this->calculate_sum_price();
		}

		$override = $this->get_pack_price_override( $context );
		if ( '' === $override ) {
			return parent::get_price( $context );
		}

		// Run the override through the same WC price filter parent::get_price()
		// would use (skipped here since we're not calling it) so multi-currency
		// plugins still get a chance to convert it — otherwise a fixed override
		// always displays in the store's base currency.
		return 'view' === $context ? apply_filters( $this->get_hook_prefix() . 'price', $override, $this ) : $override;
	}

	/**
	 * Base price for custom packs, filtered exactly once through WC's price
	 * filters (so multi-currency plugins convert it), read from postmeta
	 * directly so it's unaffected by any set_price() call already made on this
	 * object earlier in the request.
	 *
	 * @return float
	 */
	public function get_custom_base_price() {
		$price = get_post_meta( $this->get_id(), '_price', true );
		return (float) apply_filters( $this->get_hook_prefix() . 'price', $price, $this );
	}

	/**
	 * Freeze this cart item's fully-computed price. See $resolved_cart_price.
	 *
	 * @param string|float $price Final price for this cart item.
	 */
	public function set_resolved_cart_price( $price ) {
		$this->resolved_cart_price = (string) $price;
	}

	public function is_purchasable() {
		if ( $this->is_custom() ) {
			return ! empty( $this->get_addon_items() ) && parent::is_purchasable();
		}
		return ! empty( $this->get_pack_items() ) && parent::is_purchasable();
	}

	// -------------------------------------------------------------------------
	// Pack items — source of truth is the custom DB table.
	// -------------------------------------------------------------------------

	/**
	 * Return fixed pack items (standard packs only) from the database.
	 *
	 * @param string $context Ignored — DB is always the source.
	 * @return array[]
	 */
	public function get_pack_items( $context = 'view' ) {
		$id = $this->get_id();
		if ( ! $id ) {
			return array();
		}
		return JWPB_DB::get_pack_items( $id );
	}

	/**
	 * Return addon field groups (custom packs only) from postmeta.
	 *
	 * Each field: [ label (string), quantity (int), products[] => [ product_id, variation_id ] ]
	 *
	 * @return array[]
	 */
	public function get_addon_fields( $context = 'view' ) {
		$id = $this->get_id();
		if ( ! $id ) {
			return array();
		}
		$json   = get_post_meta( $id, '_pack_addon_fields', true );
		$fields = $json ? json_decode( $json, true ) : array();
		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Return a flat list of selectable addon products derived from field groups.
	 *
	 * Each item: [ product_id (int), variation_id (int), input_type (string) ]
	 *
	 * @param string $context Unused — postmeta is always the source.
	 * @return array[]
	 */
	public function get_addon_items( $context = 'view' ) {
		$items = array();
		foreach ( $this->get_addon_fields() as $field ) {
			$input_type = in_array( $field['field_type'] ?? '', array( 'checkbox', 'input' ), true )
				? $field['field_type']
				: 'checkbox';
			foreach ( $field['products'] ?? array() as $p ) {
				$items[] = array(
					'product_id'   => $p['product_id'],
					'variation_id' => $p['variation_id'],
					'input_type'   => $input_type,
				);
			}
		}
		return $items;
	}

	// -------------------------------------------------------------------------
	// Getters for postmeta-backed properties
	// -------------------------------------------------------------------------

	public function get_pack_type( $context = 'view' ) {
		$type = $this->get_prop( 'pack_type', $context );
		return in_array( $type, array( 'standard', 'custom' ), true ) ? $type : 'standard';
	}

	public function get_pack_pricing_mode( $context = 'view' ) {
		return $this->get_prop( 'pack_pricing_mode', $context );
	}

	public function get_pack_price_override( $context = 'view' ) {
		return $this->get_prop( 'pack_price_override', $context );
	}

	public function get_pack_subscription_enabled( $context = 'view' ) {
		return $this->get_prop( 'pack_subscription_enabled', $context );
	}

	public function get_pack_subscription_interval( $context = 'view' ) {
		return $this->get_prop( 'pack_subscription_interval', $context );
	}

	public function get_pack_subscription_period( $context = 'view' ) {
		return $this->get_prop( 'pack_subscription_period', $context );
	}

	public function get_pack_subscription_length( $context = 'view' ) {
		return $this->get_prop( 'pack_subscription_length', $context );
	}

	public function get_pack_seasonal_enabled( $context = 'view' ) {
		return $this->get_prop( 'pack_seasonal_enabled', $context );
	}

	public function get_pack_seasonal_count( $context = 'view' ) {
		return $this->get_prop( 'pack_seasonal_count', $context );
	}

	public function get_pack_seasonal_last_rotated( $context = 'view' ) {
		return $this->get_prop( 'pack_seasonal_last_rotated', $context );
	}

	// -------------------------------------------------------------------------
	// Setters
	// -------------------------------------------------------------------------

	public function set_pack_type( $type ) {
		$this->set_prop( 'pack_type', in_array( $type, array( 'standard', 'custom' ), true ) ? $type : 'standard' );
	}

	public function set_pack_pricing_mode( $mode ) {
		$this->set_prop( 'pack_pricing_mode', in_array( $mode, array( 'fixed', 'sum' ), true ) ? $mode : 'sum' );
	}

	public function set_pack_price_override( $price ) {
		$this->set_prop( 'pack_price_override', wc_format_decimal( $price ) );
	}

	public function set_pack_subscription_enabled( $value ) {
		$this->set_prop( 'pack_subscription_enabled', wc_bool_to_string( $value ) );
	}

	public function set_pack_subscription_interval( $value ) {
		$this->set_prop( 'pack_subscription_interval', absint( $value ) ?: 1 );
	}

	public function set_pack_subscription_period( $period ) {
		$valid = array( 'day', 'week', 'month', 'year' );
		$this->set_prop( 'pack_subscription_period', in_array( $period, $valid, true ) ? $period : 'month' );
	}

	public function set_pack_subscription_length( $value ) {
		$this->set_prop( 'pack_subscription_length', absint( $value ) );
	}

	public function set_pack_seasonal_enabled( $value ) {
		$this->set_prop( 'pack_seasonal_enabled', wc_bool_to_string( $value ) );
	}

	public function set_pack_seasonal_count( $value ) {
		$this->set_prop( 'pack_seasonal_count', absint( $value ) ?: 1 );
	}

	public function set_pack_seasonal_last_rotated( $datetime ) {
		$this->set_prop( 'pack_seasonal_last_rotated', sanitize_text_field( $datetime ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	public function is_custom() {
		return 'custom' === $this->get_pack_type();
	}

	public function is_subscription() {
		return 'yes' === $this->get_pack_subscription_enabled();
	}

	public function is_seasonal() {
		return 'yes' === $this->get_pack_seasonal_enabled();
	}

	/**
	 * Sum prices of constituent products/variations.
	 *
	 * Standard packs: sum of item prices × quantities.
	 * Custom packs: sum of all addon prices at qty 1 (represents the maximum possible price).
	 *
	 * @return string Decimal price string, or '' if no purchasable items.
	 */
	private function calculate_sum_price() {
		$total = 0.0;

		if ( $this->is_custom() ) {
			foreach ( $this->get_addon_items() as $item ) {
				$target_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
				$product   = wc_get_product( $target_id );
				if ( $product && $product->is_purchasable() ) {
					$total += (float) $product->get_price();
				}
			}
		} else {
			foreach ( $this->get_pack_items() as $item ) {
				$qty       = max( 1, (int) $item['quantity'] );
				$target_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
				$product   = wc_get_product( $target_id );
				if ( $product && $product->is_purchasable() ) {
					$total += (float) $product->get_price() * $qty;
				}
			}
		}

		return $total > 0 ? (string) $total : '';
	}

	/**
	 * Total quantity of all items across all pack slots (standard packs only).
	 *
	 * @return int
	 */
	public function get_item_count() {
		$count = 0;
		foreach ( $this->get_pack_items() as $item ) {
			$count += max( 1, (int) $item['quantity'] );
		}
		return $count;
	}
}
