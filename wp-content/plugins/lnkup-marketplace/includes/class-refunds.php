<?php
/**
 * Refund management.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Refunds {

	public static function request( $payment_id, $amount, $reason, $refund_type = 'full' ) {
		$payment = get_post( $payment_id );
		if ( ! $payment || 'lnkup_payment' !== $payment->post_type ) {
			return new WP_Error( 'invalid', __( 'Invalid payment.', 'lnkup-marketplace' ) );
		}
		if ( class_exists( 'LNKup_Refund_Rules' ) ) {
			$calc = LNKup_Refund_Rules::calculate( $payment_id, $refund_type, $amount );
			if ( is_wp_error( $calc ) ) {
				return $calc;
			}
			$amount      = (float) $calc['amount'];
			$refund_type = $calc['refund_type'];
		}
		$txn = LNKup_Transactions::get_by_payment_post( $payment_id );
		global $wpdb;
		$table = LNKup_Financial_Tables::refunds_table();
		$now   = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'transaction_id'    => $txn ? (int) $txn['id'] : 0,
				'payment_post_id'   => $payment_id,
				'vendor_user_id'    => (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ),
				'organizer_user_id' => (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true ),
				'refund_type'       => sanitize_key( $refund_type ),
				'amount'            => (float) $amount,
				'reason'            => sanitize_textarea_field( $reason ),
				'status'            => 'pending',
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$refund_id = (int) $wpdb->insert_id;
		LNKup_Financial_Audit::log( 'refund_requested', 'refund', $refund_id, array( 'amount' => $amount ) );
		LNKup_Fraud_Flags::check_refund_patterns( (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ) );
		return $refund_id;
	}

	public static function approve( $refund_id, $approver_id = 0 ) {
		global $wpdb;
		$table  = LNKup_Financial_Tables::refunds_table();
		$refund = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $refund_id ), ARRAY_A );
		if ( ! $refund || 'pending' !== $refund['status'] ) {
			return new WP_Error( 'invalid', __( 'Invalid refund request.', 'lnkup-marketplace' ) );
		}
		$provider = LNKup_Payment_Provider::get_instance();
		$result   = $provider->refund( (int) $refund['payment_post_id'], (float) $refund['amount'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$provider_ref = is_array( $result ) ? ( $result['provider_ref'] ?? '' ) : '';
		$wpdb->update(
			$table,
			array(
				'status'       => 'approved',
				'approved_by'  => $approver_id ?: get_current_user_id(),
				'provider_ref' => $provider_ref,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $refund_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
		update_post_meta( (int) $refund['payment_post_id'], '_lnkup_payment_status', 'refunded' );
		if ( 'credit' === $refund['refund_type'] || 'marketplace_credit' === $refund['refund_type'] ) {
			LNKup_Credits::grant( (int) $refund['vendor_user_id'], (float) $refund['amount'], 'refund', 'refund', $refund_id );
		}
		LNKup_Transactions::create( array(
			'type'              => 'refund',
			'status'            => 'completed',
			'amount'            => (float) $refund['amount'],
			'vendor_user_id'    => (int) $refund['vendor_user_id'],
			'organizer_user_id' => (int) $refund['organizer_user_id'],
			'payment_post_id'   => (int) $refund['payment_post_id'],
			'provider_ref'      => $provider_ref,
		) );
		LNKup_Financial_Audit::log( 'refund_issued', 'refund', $refund_id, array( 'amount' => (float) $refund['amount'] ) );
		do_action( 'lnkup_refund_completed', $refund_id );
		return true;
	}

	public static function deny( $refund_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::refunds_table();
		$wpdb->update(
			$table,
			array( 'status' => 'denied', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $refund_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		LNKup_Financial_Audit::log( 'refund_denied', 'refund', $refund_id );
	}

	public static function list_for_user( $user_id, $role = 'vendor', $limit = 25 ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::refunds_table();
		$col   = 'vendor' === $role ? 'vendor_user_id' : 'organizer_user_id';
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$col} = %d ORDER BY created_at DESC LIMIT %d", $user_id, min( 50, absint( $limit ) ) ),
			ARRAY_A
		);
	}

	public static function count_for_user( $user_id, $role = 'vendor' ) {
		global $wpdb;
		$col = 'vendor' === $role ? 'vendor_user_id' : 'organizer_user_id';
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::refunds_table() . " WHERE {$col} = %d", $user_id ) );
	}

	public static function pending_count_for_organizer( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::refunds_table() . " WHERE organizer_user_id = %d AND status = 'pending'", $user_id ) );
	}

	public static function pending_count_all() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . LNKup_Financial_Tables::refunds_table() . " WHERE status = 'pending'" );
	}

	public static function format( $row ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'              => (int) $row['id'],
			'payment_post_id' => (int) $row['payment_post_id'],
			'refund_type'     => $row['refund_type'],
			'amount'          => (float) $row['amount'],
			'reason'          => $row['reason'],
			'status'          => $row['status'],
			'created_at'      => $row['created_at'],
		);
	}
}
