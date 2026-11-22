<?php
/**
 * Trust, Safety & Compliance OS: shared helpers and feature flags.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Trust {

	const CAP = 'lnkup_manage_trust';

	/**
	 * Claim review modes: off (legacy), log (record mismatches as cases), enforce (mismatches wait for review).
	 */
	const CLAIM_MODES = array( 'off', 'log', 'enforce' );

	public static function init() {
		add_action( 'init', array( 'LNKup_Trust_Tables', 'maybe_upgrade' ), 4 );
		LNKup_Audit_Log::init();
		LNKup_Trust_Cases::init();
		LNKup_Org_Teams::init();
		LNKup_Organizer_Verification::init();
		LNKup_Event_Authenticity::init();
		LNKup_Trust_REST::init();
	}

	/**
	 * Boolean feature flag from lnkup_settings.
	 *
	 * @param string $key Setting key.
	 */
	public static function flag( $key ) {
		$settings = lnkup_get_settings();
		return ! empty( $settings[ $key ] );
	}

	public static function claim_review_mode() {
		$settings = lnkup_get_settings();
		$mode     = (string) ( $settings['trust_claim_review_mode'] ?? 'log' );
		return in_array( $mode, self::CLAIM_MODES, true ) ? $mode : 'log';
	}

	/**
	 * Whether the user may operate the Trust & Safety console.
	 *
	 * @param int $user_id User ID (0 = current).
	 */
	public static function user_can_manage( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		return user_can( $user_id, self::CAP ) || LNKup_Capabilities::user_has_manage_all( $user_id );
	}

	/**
	 * Stable per-request correlation ID.
	 */
	public static function request_id() {
		static $id = null;
		if ( null === $id ) {
			$id = substr( wp_hash( uniqid( 'lnkup', true ) . wp_rand() ), 0, 20 );
		}
		return $id;
	}

	/**
	 * Keyed hash of the client IP; never store the raw address.
	 *
	 * @param string|null $ip Raw IP (defaults to REMOTE_ADDR).
	 */
	public static function ip_hash( $ip = null ) {
		if ( null === $ip ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		}
		$ip = trim( (string) $ip );
		if ( '' === $ip ) {
			return '';
		}
		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}

	/**
	 * Simple fixed-window rate limit using transients.
	 *
	 * @param string $bucket Bucket key.
	 * @param int    $limit  Max hits per window.
	 * @param int    $window Window seconds.
	 * @return bool True when the request is allowed.
	 */
	public static function rate_limit( $bucket, $limit, $window ) {
		$key   = 'lnkup_rl_' . md5( $bucket );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * Registrable domain of an email address or URL host (best effort, no PSL).
	 *
	 * @param string $value Email or URL.
	 */
	public static function domain_of( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( false !== strpos( $value, '@' ) && false === strpos( $value, '/' ) ) {
			$host = substr( strrchr( $value, '@' ), 1 );
		} else {
			$host = (string) wp_parse_url( false === strpos( $value, '://' ) ? 'https://' . $value : $value, PHP_URL_HOST );
		}
		$host = preg_replace( '/^www\./', '', (string) $host );
		return preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $host ) ? $host : '';
	}

	/**
	 * Trust notices go to email and the in-app inbox only. No push: lock screens must not show case details.
	 *
	 * @param int    $user_id User ID.
	 * @param string $subject Subject / inbox title.
	 * @param string $body    Plain-text body.
	 * @param string $type    Inbox type slug.
	 * @param string $href    Optional dashboard link.
	 */
	public static function notify_user( $user_id, $subject, $body, $type = 'trust', $href = '' ) {
		$user = get_userdata( absint( $user_id ) );
		if ( ! $user ) {
			return;
		}
		if ( class_exists( 'LNKup_Notifications' ) && is_email( $user->user_email ) ) {
			$html = wpautop( esc_html( $body ) );
			if ( $href ) {
				$html .= '<p><a href="' . esc_url( $href ) . '">' . esc_html__( 'Open LNKup', 'lnkup-marketplace' ) . '</a></p>';
			}
			LNKup_Notifications::send( $user->user_email, $subject, $html );
		}
		if ( class_exists( 'LNKup_Inbox_Service' ) ) {
			LNKup_Inbox_Service::record_from_push( (int) $user->ID, $subject, $body, array( 'type' => sanitize_key( $type ), 'href' => esc_url_raw( $href ) ) );
		}
	}

	/**
	 * Free mailbox providers never prove control of an organization domain.
	 *
	 * @param string $domain Domain.
	 */
	public static function is_free_mail_domain( $domain ) {
		$free = array( 'gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'msn.com', 'ymail.com' );
		return in_array( strtolower( (string) $domain ), $free, true );
	}
}
