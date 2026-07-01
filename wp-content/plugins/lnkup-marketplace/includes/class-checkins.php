<?php
/**
 * Vendor check-in and on-site status transitions.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Checkins {

	public static function actions() {
		return array(
			'checked_in'     => __( 'Checked In', 'lnkup-marketplace' ),
			'setup_started'  => __( 'Setup Started', 'lnkup-marketplace' ),
			'setup_complete' => __( 'Setup Complete', 'lnkup-marketplace' ),
			'checked_out'    => __( 'Checked Out', 'lnkup-marketplace' ),
			'late_arrival'   => __( 'Late Arrival', 'lnkup-marketplace' ),
			'no_show'        => __( 'No Show', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Record check-in action.
	 *
	 * @param int    $app_id Application ID.
	 * @param string $action Action slug.
	 * @param string $method manual|qr.
	 */
	public static function record( $app_id, $action, $method = 'manual' ) {
		$app_id = absint( $app_id );
		$action = sanitize_key( $action );
		if ( ! isset( self::actions()[ $action ] ) ) {
			return new WP_Error( 'invalid_action', __( 'Invalid check-in action.', 'lnkup-marketplace' ) );
		}
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		global $wpdb;
		$wpdb->insert(
			LNKup_Operations_Tables::checkins_table(),
			array(
				'event_id'       => $event_id,
				'application_id' => $app_id,
				'action'         => $action,
				'method'         => sanitize_key( $method ),
				'actor_user_id'  => get_current_user_id(),
				'created_at'     => current_time( 'mysql' ),
			)
		);
		self::apply_side_effects( $app_id, $action );
		LNKup_Operations_Log::log(
			$event_id,
			$action,
			array(
				'application_id' => $app_id,
				'payload'        => array(
					'method'        => $method,
					'business_name' => LNKup_Event_Operations::vendor_name_for_app( $app_id ),
				),
			)
		);
		return true;
	}

	/**
	 * Apply meta and ops status side effects.
	 *
	 * @param int    $app_id Application ID.
	 * @param string $action Action slug.
	 */
	private static function apply_side_effects( $app_id, $action ) {
		switch ( $action ) {
			case 'checked_in':
				update_post_meta( $app_id, '_lnkup_checked_in_at', current_time( 'mysql' ) );
				LNKup_Event_Operations::set_ops_status( $app_id, 'checked_in' );
				break;
			case 'setup_started':
				LNKup_Event_Operations::set_ops_status( $app_id, 'setting_up' );
				break;
			case 'setup_complete':
				LNKup_Event_Operations::set_ops_status( $app_id, 'live_ready' );
				break;
			case 'checked_out':
				update_post_meta( $app_id, '_lnkup_event_completed_at', current_time( 'mysql' ) );
				update_post_meta( $app_id, '_lnkup_application_status', 'completed' );
				LNKup_Event_Operations::set_ops_status( $app_id, 'checked_out' );
				break;
			case 'late_arrival':
				update_post_meta( $app_id, '_lnkup_late_arrival_at', current_time( 'mysql' ) );
				LNKup_Event_Operations::set_ops_status( $app_id, 'late' );
				break;
			case 'no_show':
				update_post_meta( $app_id, '_lnkup_no_show_at', current_time( 'mysql' ) );
				LNKup_Event_Operations::set_ops_status( $app_id, 'no_show' );
				break;
		}
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		if ( $profile_id && class_exists( 'LNKup_Vendor_Reputation' ) ) {
			LNKup_Vendor_Reputation::refresh( $profile_id );
		}
		LNKup_Vendor_Readiness::refresh( $app_id );
	}

	/**
	 * Scan QR token and check in vendor.
	 *
	 * @param string $token    QR pass token.
	 * @param int    $event_id Expected event ID.
	 */
	public static function scan_qr( $token, $event_id = 0 ) {
		$token = sanitize_text_field( $token );
		$packets = get_posts(
			array(
				'post_type'      => 'lnkup_packet',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_qr_pass',
				'meta_value'     => $token,
			)
		);
		if ( ! $packets ) {
			return new WP_Error( 'invalid_qr', __( 'Invalid QR pass.', 'lnkup-marketplace' ) );
		}
		$app_id = (int) get_post_meta( $packets[0]->ID, '_lnkup_application_id', true );
		$ev     = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		if ( $event_id && $ev !== (int) $event_id ) {
			return new WP_Error( 'wrong_event', __( 'QR pass is for a different event.', 'lnkup-marketplace' ) );
		}
		if ( get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
			return new WP_Error( 'already_checked_in', __( 'Vendor already checked in.', 'lnkup-marketplace' ), array( 'application_id' => $app_id ) );
		}
		self::record( $app_id, 'checked_in', 'qr' );
		return array(
			'application_id' => $app_id,
			'event_id'       => $ev,
			'checked_in'     => true,
		);
	}

	/**
	 * History for application.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function history( $app_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::checkins_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d ORDER BY created_at ASC", absint( $app_id ) ),
			ARRAY_A
		);
		return $rows ?: array();
	}
}
