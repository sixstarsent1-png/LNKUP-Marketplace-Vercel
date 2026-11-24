<?php
/**
 * Strips Meta credentials from anything that may be logged, stored or displayed.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Meta_Redactor {

	const MASK = '[redacted]';

	/**
	 * Redact access tokens, authorization headers and configured secrets.
	 *
	 * @param string   $text    Raw text.
	 * @param string[] $secrets Known secret values (defaults to configured tokens).
	 */
	public static function redact( string $text, ?array $secrets = null ): string {
		if ( null === $secrets ) {
			$secrets = class_exists( 'LNKup_Meta_Config' ) ? LNKup_Meta_Config::secrets() : array();
		}
		foreach ( $secrets as $secret ) {
			if ( is_string( $secret ) && strlen( $secret ) >= 8 ) {
				$text = str_replace( $secret, self::MASK, $text );
			}
		}
		$patterns = array(
			'/(access_token["\']?\s*[:=]\s*["\']?)[^"\'&\s,}]+/i',
			'/(authorization["\']?\s*[:=]\s*["\']?(?:bearer|oauth)\s+)[^"\'&\s,}]+/i',
			'/\bEAA[A-Za-z0-9]{20,}\b/',
		);
		$text = (string) preg_replace( $patterns, array( '$1' . self::MASK, '$1' . self::MASK, self::MASK ), $text );
		return $text;
	}

	/**
	 * Redact every string inside an array (for stored API responses).
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	public static function redact_deep( $data ) {
		if ( is_string( $data ) ) {
			return self::redact( $data );
		}
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $value ) {
				$out[ $key ] = ( is_string( $key ) && in_array( strtolower( $key ), array( 'access_token', 'authorization' ), true ) ) ? self::MASK : self::redact_deep( $value );
			}
			return $out;
		}
		return $data;
	}
}
