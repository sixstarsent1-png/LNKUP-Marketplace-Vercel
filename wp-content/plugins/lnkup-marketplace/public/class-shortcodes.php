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
			'lnkup_organizer_public_profile' => 'render_organizer_public_profile',
			'lnkup_connector_dashboard'  => 'render_connector_dashboard',
			'lnkup_connector_interest_form' => 'render_connector_interest_form',
			'lnkup_external_claim_landing'  => 'render_external_claim_landing',
		);
		add_action( 'template_redirect', array( __CLASS__, 'handle_invoice_verify' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_organizer_slug_alias' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_legacy_profile_edit' ) );
		foreach ( $map as $code => $method ) {
			add_shortcode( $code, array( __CLASS__, $method ) );
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'preserve_dashboard_view_param' ), 10, 2 );
		add_filter( 'get_canonical_url', array( __CLASS__, 'event_query_canonical' ), 10, 2 );
	}

	/**
	 * Prefer the event permalink over /opportunities/?event=.
	 *
	 * @param string  $canonical Canonical URL.
	 * @param WP_Post $post      Queried post.
	 */
	public static function event_query_canonical( $canonical, $post ) {
		unset( $post );
		if ( is_admin() || ! is_page() ) {
			return $canonical;
		}
		$event_id = isset( $_GET['event'] ) ? absint( wp_unslash( $_GET['event'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $event_id < 1 ) {
			return $canonical;
		}
		$event = get_post( $event_id );
		if ( ! $event || 'lnkup_event' !== $event->post_type || 'publish' !== $event->post_status ) {
			return $canonical;
		}
		$permalink = get_permalink( $event );
		return $permalink ? $permalink : $canonical;
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
		$ip_hash  = md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt( 'nonce' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$rate_key = 'lnkup_inv_verify_' . $ip_hash;
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 20 ) {
			wp_die( esc_html__( 'Too many verification attempts. Please try again later.', 'lnkup-marketplace' ), 429 );
		}
		set_transient( $rate_key, $attempts + 1, 10 * MINUTE_IN_SECONDS );
		$token   = sanitize_text_field( wp_unslash( $_GET['lnkup_verify_invoice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$invoice = LNKup_Invoices::verify_by_token( $token );
		if ( ! $invoice ) {
			wp_die( esc_html__( 'Invalid or expired invoice verification link.', 'lnkup-marketplace' ), 404 );
		}
		wp_die( LNKup_Invoices::render_html( $invoice ), esc_html( $invoice['invoice_number'] ), array( 'response' => 200 ) );
	}

	/**
	 * Send old organizer profile links to the current slug.
	 */
	public static function redirect_organizer_slug_alias() {
		if ( ! is_page( 'organizers' ) || empty( $_GET['organizer'] ) || ! class_exists( 'LNKup_Organizer_Onboarding' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$slug    = sanitize_title( wp_unslash( $_GET['organizer'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = LNKup_Organizer_Onboarding::organizer_id_from_public_slug( $slug );
		if ( ! $user_id ) {
			return;
		}
		$current = LNKup_Organizer_Onboarding::organizer_public_slug( $user_id );
		if ( $slug === $current ) {
			return;
		}
		wp_safe_redirect( LNKup_Organizer_Onboarding::organizer_public_url( $user_id ), 301 );
		exit;
	}

	/**
	 * The profile editor used to live on Events behind ?profile=edit. Send old links to the Profile page.
	 */
	public static function redirect_legacy_profile_edit() {
		if ( ! is_page( 'organizer-dashboard' ) || ! isset( $_GET['profile'] ) || 'edit' !== sanitize_key( wp_unslash( $_GET['profile'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_safe_redirect( lnkup_organizer_view_url( 'profile' ), 302 );
		exit;
	}

	/**
	 * Organizer dashboard view and detail route for asset loading.
	 *
	 * @return array{page:bool,view:string,detail:string}
	 */
	private static function organizer_asset_context() {
		$context = array(
			'page'   => is_page( 'organizer-dashboard' ),
			'view'   => 'home',
			'detail' => '',
		);
		if ( ! $context['page'] ) {
			return $context;
		}
		if ( ! empty( $_GET['vendor'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$context['detail'] = 'vendor';
		} elseif ( ! empty( $_GET['event'] ) && ! empty( $_GET['roster'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$context['detail'] = 'roster';
		} elseif ( ! empty( $_GET['event'] ) && ! empty( $_GET['ops'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$context['detail'] = 'ops';
		} elseif ( ! empty( $_GET['event'] ) && ! empty( $_GET['scan'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$context['detail'] = 'scan';
		} elseif ( ! empty( $_GET['event'] ) && ! empty( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$context['detail'] = 'edit';
		} elseif ( ! empty( $_GET['event'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$context['detail'] = 'workspace';
		} else {
			$context['view'] = function_exists( 'lnkup_organizer_dashboard_view' ) ? lnkup_organizer_dashboard_view() : 'home';
		}
		return $context;
	}

	/**
	 * Register front-end handles once. Callers enqueue the ones the screen renders.
	 *
	 * @param string[] $component_deps Theme component stylesheet, when present.
	 */
	private static function register_frontend_assets( $component_deps ) {
		$v2 = array( 'lnkup-marketplace-v2' );
		wp_register_style( 'lnkup-marketplace-v2', LNKUP_PLUGIN_URL . 'public/css/marketplace-v2.css', $component_deps, LNKUP_VERSION );
		wp_register_script( 'lnkup-ui', LNKUP_PLUGIN_URL . 'public/js/lnkup-ui.js', array(), LNKUP_VERSION, true );
		wp_register_script( 'lnkup-marketplace', LNKUP_PLUGIN_URL . 'public/js/marketplace.js', array(), LNKUP_VERSION, true );
		wp_register_script( 'lnkup-marketplace-v2', LNKUP_PLUGIN_URL . 'public/js/marketplace-v2.js', array( 'lnkup-marketplace' ), LNKUP_VERSION, true );
		wp_register_script( 'lnkup-marketplace-map', LNKUP_PLUGIN_URL . 'public/js/marketplace-map.js', array( 'lnkup-marketplace-v2' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-apply-wizard', LNKUP_PLUGIN_URL . 'public/css/apply-wizard.css', $v2, LNKUP_VERSION );
		wp_register_script( 'lnkup-apply-wizard', LNKUP_PLUGIN_URL . 'public/js/apply-wizard.js', array( 'lnkup-marketplace' ), LNKUP_VERSION, true );
		wp_register_script( 'lnkup-tour-apply', LNKUP_PLUGIN_URL . 'public/js/tour-apply.js', array( 'lnkup-apply-wizard' ), LNKUP_VERSION, true );
		wp_register_script( 'lnkup-app-questions-editor', LNKUP_PLUGIN_URL . 'public/js/application-questions-editor.js', array( 'lnkup-apply-wizard' ), LNKUP_VERSION, true );
		wp_register_script( 'lnkup-marketplace-compare', LNKUP_PLUGIN_URL . 'public/js/marketplace-compare.js', array( 'lnkup-marketplace-v2' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-public-profile', LNKUP_PLUGIN_URL . 'public/css/public-profile.css', $v2, LNKUP_VERSION );
		wp_register_script( 'lnkup-public-vendor-profile', LNKUP_PLUGIN_URL . 'public/js/public-vendor-profile.js', array(), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-external-events', LNKUP_PLUGIN_URL . 'public/css/external-events.css', $v2, LNKUP_VERSION );
		wp_register_script( 'lnkup-external-events', LNKUP_PLUGIN_URL . 'public/js/external-events.js', array( 'lnkup-marketplace-v2', 'lnkup-ui' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-saved-searches', LNKUP_PLUGIN_URL . 'public/css/saved-searches.css', $v2, LNKUP_VERSION );
		wp_register_script( 'lnkup-saved-searches', LNKUP_PLUGIN_URL . 'public/js/saved-searches.js', array( 'lnkup-marketplace-v2', 'lnkup-ui' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-reputation', LNKUP_PLUGIN_URL . 'public/css/reputation.css', $v2, LNKUP_VERSION );
		wp_register_script( 'lnkup-reputation', LNKUP_PLUGIN_URL . 'public/js/reputation.js', array( 'lnkup-marketplace', 'lnkup-ui' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-verification', LNKUP_PLUGIN_URL . 'public/css/verification.css', $v2, LNKUP_VERSION );
		wp_register_script( 'lnkup-verification', LNKUP_PLUGIN_URL . 'public/js/verification.js', array( 'lnkup-marketplace', 'lnkup-ui' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-weather', LNKUP_PLUGIN_URL . 'public/css/weather.css', $v2, LNKUP_VERSION );
		wp_register_script( 'lnkup-weather', LNKUP_PLUGIN_URL . 'public/js/weather.js', array( 'lnkup-marketplace', 'lnkup-ui' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-operations', LNKUP_PLUGIN_URL . 'public/css/operations.css', $v2, LNKUP_VERSION );
		wp_register_script( 'lnkup-operations', LNKUP_PLUGIN_URL . 'public/js/operations.js', array( 'lnkup-marketplace', 'lnkup-ui', 'lnkup-weather', 'lnkup-qr' ), LNKUP_VERSION, true );
		wp_register_script( 'lnkup-vendor-ops', LNKUP_PLUGIN_URL . 'public/js/vendor-ops.js', array( 'lnkup-operations' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-vendor-event-pass', LNKUP_PLUGIN_URL . 'public/css/vendor-event-pass.css', $v2, LNKUP_VERSION );
		wp_register_script( 'lnkup-vendor-event-pass', LNKUP_PLUGIN_URL . 'public/js/vendor-event-pass.js', array( 'lnkup-operations', 'lnkup-vendor-ops' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-maps', LNKUP_PLUGIN_URL . 'public/css/maps.css', $v2, LNKUP_VERSION );
		wp_register_script( 'lnkup-maps', LNKUP_PLUGIN_URL . 'public/js/maps.js', array( 'lnkup-marketplace', 'lnkup-operations', 'lnkup-ui' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-intelligence', LNKUP_PLUGIN_URL . 'public/css/intelligence.css', $v2, LNKUP_VERSION );
		wp_register_script( 'lnkup-intelligence', LNKUP_PLUGIN_URL . 'public/js/intelligence.js', array( 'lnkup-marketplace', 'lnkup-ui', 'lnkup-weather' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-financial', LNKUP_PLUGIN_URL . 'public/css/financial.css', $v2, lnkup_asset_version( 'public/css/financial.css' ) );
		wp_register_style( 'lnkup-checkout', LNKUP_PLUGIN_URL . 'public/css/checkout.css', array( 'lnkup-financial' ), LNKUP_VERSION );
		wp_register_script( 'lnkup-financial', LNKUP_PLUGIN_URL . 'public/js/financial.js', array( 'lnkup-marketplace', 'lnkup-ui' ), lnkup_asset_version( 'public/js/financial.js' ), true );
		wp_register_script( 'lnkup-checkout', LNKUP_PLUGIN_URL . 'public/js/checkout.js', array( 'lnkup-financial' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-growth', LNKUP_PLUGIN_URL . 'public/css/growth.css', $v2, LNKUP_VERSION );
		wp_register_script( 'lnkup-growth', LNKUP_PLUGIN_URL . 'public/js/growth.js', array( 'lnkup-marketplace', 'lnkup-ui' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-organizer-crm', LNKUP_PLUGIN_URL . 'public/css/organizer-crm.css', array_merge( $v2, $component_deps ), LNKUP_VERSION );
		wp_register_script( 'lnkup-organizer-crm', LNKUP_PLUGIN_URL . 'public/js/organizer-crm.js', array( 'lnkup-marketplace', 'lnkup-reputation', 'lnkup-ui' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-vendor-import', LNKUP_PLUGIN_URL . 'public/css/vendor-import.css', array( 'lnkup-organizer-crm' ), LNKUP_VERSION );
		wp_register_script( 'lnkup-vendor-import', LNKUP_PLUGIN_URL . 'public/js/vendor-import.js', array( 'lnkup-organizer-crm' ), LNKUP_VERSION, true );
		wp_register_script( 'lnkup-organizer-command', LNKUP_PLUGIN_URL . 'public/js/organizer-command.js', array( 'lnkup-organizer-crm', 'lnkup-ui', 'lnkup-intelligence' ), LNKUP_VERSION, true );
		wp_register_script( 'lnkup-organizer-create', LNKUP_PLUGIN_URL . 'public/js/organizer-create.js', array( 'lnkup-organizer-command' ), LNKUP_VERSION, true );
		wp_register_script( 'lnkup-organizer-requests', LNKUP_PLUGIN_URL . 'public/js/organizer-requests.js', array( 'lnkup-marketplace', 'lnkup-ui' ), lnkup_asset_version( 'public/js/organizer-requests.js' ), true );
		wp_register_script( 'lnkup-vendor-home', LNKUP_PLUGIN_URL . 'public/js/vendor-home.js', array( 'lnkup-marketplace-v2', 'lnkup-ui' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-vendor-profile', LNKUP_PLUGIN_URL . 'public/css/vendor-profile.css', array_merge( $component_deps, array( 'lnkup-vendor-dashboard' ) ), LNKUP_VERSION );
		wp_register_script( 'lnkup-vendor-profile', LNKUP_PLUGIN_URL . 'public/js/vendor-profile.js', array( 'lnkup-marketplace', 'jquery' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-passport', LNKUP_PLUGIN_URL . 'public/css/passport.css', $v2, LNKUP_VERSION );
		wp_register_script( 'lnkup-passport', LNKUP_PLUGIN_URL . 'public/js/passport.js', array( 'lnkup-qr' ), LNKUP_VERSION, true );
		wp_register_style( 'lnkup-connector', LNKUP_PLUGIN_URL . 'public/css/connector.css', $v2, LNKUP_VERSION );
		wp_register_script( 'lnkup-connector', LNKUP_PLUGIN_URL . 'public/js/connector.js', array( 'lnkup-marketplace', 'lnkup-ui', 'lnkup-qr' ), LNKUP_VERSION, true );
	}

	/**
	 * Enqueue plugin frontend assets for the current screen.
	 */
	public static function enqueue_assets() {
		$is_dashboard = self::is_dashboard_front_page()
			|| ( function_exists( 'lnkup_theme_is_dashboard_page' ) && lnkup_theme_is_dashboard_page() );
		$component_deps = array();
		if ( wp_style_is( 'lnkup-components', 'registered' ) || wp_style_is( 'lnkup-components', 'enqueued' ) ) {
			$component_deps[] = 'lnkup-components';
		}
		self::register_frontend_assets( $component_deps );

		$vendor_dash = is_page( 'vendor-dashboard' );
		$vendor_view = 'home';
		if ( $vendor_dash && isset( $_GET['view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$vendor_view = sanitize_key( wp_unslash( $_GET['view'] ) );
		}
		if ( '' === $vendor_view ) {
			$vendor_view = 'home';
		}
		$org            = self::organizer_asset_context();
		$public_event   = is_singular( array( 'lnkup_event', 'lnkup_opportunity' ) ) || ( is_page( 'opportunities' ) && ! empty( $_GET['event'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$feed           = is_page( array( 'opportunities', 'favorites' ) ) && ! $public_event;
		$public_profile = is_page( array( 'vendors', 'organizers' ) );
		$signup         = is_page( array( 'sign-up', 'register' ) );
		$invite_banner  = $feed && is_user_logged_in() && ! empty( $_GET['invite'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $is_dashboard ) {
			wp_enqueue_style(
				'lnkup-vendor-dashboard',
				LNKUP_PLUGIN_URL . 'public/css/vendor-dashboard.css',
				$component_deps,
				LNKUP_VERSION
			);
		}

		$needs_marketplace = $feed || $public_event || $signup || is_page( 'vendor-profile' ) || $vendor_dash || $org['page'];
		$vendor_discovery  = ! $vendor_dash || in_array( $vendor_view, array( 'home', 'applications' ), true );
		if ( $needs_marketplace ) {
			wp_enqueue_style( 'lnkup-marketplace-v2' );
			wp_enqueue_script( 'lnkup-marketplace' );
			if ( $vendor_discovery ) {
				wp_enqueue_script( 'lnkup-marketplace-v2' );
			}
			$wizard_on = LNKup_Rollout::enabled( 'apply_wizard_enabled' );
			$multi_on  = LNKup_Rollout::enabled( 'tour_multi_apply_enabled' );
			// The wizard also powers withdraw buttons and draft resume cards on vendor Requests.
			if ( $wizard_on || $multi_on || ( $vendor_dash && in_array( $vendor_view, array( 'home', 'applications' ), true ) ) ) {
				wp_enqueue_style( 'lnkup-apply-wizard' );
				wp_enqueue_script( 'lnkup-apply-wizard' );
			}
			if ( $feed && ! empty( $_GET['tour_id'] ) && $multi_on ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				wp_enqueue_script( 'lnkup-tour-apply' );
			}
		}
		if ( $feed && lnkup_marketplace_phase_at_least( '1b' ) ) {
			wp_enqueue_script( 'lnkup-marketplace-map' );
		}
		if ( $feed && lnkup_marketplace_phase_at_least( '1c' ) ) {
			wp_enqueue_script( 'lnkup-marketplace-compare' );
		}
		if ( $public_event || $public_profile ) {
			wp_enqueue_style( 'lnkup-public-profile' );
		}
		if ( $public_profile ) {
			wp_enqueue_script( 'lnkup-public-vendor-profile' );
		}
		if ( $feed || $signup ) {
			wp_enqueue_style( 'lnkup-external-events' );
			wp_enqueue_script( 'lnkup-external-events' );
		}
		if ( $feed ) {
			wp_enqueue_style( 'lnkup-saved-searches' );
			wp_enqueue_script( 'lnkup-saved-searches' );
		}
		if ( $vendor_dash && in_array( $vendor_view, array( 'home', 'help' ), true ) ) {
			wp_enqueue_script( 'lnkup-vendor-home' );
		}
		if ( is_page( 'vendor-profile' ) ) {
			wp_enqueue_style( 'lnkup-vendor-profile' );
			wp_enqueue_script( 'lnkup-vendor-profile' );
		}
		if ( $public_profile ) {
			wp_enqueue_style( 'lnkup-passport' );
		}
		if ( $vendor_dash && 'passport' === $vendor_view && is_user_logged_in() ) {
			wp_enqueue_style( 'lnkup-reputation' );
			wp_enqueue_style( 'lnkup-passport' );
			wp_enqueue_script( 'lnkup-passport' );
			wp_localize_script(
				'lnkup-passport',
				'lnkupPassport',
				array(
					'root'         => esc_url_raw( rest_url( 'lnkup/v1' ) ),
					'nonce'        => wp_create_nonce( 'wp_rest' ),
					'profileUrl'   => esc_url_raw( lnkup_page_url_helper( 'vendor-profile' ) ),
					'documentsUrl' => esc_url_raw( add_query_arg( 'view', 'documents', lnkup_page_url_helper( 'vendor-dashboard' ) ) ),
					'findUrl'      => esc_url_raw( lnkup_page_url_helper( 'opportunities' ) ),
					'flags'        => class_exists( 'LNKup_Passport_Settings' ) ? array_intersect_key( LNKup_Passport_Settings::snapshot(), array_flip( LNKup_Passport_Settings::FLAGS ) ) : array(),
					'documentTypes' => LNKup_Documents::document_types(),
					'reportReasons' => LNKup_Vendor_Reviews::report_reasons(),
				)
			);
		}

		$needs_reputation = $public_profile || ( $vendor_dash && 'reviews' === $vendor_view ) || $invite_banner || $org['page'];
		if ( $needs_reputation ) {
			wp_enqueue_style( 'lnkup-reputation' );
			wp_enqueue_script( 'lnkup-reputation' );
		}
		if ( is_page( 'vendor-profile' ) || ( $vendor_dash && in_array( $vendor_view, array( 'documents', 'home' ), true ) ) ) {
			wp_enqueue_style( 'lnkup-verification' );
			wp_enqueue_script( 'lnkup-verification' );
		}

		$needs_operations = ( $vendor_dash && 'operations' === $vendor_view )
			|| ( $org['page'] && ( 'operations' === $org['view'] || in_array( $org['detail'], array( 'roster', 'ops', 'scan', 'workspace' ), true ) ) );
		if ( $needs_operations ) {
			wp_enqueue_style( 'lnkup-weather' );
			wp_enqueue_script( 'lnkup-weather' );
			wp_enqueue_style( 'lnkup-operations' );
			wp_enqueue_script( 'lnkup-operations' );
			wp_enqueue_script( 'lnkup-vendor-ops' );
			wp_enqueue_style( 'lnkup-vendor-event-pass' );
			wp_enqueue_script( 'lnkup-vendor-event-pass' );
			wp_enqueue_style( 'lnkup-maps' );
			wp_enqueue_script( 'lnkup-maps' );
		}

		$needs_checkout = $vendor_dash && in_array( $vendor_view, array( 'home', 'applications', 'payments', 'financial', 'operations' ), true );
		$needs_financial = $needs_checkout || ( $org['page'] && '' === $org['detail'] && 'financial' === $org['view'] );
		if ( $needs_financial ) {
			wp_enqueue_style( 'lnkup-financial' );
			wp_enqueue_script( 'lnkup-financial' );
		}
		if ( $needs_checkout ) {
			wp_enqueue_style( 'lnkup-checkout' );
			wp_enqueue_script( 'lnkup-checkout' );
			wp_localize_script(
				'lnkup-checkout',
				'lnkupCheckoutConfig',
				array(
					'stripePublicKey' => lnkup_get_settings()['stripe_public_key'] ?? '',
				)
			);
		}
		if ( $vendor_dash && 'growth' === $vendor_view && is_user_logged_in() && LNKup_Capabilities::can_view_growth() ) {
			wp_enqueue_style( 'lnkup-growth' );
			wp_enqueue_script( 'lnkup-growth' );
		}

		if ( $org['page'] ) {
			wp_enqueue_style( 'lnkup-organizer-crm' );
			wp_enqueue_script( 'lnkup-organizer-crm' );
			wp_enqueue_style( 'lnkup-apply-wizard' );
			wp_enqueue_script( 'lnkup-app-questions-editor' );
		}
		if ( $invite_banner ) {
			wp_enqueue_script( 'lnkup-organizer-crm' );
		}
		if ( $org['page'] && is_user_logged_in() ) {
			wp_enqueue_style( 'lnkup-organizer-start', LNKUP_PLUGIN_URL . 'public/css/organizer-start.css', array(), LNKUP_VERSION );
		}
		if ( $org['page'] && 'start' === $org['view'] && is_user_logged_in() ) {
			wp_enqueue_script( 'lnkup-organizer-start', LNKUP_PLUGIN_URL . 'public/js/organizer-start.js', array(), LNKUP_VERSION, true );
			wp_localize_script(
				'lnkup-organizer-start',
				'lnkupStart',
				array(
					'root'  => esc_url_raw( rest_url( 'lnkup/v1' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				)
			);
		}
		if ( $org['page'] && is_user_logged_in() && LNKup_Organizer_OS_Tables::ready( '2' ) && ( LNKup_Feature_Flags::organizer_ops() || LNKup_Feature_Flags::document_review() ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ops_tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			$ops_panel = ( 'workspace' === $org['detail'] && in_array( $ops_tab, array( 'readiness', 'tasks', 'documents' ), true ) )
				|| ( '' === $org['detail'] && 'tasks' === $org['view'] );
			if ( $ops_panel || ( '' === $org['detail'] && 'home' === $org['view'] && LNKup_Feature_Flags::organizer_ops() ) || ( 'workspace' === $org['detail'] && LNKup_Capabilities::is_team_only_member() ) ) {
				wp_enqueue_style( 'lnkup-organizer-ops', LNKUP_PLUGIN_URL . 'public/css/organizer-ops.css', array(), lnkup_asset_version( 'public/css/organizer-ops.css' ) );
			}
			if ( $ops_panel || ( 'workspace' === $org['detail'] && LNKup_Capabilities::is_team_only_member() ) ) {
				wp_enqueue_script( 'lnkup-organizer-ops-core', LNKUP_PLUGIN_URL . 'public/js/organizer-ops-core.js', array(), lnkup_asset_version( 'public/js/organizer-ops-core.js' ), true );
				foreach ( array( 'readiness', 'tasks', 'documents' ) as $ops_module ) {
					wp_enqueue_script( 'lnkup-organizer-' . $ops_module, LNKUP_PLUGIN_URL . 'public/js/organizer-' . $ops_module . '.js', array( 'lnkup-organizer-ops-core' ), lnkup_asset_version( 'public/js/organizer-' . $ops_module . '.js' ), true );
				}
				wp_localize_script(
					'lnkup-organizer-ops-core',
					'lnkupOps',
					array(
						'root'      => esc_url_raw( rest_url( 'lnkup/v1' ) ),
						'nonce'     => wp_create_nonce( 'wp_rest' ),
						'dashboard' => esc_url_raw( lnkup_page_url_helper( 'organizer-dashboard' ) ),
						'userId'    => get_current_user_id(),
					)
				);
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$review_classic = isset( $_GET['mode'] ) && 'classic' === sanitize_key( wp_unslash( $_GET['mode'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$workspace_tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$review_in_tab  = 'workspace' === $org['detail'] && in_array( $workspace_tab, array( 'applications', 'requests' ), true );
		if ( $org['page'] && ( ( '' === $org['detail'] && 'applications' === $org['view'] ) || $review_in_tab ) && is_user_logged_in() && LNKup_Feature_Flags::application_review() && ( ! $review_classic || LNKup_Capabilities::is_team_only_member() ) ) {
			wp_enqueue_style( 'lnkup-organizer-review', LNKUP_PLUGIN_URL . 'public/css/organizer-review.css', array(), lnkup_asset_version( 'public/css/organizer-review.css' ) );
			wp_enqueue_script( 'lnkup-organizer-review', LNKUP_PLUGIN_URL . 'public/js/organizer-review.js', array(), lnkup_asset_version( 'public/js/organizer-review.js' ), true );
			wp_localize_script(
				'lnkup-organizer-review',
				'lnkupReview',
				array(
					'root'      => esc_url_raw( rest_url( 'lnkup/v1' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'offers'    => LNKup_Feature_Flags::waitlist_offers(),
					'dashboard' => esc_url_raw( lnkup_page_url_helper( 'organizer-dashboard' ) ),
				)
			);
		}
		if ( $org['page'] && is_user_logged_in() && LNKup_Capabilities::is_team_only_member() ) {
			wp_enqueue_style( 'lnkup-org-team', LNKUP_PLUGIN_URL . 'public/css/org-team.css', array(), LNKUP_VERSION );
		}
		if ( $org['page'] && 'tours' === $org['view'] && is_user_logged_in() && LNKup_Feature_Flags::portfolio() ) {
			wp_enqueue_style( 'lnkup-portfolio', LNKUP_PLUGIN_URL . 'public/css/portfolio.css', array(), lnkup_asset_version( 'public/css/portfolio.css' ) );
			wp_enqueue_script( 'lnkup-portfolio', LNKUP_PLUGIN_URL . 'public/js/portfolio.js', array(), lnkup_asset_version( 'public/js/portfolio.js' ), true );
			wp_localize_script(
				'lnkup-portfolio',
				'lnkupPortfolio',
				array(
					'root'      => esc_url_raw( rest_url( 'lnkup/v1' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'viewUrl'   => esc_url_raw( lnkup_organizer_view_url( 'tours' ) ),
					'dashboard' => esc_url_raw( lnkup_page_url_helper( 'organizer-dashboard' ) ),
					'documents' => class_exists( 'LNKup_Documents' ) ? LNKup_Documents::document_types() : array(),
				)
			);
		}
		if ( $org['page'] && 'team' === $org['view'] && is_user_logged_in() ) {
			wp_enqueue_style( 'lnkup-org-team', LNKUP_PLUGIN_URL . 'public/css/org-team.css', array(), LNKUP_VERSION );
			wp_enqueue_script( 'lnkup-org-team', LNKUP_PLUGIN_URL . 'public/js/org-team.js', array(), LNKUP_VERSION, true );
			wp_localize_script(
				'lnkup-org-team',
				'lnkupOrgTeam',
				array(
					'root'        => esc_url_raw( rest_url( 'lnkup/v1' ) ),
					'nonce'       => wp_create_nonce( 'wp_rest' ),
					'homeUrl'     => esc_url_raw( lnkup_organizer_view_url( 'home' ) ),
					'moneyUrl'    => esc_url_raw( lnkup_organizer_view_url( 'financial', array( 'fin_tab' => 'settings' ) ) ),
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'inviteToken' => isset( $_GET['lnkup_team_invite'] ) ? sanitize_text_field( wp_unslash( $_GET['lnkup_team_invite'] ) ) : '',
				)
			);
		}
		if ( $org['page'] && class_exists( 'LNKup_Match_Settings' ) && ( LNKup_Match_Settings::enabled( 'matching_organizer_discovery_enabled' ) || LNKup_Match_Settings::enabled( 'matching_tours_enabled' ) ) ) {
			wp_enqueue_script( 'lnkup-find-vendors', LNKUP_PLUGIN_URL . 'public/js/find-vendors.js', array( 'lnkup-organizer-crm' ), LNKUP_VERSION, true );
		}
		if ( $org['page'] && '' === $org['detail'] && 'vendors' === $org['view'] ) {
			wp_enqueue_style( 'lnkup-vendor-import' );
			wp_enqueue_script( 'lnkup-vendor-import' );
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
		}
		if ( $org['page'] && ( ( '' === $org['detail'] && in_array( $org['view'], array( 'intelligence', 'create' ), true ) ) || 'edit' === $org['detail'] ) ) {
			wp_enqueue_style( 'lnkup-intelligence' );
			wp_enqueue_script( 'lnkup-intelligence' );
			wp_enqueue_script( 'lnkup-organizer-command' );
		}
		$org_requests_screen = $org['page'] && ( ( '' === $org['detail'] && in_array( $org['view'], array( 'applications', 'home' ), true ) ) || 'workspace' === $org['detail'] );
		if ( $org_requests_screen ) {
			wp_enqueue_style( 'lnkup-intelligence' );
			wp_enqueue_script( 'lnkup-intelligence' );
			wp_enqueue_script( 'lnkup-organizer-requests' );
		}
		if ( $org['page'] && ( ( '' === $org['detail'] && 'create' === $org['view'] ) || 'edit' === $org['detail'] ) ) {
			wp_enqueue_script( 'lnkup-organizer-create' );
			wp_localize_script(
				'lnkup-organizer-create',
				'lnkupEventTypeTree',
				array(
					'tree' => lnkup_get_event_type_tree(),
				)
			);
		}

		if ( is_page( array( 'connector-dashboard', 'connector-interest' ) ) || ( is_user_logged_in() && LNKup_Roles::is_connector() && ( is_page( 'connector-dashboard' ) || is_page( 'connector-interest' ) ) ) ) {
			wp_enqueue_style( 'lnkup-connector' );
			wp_enqueue_script( 'lnkup-connector' );
		}
		if ( is_page( array( 'affiliate-program', 'partner-with-lnkup' ) ) ) {
			wp_enqueue_style( 'lnkup-connector' );
		}

		if ( wp_script_is( 'lnkup-ui', 'enqueued' ) && class_exists( 'LNKup_UI_Copy' ) ) {
			wp_localize_script( 'lnkup-ui', 'lnkupUiCopy', LNKup_UI_Copy::for_js() );
		}
		if ( wp_script_is( 'lnkup-marketplace', 'enqueued' ) ) {
			wp_localize_script(
				'lnkup-marketplace',
				'lnkupAjax',
				array(
					'url'                   => admin_url( 'admin-ajax.php' ),
					'nonce'                 => wp_create_nonce( 'lnkup_action' ),
					'reviewNonce'           => wp_create_nonce( 'lnkup_review' ),
					'opportunityNonce'      => wp_create_nonce( 'lnkup_opportunity' ),
					'restUrl'               => esc_url_raw( rest_url( 'lnkup/v1/marketplace' ) ),
					'applicationsRestUrl'   => esc_url_raw( rest_url( 'lnkup/v1/applications' ) ),
					'vendorProfileRestUrl'  => esc_url_raw( rest_url( 'lnkup/v1/vendor/profile' ) ),
					'crmRestUrl'            => esc_url_raw( rest_url( 'lnkup/v1/crm' ) ),
					'matchRestUrl'          => esc_url_raw( rest_url( 'lnkup/v1/match' ) ),
					'toursRestUrl'          => esc_url_raw( rest_url( 'lnkup/v1/tours' ) ),
					'matching'              => array(
						'vendor'    => class_exists( 'LNKup_Match_Settings' ) && LNKup_Match_Settings::enabled( 'matching_vendor_discovery_enabled' ),
						'organizer' => class_exists( 'LNKup_Match_Settings' ) && LNKup_Match_Settings::enabled( 'matching_organizer_discovery_enabled' ),
						'tours'     => class_exists( 'LNKup_Match_Settings' ) && LNKup_Match_Settings::enabled( 'matching_tours_enabled' ),
					),
					'reputationRestUrl'     => esc_url_raw( rest_url( 'lnkup/v1/reputation' ) ),
					'operationsRestUrl'     => esc_url_raw( rest_url( 'lnkup/v1/operations' ) ),
					'packetsRestUrl'        => esc_url_raw( rest_url( 'lnkup/v1/packets' ) ),
					'intelligenceRestUrl'   => esc_url_raw( rest_url( 'lnkup/v1/intelligence' ) ),
					'weatherRestUrl'        => esc_url_raw( rest_url( 'lnkup/v1/weather' ) ),
					'financialRestUrl'      => esc_url_raw( rest_url( 'lnkup/v1/financial' ) ),
					'growthRestUrl'         => esc_url_raw( rest_url( 'lnkup/v1/growth' ) ),
					'connectorRestUrl'      => esc_url_raw( rest_url( 'lnkup/v1' ) ),
					'verificationRestUrl'   => esc_url_raw( rest_url( 'lnkup/v1/verification' ) ),
					'mapsRestUrl'           => esc_url_raw( rest_url( 'lnkup/v1/maps' ) ),
					'mapProvider'           => LNKup_Map_Provider::frontend_config(),
					'vendorsUrl'            => lnkup_page_url_helper( 'vendors' ),
					'restNonce'             => wp_create_nonce( 'wp_rest' ),
					'isLoggedIn'            => is_user_logged_in(),
					'isVendor'              => LNKup_Roles::is_vendor() || LNKup_Capabilities::user_has_manage_all(),
					'registrationUrl'       => lnkup_registration_url(),
					'favoritesUrl'          => lnkup_page_url_helper( 'favorites' ),
					'savedSearchLimit'      => class_exists( 'LNKup_Saved_Searches' ) ? LNKup_Saved_Searches::limit_per_vendor() : 10,
					'marketplacePhase'      => lnkup_get_marketplace_phase(),
					'opportunitiesUrl'      => lnkup_page_url_helper( 'opportunities' ),
					'externalEventsRestUrl' => esc_url_raw( rest_url( 'lnkup/v1' ) ),
					'vendorProfileUrl'      => lnkup_page_url_helper( 'vendor-profile' ),
					'organizerDashboardUrl' => lnkup_page_url_helper( 'organizer-dashboard' ),
					'stripePublicKey'       => lnkup_get_settings()['stripe_public_key'] ?? '',
					'eventTypeTree'         => lnkup_get_event_type_tree(),
				)
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
		if ( ! wp_script_is( 'lnkup-ui', 'registered' ) ) {
			wp_register_script( 'lnkup-ui', LNKUP_PLUGIN_URL . 'public/js/lnkup-ui.js', array(), LNKUP_VERSION, true );
		}
		wp_enqueue_script(
			'lnkup-organizer-recruit',
			LNKUP_PLUGIN_URL . 'public/js/organizer-recruit.js',
			array( 'lnkup-ui' ),
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
			'passport'     => 'render_passport',
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
		$is_invite = class_exists( 'LNKup_Org_Workspace' ) && LNKup_Org_Workspace::is_invite_request();
		if ( ! LNKup_Capabilities::can_access_org_workspace() && ! $is_invite ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'Organizer access required.', 'lnkup-marketplace' ) . '</p>';
		}
		if ( LNKup_Capabilities::is_team_only_member() || ( $is_invite && ! LNKup_Capabilities::can_access_org_workspace() ) ) {
			return self::render_staff_dashboard();
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

		$edit_event = isset( $_GET['event'] ) && ! empty( $_GET['edit'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $edit_event ) {
			return self::render_event_edit_view( $edit_event );
		}

		$scan_event = isset( $_GET['event'] ) && ! empty( $_GET['scan'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $scan_event ) {
			return self::render_qr_scan_view( $scan_event );
		}

		$workspace_event = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $workspace_event ) {
			return self::render_event_workspace( $workspace_event );
		}

		return LNKup_Organizer_Dashboard_Views::render( lnkup_organizer_dashboard_view() );
	}

	/**
	 * Team members without an organizer account: staff home, review queue, team access and the
	 * event pages their grants allow (each page re-checks access for its event).
	 */
	private static function render_staff_dashboard() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$event_id = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0;
		if ( $event_id && ! empty( $_GET['scan'] ) ) {
			return self::render_qr_scan_view( $event_id );
		}
		if ( $event_id && ! empty( $_GET['roster'] ) ) {
			return self::render_event_roster_view( $event_id );
		}
		if ( $event_id && ! empty( $_GET['ops'] ) ) {
			return self::render_event_operations_view( $event_id );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $event_id ) {
			$ops_tabs = self::ops_tabs( $event_id );
			$tab      = isset( $ops_tabs[ $tab ] ) ? $tab : (string) array_key_first( $ops_tabs );
			if ( '' !== $tab ) {
				$links = '';
				foreach ( $ops_tabs as $key => $label ) {
					$links .= '<a class="lnkup-btn lnkup-btn--sm ' . ( $key === $tab ? 'lnkup-btn--primary' : 'lnkup-btn--ghost' ) . '" href="' . esc_url( add_query_arg( array( 'event' => $event_id, 'tab' => $key ), lnkup_page_url_helper( 'organizer-dashboard' ) ) ) . '">' . esc_html( $label ) . '</a>';
				}
				return '<div class="lnkup-mp lnkup-organizer-dash">' . lnkup_page_header(
					array(
						'title'      => get_the_title( $event_id ),
						'back_url'   => lnkup_organizer_view_url( 'home' ),
						'back_label' => __( 'My work', 'lnkup-marketplace' ),
						'actions'    => $links,
					)
				) . self::render_ops_panel( $event_id, $tab ) . '</div>';
			}
		}
		$view = lnkup_organizer_dashboard_view();
		if ( 'team' === $view || ( 'applications' === $view && LNKup_Feature_Flags::application_review() ) || ( 'tasks' === $view && LNKup_Feature_Flags::organizer_ops() ) || 'help' === $view ) {
			return LNKup_Organizer_Dashboard_Views::render( $view );
		}
		return '<div class="lnkup-mp lnkup-organizer-dash">' . LNKup_Org_Workspace::render_staff_home( get_current_user_id() ) . '</div>';
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
			<header class="lnkup-mp-v2__hero lnkup-page-header">
				<div class="lnkup-mp-v2__hero-text lnkup-page-header__main">
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
					<h1 class="lnkup-page-header__title"><?php esc_html_e( 'Find Events opens on launch day', 'lnkup-marketplace' ); ?></h1>
					<p class="lnkup-page-header__subtitle"><?php esc_html_e( 'Create your free account now and build your vendor profile. Browse festivals, markets, and live opportunities when LNKup launches.', 'lnkup-marketplace' ); ?></p>
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
	 * Public event page: details plus the vendor slots for sale.
	 *
	 * @param int $event_id Event post ID.
	 */
	public static function render_public_event( $event_id ) {
		$event = get_post( (int) $event_id );
		if ( ! $event || 'lnkup_event' !== $event->post_type || 'publish' !== $event->post_status ) {
			return '<p class="lnkup-empty">' . esc_html__( 'Event not found.', 'lnkup-marketplace' ) . '</p>';
		}
		if ( ! class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			return '<p class="lnkup-empty">' . esc_html__( 'Event not found.', 'lnkup-marketplace' ) . '</p>';
		}
		$viewer    = LNKup_Access_Policy::viewer_context( (int) $event->ID );
		$guest     = LNKup_Access_Policy::GUEST === $viewer;
		$slots     = array_values(
			array_filter(
				LNKup_Organizer_Onboarding::event_slot_posts( (int) $event->ID ),
				static function ( $slot ) use ( $viewer ) {
					return LNKup_Access_Policy::slot_listable( $slot, $viewer );
				}
			)
		);
		$unclaimed = LNKup_Organizer_Onboarding::event_is_unclaimed_external( (int) $event->ID );
		$date  = (string) get_post_meta( $event->ID, '_lnkup_event_date', true );
		$start = (string) get_post_meta( $event->ID, '_lnkup_start_time', true );
		$end   = (string) get_post_meta( $event->ID, '_lnkup_end_time', true );
		$location     = LNKup_Organizer_Onboarding::event_public_location( (int) $event->ID );
		$availability = $unclaimed
			? array( 'key' => 'external', 'label' => __( 'Not bookable on LNKup', 'lnkup-marketplace' ) )
			: LNKup_Organizer_Onboarding::event_public_availability( (int) $event->ID );
		$event_media = class_exists( 'LNKup_Event_Media' ) ? LNKup_Event_Media::resolve( (int) $event->ID ) : array();
		$image_id    = (int) ( $event_media['attachment_id'] ?? 0 );
		$image       = (string) ( $event_media['large_url'] ?? '' );
		if ( ! $event_media ) {
			$image_id = (int) get_post_meta( $event->ID, '_lnkup_featured_image_id', true ) ?: (int) get_post_thumbnail_id( $event->ID );
			$image    = $image_id ? (string) wp_get_attachment_image_url( $image_id, 'large' ) : '';
		}
		$gallery    = array();
		if ( function_exists( 'lnkup_parse_gallery_ids' ) ) {
			foreach ( lnkup_parse_gallery_ids( get_post_meta( $event->ID, '_lnkup_gallery_ids', true ) ) as $gallery_id ) {
				if ( (int) $gallery_id === $image_id ) {
					continue;
				}
				$gallery_url = wp_get_attachment_image_url( (int) $gallery_id, 'medium' );
				if ( $gallery_url ) {
					$gallery[] = $gallery_url;
				}
			}
		}
		$can_save_event = is_user_logged_in() && class_exists( 'LNKup_Roles' ) && ( LNKup_Roles::is_vendor() || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() ) );
		$event_saved    = $can_save_event && class_exists( 'LNKup_Vendor_Personalization' ) && LNKup_Vendor_Personalization::is_favorite( get_current_user_id(), 'events', (int) $event->ID );
		$attendance = (string) get_post_meta( $event->ID, '_lnkup_expected_attendance', true );
		$ticketing  = (string) get_post_meta( $event->ID, '_lnkup_ticketing_url', true );
		$indoor     = ! empty( get_post_meta( $event->ID, '_lnkup_indoor', true ) );
		$outdoor    = ! empty( get_post_meta( $event->ID, '_lnkup_outdoor', true ) );
		$setting    = '';
		if ( $indoor && $outdoor ) {
			$setting = __( 'Indoor and outdoor', 'lnkup-marketplace' );
		} elseif ( $indoor ) {
			$setting = __( 'Indoor', 'lnkup-marketplace' );
		} elseif ( $outdoor ) {
			$setting = __( 'Outdoor', 'lnkup-marketplace' );
		}
		$amenities = LNKup_Organizer_Onboarding::event_public_amenities( (int) $event->ID );
		$type_names = array();
		$event_terms = get_the_terms( $event->ID, 'lnkup_event_type' );
		if ( $event_terms && ! is_wp_error( $event_terms ) ) {
			foreach ( $event_terms as $type_term ) {
				$type_names[] = $type_term->name;
			}
		}
		$organizer_id = (int) get_post_meta( $event->ID, '_lnkup_organizer_user_id', true );
		$organizer = $organizer_id ? get_userdata( $organizer_id ) : false;
		$profile_url = ( $organizer && class_exists( 'LNKup_Organizer_Onboarding' ) ) ? LNKup_Organizer_Onboarding::organizer_public_url( $organizer_id ) : '';
		$organizer_bio = $organizer_id ? (string) get_user_meta( $organizer_id, '_lnkup_organizer_bio', true ) : '';
		$organizer_label = $organizer_id ? (string) get_user_meta( $organizer_id, '_lnkup_organizer_public_name', true ) : '';
		if ( '' === $organizer_label && $organizer ) {
			$organizer_label = $organizer->display_name;
		}
		$organizer_tagline = $organizer_id ? (string) get_user_meta( $organizer_id, '_lnkup_organizer_tagline', true ) : '';
		$organizer_city    = $organizer_id ? (string) get_user_meta( $organizer_id, '_lnkup_organizer_city', true ) : '';
		$organizer_state   = $organizer_id ? (string) get_user_meta( $organizer_id, '_lnkup_organizer_state', true ) : '';
		$organizer_where   = function_exists( 'lnkup_display_place' ) ? lnkup_display_place( $organizer_city, $organizer_state ) : trim( $organizer_city . ( $organizer_state ? ', ' . $organizer_state : '' ) );
		$organizer_logo_id = $organizer_id ? (int) get_user_meta( $organizer_id, '_lnkup_organizer_logo_id', true ) : 0;
		$organizer_logo    = $organizer_logo_id ? (string) wp_get_attachment_image_url( $organizer_logo_id, 'thumbnail' ) : '';
		$verified          = ! $unclaimed && LNKup_Organizer_Onboarding::organizer_is_publicly_verified( $organizer_id );
		$organizer_service = $organizer_id ? (string) get_user_meta( $organizer_id, '_lnkup_organizer_service_cities', true ) : '';
		$is_owner          = is_user_logged_in() && ( get_current_user_id() === $organizer_id || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() ) );
		$dash_url          = lnkup_page_url_helper( 'organizer-dashboard' );
		$source_url        = (string) get_post_meta( $event->ID, '_lnkup_external_source_url', true );
		$apply_base        = lnkup_page_url_helper( 'opportunities' );
		$feed_url          = $apply_base;
		$about_text = trim( wp_strip_all_tags( $event->post_content ) );
		$ticket_blurb = '' !== $about_text && ( 0 === stripos( $about_text, 'Posh' ) || false !== stripos( $about_text, 'Get tickets' ) );
		$show_about = ! $unclaimed && '' !== $about_text && ! $ticket_blurb;
		$display_title = self::public_event_display_title( $event->post_title, $date, (string) $location['venue'], $organizer_label );
		self::maybe_complete_pending_follow( $organizer_id );
		$spot_cards        = array();
		$open_fee          = null;
		$open_spots        = 0;
		$earliest_deadline = '';
		foreach ( $slots as $slot ) {
			$fee       = (float) get_post_meta( $slot->ID, '_lnkup_vendor_fee', true );
			$left      = (int) get_post_meta( $slot->ID, '_lnkup_spots_remaining', true );
			$deadline  = (string) get_post_meta( $slot->ID, '_lnkup_application_deadline', true );
			$status    = (string) get_post_meta( $slot->ID, '_lnkup_opportunity_status', true );
			$is_open   = ( '' === $status || 'open' === $status );
			$past_due  = $deadline && $deadline < current_time( 'Y-m-d' );
			$can_apply = ! $unclaimed && $is_open && ! $past_due && $left > 0 && 'completed' !== $availability['key'];
			if ( $can_apply ) {
				$open_spots += $left;
				if ( null === $open_fee || $fee < $open_fee ) {
					$open_fee = $fee;
				}
			}
			if ( $deadline && ( '' === $earliest_deadline || $deadline < $earliest_deadline ) ) {
				$earliest_deadline = $deadline;
			}
			$names = wp_get_object_terms( $slot->ID, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			$labels = array();
			if ( ! is_wp_error( $names ) && $names ) {
				foreach ( $names as $name ) {
					$labels[] = function_exists( 'lnkup_display_category_badge' ) ? lnkup_display_category_badge( $name ) : $name;
				}
			}
			$labels = array_values( array_unique( array_filter( $labels ) ) );
			if ( $can_apply ) {
				$spot_state  = 'open';
				$spot_status = sprintf( _n( '%d spot available', '%d spots available', $left, 'lnkup-marketplace' ), $left );
			} elseif ( $is_open && ! $past_due && ! $unclaimed && $left < 1 ) {
				$spot_state  = 'full';
				$spot_status = __( 'Sold out', 'lnkup-marketplace' );
			} else {
				$spot_state  = 'closed';
				$spot_status = __( 'Applications closed', 'lnkup-marketplace' );
			}
			$spot_cards[] = array(
				'id'        => (int) $slot->ID,
				'label'     => $labels ? implode( ', ', $labels ) : $slot->post_title,
				'fee'       => $fee > 0 ? sprintf( __( 'Vendor Fee: %s', 'lnkup-marketplace' ), function_exists( 'lnkup_public_money' ) ? lnkup_public_money( $fee ) : (string) $fee ) : __( 'Vendor Fee: Free', 'lnkup-marketplace' ),
				'state'     => $spot_state,
				'status'    => $spot_status,
				'deadline'  => $deadline ? sprintf( __( 'Apply by %s', 'lnkup-marketplace' ), date_i18n( 'M j, Y', strtotime( $deadline ) ) ) : '',
				'docs'      => LNKup_Organizer_Onboarding::slot_public_documents( (int) $slot->ID ),
				'can_apply' => $can_apply,
				'apply_url' => add_query_arg( 'apply', $slot->ID, $apply_base ),
			);
		}
		$selected_spot    = null;
		$selected_spot_id = 0;
		foreach ( $spot_cards as $spot ) {
			if ( ! empty( $spot['can_apply'] ) ) {
				$selected_spot    = $spot;
				$selected_spot_id = (int) $spot['id'];
				break;
			}
		}
		if ( ! $selected_spot && $spot_cards ) {
			$selected_spot    = $spot_cards[0];
			$selected_spot_id = (int) $spot_cards[0]['id'];
		}
		$selected_docs = ( $selected_spot && ! empty( $selected_spot['docs'] ) ) ? implode( ', ', $selected_spot['docs'] ) : '';
		$starting_fee = '';
		if ( null !== $open_fee ) {
			$starting_fee = (float) $open_fee > 0
				? sprintf( __( 'From %s', 'lnkup-marketplace' ), function_exists( 'lnkup_public_money' ) ? lnkup_public_money( $open_fee ) : (string) $open_fee )
				: __( 'Free', 'lnkup-marketplace' );
		}
		$hours_label = $start ? $start . ( $end ? ' – ' . $end : '' ) : '';
		$deadline_note = '';
		if ( $earliest_deadline && ! $unclaimed && $open_spots > 0 ) {
			$days_left = (int) floor( ( strtotime( $earliest_deadline ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
			if ( 0 === $days_left ) {
				$deadline_note = __( 'Applications close today', 'lnkup-marketplace' );
			} elseif ( $days_left > 0 && $days_left <= 30 ) {
				/* translators: %d: days until the application deadline */
				$deadline_note = sprintf( _n( 'Applications close in %d day', 'Applications close in %d days', $days_left, 'lnkup-marketplace' ), $days_left );
			}
		}
		$vendors_booked = $unclaimed ? 0 : self::public_vendors_booked( array( (int) $event->ID ) );
		$guest_gate     = '';
		if ( $guest && ! $unclaimed && $spot_cards ) {
			// Guests get one grouped section: vendor types and status, never fees, spaces or documents.
			$guest_types = array();
			foreach ( $spot_cards as $spot ) {
				foreach ( array_map( 'trim', explode( ',', (string) $spot['label'] ) ) as $type_label ) {
					if ( '' !== $type_label ) {
						$guest_types[ strtolower( $type_label ) ] = $type_label;
					}
				}
			}
			$guest_status   = $open_spots > 0 ? __( 'Vendor applications are currently open.', 'lnkup-marketplace' ) : (string) $availability['label'];
			$guest_gate     = self::render_guest_vendor_gate( $guest_status, array_values( $guest_types ), LNKup_Access_Policy::guest_cta( 'apply', LNKup_Organizer_Onboarding::event_public_url( (int) $event->ID ) ) );
			$starting_fee   = '';
			$open_spots     = 0;
			$vendors_booked = 0;
			$selected_spot  = null;
		}
		$directions_url = ( ! empty( $location['address'] ) && empty( $location['privacy'] ) )
			? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( trim( ( $location['venue'] ? $location['venue'] . ', ' : '' ) . $location['address'] ) )
			: '';
		$calendar_url = self::public_event_calendar_url( $display_title, $date, $start, $end, (string) ( $location['address'] ? $location['address'] : $location['where'] ), self::public_event_share_url( (int) $event->ID ) );
		$pending_requests = 0;
		if ( $is_owner ) {
			$pending_requests = count(
				get_posts(
					array(
						'post_type'      => 'lnkup_application',
						'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							array( 'key' => '_lnkup_event_id', 'value' => (int) $event->ID ),
							array( 'key' => '_lnkup_application_status', 'value' => array( 'submitted', 'under_review' ), 'compare' => 'IN' ),
						),
					)
				)
			);
		}
		$organizer_events = $organizer_id && ! $unclaimed ? count( get_posts( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => '_lnkup_organizer_user_id', 'meta_value' => $organizer_id ) ) ) : 0;
		$workspace_url    = add_query_arg( 'event', (int) $event->ID, $dash_url );
		ob_start();
		?>
		<div class="lnkup-mp lnkup-pub-storefront lnkup-public-event-page" data-testid="lnkup-public-event">
			<?php if ( $is_owner ) : ?>
				<p class="lnkup-public-ownerbar">
					<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" href="<?php echo esc_url( add_query_arg( array( 'event' => (int) $event->ID, 'edit' => 1 ), $dash_url ) ); ?>"><?php esc_html_e( 'Edit event', 'lnkup-marketplace' ); ?></a>
					<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" href="<?php echo esc_url( add_query_arg( 'tab', 'slots', $workspace_url ) ); ?>"><?php esc_html_e( 'Manage vendor slots', 'lnkup-marketplace' ); ?></a>
					<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" href="<?php echo esc_url( add_query_arg( 'tab', 'requests', $workspace_url ) ); ?>">
						<?php
						echo esc_html(
							$pending_requests
								/* translators: %d: pending vendor requests */
								? sprintf( _n( 'Review %d request', 'Review %d requests', $pending_requests, 'lnkup-marketplace' ), $pending_requests )
								: __( 'Vendor requests', 'lnkup-marketplace' )
						);
						?>
					</a>
				</p>
			<?php endif; ?>
			<p><a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( $feed_url ); ?>">&larr; <?php esc_html_e( 'Find events', 'lnkup-marketplace' ); ?></a></p>
			<div class="lnkup-public-event-layout">
				<div class="lnkup-public-event-main">
					<article class="lnkup-surface--primary lnkup-public-event lnkup-event-hero">
						<div class="lnkup-event-hero__media">
							<?php if ( $event_media && function_exists( 'lnkup_event_media_html' ) ) : ?>
								<?php
								$hero_media               = $event_media;
								$hero_media['medium_url'] = (string) $event_media['large_url'];
								echo lnkup_event_media_html( $hero_media, $display_title, array( 'eager' => true, 'sizes' => '(max-width: 900px) 100vw, 960px', 'fallback' => $event_media['uploaded'] ? LNKup_Event_Media::fallback( (int) $event->ID ) : array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							<?php elseif ( $image ) : ?>
								<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $display_title ); ?>" width="960" height="540" decoding="async" fetchpriority="high" />
							<?php else : ?>
								<div class="lnkup-event-hero__placeholder" aria-hidden="true">LNKup</div>
							<?php endif; ?>
						</div>
						<div class="lnkup-event-hero__copy">
							<p class="lnkup-public-event__state"><?php echo esc_html( $availability['label'] ); ?></p>
							<?php echo class_exists( 'LNKup_Event_Distribution' ) ? LNKup_Event_Distribution::hero_notices( (int) $event->ID ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in hero_notices(). ?>
							<h1 class="lnkup-type-h1"><?php echo esc_html( $display_title ); ?></h1>
							<?php if ( $organizer_label && $profile_url ) : ?>
								<p class="lnkup-event-hero__organizer"><a href="<?php echo esc_url( $profile_url ); ?>"><?php echo esc_html( $organizer_label ); ?></a></p>
							<?php elseif ( $organizer_label ) : ?>
								<p class="lnkup-event-hero__organizer"><?php echo esc_html( $organizer_label ); ?></p>
							<?php endif; ?>
							<ul class="lnkup-event-hero__facts">
								<?php if ( $date ) : ?><li><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $date ) ) ); ?></li><?php endif; ?>
								<?php if ( $hours_label ) : ?><li><?php echo esc_html( $hours_label ); ?></li><?php endif; ?>
								<?php if ( $location['where'] ) : ?><li><?php echo esc_html( $location['where'] ); ?></li><?php endif; ?>
								<?php if ( $type_names ) : ?><li><?php echo esc_html( implode( ' · ', $type_names ) ); ?></li><?php endif; ?>
								<?php if ( '' !== $attendance && '0' !== $attendance ) : ?><li title="<?php esc_attr_e( 'Estimate provided by the organizer; not verified by LNKup', 'lnkup-marketplace' ); ?>"><?php echo esc_html( sprintf( __( 'Expected attendance %s (organizer-provided)', 'lnkup-marketplace' ), $attendance ) ); ?></li><?php endif; ?>
							</ul>
							<?php if ( $starting_fee ) : ?><p class="lnkup-event-hero__price"><?php echo esc_html( $starting_fee ); ?></p><?php endif; ?>
							<?php if ( ! $unclaimed ) : ?>
								<p class="lnkup-event-hero__actions">
									<a class="lnkup-btn lnkup-btn--primary" href="#lnkup-vendor-options"><?php esc_html_e( 'Explore Vendor Spots', 'lnkup-marketplace' ); ?></a>
									<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-share-event" data-share-url="<?php echo esc_url( self::public_event_share_url( (int) $event->ID ) ); ?>"><?php esc_html_e( 'Share', 'lnkup-marketplace' ); ?></button>
									<?php if ( $can_save_event ) : ?>
										<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-save-event<?php echo $event_saved ? ' is-active' : ''; ?>" data-favorite-type="events" data-favorite-id="<?php echo esc_attr( (string) $event->ID ); ?>" aria-pressed="<?php echo $event_saved ? 'true' : 'false'; ?>"><?php echo esc_html( $event_saved ? __( 'Saved', 'lnkup-marketplace' ) : __( 'Save', 'lnkup-marketplace' ) ); ?></button>
									<?php endif; ?>
									<?php echo class_exists( 'LNKup_Event_Distribution' ) ? LNKup_Event_Distribution::hero_actions( (int) $event->ID, (int) $open_spots, ! empty( $guest ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in hero_actions(). ?>
								</p>
							<?php endif; ?>
							<?php if ( $ticketing ) : ?>
								<p><a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( $ticketing ); ?>" rel="noopener noreferrer"><?php esc_html_e( 'Event tickets', 'lnkup-marketplace' ); ?></a></p>
							<?php endif; ?>
						</div>
					</article>

					<section id="lnkup-vendor-options" class="lnkup-public-event__slots">
						<?php if ( '' !== $guest_gate ) : ?>
							<?php echo $guest_gate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_guest_vendor_gate(). ?>
						<?php else : ?>
						<h2 class="lnkup-type-h2"><?php esc_html_e( 'Available Vendor Spots', 'lnkup-marketplace' ); ?></h2>
						<p class="lnkup-muted"><?php esc_html_e( 'Explore available opportunities and apply to be part of this event.', 'lnkup-marketplace' ); ?></p>
						<?php if ( ! $unclaimed && $spot_cards ) : ?>
							<p class="lnkup-muted"><?php esc_html_e( 'Applying is free. Payment is required only after approval.', 'lnkup-marketplace' ); ?></p>
						<?php endif; ?>
						<?php if ( $unclaimed ) : ?>
							<p class="lnkup-muted"><?php esc_html_e( 'Vendor applications are not open on LNKup yet.', 'lnkup-marketplace' ); ?></p>
							<?php if ( $source_url ) : ?>
								<p><a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( $source_url ); ?>" rel="noopener noreferrer"><?php esc_html_e( 'View original listing', 'lnkup-marketplace' ); ?></a></p>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( ! $spot_cards ) : ?>
							<p class="lnkup-empty lnkup-public-event__empty"><?php esc_html_e( 'No vendor spots are listed yet.', 'lnkup-marketplace' ); ?></p>
						<?php else : ?>
							<?php if ( ! $unclaimed && $selected_spot ) : ?>
								<div class="lnkup-slot-selected" id="lnkup-slot-selected">
									<p class="lnkup-slot-selected__name" id="lnkup-slot-selected-name"><?php echo esc_html( $selected_spot['label'] ); ?></p>
									<p class="lnkup-slot-selected__fee" id="lnkup-slot-selected-fee"><?php echo esc_html( $selected_spot['fee'] ); ?></p>
									<p class="lnkup-slot-selected__status" id="lnkup-slot-selected-status"><?php echo esc_html( $selected_spot['status'] ); ?></p>
									<p class="lnkup-slot-selected__deadline" id="lnkup-slot-selected-deadline"<?php echo $selected_spot['deadline'] ? '' : ' hidden'; ?>><?php echo esc_html( $selected_spot['deadline'] ); ?></p>
									<p class="lnkup-slot-selected__docs" id="lnkup-slot-selected-docs"<?php echo $selected_docs ? '' : ' hidden'; ?>><?php echo esc_html( $selected_docs ); ?></p>
									<?php if ( ! empty( $selected_spot['can_apply'] ) ) : ?>
										<a class="lnkup-btn lnkup-btn--primary" id="lnkup-slot-selected-apply" href="<?php echo esc_url( $selected_spot['apply_url'] ); ?>"><?php esc_html_e( 'Apply', 'lnkup-marketplace' ); ?></a>
									<?php else : ?>
										<a class="lnkup-btn lnkup-btn--primary" id="lnkup-slot-selected-apply" hidden href="#lnkup-vendor-options"><?php esc_html_e( 'Apply', 'lnkup-marketplace' ); ?></a>
									<?php endif; ?>
								</div>
							<?php endif; ?>
							<ul class="lnkup-spot-grid">
								<?php foreach ( $spot_cards as $spot ) : ?>
									<?php $spot_docs = $spot['docs'] ? implode( ', ', $spot['docs'] ) : ''; ?>
									<li
										class="lnkup-spot-card lnkup-spot-card--<?php echo esc_attr( $spot['state'] ); ?><?php echo (int) $spot['id'] === $selected_spot_id ? ' is-selected' : ''; ?>"
										id="slot-<?php echo esc_attr( (string) $spot['id'] ); ?>"
										role="button"
										tabindex="0"
										aria-pressed="<?php echo (int) $spot['id'] === $selected_spot_id ? 'true' : 'false'; ?>"
										data-slot-label="<?php echo esc_attr( $spot['label'] ); ?>"
										data-slot-fee="<?php echo esc_attr( $spot['fee'] ); ?>"
										data-slot-status="<?php echo esc_attr( $spot['status'] ); ?>"
										data-slot-deadline="<?php echo esc_attr( $spot['deadline'] ); ?>"
										data-slot-docs="<?php echo esc_attr( $spot_docs ); ?>"
										data-slot-apply="<?php echo esc_url( $spot['apply_url'] ); ?>"
										data-slot-open="<?php echo $spot['can_apply'] ? '1' : '0'; ?>"
									>
										<p class="lnkup-spot-card__name"><?php echo esc_html( $spot['label'] ); ?></p>
										<p class="lnkup-spot-card__fee"><?php echo esc_html( $spot['fee'] ); ?></p>
										<p class="lnkup-spot-card__status"><?php echo esc_html( $spot['status'] ); ?></p>
										<?php if ( $spot['deadline'] ) : ?><p class="lnkup-muted"><?php echo esc_html( $spot['deadline'] ); ?></p><?php endif; ?>
										<?php if ( $spot_docs ) : ?><p class="lnkup-muted"><?php echo esc_html( $spot_docs ); ?></p><?php endif; ?>
										<?php if ( $spot['can_apply'] ) : ?>
											<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( $spot['apply_url'] ); ?>"><?php esc_html_e( 'Apply', 'lnkup-marketplace' ); ?></a>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php endif; ?>
					</section>

					<section class="lnkup-surface--primary lnkup-public-event__about">
						<h2 class="lnkup-type-h2"><?php esc_html_e( 'About the Event', 'lnkup-marketplace' ); ?></h2>
						<?php if ( $show_about ) : ?>
							<div class="lnkup-public-event__prose"><?php echo wp_kses_post( wpautop( $event->post_content ) ); ?></div>
						<?php else : ?>
							<p class="lnkup-empty lnkup-public-event__empty"><?php esc_html_e( 'The organizer has not added a description yet.', 'lnkup-marketplace' ); ?></p>
						<?php endif; ?>
						<?php if ( $gallery ) : ?>
							<ul class="lnkup-public-event__gallery">
								<?php foreach ( $gallery as $gallery_index => $gallery_url ) : ?>
									<li><img src="<?php echo esc_url( $gallery_url ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: 1: event title, 2: photo number */ __( '%1$s photo %2$d', 'lnkup-marketplace' ), $display_title, (int) $gallery_index + 1 ) ); ?>" width="320" height="180" loading="lazy" decoding="async" /></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</section>

					<section class="lnkup-surface--primary lnkup-event-facts">
						<h2 class="lnkup-type-h2"><?php esc_html_e( 'Event Information', 'lnkup-marketplace' ); ?></h2>
						<dl class="lnkup-event-facts__grid">
							<?php if ( $date ) : ?><div><dt><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $date ) ) ); ?></dd></div><?php endif; ?>
							<?php if ( $hours_label ) : ?><div><dt><?php esc_html_e( 'Hours', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( $hours_label ); ?></dd></div><?php endif; ?>
							<?php if ( $location['where'] ) : ?><div><dt><?php esc_html_e( 'Location', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( $location['where'] ); ?></dd></div><?php endif; ?>
							<?php if ( $location['venue'] ) : ?><div><dt><?php esc_html_e( 'Venue', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( $location['venue'] ); ?><?php if ( $location['address'] ) : ?><br /><?php echo esc_html( $location['address'] ); ?><?php endif; ?></dd></div><?php endif; ?>
							<?php if ( $location['privacy'] ) : ?><div><dt><?php esc_html_e( 'Venue', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( $location['privacy'] ); ?></dd></div><?php endif; ?>
							<?php if ( '' !== $attendance && '0' !== $attendance ) : ?><div><dt><?php esc_html_e( 'Expected attendance', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( $attendance ); ?></dd></div><?php endif; ?>
							<?php if ( $type_names ) : ?><div><dt><?php esc_html_e( 'Event type', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( implode( ' · ', $type_names ) ); ?></dd></div><?php endif; ?>
							<?php if ( $setting ) : ?><div><dt><?php esc_html_e( 'Setting', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( $setting ); ?></dd></div><?php endif; ?>
							<?php if ( $earliest_deadline ) : ?><div><dt><?php esc_html_e( 'Application deadline', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $earliest_deadline ) ) ); ?></dd></div><?php endif; ?>
							<?php if ( $amenities ) : ?><div><dt><?php esc_html_e( 'Setup', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( implode( ', ', $amenities ) ); ?></dd></div><?php endif; ?>
						</dl>
						<?php if ( $directions_url || $calendar_url ) : ?>
							<p class="lnkup-event-facts__links">
								<?php if ( $directions_url ) : ?>
									<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( $directions_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get directions', 'lnkup-marketplace' ); ?></a>
								<?php endif; ?>
								<?php if ( $calendar_url ) : ?>
									<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( $calendar_url ); ?>" target="_blank" rel="noopener noreferrer" data-testid="lnkup-public-event-calendar"><?php esc_html_e( 'Add to calendar', 'lnkup-marketplace' ); ?></a>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					</section>

					<?php if ( $organizer && ! $unclaimed ) : ?>
						<section class="lnkup-surface--primary lnkup-org-preview">
							<h2 class="lnkup-type-h2"><?php esc_html_e( 'Organizer', 'lnkup-marketplace' ); ?></h2>
							<div class="lnkup-org-preview__identity">
								<span class="lnkup-org-preview__logo">
									<?php if ( $organizer_logo ) : ?>
										<img src="<?php echo esc_url( $organizer_logo ); ?>" alt="" />
									<?php else : ?>
										<?php echo esc_html( strtoupper( substr( $organizer_label, 0, 1 ) ) ); ?>
									<?php endif; ?>
								</span>
								<div>
									<p class="lnkup-org-preview__name">
										<?php echo esc_html( $organizer_label ); ?>
										<?php if ( $verified ) : ?><span class="lnkup-public-verified"><?php esc_html_e( 'Verified', 'lnkup-marketplace' ); ?></span><?php endif; ?>
									</p>
									<?php if ( $organizer_where ) : ?><p class="lnkup-muted"><?php echo esc_html( $organizer_where ); ?></p><?php endif; ?>
									<?php if ( $organizer_service ) : ?><p class="lnkup-muted"><?php echo esc_html( $organizer_service ); ?></p><?php endif; ?>
									<?php if ( $organizer_events > 0 ) : ?>
										<p class="lnkup-muted"><?php echo esc_html( sprintf( /* translators: %d: events hosted */ _n( 'Hosted %d event on LNKup', 'Hosted %d events on LNKup', $organizer_events, 'lnkup-marketplace' ), $organizer_events ) ); ?></p>
									<?php endif; ?>
								</div>
							</div>
							<?php if ( $organizer_tagline ) : ?><p><?php echo esc_html( $organizer_tagline ); ?></p><?php endif; ?>
							<?php if ( $organizer_bio ) : ?><p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $organizer_bio ), 40 ) ); ?></p><?php endif; ?>
							<p class="lnkup-org-preview__actions">
								<?php if ( $profile_url ) : ?>
									<a class="lnkup-btn lnkup-btn--outline" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'View Profile', 'lnkup-marketplace' ); ?></a>
								<?php endif; ?>
								<?php echo self::public_follow_control( $organizer_id, $profile_url ? $profile_url : LNKup_Organizer_Onboarding::event_public_url( (int) $event->ID ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</p>
						</section>
						<?php echo self::render_other_organizer_events( $organizer_id, (int) $event->ID, $organizer_label, $profile_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
					<?php echo self::render_related_market_events( (int) $event->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<?php if ( ! $unclaimed ) : ?>
					<?php
					$vendor_cta = ( ! $guest && class_exists( 'LNKup_Vendor_Presentation' ) && class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_vendor() )
						? LNKup_Vendor_Presentation::event_cta( (int) $event->ID )
						: array();
					?>
					<aside class="lnkup-public-event-summary vos-action-column" data-testid="lnkup-event-action-column"<?php echo ! empty( $vendor_cta['state'] ) ? ' data-vendor-state="' . esc_attr( $vendor_cta['state'] ) . '"' : ''; ?>>
						<p class="lnkup-public-event__state"><?php echo esc_html( $availability['label'] ); ?></p>
						<?php if ( $starting_fee ) : ?><p class="lnkup-event-hero__price"><?php echo esc_html( $starting_fee ); ?><span class="vos-action-column__unit"><?php esc_html_e( 'booth fee', 'lnkup-marketplace' ); ?></span></p><?php endif; ?>
						<ul class="vos-action-column__facts">
							<?php if ( $date ) : ?><li><?php echo esc_html( date_i18n( 'D, M j, Y', strtotime( $date ) ) ); ?></li><?php endif; ?>
							<?php if ( $location['where'] ) : ?><li><?php echo esc_html( $location['where'] ); ?></li><?php endif; ?>
							<?php if ( $open_spots > 0 ) : ?><li><?php echo esc_html( sprintf( _n( '%d spot available', '%d spots available', $open_spots, 'lnkup-marketplace' ), $open_spots ) ); ?></li><?php endif; ?>
							<?php if ( $earliest_deadline ) : ?><li><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Applications close %s', 'lnkup-marketplace' ), date_i18n( 'M j, Y', strtotime( $earliest_deadline ) ) ) ); ?></li><?php endif; ?>
						</ul>
						<?php if ( $deadline_note ) : ?><p class="lnkup-public-event__urgency" data-testid="lnkup-public-event-deadline"><?php echo esc_html( $deadline_note ); ?></p><?php endif; ?>
						<?php if ( $vendors_booked > 0 ) : ?>
							<p class="lnkup-muted"><?php echo esc_html( sprintf( /* translators: %d: vendors booked */ _n( '%d vendor already booked', '%d vendors already booked', $vendors_booked, 'lnkup-marketplace' ), $vendors_booked ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $vendor_cta['label'] ) ) : ?>
							<p class="vos-action-column__note"><?php echo esc_html( (string) $vendor_cta['note'] ); ?></p>
							<p><a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( (string) $vendor_cta['href'] ); ?>"><?php echo esc_html( (string) $vendor_cta['label'] ); ?></a></p>
						<?php elseif ( $open_spots > 0 || $guest ) : ?>
							<p><a class="lnkup-btn lnkup-btn--primary" href="#lnkup-vendor-options"><?php esc_html_e( 'Choose a booth and apply', 'lnkup-marketplace' ); ?></a></p>
							<p class="lnkup-muted"><?php esc_html_e( 'Applying is free. Payment is required only after approval.', 'lnkup-marketplace' ); ?></p>
						<?php else : ?>
							<p><span class="lnkup-btn lnkup-btn--outline" aria-disabled="true"><?php esc_html_e( 'Applications closed', 'lnkup-marketplace' ); ?></span></p>
						<?php endif; ?>
					</aside>
				<?php endif; ?>
			</div>
			<?php if ( ! $unclaimed ) : ?>
				<div class="lnkup-public-event-bar" id="lnkup-public-event-bar" data-label-apply="<?php esc_attr_e( 'Apply', 'lnkup-marketplace' ); ?>" data-label-spots="<?php esc_attr_e( 'View spots', 'lnkup-marketplace' ); ?>">
					<p class="lnkup-public-event-bar__slot" id="lnkup-public-event-bar-slot"><?php
					if ( $selected_spot ) {
						echo esc_html( $selected_spot['label'] . ( $selected_spot['fee'] ? ' · ' . $selected_spot['fee'] : '' ) );
					}
					?></p>
					<?php if ( $deadline_note ) : ?><p class="lnkup-public-event__urgency lnkup-public-event-bar__urgency"><?php echo esc_html( $deadline_note ); ?></p><?php endif; ?>
					<?php if ( $selected_spot && ! empty( $selected_spot['can_apply'] ) ) : ?>
						<a class="lnkup-btn lnkup-btn--primary" id="lnkup-public-event-bar-apply" href="<?php echo esc_url( $selected_spot['apply_url'] ); ?>"><?php esc_html_e( 'Apply', 'lnkup-marketplace' ); ?></a>
					<?php else : ?>
						<a class="lnkup-btn lnkup-btn--primary" id="lnkup-public-event-bar-apply" href="#lnkup-vendor-options"><?php esc_html_e( 'View spots', 'lnkup-marketplace' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Guest "Vendor Opportunities" section: what kinds of vendors the event wants and whether it
	 * is accepting them, with one sign-in CTA in place of pricing, spaces and requirements.
	 *
	 * @param string             $status     Status sentence.
	 * @param array<int, string> $types      Vendor type labels.
	 * @param array<string, string> $cta     LNKup_Access_Policy::guest_cta().
	 */
	private static function render_guest_vendor_gate( $status, array $types, array $cta ) {
		ob_start();
		?>
		<div class="lnkup-guest-gate" data-testid="lnkup-guest-gate" data-lnkup-gate="event">
			<h2 class="lnkup-type-h2 lnkup-guest-gate__title">
				<svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="10.5" width="14" height="10" rx="2.5"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/></svg>
				<?php esc_html_e( 'Vendor Opportunities', 'lnkup-marketplace' ); ?>
			</h2>
			<p class="lnkup-guest-gate__status"><?php echo esc_html( $status ); ?></p>
			<?php if ( $types ) : ?>
				<ul class="lnkup-guest-gate__types" aria-label="<?php esc_attr_e( 'Vendor types wanted', 'lnkup-marketplace' ); ?>">
					<?php foreach ( array_slice( $types, 0, 8 ) as $type_label ) : ?>
						<li class="lnkup-chip"><?php echo esc_html( $type_label ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="lnkup-muted"><?php echo esc_html( $cta['message'] ); ?></p>
			<p class="lnkup-guest-gate__actions">
				<a class="lnkup-btn lnkup-btn--primary" data-testid="lnkup-guest-gate-signin" data-lnkup-gate-action="sign_in" href="<?php echo esc_url( $cta['sign_in_url'] ); ?>"><?php esc_html_e( 'Sign In to View Opportunities', 'lnkup-marketplace' ); ?></a>
				<a class="lnkup-btn lnkup-btn--outline" data-testid="lnkup-guest-gate-signup" data-lnkup-gate-action="sign_up" href="<?php echo esc_url( $cta['sign_up_url'] ); ?>"><?php echo esc_html( $cta['sign_up_label'] ); ?></a>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Other upcoming events from the same organizer, excluding the current one.
	 *
	 * @param int    $organizer_id Organizer user ID.
	 * @param int    $current_id   Event to skip.
	 * @param string $label        Organizer name.
	 */
	private static function render_other_organizer_events( $organizer_id, $current_id, $label, $profile_url = '' ) {
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
			)
		);
		$items = array();
		$today = current_time( 'Y-m-d' );
		foreach ( $events as $other ) {
			if ( (int) $other->ID === (int) $current_id ) {
				continue;
			}
			$other_date = (string) get_post_meta( $other->ID, '_lnkup_event_date', true );
			if ( $other_date && $other_date < $today ) {
				continue;
			}
			$where = '';
			if ( class_exists( 'LNKup_Organizer_Onboarding' ) ) {
				$place = LNKup_Organizer_Onboarding::event_public_location( (int) $other->ID );
				$where = (string) ( $place['where'] ?? '' );
			}
			$items[] = array(
				'post'  => $other,
				'date'  => $other_date,
				'where' => $where,
			);
		}
		if ( ! $items ) {
			return '';
		}
		usort(
			$items,
			static function ( $a, $b ) {
				$a_date = '' !== $a['date'] ? $a['date'] : '9999-99-99';
				$b_date = '' !== $b['date'] ? $b['date'] : '9999-99-99';
				return strcmp( $a_date, $b_date );
			}
		);
		$items = array_slice( $items, 0, 6 );
		ob_start();
		?>
		<section class="lnkup-public-event__more-events">
			<div class="lnkup-section-head">
				<h2 class="lnkup-type-h2"><?php echo esc_html( sprintf( __( 'More events from %s', 'lnkup-marketplace' ), $label ) ); ?></h2>
				<?php if ( $profile_url ) : ?>
					<a href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'View all events', 'lnkup-marketplace' ); ?></a>
				<?php endif; ?>
			</div>
			<div class="lnkup-event-grid lnkup-event-grid--public">
				<?php foreach ( $items as $item ) : ?>
					<?php echo lnkup_render_homepage_event_card( self::public_event_card_rows( $item['post'] ), 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Up to three other events in the same stored city.
	 *
	 * @param int $event_id Current event ID.
	 */
	private static function render_related_market_events( $event_id ) {
		$city = (string) get_post_meta( (int) $event_id, '_lnkup_city', true );
		if ( '' === $city || ! function_exists( 'lnkup_render_homepage_event_card' ) ) {
			return '';
		}
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => 'publish',
				'posts_per_page' => 3,
				'post__not_in'   => array( (int) $event_id ),
				'meta_key'       => '_lnkup_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_lnkup_city',
						'value' => $city,
					),
					array(
						'key'     => '_lnkup_event_date',
						'value'   => current_time( 'Y-m-d' ),
						'compare' => '>=',
						'type'    => 'DATE',
					),
				),
			)
		);
		if ( ! $posts ) {
			return '';
		}
		ob_start();
		?>
		<section class="lnkup-public-event__related">
			<h2 class="lnkup-type-h2"><?php esc_html_e( 'More events in this city', 'lnkup-marketplace' ); ?></h2>
			<div class="lnkup-event-grid lnkup-event-grid--public">
				<?php foreach ( $posts as $related ) : ?>
					<?php
					$state = (string) get_post_meta( $related->ID, '_lnkup_state', true );
					$date  = (string) get_post_meta( $related->ID, '_lnkup_event_date', true );
					$rows  = array();
					if ( class_exists( 'LNKup_Organizer_Onboarding' ) ) {
						foreach ( LNKup_Organizer_Onboarding::event_slot_posts( (int) $related->ID ) as $slot ) {
							$row = self::public_slot_card_row( $slot, (int) $related->ID, $city, $state, $date );
							if ( null !== $row ) {
								$rows[] = $row;
							}
						}
					}
					if ( ! $rows ) {
						$rows[] = array(
							'event_id'        => (int) $related->ID,
							'city'            => $city,
							'state'           => $state,
							'date'            => $date,
							'spots_remaining' => 0,
							'is_open'         => false,
							'categories'      => array(),
							'badges'          => array(),
						);
					}
					echo lnkup_render_homepage_event_card( $rows, 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Tour/series header on Find Events (?tour_id=): stops, per-stop availability and matching stops.
	 *
	 * @param int $tour_id Tour ID.
	 */
	private static function render_tour_panel( $tour_id ) {
		if ( ! $tour_id || ! class_exists( 'LNKup_Tours' ) || ! LNKup_Match_Settings::enabled( 'matching_tours_enabled' ) ) {
			return '';
		}
		$tour = LNKup_Tours::detail( $tour_id, get_current_user_id() );
		if ( is_wp_error( $tour ) ) {
			return '';
		}
		$multi_apply = class_exists( 'LNKup_Application_Groups' ) && LNKup_Application_Groups::enabled();
		ob_start();
		?>
		<section class="lnkup-card lnkup-tour-panel" data-testid="lnkup-tour-panel" aria-labelledby="lnkup-tour-title">
			<input type="hidden" data-filter="tour_id" value="<?php echo esc_attr( (string) $tour_id ); ?>" />
			<p class="lnkup-badge lnkup-badge--outline"><?php echo esc_html( 'series' === $tour['type'] ? __( 'Recurring market', 'lnkup-marketplace' ) : __( 'Tour', 'lnkup-marketplace' ) ); ?></p>
			<h2 id="lnkup-tour-title"><?php echo esc_html( $tour['title'] ); ?></h2>
			<?php if ( $tour['organizer_name'] ) : ?>
				<p class="lnkup-muted"><?php echo esc_html( sprintf( __( 'by %s', 'lnkup-marketplace' ), $tour['organizer_name'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( $tour['matching_stops'] ) : ?>
				<p class="lnkup-match-reason"><?php echo esc_html( sprintf( _n( '%d stop matches your markets', '%d stops match your markets', $tour['matching_stops'], 'lnkup-marketplace' ), $tour['matching_stops'] ) ); ?></p>
			<?php endif; ?>
			<p class="lnkup-muted"><?php esc_html_e( 'Each stop has its own application, booth inventory and payment. Applying to one stop does not apply you to the others.', 'lnkup-marketplace' ); ?></p>
			<ol class="lnkup-tour-stops">
				<?php foreach ( $tour['stops'] as $stop ) : ?>
					<li class="lnkup-tour-stop<?php echo $stop['matches_me'] ? ' is-match' : ''; ?>" data-testid="lnkup-tour-stop" data-event-id="<?php echo esc_attr( (string) $stop['event_id'] ); ?>">
						<strong><?php echo esc_html( trim( $stop['city'] . ', ' . $stop['state'], ', ' ) ?: $stop['title'] ); ?></strong>
						<span class="lnkup-muted"><?php echo esc_html( $stop['date'] ? date_i18n( 'M j, Y', strtotime( $stop['date'] ) ) : __( 'Date TBA', 'lnkup-marketplace' ) ); ?></span>
						<?php if ( null !== $stop['fee_min'] ) : ?>
							<span><?php echo esc_html( $stop['fee_min'] === $stop['fee_max'] ? lnkup_currency( $stop['fee_min'] ) : lnkup_currency( $stop['fee_min'] ) . '–' . lnkup_currency( $stop['fee_max'] ) ); ?></span>
						<?php endif; ?>
						<span class="lnkup-badge <?php echo $stop['accepting'] ? 'lnkup-badge--success' : 'lnkup-badge--muted'; ?>"><?php echo esc_html( $stop['accepting'] ? __( 'Accepting vendors', 'lnkup-marketplace' ) : __( 'Not accepting', 'lnkup-marketplace' ) ); ?></span>
						<?php if ( $stop['matches_me'] ) : ?>
							<span class="lnkup-badge lnkup-badge--outline"><?php esc_html_e( 'Matches you', 'lnkup-marketplace' ); ?></span>
						<?php endif; ?>
						<?php if ( ! $multi_apply ) : ?>
							<?php foreach ( (array) $stop['opportunities'] as $stop_opp ) : ?>
								<?php if ( ! empty( $stop_opp['is_open'] ) && ( '' === (string) ( $stop_opp['application_status'] ?? '' ) || ! empty( $stop_opp['can_apply_again'] ) ) ) : ?>
									<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-testid="lnkup-tour-stop-apply" href="<?php echo esc_url( add_query_arg( array( 'tour_id' => $tour_id, 'apply' => (int) $stop_opp['opportunity_id'] ), lnkup_page_url_helper( 'opportunities' ) ) ); ?>">
										<?php echo esc_html( count( $stop['opportunities'] ) > 1 ? sprintf( __( 'Apply: %s', 'lnkup-marketplace' ), $stop_opp['title'] ) : __( 'Apply to this stop', 'lnkup-marketplace' ) ); ?>
									</a>
								<?php elseif ( '' !== (string) ( $stop_opp['application_status'] ?? '' ) ) : ?>
									<span class="lnkup-status lnkup-status--<?php echo esc_attr( (string) $stop_opp['application_status'] ); ?>"><?php echo esc_html( lnkup_application_status_label( (string) $stop_opp['application_status'] ) ); ?></span>
								<?php endif; ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
			<?php if ( class_exists( 'LNKup_Application_Groups' ) && LNKup_Application_Groups::enabled() ) : ?>
				<?php if ( is_user_logged_in() && LNKup_Roles::is_vendor( get_current_user_id() ) ) : ?>
					<button type="button" class="lnkup-btn lnkup-btn--primary" data-tour-apply-open="<?php echo esc_attr( (string) $tour_id ); ?>" aria-controls="lnkup-tour-apply-<?php echo esc_attr( (string) $tour_id ); ?>" aria-expanded="false" data-testid="lnkup-tour-apply-open">
						<?php echo esc_html( 'series' === $tour['type'] ? __( 'Apply to several dates', 'lnkup-marketplace' ) : __( 'Apply to several stops', 'lnkup-marketplace' ) ); ?>
					</button>
					<div class="lnkup-tour-apply" id="lnkup-tour-apply-<?php echo esc_attr( (string) $tour_id ); ?>" data-tour-apply="<?php echo esc_attr( (string) $tour_id ); ?>" data-tours-rest-url="<?php echo esc_url( rest_url( 'lnkup/v1/tours' ) ); ?>" hidden></div>
				<?php elseif ( ! is_user_logged_in() ) : ?>
					<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( lnkup_login_url_for( add_query_arg( 'tour_id', $tour_id, lnkup_page_url_helper( 'opportunities' ) ) ) ); ?>"><?php esc_html_e( 'Log in to apply to several stops', 'lnkup-marketplace' ); ?></a>
				<?php endif; ?>
			<?php endif; ?>
			<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="<?php echo esc_url( lnkup_page_url_helper( 'opportunities' ) ); ?>"><?php esc_html_e( 'View all events', 'lnkup-marketplace' ); ?></a>
		</section>
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
		$public_event = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $public_event > 0 ) {
			return self::render_public_event( $public_event );
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
			<?php echo self::render_tour_panel( (int) ( $filter_args['tour_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<header class="lnkup-mp-v2__hero lnkup-page-header">
				<?php if ( is_user_logged_in() && class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_vendor() ) : ?>
					<?php
					echo lnkup_dash_breadcrumb(
						array(
							array(
								'label' => __( 'Home', 'lnkup-marketplace' ),
								'href'  => lnkup_page_url_helper( 'vendor-dashboard' ),
							),
							array(
								'label' => __( 'Explore', 'lnkup-marketplace' ),
							),
						)
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				<?php endif; ?>
				<div class="lnkup-mp-v2__hero-text lnkup-page-header__main">
					<h1 class="lnkup-page-header__title"><?php esc_html_e( 'Find your next vendor opportunity', 'lnkup-marketplace' ); ?></h1>
					<p class="lnkup-page-header__subtitle"><?php esc_html_e( 'Festivals, markets, pop-ups, and events looking for businesses like yours.', 'lnkup-marketplace' ); ?></p>
				</div>
				<p class="lnkup-muted lnkup-mp-v2__count" id="lnkup-result-count"><?php echo esc_html( sprintf( __( '%d opportunities', 'lnkup-marketplace' ), (int) $initial['total'] ) ); ?></p>
			</header>

			<div class="lnkup-mp-v2__filter-bar lnkup-mp-v2__filter-bar--sticky lnkup-surface--secondary lnkup-filter-bar--sticky">
				<form class="lnkup-mp-v2__search-bar" id="lnkup-search-form" role="search">
					<div class="lnkup-mp-v2__search-wrap">
						<input type="search" id="lnkup-search-input" name="q" class="lnkup-mp-v2__search" placeholder="<?php esc_attr_e( 'Search events', 'lnkup-marketplace' ); ?>" aria-label="<?php esc_attr_e( 'Search events by name, type, or keyword', 'lnkup-marketplace' ); ?>" autocomplete="off" value="<?php echo esc_attr( $search_q ); ?>" />
						<?php if ( $phase_1b ) : ?>
						<div class="lnkup-mp-v2__suggestions" id="lnkup-suggestions" hidden></div>
						<?php endif; ?>
					</div>
					<label class="screen-reader-text" for="lnkup-find-city"><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></label>
					<input type="text" id="lnkup-find-city" class="lnkup-find-city" name="city" data-filter="city" placeholder="<?php esc_attr_e( 'All cities', 'lnkup-marketplace' ); ?>" value="<?php echo esc_attr( $filter_args['city'] ?? '' ); ?>" />
					<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-mp-v2__search-btn" id="lnkup-search-submit"><?php esc_html_e( 'Search', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-mp-v2__lnkup-only" id="lnkup-only-toggle" aria-pressed="false"><?php esc_html_e( 'LNKup Opportunities Only', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-mp-v2__save-search" id="lnkup-save-search"><?php esc_html_e( 'Save this search', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-mp-v2__filter-toggle" id="lnkup-filter-toggle" aria-expanded="false"><?php esc_html_e( 'Filters', 'lnkup-marketplace' ); ?></button>
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
					<select id="lnkup-filter-date" class="lnkup-mp-v2__sort" data-filter="date_preset" aria-label="<?php esc_attr_e( 'Date', 'lnkup-marketplace' ); ?>">
						<option value=""><?php esc_html_e( 'Any date', 'lnkup-marketplace' ); ?></option>
						<option value="today" <?php selected( $filter_args['date_preset'] ?? '', 'today' ); ?>><?php esc_html_e( 'Today', 'lnkup-marketplace' ); ?></option>
						<option value="tomorrow" <?php selected( $filter_args['date_preset'] ?? '', 'tomorrow' ); ?>><?php esc_html_e( 'Tomorrow', 'lnkup-marketplace' ); ?></option>
						<option value="weekend" <?php selected( $filter_args['date_preset'] ?? '', 'weekend' ); ?>><?php esc_html_e( 'This Weekend', 'lnkup-marketplace' ); ?></option>
						<option value="this_week" <?php selected( $filter_args['date_preset'] ?? '', 'this_week' ); ?>><?php esc_html_e( 'This Week', 'lnkup-marketplace' ); ?></option>
						<option value="this_month" <?php selected( $filter_args['date_preset'] ?? '', 'this_month' ); ?>><?php esc_html_e( 'This Month', 'lnkup-marketplace' ); ?></option>
						<option value="next_30" <?php selected( $filter_args['date_preset'] ?? '', 'next_30' ); ?>><?php esc_html_e( 'Next 30 Days', 'lnkup-marketplace' ); ?></option>
					</select>
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
					<button type="button" class="lnkup-badge lnkup-badge--outline lnkup-active-filter-chip" data-filter-key="<?php echo esc_attr( $chip['key'] ); ?>" data-filter-value="<?php echo esc_attr( $chip['value'] ?? '' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: filter label */ __( 'Remove filter: %s', 'lnkup-marketplace' ), $chip['label'] ) ); ?>"><?php echo esc_html( $chip['label'] ); ?> <span aria-hidden="true">×</span></button>
				<?php endforeach; ?>
				<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" id="lnkup-clear-filters-inline"><?php esc_html_e( 'Clear all', 'lnkup-marketplace' ); ?></button>
			</div>

			<aside class="lnkup-mp-v2__filter-drawer lnkup-mp-v2__filter-drawer--closed" id="lnkup-filter-drawer">
				<div class="lnkup-mp-v2__filter-inner">
					<h3><?php esc_html_e( 'Filters', 'lnkup-marketplace' ); ?></h3>
					<section class="lnkup-filter-section">
						<h4><?php esc_html_e( 'Where', 'lnkup-marketplace' ); ?></h4>
						<div class="lnkup-filter-fields">
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
						</div>
					</section>
					<section class="lnkup-filter-section">
						<h4><?php esc_html_e( 'When', 'lnkup-marketplace' ); ?></h4>
						<div class="lnkup-filter-fields">
							<label><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?>
								<select data-sync="date_preset" aria-label="<?php esc_attr_e( 'Date', 'lnkup-marketplace' ); ?>">
									<option value=""><?php esc_html_e( 'Any time', 'lnkup-marketplace' ); ?></option>
									<option value="today"><?php esc_html_e( 'Today', 'lnkup-marketplace' ); ?></option>
									<option value="tomorrow"><?php esc_html_e( 'Tomorrow', 'lnkup-marketplace' ); ?></option>
									<option value="weekend"><?php esc_html_e( 'This Weekend', 'lnkup-marketplace' ); ?></option>
									<option value="this_week"><?php esc_html_e( 'This Week', 'lnkup-marketplace' ); ?></option>
									<option value="this_month"><?php esc_html_e( 'This Month', 'lnkup-marketplace' ); ?></option>
									<option value="next_30"><?php esc_html_e( 'Next 30 Days', 'lnkup-marketplace' ); ?></option>
								</select>
							</label>
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
							<label><?php esc_html_e( 'Custom start', 'lnkup-marketplace' ); ?><input type="date" data-filter="date_from" value="<?php echo esc_attr( $filter_args['date_from'] ?? '' ); ?>" /></label>
							<label><?php esc_html_e( 'Custom end', 'lnkup-marketplace' ); ?><input type="date" data-filter="date_to" value="<?php echo esc_attr( $filter_args['date_to'] ?? '' ); ?>" /></label>
						</div>
					</section>
					<section class="lnkup-filter-section">
						<h4><?php esc_html_e( 'Fee', 'lnkup-marketplace' ); ?></h4>
						<div class="lnkup-filter-fields">
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
						</div>
					</section>
					<section class="lnkup-filter-section">
						<h4><?php esc_html_e( 'Documents', 'lnkup-marketplace' ); ?></h4>
						<div class="lnkup-filter-checks">
							<label><input type="checkbox" data-filter-doc="coi" /> <?php esc_html_e( 'COI Required', 'lnkup-marketplace' ); ?></label>
							<label><input type="checkbox" data-filter-doc="health_permit" /> <?php esc_html_e( 'Health Permit', 'lnkup-marketplace' ); ?></label>
							<label><input type="checkbox" data-filter-doc="business_license" /> <?php esc_html_e( 'Business License', 'lnkup-marketplace' ); ?></label>
							<label><input type="checkbox" data-filter-doc="w9" /> <?php esc_html_e( 'W9 Required', 'lnkup-marketplace' ); ?></label>
							<label><input type="checkbox" data-filter="doc_none" /> <?php esc_html_e( 'No Documents Required', 'lnkup-marketplace' ); ?></label>
						</div>
					</section>
					<section class="lnkup-filter-section">
						<h4><?php esc_html_e( 'More', 'lnkup-marketplace' ); ?></h4>
						<div class="lnkup-filter-checks">
							<label><input type="checkbox" data-filter="verified_organizer" /> <?php esc_html_e( 'Verified Organizer', 'lnkup-marketplace' ); ?></label>
							<label><input type="checkbox" data-filter="featured_organizer" /> <?php esc_html_e( 'Featured Organizer', 'lnkup-marketplace' ); ?></label>
							<label><input type="checkbox" data-filter="instant_approval" /> <?php esc_html_e( 'Instant Approval', 'lnkup-marketplace' ); ?></label>
							<label><input type="checkbox" data-filter="has_spots" checked /> <?php esc_html_e( 'Has Spots', 'lnkup-marketplace' ); ?></label>
							<label><input type="checkbox" data-filter="newly_added" /> <?php esc_html_e( 'Newly Added', 'lnkup-marketplace' ); ?></label>
							<label><input type="checkbox" data-filter="closing_soon" /> <?php esc_html_e( 'Closing Soon', 'lnkup-marketplace' ); ?></label>
							<label><input type="checkbox" data-filter="almost_full" /> <?php esc_html_e( 'Almost Full', 'lnkup-marketplace' ); ?></label>
							<label><input type="checkbox" data-filter="show_closed" /> <?php esc_html_e( 'Show closed opportunities', 'lnkup-marketplace' ); ?></label>
						</div>
					<?php if ( $event_type_tree ) : ?>
						<fieldset class="lnkup-filter-checks">
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
						<fieldset class="lnkup-filter-checks">
							<legend><?php esc_html_e( 'Vendor Categories', 'lnkup-marketplace' ); ?></legend>
							<?php foreach ( lnkup_canonical_category_options( $categories ) as $option ) : ?>
								<label><input type="checkbox" data-filter-cat="<?php echo esc_attr( $option['label'] ); ?>" data-filter-cat-terms="<?php echo esc_attr( implode( ',', $option['terms'] ) ); ?>" /> <?php echo esc_html( $option['label'] ); ?></label>
							<?php endforeach; ?>
						</fieldset>
					<?php endif; ?>
					<?php if ( class_exists( 'LNKup_External_Events' ) ) : ?>
					<fieldset class="lnkup-filter-checks">
						<legend><?php esc_html_e( 'External listings', 'lnkup-marketplace' ); ?></legend>
						<label><input type="checkbox" data-filter="include_external" <?php checked( ! empty( $filter_args['include_external'] ) ); ?> /> <?php esc_html_e( 'Include external events', 'lnkup-marketplace' ); ?></label>
						<label><input type="checkbox" data-filter="lnkup_only" /> <?php esc_html_e( 'LNKup listings only', 'lnkup-marketplace' ); ?></label>
						<label><input type="checkbox" data-filter="high_demand" /> <?php esc_html_e( 'High demand external only', 'lnkup-marketplace' ); ?></label>
					</fieldset>
					<?php endif; ?>
					</section>
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
						<a href="<?php echo esc_url( lnkup_registration_url() ); ?>" class="lnkup-btn lnkup-btn--primary" id="lnkup-save-search-signup"><?php esc_html_e( 'Create vendor profile', 'lnkup-marketplace' ); ?></a>
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
				<?php
				echo lnkup_page_header( array( 'title' => __( 'Favorites', 'lnkup-marketplace' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo lnkup_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array(
						'title'     => __( 'Opens on launch day', 'lnkup-marketplace' ),
						'desc'      => sprintf( __( 'Save events to your favorites when the marketplace opens %s.', 'lnkup-marketplace' ), lnkup_get_launch_date_label() ),
						'cta_label' => __( 'Complete your profile', 'lnkup-marketplace' ),
						'cta_href'  => lnkup_page_url_helper( 'vendor-profile' ),
					)
				);
				?>
			</div>
			<?php
			return ob_get_clean();
		}
		$user_id  = get_current_user_id();
		$favs     = LNKup_Vendor_Personalization::get_favorite_opportunities( $user_id, 24 );
		$recent   = LNKup_Vendor_Personalization::get_recent_opportunities( $user_id, 8 );
		$fav_sort = isset( $_GET['fav_sort'] ) ? sanitize_key( wp_unslash( $_GET['fav_sort'] ) ) : 'saved'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'date' === $fav_sort ) {
			usort(
				$favs,
				static function ( $a, $b ) {
					$ad = ! empty( $a['date'] ) ? strtotime( (string) $a['date'] ) : PHP_INT_MAX;
					$bd = ! empty( $b['date'] ) ? strtotime( (string) $b['date'] ) : PHP_INT_MAX;
					return $ad <=> $bd;
				}
			);
		} elseif ( 'fee' === $fav_sort ) {
			usort(
				$favs,
				static function ( $a, $b ) {
					return (float) ( $a['vendor_fee'] ?? 0 ) <=> (float) ( $b['vendor_fee'] ?? 0 );
				}
			);
		} else {
			$fav_sort = 'saved';
		}
		$recommended = ( class_exists( 'LNKup_Recommendations' ) && lnkup_marketplace_phase_at_least( '1b' ) ) ? LNKup_Recommendations::get_for_user( $user_id, 4 ) : array();
		$fav_ids     = array_map( 'intval', wp_list_pluck( $favs, 'opportunity_id' ) );
		$recommended = array_values(
			array_filter(
				(array) $recommended,
				static function ( $opp ) use ( $fav_ids ) {
					return is_array( $opp ) && ! in_array( (int) ( $opp['opportunity_id'] ?? 0 ), $fav_ids, true );
				}
			)
		);

		ob_start();
		?>
		<div class="lnkup-mp">
			<?php
			echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title'    => __( 'Saved', 'lnkup-marketplace' ),
					'subtitle' => __( 'Opportunities and searches you want to come back to. Remove an item with its heart.', 'lnkup-marketplace' ),
					'actions'  => '<a class="lnkup-btn lnkup-btn--outline" href="' . esc_url( lnkup_page_url_helper( 'opportunities' ) ) . '">' . esc_html__( 'Find events', 'lnkup-marketplace' ) . '</a>',
				)
			);
			?>
			<section class="lnkup-panel" id="lnkup-saved-searches-panel" data-testid="lnkup-saved-searches-panel">
				<h2 class="lnkup-panel__title"><?php esc_html_e( 'Saved Searches', 'lnkup-marketplace' ); ?></h2>
				<div id="lnkup-saved-searches-dashboard" class="lnkup-saved-searches-dashboard">
					<?php echo lnkup_loading_state( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</section>
			<section class="lnkup-panel">
				<div class="lnkup-home-block__head">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Saved opportunities', 'lnkup-marketplace' ); ?></h2>
					<?php if ( count( $favs ) > 1 ) : ?>
						<form method="get" class="lnkup-fav-sort" data-auto-submit>
							<label for="lnkup-fav-sort"><?php esc_html_e( 'Sort', 'lnkup-marketplace' ); ?></label>
							<select id="lnkup-fav-sort" name="fav_sort" data-auto-submit-field>
								<option value="saved"<?php selected( $fav_sort, 'saved' ); ?>><?php esc_html_e( 'Recently saved', 'lnkup-marketplace' ); ?></option>
								<option value="date"<?php selected( $fav_sort, 'date' ); ?>><?php esc_html_e( 'Event date', 'lnkup-marketplace' ); ?></option>
								<option value="fee"<?php selected( $fav_sort, 'fee' ); ?>><?php esc_html_e( 'Lowest fee', 'lnkup-marketplace' ); ?></option>
							</select>
							<button type="submit" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-fav-sort__apply"><?php esc_html_e( 'Apply', 'lnkup-marketplace' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
				<?php
				$today      = current_time( 'Y-m-d' );
				$soon       = gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) );
				$fav_groups = array(
					'closing'   => array(),
					'available' => array(),
					'closed'    => array(),
					'past'      => array(),
				);
				foreach ( $favs as $opp ) {
					$opp_date     = (string) ( $opp['date'] ?? '' );
					$opp_deadline = (string) ( $opp['deadline'] ?? '' );
					$is_open      = ! empty( $opp['is_open'] ) && ( '' === $opp_deadline || $opp_deadline >= $today ) && (int) ( $opp['spots_remaining'] ?? 1 ) > 0;
					if ( '' !== $opp_date && $opp_date < $today ) {
						$fav_groups['past'][] = $opp;
					} elseif ( ! $is_open ) {
						$fav_groups['closed'][] = $opp;
					} elseif ( '' !== $opp_deadline && $opp_deadline <= $soon ) {
						$fav_groups['closing'][] = $opp;
					} else {
						$fav_groups['available'][] = $opp;
					}
				}
				$fav_labels = array(
					'closing'   => __( 'Closing soon', 'lnkup-marketplace' ),
					'available' => __( 'Available', 'lnkup-marketplace' ),
					'closed'    => __( 'Applications closed', 'lnkup-marketplace' ),
					'past'      => __( 'Past events', 'lnkup-marketplace' ),
				);
				?>
				<?php foreach ( $fav_groups as $group_key => $group_items ) : ?>
					<?php if ( ! $group_items ) { continue; } ?>
					<div class="vos-saved-group" data-saved-group="<?php echo esc_attr( $group_key ); ?>">
						<p class="vos-history__label"><?php echo esc_html( $fav_labels[ $group_key ] ); ?> (<?php echo esc_html( (string) count( $group_items ) ); ?>)</p>
						<div class="lnkup-event-grid">
							<?php foreach ( $group_items as $opp ) : ?>
								<?php
								$opp['card_variant'] = 'compact';
								echo lnkup_render_opportunity_card_v2( $opp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
				<?php if ( ! $favs ) : ?>
					<?php
					echo lnkup_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						array(
							'title'     => __( 'No favorites yet', 'lnkup-marketplace' ),
							'desc'      => __( 'Browse the marketplace and tap the heart on any event to save it here.', 'lnkup-marketplace' ),
							'cta_label' => __( 'Find events', 'lnkup-marketplace' ),
							'cta_href'  => lnkup_page_url_helper( 'opportunities' ),
							'compact'   => true,
						)
					);
					?>
				<?php endif; ?>
			</section>
			<?php if ( $recommended ) : ?>
				<section class="lnkup-panel" data-testid="lnkup-favorites-recommended">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Recommended for you', 'lnkup-marketplace' ); ?></h2>
					<div class="lnkup-event-grid">
						<?php foreach ( $recommended as $opp ) : ?>
							<?php
							$opp['card_variant'] = 'compact';
							echo lnkup_render_opportunity_card_v2( $opp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>
			<?php if ( $recent ) : ?>
				<section class="lnkup-panel">
					<h2 class="lnkup-panel__title"><?php esc_html_e( 'Recently Viewed', 'lnkup-marketplace' ); ?></h2>
					<div class="lnkup-event-grid">
						<?php foreach ( $recent as $opp ) : ?>
							<?php
							$opp['card_variant'] = 'compact';
							echo lnkup_render_opportunity_card_v2( $opp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
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
			'slots'     => count( $opps ),
		);
	}

	/**
	 * Featured image URL for an organizer event card.
	 *
	 * @param int $event_id Event post ID.
	 */
	private static function organizer_event_image_url( $event_id ) {
		if ( class_exists( 'LNKup_Event_Media' ) ) {
			$media = LNKup_Event_Media::resolve( (int) $event_id );
			return (string) ( $media['medium_url'] ?: $media['url'] );
		}
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
				<a href="<?php echo esc_url( lnkup_organizer_view_url( 'financial' ) ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-expand="financial"><?php echo esc_html( lnkup_copy( 'open_financial_center' ) ); ?></a>
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
	public static function render_organizer_readiness_row( $events, $dash_url ) {
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
	 * Priority module rows from missing-doc applications.
	 *
	 * @param array  $missing_docs Apps not event-ready.
	 * @param string $dash_url     Dashboard URL.
	 */
	public static function render_organizer_priority_module( $missing_docs, $dash_url ) {
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
					$vendor_url  = $profile_id ? add_query_arg( 'vendor', $profile_id, $dash_url ) : lnkup_organizer_view_url( 'applications' );
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
							<a href="<?php echo esc_url( lnkup_organizer_view_url( 'applications' ) ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-org-app-filter-trigger="missing_docs"><?php esc_html_e( 'Review docs', 'lnkup-marketplace' ); ?></a>
							<a href="<?php echo esc_url( $vendor_url ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost"><?php esc_html_e( 'View vendor', 'lnkup-marketplace' ); ?></a>
							<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-remind-docs="<?php echo esc_attr( (string) $app->ID ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: vendor name */ __( 'Send document reminder to %s', 'lnkup-marketplace' ), $vendor_name ) ); ?>"><?php esc_html_e( 'Send reminder', 'lnkup-marketplace' ); ?></button>
							<span class="lnkup-type-caption lnkup-remind-status" data-remind-status role="status" aria-live="polite"></span>
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
	public static function render_organizer_apps_snapshot( $pending_apps, $dash_url, $limit = 3 ) {
		$snapshot = array_slice( array_values( $pending_apps ), 0, $limit );
		ob_start();
		?>
		<div class="lnkup-panel lnkup-organizer-snapshot">
			<div class="lnkup-panel__header-row">
				<h2 class="lnkup-panel__title"><?php esc_html_e( 'Recent requests', 'lnkup-marketplace' ); ?></h2>
			</div>
			<?php if ( $snapshot ) : ?>
				<?php echo self::render_applications_manager( $snapshot ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="lnkup-organizer-snapshot__footer">
					<a href="<?php echo esc_url( lnkup_organizer_view_url( 'applications' ) ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php echo esc_html( lnkup_copy( 'view_all_vendor_requests' ) ); ?></a>
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
	public static function render_organizer_launch_checklist( array $checklist, string $dash_url ): string {
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
			<a href="<?php echo esc_url( lnkup_organizer_view_url( 'financial' ) ); ?>" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-expand="financial"><?php echo esc_html( lnkup_copy( 'expand_financials' ) ); ?></a>
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
	public static function organizer_section_card_open( $id, $title, $desc = '', $actions = '' ) {
		ob_start();
		?>
		<section id="<?php echo esc_attr( $id ); ?>" class="lnkup-organizer-section-card lnkup-panel" data-section="<?php echo esc_attr( $id ); ?>">
			<?php if ( '' !== (string) $title || $desc || $actions ) : ?>
			<div class="lnkup-organizer-section-card__header">
				<div class="lnkup-organizer-section-card__head">
					<?php if ( '' !== (string) $title ) : ?>
						<h2 class="lnkup-panel__title"><?php echo esc_html( $title ); ?></h2>
					<?php endif; ?>
					<?php if ( $desc ) : ?>
						<p class="lnkup-organizer-section-card__desc lnkup-muted"><?php echo esc_html( $desc ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $actions ) : ?>
					<div class="lnkup-organizer-section-card__actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<?php endif; ?>
			</div>
			<?php endif; ?>
			<div class="lnkup-organizer-section-card__body">
		<?php
		return ob_get_clean();
	}

	/**
	 * Close organizer section card.
	 */
	public static function organizer_section_card_close() {
		return '</div></section>';
	}

	/**
	 * Open inline expand panel for secondary module details.
	 *
	 * @param string $panel_id Panel key (vendors, lists, etc.).
	 * @param bool   $hidden   Whether panel starts hidden.
	 */
	public static function organizer_expand_panel_open( $panel_id, $hidden = true ) {
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
	public static function organizer_expand_panel_close() {
		return '</div>';
	}

	/**
	 * Open compact secondary module card.
	 *
	 * @param string $id    Section anchor id.
	 * @param string $title Card title.
	 * @param string $desc  Optional description.
	 */
	public static function organizer_module_card_open( $id, $title, $desc = '' ) {
		ob_start();
		?>
		<article id="<?php echo esc_attr( $id ); ?>" class="lnkup-organizer-module-card lnkup-panel" data-section="<?php echo esc_attr( $id ); ?>">
			<?php if ( '' !== (string) $title ) : ?>
				<h2 class="lnkup-organizer-module-card__title"><?php echo esc_html( $title ); ?></h2>
			<?php endif; ?>
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
	public static function organizer_module_card_close() {
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
					'cta_href'            => lnkup_organizer_view_url( 'invites' ),
					'secondary_cta_label' => __( 'Browse marketplace', 'lnkup-marketplace' ),
					'secondary_cta_href'  => lnkup_organizer_view_url( 'vendors' ),
					'compact'             => true,
				)
			);
		}

		ob_start();
		echo '<div class="lnkup-organizer-vendors-preview-grid">';
		foreach ( array_slice( $items, 0, 3 ) as $vendor ) {
			$profile_id = (int) ( $vendor['profile_id'] ?? 0 );
			$name       = $vendor['business_name'] ?? __( 'Vendor', 'lnkup-marketplace' );
			$url        = $profile_id ? add_query_arg( array( 'vendor' => $profile_id ), $dash_url ) : lnkup_organizer_view_url( 'vendors' );
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
	public static function render_organizer_intel_summary_ssr( $user_id ) {
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
						<span class="lnkup-organizer-intel-summary__chip"><?php echo esc_html( null !== $score ? sprintf( /* translators: 1: health label, 2: score */ __( '%1$s · %2$d/100', 'lnkup-marketplace' ), $label, $score ) : $label ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $summary['approved_vendors'] ) ) : ?>
						<span class="lnkup-organizer-intel-summary__chip"><?php echo esc_html( sprintf( /* translators: %s: percent */ __( '%s%% of accepted vendors paid', 'lnkup-marketplace' ), (string) $summary['payment_completion_rate'] ) ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( ! $top && null === $score && ! $label ) : ?>
					<p class="lnkup-muted"><?php echo esc_html( lnkup_copy( 'empty_intelligence' ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
		$grouped = array();
		foreach ( (array) $actions as $action ) {
			if ( empty( $action['title'] ) ) {
				continue;
			}
			$key = (string) $action['title'] . '|' . (string) ( $action['reason'] ?? '' );
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ]           = $action;
				$grouped[ $key ]['events'] = array();
			}
			if ( ! empty( $action['event_title'] ) ) {
				$grouped[ $key ]['events'][] = (string) $action['event_title'];
			}
		}
		$top_actions = array_slice( array_values( $grouped ), 0, 3 );
		$best        = $summary['top_events'][0] ?? null;
		$weakest     = $summary['weak_events'][0] ?? null;
		$dash_url    = lnkup_page_url_helper( 'organizer-dashboard' );
		?>
		<?php if ( $top_actions ) : ?>
			<section class="lnkup-organizer-intel-summary__block" data-testid="lnkup-intel-top-actions">
				<h3 class="lnkup-event-command__title"><?php esc_html_e( 'Recommended next steps', 'lnkup-marketplace' ); ?></h3>
				<ol class="lnkup-organizer-intel-summary__actions">
					<?php foreach ( $top_actions as $action ) : ?>
						<li>
							<strong><?php echo esc_html( (string) $action['title'] ); ?></strong>
							<?php if ( ! empty( $action['reason'] ) ) : ?>
								<span class="lnkup-muted"><?php echo esc_html( (string) $action['reason'] ); ?></span>
							<?php endif; ?>
							<?php if ( count( $action['events'] ) > 1 ) : ?>
								<span class="lnkup-muted">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: number of events, 2: event names */
											__( '%1$d events: %2$s', 'lnkup-marketplace' ),
											count( $action['events'] ),
											implode( ', ', array_slice( $action['events'], 0, 3 ) ) . ( count( $action['events'] ) > 3 ? '…' : '' )
										)
									);
									?>
								</span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>
		<?php endif; ?>
		<?php if ( $best && $weakest && (int) $best['event_id'] !== (int) $weakest['event_id'] ) : ?>
			<section class="lnkup-organizer-intel-summary__block lnkup-organizer-intel-summary__events">
				<a class="lnkup-home-next lnkup-home-next--row" href="<?php echo esc_url( add_query_arg( 'event', (int) $best['event_id'], $dash_url ) ); ?>">
					<span class="lnkup-type-label"><?php esc_html_e( 'Strongest event', 'lnkup-marketplace' ); ?></span>
					<strong><?php echo esc_html( (string) $best['title'] ); ?></strong>
					<span class="lnkup-muted"><?php echo esc_html( sprintf( /* translators: 1: label, 2: score */ __( '%1$s · %2$d', 'lnkup-marketplace' ), (string) $best['label'], (int) $best['score'] ) ); ?></span>
				</a>
				<a class="lnkup-home-next lnkup-home-next--row" href="<?php echo esc_url( add_query_arg( 'event', (int) $weakest['event_id'], $dash_url ) ); ?>">
					<span class="lnkup-type-label"><?php esc_html_e( 'Needs attention', 'lnkup-marketplace' ); ?></span>
					<strong><?php echo esc_html( (string) $weakest['title'] ); ?></strong>
					<span class="lnkup-muted"><?php echo esc_html( sprintf( /* translators: 1: label, 2: score */ __( '%1$s · %2$d', 'lnkup-marketplace' ), (string) $weakest['label'], (int) $weakest['score'] ) ); ?></span>
				</a>
			</section>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	/**
	 * One vendor-slot fee row: preset type, custom type, price, and spots.
	 *
	 * @param array<int, WP_Term>|WP_Error $categories Vendor category terms.
	 * @param bool                          $removable  Show remove control.
	 * @param bool                          $testid     Add create-form test ids.
	 */
	private static function render_vendor_slot_row( $categories, $removable = false, $testid = false ) {
		ob_start();
		?>
		<div class="lnkup-fee-row lnkup-grid lnkup-grid--3" data-fee-row>
			<div class="lnkup-form-row">
				<label><?php esc_html_e( 'Vendor type', 'lnkup-marketplace' ); ?></label>
				<select name="fee_category[]" class="lnkup-fee-row__category" required<?php echo $testid ? ' data-testid="lnkup-create-fee-category"' : ''; ?>>
					<option value=""><?php esc_html_e( 'Select type…', 'lnkup-marketplace' ); ?></option>
					<?php if ( ! is_wp_error( $categories ) ) : ?>
						<?php foreach ( $categories as $term ) : ?>
							<option value="<?php echo esc_attr( $term->name ); ?>"><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
				<label class="lnkup-fee-row__custom-label"><?php esc_html_e( 'Custom type', 'lnkup-marketplace' ); ?></label>
				<input type="text" name="fee_custom[]" class="lnkup-fee-row__custom" maxlength="80" placeholder="<?php esc_attr_e( 'Or type your own', 'lnkup-marketplace' ); ?>" />
			</div>
			<div class="lnkup-form-row">
				<label><?php esc_html_e( 'Booth price ($)', 'lnkup-marketplace' ); ?></label>
				<input type="number" name="fee_amount[]" class="lnkup-fee-row__fee" min="0" step="0.01" value="250" required<?php echo $testid ? ' data-testid="lnkup-create-opp-fee"' : ''; ?> />
			</div>
			<div class="lnkup-form-row">
				<label><?php esc_html_e( 'Spots', 'lnkup-marketplace' ); ?></label>
				<?php if ( $removable ) : ?>
					<div class="lnkup-fee-row__spots-wrap">
						<input type="number" name="fee_spots[]" class="lnkup-fee-row__spots" min="1" value="10" required />
						<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm lnkup-fee-row__remove" aria-label="<?php esc_attr_e( 'Remove', 'lnkup-marketplace' ); ?>">&times;</button>
					</div>
				<?php else : ?>
					<input type="number" name="fee_spots[]" class="lnkup-fee-row__spots" min="1" value="10" required<?php echo $testid ? ' data-testid="lnkup-create-opp-spots"' : ''; ?> />
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Vendor slots panel: existing slots plus the add form.
	 *
	 * @param int $event_id Event post ID.
	 */
	public static function render_add_vendor_slot_form( $event_id, $open = false ) {
		$event_id   = (int) $event_id;
		$categories = get_terms(
			array(
				'taxonomy'   => 'lnkup_vendor_category',
				'hide_empty' => false,
			)
		);
		$slots = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		ob_start();
		?>
		<div class="lnkup-slot-panel" <?php echo $open ? '' : 'hidden'; ?> data-slot-panel="<?php echo esc_attr( (string) $event_id ); ?>">
			<h3 class="lnkup-panel__title"><?php esc_html_e( 'Vendor slots', 'lnkup-marketplace' ); ?></h3>
			<p class="lnkup-slot-panel__links">
				<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" href="<?php echo esc_url( lnkup_organizer_view_url( 'applications', array( 'event_filter' => (int) $event_id, 'filter' => 'pending' ) ) ); ?>"><?php esc_html_e( 'Review requests', 'lnkup-marketplace' ); ?></a>
				<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" href="<?php echo esc_url( lnkup_organizer_view_url( 'vendors' ) ); ?>"><?php esc_html_e( 'Find vendors', 'lnkup-marketplace' ); ?></a>
			</p>
			<?php if ( ! $slots ) : ?>
				<p class="lnkup-muted"><?php esc_html_e( 'No vendor slots yet. Add one below to publish this event.', 'lnkup-marketplace' ); ?></p>
			<?php else : ?>
				<ul class="lnkup-slot-panel__list">
					<?php foreach ( $slots as $slot ) : ?>
						<?php
						$fee        = (float) get_post_meta( $slot->ID, '_lnkup_vendor_fee', true );
						$spots      = (int) get_post_meta( $slot->ID, '_lnkup_spots_total', true );
						$slot_state = (string) get_post_meta( $slot->ID, '_lnkup_opportunity_status', true );
						$is_closed  = 'closed' === $slot_state;
						$has_apps   = (bool) get_posts(
							array(
								'post_type'      => 'lnkup_application',
								'posts_per_page' => 1,
								'fields'         => 'ids',
								'meta_key'       => '_lnkup_opportunity_id',
								'meta_value'     => $slot->ID,
								'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
							)
						);
						?>
						<li class="lnkup-slot-panel__row" data-opportunity-id="<?php echo esc_attr( (string) $slot->ID ); ?>" data-slot-row>
							<label>
								<span class="screen-reader-text"><?php esc_html_e( 'Slot name', 'lnkup-marketplace' ); ?></span>
								<input type="text" class="lnkup-slot-title-input" value="<?php echo esc_attr( $slot->post_title ); ?>" />
							</label>
							<?php if ( $is_closed ) : ?>
								<span class="lnkup-status-pill lnkup-status-pill--muted"><?php esc_html_e( 'Closed', 'lnkup-marketplace' ); ?></span>
							<?php endif; ?>
							<label>
								<span class="screen-reader-text"><?php esc_html_e( 'Booth price', 'lnkup-marketplace' ); ?></span>
								<input type="number" class="lnkup-opp-fee-input" min="0" step="0.01" value="<?php echo esc_attr( (string) $fee ); ?>" />
							</label>
							<label>
								<span class="screen-reader-text"><?php esc_html_e( 'Spots', 'lnkup-marketplace' ); ?></span>
								<input type="number" class="lnkup-opp-spots-input" min="1" value="<?php echo esc_attr( (string) max( 1, $spots ) ); ?>" />
							</label>
							<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary lnkup-opp-pricing-save" data-opportunity-id="<?php echo esc_attr( (string) $slot->ID ); ?>"><?php esc_html_e( 'Save', 'lnkup-marketplace' ); ?></button>
							<?php if ( $is_closed ) : ?>
								<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-slot-status="open" data-opportunity-id="<?php echo esc_attr( (string) $slot->ID ); ?>"><?php esc_html_e( 'Reopen', 'lnkup-marketplace' ); ?></button>
							<?php elseif ( $has_apps ) : ?>
								<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-slot-status="closed" data-opportunity-id="<?php echo esc_attr( (string) $slot->ID ); ?>"><?php esc_html_e( 'Close', 'lnkup-marketplace' ); ?></button>
							<?php else : ?>
								<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-delete-slot="<?php echo esc_attr( (string) $slot->ID ); ?>"><?php esc_html_e( 'Remove', 'lnkup-marketplace' ); ?></button>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<form class="lnkup-form lnkup-opportunity-form lnkup-add-slot-form" data-add-slot-form="<?php echo esc_attr( (string) $event_id ); ?>">
			<?php wp_nonce_field( 'lnkup_opportunity', 'lnkup_nonce' ); ?>
			<input type="hidden" name="action" value="lnkup_create_opportunity" />
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>" />
			<h3 class="lnkup-panel__title"><?php esc_html_e( 'Add vendor slot', 'lnkup-marketplace' ); ?></h3>
			<p class="lnkup-muted"><?php esc_html_e( 'Choose a type or type a custom one, then set the booth price and spots.', 'lnkup-marketplace' ); ?></p>
			<div class="lnkup-fee-rows" data-fee-rows-list>
				<?php echo self::render_vendor_slot_row( $categories, true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-add-fee-row><?php esc_html_e( 'Add another', 'lnkup-marketplace' ); ?></button>
			<template data-fee-row-template>
				<?php echo self::render_vendor_slot_row( $categories, true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</template>
			<div class="lnkup-form-row">
				<label><?php esc_html_e( 'Notes', 'lnkup-marketplace' ); ?></label>
				<textarea name="organizer_notes" rows="2"></textarea>
			</div>
			<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Save vendor slot', 'lnkup-marketplace' ); ?></button>
		</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Dialog for duplicating an event onto a new name and date.
	 */
	public static function render_duplicate_event_dialog() {
		ob_start();
		?>
		<dialog id="lnkup-duplicate-event-dialog" class="lnkup-duplicate-dialog">
			<form class="lnkup-form" id="lnkup-duplicate-event-form">
				<?php wp_nonce_field( 'lnkup_event', 'lnkup_nonce' ); ?>
				<input type="hidden" name="action" value="lnkup_duplicate_event" />
				<input type="hidden" name="event_id" id="lnkup-duplicate-event-id" value="" />
				<input type="hidden" name="client_token" id="lnkup-duplicate-client-token" value="" />
				<h3 class="lnkup-panel__title"><?php esc_html_e( 'Duplicate event', 'lnkup-marketplace' ); ?></h3>
				<p class="lnkup-muted"><?php esc_html_e( 'Copies vendor slots, fees, and documents. Does not copy vendors, applications, or payments.', 'lnkup-marketplace' ); ?></p>
				<div class="lnkup-form-row">
					<label for="lnkup-duplicate-event-name"><?php esc_html_e( 'Event name', 'lnkup-marketplace' ); ?></label>
					<input type="text" name="event_name" id="lnkup-duplicate-event-name" required />
				</div>
				<div class="lnkup-form-row">
					<label for="lnkup-duplicate-event-date"><?php esc_html_e( 'New date', 'lnkup-marketplace' ); ?></label>
					<input type="date" name="event_date" id="lnkup-duplicate-event-date" required />
				</div>
				<fieldset class="lnkup-duplicate-dialog__location">
					<legend><?php esc_html_e( 'Where is the new event?', 'lnkup-marketplace' ); ?></legend>
					<p class="lnkup-muted" id="lnkup-duplicate-event-source-place" data-testid="lnkup-duplicate-source-place"></p>
					<label class="lnkup-duplicate-dialog__keep">
						<input type="checkbox" name="keep_location" value="1" id="lnkup-duplicate-keep-location" data-testid="lnkup-duplicate-keep-location" />
						<?php esc_html_e( 'Keep the same location as the original', 'lnkup-marketplace' ); ?>
					</label>
					<div class="lnkup-grid lnkup-grid--2">
						<div class="lnkup-form-row">
							<label for="lnkup-duplicate-event-city"><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></label>
							<input type="text" name="city" id="lnkup-duplicate-event-city" data-lnkup-location-city required autocomplete="address-level2" />
						</div>
						<div class="lnkup-form-row">
							<label for="lnkup-duplicate-event-state"><?php esc_html_e( 'State', 'lnkup-marketplace' ); ?></label>
							<input type="text" name="state" id="lnkup-duplicate-event-state" maxlength="2" data-lnkup-location-state required autocomplete="address-level1" />
						</div>
					</div>
				</fieldset>
				<div class="lnkup-form-row">
					<label for="lnkup-duplicate-event-venue"><?php esc_html_e( 'Venue', 'lnkup-marketplace' ); ?></label>
					<input type="text" name="venue_name" id="lnkup-duplicate-event-venue" />
				</div>
				<div class="lnkup-form-row">
					<label for="lnkup-duplicate-event-address"><?php esc_html_e( 'Venue address', 'lnkup-marketplace' ); ?></label>
					<textarea name="venue_address" id="lnkup-duplicate-event-address" rows="2"></textarea>
				</div>
				<p id="lnkup-duplicate-event-error" class="lnkup-alert lnkup-alert--error" hidden></p>
				<div class="lnkup-duplicate-dialog__actions">
					<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Duplicate event', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-duplicate-cancel><?php esc_html_e( 'Cancel', 'lnkup-marketplace' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
		return ob_get_clean();
	}

	/**
	 * Signed URL that downloads vendor contacts for one organizer event.
	 *
	 * @param int $event_id Event post ID.
	 */
	public static function organizer_vendor_export_url( $event_id ) {
		return add_query_arg(
			array(
				'action'      => 'lnkup_export_vendors',
				'event_id'    => (int) $event_id,
				'lnkup_nonce' => wp_create_nonce( 'lnkup_export' ),
			),
			admin_url( 'admin-ajax.php' )
		);
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
		$status     = class_exists( 'LNKup_Organizer_Onboarding' )
			? LNKup_Organizer_Onboarding::sync_event_listing_status( (int) $event->ID )
			: 'draft';
		$is_live    = 'live' === $status;
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
		$export_url = self::organizer_vendor_export_url( $event->ID );
		$location   = trim( $city . ( $state ? ', ' . $state : '' ) );
		$date_label = $event_date ? date_i18n( 'M j, Y', strtotime( $event_date ) ) : '—';
		$apps_url   = lnkup_organizer_view_url( 'applications', array( 'event_filter' => (int) $event->ID, 'filter' => 'pending' ) );
		$fin_url    = lnkup_organizer_view_url( 'financial' );
		$edit_url   = add_query_arg( array( 'event' => (int) $event->ID, 'edit' => '1' ), $dash_url );
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
		$status_label = $is_live ? __( 'Live', 'lnkup-marketplace' ) : __( 'Draft', 'lnkup-marketplace' );
		$is_past      = $event_date && strtotime( (string) $event_date ) < strtotime( 'today' );
		$event_bucket = $is_past ? 'completed' : ( $is_live ? 'live' : 'draft' );
		if ( 'completed' === $event_bucket ) {
			$status_label = __( 'Completed', 'lnkup-marketplace' );
		}
		$request_total = count(
			array_filter(
				$apps,
				function ( $a ) use ( $event ) {
					return (int) get_post_meta( $a->ID, '_lnkup_event_id', true ) === (int) $event->ID;
				}
			)
		);
		$venue_name = (string) get_post_meta( $event->ID, '_lnkup_venue_name', true );
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
					if ( ! class_exists( 'LNKup_Verification' ) ) {
						return false;
					}
					$ctx  = LNKup_Verification::get_application_document_context( (int) $a->ID );
					$code = (string) ( $ctx['code'] ?? '' );
					return in_array( $code, array( 'pending', 'missing' ), true );
				}
			)
		);
		?>
		<article class="lnkup-organizer-event-card lnkup-organizer-event-card--v3 lnkup-organizer-event-card--v4 lnkup-surface--primary" data-event-bucket="<?php echo esc_attr( $event_bucket ); ?>" data-event-search="<?php echo esc_attr( strtolower( $event->post_title . ' ' . $location . ' ' . $venue_name ) ); ?>">
			<div class="lnkup-organizer-event-card__media">
				<?php if ( $image_url ) : ?>
					<img src="<?php echo esc_url( $image_url ); ?>" alt="" class="lnkup-organizer-event-card__image" loading="lazy" />
				<?php else : ?>
					<div class="lnkup-organizer-event-card__placeholder lnkup-organizer-event-card__fallback" aria-hidden="true">
						<span class="lnkup-organizer-event-card__fallback-initial"><?php echo esc_html( $initial ?: 'E' ); ?></span>
					</div>
				<?php endif; ?>
				<span class="lnkup-status-pill<?php echo 'live' === $event_bucket ? '' : ' lnkup-status-pill--muted'; ?>"><?php echo esc_html( $status_label ); ?></span>
			</div>
			<div class="lnkup-organizer-event-card__body">
				<h3 class="lnkup-organizer-event-card__title">
					<a href="<?php echo esc_url( add_query_arg( 'event', (int) $event->ID, $dash_url ) ); ?>"><?php echo esc_html( $event->post_title ); ?></a>
				</h3>
				<p class="lnkup-organizer-event-card__meta-strip lnkup-muted">
					<span><?php echo esc_html( $date_label ); ?></span>
					<?php if ( $location ) : ?>
						<span aria-hidden="true">·</span>
						<span><?php echo esc_html( $location ); ?></span>
					<?php endif; ?>
				</p>
				<p class="lnkup-organizer-event-card__meta-strip">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: vendor slot count, 2: spots still open */
							__( '%1$d vendor slots · %2$d open', 'lnkup-marketplace' ),
							(int) ( $spots['slots'] ?? 0 ),
							(int) $spots['remaining']
						)
					);
					?>
				</p>
				<dl class="lnkup-organizer-event-card__stats">
					<div>
						<dt><?php esc_html_e( 'Pending', 'lnkup-marketplace' ); ?></dt>
						<dd><?php echo esc_html( (string) $new_apps ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Booked', 'lnkup-marketplace' ); ?></dt>
						<dd><?php echo esc_html( (string) $vendor_count ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Collected', 'lnkup-marketplace' ); ?></dt>
						<dd><?php echo esc_html( lnkup_currency( (float) $revenue ) ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Docs due', 'lnkup-marketplace' ); ?></dt>
						<dd><?php echo esc_html( (string) $readiness_issues ); ?></dd>
					</div>
				</dl>
				<div class="lnkup-organizer-event-card__primary-actions">
					<a class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" href="<?php echo esc_url( add_query_arg( 'event', (int) $event->ID, $dash_url ) ); ?>"><?php esc_html_e( 'Open', 'lnkup-marketplace' ); ?></a>
					<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" href="<?php echo esc_url( $apps_url ); ?>">
						<?php esc_html_e( 'Requests', 'lnkup-marketplace' ); ?>
						<?php if ( $new_apps ) : ?>
							<span class="lnkup-dash-tabs__badge lnkup-dash-tabs__badge--pending"><?php echo esc_html( (string) $new_apps ); ?></span>
						<?php endif; ?>
					</a>
					<details class="lnkup-more-menu">
						<summary class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: event name */ __( 'More actions for %s', 'lnkup-marketplace' ), $event->post_title ) ); ?>"><?php esc_html_e( 'More', 'lnkup-marketplace' ); ?></summary>
						<div class="lnkup-more-menu__panel">
							<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit event', 'lnkup-marketplace' ); ?></a>
							<a href="<?php echo esc_url( $roster_url ); ?>"><?php esc_html_e( 'Roster', 'lnkup-marketplace' ); ?></a>
							<a href="<?php echo esc_url( $ops_url ); ?>"><?php esc_html_e( 'Operations', 'lnkup-marketplace' ); ?></a>
							<a href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export contacts', 'lnkup-marketplace' ); ?></a>
							<button type="button" data-duplicate-event="<?php echo esc_attr( (string) (int) $event->ID ); ?>" data-event-name="<?php echo esc_attr( $event->post_title ); ?>" data-city="<?php echo esc_attr( (string) $city ); ?>" data-state="<?php echo esc_attr( (string) $state ); ?>" data-venue="<?php echo esc_attr( (string) $venue_name ); ?>"><?php esc_html_e( 'Duplicate', 'lnkup-marketplace' ); ?></button>
						</div>
					</details>
				</div>
			</div>
		</article>
		<?php
	}

	/**
	 * Every vendor slot across the organizer's events, so several events can be edited in one list.
	 */
	private static function render_portfolio_vendor_slots( $only_event_id = 0 ) {
		$only_event_id = (int) $only_event_id;
		$user_id = get_current_user_id();
		$query   = array(
			'post_type'      => 'lnkup_opportunity',
			'posts_per_page' => -1,
			'post_status'    => array( 'publish', 'draft', 'pending' ),
		);
		if ( $only_event_id ) {
			$query['meta_key']   = '_lnkup_event_id';
			$query['meta_value'] = $only_event_id;
		} else {
			$query['meta_key']   = '_lnkup_organizer_user_id';
			$query['meta_value'] = $user_id;
		}
		$slots = get_posts( $query );
		if ( ! $slots ) {
			return '';
		}

		$groups = array();
		foreach ( $slots as $slot ) {
			$event_id = (int) get_post_meta( $slot->ID, '_lnkup_event_id', true );
			if ( ! isset( $groups[ $event_id ] ) ) {
				$event      = $event_id ? get_post( $event_id ) : null;
				$event_name = $event ? $event->post_title : __( 'Event', 'lnkup-marketplace' );
				$city       = $event_id ? (string) get_post_meta( $event_id, '_lnkup_city', true ) : '';
				$date       = $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_date', true ) : '';
				$groups[ $event_id ] = array(
					'name'  => $event_name,
					'when'  => trim( $city . ( $date ? ' · ' . $date : '' ) ),
					'slots' => array(),
				);
			}
			$groups[ $event_id ]['slots'][] = $slot;
		}

		ob_start();
		?>
		<div class="lnkup-slot-panel lnkup-portfolio-slots" data-portfolio-slots>
			<h3 class="lnkup-panel__title"><?php echo esc_html( $only_event_id ? __( 'Vendor slots', 'lnkup-marketplace' ) : __( 'Vendor slots across events', 'lnkup-marketplace' ) ); ?></h3>
			<p class="lnkup-muted"><?php esc_html_e( 'Change a slot’s name, price, or spots. Closing a slot keeps existing requests.', 'lnkup-marketplace' ); ?></p>
			<?php if ( ! $only_event_id ) : ?>
			<label class="lnkup-form-row lnkup-portfolio-slots__filter">
				<span class="screen-reader-text"><?php esc_html_e( 'Filter slots', 'lnkup-marketplace' ); ?></span>
				<input type="search" id="lnkup-portfolio-slot-filter" placeholder="<?php esc_attr_e( 'Filter by event, city, or slot…', 'lnkup-marketplace' ); ?>" />
			</label>
			<?php endif; ?>
			<?php foreach ( $groups as $group ) : ?>
				<section class="lnkup-portfolio-slots__group" data-slot-group>
					<?php if ( ! $only_event_id ) : ?>
					<header class="lnkup-portfolio-slots__head">
						<h4><?php echo esc_html( $group['name'] ); ?></h4>
						<?php if ( $group['when'] ) : ?>
							<p class="lnkup-muted"><?php echo esc_html( $group['when'] ); ?></p>
						<?php endif; ?>
					</header>
					<?php endif; ?>
					<ul class="lnkup-portfolio-slots__rows">
						<?php foreach ( $group['slots'] as $slot ) : ?>
							<?php
							$fee        = (float) get_post_meta( $slot->ID, '_lnkup_vendor_fee', true );
							$spots      = (int) get_post_meta( $slot->ID, '_lnkup_spots_total', true );
							$slot_state = (string) get_post_meta( $slot->ID, '_lnkup_opportunity_status', true );
							$is_closed  = 'closed' === $slot_state;
							$has_apps   = (bool) get_posts(
								array(
									'post_type'      => 'lnkup_application',
									'posts_per_page' => 1,
									'fields'         => 'ids',
									'meta_key'       => '_lnkup_opportunity_id',
									'meta_value'     => $slot->ID,
									'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
								)
							);
							$haystack = strtolower( $group['name'] . ' ' . $group['when'] . ' ' . $slot->post_title );
							?>
							<li class="lnkup-portfolio-slots__row" data-opportunity-id="<?php echo esc_attr( (string) $slot->ID ); ?>" data-slot-row data-slot-search="<?php echo esc_attr( $haystack ); ?>">
								<label class="lnkup-portfolio-slots__name">
									<span class="screen-reader-text"><?php esc_html_e( 'Slot name', 'lnkup-marketplace' ); ?></span>
									<input type="text" class="lnkup-slot-title-input" value="<?php echo esc_attr( $slot->post_title ); ?>" />
									<?php if ( $is_closed ) : ?>
										<span class="lnkup-status-pill lnkup-status-pill--muted"><?php esc_html_e( 'Closed', 'lnkup-marketplace' ); ?></span>
									<?php endif; ?>
								</label>
								<label>
									<span class="screen-reader-text"><?php esc_html_e( 'Booth price', 'lnkup-marketplace' ); ?></span>
									<input type="number" class="lnkup-opp-fee-input" min="0" step="0.01" value="<?php echo esc_attr( (string) $fee ); ?>" />
								</label>
								<label>
									<span class="screen-reader-text"><?php esc_html_e( 'Spots', 'lnkup-marketplace' ); ?></span>
									<input type="number" class="lnkup-opp-spots-input" min="1" value="<?php echo esc_attr( (string) max( 1, $spots ) ); ?>" />
								</label>
								<?php
								$inventory = class_exists( 'LNKup_Opportunity_Inventory' ) ? LNKup_Opportunity_Inventory::summary( (int) $slot->ID ) : null;
								$preview   = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::event_public_url( (int) get_post_meta( $slot->ID, '_lnkup_event_id', true ) ) : '';
								?>
								<span class="lnkup-portfolio-slots__left lnkup-type-caption" data-testid="lnkup-slot-inventory">
									<?php
									if ( $inventory ) {
										echo esc_html(
											sprintf(
												/* translators: 1: open spots, 2: held, 3: paid, 4: waitlisted */
												__( '%1$s open · %2$d held · %3$d paid · %4$d waitlisted', 'lnkup-marketplace' ),
												null === $inventory['available'] ? __( 'Unlimited', 'lnkup-marketplace' ) : (string) $inventory['available'],
												$inventory['held'],
												$inventory['paid'],
												$inventory['waitlisted']
											)
										);
									}
									?>
									<?php if ( $inventory && $inventory['waitlisted'] > 0 && ( null === $inventory['available'] || $inventory['available'] > 0 ) ) : ?>
										<a href="<?php echo esc_url( lnkup_organizer_view_url( 'applications', array( 'filter' => 'waitlisted', 'event_filter' => (int) get_post_meta( $slot->ID, '_lnkup_event_id', true ) ) ) ); ?>"><?php esc_html_e( 'Spot open — review waitlist', 'lnkup-marketplace' ); ?></a>
									<?php endif; ?>
								</span>
								<span class="lnkup-portfolio-slots__actions">
									<?php if ( $preview ) : ?>
										<a class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" href="<?php echo esc_url( $preview ); ?>" target="_blank" rel="noopener" data-testid="lnkup-slot-preview"><?php esc_html_e( 'Preview as vendor', 'lnkup-marketplace' ); ?></a>
									<?php endif; ?>
									<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary lnkup-opp-pricing-save" data-opportunity-id="<?php echo esc_attr( (string) $slot->ID ); ?>"><?php esc_html_e( 'Save', 'lnkup-marketplace' ); ?></button>
									<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-edit-app-questions="<?php echo esc_attr( (string) $slot->ID ); ?>" aria-expanded="false" data-testid="lnkup-slot-questions"><?php esc_html_e( 'Questions', 'lnkup-marketplace' ); ?></button>
									<?php if ( $is_closed ) : ?>
										<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-slot-status="open" data-opportunity-id="<?php echo esc_attr( (string) $slot->ID ); ?>"><?php esc_html_e( 'Reopen', 'lnkup-marketplace' ); ?></button>
									<?php elseif ( $has_apps ) : ?>
										<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-slot-status="closed" data-opportunity-id="<?php echo esc_attr( (string) $slot->ID ); ?>"><?php esc_html_e( 'Close', 'lnkup-marketplace' ); ?></button>
									<?php else : ?>
										<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-delete-slot="<?php echo esc_attr( (string) $slot->ID ); ?>"><?php esc_html_e( 'Remove', 'lnkup-marketplace' ); ?></button>
									<?php endif; ?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endforeach; ?>
			<p class="lnkup-muted" id="lnkup-portfolio-slot-empty" hidden><?php esc_html_e( 'No slots match that filter.', 'lnkup-marketplace' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Organizer events shortcode.
	 *
	 * @param int $limit Optional max event cards (0 = all, skips create-opportunity block when set).
	 */
	/**
	 * Applications or payments for a set of the organizer's events (one query instead of loading everything).
	 *
	 * @param string $post_type lnkup_application|lnkup_payment.
	 * @param int    $user_id   Organizer user ID.
	 * @param int[]  $event_ids Event IDs on screen.
	 * @return WP_Post[]
	 */
	private static function organizer_posts_for_events( $post_type, $user_id, $event_ids ) {
		if ( ! $event_ids ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) $user_id ),
					array( 'key' => '_lnkup_event_id', 'value' => array_map( 'strval', $event_ids ), 'compare' => 'IN' ),
				),
			)
		);
	}

	public static function render_organizer_events( $limit = 0 ) {
		if ( is_array( $limit ) ) {
			$limit = 0;
		}
		$limit   = absint( $limit );
		$user_id = get_current_user_id();
		$ids     = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $user_id,
			)
		);
		$buckets = array(
			'live'      => array(),
			'draft'     => array(),
			'completed' => array(),
		);
		foreach ( $ids as $event_id ) {
			$event_date = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
			$is_past    = $event_date && strtotime( $event_date ) < strtotime( 'today' );
			$is_live    = class_exists( 'LNKup_Organizer_Onboarding' ) && LNKup_Organizer_Onboarding::event_has_open_slot( (int) $event_id );
			$row        = array(
				'id'   => (int) $event_id,
				'date' => $event_date ? strtotime( $event_date ) : PHP_INT_MAX,
			);
			if ( $is_past ) {
				$buckets['completed'][] = $row;
			} elseif ( $is_live ) {
				$buckets['live'][] = $row;
			} else {
				$buckets['draft'][] = $row;
			}
		}
		foreach ( $buckets as $key => $rows ) {
			usort(
				$rows,
				function ( $a, $b ) use ( $key ) {
					if ( 'completed' === $key ) {
						return $b['date'] <=> $a['date'];
					}
					return $a['date'] <=> $b['date'];
				}
			);
			$buckets[ $key ] = $rows;
		}
		$dash_url = lnkup_page_url_helper( 'organizer-dashboard' );
		ob_start();
		if ( $limit > 0 ) {
			$upcoming = $buckets['live'] ? $buckets['live'] : $buckets['draft'];
			$slice    = array_slice( $upcoming, 0, $limit );
			if ( $slice ) {
				$slice_ids = array_map( 'intval', wp_list_pluck( $slice, 'id' ) );
				$apps      = self::organizer_posts_for_events( 'lnkup_application', $user_id, $slice_ids );
				$payments  = self::organizer_posts_for_events( 'lnkup_payment', $user_id, $slice_ids );
				$posts     = get_posts(
					array(
						'post_type'      => 'lnkup_event',
						'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
						'posts_per_page' => count( $slice ),
						'post__in'       => wp_list_pluck( $slice, 'id' ),
						'orderby'        => 'post__in',
					)
				);
				echo '<div class="lnkup-organizer-event-grid lnkup-events-example">';
				foreach ( $posts as $event ) {
					self::render_organizer_event_card( $event, $apps, $payments, $dash_url );
				}
				echo '</div>';
			}
			return ob_get_clean();
		}
		if ( ! $ids ) {
			echo lnkup_empty_state(
				array(
					'title'               => __( 'Launch your first event', 'lnkup-marketplace' ),
					'desc'                => __( 'Create an event to publish vendor opportunities and start filling your vendor row.', 'lnkup-marketplace' ),
					'cta_label'           => lnkup_copy( 'launch_event' ),
					'cta_href'            => lnkup_organizer_view_url( 'create' ),
					'secondary_cta_label' => __( 'Browse vendors', 'lnkup-marketplace' ),
					'secondary_cta_href'  => lnkup_organizer_view_url( 'vendors' ),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return ob_get_clean();
		}
		$tab = isset( $_GET['event_tab'] ) ? sanitize_key( wp_unslash( $_GET['event_tab'] ) ) : 'live'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $buckets[ $tab ] ) ) {
			$tab = 'live';
		}
		$page  = function_exists( 'lnkup_organizer_dashboard_page' ) ? lnkup_organizer_dashboard_page() : 1;
		$total    = count( $buckets[ $tab ] );
		$slice    = array_slice( $buckets[ $tab ], ( $page - 1 ) * 20, 20 );
		$page_ids = array_map( 'intval', wp_list_pluck( $slice, 'id' ) );
		$apps     = self::organizer_posts_for_events( 'lnkup_application', $user_id, $page_ids );
		$payments = self::organizer_posts_for_events( 'lnkup_payment', $user_id, $page_ids );
		$posts    = $slice ? get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => count( $slice ),
				'post__in'       => wp_list_pluck( $slice, 'id' ),
				'orderby'        => 'post__in',
			)
		) : array();
		$tab_labels = array(
			'live'      => __( 'Live', 'lnkup-marketplace' ),
			'draft'     => __( 'Drafts', 'lnkup-marketplace' ),
			'completed' => __( 'Completed', 'lnkup-marketplace' ),
		);
		$event_tabs = array();
		foreach ( $tab_labels as $key => $label ) {
			$event_tabs[] = array(
				'id'    => $key,
				'label' => $label,
				'href'  => lnkup_organizer_view_url( 'events', array( 'event_tab' => $key ) ),
				'badge' => count( $buckets[ $key ] ),
			);
		}
		echo lnkup_dash_tabs( $event_tabs, $tab, array( 'variant' => 'pills', 'label' => __( 'Event status', 'lnkup-marketplace' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! $posts ) {
			$empty_copy = array(
				'live'      => __( 'Events with an open vendor slot show up here.', 'lnkup-marketplace' ),
				'draft'     => __( 'Events without an open vendor slot show up here.', 'lnkup-marketplace' ),
				'completed' => __( 'Events appear here after their date passes.', 'lnkup-marketplace' ),
			);
			echo lnkup_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title'   => __( 'No events in this tab', 'lnkup-marketplace' ),
					'desc'    => $empty_copy[ $tab ] ?? '',
					'compact' => true,
				)
			);
		} else {
			if ( count( $posts ) > 3 ) {
				echo '<label class="lnkup-event-search"><span class="screen-reader-text">' . esc_html__( 'Search events on this page', 'lnkup-marketplace' ) . '</span><input type="search" data-event-search-input aria-controls="lnkup-org-event-grid" placeholder="' . esc_attr__( 'Search by event name or city', 'lnkup-marketplace' ) . '" /></label>';
			}
			echo '<div class="lnkup-organizer-event-grid lnkup-events-example" id="lnkup-org-event-grid">';
			foreach ( $posts as $event ) {
				self::render_organizer_event_card( $event, $apps, $payments, $dash_url );
			}
			echo '</div>';
			echo '<p class="lnkup-muted" id="lnkup-event-search-empty" role="status" hidden>' . esc_html__( 'No events on this page match your search.', 'lnkup-marketplace' ) . '</p>';
			echo lnkup_organizer_pagination_nav( $total, 20, 'events', array( 'event_tab' => $tab ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo self::render_duplicate_event_dialog(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::render_share_event_dialog(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
	public static function render_create_panel( $events ) {
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
						<a href="<?php echo esc_url( lnkup_organizer_view_url( 'events' ) ); ?>" class="lnkup-link"><?php esc_html_e( 'Edit fee and spots for existing vendor slots →', 'lnkup-marketplace' ); ?></a>
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
						<input type="hidden" name="client_token" id="lnkup_event_client_token" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>" />
						<input type="hidden" name="save_mode" id="lnkup_event_save_mode" value="publish" />
						<?php
						$lnkup_setup_id = isset( $_GET['setup'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['setup'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						$lnkup_setup    = ( '' !== $lnkup_setup_id && class_exists( 'LNKup_Organizer_Setups' ) ) ? LNKup_Organizer_Setups::get( get_current_user_id(), $lnkup_setup_id ) : null;
						if ( $lnkup_setup ) :
							$lnkup_setup_view = LNKup_Organizer_Setups::format( $lnkup_setup );
							?>
							<input type="hidden" name="setup_id" value="<?php echo esc_attr( $lnkup_setup_view['id'] ); ?>" />
							<div class="lnkup-notice lnkup-notice--info" data-testid="lnkup-create-setup-notice" role="status">
								<strong><?php echo esc_html( sprintf( /* translators: %s: setup name */ __( 'Using saved setup: %s', 'lnkup-marketplace' ), $lnkup_setup_view['name'] ) ); ?></strong>
								<p><?php echo esc_html( implode( ' · ', array_map( static fn( $s ) => $s['title'] . ( null === $s['vendor_fee'] ? '' : ' — $' . number_format( (float) $s['vendor_fee'], 2 ) ), $lnkup_setup_view['slots'] ) ) ); ?></p>
								<p class="lnkup-muted"><?php esc_html_e( 'These booth types, prices, requirements and application questions are copied to the new event. Any booth rows below are ignored.', 'lnkup-marketplace' ); ?></p>
							</div>
						<?php endif; ?>
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
							<div data-testid="lnkup-event-gallery">
								<?php
								echo lnkup_image_upload_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									array(
										'mode'         => 'gallery',
										'label'        => __( 'Photos (optional)', 'lnkup-marketplace' ),
										'button_label' => __( 'Add photos', 'lnkup-marketplace' ),
										'input_name'   => 'gallery_ids',
										'input_id'     => 'lnkup_event_gallery_ids',
										'alt'          => __( 'Event photo', 'lnkup-marketplace' ),
										'testid'       => 'lnkup-event-gallery-upload',
									)
								);
								?>
								<p class="lnkup-form-hint lnkup-muted"><?php esc_html_e( 'Photos from past or similar events. Vendors see these when they open the opportunity.', 'lnkup-marketplace' ); ?></p>
							</div>
						</section>

						<section class="lnkup-panel lnkup-organizer-create-section" id="lnkup-create-step-2">
							<h3 class="lnkup-panel__title"><?php esc_html_e( 'Date & location', 'lnkup-marketplace' ); ?></h3>
							<div class="lnkup-grid lnkup-grid--2">
								<div class="lnkup-form-row">
									<label for="event_date"><?php esc_html_e( 'Event date', 'lnkup-marketplace' ); ?> <span aria-hidden="true">*</span></label>
									<input type="date" id="event_date" name="event_date" data-testid="lnkup-event-date" required />
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
									<input type="text" id="event_city" name="city" data-lnkup-location-city placeholder="<?php esc_attr_e( 'Start typing a city', 'lnkup-marketplace' ); ?>" />
								</div>
								<div class="lnkup-form-row">
									<label for="event_state"><?php esc_html_e( 'State', 'lnkup-marketplace' ); ?></label>
									<input type="text" id="event_state" name="state" maxlength="2" data-lnkup-location-state />
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
							<div class="lnkup-form-row">
								<label><input type="checkbox" name="power_available" value="1" data-testid="lnkup-event-power" /> <?php esc_html_e( 'Electricity available for vendors', 'lnkup-marketplace' ); ?></label>
							</div>
						</section>

						<section class="lnkup-panel lnkup-organizer-create-section" id="lnkup-create-step-3">
							<h3 class="lnkup-panel__title"><?php esc_html_e( 'Vendor slots', 'lnkup-marketplace' ); ?></h3>
							<p class="lnkup-muted"><?php esc_html_e( 'Pick a vendor type or enter a custom type. Each row is its own booth price and spot count.', 'lnkup-marketplace' ); ?></p>
							<div class="lnkup-form-row">
								<label>
									<input type="checkbox" name="open_vendor_applications" id="lnkup_open_vendor_applications" value="1" checked data-testid="lnkup-open-vendor-apps" />
									<?php esc_html_e( 'Open vendor applications when I publish', 'lnkup-marketplace' ); ?>
								</label>
							</div>
							<div class="lnkup-grid lnkup-grid--2" data-applications-settings>
								<div class="lnkup-form-row">
									<label for="lnkup_create_app_deadline"><?php esc_html_e( 'Application deadline', 'lnkup-marketplace' ); ?></label>
									<input type="date" id="lnkup_create_app_deadline" name="application_deadline" data-testid="lnkup-create-app-deadline" />
									<p class="lnkup-form-hint lnkup-muted"><?php esc_html_e( 'Leave blank to close applications a week before the event.', 'lnkup-marketplace' ); ?></p>
								</div>
								<div class="lnkup-form-row">
									<label for="lnkup_create_document_mode"><?php esc_html_e( 'Vendor documents', 'lnkup-marketplace' ); ?></label>
									<select id="lnkup_create_document_mode" name="document_mode" data-testid="lnkup-create-document-mode">
										<option value="none"><?php esc_html_e( 'No documents required', 'lnkup-marketplace' ); ?></option>
										<option value="platform_defaults"><?php esc_html_e( 'Standard (insurance, permits)', 'lnkup-marketplace' ); ?></option>
									</select>
								</div>
							</div>
							<div id="lnkup-create-vendor-pricing" class="lnkup-create-vendor-pricing" data-fee-rows>
								<input type="hidden" name="opportunities_json" id="lnkup_opportunities_json" value="" />
								<div id="lnkup-fee-rows" class="lnkup-fee-rows" data-fee-rows-list>
									<?php echo self::render_vendor_slot_row( $categories, false, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
								<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" id="lnkup-add-fee-row" data-add-fee-row data-testid="lnkup-add-fee-row"><?php esc_html_e( 'Add another vendor slot', 'lnkup-marketplace' ); ?></button>
								<template id="lnkup-fee-row-template" data-fee-row-template>
									<?php echo self::render_vendor_slot_row( $categories, true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
						<div id="lnkup-create-event-error" class="lnkup-create-event-success" role="alert" hidden></div>
						<button type="submit" form="lnkup-create-event-form" class="lnkup-btn lnkup-btn--primary lnkup-btn--block" id="lnkup-create-event-submit" data-testid="lnkup-publish-event-sidebar"><?php esc_html_e( 'Publish event', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--block" data-save-event-draft data-testid="lnkup-save-event-draft"><?php esc_html_e( 'Save as draft', 'lnkup-marketplace' ); ?></button>
						<p class="lnkup-form-hint lnkup-muted"><?php esc_html_e( 'Drafts are private. Add vendor spots and publish when you are ready.', 'lnkup-marketplace' ); ?></p>
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
		echo '<p class="lnkup-muted lnkup-opp-pricing-empty" id="lnkup-opp-pricing-empty" hidden>' . esc_html__( 'No vendor slots for this event yet. Use Add vendor slot on the event card.', 'lnkup-marketplace' ) . '</p>';
		echo '<table class="lnkup-table"><thead><tr><th>' . esc_html__( 'Event', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Vendor slot', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Quality', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Fee & spots', 'lnkup-marketplace' ) . '</th><th>' . esc_html__( 'Deadline', 'lnkup-marketplace' ) . '</th></tr></thead><tbody>';
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
			echo '<div class="lnkup-empty-state__actions"><a href="' . esc_url( lnkup_organizer_view_url( 'create' ) ) . '" class="lnkup-btn lnkup-btn--primary">' . esc_html__( 'Create event', 'lnkup-marketplace' ) . '</a></div>';
			echo '</div>';
			return ob_get_clean();
		}
		echo self::render_create_opportunity_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="lnkup-muted"><a href="' . esc_url( lnkup_organizer_view_url( 'events' ) ) . '" class="lnkup-link">' . esc_html__( 'Edit fee and spots for existing vendor slots →', 'lnkup-marketplace' ) . '</a></p>';
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
		$back = lnkup_organizer_view_url( 'vendors' );
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
		$indicators       = (array) ( $profile['indicators'] ?? array() );
		$feedback         = (array) ( $indicators['organizer_feedback'] ?? array() );
		$completed_events = (int) ( $indicators['experience']['confirmed_events'] ?? $profile['reliability']['completed_events'] ?? $profile['completed_events'] ?? 0 );
		$avg_rating       = ! empty( $feedback['display_average'] ) ? number_format_i18n( (float) $feedback['average'], 1 ) : (string) ( $feedback['label'] ?? '—' );
		$city_label       = ! empty( $profile['city'] ) ? (string) $profile['city'] : ( ! empty( $profile['location'] ) ? (string) $profile['location'] : '' );
		$has_booked       = (bool) get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_vendor_user_id', 'value' => (string) $profile['vendor_user_id'] ),
					array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) get_current_user_id() ),
					array( 'key' => '_lnkup_application_status', 'value' => array( 'paid', 'completed' ), 'compare' => 'IN' ),
				),
			)
		);
		ob_start();
		?>
		<div class="lnkup-mp lnkup-crm lnkup-crm-profile lnkup-crm-profile--premium" id="lnkup-crm-profile" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>" data-rest="<?php echo esc_url( rest_url( 'lnkup/v1/crm' ) ); ?>">
			<a class="lnkup-page-header__back" href="<?php echo esc_url( $back ); ?>"><span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Vendors', 'lnkup-marketplace' ); ?></a>
			<div class="lnkup-crm-profile__header">
				<?php if ( $profile['logo'] ) : ?><img src="<?php echo esc_url( $profile['logo'] ); ?>" alt="" class="lnkup-crm-profile__logo" /><?php endif; ?>
				<div class="lnkup-page-header__main">
					<h1 class="lnkup-page-header__title"><?php echo esc_html( $profile['business_name'] ); ?></h1>
					<p class="lnkup-page-header__subtitle">
						<?php echo esc_html( $profile['owner_name'] ); ?>
						<?php if ( ! empty( $profile['category'] ) ) : ?> · <?php echo esc_html( implode( ', ', $profile['category'] ) ); ?><?php endif; ?>
						<?php if ( $city_label ) : ?> · <?php echo esc_html( $city_label ); ?><?php endif; ?>
					</p>
					<div class="lnkup-crm-profile__badges">
						<?php if ( LNKup_Capabilities::user_has_manage_all() ) : ?>
							<?php echo LNKup_Reputation_Render::score_badge( $profile['vendor_score'], $profile['reputation_label'] ?? '', ! empty( $profile['reputation']['is_new'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
						<?php if ( ! empty( $indicators['verification']['is_verified'] ) ) : ?>
							<span class="lnkup-badge lnkup-badge--verified" title="<?php echo esc_attr( (string) $indicators['verification']['explanation'] ); ?>"><?php echo esc_html( (string) $indicators['verification']['label'] ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $profile['passport_url'] ) ) : ?>
							<a class="lnkup-badge lnkup-badge--outline" href="<?php echo esc_url( (string) $profile['passport_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View Passport', 'lnkup-marketplace' ); ?></a>
						<?php endif; ?>
						<?php if ( ! empty( $feedback['display_average'] ) ) : ?>
							<?php echo LNKup_Reputation_Render::star_rating( (float) $feedback['average'], (int) $feedback['count'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
						<?php echo LNKup_Reputation_Render::badge_grid( $profile['badges'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php foreach ( $profile['flags'] as $flag ) : ?><span class="lnkup-badge lnkup-badge--destructive"><?php echo esc_html( $flag['label'] ); ?></span><?php endforeach; ?>
					</div>
					<div class="lnkup-crm-tags" data-crm-tags data-testid="lnkup-crm-tags">
						<ul class="lnkup-crm-tags__list" data-crm-tag-list aria-label="<?php esc_attr_e( 'Your tags for this vendor', 'lnkup-marketplace' ); ?>">
							<?php foreach ( (array) $profile['tags'] as $tag ) : ?>
								<li class="lnkup-badge lnkup-badge--secondary" data-tag-slug="<?php echo esc_attr( (string) ( $tag['slug'] ?? '' ) ); ?>">
									<span><?php echo esc_html( (string) $tag['label'] ); ?></span>
									<button type="button" class="lnkup-crm-tags__remove" data-crm-tag-remove="<?php echo esc_attr( (string) ( $tag['slug'] ?? '' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: tag label */ __( 'Remove tag %s', 'lnkup-marketplace' ), (string) $tag['label'] ) ); ?>">&times;</button>
								</li>
							<?php endforeach; ?>
						</ul>
						<form class="lnkup-crm-tags__form" data-crm-tag-form>
							<label class="screen-reader-text" for="lnkup-crm-tag-input"><?php esc_html_e( 'Add a tag', 'lnkup-marketplace' ); ?></label>
							<input type="text" id="lnkup-crm-tag-input" list="lnkup-crm-tag-catalog" maxlength="40" placeholder="<?php esc_attr_e( 'Add a tag', 'lnkup-marketplace' ); ?>" />
							<datalist id="lnkup-crm-tag-catalog">
								<?php foreach ( ( class_exists( 'LNKup_Vendor_Tags' ) ? LNKup_Vendor_Tags::catalog() : array() ) as $tag_slug => $tag_label ) : ?>
									<option value="<?php echo esc_attr( $tag_label ); ?>" data-slug="<?php echo esc_attr( $tag_slug ); ?>"></option>
								<?php endforeach; ?>
							</datalist>
							<button type="submit" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline"><?php esc_html_e( 'Add', 'lnkup-marketplace' ); ?></button>
							<span class="lnkup-type-caption" data-crm-tag-status role="status" aria-live="polite"></span>
						</form>
					</div>
				</div>
				<div class="lnkup-crm-profile__actions">
					<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-crm-fav" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>"><?php echo $profile['is_favorite'] ? '★' : '☆'; ?> <?php esc_html_e( 'Favorite', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-crm-pref<?php echo ! empty( $profile['is_preferred'] ) ? ' is-active' : ''; ?>" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>"><?php esc_html_e( 'Preferred', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm lnkup-crm-invite" data-vendor-id="<?php echo esc_attr( (string) $profile['vendor_user_id'] ); ?>" data-vendor-name="<?php echo esc_attr( $profile['business_name'] ); ?>" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>" data-testid="lnkup-crm-profile-invite"><?php echo $has_booked ? esc_html__( 'Reinvite to an event', 'lnkup-marketplace' ) : esc_html__( 'Invite', 'lnkup-marketplace' ); ?></button>
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
							<p class="lnkup-muted"><button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-crm-timeline-full aria-expanded="false"><?php esc_html_e( 'View full history', 'lnkup-marketplace' ); ?></button></p>
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
						<label class="screen-reader-text" for="lnkup-crm-note-input"><?php esc_html_e( 'Private note', 'lnkup-marketplace' ); ?></label>
						<textarea id="lnkup-crm-note-input" rows="3" placeholder="<?php esc_attr_e( 'Add a private note…', 'lnkup-marketplace' ); ?>"></textarea>
						<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Save note', 'lnkup-marketplace' ); ?></button>
					</form>
					<ul id="lnkup-crm-notes-list" class="lnkup-crm-notes">
						<?php foreach ( LNKup_Vendor_Notes::get_for_vendor( get_current_user_id(), $profile['vendor_user_id'] ) as $note ) : ?>
							<li class="lnkup-crm-note" data-note-id="<?php echo esc_attr( (string) $note['id'] ); ?>">
								<p class="lnkup-crm-note__body" data-note-body><?php echo esc_html( $note['content'] ); ?></p>
								<span class="lnkup-muted lnkup-type-caption"><?php echo esc_html( ! empty( $note['created_at'] ) ? date_i18n( 'M j, Y g:i a', strtotime( (string) $note['created_at'] ) ) : '' ); ?></span>
								<span class="lnkup-crm-note__actions">
									<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-note-edit><?php esc_html_e( 'Edit', 'lnkup-marketplace' ); ?></button>
									<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-note-delete><?php esc_html_e( 'Delete', 'lnkup-marketplace' ); ?></button>
								</span>
							</li>
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
	/**
	 * Listing editor for one event.
	 *
	 * @param WP_Post $event Event post.
	 */
	private static function render_event_edit_form( $event ) {
		$event_terms = get_the_terms( $event->ID, 'lnkup_event_type' );
		ob_start();
		?>
				<form class="lnkup-form lnkup-edit-event-form" id="lnkup-edit-event-<?php echo esc_attr( (string) (int) $event->ID ); ?>" data-edit-event-form="<?php echo esc_attr( (string) (int) $event->ID ); ?>">
					<?php
					wp_nonce_field( 'lnkup_event', 'lnkup_nonce' );
					$selected_type = '';
					if ( $event_terms && ! is_wp_error( $event_terms ) ) {
						foreach ( $event_terms as $assigned_type ) {
							if ( $assigned_type->parent ) {
								$selected_type = $assigned_type->name;
								break;
							}
							if ( '' === $selected_type ) {
								$selected_type = $assigned_type->name;
							}
						}
					}
					$ticketing_url = (string) get_post_meta( $event->ID, '_lnkup_ticketing_url', true );
					if ( 'publish' === $event->post_status && class_exists( 'LNKup_Organizer_Onboarding' ) ) {
						echo '<p><a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="' . esc_url( LNKup_Organizer_Onboarding::event_public_url( (int) $event->ID ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Preview public event', 'lnkup-marketplace' ) . '</a></p>';
					}
					?>
					<input type="hidden" name="action" value="lnkup_update_event" />
					<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) (int) $event->ID ); ?>" />
					<h3 class="lnkup-panel__title"><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></h3>
					<div class="lnkup-grid lnkup-grid--2">
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'Event title', 'lnkup-marketplace' ); ?></label>
							<input type="text" name="event_name" value="<?php echo esc_attr( $event->post_title ); ?>" required />
						</div>
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'Event category', 'lnkup-marketplace' ); ?></label>
							<select name="event_type">
								<option value=""><?php esc_html_e( 'Select category…', 'lnkup-marketplace' ); ?></option>
								<?php foreach ( lnkup_get_event_type_tree() as $type_parent ) : ?>
									<?php if ( empty( $type_parent['children'] ) ) : ?>
										<option value="<?php echo esc_attr( $type_parent['name'] ); ?>" <?php selected( $selected_type, $type_parent['name'] ); ?>><?php echo esc_html( $type_parent['name'] ); ?></option>
									<?php else : ?>
										<?php foreach ( $type_parent['children'] as $type_child ) : ?>
											<option value="<?php echo esc_attr( $type_child['name'] ); ?>" <?php selected( $selected_type, $type_child['name'] ); ?>><?php echo esc_html( $type_parent['name'] . ' · ' . $type_child['name'] ); ?></option>
										<?php endforeach; ?>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></label>
							<input type="date" name="event_date" value="<?php echo esc_attr( (string) get_post_meta( $event->ID, '_lnkup_event_date', true ) ); ?>" />
						</div>
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'Start time', 'lnkup-marketplace' ); ?></label>
							<input type="time" name="start_time" value="<?php echo esc_attr( (string) get_post_meta( $event->ID, '_lnkup_start_time', true ) ); ?>" />
						</div>
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'End time', 'lnkup-marketplace' ); ?></label>
							<input type="time" name="end_time" value="<?php echo esc_attr( (string) get_post_meta( $event->ID, '_lnkup_end_time', true ) ); ?>" />
						</div>
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'Expected attendance', 'lnkup-marketplace' ); ?></label>
							<input type="number" name="expected_attendance" min="0" value="<?php echo esc_attr( (string) get_post_meta( $event->ID, '_lnkup_expected_attendance', true ) ); ?>" />
						</div>
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'Ticketing link', 'lnkup-marketplace' ); ?></label>
							<input type="url" name="ticketing_url" value="<?php echo esc_attr( $ticketing_url ); ?>" placeholder="https://" />
						</div>
					</div>
					<div class="lnkup-form-row">
						<label><?php esc_html_e( 'Description', 'lnkup-marketplace' ); ?></label>
						<textarea name="description" rows="4"><?php echo esc_textarea( $event->post_content ); ?></textarea>
					</div>
					<h3 class="lnkup-panel__title"><?php esc_html_e( 'Venue', 'lnkup-marketplace' ); ?></h3>
					<div class="lnkup-grid lnkup-grid--2">
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'Venue', 'lnkup-marketplace' ); ?></label>
							<input type="text" name="venue_name" value="<?php echo esc_attr( (string) get_post_meta( $event->ID, '_lnkup_venue_name', true ) ); ?>" />
						</div>
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></label>
							<input type="text" name="city" data-lnkup-location-city value="<?php echo esc_attr( (string) get_post_meta( $event->ID, '_lnkup_city', true ) ); ?>" />
						</div>
						<div class="lnkup-form-row">
							<label><?php esc_html_e( 'State', 'lnkup-marketplace' ); ?></label>
							<input type="text" name="state" maxlength="2" data-lnkup-location-state value="<?php echo esc_attr( (string) get_post_meta( $event->ID, '_lnkup_state', true ) ); ?>" />
						</div>
					</div>
					<div class="lnkup-form-row">
						<label><?php esc_html_e( 'Venue address', 'lnkup-marketplace' ); ?></label>
						<textarea name="venue_address" rows="2"><?php echo esc_textarea( (string) get_post_meta( $event->ID, '_lnkup_venue_address', true ) ); ?></textarea>
					</div>
					<div class="lnkup-form-row">
						<label><input type="hidden" name="venue_hidden" value="0" /><input type="checkbox" name="venue_hidden" value="1" <?php checked( (bool) get_post_meta( $event->ID, '_lnkup_venue_hidden', true ) ); ?> /> <?php esc_html_e( 'Hide venue address until vendor payment', 'lnkup-marketplace' ); ?></label>
					</div>
					<h3 class="lnkup-panel__title"><?php esc_html_e( 'For vendors', 'lnkup-marketplace' ); ?></h3>
					<div class="lnkup-form-row">
						<label><input type="hidden" name="indoor" value="0" /><input type="checkbox" name="indoor" value="1" <?php checked( ! empty( get_post_meta( $event->ID, '_lnkup_indoor', true ) ) ); ?> /> <?php esc_html_e( 'Indoor', 'lnkup-marketplace' ); ?></label>
						<label><input type="hidden" name="outdoor" value="0" /><input type="checkbox" name="outdoor" value="1" <?php checked( ! empty( get_post_meta( $event->ID, '_lnkup_outdoor', true ) ) ); ?> /> <?php esc_html_e( 'Outdoor', 'lnkup-marketplace' ); ?></label>
					</div>
					<div class="lnkup-form-row">
						<?php
						$amenity_fields = array(
							'power_available'     => __( 'Power', 'lnkup-marketplace' ),
							'water_available'     => __( 'Water', 'lnkup-marketplace' ),
							'food_truck_allowed'  => __( 'Food trucks', 'lnkup-marketplace' ),
							'tent_allowed'        => __( 'Tents', 'lnkup-marketplace' ),
							'generator_allowed'   => __( 'Generators', 'lnkup-marketplace' ),
							'free_vendor_parking' => __( 'Free vendor parking', 'lnkup-marketplace' ),
						);
						foreach ( $amenity_fields as $amenity_key => $amenity_label ) :
							?>
							<label><input type="hidden" name="<?php echo esc_attr( $amenity_key ); ?>" value="0" /><input type="checkbox" name="<?php echo esc_attr( $amenity_key ); ?>" value="1" <?php checked( ! empty( get_post_meta( $event->ID, '_lnkup_' . $amenity_key, true ) ) ); ?> /> <?php echo esc_html( $amenity_label ); ?></label>
						<?php endforeach; ?>
					</div>
					<?php
					$edit_gallery = lnkup_parse_gallery_ids( get_post_meta( $event->ID, '_lnkup_gallery_ids', true ) );
					echo lnkup_image_upload_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						array(
							'mode'         => 'gallery',
							'label'        => __( 'Photos', 'lnkup-marketplace' ),
							'button_label' => __( 'Add photos', 'lnkup-marketplace' ),
							'input_name'   => 'gallery_ids',
							'input_id'     => 'lnkup-edit-gallery-' . (int) $event->ID,
							'ids'          => $edit_gallery,
							/* translators: %s: event title */
							'alt'          => sprintf( __( '%s photo', 'lnkup-marketplace' ), $event->post_title ),
						)
					);
					?>
					<button type="submit" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Save event', 'lnkup-marketplace' ); ?></button>
				</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Dedicated editor for one organizer event.
	 *
	 * @param int $event_id Event post ID.
	 */
	/**
	 * Event money summary from the canonical payment/transaction records.
	 *
	 * @param int $event_id Event ID.
	 * @return string
	 */
	public static function render_workspace_financials( $event_id ) {
		$event_id = (int) $event_id;
		$money    = LNKup_Organizer_Presentation::event_money( $event_id );
		$report   = class_exists( 'LNKup_Financial_Reports' ) ? (array) LNKup_Financial_Reports::for_event( $event_id ) : array();
		$unpaid   = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_event_id', 'value' => (string) $event_id ),
					array( 'key' => '_lnkup_application_status', 'value' => LNKup_Organizer_Presentation::HELD_STATUSES, 'compare' => 'IN' ),
				),
			)
		);
		ob_start();
		echo LNKup_OS_UI::metrics( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				array( 'label' => __( 'Collected (gross)', 'lnkup-marketplace' ), 'value' => LNKup_OS_UI::money( (float) $money['collected_gross'] ), 'hint' => __( 'What vendors paid, before LNKup fees', 'lnkup-marketplace' ) ),
				array( 'label' => __( 'Outstanding', 'lnkup-marketplace' ), 'value' => LNKup_OS_UI::money( (float) $money['outstanding'] ), 'hint' => __( 'Booth fees of approved vendors who have not paid', 'lnkup-marketplace' ), 'tone' => $money['outstanding'] > 0 ? 'warning' : '' ),
				array( 'label' => __( 'Refunded', 'lnkup-marketplace' ), 'value' => LNKup_OS_UI::money( (float) ( $report['refunds'] ?? 0 ) ) ),
				array(
					'label' => __( 'Paid vendors', 'lnkup-marketplace' ),
					/* translators: 1: paid vendors, 2: vendors awaiting payment */
					'value' => sprintf( __( '%1$d paid · %2$d awaiting', 'lnkup-marketplace' ), (int) $money['paid_bookings'], count( $unpaid ) ),
				),
			),
			'lnkup-event-financials'
		);
		$rows = array();
		foreach ( $unpaid as $app_id ) {
			$opp_id = (int) get_post_meta( (int) $app_id, '_lnkup_opportunity_id', true );
			$rows[] = array(
				'id'    => (int) $app_id,
				'cells' => array(
					'vendor'  => '<span class="vos-table__primary">' . esc_html( LNKup_Organizer_Presentation::vendor_name( (int) $app_id ) ) . '</span>',
					'booth'   => esc_html( $opp_id ? lnkup_plain_title( $opp_id ) : '—' ),
					'fee'     => esc_html( LNKup_OS_UI::money( $opp_id ? (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ) : 0.0 ) ),
					'since'   => LNKup_OS_UI::relative_time( (int) get_post_modified_time( 'U', false, (int) $app_id ) ),
					'actions' => '<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="' . esc_url( lnkup_organizer_view_url( 'applications', array( 'filter' => 'unpaid', 'event_filter' => $event_id, 'app' => (int) $app_id ) ) ) . '">' . esc_html__( 'Send reminder', 'lnkup-marketplace' ) . '</a>',
				),
			);
		}
		?>
		<section class="vos-section" data-testid="lnkup-event-unpaid" aria-labelledby="lnkup-event-unpaid-title">
			<?php echo LNKup_OS_UI::section_head( __( 'Approved, not yet paid', 'lnkup-marketplace' ), __( 'Open Money', 'lnkup-marketplace' ), lnkup_organizer_view_url( 'financial' ), 'lnkup-event-unpaid-title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php
			echo LNKup_OS_UI::data_table( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'vendor'  => array( 'label' => __( 'Vendor', 'lnkup-marketplace' ), 'lead' => true ),
					'booth'   => array( 'label' => __( 'Booth type', 'lnkup-marketplace' ) ),
					'fee'     => array( 'label' => __( 'Booth fee', 'lnkup-marketplace' ), 'class' => 'vos-num' ),
					'since'   => array( 'label' => __( 'Approved', 'lnkup-marketplace' ) ),
					'actions' => array( 'label' => __( 'Actions', 'lnkup-marketplace' ), 'class' => 'vos-actions' ),
				),
				$rows,
				array(
					'caption' => __( 'Approved vendors who have not paid', 'lnkup-marketplace' ),
					'empty'   => LNKup_OS_UI::empty_state( __( 'Everyone approved has paid', 'lnkup-marketplace' ), __( 'Vendors you approve show here until their booth fee is paid.', 'lnkup-marketplace' ) ),
				)
			);
			?>
			<p class="vos-footnote"><?php esc_html_e( 'Event figures are gross booth fees from payment records. Your net after LNKup fees, payouts and cash-outs are on Money.', 'lnkup-marketplace' ); ?></p>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Wave 2 event tabs this viewer can open: readiness and tasks (organizer_ops_v2_enabled, view_roster)
	 * and documents (organizer_document_review_enabled, view_documents).
	 *
	 * @param int $event_id Event.
	 * @return array<string, string> tab => label
	 */
	public static function ops_tabs( $event_id ) {
		$tabs = array();
		if ( LNKup_Feature_Flags::organizer_ops() && LNKup_Organizer_OS_Tables::ready( '2' ) && LNKup_Capabilities::staff_can( $event_id, 'view_roster' ) ) {
			$tabs['readiness'] = __( 'Readiness', 'lnkup-marketplace' );
			$tabs['tasks']     = __( 'Tasks', 'lnkup-marketplace' );
		}
		if ( LNKup_Feature_Flags::document_review() && LNKup_Organizer_OS_Tables::ready( '2' ) && LNKup_Capabilities::staff_can( $event_id, 'view_documents' ) ) {
			$tabs['documents'] = __( 'Documents', 'lnkup-marketplace' );
		}
		return $tabs;
	}

	/**
	 * Mount point for public/js/organizer-ops-*.js (readiness, tasks, documents, mytasks).
	 *
	 * @param int    $event_id Event (0 for My tasks).
	 * @param string $panel    readiness|tasks|documents|mytasks.
	 */
	public static function render_ops_panel( $event_id, $panel ) {
		return '<section class="lnkup-ops2" data-ops-panel="' . esc_attr( $panel ) . '" data-event-id="' . esc_attr( (string) absint( $event_id ) ) . '" data-testid="lnkup-ops2-' . esc_attr( $panel ) . '" aria-live="polite">' . lnkup_loading_state( 'list' ) . '</section>';
	}

	private static function render_event_workspace( $event_id ) {
		if ( class_exists( 'LNKup_Organizer_Workspace_View' ) && class_exists( 'LNKup_OS_UI' ) ) {
			return LNKup_Organizer_Workspace_View::render( (int) $event_id );
		}
		$event_id = (int) $event_id;
		$event    = get_post( $event_id );
		if ( ! $event || 'lnkup_event' !== $event->post_type || ! LNKup_Capabilities::organizer_owns_event( $event_id ) ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'You cannot open this event.', 'lnkup-marketplace' ) . '</p>';
		}
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ops_tabs = self::ops_tabs( $event_id );
		if ( ! in_array( $tab, array_merge( array( 'overview', 'slots', 'requests', 'vendors', 'financials' ), array_keys( $ops_tabs ) ), true ) ) {
			$tab = 'overview';
		}
		$can_financials = LNKup_Capabilities::can_manage_event_financials( $event_id );
		if ( 'financials' === $tab && ! $can_financials ) {
			$tab = 'overview';
		}
		$dash_url = lnkup_page_url_helper( 'organizer-dashboard' );
		$base     = add_query_arg( 'event', $event_id, $dash_url );
		$edit_url = add_query_arg( array( 'event' => $event_id, 'edit' => 1 ), $dash_url );
		$public   = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::event_public_url( $event_id ) : '';
		$date     = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$city     = (string) get_post_meta( $event_id, '_lnkup_city', true );
		$state    = (string) get_post_meta( $event_id, '_lnkup_state', true );
		$venue    = (string) get_post_meta( $event_id, '_lnkup_venue_name', true );
		$attend   = (string) get_post_meta( $event_id, '_lnkup_expected_attendance', true );
		$where    = trim( $city . ( $state ? ', ' . $state : '' ) );
		$status   = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::sync_event_listing_status( $event_id ) : 'draft';
		$is_past  = $date && strtotime( $date ) < strtotime( 'today' );
		$status_label = $is_past ? __( 'Completed', 'lnkup-marketplace' ) : ( 'live' === $status ? __( 'Live', 'lnkup-marketplace' ) : __( 'Draft', 'lnkup-marketplace' ) );
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		$counts = array(
			'requests' => count( $apps ),
			'pending'  => 0,
			'approved' => 0,
			'awaiting' => 0,
			'paid'     => 0,
		);
		$vendor_apps = array();
		foreach ( $apps as $app ) {
			$app_status = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( in_array( $app_status, array( 'submitted', 'under_review' ), true ) ) {
				$counts['pending']++;
			}
			if ( 'approved' === $app_status ) {
				$counts['approved']++;
			} elseif ( 'payment_required' === $app_status ) {
				$counts['awaiting']++;
			} elseif ( in_array( $app_status, array( 'paid', 'completed' ), true ) ) {
				$counts['paid']++;
			}
			if ( in_array( $app_status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) ) {
				$vendor_apps[] = $app;
			}
		}
		$slots = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		$tabs = array(
			'overview' => __( 'Overview', 'lnkup-marketplace' ),
			'slots'    => __( 'Slots', 'lnkup-marketplace' ),
			'requests' => __( 'Applications', 'lnkup-marketplace' ),
			'vendors'  => __( 'Confirmed vendors', 'lnkup-marketplace' ),
		);
		if ( $can_financials ) {
			$tabs['financials'] = __( 'Money', 'lnkup-marketplace' );
		}
		$tabs          = array_merge( $tabs, $ops_tabs );
		$switch_events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => 50,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => get_current_user_id(),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		ob_start();
		?>
		<div class="lnkup-event-workspace-page">
			<?php
			$switcher = '';
			if ( count( $switch_events ) > 1 ) {
				$switcher .= '<label class="screen-reader-text" for="lnkup-event-switcher">' . esc_html__( 'Switch event', 'lnkup-marketplace' ) . '</label>';
				$switcher .= '<select id="lnkup-event-switcher" class="lnkup-event-switcher" onchange="if (this.value) { window.location.href = this.value; }">';
				foreach ( $switch_events as $switch_event ) {
					$switcher .= '<option value="' . esc_url( add_query_arg( array( 'event' => (int) $switch_event->ID, 'tab' => $tab ), $dash_url ) ) . '"' . selected( (int) $switch_event->ID, $event_id, false ) . '>' . esc_html( $switch_event->post_title ) . '</option>';
				}
				$switcher .= '</select>';
			}
			$meta_parts = array(
				'<span class="lnkup-status-pill' . ( 'live' === $status && ! $is_past ? ' lnkup-status-pill--success' : ' lnkup-status-pill--muted' ) . '">' . esc_html( $status_label ) . '</span>',
			);
			if ( $date ) {
				$meta_parts[] = '<span>' . esc_html( date_i18n( 'M j, Y', strtotime( $date ) ) ) . '</span>';
			}
			if ( $where ) {
				$meta_parts[] = '<span>' . esc_html( $where ) . '</span>';
			}
			$meta_parts[] = $switcher;
			$workspace_actions = '<a class="lnkup-btn lnkup-btn--outline" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit event', 'lnkup-marketplace' ) . '</a>';
			if ( $is_past && $counts['paid'] > 0 ) {
				$rebook = array();
				foreach ( $vendor_apps as $vendor_app ) {
					if ( ! in_array( (string) get_post_meta( $vendor_app->ID, '_lnkup_application_status', true ), array( 'paid', 'completed' ), true ) ) {
						continue;
					}
					$vendor_id            = (int) get_post_meta( $vendor_app->ID, '_lnkup_vendor_user_id', true );
					$rebook[ $vendor_id ] = array(
						'vendor_user_id' => $vendor_id,
						'business_name'  => LNKup_Event_Operations::vendor_name_for_app( $vendor_app->ID ),
					);
				}
				$workspace_actions .= '<button type="button" class="lnkup-btn lnkup-btn--primary" data-rebook-vendors="' . esc_attr( wp_json_encode( array_values( $rebook ) ) ) . '" data-testid="lnkup-rebook-vendors">' . esc_html(
					/* translators: %d: number of vendors */
					sprintf( _n( 'Rebook %d vendor', 'Rebook %d vendors', count( $rebook ), 'lnkup-marketplace' ), count( $rebook ) )
				) . '</button>';
			}
			if ( $public ) {
				$workspace_actions .= '<a class="lnkup-btn lnkup-btn--outline" href="' . esc_url( $public ) . '">' . esc_html__( 'Public page', 'lnkup-marketplace' ) . '</a>';
			}
			/**
			 * Extra event workspace header actions (escaped HTML), e.g. Communications.
			 *
			 * @param string $workspace_actions Actions HTML.
			 * @param int    $event_id          Event ID.
			 */
			$workspace_actions = (string) apply_filters( 'lnkup_event_workspace_actions', $workspace_actions, $event_id );
			echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title'      => $event->post_title,
					'back_url'   => lnkup_organizer_view_url( 'events' ),
					'back_label' => __( 'Events', 'lnkup-marketplace' ),
					'meta'       => implode( '', array_filter( $meta_parts ) ),
					'actions'    => $workspace_actions,
				)
			);
			echo lnkup_stat_strip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					array( 'label' => __( 'Needs review', 'lnkup-marketplace' ), 'value' => (string) $counts['pending'] ),
					array( 'label' => __( 'Owes a fee', 'lnkup-marketplace' ), 'value' => (string) $counts['awaiting'] ),
					array( 'label' => __( 'Confirmed', 'lnkup-marketplace' ), 'value' => (string) $counts['paid'] ),
				)
			);
			if ( $counts['pending'] > 0 && 'requests' !== $tab ) {
				echo '<p class="lnkup-event-workspace__queue"><a class="lnkup-btn lnkup-btn--primary" href="' . esc_url( add_query_arg( 'tab', 'requests', $base ) ) . '">' . esc_html( sprintf( /* translators: %d: applications waiting */ _n( 'Review %d application', 'Review %d applications', $counts['pending'], 'lnkup-marketplace' ), $counts['pending'] ) ) . '</a></p>';
			}
			$workspace_tabs = array();
			foreach ( $tabs as $key => $label ) {
				$workspace_tabs[] = array(
					'id'    => $key,
					'label' => $label,
					'href'  => add_query_arg( 'tab', $key, $base ),
					'badge' => 'requests' === $key ? $counts['pending'] : null,
				);
			}
			$workspace_tabs[] = array(
				'id'    => 'operations',
				'label' => __( 'Operations', 'lnkup-marketplace' ),
				'href'  => add_query_arg( array( 'event' => $event_id, 'ops' => 1 ), $dash_url ),
			);
			$workspace_tabs[] = array(
				'id'    => 'checkin',
				'label' => __( 'Check-in', 'lnkup-marketplace' ),
				'href'  => add_query_arg( array( 'event' => $event_id, 'scan' => 1 ), $dash_url ),
			);
			echo lnkup_dash_tabs( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$workspace_tabs,
				$tab,
				array(
					'variant' => 'pills',
					'class'   => 'lnkup-event-workspace__tabs',
					'testid'  => 'lnkup-event-workspace-tabs',
					'label'   => __( 'Event sections', 'lnkup-marketplace' ),
				)
			);
			?>
			<?php if ( isset( $ops_tabs[ $tab ] ) ) : ?>
				<?php echo self::render_ops_panel( $event_id, $tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php elseif ( 'overview' === $tab ) : ?>
				<?php
				$readiness = class_exists( 'LNKup_Vendor_Readiness' ) ? LNKup_Vendor_Readiness::event_readiness_summary( $event_id ) : array();
				$spots     = self::organizer_event_spots( $event_id );
				$at_risk   = array_slice(
					array_values(
						array_filter(
							(array) ( $readiness['vendors'] ?? array() ),
							static function ( $row ) {
								return class_exists( 'LNKup_Vendor_Readiness' ) && LNKup_Vendor_Readiness::TIER_READY !== ( $row['tier'] ?? '' );
							}
						)
					),
					0,
					3
				);
				$unpaid    = class_exists( 'LNKup_Organizer_Dashboard_Views' ) ? count( LNKup_Organizer_Dashboard_Views::unpaid_ids( get_current_user_id(), $event_id ) ) : $counts['awaiting'];
				$next_step = self::workspace_next_step( $event_id, $status, $is_past, $date, count( $slots ), $counts['pending'], $unpaid, $base, $edit_url );
				$roster_url = add_query_arg( array( 'event' => $event_id, 'roster' => 1 ), $dash_url );
				$ops_url    = add_query_arg( array( 'event' => $event_id, 'ops' => 1 ), $dash_url );
				$scan_url   = add_query_arg( array( 'event' => $event_id, 'scan' => 1 ), $dash_url );
				?>
				<div class="lnkup-event-command" data-testid="lnkup-event-command">
					<div class="lnkup-event-command__main">
						<?php if ( $next_step ) : ?>
							<a class="lnkup-home-next lnkup-home-next--row lnkup-event-command__next" href="<?php echo esc_url( $next_step['href'] ); ?>" data-testid="lnkup-event-next-step">
								<span class="lnkup-type-label"><?php esc_html_e( 'Next step', 'lnkup-marketplace' ); ?></span>
								<strong><?php echo esc_html( $next_step['label'] ); ?></strong>
								<span class="lnkup-home-next__go" aria-hidden="true">&rarr;</span>
							</a>
						<?php endif; ?>
						<section class="lnkup-event-command__card" data-testid="lnkup-event-readiness">
							<h2 class="lnkup-event-command__title"><?php esc_html_e( 'Vendor readiness', 'lnkup-marketplace' ); ?></h2>
							<?php if ( empty( $readiness['vendor_count'] ) ) : ?>
								<p class="lnkup-muted"><?php esc_html_e( 'Readiness appears once you accept vendors.', 'lnkup-marketplace' ); ?></p>
							<?php else : ?>
								<p class="lnkup-event-command__big"><?php echo esc_html( sprintf( /* translators: %d: percent */ __( '%d%% ready', 'lnkup-marketplace' ), (int) $readiness['percent'] ) ); ?></p>
								<div class="lnkup-vendor-strength-card__bar" role="progressbar" aria-label="<?php esc_attr_e( 'Average readiness of paid vendors', 'lnkup-marketplace' ); ?>" aria-valuenow="<?php echo esc_attr( (string) (int) $readiness['percent'] ); ?>" aria-valuemin="0" aria-valuemax="100">
									<span class="lnkup-vendor-strength-card__fill" style="width:<?php echo esc_attr( (string) (int) $readiness['percent'] ); ?>%"></span>
								</div>
								<p class="lnkup-type-caption">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: ready, 2: on track, 3: at risk */
											__( '%1$d ready · %2$d on track · %3$d at risk', 'lnkup-marketplace' ),
											(int) $readiness['ready_count'],
											(int) $readiness['on_track_count'],
											(int) $readiness['at_risk_count']
										)
									);
									?>
								</p>
								<?php if ( $at_risk ) : ?>
									<ul class="lnkup-event-command__risks">
										<?php foreach ( $at_risk as $row ) : ?>
											<li>
												<strong><?php echo esc_html( (string) $row['business_name'] ); ?></strong>
												<span class="lnkup-muted"><?php echo esc_html( $row['blocker'] ? (string) $row['blocker'] : (string) $row['tier_label'] ); ?></span>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							<?php endif; ?>
						</section>
						<section class="lnkup-event-command__card">
							<h2 class="lnkup-event-command__title"><?php esc_html_e( 'Spots', 'lnkup-marketplace' ); ?></h2>
							<?php if ( $spots['total'] < 1 ) : ?>
								<p class="lnkup-muted"><?php esc_html_e( 'No vendor slots yet.', 'lnkup-marketplace' ); ?></p>
								<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" href="<?php echo esc_url( add_query_arg( 'tab', 'slots', $base ) ); ?>"><?php esc_html_e( 'Add a slot', 'lnkup-marketplace' ); ?></a>
							<?php else : ?>
								<p class="lnkup-event-command__big"><?php echo esc_html( sprintf( /* translators: 1: filled, 2: total */ __( '%1$d of %2$d filled', 'lnkup-marketplace' ), (int) $spots['filled'], (int) $spots['total'] ) ); ?></p>
								<div class="lnkup-vendor-strength-card__bar" role="progressbar" aria-label="<?php esc_attr_e( 'Spots filled', 'lnkup-marketplace' ); ?>" aria-valuenow="<?php echo esc_attr( (string) (int) $spots['percent'] ); ?>" aria-valuemin="0" aria-valuemax="100">
									<span class="lnkup-vendor-strength-card__fill" style="width:<?php echo esc_attr( (string) (int) $spots['percent'] ); ?>%"></span>
								</div>
								<p class="lnkup-type-caption"><?php echo esc_html( sprintf( /* translators: 1: remaining, 2: slots */ _n( '%1$d spot left across %2$d slot', '%1$d spots left across %2$d slots', (int) $spots['slots'], 'lnkup-marketplace' ), (int) $spots['remaining'], (int) $spots['slots'] ) ); ?></p>
							<?php endif; ?>
						</section>
						<nav class="lnkup-event-command__shortcuts" aria-label="<?php esc_attr_e( 'Event tools', 'lnkup-marketplace' ); ?>">
							<a class="lnkup-btn lnkup-btn--outline" href="<?php echo esc_url( $roster_url ); ?>"><?php esc_html_e( 'Roster', 'lnkup-marketplace' ); ?></a>
							<a class="lnkup-btn lnkup-btn--outline" href="<?php echo esc_url( $ops_url ); ?>"><?php esc_html_e( 'Operations', 'lnkup-marketplace' ); ?></a>
							<a class="lnkup-btn lnkup-btn--outline" href="<?php echo esc_url( $scan_url ); ?>"><?php esc_html_e( 'QR check-in', 'lnkup-marketplace' ); ?></a>
						</nav>
					</div>
					<section class="lnkup-event-workspace__facts">
						<p><span><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></span><?php echo esc_html( $date ? date_i18n( 'M j, Y', strtotime( $date ) ) : '—' ); ?></p>
						<p><span><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></span><?php echo esc_html( $where ?: '—' ); ?></p>
						<?php if ( $venue ) : ?><p><span><?php esc_html_e( 'Venue', 'lnkup-marketplace' ); ?></span><?php echo esc_html( $venue ); ?></p><?php endif; ?>
						<?php if ( $attend ) : ?><p><span><?php esc_html_e( 'Attendance', 'lnkup-marketplace' ); ?></span><?php echo esc_html( $attend ); ?></p><?php endif; ?>
						<p class="lnkup-event-workspace__actions">
							<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'lnkup-marketplace' ); ?></a>
							<?php if ( $public ) : ?>
								<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( $public ); ?>"><?php esc_html_e( 'Public page', 'lnkup-marketplace' ); ?></a>
								<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-copy-url="<?php echo esc_url( $public ); ?>"><?php esc_html_e( 'Copy link', 'lnkup-marketplace' ); ?></button>
							<?php endif; ?>
						</p>
					</section>
				</div>
			<?php elseif ( 'slots' === $tab ) : ?>
				<?php
				$slot_editor = self::render_portfolio_vendor_slots( $event_id );
				if ( ! $slot_editor ) :
					echo lnkup_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						array(
							'title'   => __( 'No vendor slots yet', 'lnkup-marketplace' ),
							'desc'    => __( 'Add a vendor slot so vendors can apply to this event.', 'lnkup-marketplace' ),
							'compact' => true,
						)
					);
					echo self::render_add_vendor_slot_form( $event_id, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				<?php else : ?>
					<?php echo $slot_editor; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			<?php elseif ( 'financials' === $tab ) : ?>
				<?php echo self::render_workspace_financials( $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php elseif ( 'requests' === $tab || 'vendors' === $tab ) : ?>
				<p class="lnkup-event-workspace__actions">
					<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" href="<?php echo esc_url( self::organizer_vendor_export_url( $event_id ) ); ?>"><?php esc_html_e( 'Export contacts', 'lnkup-marketplace' ); ?></a>
				</p>
				<?php
				echo self::render_applications_manager( 'vendors' === $tab ? $vendor_apps : $apps, array( 'selectable' => 'requests' === $tab ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * The single most useful next action for an event, in launch order.
	 *
	 * @param int    $event_id   Event ID.
	 * @param string $status     Listing status (live or draft).
	 * @param bool   $is_past    Whether the event date has passed.
	 * @param string $date       Event date.
	 * @param int    $slot_count Number of vendor slots.
	 * @param int    $pending    Requests waiting for review.
	 * @param int    $unpaid     Accepted vendors who have not paid.
	 * @param string $base       Workspace URL.
	 * @param string $edit_url   Edit URL.
	 * @return array{label:string,href:string}|null
	 */
	private static function workspace_next_step( $event_id, $status, $is_past, $date, $slot_count, $pending, $unpaid, $base, $edit_url ) {
		if ( $is_past ) {
			return null;
		}
		$dash_url = lnkup_page_url_helper( 'organizer-dashboard' );
		if ( $slot_count < 1 ) {
			return array( 'label' => __( 'Add a vendor slot so vendors can apply', 'lnkup-marketplace' ), 'href' => add_query_arg( 'tab', 'slots', $base ) );
		}
		if ( 'live' !== $status ) {
			return array( 'label' => __( 'Publish your event listing', 'lnkup-marketplace' ), 'href' => $edit_url );
		}
		if ( $pending > 0 ) {
			return array(
				/* translators: %d: pending requests */
				'label' => sprintf( _n( 'Review %d pending request', 'Review %d pending requests', $pending, 'lnkup-marketplace' ), $pending ),
				'href'  => add_query_arg( 'tab', 'requests', $base ),
			);
		}
		if ( $unpaid > 0 ) {
			return array(
				/* translators: %d: unpaid vendors */
				'label' => sprintf( _n( 'Follow up with %d unpaid vendor', 'Follow up with %d unpaid vendors', $unpaid, 'lnkup-marketplace' ), $unpaid ),
				'href'  => lnkup_organizer_view_url( 'applications', array( 'filter' => 'unpaid', 'event_filter' => $event_id ) ),
			);
		}
		$ts = $date ? strtotime( $date ) : false;
		if ( $ts && $ts <= strtotime( '+1 day', strtotime( 'today' ) ) ) {
			return array( 'label' => __( 'Open QR check-in for event day', 'lnkup-marketplace' ), 'href' => add_query_arg( array( 'event' => $event_id, 'scan' => 1 ), $dash_url ) );
		}
		return array( 'label' => __( 'Invite more vendors', 'lnkup-marketplace' ), 'href' => lnkup_organizer_view_url( 'vendors' ) );
	}

	private static function render_event_edit_view( $event_id ) {
		$event_id = (int) $event_id;
		$event    = get_post( $event_id );
		$user_id  = get_current_user_id();
		$owns     = $event
			&& 'lnkup_event' === $event->post_type
			&& class_exists( 'LNKup_Capabilities' )
			&& LNKup_Capabilities::user_owns_post( $event_id, $user_id, '_lnkup_organizer_user_id' );
		if ( ! $owns ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'You cannot edit this event.', 'lnkup-marketplace' ) . '</p>';
		}
		$dash_url = lnkup_page_url_helper( 'organizer-dashboard' );
		$public   = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::event_public_url( $event_id ) : '';
		$date     = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$city     = (string) get_post_meta( $event_id, '_lnkup_city', true );
		$state    = (string) get_post_meta( $event_id, '_lnkup_state', true );
		$attend   = (string) get_post_meta( $event_id, '_lnkup_expected_attendance', true );
		$where    = trim( $city . ( $state ? ', ' . $state : '' ) );
		$venue    = (string) get_post_meta( $event_id, '_lnkup_venue_name', true );
		$apps     = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		$stats = array(
			'requests'         => 0,
			'approved'         => 0,
			'payment_required' => 0,
			'paid'             => 0,
		);
		$pending_count = 0;
		foreach ( $apps as $app ) {
			$app_status = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
			$stats['requests']++;
			if ( in_array( $app_status, array( 'submitted', 'under_review' ), true ) ) {
				++$pending_count;
			}
			if ( 'approved' === $app_status ) {
				$stats['approved']++;
			} elseif ( 'payment_required' === $app_status ) {
				$stats['payment_required']++;
			} elseif ( in_array( $app_status, array( 'paid', 'completed' ), true ) ) {
				$stats['paid']++;
			}
		}
		$workspace = add_query_arg( 'event', $event_id, $dash_url );
		ob_start();
		?>
		<div class="lnkup-mp lnkup-event-edit lnkup-event-workspace" data-testid="lnkup-event-edit">
			<div class="lnkup-event-workspace__main">
			<?php
			$edit_meta = '';
			if ( $date ) {
				$edit_meta .= '<span>' . esc_html( date_i18n( 'M j, Y', strtotime( $date ) ) ) . '</span>';
			}
			if ( $where ) {
				$edit_meta .= '<span>' . esc_html( $where ) . '</span>';
			}
			echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title'      => $event->post_title,
					'eyebrow'    => __( 'Edit event', 'lnkup-marketplace' ),
					'back_url'   => $workspace,
					'back_label' => __( 'Back to event', 'lnkup-marketplace' ),
					'meta'       => $edit_meta,
					'actions'    => $public ? '<a class="lnkup-btn lnkup-btn--outline" href="' . esc_url( $public ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Public page', 'lnkup-marketplace' ) . '</a>' : '',
				)
			);
			echo lnkup_stat_strip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					array( 'label' => __( 'Requests', 'lnkup-marketplace' ), 'value' => (string) $stats['requests'] ),
					array( 'label' => __( 'Approved', 'lnkup-marketplace' ), 'value' => (string) $stats['approved'] ),
					array( 'label' => __( 'Payment required', 'lnkup-marketplace' ), 'value' => (string) $stats['payment_required'] ),
					array( 'label' => __( 'Paid / booked', 'lnkup-marketplace' ), 'value' => (string) $stats['paid'] ),
				)
			);
			?>
			<nav class="lnkup-dash-tabs lnkup-dash-tabs--pills" data-edit-tabs aria-label="<?php esc_attr_e( 'Event editor sections', 'lnkup-marketplace' ); ?>">
				<?php
				foreach (
					array(
						'overview' => __( 'Overview', 'lnkup-marketplace' ),
						'slots'    => __( 'Vendor slots', 'lnkup-marketplace' ),
					) as $edit_tab => $edit_label
				) :
					?>
					<button type="button" class="lnkup-dash-tabs__btn<?php echo 'overview' === $edit_tab ? ' is-active' : ''; ?>" aria-pressed="<?php echo 'overview' === $edit_tab ? 'true' : 'false'; ?>" data-edit-tab="<?php echo esc_attr( $edit_tab ); ?>"><?php echo esc_html( $edit_label ); ?></button>
				<?php endforeach; ?>
				<a class="lnkup-dash-tabs__btn" href="<?php echo esc_url( add_query_arg( 'tab', 'requests', $workspace ) ); ?>">
					<?php esc_html_e( 'Requests', 'lnkup-marketplace' ); ?>
					<?php if ( $pending_count ) : ?>
						<span class="lnkup-dash-tabs__badge lnkup-dash-tabs__badge--pending"><?php echo esc_html( (string) $pending_count ); ?></span>
					<?php endif; ?>
				</a>
				<a class="lnkup-dash-tabs__btn" href="<?php echo esc_url( add_query_arg( 'tab', 'vendors', $workspace ) ); ?>"><?php esc_html_e( 'Vendors', 'lnkup-marketplace' ); ?></a>
			</nav>
			<div data-edit-panel="overview" id="lnkup-event-edit-form">
				<?php echo self::render_event_edit_form( $event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<div data-edit-panel="slots" hidden>
				<?php echo self::render_add_vendor_slot_form( $event_id, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			</div>
			<aside class="lnkup-event-workspace__facts">
				<h2><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></h2>
				<?php if ( $date ) : ?><p><span><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></span><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $date ) ) ); ?></p><?php endif; ?>
				<?php if ( $where ) : ?><p><span><?php esc_html_e( 'City', 'lnkup-marketplace' ); ?></span><?php echo esc_html( $where ); ?></p><?php endif; ?>
				<?php if ( $venue ) : ?><p><span><?php esc_html_e( 'Venue', 'lnkup-marketplace' ); ?></span><?php echo esc_html( $venue ); ?></p><?php endif; ?>
				<?php if ( $public ) : ?>
					<p><span><?php esc_html_e( 'Public link', 'lnkup-marketplace' ); ?></span><a href="<?php echo esc_url( $public ); ?>"><?php echo esc_html( $public ); ?></a></p>
					<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-copy-url="<?php echo esc_url( $public ); ?>"><?php esc_html_e( 'Copy', 'lnkup-marketplace' ); ?></button>
				<?php endif; ?>
			</aside>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function render_event_roster_view( $event_id ) {
		$roster = LNKup_Event_Roster::get( $event_id, get_current_user_id() );
		if ( is_wp_error( $roster ) ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html( $roster->get_error_message() ) . '</p>';
		}
		$event    = get_post( $event_id );
		$back     = lnkup_organizer_view_url( 'events' );
		$is_empty = (int) ( $roster['total'] ?? 0 ) < 1;
		$is_live  = class_exists( 'LNKup_Organizer_Onboarding' ) && LNKup_Organizer_Onboarding::event_has_open_slot( (int) $event_id );
		ob_start();
		?>
		<div class="lnkup-mp lnkup-crm" id="lnkup-event-roster" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>" data-rest="<?php echo esc_url( rest_url( 'lnkup/v1/crm' ) ); ?>">
			<?php
			echo self::event_detail_head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				(int) $event_id,
				'vendors',
				__( 'Vendor roster', 'lnkup-marketplace' ),
				__( 'Approve, check in, and close out vendors for this event.', 'lnkup-marketplace' ),
				'<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="' . esc_url( self::organizer_vendor_export_url( (int) $event_id ) ) . '">' . esc_html__( 'Export contacts', 'lnkup-marketplace' ) . '</a>',
				$event ? $event->post_title : ''
			);
			?>
			<?php if ( ! $is_empty ) : ?>
			<div class="lnkup-roster-bulk" data-testid="lnkup-roster-bulk">
				<label class="lnkup-roster-bulk__all"><input type="checkbox" id="lnkup-roster-select-all" /> <?php esc_html_e( 'Select all visible', 'lnkup-marketplace' ); ?></label>
				<span class="lnkup-roster-bulk__count" id="lnkup-roster-selected-count" data-testid="lnkup-roster-selected-count" aria-live="polite"><?php esc_html_e( '0 selected', 'lnkup-marketplace' ); ?></span>
				<div class="lnkup-roster-bulk__group" role="group" aria-label="<?php esc_attr_e( 'Booking decisions', 'lnkup-marketplace' ); ?>">
					<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" data-roster-bulk="approve" disabled><?php esc_html_e( 'Approve', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--sm" data-roster-bulk="waitlist" disabled><?php esc_html_e( 'Waitlist', 'lnkup-marketplace' ); ?></button>
				</div>
				<div class="lnkup-roster-bulk__group" role="group" aria-label="<?php esc_attr_e( 'Event day', 'lnkup-marketplace' ); ?>">
					<button type="button" class="lnkup-btn lnkup-btn--sm" data-roster-bulk="check_in" disabled><?php esc_html_e( 'Check in', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-roster-bulk="no_show" disabled><?php esc_html_e( 'No-show', 'lnkup-marketplace' ); ?></button>
					<button type="button" class="lnkup-btn lnkup-btn--sm" data-roster-bulk="mark_complete" disabled><?php esc_html_e( 'Mark complete', 'lnkup-marketplace' ); ?></button>
				</div>
			</div>
			<?php endif; ?>
			<div id="lnkup-roster-content">
				<?php if ( $is_empty ) : ?>
					<?php
					$roster_desc = __( 'Vendors appear here after they apply and you approve them. This screen is for check-in and day-of, not for finding vendors.', 'lnkup-marketplace' );
					if ( ! $is_live ) {
						$roster_desc .= ' ' . __( 'Vendors cannot apply until a vendor slot is published.', 'lnkup-marketplace' );
					}
					echo lnkup_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						array(
							'title'               => __( 'No vendors on this roster yet', 'lnkup-marketplace' ),
							'desc'                => $roster_desc,
							'cta_label'           => __( 'Review requests', 'lnkup-marketplace' ),
							'cta_href'            => lnkup_organizer_view_url( 'applications', array( 'event_filter' => (int) $event_id, 'filter' => 'pending' ) ),
							'secondary_cta_label' => $is_live ? __( 'Discover vendors', 'lnkup-marketplace' ) : __( 'Publish event', 'lnkup-marketplace' ),
							'secondary_cta_href'  => $is_live ? lnkup_organizer_view_url( 'vendors' ) : $back,
						)
					);
					?>
				<?php else : ?>
					<p class="lnkup-muted oos-roster-help"><?php esc_html_e( 'Readiness columns cover booked vendors (approved or paid). Packet, booth and pass unlock after payment.', 'lnkup-marketplace' ); ?></p>
					<?php echo $roster['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Heading for event detail routes (operations, roster, check-in). Owners get the event workspace
	 * header and tabs; team members without ownership keep the standalone page header.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $tab      Workspace tab to mark active.
	 * @param string $title    Section title.
	 * @param string $subtitle Section subtitle.
	 * @param string $actions  Escaped action HTML.
	 * @param string $eyebrow  Event title for the fallback header.
	 */
	public static function event_detail_head( int $event_id, string $tab, string $title, string $subtitle, string $actions, string $eyebrow ): string {
		$chrome = class_exists( 'LNKup_Organizer_Workspace_View' ) ? LNKup_Organizer_Workspace_View::chrome( $event_id, $tab ) : '';
		if ( '' === $chrome ) {
			return lnkup_page_header(
				array(
					'title'      => $title,
					'eyebrow'    => $eyebrow,
					'subtitle'   => $subtitle,
					'back_url'   => add_query_arg( 'event', $event_id, lnkup_page_url_helper( 'organizer-dashboard' ) ),
					'back_label' => __( 'Back to event', 'lnkup-marketplace' ),
					'actions'    => $actions,
				)
			);
		}
		return $chrome . '<div class="oos-detail-head"><div><h2 class="oos-detail-head__title">' . esc_html( $title ) . '</h2><p class="lnkup-muted">' . esc_html( $subtitle ) . '</p></div>'
			. ( '' !== $actions ? '<div class="oos-detail-head__actions">' . $actions . '</div>' : '' ) . '</div>';
	}

	/**
	 * Event operations command center.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function render_event_operations_view( $event_id ) {
		if ( ! LNKup_Capabilities::staff_can( $event_id, 'view_roster' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
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
		$booked_vendors = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_event_id', 'value' => (string) (int) $event_id ),
					array( 'key' => '_lnkup_application_status', 'value' => array( 'approved', 'payment_required', 'paid', 'completed' ), 'compare' => 'IN' ),
				),
			)
		) as $booked_id ) {
			$booked_status    = (string) get_post_meta( (int) $booked_id, '_lnkup_application_status', true );
			$booked_vendors[] = array(
				'application_id' => (int) $booked_id,
				'name'           => class_exists( 'LNKup_Organizer_Presentation' ) ? LNKup_Organizer_Presentation::vendor_name( (int) $booked_id ) : LNKup_Event_Operations::vendor_name_for_app( (int) $booked_id ),
				'paid'           => in_array( $booked_status, array( 'paid', 'completed' ), true ),
			);
		}
		ob_start();
		?>
		<div class="lnkup-mp lnkup-ops" id="lnkup-event-ops" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>" data-pass-packet-id="<?php echo esc_attr( (string) $pass_packet_id ); ?>" data-booked-vendors="<?php echo esc_attr( (string) wp_json_encode( $booked_vendors ) ); ?>">
			<?php
			$ops_actions = '<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="' . esc_url( add_query_arg( array( 'event' => $event_id, 'roster' => 1 ), lnkup_page_url_helper( 'organizer-dashboard' ) ) ) . '">' . esc_html__( 'Roster', 'lnkup-marketplace' ) . '</a>'
				. '<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="' . esc_url( $scan_url ) . '">' . esc_html__( 'QR check-in', 'lnkup-marketplace' ) . '</a>';
			echo self::event_detail_head( (int) $event_id, 'operations', __( 'Operations', 'lnkup-marketplace' ), __( 'Readiness, logistics, maps, and event-day support in one place.', 'lnkup-marketplace' ), $ops_actions, $event ? $event->post_title : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<div id="lnkup-ops-kpis" class="lnkup-ops-kpis-wrap"><?php echo LNKup_Operations_Render::kpi_row( $snap['kpis'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div id="lnkup-ops-lifecycle"><?php echo LNKup_Operations_Render::lifecycle_bar( $snap['lifecycle'], (int) $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<?php
			$ops_tab_defs  = array(
				'overview'    => array( 'command', __( 'Overview', 'lnkup-marketplace' ), 'lnkup-ops-overview-panel' ),
				'readiness'   => array( 'command', __( 'Readiness', 'lnkup-marketplace' ), 'lnkup-ops-readiness-panel' ),
				'kanban'      => array( 'command', __( 'Roster / Kanban', 'lnkup-marketplace' ), 'lnkup-ops-kanban-wrap' ),
				'booths'      => array( 'logistics', __( 'Booths', 'lnkup-marketplace' ), 'lnkup-ops-booths-panel' ),
				'parking'     => array( 'logistics', __( 'Parking', 'lnkup-marketplace' ), 'lnkup-ops-parking-panel' ),
				'loadin'      => array( 'logistics', __( 'Load-in', 'lnkup-marketplace' ), 'lnkup-ops-loadin-panel' ),
				'map'         => array( 'maps', __( 'Venue map', 'lnkup-marketplace' ), 'lnkup-ops-map-panel' ),
				'map-builder' => array( 'maps', __( 'Map Builder', 'lnkup-marketplace' ), 'lnkup-ops-map-builder-panel' ),
				'vendor-pass' => array( 'logistics', lnkup_copy( 'pass_builder_title' ), 'lnkup-ops-vendor-pass-panel' ),
				'live-map'    => array( 'maps', __( 'Live map', 'lnkup-marketplace' ), 'lnkup-ops-live-map-panel' ),
				'support'     => array( 'field', __( 'Support', 'lnkup-marketplace' ), 'lnkup-ops-support-panel' ),
				'incidents'   => array( 'field', __( 'Incidents', 'lnkup-marketplace' ), 'lnkup-ops-incidents-panel' ),
				'comms'       => array( 'field', __( 'Comms', 'lnkup-marketplace' ), 'lnkup-ops-comms-panel' ),
				'analytics'   => array( 'intel', __( 'Analytics', 'lnkup-marketplace' ), 'lnkup-ops-analytics-panel' ),
				'financial'   => array( 'intel', __( 'Financial', 'lnkup-marketplace' ), 'lnkup-ops-financial-panel' ),
				'insights'    => array( 'intel', __( 'Insights', 'lnkup-marketplace' ), 'lnkup-ops-insights-panel' ),
			);
			$ops_primary   = array( 'overview', 'readiness', 'kanban', 'booths', 'parking', 'loadin', 'map', 'map-builder' );
			$ops_tab_html  = static function ( string $key, array $def ): string {
				$active = 'overview' === $key;
				return '<button type="button" class="lnkup-dash-tabs__btn lnkup-ops-tab' . ( $active ? ' is-active' : '' ) . '" data-section="' . esc_attr( $def[0] ) . '" data-ops-tab="' . esc_attr( $key ) . '" role="tab" aria-selected="' . ( $active ? 'true' : 'false' ) . '" aria-controls="' . esc_attr( $def[2] ) . '">' . esc_html( $def[1] ) . '</button>';
			};
			?>
			<div class="lnkup-ops-tabs-wrap oos-ops-tabs">
				<nav class="lnkup-ops-tabs lnkup-dash-tabs lnkup-dash-tabs--underline" role="tablist" aria-label="<?php esc_attr_e( 'Operations sections', 'lnkup-marketplace' ); ?>">
					<?php
					foreach ( $ops_primary as $key ) {
						echo $ops_tab_html( $key, $ops_tab_defs[ $key ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
				</nav>
				<details class="vos-tab-more" data-oos-ops-more>
					<summary><?php esc_html_e( 'More', 'lnkup-marketplace' ); ?></summary>
					<div class="vos-tab-more__menu" role="tablist" aria-label="<?php esc_attr_e( 'More operations sections', 'lnkup-marketplace' ); ?>">
						<?php
						foreach ( array_diff( array_keys( $ops_tab_defs ), $ops_primary ) as $key ) {
							echo $ops_tab_html( $key, $ops_tab_defs[ $key ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</div>
				</details>
			</div>
			<div class="lnkup-ops-tab-panels">
				<div class="lnkup-ops-tab-panel is-active" data-ops-panel="overview" id="lnkup-ops-overview-panel" role="tabpanel">
					<?php echo LNKup_Operations_Render::readiness_overview_card( $readiness_summary, $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div class="lnkup-ops-panel-shell">
						<?php
						echo LNKup_Operations_Render::panel_header(
							__( 'Logistics snapshot', 'lnkup-marketplace' ),
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
						<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-ops-goto-tab" data-ops-tab="booths"><?php esc_html_e( 'Open booths', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost lnkup-ops-goto-tab" data-ops-tab="support"><?php esc_html_e( 'Open support', 'lnkup-marketplace' ); ?></button>
					</div>
					<div class="lnkup-ops-overview-grid">
						<section class="lnkup-ops-panel">
							<h2><?php esc_html_e( 'Compliance', 'lnkup-marketplace' ); ?></h2>
							<div id="lnkup-ops-compliance"><?php echo LNKup_Operations_Render::compliance_alerts( $snap['compliance'], $snap['compliance_groups'] ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						</section>
						<section class="lnkup-ops-panel">
							<h2><?php esc_html_e( 'Recent activity', 'lnkup-marketplace' ); ?> <span class="lnkup-muted oos-h-note"><?php esc_html_e( 'refreshes every 15 seconds', 'lnkup-marketplace' ); ?></span></h2>
							<div id="lnkup-ops-feed"><?php echo LNKup_Operations_Render::feed_list( $snap['feed'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						</section>
					</div>
					<?php // Owners see weather on the event workspace Overview; team staff without it see it here. ?>
					<?php if ( ! LNKup_Capabilities::organizer_owns_event( (int) $event_id ) ) : ?>
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
					<?php endif; ?>
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
						<?php echo LNKup_Operations_Render::panel_header( __( 'Booths', 'lnkup-marketplace' ), __( 'Works with or without a map. Assign from a booth or from a vendor.', 'lnkup-marketplace' ), '<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-vos-open="oos-booth-bulk" data-testid="lnkup-booth-bulk-open">' . esc_html__( 'Bulk create', 'lnkup-marketplace' ) . '</button> <button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" id="lnkup-ops-add-booth">' . esc_html__( 'Add booth', 'lnkup-marketplace' ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div id="lnkup-ops-booths-stats"></div>
						<div id="lnkup-ops-booths-list" class="lnkup-ops-table-wrap"></div>
						<div id="lnkup-ops-booths-unassigned" data-testid="lnkup-booths-unassigned"></div>
					</div>
				</div>
				<?php
				if ( class_exists( 'LNKup_OS_UI' ) ) {
					$assign_body = '<p class="vos-notice" data-oos-booth-error hidden role="alert"></p><div data-oos-booth-assign-body></div>';
					$assign_foot = '<button type="button" class="lnkup-btn lnkup-btn--ghost" data-vos-close>' . esc_html__( 'Cancel', 'lnkup-marketplace' ) . '</button><button type="button" class="lnkup-btn lnkup-btn--primary" data-oos-booth-assign-save>' . esc_html__( 'Save', 'lnkup-marketplace' ) . '</button>';
					echo LNKup_OS_UI::drawer_shell( 'oos-booth-assign', __( 'Assign booth', 'lnkup-marketplace' ), $assign_body, $assign_foot, array( 'testid' => 'lnkup-booth-assign-drawer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$bulk_body  = '<form data-oos-booth-bulk-form novalidate>';
					$bulk_body .= '<p class="vos-notice" data-oos-booth-error hidden role="alert"></p>';
					$bulk_body .= '<div class="vos-field-row"><label class="vos-field"><span>' . esc_html__( 'Prefix', 'lnkup-marketplace' ) . '</span><input type="text" name="prefix" maxlength="6" value="A" /></label>';
					$bulk_body .= '<label class="vos-field"><span>' . esc_html__( 'From', 'lnkup-marketplace' ) . '</span><input type="number" name="from" min="1" max="999" value="1" /></label>';
					$bulk_body .= '<label class="vos-field"><span>' . esc_html__( 'To', 'lnkup-marketplace' ) . '</span><input type="number" name="to" min="1" max="999" value="10" /></label></div>';
					$bulk_body .= '<div class="vos-field-row"><label class="vos-field"><span>' . esc_html__( 'Type', 'lnkup-marketplace' ) . '</span><select name="booth_type">';
					foreach ( LNKup_Booths::booth_types() as $type_key => $type_label ) {
						$bulk_body .= '<option value="' . esc_attr( $type_key ) . '">' . esc_html( $type_label ) . '</option>';
					}
					$bulk_body .= '</select></label><label class="vos-field"><span>' . esc_html__( 'Size', 'lnkup-marketplace' ) . '</span><input type="text" name="booth_size" placeholder="10x10" maxlength="20" /></label></div>';
					$bulk_body .= '<label class="vos-field" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="has_power" value="1" style="width:auto;min-height:0" /> ' . esc_html__( 'Power available', 'lnkup-marketplace' ) . '</label>';
					$bulk_body .= '<label class="vos-field" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="has_water" value="1" style="width:auto;min-height:0" /> ' . esc_html__( 'Water available', 'lnkup-marketplace' ) . '</label>';
					$bulk_body .= '<p class="lnkup-muted" data-oos-booth-preview aria-live="polite"></p></form>';
					$bulk_foot  = '<button type="button" class="lnkup-btn lnkup-btn--ghost" data-vos-close>' . esc_html__( 'Cancel', 'lnkup-marketplace' ) . '</button><button type="button" class="lnkup-btn lnkup-btn--primary" data-oos-booth-bulk-save>' . esc_html__( 'Create booths', 'lnkup-marketplace' ) . '</button>';
					echo LNKup_OS_UI::dialog_shell( 'oos-booth-bulk', __( 'Bulk create booths', 'lnkup-marketplace' ), $bulk_body, $bulk_foot, array( 'testid' => 'lnkup-booth-bulk-dialog' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
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
						<?php $packet_stage = LNKup_Packets::lifecycle( $event_id ); ?>
						<p class="lnkup-ops-pass-stage" data-testid="lnkup-packet-stage" data-stage="<?php echo esc_attr( $packet_stage['stage'] ); ?>">
							<span class="lnkup-status-pill<?php echo 'published' === $packet_stage['stage'] ? ' lnkup-status-pill--success' : ( 'ready' === $packet_stage['stage'] ? '' : ' lnkup-status-pill--muted' ); ?>"><?php echo esc_html( $packet_stage['label'] ); ?></span>
							<span class="lnkup-muted"><?php echo esc_html( $packet_stage['hint'] ); ?></span>
						</p>
						<?php echo class_exists( 'LNKup_Organizer_Presentation' ) ? LNKup_Organizer_Presentation::render_packet_tracking( (int) $event_id ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
						<?php echo lnkup_loading_state( 'panel' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
	/**
	 * Check-in counts and the lookup list for the scan page.
	 *
	 * @param int $event_id Event ID.
	 * @return array{expected:int,checked_in:int,not_yet:int,exceptions:int,vendors:array<int,array<string,mixed>>}
	 */
	private static function checkin_overview( $event_id ) {
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_event_id', 'value' => (string) $event_id ),
					array( 'key' => '_lnkup_application_status', 'value' => array( 'paid', 'completed' ), 'compare' => 'IN' ),
				),
			)
		);
		$out = array( 'expected' => count( $apps ), 'checked_in' => 0, 'not_yet' => 0, 'exceptions' => 0, 'vendors' => array() );
		foreach ( $apps as $app_id ) {
			$ops     = class_exists( 'LNKup_Event_Operations' ) ? (string) LNKup_Event_Operations::resolve_ops_status( $app_id ) : '';
			$checked = '' !== (string) get_post_meta( $app_id, '_lnkup_checked_in_at', true );
			$issue   = in_array( $ops, array( 'no_show', 'late', 'needs_help', 'missing' ), true );
			$out['checked_in'] += $checked ? 1 : 0;
			$out['exceptions'] += $issue ? 1 : 0;
			$out['vendors'][]   = array(
				'app_id'  => (int) $app_id,
				'name'    => LNKup_Event_Operations::vendor_name_for_app( $app_id ),
				'booth'   => (string) get_post_meta( $app_id, '_lnkup_booth_placeholder', true ),
				'checked' => $checked,
				'label'   => LNKup_Checkins::roster_label( $ops, $checked ? '' : 'ready' ),
			);
		}
		$out['not_yet'] = max( 0, $out['expected'] - $out['checked_in'] );
		usort(
			$out['vendors'],
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);
		return $out;
	}

	public static function render_qr_scan_view( $event_id ) {
		if ( ! LNKup_Capabilities::staff_can( $event_id, 'check_in' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			return '<p class="lnkup-alert lnkup-alert--error">' . esc_html__( 'Permission denied.', 'lnkup-marketplace' ) . '</p>';
		}
		$event    = get_post( $event_id );
		$overview = self::checkin_overview( (int) $event_id );
		ob_start();
		?>
		<div class="lnkup-mp lnkup-ops" id="lnkup-qr-scan" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>">
			<?php
			echo self::event_detail_head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				(int) $event_id,
				'checkin',
				__( 'Check-in', 'lnkup-marketplace' ),
				__( 'Scan a vendor pass, type the pass code, or find the vendor by name.', 'lnkup-marketplace' ),
				'<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="' . esc_url( add_query_arg( array( 'event' => $event_id, 'roster' => 1 ), lnkup_page_url_helper( 'organizer-dashboard' ) ) ) . '">' . esc_html__( 'Roster', 'lnkup-marketplace' ) . '</a>',
				$event ? $event->post_title : ''
			);
			$checkin_counts = array(
				array( 'label' => __( 'Expected', 'lnkup-marketplace' ), 'value' => (string) $overview['expected'] ),
				array( 'label' => __( 'Checked in', 'lnkup-marketplace' ), 'value' => (string) $overview['checked_in'] ),
				array( 'label' => __( 'Not yet', 'lnkup-marketplace' ), 'value' => (string) $overview['not_yet'] ),
				array( 'label' => __( 'Exceptions', 'lnkup-marketplace' ), 'value' => (string) $overview['exceptions'], 'tone' => $overview['exceptions'] ? 'warning' : '' ),
			);
			echo class_exists( 'LNKup_OS_UI' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				? LNKup_OS_UI::metrics( $checkin_counts, 'lnkup-checkin-counts' )
				: lnkup_stat_strip( $checkin_counts, array( 'testid' => 'lnkup-checkin-counts' ) );
			?>
			<div class="lnkup-qr-layout">
				<div class="lnkup-qr-layout__main">
					<div id="lnkup-qr-reader" class="lnkup-ops-qr-reader" data-testid="lnkup-qr-reader">
						<p class="lnkup-muted" data-qr-camera-status><?php esc_html_e( 'Checking whether this browser can scan with the camera…', 'lnkup-marketplace' ); ?></p>
						<video class="lnkup-qr-video" data-qr-video playsinline muted hidden></video>
						<button type="button" class="lnkup-btn lnkup-btn--primary" data-qr-camera-start hidden><?php esc_html_e( 'Start camera', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--outline" data-qr-camera-stop hidden><?php esc_html_e( 'Stop camera', 'lnkup-marketplace' ); ?></button>
					</div>
					<form id="lnkup-qr-manual" class="lnkup-ops-form">
						<label for="lnkup-qr-token"><?php esc_html_e( 'Pass code', 'lnkup-marketplace' ); ?></label>
						<input type="text" id="lnkup-qr-token" name="token" autocomplete="off" autocapitalize="off" spellcheck="false" required />
						<button type="submit" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Check in', 'lnkup-marketplace' ); ?></button>
					</form>
					<div id="lnkup-qr-result" class="lnkup-ops-scan-result" role="status" aria-live="polite"></div>
					<section class="lnkup-checkin-lookup" data-checkin-lookup data-endpoint="<?php echo esc_url( rest_url( 'lnkup/v1/operations/events/' . (int) $event_id . '/vendors/' ) ); ?>" aria-labelledby="lnkup-checkin-lookup-title">
						<h2 id="lnkup-checkin-lookup-title" class="lnkup-event-command__title"><?php esc_html_e( 'Find a vendor', 'lnkup-marketplace' ); ?></h2>
						<label class="screen-reader-text" for="lnkup-checkin-search"><?php esc_html_e( 'Search vendors or booth numbers', 'lnkup-marketplace' ); ?></label>
						<input type="search" id="lnkup-checkin-search" data-checkin-search placeholder="<?php esc_attr_e( 'Vendor name or booth', 'lnkup-marketplace' ); ?>" data-testid="lnkup-checkin-search" />
						<?php if ( ! $overview['vendors'] ) : ?>
							<p class="lnkup-muted"><?php esc_html_e( 'No paid vendors for this event yet.', 'lnkup-marketplace' ); ?></p>
						<?php else : ?>
							<ul class="lnkup-checkin-lookup__list" data-testid="lnkup-checkin-lookup">
								<?php foreach ( $overview['vendors'] as $vendor ) : ?>
									<li class="lnkup-checkin-lookup__row" data-checkin-row data-search="<?php echo esc_attr( strtolower( $vendor['name'] . ' ' . $vendor['booth'] ) ); ?>">
										<span><strong><?php echo esc_html( $vendor['name'] ); ?></strong><?php echo $vendor['booth'] ? ' <span class="lnkup-muted">' . esc_html( sprintf( /* translators: %s: booth */ __( 'Booth %s', 'lnkup-marketplace' ), $vendor['booth'] ) ) . '</span>' : ''; ?></span>
										<span class="lnkup-muted" data-checkin-label><?php echo esc_html( $vendor['label'] ); ?></span>
										<?php if ( ! $vendor['checked'] ) : ?>
											<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" data-checkin-app="<?php echo esc_attr( (string) $vendor['app_id'] ); ?>"><?php esc_html_e( 'Check in', 'lnkup-marketplace' ); ?></button>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</section>
				</div>
				<section class="lnkup-qr-layout__recent" aria-labelledby="lnkup-qr-recent-title">
					<h2 id="lnkup-qr-recent-title" class="lnkup-event-command__title"><?php esc_html_e( 'Recent check-ins', 'lnkup-marketplace' ); ?></h2>
					<div id="lnkup-qr-recent" data-testid="lnkup-qr-recent">
						<?php echo lnkup_loading_state( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</section>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Applications manager shortcode.
	 *
	 * @param array|null $apps_override Optional subset of applications to render.
	 * @param array      $options       selectable (bool): show checkboxes and the bulk action bar.
	 */
	public static function render_applications_manager( $apps_override = null, $options = array() ) {
		LNKup_Capabilities::require_login();
		if ( ! LNKup_Roles::is_organizer() && ! LNKup_Capabilities::user_has_manage_all() ) {
			return '';
		}

		$apps       = is_array( $apps_override ) ? $apps_override : lnkup_get_applications_for_user( get_current_user_id(), 'organizer' );
		$selectable = ! empty( $options['selectable'] );
		$has_open   = false;

		ob_start();
		if ( ! $apps ) {
			$dash = lnkup_page_url_helper( 'organizer-dashboard' );
			echo lnkup_empty_state(
				array(
					'title'               => __( 'No vendor requests yet', 'lnkup-marketplace' ),
					'desc'                => lnkup_copy( 'empty_vendor_requests' ),
					'cta_label'           => lnkup_copy( 'launch_event' ),
					'cta_href'            => lnkup_organizer_view_url( 'create' ),
					'secondary_cta_label' => lnkup_copy( 'discover_vendors' ),
					'secondary_cta_href'  => lnkup_organizer_view_url( 'vendors' ),
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
			$opp_id    = (int) get_post_meta( $app->ID, '_lnkup_opportunity_id', true );
			$slot_name = $opp_id ? get_the_title( $opp_id ) : '';
			$fee       = $opp_id ? (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ) : 0;
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
			if ( ! $category_label || '—' === $category_label ) {
				$products = $profile ? trim( (string) ( $profile['products_services'] ?? '' ) ) : '';
				$category_label = $products ? wp_trim_words( $products, 12, '…' ) : __( 'Category not listed.', 'lnkup-marketplace' );
			}
			$location = $profile && class_exists( 'LNKup_Vendor_CRM_Profile' ) ? LNKup_Vendor_CRM_Profile::location_label( $profile ) : '';
			if ( ! $location ) {
				$location = __( 'Location not listed.', 'lnkup-marketplace' );
			}
			$is_open       = in_array( $status, array( 'submitted', 'under_review', 'pending', 'info_requested' ), true );
			$info_thread   = class_exists( 'LNKup_Applications' ) ? LNKup_Applications::info_history( (int) $app->ID ) : array();
			$info_last     = $info_thread ? end( $info_thread ) : null;
			$has_open      = $has_open || $is_open;
			$vendor_name   = $profile ? (string) $profile['business_name'] : '—';
			$is_approved   = in_array( $status, array( 'approved', 'payment_required', 'paid', 'completed' ), true );
			if ( $docs_total > 0 ) {
				$docs_label = sprintf( /* translators: 1: ready docs, 2: total docs */ __( 'Docs %1$d/%2$d', 'lnkup-marketplace' ), $docs_ready, $docs_total );
			} elseif ( 'none_required' === $doc_code ) {
				$docs_label = __( 'No docs needed', 'lnkup-marketplace' );
			} else {
				$docs_label = $docs_ok ? __( 'Docs ready', 'lnkup-marketplace' ) : __( 'Docs missing', 'lnkup-marketplace' );
			}
			?>
			<article class="lnkup-crm-app-card lnkup-crm-app-card--v2 lnkup-request-row<?php echo $selectable ? ' lnkup-request-row--selectable' : ''; ?>" data-app-id="<?php echo esc_attr( (string) $app->ID ); ?>" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>" data-org-app-filter="<?php echo esc_attr( $filter_key ); ?>" data-org-app-status="<?php echo esc_attr( $status ); ?>" data-org-app-docs="<?php echo esc_attr( $docs_ok ? 'ready' : 'missing' ); ?>" data-org-app-paid="<?php echo esc_attr( $is_paid ? 'yes' : 'no' ); ?>">
				<?php if ( $selectable && $is_open && $event_id ) : ?>
					<label class="lnkup-request-row__select">
						<input type="checkbox" data-request-select value="<?php echo esc_attr( (string) $app->ID ); ?>" data-event-id="<?php echo esc_attr( (string) $event_id ); ?>" />
						<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: vendor name */ __( 'Select %s', 'lnkup-marketplace' ), $vendor_name ) ); ?></span>
					</label>
				<?php endif; ?>
				<div class="lnkup-request-row__main">
					<span class="lnkup-request-row__initials" aria-hidden="true"><?php echo esc_html( $initials ?: 'V' ); ?></span>
					<div class="lnkup-request-row__copy">
						<p class="lnkup-request-row__title">
							<?php echo esc_html( $vendor_name ); ?>
							<?php if ( '' !== $rating ) : ?>
								<span class="lnkup-request-row__rating"><?php echo esc_html( sprintf( /* translators: %s: rating */ __( '★ %s', 'lnkup-marketplace' ), $rating ) ); ?></span>
							<?php endif; ?>
							<?php if ( (int) $events_count > 0 ) : ?>
								<span class="lnkup-request-row__rating"><?php echo esc_html( sprintf( /* translators: %d: events */ _n( '%d event', '%d events', (int) $events_count, 'lnkup-marketplace' ), (int) $events_count ) ); ?></span>
							<?php endif; ?>
						</p>
						<p class="lnkup-request-row__meta"><?php echo esc_html( $category_label . ' · ' . $location ); ?></p>
						<p class="lnkup-request-row__meta">
							<?php
							$event_requests = $event_id ? add_query_arg( array( 'event' => $event_id, 'tab' => 'requests' ), lnkup_page_url_helper( 'organizer-dashboard' ) ) : '';
							if ( $slot_name ) {
								echo esc_html( $slot_name );
							}
							if ( $event && $event_requests ) {
								echo $slot_name ? ' · ' : '';
								echo '<a href="' . esc_url( $event_requests ) . '">' . esc_html( $event->post_title ) . '</a>';
							}
							?>
						</p>
						<ul class="lnkup-request-row__badges" aria-label="<?php esc_attr_e( 'Request status details', 'lnkup-marketplace' ); ?>">
							<li class="lnkup-request-badge lnkup-request-badge--<?php echo esc_attr( $docs_ok ? 'ok' : 'warn' ); ?>"><?php echo esc_html( $docs_label ); ?></li>
							<?php if ( $is_approved && $fee > 0 ) : ?>
								<li class="lnkup-request-badge lnkup-request-badge--<?php echo esc_attr( $is_paid ? 'ok' : 'warn' ); ?>"><?php echo $is_paid ? esc_html__( 'Paid', 'lnkup-marketplace' ) : esc_html__( 'Not paid yet', 'lnkup-marketplace' ); ?></li>
							<?php endif; ?>
							<li class="lnkup-request-badge"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Applied %s', 'lnkup-marketplace' ), mysql2date( get_option( 'date_format' ), $app->post_date ) ) ); ?></li>
						</ul>
						<?php if ( '' !== $app_message ) : ?>
							<p class="lnkup-request-row__message"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $app_message ), 24, '…' ) ); ?></p>
						<?php endif; ?>
						<?php
						$app_answers = class_exists( 'LNKup_Application_Questions' ) ? LNKup_Application_Questions::answers_for_application( (int) $app->ID ) : array();
						if ( $app_answers || '' !== (string) $setup_notes ) :
							?>
							<details class="lnkup-request-row__answers" data-testid="lnkup-org-app-answers">
								<summary><?php esc_html_e( 'Application answers', 'lnkup-marketplace' ); ?></summary>
								<dl>
									<?php foreach ( $app_answers as $answer ) : ?>
										<?php
										$shown = $answer['value'];
										if ( 'checkbox' === ( $answer['type'] ?? '' ) ) {
											$shown = $shown ? __( 'Yes', 'lnkup-marketplace' ) : __( 'No', 'lnkup-marketplace' );
										} elseif ( 'file' === ( $answer['type'] ?? '' ) ) {
											$shown = get_the_title( (int) $shown );
										}
										?>
										<dt><?php echo esc_html( (string) ( $answer['label'] ?? '' ) ); ?></dt>
										<dd><?php echo esc_html( (string) $shown ); ?></dd>
									<?php endforeach; ?>
									<?php if ( '' !== (string) $setup_notes ) : ?>
										<dt><?php esc_html_e( 'Setup notes', 'lnkup-marketplace' ); ?></dt>
										<dd><?php echo esc_html( (string) $setup_notes ); ?></dd>
									<?php endif; ?>
								</dl>
							</details>
						<?php endif; ?>
						<?php if ( $info_last ) : ?>
							<p class="lnkup-request-row__message lnkup-info-thread" data-testid="lnkup-org-info-thread">
								<strong><?php echo 'response' === ( $info_last['type'] ?? '' ) ? esc_html__( 'Vendor replied:', 'lnkup-marketplace' ) : esc_html__( 'You asked:', 'lnkup-marketplace' ); ?></strong>
								<?php echo esc_html( wp_trim_words( (string) $info_last['message'], 40, '…' ) ); ?>
							</p>
						<?php endif; ?>
					</div>
				</div>
				<span class="lnkup-request-row__fee"><?php echo esc_html( lnkup_currency( $fee ) ); ?></span>
				<span class="lnkup-status lnkup-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_label ); ?></span>
				<div class="lnkup-request-row__actions">
					<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost lnkup-intel-open" data-app-id="<?php echo esc_attr( (string) $app->ID ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: vendor name */ __( 'View details for %s', 'lnkup-marketplace' ), $vendor_name ) ); ?>"><?php esc_html_e( 'View details', 'lnkup-marketplace' ); ?></button>
					<?php if ( $is_open && 'info_requested' !== $status ) : ?>
						<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-request-info data-app-id="<?php echo esc_attr( (string) $app->ID ); ?>" data-endpoint="<?php echo esc_url( rest_url( 'lnkup/v1/applications/' . (int) $app->ID . '/request-info' ) ); ?>" data-testid="lnkup-org-request-info"><?php esc_html_e( 'Ask for info', 'lnkup-marketplace' ); ?></button>
					<?php endif; ?>
					<?php if ( $is_open ) : ?>
						<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline lnkup-review-btn lnkup-review-btn--decline" data-app-id="<?php echo esc_attr( (string) $app->ID ); ?>" data-action="reject"><?php esc_html_e( 'Decline', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost lnkup-review-btn" data-app-id="<?php echo esc_attr( (string) $app->ID ); ?>" data-action="waitlist"><?php esc_html_e( 'Waitlist', 'lnkup-marketplace' ); ?></button>
						<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary lnkup-review-btn" data-app-id="<?php echo esc_attr( (string) $app->ID ); ?>" data-action="approve"><?php esc_html_e( 'Accept', 'lnkup-marketplace' ); ?></button>
					<?php elseif ( 'rejected' === $status && '' === (string) get_post_meta( $app->ID, LNKup_Applications::REAPPLY_ALLOWED_META, true ) ) : ?>
						<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost lnkup-review-btn" data-app-id="<?php echo esc_attr( (string) $app->ID ); ?>" data-action="allow_reapply" data-testid="lnkup-org-allow-reapply"><?php esc_html_e( 'Let them re-apply', 'lnkup-marketplace' ); ?></button>
					<?php endif; ?>
				</div>
			</article>
		<?php endforeach; ?>
		</div>
		<?php if ( $selectable && $has_open ) : ?>
			<div class="lnkup-request-bulk" data-request-bulk hidden role="region" aria-label="<?php esc_attr_e( 'Bulk actions', 'lnkup-marketplace' ); ?>">
				<span class="lnkup-request-bulk__count" data-request-bulk-count aria-live="polite"></span>
				<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-request-bulk-action="reject"><?php esc_html_e( 'Decline', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-request-bulk-action="waitlist"><?php esc_html_e( 'Waitlist', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--primary" data-request-bulk-action="approve"><?php esc_html_e( 'Accept', 'lnkup-marketplace' ); ?></button>
				<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--ghost" data-request-bulk-clear><?php esc_html_e( 'Clear', 'lnkup-marketplace' ); ?></button>
			</div>
		<?php endif; ?>
		<?php
		echo self::intel_drawer_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return ob_get_clean();
	}

	/**
	 * Request details drawer, printed once per page.
	 *
	 * @return string
	 */
	public static function intel_drawer_markup() {
		static $printed = false;
		if ( $printed ) {
			return '';
		}
		$printed = true;
		ob_start();
		?>
		<div id="lnkup-intel-drawer" class="lnkup-intel-drawer" hidden aria-hidden="true">
			<div class="lnkup-intel-drawer__panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Request details', 'lnkup-marketplace' ); ?>">
				<button type="button" class="lnkup-intel-drawer__close" id="lnkup-intel-drawer-close" aria-label="<?php esc_attr_e( 'Close details', 'lnkup-marketplace' ); ?>">&times;</button>
				<div id="lnkup-intel-drawer-content"></div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Documents manager shortcode.
	 */
	public static function render_documents_manager() {
		LNKup_Capabilities::require_login();
		$user_id    = get_current_user_id();
		$compliance = LNKup_Documents::compliance_summary( $user_id );
		$types      = LNKup_Documents::document_types();

		$status_labels = array(
			'verified' => __( 'Verified', 'lnkup-marketplace' ),
			'expiring' => __( 'Expiring soon', 'lnkup-marketplace' ),
			'pending'  => __( 'Pending review', 'lnkup-marketplace' ),
			'missing'  => __( 'Not uploaded', 'lnkup-marketplace' ),
		);

		ob_start();
		?>
		<div class="lnkup-mp lnkup-vendor-dash" id="documents">
			<?php
			echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title'    => __( 'Documents', 'lnkup-marketplace' ),
					'subtitle' => __( 'Upload once and reuse your documents everywhere you apply.', 'lnkup-marketplace' ),
					'actions'  => '<a class="lnkup-btn lnkup-btn--primary" href="#lnkup-document-upload-form">' . esc_html__( 'Upload document', 'lnkup-marketplace' ) . '</a>',
				)
			);
			?>

			<?php
			$doc_states = array( 'verified' => 0, 'expiring' => 0, 'pending' => 0, 'missing' => 0 );
			foreach ( (array) $compliance['items'] as $doc_state_item ) {
				$doc_state_key = (string) ( $doc_state_item['status'] ?? 'missing' );
				if ( isset( $doc_states[ $doc_state_key ] ) ) {
					++$doc_states[ $doc_state_key ];
				}
			}
			$doc_uploaded = (int) $compliance['total'] - $doc_states['missing'];
			?>
			<?php if ( 0 === $doc_uploaded ) : ?>
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

			<?php
			echo LNKup_Vendor_UI::metrics( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					array( 'label' => __( 'Verified', 'lnkup-marketplace' ), 'value' => (string) $doc_states['verified'], 'unit' => sprintf( /* translators: %d: total */ __( 'of %d', 'lnkup-marketplace' ), (int) $compliance['total'] ), 'hint' => __( 'Reusable on every application', 'lnkup-marketplace' ) ),
					array( 'label' => __( 'In review', 'lnkup-marketplace' ), 'value' => (string) $doc_states['pending'], 'hint' => __( 'LNKup is checking these', 'lnkup-marketplace' ) ),
					array( 'label' => __( 'Expiring soon', 'lnkup-marketplace' ), 'value' => (string) $doc_states['expiring'], 'hint' => __( 'Replace before they lapse', 'lnkup-marketplace' ), 'tone' => $doc_states['expiring'] ? 'warning' : '' ),
					array( 'label' => __( 'Not uploaded', 'lnkup-marketplace' ), 'value' => (string) $doc_states['missing'], 'hint' => __( 'Only required when an event asks', 'lnkup-marketplace' ) ),
				),
				'lnkup-doc-summary'
			);
			?>

			<?php echo self::render_documents_needed_for_events( $user_id, $types ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php
			$status_order = array( 'missing' => 0, 'expiring' => 1, 'pending' => 2, 'verified' => 3 );
			$doc_items    = (array) $compliance['items'];
			uasort(
				$doc_items,
				static function ( $a, $b ) use ( $status_order ) {
					return ( $status_order[ $a['status'] ] ?? 9 ) <=> ( $status_order[ $b['status'] ] ?? 9 );
				}
			);
			?>
			<h2 class="lnkup-panel__title"><?php esc_html_e( 'Your document library', 'lnkup-marketplace' ); ?></h2>
			<div class="lnkup-doc-grid lnkup-doc-grid--compliance">
				<?php foreach ( $doc_items as $type_key => $item ) : ?>
					<article class="lnkup-doc-card lnkup-surface--primary lnkup-micro-lift lnkup-doc-card--<?php echo esc_attr( $item['status'] ); ?>">
						<div class="lnkup-doc-card__header">
							<h3 class="lnkup-doc-card__title"><?php echo esc_html( $item['label'] ); ?></h3>
							<p class="lnkup-doc-card__status lnkup-doc-card__status--<?php echo esc_attr( $item['status'] ); ?>"><?php echo LNKup_Vendor_UI::status( (string) ( $status_labels[ $item['status'] ] ?? $item['status'] ), array( 'verified' => 'success', 'expiring' => 'warning', 'pending' => 'info', 'missing' => 'neutral' )[ $item['status'] ] ?? 'neutral' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
						</div>
						<?php if ( $item['expires'] && 'verified' !== $item['status'] && 'missing' !== $item['status'] ) : ?>
							<p class="lnkup-muted"><?php printf( esc_html__( 'Expires %s', 'lnkup-marketplace' ), esc_html( date_i18n( 'M j, Y', strtotime( $item['expires'] ) ) ) ); ?></p>
						<?php elseif ( 'verified' === $item['status'] && ! empty( $item['expires'] ) ) : ?>
							<p class="lnkup-muted"><?php printf( esc_html__( 'Valid through %s', 'lnkup-marketplace' ), esc_html( date_i18n( 'M j, Y', strtotime( $item['expires'] ) ) ) ); ?></p>
						<?php elseif ( 'missing' === $item['status'] ) : ?>
							<p class="lnkup-muted"><?php esc_html_e( 'Upload it once and it is reused when an event asks for it.', 'lnkup-marketplace' ); ?></p>
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
				<div class="lnkup-form-row">
					<label for="lnkup_document"><?php esc_html_e( 'File (PDF, JPG, or PNG)', 'lnkup-marketplace' ); ?></label>
					<input type="file" id="lnkup_document" name="lnkup_document" accept=".pdf,.jpg,.jpeg,.png" />
				</div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Required documents per active booking, so vendors see what each event still needs.
	 *
	 * @param int                  $user_id Vendor user ID.
	 * @param array<string,string> $types   Document type labels.
	 * @return string
	 */
	private static function render_documents_needed_for_events( $user_id, $types ) {
		if ( ! function_exists( 'lnkup_get_opportunity_required_documents' ) ) {
			return '';
		}
		$rows = array();
		foreach ( lnkup_get_applications_for_user( $user_id, 'vendor' ) as $app ) {
			$status = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( ! in_array( $status, array( 'submitted', 'under_review', 'approved', 'payment_required', 'paid' ), true ) ) {
				continue;
			}
			$opp_id   = (int) get_post_meta( $app->ID, '_lnkup_opportunity_id', true );
			$event_id = (int) get_post_meta( $app->ID, '_lnkup_event_id', true );
			$date     = $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_date', true ) : '';
			if ( $date && strtotime( $date ) < strtotime( 'today' ) ) {
				continue;
			}
			$required = $opp_id ? (array) lnkup_get_opportunity_required_documents( $opp_id ) : array();
			if ( ! $required ) {
				continue;
			}
			$docs    = LNKup_Applications::document_statuses_for_user( $user_id, $required, $types );
			$missing = count(
				array_filter(
					$docs,
					static function ( $d ) {
						return in_array( $d['status'], array( 'missing', 'expired' ), true );
					}
				)
			);
			$rows[] = array(
				'title'   => $event_id ? get_the_title( $event_id ) : get_the_title( $app ),
				'date'    => $date,
				'docs'    => $docs,
				'missing' => $missing,
			);
		}
		if ( ! $rows ) {
			return '';
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['missing'] <=> $a['missing'];
			}
		);
		$labels = array(
			'missing'  => __( 'Missing', 'lnkup-marketplace' ),
			'expired'  => __( 'Expired', 'lnkup-marketplace' ),
			'pending'  => __( 'In review', 'lnkup-marketplace' ),
			'uploaded' => __( 'Uploaded', 'lnkup-marketplace' ),
			'approved' => __( 'Approved', 'lnkup-marketplace' ),
		);
		ob_start();
		?>
		<section class="lnkup-panel lnkup-docs-needed" data-testid="lnkup-docs-needed">
			<h2 class="lnkup-panel__title"><?php esc_html_e( 'Needed for your events', 'lnkup-marketplace' ); ?></h2>
			<ul class="lnkup-docs-needed__list">
				<?php foreach ( $rows as $row ) : ?>
					<li class="lnkup-docs-needed__event<?php echo $row['missing'] ? ' needs-attention' : ''; ?>">
						<div class="lnkup-docs-needed__head">
							<strong><?php echo esc_html( $row['title'] ); ?></strong>
							<?php if ( $row['date'] ) : ?>
								<span class="lnkup-muted"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $row['date'] ) ) ); ?></span>
							<?php endif; ?>
						</div>
						<ul class="lnkup-docs-needed__docs">
							<?php foreach ( $row['docs'] as $doc ) : ?>
								<?php $needs = in_array( $doc['status'], array( 'missing', 'expired' ), true ); ?>
								<li class="lnkup-request-badge lnkup-request-badge--<?php echo esc_attr( $needs ? 'warn' : ( 'approved' === $doc['status'] ? 'ok' : 'neutral' ) ); ?>">
									<?php echo esc_html( $doc['label'] . ': ' . ( $labels[ $doc['status'] ] ?? $doc['status'] ) ); ?>
									<?php if ( $needs ) : ?>
										<button type="button" class="lnkup-docs-needed__upload" data-doc-upload="<?php echo esc_attr( (string) $doc['type'] ); ?>"><?php esc_html_e( 'Upload', 'lnkup-marketplace' ); ?></button>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		return (string) ob_get_clean();
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
		$rows     = function_exists( 'lnkup_payment_display_rows' ) ? lnkup_sort_payment_rows_by_activity( lnkup_payment_display_rows( $payments ) ) : array();
		if ( 'vendor' === $role ) {
			?>
		<div class="lnkup-mp lnkup-vendor-dash" id="payments">
			<?php
			echo lnkup_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title'    => __( 'Wallet', 'lnkup-marketplace' ),
					'subtitle' => __( 'What you owe, what you have paid, and your receipts. Booth fees are business expenses, not income.', 'lnkup-marketplace' ),
				)
			);
			$due_rows  = array();
			$paid_rows = array();
			foreach ( $rows as $row ) {
				$row_status = (string) get_post_meta( $row['payment']->ID, '_lnkup_payment_status', true );
				if ( in_array( $row_status, array( 'paid', 'refunded', 'partially_refunded' ), true ) ) {
					$paid_rows[] = $row;
				} else {
					$due_rows[] = $row;
				}
			}
			$receipts_url = add_query_arg( array( 'view' => 'payments', 'fin_tab' => 'receipts' ), lnkup_page_url_helper( 'vendor-dashboard' ) );
			ob_start();
			?>
			<?php
			$today       = current_time( 'Y-m-d' );
			$groups      = array(
				'upcoming' => array(),
				'past'     => array(),
			);
			foreach ( $due_rows as $row ) {
				$payment  = $row['payment'];
				$event_id = (int) get_post_meta( $payment->ID, '_lnkup_event_id', true );
				if ( ! $event_id ) {
					$event_id = (int) get_post_meta( (int) get_post_meta( $payment->ID, '_lnkup_opportunity_id', true ), '_lnkup_event_id', true );
				}
				$date                 = $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_date', true ) : '';
				$row['event_id']      = $event_id;
				$row['event_date']    = $date;
				$groups[ ( '' !== $date && $date < $today ) ? 'past' : 'upcoming' ][] = $row;
			}
			$group_labels = array(
				'upcoming' => __( 'Due for upcoming events', 'lnkup-marketplace' ),
				'past'     => __( 'Open balances on past events', 'lnkup-marketplace' ),
			);
			?>
			<section class="lnkup-wallet-payments vos-card vos-due" aria-labelledby="lnkup-wallet-payments-title" data-testid="lnkup-wallet-payments">
				<div class="vos-section-head">
					<h2 class="vos-section-head__title" id="lnkup-wallet-payments-title"><?php esc_html_e( 'Outstanding payments', 'lnkup-marketplace' ); ?></h2>
					<?php if ( $paid_rows ) : ?>
						<a class="vos-link" href="<?php echo esc_url( $receipts_url ); ?>"><?php echo esc_html( sprintf( /* translators: %d: number of paid bookings */ _n( '%d paid booking · Receipts', '%d paid bookings · Receipts', count( $paid_rows ), 'lnkup-marketplace' ), count( $paid_rows ) ) ); ?></a>
					<?php endif; ?>
				</div>
				<?php if ( ! $rows ) : ?>
					<?php echo LNKup_Vendor_UI::empty_state( __( 'No payments yet', 'lnkup-marketplace' ), __( 'Booth fees show up here after an organizer accepts you. Applying is free.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php elseif ( ! $due_rows ) : ?>
					<div class="vos-clear"><span class="vos-clear__icon" aria-hidden="true">✓</span><div><p class="vos-clear__title"><?php esc_html_e( 'You are all paid up', 'lnkup-marketplace' ); ?></p><p class="vos-clear__desc"><?php esc_html_e( 'New booth fees appear here when an organizer approves you.', 'lnkup-marketplace' ); ?></p></div></div>
				<?php else : ?>
					<?php foreach ( $groups as $group_key => $group_rows ) : ?>
						<?php if ( ! $group_rows ) { continue; } ?>
						<p class="vos-history__label"><?php echo esc_html( $group_labels[ $group_key ] ); ?></p>
						<ul class="vos-due__list">
							<?php foreach ( $group_rows as $row ) : ?>
								<?php
								$payment       = $row['payment'];
								$event_id      = (int) $row['event_id'];
								$date          = (string) $row['event_date'];
								$status        = (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true );
								$is_processing = 'processing' === $status;
								$title         = $event_id && class_exists( 'LNKup_Vendor_Presentation' ) ? LNKup_Vendor_Presentation::customer_event_title( $event_id ) : __( 'Payment', 'lnkup-marketplace' );
								$place         = $event_id && function_exists( 'lnkup_display_place' ) ? lnkup_display_place( (string) get_post_meta( $event_id, '_lnkup_city', true ), (string) get_post_meta( $event_id, '_lnkup_state', true ) ) : '';
								$organizer     = LNKup_Vendor_UI::organizer_name( $event_id );
								$invoice       = class_exists( 'LNKup_Invoices' ) ? LNKup_Invoices::get_by_payment( (int) $payment->ID ) : null;
								?>
								<li class="lnkup-request-row vos-due__row" data-testid="lnkup-wallet-due-row">
									<span class="vos-due__media" aria-hidden="true"><?php echo LNKup_Vendor_UI::event_image( $event_id, $title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<div class="vos-due__copy">
										<p class="lnkup-request-row__title vos-due__title"><?php echo esc_html( $title ); ?></p>
										<p class="lnkup-request-row__meta"><?php echo esc_html( implode( ' · ', array_filter( array( LNKup_Vendor_UI::date( $date ), $place, $organizer, ( is_array( $invoice ) && ! empty( $invoice['invoice_number'] ) ) ? (string) $invoice['invoice_number'] : '' ) ) ) ); ?></p>
									</div>
									<span class="lnkup-request-row__fee vos-due__amount"><?php echo esc_html( lnkup_currency( (float) $row['amount'] ) ); ?></span>
									<?php if ( $is_processing ) : ?>
										<?php echo LNKup_Vendor_UI::status( __( 'Processing', 'lnkup-marketplace' ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php else : ?>
										<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm lnkup-pay-btn" data-payment-id="<?php echo esc_attr( (string) $payment->ID ); ?>"><?php echo esc_html( 'past' === $group_key ? __( 'Pay invoice', 'lnkup-marketplace' ) : __( 'Pay now', 'lnkup-marketplace' ) ); ?></button>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endforeach; ?>
				<?php endif; ?>
			</section>
			<?php
			$payment_list = ob_get_clean();
			echo LNKup_Financial_Render::vendor_shell( $payment_list ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
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
	 * Dialog listing public apply links for an event.
	 */
	private static function render_share_event_dialog() {
		ob_start();
		?>
		<dialog id="lnkup-share-event-dialog" class="lnkup-duplicate-dialog">
			<h3 class="lnkup-panel__title" id="lnkup-share-event-title"><?php esc_html_e( 'Share event', 'lnkup-marketplace' ); ?></h3>
			<p class="lnkup-muted"><?php esc_html_e( 'Each vendor slot has its own public link.', 'lnkup-marketplace' ); ?></p>
			<ul id="lnkup-share-event-links" class="lnkup-share-event-links"></ul>
			<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-share-close><?php esc_html_e( 'Close', 'lnkup-marketplace' ); ?></button>
		</dialog>
		<?php
		return ob_get_clean();
	}

	/**
	 * Directory preview when Organizers or Vendors is opened without a profile query.
	 *
	 * @param string $kind organizer or vendor.
	 */
	private static function render_public_directory( $kind ) {
		$is_vendor = ( 'vendor' === $kind );
		$filters   = array(
			'q'        => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'city'     => isset( $_GET['city'] ) ? sanitize_text_field( wp_unslash( $_GET['city'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'state'    => isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'category' => isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		$cards     = $is_vendor ? self::public_vendor_directory_cards( $filters ) : self::public_organizer_directory_cards( $filters );
		$title     = $is_vendor ? __( 'Vendors', 'lnkup-marketplace' ) : __( 'Organizers', 'lnkup-marketplace' );
		$intro     = $is_vendor
			? __( 'Find a vendor by name, city, or category. Contact details stay private until you sign in.', 'lnkup-marketplace' )
			: __( 'Find an organizer by name or city. Contact details stay private.', 'lnkup-marketplace' );
		$terms = $is_vendor ? get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => true ) ) : array();
		ob_start();
		?>
		<div class="lnkup-mp lnkup-public-directory" data-testid="<?php echo esc_attr( $is_vendor ? 'lnkup-vendor-directory' : 'lnkup-organizer-directory' ); ?>">
			<header class="lnkup-public-directory__intro">
				<h1 class="lnkup-type-display"><?php echo esc_html( $title ); ?></h1>
				<p class="lnkup-muted"><?php echo esc_html( $intro ); ?></p>
			</header>
			<form class="lnkup-public-directory__search" method="get">
				<label>
					<?php echo esc_html( $is_vendor ? __( 'Business name', 'lnkup-marketplace' ) : __( 'Name', 'lnkup-marketplace' ) ); ?>
					<input type="search" name="q" value="<?php echo esc_attr( $filters['q'] ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'City', 'lnkup-marketplace' ); ?>
					<input type="search" name="city" value="<?php echo esc_attr( $filters['city'] ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'State', 'lnkup-marketplace' ); ?>
					<input type="search" name="state" value="<?php echo esc_attr( $filters['state'] ); ?>" />
				</label>
				<?php if ( $is_vendor && $terms && ! is_wp_error( $terms ) ) : ?>
					<label>
						<?php esc_html_e( 'Category', 'lnkup-marketplace' ); ?>
						<select name="category">
							<option value=""><?php esc_html_e( 'Any category', 'lnkup-marketplace' ); ?></option>
							<?php foreach ( $terms as $term ) : ?>
								<option value="<?php echo esc_attr( $term->name ); ?>" <?php selected( $filters['category'], $term->name ); ?>><?php echo esc_html( function_exists( 'lnkup_display_category_badge' ) ? lnkup_display_category_badge( $term->name ) : $term->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<button type="submit" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Search', 'lnkup-marketplace' ); ?></button>
			</form>
			<?php if ( ! $cards ) : ?>
				<p class="lnkup-empty"><?php echo esc_html( $is_vendor ? __( 'No vendors match that search.', 'lnkup-marketplace' ) : __( 'No organizers match that search.', 'lnkup-marketplace' ) ); ?></p>
			<?php else : ?>
				<div class="lnkup-public-directory__grid">
					<?php foreach ( $cards as $card ) : ?>
						<article class="lnkup-public-directory__card">
							<?php if ( ! empty( $card['cover'] ) ) : ?>
								<img class="lnkup-public-directory__cover" src="<?php echo esc_url( $card['cover'] ); ?>" alt="" width="640" height="360" loading="lazy" decoding="async" />
							<?php endif; ?>
							<a class="lnkup-public-directory__body" href="<?php echo esc_url( $card['url'] ); ?>">
								<span class="lnkup-public-directory__mark">
									<?php if ( ! empty( $card['logo'] ) ) : ?>
										<img src="<?php echo esc_url( $card['logo'] ); ?>" alt="" width="64" height="64" loading="lazy" decoding="async" />
									<?php else : ?>
										<?php echo esc_html( strtoupper( substr( (string) $card['name'], 0, 1 ) ) ); ?>
									<?php endif; ?>
								</span>
								<p class="lnkup-public-directory__name"><?php echo esc_html( $card['name'] ); ?></p>
								<?php if ( '' !== $card['where'] ) : ?>
									<p class="lnkup-public-directory__meta"><?php echo esc_html( $card['where'] ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $card['verified'] ) ) : ?>
									<p class="lnkup-public-directory__line"><span class="lnkup-public-directory__badge"><?php esc_html_e( 'Verified', 'lnkup-marketplace' ); ?></span></p>
								<?php endif; ?>
								<?php if ( ! empty( $card['categories'] ) ) : ?>
									<p class="lnkup-public-directory__line">
										<?php foreach ( array_slice( (array) $card['categories'], 0, 3 ) as $cat_name ) : ?>
											<span class="lnkup-public-directory__badge"><?php echo esc_html( function_exists( 'lnkup_display_category_badge' ) ? lnkup_display_category_badge( $cat_name ) : $cat_name ); ?></span>
										<?php endforeach; ?>
									</p>
								<?php elseif ( '' !== $card['line'] ) : ?>
									<p class="lnkup-public-directory__line"><?php echo esc_html( $card['line'] ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $card['excerpt'] ) ) : ?>
									<p class="lnkup-public-directory__excerpt"><?php echo esc_html( $card['excerpt'] ); ?></p>
								<?php endif; ?>
								<?php if ( isset( $card['completed'] ) && $card['completed'] > 0 ) : ?>
									<p class="lnkup-public-directory__next"><?php echo esc_html( sprintf( _n( '%d completed event', '%d completed events', (int) $card['completed'], 'lnkup-marketplace' ), (int) $card['completed'] ) ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $card['next'] ) ) : ?>
									<p class="lnkup-public-directory__next"><?php echo esc_html( $card['next'] ); ?></p>
								<?php endif; ?>
							</a>
							<?php if ( ! empty( $card['connect_url'] ) ) : ?>
								<p class="lnkup-public-directory__connect"><a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( $card['connect_url'] ); ?>"><?php echo esc_html( $card['connect_label'] ); ?></a></p>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Next upcoming event label for a public organizer card.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	private static function public_organizer_next_event( $user_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_event_date',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'   => '_lnkup_organizer_user_id',
						'value' => (int) $user_id,
					),
					array(
						'key'     => '_lnkup_event_date',
						'value'   => current_time( 'Y-m-d' ),
						'compare' => '>=',
						'type'    => 'DATE',
					),
				),
			)
		);
		if ( ! $posts ) {
			return '';
		}
		$event = $posts[0];
		$date  = (string) get_post_meta( $event->ID, '_lnkup_event_date', true );
		$title = function_exists( 'lnkup_display_event_title' ) ? lnkup_display_event_title( $event->post_title, $date ) : $event->post_title;
		$when  = $date ? date_i18n( 'M j, Y', strtotime( $date ) ) : '';
		return trim( $title . ( $when ? ' · ' . $when : '' ) );
	}

	/**
	 * Organizers with a public name.
	 *
	 * @param array $filters Search filters.
	 * @return array<int, array{name:string,where:string,line:string,url:string}>
	 */
	private static function public_organizer_directory_cards( $filters = array() ) {
		$filters    = is_array( $filters ) ? $filters : array();
		$meta_query = array(
			array(
				'key'     => '_lnkup_organizer_public_name',
				'value'   => '',
				'compare' => '!=',
			),
		);
		if ( ! empty( $filters['q'] ) ) {
			$meta_query[] = array(
				'key'     => '_lnkup_organizer_public_name',
				'value'   => $filters['q'],
				'compare' => 'LIKE',
			);
		}
		if ( ! empty( $filters['city'] ) ) {
			$meta_query[] = array(
				'key'     => '_lnkup_organizer_city',
				'value'   => $filters['city'],
				'compare' => 'LIKE',
			);
		}
		if ( ! empty( $filters['state'] ) ) {
			$meta_query[] = array(
				'key'   => '_lnkup_organizer_state',
				'value' => $filters['state'],
			);
		}
		$users = get_users(
			array(
				'role'       => 'lnkup_organizer',
				'number'     => 24,
				'meta_query' => $meta_query,
			)
		);
		$cards = array();
		foreach ( $users as $user ) {
			$user_id = (int) $user->ID;
			$name    = (string) get_user_meta( $user_id, '_lnkup_organizer_public_name', true );
			if ( '' === $name ) {
				continue;
			}
			$city  = (string) get_user_meta( $user_id, '_lnkup_organizer_city', true );
			$state = (string) get_user_meta( $user_id, '_lnkup_organizer_state', true );
			$url     = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::organizer_public_url( $user_id ) : '';
			$logo_id = (int) get_user_meta( $user_id, '_lnkup_organizer_logo_id', true );
			$cover_id = (int) get_user_meta( $user_id, '_lnkup_organizer_cover_id', true );
			$next    = self::public_organizer_next_event( $user_id );
			$bio     = (string) get_user_meta( $user_id, '_lnkup_organizer_bio', true );
			$cards[] = array(
				'name'       => $name,
				'where'      => function_exists( 'lnkup_display_place' ) ? lnkup_display_place( $city, $state ) : trim( $city . ( $state ? ', ' . $state : '' ) ),
				'line'       => (string) get_user_meta( $user_id, '_lnkup_organizer_tagline', true ),
				'excerpt'    => $bio ? wp_trim_words( wp_strip_all_tags( $bio ), 18 ) : '',
				'logo'       => $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '',
				'cover'      => $cover_id ? (string) wp_get_attachment_image_url( $cover_id, 'medium' ) : '',
				'next'       => $next,
				'url'        => $url,
				'categories' => array(),
				'verified'   => class_exists( 'LNKup_Organizer_Onboarding' ) && LNKup_Organizer_Onboarding::organizer_is_publicly_verified( $user_id ),
				'connect_url'   => '',
				'connect_label' => '',
			);
		}
		return $cards;
	}

	/**
	 * Published vendor profiles for the public directory.
	 *
	 * @param array $filters Search filters.
	 * @return array<int, array{name:string,where:string,line:string,url:string}>
	 */
	private static function public_vendor_directory_cards( $filters = array() ) {
		if ( ! function_exists( 'lnkup_get_discoverable_vendors' ) ) {
			return array();
		}
		$filters = is_array( $filters ) ? $filters : array();
		$result  = lnkup_get_discoverable_vendors(
			array(
				'per_page'    => 24,
				'post_status' => 'publish',
				'q'           => (string) ( $filters['q'] ?? '' ),
				'city'        => (string) ( $filters['city'] ?? '' ),
				'state'       => (string) ( $filters['state'] ?? '' ),
				'category'    => (string) ( $filters['category'] ?? '' ),
			)
		);
		$cards = array();
		foreach ( (array) ( $result['posts'] ?? array() ) as $post ) {
			$profile_id = (int) $post->ID;
			$name       = (string) get_post_meta( $profile_id, '_lnkup_business_name', true );
			if ( '' === $name ) {
				$name = $post->post_title;
			}
			if ( '' === $name ) {
				continue;
			}
			$city  = (string) get_post_meta( $profile_id, '_lnkup_city', true );
			$state = (string) get_post_meta( $profile_id, '_lnkup_state', true );
			$names = wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			$line  = ( ! is_wp_error( $names ) && $names ) ? (string) $names[0] : '';
			$slug  = (string) get_post_meta( $profile_id, '_lnkup_public_slug', true );
			if ( '' === $slug ) {
				$slug = (string) $profile_id;
			}
			$url       = add_query_arg( 'vendor', $slug, lnkup_page_url_helper( 'vendors' ) );
			$logo_id   = (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true );
			$cover_id  = (int) get_post_meta( $profile_id, '_lnkup_cover_image_id', true );
			$verified  = 'approved' === (string) get_post_meta( $profile_id, '_lnkup_verification_status', true );
			$completed = 0;
			if ( class_exists( 'LNKup_Completed_Events' ) ) {
				$completed = count( (array) LNKup_Completed_Events::get_for_vendor( $profile_id, 'public' ) );
			}
			$cat_names = ( ! is_wp_error( $names ) && $names ) ? array_values( $names ) : array();
			$excerpt   = wp_trim_words( wp_strip_all_tags( $post->post_content ), 18 );
			$is_organizer = is_user_logged_in() && class_exists( 'LNKup_Roles' ) && ( LNKup_Roles::is_organizer() || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() ) );
			if ( $is_organizer ) {
				$connect_url   = add_query_arg( 'vendor', $profile_id, lnkup_page_url_helper( 'organizer-dashboard' ) );
				$connect_label = __( 'Invite', 'lnkup-marketplace' );
			} elseif ( ! is_user_logged_in() ) {
				$connect_url   = function_exists( 'lnkup_login_url_for' ) ? lnkup_login_url_for( $url ) : wp_login_url( $url );
				$connect_label = __( 'Connect With Vendor', 'lnkup-marketplace' );
			} else {
				$connect_url   = '';
				$connect_label = '';
			}
			$cards[] = array(
				'name'          => $name,
				'where'         => function_exists( 'lnkup_display_place' ) ? lnkup_display_place( $city, $state ) : trim( $city . ( $state ? ', ' . $state : '' ) ),
				'line'          => function_exists( 'lnkup_display_label' ) ? lnkup_display_label( $line ) : $line,
				'excerpt'       => $excerpt,
				'logo'          => $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '',
				'cover'         => $cover_id ? (string) wp_get_attachment_image_url( $cover_id, 'medium' ) : '',
				'categories'    => $cat_names,
				'verified'      => $verified,
				'completed'     => $completed,
				'next'          => '',
				'url'           => $url,
				'connect_url'   => $connect_url,
				'connect_label' => $connect_label,
			);
			if ( count( $cards ) >= 24 ) {
				break;
			}
		}
		return $cards;
	}

	/**
	 * Public organizer profile: storefront, upcoming events, then about.
	 */
	public static function render_organizer_public_profile() {
		$slug = isset( $_GET['organizer'] ) ? sanitize_title( wp_unslash( $_GET['organizer'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $slug ) {
			return self::render_public_directory( 'organizer' );
		}
		if ( ! class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			return '<p class="lnkup-empty">' . esc_html__( 'Organizer not found.', 'lnkup-marketplace' ) . '</p>';
		}
		$user_id = LNKup_Organizer_Onboarding::organizer_id_from_public_slug( $slug );
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return '<p class="lnkup-empty">' . esc_html__( 'Organizer not found.', 'lnkup-marketplace' ) . '</p>';
		}
		$display_name = (string) get_user_meta( $user_id, '_lnkup_organizer_public_name', true );
		if ( '' === $display_name ) {
			$display_name = $user->display_name;
		}
		$tagline   = (string) get_user_meta( $user_id, '_lnkup_organizer_tagline', true );
		$bio       = (string) get_user_meta( $user_id, '_lnkup_organizer_bio', true );
		$city      = (string) get_user_meta( $user_id, '_lnkup_organizer_city', true );
		$state     = (string) get_user_meta( $user_id, '_lnkup_organizer_state', true );
		$service   = (string) get_user_meta( $user_id, '_lnkup_organizer_service_cities', true );
		$website   = (string) get_user_meta( $user_id, '_lnkup_organizer_website', true );
		$instagram = (string) get_user_meta( $user_id, '_lnkup_organizer_instagram', true );
		$tiktok    = (string) get_user_meta( $user_id, '_lnkup_organizer_tiktok', true );
		$where     = trim( $city . ( $state ? ', ' . $state : '' ) );
		$verified  = LNKup_Organizer_Onboarding::organizer_is_publicly_verified( $user_id );
		$logo_id   = (int) get_user_meta( $user_id, '_lnkup_organizer_logo_id', true );
		$cover_id  = (int) get_user_meta( $user_id, '_lnkup_organizer_cover_id', true );
		$logo_url  = $logo_id ? wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';
		$cover_url = $cover_id ? wp_get_attachment_image_url( $cover_id, 'large' ) : '';
		$initials  = strtoupper( substr( preg_replace( '/[^A-Za-z0-9]/', '', $display_name ), 0, 2 ) );
		if ( '' === $initials ) {
			$initials = 'LN';
		}
		$profile_url = LNKup_Organizer_Onboarding::organizer_public_url( $user_id );
		$events      = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $user_id,
			)
		);
		$today    = current_time( 'Y-m-d' );
		$upcoming = array();
		$past     = array();
		foreach ( $events as $event ) {
			$event_date = (string) get_post_meta( $event->ID, '_lnkup_event_date', true );
			if ( $event_date && $event_date < $today ) {
				$past[] = $event;
			} else {
				$upcoming[] = $event;
			}
		}
		usort(
			$upcoming,
			static function ( $a, $b ) {
				$a_date = (string) get_post_meta( $a->ID, '_lnkup_event_date', true );
				$b_date = (string) get_post_meta( $b->ID, '_lnkup_event_date', true );
				$a_date = '' !== $a_date ? $a_date : '9999-99-99';
				$b_date = '' !== $b_date ? $b_date : '9999-99-99';
				return strcmp( $a_date, $b_date );
			}
		);
		$cities     = array();
		$accepting  = array();
		foreach ( array_merge( $upcoming, $past ) as $event ) {
			$event_city = (string) get_post_meta( $event->ID, '_lnkup_city', true );
			if ( $event_city ) {
				$cities[ strtolower( $event_city ) ] = $event_city;
			}
		}
		foreach ( $upcoming as $event ) {
			foreach ( self::public_event_card_rows( $event ) as $row ) {
				if ( ! empty( $row['is_open'] ) && (int) ( $row['spots_remaining'] ?? 0 ) > 0 ) {
					$accepting[] = $event;
					break;
				}
			}
		}
		self::maybe_complete_pending_follow( $user_id );
		$instagram_url = self::public_social_url( $instagram, 'instagram' );
		$tiktok_url    = self::public_social_url( $tiktok, 'tiktok' );
		$stats         = array();
		if ( count( $upcoming ) > 0 ) {
			$stats[] = array( 'value' => count( $upcoming ), 'label' => __( 'Upcoming events', 'lnkup-marketplace' ) );
		}
		if ( count( $accepting ) > 0 ) {
			$stats[] = array( 'value' => count( $accepting ), 'label' => __( 'Accepting vendors', 'lnkup-marketplace' ) );
		}
		if ( count( $past ) > 0 ) {
			$stats[] = array( 'value' => count( $past ), 'label' => __( 'Past events', 'lnkup-marketplace' ) );
		}
		if ( count( $cities ) > 0 ) {
			$stats[] = array( 'value' => count( $cities ), 'label' => __( 'Cities', 'lnkup-marketplace' ) );
		}
		$vendors_booked = self::public_vendors_booked( wp_list_pluck( $events, 'ID' ) );
		if ( $vendors_booked > 0 ) {
			$stats[] = array( 'value' => $vendors_booked, 'label' => __( 'Vendors booked', 'lnkup-marketplace' ) );
		}
		$member_since = $user->user_registered ? (int) mysql2date( 'Y', $user->user_registered ) : 0;
		if ( $member_since ) {
			$stats[] = array( 'value' => $member_since, 'label' => __( 'On LNKup since', 'lnkup-marketplace' ) );
		}
		$is_owner   = is_user_logged_in() && ( get_current_user_id() === (int) $user_id || LNKup_Capabilities::user_has_manage_all() );
		$is_vendor  = is_user_logged_in() && class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_vendor();
		$active_tab = ( $is_vendor && $accepting ) ? 'open' : 'upcoming';
		$org_tabs   = array(
			'upcoming' => array( __( 'Upcoming Events', 'lnkup-marketplace' ), count( $upcoming ) ),
			'open'     => array( __( 'Accepting Vendors', 'lnkup-marketplace' ), count( $accepting ) ),
			'past'     => array( __( 'Past Events', 'lnkup-marketplace' ), count( $past ) ),
			'about'    => array( __( 'About', 'lnkup-marketplace' ), null ),
		);
		$service_chips = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $service ) ) ) ) );
		ob_start();
		?>
		<div class="lnkup-mp lnkup-pub-storefront lnkup-org-profile" data-testid="lnkup-public-organizer-profile">
			<?php if ( $is_owner ) : ?>
				<p class="lnkup-public-ownerbar">
					<span class="lnkup-muted"><?php esc_html_e( 'This is how vendors see your profile.', 'lnkup-marketplace' ); ?></span>
					<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" href="<?php echo esc_url( lnkup_organizer_view_url( 'profile' ) ); ?>"><?php esc_html_e( 'Edit profile', 'lnkup-marketplace' ); ?></a>
					<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" href="<?php echo esc_url( lnkup_organizer_view_url( 'create' ) ); ?>"><?php esc_html_e( 'Launch event', 'lnkup-marketplace' ); ?></a>
				</p>
			<?php endif; ?>
			<header class="lnkup-pub-hero<?php echo $cover_url ? '' : ' lnkup-pub-hero--plain'; ?>">
				<?php if ( $cover_url ) : ?>
					<div class="lnkup-pub-hero__cover" style="background-image:url(<?php echo esc_url( $cover_url ); ?>)"></div>
				<?php endif; ?>
				<div class="lnkup-pub-hero__body">
					<div class="lnkup-pub-hero__identity">
						<?php if ( $logo_url ) : ?>
							<img class="lnkup-pub-hero__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: organizer name */ __( '%s logo', 'lnkup-marketplace' ), $display_name ) ); ?>" width="112" height="112" decoding="async" />
						<?php else : ?>
							<span class="lnkup-pub-hero__logo lnkup-pub-hero__logo--initials" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
						<?php endif; ?>
						<div class="lnkup-pub-hero__titles">
							<h1 class="lnkup-type-display"><?php echo esc_html( $display_name ); ?>
								<?php if ( $verified ) : ?><span class="lnkup-public-verified"><?php esc_html_e( 'Verified', 'lnkup-marketplace' ); ?></span><?php endif; ?>
							</h1>
							<?php if ( $where ) : ?><p class="lnkup-type-meta"><?php echo esc_html( function_exists( 'lnkup_display_place' ) ? lnkup_display_place( $city, $state ) : $where ); ?></p><?php endif; ?>
							<?php if ( $tagline ) : ?><p class="lnkup-pub-hero__category"><?php echo esc_html( $tagline ); ?></p><?php endif; ?>
							<?php if ( $website || $instagram_url || $tiktok_url ) : ?>
								<p class="lnkup-pub-socials">
									<?php if ( $website ) : ?><a href="<?php echo esc_url( $website ); ?>" rel="noopener noreferrer" target="_blank"><?php esc_html_e( 'Website', 'lnkup-marketplace' ); ?></a><?php endif; ?>
									<?php if ( $instagram_url ) : ?><a href="<?php echo esc_url( $instagram_url ); ?>" rel="noopener noreferrer" target="_blank"><?php esc_html_e( 'Instagram', 'lnkup-marketplace' ); ?></a><?php endif; ?>
									<?php if ( $tiktok_url ) : ?><a href="<?php echo esc_url( $tiktok_url ); ?>" rel="noopener noreferrer" target="_blank"><?php esc_html_e( 'TikTok', 'lnkup-marketplace' ); ?></a><?php endif; ?>
								</p>
							<?php endif; ?>
						</div>
					</div>
					<div class="lnkup-pub-hero__actions">
						<?php echo self::public_follow_control( $user_id, $profile_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<button type="button" class="lnkup-btn lnkup-btn--outline" data-copy-url="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Share Profile', 'lnkup-marketplace' ); ?></button>
					</div>
				</div>
			</header>
			<?php if ( $stats ) : ?>
				<ul class="lnkup-org-stats">
					<?php foreach ( $stats as $stat ) : ?>
						<li><strong><?php echo esc_html( (string) $stat['value'] ); ?></strong><span><?php echo esc_html( $stat['label'] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<div class="lnkup-org-tabs" data-org-tabs>
				<div class="lnkup-org-tabs__list" role="tablist" aria-label="<?php esc_attr_e( 'Organizer events', 'lnkup-marketplace' ); ?>">
					<?php foreach ( $org_tabs as $tab_key => $tab_meta ) : ?>
						<?php $tab_on = $tab_key === $active_tab; ?>
						<button type="button" role="tab" id="lnkup-org-tab-<?php echo esc_attr( $tab_key ); ?>" aria-selected="<?php echo $tab_on ? 'true' : 'false'; ?>" aria-controls="lnkup-org-panel-<?php echo esc_attr( $tab_key ); ?>" data-org-tab="<?php echo esc_attr( $tab_key ); ?>"<?php echo $tab_on ? '' : ' tabindex="-1"'; ?>>
							<?php echo esc_html( $tab_meta[0] ); ?>
							<?php if ( null !== $tab_meta[1] ) : ?>
								<span class="lnkup-org-tabs__count"><?php echo esc_html( (string) $tab_meta[1] ); ?></span>
							<?php endif; ?>
						</button>
					<?php endforeach; ?>
				</div>
				<section role="tabpanel" id="lnkup-org-panel-upcoming" aria-labelledby="lnkup-org-tab-upcoming" data-org-panel="upcoming"<?php echo 'upcoming' === $active_tab ? '' : ' hidden'; ?>>
					<?php echo self::organizer_event_grid( $upcoming, __( 'No upcoming events yet.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</section>
				<section role="tabpanel" id="lnkup-org-panel-open" aria-labelledby="lnkup-org-tab-open" data-org-panel="open"<?php echo 'open' === $active_tab ? '' : ' hidden'; ?>>
					<?php echo self::organizer_event_grid( $accepting, __( 'No events are accepting vendors right now.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</section>
				<section role="tabpanel" id="lnkup-org-panel-past" aria-labelledby="lnkup-org-tab-past" data-org-panel="past" hidden>
					<?php echo self::organizer_event_grid( $past, __( 'No past events yet.', 'lnkup-marketplace' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</section>
				<section role="tabpanel" id="lnkup-org-panel-about" class="lnkup-surface--primary lnkup-org-profile__about" aria-labelledby="lnkup-org-tab-about" data-org-panel="about" hidden>
					<?php if ( $bio ) : ?>
						<div class="lnkup-public-event__prose"><?php echo wp_kses_post( wpautop( $bio ) ); ?></div>
					<?php elseif ( $tagline ) : ?>
						<p><?php echo esc_html( $tagline ); ?></p>
					<?php else : ?>
						<p class="lnkup-empty"><?php esc_html_e( 'This organizer has not added a description yet.', 'lnkup-marketplace' ); ?></p>
					<?php endif; ?>
					<?php if ( $service_chips ) : ?>
						<h3 class="lnkup-type-label"><?php esc_html_e( 'Markets served', 'lnkup-marketplace' ); ?></h3>
						<ul class="lnkup-pub-chips">
							<?php foreach ( $service_chips as $chip ) : ?>
								<li class="lnkup-badge lnkup-badge--outline"><?php echo esc_html( $chip ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( $cities ) : ?>
						<h3 class="lnkup-type-label"><?php esc_html_e( 'Cities hosted', 'lnkup-marketplace' ); ?></h3>
						<ul class="lnkup-pub-chips">
							<?php foreach ( $cities as $city_name ) : ?>
								<li class="lnkup-badge lnkup-badge--outline"><?php echo esc_html( $city_name ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Paid or completed bookings across the given events, for public social proof.
	 *
	 * @param int[] $event_ids Event IDs.
	 * @return int
	 */
	private static function public_vendors_booked( $event_ids ) {
		$event_ids = array_values( array_filter( array_map( 'intval', (array) $event_ids ) ) );
		if ( ! $event_ids ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'              => 'lnkup_application',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_lnkup_event_id',
						'value'   => $event_ids,
						'compare' => 'IN',
					),
					array(
						'key'     => '_lnkup_application_status',
						'value'   => array( 'paid', 'completed' ),
						'compare' => 'IN',
					),
				),
			)
		);
		return count( $ids );
	}

	/**
	 * Shared event cards for one organizer tab.
	 *
	 * @param array<int, WP_Post> $events Event posts.
	 * @param string              $empty  Empty-state copy.
	 */
	private static function organizer_event_grid( $events, $empty ) {
		if ( ! $events || ! function_exists( 'lnkup_render_homepage_event_card' ) ) {
			return '<p class="lnkup-empty">' . esc_html( $empty ) . '</p>';
		}
		$html = '<div class="lnkup-event-grid lnkup-event-grid--public">';
		foreach ( $events as $event ) {
			$html .= lnkup_render_homepage_event_card( self::public_event_card_rows( $event ), 'storefront' );
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Drop a trailing venue and weekday date from a title when the date is shown separately.
	 *
	 * @param string $title Event title.
	 * @param string $date  Y-m-d event date, or empty.
	 */
	/**
	 * Follow control for a public organizer. Vendors toggle the existing favorite.
	 *
	 * @param int    $organizer_id Organizer user ID.
	 * @param string $return_url   Page to reopen after signup.
	 */
	private static function public_follow_control( $organizer_id, $return_url ) {
		$organizer_id = (int) $organizer_id;
		if ( $organizer_id < 1 || get_current_user_id() === $organizer_id ) {
			return '';
		}
		$is_vendor = is_user_logged_in() && (
			( class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_vendor() )
			|| ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() )
		);
		if ( ! is_user_logged_in() ) {
			$return = add_query_arg( 'follow', $organizer_id, $return_url );
			$signup = function_exists( 'lnkup_registration_url' ) ? lnkup_registration_url() : wp_registration_url();
			$signup = add_query_arg(
				array(
					'role'     => 'vendor',
					'redirect' => rawurlencode( $return ),
				),
				$signup
			);
			$login = function_exists( 'lnkup_login_url_for' ) ? lnkup_login_url_for( $return ) : wp_login_url( $return );
			return '<a class="lnkup-btn lnkup-btn--outline" href="' . esc_url( $signup ) . '">' . esc_html__( 'Follow Organizer', 'lnkup-marketplace' ) . '</a>'
				. ' <a class="lnkup-btn lnkup-btn--ghost" href="' . esc_url( $login ) . '">' . esc_html__( 'Log in', 'lnkup-marketplace' ) . '</a>';
		}
		if ( ! $is_vendor || ! class_exists( 'LNKup_Vendor_Personalization' ) ) {
			return '';
		}
		$following = LNKup_Vendor_Personalization::is_favorite( get_current_user_id(), 'organizers', $organizer_id );
		$label     = $following ? __( 'Following', 'lnkup-marketplace' ) : __( 'Follow Organizer', 'lnkup-marketplace' );
		return '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm lnkup-follow-organizer' . ( $following ? ' is-following' : '' ) . '" data-follow-organizer="' . esc_attr( (string) $organizer_id ) . '" data-label-follow="' . esc_attr__( 'Follow Organizer', 'lnkup-marketplace' ) . '" data-label-following="' . esc_attr__( 'Following', 'lnkup-marketplace' ) . '" aria-pressed="' . ( $following ? 'true' : 'false' ) . '">' . esc_html( $label ) . '</button>';
	}

	/**
	 * Google Calendar "add event" link. Times are site-local; all-day when no start time is set.
	 *
	 * @param string $title    Event title.
	 * @param string $date     Event date (Y-m-d).
	 * @param string $start    Start time.
	 * @param string $end      End time.
	 * @param string $location Location text.
	 * @param string $url      Public event URL for the details field.
	 * @return string Empty when the event has no date.
	 */
	private static function public_event_calendar_url( $title, $date, $start, $end, $location, $url ) {
		$day = $date ? strtotime( $date ) : false;
		if ( ! $day ) {
			return '';
		}
		$start_ts = $start ? strtotime( $date . ' ' . $start ) : false;
		if ( $start_ts ) {
			$end_ts = $end ? strtotime( $date . ' ' . $end ) : false;
			if ( ! $end_ts || $end_ts <= $start_ts ) {
				$end_ts = $start_ts + 2 * HOUR_IN_SECONDS;
			}
			$dates = gmdate( 'Ymd\THis', $start_ts ) . '/' . gmdate( 'Ymd\THis', $end_ts );
		} else {
			$dates = gmdate( 'Ymd', $day ) . '/' . gmdate( 'Ymd', $day + DAY_IN_SECONDS );
		}
		return add_query_arg(
			array_map(
				'rawurlencode',
				array_filter(
					array(
						'action'   => 'TEMPLATE',
						'text'     => $title,
						'dates'    => $dates,
						'ctz'      => false !== strpos( wp_timezone_string(), '/' ) ? wp_timezone_string() : '',
						'location' => $location,
						'details'  => $url,
					)
				)
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	/**
	 * Share link for a bookable event. Referrers get their existing signup code.
	 *
	 * @param int $event_id Event post ID.
	 */
	private static function public_event_share_url( $event_id ) {
		$event_url = class_exists( 'LNKup_Organizer_Onboarding' )
			? LNKup_Organizer_Onboarding::event_public_url( (int) $event_id )
			: get_permalink( (int) $event_id );
		$user_id = get_current_user_id();
		if ( ! $user_id || ! class_exists( 'LNKup_Organizer_Recruit_Referrals' ) || ! LNKup_Organizer_Recruit_Referrals::user_can_refer( $user_id ) ) {
			return $event_url;
		}
		$row = LNKup_Organizer_Recruit_Referrals::get_or_create_code( $user_id );
		if ( ! is_array( $row ) || empty( $row['code'] ) ) {
			return $event_url;
		}
		$signup = LNKup_Organizer_Recruit_Referrals::share_url( (string) $row['code'], 'vendor' );
		return add_query_arg( 'redirect', rawurlencode( $event_url ), $signup );
	}

	/**
	 * Lowest open fee and open spot count for the event summary.
	 *
	 * @param float $fee   Lowest open vendor fee.
	 * @param int   $spots Open spots across those slots.
	 */
	private static function public_event_open_summary( $fee, $spots ) {
		$fee_label = (float) $fee > 0
			? ( function_exists( 'lnkup_currency' ) ? lnkup_currency( $fee ) : '$' . number_format( (float) $fee, 2 ) )
			: __( 'Free', 'lnkup-marketplace' );
		return sprintf(
			/* translators: 1: fee or Free, 2: number of open spots */
			__( 'From %1$s · %2$s', 'lnkup-marketplace' ),
			$fee_label,
			sprintf( _n( '%d spot open', '%d spots open', (int) $spots, 'lnkup-marketplace' ), (int) $spots )
		);
	}

	private static function public_event_display_title( $title, $date, $venue = '', $organizer = '' ) {
		if ( function_exists( 'lnkup_display_event_title' ) ) {
			return lnkup_display_event_title( $title, $date, $venue, $organizer );
		}
		return (string) $title;
	}

	/**
	 * One public card row for a slot, scrubbed for the current viewer. Null for drafts and
	 * invite-only slots, which never appear on public cards.
	 *
	 * @param WP_Post $slot     Opportunity post.
	 * @param int     $event_id Event ID.
	 * @param string  $city     Event city.
	 * @param string  $state    Event state.
	 * @param string  $date     Event date.
	 * @return array<string, mixed>|null
	 */
	private static function public_slot_card_row( $slot, $event_id, $city, $state, $date ) {
		if ( 'publish' !== $slot->post_status || get_post_meta( $slot->ID, '_lnkup_invite_only', true ) ) {
			return null;
		}
		$status   = (string) get_post_meta( $slot->ID, '_lnkup_opportunity_status', true );
		$deadline = (string) get_post_meta( $slot->ID, '_lnkup_application_deadline', true );
		$past_due = $deadline && $deadline < current_time( 'Y-m-d' );
		$names    = wp_get_object_terms( $slot->ID, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
		$row      = array(
			'event_id'        => (int) $event_id,
			'opportunity_id'  => (int) $slot->ID,
			'city'            => $city,
			'state'           => $state,
			'date'            => $date,
			'deadline'        => $deadline,
			'vendor_fee'      => (float) get_post_meta( $slot->ID, '_lnkup_vendor_fee', true ),
			'spots_remaining' => (int) get_post_meta( $slot->ID, '_lnkup_spots_remaining', true ),
			'total_spots'     => (int) get_post_meta( $slot->ID, '_lnkup_spots_total', true ),
			'is_open'         => ( '' === $status || 'open' === $status ) && ! $past_due,
			'categories'      => is_wp_error( $names ) ? array() : $names,
			'badges'          => array(),
		);
		return LNKup_Access_Policy::scrub_opportunity_item( $row );
	}

	/**
	 * Slot rows for the shared public event card.
	 *
	 * @param WP_Post $event Event post.
	 * @return array<int, array<string, mixed>>
	 */
	private static function public_event_card_rows( $event ) {
		$event_id = (int) $event->ID;
		$city     = (string) get_post_meta( $event_id, '_lnkup_city', true );
		$state    = (string) get_post_meta( $event_id, '_lnkup_state', true );
		$date     = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$rows     = array();
		if ( class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			foreach ( LNKup_Organizer_Onboarding::event_slot_posts( $event_id ) as $slot ) {
				$row = self::public_slot_card_row( $slot, $event_id, $city, $state, $date );
				if ( null !== $row ) {
					$rows[] = $row;
				}
			}
		}
		if ( ! $rows ) {
			$rows[] = array(
				'event_id'        => $event_id,
				'city'            => $city,
				'state'           => $state,
				'date'            => $date,
				'spots_remaining' => 0,
				'is_open'         => false,
				'categories'      => array(),
				'badges'          => array(),
			);
		}
		return $rows;
	}

	/**
	 * Apply a follow requested before signup, once, for a signed-in vendor.
	 *
	 * @param int $organizer_id Organizer on this page.
	 */
	private static function maybe_complete_pending_follow( $organizer_id ) {
		$organizer_id = (int) $organizer_id;
		$requested    = isset( $_GET['follow'] ) ? absint( wp_unslash( (string) $_GET['follow'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $organizer_id < 1 || $requested !== $organizer_id || ! is_user_logged_in() ) {
			return;
		}
		$is_vendor = ( class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_vendor() )
			|| ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() );
		if ( ! $is_vendor || ! class_exists( 'LNKup_Vendor_Personalization' ) ) {
			return;
		}
		if ( LNKup_Vendor_Personalization::is_favorite( get_current_user_id(), 'organizers', $organizer_id ) ) {
			return;
		}
		LNKup_Vendor_Personalization::toggle_favorite( get_current_user_id(), 'organizers', $organizer_id );
	}

	/**
	 * Public website-style URL for an Instagram or TikTok value.
	 *
	 * @param string $value   Handle or URL.
	 * @param string $network instagram|tiktok.
	 */
	private static function public_social_url( $value, $network ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			return $value;
		}
		$handle = rawurlencode( ltrim( $value, '@' ) );
		if ( 'tiktok' === $network ) {
			return 'https://www.tiktok.com/@' . $handle;
		}
		return 'https://instagram.com/' . $handle;
	}


	/**
	 * Public vendor profile shortcode.
	 */
	public static function render_vendor_public_profile() {
		$slug = class_exists( 'LNKup_Passport_Public' ) ? LNKup_Passport_Public::current_slug() : '';
		if ( '' === $slug && ! empty( $_GET['vendor'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$slug = sanitize_title( wp_unslash( (string) $_GET['vendor'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! $slug ) {
			return self::render_public_directory( 'vendor' );
		}
		$resolved   = LNKup_Vendor_Reputation::resolve_viewable_profile( $slug, get_current_user_id() );
		$profile_id = (int) $resolved['profile_id'];
		if ( ! $profile_id ) {
			return '<p class="lnkup-empty">' . esc_html__( 'This vendor profile is private or no longer available.', 'lnkup-marketplace' ) . '</p>';
		}
		$data = LNKup_Vendor_CRM_Profile::get_public( $profile_id, ! empty( $resolved['is_preview'] ) );
		if ( ! $data ) {
			return '<p class="lnkup-empty">' . esc_html__( 'Vendor not found.', 'lnkup-marketplace' ) . '</p>';
		}
		$markets      = array_filter( array_merge( (array) ( $data['cities_served'] ?? array() ), (array) ( $data['states_served'] ?? array() ) ) );
		$crm_url      = add_query_arg( 'vendor', $profile_id, lnkup_page_url_helper( 'organizer-dashboard' ) );
		$is_organizer = is_user_logged_in() && ( LNKup_Roles::is_organizer() || LNKup_Capabilities::user_has_manage_all() );
		$is_owner     = is_user_logged_in() && (int) lnkup_get_vendor_profile_id( get_current_user_id() ) === (int) $profile_id;
		$share_url    = ! empty( $data['public_slug'] ) && empty( $resolved['is_preview'] ) ? LNKup_Passport_Public::url_for_slug( (string) $data['public_slug'] ) : '';
		$actions      = $is_organizer ? '<a class="lnkup-btn lnkup-btn--primary" href="' . esc_url( $crm_url ) . '">' . esc_html__( 'Invite to an event', 'lnkup-marketplace' ) . '</a>' : '';
		if ( $share_url ) {
			$actions .= '<button type="button" class="lnkup-btn lnkup-btn--outline" data-copy-url="' . esc_url( $share_url ) . '">' . esc_html__( 'Share profile', 'lnkup-marketplace' ) . '</button>';
		}
		$instagram_url = self::public_social_url( (string) ( $data['instagram'] ?? '' ), 'instagram' );
		$tiktok_url    = self::public_social_url( (string) ( $data['tiktok'] ?? '' ), 'tiktok' );
		$setup_rows    = array_filter(
			array(
				__( 'Setup', 'lnkup-marketplace' )   => (string) ( $data['setup_type'] ?? '' ),
				__( 'Tent', 'lnkup-marketplace' )    => (string) ( $data['tent_size'] ?? '' ),
				__( 'Trailer', 'lnkup-marketplace' ) => (string) ( $data['trailer_size'] ?? '' ),
				__( 'Power', 'lnkup-marketplace' )   => (string) ( $data['power_needs'] ?? '' ),
				__( 'Water', 'lnkup-marketplace' )   => (string) ( $data['water_needs'] ?? '' ),
			),
			static fn( $value ) => '' !== trim( $value )
		);
		ob_start();
		?>
		<div class="lnkup-mp lnkup-pub-storefront" id="lnkup-public-vendor-profile" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>" data-testid="lnkup-public-vendor-profile">
			<?php if ( $is_owner ) : ?>
				<p class="lnkup-public-ownerbar">
					<span class="lnkup-muted">
						<?php
						if ( ! empty( $resolved['is_preview'] ) ) {
							esc_html_e( 'Your public Passport is private. Only you can see this preview.', 'lnkup-marketplace' );
						} else {
							esc_html_e( 'This is how organizers see your profile.', 'lnkup-marketplace' );
						}
						?>
					</span>
					<a class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" href="<?php echo esc_url( lnkup_page_url_helper( 'vendor-profile' ) ); ?>"><?php esc_html_e( 'Edit profile', 'lnkup-marketplace' ); ?></a>
				</p>
			<?php endif; ?>
			<?php echo LNKup_Reputation_Render::public_hero( $data, $actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo LNKup_Reputation_Render::public_metrics_row( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( ! empty( $data['gallery'] ) ) : ?>
				<?php echo LNKup_Reputation_Render::public_gallery( $data['gallery'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
			<div class="lnkup-pub-layout lnkup-pub-layout--aside">
				<div class="lnkup-pub-main">
					<section class="lnkup-pub-about lnkup-surface--primary">
						<h2 class="lnkup-type-h2"><?php esc_html_e( 'About this business', 'lnkup-marketplace' ); ?></h2>
						<?php if ( ! empty( $data['description'] ) ) : ?>
							<div class="lnkup-type-body lnkup-public-event__prose"><?php echo wp_kses_post( wpautop( esc_html( wp_strip_all_tags( (string) $data['description'] ) ) ) ); ?></div>
						<?php else : ?>
							<p class="lnkup-muted"><?php esc_html_e( 'This vendor has not added a description yet.', 'lnkup-marketplace' ); ?></p>
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
						<?php if ( $markets ) : ?>
							<p class="lnkup-type-meta"><strong><?php esc_html_e( 'Markets served', 'lnkup-marketplace' ); ?></strong> — <?php echo esc_html( implode( ', ', $markets ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $data['website'] ) || $instagram_url || $tiktok_url ) : ?>
							<div class="lnkup-pub-socials">
								<?php if ( ! empty( $data['website'] ) ) : ?>
									<a href="<?php echo esc_url( $data['website'] ); ?>" rel="noopener noreferrer" target="_blank"><?php esc_html_e( 'Website', 'lnkup-marketplace' ); ?></a>
								<?php endif; ?>
								<?php if ( $instagram_url ) : ?>
									<a href="<?php echo esc_url( $instagram_url ); ?>" rel="noopener noreferrer" target="_blank">Instagram</a>
								<?php endif; ?>
								<?php if ( $tiktok_url ) : ?>
									<a href="<?php echo esc_url( $tiktok_url ); ?>" rel="noopener noreferrer" target="_blank">TikTok</a>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</section>
					<?php echo LNKup_Reputation_Render::public_reviews_section( $data['reviews'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<section class="lnkup-section">
						<h2 class="lnkup-type-h2"><?php esc_html_e( 'Event history', 'lnkup-marketplace' ); ?></h2>
						<?php if ( empty( $data['events'] ) ) : ?>
							<?php
							echo lnkup_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								array(
									'title'   => __( 'No completed events yet', 'lnkup-marketplace' ),
									'desc'    => __( 'Events this vendor completes on LNKup will show up here.', 'lnkup-marketplace' ),
									'compact' => true,
								)
							);
							?>
						<?php else : ?>
							<?php echo LNKup_Reputation_Render::completed_events_timeline( $data['events'], 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</section>
				</div>
				<?php
				$trust_html      = LNKup_Reputation_Render::public_trust_strip( $data );
				$indicators_html = LNKup_Reputation_Render::indicators_card( $data['indicators'] ?? array() );
				if ( $trust_html || $setup_rows || $indicators_html ) :
					?>
				<aside class="lnkup-pub-aside" aria-label="<?php esc_attr_e( 'Vendor details', 'lnkup-marketplace' ); ?>" data-testid="lnkup-public-vendor-aside">
					<?php if ( $indicators_html ) : ?>
						<section class="lnkup-pub-trust lnkup-surface--secondary">
							<h2 class="lnkup-type-label"><?php esc_html_e( 'Vendor Passport', 'lnkup-marketplace' ); ?></h2>
							<?php echo $indicators_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</section>
					<?php endif; ?>
					<?php echo $trust_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php if ( $share_url ) : ?>
						<section class="lnkup-pub-share lnkup-surface--secondary" data-testid="lnkup-public-vendor-share">
							<h2 class="lnkup-type-label"><?php esc_html_e( 'Share this Passport', 'lnkup-marketplace' ); ?></h2>
							<img class="lnkup-pub-share__qr" loading="lazy" width="160" height="160" src="<?php echo esc_attr( class_exists( 'LNKup_QR' ) ? LNKup_QR::data_uri( $share_url, 160 ) : '' ); ?>" alt="<?php esc_attr_e( 'QR code for this vendor profile', 'lnkup-marketplace' ); ?>" />
							<button type="button" class="lnkup-btn lnkup-btn--sm lnkup-btn--outline" data-copy-url="<?php echo esc_url( $share_url ); ?>"><?php esc_html_e( 'Copy link', 'lnkup-marketplace' ); ?></button>
						</section>
					<?php endif; ?>
					<?php if ( $setup_rows ) : ?>
						<section class="lnkup-pub-setup lnkup-surface--secondary" data-testid="lnkup-public-vendor-setup">
							<h2 class="lnkup-type-label"><?php esc_html_e( 'What they bring', 'lnkup-marketplace' ); ?></h2>
							<dl class="lnkup-pub-setup__list">
								<?php foreach ( $setup_rows as $setup_label => $setup_value ) : ?>
									<div>
										<dt><?php echo esc_html( $setup_label ); ?></dt>
										<dd><?php echo esc_html( $setup_value ); ?></dd>
									</div>
								<?php endforeach; ?>
							</dl>
						</section>
					<?php endif; ?>
				</aside>
				<?php endif; ?>
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
		$nav  = LNKup_Connector::dashboard_views( $connector );
		ob_start();
		?>
		<div id="lnkup-connector-dashboard" class="lnkup-connector-dashboard" data-view="<?php echo esc_attr( $view ); ?>" data-program="<?php echo esc_attr( LNKup_Connector::program_of( $connector ) ); ?>" data-views="<?php echo esc_attr( implode( ',', array_keys( $nav ) ) ); ?>" data-testid="lnkup-connector-dashboard">
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
			<h1 class="lnkup-type-h2"><?php echo esc_html( lnkup_copy( 'claim_event_title' ) ); ?></h1>
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
