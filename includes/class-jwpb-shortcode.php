<?php
/**
 * JWPB_Shortcode — [jwpb_pack_contents] shortcode.
 *
 * Renders the item list of a pack product as a table. Product names are
 * linked to their shop pages; variation items link to the parent product page
 * with attribute query-params so WooCommerce pre-selects the variant on load.
 *
 * Usage:
 *   [jwpb_pack_contents]           — auto-detects current product page
 *   [jwpb_pack_contents id="123"]  — explicit pack product ID
 *
 * @package JezPress_Woo_Pack_Builder
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPB_Shortcode {

	/**
	 * Guard against rendering the same pack's contents more than once per
	 * page load (auto-inject placement + a manually placed shortcode/widget
	 * both firing for the same pack). Keyed by pack ID so distinct packs
	 * shown together — e.g. in a related/upsell loop — still each render.
	 *
	 * @var array<int,true>
	 */
	private static $rendered_pack_ids = array();

	public static function init() {
		add_shortcode( 'jwpb_pack_contents', array( __CLASS__, 'render' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Unused inner content.
	 * @return string HTML or empty string.
	 */
	public static function render( $atts, $content = '' ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'jwpb_pack_contents' );

		$pack_id = absint( $atts['id'] );

		// Auto-detect: use the global $product inside a WC single-product template,
		// or fall back to the current post ID on a product singular page.
		if ( ! $pack_id ) {
			global $product;
			if ( $product instanceof WC_Product ) {
				$pack_id = $product->get_id();
			} elseif ( is_singular( 'product' ) ) {
				$pack_id = get_the_ID();
			}
		}

		if ( ! $pack_id ) {
			return '';
		}

		$pack = wc_get_product( $pack_id );
		if ( ! ( $pack instanceof WC_Product_Pack ) ) {
			return '';
		}

		// Custom packs have no fixed items — render the addon selection form instead.
		if ( $pack->is_custom() ) {
			return self::get_addon_form_html( $pack );
		}

		if ( isset( self::$rendered_pack_ids[ $pack_id ] ) ) {
			return '';
		}

		$items = $pack->get_pack_items();
		if ( empty( $items ) ) {
			return '';
		}

		self::$rendered_pack_ids[ $pack_id ] = true;

		wp_enqueue_style(
			'jwpb-pack-shortcode',
			JWPB_URL . 'assets/css/shortcode.css',
			array(),
			JWPB_VERSION
		);

		$item_label = (string) get_post_meta( $pack_id, '_pack_item_label', true );
		$qty_label  = (string) get_post_meta( $pack_id, '_pack_qty_label',  true );
		$item_label = '' !== $item_label ? $item_label : __( 'Product',  'jezpress-woo-pack-builder' );
		$qty_label  = '' !== $qty_label  ? $qty_label  : __( 'Quantity', 'jezpress-woo-pack-builder' );

		// Build row data — resolve product/variation details once per item.
		$rows = array();

		foreach ( $items as $item ) {
			$product_id   = (int) $item['product_id'];
			$variation_id = (int) $item['variation_id'];
			$qty          = max( 1, (int) $item['quantity'] );

			$parent = wc_get_product( $product_id );
			if ( ! $parent ) {
				continue;
			}

			if ( $variation_id ) {
				$variation = wc_get_product( $variation_id );

				if ( $variation instanceof WC_Product_Variation ) {
					$url   = self::variation_url( $variation, $parent );
					$label = self::variation_label( $variation );
				} else {
					// Variation deleted or unavailable — fall back to parent.
					$url   = get_permalink( $product_id );
					$label = '';
				}
			} else {
				$url   = get_permalink( $product_id );
				$label = '';
			}

			$rows[] = array(
				'name'  => $parent->get_name(),
				'url'   => $url,
				'label' => $label,
				'qty'   => $qty,
			);
		}

		if ( empty( $rows ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="jwpb-pack-contents">
			<table class="jwpb-pack-table">
				<thead>
					<tr>
						<th class="jwpb-col-product"><?php echo esc_html( $item_label ); ?></th>
						<th class="jwpb-col-qty"><?php echo esc_html( $qty_label ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
					<tr class="jwpb-pack-row">
						<td class="jwpb-col-product">
							<a href="<?php echo esc_url( $row['url'] ); ?>" class="jwpb-product-link">
								<?php echo esc_html( $row['name'] ); ?>
							</a>
							<?php if ( $row['label'] ) : ?>
								<span class="jwpb-variation-label">
									<?php echo esc_html( $row['label'] ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td class="jwpb-col-qty">
							<?php echo esc_html( $row['qty'] ); ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Build the parent product URL with variation attributes as query params.
	 *
	 * WooCommerce reads these on page load and pre-selects the matching
	 * variation: e.g. ?attribute_pa_color=red&attribute_pa_size=large
	 *
	 * "Any" attributes (empty string slug) are omitted — they cannot be
	 * pre-selected to a specific value.
	 *
	 * @param WC_Product_Variation $variation
	 * @param WC_Product           $parent
	 * @return string
	 */
	private static function variation_url( WC_Product_Variation $variation, WC_Product $parent ) {
		$base       = get_permalink( $parent->get_id() );
		$attributes = $variation->get_variation_attributes();

		// Drop "any" slots — keep only explicitly chosen attribute values.
		$query_args = array_filter( $attributes, function ( $value ) {
			return '' !== $value;
		} );

		return empty( $query_args ) ? $base : add_query_arg( $query_args, $base );
	}

	/**
	 * Build and return the addon selection form HTML for a custom pack.
	 *
	 * Called by auto_inject() (via JWPB_Settings) and by render() when the
	 * shortcode is used on a custom pack. A static guard prevents the form
	 * from rendering twice if both paths fire on the same page load.
	 *
	 * @param WC_Product_Pack $pack
	 * @return string HTML or empty string.
	 */
	public static function get_addon_form_html( WC_Product_Pack $pack ) {
		if ( ! $pack->is_custom() || isset( self::$rendered_pack_ids[ $pack->get_id() ] ) ) {
			return '';
		}

		$addon_fields = $pack->get_addon_fields();
		if ( empty( $addon_fields ) ) {
			return '';
		}

		// Build display groups — skip fields with no resolvable products.
		$groups = array();

		foreach ( $addon_fields as $field ) {
			$field_type = in_array( $field['field_type'] ?? '', array( 'checkbox', 'input' ), true )
				? $field['field_type'] : 'checkbox';
			$rows = array();

			foreach ( $field['products'] ?? array() as $p ) {
				$product_id   = (int) $p['product_id'];
				$variation_id = (int) $p['variation_id'];

				$parent = wc_get_product( $product_id );
				if ( ! $parent ) {
					continue;
				}

				if ( $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation instanceof WC_Product_Variation ) {
						$display_product = $variation;
						$var_label       = self::variation_label( $variation );
					} else {
						$display_product = $parent;
						$var_label       = '';
					}
				} else {
					$display_product = $parent;
					$var_label       = '';
				}

				$rows[] = array(
					'field_key'  => $product_id . '_' . $variation_id,
					'name'       => $parent->get_name(),
					'var_label'  => $var_label,
					'price_raw'  => (float) $display_product->get_price(),
					'input_type' => $field_type,
				);
			}

			if ( ! empty( $rows ) ) {
				$groups[] = array(
					'label' => $field['label'] ?? '',
					'rows'  => $rows,
				);
			}
		}

		if ( empty( $groups ) ) {
			return '';
		}

		self::$rendered_pack_ids[ $pack->get_id() ] = true;

		$is_sum_mode = 'sum' === $pack->get_pack_pricing_mode();
		$base_price  = (float) get_post_meta( $pack->get_id(), '_price', true );

		wp_enqueue_style( 'jwpb-pack-shortcode', JWPB_URL . 'assets/css/shortcode.css', array(), JWPB_VERSION );
		wp_enqueue_script( 'jwpb-pack-frontend', JWPB_URL . 'assets/js/frontend.js', array( 'jquery' ), JWPB_VERSION, true );
		wp_localize_script( 'jwpb-pack-frontend', 'jwpbFrontend', array(
			'addonSumMode' => $is_sum_mode ? 1 : 0,
			'basePrice'    => $base_price,
			'currency'     => get_woocommerce_currency_symbol(),
		) );

		$html = '<div class="jwpb-addon-form" id="jwpb-addon-form">';

		foreach ( $groups as $group ) {
			$html .= '<div class="jwpb-addon-group">';
			if ( $group['label'] ) {
				$html .= '<p class="jwpb-addon-group-heading">' . esc_html( $group['label'] ) . '</p>';
			}
			$html .= '<ul class="jwpb-addon-list">';
			foreach ( $group['rows'] as $row ) {
				$html .= sprintf(
					'<li class="jwpb-addon-item" data-price="%s" data-name="%s">%s</li>',
					esc_attr( $row['price_raw'] ),
					esc_attr( $row['name'] . ( $row['var_label'] ? ' – ' . $row['var_label'] : '' ) ),
					self::addon_row_label( $row )
				);
			}
			$html .= '</ul></div>';
		}

		if ( $is_sum_mode ) {
			$html .= sprintf(
				'<div class="jwpb-addon-summary" id="jwpb-addon-summary" style="display:none;"><div class="jwpb-summary-row jwpb-summary-base"><span class="jwpb-summary-label">%s</span><span class="jwpb-summary-price">%s</span></div><div id="jwpb-summary-addons"></div></div><div class="jwpb-addon-total-row" id="jwpb-addon-total-row" style="display:none;"><span>%s</span><span id="jwpb-addon-total"></span></div>',
				esc_html( $pack->get_name() ),
				wp_kses_post( wc_price( $base_price ) ),
				esc_html__( 'Subtotal:', 'jezpress-woo-pack-builder' )
			);
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Build the <label> HTML for a single addon row.
	 *
	 * @param array $row  Keys: field_key, name, var_label, price_raw, input_type.
	 * @return string
	 */
	private static function addon_row_label( array $row ) {
		$name_span = '<span class="jwpb-addon-name">' . esc_html( $row['name'] );
		if ( $row['var_label'] ) {
			$name_span .= ' <span class="jwpb-variation-label">' . esc_html( $row['var_label'] ) . '</span>';
		}
		$name_span .= ' <span class="jwpb-addon-price">(+' . wp_kses_post( wc_price( $row['price_raw'] ) ) . ')</span></span>';

		$input_name = 'jwpb_addon_sel[' . esc_attr( $row['field_key'] ) . ']';

		if ( 'checkbox' === $row['input_type'] ) {
			return '<label class="jwpb-addon-label"><input type="checkbox" name="' . $input_name . '" value="1" class="jwpb-addon-checkbox">' . $name_span . '</label>';
		}

		return '<label class="jwpb-addon-label jwpb-addon-label--qty">' . $name_span . '<input type="number" name="' . $input_name . '" value="0" min="0" step="1" class="jwpb-addon-qty"></label>';
	}

	/**
	 * Resolve variation attributes to a human-readable label ("Red / Large").
	 *
	 * Taxonomy-based attributes (pa_*) are resolved to term names; custom
	 * (non-taxonomy) attributes use the raw slug value.
	 *
	 * @param WC_Product_Variation $variation
	 * @return string  Empty string when no specific attributes are set.
	 */
	private static function variation_label( WC_Product_Variation $variation ) {
		$attributes = array_filter( $variation->get_variation_attributes(), function ( $v ) {
			return '' !== $v;
		} );

		if ( empty( $attributes ) ) {
			return '';
		}

		$parts = array();

		foreach ( $attributes as $key => $slug ) {
			$taxonomy = str_replace( 'attribute_', '', $key );

			if ( taxonomy_exists( $taxonomy ) ) {
				$term     = get_term_by( 'slug', $slug, $taxonomy );
				$parts[]  = $term ? $term->name : $slug;
			} else {
				$parts[] = $slug;
			}
		}

		return implode( ' / ', $parts );
	}
}
