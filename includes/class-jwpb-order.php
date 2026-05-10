<?php
/**
 * JWPB_Order — order line-item handling for pack products.
 *
 * Packs are stored as a single line item. Pack contents (including variation
 * detail) are copied into order item meta _jwpb_contents for fulfilment.
 *
 * Item shape (from JWPB_DB): [ product_id, variation_id, quantity, sort_order ]
 *
 * @package JezPress_Woo_Pack_Builder
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPB_Order {

	public static function init() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'copy_pack_meta_to_order_item' ), 10, 4 );
		add_filter( 'woocommerce_order_item_display_meta_key',     array( __CLASS__, 'format_meta_key' ), 10, 3 );
		add_filter( 'woocommerce_order_item_display_meta_value',   array( __CLASS__, 'format_meta_value' ), 10, 3 );
		add_filter( 'woocommerce_hidden_order_itemmeta',           array( __CLASS__, 'hide_raw_meta' ) );
	}

	/**
	 * Copy pack items into order item meta at checkout.
	 *
	 * @param WC_Order_Item_Product $item          Order line item.
	 * @param string                $cart_item_key Cart item hash.
	 * @param array                 $values        Cart item data.
	 * @param WC_Order              $order         Order object.
	 * @return void
	 */
	public static function copy_pack_meta_to_order_item( $item, $cart_item_key, $values, $order ) {
		$product = $values['data'] ?? null;

		if ( ! ( $product instanceof WC_Product_Pack ) ) {
			return;
		}

		$raw_items = isset( $values['_jwpb_snapshot'] )
			? $values['_jwpb_snapshot']
			: $product->get_pack_items();

		if ( empty( $raw_items ) ) {
			return;
		}

		$contents = array();

		foreach ( $raw_items as $raw ) {
			$product_id   = (int) $raw['product_id'];
			$variation_id = (int) $raw['variation_id'];
			$qty          = max( 1, (int) $raw['quantity'] );

			$target_id = $variation_id ?: $product_id;
			$p         = wc_get_product( $target_id );

			if ( ! $p ) {
				continue;
			}

			$entry = array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'name'         => $p->get_name(),
				'sku'          => $p->get_sku(),
				'quantity'     => $qty,
				'variation'    => '',
			);

			// Resolve variation attributes for display.
			if ( $variation_id && $p instanceof WC_Product_Variation ) {
				$attrs = array_filter( $p->get_variation_attributes() );
				if ( $attrs ) {
					$readable = array();
					foreach ( $attrs as $key => $slug ) {
						$tax = str_replace( 'attribute_', '', $key );
						if ( taxonomy_exists( $tax ) ) {
							$term       = get_term_by( 'slug', $slug, $tax );
							$readable[] = $term ? $term->name : $slug;
						} else {
							$readable[] = $slug;
						}
					}
					$entry['variation'] = implode( ' / ', $readable );
				}
			}

			$contents[] = $entry;
		}

		$item->add_meta_data( '_jwpb_contents', wp_json_encode( $contents ), true );
	}

	/**
	 * Rename _jwpb_contents → "Pack Contents" in the admin order view.
	 *
	 * @param string                $key  Raw meta key.
	 * @param WC_Meta_Data          $meta Meta object.
	 * @param WC_Order_Item_Product $item Order item.
	 * @return string
	 */
	public static function format_meta_key( $key, $meta, $item ) {
		return '_jwpb_contents' === $meta->key ? __( 'Pack Contents', 'jezpress-woo-pack-builder' ) : $key;
	}

	/**
	 * Render pack contents as a human-readable list in the admin order view.
	 *
	 * @param string                $value Displayed value.
	 * @param WC_Meta_Data          $meta  Meta object.
	 * @param WC_Order_Item_Product $item  Order item.
	 * @return string
	 */
	public static function format_meta_value( $value, $meta, $item ) {
		if ( '_jwpb_contents' !== $meta->key ) {
			return $value;
		}

		$contents = json_decode( $meta->value, true );

		if ( ! is_array( $contents ) || empty( $contents ) ) {
			return $value;
		}

		$lines = array();
		foreach ( $contents as $c ) {
			$line = esc_html( $c['name'] );

			if ( ! empty( $c['variation'] ) ) {
				$line .= ' <em style="color:#666;">— ' . esc_html( $c['variation'] ) . '</em>';
			}

			if ( ! empty( $c['sku'] ) ) {
				$line .= ' <span style="color:#999;">(' . esc_html( $c['sku'] ) . ')</span>';
			}

			if ( (int) $c['quantity'] > 1 ) {
				$line .= ' &times; ' . (int) $c['quantity'];
			}

			$lines[] = $line;
		}

		return '<ul style="margin:0;padding-left:1.2em;"><li>'
			. implode( '</li><li>', $lines )
			. '</li></ul>';
	}

	/**
	 * Hide the raw JSON key from the admin order item meta display.
	 *
	 * @param array $hidden Hidden meta keys.
	 * @return array
	 */
	public static function hide_raw_meta( $hidden ) {
		$hidden[] = '_pack_items';
		return $hidden;
	}
}
