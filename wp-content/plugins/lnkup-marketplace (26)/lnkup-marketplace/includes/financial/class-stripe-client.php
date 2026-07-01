<?php
/**
 * Stripe API client — uses official SDK when available, HTTP fallback otherwise.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Stripe_Client {

	/**
	 * @var string
	 */
	private $secret_key;

	public function __construct( $secret_key = null ) {
		$settings         = lnkup_get_settings();
		$this->secret_key = $secret_key ?: ( $settings['stripe_secret_key'] ?? '' );
		if ( class_exists( '\Stripe\Stripe' ) && $this->secret_key ) {
			\Stripe\Stripe::setApiKey( $this->secret_key );
		}
	}

	public function is_available() {
		return ! empty( $this->secret_key );
	}

	public function get_publishable_key() {
		$settings = lnkup_get_settings();
		return $settings['stripe_public_key'] ?? '';
	}

	/**
	 * @param array $params Stripe API params.
	 * @return array|WP_Error
	 */
	public function create_customer( $params ) {
		return $this->request( 'POST', 'customers', $params );
	}

	/**
	 * @param string $customer_id Customer ID.
	 * @param array  $params      Params.
	 * @return array|WP_Error
	 */
	public function update_customer( $customer_id, $params ) {
		return $this->request( 'POST', 'customers/' . $customer_id, $params );
	}

	/**
	 * @param array $params Params.
	 * @return array|WP_Error
	 */
	public function create_payment_intent( $params, $idempotency_key = '' ) {
		return $this->request( 'POST', 'payment_intents', $params, $idempotency_key );
	}

	/**
	 * @param string $intent_id Intent ID.
	 * @param array  $params    Params.
	 * @return array|WP_Error
	 */
	public function update_payment_intent( $intent_id, $params ) {
		return $this->request( 'POST', 'payment_intents/' . $intent_id, $params );
	}

	/**
	 * @param string $intent_id Intent ID.
	 * @return array|WP_Error
	 */
	public function retrieve_payment_intent( $intent_id ) {
		return $this->request( 'GET', 'payment_intents/' . $intent_id );
	}

	/**
	 * @param array $params Params.
	 * @return array|WP_Error
	 */
	public function create_setup_intent( $params ) {
		return $this->request( 'POST', 'setup_intents', $params );
	}

	/**
	 * @param array $params Params.
	 * @return array|WP_Error
	 */
	public function create_checkout_session( $params ) {
		return $this->request( 'POST', 'checkout/sessions', $params );
	}

	/**
	 * @param array $params Params.
	 * @return array|WP_Error
	 */
	public function create_refund( $params ) {
		return $this->request( 'POST', 'refunds', $params );
	}

	/**
	 * @param array $params Account params.
	 * @return array|WP_Error
	 */
	public function create_connect_account( $params ) {
		return $this->request( 'POST', 'accounts', $params );
	}

	/**
	 * @param array $params Link params.
	 * @return array|WP_Error
	 */
	public function create_account_link( $params ) {
		return $this->request( 'POST', 'account_links', $params );
	}

	/**
	 * @param array $params Transfer params.
	 * @return array|WP_Error
	 */
	public function create_transfer( $params ) {
		return $this->request( 'POST', 'transfers', $params );
	}

	/**
	 * @param string $account_id Stripe Connect account ID.
	 * @return array|WP_Error
	 */
	public function retrieve_account( $account_id ) {
		return $this->request( 'GET', 'accounts/' . $account_id );
	}

	/**
	 * Retrieve Connect account balance (available + pending).
	 *
	 * @param string $stripe_account_id Connected account ID.
	 * @return array|WP_Error
	 */
	public function retrieve_connect_balance( $stripe_account_id ) {
		$balance = $this->request(
			'GET',
			'balance',
			array(),
			'',
			array( 'Stripe-Account' => $stripe_account_id )
		);
		if ( is_wp_error( $balance ) ) {
			return $balance;
		}

		$available = 0;
		$pending   = 0;
		$currency  = 'usd';
		foreach ( (array) ( $balance['available'] ?? array() ) as $item ) {
			if ( 'usd' === strtolower( (string) ( $item['currency'] ?? '' ) ) ) {
				$available += (int) ( $item['amount'] ?? 0 );
				$currency   = $item['currency'];
			}
		}
		foreach ( (array) ( $balance['pending'] ?? array() ) as $item ) {
			if ( 'usd' === strtolower( (string) ( $item['currency'] ?? '' ) ) ) {
				$pending += (int) ( $item['amount'] ?? 0 );
			}
		}

		return array(
			'available' => round( $available / 100, 2 ),
			'pending'   => round( $pending / 100, 2 ),
			'currency'  => $currency,
			'synced'    => true,
		);
	}

	/**
	 * @param string $account_id Connect account ID.
	 * @return array|WP_Error
	 */
	public function create_login_link( $account_id ) {
		return $this->request( 'POST', 'accounts/' . $account_id . '/login_links' );
	}

	/**
	 * @param string $account_id Connect account ID.
	 * @param array  $params     Payout params.
	 * @return array|WP_Error
	 */
	public function create_connect_payout( $account_id, $params ) {
		return $this->request( 'POST', 'payouts', $params, '', array( 'Stripe-Account' => $account_id ) );
	}

	/**
	 * @param string $transfer_id Transfer ID.
	 * @param array  $params      Reversal params.
	 * @return array|WP_Error
	 */
	public function reverse_transfer( $transfer_id, $params = array() ) {
		return $this->request( 'POST', 'transfers/' . $transfer_id . '/reversals', $params );
	}

	/**
	 * @param string $customer_id Customer ID.
	 * @return array|WP_Error
	 */
	public function list_payment_methods( $customer_id ) {
		return $this->request( 'GET', 'payment_methods', array( 'customer' => $customer_id, 'type' => 'card' ) );
	}

	/**
	 * @param string $method_id Payment method ID.
	 * @return array|WP_Error
	 */
	public function detach_payment_method( $method_id ) {
		return $this->request( 'POST', 'payment_methods/' . $method_id . '/detach' );
	}

	/**
	 * Verify Stripe webhook signature.
	 *
	 * @param string $payload   Raw body.
	 * @param string $sig_header Stripe-Signature header.
	 * @param string $secret    Webhook secret.
	 * @return array|WP_Error Decoded event.
	 */
	public function verify_webhook( $payload, $sig_header, $secret ) {
		if ( class_exists( '\Stripe\Webhook' ) && $secret && $sig_header ) {
			try {
				$event = \Stripe\Webhook::constructEvent( $payload, $sig_header, $secret );
				return json_decode( wp_json_encode( $event ), true );
			} catch ( Exception $e ) {
				return new WP_Error( 'invalid_signature', $e->getMessage() );
			}
		}
		if ( ! $secret || ! $sig_header ) {
			return json_decode( $payload, true );
		}
		$parts = array();
		foreach ( explode( ',', $sig_header ) as $item ) {
			$pair = explode( '=', trim( $item ), 2 );
			if ( 2 === count( $pair ) ) {
				$parts[ $pair[0] ] = $pair[1];
			}
		}
		if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) {
			return new WP_Error( 'invalid_signature', __( 'Invalid Stripe signature header.', 'lnkup-marketplace' ) );
		}
		$signed   = $parts['t'] . '.' . $payload;
		$expected = hash_hmac( 'sha256', $signed, $secret );
		if ( ! hash_equals( $expected, $parts['v1'] ) ) {
			return new WP_Error( 'invalid_signature', __( 'Webhook signature mismatch.', 'lnkup-marketplace' ) );
		}
		return json_decode( $payload, true );
	}

	/**
	 * @param string $method HTTP method.
	 * @param string $path   API path without version prefix.
	 * @param array  $params Request params.
	 * @param string $idempotency_key Optional idempotency key.
	 * @param array  $extra_headers   Optional extra HTTP headers.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $params = array(), $idempotency_key = '', $extra_headers = array() ) {
		if ( ! $this->secret_key ) {
			return new WP_Error( 'not_configured', __( 'Stripe is not configured.', 'lnkup-marketplace' ) );
		}

		if ( class_exists( '\Stripe\Stripe' ) ) {
			return $this->sdk_request( $method, $path, $params, $idempotency_key, $extra_headers );
		}

		$url = 'https://api.stripe.com/v1/' . ltrim( $path, '/' );
		$headers = array_merge(
			array( 'Authorization' => 'Bearer ' . $this->secret_key ),
			$extra_headers
		);
		if ( ! empty( $extra_headers['Stripe-Account'] ) ) {
			$headers['Stripe-Account'] = $extra_headers['Stripe-Account'];
		}
		if ( $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 30,
		);

		if ( 'GET' === $method ) {
			$url = add_query_arg( $params, $url );
		} else {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['headers']         = $headers;
			$args['body']            = $this->flatten_params( $params );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || ! empty( $body['error'] ) ) {
			$message = $body['error']['message'] ?? __( 'Stripe API error.', 'lnkup-marketplace' );
			return new WP_Error( 'stripe_error', $message, array( 'status' => $code ) );
		}
		return $body;
	}

	/**
	 * @param string $method HTTP method.
	 * @param string $path   Path.
	 * @param array  $params Params.
	 * @param string $idempotency_key Idempotency key.
	 * @param array  $extra_headers   Extra headers (e.g. Stripe-Account).
	 * @return array|WP_Error
	 */
	private function sdk_request( $method, $path, $params, $idempotency_key, $extra_headers = array() ) {
		$opts = array();
		if ( $idempotency_key ) {
			$opts['idempotency_key'] = $idempotency_key;
		}
		if ( ! empty( $extra_headers['Stripe-Account'] ) ) {
			$opts['stripe_account'] = $extra_headers['Stripe-Account'];
		}
		try {
			$parts = explode( '/', ltrim( $path, '/' ) );
			$resource = $parts[0];
			$id       = $parts[1] ?? '';
			$action   = $parts[2] ?? '';

			switch ( $resource ) {
				case 'customers':
					if ( 'POST' === $method && ! $id ) {
						$obj = \Stripe\Customer::create( $params, $opts );
					} else {
						$obj = \Stripe\Customer::update( $id, $params, $opts );
					}
					break;
				case 'payment_intents':
					if ( 'GET' === $method ) {
						$obj = \Stripe\PaymentIntent::retrieve( $id );
					} elseif ( $id ) {
						$obj = \Stripe\PaymentIntent::update( $id, $params, $opts );
					} else {
						$obj = \Stripe\PaymentIntent::create( $params, $opts );
					}
					break;
				case 'setup_intents':
					$obj = \Stripe\SetupIntent::create( $params, $opts );
					break;
				case 'checkout':
					$obj = \Stripe\Checkout\Session::create( $params, $opts );
					break;
				case 'refunds':
					$obj = \Stripe\Refund::create( $params, $opts );
					break;
				case 'accounts':
					if ( 'GET' === $method && $id ) {
						$obj = \Stripe\Account::retrieve( $id, array(), $opts );
					} elseif ( 'POST' === $method && $id && 'login_links' === $action ) {
						$obj = \Stripe\Account::createLoginLink( $id, $params, $opts );
					} elseif ( 'POST' === $method && ! $id ) {
						$obj = \Stripe\Account::create( $params, $opts );
					}
					break;
				case 'account_links':
					$obj = \Stripe\AccountLink::create( $params, $opts );
					break;
				case 'transfers':
					if ( $id && 'reversals' === $action ) {
						$obj = \Stripe\Transfer::createReversal( $id, $params, $opts );
					} else {
						$obj = \Stripe\Transfer::create( $params, $opts );
					}
					break;
				case 'payouts':
					$obj = \Stripe\Payout::create( $params, $opts );
					break;
				case 'payment_methods':
					if ( 'detach' === $action ) {
						$obj = \Stripe\PaymentMethod::retrieve( $id );
						$obj->detach();
					} else {
						$obj = \Stripe\PaymentMethod::all( $params );
					}
					break;
				case 'balance':
					$obj = \Stripe\Balance::retrieve( array(), $opts );
					break;
				default:
					return new WP_Error( 'stripe_error', __( 'Unsupported Stripe resource.', 'lnkup-marketplace' ) );
			}
			return json_decode( wp_json_encode( $obj ), true );
		} catch ( Exception $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Flatten nested array for form encoding.
	 *
	 * @param array  $params Params.
	 * @param string $prefix Key prefix.
	 * @return array
	 */
	private function flatten_params( $params, $prefix = '' ) {
		$result = array();
		foreach ( $params as $key => $value ) {
			$full_key = $prefix ? $prefix . '[' . $key . ']' : $key;
			if ( is_array( $value ) ) {
				$result = array_merge( $result, $this->flatten_params( $value, $full_key ) );
			} else {
				$result[ $full_key ] = $value;
			}
		}
		return $result;
	}
}
