<?php
/**
 * Conversions API customer information (user_data).
 *
 * Normalization follows developers.facebook.com/docs/marketing-api/conversions-api/parameters/customer-information-parameters:
 * em/ph/fn/ln/ct/st/zp/country/external_id are SHA-256 hashed after normalization;
 * client_ip_address, client_user_agent, fbp and fbc are sent as-is.
 *
 * Only the person's own account data is used. Event cities, business addresses, vendor
 * service areas and campaign markets are never sent as customer geography, and business
 * contact phones/emails are never sent as personal identifiers.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Meta_User_Data {

	const SALT_OPTION = 'lnkup_meta_external_id_salt';

	/** Presence keys stored on each outbox row (booleans only, never values). */
	const PRESENCE_KEYS = array( 'em', 'ph', 'fn', 'ln', 'external_id', 'ip', 'ua', 'fbp', 'fbc' );

	/**
	 * SHA-256 hex; values that already look hashed are returned unchanged (no double hashing).
	 *
	 * @param string $value Normalized value.
	 */
	public static function hash( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^[a-f0-9]{64}$/', $value ) ) {
			return $value;
		}
		return hash( 'sha256', $value );
	}

	public static function normalize_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/', $email ) ? $email : '';
	}

	/**
	 * Digits only with country code. Ten-digit numbers are treated as US/Canada (+1).
	 *
	 * @param string $phone Raw phone.
	 */
	public static function normalize_phone( string $phone ): string {
		$digits = (string) preg_replace( '/\D/', '', $phone );
		$digits = ltrim( $digits, '0' );
		if ( 10 === strlen( $digits ) ) {
			$digits = '1' . $digits;
		}
		return strlen( $digits ) >= 11 && strlen( $digits ) <= 15 ? $digits : '';
	}

	/**
	 * Lowercase, no punctuation (UTF-8 letters kept).
	 *
	 * @param string $name Name.
	 */
	public static function normalize_name( string $name ): string {
		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $name ), 'UTF-8' ) : strtolower( trim( $name ) );
		return trim( (string) preg_replace( '/[^\p{L}\p{M}\s-]/u', '', $name ) );
	}

	/**
	 * Lowercase, no punctuation, no spaces.
	 *
	 * @param string $city City.
	 */
	public static function normalize_city( string $city ): string {
		$city = function_exists( 'mb_strtolower' ) ? mb_strtolower( $city, 'UTF-8' ) : strtolower( $city );
		return (string) preg_replace( '/[^\p{L}\p{M}]/u', '', $city );
	}

	/**
	 * Two-letter lowercase US state code.
	 *
	 * @param string $state State name or code.
	 */
	public static function normalize_state( string $state ): string {
		$state = strtolower( trim( $state ) );
		if ( preg_match( '/^[a-z]{2}$/', $state ) ) {
			return $state;
		}
		$map = array(
			'alabama' => 'al', 'alaska' => 'ak', 'arizona' => 'az', 'arkansas' => 'ar', 'california' => 'ca', 'colorado' => 'co',
			'connecticut' => 'ct', 'delaware' => 'de', 'district of columbia' => 'dc', 'florida' => 'fl', 'georgia' => 'ga',
			'hawaii' => 'hi', 'idaho' => 'id', 'illinois' => 'il', 'indiana' => 'in', 'iowa' => 'ia', 'kansas' => 'ks',
			'kentucky' => 'ky', 'louisiana' => 'la', 'maine' => 'me', 'maryland' => 'md', 'massachusetts' => 'ma',
			'michigan' => 'mi', 'minnesota' => 'mn', 'mississippi' => 'ms', 'missouri' => 'mo', 'montana' => 'mt',
			'nebraska' => 'ne', 'nevada' => 'nv', 'new hampshire' => 'nh', 'new jersey' => 'nj', 'new mexico' => 'nm',
			'new york' => 'ny', 'north carolina' => 'nc', 'north dakota' => 'nd', 'ohio' => 'oh', 'oklahoma' => 'ok',
			'oregon' => 'or', 'pennsylvania' => 'pa', 'rhode island' => 'ri', 'south carolina' => 'sc', 'south dakota' => 'sd',
			'tennessee' => 'tn', 'texas' => 'tx', 'utah' => 'ut', 'vermont' => 'vt', 'virginia' => 'va', 'washington' => 'wa',
			'west virginia' => 'wv', 'wisconsin' => 'wi', 'wyoming' => 'wy',
		);
		return $map[ (string) preg_replace( '/\s+/', ' ', $state ) ] ?? '';
	}

	/**
	 * US zip: first five digits. Other formats: lowercase without spaces or dashes.
	 *
	 * @param string $zip Postal code.
	 */
	public static function normalize_zip( string $zip ): string {
		$zip = strtolower( (string) preg_replace( '/[\s-]/', '', $zip ) );
		if ( preg_match( '/^(\d{5})\d{4}$/', $zip, $m ) ) {
			return $m[1];
		}
		return (bool) preg_match( '/^[a-z0-9]{3,10}$/', $zip ) ? $zip : '';
	}

	public static function normalize_country( string $country ): string {
		$country = strtolower( trim( $country ) );
		return (bool) preg_match( '/^[a-z]{2}$/', $country ) ? $country : '';
	}

	/**
	 * Stable, non-reversible LNKup user identifier shared by Pixel and CAPI.
	 *
	 * @param int $user_id User.
	 */
	public static function external_id( int $user_id ): string {
		if ( $user_id < 1 ) {
			return '';
		}
		$salt = (string) get_option( self::SALT_OPTION, '' );
		if ( '' === $salt ) {
			$salt = wp_generate_password( 40, false, false );
			add_option( self::SALT_OPTION, $salt, '', false );
			$salt = (string) get_option( self::SALT_OPTION, $salt );
		}
		return hash( 'sha256', $salt . '|lnkup_user|' . $user_id );
	}

	/**
	 * Split a stored full name into first and last name.
	 *
	 * @param WP_User $user User.
	 * @return array{0:string,1:string}
	 */
	private static function names( WP_User $user ): array {
		$first = trim( (string) $user->first_name );
		$last  = trim( (string) $user->last_name );
		if ( '' === $last && false !== strpos( $first, ' ' ) ) {
			$parts = preg_split( '/\s+/', $first );
			$parts = is_array( $parts ) ? $parts : array( $first );
			$last  = (string) array_pop( $parts );
			$first = (string) array_shift( $parts );
		}
		return array( $first, $last );
	}

	/**
	 * Build CAPI user_data for a stored event.
	 *
	 * @param int                 $user_id User (0 = anonymous).
	 * @param array<string,mixed> $context Request context: ip, ua, fbp, fbc.
	 * @return array<string,mixed>
	 */
	public static function build( int $user_id, array $context ): array {
		$data = array();
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( $user instanceof WP_User ) {
			$em = self::normalize_email( (string) $user->user_email );
			if ( '' !== $em ) {
				$data['em'] = array( self::hash( $em ) );
			}
			list( $first, $last ) = self::names( $user );
			$fn = self::normalize_name( $first );
			$ln = self::normalize_name( $last );
			if ( '' !== $fn ) {
				$data['fn'] = array( self::hash( $fn ) );
			}
			if ( '' !== $ln ) {
				$data['ln'] = array( self::hash( $ln ) );
			}
			$data['external_id'] = array( self::external_id( $user_id ) );
		}
		$ip = isset( $context['ip'] ) ? (string) $context['ip'] : '';
		if ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$data['client_ip_address'] = $ip;
		}
		if ( ! empty( $context['ua'] ) ) {
			$data['client_user_agent'] = substr( (string) $context['ua'], 0, 512 );
		}
		foreach ( array( 'fbp', 'fbc' ) as $key ) {
			if ( ! empty( $context[ $key ] ) && LNKup_Meta_Cookies::valid_click_cookie( (string) $context[ $key ] ) ) {
				$data[ $key ] = (string) $context[ $key ];
			}
		}
		return (array) apply_filters( 'lnkup_meta_user_data', $data, $user_id );
	}

	/**
	 * Which matching parameters are present (comma list for FIND_IN_SET queries).
	 *
	 * @param array<string,mixed> $user_data Built user_data.
	 */
	public static function presence( array $user_data ): string {
		$map = array(
			'em'          => 'em',
			'ph'          => 'ph',
			'fn'          => 'fn',
			'ln'          => 'ln',
			'external_id' => 'external_id',
			'client_ip_address' => 'ip',
			'client_user_agent' => 'ua',
			'fbp'         => 'fbp',
			'fbc'         => 'fbc',
		);
		$out = array();
		foreach ( $map as $field => $label ) {
			if ( ! empty( $user_data[ $field ] ) ) {
				$out[] = $label;
			}
		}
		return implode( ',', $out );
	}
}
