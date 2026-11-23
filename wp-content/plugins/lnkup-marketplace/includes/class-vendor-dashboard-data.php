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
		$counts = self::request_bucket_counts( lnkup_get_applications_for_user( $user_id, 'vendor' ), $user_id );
		$action = (int) $counts['pay-now'];
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
			'info_requested'   => __( 'More info needed', 'lnkup-marketplace' ),
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
		if ( in_array( $status, array( 'payment_required', 'approved', 'info_requested' ), true ) ) {
			return 'action';
		}
		return 'active';
	}

	/**
	 * Payment owned by this vendor for an application.
	 *
	 * @param WP_Post|int $app     Application post or ID.
	 * @param int         $user_id Vendor user ID.
	 * @return WP_Post|null
	 */
	public static function application_payment_for_vendor( $app, $user_id = 0 ) {
		$app_id  = $app instanceof WP_Post ? (int) $app->ID : (int) $app;
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $app_id || ! class_exists( 'LNKup_Payments' ) ) {
			return null;
		}
		$payment = LNKup_Payments::get_for_application( $app_id );
		if ( ! $payment instanceof WP_Post ) {
			return null;
		}
		$owner = (int) get_post_meta( $payment->ID, '_lnkup_vendor_user_id', true );
		if ( $owner && $owner !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return null;
		}
		return $payment;
	}

	/**
	 * Approved or payment-required, with a pending payment the vendor owns.
	 * Rejected and cancelled applications are never payable.
	 *
	 * @param WP_Post|int $app     Application post or ID.
	 * @param int         $user_id Vendor user ID.
	 */
	public static function application_is_payable( $app, $user_id = 0 ) {
		$app_id = $app instanceof WP_Post ? (int) $app->ID : (int) $app;
		$status = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		if ( ! in_array( $status, array( 'approved', 'payment_required' ), true ) ) {
			return false;
		}
		$payment = self::application_payment_for_vendor( $app_id, $user_id );
		if ( ! $payment ) {
			return false;
		}
		return 'pending' === (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true );
	}

	/**
	 * Request filter bucket: in-review, pay-now, booked (confirmed, upcoming), completed, or applied (other).
	 *
	 * @param WP_Post|int $app     Application post or ID.
	 * @param int         $user_id Vendor user ID.
	 */
	public static function application_request_bucket( $app, $user_id = 0 ) {
		$app_id = $app instanceof WP_Post ? (int) $app->ID : (int) $app;
		$status = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		if ( in_array( $status, array( 'submitted', 'under_review', 'info_requested' ), true ) ) {
			return 'in-review';
		}
		if ( self::application_is_payable( $app_id, $user_id ) ) {
			return 'pay-now';
		}
		if ( 'completed' === $status ) {
			return 'completed';
		}
		if ( 'paid' === $status ) {
			return self::application_event_has_passed( $app_id ) ? 'completed' : 'booked';
		}
		return 'applied';
	}

	/**
	 * Whether the application's event date is before today (site time).
	 *
	 * @param int $app_id Application ID.
	 */
	public static function application_event_has_passed( $app_id ) {
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$date     = $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_date', true ) : '';
		return '' !== $date && $date < current_time( 'Y-m-d' );
	}

	/**
	 * Counts per request tab.
	 *
	 * @param array $applications Application posts.
	 * @param int   $user_id      Vendor user ID.
	 * @return array{all:int,in-review:int,pay-now:int,booked:int,completed:int}
	 */
	public static function request_bucket_counts( $applications, $user_id = 0 ) {
		$counts = array(
			'all'       => count( $applications ),
			'in-review' => 0,
			'pay-now'   => 0,
			'booked'    => 0,
			'completed' => 0,
		);
		foreach ( $applications as $app ) {
			$bucket = self::application_request_bucket( $app, $user_id );
			if ( isset( $counts[ $bucket ] ) ) {
				++$counts[ $bucket ];
			}
		}
		return $counts;
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
		$pending_count = 0;
		$outstanding   = 0.0;
		foreach ( $ctx['applications'] as $app ) {
			if ( ! self::application_is_payable( $app, $ctx['user_id'] ) ) {
				continue;
			}
			$payment = self::application_payment_for_vendor( $app, $ctx['user_id'] );
			if ( ! $payment ) {
				continue;
			}
			++$pending_count;
			$outstanding += (float) get_post_meta( $payment->ID, '_lnkup_total_amount', true );
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
			'outstanding_amount' => $outstanding,
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

		foreach ( $ctx['applications'] as $app ) {
			if ( 'info_requested' !== (string) get_post_meta( $app->ID, '_lnkup_application_status', true ) ) {
				continue;
			}
			$meta    = self::application_event_meta( $app );
			$items[] = array(
				'level'        => 'urgent',
				/* translators: %s: event title */
				'title'        => sprintf( __( 'Organizer needs more info — %s', 'lnkup-marketplace' ), $meta['event_title'] ),
				'meta'         => __( 'Reply so they can decide on your request.', 'lnkup-marketplace' ),
				'action_label' => __( 'Reply', 'lnkup-marketplace' ),
				'action_href'  => add_query_arg( 'app', (int) $app->ID, $apps_url ),
				'primary'      => true,
			);
		}

		foreach ( $ctx['applications'] as $app ) {
			if ( 'pay-now' !== self::application_request_bucket( $app, $ctx['user_id'] ) ) {
				continue;
			}
			$meta    = self::application_event_meta( $app );
			$payment = self::application_payment_for_vendor( $app, $ctx['user_id'] );
			$items[] = array(
				'level'        => 'urgent',
				'title'        => sprintf( __( 'Complete payment — %s', 'lnkup-marketplace' ), $meta['event_title'] ),
				'meta'         => $meta['date'] ? date_i18n( 'M j, Y', strtotime( $meta['date'] ) ) : '',
				'action_label' => __( 'Complete payment', 'lnkup-marketplace' ),
				'action_href'  => $pay_url,
				'primary'      => true,
				'payment_id'   => $payment ? $payment->ID : 0,
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

		$has_pay_items = (bool) array_filter(
			$items,
			static function ( $item ) {
				return ! empty( $item['payment_id'] );
			}
		);
		if ( $week['outstanding_count'] > 0 && ! $has_pay_items ) {
			$items[] = array(
				'level'        => 'urgent',
				'title'        => __( 'Outstanding payments', 'lnkup-marketplace' ),
				'meta'         => lnkup_currency( $week['outstanding_amount'] ),
				'action_label' => __( 'View payments', 'lnkup-marketplace' ),
				'action_href'  => $pay_url,
				'primary'      => false,
			);
		}

		if ( $week['next_application_id'] && $week['next_event_date'] && gmdate( 'Y-m-d', strtotime( $week['next_event_date'] ) ) === current_time( 'Y-m-d' ) ) {
			array_unshift(
				$items,
				array(
					'level'        => 'urgent',
					'title'        => sprintf( __( 'Event day — %s', 'lnkup-marketplace' ), $week['next_event_title'] ),
					'meta'         => __( 'Open your pass for check-in, arrival, and booth details.', 'lnkup-marketplace' ),
					'action_label' => __( 'Open event pass', 'lnkup-marketplace' ),
					'action_href'  => add_query_arg( array( 'view' => 'operations', 'app' => $week['next_application_id'], 'tab' => 'pass' ), $dashboard ),
					'primary'      => true,
				)
			);
		} elseif ( $week['next_application_id'] && $week['next_event_date'] ) {
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
	 * Booking timeline: Submitted → Review → Payment → Confirmed → Event day → Completed.
	 * Payment is omitted for free booths. Steps after the reached one are "pending",
	 * so the timeline never implies payment or attendance that has not happened.
	 *
	 * @param string $status     Application status.
	 * @param array  $args       Optional: is_free (bool), event_date (Y-m-d).
	 */
	public static function application_workflow_steps( $status, $args = array() ) {
		$is_free    = ! empty( $args['is_free'] );
		$event_date = (string) ( $args['event_date'] ?? '' );
		$today      = current_time( 'Y-m-d' );

		$steps = array(
			array( 'label' => __( 'Submitted', 'lnkup-marketplace' ), 'key' => 'applied' ),
			array( 'label' => __( 'Review', 'lnkup-marketplace' ), 'key' => 'review' ),
		);
		if ( ! $is_free ) {
			$steps[] = array( 'label' => __( 'Payment', 'lnkup-marketplace' ), 'key' => 'payment' );
		}
		$steps[] = array( 'label' => __( 'Confirmed', 'lnkup-marketplace' ), 'key' => 'booked' );
		$steps[] = array( 'label' => __( 'Event day', 'lnkup-marketplace' ), 'key' => 'event' );
		$steps[] = array( 'label' => __( 'Completed', 'lnkup-marketplace' ), 'key' => 'completed' );

		$reached      = 'applied';
		$reached_done = false;
		if ( in_array( $status, array( 'under_review', 'waitlisted', 'info_requested' ), true ) ) {
			$reached = 'review';
		} elseif ( in_array( $status, array( 'approved', 'payment_required' ), true ) ) {
			$reached = $is_free ? 'review' : 'payment';
		} elseif ( 'paid' === $status ) {
			$reached = 'booked';
			if ( '' !== $event_date && $event_date <= $today ) {
				$reached      = 'event';
				$reached_done = $event_date < $today;
			}
		} elseif ( 'completed' === $status ) {
			$reached      = 'completed';
			$reached_done = true;
		}

		$keys    = wp_list_pluck( $steps, 'key' );
		$current = (int) array_search( $reached, $keys, true );
		foreach ( $steps as $i => &$step ) {
			if ( $i < $current || ( $reached_done && $i === $current ) ) {
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
			'event_title'    => lnkup_plain_title( $event ? $event : $app ),
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
