<?php
/**
 * Location-based map insights.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Map_Insights {

	/**
	 * Insights for event map.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function for_event( $event_id ) {
		$insights = array();
		$booths   = class_exists( 'LNKup_Booths' ) ? LNKup_Booths::get_for_event( $event_id ) : array();
		$assigned = array_filter( $booths, function ( $b ) { return ! empty( $b['application_id'] ); } );
		$power    = array_filter( $booths, function ( $b ) { return ! empty( $b['has_power'] ); } );
		$power_used = array_filter( $power, function ( $b ) { return ! empty( $b['application_id'] ); } );

		if ( count( $booths ) > 0 && count( $power ) > 0 ) {
			$pct = round( ( count( $power_used ) / max( 1, count( $power ) ) ) * 100 );
			if ( $pct >= 80 ) {
				$insights[] = array(
					'type'  => 'warning',
					'title' => __( 'Power booths filling up', 'lnkup-marketplace' ),
					'body'  => sprintf(
						/* translators: %d percent */
						__( 'Power booths are %d%% full.', 'lnkup-marketplace' ),
						$pct
					),
				);
			}
		}

		$elements = array();
		$map = class_exists( 'LNKup_Maps' ) ? LNKup_Maps::get_for_event( $event_id ) : null;
		if ( $map ) {
			$elements = LNKup_Map_Elements::get_for_map( $map['id'], 'organizer' );
		}
		$load_in = array_filter( $elements, function ( $e ) { return 'load_in' === $e['element_type']; } );
		$near_entrance = 0;
		foreach ( $assigned as $booth ) {
			if ( $booth['pos_x'] > 0 && $booth['pos_x'] < 25 ) {
				$near_entrance++;
			}
		}
		if ( $near_entrance >= 8 ) {
			$insights[] = array(
				'type'  => 'warning',
				'title' => __( 'Entrance congestion', 'lnkup-marketplace' ),
				'body'  => sprintf(
					/* translators: %d vendor count */
					__( 'This event has %d vendors assigned near one entrance.', 'lnkup-marketplace' ),
					$near_entrance
				),
			);
		}
		if ( $load_in ) {
			$insights[] = array(
				'type'  => 'info',
				'title' => __( 'Load-in routing', 'lnkup-marketplace' ),
				'body'  => __( 'Review load-in path for potential congestion near vendor entrance.', 'lnkup-marketplace' ),
			);
		}

		return $insights;
	}

	/**
	 * Marketplace city-level insights for admin map.
	 */
	public static function marketplace_cities() {
		$cities = class_exists( 'LNKup_Market_Trends' ) ? LNKup_Market_Trends::cities( array( 'limit' => 20 ) ) : array();
		$insights = array();
		foreach ( $cities as $city ) {
			if ( ! empty( $city['insight'] ) ) {
				$insights[] = array(
					'city'    => $city['label'] ?? '',
					'insight' => $city['insight'],
					'apps'    => $city['applications'] ?? 0,
				);
			}
		}
		return $insights;
	}

	/**
	 * City aggregates for admin map pins.
	 */
	public static function city_aggregates() {
		global $wpdb;
		$table = LNKup_Marketplace_Index::table_name();
		$rows  = $wpdb->get_results(
			"SELECT city, state, AVG(lat) as lat, AVG(lng) as lng, COUNT(*) as opportunities, SUM(application_count) as applications, SUM(vendor_fee * application_count) as revenue_proxy FROM {$table} WHERE city != '' AND lat IS NOT NULL GROUP BY city, state ORDER BY opportunities DESC LIMIT 50",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = array();
		foreach ( (array) $rows as $row ) {
			$city  = (string) $row['city'];
			$state = (string) $row['state'];
			if ( class_exists( 'LNKup_Location_Service' ) ) {
				// Merge spelling variants of the same city into one pin.
				$r = LNKup_Location_Service::resolve( $city, $state );
				if ( in_array( $r['status'], array( LNKup_Location_Service::VERIFIED, LNKup_Location_Service::NORMALIZED ), true ) && '' !== $r['state_code'] ) {
					$city  = (string) $r['city'];
					$state = (string) $r['state_code'];
				}
			}
			$key = strtolower( $city ) . '|' . strtoupper( $state );
			if ( isset( $items[ $key ] ) ) {
				$n                               = $items[ $key ]['opportunities'] + (int) $row['opportunities'];
				$items[ $key ]['lat']            = ( $items[ $key ]['lat'] * $items[ $key ]['opportunities'] + (float) $row['lat'] * (int) $row['opportunities'] ) / max( 1, $n );
				$items[ $key ]['lng']            = ( $items[ $key ]['lng'] * $items[ $key ]['opportunities'] + (float) $row['lng'] * (int) $row['opportunities'] ) / max( 1, $n );
				$items[ $key ]['opportunities']  = $n;
				$items[ $key ]['applications']  += (int) $row['applications'];
				$items[ $key ]['revenue_proxy'] += (float) $row['revenue_proxy'];
				continue;
			}
			$items[ $key ] = array(
				'city'          => $city,
				'state'         => $state,
				'label'         => trim( $city . ', ' . $state ),
				'lat'           => (float) $row['lat'],
				'lng'           => (float) $row['lng'],
				'opportunities' => (int) $row['opportunities'],
				'applications'  => (int) $row['applications'],
				'revenue_proxy' => (float) $row['revenue_proxy'],
			);
		}
		return array_values( $items );
	}
}
