<?php
/**
 * Intelligence cron jobs.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Intelligence_Cron {

	const HOOK = 'lnkup_intelligence_daily';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_daily' ) );
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'invalidate_event_cache' ), 20, 1 );
		add_action( 'lnkup_event_lifecycle_stage_changed', array( __CLASS__, 'on_lifecycle_change' ), 10, 2 );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function run_daily() {
		$lock_key = 'lnkup_intel_daily_' . gmdate( 'Y-m-d' );
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, DAY_IN_SECONDS );

		LNKup_Intelligence_Snapshots::rollup_daily();
		if ( class_exists( 'LNKup_Event_Score' ) ) {
			LNKup_Event_Score::refresh_all_stale();
		}
		if ( class_exists( 'LNKup_Opportunity_Score' ) ) {
			LNKup_Opportunity_Score::refresh_all_stale();
		}
		if ( class_exists( 'LNKup_Risk_Engine' ) ) {
			LNKup_Risk_Engine::refresh_all_events();
		}
		if ( class_exists( 'LNKup_Recommended_Actions' ) ) {
			LNKup_Recommended_Actions::refresh_all();
		}
		self::generate_post_event_reports();
	}

	public static function invalidate_event_cache( $app_id ) {
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		if ( $event_id ) {
			delete_transient( 'lnkup_intel_event_' . $event_id );
			delete_transient( 'lnkup_intel_funnel_' . $event_id );
		}
	}

	public static function on_lifecycle_change( $event_id, $stage ) {
		if ( 'archived' === $stage && class_exists( 'LNKup_Post_Event_Report' ) ) {
			LNKup_Post_Event_Report::generate( $event_id );
		}
	}

	private static function generate_post_event_reports() {
		if ( ! class_exists( 'LNKup_Post_Event_Report' ) ) {
			return;
		}
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => 20,
				'meta_query'     => array(
					array(
						'key'     => '_lnkup_event_date',
						'value'   => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
						'compare' => '<=',
					),
				),
				'fields'         => 'ids',
			)
		);
		foreach ( $events as $event_id ) {
			LNKup_Post_Event_Report::maybe_generate( $event_id );
		}
	}
}
