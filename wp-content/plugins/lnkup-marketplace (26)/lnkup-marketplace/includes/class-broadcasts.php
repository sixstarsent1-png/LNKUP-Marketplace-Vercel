<?php
/**
 * Event broadcast messaging.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Broadcasts {

	public static function templates() {
		return array(
			'weather'         => __( 'Weather Update', 'lnkup-marketplace' ),
			'parking'         => __( 'Parking Notice', 'lnkup-marketplace' ),
			'emergency'       => __( 'Emergency Alert', 'lnkup-marketplace' ),
			'gate_change'     => __( 'Gate Change', 'lnkup-marketplace' ),
			'schedule_update' => __( 'Schedule Update', 'lnkup-marketplace' ),
			'power_issue'     => __( 'Power Issue', 'lnkup-marketplace' ),
			'reminder'        => __( 'Reminder', 'lnkup-marketplace' ),
			'booth_change'    => __( 'Booth Change', 'lnkup-marketplace' ),
			'custom'          => __( 'Custom Message', 'lnkup-marketplace' ),
		);
	}

	public static function audience_presets() {
		return array(
			'all'              => __( 'Everyone', 'lnkup-marketplace' ),
			'food_vendors'     => __( 'Food Vendors', 'lnkup-marketplace' ),
			'food_trucks'      => __( 'Food Trucks', 'lnkup-marketplace' ),
			'retail'           => __( 'Retail', 'lnkup-marketplace' ),
			'missing_payment'  => __( 'Missing Payment', 'lnkup-marketplace' ),
			'missing_documents'=> __( 'Missing Documents', 'lnkup-marketplace' ),
			'checked_in'       => __( 'Checked-In Vendors', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Send broadcast to event audience.
	 *
	 * @param int   $event_id Event ID.
	 * @param int   $user_id  Sender user ID.
	 * @param array $data     Broadcast data.
	 */
	public static function send( $event_id, $user_id, $data ) {
		global $wpdb;
		$filter = sanitize_key( $data['audience'] ?? 'all' );
		$message = sanitize_textarea_field( $data['message'] ?? '' );
		$template = sanitize_key( $data['template'] ?? 'custom' );
		$wpdb->insert(
			LNKup_Operations_Tables::broadcasts_table(),
			array(
				'event_id'        => absint( $event_id ),
				'audience_filter' => wp_json_encode( array( 'preset' => $filter ) ),
				'template'        => $template,
				'message'         => $message,
				'sent_by'         => absint( $user_id ),
				'sent_at'         => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		$recipients = self::resolve_audience( $event_id, $filter );
		foreach ( $recipients as $vendor_id ) {
			$notices = get_user_meta( $vendor_id, 'lnkup_ops_broadcasts', true );
			if ( ! is_array( $notices ) ) {
				$notices = array();
			}
			$notices[] = array(
				'broadcast_id' => $id,
				'event_id'     => $event_id,
				'message'      => $message,
				'template'     => $template,
				'sent_at'      => current_time( 'mysql' ),
				'read'         => false,
			);
			update_user_meta( $vendor_id, 'lnkup_ops_broadcasts', array_slice( $notices, -50 ) );
		}
		LNKup_Operations_Log::log( $event_id, 'broadcast_sent', array( 'payload' => array( 'broadcast_id' => $id, 'audience' => $filter ) ) );
		if ( class_exists( 'LNKup_Notifications' ) ) {
			LNKup_Notifications::send_ops_broadcast( $event_id, $recipients, $message );
		}
		return array( 'id' => $id, 'recipient_count' => count( $recipients ) );
	}

	/**
	 * Resolve vendor user IDs for audience preset.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $preset   Audience preset slug.
	 */
	public static function resolve_audience( $event_id, $preset ) {
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => absint( $event_id ),
			)
		);
		$ids = array();
		foreach ( $apps as $app ) {
			$vendor_id = (int) get_post_meta( $app->ID, '_lnkup_vendor_user_id', true );
			$status    = get_post_meta( $app->ID, '_lnkup_application_status', true );
			$include   = false;
			switch ( $preset ) {
				case 'all':
					$include = in_array( $status, array( 'approved', 'payment_required', 'paid', 'completed' ), true );
					break;
				case 'missing_payment':
					$include = in_array( $status, array( 'approved', 'payment_required' ), true );
					break;
				case 'missing_documents':
					$include = 'paid' === $status && LNKup_Vendor_Readiness::STATUS_NEEDS_DOCUMENTS === LNKup_Vendor_Readiness::calculate( $app->ID );
					break;
				case 'checked_in':
					$include = (bool) get_post_meta( $app->ID, '_lnkup_checked_in_at', true );
					break;
				default:
					$include = 'paid' === $status || 'completed' === $status;
			}
			if ( $include && $vendor_id ) {
				$ids[] = $vendor_id;
			}
		}
		return array_unique( $ids );
	}

	/**
	 * List broadcasts for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function get_for_event( $event_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::broadcasts_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY sent_at DESC LIMIT 50", absint( $event_id ) ),
			ARRAY_A
		);
		$items = array();
		foreach ( $rows as $row ) {
			$sender = get_userdata( (int) $row['sent_by'] );
			$filter = json_decode( $row['audience_filter'] ?: '{}', true );
			$items[] = array(
				'id'       => (int) $row['id'],
				'template' => $row['template'],
				'message'  => $row['message'],
				'audience' => $filter['preset'] ?? 'all',
				'sent_by'  => $sender ? $sender->display_name : '',
				'sent_at'  => $row['sent_at'],
			);
		}
		return $items;
	}

	/**
	 * Unread broadcasts for vendor on event.
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @param int $event_id  Event ID.
	 */
	public static function unread_for_vendor( $vendor_id, $event_id ) {
		$notices = get_user_meta( $vendor_id, 'lnkup_ops_broadcasts', true );
		if ( ! is_array( $notices ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$notices,
				function ( $n ) use ( $event_id ) {
					return empty( $n['read'] ) && (int) ( $n['event_id'] ?? 0 ) === (int) $event_id;
				}
			)
		);
	}
}
