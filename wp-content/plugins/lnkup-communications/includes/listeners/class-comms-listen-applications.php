<?php
/**
 * Application lifecycle → messages. Mapped to the real lnkup-marketplace statuses
 * (submitted, under_review, info_requested, approved, payment_required, paid,
 * waitlisted, rejected, completed, cancelled). No parallel status system.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Listen_Applications {

	public static function init(): void {
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'on_submitted' ), 20 );
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'on_status' ), 20, 3 );
		add_action( 'lnkup_application_withdrawn', array( __CLASS__, 'on_withdrawn' ), 20, 2 );
		add_action( 'lnkup_waitlist_spot_available', array( __CLASS__, 'on_spot_available' ), 20, 2 );
	}

	private static function people( int $app_id ): array {
		return array(
			(int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ),
			(int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true ),
			(int) get_post_meta( $app_id, '_lnkup_event_id', true ),
		);
	}

	/**
	 * @param int $app_id Application.
	 */
	public static function on_submitted( $app_id ): void {
		$app_id = absint( $app_id );
		list( $vendor, $organizer, $event ) = self::people( $app_id );
		if ( $vendor ) {
			LNKup_Comms_Engine::dispatch( 'v_application_submitted', $vendor, array( 'application_id' => $app_id ), array( 'source' => 'application_submitted' ) );
		}
		if ( ! $organizer ) {
			return;
		}
		$total = $event ? LNKup_Comms_Context::count_apps( array( '_lnkup_event_id' => $event ), array( 'submitted', 'under_review', 'info_requested', 'approved', 'payment_required', 'paid', 'waitlisted', 'rejected', 'completed', 'cancelled' ) ) : 0;
		if ( $total <= 1 ) {
			// The first application for an event is always immediate.
			LNKup_Comms_Engine::dispatch( 'o_application_received', $organizer, array( 'application_id' => $app_id ), array( 'source' => 'first_application' ) );
			return;
		}
		// Later applications are grouped per event into one message per burst window.
		$window = max( 5, LNKup_Comms_Settings::int( 'burst_window_minutes' ) ) * MINUTE_IN_SECONDS;
		$bucket = (int) ( floor( time() / $window ) * $window );
		LNKup_Comms_Engine::dispatch(
			'o_applications_grouped',
			$organizer,
			array( 'event_id' => $event ),
			array(
				'window'     => 'b' . $bucket,
				'at'         => $bucket + $window,
				'cancel_key' => 'event:' . $event . '|review',
				'source'     => 'application_burst',
			)
		);
	}

	/**
	 * @param int    $app_id Application.
	 * @param string $prev   Previous status.
	 * @param string $new    New status.
	 */
	public static function on_status( $app_id, $prev, $new ): void {
		$app_id = absint( $app_id );
		$prev   = (string) $prev;
		$new    = (string) $new;
		if ( $prev === $new ) {
			return;
		}
		list( $vendor, $organizer ) = self::people( $app_id );
		$ctx = array( 'application_id' => $app_id );

		// Stale prevention first: anything scheduled for a booking that just left the flow.
		if ( in_array( $new, array( 'rejected', 'cancelled' ), true ) ) {
			LNKup_Comms_Engine::cancel( 'app:' . $app_id . '|*', array(), 'application_' . $new );
		}
		if ( 'paid' === $new ) {
			LNKup_Comms_Engine::cancel( 'app:' . $app_id . '|payment', array(), 'paid' );
		}

		switch ( $new ) {
			case 'payment_required':
			case 'approved':
				if ( in_array( $prev, array( 'approved', 'payment_required' ), true ) ) {
					return;
				}
				$payment = class_exists( 'LNKup_Payments' ) ? LNKup_Payments::get_for_application( $app_id ) : null;
				$due     = LNKup_Comms_Context::payment_due( $app_id, $payment ? (int) $payment->ID : 0 );
				// A waitlist offer sends its own message with the offer deadline (v_waitlist_offer).
				if ( ! LNKup_Applications::approved_by_waitlist_offer( $app_id ) ) {
					LNKup_Comms_Engine::dispatch( 'v_application_approved', $vendor, $ctx, array( 'variant' => $due ? 'payment' : 'free', 'source' => 'application_approved' ) );
				}
				if ( $due ) {
					self::schedule_payment_reminders( $app_id, $vendor );
				}
				break;
			case 'rejected':
				LNKup_Comms_Engine::dispatch( 'v_application_rejected', $vendor, $ctx, array( 'source' => 'application_rejected' ) );
				break;
			case 'waitlisted':
				LNKup_Comms_Engine::dispatch( 'v_application_waitlisted', $vendor, $ctx, array( 'source' => 'application_waitlisted' ) );
				break;
			case 'info_requested':
				LNKup_Comms_Engine::dispatch( 'v_info_requested', $vendor, $ctx, array( 'window' => 'i' . gmdate( 'YmdHi' ), 'source' => 'info_requested' ) );
				break;
			case 'cancelled':
				if ( 'withdrawn' === (string) get_post_meta( $app_id, '_lnkup_cancellation_reason', true ) ) {
					return;
				}
				LNKup_Comms_Engine::dispatch( 'v_application_cancelled', $vendor, $ctx, array( 'source' => 'application_cancelled' ) );
				break;
			case 'paid':
				LNKup_Comms_Engine::dispatch( 'v_booking_confirmed', $vendor, $ctx, array( 'source' => 'application_paid' ) );
				LNKup_Comms_Engine::dispatch( 'o_vendor_paid', $organizer, $ctx, array( 'source' => 'application_paid' ) );
				// Check documents an hour after booking; validity skips it if nothing is missing.
				LNKup_Comms_Engine::dispatch( 'v_documents_needed', $vendor, $ctx, array( 'window' => 'after_paid', 'at' => time() + HOUR_IN_SECONDS, 'cancel_key' => 'app:' . $app_id . '|docs', 'source' => 'application_paid' ) );
				break;
		}

		if ( 'info_requested' === $prev && in_array( $new, array( 'submitted', 'under_review' ), true ) ) {
			LNKup_Comms_Engine::dispatch( 'o_info_response', $organizer, $ctx, array( 'window' => 'r' . gmdate( 'YmdHi' ), 'source' => 'info_response' ) );
		}
	}

	/**
	 * Reminders before the stored payment deadline (each only if still at least an hour away):
	 * 7 days (long deadlines only), 3 days, 24 hours, and a final 6-hour notice. Every message
	 * shows the actual deadline; send-time validity re-checks that payment is still due.
	 */
	public static function schedule_payment_reminders( int $app_id, int $vendor ): void {
		$due_raw = (string) get_post_meta( $app_id, '_lnkup_payment_due_at', true );
		$due     = '' !== $due_raw ? (int) strtotime( $due_raw . ' UTC' ) : 0;
		if ( $due <= time() ) {
			return;
		}
		$windows = array(
			'd7'  => array( 7 * DAY_IN_SECONDS, __( 'in 7 days', 'lnkup-communications' ) ),
			'd3'  => array( 3 * DAY_IN_SECONDS, __( 'in 3 days', 'lnkup-communications' ) ),
			'h24' => array( DAY_IN_SECONDS, __( 'tomorrow', 'lnkup-communications' ) ),
			'h6'  => array( 6 * HOUR_IN_SECONDS, __( 'in 6 hours', 'lnkup-communications' ) ),
		);
		foreach ( $windows as $key => $window ) {
			$at = $due - $window[0];
			// The 7-day reminder only makes sense when the deadline is more than 8 days out.
			if ( $at <= time() + HOUR_IN_SECONDS || ( 'd7' === $key && $due - time() <= 8 * DAY_IN_SECONDS ) ) {
				continue;
			}
			LNKup_Comms_Engine::dispatch(
				'v_payment_reminder',
				$vendor,
				array( 'application_id' => $app_id, 'window_label' => $window[1] ),
				array(
					'window'     => 'due_' . $key,
					'at'         => $at,
					'cancel_key' => 'app:' . $app_id . '|payment',
					'source'     => 'payment_deadline',
					// Final notice: high priority still sends during quiet hours.
					'priority'   => 'high',
				)
			);
		}
	}

	/**
	 * @param int    $app_id Application.
	 * @param string $prev   Status before withdrawal.
	 */
	public static function on_withdrawn( $app_id, $prev ): void {
		unset( $prev );
		$app_id = absint( $app_id );
		list( $vendor, $organizer ) = self::people( $app_id );
		LNKup_Comms_Engine::cancel( 'app:' . $app_id . '|*', array(), 'withdrawn' );
		LNKup_Comms_Engine::dispatch( 'v_application_withdrawn', $vendor, array( 'application_id' => $app_id ), array( 'source' => 'withdrawn' ) );
		LNKup_Comms_Engine::dispatch( 'o_application_withdrawn', $organizer, array( 'application_id' => $app_id ), array( 'source' => 'withdrawn' ) );
	}

	/**
	 * @param int $opp_id    Opportunity.
	 * @param int $remaining Spots remaining.
	 */
	public static function on_spot_available( $opp_id, $remaining ): void {
		$opp_id = absint( $opp_id );
		$waiting = LNKup_Comms_Context::count_apps( array( '_lnkup_opportunity_id' => $opp_id ), array( 'waitlisted' ) );
		if ( $waiting < 1 ) {
			return;
		}
		$ctx       = LNKup_Comms_Context::build( array( 'opportunity_id' => $opp_id ) );
		$organizer = (int) $ctx['organizer_user_id'];
		LNKup_Comms_Engine::dispatch( 'o_waitlist_spot', $organizer, array( 'opportunity_id' => $opp_id ), array( 'window' => gmdate( 'YmdH' ) . '_' . absint( $remaining ), 'source' => 'waitlist_spot' ) );
	}
}
