<?php
/**
 * Organizers never see LNKup's platform fee amounts.
 *
 * Organizer money builders already report amounts after fees; this strips the fee breakdown
 * fields that other shared payloads (payment audits, transaction rows, legacy payouts) still carry,
 * for every organizer response under lnkup/v1/financial/*. Admin and vendor responses are untouched
 * (vendors see the marketplace fee they pay).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Fee_Privacy {

	const ROUTE_PREFIX = '/lnkup/v1/financial/';

	/** Keys removed anywhere in an organizer response. Payout `fee_amount` (instant cash-out fee) stays. */
	const HIDDEN_KEYS = array(
		'platform_fee',
		'platform_fees',
		'lnkup_fee',
		'organizer_commission',
		'application_fee',
		'application_fee_cents',
		'expected_app_fee',
		'processing_fee',
		'vendor_checkout_fee',
		'vendor_checkout_fee_current',
		'vendor_checkout_fee_mismatch',
		'total_charged',
		'stripe_charge_amount_cents',
		'destination_amount',
		'organizer_net',
		'expected_net',
		'fee_breakdown',
		'fee_breakdown_source',
		'fee_payer_mode',
	);

	/** Money category rows that reveal fees (older payloads). */
	const HIDDEN_CATEGORIES = array( 'gross', 'fees' );

	public static function init() {
		// Runs inside dispatch, so HTTP requests and internal rest_do_request() calls are both covered.
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'filter_response' ), 20, 3 );
	}

	/**
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|array|mixed $response Callback result.
	 * @param array                                                  $handler  Route handler.
	 * @param WP_REST_Request                                        $request  Request.
	 * @return mixed
	 */
	public static function filter_response( $response, $handler, $request ) {
		if ( ! ( $request instanceof WP_REST_Request ) || is_wp_error( $response ) ) {
			return $response;
		}
		if ( 0 !== strpos( (string) $request->get_route(), self::ROUTE_PREFIX ) || ! self::is_organizer_request( $request ) ) {
			return $response;
		}
		if ( $response instanceof WP_HTTP_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) ) {
				$response->set_data( self::scrub( $data ) );
			}
			return $response;
		}
		return is_array( $response ) ? self::scrub( $response ) : $response;
	}

	/**
	 * Organizer view of a financial route: explicit role=organizer, or no role and an organizer account
	 * (same rule the financial routes use to pick the organizer payload). Admins see everything.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function is_organizer_request( $request ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return false;
		}
		$role = sanitize_key( (string) $request->get_param( 'role' ) );
		if ( 'vendor' === $role ) {
			return false;
		}
		return 'organizer' === $role || LNKup_Roles::is_organizer( $user_id );
	}

	/**
	 * Remove fee fields recursively.
	 *
	 * @param array $data Payload.
	 * @return array
	 */
	public static function scrub( array $data ) {
		$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );
		$out     = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( $key, self::HIDDEN_KEYS, true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				if ( isset( $value['key'] ) && is_string( $value['key'] ) && in_array( $value['key'], self::HIDDEN_CATEGORIES, true ) && array_key_exists( 'amount', $value ) ) {
					continue;
				}
				$value = self::scrub( $value );
			}
			if ( $is_list ) {
				$out[] = $value;
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}
