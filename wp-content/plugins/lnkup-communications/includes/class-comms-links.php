<?php
/**
 * Signed click-tracking links for attribution. No open-tracking pixels.
 *
 * The destination is part of the HMAC-signed token, so links cannot be turned
 * into open redirects; wp_safe_redirect() also limits targets to this site.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Links {

	const QUERY_VAR = 'lnkup_c';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_redirect' ), 6 );
	}

	private static function sign( string $payload ): string {
		return substr( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . 'lnkup_comms_click' ), 0, 24 );
	}

	public static function tracked( int $message_id, string $channel, string $url ): string {
		if ( $message_id < 1 || '' === $url ) {
			return $url;
		}
		$payload = $message_id . '|' . sanitize_key( $channel ) . '|' . $url;
		$token   = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ) . '.' . self::sign( $payload );
		return add_query_arg( self::QUERY_VAR, $token, home_url( '/' ) );
	}

	public static function maybe_redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- HMAC-signed token.
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) );
		$parts = explode( '.', $token );
		$fallback = home_url( '/' );
		if ( 2 !== count( $parts ) ) {
			wp_safe_redirect( $fallback );
			exit;
		}
		$payload = base64_decode( strtr( $parts[0], '-_', '+/' ), true );
		if ( false === $payload || ! hash_equals( self::sign( $payload ), $parts[1] ) ) {
			wp_safe_redirect( $fallback );
			exit;
		}
		$bits = explode( '|', $payload, 3 );
		if ( 3 !== count( $bits ) || ! ctype_digit( $bits[0] ) ) {
			wp_safe_redirect( $fallback );
			exit;
		}
		self::record( (int) $bits[0], sanitize_key( $bits[1] ) );
		wp_safe_redirect( esc_url_raw( $bits[2] ), 302 );
		exit;
	}

	public static function record( int $message_id, string $channel ): void {
		global $wpdb;
		$messages = LNKup_Comms_Schema::table( 'messages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$user_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT recipient_user_id FROM {$messages} WHERE id = %d", $message_id ) );
		if ( ! $user_id ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			LNKup_Comms_Schema::table( 'clicks' ),
			array(
				'message_id' => $message_id,
				'user_id'    => $user_id,
				'channel'    => $channel,
				'created_at' => LNKup_Comms_Schema::now(),
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$messages} SET clicked_at = %s WHERE id = %d AND clicked_at IS NULL", LNKup_Comms_Schema::now(), $message_id ) );
	}
}
