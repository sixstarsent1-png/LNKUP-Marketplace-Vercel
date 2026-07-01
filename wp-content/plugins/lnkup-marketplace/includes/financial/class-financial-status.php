<?php
/**
 * Smart financial status mapping.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Status {

	const STATUSES = array(
		'awaiting_invoice',
		'invoice_sent',
		'awaiting_deposit',
		'deposit_paid',
		'balance_due',
		'payment_due_soon',
		'paid',
		'refund_pending',
		'refunded',
		'chargeback',
		'waived',
		'cancelled',
		'pending',
		'failed',
	);

	/**
	 * Resolve smart status for a payment post.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function for_payment( $payment_id ) {
		$payment = get_post( $payment_id );
		if ( ! $payment || 'lnkup_payment' !== $payment->post_type ) {
			return 'pending';
		}

		$raw = get_post_meta( $payment_id, '_lnkup_payment_status', true ) ?: 'pending';
		if ( in_array( $raw, array( 'refunded', 'cancelled', 'waived', 'failed' ), true ) ) {
			return $raw;
		}
		if ( 'paid' === $raw ) {
			return 'paid';
		}

		$app_id = (int) get_post_meta( $payment_id, '_lnkup_application_id', true );
		$invoice = $app_id ? LNKup_Invoices::get_by_application( $app_id ) : null;

		if ( ! $invoice ) {
			return 'awaiting_invoice';
		}
		if ( in_array( $invoice['status'], array( 'sent', 'pending' ), true ) ) {
			$due = $invoice['due_date'] ?? '';
			if ( $due ) {
				$days = (int) floor( ( strtotime( $due ) - time() ) / DAY_IN_SECONDS );
				if ( $days < 0 ) {
					return 'balance_due';
				}
				if ( $days <= 3 ) {
					return 'payment_due_soon';
				}
			}
			return 'invoice_sent';
		}
		if ( 'overdue' === $invoice['status'] ) {
			return 'balance_due';
		}
		if ( 'partially_paid' === $invoice['status'] ) {
			$deposit = $app_id ? LNKup_Deposits::get_for_application( $app_id ) : null;
			if ( $deposit && in_array( $deposit['status'], array( 'paid', 'partial' ), true ) ) {
				return 'deposit_paid';
			}
			return 'balance_due';
		}

		$deposit = $app_id ? LNKup_Deposits::get_for_application( $app_id ) : null;
		if ( $deposit && 'required' === $deposit['status'] ) {
			return 'awaiting_deposit';
		}

		$refund_pending = self::has_pending_refund( $payment_id );
		if ( $refund_pending ) {
			return 'refund_pending';
		}

		$dispute = self::has_chargeback( $payment_id );
		if ( $dispute ) {
			return 'chargeback';
		}

		return 'pending';
	}

	/**
	 * @param int $payment_id Payment ID.
	 */
	private static function has_pending_refund( $payment_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::refunds_table();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE payment_post_id = %d AND status = 'pending'",
				$payment_id
			)
		);
		return $count > 0;
	}

	/**
	 * @param int $payment_id Payment ID.
	 */
	private static function has_chargeback( $payment_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE payment_post_id = %d AND status IN ('open','under_review')",
				$payment_id
			)
		);
		return $count > 0;
	}

	/**
	 * Human label for status.
	 *
	 * @param string $status Status slug.
	 */
	public static function label( $status ) {
		$labels = array(
			'awaiting_invoice'  => __( 'Awaiting Invoice', 'lnkup-marketplace' ),
			'invoice_sent'      => __( 'Invoice Sent', 'lnkup-marketplace' ),
			'awaiting_deposit'  => __( 'Awaiting Deposit', 'lnkup-marketplace' ),
			'deposit_paid'      => __( 'Deposit Paid', 'lnkup-marketplace' ),
			'balance_due'       => __( 'Balance Due', 'lnkup-marketplace' ),
			'payment_due_soon'  => __( 'Payment Due Soon', 'lnkup-marketplace' ),
			'paid'              => __( 'Paid', 'lnkup-marketplace' ),
			'refund_pending'    => __( 'Refund Pending', 'lnkup-marketplace' ),
			'refunded'          => __( 'Refunded', 'lnkup-marketplace' ),
			'chargeback'        => __( 'Chargeback', 'lnkup-marketplace' ),
			'waived'            => __( 'Waived', 'lnkup-marketplace' ),
			'cancelled'         => __( 'Cancelled', 'lnkup-marketplace' ),
			'pending'           => __( 'Pending', 'lnkup-marketplace' ),
			'failed'            => __( 'Failed', 'lnkup-marketplace' ),
		);
		return $labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
	}
}
