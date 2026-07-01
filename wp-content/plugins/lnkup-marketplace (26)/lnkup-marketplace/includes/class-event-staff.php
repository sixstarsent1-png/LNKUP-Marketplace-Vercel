<?php
/**
 * Event operations staff assignments.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Staff {

	public static function roles() {
		return array(
			'coordinator' => __( 'Vendor Coordinator', 'lnkup-marketplace' ),
			'parking'     => __( 'Parking Team', 'lnkup-marketplace' ),
			'load_in'     => __( 'Load-In Lead', 'lnkup-marketplace' ),
			'operations'  => __( 'Operations Manager', 'lnkup-marketplace' ),
			'power'       => __( 'Power Team', 'lnkup-marketplace' ),
			'medical'     => __( 'Medical', 'lnkup-marketplace' ),
			'security'    => __( 'Security', 'lnkup-marketplace' ),
		);
	}

	/**
	 * List staff for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function get_for_event( $event_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::staff_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY staff_role ASC", absint( $event_id ) ),
			ARRAY_A
		);
		$items = array();
		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row['user_id'] );
			$items[] = array(
				'id'          => (int) $row['id'],
				'user_id'     => (int) $row['user_id'],
				'name'        => $user ? $user->display_name : '',
				'email'       => $user ? $user->user_email : '',
				'staff_role'  => $row['staff_role'],
				'role_label'  => self::roles()[ $row['staff_role'] ] ?? $row['staff_role'],
				'created_at'  => $row['created_at'],
			);
		}
		return $items;
	}

	/**
	 * Assign staff to event.
	 *
	 * @param int    $event_id Event ID.
	 * @param int    $user_id  Staff user ID.
	 * @param string $role     Staff role slug.
	 */
	public static function assign( $event_id, $user_id, $role ) {
		global $wpdb;
		$role = sanitize_key( $role );
		if ( ! isset( self::roles()[ $role ] ) ) {
			return new WP_Error( 'invalid_role', __( 'Invalid staff role.', 'lnkup-marketplace' ) );
		}
		$wpdb->replace(
			LNKup_Operations_Tables::staff_table(),
			array(
				'event_id'    => absint( $event_id ),
				'user_id'     => absint( $user_id ),
				'staff_role'  => $role,
				'assigned_by' => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove staff from event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $user_id  Staff user ID.
	 */
	public static function remove( $event_id, $user_id ) {
		global $wpdb;
		$wpdb->delete(
			LNKup_Operations_Tables::staff_table(),
			array(
				'event_id' => absint( $event_id ),
				'user_id'  => absint( $user_id ),
			),
			array( '%d', '%d' )
		);
		return true;
	}

	/**
	 * Check if user is staff on event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $user_id  User ID.
	 */
	public static function is_staff( $event_id, $user_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::staff_table();
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE event_id = %d AND user_id = %d LIMIT 1", absint( $event_id ), absint( $user_id ) )
		);
	}
}
