<?php
/**
 * Manual/test payment provider driver.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Manual_Provider extends LNKup_Payment_Provider {

	public function get_id() {
		return 'manual';
	}

	public function is_configured() {
		return true;
	}

	public function create_checkout( $payment_id ) {
		return $this->create_checkout_session( $payment_id );
	}

	/**
	 * Safe error when no real payment processor is available and simulation is off.
	 */
	public static function unavailable_error() {
		return new WP_Error(
			'payments_unavailable',
			__( 'Online payments are temporarily unavailable. Please try again later or contact support.', 'lnkup-marketplace' ),
			array( 'status' => 503 )
		);
	}

	public function create_checkout_session( $payment_id, $args = array() ) {
		if ( ! LNKup_Financial_Service::simulation_enabled() ) {
			return self::unavailable_error();
		}
		$payment = get_post( $payment_id );
		if ( ! $payment || 'lnkup_payment' !== $payment->post_type ) {
			return new WP_Error( 'invalid', __( 'Invalid payment.', 'lnkup-marketplace' ) );
		}
		$vendor_id = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		if ( get_current_user_id() !== $vendor_id && ! LNKup_Capabilities::user_has_manage_all() ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		return array(
			'mode' => 'simulation',
			'url'  => add_query_arg(
				array(
					'action'      => 'lnkup_simulate_payment',
					'payment_id'  => $payment_id,
					'lnkup_nonce' => wp_create_nonce( 'lnkup_simulate_' . $payment_id ),
				),
				admin_url( 'admin-ajax.php' )
			),
		);
	}

	public function create_payment_intent( $payment_id, $args = array() ) {
		unset( $args );
		if ( ! LNKup_Financial_Service::simulation_enabled() ) {
			return self::unavailable_error();
		}
		$check = $this->validate_payment_access( $payment_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$intent_id = 'sim_pi_' . wp_generate_password( 16, false );
		update_post_meta( $payment_id, '_lnkup_stripe_payment_intent', $intent_id );
		return array(
			'mode'          => 'simulation',
			'client_secret' => $intent_id . '_secret',
			'intent_id'     => $intent_id,
			'simulate_url'  => $this->create_checkout_session( $payment_id )['url'] ?? '',
		);
	}

	public function refund( $payment_id, $amount ) {
		unset( $payment_id, $amount );
		return array( 'provider_ref' => 'manual_refund_' . wp_generate_password( 8, false ) );
	}

	public function get_or_create_customer( $user_id ) {
		$cid = get_user_meta( $user_id, '_lnkup_stripe_customer_id', true );
		if ( ! $cid ) {
			$cid = 'sim_cus_' . wp_generate_password( 12, false );
			update_user_meta( $user_id, '_lnkup_stripe_customer_id', $cid );
		}
		return array( 'id' => $cid );
	}

	public function create_setup_intent( $user_id ) {
		unset( $user_id );
		return array(
			'client_secret' => 'sim_seti_' . wp_generate_password( 16, false ) . '_secret',
			'mode'          => 'simulation',
		);
	}

	public function list_payment_methods( $user_id ) {
		return LNKup_Payment_Methods::list_for_user( $user_id );
	}

	public function detach_payment_method( $method_id, $user_id ) {
		global $wpdb;
		$wpdb->delete(
			LNKup_Financial_Tables::payment_methods_table(),
			array( 'id' => absint( $method_id ), 'user_id' => absint( $user_id ) ),
			array( '%d', '%d' )
		);
		return true;
	}

	public function confirm_payment_intent( $intent_id ) {
		if ( ! LNKup_Financial_Service::simulation_enabled() ) {
			return self::unavailable_error();
		}
		return array( 'id' => $intent_id, 'status' => 'succeeded' );
	}

	public function get_receipt_url( $payment_id ) {
		$receipt = LNKup_Receipts::get_by_payment( $payment_id );
		if ( $receipt ) {
			return rest_url( 'lnkup/v1/financial/receipts/' . (int) $receipt['id'] );
		}
		return '';
	}

	/**
	 * @param int $payment_id Payment ID.
	 */
	private function validate_payment_access( $payment_id ) {
		$payment = get_post( $payment_id );
		if ( ! $payment || 'lnkup_payment' !== $payment->post_type ) {
			return new WP_Error( 'invalid', __( 'Invalid payment.', 'lnkup-marketplace' ) );
		}
		$vendor_id = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		if ( get_current_user_id() !== $vendor_id && ! LNKup_Capabilities::user_has_manage_all() ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		return true;
	}
}
