<?php
/**
 * JWPB_DB — dedicated database table for pack item storage.
 *
 * Table: {prefix}jwpb_pack_items
 * Columns: id, pack_id, product_id, variation_id, quantity, sort_order, item_role, input_type
 *
 * item_role: 'standard' (fixed pack items) | 'addon' (custom pack selectable items)
 * input_type: 'checkbox' | 'select' — applies to addon rows only
 *
 * @package JezPress_Woo_Pack_Builder
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPB_DB {

	const TABLE_VERSION        = 2;
	const TABLE_VERSION_OPTION = 'jwpb_db_version';

	/**
	 * Fully-qualified table name (with WP prefix).
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'jwpb_pack_items';
	}

	/**
	 * Create (or upgrade) the pack items table using dbDelta.
	 * Safe to call on every activation — dbDelta is idempotent and adds missing columns.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id           bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			pack_id      bigint(20) UNSIGNED NOT NULL,
			product_id   bigint(20) UNSIGNED NOT NULL,
			variation_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			quantity     int(11)    UNSIGNED NOT NULL DEFAULT 1,
			sort_order   int(11)    UNSIGNED NOT NULL DEFAULT 0,
			item_role    varchar(20) NOT NULL DEFAULT 'standard',
			input_type   varchar(20) NOT NULL DEFAULT 'checkbox',
			PRIMARY KEY (id),
			KEY idx_pack_id (pack_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION );
	}

	/**
	 * True when the stored DB version is behind the current schema version.
	 *
	 * @return bool
	 */
	public static function needs_upgrade() {
		return (int) get_option( self::TABLE_VERSION_OPTION, 0 ) < self::TABLE_VERSION;
	}

	/**
	 * Fetch all standard (fixed) items for a pack ordered by sort_order then id.
	 *
	 * @param int $pack_id Pack product post ID.
	 * @return array[] Rows: [{product_id, variation_id, quantity, sort_order}]
	 */
	public static function get_pack_items( $pack_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, variation_id, quantity, sort_order
				 FROM   %i
				 WHERE  pack_id = %d AND item_role = 'standard'
				 ORDER  BY sort_order ASC, id ASC",
				self::table_name(),
				absint( $pack_id )
			),
			ARRAY_A
		);

		return array_map( function ( $row ) {
			return array(
				'product_id'   => (int) $row['product_id'],
				'variation_id' => (int) $row['variation_id'],
				'quantity'     => (int) $row['quantity'],
				'sort_order'   => (int) $row['sort_order'],
			);
		}, $rows ?: array() );
	}

	/**
	 * Fetch all addon items for a custom pack ordered by sort_order then id.
	 *
	 * @param int $pack_id Pack product post ID.
	 * @return array[] Rows: [{product_id, variation_id, input_type, sort_order}]
	 */
	public static function get_addon_items( $pack_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, variation_id, input_type, sort_order
				 FROM   %i
				 WHERE  pack_id = %d AND item_role = 'addon'
				 ORDER  BY sort_order ASC, id ASC",
				self::table_name(),
				absint( $pack_id )
			),
			ARRAY_A
		);

		return array_map( function ( $row ) {
			return array(
				'product_id'   => (int) $row['product_id'],
				'variation_id' => (int) $row['variation_id'],
				'input_type'   => in_array( $row['input_type'], array( 'checkbox', 'select' ), true ) ? $row['input_type'] : 'checkbox',
				'sort_order'   => (int) $row['sort_order'],
			);
		}, $rows ?: array() );
	}

	/**
	 * Replace all standard items for a pack atomically (delete then insert).
	 * Only affects item_role = 'standard' rows; addon rows are untouched.
	 *
	 * @param int   $pack_id Pack product post ID.
	 * @param array $items   Array of items: [{product_id, variation_id, quantity}]
	 * @return bool
	 */
	public static function save_pack_items( $pack_id, array $items ) {
		global $wpdb;

		$pack_id = absint( $pack_id );
		if ( ! $pack_id ) {
			return false;
		}

		$wpdb->delete(
			self::table_name(),
			array( 'pack_id' => $pack_id, 'item_role' => 'standard' ),
			array( '%d', '%s' )
		);

		foreach ( $items as $idx => $item ) {
			$wpdb->insert(
				self::table_name(),
				array(
					'pack_id'      => $pack_id,
					'product_id'   => absint( $item['product_id'] ?? 0 ),
					'variation_id' => absint( $item['variation_id'] ?? 0 ),
					'quantity'     => max( 1, absint( $item['quantity'] ?? 1 ) ),
					'sort_order'   => (int) $idx,
					'item_role'    => 'standard',
					'input_type'   => 'checkbox',
				),
				array( '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
			);
		}

		return true;
	}

	/**
	 * Replace all addon items for a custom pack atomically (delete then insert).
	 * Only affects item_role = 'addon' rows; standard rows are untouched.
	 *
	 * @param int   $pack_id Pack product post ID.
	 * @param array $items   Array of items: [{product_id, variation_id, input_type}]
	 * @return bool
	 */
	public static function save_addon_items( $pack_id, array $items ) {
		global $wpdb;

		$pack_id = absint( $pack_id );
		if ( ! $pack_id ) {
			return false;
		}

		$wpdb->delete(
			self::table_name(),
			array( 'pack_id' => $pack_id, 'item_role' => 'addon' ),
			array( '%d', '%s' )
		);

		foreach ( $items as $idx => $item ) {
			$input_type = in_array( $item['input_type'] ?? '', array( 'checkbox', 'select' ), true )
				? $item['input_type']
				: 'checkbox';

			$wpdb->insert(
				self::table_name(),
				array(
					'pack_id'      => $pack_id,
					'product_id'   => absint( $item['product_id'] ?? 0 ),
					'variation_id' => absint( $item['variation_id'] ?? 0 ),
					'quantity'     => 0,
					'sort_order'   => (int) $idx,
					'item_role'    => 'addon',
					'input_type'   => $input_type,
				),
				array( '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
			);
		}

		return true;
	}

	/**
	 * Delete all items for a pack (both standard and addon rows).
	 * Called when a pack product is trashed/deleted.
	 *
	 * @param int $pack_id Pack product post ID.
	 * @return void
	 */
	public static function delete_pack_items( $pack_id ) {
		global $wpdb;

		$wpdb->delete(
			self::table_name(),
			array( 'pack_id' => absint( $pack_id ) ),
			array( '%d' )
		);
	}

	/**
	 * Drop the table entirely (called from uninstall.php).
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_name() );
		delete_option( self::TABLE_VERSION_OPTION );
	}
}
