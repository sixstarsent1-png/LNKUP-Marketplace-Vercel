<?php
/**
 * Payment provider interface.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

interface LNKup_Payment_Provider_Interface {

	public function get_id();

	public function is_configured();

	public function create_checkout( $payment_id );

	public function refund( $payment_id, $amount );

	public function get_or_create_customer( $user_id );

	public function create_setup_intent( $user_id );

	public function list_payment_methods( $user_id );

	public function detach_payment_method( $method_id, $user_id );

	public function create_payment_intent( $payment_id, $args = array() );

	public function create_checkout_session( $payment_id, $args = array() );

	public function confirm_payment_intent( $intent_id );

	public function get_receipt_url( $payment_id );
}
