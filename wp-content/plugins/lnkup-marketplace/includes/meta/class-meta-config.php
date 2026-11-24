<?php
/**
 * Meta (Pixel, Conversions API, Dataset Quality, Marketing API) configuration.
 *
 * Secrets (access tokens) are read only from the server environment or wp-config.php
 * constants. They are never stored in options, printed, or returned from REST.
 * Non-secret settings (flags, IDs, thresholds) may also be edited in wp-admin; an
 * environment value or constant always wins over the saved option.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Meta_Config {

	const OPTION = 'lnkup_meta_settings';

	const DEFAULT_DATASET_ID    = '2099891494063746';
	const DEFAULT_AD_ACCOUNT_ID = '405302106865918';
	/** Latest Graph API version per developers.facebook.com/docs/graph-api/changelog (Nov 2026). */
	const DEFAULT_GRAPH_VERSION = 'v27.0';
	/** Oldest version still accepted by the Marketing API (v24.0 expired Oct 2026). */
	const MIN_GRAPH_VERSION = 'v25.0';
	const PRODUCTION_HOSTS  = array( 'thelnkup.com', 'www.thelnkup.com' );

	const SECRET_KEYS = array( 'META_CAPI_ACCESS_TOKEN', 'META_DATASET_QUALITY_ACCESS_TOKEN', 'META_MARKETING_API_ACCESS_TOKEN' );

	const FLAG_KEYS = array(
		'META_PIXEL_ENABLED'           => 'pixel_enabled',
		'META_CAPI_ENABLED'            => 'capi_enabled',
		'META_DATASET_QUALITY_ENABLED' => 'dataset_quality_enabled',
		'META_MARKETING_API_ENABLED'   => 'marketing_api_enabled',
		'META_APP_EVENTS_ENABLED'      => 'app_events_enabled',
		'META_TEST_MODE'               => 'test_mode',
	);

	const VALUE_KEYS = array(
		'META_DATASET_ID'        => 'dataset_id',
		'META_PIXEL_ID'          => 'pixel_id',
		'META_AD_ACCOUNT_ID'     => 'ad_account_id',
		'META_GRAPH_API_VERSION' => 'graph_version',
		'META_TEST_EVENT_CODE'   => 'test_event_code',
	);

	/**
	 * Saved non-secret settings merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$defaults = array(
			'pixel_enabled'           => false,
			'capi_enabled'            => false,
			'dataset_quality_enabled' => false,
			'marketing_api_enabled'   => false,
			'app_events_enabled'      => false,
			'test_mode'               => false,
			'dataset_id'              => self::DEFAULT_DATASET_ID,
			'pixel_id'                => self::DEFAULT_DATASET_ID,
			'ad_account_id'           => self::DEFAULT_AD_ACCOUNT_ID,
			'graph_version'           => self::DEFAULT_GRAPH_VERSION,
			'test_event_code'         => '',
			'ldu_mode'                => 'off',
			'excluded_user_ids'       => '',
			'exclude_staff'           => true,
			'alert_min_volume'        => 10,
			'alert_failure_rate_pct'  => 20,
			'alert_registration_gap_hours' => 48,
			'alert_failed_queue_max'  => 50,
			'alert_backlog_minutes'   => 60,
			'alert_dq_stale_hours'    => 48,
			'alert_coverage_drop_pts' => 25,
			'alert_emq_drop'          => 1.5,
			'vendor_activation_profile_min' => 70,
			'vendor_activation_min_applications' => 1,
		);
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Environment variable, then LNKUP_-prefixed constant, then bare constant.
	 *
	 * @param string $name Variable name (e.g. META_CAPI_ACCESS_TOKEN).
	 */
	private static function external( string $name ): ?string {
		$env = getenv( $name );
		if ( false !== $env && '' !== trim( (string) $env ) ) {
			return trim( (string) $env );
		}
		foreach ( array( 'LNKUP_' . $name, $name ) as $const ) {
			if ( defined( $const ) ) {
				$value = constant( $const );
				if ( is_bool( $value ) ) {
					return $value ? 'true' : 'false';
				}
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					return trim( (string) $value );
				}
			}
		}
		return null;
	}

	/**
	 * Whether a setting is pinned by the environment or wp-config.php (shown read-only in wp-admin).
	 *
	 * @param string $env_name Variable name.
	 */
	public static function is_locked( string $env_name ): bool {
		return null !== self::external( $env_name );
	}

	private static function flag( string $env_name ): bool {
		$external = self::external( $env_name );
		if ( null !== $external ) {
			return in_array( strtolower( $external ), array( '1', 'true', 'yes', 'on' ), true );
		}
		$settings = self::settings();
		return ! empty( $settings[ self::FLAG_KEYS[ $env_name ] ] );
	}

	private static function value( string $env_name ): string {
		$external = self::external( $env_name );
		if ( null !== $external ) {
			return $external;
		}
		$settings = self::settings();
		return trim( (string) ( $settings[ self::VALUE_KEYS[ $env_name ] ] ?? '' ) );
	}

	public static function pixel_enabled(): bool {
		return self::flag( 'META_PIXEL_ENABLED' ) && '' !== self::pixel_id();
	}

	/**
	 * Pixel may load: enabled, and on production (or anywhere while test mode is on).
	 */
	public static function pixel_active(): bool {
		return self::pixel_enabled() && ( self::is_production() || self::test_mode() );
	}

	public static function capi_enabled(): bool {
		return self::flag( 'META_CAPI_ENABLED' );
	}

	public static function dataset_quality_enabled(): bool {
		return self::flag( 'META_DATASET_QUALITY_ENABLED' );
	}

	public static function marketing_api_enabled(): bool {
		return self::flag( 'META_MARKETING_API_ENABLED' );
	}

	public static function app_events_enabled(): bool {
		return self::flag( 'META_APP_EVENTS_ENABLED' );
	}

	public static function test_mode(): bool {
		return self::flag( 'META_TEST_MODE' );
	}

	public static function dataset_id(): string {
		return (string) preg_replace( '/\D/', '', self::value( 'META_DATASET_ID' ) );
	}

	public static function pixel_id(): string {
		return (string) preg_replace( '/\D/', '', self::value( 'META_PIXEL_ID' ) );
	}

	/**
	 * Ad account in Marketing API form (act_123...).
	 */
	public static function ad_account_id(): string {
		$digits = (string) preg_replace( '/\D/', '', self::value( 'META_AD_ACCOUNT_ID' ) );
		return '' === $digits ? '' : 'act_' . $digits;
	}

	public static function test_event_code(): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', self::value( 'META_TEST_EVENT_CODE' ) ), 0, 40 );
	}

	public static function graph_version(): string {
		$version = self::value( 'META_GRAPH_API_VERSION' );
		return preg_match( '/^v\d{1,3}\.\d$/', $version ) ? $version : self::DEFAULT_GRAPH_VERSION;
	}

	/**
	 * True when the configured version is older than the minimum Meta still supports.
	 */
	public static function graph_version_outdated(): bool {
		return version_compare( ltrim( self::graph_version(), 'v' ), ltrim( self::MIN_GRAPH_VERSION, 'v' ), '<' );
	}

	public static function graph_base(): string {
		return 'https://graph.facebook.com/' . self::graph_version();
	}

	public static function capi_token(): string {
		return (string) self::external( 'META_CAPI_ACCESS_TOKEN' );
	}

	/**
	 * Dataset Quality token. Meta's Events Manager-generated tokens can both send events
	 * and read dataset quality, so the CAPI token is reused when no dedicated token is set.
	 */
	public static function dataset_quality_token(): string {
		$own = (string) self::external( 'META_DATASET_QUALITY_ACCESS_TOKEN' );
		return '' !== $own ? $own : self::capi_token();
	}

	public static function marketing_api_token(): string {
		return (string) self::external( 'META_MARKETING_API_ACCESS_TOKEN' );
	}

	/**
	 * Every configured secret, for redaction.
	 *
	 * @return string[]
	 */
	public static function secrets(): array {
		$out = array();
		foreach ( self::SECRET_KEYS as $key ) {
			$value = (string) self::external( $key );
			if ( strlen( $value ) >= 8 ) {
				$out[] = $value;
			}
		}
		return $out;
	}

	/**
	 * Masked display, e.g. "EAA…9f2c". Never returns more than 7 characters of the token.
	 *
	 * @param string $token Token.
	 */
	public static function mask( string $token ): string {
		if ( '' === $token ) {
			return __( 'Not configured', 'lnkup-marketplace' );
		}
		if ( strlen( $token ) < 16 ) {
			return '••••';
		}
		return substr( $token, 0, 3 ) . '…' . substr( $token, -4 );
	}

	/**
	 * Where a secret comes from: environment, constant or none.
	 *
	 * @param string $name Variable name.
	 */
	public static function secret_source( string $name ): string {
		$env = getenv( $name );
		if ( false !== $env && '' !== trim( (string) $env ) ) {
			return 'environment';
		}
		foreach ( array( 'LNKUP_' . $name, $name ) as $const ) {
			if ( defined( $const ) && '' !== trim( (string) constant( $const ) ) ) {
				return 'wp-config';
			}
		}
		return 'none';
	}

	/**
	 * Only the production domain sends real events. Staging and local sites record
	 * events internally but never send them unless test mode (with a test code) is on.
	 */
	public static function is_production(): bool {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return (bool) apply_filters( 'lnkup_meta_is_production', in_array( $host, self::PRODUCTION_HOSTS, true ) );
	}

	/**
	 * Why server events cannot be sent right now, or '' when they can.
	 */
	public static function capi_block_reason(): string {
		if ( ! self::capi_enabled() ) {
			return 'capi_disabled';
		}
		if ( '' === self::capi_token() ) {
			return 'token_missing';
		}
		if ( '' === self::dataset_id() ) {
			return 'dataset_missing';
		}
		if ( self::test_mode() ) {
			return '' === self::test_event_code() ? 'test_code_missing' : '';
		}
		return self::is_production() ? '' : 'non_production';
	}

	/**
	 * Limited Data Use processing options for CAPI and the Pixel.
	 *
	 * @return array{options:string[],country:int,state:int}|null
	 */
	public static function data_processing_options(): ?array {
		$mode = (string) ( self::settings()['ldu_mode'] ?? 'off' );
		if ( 'ldu_geo' === $mode ) {
			return array( 'options' => array( 'LDU' ), 'country' => 0, 'state' => 0 );
		}
		if ( 'ldu_all_us' === $mode ) {
			return array( 'options' => array( 'LDU' ), 'country' => 1, 'state' => 1000 );
		}
		return null;
	}

	/**
	 * Staff, excluded and test accounts never feed advertising optimization.
	 *
	 * @param int $user_id User.
	 */
	public static function is_excluded_user( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		$settings = self::settings();
		$ids      = array_filter( array_map( 'absint', explode( ',', (string) $settings['excluded_user_ids'] ) ) );
		$excluded = in_array( $user_id, $ids, true );
		if ( ! $excluded && ! empty( $settings['exclude_staff'] ) && class_exists( 'LNKup_Capabilities' ) ) {
			$excluded = (bool) LNKup_Capabilities::user_has_manage_all( $user_id );
		}
		return (bool) apply_filters( 'lnkup_meta_exclude_user', $excluded, $user_id );
	}

	/**
	 * Save non-secret settings from wp-admin. Locked (env/constant) keys are ignored.
	 *
	 * @param array<string,mixed> $input Raw input.
	 */
	public static function save( array $input ): void {
		$current = self::settings();
		foreach ( self::FLAG_KEYS as $env => $key ) {
			if ( ! self::is_locked( $env ) ) {
				$current[ $key ] = ! empty( $input[ $key ] );
			}
		}
		foreach ( self::VALUE_KEYS as $env => $key ) {
			if ( self::is_locked( $env ) || ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$current[ $key ] = sanitize_text_field( (string) $input[ $key ] );
		}
		if ( isset( $input['ldu_mode'] ) ) {
			$current['ldu_mode'] = in_array( $input['ldu_mode'], array( 'off', 'ldu_geo', 'ldu_all_us' ), true ) ? $input['ldu_mode'] : 'off';
		}
		$current['excluded_user_ids'] = implode( ',', array_filter( array_map( 'absint', explode( ',', (string) ( $input['excluded_user_ids'] ?? '' ) ) ) ) );
		$current['exclude_staff']     = ! empty( $input['exclude_staff'] );
		foreach ( array( 'alert_min_volume', 'alert_failure_rate_pct', 'alert_registration_gap_hours', 'alert_failed_queue_max', 'alert_backlog_minutes', 'alert_dq_stale_hours', 'alert_coverage_drop_pts', 'vendor_activation_profile_min', 'vendor_activation_min_applications' ) as $int_key ) {
			if ( isset( $input[ $int_key ] ) ) {
				$current[ $int_key ] = max( 0, min( 100000, absint( $input[ $int_key ] ) ) );
			}
		}
		if ( isset( $input['alert_emq_drop'] ) ) {
			$current['alert_emq_drop'] = max( 0.0, min( 10.0, (float) $input['alert_emq_drop'] ) );
		}
		update_option( self::OPTION, $current, false );
	}
}
