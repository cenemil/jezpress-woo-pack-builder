<?php
/**
 * JWPB_DB — dedicated database table for pack item storage.
 *
 * Table: {prefix}jwpb_pack_items
 * Columns: id, pack_id, product_id, variation_id, quantity, sort_order
 *
 * @package JezPress_Woo_Pack_Builder
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPB_DB {

	const TABLE_VERSION        = 1;
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
	 * Safe to call on every activation — dbDelta is idempotent.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			pack_id     bigint(20) UNSIGNED NOT NULL,
			product_id  bigint(20) UNSIGNED NOT NULL,
			variation_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			quantity    int(11)    UNSIGNED NOT NULL DEFAULT 1,
			sort_order  int(11)    UNSIGNED NOT NULL DEFAULT 0,
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
	 * Fetch all items for a pack ordered by sort_order then id.
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
				 WHERE  pack_id = %d
				 ORDER  BY sort_order ASC, id ASC",
				self::table_name(),
				absint( $pack_id )
			),
			ARRAY_A
		);

		// Cast numeric strings to ints.
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
	 * Replace all items for a pack atomically (delete then insert).
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

		// Delete existing rows for this pack.
		$wpdb->delete(
			self::table_name(),
			array( 'pack_id' => $pack_id ),
			array( '%d' )
		);

		// Insert new rows.
		foreach ( $items as $idx => $item ) {
			$wpdb->insert(
				self::table_name(),
				array(
					'pack_id'      => $pack_id,
					'product_id'   => absint( $item['product_id'] ?? 0 ),
					'variation_id' => absint( $item['variation_id'] ?? 0 ),
					'quantity'     => max( 1, absint( $item['quantity'] ?? 1 ) ),
					'sort_order'   => (int) $idx,
				),
				array( '%d', '%d', '%d', '%d', '%d' )
			);
		}

		return true;
	}

	/**
	 * Delete all items for a pack (e.g. when pack product is trashed/deleted).
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
