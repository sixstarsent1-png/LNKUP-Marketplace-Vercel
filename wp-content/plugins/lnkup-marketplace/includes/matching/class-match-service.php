<?php
/**
 * Matching service: builds engine inputs from canonical data, computes and
 * caches results, and serves vendor/organizer reads with live revalidation.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Match_Service {

	const VENDOR_CANDIDATE_LIMIT = 3000;
	const OPP_CANDIDATE_LIMIT    = 5000;
	const CHUNK                  = 400;
	const COMPUTED_META          = '_lnkup_match_computed_at';

	/* ---------------------------------------------------------------------
	 * Input builders
	 * ------------------------------------------------------------------- */

	/**
	 * Engine inputs for vendor index rows, loaded in bulk (no per-vendor queries).
	 *
	 * @param array $rows Vendor index rows (ARRAY_A).
	 * @return array<int,array> Keyed by profile_id.
	 */
	public static function vendor_inputs( array $rows ) {
		if ( ! $rows ) {
			return array();
		}
		$profile_ids = array_map( 'intval', wp_list_pluck( $rows, 'profile_id' ) );
		$user_ids    = array_values( array_unique( array_filter( array_map( 'intval', wp_list_pluck( $rows, 'user_id' ) ) ) ) );

		update_meta_cache( 'post', $profile_ids );
		if ( $user_ids ) {
			update_meta_cache( 'user', $user_ids );
		}

		$categories = self::categories_for( LNKup_Match_Tables::vendor_categories_table(), 'profile_id', $profile_ids );
		$apps       = self::applications_for_vendors( $user_ids );
		$invites    = self::invites_for_vendors( $user_ids );
		$docs       = self::approved_documents_for_vendors( $user_ids );
		$feedback   = class_exists( 'LNKup_Passport_Settings' ) && LNKup_Passport_Settings::enabled( 'matching_reputation_signals' )
			? self::feedback_for_profiles( array_map( 'intval', wp_list_pluck( $rows, 'profile_id' ) ) )
			: array();

		$out = array();
		foreach ( $rows as $row ) {
			$profile_id = (int) $row['profile_id'];
			$user_id    = (int) $row['user_id'];
			$favorites  = get_user_meta( $user_id, 'lnkup_favorites', true );
			$favorites  = is_array( $favorites ) ? $favorites : array();
			$app        = $apps[ $user_id ] ?? array();
			$inv        = $invites[ $user_id ] ?? array();
			$setup      = strtolower( (string) get_post_meta( $profile_id, '_lnkup_setup_type', true ) );

			$out[ $profile_id ] = array(
				'profile_id'             => $profile_id,
				'user_id'                => $user_id,
				'active'                 => 'active' === $row['status'],
				'discoverable'           => (bool) (int) $row['discoverable'],
				'accept_invites'         => (bool) (int) $row['accept_invites'],
				'verified'               => (bool) (int) $row['verified'],
				'categories'             => $categories[ $profile_id ] ?? array(),
				'primary_category'       => (int) $row['primary_category'],
				'city'                   => (string) $row['city'],
				'state'                  => (string) $row['state'],
				'lat'                    => null === $row['lat'] ? null : (float) $row['lat'],
				'lng'                    => null === $row['lng'] ? null : (float) $row['lng'],
				'radius_miles'           => (int) $row['radius_miles'],
				'travel_national'        => (bool) (int) $row['travel_national'],
				'cities_served'          => self::json_list( $row['cities_served'] ),
				'states_served'          => self::json_list( $row['states_served'] ),
				'event_types'            => self::json_list( $row['event_types'] ),
				'fee_max'                => null === $row['fee_max'] ? null : (float) $row['fee_max'],
				'env_pref'               => (string) $row['env_pref'],
				'available_months'       => array_filter( array_map( 'intval', explode( ',', (string) $row['available_months'] ) ) ),
				'blackout_dates'         => LNKup_Match_Vendor_Index::list_meta( $profile_id, '_lnkup_blackout_dates' ),
				'booked_dates'           => $app['booked_dates'] ?? array(),
				'power_needed'           => self::truthy( get_post_meta( $profile_id, '_lnkup_power_needed', true ) ),
				'water_needed'           => self::truthy( get_post_meta( $profile_id, '_lnkup_water_needed', true ) ),
				'food_truck'             => false !== strpos( $setup, 'truck' ) || '' !== (string) get_post_meta( $profile_id, '_lnkup_food_truck_size', true ),
				'approved_documents'     => $docs[ $user_id ] ?? array(),
				'past_organizers'        => $app['past_organizers'] ?? array(),
				'favorite_organizers'    => array_map( 'intval', (array) ( $favorites['organizers'] ?? array() ) ),
				'favorite_opportunities' => array_map( 'intval', (array) ( $favorites['opportunities'] ?? array() ) ),
				'applied_opportunities'  => $app['applied'] ?? array(),
				'invited_opportunities'  => $inv['invited'] ?? array(),
				'related_organizers'     => array_values( array_unique( array_merge( $app['organizers'] ?? array(), $inv['accepted_organizers'] ?? array() ) ) ),
				'completeness'           => (int) $row['profile_completeness'],
				'organizer_feedback'     => $feedback[ $profile_id ] ?? null,
			);
		}
		return $out;
	}

	/**
	 * Published-review averages per profile (organizer-averaged), only where the display minimum is met.
	 *
	 * @param int[] $profile_ids Profile IDs.
	 * @return array<int,float>
	 */
	private static function feedback_for_profiles( array $profile_ids ) {
		$profile_ids = array_values( array_filter( array_map( 'intval', $profile_ids ) ) );
		if ( ! $profile_ids || ! LNKup_Passport_Tables::exists( LNKup_Passport_Tables::review_index_table() ) ) {
			return array();
		}
		global $wpdb;
		$table = LNKup_Passport_Tables::review_index_table();
		$rows  = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT vendor_profile_id, organizer_user_id, AVG(overall) AS avg_overall, COUNT(*) AS n FROM {$table}
			WHERE status = 'published' AND review_id > 0 AND vendor_profile_id IN (" . implode( ',', $profile_ids ) . ')
			GROUP BY vendor_profile_id, organizer_user_id',
			ARRAY_A
		);
		$by = array();
		foreach ( $rows as $row ) {
			$pid = (int) $row['vendor_profile_id'];
			$by[ $pid ]['avgs'][] = (float) $row['avg_overall'];
			$by[ $pid ]['n']      = ( $by[ $pid ]['n'] ?? 0 ) + (int) $row['n'];
		}
		$min_n   = LNKup_Passport_Settings::int( 'passport_review_min_count' );
		$min_org = LNKup_Passport_Settings::int( 'passport_review_min_organizers' );
		$out     = array();
		foreach ( $by as $pid => $agg ) {
			if ( $agg['n'] >= $min_n && count( $agg['avgs'] ) >= $min_org ) {
				$out[ $pid ] = round( array_sum( $agg['avgs'] ) / count( $agg['avgs'] ), 1 );
			}
		}
		return $out;
	}

	/**
	 * Engine inputs for marketplace index rows.
	 *
	 * @param array $rows Marketplace index rows (ARRAY_A).
	 * @return array<int,array> Keyed by opportunity_id.
	 */
	public static function opportunity_inputs( array $rows ) {
		if ( ! $rows ) {
			return array();
		}
		$opp_ids = array_map( 'intval', wp_list_pluck( $rows, 'opportunity_id' ) );
		update_meta_cache( 'post', $opp_ids );
		$categories = self::categories_for( LNKup_Match_Tables::opportunity_categories_table(), 'opportunity_id', $opp_ids );

		$out = array();
		foreach ( $rows as $row ) {
			$opp_id = (int) $row['opportunity_id'];
			$docs   = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $row['required_documents'] ?? '' ) ) ) ) );

			$out[ $opp_id ] = array(
				'opportunity_id'     => $opp_id,
				'event_id'           => (int) $row['event_id'],
				'organizer_user_id'  => (int) $row['organizer_user_id'],
				'tour_id'            => (int) ( $row['tour_id'] ?? 0 ),
				'status'             => 'open',
				'event_published'    => true,
				'event_date'         => $row['event_date'] ? (string) $row['event_date'] : '',
				'deadline'           => $row['deadline'] ? (string) $row['deadline'] : '',
				'spots_remaining'    => (int) $row['spots_remaining'],
				'invite_only'        => (bool) get_post_meta( $opp_id, '_lnkup_invite_only', true ),
				'categories'         => $categories[ $opp_id ] ?? array(),
				'city'               => (string) $row['city'],
				'state'              => (string) $row['state'],
				'lat'                => $row['lat'],
				'lng'                => $row['lng'],
				'vendor_fee'         => (float) $row['vendor_fee'],
				'indoor'             => (bool) (int) $row['indoor'],
				'outdoor'            => (bool) (int) $row['outdoor'],
				'power_available'    => (bool) (int) $row['power_available'],
				'water_available'    => (bool) (int) $row['water_available'],
				'food_truck_allowed' => (bool) (int) $row['food_truck_allowed'],
				'event_types'        => self::json_list( $row['event_types'] ),
				'required_documents' => $docs,
				'trending_score'     => (float) $row['trending_score'],
			);
		}
		return $out;
	}

	/**
	 * Live opportunity input from canonical posts (action-time revalidation).
	 * Works for closed/full/unpublished opportunities that are not in the index.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 * @return array|null
	 */
	public static function live_opportunity_input( $opportunity_id ) {
		$opportunity_id = (int) $opportunity_id;
		$opp            = get_post( $opportunity_id );
		if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type ) {
			return null;
		}
		$row = LNKup_Marketplace_Index::get_row( $opportunity_id );
		if ( $row ) {
			$input = self::opportunity_inputs( array( $row ) )[ $opportunity_id ];
		} else {
			$event_id = (int) get_post_meta( $opportunity_id, '_lnkup_event_id', true );
			$input    = array(
				'opportunity_id'    => $opportunity_id,
				'event_id'          => $event_id,
				'organizer_user_id' => (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ),
				'categories'        => LNKup_Match_Categories::for_object( $opportunity_id ),
				'city'              => (string) get_post_meta( $event_id, '_lnkup_city', true ),
				'state'             => (string) get_post_meta( $event_id, '_lnkup_state', true ),
				'event_date'        => self::ymd( get_post_meta( $event_id, '_lnkup_event_date', true ) ),
				'deadline'          => self::ymd( get_post_meta( $opportunity_id, '_lnkup_application_deadline', true ) ),
			);
		}
		$event_id                 = (int) $input['event_id'];
		$input['status']          = 'publish' === $opp->post_status ? (string) ( get_post_meta( $opportunity_id, '_lnkup_opportunity_status', true ) ?: 'open' ) : 'closed';
		$input['event_published'] = $event_id && 'publish' === get_post_status( $event_id );
		$input['spots_remaining'] = (int) get_post_meta( $opportunity_id, '_lnkup_spots_remaining', true );
		$input['invite_only']     = (bool) get_post_meta( $opportunity_id, '_lnkup_invite_only', true );
		$input['event_date']      = self::ymd( get_post_meta( $event_id, '_lnkup_event_date', true ) );
		$input['deadline']        = self::ymd( get_post_meta( $opportunity_id, '_lnkup_application_deadline', true ) );
		return $input;
	}

	/* ---------------------------------------------------------------------
	 * Computation (cron)
	 * ------------------------------------------------------------------- */

	/**
	 * Recompute all cached matches for one vendor.
	 *
	 * @param int $profile_id Profile ID.
	 * @param int $limit      Candidate cap.
	 * @return int Recommended matches stored.
	 */
	public static function compute_for_vendor( $profile_id, $limit = self::VENDOR_CANDIDATE_LIMIT ) {
		$profile_id = (int) $profile_id;
		$row        = LNKup_Match_Vendor_Index::get_row( $profile_id );
		if ( ! $row ) {
			self::delete_results( 'profile_id', $profile_id );
			return 0;
		}
		$vendor = self::vendor_inputs( array( $row ) )[ $profile_id ];
		update_post_meta( $profile_id, self::COMPUTED_META, current_time( 'mysql' ) );
		if ( ! $vendor['active'] ) {
			self::delete_results( 'profile_id', $profile_id );
			return 0;
		}
		$opp_rows = self::candidate_opportunity_rows( $vendor, $limit );
		$opps     = self::opportunity_inputs( $opp_rows );
		$results  = array();
		foreach ( $opps as $opp ) {
			$results[] = self::evaluate( $vendor, $opp, 'index' );
		}
		self::write_results( $results, 'profile_id', $profile_id );
		return count( array_filter( wp_list_pluck( $results, 'recommended' ) ) );
	}

	/**
	 * Recompute all cached matches for one opportunity.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 * @return int Recommended matches stored.
	 */
	public static function compute_for_opportunity( $opportunity_id ) {
		$opportunity_id = (int) $opportunity_id;
		$row            = LNKup_Marketplace_Index::get_row( $opportunity_id );
		if ( ! $row ) {
			self::delete_results( 'opportunity_id', $opportunity_id );
			return 0;
		}
		$opp       = self::opportunity_inputs( array( $row ) )[ $opportunity_id ];
		$vend_rows = self::candidate_vendor_rows( $opp, self::OPP_CANDIDATE_LIMIT );
		$results   = array();
		foreach ( array_chunk( $vend_rows, self::CHUNK ) as $chunk ) {
			foreach ( self::vendor_inputs( $chunk ) as $vendor ) {
				$results[] = self::evaluate( $vendor, $opp, 'index' );
			}
		}
		self::write_results( $results, 'opportunity_id', $opportunity_id );
		return count( array_filter( wp_list_pluck( $results, 'recommended' ) ) );
	}

	/**
	 * Run the engine with current settings.
	 *
	 * @param array  $vendor Vendor input.
	 * @param array  $opp    Opportunity input.
	 * @param string $side   vendor|organizer|index.
	 */
	public static function evaluate( array $vendor, array $opp, $side = 'vendor' ) {
		$result = LNKup_Match_Engine::evaluate(
			$vendor,
			$opp,
			LNKup_Match_Settings::weights(),
			array(
				'today'     => current_time( 'Y-m-d' ),
				'side'      => $side,
				'min_score' => LNKup_Match_Settings::min_score(),
			)
		);
		$result['event_id']       = (int) $opp['event_id'];
		$result['tour_id']        = (int) ( $opp['tour_id'] ?? 0 );
		$result['event_date']     = (string) ( $opp['event_date'] ?? '' );
		$result['trending_score'] = (float) ( $opp['trending_score'] ?? 0 );
		return $result;
	}

	/**
	 * Opportunities worth evaluating for a vendor (open, future, category-compatible).
	 */
	private static function candidate_opportunity_rows( array $vendor, $limit ) {
		global $wpdb;
		$index  = LNKup_Marketplace_Index::table_name();
		$cats   = LNKup_Match_Tables::opportunity_categories_table();
		$today  = current_time( 'Y-m-d' );
		$where  = array( 'm.spots_remaining > 0', '(m.deadline IS NULL OR m.deadline >= %s)', '(m.event_date IS NULL OR m.event_date >= %s)' );
		$params = array( $today, $today );

		$term_ids = array_map( 'intval', wp_list_pluck( $vendor['categories'], 'term_id' ) );
		$groups   = array_values( array_filter( wp_list_pluck( $vendor['categories'], 'group' ) ) );
		if ( $term_ids ) {
			$clauses = array( 'c.term_id IN (' . implode( ',', array_fill( 0, count( $term_ids ), '%d' ) ) . ')' );
			$params  = array_merge( $params, $term_ids );
			if ( $groups ) {
				$clauses[] = 'c.group_key IN (' . implode( ',', array_fill( 0, count( $groups ), '%s' ) ) . ')';
				$params    = array_merge( $params, $groups );
			}
			$where[] = "(m.opportunity_id IN (SELECT c.opportunity_id FROM {$cats} c WHERE " . implode( ' OR ', $clauses ) . ") OR m.opportunity_id NOT IN (SELECT opportunity_id FROM {$cats}))";
		}
		$params[] = (int) $limit;
		$sql      = "SELECT m.* FROM {$index} m WHERE " . implode( ' AND ', $where ) . ' ORDER BY m.event_date ASC LIMIT %d';
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Vendors worth evaluating for an opportunity (active, category-compatible or uncategorized).
	 */
	private static function candidate_vendor_rows( array $opp, $limit ) {
		global $wpdb;
		$vtable = LNKup_Match_Tables::vendor_index_table();
		$cats   = LNKup_Match_Tables::vendor_categories_table();
		$where  = array( "v.status = 'active'" );
		$params = array();

		$term_ids = array_map( 'intval', wp_list_pluck( $opp['categories'], 'term_id' ) );
		$groups   = array_values( array_filter( wp_list_pluck( $opp['categories'], 'group' ) ) );
		if ( $term_ids ) {
			$clauses = array( 'c.term_id IN (' . implode( ',', array_fill( 0, count( $term_ids ), '%d' ) ) . ')' );
			$params  = array_merge( $params, $term_ids );
			if ( $groups ) {
				$clauses[] = 'c.group_key IN (' . implode( ',', array_fill( 0, count( $groups ), '%s' ) ) . ')';
				$params    = array_merge( $params, $groups );
			}
			$where[] = "(v.profile_id IN (SELECT c.profile_id FROM {$cats} c WHERE " . implode( ' OR ', $clauses ) . ") OR v.profile_id NOT IN (SELECT profile_id FROM {$cats}))";
		}
		$params[] = (int) $limit;
		$sql      = "SELECT v.* FROM {$vtable} v WHERE " . implode( ' AND ', $where ) . ' ORDER BY v.profile_id ASC LIMIT %d';
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Replace cached results for one vendor or one opportunity.
	 *
	 * @param array  $results Engine results.
	 * @param string $scope   profile_id|opportunity_id.
	 * @param int    $id      Scope ID.
	 */
	private static function write_results( array $results, $scope, $id ) {
		global $wpdb;
		$table = LNKup_Match_Tables::results_table();
		$now   = current_time( 'mysql' );
		$keep  = array();
		foreach ( array_chunk( $results, 200 ) as $chunk ) {
			$values = array();
			$params = array();
			foreach ( $chunk as $r ) {
				// Store recommended matches and near-misses (eligible but suppressed) for diagnostics.
				if ( ! $r['recommended'] && ! empty( $r['blocks'] ) ) {
					continue;
				}
				$keep[]   = 'profile_id' === $scope ? (int) $r['opportunity_id'] : (int) $r['profile_id'];
				$values[] = "(%d,%d,%d,%d,%d,%d,%d,%s,%s,%s,%s,NULLIF(%s,''))";
				array_push(
					$params,
					(int) $r['profile_id'],
					(int) $r['opportunity_id'],
					(int) $r['event_id'],
					(int) $r['tour_id'],
					(int) $r['score'],
					(int) $r['confidence'],
					$r['recommended'] ? 1 : 0,
					wp_json_encode( $r['suppressed'] ),
					wp_json_encode( self::compact_factors( $r['factors'] ) ),
					wp_json_encode( $r['reasons'] ),
					$now,
					$r['recommended'] ? $now : ''
				);
			}
			if ( ! $values ) {
				continue;
			}
			// first_matched_at keeps the first time a pair became recommended ("new matches" for organizers).
			$sql = "INSERT INTO {$table} (profile_id, opportunity_id, event_id, tour_id, score, confidence, eligible, block_reasons, factors, reasons, computed_at, first_matched_at) VALUES "
				. implode( ',', $values )
				. ' ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), tour_id = VALUES(tour_id), score = VALUES(score), confidence = VALUES(confidence), eligible = VALUES(eligible), block_reasons = VALUES(block_reasons), factors = VALUES(factors), reasons = VALUES(reasons), computed_at = VALUES(computed_at), first_matched_at = COALESCE(first_matched_at, VALUES(first_matched_at))';
			$wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB
		}

		$other = 'profile_id' === $scope ? 'opportunity_id' : 'profile_id';
		if ( $keep ) {
			$keep = array_map( 'intval', array_unique( $keep ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$scope} = %d AND {$other} NOT IN (" . implode( ',', $keep ) . ')', $id ) ); // phpcs:ignore WordPress.DB
		} else {
			self::delete_results( $scope, $id );
		}
	}

	/**
	 * Delete cached results.
	 *
	 * @param string $scope profile_id|opportunity_id|event_id.
	 * @param int    $id    ID.
	 */
	public static function delete_results( $scope, $id ) {
		if ( ! in_array( $scope, array( 'profile_id', 'opportunity_id', 'event_id' ), true ) ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( LNKup_Match_Tables::results_table(), array( $scope => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ---------------------------------------------------------------------
	 * Vendor reads
	 * ------------------------------------------------------------------- */

	/**
	 * Recommendation sections for a vendor.
	 *
	 * @param int   $user_id Vendor user ID.
	 * @param array $args    section, page, per_page.
	 * @return array
	 */
	public static function vendor_recommendations( $user_id, array $args = array() ) {
		$section  = sanitize_key( $args['section'] ?? 'recommended' );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 24, max( 1, (int) ( $args['per_page'] ?? 12 ) ) );
		$empty    = array(
			'section'  => $section,
			'items'    => array(),
			'total'    => 0,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => 0,
		);

		$profile_id = (int) lnkup_get_vendor_profile_id( $user_id );
		if ( ! $profile_id ) {
			return array_merge( $empty, array( 'needs_profile' => true ) );
		}
		$row = LNKup_Match_Vendor_Index::get_row( $profile_id );
		if ( ! $row ) {
			LNKup_Match_Vendor_Index::sync( $profile_id );
			$row = LNKup_Match_Vendor_Index::get_row( $profile_id );
		}
		if ( ! $row ) {
			return array_merge( $empty, array( 'needs_profile' => true ) );
		}
		if ( ! get_post_meta( $profile_id, self::COMPUTED_META, true ) ) {
			// First visit: one bounded computation so new vendors are never empty-handed.
			self::compute_for_vendor( $profile_id, 500 );
		}
		$vendor = self::vendor_inputs( array( $row ) )[ $profile_id ];

		if ( in_array( $section, array( 'tours', 'series' ), true ) ) {
			return array_merge( $empty, LNKup_Tours::vendor_tour_section( $vendor, $section, $page, $per_page ) );
		}

		global $wpdb;
		$results = LNKup_Match_Tables::results_table();
		$index   = LNKup_Marketplace_Index::table_name();
		$today   = current_time( 'Y-m-d' );
		$where   = array( 'r.profile_id = %d', 'r.eligible = 1', 'm.spots_remaining > 0', '(m.deadline IS NULL OR m.deadline >= %s)', '(m.event_date IS NULL OR m.event_date >= %s)' );
		$params  = array( $profile_id, $today, $today );
		$order   = 'r.score DESC, m.event_date ASC, m.trending_score DESC';

		switch ( $section ) {
			case 'preferred_markets':
				$cities = array_values( array_unique( array_filter( array_merge( array( LNKup_Match_Engine::norm( $vendor['city'] ) ), array_map( array( 'LNKup_Match_Engine', 'norm' ), array_map( array( __CLASS__, 'city_part' ), $vendor['cities_served'] ) ) ) ) ) );
				if ( ! $cities ) {
					return array_merge( $empty, array( 'needs_preferences' => true ) );
				}
				$where[] = 'LOWER(m.city) IN (' . implode( ',', array_fill( 0, count( $cities ), '%s' ) ) . ')';
				$params  = array_merge( $params, $cities );
				$order   = 'm.event_date ASC, r.score DESC';
				break;
			case 'new':
				$where[]  = 'm.created_at >= %s';
				$params[] = gmdate( 'Y-m-d H:i:s', strtotime( '-14 days' ) );
				$order    = 'm.created_at DESC, r.score DESC';
				break;
			case 'deadlines':
				$where[]  = 'm.deadline IS NOT NULL AND m.deadline <= %s';
				$params[] = gmdate( 'Y-m-d', strtotime( $today . ' +14 days' ) );
				$order    = 'm.deadline ASC, r.score DESC';
				break;
			case 'previous_organizers':
				if ( ! $vendor['past_organizers'] ) {
					return $empty;
				}
				$where[] = 'm.organizer_user_id IN (' . implode( ',', array_map( 'intval', $vendor['past_organizers'] ) ) . ')';
				break;
			case 'recommended':
				break;
			default:
				return new WP_Error( 'lnkup_invalid_section', __( 'Unknown recommendation section.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$where_sql = implode( ' AND ', $where );
		$from      = "FROM {$results} r INNER JOIN {$index} m ON m.opportunity_id = r.opportunity_id WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", $params ) ); // phpcs:ignore WordPress.DB
		$rows      = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT m.*, r.score, r.confidence, r.reasons AS match_reasons {$from} ORDER BY {$order} LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ),
			ARRAY_A
		);

		$items = self::revalidated_vendor_items( $vendor, $rows );
		return array_merge(
			$empty,
			array(
				'items' => $items,
				'total' => $total,
				'pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Re-check eligibility on live data and format cards for a vendor.
	 *
	 * @param array $vendor Vendor input.
	 * @param array $rows   Index rows joined with score/reasons.
	 */
	private static function revalidated_vendor_items( array $vendor, array $rows ) {
		$opps   = self::opportunity_inputs( $rows );
		$items  = array();
		$series = array();
		foreach ( $rows as $row ) {
			$opp_id = (int) $row['opportunity_id'];
			$check  = LNKup_Match_Engine::eligibility( $vendor, $opps[ $opp_id ], array( 'today' => current_time( 'Y-m-d' ), 'side' => 'vendor' ) );
			if ( ! $check['eligible'] || ! lnkup_vendor_can_discover_opportunity( $vendor['user_id'], $opp_id ) ) {
				continue;
			}
			$tour = LNKup_Tours::card_summary( (int) ( $row['tour_id'] ?? 0 ) );
			// Recurring series: one card per series on a page, with a count of further dates.
			if ( $tour && 'series' === $tour['type'] && isset( $series[ $tour['id'] ] ) ) {
				++$items[ $series[ $tour['id'] ] ]['more_dates'];
				continue;
			}
			$card = lnkup_get_opportunity_card_data( $opp_id, $row );
			if ( ! $card ) {
				continue;
			}
			$card['match']      = self::vendor_match_payload( (int) $row['score'], (int) $row['confidence'], self::json_list_raw( $row['match_reasons'] ) );
			$card['tour']       = $tour;
			$card['more_dates'] = 0;
			$items[]            = $card;
			if ( $tour && 'series' === $tour['type'] ) {
				$series[ $tour['id'] ] = count( $items ) - 1;
			}
		}
		return $items;
	}

	/**
	 * Vendor-safe match payload (band, not raw score).
	 */
	public static function vendor_match_payload( $score, $confidence, array $reasons ) {
		$band_labels = array(
			'strong'   => __( 'Strong match', 'lnkup-marketplace' ),
			'good'     => __( 'Good match', 'lnkup-marketplace' ),
			'possible' => __( 'Possible match', 'lnkup-marketplace' ),
		);
		$band = LNKup_Match_Engine::band( $score );
		return array(
			'band'       => $band,
			'band_label' => $band_labels[ $band ],
			'reasons'    => array_values( array_filter( array_map( static function ( $r ) {
				return is_array( $r ) ? (string) ( $r['text'] ?? '' ) : '';
			}, $reasons ) ) ),
			'confidence' => $confidence >= 80 ? 'high' : ( $confidence >= 55 ? 'medium' : 'low' ),
		);
	}

	/**
	 * Cached recommended match for a card (one indexed lookup; no live scoring).
	 *
	 * @param int $user_id        Vendor user ID.
	 * @param int $opportunity_id Opportunity ID.
	 * @return array|null Vendor match payload.
	 */
	public static function cached_match( $user_id, $opportunity_id ) {
		static $profiles = array();
		if ( ! isset( $profiles[ $user_id ] ) ) {
			$profiles[ $user_id ] = LNKup_Roles::is_vendor( $user_id ) ? (int) lnkup_get_vendor_profile_id( $user_id ) : 0;
		}
		if ( ! $profiles[ $user_id ] ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT score, confidence, reasons FROM ' . LNKup_Match_Tables::results_table() . ' WHERE profile_id = %d AND opportunity_id = %d AND eligible = 1', $profiles[ $user_id ], (int) $opportunity_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::vendor_match_payload( (int) $row['score'], (int) $row['confidence'], self::json_list_raw( $row['reasons'] ) ) : null;
	}

	/* ---------------------------------------------------------------------
	 * Alert helpers (used by lnkup-communications at send time)
	 * ------------------------------------------------------------------- */

	/**
	 * Index row + engine input for a vendor user, or null when not indexable.
	 *
	 * @param int $vendor_user_id Vendor user ID.
	 * @return array|null
	 */
	private static function vendor_input_for_user( $vendor_user_id ) {
		$profile_id = (int) lnkup_get_vendor_profile_id( (int) $vendor_user_id );
		if ( ! $profile_id ) {
			return null;
		}
		$row = LNKup_Match_Vendor_Index::get_row( $profile_id );
		if ( ! $row ) {
			LNKup_Match_Vendor_Index::sync( $profile_id );
			$row = LNKup_Match_Vendor_Index::get_row( $profile_id );
		}
		return $row ? self::vendor_inputs( array( $row ) )[ $profile_id ] : null;
	}

	/**
	 * Whether an opportunity is still a recommended, eligible match for a vendor right now.
	 * Live eligibility (status, deadline, inventory, invite-only, applied, active, category)
	 * plus the cached recommendation (score, confidence, market).
	 *
	 * @param int $vendor_user_id Vendor user ID.
	 * @param int $opportunity_id Opportunity ID.
	 */
	public static function is_recommended( $vendor_user_id, $opportunity_id ) {
		if ( ! self::is_eligible( $vendor_user_id, $opportunity_id ) ) {
			return false;
		}
		$profile_id = (int) lnkup_get_vendor_profile_id( (int) $vendor_user_id );
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . LNKup_Match_Tables::results_table() . ' WHERE profile_id = %d AND opportunity_id = %d AND eligible = 1', $profile_id, (int) $opportunity_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Hard eligibility only (no relevance): used for messages that are not market-based,
	 * e.g. an organizer the vendor already booked with posting a new event.
	 *
	 * @param int $vendor_user_id Vendor user ID.
	 * @param int $opportunity_id Opportunity ID.
	 */
	public static function is_eligible( $vendor_user_id, $opportunity_id ) {
		$vendor = self::vendor_input_for_user( $vendor_user_id );
		$opp    = self::live_opportunity_input( $opportunity_id );
		if ( ! $vendor || ! $opp ) {
			return false;
		}
		$check = LNKup_Match_Engine::eligibility( $vendor, $opp, array( 'today' => current_time( 'Y-m-d' ), 'side' => 'vendor' ) );
		return $check['eligible'] && lnkup_vendor_can_discover_opportunity( (int) $vendor_user_id, (int) $opportunity_id );
	}

	/**
	 * Top vendor-facing reasons for a cached match.
	 *
	 * @param int $vendor_user_id Vendor user ID.
	 * @param int $opportunity_id Opportunity ID.
	 * @return string[]
	 */
	public static function alert_reasons( $vendor_user_id, $opportunity_id ) {
		$match = self::cached_match( (int) $vendor_user_id, (int) $opportunity_id );
		return $match ? array_slice( $match['reasons'], 0, 2 ) : array();
	}

	/**
	 * Vendors an organizer could invite for an event: active, recommended for an open
	 * opportunity, and discoverable or already related to the organizer.
	 *
	 * @param int $event_id     Event ID.
	 * @param int $organizer_id Organizer user ID.
	 * @param int $since        Optional unix time; count only matches computed after it.
	 */
	public static function discoverable_match_count( $event_id, $organizer_id, $since = 0 ) {
		global $wpdb;
		$related = self::related_vendor_users( (int) $organizer_id );
		$visible = $related ? '(v.discoverable = 1 OR v.user_id IN (' . implode( ',', $related ) . '))' : 'v.discoverable = 1';
		$where   = $wpdb->prepare( 'r.event_id = %d', (int) $event_id );
		if ( $since > 0 ) {
			$where .= $wpdb->prepare( ' AND r.first_matched_at >= %s', wp_date( 'Y-m-d H:i:s', (int) $since ) );
		}
		$applicants = self::event_applicant_users( (int) $event_id );
		if ( $applicants ) {
			$where .= ' AND v.user_id NOT IN (' . implode( ',', $applicants ) . ')';
		}
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			'SELECT COUNT(DISTINCT r.profile_id) FROM ' . LNKup_Match_Tables::results_table() . ' r INNER JOIN ' . LNKup_Match_Tables::vendor_index_table() . " v ON v.profile_id = r.profile_id
			WHERE {$where} AND r.eligible = 1 AND v.status = 'active' AND {$visible}"
		);
	}

	/**
	 * Open stops of a published tour that a vendor is recommended for.
	 *
	 * @param int $tour_id    Tour ID.
	 * @param int $profile_id Vendor profile ID.
	 * @return array<int,array{event_id:int,opportunity_id:int,city:string,state:string,event_date:string}>
	 */
	public static function tour_matches_for_vendor( $tour_id, $profile_id ) {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		$rows  = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT m.event_id, MIN(m.opportunity_id) AS opportunity_id, m.city, m.state, m.event_date FROM ' . LNKup_Match_Tables::results_table() . ' r
				INNER JOIN ' . LNKup_Marketplace_Index::table_name() . ' m ON m.opportunity_id = r.opportunity_id
				WHERE r.profile_id = %d AND r.eligible = 1 AND m.tour_id = %d AND m.spots_remaining > 0
				AND (m.deadline IS NULL OR m.deadline >= %s) AND (m.event_date IS NULL OR m.event_date >= %s)
				GROUP BY m.event_id, m.city, m.state, m.event_date ORDER BY m.event_date ASC',
				(int) $profile_id,
				(int) $tour_id,
				$today,
				$today
			),
			ARRAY_A
		);
		return array_map(
			static function ( $row ) {
				return array(
					'event_id'       => (int) $row['event_id'],
					'opportunity_id' => (int) $row['opportunity_id'],
					'city'           => (string) $row['city'],
					'state'          => (string) $row['state'],
					'event_date'     => (string) $row['event_date'],
				);
			},
			$rows
		);
	}

	/**
	 * Live explanation for one opportunity (vendor view).
	 *
	 * @param int $user_id        Vendor user ID.
	 * @param int $opportunity_id Opportunity ID.
	 */
	public static function explain_for_vendor( $user_id, $opportunity_id ) {
		$profile_id = (int) lnkup_get_vendor_profile_id( $user_id );
		$opp        = self::live_opportunity_input( $opportunity_id );
		if ( ! $profile_id || ! $opp ) {
			return new WP_Error( 'lnkup_not_found', __( 'We could not find that opportunity.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$row = LNKup_Match_Vendor_Index::get_row( $profile_id );
		if ( ! $row ) {
			LNKup_Match_Vendor_Index::sync( $profile_id );
			$row = LNKup_Match_Vendor_Index::get_row( $profile_id );
		}
		if ( ! $row ) {
			return new WP_Error( 'lnkup_needs_profile', __( 'Complete your vendor profile to see match details.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$vendor  = self::vendor_inputs( array( $row ) )[ $profile_id ];
		$result  = self::evaluate( $vendor, $opp, 'vendor' );
		$payload = self::vendor_match_payload( $result['score'], $result['confidence'], $result['reasons'] );
		return array(
			'opportunity_id' => (int) $opportunity_id,
			'eligible'       => $result['eligible'],
			'recommended'    => $result['recommended'],
			'blocks'         => array_map( array( __CLASS__, 'block_message' ), $result['blocks'] ),
			'match'          => $result['eligible'] ? $payload : null,
			// Legacy /growth/match shape.
			'match_score'    => $result['eligible'] ? (int) $result['score'] : 0,
			'match_label'    => $result['eligible'] ? $payload['band_label'] : '',
			'reasons'        => $result['eligible'] ? $payload['reasons'] : array(),
		);
	}

	/**
	 * Friendly message for a block code.
	 *
	 * @param string $code Block code.
	 */
	public static function block_message( $code ) {
		$map = array(
			LNKup_Match_Engine::BLOCK_OPPORTUNITY_CLOSED => __( 'Applications are closed.', 'lnkup-marketplace' ),
			LNKup_Match_Engine::BLOCK_EVENT_UNPUBLISHED  => __( 'This event is not published.', 'lnkup-marketplace' ),
			LNKup_Match_Engine::BLOCK_EVENT_PAST         => __( 'This event has already happened.', 'lnkup-marketplace' ),
			LNKup_Match_Engine::BLOCK_DEADLINE_PASSED    => __( 'The application deadline has passed.', 'lnkup-marketplace' ),
			LNKup_Match_Engine::BLOCK_NO_INVENTORY       => __( 'All vendor spaces are filled.', 'lnkup-marketplace' ),
			LNKup_Match_Engine::BLOCK_INVITE_REQUIRED    => __( 'This opportunity is invite-only.', 'lnkup-marketplace' ),
			LNKup_Match_Engine::BLOCK_VENDOR_INACTIVE    => __( 'Your vendor account is not active.', 'lnkup-marketplace' ),
			LNKup_Match_Engine::BLOCK_CATEGORY_MISMATCH  => __( "This event isn't accepting your vendor category.", 'lnkup-marketplace' ),
			LNKup_Match_Engine::BLOCK_ALREADY_APPLIED    => __( 'You already applied to this opportunity.', 'lnkup-marketplace' ),
			LNKup_Match_Engine::BLOCK_NOT_DISCOVERABLE   => __( 'This vendor is not accepting discovery.', 'lnkup-marketplace' ),
		);
		return array(
			'code'    => $code,
			'message' => $map[ $code ] ?? __( 'Not available right now.', 'lnkup-marketplace' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Organizer reads
	 * ------------------------------------------------------------------- */

	/**
	 * Matching discoverable vendors for an organizer's event.
	 *
	 * @param int   $organizer_id Organizer user ID.
	 * @param int   $event_id     Event ID (ownership already checked).
	 * @param array $args         opportunity_id, category, city, state, verified, radius, exclude_applied, stage, page, per_page.
	 * @return array|WP_Error
	 */
	public static function event_vendor_candidates( $organizer_id, $event_id, array $args = array() ) {
		global $wpdb;
		$event_id = (int) $event_id;
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 48, max( 1, (int) ( $args['per_page'] ?? 24 ) ) );
		$index    = LNKup_Marketplace_Index::table_name();
		$opp_rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$index} WHERE event_id = %d", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$base     = array(
			'event_id' => $event_id,
			'items'    => array(),
			'total'    => 0,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => 0,
		);
		if ( ! $opp_rows ) {
			return array_merge( $base, array( 'needs_open_opportunity' => true ) );
		}
		$opp_ids = array_map( 'intval', wp_list_pluck( $opp_rows, 'opportunity_id' ) );
		if ( ! empty( $args['opportunity_id'] ) ) {
			$wanted = (int) $args['opportunity_id'];
			if ( ! in_array( $wanted, $opp_ids, true ) ) {
				return new WP_Error( 'lnkup_invalid_opportunity', __( 'That vendor slot is not open for this event.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			$opp_ids = array( $wanted );
		}

		$related_users = self::related_vendor_users( $organizer_id );
		$results       = LNKup_Match_Tables::results_table();
		$vtable        = LNKup_Match_Tables::vendor_index_table();
		$where         = array( 'r.opportunity_id IN (' . implode( ',', $opp_ids ) . ')', 'r.eligible = 1', "v.status = 'active'" );
		$params        = array();
		$where[]       = $related_users ? '(v.discoverable = 1 OR v.user_id IN (' . implode( ',', $related_users ) . '))' : 'v.discoverable = 1';

		if ( ! empty( $args['category'] ) ) {
			$where[]  = 'v.profile_id IN (SELECT profile_id FROM ' . LNKup_Match_Tables::vendor_categories_table() . ' WHERE term_id = %d)';
			$params[] = (int) $args['category'];
		}
		if ( ! empty( $args['city'] ) ) {
			$where[]  = 'v.city = %s';
			$params[] = sanitize_text_field( $args['city'] );
		}
		if ( ! empty( $args['state'] ) ) {
			$where[]  = 'v.state = %s';
			$params[] = strtoupper( sanitize_text_field( $args['state'] ) );
		}
		if ( ! empty( $args['verified'] ) ) {
			$where[] = 'v.verified = 1';
		}
		$event_lat = (float) get_post_meta( $event_id, '_lnkup_lat', true );
		$event_lng = (float) get_post_meta( $event_id, '_lnkup_lng', true );
		$radius    = (int) ( $args['radius'] ?? 0 );
		if ( $radius > 0 && $event_lat && $event_lng ) {
			$box     = self::bounding_box( $event_lat, $event_lng, $radius );
			$where[] = 'v.lat BETWEEN %f AND %f AND v.lng BETWEEN %f AND %f';
			$params  = array_merge( $params, $box );
			$where[] = '(3959 * ACOS(LEAST(1, COS(RADIANS(%f)) * COS(RADIANS(v.lat)) * COS(RADIANS(v.lng) - RADIANS(%f)) + SIN(RADIANS(%f)) * SIN(RADIANS(v.lat))))) <= %d';
			array_push( $params, $event_lat, $event_lng, $event_lat, $radius );
		}
		if ( ! empty( $args['exclude_applied'] ) ) {
			$applied = self::event_applicant_users( $event_id );
			if ( $applied ) {
				$where[] = 'v.user_id NOT IN (' . implode( ',', $applied ) . ')';
			}
		}
		$stage = sanitize_key( $args['stage'] ?? '' );
		if ( $stage ) {
			$where[]  = 'v.profile_id IN (SELECT profile_id FROM ' . LNKup_Match_Tables::pipeline_table() . ' WHERE event_id = %d AND organizer_user_id = %d AND stage = %s)';
			array_push( $params, $event_id, (int) $organizer_id, $stage );
		}

		$where_sql = implode( ' AND ', $where );
		$from      = "FROM {$results} r INNER JOIN {$vtable} v ON v.profile_id = r.profile_id WHERE {$where_sql}";
		$count_sql = "SELECT COUNT(DISTINCT r.profile_id) {$from}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB
		// Deterministic per-event rotation breaks ties without favoring any vendor permanently.
		$sql  = "SELECT r.profile_id, MAX(r.score) AS score, SUBSTRING_INDEX(GROUP_CONCAT(r.opportunity_id ORDER BY r.score DESC), ',', 1) AS best_opportunity_id {$from} GROUP BY r.profile_id ORDER BY score DESC, CRC32(CONCAT(r.profile_id, '-', %d)) ASC LIMIT %d OFFSET %d";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $event_id, $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$items = self::revalidated_organizer_items( $organizer_id, $event_id, $rows, $opp_rows );
		return array_merge(
			$base,
			array(
				'items' => $items,
				'total' => $total,
				'pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Re-check eligibility live and build public vendor cards.
	 */
	private static function revalidated_organizer_items( $organizer_id, $event_id, array $rows, array $opp_rows ) {
		if ( ! $rows ) {
			return array();
		}
		global $wpdb;
		$profile_ids = array_map( 'intval', wp_list_pluck( $rows, 'profile_id' ) );
		$vrows       = (array) $wpdb->get_results( 'SELECT * FROM ' . LNKup_Match_Tables::vendor_index_table() . ' WHERE profile_id IN (' . implode( ',', $profile_ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB
		$vendors     = self::vendor_inputs( $vrows );
		$opps        = self::opportunity_inputs( $opp_rows );
		$stages      = LNKup_Recruitment::stages_for_event( $organizer_id, $event_id, $profile_ids );
		$items       = array();
		foreach ( $rows as $row ) {
			$profile_id = (int) $row['profile_id'];
			$opp_id     = (int) $row['best_opportunity_id'];
			if ( empty( $vendors[ $profile_id ] ) || empty( $opps[ $opp_id ] ) ) {
				continue;
			}
			$result = self::evaluate( $vendors[ $profile_id ], $opps[ $opp_id ], 'organizer' );
			if ( ! $result['eligible'] ) {
				continue;
			}
			$card = self::public_vendor_card( $profile_id, $organizer_id );
			if ( ! $card ) {
				continue;
			}
			$card['match'] = array(
				'band'                => LNKup_Match_Engine::band( $result['score'] ),
				'score'               => (int) $result['score'],
				'reasons'             => self::organizer_reasons( $result['reasons'] ),
				'best_opportunity_id' => $opp_id,
			);
			$card['pipeline_stage'] = $stages[ $profile_id ] ?? '';
			$card['accept_invites'] = ! empty( $vendors[ $profile_id ]['accept_invites'] );
			$items[]                = $card;
		}
		return $items;
	}

	/**
	 * Organizer-facing copy for match reasons (never reveals private data).
	 *
	 * @param array $reasons Engine reasons.
	 * @return string[]
	 */
	public static function organizer_reasons( array $reasons ) {
		$out = array();
		foreach ( $reasons as $reason ) {
			$value = (string) ( $reason['value'] ?? '' );
			switch ( $reason['code'] ?? '' ) {
				case 'category':
					$out[] = sprintf( __( 'Sells in your %s category', 'lnkup-marketplace' ), $value );
					break;
				case 'category_group':
					$out[] = sprintf( __( 'Related to your %s category', 'lnkup-marketplace' ), $value );
					break;
				case 'preferred_city':
					$out[] = sprintf( __( 'Serves %s', 'lnkup-marketplace' ), $value );
					break;
				case 'home_city':
					$out[] = sprintf( __( 'Based in %s', 'lnkup-marketplace' ), $value );
					break;
				case 'within_radius':
					$out[] = sprintf( __( 'About %s miles from your event', 'lnkup-marketplace' ), $value );
					break;
				case 'preferred_state':
					$out[] = sprintf( __( 'Serves %s', 'lnkup-marketplace' ), $value );
					break;
				case 'national':
					$out[] = __( 'Travels nationally', 'lnkup-marketplace' );
					break;
				case 'within_budget':
					$out[] = __( 'Your booth fee fits their budget', 'lnkup-marketplace' );
					break;
				case 'event_type':
					$out[] = sprintf( __( 'Looks for %s events', 'lnkup-marketplace' ), $value );
					break;
				case 'available_month':
					$out[] = __( 'Available that month', 'lnkup-marketplace' );
					break;
				case 'previous_organizer':
					$out[] = __( 'Has worked your events before', 'lnkup-marketplace' );
					break;
				case 'followed_organizer':
					$out[] = __( 'Follows your events', 'lnkup-marketplace' );
					break;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Public vendor card: the only vendor shape returned to unrelated organizers.
	 *
	 * @param int $profile_id   Profile ID.
	 * @param int $organizer_id Viewing organizer.
	 * @return array|null
	 */
	public static function public_vendor_card( $profile_id, $organizer_id = 0 ) {
		$post = get_post( (int) $profile_id );
		if ( ! $post || 'lnkup_vendor_profile' !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		$profile_id = (int) $post->ID;
		$vendor_id  = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		$logo_id    = (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true );
		$cats       = LNKup_Match_Categories::for_object( $profile_id );
		$primary    = (int) get_post_meta( $profile_id, '_lnkup_primary_category', true );
		$primary_nm = '';
		foreach ( $cats as $cat ) {
			if ( $cat['term_id'] === $primary ) {
				$primary_nm = $cat['name'];
			}
		}
		$gallery = array();
		foreach ( array_slice( LNKup_Match_Vendor_Index::list_meta( $profile_id, '_lnkup_gallery_ids' ), 0, 4 ) as $aid ) {
			$url = wp_get_attachment_image_url( (int) $aid, 'medium' );
			if ( $url ) {
				$gallery[] = $url;
			}
		}
		$completed = class_exists( 'LNKup_Reliability_Metrics' ) ? (int) ( LNKup_Reliability_Metrics::get( $profile_id )['completed_events'] ?? 0 ) : 0;
		return array(
			'profile_id'        => $profile_id,
			'vendor_user_id'    => $vendor_id,
			'business_name'     => (string) ( get_post_meta( $profile_id, '_lnkup_business_name', true ) ?: $post->post_title ),
			'logo'              => $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '',
			'primary_category'  => $primary_nm ?: ( $cats[0]['name'] ?? '' ),
			'categories'        => wp_list_pluck( $cats, 'name' ),
			'city'              => (string) get_post_meta( $profile_id, '_lnkup_city', true ),
			'state'             => (string) get_post_meta( $profile_id, '_lnkup_state', true ),
			'description'       => wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 40 ),
			'products_services' => wp_trim_words( (string) get_post_meta( $profile_id, '_lnkup_products_services', true ), 25 ),
			'gallery'           => $gallery,
			'verified'          => 'approved' === get_post_meta( $profile_id, '_lnkup_verification_status', true ),
			'completed_events'  => max( 0, $completed ),
			'can_view_contact'  => $organizer_id ? LNKup_Capabilities::organizer_can_view_vendor_contact( $organizer_id, $profile_id ) : false,
		);
	}

	/**
	 * Vendor user IDs with an application on any of this organizer's events, or an accepted invite.
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @return int[]
	 */
	public static function related_vendor_users( $organizer_id ) {
		global $wpdb;
		$organizer_id = (int) $organizer_id;
		$from_apps    = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT DISTINCT v.meta_value FROM {$wpdb->postmeta} o
				INNER JOIN {$wpdb->posts} p ON p.ID = o.post_id AND p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft')
				INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_lnkup_vendor_user_id'
				WHERE o.meta_key = '_lnkup_organizer_user_id' AND o.meta_value = %s",
				(string) $organizer_id
			)
		);
		$from_invites = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT DISTINCT v.meta_value FROM {$wpdb->postmeta} o
				INNER JOIN {$wpdb->posts} p ON p.ID = o.post_id AND p.post_type = 'lnkup_vendor_invite'
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_status' AND s.meta_value = 'accepted'
				INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_lnkup_vendor_user_id'
				WHERE o.meta_key = '_lnkup_organizer_user_id' AND o.meta_value = %s",
				(string) $organizer_id
			)
		);
		return array_values( array_unique( array_filter( array_map( 'intval', array_merge( (array) $from_apps, (array) $from_invites ) ) ) ) );
	}

	/**
	 * Vendor user IDs with any application on an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int[]
	 */
	public static function event_applicant_users( $event_id ) {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT DISTINCT v.meta_value FROM {$wpdb->postmeta} e
				INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id AND p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft')
				INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_lnkup_vendor_user_id'
				WHERE e.meta_key = '_lnkup_event_id' AND e.meta_value = %s",
				(string) (int) $event_id
			)
		);
		return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * Organizers this vendor completed a paid booking with.
	 *
	 * @param int $user_id Vendor user ID.
	 * @return int[]
	 */
	public static function past_organizers_for_vendor( $user_id ) {
		$facts = self::applications_for_vendors( array( (int) $user_id ) );
		return $facts[ (int) $user_id ]['past_organizers'] ?? array();
	}

	/**
	 * Admin diagnostic: full live explanation for a pair.
	 *
	 * @param int $profile_id     Profile ID.
	 * @param int $opportunity_id Opportunity ID.
	 * @return array|WP_Error
	 */
	public static function diagnose( $profile_id, $opportunity_id ) {
		$profile_id = (int) $profile_id;
		$row        = LNKup_Match_Vendor_Index::get_row( $profile_id );
		if ( ! $row ) {
			LNKup_Match_Vendor_Index::sync( $profile_id );
			$row = LNKup_Match_Vendor_Index::get_row( $profile_id );
		}
		$opp = self::live_opportunity_input( $opportunity_id );
		if ( ! $row || ! $opp ) {
			return new WP_Error( 'lnkup_not_found', __( 'Vendor profile or opportunity not found (or not indexable).', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$vendor = self::vendor_inputs( array( $row ) )[ $profile_id ];
		$result = self::evaluate( $vendor, $opp, 'vendor' );
		global $wpdb;
		$cached = $wpdb->get_row( $wpdb->prepare( 'SELECT score, confidence, eligible, block_reasons, computed_at FROM ' . LNKup_Match_Tables::results_table() . ' WHERE profile_id = %d AND opportunity_id = %d', $profile_id, (int) $opportunity_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'profile_id'     => $profile_id,
			'opportunity_id' => (int) $opportunity_id,
			'weights'        => LNKup_Match_Settings::weights(),
			'min_score'      => LNKup_Match_Settings::min_score(),
			'live'           => $result,
			'cached'         => $cached,
			'vendor_summary' => array(
				'status'        => $row['status'],
				'discoverable'  => (bool) (int) $row['discoverable'],
				'categories'    => wp_list_pluck( $vendor['categories'], 'name' ),
				'city'          => $row['city'],
				'state'         => $row['state'],
				'has_geo'       => null !== $row['lat'],
				'radius_miles'  => (int) $row['radius_miles'],
				'completeness'  => (int) $row['profile_completeness'],
			),
			'opportunity_summary' => array(
				'status'          => $opp['status'],
				'event_published' => $opp['event_published'],
				'categories'      => wp_list_pluck( $opp['categories'], 'name' ),
				'city'            => $opp['city'],
				'state'           => $opp['state'],
				'event_date'      => $opp['event_date'],
				'deadline'        => $opp['deadline'],
				'spots_remaining' => $opp['spots_remaining'],
				'has_geo'         => ! empty( $opp['lat'] ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Bulk loaders and helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Category descriptors from a join table.
	 */
	private static function categories_for( $table, $column, array $ids ) {
		if ( ! $ids ) {
			return array();
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT {$column} AS object_id, term_id, group_key FROM {$table} WHERE {$column} IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return array();
		}
		$term_ids = array_values( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'term_id' ) ) ) );
		$names    = array();
		$terms    = get_terms(
			array(
				'taxonomy'   => LNKup_Match_Categories::TAXONOMY,
				'include'    => $term_ids,
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$names[ (int) $term->term_id ] = $term->name;
			}
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['object_id'] ][] = array(
				'term_id' => (int) $row['term_id'],
				'name'    => $names[ (int) $row['term_id'] ] ?? '',
				'group'   => (string) $row['group_key'],
			);
		}
		return $out;
	}

	/**
	 * Application facts per vendor user.
	 *
	 * @param int[] $user_ids Vendor user IDs.
	 * @return array<int,array{applied:int[],organizers:int[],past_organizers:int[],booked_dates:string[]}>
	 */
	private static function applications_for_vendors( array $user_ids ) {
		if ( ! $user_ids ) {
			return array();
		}
		global $wpdb;
		$in   = implode( ',', array_map( static function ( $id ) {
			return "'" . (int) $id . "'";
		}, $user_ids ) );
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT vu.meta_value AS vendor_user_id, op.meta_value AS opportunity_id, st.meta_value AS status, og.meta_value AS organizer_id, ed.meta_value AS event_date
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} vu ON vu.post_id = p.ID AND vu.meta_key = '_lnkup_vendor_user_id' AND vu.meta_value IN ({$in})
			LEFT JOIN {$wpdb->postmeta} op ON op.post_id = p.ID AND op.meta_key = '_lnkup_opportunity_id'
			LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_lnkup_application_status'
			LEFT JOIN {$wpdb->postmeta} og ON og.post_id = p.ID AND og.meta_key = '_lnkup_organizer_user_id'
			LEFT JOIN {$wpdb->postmeta} ev ON ev.post_id = p.ID AND ev.meta_key = '_lnkup_event_id'
			LEFT JOIN {$wpdb->postmeta} ed ON ed.post_id = ev.meta_value AND ed.meta_key = '_lnkup_event_date'
			WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft')",
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $row ) {
			$uid = (int) $row['vendor_user_id'];
			if ( ! isset( $out[ $uid ] ) ) {
				$out[ $uid ] = array( 'applied' => array(), 'organizers' => array(), 'past_organizers' => array(), 'booked_dates' => array() );
			}
			// Withdrawn / expired and re-openable declined applications do not hide the opportunity.
			$reopenable = 'cancelled' === $row['status']
				|| ( 'rejected' === $row['status'] && 'allowed' === ( LNKup_Applications::reapply_state( (int) $row['opportunity_id'], $uid )['reapply'] ?? '' ) );
			if ( ! $reopenable ) {
				$out[ $uid ]['applied'][] = (int) $row['opportunity_id'];
			}
			$out[ $uid ]['organizers'][] = (int) $row['organizer_id'];
			if ( in_array( $row['status'], array( 'paid', 'completed' ), true ) ) {
				$out[ $uid ]['past_organizers'][] = (int) $row['organizer_id'];
				$date = self::ymd( $row['event_date'] );
				if ( $date ) {
					$out[ $uid ]['booked_dates'][] = $date;
				}
			}
		}
		foreach ( $out as $uid => $facts ) {
			foreach ( $facts as $key => $list ) {
				$out[ $uid ][ $key ] = array_values( array_unique( array_filter( $list ) ) );
			}
		}
		return $out;
	}

	/**
	 * Invite facts per vendor user.
	 */
	private static function invites_for_vendors( array $user_ids ) {
		if ( ! $user_ids ) {
			return array();
		}
		global $wpdb;
		$in   = implode( ',', array_map( static function ( $id ) {
			return "'" . (int) $id . "'";
		}, $user_ids ) );
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT vu.meta_value AS vendor_user_id, op.meta_value AS opportunity_id, st.meta_value AS status, og.meta_value AS organizer_id, ex.meta_value AS expires_at
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} vu ON vu.post_id = p.ID AND vu.meta_key = '_lnkup_vendor_user_id' AND vu.meta_value IN ({$in})
			LEFT JOIN {$wpdb->postmeta} op ON op.post_id = p.ID AND op.meta_key = '_lnkup_opportunity_id'
			LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_lnkup_status'
			LEFT JOIN {$wpdb->postmeta} og ON og.post_id = p.ID AND og.meta_key = '_lnkup_organizer_user_id'
			LEFT JOIN {$wpdb->postmeta} ex ON ex.post_id = p.ID AND ex.meta_key = '_lnkup_expires_at'
			WHERE p.post_type = 'lnkup_vendor_invite' AND p.post_status = 'publish'",
			ARRAY_A
		);
		$out = array();
		$now = time();
		foreach ( $rows as $row ) {
			$uid = (int) $row['vendor_user_id'];
			if ( ! isset( $out[ $uid ] ) ) {
				$out[ $uid ] = array( 'invited' => array(), 'accepted_organizers' => array() );
			}
			$open = ! in_array( $row['status'], array( 'expired', 'declined' ), true ) && ( empty( $row['expires_at'] ) || strtotime( $row['expires_at'] ) >= $now );
			if ( $open && (int) $row['opportunity_id'] ) {
				$out[ $uid ]['invited'][] = (int) $row['opportunity_id'];
			}
			if ( 'accepted' === $row['status'] ) {
				$out[ $uid ]['accepted_organizers'][] = (int) $row['organizer_id'];
			}
		}
		return $out;
	}

	/**
	 * Approved document types per vendor user.
	 */
	private static function approved_documents_for_vendors( array $user_ids ) {
		if ( ! $user_ids ) {
			return array();
		}
		global $wpdb;
		$in   = implode( ',', array_map( static function ( $id ) {
			return "'" . (int) $id . "'";
		}, $user_ids ) );
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT vu.meta_value AS vendor_user_id, ty.meta_value AS doc_type
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} vu ON vu.post_id = p.ID AND vu.meta_key = '_lnkup_vendor_user_id' AND vu.meta_value IN ({$in})
			INNER JOIN {$wpdb->postmeta} vs ON vs.post_id = p.ID AND vs.meta_key = '_lnkup_verification_status' AND vs.meta_value = 'approved'
			INNER JOIN {$wpdb->postmeta} ty ON ty.post_id = p.ID AND ty.meta_key = '_lnkup_document_type'
			LEFT JOIN {$wpdb->postmeta} ex ON ex.post_id = p.ID AND ex.meta_key = '_lnkup_expiration_date'
			LEFT JOIN {$wpdb->postmeta} su ON su.post_id = p.ID AND su.meta_key = '_lnkup_superseded_by'
			WHERE p.post_type = 'lnkup_document' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft')
			AND su.meta_id IS NULL
			AND ( ex.meta_value IS NULL OR ex.meta_value = '' OR ex.meta_value >= '" . esc_sql( gmdate( 'Y-m-d' ) ) . "' )",
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['vendor_user_id'] ][] = sanitize_key( $row['doc_type'] );
		}
		return $out;
	}

	/**
	 * Lat/lng bounding box for a radius in miles.
	 *
	 * @return float[] min_lat, max_lat, min_lng, max_lng.
	 */
	public static function bounding_box( $lat, $lng, $miles ) {
		$lat_delta = $miles / 69.0;
		$lng_delta = $miles / max( 1.0, 69.0 * cos( deg2rad( $lat ) ) );
		return array( $lat - $lat_delta, $lat + $lat_delta, $lng - $lng_delta, $lng + $lng_delta );
	}

	/**
	 * Factors without verbose reason text (stored for diagnostics).
	 */
	private static function compact_factors( array $factors ) {
		$out = array();
		foreach ( $factors as $key => $factor ) {
			$out[ $key ] = array(
				'score'  => $factor['score'],
				'kind'   => $factor['kind'],
				'weight' => $factor['weight'] ?? 0,
			);
		}
		return $out;
	}

	public static function city_part( $value ) {
		return trim( explode( ',', (string) $value )[0] );
	}

	private static function json_list( $raw ) {
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? array_values( array_filter( array_map( 'strval', $decoded ), 'strlen' ) ) : array();
	}

	private static function json_list_raw( $raw ) {
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function truthy( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return ! in_array( $value, array( '', '0', 'no', 'none', 'false', 'n/a' ), true );
	}

	private static function ymd( $date ) {
		$date = trim( (string) $date );
		if ( '' === $date ) {
			return '';
		}
		$ts = strtotime( $date );
		return $ts ? gmdate( 'Y-m-d', $ts ) : '';
	}
}
