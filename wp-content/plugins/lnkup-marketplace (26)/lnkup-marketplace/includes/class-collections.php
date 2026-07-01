<?php
/**
 * Curated marketplace collections.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Collections {

	/**
	 * Collection definitions.
	 */
	public static function get_definitions() {
		return array(
			'food-festivals' => array(
				'label'   => __( 'Food Festivals', 'lnkup-marketplace' ),
				'filters' => array( 'event_types' => array( 'Festivals', 'Food Festival', 'Festival' ), 'categories' => array( 'Food Vendor', 'Food Truck' ) ),
			),
			'college-events' => array(
				'label'   => __( 'Best College Events', 'lnkup-marketplace' ),
				'filters' => array( 'event_types' => array( 'Campus Events', 'College Event', 'Greek Event', 'Homecoming' ) ),
			),
			'top-rodeos' => array(
				'label'   => __( 'Top Rodeos', 'lnkup-marketplace' ),
				'filters' => array( 'event_types' => array( 'Rodeo' ) ),
			),
			'weekend' => array(
				'label'   => __( 'Weekend Opportunities', 'lnkup-marketplace' ),
				'filters' => array( 'date_preset' => 'week' ),
			),
			'under-100' => array(
				'label'   => __( 'Under $100', 'lnkup-marketplace' ),
				'filters' => array( 'fee_max' => 100 ),
			),
			'large-festivals' => array(
				'label'   => __( 'Large Festivals', 'lnkup-marketplace' ),
				'filters' => array( 'attendance_min' => 10000 ),
			),
			'indoor-markets' => array(
				'label'   => __( 'Indoor Markets', 'lnkup-marketplace' ),
				'filters' => array( 'indoor' => true, 'event_types' => array( 'Markets & Pop-Ups', 'Market' ) ),
			),
			'family-events' => array(
				'label'   => __( 'Family Events', 'lnkup-marketplace' ),
				'filters' => array( 'event_types' => array( 'Community Events', 'Family Event', 'Festival' ) ),
			),
			'outdoor' => array(
				'label'   => __( 'Outdoor Events', 'lnkup-marketplace' ),
				'filters' => array( 'outdoor' => true ),
			),
			'closing-soon' => array(
				'label'   => __( 'Closing Soon', 'lnkup-marketplace' ),
				'section' => 'closing_soon',
			),
		);
	}

	/**
	 * Get collection items.
	 *
	 * @param string $slug  Collection slug.
	 * @param int    $limit Limit.
	 */
	public static function get( $slug, $limit = 12 ) {
		$defs = self::get_definitions();
		if ( empty( $defs[ $slug ] ) ) {
			return array();
		}

		$def = $defs[ $slug ];
		$cache_key = 'lnkup_coll_' . $slug . '_' . $limit;
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		if ( ! empty( $def['section'] ) ) {
			$items = LNKup_Trending::get_section( $def['section'], $limit );
		} else {
			$result = LNKup_Discovery::search( array_merge( $def['filters'] ?? array(), array( 'per_page' => $limit ) ) );
			$items  = $result['items'];
		}

		set_transient( $cache_key, $items, 15 * MINUTE_IN_SECONDS );
		return $items;
	}

	/**
	 * List all collections with counts.
	 */
	public static function list_all() {
		$list = array();
		foreach ( self::get_definitions() as $slug => $def ) {
			$items = self::get( $slug, 1 );
			$list[] = array(
				'slug'  => $slug,
				'label' => $def['label'],
				'count' => count( self::get( $slug, 24 ) ),
			);
		}
		return $list;
	}
}
