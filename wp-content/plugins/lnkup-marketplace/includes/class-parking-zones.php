<?php
/**
 * Parking zone map placements.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Parking_Zones {

	public static function zone_types() {
		return array(
			'vendor'      => __( 'Vendor Parking', 'lnkup-marketplace' ),
			'food_truck'  => __( 'Food Truck Parking', 'lnkup-marketplace' ),
			'staff'       => __( 'Staff Parking', 'lnkup-marketplace' ),
			'vip'         => __( 'VIP Parking', 'lnkup-marketplace' ),
			'oversized'   => __( 'Oversized Vehicles', 'lnkup-marketplace' ),
			'trailer'     => __( 'Trailer Parking', 'lnkup-marketplace' ),
		);
	}

	public static function get_for_event( $event_id ) {
		global $wpdb;
		$table = LNKup_Map_Tables::parking_zones_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY lot ASC", absint( $event_id ) ),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format' ), $rows ?: array() );
	}

	public static function save( $event_id, $data ) {
		global $wpdb;
		$table = LNKup_Map_Tables::parking_zones_table();
		$id    = absint( $data['id'] ?? 0 );
		$now   = current_time( 'mysql' );
		$row   = array(
			'event_id'     => absint( $event_id ),
			'map_id'       => absint( $data['map_id'] ?? 0 ),
			'lot'          => sanitize_text_field( $data['lot'] ?? '' ),
			'zone_type'    => sanitize_key( $data['zone_type'] ?? 'vendor' ),
			'pos_x'        => (float) ( $data['pos_x'] ?? 0 ),
			'pos_y'        => (float) ( $data['pos_y'] ?? 0 ),
			'width'        => (float) ( $data['width'] ?? 20 ),
			'height'       => (float) ( $data['height'] ?? 20 ),
			'capacity'     => (int) ( $data['capacity'] ?? 0 ),
			'instructions' => sanitize_textarea_field( $data['instructions'] ?? '' ),
			'updated_at'   => $now,
		);
		if ( $id ) {
			$wpdb->update( $table, $row, array( 'id' => $id ) );
			$row['id'] = $id;
		} else {
			$row['created_at'] = $now;
			$wpdb->insert( $table, $row );
			$row['id'] = (int) $wpdb->insert_id;
		}
		return self::format( $row );
	}

	public static function format( $row ) {
		return array(
			'id'           => (int) $row['id'],
			'event_id'     => (int) $row['event_id'],
			'map_id'       => (int) $row['map_id'],
			'lot'          => $row['lot'],
			'zone_type'    => $row['zone_type'],
			'zone_label'   => self::zone_types()[ $row['zone_type'] ] ?? $row['zone_type'],
			'pos_x'        => (float) $row['pos_x'],
			'pos_y'        => (float) $row['pos_y'],
			'width'        => (float) $row['width'],
			'height'       => (float) $row['height'],
			'capacity'     => (int) $row['capacity'],
			'instructions' => $row['instructions'] ?? '',
		);
	}
}
