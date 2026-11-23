<?php
/**
 * Recommendation quality report: impressions, clicks, applications that followed a recommendation,
 * approvals and paid bookings, by band, section, rules version and rollout cohort.
 *
 * Observed, not causal. Cached for 10 minutes.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Match_Quality {

	const MAX_ROWS = 20000;
	const MAX_VENDORS = 5000;

	const APPROVED_STATUSES = array( 'approved', 'payment_required', 'paid', 'completed' );
	const PAID_STATUSES     = array( 'paid', 'completed' );

	/**
	 * @param int   $days    Look-back window.
	 * @param array $filters Optional rules_version (int), section (string).
	 * @return array<string,mixed>
	 */
	public static function report( $days = 30, array $filters = array() ) {
		$days    = max( 1, min( 180, (int) $days ) );
		$filters = array(
			'rules_version' => isset( $filters['rules_version'] ) && '' !== (string) $filters['rules_version'] ? (int) $filters['rules_version'] : null,
			'section'       => isset( $filters['section'] ) ? sanitize_key( (string) $filters['section'] ) : '',
		);
		$cache_key = 'lnkup_match_quality_' . md5( wp_json_encode( array( $days, $filters ) ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$report = self::build( $days, $filters );
		set_transient( $cache_key, $report, 10 * MINUTE_IN_SECONDS );
		return $report;
	}

	/**
	 * Drop cached reports (tests, after a rules change).
	 */
	public static function flush() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_lnkup\_match\_quality\_%' OR option_name LIKE '\_transient\_timeout\_lnkup\_match\_quality\_%'" );
	}

	private static function build( $days, array $filters ) {
		$since   = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$partial = false;
		$cells   = array();

		foreach ( self::events( 'match_impression', $since, $partial ) as $row ) {
			$data = json_decode( (string) $row['event_data'], true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
				self::add( $cells, $filters, (string) ( $item['band'] ?? '' ), (string) ( $data['section'] ?? '' ), (int) ( $data['rules_version'] ?? 0 ), 'impressions' );
			}
		}
		foreach ( self::events( 'match_click', $since, $partial ) as $row ) {
			$data = json_decode( (string) $row['event_data'], true );
			$data = is_array( $data ) ? $data : array();
			self::add( $cells, $filters, (string) ( $data['band'] ?? '' ), (string) ( $data['section'] ?? '' ), (int) ( $data['rules_version'] ?? 0 ), 'clicks' );
		}
		foreach ( self::events( 'match_rec_application', $since, $partial ) as $row ) {
			$data   = json_decode( (string) $row['event_data'], true );
			$data   = is_array( $data ) ? $data : array();
			$status = (string) get_post_meta( (int) ( $data['application_id'] ?? 0 ), '_lnkup_application_status', true );
			$band   = (string) ( $data['band'] ?? '' );
			$sec    = (string) ( $data['section'] ?? '' );
			$rv     = (int) ( $data['rules_version'] ?? 0 );
			self::add( $cells, $filters, $band, $sec, $rv, 'applications' );
			if ( in_array( $status, self::APPROVED_STATUSES, true ) ) {
				self::add( $cells, $filters, $band, $sec, $rv, 'approved' );
			}
			if ( in_array( $status, self::PAID_STATUSES, true ) ) {
				self::add( $cells, $filters, $band, $sec, $rv, 'paid' );
			}
		}

		$totals = self::empty_row();
		$by     = array( 'band' => array(), 'section' => array(), 'rules_version' => array() );
		foreach ( $cells as $key => $metrics ) {
			list( $band, $section, $rv ) = explode( '|', $key );
			foreach ( array( 'band' => $band, 'section' => $section, 'rules_version' => $rv ) as $dim => $value ) {
				$by[ $dim ][ $value ] = self::sum( $by[ $dim ][ $value ] ?? self::empty_row(), $metrics );
			}
			$totals = self::sum( $totals, $metrics );
		}
		foreach ( $by as $dim => $rows ) {
			ksort( $rows );
			$by[ $dim ] = array_map( array( __CLASS__, 'rates' ), $rows );
		}

		return array(
			'days'               => $days,
			'filters'            => $filters,
			'totals'             => self::rates( $totals ),
			'by_band'            => $by['band'],
			'by_section'         => $by['section'],
			'by_rules_version'   => $by['rules_version'],
			'alert_applications' => self::count_events( 'match_alert_application', $since ),
			'cohort'             => self::cohort_split( $since ),
			'partial'            => $partial,
			'generated_at'       => current_time( 'mysql' ),
		);
	}

	/**
	 * Vendors inside vs. outside the vendor-recommendations audience: applications per vendor and approval rate.
	 */
	private static function cohort_split( $since ) {
		$flag   = 'matching_vendor_discovery_enabled';
		$config = class_exists( 'LNKup_Rollout' ) ? LNKup_Rollout::config( $flag ) : array( 'audience' => 'all', 'percent' => 100 );
		$groups = array(
			'cohort' => array( 'vendors' => 0, 'applications' => 0, 'approved' => 0 ),
			'rest'   => array( 'vendors' => 0, 'applications' => 0, 'approved' => 0 ),
		);
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$vendors = class_exists( 'LNKup_Match_Tables' ) ? array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . LNKup_Match_Tables::vendor_index_table() . " WHERE status = 'active' LIMIT %d", self::MAX_VENDORS ) ) ) : array();
		$in      = array();
		foreach ( $vendors as $vendor ) {
			$group          = self::in_cohort( $flag, $vendor, $config ) ? 'cohort' : 'rest';
			$in[ $vendor ]  = $group;
			++$groups[ $group ]['vendors'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$apps = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.meta_value AS vendor, s.meta_value AS status FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_lnkup_vendor_user_id'
				LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_application_status'
				WHERE p.post_type = 'lnkup_application' AND p.post_status = 'publish' AND p.post_date >= %s LIMIT %d",
				$since,
				self::MAX_ROWS
			),
			ARRAY_A
		);
		foreach ( $apps as $app ) {
			$group = $in[ (int) $app['vendor'] ] ?? null;
			if ( null === $group ) {
				continue;
			}
			++$groups[ $group ]['applications'];
			if ( in_array( (string) $app['status'], self::APPROVED_STATUSES, true ) ) {
				++$groups[ $group ]['approved'];
			}
		}
		foreach ( $groups as $key => $row ) {
			$groups[ $key ]['applications_per_vendor'] = $row['vendors'] ? round( $row['applications'] / $row['vendors'], 3 ) : null;
			$groups[ $key ]['approval_rate']           = $row['applications'] ? round( 100 * $row['approved'] / $row['applications'], 1 ) : null;
		}
		return array(
			'flag'     => $flag,
			'audience' => $config['audience'],
			'percent'  => 'all' === $config['audience'] ? 100 : (int) $config['percent'],
			'groups'   => $groups,
		);
	}

	/**
	 * Cohort membership without the admin bypass (admins are not real vendors in the comparison).
	 */
	private static function in_cohort( $flag, $vendor, array $config ) {
		if ( 'all' === $config['audience'] ) {
			return true;
		}
		return in_array( $vendor, (array) ( $config['user_ids'] ?? array() ), true )
			|| LNKup_Rollout::bucket( LNKup_Rollout::FLAGS[ $flag ]['group'], $vendor ) < (int) $config['percent'];
	}

	private static function events( $type, $since, &$partial ) {
		global $wpdb;
		$table = LNKup_Marketplace_Analytics::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT event_data FROM {$table} WHERE event_type = %s AND created_at >= %s ORDER BY id DESC LIMIT %d", $type, $since, self::MAX_ROWS + 1 ), ARRAY_A );
		if ( count( $rows ) > self::MAX_ROWS ) {
			$partial = true;
			array_pop( $rows );
		}
		return $rows;
	}

	private static function count_events( $type, $since ) {
		global $wpdb;
		$table = LNKup_Marketplace_Analytics::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND created_at >= %s", $type, $since ) );
	}

	private static function add( array &$cells, array $filters, $band, $section, $rules_version, $metric ) {
		if ( null !== $filters['rules_version'] && (int) $rules_version !== $filters['rules_version'] ) {
			return;
		}
		if ( '' !== $filters['section'] && $section !== $filters['section'] ) {
			return;
		}
		$band = in_array( $band, array( 'strong', 'good', 'possible' ), true ) ? $band : 'unknown';
		$key  = $band . '|' . ( '' !== $section ? sanitize_key( $section ) : 'unknown' ) . '|' . (int) $rules_version;
		if ( ! isset( $cells[ $key ] ) ) {
			$cells[ $key ] = self::empty_row();
		}
		++$cells[ $key ][ $metric ];
	}

	private static function empty_row() {
		return array( 'impressions' => 0, 'clicks' => 0, 'applications' => 0, 'approved' => 0, 'paid' => 0 );
	}

	private static function sum( array $a, array $b ) {
		foreach ( $b as $key => $value ) {
			$a[ $key ] = (int) ( $a[ $key ] ?? 0 ) + (int) $value;
		}
		return $a;
	}

	/**
	 * Add CTR, apply-per-click and approval rates (percentages, null when undefined).
	 */
	public static function rates( array $row ) {
		$row['ctr']           = $row['impressions'] ? round( 100 * $row['clicks'] / $row['impressions'], 1 ) : null;
		$row['apply_rate']    = $row['impressions'] ? round( 100 * $row['applications'] / $row['impressions'], 2 ) : null;
		$row['approval_rate'] = $row['applications'] ? round( 100 * $row['approved'] / $row['applications'], 1 ) : null;
		return $row;
	}
}
