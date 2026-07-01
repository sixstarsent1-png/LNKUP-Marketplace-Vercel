<?php
/**
 * External event source provider interface.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

interface LNKup_External_Source_Provider_Interface {

	/**
	 * Provider slug.
	 */
	public function slug(): string;

	/**
	 * Human label.
	 */
	public function label(): string;

	/**
	 * Whether provider can parse URLs.
	 */
	public function supports_url_import(): bool;

	/**
	 * Whether provider can discover event URLs (scheduled sync).
	 */
	public function supports_discovery(): bool;

	/**
	 * Discover event detail URLs for auto-import.
	 *
	 * @param array<string, mixed> $context Discovery context (market_slug, city, state, keywords).
	 * @return array<int, string>|WP_Error List of normalized event URLs.
	 */
	public function discover_event_urls( array $context );

	/**
	 * Parse a source URL into event fields (preview).
	 *
	 * @param string $url Source URL.
	 * @return array<string, mixed>|WP_Error
	 */
	public function parse_url( string $url );
}
