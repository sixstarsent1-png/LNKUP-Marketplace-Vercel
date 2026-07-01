<?php
/**
 * Receipt generation.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Receipts {

	public static function generate_number() {
		return 'RCP-' . strtoupper( wp_generate_password( 8, false, false ) );
	}

	public static function issue_for_payment( $payment_id ) {
		$existing = self::get_by_payment( $payment_id );
		if ( $existing ) {
			return (int) $existing['id'];
		}
		$txn_id = LNKup_Transactions::record_from_payment_post( $payment_id, 'payment_completed' );
		global $wpdb;
		$table = LNKup_Financial_Tables::receipts_table();
		$now   = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'receipt_number'    => self::generate_number(),
				'transaction_id'  => $txn_id,
				'payment_post_id' => $payment_id,
				'vendor_user_id'    => (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ),
				'organizer_user_id' => (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true ),
				'amount'            => (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ),
				'payment_method'    => LNKup_Payment_Provider::get_active_provider_id(),
				'status'            => 'issued',
				'metadata'          => wp_json_encode( array(
					'event_id'       => (int) get_post_meta( $payment_id, '_lnkup_event_id', true ),
					'application_id' => (int) get_post_meta( $payment_id, '_lnkup_application_id', true ),
				) ),
				'created_at'        => $now,
			)
		);
		$receipt_id = (int) $wpdb->insert_id;
		LNKup_Financial_Audit::log( 'receipt_issued', 'receipt', $receipt_id, array(
			'amount' => (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ),
		) );
		do_action( 'lnkup_receipt_issued', $receipt_id, $payment_id );
		return $receipt_id;
	}

	public static function get_by_payment( $payment_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . LNKup_Financial_Tables::receipts_table() . ' WHERE payment_post_id = %d LIMIT 1', absint( $payment_id ) ),
			ARRAY_A
		);
	}

	public static function list_for_user( $user_id, $limit = 25 ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::receipts_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE vendor_user_id = %d ORDER BY created_at DESC LIMIT %d",
				$user_id,
				min( 50, max( 1, absint( $limit ) ) )
			),
			ARRAY_A
		);
	}

	public static function format( $row ) {
		if ( ! $row ) {
			return null;
		}
		$meta = ! empty( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : array();
		return array(
			'id'              => (int) $row['id'],
			'receipt_number'  => $row['receipt_number'],
			'amount'          => (float) $row['amount'],
			'payment_method'  => $row['payment_method'],
			'status'          => $row['status'],
			'payment_post_id' => (int) $row['payment_post_id'],
			'created_at'      => $row['created_at'],
			'metadata'        => is_array( $meta ) ? $meta : array(),
		);
	}
}
