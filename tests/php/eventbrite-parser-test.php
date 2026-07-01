<?php
/**
 * Smoke test for Eventbrite HTML parser (run: php tests/php/eventbrite-parser-test.php from repo root with WP loaded).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

$fixture = dirname( __DIR__ ) . '/fixtures/eventbrite-event-page.html';
if ( ! is_readable( $fixture ) ) {
	fwrite( STDERR, "Fixture not found: {$fixture}\n" );
	exit( 1 );
}

$html = (string) file_get_contents( $fixture );

if ( ! class_exists( 'LNKup_External_Source_Eventbrite' ) ) {
	// Minimal stubs when run outside WordPress.
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
	require_once dirname( __DIR__, 2 ) . '/wp-content/plugins/lnkup-marketplace/includes/class-external-events.php';
	require_once dirname( __DIR__, 2 ) . '/wp-content/plugins/lnkup-marketplace/includes/external-sources/class-external-source-eventbrite.php';
}

$json = LNKup_External_Source_Eventbrite::extract_from_json_ld( $html );
assert( ! empty( $json['title'] ), 'JSON-LD title' );
assert( ! empty( $json['image_url'] ), 'JSON-LD image' );

$next = LNKup_External_Source_Eventbrite::extract_from_next_data( $html );
assert( ! empty( $next['organizer_email'] ), 'NEXT_DATA organizer email' );
assert( str_contains( (string) ( $next['_category_hint'] ?? '' ), 'Food' ), 'NEXT_DATA category hint' );

$og = LNKup_External_Source_Eventbrite::extract_meta_image( $html );
assert( str_contains( $og, 'og-summer-food-fest' ), 'og:image fallback' );

assert( '' === LNKup_External_Source_Eventbrite::sanitize_image_url( 'https://www.eventbrite.com/e/foo-tickets-123' ), 'reject event page as image' );
assert( str_contains(
	LNKup_External_Source_Eventbrite::sanitize_image_url( 'https://cdn.evbuc.com/images/example.jpg' ),
	'cdn.evbuc.com'
), 'accept CDN image' );

$next_proxy = '/e/_next/image?url=' . rawurlencode( 'https://cdn.evbuc.com/images/proxy-target.jpg' ) . '&w=512&q=75';
$resolved   = LNKup_External_Source_Eventbrite::sanitize_image_url( $next_proxy );
assert( str_starts_with( $resolved, 'https://' ), 'resolve Next.js proxy to https CDN' );
assert( str_contains( $resolved, 'cdn.evbuc.com' ), 'Next.js proxy resolves to CDN URL' );
assert( '' === LNKup_External_Source_Eventbrite::sanitize_image_url( '/e/_next/image?w=512&q=75' ), 'reject proxy without embedded url' );

$browse_fixture = dirname( __DIR__ ) . '/fixtures/eventbrite-city-browse.html';
assert( is_readable( $browse_fixture ), 'browse fixture readable' );
$browse_html = (string) file_get_contents( $browse_fixture );
$urls        = LNKup_External_Source_Eventbrite::extract_event_urls_from_browse_html( $browse_html );
assert( count( $urls ) >= 3, 'browse page extracts multiple event URLs' );
foreach ( $urls as $url ) {
	assert( str_contains( $url, '/e/' ), 'discovered URL is event detail page' );
}

$charlotte_url = LNKup_External_Source_Eventbrite::build_browse_url(
	LNKup_External_Source_Eventbrite::market_browse_url_map()['charlotte']
);
assert( str_contains( $charlotte_url, 'charlotte' ), 'charlotte browse URL' );

echo "eventbrite-parser-test: OK\n";
