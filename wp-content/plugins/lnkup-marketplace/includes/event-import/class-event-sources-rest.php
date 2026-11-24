<?php
/**
 * REST: an event's official listings (External Sources panel) and the Eventbrite webhook receiver.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Sources_REST {

	const NS            = 'lnkup/v1';
	const ONE_HOOK      = 'lnkup_event_source_sync_one';
	const WEBHOOKS      = 'lnkup_eventbrite_webhooks';
	const CHECK_COOLOFF = 120;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( self::ONE_HOOK, array( __CLASS__, 'run_one' ) );
	}

	public static function routes(): void {
		$base = '/organizer/events/(?P<id>\d+)/sources';
		$one  = $base . '/(?P<sid>\d+)';
		register_rest_route( self::NS, $base, array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_sources' ), 'permission_callback' => array( __CLASS__, 'can_event' ) ) );
		register_rest_route( self::NS, $one, array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'remove' ), 'permission_callback' => array( __CLASS__, 'can_source' ) ) );
		foreach ( array( 'primary', 'check', 'apply', 'dismiss' ) as $action ) {
			register_rest_route( self::NS, $one . '/' . $action, array( 'methods' => 'POST', 'callback' => array( __CLASS__, $action ), 'permission_callback' => array( __CLASS__, 'can_source' ) ) );
		}
		register_rest_route( self::NS, '/webhooks/eventbrite', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'eventbrite_webhook' ), 'permission_callback' => '__return_true' ) );
	}

	public static function can_event( WP_REST_Request $request ): bool {
		return LNKup_Event_Builder_REST::can_use() && LNKup_Event_Builder_Editor::can_edit_event( (int) $request['id'], get_current_user_id() );
	}

	public static function can_source( WP_REST_Request $request ): bool {
		if ( ! self::can_event( $request ) ) {
			return false;
		}
		$src = LNKup_Event_Sources::get( (int) $request['sid'] );
		return $src && (int) $src['event_id'] === (int) $request['id'];
	}

	public static function list_sources( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			array(
				'sources'      => LNKup_Event_Sources::for_event( (int) $request['id'] ),
				'sync_enabled' => LNKup_Event_Source_Sync::enabled(),
			)
		);
	}

	public static function remove( WP_REST_Request $request ): WP_REST_Response {
		LNKup_Event_Sources::remove( (int) $request['sid'] );
		return self::list_sources( $request );
	}

	public static function primary( WP_REST_Request $request ): WP_REST_Response {
		LNKup_Event_Sources::set_primary( (int) $request['id'], (int) $request['sid'] );
		return self::list_sources( $request );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function check( WP_REST_Request $request ) {
		if ( ! LNKup_Event_Source_Sync::enabled() ) {
			return new WP_Error( 'lnkup_sync_off', __( 'Listing updates are not turned on yet.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$src  = LNKup_Event_Sources::get( (int) $request['sid'] );
		$last = (int) get_transient( 'lnkup_source_check_' . (int) $request['sid'] );
		if ( $last && time() - $last < self::CHECK_COOLOFF ) {
			return new WP_Error( 'lnkup_sync_rate', __( 'This listing was just checked. Try again in a couple of minutes.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		if ( ! in_array( $src['mode'], array( 'import', 'account' ), true ) ) {
			return new WP_Error( 'lnkup_sync_link', __( 'This listing is linked only. Re-import it to check for updates.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		set_transient( 'lnkup_source_check_' . (int) $request['sid'], time(), self::CHECK_COOLOFF );
		$out = LNKup_Event_Source_Sync::check( (int) $request['sid'] );
		return is_wp_error( $out ) ? $out : rest_ensure_response( array( 'source' => $out ) );
	}

	/**
	 * Body: { fields: string[], confirm?: bool }.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function apply( WP_REST_Request $request ) {
		$body   = $request->get_json_params();
		$fields = array_values( array_intersect( LNKup_Event_Source_Sync::FIELDS, array_map( 'sanitize_key', (array) ( $body['fields'] ?? array() ) ) ) );
		$out    = LNKup_Event_Source_Sync::apply( (int) $request['sid'], $fields, get_current_user_id(), ! empty( $body['confirm'] ) );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		return rest_ensure_response( array_merge( $out, array( 'source' => LNKup_Event_Sources::get( (int) $request['sid'] ) ) ) );
	}

	public static function dismiss( WP_REST_Request $request ): WP_REST_Response {
		LNKup_Event_Source_Sync::dismiss( (int) $request['sid'] );
		return rest_ensure_response( array( 'source' => LNKup_Event_Sources::get( (int) $request['sid'] ) ) );
	}

	/**
	 * Eventbrite webhook: the payload is only a hint. It must reference a webhook LNKup registered
	 * and an event on the Eventbrite API host; the event is then re-read with the organizer's own
	 * token in the background. Payload data is never trusted or fetched directly.
	 */
	public static function eventbrite_webhook( WP_REST_Request $request ): WP_REST_Response {
		$body    = (array) $request->get_json_params();
		$hook_id = sanitize_text_field( (string) ( $body['config']['webhook_id'] ?? '' ) );
		$api_url = (string) ( $body['api_url'] ?? '' );
		$hooks   = (array) get_option( self::WEBHOOKS, array() );
		if ( '' === $hook_id || ! isset( $hooks[ $hook_id ] ) || ! LNKup_Event_Source_Sync::enabled() ) {
			return new WP_REST_Response( array( 'queued' => 0 ), 202 );
		}
		if ( ! preg_match( '#^https://www\.eventbriteapi\.com/v3/events/(\d+)/?$#', $api_url, $m ) ) {
			return new WP_REST_Response( array( 'queued' => 0 ), 202 );
		}
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Event_Import_Tables::sources() . " WHERE provider = 'eventbrite' AND external_event_id = %s AND organizer_id = %d AND event_id > 0", $m[1], (int) $hooks[ $hook_id ] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $ids as $id ) {
			if ( ! wp_next_scheduled( self::ONE_HOOK, array( (int) $id ) ) ) {
				wp_schedule_single_event( time() + 60, self::ONE_HOOK, array( (int) $id ) );
			}
		}
		return new WP_REST_Response( array( 'queued' => count( (array) $ids ) ), 202 );
	}

	public static function run_one( $source_id ): void {
		if ( LNKup_Event_Source_Sync::enabled() ) {
			LNKup_Event_Source_Sync::check( (int) $source_id );
		}
	}
}
