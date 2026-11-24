<?php
/**
 * Operations activity feed logger.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Operations_Log {

	/**
	 * Log an operations activity.
	 *
	 * @param int    $event_id       Event ID.
	 * @param string $activity_type  Activity slug.
	 * @param array  $args           Optional payload.
	 */
	public static function log( $event_id, $activity_type, $args = array() ) {
		global $wpdb;
		$wpdb->insert(
			LNKup_Operations_Tables::log_table(),
			array(
				'event_id'        => absint( $event_id ),
				'application_id'  => absint( $args['application_id'] ?? 0 ),
				'activity_type'   => sanitize_key( $activity_type ),
				'payload'         => wp_json_encode( $args['payload'] ?? $args ),
				'actor_user_id'   => absint( $args['actor_user_id'] ?? get_current_user_id() ),
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get feed items for event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $limit    Max items.
	 */
	public static function get_for_event( $event_id, $limit = 50 ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::log_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id = %d ORDER BY created_at DESC LIMIT %d",
				absint( $event_id ),
				absint( $limit )
			),
			ARRAY_A
		);
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::format( $row );
		}
		return $items;
	}

	/**
	 * Format log row for API/UI.
	 *
	 * @param array $row DB row.
	 */
	public static function format( $row ) {
		$payload = json_decode( $row['payload'] ?? '{}', true );
		$actor   = (int) ( $row['actor_user_id'] ?? 0 );
		$user    = $actor ? get_userdata( $actor ) : null;
		return array(
			'id'              => (int) $row['id'],
			'event_id'        => (int) $row['event_id'],
			'application_id'  => (int) $row['application_id'],
			'activity_type'   => $row['activity_type'],
			'label'           => self::label_for( $row['activity_type'], is_array( $payload ) ? $payload : array() ),
			'payload'         => is_array( $payload ) ? $payload : array(),
			'actor_name'      => $user ? $user->display_name : '',
			'created_at'      => $row['created_at'],
		);
	}

	/**
	 * Human label for activity type.
	 *
	 * @param string $type    Activity slug.
	 * @param array  $payload Payload data.
	 */
	public static function label_for( $type, $payload = array() ) {
		$labels = array(
			'checked_in'       => __( 'Vendor checked in', 'lnkup-marketplace' ),
			'setup_started'    => __( 'Setup started', 'lnkup-marketplace' ),
			'setup_complete'   => __( 'Setup complete', 'lnkup-marketplace' ),
			'checked_out'      => __( 'Vendor checked out', 'lnkup-marketplace' ),
			'late_arrival'     => __( 'Late arrival recorded', 'lnkup-marketplace' ),
			'no_show'          => __( 'No-show recorded', 'lnkup-marketplace' ),
			'booth_assigned'   => __( 'Booth assigned', 'lnkup-marketplace' ),
			'booth_unassigned' => __( 'Booth unassigned', 'lnkup-marketplace' ),
			'booths_swapped'   => __( 'Booths swapped', 'lnkup-marketplace' ),
			'parking_assigned' => __( 'Parking assigned', 'lnkup-marketplace' ),
			'loadin_assigned'  => __( 'Load-in window assigned', 'lnkup-marketplace' ),
			'support_opened'   => __( 'Support request opened', 'lnkup-marketplace' ),
			'support_resolved' => __( 'Support request resolved', 'lnkup-marketplace' ),
			'incident_created' => __( 'Incident reported', 'lnkup-marketplace' ),
			'broadcast_sent'   => __( 'Broadcast message sent', 'lnkup-marketplace' ),
			'status_changed'   => __( 'Vendor status updated', 'lnkup-marketplace' ),
			'document_approved'=> __( 'Document approved', 'lnkup-marketplace' ),
			'packet_viewed'    => __( 'Packet section viewed', 'lnkup-marketplace' ),
		);
		$label = $labels[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) );
		if ( ! empty( $payload['business_name'] ) ) {
			$label .= ' — ' . $payload['business_name'];
		}
		return $label;
	}
}
