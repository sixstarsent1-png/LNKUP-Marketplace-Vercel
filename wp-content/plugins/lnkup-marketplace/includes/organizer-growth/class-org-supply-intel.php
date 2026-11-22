<?php
/**
 * Vendor supply intelligence: per-city and per-category vendor supply versus organizer demand.
 *
 * A daily snapshot is built from the vendor match index (supply), the marketplace index of open
 * opportunities (demand), vendors' saved searches (wanted cities) and Organizer Growth leads.
 * Every figure is a count of records; signals are threshold rules shown next to the counts.
 *
 * Definitions:
 * - based:  the vendor's own city/state is the city.
 * - reach:  based, or the city is in cities_served, or the state is in states_served, or the
 *           vendor is within radius_miles of the city centroid (average of known coordinates).
 *           Vendors willing to travel nationally are reported once as a national total.
 * - active: index status "active" and discoverable.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Supply_Intel {

	const LOCK           = 'lnkup_org_supply_build';
	const MAX_CITIES     = 500;
	const MAX_VENDORS    = 20000;
	const KEEP_DAYS      = 120;
	const NATIONAL_KEY   = '_national';
	const REBUILD_OPTION = 'lnkup_org_supply_last_rebuild';
	const SIGNALS        = array( 'recruit_organizers', 'recruit_vendors', 'balanced', 'insufficient_data' );
	const COUNT_COLUMNS  = array( 'vendors_based', 'vendors_reach', 'vendors_active', 'vendors_verified', 'vendors_applied_90d', 'vendors_paid_12m', 'saved_search_vendors', 'open_opportunities', 'spots_total', 'spots_remaining', 'applications_90d', 'live_organizers', 'open_leads', 'registered_orgs' );

	public static function init(): void {
		// Before lead rescoring (priority 50) so scores use today's supply.
		add_action( 'lnkup_marketplace_daily', array( __CLASS__, 'build_snapshot' ), 45 );
	}

	public static function table(): string {
		return LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::SUPPLY );
	}

	public static function key( string $city, string $state ): string {
		return strtolower( trim( $city ) ) . '|' . strtoupper( trim( $state ) );
	}

	/**
	 * @return array{min_active:int,ratio:int}
	 */
	public static function thresholds(): array {
		$min   = (int) LNKup_Org_Growth::setting( 'org_growth_supply_min_active_vendors' );
		$ratio = (int) LNKup_Org_Growth::setting( 'org_growth_supply_surplus_ratio' );
		return array(
			'min_active' => $min > 0 ? $min : 10,
			'ratio'      => $ratio > 0 ? $ratio : 3,
		);
	}

	/**
	 * Recruiting signal for one snapshot row.
	 *
	 * @param array<string, mixed> $row Snapshot row.
	 */
	public static function signal( array $row ): string {
		$t      = self::thresholds();
		$active = (int) $row['vendors_active'];
		$open   = (int) $row['spots_remaining'];
		if ( $active < 3 && 0 === $open ) {
			return 'insufficient_data';
		}
		if ( $active >= $t['min_active'] && $active / max( 1, $open ) >= $t['ratio'] ) {
			return 'recruit_organizers';
		}
		if ( $open > 0 && $open >= 2 * $active ) {
			return 'recruit_vendors';
		}
		return 'balanced';
	}

	// ------------------------------------------------------------------ Build.

	/**
	 * Rebuild today's snapshot (idempotent: today's rows are replaced).
	 *
	 * @return array{cities:int,rows:int,snapshot_date:string}|WP_Error
	 */
	public static function build_snapshot() {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Match_Tables' ) || ! class_exists( 'LNKup_Marketplace_Index' ) || ! LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::SUPPLY ) ) {
			return new WP_Error( 'lnkup_supply_unavailable', __( 'Vendor supply data is not available on this site yet.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK ) ) ) {
			return new WP_Error( 'lnkup_supply_busy', __( 'A supply snapshot is already being built. Try again in a minute.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		try {
			$result = self::build_rows();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
		}
		LNKup_Org_Growth_Metrics::flush_cache();
		return $result;
	}

	/**
	 * @return array{cities:int,rows:int,snapshot_date:string}
	 */
	private static function build_rows(): array {
		global $wpdb;
		$date    = current_time( 'Y-m-d' );
		$today   = $date;
		$vendors = self::load_vendors();
		$opps    = self::load_opportunities( $today );

		// Indexes over vendors.
		$based     = array();
		$by_city   = array();
		$by_state  = array();
		$radius    = array();
		$national  = array();
		$coords    = array();
		foreach ( $vendors as $pid => $v ) {
			$k = self::key( $v['city'], $v['state'] );
			if ( '' !== trim( $v['city'] ) ) {
				$based[ $k ][ $pid ] = true;
				if ( null !== $v['lat'] ) {
					$coords[ $k ][] = array( $v['lat'], $v['lng'] );
				}
			}
			foreach ( $v['cities_served'] as $served ) {
				$by_city[ $served ][ $pid ] = true;
			}
			foreach ( $v['states_served'] as $st ) {
				$by_state[ $st ][ $pid ] = true;
			}
			if ( $v['radius'] > 0 && null !== $v['lat'] ) {
				$radius[ $pid ] = true;
			}
			if ( $v['national'] ) {
				$national[ $pid ] = true;
			}
		}

		// Demand per city.
		$demand = array();
		foreach ( $opps as $o ) {
			$k = self::key( $o['city'], $o['state'] );
			if ( '' === trim( $o['city'] ) ) {
				continue;
			}
			$demand[ $k ][] = $o;
			if ( null !== $o['lat'] ) {
				$coords[ $k ][] = array( $o['lat'], $o['lng'] );
			}
		}

		$leads = self::lead_counts();
		$saved = self::saved_search_demand();

		// City set, ranked by local vendors + open opportunities.
		$names = array();
		foreach ( $vendors as $v ) {
			if ( '' !== trim( $v['city'] ) ) {
				$names[ self::key( $v['city'], $v['state'] ) ] = array( trim( $v['city'] ), strtoupper( trim( $v['state'] ) ) );
			}
		}
		foreach ( $opps as $o ) {
			if ( '' !== trim( $o['city'] ) ) {
				$names[ self::key( $o['city'], $o['state'] ) ] = array( trim( $o['city'] ), strtoupper( trim( $o['state'] ) ) );
			}
		}
		foreach ( $leads['names'] as $k => $pair ) {
			$names[ $k ] = $names[ $k ] ?? $pair;
		}
		$rank = array();
		foreach ( array_keys( $names ) as $k ) {
			$rank[ $k ] = count( $based[ $k ] ?? array() ) + count( $demand[ $k ] ?? array() ) + (int) ( $leads['open'][ $k ] ?? 0 );
		}
		arsort( $rank );
		$cities = array_slice( array_keys( $rank ), 0, self::MAX_CITIES );

		// Saved searches without a state count toward every listed city of that name.
		foreach ( array_keys( $saved ) as $sk ) {
			if ( '|' !== substr( $sk, -1 ) ) {
				continue;
			}
			foreach ( $cities as $k ) {
				if ( 0 !== strpos( $k, $sk ) ) {
					continue;
				}
				foreach ( $saved[ $sk ] as $term => $users ) {
					$saved[ $k ][ $term ] = ( $saved[ $k ][ $term ] ?? array() ) + $users;
				}
			}
		}

		$applied = self::vendor_user_set( 'lnkup_application', 90 );
		$paid    = self::paid_vendor_user_set();
		$apps90  = self::applications_by_opportunity( 90 );

		$rows = array();
		foreach ( $cities as $k ) {
			list( $city, $state ) = $names[ $k ];
			$reach = ( $based[ $k ] ?? array() ) + ( $by_city[ strtolower( $city ) . '|' . $state ] ?? array() ) + ( $by_state[ $state ] ?? array() );
			$center = self::centroid( $coords[ $k ] ?? array() );
			if ( $center ) {
				foreach ( array_keys( $radius ) as $pid ) {
					if ( ! isset( $reach[ $pid ] ) && self::miles( $center, array( $vendors[ $pid ]['lat'], $vendors[ $pid ]['lng'] ) ) <= $vendors[ $pid ]['radius'] ) {
						$reach[ $pid ] = true;
					}
				}
			}

			$cells = array( 0 => self::blank() );
			foreach ( array_keys( $reach ) as $pid ) {
				$v     = $vendors[ $pid ];
				$terms = array_merge( array( 0 ), $v['terms'] );
				foreach ( $terms as $term ) {
					$cells[ $term ] = $cells[ $term ] ?? self::blank();
					$c              = &$cells[ $term ];
					++$c['vendors_reach'];
					$c['vendors_based'] += isset( $based[ $k ][ $pid ] ) ? 1 : 0;
					if ( $v['active'] ) {
						++$c['vendors_active'];
						$c['vendors_verified']    += $v['verified'] ? 1 : 0;
						$c['vendors_applied_90d'] += isset( $applied[ $v['user_id'] ] ) ? 1 : 0;
						$c['vendors_paid_12m']    += isset( $paid[ $v['user_id'] ] ) ? 1 : 0;
					}
					unset( $c );
				}
			}
			$organizers = array();
			foreach ( $demand[ $k ] ?? array() as $o ) {
				foreach ( array_merge( array( 0 ), $o['terms'] ) as $term ) {
					$cells[ $term ] = $cells[ $term ] ?? self::blank();
					$c              = &$cells[ $term ];
					++$c['open_opportunities'];
					$c['spots_total']      += $o['spots_total'];
					$c['spots_remaining']  += $o['spots_remaining'];
					$c['applications_90d'] += (int) ( $apps90[ $o['id'] ] ?? 0 );
					$organizers[ $term ][ $o['organizer'] ] = true;
					unset( $c );
				}
			}
			foreach ( $organizers as $term => $set ) {
				$cells[ $term ]['live_organizers'] = count( $set );
			}
			foreach ( $saved[ $k ] ?? array() as $term => $users ) {
				$cells[ $term ]                         = $cells[ $term ] ?? self::blank();
				$cells[ $term ]['saved_search_vendors'] = count( $users );
			}
			$cells[0]['open_leads']      = (int) ( $leads['open'][ $k ] ?? 0 );
			$cells[0]['registered_orgs'] = (int) ( $leads['registered'][ $k ] ?? 0 );

			foreach ( $cells as $term => $cell ) {
				if ( 0 !== $term && 0 === $cell['vendors_reach'] && 0 === $cell['open_opportunities'] && 0 === $cell['saved_search_vendors'] ) {
					continue;
				}
				$rows[] = array_merge( array( 'city_key' => $k, 'city' => $city, 'state' => $state, 'term_id' => (int) $term ), $cell );
			}
		}

		$nat = self::blank();
		foreach ( array_keys( $national ) as $pid ) {
			++$nat['vendors_reach'];
			if ( $vendors[ $pid ]['active'] ) {
				++$nat['vendors_active'];
				$nat['vendors_verified'] += $vendors[ $pid ]['verified'] ? 1 : 0;
			}
		}
		$rows[] = array_merge( array( 'city_key' => self::NATIONAL_KEY, 'city' => '', 'state' => '', 'term_id' => 0 ), $nat );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table = self::table();
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

		return array(
			'cities'        => count( $cities ),
			'rows'          => count( $rows ),
			'snapshot_date' => $date,
		);
	}

	/**
	 * @return array<string, int>
	 */
	private static function blank(): array {
		return array_fill_keys( self::COUNT_COLUMNS, 0 );
	}

	/**
	 * @return array<int, array<string, mixed>> profile_id => vendor.
	 */
	private static function load_vendors(): array {
		global $wpdb;
		$index = LNKup_Match_Tables::vendor_index_table();
		$cats  = LNKup_Match_Tables::vendor_categories_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT profile_id, user_id, status, discoverable, verified, city, state, lat, lng, radius_miles, cities_served, states_served, travel_national FROM {$index} LIMIT " . self::MAX_VENDORS, ARRAY_A );
		$term_rows = (array) $wpdb->get_results( "SELECT profile_id, term_id FROM {$cats}", ARRAY_A );
		// phpcs:enable
		$terms = array();
		foreach ( $term_rows as $t ) {
			$terms[ (int) $t['profile_id'] ][] = (int) $t['term_id'];
		}
		$out = array();
		foreach ( $rows as $r ) {
			$pid   = (int) $r['profile_id'];
			$state = strtoupper( trim( (string) $r['state'] ) );
			$out[ $pid ] = array(
				'user_id'       => (int) $r['user_id'],
				'active'        => 'active' === $r['status'] && (int) $r['discoverable'] === 1,
				'verified'      => (int) $r['verified'] === 1,
				'city'          => (string) $r['city'],
				'state'         => $state,
				'lat'           => is_numeric( $r['lat'] ) ? (float) $r['lat'] : null,
				'lng'           => is_numeric( $r['lng'] ) ? (float) $r['lng'] : null,
				'radius'        => (int) $r['radius_miles'],
				'national'      => (int) $r['travel_national'] === 1,
				'cities_served' => self::served_city_keys( (string) $r['cities_served'], $state ),
				'states_served' => array_values( array_filter( array_map( static fn( $s ) => strtoupper( trim( (string) $s ) ), (array) json_decode( (string) $r['states_served'], true ) ) ) ),
				'terms'         => array_values( array_unique( $terms[ $pid ] ?? array() ) ),
			);
		}
		return $out;
	}

	/**
	 * "Charlotte, NC" or "Charlotte" (assumed in the vendor's own state) → city keys.
	 *
	 * @return string[]
	 */
	public static function served_city_keys( string $json, string $home_state ): array {
		$out = array();
		foreach ( (array) json_decode( $json, true ) as $entry ) {
			$parts = array_map( 'trim', explode( ',', (string) $entry ) );
			if ( '' === $parts[0] ) {
				continue;
			}
			$state = isset( $parts[1] ) && '' !== $parts[1] ? strtoupper( substr( $parts[1], 0, 2 ) ) : $home_state;
			$out[] = self::key( $parts[0], $state );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Open opportunities (marketplace index) with category term IDs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function load_opportunities( string $today ): array {
		global $wpdb;
		$index = LNKup_Marketplace_Index::table_name();
		$cats  = LNKup_Match_Tables::opportunity_categories_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT opportunity_id, organizer_user_id, city, state, spots_total, spots_remaining, lat, lng FROM {$index} WHERE (event_date IS NULL OR event_date >= %s) LIMIT 20000", $today ), ARRAY_A );
		$term_rows = (array) $wpdb->get_results( "SELECT opportunity_id, term_id FROM {$cats}", ARRAY_A );
		// phpcs:enable
		$terms = array();
		foreach ( $term_rows as $t ) {
			$terms[ (int) $t['opportunity_id'] ][] = (int) $t['term_id'];
		}
		$out = array();
		foreach ( $rows as $r ) {
			$id    = (int) $r['opportunity_id'];
			$out[] = array(
				'id'              => $id,
				'organizer'       => (int) $r['organizer_user_id'],
				'city'            => (string) $r['city'],
				'state'           => strtoupper( trim( (string) $r['state'] ) ),
				'spots_total'     => max( 0, (int) $r['spots_total'] ),
				'spots_remaining' => max( 0, (int) $r['spots_remaining'] ),
				'lat'             => is_numeric( $r['lat'] ) ? (float) $r['lat'] : null,
				'lng'             => is_numeric( $r['lng'] ) ? (float) $r['lng'] : null,
				'terms'           => array_values( array_unique( $terms[ $id ] ?? array() ) ),
			);
		}
		return $out;
	}

	/**
	 * Open (unmerged, no outcome) and registered leads per city key.
	 *
	 * @return array{open:array<string,int>,registered:array<string,int>,names:array<string,array{0:string,1:string}>}
	 */
	private static function lead_counts(): array {
		global $wpdb;
		$leads = LNKup_Org_Leads::table();
		$ms    = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::MILESTONES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT l.city, l.state, SUM(l.outcome = '') AS open_leads, SUM(EXISTS (SELECT 1 FROM {$ms} m WHERE m.lead_id = l.id AND m.milestone = 'registered' AND m.status = 'active')) AS registered FROM {$leads} l WHERE l.merged_into_id = 0 AND l.city <> '' GROUP BY l.city, l.state LIMIT 5000", ARRAY_A );
		$out  = array( 'open' => array(), 'registered' => array(), 'names' => array() );
		foreach ( $rows as $r ) {
			$k                        = self::key( (string) $r['city'], (string) $r['state'] );
			$out['open'][ $k ]        = ( $out['open'][ $k ] ?? 0 ) + (int) $r['open_leads'];
			$out['registered'][ $k ]  = ( $out['registered'][ $k ] ?? 0 ) + (int) $r['registered'];
			$out['names'][ $k ]       = array( trim( (string) $r['city'] ), strtoupper( trim( (string) $r['state'] ) ) );
		}
		return $out;
	}

	/**
	 * Vendors with an active saved search naming a city: city key => term (0 = any) => user set.
	 *
	 * @return array<string, array<int, array<int, bool>>>
	 */
	private static function saved_search_demand(): array {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Saved_Searches' ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 10000", LNKup_Saved_Searches::META_KEY ), ARRAY_A );
		$lookup = self::term_lookup();
		$out    = array();
		foreach ( $rows as $r ) {
			$user = (int) $r['user_id'];
			foreach ( (array) maybe_unserialize( $r['meta_value'] ) as $search ) {
				if ( ! is_array( $search ) || 'active' !== ( $search['status'] ?? 'active' ) ) {
					continue;
				}
				$f    = (array) ( $search['filters'] ?? array() );
				$city = trim( (string) ( $f['city'] ?? '' ) );
				if ( '' === $city ) {
					continue;
				}
				$k                  = self::key( $city, (string) ( $f['state'] ?? '' ) );
				$out[ $k ][0][ $user ] = true;
				foreach ( (array) ( $f['categories'] ?? array() ) as $cat ) {
					$term = $lookup[ strtolower( trim( (string) $cat ) ) ] ?? 0;
					if ( $term ) {
						$out[ $k ][ $term ][ $user ] = true;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Vendor category name/slug/ID (lowercase) => term ID.
	 *
	 * @return array<string, int>
	 */
	private static function term_lookup(): array {
		$terms = get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false ) );
		$out   = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$out[ strtolower( $t->name ) ]  = (int) $t->term_id;
			$out[ strtolower( $t->slug ) ]  = (int) $t->term_id;
			$out[ (string) $t->term_id ]    = (int) $t->term_id;
		}
		return $out;
	}

	/**
	 * Vendor user IDs with a post of this type in the last N days.
	 *
	 * @return array<int, bool>
	 */
	private static function vendor_user_set( string $post_type, int $days ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT v.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_lnkup_vendor_user_id' WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft') AND p.post_date_gmt >= %s", $post_type, gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		return array_fill_keys( array_map( 'intval', $ids ), true );
	}

	/**
	 * Vendor user IDs with a paid booking in the last 12 months.
	 *
	 * @return array<int, bool>
	 */
	private static function paid_vendor_user_set(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT v.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_lnkup_vendor_user_id' INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_payment_status' AND s.meta_value IN ('paid','partially_refunded') WHERE p.post_type = 'lnkup_payment' AND p.post_date_gmt >= %s", gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS ) ) );
		return array_fill_keys( array_map( 'intval', $ids ), true );
	}

	/**
	 * Applications submitted in the last N days, per opportunity.
	 *
	 * @return array<int, int>
	 */
	private static function applications_by_opportunity( int $days ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT o.meta_value AS opp, COUNT(*) AS n FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_opportunity_id' WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft') AND p.post_date_gmt >= %s GROUP BY o.meta_value", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['opp'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * @param array<int, array{0:float,1:float}> $points Coordinates.
	 * @return array{0:float,1:float}|null
	 */
	private static function centroid( array $points ): ?array {
		if ( ! $points ) {
			return null;
		}
		$lat = array_sum( array_column( $points, 0 ) ) / count( $points );
		$lng = array_sum( array_column( $points, 1 ) ) / count( $points );
		return array( $lat, $lng );
	}

	/**
	 * Great-circle distance in miles.
	 *
	 * @param array{0:float,1:float} $a Point.
	 * @param array{0:float,1:float} $b Point.
	 */
	public static function miles( array $a, array $b ): float {
		$r    = 3958.8;
		$dlat = deg2rad( $b[0] - $a[0] );
		$dlng = deg2rad( $b[1] - $a[1] );
		$h    = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $a[0] ) ) * cos( deg2rad( $b[0] ) ) * sin( $dlng / 2 ) ** 2;
		return 2 * $r * asin( min( 1.0, sqrt( $h ) ) );
	}

	// ------------------------------------------------------------------ Read.

	public static function latest_date(): string {
		global $wpdb;
		if ( ! LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::SUPPLY ) ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var( 'SELECT MAX(snapshot_date) FROM ' . self::table() );
	}

	/**
	 * Formatted snapshot row with its signal.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function format( array $row ): array {
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
		$out['signal'] = self::signal( $out );
		return $out;
	}

	/**
	 * Cities in the latest snapshot.
	 *
	 * @param array<string, mixed> $f state, term_id, signal, min_active, sort, limit.
	 * @return array<string, mixed>
	 */
	public static function cities( array $f = array() ): array {
		global $wpdb;
		$date = self::latest_date();
		$t    = self::thresholds();
		if ( '' === $date ) {
			return array( 'snapshot_date' => '', 'items' => array(), 'national' => null, 'categories' => array(), 'thresholds' => $t );
		}
		$table  = self::table();
		$term   = absint( $f['term_id'] ?? 0 );
		$where  = array( 'snapshot_date = %s', 'term_id = %d', 'city_key <> %s' );
		$values = array( $date, $term, self::NATIONAL_KEY );
		$state  = strtoupper( sanitize_text_field( (string) ( $f['state'] ?? '' ) ) );
		if ( '' !== $state ) {
			$where[]  = 'state = %s';
			$values[] = $state;
		}
		$min = absint( $f['min_active'] ?? 0 );
		if ( $min ) {
			$where[]  = 'vendors_active >= %d';
			$values[] = $min;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' LIMIT ' . self::MAX_CITIES, $values ), ARRAY_A );
		$prev_date = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(snapshot_date) FROM {$table} WHERE snapshot_date <= %s", gmdate( 'Y-m-d', strtotime( $date ) - 7 * DAY_IN_SECONDS ) ) );
		$prev      = array();
		if ( '' !== $prev_date ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT city_key, vendors_active, spots_remaining FROM {$table} WHERE snapshot_date = %s AND term_id = %d", $prev_date, $term ), ARRAY_A ) as $p ) {
				$prev[ (string) $p['city_key'] ] = $p;
			}
		}
		$national  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE snapshot_date = %s AND city_key = %s AND term_id = 0", $date, self::NATIONAL_KEY ), ARRAY_A );
		$term_ids  = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT term_id FROM {$table} WHERE snapshot_date = %s AND term_id > 0", $date ) ) );
		// phpcs:enable
		$signal = sanitize_key( (string) ( $f['signal'] ?? '' ) );
		$items  = array();
		foreach ( $rows as $row ) {
			$item = self::format( $row );
			if ( '' !== $signal && $item['signal'] !== $signal ) {
				continue;
			}
			$p                       = $prev[ $item['city_key'] ] ?? null;
			$item['delta_active']    = null === $p ? null : $item['vendors_active'] - (int) $p['vendors_active'];
			$item['delta_remaining'] = null === $p ? null : $item['spots_remaining'] - (int) $p['spots_remaining'];
			$items[]                 = $item;
		}
		$sort = sanitize_key( (string) ( $f['sort'] ?? 'vendors_active' ) );
		$sort = in_array( $sort, array_merge( self::COUNT_COLUMNS, array( 'delta_active' ) ), true ) ? $sort : 'vendors_active';
		usort( $items, static fn( $a, $b ) => ( (int) $b[ $sort ] <=> (int) $a[ $sort ] ) ?: strcmp( $a['label'], $b['label'] ) );
		$limit = max( 1, min( self::MAX_CITIES, (int) ( $f['limit'] ?? 200 ) ) );
		return array(
			'snapshot_date' => $date,
			'compared_to'   => $prev_date,
			'items'         => array_slice( $items, 0, $limit ),
			'total'         => count( $items ),
			'national'      => $national ? array( 'vendors_reach' => (int) $national['vendors_reach'], 'vendors_active' => (int) $national['vendors_active'] ) : null,
			'categories'    => self::term_names( $term_ids ),
			'thresholds'    => $t,
		);
	}

	/**
	 * One city: category breakdown, 90-day trend, gaps and open leads.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function city( string $city_key ) {
		global $wpdb;
		$city_key = strtolower( trim( $city_key ) );
		$date     = self::latest_date();
		$table    = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = '' === $date ? array() : (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE snapshot_date = %s AND city_key = %s", $date, $city_key ), ARRAY_A );
		if ( ! $rows ) {
			return new WP_Error( 'lnkup_supply_city_not_found', __( 'No supply data for that city in the latest snapshot.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$trend = (array) $wpdb->get_results( $wpdb->prepare( "SELECT snapshot_date, vendors_active, vendors_reach, open_opportunities, spots_remaining FROM {$table} WHERE city_key = %s AND term_id = 0 AND snapshot_date >= %s ORDER BY snapshot_date ASC", $city_key, gmdate( 'Y-m-d', strtotime( $date ) - 90 * DAY_IN_SECONDS ) ), ARRAY_A );
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
		usort( $categories, static fn( $a, $b ) => $b['vendors_active'] <=> $a['vendors_active'] );
		$gaps = array_values( array_filter( $categories, static fn( $c ) => $c['vendors_active'] > 0 && 0 === $c['open_opportunities'] ) );
		return array(
			'snapshot_date' => $date,
			'summary'       => $summary,
			'categories'    => $categories,
			'gaps'          => array_map( static fn( $c ) => array( 'term_id' => $c['term_id'], 'category' => $c['category'], 'vendors_active' => $c['vendors_active'] ), $gaps ),
			'trend'         => array_map(
				static fn( $t ) => array(
					'date'               => (string) $t['snapshot_date'],
					'vendors_active'     => (int) $t['vendors_active'],
					'vendors_reach'      => (int) $t['vendors_reach'],
					'open_opportunities' => (int) $t['open_opportunities'],
					'spots_remaining'    => (int) $t['spots_remaining'],
				),
				$trend
			),
			'leads'         => $summary ? self::city_leads( $summary['city'], $summary['state'] ) : array(),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function city_leads( string $city, string $state ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, stage, tier, score FROM ' . LNKup_Org_Leads::table() . " WHERE merged_into_id = 0 AND outcome = '' AND LOWER(city) = %s AND UPPER(state) = %s ORDER BY score DESC LIMIT 25", strtolower( $city ), strtoupper( $state ) ), ARRAY_A );
		return array_map(
			static fn( $r ) => array(
				'id'    => (int) $r['id'],
				'name'  => (string) $r['name'],
				'stage' => (string) $r['stage'],
				'tier'  => (string) $r['tier'],
				'score' => (int) $r['score'],
			),
			$rows
		);
	}

	/**
	 * @param int[] $ids Term IDs.
	 * @return array<int, string>
	 */
	public static function term_names( array $ids ): array {
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
	 * Supply summary for a lead's city (lead drawer).
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return array<string, mixed>|null
	 */
	public static function for_lead( array $lead ): ?array {
		$city = trim( (string) ( $lead['city'] ?? '' ) );
		if ( '' === $city ) {
			return null;
		}
		$data = self::city( self::key( $city, (string) ( $lead['state'] ?? '' ) ) );
		if ( is_wp_error( $data ) ) {
			return array( 'available' => false, 'vendors_active' => self::vendor_market_count( $city, (string) ( $lead['state'] ?? '' ) ) );
		}
		$wanted  = array_map( 'strtolower', LNKup_Org_Lead_Scoring::split_list( (string) ( $lead['event_categories'] ?? '' ) ) );
		$overlap = array_values( array_filter( $data['categories'], static fn( $c ) => $c['vendors_active'] > 0 && in_array( strtolower( $c['category'] ), $wanted, true ) ) );
		return array(
			'available'      => true,
			'city_key'       => $data['summary']['city_key'],
			'snapshot_date'  => $data['snapshot_date'],
			'vendors_active' => $data['summary']['vendors_active'],
			'vendors_reach'  => $data['summary']['vendors_reach'],
			'open_slots'     => $data['summary']['spots_remaining'],
			'signal'         => $data['summary']['signal'],
			'top_categories' => array_map( static fn( $c ) => array( 'category' => $c['category'], 'vendors_active' => $c['vendors_active'] ), array_slice( $data['categories'], 0, 5 ) ),
			'matching'       => array_map( static fn( $c ) => array( 'category' => $c['category'], 'vendors_active' => $c['vendors_active'] ), $overlap ),
		);
	}

	/**
	 * Active vendors who serve a city (latest snapshot; live home-city count when not yet snapshotted).
	 */
	public static function vendor_market_count( string $city, string $state ): int {
		global $wpdb;
		$city = trim( $city );
		if ( '' === $city ) {
			return 0;
		}
		$date = self::latest_date();
		if ( '' !== $date ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT vendors_active FROM ' . self::table() . ' WHERE snapshot_date = %s AND city_key = %s AND term_id = 0', $date, self::key( $city, $state ) ) );
			if ( null !== $count ) {
				return (int) $count;
			}
		}
		if ( ! class_exists( 'LNKup_Match_Tables' ) ) {
			return 0;
		}
		$sql    = 'SELECT COUNT(*) FROM ' . LNKup_Match_Tables::vendor_index_table() . " WHERE status = 'active' AND discoverable = 1 AND LOWER(city) = %s";
		$values = array( strtolower( $city ) );
		if ( '' !== trim( $state ) ) {
			$sql     .= ' AND UPPER(state) = %s';
			$values[] = strtoupper( trim( $state ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Aggregates for a connector's territories (cities and states only). Small counts are masked.
	 *
	 * @param array<int, array{scope_type:string,value:string}> $territories Territories.
	 * @return array<string, mixed>
	 */
	public static function for_territories( array $territories, string $home_city = '', string $home_state = '' ): array {
		$cities = array();
		$states = array();
		foreach ( $territories as $t ) {
			$value = trim( (string) $t['value'] );
			if ( 'state' === $t['scope_type'] ) {
				$states[] = strtoupper( substr( $value, 0, 2 ) );
			} elseif ( 'city' === $t['scope_type'] ) {
				$parts    = array_map( 'trim', explode( ',', $value ) );
				$cities[] = array( strtolower( $parts[0] ), isset( $parts[1] ) ? strtoupper( substr( $parts[1], 0, 2 ) ) : '' );
			}
		}
		if ( ! $territories && '' !== trim( $home_city ) ) {
			$cities[] = array( strtolower( trim( $home_city ) ), strtoupper( trim( $home_state ) ) );
		}
		$data  = self::cities( array( 'limit' => self::MAX_CITIES ) );
		$items = array();
		foreach ( $data['items'] as $row ) {
			$match = in_array( $row['state'], $states, true );
			foreach ( $cities as $c ) {
				$match = $match || ( strtolower( $row['city'] ) === $c[0] && ( '' === $c[1] || $c[1] === $row['state'] ) );
			}
			if ( ! $match ) {
				continue;
			}
			$items[] = array(
				'label'           => $row['label'],
				'vendors_active'  => self::mask( $row['vendors_active'] ),
				'vendors_reach'   => self::mask( $row['vendors_reach'] ),
				'open_slots'      => $row['spots_remaining'],
				'live_organizers' => $row['live_organizers'],
				'signal'          => $row['signal'],
			);
		}
		return array(
			'snapshot_date' => $data['snapshot_date'],
			'items'         => array_slice( $items, 0, 50 ),
		);
	}

	/**
	 * @return int|string
	 */
	public static function mask( int $n ) {
		return $n > 0 && $n < 3 ? '<3' : $n;
	}

	public static function export_csv( array $f = array() ): string {
		$handle = fopen( 'php://temp', 'r+' );
		if ( ! $handle ) {
			return '';
		}
		$put = static function ( array $cells ) use ( $handle ): void {
			fputcsv( $handle, array_map( static fn( $v ) => preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : (string) $v, $cells ) );
		};
		$data = self::cities( array_merge( $f, array( 'limit' => self::MAX_CITIES ) ) );
		$put( array_merge( array( 'snapshot_date', 'city', 'state' ), self::COUNT_COLUMNS, array( 'signal', 'delta_active_7d' ) ) );
		foreach ( $data['items'] as $r ) {
			$cells = array( $data['snapshot_date'], $r['city'], $r['state'] );
			foreach ( self::COUNT_COLUMNS as $col ) {
				$cells[] = $r[ $col ];
			}
			$cells[] = $r['signal'];
			$cells[] = null === $r['delta_active'] ? '' : $r['delta_active'];
			$put( $cells );
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );
		return $csv;
	}
}
