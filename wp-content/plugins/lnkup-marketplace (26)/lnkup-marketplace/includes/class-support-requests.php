<?php
/**
 * Vendor support requests.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Support_Requests {

	public static function categories() {
		return array(
			'power'     => __( 'Power', 'lnkup-marketplace' ),
			'water'     => __( 'Water', 'lnkup-marketplace' ),
			'parking'   => __( 'Parking', 'lnkup-marketplace' ),
			'medical'   => __( 'Medical', 'lnkup-marketplace' ),
			'booth'     => __( 'Booth', 'lnkup-marketplace' ),
			'security'  => __( 'Security', 'lnkup-marketplace' ),
			'generator' => __( 'Generator', 'lnkup-marketplace' ),
			'internet'  => __( 'Internet', 'lnkup-marketplace' ),
			'trash'     => __( 'Trash', 'lnkup-marketplace' ),
			'emergency' => __( 'Emergency', 'lnkup-marketplace' ),
			'other'     => __( 'Other', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Create support request.
	 *
	 * @param int   $vendor_id Vendor user ID.
	 * @param array $data      Request data.
	 */
	public static function create( $vendor_id, $data ) {
		global $wpdb;
		$app_id = absint( $data['application_id'] ?? 0 );
		if ( ! LNKup_Capabilities::vendor_owns_application( $app_id, $vendor_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$priority = sanitize_key( $data['priority'] ?? 'normal' );
		if ( 'emergency' === sanitize_key( $data['category'] ?? '' ) ) {
			$priority = 'urgent';
		}
		$wpdb->insert(
			LNKup_Operations_Tables::support_table(),
			array(
				'event_id'       => $event_id,
				'application_id' => $app_id,
				'vendor_user_id' => absint( $vendor_id ),
				'category'       => sanitize_key( $data['category'] ?? 'other' ),
				'priority'       => $priority,
				'status'         => 'open',
				'message'        => sanitize_textarea_field( $data['message'] ?? '' ),
				'created_at'     => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		LNKup_Event_Operations::set_ops_status( $app_id, 'needs_help' );
		LNKup_Operations_Log::log( $event_id, 'support_opened', array( 'application_id' => $app_id, 'payload' => array( 'request_id' => $id ) ) );
		return $id;
	}

	/**
	 * List support requests for a vendor application.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function get_for_application( $app_id ) {
		global $wpdb;
		$app_id = absint( $app_id );
		if ( ! $app_id ) {
			return array();
		}
		$table = LNKup_Operations_Tables::support_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d ORDER BY created_at DESC", $app_id ),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format' ), $rows ?: array() );
	}

	/**
	 * List for event.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $status   Optional status filter.
	 */
	public static function get_for_event( $event_id, $status = '' ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::support_table();
		if ( $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND status = %s ORDER BY FIELD(priority,'urgent','high','normal','low'), created_at DESC", absint( $event_id ), sanitize_key( $status ) ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY FIELD(priority,'urgent','high','normal','low'), created_at DESC", absint( $event_id ) ),
				ARRAY_A
			);
		}
		return array_map( array( __CLASS__, 'format' ), $rows ?: array() );
	}

	/**
	 * Update support request.
	 *
	 * @param int   $id   Request ID.
	 * @param array $data Update data.
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::support_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Request not found.', 'lnkup-marketplace' ) );
		}
		$update = array();
		if ( isset( $data['assigned_staff_id'] ) ) {
			$update['assigned_staff_id'] = absint( $data['assigned_staff_id'] );
			$update['responded_at'] = current_time( 'mysql' );
		}
		if ( isset( $data['status'] ) ) {
			$update['status'] = sanitize_key( $data['status'] );
			if ( 'resolved' === $update['status'] ) {
				$update['resolved_at'] = current_time( 'mysql' );
				LNKup_Operations_Log::log( (int) $row['event_id'], 'support_resolved', array( 'application_id' => (int) $row['application_id'] ) );
			}
		}
		if ( $update ) {
			$wpdb->update( $table, $update, array( 'id' => absint( $id ) ) );
		}
		return true;
	}

	/**
	 * Format row.
	 *
	 * @param array $row DB row.
	 */
	public static function format( $row ) {
		$user = get_userdata( (int) $row['vendor_user_id'] );
		$staff = (int) $row['assigned_staff_id'] ? get_userdata( (int) $row['assigned_staff_id'] ) : null;
		return array(
			'id'             => (int) $row['id'],
			'event_id'       => (int) $row['event_id'],
			'application_id' => (int) $row['application_id'],
			'category'       => $row['category'],
			'category_label' => self::categories()[ $row['category'] ] ?? $row['category'],
			'priority'       => $row['priority'],
			'status'         => $row['status'],
			'message'        => $row['message'],
			'vendor_name'    => $user ? $user->display_name : '',
			'assigned_staff' => $staff ? $staff->display_name : '',
			'created_at'     => $row['created_at'],
			'resolved_at'    => $row['resolved_at'] ?? null,
		);
	}
}
