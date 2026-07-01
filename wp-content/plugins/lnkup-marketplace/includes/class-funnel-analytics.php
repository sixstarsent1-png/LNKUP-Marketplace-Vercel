<?php
/**
 * Application and payment funnel analytics.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Funnel_Analytics {

	/**
	 * Application funnel for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function application_funnel( $event_id ) {
		$cached = get_transient( 'lnkup_intel_funnel_app_' . $event_id );
		if ( false !== $cached ) {
			return $cached;
		}
		$opp_id = self::primary_opportunity( $event_id );
		$views  = $opp_id ? (int) get_post_meta( $opp_id, '_lnkup_view_count', true ) : 0;
		$apps   = self::apps_for_event( $event_id );
		$stages = array(
			'views'              => $views,
			'applications_started' => self::count_status( $apps, array( 'draft', 'submitted', 'under_review', 'approved', 'payment_required', 'paid', 'completed', 'waitlisted', 'rejected' ) ),
			'submitted'          => self::count_status( $apps, array( 'submitted', 'under_review', 'approved', 'payment_required', 'paid', 'completed', 'waitlisted', 'rejected' ) ),
			'approved'           => self::count_status( $apps, array( 'approved', 'payment_required', 'paid', 'completed' ) ),
			'waitlisted'         => self::count_status( $apps, array( 'waitlisted' ) ),
			'rejected'           => self::count_status( $apps, array( 'rejected' ) ),
			'payment_required'   => self::count_status( $apps, array( 'payment_required', 'paid', 'completed' ) ),
			'paid'               => self::count_status( $apps, array( 'paid', 'completed' ) ),
			'packets_unlocked'   => self::count_packets_unlocked( $apps ),
			'checked_in'         => self::count_meta( $apps, '_lnkup_checked_in_at' ),
			'completed'          => self::count_status( $apps, array( 'completed' ) ),
		);
		$stages['conversion_rates'] = self::conversion_rates( $stages );
		$stages['insight'] = self::application_insight( $stages );
		set_transient( 'lnkup_intel_funnel_app_' . $event_id, $stages, 15 * MINUTE_IN_SECONDS );
		return $stages;
	}

	/**
	 * Payment funnel for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function payment_funnel( $event_id ) {
		$cached = get_transient( 'lnkup_intel_funnel_pay_' . $event_id );
		if ( false !== $cached ) {
			return $cached;
		}
		$apps = self::apps_for_event( $event_id );
		$approved = self::count_status( $apps, array( 'approved', 'payment_required', 'paid', 'completed' ) );
		$payment_required = self::count_status( $apps, array( 'payment_required', 'paid', 'completed' ) );
		$paid = self::count_status( $apps, array( 'paid', 'completed' ) );
		$pending = self::count_payment_status( $apps, 'pending' );
		$failed = self::count_payment_status( $apps, 'failed' );
		$revenue = class_exists( 'LNKup_Revenue_Intelligence' ) ? LNKup_Revenue_Intelligence::for_event( $event_id ) : array();
		$stages = array(
			'approved_vendors'     => $approved,
			'payment_required'     => $payment_required,
			'payment_pending'      => $pending,
			'payment_completed'    => $paid,
			'payment_failed'       => $failed,
			'payment_overdue'      => max( 0, $payment_required - $paid - $pending ),
			'completion_rate'      => $approved ? round( ( $paid / $approved ) * 100, 1 ) : 0,
			'avg_time_to_pay'      => self::avg_time_to_pay( $apps ),
			'revenue'              => $revenue,
		);
		$stages['insight'] = self::payment_insight( $stages );
		set_transient( 'lnkup_intel_funnel_pay_' . $event_id, $stages, 15 * MINUTE_IN_SECONDS );
		return $stages;
	}

	private static function apps_for_event( $event_id ) {
		return get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => absint( $event_id ),
			)
		);
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

	private static function count_status( $app_ids, $statuses ) {
		$n = 0;
		foreach ( $app_ids as $app_id ) {
			if ( in_array( get_post_meta( $app_id, '_lnkup_application_status', true ), $statuses, true ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function count_meta( $app_ids, $meta_key ) {
		$n = 0;
		foreach ( $app_ids as $app_id ) {
			if ( get_post_meta( $app_id, $meta_key, true ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function count_packets_unlocked( $app_ids ) {
		$n = 0;
		foreach ( $app_ids as $app_id ) {
			$packets = get_posts(
				array(
					'post_type'      => 'lnkup_packet',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_lnkup_application_id',
					'meta_value'     => $app_id,
				)
			);
			if ( $packets && class_exists( 'LNKup_Packets' ) && LNKup_Packets::is_unlocked( $packets[0] ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function count_payment_status( $app_ids, $status ) {
		$n = 0;
		foreach ( $app_ids as $app_id ) {
			$payments = get_posts(
				array(
					'post_type'      => 'lnkup_payment',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_lnkup_application_id',
					'meta_value'     => $app_id,
				)
			);
			if ( $payments && get_post_meta( $payments[0], '_lnkup_payment_status', true ) === $status ) {
				$n++;
			}
		}
		return $n;
	}

	private static function avg_time_to_pay( $app_ids ) {
		$hours = array();
		foreach ( $app_ids as $app_id ) {
			$approved = get_post_meta( $app_id, '_lnkup_approved_at', true );
			$paid = get_post_meta( $app_id, '_lnkup_paid_at', true );
			if ( $approved && $paid ) {
				$hours[] = max( 0, ( strtotime( $paid ) - strtotime( $approved ) ) / HOUR_IN_SECONDS );
			}
		}
		if ( ! $hours ) {
			return null;
		}
		return round( array_sum( $hours ) / count( $hours ), 1 );
	}

	private static function conversion_rates( $stages ) {
		$views = max( 1, (int) $stages['views'] );
		return array(
			'view_to_apply' => round( ( $stages['submitted'] / $views ) * 100, 1 ),
			'apply_to_approve' => $stages['submitted'] ? round( ( $stages['approved'] / $stages['submitted'] ) * 100, 1 ) : 0,
			'approve_to_pay' => $stages['approved'] ? round( ( $stages['paid'] / $stages['approved'] ) * 100, 1 ) : 0,
		);
	}

	private static function application_insight( $stages ) {
		if ( $stages['views'] > 10 && $stages['submitted'] < max( 2, $stages['views'] * 0.05 ) ) {
			return sprintf(
				/* translators: 1: views 2: applications */
				__( '%1$d vendors viewed this opportunity, but only %2$d applied. Consider lowering the vendor fee or improving the event description.', 'lnkup-marketplace' ),
				$stages['views'],
				$stages['submitted']
			);
		}
		if ( $stages['submitted'] && $stages['approved'] / max( 1, $stages['submitted'] ) < 0.3 ) {
			return __( 'Approval rate is below average. Review application criteria or vendor fit.', 'lnkup-marketplace' );
		}
		return '';
	}

	private static function payment_insight( $stages ) {
		if ( $stages['approved_vendors'] && $stages['completion_rate'] < 70 ) {
			return sprintf(
				/* translators: %d: unpaid count */
				__( 'Payment completion is below average. %d approved vendors have not paid yet.', 'lnkup-marketplace' ),
				max( 0, $stages['approved_vendors'] - $stages['payment_completed'] )
			);
		}
		return '';
	}
}
