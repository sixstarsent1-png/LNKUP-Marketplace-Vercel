<?php
/**
 * Event Intelligence REST API.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Intelligence_REST {

	const NS = 'lnkup/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$org    = array( 'permission_callback' => array( __CLASS__, 'organizer_permission' ) );
		$vendor = array( 'permission_callback' => array( __CLASS__, 'vendor_permission' ) );
		$admin  = array( 'permission_callback' => array( __CLASS__, 'admin_permission' ) );

		register_rest_route( self::NS, '/intelligence/dashboard', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'dashboard' ),
		) ) );

		register_rest_route( self::NS, '/intelligence/marketplace-health', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'marketplace_health' ),
		) ) );

		register_rest_route( self::NS, '/intelligence/events/(?P<id>\d+)', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_summary' ),
		) ) );

		register_rest_route( self::NS, '/intelligence/events/(?P<id>\d+)/score', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_score' ),
		) ) );

		register_rest_route( self::NS, '/intelligence/events/(?P<id>\d+)/funnel/application', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'application_funnel' ),
		) ) );

		register_rest_route( self::NS, '/intelligence/events/(?P<id>\d+)/funnel/payment', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'payment_funnel' ),
		) ) );

		register_rest_route( self::NS, '/intelligence/events/(?P<id>\d+)/risks', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_risks' ),
		) ) );

		register_rest_route( self::NS, '/intelligence/events/(?P<id>\d+)/actions', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_actions' ),
		) ) );

		register_rest_route( self::NS, '/intelligence/events/(?P<id>\d+)/report', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_report' ),
		) ) );

		register_rest_route( self::NS, '/intelligence/opportunities/(?P<id>\d+)/score', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'opportunity_score' ),
			'permission_callback' => array( __CLASS__, 'opportunity_permission' ),
		) );

		register_rest_route( self::NS, '/intelligence/trends/categories', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'category_trends' ),
			'permission_callback' => array( __CLASS__, 'scoped_trends_permission' ),
		) );

		register_rest_route( self::NS, '/intelligence/trends/cities', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'city_trends' ),
			'permission_callback' => array( __CLASS__, 'scoped_trends_permission' ),
		) );

		register_rest_route( self::NS, '/intelligence/revenue', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'revenue' ),
			'permission_callback' => array( __CLASS__, 'revenue_permission' ),
		) );

		register_rest_route( self::NS, '/intelligence/organizer/summary', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'organizer_summary' ),
		) ) );

		register_rest_route( self::NS, '/intelligence/vendors/me', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'vendor_me' ),
		) ) );

		register_rest_route( self::NS, '/intelligence/vendors/(?P<id>\d+)/reliability', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'vendor_reliability' ),
			'permission_callback' => array( __CLASS__, 'organizer_role_permission' ),
		) );

		register_rest_route( self::NS, '/intelligence/applications/(?P<id>\d+)/intel', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'application_intel' ),
			'permission_callback' => array( __CLASS__, 'app_intel_permission' ),
		) );

		register_rest_route( self::NS, '/intelligence/predictions/event/(?P<id>\d+)', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_predictions' ),
		) ) );

		register_rest_route( self::NS, '/intelligence/predictions/market', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'market_predictions' ),
		) ) );

		register_rest_route( self::NS, '/intelligence/leaderboard/events', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_leaderboard' ),
		) ) );
	}

	public static function organizer_permission( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] ?? 0 );
		if ( $event_id && ! LNKup_Capabilities::can_view_event_intelligence( $event_id ) ) {
			return false;
		}
		return current_user_can( 'lnkup_review_applications' )
			|| ( class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer() )
			|| current_user_can( 'manage_options' )
			|| LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * Organizer role without an event in the path (vendor-level intelligence).
	 */
	public static function organizer_role_permission() {
		return current_user_can( 'lnkup_review_applications' )
			|| ( class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer() )
			|| LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * Opportunity score: the opportunity's event must be viewable by the caller.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function opportunity_permission( WP_REST_Request $request ) {
		$opp_id = absint( $request['id'] );
		if ( 'lnkup_opportunity' !== get_post_type( $opp_id ) ) {
			return false;
		}
		return LNKup_Capabilities::can_view_event_intelligence( (int) get_post_meta( $opp_id, '_lnkup_event_id', true ) );
	}

	public static function vendor_permission() {
		return current_user_can( 'lnkup_manage_own_profile' ) || LNKup_Capabilities::user_has_manage_all();
	}

	public static function admin_permission() {
		return LNKup_Capabilities::can_view_market_intelligence();
	}

	public static function scoped_trends_permission() {
		return self::vendor_permission() || self::organizer_permission( new WP_REST_Request( 'GET', '/' ) );
	}

	public static function revenue_permission( WP_REST_Request $request ) {
		if ( LNKup_Capabilities::can_view_market_intelligence() ) {
			return true;
		}
		$event_id = absint( $request->get_param( 'event_id' ) );
		if ( $event_id ) {
			return LNKup_Capabilities::can_view_event_intelligence( $event_id );
		}
		return self::organizer_permission( $request );
	}

	public static function app_intel_permission( WP_REST_Request $request ) {
		$app_id = absint( $request['id'] );
		if ( LNKup_Capabilities::vendor_owns_application( $app_id ) ) {
			return true;
		}
		return LNKup_Capabilities::user_owns_post( $app_id, get_current_user_id(), '_lnkup_organizer_user_id' ) || LNKup_Capabilities::user_has_manage_all();
	}

	public static function dashboard( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$filters = self::parse_filters( $request );

		try {
			$intel = lnkup_get_event_intelligence_summary( $user_id );
			if ( is_wp_error( $intel ) ) {
				$intel = array(
					'kpis'     => array(),
					'insights' => array(),
					'events'   => array(),
					'warnings' => array(),
				);
			}
			if ( ! is_array( $intel ) ) {
				$intel = array();
			}

			$health  = array();
			$summary = array();
			if ( class_exists( 'LNKup_Organizer_Intelligence' ) ) {
				$health  = LNKup_Organizer_Intelligence::health( $user_id );
				$summary = LNKup_Organizer_Intelligence::summary( $user_id );
			}
			if ( is_wp_error( $health ) ) {
				$health = array();
			}
			if ( is_wp_error( $summary ) ) {
				$summary = array();
			}
			if ( ! is_array( $health ) ) {
				$health = array();
			}
			if ( ! is_array( $summary ) ) {
				$summary = array();
			}

			return rest_ensure_response(
				array_merge(
					$intel,
					array(
						'health'      => $health,
						'summary'     => $summary,
						'marketplace' => LNKup_Capabilities::can_view_market_intelligence() ? self::admin_marketplace_snapshot() : null,
						'filters'     => $filters,
					)
				)
			);
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'lnkup intelligence dashboard: ' . $e->getMessage() );
			}
			return rest_ensure_response(
				array(
					'kpis'     => array(),
					'insights' => array( __( 'More data needed.', 'lnkup-marketplace' ) ),
					'events'   => array(),
					'warnings' => array(
						array(
							'level'   => 'high',
							'message' => __( 'Intelligence data is temporarily unavailable.', 'lnkup-marketplace' ),
						),
					),
					'health'   => array(),
					'summary'  => array(),
				)
			);
		}
	}

	public static function marketplace_health( WP_REST_Request $request ) {
		return rest_ensure_response( self::admin_marketplace_snapshot() );
	}

	public static function event_summary( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		return rest_ensure_response( self::event_dto( $event_id ) );
	}

	public static function event_score( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Event_Score::compute( absint( $request['id'] ) ) );
	}

	public static function application_funnel( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Funnel_Analytics::application_funnel( absint( $request['id'] ) ) );
	}

	public static function payment_funnel( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Funnel_Analytics::payment_funnel( absint( $request['id'] ) ) );
	}

	public static function event_risks( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		LNKup_Risk_Engine::evaluate_event( $event_id );
		return rest_ensure_response( array( 'items' => LNKup_Risk_Engine::get_alerts( 'event', $event_id ) ) );
	}

	public static function event_actions( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Recommended_Actions::for_event( absint( $request['id'] ) ) ) );
	}

	public static function event_report( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		$report   = LNKup_Post_Event_Report::maybe_generate( $event_id );
		if ( ! $report ) {
			$report = LNKup_Post_Event_Report::generate( $event_id );
		}
		return rest_ensure_response( $report );
	}

	public static function opportunity_score( WP_REST_Request $request ) {
		$opp_id = absint( $request['id'] );
		if ( ! LNKup_Capabilities::user_owns_post( $opp_id, get_current_user_id(), '_lnkup_organizer_user_id' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( LNKup_Opportunity_Score::compute( $opp_id ) );
	}

	public static function category_trends( WP_REST_Request $request ) {
		$args = self::parse_filters( $request );
		if ( ! LNKup_Capabilities::can_view_market_intelligence() ) {
			$args['organizer_id'] = get_current_user_id();
		}
		return rest_ensure_response( array( 'items' => LNKup_Market_Trends::categories( $args ) ) );
	}

	public static function city_trends( WP_REST_Request $request ) {
		$args = self::parse_filters( $request );
		if ( ! LNKup_Capabilities::can_view_market_intelligence() ) {
			$args['organizer_id'] = get_current_user_id();
		}
		return rest_ensure_response( array( 'items' => LNKup_Market_Trends::cities( $args ) ) );
	}

	public static function revenue( WP_REST_Request $request ) {
		$args = self::parse_filters( $request );
		if ( ! LNKup_Capabilities::can_view_market_intelligence() ) {
			$args['organizer_id'] = get_current_user_id();
		}
		return rest_ensure_response( LNKup_Revenue_Intelligence::query( $args ) );
	}

	public static function organizer_summary( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Organizer_Intelligence::summary( get_current_user_id() ) );
	}

	public static function vendor_me( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Vendor_Intelligence::for_vendor( get_current_user_id() ) );
	}

	public static function vendor_reliability( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Vendor_Reliability_Intel::for_profile( absint( $request['id'] ) ) );
	}

	public static function application_intel( WP_REST_Request $request ) {
		$app_id = absint( $request['id'] );
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		$readiness = LNKup_Vendor_Readiness::checklist( $app_id );
		$reliability = $profile_id ? LNKup_Vendor_Reliability_Intel::for_profile( $profile_id ) : null;
		return rest_ensure_response(
			array(
				'application_id' => $app_id,
				'status'         => get_post_meta( $app_id, '_lnkup_application_status', true ),
				'message'        => get_post( $app_id ) ? get_post( $app_id )->post_content : '',
				'readiness'      => $readiness,
				'reliability'    => $reliability,
				'profile'        => $profile_id ? LNKup_Vendor_CRM_Profile::get( $profile_id, get_current_user_id() ) : null,
				'status_label'   => lnkup_application_status_label( (string) get_post_meta( $app_id, '_lnkup_application_status', true ) ),
				'info_history'   => LNKup_Applications::info_history( $app_id ),
				'vendor_history' => self::vendor_history_with_organizer( $app_id ),
			)
		);
	}

	/**
	 * This vendor's other applications to the same organizer (newest first, max 8).
	 *
	 * @param int $app_id Current application ID.
	 * @return array<int, array{event:string,status:string,date:string}>
	 */
	private static function vendor_history_with_organizer( $app_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => 8,
				'fields'         => 'ids',
				'post__not_in'   => array( $app_id ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_vendor_user_id', 'value' => (string) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ) ),
					array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) get_post_meta( $app_id, '_lnkup_organizer_user_id', true ) ),
				),
			)
		);
		return array_map(
			static function ( $id ) {
				$event_id = (int) get_post_meta( $id, '_lnkup_event_id', true );
				return array(
					'event'  => $event_id ? get_the_title( $event_id ) : '',
					'status' => lnkup_application_status_label( (string) get_post_meta( $id, '_lnkup_application_status', true ) ),
					'date'   => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
				);
			},
			$ids
		);
	}

	public static function event_predictions( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Predictive_Placeholders::for_event( absint( $request['id'] ) ) );
	}

	public static function market_predictions( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'disclaimer' => __( 'Rule-based marketplace estimates. AI predictions coming soon.', 'lnkup-marketplace' ),
				'revenue'    => LNKup_Revenue_Intelligence::marketplace_summary(),
				'categories' => LNKup_Market_Trends::categories( array( 'limit' => 5 ) ),
			)
		);
	}

	public static function event_leaderboard( WP_REST_Request $request ) {
		$limit  = min( 20, absint( $request->get_param( 'limit' ) ?: 10 ) );
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'post_status'    => 'publish',
			)
		);
		$board = array();
		foreach ( $events as $event_id ) {
			$sc = LNKup_Event_Score::compute( $event_id );
			$board[] = array(
				'event_id' => $event_id,
				'title'    => get_the_title( $event_id ),
				'score'    => $sc['score'],
				'label'    => $sc['label'],
				'city'     => get_post_meta( $event_id, '_lnkup_city', true ),
			);
		}
		usort(
			$board,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return rest_ensure_response( array( 'items' => array_slice( $board, 0, $limit ) ) );
	}

	private static function event_dto( $event_id ) {
		return array(
			'event_id'           => $event_id,
			'title'              => get_the_title( $event_id ),
			'score'              => LNKup_Event_Score::compute( $event_id ),
			'application_funnel' => LNKup_Funnel_Analytics::application_funnel( $event_id ),
			'payment_funnel'     => LNKup_Funnel_Analytics::payment_funnel( $event_id ),
			'revenue'            => LNKup_Revenue_Intelligence::for_event( $event_id ),
			'risks'              => LNKup_Risk_Engine::get_alerts( 'event', $event_id ),
			'actions'            => LNKup_Recommended_Actions::for_event( $event_id ),
			'operations'         => class_exists( 'LNKup_Ops_Analytics' ) ? LNKup_Ops_Analytics::for_event( $event_id ) : array(),
			'predictions'        => LNKup_Predictive_Placeholders::for_event( $event_id ),
			'report'             => LNKup_Post_Event_Report::get( $event_id ),
			'snapshot_history'   => LNKup_Intelligence_Snapshots::get_history( 'event', $event_id, 14 ),
			'weather'            => class_exists( 'LNKup_Weather_Service' ) ? LNKup_Weather_Service::for_event( $event_id ) : null,
		);
	}

	private static function admin_marketplace_snapshot() {
		return array(
			'health_score' => self::market_health_score(),
			'revenue'      => LNKup_Revenue_Intelligence::marketplace_summary(),
			'categories'   => LNKup_Market_Trends::categories( array( 'limit' => 8 ) ),
			'cities'       => LNKup_Market_Trends::cities( array( 'limit' => 8 ) ),
			'top_vendors'  => array(),
			'at_risk'      => array(),
			'analytics'    => LNKup_Marketplace_Analytics::get_dashboard_metrics(),
		);
	}

	private static function market_health_score() {
		$rev = LNKup_Revenue_Intelligence::marketplace_summary();
		$score = min( 100, 40 + min( 40, (int) $rev['paid_bookings'] ) + ( $rev['gmv'] > 1000 ? 20 : 10 ) );
		return array(
			'score' => $score,
			'label' => $score >= 75 ? __( 'Healthy', 'lnkup-marketplace' ) : __( 'Growing', 'lnkup-marketplace' ),
		);
	}

	private static function parse_filters( WP_REST_Request $request ) {
		return array_filter(
			array(
				'date_from'    => sanitize_text_field( $request->get_param( 'date_from' ) ?: '' ),
				'date_to'      => sanitize_text_field( $request->get_param( 'date_to' ) ?: '' ),
				'city'         => sanitize_text_field( $request->get_param( 'city' ) ?: '' ),
				'state'        => sanitize_text_field( $request->get_param( 'state' ) ?: '' ),
				'organizer_id' => absint( $request->get_param( 'organizer_id' ) ),
				'event_id'     => absint( $request->get_param( 'event_id' ) ),
				'category'     => sanitize_text_field( $request->get_param( 'category' ) ?: '' ),
				'limit'        => absint( $request->get_param( 'limit' ) ?: 10 ),
			)
		);
	}
}
