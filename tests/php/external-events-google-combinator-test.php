<?php
/**
 * Smoke tests for Google Search combinator, URL normalize, skip-list, CSE client.
 *
 * Run: php tests/php/external-events-google-combinator-test.php
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', trim( (string) $title ) ) );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		$url = trim( (string) $url );
		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'lnkup_get_settings' ) ) {
	function lnkup_get_settings() {
		return array(
			'google_cse_api_key' => '',
			'google_cse_cx'      => '',
		);
	}
}

$plugin = dirname( __DIR__, 2 ) . '/wp-content/plugins/lnkup-marketplace';
require_once $plugin . '/includes/class-search-query-combinator.php';
require_once $plugin . '/includes/class-page-extractor.php';
require_once $plugin . '/includes/class-search-client-interface.php';
require_once $plugin . '/includes/class-search-client-google-cse.php';

$markets = array(
	array(
		'slug'       => 'atlanta',
		'city'       => 'Atlanta',
		'state'      => 'GA',
		'state_name' => 'Georgia',
	),
	array(
		'slug'       => 'charlotte',
		'city'       => 'Charlotte',
		'state'      => 'NC',
		'state_name' => 'North Carolina',
	),
);

$templates = array(
	'{city} "vendors wanted" festival',
	'{city} "vendor application" {year}',
);

$rows = LNKup_Search_Query_Combinator::expand( $markets, $templates, 2026 );
$queries = array_column( $rows, 'query_text' );

assert( 6 === count( $rows ), '2 cities × 1 static + 2 cities × 2 years' );
assert( in_array( 'Atlanta "vendors wanted" festival', $queries, true ), 'Atlanta vendors wanted' );
assert( in_array( 'Charlotte "vendor application" 2026', $queries, true ), 'Charlotte 2026' );
assert( in_array( 'Atlanta "vendor application" 2027', $queries, true ), 'year expands next year' );

$state_rows = LNKup_Search_Query_Combinator::expand(
	$markets,
	array( '{state} "become a vendor"' ),
	2026
);
$state_queries = array_column( $state_rows, 'query_text' );
assert( 2 === count( $state_rows ), 'state templates expand once per state' );
assert( in_array( 'Georgia "become a vendor"', $state_queries, true ), 'Georgia state query' );
assert( in_array( 'North Carolina "become a vendor"', $state_queries, true ), 'NC state query' );

$normalized = LNKup_Page_Extractor::normalize_url(
	'https://WWW.Example.com/vendors/apply/?utm_source=google&utm_medium=cpc&fbclid=abc123'
);
assert( 'https://www.example.com/vendors/apply' === $normalized, 'strip tracking + lowercase host' );

assert( LNKup_Page_Extractor::should_skip_url( 'https://www.facebook.com/groups/atlantavendors' ), 'skip facebook groups' );
assert( LNKup_Page_Extractor::should_skip_url( 'https://www.reddit.com/r/festivals' ), 'skip reddit' );
assert( LNKup_Page_Extractor::should_skip_url( 'https://www.nytimes.com/2026/06/01/festival.html' ), 'skip news without apply snippet' );
assert(
	! LNKup_Page_Extractor::should_skip_url(
		'https://www.nytimes.com/2026/06/01/festival.html',
		'Vendor applications open for the summer festival'
	),
	'allow news when snippet has apply language'
);
assert( ! LNKup_Page_Extractor::should_skip_url( 'https://atlantasummerfest.example/vendors' ), 'keep festival sites' );

$client = LNKup_Search_Client_Google_Cse::from_settings();
assert( ! $client->is_configured(), 'CSE client skipped when key missing' );

$empty = $client->search( 'Atlanta vendors wanted' );
assert( is_wp_error( $empty ) && 'not_configured' === $empty->get_error_code(), 'search without keys is WP_Error' );

require_once $plugin . '/includes/class-external-events-sync.php';
$skipped = LNKup_External_Events_Sync::run_google_discovery_batch(
	array(
		'google_enabled' => true,
	)
);
assert( ! empty( $skipped['messages'] ), 'sync skip message when CSE missing' );
assert( false !== strpos( implode( ' ', $skipped['messages'] ), 'CSE credentials missing' ), 'sync skip names missing credentials' );
assert( 0 === (int) $skipped['imported'], 'sync skip imports nothing' );

echo "external-events-google-combinator-test: OK\n";
