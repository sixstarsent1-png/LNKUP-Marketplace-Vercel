<?php
/**
 * Frontend shortcodes.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Shortcodes {

	/**
	 * Init shortcodes.
	 */
	public static function init() {
		$map = array(
			'lnkup_vendor_dashboard'     => 'render_vendor_dashboard',
			'lnkup_organizer_dashboard'    => 'render_organizer_dashboard',
			'lnkup_opportunities_feed'   => 'render_opportunities_feed',
			'lnkup_vendor_profile_form'  => 'render_vendor_profile_form',
			'lnkup_organizer_events'     => 'render_organizer_events',
			'lnkup_applications_manager' => 'render_applications_manager',
			'lnkup_documents_manager'    => 'render_documents_manager',
			'lnkup_payments_dashboard'   => 'render_payments_dashboard',
			'lnkup_favorites'            => 'render_favorites',
			'lnkup_vendor_public_profile'=> 'render_vendor_public_profile',
			'lnkup_connector_dashboard'  => 'render_connector_dashboard',
			'lnkup_connector_interest_form' => 'render_connector_interest_form',
			'lnkup_external_claim_landing'  => 'render_external_claim_landing',
		);
		add_action( 'template_redirect', array( __CLASS__, 'handle_invoice_verify' ) );
		foreach ( $map as $code => $method ) {
			add_shortcode( $code, array( __CLASS__, $method ) );
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'preserve_dashboard_view_param' ), 10, 2 );
	}

	/**
	 * Whether the current front-end page is a dashboard shell page.
	 */
	private static function is_dashboard_front_page() {
		if ( ! is_page() ) {
			return false;
		}
		$slug = get_post_field( 'post_name', get_queried_object_id() );
		return in_array(
			$slug,
			array( 'vendor-dashboard', 'organizer-dashboard', 'connector-dashboard', 'opportunities', 'vendor-profile', 'favorites' ),
			true
		);
	}

	/**
	 * Prevent canonical redirects from stripping ?view= on vendor dashboard.
	 *
	 * @param string $redirect_url  Redirect URL.
	 * @param string $requested_url Requested URL.
	 */
	public static function preserve_dashboard_view_param( $redirect_url, $requested_url ) {
		unset( $requested_url );
		if ( is_page( 'vendor-dashboard' ) && isset( $_GET['view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		if ( is_page( 'connector-dashboard' ) && isset( $_GET['view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Public invoice verification via QR token.
	 */
	public static function handle_invoice_verify() {
		if ( empty( $_GET['lnkup_verify_invoice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token   = sanitize_text_field( wp_unslash( $_GET['lnkup_verify_invoice'] ) );
		$invoice = LNKup_Invoices::verify_by_token( $token );
		if ( ! $invoice ) {
			wp_die( esc_html__( 'Invalid or expired invoice verification link.', 'lnkup-marketplace' ), 404 );
		}
		wp_die( LNKup_Invoices::render_html( $invoice ), esc_html( $invoice['invoice_number'] ), array( 'response' => 200 ) );
	}

	/**
	 * Enqueue plugin frontend assets.
	 */
	public static function enqueue_assets() {
		$is_dashboard = self::is_dashboard_front_page()
			|| ( function_exists( 'lnkup_theme_is_dashboard_page' ) && lnkup_theme_is_dashboard_page() );

		$component_deps = array();
		if ( wp_style_is( 'lnkup-components', 'registered' ) || wp_style_is( 'lnkup-components', 'enqueued' ) ) {
			$component_deps[] = 'lnkup-components';
		}

		wp_enqueue_script(
			'lnkup-ui',
			LNKUP_PLUGIN_URL . 'public/js/lnkup-ui.js',
			array(),
			LNKUP_VERSION,
			true
		);
		if ( class_exists( 'LNKup_UI_Copy' ) ) {
			wp_localize_script( 'lnkup-ui', 'lnkupUiCopy', LNKup_UI_Copy::for_js() );
		}

		if ( $is_dashboard ) {
			wp_enqueue_style(
				'lnkup-vendor-dashboard',
				LNKUP_PLUGIN_URL . 'public/css/vendor-dashboard.css',
				$component_deps,
				LNKUP_VERSION
			);
			$vendor_view = 'home';
			if ( is_page( 'vendor-dashboard' ) && isset( $_GET['view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$vendor_view = sanitize_key( wp_unslash( $_GET['view'] ) );
			}
			if ( is_page( 'vendor-dashboard' ) && ( ! $vendor_view || 'home' === $vendor_view || 'help' === $vendor_view ) ) {
				wp_enqueue_script(
					'lnkup-vendor-home',
					LNKUP_PLUGIN_URL . 'public/js/vendor-home.js',
					array( 'lnkup-marketplace-v2', 'lnkup-ui' ),
					LNKUP_VERSION,
					true
				);
			}
		}
		if ( is_page( 'vendor-profile' ) ) {
			wp_enqueue_style(
				'lnkup-vendor-profile',
				LNKUP_PLUGIN_URL . 'public/css/vendor-profile.css',
				array_merge( $component_deps, array( 'lnkup-vendor-dashboard' ) ),
				LNKUP_VERSION
			);
			wp_enqueue_script(
				'lnkup-vendor-profile',
				LNKUP_PLUGIN_URL . 'public/js/vendor-profile.js',
				array( 'lnkup-marketplace', 'jquery' ),
				LNKUP_VERSION,
				true
			);
			wp_enqueue_media();
		}
		if ( is_page( 'vendors' ) ) {
			wp_enqueue_style(
				'lnkup-public-profile',
				LNKUP_PLUGIN_URL . 'public/css/public-profile.css',
				array_merge( $component_deps, array( 'lnkup-reputation' ) ),
				LNKUP_VERSION
			);
			wp_enqueue_script(
				'lnkup-public-vendor-profile',
				LNKUP_PLUGIN_URL . 'public/js/public-vendor-profile.js',
				array(),
				LNKUP_VERSION,
				true
			);
		}
		wp_enqueue_style(
			'lnkup-marketplace-v2',
			LNKUP_PLUGIN_URL . 'public/css/marketplace-v2.css',
			$component_deps,
			LNKUP_VERSION
		);
		wp_enqueue_script(
			'lnkup-marketplace',
			LNKUP_PLUGIN_URL . 'public/js/marketplace.js',
			array(),
			LNKUP_VERSION,
			true
		);
		wp_enqueue_script(
			'lnkup-marketplace-v2',
			LNKUP_PLUGIN_URL . 'public/js/marketplace-v2.js',
			array( 'lnkup-marketplace' ),
			LNKUP_VERSION,
			true
		);
		if ( lnkup_marketplace_phase_at_least( '1b' ) ) {
			wp_enqueue_script(
				'lnkup-marketplace-map',
				LNKUP_PLUGIN_URL . 'public/js/marketplace-map.js',
				array( 'lnkup-marketplace-v2' ),
				LNKUP_VERSION,
				true
			);
		}
		if ( lnkup_marketplace_phase_at_least( '1c' ) ) {
			wp_enqueue_script(
				'lnkup-marketplace-compare',
				LNKUP_PLUGIN_URL . 'public/js/marketplace-compare.js',
				array( 'lnkup-marketplace-v2' ),
				LNKUP_VERSION,
				true
			);
		}
		if ( is_page( array( 'opportunities', 'favorites' ) ) ) {
			wp_enqueue_style(
				'lnkup-external-events',
				LNKUP_PLUGIN_URL . 'public/css/external-events.css',
				array( 'lnkup-marketplace-v2' ),
				LNKUP_VERSION
			);
			wp_enqueue_script(
				'lnkup-external-events',
				LNKUP_PLUGIN_URL . 'public/js/external-events.js',
				array( 'lnkup-marketplace-v2', 'lnkup-ui' ),
				LNKUP_VERSION,
				true
			);
			wp_enqueue_style(
				'lnkup-saved-searches',
				LNKUP_PLUGIN_URL . 'public/css/saved-searches.css',
				array( 'lnkup-marketplace-v2' ),
				LNKUP_VERSION
			);
			wp_enqueue_script(
				'lnkup-saved-searches',
				LNKUP_PLUGIN_URL . 'public/js/saved-searches.js',
				array( 'lnkup-marketplace-v2' ),
				LNKUP_VERSION,
				true
			);
		}
		if ( is_page( array( 'sign-up', 'register' ) ) ) {
			wp_enqueue_style(
				'lnkup-external-events',
				LNKUP_PLUGIN_URL . 'public/css/external-events.css',
				array( 'lnkup-marketplace-v2' ),
				LNKUP_VERSION
			);
			wp_enqueue_script(
				'lnkup-external-events',
				LNKUP_PLUGIN_URL . 'public/js/external-events.js',
				array( 'lnkup-marketplace', 'lnkup-ui' ),
				LNKUP_VERSION,
				true
			);
		}
		wp_localize_script(
			'lnkup-marketplace',
			'lnkupAjax',
			array(
				'url'              => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'lnkup_action' ),
				'reviewNonce'      => wp_create_nonce( 'lnkup_review' ),
				'opportunityNonce' => wp_create_nonce( 'lnkup_opportunity' ),
				'restUrl'          => esc_url_raw( rest_url( 'lnkup/v1/marketplace' ) ),
				'applicationsRestUrl' => esc_url_raw( rest_url( 'lnkup/v1/applications' ) ),
				'crmRestUrl'       => esc_url_raw( rest_url( 'lnkup/v1/crm' ) ),
				'reputationRestUrl'=> esc_url_raw( rest_url( 'lnkup/v1/reputation' ) ),
				'operationsRestUrl'=> esc_url_raw( rest_url( 'lnkup/v1/operations' ) ),
				'packetsRestUrl'   => esc_url_raw( rest_url( 'lnkup/v1/packets' ) ),
				'intelligenceRestUrl'=> esc_url_raw( rest_url( 'lnkup/v1/intelligence' ) ),
				'weatherRestUrl'     => esc_url_raw( rest_url( 'lnkup/v1/weather' ) ),
				'financialRestUrl'   => esc_url_raw( rest_url( 'lnkup/v1/financial' ) ),
				'growthRestUrl'      => esc_url_raw( rest_url( 'lnkup/v1/growth' ) ),
				'connectorRestUrl'   => esc_url_raw( rest_url( 'lnkup/v1' ) ),
				'verificationRestUrl'=> esc_url_raw( rest_url( 'lnkup/v1/verification' ) ),
				'mapsRestUrl'      => esc_url_raw( rest_url( 'lnkup/v1/maps' ) ),
				'mapProvider'      => LNKup_Map_Provider::frontend_config(),
				'vendorsUrl'       => lnkup_page_url_helper( 'vendors' ),
				'restNonce'        => wp_create_nonce( 'wp_rest' ),
				'isLoggedIn'       => is_user_logged_in(),
				'isVendor'         => LNKup_Roles::is_vendor() || LNKup_Capabilities::user_has_manage_all(),
				'registrationUrl'  => lnkup_registration_url(),
				'favoritesUrl'     => lnkup_page_url_helper( 'favorites' ),
				'savedSearchLimit' => class_exists( 'LNKup_Saved_Searches' ) ? LNKup_Saved_Searches::limit_per_vendor() : 10,
				'marketplacePhase' => lnkup_get_marketplace_phase(),
				'opportunitiesUrl' => lnkup_page_url_helper( 'opportunities' ),
				'externalEventsRestUrl' => esc_url_raw( rest_url( 'lnkup/v1' ) ),
				'vendorProfileUrl' => lnkup_page_url_helper( 'vendor-profile' ),
				'organizerDashboardUrl' => lnkup_page_url_helper( 'organizer-dashboard' ),
				'stripePublicKey'    => lnkup_get_settings()['stripe_public_key'] ?? '',
				'eventTypeTree'      => lnkup_get_event_type_tree(),
			)
		);
		wp_enqueue_style(
			'lnkup-reputation',
			LNKUP_PLUGIN_URL . 'public/css/reputation.css',
			array( 'lnkup-marketplace-v2' ),
			LNKUP_VERSION
		);
		wp_enqueue_script(
			'lnkup-reputation',
			LNKUP_PLUGIN_URL . 'public/js/reputation.js',
			array( 'lnkup-marketplace', 'lnkup-ui' ),
			LNKUP_VERSION,
			true
		);
		wp_enqueue_style(
			'lnkup-verification',
			LNKUP_PLUGIN_URL . 'public/css/verification.css',
			array( 'lnkup-marketplace-v2' ),
			LNKUP_VERSION
		);
		wp_enqueue_script(
			'lnkup-verification',
			LNKUP_PLUGIN_URL . 'public/js/verification.js',
			array( 'lnkup-marketplace', 'lnkup-ui' ),
			LNKUP_VERSION,
			true
		);
		wp_enqueue_style(
			'lnkup-operations',
			LNKUP_PLUGIN_URL . 'public/css/operations.css',
			array( 'lnkup-marketplace-v2' ),
			LNKUP_VERSION
		);
		wp_enqueue_script(
			'lnkup-operations',
			LNKUP_PLUGIN_URL . 'public/js/operations.js',
			array( 'lnkup-marketplace', 'lnkup-ui', 'lnkup-weather' ),
			LNKUP_VERSION,
			true
		);
		wp_enqueue_script(
			'lnkup-vendor-ops',
			LNKUP_PLUGIN_URL . 'public/js/vendor-ops.js',
			array( 'lnkup-operations' ),
			LNKUP_VERSION,
			true
		);
		wp_enqueue_style(
			'lnkup-vendor-event-pass',
			LNKUP_PLUGIN_URL . 'public/css/vendor-event-pass.css',
			array( 'lnkup-marketplace-v2' ),
			LNKUP_VERSION
		);
		wp_enqueue_script(
			'lnkup-vendor-event-pass',
			LNKUP_PLUGIN_URL . 'public/js/vendor-event-pass.js',
			array( 'lnkup-operations', 'lnkup-vendor-ops' ),
			LNKUP_VERSION,
			true
		);
		wp_enqueue_style(
			'lnkup-weather',
			LNKUP_PLUGIN_URL . 'public/css/weather.css',
			array( 'lnkup-marketplace-v2' ),
			LNKUP_VERSION
		);
		wp_enqueue_script(
			'lnkup-weather',
			LNKUP_PLUGIN_URL . 'public/js/weather.js',
			array( 'lnkup-marketplace', 'lnkup-ui' ),
			LNKUP_VERSION,
			true
		);
		wp_enqueue_style(
			'lnkup-intelligence',
			LNKUP_PLUGIN_URL . 'public/css/intelligence.css',
			array( 'lnkup-marketplace-v2' ),
			LNKUP_VERSION
		);
		wp_enqueue_script(
			'lnkup-intelligence',
			LNKUP_PLUGIN_URL . 'public/js/intelligence.js',
			array( 'lnkup-marketplace', 'lnkup-ui', 'lnkup-weather' ),
			LNKUP_VERSION,
			true
		);
		wp_enqueue_style(
			'lnkup-maps',
			LNKUP_PLUGIN_URL . 'public/css/maps.css',
			array( 'lnkup-marketplace-v2' ),
			LNKUP_VERSION
		);
		wp_enqueue_script(
			'lnkup-maps',
			LNKUP_PLUGIN_URL . 'public/js/maps.js',
			array( 'lnkup-marketplace', 'lnkup-operations' ),
			LNKUP_VERSION,
			true
		);
		$load_organizer_crm = is_user_logged_in() && (
			LNKup_Roles::is_organizer()
			|| LNKup_Capabilities::user_has_manage_all()
			|| ! empty( $_GET['invite'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| ( $is_dashboard && is_page( 'organizer-dashboard' ) )
		);
		if ( $load_organizer_crm ) {
			$crm_deps = array( 'lnkup-marketplace-v2' );
			if ( wp_style_is( 'lnkup-components', 'registered' ) || wp_style_is( 'lnkup-components', 'enqueued' ) ) {
				$crm_deps[] = 'lnkup-components';
			}
			wp_enqueue_style(
				'lnkup-organizer-crm',
				LNKUP_PLUGIN_URL . 'public/css/organizer-crm.css',
				$crm_deps,
				LNKUP_VERSION
			);
			wp_enqueue_script(
				'lnkup-organizer-crm',
				LNKUP_PLUGIN_URL . 'public/js/organizer-crm.js',
				array( 'lnkup-marketplace', 'lnkup-reputation', 'lnkup-ui' ),
				LNKUP_VERSION,
				true
			);
			wp_enqueue_style(
				'lnkup-vendor-import',
				LNKUP_PLUGIN_URL . 'public/css/vendor-import.css',
				array( 'lnkup-organizer-crm' ),
				LNKUP_VERSION
			);
			wp_enqueue_script(
				'lnkup-vendor-import',
				LNKUP_PLUGIN_URL . 'public/js/vendor-import.js',
				array( 'lnkup-organizer-crm' ),
				LNKUP_VERSION,
				true
			);
			wp_enqueue_style(
				'lnkup-external-events',
				LNKUP_PLUGIN_URL . 'public/css/external-events.css',
				array( 'lnkup-organizer-crm' ),
				LNKUP_VERSION
			);
			wp_enqueue_script(
				'lnkup-external-events',
				LNKUP_PLUGIN_URL . 'public/js/external-events.js',
				array( 'lnkup-organizer-crm', 'lnkup-ui' ),
				LNKUP_VERSION,
				true
			);
			$import_settings = lnkup_get_settings();
			wp_localize_script(
				'lnkup-vendor-import',
				'lnkupVendorImport',
				array(
					'maxRows'     => class_exists( 'LNKup_Vendor_Import' ) ? LNKup_Vendor_Import::max_rows() : 500,
					'maxBytes'    => class_exists( 'LNKup_Vendor_Import' ) ? LNKup_Vendor_Import::max_file_bytes() : 5242880,
					'allowInvite' => ! isset( $import_settings['crm_import_allow_invite'] ) || ! empty( $import_settings['crm_import_allow_invite'] ),
					'fieldLabels' => class_exists( 'LNKup_Vendor_Import' ) ? LNKup_Vendor_Import::field_labels() : array(),
				)
			);
			wp_enqueue_script(
				'lnkup-organizer-command',
				LNKUP_PLUGIN_URL . 'public/js/organizer-command.js',
				array( 'lnkup-organizer-crm', 'lnkup-ui', 'lnkup-intelligence' ),
				LNKUP_VERSION,
				true
			);
			wp_enqueue_script(
				'lnkup-organizer-create',
				LNKUP_PLUGIN_URL . 'public/js/organizer-create.js',
				array( 'lnkup-organizer-command' ),
				LNKUP_VERSION,
				true
			);
			wp_localize_script(
				'lnkup-organizer-create',
				'lnkupEventTypeTree',
				array(
					'tree' => lnkup_get_event_type_tree(),
				)
			);
		}
		if ( is_user_logged_in() && ( LNKup_Roles::is_vendor() || LNKup_Roles::is_organizer() || LNKup_Capabilities::user_has_manage_all() ) ) {
			$settings = lnkup_get_settings();
			wp_enqueue_style(
				'lnkup-financial',
				LNKUP_PLUGIN_URL . 'public/css/financial.css',
				array( 'lnkup-marketplace-v2' ),
				lnkup_asset_version( 'public/css/financial.css' )
			);
			wp_enqueue_style(
				'lnkup-checkout',
				LNKUP_PLUGIN_URL . 'public/css/checkout.css',
				array( 'lnkup-financial' ),
				LNKUP_VERSION
			);
			wp_enqueue_script(
				'lnkup-financial',
				LNKUP_PLUGIN_URL . 'public/js/financial.js',
				array( 'lnkup-marketplace', 'lnkup-ui' ),
				lnkup_asset_version( 'public/js/financial.js' ),
				true
			);
			wp_enqueue_script(
				'lnkup-checkout',
				LNKUP_PLUGIN_URL . 'public/js/checkout.js',
				array( 'lnkup-financial' ),
				LNKUP_VERSION,
				true
			);
			wp_localize_script(
				'lnkup-checkout',
				'lnkupCheckoutConfig',
				array(
					'stripePublicKey' => $settings['stripe_public_key'] ?? '',
				)
			);
		}
		if ( is_user_logged_in() && LNKup_Capabilities::can_view_growth() ) {
			wp_enqueue_style(
				'lnkup-growth',
				LNKUP_PLUGIN_URL . 'public/css/growth.css',
				array( 'lnkup-marketplace-v2' ),
				LNKUP_VERSION
			);
			wp_enqueue_script(
				'lnkup-growth',
				LNKUP_PLUGIN_URL . 'public/js/growth.js',
				array( 'lnkup-marketplace', 'lnkup-ui' ),
				LNKUP_VERSION,
				true
			);
		}
		if ( is_page( array( 'connector-dashboard', 'connector-interest' ) ) || ( is_user_logged_in() && LNKup_Roles::is_connector() ) ) {
			wp_enqueue_style(
				'lnkup-connector',
				LNKUP_PLUGIN_URL . 'public/css/connector.css',
				array( 'lnkup-marketplace-v2' ),
				LNKUP_VERSION
			);
			wp_enqueue_script(
				'lnkup-connector',
				LNKUP_PLUGIN_URL . 'public/js/connector.js',
				array( 'lnkup-marketplace', 'lnkup-ui' ),
				LNKUP_VERSION,
				true
			);
		}
	}

	/**
	 * Enqueue organizer recruit referral assets.
	 */
	public static function enqueue_organizer_recruit_assets() {
		wp_enqueue_style(
			'lnkup-organizer-recruit',
			LNKUP_PLUGIN_URL . 'public/css/organizer-recruit.css',
			array(),
			LNKUP_VERSION
		);
		wp_enqueue_script(
			'lnkup-organizer-recruit',
			LNKUP_PLUGIN_URL . 'public/js/organizer-recruit.js',
			array(),
			LNKUP_VERSION,
			true
		);
	}

	/**
	 * Vendor dashboard shortcode.
	 */
	public static function render_vendor_dashboard() {
		LNKup_Capabilities::require_login();
		if ( ! LNKup_Roles::is_vendor() && ! LNKup_Capabilities::user_has_manage_all() ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'Vendor access required.', 'lnkup-marketplace' ) . '</p>';
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'home'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map  = array(
			'home'         => 'render_home',
			'applications' => 'render_applications',
			'documents'    => 'render_documents',
			'payments'     => 'render_payments',
			'financial'    => 'render_payments',
			'growth'       => 'render_growth',
			'referrals'    => 'render_referrals',
			'reviews'      => 'render_reviews',
			'operations'   => 'render_operations',
			'help'         => 'render_help',
		);

		if ( ! isset( $map[ $view ] ) ) {
			$view = 'home';
		}

		$method = $map[ $view ];
		return LNKup_Vendor_Dashboard_Views::$method();
	}

	/**
	 * Organizer dashboard shortcode.
	 */
	public static function render_organizer_dashboard() {
		LNKup_Capabilities::require_login();
		if ( ! LNKup_Roles::is_organizer() && ! LNKup_Capabilities::user_has_manage_all() ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'Organizer access required.', 'lnkup-marketplace' ) . '</p>';
		}

		$vendor_profile = isset( $_GET['vendor'] ) ? absint( $_GET['vendor'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $vendor_profile ) {
			return self::render_vendor_crm_profile( $vendor_profile );
		}

		$roster_event = isset( $_GET['event'] ) && ! empty( $_GET['roster'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $roster_event ) {
			return self::render_event_roster_view( $roster_event );
		}

		$ops_event = isset( $_GET['event'] ) && ! empty( $_GET['ops'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $ops_event ) {
			return self::render_event_operations_view( $ops_event );
		}

		$scan_event = isset( $_GET['event'] ) && ! empty( $_GET['scan'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $scan_event ) {
			return self::render_qr_scan_view( $scan_event );
		}

		$user_id = get_current_user_id();
		$events  = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => 50,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $user_id,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$event_ids = wp_list_pluck( $events, 'ID' );
		$opps      = $event_ids
			? get_posts(
				array(
					'post_type'      => 'lnkup_opportunity',
					'posts_per_page' => 50,
					'meta_query'     => array(
						array(
							'key'     => '_lnkup_event_id',
							'value'   => $event_ids,
							'compare' => 'IN',
						),
					),
				)
			)
			: array();
		$apps     = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 50,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $user_id,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$payments = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'posts_per_page' => 50,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $user_id,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$collected = array_reduce(
			$payments,
			function ( $sum, $p ) {
				if ( 'paid' === get_post_meta( $p->ID, '_lnkup_payment_status', true ) ) {
					return $sum + (float) LNKup_Financial::payment_booth_amount( $p->ID );
				}
				return $sum;
			},
			0
		);
		$pending_apps = array_filter(
			$apps,
			function ( $a ) {
				$s = get_post_meta( $a->ID, '_lnkup_application_status', true );
				return in_array( $s, array( 'submitted', 'under_review' ), true );
			}
		);
		// Approved KPI / unpaid pool: approved but not yet paid.
		$approved_vendors = array_filter(
			$apps,
			function ( $a ) {
				$s = get_post_meta( $a->ID, '_lnkup_application_status', true );
				return in_array( $s, array( 'approved', 'payment_required' ), true );
			}
		);
		// Confirmed / readiness: approved through completed.
		$confirmed_vendors = array_filter(
			$apps,
			function ( $a ) {
				$s = get_post_meta( $a->ID, '_lnkup_application_status', true );
				return in_array( $s, array( 'approved', 'payment_required', 'paid', 'completed' ), true );
			}
		);
		$missing_docs = array_filter(
			$apps,
			function ( $a ) {
				return 'ready' !== LNKup_Vendor_Readiness::refresh( $a->ID );
			}
		);
		$upcoming_loadins = array_filter(
			$events,
			function ( $e ) {
				$date = get_post_meta( $e->ID, '_lnkup_event_date', true );
				return $date && strtotime( $date ) >= strtotime( 'today' );
			}
		);
		$user        = wp_get_current_user();
		$display     = $user->display_name;
		$event_count = count( $events );
		$open_opps   = array_filter(
			$opps,
			function ( $o ) {
				$status    = (string) get_post_meta( $o->ID, '_lnkup_opportunity_status', true );
				$remaining = (int) get_post_meta( $o->ID, '_lnkup_spots_remaining', true );
				$is_open   = '' === $status || 'open' === $status;
				return $is_open && $remaining > 0;
			}
		);
		$opp_count   = count( $open_opps );
		$dash_url    = lnkup_page_url_helper( 'organizer-dashboard' );
		$pending_payout   = class_exists( 'LNKup_Payouts' ) ? LNKup_Payouts::pending_balance( $user_id ) : 0;
		$available_payout = class_exists( 'LNKup_Payouts' ) ? LNKup_Payouts::available_balance( $user_id ) : 0;
		$payout_count     = class_exists( 'LNKup_Payouts' ) ? LNKup_Payouts::count_for_organizer( $user_id ) : 0;
		$next_loadin      = '';
		if ( $upcoming_loadins ) {
			$loadin_sorted = array_values( $upcoming_loadins );
			usort(
				$loadin_sorted,
				function ( $a, $b ) {
					$da = get_post_meta( $a->ID, '_lnkup_event_date', true );
					$db = get_post_meta( $b->ID, '_lnkup_event_date', true );
					return strtotime( $da ) <=> strtotime( $db );
				}
			);
			$first_date = get_post_meta( $loadin_sorted[0]->ID, '_lnkup_event_date', true );
			if ( $first_date ) {
				$next_loadin = date_i18n( 'M j', strtotime( $first_date ) );
			}
		}
		$primary_cta_href  = $dash_url . '#create';
		$primary_cta_label = function_exists( 'lnkup_copy' ) ? lnkup_copy( 'launch_event' ) : __( 'Launch event', 'lnkup-marketplace' );
		$vendors_booked    = 0;
		foreach ( $events as $ev ) {
			$spots = self::organizer_event_spots( $ev->ID );
			$vendors_booked += (int) $spots['filled'];
		}
		$unpaid_apps = array_filter(
			$apps,
			function ( $a ) {
				$s = get_post_meta( $a->ID, '_lnkup_application_status', true );
				return in_array( $s, array( 'approved', 'payment_required' ), true );
			}
		);
		$loadin_7d = array_filter(
			$upcoming_loadins,
			function ( $e ) {
				$date = get_post_meta( $e->ID, '_lnkup_event_date', true );
				return $date && strtotime( $date ) <= strtotime( '+7 days' );
			}
		);
		$live_events_count          = count( $upcoming_loadins );
		$confirmed_vendors_count    = count( $confirmed_vendors );
		$readiness_issues_count     = count( $missing_docs );
		$readiness_percent_sum      = 0;
		$readiness_percent_count    = 0;
		$at_risk_readiness_count    = 0;
		foreach ( $confirmed_vendors as $a ) {
			$cl = LNKup_Vendor_Readiness::checklist( $a->ID );
			$readiness_percent_sum += $cl['percent'];
			$readiness_percent_count++;
			if ( ( $cl['tier'] ?? '' ) === LNKup_Vendor_Readiness::TIER_AT_RISK ) {
				$at_risk_readiness_count++;
			}
		}
		$readiness_kpi_value        = $readiness_percent_count > 0
			? ( (int) round( $readiness_percent_sum / $readiness_percent_count ) ) . '%'
			: '—';
		$readiness_kpi_trend        = $at_risk_readiness_count > 0
			? sprintf( _n( '%d at risk', '%d at risk', $at_risk_readiness_count, 'lnkup-marketplace' ), $at_risk_readiness_count )
			: __( 'On track', 'lnkup-marketplace' );
		$command_summary_line       = sprintf(
			/* translators: 1: live events 2: confirmed vendors 3: readiness issues */
			__( '%1$s · %2$s · %3$s', 'lnkup-marketplace' ),
			sprintf( _n( '%d live event', '%d live events', $live_events_count, 'lnkup-marketplace' ), $live_events_count ),
			sprintf( _n( '%d confirmed vendor', '%d confirmed vendors', $confirmed_vendors_count, 'lnkup-marketplace' ), $confirmed_vendors_count ),
			sprintf( _n( '%d event readiness issue', '%d event readiness issues', $readiness_issues_count, 'lnkup-marketplace' ), $readiness_issues_count )
		);
		$attention_items = array();
		if ( count( $pending_apps ) > 0 ) {
			$attention_items[] = array(
				'label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_requests' ) : __( 'Pending vendor requests', 'lnkup-marketplace' ),
				'count' => count( $pending_apps ),
				'href'  => $dash_url . '#applications',
				'level' => 'warning',
			);
		}
		if ( count( $unpaid_apps ) > 0 ) {
			$attention_items[] = array(
				'label' => __( 'Awaiting vendor payment', 'lnkup-marketplace' ),
				'count' => count( $unpaid_apps ),
				'href'  => $dash_url . '#applications',
				'level' => 'warning',
			);
		}
		if ( count( $missing_docs ) > 0 ) {
			$attention_items[] = array(
				'label' => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendors_not_event_ready' ) : __( 'Vendors not event-ready', 'lnkup-marketplace' ),
				'count' => count( $missing_docs ),
				'href'  => $dash_url . '#applications',
				'level' => 'info',
			);
		}
		if ( count( $loadin_7d ) > 0 ) {
			$attention_items[] = array(
				'label' => __( 'Load-ins this week', 'lnkup-marketplace' ),
				'count' => count( $loadin_7d ),
				'href'  => $dash_url . '#operations',
				'level' => 'info',
			);
		}

		$has_priority_module = (bool) array_filter(
			$missing_docs,
			function ( $a ) {
				$s = get_post_meta( $a->ID, '_lnkup_application_status', true );
				return in_array( $s, array( 'approved', 'payment_required', 'paid' ), true );
			}
		);
		$priority_module_html = self::render_organizer_priority_module( $missing_docs, $dash_url );
		$launch_checklist     = class_exists( 'LNKup_Organizer_Launch_Checklist' )
			? LNKup_Organizer_Launch_Checklist::get_checklist(
				$user_id,
				array(
					'events'   => $events,
					'opps'     => $opps,
					'apps'     => $apps,
					'payments' => $payments,
				)
			)
			: null;
		$launch_checklist_active = $launch_checklist && ! empty( $launch_checklist['show'] ) && empty( $launch_checklist['all_complete'] );
		$show_launch_checklist   = $launch_checklist && ! empty( $launch_checklist['show'] );
		$show_organizer_welcome  = $launch_checklist && ! empty( $launch_checklist['show_welcome'] );

		global $wpdb;
		$favorites_count = 0;
		$preferred_count = 0;
		if ( class_exists( 'LNKup_Organizer_CRM_Tables' ) ) {
			$crm_table       = LNKup_Organizer_CRM_Tables::rel_table();
			$favorites_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$crm_table} WHERE organizer_user_id = %d AND is_favorite = 1", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$preferred_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$crm_table} WHERE organizer_user_id = %d AND is_preferred = 1", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$open_disputes = class_exists( 'LNKup_Financial_Disputes' ) ? LNKup_Financial_Disputes::open_count_for_organizer( $user_id ) : 0;
		$org_verify    = class_exists( 'LNKup_Verification' )
			? LNKup_Verification::get_organizer_verification_status( $user_id, array( 'public' => true ) )
			: array();
		$payout_status_html = '';
		if ( ! empty( $org_verify['public_summary'] ) ) {
			$pill_class = ! empty( $org_verify['payout_connected'] ) ? 'lnkup-status-pill--success' : 'lnkup-status-pill--warning';
			$payout_status_html = '<span class="lnkup-status-pill ' . esc_attr( $pill_class ) . '">' . esc_html( (string) $org_verify['public_summary'] ) . '</span>';
		}
		$checklist_ring = ( $show_launch_checklist && empty( $launch_checklist['all_complete'] ) ) ? (int) ( $launch_checklist['percent'] ?? 0 ) : null;

		ob_start();
		?>
		<div class="lnkup-mp lnkup-organizer-dash" id="lnkup-organizer-crm" data-rest="<?php echo esc_url( rest_url( 'lnkup/v1/crm' ) ); ?>">
			<section class="lnkup-organizer-overview lnkup-organizer-section" id="overview">
				<?php
				echo lnkup_executive_hero(
					array(
						'title'       => lnkup_copy( 'organizer_command_center' ),
						'subtitle'    => __( 'Launch events, fill vendor rows, and collect fees — all in one place.', 'lnkup-marketplace' ),
						'kpi'         => (string) $live_events_count,
						'kpi_label'   => lnkup_copy( 'live_events' ),
						'ring_pct'    => $checklist_ring,
						'metrics'     => array(
							array( 'label' => lnkup_copy( 'confirmed_vendors' ), 'value' => (string) $confirmed_vendors_count ),
							array( 'label' => lnkup_copy( 'vendor_revenue' ), 'value' => lnkup_currency( $collected ) ),
							array( 'label' => lnkup_copy( 'new_vendor_requests' ), 'value' => (string) count( $pending_apps ) ),
							array( 'label' => __( 'Open opportunities', 'lnkup-marketplace' ), 'value' => (string) $opp_count ),
						),
						'status_html' => $payout_status_html,
						'primary_cta' => array(
							'label' => lnkup_copy( 'launch_event' ),
							'href'  => $dash_url . '#create',
						),
						'secondary_cta' => array(
							'label' => __( 'Review requests', 'lnkup-marketplace' ),
							'href'  => $dash_url . '#applications',
						),
					)
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>

				<?php
				if ( $show_launch_checklist ) {
					echo self::render_organizer_launch_checklist( $launch_checklist, $dash_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				if ( $show_organizer_welcome ) {
					echo self::render_organizer_welcome_modal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				if ( class_exists( 'LNKup_Dashboard_Tour' ) ) {
					$tour_auto = LNKup_Dashboard_Tour::should_show( $user_id, 'organizer' );
					echo LNKup_Dashboard_Tour::render_modal( 'organizer', $tour_auto ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>

				<div class="lnkup-organizer-trust-strip lnkup-organizer-trust-strip--deferred" data-testid="lnkup-organizer-trust-strip" aria-live="polite" hidden></div>

				<div class="lnkup-organizer-attention-block" data-testid="lnkup-organizer-attention-block">
					<?php echo $priority_module_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php if ( $attention_items && ! $has_priority_module ) : ?>
						<div class="lnkup-panel lnkup-organizer-attention lnkup-surface--primary">
							<h2 class="lnkup-panel__title"><?php esc_html_e( 'Needs your attention', 'lnkup-marketplace' ); ?></h2>
							<ul class="lnkup-organizer-attention__list">
								<?php foreach ( $attention_items as $item ) : ?>
									<li class="lnkup-organizer-attention__item">
										<a href="<?php echo esc_url( $item['href'] ); ?>" class="lnkup-organizer-attention__link">
											<span class="lnkup-organizer-attention__dot lnkup-organizer-attention__dot--<?php echo esc_attr( $item['level'] ); ?>" aria-hidden="true"></span>
											<span class="lnkup-organizer-attention__label"><?php echo esc_html( $item['label'] ); ?></span>
											<span class="lnkup-organizer-attention__count"><?php echo esc_html( (string) $item['count'] ); ?></span>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
					<?php if ( ! $has_priority_module && ! $attention_items && 0 === $event_count && ! $launch_checklist_active ) : ?>
						<?php
						echo lnkup_empty_state(
							array(
								'title'               => __( 'Get started with your first event', 'lnkup-marketplace' ),
								'desc'                => __( 'Launch an event to publish vendor opportunities and start receiving vendor requests.', 'lnkup-marketplace' ),
								'cta_label'           => lnkup_copy( 'launch_event' ),
								'cta_href'            => $dash_url . '#create',
								'secondary_cta_label' => __( 'Browse vendors', 'lnkup-marketplace' ),
								'secondary_cta_href'  => $dash_url . '#vendors',
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					<?php endif; ?>
				</div>

				<?php echo self::render_organizer_payout_strip( $available_payout, $collected, $org_verify, $dash_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php
				$paid_vendors_count = count(
					array_filter(
						$apps,
						function ( $a ) {
							return in_array( get_post_meta( $a->ID, '_lnkup_application_status', true ), array( 'paid', 'completed' ), true );
						}
					)
				);
				$pending_trend = count( $pending_apps ) > 0
					? __( 'Needs review', 'lnkup-marketplace' )
					: __( 'All caught up', 'lnkup-marketplace' );
				$pending_dir   = count( $pending_apps ) > 0 ? 'up' : 'neutral';
				$events_trend  = $live_events_count > 0
					? sprintf( _n( '%d upcoming', '%d upcoming', $live_events_count, 'lnkup-marketplace' ), $live_events_count )
					: __( 'Create your first', 'lnkup-marketplace' );
				$booked_trend  = count( $approved_vendors ) > 0
					? sprintf( _n( '%d awaiting payment', '%d awaiting payment', count( $approved_vendors ), 'lnkup-marketplace' ), count( $approved_vendors ) )
					: __( 'No bookings yet', 'lnkup-marketplace' );
				$kpi_animate   = 'count';
				?>
				<div class="lnkup-grid lnkup-organizer-dash__stats lnkup-organizer-kpis lnkup-organizer-kpis--focused" id="lnkup-crm-stats" data-testid="lnkup-crm-stats">
					<?php
					echo LNKup_Vendor_Dashboard_Views::stat_card( lnkup_copy( 'live_events' ), (string) $live_events_count, $events_trend, 'calendar', 0 === $live_events_count, $live_events_count > 0 ? 'up' : 'neutral', $dash_url . '#events', 'accent-lime', $kpi_animate ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo LNKup_Vendor_Dashboard_Views::stat_card( lnkup_copy( 'new_vendor_requests' ), (string) count( $pending_apps ), $pending_trend, 'inbox', 0 === count( $pending_apps ), $pending_dir, $dash_url . '#applications&filter=pending', 'accent-amber', $kpi_animate ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo LNKup_Vendor_Dashboard_Views::stat_card( __( 'Approved', 'lnkup-marketplace' ), (string) count( $approved_vendors ), $booked_trend, 'users', 0 === count( $approved_vendors ), count( $approved_vendors ) > 0 ? 'up' : 'neutral', $dash_url . '#applications&filter=approved', '', $kpi_animate ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo LNKup_Vendor_Dashboard_Views::stat_card( __( 'Paid', 'lnkup-marketplace' ), (string) $paid_vendors_count, __( 'Booked vendors', 'lnkup-marketplace' ), 'users', 0 === $paid_vendors_count, $paid_vendors_count > 0 ? 'up' : 'neutral', $dash_url . '#applications&filter=paid', '', $kpi_animate ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo LNKup_Vendor_Dashboard_Views::stat_card( __( 'Payment due', 'lnkup-marketplace' ), (string) count( $unpaid_apps ), __( 'Awaiting vendor payment', 'lnkup-marketplace' ), 'payment', 0 === count( $unpaid_apps ), count( $unpaid_apps ) > 0 ? 'down' : 'neutral', $dash_url . '#applications&filter=unpaid', count( $unpaid_apps ) > 0 ? 'accent-amber' : '', $kpi_animate ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo LNKup_Vendor_Dashboard_Views::stat_card( __( 'Open opportunities', 'lnkup-marketplace' ), (string) $opp_count, __( 'Accepting vendors', 'lnkup-marketplace' ), 'calendar', 0 === $opp_count, $opp_count > 0 ? 'up' : 'neutral', $dash_url . '#events', '', $kpi_animate ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>

				<?php if ( $favorites_count > 0 || $preferred_count > 0 || $open_disputes > 0 ) : ?>
					<div class="lnkup-organizer-kpi-secondary lnkup-surface--quiet" data-testid="lnkup-crm-stats-secondary">
						<?php if ( $favorites_count > 0 ) : ?>
							<a href="<?php echo esc_url( $dash_url . '#vendors' ); ?>" class="lnkup-organizer-kpi-secondary__chip"><?php printf( esc_html__( '%d favorites', 'lnkup-marketplace' ), (int) $favorites_count ); ?></a>
						<?php endif; ?>
						<?php if ( $preferred_count > 0 ) : ?>
							<a href="<?php echo esc_url( $dash_url . '#vendors' ); ?>" class="lnkup-organizer-kpi-secondary__chip"><?php printf( esc_html__( '%d preferred', 'lnkup-marketplace' ), (int) $preferred_count ); ?></a>
						<?php endif; ?>
						<?php if ( $open_disputes > 0 ) : ?>
							<a href="<?php echo esc_url( $dash_url . '#financial' ); ?>" class="lnkup-organizer-kpi-secondary__chip lnkup-organizer-kpi-secondary__chip--warn"><?php printf( esc_html__( '%d open disputes', 'lnkup-marketplace' ), (int) $open_disputes ); ?></a>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="lnkup-grid lnkup-organizer-actions lnkup-organizer-actions--compact" data-testid="lnkup-organizer-actions">
					<?php
					echo LNKup_Vendor_Dashboard_Views::organizer_action_card( $dash_url . '#vendors', lnkup_copy( 'discover_vendors' ), __( 'Search and invite vendors', 'lnkup-marketplace' ), 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo LNKup_Vendor_Dashboard_Views::organizer_action_card( $dash_url . '#referrals', __( 'Invite organizers', 'lnkup-marketplace' ), __( 'Earn on LNKup fees for 12 months', 'lnkup-marketplace' ), 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo LNKup_Vendor_Dashboard_Views::organizer_action_card( $dash_url . '#financial', lnkup_copy( 'financial_center' ), __( 'Payouts and revenue', 'lnkup-marketplace' ), 'payment' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo LNKup_Vendor_Dashboard_Views::organizer_action_card( $dash_url . '#operations', __( 'Operations', 'lnkup-marketplace' ), __( 'Day-of ops and readiness', 'lnkup-marketplace' ), 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>

				<?php echo self::render_organizer_apps_snapshot( $pending_apps, $dash_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php echo self::render_organizer_readiness_row( $events, $dash_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php echo self::render_organizer_section_nav( $dash_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<div class="lnkup-grid lnkup-grid--3" id="lnkup-crm-widgets-grid"></div>
				<div class="lnkup-panel lnkup-organizer-activity lnkup-surface--secondary" id="lnkup-crm-activity" data-testid="lnkup-crm-activity">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Recent activity', 'lnkup-marketplace' ); ?></h2>
					<div class="lnkup-organizer-activity__body">
						<div class="lnkup-skeleton lnkup-skeleton--card" aria-hidden="true"></div>
					</div>
				</div>

				<?php if ( class_exists( 'LNKup_Weather_Render' ) ) : ?>
					<?php echo LNKup_Weather_Render::readiness_card_compact( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			</section>

			<?php
			$events_count_label = sprintf( _n( '%d event', '%d events', $event_count, 'lnkup-marketplace' ), $event_count );
			$events_actions     = '<span class="lnkup-organizer-section-card__count lnkup-muted">' . esc_html( $events_count_label ) . '</span>';
			$events_actions    .= '<a href="' . esc_url( $dash_url . '#create' ) . '" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-open-create>' . esc_html( lnkup_copy( 'launch_event' ) ) . '</a>';
			echo self::organizer_section_card_open( 'events', __( 'Your Events', 'lnkup-marketplace' ), __( 'Manage events, operations, and vendor recruitment', 'lnkup-marketplace' ), $events_actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
				<?php
				echo lnkup_executive_hero(
					array(
						'title'     => __( 'Events command', 'lnkup-marketplace' ),
						'subtitle'  => __( 'Fill capacity, review requests, and keep load-ins on track.', 'lnkup-marketplace' ),
						'kpi'       => (string) $live_events_count,
						'kpi_label' => __( 'Live events', 'lnkup-marketplace' ),
						'metrics'   => array(
							array( 'label' => __( 'Vendor revenue', 'lnkup-marketplace' ), 'value' => lnkup_currency( $collected ) ),
							array( 'label' => __( 'Booked vendors', 'lnkup-marketplace' ), 'value' => (string) $vendors_booked ),
						),
						'primary_cta' => array(
							'label' => lnkup_copy( 'launch_event' ),
							'href'  => $dash_url . '#create',
						),
						'secondary_cta' => array(
							'label' => __( 'Review requests', 'lnkup-marketplace' ),
							'href'  => $dash_url . '#applications',
						),
					)
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<span id="operations" class="lnkup-panel__anchor" tabindex="-1"></span>
				<?php echo self::render_organizer_events(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo self::render_opportunities_table(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo self::organizer_section_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php echo self::organizer_section_card_open( 'applications', lnkup_copy( 'vendor_requests' ), __( 'Approve, waitlist, or reject vendor requests', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php
				echo lnkup_executive_hero(
					array(
						'title'     => lnkup_copy( 'vendor_requests' ),
						'subtitle'  => __( 'Review applications and onboard vendors', 'lnkup-marketplace' ),
						'kpi'       => (string) count( $pending_apps ),
						'kpi_label' => __( 'Pending review', 'lnkup-marketplace' ),
					)
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<?php if ( count( $apps ) >= 10 ) : ?>
					<div class="lnkup-organizer-app-layout" role="group" aria-label="<?php esc_attr_e( 'Application layout', 'lnkup-marketplace' ); ?>">
						<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm is-active" data-org-app-layout="grid"><?php esc_html_e( 'Cards', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-org-app-layout="list"><?php esc_html_e( 'List', 'lnkup-marketplace' ); ?></button>
					</div>
				<?php endif; ?>
				<?php
				$app_count_waitlisted = count(
					array_filter(
						$apps,
						function ( $a ) {
							return 'waitlisted' === get_post_meta( $a->ID, '_lnkup_application_status', true );
						}
					)
				);
				$app_count_rejected = count(
					array_filter(
						$apps,
						function ( $a ) {
							return 'rejected' === get_post_meta( $a->ID, '_lnkup_application_status', true );
						}
					)
				);
				$app_count_paid = count(
					array_filter(
						$apps,
						function ( $a ) {
							return in_array( get_post_meta( $a->ID, '_lnkup_application_status', true ), array( 'paid', 'completed' ), true );
						}
					)
				);
				?>
				<?php if ( count( $events ) > 0 ) : ?>
					<div class="lnkup-dash-tabs lnkup-organizer-app-event-chips" role="tablist" aria-label="<?php esc_attr_e( 'Filter by event', 'lnkup-marketplace' ); ?>" data-testid="lnkup-org-app-event-chips">
						<button type="button" class="lnkup-dash-tabs__btn is-active" data-org-app-event="all"><?php esc_html_e( 'All events', 'lnkup-marketplace' ); ?> <span class="lnkup-dash-tabs__badge"><?php echo esc_html( (string) count( $apps ) ); ?></span></button>
						<?php foreach ( array_slice( $events, 0, 50 ) as $chip_event ) : ?>
							<?php
							$chip_pending = count(
								array_filter(
									$apps,
									function ( $a ) use ( $chip_event ) {
										return (int) get_post_meta( $a->ID, '_lnkup_event_id', true ) === (int) $chip_event->ID
											&& in_array( get_post_meta( $a->ID, '_lnkup_application_status', true ), array( 'submitted', 'under_review' ), true );
									}
								)
							);
							$chip_total = count(
								array_filter(
									$apps,
									function ( $a ) use ( $chip_event ) {
										return (int) get_post_meta( $a->ID, '_lnkup_event_id', true ) === (int) $chip_event->ID;
									}
								)
							);
							?>
							<button type="button" class="lnkup-dash-tabs__btn" data-org-app-event="<?php echo esc_attr( (string) (int) $chip_event->ID ); ?>">
								<?php echo esc_html( $chip_event->post_title ); ?>
								<span class="lnkup-dash-tabs__badge"><?php echo esc_html( (string) $chip_total ); ?></span>
								<?php if ( $chip_pending > 0 ) : ?>
									<span class="lnkup-dash-tabs__badge lnkup-dash-tabs__badge--pending"><?php echo esc_html( (string) $chip_pending ); ?></span>
								<?php endif; ?>
							</button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<div class="lnkup-dash-tabs lnkup-organizer-app-filters lnkup-organizer-app-filters--sticky" role="tablist" aria-label="<?php esc_attr_e( 'Application filters', 'lnkup-marketplace' ); ?>">
					<button type="button" class="lnkup-dash-tabs__btn is-active" data-org-app-filter="all"><?php esc_html_e( 'All', 'lnkup-marketplace' ); ?> <span class="lnkup-dash-tabs__badge"><?php echo esc_html( (string) count( $apps ) ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn" data-org-app-filter="pending"><?php esc_html_e( 'Pending', 'lnkup-marketplace' ); ?> <span class="lnkup-dash-tabs__badge"><?php echo esc_html( (string) count( $pending_apps ) ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn" data-org-app-filter="approved"><?php esc_html_e( 'Approved', 'lnkup-marketplace' ); ?> <span class="lnkup-dash-tabs__badge"><?php echo esc_html( (string) count( $approved_vendors ) ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn" data-org-app-filter="unpaid"><?php esc_html_e( 'Unpaid', 'lnkup-marketplace' ); ?> <span class="lnkup-dash-tabs__badge"><?php echo esc_html( (string) count( $unpaid_apps ) ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn lnkup-dash-tabs__btn--more" data-org-app-filter-toggle aria-expanded="false"><?php esc_html_e( 'More filters', 'lnkup-marketplace' ); ?></button>
				</div>
				<div class="lnkup-organizer-app-filters lnkup-organizer-app-filters--extra" id="lnkup-org-app-filters-extra" hidden>
					<button type="button" class="lnkup-dash-tabs__btn" data-org-app-filter="paid"><?php esc_html_e( 'Paid', 'lnkup-marketplace' ); ?> <span class="lnkup-dash-tabs__badge"><?php echo esc_html( (string) $app_count_paid ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn" data-org-app-filter="waitlisted"><?php esc_html_e( 'Waitlisted', 'lnkup-marketplace' ); ?> <span class="lnkup-dash-tabs__badge"><?php echo esc_html( (string) $app_count_waitlisted ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn" data-org-app-filter="rejected"><?php esc_html_e( 'Rejected', 'lnkup-marketplace' ); ?> <span class="lnkup-dash-tabs__badge"><?php echo esc_html( (string) $app_count_rejected ); ?></span></button>
					<button type="button" class="lnkup-dash-tabs__btn" data-org-app-filter="missing_docs"><?php esc_html_e( 'Missing Docs', 'lnkup-marketplace' ); ?> <span class="lnkup-dash-tabs__badge"><?php echo esc_html( (string) count( $missing_docs ) ); ?></span></button>
				</div>
				<div class="lnkup-organizer-module-card lnkup-external-pending-module" data-testid="lnkup-external-pending-interests-module">
					<h3 class="lnkup-organizer-module-card__title"><?php echo esc_html( lnkup_copy( 'organizer_claim_pending_requests' ) ); ?></h3>
					<p class="lnkup-muted"><?php esc_html_e( 'Vendors who requested opportunities on your claimed public listings.', 'lnkup-marketplace' ); ?></p>
					<div id="lnkup-external-pending-interests" class="lnkup-panel-skeleton"><?php esc_html_e( 'Loading…', 'lnkup-marketplace' ); ?></div>
				</div>
				<div id="lnkup-app-intel-grid">
					<?php echo self::render_applications_manager(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="lnkup-crm-compare-bar" id="lnkup-crm-compare-bar" hidden>
					<span class="lnkup-crm-compare-bar__count" id="lnkup-crm-compare-count"><?php esc_html_e( '0 selected', 'lnkup-marketplace' ); ?></span>
					<div class="lnkup-crm-compare-bar__actions">
						<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" id="lnkup-crm-compare-clear"><?php esc_html_e( 'Clear', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" id="lnkup-crm-compare-open"><?php esc_html_e( 'Compare', 'lnkup-marketplace' ); ?></button>
					</div>
				</div>
			<?php echo self::organizer_section_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="lnkup-organizer-secondary-grid">
				<?php echo self::organizer_module_card_open( 'vendors', lnkup_copy( 'discover_vendors' ), __( 'Search and recruit vendors for your events', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="search" id="lnkup-crm-vendor-search" class="lnkup-crm-search lnkup-organizer-module-card__search" placeholder="<?php esc_attr_e( 'Search vendors…', 'lnkup-marketplace' ); ?>" />
					<p id="lnkup-organizer-vendors-summary" class="lnkup-muted lnkup-organizer-module-summary"><?php esc_html_e( 'Browse the vendor network, then expand to invite and shortlist.', 'lnkup-marketplace' ); ?></p>
					<div id="lnkup-organizer-vendors-preview" class="lnkup-organizer-vendors-preview lnkup-organizer-vendors-preview--compact" data-loaded="1" data-ssr="1">
						<?php echo self::render_organizer_vendors_preview_ssr( $user_id, $dash_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" data-expand-panel="vendors"><?php echo esc_html( lnkup_copy( 'expand_vendors' ) ); ?></button>
					<?php echo self::organizer_expand_panel_open( 'vendors' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="lnkup-organizer-vendors-expand-head">
							<?php
							echo lnkup_executive_hero(
								array(
									'title'    => lnkup_copy( 'discover_vendors' ),
									'subtitle' => __( 'Search and recruit vendors for your events', 'lnkup-marketplace' ),
									'metrics'  => array(
										array( 'label' => __( 'Favorites', 'lnkup-marketplace' ), 'value' => (string) $favorites_count ),
										array( 'label' => __( 'Preferred', 'lnkup-marketplace' ), 'value' => (string) $preferred_count ),
									),
								)
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
							<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm lnkup-organizer-vendors-collapse" data-collapse-panel="vendors"><?php esc_html_e( 'Collapse', 'lnkup-marketplace' ); ?></button>
						</div>
						<div class="lnkup-crm-toolbar">
							<div class="lnkup-crm-toolbar__row lnkup-crm-toolbar__row--primary">
								<div class="lnkup-crm-toolbar__tabs">
									<button type="button" class="lnkup-btn lnkup-btn--sm is-active" data-crm-scope="discover"><?php esc_html_e( 'Discover', 'lnkup-marketplace' ); ?></button>
									<button type="button" class="lnkup-btn lnkup-btn--sm" data-crm-scope="my"><?php esc_html_e( 'My Vendors', 'lnkup-marketplace' ); ?></button>
									<button type="button" class="lnkup-btn lnkup-btn--sm" data-crm-scope="imported"><?php esc_html_e( 'Imported', 'lnkup-marketplace' ); ?></button>
								</div>
								<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" id="lnkup-import-vendors-btn" data-testid="lnkup-import-vendors-btn"><?php esc_html_e( 'Import vendors', 'lnkup-marketplace' ); ?></button>
								<div class="lnkup-crm-toolbar__views">
									<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm is-active" data-crm-view="card"><?php esc_html_e( 'Cards', 'lnkup-marketplace' ); ?></button>
									<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-crm-view="table"><?php esc_html_e( 'Table', 'lnkup-marketplace' ); ?></button>
									<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-crm-view="compact"><?php esc_html_e( 'List', 'lnkup-marketplace' ); ?></button>
								</div>
							</div>
							<div class="lnkup-crm-toolbar__row lnkup-crm-toolbar__row--filters">
								<select id="lnkup-crm-vendor-sort" class="lnkup-crm-search">
									<option value="score"><?php esc_html_e( 'Highest score', 'lnkup-marketplace' ); ?></option>
									<option value="rating"><?php esc_html_e( 'Best rating', 'lnkup-marketplace' ); ?></option>
									<option value="completed"><?php esc_html_e( 'Most events', 'lnkup-marketplace' ); ?></option>
									<option value="reliable"><?php esc_html_e( 'Most reliable', 'lnkup-marketplace' ); ?></option>
									<option value="preferred"><?php esc_html_e( 'Preferred first', 'lnkup-marketplace' ); ?></option>
									<option value="new"><?php esc_html_e( 'New vendors', 'lnkup-marketplace' ); ?></option>
								</select>
								<input type="text" id="lnkup-crm-vendor-city" class="lnkup-crm-search" placeholder="<?php esc_attr_e( 'City…', 'lnkup-marketplace' ); ?>" />
								<label class="lnkup-crm-toolbar__verified">
									<input type="checkbox" id="lnkup-crm-vendor-verified" value="1" />
									<?php esc_html_e( 'Verified only', 'lnkup-marketplace' ); ?>
								</label>
								<select id="lnkup-crm-vendor-category" class="lnkup-crm-search">
									<option value=""><?php esc_html_e( 'All categories', 'lnkup-marketplace' ); ?></option>
									<?php
									$vendor_cats = get_terms(
										array(
											'taxonomy'   => 'lnkup_vendor_category',
											'hide_empty' => false,
										)
									);
									if ( $vendor_cats && ! is_wp_error( $vendor_cats ) ) :
										foreach ( $vendor_cats as $cat ) :
											?>
											<option value="<?php echo esc_attr( $cat->name ); ?>"><?php echo esc_html( $cat->name ); ?></option>
											<?php
										endforeach;
									endif;
									?>
								</select>
							</div>
						</div>
						<div id="lnkup-crm-directory" class="lnkup-crm-directory--limited"></div>
						<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" id="lnkup-crm-directory-more" hidden><?php esc_html_e( 'Show more', 'lnkup-marketplace' ); ?></button>
					<?php echo self::organizer_expand_panel_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo self::organizer_module_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php echo self::organizer_module_card_open( 'lists', __( 'Vendor Lists', 'lnkup-marketplace' ), __( 'Reusable lists for invites and outreach', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<p id="lnkup-organizer-lists-summary" class="lnkup-muted lnkup-organizer-module-summary"><?php esc_html_e( 'No lists yet. Create one to organize vendors.', 'lnkup-marketplace' ); ?></p>
					<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" data-expand-panel="lists" id="lnkup-crm-new-list-compact"><?php esc_html_e( 'Create list', 'lnkup-marketplace' ); ?></button>
					<?php echo self::organizer_expand_panel_open( 'lists' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="lnkup-organizer-smart-lists" role="group" aria-label="<?php esc_attr_e( 'Smart lists', 'lnkup-marketplace' ); ?>">
							<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm lnkup-smart-list" data-crm-scope="my" data-crm-sort="preferred"><?php esc_html_e( 'Preferred Vendors', 'lnkup-marketplace' ); ?></button>
							<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm lnkup-smart-list" data-org-app-filter="missing_docs"><?php esc_html_e( 'Missing Docs', 'lnkup-marketplace' ); ?></button>
							<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm lnkup-smart-list" data-org-app-filter="unpaid"><?php esc_html_e( 'Unpaid Vendors', 'lnkup-marketplace' ); ?></button>
							<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm lnkup-smart-list" data-crm-scope="discover" data-crm-sort="rating"><?php esc_html_e( 'High Rated', 'lnkup-marketplace' ); ?></button>
							<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm lnkup-smart-list" data-crm-scope="my" data-crm-sort="completed"><?php esc_html_e( 'Recently Worked', 'lnkup-marketplace' ); ?></button>
						</div>
						<form id="lnkup-crm-new-list-form" class="lnkup-crm-new-list-form">
							<input type="text" id="lnkup-crm-new-list-name" class="lnkup-crm-search" placeholder="<?php esc_attr_e( 'New list name…', 'lnkup-marketplace' ); ?>" required />
							<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Create list', 'lnkup-marketplace' ); ?></button>
						</form>
						<div id="lnkup-crm-lists"></div>
					<?php echo self::organizer_expand_panel_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo self::organizer_module_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php echo self::organizer_module_card_open( 'invites', __( 'Vendor Invites', 'lnkup-marketplace' ), __( 'Track sent invites and resend links', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<p id="lnkup-organizer-invites-summary" class="lnkup-muted lnkup-organizer-module-summary"><?php esc_html_e( 'No invites sent yet.', 'lnkup-marketplace' ); ?></p>
					<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" data-expand-panel="invites"><?php echo esc_html( lnkup_copy( 'invite_vendors' ) ); ?></button>
					<?php echo self::organizer_expand_panel_open( 'invites' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="lnkup-crm-invites-toolbar">
							<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" id="lnkup-crm-invite-from-panel"><?php esc_html_e( 'Invite a vendor', 'lnkup-marketplace' ); ?></button>
							<p class="lnkup-muted lnkup-crm-invites-toolbar__hint"><?php esc_html_e( 'Pick a vendor from Discover Vendors, then choose which event to invite them to.', 'lnkup-marketplace' ); ?></p>
						</div>
						<details class="lnkup-crm-bulk-outreach" id="lnkup-crm-bulk-outreach" data-testid="lnkup-crm-bulk-outreach">
							<summary class="lnkup-btn lnkup-btn--sm lnkup-btn--outline"><?php esc_html_e( 'Bulk invite by email', 'lnkup-marketplace' ); ?></summary>
							<form id="lnkup-crm-bulk-outreach-form" class="lnkup-crm-slide-form lnkup-crm-bulk-outreach__form">
								<p class="lnkup-muted"><?php esc_html_e( 'Invite prospects who are not on LNKup yet. They will receive a sign-up email with a tracked link.', 'lnkup-marketplace' ); ?></p>
								<?php
								$outreach_settings = lnkup_get_settings();
								$outreach_daily    = (int) ( $outreach_settings['outreach_daily_limit'] ?? 1500 );
								if ( ! empty( $outreach_settings['outreach_rate_limit_enabled'] ) ) :
									?>
									<p class="lnkup-muted lnkup-crm-bulk-outreach__limit-note"><?php printf( esc_html__( 'Invites send gradually to protect deliverability (up to %d per day).', 'lnkup-marketplace' ), $outreach_daily ); ?></p>
								<?php endif; ?>
								<label class="lnkup-form-row"><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?>
									<select name="event_id" id="lnkup-crm-bulk-event"></select>
								</label>
								<label class="lnkup-form-row"><?php esc_html_e( 'Opportunity', 'lnkup-marketplace' ); ?>
									<select name="opportunity_id" id="lnkup-crm-bulk-opp"></select>
								</label>
								<label class="lnkup-form-row"><?php esc_html_e( 'Email addresses', 'lnkup-marketplace' ); ?>
									<textarea name="emails" id="lnkup-crm-bulk-emails" rows="5" placeholder="vendor@example.com&#10;name@company.com, Jane Doe" required></textarea>
								</label>
								<label class="lnkup-form-row"><?php esc_html_e( 'Personal message (optional)', 'lnkup-marketplace' ); ?>
									<textarea name="message" id="lnkup-crm-bulk-message" rows="2"></textarea>
								</label>
								<div class="lnkup-crm-bulk-outreach__actions">
									<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Send invites', 'lnkup-marketplace' ); ?></button>
								</div>
							</form>
						</details>
						<div id="lnkup-crm-outreach-campaigns" class="lnkup-crm-outreach-campaigns" data-testid="lnkup-crm-outreach-campaigns"></div>
						<div id="lnkup-crm-invites"></div>
					<?php echo self::organizer_expand_panel_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo self::organizer_module_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php echo self::organizer_module_card_open( 'intelligence', __( 'Event Intelligence', 'lnkup-marketplace' ), __( 'Benchmarks, funnel health, and insights', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div id="lnkup-organizer-intel-summary" class="lnkup-organizer-intel-summary" data-loaded="1" data-ssr="1">
						<?php echo self::render_organizer_intel_summary_ssr( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" data-expand-panel="intelligence"><?php echo esc_html( lnkup_copy( 'view_insights' ) ); ?></button>
					<?php echo self::organizer_expand_panel_open( 'intelligence' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div id="lnkup-organizer-intelligence" class="lnkup-intel-shell" data-organizer-id="<?php echo esc_attr( (string) $user_id ); ?>" aria-live="polite">
							<div class="lnkup-crm-skeleton lnkup-crm-skeleton--tall" aria-hidden="true"></div>
						</div>
					<?php echo self::organizer_expand_panel_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo self::organizer_module_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<section id="create" class="lnkup-organizer-launch-card lnkup-panel lnkup-organizer-section" data-section="create">
				<?php
				echo lnkup_executive_hero(
					array(
						'title'    => lnkup_copy( 'launch_opportunity_title' ),
						'subtitle' => lnkup_copy( 'launch_opportunity_copy' ),
						'primary_cta' => array(
							'label' => lnkup_copy( 'start_event_setup' ),
							'href'  => $dash_url . '#create',
						),
					)
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<h2 class="lnkup-panel__title screen-reader-text"><?php echo esc_html( lnkup_copy( 'launch_opportunity_title' ) ); ?></h2>
				<div id="lnkup-launch-success-root" class="lnkup-launch-success" hidden></div>
				<div class="lnkup-organizer-create-launcher" id="lnkup-create-launcher">
					<p class="lnkup-muted"><?php echo esc_html( lnkup_copy( 'launch_opportunity_copy' ) ); ?></p>
					<button type="button" class="lnkup-btn lnkup-btn--primary" data-open-create><?php echo esc_html( lnkup_copy( 'start_event_setup' ) ); ?></button>
				</div>
				<div id="lnkup-create-panel-wrap" class="lnkup-organizer-expand-panel" hidden>
					<?php echo self::render_create_panel( $events ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</section>

			<section id="financial" class="lnkup-organizer-section-card lnkup-panel" data-section="financial">
				<div class="lnkup-organizer-section-card__header">
					<div class="lnkup-organizer-section-card__head">
						<h2 class="lnkup-panel__title"><?php echo esc_html( lnkup_copy( 'financial_center' ) ); ?></h2>
						<p class="lnkup-organizer-section-card__desc lnkup-muted"><?php esc_html_e( 'Wallet balance, payout schedule, and organizer revenue', 'lnkup-marketplace' ); ?></p>
					</div>
					<div class="lnkup-organizer-section-card__actions">
						<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-expand-panel="financial"><?php echo esc_html( lnkup_copy( 'open_financial_center' ) ); ?></button>
					</div>
				</div>
				<?php echo self::organizer_expand_panel_open( 'financial' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php
					echo lnkup_executive_hero(
						array(
							'title'     => lnkup_copy( 'financial_center' ),
							'subtitle'  => __( 'Wallet balance, payout schedule, and organizer revenue', 'lnkup-marketplace' ),
							'kpi'       => lnkup_currency( $collected ),
							'kpi_label' => lnkup_copy( 'vendor_revenue' ),
							'metrics'   => array(
								array( 'label' => __( 'Live events', 'lnkup-marketplace' ), 'value' => (string) $live_events_count ),
							),
						)
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					<?php echo LNKup_Financial_Render::organizer_shell(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo self::organizer_expand_panel_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</section>

			<section id="referrals" class="lnkup-organizer-section-card lnkup-panel" data-section="referrals">
				<div class="lnkup-organizer-section-card__header">
					<div class="lnkup-organizer-section-card__head">
						<h2 class="lnkup-panel__title"><?php esc_html_e( 'Referral Invites', 'lnkup-marketplace' ); ?></h2>
						<p class="lnkup-organizer-section-card__desc lnkup-muted"><?php esc_html_e( 'Invite outside organizers — earn on LNKup fees for 12 months', 'lnkup-marketplace' ); ?></p>
					</div>
				</div>
				<?php
				self::enqueue_organizer_recruit_assets();
				echo LNKup_Organizer_Recruit_Render::hub_shell( 'organizer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<p class="lnkup-muted" style="margin-top:0.75rem;">
					<?php esc_html_e( 'CRM Invites (vendors to your events) stay under Invites — this hub is for recruiting organizers.', 'lnkup-marketplace' ); ?>
				</p>
			</section>

			<?php
			if ( class_exists( 'LNKup_Knowledge_Base' ) ) :
				?>
			<section id="help" class="lnkup-organizer-section-card lnkup-panel" data-section="help">
				<?php echo LNKup_Knowledge_Base::render( 'organizer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</section>
				<?php
			endif;
			?>

			<div id="lnkup-intel-drawer" class="lnkup-intel-drawer" hidden aria-hidden="true">
				<div class="lnkup-intel-drawer__panel" role="dialog" aria-modal="true">
					<button type="button" class="lnkup-intel-drawer__close" id="lnkup-intel-drawer-close">&times;</button>
					<div id="lnkup-intel-drawer-content"></div>
				</div>
			</div>

			<div id="lnkup-vendor-import-wizard" class="lnkup-vendor-import" hidden data-testid="lnkup-vendor-import-wizard" aria-hidden="true">
				<div class="lnkup-vendor-import__overlay" data-import-close></div>
				<div class="lnkup-vendor-import__panel" role="dialog" aria-modal="true" aria-labelledby="lnkup-import-wizard-title">
					<header class="lnkup-vendor-import__header">
						<h2 id="lnkup-import-wizard-title"><?php esc_html_e( 'Import vendors', 'lnkup-marketplace' ); ?></h2>
						<button type="button" class="lnkup-vendor-import__close" data-import-close aria-label="<?php esc_attr_e( 'Close', 'lnkup-marketplace' ); ?>">&times;</button>
					</header>
					<nav class="lnkup-vendor-import__steps" aria-label="<?php esc_attr_e( 'Import steps', 'lnkup-marketplace' ); ?>">
						<span class="lnkup-vendor-import__step is-active" data-import-step-indicator="1"><?php esc_html_e( 'Upload', 'lnkup-marketplace' ); ?></span>
						<span class="lnkup-vendor-import__step" data-import-step-indicator="2"><?php esc_html_e( 'Map', 'lnkup-marketplace' ); ?></span>
						<span class="lnkup-vendor-import__step" data-import-step-indicator="3"><?php esc_html_e( 'Review', 'lnkup-marketplace' ); ?></span>
						<span class="lnkup-vendor-import__step" data-import-step-indicator="4"><?php esc_html_e( 'Results', 'lnkup-marketplace' ); ?></span>
					</nav>
					<div class="lnkup-vendor-import__body">
						<section class="lnkup-vendor-import__pane is-active" data-import-step="1" data-testid="lnkup-import-step-upload">
							<p class="lnkup-muted"><?php esc_html_e( 'Upload a CSV of vendors from a spreadsheet or export. We never email anyone until you choose to invite on the review step.', 'lnkup-marketplace' ); ?></p>
							<div class="lnkup-vendor-import__dropzone" id="lnkup-import-dropzone" data-testid="lnkup-import-dropzone">
								<p><?php esc_html_e( 'Drag and drop your CSV here', 'lnkup-marketplace' ); ?></p>
								<label class="lnkup-btn lnkup-btn--outline lnkup-btn--sm">
									<?php esc_html_e( 'Choose file', 'lnkup-marketplace' ); ?>
									<input type="file" id="lnkup-import-file" accept=".csv,text/csv" hidden />
								</label>
							</div>
							<p class="lnkup-vendor-import__template">
								<a href="<?php echo esc_url( rest_url( 'lnkup/v1/crm/import/template' ) ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-testid="lnkup-import-template-link"><?php esc_html_e( 'Download CSV template', 'lnkup-marketplace' ); ?></a>
							</p>
							<div id="lnkup-import-upload-status" class="lnkup-vendor-import__status" hidden></div>
						</section>
						<section class="lnkup-vendor-import__pane" data-import-step="2" data-testid="lnkup-import-step-map" hidden>
							<p class="lnkup-muted"><?php esc_html_e( 'Match your spreadsheet columns to LNKup fields. Unmapped columns are ignored.', 'lnkup-marketplace' ); ?></p>
							<div id="lnkup-import-mapping" class="lnkup-vendor-import__mapping"></div>
						</section>
						<section class="lnkup-vendor-import__pane" data-import-step="3" data-testid="lnkup-import-step-review" hidden>
							<div id="lnkup-import-summary" class="lnkup-vendor-import__summary-grid"></div>
							<div class="lnkup-vendor-import__options">
								<label class="lnkup-form-row"><?php esc_html_e( 'Duplicate rows', 'lnkup-marketplace' ); ?>
									<select id="lnkup-import-duplicate-mode">
										<option value="skip"><?php esc_html_e( 'Skip duplicates', 'lnkup-marketplace' ); ?></option>
										<option value="update"><?php esc_html_e( 'Update existing records', 'lnkup-marketplace' ); ?></option>
										<option value="list_only"><?php esc_html_e( 'Add to list only', 'lnkup-marketplace' ); ?></option>
									</select>
								</label>
								<label class="lnkup-form-row"><?php esc_html_e( 'Add to list', 'lnkup-marketplace' ); ?>
									<select id="lnkup-import-list-select"><option value=""><?php esc_html_e( 'None', 'lnkup-marketplace' ); ?></option></select>
								</label>
								<label class="lnkup-form-row"><?php esc_html_e( 'Or create new list', 'lnkup-marketplace' ); ?>
									<input type="text" id="lnkup-import-new-list" placeholder="<?php esc_attr_e( 'List name…', 'lnkup-marketplace' ); ?>" />
								</label>
								<label class="lnkup-form-row"><?php esc_html_e( 'Tags (comma-separated)', 'lnkup-marketplace' ); ?>
									<input type="text" id="lnkup-import-tags" placeholder="<?php esc_attr_e( 'preferred, local', 'lnkup-marketplace' ); ?>" />
								</label>
								<label class="lnkup-form-row"><?php esc_html_e( 'Event (for invites)', 'lnkup-marketplace' ); ?>
									<select id="lnkup-import-event"><option value=""><?php esc_html_e( 'None', 'lnkup-marketplace' ); ?></option></select>
								</label>
								<label class="lnkup-form-row"><?php esc_html_e( 'Opportunity', 'lnkup-marketplace' ); ?>
									<select id="lnkup-import-opportunity"><option value=""><?php esc_html_e( 'None', 'lnkup-marketplace' ); ?></option></select>
								</label>
								<label class="lnkup-form-row lnkup-vendor-import__invite">
									<input type="checkbox" id="lnkup-import-send-invites" />
									<?php esc_html_e( 'Send invites after import', 'lnkup-marketplace' ); ?>
								</label>
								<label class="lnkup-form-row lnkup-vendor-import__invite-confirm" id="lnkup-import-invite-confirm-wrap" hidden>
									<input type="checkbox" id="lnkup-import-confirm-invites" data-testid="lnkup-import-confirm-invites" />
									<?php esc_html_e( 'I understand invites will be queued and sent gradually per outreach limits.', 'lnkup-marketplace' ); ?>
								</label>
								<p class="lnkup-muted lnkup-vendor-import__no-email-note"><?php esc_html_e( 'No emails are sent until you choose to invite vendors.', 'lnkup-marketplace' ); ?></p>
							</div>
						</section>
						<section class="lnkup-vendor-import__pane" data-import-step="4" data-testid="lnkup-import-step-results" hidden>
							<div id="lnkup-import-results" class="lnkup-vendor-import__results"></div>
							<div class="lnkup-vendor-import__results-actions">
								<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" id="lnkup-import-view-imported"><?php esc_html_e( 'View imported vendors', 'lnkup-marketplace' ); ?></button>
								<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" id="lnkup-import-back-crm" data-import-close><?php esc_html_e( 'Back to CRM', 'lnkup-marketplace' ); ?></button>
							</div>
						</section>
					</div>
					<footer class="lnkup-vendor-import__footer">
						<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" id="lnkup-import-back" hidden><?php esc_html_e( 'Back', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" id="lnkup-import-next" disabled><?php esc_html_e( 'Continue', 'lnkup-marketplace' ); ?></button>
					</footer>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Pre-launch marketplace placeholder.
	 */
	public static function render_prelaunch_marketplace() {
		$launch_label = lnkup_get_launch_date_label();
		$signup_url   = lnkup_registration_url();
		$profile_url  = lnkup_page_url_helper( 'vendor-profile' );
		$preview      = lnkup_get_featured_opportunities( 3, array( 'curate_homepage' => true ) );

		ob_start();
		?>
		<div class="lnkup-mp lnkup-mp-v2 lnkup-mp-prelaunch" data-testid="lnkup-prelaunch-marketplace">
			<header class="lnkup-mp-v2__hero lnkup-dash-header">
				<div class="lnkup-mp-v2__hero-text">
					<p class="lnkup-pill lnkup-pill--launch">
						<span class="lnkup-pill__dot"></span>
						<?php
						echo esc_html(
							$launch_label
								? sprintf(
									/* translators: %s: launch date */
									__( 'Opening %s', 'lnkup-marketplace' ),
									$launch_label
								)
								: __( 'Opening soon', 'lnkup-marketplace' )
						);
						?>
					</p>
					<h1 class="lnkup-dash-header__title"><?php esc_html_e( 'Find Events opens on launch day', 'lnkup-marketplace' ); ?></h1>
					<p class="lnkup-dash-header__subtitle"><?php esc_html_e( 'Create your free account now and build your vendor profile. Browse festivals, markets, and live opportunities when LNKup launches.', 'lnkup-marketplace' ); ?></p>
				</div>
			</header>

			<div class="lnkup-mp-prelaunch__actions">
				<?php if ( is_user_logged_in() ) : ?>
					<a href="<?php echo esc_url( $profile_url ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--lg"><?php esc_html_e( 'Complete your profile', 'lnkup-marketplace' ); ?></a>
				<?php else : ?>
					<a href="<?php echo esc_url( $signup_url ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--lg"><?php esc_html_e( 'Create free account', 'lnkup-marketplace' ); ?></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="lnkup-btn lnkup-btn--outline lnkup-btn--lg"><?php esc_html_e( 'Back to home', 'lnkup-marketplace' ); ?></a>
			</div>

			<?php if ( $preview ) : ?>
			<section class="lnkup-mp-prelaunch__preview" aria-label="<?php esc_attr_e( 'Sneak peek', 'lnkup-marketplace' ); ?>">
				<div class="lnkup-section__intro">
					<p class="lnkup-eyebrow"><?php esc_html_e( 'Sneak peek', 'lnkup-marketplace' ); ?></p>
					<h2 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'Events booking vendors at launch', 'lnkup-marketplace' ); ?></h2>
				</div>
				<div class="lnkup-event-grid lnkup-mp-prelaunch__grid">
					<?php foreach ( $preview as $opp ) : ?>
						<div class="lnkup-mp-prelaunch__card-wrap">
							<?php echo function_exists( 'lnkup_render_opportunity_card_v2' ) ? lnkup_render_opportunity_card_v2( $opp ) : lnkup_render_event_card( $opp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<div class="lnkup-mp-prelaunch__lock" aria-hidden="true">
								<span><?php echo esc_html( $launch_label ? sprintf( __( 'Opens %s', 'lnkup-marketplace' ), $launch_label ) : __( 'Opens soon', 'lnkup-marketplace' ) ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Opportunities feed shortcode — Marketplace V2.
	 */
	public static function render_opportunities_feed() {
		if ( ! lnkup_can_browse_marketplace() ) {
			return self::render_prelaunch_marketplace();
		}
		$apply       = isset( $_GET['apply'] ) ? absint( $_GET['apply'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$phase       = lnkup_get_marketplace_phase();
		$phase_1b    = lnkup_marketplace_phase_at_least( '1b' );
		$phase_1c    = lnkup_marketplace_phase_at_least( '1c' );
		$categories  = get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false ) );
		$event_types = lnkup_get_event_type_parents();
		$event_type_tree = lnkup_get_event_type_tree();
		$markets     = get_terms( array( 'taxonomy' => 'lnkup_market', 'hide_empty' => false ) );
		$filter_args = lnkup_parse_marketplace_filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$initial     = lnkup_get_filtered_opportunities( $filter_args );
		$market_page = null;
		if ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_enabled() && ! empty( $filter_args['city'] ) ) {
			$market_page = LNKup_Market_Pages::find_by_city_state( (string) $filter_args['city'], (string) ( $filter_args['state'] ?? '' ) );
		}
		$search_q    = $filter_args['q'] ?? '';
		$current_sort = $filter_args['sort'] ?? ( $search_q ? 'relevance' : 'newest' );
		$quick_chip_types = array( 'Festivals', 'Conferences', 'Markets & Pop-Ups', 'Campus Events', 'Corporate & Brand' );
		$featured_event_types = array();
		if ( $event_types ) {
			$type_names = wp_list_pluck( $event_types, 'name' );
			foreach ( $quick_chip_types as $chip_name ) {
				if ( in_array( $chip_name, $type_names, true ) ) {
					$featured_event_types[] = $chip_name;
				}
			}
		}
		$invite_token = isset( $_GET['invite'] ) ? sanitize_text_field( wp_unslash( $_GET['invite'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$explore_collections = array();
		if ( $phase_1b && class_exists( 'LNKup_Collections' ) ) {
			$coll_defs      = LNKup_Collections::get_definitions();
			$featured_slugs = array( 'food-festivals', 'college-events', 'top-rodeos', 'weekend', 'under-100', 'large-festivals', 'indoor-markets', 'family-events' );
			foreach ( $featured_slugs as $coll_slug ) {
				if ( empty( $coll_defs[ $coll_slug ] ) ) {
					continue;
				}
				$coll_def = $coll_defs[ $coll_slug ];
				$explore_collections[] = array(
					'slug'    => $coll_slug,
					'label'   => $coll_def['label'],
					'filters' => $coll_def['filters'] ?? array(),
					'section' => $coll_def['section'] ?? '',
				);
			}
		}
		$invite_banner = '';
		if ( $invite_token && class_exists( 'LNKup_Vendor_Invites' ) ) {
			LNKup_Vendor_Invites::mark_opened( $invite_token );
			$invite_post = LNKup_Vendor_Invites::get_by_token( $invite_token );
			if ( $invite_post ) {
				$invite_status = get_post_meta( $invite_post->ID, '_lnkup_status', true );
				$opp_id        = (int) get_post_meta( $invite_post->ID, '_lnkup_opportunity_id', true );
				$event_id      = (int) get_post_meta( $invite_post->ID, '_lnkup_event_id', true );
				$event_title   = $event_id ? get_the_title( $event_id ) : '';
				$message       = get_post_meta( $invite_post->ID, '_lnkup_invite_message', true );
				ob_start();
				?>
				<div class="lnkup-alert lnkup-alert--info lnkup-invite-banner" id="lnkup-invite-banner" data-token="<?php echo esc_attr( $invite_token ); ?>">
					<p><strong><?php esc_html_e( 'You have an event invite', 'lnkup-marketplace' ); ?></strong><?php if ( $event_title ) : ?> — <?php echo esc_html( $event_title ); ?><?php endif; ?></p>
					<?php if ( $message ) : ?><p><?php echo esc_html( $message ); ?></p><?php endif; ?>
					<?php if ( in_array( $invite_status, array( 'sent', 'opened' ), true ) ) : ?>
						<div class="lnkup-invite-banner__actions">
							<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" id="lnkup-invite-accept"><?php esc_html_e( 'Accept Invite', 'lnkup-marketplace' ); ?></button>
							<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" id="lnkup-invite-decline"><?php esc_html_e( 'Decline', 'lnkup-marketplace' ); ?></button>
							<?php if ( $opp_id ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'apply', $opp_id, lnkup_page_url_helper( 'opportunities' ) ) ); ?>" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm"><?php esc_html_e( 'Apply Now', 'lnkup-marketplace' ); ?></a>
							<?php endif; ?>
						</div>
					<?php else : ?>
						<p class="lnkup-muted"><?php echo esc_html( ucfirst( $invite_status ) ); ?></p>
					<?php endif; ?>
				</div>
				<?php
				$invite_banner = ob_get_clean();
			}
		}

		ob_start();
		?>
		<?php echo $invite_banner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php if ( lnkup_is_prelaunch_admin_preview() ) : ?>
			<div class="lnkup-alert lnkup-alert--info lnkup-mp-v2__admin-preview" data-testid="lnkup-admin-prelaunch-preview">
				<p>
					<strong><?php esc_html_e( 'Admin preview', 'lnkup-marketplace' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: launch date */
							__( 'You are viewing the marketplace before public launch on %s. Vendors still see the waitlist experience.', 'lnkup-marketplace' ),
							lnkup_get_launch_date_label()
						)
					);
					?>
				</p>
			</div>
		<?php endif; ?>
		<?php if ( $market_page ) : ?>
			<div class="lnkup-mp-v2__market-chip" data-testid="lnkup-market-page-chip">
				<a href="<?php echo esc_url( $market_page['url'] ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: city display name */
							__( 'Explore the %s market page', 'lnkup-marketplace' ),
							$market_page['display_name']
						)
					);
					?>
				</a>
			</div>
		<?php endif; ?>
		<div class="lnkup-mp lnkup-mp-v2" id="lnkup-marketplace" data-rest="<?php echo esc_url( rest_url( 'lnkup/v1/marketplace' ) ); ?>" data-phase="<?php echo esc_attr( $phase ); ?>"<?php echo $apply ? ' data-initial-apply="' . esc_attr( (string) $apply ) . '"' : ''; ?><?php echo $market_page ? ' data-market-page-url="' . esc_attr( $market_page['url'] ) . '"' : ''; ?>>
			<header class="lnkup-mp-v2__hero lnkup-dash-header">
				<?php if ( is_user_logged_in() && class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_vendor() ) : ?>
					<?php
					echo lnkup_dash_breadcrumb(
						array(
							array(
								'label' => __( 'Home', 'lnkup-marketplace' ),
								'href'  => lnkup_page_url_helper( 'vendor-dashboard' ),
							),
							array(
								'label' => __( 'Find Events', 'lnkup-marketplace' ),
							),
						)
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				<?php endif; ?>
				<div class="lnkup-mp-v2__hero-text">
					<h1 class="lnkup-dash-header__title"><?php echo esc_html( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'find_events' ) : __( 'Find Events', 'lnkup-marketplace' ) ); ?></h1>
					<p class="lnkup-dash-header__subtitle"><?php echo esc_html( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'find_events_subtitle' ) : __( 'Apply for free. Pay only when accepted.', 'lnkup-marketplace' ) ); ?></p>
				</div>
				<p class="lnkup-muted lnkup-mp-v2__count" id="lnkup-result-count"><?php echo esc_html( sprintf( __( '%d opportunities', 'lnkup-marketplace' ), (int) $initial['total'] ) ); ?></p>
			</header>

			<div class="lnkup-mp-v2__filter-bar lnkup-mp-v2__filter-bar--sticky lnkup-surface--secondary lnkup-filter-bar--sticky">
				<form class="lnkup-mp-v2__search-bar" id="lnkup-search-form" role="search">
					<div class="lnkup-mp-v2__search-wrap">
						<input type="search" id="lnkup-search-input" name="q" class="lnkup-mp-v2__search" placeholder="<?php esc_attr_e( 'Search events, organizers, cities, categories…', 'lnkup-marketplace' ); ?>" autocomplete="off" value="<?php echo esc_attr( $search_q ); ?>" />
						<?php if ( $phase_1b ) : ?>
						<div class="lnkup-mp-v2__suggestions" id="lnkup-suggestions" hidden></div>
						<?php endif; ?>
					</div>
					<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-mp-v2__search-btn" id="lnkup-search-submit"><?php esc_html_e( 'Search', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-mp-v2__filter-toggle" id="lnkup-filter-toggle" aria-expanded="false"><?php esc_html_e( 'Filters', 'lnkup-marketplace' ); ?></button>
				</form>

				<div class="lnkup-mp-v2__toolbar lnkup-mp-v2__toolbar--desktop">
					<?php if ( $event_type_tree ) : ?>
					<select id="lnkup-filter-event-type-parent" class="lnkup-mp-v2__sort" aria-label="<?php esc_attr_e( 'Event category', 'lnkup-marketplace' ); ?>">
						<option value=""><?php esc_html_e( 'All categories', 'lnkup-marketplace' ); ?></option>
						<?php foreach ( $event_type_tree as $parent_group ) : ?>
							<option value="<?php echo esc_attr( $parent_group['name'] ); ?>"><?php echo esc_html( $parent_group['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<select id="lnkup-filter-event-type" class="lnkup-mp-v2__sort" data-filter="event_types_select" aria-label="<?php esc_attr_e( 'Event subtype', 'lnkup-marketplace' ); ?>">
						<option value=""><?php esc_html_e( 'All subtypes', 'lnkup-marketplace' ); ?></option>
					</select>
					<p class="lnkup-muted lnkup-mp-v2__type-hint" id="lnkup-filter-event-type-hint" hidden><?php esc_html_e( 'Choose a category to filter by subtype.', 'lnkup-marketplace' ); ?></p>
					<?php endif; ?>
					<select id="lnkup-filter-attendance" class="lnkup-mp-v2__sort" data-filter="attendance_bucket" aria-label="<?php esc_attr_e( 'Expected attendance', 'lnkup-marketplace' ); ?>">
						<option value=""><?php esc_html_e( 'Any attendance', 'lnkup-marketplace' ); ?></option>
						<option value="0-500" <?php selected( $filter_args['attendance_bucket'] ?? '', '0-500' ); ?>>0–500</option>
						<option value="500-1000" <?php selected( $filter_args['attendance_bucket'] ?? '', '500-1000' ); ?>>500–1k</option>
						<option value="1000-5000" <?php selected( $filter_args['attendance_bucket'] ?? '', '1000-5000' ); ?>>1k–5k</option>
						<option value="5000-10000" <?php selected( $filter_args['attendance_bucket'] ?? '', '5000-10000' ); ?>>5k–10k</option>
						<option value="10000+" <?php selected( $filter_args['attendance_bucket'] ?? '', '10000+' ); ?>>10k+</option>
					</select>
					<select id="lnkup-sort" class="lnkup-mp-v2__sort">
						<option value="relevance" <?php selected( $current_sort, 'relevance' ); ?>><?php esc_html_e( 'Best Match', 'lnkup-marketplace' ); ?></option>
						<option value="recommended" <?php selected( $current_sort, 'recommended' ); ?>><?php esc_html_e( 'Recommended', 'lnkup-marketplace' ); ?></option>
						<option value="newest" <?php selected( $current_sort, 'newest' ); ?>><?php esc_html_e( 'Newest', 'lnkup-marketplace' ); ?></option>
						<option value="soonest" <?php selected( $current_sort, 'soonest' ); ?>><?php esc_html_e( 'Soonest', 'lnkup-marketplace' ); ?></option>
						<option value="deadline" <?php selected( $current_sort, 'deadline' ); ?>><?php esc_html_e( 'Closing Soon', 'lnkup-marketplace' ); ?></option>
						<option value="fee_low" <?php selected( $current_sort, 'fee_low' ); ?>><?php esc_html_e( 'Lowest Fee', 'lnkup-marketplace' ); ?></option>
						<option value="attendance" <?php selected( $current_sort, 'attendance' ); ?>><?php esc_html_e( 'Highest Attendance', 'lnkup-marketplace' ); ?></option>
						<option value="spots" <?php selected( $current_sort, 'spots' ); ?>><?php esc_html_e( 'Most Spots Remaining', 'lnkup-marketplace' ); ?></option>
						<option value="popular" <?php selected( $current_sort, 'popular' ); ?>><?php esc_html_e( 'Most Popular', 'lnkup-marketplace' ); ?></option>
						<option value="most_applied" <?php selected( $current_sort, 'most_applied' ); ?>><?php esc_html_e( 'Most Applied', 'lnkup-marketplace' ); ?></option>
						<option value="closest" <?php selected( $current_sort, 'closest' ); ?>><?php esc_html_e( 'Closest', 'lnkup-marketplace' ); ?></option>
						<?php if ( class_exists( 'LNKup_External_Events' ) ) : ?>
						<option value="high_demand" <?php selected( $current_sort, 'high_demand' ); ?>><?php esc_html_e( 'High Demand (external)', 'lnkup-marketplace' ); ?></option>
						<option value="recently_requested" <?php selected( $current_sort, 'recently_requested' ); ?>><?php esc_html_e( 'Recently Requested (external)', 'lnkup-marketplace' ); ?></option>
						<?php endif; ?>
					</select>
					<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" id="lnkup-save-search"><?php esc_html_e( 'Save this search', 'lnkup-marketplace' ); ?></button>
				</div>
			</div>

			<div class="lnkup-mp-v2__quick-chips" id="lnkup-quick-chips">
				<?php foreach ( $featured_event_types as $chip_type ) : ?>
					<button type="button" class="lnkup-badge lnkup-badge--secondary lnkup-quick-chip" data-quick-filter="<?php echo esc_attr( wp_json_encode( array( 'event_types' => $chip_type ) ) ); ?>"><?php echo esc_html( $chip_type ); ?></button>
				<?php endforeach; ?>
				<button type="button" class="lnkup-badge lnkup-badge--secondary lnkup-quick-chip" data-quick-filter='{"date_preset":"weekend"}'><?php esc_html_e( 'This Weekend', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-badge lnkup-badge--secondary lnkup-quick-chip" data-quick-filter='{"closing_soon":true}'><?php esc_html_e( 'Closing Soon', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-badge lnkup-badge--secondary lnkup-quick-chip" data-quick-filter='{"fee_bucket":"under_100"}'><?php esc_html_e( 'Low Fee', 'lnkup-marketplace' ); ?></button>
			</div>

			<div class="lnkup-mp-v2__active-filters" id="lnkup-active-filters"<?php echo empty( $initial['active_filters'] ) ? ' hidden' : ''; ?>>
				<?php foreach ( (array) $initial['active_filters'] as $chip ) : ?>
					<button type="button" class="lnkup-badge lnkup-badge--outline lnkup-active-filter-chip" data-filter-key="<?php echo esc_attr( $chip['key'] ); ?>" data-filter-value="<?php echo esc_attr( $chip['value'] ?? '' ); ?>"><?php echo esc_html( $chip['label'] ); ?> ×</button>
				<?php endforeach; ?>
				<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" id="lnkup-clear-filters-inline"><?php esc_html_e( 'Clear all', 'lnkup-marketplace' ); ?></button>
			</div>

			<aside class="lnkup-mp-v2__filter-drawer lnkup-mp-v2__filter-drawer--closed" id="lnkup-filter-drawer">
				<div class="lnkup-mp-v2__filter-inner">
					<h3><?php esc_html_e( 'Filters', 'lnkup-marketplace' ); ?></h3>
					<label><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?><input type="text" name="city" data-filter="city" value="<?php echo esc_attr( $filter_args['city'] ?? '' ); ?>" /></label>
					<label><?php esc_html_e( 'State', 'lnkup-marketplace' ); ?><input type="text" name="state" data-filter="state" maxlength="2" value="<?php echo esc_attr( $filter_args['state'] ?? '' ); ?>" /></label>
					<?php if ( $markets && ! is_wp_error( $markets ) ) : ?>
					<label><?php esc_html_e( 'Market', 'lnkup-marketplace' ); ?>
						<select data-filter="market_select">
							<option value=""><?php esc_html_e( 'Any market', 'lnkup-marketplace' ); ?></option>
							<?php foreach ( $markets as $term ) : ?>
								<option value="<?php echo esc_attr( $term->name ); ?>"><?php echo esc_html( $term->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<?php endif; ?>
					<label><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?>
						<select data-filter="date_preset">
							<option value=""><?php esc_html_e( 'Any time', 'lnkup-marketplace' ); ?></option>
							<option value="today"><?php esc_html_e( 'Today', 'lnkup-marketplace' ); ?></option>
							<option value="tomorrow"><?php esc_html_e( 'Tomorrow', 'lnkup-marketplace' ); ?></option>
							<option value="weekend"><?php esc_html_e( 'This Weekend', 'lnkup-marketplace' ); ?></option>
							<option value="this_week"><?php esc_html_e( 'This Week', 'lnkup-marketplace' ); ?></option>
							<option value="this_month"><?php esc_html_e( 'This Month', 'lnkup-marketplace' ); ?></option>
							<option value="next_30"><?php esc_html_e( 'Next 30 Days', 'lnkup-marketplace' ); ?></option>
						</select>
					</label>
					<label><?php esc_html_e( 'Custom start', 'lnkup-marketplace' ); ?><input type="date" data-filter="date_from" value="<?php echo esc_attr( $filter_args['date_from'] ?? '' ); ?>" /></label>
					<label><?php esc_html_e( 'Custom end', 'lnkup-marketplace' ); ?><input type="date" data-filter="date_to" value="<?php echo esc_attr( $filter_args['date_to'] ?? '' ); ?>" /></label>
					<label><?php esc_html_e( 'Attendance', 'lnkup-marketplace' ); ?>
						<select data-filter="attendance_bucket">
							<option value=""><?php esc_html_e( 'Any', 'lnkup-marketplace' ); ?></option>
							<option value="0-500">0–500</option>
							<option value="500-1000">500–1k</option>
							<option value="1000-5000">1k–5k</option>
							<option value="5000-10000">5k–10k</option>
							<option value="10000+">10k+</option>
						</select>
					</label>
					<label><?php esc_html_e( 'Fee range', 'lnkup-marketplace' ); ?>
						<select data-filter="fee_bucket">
							<option value=""><?php esc_html_e( 'Any fee', 'lnkup-marketplace' ); ?></option>
							<option value="free"><?php esc_html_e( 'Free', 'lnkup-marketplace' ); ?></option>
							<option value="under_100"><?php esc_html_e( 'Under $100', 'lnkup-marketplace' ); ?></option>
							<option value="100_250">$100–$250</option>
							<option value="250_500">$250–$500</option>
							<option value="500_1000">$500–$1,000</option>
							<option value="1000_plus">$1,000+</option>
						</select>
					</label>
					<label><?php esc_html_e( 'Custom min fee ($)', 'lnkup-marketplace' ); ?><input type="number" data-filter="fee_min" min="0" value="<?php echo esc_attr( $filter_args['fee_min'] ?? '' ); ?>" /></label>
					<label><?php esc_html_e( 'Custom max fee ($)', 'lnkup-marketplace' ); ?><input type="number" data-filter="fee_max" min="0" value="<?php echo esc_attr( $filter_args['fee_max'] ?? '' ); ?>" /></label>
					<label><?php esc_html_e( 'Environment', 'lnkup-marketplace' ); ?>
						<select data-filter="environment">
							<option value=""><?php esc_html_e( 'Any', 'lnkup-marketplace' ); ?></option>
							<option value="indoor"><?php esc_html_e( 'Indoor', 'lnkup-marketplace' ); ?></option>
							<option value="outdoor"><?php esc_html_e( 'Outdoor', 'lnkup-marketplace' ); ?></option>
							<option value="both"><?php esc_html_e( 'Indoor/Outdoor', 'lnkup-marketplace' ); ?></option>
							<option value="tented"><?php esc_html_e( 'Tented', 'lnkup-marketplace' ); ?></option>
							<option value="rain_or_shine"><?php esc_html_e( 'Rain or Shine', 'lnkup-marketplace' ); ?></option>
						</select>
					</label>
					<fieldset>
						<legend><?php esc_html_e( 'Documents', 'lnkup-marketplace' ); ?></legend>
						<label><input type="checkbox" data-filter-doc="coi" /> <?php esc_html_e( 'COI Required', 'lnkup-marketplace' ); ?></label>
						<label><input type="checkbox" data-filter-doc="health_permit" /> <?php esc_html_e( 'Health Permit', 'lnkup-marketplace' ); ?></label>
						<label><input type="checkbox" data-filter-doc="business_license" /> <?php esc_html_e( 'Business License', 'lnkup-marketplace' ); ?></label>
						<label><input type="checkbox" data-filter-doc="w9" /> <?php esc_html_e( 'W9 Required', 'lnkup-marketplace' ); ?></label>
						<label><input type="checkbox" data-filter="doc_none" /> <?php esc_html_e( 'No Documents Required', 'lnkup-marketplace' ); ?></label>
					</fieldset>
					<label><input type="checkbox" data-filter="verified_organizer" /> <?php esc_html_e( 'Verified Organizer', 'lnkup-marketplace' ); ?></label>
					<label><input type="checkbox" data-filter="featured_organizer" /> <?php esc_html_e( 'Featured Organizer', 'lnkup-marketplace' ); ?></label>
					<label><input type="checkbox" data-filter="instant_approval" /> <?php esc_html_e( 'Instant Approval', 'lnkup-marketplace' ); ?></label>
					<label><input type="checkbox" data-filter="has_spots" checked /> <?php esc_html_e( 'Has Spots', 'lnkup-marketplace' ); ?></label>
					<label><input type="checkbox" data-filter="newly_added" /> <?php esc_html_e( 'Newly Added', 'lnkup-marketplace' ); ?></label>
					<label><input type="checkbox" data-filter="closing_soon" /> <?php esc_html_e( 'Closing Soon', 'lnkup-marketplace' ); ?></label>
					<label><input type="checkbox" data-filter="almost_full" /> <?php esc_html_e( 'Almost Full', 'lnkup-marketplace' ); ?></label>
					<label><input type="checkbox" data-filter="show_closed" /> <?php esc_html_e( 'Show closed opportunities', 'lnkup-marketplace' ); ?></label>
					<?php if ( $event_type_tree ) : ?>
						<fieldset>
							<legend><?php esc_html_e( 'Event Types', 'lnkup-marketplace' ); ?></legend>
							<?php foreach ( $event_type_tree as $parent_group ) : ?>
								<div class="lnkup-mp-v2__filter-group">
									<label class="lnkup-mp-v2__filter-parent">
										<input type="checkbox" data-filter-event-type="<?php echo esc_attr( $parent_group['name'] ); ?>" />
										<?php echo esc_html( $parent_group['name'] ); ?>
									</label>
									<?php foreach ( $parent_group['children'] as $child_group ) : ?>
										<label class="lnkup-mp-v2__filter-child">
											<input type="checkbox" data-filter-event-type="<?php echo esc_attr( $child_group['name'] ); ?>" />
											<?php echo esc_html( $child_group['name'] ); ?>
										</label>
									<?php endforeach; ?>
								</div>
							<?php endforeach; ?>
						</fieldset>
					<?php endif; ?>
					<?php if ( ! is_wp_error( $categories ) && $categories ) : ?>
						<fieldset>
							<legend><?php esc_html_e( 'Vendor Categories', 'lnkup-marketplace' ); ?></legend>
							<?php foreach ( $categories as $term ) : ?>
								<label><input type="checkbox" data-filter-cat="<?php echo esc_attr( $term->name ); ?>" /> <?php echo esc_html( $term->name ); ?></label>
							<?php endforeach; ?>
						</fieldset>
					<?php endif; ?>
					<?php if ( class_exists( 'LNKup_External_Events' ) ) : ?>
					<fieldset>
						<legend><?php esc_html_e( 'External listings', 'lnkup-marketplace' ); ?></legend>
						<label><input type="checkbox" data-filter="include_external" checked /> <?php esc_html_e( 'Include external events', 'lnkup-marketplace' ); ?></label>
						<label><input type="checkbox" data-filter="lnkup_only" /> <?php esc_html_e( 'LNKup listings only', 'lnkup-marketplace' ); ?></label>
						<label><input type="checkbox" data-filter="high_demand" /> <?php esc_html_e( 'High demand external only', 'lnkup-marketplace' ); ?></label>
					</fieldset>
					<?php endif; ?>
					<div class="lnkup-mp-v2__filter-actions">
						<button type="button" class="lnkup-btn lnkup-btn--primary" id="lnkup-apply-filters"><?php esc_html_e( 'Apply Filters', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--ghost" id="lnkup-clear-filters"><?php esc_html_e( 'Clear All', 'lnkup-marketplace' ); ?></button>
					</div>
				</div>
			</aside>

			<button type="button" class="lnkup-mp-v2__fab" id="lnkup-filter-fab" aria-label="<?php esc_attr_e( 'Filters', 'lnkup-marketplace' ); ?>">☰</button>

			<div class="lnkup-mp-v2__view-row">
				<div class="lnkup-mp-v2__view-toggle">
					<button type="button" class="lnkup-btn lnkup-btn--sm is-active" data-view="grid"><?php esc_html_e( 'Grid', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--sm" data-view="list"><?php esc_html_e( 'List', 'lnkup-marketplace' ); ?></button>
					<?php if ( $phase_1b ) : ?>
					<button type="button" class="lnkup-btn lnkup-btn--sm" data-view="map" id="lnkup-map-toggle"><?php esc_html_e( 'Map', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--sm" data-view="split" id="lnkup-split-toggle"><?php esc_html_e( 'Split', 'lnkup-marketplace' ); ?></button>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( class_exists( 'LNKup_External_Events' ) ) : ?>
			<p class="lnkup-mp-v2__external-legal" id="lnkup-external-legal"<?php echo empty( $initial['has_external'] ) ? ' hidden' : ''; ?> data-testid="lnkup-external-legal-disclaimer"><?php echo esc_html( lnkup_copy( 'external_event_legal_disclaimer' ) ); ?></p>
			<?php endif; ?>
			<div class="lnkup-mp-v2__split-layout" id="lnkup-split-layout">
			<div class="lnkup-mp-v2__results-view" id="lnkup-grid-view">
				<div class="lnkup-event-grid" id="lnkup-results-grid"><?php echo $initial['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div id="lnkup-pagination"><?php echo $initial['pagination']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="lnkup-mp-v2__loader" id="lnkup-infinite-loader" hidden><?php echo lnkup_render_opportunity_card_skeleton(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</div>
			<?php if ( $phase_1b ) : ?>
			<div class="lnkup-mp-v2__map-view" id="lnkup-map-view" hidden></div>
			<?php endif; ?>
			</div>

			<?php if ( $phase_1b ) : ?>
			<details class="lnkup-mp-v2__explore-more lnkup-discovery-rails" id="lnkup-explore-more" hidden>
				<summary class="lnkup-mp-v2__explore-more-toggle">
					<span class="lnkup-mp-v2__explore-more-heading">
						<span class="lnkup-mp-v2__explore-more-title"><?php esc_html_e( 'Explore more opportunities', 'lnkup-marketplace' ); ?></span>
						<span class="lnkup-mp-v2__explore-more-subtitle lnkup-muted"><?php esc_html_e( 'Trending, recommended, and closing soon', 'lnkup-marketplace' ); ?></span>
					</span>
					<span class="lnkup-mp-v2__explore-more-chevron" aria-hidden="true"></span>
				</summary>
				<div class="lnkup-mp-v2__explore-more-body">
					<?php if ( is_user_logged_in() ) : ?>
					<div class="lnkup-mp-v2__saved-searches" id="lnkup-saved-searches" hidden>
						<span class="lnkup-muted"><?php esc_html_e( 'Saved searches', 'lnkup-marketplace' ); ?></span>
						<div class="lnkup-mp-v2__saved-chips" id="lnkup-saved-search-chips"></div>
					</div>
					<?php endif; ?>
					<div class="lnkup-mp-v2__explore-loading" id="lnkup-explore-loading">
						<div class="lnkup-mp-v2__explore-skeleton"></div>
					</div>
					<div class="lnkup-mp-v2__explore-tabs" id="lnkup-explore-tabs" hidden>
						<div class="lnkup-mp-v2__explore-tablist" id="lnkup-explore-tablist" role="tablist"></div>
						<div class="lnkup-mp-v2__explore-panels" id="lnkup-explore-panels"></div>
					</div>
					<script type="application/json" id="lnkup-explore-collections-data"><?php echo wp_json_encode( $explore_collections ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
				</div>
			</details>
			<?php endif; ?>

			<div class="lnkup-mp-v2__empty" id="lnkup-empty-state"<?php echo $initial['empty_state'] ? '' : ' hidden'; ?>>
				<h3><?php esc_html_e( 'No matching opportunities found', 'lnkup-marketplace' ); ?></h3>
				<p><?php esc_html_e( 'Try removing filters or browse popular categories below.', 'lnkup-marketplace' ); ?></p>
				<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" id="lnkup-empty-clear"><?php esc_html_e( 'Clear all filters', 'lnkup-marketplace' ); ?></button>
				<?php if ( $phase_1b ) : ?>
				<div id="lnkup-empty-suggestions" class="lnkup-event-grid"></div>
				<?php endif; ?>
			</div>

			<div class="lnkup-mp-v2__error" id="lnkup-error-state" hidden>
				<h3><?php esc_html_e( 'We couldn’t load opportunities', 'lnkup-marketplace' ); ?></h3>
				<p class="lnkup-muted"><?php esc_html_e( 'Check your connection and try again.', 'lnkup-marketplace' ); ?></p>
				<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" id="lnkup-error-retry"><?php esc_html_e( 'Retry', 'lnkup-marketplace' ); ?></button>
				<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( lnkup_page_url_helper( 'opportunities' ) ); ?>"><?php esc_html_e( 'Reload page', 'lnkup-marketplace' ); ?></a>
			</div>

			<?php if ( $phase_1c ) : ?>
			<div class="lnkup-mp-v2__compare-tray" id="lnkup-compare-tray" hidden>
				<div class="lnkup-mp-v2__compare-inner" id="lnkup-compare-inner"></div>
				<button type="button" class="lnkup-btn lnkup-btn--primary" id="lnkup-compare-open"><?php esc_html_e( 'Compare', 'lnkup-marketplace' ); ?></button>
			</div>
			<?php endif; ?>

			<div class="lnkup-save-search-modal" id="lnkup-save-search-modal" role="dialog" aria-modal="true" aria-labelledby="lnkup-save-search-modal-title" hidden data-testid="lnkup-save-search-modal">
				<div class="lnkup-save-search-modal__backdrop" data-save-search-close></div>
				<div class="lnkup-save-search-modal__dialog">
					<button type="button" class="lnkup-save-search-modal__close" data-save-search-close aria-label="<?php esc_attr_e( 'Close', 'lnkup-marketplace' ); ?>">&times;</button>
					<div id="lnkup-save-search-logged-out" class="lnkup-save-search-modal__guest" hidden>
						<h2 id="lnkup-save-search-modal-title" class="lnkup-save-search-modal__title"><?php esc_html_e( 'Save opportunity search', 'lnkup-marketplace' ); ?></h2>
						<p class="lnkup-muted"><?php esc_html_e( 'Get alerts when new vendor opportunities match your filters.', 'lnkup-marketplace' ); ?></p>
						<p><?php esc_html_e( 'Create a free vendor profile to save searches.', 'lnkup-marketplace' ); ?></p>
						<a href="<?php echo esc_url( lnkup_registration_url() ); ?>" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Create vendor profile', 'lnkup-marketplace' ); ?></a>
					</div>
					<form id="lnkup-save-search-form" class="lnkup-save-search-modal__form" hidden>
						<h2 id="lnkup-save-search-modal-title-vendor" class="lnkup-save-search-modal__title"><?php esc_html_e( 'Save opportunity search', 'lnkup-marketplace' ); ?></h2>
						<p class="lnkup-muted"><?php esc_html_e( 'Get alerts when new vendor opportunities match your filters.', 'lnkup-marketplace' ); ?></p>
						<label class="lnkup-save-search-modal__field">
							<span><?php esc_html_e( 'Search name', 'lnkup-marketplace' ); ?></span>
							<input type="text" id="lnkup-save-search-name" name="name" maxlength="80" required placeholder="<?php esc_attr_e( 'e.g. Food trucks in Atlanta', 'lnkup-marketplace' ); ?>" />
						</label>
						<div class="lnkup-save-search-modal__summary" id="lnkup-save-search-summary" aria-live="polite"></div>
						<p class="lnkup-save-search-modal__warning" id="lnkup-save-search-empty-warning" hidden><?php esc_html_e( 'This search will match a lot of opportunities. Add filters for better alerts.', 'lnkup-marketplace' ); ?></p>
						<label class="lnkup-save-search-modal__toggle">
							<input type="checkbox" id="lnkup-save-search-alerts" name="alert_enabled" checked />
							<span><?php esc_html_e( 'Email me when new matches are posted', 'lnkup-marketplace' ); ?></span>
						</label>
						<label class="lnkup-save-search-modal__field">
							<span><?php esc_html_e( 'Alert frequency', 'lnkup-marketplace' ); ?></span>
							<select id="lnkup-save-search-frequency" name="frequency">
								<option value="daily"><?php esc_html_e( 'Daily digest', 'lnkup-marketplace' ); ?></option>
								<option value="weekly"><?php esc_html_e( 'Weekly digest', 'lnkup-marketplace' ); ?></option>
								<?php if ( lnkup_marketplace_phase_at_least( '1c' ) ) : ?>
								<option value="instant"><?php esc_html_e( 'As soon as posted', 'lnkup-marketplace' ); ?></option>
								<?php endif; ?>
							</select>
						</label>
						<p class="lnkup-save-search-modal__error" id="lnkup-save-search-error" hidden role="alert"></p>
						<p class="lnkup-save-search-modal__success" id="lnkup-save-search-success" hidden role="status"><?php esc_html_e( 'Saved. New matching opportunities will appear in your dashboard.', 'lnkup-marketplace' ); ?></p>
						<div class="lnkup-save-search-modal__actions">
							<button type="button" class="lnkup-btn lnkup-btn--ghost" data-save-search-close><?php esc_html_e( 'Cancel', 'lnkup-marketplace' ); ?></button>
							<button type="submit" class="lnkup-btn lnkup-btn--primary" id="lnkup-save-search-submit"><?php esc_html_e( 'Save search', 'lnkup-marketplace' ); ?></button>
						</div>
					</form>
				</div>
			</div>

			<div class="lnkup-apply-modal" id="lnkup-apply-modal" role="dialog" aria-modal="true" aria-labelledby="lnkup-apply-modal-title" hidden>
				<div class="lnkup-apply-modal__backdrop" data-apply-close></div>
				<div class="lnkup-apply-modal__dialog">
					<button type="button" class="lnkup-apply-modal__close" data-apply-close aria-label="<?php esc_attr_e( 'Close', 'lnkup-marketplace' ); ?>">&times;</button>
					<div id="lnkup-apply-modal-body"></div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Favorites page shortcode.
	 */
	public static function render_favorites() {
		LNKup_Capabilities::require_login();
		if ( ! lnkup_can_browse_marketplace() ) {
			ob_start();
			?>
			<div class="lnkup-mp" data-testid="lnkup-prelaunch-favorites">
				<h1 class="lnkup-heading lnkup-heading--page"><?php esc_html_e( 'Favorites', 'lnkup-marketplace' ); ?></h1>
				<div class="lnkup-empty-state">
					<p class="lnkup-empty-state__title"><?php esc_html_e( 'Opens on launch day', 'lnkup-marketplace' ); ?></p>
					<p class="lnkup-empty-state__desc"><?php echo esc_html( sprintf( __( 'Save events to your favorites when the marketplace opens %s.', 'lnkup-marketplace' ), lnkup_get_launch_date_label() ) ); ?></p>
					<a href="<?php echo esc_url( lnkup_page_url_helper( 'vendor-profile' ) ); ?>" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Complete your profile', 'lnkup-marketplace' ); ?></a>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}
		$user_id = get_current_user_id();
		$favs    = LNKup_Vendor_Personalization::get_favorite_opportunities( $user_id, 24 );
		$recent  = LNKup_Vendor_Personalization::get_recent_opportunities( $user_id, 8 );

		ob_start();
		?>
		<div class="lnkup-mp">
			<h1 class="lnkup-heading lnkup-heading--page"><?php esc_html_e( 'Favorites', 'lnkup-marketplace' ); ?></h1>
			<section class="lnkup-panel" id="lnkup-saved-searches-panel" data-testid="lnkup-saved-searches-panel">
				<h2 class="lnkup-panel__title"><?php esc_html_e( 'Saved Searches', 'lnkup-marketplace' ); ?></h2>
				<div id="lnkup-saved-searches-dashboard" class="lnkup-saved-searches-dashboard">
					<div class="lnkup-saved-searches-dashboard__loading lnkup-muted"><?php esc_html_e( 'Loading saved searches…', 'lnkup-marketplace' ); ?></div>
				</div>
			</section>
			<section class="lnkup-panel">
				<h2 class="lnkup-panel__title"><?php esc_html_e( 'Recently Favorited', 'lnkup-marketplace' ); ?></h2>
				<div class="lnkup-event-grid">
					<?php if ( $favs ) : ?>
						<?php foreach ( $favs as $opp ) : ?>
							<?php echo lnkup_render_opportunity_card_v2( $opp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					<?php else : ?>
						<p class="lnkup-muted"><?php esc_html_e( 'No favorites yet. Browse the marketplace and tap the heart icon.', 'lnkup-marketplace' ); ?></p>
					<?php endif; ?>
				</div>
			</section>
			<?php if ( $recent ) : ?>
				<section class="lnkup-panel">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Recently Viewed', 'lnkup-marketplace' ); ?></h2>
					<div class="lnkup-event-grid">
						<?php foreach ( $recent as $opp ) : ?>
							<?php echo lnkup_render_opportunity_card_v2( $opp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Vendor profile form shortcode.
	 */
	public static function render_vendor_profile_form() {
		if ( class_exists( 'LNKup_Vendor_Profile_Form' ) ) {
			return LNKup_Vendor_Profile_Form::render();
		}
		return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'Profile editor unavailable.', 'lnkup-marketplace' ) . '</p>';
	}

	/**
	 * Spots filled metrics for an organizer event.
	 *
	 * @param int $event_id Event post ID.
	 */
	private static function organizer_event_spots( $event_id ) {
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		$total     = 0;
		$remaining = 0;
		foreach ( $opps as $opp_id ) {
			$total     += (int) get_post_meta( $opp_id, '_lnkup_spots_total', true );
			$remaining += (int) get_post_meta( $opp_id, '_lnkup_spots_remaining', true );
		}
		$filled = max( 0, $total - $remaining );
		$pct    = $total > 0 ? (int) round( ( $filled / $total ) * 100 ) : 0;
		return array(
			'total'     => $total,
			'filled'    => $filled,
			'remaining' => $remaining,
			'percent'   => $pct,
		);
	}

	/**
	 * Featured image URL for an organizer event card.
	 *
	 * @param int $event_id Event post ID.
	 */
	private static function organizer_event_image_url( $event_id ) {
		$img_id = (int) get_post_meta( $event_id, '_lnkup_featured_image_id', true );
		if ( ! $img_id ) {
			$img_id = (int) get_post_thumbnail_id( $event_id );
		}
		return $img_id ? wp_get_attachment_image_url( $img_id, 'medium_large' ) : '';
	}

	/**
	 * Connect-friendly payout strip for command overview.
	 *
	 * @param float                $available_payout Available balance.
	 * @param float                $collected        Collected revenue.
	 * @param array<string, mixed> $org_verify       Organizer verification payload.
	 * @param string               $dash_url         Dashboard URL.
	 */
	private static function render_organizer_payout_strip( $available_payout, $collected, array $org_verify, $dash_url ) {
		if ( $available_payout <= 0 && $collected <= 0 ) {
			return '';
		}
		$payout_label = ! empty( $org_verify['payout_connected'] )
			? lnkup_copy( 'payout_status_active' )
			: lnkup_copy( 'payout_status_setup' );
		ob_start();
		?>
		<div class="lnkup-organizer-payout-strip lnkup-surface--elevated" data-testid="lnkup-organizer-payout-strip">
			<div class="lnkup-organizer-payout-strip__main">
				<p class="lnkup-type-label"><?php echo esc_html( lnkup_copy( 'available_balance' ) ); ?></p>
				<p class="lnkup-organizer-payout-strip__value"><?php echo esc_html( lnkup_currency( $available_payout ) ); ?></p>
				<p class="lnkup-organizer-payout-strip__meta lnkup-muted">
					<?php
					printf(
						esc_html__( '%1$s: %2$s · %3$s', 'lnkup-marketplace' ),
						esc_html( lnkup_copy( 'collected_to_date' ) ),
						esc_html( lnkup_currency( $collected ) ),
						esc_html( $payout_label )
					);
					?>
				</p>
			</div>
			<div class="lnkup-organizer-payout-strip__actions">
				<a href="<?php echo esc_url( $dash_url . '#financial' ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-expand="financial"><?php echo esc_html( lnkup_copy( 'open_financial_center' ) ); ?></a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Top events by lowest readiness for overview row.
	 *
	 * @param array  $events   Organizer events.
	 * @param string $dash_url Dashboard URL.
	 */
	private static function render_organizer_readiness_row( $events, $dash_url ) {
		if ( ! $events || ! class_exists( 'LNKup_Vendor_Readiness' ) ) {
			return '';
		}
		$ranked = array();
		foreach ( $events as $event ) {
			$summary = LNKup_Vendor_Readiness::event_readiness_summary( $event->ID );
			$percent = (int) ( $summary['percent'] ?? 100 );
			$ranked[] = array(
				'event'   => $event,
				'percent' => $percent,
				'at_risk' => (int) ( $summary['at_risk_count'] ?? 0 ),
			);
		}
		usort(
			$ranked,
			function ( $a, $b ) {
				return $a['percent'] <=> $b['percent'];
			}
		);
		$ranked = array_slice( $ranked, 0, 3 );
		if ( ! $ranked ) {
			return '';
		}
		ob_start();
		?>
		<div class="lnkup-organizer-readiness-row" data-testid="lnkup-organizer-readiness-row">
			<?php foreach ( $ranked as $row ) : ?>
				<?php
				$event   = $row['event'];
				$ops_url = add_query_arg( array( 'event' => $event->ID, 'ops' => '1' ), $dash_url );
				$risk    = $row['at_risk'] > 0;
				?>
				<a href="<?php echo esc_url( $ops_url ); ?>" class="lnkup-organizer-readiness-mini lnkup-micro-lift<?php echo $risk ? ' lnkup-organizer-readiness-mini--risk' : ''; ?>">
					<span class="lnkup-type-meta"><?php echo esc_html( wp_trim_words( $event->post_title, 4, '…' ) ); ?></span>
					<span class="lnkup-organizer-readiness-mini__pct"><?php echo esc_html( (string) $row['percent'] ); ?>%</span>
					<span class="lnkup-muted"><?php echo esc_html( $risk ? sprintf( _n( '%d at risk', '%d at risk', $row['at_risk'], 'lnkup-marketplace' ), $row['at_risk'] ) : __( 'On track', 'lnkup-marketplace' ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Sticky in-page section nav for organizer command center.
	 *
	 * @param string $dash_url Dashboard base URL.
	 */
	private static function render_organizer_section_nav( $dash_url ) {
		$sections = array(
			'overview'     => __( 'Overview', 'lnkup-marketplace' ),
			'events'       => __( 'Events', 'lnkup-marketplace' ),
			'applications' => __( 'Requests', 'lnkup-marketplace' ),
			'vendors'      => __( 'Vendors', 'lnkup-marketplace' ),
			'lists'        => __( 'Lists', 'lnkup-marketplace' ),
			'invites'      => __( 'Invites', 'lnkup-marketplace' ),
			'operations'   => __( 'Operations', 'lnkup-marketplace' ),
			'intelligence' => __( 'Intelligence', 'lnkup-marketplace' ),
			'create'       => __( 'Launch', 'lnkup-marketplace' ),
			'financial'    => __( 'Financials', 'lnkup-marketplace' ),
			'help'         => function_exists( 'lnkup_copy' ) ? lnkup_copy( 'kb_eyebrow' ) : __( 'Help', 'lnkup-marketplace' ),
		);
		ob_start();
		?>
		<nav class="lnkup-organizer-section-nav" aria-label="<?php esc_attr_e( 'Dashboard sections', 'lnkup-marketplace' ); ?>">
			<?php foreach ( $sections as $hash => $label ) : ?>
				<a href="<?php echo esc_url( $dash_url . '#' . $hash ); ?>" class="lnkup-organizer-section-nav__link" data-hash="<?php echo esc_attr( $hash ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		return ob_get_clean();
	}

	/**
	 * First-run welcome modal for organizers (parity with vendor).
	 *
	 * @return string
	 */
	private static function render_organizer_welcome_modal(): string {
		ob_start();
		?>
		<div class="lnkup-modal lnkup-modal--organizer-welcome" id="lnkup-organizer-welcome-modal" data-testid="lnkup-organizer-welcome-modal" hidden>
			<div class="lnkup-modal__backdrop" data-organizer-welcome-close="1"></div>
			<div class="lnkup-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="lnkup-organizer-welcome-title">
				<button type="button" class="lnkup-modal__close" data-organizer-welcome-close="1" aria-label="<?php esc_attr_e( 'Close', 'lnkup-marketplace' ); ?>">&times;</button>
				<h2 id="lnkup-organizer-welcome-title" class="lnkup-type-title"><?php echo esc_html( lnkup_copy( 'organizer_tutorial_welcome_title' ) ); ?></h2>
				<p class="lnkup-muted"><?php echo esc_html( lnkup_copy( 'organizer_tutorial_welcome_body' ) ); ?></p>
				<div class="lnkup-modal__actions" style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-top:1.25rem;">
					<button type="button" class="lnkup-btn lnkup-btn--primary" data-organizer-welcome-start><?php echo esc_html( lnkup_copy( 'organizer_tutorial_welcome_start' ) ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--ghost" data-organizer-welcome-skip><?php echo esc_html( lnkup_copy( 'organizer_tutorial_welcome_skip' ) ); ?></button>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Priority module rows from missing-doc applications.
	 *
	 * @param array  $missing_docs Apps not event-ready.
	 * @param string $dash_url     Dashboard URL.
	 */
	private static function render_organizer_priority_module( $missing_docs, $dash_url ) {
		$priority_apps = array_values(
			array_filter(
				$missing_docs,
				function ( $a ) {
					$s = get_post_meta( $a->ID, '_lnkup_application_status', true );
					return in_array( $s, array( 'approved', 'payment_required', 'paid' ), true );
				}
			)
		);
		$priority_apps = array_slice( $priority_apps, 0, 5 );
		if ( ! $priority_apps ) {
			return '';
		}
		ob_start();
		?>
		<div class="lnkup-panel lnkup-organizer-priority lnkup-priority-panel">
			<h2 class="lnkup-panel__title"><?php echo esc_html( lnkup_copy( 'todays_priority' ) ); ?></h2>
			<ul class="lnkup-organizer-priority__list">
				<?php foreach ( $priority_apps as $app ) : ?>
					<?php
					$profile_id = (int) get_post_meta( $app->ID, '_lnkup_vendor_profile_id', true );
					$profile    = $profile_id ? LNKup_Vendor_CRM_Profile::get( $profile_id, get_current_user_id() ) : null;
					$vendor_name = $profile ? $profile['business_name'] : __( 'Vendor', 'lnkup-marketplace' );
					$readiness   = LNKup_Vendor_Readiness::refresh( $app->ID );
					$vendor_url  = $profile_id ? add_query_arg( 'vendor', $profile_id, $dash_url ) : $dash_url . '#applications';
					?>
					<li class="lnkup-organizer-priority__item">
						<div class="lnkup-organizer-priority__row">
							<div>
								<p class="lnkup-organizer-priority__name"><?php echo esc_html( $vendor_name ); ?></p>
								<p class="lnkup-muted"><?php echo esc_html( LNKup_Vendor_Readiness::label( $readiness ) ); ?></p>
							</div>
							<?php echo lnkup_status_badge( $readiness, LNKup_Vendor_Readiness::label( $readiness ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<div class="lnkup-organizer-priority__actions">
							<a href="<?php echo esc_url( $dash_url . '#applications' ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-org-app-filter-trigger="missing_docs"><?php esc_html_e( 'Review docs', 'lnkup-marketplace' ); ?></a>
							<a href="<?php echo esc_url( $vendor_url ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost"><?php esc_html_e( 'View vendor', 'lnkup-marketplace' ); ?></a>
							<a href="<?php echo esc_url( $dash_url . '#applications' ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost"><?php esc_html_e( 'Send reminder', 'lnkup-marketplace' ); ?></a>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Compact vendor requests snapshot for overview.
	 *
	 * @param array  $pending_apps Pending applications.
	 * @param string $dash_url     Dashboard URL.
	 * @param int    $limit        Max cards.
	 */
	private static function render_organizer_apps_snapshot( $pending_apps, $dash_url, $limit = 3 ) {
		$snapshot = array_slice( array_values( $pending_apps ), 0, $limit );
		ob_start();
		?>
		<div class="lnkup-panel lnkup-organizer-snapshot">
			<div class="lnkup-panel__header-row">
				<h2 class="lnkup-panel__title"><?php echo esc_html( lnkup_copy( 'vendor_requests_snapshot' ) ); ?></h2>
			</div>
			<?php if ( $snapshot ) : ?>
				<?php echo self::render_applications_manager( $snapshot ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="lnkup-organizer-snapshot__footer">
					<a href="<?php echo esc_url( $dash_url . '#applications' ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php echo esc_html( lnkup_copy( 'view_all_vendor_requests' ) ); ?></a>
				</div>
			<?php else : ?>
				<p class="lnkup-muted"><?php echo esc_html( lnkup_copy( 'empty_vendor_requests' ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Launch checklist panel for organizer overview.
	 *
	 * @param array<string, mixed> $checklist Checklist payload from service.
	 * @param string               $dash_url  Dashboard base URL.
	 */
	private static function render_organizer_launch_checklist( array $checklist, string $dash_url ): string {
		$completed = (int) ( $checklist['completed'] ?? 0 );
		$total     = (int) ( $checklist['total'] ?? 0 );
		$percent   = (int) ( $checklist['percent'] ?? 0 );
		$all_done  = ! empty( $checklist['all_complete'] );
		$items     = isset( $checklist['items'] ) && is_array( $checklist['items'] ) ? $checklist['items'] : array();
		$progress  = sprintf( lnkup_copy( 'launch_checklist_progress' ), $completed, $total );
		$next_key  = '';
		foreach ( $items as $scan_item ) {
			$scan_status = isset( $scan_item['status'] ) ? (string) $scan_item['status'] : LNKup_Organizer_Launch_Checklist::STATUS_NOT_STARTED;
			if ( LNKup_Organizer_Launch_Checklist::STATUS_COMPLETE !== $scan_status ) {
				$next_key = isset( $scan_item['key'] ) ? (string) $scan_item['key'] : '';
				break;
			}
		}

		ob_start();
		if ( $all_done ) :
			?>
			<div class="lnkup-launch-checklist lnkup-launch-checklist--complete" id="lnkup-launch-checklist" data-testid="lnkup-launch-checklist" data-all-complete="1">
				<div class="lnkup-launch-checklist__banner">
					<p class="lnkup-launch-checklist__banner-text"><?php echo esc_html( lnkup_copy( 'launch_checklist_all_done' ) ); ?></p>
					<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-launch-checklist-toggle aria-expanded="false">
						<?php echo esc_html( lnkup_copy( 'launch_checklist_show' ) ); ?>
					</button>
				</div>
				<div class="lnkup-launch-checklist__body" hidden>
			<?php
		else :
			?>
			<div class="lnkup-launch-checklist" id="lnkup-launch-checklist" data-testid="lnkup-launch-checklist" data-all-complete="0">
				<div class="lnkup-launch-checklist__body">
			<?php
		endif;
		?>
			<header class="lnkup-launch-checklist__header">
				<div class="lnkup-launch-checklist__head">
					<p class="lnkup-launch-checklist__eyebrow lnkup-muted"><?php echo esc_html( lnkup_copy( 'launch_checklist_eyebrow' ) ); ?></p>
					<h2 class="lnkup-launch-checklist__title"><?php echo esc_html( lnkup_copy( 'launch_checklist_title' ) ); ?></h2>
					<p class="lnkup-launch-checklist__progress lnkup-muted" data-launch-checklist-progress><?php echo esc_html( $progress ); ?></p>
				</div>
				<?php if ( ! $all_done ) : ?>
					<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-organizer-tutorial-dismiss>
						<?php echo esc_html( lnkup_copy( 'launch_checklist_dismiss' ) ); ?>
					</button>
				<?php endif; ?>
				<div class="lnkup-launch-checklist__progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php echo esc_attr( $progress ); ?>">
					<span class="lnkup-launch-checklist__progress-fill" data-launch-checklist-percent style="width:<?php echo esc_attr( (string) $percent ); ?>%"></span>
				</div>
			</header>
			<div class="lnkup-launch-checklist__grid" data-launch-checklist-grid>
				<?php foreach ( $items as $item ) : ?>
					<?php
					$key    = isset( $item['key'] ) ? (string) $item['key'] : '';
					$status = isset( $item['status'] ) ? (string) $item['status'] : LNKup_Organizer_Launch_Checklist::STATUS_NOT_STARTED;
					$label  = LNKup_Organizer_Launch_Checklist::status_label( $status );
					$is_complete = LNKup_Organizer_Launch_Checklist::STATUS_COMPLETE === $status;
					$is_current  = ! $is_complete && $key && $key === $next_key;
					$cta_url = isset( $item['cta_url'] ) ? (string) $item['cta_url'] : $dash_url;
					$cta_label = $is_complete ? lnkup_copy( 'launch_checklist_view' ) : ( isset( $item['cta_label'] ) ? (string) $item['cta_label'] : '' );
					$status_class = str_replace( '_', '-', $status );
					$card_classes = array(
						'lnkup-launch-checklist-card',
						'lnkup-launch-checklist-card--' . $status_class,
					);
					if ( $is_complete ) {
						$card_classes[] = 'is-done';
					} elseif ( $is_current ) {
						$card_classes[] = 'is-current';
					} else {
						$card_classes[] = 'is-pending';
					}
					?>
					<article
						class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>"
						data-launch-checklist-item="<?php echo esc_attr( $key ); ?>"
						data-status="<?php echo esc_attr( $status ); ?>"
						<?php echo $is_current ? 'aria-current="step"' : ''; ?>
					>
						<span class="lnkup-launch-checklist-card__pill" data-launch-checklist-status><?php echo esc_html( $label ); ?></span>
						<h3 class="lnkup-launch-checklist-card__title"><?php echo esc_html( $item['title'] ?? '' ); ?></h3>
						<p class="lnkup-launch-checklist-card__desc lnkup-muted"><?php echo esc_html( $item['description'] ?? '' ); ?></p>
						<?php if ( $cta_label ) : ?>
							<a
								href="<?php echo esc_url( $cta_url ); ?>"
								class="lnkup-btn lnkup-btn--sm <?php echo $is_complete ? 'lnkup-btn--ghost' : ( $is_current ? 'lnkup-btn--primary' : 'lnkup-btn--outline' ); ?> lnkup-launch-checklist-card__cta"
								data-launch-checklist-cta
								<?php echo ( '#create' === substr( $cta_url, -7 ) || false !== strpos( $cta_url, '#create' ) ) && ! $is_complete ? ' data-open-create' : ''; ?>
								<?php echo false !== strpos( $cta_url, '#financial' ) ? ' data-expand="financial"' : ''; ?>
							><?php echo esc_html( $cta_label ); ?></a>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Financial snapshot card for command sidebar.
	 *
	 * @param float  $available_payout Available balance.
	 * @param float  $collected        Collected revenue.
	 * @param string $dash_url         Dashboard URL.
	 */
	private static function render_organizer_fin_snapshot( $available_payout, $collected, $dash_url ) {
		$payout_label = $available_payout > 0 ? lnkup_copy( 'payout_status_active' ) : lnkup_copy( 'payout_status_setup' );
		ob_start();
		?>
		<div class="lnkup-panel lnkup-organizer-fin-snapshot">
			<h2 class="lnkup-panel__title"><?php echo esc_html( lnkup_copy( 'financial_snapshot' ) ); ?></h2>
			<span class="lnkup-muted"><?php echo esc_html( lnkup_copy( 'available_balance' ) ); ?></span>
			<strong class="lnkup-organizer-fin-snapshot__value"><?php echo esc_html( lnkup_currency( $available_payout ) ); ?></strong>
			<p class="lnkup-organizer-fin-snapshot__meta lnkup-muted">
				<?php printf( esc_html__( '%1$s: %2$s · %3$s', 'lnkup-marketplace' ), esc_html( lnkup_copy( 'collected_to_date' ) ), esc_html( lnkup_currency( $collected ) ), esc_html( $payout_label ) ); ?>
			</p>
			<a href="<?php echo esc_url( $dash_url . '#financial' ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-expand="financial"><?php echo esc_html( lnkup_copy( 'expand_financials' ) ); ?></a>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Open always-visible organizer section card.
	 *
	 * @param string $id       Section anchor id.
	 * @param string $title    Section title.
	 * @param string $desc     Optional description.
	 * @param string $actions  Optional header actions HTML.
	 */
	private static function organizer_section_card_open( $id, $title, $desc = '', $actions = '' ) {
		ob_start();
		?>
		<section id="<?php echo esc_attr( $id ); ?>" class="lnkup-organizer-section-card lnkup-panel" data-section="<?php echo esc_attr( $id ); ?>">
			<div class="lnkup-organizer-section-card__header">
				<div class="lnkup-organizer-section-card__head">
					<h2 class="lnkup-panel__title"><?php echo esc_html( $title ); ?></h2>
					<?php if ( $desc ) : ?>
						<p class="lnkup-organizer-section-card__desc lnkup-muted"><?php echo esc_html( $desc ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $actions ) : ?>
					<div class="lnkup-organizer-section-card__actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<?php endif; ?>
			</div>
			<div class="lnkup-organizer-section-card__body">
		<?php
		return ob_get_clean();
	}

	/**
	 * Close organizer section card.
	 */
	private static function organizer_section_card_close() {
		return '</div></section>';
	}

	/**
	 * Open inline expand panel for secondary module details.
	 *
	 * @param string $panel_id Panel key (vendors, lists, etc.).
	 * @param bool   $hidden   Whether panel starts hidden.
	 */
	private static function organizer_expand_panel_open( $panel_id, $hidden = true ) {
		$hidden_attr = $hidden ? ' hidden' : '';
		ob_start();
		?>
		<div id="lnkup-organizer-expand-<?php echo esc_attr( $panel_id ); ?>" class="lnkup-organizer-expand-panel" data-panel-id="<?php echo esc_attr( $panel_id ); ?>"<?php echo $hidden_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php
		return ob_get_clean();
	}

	/**
	 * Close inline expand panel.
	 */
	private static function organizer_expand_panel_close() {
		return '</div>';
	}

	/**
	 * Open compact secondary module card.
	 *
	 * @param string $id    Section anchor id.
	 * @param string $title Card title.
	 * @param string $desc  Optional description.
	 */
	private static function organizer_module_card_open( $id, $title, $desc = '' ) {
		ob_start();
		?>
		<article id="<?php echo esc_attr( $id ); ?>" class="lnkup-organizer-module-card lnkup-panel" data-section="<?php echo esc_attr( $id ); ?>">
			<h3 class="lnkup-organizer-module-card__title"><?php echo esc_html( $title ); ?></h3>
			<?php if ( $desc ) : ?>
				<p class="lnkup-organizer-module-card__desc lnkup-muted"><?php echo esc_html( $desc ); ?></p>
			<?php endif; ?>
			<div class="lnkup-organizer-module-card__compact">
		<?php
		return ob_get_clean();
	}

	/**
	 * Close compact secondary module card.
	 */
	private static function organizer_module_card_close() {
		return '</div></article>';
	}

	/**
	 * Server-rendered vendor preview cards for the organizer command center.
	 *
	 * @param int    $user_id  Organizer user ID.
	 * @param string $dash_url Dashboard base URL.
	 */
	private static function render_organizer_vendors_preview_ssr( $user_id, $dash_url ) {
		if ( ! class_exists( 'LNKup_Vendor_Directory' ) ) {
			return '<p class="lnkup-muted lnkup-organizer-preview-empty">' . esc_html__( 'Vendor network unavailable.', 'lnkup-marketplace' ) . '</p>';
		}

		$result = LNKup_Vendor_Directory::search(
			$user_id,
			array(
				'scope'    => 'discover',
				'per_page' => 3,
				'sort'     => 'score',
				'view'     => 'card',
			)
		);
		$items  = $result['items'] ?? array();

		if ( empty( $items ) ) {
			return lnkup_empty_state(
				array(
					'title'               => __( 'No vendors to show yet', 'lnkup-marketplace' ),
					'desc'                => __( 'Invite vendors you already know, or browse the marketplace to fill your events.', 'lnkup-marketplace' ),
					'cta_label'           => __( 'Invite vendors', 'lnkup-marketplace' ),
					'cta_href'            => $dash_url . '#invites',
					'secondary_cta_label' => __( 'Browse marketplace', 'lnkup-marketplace' ),
					'secondary_cta_href'  => $dash_url . '#vendors',
					'compact'             => true,
				)
			);
		}

		ob_start();
		echo '<div class="lnkup-organizer-vendors-preview-grid">';
		foreach ( array_slice( $items, 0, 3 ) as $vendor ) {
			$profile_id = (int) ( $vendor['profile_id'] ?? 0 );
			$name       = $vendor['business_name'] ?? __( 'Vendor', 'lnkup-marketplace' );
			$url        = $profile_id ? add_query_arg( array( 'vendor' => $profile_id ), $dash_url ) : $dash_url . '#vendors';
			$categories = is_array( $vendor['category'] ?? null ) ? array_slice( $vendor['category'], 0, 2 ) : array();
			$category   = implode( ', ', $categories );
			$rating     = ! empty( $vendor['avg_rating'] ) ? '★ ' . $vendor['avg_rating'] : '';
			$score      = ! empty( $vendor['vendor_score'] ) ? ( (int) $vendor['vendor_score'] . ' score' ) : '';
			$meta       = $rating ?: $score;
			$vendor_uid = (int) ( $vendor['vendor_user_id'] ?? 0 );
			?>
			<article class="lnkup-organizer-vendor-preview-card lnkup-surface--primary">
				<a href="<?php echo esc_url( $url ); ?>" class="lnkup-organizer-vendor-preview-card__link">
					<?php if ( ! empty( $vendor['logo'] ) ) : ?>
						<img src="<?php echo esc_url( $vendor['logo'] ); ?>" alt="" class="lnkup-organizer-vendor-preview-card__logo" loading="lazy" />
					<?php endif; ?>
					<span class="lnkup-organizer-vendor-preview-card__main">
						<strong><?php echo esc_html( $name ); ?></strong>
						<?php if ( $category ) : ?>
							<span class="lnkup-muted lnkup-organizer-vendor-preview-card__cat"><?php echo esc_html( $category ); ?></span>
						<?php endif; ?>
						<?php if ( $meta ) : ?>
							<span class="lnkup-muted lnkup-organizer-vendor-preview-card__meta"><?php echo esc_html( $meta ); ?></span>
						<?php endif; ?>
					</span>
				</a>
				<div class="lnkup-organizer-vendor-preview-card__actions">
					<a href="<?php echo esc_url( $url ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php esc_html_e( 'View', 'lnkup-marketplace' ); ?></a>
					<?php if ( $vendor_uid ) : ?>
						<button type="button" class="lnkup-btn lnkup-btn--soft lnkup-btn--sm lnkup-crm-invite" data-vendor-id="<?php echo esc_attr( (string) $vendor_uid ); ?>" data-vendor-name="<?php echo esc_attr( $name ); ?>" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>"><?php esc_html_e( 'Invite', 'lnkup-marketplace' ); ?></button>
					<?php endif; ?>
				</div>
			</article>
			<?php
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Server-rendered intelligence summary chips for the organizer command center.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	private static function render_organizer_intel_summary_ssr( $user_id ) {
		if ( ! class_exists( 'LNKup_Organizer_Intelligence' ) ) {
			return '<p class="lnkup-muted">' . esc_html__( 'Insights will appear as your events gain traction.', 'lnkup-marketplace' ) . '</p>';
		}

		$health  = LNKup_Organizer_Intelligence::health( $user_id );
		$summary = LNKup_Organizer_Intelligence::summary( $user_id );
		$score   = isset( $health['score'] ) ? (int) $health['score'] : null;
		$label   = $health['label'] ?? '';
		$actions = $summary['recommended_actions'] ?? array();
		$top     = ! empty( $actions[0] ) ? $actions[0] : null;

		ob_start();
		?>
		<div class="lnkup-organizer-intel-summary__hero">
			<?php if ( null !== $score ) : ?>
				<div class="lnkup-compliance-ring lnkup-organizer-intel-summary__ring" style="--progress: <?php echo esc_attr( (string) min( 100, max( 0, $score ) ) ); ?>%" role="img" aria-label="<?php echo esc_attr( sprintf( __( 'Portfolio score %d', 'lnkup-marketplace' ), $score ) ); ?>">
					<span class="lnkup-compliance-ring__value"><?php echo esc_html( (string) $score ); ?></span>
				</div>
			<?php endif; ?>
			<div class="lnkup-organizer-intel-summary__copy">
				<div class="lnkup-organizer-intel-summary__chips">
					<?php if ( $label ) : ?>
						<span class="lnkup-organizer-intel-summary__chip"><?php echo esc_html( $label ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $top && ! empty( $top['title'] ) ) : ?>
					<p class="lnkup-organizer-intel-summary__rec">
						<strong><?php echo esc_html( $top['title'] ); ?></strong>
						<?php if ( ! empty( $top['reason'] ) ) : ?>
							&mdash; <?php echo esc_html( $top['reason'] ); ?>
						<?php endif; ?>
					</p>
				<?php elseif ( null !== $score || $label ) : ?>
					<p class="lnkup-muted"><?php esc_html_e( 'Portfolio snapshot ready — expand for full insights.', 'lnkup-marketplace' ); ?></p>
				<?php else : ?>
					<p class="lnkup-muted"><?php echo esc_html( lnkup_copy( 'empty_intelligence' ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a single organizer event card.
	 *
	 * @param WP_Post $event     Event post.
	 * @param array   $apps      Organizer applications.
	 * @param array   $payments  Organizer payments.
	 * @param string  $dash_url  Dashboard base URL.
	 */
	private static function render_organizer_event_card( $event, $apps, $payments, $dash_url ) {
		$event_date = get_post_meta( $event->ID, '_lnkup_event_date', true );
		$city       = get_post_meta( $event->ID, '_lnkup_city', true );
		$state      = get_post_meta( $event->ID, '_lnkup_state', true );
		$status     = get_post_meta( $event->ID, '_lnkup_event_status', true ) ?: 'draft';
		$spots      = self::organizer_event_spots( $event->ID );
		$image_url  = self::organizer_event_image_url( $event->ID );
		$vendor_count = count(
			array_filter(
				$apps,
				function ( $a ) use ( $event ) {
					return (int) get_post_meta( $a->ID, '_lnkup_event_id', true ) === (int) $event->ID
						&& in_array( get_post_meta( $a->ID, '_lnkup_application_status', true ), array( 'approved', 'payment_required', 'paid', 'completed' ), true );
				}
			)
		);
		$new_apps = count(
			array_filter(
				$apps,
				function ( $a ) use ( $event ) {
					return (int) get_post_meta( $a->ID, '_lnkup_event_id', true ) === (int) $event->ID
						&& in_array( get_post_meta( $a->ID, '_lnkup_application_status', true ), array( 'submitted', 'under_review' ), true );
				}
			)
		);
		$revenue = array_reduce(
			$payments,
			function ( $sum, $p ) use ( $event ) {
				if ( (int) get_post_meta( $p->ID, '_lnkup_event_id', true ) !== (int) $event->ID ) {
					return $sum;
				}
				if ( 'paid' === get_post_meta( $p->ID, '_lnkup_payment_status', true ) ) {
					return $sum + (float) LNKup_Financial::payment_booth_amount( $p->ID );
				}
				return $sum;
			},
			0
		);
		$lifecycle = __( 'Open', 'lnkup-marketplace' );
		if ( $event_date && strtotime( $event_date ) < strtotime( 'today' ) ) {
			$lifecycle = __( 'Past', 'lnkup-marketplace' );
		} elseif ( $spots['total'] > 0 && $spots['filled'] >= $spots['total'] ) {
			$lifecycle = __( 'Full', 'lnkup-marketplace' );
		} elseif ( $spots['filled'] > 0 ) {
			$lifecycle = __( 'Filling', 'lnkup-marketplace' );
		}
		$lifecycle_class = sanitize_html_class( strtolower( $lifecycle ) );
		$roster_url = add_query_arg( array( 'event' => $event->ID, 'roster' => 1 ), $dash_url );
		$ops_url    = add_query_arg( array( 'event' => $event->ID, 'ops' => 1 ), $dash_url );
		$export_url = add_query_arg(
			array(
				'action'      => 'lnkup_export_vendors',
				'event_id'    => $event->ID,
				'lnkup_nonce' => wp_create_nonce( 'lnkup_export' ),
			),
			admin_url( 'admin-ajax.php' )
		);
		$location   = trim( $city . ( $state ? ', ' . $state : '' ) );
		$date_label = $event_date ? date_i18n( 'M j, Y', strtotime( $event_date ) ) : '—';
		$apps_url   = $dash_url . '#applications&event=' . (int) $event->ID;
		$fin_url    = $dash_url . '#financial';
		$initial    = strtoupper( substr( trim( $event->post_title ), 0, 1 ) );
		$event_terms = get_the_terms( $event->ID, 'lnkup_event_type' );
		$type_label  = '';
		if ( $event_terms && ! is_wp_error( $event_terms ) && ! empty( $event_terms ) ) {
			$type_term  = $event_terms[0];
			$type_label = $type_term->name;
			if ( $type_term->parent ) {
				$parent_term = get_term( $type_term->parent, 'lnkup_event_type' );
				if ( $parent_term && ! is_wp_error( $parent_term ) ) {
					$type_label = $parent_term->name . ' · ' . $type_label;
				}
			}
		}
		$status_label = ucfirst( str_replace( '_', ' ', $status ) );
		$readiness_issues = count(
			array_filter(
				$apps,
				function ( $a ) use ( $event ) {
					if ( (int) get_post_meta( $a->ID, '_lnkup_event_id', true ) !== (int) $event->ID ) {
						return false;
					}
					$s = get_post_meta( $a->ID, '_lnkup_application_status', true );
					if ( ! in_array( $s, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) ) {
						return false;
					}
					return 'ready' !== LNKup_Vendor_Readiness::refresh( $a->ID );
				}
			)
		);
		?>
		<article class="lnkup-organizer-event-card lnkup-organizer-event-card--v3 lnkup-organizer-event-card--v4 lnkup-surface--primary">
			<div class="lnkup-organizer-event-card__media">
				<?php if ( $image_url ) : ?>
					<img src="<?php echo esc_url( $image_url ); ?>" alt="" class="lnkup-organizer-event-card__image" loading="lazy" />
				<?php else : ?>
					<div class="lnkup-organizer-event-card__placeholder lnkup-organizer-event-card__fallback" aria-hidden="true" data-category="<?php echo esc_attr( $type_label ); ?>">
						<span class="lnkup-organizer-event-card__fallback-initial"><?php echo esc_html( $initial ?: 'E' ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( $type_label ) : ?>
					<span class="lnkup-organizer-event-card__type-badge"><?php echo esc_html( $type_label ); ?></span>
				<?php endif; ?>
				<span class="lnkup-organizer-event-card__status lnkup-organizer-event-card__status--<?php echo esc_attr( $lifecycle_class ); ?>"><?php echo esc_html( $lifecycle ); ?></span>
			</div>
			<div class="lnkup-organizer-event-card__body">
				<div class="lnkup-organizer-event-card__head">
					<h3 class="lnkup-organizer-event-card__title"><?php echo esc_html( $event->post_title ); ?></h3>
					<span class="lnkup-status-pill lnkup-status-pill--muted"><?php echo esc_html( $status_label ); ?></span>
				</div>
				<p class="lnkup-organizer-event-card__meta-strip lnkup-muted">
					<span><?php echo esc_html( $date_label ); ?></span>
					<?php if ( $location ) : ?>
						<span aria-hidden="true">·</span>
						<span><?php echo esc_html( $location ); ?></span>
					<?php endif; ?>
					<?php if ( $type_label ) : ?>
						<span aria-hidden="true">·</span>
						<span><?php echo esc_html( $type_label ); ?></span>
					<?php endif; ?>
				</p>
				<div class="lnkup-organizer-event-card__stats">
					<div class="lnkup-organizer-event-card__stat">
						<span class="lnkup-organizer-event-card__stat-label"><?php echo esc_html( lnkup_copy( 'vendor_revenue' ) ); ?></span>
						<strong class="lnkup-organizer-event-card__stat-value"><?php echo esc_html( $revenue > 0 ? lnkup_currency( $revenue ) : '—' ); ?></strong>
					</div>
					<div class="lnkup-organizer-event-card__stat">
						<span class="lnkup-organizer-event-card__stat-label"><?php esc_html_e( 'Vendor requests', 'lnkup-marketplace' ); ?></span>
						<strong class="lnkup-organizer-event-card__stat-value"><?php echo esc_html( (string) $new_apps ); ?></strong>
					</div>
					<?php if ( $readiness_issues > 0 ) : ?>
						<div class="lnkup-organizer-event-card__stat lnkup-organizer-event-card__stat--warning">
							<span class="lnkup-organizer-event-card__stat-label"><?php esc_html_e( 'Document issues', 'lnkup-marketplace' ); ?></span>
							<strong class="lnkup-organizer-event-card__stat-value"><?php echo esc_html( (string) $readiness_issues ); ?></strong>
						</div>
					<?php else : ?>
						<div class="lnkup-organizer-event-card__stat">
							<span class="lnkup-organizer-event-card__stat-label"><?php esc_html_e( 'Confirmed', 'lnkup-marketplace' ); ?></span>
							<strong class="lnkup-organizer-event-card__stat-value"><?php echo esc_html( (string) $vendor_count ); ?></strong>
						</div>
					<?php endif; ?>
				</div>
				<?php if ( $spots['total'] > 0 ) : ?>
					<?php
					$progress_class = '';
					if ( $spots['percent'] >= 90 ) {
						$progress_class = ' lnkup-organizer-event-card__progress--full';
					} elseif ( $spots['percent'] >= 60 ) {
						$progress_class = ' lnkup-organizer-event-card__progress--warn';
					}
					?>
					<div class="lnkup-organizer-event-card__progress<?php echo esc_attr( $progress_class ); ?>">
						<div class="lnkup-organizer-event-card__progress-header">
							<span><?php esc_html_e( 'Vendor capacity', 'lnkup-marketplace' ); ?></span>
							<span><?php printf( esc_html__( '%1$d/%2$d · %3$s%%', 'lnkup-marketplace' ), (int) $spots['filled'], (int) $spots['total'], (string) $spots['percent'] ); ?></span>
						</div>
						<div class="lnkup-progress" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $spots['percent'] ); ?>" aria-valuemin="0" aria-valuemax="100">
							<div class="lnkup-progress__fill" style="width:<?php echo esc_attr( (string) $spots['percent'] ); ?>%"></div>
						</div>
					</div>
				<?php endif; ?>
				<div class="lnkup-organizer-event-card__primary-actions">
					<a href="<?php echo esc_url( $roster_url ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary"><?php esc_html_e( 'Manage', 'lnkup-marketplace' ); ?></a>
					<a href="<?php echo esc_url( $apps_url ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline"><?php esc_html_e( 'Vendors', 'lnkup-marketplace' ); ?></a>
					<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-edit-event-toggle" data-edit-event="<?php echo esc_attr( (string) (int) $event->ID ); ?>" aria-expanded="false"><?php esc_html_e( 'Edit event', 'lnkup-marketplace' ); ?></button>
					<a href="<?php echo esc_url( $dash_url . '#pricing-' . (int) $event->ID ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-edit-fee-spots" data-pricing-event="<?php echo esc_attr( (string) (int) $event->ID ); ?>"><?php esc_html_e( 'Edit fee & spots', 'lnkup-marketplace' ); ?></a>
					<a href="<?php echo esc_url( $fin_url ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-expand="financial"><?php esc_html_e( 'Financials', 'lnkup-marketplace' ); ?></a>
					<a href="<?php echo esc_url( $ops_url ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost"><?php esc_html_e( 'Operations', 'lnkup-marketplace' ); ?></a>
				</div>
				<form class="lnkup-form lnkup-edit-event-form" id="lnkup-edit-event-<?php echo esc_attr( (string) (int) $event->ID ); ?>" hidden data-edit-event-form="<?php echo esc_attr( (string) (int) $event->ID ); ?>">
					<?php wp_nonce_field( 'lnkup_event', 'lnkup_nonce' ); ?>
					<input type="hidden" name="action" value="lnkup_update_event" />
					<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) (int) $event->ID ); ?>" />
					<div class="lnkup-grid lnkup-grid--2">
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'Event name', 'lnkup-marketplace' ); ?></label>
							<input type="text" name="event_name" value="<?php echo esc_attr( $event->post_title ); ?>" required />
						</div>
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></label>
							<input type="date" name="event_date" value="<?php echo esc_attr( (string) get_post_meta( $event->ID, '_lnkup_event_date', true ) ); ?>" />
						</div>
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></label>
							<input type="text" name="city" value="<?php echo esc_attr( (string) get_post_meta( $event->ID, '_lnkup_city', true ) ); ?>" />
						</div>
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'State', 'lnkup-marketplace' ); ?></label>
							<input type="text" name="state" maxlength="2" value="<?php echo esc_attr( (string) get_post_meta( $event->ID, '_lnkup_state', true ) ); ?>" />
						</div>
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'Venue', 'lnkup-marketplace' ); ?></label>
							<input type="text" name="venue_name" value="<?php echo esc_attr( (string) get_post_meta( $event->ID, '_lnkup_venue_name', true ) ); ?>" />
						</div>
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'Start time', 'lnkup-marketplace' ); ?></label>
							<input type="time" name="start_time" value="<?php echo esc_attr( (string) get_post_meta( $event->ID, '_lnkup_start_time', true ) ); ?>" />
						</div>
					</div>
					<div class="lnkup-form-row">
						<label><?php esc_html_e( 'Description', 'lnkup-marketplace' ); ?></label>
						<textarea name="description" rows="3"><?php echo esc_textarea( $event->post_content ); ?></textarea>
					</div>
					<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Save event', 'lnkup-marketplace' ); ?></button>
				</form>
			</div>
		</article>
		<?php
	}

	/**
	 * Organizer events shortcode.
	 *
	 * @param int $limit Optional max event cards (0 = all, skips create-opportunity block when set).
	 */
	public static function render_organizer_events( $limit = 0 ) {
		if ( is_array( $limit ) ) {
			$limit = 0;
		}
		$limit = absint( $limit );
		$user_id = get_current_user_id();
		$events  = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $user_id,
			)
		);
		$dash_url = lnkup_page_url_helper( 'organizer-dashboard' );
		$apps     = lnkup_get_applications_for_user( $user_id, 'organizer' );
		$payments = lnkup_get_payments_for_user( $user_id, 'organizer' );

		ob_start();
		if ( $events ) {
			$display_events = $limit > 0 ? array_slice( $events, 0, $limit ) : $events;
			$grid_class     = 'lnkup-organizer-event-grid';
			if ( 1 === count( $display_events ) ) {
				$grid_class .= ' lnkup-organizer-event-grid--single';
			}
			echo '<div class="' . esc_attr( $grid_class ) . '">';
			foreach ( $display_events as $event ) {
				self::render_organizer_event_card( $event, $apps, $payments, $dash_url );
			}
			echo '</div>';
		} else {
			echo lnkup_empty_state(
				array(
					'title'               => __( 'Launch your first event', 'lnkup-marketplace' ),
					'desc'                => __( 'Create an event to publish vendor opportunities and start filling your vendor row.', 'lnkup-marketplace' ),
					'cta_label'           => lnkup_copy( 'launch_event' ),
					'cta_href'            => $dash_url . '#create',
					'secondary_cta_label' => __( 'Browse vendors', 'lnkup-marketplace' ),
					'secondary_cta_href'  => $dash_url . '#vendors',
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( $limit > 0 ) {
			return ob_get_clean();
		}

		return ob_get_clean();
	}

	/**
	 * Shared settings for organizer create forms.
	 */
	private static function organizer_create_form_context() {
		$settings = lnkup_get_settings();
		return array(
			'settings'          => $settings,
			'doc_types'         => class_exists( 'LNKup_Documents' ) ? LNKup_Documents::document_types() : array(),
			'categories'        => get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false ) ),
			'default_fee'       => $settings['vendor_checkout_fee'] ?? $settings['default_fee'] ?? 5.99,
			'default_documents' => $settings['default_documents'] ?? array(),
			'commission'        => $settings['organizer_commission_percent'] ?? 6,
			'dash_url'          => lnkup_page_url_helper( 'organizer-dashboard' ),
		);
	}

	/**
	 * Import-only panel (ticketing URL preview).
	 */
	private static function render_import_event_panel() {
		ob_start();
		?>
		<div class="lnkup-organizer-import-panel" id="lnkup-import-panel">
			<p class="lnkup-muted"><?php esc_html_e( 'Paste an Eventbrite or Posh event link, or an RSS/Atom feed URL. We’ll prefill the form — you still review Vendors before publishing.', 'lnkup-marketplace' ); ?></p>
			<div class="lnkup-import-sources" aria-hidden="true">
				<span class="lnkup-badge lnkup-badge--secondary">Eventbrite</span>
				<span class="lnkup-badge lnkup-badge--secondary">Posh</span>
				<span class="lnkup-badge lnkup-badge--secondary">RSS</span>
			</div>
			<div class="lnkup-form-row lnkup-import-url-row">
				<label for="ticketing_url_import"><?php esc_html_e( 'Event or feed URL', 'lnkup-marketplace' ); ?></label>
				<div class="lnkup-import-url-field">
					<input type="url" id="ticketing_url_import" placeholder="https://www.eventbrite.com/e/… or https://posh.vip/e/… or feed.xml" data-testid="lnkup-import-url" />
					<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" id="lnkup-import-preview-btn" data-testid="lnkup-import-preview-btn"><?php esc_html_e( 'Import', 'lnkup-marketplace' ); ?></button>
				</div>
			</div>
			<div id="lnkup-import-loading" class="lnkup-import-loading" hidden>
				<span class="lnkup-spinner" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Fetching event details…', 'lnkup-marketplace' ); ?></span>
			</div>
			<div id="lnkup-import-preview" class="lnkup-import-preview" hidden data-testid="lnkup-import-preview"></div>
			<div id="lnkup-import-error" class="lnkup-import-error" hidden data-testid="lnkup-import-error"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Unified create panel: optional import, new event, or add opportunity to existing event.
	 *
	 * @param array $events Organizer events.
	 */
	private static function render_create_panel( $events ) {
		$has_events = ! empty( $events );
		ob_start();
		?>
		<div class="lnkup-organizer-create-page" id="lnkup-create-panel">
			<details class="lnkup-organizer-import-collapse">
				<summary class="lnkup-organizer-import-collapse__summary"><?php esc_html_e( 'Import from ticketing URL (optional)', 'lnkup-marketplace' ); ?></summary>
				<?php echo self::render_import_event_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</details>

			<?php if ( $has_events ) : ?>
				<div class="lnkup-dash-tabs lnkup-organizer-create-modes" role="tablist" aria-label="<?php esc_attr_e( 'Create mode', 'lnkup-marketplace' ); ?>">
					<button type="button" class="lnkup-dash-tabs__btn is-active" data-create-mode="new"><?php esc_html_e( 'New event', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-dash-tabs__btn" data-create-mode="existing"><?php esc_html_e( 'Add to existing event', 'lnkup-marketplace' ); ?></button>
				</div>
			<?php endif; ?>

			<div id="lnkup-create-mode-new" class="lnkup-organizer-create-mode is-active">
				<?php echo self::render_create_event_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<?php if ( $has_events ) : ?>
				<div id="lnkup-create-mode-existing" class="lnkup-organizer-create-mode" hidden>
					<?php echo self::render_create_opportunity_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<p class="lnkup-muted">
						<a href="#events" class="lnkup-link"><?php esc_html_e( 'Edit fee & spots for existing opportunities →', 'lnkup-marketplace' ); ?></a>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Create event form — includes Vendors step (fee/spots) before Review.
	 */
	private static function render_create_event_form() {
		$ctx         = self::organizer_create_form_context();
		$settings    = $ctx['settings'];
		$commission  = $ctx['commission'];
		$categories  = $ctx['categories'];
		ob_start();
		?>
		<div class="lnkup-organizer-create-wizard lnkup-organizer-wizard" id="lnkup-event-create-phase" data-wizard-step="1">
			<nav class="lnkup-organizer-wizard-steps" aria-label="<?php esc_attr_e( 'Event setup steps', 'lnkup-marketplace' ); ?>">
				<button type="button" class="is-active" data-wizard-step="lnkup-create-step-1"><?php esc_html_e( '1. Event basics', 'lnkup-marketplace' ); ?></button>
				<button type="button" data-wizard-step="lnkup-create-step-2"><?php esc_html_e( '2. Date & location', 'lnkup-marketplace' ); ?></button>
				<button type="button" data-wizard-step="lnkup-create-step-3"><?php esc_html_e( '3. Vendors', 'lnkup-marketplace' ); ?></button>
				<button type="button" data-wizard-step="lnkup-create-step-4"><?php esc_html_e( '4. Review', 'lnkup-marketplace' ); ?></button>
			</nav>
			<div class="lnkup-organizer-create-layout">
				<div class="lnkup-organizer-create-main">
					<form class="lnkup-form lnkup-event-form lnkup-organizer-create-form" id="lnkup-create-event-form" novalidate>
						<?php wp_nonce_field( 'lnkup_event', 'lnkup_nonce' ); ?>
						<input type="hidden" name="action" value="lnkup_create_event" />
						<input type="hidden" name="ticketing_url" id="lnkup_create_event_ticketing_url" value="" />

						<section class="lnkup-panel lnkup-organizer-create-section" id="lnkup-create-step-1">
							<h3 class="lnkup-panel__title"><?php esc_html_e( 'Event basics', 'lnkup-marketplace' ); ?></h3>
							<div class="lnkup-grid lnkup-grid--2">
								<div class="lnkup-form-row">
									<label for="event_name"><?php esc_html_e( 'Event title', 'lnkup-marketplace' ); ?> *</label>
									<input type="text" id="event_name" name="event_name" required data-testid="lnkup-event-name" />
								</div>
								<div class="lnkup-form-row">
									<label for="event_type_parent"><?php esc_html_e( 'Event category', 'lnkup-marketplace' ); ?></label>
									<select id="event_type_parent" name="event_type_parent" required>
										<option value=""><?php esc_html_e( 'Select category…', 'lnkup-marketplace' ); ?></option>
										<?php foreach ( lnkup_get_event_type_parents() as $parent_type ) : ?>
											<option value="<?php echo esc_attr( (string) $parent_type->term_id ); ?>"><?php echo esc_html( $parent_type->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="lnkup-form-row">
									<label for="event_type"><?php esc_html_e( 'Event type', 'lnkup-marketplace' ); ?></label>
									<select id="event_type" name="event_type" disabled required>
										<option value=""><?php esc_html_e( 'Select type…', 'lnkup-marketplace' ); ?></option>
									</select>
								</div>
								<div class="lnkup-form-row">
									<label for="ticketing_platform"><?php esc_html_e( 'Ticketing platform', 'lnkup-marketplace' ); ?></label>
									<select id="ticketing_platform" name="ticketing_platform">
										<option value="Manual"><?php esc_html_e( 'Manual', 'lnkup-marketplace' ); ?></option>
										<option value="Posh">Posh</option>
										<option value="Eventbrite">Eventbrite</option>
										<option value="RSS">RSS</option>
										<option value="Universe">Universe</option>
									</select>
								</div>
							</div>
							<div class="lnkup-form-row">
								<label for="description"><?php esc_html_e( 'Description', 'lnkup-marketplace' ); ?></label>
								<textarea id="description" name="description" rows="3"></textarea>
							</div>
							<div class="lnkup-form-row" data-testid="lnkup-event-gallery">
								<label><?php esc_html_e( 'Photos & videos (optional)', 'lnkup-marketplace' ); ?></label>
								<p class="lnkup-form-hint lnkup-muted"><?php esc_html_e( 'Add photos or short clips from past or similar events. Vendors see these when they open the opportunity.', 'lnkup-marketplace' ); ?></p>
								<input type="hidden" name="gallery_ids" id="lnkup_event_gallery_ids" value="" />
								<div id="lnkup-event-gallery-preview" class="lnkup-event-gallery-preview"></div>
								<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" id="lnkup-event-gallery-pick" data-testid="lnkup-event-gallery-pick"><?php esc_html_e( 'Add media', 'lnkup-marketplace' ); ?></button>
							</div>
						</section>

						<section class="lnkup-panel lnkup-organizer-create-section" id="lnkup-create-step-2">
							<h3 class="lnkup-panel__title"><?php esc_html_e( 'Date & location', 'lnkup-marketplace' ); ?></h3>
							<div class="lnkup-grid lnkup-grid--2">
								<div class="lnkup-form-row">
									<label for="event_date"><?php esc_html_e( 'Event date', 'lnkup-marketplace' ); ?></label>
									<input type="date" id="event_date" name="event_date" data-testid="lnkup-event-date" />
								</div>
								<div class="lnkup-form-row">
									<label for="start_time"><?php esc_html_e( 'Start time', 'lnkup-marketplace' ); ?></label>
									<input type="time" id="start_time" name="start_time" />
								</div>
								<div class="lnkup-form-row">
									<label for="end_time"><?php esc_html_e( 'End time', 'lnkup-marketplace' ); ?></label>
									<input type="time" id="end_time" name="end_time" />
								</div>
								<div class="lnkup-form-row">
									<label for="event_city"><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></label>
									<input type="text" id="event_city" name="city" />
								</div>
								<div class="lnkup-form-row">
									<label for="event_state"><?php esc_html_e( 'State', 'lnkup-marketplace' ); ?></label>
									<input type="text" id="event_state" name="state" maxlength="2" />
								</div>
								<div class="lnkup-form-row">
									<label for="venue_name"><?php esc_html_e( 'Venue', 'lnkup-marketplace' ); ?></label>
									<input type="text" id="venue_name" name="venue_name" />
								</div>
								<div class="lnkup-form-row">
									<label for="expected_attendance"><?php esc_html_e( 'Expected attendance', 'lnkup-marketplace' ); ?></label>
									<input type="number" id="expected_attendance" name="expected_attendance" min="0" />
								</div>
							</div>
							<div class="lnkup-form-row">
								<label for="venue_address"><?php esc_html_e( 'Venue address', 'lnkup-marketplace' ); ?></label>
								<textarea id="venue_address" name="venue_address" rows="2"></textarea>
							</div>
							<div class="lnkup-form-row">
								<label><input type="checkbox" name="venue_hidden" value="1" checked /> <?php esc_html_e( 'Hide venue address until vendor payment', 'lnkup-marketplace' ); ?></label>
							</div>
						</section>

						<section class="lnkup-panel lnkup-organizer-create-section" id="lnkup-create-step-3">
							<h3 class="lnkup-panel__title"><?php esc_html_e( 'Booth pricing by vendor type', 'lnkup-marketplace' ); ?></h3>
							<p class="lnkup-muted"><?php esc_html_e( 'Food trucks, merchants, and service vendors can each have their own booth price and spots.', 'lnkup-marketplace' ); ?></p>
							<div class="lnkup-form-row">
								<label>
									<input type="checkbox" name="open_vendor_applications" id="lnkup_open_vendor_applications" value="1" checked data-testid="lnkup-open-vendor-apps" />
									<?php esc_html_e( 'Open vendor applications when I publish', 'lnkup-marketplace' ); ?>
								</label>
							</div>
							<div id="lnkup-create-vendor-pricing" class="lnkup-create-vendor-pricing" data-fee-rows>
								<input type="hidden" name="opportunities_json" id="lnkup_opportunities_json" value="" />
								<div id="lnkup-fee-rows" class="lnkup-fee-rows">
									<div class="lnkup-fee-row lnkup-grid lnkup-grid--3" data-fee-row>
										<div class="lnkup-form-row">
											<label><?php esc_html_e( 'Vendor type', 'lnkup-marketplace' ); ?></label>
											<select name="fee_category[]" class="lnkup-fee-row__category" required data-testid="lnkup-create-fee-category">
												<option value=""><?php esc_html_e( 'Select type…', 'lnkup-marketplace' ); ?></option>
												<?php if ( ! is_wp_error( $categories ) ) : ?>
													<?php foreach ( $categories as $term ) : ?>
														<option value="<?php echo esc_attr( $term->name ); ?>"><?php echo esc_html( $term->name ); ?></option>
													<?php endforeach; ?>
												<?php endif; ?>
											</select>
										</div>
										<div class="lnkup-form-row">
											<label><?php esc_html_e( 'Booth price ($)', 'lnkup-marketplace' ); ?></label>
											<input type="number" name="fee_amount[]" class="lnkup-fee-row__fee" min="0" step="0.01" value="250" required data-testid="lnkup-create-opp-fee" />
										</div>
										<div class="lnkup-form-row">
											<label><?php esc_html_e( 'Spots', 'lnkup-marketplace' ); ?></label>
											<input type="number" name="fee_spots[]" class="lnkup-fee-row__spots" min="1" value="10" required data-testid="lnkup-create-opp-spots" />
										</div>
									</div>
								</div>
								<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" id="lnkup-add-fee-row" data-testid="lnkup-add-fee-row"><?php esc_html_e( 'Add another vendor type & price', 'lnkup-marketplace' ); ?></button>
								<template id="lnkup-fee-row-template">
									<div class="lnkup-fee-row lnkup-grid lnkup-grid--3" data-fee-row>
										<div class="lnkup-form-row">
											<label><?php esc_html_e( 'Vendor type', 'lnkup-marketplace' ); ?></label>
											<select name="fee_category[]" class="lnkup-fee-row__category" required>
												<option value=""><?php esc_html_e( 'Select type…', 'lnkup-marketplace' ); ?></option>
												<?php if ( ! is_wp_error( $categories ) ) : ?>
													<?php foreach ( $categories as $term ) : ?>
														<option value="<?php echo esc_attr( $term->name ); ?>"><?php echo esc_html( $term->name ); ?></option>
													<?php endforeach; ?>
												<?php endif; ?>
											</select>
										</div>
										<div class="lnkup-form-row">
											<label><?php esc_html_e( 'Booth price ($)', 'lnkup-marketplace' ); ?></label>
											<input type="number" name="fee_amount[]" class="lnkup-fee-row__fee" min="0" step="0.01" value="250" required />
										</div>
										<div class="lnkup-form-row">
											<label><?php esc_html_e( 'Spots', 'lnkup-marketplace' ); ?></label>
											<div class="lnkup-fee-row__spots-wrap">
												<input type="number" name="fee_spots[]" class="lnkup-fee-row__spots" min="1" value="10" required />
												<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm lnkup-fee-row__remove" aria-label="<?php esc_attr_e( 'Remove', 'lnkup-marketplace' ); ?>">&times;</button>
											</div>
										</div>
									</div>
								</template>
							</div>
						</section>

						<div class="lnkup-organizer-wizard-footer">
							<button type="button" class="lnkup-btn lnkup-btn--ghost" data-wizard-nav="prev" hidden><?php esc_html_e( 'Back', 'lnkup-marketplace' ); ?></button>
							<button type="button" class="lnkup-btn lnkup-btn--outline" data-wizard-nav="next"><?php esc_html_e( 'Continue', 'lnkup-marketplace' ); ?></button>
							<button type="submit" form="lnkup-create-event-form" class="lnkup-btn lnkup-btn--primary" id="lnkup-create-event-submit-footer" data-testid="lnkup-publish-event" hidden><?php esc_html_e( 'Publish event', 'lnkup-marketplace' ); ?></button>
						</div>
					</form>
				</div>
				<aside class="lnkup-organizer-create-sidebar" id="lnkup-create-step-4">
					<div class="lnkup-panel lnkup-organizer-create-section lnkup-organizer-create-section--publish">
						<h3 class="lnkup-panel__title"><?php esc_html_e( 'Review', 'lnkup-marketplace' ); ?></h3>
						<p class="lnkup-muted"><?php esc_html_e( 'Confirm details, then publish. Vendor fee and spots are included when applications are open.', 'lnkup-marketplace' ); ?></p>
						<dl class="lnkup-organizer-publish-summary">
							<div class="lnkup-organizer-publish-summary__row">
								<dt><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></dt>
								<dd id="lnkup-publish-event-title"><?php esc_html_e( '—', 'lnkup-marketplace' ); ?></dd>
							</div>
							<div class="lnkup-organizer-publish-summary__row">
								<dt><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></dt>
								<dd id="lnkup-publish-event-date"><?php esc_html_e( '—', 'lnkup-marketplace' ); ?></dd>
							</div>
							<div class="lnkup-organizer-publish-summary__row">
								<dt><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></dt>
								<dd id="lnkup-publish-event-city"><?php esc_html_e( '—', 'lnkup-marketplace' ); ?></dd>
							</div>
							<div class="lnkup-organizer-publish-summary__row" id="lnkup-publish-vendor-fee-row">
								<dt><?php esc_html_e( 'Booth pricing', 'lnkup-marketplace' ); ?></dt>
								<dd id="lnkup-publish-vendor-fee"><?php esc_html_e( '—', 'lnkup-marketplace' ); ?></dd>
							</div>
							<div class="lnkup-organizer-publish-summary__row" id="lnkup-publish-spots-row">
								<dt><?php esc_html_e( 'Spots', 'lnkup-marketplace' ); ?></dt>
								<dd id="lnkup-publish-spots"><?php esc_html_e( '—', 'lnkup-marketplace' ); ?></dd>
							</div>
							<div class="lnkup-organizer-publish-summary__row">
								<dt><?php esc_html_e( 'Platform fee', 'lnkup-marketplace' ); ?></dt>
								<dd><?php printf( esc_html__( '%s%% per booking', 'lnkup-marketplace' ), esc_html( (string) $commission ) ); ?></dd>
							</div>
						</dl>
						<div id="lnkup-create-event-error" class="lnkup-create-event-success" hidden></div>
						<button type="submit" form="lnkup-create-event-form" class="lnkup-btn lnkup-btn--primary lnkup-btn--block" id="lnkup-create-event-submit" data-testid="lnkup-publish-event-sidebar"><?php esc_html_e( 'Publish event', 'lnkup-marketplace' ); ?></button>
					</div>
				</aside>
			</div>
		</div>
		<?php
		echo self::render_vendor_onboarding_step( $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return ob_get_clean();
	}

	/**
	 * Post-event vendor onboarding step and first opportunity form.
	 *
	 * @param array<string, mixed> $ctx Form context.
	 */
	private static function render_vendor_onboarding_step( array $ctx ) {
		$settings           = $ctx['settings'];
		$doc_types          = $ctx['doc_types'];
		$categories         = $ctx['categories'];
		$default_documents  = $ctx['default_documents'];
		ob_start();
		?>
		<section id="lnkup-vendor-onboarding-step" class="lnkup-panel lnkup-vendor-onboarding" hidden data-testid="lnkup-vendor-onboarding">
			<p class="lnkup-vendor-onboarding__badge"><?php esc_html_e( 'Event saved', 'lnkup-marketplace' ); ?></p>
			<h3 class="lnkup-panel__title" id="lnkup-onboarding-event-title"></h3>
			<h4 class="lnkup-vendor-onboarding__heading"><?php echo esc_html( lnkup_copy( 'open_vendor_applications' ) ); ?></h4>
			<p><?php echo esc_html( lnkup_copy( 'onboarding_vendor_apply_copy' ) ); ?></p>
			<p class="lnkup-muted"><?php echo esc_html( lnkup_copy( 'onboarding_vendor_fee_copy' ) ); ?></p>

			<div class="lnkup-onboarding-choices" data-testid="lnkup-onboarding-choices">
				<button type="button" class="lnkup-btn lnkup-btn--primary" data-onboarding-choice="accept" data-testid="lnkup-accept-vendors"><?php echo esc_html( lnkup_copy( 'accept_vendors' ) ); ?></button>
				<button type="button" class="lnkup-btn lnkup-btn--outline" data-onboarding-choice="skip" data-testid="lnkup-skip-vendors"><?php echo esc_html( lnkup_copy( 'skip_for_now' ) ); ?></button>
			</div>

			<form class="lnkup-form lnkup-opportunity-form lnkup-first-opportunity-form" id="lnkup-create-first-opportunity-form" hidden data-testid="lnkup-first-opportunity-form">
				<?php wp_nonce_field( 'lnkup_opportunity', 'lnkup_nonce' ); ?>
				<input type="hidden" name="action" value="lnkup_create_opportunity" />
				<input type="hidden" name="event_id" id="lnkup_onboarding_event_id" value="" />
				<input type="hidden" name="from_onboarding" value="1" />
				<input type="hidden" name="client_token" id="lnkup_opp_client_token" value="" />

				<div class="lnkup-grid lnkup-grid--2">
					<div class="lnkup-form-row lnkup-form-row--full">
						<label for="opportunity_title"><?php esc_html_e( 'Opportunity title', 'lnkup-marketplace' ); ?></label>
						<input type="text" id="opportunity_title" name="opportunity_title" placeholder="<?php esc_attr_e( 'Vendors for your event', 'lnkup-marketplace' ); ?>" data-testid="lnkup-opp-title" />
					</div>
					<div class="lnkup-form-row">
						<label for="onboarding_vendor_fee"><?php esc_html_e( 'Booth / vendor fee ($)', 'lnkup-marketplace' ); ?></label>
						<input type="number" id="onboarding_vendor_fee" name="vendor_fee" min="0" step="0.01" value="250" required data-testid="lnkup-opp-fee" />
					</div>
					<div class="lnkup-form-row">
						<label for="onboarding_spots_total"><?php esc_html_e( 'Vendor spots', 'lnkup-marketplace' ); ?></label>
						<input type="number" id="onboarding_spots_total" name="spots_total" min="1" value="10" required data-testid="lnkup-opp-spots" />
					</div>
					<div class="lnkup-form-row">
						<label for="onboarding_application_deadline"><?php esc_html_e( 'Application deadline', 'lnkup-marketplace' ); ?></label>
						<input type="date" id="onboarding_application_deadline" name="application_deadline" data-testid="lnkup-opp-deadline" />
					</div>
					<div class="lnkup-form-row">
						<label for="onboarding_expected_attendance"><?php esc_html_e( 'Expected attendance (optional)', 'lnkup-marketplace' ); ?></label>
						<input type="number" id="onboarding_expected_attendance" name="expected_attendance" min="0" />
					</div>
				</div>

				<?php if ( $categories && ! is_wp_error( $categories ) ) : ?>
					<div class="lnkup-form-row">
						<span class="lnkup-form-label"><?php esc_html_e( 'Vendor categories needed', 'lnkup-marketplace' ); ?></span>
						<div class="lnkup-checkbox-grid lnkup-category-pills">
							<?php foreach ( $categories as $cat ) : ?>
								<label class="lnkup-pill-check"><input type="checkbox" name="vendor_categories[]" value="<?php echo esc_attr( $cat->name ); ?>" /> <?php echo esc_html( $cat->name ); ?></label>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $doc_types ) : ?>
					<div class="lnkup-form-row" data-testid="lnkup-document-mode">
						<span class="lnkup-form-label"><?php esc_html_e( 'Vendor documents', 'lnkup-marketplace' ); ?></span>
						<div class="lnkup-radio-inline lnkup-document-mode-radios">
							<label><input type="radio" name="document_mode" value="none" checked data-testid="lnkup-doc-mode-none" /> <?php esc_html_e( 'No documents required', 'lnkup-marketplace' ); ?></label>
							<label><input type="radio" name="document_mode" value="platform_defaults" data-testid="lnkup-doc-mode-defaults" /> <?php esc_html_e( 'Use platform defaults', 'lnkup-marketplace' ); ?></label>
							<label><input type="radio" name="document_mode" value="custom" data-testid="lnkup-doc-mode-custom" /> <?php esc_html_e( 'Choose required documents', 'lnkup-marketplace' ); ?></label>
						</div>
						<div class="lnkup-checkbox-grid lnkup-document-custom-list" id="lnkup-document-custom-list" hidden>
							<?php foreach ( $doc_types as $slug => $label ) : ?>
								<label><input type="checkbox" name="required_documents[]" value="<?php echo esc_attr( $slug ); ?>" /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<fieldset class="lnkup-form-row lnkup-environment-fieldset">
					<legend class="lnkup-form-label"><?php esc_html_e( 'Environment', 'lnkup-marketplace' ); ?></legend>
					<div class="lnkup-radio-inline">
						<label><input type="radio" name="environment" value="indoor" /> <?php esc_html_e( 'Indoor', 'lnkup-marketplace' ); ?></label>
						<label><input type="radio" name="environment" value="outdoor" checked /> <?php esc_html_e( 'Outdoor', 'lnkup-marketplace' ); ?></label>
						<label><input type="radio" name="environment" value="both" /> <?php esc_html_e( 'Both', 'lnkup-marketplace' ); ?></label>
					</div>
				</fieldset>

				<div class="lnkup-form-row">
					<label for="onboarding_opp_description"><?php esc_html_e( 'Short description', 'lnkup-marketplace' ); ?></label>
					<textarea id="onboarding_opp_description" name="description" rows="3" placeholder="<?php esc_attr_e( 'What vendors should know about this opportunity…', 'lnkup-marketplace' ); ?>"></textarea>
				</div>

				<div class="lnkup-form-row lnkup-checkbox-inline">
					<label><input type="checkbox" name="deposit_required" value="1" /> <?php esc_html_e( 'Require deposit at checkout (optional)', 'lnkup-marketplace' ); ?></label>
					<label><input type="checkbox" name="packet_lock" value="1" <?php checked( ! empty( $settings['default_packet_lock'] ) ); ?> /> <?php esc_html_e( 'Lock event packet until payment', 'lnkup-marketplace' ); ?></label>
				</div>

				<div id="lnkup-onboarding-opp-loading" class="lnkup-onboarding-loading" hidden>
					<span class="lnkup-spinner" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Publishing opportunity…', 'lnkup-marketplace' ); ?></span>
				</div>
				<div id="lnkup-onboarding-opp-error" class="lnkup-create-event-success" hidden data-testid="lnkup-onboarding-opp-error"></div>

				<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--block" id="lnkup-publish-opportunity-btn" data-testid="lnkup-publish-opportunity"><?php echo esc_html( lnkup_copy( 'publish_vendor_opportunity' ) ); ?></button>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Opportunity form for an existing event.
	 */
	private static function render_create_opportunity_form() {
		$user_id = get_current_user_id();
		$events  = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $user_id,
			)
		);
		if ( ! $events ) {
			return '';
		}
		$ctx = self::organizer_create_form_context();
		$categories = $ctx['categories'];
		ob_start();
		?>
		<div class="lnkup-panel lnkup-organizer-section">
			<h3 class="lnkup-panel__title"><?php esc_html_e( 'Set fee & spots for this event', 'lnkup-marketplace' ); ?></h3>
			<p class="lnkup-muted"><?php esc_html_e( 'Add a booth listing with vendor fee and number of spots so vendors can apply in Find Events.', 'lnkup-marketplace' ); ?></p>
			<form class="lnkup-form lnkup-opportunity-form" id="lnkup-create-opportunity-form">
				<?php wp_nonce_field( 'lnkup_opportunity', 'lnkup_nonce' ); ?>
				<input type="hidden" name="action" value="lnkup_create_opportunity" />
				<div class="lnkup-form-row">
					<label for="opp_event_id"><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></label>
					<select id="opp_event_id" name="event_id" required>
						<?php foreach ( $events as $event ) : ?>
							<option value="<?php echo esc_attr( (string) $event->ID ); ?>"><?php echo esc_html( $event->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="lnkup-form-row">
					<label for="opp_opportunity_title"><?php esc_html_e( 'Opportunity title', 'lnkup-marketplace' ); ?></label>
					<input type="text" id="opp_opportunity_title" name="opportunity_title" />
				</div>
				<div class="lnkup-grid lnkup-grid--2">
					<div class="lnkup-form-row">
						<label for="opp_spots_total"><?php esc_html_e( 'Number of spots', 'lnkup-marketplace' ); ?></label>
						<input type="number" id="opp_spots_total" name="spots_total" min="1" value="10" required />
					</div>
					<div class="lnkup-form-row">
						<label for="opp_vendor_fee"><?php esc_html_e( 'Vendor fee ($)', 'lnkup-marketplace' ); ?></label>
						<input type="number" id="opp_vendor_fee" name="vendor_fee" min="0" step="0.01" value="250" required />
					</div>
					<div class="lnkup-form-row">
						<label for="opp_deadline"><?php esc_html_e( 'Application deadline', 'lnkup-marketplace' ); ?></label>
						<input type="date" id="opp_deadline" name="deadline" />
					</div>
				</div>
				<?php if ( $categories && ! is_wp_error( $categories ) ) : ?>
					<div class="lnkup-form-row">
						<span class="lnkup-form-label"><?php esc_html_e( 'Vendor categories', 'lnkup-marketplace' ); ?></span>
						<div class="lnkup-checkbox-grid">
							<?php foreach ( $categories as $cat ) : ?>
								<label><input type="checkbox" name="vendor_categories[]" value="<?php echo esc_attr( $cat->name ); ?>" /> <?php echo esc_html( $cat->name ); ?></label>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
				<fieldset class="lnkup-form-row">
					<legend class="lnkup-form-label"><?php esc_html_e( 'Environment', 'lnkup-marketplace' ); ?></legend>
					<div class="lnkup-radio-inline">
						<label><input type="radio" name="environment" value="indoor" /> <?php esc_html_e( 'Indoor', 'lnkup-marketplace' ); ?></label>
						<label><input type="radio" name="environment" value="outdoor" checked /> <?php esc_html_e( 'Outdoor', 'lnkup-marketplace' ); ?></label>
						<label><input type="radio" name="environment" value="both" /> <?php esc_html_e( 'Both', 'lnkup-marketplace' ); ?></label>
					</div>
				</fieldset>
				<div class="lnkup-form-row">
					<label for="opp_description"><?php esc_html_e( 'Description', 'lnkup-marketplace' ); ?></label>
					<textarea id="opp_description" name="description" rows="3"></textarea>
				</div>
				<div class="lnkup-form-row">
					<label for="opp_notes"><?php esc_html_e( 'Organizer notes', 'lnkup-marketplace' ); ?></label>
					<textarea id="opp_notes" name="notes" rows="2"></textarea>
				</div>
				<div id="lnkup-create-opportunity-success" class="lnkup-create-event-success" hidden></div>
				<button type="submit" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Create opportunity', 'lnkup-marketplace' ); ?></button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Table of organizer opportunities with inline fee/spots edit.
	 */
	private static function render_opportunities_table() {
		$user_id = get_current_user_id();
		$opps    = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $user_id,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
			)
		);
		if ( ! $opps ) {
			return '';
		}
		ob_start();
		echo '<div class="lnkup-panel lnkup-organizer-opportunities-table" id="lnkup-opportunities-pricing" data-testid="lnkup-opportunities-pricing">';
		echo '<h3 class="lnkup-panel__title">' . esc_html__( 'Edit fee & spots', 'lnkup-marketplace' ) . '</h3>';
		echo '<p class="lnkup-muted" id="lnkup-opp-pricing-hint">' . esc_html__( 'Update booth fee or spot count without recreating the listing. Click “Edit fee & spots” on an event to focus that event.', 'lnkup-marketplace' ) . '</p>';
		echo '<p class="lnkup-muted lnkup-opp-pricing-empty" id="lnkup-opp-pricing-empty" hidden>' . esc_html__( 'No opportunities for this event yet. Add one from Launch event.', 'lnkup-marketplace' ) . '</p>';
		echo '<table class="lnkup-table"><thead><tr><th>' . esc_html__( 'Event', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Opportunity', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Quality', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Fee & spots', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Deadline', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
		foreach ( $opps as $opp ) {
			$event_id    = (int) get_post_meta( $opp->ID, '_lnkup_event_id', true );
			$event_title = $event_id ? get_the_title( $event_id ) : '';
			if ( ! $event_title ) {
				$event_title = __( 'Event', 'lnkup-marketplace' );
			}
			$quality_html = '—';
			if ( class_exists( 'LNKup_Opportunity_Score' ) ) {
				$quality      = LNKup_Opportunity_Score::compute( $opp->ID );
				$quality_html = '<span class="lnkup-intel-score-inline" title="' . esc_attr( $quality['label'] ?? '' ) . '">' . esc_html( (string) ( $quality['quality_score'] ?? 0 ) ) . '</span>';
			}
			$fee   = (float) get_post_meta( $opp->ID, '_lnkup_vendor_fee', true );
			$spots = (int) get_post_meta( $opp->ID, '_lnkup_spots_total', true );
			echo '<tr data-opportunity-id="' . esc_attr( (string) $opp->ID ) . '" data-event-id="' . esc_attr( (string) $event_id ) . '">';
			echo '<td>' . esc_html( $event_title ) . '</td>';
			echo '<td>' . esc_html( $opp->post_title ) . '</td>';
			echo '<td>' . $quality_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><div class="lnkup-opp-pricing-edit">';
			echo '<div class="lnkup-form-row"><label class="screen-reader-text" for="opp_fee_' . esc_attr( (string) $opp->ID ) . '">' . esc_html__( 'Vendor fee', 'lnkup-marketplace' ) . '</label>';
			echo '<input type="number" id="opp_fee_' . esc_attr( (string) $opp->ID ) . '" class="lnkup-opp-fee-input" min="0" step="0.01" value="' . esc_attr( (string) $fee ) . '" data-testid="lnkup-edit-opp-fee" /></div>';
			echo '<div class="lnkup-form-row"><label class="screen-reader-text" for="opp_spots_' . esc_attr( (string) $opp->ID ) . '">' . esc_html__( 'Spots', 'lnkup-marketplace' ) . '</label>';
			echo '<input type="number" id="opp_spots_' . esc_attr( (string) $opp->ID ) . '" class="lnkup-opp-spots-input" min="1" value="' . esc_attr( (string) max( 1, $spots ) ) . '" data-testid="lnkup-edit-opp-spots" /></div>';
			echo '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary lnkup-opp-pricing-save" data-opportunity-id="' . esc_attr( (string) $opp->ID ) . '">' . esc_html__( 'Save', 'lnkup-marketplace' ) . '</button>';
			echo '</div></td>';
			echo '<td>' . esc_html( get_post_meta( $opp->ID, '_lnkup_application_deadline', true ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		return ob_get_clean();
	}

	/**
	 * Create opportunity for an existing event (legacy standalone panel).
	 */
	private static function render_create_opportunity_panel() {
		$user_id  = get_current_user_id();
		$events   = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $user_id,
			)
		);
		$dash_url = lnkup_page_url_helper( 'organizer-dashboard' );
		ob_start();
		if ( ! $events ) {
			echo '<div class="lnkup-empty-state lnkup-empty-state--compact">';
			echo '<p class="lnkup-empty-state__title">' . esc_html__( 'Create an event first', 'lnkup-marketplace' ) . '</p>';
			echo '<p class="lnkup-empty-state__desc">' . esc_html__( 'You need at least one event before adding a vendor opportunity.', 'lnkup-marketplace' ) . '</p>';
			echo '<div class="lnkup-empty-state__actions"><a href="' . esc_url( $dash_url . '#create' ) . '" class="lnkup-btn lnkup-btn--primary">' . esc_html__( 'Create event', 'lnkup-marketplace' ) . '</a></div>';
			echo '</div>';
			return ob_get_clean();
		}
		echo self::render_create_opportunity_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="lnkup-muted"><a href="#events" class="lnkup-link">' . esc_html__( 'Edit fee & spots for existing opportunities →', 'lnkup-marketplace' ) . '</a></p>';
		return ob_get_clean();
	}

	/**
	 * Vendor CRM profile view.
	 *
	 * @param int $profile_id Profile post ID.
	 */
	public static function render_vendor_crm_profile( $profile_id ) {
		$profile = LNKup_Vendor_CRM_Profile::get( $profile_id, get_current_user_id() );
		if ( ! $profile ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'Vendor not found.', 'lnkup-marketplace' ) . '</p>';
		}
		$back = lnkup_page_url_helper( 'organizer-dashboard' ) . '#vendors';
		$timeline = LNKup_Vendor_Timeline::get_for_vendor( get_current_user_id(), $profile['vendor_user_id'] );
		$timeline_visible = array_slice( $timeline, 0, 8 );
		$timeline_groups  = array();
		foreach ( $timeline_visible as $item ) {
			$ts = ! empty( $item['created_at'] ) ? strtotime( $item['created_at'] ) : false;
			$date_key = $ts ? date_i18n( 'M j, Y', $ts ) : __( 'Unknown date', 'lnkup-marketplace' );
			if ( ! isset( $timeline_groups[ $date_key ] ) ) {
				$timeline_groups[ $date_key ] = array();
			}
			$timeline_groups[ $date_key ][] = $item;
		}
		$reliability_intel = class_exists( 'LNKup_Vendor_Reliability_Intel' )
			? LNKup_Vendor_Reliability_Intel::for_profile( $profile_id, get_current_user_id() )
			: null;
		$completed_events = (int) ( $profile['reliability']['completed_events'] ?? $profile['completed_events'] ?? 0 );
		$avg_rating       = ! empty( $profile['avg_rating'] ) ? (string) $profile['avg_rating'] : '—';
		$city_label       = ! empty( $profile['city'] ) ? (string) $profile['city'] : ( ! empty( $profile['location'] ) ? (string) $profile['location'] : '' );
		ob_start();
		?>
		<div class="lnkup-mp lnkup-crm lnkup-crm-profile lnkup-crm-profile--premium" id="lnkup-crm-profile" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>" data-rest="<?php echo esc_url( rest_url( 'lnkup/v1/crm' ) ); ?>">
			<p><a href="<?php echo esc_url( $back ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm">&larr; <?php esc_html_e( 'Back to directory', 'lnkup-marketplace' ); ?></a></p>
			<div class="lnkup-crm-profile__header">
				<?php if ( $profile['logo'] ) : ?><img src="<?php echo esc_url( $profile['logo'] ); ?>" alt="" class="lnkup-crm-profile__logo" /><?php endif; ?>
				<div>
					<h1 class="lnkup-heading lnkup-heading--page"><?php echo esc_html( $profile['business_name'] ); ?></h1>
					<p class="lnkup-muted">
						<?php echo esc_html( $profile['owner_name'] ); ?>
						<?php if ( ! empty( $profile['category'] ) ) : ?> · <?php echo esc_html( implode( ', ', $profile['category'] ) ); ?><?php endif; ?>
						<?php if ( $city_label ) : ?> · <?php echo esc_html( $city_label ); ?><?php endif; ?>
					</p>
					<div class="lnkup-crm-profile__badges">
						<?php echo LNKup_Reputation_Render::score_badge( $profile['vendor_score'], $profile['reputation_label'] ?? '', ! empty( $profile['reputation']['is_new'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( ! empty( $profile['avg_rating'] ) ) : ?>
							<?php echo LNKup_Reputation_Render::star_rating( $profile['avg_rating'], $profile['review_count'] ?? 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
						<?php echo LNKup_Reputation_Render::badge_grid( $profile['badges'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php foreach ( $profile['tags'] as $tag ) : ?><span class="lnkup-badge lnkup-badge--secondary"><?php echo esc_html( $tag['label'] ); ?></span><?php endforeach; ?>
						<?php foreach ( $profile['flags'] as $flag ) : ?><span class="lnkup-badge lnkup-badge--destructive"><?php echo esc_html( $flag['label'] ); ?></span><?php endforeach; ?>
					</div>
				</div>
				<div class="lnkup-crm-profile__actions">
					<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-crm-fav" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>"><?php echo $profile['is_favorite'] ? '★' : '☆'; ?> <?php esc_html_e( 'Favorite', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-crm-pref<?php echo ! empty( $profile['is_preferred'] ) ? ' is-active' : ''; ?>" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>"><?php esc_html_e( 'Preferred', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm lnkup-crm-invite" data-vendor-id="<?php echo esc_attr( (string) $profile['vendor_user_id'] ); ?>" data-vendor-name="<?php echo esc_attr( $profile['business_name'] ); ?>" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>"><?php esc_html_e( 'Invite', 'lnkup-marketplace' ); ?></button>
				</div>
			</div>

			<div class="lnkup-crm-profile__metrics-row">
				<div class="lnkup-crm-profile__metric"><span class="lnkup-type-meta"><?php esc_html_e( 'Completed events', 'lnkup-marketplace' ); ?></span><strong><?php echo esc_html( (string) $completed_events ); ?></strong></div>
				<div class="lnkup-crm-profile__metric"><span class="lnkup-type-meta"><?php esc_html_e( 'Avg rating', 'lnkup-marketplace' ); ?></span><strong><?php echo esc_html( $avg_rating ); ?></strong></div>
				<div class="lnkup-crm-profile__metric"><span class="lnkup-type-meta"><?php esc_html_e( 'Payment reliability', 'lnkup-marketplace' ); ?></span><strong><?php echo esc_html( $reliability_intel['payment_likelihood'] ?? '—' ); ?></strong></div>
				<div class="lnkup-crm-profile__metric"><span class="lnkup-type-meta"><?php esc_html_e( 'No-show risk', 'lnkup-marketplace' ); ?></span><strong><?php echo esc_html( $reliability_intel['no_show_likelihood'] ?? '—' ); ?></strong></div>
				<?php if ( ! empty( $profile['profile_strength_tier'] ) || ! empty( $profile['profile_strength_score'] ) ) : ?>
					<div class="lnkup-crm-profile__metric" data-testid="lnkup-crm-profile-strength">
						<span class="lnkup-type-meta"><?php esc_html_e( 'Profile strength', 'lnkup-marketplace' ); ?></span>
						<strong><?php echo esc_html( trim( (string) ( $profile['profile_strength_tier'] ?? '' ) . ' · ' . (int) ( $profile['profile_strength_score'] ?? 0 ) . '%' ) ); ?></strong>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $reliability_intel && ! empty( $reliability_intel['flags'] ) ) : ?>
				<section class="lnkup-panel lnkup-intel-profile-flags">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Reliability intelligence', 'lnkup-marketplace' ); ?></h2>
					<div class="lnkup-intel-flags">
						<?php foreach ( $reliability_intel['flags'] as $flag ) : ?>
							<span class="lnkup-intel-flag lnkup-intel-flag--<?php echo esc_attr( $flag['type'] ); ?>"><?php echo esc_html( $flag['label'] ); ?> — <?php echo esc_html( $flag['message'] ); ?></span>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( ! empty( $profile['description'] ) ) : ?>
				<section class="lnkup-panel" data-testid="lnkup-crm-about">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'About', 'lnkup-marketplace' ); ?></h2>
					<div class="lnkup-crm-profile__about"><?php echo wp_kses_post( wpautop( $profile['description'] ) ); ?></div>
				</section>
			<?php endif; ?>

			<?php
			$gallery_urls = array_values( array_filter( (array) ( $profile['gallery'] ?? array() ) ) );
			if ( ! empty( $profile['cover_image'] ) ) {
				array_unshift( $gallery_urls, (string) $profile['cover_image'] );
				$gallery_urls = array_values( array_unique( $gallery_urls ) );
			}
			?>
			<?php if ( $gallery_urls ) : ?>
				<section class="lnkup-panel" data-testid="lnkup-crm-gallery">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Gallery', 'lnkup-marketplace' ); ?></h2>
					<div class="lnkup-crm-gallery">
						<?php foreach ( $gallery_urls as $img_url ) : ?>
							<figure class="lnkup-crm-gallery__item">
								<img src="<?php echo esc_url( $img_url ); ?>" alt="" loading="lazy" />
							</figure>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<div class="lnkup-crm-profile__grid lnkup-crm-profile__grid--premium">
				<section class="lnkup-panel">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Contact & setup', 'lnkup-marketplace' ); ?></h2>
					<ul class="lnkup-crm-facts">
						<li class="<?php echo $profile['email'] ? '' : 'lnkup-crm-fact--empty'; ?>"><?php echo $profile['email'] ? esc_html( $profile['email'] ) : esc_html__( 'Email not provided', 'lnkup-marketplace' ); ?></li>
						<li class="<?php echo $profile['phone'] ? '' : 'lnkup-crm-fact--empty'; ?>"><?php echo $profile['phone'] ? esc_html( $profile['phone'] ) : esc_html__( 'Phone not provided', 'lnkup-marketplace' ); ?></li>
						<li class="<?php echo $profile['instagram'] ? '' : 'lnkup-crm-fact--empty'; ?>"><?php echo $profile['instagram'] ? esc_html( $profile['instagram'] ) : esc_html__( 'Instagram not provided', 'lnkup-marketplace' ); ?></li>
						<li class="<?php echo $profile['website'] ? '' : 'lnkup-crm-fact--empty'; ?>">
							<?php if ( $profile['website'] ) : ?>
								<a href="<?php echo esc_url( $profile['website'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $profile['website'] ); ?></a>
							<?php else : ?>
								<?php esc_html_e( 'Website not provided', 'lnkup-marketplace' ); ?>
							<?php endif; ?>
						</li>
						<li class="<?php echo $profile['setup_type'] ? '' : 'lnkup-crm-fact--empty'; ?>"><?php esc_html_e( 'Setup:', 'lnkup-marketplace' ); ?> <?php echo $profile['setup_type'] ? esc_html( $profile['setup_type'] ) : esc_html__( 'Not provided', 'lnkup-marketplace' ); ?></li>
						<li class="<?php echo $profile['power_needs'] ? '' : 'lnkup-crm-fact--empty'; ?>"><?php esc_html_e( 'Power:', 'lnkup-marketplace' ); ?> <?php echo $profile['power_needs'] ? esc_html( $profile['power_needs'] ) : esc_html__( 'Not provided', 'lnkup-marketplace' ); ?></li>
						<li class="<?php echo ! empty( $profile['water_needs'] ) ? '' : 'lnkup-crm-fact--empty'; ?>"><?php esc_html_e( 'Water:', 'lnkup-marketplace' ); ?> <?php echo ! empty( $profile['water_needs'] ) ? esc_html( (string) $profile['water_needs'] ) : esc_html__( 'Not provided', 'lnkup-marketplace' ); ?></li>
						<li class="<?php echo $profile['tent_size'] ? '' : 'lnkup-crm-fact--empty'; ?>"><?php esc_html_e( 'Tent:', 'lnkup-marketplace' ); ?> <?php echo $profile['tent_size'] ? esc_html( $profile['tent_size'] ) : esc_html__( 'Not provided', 'lnkup-marketplace' ); ?></li>
						<li class="<?php echo ! empty( $profile['trailer_size'] ) ? '' : 'lnkup-crm-fact--empty'; ?>"><?php esc_html_e( 'Trailer:', 'lnkup-marketplace' ); ?> <?php echo ! empty( $profile['trailer_size'] ) ? esc_html( (string) $profile['trailer_size'] ) : esc_html__( 'Not provided', 'lnkup-marketplace' ); ?></li>
						<li class="<?php echo ! empty( $profile['space_needed'] ) ? '' : 'lnkup-crm-fact--empty'; ?>"><?php esc_html_e( 'Space:', 'lnkup-marketplace' ); ?> <?php echo ! empty( $profile['space_needed'] ) ? esc_html( (string) $profile['space_needed'] ) : esc_html__( 'Not provided', 'lnkup-marketplace' ); ?></li>
					</ul>
					<?php if ( ! empty( $profile['products_services'] ) ) : ?>
						<p><?php echo esc_html( $profile['products_services'] ); ?></p>
					<?php endif; ?>
				</section>
				<section class="lnkup-panel" data-testid="lnkup-crm-documents">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Documents', 'lnkup-marketplace' ); ?></h2>
					<?php if ( ! empty( $profile['documents'] ) ) : ?>
						<div class="lnkup-crm-doc-chips">
							<?php foreach ( (array) $profile['documents'] as $doc ) : ?>
								<span class="lnkup-crm-doc-chip lnkup-crm-doc-chip--<?php echo esc_attr( sanitize_html_class( (string) ( $doc['status'] ?? 'unknown' ) ) ); ?>">
									<?php echo esc_html( (string) ( $doc['type'] ?? __( 'Document', 'lnkup-marketplace' ) ) ); ?>
									· <?php echo esc_html( (string) ( $doc['status'] ?? '—' ) ); ?>
								</span>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p class="lnkup-muted lnkup-crm-fact--empty"><?php esc_html_e( 'No documents on file.', 'lnkup-marketplace' ); ?></p>
					<?php endif; ?>
				</section>
				<section class="lnkup-panel" data-testid="lnkup-crm-shared-history">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'With you', 'lnkup-marketplace' ); ?></h2>
					<?php
					$past     = (array) ( $profile['past_events'] ?? array() );
					$upcoming = (array) ( $profile['upcoming_events'] ?? array() );
					?>
					<?php if ( $upcoming ) : ?>
						<h3 class="lnkup-crm-profile__subhead"><?php esc_html_e( 'Upcoming / open', 'lnkup-marketplace' ); ?></h3>
						<ul class="lnkup-crm-history-list">
							<?php foreach ( array_slice( $upcoming, 0, 8 ) as $row ) : ?>
								<li>
									<strong><?php echo esc_html( (string) ( $row['event_title'] ?? __( 'Event', 'lnkup-marketplace' ) ) ); ?></strong>
									<span class="lnkup-muted"> · <?php echo esc_html( (string) ( $row['status'] ?? '' ) ); ?><?php echo ! empty( $row['date'] ) ? ' · ' . esc_html( (string) $row['date'] ) : ''; ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( $past ) : ?>
						<h3 class="lnkup-crm-profile__subhead"><?php esc_html_e( 'Past together', 'lnkup-marketplace' ); ?></h3>
						<ul class="lnkup-crm-history-list">
							<?php foreach ( array_slice( $past, 0, 8 ) as $row ) : ?>
								<li>
									<strong><?php echo esc_html( (string) ( $row['event_title'] ?? __( 'Event', 'lnkup-marketplace' ) ) ); ?></strong>
									<span class="lnkup-muted"> · <?php echo esc_html( (string) ( $row['status'] ?? '' ) ); ?><?php echo ! empty( $row['date'] ) ? ' · ' . esc_html( (string) $row['date'] ) : ''; ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( ! $past && ! $upcoming ) : ?>
						<p class="lnkup-muted lnkup-crm-fact--empty"><?php esc_html_e( 'No shared applications with this organizer yet.', 'lnkup-marketplace' ); ?></p>
					<?php endif; ?>
				</section>
				<section class="lnkup-panel">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Reputation', 'lnkup-marketplace' ); ?></h2>
					<?php echo LNKup_Reputation_Render::reliability_meter( $profile['reliability'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo LNKup_Reputation_Render::risk_indicator( $profile['reliability'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<h3><?php esc_html_e( 'Completed events', 'lnkup-marketplace' ); ?></h3>
					<?php echo LNKup_Reputation_Render::completed_events_timeline( LNKup_Completed_Events::get_for_vendor( $profile_id, 'crm' ), 6 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</section>
				<section class="lnkup-panel lnkup-crm-profile__timeline-panel">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Activity timeline', 'lnkup-marketplace' ); ?></h2>
					<?php if ( $timeline_groups ) : ?>
						<div class="lnkup-crm-timeline lnkup-crm-timeline--contained">
							<?php foreach ( $timeline_groups as $date_label => $items ) : ?>
								<div class="lnkup-crm-timeline__group">
									<p class="lnkup-crm-timeline__date"><?php echo esc_html( $date_label ); ?></p>
									<ul class="lnkup-crm-timeline__list">
										<?php foreach ( $items as $item ) : ?>
											<li>
												<strong><?php echo esc_html( $item['label'] ); ?></strong>
												<?php if ( ! empty( $item['event_title'] ) ) : ?> — <?php echo esc_html( $item['event_title'] ); ?><?php endif; ?>
												<?php if ( ! empty( $item['created_at'] ) ) : ?><br><span class="lnkup-muted"><?php echo esc_html( date_i18n( get_option( 'time_format' ), strtotime( $item['created_at'] ) ) ); ?></span><?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endforeach; ?>
						</div>
						<?php if ( count( $timeline ) > 8 ) : ?>
							<p class="lnkup-muted"><a href="#" data-crm-timeline-full><?php esc_html_e( 'View full history', 'lnkup-marketplace' ); ?></a></p>
						<?php endif; ?>
					<?php else : ?>
						<?php
						echo lnkup_empty_state(
							__( 'No activity yet', 'lnkup-marketplace' ),
							__( 'Applications, invites, and notes will appear here as you work with this vendor.', 'lnkup-marketplace' )
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					<?php endif; ?>
				</section>
				<section class="lnkup-panel">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Reviews & notes', 'lnkup-marketplace' ); ?></h2>
					<h3><?php esc_html_e( 'Public reviews', 'lnkup-marketplace' ); ?></h3>
					<?php
					$reviews = LNKup_Vendor_Reviews::get_for_vendor( $profile_id, 5 );
					if ( $reviews ) :
						foreach ( $reviews as $review ) :
							echo LNKup_Reputation_Render::review_card( $review ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						endforeach;
					else :
						echo '<p class="lnkup-muted lnkup-crm-fact--empty">' . esc_html__( 'No public reviews yet.', 'lnkup-marketplace' ) . '</p>';
					endif;
					?>
					<h3><?php esc_html_e( 'Private notes', 'lnkup-marketplace' ); ?></h3>
					<form id="lnkup-crm-note-form" class="lnkup-form">
						<textarea id="lnkup-crm-note-input" rows="3" placeholder="<?php esc_attr_e( 'Add a private note…', 'lnkup-marketplace' ); ?>"></textarea>
						<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Save note', 'lnkup-marketplace' ); ?></button>
					</form>
					<ul id="lnkup-crm-notes-list" class="lnkup-crm-notes">
						<?php foreach ( LNKup_Vendor_Notes::get_for_vendor( get_current_user_id(), $profile['vendor_user_id'] ) as $note ) : ?>
							<li><?php echo esc_html( $note['content'] ); ?> <span class="lnkup-muted"><?php echo esc_html( $note['created_at'] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				</section>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Event roster view.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function render_event_roster_view( $event_id ) {
		$roster = LNKup_Event_Roster::get( $event_id, get_current_user_id() );
		if ( is_wp_error( $roster ) ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html( $roster->get_error_message() ) . '</p>';
		}
		$event = get_post( $event_id );
		$back = lnkup_page_url_helper( 'organizer-dashboard' ) . '#events';
		ob_start();
		?>
		<div class="lnkup-mp lnkup-crm" id="lnkup-event-roster" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>" data-rest="<?php echo esc_url( rest_url( 'lnkup/v1/crm' ) ); ?>">
			<p><a href="<?php echo esc_url( $back ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm">&larr; <?php esc_html_e( 'Back to events', 'lnkup-marketplace' ); ?></a></p>
			<h1 class="lnkup-heading lnkup-heading--page"><?php echo esc_html( $event ? $event->post_title : '' ); ?> — <?php esc_html_e( 'Vendor Roster', 'lnkup-marketplace' ); ?></h1>
			<div class="lnkup-roster-bulk">
				<label><input type="checkbox" id="lnkup-roster-select-all" /> <?php esc_html_e( 'Select all visible', 'lnkup-marketplace' ); ?></label>
				<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" data-roster-bulk="approve"><?php esc_html_e( 'Bulk Approve', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-btn lnkup-btn--sm" data-roster-bulk="waitlist"><?php esc_html_e( 'Waitlist', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-btn lnkup-btn--sm" data-roster-bulk="check_in"><?php esc_html_e( 'Check In', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-roster-bulk="no_show"><?php esc_html_e( 'No-show', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-btn lnkup-btn--sm" data-roster-bulk="mark_complete"><?php esc_html_e( 'Mark complete', 'lnkup-marketplace' ); ?></button>
			</div>
			<div id="lnkup-roster-content"><?php echo $roster['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Event operations command center.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function render_event_operations_view( $event_id ) {
		if ( ! LNKup_Capabilities::organizer_owns_event( $event_id ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'You do not have permission to view operations for this event.', 'lnkup-marketplace' ) . '</p>';
		}
		$snap = LNKup_Event_Operations::snapshot( $event_id, get_current_user_id() );
		if ( ! $snap ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'Event not found.', 'lnkup-marketplace' ) . '</p>';
		}
		$event = get_post( $event_id );
		$kanban = LNKup_Event_Operations::vendors_for_kanban( $event_id );
		$booths = LNKup_Booths::get_for_event( $event_id );
		$parking = LNKup_Parking::get_for_event( $event_id );
		$loadin_windows = LNKup_Loadin_Schedule::get_windows( $event_id );
		$readiness_summary = $snap['readiness_summary'] ?? LNKup_Vendor_Readiness::event_readiness_summary( $event_id );
		$back = lnkup_page_url_helper( 'organizer-dashboard' ) . '#events';
		$scan_url = add_query_arg( array( 'event' => $event_id, 'scan' => 1 ), lnkup_page_url_helper( 'organizer-dashboard' ) );
		$pass_packets = get_posts(
			array(
				'post_type'      => 'lnkup_packet',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		$pass_packet_id = $pass_packets ? (int) $pass_packets[0]->ID : 0;
		$pass_venue     = (string) get_post_meta( $event_id, '_lnkup_venue_address', true );
		$pass_parking   = (string) get_post_meta( $event_id, '_lnkup_parking_notes', true );
		$pass_rules     = $pass_packet_id ? (string) get_post_meta( $pass_packet_id, '_lnkup_vendor_rules', true ) : '';
		$pass_refund    = $pass_packet_id ? (string) get_post_meta( $pass_packet_id, '_lnkup_refund_policy', true ) : '';
		$pass_rain      = $pass_packet_id ? (string) get_post_meta( $pass_packet_id, '_lnkup_rain_plan', true ) : '';
		$pass_heat      = $pass_packet_id ? (string) get_post_meta( $pass_packet_id, '_lnkup_heat_plan', true ) : '';
		$pass_prohibited = $pass_packet_id ? (string) get_post_meta( $pass_packet_id, '_lnkup_prohibited_items', true ) : '';
		$pass_setup     = $pass_packet_id ? (string) get_post_meta( $pass_packet_id, '_lnkup_setup_restrictions', true ) : '';
		$pass_load_in   = $pass_packet_id ? (string) get_post_meta( $pass_packet_id, '_lnkup_load_in_time', true ) : '';
		$pass_load_out  = $pass_packet_id ? (string) get_post_meta( $pass_packet_id, '_lnkup_load_out_time', true ) : (string) get_post_meta( $event_id, '_lnkup_end_time', true );
		if ( $pass_packet_id && get_post_meta( $pass_packet_id, '_lnkup_parking_info', true ) ) {
			$pass_parking = (string) get_post_meta( $pass_packet_id, '_lnkup_parking_info', true );
		}
		if ( $pass_packet_id && get_post_meta( $pass_packet_id, '_lnkup_venue_address', true ) ) {
			$pass_venue = (string) get_post_meta( $pass_packet_id, '_lnkup_venue_address', true );
		}
		$day_of_raw = $pass_packet_id ? (string) get_post_meta( $pass_packet_id, '_lnkup_day_of_contact', true ) : '';
		$day_of     = $day_of_raw ? json_decode( $day_of_raw, true ) : array();
		if ( ! is_array( $day_of ) ) {
			$day_of = array();
		}
		ob_start();
		?>
		<div class="lnkup-mp lnkup-ops" id="lnkup-event-ops" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>" data-pass-packet-id="<?php echo esc_attr( (string) $pass_packet_id ); ?>">
			<p><a href="<?php echo esc_url( $back ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm">&larr; <?php esc_html_e( 'Back to events', 'lnkup-marketplace' ); ?></a></p>
			<?php
			$ops_readiness_pct = (int) ( $readiness_summary['percent'] ?? 0 );
			echo lnkup_executive_hero(
				array(
					'title'     => ( $event ? $event->post_title : '' ) . ' — ' . __( 'Operations', 'lnkup-marketplace' ),
					'subtitle'  => __( 'Mission control for vendors, logistics, and day-of execution', 'lnkup-marketplace' ),
					'kpi'       => $ops_readiness_pct . '%',
					'kpi_label' => __( 'Event readiness', 'lnkup-marketplace' ),
					'metrics'   => array(
						array( 'label' => __( 'Confirmed vendors', 'lnkup-marketplace' ), 'value' => (string) (int) ( $readiness_summary['vendor_count'] ?? 0 ) ),
						array( 'label' => __( 'At risk', 'lnkup-marketplace' ), 'value' => (string) (int) ( $readiness_summary['at_risk_count'] ?? 0 ) ),
					),
					'primary_cta' => array(
						'label' => __( 'QR scan', 'lnkup-marketplace' ),
						'href'  => $scan_url,
					),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<div id="lnkup-ops-kpis" class="lnkup-ops-kpis-wrap"><?php echo LNKup_Operations_Render::kpi_row( $snap['kpis'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div id="lnkup-ops-lifecycle"><?php echo LNKup_Operations_Render::lifecycle_bar( $snap['lifecycle'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<nav class="lnkup-ops-section-nav" role="tablist" aria-label="<?php esc_attr_e( 'Operations sections', 'lnkup-marketplace' ); ?>">
				<button type="button" class="lnkup-ops-section is-active" data-section="command"><?php esc_html_e( 'Command', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-section" data-section="logistics"><?php esc_html_e( 'Logistics', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-section" data-section="maps"><?php esc_html_e( 'Maps', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-section" data-section="field"><?php esc_html_e( 'Field', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-section" data-section="intel"><?php esc_html_e( 'Intel', 'lnkup-marketplace' ); ?></button>
			</nav>
			<label class="lnkup-ops-section-select-wrap">
				<span class="screen-reader-text"><?php esc_html_e( 'Select operations section', 'lnkup-marketplace' ); ?></span>
				<select class="lnkup-ops-section-select" id="lnkup-ops-section-select">
					<option value="command"><?php esc_html_e( 'Command', 'lnkup-marketplace' ); ?></option>
					<option value="logistics"><?php esc_html_e( 'Logistics', 'lnkup-marketplace' ); ?></option>
					<option value="maps"><?php esc_html_e( 'Maps', 'lnkup-marketplace' ); ?></option>
					<option value="field"><?php esc_html_e( 'Field', 'lnkup-marketplace' ); ?></option>
					<option value="intel"><?php esc_html_e( 'Intel', 'lnkup-marketplace' ); ?></option>
				</select>
			</label>
			<div class="lnkup-ops-tabs-wrap">
			<nav class="lnkup-ops-tabs" role="tablist">
				<button type="button" class="lnkup-ops-tab is-active" data-section="command" data-ops-tab="overview" role="tab" aria-selected="true" aria-controls="lnkup-ops-overview-panel"><?php esc_html_e( 'Overview', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="command" data-ops-tab="readiness" role="tab" aria-selected="false" aria-controls="lnkup-ops-readiness-panel"><?php esc_html_e( 'Readiness', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="command" data-ops-tab="kanban" role="tab" aria-selected="false" aria-controls="lnkup-ops-kanban-wrap"><?php esc_html_e( 'Roster / Kanban', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="logistics" data-ops-tab="booths" role="tab" aria-selected="false" aria-controls="lnkup-ops-booths-panel"><?php esc_html_e( 'Booths', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="logistics" data-ops-tab="parking" role="tab" aria-selected="false" aria-controls="lnkup-ops-parking-panel"><?php esc_html_e( 'Parking', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="logistics" data-ops-tab="loadin" role="tab" aria-selected="false" aria-controls="lnkup-ops-loadin-panel"><?php esc_html_e( 'Load-In', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="logistics" data-ops-tab="vendor-pass" role="tab" aria-selected="false" aria-controls="lnkup-ops-vendor-pass-panel"><?php echo esc_html( lnkup_copy( 'pass_builder_title' ) ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="maps" data-ops-tab="map" role="tab" aria-selected="false" aria-controls="lnkup-ops-map-panel"><?php esc_html_e( 'Venue Map', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="maps" data-ops-tab="map-builder" role="tab" aria-selected="false" aria-controls="lnkup-ops-map-builder-panel"><?php esc_html_e( 'Map Builder', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="maps" data-ops-tab="live-map" role="tab" aria-selected="false" aria-controls="lnkup-ops-live-map-panel"><?php esc_html_e( 'Live Map', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="field" data-ops-tab="support" role="tab" aria-selected="false" aria-controls="lnkup-ops-support-panel"><?php esc_html_e( 'Support', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="field" data-ops-tab="incidents" role="tab" aria-selected="false" aria-controls="lnkup-ops-incidents-panel"><?php esc_html_e( 'Incidents', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="field" data-ops-tab="comms" role="tab" aria-selected="false" aria-controls="lnkup-ops-comms-panel"><?php esc_html_e( 'Comms', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="intel" data-ops-tab="analytics" role="tab" aria-selected="false" aria-controls="lnkup-ops-analytics-panel"><?php esc_html_e( 'Analytics', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="intel" data-ops-tab="financial" role="tab" aria-selected="false" aria-controls="lnkup-ops-financial-panel"><?php esc_html_e( 'Financial', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-ops-tab" data-section="intel" data-ops-tab="insights" role="tab" aria-selected="false" aria-controls="lnkup-ops-insights-panel"><?php esc_html_e( 'Insights', 'lnkup-marketplace' ); ?></button>
			</nav>
			</div>
			<div class="lnkup-ops-tab-panels">
				<div class="lnkup-ops-tab-panel is-active" data-ops-panel="overview" id="lnkup-ops-overview-panel" role="tabpanel">
					<?php echo LNKup_Operations_Render::readiness_overview_card( $readiness_summary, $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div class="lnkup-ops-panel-shell">
						<?php
						echo LNKup_Operations_Render::panel_header(
							__( 'Logistics Snapshot', 'lnkup-marketplace' ),
							__( 'Live view of current allocations and windows.', 'lnkup-marketplace' ),
							''
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo LNKup_Operations_Render::panel_stats_strip(
							array(
								array( 'label' => __( 'Booths', 'lnkup-marketplace' ), 'value' => count( (array) $booths ) ),
								array( 'label' => __( 'Parking spots', 'lnkup-marketplace' ), 'value' => count( (array) $parking ) ),
								array( 'label' => __( 'Load-in windows', 'lnkup-marketplace' ), 'value' => count( (array) $loadin_windows ) ),
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</div>
					<div class="lnkup-ops-overview-actions">
						<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-ops-goto-section" data-ops-section="logistics"><?php esc_html_e( 'Go to Logistics', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost lnkup-ops-goto-section" data-ops-section="field"><?php esc_html_e( 'Go to Field', 'lnkup-marketplace' ); ?></button>
					</div>
					<div class="lnkup-ops-overview-grid">
						<section class="lnkup-ops-panel">
							<h2><?php esc_html_e( 'Compliance Alerts', 'lnkup-marketplace' ); ?></h2>
							<div id="lnkup-ops-compliance"><?php echo LNKup_Operations_Render::compliance_alerts( $snap['compliance'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						</section>
						<section class="lnkup-ops-panel">
							<h2><?php esc_html_e( 'Live Feed', 'lnkup-marketplace' ); ?></h2>
							<div id="lnkup-ops-feed"><?php echo LNKup_Operations_Render::feed_list( $snap['feed'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						</section>
					</div>
					<div id="lnkup-ops-weather-panel">
						<?php
						if ( class_exists( 'LNKup_Weather_Render' ) ) {
							if ( is_array( $snap['weather'] ?? null ) && ! empty( $snap['weather']['event_id'] ) ) {
								echo LNKup_Weather_Render::operations_panel( $snap['weather'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} else {
								echo LNKup_Weather_Render::skeleton( $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
						}
						?>
					</div>
					<?php if ( ! empty( $snap['emergency'] ) ) : ?>
						<h3><?php esc_html_e( 'Emergency Contacts', 'lnkup-marketplace' ); ?></h3>
						<ul><?php foreach ( $snap['emergency'] as $c ) : ?><li><?php echo esc_html( ( $c['name'] ?? '' ) . ' — ' . ( $c['phone'] ?? '' ) ); ?></li><?php endforeach; ?></ul>
					<?php endif; ?>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="readiness" id="lnkup-ops-readiness-panel" role="tabpanel">
					<?php echo LNKup_Operations_Render::readiness_dashboard( $readiness_summary, $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="kanban" id="lnkup-ops-kanban-wrap" role="tabpanel">
					<?php echo LNKup_Operations_Render::kanban_board( $kanban ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="booths" id="lnkup-ops-booths-panel" role="tabpanel">
					<div class="lnkup-ops-panel-shell">
						<?php echo LNKup_Operations_Render::panel_header( __( 'Booths', 'lnkup-marketplace' ), __( 'Assign booth space and track coverage.', 'lnkup-marketplace' ), '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" id="lnkup-ops-add-booth">' . esc_html__( 'Add Booth', 'lnkup-marketplace' ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div id="lnkup-ops-booths-stats"></div>
						<div id="lnkup-ops-booths-list" class="lnkup-ops-table-wrap"></div>
					</div>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="parking" id="lnkup-ops-parking-panel" role="tabpanel">
					<div class="lnkup-ops-panel-shell">
						<?php echo LNKup_Operations_Render::panel_header( __( 'Parking', 'lnkup-marketplace' ), __( 'Lot assignments and gate instructions.', 'lnkup-marketplace' ), '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" id="lnkup-ops-add-parking">' . esc_html__( 'Add Parking', 'lnkup-marketplace' ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div id="lnkup-ops-parking-stats"></div>
						<div id="lnkup-ops-parking-list" class="lnkup-ops-table-wrap"></div>
					</div>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="loadin" id="lnkup-ops-loadin-panel" role="tabpanel">
					<div class="lnkup-ops-panel-shell">
						<?php echo LNKup_Operations_Render::panel_header( __( 'Load-In', 'lnkup-marketplace' ), __( 'Schedule entry windows and gate flow.', 'lnkup-marketplace' ), '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" id="lnkup-ops-add-loadin">' . esc_html__( 'Add Window', 'lnkup-marketplace' ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div id="lnkup-ops-loadin-stats"></div>
						<div id="lnkup-ops-loadin-list" class="lnkup-ops-table-wrap"></div>
					</div>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="vendor-pass" id="lnkup-ops-vendor-pass-panel" role="tabpanel">
					<div class="lnkup-ops-panel-shell lnkup-ops-vendor-pass-builder" id="lnkup-ops-vendor-pass-builder" data-testid="lnkup-ops-vendor-pass-builder">
						<?php
						echo LNKup_Operations_Render::panel_header(
							lnkup_copy( 'pass_builder_title' ),
							lnkup_copy( 'pass_builder_subtitle' ),
							'<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" id="lnkup-ops-pass-preview">' . esc_html( lnkup_copy( 'pass_builder_preview' ) ) . '</button>'
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						<p class="lnkup-muted"><?php echo esc_html( lnkup_copy( 'pass_builder_note' ) ); ?></p>
						<div id="lnkup-ops-pass-completeness" class="lnkup-ops-pass-completeness" aria-live="polite"></div>
						<form id="lnkup-ops-vendor-pass-form" class="lnkup-ops-form lnkup-ops-pass-form">
							<fieldset>
								<legend><?php esc_html_e( 'Arrival', 'lnkup-marketplace' ); ?></legend>
								<label><?php esc_html_e( 'Venue address', 'lnkup-marketplace' ); ?><textarea name="venue_address" rows="2"><?php echo esc_textarea( $pass_venue ); ?></textarea></label>
								<label><?php esc_html_e( 'Vendor gate', 'lnkup-marketplace' ); ?><input type="text" name="vendor_gate" value="<?php echo esc_attr( (string) get_post_meta( $event_id, '_lnkup_vendor_gate', true ) ); ?>" /></label>
								<label><?php esc_html_e( 'Load-in entrance', 'lnkup-marketplace' ); ?><input type="text" name="loadin_entrance" value="<?php echo esc_attr( (string) get_post_meta( $event_id, '_lnkup_loadin_entrance', true ) ); ?>" /></label>
								<label><?php esc_html_e( 'Load-in time note', 'lnkup-marketplace' ); ?><input type="text" name="load_in_time" value="<?php echo esc_attr( $pass_load_in ); ?>" /></label>
								<label><?php esc_html_e( 'Load-out time', 'lnkup-marketplace' ); ?><input type="text" name="load_out_time" value="<?php echo esc_attr( $pass_load_out ); ?>" /></label>
								<label><?php esc_html_e( 'Parking instructions', 'lnkup-marketplace' ); ?><textarea name="parking_info" rows="3"><?php echo esc_textarea( $pass_parking ); ?></textarea></label>
							</fieldset>
							<fieldset>
								<legend><?php esc_html_e( 'Rules', 'lnkup-marketplace' ); ?></legend>
								<label><?php esc_html_e( 'Vendor rules', 'lnkup-marketplace' ); ?><textarea name="vendor_rules" rows="4"><?php echo esc_textarea( $pass_rules ); ?></textarea></label>
								<label><?php esc_html_e( 'Setup restrictions', 'lnkup-marketplace' ); ?><textarea name="setup_restrictions" rows="3"><?php echo esc_textarea( $pass_setup ); ?></textarea></label>
								<label><?php esc_html_e( 'Prohibited items', 'lnkup-marketplace' ); ?><textarea name="prohibited_items" rows="3"><?php echo esc_textarea( $pass_prohibited ); ?></textarea></label>
								<label><?php esc_html_e( 'Refund policy', 'lnkup-marketplace' ); ?><textarea name="refund_policy" rows="3"><?php echo esc_textarea( $pass_refund ); ?></textarea></label>
							</fieldset>
							<fieldset>
								<legend><?php esc_html_e( 'Contacts', 'lnkup-marketplace' ); ?></legend>
								<label><?php esc_html_e( 'Day-of contact name', 'lnkup-marketplace' ); ?><input type="text" name="day_of_name" value="<?php echo esc_attr( (string) ( $day_of['name'] ?? '' ) ); ?>" /></label>
								<label><?php esc_html_e( 'Day-of phone', 'lnkup-marketplace' ); ?><input type="text" name="day_of_phone" value="<?php echo esc_attr( (string) ( $day_of['phone'] ?? '' ) ); ?>" /></label>
								<label><?php esc_html_e( 'Day-of email', 'lnkup-marketplace' ); ?><input type="email" name="day_of_email" value="<?php echo esc_attr( (string) ( $day_of['email'] ?? '' ) ); ?>" /></label>
							</fieldset>
							<fieldset>
								<legend><?php esc_html_e( 'Weather', 'lnkup-marketplace' ); ?></legend>
								<label><?php esc_html_e( 'Contingency note', 'lnkup-marketplace' ); ?><textarea name="weather_placeholder" rows="2"><?php echo esc_textarea( (string) get_post_meta( $event_id, '_lnkup_weather_placeholder', true ) ); ?></textarea></label>
								<label><?php esc_html_e( 'Rain plan', 'lnkup-marketplace' ); ?><textarea name="rain_plan" rows="2"><?php echo esc_textarea( $pass_rain ); ?></textarea></label>
								<label><?php esc_html_e( 'Heat plan', 'lnkup-marketplace' ); ?><textarea name="heat_plan" rows="2"><?php echo esc_textarea( $pass_heat ); ?></textarea></label>
							</fieldset>
							<p class="lnkup-muted"><?php esc_html_e( 'Assign booths and upload maps in the Booths and Maps tabs.', 'lnkup-marketplace' ); ?></p>
							<button type="submit" class="lnkup-btn lnkup-btn--primary"><?php echo esc_html( lnkup_copy( 'pass_builder_save' ) ); ?></button>
							<p class="lnkup-ops-pass-save-status lnkup-muted" id="lnkup-ops-pass-save-status" role="status" aria-live="polite"></p>
						</form>
					</div>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="map" id="lnkup-ops-map-panel" role="tabpanel">
					<p class="lnkup-muted"><?php esc_html_e( 'Venue Map = legacy pins. Map Builder = layout editor. Live Map = day-of status.', 'lnkup-marketplace' ); ?></p>
					<?php echo LNKup_Operations_Render::venue_map( $event_id, $booths ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="map-builder" id="lnkup-ops-map-builder-panel" role="tabpanel">
					<?php echo LNKup_Map_Render::shell( $event_id, 'builder' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="live-map" id="lnkup-ops-live-map-panel" role="tabpanel">
					<?php echo LNKup_Map_Render::shell( $event_id, 'live' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="support" id="lnkup-ops-support-panel" role="tabpanel">
					<div class="lnkup-ops-panel-shell">
						<?php echo LNKup_Operations_Render::panel_header( __( 'Support Requests', 'lnkup-marketplace' ), __( 'Track open field support work.', 'lnkup-marketplace' ), '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div id="lnkup-ops-support-list" class="lnkup-ops-table-wrap"></div>
					</div>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="incidents" id="lnkup-ops-incidents-panel" role="tabpanel">
					<div class="lnkup-ops-panel-shell">
						<?php echo LNKup_Operations_Render::panel_header( __( 'Incidents', 'lnkup-marketplace' ), __( 'Safety, medical, and logistics incident queue.', 'lnkup-marketplace' ), '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" id="lnkup-ops-new-incident">' . esc_html__( 'New Incident', 'lnkup-marketplace' ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div id="lnkup-ops-incidents-list" class="lnkup-ops-table-wrap"></div>
					</div>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="comms" id="lnkup-ops-comms-panel" role="tabpanel">
					<div class="lnkup-ops-comms-grid">
						<div class="lnkup-ops-panel-shell">
							<?php echo LNKup_Operations_Render::panel_header( __( 'Broadcast', 'lnkup-marketplace' ), __( 'Send updates to paid vendors and cohorts.', 'lnkup-marketplace' ), '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<form id="lnkup-ops-broadcast-form" class="lnkup-ops-form">
								<label><?php esc_html_e( 'Audience', 'lnkup-marketplace' ); ?>
									<select name="audience">
										<option value="all_paid"><?php esc_html_e( 'All paid vendors', 'lnkup-marketplace' ); ?></option>
										<option value="checked_in"><?php esc_html_e( 'Checked in', 'lnkup-marketplace' ); ?></option>
										<option value="missing_docs"><?php esc_html_e( 'Missing documents', 'lnkup-marketplace' ); ?></option>
									</select>
								</label>
								<label><?php esc_html_e( 'Message', 'lnkup-marketplace' ); ?>
									<textarea name="message" rows="4" required></textarea>
								</label>
								<button type="submit" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Send Broadcast', 'lnkup-marketplace' ); ?></button>
							</form>
						</div>
						<div class="lnkup-ops-panel-shell">
							<?php echo LNKup_Operations_Render::panel_header( __( 'Recent Broadcasts', 'lnkup-marketplace' ), '', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<div id="lnkup-ops-broadcasts-list" class="lnkup-ops-table-wrap"></div>
						</div>
					</div>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="analytics" id="lnkup-ops-analytics-panel" role="tabpanel">
					<div class="lnkup-ops-panel-shell">
						<?php echo LNKup_Operations_Render::panel_header( __( 'Analytics', 'lnkup-marketplace' ), __( 'Live operations metrics for day-of decisions.', 'lnkup-marketplace' ), '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div id="lnkup-ops-analytics" class="lnkup-ops-analytics-grid"></div>
					</div>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="financial" id="lnkup-ops-financial-panel" role="tabpanel">
					<div class="lnkup-ops-panel-shell">
						<?php echo LNKup_Operations_Render::panel_header( __( 'Financial', 'lnkup-marketplace' ), __( 'Event payment and payout summary.', 'lnkup-marketplace' ), '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo LNKup_Financial_Render::event_financial_tab( $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</div>
				<div class="lnkup-ops-tab-panel" data-ops-panel="insights" id="lnkup-ops-insights-panel" role="tabpanel">
					<div class="lnkup-ops-panel-shell">
						<?php echo LNKup_Operations_Render::panel_header( __( 'Insights', 'lnkup-marketplace' ), __( 'AI-assisted event intelligence and risk flags.', 'lnkup-marketplace' ), '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php if ( class_exists( 'LNKup_Weather_Render' ) && is_array( $snap['weather'] ?? null ) ) : ?>
						<div id="lnkup-ops-weather-intel" class="lnkup-weather-intel-block">
							<?php echo LNKup_Weather_Render::intelligence_panel( $snap['weather'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					<?php endif; ?>
					<div id="lnkup-event-insights" class="lnkup-intel-shell" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>">
						<p class="lnkup-muted"><?php esc_html_e( 'Loading event insights…', 'lnkup-marketplace' ); ?></p>
					</div>
					</div>
				</div>
			</div>
			<div class="lnkup-ops-mobile-bar">
				<a href="<?php echo esc_url( $scan_url ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Scan', 'lnkup-marketplace' ); ?></a>
				<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-ops-goto-tab" data-ops-tab="readiness"><?php esc_html_e( 'Readiness', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-ops-goto-tab" data-ops-tab="kanban"><?php esc_html_e( 'Roster', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-btn lnkup-btn--sm" id="lnkup-ops-mobile-incident"><?php esc_html_e( 'Incident', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--accent" id="lnkup-ops-mobile-broadcast"><?php esc_html_e( 'Broadcast', 'lnkup-marketplace' ); ?></button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * QR scan check-in page for event staff.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function render_qr_scan_view( $event_id ) {
		if ( ! LNKup_Capabilities::organizer_owns_event( $event_id ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'Permission denied.', 'lnkup-marketplace' ) . '</p>';
		}
		$event = get_post( $event_id );
		$ops_url = add_query_arg( array( 'event' => $event_id, 'ops' => 1 ), lnkup_page_url_helper( 'organizer-dashboard' ) );
		ob_start();
		?>
		<div class="lnkup-mp lnkup-ops" id="lnkup-qr-scan" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>">
			<p><a href="<?php echo esc_url( $ops_url ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm">&larr; <?php esc_html_e( 'Back to operations', 'lnkup-marketplace' ); ?></a></p>
			<h1 class="lnkup-heading lnkup-heading--page"><?php echo esc_html( $event ? $event->post_title : '' ); ?> — <?php esc_html_e( 'QR Check-In', 'lnkup-marketplace' ); ?></h1>
			<div id="lnkup-qr-reader" class="lnkup-ops-qr-reader"></div>
			<form id="lnkup-qr-manual" class="lnkup-ops-form">
				<label><?php esc_html_e( 'Or enter pass code manually', 'lnkup-marketplace' ); ?>
					<input type="text" name="token" autocomplete="off" required />
				</label>
				<button type="submit" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Check In', 'lnkup-marketplace' ); ?></button>
			</form>
			<div id="lnkup-qr-result" class="lnkup-ops-scan-result" role="status" aria-live="polite"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Applications manager shortcode.
	 *
	 * @param array|null $apps_override Optional subset of applications to render.
	 */
	public static function render_applications_manager( $apps_override = null ) {
		LNKup_Capabilities::require_login();
		if ( ! LNKup_Roles::is_organizer() && ! LNKup_Capabilities::user_has_manage_all() ) {
			return '';
		}

		$apps = is_array( $apps_override ) ? $apps_override : lnkup_get_applications_for_user( get_current_user_id(), 'organizer' );

		ob_start();
		if ( ! $apps ) {
			$dash = lnkup_page_url_helper( 'organizer-dashboard' );
			echo lnkup_empty_state(
				array(
					'title'               => __( 'No vendor requests yet', 'lnkup-marketplace' ),
					'desc'                => lnkup_copy( 'empty_vendor_requests' ),
					'cta_label'           => lnkup_copy( 'launch_event' ),
					'cta_href'            => $dash . '#create',
					'secondary_cta_label' => lnkup_copy( 'discover_vendors' ),
					'secondary_cta_href'  => $dash . '#vendors',
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return ob_get_clean();
		}
		?>
		<div class="lnkup-crm-app-grid lnkup-crm-app-grid--v2">
		<?php foreach ( $apps as $app ) : ?>
			<?php
			$profile_id = (int) get_post_meta( $app->ID, '_lnkup_vendor_profile_id', true );
			$profile = $profile_id ? LNKup_Vendor_CRM_Profile::get( $profile_id, get_current_user_id() ) : null;
			$event_id  = (int) get_post_meta( $app->ID, '_lnkup_event_id', true );
			if ( ! $event_id ) {
				$opp_id_for_event = (int) get_post_meta( $app->ID, '_lnkup_opportunity_id', true );
				if ( $opp_id_for_event ) {
					$event_id = (int) get_post_meta( $opp_id_for_event, '_lnkup_event_id', true );
				}
			}
			$event     = $event_id ? get_post( $event_id ) : null;
			$status    = get_post_meta( $app->ID, '_lnkup_application_status', true );
			LNKup_Vendor_Readiness::refresh( $app->ID );
			$doc_context = class_exists( 'LNKup_Verification' )
				? LNKup_Verification::get_application_document_context( (int) $app->ID )
				: array();
			$doc_code   = $doc_context['code'] ?? '';
			$docs_ok    = in_array( $doc_code, array( 'none_required', 'ready' ), true );
			$is_paid    = class_exists( 'LNKup_Vendor_Readiness' )
				? LNKup_Vendor_Readiness::application_payment_satisfied( (int) $app->ID, $status )
				: in_array( $status, array( 'paid', 'completed' ), true );
			$filter_pending = in_array( $status, array( 'submitted', 'under_review' ), true ) ? 'pending' : '';
			$filter_approved = in_array( $status, array( 'approved', 'payment_required' ), true ) ? 'approved' : '';
			$filter_paid = $is_paid ? 'paid' : '';
			$filter_unpaid = in_array( $status, array( 'approved', 'payment_required' ), true ) && ! $is_paid ? 'unpaid' : '';
			$filter_docs = ( 'none_required' !== $doc_code && in_array( $doc_code, array( 'pending', 'missing' ), true ) ) ? 'missing_docs' : '';
			$filter_key = $filter_pending ?: ( 'waitlisted' === $status ? 'waitlisted' : ( 'rejected' === $status ? 'rejected' : ( $filter_unpaid ?: ( $filter_paid ?: ( $filter_approved ?: $filter_docs ) ) ) ) );
			$profile_url = $profile_id ? add_query_arg( 'vendor', $profile_id, lnkup_page_url_helper( 'organizer-dashboard' ) ) : '#';
			$initials    = '';
			if ( $profile && ! empty( $profile['business_name'] ) ) {
				foreach ( preg_split( '/\s+/', trim( $profile['business_name'] ) ) as $part ) {
					$initials .= strtoupper( substr( $part, 0, 1 ) );
					if ( strlen( $initials ) >= 2 ) {
						break;
					}
				}
			}
			$category_label = $profile && ! empty( $profile['category'] ) ? implode( ', ', array_slice( $profile['category'], 0, 2 ) ) : '—';
			$rating         = $profile && ! empty( $profile['avg_rating'] ) ? (string) $profile['avg_rating'] : '';
			$events_count   = $profile ? (string) ( $profile['reliability']['completed_events'] ?? $profile['completed_events'] ?? 0 ) : '0';
			$status_label  = LNKup_Vendor_Dashboard_Data::application_status_label( $status );
			$snapshot      = LNKup_Applications::get_profile_snapshot( $app->ID );
			$doc_snapshot  = LNKup_Applications::get_documents_snapshot( $app->ID );
			$app_message   = trim( (string) $app->post_content );
			$selected_cat  = get_post_meta( $app->ID, '_lnkup_selected_category', true );
			$setup_notes   = get_post_meta( $app->ID, '_lnkup_setup_notes', true );
			$docs_ready    = 0;
			$docs_total    = count( $doc_snapshot );
			foreach ( $doc_snapshot as $doc_row ) {
				if ( in_array( $doc_row['status'] ?? '', array( 'approved', 'pending', 'uploaded' ), true ) ) {
					$docs_ready++;
				}
			}
			if ( $snapshot && ! empty( $snapshot['category'] ) && is_array( $snapshot['category'] ) ) {
				$category_label = implode( ', ', array_slice( $snapshot['category'], 0, 2 ) );
			} elseif ( $selected_cat ) {
				$category_label = $selected_cat;
			}
			?>
			<article class="lnkup-crm-app-card lnkup-crm-app-card--v2 lnkup-surface--primary" data-app-id="<?php echo esc_attr( (string) $app->ID ); ?>" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>" data-org-app-filter="<?php echo esc_attr( $filter_key ); ?>" data-org-app-status="<?php echo esc_attr( $status ); ?>" data-org-app-docs="<?php echo esc_attr( $docs_ok ? 'ready' : 'missing' ); ?>" data-org-app-paid="<?php echo esc_attr( $is_paid ? 'yes' : 'no' ); ?>">
				<div class="lnkup-crm-app-card__header">
					<?php if ( $profile && ! empty( $profile['logo'] ) ) : ?>
						<img src="<?php echo esc_url( $profile['logo'] ); ?>" alt="" class="lnkup-crm-app-card__avatar" />
					<?php else : ?>
						<span class="lnkup-crm-app-card__avatar lnkup-crm-app-card__avatar--placeholder"><?php echo esc_html( $initials ?: 'V' ); ?></span>
					<?php endif; ?>
					<div class="lnkup-crm-app-card__header-main">
						<h3 class="lnkup-crm-app-card__name"><a href="<?php echo esc_url( $profile_url ); ?>"><?php echo esc_html( $profile ? $profile['business_name'] : '—' ); ?></a></h3>
						<p class="lnkup-crm-app-card__meta lnkup-muted">
							<?php echo esc_html( $category_label ); ?>
							<?php if ( $rating ) : ?>
								· ★ <?php echo esc_html( $rating ); ?>
							<?php endif; ?>
							· <?php printf( esc_html__( '%s events', 'lnkup-marketplace' ), esc_html( $events_count ) ); ?>
						</p>
						<?php if ( $profile && ! empty( $profile['profile_strength_tier'] ) ) : ?>
							<span class="lnkup-crm-app-card__strength"><?php echo esc_html( $profile['profile_strength_tier'] . ' · ' . (int) $profile['profile_strength_score'] . '%' ); ?></span>
						<?php elseif ( ! empty( $snapshot['strength_tier'] ) ) : ?>
							<span class="lnkup-crm-app-card__strength"><?php echo esc_html( $snapshot['strength_tier'] . ' · ' . (int) ( $snapshot['strength_score'] ?? 0 ) . '%' ); ?></span>
						<?php endif; ?>
						<?php
						$trust_badges = array();
						if ( $profile_id && class_exists( 'LNKup_Verification' ) ) {
							$trust_badges = LNKup_Verification::get_vendor_badges(
								$profile_id,
								'application',
								array( 'application_id' => (int) $app->ID )
							);
							if ( $trust_badges ) {
								echo '<div class="lnkup-crm-app-card__trust-badges" data-testid="lnkup-app-trust-badges">';
								foreach ( array_slice( $trust_badges, 0, 3 ) as $tb ) {
									printf(
										'<span class="lnkup-trust-badge%s">%s</span>',
										! empty( $tb['source'] ) && 'derived' === $tb['source'] ? ' lnkup-trust-badge--accent' : '',
										esc_html( $tb['label'] ?? '' )
									);
								}
								echo '</div>';
							}
						}
						$setup_bits = array_filter(
							array(
								$profile['setup_type'] ?? '',
								$profile['power_needs'] ?? '',
								$profile['tent_size'] ?? '',
							)
						);
						$has_intel_details = count( $trust_badges ) > 3
							|| ( ! empty( $doc_context['summary'] ) && 'none_required' !== $doc_code )
							|| $setup_bits
							|| $setup_notes
							|| ! empty( $snapshot['setup_needs'] )
							|| $app_message
							|| ! empty( $snapshot['products'] );
						if ( $has_intel_details ) :
							?>
							<details class="lnkup-crm-app-card__details-toggle">
								<summary><?php esc_html_e( 'Show details', 'lnkup-marketplace' ); ?></summary>
								<?php if ( count( $trust_badges ) > 3 ) : ?>
									<div class="lnkup-crm-app-card__trust-badges">
										<?php foreach ( array_slice( $trust_badges, 3 ) as $tb ) : ?>
											<span class="lnkup-trust-badge<?php echo ! empty( $tb['source'] ) && 'derived' === $tb['source'] ? ' lnkup-trust-badge--accent' : ''; ?>"><?php echo esc_html( $tb['label'] ?? '' ); ?></span>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
								<?php if ( ! empty( $doc_context['summary'] ) && 'none_required' !== $doc_code ) : ?>
									<p class="lnkup-crm-app-card__docs-context lnkup-muted" data-testid="lnkup-app-docs-context"><?php echo esc_html( $doc_context['summary'] ); ?></p>
								<?php endif; ?>
								<?php if ( $setup_bits ) : ?>
									<p class="lnkup-crm-app-card__setup lnkup-muted"><?php echo esc_html( implode( ' · ', $setup_bits ) ); ?></p>
								<?php elseif ( $setup_notes ) : ?>
									<p class="lnkup-crm-app-card__setup lnkup-muted"><?php echo esc_html( $setup_notes ); ?></p>
								<?php elseif ( ! empty( $snapshot['setup_needs'] ) ) : ?>
									<p class="lnkup-crm-app-card__setup lnkup-muted"><?php echo esc_html( $snapshot['setup_needs'] ); ?></p>
								<?php endif; ?>
								<?php if ( $app_message ) : ?>
									<p class="lnkup-crm-app-card__message lnkup-muted" data-testid="lnkup-app-message"><?php echo esc_html( wp_trim_words( $app_message, 24 ) ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $snapshot['products'] ) ) : ?>
									<p class="lnkup-crm-app-card__intel lnkup-muted"><?php echo esc_html( wp_trim_words( $snapshot['products'], 18 ) ); ?></p>
								<?php endif; ?>
							</details>
						<?php elseif ( ! empty( $doc_context['summary'] ) ) : ?>
							<p class="lnkup-crm-app-card__docs-context lnkup-muted" data-testid="lnkup-app-docs-context"><?php echo esc_html( $doc_context['summary'] ); ?></p>
						<?php endif; ?>
						<?php if ( 'none_required' === $doc_code ) : ?>
							<p class="lnkup-crm-app-card__docs-summary lnkup-muted" data-testid="lnkup-app-docs-summary">
								<?php esc_html_e( 'No documents required for this event.', 'lnkup-marketplace' ); ?>
							</p>
						<?php elseif ( $docs_total ) : ?>
							<p class="lnkup-crm-app-card__docs-summary lnkup-muted" data-testid="lnkup-app-docs-summary">
								<?php
								printf(
									esc_html__( 'Documents at apply: %1$d/%2$d ready', 'lnkup-marketplace' ),
									(int) $docs_ready,
									(int) $docs_total
								);
								?>
							</p>
						<?php endif; ?>
					</div>
				</div>
				<div class="lnkup-crm-app-card__chips">
					<span class="lnkup-status lnkup-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_label ); ?></span>
					<span class="lnkup-crm-app-chip"><?php printf( esc_html__( 'Applied: %s', 'lnkup-marketplace' ), esc_html( $event ? $event->post_title : '—' ) ); ?></span>
					<?php
					if ( 'none_required' !== $doc_code ) {
						$docs_badge_map = array(
							'ready'   => array( 'verified', __( 'Docs verified', 'lnkup-marketplace' ) ),
							'pending' => array( 'docs_pending', __( 'Docs pending', 'lnkup-marketplace' ) ),
							'missing' => array( 'docs_pending', __( 'Docs missing', 'lnkup-marketplace' ) ),
						);
						$docs_badge = $docs_badge_map[ $doc_code ] ?? ( $docs_ok
							? array( 'verified', __( 'Docs verified', 'lnkup-marketplace' ) )
							: array( 'docs_pending', __( 'Docs pending', 'lnkup-marketplace' ) ) );
						echo lnkup_status_badge( $docs_badge[0], $docs_badge[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
					<?php if ( in_array( $status, array( 'approved', 'payment_required' ), true ) ) : ?>
						<span class="lnkup-crm-app-chip lnkup-crm-app-chip--<?php echo esc_attr( $is_paid ? 'success' : 'warning' ); ?>"><?php echo esc_html( $is_paid ? __( 'Paid', 'lnkup-marketplace' ) : __( 'Balance due', 'lnkup-marketplace' ) ); ?></span>
					<?php endif; ?>
				</div>
				<?php
				$is_actionable = in_array( $status, array( 'submitted', 'under_review', 'waitlisted' ), true );
				if ( $is_actionable ) :
				?>
					<div class="lnkup-crm-app-card__actions lnkup-crm-app-card__actions--primary">
						<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary lnkup-review-btn" data-app-id="<?php echo esc_attr( (string) $app->ID ); ?>" data-action="approve"><?php esc_html_e( 'Approve', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-review-btn" data-app-id="<?php echo esc_attr( (string) $app->ID ); ?>" data-action="waitlist"><?php esc_html_e( 'Waitlist', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost lnkup-review-btn lnkup-review-btn--decline" data-app-id="<?php echo esc_attr( (string) $app->ID ); ?>" data-action="reject"><?php esc_html_e( 'Decline', 'lnkup-marketplace' ); ?></button>
					</div>
				<?php else : ?>
					<div class="lnkup-crm-app-card__decision">
						<span class="lnkup-crm-app-card__decision-label"><?php echo esc_html( LNKup_Vendor_Dashboard_Data::application_status_label( $status ) ); ?></span>
					</div>
				<?php endif; ?>
				<div class="lnkup-crm-app-card__more">
					<label class="lnkup-crm-compare-check"><input type="checkbox" class="lnkup-crm-compare-pick" value="<?php echo esc_attr( (string) $app->ID ); ?>" /> <?php esc_html_e( 'Compare', 'lnkup-marketplace' ); ?></label>
					<div class="lnkup-crm-app-card__more-actions">
						<?php
						$message_href = '';
						if ( $profile && ! empty( $profile['email'] ) ) {
							$message_href = 'mailto:' . sanitize_email( $profile['email'] );
						} elseif ( $profile_url ) {
							$message_href = $profile_url;
						}
						if ( $message_href ) :
							?>
							<a href="<?php echo esc_url( $message_href ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost"><?php esc_html_e( 'Message', 'lnkup-marketplace' ); ?></a>
						<?php endif; ?>
						<?php if ( ! $docs_ok && $profile_id ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'vendor', $profile_id, lnkup_page_url_helper( 'organizer-dashboard' ) ) ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline"><?php esc_html_e( 'Review docs', 'lnkup-marketplace' ); ?></a>
						<?php endif; ?>
						<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost lnkup-intel-open" data-app-id="<?php echo esc_attr( (string) $app->ID ); ?>"><?php esc_html_e( 'View Intel', 'lnkup-marketplace' ); ?></button>
					</div>
				</div>
			</article>
		<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Documents manager shortcode.
	 */
	public static function render_documents_manager() {
		LNKup_Capabilities::require_login();
		$user_id    = get_current_user_id();
		$compliance = LNKup_Documents::compliance_summary( $user_id );
		$types      = LNKup_Documents::document_types();
		$pct        = $compliance['total'] ? round( ( $compliance['verified'] / $compliance['total'] ) * 100 ) : 0;

		$status_labels = array(
			'verified' => __( 'Verified', 'lnkup-marketplace' ),
			'expiring' => __( 'Expiring soon', 'lnkup-marketplace' ),
			'pending'  => __( 'Pending review', 'lnkup-marketplace' ),
			'missing'  => __( 'Missing', 'lnkup-marketplace' ),
		);

		ob_start();
		?>
		<div class="lnkup-mp lnkup-vendor-dash" id="documents">
			<?php
			echo lnkup_executive_hero(
				array(
					'title'        => __( 'Documents & compliance', 'lnkup-marketplace' ),
					'subtitle'     => __( 'Upload and maintain the documents organizers require', 'lnkup-marketplace' ),
					'kpi'          => sprintf( '%d/%d', (int) $compliance['verified'], (int) $compliance['total'] ),
					'kpi_label'    => __( 'Verified documents', 'lnkup-marketplace' ),
					'ring_pct'     => $pct,
					'metrics'      => array(
						array( 'label' => __( 'Attention needed', 'lnkup-marketplace' ), 'value' => (string) (int) ( $compliance['attention_count'] ?? 0 ) ),
					),
					'primary_cta'  => array(
						'label' => __( 'Upload document', 'lnkup-marketplace' ),
						'href'  => '#lnkup-document-upload-form',
					),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>

			<?php if ( 0 === (int) $compliance['verified'] && (int) $compliance['attention_count'] === (int) $compliance['total'] ) : ?>
				<?php
				echo lnkup_empty_state(
					array(
						'title'          => lnkup_copy( 'empty_documents' ) ?: __( 'Upload your compliance documents', 'lnkup-marketplace' ),
						'desc'           => __( 'Organizers require verified documents before you can be approved for events.', 'lnkup-marketplace' ),
						'cta_label'      => __( 'Upload document', 'lnkup-marketplace' ),
						'cta_href'       => '#lnkup-document-upload-form',
						'secondary_cta_label' => __( 'View requirements', 'lnkup-marketplace' ),
						'secondary_cta_href'  => lnkup_page_url_helper( 'vendor-profile' ),
					)
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<?php endif; ?>

			<div class="lnkup-panel lnkup-doc-compliance-hero lnkup-surface--secondary">
				<div class="lnkup-doc-compliance-hero__ring">
					<div class="lnkup-compliance-ring" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $pct ); ?>" aria-valuemin="0" aria-valuemax="100" style="--progress: <?php echo esc_attr( (string) min( 100, max( 0, (int) $pct ) ) ); ?>%">
						<span class="lnkup-compliance-ring__value"><?php echo esc_html( (string) $pct ); ?>%</span>
					</div>
				</div>
				<div class="lnkup-doc-compliance-hero__copy">
					<h2 class="lnkup-panel__title"><?php printf( esc_html__( '%1$d of %2$d documents verified', 'lnkup-marketplace' ), (int) $compliance['verified'], (int) $compliance['total'] ); ?></h2>
					<p class="lnkup-muted"><?php esc_html_e( 'Your compliance center — upload once, use everywhere you apply.', 'lnkup-marketplace' ); ?></p>
				</div>
			</div>

			<div class="lnkup-doc-grid lnkup-doc-grid--compliance">
				<?php foreach ( $compliance['items'] as $type_key => $item ) : ?>
					<article class="lnkup-doc-card lnkup-surface--primary lnkup-micro-lift lnkup-doc-card--<?php echo esc_attr( $item['status'] ); ?>">
						<div class="lnkup-doc-card__header">
							<h3 class="lnkup-doc-card__title"><?php echo esc_html( $item['label'] ); ?></h3>
							<p class="lnkup-doc-card__status lnkup-doc-card__status--<?php echo esc_attr( $item['status'] ); ?>"><?php echo esc_html( $status_labels[ $item['status'] ] ?? $item['status'] ); ?></p>
						</div>
						<?php if ( $item['expires'] && 'verified' !== $item['status'] && 'missing' !== $item['status'] ) : ?>
							<p class="lnkup-muted"><?php printf( esc_html__( 'Expires %s', 'lnkup-marketplace' ), esc_html( date_i18n( 'M j, Y', strtotime( $item['expires'] ) ) ) ); ?></p>
						<?php elseif ( 'verified' === $item['status'] && ! empty( $item['expires'] ) ) : ?>
							<p class="lnkup-muted"><?php printf( esc_html__( 'Valid through %s', 'lnkup-marketplace' ), esc_html( date_i18n( 'M j, Y', strtotime( $item['expires'] ) ) ) ); ?></p>
						<?php elseif ( 'missing' === $item['status'] ) : ?>
							<p class="lnkup-muted"><?php esc_html_e( 'Required for many organizer approvals.', 'lnkup-marketplace' ); ?></p>
						<?php endif; ?>
						<div class="lnkup-doc-card__actions">
							<?php if ( $item['doc_id'] ) : ?>
								<a href="<?php echo esc_url( LNKup_Documents::download_url( $item['doc_id'] ) ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php esc_html_e( 'Download', 'lnkup-marketplace' ); ?></a>
							<?php endif; ?>
							<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" data-doc-upload="<?php echo esc_attr( $type_key ); ?>"><?php echo $item['doc_id'] ? esc_html__( 'Replace', 'lnkup-marketplace' ) : esc_html__( 'Upload', 'lnkup-marketplace' ); ?></button>
						</div>
					</article>
				<?php endforeach; ?>
			</div>

			<form class="lnkup-form lnkup-document-form lnkup-doc-upload-form" id="lnkup-document-upload-form" enctype="multipart/form-data">
				<?php wp_nonce_field( 'lnkup_document', 'lnkup_nonce' ); ?>
				<input type="hidden" name="action" value="lnkup_upload_document" />
				<div class="lnkup-grid lnkup-grid--2">
					<div class="lnkup-form-row">
						<label for="document_type"><?php esc_html_e( 'Document Type', 'lnkup-marketplace' ); ?></label>
						<select id="document_type" name="document_type" required>
							<?php foreach ( $types as $key => $label ) : ?>
								<?php if ( 'other' === $key ) { continue; } ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="lnkup-form-row">
						<label for="expiration_date"><?php esc_html_e( 'Expiration Date', 'lnkup-marketplace' ); ?></label>
						<input type="date" id="expiration_date" name="expiration_date" />
					</div>
				</div>
				<input type="file" id="lnkup_document" name="lnkup_document" accept=".pdf,.jpg,.jpeg,.png" />
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Payments dashboard shortcode.
	 *
	 * @param string $role vendor|organizer.
	 */
	public static function render_payments_dashboard( $role = 'vendor' ) {
		LNKup_Capabilities::require_login();
		if ( 'organizer' === $role ) {
			return '<p class="lnkup-muted">' . esc_html__( 'Organizer payments are managed in the Financial dashboard.', 'lnkup-marketplace' ) . '</p>';
		}

		ob_start();
		$payments = lnkup_get_payments_for_user( get_current_user_id(), $role );
		$totals   = LNKup_Vendor_Dashboard_Data::payment_totals( $payments );
		if ( 'vendor' === $role ) {
			$wallet       = LNKup_Wallet::get_balance( get_current_user_id(), 'vendor' );
			$installments = LNKup_Installments::upcoming_for_vendor( get_current_user_id() );
			$next_due     = $installments ? $installments[0] : null;
			$apps_url     = add_query_arg( 'view', 'applications', lnkup_page_url_helper( 'vendor-dashboard' ) );
			?>
		<div class="lnkup-mp lnkup-vendor-dash" id="payments">
			<?php
			echo lnkup_executive_hero(
				array(
					'title'     => __( 'Payments', 'lnkup-marketplace' ),
					'subtitle'  => __( 'Payments, invoices, credits, and transaction history', 'lnkup-marketplace' ),
					'kpi'       => lnkup_currency( $totals['outstanding'] ),
					'kpi_label' => __( 'Amount due', 'lnkup-marketplace' ),
					'metrics'   => array(
						array( 'label' => __( 'Total spent', 'lnkup-marketplace' ), 'value' => lnkup_currency( $totals['paid'] ) ),
						array( 'label' => __( 'Credits', 'lnkup-marketplace' ), 'value' => lnkup_currency( $wallet ) ),
					),
					'primary_cta' => array(
						'label' => __( 'View applications', 'lnkup-marketplace' ),
						'href'  => $apps_url,
					),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>

			<?php if ( ! $payments ) : ?>
				<?php
				echo lnkup_empty_state(
					__( 'No payments yet', 'lnkup-marketplace' ),
					__( 'Application fees and invoices will appear here once you are approved for events.', 'lnkup-marketplace' ),
					__( 'View applications', 'lnkup-marketplace' ),
					$apps_url
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<?php endif; ?>

			<?php echo LNKup_Financial_Render::vendor_shell(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php
		}
		?>

		<?php
		if ( 'vendor' === $role ) :
			?>
		</div>
		<?php
		endif;
		return ob_get_clean();
	}

	/**
	 * Public vendor profile shortcode.
	 */
	public static function render_vendor_public_profile() {
		$slug = isset( $_GET['vendor'] ) ? sanitize_text_field( wp_unslash( $_GET['vendor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $slug ) {
			return '<p class="lnkup-empty">' . esc_html__( 'Vendor not found.', 'lnkup-marketplace' ) . '</p>';
		}
		$profile_id = LNKup_Vendor_Reputation::resolve_profile( $slug );
		if ( ! $profile_id ) {
			return '<p class="lnkup-empty">' . esc_html__( 'Vendor not found.', 'lnkup-marketplace' ) . '</p>';
		}
		$data = LNKup_Vendor_CRM_Profile::get_public( $profile_id );
		if ( ! $data ) {
			return '<p class="lnkup-empty">' . esc_html__( 'Vendor not found.', 'lnkup-marketplace' ) . '</p>';
		}
		$markets = array_filter( array_merge( (array) ( $data['cities_served'] ?? array() ), (array) ( $data['states_served'] ?? array() ) ) );
		$setup_parts = array_filter(
			array(
				$data['setup_type'] ?? '',
				! empty( $data['power_needs'] ) ? __( 'Power', 'lnkup-marketplace' ) . ': ' . $data['power_needs'] : '',
				! empty( $data['water_needs'] ) ? __( 'Water', 'lnkup-marketplace' ) . ': ' . $data['water_needs'] : '',
				$data['tent_size'] ?? '',
				$data['trailer_size'] ?? '',
			)
		);
		$crm_url = add_query_arg( 'vendor', $profile_id, lnkup_page_url_helper( 'organizer-dashboard' ) );
		ob_start();
		?>
		<div class="lnkup-mp lnkup-pub-storefront" id="lnkup-public-vendor-profile" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>" data-testid="lnkup-public-vendor-profile">
			<?php echo LNKup_Reputation_Render::public_hero( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( is_user_logged_in() && ( LNKup_Roles::is_organizer() || LNKup_Capabilities::user_has_manage_all() ) ) : ?>
				<div class="lnkup-pub-hero__ctas">
					<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( $crm_url ); ?>"><?php esc_html_e( 'View in CRM', 'lnkup-marketplace' ); ?></a>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $data['gallery'] ) ) : ?>
				<?php echo LNKup_Reputation_Render::public_gallery( $data['gallery'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
			<?php echo LNKup_Reputation_Render::public_metrics_row( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="lnkup-pub-layout">
				<div class="lnkup-pub-main">
					<section class="lnkup-pub-about lnkup-surface--primary">
						<h2 class="lnkup-type-h2"><?php esc_html_e( 'About this business', 'lnkup-marketplace' ); ?></h2>
						<?php if ( ! empty( $data['description'] ) ) : ?>
							<p class="lnkup-type-body"><?php echo esc_html( $data['description'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $data['products_services'] ) ) : ?>
							<p class="lnkup-type-body"><strong><?php esc_html_e( 'Services', 'lnkup-marketplace' ); ?></strong> — <?php echo esc_html( $data['products_services'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $data['categories'] ) ) : ?>
							<div class="lnkup-pub-about__tags">
								<?php foreach ( (array) $data['categories'] as $cat ) : ?>
									<span class="lnkup-badge lnkup-badge--outline"><?php echo esc_html( $cat ); ?></span>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<?php if ( $setup_parts ) : ?>
							<p class="lnkup-type-meta"><?php echo esc_html( implode( ' · ', $setup_parts ) ); ?></p>
						<?php endif; ?>
						<?php if ( $markets ) : ?>
							<p class="lnkup-type-meta"><strong><?php esc_html_e( 'Markets served', 'lnkup-marketplace' ); ?></strong> — <?php echo esc_html( implode( ', ', $markets ) ); ?></p>
						<?php endif; ?>
						<div class="lnkup-pub-socials">
							<?php if ( ! empty( $data['website'] ) ) : ?>
								<a href="<?php echo esc_url( $data['website'] ); ?>" rel="noopener noreferrer" target="_blank"><?php esc_html_e( 'Website', 'lnkup-marketplace' ); ?></a>
							<?php endif; ?>
							<?php if ( ! empty( $data['instagram'] ) ) : ?>
								<a href="<?php echo esc_url( 'https://instagram.com/' . ltrim( $data['instagram'], '@' ) ); ?>" rel="noopener noreferrer" target="_blank">Instagram</a>
							<?php endif; ?>
							<?php if ( ! empty( $data['tiktok'] ) ) : ?>
								<a href="<?php echo esc_url( 'https://tiktok.com/@' . ltrim( $data['tiktok'], '@' ) ); ?>" rel="noopener noreferrer" target="_blank">TikTok</a>
							<?php endif; ?>
						</div>
					</section>
					<?php echo LNKup_Reputation_Render::public_reviews_section( $data['reviews'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<section class="lnkup-section">
						<h2 class="lnkup-type-h2"><?php esc_html_e( 'Event history', 'lnkup-marketplace' ); ?></h2>
						<?php echo LNKup_Reputation_Render::completed_events_timeline( $data['events'], 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</section>
				</div>
				<aside class="lnkup-pub-sidebar">
					<?php echo LNKup_Reputation_Render::public_trust_strip( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<section class="lnkup-surface--primary">
						<h2 class="lnkup-type-h2"><?php esc_html_e( 'Reliability', 'lnkup-marketplace' ); ?></h2>
						<?php echo LNKup_Reputation_Render::reliability_meter( $data['metrics'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</section>
				</aside>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Check if application post has paid status.
	 *
	 * @param WP_Post $application Application post.
	 */
	public static function is_paid_application( $application ) {
		$app_id = is_object( $application ) ? (int) $application->ID : absint( $application );
		if ( class_exists( 'LNKup_Vendor_Readiness' ) ) {
			return LNKup_Vendor_Readiness::application_payment_satisfied( $app_id );
		}
		return in_array( get_post_meta( $app_id, '_lnkup_application_status', true ), array( 'paid', 'completed' ), true );
	}

	/**
	 * City Connector dashboard shortcode.
	 */
	public static function render_connector_dashboard() {
		LNKup_Capabilities::require_login();
		if ( ! LNKup_Roles::is_connector() && ! LNKup_Capabilities::user_has_manage_all() ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'Connector access required.', 'lnkup-marketplace' ) . '</p>';
		}
		$connector = LNKup_Connector::get_by_user_id( get_current_user_id() );
		if ( ! $connector && LNKup_Capabilities::user_has_manage_all() ) {
			return '<div class="lnkup-empty-state"><p>' . esc_html__( 'No connector profile linked to your account. Manage connectors in wp-admin.', 'lnkup-marketplace' ) . '</p></div>';
		}
		if ( $connector && 'active' !== ( $connector['status'] ?? '' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			return '<div class="lnkup-empty-state"><p>' . esc_html__( 'Your connector application is pending approval.', 'lnkup-marketplace' ) . '</p></div>';
		}
		$requested = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view      = LNKup_Connector::resolve_dashboard_view( $requested, $connector );
		$base = lnkup_page_url_helper( 'connector-dashboard' );
		$nav  = array(
			'home'      => __( 'Overview', 'lnkup-marketplace' ),
			'start'     => __( 'Get Started', 'lnkup-marketplace' ),
			'referrals' => __( 'My Referrals', 'lnkup-marketplace' ),
			'earnings'  => __( 'Earnings', 'lnkup-marketplace' ),
			'ranks'     => __( 'Ranks', 'lnkup-marketplace' ),
			'resources' => __( 'Resources', 'lnkup-marketplace' ),
			'studio'    => __( 'Content Studio', 'lnkup-marketplace' ),
			'profile'   => __( 'Profile', 'lnkup-marketplace' ),
		);
		ob_start();
		?>
		<div id="lnkup-connector-dashboard" class="lnkup-connector-dashboard" data-view="<?php echo esc_attr( $view ); ?>" data-testid="lnkup-connector-dashboard">
			<nav class="lnkup-connector-nav lnkup-tabs" aria-label="<?php esc_attr_e( 'Connector views', 'lnkup-marketplace' ); ?>">
				<?php foreach ( $nav as $slug => $label ) : ?>
					<a class="lnkup-tabs__item<?php echo $view === $slug ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'view', $slug, $base ) ); ?>" data-view="<?php echo esc_attr( $slug ); ?>"<?php echo $view === $slug ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<p class="lnkup-sr-only" data-connector-live role="status" aria-live="polite"></p>
			<div class="lnkup-panel" data-connector-panel data-view="<?php echo esc_attr( $view ); ?>">
				<div class="lnkup-skeleton lnkup-skeleton--card"></div>
			</div>
			<div id="lnkup-connector-share-sheet" class="lnkup-connector-sheet" hidden data-testid="lnkup-connector-share-sheet" role="dialog" aria-modal="true" aria-labelledby="lnkup-connector-share-title"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * City Connector interest form shortcode.
	 *
	 * @param array $atts Attributes.
	 */
	public static function render_connector_interest_form( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'market'        => '',
				'show_heading'  => '1',
			),
			$atts,
			'lnkup_connector_interest_form'
		);
		$show_heading = '0' !== (string) $atts['show_heading'] && 'false' !== strtolower( (string) $atts['show_heading'] );
		if ( function_exists( 'is_page_template' ) && is_page_template( 'page-templates/connector-interest.php' ) ) {
			$show_heading = false;
		}
		$market_slug = sanitize_title( (string) $atts['market'] );
		if ( ! $market_slug && ! empty( $_GET['market'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$market_slug = sanitize_title( wp_unslash( (string) $_GET['market'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$market_name = '';
		$market      = null;
		if ( $market_slug && class_exists( 'LNKup_Market_Pages' ) ) {
			$market = LNKup_Market_Pages::get_market_by_slug( $market_slug, false );
			$market_name = $market['display_name'] ?? '';
		}
		ob_start();
		?>
		<div class="lnkup-connector-interest">
			<?php if ( $show_heading ) : ?>
				<h1 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'Become a City Connector', 'lnkup-marketplace' ); ?></h1>
				<?php if ( $market_name ) : ?>
					<p class="lnkup-muted"><?php echo esc_html( sprintf( __( 'Help build LNKup in %s.', 'lnkup-marketplace' ), $market_name ) ); ?></p>
				<?php else : ?>
					<p class="lnkup-muted"><?php esc_html_e( 'Help local vendors and organizers discover LNKup in your city.', 'lnkup-marketplace' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<form class="lnkup-signup-form" data-testid="lnkup-connector-interest-form" method="post" action="#">
				<?php if ( $market_slug ) : ?>
					<input type="hidden" name="market_slug" value="<?php echo esc_attr( $market_slug ); ?>" />
				<?php endif; ?>
				<div class="lnkup-signup-form__field">
					<label for="lnkup_connector_name"><?php esc_html_e( 'Full name', 'lnkup-marketplace' ); ?></label>
					<input id="lnkup_connector_name" name="name" type="text" required autocomplete="name" />
				</div>
				<div class="lnkup-signup-form__field">
					<label for="lnkup_connector_email"><?php esc_html_e( 'Email', 'lnkup-marketplace' ); ?></label>
					<input id="lnkup_connector_email" name="email" type="email" required autocomplete="email" />
				</div>
				<div class="lnkup-signup-form__field">
					<label for="lnkup_connector_phone"><?php esc_html_e( 'Phone (optional)', 'lnkup-marketplace' ); ?></label>
					<input id="lnkup_connector_phone" name="phone" type="tel" autocomplete="tel" />
				</div>
				<div class="lnkup-signup-form__field">
					<label for="lnkup_connector_city"><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></label>
					<input id="lnkup_connector_city" name="city" type="text" required value="<?php echo esc_attr( $market['city'] ?? '' ); ?>" />
				</div>
				<div class="lnkup-signup-form__field">
					<label for="lnkup_connector_social"><?php esc_html_e( 'Instagram or website (optional)', 'lnkup-marketplace' ); ?></label>
					<input id="lnkup_connector_social" name="social_url" type="url" placeholder="https://" />
				</div>
				<div class="lnkup-signup-form__field">
					<label for="lnkup_connector_connected"><?php esc_html_e( 'You are connected to', 'lnkup-marketplace' ); ?></label>
					<select id="lnkup_connector_connected" name="connected_to">
						<option value="both"><?php esc_html_e( 'Vendors and organizers', 'lnkup-marketplace' ); ?></option>
						<option value="vendors"><?php esc_html_e( 'Mostly vendors', 'lnkup-marketplace' ); ?></option>
						<option value="organizers"><?php esc_html_e( 'Mostly organizers', 'lnkup-marketplace' ); ?></option>
					</select>
				</div>
				<div class="lnkup-signup-form__field">
					<label for="lnkup_connector_why"><?php esc_html_e( 'Why do you want to be a connector?', 'lnkup-marketplace' ); ?></label>
					<textarea id="lnkup_connector_why" name="why" rows="4"></textarea>
				</div>
				<button type="submit" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Submit application', 'lnkup-marketplace' ); ?></button>
				<p class="lnkup-connector-interest-status" role="status" aria-live="polite"></p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Premium external event claim landing page.
	 */
	public static function render_external_claim_landing() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( (string) ( $_GET['token'] ?? '' ) ) );
		if ( '' === $token ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'Invalid or missing claim link.', 'lnkup-marketplace' ) . '</p>';
		}

		$context = LNKup_External_Events::get_claim_landing_context( $token );
		if ( ! $context ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'This claim link is invalid or has expired.', 'lnkup-marketplace' ) . '</p>';
		}

		wp_enqueue_style( 'lnkup-external-events' );
		wp_enqueue_script( 'lnkup-external-events' );

		$event  = $context['event'] ?? array();
		$demand = $context['demand'] ?? array();
		$score  = (int) ( $demand['demand_score'] ?? $event['demand_score'] ?? 0 );
		$total  = (int) ( $demand['total'] ?? $event['vendor_interest_count'] ?? 0 );

		ob_start();
		?>
		<div class="lnkup-claim-landing" data-testid="lnkup-claim-landing" data-token="<?php echo esc_attr( $token ); ?>" data-external-id="<?php echo esc_attr( (string) (int) ( $context['external_event_id'] ?? 0 ) ); ?>">
			<?php
			echo lnkup_executive_hero(
				array(
					'title'    => lnkup_copy( 'claim_event_title' ),
					'subtitle' => lnkup_copy( 'claim_event_subtitle' ),
					'variant'  => 'organizer',
				)
			);
			?>
			<div class="lnkup-claim-landing__grid lnkup-surface--primary">
				<section class="lnkup-claim-landing__hero-card">
					<?php if ( ! empty( $event['image_url'] ) ) : ?>
						<img class="lnkup-claim-landing__image" src="<?php echo esc_url( (string) $event['image_url'] ); ?>" alt="" loading="lazy" />
					<?php endif; ?>
					<h2><?php echo esc_html( (string) ( $event['title'] ?? '' ) ); ?></h2>
					<ul class="lnkup-claim-landing__meta">
						<?php if ( ! empty( $event['date_label'] ) ) : ?><li><?php echo esc_html( (string) $event['date_label'] ); ?></li><?php endif; ?>
						<?php if ( ! empty( $event['location_label'] ) ) : ?><li><?php echo esc_html( (string) $event['location_label'] ); ?></li><?php endif; ?>
						<?php if ( ! empty( $event['venue_name'] ) ) : ?><li><?php echo esc_html( (string) $event['venue_name'] ); ?></li><?php endif; ?>
					</ul>
				</section>
				<section class="lnkup-claim-landing__demand">
					<h3><?php echo esc_html( lnkup_copy( 'vendor_interest_by_category' ) ); ?></h3>
					<div class="lnkup-claim-landing__score">
						<span class="lnkup-demand-ring lnkup-demand-ring--lg" data-score="<?php echo esc_attr( (string) $score ); ?>"><?php echo esc_html( (string) $score ); ?></span>
						<p><?php echo esc_html( sprintf( lnkup_copy( 'external_event_interest_count' ), $total ) ); ?></p>
					</div>
					<?php if ( ! empty( $demand['breakdown'] ) ) : ?>
						<ul class="lnkup-claim-landing__breakdown">
							<?php foreach ( (array) $demand['breakdown'] as $line ) : ?>
								<li><?php echo esc_html( sprintf( '%s (%d)', (string) ( $line['label'] ?? '' ), (int) ( $line['count'] ?? 0 ) ) ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>
				<section class="lnkup-claim-landing__benefits">
					<p><?php echo esc_html( lnkup_copy( 'organizer_claim_benefits' ) ); ?></p>
					<div class="lnkup-claim-landing__actions">
						<?php if ( is_user_logged_in() && ( LNKup_Roles::is_organizer() || LNKup_Capabilities::user_has_manage_all() ) ) : ?>
							<button type="button" class="lnkup-btn lnkup-btn--primary" id="lnkup-claim-event-inline" data-testid="lnkup-claim-event-cta"><?php echo esc_html( lnkup_copy( 'external_event_claim_cta' ) ); ?></button>
							<p class="lnkup-claim-landing__status" id="lnkup-claim-event-status" role="status" aria-live="polite"></p>
						<?php else : ?>
							<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( (string) ( $context['signup_url'] ?? '#' ) ); ?>" data-testid="lnkup-claim-event-cta"><?php echo esc_html( lnkup_copy( 'external_event_claim_cta' ) ); ?></a>
							<p class="lnkup-muted"><?php echo esc_html( lnkup_copy( 'claim_event_pending' ) ); ?></p>
						<?php endif; ?>
					</div>
				</section>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
