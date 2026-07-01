<?php
/**
 * Organizer cashout requests and payout execution.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Payouts {

	const INSTANT_FEE_RATE = 0.015;

	/**
	 * Request cashout from organizer wallet.
	 *
	 * @param int    $user_id Organizer user ID.
	 * @param float  $amount  Requested amount.
	 * @param string $type    Payout type.
	 */
	public static function request_cashout( $user_id, $amount, $type = 'standard' ) {
		$user_id = absint( $user_id );
		$amount  = round( (float) $amount, 2 );
		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_amount', __( 'Invalid cashout amount.', 'lnkup-marketplace' ) );
		}

		$settlement_mode = class_exists( 'LNKup_Stripe_Connect' )
			? LNKup_Stripe_Connect::settlement_mode_for_organizer( $user_id )
			: 'platform_treasury';

		if ( 'destination' === $settlement_mode ) {
			if ( ! LNKup_Stripe_Connect::is_connect_ready( $user_id ) ) {
				return new WP_Error( 'connect_not_ready', __( 'Complete Stripe Connect setup before cashing out.', 'lnkup-marketplace' ) );
			}
			if ( LNKup_Stripe_Connect::has_past_due_requirements( $user_id ) ) {
				return new WP_Error( 'requirements_past_due', __( 'Resolve Stripe verification requirements before cashing out.', 'lnkup-marketplace' ) );
			}
		}

		$balances = LNKup_Organizer_Wallet::get_balances( $user_id );
		if ( $amount > (float) $balances['available'] ) {
			return new WP_Error( 'insufficient_balance', __( 'Insufficient available balance.', 'lnkup-marketplace' ) );
		}

		if ( class_exists( 'LNKup_Approval_Workflows' ) ) {
			$approval = LNKup_Approval_Workflows::maybe_require( 'payout', $amount, $user_id );
			if ( is_wp_error( $approval ) ) {
				return $approval;
			}
		}

		$fee_rate = 'instant' === $type ? self::INSTANT_FEE_RATE : 0;
		$fee      = round( $amount * $fee_rate, 2 );
		$net      = round( $amount - $fee, 2 );

		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_payouts_table();
		$now   = current_time( 'mysql' );
		$provider = 'manual';
		if ( class_exists( 'LNKup_Stripe_Connect' ) ) {
			if ( 'destination' === $settlement_mode ) {
				$provider = 'stripe_connect_payout';
			} elseif ( LNKup_Stripe_Connect::is_connect_ready( $user_id ) ) {
				$provider = 'stripe_connect';
			}
		}

		$wpdb->insert(
			$table,
			array(
				'user_id'      => $user_id,
				'amount'       => $amount,
				'fee_amount'   => $fee,
				'net_amount'   => $net,
				'payout_type'  => sanitize_key( $type ),
				'status'       => 'requested',
				'provider'     => $provider,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		$payout_id = (int) $wpdb->insert_id;

		LNKup_Organizer_Wallet::debit( $user_id, $amount, array(
			'source_type' => 'payout',
			'source_id'   => $payout_id,
			'payout_id'   => $payout_id,
			'note'        => __( 'Cashout requested', 'lnkup-marketplace' ),
		) );

		self::process_payout( $payout_id );
		LNKup_Financial_Audit::log( 'organizer_cashout_requested', 'payout', $payout_id, array( 'amount' => $amount ) );
		return $payout_id;
	}

	/**
	 * Execute payout via Connect or manual queue.
	 *
	 * @param int $payout_id Payout row ID.
	 */
	public static function process_payout( $payout_id ) {
		global $wpdb;
		$table  = LNKup_Treasury_Tables::organizer_payouts_table();
		$payout = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $payout_id ) ), ARRAY_A );
		if ( ! $payout || ! in_array( $payout['status'], array( 'requested', 'approved' ), true ) ) {
			return false;
		}

		$wpdb->update( $table, array( 'status' => 'processing', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $payout_id ) );

		if ( 'stripe_connect_payout' === $payout['provider'] && class_exists( 'LNKup_Stripe_Connect' ) ) {
			$result = LNKup_Stripe_Connect::create_connect_payout(
				(int) $payout['user_id'],
				(float) $payout['net_amount'],
				$payout['payout_type'],
				array( 'payout_id' => (string) $payout_id )
			);
			if ( is_wp_error( $result ) ) {
				$wpdb->update(
					$table,
					array( 'status' => 'failed', 'failure_reason' => $result->get_error_message(), 'updated_at' => current_time( 'mysql' ) ),
					array( 'id' => $payout_id )
				);
				self::restore_failed_cashout( (int) $payout['user_id'], (float) $payout['amount'], $payout_id );
				return false;
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
				$wpdb->update(
					$table,
					array( 'status' => 'failed', 'failure_reason' => $result->get_error_message(), 'updated_at' => current_time( 'mysql' ) ),
					array( 'id' => $payout_id )
				);
				return false;
			}
			if ( ! empty( $result['transfer_id'] ) ) {
				self::mark_paid( $payout_id, $result['transfer_id'] );
				return true;
			}
		}

		$wpdb->update( $table, array( 'status' => 'approved', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $payout_id ) );
		return true;
	}

	/**
	 * Restore balance after failed Connect payout.
	 *
	 * @param int   $user_id   Organizer user ID.
	 * @param float $amount    Amount.
	 * @param int   $payout_id Payout ID.
	 */
	private static function restore_failed_cashout( $user_id, $amount, $payout_id ) {
		global $wpdb;
		$wallet = LNKup_Organizer_Wallet::get_or_create( $user_id );
		if ( $wallet ) {
			$wpdb->update(
				LNKup_Treasury_Tables::organizer_wallets_table(),
				array(
					'processing_balance' => max( 0, (float) $wallet['processing_balance'] - $amount ),
					'updated_at'         => current_time( 'mysql' ),
				),
				array( 'id' => (int) $wallet['id'] )
			);
		}
		if ( class_exists( 'LNKup_Stripe_Connect' ) && 'destination' === LNKup_Stripe_Connect::settlement_mode_for_organizer( $user_id ) ) {
			$wpdb->insert(
				LNKup_Treasury_Tables::organizer_wallet_entries_table(),
				array(
					'wallet_id'     => (int) ( $wallet['id'] ?? 0 ),
					'user_id'       => absint( $user_id ),
					'entry_type'    => 'connect_settlement',
					'amount'        => $amount,
					'balance_after' => LNKup_Organizer_Wallet::sum_connect_settlement( $user_id ) + $amount,
					'source_type'   => 'payout_failed',
					'source_id'     => $payout_id,
					'payout_id'     => $payout_id,
					'note'          => __( 'Cashout failed — Connect balance restored', 'lnkup-marketplace' ),
					'created_at'    => current_time( 'mysql' ),
				)
			);
			return;
		}
		LNKup_Organizer_Wallet::add_entry(
			$user_id,
			$amount,
			'available',
			array(
				'source_type' => 'payout_failed',
				'source_id'   => $payout_id,
				'payout_id'   => $payout_id,
				'note'        => __( 'Cashout failed — balance restored', 'lnkup-marketplace' ),
			)
		);
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
		$now = current_time( 'mysql' );
		$wpdb->update(
			$table,
			array(
				'status'       => 'paid',
				'provider_ref' => sanitize_text_field( $provider_ref ),
				'paid_at'      => $now,
				'updated_at'   => $now,
			),
			array( 'id' => $payout_id )
		);
		$wallet = LNKup_Organizer_Wallet::get_or_create( (int) $payout['user_id'] );
		if ( $wallet ) {
			$processing = max( 0, (float) $wallet['processing_balance'] - (float) $payout['amount'] );
			$wpdb->update(
				LNKup_Treasury_Tables::organizer_wallets_table(),
				array(
					'lifetime_paid_out'    => (float) $wallet['lifetime_paid_out'] + (float) $payout['net_amount'],
					'processing_balance'   => $processing,
					'updated_at'           => $now,
				),
				array( 'id' => (int) $wallet['id'] )
			);
		}
		LNKup_Financial_Audit::log( 'organizer_payout_paid', 'payout', $payout_id, array( 'amount' => (float) $payout['net_amount'] ) );
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
			return new WP_Error( 'cannot_cancel', __( 'Payout cannot be cancelled.', 'lnkup-marketplace' ) );
		}
		$wpdb->update( $table, array( 'status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $payout_id ) );
		LNKup_Organizer_Wallet::add_entry(
			$user_id,
			(float) $payout['amount'],
			'available',
			array(
				'source_type' => 'payout_cancel',
				'source_id'   => $payout_id,
				'payout_id'   => $payout_id,
				'note'        => __( 'Cashout cancelled — balance restored', 'lnkup-marketplace' ),
			)
		);
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
			'id'           => (int) $row['id'],
			'amount'       => (float) $row['amount'],
			'fee_amount'   => (float) $row['fee_amount'],
			'net_amount'   => (float) $row['net_amount'],
			'payout_type'  => $row['payout_type'],
			'status'       => $row['status'],
			'provider'     => $row['provider'],
			'provider_ref' => $row['provider_ref'] ?? '',
			'paid_at'      => $row['paid_at'] ?? null,
			'created_at'   => $row['created_at'],
		);
	}
}
