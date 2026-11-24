<?php
/**
 * Keeps signed-in marketplace responses out of shared caches.
 *
 * Guest pages and guest REST responses stay cacheable (they only contain public-safe fields).
 * Any response rendered for a signed-in user is marked private so a page cache, CDN or proxy
 * never serves one member's pricing, favorites or nonces to another visitor.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Access_Cache {

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'mark_private_page' ), 0 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'mark_private_rest' ), 99, 3 );
	}

	/** Signed-in HTML is never stored in a shared page cache. */
	public static function mark_private_page(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			header( 'Cache-Control: private, no-store, max-age=0' );
		}
	}

	/**
	 * Signed-in lnkup/v1 responses are private; explicit guest cache headers are left alone.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 */
	public static function mark_private_rest( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		$route = (string) $request->get_route();
		if ( 0 !== strpos( $route, '/lnkup/v1/' ) && 0 !== strpos( $route, '/wp/v2/lnkup_' ) ) {
			return $response;
		}
		if ( is_user_logged_in() ) {
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			return $response;
		}
		$headers = $response->get_headers();
		if ( ! isset( $headers['Cache-Control'] ) ) {
			$response->header( 'Vary', 'Cookie, Authorization' );
		}
		return $response;
	}
}
