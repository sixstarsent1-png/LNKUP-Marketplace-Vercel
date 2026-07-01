<?php
/**
 * Stripe payment provider driver.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Stripe_Provider extends LNKup_Payment_Provider {

	/**
	 * @var LNKup_Stripe_Client
	 */
	private $client;

	public function __construct() {
		$this->client = new LNKup_Stripe_Client();
	}

	public function get_id() {
		return 'stripe';
	}

	public function is_configured() {
		return $this->client->is_available() && ! empty( lnkup_get_settings()['stripe_public_key'] );
	}

	public function create_checkout( $payment_id ) {
		return $this->create_checkout_session( $payment_id );
	}

	public function create_checkout_session( $payment_id ) {
		$check = $this->validate_payment_access( $payment_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! $this->is_configured() ) {
			return ( new LNKup_Manual_Provider() )->create_checkout_session( $payment_id );
		}

		$amount_cents = $this->amount_cents( $payment_id );
		$meta         = $this->payment_metadata( $payment_id );
		$customer     = $this->get_or_create_customer( (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ) );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$organizer_id   = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$breakdown      = LNKup_Fee_Engine::calculate_for_payment( $payment_id );
		$pi_data        = array( 'metadata' => $meta );
		$connect_params = $this->connect_charge_params( $organizer_id, $breakdown );
		if ( $connect_params ) {
			$pi_data = array_merge( $pi_data, $connect_params );
		}
		if ( class_exists( 'LNKup_Stripe_Connect' ) ) {
			LNKup_Stripe_Connect::persist_payment_settlement_meta( $payment_id, $organizer_id, $breakdown );
		}

		$session = $this->client->create_checkout_session( array(
			'mode'                => 'payment',
			'customer'            => $customer['id'],
			'success_url'         => $this->success_url( $payment_id ),
			'cancel_url'          => $this->cancel_url(),
			'client_reference_id' => (string) $payment_id,
			'payment_intent_data' => $pi_data,
			'line_items'          => array(
				array(
					'price_data' => array(
						'currency'     => 'usd',
						'product_data' => array( 'name' => get_the_title( $payment_id ) ),
						'unit_amount'  => $amount_cents,
					),
					'quantity'   => 1,
				),
			),
		) );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( ! empty( $session['payment_intent'] ) ) {
			update_post_meta( $payment_id, '_lnkup_stripe_payment_intent', sanitize_text_field( $session['payment_intent'] ) );
		}
		if ( ! empty( $session['id'] ) ) {
			update_post_meta( $payment_id, '_lnkup_stripe_checkout_session', sanitize_text_field( $session['id'] ) );
		}

		return array( 'mode' => 'stripe', 'url' => $session['url'] ?? '' );
	}

	public function create_payment_intent( $payment_id, $args = array() ) {
		$check = $this->validate_payment_access( $payment_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! $this->is_configured() ) {
			return ( new LNKup_Manual_Provider() )->create_payment_intent( $payment_id, $args );
		}

		$vendor_id = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		$customer  = $this->get_or_create_customer( $vendor_id );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$amount_cents = $this->amount_cents( $payment_id );
		$meta         = $this->payment_metadata( $payment_id );
		$idempotency  = 'lnkup_pi_' . $payment_id . '_' . $amount_cents;

		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$breakdown    = LNKup_Fee_Engine::calculate_for_payment( $payment_id );
		$params       = array(
			'amount'                    => $amount_cents,
			'currency'                  => 'usd',
			'customer'                  => $customer['id'],
			'automatic_payment_methods' => array( 'enabled' => true ),
			'metadata'                  => $meta,
		);

		if ( ! empty( $args['payment_method_id'] ) ) {
			$params['payment_method']      = sanitize_text_field( $args['payment_method_id'] );
			$params['confirm']             = false;
			unset( $params['automatic_payment_methods'] );
		}

		$connect = $this->connect_charge_params( $organizer_id, $breakdown );
		if ( $connect ) {
			$params = array_merge( $params, $connect );
		}
		if ( class_exists( 'LNKup_Stripe_Connect' ) ) {
			LNKup_Stripe_Connect::persist_payment_settlement_meta( $payment_id, $organizer_id, $breakdown );
		}

		$intent = $this->client->create_payment_intent( $params, $idempotency );
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		update_post_meta( $payment_id, '_lnkup_stripe_payment_intent', sanitize_text_field( $intent['id'] ?? '' ) );

		return array(
			'mode'             => 'embedded',
			'client_secret'    => $intent['client_secret'] ?? '',
			'intent_id'        => $intent['id'] ?? '',
			'publishable_key'  => $this->client->get_publishable_key(),
		);
	}

	public function confirm_payment_intent( $intent_id ) {
		if ( ! $this->is_configured() ) {
			return ( new LNKup_Manual_Provider() )->confirm_payment_intent( $intent_id );
		}
		return $this->client->retrieve_payment_intent( $intent_id );
	}

	public function refund( $payment_id, $amount ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Stripe is not configured.', 'lnkup-marketplace' ) );
		}
		$intent = get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
		if ( ! $intent || 0 === strpos( (string) $intent, 'sim_' ) ) {
			return ( new LNKup_Manual_Provider() )->refund( $payment_id, $amount );
		}
		$result = $this->client->create_refund( array(
			'payment_intent' => $intent,
			'amount'         => (int) round( $amount * 100 ),
		) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'provider_ref' => $result['id'] ?? '' );
	}

	public function get_or_create_customer( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user.', 'lnkup-marketplace' ) );
		}
		if ( ! $this->is_configured() ) {
			return ( new LNKup_Manual_Provider() )->get_or_create_customer( $user_id );
		}

		$existing = get_user_meta( $user_id, '_lnkup_stripe_customer_id', true );
		if ( $existing ) {
			return array( 'id' => $existing );
		}

		$user = get_userdata( $user_id );
		$customer = $this->client->create_customer( array(
			'email'    => $user->user_email,
			'name'     => $user->display_name,
			'metadata' => array(
				'wp_user_id' => (string) $user_id,
				'platform'   => 'lnkup',
			),
		) );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		update_user_meta( $user_id, '_lnkup_stripe_customer_id', sanitize_text_field( $customer['id'] ?? '' ) );
		return array( 'id' => $customer['id'] ?? '' );
	}

	public function create_setup_intent( $user_id ) {
		if ( ! $this->is_configured() ) {
			return ( new LNKup_Manual_Provider() )->create_setup_intent( $user_id );
		}
		$customer = $this->get_or_create_customer( $user_id );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		$intent = $this->client->create_setup_intent( array(
			'customer'             => $customer['id'],
			'automatic_payment_methods' => array( 'enabled' => true ),
			'metadata'             => array( 'wp_user_id' => (string) $user_id ),
		) );
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}
		return array(
			'client_secret'   => $intent['client_secret'] ?? '',
			'publishable_key' => $this->client->get_publishable_key(),
		);
	}

	public function list_payment_methods( $user_id ) {
		if ( ! $this->is_configured() ) {
			return ( new LNKup_Manual_Provider() )->list_payment_methods( $user_id );
		}
		$customer = get_user_meta( $user_id, '_lnkup_stripe_customer_id', true );
		if ( ! $customer ) {
			return array();
		}
		$result = $this->client->list_payment_methods( $customer );
		if ( is_wp_error( $result ) ) {
			return array();
		}
		$methods = array();
		foreach ( ( $result['data'] ?? array() ) as $pm ) {
			$card = $pm['card'] ?? array();
			$methods[] = array(
				'provider'           => 'stripe',
				'provider_method_id' => $pm['id'] ?? '',
				'method_type'        => $pm['type'] ?? 'card',
				'brand'              => $card['brand'] ?? '',
				'last4'              => $card['last4'] ?? '',
				'exp_month'          => (int) ( $card['exp_month'] ?? 0 ),
				'exp_year'           => (int) ( $card['exp_year'] ?? 0 ),
			);
		}
		return $methods;
	}

	public function detach_payment_method( $method_id, $user_id ) {
		$row = $this->get_local_method( $method_id, $user_id );
		$stripe_id = $row['provider_method_id'] ?? $method_id;
		if ( $this->is_configured() && $stripe_id && 0 !== strpos( $stripe_id, 'sim_' ) ) {
			$this->client->detach_payment_method( $stripe_id );
		}
		return ( new LNKup_Manual_Provider() )->detach_payment_method( $method_id, $user_id );
	}

	public function get_receipt_url( $payment_id ) {
		$intent = get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
		if ( $intent && $this->is_configured() && 0 !== strpos( (string) $intent, 'sim_' ) ) {
			$pi = $this->client->retrieve_payment_intent( $intent );
			if ( ! is_wp_error( $pi ) && ! empty( $pi['charges']['data'][0]['receipt_url'] ) ) {
				return $pi['charges']['data'][0]['receipt_url'];
			}
		}
		return ( new LNKup_Manual_Provider() )->get_receipt_url( $payment_id );
	}

	/**
	 * Sync Stripe payment methods to local table.
	 *
	 * @param int $user_id User ID.
	 */
	public function sync_payment_methods( $user_id ) {
		$remote = $this->list_payment_methods( $user_id );
		$customer = get_user_meta( $user_id, '_lnkup_stripe_customer_id', true );
		foreach ( $remote as $pm ) {
			LNKup_Payment_Methods::upsert_from_provider( $user_id, array_merge( $pm, array( 'customer_id' => $customer ) ) );
		}
	}

	/**
	 * @param int $payment_id Payment ID.
	 */
	private function amount_cents( $payment_id ) {
		$total = LNKup_Financial_Service::get_checkout_amount( $payment_id );
		return (int) round( $total * 100 );
	}

	/**
	 * @param int $payment_id Payment ID.
	 */
	private function payment_metadata( $payment_id ) {
		$invoice = LNKup_Invoices::get_by_application( (int) get_post_meta( $payment_id, '_lnkup_application_id', true ) );
		return array(
			'payment_post_id'   => (string) $payment_id,
			'application_id'    => (string) get_post_meta( $payment_id, '_lnkup_application_id', true ),
			'event_id'          => (string) get_post_meta( $payment_id, '_lnkup_event_id', true ),
			'vendor_user_id'    => (string) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ),
			'organizer_user_id' => (string) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true ),
			'invoice_number'    => $invoice['invoice_number'] ?? '',
		);
	}

	/**
	 * @param int $payment_id Payment ID.
	 */
	private function success_url( $payment_id ) {
		return add_query_arg(
			array( 'lnkup_payment' => 'success', 'payment_id' => $payment_id ),
			lnkup_page_url_helper( 'vendor-dashboard' )
		);
	}

	private function cancel_url() {
		return add_query_arg( array( 'lnkup_payment' => 'cancel' ), lnkup_page_url_helper( 'vendor-dashboard' ) );
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

	/**
	 * Build destination charge params from fee breakdown.
	 *
	 * @param int   $organizer_id Organizer ID.
	 * @param array $breakdown    Fee breakdown.
	 * @return array
	 */
	private function connect_charge_params( $organizer_id, $breakdown ) {
		if ( ! class_exists( 'LNKup_Stripe_Connect' ) || ! LNKup_Stripe_Connect::is_connect_ready( $organizer_id ) ) {
			return array();
		}
		$platform_fee_cents = (int) round( (float) ( $breakdown['platform_fee'] ?? 0 ) * 100 );
		return LNKup_Stripe_Connect::destination_charge_params( $organizer_id, $platform_fee_cents );
	}

	/**
	 * @param int $method_id Local method ID.
	 * @param int $user_id   User ID.
	 */
	private function get_local_method( $method_id, $user_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . LNKup_Financial_Tables::payment_methods_table() . ' WHERE id = %d AND user_id = %d',
				absint( $method_id ),
				absint( $user_id )
			),
			ARRAY_A
		);
	}
}
