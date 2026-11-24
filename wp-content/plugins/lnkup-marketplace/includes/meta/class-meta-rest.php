<?php
/**
 * Public measurement endpoints used by lnkup-meta-pixel.js.
 *
 * POST /meta/event                Server twin of a browser ViewContent / Search / RegistrationStarted.
 * GET  /meta/pending              Signed-in user's server conversions not yet fired in the browser.
 * POST /meta/conflict             Browser saw a second Meta Pixel on the page.
 * GET|POST /meta/tracking-preference  Signed-in user's advertising opt-out.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Meta_REST {

	const NS              = 'lnkup/v1';
	const CLIENT_EVENTS   = array( 'ViewContent', 'Search', 'OrganizerRegistrationStarted', 'VendorRegistrationStarted' );
	const RATE_PER_MINUTE = 60;
	const CONTENT_TYPES   = array( 'lnkup_event', 'lnkup_opportunity', 'lnkup_vendor_profile' );
	const CONFLICT_OPTION = 'lnkup_meta_third_party_pixel_seen';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/meta/event',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'client_event' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/meta/pending',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'pending' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
		register_rest_route(
			self::NS,
			'/meta/conflict',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'conflict' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/meta/tracking-preference',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_preference' ),
					'permission_callback' => 'is_user_logged_in',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'set_preference' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => array( 'opt_out' => array( 'type' => 'boolean', 'required' => true ) ),
				),
			)
		);
	}

	private static function within_rate( string $bucket, int $limit ): bool {
		$who   = get_current_user_id() ? 'u' . get_current_user_id() : 'ip' . md5( LNKup_Meta_Cookies::client_ip() . wp_salt( 'nonce' ) );
		$key   = 'lnkup_meta_rate_' . substr( md5( $bucket . $who . gmdate( 'YmdHi' ) ), 0, 20 );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Drop search terms that look like personal data (emails, phone numbers, long digit runs).
	 *
	 * @param string $term Raw term.
	 */
	public static function sanitize_search( string $term ): string {
		$term = trim( substr( sanitize_text_field( $term ), 0, 100 ) );
		if ( '' === $term || false !== strpos( $term, '@' ) || preg_match( '/\d[\d\s().-]{6,}\d/', $term ) ) {
			return '';
		}
		return $term;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function client_event( WP_REST_Request $request ) {
		$name     = (string) $request->get_param( 'event_name' );
		$event_id = strtolower( (string) $request->get_param( 'event_id' ) );
		if ( ! in_array( $name, self::CLIENT_EVENTS, true ) || ! LNKup_Conversion_Registry::is_uuid( $event_id ) ) {
			return new WP_Error( 'lnkup_meta_invalid_event', __( 'Unsupported event.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! self::within_rate( 'event', self::RATE_PER_MINUTE ) ) {
			return new WP_Error( 'lnkup_rate_limited', __( 'Too many events. Try again shortly.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		if ( '' !== LNKup_Meta_Config::capi_block_reason() ) {
			return rest_ensure_response( array( 'recorded' => false, 'reason' => 'server_events_off' ) );
		}

		$raw    = $request->get_param( 'custom_data' );
		$raw    = is_array( $raw ) ? $raw : array();
		$custom = array();
		if ( 'ViewContent' === $name ) {
			$ids = array();
			foreach ( array_slice( (array) ( $raw['content_ids'] ?? array() ), 0, 5 ) as $id ) {
				$id = absint( $id );
				if ( $id && 'publish' === get_post_status( $id ) && in_array( get_post_type( $id ), self::CONTENT_TYPES, true ) ) {
					$ids[] = $id;
				}
			}
			$custom = array(
				'content_ids'      => $ids,
				'content_type'     => array() !== $ids ? 'product' : '',
				'content_name'     => $ids ? wp_strip_all_tags( (string) get_the_title( $ids[0] ) ) : sanitize_title( (string) ( $raw['content_name'] ?? '' ) ),
				'content_category' => in_array( (string) ( $raw['content_category'] ?? '' ), array( 'event', 'opportunity', 'market', 'tour', 'vendor_profile' ), true ) ? (string) $raw['content_category'] : '',
			);
		} elseif ( 'Search' === $name ) {
			$term = self::sanitize_search( (string) ( $raw['search_string'] ?? '' ) );
			if ( '' === $term ) {
				return rest_ensure_response( array( 'recorded' => false, 'reason' => 'search_omitted' ) );
			}
			$custom = array( 'search_string' => $term, 'content_category' => 'vendor_opportunities' );
		} else {
			$custom = array( 'marketplace_role' => 'OrganizerRegistrationStarted' === $name ? 'organizer' : 'vendor' );
		}

		$row = LNKup_Conversion_Registry::record(
			$name,
			array(
				'user_id'         => get_current_user_id(),
				'event_id'        => $event_id,
				'custom_data'     => $custom,
				'source_url'      => (string) $request->get_param( 'source_url' ),
				'capture_request' => true,
				'browser_fired'   => true,
			)
		);
		return rest_ensure_response( array( 'recorded' => null !== $row ) );
	}

	public static function pending(): WP_REST_Response {
		$user_id = get_current_user_id();
		$events  = LNKup_Meta_Config::pixel_active() && 'granted' === LNKup_Tracking_Consent::state( $user_id, true )
			? LNKup_Conversion_Registry::consume_browser_events( $user_id )
			: array();
		$out = array();
		foreach ( $events as $event ) {
			$out[] = array(
				'name'     => (string) $event['name'],
				'eventId'  => (string) $event['eventId'],
				'data'     => (array) $event['data'],
				'standard' => ! empty( $event['standard'] ),
			);
		}
		$response = rest_ensure_response( array( 'events' => $out ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function conflict( WP_REST_Request $request ): WP_REST_Response {
		if ( self::within_rate( 'conflict', 5 ) && ! get_transient( 'lnkup_meta_conflict_recent' ) ) {
			set_transient( 'lnkup_meta_conflict_recent', 1, HOUR_IN_SECONDS );
			$reason = sanitize_key( (string) $request->get_param( 'reason' ) );
			update_option(
				self::CONFLICT_OPTION,
				array(
					'at'     => time(),
					'reason' => in_array( $reason, array( 'fbq_predefined', 'multiple_fbevents' ), true ) ? $reason : 'unknown',
					'url'    => LNKup_Conversion_Registry::clean_source_url( wp_get_referer() ? (string) wp_get_referer() : '' ),
				),
				false
			);
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function get_preference(): WP_REST_Response {
		return rest_ensure_response( array( 'opt_out' => LNKup_Tracking_Consent::user_opted_out( get_current_user_id() ) ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function set_preference( WP_REST_Request $request ): WP_REST_Response {
		LNKup_Tracking_Consent::set_preference( (bool) $request->get_param( 'opt_out' ) );
		return rest_ensure_response( array( 'opt_out' => LNKup_Tracking_Consent::user_opted_out( get_current_user_id() ) ) );
	}
}
