<?php
/**
 * Event vendor-slot fulfillment: capacity, holds, payments, remaining spots by
 * opportunity and by vendor category, review backlog, invitation outcomes,
 * deadline proximity and the next useful organizer actions.
 *
 * "Filled" follows LNKup_Opportunity_Inventory: an approved application holds a
 * spot; a submitted application never does. Payment state comes from the
 * application status that the financial flow maintains — no parallel ledger.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Fulfillment {

	const NS            = 'lnkup/v1';
	const CACHE_PREFIX  = 'lnkup_fulfill_';
	const CACHE_TTL     = 300;
	const PENDING       = array( 'submitted', 'under_review', 'info_requested' );
	const UNPAID        = array( 'approved', 'payment_required' );
	const PAID          = array( 'paid', 'completed' );
	const DEADLINE_SOON = 7;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'flush_for_application' ), 20 );
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'flush_for_application' ), 20 );
		add_action( 'save_post_lnkup_opportunity', array( __CLASS__, 'flush_for_opportunity' ), 20 );
		add_action( 'save_post_lnkup_event', array( __CLASS__, 'flush' ), 20 );
		add_action( 'lnkup_vendor_invite_created', array( __CLASS__, 'flush_for_invite' ), 20 );
		add_action( 'lnkup_vendor_invite_status_changed', array( __CLASS__, 'flush_for_invite' ), 20 );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/organizer/events/(?P<event_id>\d+)/fulfillment',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_get' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * Owner, event staff with roster access, or platform admin.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function permission( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$event_id = absint( $request['event_id'] );
		if ( ! $user_id || 'lnkup_event' !== get_post_type( $event_id ) ) {
			return false;
		}
		return LNKup_Capabilities::user_has_manage_all( $user_id ) || LNKup_Capabilities::staff_can( $event_id, 'view_roster', $user_id );
	}

	public static function rest_get( WP_REST_Request $request ) {
		try {
			return rest_ensure_response( self::for_event( absint( $request['event_id'] ) ) );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup fulfillment failed: ' . $e->getMessage() );
			return new WP_Error( 'lnkup_fulfillment_unavailable', __( 'Vendor slot details are temporarily unavailable. Please try again.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
	}

	// ------------------------------------------------------------------ Cache.

	public static function flush( $event_id ) {
		delete_transient( self::CACHE_PREFIX . absint( $event_id ) );
	}

	public static function flush_for_application( $app_id ) {
		self::flush( (int) get_post_meta( absint( $app_id ), '_lnkup_event_id', true ) );
	}

	public static function flush_for_opportunity( $opp_id ) {
		self::flush( (int) get_post_meta( absint( $opp_id ), '_lnkup_event_id', true ) );
	}

	public static function flush_for_invite( $invite_id ) {
		self::flush( (int) get_post_meta( absint( $invite_id ), '_lnkup_event_id', true ) );
	}

	// ------------------------------------------------------------------ Read.

	/**
	 * Fulfillment for one event (cached for CACHE_TTL; flushed on every relevant write).
	 *
	 * @param int  $event_id Event ID.
	 * @param bool $fresh    Skip the cache (background jobs that must see live state).
	 * @return array<string, mixed>
	 */
	public static function for_event( $event_id, $fresh = false ) {
		$event_id = absint( $event_id );
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_PREFIX . $event_id );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$data = self::compute( $event_id );
		set_transient( self::CACHE_PREFIX . $event_id, $data, self::CACHE_TTL );
		return $data;
	}

	private static function compute( $event_id ) {
		$organizer_id = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		$event_date   = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$today        = current_time( 'Y-m-d' );
		$event_status = (string) get_post_meta( $event_id, '_lnkup_event_status', true );
		$apps_by_opp  = self::application_counts( $event_id );

		$opportunities = array();
		$categories    = array();
		$totals        = array(
			'opportunities'      => 0,
			'open_opportunities' => 0,
			'capacity'           => 0,
			'unlimited'          => 0,
			'held'               => 0,
			'paid'               => 0,
			'awaiting_payment'   => 0,
			'remaining'          => 0,
			'pending_review'     => 0,
			'waitlisted'         => 0,
			'applications'       => 0,
		);
		$next_deadline = '';

		foreach ( self::opportunity_ids( $event_id ) as $opp_id ) {
			$counts    = $apps_by_opp[ $opp_id ] ?? array();
			$capacity  = LNKup_Opportunity_Inventory::capacity( $opp_id );
			$held      = self::sum( $counts, LNKup_Opportunity_Inventory::HOLD_STATUSES );
			$remaining = $capacity ? max( 0, $capacity - $held ) : null;
			$deadline  = trim( (string) get_post_meta( $opp_id, '_lnkup_application_deadline', true ) );
			$deadline  = '' !== $deadline ? substr( $deadline, 0, 10 ) : '';
			$status    = (string) get_post_meta( $opp_id, '_lnkup_opportunity_status', true );
			$is_open   = 'publish' === get_post_status( $opp_id )
				&& in_array( $status, array( '', 'open' ), true )
				&& ( '' === $deadline || $deadline >= $today )
				&& ( '' === $event_date || $event_date >= $today )
				&& ( null === $remaining || $remaining > 0 );
			$terms     = wp_get_object_terms( $opp_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			$terms     = is_wp_error( $terms ) ? array() : array_values( array_map( 'strval', $terms ) );
			$row       = array(
				'opportunity_id'   => $opp_id,
				'title'            => lnkup_plain_title( get_post( $opp_id ) ),
				'status'           => '' !== $status ? $status : 'open',
				'is_open'          => $is_open,
				'categories'       => $terms,
				'capacity'         => $capacity,
				'held'             => $held,
				'paid'             => self::sum( $counts, self::PAID ),
				'awaiting_payment' => self::sum( $counts, self::UNPAID ),
				'remaining'        => $remaining,
				'pending_review'   => self::sum( $counts, self::PENDING ),
				'waitlisted'       => self::sum( $counts, array( 'waitlisted' ) ),
				'applications'     => array_sum( $counts ),
				'deadline'         => $deadline,
				'days_to_deadline' => '' !== $deadline ? self::days_between( $today, $deadline ) : null,
				'vendor_fee'       => (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ),
			);
			$opportunities[] = $row;

			++$totals['opportunities'];
			$totals['open_opportunities'] += $is_open ? 1 : 0;
			$totals['capacity']           += $capacity;
			$totals['unlimited']          += $capacity ? 0 : 1;
			$totals['remaining']          += (int) $remaining;
			foreach ( array( 'held', 'paid', 'awaiting_payment', 'pending_review', 'waitlisted', 'applications' ) as $key ) {
				$totals[ $key ] += $row[ $key ];
			}
			if ( $is_open && '' !== $deadline && ( '' === $next_deadline || $deadline < $next_deadline ) ) {
				$next_deadline = $deadline;
			}

			foreach ( $terms ? $terms : array( '' ) as $name ) {
				$key = '' === $name ? '_any' : sanitize_title( $name );
				if ( ! isset( $categories[ $key ] ) ) {
					$categories[ $key ] = array(
						'key'       => $key,
						'label'     => '' === $name ? __( 'Any category', 'lnkup-marketplace' ) : $name,
						'capacity'  => 0,
						'held'      => 0,
						'paid'      => 0,
						'remaining' => 0,
						'unlimited' => false,
						'open'      => false,
					);
				}
				// A slot listing several categories counts once per category; category rows show where demand can land, totals do not double count.
				$categories[ $key ]['capacity']  += $capacity;
				$categories[ $key ]['held']      += $held;
				$categories[ $key ]['paid']      += $row['paid'];
				$categories[ $key ]['remaining'] += (int) $remaining;
				$categories[ $key ]['unlimited']  = $categories[ $key ]['unlimited'] || ! $capacity;
				$categories[ $key ]['open']       = $categories[ $key ]['open'] || $is_open;
			}
		}

		$invites            = self::invite_counts( $event_id );
		$invite_total       = array_sum( $invites );
		$invite_apps        = self::invite_application_count( $event_id );
		$matching           = self::matching_vendor_count( $event_id, $organizer_id, $totals['open_opportunities'] );
		$applied_or_decided = $totals['applications'];

		$data = array(
			'event_id'            => $event_id,
			'event_title'         => lnkup_plain_title( get_post( $event_id ) ),
			'event_status'        => '' !== $event_status ? $event_status : 'draft',
			'event_date'          => $event_date,
			'days_to_event'       => '' !== $event_date ? self::days_between( $today, $event_date ) : null,
			'next_deadline'       => $next_deadline,
			'days_to_deadline'    => '' !== $next_deadline ? self::days_between( $today, $next_deadline ) : null,
			'totals'              => $totals,
			'fill_rate'           => $totals['capacity'] > 0 ? round( min( $totals['held'], $totals['capacity'] ) / $totals['capacity'], 3 ) : null,
			'categories'          => array_values( $categories ),
			'opportunities'       => $opportunities,
			'invites'             => array(
				'by_status'          => $invites,
				'total'              => $invite_total,
				'applications'       => $invite_apps,
				'application_rate'   => $invite_total > 0 ? round( $invite_apps / $invite_total, 3 ) : null,
			),
			'conversion'          => array(
				'application_to_confirmed' => $applied_or_decided > 0 ? round( $totals['paid'] / $applied_or_decided, 3 ) : null,
			),
			'matching_vendors'    => $matching,
			'generated_at'        => gmdate( 'c' ),
		);
		$data['actions'] = self::actions( $data );
		return $data;
	}

	/**
	 * Ranked organizer actions grounded in the numbers above (no invented urgency).
	 *
	 * @param array $data Fulfillment data.
	 * @return array<int, array{key:string,priority:int,label:string,cta:string,view:string}>
	 */
	public static function actions( array $data ) {
		$t       = $data['totals'];
		$actions = array();
		$past    = null !== $data['days_to_event'] && $data['days_to_event'] < 0;
		if ( $past || 'cancelled' === $data['event_status'] ) {
			return array();
		}
		if ( 0 === $t['opportunities'] ) {
			$actions[] = array( 'key' => 'add_opportunity', 'priority' => 100, 'label' => __( 'Add a vendor opportunity so businesses can apply.', 'lnkup-marketplace' ), 'cta' => __( 'Manage opportunities', 'lnkup-marketplace' ), 'view' => 'events' );
			return $actions;
		}
		if ( $t['pending_review'] > 0 ) {
			/* translators: %d: count */
			$actions[] = array( 'key' => 'review_applications', 'priority' => 90, 'label' => sprintf( _n( '%d application is awaiting your review.', '%d applications are awaiting your review.', $t['pending_review'], 'lnkup-marketplace' ), $t['pending_review'] ), 'cta' => __( 'Review applications', 'lnkup-marketplace' ), 'view' => 'applications' );
		}
		$has_room = $t['open_opportunities'] > 0 && ( $t['remaining'] > 0 || $t['unlimited'] > 0 );
		if ( $has_room && null !== $data['days_to_deadline'] && $data['days_to_deadline'] <= self::DEADLINE_SOON ) {
			/* translators: %d: days */
			$actions[] = array( 'key' => 'deadline', 'priority' => 80, 'label' => sprintf( _n( 'Applications close in %d day.', 'Applications close in %d days.', max( 0, $data['days_to_deadline'] ), 'lnkup-marketplace' ), max( 0, $data['days_to_deadline'] ) ), 'cta' => __( 'Invite vendors', 'lnkup-marketplace' ), 'view' => 'find_vendors' );
		}
		if ( $has_room && $data['matching_vendors'] > 0 ) {
			/* translators: %d: count */
			$actions[] = array( 'key' => 'find_vendors', 'priority' => 70, 'label' => sprintf( _n( 'We found %d vendor matching your open slots.', 'We found %d vendors matching your open slots.', $data['matching_vendors'], 'lnkup-marketplace' ), $data['matching_vendors'] ), 'cta' => __( 'Find matching vendors', 'lnkup-marketplace' ), 'view' => 'find_vendors' );
		}
		if ( $has_room && 0 === $t['applications'] ) {
			$actions[] = array( 'key' => 'share_link', 'priority' => 60, 'label' => __( 'No applications yet. Share your vendor application link or invite vendors directly.', 'lnkup-marketplace' ), 'cta' => __( 'Share vendor applications', 'lnkup-marketplace' ), 'view' => 'events' );
		}
		if ( $t['awaiting_payment'] > 0 ) {
			/* translators: %d: count */
			$actions[] = array( 'key' => 'awaiting_payment', 'priority' => 50, 'label' => sprintf( _n( '%d approved vendor has not completed payment yet.', '%d approved vendors have not completed payment yet.', $t['awaiting_payment'], 'lnkup-marketplace' ), $t['awaiting_payment'] ), 'cta' => __( 'View vendors', 'lnkup-marketplace' ), 'view' => 'applications' );
		}
		if ( ! $has_room && $t['capacity'] > 0 && 0 === $t['unlimited'] ) {
			$actions[] = array( 'key' => 'full', 'priority' => 10, 'label' => __( 'Every vendor slot is filled.', 'lnkup-marketplace' ), 'cta' => __( 'View event readiness', 'lnkup-marketplace' ), 'view' => 'operations' );
		}
		usort(
			$actions,
			static function ( $a, $b ) {
				return $b['priority'] <=> $a['priority'];
			}
		);
		return $actions;
	}

	// ------------------------------------------------------------------ Queries.

	/**
	 * @return int[]
	 */
	private static function opportunity_ids( $event_id ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'lnkup_opportunity',
					'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => (string) $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	/**
	 * Application counts per opportunity and status for one event (one query).
	 *
	 * @return array<int, array<string, int>>
	 */
	private static function application_counts( $event_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.meta_value AS opp, COALESCE(s.meta_value, '') AS status, COUNT(DISTINCT p.ID) AS c
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_lnkup_event_id' AND e.meta_value = %s
				INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_opportunity_id'
				LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_application_status'
				WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft')
				GROUP BY o.meta_value, s.meta_value",
				(string) $event_id
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $row ) {
			$status = '' !== (string) $row['status'] ? (string) $row['status'] : 'submitted';
			$opp    = (int) $row['opp'];
			$out[ $opp ][ $status ] = ( $out[ $opp ][ $status ] ?? 0 ) + (int) $row['c'];
		}
		return $out;
	}

	/**
	 * @return array<string, int>
	 */
	private static function invite_counts( $event_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.meta_value AS status, COUNT(DISTINCT p.ID) AS c FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_lnkup_event_id' AND e.meta_value = %s
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_status'
				WHERE p.post_type = 'lnkup_vendor_invite' AND p.post_status = 'publish'
				GROUP BY s.meta_value",
				(string) $event_id
			),
			ARRAY_A
		);
		$out = array_fill_keys( array( 'sent', 'opened', 'accepted', 'declined', 'expired' ), 0 );
		foreach ( $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['c'];
		}
		return $out;
	}

	private static function invite_application_count( $event_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_lnkup_event_id' AND e.meta_value = %s
				INNER JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = '_lnkup_invite_id' AND i.meta_value > 0
				WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft')",
				(string) $event_id
			)
		);
	}

	/**
	 * Discoverable (or already related) vendors recommended for this event's open slots; 0 when matching is off.
	 */
	private static function matching_vendor_count( $event_id, $organizer_id, $open_opportunities ) {
		if ( ! $organizer_id || $open_opportunities < 1 || ! class_exists( 'LNKup_Match_Settings' ) || ! LNKup_Match_Settings::active() ) {
			return 0;
		}
		return LNKup_Match_Service::discoverable_match_count( $event_id, $organizer_id );
	}

	private static function sum( array $counts, array $statuses ) {
		$total = 0;
		foreach ( $statuses as $status ) {
			$total += (int) ( $counts[ $status ] ?? 0 );
		}
		return $total;
	}

	private static function days_between( $from, $to ) {
		$a = date_create_immutable( $from );
		$b = date_create_immutable( substr( (string) $to, 0, 10 ) );
		if ( ! $a || ! $b ) {
			return null;
		}
		return (int) $a->diff( $b )->format( '%r%a' );
	}
}
