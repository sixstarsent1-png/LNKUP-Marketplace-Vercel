<?php
/**
 * Platform fee ledger records.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Platform_Fees {

	/**
	 * Record fee breakdown for a completed payment.
	 *
	 * @param int   $payment_id Payment post ID.
	 * @param array $breakdown  Fee breakdown from LNKup_Fee_Engine.
	 */
	public static function record( $payment_id, $breakdown ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::platform_fees_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE payment_post_id = %d LIMIT 1", absint( $payment_id ) ) );
		if ( $existing ) {
			return $existing;
		}

		$txn_id = 0;
		if ( class_exists( 'LNKup_Transactions' ) ) {
			$txn = LNKup_Transactions::get_by_payment_post( $payment_id );
			$txn_id = $txn ? (int) $txn['id'] : 0;
		}

		$wpdb->insert(
			$table,
			array(
				'payment_post_id'        => absint( $payment_id ),
				'transaction_id'         => $txn_id,
				'gross_amount'           => (float) ( $breakdown['gross_amount'] ?? 0 ),
				'vendor_fee'             => (float) ( $breakdown['vendor_fee'] ?? 0 ),
				'organizer_net'          => (float) ( $breakdown['organizer_net'] ?? 0 ),
				'lnkup_platform_fee'     => (float) ( $breakdown['lnkup_platform_fee'] ?? $breakdown['platform_fee'] ?? 0 ),
				'processing_fee'         => (float) ( $breakdown['processing_fee'] ?? 0 ),
				'tax_amount'             => (float) ( $breakdown['tax_amount'] ?? 0 ),
				'discount_amount'        => (float) ( $breakdown['discount_amount'] ?? $breakdown['discount'] ?? 0 ),
				'credit_amount'          => (float) ( $breakdown['credit_amount'] ?? $breakdown['credit'] ?? 0 ),
				'reserve_amount'         => (float) ( $breakdown['reserve_amount'] ?? 0 ),
				'payout_eligible_amount' => (float) ( $breakdown['payout_eligible_amount'] ?? 0 ),
				'fee_rule_id'            => absint( $breakdown['fee_rule_id'] ?? 0 ),
				'metadata'               => ! empty( $breakdown['metadata'] ) ? wp_json_encode( $breakdown['metadata'] ) : null,
				'created_at'             => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Sum platform fees for admin reporting.
	 *
	 * @param string $since MySQL datetime.
	 */
	public static function sum_platform_fees( $since = '' ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::platform_fees_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0.0;
		}
		// One row per payment: repeated settlement (fixed in 12.22) left duplicate rows behind.
		$sql = "SELECT COALESCE(SUM(f.lnkup_platform_fee), 0) FROM {$table} f
			INNER JOIN ( SELECT MIN(id) AS id FROM {$table} GROUP BY payment_post_id ) firsts ON firsts.id = f.id";
		if ( $since ) {
			$sql .= $wpdb->prepare( ' WHERE f.created_at >= %s', $since );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (float) $wpdb->get_var( $sql );
	}
}
