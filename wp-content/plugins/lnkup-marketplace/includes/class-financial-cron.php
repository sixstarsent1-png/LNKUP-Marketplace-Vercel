<?php
/**
 * Financial reminders and maintenance cron.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Cron {

	const HOOK = 'lnkup_financial_daily';

	const HOURLY_HOOK = 'lnkup_financial_hourly';

	/** Option holding the last hourly reconciliation run summary. */
	const HEALTH_OPTION = 'lnkup_fin_reconcile_health';

	/** Max users synced per hourly run (each sync may call Stripe). */
	const SYNC_BATCH = 25;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_daily' ) );
		add_action( self::HOURLY_HOOK, array( __CLASS__, 'run_hourly' ) );
		add_action( 'lnkup_financial_reminder', array( __CLASS__, 'send_reminder_email' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_hourly' ), 20 );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 7200, 'daily', self::HOOK );
		}
		self::maybe_schedule_hourly();
	}

	public static function maybe_schedule_hourly() {
		if ( ! wp_next_scheduled( self::HOURLY_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOURLY_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::HOURLY_HOOK );
	}

	/**
	 * Hourly: retry failed webhooks, then sync users with payments that are unpaid-with-a-Stripe-
	 * reference or paid-but-unsettled since the auto-recovery cutoff. Serialized by a DB lock.
	 */
	public static function run_hourly() {
		$started = microtime( true );
		$result  = LNKup_Financial_Service::with_lock(
			'fin_hourly',
			static function () {
				LNKup_Stripe_Webhooks::retry_failed();
				LNKup_Refunds::requeue_stuck_processing();
				$synced = array();
				$errors = array();
				foreach ( self::users_needing_sync() as $row ) {
					$sync = 'organizer' === $row['role']
						? LNKup_Financial_Service::sync_organizer( $row['user_id'] )
						: LNKup_Financial_Service::sync_vendor( $row['user_id'] );
					if ( is_wp_error( $sync ) ) {
						$errors[] = $row['role'] . ':' . $row['user_id'] . ' ' . $sync->get_error_code();
						continue;
					}
					$synced[] = $row['role'] . ':' . $row['user_id'];
				}
				return array( 'synced' => $synced, 'errors' => $errors );
			},
			0
		);
		$summary = array(
			'ran_at'      => current_time( 'mysql' ),
			'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'skipped'     => is_wp_error( $result ),
			'synced'      => is_wp_error( $result ) ? array() : $result['synced'],
			'errors'      => is_wp_error( $result ) ? array( $result->get_error_code() ) : $result['errors'],
		);
		update_option( self::HEALTH_OPTION, $summary, false );
		return $summary;
	}

	/**
	 * Organizers and vendors with payments the hourly sync can act on.
	 *
	 * @return array<int, array{user_id:int, role:string}>
	 */
	public static function users_needing_sync() {
		global $wpdb;
		$cutoff = (string) get_option( 'lnkup_fin_auto_recovery_since', '' );
		if ( '' === $cutoff ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, st.meta_value AS status, org.meta_value AS organizer_id, ven.meta_value AS vendor_id
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_lnkup_payment_status'
				LEFT JOIN {$wpdb->postmeta} org ON org.post_id = p.ID AND org.meta_key = '_lnkup_organizer_user_id'
				LEFT JOIN {$wpdb->postmeta} ven ON ven.post_id = p.ID AND ven.meta_key = '_lnkup_vendor_user_id'
				LEFT JOIN {$wpdb->postmeta} pi ON pi.post_id = p.ID AND pi.meta_key = '_lnkup_stripe_payment_intent'
				LEFT JOIN {$wpdb->postmeta} cs ON cs.post_id = p.ID AND cs.meta_key = '_lnkup_stripe_checkout_session'
				LEFT JOIN {$wpdb->postmeta} sa ON sa.post_id = p.ID AND sa.meta_key = '_lnkup_settled_at'
				LEFT JOIN {$wpdb->postmeta} pa ON pa.post_id = p.ID AND pa.meta_key = '_lnkup_paid_at'
				WHERE p.post_type = 'lnkup_payment'
				AND (
					( st.meta_value IN ('pending','processing','failed') AND p.post_date >= %s
						AND ( COALESCE(pi.meta_value,'') <> '' OR COALESCE(cs.meta_value,'') <> '' ) )
					OR ( st.meta_value = 'paid' AND COALESCE(sa.meta_value,'') = '' AND COALESCE(NULLIF(pa.meta_value,''), p.post_date) >= %s )
				)
				ORDER BY p.ID DESC LIMIT 200",
				$cutoff,
				$cutoff
			),
			ARRAY_A
		);
		$users = array();
		foreach ( (array) $rows as $row ) {
			if ( 'paid' === $row['status'] && absint( $row['organizer_id'] ) ) {
				$users[ 'organizer:' . absint( $row['organizer_id'] ) ] = array( 'user_id' => absint( $row['organizer_id'] ), 'role' => 'organizer' );
			} elseif ( absint( $row['vendor_id'] ) ) {
				$users[ 'vendor:' . absint( $row['vendor_id'] ) ] = array( 'user_id' => absint( $row['vendor_id'] ), 'role' => 'vendor' );
			}
		}
		return array_slice( array_values( $users ), 0, self::SYNC_BATCH );
	}

	public static function run_daily() {
		$today = current_time( 'Y-m-d' );
		if ( get_transient( 'lnkup_fin_daily_' . $today ) ) {
			return;
		}
		set_transient( 'lnkup_fin_daily_' . $today, 1, DAY_IN_SECONDS );
		self::check_overdue_invoices();
		self::check_payment_reminders();
		self::check_document_reminders();
		self::check_arrival_reminders();
		LNKup_Notifications::resume_due_retries();
		self::check_installment_due();
		self::check_deposit_due();
		self::release_expired_payment_holds();
		self::mark_expired_payment_methods();
		if ( class_exists( 'LNKup_Reserves' ) ) {
			LNKup_Reserves::release_expired();
		}
		if ( class_exists( 'LNKup_Financial_Health_Score' ) ) {
			LNKup_Financial_Health_Score::compute_all();
		}
		do_action( 'lnkup_financial_cron' );
		delete_transient( 'lnkup_fin_market_summary' );
	}

	/**
	 * Release spots held by approvals that were never paid. Off unless the
	 * `payment_hold_expiry_days` setting is a positive number of days past the due date.
	 */
	public static function release_expired_payment_holds() {
		$settings = lnkup_get_settings();
		$grace    = absint( $settings['payment_hold_expiry_days'] ?? 0 );
		if ( ! $grace ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $grace * DAY_IN_SECONDS );
		$apps   = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_application_status', 'value' => 'payment_required' ),
					array( 'key' => '_lnkup_payment_due_at', 'value' => $cutoff, 'compare' => '<', 'type' => 'DATETIME' ),
				),
			)
		);
		$released = 0;
		foreach ( $apps as $app_id ) {
			$payment = LNKup_Payments::get_for_application( $app_id );
			if ( $payment && 'pending' !== (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ) ) {
				continue;
			}
			if ( true === LNKup_Applications::transition( $app_id, 'cancelled', array( 'reason' => 'payment_expired' ) ) ) {
				++$released;
			}
		}
		return $released;
	}

	public static function check_overdue_invoices() {
		global $wpdb;
		$table = LNKup_Financial_Tables::invoices_table();
		$today = current_time( 'Y-m-d' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ('pending','sent','partially_paid') AND due_date < %s",
				$today
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$wpdb->update( $table, array( 'status' => 'overdue', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row['id'] ) );
			do_action( 'lnkup_financial_reminder', 'overdue_invoice', $row );
		}
	}

	public static function check_installment_due() {
		global $wpdb;
		$table = LNKup_Financial_Tables::installments_table();
		$today = current_time( 'Y-m-d' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'pending' AND due_date <= %s", $today ),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			do_action( 'lnkup_financial_reminder', 'installment_due', $row );
		}
	}

	public static function check_deposit_due() {
		global $wpdb;
		$table = LNKup_Financial_Tables::deposits_table();
		$today = current_time( 'Y-m-d' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ('required','partial') AND deadline <= %s", $today ),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			do_action( 'lnkup_financial_reminder', 'deposit_due', $row );
		}
	}

	public static function mark_expired_payment_methods() {
		LNKup_Payment_Methods::mark_expired_cards();
	}

	public static function check_payment_reminders() {
		global $wpdb;
		$table = LNKup_Financial_Tables::invoices_table();
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		$until = $today->modify( '+7 days' )->format( 'Y-m-d' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ('pending','sent','partially_paid','overdue') AND due_date IS NOT NULL AND due_date <= %s LIMIT 500",
				$until
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}
		foreach ( $rows as $row ) {
			$due = DateTimeImmutable::createFromFormat( 'Y-m-d', (string) ( $row['due_date'] ?? '' ), wp_timezone() );
			if ( ! $due instanceof DateTimeImmutable ) {
				continue;
			}
			$due  = $due->setTime( 0, 0 );
			$days = (int) $today->diff( $due )->format( '%r%a' );
			if ( $days < 0 ) {
				$window = 'past_due';
			} elseif ( $days <= 1 ) {
				$window = '24_hours';
			} elseif ( $days <= 3 ) {
				$window = '3_days';
			} elseif ( $days <= 7 ) {
				$window = '7_days';
			} else {
				continue;
			}
			LNKup_Notifications::send_invoice_reminder( $row, $window );
		}
	}

	/**
	 * One document reminder per application with unmet required documents.
	 */
	public static function check_document_reminders() {
		foreach ( self::reminder_application_ids() as $app_id ) {
			LNKup_Notifications::send_documents_needed( $app_id );
		}
	}

	/**
	 * One arrival reminder inside the 24 hours before load-in or event start.
	 */
	public static function check_arrival_reminders() {
		foreach ( self::reminder_application_ids() as $app_id ) {
			LNKup_Notifications::send_arrival_reminder( $app_id );
		}
	}

	/**
	 * @return int[]
	 */
	private static function reminder_application_ids() {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_lnkup_application_status',
						'value'   => array( 'approved', 'payment_required', 'paid', 'completed' ),
						'compare' => 'IN',
					),
				),
			)
		);
		return array_map( 'absint', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * @param string $type Reminder type.
	 * @param array  $row  Invoice or payment row.
	 */
	public static function send_reminder_email( $type, $row ) {
		if ( in_array( $type, array( 'overdue_invoice', 'payment_past_due' ), true ) ) {
			LNKup_Notifications::send_invoice_reminder( $row, 'past_due' );
			return;
		}
		if ( 0 === strpos( (string) $type, 'payment_reminder_' ) ) {
			LNKup_Notifications::send_invoice_reminder( $row, substr( (string) $type, strlen( 'payment_reminder_' ) ) );
			return;
		}
		if ( LNKup_Notifications::delegated( 'financial_reminder', array( $type, $row ) ) ) {
			return;
		}
		$vendor_id = (int) ( $row['vendor_user_id'] ?? 0 );
		$user      = get_userdata( $vendor_id );
		if ( ! $user ) {
			return;
		}
		$subjects = array(
			'payment_reminder_7_days'  => __( 'Payment due in 7 days', 'lnkup-marketplace' ),
			'payment_reminder_3_days'  => __( 'Payment due in 3 days', 'lnkup-marketplace' ),
			'payment_reminder_24_hours'=> __( 'Payment due tomorrow', 'lnkup-marketplace' ),
			'payment_past_due'         => __( 'Payment past due', 'lnkup-marketplace' ),
			'overdue_invoice'          => __( 'Invoice overdue', 'lnkup-marketplace' ),
		);
		$subject = $subjects[ $type ] ?? __( 'Payment reminder', 'lnkup-marketplace' );
		$amount  = isset( $row['total_amount'] ) ? lnkup_currency( $row['total_amount'] ) : '';
		$body    = sprintf(
			__( "Hello %1\$s,\n\nThis is a reminder about your LNKup payment%2\$s.\n\nPlease visit your vendor dashboard to complete payment.\n\n— LNKup", 'lnkup-marketplace' ),
			$user->display_name,
			$amount ? ' (' . $amount . ')' : ''
		);
		wp_mail( $user->user_email, $subject, $body );
		LNKup_Financial_Activity::record( 'reminder_sent', array(
			'user_id' => $vendor_id,
			'role'    => 'vendor',
			'entity_type' => 'invoice',
			'entity_id' => (int) ( $row['id'] ?? 0 ),
			'message' => $subject,
		) );
	}
}
