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

	public static function init() {
		add_shortcode( 'jwpb_pack_contents', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_addon_form' ) );
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

		$items = $pack->get_pack_items();
		if ( empty( $items ) ) {
			return '';
		}

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
	 * Render the addon selection form for custom packs.
	 * Hooked into woocommerce_before_add_to_cart_button — fires inside <form class="cart">.
	 *
	 * @return void
	 */
	public static function render_addon_form() {
		global $product;

		if ( ! ( $product instanceof WC_Product_Pack ) || ! $product->is_custom() ) {
			return;
		}

		$addon_fields = $product->get_addon_fields();
		if ( empty( $addon_fields ) ) {
			return;
		}

		// Build display groups — skip fields with no resolvable products.
		$groups = array();

		foreach ( $addon_fields as $field ) {
			$label      = $field['label'] ?? '';
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
					'price_html' => $display_product->get_price_html(),
					'price_raw'  => (float) $display_product->get_price(),
					'input_type' => $field_type,
				);
			}

			if ( empty( $rows ) ) {
				continue;
			}

			$groups[] = array(
				'label'      => $label,
				'field_type' => $field_type,
				'rows'       => $rows,
			);
		}

		if ( empty( $groups ) ) {
			return;
		}

		wp_enqueue_style(
			'jwpb-pack-shortcode',
			JWPB_URL . 'assets/css/shortcode.css',
			array(),
			JWPB_VERSION
		);

		wp_enqueue_script(
			'jwpb-pack-frontend',
			JWPB_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			JWPB_VERSION,
			true
		);

		wp_localize_script( 'jwpb-pack-frontend', 'jwpbFrontend', array(
			'addonSumMode' => 'sum' === $product->get_pack_pricing_mode() ? 1 : 0,
			'basePrice'    => (float) get_post_meta( $product->get_id(), '_price', true ),
			'currency'     => get_woocommerce_currency_symbol(),
			'i18n'         => array(
				'selectAtLeastOne' => __( 'Please select at least one item.', 'jezpress-woo-pack-builder' ),
			),
		) );

		$is_sum_mode = 'sum' === $product->get_pack_pricing_mode();

		ob_start();
		?>
		<div class="jwpb-addon-form" id="jwpb-addon-form">
			<?php foreach ( $groups as $group ) : ?>
			<div class="jwpb-addon-group">
				<?php if ( $group['label'] ) : ?>
				<p class="jwpb-addon-group-heading"><?php echo esc_html( $group['label'] ); ?></p>
				<?php endif; ?>
				<ul class="jwpb-addon-list">
					<?php foreach ( $group['rows'] as $row ) : ?>
					<li class="jwpb-addon-item"
						data-price="<?php echo esc_attr( $row['price_raw'] ); ?>"
						data-name="<?php echo esc_attr( $row['name'] . ( $row['var_label'] ? ' – ' . $row['var_label'] : '' ) ); ?>">
						<?php if ( 'checkbox' === $row['input_type'] ) : ?>
						<label class="jwpb-addon-label">
							<input type="checkbox"
								name="jwpb_addon_sel[<?php echo esc_attr( $row['field_key'] ); ?>]"
								value="1"
								class="jwpb-addon-checkbox">
							<span class="jwpb-addon-name">
								<?php echo esc_html( $row['name'] ); ?>
								<?php if ( $row['var_label'] ) : ?>
									<span class="jwpb-variation-label"><?php echo esc_html( $row['var_label'] ); ?></span>
								<?php endif; ?>
								<span class="jwpb-addon-price">(+<?php echo wp_kses_post( wc_price( $row['price_raw'] ) ); ?>)</span>
							</span>
						</label>
						<?php else : ?>
						<label class="jwpb-addon-label jwpb-addon-label--qty">
							<span class="jwpb-addon-name">
								<?php echo esc_html( $row['name'] ); ?>
								<?php if ( $row['var_label'] ) : ?>
									<span class="jwpb-variation-label"><?php echo esc_html( $row['var_label'] ); ?></span>
								<?php endif; ?>
								<span class="jwpb-addon-price">(+<?php echo wp_kses_post( wc_price( $row['price_raw'] ) ); ?>)</span>
							</span>
							<input type="number"
								name="jwpb_addon_sel[<?php echo esc_attr( $row['field_key'] ); ?>]"
								value="0"
								min="0"
								step="1"
								class="jwpb-addon-qty input-text qty">
						</label>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endforeach; ?>
			<?php if ( $is_sum_mode ) : ?>
			<div class="jwpb-addon-summary" id="jwpb-addon-summary" style="display:none;">
				<div class="jwpb-summary-row jwpb-summary-base">
					<span class="jwpb-summary-label"><?php echo esc_html( $product->get_name() ); ?></span>
					<span class="jwpb-summary-price"><?php echo wp_kses_post( wc_price( (float) get_post_meta( $product->get_id(), '_price', true ) ) ); ?></span>
				</div>
				<div id="jwpb-summary-addons"></div>
			</div>
			<div class="jwpb-addon-total-row" id="jwpb-addon-total-row" style="display:none;">
				<span><?php esc_html_e( 'Subtotal:', 'jezpress-woo-pack-builder' ); ?></span>
				<span id="jwpb-addon-total"></span>
			</div>
			<?php endif; ?>
			<div class="jwpb-addon-error" id="jwpb-addon-error" style="display:none;">
				<?php esc_html_e( 'Please select at least one item.', 'jezpress-woo-pack-builder' ); ?>
			</div>
		</div>
		<?php
		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
