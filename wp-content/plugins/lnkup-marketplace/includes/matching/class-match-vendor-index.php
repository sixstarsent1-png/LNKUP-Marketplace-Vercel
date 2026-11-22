<?php
/**
 * Derived vendor matching index (one row per vendor profile).
 *
 * Location is stored at city-centroid precision (2 decimals) and is never
 * returned to organizers. Geocoding happens in cron, never on profile save.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Match_Vendor_Index {

	const GEO_KEY_META = '_lnkup_geo_key';

	/**
	 * Preference fields counted toward profile completeness.
	 */
	const COMPLETENESS_FIELDS = array( 'categories', 'location', 'markets', 'radius', 'event_types', 'months', 'budget', 'environment' );

	public static function init() {
		add_action( 'save_post_lnkup_vendor_profile', array( __CLASS__, 'on_profile_save' ), 30, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ) );
		add_action( 'set_user_role', array( __CLASS__, 'on_user_changed' ) );
		add_action( 'added_user_meta', array( __CLASS__, 'on_user_meta' ), 10, 3 );
		add_action( 'updated_user_meta', array( __CLASS__, 'on_user_meta' ), 10, 3 );
	}

	public static function on_profile_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || ( $post && 'auto-draft' === $post->post_status ) ) {
			return;
		}
		self::sync( $post_id );
	}

	public static function on_delete( $post_id ) {
		if ( 'lnkup_vendor_profile' === get_post_type( $post_id ) ) {
			self::remove( $post_id );
		}
	}

	public static function on_user_deleted( $user_id ) {
		global $wpdb;
		$profile_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT profile_id FROM ' . LNKup_Match_Tables::vendor_index_table() . ' WHERE user_id = %d', $user_id ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $profile_ids as $profile_id ) {
			self::remove( (int) $profile_id );
		}
	}

	public static function on_user_changed( $user_id ) {
		$profile_id = (int) lnkup_get_vendor_profile_id( $user_id );
		if ( $profile_id ) {
			self::sync( $profile_id );
		}
	}

	/**
	 * Account deletion requests deactivate the vendor for matching immediately.
	 */
	public static function on_user_meta( $meta_id, $user_id, $meta_key ) {
		unset( $meta_id );
		if ( class_exists( 'LNKup_Account_Deletion' ) && LNKup_Account_Deletion::META_REQUESTED === $meta_key ) {
			self::on_user_changed( $user_id );
		}
	}

	/**
	 * Remove a profile from the index and the results cache.
	 *
	 * @param int $profile_id Profile ID.
	 */
	public static function remove( $profile_id ) {
		global $wpdb;
		$profile_id = (int) $profile_id;
		$wpdb->delete( LNKup_Match_Tables::vendor_index_table(), array( 'profile_id' => $profile_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( LNKup_Match_Tables::vendor_categories_table(), array( 'profile_id' => $profile_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( LNKup_Match_Tables::results_table(), array( 'profile_id' => $profile_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whether the profile owner can currently be matched.
	 *
	 * @param int $user_id Owner user ID.
	 */
	public static function user_is_active_vendor( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return false;
		}
		if ( class_exists( 'LNKup_Roles' ) && ! LNKup_Roles::is_vendor( $user_id ) ) {
			return false;
		}
		if ( class_exists( 'LNKup_Account_Deletion' ) && get_user_meta( $user_id, LNKup_Account_Deletion::META_REQUESTED, true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Read a JSON-or-CSV list meta value.
	 *
	 * @param int    $profile_id Profile ID.
	 * @param string $key        Meta key.
	 * @return array
	 */
	public static function list_meta( $profile_id, $key ) {
		$raw = get_post_meta( $profile_id, $key, true );
		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'trim', array_map( 'strval', $raw ) ), 'strlen' ) );
		}
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return array_values( array_filter( array_map( 'trim', array_map( 'strval', $decoded ) ), 'strlen' ) );
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' ) );
	}

	/**
	 * Matching preferences for a profile (canonical post meta, normalized).
	 *
	 * @param int $profile_id Profile ID.
	 * @return array
	 */
	public static function preferences( $profile_id ) {
		$profile_id = (int) $profile_id;
		$discover   = get_post_meta( $profile_id, '_lnkup_discoverable', true );
		$accept     = get_post_meta( $profile_id, '_lnkup_accept_invites', true );
		$fee_max    = get_post_meta( $profile_id, '_lnkup_preferred_fee_max', true );
		$env        = sanitize_key( (string) get_post_meta( $profile_id, '_lnkup_indoor_outdoor_preference', true ) );
		$categories = LNKup_Match_Categories::for_object( $profile_id );
		$primary    = (int) get_post_meta( $profile_id, '_lnkup_primary_category', true );
		if ( ! $primary && 1 === count( $categories ) ) {
			$primary = $categories[0]['term_id'];
		}
		return array(
			'categories'       => $categories,
			'primary_category' => $primary,
			'city'             => (string) get_post_meta( $profile_id, '_lnkup_city', true ),
			'state'            => strtoupper( (string) get_post_meta( $profile_id, '_lnkup_state', true ) ),
			'zip'              => (string) get_post_meta( $profile_id, '_lnkup_zip', true ),
			'cities_served'    => self::list_meta( $profile_id, '_lnkup_cities_served' ),
			'states_served'    => array_map( 'strtoupper', self::list_meta( $profile_id, '_lnkup_states_served' ) ),
			'radius_miles'     => (int) get_post_meta( $profile_id, '_lnkup_service_radius_miles', true ),
			'travel_national'  => (bool) get_post_meta( $profile_id, '_lnkup_willing_to_travel_national', true ),
			'event_types'      => self::list_meta( $profile_id, '_lnkup_preferred_event_types' ),
			'preferred_markets'=> array_map( 'intval', self::list_meta( $profile_id, '_lnkup_preferred_markets' ) ),
			'available_months' => array_values( array_filter( array_map( 'intval', self::list_meta( $profile_id, '_lnkup_available_months' ) ), array( __CLASS__, 'valid_month' ) ) ),
			'blackout_dates'   => array_values( array_filter( self::list_meta( $profile_id, '_lnkup_blackout_dates' ), array( __CLASS__, 'valid_date' ) ) ),
			'fee_max'          => '' === (string) $fee_max ? null : (float) $fee_max,
			'env_pref'         => in_array( $env, array( 'indoor', 'outdoor', 'both' ), true ) ? $env : '',
			'discoverable'     => '' === (string) $discover ? true : (bool) $discover,
			'accept_invites'   => '' === (string) $accept ? true : (bool) $accept,
		);
	}

	/**
	 * Store opt-in/opt-out flags as '1'/'0' so an explicit "no" is never read as unset.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_flag( $value ) {
		return rest_sanitize_boolean( $value ) ? '1' : '0';
	}

	public static function valid_month( $month ) {
		return $month >= 1 && $month <= 12;
	}

	public static function valid_date( $date ) {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date );
	}

	/**
	 * Completeness of matching preferences.
	 *
	 * @param array $prefs Output of preferences().
	 * @return array{score:int,missing:string[]}
	 */
	public static function completeness( array $prefs ) {
		$have = array(
			'categories'  => ! empty( $prefs['categories'] ),
			'location'    => '' !== $prefs['city'] || '' !== $prefs['state'],
			'markets'     => ! empty( $prefs['cities_served'] ) || ! empty( $prefs['states_served'] ) || ! empty( $prefs['travel_national'] ),
			'radius'      => $prefs['radius_miles'] > 0 || ! empty( $prefs['travel_national'] ),
			'event_types' => ! empty( $prefs['event_types'] ),
			'months'      => ! empty( $prefs['available_months'] ),
			'budget'      => null !== $prefs['fee_max'],
			'environment' => '' !== $prefs['env_pref'],
		);
		$missing = array_keys( array_filter( $have, static function ( $ok ) {
			return ! $ok;
		} ) );
		return array(
			'score'   => (int) round( ( count( $have ) - count( $missing ) ) / count( $have ) * 100 ),
			'missing' => $missing,
		);
	}

	/**
	 * Upsert the index row for a profile.
	 *
	 * @param int $profile_id Profile ID.
	 */
	public static function sync( $profile_id ) {
		$profile_id = (int) $profile_id;
		$post       = get_post( $profile_id );
		if ( ! $post || 'lnkup_vendor_profile' !== $post->post_type || 'publish' !== $post->post_status ) {
			self::remove( $profile_id );
			return false;
		}
		$user_id = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		if ( ! $user_id ) {
			self::remove( $profile_id );
			return false;
		}

		$prefs   = self::preferences( $profile_id );
		$geo_key = strtolower( implode( '|', array( $prefs['city'], $prefs['state'], $prefs['zip'] ) ) );
		if ( get_post_meta( $profile_id, self::GEO_KEY_META, true ) !== $geo_key ) {
			delete_post_meta( $profile_id, '_lnkup_geo_lat' );
			delete_post_meta( $profile_id, '_lnkup_geo_lng' );
			update_post_meta( $profile_id, self::GEO_KEY_META, $geo_key );
		}
		$lat = get_post_meta( $profile_id, '_lnkup_geo_lat', true );
		$lng = get_post_meta( $profile_id, '_lnkup_geo_lng', true );

		$row = array(
			'profile_id'           => $profile_id,
			'user_id'              => $user_id,
			'discoverable'         => $prefs['discoverable'] ? 1 : 0,
			'accept_invites'       => $prefs['accept_invites'] ? 1 : 0,
			'status'               => self::user_is_active_vendor( $user_id ) ? 'active' : 'inactive',
			'city'                 => substr( $prefs['city'], 0, 100 ),
			'state'                => substr( $prefs['state'], 0, 10 ),
			'zip'                  => substr( $prefs['zip'], 0, 12 ),
			'lat'                  => is_numeric( $lat ) ? round( (float) $lat, 2 ) : null,
			'lng'                  => is_numeric( $lng ) ? round( (float) $lng, 2 ) : null,
			'radius_miles'         => max( 0, (int) $prefs['radius_miles'] ),
			'travel_national'      => $prefs['travel_national'] ? 1 : 0,
			'cities_served'        => wp_json_encode( $prefs['cities_served'] ),
			'states_served'        => wp_json_encode( $prefs['states_served'] ),
			'event_types'          => wp_json_encode( $prefs['event_types'] ),
			'fee_max'              => $prefs['fee_max'],
			'env_pref'             => $prefs['env_pref'],
			'available_months'     => implode( ',', $prefs['available_months'] ),
			'primary_category'     => (int) $prefs['primary_category'],
			'verified'             => 'approved' === get_post_meta( $profile_id, '_lnkup_verification_status', true ) ? 1 : 0,
			'profile_completeness' => self::completeness( $prefs )['score'],
			'updated_at'           => current_time( 'mysql' ),
		);

		global $wpdb;
		$wpdb->replace( LNKup_Match_Tables::vendor_index_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$cat_table = LNKup_Match_Tables::vendor_categories_table();
		$wpdb->delete( $cat_table, array( 'profile_id' => $profile_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// Upsert: a concurrent sync may already have re-inserted the same pair after our delete.
		foreach ( $prefs['categories'] as $cat ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"INSERT INTO {$cat_table} (profile_id, term_id, group_key) VALUES (%d, %d, %s) ON DUPLICATE KEY UPDATE group_key = VALUES(group_key)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$profile_id,
					$cat['term_id'],
					$cat['group']
				)
			);
		}

		LNKup_Match_Cron::mark_dirty( 'vendor', $profile_id );
		return true;
	}

	/**
	 * Geocode a batch of indexed vendors that have a city but no centroid.
	 *
	 * @param int $limit Max lookups per run (Nominatim is rate-limited).
	 */
	public static function geocode_batch( $limit = 20 ) {
		global $wpdb;
		$table = LNKup_Match_Tables::vendor_index_table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT profile_id, city, state, zip FROM {$table} WHERE lat IS NULL AND (city <> '' OR zip <> '') ORDER BY updated_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $limit )
			),
			ARRAY_A
		);
		$done = 0;
		foreach ( (array) $rows as $row ) {
			$profile_id = (int) $row['profile_id'];
			if ( get_post_meta( $profile_id, '_lnkup_geo_failed', true ) === strtolower( $row['city'] . '|' . $row['state'] . '|' . $row['zip'] ) ) {
				continue;
			}
			$query  = trim( implode( ', ', array_filter( array( $row['city'], $row['state'], $row['zip'], 'USA' ) ) ) );
			$coords = LNKup_Geocoder::geocode( $query );
			if ( ! $coords ) {
				update_post_meta( $profile_id, '_lnkup_geo_failed', strtolower( $row['city'] . '|' . $row['state'] . '|' . $row['zip'] ) );
				continue;
			}
			$lat = round( (float) $coords['lat'], 2 );
			$lng = round( (float) $coords['lng'], 2 );
			update_post_meta( $profile_id, '_lnkup_geo_lat', (string) $lat );
			update_post_meta( $profile_id, '_lnkup_geo_lng', (string) $lng );
			$wpdb->update( $table, array( 'lat' => $lat, 'lng' => $lng ), array( 'profile_id' => $profile_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			LNKup_Match_Cron::mark_dirty( 'vendor', $profile_id );
			++$done;
		}
		return $done;
	}

	/**
	 * Backfill a page of profiles into the index.
	 *
	 * @param int $page     1-based page.
	 * @param int $per_page Page size.
	 * @return int Profiles processed.
	 */
	public static function backfill_page( $page, $per_page = 200 ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_profile',
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => max( 1, (int) $page ),
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			self::sync( (int) $id );
		}
		return count( $ids );
	}

	/**
	 * Index row for a profile.
	 *
	 * @param int $profile_id Profile ID.
	 */
	public static function get_row( $profile_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Match_Tables::vendor_index_table() . ' WHERE profile_id = %d', (int) $profile_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}
}
