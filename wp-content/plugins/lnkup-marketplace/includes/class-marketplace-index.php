<?php
/**
 * Denormalized marketplace search index.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Marketplace_Index {

	const TABLE = 'lnkup_marketplace_index';

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'save_post_lnkup_event', array( __CLASS__, 'on_event_save' ), 20, 2 );
		add_action( 'save_post_lnkup_opportunity', array( __CLASS__, 'on_opportunity_save' ), 20, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
	}

	/**
	 * Get table name with prefix.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Whether the index table exists.
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Create DB table on activation.
	 */
	public static function create_tables() {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			opportunity_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title varchar(255) NOT NULL DEFAULT '',
			excerpt text,
			city varchar(100) NOT NULL DEFAULT '',
			state varchar(10) NOT NULL DEFAULT '',
			venue_name varchar(255) NOT NULL DEFAULT '',
			markets text,
			categories text,
			event_types text,
			tags text,
			vendor_fee decimal(10,2) NOT NULL DEFAULT 0,
			attendance int(11) NOT NULL DEFAULT 0,
			spots_remaining int(11) NOT NULL DEFAULT 0,
			spots_total int(11) NOT NULL DEFAULT 0,
			deadline date DEFAULT NULL,
			event_date date DEFAULT NULL,
			lat decimal(10,7) DEFAULT NULL,
			lng decimal(10,7) DEFAULT NULL,
			indoor tinyint(1) NOT NULL DEFAULT 0,
			outdoor tinyint(1) NOT NULL DEFAULT 0,
			power_available tinyint(1) NOT NULL DEFAULT 0,
			water_available tinyint(1) NOT NULL DEFAULT 0,
			food_truck_allowed tinyint(1) NOT NULL DEFAULT 0,
			tent_allowed tinyint(1) NOT NULL DEFAULT 0,
			generator_allowed tinyint(1) NOT NULL DEFAULT 0,
			free_parking tinyint(1) NOT NULL DEFAULT 0,
			instant_approval tinyint(1) NOT NULL DEFAULT 0,
			organizer_verified tinyint(1) NOT NULL DEFAULT 0,
			organizer_rating decimal(3,2) DEFAULT NULL,
			application_count int(11) NOT NULL DEFAULT 0,
			view_count int(11) NOT NULL DEFAULT 0,
			trending_score decimal(12,4) NOT NULL DEFAULT 0,
			search_blob longtext,
			featured_image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			load_in_time varchar(50) NOT NULL DEFAULT '',
			required_documents text,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (opportunity_id),
			KEY event_id (event_id),
			KEY organizer_user_id (organizer_user_id),
			KEY city_state (city, state),
			KEY event_date (event_date),
			KEY trending_score (trending_score),
			FULLTEXT KEY search_ft (title, city, state, venue_name, search_blob)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Rebuild entire index.
	 */
	public static function rebuild_all() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $opps as $opp_id ) {
			self::sync_opportunity( $opp_id );
		}

		return count( $opps );
	}

	/**
	 * Sync when event saved — reindex linked opportunities.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_event_save( $post_id, $post ) {
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! get_post_meta( $post_id, '_lnkup_lat', true ) ) {
			LNKup_Geocoder::geocode_event( $post_id );
		}
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $post_id,
			)
		);
		foreach ( $opps as $opp_id ) {
			self::sync_opportunity( $opp_id );
		}
	}

	/**
	 * Sync when opportunity saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_opportunity_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' === $post->post_status ) {
			self::sync_opportunity( $post_id );
		} else {
			self::delete_row( $post_id );
		}
	}

	/**
	 * Remove index row on delete.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_delete( $post_id ) {
		$type = get_post_type( $post_id );
		if ( 'lnkup_opportunity' === $type ) {
			self::delete_row( $post_id );
		}
	}

	/**
	 * Delete index row.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 */
	public static function delete_row( $opportunity_id ) {
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'opportunity_id' => $opportunity_id ), array( '%d' ) );
	}

	/**
	 * Build and upsert index row for opportunity.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 */
	public static function sync_opportunity( $opportunity_id ) {
		$opp = get_post( $opportunity_id );
		if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type ) {
			return new WP_Error( 'invalid_opportunity', __( 'Invalid opportunity.', 'lnkup-marketplace' ) );
		}

		$status = get_post_meta( $opportunity_id, '_lnkup_opportunity_status', true );
		if ( 'open' !== $status || 'publish' !== $opp->post_status ) {
			self::delete_row( $opportunity_id );
			return new WP_Error( 'not_open', __( 'Opportunity is not open for applications.', 'lnkup-marketplace' ) );
		}

		$event_id = (int) get_post_meta( $opportunity_id, '_lnkup_event_id', true );
		$event    = $event_id ? get_post( $event_id ) : null;
		if ( ! $event || 'publish' !== $event->post_status ) {
			self::delete_row( $opportunity_id );
			return new WP_Error( 'missing_event', __( 'Linked event is missing or not published.', 'lnkup-marketplace' ) );
		}

		if ( class_exists( 'LNKup_Event_Authenticity' ) && ! LNKup_Event_Authenticity::is_bookable( $event_id ) ) {
			self::delete_row( $opportunity_id );
			return new WP_Error( 'authenticity_pending', __( 'Listing is waiting for organizer verification.', 'lnkup-marketplace' ) );
		}

		$organizer_id = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		$categories   = wp_get_post_terms( $opportunity_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
		$event_terms  = wp_get_post_terms( $event_id, 'lnkup_event_type' );
		$markets      = wp_get_post_terms( $opportunity_id, 'lnkup_market', array( 'fields' => 'names' ) );
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}
		if ( is_wp_error( $event_terms ) ) {
			$event_terms = array();
		}
		$event_types = lnkup_expand_event_type_names_for_index( $event_terms );
		if ( is_wp_error( $markets ) ) {
			$markets = array();
		}

		$organizer      = $organizer_id ? get_userdata( $organizer_id ) : null;
		$organizer_name = $organizer ? $organizer->display_name : '';
		$tags           = get_post_meta( $event_id, '_lnkup_tags', true );

		$search_parts = array(
			$event->post_title,
			$opp->post_title,
			$opp->post_content,
			$event->post_content,
			get_post_meta( $event_id, '_lnkup_city', true ),
			get_post_meta( $event_id, '_lnkup_state', true ),
			get_post_meta( $event_id, '_lnkup_venue_name', true ),
			$organizer_name,
			$tags,
			implode( ' ', $categories ),
			implode( ' ', $event_types ),
			implode( ' ', $markets ),
		);

		$app_count  = (int) get_post_meta( $opportunity_id, '_lnkup_application_count', true );
		$view_count = (int) get_post_meta( $opportunity_id, '_lnkup_view_count', true );
		$spots_rem  = (int) get_post_meta( $opportunity_id, '_lnkup_spots_remaining', true );
		$spots_tot  = (int) get_post_meta( $opportunity_id, '_lnkup_spots_total', true );

		$trending = LNKup_Trending::compute_score(
			array(
				'application_count' => $app_count,
				'view_count'        => $view_count,
				'spots_remaining'   => $spots_rem,
				'spots_total'       => $spots_tot,
				'created_at'        => $opp->post_date,
				'deadline'          => get_post_meta( $opportunity_id, '_lnkup_application_deadline', true ),
			)
		);

		$row = array(
			'opportunity_id'      => $opportunity_id,
			'event_id'            => $event_id,
			'organizer_user_id'   => $organizer_id,
			'title'               => $event->post_title,
			'excerpt'             => wp_trim_words( $opp->post_content ?: $event->post_content, 30 ),
			'city'                => (string) get_post_meta( $event_id, '_lnkup_city', true ),
			'state'               => (string) get_post_meta( $event_id, '_lnkup_state', true ),
			'venue_name'          => (string) get_post_meta( $event_id, '_lnkup_venue_name', true ),
			'markets'             => wp_json_encode( $markets ),
			'categories'          => wp_json_encode( $categories ),
			'event_types'         => wp_json_encode( $event_types ),
			'tags'                => (string) $tags,
			'vendor_fee'          => (float) get_post_meta( $opportunity_id, '_lnkup_vendor_fee', true ),
			'attendance'          => (int) get_post_meta( $event_id, '_lnkup_expected_attendance', true ),
			'spots_remaining'     => $spots_rem,
			'spots_total'         => $spots_tot,
			'deadline'            => self::normalize_date( get_post_meta( $opportunity_id, '_lnkup_application_deadline', true ) ),
			'event_date'          => self::normalize_date( get_post_meta( $event_id, '_lnkup_event_date', true ) ),
			'lat'                 => get_post_meta( $event_id, '_lnkup_lat', true ) ?: null,
			'lng'                 => get_post_meta( $event_id, '_lnkup_lng', true ) ?: null,
			'indoor'              => (int) (bool) get_post_meta( $event_id, '_lnkup_indoor', true ),
			'outdoor'             => (int) (bool) get_post_meta( $event_id, '_lnkup_outdoor', true ),
			'power_available'     => (int) (bool) get_post_meta( $event_id, '_lnkup_power_available', true ),
			'water_available'     => (int) (bool) get_post_meta( $event_id, '_lnkup_water_available', true ),
			'food_truck_allowed'  => (int) (bool) get_post_meta( $event_id, '_lnkup_food_truck_allowed', true ),
			'tent_allowed'        => (int) (bool) get_post_meta( $event_id, '_lnkup_tent_allowed', true ),
			'generator_allowed'   => (int) (bool) get_post_meta( $event_id, '_lnkup_generator_allowed', true ),
			'free_parking'        => (int) (bool) get_post_meta( $event_id, '_lnkup_free_vendor_parking', true ),
			'instant_approval'    => (int) (bool) get_post_meta( $opportunity_id, '_lnkup_instant_approval', true ),
			'organizer_verified'  => (int) (bool) get_user_meta( $organizer_id, 'lnkup_organizer_verified', true ),
			'organizer_rating'    => get_user_meta( $organizer_id, 'lnkup_organizer_rating', true ) ?: null,
			'application_count'   => $app_count,
			'view_count'          => $view_count,
			'trending_score'      => $trending,
			'search_blob'         => implode( ' ', array_filter( $search_parts ) ),
			'featured_image_id'   => (int) get_post_meta( $opportunity_id, '_lnkup_featured_image_id', true )
				?: (int) get_post_meta( $event_id, '_lnkup_featured_image_id', true ),
			'load_in_time'        => (string) get_post_meta( $opportunity_id, '_lnkup_load_in_time', true ),
			'required_documents'  => self::normalize_required_documents( get_post_meta( $opportunity_id, '_lnkup_required_documents', true ) ),
			'created_at'          => $opp->post_date,
			'updated_at'          => current_time( 'mysql' ),
		);

		global $wpdb;
		$table = self::table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT opportunity_id FROM {$table} WHERE opportunity_id = %d", $opportunity_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $exists ) {
			$wpdb->update( $table, $row, array( 'opportunity_id' => $opportunity_id ) );
		} else {
			$wpdb->insert( $table, $row );
		}

		return true;
	}

	/**
	 * Increment view count.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 */
	public static function increment_view( $opportunity_id ) {
		$count = (int) get_post_meta( $opportunity_id, '_lnkup_view_count', true );
		update_post_meta( $opportunity_id, '_lnkup_view_count', $count + 1 );
		self::sync_opportunity( $opportunity_id );
	}

	/**
	 * Increment application count.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 */
	public static function increment_applications( $opportunity_id ) {
		$count = (int) get_post_meta( $opportunity_id, '_lnkup_application_count', true );
		update_post_meta( $opportunity_id, '_lnkup_application_count', $count + 1 );
		self::sync_opportunity( $opportunity_id );
	}

	/**
	 * Normalize date string for DB.
	 *
	 * @param string $date Date string.
	 */
	private static function normalize_date( $date ) {
		if ( empty( $date ) ) {
			return null;
		}
		$ts = strtotime( $date );
		return $ts ? gmdate( 'Y-m-d', $ts ) : null;
	}

	/**
	 * Normalize required documents meta to comma-separated slugs.
	 *
	 * @param mixed $raw Raw meta value.
	 */
	private static function normalize_required_documents( $raw ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw || 'none' === $raw ) {
			return '';
		}
		$parts = array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', $raw ) ) );
		return implode( ',', $parts );
	}

	/**
	 * Normalize invalid DATE column values to NULL.
	 */
	public static function cleanup_invalid_dates() {
		if ( ! self::table_exists() ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
		// Avoid comparing DATE columns to '' — invalid in MySQL strict mode.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET deadline = NULL WHERE deadline = '0000-00-00' OR deadline < '1000-01-01'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET event_date = NULL WHERE event_date = '0000-00-00' OR event_date < '1000-01-01'" );
	}

	/**
	 * Get row by opportunity ID.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 */
	public static function get_row( $opportunity_id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE opportunity_id = %d", $opportunity_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
