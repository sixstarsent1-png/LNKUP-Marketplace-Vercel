<?php
/**
 * Organizer mobile contract (Organizer Mobile OS 2.0).
 *
 * Read models for the native organizer app: one Home payload, an event operations summary, a
 * check-in summary whose populations match LNKup_Checkins::evaluate(), the portfolio with event
 * media, the invite lifecycle and a read-only cash-out preview. Every route composes existing
 * services (presentation, activation, check-ins, invites, payouts); none of them write money.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LNKup_Organizer_Mobile_REST
 */
class LNKup_Organizer_Mobile_REST {

	const NS = 'lnkup/v1';

	/** Rows returned per action-queue group on Home. */
	const HOME_QUEUE_ROWS = 3;

	/**
	 * Hook routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function register_routes(): void {
		$organizer = array( __CLASS__, 'organizer_permission' );

		register_rest_route(
			self::NS,
			'/organizer/home-summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'home_summary' ),
				'permission_callback' => $organizer,
			)
		);
		register_rest_route(
			self::NS,
			'/organizer/mobile/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'mobile_events' ),
				'permission_callback' => $organizer,
				'args'                => array(
					'q'            => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'publication'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					'chronology'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					'applications' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					'tour'         => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/organizer/events/(?P<id>\d+)/operations-summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'operations_summary' ),
				'permission_callback' => self::event_cap( 'view_roster' ),
				'args'                => self::id_arg(),
			)
		);
		register_rest_route(
			self::NS,
			'/organizer/events/(?P<id>\d+)/score',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'event_score' ),
				'permission_callback' => self::event_cap( 'view_intelligence' ),
				'args'                => self::id_arg(),
			)
		);
		register_rest_route(
			self::NS,
			'/organizer/applications/(?P<id>\d+)/review',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'application_review' ),
				'permission_callback' => static function ( WP_REST_Request $request ) {
					return is_user_logged_in() && LNKup_Capabilities::can_on_application( absint( $request['id'] ), 'manage_applications' );
				},
				'args'                => self::id_arg(),
			)
		);
		register_rest_route(
			self::NS,
			'/operations/events/(?P<id>\d+)/checkin-summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'checkin_summary' ),
				'permission_callback' => self::event_cap( 'check_in' ),
				'args'                => self::id_arg(),
			)
		);
		register_rest_route(
			self::NS,
			'/organizer/mobile/invites',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'invites' ),
				'permission_callback' => $organizer,
			)
		);
		register_rest_route(
			self::NS,
			'/crm/invites/(?P<id>\d+)/remind',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'remind_invite' ),
				'permission_callback' => $organizer,
				'args'                => self::id_arg(),
			)
		);
		register_rest_route(
			self::NS,
			'/crm/invites/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cancel_invite' ),
				'permission_callback' => $organizer,
				'args'                => self::id_arg(),
			)
		);
		register_rest_route(
			self::NS,
			'/financial/organizer-payouts/preview',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'payout_preview' ),
				'permission_callback' => array( __CLASS__, 'payout_permission' ),
				'args'                => array(
					'amount' => array( 'type' => 'number', 'default' => 0 ),
					'type'   => array( 'type' => 'string', 'default' => 'standard', 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);
	}

	// ------------------------------------------------------------------ Permissions.

	/**
	 * Organizers and platform admins.
	 */
	public static function organizer_permission(): bool {
		return is_user_logged_in()
			&& ( ( class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer() ) || LNKup_Capabilities::user_has_manage_all() );
	}

	/**
	 * Cash-out preview: only an organizer's own balance (never staff or admins acting for others).
	 */
	public static function payout_permission(): bool {
		return is_user_logged_in() && class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer();
	}

