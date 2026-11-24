<?php
/**
 * Match liquidity per city and vendor category (Matching Phase 7).
 *
 * Organizer Growth's vendor supply snapshot counts vendors and booths by geography. This
 * snapshot adds whether they actually match: booths with too few recommended vendors, vendors
 * with no recommended booth, recommendations that led to applications, and what vendors search
 * for without finding anything. It uses the same city key (lower(city)|UPPER(state)) and runs
 * just before that snapshot so both describe the same day.
 *
 * Every figure is a count of records; signals are threshold rules shown next to the counts.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Match_Market {

	const LOCK            = 'lnkup_match_market_build';
	const MAX_CITIES      = 500;
	const KEEP_DAYS       = 120;
	const WINDOW_DAYS     = 30;
	const REBUILD_OPTION  = 'lnkup_match_market_last_rebuild';
	const STATUS_LOG_OPTION = 'lnkup_market_status_log';
	const SIGNALS         = array( 'unmet_search', 'idle_supply', 'thin_supply', 'healthy', 'insufficient_data' );
	const COUNT_COLUMNS   = array( 'open_opportunities', 'opps_thin', 'opps_unmatched', 'matched_vendors', 'idle_vendors', 'recommended_pairs', 'applications_30d_from_recs', 'searches_30d', 'zero_result_searches_30d' );

	public static function init() {
		// Before Organizer Growth's supply snapshot (45) and lead rescoring (50).
		add_action( 'lnkup_marketplace_daily', array( __CLASS__, 'daily' ), 44 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function table() {
		return LNKup_Match_Tables::market_table();
	}

	public static function key( $city, $state ) {
		return strtolower( trim( (string) $city ) ) . '|' . strtoupper( trim( (string) $state ) );
	}

	/**
	 * Threshold rules (filterable).
	 *
	 * @return array{thin_below:int,idle_min:int,idle_ratio:int,unmet_min:int,thin_share:float}
	 */
	public static function thresholds() {
		return (array) apply_filters(
			'lnkup_match_market_thresholds',
			array(
				'thin_below' => 3,   // Booths with fewer recommended vendors than this are "thin".
				'thin_share' => 0.5, // Share of open booths that are thin before the city is flagged.
				'idle_min'   => 5,   // Idle vendors needed before "idle supply" is flagged.
				'idle_ratio' => 2,   // Idle vendors per open booth for "idle supply".
				'unmet_min'  => 3,   // Zero-result searches (30 days) with no open booths for "unmet search".
			)
		);
	}

	/**
	 * Signal for one snapshot row (term 0 or a category).
	 *
	 * @param array $row Snapshot row (counts).
	 */
	public static function signal( array $row ) {
		$t     = self::thresholds();
		$open  = (int) $row['open_opportunities'];
		$idle  = (int) $row['idle_vendors'];
		$thin  = (int) $row['opps_thin'];
		$zero  = (int) $row['zero_result_searches_30d'];
		if ( 0 === $open && $zero >= $t['unmet_min'] ) {
			return 'unmet_search';
		}
		if ( $idle >= $t['idle_min'] && $idle >= $t['idle_ratio'] * max( 1, $open ) ) {
			return 'idle_supply';
		}
		if ( $open > 0 && $thin > 0 && $thin / $open >= $t['thin_share'] ) {
			return 'thin_supply';
		}
		if ( 0 === $open && (int) $row['matched_vendors'] + $idle < 3 && (int) $row['searches_30d'] < 3 ) {
			return 'insufficient_data';
		}
		return 'healthy';
	}

	/**
	 * Plain-language reading of a signal.
	 *
	 * @param string $signal Signal key.
	 */
	public static function signal_label( $signal ) {
		$labels = array(
			'unmet_search'      => __( 'Vendors search here but find no booths', 'lnkup-marketplace' ),
			'idle_supply'       => __( 'Matching vendors with nothing to apply to (needs organizers)', 'lnkup-marketplace' ),
			'thin_supply'       => __( 'Booths with too few matching vendors (needs vendors)', 'lnkup-marketplace' ),
			'healthy'           => __( 'Healthy', 'lnkup-marketplace' ),
			'insufficient_data' => __( 'Not enough data', 'lnkup-marketplace' ),
		);
		return $labels[ $signal ] ?? $signal;
	}

	public static function daily() {
		if ( class_exists( 'LNKup_Match_Settings' ) && LNKup_Match_Settings::active() ) {
			self::build_snapshot();
		}
	}

	// ------------------------------------------------------------------ Build.

	/**
	 * Rebuild today's snapshot (idempotent: today's rows are replaced).
	 *
	 * @return array{cities:int,rows:int,snapshot_date:string}|WP_Error
	 */
	public static function build_snapshot() {
		global $wpdb;
		if ( ! LNKup_Match_Tables::exists( self::table() ) || ! LNKup_Match_Tables::exists( LNKup_Match_Tables::results_table() ) ) {
			return new WP_Error( 'lnkup_market_unavailable', __( 'Matching data is not available on this site yet.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK ) ) ) {
			return new WP_Error( 'lnkup_market_busy', __( 'A market snapshot is already being built. Try again in a minute.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		try {
			$result = self::build_rows();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
		}
		return $result;
	}

	private static function build_rows() {
		global $wpdb;
		$date  = current_time( 'Y-m-d' );
		$t     = self::thresholds();
		$opps  = self::open_opportunities( $date );
		$recs  = self::recommended_by_opportunity( array_keys( $opps ) );
		$names = array();
		$cells = array();

		$add = static function ( $key, $term, $col, $n = 1 ) use ( &$cells ) {
			if ( ! isset( $cells[ $key ][ $term ] ) ) {
				$cells[ $key ][ $term ] = array_fill_keys( self::COUNT_COLUMNS, 0 );
			}
			$cells[ $key ][ $term ][ $col ] += $n;
		};

		// Demand side and recommendations per booth.
		$matched = array(); // city key => term => profile set.
		foreach ( $opps as $id => $o ) {
			$k           = self::key( $o['city'], $o['state'] );
			$names[ $k ] = array( $o['city'], $o['state'] );
			$profiles    = $recs[ $id ] ?? array();
			foreach ( array_merge( array( 0 ), $o['terms'] ) as $term ) {
				$add( $k, $term, 'open_opportunities' );
				$add( $k, $term, 'recommended_pairs', count( $profiles ) );
				if ( count( $profiles ) < $t['thin_below'] ) {
					$add( $k, $term, 'opps_thin' );
				}
				if ( ! $profiles ) {
					$add( $k, $term, 'opps_unmatched' );
				}
				foreach ( $profiles as $pid ) {
					$matched[ $k ][ $term ][ $pid ] = true;
				}
			}
		}
		foreach ( $matched as $k => $terms ) {
			foreach ( $terms as $term => $set ) {
				$add( $k, $term, 'matched_vendors', count( $set ) );
			}
		}

		// Idle supply: active, discoverable vendors based in or serving the city with no recommended open booth there.
		foreach ( self::vendors() as $pid => $v ) {
			foreach ( $v['city_keys'] as $k ) {
				if ( isset( $matched[ $k ][0][ $pid ] ) ) {
					continue;
				}
				if ( $k === $v['home_key'] ) {
					$names[ $k ] = $names[ $k ] ?? array( $v['city'], $v['state'] );
				}
				if ( ! isset( $names[ $k ] ) ) {
					continue;
				}
				foreach ( array_merge( array( 0 ), $v['terms'] ) as $term ) {
					$add( $k, $term, 'idle_vendors' );
				}
			}
		}

		// Applications that followed a recommendation.
		foreach ( self::rec_applications( $date ) as $row ) {
			$k = $row['key'];
			if ( '' === $k ) {
				continue;
			}
			$names[ $k ] = $names[ $k ] ?? array( $row['city'], $row['state'] );
			foreach ( array_merge( array( 0 ), $row['terms'] ) as $term ) {
				$add( $k, $term, 'applications_30d_from_recs' );
			}
		}

		// Search demand.
		$lookup = self::term_lookup();
		foreach ( self::searches( $date ) as $s ) {
			$k = self::key( $s['city'], $s['state'] );
			if ( '' === $s['state'] ) {
				// No state in the search: credit the single known city with that name, if unambiguous.
				$candidates = array_values( array_filter( array_keys( $names ), static fn( $nk ) => 0 === strpos( $nk, strtolower( trim( $s['city'] ) ) . '|' ) ) );
				$k          = 1 === count( $candidates ) ? $candidates[0] : $k;
			}
			$names[ $k ] = $names[ $k ] ?? array( $s['city'], $s['state'] );
			$terms       = array( 0 );
			foreach ( $s['categories'] as $cat ) {
				$term = $lookup[ strtolower( (string) $cat ) ] ?? 0;
				if ( $term ) {
					$terms[] = $term;
				}
			}
			foreach ( array_unique( $terms ) as $term ) {
				$add( $k, $term, 'searches_30d' );
				if ( $s['zero'] ) {
					$add( $k, $term, 'zero_result_searches_30d' );
				}
			}
		}

		// Keep the busiest cities.
		$rank = array();
		foreach ( $cells as $k => $terms ) {
			$all        = $terms[0] ?? array_fill_keys( self::COUNT_COLUMNS, 0 );
			$rank[ $k ] = $all['open_opportunities'] + $all['matched_vendors'] + $all['idle_vendors'] + $all['searches_30d'];
		}
		arsort( $rank );
		$keep = array_slice( array_keys( $rank ), 0, self::MAX_CITIES );

		$rows = array();
		foreach ( $keep as $k ) {
			list( $city, $state ) = $names[ $k ] ?? array( '', '' );
			if ( ! isset( $cells[ $k ][0] ) ) {
				$cells[ $k ][0] = array_fill_keys( self::COUNT_COLUMNS, 0 );
			}
			foreach ( $cells[ $k ] as $term => $counts ) {
				$rows[] = array_merge( array( 'city_key' => $k, 'city' => (string) $city, 'state' => strtoupper( (string) $state ), 'term_id' => (int) $term ), $counts );
			}
		}

		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE snapshot_date = %s", $date ) );
		$now  = current_time( 'mysql' );
		$cols = array_merge( array( 'snapshot_date', 'city_key', 'city', 'state', 'term_id' ), self::COUNT_COLUMNS, array( 'created_at' ) );
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$place  = array();
			$values = array();
			foreach ( $chunk as $r ) {
				$place[] = '(%s, %s, %s, %s, %d' . str_repeat( ', %d', count( self::COUNT_COLUMNS ) ) . ', %s)';
				$values  = array_merge( $values, array( $date, substr( $r['city_key'], 0, 140 ), substr( $r['city'], 0, 100 ), substr( $r['state'], 0, 10 ), $r['term_id'] ) );
				foreach ( self::COUNT_COLUMNS as $col ) {
					$values[] = (int) $r[ $col ];
				}
				$values[] = $now;
			}
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (" . implode( ', ', $cols ) . ') VALUES ' . implode( ', ', $place ), $values ) );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE snapshot_date < %s", gmdate( 'Y-m-d', strtotime( $date ) - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
		// phpcs:enable
		return array( 'cities' => count( $keep ), 'rows' => count( $rows ), 'snapshot_date' => $date );
	}

	/**
	 * Open booths (indexed, spots left, deadline and event not past) with category term IDs.
	 *
	 * @return array<int,array{city:string,state:string,terms:int[],organizer:int,event_id:int}>
	 */
	private static function open_opportunities( $today ) {
		global $wpdb;
		$index = LNKup_Marketplace_Index::table_name();
		$cats  = LNKup_Match_Tables::opportunity_categories_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT opportunity_id, event_id, organizer_user_id, city, state FROM {$index} WHERE spots_remaining > 0 AND (deadline IS NULL OR deadline >= %s) AND (event_date IS NULL OR event_date >= %s) AND city <> '' LIMIT 20000", $today, $today ), ARRAY_A );
		$terms = array();
		foreach ( (array) $wpdb->get_results( "SELECT opportunity_id, term_id FROM {$cats}", ARRAY_A ) as $t ) {
			$terms[ (int) $t['opportunity_id'] ][] = (int) $t['term_id'];
		}
		// phpcs:enable
		$out = array();
		foreach ( $rows as $r ) {
			$id         = (int) $r['opportunity_id'];
			$out[ $id ] = array(
				'city'      => trim( (string) $r['city'] ),
				'state'     => strtoupper( trim( (string) $r['state'] ) ),
				'terms'     => array_values( array_unique( $terms[ $id ] ?? array() ) ),
				'organizer' => (int) $r['organizer_user_id'],
				'event_id'  => (int) $r['event_id'],
			);
		}
		return $out;
	}

	/**
	 * Recommended active vendors (profile IDs) per open booth.
	 *
	 * @param int[] $opp_ids Open opportunity IDs.
	 * @return array<int,int[]>
	 */
	private static function recommended_by_opportunity( array $opp_ids ) {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_map( 'intval', $opp_ids ), 1000 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( 'SELECT r.opportunity_id, r.profile_id FROM ' . LNKup_Match_Tables::results_table() . ' r INNER JOIN ' . LNKup_Match_Tables::vendor_index_table() . " v ON v.profile_id = r.profile_id AND v.status = 'active' WHERE r.eligible = 1 AND r.opportunity_id IN (" . implode( ',', $chunk ) . ')', ARRAY_A );
			foreach ( $rows as $r ) {
				$out[ (int) $r['opportunity_id'] ][] = (int) $r['profile_id'];
			}
		}
		return $out;
	}

	/**
	 * Active, discoverable vendors with the city keys they are based in or serve.
	 *
	 * @return array<int,array{city:string,state:string,home_key:string,city_keys:string[],terms:int[]}>
	 */
	private static function vendors() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = (array) $wpdb->get_results( 'SELECT profile_id, city, state, cities_served FROM ' . LNKup_Match_Tables::vendor_index_table() . " WHERE status = 'active' AND discoverable = 1 LIMIT 20000", ARRAY_A );
		$terms = array();
		foreach ( (array) $wpdb->get_results( 'SELECT profile_id, term_id FROM ' . LNKup_Match_Tables::vendor_categories_table(), ARRAY_A ) as $t ) {
			$terms[ (int) $t['profile_id'] ][] = (int) $t['term_id'];
		}
		// phpcs:enable
		$out = array();
		foreach ( $rows as $r ) {
			$pid   = (int) $r['profile_id'];
			$state = strtoupper( trim( (string) $r['state'] ) );
			$home  = '' !== trim( (string) $r['city'] ) ? self::key( (string) $r['city'], $state ) : '';
			$keys  = $home ? array( $home ) : array();
			foreach ( (array) json_decode( (string) $r['cities_served'], true ) as $entry ) {
				$parts = array_map( 'trim', explode( ',', (string) $entry ) );
				if ( '' !== $parts[0] ) {
					$keys[] = self::key( $parts[0], isset( $parts[1] ) && '' !== $parts[1] ? substr( $parts[1], 0, 2 ) : $state );
				}
			}
			$out[ $pid ] = array(
				'city'      => trim( (string) $r['city'] ),
				'state'     => $state,
				'home_key'  => $home,
				'city_keys' => array_values( array_unique( $keys ) ),
				'terms'     => array_values( array_unique( $terms[ $pid ] ?? array() ) ),
			);
		}
		return $out;
	}

	/**
	 * match_rec_application events in the window, located by the opportunity's event city.
	 *
	 * @return array<int,array{key:string,city:string,state:string,terms:int[]}>
	 */
	private static function rec_applications( $date ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( $date ) - self::WINDOW_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT opportunity_id FROM ' . LNKup_Marketplace_Analytics::table_name() . " WHERE event_type = 'match_rec_application' AND created_at >= %s AND opportunity_id > 0 LIMIT 20000", $since ) ) );
		$out = array();
		foreach ( $ids as $opp ) {
			$event = (int) get_post_meta( $opp, '_lnkup_event_id', true );
			$city  = trim( (string) get_post_meta( $event, '_lnkup_city', true ) );
			$state = strtoupper( trim( (string) get_post_meta( $event, '_lnkup_state', true ) ) );
			$terms = wp_get_object_terms( $opp, 'lnkup_vendor_category', array( 'fields' => 'ids' ) );
			$out[] = array(
				'key'   => '' !== $city ? self::key( $city, $state ) : '',
				'city'  => $city,
				'state' => $state,
				'terms' => is_wp_error( $terms ) ? array() : array_map( 'intval', $terms ),
			);
		}
		return $out;
	}

	/**
	 * First-page searches in the window that named a city.
	 *
	 * @return array<int,array{city:string,state:string,categories:string[],zero:bool}>
	 */
	private static function searches( $date ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( $date ) - self::WINDOW_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT event_type, event_data FROM ' . LNKup_Marketplace_Analytics::table_name() . " WHERE event_type IN ('search','search_no_results') AND created_at >= %s AND event_data LIKE %s ORDER BY id DESC LIMIT 50000", $since, '%"city":"_%' ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$data = json_decode( (string) $r['event_data'], true );
			if ( ! is_array( $data ) || '' === trim( (string) ( $data['city'] ?? '' ) ) || (int) ( $data['page'] ?? 1 ) > 1 ) {
				continue;
			}
			$out[] = array(
				'city'       => trim( (string) $data['city'] ),
				'state'      => strtoupper( trim( (string) ( $data['state'] ?? '' ) ) ),
				'categories' => array_map( 'strval', (array) ( $data['categories'] ?? array() ) ),
				'zero'       => 'search_no_results' === $r['event_type'] || 0 === (int) ( $data['total'] ?? 1 ),
			);
		}
		return $out;
	}

	/**
	 * Vendor category name / slug (lowercase) => term ID.
	 *
	 * @return array<string,int>
	 */
	private static function term_lookup() {
		$terms = get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false ) );
		$out   = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$out[ strtolower( $t->name ) ] = (int) $t->term_id;
			$out[ strtolower( $t->slug ) ] = (int) $t->term_id;
		}
		return $out;
	}

	// ------------------------------------------------------------------ Read.

	public static function latest_date() {
		global $wpdb;
		if ( ! LNKup_Match_Tables::exists( self::table() ) ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var( 'SELECT MAX(snapshot_date) FROM ' . self::table() );
	}

	/**
	 * Snapshot row as integers plus its signal.
	 *
	 * @param array $row Raw row.
	 */
	private static function format( array $row ) {
		$out = array(
			'city_key' => (string) $row['city_key'],
			'city'     => (string) $row['city'],
			'state'    => (string) $row['state'],
			'label'    => trim( (string) $row['city'] . ( '' !== (string) $row['state'] ? ', ' . $row['state'] : '' ) ),
			'term_id'  => (int) $row['term_id'],
		);
		foreach ( self::COUNT_COLUMNS as $col ) {
			$out[ $col ] = (int) $row[ $col ];
		}
		$out['signal']       = self::signal( $out );
		$out['signal_label'] = self::signal_label( $out['signal'] );
		return $out;
	}

	/**
	 * Cities in the latest snapshot with 7-day change and the Organizer Growth supply signal.
	 *
	 * @param array $f state, term_id, signal, sort, limit.
	 * @return array<string,mixed>
	 */
	public static function cities( array $f = array() ) {
		global $wpdb;
		$date = self::latest_date();
		if ( '' === $date ) {
			return array( 'snapshot_date' => '', 'items' => array(), 'total' => 0, 'categories' => array(), 'thresholds' => self::thresholds() );
		}
		$table  = self::table();
		$term   = absint( $f['term_id'] ?? 0 );
		$where  = array( 'snapshot_date = %s', 'term_id = %d' );
		$values = array( $date, $term );
		$state  = strtoupper( sanitize_text_field( (string) ( $f['state'] ?? '' ) ) );
		if ( '' !== $state ) {
			$where[]  = 'state = %s';
			$values[] = $state;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows      = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' LIMIT ' . self::MAX_CITIES, $values ), ARRAY_A );
		$prev_date = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(snapshot_date) FROM {$table} WHERE snapshot_date <= %s", gmdate( 'Y-m-d', strtotime( $date ) - 7 * DAY_IN_SECONDS ) ) );
		$prev      = array();
		if ( '' !== $prev_date ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT city_key, idle_vendors, opps_thin, matched_vendors FROM {$table} WHERE snapshot_date = %s AND term_id = %d", $prev_date, $term ), ARRAY_A ) as $p ) {
				$prev[ (string) $p['city_key'] ] = $p;
			}
		}
		$term_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT term_id FROM {$table} WHERE snapshot_date = %s AND term_id > 0", $date ) ) );
		// phpcs:enable
		$org    = self::org_signals();
		$signal = sanitize_key( (string) ( $f['signal'] ?? '' ) );
		$items  = array();
		foreach ( $rows as $row ) {
			$item = self::format( $row );
			if ( '' !== $signal && $item['signal'] !== $signal ) {
				continue;
			}
			$p                       = $prev[ $item['city_key'] ] ?? null;
			$item['delta_idle']      = null === $p ? null : $item['idle_vendors'] - (int) $p['idle_vendors'];
			$item['delta_thin']      = null === $p ? null : $item['opps_thin'] - (int) $p['opps_thin'];
			$item['delta_matched']   = null === $p ? null : $item['matched_vendors'] - (int) $p['matched_vendors'];
			$item['org_signal']      = $org[ $item['city_key'] ] ?? null;
			$items[]                 = $item;
		}
		$sort = sanitize_key( (string) ( $f['sort'] ?? 'idle_vendors' ) );
		$sort = in_array( $sort, array_merge( self::COUNT_COLUMNS, array( 'delta_idle', 'delta_thin' ) ), true ) ? $sort : 'idle_vendors';
		usort( $items, static fn( $a, $b ) => ( (int) $b[ $sort ] <=> (int) $a[ $sort ] ) ?: strcmp( $a['label'], $b['label'] ) );
		$limit = max( 1, min( self::MAX_CITIES, (int) ( $f['limit'] ?? 200 ) ) );
		return array(
			'snapshot_date' => $date,
			'compared_to'   => $prev_date,
			'items'         => array_slice( $items, 0, $limit ),
			'total'         => count( $items ),
			'categories'    => self::term_names( $term_ids ),
			'thresholds'    => self::thresholds(),
		);
	}

	/**
	 * One city: categories, thin booths, 90-day trend, open leads.
	 *
	 * @param string $city_key City key.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function city( $city_key ) {
		global $wpdb;
		$city_key = strtolower( trim( (string) $city_key ) );
		$parts    = explode( '|', $city_key );
		$city_key = $parts[0] . '|' . strtoupper( $parts[1] ?? '' );
		$date     = self::latest_date();
		$table    = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = '' === $date ? array() : (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE snapshot_date = %s AND city_key = %s", $date, $city_key ), ARRAY_A );
		if ( ! $rows ) {
			return new WP_Error( 'lnkup_market_city_not_found', __( 'No market data for that city in the latest snapshot.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$trend = (array) $wpdb->get_results( $wpdb->prepare( "SELECT snapshot_date, open_opportunities, opps_thin, matched_vendors, idle_vendors, zero_result_searches_30d FROM {$table} WHERE city_key = %s AND term_id = 0 AND snapshot_date >= %s ORDER BY snapshot_date ASC", $city_key, gmdate( 'Y-m-d', strtotime( $date ) - 90 * DAY_IN_SECONDS ) ), ARRAY_A );
		// phpcs:enable
		$summary    = null;
		$categories = array();
		foreach ( $rows as $row ) {
			$item = self::format( $row );
			if ( 0 === $item['term_id'] ) {
				$summary = $item;
			} else {
				$categories[] = $item;
			}
		}
		$names = self::term_names( array_column( $categories, 'term_id' ) );
		foreach ( $categories as &$c ) {
			$c['category'] = $names[ $c['term_id'] ] ?? ( '#' . $c['term_id'] );
		}
		unset( $c );
		usort( $categories, static fn( $a, $b ) => ( $b['opps_thin'] + $b['idle_vendors'] ) <=> ( $a['opps_thin'] + $a['idle_vendors'] ) );
		return array(
			'snapshot_date' => $date,
			'summary'       => $summary,
			'org_signal'    => self::org_signals()[ $city_key ] ?? null,
			'categories'    => $categories,
			'thin_booths'   => $summary ? self::thin_booths( $summary['city'], $summary['state'] ) : array(),
			'trend'         => array_map(
				static fn( $t ) => array_map( 'intval', array_diff_key( $t, array( 'snapshot_date' => 1 ) ) ) + array( 'date' => (string) $t['snapshot_date'] ),
				$trend
			),
			'open_leads'    => $summary ? self::open_leads( $summary['city'], $summary['state'] ) : 0,
		);
	}

	/**
	 * Open booths in a city with fewer recommended vendors than the threshold (live, max 25).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function thin_booths( $city, $state ) {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		$index = LNKup_Marketplace_Index::table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.opportunity_id, m.event_id, m.organizer_user_id, m.event_date, m.spots_remaining,
				(SELECT COUNT(*) FROM " . LNKup_Match_Tables::results_table() . ' r INNER JOIN ' . LNKup_Match_Tables::vendor_index_table() . " v ON v.profile_id = r.profile_id AND v.status = 'active' WHERE r.opportunity_id = m.opportunity_id AND r.eligible = 1) AS recommended
				FROM {$index} m WHERE LOWER(m.city) = %s AND UPPER(m.state) = %s AND m.spots_remaining > 0 AND (m.deadline IS NULL OR m.deadline >= %s) AND (m.event_date IS NULL OR m.event_date >= %s)
				HAVING recommended < %d ORDER BY recommended ASC, m.event_date ASC LIMIT 25",
				strtolower( trim( (string) $city ) ),
				strtoupper( trim( (string) $state ) ),
				$today,
				$today,
				(int) self::thresholds()['thin_below']
			),
			ARRAY_A
		);
		// phpcs:enable
		return array_map(
			static function ( $r ) {
				$organizer = get_userdata( (int) $r['organizer_user_id'] );
				return array(
					'opportunity_id' => (int) $r['opportunity_id'],
					'title'          => get_the_title( (int) $r['opportunity_id'] ),
					'event_id'       => (int) $r['event_id'],
					'event'          => get_the_title( (int) $r['event_id'] ),
					'event_date'     => (string) $r['event_date'],
					'organizer'      => $organizer ? (string) $organizer->display_name : '',
					'spots_remaining' => (int) $r['spots_remaining'],
					'recommended'    => (int) $r['recommended'],
					'edit_url'       => (string) get_edit_post_link( (int) $r['event_id'], 'raw' ),
				);
			},
			$rows
		);
	}

	/**
	 * Latest term-0 row for a city (for lead scoring, market pages), or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function for_city( $city, $state ) {
		global $wpdb;
		$date = self::latest_date();
		if ( '' === $date || '' === trim( (string) $city ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE snapshot_date = %s AND city_key = %s AND term_id = 0', $date, self::key( $city, $state ) ), ARRAY_A );
		return $row ? self::format( $row ) : null;
	}

	/**
	 * City keys (latest snapshot, term 0) per match signal.
	 *
	 * @return array<string,string> city_key => signal.
	 */
	public static function city_signals() {
		global $wpdb;
		$date = self::latest_date();
		if ( '' === $date ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE snapshot_date = %s AND term_id = 0', $date ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['city_key'] ] = self::signal( array_map( 'intval', array_intersect_key( $row, array_flip( self::COUNT_COLUMNS ) ) ) );
		}
		return $out;
	}

	/**
	 * Organizer Growth supply signal per city key (latest supply snapshot), when available.
	 *
	 * @return array<string,string>
	 */
	public static function org_signals() {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Org_Supply_Intel' ) || ! class_exists( 'LNKup_Org_Growth_Tables' ) || ! LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::SUPPLY ) ) {
			return array();
		}
		$date = LNKup_Org_Supply_Intel::latest_date();
		if ( '' === $date ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Org_Supply_Intel::table() . ' WHERE snapshot_date = %s AND term_id = 0', $date ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['city_key'] ] = LNKup_Org_Supply_Intel::signal( $row );
		}
		return $out;
	}

	private static function open_leads( $city, $state ) {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Org_Leads' ) || ! LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::LEADS ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Org_Leads::table() . " WHERE merged_into_id = 0 AND outcome = '' AND LOWER(city) = %s AND UPPER(state) = %s", strtolower( trim( (string) $city ) ), strtoupper( trim( (string) $state ) ) ) );
	}

	/**
	 * @param int[] $ids Term IDs.
	 * @return array<int,string>
	 */
	public static function term_names( array $ids ) {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$terms = get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'include' => $ids, 'hide_empty' => false ) );
		$out   = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$out[ (int) $t->term_id ] = (string) $t->name;
		}
		return $out;
	}

	/**
	 * CSV of the city table (formula-safe).
	 *
	 * @param array $f Filters.
	 */
	public static function export_csv( array $f = array() ) {
		$handle = fopen( 'php://temp', 'r+' );
		if ( ! $handle ) {
			return '';
		}
		$put  = static function ( array $cells ) use ( $handle ) {
			fputcsv( $handle, array_map( static fn( $v ) => preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : (string) $v, $cells ) );
		};
		$data = self::cities( array_merge( $f, array( 'limit' => self::MAX_CITIES ) ) );
		$put( array_merge( array( 'snapshot_date', 'city', 'state' ), self::COUNT_COLUMNS, array( 'signal', 'organizer_growth_signal', 'delta_idle_7d', 'delta_thin_7d' ) ) );
		foreach ( $data['items'] as $r ) {
			$cells = array( $data['snapshot_date'], $r['city'], $r['state'] );
			foreach ( self::COUNT_COLUMNS as $col ) {
				$cells[] = $r[ $col ];
			}
			$cells[] = $r['signal'];
			$cells[] = (string) $r['org_signal'];
			$cells[] = null === $r['delta_idle'] ? '' : $r['delta_idle'];
			$cells[] = null === $r['delta_thin'] ? '' : $r['delta_thin'];
			$put( $cells );
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );
		return $csv;
	}

	// ------------------------------------------------------------------ City Market Pages.

	/**
	 * Suggested launch status for a market page from the latest signals, or null when there is
	 * nothing to suggest. Suggestions are never applied automatically.
	 *
	 * @param string $city  City.
	 * @param string $state State.
	 * @return array{status:string,reason:string}|null
	 */
	public static function suggest_market_status( $city, $state ) {
		$row  = self::for_city( $city, $state );
		$need = class_exists( 'LNKup_Org_Market_Need' ) ? (string) ( LNKup_Org_Market_Need::cities()[ self::key( $city, $state ) ]['need'] ?? '' ) : '';
		if ( ! $row && '' === $need ) {
			return null;
		}
		$facts = $row ? sprintf(
			/* translators: 1: matched vendors, 2: idle vendors, 3: thin booths, 4: zero-result searches. */
			__( '%1$d matching vendors, %2$d with nothing to apply to, %3$d booths short of vendors, %4$d searches found nothing', 'lnkup-marketplace' ),
			$row['matched_vendors'],
			$row['idle_vendors'],
			$row['opps_thin'],
			$row['zero_result_searches_30d']
		) : __( 'Vendor supply signal from Organizer Growth', 'lnkup-marketplace' );
		if ( 'need_organizers' === $need ) {
			return array( 'status' => 'recruiting_organizers', 'reason' => __( 'Needs organizers', 'lnkup-marketplace' ) . ': ' . $facts );
		}
		if ( 'need_vendors' === $need ) {
			return array( 'status' => 'recruiting_vendors', 'reason' => __( 'Needs vendors', 'lnkup-marketplace' ) . ': ' . $facts );
		}
		if ( $row && 'healthy' === $row['signal'] && $row['open_opportunities'] > 0 ) {
			return array( 'status' => 'active', 'reason' => __( 'Healthy with live booths', 'lnkup-marketplace' ) . ': ' . $facts );
		}
		return null;
	}

	/**
	 * Apply the current suggestion to a market page (explicit admin action, audit logged).
	 *
	 * @param string $slug Market slug.
	 * @return array{from:string,to:string}|WP_Error
	 */
	public static function apply_market_suggestion( $slug ) {
		$slug    = sanitize_title( (string) $slug );
		$markets = LNKup_Market_Pages::get_all_markets();
		if ( '' === $slug || empty( $markets[ $slug ] ) ) {
			return new WP_Error( 'lnkup_market_not_found', __( 'Market not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$market     = $markets[ $slug ];
		$suggestion = self::suggest_market_status( (string) ( $market['city'] ?? '' ), (string) ( $market['state'] ?? '' ) );
		$from       = (string) ( $market['launch_status'] ?? 'prelaunch' );
		if ( ! $suggestion || $suggestion['status'] === $from ) {
			return new WP_Error( 'lnkup_market_no_suggestion', __( 'There is no new status to apply for this market.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$markets[ $slug ]['launch_status'] = $suggestion['status'];
		LNKup_Market_Pages::save_markets( $markets );

		$log   = get_option( self::STATUS_LOG_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'slug'    => $slug,
			'from'    => $from,
			'to'      => $suggestion['status'],
			'reason'  => $suggestion['reason'],
			'user_id' => get_current_user_id(),
			'at'      => current_time( 'mysql', true ),
		);
		update_option( self::STATUS_LOG_OPTION, array_slice( $log, -100 ), false );
		if ( class_exists( 'LNKup_Audit_Log' ) ) {
			LNKup_Audit_Log::log( 'market_status_applied', 'city_market', 0, array( 'prev' => array( 'slug' => $slug, 'launch_status' => $from ), 'new' => array( 'slug' => $slug, 'launch_status' => $suggestion['status'] ), 'reason' => $suggestion['reason'] ) );
		}
		return array( 'from' => $from, 'to' => $suggestion['status'] );
	}

	/**
	 * Recent applied market status changes, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function market_status_log( $limit = 10 ) {
		$log = get_option( self::STATUS_LOG_OPTION, array() );
		return array_slice( array_reverse( is_array( $log ) ? $log : array() ), 0, max( 1, (int) $limit ) );
	}

	/**
	 * Cities without a market page, ranked by matched vendors plus searches that found nothing.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function suggested_new_markets( $limit = 10 ) {
		$existing = array();
		foreach ( LNKup_Market_Pages::get_all_markets() as $m ) {
			$existing[ self::key( (string) ( $m['city'] ?? '' ), (string) ( $m['state'] ?? '' ) ) ] = true;
		}
		$out = array();
		foreach ( self::cities( array( 'limit' => self::MAX_CITIES ) )['items'] as $row ) {
			$score = $row['matched_vendors'] + $row['zero_result_searches_30d'];
			if ( $score <= 0 || '' === $row['state'] || isset( $existing[ $row['city_key'] ] ) ) {
				continue;
			}
			$row['demand'] = $score;
			$out[]         = $row;
		}
		usort( $out, static fn( $a, $b ) => ( $b['demand'] <=> $a['demand'] ) ?: strcmp( $a['label'], $b['label'] ) );
		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}

	// ------------------------------------------------------------------ REST (admin only).

	public static function register_routes() {
		$perm = array( 'LNKup_Match_REST', 'admin_permission' );
		register_rest_route( 'lnkup/v1', '/match/admin/market', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_cities' ), 'permission_callback' => $perm ) );
		register_rest_route( 'lnkup/v1', '/match/admin/market/city', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_city' ), 'permission_callback' => $perm ) );
		register_rest_route( 'lnkup/v1', '/match/admin/market/export', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_export' ), 'permission_callback' => $perm ) );
		register_rest_route( 'lnkup/v1', '/match/admin/market/rebuild', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_rebuild' ), 'permission_callback' => $perm ) );
	}

	public static function rest_cities( WP_REST_Request $request ) {
		return rest_ensure_response( self::cities( $request->get_params() ) );
	}

	public static function rest_city( WP_REST_Request $request ) {
		$data = self::city( (string) $request->get_param( 'key' ) );
		return is_wp_error( $data ) ? $data : rest_ensure_response( $data );
	}

	public static function rest_export( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'filename' => 'lnkup-market-supply-demand-' . current_time( 'Y-m-d' ) . '.csv', 'csv' => self::export_csv( $request->get_params() ) ) );
	}

	public static function rest_rebuild() {
		$last = (int) get_option( self::REBUILD_OPTION, 0 );
		if ( $last && time() - $last < 10 * MINUTE_IN_SECONDS ) {
			return new WP_Error( 'lnkup_market_rebuild_throttled', __( 'The market snapshot was rebuilt in the last 10 minutes. Try again shortly.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		update_option( self::REBUILD_OPTION, time(), false );
		$result = self::build_snapshot();
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
