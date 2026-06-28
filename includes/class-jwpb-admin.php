<?php
/**
 * JWPB_Admin — WooCommerce → Packs admin page with list table and license tab.
 *
 * @package JezPress_Woo_Pack_Builder
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPB_Admin {

	private static $instance = null;

	private function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	public function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_jwpb-pack-builder' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'jwpb-pack-admin',
			JWPB_URL . 'assets/css/admin.css',
			array(),
			JWPB_VERSION
		);

		wp_enqueue_script(
			'jwpb-pack-admin',
			JWPB_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			JWPB_VERSION,
			true
		);

		wp_localize_script( 'jwpb-pack-admin', 'jwpbAdmin', array(
			'ajaxurl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'jwpb_admin_nonce' ),
			'currency' => get_woocommerce_currency_symbol(),
			'i18n'     => array(
				'newPackTitle'  => __( 'New Pack',                     'jezpress-woo-pack-builder' ),
				'editPackTitle' => __( 'Edit Pack',                    'jezpress-woo-pack-builder' ),
				'createPack'    => __( 'Create Pack',                  'jezpress-woo-pack-builder' ),
				'updatePack'    => __( 'Update Pack',                  'jezpress-woo-pack-builder' ),
				'creating'      => __( 'Creating…',                   'jezpress-woo-pack-builder' ),
				'updating'      => __( 'Updating…',                   'jezpress-woo-pack-builder' ),
				'created'       => __( 'Pack created. Redirecting…',  'jezpress-woo-pack-builder' ),
				'updated'       => __( 'Pack updated. Redirecting…',  'jezpress-woo-pack-builder' ),
				'nameRequired'  => __( 'Pack name is required.',       'jezpress-woo-pack-builder' ),
				'loadError'     => __( 'Failed to load pack data. Please try again.', 'jezpress-woo-pack-builder' ),
				'error'         => __( 'An error occurred. Please try again.', 'jezpress-woo-pack-builder' ),
			),
		) );
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Pack Builder', 'jezpress-woo-pack-builder' ),
			__( 'Packs', 'jezpress-woo-pack-builder' ),
			'manage_woocommerce',
			'jwpb-pack-builder',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';

		$license     = JWPB_License::get_instance();
		$is_licensed = $license && $license->is_valid();

		if ( ! $is_licensed && 'license' !== $tab ) {
			$tab = 'license';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pack Builder', 'jezpress-woo-pack-builder' ); ?></h1>

			<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
				<?php if ( $is_licensed ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=jwpb-pack-builder&tab=settings' ) ); ?>"
					   class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
						<?php esc_html_e( 'Settings', 'jezpress-woo-pack-builder' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=jwpb-pack-builder&tab=packs' ) ); ?>"
					   class="nav-tab <?php echo 'packs' === $tab ? 'nav-tab-active' : ''; ?>">
						<?php esc_html_e( 'Packs', 'jezpress-woo-pack-builder' ); ?>
					</a>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=jwpb-pack-builder&tab=license' ) ); ?>"
				   class="nav-tab <?php echo 'license' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'License', 'jezpress-woo-pack-builder' ); ?>
				</a>
			</nav>

			<?php if ( 'license' === $tab ) : ?>
				<?php if ( $license ) { $license->render_tab_content(); } ?>
			<?php elseif ( 'settings' === $tab ) : ?>
				<?php $this->render_settings_tab(); ?>
			<?php elseif ( 'packs' === $tab && $is_licensed ) : ?>
				<?php $this->render_packs_tab(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_settings_tab() {
		$saved             = isset( $_GET['saved'] ) && '1' === $_GET['saved'];
		$current_placement = JWPB_Settings::get( 'pack_contents_placement', 'none' );
		$placement_labels  = JWPB_Settings::placement_labels();
		?>
		<div class="admin-page-wrap">

			<?php if ( $saved ) : ?>
				<div class="notice notice-success inline admin-page-notice">
					<p><?php esc_html_e( 'Settings saved.', 'jezpress-woo-pack-builder' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Settings card -->
			<div class="admin-page-card">
				<h2 class="admin-page-card-title">
					<?php esc_html_e( 'Settings', 'jezpress-woo-pack-builder' ); ?>
				</h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'jwpb_save_settings' ); ?>
					<input type="hidden" name="action" value="jwpb_save_settings">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="pack_contents_placement">
									<?php esc_html_e( 'Pack Contents Placement', 'jezpress-woo-pack-builder' ); ?>
								</label>
							</th>
							<td>
								<select id="pack_contents_placement" name="pack_contents_placement" class="jwpb-select">
									<?php foreach ( $placement_labels as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>"
											<?php selected( $current_placement, $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Automatically inject the pack contents table into the single-product template. Applies only to Pack type products. Choose None to place the table manually with the shortcode.', 'jezpress-woo-pack-builder' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="seasonal_tag">
									<?php esc_html_e( 'Seasonal Pool Tag', 'jezpress-woo-pack-builder' ); ?>
								</label>
							</th>
							<td>
								<input type="text" id="seasonal_tag" name="seasonal_tag" class="regular-text"
									value="<?php echo esc_attr( JWPB_Settings::get( 'seasonal_tag', 'seasonal' ) ); ?>"
									placeholder="seasonal">
								<p class="description">
									<?php esc_html_e( 'WooCommerce product tag slug used to identify products eligible for seasonal rotation. Products must also be In Stock. Defaults to "seasonal".', 'jezpress-woo-pack-builder' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="shipping_weight_debug">
									<?php esc_html_e( 'Shipping Weight Debug', 'jezpress-woo-pack-builder' ); ?>
								</label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="shipping_weight_debug" name="shipping_weight_debug" value="1"
										<?php checked( JWPB_Settings::get( 'shipping_weight_debug', false ) ); ?>>
									<?php esc_html_e( 'Show a weight breakdown panel on cart and checkout pages for pack products.', 'jezpress-woo-pack-builder' ); ?>
								</label>
							</td>
						</tr>
					</table>

					<p class="submit">
						<?php submit_button( __( 'Save Settings', 'jezpress-woo-pack-builder' ), 'primary', 'submit', false ); ?>
					</p>
				</form>
			</div>

			<!-- Knowledgebase card -->
			<div class="admin-page-card">
				<h2 class="admin-page-card-title">
					<?php esc_html_e( 'Knowledgebase', 'jezpress-woo-pack-builder' ); ?>
				</h2>

				<div class="admin-page-card-section">
					<h3 class="admin-page-card-section-title"><?php esc_html_e( 'Shortcode', 'jezpress-woo-pack-builder' ); ?></h3>
					<p><?php esc_html_e( 'Embed the pack contents table on any page or post:', 'jezpress-woo-pack-builder' ); ?></p>
					<div class="admin-page-code-block">
						<code>[jwpb_pack_contents]</code>
					</div>
					<p class="description">
						<?php esc_html_e( 'Auto-detects the current pack when placed inside a single product template.', 'jezpress-woo-pack-builder' ); ?>
					</p>
					<div class="admin-page-code-block" style="margin-top:8px;">
						<code>[jwpb_pack_contents id="123"]</code>
					</div>
					<p class="description">
						<?php esc_html_e( 'Display a specific pack by product ID — works on any page.', 'jezpress-woo-pack-builder' ); ?>
					</p>
				</div>
				
				<div class="admin-page-card-section">
					<h3 class="admin-page-card-section-title"><?php esc_html_e( 'What the table displays', 'jezpress-woo-pack-builder' ); ?></h3>
					<ul class="admin-page-card-section-ul">
						<li><?php esc_html_e( 'Each item\'s product name, linked to its shop page.', 'jezpress-woo-pack-builder' ); ?></li>
						<li><?php esc_html_e( 'For variation items, a sub-label (e.g. Red / Large) is shown and the link pre-selects that variant on the product page.', 'jezpress-woo-pack-builder' ); ?></li>
						<li><?php esc_html_e( 'Item quantity.', 'jezpress-woo-pack-builder' ); ?></li>
					</ul>
				</div>

				<div class="admin-page-card-section">
					<h3 class="admin-page-card-section-title"><?php esc_html_e( 'Pricing modes', 'jezpress-woo-pack-builder' ); ?></h3>
					<ul class="admin-page-card-section-ul">
						<li>
							<strong><?php esc_html_e( 'Sum of products', 'jezpress-woo-pack-builder' ); ?></strong>
							&mdash; <?php esc_html_e( 'Pack price is calculated from current constituent product prices. Updates automatically when item prices change.', 'jezpress-woo-pack-builder' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Fixed price', 'jezpress-woo-pack-builder' ); ?></strong>
							&mdash; <?php esc_html_e( 'A set price independent of constituent products.', 'jezpress-woo-pack-builder' ); ?>
						</li>
					</ul>
				</div>

				<div class="admin-page-card-section">
					<h3 class="admin-page-card-section-title"><?php esc_html_e( 'Creating your first pack', 'jezpress-woo-pack-builder' ); ?></h3>
					<ol class="admin-page-card-section-ol">
						<li>
							<?php
							printf(
								/* translators: 1: menu path, 2: button label */
								esc_html__( 'Go to %1$s and click %2$s.', 'jezpress-woo-pack-builder' ),
								'<strong>' . esc_html__( 'WooCommerce → Packs', 'jezpress-woo-pack-builder' ) . '</strong>',
								'<strong>' . esc_html__( '+ Add New Pack', 'jezpress-woo-pack-builder' ) . '</strong>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Enter a pack name, choose a pricing mode, and click Create Pack.', 'jezpress-woo-pack-builder' ); ?></li>
						<li><?php esc_html_e( 'In the Pack Contents tab, search for products to add. For variable products, choose a specific variation and set quantities.', 'jezpress-woo-pack-builder' ); ?></li>
						<li><?php esc_html_e( 'Optionally configure subscription billing (Subscription tab) or seasonal rotation (Seasonal tab).', 'jezpress-woo-pack-builder' ); ?></li>
						<li><?php esc_html_e( 'Publish the product.', 'jezpress-woo-pack-builder' ); ?></li>
					</ol>
				</div>

				<div class="admin-page-card-section">
					<h3 class="admin-page-card-section-title"><?php esc_html_e( 'Seasonal rotation', 'jezpress-woo-pack-builder' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Tag any product with the configured Seasonal Pool Tag and ensure it is In Stock. Open the Seasonal tab on a pack and click Rotate Now to randomly draw items from the pool.', 'jezpress-woo-pack-builder' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_packs_tab() {
		// Fetch all pack products.
		$pack_ids = wc_get_products( array(
			'type'   => 'pack',
			'limit'  => -1,
			'return' => 'ids',
		) );
		?>
		<div class="admin-page-wrap">

			<?php if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) : ?>
				<div class="notice notice-success inline admin-page-notice">
					<p><?php esc_html_e( 'Pack updated successfully.', 'jezpress-woo-pack-builder' ); ?></p>
				</div>
			<?php endif; ?>

			<button type="button" id="jwpb-toggle-create-form" class="button button-primary">
				<?php esc_html_e( '+ Add New Pack', 'jezpress-woo-pack-builder' ); ?>
			</button>

			<!-- Dual-mode pack form panel (create + edit) -->
			<div id="jwpb-pack-form-panel" class="jwpb-create-panel admin-page-card" style="display:none;">

				<input type="hidden" id="jwpb-form-pack-id" value="0">

				<h3 id="jwpb-form-title" class="admin-page-card-title"><?php esc_html_e( 'New Pack', 'jezpress-woo-pack-builder' ); ?></h3>

				<table class="form-table jwpb-create-form-table">
					<tr>
						<th scope="row">
							<label for="jwpb-new-pack-name">
								<?php esc_html_e( 'Pack Name', 'jezpress-woo-pack-builder' ); ?>
								<span class="required">*</span>
							</label>
						</th>
						<td>
							<input type="text" id="jwpb-new-pack-name" class="regular-text"
								placeholder="<?php esc_attr_e( 'e.g. Christmas Hamper', 'jezpress-woo-pack-builder' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pricing Mode', 'jezpress-woo-pack-builder' ); ?></th>
						<td>
							<fieldset>
								<label style="margin-right:16px !important;">
									<input type="radio" name="jwpb_new_pricing_mode" value="sum" checked>
									<?php esc_html_e( 'Sum of products', 'jezpress-woo-pack-builder' ); ?>
								</label>
								<label>
									<input type="radio" name="jwpb_new_pricing_mode" value="fixed">
									<?php esc_html_e( 'Fixed price', 'jezpress-woo-pack-builder' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr id="jwpb-new-price-row" style="display:none;">
						<th scope="row">
							<label for="jwpb-new-pack-price">
								<?php
								printf(
									/* translators: %s: currency symbol */
									esc_html__( 'Pack Price (%s)', 'jezpress-woo-pack-builder' ),
									esc_html( get_woocommerce_currency_symbol() )
								);
								?>
							</label>
						</th>
						<td>
							<input type="text" id="jwpb-new-pack-price" class="small-text"
								placeholder="0.00" inputmode="decimal">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="jwpb-new-item-label">
								<?php esc_html_e( 'Product Column Label', 'jezpress-woo-pack-builder' ); ?>
							</label>
						</th>
						<td>
							<input type="text" id="jwpb-new-item-label" class="regular-text"
								placeholder="<?php esc_attr_e( 'Product', 'jezpress-woo-pack-builder' ); ?>">
							<p class="description">
								<?php esc_html_e( 'Heading for the product name column in the pack contents table. Leave blank to use the default.', 'jezpress-woo-pack-builder' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="jwpb-new-qty-label">
								<?php esc_html_e( 'Quantity Column Label', 'jezpress-woo-pack-builder' ); ?>
							</label>
						</th>
						<td>
							<input type="text" id="jwpb-new-qty-label" class="regular-text"
								placeholder="<?php esc_attr_e( 'Quantity', 'jezpress-woo-pack-builder' ); ?>">
							<p class="description">
								<?php esc_html_e( 'Heading for the quantity column in the pack contents table. Leave blank to use the default.', 'jezpress-woo-pack-builder' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<div class="admin-page-card-submit">
					<button type="button" id="jwpb-form-submit" class="button button-primary">
						<?php esc_html_e( 'Create Pack', 'jezpress-woo-pack-builder' ); ?>
					</button>
					<button type="button" id="jwpb-form-cancel" class="button">
						<?php esc_html_e( 'Cancel', 'jezpress-woo-pack-builder' ); ?>
					</button>
					<span id="jwpb-form-spinner" class="spinner jwpb-inline-spinner" style="display:none;"></span>
				</div>

				<p id="jwpb-form-error" class="jwpb-create-error" style="display:none;"></p>
			</div>
			<!-- /pack-form-panel -->

			<?php if ( empty( $pack_ids ) ) : ?>
				<p style="margin-top:20px; color:#646970;">
					<?php esc_html_e( 'No packs found. Use the button above to create your first pack.', 'jezpress-woo-pack-builder' ); ?>
				</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Pack Name', 'jezpress-woo-pack-builder' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Pricing', 'jezpress-woo-pack-builder' ); ?></th>
							<th style="width:60px;"><?php esc_html_e( 'Items', 'jezpress-woo-pack-builder' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Subscription', 'jezpress-woo-pack-builder' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Seasonal', 'jezpress-woo-pack-builder' ); ?></th>
							<th style="width:200px;"><?php esc_html_e( 'Actions', 'jezpress-woo-pack-builder' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pack_ids as $pack_id ) :
							$product = wc_get_product( $pack_id );
							if ( ! ( $product instanceof WC_Product_Pack ) ) continue;

							$edit_url   = get_edit_post_link( $pack_id );
							$view_url   = get_permalink( $pack_id );
							$item_count = count( $product->get_pack_items() );
							$is_sub     = $product->is_subscription();
							$is_sea     = $product->is_seasonal();
							$mode       = $product->get_pack_pricing_mode();
							$price      = wc_price( $product->get_price() );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $product->get_name() ); ?></strong>
									<br>
									<span class="description">#<?php echo esc_html( $pack_id ); ?> &mdash; <?php echo esc_html( $product->get_status() ); ?></span>
								</td>
								<td>
									<?php echo 'fixed' === $mode ? esc_html__( 'Fixed', 'jezpress-woo-pack-builder' ) : esc_html__( 'Sum', 'jezpress-woo-pack-builder' ); ?>
									<br><small><?php echo wp_kses_post( $price ); ?></small>
								</td>
								<td><?php echo esc_html( $item_count ); ?></td>
								<td>
									<?php if ( $is_sub ) :
										echo esc_html( JWPB_Subscription_Bridge::get_billing_description( $product ) );
									else : ?>
										<span style="color:#999;"><?php esc_html_e( 'No', 'jezpress-woo-pack-builder' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $is_sea ) : ?>
										<span style="color:#2271b1;">&#10003;</span>
									<?php else : ?>
										<span style="color:#999;"><?php esc_html_e( 'No', 'jezpress-woo-pack-builder' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="jwpb-actions-cell">
									<button type="button"
										class="button button-small jwpb-edit-pack-btn"
										data-pack-id="<?php echo esc_attr( $pack_id ); ?>">
										<?php esc_html_e( 'Edit Pack', 'jezpress-woo-pack-builder' ); ?>
									</button>
									<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
										<?php esc_html_e( 'Edit Product', 'jezpress-woo-pack-builder' ); ?>
									</a>
									<?php if ( $view_url ) : ?>
										<a href="<?php echo esc_url( $view_url ); ?>" class="button button-small" target="_blank">
											<?php esc_html_e( 'View', 'jezpress-woo-pack-builder' ); ?>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
