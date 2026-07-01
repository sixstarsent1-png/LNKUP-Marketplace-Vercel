<?php
/**
 * Premium listing purchases and metrics.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Premium_Listings {

	/**
	 * Purchase premium placement.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    Placement type.
	 * @param int    $target_id Target post ID.
	 * @param float  $amount  Amount paid.
	 */
	public static function purchase( $user_id, $type, $target_id, $amount ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::premium_listings_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}
		$now = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'user_id'        => absint( $user_id ),
				'placement_type' => sanitize_key( $type ),
				'target_id'      => absint( $target_id ),
				'amount'         => round( (float) $amount, 2 ),
				'status'         => 'active',
				'impressions'    => 0,
				'clicks'         => 0,
				'starts_at'      => $now,
				'ends_at'        => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ),
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * List premium listings for user.
	 *
	 * @param int $user_id User ID.
	 */
	public static function list_for_user( $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::premium_listings_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 25", absint( $user_id ) ),
			ARRAY_A
		);
	}

	/**
	 * Record impression.
	 *
	 * @param int $listing_id Listing ID.
	 */
	public static function track_impression( $listing_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::premium_listings_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET impressions = impressions + 1, updated_at = %s WHERE id = %d", current_time( 'mysql' ), absint( $listing_id ) ) );
	}

	/**
	 * Record click.
	 *
	 * @param int $listing_id Listing ID.
	 */
	public static function track_click( $listing_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::premium_listings_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET clicks = clicks + 1, updated_at = %s WHERE id = %d", current_time( 'mysql' ), absint( $listing_id ) ) );
	}
}
