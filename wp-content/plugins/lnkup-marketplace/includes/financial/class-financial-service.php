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
		LNKup_Payments::refresh_pending_fees( $payment_id );
		$mode = $args['mode'] ?? 'embedded';
		if ( 'redirect' === $mode ) {
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

	public static function finalize_payment( $payment_id, $intent_id = '', $context = array() ) {
		$status = get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( 'paid' === $status ) {
			if ( class_exists( 'LNKup_Financial_Audit' ) ) {
				LNKup_Financial_Audit::log( 'finalize_skipped', 'payment', $payment_id, array_merge( $context, array( 'reason' => 'already_paid' ) ) );
			}
			return array( 'already_paid' => true );
		}

		if ( $intent_id ) {
			self::backfill_stripe_payment_meta( $payment_id, $intent_id );
		}

		LNKup_Payments::mark_paid( $payment_id, $intent_id );
		LNKup_Transactions::sync_extended_fields( $payment_id );

		self::settle_treasury( $payment_id );

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

		self::backfill_stripe_payment_meta( $payment_id, $intent_id );

		$status = get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( 'paid' !== $status ) {
			$intent_id = $intent_id ?: (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
			if ( $intent_id && 0 === strpos( $intent_id, 'pi_' ) ) {
				$si_status = self::stripe_intent_status( $intent_id );
				if ( 'processing' === $si_status ) {
					self::mark_payment_processing( $payment_id, $intent_id, array( 'source' => 'reconcile' ) );
					return new WP_Error(
						'payment_processing',
						__( 'Bank payment is still processing. Packet unlocks when funds clear.', 'lnkup-marketplace' ),
						array( 'status' => 409 )
					);
				}
				if ( 'succeeded' !== $si_status ) {
					return new WP_Error(
						'payment_incomplete',
						__( 'Stripe payment is not complete yet.', 'lnkup-marketplace' ),
						array( 'status' => 400 )
					);
				}
			}
			return self::finalize_payment( $payment_id, $intent_id, array( 'source' => 'reconcile' ) );
		}

		$mode = get_post_meta( $payment_id, '_lnkup_settlement_mode', true );
		if ( 'destination' === $mode && class_exists( 'LNKup_Organizer_Wallet' ) ) {
			if ( ! LNKup_Organizer_Wallet::has_connect_settlement_for_payment( $payment_id ) ) {
				self::settle_treasury( $payment_id );
				return array( 'success' => true, 'action' => 'settlement_backfilled', 'payment_id' => $payment_id );
			}
		}

		return array( 'success' => true, 'action' => 'already_settled', 'payment_id' => $payment_id );
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

			if ( 'paid' === $status ) {
				$mode     = (string) get_post_meta( $payment_id, '_lnkup_settlement_mode', true );
				$dest     = (string) get_post_meta( $payment_id, '_lnkup_stripe_destination_account', true );
				$has_cs   = class_exists( 'LNKup_Organizer_Wallet' ) && LNKup_Organizer_Wallet::has_connect_settlement_for_payment( $payment_id );
				$has_row  = class_exists( 'LNKup_Organizer_Wallet' ) && LNKup_Organizer_Wallet::has_wallet_entry_for_payment( $payment_id );

				if ( $has_cs ) {
					continue;
				}

				// Destination charge missing connect_settlement mirror.
				if ( ( 'destination' === $mode || '' !== $dest ) && '' !== $dest && ! $has_cs ) {
					$result = self::reconcile_payment( $payment_id, $intent_id );
					if ( ! is_wp_error( $result ) ) {
						$reconciled++;
					}
					continue;
				}

				// Platform charge (no destination) missing any ledger — settle treasury credit.
				if ( '' === $dest && ! $has_row ) {
					self::settle_treasury( $payment_id );
					$reconciled++;
				}
				continue;
			} elseif ( ! $intent_id ) {
				continue;
			}

			$result = self::reconcile_payment( $payment_id, $intent_id );
			if ( ! is_wp_error( $result ) ) {
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
			if ( 'paid' === get_post_meta( $payment_id, '_lnkup_payment_status', true ) ) {
				continue;
			}
			$intent_id = (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
			if ( ! $intent_id ) {
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

		$client = new LNKup_Stripe_Client();
		$intent = $client->retrieve_payment_intent( $intent_id );
		if ( is_wp_error( $intent ) ) {
			return $intent;
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
		if ( $amount_cents > 0 && $app_fee_cents >= 0 ) {
			update_post_meta( $payment_id, '_lnkup_stripe_destination_amount', ( $amount_cents - $app_fee_cents ) / 100 );
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
		$breakdown    = LNKup_Fee_Engine::calculate_for_payment( $payment_id );
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
		LNKup_Financial_Audit::log( 'treasury_settled', 'payment', $payment_id, array_merge( $breakdown, array( 'settlement_mode' => $mode ) ) );
	}

	/**
	 * Request organizer cashout.
	 *
	 * @param int    $user_id Organizer user ID.
	 * @param float  $amount  Amount.
	 * @param string $type    Payout type.
	 */
	public static function request_organizer_cashout( $user_id, $amount, $type = 'standard' ) {
		return LNKup_Organizer_Payouts::request_cashout( $user_id, $amount, $type );
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
			'balances'         => $balances,
			'display_balances' => $payload['display_balances'],
			'entries'          => LNKup_Organizer_Wallet::list_entries( $user_id ),
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

		// Ensure platform-settled booth payments get ledger claims before balance reads.
		$lock = 'lnkup_org_wallet_reconcile_' . absint( $user_id );
		if ( $user_id && ! get_transient( $lock ) ) {
			try {
				self::reconcile_organizer_payments( $user_id );
				if ( class_exists( 'LNKup_Transactions' ) ) {
					LNKup_Transactions::backfill_amounts_from_payments( $user_id );
				}
			} catch ( Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'LNKup reconcile on organizer wallet: ' . $e->getMessage() );
				}
			}
			set_transient( $lock, 1, MINUTE_IN_SECONDS );
		}

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

		if ( 0 === strpos( $intent_id, 'sim_' ) || self::stripe_intent_is_paid( $intent_id ) ) {
			return self::finalize_payment( $payment_id, $intent_id, array( 'source' => 'client_confirm' ) );
		}

		return new WP_Error(
			'payment_incomplete',
			__( 'Payment not completed yet. If you were charged, wait a moment and refresh, or contact support.', 'lnkup-marketplace' ),
			array( 'status' => 400 )
		);
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
		$secret   = trim( (string) ( $settings['stripe_webhook_secret'] ?? '' ) );
		$event    = $client->verify_webhook( $payload, $sig, $secret );

		if ( is_wp_error( $event ) ) {
			// Signing secret configured ⇒ never accept unsigned payloads (even in test_mode).
			if ( '' !== $secret ) {
				return new WP_REST_Response( array( 'error' => $event->get_error_message() ), 400 );
			}
			// Dev-only: unsigned JSON allowed only when no secret AND test_mode is on.
			if ( empty( $settings['test_mode'] ) ) {
				return new WP_REST_Response( array( 'error' => $event->get_error_message() ), 400 );
			}
			$event = json_decode( $payload, true );
		}

		if ( empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$log_id = LNKup_Stripe_Webhooks::log_event( $event );
		if ( LNKup_Stripe_Webhooks::is_event_processed( $log_id ) ) {
			return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
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
		$reconcile_lock = 'lnkup_vendor_reconcile_' . $user_id;
		if ( ! get_transient( $reconcile_lock ) ) {
			self::reconcile_vendor_payments( $user_id );
			set_transient( $reconcile_lock, 1, MINUTE_IN_SECONDS );
		}
		$base    = LNKup_Financial::vendor_dashboard( $user_id );
		$report  = LNKup_Financial_Reports::vendor_report( $user_id );
		return array_merge( $base, $report, array(
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
			'gross_revenue'   => 0,
			'platform_fees'   => 0,
			'refunds'         => 0,
			'outstanding'     => 0,
			'open_disputes'   => 0,
			'by_event'        => array(),
			'by_month'        => array(),
			'by_category'     => array(),
			'completion_rate' => 0,
		);
		try {
			$reports = LNKup_Financial_Reports::organizer_report( $uid );
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
		return array_merge( $data, array(
			'gross_revenue'       => max(
				(float) ( $reports['gross_revenue'] ?? 0 ),
				(float) ( $data['vendor_revenue'] ?? 0 )
			),
			'platform_fees'       => $reports['platform_fees'] ?? 0,
			'collected'           => (float) ( $data['vendor_revenue'] ?? 0 ),
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
			'platform_fees'      => 0,
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
