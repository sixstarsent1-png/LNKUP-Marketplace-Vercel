<?php
/**
 * Organizer treasury wallet and ledger.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Wallet {

	/**
	 * Get or create wallet row for organizer.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function get_or_create( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$table   = LNKup_Treasury_Tables::organizer_wallets_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A );
		if ( $row ) {
			return $row;
		}

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'user_id'            => $user_id,
				'available_balance'  => 0,
				'pending_balance'    => 0,
				'processing_balance' => 0,
				'lifetime_paid_out'  => 0,
				'reserve_hold'       => 0,
				'currency'           => 'USD',
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);

		return self::get_or_create( $user_id );
	}

	/**
	 * Balances summary for dashboard.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function get_balances( $user_id ) {
		$user_id = absint( $user_id );
		$settlement_mode = class_exists( 'LNKup_Stripe_Connect' )
			? LNKup_Stripe_Connect::settlement_mode_for_organizer( $user_id )
			: 'platform_treasury';

		// Connect became ready after platform charges: move stranded treasury credits
		// into connect_settlement so recovery / Cash Out can see them.
		if ( 'destination' === $settlement_mode ) {
			self::migrate_treasury_balance_to_connect_settlement( $user_id );
			self::backfill_platform_payments_to_connect_settlement( $user_id );
		}

		$wallet = self::get_or_create( $user_id );
		if ( ! $wallet ) {
			return array(
				'available'          => 0,
				'pending'            => 0,
				'processing'         => 0,
				'lifetime_paid'      => 0,
				'reserve_hold'       => 0,
				'connect_settlement' => 0,
				'last_payout'        => null,
				'next_scheduled'     => null,
				'settlement_mode'    => $settlement_mode,
			);
		}

		$last_payout = null;
		if ( class_exists( 'LNKup_Organizer_Payouts' ) ) {
			$payouts = LNKup_Organizer_Payouts::list_for_user( $user_id, 1 );
			$last_payout = $payouts ? $payouts[0] : null;
		}

		$connect_settlement = self::sum_connect_settlement( $user_id );
		$available          = (float) $wallet['available_balance'];
		$pending            = (float) $wallet['pending_balance'];
		if ( 'destination' === $settlement_mode ) {
			// Tracked settlement minus in-flight cashouts. Live withdrawable amount
			// for destination cashout comes from Stripe via organizer_cashout_available().
			$available = max( 0, $connect_settlement - (float) $wallet['processing_balance'] );
		}

		return array(
			'available'          => $available,
			'pending'            => $pending,
			'processing'         => (float) $wallet['processing_balance'],
			'lifetime_paid'      => (float) $wallet['lifetime_paid_out'],
			'reserve_hold'       => (float) $wallet['reserve_hold'],
			'connect_settlement' => $connect_settlement,
			'last_payout'        => $last_payout,
			'next_scheduled'     => null,
			'settlement_mode'    => $settlement_mode,
		);
	}

	/**
	 * Convert stranded treasury available_balance into connect_settlement when Connect is ready.
	 *
	 * Idempotent: no-op when available_balance is already ~0.
	 *
	 * @param int $user_id Organizer user ID.
	 * @return float Amount migrated (dollars), or 0.
	 */
	public static function migrate_treasury_balance_to_connect_settlement( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return 0.0;
		}
		if ( ! class_exists( 'LNKup_Stripe_Connect' ) || ! LNKup_Stripe_Connect::is_connect_ready( $user_id ) ) {
			return 0.0;
		}
		if ( 'destination' !== LNKup_Stripe_Connect::settlement_mode_for_organizer( $user_id ) ) {
			return 0.0;
		}

		global $wpdb;
		$wallet = self::get_or_create( $user_id );
		if ( ! $wallet ) {
			return 0.0;
		}

		$amount = round( (float) ( $wallet['available_balance'] ?? 0 ), 2 );
		if ( $amount < 0.01 ) {
			return 0.0;
		}

		$entries = LNKup_Treasury_Tables::organizer_wallet_entries_table();
		$wallets = LNKup_Treasury_Tables::organizer_wallets_table();
		if ( ! LNKup_Financial_Tables::table_exists( $entries ) || ! LNKup_Financial_Tables::table_exists( $wallets ) ) {
			return 0.0;
		}

		// Zero treasury available first so concurrent reads do not double-migrate.
		$wpdb->update(
			$wallets,
			array(
				'available_balance' => 0,
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => (int) $wallet['id'] )
		);

		$wpdb->insert(
			$entries,
			array(
				'wallet_id'       => (int) $wallet['id'],
				'user_id'         => $user_id,
				'entry_type'      => 'connect_settlement',
				'amount'          => $amount,
				'balance_after'   => 0,
				'source_type'     => 'treasury_migration',
				'source_id'       => 0,
				'payment_post_id' => 0,
				'note'            => __( 'Migrated stranded treasury balance for Connect recovery', 'lnkup-marketplace' ),
				'metadata'        => wp_json_encode(
					array(
						'migrated_from_treasury' => true,
						'amount'                 => $amount,
					)
				),
				'created_at'      => current_time( 'mysql' ),
			)
		);

		if ( class_exists( 'LNKup_Financial_Audit' ) ) {
			LNKup_Financial_Audit::log(
				'treasury_migrated_to_connect_settlement',
				'wallet',
				$user_id,
				array( 'amount' => $amount )
			);
		}

		return $amount;
	}

	/**
	 * Whether any wallet ledger row exists for a payment.
	 *
	 * @param int $payment_id Payment post ID.
	 * @return bool
	 */
	public static function has_wallet_entry_for_payment( $payment_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_wallet_entries_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return false;
		}
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE payment_post_id = %d LIMIT 1",
				absint( $payment_id )
			)
		);
		return (bool) $found;
	}

	/**
	 * Backfill connect_settlement for paid platform charges missing a recovery claim.
	 *
	 * Covers booth payments that landed on the master Stripe account with no wallet
	 * ledger (migrate is a no-op when available_balance is already 0).
	 *
	 * @param int $user_id Organizer user ID.
	 * @return int Number of payments backfilled.
	 */
	public static function backfill_platform_payments_to_connect_settlement( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return 0;
		}
		if ( ! class_exists( 'LNKup_Stripe_Connect' ) || ! LNKup_Stripe_Connect::is_connect_ready( $user_id ) ) {
			return 0;
		}
		if ( 'destination' !== LNKup_Stripe_Connect::settlement_mode_for_organizer( $user_id ) ) {
			return 0;
		}
		if ( ! function_exists( 'lnkup_get_payments_for_user' ) || ! class_exists( 'LNKup_Fee_Engine' ) ) {
			return 0;
		}

		$wallet = self::get_or_create( $user_id );
		if ( ! $wallet ) {
			return 0;
		}

		$claim = self::sum_connect_settlement( $user_id );
		$avail = round( (float) ( $wallet['available_balance'] ?? 0 ), 2 );
		// When claim and available are both empty, recreate from paid platform payments
		// even if orphan treasury credit rows exist. When claim already exists (e.g. after
		// migrate), only fill payments that have no ledger row (avoid double-count).
		$orphan_mode = ( $claim < 0.01 && $avail < 0.01 );

		$backfilled = 0;
		$payments   = lnkup_get_payments_for_user( $user_id, 'organizer' );
		foreach ( (array) $payments as $payment ) {
			$payment_id = (int) $payment->ID;
			if ( ! $payment_id ) {
				continue;
			}
			if ( 'paid' !== (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) ) {
				continue;
			}
			if ( self::has_connect_settlement_for_payment( $payment_id ) ) {
				continue;
			}

			// Destination-stamped charges belong on Connect; skip platform recovery.
			$dest = (string) get_post_meta( $payment_id, '_lnkup_stripe_destination_account', true );
			if ( '' !== $dest ) {
				continue;
			}

			if ( ! $orphan_mode && self::has_wallet_entry_for_payment( $payment_id ) ) {
				continue;
			}

			$breakdown = LNKup_Fee_Engine::calculate_for_payment( $payment_id );
			$net       = (float) ( $breakdown['payout_eligible_amount'] ?? $breakdown['organizer_net'] ?? 0 );
			if ( $net < 0.01 ) {
				// Meta incomplete — derive organizer share from totals / amount.
				$total   = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
				if ( $total < 0.01 ) {
					$total = (float) get_post_meta( $payment_id, '_lnkup_amount', true );
				}
				$app_fee = (float) get_post_meta( $payment_id, '_lnkup_application_fee_cents', true );
				if ( $app_fee >= 1 ) {
					$app_fee = $app_fee / 100;
				} else {
					$app_fee = (float) ( $breakdown['platform_fee'] ?? 0 );
				}
				$net = max( 0, round( $total - $app_fee, 2 ) );
				$breakdown['organizer_net']           = $net;
				$breakdown['payout_eligible_amount'] = $net;
			}
			if ( $net < 0.01 ) {
				continue;
			}

			if ( ! self::record_connect_settlement( $user_id, $payment_id, $breakdown ) ) {
				continue;
			}

			global $wpdb;
			$table = LNKup_Treasury_Tables::organizer_wallet_entries_table();
			$wpdb->update(
				$table,
				array(
					'note'     => __( 'Platform charge recovered for Connect cashout', 'lnkup-marketplace' ),
					'metadata' => wp_json_encode(
						array(
							'settlement_mode'   => 'platform_treasury',
							'recovery_backfill' => true,
							'breakdown'         => $breakdown,
						)
					),
				),
				array(
					'payment_post_id' => $payment_id,
					'entry_type'      => 'connect_settlement',
					'user_id'         => $user_id,
				)
			);
			$backfilled++;
		}

		if ( $backfilled > 0 && class_exists( 'LNKup_Financial_Audit' ) ) {
			LNKup_Financial_Audit::log(
				'platform_payments_backfilled_to_connect_settlement',
				'wallet',
				$user_id,
				array( 'payments' => $backfilled )
			);
		}

		return $backfilled;
	}

	/**
	 * Credit organizer from completed payment settlement.
	 *
	 * @param int   $payment_id Payment post ID.
	 * @param array $breakdown  Fee breakdown.
	 */
	public static function credit_from_payment( $payment_id, $breakdown ) {
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		if ( ! $organizer_id ) {
			return false;
		}

		$mode = get_post_meta( $payment_id, '_lnkup_settlement_mode', true );
		if ( ! $mode && class_exists( 'LNKup_Stripe_Connect' ) ) {
			$mode = LNKup_Stripe_Connect::settlement_mode_for_organizer( $organizer_id );
		}

		if ( 'destination' === $mode ) {
			return self::record_connect_settlement( $organizer_id, $payment_id, $breakdown );
		}

		$net     = (float) ( $breakdown['organizer_net'] ?? 0 );
		$reserve = (float) ( $breakdown['reserve_amount'] ?? 0 );
		$credit  = max( 0, $net - $reserve );
		if ( $credit <= 0 && $reserve <= 0 ) {
			return false;
		}

		$pending = class_exists( 'LNKup_Escrow' ) && LNKup_Escrow::has_active_hold( $payment_id );
		if ( $credit > 0 ) {
			self::add_entry(
				$organizer_id,
				$credit,
				$pending ? 'pending' : 'available',
				array(
					'source_type'      => 'payment',
					'source_id'        => $payment_id,
					'payment_post_id'  => $payment_id,
					'note'             => __( 'Vendor payment settlement', 'lnkup-marketplace' ),
				)
			);
		}
		if ( $reserve > 0 ) {
			self::add_reserve_hold( $organizer_id, $reserve, $payment_id );
		}

		return true;
	}

	/**
	 * Whether a connect_settlement ledger row exists for a payment.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function has_connect_settlement_for_payment( $payment_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_wallet_entries_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return false;
		}
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE payment_post_id = %d AND entry_type = 'connect_settlement' LIMIT 1",
				absint( $payment_id )
			)
		);
		return (bool) $found;
	}

	/**
	 * Ledger-only entry for destination charge settlement (no withdrawable credit).
	 *
	 * @param int   $user_id    Organizer user ID.
	 * @param int   $payment_id Payment post ID.
	 * @param array $breakdown  Fee breakdown.
	 */
	public static function record_connect_settlement( $user_id, $payment_id, $breakdown ) {
		if ( self::has_connect_settlement_for_payment( $payment_id ) ) {
			return true;
		}

		global $wpdb;
		$wallet = self::get_or_create( $user_id );
		if ( ! $wallet ) {
			return false;
		}
		$dest_gross = (float) get_post_meta( $payment_id, '_lnkup_stripe_destination_amount', true );
		$net        = $dest_gross > 0
			? $dest_gross
			: (float) ( $breakdown['payout_eligible_amount'] ?? $breakdown['organizer_net'] ?? 0 );
		if ( $net <= 0 ) {
			return false;
		}
		$wpdb->insert(
			LNKup_Treasury_Tables::organizer_wallet_entries_table(),
			array(
				'wallet_id'       => (int) $wallet['id'],
				'user_id'         => absint( $user_id ),
				'entry_type'      => 'connect_settlement',
				'amount'          => $net,
				'balance_after'   => (float) $wallet['available_balance'],
				'source_type'     => 'payment',
				'source_id'       => $payment_id,
				'payment_post_id' => $payment_id,
				'note'            => __( 'Settled via Stripe Connect (destination charge)', 'lnkup-marketplace' ),
				'metadata'        => wp_json_encode( array( 'settlement_mode' => 'destination', 'breakdown' => $breakdown ) ),
				'created_at'      => current_time( 'mysql' ),
			)
		);
		$reserve = (float) ( $breakdown['reserve_amount'] ?? 0 );
		if ( $reserve > 0 ) {
			self::add_reserve_hold( $user_id, $reserve, $payment_id );
		}
		return true;
	}

	/**
	 * Sum connect_settlement ledger credits minus payout debits for destination mode.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function sum_connect_settlement( $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_wallet_entries_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0.0;
		}
		$credits = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE user_id = %d AND entry_type = 'connect_settlement'",
				absint( $user_id )
			)
		);
		$debits = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE user_id = %d AND entry_type = 'debit' AND source_type = 'payout'",
				absint( $user_id )
			)
		);
		return max( 0, round( $credits - $debits, 2 ) );
	}

	/**
	 * Add ledger entry and update balances.
	 *
	 * @param int    $user_id Organizer user ID.
	 * @param float  $amount  Amount.
	 * @param string $bucket  available|pending|processing.
	 * @param array  $meta    Entry metadata.
	 */
	public static function add_entry( $user_id, $amount, $bucket = 'available', $meta = array() ) {
		global $wpdb;
		$amount  = round( (float) $amount, 2 );
		$wallet  = self::get_or_create( $user_id );
		if ( ! $wallet || $amount <= 0 ) {
			return 0;
		}

		$entry_type = ( $meta['entry_type'] ?? '' ) === 'debit' ? 'debit' : 'credit';
		$field      = 'available_balance';
		if ( 'pending' === $bucket ) {
			$field = 'pending_balance';
		} elseif ( 'processing' === $bucket ) {
			$field = 'processing_balance';
		}

		$current = (float) $wallet[ $field ];
		$new     = 'debit' === $entry_type ? max( 0, $current - $amount ) : $current + $amount;

		$wpdb->update(
			LNKup_Treasury_Tables::organizer_wallets_table(),
			array( $field => $new, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $wallet['id'] )
		);

		$wpdb->insert(
			LNKup_Treasury_Tables::organizer_wallet_entries_table(),
			array(
				'wallet_id'       => (int) $wallet['id'],
				'user_id'         => absint( $user_id ),
				'entry_type'      => $entry_type,
				'amount'          => $amount,
				'balance_after'   => $new,
				'source_type'     => sanitize_key( $meta['source_type'] ?? '' ),
				'source_id'       => absint( $meta['source_id'] ?? 0 ),
				'payment_post_id' => absint( $meta['payment_post_id'] ?? 0 ),
				'payout_id'       => absint( $meta['payout_id'] ?? 0 ),
				'note'            => sanitize_text_field( $meta['note'] ?? '' ),
				'metadata'        => ! empty( $meta['metadata'] ) ? wp_json_encode( $meta['metadata'] ) : null,
				'created_at'      => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Debit available balance for cashout.
	 *
	 * @param int   $user_id Organizer user ID.
	 * @param float $amount  Amount.
	 * @param array $meta    Metadata.
	 */
	public static function debit( $user_id, $amount, $meta = array() ) {
		$meta['entry_type'] = 'debit';
		if ( class_exists( 'LNKup_Stripe_Connect' ) && 'destination' === LNKup_Stripe_Connect::settlement_mode_for_organizer( $user_id ) ) {
			return self::record_connect_debit( $user_id, $amount, $meta );
		}
		return self::add_entry( $user_id, $amount, 'available', $meta );
	}

	/**
	 * Ledger debit for destination mode (does not mutate treasury available_balance).
	 *
	 * @param int   $user_id Organizer user ID.
	 * @param float $amount  Amount.
	 * @param array $meta    Metadata.
	 */
	public static function record_connect_debit( $user_id, $amount, $meta = array() ) {
		global $wpdb;
		$wallet = self::get_or_create( $user_id );
		if ( ! $wallet || $amount <= 0 ) {
			return 0;
		}
		$wpdb->insert(
			LNKup_Treasury_Tables::organizer_wallet_entries_table(),
			array(
				'wallet_id'       => (int) $wallet['id'],
				'user_id'         => absint( $user_id ),
				'entry_type'      => 'debit',
				'amount'          => round( (float) $amount, 2 ),
				'balance_after'   => self::sum_connect_settlement( $user_id ) - round( (float) $amount, 2 ),
				'source_type'     => sanitize_key( $meta['source_type'] ?? 'payout' ),
				'source_id'       => absint( $meta['source_id'] ?? 0 ),
				'payout_id'       => absint( $meta['payout_id'] ?? 0 ),
				'note'            => sanitize_text_field( $meta['note'] ?? '' ),
				'metadata'        => ! empty( $meta['metadata'] ) ? wp_json_encode( $meta['metadata'] ) : null,
				'created_at'      => current_time( 'mysql' ),
			)
		);
		$wpdb->update(
			LNKup_Treasury_Tables::organizer_wallets_table(),
			array(
				'processing_balance' => (float) $wallet['processing_balance'] + round( (float) $amount, 2 ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => (int) $wallet['id'] )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Move pending to available when escrow releases.
	 *
	 * @param int   $user_id    Organizer user ID.
	 * @param float $amount     Amount.
	 * @param int   $payment_id Payment post ID.
	 */
	public static function release_pending( $user_id, $amount, $payment_id = 0 ) {
		global $wpdb;
		$wallet = self::get_or_create( $user_id );
		if ( ! $wallet ) {
			return false;
		}
		$amount = min( (float) $amount, (float) $wallet['pending_balance'] );
		if ( $amount <= 0 ) {
			return false;
		}

		$table = LNKup_Treasury_Tables::organizer_wallets_table();
		$wpdb->update(
			$table,
			array(
				'pending_balance'   => max( 0, (float) $wallet['pending_balance'] - $amount ),
				'available_balance' => (float) $wallet['available_balance'] + $amount,
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => (int) $wallet['id'] )
		);

		$wpdb->insert(
			LNKup_Treasury_Tables::organizer_wallet_entries_table(),
			array(
				'wallet_id'       => (int) $wallet['id'],
				'user_id'         => absint( $user_id ),
				'entry_type'      => 'transfer',
				'amount'          => $amount,
				'balance_after'   => (float) $wallet['available_balance'] + $amount,
				'source_type'     => 'escrow_release',
				'payment_post_id' => absint( $payment_id ),
				'note'            => __( 'Escrow released to available', 'lnkup-marketplace' ),
				'created_at'      => current_time( 'mysql' ),
			)
		);

		return true;
	}

	/**
	 * Track reserve on wallet.
	 *
	 * @param int   $user_id    Organizer user ID.
	 * @param float $amount     Reserve amount.
	 * @param int   $payment_id Payment post ID.
	 */
	private static function add_reserve_hold( $user_id, $amount, $payment_id ) {
		global $wpdb;
		$wallet = self::get_or_create( $user_id );
		if ( ! $wallet ) {
			return;
		}
		$wpdb->update(
			LNKup_Treasury_Tables::organizer_wallets_table(),
			array(
				'reserve_hold' => (float) $wallet['reserve_hold'] + $amount,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => (int) $wallet['id'] )
		);
	}

	/**
	 * List wallet entries.
	 *
	 * @param int $user_id Organizer user ID.
	 * @param int $limit   Limit.
	 */
	public static function list_entries( $user_id, $limit = 50 ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_wallet_entries_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
				absint( $user_id ),
				absint( $limit )
			),
			ARRAY_A
		);
	}
}
