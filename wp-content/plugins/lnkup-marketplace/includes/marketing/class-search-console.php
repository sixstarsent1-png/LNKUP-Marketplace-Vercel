<?php
/**
 * Google Search Console (read-only) for the Marketing Command Center.
 *
 * Credentials live in wp-config.php only, never in the database:
 *   define( 'LNKUP_GSC_SERVICE_ACCOUNT', '/path/outside/webroot/service-account.json' ); // or the JSON itself
 *   define( 'LNKUP_GSC_PROPERTY', 'sc-domain:thelnkup.com' );
 * The service account needs read access to the property. Without both constants the command
 * center shows "Not connected"; numbers are never estimated or simulated.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Search_Console {

	const SCOPE     = 'https://www.googleapis.com/auth/webmasters.readonly';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * @return array{client_email:string,private_key:string}|null
	 */
	private static function credentials(): ?array {
		if ( ! defined( 'LNKUP_GSC_SERVICE_ACCOUNT' ) ) {
			return null;
		}
		$raw = (string) constant( 'LNKUP_GSC_SERVICE_ACCOUNT' );
		if ( '' !== $raw && '{' !== $raw[0] && is_readable( $raw ) ) {
			$raw = (string) file_get_contents( $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) || empty( $json['client_email'] ) || empty( $json['private_key'] ) ) {
			return null;
		}
		return array( 'client_email' => (string) $json['client_email'], 'private_key' => (string) $json['private_key'] );
	}

	public static function property(): string {
		return defined( 'LNKUP_GSC_PROPERTY' ) ? trim( (string) constant( 'LNKUP_GSC_PROPERTY' ) ) : '';
	}

	public static function connected(): bool {
		return null !== self::credentials() && '' !== self::property() && function_exists( 'openssl_sign' );
	}

	/**
	 * Status without secrets.
	 *
	 * @return array{connected:bool,property:string,reason:string}
	 */
	public static function status(): array {
		$reason = '';
		if ( null === self::credentials() ) {
			$reason = 'LNKUP_GSC_SERVICE_ACCOUNT is not set or not readable.';
		} elseif ( '' === self::property() ) {
			$reason = 'LNKUP_GSC_PROPERTY is not set.';
		} elseif ( ! function_exists( 'openssl_sign' ) ) {
			$reason = 'The PHP OpenSSL extension is not available.';
		}
		return array( 'connected' => '' === $reason, 'property' => self::property(), 'reason' => $reason );
	}

	private static function b64( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @return string|WP_Error Access token.
	 */
	private static function token() {
		$cached = get_transient( 'lnkup_gsc_token' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$creds = self::credentials();
		if ( ! $creds ) {
			return new WP_Error( 'lnkup_gsc_not_connected', 'Search Console is not connected.' );
		}
		$now    = time();
		$header = self::b64( (string) wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::b64( (string) wp_json_encode( array( 'iss' => $creds['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600 ) ) );
		$sig    = '';
		if ( ! openssl_sign( $header . '.' . $claims, $sig, $creds['private_key'], 'sha256WithRSAEncryption' ) ) {
			return new WP_Error( 'lnkup_gsc_key', 'The service account key could not be used.' );
		}
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array( 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $header . '.' . $claims . '.' . self::b64( $sig ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$token = is_array( $body ) ? (string) ( $body['access_token'] ?? '' ) : '';
		if ( '' === $token ) {
			return new WP_Error( 'lnkup_gsc_auth', 'Google rejected the service account (check property access).' );
		}
		set_transient( 'lnkup_gsc_token', $token, 50 * MINUTE_IN_SECONDS );
		return $token;
	}

	/**
	 * Clicks, impressions, CTR and position by page or query, from Google.
	 *
	 * @param string $dimension page|query.
	 * @return array{rows:array<int,array<string,mixed>>,totals:array<string,float|int>,fetched_at:string}|WP_Error
	 */
	public static function performance( string $from, string $to, string $dimension = 'page', int $limit = 25, bool $refresh = false ) {
		if ( ! self::connected() ) {
			return new WP_Error( 'lnkup_gsc_not_connected', 'Search Console is not connected.' );
		}
		$dimension = 'query' === $dimension ? 'query' : 'page';
		$key       = 'lnkup_gsc_' . md5( $from . $to . $dimension . $limit . self::property() );
		$cached    = $refresh ? false : get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = wp_remote_post(
			'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( self::property() ) . '/searchAnalytics/query',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( array( 'startDate' => $from, 'endDate' => $to, 'dimensions' => array( $dimension ), 'rowLimit' => max( 1, min( 250, $limit ) ) ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			return new WP_Error( 'lnkup_gsc_query', sprintf( 'Search Console returned HTTP %d.', $code ) );
		}
		$rows   = array();
		$totals = array( 'clicks' => 0, 'impressions' => 0 );
		foreach ( (array) ( $body['rows'] ?? array() ) as $row ) {
			$rows[]                 = array(
				'key'         => (string) ( $row['keys'][0] ?? '' ),
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'ctr'         => round( (float) ( $row['ctr'] ?? 0 ) * 100, 2 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
			);
			$totals['clicks']      += (int) ( $row['clicks'] ?? 0 );
			$totals['impressions'] += (int) ( $row['impressions'] ?? 0 );
		}
		$data = array( 'rows' => $rows, 'totals' => $totals, 'fetched_at' => gmdate( 'c' ) );
		set_transient( $key, $data, self::CACHE_TTL );
		return $data;
	}
}
