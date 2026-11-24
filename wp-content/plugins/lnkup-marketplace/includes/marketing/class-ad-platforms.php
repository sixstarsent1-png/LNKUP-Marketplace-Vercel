<?php
/**
 * GA4, Google Ads conversion tags and TikTok Pixel / Events API (flags ga4_enabled, tiktok_enabled).
 *
 * Every platform is off unless: its flag is on, its IDs are configured, the site is the production
 * host (LNKup_Meta_Config::is_production) or ads test mode is on, the visitor has not refused
 * tracking (LNKup_Tracking_Consent), and the account is not staff/excluded. Staging and local
 * sites therefore never send data unless test mode is deliberately enabled (GA4 then uses the
 * validation-only debug endpoint; TikTok requires a test event code).
 *
 * Conversions come only from LNKup_Conversion_Registry rows and reuse their event_id:
 * - GA4: Purchase is sent server-side with transaction_id = event_id; events the registry fires in
 *   the browser are sent from the browser only (GA4 does not de-duplicate other events).
 * - TikTok: browser and Events API both send, de-duplicated by event_id.
 * - Google Ads: browser conversion tags with transaction_id = event_id.
 * Parameters never include names, emails, phone numbers or application content.
 *
 * Secrets (GA4 API secret, TikTok access token) come only from the environment or wp-config.php.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Ad_Platforms {

	const OPTION        = 'lnkup_ads_settings';
	const PENDING_META  = '_lnkup_ads_pending';
	const GA_CID_META   = '_lnkup_ga_cid';
	const DISPATCH_HOOK = 'lnkup_ads_dispatch';
	const MAX_ATTEMPTS  = 5;

	/**
	 * Registry event => platform event names (null = not sent to that platform).
	 */
	const EVENTS = array(
		'CompleteRegistration' => array( 'ga4' => 'sign_up', 'tiktok' => 'CompleteRegistration' ),
		'Lead'                 => array( 'ga4' => 'generate_lead', 'tiktok' => 'SubmitForm' ),
		'SubmitApplication'    => array( 'ga4' => 'submit_application', 'tiktok' => 'SubmitForm' ),
		'InitiateCheckout'     => array( 'ga4' => 'begin_checkout', 'tiktok' => 'InitiateCheckout' ),
		'Purchase'             => array( 'ga4' => 'purchase', 'tiktok' => 'CompletePayment' ),
		'OrganizerRegistered'  => array( 'ga4' => 'organizer_sign_up', 'tiktok' => null ),
		'FirstEventPublished'  => array( 'ga4' => 'organizer_first_event_published', 'tiktok' => null ),
		'ApplicationApproved'  => array( 'ga4' => 'application_approved', 'tiktok' => null ),
	);

	public static function init(): void {
		add_action( 'lnkup_conversion_recorded', array( __CLASS__, 'on_conversion' ), 30, 2 );
		add_action( 'wp_head', array( __CLASS__, 'render_tags' ), 3 );
		add_action( 'init', array( __CLASS__, 'remember_ga_client' ), 20 );
		add_action( self::DISPATCH_HOOK, array( __CLASS__, 'dispatch' ) );
	}

	/* ------------------------------------------------------------ Config */

	/**
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'ga4_measurement_id'     => '',
				'gads_conversion_id'     => '',
				'gads_labels'            => array(),
				'tiktok_pixel_id'        => '',
				'tiktok_test_event_code' => '',
				'test_mode'              => false,
			)
		);
	}

	private static function external( string $name ): string {
		$env = getenv( $name );
		if ( false !== $env && '' !== trim( (string) $env ) ) {
			return trim( (string) $env );
		}
		foreach ( array( 'LNKUP_' . $name, $name ) as $const ) {
			if ( defined( $const ) && is_scalar( constant( $const ) ) ) {
				return trim( (string) constant( $const ) );
			}
		}
		return '';
	}

	public static function ga4_id(): string {
		$id = self::external( 'GA4_MEASUREMENT_ID' ) ?: (string) self::settings()['ga4_measurement_id'];
		return preg_match( '/^G-[A-Z0-9]{4,16}$/', $id ) ? $id : '';
	}

	public static function gads_id(): string {
		$id = self::external( 'GADS_CONVERSION_ID' ) ?: (string) self::settings()['gads_conversion_id'];
		return preg_match( '/^AW-\d{6,14}$/', $id ) ? $id : '';
	}

	public static function gads_label( string $event ): string {
		$labels = (array) self::settings()['gads_labels'];
		$label  = (string) ( $labels[ $event ] ?? '' );
		return preg_match( '/^[A-Za-z0-9_-]{4,40}$/', $label ) ? $label : '';
	}

	public static function tiktok_id(): string {
		$id = self::external( 'TIKTOK_PIXEL_ID' ) ?: (string) self::settings()['tiktok_pixel_id'];
		return preg_match( '/^[A-Z0-9]{10,30}$/', $id ) ? $id : '';
	}

	public static function test_mode(): bool {
		$ext = strtolower( self::external( 'ADS_TEST_MODE' ) );
		return '' !== $ext ? in_array( $ext, array( '1', 'true', 'yes', 'on' ), true ) : ! empty( self::settings()['test_mode'] );
	}

	private static function host_allowed(): bool {
		$production = class_exists( 'LNKup_Meta_Config' ) ? LNKup_Meta_Config::is_production() : false;
		return $production || self::test_mode();
	}

	private static function flag( string $platform ): bool {
		$flag = 'ga4' === $platform ? 'ga4_enabled' : 'tiktok_enabled';
		return class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( $flag );
	}

	public static function client_active( string $platform ): bool {
		if ( ! self::flag( $platform ) || ! self::host_allowed() ) {
			return false;
		}
		return 'ga4' === $platform ? ( '' !== self::ga4_id() || '' !== self::gads_id() ) : '' !== self::tiktok_id();
	}

	public static function server_active( string $platform ): bool {
		if ( ! self::flag( $platform ) || ! self::host_allowed() ) {
			return false;
		}
		if ( 'ga4' === $platform ) {
			return '' !== self::ga4_id() && '' !== self::external( 'GA4_API_SECRET' );
		}
		if ( '' === self::tiktok_id() || '' === self::external( 'TIKTOK_ACCESS_TOKEN' ) ) {
			return false;
		}
		return ! self::test_mode() || '' !== self::tiktok_test_code();
	}

	private static function tiktok_test_code(): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) self::settings()['tiktok_test_event_code'] ), 0, 40 );
	}

	/**
	 * Status for the Command Center (never includes secrets).
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		return array(
			'host_allowed'  => self::host_allowed(),
			'test_mode'     => self::test_mode(),
			'ga4'           => array( 'flag' => self::flag( 'ga4' ), 'measurement_id' => self::ga4_id(), 'api_secret' => '' !== self::external( 'GA4_API_SECRET' ), 'client' => self::client_active( 'ga4' ), 'server' => self::server_active( 'ga4' ) ),
			'google_ads'    => array( 'conversion_id' => self::gads_id(), 'labels' => array_keys( array_filter( (array) self::settings()['gads_labels'] ) ) ),
			'tiktok'        => array( 'flag' => self::flag( 'tiktok' ), 'pixel_id' => self::tiktok_id(), 'access_token' => '' !== self::external( 'TIKTOK_ACCESS_TOKEN' ), 'client' => self::client_active( 'tiktok' ), 'server' => self::server_active( 'tiktok' ) ),
		);
	}

	/* ------------------------------------------------------------ Gating per person */

	private static function person_allowed( int $user_id, bool $in_request ): bool {
		if ( class_exists( 'LNKup_Meta_Config' ) && LNKup_Meta_Config::is_excluded_user( $user_id ) ) {
			return false;
		}
		return ! class_exists( 'LNKup_Tracking_Consent' ) || 'granted' === LNKup_Tracking_Consent::state( $user_id, $in_request );
	}

	/**
	 * Non-personal parameters for a conversion row.
	 *
	 * @param array<string,mixed> $row Conversion row.
	 * @return array<string,mixed>
	 */
	public static function params( array $row ): array {
		$custom = json_decode( (string) ( $row['custom_data'] ?? '' ), true );
		$custom = is_array( $custom ) ? $custom : array();
		$out    = array();
		if ( isset( $custom['value'] ) ) {
			$out['value']    = round( (float) $custom['value'], 2 );
			$out['currency'] = (string) ( $custom['currency'] ?? 'USD' );
		}
		$role = (string) ( $row['role'] ?? '' );
		if ( in_array( $role, array( 'vendor', 'organizer' ), true ) ) {
			$out['marketplace_role'] = $role;
		}
		return $out;
	}

	/* ------------------------------------------------------------ Conversions */

	/**
	 * @param array<string,mixed> $row Conversion row.
	 * @param array<string,mixed> $def Definition.
	 */
	public static function on_conversion( $row, $def = array() ): void {
		global $wpdb;
		$name = is_array( $row ) ? (string) ( $row['event_name'] ?? '' ) : '';
		if ( ! isset( self::EVENTS[ $name ] ) || 'granted' !== ( $row['consent_state'] ?? '' ) ) {
			return;
		}
		$user_id  = (int) ( $row['user_id'] ?? 0 );
		$platform = (string) ( $row['source_platform'] ?? '' );
		if ( 'app' === $platform || ! self::person_allowed( $user_id, false ) ) {
			return;
		}
		$browser = ! empty( $def['browser'] ) && $user_id > 0;
		if ( $browser && ( self::client_active( 'ga4' ) || self::client_active( 'tiktok' ) ) ) {
			$queue   = get_user_meta( $user_id, self::PENDING_META, true );
			$queue   = is_array( $queue ) ? $queue : array();
			$queue[] = array( 'name' => $name, 'event_id' => (string) $row['event_id'], 'params' => self::params( $row ), 'at' => time() );
			update_user_meta( $user_id, self::PENDING_META, array_slice( $queue, -10 ) );
		}
		$queued = false;
		foreach ( array( 'ga4', 'tiktok' ) as $p ) {
			if ( null === self::EVENTS[ $name ][ $p ] || ! self::server_active( $p ) ) {
				continue;
			}
			if ( 'ga4' === $p && $browser && 'Purchase' !== $name ) {
				continue;
			}
			$payload = 'ga4' === $p ? self::ga4_payload( $row, $user_id ) : self::tiktok_payload( $row, $user_id );
			if ( null === $payload ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . LNKup_Marketing_Tables::ads_outbox() . ' (platform, event_id, event_name, payload, status, is_test, created_at) VALUES (%s, %s, %s, %s, %s, %d, %s)', $p, (string) $row['event_id'], $name, (string) wp_json_encode( $payload ), 'pending', self::test_mode() ? 1 : 0, current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$queued = true;
		}
		if ( $queued && ! wp_next_scheduled( self::DISPATCH_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::DISPATCH_HOOK );
		}
	}

	/**
	 * GA4 Measurement Protocol body, or null when no GA client ID is known for the person.
	 *
	 * @param array<string,mixed> $row Conversion row.
	 * @return array<string,mixed>|null
	 */
	public static function ga4_payload( array $row, int $user_id ): ?array {
		$cid = self::ga_client_id_from_cookie();
		if ( '' === $cid && $user_id > 0 ) {
			$cid = (string) get_user_meta( $user_id, self::GA_CID_META, true );
		}
		if ( '' === $cid ) {
			return null;
		}
		$params = array_merge( self::params( $row ), array( 'transaction_id' => (string) $row['event_id'], 'engagement_time_msec' => 1 ) );
		if ( self::test_mode() ) {
			$params['debug_mode'] = 1;
		}
		return array(
			'client_id'        => $cid,
			'timestamp_micros' => (int) ( $row['event_time'] ?? time() ) * 1000000,
			'events'           => array( array( 'name' => self::EVENTS[ (string) $row['event_name'] ]['ga4'], 'params' => $params ) ),
		);
	}

	/**
	 * TikTok Events API body (click ID only; no hashed email or phone).
	 *
	 * @param array<string,mixed> $row Conversion row.
	 * @return array<string,mixed>
	 */
	public static function tiktok_payload( array $row, int $user_id ): array {
		$event = array(
			'event'      => self::EVENTS[ (string) $row['event_name'] ]['tiktok'],
			'event_time' => (int) ( $row['event_time'] ?? time() ),
			'event_id'   => (string) $row['event_id'],
			'page'       => array( 'url' => (string) ( $row['event_source_url'] ?? '' ) ),
			'properties' => self::params( $row ),
		);
		$touch   = $user_id > 0 ? get_user_meta( $user_id, '_lnkup_acq_last_touch', true ) : array();
		$ttclid  = is_array( $touch ) ? (string) ( $touch['ttclid'] ?? '' ) : '';
		if ( '' !== $ttclid ) {
			$event['user'] = array( 'ttclid' => $ttclid );
		}
		$body = array( 'event_source' => 'web', 'event_source_id' => self::tiktok_id(), 'data' => array( $event ) );
		if ( self::test_mode() ) {
			$body['test_event_code'] = self::tiktok_test_code();
		}
		return $body;
	}

	private static function ga_client_id_from_cookie(): string {
		$raw = isset( $_COOKIE['_ga'] ) ? (string) wp_unslash( $_COOKIE['_ga'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return preg_match( '/^GA\d\.\d\.(\d+\.\d+)$/', $raw, $m ) ? $m[1] : '';
	}

	public static function remember_ga_client(): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! self::client_active( 'ga4' ) ) {
			return;
		}
		$cid = self::ga_client_id_from_cookie();
		if ( '' !== $cid && get_user_meta( $user_id, self::GA_CID_META, true ) !== $cid ) {
			update_user_meta( $user_id, self::GA_CID_META, $cid );
		}
	}

	/**
	 * Send pending outbox rows. Re-checks gating at send time.
	 *
	 * @return array{sent:int,failed:int,skipped:int}
	 */
	public static function dispatch(): array {
		global $wpdb;
		$table = LNKup_Marketing_Tables::ads_outbox();
		$rows  = (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE status IN ('pending','retry') ORDER BY id ASC LIMIT 50", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array( 'sent' => 0, 'failed' => 0, 'skipped' => 0 );
		foreach ( $rows as $row ) {
			$platform = (string) $row['platform'];
			if ( ! self::server_active( $platform ) ) {
				$wpdb->update( $table, array( 'status' => 'skipped', 'last_error' => 'inactive' ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$out['skipped'];
				continue;
			}
			$response = 'ga4' === $platform ? self::send_ga4( (string) $row['payload'] ) : self::send_tiktok( (string) $row['payload'] );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$ok       = $code >= 200 && $code < 300;
			if ( $ok && 'tiktok' === $platform ) {
				$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				$ok   = is_array( $body ) && 0 === (int) ( $body['code'] ?? -1 );
			}
			$attempts = (int) $row['attempts'] + 1;
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'status'     => $ok ? 'sent' : ( $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'retry' ),
					'attempts'   => $attempts,
					'last_error' => $ok ? '' : ( is_wp_error( $response ) ? substr( $response->get_error_code(), 0, 60 ) : 'http_' . $code ),
					'sent_at'    => $ok ? current_time( 'mysql', true ) : null,
				),
				array( 'id' => (int) $row['id'] )
			);
			$ok ? ++$out['sent'] : ++$out['failed'];
		}
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','retry')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $left > 0 && ! wp_next_scheduled( self::DISPATCH_HOOK ) ) {
			wp_schedule_single_event( time() + 300, self::DISPATCH_HOOK );
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private static function send_ga4( string $payload ) {
		$endpoint = self::test_mode() ? 'https://www.google-analytics.com/debug/mp/collect' : 'https://www.google-analytics.com/mp/collect';
		$url      = add_query_arg( array( 'measurement_id' => self::ga4_id(), 'api_secret' => self::external( 'GA4_API_SECRET' ) ), $endpoint );
		return wp_remote_post( $url, array( 'timeout' => 8, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => $payload ) );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private static function send_tiktok( string $payload ) {
		return wp_remote_post(
			'https://business-api.tiktok.com/open_api/v1.3/event/track/',
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json', 'Access-Token' => self::external( 'TIKTOK_ACCESS_TOKEN' ) ),
				'body'    => $payload,
			)
		);
	}

	/* ------------------------------------------------------------ Browser tags */

	public static function render_tags(): void {
		if ( is_admin() || is_feed() || is_embed() ) {
			return;
		}
		$ga  = self::client_active( 'ga4' );
		$tt  = self::client_active( 'tiktok' );
		$uid = get_current_user_id();
		if ( ( ! $ga && ! $tt ) || ! self::person_allowed( $uid, true ) ) {
			return;
		}
		$pending = array();
		if ( $uid > 0 ) {
			$queue = get_user_meta( $uid, self::PENDING_META, true );
			if ( is_array( $queue ) && $queue ) {
				delete_user_meta( $uid, self::PENDING_META );
				$pending = array_values( array_filter( $queue, static fn( $q ) => is_array( $q ) && (int) ( $q['at'] ?? 0 ) > time() - 2 * DAY_IN_SECONDS ) );
			}
		}
		$events = array();
		foreach ( $pending as $item ) {
			$name = (string) ( $item['name'] ?? '' );
			if ( ! isset( self::EVENTS[ $name ] ) ) {
				continue;
			}
			$events[] = array(
				'ga4'    => $ga && 'Purchase' !== $name && self::ga4_id() ? self::EVENTS[ $name ]['ga4'] : null,
				'tiktok' => $tt ? self::EVENTS[ $name ]['tiktok'] : null,
				'gads'   => $ga && self::gads_id() && self::gads_label( $name ) ? self::gads_id() . '/' . self::gads_label( $name ) : null,
				'id'     => (string) $item['event_id'],
				'params' => (array) $item['params'],
			);
		}
		$config = array( 'ga4' => $ga ? self::ga4_id() : '', 'gads' => $ga ? self::gads_id() : '', 'tiktok' => $tt ? self::tiktok_id() : '', 'events' => $events );
		if ( $config['ga4'] || $config['gads'] ) {
			$loader = $config['ga4'] ? $config['ga4'] : $config['gads'];
			printf( '<script async src="%s"></script>' . "\n", esc_url( 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $loader ) ) ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
		}
		?>
		<script id="lnkup-ad-platforms">
		(function (c) {
			if (navigator.globalPrivacyControl === true) return;
			if (c.ga4 || c.gads) {
				window.dataLayer = window.dataLayer || [];
				window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
				gtag('js', new Date());
				if (c.ga4) gtag('config', c.ga4, { allow_google_signals: false, allow_ad_personalization_signals: false });
				if (c.gads) gtag('config', c.gads);
			}
			if (c.tiktok) {
				!function (w, d, t) { w.TiktokAnalyticsObject = t; var ttq = w[t] = w[t] || []; ttq.methods = ['page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie']; ttq.setAndDefer = function (t, e) { t[e] = function () { t.push([e].concat(Array.prototype.slice.call(arguments, 0))); }; }; for (var i = 0; i < ttq.methods.length; i++) ttq.setAndDefer(ttq, ttq.methods[i]); ttq.load = function (e) { var s = d.createElement('script'); s.async = true; s.src = 'https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=' + encodeURIComponent(e) + '&lib=' + t; var f = d.getElementsByTagName('script')[0]; f.parentNode.insertBefore(s, f); }; ttq.load(c.tiktok); ttq.page(); }(window, document, 'ttq');
			}
			(c.events || []).forEach(function (e) {
				var p = Object.assign({}, e.params || {});
				if (e.ga4 && window.gtag) gtag('event', e.ga4, Object.assign({ transaction_id: e.id }, p));
				if (e.gads && window.gtag) gtag('event', 'conversion', { send_to: e.gads, transaction_id: e.id, value: p.value, currency: p.currency });
				if (e.tiktok && window.ttq) window.ttq.track(e.tiktok, p, { event_id: e.id });
			});
		})(<?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); ?>);
		</script>
		<?php
	}
}
