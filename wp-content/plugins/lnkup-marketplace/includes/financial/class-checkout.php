<?php
/**
 * Embedded checkout orchestration.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Checkout {

	/**
	 * Build checkout summary for wizard step 1.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function get_summary( $payment_id ) {
		$payment = get_post( $payment_id );
		if ( ! $payment || 'lnkup_payment' !== $payment->post_type ) {
			return new WP_Error( 'invalid', __( 'Invalid payment.', 'lnkup-marketplace' ) );
		}

		LNKup_Payments::refresh_pending_fees( $payment_id );

		$intent_id = (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
		if ( $intent_id && 'pending' === get_post_meta( $payment_id, '_lnkup_payment_status', true ) ) {
			LNKup_Financial_Service::confirm_checkout( $payment_id, $intent_id );
		}

		$event_id   = (int) get_post_meta( $payment_id, '_lnkup_event_id', true );
		$opp_id     = (int) get_post_meta( $payment_id, '_lnkup_opportunity_id', true );
		$org_id     = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$vendor_id  = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		$app_id     = (int) get_post_meta( $payment_id, '_lnkup_application_id', true );
		$event      = $event_id ? get_post( $event_id ) : null;
		$organizer  = get_userdata( $org_id );
		$invoice    = $app_id ? LNKup_Invoices::get_by_application( $app_id ) : null;

		$vendor_fee    = (float) get_post_meta( $payment_id, '_lnkup_vendor_spot_fee', true );
		$checkout_fee  = (float) get_post_meta( $payment_id, '_lnkup_vendor_checkout_fee', true );
		$booth_total   = round( $vendor_fee + $checkout_fee, 2 );
		$processing    = (float) get_post_meta( $payment_id, '_lnkup_processing_fee', true );
		$tax_amount    = (float) get_post_meta( $payment_id, '_lnkup_tax_amount', true );
		$discount      = (float) get_post_meta( $payment_id, '_lnkup_discount_amount', true );
		$credit_avail  = LNKup_Wallet::get_balance( $vendor_id, 'vendor' );
		$credit_applied = (float) get_post_meta( $payment_id, '_lnkup_credit_applied', true );
		$total         = LNKup_Financial_Service::get_checkout_amount( $payment_id );
		$image_id      = $event_id ? (int) get_post_thumbnail_id( $event_id ) : 0;
		$image_url     = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';

		return array(
			'payment_id'       => $payment_id,
			'status'           => LNKup_Financial_Status::for_payment( $payment_id ),
			'status_label'     => LNKup_Financial_Status::label( LNKup_Financial_Status::for_payment( $payment_id ) ),
			'event'            => array(
				'id'    => $event_id,
				'title' => $event ? $event->post_title : $payment->post_title,
				'image' => $image_url ?: '',
			),
			'organizer'        => array(
				'id'   => $org_id,
				'name' => $organizer ? $organizer->display_name : '',
			),
			'opportunity_id'   => $opp_id,
			'invoice_number'   => $invoice['invoice_number'] ?? '',
			'breakdown'        => array(
				'vendor_fee'              => $vendor_fee,
				'vendor_checkout_fee'     => $checkout_fee,
				'booth_reservation_total' => $booth_total,
				'processing_fee'          => $processing,
				'tax'                     => $tax_amount,
				'discount'                => $discount,
				'credit_applied'          => $credit_applied,
				'credit_available'        => $credit_avail,
				'total'                   => $total,
			),
			'unlock_estimate'  => self::unlock_estimate( $event_id ),
		);
	}

	/**
	 * Prepare embedded checkout (Payment Intent).
	 *
	 * @param int   $payment_id Payment ID.
	 * @param array $args       Optional payment_method_id.
	 */
	public static function prepare( $payment_id, $args = array() ) {
		$pay_status = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( 'paid' === $pay_status ) {
			return array(
				'mode'         => 'no_payment_due',
				'paid'         => true,
				'already_paid' => true,
				'payment_id'   => (int) $payment_id,
			);
		}
		if ( 'pending' !== $pay_status ) {
			return new WP_Error( 'payment_not_payable', __( 'This booking is no longer open for payment.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		LNKup_Payments::refresh_pending_fees( $payment_id );
		$zero = LNKup_Financial_Service::maybe_finalize_zero_amount( $payment_id, 'free_booth' );
		if ( is_wp_error( $zero ) ) {
			return $zero;
		}
		if ( null !== $zero ) {
			return array(
				'mode'       => 'no_payment_due',
				'paid'       => true,
				'payment_id' => (int) $payment_id,
			);
		}
		$provider = LNKup_Payment_Provider::get_instance();
		return $provider->create_payment_intent( $payment_id, $args );
	}

	/**
	 * Apply wallet credits before checkout.
	 *
	 * @param int   $payment_id Payment ID.
	 * @param float $amount     Amount to apply (0 = max available).
	 */
	public static function apply_credits( $payment_id, $amount = 0 ) {
		$vendor_id = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		$actor_id  = LNKup_Capabilities::user_has_manage_all() ? $vendor_id : get_current_user_id();
		$total     = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
		$result    = LNKup_Credits::apply_to_payment( $payment_id, $actor_id, $amount > 0 ? $amount : $total );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::get_summary( $payment_id );
	}

	/**
	 * Estimated packet unlock time.
	 *
	 * @param int $event_id Event ID.
	 */
	private static function unlock_estimate( $event_id ) {
		if ( ! $event_id ) {
			return __( 'Immediately after payment', 'lnkup-marketplace' );
		}
		$start = get_post_meta( $event_id, '_lnkup_event_start', true );
		if ( $start ) {
			return sprintf(
				/* translators: %s: date */
				__( 'Packet unlocks immediately. Event starts %s', 'lnkup-marketplace' ),
				date_i18n( 'M j, Y', strtotime( $start ) )
			);
		}
		return __( 'Immediately after payment', 'lnkup-marketplace' );
	}
}
