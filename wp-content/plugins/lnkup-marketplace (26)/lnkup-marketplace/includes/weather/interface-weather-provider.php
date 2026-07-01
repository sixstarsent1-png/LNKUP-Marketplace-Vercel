<?php
/**
 * Weather provider interface.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

interface LNKup_Weather_Provider_Interface {

	/**
	 * Provider slug.
	 */
	public function slug();

	/**
	 * Whether API credentials are configured.
	 */
	public function is_configured();

	/**
	 * Fetch live/current and short-range forecast data.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @param array $context Event context.
	 * @return array|WP_Error
	 */
	public function fetch_live( $lat, $lng, array $context );

	/**
	 * Fetch extended planning forecast.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @param array $context Event context.
	 * @return array|WP_Error
	 */
	public function fetch_extended( $lat, $lng, array $context );

	/**
	 * Fetch historical / seasonal risk data.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @param array $context Event context.
	 * @return array|WP_Error
	 */
	public function fetch_historical( $lat, $lng, array $context );

	/**
	 * Admin diagnostics (no secrets).
	 *
	 * @return array
	 */
	public function admin_status();
}
