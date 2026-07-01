<?php
/**
 * Event ROI and profit tracking (vendor-private).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Growth_Roi {

	public static function get( $application_id, $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! LNKup_Capabilities::vendor_owns_application( $application_id, $user_id ) ) {
			return null;
		}
		global $wpdb;
		$roi_table = LNKup_Growth_Tables::roi_table();
		$profit_table = LNKup_Growth_Tables::profit_table();
		$roi = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$roi_table} WHERE application_id = %d AND vendor_user_id = %d", $application_id, $user_id ),
			ARRAY_A
		);
		$profit = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$profit_table} WHERE application_id = %d AND vendor_user_id = %d", $application_id, $user_id ),
			ARRAY_A
		);
		$meta = self::application_defaults( $application_id );
		return array_merge( $meta, array(
			'roi'    => $roi ? self::format_roi( $roi ) : null,
			'profit' => $profit ? self::format_profit( $profit ) : self::empty_profit(),
		) );
	}

	public static function save( $application_id, $user_id, $data ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! LNKup_Capabilities::vendor_owns_application( $application_id, $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$profit = self::save_profit( $application_id, $user_id, $data );
		$calc   = self::calculate( $profit, (float) ( $data['gross_revenue'] ?? $profit['gross_revenue'] ?? 0 ) );
		global $wpdb;
		$table = LNKup_Growth_Tables::roi_table();
		$event_id = (int) get_post_meta( $application_id, '_lnkup_event_id', true );
		$row = array(
			'vendor_user_id'      => $user_id,
			'application_id'      => $application_id,
			'event_id'            => $event_id,
			'gross_revenue'       => $calc['gross_revenue'],
			'estimated_expenses'  => $calc['estimated_expenses'],
			'estimated_profit'    => $calc['estimated_profit'],
			'profit_margin'       => $calc['profit_margin'],
			'worth_it_rating'     => sanitize_key( $data['worth_it_rating'] ?? '' ),
			'attendance'          => absint( $data['attendance'] ?? 0 ),
			'travel_distance'     => (float) ( $data['travel_distance'] ?? 0 ),
			'time_invested_hours' => (float) ( $data['time_invested_hours'] ?? 0 ),
			'organizer_rating'    => (float) ( $data['organizer_rating'] ?? 0 ),
			'would_recommend'     => ! empty( $data['would_recommend'] ) ? 1 : 0,
			'repeat_potential'    => sanitize_key( $data['repeat_potential'] ?? '' ),
			'notes'               => sanitize_textarea_field( $data['notes'] ?? '' ),
			'updated_at'          => current_time( 'mysql' ),
		);
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE application_id = %d AND vendor_user_id = %d", $application_id, $user_id ) );
		if ( $exists ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $exists ) );
		} else {
			$row['created_at'] = current_time( 'mysql' );
			$wpdb->insert( $table, $row );
		}
		LNKup_Growth_Kpis::invalidate_cache( $user_id );
		return self::get( $application_id, $user_id );
	}

	public static function list_completed( $user_id ) {
		$user_id = $user_id ?: get_current_user_id();
		$apps    = lnkup_get_applications_for_user( $user_id, 'vendor' );
		$items   = array();
		foreach ( $apps as $app ) {
			if ( 'completed' !== get_post_meta( $app->ID, '_lnkup_application_status', true ) ) {
				continue;
			}
			$items[] = self::get( $app->ID, $user_id );
		}
		return $items;
	}

	private static function save_profit( $application_id, $user_id, $data ) {
		global $wpdb;
		$table = LNKup_Growth_Tables::profit_table();
		$fields = array(
			'booth_fee', 'travel_cost', 'hotel', 'food', 'gas',
			'inventory_cost', 'staff_cost', 'marketing_cost', 'misc_expenses', 'gross_revenue',
		);
		$row = array( 'vendor_user_id' => $user_id, 'application_id' => $application_id, 'updated_at' => current_time( 'mysql' ) );
		foreach ( $fields as $f ) {
			if ( isset( $data[ $f ] ) ) {
				$row[ $f ] = (float) $data[ $f ];
			}
		}
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE application_id = %d AND vendor_user_id = %d", $application_id, $user_id ) );
		if ( $exists ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $exists ) );
		} else {
			$row['created_at'] = current_time( 'mysql' );
			$wpdb->insert( $table, $row );
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d", $application_id ), ARRAY_A );
	}

	private static function calculate( $profit, $gross_revenue ) {
		$expense_fields = array( 'booth_fee', 'travel_cost', 'hotel', 'food', 'gas', 'inventory_cost', 'staff_cost', 'marketing_cost', 'misc_expenses' );
		$expenses = 0;
		foreach ( $expense_fields as $f ) {
			$expenses += (float) ( $profit[ $f ] ?? 0 );
		}
		$gross  = (float) $gross_revenue;
		$profit_amt = $gross - $expenses;
		$margin = $gross > 0 ? round( ( $profit_amt / $gross ) * 100, 2 ) : 0;
		return array(
			'gross_revenue'      => round( $gross, 2 ),
			'estimated_expenses' => round( $expenses, 2 ),
			'estimated_profit'   => round( $profit_amt, 2 ),
			'profit_margin'      => $margin,
			'net_earnings'       => round( $profit_amt, 2 ),
		);
	}

	private static function application_defaults( $application_id ) {
		$app  = get_post( $application_id );
		$meta = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
		return array(
			'application_id' => (int) $application_id,
			'event_title'    => $meta['event_title'],
			'event_date'     => $meta['date'],
			'city'           => $meta['city'],
		);
	}

	private static function format_roi( $row ) {
		return array(
			'gross_revenue'       => (float) $row['gross_revenue'],
			'estimated_expenses'  => (float) $row['estimated_expenses'],
			'estimated_profit'    => (float) $row['estimated_profit'],
			'profit_margin'       => (float) $row['profit_margin'],
			'worth_it_rating'     => $row['worth_it_rating'],
			'attendance'          => (int) $row['attendance'],
			'travel_distance'     => (float) $row['travel_distance'],
			'time_invested_hours' => (float) $row['time_invested_hours'],
			'organizer_rating'    => (float) $row['organizer_rating'],
			'would_recommend'     => (bool) $row['would_recommend'],
			'repeat_potential'    => $row['repeat_potential'],
		);
	}

	private static function format_profit( $row ) {
		return array(
			'booth_fee'       => (float) $row['booth_fee'],
			'travel_cost'     => (float) $row['travel_cost'],
			'hotel'           => (float) $row['hotel'],
			'food'            => (float) $row['food'],
			'gas'             => (float) $row['gas'],
			'inventory_cost'  => (float) $row['inventory_cost'],
			'staff_cost'      => (float) $row['staff_cost'],
			'marketing_cost'  => (float) $row['marketing_cost'],
			'misc_expenses'   => (float) $row['misc_expenses'],
			'gross_revenue'   => (float) $row['gross_revenue'],
		);
	}

	private static function empty_profit() {
		return array(
			'booth_fee' => 0, 'travel_cost' => 0, 'hotel' => 0, 'food' => 0, 'gas' => 0,
			'inventory_cost' => 0, 'staff_cost' => 0, 'marketing_cost' => 0, 'misc_expenses' => 0, 'gross_revenue' => 0,
		);
	}
}
