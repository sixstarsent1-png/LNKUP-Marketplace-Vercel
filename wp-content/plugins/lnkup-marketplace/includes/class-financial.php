<?php
/**
 * Financial platform orchestrator.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial {

	/**
	 * Checkout amount after credits/installments/deposits.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function get_checkout_amount( $payment_id ) {
		$total = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
		$credit = (float) get_post_meta( $payment_id, '_lnkup_credit_applied', true );
		$installment = (float) get_post_meta( $payment_id, '_lnkup_installment_amount', true );
		if ( $installment > 0 ) {
			return max( 0, $installment - $credit );
		}
		return max( 0, $total - $credit );
	}

	/**
	 * Vendor dashboard summary.
	 *
	 * @param int $user_id Vendor user ID.
	 */
	public static function vendor_dashboard( $user_id = 0 ) {
		$user_id  = $user_id ?: get_current_user_id();
		$payments = lnkup_get_payments_for_user( $user_id, 'vendor' );
		$totals   = LNKup_Vendor_Dashboard_Data::payment_totals( $payments );
		$wallet  = LNKup_Wallet::get_balance( $user_id, 'vendor' );
		$upcoming = LNKup_Installments::upcoming_for_vendor( $user_id );
		return array(
			'revenue_paid'     => (float) ( $totals['paid'] ?? 0 ),
			'outstanding'      => (float) ( $totals['outstanding'] ?? 0 ),
			'wallet_balance'   => $wallet,
			'upcoming_payments'=> $upcoming,
			'invoice_count'    => LNKup_Invoices::count_for_user( $user_id, 'vendor' ),
			'refund_count'     => LNKup_Refunds::count_for_user( $user_id, 'vendor' ),
		);
	}

	/**
	 * Organizer dashboard summary.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function organizer_dashboard( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		$collected = self::organizer_collected_revenue( $user_id );
		$pending   = self::organizer_pending_revenue( $user_id );
		$wallet    = class_exists( 'LNKup_Organizer_Wallet' ) ? LNKup_Organizer_Wallet::get_balances( $user_id ) : array();
		$payout_count    = 0;
		$available_legacy = (float) ( $wallet['available'] ?? 0 );
		$refund_requests  = 0;
		$open_disputes    = 0;
		try {
			$available_legacy = (float) ( $wallet['available'] ?? LNKup_Payouts::available_balance( $user_id ) );
			$payout_count     = LNKup_Payouts::count_for_organizer( $user_id );
		} catch ( Throwable $e ) {
			// Legacy payout tables may be unavailable; wallet balances still apply.
		}
		try {
			$refund_requests = LNKup_Refunds::pending_count_for_organizer( $user_id );
			$open_disputes   = LNKup_Financial_Disputes::open_count_for_organizer( $user_id );
		} catch ( Throwable $e ) {
			// Non-critical dashboard metrics.
		}
		return array(
			'vendor_revenue'    => $collected,
			'pending_revenue'   => $pending,
			'available_balance' => $available_legacy,
			'wallet_pending'    => (float) ( $wallet['pending'] ?? 0 ),
			'reserve_hold'      => (float) ( $wallet['reserve_hold'] ?? 0 ),
			'payout_count'      => $payout_count,
			'refund_requests'   => $refund_requests,
			'open_disputes'     => $open_disputes,
		);
	}

	/**
	 * Admin marketplace financial summary.
	 */
	public static function admin_dashboard() {
		$summary = LNKup_Financial_Reports::admin_extended_summary();
		return array_merge(
			$summary,
			array(
				'pending_refunds'  => LNKup_Refunds::pending_count_all(),
				'pending_payouts'  => LNKup_Payouts::pending_count_all(),
				'open_disputes'    => LNKup_Financial_Disputes::open_count_all(),
				'fraud_flags'      => LNKup_Fraud_Flags::open_count(),
				'alerts'           => LNKup_Financial::financial_alerts(),
			)
		);
	}

	/**
	 * Resolve booth fee amount from a payment CPT (matches activity / checkout).
	 *
	 * @param int $payment_id Payment post ID.
	 * @return float
	 */
	public static function payment_booth_amount( $payment_id ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id ) {
			return 0.0;
		}
		foreach ( array( '_lnkup_total_amount', '_lnkup_amount', '_lnkup_vendor_spot_fee', '_lnkup_total' ) as $key ) {
			$amount = (float) get_post_meta( $payment_id, $key, true );
			if ( $amount > 0 ) {
				return round( $amount, 2 );
			}
		}
		return 0.0;
	}

	/**
	 * Organizer collected vendor booth fees (paid payment CPTs are source of truth).
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function organizer_collected_revenue( $user_id ) {
		$user_id  = absint( $user_id );
		$payments = lnkup_get_payments_for_user( $user_id, 'organizer' );
		$total    = 0.0;
		foreach ( $payments as $p ) {
			$total += self::collected_amount( $p->ID );
		}
		return round( $total, 2 );
	}

	/**
	 * Money vendors actually paid on a payment, before refunds (refunds are reported separately).
	 *
	 * Counts paid, refunded, partially refunded and disputed bookings in full, and the paid part
	 * of a deposit/installment booking. Lost chargebacks count as not collected.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function collected_amount( $payment_id ) {
		$status = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( in_array( $status, array( 'paid', 'refunded', 'partially_refunded', 'disputed' ), true ) ) {
			return self::payment_booth_amount( $payment_id );
		}
		if ( 'partially_paid' === $status ) {
			return round( (int) get_post_meta( $payment_id, '_lnkup_amount_paid_cents', true ) / 100, 2 );
		}
		return 0.0;
	}

	/**
	 * Organizer pending (unpaid) vendor revenue.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function organizer_pending_revenue( $user_id ) {
		$payments = lnkup_get_payments_for_user( $user_id, 'organizer' );
		$total    = 0.0;
		foreach ( $payments as $p ) {
			$status = (string) get_post_meta( $p->ID, '_lnkup_payment_status', true );
			// Only money still due: refunded, failed, voided and disputed bookings are not pending revenue.
			if ( in_array( $status, array( 'pending', 'processing' ), true ) && '' === (string) get_post_meta( $p->ID, '_lnkup_voided_at', true ) ) {
				$total += self::payment_booth_amount( $p->ID );
			} elseif ( 'partially_paid' === $status ) {
				$total += max( 0, self::payment_booth_amount( $p->ID ) - self::collected_amount( $p->ID ) );
			}
		}
		return round( $total, 2 );
	}

	/**
	 * Collected revenue for a single event (paid payment CPTs are source of truth).
	 *
	 * @param int $event_id Event post ID.
	 */
	public static function event_collected_revenue( $event_id ) {
		$event_id = absint( $event_id );
		$payments = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array( 'key' => '_lnkup_event_id', 'value' => $event_id ),
					array( 'key' => '_lnkup_payment_status', 'value' => array( 'paid', 'refunded', 'partially_refunded', 'disputed', 'partially_paid' ), 'compare' => 'IN' ),
				),
				'fields'         => 'ids',
			)
		);
		$total = 0.0;
		foreach ( $payments as $pid ) {
			$total += self::collected_amount( (int) $pid );
		}
		return round( $total, 2 );
	}

	/**
	 * Financial alerts for admin.
	 */
	public static function financial_alerts() {
		$alerts = array();
		if ( LNKup_Refunds::pending_count_all() > 0 ) {
			$alerts[] = array( 'type' => 'refunds', 'message' => __( 'Pending refund requests need review.', 'lnkup-marketplace' ) );
		}
		if ( LNKup_Fraud_Flags::open_count() > 0 ) {
			$alerts[] = array( 'type' => 'fraud', 'message' => __( 'Fraud flags require admin review.', 'lnkup-marketplace' ) );
		}
		return $alerts;
	}

	/**
	 * Event financial snapshot.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function event_financials( $event_id ) {
		return LNKup_Financial_Reports::for_event( $event_id );
	}
}
