<?php
/**
 * Mobile auth tokens for website-password sign-in.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Issues and validates long-lived Bearer tokens after wp_authenticate().
 *
 * Token format: lnkup_m_{user_id}.{random}
 */
class LNKup_Mobile_Auth {

	const META_KEY     = '_lnkup_mobile_auth_tokens';
	const TOKEN_PREFIX = 'lnkup_m_';
	const TTL_SECONDS  = 7776000; // 90 days.
	const MAX_TOKENS   = 10;

	/**
	 * Hook REST authentication for Bearer tokens.
	 */
	public static function init(): void {
		add_filter( 'determine_current_user', array( __CLASS__, 'authenticate_bearer' ), 20 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'rest_auth_errors' ), 20 );
	}

	/**
	 * Authenticate Authorization: Bearer lnkup_m_…
	 *
	 * @param int|false $user_id Existing user id.
	 * @return int|false
	 */
	public static function authenticate_bearer( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}

		$token = self::get_bearer_token_from_request();
		if ( ! $token ) {
			return $user_id;
		}

		$found = self::find_user_id_for_token( $token );
		return $found ? $found : $user_id;
	}

	/**
	 * Allow REST when Bearer auth already set the current user.
	 *
	 * @param mixed $result Prior result.
	 * @return mixed
	 */
	public static function rest_auth_errors( $result ) {
		if ( true === $result || is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_user_logged_in() ) {
			return true;
		}
		return $result;
	}

	/**
	 * Issue a new token for a user (revokes expired; caps active tokens).
	 *
	 * @param int $user_id User ID.
	 * @return array{token:string,expires_at:int}|WP_Error
	 */
	public static function issue_token( int $user_id ) {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$raw     = self::TOKEN_PREFIX . $user_id . '.' . self::random_token_part();
		$hash    = self::hash_token( $raw );
		$expires = time() + self::TTL_SECONDS;
		$tokens  = self::prune_tokens( self::get_tokens( $user_id ) );
		$tokens[] = array(
			'hash'    => $hash,
			'exp'     => $expires,
			'created' => time(),
		);

		if ( count( $tokens ) > self::MAX_TOKENS ) {
			$tokens = array_slice( $tokens, -self::MAX_TOKENS );
		}

		update_user_meta( $user_id, self::META_KEY, array_values( $tokens ) );

		return array(
			'token'      => $raw,
			'expires_at' => $expires,
		);
	}

	/**
	 * Revoke a specific token for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Raw token.
	 */
	public static function revoke_token( int $user_id, string $token ): void {
		$hash   = self::hash_token( $token );
		$tokens = self::get_tokens( $user_id );
		$tokens = array_values(
			array_filter(
				$tokens,
				static function ( $row ) use ( $hash ) {
					return empty( $row['hash'] ) || ! hash_equals( (string) $row['hash'], $hash );
				}
			)
		);
		update_user_meta( $user_id, self::META_KEY, $tokens );
	}

	/**
	 * @param string $token Raw bearer token.
	 * @return int User ID or 0.
	 */
	public static function find_user_id_for_token( string $token ): int {
		if ( ! preg_match( '/^' . preg_quote( self::TOKEN_PREFIX, '/' ) . '(\d+)\./', $token, $m ) ) {
			return 0;
		}

		$user_id = (int) $m[1];
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return 0;
		}

		$hash    = self::hash_token( $token );
		$tokens  = self::get_tokens( $user_id );
		$changed = false;

		foreach ( $tokens as $i => $row ) {
			if ( empty( $row['hash'] ) || empty( $row['exp'] ) ) {
				unset( $tokens[ $i ] );
				$changed = true;
				continue;
			}
			if ( (int) $row['exp'] < time() ) {
				unset( $tokens[ $i ] );
				$changed = true;
				continue;
			}
			if ( hash_equals( (string) $row['hash'], $hash ) ) {
				if ( $changed ) {
					update_user_meta( $user_id, self::META_KEY, array_values( $tokens ) );
				}
				return $user_id;
			}
		}

		if ( $changed ) {
			update_user_meta( $user_id, self::META_KEY, array_values( $tokens ) );
		}

		return 0;
	}

	/**
	 * @return string|null
	 */
	public static function get_bearer_token_from_request(): ?string {
		$header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		} elseif ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $key => $value ) {
					if ( strtolower( (string) $key ) === 'authorization' ) {
						$header = (string) $value;
						break;
					}
				}
			}
		}

		if ( preg_match( '/Bearer\s+(\S+)/i', $header, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * @param int $user_id User ID.
	 * @return array<int,array{hash?:string,exp?:int,created?:int}>
	 */
	private static function get_tokens( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @param array<int,array{hash?:string,exp?:int,created?:int}> $tokens Tokens.
	 * @return array<int,array{hash?:string,exp?:int,created?:int}>
	 */
	private static function prune_tokens( array $tokens ): array {
		$now = time();
		return array_values(
			array_filter(
				$tokens,
				static function ( $row ) use ( $now ) {
					return ! empty( $row['hash'] ) && ! empty( $row['exp'] ) && (int) $row['exp'] >= $now;
				}
			)
		);
	}

	/**
	 * @param string $token Raw token.
	 */
	private static function hash_token( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * @return string
	 */
	private static function random_token_part(): string {
		try {
			$bytes = random_bytes( 32 );
		} catch ( Exception $e ) {
			$bytes = wp_generate_password( 32, true, true );
		}
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
