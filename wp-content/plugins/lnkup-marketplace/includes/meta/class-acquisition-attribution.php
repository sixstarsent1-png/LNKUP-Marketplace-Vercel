<?php
/**
 * First-party acquisition attribution (paid and organic campaign touches).
 *
 * Two first-party cookies, 90 days each:
 * - lnkup_ft: first touch, written once.
 * - lnkup_lt: last non-direct touch, replaced only by a visit with campaign parameters or an
 *   external referrer, so direct return visits never overwrite known attribution.
 *
 * Both are written by lnkup-attribution.js (works on cached pages) and by the server on
 * uncached requests. On registration they are copied to user meta. Conversions snapshot the
 * user's attribution so registrations, applications, checkouts and bookings carry the campaign.
 *
 * This is measurement only. Referral and City Connector commissions keep their own validated
 * cookies and LNKup_Attribution_Arbiter; nothing here grants credit or rewards.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Acquisition_Attribution {

	const FIRST_COOKIE = 'lnkup_ft';
	const LAST_COOKIE  = 'lnkup_lt';
	const META_FIRST   = '_lnkup_acq_first_touch';
	const META_LAST    = '_lnkup_acq_last_touch';
	const META_REG     = '_lnkup_acq_registration';
	const TTL          = 90 * DAY_IN_SECONDS;
	const RETENTION_MONTHS = 24;

	const PARAMS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'campaign_id', 'adset_id', 'ad_id', 'placement', 'fbclid' );

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'capture' ), 8 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 1 );
		add_action( 'lnkup_user_registered', array( __CLASS__, 'on_registered' ), 5, 3 );
	}

	/**
	 * Sanitize one touch (from a cookie, the URL or the app). Unknown keys are dropped.
	 *
	 * @param mixed $raw Raw touch.
	 * @return array<string,mixed>
	 */
	public static function sanitize_touch( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( self::PARAMS as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
				continue;
			}
			$value = trim( (string) $raw[ $key ] );
			if ( 'fbclid' === $key ) {
				$value = LNKup_Meta_Cookies::sanitize_fbclid( $value );
			} elseif ( in_array( $key, array( 'campaign_id', 'adset_id', 'ad_id' ), true ) ) {
				$value = (string) preg_replace( '/[^0-9A-Za-z_{}.-]/', '', $value );
				$value = substr( $value, 0, 40 );
			} else {
				$value = substr( sanitize_text_field( $value ), 0, 150 );
				// URL-shaped values are not campaign labels; never echo them back anywhere.
				if ( preg_match( '#^[a-z]+://#i', $value ) ) {
					$value = '';
				}
			}
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}
		if ( isset( $raw['landing'] ) && is_scalar( $raw['landing'] ) ) {
			$path = (string) wp_parse_url( '/' . ltrim( (string) $raw['landing'], '/' ), PHP_URL_PATH );
			$out['landing'] = substr( sanitize_text_field( $path ), 0, 200 );
		}
		if ( isset( $raw['referrer'] ) && is_scalar( $raw['referrer'] ) ) {
			$host = strtolower( (string) preg_replace( '/[^a-z0-9.-]/i', '', (string) $raw['referrer'] ) );
			if ( '' !== $host ) {
				$out['referrer'] = substr( $host, 0, 100 );
			}
		}
		if ( isset( $raw['ts'] ) && is_numeric( $raw['ts'] ) ) {
			$ts = (int) $raw['ts'];
			if ( $ts > 1600000000 && $ts <= time() + 300 ) {
				$out['ts'] = $ts;
			}
		}
		return $out;
	}

	/** Referrers that are part of an LNKup flow (payments, sign-in), not acquisition sources. */
	const IGNORED_REFERRERS = array( 'stripe.com', 'stripe.network', 'paypal.com', 'accounts.google.com', 'appleid.apple.com' );

	/**
	 * Own site (with or without www) or a payment / sign-in provider.
	 *
	 * @param string $host Referrer host.
	 */
	public static function is_internal_referrer( string $host ): bool {
		$host = (string) preg_replace( '/^www\./', '', strtolower( $host ) );
		$own  = (string) preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		if ( $host === $own ) {
			return true;
		}
		foreach ( self::IGNORED_REFERRERS as $ignored ) {
			if ( $host === $ignored || substr( $host, -strlen( '.' . $ignored ) ) === '.' . $ignored ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a touch carries campaign information (not a plain direct visit).
	 *
	 * @param array<string,mixed> $touch Touch.
	 */
	public static function is_campaign( array $touch ): bool {
		foreach ( self::PARAMS as $key ) {
			if ( ! empty( $touch[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $name Cookie.
	 * @return array<string,mixed>
	 */
	public static function read_cookie( string $name ): array {
		if ( empty( $_COOKIE[ $name ] ) ) {
			return array();
		}
		$raw  = (string) wp_unslash( $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$json = json_decode( rawurldecode( $raw ), true );
		return self::sanitize_touch( $json );
	}

	/**
	 * Touch described by the current request URL and referrer.
	 *
	 * @return array<string,mixed>
	 */
	private static function request_touch(): array {
		$raw = array();
		foreach ( self::PARAMS as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$raw[ $key ] = wp_unslash( (string) $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
			}
		}
		$referrer_host = '';
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referrer_host = strtolower( (string) wp_parse_url( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ), PHP_URL_HOST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		if ( '' !== $referrer_host && ! self::is_internal_referrer( $referrer_host ) ) {
			$raw['referrer'] = $referrer_host;
		}
		$raw['landing'] = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$raw['ts']      = time();
		return self::sanitize_touch( $raw );
	}

	private static function write_cookie( string $name, array $touch ): void {
		if ( headers_sent() ) {
			return;
		}
		$value = rawurlencode( (string) wp_json_encode( $touch ) );
		setcookie(
			$name,
			$value,
			array(
				'expires'  => time() + self::TTL,
				'path'     => '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ $name ] = $value;
	}

	/**
	 * Server-side capture for uncached requests. Only writes when there is something to record,
	 * so ordinary anonymous page views stay cacheable.
	 */
	public static function capture(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		$touch    = self::request_touch();
		$campaign = self::is_campaign( $touch );
		if ( ! $campaign && empty( $touch['referrer'] ) ) {
			return;
		}
		if ( 'denied' === LNKup_Tracking_Consent::state( get_current_user_id(), true ) ) {
			return;
		}
		if ( array() === self::read_cookie( self::FIRST_COOKIE ) ) {
			self::write_cookie( self::FIRST_COOKIE, $touch );
		}
		self::write_cookie( self::LAST_COOKIE, $touch );
		$user_id = get_current_user_id();
		if ( $user_id && $campaign ) {
			update_user_meta( $user_id, self::META_LAST, $touch );
		}
	}

	public static function enqueue(): void {
		if ( is_admin() || is_feed() ) {
			return;
		}
		wp_enqueue_script( 'lnkup-attribution', LNKUP_PLUGIN_URL . 'public/js/lnkup-attribution.js', array(), LNKUP_VERSION, array( 'in_footer' => false, 'strategy' => 'async' ) );
	}

	/**
	 * Copy cookie (web) or app-supplied attribution onto the new account.
	 *
	 * @param int                 $user_id User.
	 * @param string              $role    Role.
	 * @param array<string,mixed> $context Registration context.
	 */
	public static function on_registered( $user_id, $role, $context = array() ): void {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || 'denied' === LNKup_Tracking_Consent::state( $user_id, true ) ) {
			return;
		}
		$app   = is_array( $context ) && isset( $context['attribution'] ) ? self::sanitize_touch( $context['attribution'] ) : array();
		$first = self::read_cookie( self::FIRST_COOKIE );
		$last  = self::read_cookie( self::LAST_COOKIE );
		if ( array() !== $app ) {
			$first = array() !== $first ? $first : $app;
			$last  = $app;
		}
		if ( array() === $first && array() === $last ) {
			return;
		}
		if ( array() !== $first ) {
			update_user_meta( $user_id, self::META_FIRST, $first );
		}
		if ( array() !== $last ) {
			update_user_meta( $user_id, self::META_LAST, $last );
		}
		update_user_meta(
			$user_id,
			self::META_REG,
			array_merge(
				array() !== $last ? $last : $first,
				array(
					'registered_at' => time(),
					'role'          => 'lnkup_organizer' === (string) $role ? 'organizer' : 'vendor',
					'channel'       => is_array( $context ) ? sanitize_key( (string) ( $context['channel'] ?? 'web' ) ) : 'web',
				)
			)
		);
	}

	/**
	 * Compact attribution for a conversion row: the user's stored touches, else this browser's cookies.
	 *
	 * @param int $user_id User (0 = anonymous).
	 * @return array<string,mixed>
	 */
	public static function snapshot( int $user_id ): array {
		$first = $user_id ? get_user_meta( $user_id, self::META_FIRST, true ) : array();
		$last  = $user_id ? get_user_meta( $user_id, self::META_LAST, true ) : array();
		$first = is_array( $first ) && array() !== $first ? $first : ( 0 === $user_id || get_current_user_id() === $user_id ? self::read_cookie( self::FIRST_COOKIE ) : array() );
		$last  = is_array( $last ) && array() !== $last ? $last : ( 0 === $user_id || get_current_user_id() === $user_id ? self::read_cookie( self::LAST_COOKIE ) : array() );
		$out   = array();
		if ( array() !== $first ) {
			$out['ft'] = self::compact( $first );
		}
		if ( array() !== $last ) {
			$out['lt'] = self::compact( $last );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $touch Touch.
	 * @return array<string,mixed>
	 */
	private static function compact( array $touch ): array {
		$keep = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'campaign_id', 'adset_id', 'ad_id', 'placement', 'landing', 'referrer', 'ts' );
		$out  = array_intersect_key( $touch, array_flip( $keep ) );
		if ( ! empty( $touch['fbclid'] ) ) {
			$out['fbclid'] = 1;
		}
		return $out;
	}

	/**
	 * Remove acquisition user meta for accounts registered more than 24 months ago.
	 */
	public static function purge_expired(): void {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::RETENTION_MONTHS . ' months' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE um FROM {$wpdb->usermeta} um INNER JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE um.meta_key IN (%s, %s, %s) AND u.user_registered < %s",
				self::META_FIRST,
				self::META_LAST,
				self::META_REG,
				$cutoff
			)
		);
	}
}
