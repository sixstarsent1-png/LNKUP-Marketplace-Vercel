<?php
/**
 * Reusable venue map templates.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Map_Templates {

	public static function categories() {
		return array(
			'outdoor_festival' => __( 'Outdoor Festival', 'lnkup-marketplace' ),
			'rodeo'            => __( 'Rodeo', 'lnkup-marketplace' ),
			'block_party'      => __( 'Block Party', 'lnkup-marketplace' ),
			'indoor_market'    => __( 'Indoor Market', 'lnkup-marketplace' ),
			'campus'           => __( 'Campus Event', 'lnkup-marketplace' ),
			'food_truck_court' => __( 'Food Truck Court', 'lnkup-marketplace' ),
			'farm'             => __( 'Farm Venue', 'lnkup-marketplace' ),
			'parking_lot'      => __( 'Parking Lot Festival', 'lnkup-marketplace' ),
			'custom'           => __( 'Custom', 'lnkup-marketplace' ),
		);
	}

	public static function get_for_organizer( $organizer_id ) {
		global $wpdb;
		$table = LNKup_Map_Templates::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE organizer_user_id = %d OR organizer_user_id = 0 ORDER BY name ASC", absint( $organizer_id ) ),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format' ), $rows ?: array() );
	}

	public static function table() {
		return LNKup_Map_Tables::templates_table();
	}

	public static function save( $organizer_id, $data ) {
		global $wpdb;
		$table = LNKup_Map_Templates::table();
		$id    = absint( $data['id'] ?? 0 );
		$now   = current_time( 'mysql' );
		$row   = array(
			'organizer_user_id' => absint( $organizer_id ),
			'name'              => sanitize_text_field( $data['name'] ?? '' ),
			'category'          => sanitize_key( $data['category'] ?? 'custom' ),
			'elements_json'     => wp_json_encode( $data['elements'] ?? array() ),
			'settings'          => wp_json_encode( $data['settings'] ?? array() ),
			'updated_at'        => $now,
		);
		if ( $id ) {
			$wpdb->update( $table, $row, array( 'id' => $id, 'organizer_user_id' => absint( $organizer_id ) ) );
			$row['id'] = $id;
		} else {
			$row['created_at'] = $now;
			$wpdb->insert( $table, $row );
			$row['id'] = (int) $wpdb->insert_id;
		}
		return self::format( $row );
	}

	public static function apply_to_event( $template_id, $event_id, $organizer_id ) {
		global $wpdb;
		$table = LNKup_Map_Templates::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND (organizer_user_id = %d OR organizer_user_id = 0)", absint( $template_id ), absint( $organizer_id ) ),
			ARRAY_A
		);
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'lnkup-marketplace' ) );
		}
		$map = LNKup_Maps::ensure( $event_id );
		$settings = json_decode( $row['settings'] ?? '{}', true ) ?: array();
		LNKup_Maps::update( $map['id'], array( 'settings' => $settings, 'status' => 'draft' ) );
		global $wpdb;
		$el_table = LNKup_Map_Tables::elements_table();
		$wpdb->delete( $el_table, array( 'map_id' => $map['id'] ) );
		$elements = json_decode( $row['elements_json'] ?? '[]', true ) ?: array();
		foreach ( $elements as $el ) {
			unset( $el['id'] );
			$el['map_id'] = $map['id'];
			LNKup_Map_Elements::save( $map['id'], $event_id, $el );
		}
		return LNKup_Maps::get_for_event( $event_id );
	}

	public static function save_from_event( $event_id, $organizer_id, $name, $category = 'custom' ) {
		$map = LNKup_Maps::get_for_event( $event_id );
		if ( ! $map ) {
			return new WP_Error( 'no_map', __( 'Event has no map.', 'lnkup-marketplace' ) );
		}
		$elements = LNKup_Map_Elements::get_for_map( $map['id'], 'all' );
		foreach ( $elements as &$el ) {
			unset( $el['id'], $el['booth'], $el['live_status'] );
		}
		return self::save(
			$organizer_id,
			array(
				'name'     => $name,
				'category' => $category,
				'elements' => $elements,
				'settings' => $map['settings'],
			)
		);
	}

	public static function format( $row ) {
		return array(
			'id'         => (int) $row['id'],
			'name'       => $row['name'],
			'category'   => $row['category'],
			'category_label' => self::categories()[ $row['category'] ] ?? $row['category'],
			'elements'   => json_decode( $row['elements_json'] ?? '[]', true ) ?: array(),
			'settings'   => json_decode( $row['settings'] ?? '{}', true ) ?: array(),
			'created_at' => $row['created_at'] ?? '',
		);
	}
}
