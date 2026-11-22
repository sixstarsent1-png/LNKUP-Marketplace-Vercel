<?php
/**
 * Personalized opportunity recommendations.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Recommendations {

	/**
	 * Get recommended opportunities for vendor.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Limit.
	 */
	public static function get_for_user( $user_id = 0, $limit = 8 ) {
		$user_id = $user_id ?: get_current_user_id();
		$limit   = absint( $limit );

		if ( $user_id && class_exists( 'LNKup_Match_Settings' ) && LNKup_Match_Settings::enabled( 'matching_vendor_discovery_enabled' ) && LNKup_Roles::is_vendor( $user_id ) ) {
			$matched = LNKup_Match_Service::vendor_recommendations( $user_id, array( 'section' => 'recommended', 'per_page' => min( 24, max( 1, $limit ) ) ) );
			if ( ! is_wp_error( $matched ) && ! empty( $matched['items'] ) ) {
				return array_slice( $matched['items'], 0, $limit );
			}
		}

		global $wpdb;
		$table = LNKup_Marketplace_Index::table_name();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY trending_score DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return self::fallback( $limit );
		}

		if ( ! $user_id ) {
			return self::fallback( $limit );
		}

		$profile_id = lnkup_get_vendor_profile_id( $user_id );
		$profile_cats = array();
		if ( $profile_id ) {
			$profile_cats = wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			if ( is_wp_error( $profile_cats ) ) {
				$profile_cats = array();
			}
		}

		$vendor_city  = $profile_id ? get_post_meta( $profile_id, '_lnkup_city', true ) : '';
		$favorites    = LNKup_Vendor_Personalization::get_favorites( $user_id );
		$recent       = LNKup_Vendor_Personalization::get_recently_viewed( $user_id );
		$applications = lnkup_get_applications_for_user( $user_id, 'vendor' );
		$applied_opp_ids = array();
		foreach ( $applications as $app ) {
			$applied_opp_ids[] = (int) get_post_meta( $app->ID, '_lnkup_opportunity_id', true );
		}

		$scored = array();
		foreach ( $rows as $row ) {
			if ( in_array( (int) $row['opportunity_id'], $applied_opp_ids, true ) ) {
				continue;
			}
			$score = self::score_row( $row, $profile_cats, $vendor_city, $favorites, $recent );
			$scored[] = array( 'score' => $score, 'row' => $row );
		}

		usort( $scored, function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );

		$items = array();
		foreach ( array_slice( $scored, 0, $limit ) as $s ) {
			$items[] = lnkup_format_index_row( $s['row'] );
		}

		if ( count( $items ) < $limit ) {
			$fallback = self::fallback( $limit - count( $items ) );
			$existing = wp_list_pluck( $items, 'opportunity_id' );
			foreach ( $fallback as $f ) {
				if ( ! in_array( $f['opportunity_id'], $existing, true ) ) {
					$items[] = $f;
				}
			}
		}

		return array_slice( $items, 0, $limit );
	}

	/**
	 * Public score for a marketplace index row.
	 *
	 * @param int   $user_id Vendor user ID.
	 * @param array $row     Index row.
	 */
	public static function score_for_row( $user_id, $row ) {
		$user_id = $user_id ?: get_current_user_id();
		$profile_id = lnkup_get_vendor_profile_id( $user_id );
		$profile_cats = array();
		if ( $profile_id ) {
			$profile_cats = wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			if ( is_wp_error( $profile_cats ) ) {
				$profile_cats = array();
			}
		}
		$vendor_city = $profile_id ? get_post_meta( $profile_id, '_lnkup_city', true ) : '';
		$favorites   = LNKup_Vendor_Personalization::get_favorites( $user_id );
		$recent      = LNKup_Vendor_Personalization::get_recently_viewed( $user_id );
		return self::score_row( $row, $profile_cats, $vendor_city, $favorites, $recent );
	}

	/**
	 * Score a single index row for vendor.
	 */
	private static function score_row( $row, $profile_cats, $vendor_city, $favorites, $recent ) {
		$score = (float) ( $row['trending_score'] ?? 0 );

		$row_cats = json_decode( $row['categories'] ?? '[]', true ) ?: array();
		foreach ( $profile_cats as $pc ) {
			if ( in_array( $pc, $row_cats, true ) ) {
				$score += 25;
			}
		}

		if ( $vendor_city && strcasecmp( $row['city'], $vendor_city ) === 0 ) {
			$score += 20;
		}

		if ( in_array( (int) $row['organizer_user_id'], (array) $favorites['organizers'], true ) ) {
			$score += 30;
		}
		if ( in_array( (int) $row['opportunity_id'], (array) $favorites['opportunities'], true ) ) {
			$score += 15;
		}

		foreach ( $recent as $r ) {
			if ( 'opportunity' === $r['type'] && (int) $r['id'] === (int) $row['opportunity_id'] ) {
				$score += 10;
			}
		}

		$event_types = json_decode( $row['event_types'] ?? '[]', true ) ?: array();
		if ( in_array( 'Festival', $event_types, true ) ) {
			$score += 5;
		}

		return $score;
	}

	/**
	 * Fallback recommendations when personalization is sparse.
	 *
	 * @param int $limit Limit.
	 */
	public static function fallback( $limit = 8 ) {
		$items = LNKup_Trending::get_section( 'trending', $limit );
		if ( count( $items ) >= $limit ) {
			return $items;
		}
		$newest = LNKup_Trending::get_section( 'recently_added', $limit );
		$ids    = wp_list_pluck( $items, 'opportunity_id' );
		foreach ( $newest as $n ) {
			if ( ! in_array( $n['opportunity_id'], $ids, true ) ) {
				$items[] = $n;
			}
		}
		return array_slice( $items, 0, $limit );
	}
}
