<?php
/**
 * Organizer presentation contracts: canonical event identity, capacity, money and
 * action queues. Read-only; every figure comes from existing CPTs, meta and services.
 * Sources and scopes are documented in docs/organizer-experience-v2/DATA_INTEGRITY.md.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Shared contract for organizer screens and REST payloads.
 */
class LNKup_Organizer_Presentation {

	const PENDING_STATUSES   = array( 'submitted', 'under_review' );
	const HELD_STATUSES      = array( 'approved', 'payment_required' );
	const CONFIRMED_STATUSES = array( 'paid', 'completed' );

	/**
	 * Per-request cache of status counts keyed by event ID.
	 *
	 * @var array<int,array<string,int>>
	 */
	private static $status_cache = array();

	/**
	 * Register the read-only portfolio route used by Expo and the web BFF.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'lnkup/v1',
			'/organizer/portfolio',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_portfolio' ),
				'permission_callback' => static function () {
					return is_user_logged_in() && ( ( class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer() ) || LNKup_Capabilities::user_has_manage_all() );
				},
			)
		);
		register_rest_route(
			'lnkup/v1',
			'/organizer/events/(?P<id>\d+)/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_event_summary' ),
				'permission_callback' => static function ( WP_REST_Request $request ) {
					// Owners, or event staff (who only see money with view_financials).
					return is_user_logged_in() && LNKup_Capabilities::staff_can( absint( $request['id'] ), 'view_roster' );
				},
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * GET /organizer/portfolio — the current organizer's events with presentation fields.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_portfolio( WP_REST_Request $request ) {
		$args = array(
			'publication' => sanitize_key( (string) $request->get_param( 'publication' ) ),
			'chronology'  => sanitize_key( (string) $request->get_param( 'chronology' ) ),
		);
		$rows = self::portfolio_rows( get_current_user_id(), $args );
		foreach ( $rows as &$row ) {
			unset( $row['image'] );
		}
		unset( $row );
		return rest_ensure_response( array( 'events' => $rows ) );
	}

	/**
	 * GET /organizer/events/{id}/summary.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_event_summary( WP_REST_Request $request ) {
		$summary = self::event_summary( absint( $request['id'] ), LNKup_Capabilities::staff_can( absint( $request['id'] ), 'view_financials' ) );
		if ( ! $summary ) {
			return new WP_Error( 'lnkup_not_found', __( 'Event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		unset( $summary['image'] );
		return rest_ensure_response( $summary );
	}

	/**
	 * Canonical event title for organizer screens.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function title( int $event_id ): string {
		if ( class_exists( 'LNKup_Vendor_Presentation' ) ) {
			return LNKup_Vendor_Presentation::customer_event_title( $event_id );
		}
		$title = get_the_title( $event_id );
		return function_exists( 'lnkup_plain_text' ) ? lnkup_plain_text( $title ) : wp_strip_all_tags( $title );
	}

	/**
	 * Normalized "City, ST" for an event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function place( int $event_id ): string {
		$city  = (string) get_post_meta( $event_id, '_lnkup_city', true );
		$state = (string) get_post_meta( $event_id, '_lnkup_state', true );
		if ( function_exists( 'lnkup_display_place' ) ) {
			return (string) lnkup_display_place( $city, $state );
		}
		return trim( $city . ( $state ? ', ' . $state : '' ) );
	}

	/**
	 * Chronological state from the event date.
	 *
	 * @param string $date Y-m-d.
	 * @return string upcoming|today|completed|undated
	 */
	public static function chronology( string $date ): string {
		if ( '' === $date ) {
			return 'undated';
		}
		$today = current_time( 'Y-m-d' );
		$day   = substr( $date, 0, 10 );
		if ( $day === $today ) {
			return 'today';
		}
		return $day < $today ? 'completed' : 'upcoming';
	}

