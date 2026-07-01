<?php
/**
 * Category and city market trends.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Market_Trends {

	/**
	 * Category demand trends.
	 *
	 * @param array $args Filters.
	 */
	public static function categories( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'organizer_id' => 0,
				'city'         => '',
				'limit'        => 10,
			)
		);
		global $wpdb;
		$table = LNKup_Marketplace_Index::table_name();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$where = '1=1';
		$params = array();
		if ( $args['organizer_id'] ) {
			$where .= ' AND organizer_user_id = %d';
			$params[] = absint( $args['organizer_id'] );
		}
		if ( $args['city'] ) {
			$where .= ' AND city = %s';
			$params[] = sanitize_text_field( $args['city'] );
		}
		$sql = "SELECT categories, application_count, spots_total, spots_remaining FROM {$table} WHERE {$where}";
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$agg  = array();
		foreach ( $rows as $row ) {
			$cats = json_decode( $row['categories'] ?? '[]', true );
			if ( ! is_array( $cats ) ) {
				continue;
			}
			foreach ( $cats as $cat ) {
				if ( ! isset( $agg[ $cat ] ) ) {
					$agg[ $cat ] = array(
						'category'     => $cat,
						'applications' => 0,
						'spots_total'  => 0,
						'spots_remaining' => 0,
					);
				}
				$agg[ $cat ]['applications'] += (int) $row['application_count'];
				$agg[ $cat ]['spots_total'] += (int) $row['spots_total'];
				$agg[ $cat ]['spots_remaining'] += (int) $row['spots_remaining'];
			}
		}
		foreach ( $agg as &$item ) {
			$filled = max( 0, $item['spots_total'] - $item['spots_remaining'] );
			$item['fill_rate'] = $item['spots_total'] ? round( ( $filled / $item['spots_total'] ) * 100, 1 ) : 0;
			if ( $item['fill_rate'] >= 90 ) {
				$item['status'] = 'full';
				$item['insight'] = sprintf( __( '%s vendors are full.', 'lnkup-marketplace' ), $item['category'] );
			} elseif ( $item['fill_rate'] < 40 && $item['applications'] > 2 ) {
				$item['status'] = 'shortage';
				$item['insight'] = sprintf( __( '%s vendors needed.', 'lnkup-marketplace' ), $item['category'] );
			} elseif ( $item['fill_rate'] > 75 && $item['applications'] < 3 ) {
				$item['status'] = 'oversupply';
				$item['insight'] = sprintf( __( '%s may be oversaturated.', 'lnkup-marketplace' ), $item['category'] );
			} else {
				$item['status'] = 'balanced';
				$item['insight'] = '';
			}
		}
		usort(
			$agg,
			function ( $a, $b ) {
				return $b['applications'] <=> $a['applications'];
			}
		);
		return array_slice( array_values( $agg ), 0, absint( $args['limit'] ) );
	}

	/**
	 * City/market trends.
	 *
	 * @param array $args Filters.
	 */
	public static function cities( $args = array() ) {
		$args = wp_parse_args( $args, array( 'organizer_id' => 0, 'limit' => 10 ) );
		global $wpdb;
		$table = LNKup_Marketplace_Index::table_name();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$where = "city != ''";
		$params = array();
		if ( $args['organizer_id'] ) {
			$where .= ' AND organizer_user_id = %d';
			$params[] = absint( $args['organizer_id'] );
		}
		$sql = "SELECT city, state, COUNT(*) as active_events, SUM(application_count) as applications, SUM(spots_total - spots_remaining) as paid_bookings FROM {$table} WHERE {$where} GROUP BY city, state ORDER BY applications DESC LIMIT %d";
		$params[] = absint( $args['limit'] );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['label'] = trim( $row['city'] . ( $row['state'] ? ', ' . $row['state'] : '' ) );
			$row['insight'] = self::city_insight( $row );
		}
		return $rows ?: array();
	}

	private static function city_insight( $row ) {
		if ( (int) $row['applications'] > 20 && (int) $row['paid_bookings'] < 5 ) {
			return sprintf(
				__( '%s has high demand but lower paid bookings — follow up on payments.', 'lnkup-marketplace' ),
				$row['label']
			);
		}
		return '';
	}
}
