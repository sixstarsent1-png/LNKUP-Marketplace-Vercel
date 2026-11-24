<?php
/**
 * Admin REST for the Communications OS (also used by the wp-admin screens).
 * Requires lnkup_manage_all (LNKup admins) or manage_options.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Admin_REST {

	const NS = 'lnkup/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function is_admin_user(): bool {
		$user_id = get_current_user_id();
		return $user_id > 0 && ( current_user_can( 'manage_options' ) || LNKup_Capabilities::user_has_manage_all( $user_id ) );
	}

	public static function register_routes(): void {
		$perm = array( __CLASS__, 'is_admin_user' );
		$routes = array(
			array( '/comms/admin/overview', 'GET', 'overview' ),
			array( '/comms/admin/logs', 'GET', 'logs' ),
			array( '/comms/admin/health', 'GET', 'health' ),
			array( '/comms/admin/health/action', 'POST', 'health_action' ),
			array( '/comms/admin/settings', 'GET', 'get_settings' ),
			array( '/comms/admin/settings', 'POST', 'save_settings' ),
			array( '/comms/admin/templates', 'GET', 'templates' ),
			array( '/comms/admin/templates/(?P<key>[a-z0-9_:]+)', 'POST', 'save_template' ),
			array( '/comms/admin/templates/(?P<key>[a-z0-9_:]+)/preview', 'POST', 'preview_template' ),
			array( '/comms/admin/campaigns', 'GET', 'campaigns' ),
			array( '/comms/admin/campaigns', 'POST', 'create_campaign' ),
			array( '/comms/admin/campaigns/(?P<id>\d+)/preview', 'POST', 'campaign_preview' ),
			array( '/comms/admin/campaigns/(?P<id>\d+)/test', 'POST', 'campaign_test' ),
			array( '/comms/admin/campaigns/(?P<id>\d+)/(?P<action>start|pause|resume|cancel)', 'POST', 'campaign_action' ),
			array( '/comms/admin/analytics', 'GET', 'analytics' ),
			array( '/comms/admin/automations', 'GET', 'automations' ),
			array( '/comms/admin/automations/(?P<key>[a-z0-9_]+)/(?P<action>pause|resume)', 'POST', 'automation_action' ),
			array( '/comms/admin/test-send', 'POST', 'test_send' ),
			array( '/comms/admin/templates/(?P<key>[a-z0-9_:]+)/versions', 'GET', 'template_versions' ),
			array( '/comms/admin/templates/(?P<key>[a-z0-9_:]+)/draft', 'POST', 'save_template_draft' ),
			array( '/comms/admin/templates/(?P<key>[a-z0-9_:]+)/publish', 'POST', 'publish_template_draft' ),
			array( '/comms/admin/template-versions/(?P<id>\d+)/restore', 'POST', 'restore_template_version' ),
			array( '/comms/admin/users/(?P<id>\d+)/timeline', 'GET', 'user_timeline' ),
			array( '/comms/admin/suppressions', 'GET', 'suppressions' ),
			array( '/comms/admin/suppressions/(?P<id>\d+)', 'DELETE', 'remove_suppression' ),
		);
		$grouped = array();
		foreach ( $routes as $route ) {
			$grouped[ $route[0] ][] = array(
				'methods'             => $route[1],
				'callback'            => array( __CLASS__, $route[2] ),
				'permission_callback' => $perm,
			);
		}
		foreach ( $grouped as $path => $handlers ) {
			register_rest_route( self::NS, $path, $handlers );
		}
	}

	public static function overview( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( LNKup_Comms_Analytics::overview( max( 1, min( 90, (int) ( $request['days'] ?? 7 ) ) ) ) );
	}

	/**
	 * Paginated delivery history. Bodies are never stored; only rendered titles.
	 */
	public static function logs( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( self::query_logs( $request->get_params() ) );
	}

	public static function query_logs( array $params ): array {
		global $wpdb;
		$messages = LNKup_Comms_Schema::table( 'messages' );
		$where    = array( '1=1' );
		$args     = array();
		foreach ( array( 'trigger_key' => 'trigger', 'status' => 'status', 'recipient_role' => 'role' ) as $col => $param ) {
			if ( ! empty( $params[ $param ] ) ) {
				$where[] = "m.{$col} = %s";
				$args[]  = sanitize_key( (string) $params[ $param ] );
			}
		}
		foreach ( array( 'recipient_user_id' => 'user', 'event_id' => 'event', 'application_id' => 'application', 'campaign_id' => 'campaign' ) as $col => $param ) {
			if ( ! empty( $params[ $param ] ) ) {
				$where[] = "m.{$col} = %d";
				$args[]  = absint( $params[ $param ] );
			}
		}
		$page     = max( 1, absint( $params['page'] ?? 1 ) );
		$per_page = min( 100, max( 10, absint( $params['per_page'] ?? 50 ) ) );
		$sql_where = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} m WHERE {$sql_where}", $args ) : "SELECT COUNT(*) FROM {$messages} m WHERE {$sql_where}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.id, m.trigger_key, m.recipient_user_id, m.recipient_role, m.category, m.priority, m.event_id, m.application_id, m.campaign_id, m.template_key, m.title, m.status, m.suppression_reason, m.scheduled_at, m.sent_at, m.read_at, m.opened_at, m.clicked_at, m.created_at FROM {$messages} m WHERE {$sql_where} ORDER BY m.id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		$ids  = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$dels = array();
		if ( $ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$drows = (array) $wpdb->get_results( 'SELECT message_id, channel, status, attempts, last_error, suppression_reason, updated_at FROM ' . LNKup_Comms_Schema::table( 'deliveries' ) . ' WHERE message_id IN (' . implode( ',', $ids ) . ') ORDER BY id ASC', ARRAY_A );
			foreach ( $drows as $d ) {
				$dels[ (int) $d['message_id'] ][] = $d;
			}
		}
		foreach ( $rows as &$row ) {
			$user              = get_userdata( (int) $row['recipient_user_id'] );
			$row['recipient']  = $user ? (string) $user->display_name : __( '(deleted user)', 'lnkup-communications' );
			$row['event_name'] = (int) $row['event_id'] ? (string) get_the_title( (int) $row['event_id'] ) : '';
			$row['deliveries'] = $dels[ (int) $row['id'] ] ?? array();
		}
		return array( 'items' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $per_page );
	}

	public static function health(): WP_REST_Response {
		return rest_ensure_response( array( 'checks' => LNKup_Comms_Health::checks() ) );
	}

	public static function health_action( WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request['action'] );
		switch ( $action ) {
			case 'sweep':
				LNKup_Comms_Queue::sweep();
				return rest_ensure_response( array( 'ok' => true ) );
			case 'retry_failed':
				return rest_ensure_response( array( 'ok' => true, 'queued' => LNKup_Comms_Health::retry_failed() ) );
			case 'cancel_stale':
				return rest_ensure_response( array( 'ok' => true, 'cancelled' => LNKup_Comms_Health::stale_scheduled( true ) ) );
		}
		return new WP_Error( 'lnkup_comms_action', __( 'Unknown action.', 'lnkup-communications' ), array( 'status' => 400 ) );
	}

	public static function get_settings(): WP_REST_Response {
		$settings = LNKup_Comms_Settings::all();
		$settings['email_webhook_secret'] = '' !== (string) $settings['email_webhook_secret'] ? '••••••••' : '';
		return rest_ensure_response( array( 'settings' => $settings, 'flags' => LNKup_Comms_Settings::flags() ) );
	}

	public static function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$input = (array) $request->get_json_params();
		if ( isset( $input['email_webhook_secret'] ) && '••••••••' === $input['email_webhook_secret'] ) {
			unset( $input['email_webhook_secret'] );
		}
		$before = LNKup_Comms_Settings::all();
		LNKup_Comms_Admin_Ops::audit_settings( $before, LNKup_Comms_Settings::update( $input ) );
		return self::get_settings();
	}

	public static function templates(): WP_REST_Response {
		$out = array();
		foreach ( array_keys( LNKup_Comms_Templates::defaults() ) as $key ) {
			$out[] = array(
				'key'        => $key,
				'template'   => LNKup_Comms_Templates::get( $key ),
				'overridden' => array() !== LNKup_Comms_Templates::override( $key ),
				'variables'  => LNKup_Comms_Templates::placeholders( LNKup_Comms_Templates::defaults()[ $key ] ),
			);
		}
		return rest_ensure_response( array( 'items' => $out ) );
	}

	public static function save_template( WP_REST_Request $request ) {
		$key = (string) $request['key'];
		if ( ! empty( $request['reset'] ) ) {
			LNKup_Comms_Templates::reset_override( $key );
			return rest_ensure_response( array( 'ok' => true ) );
		}
		$result = LNKup_Comms_Templates::save_override( $key, (array) $request->get_json_params(), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true ) );
	}

	public static function template_versions( WP_REST_Request $request ): WP_REST_Response {
		$key = (string) $request['key'];
		return rest_ensure_response( array( 'items' => LNKup_Comms_Templates::versions( $key ), 'draft' => LNKup_Comms_Templates::draft( $key ) ) );
	}

	public static function save_template_draft( WP_REST_Request $request ) {
		$result = LNKup_Comms_Templates::save_draft( (string) $request['key'], (array) $request->get_json_params(), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true, 'id' => $result ) );
	}

	public static function publish_template_draft( WP_REST_Request $request ) {
		$result = LNKup_Comms_Templates::publish_draft( (string) $request['key'], get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true ) );
	}

	public static function restore_template_version( WP_REST_Request $request ) {
		$result = LNKup_Comms_Templates::restore_version( absint( $request['id'] ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * One user's communication history. Every view is audit-logged.
	 */
	public static function user_timeline( WP_REST_Request $request ) {
		$user_id  = absint( $request['id'] );
		$timeline = LNKup_Comms_Admin_Ops::user_timeline( $user_id );
		if ( null === $timeline ) {
			return new WP_Error( 'lnkup_comms_not_found', __( 'User not found.', 'lnkup-communications' ), array( 'status' => 404 ) );
		}
		LNKup_Comms_Admin_Ops::audit( 'comms_timeline_viewed', $user_id );
		return rest_ensure_response( $timeline );
	}

	public static function suppressions( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( LNKup_Comms_Admin_Ops::suppressions( sanitize_email( (string) $request['email'] ), max( 1, absint( $request['page'] ?? 1 ) ) ) );
	}

	public static function remove_suppression( WP_REST_Request $request ) {
		$result = LNKup_Comms_Admin_Ops::remove_suppression( absint( $request['id'] ), (string) $request['note'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Render with clearly-labelled sample values (never real customer data).
	 */
	public static function preview_template( WP_REST_Request $request ) {
		$key      = (string) $request['key'];
		$template = LNKup_Comms_Templates::get( $key );
		if ( ! $template ) {
			return new WP_Error( 'lnkup_comms_template', __( 'Unknown template.', 'lnkup-communications' ), array( 'status' => 404 ) );
		}
		$samples = self::sample_variables();
		$vars    = array();
		foreach ( LNKup_Comms_Templates::placeholders( $template ) as $name ) {
			$vars[ $name ] = $samples[ $name ] ?? '[' . $name . ']';
		}
		$role     = 0 === strpos( $key, 'o_' ) ? 'organizer' : 'vendor';
		$rendered = LNKup_Comms_Templates::render( $key, $vars, $role, array( array( 'title' => 'Sample Festival', 'meta' => 'Sat, Jun 5 · Atlanta, GA', 'url' => home_url( '/' ) ) ) );
		if ( is_wp_error( $rendered ) ) {
			return new WP_Error( 'lnkup_comms_render', $rendered->get_error_message(), array( 'status' => 400 ) );
		}
		$footer = array( 'prefs_url' => LNKup_Comms_Suppression::preferences_url(), 'unsubscribe_url' => home_url( '/' ), 'reason' => __( 'Preview — sample values only.', 'lnkup-communications' ), 'address' => (string) LNKup_Comms_Settings::get( 'company_address' ) );
		return rest_ensure_response(
			array(
				'rendered' => $rendered,
				'html'     => LNKup_Comms_Templates::email_html( $rendered, home_url( '/' ), $footer ),
				'text'     => LNKup_Comms_Templates::email_text( $rendered, home_url( '/' ), $footer ),
			)
		);
	}

	public static function sample_variables(): array {
		return array(
			'first_name' => 'Jordan', 'business_name' => 'Sample Eats Co.', 'organizer_name' => 'Sample Events', 'event_name' => 'Sample Summer Festival',
			'opportunity_name' => 'Food vendor booth', 'event_date' => 'Sat, Jun 5, 2027', 'event_time' => '10:00 am', 'event_city' => 'Atlanta, GA',
			'application_status' => 'Approved', 'payment_deadline' => 'May 20, 2027', 'application_deadline' => 'May 1, 2027', 'booking_reference' => 'LNK-1001',
			'window_label' => 'tomorrow', 'tasks_line' => 'required documents', 'changed_fields' => 'Load-in time, Venue', 'count' => '3', 'pending_count' => '12',
			'stale_pending_count' => '5', 'hours' => '48', 'checked_in_count' => '18', 'confirmed_count' => '24', 'event_summary_line' => '24 confirmed vendors · 3 awaiting review · 2 approved but unpaid.',
			'next_step' => 'Choose your business categories', 'document_label' => 'Certificate of Insurance', 'review_note' => 'The policy dates are not visible.', 'expires_on' => 'Jul 1, 2027',
			'rejection_reason' => '', 'event_status_note' => 'Severe weather forecast.', 'outcome_label' => 'resolved in your favor', 'cadence_label' => 'monthly',
			'announcement_title' => 'Parking update', 'announcement_body' => 'Vendor parking moved to Lot C.', 'campaign_subject' => 'New on LNKup', 'campaign_body' => 'Sample announcement body.',
			'campaign_cta' => 'Learn more', 'campaign_push' => 'Sample push text.', 'digest_period' => 'weekly', 'spots_remaining' => '4', 'summary_line' => 'Sample health summary.',
			'open_spots' => '8', 'days_until' => '7', 'category_summary' => '4 Food Vendor, 2 Clothing', 'vendor_name' => 'Sample Eats Co.', 'invite_expires' => 'Jun 1',
		);
	}

	public static function campaigns(): WP_REST_Response {
		return rest_ensure_response( array( 'items' => LNKup_Comms_Campaigns::list( 'admin' ) ) );
	}

	public static function create_campaign( WP_REST_Request $request ) {
		$id = LNKup_Comms_Campaigns::create_admin( (array) $request->get_json_params(), get_current_user_id() );
		return is_wp_error( $id ) ? $id : rest_ensure_response( array( 'id' => $id ) );
	}

	public static function campaign_preview( WP_REST_Request $request ) {
		$preview = LNKup_Comms_Campaigns::preview( absint( $request['id'] ) );
		return null === $preview ? new WP_Error( 'lnkup_comms_campaign', __( 'Campaign not found.', 'lnkup-communications' ), array( 'status' => 404 ) ) : rest_ensure_response( $preview );
	}

	public static function campaign_test( WP_REST_Request $request ) {
		$result = LNKup_Comms_Campaigns::test_send( absint( $request['id'] ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'queued' => $result ) );
	}

	public static function campaign_action( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		switch ( (string) $request['action'] ) {
			case 'start':
			case 'resume':
				$result = LNKup_Comms_Campaigns::start( $id, absint( $request['confirm_count'] ?? 0 ) );
				return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true ) );
			case 'pause':
				LNKup_Comms_Campaigns::pause( $id );
				return rest_ensure_response( array( 'ok' => true ) );
			case 'cancel':
				LNKup_Comms_Campaigns::cancel( $id );
				return rest_ensure_response( array( 'ok' => true ) );
		}
		return new WP_Error( 'lnkup_comms_action', __( 'Unknown action.', 'lnkup-communications' ), array( 'status' => 400 ) );
	}

	public static function analytics( WP_REST_Request $request ): WP_REST_Response {
		$days = max( 1, min( 90, (int) ( $request['days'] ?? 30 ) ) );
		return rest_ensure_response(
			array(
				'triggers'          => LNKup_Comms_Analytics::trigger_report( $days ),
				'funnel'            => LNKup_Comms_Analytics::marketplace_funnel( $days ),
				'holdouts'          => LNKup_Comms_Admin_Ops::holdout_report( $days ),
				'attribution_days'  => LNKup_Comms_Settings::int( 'attribution_days' ),
				'note'              => __( 'Action completion is correlation within the attribution window, not proof a message caused the action.', 'lnkup-communications' ),
			)
		);
	}

	public static function automations(): WP_REST_Response {
		return rest_ensure_response( array( 'items' => LNKup_Comms_Automations::catalog(), 'paused' => (array) LNKup_Comms_Settings::get( 'paused_automations' ) ) );
	}

	/**
	 * Pause/resume an individual trigger. Critical triggers cannot be paused.
	 */
	public static function automation_action( WP_REST_Request $request ) {
		$key = sanitize_key( (string) $request['key'] );
		$def = LNKup_Comms_Registry::get( $key );
		if ( ! $def ) {
			return new WP_Error( 'lnkup_comms_action', __( 'Unknown trigger.', 'lnkup-communications' ), array( 'status' => 404 ) );
		}
		if ( 'critical' === $def['priority'] ) {
			return new WP_Error( 'lnkup_comms_locked', __( 'Critical messages cannot be paused.', 'lnkup-communications' ), array( 'status' => 409 ) );
		}
		$paused = (array) LNKup_Comms_Settings::get( 'paused_automations' );
		$paused = 'pause' === $request['action'] ? array_unique( array_merge( $paused, array( $key ) ) ) : array_diff( $paused, array( $key ) );
		LNKup_Comms_Settings::update( array( 'paused_automations' => array_values( $paused ) ) );
		LNKup_Comms_Admin_Ops::audit( 'pause' === $request['action'] ? 'comms_trigger_paused' : 'comms_trigger_resumed', $key );
		return rest_ensure_response( array( 'ok' => true, 'paused' => array_values( $paused ) ) );
	}

	/**
	 * Fire one real trigger to an internal test account, bypassing its feature flag.
	 * Recipients are restricted to the current admin and configured test accounts.
	 */
	public static function test_send( WP_REST_Request $request ) {
		$trigger   = sanitize_key( (string) $request['trigger'] );
		$recipient = absint( $request['user_id'] ?? get_current_user_id() );
		$allowed   = array_merge( array( get_current_user_id() ), LNKup_Comms_Settings::test_user_ids() );
		if ( ! in_array( $recipient, $allowed, true ) ) {
			return new WP_Error( 'lnkup_comms_test', __( 'Test sends can only go to you or configured test accounts.', 'lnkup-communications' ), array( 'status' => 403 ) );
		}
		if ( ! LNKup_Comms_Registry::get( $trigger ) ) {
			return new WP_Error( 'lnkup_comms_test', __( 'Unknown trigger.', 'lnkup-communications' ), array( 'status' => 404 ) );
		}
		$ctx = array();
		foreach ( array( 'application_id', 'event_id', 'opportunity_id', 'payment_id', 'document_id' ) as $key ) {
			if ( ! empty( $request[ $key ] ) ) {
				$ctx[ $key ] = absint( $request[ $key ] );
			}
		}
		$id = LNKup_Comms_Engine::dispatch( $trigger, $recipient, $ctx, array( 'force' => true, 'window' => 'test_' . time(), 'variant' => sanitize_key( (string) ( $request['variant'] ?? '' ) ), 'source' => 'admin_test' ) );
		if ( ! $id ) {
			return new WP_Error( 'lnkup_comms_test', __( 'Not queued: the recipient is not authorized for these records, or the message is not currently valid (see validity rules).', 'lnkup-communications' ), array( 'status' => 409 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'message_id' => $id ) );
	}
}
