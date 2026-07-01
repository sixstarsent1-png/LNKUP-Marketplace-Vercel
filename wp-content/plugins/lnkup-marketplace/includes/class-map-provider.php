<?php
/**
 * Map provider abstraction.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

interface LNKup_Map_Provider_Interface {

	/**
	 * Provider slug.
	 */
	public function slug();

	/**
	 * Frontend config for map initialization.
	 */
	public function config();

	/**
	 * Whether tiles/coords are available.
	 */
	public function is_available();
}

class LNKup_Map_Provider {

	/**
	 * Get active provider instance.
	 */
	public static function get() {
		$settings = lnkup_get_settings();
		$slug     = $settings['map_provider'] ?? 'leaflet';

		switch ( $slug ) {
			case 'static':
				return new LNKup_Map_Provider_Static();
			case 'mapbox':
				if ( ! empty( $settings['mapbox_token'] ) ) {
					return new LNKup_Map_Provider_Leaflet( 'mapbox', $settings['mapbox_token'] );
				}
				break;
			case 'google':
				if ( ! empty( $settings['google_maps_key'] ) ) {
					return new LNKup_Map_Provider_Leaflet( 'google', $settings['google_maps_key'] );
				}
				break;
		}

		return new LNKup_Map_Provider_Leaflet();
	}

	/**
	 * Config array for wp_localize_script.
	 */
	public static function frontend_config() {
		$provider = self::get();
		return array_merge(
			array(
				'provider' => $provider->slug(),
				'available'=> $provider->is_available(),
			),
			$provider->config()
		);
	}
}
