<?php
/**
 * Vendor growth analytics and application funnel.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Analytics {

	public static function revenue_over_time( $user_id, $days = 90 ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as d, SUM(amount) as total FROM {$table} WHERE vendor_user_id = %d AND type = 'payment' AND status = 'paid' AND created_at >= %s GROUP BY DATE(created_at) ORDER BY d ASC",
				$user_id,
				$since . ' 00:00:00'
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return self::legacy_revenue_over_time( $user_id, $days );
		}
		return array_map(
			function ( $r ) {
				return array( 'date' => $r['d'], 'amount' => round( (float) $r['total'], 2 ) );
			},
			$rows
		);
	}

	private static function legacy_revenue_over_time( $user_id, $days ) {
		$payments = lnkup_get_payments_for_user( $user_id, 'vendor' );
		$since    = strtotime( "-{$days} days" );
		$by_date  = array();
		foreach ( $payments as $payment ) {
			if ( 'paid' !== get_post_meta( $payment->ID, '_lnkup_payment_status', true ) ) {
				continue;
			}
			$paid_at = get_post_meta( $payment->ID, '_lnkup_paid_at', true );
			if ( ! $paid_at || strtotime( $paid_at ) < $since ) {
				continue;
			}
			$d = gmdate( 'Y-m-d', strtotime( $paid_at ) );
			$by_date[ $d ] = ( $by_date[ $d ] ?? 0 ) + (float) get_post_meta( $payment->ID, '_lnkup_vendor_spot_fee', true );
		}
		ksort( $by_date );
		$out = array();
		foreach ( $by_date as $d => $amt ) {
			$out[] = array( 'date' => $d, 'amount' => round( $amt, 2 ) );
		}
		return $out;
	}

	public static function revenue_by_month( $user_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-%%m') as m, SUM(amount) as total FROM {$table} WHERE vendor_user_id = %d AND type = 'payment' AND status = 'paid' GROUP BY m ORDER BY m ASC LIMIT 24",
				$user_id
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return self::legacy_revenue_by_month( $user_id );
		}
		return array_map(
			function ( $r ) {
				return array( 'month' => $r['m'], 'amount' => round( (float) $r['total'], 2 ) );
			},
			$rows
		);
	}

	private static function legacy_revenue_by_month( $user_id ) {
		$payments = lnkup_get_payments_for_user( $user_id, 'vendor' );
		$by_month = array();
		foreach ( $payments as $payment ) {
			if ( 'paid' !== get_post_meta( $payment->ID, '_lnkup_payment_status', true ) ) {
				continue;
			}
			$paid_at = get_post_meta( $payment->ID, '_lnkup_paid_at', true );
			if ( ! $paid_at ) {
				continue;
			}
			$m = gmdate( 'Y-m', strtotime( $paid_at ) );
			$by_month[ $m ] = ( $by_month[ $m ] ?? 0 ) + (float) get_post_meta( $payment->ID, '_lnkup_vendor_spot_fee', true );
		}
		ksort( $by_month );
		$out = array();
		foreach ( $by_month as $m => $amt ) {
			$out[] = array( 'month' => $m, 'amount' => round( $amt, 2 ) );
		}
		return $out;
	}

	public static function revenue_by_dimension( $user_id, $dimension ) {
		$applications = lnkup_get_applications_for_user( $user_id, 'vendor' );
		$buckets      = array();
		foreach ( $applications as $app ) {
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( ! in_array( $status, array( 'paid', 'completed' ), true ) ) {
				continue;
			}
			$meta   = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
			$fee    = (float) $meta['fee'];
			$key    = '';
			switch ( $dimension ) {
				case 'event':
					$key = $meta['event_title'] ?: __( 'Unknown Event', 'lnkup-marketplace' );
					break;
				case 'city':
					$key = trim( $meta['city'] . ( $meta['state'] ? ', ' . $meta['state'] : '' ) ) ?: __( 'Unknown', 'lnkup-marketplace' );
					break;
				case 'organizer':
					$oid  = (int) get_post_meta( $app->ID, '_lnkup_organizer_user_id', true );
					$user = $oid ? get_userdata( $oid ) : null;
					$key  = $user ? ( $user->display_name ?: __( 'Organizer', 'lnkup-marketplace' ) ) : __( 'Unknown', 'lnkup-marketplace' );
					break;
				case 'category':
					$event_id = (int) $meta['event_id'];
					$terms    = $event_id ? wp_get_post_terms( $event_id, 'lnkup_event_type', array( 'fields' => 'names' ) ) : array();
					if ( is_wp_error( $terms ) ) {
						$terms = array();
					}
					$key      = ! empty( $terms ) ? $terms[0] : __( 'Uncategorized', 'lnkup-marketplace' );
					break;
				case 'season':
					$key = $meta['date'] ? self::season_from_date( $meta['date'] ) : __( 'Unknown', 'lnkup-marketplace' );
					break;
				default:
					$key = __( 'Other', 'lnkup-marketplace' );
			}
			$buckets[ $key ] = ( $buckets[ $key ] ?? 0 ) + $fee;
		}
		arsort( $buckets );
		$out = array();
		foreach ( $buckets as $label => $amount ) {
			$out[] = array( 'label' => $label, 'amount' => round( $amount, 2 ) );
		}
		return $out;
	}

	private static function season_from_date( $date ) {
		$m = (int) gmdate( 'n', strtotime( $date ) );
		if ( $m >= 3 && $m <= 5 ) {
			return __( 'Spring', 'lnkup-marketplace' );
		}
		if ( $m >= 6 && $m <= 8 ) {
			return __( 'Summer', 'lnkup-marketplace' );
		}
		if ( $m >= 9 && $m <= 11 ) {
			return __( 'Fall', 'lnkup-marketplace' );
		}
		return __( 'Winter', 'lnkup-marketplace' );
	}

	public static function revenue_summary( $user_id ) {
		$by_event = self::revenue_by_dimension( $user_id, 'event' );
		$amounts  = wp_list_pluck( $by_event, 'amount' );
		$total    = array_sum( $amounts );
		$count    = count( $amounts );
		return array(
			'average_per_event' => $count ? round( $total / $count, 2 ) : 0,
			'highest'           => $amounts ? max( $amounts ) : 0,
			'lowest'            => $amounts ? min( $amounts ) : 0,
			'growth_pct'        => LNKup_Growth_Snapshots::growth_rate( $user_id ),
		);
	}

	public static function vendor_funnel( $user_id ) {
		$applications = lnkup_get_applications_for_user( $user_id, 'vendor' );
		$funnel       = array(
			'submitted'  => 0,
			'approved'   => 0,
			'paid'       => 0,
			'completed'  => 0,
			'rejected'   => 0,
			'waitlisted' => 0,
			'cancelled'  => 0,
		);
		$approval_times = array();
		foreach ( $applications as $app ) {
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( in_array( $status, array( 'submitted', 'under_review' ), true ) ) {
				$funnel['submitted']++;
			} elseif ( in_array( $status, array( 'approved', 'payment_required' ), true ) ) {
				$funnel['approved']++;
			} elseif ( 'paid' === $status ) {
				$funnel['paid']++;
			} elseif ( 'completed' === $status ) {
				$funnel['completed']++;
			} elseif ( 'rejected' === $status ) {
				$funnel['rejected']++;
			} elseif ( 'waitlisted' === $status ) {
				$funnel['waitlisted']++;
			} elseif ( 'cancelled' === $status ) {
				$funnel['cancelled']++;
			}
			$submitted = get_post_meta( $app->ID, '_lnkup_submitted_at', true );
			$decided   = get_post_meta( $app->ID, '_lnkup_decided_at', true );
			if ( $submitted && $decided ) {
				$approval_times[] = max( 0, strtotime( $decided ) - strtotime( $submitted ) );
			}
		}
		$total = count( $applications );
		return array(
			'funnel'              => $funnel,
			'approval_rate'       => $total ? round( ( ( $funnel['approved'] + $funnel['paid'] + $funnel['completed'] ) / $total ) * 100, 1 ) : 0,
			'rejection_rate'      => $total ? round( ( $funnel['rejected'] / $total ) * 100, 1 ) : 0,
			'waitlist_rate'       => $total ? round( ( $funnel['waitlisted'] / $total ) * 100, 1 ) : 0,
			'avg_approval_hours'  => $approval_times ? round( array_sum( $approval_times ) / count( $approval_times ) / 3600, 1 ) : 0,
			'best_organizer'      => LNKup_Growth_Relationships::top_organizer( $user_id ),
			'best_categories'     => array_slice( self::revenue_by_dimension( $user_id, 'category' ), 0, 3 ),
			'best_cities'         => array_slice( self::revenue_by_dimension( $user_id, 'city' ), 0, 3 ),
		);
	}

	public static function growth_trends( $user_id ) {
		return array(
			'revenue'           => LNKup_Growth_Snapshots::trend( $user_id, 'revenue_this_month', 90 ),
			'applications'      => LNKup_Growth_Snapshots::trend( $user_id, 'applications_submitted', 90 ),
			'approval_rate'     => LNKup_Growth_Snapshots::trend( $user_id, 'application_success_rate', 90 ),
			'vendor_score'      => LNKup_Growth_Snapshots::trend( $user_id, 'vendor_score', 90 ),
			'profile_strength'  => LNKup_Growth_Snapshots::trend( $user_id, 'profile_strength', 90 ),
			'cities_served'     => LNKup_Growth_Snapshots::trend( $user_id, 'cities_served', 90 ),
		);
	}
}
