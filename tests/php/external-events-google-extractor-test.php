<?php
/**
 * Smoke tests for page extractor and create() field mapping.
 *
 * Run: php tests/php/external-events-google-extractor-test.php
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
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

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
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

if ( ! function_exists( 'lnkup_get_event_type_parents' ) ) {
	function lnkup_get_event_type_parents() {
		return array(
			(object) array( 'name' => 'Festivals' ),
			(object) array( 'name' => 'Markets & Pop-Ups' ),
		);
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field ) {
		$out = array();
		foreach ( $list as $item ) {
			$out[] = is_object( $item ) ? $item->{$field} : $item[ $field ];
		}
		return $out;
	}
}

$plugin = dirname( __DIR__, 2 ) . '/wp-content/plugins/lnkup-marketplace';
require_once $plugin . '/includes/class-external-events.php';
require_once $plugin . '/includes/class-page-extractor.php';
require_once $plugin . '/includes/class-search-client-interface.php';
require_once $plugin . '/includes/external-sources/class-external-source-google.php';

$html = <<<HTML
<!doctype html>
<html>
<head>
<title>Ignore me</title>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"Event","name":"Atlanta Summer Festival","description":"Food and craft vendors wanted.","startDate":"2026-07-18","location":{"@type":"Place","name":"Piedmont Park","address":{"@type":"PostalAddress","addressLocality":"Atlanta","addressRegion":"GA"}},"organizer":{"@type":"Organization","name":"ATL Festivals"}}
</script>
</head>
<body>
<a href="https://atlsummerfest.example/vendors/apply">Vendor application</a>
<a href="https://instagram.com/atlsummerfest">Follow us</a>
<p>Questions: booths@atlsummerfest.org</p>
</body>
</html>
HTML;

$fields = LNKup_Page_Extractor::extract( $html, 'https://atlsummerfest.example/event?utm_source=google' );
assert( 'Atlanta Summer Festival' === $fields['title'], 'JSON-LD event name' );
assert( '2026-07-18' === $fields['start_datetime'], 'JSON-LD start date' );
assert( 'Atlanta' === $fields['city'], 'JSON-LD city' );
assert( 'GA' === $fields['state'], 'JSON-LD state' );
assert( 'ATL Festivals' === $fields['organizer_name'], 'JSON-LD organizer' );
assert( 'https://atlsummerfest.example/vendors/apply' === $fields['application_url'], 'application href' );
assert( 'https://www.instagram.com/atlsummerfest' === $fields['organizer_instagram'], 'instagram handle' );
assert( 'booths@atlsummerfest.org' === $fields['organizer_email'], 'email from page' );
assert( 'Festivals' === $fields['event_type'], 'category from title' );

$apply_only = <<<HTML
<html><body>
<h1>Raleigh Night Market</h1>
<a href="/booth-registration">Register a booth</a>
</body></html>
HTML;
$apply = LNKup_Page_Extractor::extract( $apply_only, 'https://raleightonight.example/2026' );
assert( 'https://raleightonight.example/booth-registration' === $apply['application_url'], 'relative apply href' );

$provider = new LNKup_External_Source_Google();
$urls     = $provider->discover_event_urls(
	array(
		'urls'    => array(
			'https://www.facebook.com/groups/vendors',
			'https://atlsummerfest.example/vendors?utm_campaign=spring',
		),
		'snippet' => '',
	)
);
assert( is_array( $urls ), 'injected urls return array' );
assert( 1 === count( $urls ), 'skip-list filters facebook group' );
assert( 'https://atlsummerfest.example/vendors' === $urls[0], 'normalized remaining url' );

$normalize = new ReflectionMethod( 'LNKup_External_Events', 'normalize_row' );
$normalize->setAccessible( true );
$row = $normalize->invoke(
	null,
	array(
		'source_platform'     => 'google',
		'source_url'          => 'https://atlsummerfest.example/event',
		'title'               => 'Atlanta Summer Festival',
		'application_url'     => 'https://atlsummerfest.example/vendors/apply',
		'organizer_instagram' => '@atlsummerfest',
		'discovery_query'     => 'Atlanta "vendors wanted" festival',
		'workflow_status'     => LNKup_External_Events::WORKFLOW_PENDING_REVIEW,
	),
	true
);

assert( 'google' === $row['source_platform'], 'create maps google platform' );
assert( LNKup_External_Events::WORKFLOW_PENDING_REVIEW === $row['workflow_status'], 'pending_review default for google' );
assert( 'https://atlsummerfest.example/vendors/apply' === $row['application_url'], 'application_url stored' );
assert( 'https://www.instagram.com/atlsummerfest' === $row['organizer_instagram'], 'instagram stored' );
assert( 'Atlanta "vendors wanted" festival' === $row['discovery_query'], 'discovery_query stored' );

echo "external-events-google-extractor-test: OK\n";
