<?php
/**
 * Payment dispute center (financial, not reputation).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Disputes {

	public static function open( $payment_id, $reason, $opened_by = 0 ) {
		$txn = LNKup_Transactions::get_by_payment_post( $payment_id );
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		$now   = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'transaction_id'    => $txn ? (int) $txn['id'] : 0,
				'payment_post_id'   => $payment_id,
				'dispute_type'      => 'payment',
				'opened_by'         => $opened_by ?: get_current_user_id(),
				'vendor_user_id'    => (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ),
				'organizer_user_id' => (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true ),
				'reason'            => sanitize_textarea_field( $reason ),
				'status'            => 'open',
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$dispute_id = (int) $wpdb->insert_id;
		update_post_meta( $payment_id, '_lnkup_payment_status', 'disputed' );
		LNKup_Financial_Audit::log( 'dispute_opened', 'dispute', $dispute_id );
		LNKup_Fraud_Flags::check_dispute_patterns( (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ) );
		return $dispute_id;
	}

	/**
	 * Open dispute from Stripe chargeback webhook.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $reason     Dispute reason.
	 */
	public static function open_from_chargeback( $payment_id, $reason = '' ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE payment_post_id = %d AND status IN ('open','pending','under_review')",
				$payment_id
			)
		);
		if ( $exists ) {
			return 0;
		}
		return self::open( $payment_id, $reason ?: __( 'Chargeback initiated', 'lnkup-marketplace' ), 0 );
	}

	public static function respond( $dispute_id, $evidence, $user_id = 0 ) {
		global $wpdb;
		$table   = LNKup_Financial_Tables::disputes_table();
		$dispute = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $dispute_id ), ARRAY_A );
		if ( ! $dispute ) {
			return new WP_Error( 'invalid', __( 'Dispute not found.', 'lnkup-marketplace' ) );
		}
		$existing = ! empty( $dispute['evidence'] ) ? json_decode( $dispute['evidence'], true ) : array();
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$existing[] = array(
			'user_id' => $user_id ?: get_current_user_id(),
			'message' => sanitize_textarea_field( $evidence ),
			'at'      => current_time( 'mysql' ),
		);
		$wpdb->update(
			$table,
			array(
				'evidence'   => wp_json_encode( $existing ),
				'status'     => 'pending',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $dispute_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		LNKup_Financial_Audit::log( 'dispute_responded', 'dispute', $dispute_id );
		do_action( 'lnkup_dispute_updated', $dispute_id );
		return true;
	}

	public static function resolve( $dispute_id, $status, $note = '', $resolver_id = 0 ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		$wpdb->update(
			$table,
			array(
				'status'          => sanitize_key( $status ),
				'resolution_note' => sanitize_textarea_field( $note ),
				'resolved_by'     => $resolver_id ?: get_current_user_id(),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $dispute_id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
		LNKup_Financial_Audit::log( 'dispute_resolved', 'dispute', $dispute_id, array( 'metadata' => array( 'status' => $status ) ) );
	}

	public static function list_for_user( $user_id, $role = 'vendor', $limit = 25 ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		$col   = 'vendor' === $role ? 'vendor_user_id' : 'organizer_user_id';
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$col} = %d ORDER BY created_at DESC LIMIT %d", $user_id, min( 50, absint( $limit ) ) ),
			ARRAY_A
		);
	}

	public static function open_count_for_organizer( $user_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE organizer_user_id = %d AND status IN ('open','pending')", $user_id ) );
	}

	public static function open_count_all() {
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('open','pending')" );
	}

	public static function format( $row ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'              => (int) $row['id'],
			'payment_post_id' => (int) $row['payment_post_id'],
			'reason'          => $row['reason'],
			'status'          => $row['status'],
			'created_at'      => $row['created_at'],
			'updated_at'      => $row['updated_at'],
		);
	}
}
