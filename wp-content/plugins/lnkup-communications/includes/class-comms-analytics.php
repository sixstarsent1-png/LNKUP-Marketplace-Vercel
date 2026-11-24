<?php
/**
 * Communication analytics.
 *
 * Every number here is read from stored rows. Nothing is estimated:
 * - "accepted" = provider accepted (Expo ticket / mail transport). Not device delivery.
 * - "delivered" only appears when Expo receipts or an email provider webhook confirm it.
 * - Opens are inbox reads / push taps reported by our apps. No email open pixels.
 * - "Action completed" = the target action happened within N days after the message
 *   (attribution window). This is correlation, not proof of causation.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Analytics {

	const OPTION = 'lnkup_comms_counters';

	/**
	 * Increment a lightweight daily counter (for events with no row of their own).
	 */
	public static function bump( string $metric, string $dimension = '' ): void {
		$data = get_option( self::OPTION, array() );
		$data = is_array( $data ) ? $data : array();
		$day  = gmdate( 'Y-m-d' );
		$key  = sanitize_key( $metric ) . ( '' !== $dimension ? '|' . substr( sanitize_key( $dimension ), 0, 48 ) : '' );
		$data[ $day ][ $key ] = (int) ( $data[ $day ][ $key ] ?? 0 ) + 1;
		if ( count( $data ) > 90 ) {
			ksort( $data );
			$data = array_slice( $data, -90, null, true );
		}
		update_option( self::OPTION, $data, false );
	}

	public static function counter( string $prefix, int $days ): array {
		$data = get_option( self::OPTION, array() );
		$out  = array();
		$from = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		foreach ( is_array( $data ) ? $data : array() as $day => $metrics ) {
			if ( $day < $from ) {
				continue;
			}
			foreach ( (array) $metrics as $key => $count ) {
				if ( 0 === strpos( (string) $key, $prefix ) ) {
					$out[ (string) $key ] = (int) ( $out[ (string) $key ] ?? 0 ) + (int) $count;
				}
			}
		}
		arsort( $out );
		return $out;
	}

	/**
	 * Operational overview for the admin dashboard.
	 */
	public static function overview( int $days = 7 ): array {
		global $wpdb;
		$messages   = LNKup_Comms_Schema::table( 'messages' );
		$deliveries = LNKup_Comms_Schema::table( 'deliveries' );
		$since      = LNKup_Comms_Schema::now( -$days * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_status = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS c FROM {$messages} WHERE created_at >= %s GROUP BY status", $since ), ARRAY_A );
		$channels  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT channel, status, COUNT(*) AS c FROM {$deliveries} WHERE created_at >= %s GROUP BY channel, status", $since ), ARRAY_A );
		$reasons   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT suppression_reason AS reason, COUNT(*) AS c FROM {$deliveries} WHERE created_at >= %s AND status = 'suppressed' AND suppression_reason <> '' GROUP BY suppression_reason ORDER BY c DESC LIMIT 12", $since ), ARRAY_A );
		$msg_reasons = (array) $wpdb->get_results( $wpdb->prepare( "SELECT SUBSTRING_INDEX(suppression_reason, ':', 2) AS reason, COUNT(*) AS c FROM {$messages} WHERE created_at >= %s AND status IN ('suppressed','cancelled','expired') GROUP BY reason ORDER BY c DESC LIMIT 12", $since ), ARRAY_A );
		$opened    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE created_at >= %s AND (opened_at IS NOT NULL OR read_at IS NOT NULL)", $since ) );
		$clicked   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE created_at >= %s AND clicked_at IS NOT NULL", $since ) );
		$pending_users = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT recipient_user_id) FROM {$messages} WHERE status = 'scheduled'" );
		$queued    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$messages} WHERE status = 'scheduled'" );
		// phpcs:enable

		$status_map = array();
		foreach ( $by_status as $row ) {
			$status_map[ (string) $row['status'] ] = (int) $row['c'];
		}
		$channel_map = array();
		foreach ( $channels as $row ) {
			$channel_map[ (string) $row['channel'] ][ (string) $row['status'] ] = (int) $row['c'];
		}
		return array(
			'days'               => $days,
			'messages'           => $status_map,
			'channels'           => $channel_map,
			'queued'             => $queued,
			'users_pending'      => $pending_users,
			'opened'             => $opened,
			'clicked'            => $clicked,
			'delivery_reasons'   => $reasons,
			'message_reasons'    => $msg_reasons,
			'active_automations' => count( array_filter( array_keys( LNKup_Comms_Settings::flags() ), array( 'LNKup_Comms_Settings', 'enabled' ) ) ),
			'claimed_core_keys'  => LNKup_Comms_Delegation::claimed_keys(),
			'unsubscribes'       => array_sum( self::counter( 'unsubscribed', $days ) ),
			'failed_jobs'        => LNKup_Comms_Queue::failed_actions( $days * DAY_IN_SECONDS ),
		);
	}

	/**
	 * Per-trigger performance with attribution where an action can be measured.
	 */
	public static function trigger_report( int $days = 30 ): array {
		global $wpdb;
		$messages = LNKup_Comms_Schema::table( 'messages' );
		$since    = LNKup_Comms_Schema::now( -$days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT trigger_key,
					COUNT(*) AS created,
					SUM(status IN ('sent','partial')) AS sent,
					SUM(status = 'suppressed') AS suppressed,
					SUM(status = 'cancelled') AS cancelled,
					SUM(status = 'failed') AS failed,
					SUM(opened_at IS NOT NULL OR read_at IS NOT NULL) AS opened,
					SUM(clicked_at IS NOT NULL) AS clicked
				FROM {$messages} WHERE created_at >= %s GROUP BY trigger_key ORDER BY created DESC",
				$since
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $row ) {
			$trigger              = (string) $row['trigger_key'];
			$row['actioned']      = self::attributed( $trigger, $since );
			$row['action_label']  = self::action_label( $trigger );
			$row['flow']          = self::flow_group( $trigger );
			$out[]                = $row;
		}
		return $out;
	}

	public static function flow_group( string $trigger ): string {
		if ( 'campaign_message' === $trigger ) {
			return 'campaigns';
		}
		if ( in_array( $trigger, array( 'v_welcome', 'v_onboarding', 'o_welcome', 'o_onboarding', 'o_event_published' ), true ) ) {
			return 'onboarding';
		}
		if ( in_array( $trigger, array( 'v_opportunity_match', 'v_organizer_new_opportunity', 'digest_email', 'v_tour_match', 'v_weekly_matches', 'v_saved_search_match', 'v_saved_deadline', 'v_invited_to_apply', 'v_invite_expiring' ), true ) ) {
			return 'discovery';
		}
		if ( in_array( $trigger, array( 'v_application_draft', 'v_inactive' ), true ) ) {
			return 'vendor_engagement';
		}
		if ( in_array( $trigger, array( 'o_recruit_vendors', 'o_no_applications', 'o_publish_next', 'o_reactivation', 'o_open_spots', 'o_new_matching_vendors', 'o_new_vendors_nearby', 'o_invite_response', 'o_event_no_slots', 'o_setup_profile', 'o_setup_payouts', 'o_publish_draft', 'o_first_booking' ), true ) ) {
			return 'organizer_activation';
		}
		if ( in_array( $trigger, array( 'v_event_thanks', 'o_event_completed' ), true ) ) {
			return 'retention';
		}
		return 'transactional';
	}

	private static function action_label( string $trigger ): string {
		$labels = array(
			'v_application_approved'      => __( 'Paid within window', 'lnkup-communications' ),
			'v_payment_reminder'          => __( 'Paid within window', 'lnkup-communications' ),
			'v_payment_failed'            => __( 'Paid within window', 'lnkup-communications' ),
			'v_documents_needed'          => __( 'Uploaded a document', 'lnkup-communications' ),
			'v_document_rejected'         => __( 'Uploaded a document', 'lnkup-communications' ),
			'v_opportunity_match'         => __( 'Applied to the opportunity', 'lnkup-communications' ),
			'v_organizer_new_opportunity' => __( 'Applied to the opportunity', 'lnkup-communications' ),
			'v_saved_deadline'            => __( 'Applied to the opportunity', 'lnkup-communications' ),
			'v_invited_to_apply'          => __( 'Applied to the opportunity', 'lnkup-communications' ),
			'o_onboarding'                => __( 'Created an event', 'lnkup-communications' ),
			'o_welcome'                   => __( 'Created an event', 'lnkup-communications' ),
			'o_publish_next'              => __( 'Created an event', 'lnkup-communications' ),
			'o_reactivation'              => __( 'Created an event', 'lnkup-communications' ),
			'o_setup_profile'             => __( 'Completed organization profile', 'lnkup-communications' ),
			'o_setup_payouts'             => __( 'Finished payout setup', 'lnkup-communications' ),
			'o_publish_draft'             => __( 'Published an event', 'lnkup-communications' ),
			'v_application_draft'         => __( 'Submitted an application', 'lnkup-communications' ),
			'v_inactive'                  => __( 'Submitted an application', 'lnkup-communications' ),
			'o_new_vendors_nearby'        => __( 'Invited a vendor to the event', 'lnkup-communications' ),
			'o_new_matching_vendors'      => __( 'Invited a vendor to the event', 'lnkup-communications' ),
			'o_open_spots'                => __( 'Invited a vendor to the event', 'lnkup-communications' ),
			'o_event_no_slots'            => __( 'Added a vendor opportunity', 'lnkup-communications' ),
		);
		return $labels[ $trigger ] ?? '';
	}

	/**
	 * Messages followed by the target action within the attribution window.
	 */
	private static function attributed( string $trigger, string $since ): ?int {
		global $wpdb;
		$messages = LNKup_Comms_Schema::table( 'messages' );
		$window   = max( 1, LNKup_Comms_Settings::int( 'attribution_days' ) ) * DAY_IN_SECONDS;
		// _lnkup_paid_at is stored in site time; message timestamps are UTC.
		$offset   = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
		$pm       = $wpdb->postmeta;
		$posts    = $wpdb->posts;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		switch ( $trigger ) {
			case 'v_application_approved':
			case 'v_payment_reminder':
			case 'v_payment_failed':
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT m.id) FROM {$messages} m INNER JOIN {$pm} p ON p.post_id = m.application_id AND p.meta_key = '_lnkup_paid_at'
						WHERE m.trigger_key = %s AND m.created_at >= %s AND m.status IN ('sent','partial') AND p.meta_value >= DATE_ADD(m.sent_at, INTERVAL %d SECOND) AND p.meta_value <= DATE_ADD(m.sent_at, INTERVAL %d SECOND)",
						$trigger,
						$since,
						$offset,
						$offset + $window
					)
				);
			case 'v_documents_needed':
			case 'v_document_rejected':
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT m.id) FROM {$messages} m INNER JOIN {$posts} d ON d.post_type = 'lnkup_document' AND d.post_author = m.recipient_user_id
						WHERE m.trigger_key = %s AND m.created_at >= %s AND m.status IN ('sent','partial') AND d.post_date_gmt >= m.sent_at AND d.post_date_gmt <= DATE_ADD(m.sent_at, INTERVAL %d SECOND)",
						$trigger,
						$since,
						$window
					)
				);
			case 'v_opportunity_match':
			case 'v_organizer_new_opportunity':
			case 'v_saved_deadline':
			case 'v_invited_to_apply':
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT m.id) FROM {$messages} m
						INNER JOIN {$pm} o ON o.meta_key = '_lnkup_opportunity_id' AND o.meta_value = m.opportunity_id
						INNER JOIN {$pm} v ON v.post_id = o.post_id AND v.meta_key = '_lnkup_vendor_user_id' AND v.meta_value = m.recipient_user_id
						INNER JOIN {$posts} a ON a.ID = o.post_id AND a.post_type = 'lnkup_application' AND a.post_status <> 'lnkup_app_draft'
						WHERE m.trigger_key = %s AND m.created_at >= %s AND m.status IN ('sent','partial') AND a.post_date_gmt >= m.sent_at AND a.post_date_gmt <= DATE_ADD(m.sent_at, INTERVAL %d SECOND)",
						$trigger,
						$since,
						$window
					)
				);
			case 'o_onboarding':
			case 'o_welcome':
			case 'o_publish_next':
			case 'o_reactivation':
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT m.id) FROM {$messages} m
						INNER JOIN {$pm} o ON o.meta_key = '_lnkup_organizer_user_id' AND o.meta_value = m.recipient_user_id
						INNER JOIN {$posts} e ON e.ID = o.post_id AND e.post_type = 'lnkup_event'
						WHERE m.trigger_key = %s AND m.created_at >= %s AND m.status IN ('sent','partial') AND e.post_date_gmt >= m.sent_at AND e.post_date_gmt <= DATE_ADD(m.sent_at, INTERVAL %d SECOND)",
						$trigger,
						$since,
						$window
					)
				);
			case 'o_setup_profile':
			case 'o_setup_payouts':
			case 'o_publish_draft':
				return self::activation_attributed( $trigger, $since, $window );
			case 'v_application_draft':
			case 'v_inactive':
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT m.id) FROM {$messages} m
						INNER JOIN {$pm} v ON v.meta_key = '_lnkup_vendor_user_id' AND v.meta_value = m.recipient_user_id
						INNER JOIN {$posts} a ON a.ID = v.post_id AND a.post_type = 'lnkup_application' AND a.post_status NOT IN ('trash','lnkup_app_draft')
						WHERE m.trigger_key = %s AND m.created_at >= %s AND m.status IN ('sent','partial') AND a.post_date_gmt >= m.sent_at AND a.post_date_gmt <= DATE_ADD(m.sent_at, INTERVAL %d SECOND)",
						$trigger,
						$since,
						$window
					)
				);
			case 'o_new_vendors_nearby':
			case 'o_new_matching_vendors':
			case 'o_open_spots':
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT m.id) FROM {$messages} m
						INNER JOIN {$pm} e ON e.meta_key = '_lnkup_event_id' AND e.meta_value = m.event_id
						INNER JOIN {$posts} i ON i.ID = e.post_id AND i.post_type = 'lnkup_vendor_invite' AND i.post_author = m.recipient_user_id
						WHERE m.trigger_key = %s AND m.created_at >= %s AND m.status IN ('sent','partial') AND i.post_date_gmt >= m.sent_at AND i.post_date_gmt <= DATE_ADD(m.sent_at, INTERVAL %d SECOND)",
						$trigger,
						$since,
						$window
					)
				);
			case 'o_event_no_slots':
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT m.id) FROM {$messages} m
						INNER JOIN {$pm} e ON e.meta_key = '_lnkup_event_id' AND e.meta_value = m.event_id
						INNER JOIN {$posts} o ON o.ID = e.post_id AND o.post_type = 'lnkup_opportunity'
						WHERE m.trigger_key = %s AND m.created_at >= %s AND m.status IN ('sent','partial') AND o.post_date_gmt >= m.sent_at AND o.post_date_gmt <= DATE_ADD(m.sent_at, INTERVAL %d SECOND)",
						$trigger,
						$since,
						$window
					)
				);
			default:
				return null;
		}
		// phpcs:enable
	}

	/**
	 * Activation nudges followed by the organizer completing that stage within the window.
	 * Uses the stage snapshot stored in the message context and the first-completed time the
	 * marketplace activation model records (`_lnkup_activation_done_{stage}`, UTC).
	 */
	public static function activation_attributed( string $trigger, string $since, int $window ): int {
		global $wpdb;
		$messages = LNKup_Comms_Schema::table( 'messages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT recipient_user_id, sent_at, context FROM {$messages} WHERE trigger_key = %s AND created_at >= %s AND status IN ('sent','partial') AND sent_at IS NOT NULL LIMIT 5000", $trigger, $since ), ARRAY_A );
		$count = 0;
		foreach ( (array) $rows as $row ) {
			$ctx   = json_decode( (string) $row['context'], true );
			$stage = is_array( $ctx ) ? sanitize_key( (string) ( $ctx['activation_stage'] ?? '' ) ) : '';
			if ( '' === $stage ) {
				continue;
			}
			$done = (string) get_user_meta( (int) $row['recipient_user_id'], '_lnkup_activation_done_' . $stage, true );
			$sent = strtotime( (string) $row['sent_at'] . ' UTC' );
			$at   = '' !== $done ? strtotime( $done . ' UTC' ) : false;
			if ( $at && $sent && $at >= $sent && $at <= $sent + $window ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Marketplace activation funnel for the period (real record counts).
	 */
	public static function marketplace_funnel( int $days = 30 ): array {
		$after   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$vendors = get_users( array( 'role__in' => array( 'lnkup_vendor' ), 'fields' => 'ID', 'date_query' => array( array( 'after' => $after, 'column' => 'user_registered' ) ), 'number' => 2000 ) );
		$orgs    = get_users( array( 'role__in' => array( 'lnkup_organizer' ), 'fields' => 'ID', 'date_query' => array( array( 'after' => $after, 'column' => 'user_registered' ) ), 'number' => 2000 ) );

		$profile_complete = 0;
		$applied          = 0;
		foreach ( array_map( 'intval', (array) $vendors ) as $vendor_id ) {
			$step = LNKup_Comms_Context::vendor_next_step( $vendor_id )['key'];
			if ( in_array( $step, array( '', 'first_application' ), true ) ) {
				++$profile_complete;
			}
			if ( '' === $step ) {
				++$applied;
			}
		}
		$with_event = 0;
		$published  = 0;
		foreach ( array_map( 'intval', (array) $orgs ) as $org_id ) {
			$step = LNKup_Comms_Context::organizer_next_step( $org_id )['key'];
			if ( 'first_event' !== $step ) {
				++$with_event;
			}
			if ( in_array( $step, array( '', 'payouts' ), true ) ) {
				++$published;
			}
		}
		return array(
			'days'                     => $days,
			'new_vendors'              => count( (array) $vendors ),
			'vendors_profile_complete' => $profile_complete,
			'vendors_applied'          => $applied,
			'new_organizers'           => count( (array) $orgs ),
			'organizers_created_event' => $with_event,
			'organizers_published'     => $published,
			'capped'                   => count( (array) $vendors ) >= 2000 || count( (array) $orgs ) >= 2000,
		);
	}
}
