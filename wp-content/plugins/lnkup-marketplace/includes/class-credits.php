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

	/**
	 * Apply wallet credits to a pending payment. Idempotent: never credits more than the
	 * amount still uncovered, so retries and double taps cannot debit the wallet twice.
	 *
	 * @param int   $payment_id Payment post ID.
	 * @param int   $user_id    Vendor user ID (must own the payment).
	 * @param float $amount     Requested credit amount.
	 * @return float|WP_Error Amount newly applied.
	 */
	public static function apply_to_payment( $payment_id, $user_id, $amount ) {
		$payment_id = absint( $payment_id );
		$user_id    = absint( $user_id );
		if ( ! $payment_id || 'lnkup_payment' !== get_post_type( $payment_id ) ) {
			return new WP_Error( 'invalid_payment', __( 'Invalid payment.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ) !== $user_id ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( 'pending' !== (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) ) {
			return new WP_Error( 'payment_not_pending', __( 'Credits can only be applied to an unpaid booking.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}

		global $wpdb;
		$lock = 'lnkup_credit_apply_' . $user_id;
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'busy', __( 'Please wait a moment and try again.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		try {
			$already   = (float) get_post_meta( $payment_id, '_lnkup_credit_applied', true );
			$total     = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
			$uncovered = max( 0, round( $total - $already, 2 ) );
			$apply     = round( min( self::available_total( $user_id ), (float) $amount, $uncovered ), 2 );
			if ( $apply <= 0 ) {
				return 0;
			}
			self::consume( $user_id, $apply );
			LNKup_Wallet::debit( $user_id, $apply, 'checkout', $payment_id, 'vendor' );
			update_post_meta( $payment_id, '_lnkup_credit_applied', round( $already + $apply, 2 ) );
			LNKup_Financial_Audit::log( 'credit_applied', 'payment', $payment_id, array( 'amount' => $apply, 'total_applied' => round( $already + $apply, 2 ) ) );
			return $apply;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Give back wallet credit applied to a payment that will never be collected (voided / expired)
	 * or whose card refund cannot include the credit-covered portion. Idempotent per payment.
	 *
	 * @param int    $payment_id Payment post ID.
	 * @param string $reason     Audit reason.
	 * @param float  $amount     Amount to restore; 0 = everything still applied.
	 * @return float Amount restored.
	 */
	public static function restore_for_payment( $payment_id, $reason, $amount = 0.0 ) {
		$payment_id = absint( $payment_id );
		$user_id    = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		if ( ! $payment_id || ! $user_id ) {
			return 0.0;
		}
		global $wpdb;
		$lock = 'lnkup_credit_apply_' . $user_id;
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 0.0;
		}
		try {
			$applied  = (float) get_post_meta( $payment_id, '_lnkup_credit_applied', true );
			$restored = (float) get_post_meta( $payment_id, '_lnkup_credit_restored', true );
			$open     = max( 0, round( $applied - $restored, 2 ) );
			$give     = $amount > 0 ? round( min( (float) $amount, $open ), 2 ) : $open;
			if ( $give <= 0 ) {
				return 0.0;
			}
			self::grant( $user_id, $give, 'refund', sanitize_key( $reason ), $payment_id );
			update_post_meta( $payment_id, '_lnkup_credit_restored', round( $restored + $give, 2 ) );
			LNKup_Financial_Audit::log( 'credit_restored', 'payment', $payment_id, array( 'amount' => $give, 'reason' => sanitize_key( $reason ) ) );
			return $give;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Draw down credit rows oldest-expiring first.
	 *
	 * @param int   $user_id User ID.
	 * @param float $apply   Amount to consume.
	 */
	private static function consume( $user_id, $apply ) {
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
