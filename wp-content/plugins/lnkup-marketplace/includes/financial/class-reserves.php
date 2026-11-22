<?php
/**
 * Post-event reserve holds for organizer treasury.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Reserves {

	/**
	 * Create reserve hold after settlement.
	 *
	 * @param int   $payment_id Payment post ID.
	 * @param array $breakdown  Fee breakdown.
	 */
	public static function maybe_hold( $payment_id, $breakdown ) {
		$settings = lnkup_get_settings();
		$pct      = (float) ( $settings['reserve_percent'] ?? 0 );
		if ( $pct <= 0 ) {
			return 0;
		}
		$net = (float) ( $breakdown['organizer_net'] ?? 0 );
		if ( $net <= 0 ) {
			return 0;
		}
		$amount       = round( $net * ( $pct / 100 ), 2 );
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$event_id     = (int) get_post_meta( $payment_id, '_lnkup_event_id', true );
		if ( $amount <= 0 ) {
			return 0;
		}
		// Destination charges settle on the organizer's Stripe account; LNKup holds nothing to reserve.
		if ( self::is_destination_payment( $payment_id ) ) {
			return 0;
		}

		global $wpdb;
		$table = LNKup_Treasury_Tables::reserve_holds_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE payment_post_id = %d LIMIT 1", absint( $payment_id ) ) );
		if ( $existing ) {
			return $existing;
		}
		$now   = current_time( 'mysql' );
		$days  = absint( $settings['reserve_hold_days'] ?? 30 );
		$wpdb->insert(
			$table,
			array(
				'organizer_user_id' => $organizer_id,
				'event_id'          => $event_id,
				'payment_post_id'   => absint( $payment_id ),
				'amount'            => $amount,
				'status'            => 'active',
				'expires_at'        => gmdate( 'Y-m-d H:i:s', strtotime( "+{$days} days" ) ),
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		LNKup_Financial_Audit::log( 'reserve_held', 'reserve', $id, array( 'payment_id' => $payment_id, 'amount' => $amount ) );
		if ( isset( $breakdown['reserve_amount'] ) ) {
			$breakdown['reserve_amount'] = $amount;
		}
		return $id;
	}

	/**
	 * Sum active reserves for organizer.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function active_total( $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::reserve_holds_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0.0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount - used_amount), 0) FROM {$table} WHERE organizer_user_id = %d AND status = 'active'",
				absint( $user_id )
			)
		);
	}

	/**
	 * List reserve holds for organizer.
	 *
	 * @param int $user_id Organizer user ID.
	 * @param int $limit   Limit.
	 */
	public static function list_for_user( $user_id, $limit = 25 ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::reserve_holds_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE organizer_user_id = %d ORDER BY created_at DESC LIMIT %d",
				absint( $user_id ),
				absint( $limit )
			),
			ARRAY_A
		);
	}

	/**
	 * Release expired reserves via cron.
	 */
	public static function release_expired() {
		global $wpdb;
		$table = LNKup_Treasury_Tables::reserve_holds_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return;
		}
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= %s", $now ),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$remaining  = (float) $row['amount'] - (float) $row['used_amount'];
			$payment_id = (int) $row['payment_post_id'];
			$claimed    = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'released', released_at = %s, updated_at = %s WHERE id = %d AND status = 'active'",
					$now,
					$now,
					(int) $row['id']
				)
			);
			if ( 1 !== (int) $claimed || $remaining <= 0 || ! class_exists( 'LNKup_Organizer_Wallet' ) ) {
				continue;
			}
			// Only money LNKup actually held may be credited, and only once per payment: duplicate
			// holds from repeated settlement and holds on destination charges release without credit.
			if ( self::is_destination_payment( $payment_id ) ) {
				LNKup_Financial_Audit::log( 'reserve_release_skipped', 'reserve', (int) $row['id'], array( 'amount' => $remaining, 'reason' => 'destination_charge', 'payment_id' => $payment_id ) );
				continue;
			}
			$pay_status = $payment_id ? (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) : '';
			if ( in_array( $pay_status, array( 'refunded', 'partially_refunded', 'disputed', 'chargeback_lost' ), true ) ) {
				// The reserve covers exactly this: it is used, not paid out to the organizer.
				$wpdb->update( $table, array( 'status' => 'used', 'used_amount' => (float) $row['amount'], 'updated_at' => $now ), array( 'id' => (int) $row['id'] ) );
				LNKup_Financial_Audit::log( 'reserve_release_skipped', 'reserve', (int) $row['id'], array( 'amount' => $remaining, 'reason' => 'payment_' . $pay_status, 'payment_id' => $payment_id ) );
				continue;
			}
			$entry = LNKup_Organizer_Wallet::add_entry(
				(int) $row['organizer_user_id'],
				$remaining,
				'available',
				array(
					'source_type'     => 'reserve_release',
					'source_id'       => (int) $row['id'],
					'payment_post_id' => $payment_id,
					'note'            => __( 'Reserve hold released', 'lnkup-marketplace' ),
					'idempotency_key' => $payment_id ? 'payment:' . $payment_id . ':reserve_release' : 'reserve:' . (int) $row['id'] . ':release',
				)
			);
			if ( ! $entry ) {
				LNKup_Financial_Audit::log( 'reserve_release_skipped', 'reserve', (int) $row['id'], array( 'amount' => $remaining, 'reason' => 'already_released_for_payment', 'payment_id' => $payment_id ) );
			}
		}
	}

	/**
	 * Whether the charge settled on the organizer's connected account.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function is_destination_payment( $payment_id ) {
		return $payment_id > 0 && '' !== (string) get_post_meta( $payment_id, '_lnkup_stripe_destination_account', true );
	}
}
