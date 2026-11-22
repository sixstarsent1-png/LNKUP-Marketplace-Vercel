<?php
/**
 * Background creation of many stops / occurrences.
 *
 * Up to INLINE_MAX rows run in the request. Larger batches become a job: the first chunk runs
 * immediately, the rest continue in CHUNK-sized WP-Cron steps. Every row is idempotent (stop key
 * client token), so a crashed or repeated step never duplicates a stop. Polling the job status
 * resumes a stalled job, which covers sites where WP-Cron only runs on traffic.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Portfolio_Jobs {

	const HOOK       = 'lnkup_portfolio_run_job';
	const INLINE_MAX = 10;
	const CHUNK      = 10;
	const STALE_SECS = 60;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'process' ) );
	}

	/**
	 * Create stops inline or as a job.
	 *
	 * @param int    $tour_id         Tour.
	 * @param array  $rows            Raw stop rows.
	 * @param string $idempotency_key Request key (same key = same job).
	 * @return array|WP_Error { mode: inline|job, result?|job? }
	 */
	public static function add_stops( $tour_id, array $rows, $idempotency_key ) {
		$rows = array_values( $rows );
		if ( count( $rows ) <= self::INLINE_MAX ) {
			$result = LNKup_Portfolio_Tours::add_stops( $tour_id, $rows );
			return is_wp_error( $result ) ? $result : array( 'mode' => 'inline', 'result' => $result );
		}

		$organizer = (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true );
		if ( get_current_user_id() !== $organizer ) {
			return new WP_Error( 'lnkup_forbidden', __( 'Only the tour owner can add stops.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$existing = LNKup_Portfolio_Tours::stops_by_key( LNKup_Tours::stop_event_ids( $tour_id ) );
		$new      = 0;
		foreach ( $rows as $i => $row ) {
			$clean = is_array( $row ) ? LNKup_Portfolio_Tours::sanitize_stop_row( $row, $i ) : null;
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			if ( is_array( $clean ) && ! isset( $existing[ $clean['key'] ] ) ) {
				++$new;
			}
		}
		if ( count( LNKup_Tours::stop_event_ids( $tour_id ) ) + $new > LNKup_Tours::MAX_STOPS ) {
			return new WP_Error( 'lnkup_too_many_stops', sprintf( __( 'A tour can have up to %d stops.', 'lnkup-marketplace' ), LNKup_Tours::MAX_STOPS ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$table = LNKup_Portfolio_Tables::jobs_table();
		$key   = 'stops_' . md5( $organizer . '|' . $tour_id . '|' . ( '' !== (string) $idempotency_key ? $idempotency_key : wp_json_encode( $rows ) ) );
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE job_key = %s", $key ) ); // phpcs:ignore WordPress.DB
		if ( $found ) {
			return array( 'mode' => 'job', 'job' => self::get( $found ) );
		}
		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'job_key'           => $key,
				'organizer_user_id' => $organizer,
				'tour_id'           => (int) $tour_id,
				'type'              => 'add_stops',
				'status'            => 'running',
				'total'             => count( $rows ),
				'payload'           => wp_json_encode( $rows ),
				'results'           => wp_json_encode( array() ),
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE job_key = %s", $key ) ); // phpcs:ignore WordPress.DB
			if ( $found ) {
				return array( 'mode' => 'job', 'job' => self::get( $found ) );
			}
			return new WP_Error( 'lnkup_job_failed', __( 'We could not start creating these dates. Try again.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		$job_id = (int) $wpdb->insert_id;
		self::process( $job_id );
		return array( 'mode' => 'job', 'job' => self::get( $job_id ) );
	}

	/**
	 * Run the next chunk of a job.
	 *
	 * @param int $job_id Job.
	 */
	public static function process( $job_id ) {
		global $wpdb;
		$job_id = absint( $job_id );
		$lock   = 'lnkup_portfolio_job_' . $job_id;
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$previous_user = get_current_user_id();
		try {
			$table = LNKup_Portfolio_Tables::jobs_table();
			$job   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( ! $job || 'running' !== $job['status'] ) {
				return;
			}
			$rows    = json_decode( (string) $job['payload'], true );
			$results = json_decode( (string) $job['results'], true );
			$rows    = is_array( $rows ) ? $rows : array();
			$results = is_array( $results ) ? $results : array();
			$done    = (int) $job['done'];
			$tour_id = (int) $job['tour_id'];

			wp_set_current_user( (int) $job['organizer_user_id'] );
			$order = count( LNKup_Tours::stop_event_ids( $tour_id ) );
			foreach ( array_slice( $rows, $done, self::CHUNK, true ) as $index => $row ) {
				$results[] = LNKup_Portfolio_Tours::create_stop( $tour_id, is_array( $row ) ? $row : array(), (int) $index, ++$order );
				++$done;
			}
			$failed   = count( array_filter( $results, static function ( $r ) { return 'failed' === ( $r['result'] ?? '' ); } ) );
			$finished = $done >= count( $rows );
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'done'       => $done,
					'failed'     => $failed,
					'results'    => wp_json_encode( $results ),
					'status'     => $finished ? ( $failed ? 'partial' : 'complete' ) : 'running',
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $job_id ),
				array( '%d', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
			if ( ! $finished && ! wp_next_scheduled( self::HOOK, array( $job_id ) ) ) {
				wp_schedule_single_event( time() + 5, self::HOOK, array( $job_id ) );
			}
			if ( $finished ) {
				LNKup_Portfolio_Audit::log( 'tour', $tour_id, 'stops_job_finished', array( 'job_id' => $job_id, 'total' => count( $rows ), 'failed' => $failed ), (int) $job['organizer_user_id'] );
			}
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup portfolio job ' . $job_id . ' failed: ' . $e->getMessage() );
		} finally {
			wp_set_current_user( $previous_user );
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Job status. A running job that has not moved for STALE_SECS is resumed here.
	 *
	 * @param int $job_id      Job.
	 * @param int $organizer   Required owner (0 = skip check).
	 * @return array|WP_Error
	 */
	public static function get( $job_id, $organizer = 0 ) {
		global $wpdb;
		$table = LNKup_Portfolio_Tables::jobs_table();
		$job   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $job_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $job || ( $organizer && (int) $job['organizer_user_id'] !== (int) $organizer && ! LNKup_Capabilities::user_has_manage_all( $organizer ) ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'This job was not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( 'running' === $job['status'] && strtotime( (string) $job['updated_at'] ) < strtotime( current_time( 'mysql' ) ) - self::STALE_SECS ) {
			self::process( (int) $job['id'] );
			$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $job_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		$results = json_decode( (string) $job['results'], true );
		return array(
			'id'      => (int) $job['id'],
			'tour_id' => (int) $job['tour_id'],
			'type'    => $job['type'],
			'status'  => $job['status'],
			'total'   => (int) $job['total'],
			'done'    => (int) $job['done'],
			'failed'  => (int) $job['failed'],
			'results' => is_array( $results ) ? $results : array(),
		);
	}
}
