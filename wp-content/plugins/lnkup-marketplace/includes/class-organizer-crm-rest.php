<?php
/**
 * Organizer CRM REST API.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_CRM_REST {

	const NS = 'lnkup/v1';

	/**
	 * Init routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Organizer permission.
	 */
	public static function organizer_permission() {
		return current_user_can( 'lnkup_review_applications' )
			|| ( class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer() )
			|| current_user_can( 'manage_options' )
			|| LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		$org = array( 'permission_callback' => array( __CLASS__, 'organizer_permission' ) );

		register_rest_route( self::NS, '/crm/vendors', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'vendors' ),
		) ) );

		register_rest_route( self::NS, '/crm/vendors/(?P<id>\d+)', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'vendor_profile' ),
		) ) );

		register_rest_route( self::NS, '/crm/vendors/(?P<id>\d+)/timeline', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'vendor_timeline' ),
		) ) );

		register_rest_route( self::NS, '/crm/vendors/(?P<id>\d+)/notes', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_notes' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_note' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
		) );

		register_rest_route( self::NS, '/crm/notes/(?P<id>\d+)', array(
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'update_note' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_note' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
		) );

		register_rest_route( self::NS, '/crm/vendors/(?P<id>\d+)/tags', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'add_tag' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'remove_tag' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
		) );

		register_rest_route( self::NS, '/crm/vendors/(?P<id>\d+)/favorite', array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'toggle_favorite' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/vendors/(?P<id>\d+)/preferred', array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'toggle_preferred' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/vendors/(?P<id>\d+)/flags', array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'add_flag' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/lists', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_lists' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_list' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
		) );

		register_rest_route( self::NS, '/crm/lists/(?P<id>\d+)', array(
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'rename_list' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_list' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
		) );

		register_rest_route( self::NS, '/crm/lists/(?P<id>\d+)/vendors', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_vendors' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'list_add_vendor' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'list_remove_vendor' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) ),
		) );

		register_rest_route( self::NS, '/crm/invites', array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'create_invite' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/invites/list', array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'list_invites' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/invites/respond', array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'respond_invite' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( self::NS, '/vendor/invites', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'vendor_invites' ),
			'permission_callback' => array( __CLASS__, 'vendor_permission' ),
			'args'                => array(
				'scope' => array( 'type' => 'string', 'enum' => array( 'open', 'all' ), 'default' => 'open' ),
			),
		) );

		register_rest_route( self::NS, '/vendor/invites/(?P<id>\d+)/respond', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'vendor_invite_respond' ),
			'permission_callback' => array( __CLASS__, 'vendor_permission' ),
		) );

		register_rest_route( self::NS, '/crm/outreach/bulk', array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'outreach_bulk' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/outreach/list', array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'outreach_list' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/applications/(?P<id>\d+)/intel', array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'application_intel' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/applications/compare', array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'compare_applications' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/events/invite-options', array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'invite_options' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
			'args'     => array(
				'include_all' => array(
					'description'       => 'When true, include events without open opportunities (mobile Events list).',
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			),
		) );

		register_rest_route( self::NS, '/crm/events/(?P<id>\d+)/roster', array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_roster' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/events/(?P<id>\d+)/roster/bulk', array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'roster_bulk' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/applications/(?P<id>\d+)/check-in', array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'check_in' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/applications/(?P<id>\d+)/no-show', array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'no_show' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/dashboard/widgets', array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'dashboard_widgets' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/launch-checklist', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'launch_checklist' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/import/template', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'import_template' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/import/upload', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'import_upload' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/import/preview', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'import_preview' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/import/run', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'import_run' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/import/(?P<id>\d+)/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'import_status' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );

		register_rest_route( self::NS, '/crm/prospects', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'prospects' ),
			'permission_callback' => array( __CLASS__, 'organizer_permission' ),
		) );
	}

	/**
	 * Resolve profile ID to vendor user ID.
	 */
	private static function vendor_user_from_profile( $profile_id ) {
		return (int) get_post_meta( absint( $profile_id ), '_lnkup_owner_user_id', true );
	}

	public static function vendors( WP_REST_Request $request ) {
		try {
			$result = LNKup_Vendor_Directory::search( get_current_user_id(), $request->get_params() );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup vendor directory failed: ' . $e->getMessage() );
			return new WP_Error( 'lnkup_directory_unavailable', __( "We couldn't load vendors. Try again in a moment.", 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		return rest_ensure_response( $result );
	}

	public static function vendor_profile( WP_REST_Request $request ) {
		$profile_id = absint( $request['id'] );
		$user_id    = get_current_user_id();
		if ( '0' === (string) get_post_meta( $profile_id, '_lnkup_discoverable', true )
			&& ! LNKup_Capabilities::user_has_manage_all( $user_id )
			&& ! LNKup_Capabilities::organizer_can_manage_vendor( $user_id, (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true ) ) ) {
			return new WP_Error( 'not_found', __( 'Vendor not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$profile = LNKup_Vendor_CRM_Profile::get( $profile_id, $user_id );
		if ( ! $profile ) {
			return new WP_Error( 'not_found', __( 'Vendor not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		LNKup_Match_Service::remember_viewed_vendor( $user_id, $profile_id );
		return rest_ensure_response( $profile );
	}

	public static function vendor_timeline( WP_REST_Request $request ) {
		$vendor_id = self::vendor_user_from_profile( $request['id'] );
		$items = LNKup_Vendor_Timeline::get_for_vendor( get_current_user_id(), $vendor_id );
		return rest_ensure_response( array( 'items' => $items ) );
	}

	public static function get_notes( WP_REST_Request $request ) {
		$vendor_id = self::vendor_user_from_profile( $request['id'] );
		return rest_ensure_response( array( 'items' => LNKup_Vendor_Notes::get_for_vendor( get_current_user_id(), $vendor_id ) ) );
	}

	public static function create_note( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$vendor_id = self::vendor_user_from_profile( $request['id'] );
		$result = LNKup_Vendor_Notes::create(
			get_current_user_id(),
			$vendor_id,
			$body['content'] ?? '',
			absint( $body['event_id'] ?? 0 ),
			! empty( $body['pinned'] )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'id' => $result ) );
	}

	public static function update_note( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Vendor_Notes::update( absint( $request['id'] ), get_current_user_id(), $body['content'] ?? '', isset( $body['pinned'] ) ? (bool) $body['pinned'] : null );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function delete_note( WP_REST_Request $request ) {
		$result = LNKup_Vendor_Notes::delete( absint( $request['id'] ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function add_tag( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$vendor_id = self::vendor_user_from_profile( $request['id'] );
		$result = LNKup_Vendor_Tags::add( get_current_user_id(), $vendor_id, $body['slug'] ?? '', $body['label'] ?? '' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function remove_tag( WP_REST_Request $request ) {
		$vendor_id = self::vendor_user_from_profile( $request['id'] );
		LNKup_Vendor_Tags::remove( get_current_user_id(), $vendor_id, $request->get_param( 'slug' ) ?: '' );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function toggle_favorite( WP_REST_Request $request ) {
		global $wpdb;
		$vendor_id = self::vendor_user_from_profile( $request['id'] );
		$org = get_current_user_id();
		LNKup_Organizer_CRM::ensure_relationship( $org, $vendor_id, absint( $request['id'] ) );
		$table = LNKup_Organizer_CRM_Tables::rel_table();
		$row = LNKup_Organizer_CRM::get_relationship( $org, $vendor_id );
		$new = empty( $row['is_favorite'] ) ? 1 : 0;
		$wpdb->update( $table, array( 'is_favorite' => $new ), array( 'organizer_user_id' => $org, 'vendor_user_id' => $vendor_id ), array( '%d' ), array( '%d', '%d' ) );
		LNKup_Vendor_Directory::flush_discover_cache( $org );
		return rest_ensure_response( array( 'is_favorite' => (bool) $new ) );
	}

	public static function toggle_preferred( WP_REST_Request $request ) {
		global $wpdb;
		$vendor_id = self::vendor_user_from_profile( $request['id'] );
		$org = get_current_user_id();
		LNKup_Organizer_CRM::ensure_relationship( $org, $vendor_id, absint( $request['id'] ) );
		$table = LNKup_Organizer_CRM_Tables::rel_table();
		$row = LNKup_Organizer_CRM::get_relationship( $org, $vendor_id );
		$new = empty( $row['is_preferred'] ) ? 1 : 0;
		$wpdb->update( $table, array( 'is_preferred' => $new ), array( 'organizer_user_id' => $org, 'vendor_user_id' => $vendor_id ), array( '%d' ), array( '%d', '%d' ) );
		LNKup_Vendor_Directory::flush_discover_cache( $org );
		return rest_ensure_response( array( 'is_preferred' => (bool) $new ) );
	}

	public static function add_flag( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$vendor_id = self::vendor_user_from_profile( $request['id'] );
		$result = LNKup_Vendor_Flags::add( get_current_user_id(), $vendor_id, $body['type'] ?? '', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'id' => $result ) );
	}

	public static function get_lists( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Vendor_Lists::get_for_organizer( get_current_user_id() ) ) );
	}

	public static function create_list( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$id = LNKup_Vendor_Lists::create( get_current_user_id(), $body['name'] ?? 'List', $body['color'] ?? '' );
		return rest_ensure_response( array( 'id' => $id ) );
	}

	public static function rename_list( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Vendor_Lists::rename( absint( $request['id'] ), get_current_user_id(), $body['name'] ?? '' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function delete_list( WP_REST_Request $request ) {
		$result = LNKup_Vendor_Lists::delete( absint( $request['id'] ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function list_vendors( WP_REST_Request $request ) {
		$items = LNKup_Vendor_Lists::get_vendors( absint( $request['id'] ), get_current_user_id() );
		return rest_ensure_response( array( 'items' => $items ) );
	}

	public static function list_add_vendor( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Vendor_Lists::add_vendor( absint( $request['id'] ), get_current_user_id(), absint( $body['profile_id'] ?? 0 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function list_remove_vendor( WP_REST_Request $request ) {
		$profile_id = absint( $request->get_param( 'profile_id' ) );
		LNKup_Vendor_Lists::remove_vendor( absint( $request['id'] ), get_current_user_id(), $profile_id );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function create_invite( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Vendor_Invites::create( get_current_user_id(), absint( $body['vendor_user_id'] ?? 0 ), $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function list_invites( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Vendor_Invites::get_for_organizer( get_current_user_id() ) ) );
	}

	public static function vendor_permission() {
		$user_id = get_current_user_id();
		return $user_id && LNKup_Roles::is_vendor( $user_id );
	}

	/**
	 * Organizer invitations addressed to the signed-in vendor.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function vendor_invites( WP_REST_Request $request ) {
		$scope = 'all' === $request->get_param( 'scope' ) ? 'all' : 'open';
		return rest_ensure_response( array( 'items' => LNKup_Vendor_Invites::get_for_vendor( get_current_user_id(), $scope ) ) );
	}

	/**
	 * Accept or decline one of the signed-in vendor's invitations.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function vendor_invite_respond( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Vendor_Invites::respond_by_id( absint( $request['id'] ), ! empty( $body['accept'] ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( is_array( $result ) ? $result : array( 'success' => true ) );
	}

	public static function respond_invite( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Vendor_Invites::respond( $body['token'] ?? '', ! empty( $body['accept'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_array( $result ) ) {
			return rest_ensure_response( $result );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Bulk invite prospects by email (outreach campaign).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function outreach_bulk( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Outreach' ) ) {
			return new WP_Error( 'unavailable', __( 'Outreach is not available.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}

		$body      = $request->get_json_params() ?: $request->get_params();
		$event_id  = absint( $body['event_id'] ?? 0 );
		$org_id    = get_current_user_id();

		if ( $event_id && ! LNKup_Capabilities::organizer_owns_event( $event_id, $org_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not own this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		$name = sanitize_text_field( $body['name'] ?? '' );
		if ( '' === $name ) {
			$name = $event_id
				? sprintf(
					/* translators: %s: event title */
					__( 'Invite: %s', 'lnkup-marketplace' ),
					lnkup_plain_title( $event_id )
				)
				: __( 'Vendor outreach', 'lnkup-marketplace' );
		}

		$result = LNKup_Outreach::create_and_queue(
			array(
				'name'           => $name,
				'target_role'    => 'lnkup_vendor',
				'message'        => sanitize_textarea_field( $body['message'] ?? '' ),
				'source'         => 'organizer',
				'created_by'     => $org_id,
				'event_id'       => $event_id,
				'opportunity_id' => absint( $body['opportunity_id'] ?? 0 ),
				'recipients'     => $body['emails'] ?? $body['recipients'] ?? '',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * List organizer outreach campaigns.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function outreach_list( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Outreach' ) ) {
			return rest_ensure_response( array( 'items' => array() ) );
		}

		$items = LNKup_Outreach::list_campaigns(
			array(
				'created_by' => get_current_user_id(),
				'source'     => 'organizer',
				'limit'      => absint( $request->get_param( 'limit' ) ?: 20 ),
			)
		);

		$safe = array();
		foreach ( $items as $row ) {
			$safe[] = array(
				'id'         => (int) $row['id'],
				'name'       => $row['name'],
				'status'     => $row['status'],
				'total'      => (int) $row['total'],
				'sent'       => (int) $row['sent'],
				'skipped'    => (int) $row['skipped'],
				'failed'     => (int) $row['failed'],
				'registered' => (int) $row['registered'],
				'event_id'   => (int) $row['event_id'],
				'created_at' => $row['created_at'],
			);
		}

		return rest_ensure_response( array( 'items' => $safe ) );
	}

	/**
	 * Organizer events/opportunities for invite picker.
	 *
	 * Query: include_all=1 returns every organizer event (including closed / no open opps) for mobile Events.
	 */
	public static function invite_options( WP_REST_Request $request ) {
		$include_all = (bool) $request->get_param( 'include_all' );
		return rest_ensure_response(
			LNKup_Vendor_Invites::get_invite_options_for_organizer(
				get_current_user_id(),
				array( 'include_all' => $include_all )
			)
		);
	}

	public static function application_intel( WP_REST_Request $request ) {
		$app_id = absint( $request['id'] );
		if ( ! LNKup_Capabilities::user_owns_post( $app_id, get_current_user_id(), '_lnkup_organizer_user_id' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		$profile = LNKup_Vendor_CRM_Profile::get( $profile_id, get_current_user_id() );
		$readiness = LNKup_Vendor_Readiness::checklist( $app_id );
		$reliability = $profile_id && class_exists( 'LNKup_Vendor_Reliability_Intel' )
			? LNKup_Vendor_Reliability_Intel::for_profile( $profile_id, get_current_user_id() )
			: null;
		return rest_ensure_response( array(
			'application_id'     => $app_id,
			'message'            => get_post( $app_id ) ? get_post( $app_id )->post_content : '',
			'status'             => get_post_meta( $app_id, '_lnkup_application_status', true ),
			'readiness'          => $readiness,
			'readiness_label'    => LNKup_Vendor_Readiness::label( LNKup_Vendor_Readiness::refresh( $app_id ) ),
			'profile'            => $profile,
			'reliability'        => $reliability,
			'payment_likelihood' => $reliability['payment_likelihood'] ?? null,
			'no_show_likelihood' => $reliability['no_show_likelihood'] ?? null,
		) );
	}

	public static function compare_applications( WP_REST_Request $request ) {
		$ids = $request->get_param( 'ids' );
		if ( is_string( $ids ) ) {
			$ids = array_map( 'absint', explode( ',', $ids ) );
		}
		$ids = array_slice( array_filter( (array) $ids ), 0, 4 );
		$items = array();
		foreach ( $ids as $app_id ) {
			if ( ! LNKup_Capabilities::user_owns_post( $app_id, get_current_user_id(), '_lnkup_organizer_user_id' ) ) {
				continue;
			}
			$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
			$profile = LNKup_Vendor_CRM_Profile::get( $profile_id, get_current_user_id() );
			if ( $profile ) {
				$profile['application_message'] = get_post( $app_id )->post_content;
				$profile['application_id'] = $app_id;
				$items[] = $profile;
			}
		}
		return rest_ensure_response( array( 'items' => $items ) );
	}

	public static function event_roster( WP_REST_Request $request ) {
		$result = LNKup_Event_Roster::get( absint( $request['id'] ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function roster_bulk( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: $request->get_params();
		$result = LNKup_Event_Roster::bulk_action(
			absint( $request['id'] ),
			get_current_user_id(),
			$body['application_ids'] ?? array(),
			$body['action'] ?? '',
			$body
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function check_in( WP_REST_Request $request ) {
		$app_id   = absint( $request['id'] );
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$result   = LNKup_Event_Roster::bulk_action( $event_id, get_current_user_id(), array( $app_id ), 'check_in' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$item = $result['results'][0] ?? $result;
		if ( is_array( $item ) && empty( $item['success'] ) ) {
			return LNKup_Checkins::rest_response( $item );
		}
		return rest_ensure_response( $result );
	}

	public static function no_show( WP_REST_Request $request ) {
		$app_id   = absint( $request['id'] );
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$result   = LNKup_Event_Roster::bulk_action( $event_id, get_current_user_id(), array( $app_id ), 'no_show' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$item = $result['results'][0] ?? $result;
		if ( is_array( $item ) && empty( $item['success'] ) ) {
			return LNKup_Checkins::rest_response( $item );
		}
		return rest_ensure_response( $result );
	}

	public static function dashboard_widgets( WP_REST_Request $request ) {
		$org = get_current_user_id();
		$apps = lnkup_get_applications_for_user( $org, 'organizer' );
		$pending = array_filter( $apps, function ( $a ) {
			return in_array( get_post_meta( $a->ID, '_lnkup_application_status', true ), array( 'submitted', 'under_review' ), true );
		} );
		global $wpdb;
		$table = LNKup_Organizer_CRM_Tables::rel_table();
		$favorites = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE organizer_user_id = %d AND is_favorite = 1", $org ) );
		$preferred = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE organizer_user_id = %d AND is_preferred = 1", $org ) );
		$recent_notes = get_posts( array(
			'post_type' => 'lnkup_vendor_note', 'posts_per_page' => 5,
			'meta_key' => '_lnkup_organizer_user_id', 'meta_value' => $org,
		) );
		$notes_out = array();
		foreach ( $recent_notes as $n ) {
			$notes_out[] = array( 'id' => $n->ID, 'content' => wp_trim_words( $n->post_content, 12 ), 'date' => $n->post_date );
		}
		$events = get_posts( array(
			'post_type' => 'lnkup_event', 'posts_per_page' => 3,
			'meta_key' => '_lnkup_organizer_user_id', 'meta_value' => $org,
			'meta_query' => array( array( 'key' => '_lnkup_event_date', 'value' => gmdate( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' ) ),
		) );
		$rosters = array();
		foreach ( $events as $ev ) {
			$r = LNKup_Event_Roster::get( $ev->ID, $org );
			if ( ! is_wp_error( $r ) ) {
				$rosters[] = array( 'event_id' => $ev->ID, 'title' => $ev->post_title, 'total' => $r['total'] );
			}
		}
		$activity = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT activity_type, created_at, vendor_user_id FROM " . LNKup_Organizer_CRM_Tables::activity_table() . " WHERE organizer_user_id = %d AND activity_type = %s ORDER BY created_at DESC LIMIT 10",
				$org,
				'applied'
			),
			ARRAY_A
		);
		$rebook = array();
		$pref_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT vendor_profile_id FROM {$table} WHERE organizer_user_id = %d AND is_preferred = 1 LIMIT 5", $org ),
			ARRAY_A
		);
		foreach ( (array) $pref_rows as $pr ) {
			$p = LNKup_Vendor_CRM_Profile::get( (int) $pr['vendor_profile_id'], $org );
			if ( $p ) {
				$rebook[] = $p;
			}
		}
		return rest_ensure_response( array(
			'pending_applications' => count( $pending ),
			'favorites_count'      => $favorites,
			'preferred_count'      => $preferred,
			'recent_notes'         => $notes_out,
			'upcoming_rosters'     => $rosters,
			'activity_feed'        => $activity,
			'rebook_recommendations'=> $rebook,
			'lists'                => LNKup_Vendor_Lists::get_for_organizer( $org ),
			'invites'              => LNKup_Vendor_Invites::get_for_organizer( $org, 5 ),
		) );
	}

	/**
	 * Organizer launch checklist (derived progress).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function launch_checklist( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Organizer_Launch_Checklist' ) ) {
			return new WP_Error( 'unavailable', __( 'Launch checklist unavailable.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		return rest_ensure_response( LNKup_Organizer_Launch_Checklist::get_checklist( get_current_user_id() ) );
	}

	/**
	 * Download CSV import template.
	 */
	public static function import_template( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Vendor_Import' ) ) {
			return new WP_Error( 'unavailable', __( 'Import unavailable.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		$csv = LNKup_Vendor_Import::template_csv();
		$response = new WP_REST_Response( $csv );
		$response->set_headers(
			array(
				'Content-Type'        => 'text/csv; charset=utf-8',
				'Content-Disposition' => 'attachment; filename="lnkup-vendor-import-template.csv"',
			)
		);
		return $response;
	}

	/**
	 * Upload CSV for import.
	 */
	public static function import_upload( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Vendor_Import' ) ) {
			return new WP_Error( 'unavailable', __( 'Import unavailable.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;
		if ( ! $file || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', __( 'No file uploaded.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$result = LNKup_Vendor_Import::handle_upload( get_current_user_id(), $file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['field_labels'] = LNKup_Vendor_Import::field_labels();
		$result['max_rows']     = LNKup_Vendor_Import::max_rows();
		$result['max_bytes']    = LNKup_Vendor_Import::max_file_bytes();
		return rest_ensure_response( $result );
	}

	/**
	 * Preview import mapping.
	 */
	public static function import_preview( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Vendor_Import' ) ) {
			return new WP_Error( 'unavailable', __( 'Import unavailable.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		$body      = $request->get_json_params() ?: $request->get_params();
		$import_id = absint( $body['import_id'] ?? 0 );
		$mapping   = isset( $body['mapping'] ) ? (array) $body['mapping'] : array();
		$options   = isset( $body['options'] ) ? (array) $body['options'] : array();
		$result    = LNKup_Vendor_Import::preview_import( get_current_user_id(), $import_id, $mapping, $options );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Run import job.
	 */
	public static function import_run( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Vendor_Import' ) ) {
			return new WP_Error( 'unavailable', __( 'Import unavailable.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		$body      = $request->get_json_params() ?: $request->get_params();
		$import_id = absint( $body['import_id'] ?? 0 );
		$mapping   = isset( $body['mapping'] ) ? (array) $body['mapping'] : array();
		$options   = isset( $body['options'] ) ? (array) $body['options'] : array();
		if ( ! empty( $body['confirm_invites'] ) ) {
			$options['confirm_invites'] = true;
		}
		$result = LNKup_Vendor_Import::run_import( get_current_user_id(), $import_id, $mapping, $options );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Import job status.
	 */
	public static function import_status( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Vendor_Import' ) ) {
			return new WP_Error( 'unavailable', __( 'Import unavailable.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		$job = LNKup_Vendor_Import::get_job( absint( $request['id'] ), get_current_user_id() );
		if ( ! $job ) {
			return new WP_Error( 'not_found', __( 'Import not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$summary = json_decode( $job['summary_json'] ?? '{}', true );
		return rest_ensure_response(
			array(
				'import_id' => (int) $job['id'],
				'status'    => $job['status'],
				'summary'   => is_array( $summary ) ? $summary : array(),
			)
		);
	}

	/**
	 * List organizer prospects.
	 */
	public static function prospects( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Vendor_Import' ) ) {
			return new WP_Error( 'unavailable', __( 'Import unavailable.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		$result = LNKup_Vendor_Import::list_prospects( get_current_user_id(), $request->get_params() );
		return rest_ensure_response( $result );
	}
}
