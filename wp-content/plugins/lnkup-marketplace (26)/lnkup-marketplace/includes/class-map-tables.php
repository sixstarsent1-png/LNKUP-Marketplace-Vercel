<?php
/**
 * Map system custom database tables.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Map_Tables {

	public static function geocode_cache_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_geocode_cache';
	}

	public static function maps_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_maps';
	}

	public static function elements_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_map_elements';
	}

	public static function parking_zones_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_parking_zones';
	}

	public static function routes_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_map_routes';
	}

	public static function templates_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_map_templates';
	}

	public static function share_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_map_shares';
	}

	/**
	 * Create all map tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$geocode = self::geocode_cache_table();
		dbDelta(
			"CREATE TABLE {$geocode} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				address_hash varchar(64) NOT NULL DEFAULT '',
				address_text text NOT NULL,
				lat decimal(10,7) DEFAULT NULL,
				lng decimal(10,7) DEFAULT NULL,
				provider varchar(40) NOT NULL DEFAULT 'nominatim',
				status varchar(20) NOT NULL DEFAULT 'ok',
				corrected_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY address_hash (address_hash),
				KEY status (status)
			) {$charset};"
		);

		$maps = self::maps_table();
		dbDelta(
			"CREATE TABLE {$maps} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'draft',
				canvas_type varchar(20) NOT NULL DEFAULT 'image',
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				settings longtext,
				visibility varchar(20) NOT NULL DEFAULT 'organizer',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY event_id (event_id),
				KEY status (status)
			) {$charset};"
		);

		$elements = self::elements_table();
		dbDelta(
			"CREATE TABLE {$elements} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				map_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				element_type varchar(40) NOT NULL DEFAULT 'custom',
				label varchar(120) NOT NULL DEFAULT '',
				pos_x decimal(6,2) NOT NULL DEFAULT 0,
				pos_y decimal(6,2) NOT NULL DEFAULT 0,
				width decimal(6,2) NOT NULL DEFAULT 8,
				height decimal(6,2) NOT NULL DEFAULT 8,
				rotation decimal(6,2) NOT NULL DEFAULT 0,
				layer varchar(40) NOT NULL DEFAULT 'booths',
				visibility varchar(20) NOT NULL DEFAULT 'public',
				linked_type varchar(40) NOT NULL DEFAULT '',
				linked_id bigint(20) unsigned NOT NULL DEFAULT 0,
				style longtext,
				meta longtext,
				sort_order smallint NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY map_id (map_id),
				KEY event_id (event_id),
				KEY linked (linked_type, linked_id),
				KEY layer (layer)
			) {$charset};"
		);

		$parking = self::parking_zones_table();
		dbDelta(
			"CREATE TABLE {$parking} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				map_id bigint(20) unsigned NOT NULL DEFAULT 0,
				lot varchar(80) NOT NULL DEFAULT '',
				zone_type varchar(40) NOT NULL DEFAULT 'vendor',
				pos_x decimal(6,2) NOT NULL DEFAULT 0,
				pos_y decimal(6,2) NOT NULL DEFAULT 0,
				width decimal(6,2) NOT NULL DEFAULT 20,
				height decimal(6,2) NOT NULL DEFAULT 20,
				capacity smallint NOT NULL DEFAULT 0,
				instructions text,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY event_id (event_id),
				KEY map_id (map_id)
			) {$charset};"
		);

		$routes = self::routes_table();
		dbDelta(
			"CREATE TABLE {$routes} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				map_id bigint(20) unsigned NOT NULL DEFAULT 0,
				route_type varchar(40) NOT NULL DEFAULT 'load_in',
				label varchar(120) NOT NULL DEFAULT '',
				path_json longtext,
				direction varchar(20) NOT NULL DEFAULT 'one_way',
				restricted tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY event_id (event_id),
				KEY map_id (map_id)
			) {$charset};"
		);

		$templates = self::templates_table();
		dbDelta(
			"CREATE TABLE {$templates} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(120) NOT NULL DEFAULT '',
				category varchar(40) NOT NULL DEFAULT 'custom',
				elements_json longtext,
				settings longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY organizer_user_id (organizer_user_id)
			) {$charset};"
		);

		$shares = self::share_table();
		dbDelta(
			"CREATE TABLE {$shares} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				map_id bigint(20) unsigned NOT NULL DEFAULT 0,
				token varchar(64) NOT NULL DEFAULT '',
				scope varchar(20) NOT NULL DEFAULT 'public',
				expires_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY token (token),
				KEY event_id (event_id)
			) {$charset};"
		);
	}
}
