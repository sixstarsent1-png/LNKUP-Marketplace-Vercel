<?php
/**
 * Vendor Passport visibility preferences.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Passport_Privacy {

	const META = '_lnkup_passport_visibility';

	const BOOL_KEYS = array( 'public_profile', 'show_history', 'show_reviews', 'show_cities', 'show_portfolio', 'show_socials' );

	/**
	 * Visibility preferences with defaults applied.
	 *
	 * When the vendor never chose `public_profile`, the legacy `_lnkup_discoverable` opt-out decides.
	 *
	 * @param int $profile_id Vendor profile ID.
	 * @return array<string,mixed>
	 */
	public static function get( int $profile_id ): array {
		$raw    = get_post_meta( $profile_id, self::META, true );
		$stored = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : array() );
		$stored = is_array( $stored ) ? $stored : array();

		$prefs = array();
		foreach ( self::BOOL_KEYS as $key ) {
			$prefs[ $key ] = array_key_exists( $key, $stored ) ? (bool) $stored[ $key ] : true;
		}
		if ( ! array_key_exists( 'public_profile', $stored ) ) {
			$prefs['public_profile'] = '0' !== (string) get_post_meta( $profile_id, '_lnkup_discoverable', true );
		}
		$prefs['hidden_event_ids'] = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $stored['hidden_event_ids'] ?? array() ) ) ) ) );
		$prefs['explicit']         = array_key_exists( 'public_profile', $stored );
		return $prefs;
	}

	/**
	 * Merge and persist visibility changes.
	 *
	 * @param int   $profile_id Vendor profile ID.
	 * @param array $input      Partial preferences.
	 * @return array<string,mixed>
	 */
	public static function save( int $profile_id, array $input ): array {
		$current = self::get( $profile_id );
		unset( $current['explicit'] );
		foreach ( self::BOOL_KEYS as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$current[ $key ] = rest_sanitize_boolean( $input[ $key ] );
			}
		}
		if ( array_key_exists( 'hidden_event_ids', $input ) ) {
			$current['hidden_event_ids'] = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) $input['hidden_event_ids'] ) ) ) ), 0, 500 );
		}
		update_post_meta( $profile_id, self::META, wp_json_encode( $current ) );
		do_action( 'lnkup_passport_privacy_changed', $profile_id, $current );
		return self::get( $profile_id );
	}

	/**
	 * Whether the vendor's public Passport may be served to anonymous visitors.
	 *
	 * @param int $profile_id Vendor profile ID.
	 */
	public static function is_public( int $profile_id ): bool {
		$post = $profile_id ? get_post( $profile_id ) : null;
		if ( ! $post || 'lnkup_vendor_profile' !== $post->post_type || 'publish' !== $post->post_status ) {
			return false;
		}
		$owner = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		if ( ! $owner || ! get_userdata( $owner ) ) {
			return false;
		}
		$prefs = self::get( $profile_id );
		return ! empty( $prefs['public_profile'] );
	}
}