	/**
	 * Application counts per status for a set of events, in one query.
	 *
	 * @param int[] $event_ids Event IDs.
	 * @return array<int,array<string,int>> event_id => status => count
	 */
	public static function status_counts( array $event_ids ): array {
		global $wpdb;
		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );
		$missing   = array_values( array_diff( $event_ids, array_keys( self::$status_cache ) ) );
		if ( $missing ) {
			foreach ( $missing as $id ) {
				self::$status_cache[ $id ] = array();
			}
			$placeholders = implode( ',', array_fill( 0, count( $missing ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.meta_value AS event_id, s.meta_value AS status, COUNT(DISTINCT p.ID) AS n
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_lnkup_event_id' AND e.meta_value IN ({$placeholders})
					INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_application_status'
					WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft')
					GROUP BY e.meta_value, s.meta_value",
					array_map( 'strval', $missing )
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				self::$status_cache[ (int) $row['event_id'] ][ (string) $row['status'] ] = (int) $row['n'];
			}
		}
		$out = array();
		foreach ( $event_ids as $id ) {
			$out[ $id ] = self::$status_cache[ $id ] ?? array();
		}
		return $out;
	}

	/**
	 * Sum of counts for the given statuses.
	 *
	 * @param array<string,int> $counts   Status counts.
	 * @param string[]          $statuses Statuses.
	 */
	private static function sum_statuses( array $counts, array $statuses ): int {
		$n = 0;
		foreach ( $statuses as $status ) {
			$n += (int) ( $counts[ $status ] ?? 0 );
		}
		return $n;
	}

	/**
	 * Vendor slots (opportunities) on an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int[]
	 */
	public static function opportunity_ids( int $event_id ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'lnkup_opportunity',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => (string) $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	/**
	 * Capacity breakdown for one booth type (opportunity).
	 *
	 * @param int $opp_id Opportunity ID.
	 * @return array{total:int,confirmed:int,held:int,open:int,waitlisted:int,unlimited:bool}
	 */
	public static function opportunity_capacity( int $opp_id ): array {
		if ( class_exists( 'LNKup_Opportunity_Inventory' ) ) {
			$inv       = LNKup_Opportunity_Inventory::summary( $opp_id );
			$confirmed = (int) $inv['paid'];
			$held      = max( 0, (int) $inv['held'] - $confirmed );
			return array(
				'total'      => (int) $inv['capacity'],
				'confirmed'  => $confirmed,
				'held'       => $held,
				'open'       => null === $inv['available'] ? 0 : (int) $inv['available'],
				'waitlisted' => (int) $inv['waitlisted'],
				'unlimited'  => null === $inv['available'],
			);
		}
		$total = (int) get_post_meta( $opp_id, '_lnkup_spots_total', true );
		$left  = (int) get_post_meta( $opp_id, '_lnkup_spots_remaining', true );
		return array(
			'total'      => $total,
			'confirmed'  => 0,
			'held'       => max( 0, $total - $left ),
			'open'       => $left,
			'waitlisted' => 0,
			'unlimited'  => 0 === $total,
		);
	}

	/**
	 * Capacity breakdown for an event (sum of its booth types).
	 *
	 * @param int $event_id Event ID.
	 * @return array{total:int,confirmed:int,held:int,open:int,waitlisted:int,pending:int,booth_types:int}
	 */
	public static function capacity( int $event_id ): array {
		$out = array(
			'total'       => 0,
			'confirmed'   => 0,
			'held'        => 0,
			'open'        => 0,
			'waitlisted'  => 0,
			'pending'     => 0,
			'booth_types' => 0,
		);
		foreach ( self::opportunity_ids( $event_id ) as $opp_id ) {
			$cap = self::opportunity_capacity( $opp_id );
			$out['total']      += $cap['total'];
			$out['open']       += $cap['open'];
			$out['booth_types']++;
		}
		$counts            = self::status_counts( array( $event_id ) )[ $event_id ] ?? array();
		$out['confirmed']  = self::sum_statuses( $counts, self::CONFIRMED_STATUSES );
		$out['held']       = self::sum_statuses( $counts, self::HELD_STATUSES );
		$out['waitlisted'] = (int) ( $counts['waitlisted'] ?? 0 );
		$out['pending']    = self::sum_statuses( $counts, self::PENDING_STATUSES );
		if ( $out['total'] > 0 ) {
			$out['open'] = max( 0, $out['total'] - $out['confirmed'] - $out['held'] );
		}
		return $out;
	}

	/**
	 * Event-scoped money from payment records.
	 *
	 * @param int $event_id Event ID.
	 * @return array{collected_gross:float,outstanding:float,paid_bookings:int}
	 */
	public static function event_money( int $event_id ): array {
		$collected = 0.0;
		$paid      = 0;
		$payments  = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$paid_apps = array();
		foreach ( $payments as $payment_id ) {
			$amount = class_exists( 'LNKup_Financial' ) ? (float) LNKup_Financial::collected_amount( (int) $payment_id ) : 0.0;
			if ( $amount > 0 ) {
				$collected += $amount;
				$paid++;
				$paid_apps[ (int) get_post_meta( (int) $payment_id, '_lnkup_application_id', true ) ] = true;
			}
		}
		$outstanding = 0.0;
		$held        = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_event_id', 'value' => (string) $event_id ),
					array( 'key' => '_lnkup_application_status', 'value' => self::HELD_STATUSES, 'compare' => 'IN' ),
				),
			)
		);
		foreach ( $held as $app_id ) {
			if ( isset( $paid_apps[ (int) $app_id ] ) ) {
				continue;
			}
			$opp_id       = (int) get_post_meta( (int) $app_id, '_lnkup_opportunity_id', true );
			$outstanding += $opp_id ? (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ) : 0.0;
		}
		return array(
			'collected_gross' => round( $collected, 2 ),
			'outstanding'     => round( $outstanding, 2 ),
			'paid_bookings'   => $paid,
		);
	}

	/**
	 * Full event summary for organizer screens.
	 *
	 * @param int  $event_id   Event ID.
	 * @param bool $with_money Include the payment roll-up (one extra query pair).
	 * @return array<string,mixed>|null
	 */
	public static function event_summary( int $event_id, bool $with_money = true ): ?array {
		$event = get_post( $event_id );
		if ( ! $event instanceof WP_Post || 'lnkup_event' !== $event->post_type ) {
			return null;
		}
		$date        = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$venue       = (string) get_post_meta( $event_id, '_lnkup_venue_name', true );
		$venue       = get_post_meta( $event_id, '_lnkup_venue_hidden', true ) ? '' : $venue;
		$publication = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::sync_event_listing_status( $event_id ) : 'draft';
		$chronology  = self::chronology( $date );
		$apps_open   = class_exists( 'LNKup_Organizer_Onboarding' ) && LNKup_Organizer_Onboarding::event_has_open_slot( $event_id );
		$capacity    = self::capacity( $event_id );
		$counts      = self::status_counts( array( $event_id ) )[ $event_id ] ?? array();
		$tour_id     = (int) get_post_meta( $event_id, '_lnkup_tour_id', true );
		$chrono_map  = array(
			'upcoming'  => array( __( 'Upcoming', 'lnkup-marketplace' ), 'info' ),
			'today'     => array( __( 'Today', 'lnkup-marketplace' ), 'warning' ),
			'completed' => array( __( 'Completed', 'lnkup-marketplace' ), 'neutral' ),
			'undated'   => array( __( 'No date', 'lnkup-marketplace' ), 'warning' ),
		);
		$summary = array(
			'id'                 => $event_id,
			'title'              => self::title( $event_id ),
			'date'               => $date,
			'date_label'         => class_exists( 'LNKup_OS_UI' ) ? LNKup_OS_UI::date( $date ) : $date,
			'days_until'         => class_exists( 'LNKup_OS_UI' ) ? LNKup_OS_UI::days_until( $date ) : null,
			'venue'              => function_exists( 'lnkup_plain_text' ) ? lnkup_plain_text( $venue ) : $venue,
			'place'              => self::place( $event_id ),
			'market'             => (string) get_post_meta( $event_id, '_lnkup_market', true ),
			'publication'        => 'live' === $publication ? 'live' : 'draft',
			'publication_label'  => 'live' === $publication ? __( 'Published', 'lnkup-marketplace' ) : __( 'Draft', 'lnkup-marketplace' ),
			'publication_tone'   => 'live' === $publication ? 'success' : 'neutral',
			'chronology'         => $chronology,
			'chronology_label'   => $chrono_map[ $chronology ][0],
			'chronology_tone'    => $chrono_map[ $chronology ][1],
			'applications_open'  => $apps_open,
			'applications'       => array(
				'total'          => array_sum( $counts ),
				'pending'        => self::sum_statuses( $counts, self::PENDING_STATUSES ),
				'info_requested' => (int) ( $counts['info_requested'] ?? 0 ),
				'held'           => self::sum_statuses( $counts, self::HELD_STATUSES ),
				'confirmed'      => self::sum_statuses( $counts, self::CONFIRMED_STATUSES ),
				'waitlisted'     => (int) ( $counts['waitlisted'] ?? 0 ),
				'declined'       => self::sum_statuses( $counts, array( 'rejected', 'cancelled' ) ),
			),
			'capacity'           => $capacity,
			'tour_id'            => $tour_id,
			'tour_title'         => $tour_id ? self::plain( get_the_title( $tour_id ) ) : '',
			'url'                => add_query_arg( 'event', $event_id, lnkup_page_url_helper( 'organizer-dashboard' ) ),
			'public_url'         => class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::event_public_url( $event_id ) : '',
		);
		if ( $with_money ) {
			$summary['money'] = self::event_money( $event_id );
		}
		return $summary;
	}

	/**
	 * Plain text helper.
	 *
	 * @param string $text Text.
	 */
	private static function plain( string $text ): string {
		return function_exists( 'lnkup_plain_text' ) ? (string) lnkup_plain_text( $text ) : wp_strip_all_tags( $text );
	}

	/**
	 * Owned event IDs.
	 *
	 * @param int $user_id Organizer.
	 * @return int[]
	 */
	public static function event_ids( int $user_id ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'lnkup_event',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_lnkup_organizer_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	/**
	 * Portfolio rows with filters.
	 *
	 * @param int                  $user_id Organizer.
	 * @param array<string,string> $args    publication, chronology, applications (open|closed), tour, market, from, to, q.
	 * @return array<int,array<string,mixed>>
	 */
	public static function portfolio_rows( int $user_id, array $args = array() ): array {
		$ids = self::event_ids( $user_id );
		self::status_counts( $ids );
		$rows = array();
		foreach ( $ids as $event_id ) {
			$row = self::event_summary( $event_id );
			if ( ! $row ) {
				continue;
			}
			$chron = (string) ( $args['chronology'] ?? '' );
			if ( '' !== $chron && 'all' !== $chron ) {
				$bucket = 'today' === $row['chronology'] ? 'in_progress' : $row['chronology'];
				if ( $bucket !== $chron && ! ( 'upcoming' === $chron && 'undated' === $row['chronology'] ) ) {
					continue;
				}
			}
			if ( ! empty( $args['publication'] ) && 'all' !== $args['publication'] && $row['publication'] !== $args['publication'] ) {
				continue;
			}
			if ( ! empty( $args['applications'] ) && ( 'open' === $args['applications'] ) !== (bool) $row['applications_open'] ) {
				continue;
			}
			if ( ! empty( $args['tour'] ) && (int) $args['tour'] !== (int) $row['tour_id'] ) {
				continue;
			}
			if ( ! empty( $args['market'] ) && 0 !== strcasecmp( (string) $args['market'], (string) $row['place'] ) ) {
				continue;
			}
			if ( ! empty( $args['from'] ) && ( '' === $row['date'] || $row['date'] < $args['from'] ) ) {
				continue;
			}
			if ( ! empty( $args['to'] ) && ( '' === $row['date'] || $row['date'] > $args['to'] ) ) {
				continue;
			}
			if ( ! empty( $args['q'] ) && false === stripos( $row['title'] . ' ' . $row['place'] . ' ' . $row['venue'], (string) $args['q'] ) ) {
				continue;
			}
			$rows[] = $row;
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				$a_past = 'completed' === $a['chronology'];
				$b_past = 'completed' === $b['chronology'];
				if ( $a_past !== $b_past ) {
					return $a_past ? 1 : -1;
				}
				$ad = '' === $a['date'] ? '9999-12-31' : $a['date'];
				$bd = '' === $b['date'] ? '9999-12-31' : $b['date'];
				return $a_past ? strcmp( $bd, $ad ) : strcmp( $ad, $bd );
			}
		);
		return $rows;
	}

	/**
	 * Application rows for the action queue.
	 *
	 * @param int      $user_id  Organizer.
	 * @param string[] $statuses Statuses.
	 * @param int      $limit    Max rows.
	 * @return int[]
	 */
	private static function application_ids( int $user_id, array $statuses, int $limit = 50 ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'orderby'        => 'date',
					'order'          => 'DESC',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) $user_id ),
						array( 'key' => '_lnkup_application_status', 'value' => $statuses, 'compare' => 'IN' ),
					),
				)
			)
		);
	}

	/**
	 * Vendor display name for an application.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function vendor_name( int $app_id ): string {
		if ( class_exists( 'LNKup_Event_Operations' ) && method_exists( 'LNKup_Event_Operations', 'vendor_name_for_app' ) ) {
			$name = (string) LNKup_Event_Operations::vendor_name_for_app( $app_id );
			if ( '' !== $name ) {
				return self::plain( $name );
			}
		}
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		$name       = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_business_name', true ) : '';
		if ( '' === $name ) {
			$user = get_userdata( (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ) );
			$name = $user ? (string) $user->display_name : __( 'Vendor', 'lnkup-marketplace' );
		}
		return self::plain( $name );
	}

	/**
	 * Grouped action queue. One row per application with event context.
	 *
	 * @param int $user_id Organizer.
	 * @return array<string,array{label:string,count:int,rows:array<int,array<string,mixed>>,href:string}>
	 */
	public static function action_queue( int $user_id ): array {
		$dash   = lnkup_page_url_helper( 'organizer-dashboard' );
		$groups = array(
			'review'    => array(
				'label' => __( 'Applications to review', 'lnkup-marketplace' ),
				'href'  => lnkup_organizer_view_url( 'applications', array( 'filter' => 'pending', 'group' => 'pending' ) ),
				'rows'  => array(),
			),
			'payment'   => array(
				'label' => __( 'Approved, not yet paid', 'lnkup-marketplace' ),
				'href'  => lnkup_organizer_view_url( 'applications', array( 'filter' => 'unpaid' ) ),
				'rows'  => array(),
			),
			'documents' => array(
				'label' => __( 'Documents to review', 'lnkup-marketplace' ),
				'href'  => lnkup_organizer_view_url( 'applications', array( 'filter' => 'missing_docs' ) ),
				'rows'  => array(),
			),
			'booths'    => array(
				'label' => __( 'Confirmed vendors without a booth', 'lnkup-marketplace' ),
				'href'  => lnkup_organizer_view_url( 'operations' ),
				'rows'  => array(),
			),
			'packet'    => array(
				'label' => __( 'Vendor packets still in draft', 'lnkup-marketplace' ),
				'href'  => lnkup_organizer_view_url( 'operations' ),
				'rows'  => array(),
			),
		);

		$event_ctx = static function ( int $event_id ): string {
			$title = self::title( $event_id );
			$date  = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
			return trim( $title . ( $date ? ' · ' . date_i18n( 'M j', (int) strtotime( $date ) ) : '' ) );
		};

		foreach ( self::application_ids( $user_id, self::PENDING_STATUSES, 25 ) as $app_id ) {
			$event_id                   = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
			$groups['review']['rows'][] = array(
				'app_id'   => $app_id,
				'event_id' => $event_id,
				'title'    => self::vendor_name( $app_id ),
				'meta'     => $event_ctx( $event_id ),
				'actions'  => array(
					array( 'label' => __( 'Review', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'applications', array( 'filter' => 'pending', 'event_filter' => $event_id, 'app' => $app_id ) ) ),
				),
			);
		}

		$unpaid = class_exists( 'LNKup_Organizer_Dashboard_Views' ) ? LNKup_Organizer_Dashboard_Views::unpaid_ids( $user_id ) : array();
		foreach ( array_slice( array_map( 'intval', (array) $unpaid ), 0, 25 ) as $app_id ) {
			$event_id                    = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
			$groups['payment']['rows'][] = array(
				'app_id'   => $app_id,
				'event_id' => $event_id,
				'title'    => self::vendor_name( $app_id ),
				'meta'     => $event_ctx( $event_id ),
				'actions'  => array(
					array( 'label' => __( 'View invoice', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'financial', array( 'fin_tab' => 'vendor-revenue', 'event' => null ) ) ),
					array( 'label' => __( 'Send reminder', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'applications', array( 'filter' => 'unpaid', 'event_filter' => $event_id, 'app' => $app_id ) ) ),
				),
			);
		}

		if ( class_exists( 'LNKup_Verification' ) ) {
			foreach ( self::application_ids( $user_id, array( 'approved', 'payment_required', 'paid' ), 40 ) as $app_id ) {
				$ctx = LNKup_Verification::get_application_document_context( $app_id );
				if ( ! in_array( (string) ( $ctx['code'] ?? '' ), array( 'pending', 'missing' ), true ) ) {
					continue;
				}
				$event_id                      = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
				$groups['documents']['rows'][] = array(
					'app_id'   => $app_id,
					'event_id' => $event_id,
					'title'    => self::vendor_name( $app_id ),
					'meta'     => $event_ctx( $event_id ) . ( 'pending' === ( $ctx['code'] ?? '' ) ? ' · ' . __( 'Uploaded, awaiting your review', 'lnkup-marketplace' ) : ' · ' . __( 'Not uploaded yet', 'lnkup-marketplace' ) ),
					'actions'  => array(
						array( 'label' => __( 'Review documents', 'lnkup-marketplace' ), 'href' => add_query_arg( array( 'event' => $event_id, 'tab' => 'documents' ), $dash ) ),
					),
				);
			}
		}

		$upcoming = array_filter(
			self::event_ids( $user_id ),
			static function ( $event_id ) {
				return in_array( self::chronology( (string) get_post_meta( $event_id, '_lnkup_event_date', true ) ), array( 'upcoming', 'today' ), true );
			}
		);
		foreach ( $upcoming as $event_id ) {
			$counts    = self::status_counts( array( $event_id ) )[ $event_id ] ?? array();
			$confirmed = self::sum_statuses( $counts, self::CONFIRMED_STATUSES );
			if ( $confirmed < 1 ) {
				continue;
			}
			if ( class_exists( 'LNKup_Booths' ) ) {
				$booths   = (array) LNKup_Booths::get_for_event( $event_id );
				$assigned = count(
					array_filter(
						$booths,
						static function ( $b ) {
							return ! empty( $b['application_id'] );
						}
					)
				);
				if ( $assigned < $confirmed ) {
					$groups['booths']['rows'][] = array(
						'app_id'   => 0,
						'event_id' => $event_id,
						'title'    => $event_ctx( $event_id ),
						'meta'     => sprintf( /* translators: 1: assigned, 2: confirmed */ __( '%1$d of %2$d confirmed vendors have a booth', 'lnkup-marketplace' ), $assigned, $confirmed ),
						'actions'  => array(
							array( 'label' => __( 'Assign booths', 'lnkup-marketplace' ), 'href' => add_query_arg( array( 'event' => $event_id, 'ops' => 1 ), $dash ) . '#booths' ),
						),
					);
				}
			}
			if ( class_exists( 'LNKup_Packets' ) ) {
				$packet = LNKup_Packets::lifecycle( $event_id );
				if ( 'draft' === ( $packet['stage'] ?? '' ) ) {
					$groups['packet']['rows'][] = array(
						'app_id'   => 0,
						'event_id' => $event_id,
						'title'    => $event_ctx( $event_id ),
						'meta'     => sprintf( /* translators: %d: confirmed vendors */ _n( '%d confirmed vendor is waiting for event details', '%d confirmed vendors are waiting for event details', $confirmed, 'lnkup-marketplace' ), $confirmed ),
						'actions'  => array(
							array( 'label' => __( 'Prepare packet', 'lnkup-marketplace' ), 'href' => add_query_arg( array( 'event' => $event_id, 'ops' => 1 ), $dash ) . '#packet' ),
						),
					);
				}
			}
		}

		$out = array();
		foreach ( $groups as $key => $group ) {
			$group['count'] = count( $group['rows'] );
			if ( 'review' === $key ) {
				$group['count'] = (int) self::count_applications( $user_id, self::PENDING_STATUSES );
			} elseif ( 'payment' === $key ) {
				$group['count'] = count( (array) $unpaid );
			}
			$out[ $key ] = $group;
		}
		return $out;
	}

	/**
	 * Packet tracking, each stage with its own denominator:
	 * prepared (checklist items done / items), published (lifecycle stage), accessible
	 * (unlocked passes / paid vendors), viewed and acknowledged (/ vendors with access).
	 *
	 * @param int $event_id Event ID.
	 * @return array<string,mixed>
	 */
	public static function packet_tracking( int $event_id ): array {
		$complete  = class_exists( 'LNKup_Packets' ) ? LNKup_Packets::get_pass_completeness( $event_id ) : array( 'complete' => 0, 'total' => 0 );
		$lifecycle = class_exists( 'LNKup_Packets' ) ? LNKup_Packets::lifecycle( $event_id ) : array( 'stage' => 'draft', 'label' => '' );
		$counts    = self::status_counts( array( $event_id ) )[ $event_id ] ?? array();
		$paid      = self::sum_statuses( $counts, self::CONFIRMED_STATUSES );
		$viewed    = 0;
		$accepted  = 0;
		$unlocked  = (int) ( $lifecycle['unlocked'] ?? 0 );
		if ( $paid && class_exists( 'LNKup_Vendor_Readiness' ) ) {
			$paid_ids = get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => '_lnkup_event_id', 'value' => (string) $event_id ),
						array( 'key' => '_lnkup_application_status', 'value' => self::CONFIRMED_STATUSES, 'compare' => 'IN' ),
					),
				)
			);
			foreach ( $paid_ids as $app_id ) {
				foreach ( (array) ( LNKup_Vendor_Readiness::checklist( (int) $app_id )['items'] ?? array() ) as $item ) {
					if ( 'packet_viewed' === $item['key'] && $item['done'] ) {
						$viewed++;
					}
					if ( 'rules_accepted' === $item['key'] && $item['done'] ) {
						$accepted++;
					}
				}
			}
		}
		return array(
			'prepared'     => array( (int) $complete['complete'], (int) $complete['total'] ),
			'published'    => (string) ( $lifecycle['label'] ?? '' ),
			'stage'        => (string) ( $lifecycle['stage'] ?? 'draft' ),
			'accessible'   => array( $unlocked, $paid ),
			'viewed'       => array( $viewed, $unlocked ),
			'acknowledged' => array( $accepted, $unlocked ),
		);
	}

	/**
	 * Packet tracking strip for the operations page.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function render_packet_tracking( int $event_id ): string {
		if ( ! class_exists( 'LNKup_OS_UI' ) ) {
			return '';
		}
		$t     = self::packet_tracking( $event_id );
		$frac  = static function ( array $pair ): string {
			/* translators: 1: count, 2: total */
			return sprintf( __( '%1$d of %2$d', 'lnkup-marketplace' ), $pair[0], $pair[1] );
		};
		$cards = array(
			array( 'label' => __( 'Prepared', 'lnkup-marketplace' ), 'value' => $frac( $t['prepared'] ), 'hint' => __( 'packet sections filled in', 'lnkup-marketplace' ) ),
			array( 'label' => __( 'Published', 'lnkup-marketplace' ), 'value' => $t['published'], 'hint' => __( 'vendors see it once they pay', 'lnkup-marketplace' ) ),
			array( 'label' => __( 'Accessible', 'lnkup-marketplace' ), 'value' => $frac( $t['accessible'] ), 'hint' => __( 'of paid vendors can open it', 'lnkup-marketplace' ) ),
			array( 'label' => __( 'Viewed', 'lnkup-marketplace' ), 'value' => $frac( $t['viewed'] ), 'hint' => __( 'of vendors with access', 'lnkup-marketplace' ) ),
			array( 'label' => __( 'Acknowledged rules', 'lnkup-marketplace' ), 'value' => $frac( $t['acknowledged'] ), 'hint' => __( 'of vendors with access', 'lnkup-marketplace' ) ),
		);
		return LNKup_OS_UI::metrics( $cards, 'lnkup-packet-tracking' );
	}

	/**
	 * Count of the organizer's applications in the given statuses.
	 *
	 * @param int      $user_id  Organizer.
	 * @param string[] $statuses Statuses.
	 */
	public static function count_applications( int $user_id, array $statuses ): int {
		if ( ! function_exists( 'lnkup_count_posts_for_organizer' ) ) {
			return 0;
		}
		return (int) lnkup_count_posts_for_organizer(
			'lnkup_application',
			$user_id,
			array(
				array(
					'key'     => '_lnkup_application_status',
					'value'   => $statuses,
					'compare' => 'IN',
				),
			)
		);
	}

	/**
	 * The organizer money contract for server-rendered summaries. Ledger figures (collected,
	 * refunds) are computed on every call; the Stripe balance payload is cached for two minutes
	 * so Home and the Money summary don't add a live Stripe request per page view. Money's
	 * JS panels still read the live balance.
	 *
	 * @param int $user_id Organizer.
	 * @return array<string,mixed>
	 */
	public static function money_contract( int $user_id ): array {
		if ( ! class_exists( 'LNKup_Financial_Service' ) || ! method_exists( 'LNKup_Financial_Service', 'organizer_money_contract' ) ) {
			return array();
		}
		try {
			$key     = 'lnkup_oos_balance_' . $user_id;
			$payload = get_transient( $key );
			if ( ! is_array( $payload ) ) {
				$payload = (array) LNKup_Financial_Service::organizer_balance_payload( $user_id );
				set_transient( $key, $payload, 2 * MINUTE_IN_SECONDS );
			}
			return (array) LNKup_Financial_Service::organizer_money_contract( $user_id, $payload );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'LNKup organizer money_contract: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return array();
		}
	}

	/**
	 * Organizer money summary — the same contract the Money page uses.
	 *
	 * @param int $user_id Organizer.
	 * @return array{collected:float,refunds:float,net_earnings:float,available:float|null,pending:float|null,source:string,label:string}
	 */
	public static function financial_summary( int $user_id ): array {
		$contract = self::money_contract( $user_id );
		$m        = (array) ( $contract['metrics'] ?? array() );
		$dollars = static function ( $metric ) {
			if ( ! is_array( $metric ) || 'ok' !== ( $metric['status'] ?? '' ) || null === ( $metric['cents'] ?? null ) ) {
				return null;
			}
			return round( (int) $metric['cents'] / 100, 2 );
		};
		return array(
			'collected'    => (float) ( $dollars( $m['collected'] ?? null ) ?? 0.0 ),
			'refunds'      => (float) ( $dollars( $m['refunds'] ?? null ) ?? 0.0 ),
			'net_earnings' => (float) ( $dollars( $m['net_earnings'] ?? null ) ?? 0.0 ),
			'available'    => $dollars( $m['available'] ?? null ),
			'pending'      => $dollars( $m['pending'] ?? null ),
			'source'       => 'organizer_money_contract',
			'label'        => __( 'Collected after LNKup fees · all time', 'lnkup-marketplace' ),
		);
	}
}

LNKup_Organizer_Presentation::init();
