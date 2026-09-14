<?php
/**
 * Marketplace analytics tracking and reporting.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Marketplace_Analytics {

	const TABLE = 'lnkup_analytics_events';

	/**
	 * Init hooks.
	 */
	public static function init() {
		// No front hooks; REST handles tracking.
	}

	/**
	 * Table name.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create analytics table.
	 */
	public static function create_tables() {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL DEFAULT '',
			event_data text,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Track an event.
	 *
	 * @param string $type Event type.
	 * @param array  $data Extra data.
	 * @param int    $user_id User ID.
	 * @param int    $opp_id Opportunity ID.
	 */
	public static function track( $type, $data = array(), $user_id = 0, $opp_id = 0 ) {
		global $wpdb;
		try {
			$wpdb->insert(
				self::table_name(),
				array(
					'event_type'     => sanitize_key( $type ),
					'event_data'     => wp_json_encode( $data ),
					'user_id'        => $user_id ?: get_current_user_id(),
					'opportunity_id' => absint( $opp_id ),
					'created_at'     => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%d', '%d', '%s' )
			);

			if ( 'search' === $type && ! empty( $data['q'] ) ) {
				self::bump_popular_search( $data['q'] );
			}
			if ( 'search_no_results' === $type && ! empty( $data['q'] ) ) {
				$zero = self::option_array( 'lnkup_zero_result_searches' );
				$key  = sanitize_text_field( (string) $data['q'] );
				if ( '' !== $key ) {
					$zero[ $key ] = (int) ( $zero[ $key ] ?? 0 ) + 1;
					arsort( $zero );
					update_option( 'lnkup_zero_result_searches', array_slice( $zero, 0, 50, true ) );
				}
			}
			if ( 'filter_use' === $type && ! empty( $data['filter'] ) ) {
				self::bump_filter_usage( $data['filter'] );
			}
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never let analytics crash Find / marketplace search.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'LNKup_Marketplace_Analytics::track failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Coerce a wp_option to an array (corrupt/scalar options must not TypeError).
	 *
	 * @param string $option Option name.
	 * @return array
	 */
	private static function option_array( $option ) {
		$value = get_option( $option, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Bump filter usage counter.
	 *
	 * @param string $filter Filter key.
	 */
	private static function bump_filter_usage( $filter ) {
		$usage = self::option_array( 'lnkup_filter_usage' );
		$key   = sanitize_key( $filter );
		if ( '' === $key ) {
			return;
		}
		$usage[ $key ] = (int) ( $usage[ $key ] ?? 0 ) + 1;
		arsort( $usage );
		update_option( 'lnkup_filter_usage', array_slice( $usage, 0, 30, true ) );
	}

	/**
	 * Bump popular search counter.
	 *
	 * @param string $q Query.
	 */
	private static function bump_popular_search( $q ) {
		$popular = self::option_array( 'lnkup_popular_searches' );
		$q       = sanitize_text_field( (string) $q );
		if ( '' === $q ) {
			return;
		}
		// Legacy / corrupt rows may be associative maps — keep list of strings only.
		$list = array();
		foreach ( $popular as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$list[] = $item;
			} elseif ( is_array( $item ) && ! empty( $item['q'] ) ) {
				$list[] = sanitize_text_field( (string) $item['q'] );
			}
		}
		if ( ! in_array( $q, $list, true ) ) {
			array_unshift( $list, $q );
		}
		update_option( 'lnkup_popular_searches', array_values( array_slice( array_unique( $list ), 0, 20 ) ) );
	}

	/**
	 * Admin dashboard metrics.
	 */
	public static function get_dashboard_metrics() {
		global $wpdb;
		$table = self::table_name();
		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );

		$searches = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = 'search' AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$clicks   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = 'card_click' AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$favorites = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = 'favorite' AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reco_clicks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = 'recommendation_click' AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$city_rows = $wpdb->get_results( $wpdb->prepare( "SELECT event_data FROM {$table} WHERE event_type = 'search' AND created_at >= %s LIMIT 500", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cities = array();
		foreach ( (array) $city_rows as $row ) {
			$data = json_decode( $row['event_data'], true );
			if ( ! empty( $data['city'] ) ) {
				$c = $data['city'];
				$cities[ $c ] = ( $cities[ $c ] ?? 0 ) + 1;
			}
		}
		arsort( $cities );

		return array(
			'searches_30d'        => $searches,
			'card_clicks_30d'     => $clicks,
			'favorites_30d'      => $favorites,
			'recommendation_ctr' => $searches > 0 ? round( ( $reco_clicks / $searches ) * 100, 1 ) : 0,
			'top_cities'         => array_slice( $cities, 0, 10, true ),
			'zero_result_searches' => get_option( 'lnkup_zero_result_searches', array() ),
			'popular_searches'   => get_option( 'lnkup_popular_searches', array() ),
			'popular_categories' => LNKup_Trending::get_popular_categories( 10 ),
			'filter_usage'       => get_option( 'lnkup_filter_usage', array() ),
		);
	}
}
