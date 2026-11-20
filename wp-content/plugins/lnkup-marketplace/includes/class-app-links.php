<?php
/**
 * Universal / app links so shared profile URLs open the app when it is installed.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_App_Links {

	const TEAM_ID   = 'R23JQ6A5P7';
	const BUNDLE_ID = 'com.lnkup.marketplace';

	/**
	 * Init.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 0 );
	}

	/**
	 * Serve association files before the theme.
	 */
	public static function maybe_serve() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		$path = untrailingslashit( $path );

		if ( '/.well-known/apple-app-site-association' === $path || '/apple-app-site-association' === $path ) {
			self::send_json( self::apple_payload() );
		}
		if ( '/.well-known/assetlinks.json' === $path ) {
			self::send_json( self::android_payload() );
		}
	}

	/**
	 * @param array<string, mixed>|array<int, mixed> $payload JSON payload.
	 */
	private static function send_json( $payload ) {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $payload );
		exit;
	}

	/**
	 * Apple associated domains for vendor and organizer profile URLs.
	 *
	 * @return array<string, mixed>
	 */
	private static function apple_payload() {
		$app_id = self::TEAM_ID . '.' . self::BUNDLE_ID;
		$paths  = array( '/vendors', '/vendors/', '/organizers', '/organizers/' );
		return array(
			'applinks' => array(
				'apps'    => array(),
				'details' => array(
					array(
						'appID' => $app_id,
						'paths' => $paths,
					),
					array(
						'appIDs'     => array( $app_id ),
						'components' => array(
							array( '/' => '/vendors' ),
							array( '/' => '/vendors/' ),
							array( '/' => '/organizers' ),
							array( '/' => '/organizers/' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Android app links. Fingerprints come from the lnkup_android_sha256_fingerprints option.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function android_payload() {
		$stored = get_option( 'lnkup_android_sha256_fingerprints', array() );
		$prints = is_array( $stored ) ? $stored : array();
		$prints = array_values( array_filter( array_map( 'strval', $prints ) ) );
		$prints = apply_filters( 'lnkup_android_sha256_fingerprints', $prints );
		if ( ! is_array( $prints ) ) {
			$prints = array();
		}
		return array(
			array(
				'relation' => array( 'delegate_permission/common.handle_all_urls' ),
				'target'   => array(
					'namespace'                => 'android_app',
					'package_name'             => self::BUNDLE_ID,
					'sha256_cert_fingerprints' => array_values( $prints ),
				),
			),
		);
	}
}
