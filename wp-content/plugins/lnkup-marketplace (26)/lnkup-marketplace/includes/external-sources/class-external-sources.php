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
	 * Detect provider from URL host.
	 *
	 * @param string $url URL.
	 */
	public static function detect_from_url( string $url ): ?LNKup_External_Source_Provider_Interface {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$host = strtolower( (string) $host );
		if ( str_contains( $host, 'eventbrite' ) ) {
			return self::get( 'eventbrite' );
		}
		return null;
	}

	/**
	 * Public provider list for admin.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_for_admin(): array {
		$out = array();
		foreach ( self::providers() as $slug => $provider ) {
			$out[] = array(
				'slug'               => $slug,
				'label'              => $provider->label(),
				'supports_url_import'=> $provider->supports_url_import(),
				'supports_discovery' => $provider->supports_discovery(),
				'status'             => in_array( $slug, array( 'manual', 'csv', 'eventbrite' ), true ) ? 'active' : 'planned',
			);
		}
		$planned = array( 'posh', 'universe', 'humanitix', 'rss', 'city_calendars' );
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
