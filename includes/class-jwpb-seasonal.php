<?php
/**
 * JWPB_Seasonal — seasonal product pool management and rotation.
 *
 * Rotation writes to the custom DB table via JWPB_DB.
 * Item shape: [ product_id, variation_id (0), quantity (1), sort_order ]
 *
 * @package JezPress_Woo_Pack_Builder
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPB_Seasonal {

	/**
	 * Return all in-stock product IDs tagged 'seasonal'.
	 *
	 * @return int[]
	 */
	public static function get_pool() {
		$tag   = JWPB_Settings::get( 'seasonal_tag', 'seasonal' );
		$query = new WC_Product_Query( array(
			'tag'          => array( $tag ),
			'stock_status' => 'instock',
			'status'       => 'publish',
			'limit'        => -1,
			'return'       => 'ids',
		) );

		return array_map( 'absint', $query->get_products() );
	}

	/**
	 * Return count of eligible seasonal products.
	 *
	 * @return int
	 */
	public static function get_pool_count() {
		return count( self::get_pool() );
	}

	/**
	 * Randomly draw $count products from the seasonal pool and write them to
	 * the pack items DB table. Returns the new items array.
	 *
	 * @param int $pack_id Pack product post ID.
	 * @param int $count   Number of items to draw.
	 * @return array[] New items list: [{product_id, variation_id, quantity, sort_order}]
	 */
	public static function rotate( $pack_id, $count ) {
		$pool  = self::get_pool();
		$count = max( 1, absint( $count ) );

		if ( empty( $pool ) ) {
			return array();
		}

		if ( count( $pool ) <= $count ) {
			$selected = $pool;
		} else {
			$keys     = (array) array_rand( $pool, $count );
			$selected = array_map( function ( $k ) use ( $pool ) { return $pool[ $k ]; }, $keys );
		}

		$items = array();
		foreach ( $selected as $product_id ) {
			$items[] = array(
				'product_id'   => (int) $product_id,
				'variation_id' => 0,
				'quantity'     => 1,
			);
		}

		JWPB_DB::save_pack_items( $pack_id, $items );
		update_post_meta( $pack_id, '_pack_seasonal_last_rotated', current_time( 'mysql' ) );
		wc_delete_product_transients( $pack_id );

		// Return with sort_order added for consistency with DB shape.
		return array_map( function ( $item, $idx ) {
			$item['sort_order'] = $idx;
			return $item;
		}, $items, array_keys( $items ) );
	}
}
