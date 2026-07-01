<?php
/**
 * Configurable refund rules engine.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Refund_Rules {

	/**
	 * Calculate refund amount/type for a payment.
	 *
	 * @param int    $payment_id Payment post ID.
	 * @param string $requested_type Requested refund type.
	 * @param float  $requested_amount Requested amount.
	 */
	public static function calculate( $payment_id, $requested_type = 'full', $requested_amount = 0 ) {
		$paid    = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
		$status  = get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( 'paid' !== $status ) {
			return new WP_Error( 'not_paid', __( 'Payment is not paid.', 'lnkup-marketplace' ) );
		}

		$rule = self::match_rule( $payment_id );
		$type = $requested_type;
		$amount = $requested_amount > 0 ? $requested_amount : $paid;

		if ( $rule ) {
			if ( 'full_only' === ( $rule['refund_type'] ?? '' ) ) {
				$type   = 'full';
				$amount = $paid;
			}
			$max_pct = (float) ( $rule['max_percent'] ?? 100 );
			$amount  = min( $amount, round( $paid * ( $max_pct / 100 ), 2 ) );
			if ( ! empty( $rule['fee_amount'] ) ) {
				$amount = max( 0, $amount - (float) $rule['fee_amount'] );
			}
		}

		if ( 'full' === $type ) {
			$amount = $paid;
		}

		return array(
			'refund_type'   => $type,
			'amount'        => round( $amount, 2 ),
			'max_amount'    => $paid,
			'rule_id'       => $rule ? (int) $rule['id'] : 0,
			'approval_needed' => $amount > ( class_exists( 'LNKup_Approval_Workflows' ) ? LNKup_Approval_Workflows::get_threshold( 'refund' ) : 500 ),
		);
	}

	/**
	 * Match refund rule for payment context.
	 *
	 * @param int $payment_id Payment ID.
	 */
	private static function match_rule( $payment_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::refund_rules_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return null;
		}
		$event_id     = (int) get_post_meta( $payment_id, '_lnkup_event_id', true );
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rules = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active' ORDER BY priority ASC", ARRAY_A );
		foreach ( $rules as $rule ) {
			$scope = $rule['scope_type'] ?? 'global';
			$scope_id = $rule['scope_id'] ?? 0;
			if ( 'event' === $scope && (int) $scope_id !== $event_id ) {
				continue;
			}
			if ( 'organizer' === $scope && (int) $scope_id !== $organizer_id ) {
				continue;
			}
			return $rule;
		}
		return null;
	}

	/**
	 * List active refund rules.
	 */
	public static function list_rules() {
		global $wpdb;
		$table = LNKup_Treasury_Tables::refund_rules_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active' ORDER BY priority ASC", ARRAY_A );
	}
}
