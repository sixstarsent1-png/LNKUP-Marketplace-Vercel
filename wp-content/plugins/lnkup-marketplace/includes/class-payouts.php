<?php
/**
 * Organizer payout management.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Payouts {

	const PLATFORM_FEE_RATE = 0.08;

	public static function available_balance( $organizer_id ) {
		$collected = LNKup_Financial::organizer_collected_revenue( $organizer_id );
		$paid_out  = self::completed_total( $organizer_id );
		$pending   = self::pending_total( $organizer_id );
		return max( 0, round( $collected - $paid_out - $pending, 2 ) );
	}

	public static function pending_balance( $organizer_id ) {
		return self::pending_total( $organizer_id );
	}

	public static function completed_total( $organizer_id ) {
		global $wpdb;
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(net_amount), 0) FROM " . LNKup_Financial_Tables::payouts_table() . " WHERE organizer_user_id = %d AND status = 'completed'",
				$organizer_id
			)
		);
		return round( (float) $sum, 2 );
	}

	public static function pending_total( $organizer_id ) {
		global $wpdb;
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(net_amount), 0) FROM " . LNKup_Financial_Tables::payouts_table() . " WHERE organizer_user_id = %d AND status IN ('pending','scheduled')",
				$organizer_id
			)
		);
		return round( (float) $sum, 2 );
	}

	public static function schedule_for_event( $organizer_id, $event_id, $gross_amount, $scheduled_date = null ) {
		$split        = LNKup_Stripe_Connect::calculate_payout_split( $gross_amount, $organizer_id );
		$platform_fee = $split['platform_fee'];
		$net          = $split['net_amount'];
		global $wpdb;
		$table = LNKup_Financial_Tables::payouts_table();
		$wpdb->insert(
			$table,
			array(
				'organizer_user_id' => $organizer_id,
				'event_id'          => $event_id,
				'gross_amount'      => $gross_amount,
				'platform_fee'      => $platform_fee,
				'net_amount'        => $net,
				'status'            => 'scheduled',
				'scheduled_date'    => $scheduled_date ?: gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
				'provider'          => 'manual',
				'estimated_arrival' => $scheduled_date ?: gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
				'created_at'        => current_time( 'mysql' ),
			)
		);
		$payout_id = (int) $wpdb->insert_id;
		LNKup_Financial_Audit::log( 'payout_scheduled', 'payout', $payout_id, array( 'amount' => $net ) );
		return $payout_id;
	}

	public static function mark_completed( $payout_id, $provider_ref = '' ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::payouts_table();
		$payout = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $payout_id ), ARRAY_A );
		if ( ! $payout ) {
			return false;
		}
		// Atomic claim so a payout is completed (and its transaction recorded) only once.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'completed', paid_at = %s, provider_ref = %s WHERE id = %d AND status IN ('scheduled','pending','processing')",
				current_time( 'mysql' ),
				sanitize_text_field( $provider_ref ),
				absint( $payout_id )
			)
		);
		if ( 1 !== (int) $claimed ) {
			return false;
		}
		LNKup_Transactions::create( array(
			'type'              => 'payout',
			'status'            => 'completed',
			'amount'            => (float) $payout['net_amount'],
			'organizer_user_id' => (int) $payout['organizer_user_id'],
			'event_id'          => (int) $payout['event_id'],
			'provider'          => $payout['provider'],
			'provider_ref'      => $provider_ref,
		) );
		LNKup_Financial_Audit::log( 'payout_sent', 'payout', $payout_id, array( 'amount' => (float) $payout['net_amount'] ) );
		return true;
	}

	public static function list_for_organizer( $organizer_id, $limit = 25 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . LNKup_Financial_Tables::payouts_table() . ' WHERE organizer_user_id = %d ORDER BY created_at DESC LIMIT %d',
				$organizer_id,
				min( 50, absint( $limit ) )
			),
			ARRAY_A
		);
	}

	public static function count_for_organizer( $organizer_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::payouts_table() . ' WHERE organizer_user_id = %d', $organizer_id ) );
	}

	public static function pending_count_all() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . LNKup_Financial_Tables::payouts_table() . " WHERE status IN ('pending','scheduled')" );
	}

	public static function format( $row ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'                => (int) $row['id'],
			'event_id'          => (int) $row['event_id'],
			'event_title'       => (int) $row['event_id'] ? lnkup_plain_title( (int) $row['event_id'] ) : '',
			'gross_amount'      => (float) $row['gross_amount'],
			'platform_fee'      => (float) $row['platform_fee'],
			'net_amount'        => (float) $row['net_amount'],
			'status'            => $row['status'],
			'scheduled_date'    => $row['scheduled_date'],
			'estimated_arrival' => $row['estimated_arrival'],
			'paid_at'           => $row['paid_at'],
		);
	}
}
