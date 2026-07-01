<?php
/**
 * Payment lifecycle timeline.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Payment_Timeline {

	const STEPS = array(
		'application_approved',
		'invoice_created',
		'reminder_sent',
		'payment_received',
		'packet_unlocked',
		'checked_in',
		'completed',
		'review_submitted',
	);

	public static function init() {
		add_action( 'lnkup_application_approved', array( __CLASS__, 'on_application_approved' ), 10, 1 );
		add_action( 'lnkup_invoice_created', array( __CLASS__, 'on_invoice_created' ), 10, 2 );
		add_action( 'lnkup_financial_reminder', array( __CLASS__, 'on_reminder' ), 10, 2 );
		add_action( 'lnkup_payment_completed', array( __CLASS__, 'on_payment_received' ), 10, 1 );
		add_action( 'lnkup_packet_unlocked', array( __CLASS__, 'on_packet_unlocked' ), 10, 2 );
		add_action( 'lnkup_vendor_checked_in', array( __CLASS__, 'on_checked_in' ), 10, 2 );
		add_action( 'lnkup_event_completed', array( __CLASS__, 'on_completed' ), 10, 2 );
		add_action( 'lnkup_review_submitted', array( __CLASS__, 'on_review_submitted' ), 10, 2 );
	}

	/**
	 * Record a timeline step.
	 *
	 * @param int    $payment_id Payment post ID.
	 * @param string $step       Step slug.
	 * @param array  $args       Optional notes, actor_id, status.
	 */
	public static function record( $payment_id, $step, $args = array() ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::payment_timeline_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'payment_post_id' => absint( $payment_id ),
				'step'            => sanitize_key( $step ),
				'status'          => sanitize_key( $args['status'] ?? 'completed' ),
				'actor_user_id'   => absint( $args['actor_id'] ?? get_current_user_id() ),
				'notes'           => sanitize_textarea_field( $args['notes'] ?? '' ),
				'created_at'      => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * @param int $payment_id Payment ID.
	 */
	public static function list_for_payment( $payment_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::payment_timeline_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE payment_post_id = %d ORDER BY created_at ASC, id ASC",
				absint( $payment_id )
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format' ), $rows );
	}

	/**
	 * @param array $row DB row.
	 */
	public static function format( $row ) {
		return array(
			'id'        => (int) $row['id'],
			'step'      => $row['step'],
			'label'     => self::step_label( $row['step'] ),
			'status'    => $row['status'],
			'actor_id'  => (int) $row['actor_user_id'],
			'notes'     => $row['notes'],
			'created_at'=> $row['created_at'],
		);
	}

	/**
	 * @param string $step Step slug.
	 */
	public static function step_label( $step ) {
		$labels = array(
			'application_approved' => __( 'Application Approved', 'lnkup-marketplace' ),
			'invoice_created'      => __( 'Invoice Created', 'lnkup-marketplace' ),
			'reminder_sent'        => __( 'Reminder Sent', 'lnkup-marketplace' ),
			'payment_received'     => __( 'Payment Received', 'lnkup-marketplace' ),
			'packet_unlocked'      => __( 'Packet Unlocked', 'lnkup-marketplace' ),
			'checked_in'           => __( 'Checked In', 'lnkup-marketplace' ),
			'completed'            => __( 'Completed', 'lnkup-marketplace' ),
			'review_submitted'     => __( 'Review Submitted', 'lnkup-marketplace' ),
		);
		return $labels[ $step ] ?? ucwords( str_replace( '_', ' ', $step ) );
	}

	public static function on_application_approved( $app_id ) {
		$payment = LNKup_Payments::get_for_application( $app_id );
		if ( $payment ) {
			self::record( $payment->ID, 'application_approved' );
		}
	}

	public static function on_invoice_created( $payment_id, $invoice_id ) {
		self::record( $payment_id, 'invoice_created', array( 'notes' => 'Invoice #' . $invoice_id ) );
	}

	public static function on_reminder( $type, $row ) {
		$payment_id = self::resolve_payment_from_reminder( $type, $row );
		if ( $payment_id ) {
			self::record( $payment_id, 'reminder_sent', array( 'notes' => $type ) );
		}
	}

	public static function on_payment_received( $payment_id ) {
		self::record( $payment_id, 'payment_received' );
	}

	public static function on_packet_unlocked( $app_id, $payment_id ) {
		self::record( $payment_id, 'packet_unlocked' );
	}

	public static function on_checked_in( $app_id, $payment_id ) {
		if ( ! $payment_id ) {
			$payment = LNKup_Payments::get_for_application( $app_id );
			$payment_id = $payment ? $payment->ID : 0;
		}
		if ( $payment_id ) {
			self::record( $payment_id, 'checked_in' );
		}
	}

	public static function on_completed( $event_id, $app_id ) {
		$payment = LNKup_Payments::get_for_application( $app_id );
		if ( $payment ) {
			self::record( $payment->ID, 'completed' );
		}
	}

	public static function on_review_submitted( $app_id, $payment_id ) {
		if ( ! $payment_id ) {
			$payment = LNKup_Payments::get_for_application( $app_id );
			$payment_id = $payment ? $payment->ID : 0;
		}
		if ( $payment_id ) {
			self::record( $payment_id, 'review_submitted' );
		}
	}

	/**
	 * @param string $type Reminder type.
	 * @param array  $row  Row data.
	 */
	private static function resolve_payment_from_reminder( $type, $row ) {
		if ( ! empty( $row['payment_post_id'] ) ) {
			return (int) $row['payment_post_id'];
		}
		if ( ! empty( $row['application_id'] ) ) {
			$payment = LNKup_Payments::get_for_application( (int) $row['application_id'] );
			return $payment ? $payment->ID : 0;
		}
		return 0;
	}
}
