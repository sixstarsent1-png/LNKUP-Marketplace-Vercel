<?php
/**
 * Opportunity quality score.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Opportunity_Score {

	public static function compute( $opportunity_id ) {
		$opportunity_id = absint( $opportunity_id );
		$opp = get_post( $opportunity_id );
		if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type ) {
			return null;
		}
		$event_id = (int) get_post_meta( $opportunity_id, '_lnkup_event_id', true );
		$issues   = array();
		$points   = 0;
		$max      = 100;
		$checks   = array(
			array( 'title', 10, strlen( $opp->post_title ) >= 5, __( 'Add a clear event title.', 'lnkup-marketplace' ) ),
			array( 'description', 15, strlen( wp_strip_all_tags( $opp->post_content ) ) >= 80, __( 'Expand the event description.', 'lnkup-marketplace' ) ),
			array( 'image', 15, (bool) get_post_thumbnail_id( $event_id ?: $opportunity_id ), __( 'Add an event image.', 'lnkup-marketplace' ) ),
			array( 'city', 10, (bool) get_post_meta( $event_id, '_lnkup_city', true ), __( 'Set the event city.', 'lnkup-marketplace' ) ),
			array( 'attendance', 10, (int) get_post_meta( $event_id, '_lnkup_expected_attendance', true ) > 0, __( 'Add expected attendance.', 'lnkup-marketplace' ) ),
			array( 'fee', 10, (float) get_post_meta( $opportunity_id, '_lnkup_vendor_fee', true ) > 0, __( 'Set a vendor fee.', 'lnkup-marketplace' ) ),
			array( 'deadline', 10, (bool) get_post_meta( $opportunity_id, '_lnkup_application_deadline', true ), __( 'Set an application deadline.', 'lnkup-marketplace' ) ),
			array( 'docs', 10, (bool) get_post_meta( $opportunity_id, '_lnkup_required_documents', true ), __( 'Specify required documents.', 'lnkup-marketplace' ) ),
			array( 'verified', 10, (bool) get_post_meta( $event_id, '_lnkup_organizer_verified', true ), __( 'Complete organizer verification.', 'lnkup-marketplace' ) ),
		);
		foreach ( $checks as $check ) {
			if ( $check[2] ) {
				$points += $check[1];
			} else {
				$issues[] = $check[3];
			}
		}
		$score = min( $max, $points );
		$label = $score >= 80 ? 'high' : ( $score >= 55 ? 'medium' : 'low' );
		return array(
			'opportunity_id' => $opportunity_id,
			'quality_score'  => $score,
			'label'          => $label,
			'issues'         => $issues,
		);
	}

	public static function persist( $opportunity_id ) {
		$data = self::compute( $opportunity_id );
		if ( ! $data ) {
			return null;
		}
		global $wpdb;
		$table = LNKup_Intelligence_Tables::opportunity_scores_table();
		$wpdb->insert(
			$table,
			array(
				'opportunity_id' => absint( $opportunity_id ),
				'quality_score'  => $data['quality_score'],
				'label'          => $data['label'],
				'issues'         => wp_json_encode( $data['issues'] ),
				'computed_at'    => current_time( 'mysql' ),
			)
		);
		update_post_meta( $opportunity_id, '_lnkup_opportunity_quality_score', $data['quality_score'] );
		update_post_meta( $opportunity_id, '_lnkup_opportunity_quality_at', current_time( 'mysql' ) );
		return $data;
	}

	public static function refresh_all_stale() {
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'post_status'    => 'publish',
			)
		);
		foreach ( $opps as $opp_id ) {
			self::persist( $opp_id );
		}
	}
}
