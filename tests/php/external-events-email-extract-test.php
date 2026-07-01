<?php
/**
 * Smoke tests for organizer email extraction from description text.
 *
 * Run from repo root with WordPress loaded, or standalone with stubs:
 * php tests/php/external-events-email-extract-test.php
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

$valid = LNKup_External_Events::extract_email_from_text(
	'Contact the organizer at vendors@summerfest.example.com for booth details.'
);
assert( 'vendors@summerfest.example.com' === $valid, 'plain text email' );

$mailto = LNKup_External_Events::extract_email_from_text(
	'<p>Email <a href="mailto:booth-team@foodmarket.org">booth-team@foodmarket.org</a></p>'
);
assert( 'booth-team@foodmarket.org' === $mailto, 'mailto link email' );

$blocked = LNKup_External_Events::extract_email_from_text(
	'Questions? Email support@eventbrite.com or noreply@platform.test'
);
assert( '' === $blocked, 'blocklisted platform emails' );

$first_wins = LNKup_External_Events::extract_email_from_text(
	'Reach us at hello@festival.co or backup@festival.co'
);
assert( 'hello@festival.co' === $first_wins, 'first valid email wins' );

list( $data, $extracted ) = LNKup_External_Events::maybe_extract_organizer_email(
	array(
		'description_excerpt' => 'Vendor inquiries: apply@popupmarket.io',
	)
);
assert( $extracted, 'maybe_extract sets flag' );
assert( 'apply@popupmarket.io' === $data['organizer_email'], 'maybe_extract sets email' );

list( $unchanged, $not_extracted ) = LNKup_External_Events::maybe_extract_organizer_email(
	array(
		'organizer_email' => 'existing@organizer.test',
		'description_excerpt' => 'other@email.test',
	)
);
assert( ! $not_extracted, 'maybe_extract skips when email present' );
assert( 'existing@organizer.test' === $unchanged['organizer_email'], 'maybe_extract preserves email' );

$long_desc = str_repeat( 'Event details. ', 30 ) . ' Email booth@lateinfo.example.com for vendor booths.';
list( $with_email, $extracted_long ) = LNKup_External_Events::maybe_extract_organizer_email(
	array(
		'description' => $long_desc,
		'description_excerpt' => substr( $long_desc, 0, 280 ),
	)
);
assert( $extracted_long, 'maybe_extract from full description beyond excerpt' );
assert( 'booth@lateinfo.example.com' === $with_email['organizer_email'], 'full description email wins' );

echo "external-events-email-extract-test: OK\n";
