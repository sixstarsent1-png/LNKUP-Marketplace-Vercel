<?php
/**
 * Meta Pixel (browser) loader.
 *
 * Prints one small config object in <head> and loads public/js/lnkup-meta-pixel.js, which
 * initializes the Pixel once. If another plugin already defined window.fbq, the script does
 * not initialize a second time; it sends LNKup's authoritative events through
 * trackSingle with the same event_id and reports the conflict to wp-admin.
 *
 * Server-recorded conversions (registration, application, checkout, purchase) reach the
 * browser through the per-user pending queue so both sides share one event_id.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Meta_Pixel {

	const HANDLE = 'lnkup-meta-pixel';

	/** Plugins known to inject their own Meta Pixel. */
	const KNOWN_PIXEL_PLUGINS = array(
		'official-facebook-pixel/facebook-for-wordpress.php' => 'Meta Pixel for WordPress',
		'facebook-for-woocommerce/facebook-for-woocommerce.php' => 'Facebook for WooCommerce',
		'pixelyoursite/facebook-pixel-master.php'            => 'PixelYourSite',
		'pixelyoursite-pro/pixelyoursite-pro.php'            => 'PixelYourSite Pro',
		'pixel-caffeine/pixel-caffeine.php'                  => 'Pixel Caffeine',
		'facebook-conversion-pixel/facebook-conversion-pixel.php' => 'Facebook Conversion Pixel',
		'wp-facebook-pixel/wp-facebook-pixel.php'            => 'WP Facebook Pixel',
	);

	private static bool $printed = false;

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 1 );
	}

	/**
	 * Active plugins that are known to add a Meta Pixel.
	 *
	 * @return array<string,string>
	 */
	public static function detected_plugins(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$found = array();
		foreach ( self::KNOWN_PIXEL_PLUGINS as $file => $label ) {
			if ( in_array( $file, $active, true ) ) {
				$found[ $file ] = $label;
			}
		}
		return $found;
	}

	private static function should_load(): bool {
		if ( self::$printed || ! LNKup_Meta_Config::pixel_active() ) {
			return false;
		}
		if ( is_admin() || is_feed() || is_robots() || is_trackback() || wp_doing_ajax() || is_customize_preview() ) {
			return false;
		}
		$user_id = get_current_user_id();
		if ( LNKup_Meta_Config::is_excluded_user( $user_id ) || 'denied' === LNKup_Tracking_Consent::state( $user_id, true ) ) {
			return false;
		}
		return ! apply_filters( 'lnkup_meta_pixel_skip', false );
	}

	/**
	 * ViewContent parameters for marketplace pages (public, non-personal; safe in cached HTML).
	 *
	 * @return array<string,mixed>|null
	 */
	private static function view_content(): ?array {
		if ( is_singular( array( 'lnkup_event', 'lnkup_opportunity' ) ) ) {
			$post_id = (int) get_queried_object_id();
			return array(
				'content_ids'      => array( (string) $post_id ),
				'content_type'     => 'product',
				'content_name'     => wp_strip_all_tags( (string) get_the_title( $post_id ) ),
				'content_category' => 'lnkup_event' === get_post_type( $post_id ) ? 'event' : 'opportunity',
			);
		}
		if ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_market_request() ) {
			return array( 'content_name' => LNKup_Market_Pages::current_slug(), 'content_category' => 'market' );
		}
		if ( class_exists( 'LNKup_Portfolio_Public' ) && '' !== (string) get_query_var( LNKup_Portfolio_Public::QUERY_VAR ) ) {
			return array( 'content_name' => sanitize_title( (string) get_query_var( LNKup_Portfolio_Public::QUERY_VAR ) ), 'content_category' => 'tour' );
		}
		if ( class_exists( 'LNKup_Passport_Public' ) && '' !== (string) get_query_var( LNKup_Passport_Public::QUERY_VAR ) ) {
			return array( 'content_name' => sanitize_title( (string) get_query_var( LNKup_Passport_Public::QUERY_VAR ) ), 'content_category' => 'vendor_profile' );
		}
		return null;
	}

	/**
	 * Sign-up page role hint (organizer/vendor) for the RegistrationStarted events.
	 */
	private static function registration_role(): string {
		if ( ! is_page( 'sign-up' ) ) {
			return '';
		}
		$role = isset( $_GET['role'] ) ? sanitize_key( wp_unslash( (string) $_GET['role'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $role, array( 'organizer', 'vendor' ), true ) ? $role : 'any';
	}

	public static function enqueue(): void {
		if ( ! self::should_load() ) {
			return;
		}
		self::$printed = true;

		$user_id = get_current_user_id();
		$pending = $user_id ? LNKup_Conversion_Registry::consume_browser_events( $user_id ) : array();
		$client  = array( 'ViewContent', 'Search', 'OrganizerRegistrationStarted', 'VendorRegistrationStarted' );
		$config  = array(
			'pixelId'          => LNKup_Meta_Config::pixel_id(),
			'restUrl'          => esc_url_raw( rest_url( 'lnkup/v1/' ) ),
			'nonce'            => $user_id ? wp_create_nonce( 'wp_rest' ) : '',
			'loggedIn'         => $user_id > 0,
			'capi'             => '' === LNKup_Meta_Config::capi_block_reason(),
			'advancedMatching' => $user_id ? array( 'external_id' => LNKup_Meta_User_Data::external_id( $user_id ) ) : new stdClass(),
			'dpo'              => LNKup_Meta_Config::data_processing_options(),
			'pending'          => array_map(
				static function ( $event ) {
					return array(
						'name'     => (string) $event['name'],
						'eventId'  => (string) $event['eventId'],
						'data'     => (array) $event['data'],
						'standard' => ! empty( $event['standard'] ),
					);
				},
				$pending
			),
			'clientEvents'     => $client,
			'standardEvents'   => array( 'PageView', 'ViewContent', 'Search', 'AddToWishlist', 'CompleteRegistration', 'SubmitApplication', 'InitiateCheckout', 'Purchase', 'Lead', 'Contact' ),
			'viewContent'      => self::view_content(),
			'registrationRole' => self::registration_role(),
		);

		// Loaded in <head> (async, non-blocking) so it runs before footer-injected third-party Pixels.
		wp_enqueue_script( self::HANDLE, LNKUP_PLUGIN_URL . 'public/js/lnkup-meta-pixel.js', array(), LNKUP_VERSION, array( 'in_footer' => false, 'strategy' => 'async' ) );
		wp_add_inline_script( self::HANDLE, 'window.lnkupMeta = ' . wp_json_encode( $config ) . ';', 'before' );
	}
}
