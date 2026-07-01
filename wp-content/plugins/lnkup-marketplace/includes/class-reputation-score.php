<?php
/**
 * LNKup vendor reputation score calculator.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Reputation_Score {

	const LABEL_NEW       = 'New Vendor — Building Reputation';
	const LABEL_ELITE     = 'Elite';
	const LABEL_TRUSTED   = 'Trusted';
	const LABEL_RELIABLE  = 'Reliable';
	const LABEL_NEEDS     = 'Needs Improvement';
	const LABEL_AT_RISK   = 'At Risk';

	/**
	 * Calculate score and breakdown for vendor profile.
	 *
	 * @param int $profile_id Vendor profile post ID.
	 */
	public static function calculate( $profile_id ) {
		$profile_id = absint( $profile_id );
		$metrics    = LNKup_Reliability_Metrics::get( $profile_id );
		$vendor_id  = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );

		$weights = apply_filters(
			'lnkup_reputation_score_weights',
			array(
				'base'           => 40,
				'completed'      => 3,
				'rating'         => 8,
				'payment'        => 10,
				'documents'      => 8,
				'check_in'       => 8,
				'rebook'         => 5,
				'no_show_pen'    => 12,
				'cancel_pen'     => 6,
				'flag_pen'       => 8,
				'dispute_pen'    => 5,
			)
		);

		$breakdown = array();
		$score     = (float) $weights['base'];
		$breakdown['base'] = $weights['base'];

		$completed_pts = min( 25, $metrics['completed_events'] * $weights['completed'] );
		$score += $completed_pts;
		$breakdown['completed_events'] = $completed_pts;

		$rating_pts = 0;
		if ( $metrics['avg_organizer_rating'] > 0 ) {
			$rating_pts = ( $metrics['avg_organizer_rating'] / 5 ) * $weights['rating'];
		}
		$score += $rating_pts;
		$breakdown['reviews'] = round( $rating_pts, 2 );

		$pay_pts = ( $metrics['payment_completion_rate'] / 100 ) * $weights['payment'];
		$score += $pay_pts;
		$breakdown['payment'] = round( $pay_pts, 2 );

		$doc_pts = ( $metrics['document_compliance_rate'] / 100 ) * $weights['documents'];
		$score += $doc_pts;
		$breakdown['documents'] = round( $doc_pts, 2 );

		$check_pts = ( $metrics['on_time_check_in_rate'] / 100 ) * $weights['check_in'];
		$score += $check_pts;
		$breakdown['check_in'] = round( $check_pts, 2 );

		$rebook_pts = ( $metrics['repeat_booking_rate'] / 100 ) * $weights['rebook'];
		$score += $rebook_pts;
		$breakdown['rebooking'] = round( $rebook_pts, 2 );

		$no_show_pen = min( $weights['no_show_pen'], ( $metrics['no_show_rate'] / 100 ) * $weights['no_show_pen'] * 2 );
		$score -= $no_show_pen;
		$breakdown['no_show_penalty'] = round( -$no_show_pen, 2 );

		$cancel_pen = min( $weights['cancel_pen'], ( $metrics['cancellation_rate'] / 100 ) * $weights['cancel_pen'] * 2 );
		$score -= $cancel_pen;
		$breakdown['cancellation_penalty'] = round( -$cancel_pen, 2 );

		$flag_count = self::platform_flag_count( $vendor_id );
		$flag_pen   = min( $weights['flag_pen'], $flag_count * 4 );
		$score -= $flag_pen;
		$breakdown['flags_penalty'] = round( -$flag_pen, 2 );

		$dispute_pen = min( $weights['dispute_pen'], $metrics['dispute_count'] * 3 );
		$score -= $dispute_pen;
		$breakdown['disputes_penalty'] = round( -$dispute_pen, 2 );

		$override = (int) get_post_meta( $profile_id, '_lnkup_reputation_score_override', true );
		if ( $override > 0 ) {
			$score = (float) $override;
			$breakdown['admin_override'] = $override;
		}

		$score = (int) round( max( 0, min( 100, $score ) ) );

		if ( $metrics['completed_events'] >= 10 && $score < 35 ) {
			$score = 35;
			$breakdown['recovery_floor'] = 35;
		}

		$is_new = $metrics['completed_events'] < 2 && $metrics['review_count'] < 1;
		$label  = $is_new ? self::LABEL_NEW : self::label_for_score( $score );

		$result = array(
			'score'      => $score,
			'label'      => $label,
			'is_new'     => $is_new,
			'breakdown'  => $breakdown,
			'metrics'    => $metrics,
			'avg_rating' => $metrics['avg_organizer_rating'],
			'review_count' => $metrics['review_count'],
		);

		return apply_filters( 'lnkup_reputation_score_result', $result, $profile_id );
	}

	/**
	 * Score label from numeric score.
	 *
	 * @param int $score Score 0-100.
	 */
	public static function label_for_score( $score ) {
		if ( $score >= 90 ) {
			return self::LABEL_ELITE;
		}
		if ( $score >= 80 ) {
			return self::LABEL_TRUSTED;
		}
		if ( $score >= 65 ) {
			return self::LABEL_RELIABLE;
		}
		if ( $score >= 50 ) {
			return self::LABEL_NEEDS;
		}
		return self::LABEL_AT_RISK;
	}

	/**
	 * Count platform-wide flags for vendor.
	 *
	 * @param int $vendor_id Vendor user ID.
	 */
	private static function platform_flag_count( $vendor_id ) {
		$flags = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_flag',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_vendor_user_id',
				'meta_value'     => $vendor_id,
			)
		);
		return count( $flags );
	}
}
