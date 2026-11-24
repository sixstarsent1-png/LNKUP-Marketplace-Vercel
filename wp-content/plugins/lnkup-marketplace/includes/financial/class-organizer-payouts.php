<?php
/**
 * Organizer cashout requests and payout execution.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Payouts {

	const INSTANT_FEE_RATE = 0.015;

	const TYPES = array( 'standard', 'instant' );

	/**
	 * LNKup's instant cash-out fee rate for payouts LNKup sends (not Stripe Connect payouts, where
	 * Stripe charges its own fee). Configurable as `organizer_instant_fee_rate`; defaults to the
	 * long-standing 1.5% so existing policy is unchanged.
	 */
	public static function instant_fee_rate() {
		$settings = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		$rate     = isset( $settings['organizer_instant_fee_rate'] ) && is_numeric( $settings['organizer_instant_fee_rate'] )
			? (float) $settings['organizer_instant_fee_rate']
			: self::INSTANT_FEE_RATE;
		return max( 0.0, min( 0.1, $rate ) );
	}

	/**
	 * Provider a cash-out would use for this organizer right now.
	 *
	 * @param int    $user_id         Organizer.
	 * @param string $settlement_mode destination|platform_treasury.
	 */
	public static function provider_for( $user_id, $settlement_mode ) {
		if ( ! class_exists( 'LNKup_Stripe_Connect' ) ) {
			return 'manual';
		}
		if ( 'destination' === $settlement_mode ) {
			return 'stripe_connect_payout';
		}
		return LNKup_Stripe_Connect::is_connect_ready( $user_id ) ? 'stripe_connect' : 'manual';
	}

	/**
	 * LNKup fee for a cash-out. Connect payouts leave the organizer's own Stripe balance, so LNKup
	 * keeps nothing there.
	 *
	 * @param float  $amount   Amount.
	 * @param string $type     standard|instant.
	 * @param string $provider Provider.
	 */
	public static function lnkup_fee( $amount, $type, $provider ) {
		$rate = 'instant' === $type && 'stripe_connect_payout' !== $provider ? self::instant_fee_rate() : 0.0;
		return round( (float) $amount * $rate, 2 );
	}

	/**
	 * Read-only cash-out preview: revalidates payout setup and the available balance and returns
	 * the fees LNKup would take. Writes nothing and never contacts Stripe for a payout.
	 *
	 * @param int    $user_id Organizer.
	 * @param float  $amount  Requested amount (0 = report limits only).
	 * @param string $type    standard|instant.
	 * @return array<string,mixed>
	 */
	public static function preview( $user_id, $amount, $type ) {
		$user_id = absint( $user_id );
		$amount  = round( max( 0.0, (float) $amount ), 2 );
		$type    = in_array( $type, self::TYPES, true ) ? $type : 'standard';
		$mode    = class_exists( 'LNKup_Stripe_Connect' ) ? LNKup_Stripe_Connect::settlement_mode_for_organizer( $user_id ) : 'platform_treasury';
		$problem = '';
		if ( 'destination' === $mode ) {
			if ( ! LNKup_Stripe_Connect::is_connect_ready( $user_id ) ) {
				$problem = 'connect_not_ready';
			} elseif ( LNKup_Stripe_Connect::has_past_due_requirements( $user_id ) ) {
				$problem = 'requirements_past_due';
			}
		}
		$available = null;
		if ( '' === $problem && class_exists( 'LNKup_Financial_Service' ) ) {
			$avail = LNKup_Financial_Service::organizer_cashout_available( $user_id, 'destination' === $mode ? $type : 'standard' );
			if ( is_wp_error( $avail ) ) {
				$problem = $avail->get_error_code();
			} else {
				$available = round( (float) $avail, 2 );
			}
		}
		$provider = self::provider_for( $user_id, $mode );
		$fee      = self::lnkup_fee( $amount, $type, $provider );
		$net      = round( $amount - $fee, 2 );
		if ( '' === $problem && $amount > 0 ) {
			if ( null !== $available && $amount > $available ) {
				$problem = 'insufficient_balance';
			} elseif ( 'instant' === $type && $net <= 0 ) {
				$problem = 'amount_below_fee';
			}
		}
		$provider_note = '';
		if ( 'instant' === $type && 'stripe_connect_payout' === $provider ) {
			$provider_note = __( 'Stripe deducts its instant payout fee from this payout. LNKup does not take a fee on it.', 'lnkup-marketplace' );
		}
		$timing = 'instant' === $type
			? __( 'Instant payouts usually arrive within minutes, but timing depends on your bank.', 'lnkup-marketplace' )
			: __( 'Standard payouts usually arrive in 1 to 3 business days.', 'lnkup-marketplace' );
		return array(
			'type'                => $type,
			'amount'              => $amount,
			'available'           => $available,
			'fee_amount'          => $fee,
			'fee_rate'            => 'instant' === $type && 'stripe_connect_payout' !== $provider ? self::instant_fee_rate() : 0.0,
			'provider_fee_note'   => $provider_note,
			'estimated_net'       => $net,
			'net_is_estimate'     => '' !== $provider_note,
			'method'              => 'destination' === $mode ? 'connect' : 'lnkup',
			'destination_summary' => self::destination_summary( $user_id, $mode ),
			'timing_note'         => $timing,
			'can_submit'          => '' === $problem && $amount > 0,
			'problem'             => $problem,
		);
	}

	/**
	 * Masked payout destination from the connected account, when Stripe has reported one.
	 *
	 * @param int    $user_id Organizer.
	 * @param string $mode    Settlement mode.
	 */
	private static function destination_summary( $user_id, $mode ) {
		if ( 'destination' !== $mode && ! ( class_exists( 'LNKup_Stripe_Connect' ) && LNKup_Stripe_Connect::is_connect_ready( $user_id ) ) ) {
			return __( 'Paid out by LNKup to the account on file', 'lnkup-marketplace' );
		}
		$last4 = (string) get_user_meta( $user_id, '_lnkup_connect_external_last4', true );
		$bank  = (string) get_user_meta( $user_id, '_lnkup_connect_external_bank', true );
		if ( '' !== $last4 ) {
			/* translators: 1: bank name, 2: last four digits */
			return trim( sprintf( __( '%1$s account ending in %2$s', 'lnkup-marketplace' ), '' !== $bank ? $bank : __( 'Bank', 'lnkup-marketplace' ), $last4 ) );
		}
		return __( 'The bank account on your payout profile', 'lnkup-marketplace' );
	}

	/**
	 * Request cashout from organizer wallet.
	 *
	 * @param int    $user_id Organizer user ID.
	 * @param float  $amount  Requested amount.
	 * @param string $type    Payout type.
	 */
	public static function request_cashout( $user_id, $amount, $type = 'standard', $client_token = '' ) {
		global $wpdb;
		// Token characters are limited so the JSON-encoded metadata matches it literally.
		$client_token = substr( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $client_token ), 0, 64 );
		$user_id = absint( $user_id );
		$amount  = round( (float) $amount, 2 );
		$type    = sanitize_key( (string) $type );
		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_amount', __( 'Invalid cashout amount.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'invalid_payout_type', __( 'Choose a standard or instant cashout.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( 'instant' === $type && round( $amount - round( $amount * self::instant_fee_rate(), 2 ), 2 ) <= 0 ) {
			return new WP_Error( 'amount_below_fee', __( 'This amount is too small for an instant cashout.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$lock = 'lnkup_cashout_' . $user_id;
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'busy', __( 'A cashout is already being processed. Refresh in a moment.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		try {
			if ( '' !== $client_token ) {
				$existing = self::payout_for_client_token( $user_id, $client_token );
				if ( $existing ) {
					// A replay reports what actually happened to the original request.
					if ( in_array( $existing['status'], array( 'failed', 'cancelled' ), true ) ) {
						return new WP_Error( 'payout_failed', __( 'This cashout did not go through. Your balance was restored.', 'lnkup-marketplace' ), array( 'status' => 409, 'payout_id' => (int) $existing['id'], 'payout_status' => $existing['status'] ) );
					}
					return (int) $existing['id'];
				}
			}
			return self::request_cashout_locked( $user_id, $amount, $type, $client_token );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Balance check, insert and debit for one organizer; caller holds the cashout lock so two
	 * concurrent requests cannot both spend the same available balance.
	 *
	 * @param int    $user_id Organizer user ID.
	 * @param float  $amount  Amount.
	 * @param string $type    Validated payout type.
	 */
	private static function payout_for_client_token( $user_id, $client_token ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_payouts_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE user_id = %d AND metadata LIKE %s ORDER BY id DESC LIMIT 1", absint( $user_id ), '%' . $wpdb->esc_like( '"client_token":"' . $client_token . '"' ) . '%' ), ARRAY_A );
		return $row ? $row : null;
	}

	private static function request_cashout_locked( $user_id, $amount, $type, $client_token = '' ) {
		$settlement_mode = class_exists( 'LNKup_Stripe_Connect' )
			? LNKup_Stripe_Connect::settlement_mode_for_organizer( $user_id )
			: 'platform_treasury';

		if ( 'destination' === $settlement_mode ) {
			if ( ! LNKup_Stripe_Connect::is_connect_ready( $user_id ) ) {
				return new WP_Error( 'connect_not_ready', __( 'Finish payout setup before cashing out.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			if ( LNKup_Stripe_Connect::has_past_due_requirements( $user_id ) ) {
				return new WP_Error( 'requirements_past_due', __( 'Finish your payout verification before cashing out.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
		}

		$available = class_exists( 'LNKup_Financial_Service' )
			? LNKup_Financial_Service::organizer_cashout_available( $user_id, 'destination' === $settlement_mode ? $type : 'standard' )
			: (float) ( LNKup_Organizer_Wallet::get_balances( $user_id )['available'] ?? 0 );
		if ( is_wp_error( $available ) ) {
			$data = $available->get_error_data();
			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				$available->add_data( array( 'status' => 400 ) );
			}
			return $available;
		}
		if ( $amount > (float) $available ) {
			return new WP_Error(
				'insufficient_balance',
				'instant' === $type
					/* translators: %s: instant-eligible amount */
					? sprintf( __( 'Only %s can be cashed out instantly right now.', 'lnkup-marketplace' ), lnkup_currency( (float) $available ) )
					: __( 'Insufficient available balance.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}

		if ( class_exists( 'LNKup_Approval_Workflows' ) ) {
			$approval = LNKup_Approval_Workflows::maybe_require( 'payout', $amount, $user_id );
			if ( is_wp_error( $approval ) ) {
				return $approval;
			}
		}

		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_payouts_table();
		$now   = current_time( 'mysql' );
		$provider = self::provider_for( $user_id, $settlement_mode );

		// A Connect payout leaves the organizer's own Stripe balance, so LNKup cannot keep a fee out
		// of it (Stripe charges its instant fee there directly); the full debited amount is paid out.
		$fee = self::lnkup_fee( $amount, $type, $provider );
		$net = round( $amount - $fee, 2 );

		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'      => $user_id,
				'amount'       => $amount,
				'fee_amount'   => $fee,
				'net_amount'   => $net,
				'payout_type'  => $type,
				'status'       => 'requested',
				'provider'     => $provider,
				'metadata'     => '' !== $client_token ? wp_json_encode( array( 'client_token' => $client_token ) ) : null,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		if ( false === $inserted || ! $wpdb->insert_id ) {
			return new WP_Error(
				'payout_failed',
				__( 'Cashout could not be recorded. Your balance was not changed.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}
		$payout_id = (int) $wpdb->insert_id;

		$debited = LNKup_Organizer_Wallet::debit( $user_id, $amount, array(
			'source_type' => 'payout',
			'source_id'   => $payout_id,
			'payout_id'   => $payout_id,
			'note'        => __( 'Cashout requested', 'lnkup-marketplace' ),
		) );
		if ( ! $debited ) {
			// Never send money the ledger did not take out of the balance.
			$wpdb->update( $table, array( 'status' => 'failed', 'failure_reason' => 'ledger_debit_failed', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $payout_id ) );
			return new WP_Error( 'payout_failed', __( 'Cashout could not be recorded. Your balance was not changed.', 'lnkup-marketplace' ), array( 'status' => 400, 'payout_id' => $payout_id ) );
		}

		$ok = self::process_payout( $payout_id );
		LNKup_Financial_Audit::log( 'organizer_cashout_requested', 'payout', $payout_id, array( 'amount' => $amount ) );
		if ( is_wp_error( $ok ) ) {
			$data = $ok->get_error_data();
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			$data['status']    = ! empty( $data['status'] ) ? (int) $data['status'] : 400;
			$data['payout_id'] = $payout_id;
			$code              = $ok->get_error_code() ? $ok->get_error_code() : 'payout_failed';
			$message           = trim( (string) $ok->get_error_message() );
			if ( '' === $message ) {
				$message = __( 'Cashout failed. Please try again or contact support.', 'lnkup-marketplace' );
				$code    = 'payout_failed';
			}
			return new WP_Error( $code, $message, $data );
		}
		if ( ! $ok ) {
			return new WP_Error(
				'payout_failed',
				__( 'Cashout failed. Please try again or contact support.', 'lnkup-marketplace' ),
				array( 'status' => 400, 'payout_id' => $payout_id )
			);
		}
		return $payout_id;
	}

	/**
	 * Execute payout via Connect or manual queue.
	 *
	 * @param int $payout_id Payout row ID.
	 * @return true|WP_Error True on success; WP_Error with the live failure message on failure.
	 */
	public static function process_payout( $payout_id ) {
		global $wpdb;
		$table  = LNKup_Treasury_Tables::organizer_payouts_table();
		$payout = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $payout_id ) ), ARRAY_A );
		if ( ! $payout ) {
			return new WP_Error(
				'payout_not_found',
				__( 'Cashout request was not found.', 'lnkup-marketplace' ),
				array( 'status' => 400, 'payout_id' => absint( $payout_id ) )
			);
		}
		$invalid_status = new WP_Error(
			'payout_invalid_status',
			sprintf(
				/* translators: %s: payout status slug */
				__( 'Cashout cannot be processed from status “%s”.', 'lnkup-marketplace' ),
				(string) $payout['status']
			),
			array( 'status' => 400, 'payout_id' => absint( $payout_id ), 'payout_status' => (string) $payout['status'] )
		);
		if ( ! in_array( $payout['status'], array( 'requested', 'approved' ), true ) ) {
			return $invalid_status;
		}

		// Atomic claim: a concurrent request that already moved the row must not pay out twice.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'processing', updated_at = %s WHERE id = %d AND status IN ('requested','approved')",
				current_time( 'mysql' ),
				absint( $payout_id )
			)
		);
		if ( 1 !== (int) $claimed ) {
			return $invalid_status;
		}

		if ( 'stripe_connect_payout' === $payout['provider'] && class_exists( 'LNKup_Stripe_Connect' ) ) {
			$result = LNKup_Stripe_Connect::create_connect_payout(
				(int) $payout['user_id'],
				(float) $payout['net_amount'],
				$payout['payout_type'],
				array( 'payout_id' => (string) $payout_id )
			);
			if ( is_wp_error( $result ) ) {
				self::mark_payout_failed( $payout_id, $result );
				self::restore_failed_cashout( (int) $payout['user_id'], (float) $payout['amount'], $payout_id );
				return $result;
			}
			if ( ! empty( $result['payout_id'] ) ) {
				$wpdb->update(
					$table,
					array( 'provider_ref' => sanitize_text_field( $result['payout_id'] ), 'updated_at' => current_time( 'mysql' ) ),
					array( 'id' => $payout_id )
				);
			}
			return true;
		}

		if ( 'stripe_connect' === $payout['provider'] && class_exists( 'LNKup_Stripe_Connect' ) ) {
			$result = LNKup_Stripe_Connect::schedule_transfer( (int) $payout['user_id'], (float) $payout['net_amount'], array( 'payout_id' => $payout_id ) );
			if ( is_wp_error( $result ) ) {
				self::mark_payout_failed( $payout_id, $result );
				self::restore_failed_cashout( (int) $payout['user_id'], (float) $payout['amount'], $payout_id );
				return $result;
			}
			if ( ! empty( $result['transfer_id'] ) ) {
				self::mark_paid( $payout_id, $result['transfer_id'] );
				return true;
			}
			$err = new WP_Error(
				'transfer_incomplete',
				__( 'Payout transfer did not return a transfer ID. Please try again or contact support.', 'lnkup-marketplace' ),
				array( 'status' => 400, 'payout_id' => absint( $payout_id ) )
			);
			self::mark_payout_failed( $payout_id, $err );
			self::restore_failed_cashout( (int) $payout['user_id'], (float) $payout['amount'], $payout_id );
			return $err;
		}

		$wpdb->update( $table, array( 'status' => 'approved', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $payout_id ) );
		return true;
	}

	/**
	 * Persist failed payout status + full failure_reason (+ metadata hints).
	 *
	 * @param int      $payout_id Payout row ID.
	 * @param WP_Error $error     Failure.
	 */
	private static function mark_payout_failed( $payout_id, $error ) {
		global $wpdb;
		$table   = LNKup_Treasury_Tables::organizer_payouts_table();
		$message = is_wp_error( $error ) ? (string) $error->get_error_message() : '';
		if ( '' === trim( $message ) ) {
			$message = __( 'Cashout failed. Please try again or contact support.', 'lnkup-marketplace' );
		}

		$data     = is_wp_error( $error ) ? $error->get_error_data() : array();
		$meta     = array();
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT metadata FROM {$table} WHERE id = %d", absint( $payout_id ) ) );
		if ( $existing ) {
			$decoded = json_decode( (string) $existing, true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		$meta['failure_code'] = is_wp_error( $error ) ? (string) $error->get_error_code() : 'payout_failed';
		if ( is_array( $data ) ) {
			if ( ! empty( $data['attempted'] ) ) {
				$meta['attempted'] = $data['attempted'];
			}
			if ( ! empty( $data['source_types'] ) ) {
				$meta['source_types'] = $data['source_types'];
			}
			if ( ! empty( $data['stripe_code'] ) ) {
				$meta['stripe_code'] = $data['stripe_code'];
			}
		}

		$wpdb->update(
			$table,
			array(
				'status'         => 'failed',
				'failure_reason' => $message,
				'metadata'       => wp_json_encode( $meta ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => absint( $payout_id ) )
		);
	}

	/**
	 * Restore balance after failed Connect payout.
	 *
	 * @param int   $user_id            Organizer user ID.
	 * @param float $amount             Amount.
	 * @param int   $payout_id          Payout ID.
	 * @param bool  $release_processing False when mark_paid() already released processing_balance.
	 * @param bool  $cancelled          True when the organizer cancelled (vs. a failed payout).
	 */
	private static function restore_failed_cashout( $user_id, $amount, $payout_id, $release_processing = true, $cancelled = false ) {
		global $wpdb;
		$wallet = LNKup_Organizer_Wallet::get_or_create( $user_id );
		$key    = 'payout:' . absint( $payout_id ) . ':restore';
		$source = $cancelled ? 'payout_cancel' : 'payout_failed';
		$note   = $cancelled
			? __( 'Cashout cancelled — balance restored', 'lnkup-marketplace' )
			: __( 'Cashout failed — balance restored', 'lnkup-marketplace' );
		// The bucket follows how this payout was debited (its provider), not today's Connect state:
		// only Connect payouts were booked as connect debits + processing_balance.
		$is_destination = self::is_connect_payout( $payout_id, $user_id );
		$release_processing = $release_processing && $is_destination;
		if ( $is_destination ) {
			$restored = LNKup_Organizer_Wallet::insert_entry(
				array(
					'wallet_id'       => (int) ( $wallet['id'] ?? 0 ),
					'user_id'         => absint( $user_id ),
					'entry_type'      => 'connect_settlement',
					'amount'          => $amount,
					'balance_after'   => LNKup_Organizer_Wallet::sum_connect_settlement( $user_id ) + $amount,
					'source_type'     => $source,
					'source_id'       => $payout_id,
					'payout_id'       => $payout_id,
					'note'            => $note,
					'idempotency_key' => $key,
				)
			);
		} else {
			$restored = LNKup_Organizer_Wallet::add_entry(
				$user_id,
				$amount,
				'available',
				array(
					'source_type'     => $source,
					'source_id'       => $payout_id,
					'payout_id'       => $payout_id,
					'note'            => $note,
					'idempotency_key' => $key,
				)
			);
		}
		if ( $restored && $wallet && $release_processing ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . LNKup_Treasury_Tables::organizer_wallets_table() . ' SET processing_balance = GREATEST(0, processing_balance - %f), updated_at = %s WHERE id = %d',
					$amount,
					current_time( 'mysql' ),
					(int) $wallet['id']
				)
			);
		}
	}

	/**
	 * Mark payout as paid (admin or webhook).
	 *
	 * @param int    $payout_id    Payout ID.
	 * @param string $provider_ref Provider reference.
	 */
	public static function mark_paid( $payout_id, $provider_ref = '' ) {
		global $wpdb;
		$table  = LNKup_Treasury_Tables::organizer_payouts_table();
		$payout = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $payout_id ) ), ARRAY_A );
		if ( ! $payout ) {
			return false;
		}
		if ( 'paid' === $payout['status'] ) {
			return true;
		}
		$now          = current_time( 'mysql' );
		$provider_ref = '' !== (string) $provider_ref ? sanitize_text_field( $provider_ref ) : (string) ( $payout['provider_ref'] ?? '' );
		$prior        = (string) $payout['status'];
		$after_fail   = in_array( $prior, array( 'failed', 'cancelled' ), true );
		if ( ! $after_fail && ! in_array( $prior, array( 'requested', 'approved', 'processing' ), true ) ) {
			return false;
		}
		// Only the request that flips the row to paid may touch wallet totals (webhook + sync path race).
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'paid', provider_ref = %s, paid_at = %s, updated_at = %s WHERE id = %d AND status = %s",
				$provider_ref,
				$now,
				$now,
				absint( $payout_id ),
				$prior
			)
		);
		if ( 1 !== (int) $updated ) {
			return true;
		}
		$user_id    = (int) $payout['user_id'];
		$is_connect = self::is_connect_payout( $payout_id, $user_id );
		$wallets    = LNKup_Treasury_Tables::organizer_wallets_table();
		$wallet     = LNKup_Organizer_Wallet::get_or_create( $user_id );

		if ( $after_fail ) {
			// The payout reached the bank after all, but its balance was already restored: take it back.
			$amount = (float) $payout['amount'];
			if ( $is_connect ) {
				LNKup_Organizer_Wallet::insert_entry(
					array(
						'wallet_id'       => (int) $wallet['id'],
						'user_id'         => $user_id,
						'entry_type'      => 'debit',
						'amount'          => $amount,
						'source_type'     => 'payout',
						'payout_id'       => absint( $payout_id ),
						'note'            => __( 'Cashout completed after it was marked failed — balance corrected', 'lnkup-marketplace' ),
						'idempotency_key' => 'payout:' . absint( $payout_id ) . ':redebit',
					)
				);
			} else {
				LNKup_Organizer_Wallet::add_entry( $user_id, $amount, 'available', array( 'entry_type' => 'debit', 'source_type' => 'payout', 'payout_id' => absint( $payout_id ), 'note' => __( 'Cashout completed after it was marked failed — balance corrected', 'lnkup-marketplace' ), 'idempotency_key' => 'payout:' . absint( $payout_id ) . ':redebit' ) );
			}
			LNKup_Financial_Audit::log( 'payout_paid_after_failure', 'payout', $payout_id, array( 'amount' => $amount, 'prior_status' => $prior ) );
		}

		if ( $wallet ) {
			$release = ( $is_connect && ! $after_fail ) ? (float) $payout['amount'] : 0.0;
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wallets} SET lifetime_paid_out = lifetime_paid_out + %f, processing_balance = GREATEST(0, processing_balance - %f), updated_at = %s WHERE id = %d",
					(float) $payout['net_amount'],
					$release,
					$now,
					(int) $wallet['id']
				)
			);
		}
		LNKup_Financial_Audit::log( 'organizer_payout_paid', 'payout', $payout_id, array( 'amount' => (float) $payout['net_amount'] ) );
		do_action( 'lnkup_organizer_payout_status_changed', absint( $payout_id ), $prior, 'paid', $user_id );
		return true;
	}

	/**
	 * Whether a payout was booked against the Connect ledger (connect debit + processing).
	 *
	 * @param int $payout_id Payout ID.
	 * @param int $user_id   Organizer (fallback when the row is missing).
	 */
	private static function is_connect_payout( $payout_id, $user_id ) {
		global $wpdb;
		$table    = LNKup_Treasury_Tables::organizer_payouts_table();
		$provider = (string) $wpdb->get_var( $wpdb->prepare( "SELECT provider FROM {$table} WHERE id = %d", absint( $payout_id ) ) );
		if ( '' !== $provider ) {
			return 'stripe_connect_payout' === $provider;
		}
		return class_exists( 'LNKup_Stripe_Connect' ) && 'destination' === LNKup_Stripe_Connect::settlement_mode_for_organizer( $user_id );
	}

	/**
	 * Fail a payout after Stripe reports it failed asynchronously (payout.failed, transfer.reversed).
	 *
	 * @param int    $payout_id Payout row ID.
	 * @param string $reason    Failure reason.
	 * @return bool True when the payout was moved to failed and the balance restored.
	 */
	public static function handle_async_failure( $payout_id, $reason = '' ) {
		global $wpdb;
		$payout_id = absint( $payout_id );
		$table     = LNKup_Treasury_Tables::organizer_payouts_table();
		$payout    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $payout_id ), ARRAY_A );
		if ( ! $payout || ! in_array( $payout['status'], array( 'processing', 'paid' ), true ) ) {
			return false;
		}
		$prior_status = (string) $payout['status'];
		$reason       = '' !== trim( (string) $reason ) ? sanitize_text_field( $reason ) : __( 'Payout failed', 'lnkup-marketplace' );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', failure_reason = %s, updated_at = %s WHERE id = %d AND status = %s",
				$reason,
				current_time( 'mysql' ),
				$payout_id,
				$prior_status
			)
		);
		if ( 1 !== (int) $updated ) {
			return false;
		}

		$user_id = (int) $payout['user_id'];
		if ( 'paid' === $prior_status ) {
			$wallet = LNKup_Organizer_Wallet::get_or_create( $user_id );
			if ( $wallet ) {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . LNKup_Treasury_Tables::organizer_wallets_table() . ' SET lifetime_paid_out = GREATEST(0, lifetime_paid_out - %f), updated_at = %s WHERE id = %d',
						(float) $payout['net_amount'],
						current_time( 'mysql' ),
						(int) $wallet['id']
					)
				);
			}
		}
		self::restore_failed_cashout( $user_id, (float) $payout['amount'], $payout_id, 'paid' !== $prior_status );
		LNKup_Financial_Audit::log(
			'organizer_payout_failed_async',
			'payout',
			$payout_id,
			array(
				'prior_status' => $prior_status,
				'reason'       => $reason,
			)
		);
		do_action( 'lnkup_organizer_payout_status_changed', $payout_id, $prior_status, 'failed', $user_id );
		return true;
	}

	/**
	 * Cancel pending payout and restore balance.
	 *
	 * @param int $payout_id Payout ID.
	 * @param int $user_id   Organizer user ID.
	 */
	public static function cancel( $payout_id, $user_id ) {
		global $wpdb;
		$table  = LNKup_Treasury_Tables::organizer_payouts_table();
		$payout = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id = %d", absint( $payout_id ), absint( $user_id ) ),
			ARRAY_A
		);
		if ( ! $payout || ! in_array( $payout['status'], array( 'requested', 'approved' ), true ) ) {
			return new WP_Error( 'cannot_cancel', __( 'Payout cannot be cancelled.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$cancelled = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'cancelled', updated_at = %s WHERE id = %d AND user_id = %d AND status IN ('requested','approved')",
				current_time( 'mysql' ),
				absint( $payout_id ),
				absint( $user_id )
			)
		);
		if ( 1 !== (int) $cancelled ) {
			return new WP_Error( 'cannot_cancel', __( 'This cashout is already being processed.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		self::restore_failed_cashout( absint( $user_id ), (float) $payout['amount'], absint( $payout_id ), true, true );
		LNKup_Financial_Audit::log( 'organizer_payout_cancelled', 'payout', absint( $payout_id ), array( 'amount' => (float) $payout['amount'] ) );
		return true;
	}

	/**
	 * List payouts for organizer.
	 *
	 * @param int $user_id Organizer user ID.
	 * @param int $limit   Limit.
	 */
	public static function list_for_user( $user_id, $limit = 25 ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_payouts_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
				absint( $user_id ),
				absint( $limit )
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Format payout for REST.
	 *
	 * @param array $row Payout row.
	 */
	public static function format( $row ) {
		return array(
			'id'             => (int) $row['id'],
			'amount'         => (float) $row['amount'],
			'fee_amount'     => (float) $row['fee_amount'],
			'net_amount'     => (float) $row['net_amount'],
			'payout_type'    => $row['payout_type'],
			'status'         => $row['status'],
			'provider'       => $row['provider'],
			'provider_ref'   => $row['provider_ref'] ?? '',
			'reference'      => 'LNK-PO-' . (int) $row['id'],
			'source'         => 'lnkup_cashout',
			'failure_reason' => isset( $row['failure_reason'] ) ? (string) $row['failure_reason'] : '',
			'paid_at'        => $row['paid_at'] ?? null,
			'created_at'     => $row['created_at'],
			'updated_at'     => $row['updated_at'] ?? null,
		);
	}
}
