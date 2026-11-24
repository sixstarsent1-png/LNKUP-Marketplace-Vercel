<?php
/**
 * Meta first-party browser (_fbp) and click (_fbc) identifiers.
 *
 * The Pixel creates _fbp itself. The server only writes _fbc when a request arrives with
 * fbclid, so ordinary anonymous page views never get a Set-Cookie header (keeps page cache intact).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Meta_Cookies {

	const FBP = '_fbp';
	const FBC = '_fbc';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_set_fbc' ), 7 );
	}

	/**
	 * fb.{subdomain_index}.{creation_time_ms}.{value}
	 *
	 * @param string $value Cookie value.
	 */
	public static function valid_click_cookie( string $value ): bool {
		return strlen( $value ) <= 600 && (bool) preg_match( '/^fb\.\d\.\d{10,14}\.[A-Za-z0-9_\-]+$/', $value );
	}

	public static function sanitize_fbclid( string $fbclid ): string {
		$fbclid = trim( $fbclid );
		return (bool) preg_match( '/^[A-Za-z0-9_\-]{8,500}$/', $fbclid ) ? $fbclid : '';
	}

	/**
	 * Build _fbc from an fbclid.
	 *
	 * @param string $fbclid Click ID.
	 * @param int    $ms     Creation time in milliseconds.
	 */
	public static function build_fbc( string $fbclid, int $ms ): string {
		return 'fb.1.' . $ms . '.' . $fbclid;
	}

	public static function current_fbp(): string {
		$value = isset( $_COOKIE[ self::FBP ] ) ? (string) wp_unslash( $_COOKIE[ self::FBP ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return self::valid_click_cookie( $value ) ? $value : '';
	}

	/**
	 * _fbc cookie, or one derived from fbclid on this request.
	 */
	public static function current_fbc(): string {
		$value = isset( $_COOKIE[ self::FBC ] ) ? (string) wp_unslash( $_COOKIE[ self::FBC ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( self::valid_click_cookie( $value ) ) {
			return $value;
		}
		$fbclid = isset( $_GET['fbclid'] ) ? self::sanitize_fbclid( (string) wp_unslash( $_GET['fbclid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		return '' !== $fbclid ? self::build_fbc( $fbclid, (int) round( microtime( true ) * 1000 ) ) : '';
	}

	/**
	 * Persist _fbc for 90 days when a Meta ad click lands on the site.
	 */
	public static function maybe_set_fbc(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || headers_sent() || empty( $_GET['fbclid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( 'denied' === LNKup_Tracking_Consent::state( get_current_user_id(), true ) ) {
			return;
		}
		$fbclid = self::sanitize_fbclid( (string) wp_unslash( $_GET['fbclid'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		if ( '' === $fbclid ) {
			return;
		}
		$existing = isset( $_COOKIE[ self::FBC ] ) ? (string) wp_unslash( $_COOKIE[ self::FBC ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $existing && substr( $existing, -strlen( $fbclid ) ) === $fbclid ) {
			return;
		}
		$value = self::build_fbc( $fbclid, (int) round( microtime( true ) * 1000 ) );
		setcookie(
			self::FBC,
			$value,
			array(
				'expires'  => time() + 90 * DAY_IN_SECONDS,
				'path'     => '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::FBC ] = $value;
	}

	/**
	 * Client IP for CAPI. REMOTE_ADDR unless the site defines trusted proxy handling via filter.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ip = (string) apply_filters( 'lnkup_meta_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	public static function user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Request context captured at the moment of the action (dispatch runs later without a request).
	 *
	 * @return array<string,string>
	 */
	public static function request_context(): array {
		return array_filter(
			array(
				'ip'  => self::client_ip(),
				'ua'  => self::user_agent(),
				'fbp' => self::current_fbp(),
				'fbc' => self::current_fbc(),
			)
		);
	}
}
