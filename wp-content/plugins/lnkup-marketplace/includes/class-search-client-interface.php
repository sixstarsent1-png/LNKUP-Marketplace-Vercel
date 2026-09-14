<?php
/**
 * Search client contract for SERP providers.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

interface LNKup_Search_Client_Interface {

	/**
	 * Whether credentials are present.
	 */
	public function is_configured(): bool;

	/**
	 * Run a web search.
	 *
	 * @param string $query Query text.
	 * @param int    $start 1-based result offset (CSE uses 1, 11, …).
	 * @return array<int, array{url: string, title: string, snippet: string}>|WP_Error
	 */
	public function search( string $query, int $start = 1 );
}
