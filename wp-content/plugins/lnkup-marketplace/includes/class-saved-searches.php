<?php
/**
 * Vendor saved searches and alert matching.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Saved_Searches {

	const META_KEY = 'lnkup_saved_searches';

	const STATUS_ACTIVE  = 'active';
	const STATUS_PAUSED  = 'paused';
	const STATUS_DELETED = 'deleted';

	const FREQ_DAILY   = 'daily';
	const FREQ_WEEKLY  = 'weekly';
	const FREQ_INSTANT = 'instant';

	/**
	 * Allowed filter keys for saved searches.
	 *
	 * @return string[]
	 */
	public static function allowed_filter_keys() {
		return array(
			'q',
			'city',
			'state',
			'radius',
			'lat',
			'lng',
			'date_preset',
			'date_from',
			'date_to',
			'attendance_min',
			'attendance_max',
			'attendance_bucket',
			'fee_min',
			'fee_max',
			'fee_free',
			'fee_bucket',
			'categories',
			'event_types',
			'markets',
			'indoor',
			'outdoor',
			'environment',
			'power_available',
			'water_available',
			'food_truck_allowed',
			'verified_organizer',
			'featured_organizer',
			'instant_approval',
			'has_spots',
			'show_closed',
			'newly_added',
			'closing_soon',
			'almost_full',
			'applications_open',
			'doc_required',
			'doc_none',
			'sort',
		);
	}

	/**
	 * Sanitize marketplace filter snapshot.
	 *
	 * @param array $raw Raw filters.
	 */
	public static function sanitize_filters( $raw ) {
		$raw    = is_array( $raw ) ? $raw : array();
		$clean  = array();
		$allowed = self::allowed_filter_keys();

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			$val = $raw[ $key ];
			if ( in_array( $key, array( 'categories', 'event_types', 'markets', 'doc_required' ), true ) ) {
				$val = is_string( $val ) ? array_filter( explode( ',', $val ) ) : (array) $val;
				$clean[ $key ] = array_values( array_filter( array_map( 'sanitize_text_field', $val ) ) );
				continue;
			}
			if ( in_array(
				$key,
				array(
					'fee_free',
					'indoor',
					'outdoor',
					'power_available',
					'water_available',
					'food_truck_allowed',
					'verified_organizer',
					'featured_organizer',
					'instant_approval',
					'has_spots',
					'show_closed',
					'newly_added',
					'closing_soon',
					'almost_full',
					'applications_open',
					'doc_none',
				),
				true
			) ) {
				$clean[ $key ] = (bool) rest_sanitize_boolean( $val );
				continue;
			}
			if ( in_array( $key, array( 'radius', 'attendance_min', 'attendance_max' ), true ) ) {
				$clean[ $key ] = max( 0, (int) $val );
				continue;
			}
			if ( in_array( $key, array( 'lat', 'lng', 'fee_min', 'fee_max' ), true ) ) {
				$clean[ $key ] = is_numeric( $val ) ? (float) $val : null;
				continue;
			}
			if ( 'sort' === $key ) {
				$sort = sanitize_key( (string) $val );
				$clean[ $key ] = $sort ?: 'newest';
				continue;
			}
			$clean[ $key ] = sanitize_text_field( (string) $val );
		}

		return $clean;
	}

	/**
	 * Normalize filters for duplicate comparison.
	 *
	 * @param array $filters Filters.
	 */
	public static function normalize_filters_fingerprint( $filters ) {
		$filters = self::sanitize_filters( $filters );
		ksort( $filters );
		return wp_json_encode( $filters );
	}

	/**
	 * Per-vendor saved search limit.
	 */
	public static function limit_per_vendor() {
		$settings = lnkup_get_settings();
		$limit    = isset( $settings['saved_search_limit_per_vendor'] ) ? (int) $settings['saved_search_limit_per_vendor'] : 10;
		return max( 1, min( 25, $limit ) );
	}

	/**
	 * Default alert frequency from settings.
	 */
	public static function default_frequency() {
		$settings = lnkup_get_settings();
		$freq     = isset( $settings['saved_search_default_frequency'] ) ? sanitize_key( (string) $settings['saved_search_default_frequency'] ) : self::FREQ_DAILY;
		return in_array( $freq, array( self::FREQ_DAILY, self::FREQ_WEEKLY, self::FREQ_INSTANT ), true ) ? $freq : self::FREQ_DAILY;
	}

	/**
	 * Max matches per alert email.
	 */
	public static function max_matches_per_email() {
		$settings = lnkup_get_settings();
		$max      = isset( $settings['saved_search_max_matches_per_email'] ) ? (int) $settings['saved_search_max_matches_per_email'] : 5;
		return max( 1, min( 10, $max ) );
	}

	/**
	 * Whether alerts are enabled platform-wide.
	 */
	public static function alerts_enabled() {
		$settings = lnkup_get_settings();
		if ( isset( $settings['saved_search_alerts_enabled'] ) ) {
			return (bool) $settings['saved_search_alerts_enabled'];
		}
		return true;
	}

	/**
	 * Get all saved searches for user (excludes deleted).
	 *
	 * @param int $user_id User ID.
	 */
	public static function get_for_user( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}
		$searches = self::get_raw( $user_id );
		$out      = array();
		$changed  = false;
		$normalized_all = array();

		foreach ( $searches as $search ) {
			$normalized = self::normalize_row( $search, $user_id );
			$normalized_all[] = $normalized;
			if ( $normalized !== $search ) {
				$changed = true;
			}
			if ( self::STATUS_DELETED === ( $normalized['status'] ?? '' ) ) {
				continue;
			}
			$out[] = self::enrich_row( $normalized, $user_id );
		}

		if ( $changed ) {
			self::persist( $user_id, $normalized_all );
		}

		return $out;
	}

	/**
	 * Get single search by ID.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $search_id Search UUID.
	 */
	public static function get_by_id( $user_id, $search_id ) {
		$row = self::find_row( $user_id, $search_id );
		return $row ? self::enrich_row( $row, $user_id ) : null;
	}

	/**
	 * Normalized stored row without computed fields (enrich_row runs searches, so run() must not enrich).
	 *
	 * @param int    $user_id   User ID.
	 * @param string $search_id Search UUID.
	 */
	private static function find_row( $user_id, $search_id ) {
		foreach ( self::get_raw( $user_id ) as $search ) {
			if ( ( $search['id'] ?? '' ) === $search_id && self::STATUS_DELETED !== ( $search['status'] ?? '' ) ) {
				return self::normalize_row( $search, $user_id );
			}
		}
		return null;
	}

	/**
	 * Raw stored rows including deleted.
	 *
	 * @param int $user_id User ID.
	 */
	private static function get_raw( $user_id ) {
		$data = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Persist all rows.
	 *
	 * @param int   $user_id User ID.
	 * @param array $rows    Rows.
	 */
	private static function persist( $user_id, $rows ) {
		update_user_meta( $user_id, self::META_KEY, array_values( $rows ) );
	}

	/**
	 * Migrate legacy row to full schema.
	 *
	 * @param array $row     Row.
	 * @param int   $user_id User ID.
	 */
	private static function normalize_row( $row, $user_id ) {
		$now = current_time( 'mysql' );
		return array(
			'id'              => (string) ( $row['id'] ?? wp_generate_uuid4() ),
			'user_id'         => $user_id,
			'name'            => sanitize_text_field( (string) ( $row['name'] ?? __( 'Saved Search', 'lnkup-marketplace' ) ) ),
			'filters'         => self::sanitize_filters( (array) ( $row['filters'] ?? array() ) ),
			'alert_enabled'   => array_key_exists( 'alert_enabled', $row ) ? (bool) $row['alert_enabled'] : true,
			'frequency'       => in_array( $row['frequency'] ?? '', array( self::FREQ_DAILY, self::FREQ_WEEKLY, self::FREQ_INSTANT ), true )
				? $row['frequency']
				: self::default_frequency(),
			'status'          => in_array( $row['status'] ?? '', array( self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_DELETED ), true )
				? $row['status']
				: self::STATUS_ACTIVE,
			'last_checked_at' => $row['last_checked_at'] ?? null,
			'last_sent_at'    => $row['last_sent_at'] ?? null,
			'created_at'      => $row['created_at'] ?? ( $row['created'] ?? $now ),
			'updated_at'      => $row['updated_at'] ?? ( $row['created'] ?? $now ),
		);
	}

	/**
	 * Add computed fields for API/UI.
	 *
	 * @param array $row     Row.
	 * @param int   $user_id User ID.
	 */
	private static function enrich_row( $row, $user_id ) {
		$row['filter_summary'] = lnkup_build_active_filters( $row['filters'] ?? array() );
		$row['is_paused']      = self::STATUS_PAUSED === ( $row['status'] ?? '' );
		$row['new_match_count'] = self::count_new_matches( $user_id, $row );
		return $row;
	}

	/**
	 * Count new matches since last check.
	 *
	 * @param int   $user_id User ID.
	 * @param array $search  Search row.
	 */
	public static function count_new_matches( $user_id, $search ) {
		$created_after = $search['last_checked_at'] ?? $search['created_at'] ?? null;
		$result        = self::run( $user_id, $search['id'], array(
			'created_after' => $created_after,
			'per_page'      => 50,
			'count_only'    => false,
		) );
		if ( is_wp_error( $result ) ) {
			return 0;
		}
		return (int) ( $result['total'] ?? 0 );
	}

	/**
	 * Create saved search.
	 *
	 * @param int    $user_id User ID.
	 * @param string $name    Name.
	 * @param array  $filters Filters.
	 * @param array  $args    alert_enabled, frequency, force.
	 */
	public static function create( $user_id, $name, $filters, $args = array() ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new WP_Error( 'not_logged_in', __( 'You must be logged in to save searches.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}

		$active = array_filter(
			self::get_raw( $user_id ),
			function ( $s ) {
				return self::STATUS_DELETED !== ( $s['status'] ?? self::STATUS_ACTIVE );
			}
		);
		if ( count( $active ) >= self::limit_per_vendor() ) {
			return new WP_Error(
				'limit_reached',
				sprintf(
					/* translators: %d: max saved searches */
					__( 'You can save up to %d searches. Delete one to add another.', 'lnkup-marketplace' ),
					self::limit_per_vendor()
				),
				array( 'status' => 400 )
			);
		}

		$filters   = self::sanitize_filters( $filters );
		$fingerprint = self::normalize_filters_fingerprint( $filters );
		$force     = ! empty( $args['force'] );

		if ( ! $force ) {
			foreach ( $active as $existing ) {
				if ( self::STATUS_ACTIVE !== ( $existing['status'] ?? self::STATUS_ACTIVE ) ) {
					continue;
				}
				if ( self::normalize_filters_fingerprint( (array) ( $existing['filters'] ?? array() ) ) === $fingerprint ) {
					return new WP_Error(
						'duplicate_search',
						__( 'You already saved this search.', 'lnkup-marketplace' ),
						array(
							'status'      => 409,
							'existing_id' => $existing['id'] ?? '',
						)
					);
				}
			}
		}

		$now  = current_time( 'mysql' );
		$row  = array(
			'id'              => wp_generate_uuid4(),
			'user_id'         => $user_id,
			'name'            => sanitize_text_field( $name ?: __( 'All new opportunities', 'lnkup-marketplace' ) ),
			'filters'         => $filters,
			'alert_enabled'   => array_key_exists( 'alert_enabled', $args ) ? (bool) $args['alert_enabled'] : true,
			'frequency'       => in_array( $args['frequency'] ?? '', array( self::FREQ_DAILY, self::FREQ_WEEKLY, self::FREQ_INSTANT ), true )
				? $args['frequency']
				: self::default_frequency(),
			'status'          => self::STATUS_ACTIVE,
			'last_checked_at' => null,
			'last_sent_at'    => null,
			'created_at'      => $now,
			'updated_at'      => $now,
		);

		$rows   = self::get_raw( $user_id );
		$rows[] = $row;
		self::persist( $user_id, $rows );

		return self::enrich_row( $row, $user_id );
	}

	/**
	 * Update saved search.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $search_id Search ID.
	 * @param array  $data      Fields to update.
	 */
	public static function update( $user_id, $search_id, $data ) {
		$rows    = self::get_raw( $user_id );
		$found   = false;
		$updated = null;

		foreach ( $rows as $i => $row ) {
			if ( ( $row['id'] ?? '' ) !== $search_id ) {
				continue;
			}
			if ( self::STATUS_DELETED === ( $row['status'] ?? '' ) ) {
				return new WP_Error( 'not_found', __( 'Saved search not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}

			$row = self::normalize_row( $row, $user_id );

			if ( isset( $data['name'] ) ) {
				$row['name'] = sanitize_text_field( (string) $data['name'] );
			}
			if ( isset( $data['filters'] ) ) {
				$row['filters'] = self::sanitize_filters( (array) $data['filters'] );
			}
			if ( array_key_exists( 'alert_enabled', $data ) ) {
				$row['alert_enabled'] = (bool) $data['alert_enabled'];
			}
			if ( isset( $data['frequency'] ) ) {
				$freq = sanitize_key( (string) $data['frequency'] );
				if ( in_array( $freq, array( self::FREQ_DAILY, self::FREQ_WEEKLY, self::FREQ_INSTANT ), true ) ) {
					$row['frequency'] = $freq;
				}
			}
			if ( isset( $data['status'] ) ) {
				$status = sanitize_key( (string) $data['status'] );
				// delete() soft-deletes through here; without STATUS_DELETED the row stayed active.
				if ( in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_DELETED ), true ) ) {
					$row['status'] = $status;
				}
			}
			if ( isset( $data['last_checked_at'] ) ) {
				$row['last_checked_at'] = $data['last_checked_at'];
			}
			if ( isset( $data['last_sent_at'] ) ) {
				$row['last_sent_at'] = $data['last_sent_at'];
			}

			$row['updated_at'] = current_time( 'mysql' );
			$rows[ $i ]        = $row;
			$updated           = self::STATUS_DELETED === $row['status'] ? $row : self::enrich_row( $row, $user_id );
			$found             = true;
			break;
		}

		if ( ! $found ) {
			return new WP_Error( 'not_found', __( 'Saved search not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}

		self::persist( $user_id, $rows );
		return $updated;
	}

	/**
	 * Soft-delete saved search.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $search_id Search ID.
	 */
	public static function delete( $user_id, $search_id ) {
		return self::update( $user_id, $search_id, array( 'status' => self::STATUS_DELETED ) );
	}

	/**
	 * Run saved search and return matching opportunities.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $search_id Search ID.
	 * @param array  $args      created_after, per_page, page, count_only.
	 */
	public static function run( $user_id, $search_id, $args = array() ) {
		$search = self::find_row( $user_id, $search_id );
		if ( ! $search ) {
			return new WP_Error( 'not_found', __( 'Saved search not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}

		$filters = (array) ( $search['filters'] ?? array() );
		if ( empty( $filters['show_closed'] ) ) {
			$filters['has_spots']         = $filters['has_spots'] ?? true;
			$filters['applications_open'] = true;
		}

		if ( ! empty( $args['created_after'] ) ) {
			$filters['created_after'] = $args['created_after'];
		}

		$filters['per_page'] = isset( $args['per_page'] ) ? (int) $args['per_page'] : 24;
		$filters['page']     = isset( $args['page'] ) ? (int) $args['page'] : 1;

		$fetch_per_page = max( $filters['per_page'], 100 );
		$fetch_args     = array_merge( $filters, array( 'per_page' => $fetch_per_page, 'page' => 1 ) );
		$result         = LNKup_Discovery::search( $fetch_args );
		$accessible     = self::filter_accessible( $user_id, $result['items'] ?? array() );
		$total          = count( $accessible );
		$offset         = ( $filters['page'] - 1 ) * $filters['per_page'];
		$items          = array_slice( $accessible, $offset, $filters['per_page'] );

		return array(
			'items'          => $items,
			'total'          => $total,
			'page'           => $filters['page'],
			'per_page'       => $filters['per_page'],
			'pages'          => (int) ceil( $total / max( 1, $filters['per_page'] ) ),
			'filter_summary' => lnkup_build_active_filters( $search['filters'] ?? array() ),
			'search'         => $search,
		);
	}

	/**
	 * Remove opportunities vendor cannot discover.
	 *
	 * @param int   $user_id User ID.
	 * @param array $items   Opportunity items.
	 */
	public static function filter_accessible( $user_id, $items ) {
		$out = array();
		foreach ( (array) $items as $item ) {
			$opp_id = (int) ( $item['opportunity_id'] ?? $item['id'] ?? 0 );
			if ( $opp_id && lnkup_vendor_can_discover_opportunity( $user_id, $opp_id ) ) {
				if ( ! self::vendor_has_application( $user_id, $opp_id ) ) {
					$out[] = $item;
				}
			}
		}
		return $out;
	}

	/**
	 * Whether vendor already applied to opportunity.
	 *
	 * @param int $user_id User ID.
	 * @param int $opp_id  Opportunity ID.
	 */
	public static function vendor_has_application( $user_id, $opp_id ) {
		$existing = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_lnkup_opportunity_id', 'value' => absint( $opp_id ) ),
					array( 'key' => '_lnkup_vendor_user_id', 'value' => absint( $user_id ) ),
				),
			)
		);
		return ! empty( $existing );
	}

	/**
	 * Get active alert-enabled searches for cron.
	 *
	 * @param string|null $frequency Filter by frequency.
	 */
	public static function get_alert_candidates( $frequency = null ) {
		$vendors = get_users( array( 'role' => 'lnkup_vendor', 'fields' => 'ID' ) );
		$out     = array();

		foreach ( $vendors as $user_id ) {
			foreach ( self::get_raw( $user_id ) as $row ) {
				$row = self::normalize_row( $row, $user_id );
				if ( self::STATUS_ACTIVE !== $row['status'] || ! $row['alert_enabled'] ) {
					continue;
				}
				if ( $frequency && $row['frequency'] !== $frequency ) {
					continue;
				}
				$out[] = array(
					'user_id' => (int) $user_id,
					'search'  => $row,
				);
			}
		}
		return $out;
	}

	/**
	 * Mark search checked/sent timestamps.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $search_id Search ID.
	 * @param bool   $sent      Whether email was sent.
	 */
	public static function touch_alert_timestamps( $user_id, $search_id, $sent = false ) {
		$now  = current_time( 'mysql' );
		$data = array( 'last_checked_at' => $now );
		if ( $sent ) {
			$data['last_sent_at'] = $now;
		}
		return self::update( $user_id, $search_id, $data );
	}

	/**
	 * Check if instant alert was already sent today for search+opp.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $search_id Search ID.
	 * @param int    $opp_id    Opportunity ID.
	 */
	public static function instant_alert_sent_today( $user_id, $search_id, $opp_id ) {
		$key = 'lnkup_instant_alert_' . md5( $search_id . '_' . $opp_id );
		return (bool) get_user_meta( $user_id, $key, true );
	}

	/**
	 * Record instant alert sent today.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $search_id Search ID.
	 * @param int    $opp_id    Opportunity ID.
	 */
	public static function mark_instant_alert_sent( $user_id, $search_id, $opp_id ) {
		$key = 'lnkup_instant_alert_' . md5( $search_id . '_' . $opp_id );
		update_user_meta( $user_id, $key, gmdate( 'Y-m-d' ) );
	}

	/**
	 * Whether weekly search is due.
	 *
	 * @param array $search Search row.
	 */
	public static function is_weekly_due( $search ) {
		$last = $search['last_sent_at'] ?? null;
		if ( ! $last ) {
			return true;
		}
		return strtotime( $last ) < strtotime( '-7 days' );
	}
}
