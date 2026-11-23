<?php
/**
 * Post-event report generation.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Post_Event_Report {

	public static function maybe_generate( $event_id ) {
		global $wpdb;
		$table = LNKup_Intelligence_Tables::event_reports_table();
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_id = %d", absint( $event_id ) ) );
		if ( $exists ) {
			return self::get( $event_id );
		}
		$date = get_post_meta( $event_id, '_lnkup_event_date', true );
		if ( ! $date || $date > gmdate( 'Y-m-d' ) ) {
			return null;
		}
		return self::generate( $event_id );
	}

	public static function generate( $event_id ) {
		$event_id = absint( $event_id );
		$report   = self::build( $event_id );
		global $wpdb;
		$table = LNKup_Intelligence_Tables::event_reports_table();
		$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		$wpdb->replace(
			$table,
			array(
				'event_id'          => $event_id,
				'organizer_user_id' => $organizer,
				'report'            => wp_json_encode( $report ),
				'generated_at'      => current_time( 'mysql' ),
			)
		);
		return $report;
	}

	public static function get( $event_id ) {
		global $wpdb;
		$table = LNKup_Intelligence_Tables::event_reports_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d", absint( $event_id ) ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['report'] = json_decode( $row['report'] ?? '{}', true );
		return $row;
	}

	private static function build( $event_id ) {
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		$counts = array(
			'applications' => count( $apps ),
			'approved'     => 0,
			'paid'         => 0,
			'checked_in'   => 0,
			'no_shows'     => 0,
			'completed'    => 0,
		);
		foreach ( $apps as $app_id ) {
			$status = get_post_meta( $app_id, '_lnkup_application_status', true );
			if ( in_array( $status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) ) {
				$counts['approved']++;
			}
			if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
				$counts['paid']++;
			}
			if ( get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
				$counts['checked_in']++;
			}
			if ( get_post_meta( $app_id, '_lnkup_no_show_at', true ) ) {
				$counts['no_shows']++;
			}
			if ( 'completed' === $status ) {
				$counts['completed']++;
			}
		}
		$revenue = class_exists( 'LNKup_Revenue_Intelligence' ) ? LNKup_Revenue_Intelligence::for_event( $event_id ) : array();
		$score   = class_exists( 'LNKup_Event_Score' ) ? LNKup_Event_Score::compute( $event_id ) : array();
		$ops     = class_exists( 'LNKup_Ops_Analytics' ) ? LNKup_Ops_Analytics::for_event( $event_id ) : array();
		$top     = class_exists( 'LNKup_Vendor_Reliability_Intel' ) ? LNKup_Vendor_Reliability_Intel::top_vendors( (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ), 3 ) : array();
		return array(
			'event_id'    => $event_id,
			'event_title' => lnkup_plain_title( $event_id ),
			'counts'      => $counts,
			'revenue'     => $revenue,
			'event_score' => $score,
			'operations'  => $ops,
			'top_vendors' => $top,
			'lessons'     => self::lessons( $counts, $score, $ops ),
			'improvements'=> class_exists( 'LNKup_Recommended_Actions' ) ? LNKup_Recommended_Actions::for_event( $event_id ) : array(),
			'generated_at'=> current_time( 'mysql' ),
		);
	}

	private static function lessons( $counts, $score, $ops ) {
		$lessons = array();
		if ( $counts['no_shows'] > 0 ) {
			$lessons[] = __( 'Consider stricter readiness requirements to reduce no-shows.', 'lnkup-marketplace' );
		}
		if ( ! empty( $ops['open_support'] ) ) {
			$lessons[] = __( 'High support volume — add pre-event FAQ to vendor packets.', 'lnkup-marketplace' );
		}
		if ( ! empty( $score['score'] ) && $score['score'] < 65 ) {
			$lessons[] = __( 'Event score suggests room to improve application and payment conversion.', 'lnkup-marketplace' );
		}
		if ( ! $lessons ) {
			$lessons[] = __( 'Strong event execution. Rebook top vendors for your next event.', 'lnkup-marketplace' );
		}
		return $lessons;
	}
}
