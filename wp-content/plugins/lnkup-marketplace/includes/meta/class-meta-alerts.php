<?php
/**
 * Administrator-only Meta integration alerts.
 *
 * Hourly checks with configurable thresholds and minimum-volume floors. Each alert stores
 * evidence, a *suspected* cause (never presented as confirmed) and a recommended action.
 * Alerts resolve themselves when the condition clears. Email goes only to the site admin
 * address, at most once per alert per 24 hours. Vendors and organizers never see these.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Meta_Alerts {

	const HOOK = 'lnkup_meta_alerts_eval';

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'evaluate' ) );
	}

	private static function count_conversions( string $where, array $args ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Meta_Tables::conversions() . ' WHERE ' . $where, $args ) );
	}

	private static function gmt( int $seconds_ago ): string {
		return gmdate( 'Y-m-d H:i:s', time() - $seconds_ago );
	}

	/**
	 * Window start that never reaches back before conversion recording existed.
	 *
	 * @param int $seconds_ago Window length.
	 */
	private static function window_start( int $seconds_ago ): int {
		return max( time() - $seconds_ago, LNKup_Meta_Measurement::installed_at() );
	}

	/**
	 * Run every check. Returns the alert keys currently open.
	 *
	 * @return string[]
	 */
	public static function evaluate(): array {
		$settings = LNKup_Meta_Config::settings();
		$min      = max( 1, (int) $settings['alert_min_volume'] );
		$capi_on  = '' === LNKup_Meta_Config::capi_block_reason();
		$found    = array();

		if ( $capi_on ) {
			$found += self::check_auth();
			$found += self::check_failure_rate( $min, (int) $settings['alert_failure_rate_pct'] );
			$found += self::check_failed_queue( (int) $settings['alert_failed_queue_max'] );
			$found += self::check_backlog( $min, (int) $settings['alert_backlog_minutes'] );
			$found += self::check_registration_gap( (int) $settings['alert_registration_gap_hours'] );
			$found += self::check_purchase_gap();
			$found += self::check_coverage_drop( $min, (int) $settings['alert_coverage_drop_pts'] );
		}
		if ( LNKup_Meta_Config::dataset_quality_enabled() ) {
			$found += self::check_dq_stale( (int) $settings['alert_dq_stale_hours'] );
			$found += self::check_emq_drop( (float) $settings['alert_emq_drop'] );
		}
		if ( LNKup_Meta_Config::pixel_enabled() ) {
			$found += self::check_pixel_conflict();
		}

		foreach ( $found as $key => $alert ) {
			self::open( $key, $alert );
		}
		self::resolve_missing( array_keys( $found ) );
		return array_keys( $found );
	}

	/**
	 * @return array<string,array<string,string>>
	 */
	private static function check_auth(): array {
		$last = get_option( 'lnkup_meta_last_delivery', array() );
		if ( ! is_array( $last ) || empty( $last['auth'] ) || (int) ( $last['at'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
			return array();
		}
		return array(
			'capi_auth' => array(
				'severity' => 'critical',
				'title'    => 'Conversions API credentials were rejected',
				'events'   => 'All server events',
				'evidence' => sprintf( 'Last delivery attempt at %s failed with Graph error %s.', gmdate( 'Y-m-d H:i', (int) $last['at'] ) . ' UTC', (string) ( $last['code'] ?? '' ) ),
				'cause'    => 'Suspected: the access token expired, was revoked, or lost access to the dataset.',
				'action'   => 'Generate a new token in Events Manager (Settings → Conversions API), update META_CAPI_ACCESS_TOKEN in wp-config.php, then run "Check credentials". Pending events retry automatically for up to 7 days.',
			),
		);
	}

	/**
	 * @return array<string,array<string,string>>
	 */
	private static function check_failure_rate( int $min, int $pct ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) total, SUM(http_status < 200 OR http_status >= 300) failed FROM ' . LNKup_Meta_Tables::deliveries() . " WHERE api = 'capi' AND is_test = 0 AND created_at >= %s", self::gmt( DAY_IN_SECONDS ) ), ARRAY_A );
		$total  = (int) ( $row['total'] ?? 0 );
		$failed = (int) ( $row['failed'] ?? 0 );
		if ( $total < $min || $failed * 100 < $pct * $total ) {
			return array();
		}
		return array(
			'delivery_failure_rate' => array(
				'severity' => 'high',
				'title'    => 'Conversions API deliveries are failing',
				'events'   => 'All server events',
				'evidence' => sprintf( '%d of %d delivery requests failed in the last 24 hours (threshold %d%%).', $failed, $total, $pct ),
				'cause'    => 'Suspected: Meta outage, rate limiting, or an invalid parameter in one event type. Check the Debugger for error codes.',
				'action'   => 'Open Meta Integration → Debugger, filter by failed, and review the sanitized error message.',
			),
		);
	}

	/**
	 * @return array<string,array<string,string>>
	 */
	private static function check_failed_queue( int $max ): array {
		$failed = self::count_conversions( "status = 'failed' AND created_at >= %s", array( self::gmt( 7 * DAY_IN_SECONDS ) ) );
		if ( $max < 1 || $failed < $max ) {
			return array();
		}
		return array(
			'failed_queue' => array(
				'severity' => 'medium',
				'title'    => 'Failed conversion events are accumulating',
				'events'   => 'Mixed',
				'evidence' => sprintf( '%d events reached the dead-letter state in the last 7 days (threshold %d).', $failed, $max ),
				'cause'    => 'Suspected: a persistent API rejection for a specific event type.',
				'action'   => 'Fix the cause shown in the Debugger, then use "Retry failed events" (only events younger than 7 days can still be sent).',
			),
		);
	}

	/**
	 * @return array<string,array<string,string>>
	 */
	private static function check_backlog( int $min, int $minutes ): array {
		$stuck = self::count_conversions( "status IN ('pending','sending') AND created_at < %s", array( self::gmt( max( 5, $minutes ) * MINUTE_IN_SECONDS ) ) );
		if ( $stuck < $min ) {
			return array();
		}
		return array(
			'delivery_backlog' => array(
				'severity' => 'medium',
				'title'    => 'Conversion events are delayed',
				'events'   => 'Mixed',
				'evidence' => sprintf( '%d events have been waiting more than %d minutes.', $stuck, $minutes ),
				'cause'    => 'Suspected: WP-Cron / Action Scheduler is not running, or deliveries are being retried after errors.',
				'action'   => 'Confirm a real server cron hits wp-cron.php (SiteGround: DISABLE_WP_CRON plus a cron job) and check Tools → Scheduled Actions.',
			),
		);
	}

	/**
	 * Registrations happened but no CompleteRegistration was recorded.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function check_registration_gap( int $hours ): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', self::window_start( max( 1, $hours ) * HOUR_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$users = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s WHERE u.user_registered >= %s AND (m.meta_value LIKE %s OR m.meta_value LIKE %s)", $wpdb->prefix . 'capabilities', $since, '%lnkup_vendor%', '%lnkup_organizer%' ) );
		$conversions = self::count_conversions( "event_name = 'CompleteRegistration' AND created_at >= %s", array( $since ) );
		if ( $users < 3 || $conversions > 0 ) {
			return array();
		}
		return array(
			'registration_gap' => array(
				'severity' => 'high',
				'title'    => 'Registrations are not producing CompleteRegistration events',
				'events'   => 'CompleteRegistration, VendorRegistered, OrganizerRegistered',
				'evidence' => sprintf( '%d vendor/organizer accounts registered in the last %d hours; 0 CompleteRegistration events recorded.', $users, $hours ),
				'cause'    => 'Suspected: accounts are created through a path that does not call LNKup_Registration::register_user (imports, admin-created users), or the lnkup_user_registered hook was removed.',
				'action'   => 'Check how these accounts were created. Imports and staff-created accounts are expected not to fire registration conversions.',
			),
		);
	}

	/**
	 * Paid bookings with a positive amount but no Purchase conversion.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function check_purchase_gap(): array {
		global $wpdb;
		// _lnkup_paid_at is stored in site time.
		$since = wp_date( 'Y-m-d H:i:s', self::window_start( 7 * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$paid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_payment_status' AND s.meta_value = 'paid' INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_lnkup_paid_at' AND a.meta_value >= %s INNER JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_lnkup_amount_paid_cents' AND CAST(c.meta_value AS UNSIGNED) > 0 WHERE p.post_type = 'lnkup_payment'", $since ) );
		$purchases = self::count_conversions( "event_name = 'Purchase' AND created_at >= %s", array( self::gmt( 8 * DAY_IN_SECONDS ) ) );
		$missing   = $paid - $purchases;
		if ( $paid < 3 || $missing < max( 3, (int) ceil( $paid * 0.2 ) ) ) {
			return array();
		}
		return array(
			'purchase_gap' => array(
				'severity' => 'high',
				'title'    => 'Paid bookings are missing Purchase events',
				'events'   => 'Purchase',
				'evidence' => sprintf( '%d paid bookings in the last 7 days; %d Purchase events recorded.', $paid, $purchases ),
				'cause'    => 'Suspected: payments were marked paid outside LNKup_Financial_Service::finalize_payment (manual reconciliation), which does not fire lnkup_payment_completed.',
				'action'   => 'Review recent payments in Financial → Reconciliation. Do not create Purchase events manually.',
			),
		);
	}

	/**
	 * Email / external_id coverage on authenticated conversions dropped week over week.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function check_coverage_drop( int $min, int $points ): array {
		$names = "event_name IN ('CompleteRegistration','SubmitApplication','InitiateCheckout','Purchase')";
		$now_total  = self::count_conversions( "{$names} AND created_at >= %s", array( self::gmt( 7 * DAY_IN_SECONDS ) ) );
		$prev_total = self::count_conversions( "{$names} AND created_at >= %s AND created_at < %s", array( self::gmt( 14 * DAY_IN_SECONDS ), self::gmt( 7 * DAY_IN_SECONDS ) ) );
		if ( $now_total < $min || $prev_total < $min ) {
			return array();
		}
		$alerts = array();
		foreach ( array( 'fbp' => 'Browser ID (fbp)', 'ua' => 'User agent', 'ip' => 'IP address' ) as $key => $label ) {
			$now  = self::count_conversions( "{$names} AND created_at >= %s AND FIND_IN_SET(%s, match_presence)", array( self::gmt( 7 * DAY_IN_SECONDS ), $key ) );
			$prev = self::count_conversions( "{$names} AND created_at >= %s AND created_at < %s AND FIND_IN_SET(%s, match_presence)", array( self::gmt( 14 * DAY_IN_SECONDS ), self::gmt( 7 * DAY_IN_SECONDS ), $key ) );
			$now_pct  = (int) round( 100 * $now / $now_total );
			$prev_pct = (int) round( 100 * $prev / $prev_total );
			if ( $prev_pct - $now_pct >= $points ) {
				$alerts[ 'coverage_' . $key ] = array(
					'severity' => 'medium',
					'title'    => $label . ' coverage dropped on key conversions',
					'events'   => 'CompleteRegistration, SubmitApplication, InitiateCheckout, Purchase',
					'evidence' => sprintf( '%s present on %d%% of events this week vs %d%% last week (%d vs %d events).', $label, $now_pct, $prev_pct, $now_total, $prev_total ),
					'cause'    => 'Suspected: Pixel disabled or blocked, a caching/proxy change hiding the visitor IP, or more events arriving from server-only contexts.',
					'action'   => 'Check that the Pixel loads on sign-up and checkout pages and that REMOTE_ADDR is the visitor IP (configure the lnkup_meta_client_ip filter behind a proxy).',
				);
			}
		}
		return $alerts;
	}

	/**
	 * @return array<string,array<string,string>>
	 */
	private static function check_dq_stale( int $hours ): array {
		$status = LNKup_Meta_Dataset_Quality::status();
		$last   = (int) ( $status['last_success_at'] ?? 0 );
		if ( ! empty( $status['auth_error'] ) ) {
			return array(
				'dq_access' => array(
					'severity' => 'high',
					'title'    => 'Dataset Quality API access failed',
					'events'   => 'Dataset Quality metrics',
					'evidence' => sprintf( 'Last check failed with Graph error %s.', (string) ( $status['error_code'] ?? '' ) ),
					'cause'    => 'Suspected: the token lacks dataset access ("Use events dataset") or ads_read, or was generated before the Events Manager opt-in.',
					'action'   => 'See docs/META_MEASUREMENT.md → Credentials. Set META_DATASET_QUALITY_ACCESS_TOKEN if the CAPI token cannot read quality data.',
				),
			);
		}
		if ( $last && $last > time() - max( 1, $hours ) * HOUR_IN_SECONDS ) {
			return array();
		}
		if ( ! $last && empty( $status['checked_at'] ) ) {
			return array();
		}
		return array(
			'dq_stale' => array(
				'severity' => 'low',
				'title'    => 'Dataset Quality sync is stale',
				'events'   => 'Dataset Quality metrics',
				'evidence' => $last ? sprintf( 'Last successful sync %s UTC.', gmdate( 'Y-m-d H:i', $last ) ) : 'No successful sync yet.',
				'cause'    => 'Suspected: WP-Cron is not running or the API returned errors.',
				'action'   => 'Use "Sync Dataset Quality now" on the Meta Integration page and review the error shown.',
			),
		);
	}

	/**
	 * Official EMQ fell by at least $drop points versus a week ago (Meta-reported only).
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function check_emq_drop( float $drop ): array {
		if ( $drop <= 0 ) {
			return array();
		}
		$series = LNKup_Meta_Dataset_Quality::series( 'emq_score', 10 );
		$alerts = array();
		foreach ( $series as $event => $points ) {
			$values = array_values( array_filter( $points, 'is_float' ) );
			if ( count( $values ) < 2 ) {
				continue;
			}
			$first = $values[0];
			$last  = $values[ count( $values ) - 1 ];
			if ( $first - $last >= $drop ) {
				$alerts[ 'emq_' . substr( sanitize_key( $event ), 0, 40 ) ] = array(
					'severity' => 'medium',
					'title'    => 'Event Match Quality declined for ' . $event,
					'events'   => $event,
					'evidence' => sprintf( 'Meta-reported EMQ fell from %.1f to %.1f over the last %d snapshots.', $first, $last, count( $values ) ),
					'cause'    => 'Suspected: fewer matching parameters (see coverage on the Events tab) or a Meta-side recalculation.',
					'action'   => 'Compare matching-parameter coverage for this event and review EMQ diagnostics on the Dataset Quality tab.',
				);
			}
		}
		return $alerts;
	}

	/**
	 * @return array<string,array<string,string>>
	 */
	private static function check_pixel_conflict(): array {
		$seen    = get_option( LNKup_Meta_REST::CONFLICT_OPTION, array() );
		$plugins = LNKup_Meta_Pixel::detected_plugins();
		$recent  = is_array( $seen ) && (int) ( $seen['at'] ?? 0 ) > time() - 3 * DAY_IN_SECONDS;
		if ( ! $recent && array() === $plugins ) {
			return array();
		}
		return array(
			'pixel_conflict' => array(
				'severity' => 'high',
				'title'    => 'A second Meta Pixel is running on the site',
				'events'   => 'PageView and all browser events',
				'evidence' => trim( ( array() !== $plugins ? 'Active plugin(s): ' . implode( ', ', $plugins ) . '. ' : '' ) . ( $recent ? 'Browser reported a second Pixel (' . (string) $seen['reason'] . ').' : '' ) ),
				'cause'    => 'Suspected: the legacy third-party Pixel plugin is still active alongside the LNKup Pixel.',
				'action'   => 'Deactivate the third-party Pixel plugin. Until then LNKup sends its conversions through the existing Pixel (trackSingle) but PageView may be double counted.',
			),
		);
	}

	/**
	 * Open or refresh an alert; email the site admin at most once per 24h per alert.
	 *
	 * @param string               $key   Alert key.
	 * @param array<string,string> $alert Alert.
	 */
	private static function open( string $key, array $alert ): void {
		global $wpdb;
		$table = LNKup_Meta_Tables::alerts();
		$now   = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE alert_key = %s AND status IN ('open','dismissed') ORDER BY id DESC LIMIT 1", $key ), ARRAY_A );
		$fields = array(
			'severity'           => $alert['severity'],
			'title'              => substr( $alert['title'], 0, 191 ),
			'affected_events'    => substr( $alert['events'], 0, 255 ),
			'evidence'           => $alert['evidence'],
			'suspected_cause'    => $alert['cause'],
			'recommended_action' => $alert['action'],
			'last_seen'          => $now,
		);
		if ( is_array( $row ) ) {
			$wpdb->update( $table, $fields, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$id       = (int) $row['id'];
			$notified = (string) ( $row['notified_at'] ?? '' );
			if ( 'dismissed' === $row['status'] ) {
				return;
			}
		} else {
			$wpdb->insert( $table, array_merge( $fields, array( 'alert_key' => $key, 'status' => 'open', 'first_seen' => $now ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$id       = (int) $wpdb->insert_id;
			$notified = '';
		}
		if ( '' !== $notified && strtotime( $notified . ' UTC' ) > time() - DAY_IN_SECONDS ) {
			return;
		}
		if ( ! in_array( $alert['severity'], array( 'critical', 'high' ), true ) ) {
			return;
		}
		$body = sprintf(
			'<p><strong>%s</strong> (%s)</p><p>%s</p><p><em>%s</em></p><p>%s</p><p><a href="%s">%s</a></p>',
			esc_html( $alert['title'] ),
			esc_html( $alert['severity'] ),
			esc_html( $alert['evidence'] ),
			esc_html( $alert['cause'] ),
			esc_html( $alert['action'] ),
			esc_url( admin_url( 'admin.php?page=lnkup-meta-health&tab=alerts' ) ),
			esc_html__( 'Open Meta Integration', 'lnkup-marketplace' )
		);
		$subject = '[LNKup] Meta integration: ' . $alert['title'];
		if ( class_exists( 'LNKup_Notifications' ) ) {
			LNKup_Notifications::send( (string) get_option( 'admin_email' ), $subject, $body );
		} else {
			wp_mail( (string) get_option( 'admin_email' ), $subject, wp_strip_all_tags( $body ) );
		}
		$wpdb->update( $table, array( 'notified_at' => $now ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * @param string[] $active Keys still firing.
	 */
	private static function resolve_missing( array $active ): void {
		global $wpdb;
		$table = LNKup_Meta_Tables::alerts();
		$now   = gmdate( 'Y-m-d H:i:s' );
		if ( array() === $active ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'resolved', resolved_at = %s WHERE status IN ('open','dismissed')", $now ) );
			return;
		}
		$in = implode( ',', array_fill( 0, count( $active ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'resolved', resolved_at = %s WHERE status IN ('open','dismissed') AND alert_key NOT IN ({$in})", array_merge( array( $now ), $active ) ) );
	}

	/**
	 * @param int    $id     Alert.
	 * @param string $status dismissed|resolved.
	 */
	public static function set_status( int $id, string $status ): void {
		global $wpdb;
		if ( ! in_array( $status, array( 'dismissed', 'resolved' ), true ) ) {
			return;
		}
		$wpdb->update( LNKup_Meta_Tables::alerts(), array( 'status' => $status, 'resolved_at' => 'resolved' === $status ? gmdate( 'Y-m-d H:i:s' ) : null ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}
}
