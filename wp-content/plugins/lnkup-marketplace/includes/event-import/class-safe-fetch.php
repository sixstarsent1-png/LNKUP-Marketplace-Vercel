<?php
/**
 * Outbound fetches for organizer-supplied URLs (event import), hardened against SSRF.
 *
 * - http/https only, default ports only, no credentials in the URL.
 * - Every A/AAAA record must be a public address (no loopback, private, link-local, CGNAT,
 *   reserved, multicast, IPv6 ULA/link-local, IPv4-mapped private, NAT64-embedded private).
 * - The validated address is pinned for the connection (CURLOPT_RESOLVE), so DNS rebinding
 *   between the check and the request cannot reach an internal host.
 * - Redirects are followed manually (max 3) and every hop is validated again.
 * - Bounded timeout, response size, content types and per-user request rate.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Safe_Fetch {

	const MAX_BYTES     = 2097152;
	const TIMEOUT       = 8;
	const MAX_REDIRECTS = 3;
	const USER_AGENT    = 'LNKupEventImport/1.0 (+https://thelnkup.com/organizers)';
	const RATE_PER_HOUR = 60;
	const BLOCKED_HOSTS = array( 'localhost', 'metadata.google.internal', 'metadata', 'instance-data' );

	/** @var array{host:string,port:int,ip:string}|null */
	private static ?array $pin = null;

	/**
	 * Validate a URL and return its resolved public addresses.
	 *
	 * @return array{url:string,host:string,port:int,ips:string[]}|WP_Error
	 */
	public static function validate( string $url ) {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return self::error( 'lnkup_fetch_url', __( 'Paste a full link that starts with https://', 'lnkup-marketplace' ) );
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return self::error( 'lnkup_fetch_scheme', __( 'Only web links (https://) can be imported.', 'lnkup-marketplace' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return self::error( 'lnkup_fetch_url', __( 'Links with a username or password cannot be imported.', 'lnkup-marketplace' ) );
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		if ( ! in_array( $port, array( 80, 443 ), true ) ) {
			return self::error( 'lnkup_fetch_port', __( 'This link uses a port that cannot be imported.', 'lnkup-marketplace' ) );
		}
		$host = strtolower( rtrim( trim( (string) $parts['host'], '[]' ), '.' ) );
		if ( '' === $host || in_array( $host, self::BLOCKED_HOSTS, true ) || preg_match( '/\.(local|localhost|internal|lan|home|corp|intranet)$/', $host ) ) {
			return self::error( 'lnkup_fetch_host', __( 'This address cannot be imported.', 'lnkup-marketplace' ) );
		}
		$ips = self::resolve( $host );
		if ( ! $ips ) {
			return self::error( 'lnkup_fetch_dns', __( 'We could not find that website. Check the link.', 'lnkup-marketplace' ) );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return self::error( 'lnkup_fetch_private', __( 'This address cannot be imported.', 'lnkup-marketplace' ) );
			}
		}
		return array( 'url' => $url, 'host' => $host, 'port' => $port, 'ips' => $ips );
	}

	/**
	 * @return string[]
	 */
	public static function resolve( string $host ): array {
		$override = apply_filters( 'lnkup_safe_fetch_resolve', null, $host );
		if ( is_array( $override ) ) {
			return array_values( array_filter( array_map( 'strval', $override ) ) );
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}
		$ips = array();
		$v4  = gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			foreach ( is_array( $aaaa ) ? $aaaa : array() as $rec ) {
				if ( ! empty( $rec['ipv6'] ) ) {
					$ips[] = (string) $rec['ipv6'];
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}

	public static function is_public_ip( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = inet_pton( $ip );
			if ( false === $bin ) {
				return false;
			}
			// IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::/96) carry an IPv4 address.
			if ( 0 === strncmp( $bin, str_repeat( "\0", 10 ) . "\xff\xff", 12 ) || 0 === strncmp( $bin, "\x00\x64\xff\x9b" . str_repeat( "\0", 8 ), 12 ) ) {
				return self::is_public_ip( (string) inet_ntop( substr( $bin, 12 ) ) );
			}
			$first = ord( $bin[0] );
			if ( '::' === $ip || '::1' === $ip || ( $first & 0xfe ) === 0xfc || ( 0xfe === $first && ( ord( $bin[1] ) & 0xc0 ) === 0x80 ) || 0xff === $first ) {
				return false; // Unspecified, loopback, ULA fc00::/7, link-local fe80::/10, multicast.
			}
			return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		$long = ip2long( $ip );
		$deny = array( array( '100.64.0.0', 10 ), array( '192.0.0.0', 24 ), array( '192.0.2.0', 24 ), array( '198.18.0.0', 15 ), array( '198.51.100.0', 24 ), array( '203.0.113.0', 24 ), array( '224.0.0.0', 4 ), array( '169.254.0.0', 16 ), array( '127.0.0.0', 8 ), array( '0.0.0.0', 8 ) );
		foreach ( $deny as $range ) {
			$mask = -1 << ( 32 - $range[1] );
			if ( ( $long & $mask ) === ( ip2long( $range[0] ) & $mask ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Fetch a URL safely.
	 *
	 * @param string[] $types Allowed content-type fragments (e.g. html, json, calendar, csv).
	 * @return array{url:string,status:int,content_type:string,body:string,headers:array<string,string>}|WP_Error
	 */
	public static function get( string $url, array $types = array( 'html' ), array $args = array() ) {
		if ( self::rate_limited() ) {
			return self::error( 'lnkup_fetch_rate', __( 'Too many imports in a short time. Try again in a few minutes.', 'lnkup-marketplace' ), 429 );
		}
		$current = $url;
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$check = self::validate( $current );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			self::$pin = array( 'host' => $check['host'], 'port' => $check['port'], 'ip' => $check['ips'][0] );
			add_action( 'http_api_curl', array( __CLASS__, 'pin_curl' ), 10, 3 );
			$response = wp_safe_remote_get(
				$current,
				array(
					'timeout'             => self::TIMEOUT,
					'redirection'         => 0,
					'user-agent'          => self::USER_AGENT,
					'limit_response_size' => self::MAX_BYTES,
					'reject_unsafe_urls'  => true,
					'headers'             => array_merge( array( 'Accept' => 'text/html,application/xhtml+xml,application/json,text/calendar,text/csv;q=0.9,*/*;q=0.5' ), (array) ( $args['headers'] ?? array() ) ),
				)
			);
			remove_action( 'http_api_curl', array( __CLASS__, 'pin_curl' ), 10 );
			self::$pin = null;
			if ( is_wp_error( $response ) ) {
				return self::error( 'lnkup_fetch_failed', __( 'We could not reach that page. It may be private or temporarily down.', 'lnkup-marketplace' ), 502 );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 300 && $code < 400 ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				if ( '' === $location ) {
					return self::error( 'lnkup_fetch_failed', __( 'That page redirected without a destination.', 'lnkup-marketplace' ), 502 );
				}
				$current = self::absolute( $location, $current );
				continue;
			}
			if ( $code < 200 || $code >= 300 ) {
				return self::error( 'lnkup_fetch_status', 404 === $code || 410 === $code ? __( 'That page was not found. Check the link.', 'lnkup-marketplace' ) : __( 'That page did not load. It may be private, removed, or blocking imports.', 'lnkup-marketplace' ), 404 === $code ? 404 : 502 );
			}
			$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
			$ok   = false;
			foreach ( $types as $t ) {
				$ok = $ok || false !== strpos( $type, $t );
			}
			if ( '' !== $type && ! $ok ) {
				return self::error( 'lnkup_fetch_type', __( 'That link is not a web page or supported file.', 'lnkup-marketplace' ), 415 );
			}
			$body = (string) wp_remote_retrieve_body( $response );
			return array( 'url' => $current, 'status' => $code, 'content_type' => $type, 'body' => substr( $body, 0, self::MAX_BYTES ), 'headers' => array() );
		}
		return self::error( 'lnkup_fetch_redirects', __( 'That link redirects too many times.', 'lnkup-marketplace' ), 502 );
	}

	/**
	 * Pin the validated address for the outgoing connection.
	 *
	 * @param resource|CurlHandle $handle cURL handle.
	 */
	public static function pin_curl( $handle, $r, $url ): void {
		unset( $r, $url );
		if ( self::$pin && defined( 'CURLOPT_RESOLVE' ) ) {
			$ip = false !== strpos( self::$pin['ip'], ':' ) ? '[' . self::$pin['ip'] . ']' : self::$pin['ip'];
			curl_setopt( $handle, CURLOPT_RESOLVE, array( self::$pin['host'] . ':' . self::$pin['port'] . ':' . $ip ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		}
		if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTP' ) && defined( 'CURLPROTO_HTTPS' ) ) {
			curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		}
	}

	public static function absolute( string $location, string $base ): string {
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}
		$b = wp_parse_url( $base );
		if ( 0 === strpos( $location, '//' ) ) {
			return ( $b['scheme'] ?? 'https' ) . ':' . $location;
		}
		$origin = ( $b['scheme'] ?? 'https' ) . '://' . ( $b['host'] ?? '' ) . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
		if ( 0 === strpos( $location, '/' ) ) {
			return $origin . $location;
		}
		$dir = isset( $b['path'] ) ? preg_replace( '#/[^/]*$#', '/', (string) $b['path'] ) : '/';
		return $origin . $dir . $location;
	}

	private static function rate_limited(): bool {
		$user = get_current_user_id();
		if ( ! $user ) {
			return false; // Background jobs run as the initiating user via wp_set_current_user().
		}
		$key  = 'lnkup_safe_fetch_' . $user;
		$hits = (int) get_transient( $key );
		if ( $hits >= (int) apply_filters( 'lnkup_safe_fetch_rate', self::RATE_PER_HOUR ) ) {
			return true;
		}
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
		return false;
	}

	private static function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
