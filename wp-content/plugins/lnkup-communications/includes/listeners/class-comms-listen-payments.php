<?php
/**
 * Payment, refund, dispute and payout messages.
 *
 * Sources are server-side only:
 * - lnkup_payment_completed        → fired by LNKup_Financial_Service::finalize_payment (verified webhook / zero-amount).
 * - lnkup_payment_status_changed   → canonical _lnkup_payment_status writes (webhook handlers, refunds, voids).
 * - lnkup_refund_synced            → charge.refunded webhook after sync.
 * - lnkup_chargeback_closed        → dispute closed webhook.
 * - lnkup_connect_account_status_changed / lnkup_organizer_payout_status_changed → Connect + payout ledger.
 * Nothing reads wallet balances or display totals. Every validity rule re-reads payment status at send time.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Listen_Payments {

	public static function init(): void {
		add_action( 'lnkup_payment_completed', array( __CLASS__, 'on_completed' ), 30 );
		add_action( 'lnkup_payment_status_changed', array( __CLASS__, 'on_status' ), 20, 3 );
		add_action( 'lnkup_refund_synced', array( __CLASS__, 'on_refund_synced' ), 20 );
		add_action( 'lnkup_chargeback_closed', array( __CLASS__, 'on_chargeback_closed' ), 20, 2 );
		add_action( 'lnkup_receipt_issued', array( __CLASS__, 'on_receipt' ), 20, 2 );
		add_action( 'lnkup_connect_account_status_changed', array( __CLASS__, 'on_connect_status' ), 20, 3 );
		add_action( 'lnkup_organizer_payout_status_changed', array( __CLASS__, 'on_payout_status' ), 20, 4 );
	}

	private static function parties( int $payment_id ): array {
		$app = (int) get_post_meta( $payment_id, '_lnkup_application_id', true );
		return array(
			$app,
			(int) get_post_meta( $app ?: $payment_id, '_lnkup_vendor_user_id', true ),
			(int) get_post_meta( $app ?: $payment_id, '_lnkup_organizer_user_id', true ),
		);
	}

	/**
	 * @param int $payment_id Payment (verified completion).
	 */
	public static function on_completed( $payment_id ): void {
		$payment_id = absint( $payment_id );
		list( $app, $vendor, $organizer ) = self::parties( $payment_id );
		if ( ! $app ) {
			return;
		}
		LNKup_Comms_Engine::cancel( 'app:' . $app . '|payment', array(), 'paid' );
		// Same dedupe keys as the application "paid" transition: only one of the two sends.
		LNKup_Comms_Engine::dispatch( 'v_booking_confirmed', $vendor, array( 'application_id' => $app ), array( 'source' => 'payment_completed' ) );
		LNKup_Comms_Engine::dispatch( 'o_vendor_paid', $organizer, array( 'application_id' => $app ), array( 'source' => 'payment_completed' ) );
	}

	/**
	 * @param int    $payment_id Payment.
	 * @param string $old        Previous status.
	 * @param string $new        New status.
	 */
	public static function on_status( $payment_id, $old, $new ): void {
		$payment_id = absint( $payment_id );
		list( $app, $vendor, $organizer ) = self::parties( $payment_id );
		if ( ! $app ) {
			return;
		}
		$ctx = array( 'application_id' => $app, 'payment_id' => $payment_id );
		switch ( (string) $new ) {
			case 'failed':
				if ( ! in_array( (string) $old, array( '', 'pending', 'processing', 'sent' ), true ) ) {
					return;
				}
				// Short delay: a voided (not declined) payment sets _lnkup_voided_at right after; validity then suppresses.
				$window = 'f' . gmdate( 'YmdH' );
				LNKup_Comms_Engine::dispatch( 'v_payment_failed', $vendor, $ctx, array( 'window' => $window, 'at' => time() + 120, 'source' => 'payment_failed' ) );
				LNKup_Comms_Engine::dispatch( 'o_vendor_payment_failed', $organizer, $ctx, array( 'window' => $window, 'at' => time() + 120, 'source' => 'payment_failed' ) );
				break;
			case 'refunded':
			case 'partially_refunded':
				self::refund_messages( $payment_id, (string) $new );
				break;
			case 'disputed':
				LNKup_Comms_Engine::dispatch( 'o_dispute_opened', $organizer, $ctx, array( 'source' => 'payment_disputed' ) );
				break;
			case 'paid':
				LNKup_Comms_Engine::cancel( 'app:' . $app . '|payment', array(), 'paid' );
				break;
		}
	}

	public static function on_refund_synced( $payment_id ): void {
		$payment_id = absint( $payment_id );
		$status     = LNKup_Comms_Context::payment_status( $payment_id );
		if ( in_array( $status, array( 'refunded', 'partially_refunded' ), true ) ) {
			self::refund_messages( $payment_id, $status );
		}
	}

	private static function refund_messages( int $payment_id, string $status ): void {
		list( $app, $vendor, $organizer ) = self::parties( $payment_id );
		$ctx    = array( 'application_id' => $app, 'payment_id' => $payment_id );
		$window = 'r_' . $status . '_' . substr( md5( (string) get_post_meta( $payment_id, '_lnkup_refund_amount', true ) ), 0, 8 );
		LNKup_Comms_Engine::dispatch( 'v_refund_completed', $vendor, $ctx, array( 'window' => $window, 'source' => 'refund' ) );
		LNKup_Comms_Engine::dispatch( 'o_refund_completed', $organizer, $ctx, array( 'window' => $window, 'source' => 'refund' ) );
	}

	/**
	 * @param int    $payment_id Payment.
	 * @param string $outcome    won|lost.
	 */
	public static function on_chargeback_closed( $payment_id, $outcome ): void {
		$payment_id = absint( $payment_id );
		list( $app, , $organizer ) = self::parties( $payment_id );
		$labels = array(
			'won'  => __( 'resolved in your favor', 'lnkup-communications' ),
			'lost' => __( 'resolved in the cardholder’s favor', 'lnkup-communications' ),
		);
		LNKup_Comms_Engine::dispatch(
			'o_chargeback_closed',
			$organizer,
			array( 'application_id' => $app, 'payment_id' => $payment_id, 'outcome_label' => $labels[ (string) $outcome ] ?? '' ),
			array( 'window' => sanitize_key( (string) $outcome ), 'source' => 'chargeback_closed' )
		);
	}

	public static function on_receipt( $receipt_id, $payment_id ): void {
		$payment_id = absint( $payment_id );
		list( $app, $vendor ) = self::parties( $payment_id );
		if ( $app ) {
			LNKup_Comms_Engine::dispatch( 'v_receipt_available', $vendor, array( 'application_id' => $app, 'payment_id' => $payment_id ), array( 'window' => 'rc' . absint( $receipt_id ), 'source' => 'receipt' ) );
		}
	}

	/**
	 * Payout setup became active, or stopped being active. Behind fin_payout_notifications.
	 */
	public static function on_connect_status( $user_id, $old, $new ): void {
		$user_id   = absint( $user_id );
		$was_ready = false !== strpos( (string) $old, ':ready' );
		$is_ready  = false !== strpos( (string) $new, ':ready' );
		if ( $is_ready && ! $was_ready ) {
			LNKup_Comms_Engine::dispatch( 'o_payout_setup', $user_id, array( 'organizer_user_id' => $user_id ), array( 'variant' => 'ready', 'window' => 'ready_' . gmdate( 'Ymd' ), 'source' => 'connect' ) );
		} elseif ( ! $is_ready && ( $was_ready || in_array( strtok( (string) $new, ':' ), array( 'rejected', 'disabled' ), true ) ) ) {
			LNKup_Comms_Engine::dispatch( 'o_payout_setup', $user_id, array( 'organizer_user_id' => $user_id ), array( 'variant' => 'action', 'window' => 'action_' . gmdate( 'Ymd' ), 'source' => 'connect' ) );
		}
	}

	/**
	 * Verified payout ledger transitions. Behind fin_payout_notifications.
	 */
	public static function on_payout_status( $payout_id, $old, $new, $user_id ): void {
		unset( $old );
		$new = (string) $new;
		if ( ! in_array( $new, array( 'paid', 'failed' ), true ) ) {
			return;
		}
		LNKup_Comms_Engine::dispatch( 'o_payout_status', absint( $user_id ), array( 'organizer_user_id' => absint( $user_id ) ), array( 'variant' => $new, 'window' => 'p' . absint( $payout_id ) . '_' . $new, 'source' => 'payout' ) );
	}
}
