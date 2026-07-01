<?php
/**
 * Weather intelligence snapshot tables.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Weather_Tables {

	/**
	 * Create weather tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::snapshots_table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				provider varchar(32) NOT NULL DEFAULT 'openweather',
				forecast_type varchar(32) NOT NULL DEFAULT 'unavailable',
				forecast_date date DEFAULT NULL,
				location_label varchar(255) NOT NULL DEFAULT '',
				lat decimal(10,6) DEFAULT NULL,
				lng decimal(10,6) DEFAULT NULL,
				temp_high decimal(6,2) DEFAULT NULL,
				temp_low decimal(6,2) DEFAULT NULL,
				temperature decimal(6,2) DEFAULT NULL,
				feels_like decimal(6,2) DEFAULT NULL,
				humidity smallint unsigned DEFAULT NULL,
				precipitation_chance smallint unsigned DEFAULT NULL,
				precipitation_amount decimal(8,2) DEFAULT NULL,
				wind_speed decimal(6,2) DEFAULT NULL,
				wind_gust decimal(6,2) DEFAULT NULL,
				condition_code varchar(32) NOT NULL DEFAULT '',
				condition_summary varchar(255) NOT NULL DEFAULT '',
				alerts_count smallint unsigned NOT NULL DEFAULT 0,
				raw_payload longtext,
				overall_risk varchar(16) NOT NULL DEFAULT 'unavailable',
				rain_risk varchar(16) NOT NULL DEFAULT 'unavailable',
				wind_risk varchar(16) NOT NULL DEFAULT 'unavailable',
				heat_risk varchar(16) NOT NULL DEFAULT 'unavailable',
				storm_risk varchar(16) NOT NULL DEFAULT 'unavailable',
				setup_risk varchar(16) NOT NULL DEFAULT 'unavailable',
				confidence varchar(16) NOT NULL DEFAULT 'unavailable',
				recommendations longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY event_id (event_id),
				KEY forecast_date (forecast_date),
				KEY created_at (created_at)
			) {$charset};"
		);
	}

	/**
	 * Snapshots table name.
	 */
	public static function snapshots_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_weather_snapshots';
	}

	/**
	 * Insert snapshot row.
	 *
	 * @param array $row Row data.
	 */
	public static function insert_snapshot( array $row ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row['created_at'] = $row['created_at'] ?? $now;
		$row['updated_at'] = $row['updated_at'] ?? $now;
		if ( isset( $row['recommendations'] ) && is_array( $row['recommendations'] ) ) {
			$row['recommendations'] = wp_json_encode( $row['recommendations'] );
		}
		if ( isset( $row['raw_payload'] ) && is_array( $row['raw_payload'] ) ) {
			$row['raw_payload'] = wp_json_encode( $row['raw_payload'] );
		}
		$wpdb->insert( self::snapshots_table(), $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Latest snapshot for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function get_latest( $event_id ) {
		global $wpdb;
		$table = self::snapshots_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id = %d ORDER BY id DESC LIMIT 1",
				absint( $event_id )
			),
			ARRAY_A
		);
		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * Snapshot history.
	 *
	 * @param int $event_id Event ID.
	 * @param int $limit    Limit.
	 */
	public static function get_history( $event_id, $limit = 30 ) {
		global $wpdb;
		$table = self::snapshots_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id = %d ORDER BY id DESC LIMIT %d",
				absint( $event_id ),
				absint( $limit )
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'decode_row' ), $rows ?: array() );
	}

	/**
	 * Decode JSON columns.
	 *
	 * @param array $row DB row.
	 */
	private static function decode_row( array $row ) {
		if ( ! empty( $row['recommendations'] ) ) {
			$decoded = json_decode( $row['recommendations'], true );
			$row['recommendations'] = is_array( $decoded ) ? $decoded : array();
		} else {
			$row['recommendations'] = array();
		}
		if ( ! empty( $row['raw_payload'] ) ) {
			$decoded = json_decode( $row['raw_payload'], true );
			$row['raw_payload'] = is_array( $decoded ) ? $decoded : array();
		} else {
			$row['raw_payload'] = array();
		}
		return $row;
	}
}
