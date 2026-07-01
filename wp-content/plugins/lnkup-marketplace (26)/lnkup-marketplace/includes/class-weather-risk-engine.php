<?php
/**
 * Weather risk scoring and recommendations.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Weather_Risk_Engine {

	const LEVELS = array( 'low', 'medium', 'high', 'unavailable' );

	/**
	 * Evaluate risks from normalized weather + event context.
	 *
	 * @param array $weather Normalized provider payload.
	 * @param array $context Event context.
	 */
	public static function evaluate( array $weather, array $context ) {
		$mode     = $weather['mode'] ?? 'unavailable';
		$current  = $weather['current'] ?? null;
		$event_day = $weather['event_day'] ?? null;
		$is_outdoor = ! empty( $context['outdoor'] ) && empty( $context['indoor_only'] );

		if ( ! empty( $weather['placeholder'] ) ) {
			return self::unavailable_result(
				'historical',
				array(
					__( 'Live forecast is not available yet. Add historical weather API access to unlock seasonal risk.', 'lnkup-marketplace' ),
					__( 'Monitor forecast again 7 days before event.', 'lnkup-marketplace' ),
				)
			);
		}

		$metrics = self::extract_metrics( $current, $event_day );
		if ( empty( $metrics ) ) {
			return self::unavailable_result( self::confidence_for_mode( $mode ) );
		}

		$rain_risk  = self::rain_risk( $metrics['pop_pct'] );
		$wind_risk  = self::wind_risk( $metrics['wind_speed'], $metrics['wind_gust'] );
		$heat_risk  = self::heat_risk( $metrics['temp'], $metrics['feels_like'] );
		$storm_risk = self::storm_risk( $metrics['condition_id'], $metrics['description'], $weather['alerts'] ?? array() );
		$setup_risk = self::setup_risk( $rain_risk, $wind_risk, $storm_risk, $is_outdoor );

		$risks = array(
			'overall_risk' => self::max_risk( array( $rain_risk, $wind_risk, $heat_risk, $storm_risk, $setup_risk ) ),
			'rain_risk'      => $rain_risk,
			'wind_risk'      => $wind_risk,
			'heat_risk'      => $heat_risk,
			'storm_risk'     => $storm_risk,
			'setup_risk'     => $setup_risk,
			'confidence'     => self::confidence_for_mode( $mode ),
			'recommendations'=> self::recommendations( $rain_risk, $wind_risk, $heat_risk, $storm_risk, $setup_risk, $mode, $is_outdoor ),
		);
		return $risks;
	}

	/**
	 * @param array|null $current   Current block.
	 * @param array|null $event_day Event day block.
	 */
	private static function extract_metrics( $current, $event_day ) {
		$src = is_array( $event_day ) ? $event_day : ( is_array( $current ) ? $current : null );
		if ( ! $src ) {
			return array();
		}
		$temp = $src['slot_temp'] ?? $src['temp'] ?? $src['temp_high'] ?? null;
		return array(
			'temp'         => $temp,
			'feels_like'   => $src['feels_like'] ?? $temp,
			'pop_pct'      => $src['slot_pop'] ?? $src['pop_pct'] ?? null,
			'wind_speed'   => $src['wind_speed'] ?? null,
			'wind_gust'    => $src['wind_gust'] ?? null,
			'condition_id' => $src['condition_id'] ?? 0,
			'description'  => strtolower( (string) ( $src['description'] ?? '' ) ),
		);
	}

	private static function rain_risk( $pop ) {
		if ( null === $pop ) {
			return 'unavailable';
		}
		$pop = (int) $pop;
		if ( $pop >= 60 ) {
			return 'high';
		}
		if ( $pop >= 35 ) {
			return 'medium';
		}
		return 'low';
	}

	private static function wind_risk( $speed, $gust ) {
		$speed = (float) ( $speed ?? 0 );
		$gust  = (float) ( $gust ?? 0 );
		if ( $speed <= 0 && $gust <= 0 ) {
			return 'unavailable';
		}
		if ( $speed >= 20 || $gust >= 30 ) {
			return 'high';
		}
		if ( $speed >= 12 || $gust >= 20 ) {
			return 'medium';
		}
		return 'low';
	}

	private static function heat_risk( $temp, $feels_like ) {
		$val = max( (float) ( $temp ?? 0 ), (float) ( $feels_like ?? 0 ) );
		if ( $val <= 0 ) {
			return 'unavailable';
		}
		if ( $val >= 95 ) {
			return 'high';
		}
		if ( $val >= 88 ) {
			return 'medium';
		}
		return 'low';
	}

	private static function storm_risk( $condition_id, $description, array $alerts ) {
		if ( ! empty( $alerts ) ) {
			return 'high';
		}
		$id = (int) $condition_id;
		if ( ( $id >= 200 && $id < 300 ) || false !== strpos( $description, 'thunder' ) || false !== strpos( $description, 'storm' ) ) {
			return 'high';
		}
		if ( false !== strpos( $description, 'rain' ) && false !== strpos( $description, 'heavy' ) ) {
			return 'medium';
		}
		return 'low';
	}

	private static function setup_risk( $rain, $wind, $storm, $is_outdoor ) {
		if ( ! $is_outdoor ) {
			return 'low';
		}
		return self::max_risk( array( $rain, $wind, $storm ) );
	}

	private static function max_risk( array $levels ) {
		$order = array( 'unavailable' => 0, 'low' => 1, 'medium' => 2, 'high' => 3 );
		$max   = 'unavailable';
		foreach ( $levels as $level ) {
			if ( ( $order[ $level ] ?? 0 ) > ( $order[ $max ] ?? 0 ) ) {
				$max = $level;
			}
		}
		return $max;
	}

	private static function confidence_for_mode( $mode ) {
		switch ( $mode ) {
			case 'live_forecast':
				return 'live';
			case 'extended_forecast':
				return 'extended';
			case 'historical_risk':
				return 'historical';
			default:
				return 'unavailable';
		}
	}

	private static function unavailable_result( $confidence = 'unavailable', array $recommendations = array() ) {
		return array(
			'overall_risk'    => 'unavailable',
			'rain_risk'       => 'unavailable',
			'wind_risk'       => 'unavailable',
			'heat_risk'       => 'unavailable',
			'storm_risk'      => 'unavailable',
			'setup_risk'      => 'unavailable',
			'confidence'      => $confidence,
			'recommendations' => $recommendations,
		);
	}

	private static function recommendations( $rain, $wind, $heat, $storm, $setup, $mode, $is_outdoor ) {
		$items = array();
		if ( in_array( $mode, array( 'extended_forecast', 'historical_risk' ), true ) ) {
			$items[] = __( 'Monitor forecast again 7 days before event.', 'lnkup-marketplace' );
		}
		if ( 'historical_risk' === $mode ) {
			$items[] = __( 'Live forecast is not available yet; use historical risk for planning.', 'lnkup-marketplace' );
		}
		if ( in_array( $rain, array( 'medium', 'high' ), true ) ) {
			$items[] = __( 'Prepare a rain load-in plan.', 'lnkup-marketplace' );
			$items[] = __( 'Consider floor covering or mats near vendor setup areas.', 'lnkup-marketplace' );
		}
		if ( in_array( $wind, array( 'medium', 'high' ), true ) || in_array( $storm, array( 'medium', 'high' ), true ) ) {
			$items[] = __( 'Review tent weights and vendor booth anchoring.', 'lnkup-marketplace' );
			$items[] = __( 'Secure signage and lightweight displays.', 'lnkup-marketplace' );
		}
		if ( in_array( $heat, array( 'medium', 'high' ), true ) ) {
			$items[] = __( 'Add water station reminders for vendors.', 'lnkup-marketplace' );
		}
		if ( in_array( $setup, array( 'medium', 'high' ), true ) && $is_outdoor ) {
			$items[] = __( 'Weather risk is elevated for outdoor setup.', 'lnkup-marketplace' );
			$items[] = __( 'Review tent, load-in, and backup plans.', 'lnkup-marketplace' );
		}
		return array_values( array_unique( $items ) );
	}
}