	/**
	 * Event-scoped staff capability for routes with an `{id}` event.
	 *
	 * @param string $capability Capability key (see LNKup_Capabilities::staff_can()).
	 */
	private static function event_cap( string $capability ): callable {
		return static function ( WP_REST_Request $request ) use ( $capability ) {
			$event_id = absint( $request['id'] );
			return is_user_logged_in()
				&& 'lnkup_event' === get_post_type( $event_id )
				&& LNKup_Capabilities::staff_can( $event_id, $capability );
		};
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function id_arg(): array {
		return array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
	}

	// ------------------------------------------------------------------ Home.

	/**
	 * GET /organizer/home-summary — everything the Home command center needs in one request.
	 * Setup progress comes only from LNKup_Organizer_Activation (the single setup model).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function home_summary( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		try {
			$activation = class_exists( 'LNKup_Organizer_Activation' ) ? LNKup_Organizer_Activation::for_user( $user_id ) : array();
			$rows       = LNKup_Organizer_Presentation::portfolio_rows( $user_id );
			$queue      = LNKup_Organizer_Presentation::action_queue( $user_id );
			$money      = LNKup_Organizer_Presentation::financial_summary( $user_id );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'LNKup home-summary: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return new WP_Error( 'lnkup_home_unavailable', __( 'Your dashboard could not load. Pull to refresh.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}

		$counts    = array( 'total' => count( $rows ), 'live' => 0, 'draft' => 0, 'upcoming' => 0, 'today' => 0, 'completed' => 0, 'undated' => 0 );
		$today     = array();
		$next      = null;
		$upcoming  = array();
		$confirmed = 0;
		$gaps      = array();
		foreach ( $rows as $row ) {
			$counts[ 'live' === $row['publication'] ? 'live' : 'draft' ]++;
			$counts[ $row['chronology'] ] = ( $counts[ $row['chronology'] ] ?? 0 ) + 1;
			if ( in_array( $row['chronology'], array( 'upcoming', 'today' ), true ) ) {
				$confirmed += (int) $row['applications']['confirmed'];
				if ( 'live' === $row['publication'] && count( $gaps ) < self::HOME_QUEUE_ROWS ) {
					$gaps = array_merge( $gaps, self::recruitment_gaps( $row ) );
				}
			}
			if ( 'today' === $row['chronology'] ) {
				$today[] = self::event_card( $row );
			} elseif ( 'upcoming' === $row['chronology'] && count( $upcoming ) < 5 ) {
				$card       = self::event_card( $row );
				$upcoming[] = $card;
				$next       = $next ?? $card;
			}
		}
		$kpis = array(
			'collected'       => array( 'value' => $money['collected'], 'label' => $money['label'] ),
			'awaiting_review' => array( 'value' => (int) ( $queue['review']['count'] ?? 0 ), 'label' => __( 'Applications to review', 'lnkup-marketplace' ) ),
			'approved_unpaid' => array( 'value' => (int) ( $queue['payment']['count'] ?? 0 ), 'label' => __( 'Approved, not yet paid', 'lnkup-marketplace' ) ),
			'upcoming_events' => array( 'value' => $counts['upcoming'] + $counts['today'], 'label' => __( 'Upcoming events', 'lnkup-marketplace' ) ),
			'confirmed'       => array( 'value' => $confirmed, 'label' => __( 'Confirmed vendors at upcoming events', 'lnkup-marketplace' ) ),
		);

		$queue_out = array();
		foreach ( $queue as $key => $group ) {
			$queue_out[ $key ] = array(
				'label' => (string) $group['label'],
				'count' => (int) $group['count'],
				'rows'  => array_map(
					static function ( $r ) {
						return array(
							'app_id'   => (int) $r['app_id'],
							'event_id' => (int) $r['event_id'],
							'title'    => (string) $r['title'],
							'meta'     => (string) $r['meta'],
						);
					},
					array_slice( (array) $group['rows'], 0, self::HOME_QUEUE_ROWS )
				),
			);
		}

		$is_activated = ! empty( $activation['is_activated'] );
		if ( ! $counts['total'] ) {
			$lifecycle = 'new';
		} elseif ( $today ) {
			$lifecycle = 'event_day';
		} elseif ( ! $is_activated ) {
			$lifecycle = 'setup';
		} else {
			$lifecycle = 'active';
		}

		$payouts_stage = null;
		foreach ( (array) ( $activation['stages'] ?? array() ) as $stage ) {
			if ( 'payouts' === ( $stage['key'] ?? '' ) ) {
				$payouts_stage = $stage;
			}
		}

		return rest_ensure_response(
			array(
				'lifecycle'   => $lifecycle,
				'setup'       => self::setup_payload( $activation ),
				'kpis'        => $kpis,
				'events'      => array(
					'counts'   => $counts,
					'today'    => $today,
					'next'     => $next,
					'upcoming' => $upcoming,
				),
				'recruitment' => array_slice( $gaps, 0, self::HOME_QUEUE_ROWS ),
				'queue'       => $queue_out,
				'money'       => array(
					'collected'     => $money['collected'],
					'refunds'       => $money['refunds'],
					'net_earnings'  => $money['net_earnings'],
					'available'     => $money['available'],
					'pending'       => $money['pending'],
					'label'         => $money['label'],
					'payouts_ready' => $payouts_stage ? 'complete' === $payouts_stage['status'] : null,
				),
				'generated_at' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Booth types of a live event that still have open spaces, with a short category label.
	 *
	 * @param array<string,mixed> $row Portfolio row.
	 * @return array<int,array<string,mixed>>
	 */
	private static function recruitment_gaps( array $row ): array {
		$out = array();
		foreach ( LNKup_Organizer_Presentation::opportunity_ids( (int) $row['id'] ) as $opp_id ) {
			$cap = LNKup_Organizer_Presentation::opportunity_capacity( $opp_id );
			if ( $cap['unlimited'] || $cap['open'] < 1 ) {
				continue;
			}
			$terms = taxonomy_exists( 'lnkup_vendor_category' ) ? wp_get_object_terms( $opp_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) ) : array();
			$label = ! is_wp_error( $terms ) && $terms ? (string) $terms[0] : '';
			if ( '' !== $label && function_exists( 'lnkup_canonical_category_label' ) ) {
				$label = (string) lnkup_canonical_category_label( $label );
			}
			$title = get_the_title( $opp_id );
			$out[] = array(
				'event_id'       => (int) $row['id'],
				'event_title'    => (string) $row['title'],
				'date_label'     => (string) $row['date_label'],
				'opportunity_id' => $opp_id,
				'booth_type'     => function_exists( 'lnkup_plain_text' ) ? (string) lnkup_plain_text( $title ) : wp_strip_all_tags( $title ),
				'category'       => $label,
				'open'           => (int) $cap['open'],
				'total'          => (int) $cap['total'],
			);
		}
		return $out;
	}

