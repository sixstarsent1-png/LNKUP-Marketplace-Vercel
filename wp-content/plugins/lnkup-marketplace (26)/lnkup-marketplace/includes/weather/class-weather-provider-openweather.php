<?php
/**
 * OpenWeather provider (Current + Forecast 2.5).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Weather_Provider_OpenWeather implements LNKup_Weather_Provider_Interface {

	const API_BASE = 'https://api.openweathermap.org/data/2.5';

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * @var string
	 */
	private $units;

	public function __construct( $api_key = '', $units = 'imperial' ) {
		$this->api_key = (string) $api_key;
		$this->units   = in_array( $units, array( 'imperial', 'metric', 'standard' ), true ) ? $units : 'imperial';
	}

	public function slug() {
		return 'openweather';
	}

	public function is_configured() {
		return '' !== trim( $this->api_key );
	}

	public function fetch_live( $lat, $lng, array $context ) {
		$current = $this->request( 'weather', $lat, $lng );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$forecast = $this->request( 'forecast', $lat, $lng );
		if ( is_wp_error( $forecast ) ) {
			return $forecast;
		}
		return $this->normalize( $current, $forecast, $context, 'live_forecast' );
	}

	public function fetch_extended( $lat, $lng, array $context ) {
		$forecast = $this->request( 'forecast', $lat, $lng );
		if ( is_wp_error( $forecast ) ) {
			return $forecast;
		}
		$current = $this->request( 'weather', $lat, $lng );
		if ( is_wp_error( $current ) ) {
			$current = array();
		}
		return $this->normalize( $current, $forecast, $context, 'extended_forecast' );
	}

	public function fetch_historical( $lat, $lng, array $context ) {
		if ( empty( $context['historical_enabled'] ) ) {
			return array(
				'provider'    => $this->slug(),
				'mode'        => 'historical_risk',
				'current'     => null,
				'event_day'   => null,
				'hourly'      => array(),
				'daily'       => array(),
				'alerts'      => array(),
				'historical'  => true,
				'placeholder' => true,
			);
		}

		$event_date = $context['event_date'] ?? '';
		$ts         = $event_date ? strtotime( $event_date . ' 12:00:00' ) : false;
		if ( ! $ts ) {
			return new WP_Error( 'lnkup_weather_no_date', __( 'Event date required for historical lookup.', 'lnkup-marketplace' ) );
		}

		$url = add_query_arg(
			array(
				'lat'   => $lat,
				'lon'   => $lng,
				'dt'    => $ts,
				'appid' => $this->api_key,
				'units' => $this->units,
			),
			'https://api.openweathermap.org/data/3.0/onecall/timemachine'
		);

		$response = $this->http_get( $url );
		if ( is_wp_error( $response ) ) {
			return array(
				'provider'    => $this->slug(),
				'mode'        => 'historical_risk',
				'current'     => null,
				'event_day'   => null,
				'hourly'      => array(),
				'daily'       => array(),
				'alerts'      => array(),
				'historical'  => true,
				'placeholder' => true,
			);
		}

		$data    = $response['data'][0] ?? array();
		$temp    = isset( $data['temp'] ) ? (float) $data['temp'] : null;
		$weather = $data['weather'][0] ?? array();
		return array(
			'provider'    => $this->slug(),
			'mode'        => 'historical_risk',
			'current'     => null,
			'event_day'   => array(
				'date'        => $event_date,
				'temp_high'   => $temp,
				'temp_low'    => $temp,
				'description' => $weather['description'] ?? '',
				'pop_pct'     => isset( $data['pop'] ) ? (int) round( (float) $data['pop'] * 100 ) : null,
				'icon'        => $weather['icon'] ?? '',
			),
			'hourly'      => array(),
			'daily'       => array(),
			'alerts'      => array(),
			'historical'  => true,
			'placeholder' => false,
			'raw'         => $response,
		);
	}

	public function admin_status() {
		return array(
			'provider'   => $this->slug(),
			'configured' => $this->is_configured(),
			'units'      => $this->units,
			'endpoints'  => array( 'current', 'forecast', 'historical_optional' ),
		);
	}

	/**
	 * @param string $endpoint weather|forecast.
	 * @param float  $lat      Latitude.
	 * @param float  $lng      Longitude.
	 */
	private function request( $endpoint, $lat, $lng ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'lnkup_weather_unconfigured', __( 'OpenWeather API key is not configured.', 'lnkup-marketplace' ) );
		}
		$url = add_query_arg(
			array(
				'lat'   => $lat,
				'lon'   => $lng,
				'appid' => $this->api_key,
				'units' => $this->units,
			),
			self::API_BASE . '/' . $endpoint
		);
		return $this->http_get( $url );
	}

	/**
	 * @param string $url URL.
	 */
	private function http_get( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 12,
			)
		);
		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup OpenWeather request failed: ' . $response->get_error_message() );
			return new WP_Error( 'lnkup_weather_http', __( 'Weather provider request failed.', 'lnkup-marketplace' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup OpenWeather bad response code: ' . $code );
			return new WP_Error( 'lnkup_weather_bad_response', __( 'Weather provider returned an invalid response.', 'lnkup-marketplace' ) );
		}
		if ( isset( $body['cod'] ) && (int) $body['cod'] !== 200 && (int) $body['cod'] !== 0 ) {
			return new WP_Error( 'lnkup_weather_api', __( 'Weather provider rejected the request.', 'lnkup-marketplace' ) );
		}
		return $body;
	}

	/**
	 * @param array  $current  Current weather.
	 * @param array  $forecast Forecast.
	 * @param array  $context  Context.
	 * @param string $mode     Mode slug.
	 */
	private function normalize( $current, $forecast, array $context, $mode ) {
		$event_date = $context['event_date'] ?? '';
		$start_time = $context['start_time'] ?? '12:00';
		$event_ts   = $event_date ? strtotime( trim( $event_date . ' ' . $start_time ) ) : false;
		$hourly     = array();
		$daily_map  = array();
		$event_day  = null;

		if ( ! empty( $forecast['list'] ) && is_array( $forecast['list'] ) ) {
			foreach ( $forecast['list'] as $item ) {
				if ( empty( $item['dt'] ) ) {
					continue;
				}
				$slot = array(
					'timestamp'    => (int) $item['dt'],
					'temp'         => isset( $item['main']['temp'] ) ? (float) $item['main']['temp'] : null,
					'feels_like'   => isset( $item['main']['feels_like'] ) ? (float) $item['main']['feels_like'] : null,
					'humidity'     => isset( $item['main']['humidity'] ) ? (int) $item['main']['humidity'] : null,
					'wind_speed'   => isset( $item['wind']['speed'] ) ? (float) $item['wind']['speed'] : null,
					'wind_gust'    => isset( $item['wind']['gust'] ) ? (float) $item['wind']['gust'] : null,
					'pop_pct'      => isset( $item['pop'] ) ? (int) round( (float) $item['pop'] * 100 ) : 0,
					'description'  => $item['weather'][0]['description'] ?? '',
					'icon'         => $item['weather'][0]['icon'] ?? '',
					'condition_id' => isset( $item['weather'][0]['id'] ) ? (int) $item['weather'][0]['id'] : 0,
				);
				$hourly[] = $slot;
				$day_key  = gmdate( 'Y-m-d', (int) $item['dt'] );
				if ( ! isset( $daily_map[ $day_key ] ) ) {
					$daily_map[ $day_key ] = array(
						'date'        => $day_key,
						'temp_high'   => $slot['temp'],
						'temp_low'    => $slot['temp'],
						'pop_pct'     => $slot['pop_pct'],
						'description' => $slot['description'],
						'icon'        => $slot['icon'],
						'wind_speed'  => $slot['wind_speed'],
						'wind_gust'   => $slot['wind_gust'],
					);
				} else {
					$daily_map[ $day_key ]['temp_high'] = max( $daily_map[ $day_key ]['temp_high'], $slot['temp'] );
					$daily_map[ $day_key ]['temp_low']  = min( $daily_map[ $day_key ]['temp_low'], $slot['temp'] );
					$daily_map[ $day_key ]['pop_pct']   = max( $daily_map[ $day_key ]['pop_pct'], $slot['pop_pct'] );
					if ( $slot['wind_speed'] > ( $daily_map[ $day_key ]['wind_speed'] ?? 0 ) ) {
						$daily_map[ $day_key ]['wind_speed'] = $slot['wind_speed'];
						$daily_map[ $day_key ]['wind_gust']  = $slot['wind_gust'];
					}
				}
				if ( $event_ts && abs( (int) $item['dt'] - $event_ts ) < 3 * HOUR_IN_SECONDS ) {
					$event_day = $daily_map[ $day_key ];
					$event_day['slot_temp'] = $slot['temp'];
					$event_day['slot_pop']  = $slot['pop_pct'];
				}
			}
		}

		if ( ! $event_day && $event_date && isset( $daily_map[ $event_date ] ) ) {
			$event_day = $daily_map[ $event_date ];
		}

		$cur_weather  = $current['weather'][0] ?? array();
		$current_norm = null;
		if ( ! empty( $current['main'] ) ) {
			$current_norm = array(
				'temp'         => isset( $current['main']['temp'] ) ? (float) $current['main']['temp'] : null,
				'feels_like'   => isset( $current['main']['feels_like'] ) ? (float) $current['main']['feels_like'] : null,
				'humidity'     => isset( $current['main']['humidity'] ) ? (int) $current['main']['humidity'] : null,
				'wind_speed'   => isset( $current['wind']['speed'] ) ? (float) $current['wind']['speed'] : null,
				'wind_gust'    => isset( $current['wind']['gust'] ) ? (float) $current['wind']['gust'] : null,
				'description'  => $cur_weather['description'] ?? '',
				'icon'         => $cur_weather['icon'] ?? '',
				'condition_id' => isset( $cur_weather['id'] ) ? (int) $cur_weather['id'] : 0,
				'pop_pct'      => null,
			);
		}

		return array(
			'provider'  => $this->slug(),
			'mode'      => $mode,
			'current'   => $current_norm,
			'event_day' => $event_day,
			'hourly'    => array_slice( $hourly, 0, 16 ),
			'daily'     => array_values( $daily_map ),
			'alerts'    => array(),
			'location'  => array(
				'name' => $current['name'] ?? ( $forecast['city']['name'] ?? '' ),
			),
			'raw'       => array(
				'current'  => $current,
				'forecast' => $forecast,
			),
		);
	}
}
