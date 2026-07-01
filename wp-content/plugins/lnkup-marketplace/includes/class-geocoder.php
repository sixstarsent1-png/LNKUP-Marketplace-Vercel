<?php
/**
 * Geocoding via Nominatim (OpenStreetMap) with DB cache.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Geocoder {

	const STATUS_OK      = 'ok';
	const STATUS_FAILED  = 'failed';
	const STATUS_MANUAL  = 'manual';

	/**
	 * Geocode an address string and return lat/lng.
	 *
	 * @param string $address Full address.
	 */
	public static function geocode( $address ) {
		$address = trim( (string) $address );
		if ( '' === $address ) {
			return null;
		}

		$hash = md5( strtolower( $address ) );
		$cached = self::get_cache( $hash );
		if ( $cached && self::STATUS_OK === $cached['status'] ) {
			return array(
				'lat' => (float) $cached['lat'],
				'lng' => (float) $cached['lng'],
			);
		}

		$transient_key = 'lnkup_geo_' . $hash;
		$transient     = get_transient( $transient_key );
		if ( is_array( $transient ) && isset( $transient['lat'], $transient['lng'] ) ) {
			return $transient;
		}

		$url = add_query_arg(
			array(
				'q'              => $address,
				'format'         => 'json',
				'limit'          => 1,
				'addressdetails' => 0,
			),
			'https://nominatim.openstreetmap.org/search'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'User-Agent' => 'LNKup-Marketplace/8.0 (WordPress)',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::save_cache( $hash, $address, null, null, self::STATUS_FAILED );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body[0]['lat'] ) || empty( $body[0]['lon'] ) ) {
			self::save_cache( $hash, $address, null, null, self::STATUS_FAILED );
			return null;
		}

		$result = array(
			'lat' => (float) $body[0]['lat'],
			'lng' => (float) $body[0]['lon'],
		);

		set_transient( $transient_key, $result, WEEK_IN_SECONDS );
		self::save_cache( $hash, $address, $result['lat'], $result['lng'], self::STATUS_OK );
		return $result;
	}

	/**
	 * Get cached geocode row.
	 *
	 * @param string $hash Address hash.
	 */
	public static function get_cache( $hash ) {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Map_Tables' ) ) {
			return null;
		}
		$table = LNKup_Map_Tables::geocode_cache_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE address_hash = %s LIMIT 1", $hash ),
			ARRAY_A
		);
	}

	/**
	 * Persist geocode result.
	 */
	public static function save_cache( $hash, $address, $lat, $lng, $status = self::STATUS_OK ) {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Map_Tables' ) ) {
			return;
		}
		$table = LNKup_Map_Tables::geocode_cache_table();
		$now   = current_time( 'mysql' );
		$existing = self::get_cache( $hash );
		$row = array(
			'address_hash' => $hash,
			'address_text' => $address,
			'lat'          => null !== $lat ? $lat : null,
			'lng'          => null !== $lng ? $lng : null,
			'provider'     => 'nominatim',
			'status'       => $status,
			'updated_at'   => $now,
		);
		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => $existing['id'] ) );
		} else {
			$row['created_at'] = $now;
			$wpdb->insert( $table, $row );
		}
	}

	/**
	 * Build full address string from event meta.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function event_address_string( $event_id ) {
		$parts = array_filter(
			array(
				get_post_meta( $event_id, '_lnkup_address_line1', true ) ?: get_post_meta( $event_id, '_lnkup_venue_address', true ),
				get_post_meta( $event_id, '_lnkup_address_line2', true ),
				get_post_meta( $event_id, '_lnkup_city', true ),
				get_post_meta( $event_id, '_lnkup_state', true ),
				get_post_meta( $event_id, '_lnkup_zip', true ) ?: '',
				get_post_meta( $event_id, '_lnkup_country', true ) ?: 'USA',
			)
		);
		return trim( implode( ', ', $parts ) );
	}

	/**
	 * Geocode event and persist meta.
	 *
	 * @param int  $event_id Event post ID.
	 * @param bool $force    Skip manual override check.
	 */
	public static function geocode_event( $event_id, $force = false ) {
		if ( ! $force && get_post_meta( $event_id, '_lnkup_geocode_manual_override', true ) ) {
			update_post_meta( $event_id, '_lnkup_geocode_status', self::STATUS_MANUAL );
			return array(
				'lat' => (float) get_post_meta( $event_id, '_lnkup_lat', true ),
				'lng' => (float) get_post_meta( $event_id, '_lnkup_lng', true ),
			);
		}

		$address = self::event_address_string( $event_id );
		if ( '' === $address ) {
			$city  = get_post_meta( $event_id, '_lnkup_city', true );
			$state = get_post_meta( $event_id, '_lnkup_state', true );
			$venue = get_post_meta( $event_id, '_lnkup_venue_name', true );
			$address = trim( implode( ', ', array_filter( array( $venue, $city, $state, 'USA' ) ) ) );
		}

		$coords = self::geocode( $address );
		if ( $coords ) {
			update_post_meta( $event_id, '_lnkup_lat', $coords['lat'] );
			update_post_meta( $event_id, '_lnkup_lng', $coords['lng'] );
			update_post_meta( $event_id, '_lnkup_geocode_status', self::STATUS_OK );
		} else {
			update_post_meta( $event_id, '_lnkup_geocode_status', self::STATUS_FAILED );
		}
		return $coords;
	}

	/**
	 * Set manual coordinates override.
	 *
	 * @param int   $event_id Event ID.
	 * @param float $lat      Latitude.
	 * @param float $lng      Longitude.
	 */
	public static function set_manual_coords( $event_id, $lat, $lng ) {
		$lat = self::validate_lat( $lat );
		$lng = self::validate_lng( $lng );
		if ( null === $lat || null === $lng ) {
			return new WP_Error( 'invalid_coords', __( 'Invalid coordinates.', 'lnkup-marketplace' ) );
		}
		update_post_meta( $event_id, '_lnkup_lat', $lat );
		update_post_meta( $event_id, '_lnkup_lng', $lng );
		update_post_meta( $event_id, '_lnkup_geocode_manual_override', true );
		update_post_meta( $event_id, '_lnkup_geocode_status', self::STATUS_MANUAL );
		return array( 'lat' => $lat, 'lng' => $lng );
	}

	/**
	 * Validate latitude.
	 *
	 * @param mixed $lat Latitude.
	 */
	public static function validate_lat( $lat ) {
		$lat = (float) $lat;
		if ( $lat < -90 || $lat > 90 ) {
			return null;
		}
		return $lat;
	}

	/**
	 * Validate longitude.
	 *
	 * @param mixed $lng Longitude.
	 */
	public static function validate_lng( $lng ) {
		$lng = (float) $lng;
		if ( $lng < -180 || $lng > 180 ) {
			return null;
		}
		return $lng;
	}

	/**
	 * Events missing coordinates.
	 *
	 * @param int $limit Limit.
	 */
	public static function events_missing_coords( $limit = 50 ) {
		return get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => $limit,
				'post_status'    => 'publish',
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_lnkup_lat', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_lnkup_lat', 'value' => '', 'compare' => '=' ),
					array( 'key' => '_lnkup_geocode_status', 'value' => self::STATUS_FAILED ),
				),
			)
		);
	}

	/**
	 * Batch backfill geocoding.
	 *
	 * @param int $limit Limit.
	 */
	public static function backfill_events( $limit = 25 ) {
		$events = self::events_missing_coords( $limit );
		$done   = 0;
		foreach ( $events as $event ) {
			if ( get_post_meta( $event->ID, '_lnkup_geocode_manual_override', true ) ) {
				continue;
			}
			self::geocode_event( $event->ID, true );
			$done++;
		}
		return $done;
	}

	/**
	 * Haversine distance in miles.
	 */
	public static function distance_miles( $lat1, $lng1, $lat2, $lng2 ) {
		$earth = 3959;
		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lng = deg2rad( $lng2 - $lng1 );
		$a     = sin( $d_lat / 2 ) * sin( $d_lat / 2 ) +
			cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) *
			sin( $d_lng / 2 ) * sin( $d_lng / 2 );
		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
		return $earth * $c;
	}
}
