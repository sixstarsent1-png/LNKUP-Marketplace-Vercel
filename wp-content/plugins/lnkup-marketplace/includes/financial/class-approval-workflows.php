<?php
/**
 * Threshold-based financial approval workflows.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Approval_Workflows {

	const DEFAULT_THRESHOLDS = array(
		'refund'  => 500,
		'payout'  => 5000,
		'credit'  => 100,
		'discount' => 30,
	);

	/**
	 * Check if action requires approval; create queue item if so.
	 *
	 * @param string $action_type Action type.
	 * @param float  $amount      Amount or percent.
	 * @param int    $user_id     Requesting user ID.
	 * @param array  $context     Extra context.
	 */
	public static function maybe_require( $action_type, $amount, $user_id, $context = array() ) {
		$threshold = self::get_threshold( $action_type );
		if ( 'discount' === $action_type ) {
			if ( $amount <= $threshold ) {
				return true;
			}
		} elseif ( $amount <= $threshold ) {
			return true;
		}

		global $wpdb;
		$table = LNKup_Treasury_Tables::financial_approvals_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return true;
		}
		$now = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'action_type'   => sanitize_key( $action_type ),
				'requested_by'  => absint( $user_id ),
				'amount'        => round( (float) $amount, 2 ),
				'status'        => 'pending',
				'reference_type' => sanitize_key( $context['reference_type'] ?? '' ),
				'reference_id'  => absint( $context['reference_id'] ?? 0 ),
				'metadata'      => ! empty( $context ) ? wp_json_encode( $context ) : null,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		LNKup_Financial_Audit::log( 'approval_required', 'approval', $id, array( 'type' => $action_type, 'amount' => $amount ) );
		return new WP_Error( 'approval_required', __( 'This action requires admin approval.', 'lnkup-marketplace' ), array( 'approval_id' => $id ) );
	}

	/**
	 * Get threshold for action type.
	 *
	 * @param string $action_type Action type.
	 */
	public static function get_threshold( $action_type ) {
		$settings = lnkup_get_settings();
		$key      = 'approval_threshold_' . sanitize_key( $action_type );
		if ( isset( $settings[ $key ] ) ) {
			return (float) $settings[ $key ];
		}
		return (float) ( self::DEFAULT_THRESHOLDS[ $action_type ] ?? 0 );
	}

	/**
	 * Approve pending item.
	 *
	 * @param int $approval_id Approval ID.
	 * @param int $admin_id    Admin user ID.
	 */
	public static function approve( $approval_id, $admin_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::financial_approvals_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $approval_id ) ), ARRAY_A );
		if ( ! $row || 'pending' !== $row['status'] ) {
			return false;
		}
		$wpdb->update(
			$table,
			array(
				'status'      => 'approved',
				'reviewed_by' => absint( $admin_id ),
				'reviewed_at' => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $approval_id )
		);
		LNKup_Financial_Audit::log( 'approval_granted', 'approval', $approval_id, array( 'admin' => $admin_id ) );
		return true;
	}

	/**
	 * Reject pending item.
	 *
	 * @param int    $approval_id Approval ID.
	 * @param int    $admin_id    Admin user ID.
	 * @param string $note        Rejection note.
	 */
	public static function reject( $approval_id, $admin_id, $note = '' ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::financial_approvals_table();
		$wpdb->update(
			$table,
			array(
				'status'      => 'rejected',
				'reviewed_by' => absint( $admin_id ),
				'reviewed_at' => current_time( 'mysql' ),
				'review_note' => sanitize_text_field( $note ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => absint( $approval_id ) )
		);
		LNKup_Financial_Audit::log( 'approval_rejected', 'approval', $approval_id, array( 'admin' => $admin_id ) );
		return true;
	}

	/**
	 * List pending approvals.
	 */
	public static function list_pending( $limit = 50 ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::financial_approvals_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d", absint( $limit ) ),
			ARRAY_A
		);
	}
}
