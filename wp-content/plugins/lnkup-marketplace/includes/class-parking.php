<?php
/**
 * Parking assignment management.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Parking {

	public static function vehicle_types() {
		return array(
			'standard'  => __( 'Standard Vehicle', 'lnkup-marketplace' ),
			'trailer'   => __( 'Trailer', 'lnkup-marketplace' ),
			'food_truck'=> __( 'Food Truck', 'lnkup-marketplace' ),
			'oversized' => __( 'Oversized Vehicle', 'lnkup-marketplace' ),
		);
	}

	/**
	 * List parking for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function get_for_event( $event_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::parking_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY lot, row_label, space_number", absint( $event_id ) ),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format' ), $rows ?: array() );
	}

	/**
	 * Get parking for application.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function get_for_application( $app_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::parking_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d LIMIT 1", absint( $app_id ) ),
			ARRAY_A
		);
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Save parking assignment.
	 *
	 * @param int   $event_id Event ID.
	 * @param array $data     Parking data.
	 */
	public static function save( $event_id, $data ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::parking_table();
		$id    = absint( $data['id'] ?? 0 );
		$app_id = absint( $data['application_id'] ?? 0 );
		$qr    = sanitize_text_field( $data['qr_token'] ?? '' );
		if ( ! $qr && $app_id ) {
			$qr = 'PARK-' . strtoupper( wp_generate_password( 8, false ) );
		}
		$row = array(
			'event_id'       => absint( $event_id ),
			'application_id' => $app_id,
			'lot'            => sanitize_text_field( $data['lot'] ?? '' ),
			'row_label'      => sanitize_text_field( $data['row_label'] ?? '' ),
			'space_number'   => sanitize_text_field( $data['space_number'] ?? '' ),
			'vehicle_type'   => sanitize_key( $data['vehicle_type'] ?? 'standard' ),
			'gate'           => sanitize_text_field( $data['gate'] ?? '' ),
			'qr_token'       => $qr,
			'instructions'   => sanitize_textarea_field( $data['instructions'] ?? '' ),
			'updated_at'     => current_time( 'mysql' ),
		);
		if ( $id ) {
			$wpdb->update( $table, $row, array( 'id' => $id ) );
			$parking_id = $id;
		} else {
			$row['created_at'] = current_time( 'mysql' );
			$wpdb->insert( $table, $row );
			$parking_id = (int) $wpdb->insert_id;
		}
		if ( $app_id ) {
			self::sync_packet( $app_id, $row );
			LNKup_Vendor_Readiness::refresh( $app_id );
			LNKup_Operations_Log::log(
				$event_id,
				'parking_assigned',
				array( 'application_id' => $app_id, 'payload' => array( 'lot' => $row['lot'], 'space' => $row['space_number'] ) )
			);
		}
		return $parking_id;
	}

	/**
	 * Sync parking info to packet.
	 *
	 * @param int   $app_id Application ID.
	 * @param array $row    Parking row.
	 */
	private static function sync_packet( $app_id, $row ) {
		$info = sprintf(
			'%s — Row %s, Space %s (%s). Gate: %s',
			$row['lot'],
			$row['row_label'],
			$row['space_number'],
			self::vehicle_types()[ $row['vehicle_type'] ] ?? $row['vehicle_type'],
			$row['gate']
		);
		$packets = get_posts(
			array(
				'post_type'      => 'lnkup_packet',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_application_id',
				'meta_value'     => absint( $app_id ),
				'fields'         => 'ids',
			)
		);
		if ( $packets ) {
			update_post_meta( $packets[0], '_lnkup_parking_info', $info );
		}
	}

	/**
	 * Format parking row.
	 *
	 * @param array $row DB row.
	 */
	public static function format( $row ) {
		return array(
			'id'             => (int) $row['id'],
			'event_id'       => (int) $row['event_id'],
			'application_id' => (int) $row['application_id'],
			'lot'            => $row['lot'],
			'row_label'      => $row['row_label'],
			'space_number'   => $row['space_number'],
			'vehicle_type'   => $row['vehicle_type'],
			'gate'           => $row['gate'],
			'qr_token'       => $row['qr_token'],
			'instructions'   => $row['instructions'] ?? '',
			'label'          => trim( $row['lot'] . ' ' . $row['row_label'] . '-' . $row['space_number'] ),
		);
	}
}
