<?php
/**
 * Vendor dashboard data helpers.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Dashboard_Data {

	/**
	 * Core document types for compliance scoring.
	 */
	public static function core_document_types() {
		return array(
			'certificate_of_insurance',
			'health_permit',
			'business_license',
			'w9',
			'sales_tax_certificate',
		);
	}

	/**
	 * Load vendor context for dashboard views.
	 *
	 * @param int $user_id User ID.
	 */
	public static function context( $user_id = 0 ) {
		$user_id      = $user_id ? $user_id : get_current_user_id();
		$profile_id   = lnkup_get_vendor_profile_id( $user_id );
		$applications = lnkup_get_applications_for_user( $user_id, 'vendor' );
		$payments     = lnkup_get_payments_for_user( $user_id, 'vendor' );
		$documents    = LNKup_Documents::get_for_vendor( $user_id );
		$compliance   = LNKup_Documents::compliance_summary( $user_id );

		return array(
			'user_id'      => $user_id,
			'profile_id'   => $profile_id,
			'profile'      => $profile_id ? get_post( $profile_id ) : null,
			'business_name'=> $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_business_name', true ) : '',
			'city'         => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_city', true ) : '',
			'state'        => $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_state', true ) : '',
			'applications' => $applications,
			'payments'     => $payments,
			'documents'    => $documents,
			'compliance'   => $compliance,
		);
	}

	/**
	 * Nav badge counts for sidebar.
	 *
	 * @param int $user_id User ID.
	 */
	public static function nav_badges( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$action  = 0;
		foreach ( lnkup_get_applications_for_user( $user_id, 'vendor' ) as $app ) {
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( in_array( $status, array( 'payment_required', 'approved' ), true ) ) {
				$action++;
			}
		}
		$compliance = LNKup_Documents::compliance_summary( $user_id );
		$docs       = (int) ( $compliance['attention_count'] ?? 0 );

		return array(
			'applications' => $action,
			'documents'    => $docs,
		);
	}

	/**
	 * Human-readable application status label.
	 *
	 * @param string $status Status slug.
	 */
	public static function application_status_label( $status ) {
		$labels = array(
			'submitted'        => __( 'Submitted', 'lnkup-marketplace' ),
			'under_review'     => __( 'Under Review', 'lnkup-marketplace' ),
			'approved'         => __( 'Approved', 'lnkup-marketplace' ),
			'payment_required' => __( 'Payment Required', 'lnkup-marketplace' ),
			'paid'             => __( 'Paid', 'lnkup-marketplace' ),
			'waitlisted'       => __( 'Waitlisted', 'lnkup-marketplace' ),
			'rejected'         => __( 'Rejected', 'lnkup-marketplace' ),
			'completed'        => __( 'Completed', 'lnkup-marketplace' ),
			'cancelled'        => __( 'Cancelled', 'lnkup-marketplace' ),
		);
		return $labels[ $status ] ?? ucwords( str_replace( '_', ' ', (string) $status ) );
	}

	/**
	 * Application tab bucket.
	 *
	 * @param string $status Status slug.
	 */
	public static function application_tab( $status ) {
		if ( in_array( $status, array( 'completed', 'rejected', 'cancelled' ), true ) ) {
			return 'past';
		}
		if ( in_array( $status, array( 'payment_required', 'approved' ), true ) ) {
			return 'action';
		}
		return 'active';
	}

	/**
	 * Count active (non-terminal) applications.
	 *
	 * @param array $applications Application posts.
	 */
	public static function count_active_applications( $applications ) {
		return count(
			array_filter(
				$applications,
				function ( $a ) {
					$s = get_post_meta( $a->ID, '_lnkup_application_status', true );
					return ! in_array( $s, array( 'completed', 'rejected', 'cancelled' ), true );
				}
			)
		);
	}

	/**
	 * Count confirmed bookings (paid/completed).
	 *
	 * @param array $applications Application posts.
	 */
	public static function count_confirmed_bookings( $applications ) {
		return count(
			array_filter(
				$applications,
				function ( $a ) {
					$s = get_post_meta( $a->ID, '_lnkup_application_status', true );
					return in_array( $s, array( 'paid', 'completed' ), true );
				}
			)
		);
	}

	/**
	 * Sum earned vendor fees this calendar year.
	 *
	 * @param array $payments Payment posts.
	 */
	public static function earned_this_season( $payments ) {
		$year = (int) gmdate( 'Y' );
		$sum  = 0.0;
		foreach ( $payments as $payment ) {
			if ( 'paid' !== get_post_meta( $payment->ID, '_lnkup_payment_status', true ) ) {
				continue;
			}
			$paid_at = get_post_meta( $payment->ID, '_lnkup_paid_at', true );
			if ( $paid_at && (int) gmdate( 'Y', strtotime( $paid_at ) ) !== $year ) {
				continue;
			}
			$sum += (float) get_post_meta( $payment->ID, '_lnkup_vendor_spot_fee', true );
		}
		return $sum;
	}

	/**
	 * Applications needing vendor action.
	 *
	 * @param array $applications Application posts.
	 */
	public static function attention_applications( $applications ) {
		return array_filter(
			$applications,
			function ( $a ) {
				$s = get_post_meta( $a->ID, '_lnkup_application_status', true );
				return in_array( $s, array( 'payment_required', 'approved' ), true );
			}
		);
	}

	/**
	 * Payment totals for vendor.
	 *
	 * @param array $payments Payment posts.
	 */
	public static function payment_totals( $payments ) {
		$paid        = 0.0;
		$outstanding = 0.0;
		foreach ( $payments as $payment ) {
			$status = get_post_meta( $payment->ID, '_lnkup_payment_status', true );
			$amount = (float) get_post_meta( $payment->ID, '_lnkup_total_amount', true );
			if ( 'paid' === $status ) {
				$paid += $amount;
			} elseif ( 'pending' === $status ) {
				$outstanding += $amount;
			}
		}
		return array(
			'paid'        => $paid,
			'outstanding' => $outstanding,
		);
	}

	/**
	 * Paid/completed applications for event operations.
	 *
	 * @param array $applications Application posts.
	 */
	public static function confirmed_applications( $applications ) {
		return array_values(
			array_filter(
				$applications,
				function ( $a ) {
					$s = get_post_meta( $a->ID, '_lnkup_application_status', true );
					return in_array( $s, array( 'paid', 'completed' ), true );
				}
			)
		);
	}

	/**
	 * Applications eligible for event pass view (including locked).
	 *
	 * @param array $applications Application posts.
	 */
	public static function pass_viewable_applications( $applications ) {
		return array_values(
			array_filter(
				$applications,
				function ( $a ) {
					$s = get_post_meta( $a->ID, '_lnkup_application_status', true );
					return in_array( $s, array( 'payment_required', 'approved', 'paid', 'completed' ), true );
				}
			)
		);
	}

	/**
	 * Whether application is pass-only (locked, no full ops).
	 *
	 * @param int $app_id Application ID.
	 */
	public static function is_pass_only_application( $app_id ) {
		$status = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		return in_array( $status, array( 'payment_required', 'approved' ), true );
	}

	/**
	 * Applications submitted since Monday of the current week.
	 *
	 * @param array $applications Application posts.
	 */
	public static function applications_this_week( $applications ) {
		$week_start = strtotime( 'monday this week', current_time( 'timestamp' ) );
		$count      = 0;
		foreach ( $applications as $app ) {
			if ( strtotime( $app->post_date ) >= $week_start ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Weekly snapshot metrics for home strip.
	 *
	 * @param array $ctx Context from self::context().
	 */
	public static function week_snapshot( $ctx ) {
		$totals           = self::payment_totals( $ctx['payments'] );
		$pending_count    = 0;
		foreach ( $ctx['payments'] as $payment ) {
			if ( 'pending' === get_post_meta( $payment->ID, '_lnkup_payment_status', true ) ) {
				$pending_count++;
			}
		}
		$profile_strength = 0;
		if ( class_exists( 'LNKup_Growth_Profile_Strength' ) ) {
			$strength_payload = LNKup_Growth_Profile_Strength::score( $ctx['user_id'] );
			$profile_strength = (int) ( $strength_payload['score'] ?? 0 );
		}
		$ops           = class_exists( 'LNKup_Event_Operations' ) ? LNKup_Event_Operations::vendor_home( $ctx['user_id'] ) : array( 'has_event' => false );
		$confirmed     = self::confirmed_applications( $ctx['applications'] );
		$confirmed_ids = array_map( 'intval', wp_list_pluck( $confirmed, 'ID' ) );
		$app_id        = ! empty( $ops['application_id'] ) ? (int) $ops['application_id'] : 0;
		if ( $app_id && ! in_array( $app_id, $confirmed_ids, true ) ) {
			$app_id = 0;
		}
		return array(
			'next_event_date'    => $app_id && ! empty( $ops['event_date'] ) ? $ops['event_date'] : '',
			'next_event_title'   => $app_id && ! empty( $ops['event_title'] ) ? $ops['event_title'] : '',
			'next_application_id'=> $app_id,
			'readiness_percent'  => $app_id && ! empty( $ops['readiness_percent'] ) ? (int) $ops['readiness_percent'] : 0,
			'outstanding_amount' => $totals['outstanding'],
			'outstanding_count'  => $pending_count,
			'profile_strength'   => $profile_strength,
		);
	}

	/**
	 * Time-of-day greeting label.
	 */
	public static function greeting_label() {
		$hour = (int) current_time( 'G' );
		if ( $hour < 12 ) {
			return __( 'Good morning', 'lnkup-marketplace' );
		}
		if ( $hour < 17 ) {
			return __( 'Good afternoon', 'lnkup-marketplace' );
		}
		return __( 'Good evening', 'lnkup-marketplace' );
	}

	/**
	 * Unified today's priorities from existing context (no extra queries).
	 *
	 * @param array $ctx Context from self::context().
	 */
	public static function today_priorities( $ctx ) {
		$items      = array();
		$dashboard  = lnkup_page_url_helper( 'vendor-dashboard' );
		$docs_url   = add_query_arg( 'view', 'documents', $dashboard );
		$apps_url   = add_query_arg( 'view', 'applications', $dashboard );
		$pay_url    = add_query_arg( 'view', 'payments', $dashboard );
		$week       = self::week_snapshot( $ctx );

		foreach ( self::attention_applications( $ctx['applications'] ) as $app ) {
			$meta    = self::application_event_meta( $app );
			$status  = get_post_meta( $app->ID, '_lnkup_application_status', true );
			$payment = LNKup_Payments::get_for_application( $app->ID );
			$level   = 'payment_required' === $status ? 'urgent' : 'warn';
			$pay_pending = $payment && 'pending' === get_post_meta( $payment->ID, '_lnkup_payment_status', true );
			$items[] = array(
				'level'        => $level,
				'title'        => 'payment_required' === $status
					? sprintf( __( 'Payment due — %s', 'lnkup-marketplace' ), $meta['event_title'] )
					: sprintf( __( 'Approved — complete payment for %s', 'lnkup-marketplace' ), $meta['event_title'] ),
				'meta'         => $meta['date'] ? date_i18n( 'M j, Y', strtotime( $meta['date'] ) ) : '',
				'action_label' => $pay_pending ? __( 'Pay now', 'lnkup-marketplace' ) : __( 'View app', 'lnkup-marketplace' ),
				'action_href'  => $apps_url,
				'primary'      => true,
				'payment_id'   => $pay_pending ? $payment->ID : 0,
			);
		}

		if ( ! empty( $ctx['compliance']['attention_count'] ) ) {
			$items[] = array(
				'level'        => 'warn',
				'title'        => __( 'Documents need attention', 'lnkup-marketplace' ),
				'meta'         => sprintf( __( '%d items missing or expiring', 'lnkup-marketplace' ), (int) $ctx['compliance']['attention_count'] ),
				'action_label' => __( 'Review', 'lnkup-marketplace' ),
				'action_href'  => $docs_url,
				'primary'      => true,
			);
		}

		if ( $week['outstanding_count'] > 0 ) {
			$items[] = array(
				'level'        => 'urgent',
				'title'        => __( 'Outstanding payments', 'lnkup-marketplace' ),
				'meta'         => lnkup_currency( $week['outstanding_amount'] ),
				'action_label' => __( 'View payments', 'lnkup-marketplace' ),
				'action_href'  => $pay_url,
				'primary'      => false,
			);
		}

		if ( $week['next_application_id'] && $week['next_event_date'] ) {
			$load_in = strtotime( $week['next_event_date'] ) - current_time( 'timestamp' );
			if ( $load_in > 0 && $load_in < 14 * DAY_IN_SECONDS ) {
				$items[] = array(
					'level'        => 'info',
					'title'        => sprintf( __( 'Upcoming load-in — %s', 'lnkup-marketplace' ), $week['next_event_title'] ),
					'meta'         => date_i18n( 'l, M j', strtotime( $week['next_event_date'] ) ),
					'action_label' => __( 'Event ops', 'lnkup-marketplace' ),
					'action_href'  => add_query_arg( array( 'view' => 'operations', 'app' => $week['next_application_id'] ), $dashboard ),
					'primary'      => false,
				);
			}
		}

		return array_slice( $items, 0, 6 );
	}

	/**
	 * Workflow step states for an application.
	 *
	 * @param string $status Application status.
	 */
	public static function application_workflow_steps( $status ) {
		$steps = array(
			array( 'label' => __( 'Applied', 'lnkup-marketplace' ), 'key' => 'applied' ),
			array( 'label' => __( 'Review', 'lnkup-marketplace' ), 'key' => 'review' ),
			array( 'label' => __( 'Payment', 'lnkup-marketplace' ), 'key' => 'payment' ),
			array( 'label' => __( 'Booked', 'lnkup-marketplace' ), 'key' => 'booked' ),
		);
		$map = array(
			'submitted'        => 0,
			'under_review'     => 1,
			'waitlisted'       => 1,
			'approved'         => 2,
			'payment_required' => 2,
			'paid'             => 3,
			'completed'        => 3,
		);
		$current = $map[ $status ] ?? 0;
		foreach ( $steps as $i => &$step ) {
			if ( $i < $current ) {
				$step['state'] = 'done';
			} elseif ( $i === $current ) {
				$step['state'] = 'current';
			} else {
				$step['state'] = 'pending';
			}
		}
		unset( $step );
		return $steps;
	}

	/**
	 * Event meta for an application row.
	 *
	 * @param WP_Post $app Application post.
	 */
	public static function application_event_meta( $app ) {
		if ( ! $app || ! is_a( $app, 'WP_Post' ) ) {
			return array(
				'event_id'       => 0,
				'event_title'    => '',
				'city'           => '',
				'state'          => '',
				'date'           => '',
				'fee'            => 0,
				'venue'          => '',
				'venue_address'  => '',
				'start_time'     => '',
			);
		}
		$event_id = (int) get_post_meta( $app->ID, '_lnkup_event_id', true );
		$opp_id   = (int) get_post_meta( $app->ID, '_lnkup_opportunity_id', true );
		$event    = $event_id ? get_post( $event_id ) : null;
		$city     = $event_id ? (string) get_post_meta( $event_id, '_lnkup_city', true ) : '';
		$state    = $event_id ? (string) get_post_meta( $event_id, '_lnkup_state', true ) : '';
		$date     = $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_date', true ) : '';
		$fee      = $opp_id ? (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ) : 0;
		return array(
			'event_id'       => $event_id,
			'event_title'    => $event ? $event->post_title : $app->post_title,
			'city'           => $city,
			'state'          => $state,
			'date'           => $date,
			'fee'            => $fee,
			'venue'          => $event_id ? (string) get_post_meta( $event_id, '_lnkup_venue_name', true ) : '',
			'venue_address'  => $event_id ? (string) get_post_meta( $event_id, '_lnkup_venue_address', true ) : '',
			'start_time'     => $event_id ? (string) get_post_meta( $event_id, '_lnkup_start_time', true ) : '',
		);
	}
}
