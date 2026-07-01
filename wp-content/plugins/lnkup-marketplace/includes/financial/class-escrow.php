<?php
/**
 * Escrow hold lifecycle for organizer treasury.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Escrow {

	public static function init() {
		add_action( 'lnkup_event_completed', array( __CLASS__, 'release_for_event' ), 10, 1 );
	}

	/**
	 * Whether payment has active escrow hold.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function has_active_hold( $payment_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::escrow_holds_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE payment_post_id = %d AND status = 'held' LIMIT 1",
				absint( $payment_id )
			)
		);
	}

	/**
	 * Create escrow hold after settlement if rules apply.
	 *
	 * @param int   $payment_id Payment post ID.
	 * @param array $breakdown  Fee breakdown.
	 */
	public static function maybe_hold( $payment_id, $breakdown ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['escrow_enabled'] ) ) {
			return 0;
		}
		$amount = (float) ( $breakdown['payout_eligible_amount'] ?? $breakdown['organizer_net'] ?? 0 );
		if ( $amount <= 0 ) {
			return 0;
		}
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$app_id       = (int) get_post_meta( $payment_id, '_lnkup_application_id', true );
		$hold_pct     = (float) ( $settings['escrow_hold_percent'] ?? 100 );
		$hold_amount  = round( $amount * ( $hold_pct / 100 ), 2 );
		if ( $hold_amount <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table = LNKup_Treasury_Tables::escrow_holds_table();
		$now   = current_time( 'mysql' );
		$release_days = absint( $settings['escrow_release_days'] ?? 7 );
		$wpdb->insert(
			$table,
			array(
				'payment_post_id'     => absint( $payment_id ),
				'application_id'      => $app_id,
				'organizer_user_id'   => $organizer_id,
				'amount'              => $hold_amount,
				'status'              => 'held',
				'hold_reason'         => 'pre_event',
				'release_at'          => gmdate( 'Y-m-d H:i:s', strtotime( "+{$release_days} days" ) ),
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);
		$hold_id = (int) $wpdb->insert_id;
		LNKup_Financial_Audit::log( 'escrow_held', 'escrow', $hold_id, array( 'payment_id' => $payment_id, 'amount' => $hold_amount ) );
		return $hold_id;
	}

	/**
	 * Release escrow for a payment.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function release( $payment_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::escrow_holds_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hold = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE payment_post_id = %d AND status = 'held' LIMIT 1", absint( $payment_id ) ),
			ARRAY_A
		);
		if ( ! $hold ) {
			return false;
		}
		$release = (float) $hold['amount'] - (float) $hold['released_amount'];
		if ( $release <= 0 ) {
			return false;
		}
		$wpdb->update(
			$table,
			array(
				'released_amount' => (float) $hold['amount'],
				'status'          => 'released',
				'released_at'     => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => (int) $hold['id'] )
		);
		if ( class_exists( 'LNKup_Organizer_Wallet' ) ) {
			LNKup_Organizer_Wallet::release_pending( (int) $hold['organizer_user_id'], $release, $payment_id );
		}
		LNKup_Financial_Audit::log( 'escrow_released', 'escrow', (int) $hold['id'], array( 'amount' => $release ) );
		return true;
	}

	/**
	 * Release all escrow holds tied to an event.
	 *
	 * @param int $event_id Event post ID.
	 */
	public static function release_for_event( $event_id ) {
		$payments = get_posts( array(
			'post_type'      => 'lnkup_payment',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array( 'key' => '_lnkup_event_id', 'value' => absint( $event_id ) ),
				array( 'key' => '_lnkup_payment_status', 'value' => 'paid' ),
			),
			'fields'         => 'ids',
		) );
		foreach ( $payments as $payment_id ) {
			self::release( $payment_id );
		}
	}

	/**
	 * List escrow holds for organizer.
	 *
	 * @param int $user_id Organizer user ID.
	 * @param int $limit   Limit.
	 */
	public static function list_for_user( $user_id, $limit = 25 ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::escrow_holds_table();
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
}
