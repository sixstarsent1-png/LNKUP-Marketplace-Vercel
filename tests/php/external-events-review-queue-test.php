<?php
/**
 * Smoke tests for external events review queue helpers.
 *
 * Run: php tests/php/external-events-review-queue-test.php
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

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return (bool) filter_var( trim( (string) $email ), FILTER_VALIDATE_EMAIL );
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

if ( ! class_exists( 'LNKup_External_Source_Eventbrite' ) ) {
	require_once dirname( __DIR__, 2 ) . '/wp-content/plugins/lnkup-marketplace/includes/external-sources/class-external-source-eventbrite.php';
}

if ( ! class_exists( 'LNKup_External_Events' ) ) {
	require_once dirname( __DIR__, 2 ) . '/wp-content/plugins/lnkup-marketplace/includes/class-external-events.php';
}

$ready_row = array(
	'image_url'       => 'https://cdn.evbuc.com/images/example.jpg',
	'organizer_email' => 'organizer@example.com',
	'event_type'      => 'Festivals',
);
$ready = LNKup_External_Events::review_readiness( $ready_row );
assert( ! empty( $ready['ready'] ), 'complete row is ready' );
assert( empty( $ready['warnings'] ), 'ready row has no warnings' );

$missing_email = LNKup_External_Events::review_readiness(
	array_merge(
		$ready_row,
		array( 'organizer_email' => '' )
	)
);
assert( empty( $missing_email['ready'] ), 'missing email not ready' );
assert( ! empty( $missing_email['warnings'] ), 'missing email has warnings' );

$bad_image = LNKup_External_Events::review_readiness(
	array_merge(
		$ready_row,
		array( 'image_url' => 'https://www.eventbrite.com/e/summer-fest-123' )
	)
);
assert( empty( $bad_image['ready'] ), 'event page image URL not ready' );

$proxy_image = LNKup_External_Events::review_readiness(
	array_merge(
		$ready_row,
		array( 'image_url' => '/e/_next/image?w=512&q=75' )
	)
);
assert( empty( $proxy_image['ready'] ), 'unresolved Next.js proxy not ready' );

$clause = LNKup_External_Events::ready_to_publish_sql_clause();
assert( str_contains( $clause, 'organizer_email' ), 'ready SQL clause checks email' );
assert( str_contains( $clause, 'event_type' ), 'ready SQL clause checks event type' );

echo "external-events-review-queue-test: OK\n";
