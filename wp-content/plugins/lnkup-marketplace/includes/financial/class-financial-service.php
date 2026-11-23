<?php
/**
 * Central financial service — single entry point for all payment operations.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Service {

	public static function init() {
		LNKup_Payment_Timeline::init();
		LNKup_Financial_Activity::init_hooks();
		LNKup_Stripe_Webhooks::init();
	}

	/**
	 * Get active payment provider.
	 */
	public static function provider() {
		return LNKup_Payment_Provider::get_instance();
	}

	/**
	 * Checkout amount after credits/installments/deposits.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function get_checkout_amount( $payment_id ) {
		LNKup_Payments::refresh_pending_fees( $payment_id );
		return self::stored_amount_due( $payment_id );
	}

	/**
	 * Amount due from stored payment meta, without recalculating fees.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function stored_amount_due( $payment_id ) {
		$total       = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
		$credit      = (float) get_post_meta( $payment_id, '_lnkup_credit_applied', true );
		$installment = (float) get_post_meta( $payment_id, '_lnkup_installment_amount', true );
		if ( $installment > 0 ) {
			return max( 0, round( $installment - $credit, 2 ) );
		}
		return max( 0, round( $total - $credit, 2 ) );
	}

	/**
	 * Create checkout (legacy redirect or embedded prepare).
	 *
	 * @param int   $payment_id Payment ID.
	 * @param array $args       mode: embedded|redirect.
	 */
	public static function create_checkout( $payment_id, $args = array() ) {
		if ( 'paid' !== (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) && ! LNKup_Payments::is_payable( $payment_id ) ) {
			return new WP_Error( 'payment_not_payable', __( 'This booking is no longer open for payment.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$checkout_event = (int) get_post_meta( $payment_id, '_lnkup_event_id', true );
		if ( $checkout_event && 'paid' !== (string) get_post_meta( $payment_id, '_lnkup_payment_status', true )
			&& class_exists( 'LNKup_Event_Authenticity' ) && ! LNKup_Event_Authenticity::is_bookable( $checkout_event ) ) {
			return new WP_Error( 'lnkup_listing_under_review', __( 'Payments for this event are paused while LNKup confirms who manages it. You have not been charged.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		LNKup_Payments::reopen_if_declined( $payment_id );
		LNKup_Payments::refresh_pending_fees( $payment_id );
		$mode = $args['mode'] ?? 'embedded';
		if ( 'redirect' === $mode ) {
			$zero = self::maybe_finalize_zero_amount( $payment_id, 'free_booth' );
			if ( is_wp_error( $zero ) ) {
				return $zero;
			}
			if ( null !== $zero ) {
				return array( 'mode' => 'no_payment_due', 'paid' => true, 'url' => '' );
			}
			return self::provider()->create_checkout_session( $payment_id, $args );
		}
		return LNKup_Checkout::prepare( $payment_id, $args );
	}

	/**
	 * @param int $payment_id Payment ID.
	 */
	public static function get_checkout_summary( $payment_id ) {
		return LNKup_Checkout::get_summary( $payment_id );
	}

	/**
	 * @param int   $payment_id Payment ID.
	 * @param array $args       Args.
	 */
	public static function prepare_checkout( $payment_id, $args = array() ) {
		return LNKup_Checkout::prepare( $payment_id, $args );
	}

	/**
	 * @param int   $payment_id Payment ID.
	 * @param float $amount     Credit amount.
	 */
	public static function apply_wallet_credits( $payment_id, $amount = 0 ) {
		return LNKup_Checkout::apply_credits( $payment_id, $amount );
	}

	/**
	 * Finalize payment after Stripe confirmation.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $intent_id  Stripe intent ID.
	 * @param array  $context    Extra context.
	 */
	/**
	 * Mark payment processing (ACH / delayed settlement) without unlocking packets.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $intent_id  Stripe intent ID.
	 * @param array  $context    Extra context.
	 */
	public static function mark_payment_processing( $payment_id, $intent_id = '', $context = array() ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id ) {
			return new WP_Error( 'invalid_payment', __( 'Invalid payment.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$status = get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( 'paid' === $status ) {
			return array( 'already_paid' => true );
		}

		if ( $intent_id ) {
			self::backfill_stripe_payment_meta( $payment_id, $intent_id );
		}

		LNKup_Payments::mark_processing( $payment_id, $intent_id );

		if ( class_exists( 'LNKup_Financial_Audit' ) ) {
			LNKup_Financial_Audit::log( 'payment_processing', 'payment', $payment_id, array_merge( $context, array(
				'intent_id' => (string) $intent_id,
			) ) );
		}

		return array( 'success' => true, 'status' => 'processing', 'payment_id' => $payment_id );
	}

	/**
	 * Run a callback while holding a MySQL named lock (serializes webhook, confirm, cron and sync).
	 *
	 * @param string   $name     Lock name (prefixed, max 64 chars).
	 * @param callable $callback Work to run.
	 * @param int      $timeout  Seconds to wait for the lock.
	 * @return mixed|WP_Error Callback result, or WP_Error when the lock is busy.
	 */
	public static function with_lock( $name, callable $callback, $timeout = 10 ) {
		global $wpdb;
		$key = substr( 'lnkup_' . sanitize_key( $name ), 0, 64 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$got = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, absint( $timeout ) ) );
		if ( 1 !== $got ) {
			return new WP_Error(
				'lnkup_busy',
				__( 'This payment is already being processed. Refresh in a moment.', 'lnkup-marketplace' ),
				array( 'status' => 409 )
			);
		}
		try {
			return $callback();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	/**
	 * Whether settle_treasury() has completed for a payment (marker or legacy ledger row).
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function is_settled( $payment_id ) {
		if ( '' !== (string) get_post_meta( $payment_id, '_lnkup_settled_at', true ) ) {
			return true;
		}
		return class_exists( 'LNKup_Organizer_Wallet' ) && LNKup_Organizer_Wallet::has_wallet_entry_for_payment( $payment_id );
	}

	/**
	 * Settle a paid payment that never reached the ledger (crash between mark_paid and settle).
	 *
	 * Caller must hold the payment lock.
	 *
	 * @param int $payment_id Payment post ID.
	 * @return bool True when settlement ran now.
	 */
	private static function ensure_settled( $payment_id ) {
		if ( 'paid' !== (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) || self::is_settled( $payment_id ) ) {
			return false;
		}
		if ( ! self::auto_recovery_allowed( $payment_id ) ) {
			if ( class_exists( 'LNKup_Financial_Audit' ) ) {
				LNKup_Financial_Audit::log( 'historical_settlement_skipped', 'payment', $payment_id, array( 'reason' => 'paid_before_auto_recovery_cutoff' ) );
			}
			return false;
		}
		self::settle_treasury( $payment_id );
		if ( class_exists( 'LNKup_Financial_Audit' ) ) {
			LNKup_Financial_Audit::log( 'settlement_recovered', 'payment', $payment_id, array() );
		}
		return true;
	}

	/**
	 * Settle one historical paid payment for an approved repair run (bypasses the auto-recovery cutoff).
	 *
	 * @param int   $payment_id Payment post ID.
	 * @param array $context    Repair context for the audit log (run id, actor).
	 * @return bool|WP_Error True when settlement ran, false when nothing to do.
	 */
	public static function repair_settle( $payment_id, $context = array() ) {
		$payment_id = absint( $payment_id );
		return self::with_lock(
			'pay_' . $payment_id,
			static function () use ( $payment_id, $context ) {
				if ( 'paid' !== (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) || self::is_settled( $payment_id ) ) {
					return false;
				}
				self::settle_treasury( $payment_id );
				LNKup_Financial_Audit::log( 'repair_settlement_applied', 'payment', $payment_id, $context );
				return true;
			},
			15
		);
	}

	/**
	 * Automatic ledger recovery only touches payments paid after the reconciliation release.
	 *
	 * Older gaps are repaired through the approval-gated LNKup_Financial_Repair tool so
	 * historical balances never change silently.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function auto_recovery_allowed( $payment_id ) {
		$cutoff = (string) get_option( 'lnkup_fin_auto_recovery_since', '' );
		if ( '' === $cutoff ) {
			return false;
		}
		$paid_at = (string) get_post_meta( $payment_id, '_lnkup_paid_at', true );
		if ( '' === $paid_at ) {
			$paid_at = (string) get_post_field( 'post_date', $payment_id );
		}
		return $paid_at >= $cutoff;
	}

	/**
	 * Whether a PaymentIntent is a different charge from the one recorded on the payment.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $intent_id  PaymentIntent ID.
	 */
	private static function is_other_intent( $payment_id, $intent_id ) {
		$intent_id = (string) $intent_id;
		if ( 0 !== strpos( $intent_id, 'pi_' ) ) {
			return false;
		}
		$stored = (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
		if ( '' === $stored || $stored === $intent_id ) {
			return false;
		}
		$partials = (array) get_post_meta( $payment_id, '_lnkup_partial_charges', true );
		return ! isset( $partials[ $intent_id ] );
	}

	/**
	 * Record a succeeded charge that must not be applied (duplicate or on a closed payment).
	 * Surfaces in reconciliation and the repair tool for an admin-approved refund.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $intent_id  PaymentIntent ID.
	 * @param string $reason     Reason code.
	 * @param array  $context    Context.
	 */
	private static function record_extra_charge( $payment_id, $intent_id, $reason, $context = array() ) {
		$list = get_post_meta( $payment_id, '_lnkup_extra_charges', true );
		$list = is_array( $list ) ? $list : array();
		if ( isset( $list[ $intent_id ] ) ) {
			return;
		}
		$list[ $intent_id ] = array(
			'reason'      => sanitize_key( $reason ),
			'recorded_at' => current_time( 'mysql' ),
			'source'      => sanitize_key( (string) ( $context['source'] ?? '' ) ),
		);
		update_post_meta( $payment_id, '_lnkup_extra_charges', $list );
		LNKup_Financial_Audit::log( 'extra_succeeded_charge', 'payment', $payment_id, array( 'intent_id' => $intent_id, 'reason' => $reason ) );
	}

	/**
	 * Cents covered by this charge when it is a deposit/installment that leaves a balance; else 0.
	 *
	 * @param int $payment_id Payment ID.
	 */
	private static function partial_charge_cents( $payment_id ) {
		$installment = (int) round( (float) get_post_meta( $payment_id, '_lnkup_installment_amount', true ) * 100 );
		if ( $installment <= 0 ) {
			return 0;
		}
		$total     = (int) round( (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ) * 100 );
		$paid      = (int) get_post_meta( $payment_id, '_lnkup_amount_paid_cents', true );
		$remaining = $total - $paid;
		return ( $installment < $remaining - 1 ) ? $installment : 0;
	}

	/**
	 * Apply a deposit/installment charge: the booking stays unpaid (packet locked, no
	 * settlement) until the final charge covers the remaining balance.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $intent_id  PaymentIntent ID.
	 * @param int    $cents      Amount this charge covers (charge plus credit applied).
	 * @param array  $context    Context.
	 */
	private static function record_partial_charge( $payment_id, $intent_id, $cents, $context ) {
		$partials = get_post_meta( $payment_id, '_lnkup_partial_charges', true );
		$partials = is_array( $partials ) ? $partials : array();
		$ref      = '' !== (string) $intent_id ? (string) $intent_id : 'charge_' . count( $partials );
		if ( isset( $partials[ $ref ] ) ) {
			return array( 'success' => true, 'partial' => true, 'already_recorded' => true, 'payment_id' => $payment_id );
		}
		$partials[ $ref ] = array(
			'cents'       => (int) $cents,
			'recorded_at' => current_time( 'mysql' ),
		);
		update_post_meta( $payment_id, '_lnkup_partial_charges', $partials );
		$paid = (int) get_post_meta( $payment_id, '_lnkup_amount_paid_cents', true ) + (int) $cents;
		update_post_meta( $payment_id, '_lnkup_amount_paid_cents', $paid );

		$app_id = (int) get_post_meta( $payment_id, '_lnkup_application_id', true );
		if ( $app_id ) {
			$deposit = LNKup_Deposits::get_for_application( $app_id );
			if ( $deposit && 'paid' !== $deposit['status'] ) {
				LNKup_Deposits::mark_paid( (int) $deposit['id'], round( $cents / 100, 2 ) );
			}
		}
		$installment_id = (int) get_post_meta( $payment_id, '_lnkup_installment_id', true );
		if ( $installment_id ) {
			LNKup_Installments::mark_paid( $installment_id, $payment_id );
			delete_post_meta( $payment_id, '_lnkup_installment_id' );
		}

		// Credit applied to this charge is used up; the next charge starts without it.
		$credit = (float) get_post_meta( $payment_id, '_lnkup_credit_applied', true );
		if ( $credit > 0 ) {
			update_post_meta( $payment_id, '_lnkup_credit_used_cents', (int) get_post_meta( $payment_id, '_lnkup_credit_used_cents', true ) + (int) round( $credit * 100 ) );
			update_post_meta( $payment_id, '_lnkup_credit_applied', 0 );
		}

		$total     = (int) round( (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ) * 100 );
		$remaining = max( 0, $total - $paid );
		$next      = $app_id ? LNKup_Installments::apply_to_payment( $payment_id, $app_id ) : 0;
		if ( $next <= 0 || (int) round( $next * 100 ) > $remaining ) {
			update_post_meta( $payment_id, '_lnkup_installment_amount', round( $remaining / 100, 2 ) );
		}

		update_post_meta( $payment_id, '_lnkup_payment_status', 'partially_paid' );
		update_post_meta( $payment_id, '_lnkup_charge_seq', (int) get_post_meta( $payment_id, '_lnkup_charge_seq', true ) + 1 );
		// The next charge needs a fresh PaymentIntent / Checkout Session.
		delete_post_meta( $payment_id, '_lnkup_stripe_payment_intent' );
		delete_post_meta( $payment_id, '_lnkup_stripe_checkout_session' );

		LNKup_Financial_Audit::log( 'partial_payment_recorded', 'payment', $payment_id, array_merge( $context, array( 'amount' => $cents / 100, 'intent_id' => (string) $intent_id, 'remaining_cents' => $remaining ) ) );
		return array(
			'success'         => true,
			'partial'         => true,
			'payment_id'      => $payment_id,
			'remaining_cents' => $remaining,
		);
	}

	/**
	 * Whether a succeeded charge covers what this payment expected for it.
	 *
	 * Uses the amount stamped when the PaymentIntent / Checkout Session was created, so a
	 * later fee change neither rejects a valid payment nor accepts a stale lower one.
	 *
	 * @param int    $payment_id     Payment ID.
	 * @param string $ref            PaymentIntent or Checkout Session ID.
	 * @param int    $received_cents Amount Stripe collected.
	 * @param string $currency       Currency.
	 */
	public static function charge_amount_ok( $payment_id, $ref, $received_cents, $currency = 'usd' ) {
		if ( 'usd' !== strtolower( (string) $currency ) ) {
			return false;
		}
		$expected_map = get_post_meta( $payment_id, '_lnkup_stripe_expected_cents', true );
		$expected_map = is_array( $expected_map ) ? $expected_map : array();
		$session_id   = (string) get_post_meta( $payment_id, '_lnkup_stripe_checkout_session', true );
		if ( isset( $expected_map[ $ref ] ) ) {
			$expected = (int) $expected_map[ $ref ];
		} elseif ( '' !== $session_id && isset( $expected_map[ $session_id ] ) ) {
			// PaymentIntent created by a hosted Checkout Session.
			$expected = (int) $expected_map[ $session_id ];
		} else {
			$expected = (int) round( self::stored_amount_due( $payment_id ) * 100 );
		}
		return (int) $received_cents >= $expected && $received_cents > 0;
	}

	/**
	 * Remember the amount a PaymentIntent / Checkout Session was created for.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $ref        PaymentIntent or Checkout Session ID.
	 * @param int    $cents      Amount in cents.
	 */
	public static function remember_expected_amount( $payment_id, $ref, $cents ) {
		if ( '' === (string) $ref ) {
			return;
		}
		$map         = get_post_meta( $payment_id, '_lnkup_stripe_expected_cents', true );
		$map         = is_array( $map ) ? $map : array();
		$map[ $ref ] = (int) $cents;
		update_post_meta( $payment_id, '_lnkup_stripe_expected_cents', array_slice( $map, -20, null, true ) );
	}

	/**
	 * Flag a charge whose amount did not match what was expected (not finalized).
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $ref        Stripe reference.
	 * @param int    $received   Received cents.
	 */
	public static function flag_amount_mismatch( $payment_id, $ref, $received ) {
		update_post_meta( $payment_id, '_lnkup_needs_review', 'amount_mismatch' );
		LNKup_Financial_Audit::log( 'charge_amount_mismatch', 'payment', $payment_id, array( 'ref' => (string) $ref, 'received_cents' => (int) $received ) );
	}

	/**
	 * Mark paid and settle exactly once, serialized per payment.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $intent_id  Stripe intent ID.
	 * @param array  $context    Extra context.
	 */
	public static function finalize_payment( $payment_id, $intent_id = '', $context = array() ) {
		$payment_id = absint( $payment_id );
		return self::with_lock(
			'pay_' . $payment_id,
			static function () use ( $payment_id, $intent_id, $context ) {
				return self::finalize_payment_locked( $payment_id, $intent_id, $context );
			},
			15
		);
	}

	/**
	 * @param int    $payment_id Payment ID.
	 * @param string $intent_id  Stripe intent ID.
	 * @param array  $context    Extra context.
	 */
	private static function finalize_payment_locked( $payment_id, $intent_id, $context ) {
		$status = get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( 'paid' === $status ) {
			if ( self::is_other_intent( $payment_id, $intent_id ) ) {
				self::record_extra_charge( $payment_id, $intent_id, 'second_charge_on_paid_payment', $context );
			}
			$recovered = self::ensure_settled( $payment_id );
			if ( class_exists( 'LNKup_Financial_Audit' ) ) {
				LNKup_Financial_Audit::log( 'finalize_skipped', 'payment', $payment_id, array_merge( $context, array( 'reason' => 'already_paid', 'settlement_recovered' => $recovered ) ) );
			}
			return array( 'already_paid' => true, 'settlement_recovered' => $recovered );
		}

		if ( ! LNKup_Payments::is_collectible( $payment_id ) ) {
			// Refunded, disputed or voided: money Stripe collected here must be reviewed, never re-applied.
			$voided = '' !== (string) get_post_meta( $payment_id, '_lnkup_voided_at', true );
			if ( 0 === strpos( (string) $intent_id, 'pi_' ) && ( $voided || self::is_other_intent( $payment_id, $intent_id ) ) ) {
				self::record_extra_charge( $payment_id, $intent_id, $voided ? 'charge_on_voided_payment' : 'charge_on_closed_payment', $context );
			}
			return array(
				'refused'    => true,
				'reason'     => 'payment_not_collectible',
				'payment_id' => $payment_id,
			);
		}

		$partials = get_post_meta( $payment_id, '_lnkup_partial_charges', true );
		if ( '' !== (string) $intent_id && is_array( $partials ) && isset( $partials[ $intent_id ] ) ) {
			// Replay of a deposit/installment charge already counted; never let it complete the balance.
			return array( 'success' => true, 'partial' => true, 'already_recorded' => true, 'payment_id' => $payment_id );
		}

		if ( $intent_id ) {
			self::backfill_stripe_payment_meta( $payment_id, $intent_id );
		}

		$partial = self::partial_charge_cents( $payment_id );
		if ( $partial > 0 ) {
			return self::record_partial_charge( $payment_id, $intent_id, $partial, $context );
		}

		LNKup_Payments::mark_paid( $payment_id, $intent_id );
		LNKup_Transactions::sync_extended_fields( $payment_id );
		update_post_meta( $payment_id, '_lnkup_amount_paid_cents', (int) round( (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ) * 100 ) );

		if ( ! self::is_settled( $payment_id ) ) {
			self::settle_treasury( $payment_id );
		}

		if ( class_exists( 'LNKup_Financial_Audit' ) ) {
			LNKup_Financial_Audit::log( 'finalize_completed', 'payment', $payment_id, array_merge( $context, array(
				'ledger' => class_exists( 'LNKup_Organizer_Wallet' ) && LNKup_Organizer_Wallet::has_connect_settlement_for_payment( $payment_id ),
			) ) );
		}

		do_action( 'lnkup_payment_completed', $payment_id );

		return array( 'success' => true, 'payment_id' => $payment_id, 'context' => $context );
	}

	/**
	 * Idempotent reconcile when webhooks were missed or ledger is empty.
	 *
	 * @param int    $payment_id Payment post ID.
	 * @param string $intent_id  Optional Stripe PaymentIntent ID.
	 */
	public static function reconcile_payment( $payment_id, $intent_id = '' ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id || 'lnkup_payment' !== get_post_type( $payment_id ) ) {
			return new WP_Error( 'invalid_payment', __( 'Invalid payment.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$current = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( 'paid' !== $current && ! LNKup_Payments::is_collectible( $payment_id ) ) {
			return array( 'success' => true, 'action' => 'not_collectible', 'payment_id' => $payment_id );
		}

		$intent_id = $intent_id ?: (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
		if ( 0 !== strpos( (string) $intent_id, 'pi_' ) ) {
			$from_session = self::intent_from_checkout_session( $payment_id );
			if ( '' !== $from_session ) {
				$intent_id = $from_session;
			}
		}

		self::backfill_stripe_payment_meta( $payment_id, $intent_id );

		$status = get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( 'paid' !== $status ) {
			if ( $intent_id && 0 === strpos( $intent_id, 'pi_' ) ) {
				$verified = self::verify_intent_for_payment( $payment_id, $intent_id );
				if ( 'processing' === $verified ) {
					self::mark_payment_processing( $payment_id, $intent_id, array( 'source' => 'reconcile' ) );
					return new WP_Error(
						'payment_processing',
						__( 'Bank payment is still processing. Packet unlocks when funds clear.', 'lnkup-marketplace' ),
						array( 'status' => 409 )
					);
				}
				if ( is_wp_error( $verified ) ) {
					return $verified;
				}
				return self::finalize_payment( $payment_id, $intent_id, array( 'source' => 'reconcile' ) );
			}
			if ( $intent_id && 0 === strpos( $intent_id, 'sim_' ) && self::simulation_enabled() ) {
				return self::finalize_payment( $payment_id, $intent_id, array( 'source' => 'reconcile_simulation' ) );
			}
			$zero = self::maybe_finalize_zero_amount( $payment_id );
			if ( null !== $zero ) {
				return $zero;
			}
			return new WP_Error(
				'payment_incomplete',
				__( 'No completed payment was found for this booking yet.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}

		$settled = self::with_lock(
			'pay_' . $payment_id,
			static function () use ( $payment_id ) {
				return self::ensure_settled( $payment_id );
			},
			15
		);
		if ( is_wp_error( $settled ) ) {
			return $settled;
		}

		return array(
			'success'    => true,
			'action'     => $settled ? 'settlement_backfilled' : 'already_settled',
			'payment_id' => $payment_id,
		);
	}

	/**
	 * Resolve the PaymentIntent for a hosted Checkout Session payment (GET only).
	 *
	 * Stores the intent on the payment when the session belongs to it.
	 *
	 * @param int $payment_id Payment post ID.
	 * @return string PaymentIntent ID or ''.
	 */
	private static function intent_from_checkout_session( $payment_id ) {
		$session_id = (string) get_post_meta( $payment_id, '_lnkup_stripe_checkout_session', true );
		if ( 0 !== strpos( $session_id, 'cs_' ) || ! class_exists( 'LNKup_Stripe_Client' ) ) {
			return '';
		}
		$client = new LNKup_Stripe_Client();
		if ( ! $client->is_available() ) {
			return '';
		}
		$session = $client->read( 'checkout/sessions/' . $session_id );
		if ( is_wp_error( $session ) ) {
			return '';
		}
		$ref = absint( $session['client_reference_id'] ?? 0 );
		if ( $ref && $ref !== (int) $payment_id ) {
			return '';
		}
		$intent_id = is_string( $session['payment_intent'] ?? null ) ? $session['payment_intent'] : (string) ( $session['payment_intent']['id'] ?? '' );
		if ( 0 !== strpos( $intent_id, 'pi_' ) ) {
			return '';
		}
		update_post_meta( $payment_id, '_lnkup_stripe_payment_intent', sanitize_text_field( $intent_id ) );
		return $intent_id;
	}

	/**
	 * Auto-reconcile organizer payments stuck pending or missing ledger rows.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function reconcile_organizer_payments( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return 0;
		}

		$reconciled = 0;
		$payments   = lnkup_get_payments_for_user( $user_id, 'organizer' );
		foreach ( (array) $payments as $payment ) {
			$payment_id = (int) $payment->ID;
			$status     = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
			$intent_id  = (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );

			if ( ! self::auto_recovery_allowed( $payment_id ) ) {
				continue;
			}
			if ( 'paid' === $status ) {
				if ( self::is_settled( $payment_id ) ) {
					continue;
				}
				$result = self::reconcile_payment( $payment_id, $intent_id );
				if ( ! is_wp_error( $result ) && 'settlement_backfilled' === ( $result['action'] ?? '' ) ) {
					$reconciled++;
				}
				continue;
			}
			if ( ! in_array( $status, array( 'pending', 'processing', 'failed', '' ), true ) ) {
				continue;
			}
			if ( ! $intent_id && '' === (string) get_post_meta( $payment_id, '_lnkup_stripe_checkout_session', true ) ) {
				continue;
			}

			$result = self::reconcile_payment( $payment_id, $intent_id );
			if ( ! is_wp_error( $result ) && empty( $result['already_paid'] ) ) {
				$reconciled++;
			}
		}

		// After settle credits, migrate/backfill into connect_settlement for destination-ready organizers.
		if ( class_exists( 'LNKup_Organizer_Wallet' ) ) {
			LNKup_Organizer_Wallet::migrate_treasury_balance_to_connect_settlement( $user_id );
			LNKup_Organizer_Wallet::backfill_platform_payments_to_connect_settlement( $user_id );
		}

		return $reconciled;
	}

	/**
	 * Auto-reconcile vendor payments stuck in pending after Stripe succeeded.
	 *
	 * @param int $user_id Vendor user ID.
	 */
	public static function reconcile_vendor_payments( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return 0;
		}

		$reconciled = 0;
		$payments   = lnkup_get_payments_for_user( $user_id, 'vendor' );
		foreach ( (array) $payments as $payment ) {
			$payment_id = (int) $payment->ID;
			$status     = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
			if ( ! in_array( $status, array( 'pending', 'processing', 'failed', '' ), true ) || ! self::auto_recovery_allowed( $payment_id ) ) {
				continue;
			}
			$intent_id = (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
			if ( ! $intent_id && '' === (string) get_post_meta( $payment_id, '_lnkup_stripe_checkout_session', true ) ) {
				continue;
			}
			$result = self::reconcile_payment( $payment_id, $intent_id );
			if ( ! is_wp_error( $result ) ) {
				$reconciled++;
			}
		}

		return $reconciled;
	}

	/**
	 * Sync Stripe PaymentIntent amounts into payment meta.
	 *
	 * @param int    $payment_id Payment post ID.
	 * @param string $intent_id  Stripe PaymentIntent ID.
	 */
	public static function backfill_stripe_payment_meta( $payment_id, $intent_id = '' ) {
		$intent_id = $intent_id ?: (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
		if ( ! $intent_id || ! class_exists( 'LNKup_Stripe_Client' ) ) {
			return false;
		}

		if ( 0 !== strpos( (string) $intent_id, 'pi_' ) ) {
			return false;
		}
		$client = new LNKup_Stripe_Client();
		if ( ! $client->is_available() ) {
			return false;
		}
		$intent = $client->read( 'payment_intents/' . $intent_id, array( 'expand' => array( 'latest_charge' ) ) );
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}
		$meta_payment = absint( $intent['metadata']['payment_post_id'] ?? 0 );
		$stored       = (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
		if ( $meta_payment ? $meta_payment !== (int) $payment_id : $stored !== $intent_id ) {
			return false;
		}
		// A second charge on a paid or closed payment must not overwrite the recorded charge's references.
		$status = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( '' !== $stored && $stored !== $intent_id && ( 'paid' === $status || ! LNKup_Payments::is_collectible( $payment_id ) ) ) {
			return false;
		}

		$amount_cents    = (int) ( $intent['amount'] ?? 0 );
		$app_fee_cents   = (int) ( $intent['application_fee_amount'] ?? 0 );
		$destination     = $intent['transfer_data']['destination'] ?? '';

		if ( $amount_cents > 0 ) {
			update_post_meta( $payment_id, '_lnkup_stripe_charge_amount_cents', $amount_cents );
		}
		if ( $app_fee_cents > 0 ) {
			update_post_meta( $payment_id, '_lnkup_application_fee_cents', $app_fee_cents );
			update_post_meta( $payment_id, '_lnkup_lnkup_fee', $app_fee_cents / 100 );
		}
		if ( $destination ) {
			update_post_meta( $payment_id, '_lnkup_stripe_destination_account', sanitize_text_field( $destination ) );
		}
		if ( $amount_cents > 0 && $app_fee_cents >= 0 && $destination ) {
			update_post_meta( $payment_id, '_lnkup_stripe_destination_amount', ( $amount_cents - $app_fee_cents ) / 100 );
		}

		$charge = is_array( $intent['latest_charge'] ?? null ) ? $intent['latest_charge'] : array();
		$refs   = array(
			'_lnkup_stripe_charge_id'              => (string) ( $charge['id'] ?? ( is_string( $intent['latest_charge'] ?? null ) ? $intent['latest_charge'] : '' ) ),
			'_lnkup_stripe_balance_transaction_id' => is_string( $charge['balance_transaction'] ?? null ) ? $charge['balance_transaction'] : '',
			'_lnkup_stripe_transfer_id'            => is_string( $charge['transfer'] ?? null ) ? $charge['transfer'] : '',
		);
		foreach ( $refs as $key => $value ) {
			if ( '' !== $value ) {
				update_post_meta( $payment_id, $key, sanitize_text_field( $value ) );
			}
		}
		update_post_meta( $payment_id, '_lnkup_stripe_livemode', ! empty( $intent['livemode'] ) ? '1' : '0' );
		if ( ! empty( $charge['created'] ) && '' === (string) get_post_meta( $payment_id, '_lnkup_stripe_charged_at', true ) ) {
			update_post_meta( $payment_id, '_lnkup_stripe_charged_at', gmdate( 'Y-m-d H:i:s', (int) $charge['created'] ) );
		}

		return true;
	}

	/**
	 * Treasury settlement pipeline after payment marked paid.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function settle_treasury( $payment_id ) {
		if ( ! class_exists( 'LNKup_Fee_Engine' ) ) {
			return;
		}
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$breakdown    = LNKup_Fee_Engine::settlement_breakdown( $payment_id );
		if ( class_exists( 'LNKup_Stripe_Connect' ) ) {
			LNKup_Stripe_Connect::persist_payment_settlement_meta( $payment_id, $organizer_id, $breakdown, 'settle' );
		}
		$mode = get_post_meta( $payment_id, '_lnkup_settlement_mode', true ) ?: 'platform_treasury';
		if ( class_exists( 'LNKup_Transactions' ) ) {
			LNKup_Transactions::sync_settlement_fields( $payment_id, $breakdown, $mode );
		}
		if ( class_exists( 'LNKup_Platform_Fees' ) ) {
			LNKup_Platform_Fees::record( $payment_id, $breakdown );
		}
		if ( class_exists( 'LNKup_Reserves' ) ) {
			LNKup_Reserves::maybe_hold( $payment_id, $breakdown );
		}
		if ( 'platform_treasury' === $mode && class_exists( 'LNKup_Escrow' ) ) {
			LNKup_Escrow::maybe_hold( $payment_id, $breakdown );
		}
		if ( class_exists( 'LNKup_Organizer_Wallet' ) ) {
			LNKup_Organizer_Wallet::credit_from_payment( $payment_id, $breakdown );
		}
		if ( class_exists( 'LNKup_Payment_Timeline' ) ) {
			$note = 'destination' === $mode
				? __( 'Connect destination settlement recorded (ledger mirror)', 'lnkup-marketplace' )
				: __( 'Treasury settlement recorded', 'lnkup-marketplace' );
			LNKup_Payment_Timeline::record( $payment_id, 'settled', array( 'notes' => $note ) );
		}
		if ( class_exists( 'LNKup_Financial_Activity' ) ) {
			$organizer_net = (float) ( $breakdown['organizer_net'] ?? 0 );
			$event_id      = (int) get_post_meta( $payment_id, '_lnkup_event_id', true );
			LNKup_Financial_Activity::record( 'payment_settled', array(
				'user_id'     => $organizer_id,
				'role'        => 'organizer',
				'entity_type' => 'payment',
				'entity_id'   => $payment_id,
				'amount'      => $organizer_net,
				'message'     => __( 'Booth payment settled', 'lnkup-marketplace' ),
				'metadata'    => array(
					'status'          => 'completed',
					'settlement_mode' => $mode,
					'event_id'        => $event_id,
				),
			) );
		}
		update_post_meta( $payment_id, '_lnkup_settled_at', current_time( 'mysql' ) );
		LNKup_Financial_Audit::log( 'treasury_settled', 'payment', $payment_id, array_merge( $breakdown, array( 'settlement_mode' => $mode ) ) );
	}

	/**
	 * Request organizer cashout.
	 *
	 * @param int    $user_id Organizer user ID.
	 * @param float  $amount  Amount.
	 * @param string $type         Payout type.
	 * @param string $client_token Optional idempotency key from the client.
	 */
	public static function request_organizer_cashout( $user_id, $amount, $type = 'standard', $client_token = '' ) {
		// Cashout is a write: bring the ledger current (missed webhooks, Connect recovery) first.
		self::sync_organizer( $user_id );
		return LNKup_Organizer_Payouts::request_cashout( $user_id, $amount, $type, $client_token );
	}

	/**
	 * Bring an organizer's payments and ledger current with Stripe. Write path only.
	 *
	 * Runs from the hourly cron, cashout, and the explicit POST /financial/sync. Never from GET.
	 *
	 * @param int $user_id Organizer user ID.
	 * @return array|WP_Error Summary.
	 */
	public static function sync_organizer( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return self::with_lock(
			'org_sync_' . $user_id,
			static function () use ( $user_id ) {
				$reconciled = self::reconcile_organizer_payments( $user_id );
				if ( class_exists( 'LNKup_Transactions' ) ) {
					LNKup_Transactions::backfill_amounts_from_payments( $user_id );
				}
				update_user_meta( $user_id, '_lnkup_financial_synced_at', current_time( 'mysql' ) );
				return array(
					'reconciled' => (int) $reconciled,
					'synced_at'  => current_time( 'mysql' ),
				);
			},
			5
		);
	}

	/**
	 * Vendor counterpart of sync_organizer(): finalize payments Stripe already collected.
	 *
	 * @param int $user_id Vendor user ID.
	 * @return array|WP_Error Summary.
	 */
	public static function sync_vendor( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return self::with_lock(
			'vendor_sync_' . $user_id,
			static function () use ( $user_id ) {
				$reconciled = self::reconcile_vendor_payments( $user_id );
				update_user_meta( $user_id, '_lnkup_financial_synced_at', current_time( 'mysql' ) );
				return array(
					'reconciled' => (int) $reconciled,
					'synced_at'  => current_time( 'mysql' ),
				);
			},
			5
		);
	}

	/**
	 * Get organizer wallet balances.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function organizer_wallet( $user_id = 0 ) {
		$user_id  = $user_id ?: get_current_user_id();
		$payload  = self::organizer_balance_payload( $user_id );
		$balances = $payload['wallet'];
		return array(
			'money'            => self::organizer_money_contract( $user_id, $payload ),
			'balances'         => $balances,
			'display_balances' => $payload['display_balances'],
			'entries'          => LNKup_Organizer_Wallet::list_entries( $user_id ),
			'entries_total'    => LNKup_Organizer_Wallet::count_entries( $user_id ),
			'escrow'           => class_exists( 'LNKup_Escrow' ) ? LNKup_Escrow::list_for_user( $user_id ) : array(),
			'reserves'         => class_exists( 'LNKup_Reserves' ) ? LNKup_Reserves::list_for_user( $user_id ) : array(),
			'connect'          => $payload['connect'],
			'stripe_balance'   => $payload['stripe_balance'],
			'wallet'           => $balances,
		);
	}

	/**
	 * Stripe Connect balance for destination-mode organizers.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function organizer_stripe_balance( $user_id ) {
		if ( ! class_exists( 'LNKup_Stripe_Connect' ) ) {
			return array( 'available' => 0, 'pending' => 0, 'synced' => false );
		}
		if ( 'destination' !== LNKup_Stripe_Connect::settlement_mode_for_organizer( $user_id ) ) {
			return array( 'available' => 0, 'pending' => 0, 'synced' => false );
		}
		return LNKup_Stripe_Connect::retrieve_connect_balance( $user_id );
	}

	/**
	 * Wallet + Stripe + display balances for organizer money UI.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function organizer_balance_payload( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();

		// Pure read. Ledger catch-up runs in sync_organizer() (cron, cashout, POST /financial/sync).
		$wallet  = array();
		if ( class_exists( 'LNKup_Organizer_Wallet' ) ) {
			try {
				$wallet = LNKup_Organizer_Wallet::get_balances( $user_id );
			} catch ( Throwable $e ) {
				$wallet = array();
			}
		}
		$stripe_balance   = self::organizer_stripe_balance( $user_id );
		$display_balances = self::organizer_display_balances( $user_id, $wallet, $stripe_balance );
		if ( ! empty( $stripe_balance['synced'] ) && is_array( $wallet ) ) {
			$wallet['stripe_balance'] = $stripe_balance;
		}
		$connect = array();
		if ( class_exists( 'LNKup_Stripe_Connect' ) ) {
			try {
				$connect = LNKup_Stripe_Connect::onboarding_status( $user_id );
			} catch ( Throwable $e ) {
				$connect = array();
			}
		}
		return array(
			'wallet'           => $wallet,
			'stripe_balance'   => $stripe_balance,
			'display_balances' => $display_balances,
			'connect'          => $connect,
		);
	}

	/**
	 * Cashout ceiling shared by Money UI and POST /organizer-wallet/cashout.
	 *
	 * Destination mode uses live Stripe Connect available plus ledger-capped
	 * platform recovery (same as display_balances). Platform treasury uses ledger
	 * available. Returns WP_Error when destination Stripe balance cannot be synced.
	 *
	 * @param int $user_id Organizer user ID.
	 * @return float|WP_Error
	 */
	public static function organizer_cashout_available( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$wallet  = class_exists( 'LNKup_Organizer_Wallet' ) ? LNKup_Organizer_Wallet::get_balances( $user_id ) : array();
		$mode    = (string) ( $wallet['settlement_mode'] ?? 'platform_treasury' );

		if ( 'destination' === $mode ) {
			$stripe_balance = self::organizer_stripe_balance( $user_id );
			if ( empty( $stripe_balance['synced'] ) ) {
				return new WP_Error(
					'balance_unavailable',
					__( 'Could not load your Stripe payout balance. Refresh and try again.', 'lnkup-marketplace' ),
					array( 'status' => 400 )
				);
			}
			$display = self::organizer_display_balances( $user_id, $wallet, $stripe_balance );
			return round( (float) ( $display['available'] ?? 0 ), 2 );
		}

		return round( (float) ( $wallet['available'] ?? 0 ), 2 );
	}

	/**
	 * Unified organizer balance figures for dashboard UI (ledger + Stripe Connect).
	 *
	 * Destination + synced: Available = Connect available + ledger-capped platform recovery.
	 * Never exposes uncapped platform fees as Available.
	 *
	 * @param int        $user_id        Organizer user ID.
	 * @param array|null $wallet         Optional wallet balances from LNKup_Organizer_Wallet::get_balances().
	 * @param array|null $stripe_balance Optional Stripe Connect balance payload.
	 */
	public static function organizer_display_balances( $user_id = 0, $wallet = null, $stripe_balance = null ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! is_array( $wallet ) ) {
			$wallet = class_exists( 'LNKup_Organizer_Wallet' ) ? LNKup_Organizer_Wallet::get_balances( $user_id ) : array();
		}
		if ( ! is_array( $stripe_balance ) ) {
			$stripe_balance = self::organizer_stripe_balance( $user_id );
		}

		$mode      = (string) ( $wallet['settlement_mode'] ?? 'platform_treasury' );
		$available = (float) ( $wallet['available'] ?? 0 );
		$pending   = (float) ( $wallet['pending'] ?? 0 );
		$source    = 'ledger';

		$connect_available    = 0.0;
		$platform_recoverable = 0.0;
		$ledger_claim         = round( (float) ( $wallet['available'] ?? 0 ), 2 );

		if ( 'destination' === $mode && ! empty( $stripe_balance['synced'] ) ) {
			$connect_available = (float) ( $stripe_balance['available'] ?? 0 );
			$pending           = (float) ( $stripe_balance['pending'] ?? 0 );
			$source            = 'stripe_connect';

			$connect_cents = isset( $stripe_balance['available_cents'] )
				? (int) $stripe_balance['available_cents']
				: (int) round( $connect_available * 100 );

			if ( class_exists( 'LNKup_Stripe_Connect' ) ) {
				$recoverable_cents    = LNKup_Stripe_Connect::organizer_recoverable_platform_cents( $user_id, $connect_cents );
				$platform_recoverable = round( $recoverable_cents / 100, 2 );
				$ledger_claim         = round( LNKup_Stripe_Connect::organizer_ledger_claim_cents( $user_id ) / 100, 2 );
			}

			$available = round( $connect_available + $platform_recoverable, 2 );
			if ( $platform_recoverable > 0 ) {
				$source = 'stripe_connect_plus_recovery';
			}
		} elseif ( 'destination' === $mode && (float) ( $wallet['connect_settlement'] ?? 0 ) > 0 ) {
			$settled   = (float) $wallet['connect_settlement'];
			$available = $ledger_claim > 0 ? $ledger_claim : $settled;
			$source    = 'ledger_settlement';
		}

		return array(
			'available'            => round( $available, 2 ),
			'pending'              => round( $pending, 2 ),
			'processing'           => round( (float) ( $wallet['processing'] ?? 0 ), 2 ),
			'reserve_hold'         => round( (float) ( $wallet['reserve_hold'] ?? 0 ), 2 ),
			'connect_settlement'   => round( (float) ( $wallet['connect_settlement'] ?? 0 ), 2 ),
			'connect_available'    => round( $connect_available, 2 ),
			'platform_recoverable' => round( $platform_recoverable, 2 ),
			'ledger_claim'         => round( $ledger_claim, 2 ),
			'settlement_mode'      => $mode,
			'balance_source'       => $source,
			'stripe_synced'        => ! empty( $stripe_balance['synced'] ),
			'source_types'         => ( in_array( $source, array( 'stripe_connect', 'stripe_connect_plus_recovery' ), true ) && is_array( $stripe_balance['source_types'] ?? null ) )
				? $stripe_balance['source_types']
				: array(),
		);
	}

	/**
	 * Canonical organizer money contract (v1), shared by web and mobile.
	 *
	 * Every metric is integer cents with its source and sync time. A figure that cannot be
	 * read (Stripe unreachable) is reported as status "unavailable" with cents null — never 0.
	 * Definitions live in docs/finance/MONEY_DEFINITIONS.md.
	 *
	 * @param int        $user_id         Organizer user ID.
	 * @param array|null $balance_payload Optional organizer_balance_payload() result.
	 * @param array      $ledger_totals   Optional collected / fees / refunds in dollars.
	 */
	public static function organizer_money_contract( $user_id = 0, $balance_payload = null, $ledger_totals = array() ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! is_array( $balance_payload ) ) {
			$balance_payload = self::organizer_balance_payload( $user_id );
		}
		$wallet  = (array) ( $balance_payload['wallet'] ?? array() );
		$stripe  = (array) ( $balance_payload['stripe_balance'] ?? array() );
		$display = (array) ( $balance_payload['display_balances'] ?? array() );
		$connect = (array) ( $balance_payload['connect'] ?? array() );
		$mode    = (string) ( $wallet['settlement_mode'] ?? ( $connect['settlement_mode'] ?? 'platform_treasury' ) );
		$now     = gmdate( 'c' );
		$synced  = ! empty( $stripe['synced'] );
		$cents   = static function ( $dollars ) {
			return (int) round( (float) $dollars * 100 );
		};
		$metric  = static function ( $value, $source, $synced_at = null, $status = 'ok' ) {
			return array(
				'cents'     => 'ok' === $status ? (int) $value : null,
				'status'    => $status,
				'source'    => $source,
				'synced_at' => $synced_at,
			);
		};

		// Organizers see collected after LNKup fees; the fee amount itself is never returned.
		if ( ! array_key_exists( 'collected', $ledger_totals ) ) {
			$gross = class_exists( 'LNKup_Financial' ) ? LNKup_Financial::organizer_collected_revenue( $user_id ) : 0;
			$fees  = class_exists( 'LNKup_Financial_Reports' ) ? LNKup_Financial_Reports::organizer_platform_fees( $user_id ) : 0;
			$ledger_totals['collected'] = max( 0, (float) $gross - (float) $fees );
		} elseif ( empty( $ledger_totals['after_fees'] ) ) {
			$ledger_totals['collected'] = max( 0, (float) $ledger_totals['collected'] - (float) ( $ledger_totals['platform_fees'] ?? 0 ) );
		}
		$collected = $cents( $ledger_totals['collected'] );
		$refunds   = $cents( $ledger_totals['refunds'] ?? 0 );

		$destination = 'destination' === $mode;
		if ( $destination ) {
			$balance_status = $synced ? 'ok' : 'unavailable';
			$available      = $metric( $cents( $display['available'] ?? 0 ), 'stripe_connect', $synced ? $now : null, $balance_status );
			$pending        = $metric( $cents( $stripe['pending'] ?? 0 ), 'stripe_connect', $synced ? $now : null, $balance_status );
		} else {
			$available = $metric( $cents( $wallet['available'] ?? 0 ), 'ledger' );
			$pending   = $metric( $cents( $wallet['pending'] ?? 0 ), 'ledger' );
		}

		$lnkup_paid = 0;
		if ( class_exists( 'LNKup_Organizer_Payouts' ) ) {
			foreach ( (array) LNKup_Organizer_Payouts::list_for_user( $user_id, 500 ) as $payout ) {
				if ( in_array( (string) ( $payout['status'] ?? '' ), array( 'paid', 'completed' ), true ) ) {
					$lnkup_paid += $cents( $payout['net_amount'] ?? $payout['amount'] ?? 0 );
				}
			}
		}

		$bank = $destination ? self::connect_payout_totals( $user_id ) : null;
		if ( $destination ) {
			$bank_status = ! empty( $bank['ok'] ) ? 'ok' : 'unavailable';
			$paid_out    = $metric( (int) ( $bank['paid_cents'] ?? 0 ), 'stripe_connect_payouts', $bank['synced_at'] ?? null, $bank_status );
			$in_transit  = $metric( (int) ( $bank['in_transit_cents'] ?? 0 ), 'stripe_connect_payouts', $bank['synced_at'] ?? null, $bank_status );
		} else {
			$paid_out   = $metric( $lnkup_paid, 'lnkup_cashouts' );
			$in_transit = $metric( $cents( $wallet['processing'] ?? 0 ), 'ledger' );
		}

		$payouts = self::organizer_payout_state( $connect, $mode, $available );

		return array(
			'version'         => 1,
			'currency'        => 'usd',
			'generated_at'    => $now,
			'last_synced_at'  => (string) get_user_meta( $user_id, '_lnkup_financial_synced_at', true ) ?: null,
			'settlement_mode' => $mode,
			'payouts'         => $payouts,
			'metrics'         => array(
				'collected'           => $metric( $collected, 'wordpress_payments' ),
				'refunds'             => $metric( $refunds, 'wordpress_payments' ),
				'net_earnings'        => $metric( max( 0, $collected - $refunds ), 'wordpress_payments' ),
				'available'           => $available,
				'pending'             => $pending,
				'in_transit'          => $in_transit,
				'paid_out'            => $paid_out,
				'processing'          => $metric( $cents( $wallet['processing'] ?? 0 ), 'ledger' ),
				'lnkup_cashouts_paid' => $metric( $lnkup_paid, 'ledger' ),
				'reserve'             => $metric( $cents( $wallet['reserve_hold'] ?? 0 ), 'ledger' ),
				'lnkup_settled'       => $metric( $cents( $wallet['connect_settlement'] ?? 0 ), 'ledger' ),
				'platform_held'       => $metric( $cents( (float) ( $wallet['treasury_available'] ?? 0 ) + (float) ( $wallet['treasury_pending'] ?? 0 ) ), 'ledger' ),
			),
		);
	}

	/**
	 * Canonical vendor money contract (v1): what the vendor paid, from payment records.
	 *
	 * @param int $user_id Vendor user ID.
	 */
	public static function vendor_money_contract( $user_id ) {
		$totals = array(
			'paid'       => 0,
			'processing' => 0,
			'outstanding' => 0,
			'refunded'   => 0,
		);
		$counts = array_fill_keys( array_keys( $totals ), 0 );
		foreach ( (array) lnkup_get_payments_for_user( absint( $user_id ), 'vendor' ) as $payment ) {
			$id     = (int) $payment->ID;
			$status = (string) get_post_meta( $id, '_lnkup_payment_status', true );
			$amount = (int) round( (float) get_post_meta( $id, '_lnkup_total_amount', true ) * 100 );
			$bucket = array(
				'paid'       => 'paid',
				'processing' => 'processing',
				'pending'    => 'outstanding',
				'failed'     => 'outstanding',
				'refunded'   => 'refunded',
			)[ $status ] ?? '';
			if ( 'refunded' === $bucket ) {
				$amount = (int) round( (float) get_post_meta( $id, '_lnkup_refund_amount', true ) * 100 ) ?: $amount;
			}
			if ( '' !== $bucket ) {
				$totals[ $bucket ] += $amount;
				++$counts[ $bucket ];
			}
		}
		$metrics = array();
		foreach ( $totals as $key => $value ) {
			$metrics[ $key ] = array(
				'cents'     => $value,
				'count'     => $counts[ $key ],
				'status'    => 'ok',
				'source'    => 'wordpress_payments',
				'synced_at' => null,
			);
		}
		return array(
			'version'        => 1,
			'currency'       => 'usd',
			'generated_at'   => gmdate( 'c' ),
			'last_synced_at' => (string) get_user_meta( absint( $user_id ), '_lnkup_financial_synced_at', true ) ?: null,
			'metrics'        => $metrics,
		);
	}

	/**
	 * Payout status shown next to balances. "Active" requires a ready Connect account; Cash Out
	 * additionally requires a synced, positive available balance.
	 *
	 * @param array  $connect   Onboarding status.
	 * @param string $mode      Settlement mode.
	 * @param array  $available Available metric.
	 */
	private static function organizer_payout_state( $connect, $mode, $available ) {
		$status = (string) ( $connect['status'] ?? 'not_started' );
		$map    = array(
			'active'                => array( 'active', __( 'Payouts active', 'lnkup-marketplace' ) ),
			'pending'               => array( 'setup_incomplete', __( 'Finish payout setup', 'lnkup-marketplace' ) ),
			'restricted'            => array( 'setup_incomplete', __( 'Finish payout setup', 'lnkup-marketplace' ) ),
			'pending_verification'  => array( 'verifying', __( 'Verifying payout details', 'lnkup-marketplace' ) ),
			'verification_required' => array( 'action_required', __( 'Payout details needed', 'lnkup-marketplace' ) ),
			'disabled'              => array( 'paused', __( 'Payouts paused', 'lnkup-marketplace' ) ),
			'rejected'              => array( 'paused', __( 'Payouts paused', 'lnkup-marketplace' ) ),
			'not_started'           => array( 'not_connected', __( 'Set up payouts', 'lnkup-marketplace' ) ),
		);
		list( $state, $label ) = $map[ $status ] ?? $map['not_started'];
		if ( 'active' === $state && 'destination' !== $mode ) {
			$state = 'setup_incomplete';
			$label = __( 'Finish payout setup', 'lnkup-marketplace' );
		}
		$connect_off = false === ( $connect['available'] ?? true ) || 'connect_not_configured' === ( $connect['code'] ?? '' );
		if ( $connect_off && 'destination' !== $mode ) {
			$state = 'manual';
			$label = __( 'Paid by LNKup', 'lnkup-marketplace' );
		}

		// Mirrors LNKup_Organizer_Payouts::request_cashout_locked(): destination cashouts need a
		// ready account; platform-settled balances are paid out by LNKup unless payouts are paused.
		$eligible = 'destination' === $mode ? 'active' === $state : 'paused' !== $state;

		$reason = '';
		if ( ! $eligible ) {
			$reason = 'payouts_not_active';
		} elseif ( 'ok' !== $available['status'] ) {
			$reason = 'balance_unavailable';
		} elseif ( (int) $available['cents'] < 100 ) {
			$reason = 'no_available_balance';
		}

		$detail = '';
		if ( 'active' === $state ) {
			$detail = __( 'Vendor payments go straight to your payout account.', 'lnkup-marketplace' );
		} elseif ( 'manual' === $state ) {
			$detail = __( 'LNKup sends your cash outs to you directly.', 'lnkup-marketplace' );
		}

		return array(
			'state'           => $state,
			'label'           => $label,
			'detail'          => $detail,
			'method'          => 'destination' === $mode ? 'connect' : 'lnkup',
			'can_cash_out'    => '' === $reason,
			'cash_out_reason' => $reason,
		);
	}

	/**
	 * Stripe payouts on the organizer's connected account (paid + in transit), cached 5 minutes.
	 *
	 * @param int $user_id Organizer user ID.
	 * @return array{ok:bool, paid_cents:int, in_transit_cents:int, synced_at:?string}
	 */
	private static function connect_payout_totals( $user_id ) {
		$cache_key = 'lnkup_connect_payouts_' . absint( $user_id );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$fail    = array( 'ok' => false, 'paid_cents' => 0, 'in_transit_cents' => 0, 'synced_at' => null );
		$account = class_exists( 'LNKup_Stripe_Connect' ) ? LNKup_Stripe_Connect::get_account( $user_id, 'organizer' ) : null;
		$acct_id = (string) ( $account['stripe_account_id'] ?? '' );
		$client  = new LNKup_Stripe_Client();
		if ( '' === $acct_id || ! $client->is_available() ) {
			return $fail;
		}
		$paid       = 0;
		$in_transit = 0;
		$after      = '';
		for ( $page = 0; $page < 5; $page++ ) {
			$params = array( 'limit' => 100 );
			if ( '' !== $after ) {
				$params['starting_after'] = $after;
			}
			$list = $client->read( 'payouts', $params, $acct_id );
			if ( is_wp_error( $list ) ) {
				return $fail;
			}
			foreach ( (array) ( $list['data'] ?? array() ) as $payout ) {
				$status = (string) ( $payout['status'] ?? '' );
				if ( 'paid' === $status ) {
					$paid += (int) ( $payout['amount'] ?? 0 );
				} elseif ( in_array( $status, array( 'pending', 'in_transit' ), true ) ) {
					$in_transit += (int) ( $payout['amount'] ?? 0 );
				}
				$after = (string) ( $payout['id'] ?? '' );
			}
			if ( empty( $list['has_more'] ) ) {
				break;
			}
		}
		$result = array(
			'ok'               => true,
			'paid_cents'       => $paid,
			'in_transit_cents' => $in_transit,
			'synced_at'        => gmdate( 'c' ),
		);
		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Confirm checkout from client after Payment Element success.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $intent_id  Intent ID.
	 */
	public static function confirm_checkout( $payment_id, $intent_id ) {
		$payment_id = absint( $payment_id );
		$intent_id  = sanitize_text_field( (string) $intent_id );
		if ( ! $intent_id ) {
			$intent_id = (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
		}
		if ( ! $intent_id ) {
			return new WP_Error( 'missing_intent', __( 'Missing payment reference.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		if ( 'paid' === get_post_meta( $payment_id, '_lnkup_payment_status', true ) ) {
			return array(
				'success'      => true,
				'already_paid' => true,
				'payment_id'   => $payment_id,
			);
		}

		if ( 0 === strpos( $intent_id, 'sim_' ) ) {
			if ( ! self::simulation_enabled() ) {
				self::log_rejected_confirm( $payment_id, $intent_id, 'simulation_disabled' );
				return new WP_Error( 'payment_incomplete', __( 'Payment not completed yet.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			return self::refused_as_error( self::finalize_payment( $payment_id, $intent_id, array( 'source' => 'client_confirm_simulation' ) ) );
		}

		$verified = self::verify_intent_for_payment( $payment_id, $intent_id );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		if ( 'processing' === $verified ) {
			self::mark_payment_processing( $payment_id, $intent_id, array( 'source' => 'client_confirm' ) );
			return new WP_Error(
				'payment_processing',
				__( 'Bank payment is still processing. Packet unlocks when funds clear.', 'lnkup-marketplace' ),
				array( 'status' => 409 )
			);
		}
		return self::refused_as_error( self::finalize_payment( $payment_id, $intent_id, array( 'source' => 'client_confirm' ) ) );
	}

	/**
	 * Client-facing error for a finalize that was refused (closed or voided payment).
	 *
	 * @param mixed $result finalize_payment() result.
	 * @return mixed
	 */
	private static function refused_as_error( $result ) {
		if ( is_array( $result ) && ! empty( $result['refused'] ) ) {
			return new WP_Error(
				'payment_not_collectible',
				__( 'This booking is no longer open for payment. LNKup support has been notified about this charge.', 'lnkup-marketplace' ),
				array( 'status' => 409 )
			);
		}
		return $result;
	}

	/**
	 * Whether simulated (sim_) payments may be finalized.
	 *
	 * Only when test mode is on and live Stripe is not fully configured, or when
	 * a developer explicitly defines LNKUP_ALLOW_PAYMENT_SIMULATION.
	 */
	public static function simulation_enabled() {
		if ( defined( 'LNKUP_ALLOW_PAYMENT_SIMULATION' ) ) {
			return (bool) LNKUP_ALLOW_PAYMENT_SIMULATION;
		}
		$settings = lnkup_get_settings();
		if ( empty( $settings['test_mode'] ) || ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return false;
		}
		$provider = self::provider();
		return 'manual' === $provider->get_id() || ! $provider->is_configured();
	}

	/**
	 * Retrieve a Stripe PaymentIntent and confirm it belongs to this payment.
	 *
	 * @param int    $payment_id Payment post ID.
	 * @param string $intent_id  PaymentIntent ID (pi_…).
	 * @return string|WP_Error 'succeeded' | 'processing', or an error.
	 */
	public static function verify_intent_for_payment( $payment_id, $intent_id ) {
		$incomplete = new WP_Error(
			'payment_incomplete',
			__( 'Payment not completed yet. If you were charged, wait a moment and refresh, or contact support.', 'lnkup-marketplace' ),
			array( 'status' => 400 )
		);
		if ( 0 !== strpos( (string) $intent_id, 'pi_' ) || ! class_exists( 'LNKup_Stripe_Client' ) ) {
			return $incomplete;
		}
		$client = new LNKup_Stripe_Client();
		$intent = $client->retrieve_payment_intent( $intent_id );
		if ( is_wp_error( $intent ) || ! is_array( $intent ) ) {
			return $incomplete;
		}

		$meta_payment = absint( $intent['metadata']['payment_post_id'] ?? 0 );
		$stored       = (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
		$bound        = $meta_payment ? ( $meta_payment === (int) $payment_id ) : ( $stored === $intent_id );
		if ( ! $bound ) {
			self::log_rejected_confirm( $payment_id, $intent_id, 'intent_not_for_payment' );
			return new WP_Error( 'payment_mismatch', __( 'That payment does not match this booking.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$status = (string) ( $intent['status'] ?? '' );
		if ( 'processing' === $status ) {
			return 'processing';
		}
		if ( 'succeeded' !== $status ) {
			return $incomplete;
		}

		$expected_map   = get_post_meta( $payment_id, '_lnkup_stripe_expected_cents', true );
		$expected_cents = is_array( $expected_map ) && isset( $expected_map[ $intent_id ] )
			? (int) $expected_map[ $intent_id ]
			: (int) round( self::stored_amount_due( $payment_id ) * 100 );
		$received_cents = (int) ( $intent['amount_received'] ?? $intent['amount'] ?? 0 );
		if ( ! self::charge_amount_ok( $payment_id, $intent_id, $received_cents, (string) ( $intent['currency'] ?? 'usd' ) ) ) {
			self::log_rejected_confirm(
				$payment_id,
				$intent_id,
				'amount_mismatch',
				array(
					'expected_cents' => $expected_cents,
					'received_cents' => $received_cents,
				)
			);
			return new WP_Error( 'payment_mismatch', __( 'The amount paid does not match this booking. Contact support.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		return 'succeeded';
	}

	/**
	 * Finalize a pending payment whose amount due is zero (free booth or fully covered by credits).
	 *
	 * @param int    $payment_id Payment post ID.
	 * @param string $source     Audit source label.
	 * @return array|WP_Error|null Null when the payment still has an amount due.
	 */
	public static function maybe_finalize_zero_amount( $payment_id, $source = 'zero_amount' ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id || 'lnkup_payment' !== get_post_type( $payment_id ) ) {
			return null;
		}
		if ( 'pending' !== (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) ) {
			return null;
		}
		if ( self::get_checkout_amount( $payment_id ) > 0 ) {
			return null;
		}
		$total  = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
		$source = $total > 0 ? 'credit_covered' : sanitize_key( $source );
		return self::finalize_payment( $payment_id, '', array( 'source' => $source ) );
	}

	/**
	 * Audit a refused client-side confirmation attempt.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $intent_id  Submitted intent ID.
	 * @param string $reason     Reason code.
	 * @param array  $extra      Extra context.
	 */
	private static function log_rejected_confirm( $payment_id, $intent_id, $reason, $extra = array() ) {
		if ( class_exists( 'LNKup_Financial_Audit' ) ) {
			LNKup_Financial_Audit::log(
				'confirm_rejected',
				'payment',
				$payment_id,
				array_merge(
					$extra,
					array(
						'reason'    => $reason,
						'intent_id' => (string) $intent_id,
						'user_id'   => get_current_user_id(),
					)
				)
			);
		}
		if ( class_exists( 'LNKup_Trust_Cases' ) ) {
			LNKup_Trust_Cases::open(
				array(
					'case_type'    => 'payment_mismatch',
					'severity'     => 'amount_mismatch' === $reason ? 'elevated' : 'review',
					'subject_type' => 'payment',
					'subject_id'   => absint( $payment_id ),
					'payment_id'   => absint( $payment_id ),
					'event_id'     => (int) get_post_meta( $payment_id, '_lnkup_event_id', true ),
					'signal_key'   => sanitize_key( $reason ),
					'summary'      => sprintf( 'Payment confirmation rejected (%s).', sanitize_key( $reason ) ),
					'evidence'     => array(
						array(
							'source'    => 'financial_service',
							'reason'    => sanitize_key( $reason ),
							'intent_id' => sanitize_text_field( (string) $intent_id ),
							'user_id'   => get_current_user_id(),
							'at'        => current_time( 'mysql', true ),
						),
					),
					'next_step'    => __( 'Compare the PaymentIntent in Stripe with this booking. The booking was not marked paid.', 'lnkup-marketplace' ),
				)
			);
		}
	}

	/**
	 * Whether a Stripe PaymentIntent has successfully collected funds.
	 *
	 * @param string $intent_id PaymentIntent ID.
	 */
	public static function stripe_intent_status( $intent_id ) {
		if ( ! $intent_id || 0 !== strpos( (string) $intent_id, 'pi_' ) || ! class_exists( 'LNKup_Stripe_Client' ) ) {
			return '';
		}
		$client = new LNKup_Stripe_Client();
		$intent = $client->retrieve_payment_intent( $intent_id );
		if ( is_wp_error( $intent ) ) {
			return '';
		}
		return (string) ( $intent['status'] ?? '' );
	}

	/**
	 * Whether a Stripe PaymentIntent has successfully collected funds.
	 *
	 * @param string $intent_id PaymentIntent ID.
	 */
	private static function stripe_intent_is_paid( $intent_id ) {
		// ACH may be "processing" — that is not paid / packet-unlock eligible.
		return 'succeeded' === self::stripe_intent_status( $intent_id );
	}

	/**
	 * Process refund through provider.
	 *
	 * @param int   $payment_id Payment ID.
	 * @param float $amount     Amount.
	 */
	public static function refund( $payment_id, $amount ) {
		return self::provider()->refund( $payment_id, $amount );
	}

	/**
	 * Handle Stripe webhook request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function handle_webhook( $request ) {
		$settings = lnkup_get_settings();
		$payload  = $request->get_body();
		$sig      = $request->get_header( 'stripe-signature' );
		$client   = new LNKup_Stripe_Client();
		// Platform endpoint and Connect endpoint each have their own signing secret.
		$secrets  = array_values(
			array_filter(
				array(
					trim( (string) ( $settings['stripe_webhook_secret'] ?? '' ) ),
					trim( (string) ( $settings['stripe_connect_webhook_secret'] ?? '' ) ),
				)
			)
		);

		$event = new WP_Error( 'not_configured', __( 'Webhook signing secret is not configured.', 'lnkup-marketplace' ) );
		foreach ( $secrets as $secret ) {
			$event = $client->verify_webhook( $payload, $sig, $secret );
			if ( ! is_wp_error( $event ) ) {
				break;
			}
		}

		if ( is_wp_error( $event ) ) {
			// Unsigned payloads are never accepted, including in test mode.
			return new WP_REST_Response( array( 'error' => $event->get_error_message() ), 400 );
		}

		if ( ! is_array( $event ) || empty( $event['type'] ) || empty( $event['id'] ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$log_id = LNKup_Stripe_Webhooks::log_event( $event );
		if ( LNKup_Stripe_Webhooks::is_event_processed( $log_id ) ) {
			return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
		}
		// Atomic claim: a concurrent delivery of the same event must not process it twice.
		if ( $log_id && ! LNKup_Stripe_Webhooks::claim_event( $log_id ) ) {
			return new WP_REST_Response( array( 'received' => true, 'duplicate' => true, 'in_flight' => true ), 200 );
		}
		$result = LNKup_Stripe_Webhooks::process_event( $event, $log_id );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
		}
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Vendor dashboard summary (delegates to LNKup_Financial).
	 *
	 * @param int $user_id User ID.
	 */
	public static function vendor_dashboard( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		$base    = LNKup_Financial::vendor_dashboard( $user_id );
		$report  = LNKup_Financial_Reports::vendor_report( $user_id );
		return array_merge( $base, $report, array(
			'money'  => self::vendor_money_contract( $user_id ),
			'charts' => array(
				'monthly_spending' => $report['by_month'] ?? array(),
			),
		) );
	}

	/**
	 * Organizer dashboard summary.
	 *
	 * @param int $user_id User ID.
	 */
	public static function organizer_dashboard( $user_id = 0 ) {
		$uid = $user_id ?: get_current_user_id();

		$data = array(
			'vendor_revenue'    => 0,
			'pending_revenue'   => 0,
			'available_balance' => 0,
			'wallet_pending'    => 0,
			'reserve_hold'      => 0,
			'payout_count'      => 0,
			'refund_requests'   => 0,
			'open_disputes'     => 0,
		);
		try {
			$data = LNKup_Financial::organizer_dashboard( $uid );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup organizer_dashboard data: ' . $e->getMessage() );
			}
		}

		$reports = array(
			'collected'       => 0,
			'refunds'         => 0,
			'outstanding'     => 0,
			'open_disputes'   => 0,
			'by_event'        => array(),
			'by_month'        => array(),
			'by_category'     => array(),
			'completion_rate' => 0,
		);
		try {
			// Organizers only ever see amounts after LNKup fees.
			$reports = LNKup_Financial_Reports::organizer_report( $uid, true );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup organizer_dashboard reports: ' . $e->getMessage() );
			}
		}

		$balance_payload  = self::organizer_balance_payload( $uid );
		$wallet           = $balance_payload['wallet'];
		$stripe_balance   = $balance_payload['stripe_balance'];
		$display_balances = $balance_payload['display_balances'];
		$connect          = $balance_payload['connect'];
		$sponsor_revenue = 0;
		if ( class_exists( 'LNKup_Sponsor_Payments' ) ) {
			try {
				$sponsor_revenue = LNKup_Sponsor_Payments::total_revenue( $uid );
			} catch ( Throwable $e ) {
				$sponsor_revenue = 0;
			}
		}
		$payouts_paid = 0;
		if ( class_exists( 'LNKup_Organizer_Payouts' ) ) {
			try {
				$payouts = LNKup_Organizer_Payouts::list_for_user( $uid, 100 );
				foreach ( (array) $payouts as $p ) {
					if ( in_array( $p['status'] ?? '', array( 'completed', 'paid' ), true ) ) {
						$payouts_paid += (float) ( $p['net_amount'] ?? $p['amount'] ?? 0 );
					}
				}
				$payouts_paid = round( $payouts_paid, 2 );
			} catch ( Throwable $e ) {
				$payouts_paid = 0;
			}
		}
		$health = array();
		if ( class_exists( 'LNKup_Financial_Health_Score' ) ) {
			try {
				$health = LNKup_Financial_Health_Score::get_latest( $uid, 'organizer' );
			} catch ( Throwable $e ) {
				$health = array();
			}
		}
		$collected     = (float) ( $reports['collected'] ?? 0 );
		$refunds_total = (float) ( $reports['refunds'] ?? 0 );
		$categories    = self::organizer_money_categories(
			array(
				'collected'  => $collected,
				'refunds'    => $refunds_total,
				'pending'    => (float) ( $display_balances['pending'] ?? $wallet['pending'] ?? 0 ),
				'available'  => (float) ( $display_balances['available'] ?? $wallet['available'] ?? 0 ),
				'in_transit' => (float) ( $display_balances['processing'] ?? $wallet['processing'] ?? 0 ),
				'paid_out'   => $payouts_paid,
				'synced'     => ! empty( $stripe_balance ) && empty( $stripe_balance['error'] ),
			)
		);
		$money = self::organizer_money_contract(
			$uid,
			$balance_payload,
			array(
				'collected'  => $collected,
				'after_fees' => true,
				'refunds'    => $refunds_total,
			)
		);
		return array_merge( $data, array(
			'money'               => $money,
			'money_categories'    => $categories,
			'vendor_revenue'      => $collected,
			'gross_revenue'       => $collected,
			'collected'           => $collected,
			'refunds'             => $reports['refunds'] ?? 0,
			'outstanding'         => $reports['outstanding'] ?? 0,
			'pending_balance'     => (float) ( $display_balances['pending'] ?? $wallet['pending'] ?? $data['wallet_pending'] ?? 0 ),
			'processing_balance'  => (float) ( $display_balances['processing'] ?? $wallet['processing'] ?? 0 ),
			'reserve_balance'     => (float) ( $display_balances['reserve_hold'] ?? $wallet['reserve_hold'] ?? $data['reserve_hold'] ?? 0 ),
			'available_balance'   => (float) ( $display_balances['available'] ?? $wallet['available'] ?? $data['available_balance'] ?? 0 ),
			'display_balances'    => $display_balances,
			'payouts_paid'        => $payouts_paid,
			'open_invoices'       => (int) ( $reports['outstanding'] ?? 0 ) > 0 ? 1 : 0,
			'overdue_invoices'    => 0,
			'chargebacks'           => (int) ( $reports['open_disputes'] ?? $data['open_disputes'] ?? 0 ),
			'wallet'                => $wallet,
			'health_score'          => $health,
			'sponsor_revenue'       => $sponsor_revenue,
			'connect'               => $connect,
			'stripe_balance'        => $stripe_balance,
			'connect_status'        => array(
				'enabled'    => ( 'active' === ( $connect['status'] ?? '' ) ),
				'status'     => $connect['status'] ?? 'not_started',
				'settlement' => $connect['settlement_mode'] ?? 'destination',
			),
			'charts'                => array(
				'revenue_by_event'  => $reports['by_event'] ?? array(),
				'revenue_by_month'  => $reports['by_month'] ?? array(),
				'vendor_categories' => $reports['by_category'] ?? array(),
				'completion_rate'   => $reports['completion_rate'] ?? 0,
			),
		) );
	}

	/**
	 * Organizer money in flow order. Values come from the booking ledger (collected after LNKup fees,
	 * refunds) and the payout account / wallet (pending, available, in transit, paid out); nothing is
	 * estimated. Organizers never see platform fee amounts or pre-fee totals.
	 *
	 * @param array<string, float|bool> $v Raw values.
	 * @return array<int, array{key:string,label:string,amount:float,source:string,hint:string}>
	 */
	private static function organizer_money_categories( $v ) {
		$balance_source = ! empty( $v['synced'] ) ? 'payout_account' : 'ledger';
		$rows           = array(
			array( 'net', __( 'Earnings', 'lnkup-marketplace' ), max( 0, $v['collected'] - $v['refunds'] ), 'ledger', __( "What you've earned from paid booths, after refunds.", 'lnkup-marketplace' ) ),
			array( 'refunds', __( 'Refunds', 'lnkup-marketplace' ), $v['refunds'], 'ledger', __( 'Money returned to vendors.', 'lnkup-marketplace' ) ),
			array( 'pending', __( 'Pending', 'lnkup-marketplace' ), $v['pending'], $balance_source, __( 'Still settling. Pending money is not available to cash out yet.', 'lnkup-marketplace' ) ),
			array( 'available', __( 'Available to cash out', 'lnkup-marketplace' ), $v['available'], $balance_source, __( 'Ready to send to your bank.', 'lnkup-marketplace' ) ),
			array( 'in_transit', __( 'In transit', 'lnkup-marketplace' ), $v['in_transit'], $balance_source, __( 'Cashouts on the way to your bank.', 'lnkup-marketplace' ) ),
			array( 'paid_out', __( 'Paid out', 'lnkup-marketplace' ), $v['paid_out'], 'ledger', __( 'Cashouts that reached your bank.', 'lnkup-marketplace' ) ),
		);
		return array_map(
			static function ( $row ) {
				return array(
					'key'    => $row[0],
					'label'  => $row[1],
					'amount' => round( (float) $row[2], 2 ),
					'source' => $row[3],
					'hint'   => $row[4],
				);
			},
			$rows
		);
	}

	/**
	 * Admin dashboard summary.
	 */
	public static function admin_dashboard() {
		return LNKup_Financial::admin_dashboard();
	}

	/**
	 * Calculate payout split for organizer.
	 *
	 * @param float $gross      Gross amount.
	 * @param int   $organizer_id Organizer ID.
	 */
	public static function calculate_payout_split( $gross, $organizer_id ) {
		return LNKup_Stripe_Connect::calculate_payout_split( $gross, $organizer_id );
	}

	/**
	 * Payment timeline for payment.
	 *
	 * @param int $payment_id Payment ID.
	 */
	public static function get_timeline( $payment_id ) {
		return LNKup_Payment_Timeline::list_for_payment( $payment_id );
	}

	/**
	 * Activity feed for user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role.
	 * @param int    $limit   Limit.
	 */
	public static function get_activity( $user_id, $role = 'vendor', $limit = 25 ) {
		return LNKup_Financial_Activity::list_for_user( $user_id, $role, $limit );
	}

	/**
	 * Zero-state organizer dashboard when data layer fails.
	 *
	 * @param int $user_id User ID.
	 */
	public static function organizer_dashboard_zero( $user_id = 0 ) {
		$uid             = $user_id ?: get_current_user_id();
		$balance_payload = self::organizer_balance_payload( $uid );
		$wallet          = $balance_payload['wallet'];
		$display         = $balance_payload['display_balances'];
		$connect         = $balance_payload['connect'];
		$stripe_balance  = $balance_payload['stripe_balance'];
		return array(
			'vendor_revenue'     => 0,
			'gross_revenue'      => 0,
			'available_balance'  => (float) ( $display['available'] ?? 0 ),
			'pending_balance'    => (float) ( $display['pending'] ?? 0 ),
			'wallet_pending'     => (float) ( $display['pending'] ?? 0 ),
			'processing_balance' => (float) ( $display['processing'] ?? 0 ),
			'reserve_balance'    => (float) ( $display['reserve_hold'] ?? 0 ),
			'reserve_hold'       => (float) ( $display['reserve_hold'] ?? 0 ),
			'stripe_fees'        => 0,
			'payouts_paid'       => 0,
			'refunds'            => 0,
			'chargebacks'        => 0,
			'collected'          => 0,
			'outstanding'        => 0,
			'pending_revenue'    => 0,
			'open_invoices'      => 0,
			'overdue_invoices'   => 0,
			'sponsor_revenue'    => 0,
			'payout_count'       => 0,
			'wallet'             => $wallet,
			'display_balances'   => $display,
			'stripe_balance'     => $stripe_balance,
			'connect'            => $connect,
			'connect_status'     => array(
				'enabled'    => ( 'active' === ( $connect['status'] ?? '' ) ),
				'status'     => $connect['status'] ?? 'not_started',
				'settlement' => $connect['settlement_mode'] ?? 'destination',
			),
			'health_score'       => array(),
			'charts'             => array(
				'revenue_by_event' => array(),
				'revenue_by_month' => array(),
			),
		);
	}
}
