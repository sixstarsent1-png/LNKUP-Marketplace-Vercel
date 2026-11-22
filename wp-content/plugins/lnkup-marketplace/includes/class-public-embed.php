<?php
/**
 * Public opportunity card for the embeddable "Apply on LNKup" button (public/js/embed.js).
 *
 * Returns only fields already shown on the public Find Events listing, and only for open slots
 * on live, non-invite-only events. The embed links out to LNKup to apply — no iframe or form
 * on the organizer's site, so no session or CSRF exposure.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Public_Embed {

	const RATE_LIMIT = 300;
	const CACHE_TTL  = 300;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'save_post_lnkup_opportunity', array( __CLASS__, 'flush' ), 20, 1 );
		add_action( 'lnkup_opportunity_status_changed', array( __CLASS__, 'flush' ), 20, 1 );
	}

	public static function flush( $opp_id ): void {
		delete_transient( 'lnkup_embed_card_' . (int) $opp_id );
	}

	public static function register_routes(): void {
		register_rest_route(
			'lnkup/v1',
			'/public/opportunities/(?P<id>\d+)/card',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'card' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private static function rate_limited(): bool {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key  = 'lnkup_embed_rl_' . substr( hash( 'sha256', $ip . wp_salt( 'nonce' ) ), 0, 20 );
		$hits = (int) get_transient( $key );
		if ( $hits >= self::RATE_LIMIT ) {
			return true;
		}
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
		return false;
	}

	/**
	 * Public card payload, or null when the slot must not be shown.
	 *
	 * @return array<string, mixed>|null
	 */
	/**
	 * Whether the slot may be shown publicly right now (checked on every request, never cached).
	 */
	public static function visible( int $opp_id ): bool {
		$opp = get_post( $opp_id );
		if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type || 'publish' !== $opp->post_status ) {
			return false;
		}
		$slot = (string) get_post_meta( $opp_id, '_lnkup_opportunity_status', true );
		if ( ( '' !== $slot && 'open' !== $slot ) || get_post_meta( $opp_id, '_lnkup_invite_only', true ) ) {
			return false;
		}
		$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		$event    = $event_id ? get_post( $event_id ) : null;
		return $event && 'publish' === $event->post_status && 'live' === (string) get_post_meta( $event_id, '_lnkup_event_status', true );
	}

	public static function payload( int $opp_id ): ?array {
		if ( ! self::visible( $opp_id ) ) {
			return null;
		}
		$opp      = get_post( $opp_id );
		$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		$event    = get_post( $event_id );
		$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		$org_name  = (string) get_user_meta( $organizer, '_lnkup_organizer_public_name', true );
		if ( '' === $org_name ) {
			$user     = get_userdata( $organizer );
			$org_name = $user ? (string) $user->display_name : '';
		}
		$base = function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'opportunities' ) : home_url( '/opportunities/' );
		$fee  = get_post_meta( $opp_id, '_lnkup_vendor_fee', true );
		return array(
			'id'             => $opp_id,
			'title'          => html_entity_decode( get_the_title( $opp ), ENT_QUOTES, 'UTF-8' ),
			'event_title'    => html_entity_decode( get_the_title( $event ), ENT_QUOTES, 'UTF-8' ),
			'event_date'     => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
			'city'           => (string) get_post_meta( $event_id, '_lnkup_city', true ),
			'state'          => (string) get_post_meta( $event_id, '_lnkup_state', true ),
			'vendor_fee'     => '' === (string) $fee ? null : (float) $fee,
			'spots_left'     => (int) get_post_meta( $opp_id, '_lnkup_spots_remaining', true ),
			'deadline'       => (string) get_post_meta( $opp_id, '_lnkup_application_deadline', true ),
			'organizer_name' => $org_name,
			'organizer_slug' => class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::organizer_public_slug( $organizer ) : '',
			'apply_url'      => add_query_arg( 'apply', $opp_id, $base ),
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function card( WP_REST_Request $request ) {
		if ( self::rate_limited() ) {
			return new WP_Error( 'lnkup_rate_limited', __( 'Too many requests. Try again later.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$opp_id = (int) $request['id'];
		$cached = self::visible( $opp_id ) ? get_transient( 'lnkup_embed_card_' . $opp_id ) : false;
		$data   = is_array( $cached ) ? $cached : self::payload( $opp_id );
		if ( null === $data ) {
			return new WP_Error( 'lnkup_not_found', __( 'This vendor opportunity is not open for applications.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! is_array( $cached ) ) {
			set_transient( 'lnkup_embed_card_' . $opp_id, $data, self::CACHE_TTL );
		}
		$response = new WP_REST_Response( array( 'success' => true, 'data' => $data ), 200 );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}
}
