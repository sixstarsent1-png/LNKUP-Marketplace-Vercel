<?php
/**
 * Load-in window scheduling.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Loadin_Schedule {

	/**
	 * Windows for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function get_windows( $event_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::loadin_windows_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY start_at ASC", absint( $event_id ) ),
			ARRAY_A
		);
		$items = array();
		foreach ( $rows as $row ) {
			$row['assigned_count'] = self::count_assignments( (int) $row['id'] );
			$row['slots_remaining'] = max( 0, (int) $row['max_slots'] - $row['assigned_count'] );
			$items[] = self::format_window( $row );
		}
		return $items;
	}

	/**
	 * Create load-in window.
	 *
	 * @param int   $event_id Event ID.
	 * @param array $data     Window data.
	 */
	public static function create_window( $event_id, $data ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::loadin_windows_table();
		$wpdb->insert(
			$table,
			array(
				'event_id'   => absint( $event_id ),
				'start_at'   => sanitize_text_field( $data['start_at'] ?? '' ),
				'end_at'     => sanitize_text_field( $data['end_at'] ?? '' ),
				'gate'       => sanitize_text_field( $data['gate'] ?? '' ),
				'max_slots'  => absint( $data['max_slots'] ?? 10 ),
				'created_at' => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Assign application to window.
	 *
	 * @param int $window_id Window ID.
	 * @param int $app_id    Application ID.
	 */
	public static function assign( $window_id, $app_id, $event_id = 0 ) {
		global $wpdb;
		$windows = LNKup_Operations_Tables::loadin_windows_table();
		$assign  = LNKup_Operations_Tables::loadin_assignments_table();
		$window  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$windows} WHERE id = %d", absint( $window_id ) ), ARRAY_A );
		if ( ! $window || ( $event_id && (int) $window['event_id'] !== absint( $event_id ) ) ) {
			return new WP_Error( 'not_found', __( 'Load-in window not found for this event.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! LNKup_Capabilities::application_in_event( $app_id, (int) $window['event_id'] ) ) {
			return new WP_Error( 'wrong_event', __( 'That vendor is not booked for this event.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$lock = 'lnkup_loadin_' . absint( $window_id );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'busy', __( 'Another change to this load-in window is in progress. Try again.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		try {
			$already = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$assign} WHERE window_id = %d AND application_id = %d", absint( $window_id ), absint( $app_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $already && self::count_assignments( $window_id ) >= (int) $window['max_slots'] ) {
				return new WP_Error( 'full', __( 'Load-in window is full.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
			}
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$assign,
				array(
					'window_id'      => absint( $window_id ),
					'application_id' => absint( $app_id ),
					'assigned_at'    => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s' )
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$label = gmdate( 'g:i A', strtotime( $window['start_at'] ) ) . ' – ' . gmdate( 'g:i A', strtotime( $window['end_at'] ) );
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
			update_post_meta( $packets[0], '_lnkup_load_in_time', $label . ' (Gate: ' . $window['gate'] . ')' );
		}
		LNKup_Vendor_Readiness::refresh( $app_id );
		LNKup_Operations_Log::log(
			(int) $window['event_id'],
			'loadin_assigned',
			array( 'application_id' => $app_id, 'payload' => array( 'window' => $label ) )
		);
		return true;
	}

	/**
	 * Get assignment for application.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function get_for_application( $app_id ) {
		global $wpdb;
		$assign  = LNKup_Operations_Tables::loadin_assignments_table();
		$windows = LNKup_Operations_Tables::loadin_windows_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT w.*, a.assigned_at FROM {$assign} a INNER JOIN {$windows} w ON w.id = a.window_id WHERE a.application_id = %d LIMIT 1",
				absint( $app_id )
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		return self::format_window( $row );
	}

	/**
	 * Count assignments in window.
	 *
	 * @param int $window_id Window ID.
	 */
	private static function count_assignments( $window_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::loadin_assignments_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE window_id = %d", absint( $window_id ) ) );
	}

	/**
	 * Format window row.
	 *
	 * @param array $row DB row.
	 */
	private static function format_window( $row ) {
		return array(
			'id'               => (int) $row['id'],
			'event_id'         => (int) $row['event_id'],
			'start_at'         => $row['start_at'],
			'end_at'           => $row['end_at'],
			'gate'             => $row['gate'],
			'max_slots'        => (int) $row['max_slots'],
			'assigned_count'   => (int) ( $row['assigned_count'] ?? self::count_assignments( (int) $row['id'] ) ),
			'slots_remaining'  => (int) ( $row['slots_remaining'] ?? 0 ),
			'label'            => gmdate( 'g:i A', strtotime( $row['start_at'] ) ) . ' – ' . gmdate( 'g:i A', strtotime( $row['end_at'] ) ),
		);
	}
}
