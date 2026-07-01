<?php
/**
 * Weather intelligence cron.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Weather_Cron {

	const HOOK = 'lnkup_weather_daily';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_daily' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function run_daily() {
		$settings = lnkup_get_settings();
		if ( empty( $settings['weather_intelligence_enabled'] ) ) {
			return;
		}
		if ( empty( $settings['openweather_api_key'] ) ) {
			return;
		}

		$lock_key = 'lnkup_weather_daily_' . gmdate( 'Y-m-d' );
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, DAY_IN_SECONDS );

		$today   = gmdate( 'Y-m-d' );
		$end     = gmdate( 'Y-m-d', strtotime( '+14 days' ) );
		$events  = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => 25,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_lnkup_event_date',
						'value'   => array( $today, $end ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
			)
		);

		foreach ( $events as $event_id ) {
			$cache_key = 'lnkup_weather_intel_' . $event_id;
			if ( get_transient( $cache_key ) ) {
				continue;
			}
			LNKup_Weather_Service::refresh_event( $event_id, 'cron' );
		}
	}
}
