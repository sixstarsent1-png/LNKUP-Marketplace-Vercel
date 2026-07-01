<?php
/**
 * Rule-based predictive placeholders for future AI.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Predictive_Placeholders {

	/**
	 * Predictions for event scope.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function for_event( $event_id ) {
		$event_id = absint( $event_id );
		$opp_id   = self::primary_opportunity( $event_id );
		$views    = $opp_id ? (int) get_post_meta( $opp_id, '_lnkup_view_count', true ) : 0;
		$apps     = count(
			get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_lnkup_event_id',
					'meta_value'     => $event_id,
				)
			)
		);
		$conversion = $views ? $apps / $views : 0.08;
		$predicted_apps = (int) round( max( $apps, $views * $conversion * 1.2 ) );
		$funnel = class_exists( 'LNKup_Funnel_Analytics' ) ? LNKup_Funnel_Analytics::payment_funnel( $event_id ) : array();
		$pay_rate = isset( $funnel['completion_rate'] ) ? (float) $funnel['completion_rate'] / 100 : 0.75;
		$categories = class_exists( 'LNKup_Market_Trends' ) ? LNKup_Market_Trends::categories( array( 'limit' => 5 ) ) : array();
		$shortage = array_values(
			array_filter(
				$categories,
				function ( $c ) {
					return 'shortage' === ( $c['status'] ?? '' );
				}
			)
		);
		return array(
			'predicted_application_volume' => $predicted_apps,
			'predicted_payment_completion' => round( $pay_rate * 100, 1 ) . '%',
			'category_recommendation'      => $shortage ? $shortage[0]['category'] : '',
			'event_risk_prediction'        => class_exists( 'LNKup_Event_Score' ) ? LNKup_Event_Score::compute( $event_id )['label_key'] : 'unknown',
			'suggested_vendor_mix'         => array_slice( wp_list_pluck( $categories, 'category' ), 0, 3 ),
			'recommended_vendor_fee'       => self::suggested_fee( $opp_id ),
			'disclaimer'                   => __( 'Rule-based estimates. AI predictions coming soon.', 'lnkup-marketplace' ),
		);
	}

	private static function suggested_fee( $opp_id ) {
		if ( ! $opp_id ) {
			return null;
		}
		$fee = (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true );
		if ( $fee > 0 ) {
			return $fee;
		}
		return null;
	}

	private static function primary_opportunity( $event_id ) {
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => absint( $event_id ),
			)
		);
		return $opps ? (int) $opps[0] : 0;
	}
}
