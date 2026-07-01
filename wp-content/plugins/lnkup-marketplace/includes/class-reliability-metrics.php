<?php
/**
 * Vendor reliability metrics aggregation.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Reliability_Metrics {

	/**
	 * Get platform-wide reliability metrics for a vendor profile.
	 *
	 * @param int $profile_id Vendor profile post ID.
	 */
	public static function get( $profile_id ) {
		$profile_id = absint( $profile_id );
		$vendor_id  = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		if ( ! $vendor_id ) {
			return self::empty_metrics();
		}

		global $wpdb;
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_vendor_user_id',
				'meta_value'     => $vendor_id,
			)
		);

		$total_bookings   = 0;
		$completed        = 0;
		$no_shows         = 0;
		$check_ins        = 0;
		$late             = 0;
		$cancelled        = 0;
		$paid_count       = 0;
		$payment_required = 0;

		foreach ( $apps as $app_id ) {
			$status = get_post_meta( $app_id, '_lnkup_application_status', true );
			if ( in_array( $status, array( 'paid', 'completed' ), true ) || get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
				$total_bookings++;
			}
			if ( in_array( $status, array( 'paid', 'completed' ), true ) || get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
				$completed++;
			}
			if ( get_post_meta( $app_id, '_lnkup_no_show_at', true ) ) {
				$no_shows++;
			}
			if ( get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
				$check_ins++;
			}
			if ( get_post_meta( $app_id, '_lnkup_late_arrival_at', true ) ) {
				$late++;
			}
			if ( get_post_meta( $app_id, '_lnkup_cancellation_at', true ) || 'rejected' === $status ) {
				$cancelled++;
			}
			if ( 'paid' === $status || 'completed' === $status ) {
				$paid_count++;
			}
			if ( in_array( $status, array( 'payment_required', 'approved' ), true ) ) {
				$payment_required++;
			}
		}

		$docs = LNKup_Documents::get_for_vendor( $vendor_id );
		$doc_total = count( $docs );
		$doc_approved = count(
			array_filter(
				$docs,
				function ( $d ) {
					return 'approved' === get_post_meta( $d->ID, '_lnkup_verification_status', true );
				}
			)
		);

		$review_summary = class_exists( 'LNKup_Vendor_Reviews' ) ? LNKup_Vendor_Reviews::summary_for_vendor( $profile_id ) : array( 'avg' => 0, 'count' => 0 );

		$disputes = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_lnkup_vendor_user_id' AND pm.meta_value = %d
				WHERE p.post_type = 'lnkup_dispute' AND p.post_status = 'publish'",
				$vendor_id
			)
		);

		$rebooks = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pm2.meta_value) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_lnkup_vendor_user_id' AND pm.meta_value = %d
				INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_lnkup_organizer_user_id'
				WHERE p.post_type = 'lnkup_application' AND p.post_status = 'publish'",
				$vendor_id
			)
		);

		$repeat = max( 0, $rebooks - 1 );
		$denom  = max( 1, $total_bookings );

		return array(
			'completed_events'          => $completed,
			'no_show_rate'              => round( ( $no_shows / $denom ) * 100, 1 ),
			'cancellation_rate'         => round( ( $cancelled / $denom ) * 100, 1 ),
			'on_time_check_in_rate'     => $completed ? round( ( ( $check_ins - $late ) / max( 1, $completed ) ) * 100, 1 ) : 0,
			'payment_completion_rate'   => ( $paid_count + $payment_required ) ? round( ( $paid_count / ( $paid_count + $payment_required ) ) * 100, 1 ) : 100,
			'document_compliance_rate'  => $doc_total ? round( ( $doc_approved / $doc_total ) * 100, 1 ) : 0,
			'avg_organizer_rating'      => $review_summary['avg'],
			'review_count'              => $review_summary['count'],
			'repeat_booking_rate'       => $rebooks > 1 ? round( ( $repeat / max( 1, $rebooks ) ) * 100, 1 ) : 0,
			'response_rate'             => 100,
			'dispute_count'             => $disputes,
			'no_show_count'             => $no_shows,
			'check_in_count'            => $check_ins,
		);
	}

	/**
	 * Empty metrics placeholder.
	 */
	private static function empty_metrics() {
		return array(
			'completed_events'         => 0,
			'no_show_rate'             => 0,
			'cancellation_rate'        => 0,
			'on_time_check_in_rate'    => 0,
			'payment_completion_rate'  => 0,
			'document_compliance_rate' => 0,
			'avg_organizer_rating'     => 0,
			'review_count'             => 0,
			'repeat_booking_rate'      => 0,
			'response_rate'            => 0,
			'dispute_count'            => 0,
			'no_show_count'            => 0,
			'check_in_count'           => 0,
		);
	}
}
