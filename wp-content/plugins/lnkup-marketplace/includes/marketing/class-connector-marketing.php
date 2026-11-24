<?php
/**
 * City Connector / partner "Share & Track" toolkit (flag tracking_links).
 *
 * Tracked links per channel to public LNKup pages (markets, weekly digests, categories, sign-up)
 * that carry the connector's existing referral code (?ref=), so the referral program keeps
 * attributing and paying exactly as before; local QR codes; market share graphics with connector
 * branding; and per-link clicks, sign-ups, applications and bookings. Commissions stay with
 * LNKup_Connector_Rewards.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Connector_Marketing {

	const TARGETS = array( 'market', 'category', 'signup', 'feed' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 40 );
	}

	/**
	 * Active connector row for the current user, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function connector(): ?array {
		if ( ! class_exists( 'LNKup_Connector' ) || ! class_exists( 'LNKup_Tracking_Links' ) || ! LNKup_Tracking_Links::active() ) {
			return null;
		}
		$row = LNKup_Connector::get_by_user_id( get_current_user_id() );
		return ( $row && 'active' === ( $row['status'] ?? '' ) ) ? $row : null;
	}

	public static function routes(): void {
		$perm = static fn(): bool => null !== self::connector();
		register_rest_route( 'lnkup/v1', '/connector/marketing/context', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_context' ), 'permission_callback' => $perm ) );
		register_rest_route(
			'lnkup/v1',
			'/connector/marketing/links',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_links' ), 'permission_callback' => $perm ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_create' ), 'permission_callback' => $perm ),
			)
		);
		register_rest_route( 'lnkup/v1', '/connector/marketing/links/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_status' ), 'permission_callback' => $perm ) );
	}

	/**
	 * Markets the connector can promote: their own market first, then other live markets.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function markets( array $connector ): array {
		$out  = array();
		$mine = (string) ( $connector['market_slug'] ?? '' );
		foreach ( LNKup_Market_Pages::get_all_markets() as $slug => $raw ) {
			$market = LNKup_Market_Pages::get_market_by_slug( (string) ( is_array( $raw ) && ! empty( $raw['slug'] ) ? $raw['slug'] : $slug ) );
			if ( ! $market ) {
				continue;
			}
			$cats   = class_exists( 'LNKup_Market_Registry' ) ? array_slice( LNKup_Market_Registry::categories( $market ), 0, 5 ) : array();
			$digest = ( class_exists( 'LNKup_Market_Discovery' ) && LNKup_Market_Discovery::active() ) ? LNKup_Market_Discovery::build( $market, LNKup_Market_Discovery::DIGEST_SLUG ) : null;
			$out[]  = array(
				'slug'        => (string) $market['slug'],
				'name'        => (string) $market['display_name'],
				'state'       => (string) $market['state'],
				'mine'        => $market['slug'] === $mine,
				'url'         => LNKup_Market_Pages::market_url( (string) $market['slug'] ),
				'digest_url'  => $digest && count( (array) $digest['cards'] ) > 0 ? (string) $digest['url'] : '',
				'open_events' => class_exists( 'LNKup_Index_Policy' ) ? LNKup_Index_Policy::open_events_in_city( (string) $market['city'], (string) $market['state'] ) : 0,
				'categories'  => array_map( static fn( $c ) => array( 'label' => $c['label'], 'count' => $c['count'] ), $cats ),
			);
		}
		usort( $out, static fn( $a, $b ) => ( $b['mine'] <=> $a['mine'] ) ?: ( $b['open_events'] <=> $a['open_events'] ) );
		return $out;
	}

	public static function rest_context() {
		$connector = (array) self::connector();
		return rest_ensure_response(
			array(
				'connector' => array( 'name' => (string) ( $connector['display_name'] ?? '' ), 'market' => (string) ( $connector['market_slug'] ?? '' ) ),
				'markets'   => self::markets( $connector ),
				'channels'  => array_values( array_diff( LNKup_Tracking_Links::CHANNELS, array( 'app' ) ) ),
				'categories' => function_exists( 'lnkup_canonical_vendor_categories' ) ? lnkup_canonical_vendor_categories() : array(),
			)
		);
	}

	public static function rest_links() {
		$rows = LNKup_Tracking_Links::list_for_owner( get_current_user_id(), array( 'limit' => 200 ) );
		return rest_ensure_response( array( 'links' => array_map( array( 'LNKup_Organizer_Marketing', 'public_link' ), $rows ) ) );
	}

	public static function rest_create( WP_REST_Request $request ) {
		$body = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		$type = sanitize_key( (string) ( $body['target_type'] ?? 'market' ) );
		if ( ! in_array( $type, self::TARGETS, true ) ) {
			return new WP_Error( 'lnkup_link_target', __( 'Connector links can open market pages, categories, sign-up or the event feed.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$connector = (array) self::connector();
		$result    = LNKup_Tracking_Links::create(
			array(
				'owner_user_id'    => get_current_user_id(),
				'owner_type'       => 'connector',
				'owner_ref_id'     => (int) ( $connector['id'] ?? 0 ),
				'target_type'      => $type,
				'target_key'       => (string) ( $body['target_key'] ?? '' ),
				'label'            => (string) ( $body['label'] ?? '' ),
				'channel'          => (string) ( $body['channel'] ?? 'other' ),
				'campaign'         => (string) ( $body['campaign'] ?? '' ),
				'forward_referral' => true,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'link' => LNKup_Organizer_Marketing::public_link( array_merge( $result, array( 'clicks' => 0 ) ) ) ) );
	}

	public static function rest_status( WP_REST_Request $request ) {
		$body   = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		$result = LNKup_Tracking_Links::set_status( (int) $request['id'], sanitize_key( (string) ( $body['status'] ?? '' ) ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'link' => LNKup_Organizer_Marketing::public_link( $result ) ) );
	}

	public static function assets(): void {
		if ( ! is_page( 'connector-dashboard' ) || null === self::connector() ) {
			return;
		}
		wp_enqueue_style( 'lnkup-organizer-marketing', LNKUP_PLUGIN_URL . 'public/css/organizer-marketing.css', array(), LNKUP_VERSION );
		wp_enqueue_script( 'lnkup-connector-marketing', LNKUP_PLUGIN_URL . 'public/js/connector-marketing.js', array( 'lnkup-qr' ), LNKUP_VERSION, true );
		wp_localize_script( 'lnkup-connector-marketing', 'lnkupConnectorMarketing', array( 'rest' => esc_url_raw( rest_url( 'lnkup/v1/connector/marketing/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}
}
