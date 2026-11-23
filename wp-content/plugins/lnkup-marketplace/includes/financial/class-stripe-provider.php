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

	public function create_checkout_session( $payment_id, $args = array() ) {
		$check = $this->validate_payment_access( $payment_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! $this->is_configured() ) {
			return ( new LNKup_Manual_Provider() )->create_checkout_session( $payment_id );
		}

		$prepared = $this->prepare_charge_for_stripe( $payment_id );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$amount_cents = $prepared['amount_cents'];
		$meta         = $this->payment_metadata( $payment_id );
		$open         = $this->settle_open_charges( $payment_id, $amount_cents, ! empty( $prepared['connect_params'] ) ? 'destination' : 'platform_treasury', true );
		if ( is_wp_error( $open ) ) {
			return $open;
		}
		$customer     = $this->get_or_create_customer( (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ) );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$pi_data = array( 'metadata' => $meta );
		if ( ! empty( $prepared['connect_params'] ) ) {
			$pi_data = array_merge( $pi_data, $prepared['connect_params'] );
		}

		$session_params = array(
			'mode'                => 'payment',
			'customer'            => $customer['id'],
			'success_url'         => $this->success_url( $payment_id, $args ),
			'cancel_url'          => $this->cancel_url( $payment_id, $args ),
			'client_reference_id' => (string) $payment_id,
			'payment_intent_data' => $pi_data,
			'line_items'          => array(
				array(
					'price_data' => array(
						'currency'     => 'usd',
						'product_data' => array( 'name' => lnkup_plain_title( $payment_id ) ),
						'unit_amount'  => $amount_cents,
					),
					'quantity'   => 1,
				),
			),
		);

		// Mirror PaymentIntent ACH contract: exclude bank debit when marketplace ACH is off.
		$excluded = $this->excluded_payment_method_types();
		if ( ! empty( $excluded ) ) {
			$session_params['excluded_payment_method_types'] = $excluded;
		}
		if ( ! empty( lnkup_get_settings()['ach_enabled'] ) ) {
			$session_params['payment_method_options'] = array(
				'us_bank_account' => array(
					'verification_method' => 'automatic',
				),
			);
		}

		$session = $this->client->create_checkout_session( $session_params );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( ! empty( $session['payment_intent'] ) ) {
			update_post_meta( $payment_id, '_lnkup_stripe_payment_intent', sanitize_text_field( $session['payment_intent'] ) );
		}
		if ( ! empty( $session['id'] ) ) {
			update_post_meta( $payment_id, '_lnkup_stripe_checkout_session', sanitize_text_field( $session['id'] ) );
			LNKup_Financial_Service::remember_expected_amount( $payment_id, (string) $session['id'], $amount_cents );
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

		$prepared = $this->prepare_charge_for_stripe( $payment_id );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$amount_cents = $prepared['amount_cents'];
		$meta         = $this->payment_metadata( $payment_id );
		// Settlement mode changes the request params, and Stripe rejects a reused key with different params.
		$mode         = ! empty( $prepared['connect_params'] ) ? 'destination' : 'platform_treasury';

		$open = $this->settle_open_charges( $payment_id, $amount_cents, $mode );
		if ( is_wp_error( $open ) ) {
			return $open;
		}
		if ( is_array( $open ) && ! empty( $open['reuse'] ) && empty( $args['payment_method_id'] ) ) {
			return array(
				'mode'            => 'embedded',
				'client_secret'   => $open['reuse']['client_secret'] ?? '',
				'intent_id'       => $open['reuse']['id'] ?? '',
				'publishable_key' => $this->client->get_publishable_key(),
			);
		}

		$seq          = (int) get_post_meta( $payment_id, '_lnkup_charge_seq', true );
		$param_hash   = substr( md5( (string) wp_json_encode( array( $args['payment_method_id'] ?? '', $this->excluded_payment_method_types(), ! empty( lnkup_get_settings()['ach_enabled'] ) ) ) ), 0, 8 );
		$idempotency  = 'lnkup_pi_' . $payment_id . '_' . $seq . '_' . $amount_cents . '_' . $mode . '_' . $param_hash;

		$params = array(
			'amount'                    => $amount_cents,
			'currency'                  => 'usd',
			'customer'                  => $customer['id'],
			'automatic_payment_methods' => array( 'enabled' => true ),
			'metadata'                  => $meta,
		);

		$excluded = $this->excluded_payment_method_types();
		if ( ! empty( $excluded ) ) {
			$params['excluded_payment_method_types'] = $excluded;
		}

		if ( ! empty( lnkup_get_settings()['ach_enabled'] ) ) {
			$params['payment_method_options'] = array(
				'us_bank_account' => array(
					'verification_method' => 'automatic',
				),
			);
		}

		if ( ! empty( $args['payment_method_id'] ) ) {
			$params['payment_method'] = sanitize_text_field( $args['payment_method_id'] );
			$params['confirm']        = false;
			unset( $params['automatic_payment_methods'] );
			unset( $params['excluded_payment_method_types'] );
		}

		if ( ! empty( $prepared['connect_params'] ) ) {
			$params = array_merge( $params, $prepared['connect_params'] );
		}

		$intent = $this->client->create_payment_intent( $params, $idempotency );
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		update_post_meta( $payment_id, '_lnkup_stripe_payment_intent', sanitize_text_field( $intent['id'] ?? '' ) );
		LNKup_Financial_Service::remember_expected_amount( $payment_id, (string) ( $intent['id'] ?? '' ), $amount_cents );

		return array(
			'mode'            => 'embedded',
			'client_secret'   => $intent['client_secret'] ?? '',
			'intent_id'       => $intent['id'] ?? '',
			'publishable_key' => $this->client->get_publishable_key(),
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
		$amount_cents   = (int) round( $amount * 100 );
		$prior_refunded = (int) round( (float) get_post_meta( $payment_id, '_lnkup_refund_amount', true ) * 100 );
		$partials       = get_post_meta( $payment_id, '_lnkup_partial_charges', true );
		if ( is_array( $partials ) && $partials ) {
			return $this->refund_across_intents( $payment_id, (string) $intent, $partials, $amount_cents, $prior_refunded );
		}
		$params         = array(
			'payment_intent' => $intent,
			'amount'         => $amount_cents,
		);
		// Destination charge: pull the organizer's share back from Connect; LNKup keeps its application fee.
		if ( 'destination' === get_post_meta( $payment_id, '_lnkup_settlement_mode', true ) ) {
			$params['reverse_transfer'] = true;
		}
		$result = $this->client->create_refund(
			$params,
			'lnkup_refund_' . absint( $payment_id ) . '_' . $amount_cents . '_' . $prior_refunded
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'provider_ref' => $result['id'] ?? '' );
	}

	/**
	 * Deposit / installment bookings are paid by several PaymentIntents. Spread a refund over
	 * them newest-first, never exceeding what each intent collected or already returned (B-9).
	 *
	 * @param int    $payment_id     Payment post ID.
	 * @param string $current_intent Latest PaymentIntent on the payment.
	 * @param array  $partials       Map of intent ID => array( cents ).
	 * @param int    $amount_cents   Amount to refund.
	 * @param int    $prior_refunded Cents refunded before this request (idempotency key salt).
	 * @return array|WP_Error
	 */
	private function refund_across_intents( $payment_id, $current_intent, $partials, $amount_cents, $prior_refunded ) {
		$capacity = array();
		foreach ( $partials as $ref => $row ) {
			if ( 0 === strpos( (string) $ref, 'pi_' ) ) {
				$capacity[ (string) $ref ] = (int) ( $row['cents'] ?? 0 );
			}
		}
		if ( '' !== $current_intent && 0 === strpos( $current_intent, 'pi_' ) && ! isset( $capacity[ $current_intent ] ) ) {
			$capacity[ $current_intent ] = (int) get_post_meta( $payment_id, '_lnkup_stripe_charge_amount_cents', true );
		}
		$done = get_post_meta( $payment_id, '_lnkup_refunded_by_intent', true );
		$done = is_array( $done ) ? $done : array();

		// Pieces sent by an earlier attempt of this same request (same prior_refunded) count already.
		$inflight  = get_post_meta( $payment_id, '_lnkup_refund_inflight', true );
		$inflight  = is_array( $inflight ) ? $inflight : array();
		$try_key   = 'p' . $prior_refunded;
		$plan      = array();
		$remaining = $amount_cents - (int) ( $inflight[ $try_key ] ?? 0 );
		foreach ( array_reverse( $capacity, true ) as $intent_id => $cents ) {
			if ( $remaining <= 0 ) {
				break;
			}
			$open = max( 0, $cents - (int) ( $done[ $intent_id ] ?? 0 ) );
			if ( $open <= 0 ) {
				continue;
			}
			$take                 = min( $open, $remaining );
			$plan[ $intent_id ]   = $take;
			$remaining           -= $take;
		}
		if ( $remaining > 0 ) {
			return new WP_Error( 'refund_exceeds_charges', __( 'This refund is larger than what the card payments collected.', 'lnkup-marketplace' ) );
		}

		$refs        = array();
		$destination = 'destination' === get_post_meta( $payment_id, '_lnkup_settlement_mode', true );
		foreach ( $plan as $intent_id => $cents ) {
			$params = array(
				'payment_intent' => $intent_id,
				'amount'         => $cents,
			);
			if ( $destination ) {
				$params['reverse_transfer'] = true;
			}
			$result = $this->client->create_refund( $params, 'lnkup_refund_' . absint( $payment_id ) . '_' . $intent_id . '_' . $cents . '_' . $prior_refunded );
			if ( is_wp_error( $result ) ) {
				// Already-sent pieces stay recorded so a retry only sends what is missing.
				update_post_meta( $payment_id, '_lnkup_refunded_by_intent', $done );
				update_post_meta( $payment_id, '_lnkup_refund_inflight', $inflight );
				return $result;
			}
			$done[ $intent_id ]   = (int) ( $done[ $intent_id ] ?? 0 ) + $cents;
			$inflight[ $try_key ] = (int) ( $inflight[ $try_key ] ?? 0 ) + $cents;
			$refs[]               = (string) ( $result['id'] ?? '' );
		}
		unset( $inflight[ $try_key ] );
		update_post_meta( $payment_id, '_lnkup_refunded_by_intent', $done );
		update_post_meta( $payment_id, '_lnkup_refund_inflight', $inflight );
		return array( 'provider_ref' => implode( ',', array_filter( $refs ) ) );
	}

	public function get_or_create_customer( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user.', 'lnkup-marketplace' ) );
		}
		if ( ! $this->is_configured() ) {
			return ( new LNKup_Manual_Provider() )->get_or_create_customer( $user_id );
		}

		$existing = $this->customer_id_for_mode( $user_id );
		if ( $existing ) {
			return array( 'id' => $existing );
		}

		// One creator per user, and a deterministic key, so concurrent checkouts share one customer.
		$client = $this->client;
		return LNKup_Financial_Service::with_lock(
			'cus_' . $user_id,
			function () use ( $user_id, $client ) {
				$existing = $this->customer_id_for_mode( $user_id );
				if ( $existing ) {
					return array( 'id' => $existing );
				}
				$user     = get_userdata( $user_id );
				$mode     = $client->key_mode();
				$customer = $client->create_customer(
					array(
						'email'    => $user ? $user->user_email : '',
						'name'     => $user ? $user->display_name : '',
						'metadata' => array(
							'wp_user_id' => (string) $user_id,
							'platform'   => 'lnkup',
						),
					),
					'lnkup_cus_' . $user_id . '_' . $mode
				);
				if ( is_wp_error( $customer ) ) {
					return $customer;
				}
				$id = sanitize_text_field( $customer['id'] ?? '' );
				update_user_meta( $user_id, '_lnkup_stripe_customer_id_' . $mode, $id );
				update_user_meta( $user_id, '_lnkup_stripe_customer_id', $id );
				return array( 'id' => $id );
			}
		);
	}

	/**
	 * Stripe customer for the configured key's mode. A legacy (mode-less) ID is adopted only
	 * after Stripe confirms it exists in this mode — test customers do not exist in live.
	 *
	 * @param int $user_id User ID.
	 * @return string Customer ID or ''.
	 */
	private function customer_id_for_mode( $user_id ) {
		$mode = $this->client->key_mode();
		$id   = (string) get_user_meta( $user_id, '_lnkup_stripe_customer_id_' . $mode, true );
		if ( '' !== $id ) {
			return $id;
		}
		$legacy = (string) get_user_meta( $user_id, '_lnkup_stripe_customer_id', true );
		if ( 0 !== strpos( $legacy, 'cus_' ) ) {
			return '';
		}
		$found = $this->client->read( 'customers/' . $legacy );
		if ( is_wp_error( $found ) || ! empty( $found['deleted'] ) ) {
			return '';
		}
		update_user_meta( $user_id, '_lnkup_stripe_customer_id_' . $mode, $legacy );
		return $legacy;
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
		$customer = $this->customer_id_for_mode( $user_id );
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
		$customer = $this->is_configured() ? $this->customer_id_for_mode( $user_id ) : get_user_meta( $user_id, '_lnkup_stripe_customer_id', true );
		foreach ( $remote as $pm ) {
			LNKup_Payment_Methods::upsert_from_provider( $user_id, array_merge( $pm, array( 'customer_id' => $customer ) ) );
		}
	}

	/**
	 * Payment method types to exclude when marketplace flags are off.
	 * Keep automatic_payment_methods enabled; do not hardcode payment_method_types.
	 *
	 * @return string[]
	 */
	private function excluded_payment_method_types() {
		$settings = lnkup_get_settings();
		$excluded = array();
		if ( empty( $settings['ach_enabled'] ) ) {
			$excluded[] = 'us_bank_account';
		}
		return $excluded;
	}

	/**
	 * Keep at most one payable Stripe charge per booking before creating a new one.
	 *
	 * - An open PaymentIntent for the same amount and mode is reused (embedded checkout).
	 * - Any other open PaymentIntent is cancelled and an open Checkout Session expired, so an
	 *   abandoned tab or app session can no longer be paid. Cancelling moves no money.
	 * - A charge that already succeeded or is processing blocks a new one; the payment is
	 *   reconciled instead so the vendor is never charged twice.
	 *
	 * @param int    $payment_id    Payment ID.
	 * @param int    $amount_cents  Amount about to be charged.
	 * @param string $mode          Settlement mode about to be used.
	 * @param bool   $for_session   True when a hosted session will be created (never reuse an intent).
	 * @return array|WP_Error array( 'reuse' => intent ) or empty array; WP_Error when blocked.
	 */
	private function settle_open_charges( $payment_id, $amount_cents, $mode, $for_session = false ) {
		$intent_id  = (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
		$session_id = (string) get_post_meta( $payment_id, '_lnkup_stripe_checkout_session', true );
		$blocked    = new WP_Error(
			'payment_in_progress',
			__( 'A payment for this booking is already in progress. Refresh in a moment.', 'lnkup-marketplace' ),
			array( 'status' => 409 )
		);

		if ( 0 === strpos( $session_id, 'cs_' ) ) {
			$session = $this->client->read( 'checkout/sessions/' . $session_id );
			if ( ! is_wp_error( $session ) ) {
				if ( 'complete' === ( $session['status'] ?? '' ) ) {
					LNKup_Financial_Service::reconcile_payment( $payment_id );
					return $blocked;
				}
				if ( 'open' === ( $session['status'] ?? '' ) ) {
					$this->client->expire_checkout_session( $session_id );
				}
			}
		}

		if ( 0 === strpos( $intent_id, 'pi_' ) ) {
			$intent = $this->client->read( 'payment_intents/' . $intent_id );
			if ( ! is_wp_error( $intent ) ) {
				$status = (string) ( $intent['status'] ?? '' );
				if ( in_array( $status, array( 'succeeded', 'processing' ), true ) ) {
					LNKup_Financial_Service::reconcile_payment( $payment_id, $intent_id );
					return $blocked;
				}
				if ( in_array( $status, array( 'requires_payment_method', 'requires_confirmation', 'requires_action' ), true ) ) {
					$same_mode = ( 'destination' === $mode ) === ( '' !== (string) ( $intent['transfer_data']['destination'] ?? '' ) );
					if ( ! $for_session && $same_mode && (int) ( $intent['amount'] ?? 0 ) === (int) $amount_cents ) {
						return array( 'reuse' => $intent );
					}
					$this->client->cancel_payment_intent( $intent_id );
				}
			}
		}
		return array();
	}

	/**
	 * Cancel/expire the booking's open Stripe charges (used when a payment is voided).
	 *
	 * @param int $payment_id Payment ID.
	 */
	public function cancel_open_charges( $payment_id ) {
		if ( ! $this->is_configured() ) {
			return;
		}
		$intent_id  = (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
		$session_id = (string) get_post_meta( $payment_id, '_lnkup_stripe_checkout_session', true );
		if ( 0 === strpos( $session_id, 'cs_' ) ) {
			$this->client->expire_checkout_session( $session_id );
		}
		if ( 0 === strpos( $intent_id, 'pi_' ) ) {
			$intent = $this->client->read( 'payment_intents/' . $intent_id );
			if ( ! is_wp_error( $intent ) && in_array( (string) ( $intent['status'] ?? '' ), array( 'requires_payment_method', 'requires_confirmation', 'requires_action' ), true ) ) {
				$this->client->cancel_payment_intent( $intent_id );
			}
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
	 * @param int   $payment_id Payment ID.
	 * @param array $args       Checkout args (return_to, app_base_url, application_id).
	 */
	private function success_url( $payment_id, $args = array() ) {
		if ( ! empty( $args['return_to'] ) && 'native' === $args['return_to'] ) {
			return lnkup_native_payment_return_url( $payment_id, 'success', $args );
		}
		if ( ! empty( $args['return_to'] ) && 'app' === $args['return_to'] ) {
			$app_url = lnkup_vendor_app_payment_return_url( $payment_id, 'success', $args );
			if ( $app_url ) {
				return $app_url;
			}
		}
		return add_query_arg(
			array( 'lnkup_payment' => 'success', 'payment_id' => $payment_id ),
			lnkup_page_url_helper( 'vendor-dashboard' )
		);
	}

	/**
	 * @param int   $payment_id Payment ID.
	 * @param array $args       Checkout args.
	 */
	private function cancel_url( $payment_id, $args = array() ) {
		if ( ! empty( $args['return_to'] ) && 'native' === $args['return_to'] ) {
			return lnkup_native_payment_return_url( $payment_id, 'cancelled', $args );
		}
		if ( ! empty( $args['return_to'] ) && 'app' === $args['return_to'] ) {
			$app_url = lnkup_vendor_app_payment_return_url( $payment_id, 'cancelled', $args );
			if ( $app_url ) {
				return $app_url;
			}
		}
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
	 * Refresh fees, validate Connect application_fee vs charge amount, stamp settlement meta.
	 *
	 * @param int $payment_id Payment post ID.
	 * @return array{amount_cents:int,breakdown:array,connect_params:array}|WP_Error
	 */
	private function prepare_charge_for_stripe( $payment_id ) {
		$payment_id = absint( $payment_id );
		if ( class_exists( 'LNKup_Payments' ) ) {
			LNKup_Payments::refresh_pending_fees( $payment_id );
		}

		$amount_cents = $this->amount_cents( $payment_id );
		if ( $amount_cents <= 0 ) {
			return new WP_Error(
				'invalid_amount',
				__( 'Payment amount must be greater than zero.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}

		$organizer_id  = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$breakdown     = LNKup_Fee_Engine::calculate_for_payment( $payment_id );
		$destination   = class_exists( 'LNKup_Stripe_Connect' ) && LNKup_Stripe_Connect::is_connect_ready( $organizer_id );
		$app_fee_cents = $destination
			? LNKup_Fee_Engine::destination_application_fee_cents( $payment_id, $amount_cents, $breakdown )
			: (int) round( (float) ( $breakdown['platform_fee'] ?? 0 ) * 100 );
		$breakdown['application_fee_cents'] = $app_fee_cents;

		if ( $app_fee_cents < 0 || ( $app_fee_cents >= $amount_cents && ! $destination ) ) {
			return new WP_Error(
				'invalid_application_fee',
				__( 'Marketplace fee must be less than the charge amount. Check booth price and fee settings.', 'lnkup-marketplace' ),
				array(
					'status'                => 400,
					'amount_cents'          => $amount_cents,
					'application_fee_cents' => $app_fee_cents,
				)
			);
		}

		$connect_params = $this->connect_charge_params( $organizer_id, $breakdown );
		if ( class_exists( 'LNKup_Stripe_Connect' ) ) {
			LNKup_Stripe_Connect::persist_payment_settlement_meta(
				$payment_id,
				$organizer_id,
				$breakdown,
				'create',
				$amount_cents
			);
		}

		return array(
			'amount_cents'   => $amount_cents,
			'breakdown'      => $breakdown,
			'connect_params' => $connect_params,
		);
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
		$fee_cents = isset( $breakdown['application_fee_cents'] )
			? (int) $breakdown['application_fee_cents']
			: (int) round( (float) ( $breakdown['platform_fee'] ?? 0 ) * 100 );
		return LNKup_Stripe_Connect::destination_charge_params( $organizer_id, $fee_cents );
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
