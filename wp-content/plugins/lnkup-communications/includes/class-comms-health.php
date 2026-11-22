<?php
/**
 * Delivery health checks, safe operator actions and alerting.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Health {

	public static function init(): void {
		add_action( 'lnkup_comms_hourly', array( __CLASS__, 'alert_if_needed' ), 50 );
	}

	/**
	 * @return array<int, array{key:string,label:string,status:string,detail:string,action:string}>
	 */
	public static function checks(): array {
		global $wpdb;
		$messages   = LNKup_Comms_Schema::table( 'messages' );
		$deliveries = LNKup_Comms_Schema::table( 'deliveries' );
		$day        = LNKup_Comms_Schema::now( -DAY_IN_SECONDS );
		$checks     = array();

		$last_hourly = self::last_completed( 'lnkup_comms_hourly' );
		$runner_ok   = $last_hourly && $last_hourly > time() - 2 * HOUR_IN_SECONDS;
		$checks[]    = array(
			'key'    => 'cron_runner',
			'label'  => __( 'Background worker', 'lnkup-communications' ),
			'status' => $runner_ok ? 'ok' : 'error',
			'detail' => $runner_ok
				/* translators: %s: time ago */
				? sprintf( __( 'Hourly job last completed %s ago.', 'lnkup-communications' ), human_time_diff( (int) $last_hourly ) )
				: __( 'The hourly job has not completed in the last 2 hours. Configure a server cron that runs `wp action-scheduler run` every minute (and set DISABLE_WP_CRON). Time-sensitive reminders will be late until this is fixed.', 'lnkup-communications' ),
			'action' => '',
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$backlog = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE status = 'scheduled' AND scheduled_at < %s", LNKup_Comms_Schema::now( -15 * MINUTE_IN_SECONDS ) ) );
		$stuck   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE status = 'sending' AND updated_at < %s", LNKup_Comms_Schema::now( -30 * MINUTE_IN_SECONDS ) ) );
		$email   = (array) $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status IN ('provider_accepted','delivered')) AS ok, SUM(status = 'failed') AS failed, SUM(status = 'bounced') AS bounced FROM {$deliveries} WHERE channel = 'email' AND created_at >= %s", $day ), ARRAY_A );
		$push    = (array) $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status IN ('provider_accepted','delivered')) AS ok, SUM(status = 'failed') AS failed FROM {$deliveries} WHERE channel = 'push' AND created_at >= %s", $day ), ARRAY_A );
		$render  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE status = 'failed' AND suppression_reason LIKE 'render_failed%%' AND updated_at >= %s", $day ) );
		$dupes   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT recipient_user_id, trigger_key, application_id, COUNT(*) c FROM {$messages} WHERE status IN ('sent','partial') AND application_id > 0 AND sent_at >= %s AND trigger_key NOT IN ('v_payment_reminder','v_countdown','o_countdown','v_announcement','v_documents_needed') GROUP BY recipient_user_id, trigger_key, application_id HAVING c > 1) d", $day ) );
		$last_hr = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE created_at >= %s", LNKup_Comms_Schema::now( -HOUR_IN_SECONDS ) ) );
		$prev    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE created_at >= %s AND created_at < %s", $day, LNKup_Comms_Schema::now( -HOUR_IN_SECONDS ) ) );
		// phpcs:enable

		$checks[] = self::threshold( 'backlog', __( 'Queue backlog', 'lnkup-communications' ), $backlog, 1, 50, __( '%d messages are overdue for delivery.', 'lnkup-communications' ), 'sweep' );
		$checks[] = self::threshold( 'stuck', __( 'Stuck deliveries', 'lnkup-communications' ), $stuck, 1, 10, __( '%d messages have been "sending" for over 30 minutes.', 'lnkup-communications' ), 'sweep' );

		$e_ok   = (int) ( $email['ok'] ?? 0 );
		$e_fail = (int) ( $email['failed'] ?? 0 );
		$rate   = ( $e_ok + $e_fail ) > 0 ? $e_fail / ( $e_ok + $e_fail ) : 0.0;
		$checks[] = array(
			'key'    => 'email',
			'label'  => __( 'Email transport', 'lnkup-communications' ),
			'status' => $rate >= 0.25 ? 'error' : ( $rate >= 0.05 ? 'warn' : 'ok' ),
			/* translators: 1: accepted, 2: failed, 3: bounced */
			'detail' => sprintf( __( 'Last 24h: %1$d accepted by the mail transport, %2$d failed, %3$d bounced (provider-reported).', 'lnkup-communications' ), $e_ok, $e_fail, (int) ( $email['bounced'] ?? 0 ) ),
			'action' => $e_fail > 0 ? 'retry_failed' : '',
		);
		$p_fail   = (int) ( $push['failed'] ?? 0 );
		$disabled = array_sum( LNKup_Comms_Analytics::counter( 'push_device_disabled', 1 ) );
		$checks[] = array(
			'key'    => 'push',
			'label'  => __( 'Push (Expo)', 'lnkup-communications' ),
			'status' => $p_fail > max( 10, (int) ( $push['ok'] ?? 0 ) ) ? 'warn' : 'ok',
			/* translators: 1: accepted, 2: failed, 3: disabled tokens */
			'detail' => sprintf( __( 'Last 24h: %1$d tickets accepted by Expo (not proof of display), %2$d failed, %3$d invalid tokens disabled.', 'lnkup-communications' ), (int) ( $push['ok'] ?? 0 ), $p_fail, $disabled ),
			'action' => '',
		);
		$checks[] = self::threshold( 'render', __( 'Template rendering', 'lnkup-communications' ), $render, 1, 20, __( '%d messages were not sent because a required variable was missing.', 'lnkup-communications' ), '' );
		$checks[] = self::threshold( 'duplicates', __( 'Duplicate sends', 'lnkup-communications' ), $dupes, 1, 5, __( '%d recipient/message pairs were sent more than once in 24h.', 'lnkup-communications' ), '' );
		$avg      = $prev / 23;
		$checks[] = array(
			'key'    => 'spike',
			'label'  => __( 'Volume spike', 'lnkup-communications' ),
			'status' => ( $last_hr > 100 && $last_hr > 5 * max( 1.0, $avg ) ) ? 'warn' : 'ok',
			/* translators: 1: last hour, 2: average */
			'detail' => sprintf( __( '%1$d messages in the last hour (hourly average %2$s).', 'lnkup-communications' ), $last_hr, number_format_i18n( $avg, 1 ) ),
			'action' => '',
		);
		$stale    = self::stale_scheduled( false );
		$checks[] = self::threshold( 'stale', __( 'Obsolete scheduled reminders', 'lnkup-communications' ), $stale, 1, 1, __( '%d scheduled messages reference paid, cancelled or past bookings/events. They will be suppressed at send time; you can cancel them now.', 'lnkup-communications' ), 'cancel_stale' );
		$checks[] = array(
			'key'    => 'failed_jobs',
			'label'  => __( 'Failed background jobs', 'lnkup-communications' ),
			'status' => LNKup_Comms_Queue::failed_actions() > 0 ? 'warn' : 'ok',
			/* translators: %d: count */
			'detail' => sprintf( __( '%d failed jobs in the last 24h (Tools → Scheduled Actions, group lnkup-comms).', 'lnkup-communications' ), LNKup_Comms_Queue::failed_actions() ),
			'action' => '',
		);

		$settings  = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		$checks[]  = array(
			'key'    => 'expo_token',
			'label'  => __( 'Expo access token', 'lnkup-communications' ),
			'status' => '' !== trim( (string) ( $settings['expo_push_access_token'] ?? '' ) ) ? 'ok' : 'warn',
			'detail' => __( 'Recommended when "Enhanced push security" is on in the Expo project. Set in LNKup → Settings.', 'lnkup-communications' ),
			'action' => '',
		);
		$checks[] = array(
			'key'    => 'address',
			'label'  => __( 'Sender postal address', 'lnkup-communications' ),
			'status' => '' !== (string) LNKup_Comms_Settings::get( 'company_address' ) ? 'ok' : 'warn',
			'detail' => __( 'Commercial (promotional) emails should include a physical mailing address (CAN-SPAM).', 'lnkup-communications' ),
			'action' => '',
		);
		$checks[] = array(
			'key'    => 'email_webhook',
			'label'  => __( 'Bounce & complaint webhook', 'lnkup-communications' ),
			'status' => '' !== (string) LNKup_Comms_Settings::get( 'email_webhook_secret' ) ? 'ok' : 'warn',
			'detail' => __( 'Without provider webhooks, bounces and spam complaints cannot suppress future sends automatically.', 'lnkup-communications' ),
			'action' => '',
		);
		return $checks;
	}

	private static function threshold( string $key, string $label, int $value, int $warn, int $error, string $detail, string $action ): array {
		return array(
			'key'    => $key,
			'label'  => $label,
			'status' => $value >= $error ? 'error' : ( $value >= $warn ? 'warn' : 'ok' ),
			'detail' => sprintf( $detail, $value ),
			'action' => $value > 0 ? $action : '',
		);
	}

	private static function last_completed( string $hook ): ?int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! did_action( 'action_scheduler_init' ) ) {
			return null;
		}
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => LNKup_Comms_Queue::GROUP,
				'status'   => ActionScheduler_Store::STATUS_COMPLETE,
				'per_page' => 1,
				'orderby'  => 'date',
				'order'    => 'DESC',
			)
		);
		foreach ( (array) $actions as $action ) {
			if ( is_object( $action ) && method_exists( $action, 'get_schedule' ) ) {
				$date = $action->get_schedule()->get_date();
				return $date ? $date->getTimestamp() : null;
			}
		}
		return null;
	}

	/**
	 * Scheduled messages whose underlying booking/event state already makes them obsolete.
	 */
	public static function stale_scheduled( bool $cancel ): int {
		global $wpdb;
		$table = LNKup_Comms_Schema::table( 'messages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = (array) $wpdb->get_results( "SELECT id, trigger_key, context FROM {$table} WHERE status = 'scheduled' ORDER BY id ASC LIMIT 2000", ARRAY_A );
		$stale = array();
		foreach ( $rows as $row ) {
			$def = LNKup_Comms_Registry::get( (string) $row['trigger_key'] );
			if ( ! $def || empty( $def['validity'] ) ) {
				continue;
			}
			$stored = json_decode( (string) $row['context'], true );
			$ctx    = LNKup_Comms_Context::build( is_array( $stored ) ? $stored : array() );
			if ( '' !== LNKup_Comms_Context::check( (string) $def['validity'], $ctx ) ) {
				$stale[] = (int) $row['id'];
			}
		}
		if ( $cancel && $stale ) {
			foreach ( array_chunk( $stale, 500 ) as $chunk ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'cancelled', suppression_reason = 'cancelled:stale', updated_at = %s WHERE status = 'scheduled' AND id IN (" . implode( ',', $chunk ) . ')', LNKup_Comms_Schema::now() ) );
			}
		}
		return count( $stale );
	}

	/**
	 * Retry failed email/push deliveries from the last 24h (not yet expired, < 4 attempts).
	 */
	public static function retry_failed(): int {
		global $wpdb;
		$deliveries = LNKup_Comms_Schema::table( 'deliveries' );
		$messages   = LNKup_Comms_Schema::table( 'messages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT d.message_id, d.channel FROM {$deliveries} d INNER JOIN {$messages} m ON m.id = d.message_id
				WHERE d.status = 'failed' AND d.channel IN ('email','push') AND d.created_at >= %s AND (m.expires_at IS NULL OR m.expires_at > %s)
				AND NOT EXISTS (SELECT 1 FROM {$deliveries} x WHERE x.message_id = d.message_id AND x.channel = d.channel AND x.status IN ('provider_accepted','delivered'))
				LIMIT 500",
				LNKup_Comms_Schema::now( -DAY_IN_SECONDS ),
				LNKup_Comms_Schema::now()
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			LNKup_Comms_Queue::enqueue_retry( (int) $row['message_id'], (string) $row['channel'], 5 );
		}
		return count( $rows );
	}

	/**
	 * Email site admins at most every 6 hours while any check is in error.
	 */
	public static function alert_if_needed(): void {
		$errors = array_filter(
			self::checks(),
			static function ( array $check ): bool {
				return 'error' === $check['status'];
			}
		);
		if ( ! $errors || get_transient( 'lnkup_comms_health_alerted' ) ) {
			return;
		}
		set_transient( 'lnkup_comms_health_alerted', 1, 6 * HOUR_IN_SECONDS );
		$summary = implode( ' ', wp_list_pluck( $errors, 'detail' ) );
		$settings = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		$to       = is_email( (string) ( $settings['support_email'] ?? '' ) ) ? (string) $settings['support_email'] : (string) get_option( 'admin_email' );
		$rendered = LNKup_Comms_Templates::render( 'admin_health_alert', array( 'summary_line' => $summary ), 'admin' );
		if ( ! is_wp_error( $rendered ) ) {
			LNKup_Comms_Channel_Email::send_raw( $to, $rendered, admin_url( 'admin.php?page=lnkup-communications&tab=health' ) );
		}
	}
}
