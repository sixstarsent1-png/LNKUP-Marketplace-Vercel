<?php
/**
 * Marketplace engagement journeys built on canonical state:
 *
 * - o_invite_response: organizer hears when a vendor accepts or declines an invitation.
 * - o_new_vendors_nearby: hourly, when enough newly matching discoverable vendors appear for a
 *   live event with open slots (public vendor cards only; 72h cooldown per event; smaller counts
 *   keep accumulating and also roll into the weekly o_new_matching_vendors).
 * - o_open_spots (deadline variant): 7 and 2 days before the application deadline while spots remain.
 * - o_event_no_slots: published event that vendors cannot apply to yet.
 * - v_invite_expiring: unanswered invitation about 3 days before it expires.
 * - v_application_draft: saved, unfinished application (24h and 72h after the last edit, and
 *   when the deadline is within 3 days).
 * - v_inactive: vendors inactive 14 / 30 / 60 days, only when real recommendations exist.
 *
 * Everything runs in background batches and is re-validated at send time.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Auto_Engagement {

	const NEARBY_SINCE      = '_lnkup_comms_nearby_since';
	const NEARBY_SENT       = '_lnkup_comms_nearby_sent_at';
	const NEARBY_THRESHOLD  = 3;
	const NEARBY_COOLDOWN   = 3 * DAY_IN_SECONDS;
	const DEADLINE_WINDOWS  = array( 7, 2 );
	const INACTIVE_SEGMENTS = array( 14, 30, 60 );
	const BATCH             = 200;

	public static function init(): void {
		add_action( 'lnkup_vendor_invite_status_changed', array( __CLASS__, 'on_invite_status' ), 30, 3 );
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'on_application_submitted' ), 40 );
		add_action( 'lnkup_comms_hourly', array( __CLASS__, 'hourly' ), 45 );
		add_action( 'lnkup_comms_daily', array( __CLASS__, 'daily' ), 45 );
		add_action( 'lnkup_comms_inactive_vendors', array( __CLASS__, 'process_inactive' ), 10, 1 );
	}

	// ------------------------------------------------------------------ Invitations.

	/**
	 * Fired both by the status-meta watcher ( id, old, new, key ) and by LNKup_Vendor_Invites ( id, status ).
	 *
	 * @param int         $invite_id Invite.
	 * @param string      $a         New status (2-arg form) or old status (watcher form).
	 * @param string|null $b         New status (watcher form).
	 */
	public static function on_invite_status( $invite_id, $a = '', $b = null ): void {
		$invite_id = absint( $invite_id );
		$status    = sanitize_key( (string) ( null !== $b ? $b : $a ) );
		if ( ! in_array( $status, array( 'accepted', 'declined' ), true ) || 'lnkup_vendor_invite' !== get_post_type( $invite_id ) ) {
			return;
		}
		$organizer = (int) get_post_meta( $invite_id, '_lnkup_organizer_user_id', true );
		$vendor    = (int) get_post_meta( $invite_id, '_lnkup_vendor_user_id', true );
		$profile   = function_exists( 'lnkup_get_vendor_profile_id' ) ? (int) lnkup_get_vendor_profile_id( $vendor ) : 0;
		$name      = $profile ? (string) ( get_post_meta( $profile, '_lnkup_business_name', true ) ?: get_the_title( $profile ) ) : '';
		if ( ! $organizer || '' === $name ) {
			return;
		}
		LNKup_Comms_Engine::dispatch(
			'o_invite_response',
			$organizer,
			array(
				'event_id'      => (int) get_post_meta( $invite_id, '_lnkup_event_id', true ),
				'invite_id'     => $invite_id,
				'invite_status' => $status,
				'vendor_name'   => $name,
			),
			array( 'variant' => $status, 'window' => 'inv' . $invite_id . $status, 'cancel_key' => 'invite:' . $invite_id, 'source' => 'invite_response' )
		);
	}

	public static function on_application_submitted( $app_id ): void {
		$app_id = absint( $app_id );
		$vendor = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$opp    = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		if ( $vendor ) {
			LNKup_Comms_Engine::cancel( 'user:' . $vendor . '|inactive', array( 'v_inactive' ), 'vendor_active' );
		}
		if ( $vendor && $opp ) {
			LNKup_Comms_Engine::cancel( 'draft:' . $vendor . ':' . $opp, array( 'v_application_draft' ), 'application_submitted' );
		}
	}

	// ------------------------------------------------------------------ Schedules.

	public static function hourly(): void {
		if ( LNKup_Comms_Settings::enabled( 'flow_recruitment_updates' ) && ! LNKup_Comms_Settings::automation_paused( 'o_new_vendors_nearby' ) && LNKup_Comms_Matching::core_engine_ready() ) {
			self::nearby_vendors();
		}
	}

	public static function daily(): void {
		if ( LNKup_Comms_Settings::enabled( 'flow_recruitment_updates' ) && ! LNKup_Comms_Settings::automation_paused( 'o_open_spots' ) ) {
			self::deadline_spots();
		}
		if ( LNKup_Comms_Settings::enabled( 'flow_activation' ) && ! LNKup_Comms_Settings::automation_paused( 'o_event_no_slots' ) ) {
			self::events_without_slots();
		}
		if ( LNKup_Comms_Settings::enabled( 'flow_discovery' ) && ! LNKup_Comms_Settings::automation_paused( 'v_invite_expiring' ) ) {
			self::expiring_invites();
		}
		if ( LNKup_Comms_Settings::enabled( 'flow_vendor_engagement' ) ) {
			if ( ! LNKup_Comms_Settings::automation_paused( 'v_application_draft' ) ) {
				self::drafts();
			}
			if ( ! LNKup_Comms_Settings::automation_paused( 'v_inactive' ) && LNKup_Comms_Matching::core_engine_ready() ) {
				LNKup_Comms_Queue::job( 'lnkup_comms_inactive_vendors', array( 1 ), 0 );
			}
		}
	}

	// ------------------------------------------------------------------ Organizers.

	/**
	 * Live events with open spots that gained enough newly matching discoverable vendors.
	 * The first run per event only records a baseline, so turning the flow on never sends a backlog.
	 */
	public static function nearby_vendors(): int {
		global $wpdb;
		$index  = LNKup_Marketplace_Index::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$events = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$index} WHERE spots_remaining > 0 AND (event_date IS NULL OR event_date >= %s) LIMIT 500", wp_date( 'Y-m-d' ) ) ) );
		$sent   = 0;
		foreach ( $events as $event_id ) {
			try {
				$sent += self::nearby_for_event( $event_id ) ? 1 : 0;
			} catch ( Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup Communications nearby vendors failed for event ' . $event_id . ': ' . $e->getMessage() );
			}
		}
		return $sent;
	}

	public static function nearby_for_event( int $event_id ): int {
		$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		if ( ! $organizer || 'live' !== LNKup_Comms_Context::event_status( $event_id ) ) {
			return 0;
		}
		$since = (int) get_post_meta( $event_id, self::NEARBY_SINCE, true );
		if ( ! $since ) {
			update_post_meta( $event_id, self::NEARBY_SINCE, time() );
			return 0;
		}
		$last_sent = (int) get_post_meta( $event_id, self::NEARBY_SENT, true );
		if ( $last_sent && time() - $last_sent < self::NEARBY_COOLDOWN ) {
			return 0;
		}
		$count = LNKup_Match_Service::discoverable_match_count( $event_id, $organizer, $since );
		if ( $count < (int) apply_filters( 'lnkup_comms_nearby_vendor_threshold', self::NEARBY_THRESHOLD, $event_id ) ) {
			return 0;
		}
		$find  = LNKup_Comms_Context::web_url( 'org_find_vendors', LNKup_Comms_Context::build( array( 'event_id' => $event_id ) ), 'organizer' );
		$cards = LNKup_Match_Service::event_vendor_candidates( $organizer, $event_id, array( 'section' => 'new_nearby', 'per_page' => 5, 'exclude_applied' => true ) );
		$items = array();
		foreach ( is_wp_error( $cards ) ? array() : (array) $cards['items'] as $card ) {
			$profile_url = add_query_arg( 'profile', (int) $card['profile_id'], $find );
			$items[]     = array(
				'title'        => (string) $card['business_name'],
				'meta'         => implode( ' · ', array_filter( array( (string) $card['primary_category'], trim( $card['city'] . ', ' . $card['state'], ', ' ), (string) ( $card['match']['reasons'][0] ?? '' ), $card['completed_events'] > 0 ? sprintf( /* translators: %d: events */ _n( '%d event on LNKup', '%d events on LNKup', (int) $card['completed_events'], 'lnkup-communications' ), (int) $card['completed_events'] ) : '' ) ) ),
				'url'          => $profile_url,
				'image'        => (string) $card['logo'],
				'badge'        => ! empty( $card['verified'] ) ? __( 'Verified', 'lnkup-communications' ) : '',
				'action_label' => __( 'Invite to event', 'lnkup-communications' ),
				'action_url'   => $profile_url,
			);
		}
		$message = LNKup_Comms_Engine::dispatch(
			'o_new_vendors_nearby',
			$organizer,
			array( 'event_id' => $event_id, 'count' => (string) $count, 'category_summary' => LNKup_Comms_Engagement::category_summary( $event_id ) ),
			array( 'window' => 'n' . gmdate( 'YmdH' ), 'items' => $items, 'source' => 'recruitment_nearby' )
		);
		if ( $message ) {
			update_post_meta( $event_id, self::NEARBY_SENT, time() );
			update_post_meta( $event_id, self::NEARBY_SINCE, time() );
		}
		return $message;
	}

	/**
	 * Open spots 7 and 2 days before the application deadline (category breakdown included).
	 */
	public static function deadline_spots(): int {
		global $wpdb;
		$index = LNKup_Marketplace_Index::table_name();
		$sent  = 0;
		foreach ( self::DEADLINE_WINDOWS as $days ) {
			$date = wp_date( 'Y-m-d', time() + $days * DAY_IN_SECONDS );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$events = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$index} WHERE deadline = %s AND spots_remaining > 0", $date ) ) );
			foreach ( $events as $event_id ) {
				$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
				$open      = LNKup_Comms_Matching::event_open_spots( $event_id );
				if ( ! $organizer || $open < 1 ) {
					continue;
				}
				$sent += LNKup_Comms_Engine::dispatch(
					'o_open_spots',
					$organizer,
					array( 'event_id' => $event_id, 'open_spots' => (string) $open, 'days_until' => (string) $days, 'category_summary' => LNKup_Comms_Engagement::category_summary( $event_id ) ),
					array( 'variant' => 'deadline', 'window' => 'dl_d' . $days, 'source' => 'recruitment_updates' )
				) ? 1 : 0;
			}
		}
		return $sent;
	}

	/**
	 * Published events (at least a day old, still upcoming) with no published vendor opportunity.
	 */
	public static function events_without_slots(): int {
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_event_date', 'value' => wp_date( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ), 'compare' => '>=', 'type' => 'DATE' ),
				),
				'date_query'     => array( array( 'before' => '1 day ago', 'column' => 'post_date_gmt' ) ),
			)
		);
		$sent = 0;
		foreach ( array_map( 'intval', (array) $events ) as $event_id ) {
			if ( LNKup_Comms_Context::event_cancelled( $event_id ) || LNKup_Comms_Context::published_opportunity_count( $event_id ) > 0 ) {
				continue;
			}
			$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
			$age       = (int) floor( ( time() - (int) get_post_time( 'U', true, $event_id ) ) / DAY_IN_SECONDS );
			if ( ! $organizer ) {
				continue;
			}
			$sent += LNKup_Comms_Engine::dispatch( 'o_event_no_slots', $organizer, array( 'event_id' => $event_id ), array( 'window' => $age >= 4 ? 'a4' : 'a1', 'source' => 'activation' ) ) ? 1 : 0;
		}
		return $sent;
	}

	// ------------------------------------------------------------------ Vendors.

	/**
	 * Open invitations expiring in 2–3 days.
	 */
	public static function expiring_invites(): int {
		$ids  = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_invite',
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_status', 'value' => array( 'sent', 'opened' ), 'compare' => 'IN' ),
					array( 'key' => '_lnkup_expires_at', 'value' => array( gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ) ), 'compare' => 'BETWEEN', 'type' => 'DATETIME' ),
				),
			)
		);
		$sent = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $invite_id ) {
			$vendor  = (int) get_post_meta( $invite_id, '_lnkup_vendor_user_id', true );
			$expires = (string) get_post_meta( $invite_id, '_lnkup_expires_at', true );
			$sent   += LNKup_Comms_Engine::dispatch(
				'v_invite_expiring',
				$vendor,
				array(
					'invite_id'         => $invite_id,
					'vendor_user_id'    => $vendor,
					'opportunity_id'    => (int) get_post_meta( $invite_id, '_lnkup_opportunity_id', true ),
					'event_id'          => (int) get_post_meta( $invite_id, '_lnkup_event_id', true ),
					'organizer_user_id' => (int) get_post_meta( $invite_id, '_lnkup_organizer_user_id', true ),
					'invite_expires'    => wp_date( 'M j', (int) strtotime( $expires . ' UTC' ) ),
				),
				array( 'window' => 'exp' . $invite_id, 'cancel_key' => 'invite:' . $invite_id, 'source' => 'invite_expiring' )
			) ? 1 : 0;
		}
		return $sent;
	}

	/**
	 * Saved, unfinished applications: 24h and 72h after the last edit (within a week), and once
	 * when the application deadline is 3 days away or less.
	 */
	public static function drafts(): int {
		if ( ! class_exists( 'LNKup_Application_Drafts' ) ) {
			return 0;
		}
		$ids  = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => LNKup_Application_Drafts::POST_STATUS,
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'modified',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_draft_state', 'value' => LNKup_Application_Drafts::STATE_OPEN ),
					array( 'key' => '_lnkup_draft_opportunity_id', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ),
				),
				'date_query'     => array( array( 'column' => 'post_modified_gmt', 'before' => '24 hours ago', 'after' => '8 days ago' ) ),
			)
		);
		$sent = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $draft_id ) {
			$vendor   = (int) get_post_meta( $draft_id, '_lnkup_draft_vendor_user_id', true );
			$opp      = (int) get_post_meta( $draft_id, '_lnkup_draft_opportunity_id', true );
			$age      = time() - (int) get_post_modified_time( 'U', true, $draft_id );
			$deadline = trim( (string) get_post_meta( $opp, '_lnkup_application_deadline', true ) );
			$closing  = '' !== $deadline && strtotime( substr( $deadline, 0, 10 ) ) <= strtotime( wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) );
			$window   = $closing ? 'dl' : ( $age >= 3 * DAY_IN_SECONDS ? 'd3' : 'd1' );
			$sent    += LNKup_Comms_Engine::dispatch(
				'v_application_draft',
				$vendor,
				array( 'draft_id' => $draft_id, 'vendor_user_id' => $vendor, 'opportunity_id' => $opp ),
				array( 'window' => $window . '_' . $draft_id . '_' . (int) get_post_meta( $draft_id, '_lnkup_draft_revision', true ), 'cancel_key' => 'draft:' . $vendor . ':' . $opp, 'source' => 'draft_reminder' )
			) ? 1 : 0;
		}
		return $sent;
	}

	/**
	 * One page of vendors; each gets at most one message per inactivity segment, and only
	 * when the match engine has live recommendations for them.
	 *
	 * @param int $page Page.
	 */
	public static function process_inactive( $page = 1 ): void {
		global $wpdb;
		$page = max( 1, absint( $page ) );
		if ( ! LNKup_Comms_Settings::enabled( 'flow_vendor_engagement' ) || LNKup_Comms_Settings::automation_paused( 'v_inactive' ) || ! LNKup_Comms_Matching::core_engine_ready() ) {
			return;
		}
		$vtable = LNKup_Match_Tables::vendor_index_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$users    = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$vtable} WHERE status = 'active' ORDER BY profile_id ASC LIMIT %d OFFSET %d", self::BATCH + 1, ( $page - 1 ) * self::BATCH ) ) );
		$has_more = count( $users ) > self::BATCH;
		foreach ( array_slice( $users, 0, self::BATCH ) as $user_id ) {
			try {
				self::inactive_for_vendor( $user_id );
			} catch ( Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup Communications inactive vendor failed for user ' . $user_id . ': ' . $e->getMessage() );
			}
		}
		if ( $has_more ) {
			LNKup_Comms_Queue::job( 'lnkup_comms_inactive_vendors', array( $page + 1 ), 30 );
		}
	}

	/**
	 * Inactivity segment for a number of idle days, or 0 when none applies.
	 */
	public static function inactive_segment( int $days ): int {
		$segment = 0;
		foreach ( self::INACTIVE_SEGMENTS as $threshold ) {
			if ( $days >= $threshold ) {
				$segment = $threshold;
			}
		}
		return $segment;
	}

	public static function inactive_for_vendor( int $user_id ): int {
		if ( $user_id < 1 ) {
			return 0;
		}
		$last    = LNKup_Comms_Engagement::vendor_last_activity( $user_id );
		$segment = $last ? self::inactive_segment( (int) floor( ( time() - $last ) / DAY_IN_SECONDS ) ) : 0;
		if ( ! $segment ) {
			return 0;
		}
		$recs = LNKup_Comms_Engagement::vendor_recommendations( $user_id, 3 );
		if ( ! $recs ) {
			return 0;
		}
		$items = array();
		foreach ( $recs as $item ) {
			$items[] = array(
				'title' => (string) ( $item['event_name'] ?? $item['name'] ?? '' ),
				'meta'  => trim( implode( ' · ', array_filter( array( (string) ( $item['location_label'] ?? '' ), ! empty( $item['date'] ) ? wp_date( 'M j', (int) strtotime( (string) $item['date'] ) ) : '', (string) ( $item['match']['reasons'][0] ?? '' ) ) ) ) ),
				'url'   => LNKup_Comms_Context::web_url( 'opportunity', LNKup_Comms_Context::build( array( 'opportunity_id' => (int) $item['opportunity_id'] ) ), 'vendor' ),
			);
		}
		return LNKup_Comms_Engine::dispatch(
			'v_inactive',
			$user_id,
			array( 'vendor_user_id' => $user_id, 'count' => (string) count( $items ), 'last_activity_ts' => $last ),
			array( 'window' => 'i' . $segment . '_' . gmdate( 'Ymd', $last ), 'items' => $items, 'cancel_key' => 'user:' . $user_id . '|inactive', 'source' => 'vendor_reactivation' )
		);
	}
}
