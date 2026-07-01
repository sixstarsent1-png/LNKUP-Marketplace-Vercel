<?php
/**
 * Marketplace credits.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Credits {

	public static function grant( $user_id, $amount, $credit_type, $source = '', $source_id = 0, $expires_at = null ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::credits_table();
		$wpdb->insert(
			$table,
			array(
				'user_id'     => absint( $user_id ),
				'credit_type' => sanitize_key( $credit_type ),
				'amount'      => (float) $amount,
				'remaining'   => (float) $amount,
				'source'      => sanitize_key( $source ),
				'source_id'   => absint( $source_id ),
				'expires_at'  => $expires_at,
				'status'      => 'active',
				'created_at'  => current_time( 'mysql' ),
			)
		);
		$credit_id = (int) $wpdb->insert_id;
		LNKup_Wallet::credit( $user_id, $amount, $credit_type, $credit_id, 'vendor', $source );
		LNKup_Financial_Audit::log( 'credit_added', 'credit', $credit_id, array( 'amount' => $amount ) );
		return $credit_id;
	}

	public static function available_total( $user_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::credits_table();
		$sum   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(remaining), 0) FROM {$table} WHERE user_id = %d AND status = 'active' AND remaining > 0",
				$user_id
			)
		);
		return round( (float) $sum, 2 );
	}

	public static function apply_to_payment( $payment_id, $user_id, $amount ) {
		$available = self::available_total( $user_id );
		$apply     = min( $available, (float) $amount );
		if ( $apply <= 0 ) {
			return 0;
		}
		global $wpdb;
		$table   = LNKup_Financial_Tables::credits_table();
		$credits = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND status = 'active' AND remaining > 0 ORDER BY expires_at ASC, id ASC",
				$user_id
			),
			ARRAY_A
		);
		$remaining = $apply;
		foreach ( $credits as $credit ) {
			if ( $remaining <= 0 ) {
				break;
			}
			$use = min( (float) $credit['remaining'], $remaining );
			$new_rem = (float) $credit['remaining'] - $use;
			$wpdb->update(
				$table,
				array(
					'remaining' => $new_rem,
					'status'    => $new_rem <= 0 ? 'used' : 'active',
				),
				array( 'id' => (int) $credit['id'] ),
				array( '%f', '%s' ),
				array( '%d' )
			);
			$remaining -= $use;
		}
		LNKup_Wallet::debit( $user_id, $apply, 'checkout', $payment_id, 'vendor' );
		update_post_meta( $payment_id, '_lnkup_credit_applied', $apply );
		LNKup_Financial_Audit::log( 'credit_applied', 'payment', $payment_id, array( 'amount' => $apply ) );
		return $apply;
	}

	public static function list_for_user( $user_id, $limit = 25 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . LNKup_Financial_Tables::credits_table() . ' WHERE user_id = %d ORDER BY created_at DESC LIMIT %d',
				$user_id,
				min( 50, max( 1, absint( $limit ) ) )
			),
			ARRAY_A
		);
	}
}