	/**
	 * Activation reduced to what mobile renders (no web URLs).
	 *
	 * @param array<string,mixed> $activation Activation payload.
	 * @return array<string,mixed>
	 */
	private static function setup_payload( array $activation ): array {
		$next = $activation['next_action'] ?? null;
		return array(
			'percent'      => (int) ( $activation['percent'] ?? 0 ),
			'completed'    => (int) ( $activation['completed'] ?? 0 ),
			'total'        => (int) ( $activation['total'] ?? 0 ),
			'is_activated' => ! empty( $activation['is_activated'] ),
			'next'         => is_array( $next ) ? array(
				'stage'   => (string) $next['stage'],
				'title'   => (string) $next['title'],
				'label'   => (string) $next['label'],
				'missing' => array_values( (array) $next['missing'] ),
			) : null,
			'stages'       => array_map(
				static function ( $s ) {
					return array(
						'key'     => (string) $s['key'],
						'title'   => (string) $s['title'],
						'status'  => (string) $s['status'],
						'missing' => array_values( (array) $s['missing'] ),
					);
				},
				(array) ( $activation['stages'] ?? array() )
			),
			'share'        => isset( $activation['share'] ) && is_array( $activation['share'] ) ? array(
				'event_id'        => (int) $activation['share']['event_id'],
				'event_title'     => (string) $activation['share']['event_title'],
				'application_url' => (string) $activation['share']['application_url'],
			) : null,
		);
	}

	// ------------------------------------------------------------------ Events.

