<?php
/**
 * Local QR codes (no third-party QR service ever sees share links, check-in tokens or profiles).
 *
 * Server: SVG generated with the vendored qrcode-generator PHP port (MIT, includes/vendor/qrcode.php).
 * Browser: public/js/lnkup-qr.js wraps the vendored JS build (public/vendor/qrcode-generator/).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_QR {

	const MAX_LENGTH = 1000;

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ), 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register' ), 1 );
	}

	public static function register(): void {
		wp_register_script( 'lnkup-qrcode-generator', LNKUP_PLUGIN_URL . 'public/vendor/qrcode-generator/qrcode.js', array(), '1.4.4', true );
		wp_register_script( 'lnkup-qr', LNKUP_PLUGIN_URL . 'public/js/lnkup-qr.js', array( 'lnkup-qrcode-generator' ), LNKUP_VERSION, true );
	}

	private static function load(): bool {
		if ( ! class_exists( '\LNKup\Vendor\QR\QRCode', false ) ) {
			require_once LNKUP_PLUGIN_DIR . 'includes/vendor/qrcode.php';
		}
		return class_exists( '\LNKup\Vendor\QR\QRCode', false );
	}

	/**
	 * SVG markup for $text, or '' when it cannot be encoded.
	 */
	public static function svg( string $text, int $size = 240, int $margin = 4, string $dark = '#0b0b0c' ): string {
		if ( '' === $text || strlen( $text ) > self::MAX_LENGTH || ! self::load() ) {
			return '';
		}
		try {
			$qr = \LNKup\Vendor\QR\QRCode::getMinimumQRCode( $text, \LNKup\Vendor\QR\QR_ERROR_CORRECT_LEVEL_M );
		} catch ( Throwable $e ) {
			return '';
		}
		$count = $qr->getModuleCount();
		$total = $count + 2 * $margin;
		$path  = '';
		for ( $r = 0; $r < $count; $r++ ) {
			for ( $c = 0; $c < $count; $c++ ) {
				if ( $qr->isDark( $r, $c ) ) {
					$path .= 'M' . ( $c + $margin ) . ' ' . ( $r + $margin ) . 'h1v1h-1z';
				}
			}
		}
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" height="%2$d" shape-rendering="crispEdges"><rect width="%1$d" height="%1$d" fill="#fff"/><path fill="%3$s" d="%4$s"/></svg>',
			$total,
			max( 64, $size ),
			preg_match( '/^#[0-9a-f]{3,6}$/i', $dark ) ? $dark : '#0b0b0c',
			$path
		);
	}

	/**
	 * data: URI usable as an <img src>.
	 */
	public static function data_uri( string $text, int $size = 240, int $margin = 4 ): string {
		$svg = self::svg( $text, $size, $margin );
		return '' === $svg ? '' : 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
