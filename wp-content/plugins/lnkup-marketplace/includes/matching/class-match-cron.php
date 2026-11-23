<?php
/**
 * Incremental match recomputation (dirty queue) and nightly rebuild.
 *
 * Saves mark vendors/opportunities dirty; a single scheduled worker drains
 * the queue in bounded batches so no page load does matching work.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Match_Cron {

	const QUEUE_HOOK    = 'lnkup_match_process_queue';
	const BACKFILL_HOOK = 'lnkup_match_backfill';
	const DAILY_HOOK    = 'lnkup_match_daily';
	const STATUS_OPTION = 'lnkup_match_job_status';

	const VENDOR_BATCH = 25;
	const OPP_BATCH    = 5;
	const TIME_BUDGET  = 20;

	public static function init() {
		add_action( self::QUEUE_HOOK, array( __CLASS__, 'process_queue' ) );
		add_action( self::BACKFILL_HOOK, array( __CLASS__, 'process_backfill' ) );
		add_action( self::DAILY_HOOK, array( __CLASS__, 'daily' ) );
		add_action( 'lnkup_marketplace_index_changed', array( __CLASS__, 'on_index_changed' ), 20, 2 );
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'on_application' ) );
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'on_application' ) );
		add_action( 'lnkup_application_withdrawn', array( __CLASS__, 'on_application' ) );
		add_action( 'lnkup_application_reapply_allowed', array( __CLASS__, 'on_application' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/**
	 * Schedule the nightly rebuild.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 04:30' ), 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Queue an entity for recomputation.
	 *
	 * @param string $type vendor|opportunity.
	 * @param int    $id   Profile or opportunity ID.
	 */
	public static function mark_dirty( $type, $id ) {
		if ( ! in_array( $type, array( 'vendor', 'opportunity' ), true ) || ! (int) $id ) {
			return;
		}
		global $wpdb;
		$table = LNKup_Match_Tables::dirty_table();
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"INSERT INTO {$table} (entity_type, entity_id, queued_at) VALUES (%s, %d, %s) ON DUPLICATE KEY UPDATE queued_at = VALUES(queued_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$type,
				(int) $id,
				current_time( 'mysql' )
			)
		);
		self::ensure_worker();
	}

	public static function on_index_changed( $opportunity_id, $change ) {
		if ( 'removed' === $change ) {
			LNKup_Match_Service::delete_results( 'opportunity_id', $opportunity_id );
			return;
		}
		self::mark_dirty( 'opportunity', $opportunity_id );
	}

	/**
	 * An application changes the vendor's eligibility (already applied) and history signals.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function on_application( $app_id ) {
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		if ( ! $profile_id ) {
			$profile_id = (int) lnkup_get_vendor_profile_id( (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ) );
		}
		if ( $profile_id ) {
			self::mark_dirty( 'vendor', $profile_id );
		}
	}

	private static function ensure_worker( $delay = 30 ) {
		if ( ! wp_next_scheduled( self::QUEUE_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::QUEUE_HOOK );
		}
	}

	/**
	 * Drain a bounded batch of the dirty queue.
	 */
	public static function process_queue() {
		if ( ! LNKup_Match_Settings::active() || ! LNKup_Match_Tables::exists( LNKup_Match_Tables::dirty_table() ) ) {
			return;
		}
		global $wpdb;
		$table   = LNKup_Match_Tables::dirty_table();
		$started = microtime( true );
		$done    = 0;
		$errors  = 0;

		$batches = array(
			'vendor'      => self::VENDOR_BATCH,
			'opportunity' => self::OPP_BATCH,
		);
		foreach ( $batches as $type => $limit ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT entity_id FROM {$table} WHERE entity_type = %s ORDER BY queued_at ASC LIMIT %d", $type, $limit ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $ids as $id ) {
				if ( microtime( true ) - $started > self::TIME_BUDGET ) {
					break 2;
				}
				$wpdb->delete( $table, array( 'entity_type' => $type, 'entity_id' => (int) $id ), array( '%s', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				try {
					if ( 'vendor' === $type ) {
						LNKup_Match_Service::compute_for_vendor( (int) $id );
					} else {
						LNKup_Match_Service::compute_for_opportunity( (int) $id );
					}
					++$done;
				} catch ( Throwable $e ) {
					++$errors;
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( sprintf( 'LNKup match compute failed (%s #%d): %s', $type, (int) $id, $e->getMessage() ) );
				}
			}
		}

		LNKup_Match_Vendor_Index::geocode_batch( 10 );

		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
		$previous  = (array) ( get_option( self::STATUS_OPTION, array() )['queue'] ?? array() );
		// A run that only failed counts toward the consecutive-failure alarm; any success resets it.
		$failing   = $errors > 0 && 0 === $done ? (int) ( $previous['consecutive_error_runs'] ?? 0 ) + 1 : 0;
		self::record_status( 'queue', array( 'processed' => $done, 'errors' => $errors, 'remaining' => $remaining, 'seconds' => round( microtime( true ) - $started, 2 ), 'consecutive_error_runs' => $failing ) );
		if ( $remaining > 0 ) {
			self::ensure_worker( 15 );
		}
	}

	/**
	 * Re-index all vendor profiles, then queue every open opportunity (paged in cron).
	 */
	public static function queue_full_rebuild() {
		update_option( 'lnkup_match_backfill_page', 1, false );
		if ( ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::BACKFILL_HOOK );
		}
	}

	/**
	 * One backfill page per run; reschedules itself until complete.
	 */
	public static function process_backfill() {
		if ( ! LNKup_Match_Tables::exists( LNKup_Match_Tables::vendor_index_table() ) ) {
			return;
		}
		$page      = max( 1, (int) get_option( 'lnkup_match_backfill_page', 1 ) );
		$total     = self::backfill_total_pages();
		$processed = LNKup_Match_Vendor_Index::backfill_page( $page, 200 );
		if ( $processed > 0 ) {
			update_option( 'lnkup_match_backfill_page', $page + 1, false );
			wp_schedule_single_event( time() + 5, self::BACKFILL_HOOK );
			self::record_status( 'backfill', array( 'page' => $page, 'total_pages' => $total, 'processed' => $processed ) );
			return;
		}
		delete_option( 'lnkup_match_backfill_page' );
		self::queue_all_opportunities();
		self::record_status( 'backfill', array( 'page' => $page, 'total_pages' => $total, 'processed' => 0, 'complete' => true ) );
	}

	/**
	 * Vendor profile pages the backfill walks (200 per page).
	 */
	private static function backfill_total_pages() {
		$counts = wp_count_posts( 'lnkup_vendor_profile' );
		$total  = 0;
		foreach ( (array) $counts as $count ) {
			$total += (int) $count;
		}
		return max( 1, (int) ceil( $total / 200 ) );
	}

	const STALE_QUEUE_MINUTES     = 120;
	const STALLED_BACKFILL_MINUTES = 30;
	const ALERT_TRANSIENT         = 'lnkup_match_health_alerted';

	/**
	 * Problems an operator should act on (empty = healthy).
	 *
	 * @return array<int,array{code:string,detail:string}>
	 */
	public static function health_issues() {
		if ( ! LNKup_Match_Settings::active() ) {
			return array();
		}
		$health = self::health();
		$issues = array();
		if ( null !== $health['queue_oldest_minutes'] && $health['queue_oldest_minutes'] > self::STALE_QUEUE_MINUTES ) {
			$issues[] = array( 'code' => 'queue_stale', 'detail' => sprintf( __( 'Match recompute queue has items waiting %d minutes (WP-Cron may not be running).', 'lnkup-marketplace' ), $health['queue_oldest_minutes'] ) );
		}
		if ( $health['backfill']['running'] && null !== $health['backfill']['minutes_since'] && $health['backfill']['minutes_since'] > self::STALLED_BACKFILL_MINUTES ) {
			$issues[] = array( 'code' => 'backfill_stalled', 'detail' => sprintf( __( 'Vendor index backfill has not advanced for %d minutes.', 'lnkup-marketplace' ), $health['backfill']['minutes_since'] ) );
		}
		if ( $health['consecutive_error_runs'] >= 3 ) {
			$issues[] = array( 'code' => 'compute_failing', 'detail' => sprintf( __( 'The last %d match recompute runs only failed. Check the PHP error log.', 'lnkup-marketplace' ), $health['consecutive_error_runs'] ) );
		}
		if ( ! $health['next_daily_run'] ) {
			$issues[] = array( 'code' => 'daily_missing', 'detail' => __( 'The nightly match rebuild is not scheduled.', 'lnkup-marketplace' ) );
		}
		return $issues;
	}

	/**
	 * Email admins about matching problems (at most every 6 hours). Runs nightly and on demand.
	 *
	 * @return bool Whether an alert was sent.
	 */
	public static function alert_if_unhealthy() {
		$issues = self::health_issues();
		if ( ! $issues || get_transient( self::ALERT_TRANSIENT ) ) {
			return false;
		}
		set_transient( self::ALERT_TRANSIENT, 1, 6 * HOUR_IN_SECONDS );
		$summary  = 'Matching: ' . implode( ' ', wp_list_pluck( $issues, 'detail' ) );
		$settings = lnkup_get_settings();
		$to       = is_email( (string) ( $settings['support_email'] ?? '' ) ) ? (string) $settings['support_email'] : (string) get_option( 'admin_email' );
		$url      = admin_url( 'admin.php?page=lnkup-marketplace-intelligence&tab=rollout' );
		if ( class_exists( 'LNKup_Comms_Templates' ) && class_exists( 'LNKup_Comms_Channel_Email' ) ) {
			$rendered = LNKup_Comms_Templates::render( 'admin_health_alert', array( 'summary_line' => $summary ), 'admin' );
			if ( ! is_wp_error( $rendered ) ) {
				LNKup_Comms_Channel_Email::send_raw( $to, $rendered, $url );
				return true;
			}
		}
		wp_mail( $to, __( 'LNKup matching health alert', 'lnkup-marketplace' ), $summary . "\n\n" . $url );
		return true;
	}

	/**
	 * Admin notice on LNKup screens while matching is unhealthy.
	 */
	public static function admin_notice() {
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'lnkup' ) ) {
			return;
		}
		$issues = self::health_issues();
		if ( ! $issues ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Matching needs attention:', 'lnkup-marketplace' ) . '</strong> ' . esc_html( implode( ' ', wp_list_pluck( $issues, 'detail' ) ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=lnkup-marketplace-intelligence&tab=rollout' ) ) . '">' . esc_html__( 'Open Rollout & health', 'lnkup-marketplace' ) . '</a></p></div>';
	}

	/**
	 * Mark every indexed opportunity dirty.
	 */
	public static function queue_all_opportunities() {
		global $wpdb;
		$index = LNKup_Marketplace_Index::table_name();
		$table = LNKup_Match_Tables::dirty_table();
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"INSERT INTO {$table} (entity_type, entity_id, queued_at) SELECT 'opportunity', opportunity_id, %s FROM {$index} ON DUPLICATE KEY UPDATE queued_at = VALUES(queued_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql' )
			)
		);
		self::ensure_worker( 10 );
	}

	/**
	 * Nightly: expire stale results, refresh every open opportunity.
	 */
	public static function daily() {
		if ( ! LNKup_Match_Settings::active() ) {
			return;
		}
		global $wpdb;
		$results = LNKup_Match_Tables::results_table();
		$index   = LNKup_Marketplace_Index::table_name();
		$wpdb->query( "DELETE r FROM {$results} r LEFT JOIN {$index} m ON m.opportunity_id = r.opportunity_id WHERE m.opportunity_id IS NULL" ); // phpcs:ignore WordPress.DB
		// Check before re-queueing so a queue that never drains is still reported as stale.
		self::alert_if_unhealthy();
		self::queue_all_opportunities();
		self::record_status( 'daily', array( 'ran' => true ) );
	}

	/**
	 * Job health for the admin page.
	 *
	 * @param string $job  Job key.
	 * @param array  $data Data.
	 */
	private static function record_status( $job, array $data ) {
		$status         = get_option( self::STATUS_OPTION, array() );
		$status         = is_array( $status ) ? $status : array();
		$status[ $job ] = array_merge( $data, array( 'at' => current_time( 'mysql' ) ) );
		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * Health snapshot.
	 */
	public static function health() {
		global $wpdb;
		$status   = get_option( self::STATUS_OPTION, array() );
		$status   = is_array( $status ) ? $status : array();
		$dirty    = LNKup_Match_Tables::dirty_table();
		$oldest   = LNKup_Match_Tables::exists( $dirty ) ? $wpdb->get_var( "SELECT MIN(queued_at) FROM {$dirty}" ) : null; // phpcs:ignore WordPress.DB
		$now      = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$backfill = (array) ( $status['backfill'] ?? array() );
		$running  = (bool) get_option( 'lnkup_match_backfill_page' );
		$page     = (int) ( $backfill['page'] ?? 0 );
		$pages    = (int) ( $backfill['total_pages'] ?? 0 );
		return array(
			'queue_oldest_minutes'   => $oldest ? (int) floor( ( $now - strtotime( $oldest ) ) / 60 ) : null,
			'consecutive_error_runs' => (int) ( $status['queue']['consecutive_error_runs'] ?? 0 ),
			'backfill'               => array(
				'running'       => $running,
				'complete'      => ! $running && ! empty( $backfill['complete'] ),
				'page'          => $page,
				'total_pages'   => $pages,
				'percent'       => $running && $pages ? min( 99, (int) floor( 100 * $page / $pages ) ) : ( ! $running && ! empty( $backfill['complete'] ) ? 100 : null ),
				'minutes_since' => ! empty( $backfill['at'] ) ? (int) floor( ( $now - strtotime( $backfill['at'] ) ) / 60 ) : null,
			),
			'queue_size'      => LNKup_Match_Tables::exists( LNKup_Match_Tables::dirty_table() ) ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Match_Tables::dirty_table() ) : 0, // phpcs:ignore WordPress.DB
			'vendors_indexed' => LNKup_Match_Tables::exists( LNKup_Match_Tables::vendor_index_table() ) ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Match_Tables::vendor_index_table() ) : 0, // phpcs:ignore WordPress.DB
			'vendors_geo'     => LNKup_Match_Tables::exists( LNKup_Match_Tables::vendor_index_table() ) ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Match_Tables::vendor_index_table() . ' WHERE lat IS NOT NULL' ) : 0, // phpcs:ignore WordPress.DB
			'matches'         => LNKup_Match_Tables::exists( LNKup_Match_Tables::results_table() ) ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Match_Tables::results_table() . ' WHERE eligible = 1' ) : 0, // phpcs:ignore WordPress.DB
			'next_queue_run'  => wp_next_scheduled( self::QUEUE_HOOK ),
			'next_daily_run'  => wp_next_scheduled( self::DAILY_HOOK ),
			'last'            => $status,
		);
	}
}
