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
		$completed_by_org = array();

		foreach ( $apps as $app_id ) {
			$status     = get_post_meta( $app_id, '_lnkup_application_status', true );
			$checked_in = (bool) get_post_meta( $app_id, '_lnkup_checked_in_at', true );
			$no_show    = (bool) get_post_meta( $app_id, '_lnkup_no_show_at', true );
			$booked     = in_array( $status, array( 'paid', 'completed' ), true ) || $checked_in;
			if ( $booked ) {
				$total_bookings++;
			}
			if ( self::is_completed_participation( (int) $app_id, (string) $status, $checked_in, $no_show ) ) {
				$completed++;
				$org = (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true );
				if ( $org ) {
					$completed_by_org[ $org ] = ( $completed_by_org[ $org ] ?? 0 ) + 1;
				}
			}
			if ( $no_show ) {
				$no_shows++;
			}
			if ( $checked_in ) {
				$check_ins++;
			}
			if ( get_post_meta( $app_id, '_lnkup_late_arrival_at', true ) ) {
				$late++;
			}
			// Organizer rejections are decisions, not vendor cancellations.
			if ( get_post_meta( $app_id, '_lnkup_cancellation_at', true ) ) {
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

		// Review disputes are the vendor exercising a right to report, not misconduct; they never count here.
		$disputes = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_lnkup_vendor_user_id' AND pm.meta_value = %d
				LEFT JOIN {$wpdb->postmeta} rv ON rv.post_id = p.ID AND rv.meta_key = '_lnkup_review_id'
				WHERE p.post_type = 'lnkup_dispute' AND p.post_status = 'publish' AND rv.meta_id IS NULL",
				$vendor_id
			)
		);

		$organizers_worked = count( $completed_by_org );
		$organizers_repeat = count(
			array_filter(
				$completed_by_org,
				static function ( $count ) {
					return $count >= 2;
				}
			)
		);
		$denom = max( 1, $total_bookings );
		$response_rate = self::invite_response_rate( $vendor_id );

		return array(
			'completed_events'          => $completed,
			'no_show_rate'              => round( ( $no_shows / $denom ) * 100, 1 ),
			'cancellation_rate'         => round( ( $cancelled / $denom ) * 100, 1 ),
			'on_time_check_in_rate'     => $completed ? round( ( ( $check_ins - $late ) / max( 1, $completed ) ) * 100, 1 ) : 0,
			'payment_completion_rate'   => ( $paid_count + $payment_required ) ? round( ( $paid_count / ( $paid_count + $payment_required ) ) * 100, 1 ) : 100,
			'document_compliance_rate'  => $doc_total ? round( ( $doc_approved / $doc_total ) * 100, 1 ) : 0,
			'avg_organizer_rating'      => $review_summary['avg'],
			'review_count'              => $review_summary['count'],
			'repeat_booking_rate'       => $organizers_worked ? round( ( $organizers_repeat / $organizers_worked ) * 100, 1 ) : 0,
			'response_rate'             => null === $response_rate ? 0 : $response_rate,
			'response_rate_known'       => null !== $response_rate,
			'dispute_count'             => $disputes,
			'no_show_count'             => $no_shows,
			'check_in_count'            => $check_ins,
			'total_bookings'            => $total_bookings,
			'organizers_worked_with'    => $organizers_worked,
			'repeat_organizers'         => $organizers_repeat,
		);
	}

	/**
	 * Evidence-based participation: a check-in, or a paid booking for an event that has ended with no no-show recorded.
	 *
	 * @param int    $app_id     Application ID.
	 * @param string $status     Application status.
	 * @param bool   $checked_in Check-in recorded.
	 * @param bool   $no_show    No-show recorded.
	 */
	public static function is_completed_participation( int $app_id, string $status, bool $checked_in, bool $no_show ): bool {
		if ( $no_show ) {
			return false;
		}
		if ( $checked_in ) {
			return true;
		}
		if ( ! in_array( $status, array( 'paid', 'completed' ), true ) || get_post_meta( $app_id, '_lnkup_cancellation_at', true ) ) {
			return false;
		}
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		return class_exists( 'LNKup_Vendor_Reviews' ) ? LNKup_Vendor_Reviews::event_has_ended( $event_id ) : false;
	}

	/**
	 * Share of decided organizer invites the vendor answered (accepted or declined) instead of letting expire.
	 * Null until at least 3 invites were decided.
	 *
	 * @param int $vendor_id Vendor user ID.
	 */
	private static function invite_response_rate( int $vendor_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT st.meta_value AS status, COUNT(*) AS n FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_lnkup_vendor_user_id' AND v.meta_value = %d
				INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_lnkup_status'
				WHERE p.post_type = 'lnkup_vendor_invite' AND st.meta_value IN ('accepted','declined','expired')
				GROUP BY st.meta_value",
				$vendor_id
			),
			ARRAY_A
		);
		$counts = array( 'accepted' => 0, 'declined' => 0, 'expired' => 0 );
		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
		}
		$decided = array_sum( $counts );
		if ( $decided < 3 ) {
			return null;
		}
		return round( ( ( $counts['accepted'] + $counts['declined'] ) / $decided ) * 100, 1 );
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
			'response_rate_known'      => false,
			'dispute_count'            => 0,
			'no_show_count'            => 0,
			'check_in_count'           => 0,
			'total_bookings'           => 0,
			'organizers_worked_with'   => 0,
			'repeat_organizers'        => 0,
		);
	}
}
