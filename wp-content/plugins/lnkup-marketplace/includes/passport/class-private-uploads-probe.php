<?php
/**
 * Checks that the private document directory is not reachable by URL.
 *
 * Writes a throwaway token file into uploads/lnkup-private/, requests its public URL without
 * credentials, records the result and deletes the file. Document Vault sharing and the private
 * document migration require a "protected" result, because hosts such as SiteGround's NGINX
 * Direct Delivery serve uploads without reading .htaccess.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Private_Uploads_Probe {

	const OPTION          = 'lnkup_private_uploads_probe';
	const STATUS_OK       = 'protected';
	const STATUS_EXPOSED  = 'exposed';
	const STATUS_UNKNOWN  = 'unknown';
	const FRESH_SECONDS   = DAY_IN_SECONDS;

	/**
	 * Run the probe now and store the result.
	 *
	 * @return array{status:string,http_code:int,checked_at:int,detail:string}
	 */
	public static function run(): array {
		$dirs = wp_upload_dir( null, false );
		if ( ! empty( $dirs['error'] ) ) {
			return self::store( self::STATUS_UNKNOWN, 0, (string) $dirs['error'] );
		}
		$dir = trailingslashit( $dirs['basedir'] ) . LNKup_Documents::PRIVATE_SUBDIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return self::store( self::STATUS_UNKNOWN, 0, 'Private directory is not writable.' );
		}
		$token = wp_generate_password( 32, false );
		$name  = '.lnkup-probe-' . wp_generate_password( 12, false ) . '.txt';
		$path  = trailingslashit( $dir ) . $name;
		if ( false === file_put_contents( $path, $token ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return self::store( self::STATUS_UNKNOWN, 0, 'Could not write the probe file.' );
		}
		$url = (string) apply_filters(
			'lnkup_private_uploads_probe_url',
			trailingslashit( $dirs['baseurl'] ) . LNKup_Documents::PRIVATE_SUBDIR . '/' . $name,
			$name
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'cookies'     => array(),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		wp_delete_file( $path );

		if ( is_wp_error( $response ) ) {
			return self::store( self::STATUS_UNKNOWN, 0, $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( false !== strpos( $body, $token ) ) {
			return self::store( self::STATUS_EXPOSED, $code, 'The probe file was served publicly.' );
		}
		return self::store( self::STATUS_OK, $code, '' );
	}

	/**
	 * Last stored result, or an "unknown" placeholder when the probe has never run.
	 *
	 * @return array{status:string,http_code:int,checked_at:int,detail:string}
	 */
	public static function last(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'status'     => (string) ( $saved['status'] ?? self::STATUS_UNKNOWN ),
			'http_code'  => (int) ( $saved['http_code'] ?? 0 ),
			'checked_at' => (int) ( $saved['checked_at'] ?? 0 ),
			'detail'     => (string) ( $saved['detail'] ?? '' ),
		);
	}

	/**
	 * Whether the most recent probe passed within the freshness window. Enabling features calls
	 * run() first, so a stale pass is never used to switch something on.
	 */
	public static function is_protected(): bool {
		$filtered = apply_filters( 'lnkup_private_uploads_protected', null );
		if ( null !== $filtered ) {
			return (bool) $filtered;
		}
		$last = self::last();
		return self::STATUS_OK === $last['status'] && ( time() - $last['checked_at'] ) <= self::FRESH_SECONDS;
	}

	/**
	 * Whether the most recent probe saw the private directory served publicly.
	 */
	public static function is_exposed(): bool {
		$filtered = apply_filters( 'lnkup_private_uploads_protected', null );
		if ( null !== $filtered ) {
			return ! $filtered;
		}
		return self::STATUS_EXPOSED === self::last()['status'];
	}

	/**
	 * @return array{status:string,http_code:int,checked_at:int,detail:string}
	 */
	private static function store( string $status, int $code, string $detail ): array {
		$result = array(
			'status'     => $status,
			'http_code'  => $code,
			'checked_at' => time(),
			'detail'     => $detail,
		);
		update_option( self::OPTION, $result, false );
		return $result;
	}
}
