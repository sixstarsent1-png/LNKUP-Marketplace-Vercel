<?php
/**
 * Booth assignment management.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Booths {

	public static function booth_types() {
		return array(
			'standard'   => __( 'Standard', 'lnkup-marketplace' ),
			'premium'    => __( 'Premium', 'lnkup-marketplace' ),
			'corner'     => __( 'Corner', 'lnkup-marketplace' ),
			'food_truck' => __( 'Food Truck Space', 'lnkup-marketplace' ),
			'power'      => __( 'Power Booth', 'lnkup-marketplace' ),
			'sponsor'    => __( 'Sponsor', 'lnkup-marketplace' ),
			'reserved'   => __( 'Reserved', 'lnkup-marketplace' ),
			'accessible' => __( 'Accessible', 'lnkup-marketplace' ),
		);
	}

	/**
	 * List booths for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function get_for_event( $event_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::booths_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY booth_number ASC", absint( $event_id ) ),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format' ), $rows ?: array() );
	}

	/**
	 * Get booth assigned to application.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function get_for_application( $app_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::booths_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d LIMIT 1", absint( $app_id ) ),
			ARRAY_A
		);
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Create or update booth.
	 *
	 * @param int   $event_id Event ID.
	 * @param array $data     Booth data.
	 */
	public static function save( $event_id, $data ) {
		global $wpdb;
		$table    = LNKup_Operations_Tables::booths_table();
		$id       = absint( $data['id'] ?? 0 );
		$event_id = absint( $event_id );
		if ( $id && (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$table} WHERE id = %d", $id ) ) !== $event_id ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return new WP_Error( 'not_found', __( 'Booth not found for this event.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$app_id = absint( $data['application_id'] ?? 0 );
		if ( $app_id && ! LNKup_Capabilities::application_in_event( $app_id, $event_id ) ) {
			return new WP_Error( 'wrong_event', __( 'That vendor is not booked for this event.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		// Assignment is never written here: editing a booth keeps its vendor, and assigning
		// goes through assign() so the lock, booking check and one-booth-per-vendor rule apply.
		$row = array(
			'event_id'     => absint( $event_id ),
			'booth_number' => sanitize_text_field( $data['booth_number'] ?? '' ),
			'booth_type'   => sanitize_key( $data['booth_type'] ?? 'standard' ),
			'booth_size'   => sanitize_text_field( $data['booth_size'] ?? '' ),
			'pos_x'        => (float) ( $data['pos_x'] ?? 0 ),
			'pos_y'        => (float) ( $data['pos_y'] ?? 0 ),
			'has_power'    => ! empty( $data['has_power'] ) ? 1 : 0,
			'has_water'    => ! empty( $data['has_water'] ) ? 1 : 0,
			'notes'        => sanitize_textarea_field( $data['notes'] ?? '' ),
			'updated_at'   => current_time( 'mysql' ),
		);
		if ( $id ) {
			$wpdb->update( $table, $row, array( 'id' => $id, 'event_id' => $event_id ), null, array( '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$booth_id = $id;
		} else {
			$row['application_id'] = 0;
			$row['created_at']     = current_time( 'mysql' );
			$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$booth_id = (int) $wpdb->insert_id;
			if ( ! $booth_id ) {
				return new WP_Error( 'booth_exists', __( 'A booth with that number already exists for this event.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
			}
		}
		if ( $app_id ) {
			$assigned = self::assign( $booth_id, $app_id, $event_id );
			if ( is_wp_error( $assigned ) ) {
				return $assigned;
			}
		}
		return $booth_id;
	}

	/**
	 * Assign booth to application.
	 *
	 * @param int $booth_id Booth row ID.
	 * @param int $app_id   Application ID.
	 */
	public static function assign( $booth_id, $app_id, $event_id = 0 ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::booths_table();
		$lock  = 'lnkup_booth_' . absint( $booth_id );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'busy', __( 'Another change to this booth is in progress. Try again.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		try {
			$booth = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $booth_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $booth || ( $event_id && (int) $booth['event_id'] !== absint( $event_id ) ) ) {
				return new WP_Error( 'not_found', __( 'Booth not found for this event.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			if ( ! LNKup_Capabilities::application_in_event( $app_id, (int) $booth['event_id'] ) ) {
				return new WP_Error( 'wrong_event', __( 'That vendor is not booked for this event.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
			}
			if ( $booth['application_id'] && (int) $booth['application_id'] !== (int) $app_id ) {
				return new WP_Error( 'conflict', __( 'Booth already assigned.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
			}
			$app_status = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
			if ( ! in_array( $app_status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) ) {
				return new WP_Error( 'not_booked', __( 'Approve this vendor before assigning a booth.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
			}
			$previous = $wpdb->get_col( $wpdb->prepare( "SELECT booth_number FROM {$table} WHERE event_id = %d AND application_id = %d AND id <> %d", (int) $booth['event_id'], absint( $app_id ), absint( $booth_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $previous ) {
				// One booth per vendor per event: moving a vendor frees the old booth.
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET application_id = 0, updated_at = %s WHERE event_id = %d AND application_id = %d AND id <> %d", current_time( 'mysql' ), (int) $booth['event_id'], absint( $app_id ), absint( $booth_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$wpdb->update(
				$table,
				array( 'application_id' => absint( $app_id ), 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => absint( $booth_id ) ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$changed = (string) get_post_meta( $app_id, '_lnkup_booth_placeholder', true ) !== (string) $booth['booth_number'];
		self::sync_packet( $app_id, $booth['booth_number'] );
		update_post_meta( $app_id, '_lnkup_booth_placeholder', $booth['booth_number'] );
		if ( $changed && in_array( $app_status, array( 'paid', 'completed' ), true ) ) {
			LNKup_Notifications::send_pass_details_updated( $app_id );
		}
		LNKup_Vendor_Readiness::refresh( $app_id );
		LNKup_Operations_Log::log(
			(int) $booth['event_id'],
			'booth_assigned',
			array(
				'application_id' => $app_id,
				'payload'        => array( 'booth_number' => $booth['booth_number'] ),
			)
		);
		do_action( 'lnkup_booth_assigned', $booth_id, $app_id, (int) $booth['event_id'] );
		delete_transient( 'lnkup_map_live_' . (int) $booth['event_id'] );
		return true;
	}

	/**
	 * Sync booth to packet meta.
	 *
	 * @param int    $app_id Application ID.
	 * @param string $booth  Booth number.
	 */
	public static function sync_packet( $app_id, $booth ) {
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
			update_post_meta( $packets[0], '_lnkup_booth_assignment', sanitize_text_field( $booth ) );
		}
	}

	/**
	 * Format booth row.
	 *
	 * @param array $row DB row.
	 */
	public static function format( $row ) {
		$app_id = (int) ( $row['application_id'] ?? 0 );
		$name   = '';
		if ( $app_id ) {
			$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
			$name = $profile_id ? ( get_post_meta( $profile_id, '_lnkup_business_name', true ) ?: lnkup_plain_title( $profile_id ) ) : '';
		}
		return array(
			'id'               => (int) $row['id'],
			'event_id'         => (int) $row['event_id'],
			'booth_number'     => $row['booth_number'],
			'booth_type'       => $row['booth_type'],
			'booth_type_label' => self::booth_types()[ $row['booth_type'] ] ?? $row['booth_type'],
			'booth_size'       => $row['booth_size'],
			'pos_x'            => (float) $row['pos_x'],
			'pos_y'            => (float) $row['pos_y'],
			'has_power'        => (bool) $row['has_power'],
			'has_water'        => (bool) $row['has_water'],
			'application_id'   => $app_id,
			'vendor_name'      => $name,
			'notes'            => $row['notes'] ?? '',
		);
	}
}
