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
		add_action( 'woocommerce_order_item_meta_end',            array( __CLASS__, 'render_pack_contents' ), 10, 4 );
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

		if ( $product->is_custom() ) {
			$raw_items = $values['_jwpb_addon_selections'] ?? array();
		} elseif ( isset( $values['_jwpb_snapshot'] ) ) {
			$raw_items = $values['_jwpb_snapshot'];
		} else {
			$raw_items = $product->get_pack_items();
		}

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

		$lines = self::build_content_lines( $contents, $item, true );

		return '<ul style="margin:0;padding-left:1.2em;"><li>'
			. implode( '</li><li>', $lines )
			. '</li></ul>';
	}

	/**
	 * Render pack contents in customer-facing order item output.
	 *
	 * The contents are stored under the underscore-prefixed key _jwpb_contents,
	 * and wc_display_item_meta() hides every meta key starting with an underscore
	 * (WC_Order_Item::get_formatted_meta_data() defaults to $hideprefix = '_'). The
	 * admin order screen is the only place that passes '' instead, which is why
	 * format_meta_key()/format_meta_value() only ever showed up there. Everything
	 * the customer sees — all order emails (including the pre-order confirmation
	 * and release notice), the thank-you page and My Account → order details —
	 * therefore listed the pack line with no contents at all.
	 *
	 * woocommerce_order_item_meta_end fires in exactly those customer-facing
	 * templates and not in the admin meta view, so there is no double render.
	 *
	 * @param int           $item_id    Order item ID.
	 * @param WC_Order_Item $item       Order item.
	 * @param WC_Order      $order      Order object.
	 * @param bool          $plain_text Whether this is the plain-text email.
	 * @return void
	 */
	public static function render_pack_contents( $item_id, $item, $order, $plain_text = false ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$raw = $item->get_meta( '_jwpb_contents', true );

		if ( ! $raw ) {
			return;
		}

		$contents = json_decode( $raw, true );

		if ( ! is_array( $contents ) || empty( $contents ) ) {
			return;
		}

		$label = __( 'Pack Contents', 'jezpress-woo-pack-builder' );

		if ( $plain_text ) {
			echo "\n" . esc_html( $label ) . ":\n";

			foreach ( self::build_content_lines( $contents, $item, false ) as $line ) {
				echo ' - ' . esc_html( $line ) . "\n";
			}

			return;
		}

		$lines = self::build_content_lines( $contents, $item, true );

		echo '<ul class="wc-item-meta jwpb-pack-contents" style="margin:0.25em 0 0;padding-left:1.2em;font-size:0.9em;list-style:disc;">';
		echo '<li style="margin:0;list-style:none;padding:0;"><strong>' . esc_html( $label ) . '</strong>';
		echo '<ul style="margin:0;padding-left:1.2em;list-style:disc;"><li style="margin:0;">'
			. implode( '</li><li style="margin:0;">', $lines ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in format_content_line().
			. '</li></ul></li></ul>';
	}

	/**
	 * Format every contents entry into a display line, giving other plugins a
	 * chance to annotate each one.
	 *
	 * The `jwpb_order_item_content_line` filter is what JezPress Woo Pre-Order
	 * hooks to append "(Pre-order)" to the individual pack items still awaiting
	 * release — that state lives in its order item meta, not in _jwpb_contents,
	 * so this plugin has no way to know it on its own. Both the admin and the
	 * customer-facing renderer run entries through here, so an annotation
	 * appears in every view rather than only some of them.
	 *
	 * @param array                 $contents Decoded _jwpb_contents entries.
	 * @param WC_Order_Item_Product $item     Order item the contents belong to.
	 * @param bool                  $html     True for HTML output, false for plain text.
	 * @return string[] Lines, HTML-escaped when $html is true.
	 */
	private static function build_content_lines( $contents, $item, $html ) {
		$lines = array();

		foreach ( $contents as $c ) {
			/**
			 * Filter a single pack contents line.
			 *
			 * @since 1.3.3
			 * @param string                $line Formatted line; escaped when $html is true.
			 * @param array                 $c    The contents entry.
			 * @param WC_Order_Item_Product $item Order item.
			 * @param bool                  $html Whether $line is HTML or plain text.
			 */
			$lines[] = apply_filters(
				'jwpb_order_item_content_line',
				self::format_content_line( $c, $html ),
				$c,
				$item,
				$html
			);
		}

		return $lines;
	}

	/**
	 * Build a single "Name — Variation (SKU) × N" line from a contents entry.
	 *
	 * @param array $c    Contents entry.
	 * @param bool  $html Whether to wrap the variation/SKU in markup.
	 * @return string HTML-escaped string when $html is true, raw text otherwise.
	 */
	private static function format_content_line( $c, $html ) {
		$name      = isset( $c['name'] ) ? (string) $c['name'] : '';
		$variation = isset( $c['variation'] ) ? (string) $c['variation'] : '';
		$sku       = isset( $c['sku'] ) ? (string) $c['sku'] : '';
		$qty       = isset( $c['quantity'] ) ? (int) $c['quantity'] : 1;

		if ( ! $html ) {
			$line = $name;

			if ( '' !== $variation ) {
				$line .= ' — ' . $variation;
			}

			if ( '' !== $sku ) {
				$line .= ' (' . $sku . ')';
			}

			return $qty > 1 ? $line . ' x ' . $qty : $line;
		}

		$line = esc_html( $name );

		if ( '' !== $variation ) {
			$line .= ' <em style="color:#666;">— ' . esc_html( $variation ) . '</em>';
		}

		if ( '' !== $sku ) {
			$line .= ' <span style="color:#999;">(' . esc_html( $sku ) . ')</span>';
		}

		return $qty > 1 ? $line . ' &times; ' . $qty : $line;
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
