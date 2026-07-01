<?php
/**
 * Deposit management.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Deposits {

	public static function create_for_application( $application_id, $args = array() ) {
		$opp_id = (int) get_post_meta( $application_id, '_lnkup_opportunity_id', true );
		$total  = isset( $args['total_amount'] ) ? (float) $args['total_amount'] : self::default_total( $opp_id );
		$type   = $args['deposit_type'] ?? get_post_meta( $opp_id, '_lnkup_deposit_type', true ) ?: 'fixed';
		$amount = isset( $args['deposit_amount'] ) ? (float) $args['deposit_amount'] : (float) get_post_meta( $opp_id, '_lnkup_deposit_amount', true );
		$percent = isset( $args['deposit_percent'] ) ? (float) $args['deposit_percent'] : (float) get_post_meta( $opp_id, '_lnkup_deposit_percent', true );
		if ( 'percent' === $type && $percent > 0 ) {
			$amount = round( $total * ( $percent / 100 ), 2 );
		}
		if ( $amount <= 0 ) {
			return 0;
		}
		global $wpdb;
		$table = LNKup_Financial_Tables::deposits_table();
		$wpdb->insert(
			$table,
			array(
				'application_id'   => $application_id,
				'opportunity_id'   => $opp_id,
				'deposit_type'     => sanitize_key( $type ),
				'deposit_amount'   => $amount,
				'deposit_percent'  => $percent,
				'total_amount'     => $total,
				'paid_amount'      => 0,
				'remaining_amount' => $total - $amount,
				'deadline'         => $args['deadline'] ?? get_post_meta( $opp_id, '_lnkup_deposit_deadline', true ) ?: gmdate( 'Y-m-d', strtotime( '+14 days' ) ),
				'refund_rules'     => sanitize_textarea_field( $args['refund_rules'] ?? '' ),
				'status'           => 'required',
				'created_at'       => current_time( 'mysql' ),
			)
		);
		$deposit_id = (int) $wpdb->insert_id;
		update_post_meta( $application_id, '_lnkup_deposit_id', $deposit_id );
		LNKup_Financial_Audit::log( 'deposit_required', 'deposit', $deposit_id, array( 'amount' => $amount ) );
		return $deposit_id;
	}

	public static function default_total( $opp_id ) {
		$vendor_fee   = (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true );
		$organizer_id = (int) get_post_meta( $opp_id, '_lnkup_organizer_user_id', true );
		$event_id     = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		if ( class_exists( 'LNKup_Fee_Engine' ) ) {
			$fees = LNKup_Fee_Engine::calculate(
				array(
					'vendor_fee'   => $vendor_fee,
					'organizer_id' => $organizer_id,
					'event_id'     => $event_id,
				)
			);
			return (float) ( $fees['total'] ?? 0 );
		}
		$checkout_fee = (float) ( lnkup_get_settings()['vendor_checkout_fee'] ?? 5.99 );
		return round( $vendor_fee + $checkout_fee + ( ( $vendor_fee + $checkout_fee ) * 0.029 + 0.30 ), 2 );
	}

	public static function mark_paid( $deposit_id, $paid_amount ) {
		global $wpdb;
		$table   = LNKup_Financial_Tables::deposits_table();
		$deposit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $deposit_id ), ARRAY_A );
		if ( ! $deposit ) {
			return false;
		}
		$new_paid = (float) $deposit['paid_amount'] + (float) $paid_amount;
		$remaining = max( 0, (float) $deposit['total_amount'] - $new_paid );
		$status = $remaining <= 0 ? 'paid' : 'partial';
		$wpdb->update(
			$table,
			array(
				'paid_amount'      => $new_paid,
				'remaining_amount' => $remaining,
				'status'           => $status,
			),
			array( 'id' => $deposit_id ),
			array( '%f', '%f', '%s' ),
			array( '%d' )
		);
		LNKup_Financial_Audit::log( 'deposit_paid', 'deposit', $deposit_id, array( 'amount' => $paid_amount ) );
		return true;
	}

	public static function get_for_application( $application_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . LNKup_Financial_Tables::deposits_table() . ' WHERE application_id = %d ORDER BY id DESC LIMIT 1', $application_id ),
			ARRAY_A
		);
	}

	public static function checkout_amount( $application_id ) {
		$deposit = self::get_for_application( $application_id );
		if ( ! $deposit || 'paid' === $deposit['status'] ) {
			return 0;
		}
		if ( 'required' === $deposit['status'] || 'partial' === $deposit['status'] ) {
			return (float) $deposit['deposit_amount'] - (float) $deposit['paid_amount'];
		}
		return 0;
	}
}
