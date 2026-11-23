<?php
/**
 * Financial platform REST API.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_REST {

	const NS = 'lnkup/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$vendor = array( 'permission_callback' => array( __CLASS__, 'vendor_permission' ) );
		$org    = array( 'permission_callback' => array( __CLASS__, 'organizer_permission' ) );
		$admin  = array( 'permission_callback' => array( __CLASS__, 'admin_permission' ) );
		$fin    = array( 'permission_callback' => array( __CLASS__, 'financial_permission' ) );

		register_rest_route( self::NS, '/financial/dashboard', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'dashboard' ),
		) ) );
		register_rest_route( self::NS, '/financial/summary', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'dashboard' ),
		) ) );

		register_rest_route( self::NS, '/financial/transactions', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'transactions' ),
		) ) );

		register_rest_route( self::NS, '/financial/sync', array_merge( $fin, array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'sync' ),
		) ) );

		register_rest_route( self::NS, '/financial/invoices', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'invoices' ),
		) ) );

		register_rest_route( self::NS, '/financial/receipts', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'receipts' ),
		) ) );

		register_rest_route( self::NS, '/financial/wallet', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'wallet' ),
		) ) );

		register_rest_route( self::NS, '/financial/refunds', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'refunds_list' ),
		) ) );
		register_rest_route( self::NS, '/financial/refunds', array_merge( $fin, array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'refunds_create' ),
		) ) );

		register_rest_route( self::NS, '/financial/refunds/(?P<id>\d+)/approve', array_merge( $org, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'refunds_approve' ),
		) ) );

		register_rest_route( self::NS, '/financial/payouts', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'payouts' ),
		) ) );

		register_rest_route( self::NS, '/financial/disputes', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'disputes_list' ),
		) ) );
		register_rest_route( self::NS, '/financial/disputes', array_merge( $fin, array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'disputes_create' ),
		) ) );

		register_rest_route( self::NS, '/financial/disputes/(?P<id>\d+)/respond', array_merge( $fin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'disputes_respond' ),
		) ) );

		register_rest_route( self::NS, '/financial/disputes/(?P<id>\d+)/resolve', array_merge( $org, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'disputes_resolve' ),
		) ) );

		register_rest_route( self::NS, '/financial/installments', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'installments' ),
		) ) );

		register_rest_route( self::NS, '/financial/payment-methods', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'payment_methods' ),
		) ) );

		register_rest_route( self::NS, '/financial/promo-codes/validate', array_merge( $vendor, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'promo_validate' ),
		) ) );

		register_rest_route( self::NS, '/financial/reports/(?P<type>[a-z_]+)', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'reports' ),
		) ) );

		register_rest_route( self::NS, '/financial/events/(?P<id>\d+)/financials', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_financials' ),
		) ) );

		register_rest_route( self::NS, '/financial/tax-profile', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'tax_profile' ),
		) ) );

		register_rest_route( self::NS, '/financial/admin/summary', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'admin_summary' ),
		) ) );

		register_rest_route( self::NS, '/financial/checkout/(?P<payment_id>\d+)/summary', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'checkout_summary' ),
		) ) );
		register_rest_route( self::NS, '/financial/checkout/(?P<payment_id>\d+)/prepare', array_merge( $vendor, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'checkout_prepare' ),
		) ) );
		register_rest_route( self::NS, '/financial/checkout/(?P<payment_id>\d+)/confirm', array_merge( $vendor, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'checkout_confirm' ),
		) ) );
		register_rest_route( self::NS, '/financial/checkout/(?P<payment_id>\d+)/apply-credits', array_merge( $vendor, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'checkout_apply_credits' ),
		) ) );
		register_rest_route( self::NS, '/financial/checkout/(?P<payment_id>\d+)/redirect-url', array_merge( $vendor, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'checkout_redirect_url' ),
		) ) );
		// Public bounce from Stripe Checkout back into the native app. Changes no state;
		// the app re-reads payment status from authenticated routes after it reopens.
		register_rest_route( self::NS, '/financial/checkout/native-return', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'checkout_native_return' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'payment'        => array( 'sanitize_callback' => 'sanitize_key' ),
				'application_id' => array( 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( self::NS, '/financial/activity', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'activity' ),
		) ) );

		register_rest_route( self::NS, '/financial/payments/(?P<payment_id>\d+)/timeline', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'payment_timeline' ),
		) ) );
		register_rest_route( self::NS, '/financial/payments/(?P<payment_id>\d+)/audit', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'payment_audit' ),
		) ) );
		register_rest_route( self::NS, '/financial/payments/(?P<payment_id>\d+)/reconcile', array_merge( $org, array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'payment_reconcile' ),
		) ) );

		register_rest_route( self::NS, '/financial/payment-methods/setup', array_merge( $vendor, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'payment_methods_setup' ),
		) ) );
		register_rest_route( self::NS, '/financial/payment-methods/(?P<id>\d+)', array(
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'payment_methods_delete' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'payment_methods_patch' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
			),
		) );

		register_rest_route( self::NS, '/financial/invoices/(?P<id>\d+)', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'invoice_detail' ),
		) ) );
		register_rest_route( self::NS, '/financial/invoices/(?P<id>\d+)/pdf', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'invoice_pdf' ),
			'permission_callback' => array( __CLASS__, 'invoice_document_permission' ),
		) );
		register_rest_route( self::NS, '/financial/invoices/(?P<id>\d+)/email', array_merge( $fin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'invoice_email' ),
		) ) );

		register_rest_route( self::NS, '/financial/receipts/(?P<id>\d+)', array_merge( $vendor, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'receipt_detail' ),
		) ) );
		register_rest_route( self::NS, '/financial/receipts/(?P<id>\d+)/view', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'receipt_view' ),
			'permission_callback' => array( __CLASS__, 'receipt_document_permission' ),
		) );

		register_rest_route( self::NS, '/financial/refunds/(?P<id>\d+)/deny', array_merge( $org, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'refunds_deny' ),
		) ) );

		register_rest_route( self::NS, '/financial/reports/export/(?P<format>csv|pdf)', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'reports_export' ),
		) ) );

		register_rest_route( self::NS, '/financial/organizer-wallet', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'organizer_wallet' ),
		) ) );
		register_rest_route( self::NS, '/financial/organizer-balances', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'organizer_balances' ),
		) ) );
		register_rest_route( self::NS, '/financial/organizer-wallet/entries', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'organizer_wallet_entries' ),
		) ) );
		register_rest_route( self::NS, '/financial/organizer-wallet/cashout', array_merge( $org, array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'organizer_cashout' ),
		) ) );
		register_rest_route( self::NS, '/financial/organizer-payouts', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'organizer_payouts' ),
		) ) );
		register_rest_route( self::NS, '/financial/organizer-payouts/(?P<id>\d+)/cancel', array_merge( $org, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'organizer_payout_cancel' ),
		) ) );

		register_rest_route( self::NS, '/financial/connect/onboarding-link', array_merge( $org, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'connect_onboarding' ),
		) ) );
		register_rest_route( self::NS, '/financial/connect/login-link', array_merge( $org, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'connect_login_link' ),
		) ) );
		register_rest_route( self::NS, '/financial/connect/status', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'connect_status' ),
		) ) );
		register_rest_route( self::NS, '/financial/connect/refresh', array_merge( $org, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'connect_refresh' ),
		) ) );
		register_rest_route( self::NS, '/financial/connect/deauthorize', array_merge( $org, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'connect_deauthorize' ),
		) ) );

		register_rest_route( self::NS, '/financial/referrals', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'referrals_list' ),
		) ) );
		register_rest_route( self::NS, '/financial/referrals/code', array_merge( $fin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'referrals_code' ),
		) ) );
		register_rest_route( self::NS, '/financial/credits/apply', array_merge( $vendor, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'credits_apply' ),
		) ) );

		register_rest_route( self::NS, '/financial/sponsor-invoices', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'sponsor_invoices' ),
		) ) );
		register_rest_route( self::NS, '/financial/sponsor-invoices', array_merge( $org, array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'sponsor_invoices_create' ),
		) ) );

		register_rest_route( self::NS, '/financial/subscriptions', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'subscriptions' ),
		) ) );
		register_rest_route( self::NS, '/financial/subscriptions', array_merge( $fin, array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'subscriptions_create' ),
		) ) );

		register_rest_route( self::NS, '/financial/premium-listings', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'premium_listings' ),
		) ) );

		register_rest_route( self::NS, '/financial/events/(?P<id>\d+)/budget', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'event_budget' ),
		) ) );
		register_rest_route( self::NS, '/financial/forecast', array_merge( $org, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'cashflow_forecast' ),
		) ) );
		register_rest_route( self::NS, '/financial/tax-center', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'tax_center' ),
		) ) );
		register_rest_route( self::NS, '/financial/tax-center/export', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'tax_center_export' ),
		) ) );
		register_rest_route( self::NS, '/financial/tax-center/w9/pdf', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'tax_center_w9_pdf' ),
		) ) );
		register_rest_route( self::NS, '/financial/tax-center/1099/pdf', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'tax_center_1099_pdf' ),
		) ) );

		register_rest_route( self::NS, '/financial/refunds/calculate', array_merge( $fin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'refunds_calculate' ),
		) ) );
		register_rest_route( self::NS, '/financial/approvals', array_merge( $admin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'approvals_list' ),
		) ) );
		register_rest_route( self::NS, '/financial/approvals/(?P<id>\d+)/approve', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'approvals_approve' ),
		) ) );
		register_rest_route( self::NS, '/financial/approvals/(?P<id>\d+)/reject', array_merge( $admin, array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'approvals_reject' ),
		) ) );

		register_rest_route( self::NS, '/financial/insurance-offers', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'insurance_offers' ),
		) ) );
		register_rest_route( self::NS, '/financial/financing-offers', array_merge( $fin, array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'financing_offers' ),
		) ) );
	}

	/**
	 * Resolve financial API role from explicit request or user capabilities.
	 *
	 * @param WP_REST_Request|null $request REST request.
	 */
	private static function resolve_financial_role( $request = null ) {
		$requested = '';
		if ( $request instanceof WP_REST_Request ) {
			$requested = sanitize_key( (string) $request->get_param( 'role' ) );
		}
		$uid = get_current_user_id();
		if ( 'vendor' === $requested && ( LNKup_Roles::is_vendor( $uid ) || LNKup_Capabilities::user_has_manage_all() ) ) {
			return 'vendor';
		}
		if ( 'organizer' === $requested && ( LNKup_Roles::is_organizer( $uid ) || LNKup_Capabilities::user_has_manage_all() ) ) {
			return 'organizer';
		}
		return LNKup_Roles::is_organizer( $uid ) ? 'organizer' : 'vendor';
	}

	public static function vendor_permission() {
		return is_user_logged_in() && ( LNKup_Roles::is_vendor() || LNKup_Capabilities::user_has_manage_all() );
	}

	/**
	 * Receipt page: the logged-in vendor, or a signed link (apps open it in a browser with no login).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function receipt_document_permission( $request ) {
		return self::signed_document_permission( 'receipt', $request ) || self::vendor_permission();
	}

	/**
	 * Invoice document: a logged-in party, or a signed link.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function invoice_document_permission( $request ) {
		return self::signed_document_permission( 'invoice', $request ) || self::financial_permission();
	}

	/**
	 * A valid signature runs this one request as the user it was issued to; the callback's ownership
	 * checks still apply to that user.
	 *
	 * @param string          $type    receipt|invoice.
	 * @param WP_REST_Request $request Request.
	 */
	private static function signed_document_permission( $type, $request ) {
		if ( is_user_logged_in() || ! class_exists( 'LNKup_Signed_Links' ) ) {
			return false;
		}
		$user_id = LNKup_Signed_Links::verified_user( $type, absint( $request['id'] ), $request );
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return false;
		}
		wp_set_current_user( $user_id );
		return true;
	}

	public static function organizer_permission() {
		return is_user_logged_in() && ( LNKup_Roles::is_organizer() || LNKup_Capabilities::user_has_manage_all() );
	}

	public static function admin_permission() {
		return LNKup_Capabilities::user_has_manage_all();
	}

	public static function financial_permission() {
		return is_user_logged_in() && (
			LNKup_Roles::is_organizer() ||
			LNKup_Roles::is_vendor() ||
			current_user_can( 'lnkup_view_own_financials' ) ||
			LNKup_Capabilities::user_has_manage_all()
		);
	}

	/**
	 * Wrap REST callbacks with try/catch for consistent JSON errors.
	 *
	 * @param callable $callback Handler returning data or WP_Error.
	 */
	private static function respond( $callback ) {
		try {
			$result = $callback();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( self::sanitize_rest_data( $result ) );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup financial REST: ' . $e->getMessage() );
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				return new WP_Error( 'financial_error', $e->getMessage(), array( 'status' => 500 ) );
			}
			return new WP_Error( 'financial_error', __( 'Financial data unavailable.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * Ensure REST payloads are JSON-serializable (UTF-8 safe scalars and arrays).
	 *
	 * @param mixed $data Response data.
	 */
	private static function sanitize_rest_data( $data ) {
		if ( is_array( $data ) ) {
			$clean = array();
			foreach ( $data as $key => $value ) {
				$clean[ is_string( $key ) ? $key : (string) $key ] = self::sanitize_rest_data( $value );
			}
			return $clean;
		}
		if ( is_string( $data ) ) {
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$converted = mb_convert_encoding( $data, 'UTF-8', 'UTF-8' );
				return is_string( $converted ) ? $converted : $data;
			}
			return wp_check_invalid_utf8( $data );
		}
		if ( is_float( $data ) ) {
			return is_finite( $data ) ? $data : 0.0;
		}
		if ( is_int( $data ) || is_bool( $data ) || null === $data ) {
			return $data;
		}
		return null;
	}

	/**
	 * Zero-state organizer dashboard payload.
	 */
	private static function organizer_zero_dashboard() {
		$uid = get_current_user_id();
		$balance_payload = class_exists( 'LNKup_Financial_Service' )
			? LNKup_Financial_Service::organizer_balance_payload( $uid )
			: array(
				'wallet'           => array(),
				'stripe_balance'   => array( 'available' => 0, 'pending' => 0, 'synced' => false ),
				'display_balances' => array(),
				'connect'          => array( 'status' => 'not_started', 'settlement_mode' => 'destination' ),
			);
		$wallet  = $balance_payload['wallet'] ?? array();
		$display = $balance_payload['display_balances'] ?? array();
		$connect = $balance_payload['connect'] ?? array(
			'enabled'    => false,
			'status'     => 'not_started',
			'settlement' => 'destination',
		);
		if ( empty( $connect['status'] ) && class_exists( 'LNKup_Stripe_Connect' ) ) {
			try {
				$status  = LNKup_Stripe_Connect::onboarding_status( $uid );
				$connect = array(
					'enabled'    => ( 'active' === ( $status['status'] ?? '' ) ),
					'status'     => $status['status'] ?? 'not_started',
					'settlement' => $status['settlement_mode'] ?? 'destination',
				);
			} catch ( Throwable $e ) {
				// Keep default connect stub.
			}
		}
		return array(
			'vendor_revenue'      => 0,
			'gross_revenue'       => 0,
			'available_balance'   => (float) ( $display['available'] ?? 0 ),
			'pending_balance'     => (float) ( $display['pending'] ?? 0 ),
			'wallet_pending'      => (float) ( $display['pending'] ?? 0 ),
			'processing_balance'  => (float) ( $display['processing'] ?? 0 ),
			'reserve_balance'     => (float) ( $display['reserve_hold'] ?? 0 ),
			'reserve_hold'        => (float) ( $display['reserve_hold'] ?? 0 ),
			'platform_fees'       => 0,
			'stripe_fees'         => 0,
			'payout_count'        => 0,
			'payouts_paid'        => 0,
			'refunds'             => 0,
			'chargebacks'         => 0,
			'collected'           => 0,
			'outstanding'         => 0,
			'pending_revenue'     => 0,
			'open_invoices'       => 0,
			'overdue_invoices'    => 0,
			'sponsor_revenue'     => 0,
			'recent_activity'     => array(),
			'connect_status'        => $connect,
			'connect'               => $connect,
			'wallet'                => $wallet,
			'display_balances'      => $display,
			'stripe_balance'        => $balance_payload['stripe_balance'] ?? array(),
			'charts'                => array(
				'revenue_by_month' => array(),
				'revenue_by_event' => array(),
			),
			'health_score'            => array(),
		);
	}

	/**
	 * Zero-state vendor dashboard payload.
	 */
	private static function vendor_zero_dashboard() {
		return array(
			'revenue_paid'   => 0,
			'outstanding'    => 0,
			'wallet_balance' => 0,
			'invoice_count'  => 0,
			'refund_count'   => 0,
			'money_spent'    => 0,
			'lifetime_spend' => 0,
			'refunds'        => 0,
			'charts'         => array(
				'monthly_spending' => array(),
			),
		);
	}

	public static function dashboard( $request = null ) {
		return self::respond( function () use ( $request ) {
			if ( LNKup_Capabilities::user_has_manage_all() && ! empty( $_GET['scope'] ) && 'admin' === $_GET['scope'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return LNKup_Financial_Service::admin_dashboard();
			}
			$role = self::resolve_financial_role( $request );
			if ( 'organizer' === $role ) {
				try {
					return LNKup_Financial_Service::organizer_dashboard();
				} catch ( Throwable $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( 'LNKup organizer dashboard: ' . $e->getMessage() );
					}
					try {
						return self::organizer_zero_dashboard();
					} catch ( Throwable $fallback_error ) {
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							error_log( 'LNKup organizer dashboard fallback: ' . $fallback_error->getMessage() );
						}
						return array(
							'gross_revenue'    => 0,
							'available_balance'=> 0,
							'pending_balance'  => 0,
							'platform_fees'    => 0,
							'payouts_paid'     => 0,
							'refunds'          => 0,
							'charts'           => array(
								'revenue_by_month' => array(),
								'revenue_by_event' => array(),
							),
						);
					}
				}
			}
			try {
				return LNKup_Financial_Service::vendor_dashboard();
			} catch ( Throwable $e ) {
				return self::vendor_zero_dashboard();
			}
		} );
	}

	public static function transactions( $request ) {
		$response = self::transactions_list( $request );
		if ( $response instanceof WP_REST_Response && ! $response->is_error() ) {
			$limit = min( 100, max( 1, absint( $request->get_param( 'limit' ) ?: 25 ) ) );
			$total = LNKup_Transactions::count_for_user( get_current_user_id(), self::resolve_financial_role( $request ) );
			$response->header( 'X-WP-Total', (string) $total );
			$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $limit ) ) );
		}
		return $response;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	private static function transactions_list( $request ) {
		return self::respond( function () use ( $request ) {
			$role  = self::resolve_financial_role( $request );
			$uid   = get_current_user_id();
			$limit = min( 100, max( 1, absint( $request->get_param( 'limit' ) ?: 25 ) ) );
			$page  = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
			$rows  = LNKup_Transactions::list_for_user( array(
				'user_id' => $uid,
				'role'    => $role,
				'limit'   => $limit,
				'offset'  => ( $page - 1 ) * $limit,
				'all'     => LNKup_Capabilities::user_has_manage_all() && $request->get_param( 'all' ),
			) );
			$rows = is_array( $rows ) ? $rows : array();
			return array_map( array( 'LNKup_Transactions', 'format' ), $rows );
		} );
	}

	/**
	 * Explicit "refresh from Stripe" for the signed-in organizer or vendor (rate limited).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function sync( $request ) {
		return self::respond( function () use ( $request ) {
			$uid      = get_current_user_id();
			$role     = self::resolve_financial_role( $request );
			$throttle = 'lnkup_fin_sync_' . $role . '_' . $uid;
			if ( get_transient( $throttle ) ) {
				return array(
					'throttled' => true,
					'synced_at' => (string) get_user_meta( $uid, '_lnkup_financial_synced_at', true ),
				);
			}
			set_transient( $throttle, 1, 30 );
			return 'organizer' === $role
				? LNKup_Financial_Service::sync_organizer( $uid )
				: LNKup_Financial_Service::sync_vendor( $uid );
		} );
	}

	public static function invoices( $request = null ) {
		return self::respond( function () use ( $request ) {
			$role = self::resolve_financial_role( $request );
			$rows = LNKup_Invoices::list_for_user( get_current_user_id(), $role );
			$rows = is_array( $rows ) ? $rows : array();
			return array_map( array( 'LNKup_Invoices', 'format' ), $rows );
		} );
	}

	public static function receipts() {
		return self::respond( function () {
			$rows = LNKup_Receipts::list_for_user( get_current_user_id() );
			$rows = is_array( $rows ) ? $rows : array();
			return array_map( array( 'LNKup_Receipts', 'format' ), $rows );
		} );
	}

	public static function wallet( $request = null ) {
		return self::respond( function () use ( $request ) {
			$role = self::resolve_financial_role( $request );
			$uid  = get_current_user_id();
			return array(
				'balance'  => LNKup_Wallet::get_balance( $uid, $role ),
				'entries'  => LNKup_Wallet::entries( $uid, $role ) ?: array(),
				'credits'  => LNKup_Credits::list_for_user( $uid ) ?: array(),
			);
		} );
	}

	public static function refunds_list( $request = null ) {
		return self::respond( function () use ( $request ) {
			$role = self::resolve_financial_role( $request );
			$rows = LNKup_Refunds::list_for_user( get_current_user_id(), $role );
			$rows = is_array( $rows ) ? $rows : array();
			return array_map( array( 'LNKup_Refunds', 'format' ), $rows );
		} );
	}

	public static function refunds_create( $request ) {
		$params     = $request->get_json_params();
		$payment_id = absint( $params['payment_id'] ?? 0 );
		if ( ! self::can_access_payment( $payment_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$id = LNKup_Refunds::request(
			$payment_id,
			(float) ( $params['amount'] ?? 0 ),
			$params['reason'] ?? '',
			$params['refund_type'] ?? 'full'
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response( array( 'id' => $id ) );
	}

	public static function refunds_approve( $request ) {
		$refund_id = absint( $request['id'] );
		if ( ! self::can_organizer_access_refund( $refund_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$result = LNKup_Refunds::approve( $refund_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function payouts() {
		$rows = LNKup_Payouts::list_for_organizer( get_current_user_id() );
		$rows = is_array( $rows ) ? $rows : array();
		return rest_ensure_response( array_map( array( 'LNKup_Payouts', 'format' ), $rows ) );
	}

	public static function disputes_list( $request = null ) {
		$role = self::resolve_financial_role( $request );
		$rows = LNKup_Financial_Disputes::list_for_user( get_current_user_id(), $role );
		$rows = is_array( $rows ) ? $rows : array();
		return rest_ensure_response( array_values( array_filter( array_map( array( 'LNKup_Financial_Disputes', 'format' ), $rows ) ) ) );
	}

	public static function disputes_create( $request ) {
		$params     = $request->get_json_params();
		$payment_id = absint( $params['payment_id'] ?? $params['payment_post_id'] ?? 0 );
		$reason     = (string) ( $params['reason'] ?? '' );

		if ( ! self::can_vendor_open_dispute( $payment_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		$id = LNKup_Financial_Disputes::open( $payment_id, $reason, get_current_user_id() );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$row = LNKup_Financial_Disputes::get( (int) $id );
		return rest_ensure_response(
			array(
				'id'      => (int) $id,
				'dispute' => LNKup_Financial_Disputes::format( $row ),
			)
		);
	}

	public static function disputes_respond( $request ) {
		$dispute_id = absint( $request['id'] );
		if ( ! self::can_access_dispute( $dispute_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$params = $request->get_json_params();
		$result = LNKup_Financial_Disputes::respond( $dispute_id, $params['evidence'] ?? '', get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$row = LNKup_Financial_Disputes::get( $dispute_id );
		return rest_ensure_response(
			array(
				'success' => true,
				'dispute' => LNKup_Financial_Disputes::format( $row ),
			)
		);
	}

	public static function disputes_resolve( $request ) {
		$dispute_id = absint( $request['id'] );
		if ( ! self::can_organizer_access_dispute( $dispute_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$params  = $request->get_json_params();
		$outcome = sanitize_key( (string) ( $params['outcome'] ?? '' ) );
		$note    = (string) ( $params['note'] ?? '' );
		$result  = LNKup_Financial_Disputes::resolve( $dispute_id, $outcome, $note, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$row = LNKup_Financial_Disputes::get( $dispute_id );
		return rest_ensure_response(
			array(
				'success' => true,
				'dispute' => LNKup_Financial_Disputes::format( $row ),
			)
		);
	}

	public static function installments() {
		return rest_ensure_response( LNKup_Installments::upcoming_for_vendor( get_current_user_id() ) );
	}

	public static function payment_methods() {
		return rest_ensure_response( LNKup_Payment_Methods::list_for_user( get_current_user_id() ) );
	}

	public static function promo_validate( $request ) {
		$params = $request->get_json_params();
		$promo  = LNKup_Promo_Codes::validate( $params['code'] ?? '', get_current_user_id(), $params['context'] ?? array() );
		if ( is_wp_error( $promo ) ) {
			return $promo;
		}
		return rest_ensure_response( $promo );
	}

	public static function reports( $request ) {
		return self::respond( function () use ( $request ) {
			$type = $request['type'];
			$uid  = get_current_user_id();
			if ( LNKup_Capabilities::user_has_manage_all() && 'admin' === $type ) {
				return LNKup_Financial_Reports::marketplace_summary();
			}
			if ( 'organizer' === self::resolve_financial_role( $request ) && 'vendor' !== $type ) {
				return LNKup_Financial_Reports::organizer_report( $uid );
			}
			if ( 'chart' === $type ) {
				return LNKup_Financial_Reports::chart_revenue_over_time();
			}
			return LNKup_Financial_Reports::vendor_report( $uid );
		} );
	}

	public static function event_financials( $request ) {
		$event_id = absint( $request['id'] );
		if ( ! LNKup_Capabilities::can_manage_event_financials( $event_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( LNKup_Financial::event_financials( $event_id ) );
	}

	public static function tax_profile() {
		return rest_ensure_response( LNKup_Tax_Profiles::format( LNKup_Tax_Profiles::get_or_create( get_current_user_id() ) ) );
	}

	public static function admin_summary() {
		return rest_ensure_response( LNKup_Financial_Service::admin_dashboard() );
	}

	public static function checkout_summary( $request ) {
		$payment_id = absint( $request['payment_id'] );
		if ( ! self::can_access_payment( $payment_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$result = LNKup_Financial_Service::get_checkout_summary( $payment_id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function checkout_prepare( $request ) {
		$payment_id = absint( $request['payment_id'] );
		if ( ! self::can_access_payment( $payment_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$params = $request->get_json_params() ?: array();
		$result = LNKup_Financial_Service::prepare_checkout( $payment_id, $params );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function checkout_confirm( $request ) {
		$payment_id = absint( $request['payment_id'] );
		if ( ! self::can_access_payment( $payment_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$params = $request->get_json_params() ?: array();
		$result = LNKup_Financial_Service::confirm_checkout( $payment_id, $params['intent_id'] ?? '' );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function checkout_apply_credits( $request ) {
		$payment_id = absint( $request['payment_id'] );
		if ( ! self::can_access_payment( $payment_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$params = $request->get_json_params() ?: array();
		$result = LNKup_Financial_Service::apply_wallet_credits( $payment_id, (float) ( $params['amount'] ?? 0 ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Safe redirect checkout URL for headless vendor app (no client_secret).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function checkout_redirect_url( $request ) {
		$payment_id = absint( $request['payment_id'] );
		if ( ! self::can_access_payment( $payment_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		$pay_status = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( 'paid' === $pay_status ) {
			return rest_ensure_response(
				array(
					'status'     => 'already_paid',
					'payment_id' => $payment_id,
				)
			);
		}

		$params = $request->get_json_params() ?: array();
		$checkout_args = array( 'mode' => 'redirect' );
		if ( ! empty( $params['return_to'] ) && 'native' === $params['return_to'] ) {
			$checkout_args['return_to']      = 'native';
			$checkout_args['application_id'] = isset( $params['application_id'] ) ? absint( $params['application_id'] ) : 0;
		} elseif ( ! empty( $params['return_to'] ) && 'app' === $params['return_to'] ) {
			$checkout_args['return_to']      = 'app';
			$checkout_args['app_base_url']   = isset( $params['app_base_url'] ) ? (string) $params['app_base_url'] : '';
			$checkout_args['application_id'] = isset( $params['application_id'] ) ? absint( $params['application_id'] ) : 0;
		}

		$result = LNKup_Financial_Service::create_checkout( $payment_id, $checkout_args );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'status'     => 'unavailable',
					'payment_id' => $payment_id,
					'message'    => $result->get_error_message(),
				)
			);
		}

		if ( is_array( $result ) && ! empty( $result['paid'] ) ) {
			return rest_ensure_response(
				array(
					'status'     => 'already_paid',
					'payment_id' => $payment_id,
				)
			);
		}

		$url = is_array( $result ) ? (string) ( $result['url'] ?? '' ) : '';
		if ( ! $url ) {
			return rest_ensure_response(
				array(
					'status'     => 'unavailable',
					'payment_id' => $payment_id,
				)
			);
		}

		return rest_ensure_response(
			array(
				'status'       => 'ready',
				'checkout_url' => $url,
				'payment_id'   => $payment_id,
			)
		);
	}

	/**
	 * Redirect Stripe Checkout success/cancel back to lnkup://stripe-redirect.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function checkout_native_return( $request ) {
		$state = (string) $request->get_param( 'payment' );
		if ( ! in_array( $state, array( 'success', 'cancelled' ), true ) ) {
			$state = 'cancelled';
		}
		$query = array( 'payment' => $state );
		$app   = absint( $request->get_param( 'application_id' ) );
		if ( $app ) {
			$query['applicationId'] = $app;
		}
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', 'lnkup://stripe-redirect?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function activity( $request ) {
		return self::respond( function () use ( $request ) {
			$role = self::resolve_financial_role( $request );
			$rows = LNKup_Financial_Service::get_activity( get_current_user_id(), $role, $request->get_param( 'limit' ) ?: 25 );
			return is_array( $rows ) ? $rows : array();
		} );
	}

	public static function payment_timeline( $request ) {
		$payment_id = absint( $request['payment_id'] );
		if ( ! self::can_access_payment( $payment_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( LNKup_Financial_Service::get_timeline( $payment_id ) );
	}

	public static function payment_audit( $request ) {
		$payment_id = absint( $request['payment_id'] );
		if ( ! self::can_access_payment( $payment_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$audit = LNKup_Payments::get_audit( $payment_id, true );
		if ( ! $audit ) {
			return new WP_Error( 'not_found', __( 'Payment not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $audit );
	}

	public static function payment_reconcile( $request ) {
		$payment_id = absint( $request['payment_id'] );
		if ( ! self::can_access_payment( $payment_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$params    = $request->get_json_params() ?: array();
		$intent_id = sanitize_text_field( (string) ( $params['intent_id'] ?? '' ) );
		$result    = LNKup_Financial_Service::reconcile_payment( $payment_id, $intent_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function payment_methods_setup() {
		$result = LNKup_Payment_Provider::get_instance()->create_setup_intent( get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function payment_methods_delete( $request ) {
		$id = absint( $request['id'] );
		LNKup_Payment_Provider::get_instance()->detach_payment_method( $id, get_current_user_id() );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function payment_methods_patch( $request ) {
		$params = $request->get_json_params() ?: array();
		$id     = absint( $request['id'] );
		if ( ! empty( $params['is_default'] ) ) {
			LNKup_Payment_Methods::set_default( $id, get_current_user_id() );
		}
		if ( isset( $params['label'] ) ) {
			LNKup_Payment_Methods::rename( $id, get_current_user_id(), $params['label'] );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function invoice_detail( $request ) {
		$invoice = LNKup_Invoices::get( absint( $request['id'] ) );
		if ( ! $invoice || ! self::can_access_invoice( $invoice ) ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( LNKup_Invoices::format_detail( $invoice ) );
	}

	public static function invoice_pdf( $request ) {
		$invoice = LNKup_Invoices::get( absint( $request['id'] ) );
		if ( ! $invoice || ! self::can_access_invoice( $invoice ) ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return self::serve_html( LNKup_Invoices::render_html( $invoice ) );
	}

	/**
	 * Send an HTML document instead of a JSON-encoded string.
	 *
	 * @param string $html Document markup.
	 * @return WP_REST_Response
	 */
	private static function serve_html( $html ) {
		add_filter(
			'rest_pre_serve_request',
			static function () use ( $html ) {
				header( 'Content-Type: text/html; charset=UTF-8' );
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return true;
			}
		);
		return new WP_REST_Response( null, 200 );
	}

	public static function invoice_email( $request ) {
		$invoice = LNKup_Invoices::get( absint( $request['id'] ) );
		if ( ! $invoice || ! self::can_access_invoice( $invoice ) ) {
			return new WP_Error( 'not_found', __( 'Invoice not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$sent = LNKup_Invoices::email_invoice( $invoice );
		return rest_ensure_response( array( 'sent' => (bool) $sent ) );
	}

	public static function receipt_view( $request ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . LNKup_Financial_Tables::receipts_table() . ' WHERE id = %d', absint( $request['id'] ) ),
			ARRAY_A
		);
		if ( ! $row || ( (int) $row['vendor_user_id'] !== get_current_user_id() && ! LNKup_Capabilities::user_has_manage_all() ) ) {
			return new WP_Error( 'not_found', __( 'Receipt not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return self::serve_html( LNKup_Receipts::render_html( $row ) );
	}

	public static function receipt_detail( $request ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . LNKup_Financial_Tables::receipts_table() . ' WHERE id = %d', absint( $request['id'] ) ),
			ARRAY_A
		);
		if ( ! $row || (int) $row['vendor_user_id'] !== get_current_user_id() && ! LNKup_Capabilities::user_has_manage_all() ) {
			return new WP_Error( 'not_found', __( 'Receipt not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( LNKup_Receipts::format( $row ) );
	}

	public static function refunds_deny( $request ) {
		$refund_id = absint( $request['id'] );
		if ( ! self::can_organizer_access_refund( $refund_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$result = LNKup_Refunds::deny( $refund_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function reports_export( $request ) {
		$format = $request['format'];
		$role   = self::resolve_financial_role( $request );
		$data   = 'organizer' === $role ? LNKup_Financial_Reports::organizer_report( get_current_user_id() ) : LNKup_Financial_Reports::vendor_report( get_current_user_id() );
		if ( 'csv' === $format ) {
			return rest_ensure_response( array( 'csv' => LNKup_Financial_Reports::to_csv( $data ) ) );
		}
		return rest_ensure_response( array( 'html' => LNKup_Financial_Reports::to_pdf_html( $data ) ) );
	}

	/**
	 * @param int $payment_id Payment ID.
	 */
	private static function can_access_payment( $payment_id ) {
		if ( LNKup_Capabilities::user_has_manage_all() ) {
			return true;
		}
		$uid          = get_current_user_id();
		$vendor_id    = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		return $uid === $vendor_id || $uid === $organizer_id;
	}

	/**
	 * @param int $refund_id Refund row ID.
	 */
	private static function can_organizer_access_refund( $refund_id ) {
		if ( LNKup_Capabilities::user_has_manage_all() ) {
			return true;
		}
		global $wpdb;
		$table = LNKup_Financial_Tables::refunds_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return false;
		}
		$organizer_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT organizer_user_id FROM {$table} WHERE id = %d", $refund_id )
		);
		return $organizer_id && get_current_user_id() === $organizer_id;
	}

	/**
	 * Vendor (or admin) may open a mediation dispute on their paid payment.
	 *
	 * @param int $payment_id Payment CPT ID.
	 */
	private static function can_vendor_open_dispute( $payment_id ) {
		$payment_id = absint( $payment_id );
		if ( $payment_id <= 0 ) {
			return false;
		}
		if ( LNKup_Capabilities::user_has_manage_all() ) {
			return true;
		}
		$uid       = get_current_user_id();
		$vendor_id = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		return $uid > 0 && $uid === $vendor_id;
	}

	/**
	 * Vendor or organizer party on the dispute row.
	 *
	 * @param int $dispute_id Dispute row ID.
	 */
	private static function can_access_dispute( $dispute_id ) {
		if ( LNKup_Capabilities::user_has_manage_all() ) {
			return true;
		}
		$row = LNKup_Financial_Disputes::get( absint( $dispute_id ) );
		if ( ! $row ) {
			return false;
		}
		$uid = get_current_user_id();
		return $uid === (int) $row['vendor_user_id'] || $uid === (int) $row['organizer_user_id'];
	}

	/**
	 * Organizer (or admin) may resolve a dispute.
	 *
	 * @param int $dispute_id Dispute row ID.
	 */
	private static function can_organizer_access_dispute( $dispute_id ) {
		if ( LNKup_Capabilities::user_has_manage_all() ) {
			return true;
		}
		$row = LNKup_Financial_Disputes::get( absint( $dispute_id ) );
		if ( ! $row ) {
			return false;
		}
		return get_current_user_id() === (int) $row['organizer_user_id'];
	}

	/**
	 * @param array $invoice Invoice row.
	 */
	private static function can_access_invoice( $invoice ) {
		if ( LNKup_Capabilities::user_has_manage_all() ) {
			return true;
		}
		$uid = get_current_user_id();
		return $uid === (int) $invoice['vendor_user_id'] || $uid === (int) $invoice['organizer_user_id'];
	}

	public static function organizer_balances() {
		return self::respond( function () {
			$payload = LNKup_Financial_Service::organizer_balance_payload( get_current_user_id() );
			return array_merge(
				$payload,
				array(
					'balances' => $payload['wallet'],
					'money'    => LNKup_Financial_Service::organizer_money_contract( get_current_user_id(), $payload ),
				)
			);
		} );
	}

	public static function organizer_wallet() {
		return self::respond( function () {
			return LNKup_Financial_Service::organizer_wallet( get_current_user_id() );
		} );
	}

	public static function organizer_wallet_entries( $request = null ) {
		$uid      = get_current_user_id();
		$per_page = $request instanceof WP_REST_Request ? min( 100, max( 1, absint( $request->get_param( 'per_page' ) ?: 50 ) ) ) : 50;
		$page     = $request instanceof WP_REST_Request ? max( 1, absint( $request->get_param( 'page' ) ?: 1 ) ) : 1;
		$total    = LNKup_Organizer_Wallet::count_entries( $uid );
		$response = rest_ensure_response( LNKup_Organizer_Wallet::list_entries( $uid, $per_page, ( $page - 1 ) * $per_page ) );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );
		return $response;
	}

	public static function organizer_cashout( $request ) {
		$params  = $request->get_json_params() ?: array();
		$user_id = get_current_user_id();
		$amount  = (float) ( $params['amount'] ?? 0 );
		$result  = LNKup_Financial_Service::request_organizer_cashout(
			$user_id,
			$amount,
			$params['type'] ?? 'standard',
			sanitize_text_field( (string) ( $params['client_token'] ?? '' ) )
		);
		if ( is_wp_error( $result ) ) {
			$code    = $result->get_error_code() ? $result->get_error_code() : 'cashout_failed';
			$message = trim( (string) $result->get_error_message() );
			if ( '' === $message ) {
				$message = __( 'Cashout could not be completed. Please try again or contact support.', 'lnkup-marketplace' );
			}
			$data = $result->get_error_data();
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			if ( empty( $data['status'] ) ) {
				$data['status'] = 400;
			}
			$result = new WP_Error( $code, $message, $data );

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[LNKup] organizer_cashout_failed user=%d code=%s message=%s amount=%s',
					$user_id,
					$code,
					$message,
					(string) $amount
				)
			);
			LNKup_Financial_Audit::log(
				'organizer_cashout_failed',
				'user',
				$user_id,
				array(
					'amount'   => $amount,
					'metadata' => array(
						'code'    => $code,
						'message' => $message,
						'type'    => sanitize_key( (string) ( $params['type'] ?? 'standard' ) ),
					),
				)
			);
			return $result;
		}
		return rest_ensure_response( array( 'payout_id' => $result ) );
	}

	public static function organizer_payouts() {
		return self::respond( function () {
			$rows = LNKup_Organizer_Payouts::list_for_user( get_current_user_id() );
			$rows = is_array( $rows ) ? $rows : array();
			return array_map( array( 'LNKup_Organizer_Payouts', 'format' ), $rows );
		} );
	}

	public static function organizer_payout_cancel( $request ) {
		$result = LNKup_Organizer_Payouts::cancel( absint( $request['id'] ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'success' => true ) );
	}

	public static function connect_onboarding( $request ) {
		return self::respond( function () use ( $request ) {
			$params = $request instanceof WP_REST_Request ? $request->get_json_params() : array();
			if ( ! is_array( $params ) ) {
				$params = array();
			}
			return LNKup_Stripe_Connect::create_onboarding_link(
				get_current_user_id(),
				array(
					'return_url'  => isset( $params['return_url'] ) ? (string) $params['return_url'] : '',
					'refresh_url' => isset( $params['refresh_url'] ) ? (string) $params['refresh_url'] : '',
				)
			);
		} );
	}

	public static function connect_login_link() {
		return self::respond( function () {
			return LNKup_Stripe_Connect::create_login_link( get_current_user_id() );
		} );
	}

	public static function connect_status() {
		return self::respond( function () {
			return LNKup_Stripe_Connect::onboarding_status( get_current_user_id() );
		} );
	}

	public static function connect_refresh() {
		return self::respond( function () {
			$result = LNKup_Stripe_Connect::sync_account_from_stripe( get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return LNKup_Stripe_Connect::onboarding_status( get_current_user_id() );
		} );
	}

	public static function connect_deauthorize() {
		return self::respond( function () {
			return LNKup_Stripe_Connect::deauthorize_account( get_current_user_id() );
		} );
	}

	public static function referrals_list() {
		return rest_ensure_response( array(
			'code'      => LNKup_Referral_Credits::get_or_create_code( get_current_user_id() ),
			'referrals' => LNKup_Referral_Credits::list_for_user( get_current_user_id() ),
			'rewards'   => LNKup_Referral_Credits::list_rewards( get_current_user_id() ),
		) );
	}

	public static function referrals_code() {
		return rest_ensure_response( LNKup_Referral_Credits::get_or_create_code( get_current_user_id() ) );
	}

	public static function credits_apply( $request ) {
		$params     = $request->get_json_params() ?: array();
		$payment_id = absint( $params['payment_id'] ?? 0 );
		if ( ! $payment_id || (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ) !== get_current_user_id() ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$result = LNKup_Financial_Service::apply_wallet_credits( $payment_id, (float) ( $params['amount'] ?? 0 ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function sponsor_invoices() {
		return rest_ensure_response( LNKup_Sponsor_Payments::list_invoices( get_current_user_id() ) );
	}

	public static function sponsor_invoices_create( $request ) {
		$params = $request->get_json_params() ?: array();
		$event_id = absint( $params['event_id'] ?? 0 );
		if ( $event_id && ! LNKup_Capabilities::can_manage_event_financials( $event_id ) ) {
			return new WP_Error( 'forbidden', __( 'You can only invoice sponsors for your own events.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( (float) ( $params['total_amount'] ?? 0 ) <= 0 ) {
			return new WP_Error( 'invalid_amount', __( 'Enter an invoice amount greater than zero.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$params['organizer_user_id'] = get_current_user_id();
		$id = LNKup_Sponsor_Payments::create_invoice( $params );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response( array( 'id' => $id ) );
	}

	public static function subscriptions() {
		$uid = get_current_user_id();
		return rest_ensure_response( array(
			'plans'        => LNKup_Subscriptions::list_plans( LNKup_Roles::is_organizer( $uid ) ? 'organizer' : 'vendor' ),
			'active'       => LNKup_Subscriptions::get_active( $uid ),
		) );
	}

	public static function subscriptions_create( $request ) {
		$params = $request->get_json_params() ?: array();
		$result = LNKup_Subscriptions::subscribe( get_current_user_id(), absint( $params['plan_id'] ?? 0 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_array( $result ) ) {
			return rest_ensure_response( $result );
		}
		return rest_ensure_response( array( 'id' => $result ) );
	}

	public static function premium_listings() {
		return rest_ensure_response( LNKup_Premium_Listings::list_for_user( get_current_user_id() ) );
	}

	public static function event_budget( $request ) {
		$event_id = absint( $request['id'] );
		if ( ! LNKup_Capabilities::can_manage_event_financials( $event_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( LNKup_Budget_Center::get_with_variance( $event_id ) );
	}

	/**
	 * Zero-state cashflow forecast payload.
	 */
	private static function forecast_zero_state() {
		return array(
			'next_7_days'              => 0,
			'next_30_days'             => 0,
			'expected_payouts'         => 0,
			'overdue_invoices'         => 0,
			'high_risk_invoices'       => array(),
			'projected_available_cash' => 0,
			'confidence'               => 'low',
			'message'                  => __( 'Forecasts will appear after vendor invoices and payments are created.', 'lnkup-marketplace' ),
			'horizon_days'             => 30,
			'expected_inflows'         => 0,
			'outstanding'              => 0,
			'projected_payout'         => 0,
		);
	}

	public static function cashflow_forecast( $request ) {
		$days = absint( $request->get_param( 'days' ) ?: 30 );
		return self::respond( function () use ( $days ) {
			try {
				return LNKup_Budget_Center::forecast( get_current_user_id(), $days );
			} catch ( Throwable $e ) {
				return self::forecast_zero_state();
			}
		} );
	}

	public static function tax_center() {
		return rest_ensure_response( LNKup_Tax_Center::get_summary( get_current_user_id() ) );
	}

	public static function tax_center_export( $request ) {
		$year = absint( $request->get_param( 'year' ) ?: gmdate( 'Y' ) );
		return rest_ensure_response( array( 'csv' => LNKup_Tax_Center::export_csv( get_current_user_id(), $year ) ) );
	}

	public static function tax_center_w9_pdf() {
		$result = LNKup_Tax_Center::generate_w9_pdf( get_current_user_id() );
		return is_wp_error( $result ) ? $result : self::serve_pdf( $result );
	}

	public static function tax_center_1099_pdf( $request ) {
		$year   = absint( $request->get_param( 'year' ) ?: gmdate( 'Y' ) );
		$result = LNKup_Tax_Center::generate_1099_pdf( get_current_user_id(), $year );
		return is_wp_error( $result ) ? $result : self::serve_pdf( $result );
	}

	/**
	 * Stream a generated PDF through REST.
	 *
	 * @param array $result { binary, filename }.
	 * @return WP_REST_Response
	 */
	private static function serve_pdf( $result ) {
		$binary   = (string) ( $result['binary'] ?? '' );
		$filename = sanitize_file_name( $result['filename'] ?? 'lnkup-tax.pdf' );
		add_filter(
			'rest_pre_serve_request',
			static function ( $served ) use ( $binary, $filename ) {
				header( 'Content-Type: application/pdf' );
				header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
				header( 'Content-Length: ' . strlen( $binary ) );
				echo $binary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return true;
			}
		);
		return new WP_REST_Response( null, 200 );
	}

	public static function refunds_calculate( $request ) {
		$params     = $request->get_json_params() ?: array();
		$payment_id = absint( $params['payment_id'] ?? 0 );
		if ( ! $payment_id || 'lnkup_payment' !== get_post_type( $payment_id ) ) {
			return new WP_Error( 'invalid_payment', __( 'Payment not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_access_payment( $payment_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$result = LNKup_Refund_Rules::calculate(
			$payment_id,
			sanitize_key( (string) ( $params['refund_type'] ?? 'full' ) ),
			(float) ( $params['amount'] ?? 0 )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function approvals_list() {
		return rest_ensure_response( LNKup_Approval_Workflows::list_pending() );
	}

	public static function approvals_approve( $request ) {
		$ok = LNKup_Approval_Workflows::approve( absint( $request['id'] ), get_current_user_id() );
		return rest_ensure_response( array( 'success' => (bool) $ok ) );
	}

	public static function approvals_reject( $request ) {
		$params = $request->get_json_params() ?: array();
		LNKup_Approval_Workflows::reject( absint( $request['id'] ), get_current_user_id(), $params['note'] ?? '' );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function insurance_offers() {
		return rest_ensure_response( LNKup_Insurance_Marketplace::list_offers() );
	}

	public static function financing_offers() {
		return rest_ensure_response( LNKup_Financing::list_offers() );
	}
}
