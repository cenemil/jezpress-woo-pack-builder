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
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_pack_price' ), 10, 1 );
		add_filter( 'woocommerce_cart_item_name',          array( __CLASS__, 'append_contents_summary' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation',  array( __CLASS__, 'apply_subscription_to_cart' ), 10, 2 );
		add_filter( 'woocommerce_cart_shipping_packages',  array( __CLASS__, 'inject_pack_weight_into_packages' ) );

		if ( JWPB_Settings::get( 'shipping_weight_debug', false ) ) {
			add_action( 'woocommerce_cart_totals_after_order_total',  array( __CLASS__, 'render_weight_debug' ) );
			add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'render_weight_debug' ) );
		}
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

			if ( ! ( $product instanceof WC_Product_Pack ) ) {
				continue;
			}

			if ( $product->is_custom() ) {
				// Base price via get_custom_base_price() (postmeta, filtered once for
				// multi-currency) rather than get_price() / raw postmeta directly, so
				// the value is stable even if set_price()/set_weight() were already
				// called this request and is still converted exactly once.
				$total  = $product->get_custom_base_price();
				$weight = (float) get_post_meta( $product->get_id(), '_weight', true );
				$items  = $cart_item['_jwpb_addon_selections'] ?? array();

				foreach ( $items as $item ) {
					$qty       = max( 1, (int) $item['quantity'] );
					$target_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
					$p         = wc_get_product( $target_id );
					if ( $p && $p->is_purchasable() ) {
						$total  += (float) $p->get_price() * $qty;
						$weight += (float) $p->get_weight() * $qty;
					}
				}

				// Freeze the computed total: get_price() now returns it directly instead
				// of running it back through WC's price filters, which would otherwise
				// convert an already-converted total a second time under a multi-currency
				// plugin (e.g. YITH Multi Currency Switcher).
				$product->set_resolved_cart_price( $total );
				$product->set_price( $total );
				$product->set_weight( $weight );
				continue;
			}

			// Standard pack: recompute weight from items regardless of pricing mode.
			$items  = isset( $cart_item['_jwpb_snapshot'] ) ? $cart_item['_jwpb_snapshot'] : $product->get_pack_items();
			$weight = (float) get_post_meta( $product->get_id(), '_weight', true );

			foreach ( $items as $item ) {
				$qty       = max( 1, (int) $item['quantity'] );
				$target_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
				$p         = wc_get_product( $target_id );
				if ( $p ) {
					$weight += (float) $p->get_weight() * $qty;
				}
			}

			$product->set_weight( $weight );

			// Fixed-price packs: weight done, price is already stored correctly.
			if ( 'sum' !== $product->get_pack_pricing_mode() ) {
				continue;
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
	 * Append pack contents as a stacked list below the pack name in cart and checkout.
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

		$rows = '';
		foreach ( $parts as $part ) {
			$rows .= '<div class="jwpb-cart-contents-item">' . $part . '</div>';
		}

		$name .= '<div class="jwpb-cart-contents" style="margin-top:0.5em;font-weight:normal;color:#666;">'
			. $rows
			. '</div>';

		return $name;
	}

	/**
	 * Add the weight of customer-selected addon products to the cart item weight
	 * for custom packs. Without this, only the pack product's own shipping weight
	 * is used and addon weights are silently ignored.
	 *
	 * @param float  $weight        Current item weight.
	 * @param array  $cart_item     Cart item data.
	 * @param string $cart_item_key Cart item hash.
	 * @return float
	 */
	/**
	 * Stamp computed pack weights into shipping packages before they reach any plugin.
	 *
	 * WooCommerce caches shipping rates against a hash of the serialised package. Because
	 * WC_Product properties are all protected, json_encode() on a product object yields "{}"
	 * — the hash is identical regardless of what set_weight() was called with, so shipping
	 * rates never update when addon selections (and therefore pack weight) change.
	 *
	 * This filter fires inside get_shipping_packages(), which is called after
	 * woocommerce_before_calculate_totals, so set_pack_price() has already run. We:
	 *   1. Re-apply set_weight() here as the definitive last-step so any shipping plugin
	 *      reading $item['data']->get_weight() always gets the correct combined value.
	 *   2. Write the computed weight as a plain scalar (jwpb_computed_weight) onto the cart
	 *      item so json_encode($package) captures it in the hash — forcing recalculation
	 *      whenever pack contents change.
	 *
	 * Applies to both custom packs (addon selections) and standard packs (fixed items,
	 * including seasonal snapshots).
	 *
	 * @param array $packages Shipping packages.
	 * @return array
	 */
	public static function inject_pack_weight_into_packages( $packages ) {
		foreach ( $packages as &$package ) {
			foreach ( $package['contents'] as $cart_item_key => &$cart_item ) {
				$product = $cart_item['data'];

				if ( ! ( $product instanceof WC_Product_Pack ) ) {
					continue;
				}

				$weight = (float) get_post_meta( $product->get_id(), '_weight', true );

				if ( $product->is_custom() ) {
					$items = $cart_item['_jwpb_addon_selections'] ?? array();
				} else {
					$items = isset( $cart_item['_jwpb_snapshot'] ) ? $cart_item['_jwpb_snapshot'] : $product->get_pack_items();
				}

				foreach ( $items as $item ) {
					$qty       = max( 1, (int) $item['quantity'] );
					$target_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
					$p         = wc_get_product( $target_id );
					if ( $p ) {
						$weight += (float) $p->get_weight() * $qty;
					}
				}

				$product->set_weight( $weight );

				// Plain scalar so json_encode() captures it in the package hash.
				$cart_item['jwpb_computed_weight'] = $weight;
			}
		}

		return $packages;
	}

	/**
	 * Render the shipping weight breakdown panel in cart/checkout totals.
	 * Builds its data directly from the cart so it is not dependent on the
	 * weight filter having fired first.
	 */
	public static function render_weight_debug() {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		$unit    = get_option( 'woocommerce_weight_unit', 'kg' );
		$entries = array();

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];

			if ( ! ( $product instanceof WC_Product_Pack ) || ! $product->is_custom() ) {
				continue;
			}

			// Use postmeta, not get_weight() — set_pack_price() has already called
			// set_weight() on the in-memory object with the combined value.
			$base   = (float) get_post_meta( $product->get_id(), '_weight', true );
			$total  = $base;
			$addons = array();

			foreach ( $cart_item['_jwpb_addon_selections'] ?? array() as $item ) {
				$qty       = max( 1, (int) $item['quantity'] );
				$target_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
				$p         = wc_get_product( $target_id );

				if ( $p ) {
					$addon_weight = (float) $p->get_weight();
					$contribution = $addon_weight * $qty;
					$total       += $contribution;
					$addons[]     = array(
						'name'         => $p->get_name(),
						'qty'          => $qty,
						'unit_weight'  => $addon_weight,
						'contribution' => $contribution,
					);
				} else {
					$addons[] = array(
						'name'         => sprintf( '#%d (not found)', $target_id ),
						'qty'          => $qty,
						'unit_weight'  => null,
						'contribution' => null,
					);
				}
			}

			$entries[] = array(
				'pack_name'   => $product->get_name(),
				'base_weight' => $base,
				'addons'      => $addons,
				'total'       => $total,
				'unit'        => $unit,
			);
		}

		if ( empty( $entries ) ) {
			return;
		}
		?>
		<tr class="jwpb-weight-debug">
			<td colspan="2" style="padding:0 0 8px;">
				<details style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;font-size:12px;">
					<summary style="padding:8px 12px;cursor:pointer;font-weight:600;list-style:none;display:flex;align-items:center;gap:6px;">
						&#9884; <?php esc_html_e( 'Pack shipping weight breakdown (debug)', 'jezpress-woo-pack-builder' ); ?>
					</summary>
					<div style="padding:8px 12px 12px;">
					<?php foreach ( $entries as $entry ) : ?>
						<p style="margin:6px 0 4px;font-weight:600;"><?php echo esc_html( $entry['pack_name'] ); ?></p>
						<table style="width:100%;border-collapse:collapse;font-size:11px;">
							<thead>
								<tr style="background:#eee;">
									<th style="text-align:left;padding:3px 6px;"><?php esc_html_e( 'Item', 'jezpress-woo-pack-builder' ); ?></th>
									<th style="text-align:center;padding:3px 6px;"><?php esc_html_e( 'Qty', 'jezpress-woo-pack-builder' ); ?></th>
									<th style="text-align:right;padding:3px 6px;"><?php esc_html_e( 'Unit weight', 'jezpress-woo-pack-builder' ); ?></th>
									<th style="text-align:right;padding:3px 6px;"><?php esc_html_e( 'Contribution', 'jezpress-woo-pack-builder' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td style="padding:3px 6px;color:#666;"><?php esc_html_e( 'Pack base', 'jezpress-woo-pack-builder' ); ?></td>
									<td style="text-align:center;padding:3px 6px;">—</td>
									<td style="text-align:right;padding:3px 6px;"><?php echo esc_html( $entry['base_weight'] . ' ' . $entry['unit'] ); ?></td>
									<td style="text-align:right;padding:3px 6px;"><?php echo esc_html( $entry['base_weight'] . ' ' . $entry['unit'] ); ?></td>
								</tr>
								<?php foreach ( $entry['addons'] as $addon ) : ?>
								<tr>
									<td style="padding:3px 6px;"><?php echo esc_html( $addon['name'] ); ?></td>
									<td style="text-align:center;padding:3px 6px;"><?php echo esc_html( $addon['qty'] ); ?></td>
									<td style="text-align:right;padding:3px 6px;">
										<?php
										if ( null !== $addon['unit_weight'] ) {
											echo esc_html( $addon['unit_weight'] . ' ' . $entry['unit'] );
										} else {
											echo '<em>—</em>';
										}
										?>
									</td>
									<td style="text-align:right;padding:3px 6px;">
										<?php
										if ( null !== $addon['contribution'] ) {
											echo esc_html( $addon['contribution'] . ' ' . $entry['unit'] );
										} else {
											echo '<em style="color:#c00;">not found</em>';
										}
										?>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
							<tfoot>
								<tr style="border-top:2px solid #ccc;font-weight:600;">
									<td colspan="3" style="padding:4px 6px;"><?php esc_html_e( 'Total weight', 'jezpress-woo-pack-builder' ); ?></td>
									<td style="text-align:right;padding:4px 6px;"><?php echo esc_html( $entry['total'] . ' ' . $entry['unit'] ); ?></td>
								</tr>
							</tfoot>
						</table>
					<?php endforeach; ?>
					</div>
				</details>
			</td>
		</tr>
		<?php
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
