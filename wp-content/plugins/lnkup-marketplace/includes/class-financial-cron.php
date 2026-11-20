<?php
/**
 * Financial reminders and maintenance cron.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Cron {

	const HOOK = 'lnkup_financial_daily';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_daily' ) );
		add_action( 'lnkup_financial_reminder', array( __CLASS__, 'send_reminder_email' ), 10, 2 );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 7200, 'daily', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
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
