<?php
/**
 * Fraud detection placeholders.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Fraud_Flags {

	public static function flag( $user_id, $flag_type, $details = '', $severity = 'medium' ) {
		global $wpdb;
		$wpdb->insert(
			LNKup_Financial_Tables::fraud_flags_table(),
			array(
				'user_id'    => absint( $user_id ),
				'flag_type'  => sanitize_key( $flag_type ),
				'severity'   => sanitize_key( $severity ),
				'details'    => sanitize_textarea_field( $details ),
				'status'     => 'open',
				'created_at' => current_time( 'mysql' ),
			)
		);
		LNKup_Financial_Audit::log( 'fraud_flagged', 'fraud_flag', (int) $wpdb->insert_id );
	}

	public static function check_refund_patterns( $user_id ) {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::refunds_table() . ' WHERE vendor_user_id = %d AND created_at >= %s',
				$user_id,
				gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
			)
		);
		if ( $count >= 3 ) {
			self::flag( $user_id, 'excessive_refunds', sprintf( '%d refunds in 30 days', $count ), 'high' );
		}
	}

	public static function check_dispute_patterns( $user_id ) {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::disputes_table() . ' WHERE vendor_user_id = %d AND created_at >= %s',
				$user_id,
				gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) )
			)
		);
		if ( $count >= 2 ) {
			self::flag( $user_id, 'suspicious_disputes', sprintf( '%d disputes in 90 days', $count ), 'medium' );
		}
	}

	public static function check_failed_payments( $user_id ) {
		$failed = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $user_id ),
					array( 'key' => '_lnkup_payment_status', 'value' => 'failed' ),
				),
			)
		);
		if ( count( $failed ) >= 5 ) {
			self::flag( $user_id, 'multiple_failed_payments', count( $failed ) . ' failed payments', 'high' );
		}
	}

	public static function open_count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . LNKup_Financial_Tables::fraud_flags_table() . " WHERE status = 'open'" );
	}

	public static function list_open( $limit = 25 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM " . LNKup_Financial_Tables::fraud_flags_table() . " WHERE status = 'open' ORDER BY created_at DESC LIMIT %d", min( 50, absint( $limit ) ) ),
			ARRAY_A
		);
	}

	public static function resolve( $flag_id, $reviewer_id = 0 ) {
		global $wpdb;
		$wpdb->update(
			LNKup_Financial_Tables::fraud_flags_table(),
			array( 'status' => 'resolved', 'reviewed_by' => $reviewer_id ?: get_current_user_id() ),
			array( 'id' => $flag_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}
}
