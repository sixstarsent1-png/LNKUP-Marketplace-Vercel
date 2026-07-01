<?php
/**
 * Rule-based vendor category recommendations for external events.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Events_Recommendations {

	/**
	 * Event type → recommended vendor categories.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function type_map(): array {
		return array(
			'festivals'     => array( 'Food Truck', 'Coffee', 'Desserts', 'Retail', 'Fashion' ),
			'festival'      => array( 'Food Truck', 'Coffee', 'Desserts', 'Retail' ),
			'markets'       => array( 'Retail', 'Fashion', 'Food Truck', 'Handmade' ),
			'market'        => array( 'Retail', 'Fashion', 'Food Truck' ),
			'food'          => array( 'Food Truck', 'Coffee', 'Desserts', 'Catering' ),
			'conference'    => array( 'Coffee', 'Retail', 'Service Businesses' ),
			'expo'          => array( 'Retail', 'Service Businesses', 'Fashion' ),
			'corporate'     => array( 'Service Businesses', 'Retail', 'Coffee' ),
		);
	}

	/**
	 * @param array<string, mixed> $row External event row.
	 * @return array<int, string>
	 */
	public static function for_event( array $row ): array {
		$type = strtolower( (string) ( $row['event_type'] ?? '' ) );
		$map  = self::type_map();
		foreach ( $map as $key => $cats ) {
			if ( str_contains( $type, $key ) ) {
				return $cats;
			}
		}
		return array( 'Food Truck', 'Retail', 'Service Businesses' );
	}

	/**
	 * @param int $external_id External event ID.
	 * @return array<int, string>
	 */
	public static function for_external_id( int $external_id ): array {
		$row = LNKup_External_Events::get_row( $external_id );
		return $row ? self::for_event( $row ) : array();
	}
}
