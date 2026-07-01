<?php
/**
 * Vendor personal marketplace insights.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Intelligence {

	/**
	 * Personal insights for vendor.
	 *
	 * @param int $user_id Vendor user ID.
	 */
	public static function for_vendor( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		$cached = get_transient( 'lnkup_intel_vendor_' . $user_id );
		if ( false !== $cached ) {
			return $cached;
		}
		$profile_id = lnkup_get_vendor_profile_id( $user_id );
		$reputation = $profile_id && class_exists( 'LNKup_Vendor_Reputation' ) ? LNKup_Vendor_Reputation::get( $profile_id ) : array();
		$matches = class_exists( 'LNKup_Recommendations' ) ? LNKup_Recommendations::get_for_user( $user_id, 5 ) : array();
		$apps = lnkup_get_applications_for_user( $user_id, 'vendor' );
		$cities = array();
		$categories = array();
		foreach ( $apps as $app ) {
			$event_id = (int) get_post_meta( $app->ID, '_lnkup_event_id', true );
			$city = get_post_meta( $event_id, '_lnkup_city', true );
			if ( $city ) {
				$cities[ $city ] = ( $cities[ $city ] ?? 0 ) + 1;
			}
		}
		arsort( $cities );
		$trending_cats = class_exists( 'LNKup_Trending' ) ? LNKup_Trending::get_popular_categories( 5 ) : array();
		$suggestions = array();
		if ( $profile_id && class_exists( 'LNKup_Documents' ) ) {
			$docs = LNKup_Documents::get_for_vendor( $user_id );
			$unverified = array_filter(
				$docs,
				function ( $doc ) {
					return 'approved' !== get_post_meta( $doc->ID, '_lnkup_verification_status', true );
				}
			);
			if ( $unverified ) {
				$suggestions[] = array(
					'action_key' => 'upload_coi',
					'title'      => __( 'Upload or renew COI', 'lnkup-marketplace' ),
					'reason'     => __( 'Verified documents improve approval odds.', 'lnkup-marketplace' ),
				);
			}
		}
		if ( count( $matches ) < 3 ) {
			$suggestions[] = array(
				'action_key' => 'apply_nearby',
				'title'      => __( 'Apply to nearby events', 'lnkup-marketplace' ),
				'reason'     => __( 'Expand your pipeline with matching opportunities.', 'lnkup-marketplace' ),
			);
		}
		$data = array(
			'user_id'              => $user_id,
			'best_matches'         => $matches,
			'active_cities'        => array_slice( array_keys( $cities ), 0, 5 ),
			'trending_categories'  => $trending_cats,
			'reputation'           => $reputation,
			'reputation_trend'     => self::reputation_trend( $profile_id ),
			'payment_reliability'  => self::payment_reliability( $user_id ),
			'document_readiness'   => self::document_readiness( $user_id ),
			'suggested_actions'    => $suggestions,
			'public_trends'        => array(
				'categories' => class_exists( 'LNKup_Market_Trends' ) ? LNKup_Market_Trends::categories( array( 'limit' => 5 ) ) : array(),
				'cities'     => class_exists( 'LNKup_Market_Trends' ) ? LNKup_Market_Trends::cities( array( 'limit' => 5 ) ) : array(),
			),
		);
		set_transient( 'lnkup_intel_vendor_' . $user_id, $data, 15 * MINUTE_IN_SECONDS );
		return $data;
	}

	private static function reputation_trend( $profile_id ) {
		if ( ! $profile_id || ! class_exists( 'LNKup_Reputation_Tables' ) ) {
			return 'stable';
		}
		global $wpdb;
		$table = LNKup_Reputation_Tables::snapshot_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT score FROM {$table} WHERE profile_id = %d ORDER BY created_at DESC LIMIT 2",
				absint( $profile_id )
			),
			ARRAY_A
		);
		if ( count( $rows ) < 2 ) {
			return 'stable';
		}
		return (int) $rows[0]['score'] > (int) $rows[1]['score'] ? 'up' : ( (int) $rows[0]['score'] < (int) $rows[1]['score'] ? 'down' : 'stable' );
	}

	private static function payment_reliability( $user_id ) {
		if ( ! class_exists( 'LNKup_Reliability_Metrics' ) ) {
			return 'medium';
		}
		$profile_id = lnkup_get_vendor_profile_id( $user_id );
		if ( ! $profile_id ) {
			return 'medium';
		}
		$m = LNKup_Reliability_Metrics::get( $profile_id );
		$rate = (float) ( $m['payment_completion_rate'] ?? 0.7 );
		return $rate >= 0.85 ? 'high' : ( $rate >= 0.6 ? 'medium' : 'low' );
	}

	private static function document_readiness( $user_id ) {
		$docs = LNKup_Documents::get_for_vendor( $user_id );
		if ( ! $docs ) {
			return 'incomplete';
		}
		foreach ( $docs as $doc ) {
			if ( 'approved' !== get_post_meta( $doc->ID, '_lnkup_verification_status', true ) ) {
				return 'incomplete';
			}
		}
		return 'complete';
	}
}
