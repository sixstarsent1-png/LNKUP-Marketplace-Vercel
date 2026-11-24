<?php
/**
 * Marketplace search and filter engine.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Discovery {

	const PER_PAGE = 24;

	/**
	 * Search index with filters, sort, pagination.
	 *
	 * @param array $args Query args.
	 */
	public static function search( $args = array() ) {
		global $wpdb;
		$table = LNKup_Marketplace_Index::table_name();

		$defaults = array(
			'q'                  => '',
			'city'               => '',
			'state'              => '',
			'radius'             => 0,
			'lat'                => null,
			'lng'                => null,
			'date_preset'        => '',
			'date_from'          => '',
			'date_to'            => '',
			'attendance_min'     => 0,
			'attendance_max'     => 0,
			'attendance_bucket'  => '',
			'fee_min'            => null,
			'fee_max'            => null,
			'fee_free'           => false,
			'fee_bucket'         => '',
			'categories'         => array(),
			'event_types'        => array(),
			'markets'            => array(),
			'indoor'             => null,
			'outdoor'            => null,
			'environment'        => '',
			'power_available'    => null,
			'water_available'    => null,
			'food_truck_allowed' => null,
			'verified_organizer' => null,
			'featured_organizer' => null,
			'instant_approval'   => null,
			'has_spots'          => null,
			'show_closed'        => false,
			'newly_added'        => false,
			'closing_soon'       => false,
			'almost_full'        => false,
			'applications_open'  => false,
			'doc_required'       => array(),
			'doc_none'           => false,
			'sort'               => 'newest',
			'page'               => 1,
			'per_page'           => self::PER_PAGE,
			'created_after'      => '',
			'tour_id'            => 0,
			'organizer_id'       => 0,
			'opportunity_ids'    => array(),
			'preferred_markets'  => false,
			'previous_organizers'=> false,
			'vendor_user_id'     => 0,
			'force_like'         => false,
		);

		$args = wp_parse_args( $args, $defaults );
		$args = self::normalize_fee_bucket( $args );
		$page    = max( 1, (int) $args['page'] );
		$per_page = min( 48, max( 1, (int) $args['per_page'] ) );
		$offset  = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array();

		$q            = self::normalize_query( $args['q'] );
		$use_fulltext = false;
		if ( $q ) {
			$boolean = self::fulltext_query( $q );
			if ( $boolean && empty( $args['force_like'] ) ) {
				$use_fulltext = true;
				$where[]      = "MATCH({$table}.title, {$table}.city, {$table}.state, {$table}.venue_name, {$table}.search_blob) AGAINST (%s IN BOOLEAN MODE)";
				$params[]     = $boolean;
			} else {
				$like    = '%' . $wpdb->esc_like( $q ) . '%';
				$where[] = '(title LIKE %s OR city LIKE %s OR state LIKE %s OR venue_name LIKE %s OR search_blob LIKE %s OR categories LIKE %s OR event_types LIKE %s OR tags LIKE %s)';
				array_push( $params, $like, $like, $like, $like, $like, $like, $like, $like );
			}
		}

		if ( ! empty( $args['tour_id'] ) ) {
			$where[]  = "{$table}.tour_id = %d";
			$params[] = absint( $args['tour_id'] );
		}
		if ( ! empty( $args['organizer_id'] ) ) {
			$where[]  = "{$table}.organizer_user_id = %d";
			$params[] = absint( $args['organizer_id'] );
		}
		$only_ids = array_filter( array_map( 'absint', (array) $args['opportunity_ids'] ) );
		if ( $only_ids ) {
			$where[] = "{$table}.opportunity_id IN (" . implode( ',', $only_ids ) . ')';
		}
		$vendor_user_id = absint( $args['vendor_user_id'] ) ?: get_current_user_id();
		if ( ! empty( $args['preferred_markets'] ) || ! empty( $args['previous_organizers'] ) ) {
			$personal = self::personal_filter( $args, $vendor_user_id );
			if ( null === $personal ) {
				$where[] = '1=0';
			} else {
				$where[] = $personal['sql'];
				$params  = array_merge( $params, $personal['params'] );
			}
		}

		$lat    = is_numeric( $args['lat'] ) ? (float) $args['lat'] : 0.0;
		$lng    = is_numeric( $args['lng'] ) ? (float) $args['lng'] : 0.0;
		$has_xy = $lat && $lng && abs( $lat ) <= 90 && abs( $lng ) <= 180;
		$radius = (int) $args['radius'];
		if ( $radius > 0 && $has_xy ) {
			$box      = LNKup_Match_Service::bounding_box( $lat, $lng, $radius );
			$where[]  = "{$table}.lat BETWEEN %f AND %f AND {$table}.lng BETWEEN %f AND %f";
			$params   = array_merge( $params, $box );
			$where[]  = self::distance_sql( $table ) . ' <= %d';
			$params   = array_merge( $params, array( $lat, $lng, $lat, $radius ) );
		}

		if ( $args['city'] ) {
			$where[]  = 'city LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['city'] ) . '%';
		}
		if ( $args['state'] ) {
			$where[]  = 'UPPER(state) = %s';
			$params[] = strtoupper( $args['state'] );
		}

		self::apply_date_filters( $args, $where, $params );
		self::apply_attendance_filters( $args, $where, $params );
		self::apply_fee_filters( $args, $where, $params );
		self::apply_taxonomy_filters( $args, $where, $params );
		self::apply_environment_filter( $args, $where, $params );
		self::apply_document_filters( $args, $where, $params );
		self::apply_availability_filters( $args, $where, $params );
		self::apply_deadline_visibility( $args, $where, $params );
		if ( ! empty( $args['created_after'] ) ) {
			$where[]  = 'created_at > %s';
			$params[] = gmdate( 'Y-m-d H:i:s', strtotime( (string) $args['created_after'] ) );
		}
		self::apply_bool_filter( $args, 'indoor', 'indoor', $where, $params );
		self::apply_bool_filter( $args, 'outdoor', 'outdoor', $where, $params );
		self::apply_bool_filter( $args, 'power_available', 'power_available', $where, $params );
		self::apply_bool_filter( $args, 'water_available', 'water_available', $where, $params );
		self::apply_bool_filter( $args, 'food_truck_allowed', 'food_truck_allowed', $where, $params );
		if ( $args['featured_organizer'] ) {
			$args['verified_organizer'] = true;
		}
		self::apply_bool_filter( $args, 'verified_organizer', 'organizer_verified', $where, $params );
		self::apply_bool_filter( $args, 'instant_approval', 'instant_approval', $where, $params );

		if ( $args['has_spots'] ) {
			$where[] = 'spots_remaining > 0';
		}

		$where_sql = implode( ' AND ', $where );
		if ( $q && ( empty( $args['sort'] ) || 'newest' === $args['sort'] ) ) {
			$args['sort'] = 'relevance';
		}

		$join       = '';
		$select     = "{$table}.*";
		$profile_id = 0;
		if ( 'recommended' === $args['sort'] && $vendor_user_id && class_exists( 'LNKup_Match_Settings' ) && LNKup_Match_Settings::enabled( 'matching_vendor_discovery_enabled' ) ) {
			$profile_id = (int) lnkup_get_vendor_profile_id( $vendor_user_id );
		}
		if ( $profile_id ) {
			$results = LNKup_Match_Tables::results_table();
			$join    = $wpdb->prepare( " LEFT JOIN {$results} mr ON mr.opportunity_id = {$table}.opportunity_id AND mr.profile_id = %d AND mr.eligible = 1", $profile_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$select .= ', mr.score AS match_score';
		}

		$order_sql = '';
		if ( 'relevance' === $args['sort'] && $q ) {
			$relevance = self::build_relevance_order( $q );
			$order_sql = $relevance['sql'];
			$params    = array_merge( $params, $relevance['params'] );
		} elseif ( $profile_id ) {
			$order_sql = "ORDER BY COALESCE(mr.score, 0) DESC, {$table}.event_date ASC, {$table}.trending_score DESC";
		} elseif ( 'closest' === $args['sort'] && $has_xy ) {
			$order_sql = $wpdb->prepare( 'ORDER BY ' . self::distance_sql( $table ) . ' ASC', $lat, $lng, $lat ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$order_sql = self::build_order( $args['sort'], $args['lat'], $args['lng'] );
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( $params && 'relevance' !== $args['sort'] ) {
			$count_sql = $wpdb->prepare( $count_sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total     = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} elseif ( $params && 'relevance' === $args['sort'] && $q ) {
			// Count query must not include ORDER BY relevance placeholders.
			$relevance_count = count( self::build_relevance_order( $q )['params'] );
			$count_params    = array_slice( $params, 0, max( 0, count( $params ) - $relevance_count ) );
			$count_sql       = $count_params
				? $wpdb->prepare( $count_sql, $count_params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				: $count_sql;
			$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( $use_fulltext && 0 === $total && 1 === $page ) {
			// Fulltext ignores short/stop words; fall back to substring matching.
			return self::search( array_merge( $args, array( 'force_like' => true ) ) );
		}

		$sql        = "SELECT {$select} FROM {$table}{$join} WHERE {$where_sql} {$order_sql} LIMIT %d OFFSET %d";
		$all_params = array_merge( $params, array( $per_page, $offset ) );
		$rows       = $wpdb->get_results( $wpdb->prepare( $sql, $all_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$items = array();
		if ( class_exists( 'LNKup_Event_Media' ) ) {
			LNKup_Event_Media::prime( wp_list_pluck( (array) $rows, 'event_id' ) );
		}
		foreach ( (array) $rows as $row ) {
			$item = lnkup_get_opportunity_card_data( (int) $row['opportunity_id'], $row );
			if ( $has_xy && $row['lat'] && $row['lng'] ) {
				$item['distance_miles'] = round( LNKup_Geocoder::distance_miles( $lat, $lng, (float) $row['lat'], (float) $row['lng'] ), 1 );
			}
			if ( isset( $row['match_score'] ) && null !== $row['match_score'] ) {
				$item['match_band'] = LNKup_Match_Engine::band( (int) $row['match_score'] );
			}
			if ( $q ) {
				$item = self::highlight_item( $item, $q );
			}
			$items[] = $item;
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Autocomplete suggestions.
	 *
	 * @param string $q     Query.
	 * @param int    $user_id User ID for recent searches.
	 */
	public static function suggestions( $q = '', $user_id = 0 ) {
		global $wpdb;
		$table = LNKup_Marketplace_Index::table_name();
		$q     = self::normalize_query( $q );

		$suggestions = array(
			'recent'     => array(),
			'popular'    => array(),
			'categories' => array(),
			'cities'     => array(),
			'events'     => array(),
			'organizers' => array(),
		);

		if ( $user_id ) {
			$history = LNKup_Vendor_Personalization::get_search_history( $user_id );
			$suggestions['recent'] = array_slice( $history, 0, 5 );
		}

		$popular = get_option( 'lnkup_popular_searches', array( 'Charlotte', 'Food Truck', 'Festival', 'Atlanta', 'Rodeo' ) );
		$suggestions['popular'] = array_slice( (array) $popular, 0, 8 );
		$suggestions['categories'] = LNKup_Trending::get_popular_categories( 8 );

		if ( strlen( $q ) >= 1 ) {
			$like = '%' . $wpdb->esc_like( $q ) . '%';
			$cities = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT city FROM {$table} WHERE city LIKE %s ORDER BY city LIMIT 5", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$suggestions['cities'] = array_values( array_filter( (array) $cities ) );

			$events = $wpdb->get_results( $wpdb->prepare( "SELECT opportunity_id, title, city, state FROM {$table} WHERE title LIKE %s ORDER BY trending_score DESC LIMIT 5", $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $events as $e ) {
				$suggestions['events'][] = array(
					'id'    => (int) $e['opportunity_id'],
					'label' => $e['title'] . ' — ' . $e['city'] . ', ' . $e['state'],
				);
			}

			$terms = get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false, 'search' => $q, 'number' => 5 ) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$suggestions['categories'][] = $term->name;
				}
			}
		}

		$trending = LNKup_Trending::get_section( 'trending', 3 );
		foreach ( $trending as $t ) {
			$suggestions['events'][] = array(
				'id'    => $t['opportunity_id'],
				'label' => $t['name'] . ' — ' . $t['city'] . ', ' . $t['state'],
			);
		}

		return $suggestions;
	}

	/**
	 * Normalize search query.
	 *
	 * @param string $q Query.
	 */
	public static function normalize_query( $q ) {
		$q = strtolower( trim( (string) $q ) );
		$q = preg_replace( '/\s+/', ' ', $q );
		return $q;
	}

	/**
	 * Highlight matching text in item fields.
	 *
	 * @param array  $item Item.
	 * @param string $q    Query.
	 */
	private static function highlight_item( $item, $q ) {
		$words = explode( ' ', $q );
		foreach ( array( 'name', 'city', 'state' ) as $field ) {
			if ( empty( $item[ $field ] ) ) {
				continue;
			}
			$text = $item[ $field ];
			foreach ( $words as $word ) {
				if ( strlen( $word ) < 2 ) {
					continue;
				}
				$text = preg_replace( '/(' . preg_quote( $word, '/' ) . ')/i', '<mark class="lnkup-highlight">$1</mark>', $text );
			}
			$item[ $field . '_html' ] = $text;
		}
		return $item;
	}

	/**
	 * Apply date preset filters.
	 */
	private static function apply_date_filters( $args, &$where, &$params ) {
		$from = $args['date_from'];
		$to   = $args['date_to'];

		switch ( $args['date_preset'] ) {
			case 'today':
				$from = $to = gmdate( 'Y-m-d' );
				break;
			case 'tomorrow':
				$from = $to = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
				break;
			case 'week':
			case 'this_week':
				$from = gmdate( 'Y-m-d' );
				$to   = gmdate( 'Y-m-d', strtotime( 'Sunday this week' ) );
				break;
			case 'weekend':
			case 'this_weekend':
				$from = gmdate( 'Y-m-d', strtotime( 'Saturday this week' ) );
				$to   = gmdate( 'Y-m-d', strtotime( 'Sunday this week' ) );
				break;
			case 'month':
			case 'this_month':
				$from = gmdate( 'Y-m-d' );
				$to   = gmdate( 'Y-m-t' );
				break;
			case 'next_30':
				$from = gmdate( 'Y-m-d' );
				$to   = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
				break;
		}

		if ( $from ) {
			$where[]  = 'event_date >= %s';
			$params[] = $from;
		}
		if ( $to ) {
			$where[]  = 'event_date <= %s';
			$params[] = $to;
		}
	}

	/**
	 * Apply attendance range.
	 */
	private static function apply_attendance_filters( $args, &$where, &$params ) {
		if ( $args['attendance_min'] > 0 ) {
			$where[]  = 'attendance >= %d';
			$params[] = (int) $args['attendance_min'];
		}
		if ( $args['attendance_max'] > 0 ) {
			$where[]  = 'attendance <= %d';
			$params[] = (int) $args['attendance_max'];
		}
		if ( ! empty( $args['attendance_bucket'] ) ) {
			$bucket = $args['attendance_bucket'];
			if ( '0-500' === $bucket ) {
				$where[] = 'attendance <= 500';
			} elseif ( '500-1000' === $bucket ) {
				$where[] = 'attendance > 500 AND attendance <= 1000';
			} elseif ( '1000-5000' === $bucket ) {
				$where[] = 'attendance > 1000 AND attendance <= 5000';
			} elseif ( '5000-10000' === $bucket ) {
				$where[] = 'attendance > 5000 AND attendance <= 10000';
			} elseif ( '10000+' === $bucket ) {
				$where[] = 'attendance > 10000';
			}
		}
	}

	/**
	 * Apply fee filters.
	 */
	private static function apply_fee_filters( $args, &$where, &$params ) {
		if ( $args['fee_free'] ) {
			$where[] = 'vendor_fee = 0';
			return;
		}
		if ( null !== $args['fee_min'] && '' !== $args['fee_min'] ) {
			$where[]  = 'vendor_fee >= %f';
			$params[] = (float) $args['fee_min'];
		}
		if ( null !== $args['fee_max'] && '' !== $args['fee_max'] ) {
			$where[]  = 'vendor_fee <= %f';
			$params[] = (float) $args['fee_max'];
		}
	}

	/**
	 * Apply taxonomy JSON filters.
	 */
	private static function apply_taxonomy_filters( $args, &$where, &$params ) {
		global $wpdb;
		foreach ( array( 'categories' => 'categories', 'event_types' => 'event_types', 'markets' => 'markets' ) as $key => $col ) {
			$vals = array_filter( (array) $args[ $key ] );
			if ( empty( $vals ) ) {
				continue;
			}
			$parts = array();
			foreach ( $vals as $val ) {
				$parts[]  = "{$col} LIKE %s";
				$params[] = '%' . $wpdb->esc_like( $val ) . '%';
			}
			if ( count( $parts ) === 1 ) {
				$where[] = $parts[0];
			} else {
				$where[] = '(' . implode( ' OR ', $parts ) . ')';
			}
		}
	}

	/**
	 * Apply boolean column filter.
	 */
	private static function apply_bool_filter( $args, $arg_key, $col, &$where, &$params ) {
		if ( null === $args[ $arg_key ] || '' === $args[ $arg_key ] ) {
			return;
		}
		$where[]  = "{$col} = %d";
		$params[] = $args[ $arg_key ] ? 1 : 0;
	}

	/**
	 * Build ORDER BY clause.
	 */
	private static function build_order( $sort, $lat = null, $lng = null ) {
		global $wpdb;
		switch ( $sort ) {
			case 'relevance':
				return 'ORDER BY trending_score DESC';
			case 'recommended':
			case 'popular':
				return 'ORDER BY trending_score DESC';
			case 'soonest':
				return 'ORDER BY event_date ASC';
			case 'spots':
			case 'spots_remaining':
				return 'ORDER BY spots_remaining DESC';
			case 'recently_added':
			case 'newest':
				return 'ORDER BY created_at DESC';
			case 'fee_low':
				return 'ORDER BY vendor_fee ASC';
			case 'fee_high':
				return 'ORDER BY vendor_fee DESC';
			case 'attendance':
				return 'ORDER BY attendance DESC';
			case 'deadline':
			case 'closing_soon':
				return 'ORDER BY deadline ASC';
			case 'most_applied':
				return 'ORDER BY application_count DESC';
			case 'closest':
				if ( $lat && $lng ) {
					return $wpdb->prepare( 'ORDER BY (POW(lat - %f, 2) + POW(lng - %f, 2)) ASC', $lat, $lng ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
				return 'ORDER BY trending_score DESC';
			default:
				return 'ORDER BY created_at DESC';
		}
	}

	/**
	 * Relevance-ranked ORDER BY when a search query is present.
	 * Returns SQL fragment + params for a single outer $wpdb->prepare (no nested prepare).
	 *
	 * @param string $q Normalized query.
	 * @return array{sql:string,params:array}
	 */
	private static function build_relevance_order( $q ) {
		global $wpdb;
		$q          = self::normalize_query( $q );
		$like_start = $wpdb->esc_like( $q ) . '%';
		$like_any   = '%' . $wpdb->esc_like( $q ) . '%';
		return array(
			'sql'    => 'ORDER BY (title = %s) DESC, (LOWER(title) LIKE %s) DESC, (city LIKE %s OR state LIKE %s) DESC, (categories LIKE %s OR event_types LIKE %s) DESC, (search_blob LIKE %s) DESC, trending_score DESC',
			'params' => array( $q, $like_start, $like_any, $like_any, $like_any, $like_any, $like_any ),
		);
	}

	/**
	 * Map fee bucket to min/max.
	 *
	 * @param array $args Query args.
	 */
	private static function normalize_fee_bucket( $args ) {
		if ( empty( $args['fee_bucket'] ) ) {
			return $args;
		}
		switch ( $args['fee_bucket'] ) {
			case 'free':
				$args['fee_free'] = true;
				break;
			case 'under_100':
				$args['fee_max'] = 100;
				break;
			case '100_250':
				$args['fee_min'] = 100;
				$args['fee_max'] = 250;
				break;
			case '250_500':
				$args['fee_min'] = 250;
				$args['fee_max'] = 500;
				break;
			case '500_1000':
				$args['fee_min'] = 500;
				$args['fee_max'] = 1000;
				break;
			case '1000_plus':
				$args['fee_min'] = 1000;
				break;
		}
		return $args;
	}

	/**
	 * Hide past-deadline opportunities unless show_closed.
	 */
	private static function apply_deadline_visibility( $args, &$where, &$params ) {
		if ( ! empty( $args['show_closed'] ) ) {
			return;
		}
		$today    = gmdate( 'Y-m-d' );
		$where[]  = '(deadline IS NULL OR deadline >= %s)';
		$params[] = $today;
	}

	/**
	 * Environment filter.
	 */
	private static function apply_environment_filter( $args, &$where, &$params ) {
		$env = sanitize_key( $args['environment'] ?? '' );
		if ( ! $env ) {
			return;
		}
		switch ( $env ) {
			case 'indoor':
				$where[] = 'indoor = 1';
				break;
			case 'outdoor':
				$where[] = 'outdoor = 1';
				break;
			case 'both':
			case 'indoor_outdoor':
				$where[] = 'indoor = 1 AND outdoor = 1';
				break;
			case 'tented':
				$where[] = 'tent_allowed = 1';
				break;
			case 'rain_or_shine':
				$where[] = "(tags LIKE '%rain%' OR tags LIKE '%shine%' OR tags LIKE '%tented%')";
				break;
		}
	}

	/**
	 * Document requirement filters.
	 */
	private static function apply_document_filters( $args, &$where, &$params ) {
		global $wpdb;
		if ( ! empty( $args['doc_none'] ) ) {
			$where[] = "(required_documents IS NULL OR required_documents = '' OR required_documents = 'none')";
			return;
		}
		$map = array(
			'coi'              => 'certificate_of_insurance',
			'health_permit'    => 'health_permit',
			'business_license' => 'business_license',
			'w9'               => 'w9',
		);
		foreach ( array_filter( (array) $args['doc_required'] ) as $doc ) {
			$slug = $map[ $doc ] ?? sanitize_key( $doc );
			$where[]  = 'required_documents LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $slug ) . '%';
		}
	}

	/**
	 * Availability filters.
	 */
	private static function apply_availability_filters( $args, &$where, &$params ) {
		unset( $params );
		if ( ! empty( $args['newly_added'] ) ) {
			$where[] = "created_at >= '" . esc_sql( gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) ) ) . "'";
		}
		if ( ! empty( $args['closing_soon'] ) ) {
			$where[] = "deadline IS NOT NULL AND deadline <= '" . esc_sql( gmdate( 'Y-m-d', strtotime( '+7 days' ) ) ) . "' AND deadline >= '" . esc_sql( gmdate( 'Y-m-d' ) ) . "'";
		}
		if ( ! empty( $args['almost_full'] ) ) {
			$where[] = 'spots_total > 0 AND (spots_remaining / spots_total) <= 0.1 AND spots_remaining > 0';
		}
		if ( ! empty( $args['applications_open'] ) ) {
			$today   = gmdate( 'Y-m-d' );
			$where[] = "(deadline IS NULL OR deadline >= '{$today}')";
		}
	}

	/**
	 * Match saved search filters against current index row.
	 *
	 * @param array $filters Saved filters.
	 * @param array $row     Index row.
	 */
	public static function matches_filters( $filters, $row ) {
		$opportunity_id = (int) ( $row['opportunity_id'] ?? 0 );
		if ( ! $opportunity_id ) {
			return false;
		}
		$result = self::search(
			array_merge(
				(array) $filters,
				array(
					'opportunity_ids' => array( $opportunity_id ),
					'per_page'        => 1,
					'page'            => 1,
				)
			)
		);
		return (int) ( $result['total'] ?? 0 ) > 0;
	}

	/**
	 * Boolean-mode fulltext expression, or '' when LIKE is more accurate (short words).
	 *
	 * @param string $q Normalized query.
	 */
	private static function fulltext_query( $q ) {
		$clean = trim( preg_replace( '/[+\-<>()~*"@]+/', ' ', (string) $q ) );
		$words = array_filter( explode( ' ', $clean ), 'strlen' );
		if ( ! $words ) {
			return '';
		}
		$terms = array();
		foreach ( $words as $word ) {
			if ( strlen( $word ) < 3 ) {
				return '';
			}
			$terms[] = '+' . $word . '*';
		}
		return implode( ' ', $terms );
	}

	/**
	 * Haversine distance in miles from a point (3 placeholders: lat, lng, lat).
	 *
	 * @param string $table Index table name.
	 */
	private static function distance_sql( $table ) {
		return "(3959 * ACOS(LEAST(1, COS(RADIANS(%f)) * COS(RADIANS({$table}.lat)) * COS(RADIANS({$table}.lng) - RADIANS(%f)) + SIN(RADIANS(%f)) * SIN(RADIANS({$table}.lat)))))";
	}

	/**
	 * Vendor-personal filters (preferred markets, previous organizers).
	 *
	 * @param array $args    Search args.
	 * @param int   $user_id Vendor user ID.
	 * @return array{sql:string,params:array}|null Null when the filter can match nothing.
	 */
	private static function personal_filter( $args, $user_id ) {
		$profile_id = $user_id ? (int) lnkup_get_vendor_profile_id( $user_id ) : 0;
		if ( ! $profile_id ) {
			return null;
		}
		$clauses = array();
		$params  = array();
		if ( ! empty( $args['preferred_markets'] ) ) {
			$cities = array_map( array( 'LNKup_Match_Service', 'city_part' ), LNKup_Match_Vendor_Index::list_meta( $profile_id, '_lnkup_cities_served' ) );
			$cities[] = (string) get_post_meta( $profile_id, '_lnkup_city', true );
			$cities   = array_values( array_unique( array_filter( array_map( array( 'LNKup_Match_Engine', 'norm' ), $cities ) ) ) );
			$states   = array_values( array_unique( array_filter( array_map( 'strtoupper', LNKup_Match_Vendor_Index::list_meta( $profile_id, '_lnkup_states_served' ) ) ) ) );
			$market   = array();
			if ( $cities ) {
				$market[] = 'LOWER(city) IN (' . implode( ',', array_fill( 0, count( $cities ), '%s' ) ) . ')';
				$params   = array_merge( $params, $cities );
			}
			if ( $states ) {
				$market[] = 'UPPER(state) IN (' . implode( ',', array_fill( 0, count( $states ), '%s' ) ) . ')';
				$params   = array_merge( $params, $states );
			}
			if ( ! $market ) {
				return null;
			}
			$clauses[] = '(' . implode( ' OR ', $market ) . ')';
		}
		if ( ! empty( $args['previous_organizers'] ) ) {
			$past = LNKup_Match_Service::past_organizers_for_vendor( $user_id );
			if ( ! $past ) {
				return null;
			}
			$clauses[] = 'organizer_user_id IN (' . implode( ',', array_map( 'intval', $past ) ) . ')';
		}
		return array(
			'sql'    => implode( ' AND ', $clauses ),
			'params' => $params,
		);
	}
}
