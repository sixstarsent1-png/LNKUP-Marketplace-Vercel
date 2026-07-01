<?php
/**
 * Load-in and traffic route paths.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Map_Routes {

	public static function route_types() {
		return array(
			'load_in'   => __( 'Load-In Path', 'lnkup-marketplace' ),
			'parking'   => __( 'Parking Path', 'lnkup-marketplace' ),
			'exit'      => __( 'Exit Path', 'lnkup-marketplace' ),
			'entrance'  => __( 'Vendor Entrance', 'lnkup-marketplace' ),
			'traffic'   => __( 'Traffic Flow', 'lnkup-marketplace' ),
			'walking'   => __( 'Walking Route', 'lnkup-marketplace' ),
			'restricted'=> __( 'Restricted Zone', 'lnkup-marketplace' ),
		);
	}

	public static function get_for_event( $event_id ) {
		global $wpdb;
		$table = LNKup_Map_Tables::routes_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY route_type ASC", absint( $event_id ) ),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format' ), $rows ?: array() );
	}

	public static function save( $event_id, $data ) {
		global $wpdb;
		$table = LNKup_Map_Tables::routes_table();
		$id    = absint( $data['id'] ?? 0 );
		$now   = current_time( 'mysql' );
		$path  = $data['path'] ?? $data['path_json'] ?? array();
		if ( is_string( $path ) ) {
			$path = json_decode( $path, true ) ?: array();
		}
		$row = array(
			'event_id'    => absint( $event_id ),
			'map_id'      => absint( $data['map_id'] ?? 0 ),
			'route_type'  => sanitize_key( $data['route_type'] ?? 'load_in' ),
			'label'       => sanitize_text_field( $data['label'] ?? '' ),
			'path_json'   => wp_json_encode( $path ),
			'direction'   => sanitize_key( $data['direction'] ?? 'one_way' ),
			'restricted'  => ! empty( $data['restricted'] ) ? 1 : 0,
			'updated_at'  => $now,
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
			'id'          => (int) $row['id'],
			'event_id'    => (int) $row['event_id'],
			'map_id'      => (int) $row['map_id'],
			'route_type'  => $row['route_type'],
			'label'       => $row['label'],
			'path'        => json_decode( $row['path_json'] ?? '[]', true ) ?: array(),
			'direction'   => $row['direction'],
			'restricted'  => (bool) $row['restricted'],
		);
	}
}
