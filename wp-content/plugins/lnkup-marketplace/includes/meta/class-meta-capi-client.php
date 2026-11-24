<?php
/**
 * Graph API client for the Conversions API and read-only diagnostics.
 *
 * Tokens travel only in the POST body (events) or the query string of server-to-server GETs,
 * and every error message passes through LNKup_Meta_Redactor before it is stored or shown.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Meta_Capi_Client {

	const TIMEOUT = 10;

	/** Graph error codes that mean "slow down" (rate limits / throttling). */
	const THROTTLE_CODES = array( 4, 17, 32, 613, 80004 );
	/** Graph error codes that mean the credential is invalid or lacks permission. */
	const AUTH_CODES = array( 102, 190, 10, 200, 3 );

	/**
	 * POST /{dataset_id}/events.
	 *
	 * @param array<int,array<string,mixed>> $events  Server events.
	 * @param string                         $test_code Test event code ('' for production).
	 * @return array<string,mixed> ok, http, events_received, fbtrace_id, error_code, error_message, retryable, auth_error, retry_after
	 */
	public static function send_events( array $events, string $test_code = '' ): array {
		$token   = LNKup_Meta_Config::capi_token();
		$dataset = LNKup_Meta_Config::dataset_id();
		if ( '' === $token || '' === $dataset ) {
			return self::failure( 0, 'not_configured', 'Conversions API token or dataset is not configured.', false, true );
		}
		$body = array(
			'data'          => wp_json_encode( array_values( $events ) ),
			'access_token'  => $token,
			'partner_agent' => 'lnkup_marketplace_' . ( defined( 'LNKUP_VERSION' ) ? LNKUP_VERSION : '1' ),
		);
		if ( '' !== $test_code ) {
			$body['test_event_code'] = $test_code;
		}
		$response = wp_remote_post(
			LNKup_Meta_Config::graph_base() . '/' . rawurlencode( $dataset ) . '/events',
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'body'        => $body,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
		return self::parse( $response );
	}

	/**
	 * Read-only GET against the Graph API.
	 *
	 * @param string              $path  Path after the version (e.g. "dataset_quality").
	 * @param array<string,mixed> $query Query args (without access_token).
	 * @param string              $token Token.
	 * @return array<string,mixed> Parsed result plus 'body' (decoded JSON) on success.
	 */
	public static function get( string $path, array $query, string $token ): array {
		if ( '' === $token ) {
			return self::failure( 0, 'not_configured', 'Access token is not configured.', false, true );
		}
		$url      = add_query_arg( array_map( 'rawurlencode', array_merge( $query, array( 'access_token' => $token ) ) ), LNKup_Meta_Config::graph_base() . '/' . ltrim( $path, '/' ) );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
		return self::parse( $response );
	}

	/**
	 * @param array|WP_Error $response wp_remote_* response.
	 * @return array<string,mixed>
	 */
	private static function parse( $response ): array {
		if ( is_wp_error( $response ) ) {
			return self::failure( 0, 'network_error', LNKup_Meta_Redactor::redact( $response->get_error_message() ), true, false );
		}
		$http = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );
		$json = is_array( $json ) ? $json : array();

		if ( $http >= 200 && $http < 300 && ! isset( $json['error'] ) ) {
			return array(
				'ok'              => true,
				'http'            => $http,
				'events_received' => (int) ( $json['events_received'] ?? 0 ),
				'fbtrace_id'      => substr( (string) ( $json['fbtrace_id'] ?? '' ), 0, 64 ),
				'error_code'      => '',
				'error_message'   => '',
				'retryable'       => false,
				'auth_error'      => false,
				'retry_after'     => 0,
				'body'            => $json,
			);
		}

		$error   = is_array( $json['error'] ?? null ) ? $json['error'] : array();
		$code    = (int) ( $error['code'] ?? 0 );
		$sub     = (int) ( $error['error_subcode'] ?? 0 );
		$message = (string) ( $error['error_user_msg'] ?? $error['message'] ?? ( '' !== $raw ? substr( $raw, 0, 300 ) : 'HTTP ' . $http ) );
		$throttled = 429 === $http || in_array( $code, self::THROTTLE_CODES, true );
		$auth      = in_array( $code, self::AUTH_CODES, true ) || 401 === $http || 403 === $http;
		$retryable = $throttled || $auth || $http >= 500 || 0 === $http;
		$result    = self::failure( $http, $code ? (string) $code . ( $sub ? '.' . $sub : '' ) : 'http_' . $http, LNKup_Meta_Redactor::redact( $message ), $retryable, $auth );
		$result['throttled']   = $throttled;
		$result['retry_after'] = $throttled ? max( 900, (int) wp_remote_retrieve_header( $response, 'retry-after' ) ) : 0;
		$result['fbtrace_id']  = substr( (string) ( $error['fbtrace_id'] ?? '' ), 0, 64 );
		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function failure( int $http, string $code, string $message, bool $retryable, bool $auth ): array {
		return array(
			'ok'              => false,
			'http'            => $http,
			'events_received' => 0,
			'fbtrace_id'      => '',
			'error_code'      => substr( $code, 0, 64 ),
			'error_message'   => substr( $message, 0, 500 ),
			'retryable'       => $retryable,
			'auth_error'      => $auth,
			'throttled'       => false,
			'retry_after'     => 0,
			'body'            => array(),
		);
	}
}
