<?php
/**
 * CSV external event source.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Source_Csv implements LNKup_External_Source_Provider_Interface {

	public function slug(): string {
		return 'csv';
	}

	public function label(): string {
		return __( 'CSV', 'lnkup-marketplace' );
	}

	public function supports_url_import(): bool {
		return false;
	}

	public function supports_discovery(): bool {
		return false;
	}

	/**
	 * @param array<string, mixed> $context Unused.
	 */
	public function discover_event_urls( array $context ) {
		return new WP_Error( 'unsupported', __( 'CSV provider does not support discovery.', 'lnkup-marketplace' ) );
	}

	/**
	 * @param string $url Unused.
	 */
	public function parse_url( string $url ) {
		return new WP_Error( 'unsupported', __( 'Use CSV import endpoint.', 'lnkup-marketplace' ) );
	}
}
