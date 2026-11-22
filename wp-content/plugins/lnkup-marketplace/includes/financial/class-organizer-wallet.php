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
	 * Balances summary for dashboard. Pure read: never migrates, backfills or inserts.
	 *
	 * Treasury → Connect migration and platform-charge backfill run from
	 * LNKup_Financial_Service::sync_organizer() (cron, cashout, explicit sync).
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function get_balances( $user_id ) {
		$user_id = absint( $user_id );
		$settlement_mode = class_exists( 'LNKup_Stripe_Connect' )
			? LNKup_Stripe_Connect::settlement_mode_for_organizer( $user_id )
			: 'platform_treasury';

		$wallet = self::get_row( $user_id );
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
			'treasury_available' => (float) $wallet['available_balance'],
			'treasury_pending'   => (float) $wallet['pending_balance'],
			'last_payout'        => $last_payout,
			'next_scheduled'     => null,
			'settlement_mode'    => $settlement_mode,
		);
	}

	/**
	 * Wallet row without creating one.
	 *
	 * @param int $user_id Organizer user ID.
	 * @return array|null
	 */
	public static function get_row( $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_wallets_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", absint( $user_id ) ), ARRAY_A );
		return $row ?: null;
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

		// Compare-and-swap: only the request that zeroes this exact balance may record the migration.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wallets} SET available_balance = 0, updated_at = %s WHERE id = %d AND available_balance = %s",
				current_time( 'mysql' ),
				(int) $wallet['id'],
				number_format( $amount, 2, '.', '' )
			)
		);
		if ( 1 !== (int) $claimed ) {
			return 0.0;
		}

		$entry_id = self::insert_entry(
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
			)
		);
		if ( ! $entry_id ) {
			// Never leave the balance zeroed without its ledger counterpart.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wallets} SET available_balance = available_balance + %f, updated_at = %s WHERE id = %d",
					$amount,
					current_time( 'mysql' ),
					(int) $wallet['id']
				)
			);
			if ( class_exists( 'LNKup_Financial_Audit' ) ) {
				LNKup_Financial_Audit::log( 'treasury_migration_rolled_back', 'wallet', $user_id, array( 'amount' => $amount ) );
			}
			return 0.0;
		}

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
			if ( class_exists( 'LNKup_Financial_Service' ) && ! LNKup_Financial_Service::auto_recovery_allowed( $payment_id ) ) {
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

			$breakdown = LNKup_Fee_Engine::settlement_breakdown( $payment_id );
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

		if ( self::has_wallet_entry_for_payment( $payment_id ) ) {
			return true;
		}

		$pending = class_exists( 'LNKup_Escrow' ) && LNKup_Escrow::has_active_hold( $payment_id );
		$entry   = 0;
		if ( $credit > 0 ) {
			$entry = self::add_entry(
				$organizer_id,
				$credit,
				$pending ? 'pending' : 'available',
				array(
					'source_type'      => 'payment',
					'source_id'        => $payment_id,
					'payment_post_id'  => $payment_id,
					'note'             => __( 'Vendor payment settlement', 'lnkup-marketplace' ),
					'idempotency_key'  => self::payment_key( $payment_id, 'credit' ),
					'metadata'         => array(
						'settlement_mode' => 'platform_treasury',
						'gross_cents'     => (int) round( (float) ( $breakdown['gross_amount'] ?? 0 ) * 100 ),
						'net_cents'       => (int) round( $net * 100 ),
						'reserve_cents'   => (int) round( $reserve * 100 ),
						'intent_id'       => (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true ),
						'charge_id'       => (string) get_post_meta( $payment_id, '_lnkup_stripe_charge_id', true ),
					),
				)
			);
		}
		if ( $reserve > 0 && ( $entry || $credit <= 0 ) ) {
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
		// Destination amount is only meaningful for real destination charges; platform charges
		// carry no application fee in Stripe, so their "destination amount" would be gross.
		$has_dest   = '' !== (string) get_post_meta( $payment_id, '_lnkup_stripe_destination_account', true );
		$dest_gross = $has_dest ? (float) get_post_meta( $payment_id, '_lnkup_stripe_destination_amount', true ) : 0.0;
		$net        = $dest_gross > 0
			? $dest_gross
			: (float) ( $breakdown['payout_eligible_amount'] ?? $breakdown['organizer_net'] ?? 0 );
		if ( $net <= 0 ) {
			return false;
		}
		$inserted = self::insert_entry(
			array(
				'wallet_id'       => (int) $wallet['id'],
				'user_id'         => absint( $user_id ),
				'entry_type'      => 'connect_settlement',
				'amount'          => round( $net, 2 ),
				'balance_after'   => (float) $wallet['available_balance'],
				'source_type'     => 'payment',
				'source_id'       => $payment_id,
				'payment_post_id' => $payment_id,
				'note'            => __( 'Settled via Stripe Connect (destination charge)', 'lnkup-marketplace' ),
				'metadata'        => wp_json_encode(
					array(
						'settlement_mode' => 'destination',
						'breakdown'       => $breakdown,
						'intent_id'       => (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true ),
						'charge_id'       => (string) get_post_meta( $payment_id, '_lnkup_stripe_charge_id', true ),
						'transfer_id'     => (string) get_post_meta( $payment_id, '_lnkup_stripe_transfer_id', true ),
					)
				),
				'idempotency_key' => self::payment_key( $payment_id, 'connect_settlement' ),
			)
		);
		if ( ! $inserted ) {
			return self::has_connect_settlement_for_payment( $payment_id );
		}
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
				"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE user_id = %d AND entry_type = 'debit' AND source_type IN ('payout','reconciliation_offset_connect')",
				absint( $user_id )
			)
		);
		return max( 0, round( $credits - $debits, 2 ) );
	}

	/**
	 * Money LNKup holds for a destination-mode organizer and may transfer on Cash Out.
	 *
	 * Counts connect_settlement rows for platform-held funds (treasury migrations, platform-charge
	 * recoveries, cashout restores) minus cashout debits and offsets. Destination-charge mirrors
	 * are excluded: that money is already on the organizer's Stripe account and Stripe pays it
	 * out automatically, so counting it would offer LNKup's own balance a second time.
	 *
	 * @param int $user_id Organizer user ID.
	 * @return float Dollars.
	 */
	public static function platform_claim( $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_wallet_entries_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0.0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$credits = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$table}
				WHERE user_id = %d AND entry_type = 'connect_settlement'
				AND NOT ( source_type = 'payment' AND COALESCE(metadata, '') LIKE %s )",
				absint( $user_id ),
				'%"settlement_mode":"destination"%'
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$debits = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE user_id = %d AND entry_type = 'debit' AND source_type IN ('payout','reconciliation_offset_connect')",
				absint( $user_id )
			)
		);
		return max( 0.0, round( $credits - $debits, 2 ) );
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

		// Ledger row first: a duplicate idempotency key aborts before any balance moves.
		$entry_id = self::insert_entry(
			array(
				'wallet_id'       => (int) $wallet['id'],
				'user_id'         => absint( $user_id ),
				'entry_type'      => $entry_type,
				'amount'          => $amount,
				'balance_after'   => 0,
				'source_type'     => sanitize_key( $meta['source_type'] ?? '' ),
				'source_id'       => absint( $meta['source_id'] ?? 0 ),
				'payment_post_id' => absint( $meta['payment_post_id'] ?? 0 ),
				'payout_id'       => absint( $meta['payout_id'] ?? 0 ),
				'note'            => sanitize_text_field( $meta['note'] ?? '' ),
				'metadata'        => ! empty( $meta['metadata'] ) ? wp_json_encode( $meta['metadata'] ) : null,
				'idempotency_key' => $meta['idempotency_key'] ?? null,
			)
		);
		if ( ! $entry_id ) {
			return 0;
		}

		$wallets = LNKup_Treasury_Tables::organizer_wallets_table();
		$sql     = 'debit' === $entry_type
			? "UPDATE {$wallets} SET {$field} = GREATEST(0, {$field} - %f), updated_at = %s WHERE id = %d"
			: "UPDATE {$wallets} SET {$field} = {$field} + %f, updated_at = %s WHERE id = %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( $sql, $amount, current_time( 'mysql' ), (int) $wallet['id'] ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new = (float) $wpdb->get_var( $wpdb->prepare( "SELECT {$field} FROM {$wallets} WHERE id = %d", (int) $wallet['id'] ) );
		$wpdb->update( LNKup_Treasury_Tables::organizer_wallet_entries_table(), array( 'balance_after' => $new ), array( 'id' => $entry_id ) );

		return $entry_id;
	}

	/**
	 * Insert a ledger row; returns 0 when the idempotency key already exists.
	 *
	 * @param array $row Column values (created_at defaults to now).
	 * @return int Inserted row ID or 0.
	 */
	public static function insert_entry( array $row ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_wallet_entries_table();
		$key   = isset( $row['idempotency_key'] ) ? substr( sanitize_text_field( (string) $row['idempotency_key'] ), 0, 96 ) : '';
		if ( '' === $key || ! self::has_idempotency_column() ) {
			unset( $row['idempotency_key'] );
		} else {
			$row['idempotency_key'] = $key;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE idempotency_key = %s LIMIT 1", $key ) ) ) {
				return 0;
			}
		}
		if ( empty( $row['created_at'] ) ) {
			$row['created_at'] = current_time( 'mysql' );
		}
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( $table, $row );
		$wpdb->suppress_errors( $suppress );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Whether the ledger has the idempotency_key column (absent until the DB upgrade runs).
	 */
	public static function has_idempotency_column() {
		static $has = null;
		if ( null === $has ) {
			global $wpdb;
			$table = LNKup_Treasury_Tables::organizer_wallet_entries_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$has = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'idempotency_key' ) );
		}
		return $has;
	}

	/**
	 * Idempotency key for a ledger row tied to one payment and entry type.
	 *
	 * @param int    $payment_id Payment post ID.
	 * @param string $kind       Entry kind (credit, connect_settlement, …).
	 */
	public static function payment_key( $payment_id, $kind ) {
		return 'payment:' . absint( $payment_id ) . ':' . sanitize_key( $kind );
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
		if ( empty( $meta['idempotency_key'] ) && ! empty( $meta['payout_id'] ) ) {
			$meta['idempotency_key'] = 'payout:' . absint( $meta['payout_id'] ) . ':debit';
		}
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
		$amount    = round( (float) $amount, 2 );
		$payout_id = absint( $meta['payout_id'] ?? 0 );
		$entry_id  = self::insert_entry(
			array(
				'wallet_id'       => (int) $wallet['id'],
				'user_id'         => absint( $user_id ),
				'entry_type'      => 'debit',
				'amount'          => $amount,
				'balance_after'   => self::sum_connect_settlement( $user_id ) - $amount,
				'source_type'     => sanitize_key( $meta['source_type'] ?? 'payout' ),
				'source_id'       => absint( $meta['source_id'] ?? 0 ),
				'payout_id'       => $payout_id,
				'note'            => sanitize_text_field( $meta['note'] ?? '' ),
				'metadata'        => ! empty( $meta['metadata'] ) ? wp_json_encode( $meta['metadata'] ) : null,
				'idempotency_key' => $meta['idempotency_key'] ?? ( $payout_id ? 'payout:' . $payout_id . ':debit' : null ),
			)
		);
		if ( ! $entry_id ) {
			return 0;
		}
		$wallets = LNKup_Treasury_Tables::organizer_wallets_table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wallets} SET processing_balance = processing_balance + %f, updated_at = %s WHERE id = %d",
				$amount,
				current_time( 'mysql' ),
				(int) $wallet['id']
			)
		);
		return $entry_id;
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
	public static function list_entries( $user_id, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_wallet_entries_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				absint( $user_id ),
				absint( $limit ),
				absint( $offset )
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format_entry' ), (array) $rows );
	}

	/**
	 * Total ledger rows for an organizer (pagination).
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function count_entries( $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_wallet_entries_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", absint( $user_id ) ) );
	}

	/**
	 * Ledger row plus the references a reader needs to trace it (raw columns kept for BC).
	 *
	 * @param array $row Ledger row.
	 */
	public static function format_entry( $row ) {
		$payment_id = absint( $row['payment_post_id'] ?? 0 );
		$meta       = json_decode( (string) ( $row['metadata'] ?? '' ), true );
		$row['amount_cents'] = (int) round( (float) ( $row['amount'] ?? 0 ) * 100 );
		$row['reference']    = array(
			'payment_id'     => $payment_id,
			'payment_title'  => $payment_id ? get_the_title( $payment_id ) : '',
			'event_id'       => $payment_id ? (int) get_post_meta( $payment_id, '_lnkup_event_id', true ) : 0,
			'paid_at'        => $payment_id ? (string) get_post_meta( $payment_id, '_lnkup_paid_at', true ) : '',
			'payout_id'      => absint( $row['payout_id'] ?? 0 ),
			'intent_id'      => is_array( $meta ) ? (string) ( $meta['intent_id'] ?? '' ) : '',
			'charge_id'      => is_array( $meta ) ? (string) ( $meta['charge_id'] ?? '' ) : '',
		);
		unset( $row['idempotency_key'] );
		return $row;
	}
}
