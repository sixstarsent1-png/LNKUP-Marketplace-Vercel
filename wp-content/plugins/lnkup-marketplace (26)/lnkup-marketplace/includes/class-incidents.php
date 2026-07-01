<?php
/**
 * Event incident management.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Incidents {

	public static function types() {
		return array(
			'medical'           => __( 'Medical', 'lnkup-marketplace' ),
			'security'          => __( 'Security', 'lnkup-marketplace' ),
			'vendor_dispute'    => __( 'Vendor Dispute', 'lnkup-marketplace' ),
			'rule_violation'    => __( 'Rule Violation', 'lnkup-marketplace' ),
			'equipment_failure' => __( 'Equipment Failure', 'lnkup-marketplace' ),
			'weather'           => __( 'Weather', 'lnkup-marketplace' ),
			'power'             => __( 'Power', 'lnkup-marketplace' ),
			'property_damage'   => __( 'Property Damage', 'lnkup-marketplace' ),
			'lost_item'         => __( 'Lost Item', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Create incident.
	 *
	 * @param int   $event_id Event ID.
	 * @param int   $user_id  Reporter user ID.
	 * @param array $data     Incident data.
	 */
	public static function create( $event_id, $user_id, $data ) {
		global $wpdb;
		$photos = isset( $data['photos'] ) && is_array( $data['photos'] ) ? array_map( 'absint', $data['photos'] ) : array();
		$wpdb->insert(
			LNKup_Operations_Tables::incidents_table(),
			array(
				'event_id'           => absint( $event_id ),
				'incident_type'      => sanitize_key( $data['type'] ?? 'other' ),
				'location'           => sanitize_text_field( $data['location'] ?? '' ),
				'notes'              => sanitize_textarea_field( $data['notes'] ?? '' ),
				'photos'             => wp_json_encode( $photos ),
				'assigned_staff_id'  => absint( $data['assigned_staff_id'] ?? 0 ),
				'status'             => 'open',
				'reporter_user_id'   => absint( $user_id ),
				'created_at'         => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		LNKup_Operations_Log::log( $event_id, 'incident_created', array( 'payload' => array( 'incident_id' => $id, 'type' => $data['type'] ?? '' ) ) );
		return $id;
	}

	/**
	 * List incidents for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function get_for_event( $event_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::incidents_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY created_at DESC", absint( $event_id ) ),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format' ), $rows ?: array() );
	}

	/**
	 * Update incident.
	 *
	 * @param int   $id   Incident ID.
	 * @param array $data Update data.
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::incidents_table();
		$update = array();
		if ( isset( $data['status'] ) ) {
			$update['status'] = sanitize_key( $data['status'] );
		}
		if ( isset( $data['assigned_staff_id'] ) ) {
			$update['assigned_staff_id'] = absint( $data['assigned_staff_id'] );
		}
		if ( isset( $data['resolution'] ) ) {
			$update['resolution'] = sanitize_textarea_field( $data['resolution'] );
			$update['resolved_at'] = current_time( 'mysql' );
			$update['status'] = 'resolved';
		}
		if ( $update ) {
			$wpdb->update( $table, $update, array( 'id' => absint( $id ) ) );
		}
		return true;
	}

	/**
	 * Format incident row.
	 *
	 * @param array $row DB row.
	 */
	public static function format( $row ) {
		$reporter = get_userdata( (int) $row['reporter_user_id'] );
		$staff    = (int) $row['assigned_staff_id'] ? get_userdata( (int) $row['assigned_staff_id'] ) : null;
		return array(
			'id'             => (int) $row['id'],
			'event_id'       => (int) $row['event_id'],
			'type'           => $row['incident_type'],
			'type_label'     => self::types()[ $row['incident_type'] ] ?? $row['incident_type'],
			'location'       => $row['location'],
			'notes'          => $row['notes'],
			'photos'         => json_decode( $row['photos'] ?: '[]', true ),
			'status'         => $row['status'],
			'resolution'     => $row['resolution'] ?? '',
			'reporter_name'  => $reporter ? $reporter->display_name : '',
			'assigned_staff' => $staff ? $staff->display_name : '',
			'created_at'     => $row['created_at'],
			'resolved_at'    => $row['resolved_at'] ?? null,
		);
	}
}
