<?php
/**
 * Matching-driven alerts built on the lnkup-marketplace match engine:
 *
 * - v_tour_match: one message per vendor per tour listing only the stops they match.
 * - v_weekly_matches: Monday summary of top matches for vendors who heard nothing that week.
 * - o_open_spots: organizer reminder 14 and 7 days out while vendor spaces are open.
 * - o_new_matching_vendors: weekly count of vendors who newly match an organizer's open event.
 *
 * All work runs in background batches; every message is re-validated at send time.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Auto_Matching {

	const TOUR_BATCH   = 200;
	const WEEKLY_BATCH = 500;
	const TOUR_META    = 'lnkup_comms_tour_announced_';
	const NEW_MATCHES  = '_lnkup_comms_new_matches_at';

	/** Triggers that count as "the vendor already heard about opportunities". */
	const DISCOVERY_TRIGGERS = array( 'v_opportunity_match', 'v_organizer_new_opportunity', 'v_tour_match', 'v_weekly_matches', 'v_saved_search_match', 'v_invited_to_apply' );

	public static function init(): void {
		add_action( 'lnkup_comms_match_tour', array( __CLASS__, 'process_tour' ), 10, 2 );
		add_action( 'lnkup_comms_weekly_matches', array( __CLASS__, 'process_weekly' ), 10, 1 );
		add_action( 'lnkup_comms_weekly', array( __CLASS__, 'weekly' ), 20 );
		add_action( 'lnkup_comms_daily', array( __CLASS__, 'daily' ), 35 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_tour_status' ), 20, 3 );
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'attribute_application' ), 30 );
	}

	/**
	 * Observed (not causal) attribution: the vendor opened or clicked a discovery
	 * message about this opportunity, its event or its tour in the last 14 days.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function attribute_application( $app_id ): void {
		global $wpdb;
		$app_id = absint( $app_id );
		$vendor = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$opp    = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$event  = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		if ( ! $vendor || ! $opp || ! class_exists( 'LNKup_Marketplace_Analytics' ) ) {
			return;
		}
		$tour     = $event ? (int) get_post_meta( $event, '_lnkup_tour_id', true ) : 0;
		$messages = LNKup_Comms_Schema::table( 'messages' );
		$in       = "'" . implode( "','", self::DISCOVERY_TRIGGERS ) . "','v_saved_deadline'";
		$since    = LNKup_Comms_Schema::now( -14 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, trigger_key FROM {$messages}
				WHERE recipient_user_id = %d AND trigger_key IN ({$in})
				AND ( opportunity_id = %d OR ( %d > 0 AND event_id = %d ) OR ( %d > 0 AND trigger_key = 'v_tour_match' AND context LIKE %s ) )
				AND ( clicked_at >= %s OR opened_at >= %s )
				ORDER BY COALESCE(clicked_at, opened_at) DESC LIMIT 1",
				$vendor,
				$opp,
				$event,
				$event,
				$tour,
				'%"tour_id":' . $tour . ',%',
				$since,
				$since
			),
			ARRAY_A
		);
		if ( $row ) {
			LNKup_Marketplace_Analytics::track( 'match_alert_application', array( 'trigger' => (string) $row['trigger_key'], 'message_id' => (int) $row['id'], 'application_id' => $app_id ), $vendor, $opp );
		}
	}

	private static function engine_ready(): bool {
		return LNKup_Comms_Matching::core_engine_ready();
	}

	// ------------------------------------------------------------------ Tours.

	public static function on_tour_status( $new_status, $old_status, $post ): void {
		if ( $post instanceof WP_Post && 'lnkup_tour' === $post->post_type && 'publish' === $new_status && 'publish' !== $old_status ) {
			self::queue_tour( (int) $post->ID );
		}
	}

	/**
	 * Queue tour matching 10 minutes out (stops are often attached right after publishing).
	 */
	public static function queue_tour( int $tour_id ): void {
		if ( $tour_id < 1 || ! LNKup_Comms_Settings::enabled( 'flow_discovery_tours' ) ) {
			return;
		}
		LNKup_Comms_Queue::job( 'lnkup_comms_match_tour', array( $tour_id, 1 ), 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * One page of vendors with at least one matching open stop.
	 *
	 * @param int $tour_id Tour ID.
	 * @param int $page    Page.
	 */
	public static function process_tour( $tour_id, $page = 1 ): void {
		global $wpdb;
		$tour_id = absint( $tour_id );
		$page    = max( 1, absint( $page ) );
		if ( ! LNKup_Comms_Settings::enabled( 'flow_discovery_tours' ) || LNKup_Comms_Settings::automation_paused( 'v_tour_match' ) || ! self::engine_ready() || 'publish' !== get_post_status( $tour_id ) ) {
			return;
		}
		$results  = LNKup_Match_Tables::results_table();
		$vtable   = LNKup_Match_Tables::vendor_index_table();
		$index    = LNKup_Marketplace_Index::table_name();
		$organizer = (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT r.profile_id, v.user_id FROM {$results} r
				INNER JOIN {$index} m ON m.opportunity_id = r.opportunity_id
				INNER JOIN {$vtable} v ON v.profile_id = r.profile_id
				WHERE m.tour_id = %d AND r.eligible = 1 AND v.status = 'active'
				ORDER BY r.profile_id ASC LIMIT %d OFFSET %d",
				$tour_id,
				self::TOUR_BATCH + 1,
				( $page - 1 ) * self::TOUR_BATCH
			),
			ARRAY_A
		);
		$has_more = count( $rows ) > self::TOUR_BATCH;
		foreach ( array_slice( $rows, 0, self::TOUR_BATCH ) as $row ) {
			$vendor = (int) $row['user_id'];
			if ( ! $vendor || $vendor === $organizer ) {
				continue;
			}
			self::announce_tour( $tour_id, (int) $row['profile_id'], $vendor );
		}
		if ( $has_more ) {
			LNKup_Comms_Queue::job( 'lnkup_comms_match_tour', array( $tour_id, $page + 1 ), 60 );
		}
	}

	/**
	 * Send one grouped tour message when the vendor matches stops they have not been told about.
	 */
	public static function announce_tour( int $tour_id, int $profile_id, int $vendor ): int {
		$stops = LNKup_Match_Service::tour_matches_for_vendor( $tour_id, $profile_id );
		if ( ! $stops ) {
			return 0;
		}
		$meta_key  = self::TOUR_META . $tour_id;
		$announced = array_map( 'intval', (array) get_user_meta( $vendor, $meta_key, true ) );
		$event_ids = array_map( 'intval', wp_list_pluck( $stops, 'event_id' ) );
		if ( ! array_diff( $event_ids, $announced ) ) {
			return 0;
		}
		$ctx   = array( 'vendor_user_id' => $vendor, 'profile_id' => $profile_id, 'tour_id' => $tour_id );
		$items = array();
		foreach ( array_slice( $stops, 0, 10 ) as $stop ) {
			$items[] = array(
				'title' => trim( $stop['city'] . ( '' !== $stop['state'] ? ', ' . $stop['state'] : '' ), ', ' ),
				'meta'  => $stop['event_date'] ? wp_date( 'D, M j, Y', (int) strtotime( $stop['event_date'] ) ) : '',
				'url'   => LNKup_Comms_Context::web_url( 'tour', LNKup_Comms_Context::build( $ctx ), 'vendor' ),
			);
		}
		$cities = array_values( array_unique( array_filter( wp_list_pluck( $stops, 'city' ) ) ) );
		$message = LNKup_Comms_Engine::dispatch(
			'v_tour_match',
			$vendor,
			array_merge(
				$ctx,
				array(
					'stop_count'  => (string) count( $stops ),
					'stop_cities' => implode( ', ', array_slice( $cities, 0, 4 ) ),
				)
			),
			array(
				'window'     => 'tour' . $tour_id . '_' . count( array_unique( array_merge( $announced, $event_ids ) ) ),
				'cancel_key' => 'tour:' . $tour_id,
				'items'      => $items,
				'source'     => 'discovery_tour',
			)
		);
		if ( $message ) {
			update_user_meta( $vendor, $meta_key, array_values( array_unique( array_merge( $announced, $event_ids ) ) ) );
		}
		return $message;
	}

	// ------------------------------------------------------------------ Weekly vendor summary.

	public static function weekly(): void {
		if ( LNKup_Comms_Settings::enabled( 'flow_discovery_weekly' ) && self::engine_ready() ) {
			LNKup_Comms_Queue::job( 'lnkup_comms_weekly_matches', array( 1 ), 0 );
		}
		if ( LNKup_Comms_Settings::enabled( 'flow_recruitment_updates' ) && self::engine_ready() ) {
			self::new_matching_vendors();
		}
	}

	/**
	 * One page of active vendors from the matching index.
	 *
	 * @param int $page Page.
	 */
	public static function process_weekly( $page = 1 ): void {
		global $wpdb;
		$page = max( 1, absint( $page ) );
		if ( ! LNKup_Comms_Settings::enabled( 'flow_discovery_weekly' ) || LNKup_Comms_Settings::automation_paused( 'v_weekly_matches' ) || ! self::engine_ready() ) {
			return;
		}
		$vtable = LNKup_Match_Tables::vendor_index_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$users = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$vtable} WHERE status = 'active' ORDER BY profile_id ASC LIMIT %d OFFSET %d", self::WEEKLY_BATCH + 1, ( $page - 1 ) * self::WEEKLY_BATCH ) ) );
		$has_more = count( $users ) > self::WEEKLY_BATCH;
		foreach ( array_slice( $users, 0, self::WEEKLY_BATCH ) as $user_id ) {
			try {
				self::weekly_for_vendor( $user_id );
			} catch ( Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup Communications weekly matches failed for user ' . $user_id . ': ' . $e->getMessage() );
			}
		}
		if ( $has_more ) {
			LNKup_Comms_Queue::job( 'lnkup_comms_weekly_matches', array( $page + 1 ), 30 );
		}
	}

	/**
	 * Top recommended, not-yet-notified opportunities for one vendor.
	 */
	public static function weekly_for_vendor( int $user_id ): int {
		global $wpdb;
		if ( $user_id < 1 || 'off' === LNKup_Comms_Preferences::email_mode( $user_id, 'opportunities' ) ) {
			return 0;
		}
		$messages = LNKup_Comms_Schema::table( 'messages' );
		$in       = "'" . implode( "','", self::DISCOVERY_TRIGGERS ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE recipient_user_id = %d AND trigger_key IN ({$in}) AND created_at >= %s", $user_id, gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) );
		if ( $recent > 0 ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$told = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT opportunity_id FROM {$messages} WHERE recipient_user_id = %d AND trigger_key IN ({$in}) AND opportunity_id > 0", $user_id ) ) );
		$recs = LNKup_Match_Service::vendor_recommendations( $user_id, array( 'section' => 'recommended', 'per_page' => 12 ) );
		if ( is_wp_error( $recs ) || empty( $recs['items'] ) ) {
			return 0;
		}
		$items = array();
		foreach ( $recs['items'] as $item ) {
			$opp_id = (int) $item['opportunity_id'];
			if ( in_array( $opp_id, $told, true ) ) {
				continue;
			}
			$reason  = (string) ( $item['match']['reasons'][0] ?? '' );
			$items[] = array(
				'title' => (string) ( $item['event_name'] ?? $item['name'] ?? '' ),
				'meta'  => trim( implode( ' · ', array_filter( array( (string) ( $item['location_label'] ?? '' ), $item['date'] ? wp_date( 'M j', (int) strtotime( (string) $item['date'] ) ) : '', $reason ) ) ) ),
				'url'   => LNKup_Comms_Context::web_url( 'opportunity', LNKup_Comms_Context::build( array( 'opportunity_id' => $opp_id ) ), 'vendor' ),
			);
			if ( count( $items ) >= 5 ) {
				break;
			}
		}
		if ( ! $items ) {
			return 0;
		}
		return LNKup_Comms_Engine::dispatch(
			'v_weekly_matches',
			$user_id,
			array( 'vendor_user_id' => $user_id, 'count' => (string) count( $items ) ),
			array( 'window' => 'wk' . gmdate( 'oW' ), 'items' => $items, 'source' => 'discovery_weekly' )
		);
	}

	// ------------------------------------------------------------------ Organizer recruitment updates.

	public static function daily(): void {
		if ( ! LNKup_Comms_Settings::enabled( 'flow_recruitment_updates' ) || LNKup_Comms_Settings::automation_paused( 'o_open_spots' ) ) {
			return;
		}
		self::open_spots();
	}

	/**
	 * Events 14 or 7 days out that still have open vendor spaces.
	 */
	public static function open_spots(): int {
		global $wpdb;
		$index = LNKup_Marketplace_Index::table_name();
		$sent  = 0;
		foreach ( array( 14, 7 ) as $days ) {
			$date = wp_date( 'Y-m-d', time() + $days * DAY_IN_SECONDS );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$events = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$index} WHERE event_date = %s AND spots_remaining > 0", $date ) ) );
			foreach ( $events as $event_id ) {
				$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
				$open      = LNKup_Comms_Matching::event_open_spots( $event_id );
				if ( ! $organizer || $open < 1 ) {
					continue;
				}
				$sent += LNKup_Comms_Engine::dispatch(
					'o_open_spots',
					$organizer,
					array( 'event_id' => $event_id, 'open_spots' => (string) $open, 'days_until' => (string) $days ),
					array( 'window' => 'spots_d' . $days, 'source' => 'recruitment_updates' )
				) ? 1 : 0;
			}
		}
		return $sent;
	}

	/**
	 * Weekly: vendors who newly became recommended for an organizer's open events.
	 */
	public static function new_matching_vendors(): int {
		global $wpdb;
		if ( LNKup_Comms_Settings::automation_paused( 'o_new_matching_vendors' ) ) {
			return 0;
		}
		$index  = LNKup_Marketplace_Index::table_name();
		$today  = wp_date( 'Y-m-d' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$events = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$index} WHERE spots_remaining > 0 AND (event_date IS NULL OR event_date >= %s) LIMIT 2000", $today ) ) );
		$sent   = 0;
		foreach ( $events as $event_id ) {
			$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
			if ( ! $organizer ) {
				continue;
			}
			$since = (int) get_post_meta( $event_id, self::NEW_MATCHES, true ) ?: time() - WEEK_IN_SECONDS;
			$count = LNKup_Match_Service::discoverable_match_count( $event_id, $organizer, $since );
			update_post_meta( $event_id, self::NEW_MATCHES, time() );
			if ( $count < 1 ) {
				continue;
			}
			$sent += LNKup_Comms_Engine::dispatch(
				'o_new_matching_vendors',
				$organizer,
				array( 'event_id' => $event_id, 'count' => (string) $count ),
				array( 'window' => 'wk' . gmdate( 'oW' ), 'source' => 'recruitment_updates' )
			) ? 1 : 0;
		}
		return $sent;
	}
}
