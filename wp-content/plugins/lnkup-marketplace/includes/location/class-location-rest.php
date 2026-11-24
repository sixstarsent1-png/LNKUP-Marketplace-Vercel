<?php
/**
 * Location autocomplete and resolution for every client (WordPress JS, Next.js BFF, Expo).
 *
 * GET lnkup/v1/locations/suggest?q=atlanta+ga&limit=8
 * GET lnkup/v1/locations/resolve?city=Atlanta&state=GE&country=US
 *
 * Read-only reference data (bundled Census gazetteer), so both routes are public. They are rate
 * limited per hashed IP and cached in transients; no user data is read or stored.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Location_REST {

	const NS         = 'lnkup/v1';
	const RATE_LIMIT = 240;
	const CACHE_TTL  = DAY_IN_SECONDS;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_field_script' ), 20 );
	}

	/** Autocomplete + validation for any form that opts in with data-lnkup-location-city. */
	public static function enqueue_field_script(): void {
		$path = LNKUP_PLUGIN_DIR . 'public/js/location-field.js';
		wp_enqueue_script( 'lnkup-location-field', LNKUP_PLUGIN_URL . 'public/js/location-field.js', array(), file_exists( $path ) ? LNKUP_VERSION . '.' . filemtime( $path ) : LNKUP_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script( 'lnkup-location-field', 'lnkupLocation', array( 'restUrl' => esc_url_raw( rest_url( self::NS ) ) ) );
		wp_register_style( 'lnkup-location-field', false, array(), LNKUP_VERSION );
		wp_enqueue_style( 'lnkup-location-field' );
		wp_add_inline_style(
			'lnkup-location-field',
			'.lnkup-loc{position:relative}'
			. '.lnkup-loc__list{position:absolute;z-index:50;left:0;right:0;top:100%;margin:4px 0 0;padding:4px;list-style:none;background:#fff;border:1px solid #e3e5de;border-radius:12px;box-shadow:0 10px 30px rgba(11,11,12,.12);max-height:260px;overflow:auto}'
			. '.lnkup-loc__option{padding:8px 10px;border-radius:8px;cursor:pointer;font-size:14px}'
			. '.lnkup-loc__option:hover,.lnkup-loc__option[aria-selected="true"]{background:#eefbc9}'
			. '.lnkup-loc__note{margin:6px 0 0;padding:10px 12px;border-radius:10px;background:#fff8e6;border:1px solid #f1d58a;font-size:14px}'
			. '.lnkup-loc__note p{margin:0 0 6px}'
			. '.lnkup-loc__note button{margin:0 6px 6px 0}'
		);
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/locations/suggest',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'suggest' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'     => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'limit' => array( 'type' => 'integer', 'default' => 8, 'sanitize_callback' => 'absint' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/locations/resolve',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'resolve' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'city'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'state'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'country' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function suggest( WP_REST_Request $request ) {
		if ( self::rate_limited() ) {
			return self::too_many();
		}
		$q     = substr( trim( (string) $request->get_param( 'q' ) ), 0, 80 );
		$limit = max( 1, min( 15, (int) $request->get_param( 'limit' ) ) );
		if ( strlen( $q ) < 2 ) {
			return self::respond( array( 'results' => array() ) );
		}
		$key     = 'lnkup_loc_s_' . md5( strtolower( $q ) . '|' . $limit );
		$results = get_transient( $key );
		if ( ! is_array( $results ) ) {
			$results = LNKup_Location_Service::suggest( $q, $limit );
			set_transient( $key, $results, self::CACHE_TTL );
		}
		return self::respond( array( 'results' => $results ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function resolve( WP_REST_Request $request ) {
		if ( self::rate_limited() ) {
			return self::too_many();
		}
		$city    = substr( (string) $request->get_param( 'city' ), 0, 120 );
		$state   = substr( (string) $request->get_param( 'state' ), 0, 60 );
		$country = substr( (string) $request->get_param( 'country' ), 0, 60 );
		$key     = 'lnkup_loc_r_' . md5( strtolower( $city . '|' . $state . '|' . $country ) );
		$result  = get_transient( $key );
		if ( ! is_array( $result ) ) {
			$result = LNKup_Location_Service::resolve( $city, $state, $country );
			set_transient( $key, $result, self::CACHE_TTL );
		}
		$result['message'] = in_array( $result['status'], array( LNKup_Location_Service::VERIFIED, LNKup_Location_Service::NORMALIZED ), true )
			? ''
			: LNKup_Location_Service::confirmation_message( $result );
		return self::respond( $result );
	}

	/**
	 * @param array<string, mixed> $data Payload.
	 */
	private static function respond( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		return $response;
	}

	private static function too_many(): WP_Error {
		return new WP_Error( 'lnkup_rate_limited', __( 'Too many location lookups. Try again in a minute.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
	}

	private static function rate_limited(): bool {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key  = 'lnkup_loc_rl_' . substr( hash( 'sha256', $ip . wp_salt( 'nonce' ) ), 0, 20 );
		$hits = (int) get_transient( $key );
		if ( $hits >= self::RATE_LIMIT ) {
			return true;
		}
		set_transient( $key, $hits + 1, MINUTE_IN_SECONDS * 5 );
		return false;
	}
}
