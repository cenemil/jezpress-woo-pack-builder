<?php
/**
 * JWPB_Product_Type — registers the 'pack' WooCommerce product type,
 * product data tabs, panel HTML, meta save, and admin script enqueuing.
 *
 * Pack items are persisted to {prefix}jwpb_pack_items via JWPB_DB.
 * The form submits a JSON blob (_pack_items_json) which save_meta() parses.
 *
 * @package JezPress_Woo_Pack_Builder
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPB_Product_Type {

	public static function init() {
		add_filter( 'product_type_selector',            array( __CLASS__, 'add_pack_type' ) );
		add_filter( 'woocommerce_product_class',        array( __CLASS__, 'get_pack_class' ), 10, 2 );
		add_filter( 'woocommerce_product_data_tabs',    array( __CLASS__, 'add_product_data_tabs' ) );
		add_action( 'woocommerce_product_data_panels',  array( __CLASS__, 'render_product_data_panels' ) );
		// Priority 25: runs after WooCommerce's own $product->save() (priority 10) so our
		// _price / _regular_price writes are the final values stored for pack products.
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_meta' ), 25 );
		add_action( 'admin_enqueue_scripts',            array( __CLASS__, 'enqueue_scripts' ) );
		// Use the simple add-to-cart template for pack products on the single product page.
		add_action( 'woocommerce_pack_add_to_cart',     'woocommerce_simple_add_to_cart' );
	}

	// -------------------------------------------------------------------------
	// Type registration
	// -------------------------------------------------------------------------

	public static function add_pack_type( $types ) {
		$types['pack'] = __( 'Pack', 'jezpress-woo-pack-builder' );
		return $types;
	}

	public static function get_pack_class( $classname, $product_type ) {
		return 'pack' === $product_type ? 'WC_Product_Pack' : $classname;
	}

	// -------------------------------------------------------------------------
	// Product data tabs
	// -------------------------------------------------------------------------

	public static function add_product_data_tabs( $tabs ) {
		// WooCommerce's General tab has no fields relevant to pack products
		// (price and virtual/downloadable are all show_if_simple/show_if_external).
		// Adding hide_if_pack here lets WooCommerce's own JS hide it on type change.
		if ( isset( $tabs['general'] ) ) {
			$tabs['general']['class'][] = 'hide_if_pack';
		}

		$tabs['jwpb_contents'] = array(
			'label'    => __( 'Pack Contents', 'jezpress-woo-pack-builder' ),
			'target'   => 'jwpb_contents_data',
			'class'    => array( 'show_if_pack' ),
			'priority' => 11,
		);
		$tabs['jwpb_subscription'] = array(
			'label'    => __( 'Subscription', 'jezpress-woo-pack-builder' ),
			'target'   => 'jwpb_subscription_data',
			'class'    => array( 'show_if_pack' ),
			'priority' => 13,
		);
		return $tabs;
	}

	// -------------------------------------------------------------------------
	// Panel HTML
	// -------------------------------------------------------------------------

	public static function render_product_data_panels() {
		global $product_object;

		$is_pack = $product_object instanceof WC_Product_Pack;

		$pricing_mode     = $is_pack ? $product_object->get_pack_pricing_mode( 'edit' )          : 'sum';
		$price_override   = $is_pack ? $product_object->get_pack_price_override( 'edit' )         : '';
		$sub_enabled      = $is_pack ? $product_object->get_pack_subscription_enabled( 'edit' )   : 'no';
		$sub_interval     = $is_pack ? $product_object->get_pack_subscription_interval( 'edit' )  : 1;
		$sub_period       = $is_pack ? $product_object->get_pack_subscription_period( 'edit' )    : 'month';
		$sub_length       = $is_pack ? $product_object->get_pack_subscription_length( 'edit' )    : 0;
		$seasonal_enabled = $is_pack ? $product_object->get_pack_seasonal_enabled( 'edit' )       : 'no';
		$seasonal_count   = $is_pack ? $product_object->get_pack_seasonal_count( 'edit' )         : 3;
		$last_rotated     = $is_pack ? $product_object->get_pack_seasonal_last_rotated( 'edit' )  : '';
		$pack_id          = $is_pack ? $product_object->get_id()                                  : 0;
		?>

		<!-- PACK CONTENTS -->
		<div id="jwpb_contents_data" class="panel woocommerce_options_panel">
			<div class="options_group">

				<p class="form-field">
					<label><?php esc_html_e( 'Pricing Mode', 'jezpress-woo-pack-builder' ); ?></label>
					<span style="display:inline-block; line-height:2;">
						<span style="padding-right:16px;">
							<input type="radio" name="_pack_pricing_mode" value="sum" id="_pack_pricing_mode_sum" <?php checked( $pricing_mode, 'sum' ); ?>>
							<?php esc_html_e( 'Sum of products', 'jezpress-woo-pack-builder' ); ?>
						</span>
						<span>
							<input type="radio" name="_pack_pricing_mode" value="fixed" id="_pack_pricing_mode_fixed" <?php checked( $pricing_mode, 'fixed' ); ?>>
							<?php esc_html_e( 'Fixed price', 'jezpress-woo-pack-builder' ); ?>
						</span>
					</span>
				</p>

				<p class="form-field jwpb-fixed-price-field" style="<?php echo 'fixed' !== $pricing_mode ? 'display:none;' : ''; ?>">
					<label for="_pack_price_override">
						<?php esc_html_e( 'Pack Price', 'jezpress-woo-pack-builder' ); ?>
						(<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)
					</label>
					<input type="text" id="_pack_price_override" name="_pack_price_override"
						class="short wc_input_price"
						value="<?php echo esc_attr( wc_format_localized_price( $price_override ) ); ?>">
				</p>

				<p class="form-field">
					<label for="_pack_item_label"><?php esc_html_e( 'Product Column Label', 'jezpress-woo-pack-builder' ); ?></label>
					<input type="text" id="_pack_item_label" name="_pack_item_label" class="short"
						value="<?php echo esc_attr( (string) get_post_meta( $pack_id, '_pack_item_label', true ) ); ?>"
						placeholder="<?php esc_attr_e( 'Product', 'jezpress-woo-pack-builder' ); ?>">
					<span class="description"><?php esc_html_e( 'Heading for the product name column in the pack contents table. Leave blank for default.', 'jezpress-woo-pack-builder' ); ?></span>
				</p>

				<p class="form-field">
					<label for="_pack_qty_label"><?php esc_html_e( 'Quantity Column Label', 'jezpress-woo-pack-builder' ); ?></label>
					<input type="text" id="_pack_qty_label" name="_pack_qty_label" class="short"
						value="<?php echo esc_attr( (string) get_post_meta( $pack_id, '_pack_qty_label', true ) ); ?>"
						placeholder="<?php esc_attr_e( 'Quantity', 'jezpress-woo-pack-builder' ); ?>">
					<span class="description"><?php esc_html_e( 'Heading for the quantity column in the pack contents table. Leave blank for default.', 'jezpress-woo-pack-builder' ); ?></span>
				</p>

			</div>

			<div class="options_group">

				<p class="form-field">
					<label for="_pack_seasonal_enabled"><?php esc_html_e( 'Seasonal Rotation', 'jezpress-woo-pack-builder' ); ?></label>
					<input type="checkbox" id="_pack_seasonal_enabled" name="_pack_seasonal_enabled"
						value="yes" <?php checked( $seasonal_enabled, 'yes' ); ?>>
				</p>

				<div class="jwpb-seasonal-fields" style="<?php echo 'yes' !== $seasonal_enabled ? 'display:none;' : ''; ?>">
					<p class="form-field jwpb-seasonal-meta">
						<label><?php esc_html_e( 'Seasonal Pool', 'jezpress-woo-pack-builder' ); ?></label>
						<span id="jwpb-pool-count">—</span>
						<?php
						printf(
							/* translators: %s: seasonal product tag slug */
							esc_html__( 'eligible products (tagged "%s", in stock)', 'jezpress-woo-pack-builder' ),
							esc_html( JWPB_Settings::get( 'seasonal_tag', 'seasonal' ) )
						);
						?>
					</p>

					<p class="form-field">
						<label for="_pack_seasonal_count"></label>
						<input type="number" id="_pack_seasonal_count" name="_pack_seasonal_count"
							value="<?php echo esc_attr( $seasonal_count ); ?>" min="1" step="1" style="width:50px; margin-right:8px;">
						<span><?php esc_html_e( 'products drawn from the seasonal pool', 'jezpress-woo-pack-builder' ); ?></span>
					</p>

					<?php if ( $pack_id ) : ?>
						<p class="form-field">
							<button type="button" id="jwpb-rotate-btn" class="button jwpb-rotate-btn"
								data-pack-id="<?php echo esc_attr( $pack_id ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'jwpb_rotate_' . $pack_id ) ); ?>">
								<?php esc_html_e( 'Rotate Now', 'jezpress-woo-pack-builder' ); ?>
							</button>
							<span id="jwpb-rotate-status" style="margin-left:12px;"></span>
						</p>
					<?php else : ?>
						<p class="description" style="margin:0 24px 12px;">
							<?php esc_html_e( 'Save the product first to enable manual rotation.', 'jezpress-woo-pack-builder' ); ?>
						</p>
					<?php endif; ?>
				</div>

			</div>

			<div class="options_group">

				<p class="form-field">
					<label for="jwpb-product-search"><?php esc_html_e( 'Add Product', 'jezpress-woo-pack-builder' ); ?></label>
					<select id="jwpb-product-search" class="jwpb-product-search"
						style="min-width:300px; max-width:60%;"
						data-placeholder="<?php esc_attr_e( 'Search for a product…', 'jezpress-woo-pack-builder' ); ?>">
					</select>
					<span id="jwpb-adding-spinner" class="spinner" style="float:none; margin:4px 6px; display:none; visibility:visible;"></span>
				</p>

				<div style="margin: 12px 12px 20px 12px;">
					<p class="description" style="margin: 0;">
						<?php esc_html_e( 'Select products from the search. For variable products choose a specific variation and set a quantity.', 'jezpress-woo-pack-builder' ); ?>
					</p>
					<table id="jwpb-items-table" class="jwpb-items-table widefat">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Product', 'jezpress-woo-pack-builder' ); ?></th>
								<th><?php esc_html_e( 'Variation', 'jezpress-woo-pack-builder' ); ?></th>
								<th style="width:80px;"><?php esc_html_e( 'Qty', 'jezpress-woo-pack-builder' ); ?></th>
								<th style="width:120px;"><?php esc_html_e( 'Price', 'jezpress-woo-pack-builder' ); ?></th>
								<th style="width:40px;"></th>
							</tr>
						</thead>
						<tbody id="jwpb-items-tbody">
							<!-- Rows rendered by editor.js from jwpbData.existingItems -->
						</tbody>
						<tfoot id="jwpb-items-empty" style="display:none;">
							<tr>
								<td colspan="5" style="color:#999; font-style:italic; text-align:center; padding:12px;">
									<?php esc_html_e( 'No products added yet. Search above to add products.', 'jezpress-woo-pack-builder' ); ?>
								</td>
							</tr>
						</tfoot>
					</table>
				</div>

				<?php /* Hidden field — JS writes JSON here before form submit */ ?>
				<input type="hidden" id="jwpb-items-json" name="_pack_items_json" value="[]">

			</div>
		</div>

		<!-- SUBSCRIPTION -->
		<div id="jwpb_subscription_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php if ( ! JWPB_Subscription_Bridge::is_available() ) : ?>
					<div class="notice inline notice-warning" style="margin:12px 24px;">
						<p><?php esc_html_e( 'No subscription engine detected. Install JezPress WC Subscription or WooCommerce Subscriptions to enable subscription billing.', 'jezpress-woo-pack-builder' ); ?></p>
					</div>
				<?php endif; ?>

				<p class="form-field">
					<label for="_pack_subscription_enabled">
						<input type="checkbox" id="_pack_subscription_enabled" name="_pack_subscription_enabled"
							value="yes" <?php checked( $sub_enabled, 'yes' ); ?>>
						<?php esc_html_e( 'Enable subscription billing', 'jezpress-woo-pack-builder' ); ?>
					</label>
				</p>

				<div class="jwpb-subscription-fields" style="<?php echo 'yes' !== $sub_enabled ? 'display:none;' : ''; ?>">
					<p class="form-field">
						<label for="_pack_subscription_interval"><?php esc_html_e( 'Billing Every', 'jezpress-woo-pack-builder' ); ?></label>
						<input type="number" id="_pack_subscription_interval" name="_pack_subscription_interval"
							value="<?php echo esc_attr( $sub_interval ); ?>" min="1" step="1" style="width:60px;">
						<select name="_pack_subscription_period" id="_pack_subscription_period" style="margin-left:8px;">
							<?php foreach ( array( 'day' => __( 'Day(s)', 'jezpress-woo-pack-builder' ), 'week' => __( 'Week(s)', 'jezpress-woo-pack-builder' ), 'month' => __( 'Month(s)', 'jezpress-woo-pack-builder' ), 'year' => __( 'Year(s)', 'jezpress-woo-pack-builder' ) ) as $val => $lbl ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $sub_period, $val ); ?>>
									<?php echo esc_html( $lbl ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="form-field">
						<label for="_pack_subscription_length"><?php esc_html_e( 'Billing Length', 'jezpress-woo-pack-builder' ); ?></label>
						<input type="number" id="_pack_subscription_length" name="_pack_subscription_length"
							value="<?php echo esc_attr( $sub_length ); ?>" min="0" step="1" style="width:80px;">
						<span class="description"><?php esc_html_e( 'billing cycles (0 = indefinite)', 'jezpress-woo-pack-builder' ); ?></span>
					</p>
				</div>
			</div>
		</div>

		<?php
	}

	// -------------------------------------------------------------------------
	// Meta save
	// -------------------------------------------------------------------------

	public static function save_meta( $post_id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$product_type = isset( $_POST['product-type'] ) ? sanitize_key( $_POST['product-type'] ) : '';
		if ( 'pack' !== $product_type ) {
			return;
		}

		// --- Pack items (from JSON blob written by JS) ---
		$json = isset( $_POST['_pack_items_json'] )
			? sanitize_text_field( wp_unslash( $_POST['_pack_items_json'] ) )
			: '[]';

		$raw_items = json_decode( $json, true );
		$items     = array();

		if ( is_array( $raw_items ) ) {
			foreach ( $raw_items as $entry ) {
				$product_id   = absint( $entry['product_id'] ?? 0 );
				$variation_id = absint( $entry['variation_id'] ?? 0 );
				$quantity     = max( 1, absint( $entry['quantity'] ?? 1 ) );

				if ( ! $product_id ) {
					continue;
				}

				// Validate parent product exists and is not itself a pack.
				$p = wc_get_product( $product_id );
				if ( ! $p || 'pack' === $p->get_type() ) {
					continue;
				}

				// Validate variation belongs to this parent (if specified).
				if ( $variation_id ) {
					$v = wc_get_product( $variation_id );
					if ( ! ( $v instanceof WC_Product_Variation ) || $v->get_parent_id() !== $product_id ) {
						$variation_id = 0;
					}
				}

				$items[] = array(
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'quantity'     => $quantity,
				);
			}
		}

		JWPB_DB::save_pack_items( $post_id, $items );

		// --- Column labels ---
		update_post_meta( $post_id, '_pack_item_label', sanitize_text_field( wp_unslash( $_POST['_pack_item_label'] ?? '' ) ) );
		update_post_meta( $post_id, '_pack_qty_label',  sanitize_text_field( wp_unslash( $_POST['_pack_qty_label']  ?? '' ) ) );

		// --- Pricing ---
		$pricing_mode = sanitize_key( $_POST['_pack_pricing_mode'] ?? 'sum' );
		if ( ! in_array( $pricing_mode, array( 'fixed', 'sum' ), true ) ) {
			$pricing_mode = 'sum';
		}
		update_post_meta( $post_id, '_pack_pricing_mode', $pricing_mode );

		if ( 'fixed' === $pricing_mode ) {
			$override = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_pack_price_override'] ?? '' ) ) );
			update_post_meta( $post_id, '_pack_price_override', $override );
			update_post_meta( $post_id, '_price', $override );
			update_post_meta( $post_id, '_regular_price', $override );
		} else {
			// Compute sum and update WC price index.
			$total = 0.0;
			foreach ( $items as $item ) {
				$target_id = $item['variation_id'] ?: $item['product_id'];
				$p         = wc_get_product( $target_id );
				if ( $p ) {
					$total += (float) $p->get_price() * $item['quantity'];
				}
			}
			$total_str = wc_format_decimal( $total );
			update_post_meta( $post_id, '_pack_price_override', '' );
			update_post_meta( $post_id, '_price', $total_str );
			update_post_meta( $post_id, '_regular_price', $total_str );
		}

		// --- Subscription ---
		update_post_meta( $post_id, '_pack_subscription_enabled', isset( $_POST['_pack_subscription_enabled'] ) ? 'yes' : 'no' );
		update_post_meta( $post_id, '_pack_subscription_interval', absint( $_POST['_pack_subscription_interval'] ?? 1 ) ?: 1 );

		$valid_periods = array( 'day', 'week', 'month', 'year' );
		$sub_period    = sanitize_key( $_POST['_pack_subscription_period'] ?? 'month' );
		update_post_meta( $post_id, '_pack_subscription_period', in_array( $sub_period, $valid_periods, true ) ? $sub_period : 'month' );
		update_post_meta( $post_id, '_pack_subscription_length', absint( $_POST['_pack_subscription_length'] ?? 0 ) );

		// --- Seasonal ---
		update_post_meta( $post_id, '_pack_seasonal_enabled', isset( $_POST['_pack_seasonal_enabled'] ) ? 'yes' : 'no' );
		update_post_meta( $post_id, '_pack_seasonal_count', max( 1, absint( $_POST['_pack_seasonal_count'] ?? 3 ) ) );

		wc_delete_product_transients( $post_id );
	}

	// -------------------------------------------------------------------------
	// Scripts & styles
	// -------------------------------------------------------------------------

	public static function enqueue_scripts( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		global $post;
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		wp_enqueue_style(
			'jwpb-pack-editor',
			JWPB_URL . 'assets/css/editor.css',
			array(),
			JWPB_VERSION
		);

		wp_enqueue_script(
			'jwpb-pack-editor',
			JWPB_URL . 'assets/js/editor.js',
			array( 'jquery', 'select2' ),
			JWPB_VERSION,
			true
		);

		// Pre-fetch existing items from DB and enrich with product data for
		// JS to render immediately on load without extra AJAX calls.
		$existing_items = self::get_enriched_items( $post->ID );

		wp_localize_script( 'jwpb-pack-editor', 'jwpbData', array(
			'ajaxurl'       => admin_url( 'admin-ajax.php' ),
			'currency'      => get_woocommerce_currency_symbol(),
			'adminNonce'    => wp_create_nonce( 'jwpb_admin_nonce' ),
			'searchNonce'   => wp_create_nonce( 'search-products' ),
			'existingItems' => $existing_items,
			'defaultType'   => isset( $_GET['jwpb_type'] ) ? sanitize_key( $_GET['jwpb_type'] ) : '',
			'i18n'          => array(
				'remove'           => __( 'Remove', 'jezpress-woo-pack-builder' ),
				'selectVariation'  => __( 'Select variation…', 'jezpress-woo-pack-builder' ),
				'anyVariation'     => __( 'Any', 'jezpress-woo-pack-builder' ),
				'rotating'         => __( 'Rotating…', 'jezpress-woo-pack-builder' ),
				'rotated'          => __( 'Rotated successfully.', 'jezpress-woo-pack-builder' ),
				'error'            => __( 'An error occurred. Please try again.', 'jezpress-woo-pack-builder' ),
				'loading'          => __( 'Loading…', 'jezpress-woo-pack-builder' ),
				'noItems'          => __( 'No products added yet.', 'jezpress-woo-pack-builder' ),
			),
		) );
	}

	/**
	 * Fetch DB items for a product and enrich with product/variation display data.
	 *
	 * @param int $product_id Product post ID.
	 * @return array[]
	 */
	private static function get_enriched_items( $product_id ) {
		$db_items = JWPB_DB::get_pack_items( $product_id );
		$enriched = array();

		foreach ( $db_items as $item ) {
			$parent = wc_get_product( $item['product_id'] );
			if ( ! $parent ) {
				continue;
			}

			$row = array(
				'product_id'   => $item['product_id'],
				'variation_id' => $item['variation_id'],
				'quantity'     => $item['quantity'],
				'name'         => $parent->get_name(),
				'sku'          => $parent->get_sku(),
				'type'         => $parent->get_type(),
				'price_html'   => $parent->get_price_html(),
				'variations'   => array(),
			);

			// Build variation list for variable products.
			if ( $parent->is_type( 'variable' ) ) {
				foreach ( $parent->get_available_variations() as $var ) {
					$v = wc_get_product( $var['variation_id'] );
					if ( ! $v ) {
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

					$row['variations'][] = array(
						'id'         => $var['variation_id'],
						'label'      => implode( ' / ', $attrs ) ?: ( '#' . $var['variation_id'] ),
						'price_html' => $v->get_price_html(),
						'sku'        => $v->get_sku(),
					);
				}

				// If a variation is already selected, override price_html with that variation's price.
				if ( $item['variation_id'] ) {
					$selected_v = wc_get_product( $item['variation_id'] );
					if ( $selected_v ) {
						$row['price_html'] = $selected_v->get_price_html();
					}
				}
			}

			$enriched[] = $row;
		}

		return $enriched;
	}
}
