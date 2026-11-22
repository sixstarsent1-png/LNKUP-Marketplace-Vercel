<?php
/**
 * Vendor ↔ opportunity matching using real profile and opportunity data.
 *
 * Inputs: opportunity vendor categories (lnkup_vendor_category), event city/state,
 * vendor profile categories, home city/state, cities and states served.
 * No GPS is required. Profiles must be published (public) to be matched or counted.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Matching {

	/**
	 * What an opportunity is looking for.
	 *
	 * @return array{categories:int[],city:string,state:string,event_id:int,organizer_id:int}
	 */
	public static function signature( int $opp_id ): array {
		$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		$terms    = wp_get_object_terms( $opp_id, 'lnkup_vendor_category', array( 'fields' => 'ids' ) );
		$city     = trim( (string) get_post_meta( $event_id ?: $opp_id, '_lnkup_city', true ) );
		$state    = trim( (string) get_post_meta( $event_id ?: $opp_id, '_lnkup_state', true ) );
		if ( '' === $city && $event_id ) {
			$city  = trim( (string) get_post_meta( $opp_id, '_lnkup_city', true ) );
			$state = '' !== $state ? $state : trim( (string) get_post_meta( $opp_id, '_lnkup_state', true ) );
		}
		$organizer = (int) get_post_meta( $event_id ?: $opp_id, '_lnkup_organizer_user_id', true );
		return array(
			'categories'   => is_wp_error( $terms ) ? array() : array_map( 'intval', (array) $terms ),
			'city'         => $city,
			'state'        => $state,
			'event_id'     => $event_id,
			'organizer_id' => $organizer,
		);
	}

	/**
	 * WP_Query args for vendor profiles matching a signature.
	 */
	private static function profile_query( array $sig, int $page, int $per_page ): array {
		$geo = array( 'relation' => 'OR' );
		if ( '' !== $sig['city'] ) {
			$geo[] = array( 'key' => '_lnkup_city', 'value' => $sig['city'] );
			$geo[] = array( 'key' => '_lnkup_cities_served', 'value' => $sig['city'], 'compare' => 'LIKE' );
		}
		if ( '' !== $sig['state'] ) {
			$geo[] = array( 'key' => '_lnkup_states_served', 'value' => $sig['state'], 'compare' => 'LIKE' );
			$geo[] = array(
				'relation' => 'AND',
				array( 'key' => '_lnkup_state', 'value' => $sig['state'] ),
				array( 'key' => '_lnkup_willing_to_travel', 'value' => array( '1', 'yes', 'true' ), 'compare' => 'IN' ),
			);
		}
		$args = array(
			'post_type'              => 'lnkup_vendor_profile',
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page,
			'paged'                  => max( 1, $page ),
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'update_post_term_cache' => false,
			'meta_query'             => count( $geo ) > 1 ? array( $geo ) : array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
		if ( $sig['categories'] ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'lnkup_vendor_category',
					'field'    => 'term_id',
					'terms'    => $sig['categories'],
				),
			);
		}
		return $args;
	}

	/**
	 * One page of matching vendor user IDs (profile owners).
	 *
	 * @return array{user_ids:int[],has_more:bool}
	 */
	public static function vendor_page( int $opp_id, int $page, int $per_page = 200 ): array {
		if ( self::core_engine_ready() ) {
			return self::core_engine_page( $opp_id, $page, $per_page );
		}
		$sig = self::signature( $opp_id );
		if ( '' === $sig['city'] && '' === $sig['state'] ) {
			// Without a location we cannot match responsibly; never broadcast nationally.
			return array( 'user_ids' => array(), 'has_more' => false );
		}
		$query   = new WP_Query( self::profile_query( $sig, $page, $per_page ) );
		$users   = array();
		foreach ( (array) $query->posts as $profile_id ) {
			$owner = (int) get_post_meta( (int) $profile_id, '_lnkup_owner_user_id', true );
			if ( $owner > 0 ) {
				$users[ $owner ] = $owner;
			}
		}
		return array(
			'user_ids' => array_values( $users ),
			'has_more' => $page < (int) $query->max_num_pages,
		);
	}

	/**
	 * Count of public vendor profiles that match any open opportunity of an event.
	 * Only a count is exposed to organizers; never identities or contact data.
	 */
	public static function count_for_event( int $event_id ): int {
		if ( self::core_engine_ready() ) {
			// Same visibility as Find Vendors: active, and discoverable or already related to this organizer.
			return LNKup_Match_Service::discoverable_match_count( $event_id, (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) );
		}
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$ids = array();
		foreach ( array_map( 'intval', $opps ) as $opp_id ) {
			$sig = self::signature( $opp_id );
			if ( '' === $sig['city'] && '' === $sig['state'] ) {
				continue;
			}
			$args           = self::profile_query( $sig, 1, 2000 );
			$args['fields'] = 'ids';
			foreach ( (array) get_posts( $args ) as $profile_id ) {
				$ids[ (int) $profile_id ] = true;
			}
		}
		return count( $ids );
	}

	/**
	 * lnkup-marketplace ships a scored match engine (eligibility, categories, distance,
	 * availability). Prefer its cached results whenever it is enabled.
	 */
	public static function core_engine_ready(): bool {
		return class_exists( 'LNKup_Match_Settings' ) && class_exists( 'LNKup_Match_Tables' ) && class_exists( 'LNKup_Match_Service' )
			&& LNKup_Match_Settings::enabled() && LNKup_Match_Tables::exists( LNKup_Match_Tables::results_table() );
	}

	/**
	 * Eligible vendors for an opportunity from the core engine (computed on demand if missing).
	 *
	 * @return array{user_ids:int[],has_more:bool}
	 */
	private static function core_engine_page( int $opp_id, int $page, int $per_page ): array {
		global $wpdb;
		$table  = LNKup_Match_Tables::results_table();
		$vtable = LNKup_Match_Tables::vendor_index_table();
		$from   = "{$table} r INNER JOIN {$vtable} v ON v.profile_id = r.profile_id";
		$where  = $wpdb->prepare( "r.opportunity_id = %d AND r.eligible = 1 AND v.status = 'active'", $opp_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$from} WHERE {$where}" );
		if ( 0 === $total && 1 === $page ) {
			LNKup_Match_Service::compute_for_opportunity( $opp_id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$from} WHERE {$where}" );
		}
		$offset = ( max( 1, $page ) - 1 ) * $per_page;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$profiles = (array) $wpdb->get_col( $wpdb->prepare( "SELECT r.profile_id FROM {$from} WHERE {$where} ORDER BY r.score DESC, r.profile_id ASC LIMIT %d OFFSET %d", $per_page, $offset ) );
		$users    = array();
		foreach ( array_map( 'intval', $profiles ) as $profile_id ) {
			if ( 'publish' !== get_post_status( $profile_id ) ) {
				continue;
			}
			$owner = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
			if ( $owner > 0 ) {
				$users[ $owner ] = $owner;
			}
		}
		return array(
			'user_ids' => array_values( $users ),
			'has_more' => $offset + $per_page < $total,
		);
	}

	/**
	 * Open vendor spaces across an event's published, open opportunities.
	 */
	public static function event_open_spots( int $event_id ): int {
		$open = 0;
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( array_map( 'intval', $opps ) as $opp_id ) {
			if ( LNKup_Comms_Context::opportunity_open( $opp_id ) ) {
				$open += max( 0, (int) get_post_meta( $opp_id, '_lnkup_spots_remaining', true ) );
			}
		}
		return $open;
	}

	/**
	 * Published tour an opportunity belongs to (0 when none), only when tour matching is on.
	 */
	public static function tour_for_opportunity( int $opp_id ): int {
		if ( ! self::core_engine_ready() || ! LNKup_Match_Settings::enabled( 'matching_tours_enabled' ) ) {
			return 0;
		}
		$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		$tour_id  = $event_id ? (int) get_post_meta( $event_id, '_lnkup_tour_id', true ) : 0;
		return $tour_id && 'lnkup_tour' === get_post_type( $tour_id ) && 'publish' === get_post_status( $tour_id ) ? $tour_id : 0;
	}

	/**
	 * Vendors who completed a paid booking with this organizer before.
	 *
	 * @return int[]
	 */
	public static function worked_with( int $organizer_id, int $limit = 500 ): array {
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) $organizer_id ),
					array( 'key' => '_lnkup_application_status', 'value' => array( 'paid', 'completed' ), 'compare' => 'IN' ),
				),
			)
		);
		$vendors = array();
		foreach ( array_map( 'intval', $apps ) as $app_id ) {
			$vendor = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
			if ( $vendor ) {
				$vendors[ $vendor ] = $vendor;
			}
		}
		return array_values( $vendors );
	}
}
