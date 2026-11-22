<?php
/**
 * User-facing REST: preferences, categorized inbox, read/open tracking, next steps.
 *
 * Every route requires a logged-in user and only touches that user's own rows.
 * The existing /notifications/inbox* and /push/* routes keep working unchanged.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_REST {

	const NS = 'lnkup/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function logged_in(): bool {
		return is_user_logged_in();
	}

	public static function register_routes(): void {
		$role_arg = array(
			'role' => array(
				'type'              => 'string',
				'enum'              => array( 'vendor', 'organizer', '' ),
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
		);
		register_rest_route(
			self::NS,
			'/comms/preferences',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_preferences' ),
					'permission_callback' => array( __CLASS__, 'logged_in' ),
				),
				array(
					'methods'             => 'PATCH,POST',
					'callback'            => array( __CLASS__, 'update_preferences' ),
					'permission_callback' => array( __CLASS__, 'logged_in' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/comms/inbox',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'inbox' ),
				'permission_callback' => array( __CLASS__, 'logged_in' ),
				'args'                => array_merge(
					$role_arg,
					array(
						'tab'      => array( 'type' => 'string', 'default' => 'all', 'sanitize_callback' => 'sanitize_key' ),
						'filter'   => array( 'type' => 'string', 'enum' => array( 'all', 'unread', 'action' ), 'default' => 'all' ),
						'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
						'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 50 ),
					)
				),
			)
		);
		register_rest_route(
			self::NS,
			'/comms/inbox/count',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'inbox_count' ),
				'permission_callback' => array( __CLASS__, 'logged_in' ),
				'args'                => $role_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/comms/inbox/(?P<id>[a-z0-9_\-]{1,120})/read',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'mark_read' ),
				'permission_callback' => array( __CLASS__, 'logged_in' ),
			)
		);
		register_rest_route(
			self::NS,
			'/comms/inbox/read-all',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'mark_all_read' ),
				'permission_callback' => array( __CLASS__, 'logged_in' ),
				'args'                => array_merge( $role_arg, array( 'tab' => array( 'type' => 'string', 'default' => 'all', 'sanitize_callback' => 'sanitize_key' ) ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/comms/opened',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'opened' ),
				'permission_callback' => array( __CLASS__, 'logged_in' ),
				'args'                => array( 'notificationId' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/comms/next-steps',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'next_steps' ),
				'permission_callback' => array( __CLASS__, 'logged_in' ),
				'args'                => $role_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/comms/registry',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'registry' ),
				'permission_callback' => array( __CLASS__, 'logged_in' ),
			)
		);
	}

	private static function resolve_role( int $user_id, string $requested ): string {
		$roles = LNKup_Comms_Preferences::user_roles( $user_id );
		if ( '' !== $requested && in_array( $requested, $roles, true ) ) {
			return $requested;
		}
		return $roles[0] ?? 'vendor';
	}

	public static function get_preferences(): WP_REST_Response {
		return rest_ensure_response( LNKup_Comms_Preferences::get( get_current_user_id() ) );
	}

	public static function update_preferences( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		return rest_ensure_response( LNKup_Comms_Preferences::update( get_current_user_id(), is_array( $body ) ? $body : array() ) );
	}

	/**
	 * Format an inbox row (ours or core's) for clients.
	 */
	private static function format_row( array $row, string $role ): ?array {
		$base = LNKup_Inbox_Service::format_row( $row );
		if ( ! $base ) {
			return null;
		}
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$payload = is_array( $payload ) ? $payload : array();
		$deep    = (string) ( $payload['deepLink'] ?? '' );
		if ( '' === $deep && isset( $payload['href'] ) && 0 === strpos( (string) $payload['href'], '/' ) ) {
			$deep = (string) $payload['href'];
		}
		$base['tab']            = (string) ( $payload['tab'] ?? LNKup_Comms_Registry::tab_for_type( (string) $row['notification_type'], $role ) );
		$base['category']       = (string) ( $payload['category'] ?? '' );
		$base['deepLink']       = $deep;
		$base['applicationId']  = (string) ( $payload['applicationId'] ?? $base['requestId'] ?? '' );
		$base['eventId']        = (string) ( $payload['eventId'] ?? '' );
		$base['opportunityId']  = (string) ( $payload['opportunityId'] ?? '' );
		$base['notificationId'] = (int) ( $payload['notificationId'] ?? 0 );
		$base['priority']       = (string) ( $payload['priority'] ?? '' );
		return $base;
	}

	/**
	 * WHERE clause for the user's inbox, by role / tab / filter.
	 *
	 * @return array{0:string,1:array}
	 */
	private static function where( int $user_id, string $role, string $tab, string $filter ): array {
		$where  = array( 'user_id = %d', "title <> ''", 'dismissed_at IS NULL' );
		$params = array( $user_id );
		if ( '' !== $role ) {
			$where[]  = "(role = %s OR role = '')";
			$params[] = $role;
		}
		if ( 'all' !== $tab && in_array( $tab, LNKup_Comms_Registry::tabs( $role ?: 'vendor' ), true ) ) {
			$types = LNKup_Comms_Registry::types_for_tab( $tab, $role ?: 'vendor' );
			if ( array() === $types ) {
				$where[] = '1 = 0';
			} else {
				$where[] = 'notification_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
				$params  = array_merge( $params, $types );
			}
		}
		if ( 'unread' === $filter ) {
			$where[] = 'read_at IS NULL';
		} elseif ( 'action' === $filter ) {
			$where[] = 'action_required = 1 AND read_at IS NULL';
		}
		return array( implode( ' AND ', $where ), $params );
	}

	public static function inbox( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$user_id  = get_current_user_id();
		$role     = self::resolve_role( $user_id, (string) $request['role'] );
		$tab      = (string) $request['tab'];
		$page     = max( 1, (int) $request['page'] );
		$per_page = min( 50, max( 1, (int) $request['per_page'] ) );
		$table    = LNKup_Inbox_Tables::index_table();
		list( $where, $params ) = self::where( $user_id, $role, $tab, (string) $request['filter'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		$items = array();
		foreach ( $rows as $row ) {
			$item = self::format_row( $row, $role );
			if ( $item ) {
				$items[] = $item;
			}
		}
		return rest_ensure_response(
			array(
				'items'    => $items,
				'page'     => $page,
				'perPage'  => $per_page,
				'total'    => $total,
				'hasMore'  => $page * $per_page < $total,
				'role'     => $role,
				'tab'      => $tab,
				'tabs'     => LNKup_Comms_Registry::tabs( $role ),
			)
		);
	}

	public static function inbox_count( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$user_id = get_current_user_id();
		$role    = self::resolve_role( $user_id, (string) $request['role'] );
		$table   = LNKup_Inbox_Tables::index_table();
		$by_tab  = array();
		foreach ( LNKup_Comms_Registry::tabs( $role ) as $tab ) {
			list( $where, $params ) = self::where( $user_id, $role, $tab, 'unread' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$by_tab[ $tab ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
		}
		list( $where, $params ) = self::where( $user_id, $role, 'all', 'action' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$action = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
		return rest_ensure_response(
			array(
				'unread'         => $by_tab['all'] ?? 0,
				'actionRequired' => $action,
				'byTab'          => $by_tab,
				'role'           => $role,
			)
		);
	}

	public static function mark_read( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$client_id = LNKup_Inbox_Service::sanitize_client_id( (string) $request['id'] );
		$result    = LNKup_Inbox_Service::mark_read( $user_id, $client_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( preg_match( '/^comms-(\d+)$/', $client_id, $m ) ) {
			LNKup_Comms_Engine::mark_opened( $user_id, (int) $m[1], false );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function mark_all_read( WP_REST_Request $request ) {
		global $wpdb;
		$user_id = get_current_user_id();
		$role    = self::resolve_role( $user_id, (string) $request['role'] );
		$table   = LNKup_Inbox_Tables::index_table();
		list( $where, $params ) = self::where( $user_id, $role, (string) $request['tab'], 'unread' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT client_id FROM {$table} WHERE {$where} LIMIT 500", $params ) );
		$now = current_time( 'mysql' );
		if ( $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET read_at = %s, updated_at = %s WHERE user_id = %d AND client_id IN ({$placeholders})", array_merge( array( $now, $now, $user_id ), $ids ) ) );
			$message_ids = array();
			foreach ( $ids as $client_id ) {
				if ( preg_match( '/^comms-(\d+)$/', (string) $client_id, $m ) ) {
					$message_ids[] = (int) $m[1];
				}
			}
			if ( $message_ids ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Comms_Schema::table( 'messages' ) . ' SET read_at = %s WHERE recipient_user_id = %d AND read_at IS NULL AND id IN (' . implode( ',', $message_ids ) . ')', LNKup_Comms_Schema::now(), $user_id ) );
			}
		}
		return rest_ensure_response( array( 'ok' => true, 'marked' => count( $ids ) ) );
	}

	public static function opened( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$id      = absint( $request['notificationId'] );
		$message = LNKup_Comms_Engine::message( $id );
		if ( ! $message || (int) $message['recipient_user_id'] !== $user_id ) {
			return rest_ensure_response( array( 'ok' => false ) );
		}
		LNKup_Comms_Engine::mark_opened( $user_id, $id, true );
		LNKup_Inbox_Service::mark_read( $user_id, LNKup_Comms_Channel_Inbox::client_id( $id ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function next_steps( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$role    = self::resolve_role( $user_id, (string) $request['role'] );
		$data    = 'organizer' === $role ? LNKup_Comms_Next_Steps::organizer( $user_id ) : LNKup_Comms_Next_Steps::vendor( $user_id );
		$data['role'] = $role;
		return rest_ensure_response( $data );
	}

	public static function registry(): WP_REST_Response {
		$user_id = get_current_user_id();
		$tabs    = array();
		foreach ( LNKup_Comms_Preferences::user_roles( $user_id ) as $role ) {
			$tabs[ $role ] = LNKup_Comms_Registry::tabs( $role );
		}
		return rest_ensure_response( array( 'tabs' => $tabs ) );
	}
}