	/**
	 * GET /organizer/mobile/events — portfolio rows with media, filter counts and search.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function mobile_events( WP_REST_Request $request ) {
		$all = LNKup_Organizer_Presentation::portfolio_rows( get_current_user_id() );
		if ( class_exists( 'LNKup_Event_Media' ) ) {
			LNKup_Event_Media::prime( array_map( static fn( $r ) => (int) $r['id'], $all ) );
		}
		$counts = array( 'all' => count( $all ), 'upcoming' => 0, 'in_progress' => 0, 'completed' => 0, 'draft' => 0, 'live' => 0 );
		foreach ( $all as $row ) {
			$bucket = 'today' === $row['chronology'] ? 'in_progress' : ( 'undated' === $row['chronology'] ? 'upcoming' : $row['chronology'] );
			$counts[ $bucket ]++;
			$counts[ 'live' === $row['publication'] ? 'live' : 'draft' ]++;
		}
		$filters = array(
			'q'            => trim( (string) $request->get_param( 'q' ) ),
			'publication'  => (string) $request->get_param( 'publication' ),
			'chronology'   => (string) $request->get_param( 'chronology' ),
			'applications' => (string) $request->get_param( 'applications' ),
			'tour'         => (int) $request->get_param( 'tour' ),
		);
		$events = array();
		foreach ( $all as $row ) {
			if ( self::row_matches( $row, $filters ) ) {
				$events[] = self::event_card( $row );
			}
		}
		return rest_ensure_response( array( 'events' => $events, 'counts' => $counts ) );
	}

	/**
	 * Same filter semantics as LNKup_Organizer_Presentation::portfolio_rows().
	 *
	 * @param array<string,mixed> $row     Portfolio row.
	 * @param array<string,mixed> $filters Filters.
	 */
	private static function row_matches( array $row, array $filters ): bool {
		$chron = $filters['chronology'];
		if ( '' !== $chron && 'all' !== $chron ) {
			$bucket = 'today' === $row['chronology'] ? 'in_progress' : $row['chronology'];
			if ( $bucket !== $chron && ! ( 'upcoming' === $chron && 'undated' === $row['chronology'] ) ) {
				return false;
			}
		}
		if ( '' !== $filters['publication'] && 'all' !== $filters['publication'] && $row['publication'] !== $filters['publication'] ) {
			return false;
		}
		if ( in_array( $filters['applications'], array( 'open', 'closed' ), true ) && ( 'open' === $filters['applications'] ) !== (bool) $row['applications_open'] ) {
			return false;
		}
		if ( $filters['tour'] && (int) $filters['tour'] !== (int) $row['tour_id'] ) {
			return false;
		}
		if ( '' !== $filters['q'] && false === stripos( $row['title'] . ' ' . $row['place'] . ' ' . $row['venue'] . ' ' . $row['tour_title'], $filters['q'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Portfolio row for mobile: no web URLs, plus event media and its always-loadable fallback.
	 *
	 * @param array<string,mixed> $row Portfolio row.
	 * @return array<string,mixed>
	 */
	private static function event_card( array $row ): array {
		unset( $row['url'], $row['image'] );
		$row['media']        = null;
		$row['fallback_url'] = '';
		if ( class_exists( 'LNKup_Event_Media' ) ) {
			$media        = LNKup_Event_Media::resolve( (int) $row['id'] );
			$row['media'] = LNKup_Event_Media::to_rest( $media );
			if ( ! empty( $media['uploaded'] ) ) {
				$fallback            = LNKup_Event_Media::fallback( (int) $row['id'] );
				$row['fallback_url'] = (string) ( $fallback['medium_url'] ?? $fallback['url'] ?? '' );
			}
		}
		return $row;
	}

	// ------------------------------------------------------------------ Operations.

	/**
	 * GET /organizer/events/{id}/operations-summary — one event's operations workspace for owners
	 * and assigned staff. Money is included only for people allowed to see event financials.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function operations_summary( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		$user_id  = get_current_user_id();
		$can      = array();
		foreach ( array( 'view_roster', 'check_in', 'edit_ops', 'edit_packets', 'edit_map', 'broadcast', 'view_intelligence', 'view_financials', 'manage_staff', 'owner' ) as $cap ) {
			$can[ $cap ] = LNKup_Capabilities::staff_can( $event_id, $cap, $user_id );
		}
		$summary = LNKup_Organizer_Presentation::event_summary( $event_id, $can['view_financials'] );
		if ( ! $summary ) {
			return new WP_Error( 'lnkup_not_found', __( 'Event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$event = self::event_card( $summary );

		$mode_map = array(
			'upcoming'  => 'pre_event',
			'undated'   => 'pre_event',
			'today'     => 'event_day',
			'completed' => 'post_event',
		);
		$mode = $mode_map[ $summary['chronology'] ] ?? 'pre_event';

		$checkin = self::checkin_populations( $event_id, $user_id );
		unset( $checkin['rows'] );

		$booths   = class_exists( 'LNKup_Booths' ) ? (array) LNKup_Booths::get_for_event( $event_id ) : array();
		$assigned = count( array_filter( $booths, static fn( $b ) => ! empty( $b['application_id'] ) ) );
		$confirmed = (int) ( $summary['capacity']['confirmed'] ?? $summary['applications']['confirmed'] ?? 0 );

		$packet    = LNKup_Organizer_Presentation::packet_tracking( $event_id );
		$readiness = class_exists( 'LNKup_Vendor_Readiness' ) ? (array) LNKup_Vendor_Readiness::event_readiness_summary( $event_id ) : array();
		// Vendor rows link to the vendor's own dashboard; organizers get the facts, not those URLs.
		$readiness['vendors'] = array_map(
			static function ( $row ) {
				return array(
					'application_id' => (int) ( $row['application_id'] ?? 0 ),
					'business_name'  => (string) ( $row['business_name'] ?? '' ),
					'percent'        => (int) ( $row['percent'] ?? 0 ),
					'tier'           => (string) ( $row['tier'] ?? '' ),
					'tier_label'     => (string) ( $row['tier_label'] ?? '' ),
					'status_label'   => (string) ( $row['status_label'] ?? '' ),
					'blocker'        => (string) ( $row['blocker'] ?? '' ),
				);
			},
			(array) ( $readiness['vendors'] ?? array() )
		);
		$windows   = class_exists( 'LNKup_Loadin_Schedule' ) ? (array) LNKup_Loadin_Schedule::get_windows( $event_id ) : array();
		$parking   = class_exists( 'LNKup_Parking' ) ? (array) LNKup_Parking::get_for_event( $event_id ) : array();
		$incidents = class_exists( 'LNKup_Incidents' ) ? (array) LNKup_Incidents::get_for_event( $event_id ) : array();
		$open_inc  = count( array_filter( $incidents, static fn( $i ) => ! in_array( (string) ( $i['status'] ?? '' ), array( 'resolved', 'closed' ), true ) ) );
		$staff     = class_exists( 'LNKup_Event_Staff' ) ? (array) LNKup_Event_Staff::get_for_event( $event_id ) : array();
		$lifecycle = class_exists( 'LNKup_Event_Lifecycle' ) ? (array) LNKup_Event_Lifecycle::get( $event_id ) : array();
		$my_role   = $can['owner'] ? 'owner' : ( class_exists( 'LNKup_Event_Staff' ) ? LNKup_Event_Staff::role_for( $event_id, $user_id ) : '' );

		$tasks = array();
		$task  = static function ( string $key, string $label, string $detail, bool $done, string $owner_cap, string $owner = 'organizer' ) use ( &$tasks, $can ) {
			$tasks[] = array(
				'key'      => $key,
				'label'    => $label,
				'detail'   => $detail,
				'done'     => $done,
				'owner'    => $owner,
				'can_act'  => ! empty( $can[ $owner_cap ] ),
			);
		};
		if ( 'post_event' !== $mode ) {
			$task(
				'booths',
				__( 'Assign booths', 'lnkup-marketplace' ),
				$confirmed > 0
					/* translators: 1: assigned, 2: confirmed vendors */
					? sprintf( __( '%1$d of %2$d confirmed vendors have a booth', 'lnkup-marketplace' ), min( $assigned, $confirmed ), $confirmed )
					: __( 'No confirmed vendors to place yet', 'lnkup-marketplace' ),
				$assigned >= $confirmed,
				'edit_ops'
			);
			$task(
				'packet',
				__( 'Publish the vendor packet', 'lnkup-marketplace' ),
				'draft' === $packet['stage'] ? __( 'Vendors cannot see event details yet', 'lnkup-marketplace' ) : (string) $packet['published'],
				'draft' !== $packet['stage'],
				'edit_packets'
			);
			$task(
				'loadin',
				__( 'Set load-in windows', 'lnkup-marketplace' ),
				$windows ? sprintf( /* translators: %d: windows */ _n( '%d window scheduled', '%d windows scheduled', count( $windows ), 'lnkup-marketplace' ), count( $windows ) ) : __( 'No load-in windows yet', 'lnkup-marketplace' ),
				! empty( $windows ),
				'edit_ops'
			);
			$task(
				'parking',
				__( 'Add parking details', 'lnkup-marketplace' ),
				$parking ? sprintf( /* translators: %d: areas */ _n( '%d parking area', '%d parking areas', count( $parking ), 'lnkup-marketplace' ), count( $parking ) ) : __( 'No parking details yet', 'lnkup-marketplace' ),
				! empty( $parking ),
				'edit_ops'
			);
			$task(
				'payments',
				__( 'Collect booth payments', 'lnkup-marketplace' ),
				$checkin['blocked']['payment_pending']
					? sprintf( /* translators: %d: vendors */ _n( '%d approved vendor has not paid', '%d approved vendors have not paid', $checkin['blocked']['payment_pending'], 'lnkup-marketplace' ), $checkin['blocked']['payment_pending'] )
					: __( 'Every booked vendor has paid', 'lnkup-marketplace' ),
				0 === $checkin['blocked']['payment_pending'],
				'view_roster',
				'vendor'
			);
			$task(
				'documents',
				__( 'Review vendor documents', 'lnkup-marketplace' ),
				$checkin['blocked']['documents']
					? sprintf( /* translators: %d: vendors */ _n( '%d vendor needs document review', '%d vendors need document review', $checkin['blocked']['documents'], 'lnkup-marketplace' ), $checkin['blocked']['documents'] )
					: __( 'No documents waiting', 'lnkup-marketplace' ),
				0 === $checkin['blocked']['documents'],
				'view_roster',
				'vendor'
			);
		}

		return rest_ensure_response(
			array(
				'event'       => $event,
				'mode'        => $mode,
				'my_role'     => $my_role,
				'can'         => $can,
				'lifecycle'   => array(
					'stage'       => (string) ( $lifecycle['stage'] ?? '' ),
					'stage_label' => (string) ( $lifecycle['stage_label'] ?? '' ),
					'progress'    => (int) ( $lifecycle['progress'] ?? 0 ),
				),
				'checkin'     => $checkin,
				'booths'      => array(
					'total'     => count( $booths ),
					'assigned'  => $assigned,
					'open'      => max( 0, count( $booths ) - $assigned ),
					'confirmed' => $confirmed,
					'unplaced'  => max( 0, $confirmed - $assigned ),
				),
				'packet'      => $packet,
				'readiness'   => $readiness,
				'logistics'   => array(
					'loadin_windows' => count( $windows ),
					'parking_areas'  => count( $parking ),
				),
				'incidents'   => array( 'open' => $open_inc, 'total' => count( $incidents ) ),
				'staff'       => array_map(
					static fn( $s ) => array( 'user_id' => (int) $s['user_id'], 'name' => (string) $s['name'], 'role' => (string) $s['staff_role'], 'role_label' => (string) $s['role_label'] ),
					$staff
				),
				'tasks'       => $tasks,
			)
		);
	}

	/**
	 * GET /organizer/events/{id}/score — event score with every factor explained.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function event_score( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Event_Score' ) ) {
			return new WP_Error( 'lnkup_unavailable', __( 'Event insights are not available right now.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		$score   = LNKup_Event_Score::compute( absint( $request['id'] ) );
		$weights = (array) ( $score['weights'] ?? array() );
		$total   = array_sum( $weights );
		$labels  = self::score_factor_labels();
		$factors = array();
		foreach ( (array) $score['breakdown'] as $key => $value ) {
			$factors[] = array(
				'key'         => (string) $key,
				'label'       => $labels[ $key ][0] ?? ucfirst( str_replace( '_', ' ', (string) $key ) ),
				'explanation' => $labels[ $key ][1] ?? '',
				'value'       => (int) $value,
				'weight_pct'  => $total ? (int) round( 100 * ( (float) ( $weights[ $key ] ?? 0 ) ) / $total ) : null,
				'estimated'   => in_array( $key, array( 'category' ), true ),
				'action'      => self::score_factor_actions()[ $key ] ?? null,
			);
		}
		return rest_ensure_response(
			array(
				'event_id'  => (int) $score['event_id'],
				'score'     => (int) $score['score'],
				'label'     => (string) $score['label'],
				'label_key' => (string) $score['label_key'],
				'is_new'    => (bool) $score['is_new'],
				'factors'   => $factors,
			)
		);
	}

	/**
	 * Where an organizer improves each factor (mobile destinations, not web URLs).
	 *
	 * @return array<string,array{label:string,target:string}>
	 */
	private static function score_factor_actions(): array {
		return array(
			'applications' => array( 'label' => __( 'Find vendors', 'lnkup-marketplace' ), 'target' => 'recruit' ),
			'conversion'   => array( 'label' => __( 'Share the event', 'lnkup-marketplace' ), 'target' => 'share' ),
			'approval'     => array( 'label' => __( 'Review applications', 'lnkup-marketplace' ), 'target' => 'review' ),
			'payment'      => array( 'label' => __( 'See unpaid invoices', 'lnkup-marketplace' ), 'target' => 'money' ),
			'readiness'    => array( 'label' => __( 'Open readiness', 'lnkup-marketplace' ), 'target' => 'operations' ),
			'checkin'      => array( 'label' => __( 'Open check-in', 'lnkup-marketplace' ), 'target' => 'checkin' ),
			'support'      => array( 'label' => __( 'Open operations', 'lnkup-marketplace' ), 'target' => 'operations' ),
			'incidents'    => array( 'label' => __( 'Open operations', 'lnkup-marketplace' ), 'target' => 'operations' ),
			'revenue'      => array( 'label' => __( 'See event money', 'lnkup-marketplace' ), 'target' => 'money' ),
		);
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function score_factor_labels(): array {
		return array(
			'applications' => array( __( 'Applications', 'lnkup-marketplace' ), __( 'How many vendors applied (10 applications scores full marks).', 'lnkup-marketplace' ) ),
			'conversion'   => array( __( 'Listing conversion', 'lnkup-marketplace' ), __( 'Share of listing views that became applications.', 'lnkup-marketplace' ) ),
			'approval'     => array( __( 'Approvals', 'lnkup-marketplace' ), __( 'Share of applications you approved.', 'lnkup-marketplace' ) ),
			'payment'      => array( __( 'Payments', 'lnkup-marketplace' ), __( 'Share of approved vendors who paid.', 'lnkup-marketplace' ) ),
			'readiness'    => array( __( 'Vendor readiness', 'lnkup-marketplace' ), __( 'Average readiness checklist of paid vendors.', 'lnkup-marketplace' ) ),
			'checkin'      => array( __( 'Check-in', 'lnkup-marketplace' ), __( 'Share of paid vendors checked in.', 'lnkup-marketplace' ) ),
			'no_show'      => array( __( 'Attendance', 'lnkup-marketplace' ), __( 'Paid vendors who were not marked as no-shows.', 'lnkup-marketplace' ) ),
			'support'      => array( __( 'Support', 'lnkup-marketplace' ), __( 'Lower when vendor support requests are open.', 'lnkup-marketplace' ) ),
			'incidents'    => array( __( 'Incidents', 'lnkup-marketplace' ), __( 'Lower when event incidents are open.', 'lnkup-marketplace' ) ),
			'category'     => array( __( 'Category mix', 'lnkup-marketplace' ), __( 'A fixed estimate until category balance is measured.', 'lnkup-marketplace' ) ),
			'revenue'      => array( __( 'Revenue', 'lnkup-marketplace' ), __( 'Follows payment completion.', 'lnkup-marketplace' ) ),
		);
	}

	// ------------------------------------------------------------------ Application review.

	/**
	 * GET /organizer/applications/{id}/review — everything needed to decide on one application:
	 * vendor identity and track record, event and booth, answers, document statuses (no files)
	 * and what each decision does. Decisions go through the existing roster and info routes.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function application_review( WP_REST_Request $request ) {
		$app_id = absint( $request['id'] );
		$app    = get_post( $app_id );
		if ( ! $app instanceof WP_Post || 'lnkup_application' !== $app->post_type ) {
			return new WP_Error( 'lnkup_not_found', __( 'Application not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$status     = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		$event_id   = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$opp_id     = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		$vendor_id  = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );

		$term_id  = $profile_id ? (int) get_post_meta( $profile_id, '_lnkup_primary_category', true ) : 0;
		$term     = $term_id ? get_term( $term_id ) : null;
		$category = $term instanceof WP_Term ? $term->name : (string) get_post_meta( $app_id, '_lnkup_selected_category', true );
		if ( '' !== $category && function_exists( 'lnkup_canonical_category_label' ) ) {
			$category = (string) lnkup_canonical_category_label( $category );
		}
		$completed = $vendor_id ? count(
			get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => '_lnkup_vendor_user_id', 'value' => (string) $vendor_id ),
						array( 'key' => '_lnkup_application_status', 'value' => 'completed' ),
					),
				)
			)
		) : 0;
		$reputation = $profile_id && class_exists( 'LNKup_Vendor_Reputation' ) ? LNKup_Vendor_Reputation::get( $profile_id ) : null;

		$answers = array();
		$raw     = json_decode( (string) get_post_meta( $app_id, '_lnkup_application_answers', true ), true );
		foreach ( is_array( $raw ) ? $raw : array() as $key => $value ) {
			$question = is_array( $value ) ? (string) ( $value['question'] ?? $value['label'] ?? $key ) : (string) $key;
			$answer   = is_array( $value ) ? ( $value['answer'] ?? $value['value'] ?? '' ) : $value;
			$answer   = is_array( $answer ) ? implode( ', ', array_map( 'strval', $answer ) ) : (string) $answer;
			if ( '' === trim( $answer ) ) {
				continue;
			}
			$answers[] = array(
				'question' => wp_strip_all_tags( is_numeric( $question ) ? __( 'Answer', 'lnkup-marketplace' ) : ucfirst( str_replace( '_', ' ', $question ) ) ),
				'answer'   => wp_strip_all_tags( $answer ),
			);
		}

		$documents = array();
		$doc_code  = '';
		if ( class_exists( 'LNKup_Verification' ) && $profile_id ) {
			$ctx      = LNKup_Verification::get_application_document_context( $app_id );
			$doc_code = (string) ( $ctx['code'] ?? '' );
			$items    = LNKup_Verification::get_required_verification_items_for_vendor( $profile_id, array( 'application_id' => $app_id, 'opportunity_id' => $opp_id ) );
			foreach ( (array) ( $items['required'] ?? array() ) as $item ) {
				$documents[] = array(
					'slug'   => (string) $item['slug'],
					'label'  => (string) $item['label'],
					'status' => (string) $item['status'],
				);
			}
		}

		$fee      = $opp_id ? (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ) : 0.0;
		$open     = in_array( $status, array( 'submitted', 'under_review', 'info_requested' ), true );
		$actions  = array(
			'approve'      => $open || 'waitlisted' === $status,
			'waitlist'     => $open,
			'reject'       => $open || 'waitlisted' === $status,
			'request_info' => in_array( $status, array( 'submitted', 'under_review' ), true ),
		);
		$approve_note = $fee > 0
			? sprintf(
				/* translators: %s: booth fee */
				__( 'Approving holds the booth and sends the vendor an invoice for %s. They have 7 days to pay; the booth is confirmed once they do.', 'lnkup-marketplace' ),
				'$' . number_format_i18n( $fee, floor( $fee ) === $fee ? 0 : 2 )
			)
			: __( 'This booth is free, so approving confirms the vendor right away.', 'lnkup-marketplace' );

		return rest_ensure_response(
			array(
				'application_id' => $app_id,
				'status'         => $status,
				'submitted_at'   => (string) get_post_meta( $app_id, '_lnkup_submitted_at', true ),
				'message'        => wp_strip_all_tags( (string) $app->post_content ),
				'setup_notes'    => wp_strip_all_tags( (string) get_post_meta( $app_id, '_lnkup_setup_notes', true ) ),
				'vendor'         => array(
					'profile_id'      => $profile_id,
					'business_name'   => LNKup_Organizer_Presentation::vendor_name( $app_id ),
					'logo_url'        => $profile_id ? (string) get_the_post_thumbnail_url( $profile_id, 'thumbnail' ) : '',
					'category'        => $category,
					'place'           => $profile_id && function_exists( 'lnkup_display_place' ) ? (string) lnkup_display_place( (string) get_post_meta( $profile_id, '_lnkup_city', true ), (string) get_post_meta( $profile_id, '_lnkup_state', true ) ) : '',
					'verified'        => 'approved' === (string) get_post_meta( $profile_id, '_lnkup_verification_status', true ),
					'completed_events'=> $completed,
					'rating'          => $reputation ? (float) ( $reputation['avg_rating'] ?? 0 ) : 0.0,
					'review_count'    => $reputation ? (int) ( $reputation['review_count'] ?? 0 ) : 0,
					'reputation'      => $reputation && empty( $reputation['is_new'] ) ? (string) ( $reputation['label'] ?? '' ) : '',
				),
				'event'          => array(
					'id'         => $event_id,
					'title'      => LNKup_Organizer_Presentation::title( $event_id ),
					'date'       => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
					'place'      => LNKup_Organizer_Presentation::place( $event_id ),
					'booth_type' => $opp_id ? (string) ( function_exists( 'lnkup_plain_text' ) ? lnkup_plain_text( get_the_title( $opp_id ) ) : get_the_title( $opp_id ) ) : '',
					'open'       => $opp_id ? (int) LNKup_Organizer_Presentation::opportunity_capacity( $opp_id )['open'] : 0,
					'unlimited'  => $opp_id ? (bool) LNKup_Organizer_Presentation::opportunity_capacity( $opp_id )['unlimited'] : true,
				),
				'fee'            => $fee,
				'payment_due_at' => (string) get_post_meta( $app_id, '_lnkup_payment_due_at', true ),
				'answers'        => $answers,
				'documents'      => array( 'code' => $doc_code, 'items' => $documents ),
				'actions'        => $actions,
				'approve_note'   => $approve_note,
			)
		);
	}

