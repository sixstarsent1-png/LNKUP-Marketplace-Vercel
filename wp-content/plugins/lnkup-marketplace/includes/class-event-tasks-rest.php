<?php
/**
 * REST routes for event tasks and task templates (lnkup/v1), behind organizer_ops_v2_enabled.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Tasks_REST {

	const NS = 'lnkup/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$id = array( 'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ) );

		register_rest_route(
			self::NS,
			'/events/(?P<id>\d+)/tasks',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'event_tasks' ), 'permission_callback' => self::event_cap( 'view_roster' ), 'args' => $id ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create' ), 'permission_callback' => self::event_cap( 'edit_ops' ), 'args' => $id ),
			)
		);
		register_rest_route( self::NS, '/events/(?P<id>\d+)/tasks/apply-template', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'apply_template' ), 'permission_callback' => self::event_cap( 'edit_ops' ), 'args' => $id ) );
		register_rest_route( self::NS, '/events/(?P<id>\d+)/task-assignees', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'assignees' ), 'permission_callback' => self::event_cap( 'edit_ops' ), 'args' => $id ) );
		register_rest_route(
			self::NS,
			'/tasks/(?P<id>\d+)',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_task' ), 'permission_callback' => self::task_cap( 'view_roster' ), 'args' => $id ),
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update' ), 'permission_callback' => self::task_cap( 'view_roster' ), 'args' => $id ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'cancel' ), 'permission_callback' => self::task_cap( 'edit_ops' ), 'args' => $id ),
			)
		);
		register_rest_route( self::NS, '/tasks/(?P<id>\d+)/comments', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'comment' ), 'permission_callback' => self::task_cap( 'view_roster' ), 'args' => $id ) );
		register_rest_route(
			self::NS,
			'/tasks',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'my_tasks' ),
				'permission_callback' => array( __CLASS__, 'workspace_permission' ),
				'args'                => array(
					'view'     => array( 'type' => 'string', 'default' => 'mine' ),
					'event_id' => array( 'type' => 'integer', 'default' => 0 ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/task-templates',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'templates' ), 'permission_callback' => array( __CLASS__, 'template_permission' ) ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_template' ), 'permission_callback' => array( __CLASS__, 'template_permission' ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/task-templates/(?P<id>\d+)',
			array(
				array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'save_template' ), 'permission_callback' => array( __CLASS__, 'template_permission' ), 'args' => $id ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_template' ), 'permission_callback' => array( __CLASS__, 'template_permission' ), 'args' => $id ),
			)
		);
	}

	/* ---------- permissions ---------- */

	/**
	 * @return true|WP_Error
	 */
	public static function gate() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Sign in to continue.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		if ( ! LNKup_Feature_Flags::organizer_ops() ) {
			return new WP_Error( 'lnkup_feature_off', __( 'This feature is not turned on yet.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! LNKup_Organizer_OS_Tables::ready( '2' ) ) {
			return new WP_Error( 'lnkup_not_ready', __( 'This feature is still being set up. Try again in a minute.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		return true;
	}

	public static function workspace_permission() {
		$gate = self::gate();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		return LNKup_Capabilities::can_access_org_workspace() ? true : new WP_Error( 'lnkup_forbidden', __( 'Organizer access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
	}

	/**
	 * Templates belong to the organizer account itself.
	 */
	public static function template_permission() {
		$gate = self::gate();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$user_id = get_current_user_id();
		return LNKup_Roles::is_organizer( $user_id ) || LNKup_Capabilities::user_has_manage_all( $user_id )
			? true
			: new WP_Error( 'lnkup_forbidden', __( 'Only organizers can manage task templates.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
	}

	/**
	 * @param string $capability Capability on the event.
	 */
	public static function event_cap( $capability ) {
		return static function ( WP_REST_Request $request ) use ( $capability ) {
			$gate = self::gate();
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
			$event_id = absint( $request['id'] );
			if ( 'lnkup_event' !== get_post_type( $event_id ) ) {
				return new WP_Error( 'lnkup_not_found', __( 'Event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			return LNKup_Capabilities::staff_can( $event_id, $capability )
				? true
				: new WP_Error( 'lnkup_forbidden', __( 'You do not have access to this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		};
	}

	/**
	 * @param string $capability Capability on the task's event.
	 */
	private static function task_cap( $capability ) {
		return static function ( WP_REST_Request $request ) use ( $capability ) {
			$gate = self::gate();
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
			$row = LNKup_Event_Tasks::get_row( (int) $request['id'] );
			if ( ! $row || ! LNKup_Capabilities::staff_can( (int) $row['event_id'], 'view_roster' ) ) {
				return new WP_Error( 'lnkup_not_found', __( 'Task not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			return LNKup_Capabilities::staff_can( (int) $row['event_id'], $capability )
				? true
				: new WP_Error( 'lnkup_forbidden', __( 'You cannot change this task.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		};
	}

	/* ---------- handlers ---------- */

	public static function event_tasks( WP_REST_Request $request ) {
		$event_id = (int) $request['id'];
		return rest_ensure_response(
			array(
				'items'    => LNKup_Event_Tasks::for_event( $event_id, get_current_user_id(), array( 'status' => (string) $request->get_param( 'status' ) ) ),
				'counts'   => LNKup_Event_Tasks::event_counts( $event_id ),
				'can_edit' => LNKup_Capabilities::staff_can( $event_id, 'edit_ops' ),
			)
		);
	}

	public static function create( WP_REST_Request $request ) {
		return self::respond( LNKup_Event_Tasks::create( (int) $request['id'], self::body( $request ), get_current_user_id() ) );
	}

	public static function apply_template( WP_REST_Request $request ) {
		$body = self::body( $request );
		return self::respond( LNKup_Event_Tasks::apply_template( (int) $request['id'], absint( $body['template_id'] ?? 0 ), get_current_user_id() ) );
	}

	public static function assignees( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Event_Tasks::assignee_candidates( (int) $request['id'] ) ) );
	}

	public static function get_task( WP_REST_Request $request ) {
		$row = LNKup_Event_Tasks::get_row( (int) $request['id'] );
		$out = LNKup_Event_Tasks::format( $row, get_current_user_id(), true );
		$out['comments'] = LNKup_Event_Tasks::comments( (int) $row['id'] );
		return rest_ensure_response( $out );
	}

	public static function update( WP_REST_Request $request ) {
		return self::respond( LNKup_Event_Tasks::update( (int) $request['id'], self::body( $request ), get_current_user_id() ) );
	}

	public static function cancel( WP_REST_Request $request ) {
		return self::respond( LNKup_Event_Tasks::update( (int) $request['id'], array( 'status' => 'cancelled' ), get_current_user_id() ) );
	}

	public static function comment( WP_REST_Request $request ) {
		$body = self::body( $request );
		$out  = LNKup_Event_Tasks::add_comment( (int) $request['id'], (string) ( $body['body'] ?? '' ), get_current_user_id() );
		return is_wp_error( $out ) ? $out : rest_ensure_response( array( 'items' => $out ) );
	}

	public static function my_tasks( WP_REST_Request $request ) {
		return rest_ensure_response(
			LNKup_Event_Tasks::list_for_user(
				get_current_user_id(),
				array(
					'view'     => (string) $request['view'],
					'event_id' => (int) $request['event_id'],
					'page'     => (int) $request['page'],
					'per_page' => (int) $request['per_page'],
				)
			)
		);
	}

	public static function templates() {
		return rest_ensure_response( array( 'items' => LNKup_Event_Tasks::templates_for( get_current_user_id() ) ) );
	}

	public static function save_template( WP_REST_Request $request ) {
		return self::respond( LNKup_Event_Tasks::save_template( get_current_user_id(), absint( $request['id'] ?? 0 ), self::body( $request ) ) );
	}

	public static function delete_template( WP_REST_Request $request ) {
		$result = LNKup_Event_Tasks::delete_template( get_current_user_id(), (int) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * @param mixed $result Result or WP_Error.
	 */
	private static function respond( $result ) {
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	private static function body( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : (array) $request->get_body_params();
	}
}
