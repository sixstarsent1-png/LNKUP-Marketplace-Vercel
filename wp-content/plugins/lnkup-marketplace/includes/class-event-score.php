<?php
/**
 * Event performance score (0-100).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Score {

	public static function labels() {
		return array(
			'excellent'       => __( 'Excellent', 'lnkup-marketplace' ),
			'strong'          => __( 'Strong', 'lnkup-marketplace' ),
			'healthy'         => __( 'Healthy', 'lnkup-marketplace' ),
			'needs_attention' => __( 'Needs Attention', 'lnkup-marketplace' ),
			'at_risk'         => __( 'At Risk', 'lnkup-marketplace' ),
			'new_event'       => __( 'New Event', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Compute event performance score.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function compute( $event_id ) {
		$event_id = absint( $event_id );
		$cached   = get_transient( 'lnkup_event_score_' . $event_id );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		$is_new = self::is_new_event( $event_id );
		$breakdown = array();
		$weights   = array(
			'applications'  => 15,
			'conversion'    => 10,
			'approval'      => 10,
			'payment'       => 15,
			'readiness'     => 10,
			'checkin'       => 10,
			'no_show'       => 10,
			'support'       => 5,
			'incidents'     => 5,
			'category'      => 5,
			'revenue'       => 5,
		);
		if ( $is_new ) {
			$weights = array_map(
				function ( $w ) {
					return (int) round( $w * 0.6 );
				},
				$weights
			);
		}
		$opp_id = self::primary_opportunity( $event_id );
		$views  = $opp_id ? (int) get_post_meta( $opp_id, '_lnkup_view_count', true ) : 0;
		$app_count = count( $apps );
		$breakdown['applications'] = min( 100, $app_count * 10 );
		$breakdown['conversion'] = $views ? min( 100, round( ( $app_count / max( 1, $views ) ) * 100 ) ) : ( $app_count ? 50 : 0 );
		$approved = 0;
		$paid = 0;
		$checked_in = 0;
		$no_show = 0;
		$readiness_sum = 0;
		foreach ( $apps as $app_id ) {
			$status = get_post_meta( $app_id, '_lnkup_application_status', true );
			if ( in_array( $status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) ) {
				$approved++;
			}
			if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
				$paid++;
			}
			if ( get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
				$checked_in++;
			}
			if ( get_post_meta( $app_id, '_lnkup_no_show_at', true ) ) {
				$no_show++;
			}
			if ( class_exists( 'LNKup_Vendor_Readiness' ) ) {
				$cl = LNKup_Vendor_Readiness::checklist( $app_id );
				$readiness_sum += (int) ( $cl['percent'] ?? 0 );
			}
		}
		$breakdown['approval'] = $app_count ? min( 100, round( ( $approved / $app_count ) * 100 ) ) : 0;
		$breakdown['payment'] = $approved ? min( 100, round( ( $paid / $approved ) * 100 ) ) : 0;
		$breakdown['readiness'] = $paid ? min( 100, round( $readiness_sum / max( 1, $paid ) ) ) : 0;
		$breakdown['checkin'] = $paid ? min( 100, round( ( $checked_in / $paid ) * 100 ) ) : 0;
		$breakdown['no_show'] = $paid ? max( 0, 100 - round( ( $no_show / $paid ) * 100 ) ) : 100;
		$breakdown['support'] = 100;
		$breakdown['incidents'] = 100;
		if ( class_exists( 'LNKup_Ops_Analytics' ) ) {
			$ops = LNKup_Ops_Analytics::for_event( $event_id );
			if ( ! empty( $ops['open_support'] ) ) {
				$breakdown['support'] = max( 0, 100 - min( 100, (int) $ops['open_support'] * 15 ) );
			}
			if ( ! empty( $ops['open_incidents'] ) ) {
				$breakdown['incidents'] = max( 0, 100 - min( 100, (int) $ops['open_incidents'] * 20 ) );
			}
		}
		$breakdown['category'] = 75;
		$breakdown['revenue'] = $breakdown['payment'];
		$score = 0;
		$total_weight = array_sum( $weights );
		foreach ( $weights as $key => $weight ) {
			$score += ( $breakdown[ $key ] ?? 0 ) * ( $weight / $total_weight );
		}
		$score = (int) round( $score );
		if ( $is_new ) {
			$score = max( 50, $score );
			$label_key = 'new_event';
		} else {
			$label_key = self::label_for_score( $score );
		}
		$result = array(
			'event_id'  => $event_id,
			'score'     => $score,
			'label'     => self::labels()[ $label_key ],
			'label_key' => $label_key,
			'breakdown' => $breakdown,
			'is_new'    => $is_new,
		);
		set_transient( 'lnkup_event_score_' . $event_id, $result, HOUR_IN_SECONDS );
		return $result;
	}

	public static function persist( $event_id ) {
		$data = self::compute( $event_id );
		global $wpdb;
		$table = LNKup_Intelligence_Tables::event_scores_table();
		$wpdb->insert(
			$table,
			array(
				'event_id'    => absint( $event_id ),
				'score'       => $data['score'],
				'label'       => $data['label_key'],
				'breakdown'   => wp_json_encode( $data['breakdown'] ),
				'computed_at' => current_time( 'mysql' ),
			)
		);
		update_post_meta( $event_id, '_lnkup_event_score', $data['score'] );
		update_post_meta( $event_id, '_lnkup_event_score_label', $data['label_key'] );
		update_post_meta( $event_id, '_lnkup_event_score_at', current_time( 'mysql' ) );
		return $data;
	}

	public static function refresh_all_stale() {
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'post_status'    => 'publish',
			)
		);
		foreach ( $events as $event_id ) {
			delete_transient( 'lnkup_event_score_' . $event_id );
			self::persist( $event_id );
		}
	}

	private static function is_new_event( $event_id ) {
		$event = get_post( $event_id );
		if ( ! $event ) {
			return true;
		}
		$age_days = ( time() - strtotime( $event->post_date ) ) / DAY_IN_SECONDS;
		$event_date = get_post_meta( $event_id, '_lnkup_event_date', true );
		return $age_days < 7 && ( ! $event_date || $event_date >= gmdate( 'Y-m-d' ) );
	}

	private static function label_for_score( $score ) {
		if ( $score >= 90 ) {
			return 'excellent';
		}
		if ( $score >= 80 ) {
			return 'strong';
		}
		if ( $score >= 65 ) {
			return 'healthy';
		}
		if ( $score >= 50 ) {
			return 'needs_attention';
		}
		return 'at_risk';
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
