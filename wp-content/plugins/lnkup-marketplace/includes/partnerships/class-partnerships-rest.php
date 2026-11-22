<?php
/**
 * Partnership OS REST API (`lnkup/v1`).
 *
 * Admin routes require `LNKup_Capabilities::user_has_manage_all()`. Connector routes require an
 * active connector account and only ever return that connector's own records.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Partnerships_REST {

	const NS = 'lnkup/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$admin = array( 'permission_callback' => array( __CLASS__, 'admin_permission' ) );

		register_rest_route( self::NS, '/partners/overview', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_overview' ),
		) ) );

		register_rest_route( self::NS, '/partners/referrals', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_referrals' ),
		) ) );

		register_rest_route( self::NS, '/partners/ledger', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_ledger' ),
		) ) );

		register_rest_route( self::NS, '/partners/ledger/(?P<id>\d+)/(?P<action>approve|hold|release|mark-paid)', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'post_ledger_action' ),
		) ) );

		register_rest_route( self::NS, '/partners/rules', array(
			array_merge( $admin, array(
				'methods'  => 'GET',
				'callback' => array( __CLASS__, 'get_rules' ),
			) ),
			array_merge( $admin, array(
				'methods'  => 'POST',
				'callback' => array( __CLASS__, 'post_rule' ),
			) ),
		) );

		register_rest_route( self::NS, '/partners/rules/(?P<id>\d+)/publish', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'post_rule_publish' ),
		) ) );

		register_rest_route( self::NS, '/partners/reviews', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_reviews' ),
		) ) );

		register_rest_route( self::NS, '/partners/reviews/(?P<id>\d+)/resolve', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'post_review_resolve' ),
		) ) );

		register_rest_route( self::NS, '/partners/territories', array(
			array_merge( $admin, array(
				'methods'  => 'GET',
				'callback' => array( __CLASS__, 'get_territories' ),
			) ),
			array_merge( $admin, array(
				'methods'  => 'POST',
				'callback' => array( __CLASS__, 'post_territory' ),
			) ),
			array_merge( $admin, array(
				'methods'  => 'DELETE',
				'callback' => array( __CLASS__, 'delete_territory' ),
			) ),
		) );

		register_rest_route( self::NS, '/partners/apply', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'post_apply' ),
			// Public by design: honeypot, hashed-IP rate limit and validation run inside the callback.
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/partners/applications', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_applications' ),
		) ) );

		register_rest_route( self::NS, '/partners/applications/(?P<id>\d+)/(?P<action>review|request-info|approve|decline|suspend|close)', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'post_application_action' ),
		) ) );

		register_rest_route( self::NS, '/partners/organizations', array(
			array_merge( $admin, array(
				'methods'  => 'GET',
				'callback' => array( __CLASS__, 'get_organizations' ),
			) ),
			array_merge( $admin, array(
				'methods'  => 'POST',
				'callback' => array( __CLASS__, 'post_organization' ),
			) ),
		) );

		register_rest_route( self::NS, '/partners/organizations/(?P<id>\d+)', array(
			array_merge( $admin, array(
				'methods'  => 'GET',
				'callback' => array( __CLASS__, 'get_organization' ),
			) ),
			array_merge( $admin, array(
				'methods'  => 'POST',
				'callback' => array( __CLASS__, 'post_organization_update' ),
			) ),
		) );

		$connector = array( 'permission_callback' => array( __CLASS__, 'connector_permission' ) );

		register_rest_route( self::NS, '/connector/leads', array(
			array_merge( $connector, array(
				'methods'  => 'GET',
				'callback' => array( __CLASS__, 'get_connector_leads' ),
			) ),
			array_merge( $connector, array(
				'methods'  => 'POST',
				'callback' => array( __CLASS__, 'post_connector_lead' ),
			) ),
		) );

		register_rest_route( self::NS, '/connector/territories', array_merge( $connector, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'get_connector_territories' ),
		) ) );
	}

	// ------------------------------------------------------------------ Permissions.

	public static function admin_permission(): bool {
		return is_user_logged_in() && LNKup_Capabilities::user_has_manage_all();
	}

	public static function connector_permission(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return LNKup_Roles::is_connector() || LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * Active connector row for the current user (never another connector's).
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function current_connector() {
		$connector = LNKup_Connector::get_by_user_id( get_current_user_id() );
		if ( ! $connector || 'active' !== ( $connector['status'] ?? '' ) ) {
			return new WP_Error( 'lnkup_connector_inactive', __( 'Active connector access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return $connector;
	}

	/**
	 * @return true|WP_Error
	 */
	private static function require_ready() {
		if ( ! LNKup_Partner_Tables::ready() ) {
			return new WP_Error( 'lnkup_partners_not_ready', __( 'Partnerships are still being set up. Try again shortly.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		return true;
	}

	/**
	 * @param mixed $result Service result.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => (int) ( $data['status'] ?? 400 ) ) );
		}
		return rest_ensure_response( true === $result ? array( 'success' => true ) : $result );
	}

	// ------------------------------------------------------------------ Admin.

	public static function get_overview() {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		return rest_ensure_response( LNKup_Partnerships::overview() );
	}

	public static function get_referrals( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		return rest_ensure_response(
			LNKup_Partnerships::referrals(
				array(
					'program'            => $request->get_param( 'program' ),
					'attribution_status' => $request->get_param( 'attribution_status' ),
					'market'             => $request->get_param( 'market' ),
					'role'               => $request->get_param( 'role' ),
					'page'               => $request->get_param( 'page' ),
					'per_page'           => $request->get_param( 'per_page' ),
				)
			)
		);
	}

	public static function get_ledger( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$args = array(
			'program'      => $request->get_param( 'program' ),
			'status'       => $request->get_param( 'status' ),
			'connector_id' => $request->get_param( 'connector_id' ),
			'page'         => $request->get_param( 'page' ),
			'per_page'     => $request->get_param( 'per_page' ),
		);
		return rest_ensure_response( LNKup_Partnerships::ledger( $args ) );
	}

	public static function post_ledger_action( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$result = LNKup_Partnerships::ledger_action(
			absint( $request['id'] ),
			sanitize_key( (string) $request['action'] ),
			array(
				'reason'           => (string) $request->get_param( 'reason' ),
				'payout_reference' => (string) $request->get_param( 'payout_reference' ),
			)
		);
		if ( true === $result ) {
			$row = LNKup_Connector_Rewards::get_reward( absint( $request['id'] ) );
			return rest_ensure_response( array( 'success' => true, 'reward' => $row ? LNKup_Partnerships::present_reward( $row ) : null ) );
		}
		return self::respond( $result );
	}

	public static function get_rules( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		return rest_ensure_response(
			array(
				'programs' => LNKup_Partner_Programs::all(),
				'rules'    => LNKup_Commission_Rules::list_rules( sanitize_key( (string) $request->get_param( 'program' ) ) ),
			)
		);
	}

	public static function post_rule( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$params = (array) $request->get_json_params() + (array) $request->get_body_params();
		return self::respond( LNKup_Commission_Rules::create_version( (string) ( $params['program'] ?? '' ), $params, get_current_user_id() ) );
	}

	public static function post_rule_publish( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		return self::respond( LNKup_Commission_Rules::publish( absint( $request['id'] ), get_current_user_id() ) );
	}

	public static function get_reviews( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		return rest_ensure_response(
			LNKup_Partner_Reviews::query(
				array(
					'type'     => $request->get_param( 'type' ),
					'status'   => $request->get_param( 'status' ) ?: 'open',
					'page'     => $request->get_param( 'page' ),
					'per_page' => $request->get_param( 'per_page' ),
				)
			)
		);
	}

	public static function post_review_resolve( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$review_id = absint( $request['id'] );
		$review    = LNKup_Partner_Reviews::get( $review_id );
		if ( ! $review ) {
			return new WP_Error( 'lnkup_review_not_found', __( 'Review not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$notes = (string) $request->get_param( 'notes' );
		if ( 'attribution_conflict' === $review['type'] ) {
			return self::respond( LNKup_Attribution_Arbiter::resolve_conflict( $review_id, absint( $request->get_param( 'winner_referral_id' ) ), get_current_user_id(), $notes ) );
		}
		$resolution = sanitize_key( (string) $request->get_param( 'resolution' ) );
		if ( ! in_array( $resolution, array( 'recovered', 'written_off', 'not_owed' ), true ) ) {
			return new WP_Error( 'lnkup_review_resolution', __( 'Choose recovered, written off or not owed.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! LNKup_Partner_Reviews::close( $review_id, $resolution, 0, $notes, get_current_user_id() ) ) {
			return new WP_Error( 'lnkup_review_state', __( 'This review was already resolved.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'review_id' => $review_id, 'resolution' => $resolution ) );
	}

	public static function get_territories( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		return rest_ensure_response( array( 'items' => LNKup_Partnerships::territories( absint( $request->get_param( 'connector_id' ) ) ) ) );
	}

	public static function post_territory( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		return self::respond(
			LNKup_Partnerships::add_territory(
				absint( $request->get_param( 'connector_id' ) ),
				(string) $request->get_param( 'scope_type' ),
				(string) $request->get_param( 'value' ),
				get_current_user_id()
			)
		);
	}

	public static function delete_territory( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		return self::respond( LNKup_Partnerships::remove_territory( absint( $request->get_param( 'id' ) ) ) );
	}

	// ------------------------------------------------------------------ Applications and organizations.

	public static function post_apply( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$params = (array) $request->get_json_params() + (array) $request->get_body_params();
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return self::respond( LNKup_Partner_Applications::submit( (string) ( $params['program'] ?? '' ), $params, $ip ) );
	}

	public static function get_applications( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		return rest_ensure_response(
			LNKup_Partner_Applications::query(
				array(
					'program'  => $request->get_param( 'program' ),
					'status'   => $request->get_param( 'status' ),
					'page'     => $request->get_param( 'page' ),
					'per_page' => $request->get_param( 'per_page' ),
				)
			)
		);
	}

	public static function post_application_action( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		return self::respond( LNKup_Partner_Applications::transition( absint( $request['id'] ), (string) $request['action'], get_current_user_id(), (string) $request->get_param( 'note' ) ) );
	}

	public static function get_organizations( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		return rest_ensure_response(
			LNKup_Partner_Organizations::query(
				array(
					'stage'    => $request->get_param( 'stage' ),
					'category' => $request->get_param( 'category' ),
					'search'   => $request->get_param( 'search' ),
					'page'     => $request->get_param( 'page' ),
					'per_page' => $request->get_param( 'per_page' ),
				)
			)
		);
	}

	public static function get_organization( WP_REST_Request $request ) {
		$row = LNKup_Partner_Organizations::get( absint( $request['id'] ) );
		if ( ! $row ) {
			return new WP_Error( 'lnkup_partner_org_missing', __( 'Partner organization not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( LNKup_Partner_Organizations::present( $row ) );
	}

	public static function post_organization( WP_REST_Request $request ) {
		$ready = self::require_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$params = (array) $request->get_json_params() + (array) $request->get_body_params();
		$id     = LNKup_Partner_Organizations::find_or_create( $params, get_current_user_id() );
		if ( ! $id ) {
			return new WP_Error( 'lnkup_partner_org_name', __( 'Organization name is required.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return self::respond( LNKup_Partner_Organizations::update( $id, array_diff_key( $params, array_flip( array( 'name', 'website', 'contact_email' ) ) ), get_current_user_id() ) );
	}

	public static function post_organization_update( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params() + (array) $request->get_body_params();
		return self::respond( LNKup_Partner_Organizations::update( absint( $request['id'] ), $params, get_current_user_id() ) );
	}

	// ------------------------------------------------------------------ Connector.

	public static function get_connector_leads( WP_REST_Request $request ) {
		$connector = self::current_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		return rest_ensure_response( LNKup_Partnerships::connector_leads( $connector, absint( $request->get_param( 'page' ) ) ?: 1, absint( $request->get_param( 'per_page' ) ) ?: 20 ) );
	}

	public static function post_connector_lead( WP_REST_Request $request ) {
		$connector = self::current_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		$params = (array) $request->get_json_params() + (array) $request->get_body_params();
		return self::respond( LNKup_Partnerships::submit_connector_lead( $connector, $params ) );
	}

	public static function get_connector_territories() {
		$connector = self::current_connector();
		if ( is_wp_error( $connector ) ) {
			return $connector;
		}
		if ( is_wp_error( self::require_ready() ) ) {
			return rest_ensure_response( array( 'items' => array(), 'city' => (string) $connector['city'], 'state' => (string) $connector['state'] ) );
		}
		return rest_ensure_response(
			array(
				'items' => LNKup_Partnerships::territories( (int) $connector['id'] ),
				'city'  => (string) $connector['city'],
				'state' => (string) $connector['state'],
			)
		);
	}
}
