<?php
/**
 * Feature flags for the Marketing, SEO & Distribution OS. All default off; stored in lnkup_settings.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Marketing_Flags {

	const FLAGS = array(
		'seo_index_policy'      => 'Index policy, clean event/organizer/tour URLs, schema and sitemaps',
		'market_category_pages' => 'City + category pages, weekly digests and market alerts',
		'tracking_links'        => 'Tracking links (/l/{code}) and attribution extensions',
		'organizer_marketing'   => 'Organizer Marketing workspace',
		'campaign_scheduling'   => 'Scheduled, approved campaigns with saved segments',
		'ga4_enabled'           => 'Google Analytics 4 (production host or test mode only)',
		'tiktok_enabled'        => 'TikTok Pixel and Events API (production host or test mode only)',
		'resources_hub'         => 'Resources hub and guides',
		'deep_links_v2'         => 'App links for events, markets, tours and tracking links',
	);

	/** @var array<string,bool> */
	private static array $overrides = array();

	public static function enabled( string $flag ): bool {
		if ( ! isset( self::FLAGS[ $flag ] ) ) {
			return false;
		}
		if ( array_key_exists( $flag, self::$overrides ) ) {
			return self::$overrides[ $flag ];
		}
		$settings = function_exists( 'lnkup_get_settings' ) ? (array) lnkup_get_settings() : (array) get_option( 'lnkup_settings', array() );
		return (bool) apply_filters( 'lnkup_marketing_flag', ! empty( $settings[ $flag ] ), $flag );
	}

	/**
	 * Persist a flag (admin/CLI/tests).
	 */
	public static function set( string $flag, bool $on ): void {
		if ( ! isset( self::FLAGS[ $flag ] ) ) {
			return;
		}
		$settings          = get_option( 'lnkup_settings', array() );
		$settings          = is_array( $settings ) ? $settings : array();
		$settings[ $flag ] = $on ? 1 : 0;
		update_option( 'lnkup_settings', $settings );
		unset( self::$overrides[ $flag ] );
		do_action( 'lnkup_marketing_flag_changed', $flag, $on );
	}

	/**
	 * Request-scoped override (tests only).
	 */
	public static function override( string $flag, ?bool $on ): void {
		if ( null === $on ) {
			unset( self::$overrides[ $flag ] );
			return;
		}
		self::$overrides[ $flag ] = $on;
	}

	/**
	 * @return array<string,bool>
	 */
	public static function all(): array {
		$out = array();
		foreach ( array_keys( self::FLAGS ) as $flag ) {
			$out[ $flag ] = self::enabled( $flag );
		}
		return $out;
	}
}
