<?php
/**
 * Stripe gateway — delegates to LNKup_Financial_Service.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Stripe {

	public static function init() {
		add_action( 'wp_ajax_lnkup_create_checkout', array( __CLASS__, 'ajax_create_checkout' ) );
		add_action( 'wp_ajax_lnkup_simulate_payment', array( __CLASS__, 'ajax_simulate_payment' ) );
	}

	public static function is_configured() {
		return LNKup_Payment_Provider::get_instance()->is_configured();
	}

	public static function create_checkout( $payment_id, $args = array() ) {
		return LNKup_Financial_Service::create_checkout( $payment_id, $args );
	}

	public static function ajax_create_checkout() {
		$payment_id = isset( $_POST['payment_id'] ) ? absint( $_POST['payment_id'] ) : 0;
		if ( isset( $_POST['lnkup_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lnkup_nonce'] ) ), 'lnkup_action' ) ) {
			// ok
		} elseif ( isset( $_POST['lnkup_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lnkup_nonce'] ) ), 'lnkup_checkout' ) ) {
			// ok
		} else {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'lnkup-marketplace' ) ) );
		}
		$vendor_id = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		if ( ! LNKup_Capabilities::user_has_manage_all() && get_current_user_id() !== $vendor_id ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lnkup-marketplace' ) ) );
		}
		$is_pending = 'pending' === (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( ! $is_pending ) {
			wp_send_json_error( array( 'message' => __( 'This booking is already paid or no longer payable.', 'lnkup-marketplace' ) ) );
		}
		if ( ! empty( $_POST['promo_code'] ) && ! get_post_meta( $payment_id, '_lnkup_promo_code', true ) ) {
			$promo = LNKup_Promo_Codes::validate( sanitize_text_field( wp_unslash( $_POST['promo_code'] ) ), get_current_user_id() );
			if ( ! is_wp_error( $promo ) ) {
				$subtotal = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
				$discount = LNKup_Promo_Codes::calculate_discount( $promo, $subtotal );
				$redeemed = LNKup_Promo_Codes::redeem( $promo['code'], get_current_user_id(), $payment_id, $discount );
				if ( true === $redeemed ) {
					update_post_meta( $payment_id, '_lnkup_discount_amount', $discount );
				}
			}
		}
		if ( ! empty( $_POST['use_credits'] ) ) {
			LNKup_Financial_Service::apply_wallet_credits( $payment_id );
		}
		$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'embedded';
		$result = LNKup_Financial_Service::create_checkout( $payment_id, array( 'mode' => $mode ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public static function ajax_simulate_payment() {
		$payment_id = isset( $_GET['payment_id'] ) ? absint( $_GET['payment_id'] ) : 0;
		LNKup_Security::verify_nonce( 'lnkup_simulate_' . $payment_id );
		if ( ! LNKup_Financial_Service::simulation_enabled() ) {
			wp_die( esc_html__( 'Payment simulation is disabled.', 'lnkup-marketplace' ), 403 );
		}
		if ( 'lnkup_payment' !== get_post_type( $payment_id ) ) {
			wp_die( esc_html__( 'Invalid payment.', 'lnkup-marketplace' ), 404 );
		}
		$vendor_id = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		if ( ! LNKup_Capabilities::user_has_manage_all() && get_current_user_id() !== $vendor_id ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}
		LNKup_Financial_Service::finalize_payment( $payment_id, 'sim_' . wp_generate_password( 12, false ) );
		wp_safe_redirect( add_query_arg( 'lnkup_payment', 'success', lnkup_page_url_helper( 'vendor-dashboard' ) ) );
		exit;
	}
}
