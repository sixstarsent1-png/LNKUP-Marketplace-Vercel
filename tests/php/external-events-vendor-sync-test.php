<?php
/**
 * Smoke tests for vendor-focused external event sync scoring/filtering.
 *
 * Run: php tests/php/external-events-vendor-sync-test.php
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		$email = trim( (string) $email );
		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return (bool) sanitize_email( (string) $email );
	}
}

if ( ! class_exists( 'LNKup_External_Events' ) ) {
	require_once dirname( __DIR__, 2 ) . '/wp-content/plugins/lnkup-marketplace/includes/class-external-events.php';
}

$short_desc = str_repeat( 'Community gathering. ', 20 );
$long_tail  = ' Contact vendors@foodtruckfest.example.com for food truck booth info.';
$full       = $short_desc . $long_tail;

$score = LNKup_External_Events::score_vendor_relevance(
	'Summer Food Truck Festival',
	$full,
	'Food & Drink'
);
assert( ! empty( $score['matched'] ), 'vendor terms matched in full description' );
assert( $score['score'] >= 40, 'vendor fit score meaningful' );

$truncated = substr( $full, 0, 280 );
$miss_on_trunc = LNKup_External_Events::score_vendor_relevance(
	'Community Block Party',
	$truncated,
	''
);
assert( empty( $miss_on_trunc['matched'] ) || $miss_on_trunc['score'] < $score['score'], 'truncated text may miss tail terms' );

$haystack = LNKup_External_Events::vendor_filter_haystack(
	array(
		'title'       => 'Art Walk',
		'description' => 'Local artists only, no vendors.',
		'event_type'  => 'Festivals',
	)
);
assert( str_contains( $haystack, 'vendor' ), 'vendor filter haystack includes description' );

$email = LNKup_External_Events::extract_email_from_text( $full );
assert( 'vendors@foodtruckfest.example.com' === $email, 'email extracted from text after 280 chars' );

echo "external-events-vendor-sync-test: OK\n";
