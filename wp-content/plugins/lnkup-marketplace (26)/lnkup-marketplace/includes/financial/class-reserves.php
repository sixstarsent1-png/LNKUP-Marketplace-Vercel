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

		global $wpdb;
		$table = LNKup_Treasury_Tables::reserve_holds_table();
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
			$remaining = (float) $row['amount'] - (float) $row['used_amount'];
			$wpdb->update(
				$table,
				array( 'status' => 'released', 'released_at' => $now, 'updated_at' => $now ),
				array( 'id' => (int) $row['id'] )
			);
			if ( $remaining > 0 && class_exists( 'LNKup_Organizer_Wallet' ) ) {
				LNKup_Organizer_Wallet::add_entry(
					(int) $row['organizer_user_id'],
					$remaining,
					'available',
					array(
						'source_type'     => 'reserve_release',
						'source_id'       => (int) $row['id'],
						'payment_post_id' => (int) $row['payment_post_id'],
						'note'            => __( 'Reserve hold released', 'lnkup-marketplace' ),
					)
				);
			}
		}
	}
}