	// ------------------------------------------------------------------ Check-in.

	/**
	 * GET /operations/events/{id}/checkin-summary — read-only roster for vendor lookup plus
	 * populations. Expected arrivals counts only vendors check-in would accept (ready or already
	 * checked in); unpaid, document-blocked and invalid bookings are reported separately.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function checkin_summary( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		$data     = self::checkin_populations( $event_id, get_current_user_id() );
		$data['event_id']     = $event_id;
		$data['title']        = LNKup_Organizer_Presentation::title( $event_id );
		$data['date']         = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$data['chronology']   = LNKup_Organizer_Presentation::chronology( $data['date'] );
		$data['generated_at'] = gmdate( 'c' );
		return rest_ensure_response( $data );
	}

	/**
	 * Evaluate every booked application of an event with the shared check-in rules.
	 *
	 * @param int $event_id Event.
	 * @param int $user_id  Actor.
	 * @return array<string,mixed>
	 */
	private static function checkin_populations( int $event_id, int $user_id ): array {
		$statuses = array_merge( LNKup_Organizer_Presentation::HELD_STATUSES, LNKup_Organizer_Presentation::CONFIRMED_STATUSES );
		$app_ids  = array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => '_lnkup_event_id', 'value' => (string) $event_id ),
						array( 'key' => '_lnkup_application_status', 'value' => $statuses, 'compare' => 'IN' ),
					),
				)
			)
		);
		if ( $app_ids ) {
			update_meta_cache( 'post', $app_ids );
		}
		$booth_by_app = array();
		if ( class_exists( 'LNKup_Booths' ) ) {
			foreach ( (array) LNKup_Booths::get_for_event( $event_id ) as $booth ) {
				if ( ! empty( $booth['application_id'] ) ) {
					$booth_by_app[ (int) $booth['application_id'] ] = (string) ( $booth['booth_number'] ?? '' );
				}
			}
		}

		$counts = array(
			'booked'          => 0,
			'expected'        => 0,
			'checked_in'      => 0,
			'waiting'         => 0,
			'no_show'         => 0,
			'invalid'         => 0,
			'blocked'         => array( 'payment_pending' => 0, 'documents' => 0 ),
		);
		$rows = array();
		foreach ( $app_ids as $app_id ) {
			$verdict = LNKup_Checkins::evaluate( $app_id, '', $user_id, $event_id );
			$reason  = (string) ( $verdict['reason'] ?? '' );
			if ( 'forbidden' === $reason ) {
				continue;
			}
			$counts['booked']++;
			$checked_in = ! empty( $verdict['checked_in'] );
			$ops        = (string) ( $verdict['ops_status'] ?? '' );
			if ( 'invalid_booking' === $reason ) {
				$counts['invalid']++;
			} elseif ( 'payment_pending' === $reason || 'documents' === $reason ) {
				$counts['blocked'][ $reason ]++;
			} else {
				$counts['expected']++;
				if ( $checked_in ) {
					$counts['checked_in']++;
				} elseif ( 'no_show' === $ops ) {
					$counts['no_show']++;
				} else {
					$counts['waiting']++;
				}
			}
			$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
			$term_id    = $profile_id ? (int) get_post_meta( $profile_id, '_lnkup_primary_category', true ) : 0;
			$term       = $term_id ? get_term( $term_id ) : null;
			$rows[]     = array(
				'application_id' => $app_id,
				'vendor_name'    => LNKup_Organizer_Presentation::vendor_name( $app_id ),
				'category'       => $term instanceof WP_Term ? $term->name : '',
				'booth'          => $booth_by_app[ $app_id ] ?? '',
				'reason'         => $reason,
				'eligible'       => ! empty( $verdict['eligible'] ),
				'checked_in'     => $checked_in,
				'checked_in_at'  => (string) ( $verdict['checked_in_at'] ?? '' ),
				'ops_status'     => $ops,
				'actions'        => array_values( (array) ( $verdict['actions'] ?? array() ) ),
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcasecmp( $a['vendor_name'], $b['vendor_name'] );
			}
		);
		$counts['arrival_pct'] = $counts['expected'] ? (int) round( 100 * $counts['checked_in'] / $counts['expected'] ) : 0;
		$counts['rows']        = $rows;
		return $counts;
	}

	// ------------------------------------------------------------------ Invites.

	/**
	 * GET /organizer/mobile/invites.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function invites( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Vendor_Invites::organizer_overview( get_current_user_id() ) );
	}

	/**
	 * POST /crm/invites/{id}/remind.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function remind_invite( WP_REST_Request $request ) {
		$result = LNKup_Vendor_Invites::remind( absint( $request['id'] ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'invite' => $result ) );
	}

	/**
	 * POST /crm/invites/{id}/cancel.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function cancel_invite( WP_REST_Request $request ) {
		$result = LNKup_Vendor_Invites::cancel( absint( $request['id'] ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'invite' => $result ) );
	}

	// ------------------------------------------------------------------ Money.

	/**
	 * GET /financial/organizer-payouts/preview — read-only; never creates a payout.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function payout_preview( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Organizer_Payouts' ) ) {
			return new WP_Error( 'lnkup_unavailable', __( 'Cash-out is not available right now.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		try {
			$preview = LNKup_Organizer_Payouts::preview(
				get_current_user_id(),
				(float) $request->get_param( 'amount' ),
				(string) $request->get_param( 'type' )
			);
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'LNKup payout preview: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return new WP_Error( 'lnkup_unavailable', __( 'We could not check your balance. Try again in a moment.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		return rest_ensure_response( $preview );
	}
}
