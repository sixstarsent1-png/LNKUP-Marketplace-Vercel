<?php
/**
 * Trust & Safety REST routes (lnkup/v1).
 *
 * - /trust/*                      Trust & Safety reviewers only (lnkup_manage_trust or manage-all).
 * - /organizer/verification*      The signed-in organizer, for their own account.
 * - /org/team*, /org/invites/*    Organization owner / org admins (manage_team), or the invitee.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Trust_REST {

	const NS = 'lnkup/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$admin = array( __CLASS__, 'admin_permission' );
		$org   = array( __CLASS__, 'organizer_permission' );
		$auth  = array( __CLASS__, 'logged_in_permission' );
		$id    = array( 'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ) );

		register_rest_route( self::NS, '/trust/overview', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $admin ) );
		register_rest_route(
			self::NS,
			'/trust/cases',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_cases' ),
				'permission_callback' => $admin,
				'args'                => array(
					'status'    => array( 'type' => 'string', 'default' => 'active' ),
					'severity'  => array( 'type' => 'string' ),
					'case_type' => array( 'type' => 'string' ),
					'page'      => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page'  => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
				),
			)
		);
		register_rest_route( self::NS, '/trust/cases/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_case' ), 'permission_callback' => $admin, 'args' => $id ) );
		register_rest_route( self::NS, '/trust/cases/(?P<id>\d+)/transition', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'transition_case' ), 'permission_callback' => $admin, 'args' => $id ) );
		register_rest_route( self::NS, '/trust/cases/(?P<id>\d+)/assign', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'assign_case' ), 'permission_callback' => $admin, 'args' => $id ) );
		register_rest_route( self::NS, '/trust/cases/(?P<id>\d+)/notes', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'note_case' ), 'permission_callback' => $admin, 'args' => $id ) );
		register_rest_route( self::NS, '/trust/claims', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_claims' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/trust/claims/(?P<id>\d+)/approve', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'approve_claim' ), 'permission_callback' => $admin, 'args' => $id ) );
		register_rest_route( self::NS, '/trust/claims/(?P<id>\d+)/reject', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'reject_claim' ), 'permission_callback' => $admin, 'args' => $id ) );
		register_rest_route( self::NS, '/trust/events/(?P<id>\d+)/authenticity', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'set_authenticity' ), 'permission_callback' => $admin, 'args' => $id ) );
		register_rest_route( self::NS, '/trust/organizers/queue', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'organizer_queue' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/trust/organizers/(?P<id>\d+)/decision', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'organizer_decision' ), 'permission_callback' => $admin, 'args' => $id ) );
		register_rest_route( self::NS, '/trust/audit', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'audit_list' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/trust/audit/verify', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'audit_verify' ), 'permission_callback' => $admin ) );

		register_rest_route( self::NS, '/organizer/verification', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'my_verification' ), 'permission_callback' => $org ) );
		register_rest_route( self::NS, '/organizer/verification/request', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'request_verification' ), 'permission_callback' => $org ) );
		register_rest_route( self::NS, '/organizer/verification/domain/start', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'domain_start' ), 'permission_callback' => $org ) );
		register_rest_route( self::NS, '/organizer/verification/domain/confirm', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'domain_confirm' ), 'permission_callback' => $org ) );

		register_rest_route(
			self::NS,
			'/org/team',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'team_list' ), 'permission_callback' => array( __CLASS__, 'team_permission' ) ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'team_invite' ), 'permission_callback' => array( __CLASS__, 'team_permission' ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/org/team/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'team_update' ), 'permission_callback' => array( __CLASS__, 'team_permission' ), 'args' => $id ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'team_revoke' ), 'permission_callback' => array( __CLASS__, 'team_permission' ), 'args' => $id ),
			)
		);
		register_rest_route(
			self::NS,
			'/org/team/audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'team_audit' ),
				'permission_callback' => array( __CLASS__, 'team_permission' ),
				'args'                => array(
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
				),
			)
		);
		register_rest_route( self::NS, '/org/invites/accept', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'invite_accept' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/org/memberships', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'my_memberships' ), 'permission_callback' => $auth ) );
	}

	/* ---------- permissions ---------- */

	public static function admin_permission() {
		return LNKup_Trust::user_can_manage();
	}

	public static function organizer_permission() {
		$user_id = get_current_user_id();
		return $user_id && ( LNKup_Roles::is_organizer( $user_id ) || LNKup_Capabilities::user_has_manage_all( $user_id ) );
	}

	public static function logged_in_permission() {
		return is_user_logged_in();
	}

	/**
	 * Owner of the organization or an org admin with manage_team.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function team_permission( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		$owner = self::team_owner( $request );
		if ( $owner === $user_id ) {
			return LNKup_Roles::is_organizer( $user_id ) || LNKup_Capabilities::user_has_manage_all( $user_id );
		}
		return LNKup_Org_Teams::can_org( $user_id, $owner, 'manage_team' );
	}

	/* ---------- Trust & Safety console ---------- */

	public static function overview() {
		return rest_ensure_response( self::overview_data() );
	}

	/**
	 * Real counts only; sections that are not available report null rather than zero.
	 */
	public static function overview_data() {
		$claims = LNKup_Event_Claims::count_by_status();
		$chain  = get_option( 'lnkup_audit_chain_last_check', null );
		$cases  = LNKup_Trust_Cases::open_counts();
		return array(
			'cases'                       => $cases,
			'claims_pending'              => (int) $claims['pending'],
			'claims_disputed'             => (int) $claims['disputed'],
			'organizer_reviews_pending'   => LNKup_Organizer_Verification::count_pending(),
			'open_chargebacks'            => class_exists( 'LNKup_Financial_Disputes' ) ? (int) LNKup_Financial_Disputes::open_count_all() : null,
			'open_fraud_flags'            => class_exists( 'LNKup_Fraud_Flags' ) ? (int) LNKup_Fraud_Flags::open_count() : null,
			'payment_mismatch_cases'      => (int) ( $cases['by_type']['payment_mismatch'] ?? 0 ),
			'audit_chain'                 => is_array( $chain ) ? $chain : null,
			'flags'                       => array(
				'trust_claim_review_mode'   => LNKup_Trust::claim_review_mode(),
				'trust_publish_gate'        => LNKup_Trust::flag( 'trust_publish_gate' ),
				'trust_org_teams'           => LNKup_Trust::flag( 'trust_org_teams' ),
				'trust_auto_promote_claims' => LNKup_Trust::flag( 'trust_auto_promote_claims' ),
			),
		);
	}

	public static function list_cases( WP_REST_Request $request ) {
		$per_page = (int) $request['per_page'];
		$result   = LNKup_Trust_Cases::query(
			array(
				'status'    => sanitize_key( (string) $request['status'] ),
				'severity'  => sanitize_key( (string) $request['severity'] ),
				'case_type' => sanitize_key( (string) $request['case_type'] ),
				'limit'     => $per_page,
				'offset'    => ( (int) $request['page'] - 1 ) * $per_page,
			)
		);
		$response = rest_ensure_response( $result );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		return $response;
	}

	public static function get_case( WP_REST_Request $request ) {
		$case = LNKup_Trust_Cases::get( (int) $request['id'] );
		if ( ! $case ) {
			return new WP_Error( 'lnkup_case_not_found', __( 'Case not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$timeline = array_map(
			static function ( $row ) {
				$actor = (int) $row['actor_user_id'];
				$user  = $actor ? get_userdata( $actor ) : null;
				return array(
					'type'       => (string) $row['event_type'],
					'from'       => (string) $row['from_status'],
					'to'         => (string) $row['to_status'],
					'note'       => (string) $row['note'],
					'actor'      => $user ? $user->display_name : ( $actor ? '#' . $actor : __( 'System', 'lnkup-marketplace' ) ),
					'created_at' => (string) $row['created_at'],
				);
			},
			LNKup_Trust_Cases::timeline( (int) $case['id'] )
		);
		return rest_ensure_response( array_merge( LNKup_Trust_Cases::format( $case ), array( 'timeline' => $timeline ) ) );
	}

	public static function transition_case( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Trust_Cases::transition( (int) $request['id'], sanitize_key( (string) ( $body['status'] ?? '' ) ), (string) ( $body['note'] ?? '' ) );
		return is_wp_error( $result ) ? $result : self::get_case( $request );
	}

	public static function assign_case( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Trust_Cases::assign( (int) $request['id'], absint( $body['user_id'] ?? 0 ) );
		return is_wp_error( $result ) ? $result : self::get_case( $request );
	}

	public static function note_case( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Trust_Cases::add_note( (int) $request['id'], (string) ( $body['note'] ?? '' ) );
		return is_wp_error( $result ) ? $result : self::get_case( $request );
	}

	public static function list_claims( WP_REST_Request $request ) {
		$rows = LNKup_Event_Claims::list_for_review( sanitize_key( (string) $request->get_param( 'status' ) ) );
		return rest_ensure_response( array( 'items' => array_map( array( __CLASS__, 'format_claim' ), $rows ) ) );
	}

	public static function approve_claim( WP_REST_Request $request ) {
		$note = self::required_note( $request );
		if ( is_wp_error( $note ) ) {
			return $note;
		}
		$result = LNKup_Event_Claims::approve( (int) $request['id'], $note );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function reject_claim( WP_REST_Request $request ) {
		$note = self::required_note( $request );
		if ( is_wp_error( $note ) ) {
			return $note;
		}
		$result = LNKup_Event_Claims::reject( (int) $request['id'], $note );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function set_authenticity( WP_REST_Request $request ) {
		$body = self::body( $request );
		$note = self::required_note( $request );
		if ( is_wp_error( $note ) ) {
			return $note;
		}
		$result = LNKup_Event_Authenticity::set_status( (int) $request['id'], sanitize_key( (string) ( $body['status'] ?? '' ) ), $note );
		return is_wp_error( $result ) ? $result : rest_ensure_response( LNKup_Event_Authenticity::public_label( (int) $request['id'] ) );
	}

	public static function organizer_queue() {
		return rest_ensure_response( array( 'items' => LNKup_Organizer_Verification::review_queue() ) );
	}

	public static function organizer_decision( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Organizer_Verification::decide( (int) $request['id'], sanitize_key( (string) ( $body['decision'] ?? '' ) ), (string) ( $body['note'] ?? '' ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function audit_list( WP_REST_Request $request ) {
		$rows = LNKup_Audit_Log::list_entries(
			array(
				'entity_type'   => (string) $request->get_param( 'entity_type' ),
				'entity_id'     => (int) $request->get_param( 'entity_id' ),
				'action'        => (string) $request->get_param( 'action' ),
				'actor_user_id' => (int) $request->get_param( 'actor_user_id' ),
				'limit'         => (int) ( $request->get_param( 'per_page' ) ?: 50 ),
				'offset'        => (int) $request->get_param( 'offset' ),
			)
		);
		$items = array_map(
			static function ( $row ) {
				unset( $row['ip_hash'], $row['prev_hash'] );
				$row['prev_state'] = json_decode( (string) $row['prev_state'], true );
				$row['new_state']  = json_decode( (string) $row['new_state'], true );
				return $row;
			},
			$rows
		);
		return rest_ensure_response( array( 'items' => $items ) );
	}

	public static function audit_verify() {
		$result = LNKup_Audit_Log::verify_chain();
		update_option( 'lnkup_audit_chain_last_check', array_merge( $result, array( 'at' => current_time( 'mysql', true ) ) ), false );
		LNKup_Audit_Log::log( 'audit_chain_verified', 'audit_log', 0, array( 'new' => $result, 'result' => $result['ok'] ? 'success' : 'failure' ) );
		return rest_ensure_response( $result );
	}

	/* ---------- organizer self-service ---------- */

	public static function my_verification() {
		$user_id = get_current_user_id();
		return rest_ensure_response(
			array(
				'indicators' => LNKup_Organizer_Verification::indicators( $user_id ),
				'labels'     => LNKup_Organizer_Verification::status_labels(),
			)
		);
	}

	public static function request_verification( WP_REST_Request $request ) {
		$result = LNKup_Organizer_Verification::request_review( get_current_user_id(), self::body( $request ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'indicators' => $result ) );
	}

	public static function domain_start( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Organizer_Verification::start_domain(
			get_current_user_id(),
			(string) ( $body['domain'] ?? '' ),
			sanitize_key( (string) ( $body['method'] ?? '' ) ),
			(string) ( $body['email'] ?? '' )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function domain_confirm( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Organizer_Verification::confirm_domain( get_current_user_id(), (string) ( $body['code'] ?? '' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'indicators' => $result ) );
	}

	/* ---------- organization team ---------- */

	public static function team_list( WP_REST_Request $request ) {
		$owner = self::team_owner( $request );
		return rest_ensure_response(
			array_merge(
				LNKup_Org_Teams::list_team( $owner ),
				array(
					'owner_id' => $owner,
					'enabled'  => LNKup_Org_Teams::enabled(),
					'tours'    => self::owner_tours( $owner ),
					'events'   => self::owner_events( $owner ),
				)
			)
		);
	}

	public static function team_invite( WP_REST_Request $request ) {
		if ( ! LNKup_Org_Teams::enabled() ) {
			return new WP_Error( 'lnkup_teams_disabled', __( 'Team access is not enabled yet.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$body   = self::body( $request );
		$result = LNKup_Org_Teams::invite(
			self::team_owner( $request ),
			(string) ( $body['email'] ?? '' ),
			sanitize_key( (string) ( $body['role'] ?? '' ) ),
			sanitize_key( (string) ( $body['scope_type'] ?? 'all' ) ),
			absint( $body['scope_id'] ?? 0 ),
			get_current_user_id()
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function team_update( WP_REST_Request $request ) {
		$body    = self::body( $request );
		$changes = array_intersect_key( $body, array_flip( array( 'role', 'scope_type', 'scope_id' ) ) );
		$result  = LNKup_Org_Teams::update_member( self::team_owner( $request ), (int) $request['id'], $changes, get_current_user_id() );
		return is_wp_error( $result ) ? $result : self::team_list( $request );
	}

	public static function team_revoke( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Org_Teams::revoke( self::team_owner( $request ), (int) $request['id'], get_current_user_id(), (string) ( $body['reason'] ?? '' ) );
		return is_wp_error( $result ) ? $result : self::team_list( $request );
	}

	/**
	 * Team activity for one organization: membership changes and organizer-side review
	 * decisions, newest first. IP hashes and chain hashes are never returned.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function team_audit( WP_REST_Request $request ) {
		$owner    = self::team_owner( $request );
		$per_page = (int) $request['per_page'];
		$rows     = LNKup_Audit_Log::list_entries(
			array(
				'entity_type' => 'org',
				'entity_id'   => $owner,
				'limit'       => $per_page,
				'offset'      => ( (int) $request['page'] - 1 ) * $per_page,
			)
		);
		$items = array();
		foreach ( $rows as $row ) {
			$actor   = (int) ( $row['actor_user_id'] ?? 0 );
			$user    = $actor ? get_userdata( $actor ) : null;
			$items[] = array(
				'id'         => (int) $row['id'],
				'action'     => (string) $row['action'],
				'actor'      => $user ? $user->display_name : ( $actor ? '#' . $actor : __( 'System', 'lnkup-marketplace' ) ),
				'prev_state' => json_decode( (string) ( $row['prev_state'] ?? '' ), true ),
				'new_state'  => json_decode( (string) ( $row['new_state'] ?? '' ), true ),
				'reason'     => (string) ( $row['reason'] ?? '' ),
				'created_at' => (string) $row['created_at'],
			);
		}
		return rest_ensure_response( array( 'items' => $items, 'page' => (int) $request['page'] ) );
	}

	public static function invite_accept( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$result = LNKup_Org_Teams::accept( (string) ( $body['token'] ?? '' ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function my_memberships() {
		$owners = LNKup_Org_Teams::orgs_for_user( get_current_user_id() );
		$items  = array();
		foreach ( $owners as $owner_id ) {
			$owner = get_userdata( $owner_id );
			foreach ( LNKup_Org_Teams::active_memberships( $owner_id, get_current_user_id() ) as $row ) {
				$items[] = array(
					'org_owner_id' => $owner_id,
					'org_name'     => $owner ? $owner->display_name : '',
					'role'         => (string) $row['role'],
					'role_label'   => LNKup_Org_Teams::roles()[ $row['role'] ] ?? (string) $row['role'],
					'scope_type'   => (string) $row['scope_type'],
					'scope_id'     => (int) $row['scope_id'],
				);
			}
		}
		return rest_ensure_response( array( 'items' => $items ) );
	}

	/* ---------- helpers ---------- */

	private static function body( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : (array) $request->get_body_params();
	}

	/**
	 * @return string|WP_Error
	 */
	private static function required_note( WP_REST_Request $request ) {
		$note = trim( sanitize_textarea_field( (string) ( self::body( $request )['note'] ?? '' ) ) );
		if ( mb_strlen( $note ) < 5 ) {
			return new WP_Error( 'lnkup_note_required', __( 'Add a short reason for this decision.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return $note;
	}

	/**
	 * Organization the request targets: ?owner_id= for org admins, otherwise the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function team_owner( WP_REST_Request $request ) {
		$owner = absint( $request->get_param( 'owner_id' ) );
		return $owner ?: get_current_user_id();
	}

	private static function owner_tours( $owner_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_tour',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => absint( $owner_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_map(
			static function ( $id ) {
				return array( 'id' => (int) $id, 'title' => lnkup_plain_title( $id ) );
			},
			$ids
		);
	}

	private static function owner_events( $owner_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_key'       => '_lnkup_organizer_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => absint( $owner_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_map(
			static function ( $id ) {
				return array( 'id' => (int) $id, 'title' => lnkup_plain_title( $id ) );
			},
			$ids
		);
	}

	/**
	 * Admin view of a claim row.
	 *
	 * @param array $row Claim row.
	 */
	public static function format_claim( $row ) {
		$org      = get_userdata( (int) $row['organizer_user_id'] );
		$external = LNKup_External_Events::get_row( (int) $row['external_event_id'] );
		return array(
			'id'                => (int) $row['id'],
			'external_event_id' => (int) $row['external_event_id'],
			'external_title'    => $external ? (string) $external['title'] : '',
			'source_url'        => $external ? (string) ( $external['source_url'] ?? '' ) : '',
			'event_id'          => (int) $row['event_id'],
			'organizer_user_id' => (int) $row['organizer_user_id'],
			'organizer_name'    => $org ? $org->display_name : '',
			'organizer_email'   => $org ? $org->user_email : '',
			'match_type'        => (string) $row['match_type'],
			'status'            => (string) $row['status'],
			'case_id'           => (int) $row['case_id'],
			'evidence'          => (array) json_decode( (string) $row['evidence'], true ),
			'created_at'        => (string) $row['created_at'],
		);
	}
}
