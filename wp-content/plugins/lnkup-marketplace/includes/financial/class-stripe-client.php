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
	 * @param array  $params          Params.
	 * @param string $idempotency_key Optional idempotency key.
	 * @return array|WP_Error
	 */
	public function create_refund( $params, $idempotency_key = '' ) {
		return $this->request( 'POST', 'refunds', $params, $idempotency_key );
	}

	/**
	 * @param array  $params          Stripe subscription params.
	 * @param string $idempotency_key Optional idempotency key.
	 * @return array|WP_Error
	 */
	public function create_subscription( $params, $idempotency_key = '' ) {
		return $this->request( 'POST', 'subscriptions', $params, $idempotency_key );
	}

	/**
	 * @param string $subscription_id Stripe subscription ID.
	 * @param array  $params          Optional params (e.g. cancel_at_period_end).
	 * @return array|WP_Error
	 */
	public function cancel_subscription( $subscription_id, $params = array() ) {
		$path = 'subscriptions/' . rawurlencode( $subscription_id );
		if ( empty( $params ) ) {
			return $this->request( 'DELETE', $path );
		}
		return $this->request( 'POST', $path, $params );
	}

	/**
	 * @param string $subscription_id Stripe subscription ID.
	 * @return array|WP_Error
	 */
	public function retrieve_subscription( $subscription_id ) {
		return $this->request( 'GET', 'subscriptions/' . rawurlencode( $subscription_id ) );
	}

	/**
	 * @param array  $params          Account params.
	 * @param string $idempotency_key Optional idempotency key.
	 * @return array|WP_Error
	 */
	public function create_connect_account( $params, $idempotency_key = '' ) {
		return $this->request( 'POST', 'accounts', $params, $idempotency_key );
	}

	/**
	 * @param string $account_id Connect account ID.
	 * @param array  $params     Update params.
	 * @return array|WP_Error
	 */
	public function update_connect_account( $account_id, $params ) {
		return $this->request( 'POST', 'accounts/' . $account_id, $params );
	}

	/**
	 * @param array $params Link params.
	 * @return array|WP_Error
	 */
	public function create_account_link( $params ) {
		return $this->request( 'POST', 'account_links', $params );
	}

	/**
	 * @param array  $params          Transfer params.
	 * @param string $idempotency_key Optional idempotency key.
	 * @return array|WP_Error
	 */
	public function create_transfer( $params, $idempotency_key = '' ) {
		return $this->request( 'POST', 'transfers', $params, $idempotency_key );
	}

	/**
	 * @param string $account_id Stripe Connect account ID.
	 * @return array|WP_Error
	 */
	public function retrieve_account( $account_id ) {
		return $this->request( 'GET', 'accounts/' . $account_id );
	}

	/**
	 * Retrieve Connect account balance (available + pending + instant).
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
		return $this->normalize_balance_payload( $balance );
	}

	/**
	 * Retrieve platform (LNKup) Stripe balance — no Stripe-Account header.
	 *
	 * @return array|WP_Error
	 */
	public function retrieve_platform_balance() {
		$balance = $this->request( 'GET', 'balance', array(), '', array() );
		return $this->normalize_balance_payload( $balance );
	}

	/**
	 * Normalize Stripe Balance API payload into dollars + cents maps.
	 *
	 * @param array|WP_Error $balance Raw balance or error.
	 * @return array|WP_Error
	 */
	private function normalize_balance_payload( $balance ) {
		if ( is_wp_error( $balance ) ) {
			return $balance;
		}

		$settings         = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		$preferred        = strtolower( (string) ( $settings['connect_platform_currency'] ?? 'usd' ) );
		$available_items  = (array) ( $balance['available'] ?? array() );
		$pending_items    = (array) ( $balance['pending'] ?? array() );
		$instant_items    = (array) ( $balance['instant_available'] ?? array() );
		$available        = 0;
		$pending          = 0;
		$instant          = 0;
		$currency         = $preferred;
		$source_types     = array();
		$instant_sources  = array();
		$matched          = false;

		foreach ( $available_items as $item ) {
			$item_currency = strtolower( (string) ( $item['currency'] ?? '' ) );
			if ( $item_currency === $preferred ) {
				$available += (int) ( $item['amount'] ?? 0 );
				$currency   = $item_currency;
				$matched    = true;
				$item_sources = is_array( $item['source_types'] ?? null ) ? $item['source_types'] : array();
				foreach ( $item_sources as $source_key => $source_cents ) {
					$key = sanitize_key( (string) $source_key );
					if ( '' === $key ) {
						continue;
					}
					$source_types[ $key ] = (int) ( $source_types[ $key ] ?? 0 ) + (int) $source_cents;
				}
			}
		}
		foreach ( $pending_items as $item ) {
			if ( strtolower( (string) ( $item['currency'] ?? '' ) ) === $preferred ) {
				$pending += (int) ( $item['amount'] ?? 0 );
			}
		}
		foreach ( $instant_items as $item ) {
			if ( strtolower( (string) ( $item['currency'] ?? '' ) ) === $preferred ) {
				$instant += (int) ( $item['amount'] ?? 0 );
				$item_sources = is_array( $item['source_types'] ?? null ) ? $item['source_types'] : array();
				foreach ( $item_sources as $source_key => $source_cents ) {
					$key = sanitize_key( (string) $source_key );
					if ( '' === $key ) {
						continue;
					}
					$instant_sources[ $key ] = (int) ( $instant_sources[ $key ] ?? 0 ) + (int) $source_cents;
				}
			}
		}

		if ( ! $matched && ! empty( $available_items[0] ) ) {
			$fallback         = $available_items[0];
			$currency         = strtolower( (string) ( $fallback['currency'] ?? $preferred ) );
			$available        = (int) ( $fallback['amount'] ?? 0 );
			$source_types     = array();
			$item_sources     = is_array( $fallback['source_types'] ?? null ) ? $fallback['source_types'] : array();
			foreach ( $item_sources as $source_key => $source_cents ) {
				$key = sanitize_key( (string) $source_key );
				if ( '' === $key ) {
					continue;
				}
				$source_types[ $key ] = (int) $source_cents;
			}
			$pending = 0;
			foreach ( $pending_items as $item ) {
				if ( strtolower( (string) ( $item['currency'] ?? '' ) ) === $currency ) {
					$pending += (int) ( $item['amount'] ?? 0 );
				}
			}
			$instant         = 0;
			$instant_sources = array();
			foreach ( $instant_items as $item ) {
				if ( strtolower( (string) ( $item['currency'] ?? '' ) ) === $currency ) {
					$instant += (int) ( $item['amount'] ?? 0 );
					$item_sources = is_array( $item['source_types'] ?? null ) ? $item['source_types'] : array();
					foreach ( $item_sources as $source_key => $source_cents ) {
						$key = sanitize_key( (string) $source_key );
						if ( '' === $key ) {
							continue;
						}
						$instant_sources[ $key ] = (int) ( $instant_sources[ $key ] ?? 0 ) + (int) $source_cents;
					}
				}
			}
		}

		return array(
			'available'               => round( $available / 100, 2 ),
			'pending'                 => round( $pending / 100, 2 ),
			'instant_available'       => round( $instant / 100, 2 ),
			'available_cents'         => $available,
			'pending_cents'           => $pending,
			'instant_available_cents' => $instant,
			'currency'                => $currency,
			'source_types'            => $source_types,
			'instant_source_types'    => $instant_sources,
			'synced'                  => true,
		);
	}

	/**
	 * List external accounts (bank / debit) for a connected account.
	 *
	 * @param string $stripe_account_id Connected account ID.
	 * @return array|WP_Error List of account objects or error.
	 */
	public function list_connect_external_accounts( $stripe_account_id ) {
		$result = $this->request(
			'GET',
			'accounts/' . $stripe_account_id . '/external_accounts',
			array( 'limit' => 20 ),
			'',
			array()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return is_array( $result['data'] ?? null ) ? $result['data'] : array();
	}

	/**
	 * @param string $account_id Connect account ID.
	 * @return array|WP_Error
	 */
	public function create_login_link( $account_id ) {
		return $this->request( 'POST', 'accounts/' . $account_id . '/login_links' );
	}

	/**
	 * @param string $account_id      Connect account ID.
	 * @param array  $params          Payout params.
	 * @param string $idempotency_key Optional idempotency key.
	 * @return array|WP_Error
	 */
	public function create_connect_payout( $account_id, $params, $idempotency_key = '' ) {
		return $this->request( 'POST', 'payouts', $params, $idempotency_key, array( 'Stripe-Account' => $account_id ) );
	}

	/**
	 * Create a payout on the platform (LNKup main) Stripe account.
	 *
	 * @param array $params Payout params (amount, currency, source_type, …).
	 * @return array|WP_Error
	 */
	public function create_platform_payout( $params ) {
		return $this->request( 'POST', 'payouts', $params, '', array() );
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
	 * Never returns an unverified payload: a missing secret or header is a WP_Error.
	 *
	 * @param string $payload    Raw body.
	 * @param string $sig_header Stripe-Signature header.
	 * @param string $secret     Webhook secret.
	 * @param int    $tolerance  Max age of the signed timestamp, in seconds.
	 * @return array|WP_Error Decoded event.
	 */
	public function verify_webhook( $payload, $sig_header, $secret, $tolerance = 300 ) {
		$payload    = (string) $payload;
		$sig_header = trim( (string) $sig_header );
		$secret     = trim( (string) $secret );

		if ( '' === $secret ) {
			return new WP_Error( 'not_configured', __( 'Webhook signing secret is not configured.', 'lnkup-marketplace' ) );
		}
		if ( '' === $sig_header ) {
			return new WP_Error( 'missing_signature', __( 'Missing Stripe signature header.', 'lnkup-marketplace' ) );
		}

		if ( class_exists( '\Stripe\Webhook' ) ) {
			try {
				\Stripe\Webhook::constructEvent( $payload, $sig_header, $secret, (int) $tolerance );
			} catch ( Exception $e ) {
				return new WP_Error( 'invalid_signature', $e->getMessage() );
			}
			return $this->decode_event_payload( $payload );
		}

		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', $sig_header ) as $item ) {
			$pair = explode( '=', trim( $item ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] && '' !== $pair[1] ) {
				$signatures[] = $pair[1];
			}
		}
		if ( $timestamp < 1 || empty( $signatures ) ) {
			return new WP_Error( 'invalid_signature', __( 'Invalid Stripe signature header.', 'lnkup-marketplace' ) );
		}
		if ( $tolerance > 0 && abs( time() - $timestamp ) > (int) $tolerance ) {
			return new WP_Error( 'invalid_signature', __( 'Webhook timestamp is outside the allowed tolerance.', 'lnkup-marketplace' ) );
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return $this->decode_event_payload( $payload );
			}
		}
		return new WP_Error( 'invalid_signature', __( 'Webhook signature mismatch.', 'lnkup-marketplace' ) );
	}

	/**
	 * Decode a verified webhook body.
	 *
	 * @param string $payload Raw body.
	 * @return array|WP_Error
	 */
	private function decode_event_payload( $payload ) {
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) ) {
			return new WP_Error( 'invalid_payload', __( 'Webhook payload is not valid JSON.', 'lnkup-marketplace' ) );
		}
		return $event;
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
			$message    = $body['error']['message'] ?? __( 'Stripe API error.', 'lnkup-marketplace' );
			$stripe_code = isset( $body['error']['code'] ) ? (string) $body['error']['code'] : '';
			return new WP_Error(
				'stripe_error',
				$message,
				array(
					'status'      => $code,
					'stripe_code' => $stripe_code,
				)
			);
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
					if ( 'GET' === $method && $id && 'external_accounts' === $action ) {
						if ( is_callable( array( '\Stripe\Account', 'allExternalAccounts' ) ) ) {
							$obj = \Stripe\Account::allExternalAccounts( $id, $params, $opts );
						} else {
							$acct = \Stripe\Account::retrieve( $id, array(), $opts );
							$obj  = $acct->external_accounts->all( $params );
						}
					} elseif ( 'GET' === $method && $id ) {
						$obj = \Stripe\Account::retrieve( $id, array(), $opts );
					} elseif ( 'POST' === $method && $id && 'login_links' === $action ) {
						$obj = \Stripe\Account::createLoginLink( $id, $params, $opts );
					} elseif ( 'POST' === $method && $id ) {
						$obj = \Stripe\Account::update( $id, $params, $opts );
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
				case 'subscriptions':
					if ( 'GET' === $method && $id ) {
						$obj = \Stripe\Subscription::retrieve( $id, $opts );
					} elseif ( 'DELETE' === $method && $id ) {
						$obj = \Stripe\Subscription::cancel( $id, $params ?: null, $opts );
					} elseif ( 'POST' === $method && $id ) {
						$obj = \Stripe\Subscription::update( $id, $params, $opts );
					} else {
						$obj = \Stripe\Subscription::create( $params, $opts );
					}
					break;
				case 'balance':
					// Balance::retrieve( $opts ) takes a single opts argument (not params, opts).
					$obj = \Stripe\Balance::retrieve( $opts );
					break;
				default:
					return new WP_Error( 'stripe_error', __( 'Unsupported Stripe resource.', 'lnkup-marketplace' ) );
			}
			return json_decode( wp_json_encode( $obj ), true );
		} catch ( Exception $e ) {
			$stripe_code = '';
			if ( is_object( $e ) && method_exists( $e, 'getStripeCode' ) ) {
				$stripe_code = (string) $e->getStripeCode();
			}
			return new WP_Error(
				'stripe_error',
				$e->getMessage(),
				array(
					'status'      => 400,
					'stripe_code' => $stripe_code,
				)
			);
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
