<?php
/**
 * Opportunity match scores for vendors.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Match {

	/**
	 * Match score for a single opportunity.
	 *
	 * @param int $user_id        Vendor user ID.
	 * @param int $opportunity_id Opportunity ID.
	 */
	public static function for_opportunity( $user_id, $opportunity_id ) {
		$user_id        = $user_id ?: get_current_user_id();
		$opportunity_id = absint( $opportunity_id );
		if ( ! $user_id || ! $opportunity_id ) {
			return self::empty_score();
		}

		if ( class_exists( 'LNKup_Match_Settings' ) && LNKup_Match_Settings::enabled() ) {
			$explained = LNKup_Match_Service::explain_for_vendor( $user_id, $opportunity_id );
			if ( is_wp_error( $explained ) ) {
				return self::empty_score();
			}
			return array(
				'match_score' => (int) $explained['match_score'],
				'match_label' => (string) $explained['match_label'],
				'reasons'     => array_slice( (array) $explained['reasons'], 0, 3 ),
			);
		}

		global $wpdb;
		$table = LNKup_Marketplace_Index::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE opportunity_id = %d", $opportunity_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return self::empty_score();
		}

		$raw_score = LNKup_Recommendations::score_for_row( $user_id, $row );
		$pct       = self::normalize_score( $raw_score );
		return array(
			'match_score' => $pct,
			'match_label' => self::label_for_score( $pct, $row, $user_id ),
			'reasons'     => self::reasons_for_match( $pct, $row, $user_id ),
		);
	}

	public static function empty_score() {
		return array(
			'match_score' => 0,
			'match_label' => '',
			'reasons'     => array(),
		);
	}

	private static function normalize_score( $raw ) {
		$max = 150;
		return min( 100, max( 0, (int) round( ( $raw / $max ) * 100 ) ) );
	}

	private static function label_for_score( $pct, $row, $user_id ) {
		if ( $pct >= 90 ) {
			return __( 'Perfect Match', 'lnkup-marketplace' );
		}
		if ( $pct >= 75 ) {
			return __( 'Strong Opportunity', 'lnkup-marketplace' );
		}
		if ( $pct >= 60 ) {
			return __( 'Good Expansion Opportunity', 'lnkup-marketplace' );
		}
		$profile_id   = lnkup_get_vendor_profile_id( $user_id );
		$vendor_city  = $profile_id ? get_post_meta( $profile_id, '_lnkup_city', true ) : '';
		if ( $vendor_city && strcasecmp( $row['city'] ?? '', $vendor_city ) !== 0 && $pct >= 45 ) {
			return __( 'Outside Your Normal Market', 'lnkup-marketplace' );
		}
		if ( $pct >= 40 ) {
			return __( 'Stretch Opportunity', 'lnkup-marketplace' );
		}
		return __( 'Low Match', 'lnkup-marketplace' );
	}

	private static function reasons_for_match( $pct, $row, $user_id ) {
		$reasons     = array();
		$profile_id  = lnkup_get_vendor_profile_id( $user_id );
		$profile_cats = array();
		if ( $profile_id ) {
			$profile_cats = wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			if ( is_wp_error( $profile_cats ) ) {
				$profile_cats = array();
			}
		}
		$row_cats = json_decode( $row['categories'] ?? '[]', true ) ?: array();
		foreach ( $profile_cats as $pc ) {
			if ( in_array( $pc, $row_cats, true ) ) {
				$reasons[] = sprintf( __( 'Matches your %s category', 'lnkup-marketplace' ), $pc );
				break;
			}
		}
		$vendor_city = $profile_id ? get_post_meta( $profile_id, '_lnkup_city', true ) : '';
		if ( $vendor_city && strcasecmp( $row['city'] ?? '', $vendor_city ) === 0 ) {
			$reasons[] = __( 'In your home city', 'lnkup-marketplace' );
		}
		if ( $pct >= 75 ) {
			$reasons[] = __( 'High trending score', 'lnkup-marketplace' );
		}
		return array_slice( $reasons, 0, 3 );
	}
}
