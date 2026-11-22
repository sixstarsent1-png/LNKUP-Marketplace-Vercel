<?php
/**
 * Organizer event communications REST.
 *
 * Authorization: event owner, or event staff with the existing `broadcast`
 * capability (LNKup_Capabilities::staff_can). Recipients are always limited to
 * vendors with an application on that event. Rate limited and audited.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Organizer_REST {

	const NS = 'lnkup/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function can_message( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		if ( ! is_user_logged_in() || 'lnkup_event' !== get_post_type( $event_id ) ) {
			return false;
		}
		if ( ! LNKup_Capabilities::organizer_owns_event( $event_id, get_current_user_id() ) && ! LNKup_Capabilities::staff_can( $event_id, 'broadcast', get_current_user_id() ) ) {
			return false;
		}
		if ( ! LNKup_Comms_Settings::enabled( 'organizer_broadcasts' ) ) {
			return new WP_Error( 'lnkup_comms_disabled', __( 'Event communications are not enabled yet.', 'lnkup-communications' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function register_routes(): void {
		$id = array( 'id' => array( 'sanitize_callback' => 'absint' ) );
		register_rest_route( self::NS, '/comms/events/(?P<id>\d+)/audiences', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'audiences' ), 'permission_callback' => array( __CLASS__, 'can_message' ), 'args' => $id ) );
		register_rest_route( self::NS, '/comms/events/(?P<id>\d+)/templates', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'templates' ), 'permission_callback' => array( __CLASS__, 'can_message' ), 'args' => $id ) );
		register_rest_route( self::NS, '/comms/events/(?P<id>\d+)/preview', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'preview' ), 'permission_callback' => array( __CLASS__, 'can_message' ), 'args' => $id ) );
		register_rest_route( self::NS, '/comms/events/(?P<id>\d+)/send', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'send' ), 'permission_callback' => array( __CLASS__, 'can_message' ), 'args' => $id ) );
		register_rest_route( self::NS, '/comms/events/(?P<id>\d+)/history', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'history' ), 'permission_callback' => array( __CLASS__, 'can_message' ), 'args' => $id ) );
	}

	public static function audiences( WP_REST_Request $request ): WP_REST_Response {
		$event_id = absint( $request['id'] );
		return rest_ensure_response(
			array(
				'eventId'   => $event_id,
				'eventName' => (string) get_the_title( $event_id ),
				'groups'    => LNKup_Comms_Audiences::event_group_counts( $event_id ),
			)
		);
	}

	public static function templates(): WP_REST_Response {
		$out = array();
		foreach ( LNKup_Comms_Campaigns::event_templates() as $key => $template ) {
			$out[] = array(
				'key'      => $key,
				'label'    => $template['label'],
				'priority' => $template['priority'],
				'groups'   => $template['groups'],
				'title'    => $template['title'],
				'body'     => $template['body'],
			);
		}
		return rest_ensure_response( array( 'templates' => $out ) );
	}

	public static function preview( WP_REST_Request $request ) {
		$event_id  = absint( $request['id'] );
		$body      = (array) $request->get_json_params();
		$group     = sanitize_key( (string) ( $body['group'] ?? '' ) );
		$template  = sanitize_key( (string) ( $body['template'] ?? 'announcement' ) );
		$templates = LNKup_Comms_Campaigns::event_templates();
		if ( ! isset( LNKup_Comms_Audiences::event_groups()[ $group ] ) || ! isset( $templates[ $template ] ) ) {
			return new WP_Error( 'lnkup_comms_preview', __( 'Choose a message type and recipients.', 'lnkup-communications' ), array( 'status' => 400 ) );
		}
		$channels = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $body['channels'] ?? array( 'inbox', 'email', 'push' ) ) ), array( 'inbox', 'email', 'push' ) ) );
		$members  = LNKup_Comms_Audiences::event_group_members( $event_id, $group );
		$preview  = LNKup_Comms_Audiences::preview( array_keys( $members ), 'announcements', 'high' === $templates[ $template ]['priority'], array_unique( array_merge( array( 'inbox' ), $channels ) ) );
		$names    = array();
		foreach ( array_slice( $members, 0, 5, true ) as $app_id ) {
			$profile = (int) get_post_meta( (int) $app_id, '_lnkup_vendor_profile_id', true );
			$names[] = $profile ? (string) get_the_title( $profile ) : '';
		}
		$preview['sample']      = array_values( array_filter( $names ) );
		$preview['rateLimit']   = LNKup_Comms_Campaigns::event_rate_limit( $event_id );
		$preview['allowed']     = ! $templates[ $template ]['groups'] || in_array( $group, $templates[ $template ]['groups'], true );
		$preview['title']       = mb_substr( sanitize_text_field( (string) ( $body['title'] ?? '' ) ), 0, 120 );
		$preview['body']        = mb_substr( sanitize_textarea_field( (string) ( $body['body'] ?? '' ) ), 0, 1000 );
		return rest_ensure_response( $preview );
	}

	public static function send( WP_REST_Request $request ) {
		$result = LNKup_Comms_Campaigns::create_event( absint( $request['id'] ), get_current_user_id(), (array) $request->get_json_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'ok' => true ) + $result );
	}

	public static function history( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$event_id = absint( $request['id'] );
		$rows     = LNKup_Comms_Campaigns::list( 'event', $event_id, 30 );
		$messages = LNKup_Comms_Schema::table( 'messages' );
		$groups   = LNKup_Comms_Audiences::event_groups();
		$out      = array();
		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats  = (array) $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(status IN ('sent','partial')) AS sent, SUM(read_at IS NOT NULL OR opened_at IS NOT NULL) AS opened FROM {$messages} WHERE campaign_id = %d", (int) $row['id'] ), ARRAY_A );
			$author = get_userdata( (int) $row['created_by'] );
			$out[]  = array(
				'id'         => (int) $row['id'],
				'type'       => (string) $row['name'],
				'title'      => (string) ( $row['content']['subject'] ?? '' ),
				'body'       => (string) ( $row['content']['body'] ?? '' ),
				'group'      => (string) ( $groups[ (string) $row['audience_key'] ]['label'] ?? $row['audience_key'] ),
				'status'     => (string) $row['status'],
				'recipients' => (int) $row['total_eligible'],
				'sent'       => (int) ( $stats['sent'] ?? 0 ),
				'opened'     => (int) ( $stats['opened'] ?? 0 ),
				'sentBy'     => $author ? (string) $author->display_name : '',
				'createdAt'  => gmdate( 'c', (int) strtotime( (string) $row['created_at'] . ' UTC' ) ),
			);
		}
		return rest_ensure_response( array( 'items' => $out ) );
	}
}
