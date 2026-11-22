<?php
/**
 * Minimal tours & event series.
 *
 * A tour/series (lnkup_tour) groups existing lnkup_event records as stops or
 * occurrences via `_lnkup_tour_id`. Inventory, applications, approvals and
 * payments stay on each event/opportunity; nothing is combined across stops.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Tours {

	const TYPES     = array( 'tour', 'series' );
	const MAX_STOPS = 60;

	/**
	 * Summary cache per request.
	 *
	 * @var array<int,array|null>
	 */
	private static $summaries = array();

	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'on_tour_status' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
	}

	/**
	 * Tour publish/unpublish changes which index rows carry tour_id.
	 */
	public static function on_tour_status( $new_status, $old_status, $post ) {
		if ( 'lnkup_tour' === $post->post_type && $new_status !== $old_status ) {
			self::reindex_stops( $post->ID );
		}
	}

	public static function on_delete( $post_id ) {
		if ( 'lnkup_tour' !== get_post_type( $post_id ) ) {
			return;
		}
		foreach ( self::stop_event_ids( $post_id ) as $event_id ) {
			delete_post_meta( $event_id, '_lnkup_tour_id' );
			delete_post_meta( $event_id, '_lnkup_tour_stop_order' );
			self::reindex_event( $event_id );
		}
	}

	/**
	 * Event IDs attached to a tour (any status).
	 *
	 * @param int $tour_id Tour ID.
	 * @return int[]
	 */
	public static function stop_event_ids( $tour_id ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'lnkup_event',
					'post_status'    => 'any',
					'posts_per_page' => self::MAX_STOPS,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_lnkup_tour_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => (int) $tour_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	private static function reindex_stops( $tour_id ) {
		foreach ( self::stop_event_ids( $tour_id ) as $event_id ) {
			self::reindex_event( $event_id );
		}
	}

	private static function reindex_event( $event_id ) {
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (int) $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $opps as $opp_id ) {
			LNKup_Marketplace_Index::sync_opportunity( (int) $opp_id );
		}
	}

	/**
	 * Create or update a tour owned by an organizer.
	 *
	 * @param int   $organizer_id Organizer user ID.
	 * @param array $data         title, description, type, status, event_ids.
	 * @param int   $tour_id      Existing tour (0 = create).
	 * @return array|WP_Error
	 */
	public static function save( $organizer_id, array $data, $tour_id = 0 ) {
		$tour_id = (int) $tour_id;
		if ( $tour_id && ! self::owns( $tour_id, $organizer_id ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You can only edit your own tours.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		if ( ! $tour_id && '' === $title ) {
			return new WP_Error( 'lnkup_missing_title', __( 'Give your tour or series a name.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$type   = sanitize_key( (string) ( $data['type'] ?? ( $tour_id ? get_post_meta( $tour_id, '_lnkup_tour_type', true ) : '' ) ?: 'tour' ) );
		$status = sanitize_key( (string) ( $data['status'] ?? ( $tour_id ? get_post_status( $tour_id ) : '' ) ?: 'publish' ) );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'lnkup_invalid_type', __( 'Choose tour or series.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
			$status = 'draft';
		}

		$event_ids = null;
		if ( array_key_exists( 'event_ids', $data ) ) {
			$event_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) $data['event_ids'] ) ) ) ), 0, self::MAX_STOPS );
			foreach ( $event_ids as $event_id ) {
				if ( ! LNKup_Capabilities::organizer_owns_event( $event_id, $organizer_id ) ) {
					return new WP_Error( 'lnkup_forbidden', __( 'You can only add your own events to a tour.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
				}
				$current = (int) get_post_meta( $event_id, '_lnkup_tour_id', true );
				if ( $current && $current !== $tour_id && 'lnkup_tour' === get_post_type( $current ) ) {
					return new WP_Error( 'lnkup_event_in_tour', sprintf( __( '"%s" already belongs to another tour.', 'lnkup-marketplace' ), get_the_title( $event_id ) ), array( 'status' => 409 ) );
				}
			}
		}

		$postarr = array(
			'post_type'   => 'lnkup_tour',
			'post_status' => $status,
		);
		if ( '' !== $title ) {
			$postarr['post_title'] = $title;
		}
		if ( array_key_exists( 'description', $data ) ) {
			$postarr['post_content'] = sanitize_textarea_field( (string) $data['description'] );
		}
		if ( $tour_id ) {
			$postarr['ID'] = $tour_id;
			$result        = wp_update_post( $postarr, true );
		} else {
			$postarr['post_author'] = (int) $organizer_id;
			$result                 = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'lnkup_tour_save_failed', __( 'We could not save this tour.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		$tour_id = (int) $result;
		if ( ! get_post_meta( $tour_id, '_lnkup_organizer_user_id', true ) ) {
			update_post_meta( $tour_id, '_lnkup_organizer_user_id', (int) $organizer_id );
		}
		update_post_meta( $tour_id, '_lnkup_tour_type', $type );

		if ( null !== $event_ids ) {
			foreach ( self::stop_event_ids( $tour_id ) as $existing ) {
				if ( ! in_array( $existing, $event_ids, true ) ) {
					delete_post_meta( $existing, '_lnkup_tour_id' );
					delete_post_meta( $existing, '_lnkup_tour_stop_order' );
					self::reindex_event( $existing );
				}
			}
			foreach ( $event_ids as $order => $event_id ) {
				update_post_meta( $event_id, '_lnkup_tour_id', $tour_id );
				update_post_meta( $event_id, '_lnkup_tour_stop_order', $order + 1 );
				self::reindex_event( $event_id );
			}
		}
		unset( self::$summaries[ $tour_id ] );
		return self::detail( $tour_id, $organizer_id );
	}

	/**
	 * Whether the user owns the tour.
	 */
	public static function owns( $tour_id, $user_id ) {
		if ( 'lnkup_tour' !== get_post_type( $tour_id ) ) {
			return false;
		}
		return LNKup_Capabilities::user_has_manage_all( $user_id ) || (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true ) === (int) $user_id;
	}

	/**
	 * Tours owned by an organizer.
	 */
	public static function list_for_organizer( $organizer_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_tour',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (int) $organizer_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$items = array();
		foreach ( $ids as $id ) {
			$summary                = self::card_summary( (int) $id, true );
			$summary['status']      = get_post_status( $id );
			$summary['event_ids']   = self::stop_event_ids( (int) $id );
			$items[]                = $summary;
		}
		return $items;
	}

	/**
	 * Small tour descriptor for cards (published tours only unless $any).
	 *
	 * @param int  $tour_id Tour ID.
	 * @param bool $any     Include drafts.
	 * @return array|null
	 */
	public static function card_summary( $tour_id, $any = false ) {
		$tour_id = (int) $tour_id;
		if ( ! $tour_id ) {
			return null;
		}
		if ( ! $any && array_key_exists( $tour_id, self::$summaries ) ) {
			return self::$summaries[ $tour_id ];
		}
		$post = get_post( $tour_id );
		if ( ! $post || 'lnkup_tour' !== $post->post_type || ( ! $any && 'publish' !== $post->post_status ) ) {
			self::$summaries[ $tour_id ] = null;
			return null;
		}
		$summary = array(
			'id'         => $tour_id,
			'title'      => $post->post_title,
			'type'       => (string) ( get_post_meta( $tour_id, '_lnkup_tour_type', true ) ?: 'tour' ),
			'stop_count' => count( self::stop_event_ids( $tour_id ) ),
		);
		if ( ! $any ) {
			self::$summaries[ $tour_id ] = $summary;
		}
		return $summary;
	}

	/**
	 * Tour detail: per-stop availability, pricing and (for vendors) match status.
	 *
	 * @param int $tour_id Tour ID.
	 * @param int $user_id Viewer.
	 * @return array|WP_Error
	 */
	public static function detail( $tour_id, $user_id = 0 ) {
		$tour_id = (int) $tour_id;
		$post    = get_post( $tour_id );
		$owner   = $user_id && self::owns( $tour_id, $user_id );
		if ( ! $post || 'lnkup_tour' !== $post->post_type || ( 'publish' !== $post->post_status && ! $owner ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Tour not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}

		global $wpdb;
		$index     = LNKup_Marketplace_Index::table_name();
		$event_ids = self::stop_event_ids( $tour_id );
		$rows      = $event_ids ? (array) $wpdb->get_results( 'SELECT * FROM ' . $index . ' WHERE event_id IN (' . implode( ',', $event_ids ) . ')', ARRAY_A ) : array(); // phpcs:ignore WordPress.DB
		$by_event  = array();
		foreach ( $rows as $row ) {
			$by_event[ (int) $row['event_id'] ][] = $row;
		}

		$profile_id = $user_id && LNKup_Roles::is_vendor( $user_id ) ? (int) lnkup_get_vendor_profile_id( $user_id ) : 0;
		$matched    = array();
		$applied    = array();
		if ( $profile_id && $rows ) {
			$opp_ids = array_map( 'intval', wp_list_pluck( $rows, 'opportunity_id' ) );
			$matched = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT opportunity_id FROM ' . LNKup_Match_Tables::results_table() . ' WHERE profile_id = %d AND eligible = 1 AND opportunity_id IN (' . implode( ',', $opp_ids ) . ')', $profile_id ) ) ); // phpcs:ignore WordPress.DB
			$newest = array();
			foreach ( lnkup_get_applications_for_user( $user_id, 'vendor' ) as $app ) {
				$app_opp = (int) get_post_meta( $app->ID, '_lnkup_opportunity_id', true );
				// The newest application per slot decides the status (older withdrawn ones must not mask it).
				if ( ( $newest[ $app_opp ] ?? 0 ) < (int) $app->ID ) {
					$newest[ $app_opp ]  = (int) $app->ID;
					$applied[ $app_opp ] = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
				}
			}
		}

		$today = current_time( 'Y-m-d' );
		$stops = array();
		foreach ( $event_ids as $event_id ) {
			if ( 'publish' !== get_post_status( $event_id ) && ! $owner ) {
				continue;
			}
			$opps  = array();
			$fees  = array();
			$open  = false;
			$match = false;
			foreach ( $by_event[ $event_id ] ?? array() as $row ) {
				$opp_id  = (int) $row['opportunity_id'];
				$unlimited = (int) $row['spots_total'] <= 0;
				$is_open   = ( $unlimited || (int) $row['spots_remaining'] > 0 ) && ( empty( $row['deadline'] ) || $row['deadline'] >= $today ) && ( empty( $row['event_date'] ) || $row['event_date'] >= $today );
				if ( $profile_id && ! lnkup_vendor_can_discover_opportunity( $user_id, $opp_id ) ) {
					continue;
				}
				if ( ! $owner && ! $profile_id && get_post_meta( $opp_id, '_lnkup_invite_only', true ) ) {
					continue;
				}
				$fees[]     = (float) $row['vendor_fee'];
				$open       = $open || $is_open;
				$match      = $match || ( $is_open && in_array( $opp_id, $matched, true ) );
				$app_status = $applied[ $opp_id ] ?? '';
				$again      = 'cancelled' === $app_status
					|| ( 'rejected' === $app_status && 'allowed' === ( LNKup_Applications::reapply_state( $opp_id, $user_id )['reapply'] ?? '' ) );
				$opps[]     = array(
					'opportunity_id'     => $opp_id,
					'title'              => get_the_title( $opp_id ),
					'vendor_fee'         => (float) $row['vendor_fee'],
					'spots_remaining'    => (int) $row['spots_remaining'],
					'spots_unlimited'    => $unlimited,
					'deadline'           => $row['deadline'],
					'is_open'            => $is_open,
					'matches_me'         => in_array( $opp_id, $matched, true ),
					'application_status' => $app_status,
					'can_apply_again'    => $again,
					'apply_url'          => add_query_arg( array( 'apply' => $opp_id ), lnkup_page_url_helper( 'opportunities' ) ),
				);
			}
			$stops[] = array(
				'event_id'      => $event_id,
				'title'         => get_the_title( $event_id ),
				'order'         => (int) get_post_meta( $event_id, '_lnkup_tour_stop_order', true ),
				'date'          => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
				'city'          => (string) get_post_meta( $event_id, '_lnkup_city', true ),
				'state'         => (string) get_post_meta( $event_id, '_lnkup_state', true ),
				'accepting'     => $open,
				'matches_me'    => $match,
				'fee_min'       => $fees ? min( $fees ) : null,
				'fee_max'       => $fees ? max( $fees ) : null,
				'opportunities' => $opps,
			);
		}
		usort(
			$stops,
			static function ( $a, $b ) {
				return array( $a['date'] ?: '9999', $a['order'] ) <=> array( $b['date'] ?: '9999', $b['order'] );
			}
		);

		$organizer_id = (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true );
		$organizer    = $organizer_id ? get_userdata( $organizer_id ) : null;
		return array(
			'id'             => $tour_id,
			'title'          => $post->post_title,
			'description'    => wp_strip_all_tags( (string) $post->post_content ),
			'type'           => (string) ( get_post_meta( $tour_id, '_lnkup_tour_type', true ) ?: 'tour' ),
			'status'         => $post->post_status,
			'organizer_name' => $organizer ? $organizer->display_name : '',
			'stops'          => $stops,
			'stop_count'     => count( $stops ),
			'matching_stops' => count( array_filter( wp_list_pluck( $stops, 'matches_me' ) ) ),
			'is_owner'       => $owner,
		);
	}

	/**
	 * Vendor "Tours accepting vendors" / "Recurring markets" section: one card per tour
	 * listing only the stops that match the vendor.
	 *
	 * @param array  $vendor   Vendor input (LNKup_Match_Service::vendor_inputs()).
	 * @param string $section  tours|series.
	 * @param int    $page     Page.
	 * @param int    $per_page Per page.
	 */
	public static function vendor_tour_section( array $vendor, $section, $page, $per_page ) {
		global $wpdb;
		$results = LNKup_Match_Tables::results_table();
		$index   = LNKup_Marketplace_Index::table_name();
		$today   = current_time( 'Y-m-d' );
		$type    = 'series' === $section ? 'series' : 'tour';
		$where   = "r.profile_id = %d AND r.eligible = 1 AND m.tour_id > 0 AND (m.spots_remaining > 0 OR m.spots_total <= 0) AND (m.deadline IS NULL OR m.deadline >= %s) AND (m.event_date IS NULL OR m.event_date >= %s)
			AND m.tour_id IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_lnkup_tour_type' AND meta_value = %s)";
		$params  = array( (int) $vendor['profile_id'], $today, $today, $type );
		$from    = "FROM {$results} r INNER JOIN {$index} m ON m.opportunity_id = r.opportunity_id WHERE {$where}";
		$total   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.tour_id) {$from}", $params ) ); // phpcs:ignore WordPress.DB
		$rows    = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT m.tour_id, COUNT(DISTINCT m.event_id) AS matching_stops, MIN(m.event_date) AS next_date, MIN(m.vendor_fee) AS fee_min, MAX(m.vendor_fee) AS fee_max, MAX(r.score) AS score,
				GROUP_CONCAT(DISTINCT CONCAT(m.city, ', ', m.state) ORDER BY m.event_date SEPARATOR '|') AS places {$from} GROUP BY m.tour_id ORDER BY score DESC, next_date ASC LIMIT %d OFFSET %d",
				array_merge( $params, array( (int) $per_page, ( (int) $page - 1 ) * (int) $per_page ) )
			),
			ARRAY_A
		);
		$items = array();
		foreach ( $rows as $row ) {
			$summary = self::card_summary( (int) $row['tour_id'] );
			if ( ! $summary ) {
				continue;
			}
			$items[] = array_merge(
				$summary,
				array(
					'kind'           => 'tour',
					'matching_stops' => (int) $row['matching_stops'],
					'next_date'      => $row['next_date'],
					'places'         => array_values( array_filter( explode( '|', (string) $row['places'] ) ) ),
					'fee_min'        => (float) $row['fee_min'],
					'fee_max'        => (float) $row['fee_max'],
					'match'          => array(
						'band'    => LNKup_Match_Engine::band( (int) $row['score'] ),
						'reasons' => array( sprintf( _n( '%d stop matches your markets', '%d stops match your markets', (int) $row['matching_stops'], 'lnkup-marketplace' ), (int) $row['matching_stops'] ) ),
					),
				)
			);
		}
		return array(
			'section' => $section,
			'items'   => $items,
			'total'   => $total,
			'pages'   => (int) ceil( $total / max( 1, (int) $per_page ) ),
		);
	}
}
