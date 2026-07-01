<?php
/**
 * Trending and popularity scoring.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Trending {

	/**
	 * Compute trending score for an opportunity.
	 *
	 * @param array $data Index row data.
	 */
	public static function compute_score( $data ) {
		$apps     = (int) ( $data['application_count'] ?? 0 );
		$views    = (int) ( $data['view_count'] ?? 0 );
		$spots_r  = max( 1, (int) ( $data['spots_remaining'] ?? 1 ) );
		$spots_t  = max( 1, (int) ( $data['spots_total'] ?? 1 ) );
		$fill_pct = 1 - ( $spots_r / $spots_t );

		$recency = 0;
		if ( ! empty( $data['created_at'] ) ) {
			$days = max( 0, ( time() - strtotime( $data['created_at'] ) ) / DAY_IN_SECONDS );
			$recency = max( 0, 30 - $days ) / 30;
		}

		$urgency = 0;
		if ( ! empty( $data['deadline'] ) ) {
			$days_left = ( strtotime( $data['deadline'] ) - time() ) / DAY_IN_SECONDS;
			if ( $days_left >= 0 && $days_left <= 7 ) {
				$urgency = 1 - ( $days_left / 7 );
			}
		}

		return round( ( $apps * 3 ) + ( $views * 0.5 ) + ( $fill_pct * 20 ) + ( $recency * 15 ) + ( $urgency * 25 ), 4 );
	}

	/**
	 * Get trending section items.
	 *
	 * @param string $section Section key.
	 * @param int    $limit   Limit.
	 */
	public static function get_section( $section, $limit = 8 ) {
		global $wpdb;
		$table = LNKup_Marketplace_Index::table_name();
		$limit = absint( $limit );

		switch ( $section ) {
			case 'trending':
				$sql = "SELECT * FROM {$table} ORDER BY trending_score DESC LIMIT {$limit}";
				break;
			case 'most_applied':
				$sql = "SELECT * FROM {$table} ORDER BY application_count DESC LIMIT {$limit}";
				break;
			case 'popular_week':
				$sql = $wpdb->prepare(
					"SELECT * FROM {$table} WHERE created_at >= %s ORDER BY view_count DESC LIMIT {$limit}",
					gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
				);
				break;
			case 'recently_added':
				$sql = "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT {$limit}";
				break;
			case 'closing_soon':
				$sql = $wpdb->prepare(
					"SELECT * FROM {$table} WHERE deadline IS NOT NULL AND deadline >= %s AND deadline <= %s ORDER BY deadline ASC LIMIT {$limit}",
					gmdate( 'Y-m-d' ),
					gmdate( 'Y-m-d', strtotime( '+14 days' ) )
				);
				break;
			case 'almost_full':
				$sql = "SELECT * FROM {$table} WHERE spots_total > 0 AND (spots_remaining / spots_total) <= 0.1 ORDER BY spots_remaining ASC LIMIT {$limit}";
				break;
			case 'new_organizer':
				$sql = $wpdb->prepare(
					"SELECT * FROM {$table} WHERE created_at >= %s ORDER BY created_at DESC LIMIT {$limit}",
					gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
				);
				break;
			case 'featured_organizer':
				$sql = "SELECT * FROM {$table} WHERE organizer_verified = 1 ORDER BY organizer_rating DESC, trending_score DESC LIMIT {$limit}";
				break;
			default:
				$sql = "SELECT * FROM {$table} ORDER BY trending_score DESC LIMIT {$limit}";
		}

		$cache_key = 'lnkup_trend_' . md5( $section . $limit );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = lnkup_format_index_row( $row );
		}

		set_transient( $cache_key, $items, 15 * MINUTE_IN_SECONDS );
		return $items;
	}

	/**
	 * Get all section definitions for marketplace rails.
	 */
	public static function get_all_sections() {
		return array(
			'trending'          => __( 'Trending Events', 'lnkup-marketplace' ),
			'most_applied'      => __( 'Most Applied', 'lnkup-marketplace' ),
			'popular_week'      => __( 'Popular This Week', 'lnkup-marketplace' ),
			'recently_added'    => __( 'Recently Added', 'lnkup-marketplace' ),
			'closing_soon'      => __( 'Closing Soon', 'lnkup-marketplace' ),
			'almost_full'       => __( 'Almost Full', 'lnkup-marketplace' ),
			'featured_organizer'=> __( 'Featured Organizers', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Get popular categories from index.
	 *
	 * @param int $limit Limit.
	 */
	public static function get_popular_categories( $limit = 8 ) {
		global $wpdb;
		$table = LNKup_Marketplace_Index::table_name();
		$rows  = $wpdb->get_col( "SELECT categories FROM {$table} WHERE categories != '' AND categories != '[]'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$counts = array();
		foreach ( $rows as $json ) {
			$cats = json_decode( $json, true );
			if ( ! is_array( $cats ) ) {
				continue;
			}
			foreach ( $cats as $cat ) {
				$counts[ $cat ] = ( $counts[ $cat ] ?? 0 ) + 1;
			}
		}
		arsort( $counts );
		return array_slice( array_keys( $counts ), 0, $limit );
	}
}
