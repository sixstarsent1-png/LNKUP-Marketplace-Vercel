<?php
/**
 * Payment provider abstraction.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

abstract class LNKup_Payment_Provider implements LNKup_Payment_Provider_Interface {

	abstract public function get_id();

	abstract public function is_configured();

	abstract public function create_checkout( $payment_id );

	abstract public function refund( $payment_id, $amount );

	public function create_checkout_session( $payment_id, $args = array() ) {
		unset( $args );
		return $this->create_checkout( $payment_id );
	}

	public function create_payment_intent( $payment_id, $args = array() ) {
		unset( $args );
		return $this->create_checkout( $payment_id );
	}

	public function get_or_create_customer( $user_id ) {
		unset( $user_id );
		return new WP_Error( 'unsupported', __( 'Customer management not supported.', 'lnkup-marketplace' ) );
	}

	public function create_setup_intent( $user_id ) {
		unset( $user_id );
		return new WP_Error( 'unsupported', __( 'Setup intent not supported.', 'lnkup-marketplace' ) );
	}

	public function list_payment_methods( $user_id ) {
		return LNKup_Payment_Methods::list_for_user( $user_id );
	}

	public function detach_payment_method( $method_id, $user_id ) {
		unset( $method_id, $user_id );
		return new WP_Error( 'unsupported', __( 'Detach not supported.', 'lnkup-marketplace' ) );
	}

	public function confirm_payment_intent( $intent_id ) {
		unset( $intent_id );
		return new WP_Error( 'unsupported', __( 'Confirm not supported.', 'lnkup-marketplace' ) );
	}

	public function get_receipt_url( $payment_id ) {
		unset( $payment_id );
		return '';
	}

	/**
	 * Get active provider instance.
	 */
	public static function get_instance() {
		$settings = lnkup_get_settings();
		$driver   = isset( $settings['payment_provider'] ) ? $settings['payment_provider'] : 'stripe';
		if ( 'manual' === $driver ) {
			return new LNKup_Manual_Provider();
		}
		if ( empty( $settings['stripe_secret_key'] ) ) {
			return new LNKup_Manual_Provider();
		}
		return new LNKup_Stripe_Provider();
	}

	/**
	 * Active provider ID string.
	 */
	public static function get_active_provider_id() {
		return self::get_instance()->get_id();
	}
}
