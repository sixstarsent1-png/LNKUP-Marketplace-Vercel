<?php
/**
 * Feature flags for Event Creation, Universal Import & Event Portfolio OS 3.0.
 * All default off; stored in lnkup_settings next to the marketing flags.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_OS_Flags {

	const FLAGS = array(
		'event_builder_v3'     => 'New event builder (create, edit, duplicate, templates, drafts)',
		'event_import_v3'      => 'Event import from URLs with review, sources and duplicate checks',
		'event_import_bulk'    => 'Bulk import (multiple URLs, CSV, ICS) with background jobs',
		'eventbrite_oauth'     => 'Eventbrite account connection (needs app credentials in wp-config)',
		'event_source_sync'    => 'Check external sources for changes and review them',
		'portfolio_builder_v3' => 'Recurring, series and tour builders in the new creation flow',
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
		return (bool) apply_filters( 'lnkup_event_os_flag', ! empty( $settings[ $flag ] ), $flag );
	}

	public static function set( string $flag, bool $on ): void {
		if ( ! isset( self::FLAGS[ $flag ] ) ) {
			return;
		}
		$settings          = get_option( 'lnkup_settings', array() );
		$settings          = is_array( $settings ) ? $settings : array();
		$settings[ $flag ] = $on ? 1 : 0;
		update_option( 'lnkup_settings', $settings );
		unset( self::$overrides[ $flag ] );
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
