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
		if ( get_transient( 'lnkup_fin_daily_' . gmdate( 'Y-m-d' ) ) ) {
			return;
		}
		set_transient( 'lnkup_fin_daily_' . gmdate( 'Y-m-d' ), 1, DAY_IN_SECONDS );
		self::check_overdue_invoices();
		self::check_payment_reminders();
		self::check_installment_due();
		self::check_deposit_due();
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

	public static function check_overdue_invoices() {
		global $wpdb;
		$table = LNKup_Financial_Tables::invoices_table();
		$today = gmdate( 'Y-m-d' );
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
		$today = gmdate( 'Y-m-d' );
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
		$today = gmdate( 'Y-m-d' );
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
		$today = gmdate( 'Y-m-d' );
		$windows = array(
			'7_days'  => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
			'3_days'  => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
			'24_hours'=> gmdate( 'Y-m-d', strtotime( '+1 day' ) ),
		);
		foreach ( $windows as $type => $due_match ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status IN ('pending','sent','partially_paid') AND due_date = %s",
					$due_match
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				do_action( 'lnkup_financial_reminder', 'payment_reminder_' . $type, $row );
			}
		}
		$past_due = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ('pending','sent','partially_paid','overdue') AND due_date < %s",
				$today
			),
			ARRAY_A
		);
		foreach ( $past_due as $row ) {
			do_action( 'lnkup_financial_reminder', 'payment_past_due', $row );
		}
	}

	/**
	 * @param string $type Reminder type.
	 * @param array  $row  Invoice or payment row.
	 */
	public static function send_reminder_email( $type, $row ) {
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
