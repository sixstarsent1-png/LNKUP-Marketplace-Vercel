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

	/**
	 * Earned platform fees net of refunds and lost chargebacks.
	 *
	 * @param string     $since       MySQL datetime lower bound ('' = all time).
	 * @param int[]|null $payment_ids Restrict to these payments (null = all).
	 */
	public static function sum_net_platform_fees( $since = '', $payment_ids = null ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::platform_fees_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0.0;
		}
		if ( is_array( $payment_ids ) ) {
			$payment_ids = array_values( array_filter( array_map( 'absint', $payment_ids ) ) );
			if ( ! $payment_ids ) {
				return 0.0;
			}
		}
		$sql = "SELECT COALESCE(SUM(GREATEST(f.lnkup_platform_fee - f.refunded_platform_fee, 0)), 0) FROM {$table} f
			INNER JOIN ( SELECT MIN(id) AS id FROM {$table} GROUP BY payment_post_id ) firsts ON firsts.id = f.id WHERE 1=1";
		if ( $since ) {
			$sql .= $wpdb->prepare( ' AND f.created_at >= %s', $since );
		}
		if ( is_array( $payment_ids ) ) {
			$sql .= ' AND f.payment_post_id IN (' . implode( ',', $payment_ids ) . ')';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return round( (float) $wpdb->get_var( $sql ), 2 );
	}

	/**
	 * Net earned fee for one payment.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function net_fee_for_payment( $payment_id ) {
		return self::sum_net_platform_fees( '', array( absint( $payment_id ) ) );
	}

	/**
	 * Recompute the refunded share of LNKup's fee for a payment.
	 *
	 * LNKup returns its fee in proportion to what the vendor got back: platform charges refund
	 * the whole amount from LNKup's balance, and destination refunds reverse only the
	 * organizer's transfer, so LNKup covers the fee share. A lost chargeback forfeits all of it.
	 *
	 * @param int  $payment_id     Payment post ID.
	 * @param int  $returned_cents Cumulative cents returned to the vendor.
	 * @param bool $forfeit_all    Lost chargeback.
	 */
	public static function sync_refunded_fee( $payment_id, $returned_cents, $forfeit_all = false ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::platform_fees_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return;
		}
		$payment_id = absint( $payment_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, lnkup_platform_fee FROM {$table} WHERE payment_post_id = %d ORDER BY id ASC LIMIT 1", $payment_id ), ARRAY_A );
		if ( ! $row ) {
			return;
		}
		$fee   = (float) $row['lnkup_platform_fee'];
		$gross = (int) round( (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ) * 100 );
		if ( $forfeit_all ) {
			$refunded = $fee;
		} elseif ( $gross > 0 ) {
			$refunded = round( $fee * min( 1, max( 0, (int) $returned_cents ) / $gross ), 2 );
		} else {
			$refunded = 0.0;
		}
		$wpdb->update( $table, array( 'refunded_platform_fee' => $refunded ), array( 'id' => (int) $row['id'] ), array( '%f' ), array( '%d' ) );
	}

	/**
	 * Cumulative cents returned to the vendor (card + credit) from payment meta.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function returned_cents( $payment_id ) {
		$card   = (float) get_post_meta( $payment_id, '_lnkup_refund_amount', true );
		$credit = (float) get_post_meta( $payment_id, '_lnkup_credit_refund_amount', true );
		return (int) round( ( $card + $credit ) * 100 );
	}

	/**
	 * Recorded vs expected refunded fee for a payment, or null when they already agree
	 * (or the payment has no fee row). Expected uses the same rule as sync_refunded_fee().
	 *
	 * @param int $payment_id Payment post ID.
	 * @return array{fee:float,recorded:float,expected:float}|null
	 */
	public static function refunded_fee_gap( $payment_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::platform_fees_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT lnkup_platform_fee, refunded_platform_fee FROM {$table} WHERE payment_post_id = %d ORDER BY id ASC LIMIT 1", absint( $payment_id ) ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$fee   = (float) $row['lnkup_platform_fee'];
		$gross = (int) round( (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ) * 100 );
		if ( 'chargeback_lost' === (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) ) {
			$expected = $fee;
		} elseif ( $gross > 0 ) {
			$expected = round( $fee * min( 1, self::returned_cents( $payment_id ) / $gross ), 2 );
		} else {
			$expected = 0.0;
		}
		$recorded = round( (float) $row['refunded_platform_fee'], 2 );
		if ( abs( $expected - $recorded ) < 0.005 ) {
			return null;
		}
		return array(
			'fee'      => $fee,
			'recorded' => $recorded,
			'expected' => $expected,
		);
	}

	/**
	 * Bring one payment's refunded fee in line with its refund/chargeback history (idempotent).
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function resync_refunded_fee( $payment_id ) {
		$forfeit = 'chargeback_lost' === (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
		self::sync_refunded_fee( $payment_id, self::returned_cents( $payment_id ), $forfeit );
		return null === self::refunded_fee_gap( $payment_id );
	}

	/**
	 * Refunded / charged-back payments whose fee row is out of date, grouped by organizer.
	 * Used for the one-time backfill of refunds that happened before 12.25.0.
	 *
	 * @return array<int, int[]> organizer_user_id => payment IDs.
	 */
	public static function backfill_candidates( $limit = 2000 ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::platform_fees_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT f.payment_post_id AS pid, o.meta_value AS organizer
				FROM {$table} f
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = f.payment_post_id AND s.meta_key = '_lnkup_payment_status'
					AND s.meta_value IN ('refunded','partially_refunded','chargeback_lost','paid','disputed')
				LEFT JOIN {$wpdb->postmeta} o ON o.post_id = f.payment_post_id AND o.meta_key = '_lnkup_organizer_user_id'
				LEFT JOIN {$wpdb->postmeta} r ON r.post_id = f.payment_post_id AND r.meta_key = '_lnkup_refund_amount'
				LEFT JOIN {$wpdb->postmeta} c ON c.post_id = f.payment_post_id AND c.meta_key = '_lnkup_credit_refund_amount'
				WHERE s.meta_value = 'chargeback_lost' OR CAST(COALESCE(r.meta_value, 0) AS DECIMAL(14,2)) > 0 OR CAST(COALESCE(c.meta_value, 0) AS DECIMAL(14,2)) > 0
				LIMIT %d",
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$pid = (int) $row['pid'];
			if ( null !== self::refunded_fee_gap( $pid ) ) {
				$out[ (int) $row['organizer'] ][] = $pid;
			}
		}
		return $out;
	}
}
