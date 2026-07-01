<?php
/**
 * Event map CRUD.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Maps {

	/**
	 * Get or create map for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function get_for_event( $event_id ) {
		global $wpdb;
		$table = LNKup_Map_Tables::maps_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d LIMIT 1", absint( $event_id ) ),
			ARRAY_A
		);
		if ( $row ) {
			return self::format( $row );
		}
		return null;
	}

	/**
	 * Ensure map exists for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function ensure( $event_id ) {
		$map = self::get_for_event( $event_id );
		if ( $map ) {
			return $map;
		}
		return self::create( $event_id );
	}

	/**
	 * Create map record.
	 *
	 * @param int   $event_id Event ID.
	 * @param array $data     Optional data.
	 */
	public static function create( $event_id, $data = array() ) {
		global $wpdb;
		$table = LNKup_Map_Tables::maps_table();
		$now   = current_time( 'mysql' );
		$attachment = absint( $data['attachment_id'] ?? get_post_meta( $event_id, '_lnkup_venue_map_attachment_id', true ) );
		$row = array(
			'event_id'      => absint( $event_id ),
			'status'        => sanitize_key( $data['status'] ?? 'draft' ),
			'canvas_type'   => sanitize_key( $data['canvas_type'] ?? ( $attachment ? 'image' : 'schematic' ) ),
			'attachment_id' => $attachment,
			'settings'      => wp_json_encode( $data['settings'] ?? array() ),
			'visibility'    => sanitize_key( $data['visibility'] ?? 'organizer' ),
			'created_at'    => $now,
			'updated_at'    => $now,
		);
		$wpdb->insert( $table, $row );
		$row['id'] = (int) $wpdb->insert_id;
		if ( $attachment ) {
			update_post_meta( $event_id, '_lnkup_venue_map_attachment_id', $attachment );
		}
		return self::format( $row );
	}

	/**
	 * Update map.
	 *
	 * @param int   $map_id Map ID.
	 * @param array $data   Data.
	 */
	public static function update( $map_id, $data ) {
		global $wpdb;
		$table = LNKup_Map_Tables::maps_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $map_id ) ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Map not found.', 'lnkup-marketplace' ) );
		}
		$update = array( 'updated_at' => current_time( 'mysql' ) );
		if ( isset( $data['status'] ) ) {
			$update['status'] = sanitize_key( $data['status'] );
		}
		if ( isset( $data['canvas_type'] ) ) {
			$update['canvas_type'] = sanitize_key( $data['canvas_type'] );
		}
		if ( isset( $data['attachment_id'] ) ) {
			$update['attachment_id'] = absint( $data['attachment_id'] );
			update_post_meta( (int) $row['event_id'], '_lnkup_venue_map_attachment_id', $update['attachment_id'] );
		}
		if ( isset( $data['settings'] ) ) {
			$update['settings'] = wp_json_encode( $data['settings'] );
		}
		if ( isset( $data['visibility'] ) ) {
			$update['visibility'] = sanitize_key( $data['visibility'] );
		}
		$wpdb->update( $table, $update, array( 'id' => absint( $map_id ) ) );
		return self::format( array_merge( $row, $update ) );
	}

	/**
	 * Publish map.
	 *
	 * @param int $map_id Map ID.
	 */
	public static function publish( $map_id ) {
		return self::update( $map_id, array( 'status' => 'published' ) );
	}

	/**
	 * Duplicate map from source event to target.
	 *
	 * @param int $source_event_id Source event.
	 * @param int $target_event_id Target event.
	 */
	public static function duplicate_from_event( $source_event_id, $target_event_id ) {
		$source = self::get_for_event( $source_event_id );
		if ( ! $source ) {
			return new WP_Error( 'no_map', __( 'Source event has no map.', 'lnkup-marketplace' ) );
		}
		$target = self::ensure( $target_event_id );
		self::update(
			$target['id'],
			array(
				'canvas_type'   => $source['canvas_type'],
				'attachment_id' => $source['attachment_id'],
				'settings'      => $source['settings'],
				'status'        => 'draft',
			)
		);
		LNKup_Map_Elements::duplicate_map_elements( $source['id'], $target['id'], $target_event_id );
		return self::get_for_event( $target_event_id );
	}

	/**
	 * Format map row.
	 *
	 * @param array $row DB row.
	 */
	public static function format( $row ) {
		$settings = json_decode( $row['settings'] ?? '{}', true ) ?: array();
		$url      = ! empty( $row['attachment_id'] ) ? wp_get_attachment_url( (int) $row['attachment_id'] ) : '';
		return array(
			'id'            => (int) $row['id'],
			'event_id'      => (int) $row['event_id'],
			'status'        => $row['status'],
			'canvas_type'   => $row['canvas_type'],
			'attachment_id' => (int) $row['attachment_id'],
			'image_url'     => $url ?: '',
			'settings'      => $settings,
			'visibility'    => $row['visibility'],
			'updated_at'    => $row['updated_at'] ?? '',
		);
	}
}
