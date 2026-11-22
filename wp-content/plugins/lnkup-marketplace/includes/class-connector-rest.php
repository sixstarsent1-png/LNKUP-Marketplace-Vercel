<?php
/**
 * City Connector REST API.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Connector_REST {

	const NS = 'lnkup/v1';

	/**
	 * Init routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		$connector = array( 'permission_callback' => array( __CLASS__, 'connector_permission' ) );

		register_rest_route( self::NS, '/connector/dashboard', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_dashboard' ),
		) ) );

		register_rest_route( self::NS, '/connector/links', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_links' ),
		) ) );

		register_rest_route( self::NS, '/connector/referrals', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_referrals' ),
		) ) );

		register_rest_route( self::NS, '/connector/rewards', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_rewards' ),
		) ) );

		register_rest_route( self::NS, '/connector/onboarding', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_onboarding' ),
		) ) );

		register_rest_route( self::NS, '/connector/onboarding/step', array_merge( $connector, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'post_onboarding_step' ),
		) ) );

		register_rest_route( self::NS, '/connector/funnel', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_funnel' ),
		) ) );

		register_rest_route( self::NS, '/connector/earnings', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_earnings' ),
		) ) );

		register_rest_route( self::NS, '/connector/resources', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_resources' ),
		) ) );

		register_rest_route( self::NS, '/connector/terms', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_terms' ),
		) ) );

		register_rest_route( self::NS, '/connector/calculator', array_merge( $connector, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'post_calculator' ),
		) ) );

		register_rest_route( self::NS, '/connector/profile', array_merge( $connector, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'post_profile' ),
		) ) );

		register_rest_route( self::NS, '/connector/rank', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_rank' ),
		) ) );

		register_rest_route( self::NS, '/connector/rank/roadmap', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_rank_roadmap' ),
		) ) );

		register_rest_route( self::NS, '/connector/rank/history', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_rank_history' ),
		) ) );

		register_rest_route( self::NS, '/connector/rank/acknowledge', array_merge( $connector, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'post_rank_acknowledge' ),
		) ) );

		register_rest_route( self::NS, '/connector/leaderboard', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_leaderboard' ),
		) ) );

		register_rest_route( self::NS, '/connector/studio', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_studio' ),
		) ) );

		register_rest_route( self::NS, '/connector/studio/templates/(?P<id>\d+)', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_studio_template' ),
		) ) );

		register_rest_route( self::NS, '/connector/studio/posts', array_merge( $connector, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'post_studio_post' ),
		) ) );

		register_rest_route( self::NS, '/connector/studio/analytics', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_studio_analytics' ),
		) ) );

		register_rest_route( self::NS, '/connector/interest', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'submit_interest' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/referrals/resolve', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'resolve_code' ),
			'permission_callback' => '__return_true',
		) );

		$admin = array( 'permission_callback' => array( __CLASS__, 'admin_permission' ) );

		register_rest_route( self::NS, '/admin/connectors', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'admin_list' ),
		) ) );

		register_rest_route( self::NS, '/admin/connectors/(?P<id>\d+)/approve', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'admin_approve' ),
		) ) );

		register_rest_route( self::NS, '/admin/connectors/(?P<id>\d+)/pause', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'admin_pause' ),
		) ) );

		register_rest_route( self::NS, '/admin/connectors/(?P<id>\d+)/reject', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'admin_reject' ),
		) ) );

		register_rest_route( self::NS, '/admin/connectors/(?P<id>\d+)/reward', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'admin_reward' ),
		) ) );

		register_rest_route( self::NS, '/admin/connectors/(?P<id>\d+)/rank', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'admin_get_rank' ),
		) ) );

		register_rest_route( self::NS, '/admin/connectors/(?P<id>\d+)/rank/recalculate', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'admin_recalculate_rank' ),
		) ) );

		register_rest_route( self::NS, '/admin/connectors/(?P<id>\d+)/rank/override', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'admin_override_rank' ),
		) ) );

		register_rest_route( self::NS, '/admin/connectors/(?P<id>\d+)/rank/bonus/reverse', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'admin_reverse_bonus' ),
		) ) );

		register_rest_route( self::NS, '/admin/connectors/attributions', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'admin_attributions' ),
		) ) );

		register_rest_route( self::NS, '/admin/connectors/attributions/(?P<payment_id>\d+)/resolve', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'admin_resolve_attribution' ),
		) ) );
	}

	/**
	 * Connector permission.
	 */
	public static function connector_permission() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( LNKup_Capabilities::user_has_manage_all() ) {
			return true;
		}
		return LNKup_Roles::is_connector();
	}

	/**
	 * Admin permission.
	 */
	public static function admin_permission() {
		return LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * Get connector for current user.
	 */
	private static function current_connector() {
		$user_id = get_current_user_id();
		if ( LNKup_Capabilities::user_has_manage_all() && ! empty( $_GET['connector_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return LNKup_Connector::get( absint( $_GET['connector_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return LNKup_Connector::get_by_user_id( $user_id );
	}

	public static function get_dashboard() {
		$connector = self::current_connector();
		if ( ! $connector || 'active' !== ( $connector['status'] ?? '' ) ) {
			return new WP_Error( 'forbidden', __( 'Active connector access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( LNKup_Connector::get_dashboard_summary( (int) $connector['id'] ) );
	}

	public static function get_links() {
		$connector = self::current_connector();
		if ( ! $connector ) {
			return new WP_Error( 'forbidden', __( 'Connector access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( LNKup_Connector::build_referral_links( $connector ) );
	}

	public static function get_referrals( WP_REST_Request $request ) {
		$connector = self::current_connector();
		if ( ! $connector ) {
			return new WP_Error( 'forbidden', __( 'Connector access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$role = sanitize_key( (string) $request->get_param( 'role' ) );
		return rest_ensure_response( array(
			'vendors'    => LNKup_Connector::list_referred_users( (int) $connector['id'], 'vendor' ),
			'organizers' => LNKup_Connector::list_referred_users( (int) $connector['id'], 'organizer' ),
			'activity'   => LNKup_Connector::public_activity( (int) $connector['id'] ),
		) );
	}

	public static function get_rewards() {
		$connector = self::current_connector();
		if ( ! $connector ) {
			return new WP_Error( 'forbidden', __( 'Connector access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( LNKup_Connector_Rewards::list_for_connector( (int) $connector['id'] ) );
	}

	/**
	 * Require a connector row for the current user.
	 */
	private static function require_connector() {
		$connector = self::current_connector();
		if ( ! $connector ) {
			return new WP_Error( 'forbidden', __( 'Connector access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return $connector;
	}

	public static function get_onboarding() {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		$payload = LNKup_Connector::get_onboarding( (int) $connector['id'] );
		$payload['links']     = LNKup_Connector::build_referral_links( $connector );
		$payload['connector'] = LNKup_Connector::public_connector( $connector );
		$payload['terms']     = LNKup_Connector_Rewards::terms( (int) $connector['id'] );
		$code                 = LNKup_Connector::get_code_for_connector( (int) $connector['id'] );
		$payload['code']      = $code['code'] ?? '';
		return rest_ensure_response( $payload );
	}

	public static function post_onboarding_step( WP_REST_Request $request ) {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		$result = LNKup_Connector::mark_onboarding_step( (int) $connector['id'], (string) $request->get_param( 'step' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function get_funnel() {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		return rest_ensure_response( LNKup_Connector::get_funnel( (int) $connector['id'] ) );
	}

	public static function get_earnings() {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		return rest_ensure_response( LNKup_Connector::get_earnings( (int) $connector['id'] ) );
	}

	public static function get_resources() {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		return rest_ensure_response( LNKup_Connector::get_resources( (int) $connector['id'] ) );
	}

	public static function get_terms() {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		return rest_ensure_response( LNKup_Connector_Rewards::terms( (int) $connector['id'] ) );
	}

	public static function post_calculator( WP_REST_Request $request ) {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		$booth = (float) $request->get_param( 'booth_price' );
		$books = (int) $request->get_param( 'paid_bookings' );
		$orgs  = (int) $request->get_param( 'organizers' );
		return rest_ensure_response( LNKup_Connector_Rewards::estimate_earnings( $booth, $books, $orgs, (int) $connector['id'] ) );
	}

	public static function post_profile( WP_REST_Request $request ) {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		$result = LNKup_Connector::update_profile(
			(int) $connector['id'],
			array(
				'display_name'       => $request->get_param( 'display_name' ),
				'phone'              => $request->get_param( 'phone' ),
				'social_url'         => $request->get_param( 'social_url' ),
				'city'               => $request->get_param( 'city' ),
				'bio'                => $request->get_param( 'bio' ),
				'payout_preference'  => $request->get_param( 'payout_preference' ),
				'leaderboard_opt_in' => $request->get_param( 'leaderboard_opt_in' ),
				'growth_styles'      => $request->get_param( 'growth_styles' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function submit_interest( WP_REST_Request $request ) {
		$result = LNKup_Connector::submit_interest( array(
			'name'         => $request->get_param( 'name' ),
			'email'        => $request->get_param( 'email' ),
			'phone'        => $request->get_param( 'phone' ),
			'city'         => $request->get_param( 'city' ),
			'market_slug'  => $request->get_param( 'market_slug' ),
			'social_url'   => $request->get_param( 'social_url' ),
			'connected_to' => $request->get_param( 'connected_to' ),
			'why'          => $request->get_param( 'why' ),
		) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function resolve_code( WP_REST_Request $request ) {
		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$data = LNKup_Connector::resolve_code_public( $code );
		if ( ! $data ) {
			return rest_ensure_response( array( 'valid' => false ) );
		}
		return rest_ensure_response( $data );
	}

	public static function admin_list() {
		global $wpdb;
		$table = LNKup_Connector_Tables::connectors_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200", ARRAY_A );
		return rest_ensure_response( array(
			'connectors' => $rows,
			'metrics'    => LNKup_Connector::admin_metrics(),
		) );
	}

	public static function admin_approve( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$result = LNKup_Connector::approve( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'connector' => $result ) );
	}

	public static function admin_pause( WP_REST_Request $request ) {
		global $wpdb;
		$id = absint( $request['id'] );
		$wpdb->update(
			LNKup_Connector_Tables::connectors_table(),
			array( 'status' => 'paused', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id )
		);
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function admin_reject( WP_REST_Request $request ) {
		global $wpdb;
		$id = absint( $request['id'] );
		$wpdb->update(
			LNKup_Connector_Tables::connectors_table(),
			array( 'status' => 'rejected', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id )
		);
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function get_rank() {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		return rest_ensure_response( LNKup_Connector_Ranks::dto( (int) $connector['id'] ) );
	}

	public static function get_rank_roadmap() {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		return rest_ensure_response( LNKup_Connector_Ranks::roadmap( (int) $connector['id'] ) );
	}

	public static function get_rank_history() {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		return rest_ensure_response( array( 'history' => LNKup_Connector_Ranks::history( (int) $connector['id'] ) ) );
	}

	public static function post_rank_acknowledge() {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		$result = LNKup_Connector_Ranks::acknowledge_celebration( (int) $connector['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function get_leaderboard( WP_REST_Request $request ) {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		$market = sanitize_title( (string) ( $request->get_param( 'market' ) ?: ( $connector['market_slug'] ?? '' ) ) );
		$period = sanitize_key( (string) ( $request->get_param( 'period' ) ?: 'all' ) );
		return rest_ensure_response( LNKup_Connector_Ranks::leaderboard( $market, $period ) );
	}

	public static function get_studio() {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		$result = LNKup_Connector_Studio::dashboard( (int) $connector['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function get_studio_template( WP_REST_Request $request ) {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		$result = LNKup_Connector_Studio::get_template( (int) $connector['id'], absint( $request['id'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function post_studio_post( WP_REST_Request $request ) {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		$result = LNKup_Connector_Studio::save_post(
			(int) $connector['id'],
			array(
				'id'           => $request->get_param( 'id' ),
				'title'        => $request->get_param( 'title' ),
				'status'       => $request->get_param( 'status' ),
				'url'          => $request->get_param( 'url' ),
				'platform'     => $request->get_param( 'platform' ),
				'audience'     => $request->get_param( 'audience' ),
				'city'         => $request->get_param( 'city' ),
				'campaign'     => $request->get_param( 'campaign' ),
				'content_type' => $request->get_param( 'content_type' ),
				'template_id'  => $request->get_param( 'template_id' ),
				'published_at' => $request->get_param( 'published_at' ),
				'manual_views' => $request->get_param( 'manual_views' ),
				'reminder_at'  => $request->get_param( 'reminder_at' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function get_studio_analytics() {
		$connector = self::require_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		$result = LNKup_Connector_Studio::analytics( (int) $connector['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function admin_get_rank( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Connector_Ranks::dto( absint( $request['id'] ) ) );
	}

	public static function admin_recalculate_rank( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Connector_Ranks::recalculate( absint( $request['id'] ), 'admin' ) );
	}

	public static function admin_override_rank( WP_REST_Request $request ) {
		$result = LNKup_Connector_Ranks::override_rank( absint( $request['id'] ), (string) $request->get_param( 'rank' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function admin_reverse_bonus( WP_REST_Request $request ) {
		$result = LNKup_Connector_Ranks::reverse_bonus( absint( $request['id'] ), absint( $request->get_param( 'reward_id' ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function admin_attributions() {
		return rest_ensure_response( array( 'disputes' => LNKup_Connector_Ranks::disputed_attributions() ) );
	}

	public static function admin_resolve_attribution( WP_REST_Request $request ) {
		LNKup_Connector_Ranks::resolve_attribution(
			absint( $request['payment_id'] ),
			absint( $request->get_param( 'connector_id' ) ),
			(string) $request->get_param( 'status' )
		);
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function admin_reward( WP_REST_Request $request ) {
		$reward_id = absint( $request->get_param( 'reward_id' ) );
		$action    = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( 'paid' === $action ) {
			$result = LNKup_Connector_Rewards::mark_paid( $reward_id, (string) $request->get_param( 'payout_reference' ) );
		} else {
			$result = LNKup_Connector_Rewards::approve_reward( $reward_id );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}
}
