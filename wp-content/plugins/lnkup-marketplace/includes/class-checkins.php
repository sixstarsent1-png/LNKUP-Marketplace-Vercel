<?php
/**
 * Vendor check-in and on-site status transitions.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Checkins {

	/**
	 * Supported operational actions.
	 *
	 * @return array<string, string>
	 */
	public static function actions() {
		return array(
			'checked_in'     => __( 'Checked In', 'lnkup-marketplace' ),
			'setup_started'  => __( 'Setup Started', 'lnkup-marketplace' ),
			'setup_complete' => __( 'Setup Complete', 'lnkup-marketplace' ),
			'checked_out'    => __( 'Checked Out', 'lnkup-marketplace' ),
			'late_arrival'   => __( 'Late Arrival', 'lnkup-marketplace' ),
			'no_show'        => __( 'No Show', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Staff-facing status labels.
	 *
	 * @param string $ops    Stored ops status.
	 * @param string $reason Eligibility reason.
	 */
	public static function roster_label( $ops, $reason = '' ) {
		$ops    = sanitize_key( (string) $ops );
		$reason = sanitize_key( (string) $reason );
		if ( 'checked_out' === $ops ) {
			return __( 'Checked out', 'lnkup-marketplace' );
		}
		if ( 'setting_up' === $ops ) {
			return __( 'Setting up', 'lnkup-marketplace' );
		}
		if ( 'live_ready' === $ops ) {
			return __( 'Ready', 'lnkup-marketplace' );
		}
		if ( in_array( $ops, array( 'checked_in', 'late' ), true ) ) {
			return __( 'Checked in', 'lnkup-marketplace' );
		}
		if ( 'payment_pending' === $reason ) {
			return __( 'Payment pending', 'lnkup-marketplace' );
		}
		if ( 'documents' === $reason ) {
			return __( 'Documents need attention', 'lnkup-marketplace' );
		}
		if ( 'invalid_booking' === $reason ) {
			return __( 'Not eligible', 'lnkup-marketplace' );
		}
		if ( 'event_completed' === $reason ) {
			return __( 'Checked out', 'lnkup-marketplace' );
		}
		if ( 'ready' === $reason ) {
			return __( 'Ready to check in', 'lnkup-marketplace' );
		}
		return __( 'Ready to check in', 'lnkup-marketplace' );
	}

	/**
	 * Whether this user may run event-day actions for one event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $user_id  User ID.
	 */
	public static function actor_can_operate( $event_id, $user_id = 0 ) {
		$event_id = absint( $event_id );
		$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $event_id || ! $user_id ) {
			return false;
		}
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return true;
		}
		return LNKup_Capabilities::staff_can( $event_id, 'check_in', $user_id );
	}

	/**
	 * Shared eligibility for every check-in entry point.
	 *
	 * @param int    $app_id            Application ID.
	 * @param string $action            Requested action.
	 * @param int    $user_id           Actor.
	 * @param int    $expected_event_id Event the actor claims to be managing.
	 * @return array<string, mixed>
	 */
	public static function evaluate( $app_id, $action = 'checked_in', $user_id = 0, $expected_event_id = 0 ) {
		$app_id            = absint( $app_id );
		$action            = sanitize_key( (string) $action );
		$user_id           = $user_id ? absint( $user_id ) : get_current_user_id();
		$expected_event_id = absint( $expected_event_id );
		$base              = array(
			'success'          => false,
			'eligible'         => false,
			'reason'           => 'not_found',
			'message'          => __( 'Application not found.', 'lnkup-marketplace' ),
			'application_id'   => $app_id,
			'event_id'         => 0,
			'ops_status'       => '',
			'checked_in_at'    => '',
			'actions'          => array(),
			'checked_in'       => false,
		);

		$app = $app_id ? get_post( $app_id ) : null;
		if ( ! $app || 'lnkup_application' !== $app->post_type ) {
			return $base;
		}

		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$event    = $event_id ? get_post( $event_id ) : null;
		if ( ! $event || 'lnkup_event' !== $event->post_type ) {
			return $base;
		}

		$base['event_id'] = $event_id;
		if ( $expected_event_id && $expected_event_id !== $event_id ) {
			return self::result( $base, 'wrong_event', __( 'That application belongs to a different event.', 'lnkup-marketplace' ) );
		}

		$opp_id = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		if ( $opp_id ) {
			$opp = get_post( $opp_id );
			if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type ) {
				return self::result( $base, 'not_found', __( 'Application not found.', 'lnkup-marketplace' ) );
			}
			$opp_event = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
			if ( $opp_event && $opp_event !== $event_id ) {
				return self::result( $base, 'wrong_event', __( 'That application belongs to a different event.', 'lnkup-marketplace' ) );
			}
		}

		$vendor_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$can_operate = self::actor_can_operate( $event_id, $user_id );
		if ( ! $can_operate ) {
			return self::result( $base, 'forbidden', __( 'You cannot manage this event.', 'lnkup-marketplace' ) );
		}
		if ( $vendor_id === $user_id && ! LNKup_Capabilities::user_has_manage_all( $user_id ) && ! LNKup_Capabilities::user_owns_post( $event_id, $user_id, '_lnkup_organizer_user_id' ) ) {
			return self::result( $base, 'forbidden', __( 'You cannot manage this event.', 'lnkup-marketplace' ) );
		}

		$ops            = class_exists( 'LNKup_Event_Operations' ) ? LNKup_Event_Operations::resolve_ops_status( $app_id ) : '';
		$checked_in_at  = (string) get_post_meta( $app_id, '_lnkup_checked_in_at', true );
		$base['ops_status']    = $ops;
		$base['checked_in_at'] = $checked_in_at;
		$base['checked_in']    = '' !== $checked_in_at;

		$status = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		if ( self::booking_is_invalid( $app_id, $status ) ) {
			return self::result( $base, 'invalid_booking', __( 'This booking is not eligible for event-day actions.', 'lnkup-marketplace' ) );
		}

		$stage = class_exists( 'LNKup_Event_Lifecycle' ) ? (string) LNKup_Event_Lifecycle::get_stage( $event_id ) : '';
		if ( 'archived' === $stage || 'checked_out' === $ops ) {
			$done = self::result( $base, 'event_completed', __( 'This event is closed for new event-day actions.', 'lnkup-marketplace' ) );
			$done['ops_status']    = $ops;
			$done['checked_in_at'] = $checked_in_at;
			$done['checked_in']    = '' !== $checked_in_at;
			return $done;
		}

		if ( 'checked_in' === $action && '' !== $checked_in_at ) {
			// Duplicate scans report the existing check-in, even when it was an audited override.
			$info                  = self::result( $base, 'already_checked_in', __( 'Already checked in.', 'lnkup-marketplace' ) );
			$info['success']       = true;
			$info['checked_in']    = true;
			$info['checked_in_at'] = $checked_in_at;
			$info['actions']       = self::allowed_actions( $ops, true );
			return $info;
		}

		if ( ! self::payment_allows_checkin( $app_id, $status ) ) {
			return self::result( $base, 'payment_pending', __( 'Payment pending.', 'lnkup-marketplace' ) );
		}

		if ( self::documents_need_attention( $app_id ) ) {
			return self::result( $base, 'documents', __( 'Documents need attention.', 'lnkup-marketplace' ) );
		}

		$allowed = self::allowed_actions( $ops, '' !== $checked_in_at, $app_id );
		$base['actions'] = $allowed;
		if ( '' === $action ) {
			$view = self::result( $base, 'ready', '' );
			$view['eligible'] = ! empty( $allowed );
			$view['actions']  = $allowed;
			$view['ops_label'] = self::roster_label( $ops, '' !== $checked_in_at ? '' : 'ready' );
			return $view;
		}
		if ( 'checked_in' === $action && '' !== $checked_in_at ) {
			$info = self::result( $base, 'already_checked_in', __( 'Already checked in.', 'lnkup-marketplace' ) );
			$info['success']       = true;
			$info['checked_in']    = true;
			$info['checked_in_at'] = $checked_in_at;
			$info['actions']       = $allowed;
			return $info;
		}
		if ( $action && ! in_array( $action, $allowed, true ) ) {
			$conflict = self::result( $base, 'conflict', __( 'That action is not available from the current status.', 'lnkup-marketplace' ) );
			$conflict['actions'] = $allowed;
			return $conflict;
		}

		$ready = self::result( $base, 'ready', __( 'Ready to check in.', 'lnkup-marketplace' ) );
		$ready['eligible'] = true;
		$ready['actions']  = $allowed;
		return $ready;
	}

	/**
	 * Record a check-in action once.
	 *
	 * @param int    $app_id            Application ID.
	 * @param string $action            Action slug.
	 * @param string $method            manual|qr|bulk.
	 * @param int    $expected_event_id Event the caller is operating.
	 * @return array<string, mixed>
	 */
	public static function record( $app_id, $action, $method = 'manual', $expected_event_id = 0 ) {
		$app_id = absint( $app_id );
		$action = sanitize_key( (string) $action );
		$method = sanitize_key( (string) $method );
		if ( ! in_array( $method, array( 'manual', 'qr', 'bulk' ), true ) ) {
			$method = 'manual';
		}
		if ( ! isset( self::actions()[ $action ] ) ) {
			return array(
				'success'        => false,
				'eligible'       => false,
				'reason'         => 'conflict',
				'message'        => __( 'Invalid check-in action.', 'lnkup-marketplace' ),
				'application_id' => $app_id,
				'event_id'       => 0,
				'ops_status'     => '',
				'checked_in_at'  => '',
				'actions'        => array(),
				'checked_in'     => false,
			);
		}

		global $wpdb;
		$lock = 'lnkup_checkin_' . $app_id;
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
		if ( '1' !== (string) $got ) {
			return array(
				'success'        => false,
				'eligible'       => false,
				'reason'         => 'conflict',
				'message'        => __( 'Another update is in progress. Try again.', 'lnkup-marketplace' ),
				'application_id' => $app_id,
				'event_id'       => (int) get_post_meta( $app_id, '_lnkup_event_id', true ),
				'ops_status'     => '',
				'checked_in_at'  => (string) get_post_meta( $app_id, '_lnkup_checked_in_at', true ),
				'actions'        => array(),
				'checked_in'     => (bool) get_post_meta( $app_id, '_lnkup_checked_in_at', true ),
			);
		}

		try {
			$state = self::evaluate( $app_id, $action, get_current_user_id(), $expected_event_id );
			if ( empty( $state['eligible'] ) ) {
				return $state;
			}

			$inserted = $wpdb->insert(
				LNKup_Operations_Tables::checkins_table(),
				array(
					'event_id'       => (int) $state['event_id'],
					'application_id' => $app_id,
					'action'         => $action,
					'method'         => $method,
					'actor_user_id'  => get_current_user_id(),
					'created_at'     => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s' )
			);
			if ( ! $inserted ) {
				$state['success']  = false;
				$state['eligible'] = false;
				$state['reason']   = 'write_failed';
				$state['message']  = __( 'The check-in could not be saved.', 'lnkup-marketplace' );
				return $state;
			}

			$applied = self::apply_side_effects( $app_id, $action );
			if ( ! $applied ) {
				$state['success']  = false;
				$state['eligible'] = false;
				$state['reason']   = 'write_failed';
				$state['message']  = __( 'The check-in could not be saved.', 'lnkup-marketplace' );
				return $state;
			}

			$log_id = LNKup_Operations_Log::log(
				(int) $state['event_id'],
				$action,
				array(
					'application_id' => $app_id,
					'actor_user_id'  => get_current_user_id(),
					'payload'        => array(
						'method'        => $method,
						'action'        => $action,
						'business_name' => LNKup_Event_Operations::vendor_name_for_app( $app_id ),
					),
				)
			);

			$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
			if ( $profile_id && class_exists( 'LNKup_Vendor_Reputation' ) ) {
				LNKup_Vendor_Reputation::refresh( $profile_id );
			}
			if ( class_exists( 'LNKup_Vendor_Readiness' ) ) {
				LNKup_Vendor_Readiness::refresh( $app_id );
			}

			$fresh = self::evaluate( $app_id, '', get_current_user_id(), (int) $state['event_id'] );
			$fresh['success']  = true;
			$fresh['eligible'] = true;
			$fresh['reason']   = 'ready';
			$fresh['message']  = self::actions()[ $action ];
			$fresh['activity'] = $log_id && class_exists( 'LNKup_Operations_Log' ) ? self::activity_from_log( $log_id ) : null;
			return $fresh;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Owner/operations override of the payment and document gates, with a required reason.
	 * Hard rules still apply: wrong event, invalid booking, closed event, or an action the
	 * current status does not allow are never overridden.
	 *
	 * @param int    $app_id            Application ID.
	 * @param string $action            Check-in action slug.
	 * @param string $reason            Why the gate is being bypassed (audited).
	 * @param int    $expected_event_id Event being operated.
	 * @return array<string, mixed>
	 */
	public static function record_override( $app_id, $action, $reason, $expected_event_id ) {
		$app_id = absint( $app_id );
		$action = sanitize_key( (string) $action );
		$reason = trim( sanitize_textarea_field( (string) $reason ) );
		$state  = self::evaluate( $app_id, '', get_current_user_id(), $expected_event_id );
		if ( mb_strlen( $reason ) < 5 ) {
			return self::result( $state, 'reason_required', __( 'Add a reason (at least 5 characters) for the override.', 'lnkup-marketplace' ) );
		}
		if ( ! isset( self::actions()[ $action ] ) || in_array( (string) $state['reason'], array( 'not_found', 'wrong_event', 'forbidden', 'invalid_booking', 'event_completed' ), true ) ) {
			return $state;
		}
		if ( 'checked_in' === $action && ! empty( $state['checked_in'] ) ) {
			return self::record( $app_id, $action, 'manual', $expected_event_id );
		}
		$ops     = (string) $state['ops_status'];
		$allowed = self::allowed_actions( $ops, ! empty( $state['checked_in'] ), $app_id );
		if ( ! in_array( $action, $allowed, true ) ) {
			$conflict            = self::result( $state, 'conflict', __( 'That action is not available from the current status.', 'lnkup-marketplace' ) );
			$conflict['actions'] = $allowed;
			return $conflict;
		}

		global $wpdb;
		$lock = 'lnkup_checkin_' . $app_id;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return self::result( $state, 'conflict', __( 'Another update is in progress. Try again.', 'lnkup-marketplace' ) );
		}
		try {
			$inserted = $wpdb->insert(
				LNKup_Operations_Tables::checkins_table(),
				array(
					'event_id'       => (int) $state['event_id'],
					'application_id' => $app_id,
					'action'         => $action,
					'method'         => 'manual',
					'actor_user_id'  => get_current_user_id(),
					'created_at'     => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s' )
			);
			if ( ! $inserted || ! self::apply_side_effects( $app_id, $action ) ) {
				return self::result( $state, 'write_failed', __( 'The check-in could not be saved.', 'lnkup-marketplace' ) );
			}
			LNKup_Operations_Log::log(
				(int) $state['event_id'],
				'manual_override',
				array(
					'application_id' => $app_id,
					'actor_user_id'  => get_current_user_id(),
					'payload'        => array(
						'action'        => $action,
						'reason'        => $reason,
						'bypassed_gate' => (string) $state['reason'],
						'business_name' => LNKup_Event_Operations::vendor_name_for_app( $app_id ),
					),
				)
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		$fresh             = self::evaluate( $app_id, '', get_current_user_id(), (int) $state['event_id'] );
		$fresh['success']  = true;
		$fresh['override'] = true;
		$fresh['message']  = self::actions()[ $action ];
		return $fresh;
	}

	/**
	 * Scan a QR token and check the vendor in.
	 *
	 * @param string $token    QR pass token.
	 * @param int    $event_id Expected event ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function scan_qr( $token, $event_id = 0 ) {
		$token = sanitize_text_field( $token );
		if ( '' === $token ) {
			return new WP_Error( 'not_found', __( 'Invalid QR pass.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$indexed_app = class_exists( 'LNKup_Pass_Tokens' ) ? LNKup_Pass_Tokens::lookup_application( $token ) : 0;
		if ( $indexed_app ) {
			return self::record( $indexed_app, 'checked_in', 'qr', absint( $event_id ) );
		}
		$packets = get_posts(
			array(
				'post_type'      => 'lnkup_packet',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_qr_pass',
				'meta_value'     => $token,
			)
		);
		if ( ! $packets ) {
			return new WP_Error( 'not_found', __( 'Invalid QR pass.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$app_id = (int) get_post_meta( $packets[0]->ID, '_lnkup_application_id', true );
		return self::record( $app_id, 'checked_in', 'qr', absint( $event_id ) );
	}

	/**
	 * Map an eligibility array to an HTTP response.
	 *
	 * @param array<string, mixed> $result Evaluation or record result.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_response( array $result ) {
		if ( ! empty( $result['success'] ) ) {
			return rest_ensure_response( $result );
		}
		$status = 409;
		$reason = (string) ( $result['reason'] ?? 'conflict' );
		if ( 'not_found' === $reason ) {
			$status = 404;
		} elseif ( 'forbidden' === $reason ) {
			$status = 403;
		} elseif ( 'write_failed' === $reason ) {
			$status = 500;
		} elseif ( 'reason_required' === $reason ) {
			$status = 400;
		}
		return new WP_Error(
			$reason,
			(string) ( $result['message'] ?? __( 'Request failed.', 'lnkup-marketplace' ) ),
			array( 'status' => $status )
		);
	}

	/**
	 * History for an application.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function history( $app_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::checkins_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d ORDER BY created_at ASC", absint( $app_id ) ),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Actions that may follow the current on-site state.
	 *
	 * @param string $ops         Ops status.
	 * @param bool   $checked_in  Whether a check-in timestamp exists.
	 * @return string[]
	 */
	/**
	 * Whether the vendor's arrival window is over: their load-in window end, else the event
	 * start time. Unknown windows count as over so the action is never blocked by missing data.
	 *
	 * @param int $app_id Application ID.
	 */
	private static function arrival_window_passed( $app_id ) {
		$end = '';
		if ( class_exists( 'LNKup_Loadin_Schedule' ) ) {
			$window = LNKup_Loadin_Schedule::get_for_application( $app_id );
			$end    = is_array( $window ) ? (string) ( $window['end_at'] ?? '' ) : '';
		}
		if ( '' === $end ) {
			$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
			$date     = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
			$start    = (string) get_post_meta( $event_id, '_lnkup_start_time', true );
			$end      = '' !== $date ? trim( $date . ' ' . ( '' !== $start ? $start : '00:00' ) ) : '';
		}
		$ts = '' !== $end ? strtotime( $end ) : false;
		return false === $ts || (int) current_time( 'timestamp' ) >= $ts;
	}

	private static function allowed_actions( $ops, $checked_in, $app_id = 0 ) {
		if ( 'checked_out' === $ops ) {
			return array();
		}
		if ( 'no_show' === $ops ) {
			return array( 'checked_in' );
		}
		if ( 'setting_up' === $ops ) {
			return array( 'setup_complete', 'checked_out' );
		}
		if ( 'live_ready' === $ops ) {
			return array( 'checked_out' );
		}
		if ( $checked_in || in_array( $ops, array( 'checked_in', 'late' ), true ) ) {
			return array( 'setup_started', 'setup_complete', 'checked_out' );
		}
		if ( $app_id && ! self::arrival_window_passed( $app_id ) ) {
			return array( 'checked_in', 'no_show' );
		}
		return array( 'checked_in', 'late_arrival', 'no_show' );
	}

	/**
	 * @param array<string, mixed> $base    Base payload.
	 * @param string               $reason  Reason code.
	 * @param string               $message User-facing explanation.
	 * @return array<string, mixed>
	 */
	private static function result( array $base, $reason, $message ) {
		$base['success']   = false;
		$base['reason']    = $reason;
		$base['message']   = $message;
		$base['ops_label'] = self::roster_label( (string) ( $base['ops_status'] ?? '' ), $reason );
		return $base;
	}

	/**
	 * Rejected, waitlisted, cancelled, and refunded bookings stay ineligible.
	 *
	 * @param int    $app_id Application ID.
	 * @param string $status Application status.
	 */
	private static function booking_is_invalid( $app_id, $status ) {
		if ( in_array( $status, array( 'rejected', 'waitlisted', 'cancelled', 'canceled', 'refunded' ), true ) ) {
			return true;
		}
		if ( ! class_exists( 'LNKup_Payments' ) ) {
			return false;
		}
		$payment = LNKup_Payments::get_for_application( $app_id );
		if ( ! $payment ) {
			return false;
		}
		$pay_status = (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true );
		if ( in_array( $pay_status, array( 'refunded', 'cancelled', 'canceled', 'failed', 'disputed', 'chargeback_lost' ), true ) ) {
			return true;
		}
		// Partial refunds (e.g. goodwill credits) keep check-in unless the organizer opted to block them.
		if ( 'partially_refunded' === $pay_status ) {
			$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
			return $event_id && (bool) get_post_meta( $event_id, '_lnkup_block_checkin_partial_refund', true );
		}
		return false;
	}

	/**
	 * Payment gate. A missing opportunity is not treated as free.
	 *
	 * @param int    $app_id Application ID.
	 * @param string $status Application status.
	 */
	private static function payment_allows_checkin( $app_id, $status ) {
		if ( ! class_exists( 'LNKup_Vendor_Readiness' ) ) {
			return false;
		}
		return LNKup_Vendor_Readiness::application_payment_satisfied( $app_id, $status );
	}

	/**
	 * Existing required documents for this opportunity.
	 *
	 * @param int $app_id Application ID.
	 */
	private static function documents_need_attention( $app_id ) {
		if ( ! class_exists( 'LNKup_Verification' ) ) {
			return false;
		}
		$doc_ctx  = LNKup_Verification::get_application_document_context( $app_id );
		$doc_code = (string) ( $doc_ctx['code'] ?? '' );
		return in_array( $doc_code, array( 'pending', 'missing' ), true );
	}

	/**
	 * Apply meta for a saved action. Does not change payment or application status.
	 *
	 * @param int    $app_id Application ID.
	 * @param string $action Action slug.
	 */
	private static function apply_side_effects( $app_id, $action ) {
		$ops = '';
		switch ( $action ) {
			case 'checked_in':
				$stamp = current_time( 'mysql' );
				update_post_meta( $app_id, '_lnkup_checked_in_at', $stamp );
				if ( (string) get_post_meta( $app_id, '_lnkup_checked_in_at', true ) !== $stamp ) {
					return false;
				}
				delete_post_meta( $app_id, '_lnkup_no_show_at' );
				$ops = 'checked_in';
				break;
			case 'setup_started':
				$ops = 'setting_up';
				break;
			case 'setup_complete':
				$ops = 'live_ready';
				break;
			case 'checked_out':
				$stamp = current_time( 'mysql' );
				update_post_meta( $app_id, '_lnkup_event_completed_at', $stamp );
				if ( (string) get_post_meta( $app_id, '_lnkup_event_completed_at', true ) !== $stamp ) {
					return false;
				}
				$ops = 'checked_out';
				break;
			case 'late_arrival':
				$stamp = current_time( 'mysql' );
				if ( ! get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
					update_post_meta( $app_id, '_lnkup_checked_in_at', $stamp );
					if ( (string) get_post_meta( $app_id, '_lnkup_checked_in_at', true ) !== $stamp ) {
						return false;
					}
				}
				update_post_meta( $app_id, '_lnkup_late_arrival_at', $stamp );
				$ops = 'late';
				break;
			case 'no_show':
				$stamp = current_time( 'mysql' );
				update_post_meta( $app_id, '_lnkup_no_show_at', $stamp );
				if ( (string) get_post_meta( $app_id, '_lnkup_no_show_at', true ) !== $stamp ) {
					return false;
				}
				$ops = 'no_show';
				break;
			default:
				return false;
		}
		if ( '' === $ops || ! class_exists( 'LNKup_Event_Operations' ) ) {
			return false;
		}
		$saved = update_post_meta( $app_id, '_lnkup_ops_status', $ops );
		if ( ! $saved && (string) get_post_meta( $app_id, '_lnkup_ops_status', true ) !== $ops ) {
			return false;
		}
		do_action( 'lnkup_checkin_recorded', (int) get_post_meta( $app_id, '_lnkup_event_id', true ), (int) $app_id, (string) $action );
		if ( 'checked_out' === $action ) {
			do_action( 'lnkup_application_event_completed', (int) $app_id, (int) get_post_meta( $app_id, '_lnkup_event_id', true ) );
		}
		return true;
	}

	/**
	 * @param int $log_id Log row ID.
	 * @return array<string, mixed>|null
	 */
	private static function activity_from_log( $log_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::log_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $log_id ) ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return LNKup_Operations_Log::format( $row );
	}
}
