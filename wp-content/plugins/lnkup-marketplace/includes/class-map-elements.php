<?php
/**
 * Map element CRUD.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Map_Elements {

	public static function element_types() {
		return array(
			'booth'        => __( 'Booth', 'lnkup-marketplace' ),
			'stage'        => __( 'Stage', 'lnkup-marketplace' ),
			'food_court'   => __( 'Food Court', 'lnkup-marketplace' ),
			'retail'       => __( 'Retail Area', 'lnkup-marketplace' ),
			'sponsor'      => __( 'Sponsor Area', 'lnkup-marketplace' ),
			'restroom'     => __( 'Restroom', 'lnkup-marketplace' ),
			'first_aid'    => __( 'First Aid', 'lnkup-marketplace' ),
			'water'        => __( 'Water Station', 'lnkup-marketplace' ),
			'power'        => __( 'Power Drop', 'lnkup-marketplace' ),
			'exit'         => __( 'Emergency Exit', 'lnkup-marketplace' ),
			'check_in'     => __( 'Vendor Check-In', 'lnkup-marketplace' ),
			'load_in'      => __( 'Load-In Entrance', 'lnkup-marketplace' ),
			'parking'      => __( 'Parking', 'lnkup-marketplace' ),
			'vip'          => __( 'VIP', 'lnkup-marketplace' ),
			'info'         => __( 'Info Booth', 'lnkup-marketplace' ),
			'security'     => __( 'Security', 'lnkup-marketplace' ),
			'medical'      => __( 'Medical', 'lnkup-marketplace' ),
			'trash'        => __( 'Trash', 'lnkup-marketplace' ),
			'generator'    => __( 'Generator', 'lnkup-marketplace' ),
			'custom'       => __( 'Custom', 'lnkup-marketplace' ),
		);
	}

	public static function layers() {
		return array( 'booths', 'vendors', 'parking', 'power', 'water', 'restrooms', 'stages', 'safety', 'staff', 'support', 'incidents', 'routes', 'public' );
	}

	/**
	 * List elements for map.
	 *
	 * @param int    $map_id     Map ID.
	 * @param string $visibility Filter visibility (public|staff|organizer|all).
	 */
	public static function get_for_map( $map_id, $visibility = 'all' ) {
		global $wpdb;
		$table = LNKup_Map_Tables::elements_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE map_id = %d ORDER BY sort_order ASC, id ASC", absint( $map_id ) ),
			ARRAY_A
		);
		$items = array();
		foreach ( (array) $rows as $row ) {
			$el = self::format( $row );
			if ( 'all' !== $visibility && ! self::visible_for( $el['visibility'], $visibility ) ) {
				continue;
			}
			$items[] = $el;
		}
		return $items;
	}

	/**
	 * Check visibility scope.
	 */
	public static function visible_for( $element_visibility, $scope ) {
		if ( 'organizer' === $scope || 'all' === $scope ) {
			return true;
		}
		if ( 'staff' === $scope ) {
			return in_array( $element_visibility, array( 'public', 'staff', 'vendor' ), true );
		}
		if ( 'vendor' === $scope || 'public' === $scope ) {
			return 'public' === $element_visibility;
		}
		return false;
	}

	/**
	 * Save element.
	 *
	 * @param int   $map_id   Map ID.
	 * @param int   $event_id Event ID.
	 * @param array $data     Element data.
	 */
	public static function save( $map_id, $event_id, $data ) {
		global $wpdb;
		$table = LNKup_Map_Tables::elements_table();
		$id    = absint( $data['id'] ?? 0 );
		$now   = current_time( 'mysql' );
		$row   = array(
			'map_id'       => absint( $map_id ),
			'event_id'     => absint( $event_id ),
			'element_type' => sanitize_key( $data['element_type'] ?? 'custom' ),
			'label'        => sanitize_text_field( $data['label'] ?? '' ),
			'pos_x'        => (float) ( $data['pos_x'] ?? 0 ),
			'pos_y'        => (float) ( $data['pos_y'] ?? 0 ),
			'width'        => (float) ( $data['width'] ?? 8 ),
			'height'       => (float) ( $data['height'] ?? 8 ),
			'rotation'     => (float) ( $data['rotation'] ?? 0 ),
			'layer'        => sanitize_key( $data['layer'] ?? 'booths' ),
			'visibility'   => sanitize_key( $data['visibility'] ?? 'public' ),
			'linked_type'  => sanitize_key( $data['linked_type'] ?? '' ),
			'linked_id'    => absint( $data['linked_id'] ?? 0 ),
			'style'        => wp_json_encode( $data['style'] ?? array() ),
			'meta'         => wp_json_encode( $data['meta'] ?? array() ),
			'sort_order'   => (int) ( $data['sort_order'] ?? 0 ),
			'updated_at'   => $now,
		);
		if ( $id ) {
			$wpdb->update( $table, $row, array( 'id' => $id ) );
			$row['id'] = $id;
		} else {
			$row['created_at'] = $now;
			$wpdb->insert( $table, $row );
			$row['id'] = (int) $wpdb->insert_id;
		}
		if ( 'booth' === $row['element_type'] && $row['linked_id'] ) {
			self::sync_booth_position( $row['linked_id'], $row['pos_x'], $row['pos_y'] );
		}
		return self::format( $row );
	}

	/**
	 * Batch save elements.
	 */
	public static function save_batch( $map_id, $event_id, $elements ) {
		$saved = array();
		foreach ( (array) $elements as $el ) {
			$saved[] = self::save( $map_id, $event_id, $el );
		}
		delete_transient( 'lnkup_map_live_' . $event_id );
		return $saved;
	}

	/**
	 * Delete element.
	 *
	 * @param int $element_id Element ID.
	 */
	public static function delete( $element_id ) {
		global $wpdb;
		$table = LNKup_Map_Tables::elements_table();
		$wpdb->delete( $table, array( 'id' => absint( $element_id ) ) );
		return true;
	}

	/**
	 * Sync booth table position from element.
	 */
	public static function sync_booth_position( $booth_id, $pos_x, $pos_y ) {
		if ( ! class_exists( 'LNKup_Booths' ) ) {
			return;
		}
		global $wpdb;
		$table = LNKup_Operations_Tables::booths_table();
		$wpdb->update(
			$table,
			array(
				'pos_x'      => (float) $pos_x,
				'pos_y'      => (float) $pos_y,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $booth_id ) )
		);
	}

	/**
	 * Link element to booth record.
	 */
	public static function link_booth_element( $map_id, $event_id, $booth_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::booths_table();
		$booth = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $booth_id ) ), ARRAY_A );
		if ( ! $booth ) {
			return new WP_Error( 'not_found', __( 'Booth not found.', 'lnkup-marketplace' ) );
		}
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . LNKup_Map_Tables::elements_table() . " WHERE map_id = %d AND linked_type = 'booth' AND linked_id = %d LIMIT 1",
				absint( $map_id ),
				absint( $booth_id )
			),
			ARRAY_A
		);
		$data = array(
			'id'           => $existing ? (int) $existing['id'] : 0,
			'element_type' => 'booth',
			'label'        => $booth['booth_number'],
			'pos_x'        => (float) $booth['pos_x'],
			'pos_y'        => (float) $booth['pos_y'],
			'layer'        => 'booths',
			'visibility'   => 'public',
			'linked_type'  => 'booth',
			'linked_id'    => (int) $booth_id,
		);
		return self::save( $map_id, $event_id, $data );
	}

	/**
	 * Duplicate elements between maps.
	 */
	public static function duplicate_map_elements( $source_map_id, $target_map_id, $target_event_id ) {
		global $wpdb;
		$table = LNKup_Map_Tables::elements_table();
		$wpdb->delete( $table, array( 'map_id' => absint( $target_map_id ) ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE map_id = %d", absint( $source_map_id ) ),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			unset( $row['id'] );
			$row['map_id']   = absint( $target_map_id );
			$row['event_id'] = absint( $target_event_id );
			$row['linked_id'] = 0;
			$row['linked_type'] = '';
			$row['created_at'] = current_time( 'mysql' );
			$row['updated_at'] = current_time( 'mysql' );
			$wpdb->insert( $table, $row );
		}
	}

	/**
	 * Search elements in map.
	 */
	public static function search( $map_id, $query, $visibility = 'organizer' ) {
		$query = strtolower( trim( (string) $query ) );
		if ( '' === $query ) {
			return array();
		}
		$matches = array();
		foreach ( self::get_for_map( $map_id, $visibility ) as $el ) {
			$hay = strtolower( $el['label'] . ' ' . $el['element_type'] . ' ' . ( $el['meta']['vendor_name'] ?? '' ) );
			if ( false !== strpos( $hay, $query ) ) {
				$matches[] = $el;
			}
		}
		return $matches;
	}

	/**
	 * Format element row with live status for ops map.
	 */
	public static function format( $row, $enrich = true ) {
		$style = json_decode( $row['style'] ?? '{}', true ) ?: array();
		$meta  = json_decode( $row['meta'] ?? '{}', true ) ?: array();
		$el = array(
			'id'           => (int) $row['id'],
			'map_id'       => (int) $row['map_id'],
			'event_id'     => (int) $row['event_id'],
			'element_type' => $row['element_type'],
			'label'        => $row['label'],
			'pos_x'        => (float) $row['pos_x'],
			'pos_y'        => (float) $row['pos_y'],
			'width'        => (float) $row['width'],
			'height'       => (float) $row['height'],
			'rotation'     => (float) $row['rotation'],
			'layer'        => $row['layer'],
			'visibility'   => $row['visibility'],
			'linked_type'  => $row['linked_type'],
			'linked_id'    => (int) $row['linked_id'],
			'style'        => $style,
			'meta'         => $meta,
			'sort_order'   => (int) $row['sort_order'],
		);
		if ( $enrich && 'booth' === $row['linked_type'] && $row['linked_id'] && class_exists( 'LNKup_Booths' ) ) {
			global $wpdb;
			$booth_row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . LNKup_Operations_Tables::booths_table() . ' WHERE id = %d', (int) $row['linked_id'] ),
				ARRAY_A
			);
			if ( $booth_row ) {
				$el['booth'] = LNKup_Booths::format( $booth_row );
				if ( ! empty( $el['booth']['application_id'] ) && class_exists( 'LNKup_Event_Operations' ) ) {
					$el['live_status'] = LNKup_Event_Operations::resolve_ops_status( (int) $el['booth']['application_id'] );
				}
			}
		}
		return $el;
	}
}
