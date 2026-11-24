<?php
/**
 * Event Weather Intelligence service.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Weather_Service {

	/**
	 * Get weather intelligence DTO for event (cached).
	 *
	 * @param int $event_id Event ID.
	 */
	public static function for_event( $event_id ) {
		$event_id = absint( $event_id );
		if ( ! $event_id ) {
			return self::empty_dto( $event_id, 'unavailable', __( 'Event not found.', 'lnkup-marketplace' ) );
		}

		$settings = lnkup_get_settings();
		if ( empty( $settings['weather_intelligence_enabled'] ) ) {
			return self::for_viewer( self::empty_dto( $event_id, 'unconfigured', __( 'Weather intelligence is disabled.', 'lnkup-marketplace' ) ) );
		}

		$cache_key = 'lnkup_weather_intel_' . $event_id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached['event_id'] ) ) {
			return self::for_viewer( $cached );
		}

		$dto = self::build_dto( $event_id, false );
		$ttl = max( 5, (int) ( $settings['weather_refresh_minutes'] ?? 30 ) ) * MINUTE_IN_SECONDS;
		set_transient( $cache_key, $dto, $ttl );
		return self::for_viewer( $dto );
	}

	/**
	 * Replace provider/setup messages with copy that is safe for organizers and vendors.
	 * Users with lnkup_manage_all keep the original message (e.g. API key setup).
	 *
	 * @param array $dto Weather DTO.
	 * @return array
	 */
	public static function for_viewer( array $dto ): array {
		$status = (string) ( $dto['status'] ?? '' );
		if ( ! in_array( $status, array( 'unconfigured', 'no_date', 'no_location', 'error', 'unavailable' ), true ) ) {
			return $dto;
		}
		$dto['viewer_state'] = $status;
		if ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() ) {
			$dto['admin_message'] = (string) ( $dto['message'] ?? '' );
			return $dto;
		}
		$copy = array(
			'unconfigured' => __( "Weather isn't available for this event.", 'lnkup-marketplace' ),
			'no_date'      => __( 'Add a date and location to see the forecast.', 'lnkup-marketplace' ),
			'no_location'  => __( 'Add a date and location to see the forecast.', 'lnkup-marketplace' ),
			'error'        => __( 'Weather is temporarily unavailable.', 'lnkup-marketplace' ),
			'unavailable'  => __( "Weather isn't available for this event.", 'lnkup-marketplace' ),
		);
		$dto['message'] = $copy[ $status ];
		return $dto;
	}

	/**
	 * Force refresh weather for event.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $source   manual|cron|api.
	 */
	public static function refresh_event( $event_id, $source = 'manual' ) {
		$event_id = absint( $event_id );
		delete_transient( 'lnkup_weather_intel_' . $event_id );
		$dto = self::build_dto( $event_id, true );
		$ttl = max( 5, (int) ( lnkup_get_settings()['weather_refresh_minutes'] ?? 30 ) ) * MINUTE_IN_SECONDS;
		set_transient( 'lnkup_weather_intel_' . $event_id, $dto, $ttl );
		return self::for_viewer( $dto );
	}

	/**
	 * Portfolio summary for organizer dashboard card.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function portfolio_summary( $user_id ) {
		$user_id = absint( $user_id );
		$events  = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => 1,
				'author'         => $user_id,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'meta_key'       => '_lnkup_event_date',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_lnkup_event_date',
						'value'   => gmdate( 'Y-m-d' ),
						'compare' => '>=',
					),
				),
			)
		);
		if ( ! $events ) {
			return array(
				'has_event' => false,
				'message'   => __( 'Create an event to unlock weather intelligence.', 'lnkup-marketplace' ),
			);
		}
		$dto = self::for_event( $events[0]->ID );
		$dto['has_event'] = true;
		$dto['event_title'] = lnkup_plain_title( $events[0]->ID );
		return $dto;
	}

	/**
	 * Vendor-safe weather note.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function vendor_note( $event_id ) {
		$settings = lnkup_get_settings();
		if ( empty( $settings['weather_vendor_packet_note'] ) ) {
			return null;
		}
		$dto = self::for_event( $event_id );
		if ( 'ok' !== ( $dto['status'] ?? '' ) && 'stale' !== ( $dto['status'] ?? '' ) ) {
			return array(
				'summary' => $dto['message'] ?? '',
				'risks'   => array(),
			);
		}
		return array(
			'summary'         => $dto['summary'] ?? '',
			'confidence'      => $dto['confidence'] ?? '',
			'forecast_status' => $dto['forecast_status'] ?? '',
			'risks'           => $dto['risks'] ?? array(),
			'recommendations' => array_slice( $dto['recommendations'] ?? array(), 0, 3 ),
			'contingency'     => $dto['contingency_plan'] ?? '',
		);
	}

	/**
	 * Resolve forecast mode from days until event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function resolve_mode( $event_id ) {
		$days = self::days_until_event( $event_id );
		if ( null === $days ) {
			return 'unavailable';
		}
		if ( $days <= 7 ) {
			return 'live_forecast';
		}
		if ( $days <= 16 ) {
			return 'extended_forecast';
		}
		return 'historical_risk';
	}

	/**
	 * Get configured provider instance.
	 */
	public static function provider() {
		$settings = lnkup_get_settings();
		return new LNKup_Weather_Provider_OpenWeather(
			$settings['openweather_api_key'] ?? '',
			$settings['weather_units'] ?? 'imperial'
		);
	}

	/**
	 * Build full DTO.
	 *
	 * @param int  $event_id Event ID.
	 * @param bool $force    Force provider fetch.
	 */
	private static function build_dto( $event_id, $force ) {
		$settings = lnkup_get_settings();
		$provider = self::provider();

		if ( ! $provider->is_configured() ) {
			return self::empty_dto( $event_id, 'unconfigured', __( 'Configure your OpenWeather API key in LNKup Settings to unlock weather intelligence.', 'lnkup-marketplace' ) );
		}

		$context = self::event_context( $event_id );
		if ( empty( $context['event_date'] ) ) {
			return self::empty_dto( $event_id, 'no_date', __( 'A forecast appears here closer to the event, once a date and location are published.', 'lnkup-marketplace' ) );
		}

		$location = self::resolve_location( $event_id, $force );
		if ( is_wp_error( $location ) ) {
			$stale = LNKup_Weather_Tables::get_latest( $event_id );
			if ( $stale ) {
				return self::dto_from_snapshot( $stale, true, $context );
			}
			return self::empty_dto( $event_id, 'no_location', $location->get_error_message() );
		}

		$mode = self::resolve_mode( $event_id );
		$context['historical_enabled'] = ! empty( $settings['weather_historical_enabled'] );

		$weather = null;
		if ( 'live_forecast' === $mode ) {
			$weather = $provider->fetch_live( $location['lat'], $location['lng'], $context );
		} elseif ( 'extended_forecast' === $mode ) {
			$weather = $provider->fetch_extended( $location['lat'], $location['lng'], $context );
		} else {
			$weather = $provider->fetch_historical( $location['lat'], $location['lng'], $context );
		}

		if ( is_wp_error( $weather ) ) {
			$stale = LNKup_Weather_Tables::get_latest( $event_id );
			if ( $stale ) {
				return self::dto_from_snapshot( $stale, true, $context );
			}
			return self::empty_dto(
				$event_id,
				'error',
				__( 'We couldn\'t refresh weather intelligence right now. Try again later.', 'lnkup-marketplace' )
			);
		}

		$weather['mode'] = $mode;
		$risks           = LNKup_Weather_Risk_Engine::evaluate( $weather, $context );
		$snapshot_id     = self::persist_snapshot( $event_id, $weather, $location, $context, $risks );

		return self::compose_dto( $event_id, $weather, $location, $context, $risks, 'ok', false, $snapshot_id );
	}

	/**
	 * Event context from meta.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function event_context( $event_id ) {
		$indoor  = (bool) get_post_meta( $event_id, '_lnkup_indoor', true );
		$outdoor = (bool) get_post_meta( $event_id, '_lnkup_outdoor', true );
		return array(
			'event_id'    => $event_id,
			'title'       => lnkup_plain_title( $event_id ),
			'city'        => get_post_meta( $event_id, '_lnkup_city', true ),
			'state'       => get_post_meta( $event_id, '_lnkup_state', true ),
			'venue'       => get_post_meta( $event_id, '_lnkup_venue_name', true ),
			'address'     => class_exists( 'LNKup_Geocoder' ) ? LNKup_Geocoder::event_address_string( $event_id ) : '',
			'event_date'  => get_post_meta( $event_id, '_lnkup_event_date', true ),
			'start_time'  => get_post_meta( $event_id, '_lnkup_start_time', true ) ?: '12:00',
			'end_time'    => get_post_meta( $event_id, '_lnkup_end_time', true ),
			'outdoor'     => $outdoor,
			'indoor'      => $indoor,
			'indoor_only' => $indoor && ! $outdoor,
			'attendance'  => (int) get_post_meta( $event_id, '_lnkup_expected_attendance', true ),
			'contingency' => get_post_meta( $event_id, '_lnkup_weather_placeholder', true ),
		);
	}

	/**
	 * Resolve coordinates for event.
	 *
	 * @param int  $event_id Event ID.
	 * @param bool $force    Force geocode.
	 */
	private static function resolve_location( $event_id, $force = false ) {
		$lat = LNKup_Geocoder::validate_lat( get_post_meta( $event_id, '_lnkup_lat', true ) );
		$lng = LNKup_Geocoder::validate_lng( get_post_meta( $event_id, '_lnkup_lng', true ) );
		if ( null !== $lat && null !== $lng ) {
			return array(
				'lat'   => $lat,
				'lng'   => $lng,
				'label' => self::location_label( $event_id ),
			);
		}
		if ( class_exists( 'LNKup_Geocoder' ) ) {
			$coords = LNKup_Geocoder::geocode_event( $event_id, $force );
			if ( $coords && isset( $coords['lat'], $coords['lng'] ) ) {
				return array(
					'lat'   => (float) $coords['lat'],
					'lng'   => (float) $coords['lng'],
					'label' => self::location_label( $event_id ),
				);
			}
		}
		return new WP_Error(
			'lnkup_weather_no_location',
			__( 'Add an event location to unlock weather intelligence.', 'lnkup-marketplace' )
		);
	}

	/**
	 * @param int $event_id Event ID.
	 */
	private static function location_label( $event_id ) {
		$parts = array_filter(
			array(
				get_post_meta( $event_id, '_lnkup_venue_name', true ),
				get_post_meta( $event_id, '_lnkup_city', true ),
				get_post_meta( $event_id, '_lnkup_state', true ),
			)
		);
		return implode( ', ', $parts );
	}

	/**
	 * Days until event (null if no date).
	 *
	 * @param int $event_id Event ID.
	 */
	public static function days_until_event( $event_id ) {
		$date = get_post_meta( $event_id, '_lnkup_event_date', true );
		if ( ! $date ) {
			return null;
		}
		$today = strtotime( gmdate( 'Y-m-d' ) );
		$event = strtotime( $date );
		if ( false === $today || false === $event ) {
			return null;
		}
		return (int) floor( ( $event - $today ) / DAY_IN_SECONDS );
	}

	/**
	 * Persist snapshot row.
	 */
	private static function persist_snapshot( $event_id, array $weather, array $location, array $context, array $risks ) {
		$current   = $weather['current'] ?? null;
		$event_day = $weather['event_day'] ?? null;
		$row       = array(
			'event_id'             => $event_id,
			'provider'             => $weather['provider'] ?? 'openweather',
			'forecast_type'        => $weather['mode'] ?? 'unavailable',
			'forecast_date'        => $context['event_date'] ?? null,
			'location_label'       => $location['label'] ?? '',
			'lat'                  => $location['lat'] ?? null,
			'lng'                  => $location['lng'] ?? null,
			'temp_high'            => $event_day['temp_high'] ?? ( $current['temp'] ?? null ),
			'temp_low'             => $event_day['temp_low'] ?? null,
			'temperature'          => $current['temp'] ?? ( $event_day['slot_temp'] ?? null ),
			'feels_like'           => $current['feels_like'] ?? null,
			'humidity'             => $current['humidity'] ?? null,
			'precipitation_chance' => $event_day['slot_pop'] ?? ( $event_day['pop_pct'] ?? null ),
			'wind_speed'           => $current['wind_speed'] ?? ( $event_day['wind_speed'] ?? null ),
			'wind_gust'            => $current['wind_gust'] ?? ( $event_day['wind_gust'] ?? null ),
			'condition_code'       => $current['icon'] ?? ( $event_day['icon'] ?? '' ),
			'condition_summary'    => $current['description'] ?? ( $event_day['description'] ?? '' ),
			'alerts_count'         => count( $weather['alerts'] ?? array() ),
			'raw_payload'          => $weather['raw'] ?? array(),
			'overall_risk'         => $risks['overall_risk'] ?? 'unavailable',
			'rain_risk'            => $risks['rain_risk'] ?? 'unavailable',
			'wind_risk'            => $risks['wind_risk'] ?? 'unavailable',
			'heat_risk'            => $risks['heat_risk'] ?? 'unavailable',
			'storm_risk'           => $risks['storm_risk'] ?? 'unavailable',
			'setup_risk'           => $risks['setup_risk'] ?? 'unavailable',
			'confidence'           => $risks['confidence'] ?? 'unavailable',
			'recommendations'      => $risks['recommendations'] ?? array(),
		);
		return LNKup_Weather_Tables::insert_snapshot( $row );
	}

	/**
	 * @param array $snapshot Snapshot row.
	 * @param bool  $stale    Stale flag.
	 * @param array $context  Event context.
	 */
	private static function dto_from_snapshot( array $snapshot, $stale, array $context ) {
		$weather = array(
			'mode'      => $snapshot['forecast_type'] ?? 'unavailable',
			'current'   => array(
				'temp'        => $snapshot['temperature'] ?? null,
				'feels_like'  => $snapshot['feels_like'] ?? null,
				'humidity'    => $snapshot['humidity'] ?? null,
				'wind_speed'  => $snapshot['wind_speed'] ?? null,
				'wind_gust'   => $snapshot['wind_gust'] ?? null,
				'description' => $snapshot['condition_summary'] ?? '',
				'icon'        => $snapshot['condition_code'] ?? '',
			),
			'event_day' => array(
				'date'        => $snapshot['forecast_date'] ?? '',
				'temp_high'   => $snapshot['temp_high'] ?? null,
				'temp_low'    => $snapshot['temp_low'] ?? null,
				'pop_pct'     => $snapshot['precipitation_chance'] ?? null,
				'description' => $snapshot['condition_summary'] ?? '',
			),
			'alerts' => array(),
		);
		$risks = array(
			'overall_risk'    => $snapshot['overall_risk'] ?? 'unavailable',
			'rain_risk'       => $snapshot['rain_risk'] ?? 'unavailable',
			'wind_risk'       => $snapshot['wind_risk'] ?? 'unavailable',
			'heat_risk'       => $snapshot['heat_risk'] ?? 'unavailable',
			'storm_risk'      => $snapshot['storm_risk'] ?? 'unavailable',
			'setup_risk'      => $snapshot['setup_risk'] ?? 'unavailable',
			'confidence'      => $snapshot['confidence'] ?? 'unavailable',
			'recommendations' => $snapshot['recommendations'] ?? array(),
		);
		$location = array(
			'label' => $snapshot['location_label'] ?? '',
			'lat'   => $snapshot['lat'] ?? null,
			'lng'   => $snapshot['lng'] ?? null,
		);
		return self::compose_dto(
			(int) $snapshot['event_id'],
			$weather,
			$location,
			$context,
			$risks,
			'error',
			$stale,
			(int) ( $snapshot['id'] ?? 0 ),
			$snapshot['updated_at'] ?? ''
		);
	}

	/**
	 * Compose API/UI DTO.
	 */
	private static function compose_dto( $event_id, array $weather, array $location, array $context, array $risks, $status, $stale, $snapshot_id = 0, $last_updated = '' ) {
		$mode = $weather['mode'] ?? self::resolve_mode( $event_id );
		$dto  = array(
			'success'          => in_array( $status, array( 'ok', 'stale' ), true ) || $stale,
			'event_id'         => $event_id,
			'mode'             => $mode,
			'status'           => $stale ? 'stale' : $status,
			'confidence'       => $risks['confidence'] ?? 'unavailable',
			'forecast_status'  => self::forecast_status_label( $mode, $risks['confidence'] ?? '' ),
			'location'         => array(
				'label' => $location['label'] ?? '',
				'lat'   => $location['lat'] ?? null,
				'lng'   => $location['lng'] ?? null,
			),
			'current'          => $weather['current'] ?? null,
			'event_day'        => $weather['event_day'] ?? null,
			'hourly'           => $weather['hourly'] ?? array(),
			'alerts'           => $weather['alerts'] ?? array(),
			'risks'            => array(
				'overall' => $risks['overall_risk'] ?? 'unavailable',
				'rain'    => $risks['rain_risk'] ?? 'unavailable',
				'wind'    => $risks['wind_risk'] ?? 'unavailable',
				'heat'    => $risks['heat_risk'] ?? 'unavailable',
				'storm'   => $risks['storm_risk'] ?? 'unavailable',
				'setup'   => $risks['setup_risk'] ?? 'unavailable',
			),
			'recommendations'  => $risks['recommendations'] ?? array(),
			'contingency_plan' => $context['contingency'] ?? '',
			'summary'          => self::format_summary( $weather, $context ),
			'stale'            => (bool) $stale,
			'last_updated'     => $last_updated ?: gmdate( 'c' ),
			'snapshot_id'      => $snapshot_id,
			'message'          => '',
			'days_until'       => self::days_until_event( $event_id ),
		);
		if ( 'error' === $status && $stale ) {
			$dto['message'] = __( 'We couldn\'t refresh weather intelligence right now. Showing last successful snapshot.', 'lnkup-marketplace' );
		}
		return $dto;
	}

	/**
	 * Empty/error DTO.
	 */
	private static function empty_dto( $event_id, $status, $message ) {
		$context = $event_id ? self::event_context( $event_id ) : array();
		$stale   = LNKup_Weather_Tables::get_latest( $event_id );
		if ( $stale && in_array( $status, array( 'error', 'no_location' ), true ) ) {
			return self::dto_from_snapshot( $stale, true, $context );
		}
		return array(
			'success'          => false,
			'event_id'         => $event_id,
			'mode'             => 'unavailable',
			'status'           => $status,
			'confidence'       => 'unavailable',
			'forecast_status'  => self::forecast_status_label( 'unavailable', 'unavailable' ),
			'location'         => null,
			'current'          => null,
			'event_day'        => null,
			'hourly'           => array(),
			'alerts'           => array(),
			'risks'            => array(
				'overall' => 'unavailable',
				'rain'    => 'unavailable',
				'wind'    => 'unavailable',
				'heat'    => 'unavailable',
				'storm'   => 'unavailable',
				'setup'   => 'unavailable',
			),
			'recommendations'  => array(),
			'contingency_plan' => $context['contingency'] ?? '',
			'summary'          => '',
			'stale'            => false,
			'last_updated'     => '',
			'snapshot_id'      => 0,
			'message'          => $message,
			'days_until'       => $event_id ? self::days_until_event( $event_id ) : null,
		);
	}

	/**
	 * Human forecast status label.
	 */
	public static function forecast_status_label( $mode, $confidence ) {
		switch ( $mode ) {
			case 'live_forecast':
				return __( 'Live forecast available', 'lnkup-marketplace' );
			case 'extended_forecast':
				return __( 'Extended forecast — planning guidance', 'lnkup-marketplace' );
			case 'historical_risk':
				return __( 'Showing historical weather risk', 'lnkup-marketplace' );
			default:
				if ( 'extended' === $confidence ) {
					return __( 'Extended forecast — planning guidance', 'lnkup-marketplace' );
				}
				return __( 'Too early for live forecast', 'lnkup-marketplace' );
		}
	}

	/**
	 * One-line summary.
	 */
	private static function format_summary( array $weather, array $context ) {
		$parts = array();
		$cur   = $weather['current'] ?? null;
		if ( $cur && null !== ( $cur['temp'] ?? null ) ) {
			$parts[] = sprintf(
				/* translators: %s: temperature */
				__( 'Now %s°F', 'lnkup-marketplace' ),
				round( (float) $cur['temp'] )
			);
			if ( ! empty( $cur['description'] ) ) {
				$parts[0] .= ' — ' . $cur['description'];
			}
		}
		$day = $weather['event_day'] ?? null;
		if ( $day && ! empty( $context['event_date'] ) ) {
			$high = isset( $day['temp_high'] ) ? round( (float) $day['temp_high'] ) : null;
			$low  = isset( $day['temp_low'] ) ? round( (float) $day['temp_low'] ) : null;
			$pop  = $day['pop_pct'] ?? null;
			if ( $high ) {
				$line = sprintf(
					/* translators: 1: event date 2: high 3: low */
					__( 'Event day %1$s: %2$s°F', 'lnkup-marketplace' ),
					$context['event_date'],
					$high
				);
				if ( $low && $low !== $high ) {
					$line .= '/' . $low . '°F';
				}
				if ( null !== $pop ) {
					$line .= sprintf( ' · %d%% rain', (int) $pop );
				}
				$parts[] = $line;
			}
		}
		return implode( ' · ', array_filter( $parts ) );
	}
}
