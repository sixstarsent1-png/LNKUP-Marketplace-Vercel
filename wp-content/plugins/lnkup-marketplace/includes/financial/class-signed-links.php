<?php
/**
 * Short-lived signed URLs for receipt and invoice documents.
 *
 * The apps open these in a system browser that carries no login (no Bearer token, and WordPress
 * drops cookie auth on REST without a nonce), so the URL itself proves who may view it.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Signed_Links {

	const TTL = HOUR_IN_SECONDS;

	/**
	 * Add expires/user/token query args to a document URL.
	 *
	 * @param string $url     Document URL.
	 * @param string $type    Document type (receipt|invoice).
	 * @param int    $id      Document row ID.
	 * @param int    $user_id User the link is issued to.
	 * @return string
	 */
	public static function sign_url( $url, $type, $id, $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return $url;
		}
		$expires = time() + self::TTL;
		return add_query_arg(
			array(
				'lnkup_user'    => $user_id,
				'lnkup_expires' => $expires,
				'lnkup_token'   => self::token( $type, $id, $user_id, $expires ),
			),
			$url
		);
	}

	/**
	 * User ID a request's signature was issued to, or 0 when missing, expired or invalid.
	 *
	 * @param string          $type    Document type.
	 * @param int             $id      Document row ID.
	 * @param WP_REST_Request $request Request.
	 * @return int
	 */
	public static function verified_user( $type, $id, $request ) {
		$user_id = absint( $request->get_param( 'lnkup_user' ) );
		$expires = absint( $request->get_param( 'lnkup_expires' ) );
		$token   = (string) $request->get_param( 'lnkup_token' );
		if ( ! $user_id || ! $expires || '' === $token || $expires < time() ) {
			return 0;
		}
		return hash_equals( self::token( $type, $id, $user_id, $expires ), $token ) ? $user_id : 0;
	}

	private static function token( $type, $id, $user_id, $expires ) {
		return hash_hmac( 'sha256', sanitize_key( $type ) . '|' . (int) $id . '|' . (int) $user_id . '|' . (int) $expires, wp_salt( 'auth' ) );
	}
}
