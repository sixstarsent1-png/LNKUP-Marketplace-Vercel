<?php
/**
 * Operations analytics aggregates.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Ops_Analytics {

	/**
	 * Average check-in time label for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function avg_checkin_time( $event_id ) {
		if ( ! $event_id ) {
			return '—';
		}
		global $wpdb;
		$table = LNKup_Operations_Tables::checkins_table();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND action = 'checked_in'", absint( $event_id ) )
		);
		return $count ? sprintf( _n( '%d check-in', '%d check-ins', $count, 'lnkup-marketplace' ), $count ) : '—';
	}

	/**
	 * Full analytics for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function for_event( $event_id ) {
		global $wpdb;
		$checkins = LNKup_Operations_Tables::checkins_table();
		$support  = LNKup_Operations_Tables::support_table();
		$incidents = LNKup_Operations_Tables::incidents_table();

		$checked_in = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$checkins} WHERE event_id = %d AND action = 'checked_in'", absint( $event_id ) ) );
		$setup_done = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$checkins} WHERE event_id = %d AND action = 'setup_complete'", absint( $event_id ) ) );
		$late = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$checkins} WHERE event_id = %d AND action = 'late_arrival'", absint( $event_id ) ) );
		$no_show = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$checkins} WHERE event_id = %d AND action = 'no_show'", absint( $event_id ) ) );
		$support_open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$support} WHERE event_id = %d AND status = 'open'", absint( $event_id ) ) );
		$support_resolved = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$support} WHERE event_id = %d AND status = 'resolved'", absint( $event_id ) ) );
		$incidents_open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$incidents} WHERE event_id = %d AND status = 'open'", absint( $event_id ) ) );

		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => absint( $event_id ),
				'fields'         => 'ids',
			)
		);
		$readiness_sum = 0;
		foreach ( $apps as $app_id ) {
			$readiness_sum += LNKup_Vendor_Readiness::checklist( $app_id )['percent'];
		}
		$readiness_pct = $apps ? round( $readiness_sum / count( $apps ) ) : 0;

		return array(
			'checked_in'           => $checked_in,
			'setup_complete'       => $setup_done,
			'late_vendors'         => $late,
			'no_shows'             => $no_show,
			'readiness_pct'        => $readiness_pct,
			'support_open'         => $support_open,
			'support_resolved'     => $support_resolved,
			'incidents_open'       => $incidents_open,
			'power_requests'       => self::support_by_category( $event_id, 'power' ),
			'water_requests'       => self::support_by_category( $event_id, 'water' ),
		);
	}

	/**
	 * Support count by category.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $category Category slug.
	 */
	private static function support_by_category( $event_id, $category ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::support_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND category = %s", absint( $event_id ), sanitize_key( $category ) )
		);
	}

	/**
	 * Platform-wide ops health for admin.
	 */
	public static function platform_health() {
		global $wpdb;
		$support = LNKup_Operations_Tables::support_table();
		$incidents = LNKup_Operations_Tables::incidents_table();
		return array(
			'open_support'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$support} WHERE status = 'open'" ),
			'open_incidents'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$incidents} WHERE status = 'open'" ),
			'events_today'    => self::events_today_count(),
		);
	}

	/**
	 * Count events happening today.
	 */
	private static function events_today_count() {
		$today = gmdate( 'Y-m-d' );
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_lnkup_event_date',
						'value' => $today,
					),
				),
			)
		);
		return count( $events );
	}
}
