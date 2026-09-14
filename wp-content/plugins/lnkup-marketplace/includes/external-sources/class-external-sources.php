<?php
/**
 * External event source registry.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Sources {

	/**
	 * @return array<string, LNKup_External_Source_Provider_Interface>
	 */
	public static function providers(): array {
		return array(
			'manual'     => new LNKup_External_Source_Manual(),
			'csv'        => new LNKup_External_Source_Csv(),
			'eventbrite' => new LNKup_External_Source_Eventbrite(),
			'posh'       => new LNKup_External_Source_Posh(),
			'rss'        => new LNKup_External_Source_Rss(),
			'google'     => new LNKup_External_Source_Google(),
		);
	}

	/**
	 * @param string $slug Provider slug.
	 */
	public static function get( string $slug ): ?LNKup_External_Source_Provider_Interface {
		$providers = self::providers();
		return $providers[ sanitize_key( $slug ) ] ?? null;
	}

	/**
	 * Detect provider from URL host / feed heuristics.
	 *
	 * @param string $url URL.
	 */
	public static function detect_from_url( string $url ): ?LNKup_External_Source_Provider_Interface {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$host = strtolower( (string) $host );
		if ( str_contains( $host, 'eventbrite' ) ) {
			return self::get( 'eventbrite' );
		}
		if ( class_exists( 'LNKup_External_Source_Posh' ) && LNKup_External_Source_Posh::is_allowed_host( $host ) ) {
			return self::get( 'posh' );
		}
		if ( class_exists( 'LNKup_External_Source_Rss' ) && LNKup_External_Source_Rss::looks_like_feed_url( $url ) ) {
			return self::get( 'rss' );
		}
		if ( class_exists( 'LNKup_Page_Extractor' ) && class_exists( 'LNKup_External_Source_Google' ) ) {
			$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
			if ( in_array( $scheme, array( 'http', 'https' ), true ) && ! LNKup_Page_Extractor::should_skip_url( $url ) ) {
				return self::get( 'google' );
			}
		}
		return null;
	}

	/**
	 * Public provider list for admin.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_for_admin(): array {
		$active_slugs = array( 'manual', 'csv', 'eventbrite', 'posh', 'rss', 'google' );
		$out          = array();
		foreach ( self::providers() as $slug => $provider ) {
			$out[] = array(
				'slug'                => $slug,
				'label'               => $provider->label(),
				'supports_url_import' => $provider->supports_url_import(),
				'supports_discovery'  => $provider->supports_discovery(),
				'status'              => in_array( $slug, $active_slugs, true ) ? 'active' : 'planned',
			);
		}
		$planned = array( 'universe', 'humanitix', 'city_calendars' );
		foreach ( $planned as $slug ) {
			$out[] = array(
				'slug'                => $slug,
				'label'               => ucwords( str_replace( '_', ' ', $slug ) ),
				'supports_url_import' => false,
				'supports_discovery'  => false,
				'status'              => 'planned',
			);
		}
		return $out;
	}
}
