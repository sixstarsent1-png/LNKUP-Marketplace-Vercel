<?php
/**
 * Unit tests for the Meta measurement module (pure logic, minimal WordPress stubs).
 *
 * Run: php tests/php/meta-measurement-test.php
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['lnkup_test_options']   = array();
$GLOBALS['lnkup_test_user_meta'] = array();
$GLOBALS['lnkup_test_filters']   = array();

function sanitize_text_field( $s ) {
	return trim( (string) preg_replace( '/[\r\n\t<>]+/', ' ', strip_tags( (string) $s ) ) );
}
function sanitize_key( $s ) {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
}
function sanitize_title( $s ) {
	return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' );
}
function absint( $v ) {
	return abs( (int) $v );
}
function wp_unslash( $v ) {
	return $v;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}
function home_url( $path = '' ) {
	return 'https://thelnkup.com' . $path;
}
function esc_url_raw( $url ) {
	return (string) $url;
}
function apply_filters( $tag, $value, ...$args ) {
	return isset( $GLOBALS['lnkup_test_filters'][ $tag ] ) ? call_user_func( $GLOBALS['lnkup_test_filters'][ $tag ], $value, ...$args ) : $value;
}
function get_option( $key, $default = false ) {
	return $GLOBALS['lnkup_test_options'][ $key ] ?? $default;
}
function add_option( $key, $value ) {
	$GLOBALS['lnkup_test_options'][ $key ] = $value;
	return true;
}
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}
function wp_generate_password( $len = 12 ) {
	return substr( str_repeat( 'abcdefghijklmnopqrstuvwxyz0123456789', 3 ), 0, $len );
}
function get_user_meta( $user_id, $key, $single = false ) {
	return $GLOBALS['lnkup_test_user_meta'][ $user_id ][ $key ] ?? '';
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}
function __( $s ) {
	return $s;
}

$plugin = getenv( 'LNKUP_PLUGIN_DIR' ) ?: dirname( __DIR__, 2 ) . '/wp-content/plugins/lnkup-marketplace';
foreach ( array( 'class-meta-config', 'class-meta-redactor', 'class-meta-cookies', 'class-meta-user-data', 'class-tracking-consent', 'class-conversion-registry', 'class-meta-dispatcher', 'class-acquisition-attribution', 'class-meta-dataset-quality', 'class-meta-rest' ) as $file ) {
	require_once $plugin . '/includes/meta/' . $file . '.php';
}

$failures = 0;
$checks   = 0;

/**
 * Record one expectation.
 *
 * @param bool   $condition Expected truthy.
 * @param string $label     Description.
 */
function lnkup_meta_check( $condition, $label ) {
	global $failures, $checks;
	++$checks;
	if ( $condition ) {
		echo "  ok   {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL {$label}\n";
}

echo "Customer information normalization and hashing\n";
lnkup_meta_check( LNKup_Meta_User_Data::normalize_email( '  Jane.Doe@Example.COM ' ) === 'jane.doe@example.com', 'email is trimmed and lowercased' );
lnkup_meta_check( LNKup_Meta_User_Data::normalize_email( 'not-an-email' ) === '', 'invalid email is dropped' );
lnkup_meta_check( LNKup_Meta_User_Data::hash( 'jane.doe@example.com' ) === hash( 'sha256', 'jane.doe@example.com' ), 'email hashed with SHA-256' );
$hashed = hash( 'sha256', 'x' );
lnkup_meta_check( LNKup_Meta_User_Data::hash( $hashed ) === $hashed, 'already-hashed value is not hashed twice' );
lnkup_meta_check( LNKup_Meta_User_Data::hash( '' ) === '', 'empty value stays empty' );
lnkup_meta_check( LNKup_Meta_User_Data::normalize_phone( '(704) 555-0199' ) === '17045550199', 'US 10-digit phone gets country code' );
lnkup_meta_check( LNKup_Meta_User_Data::normalize_phone( '+44 20 7946 0958' ) === '442079460958', 'international phone keeps its country code' );
lnkup_meta_check( LNKup_Meta_User_Data::normalize_phone( '555-0199' ) === '', 'too-short phone is dropped' );
lnkup_meta_check( LNKup_Meta_User_Data::normalize_name( " O'Brien, Jr. " ) === 'obrien jr', 'name lowercased without punctuation' );
lnkup_meta_check( LNKup_Meta_User_Data::normalize_name( 'José' ) === 'josé', 'UTF-8 letters are kept' );
lnkup_meta_check( LNKup_Meta_User_Data::normalize_city( 'Winston-Salem' ) === 'winstonsalem', 'city has no spaces or punctuation' );
lnkup_meta_check( LNKup_Meta_User_Data::normalize_state( 'North Carolina' ) === 'nc', 'state name maps to 2-letter code' );
lnkup_meta_check( LNKup_Meta_User_Data::normalize_state( 'GA' ) === 'ga', 'state code lowercased' );
lnkup_meta_check( LNKup_Meta_User_Data::normalize_zip( '28202-1234' ) === '28202', 'US zip+4 trimmed to 5 digits' );
lnkup_meta_check( LNKup_Meta_User_Data::normalize_country( 'US' ) === 'us', 'country lowercased' );
$ext1 = LNKup_Meta_User_Data::external_id( 42 );
lnkup_meta_check( (bool) preg_match( '/^[a-f0-9]{64}$/', $ext1 ) && LNKup_Meta_User_Data::external_id( 42 ) === $ext1, 'external_id is a stable SHA-256' );
lnkup_meta_check( LNKup_Meta_User_Data::external_id( 43 ) !== $ext1, 'external_id differs per user' );
lnkup_meta_check( false === strpos( $ext1, '42' ) || hash( 'sha256', '42' ) !== $ext1, 'external_id is salted (not sha256 of the ID)' );
$ud = LNKup_Meta_User_Data::build( 0, array( 'ip' => '203.0.113.9', 'ua' => 'Mozilla/5.0', 'fbp' => 'fb.1.1700000000000.123456789', 'fbc' => 'fb.1.1700000000000.AbCdEf123' ) );
lnkup_meta_check( '203.0.113.9' === ( $ud['client_ip_address'] ?? '' ), 'IP address is sent unhashed' );
lnkup_meta_check( 'Mozilla/5.0' === ( $ud['client_user_agent'] ?? '' ), 'user agent is sent unhashed' );
lnkup_meta_check( 'fb.1.1700000000000.123456789' === ( $ud['fbp'] ?? '' ) && isset( $ud['fbc'] ), 'fbp and fbc are sent unhashed' );
lnkup_meta_check( ! isset( $ud['em'] ) && ! isset( $ud['external_id'] ), 'anonymous events carry no email or external_id' );
$bad = LNKup_Meta_User_Data::build( 0, array( 'ip' => 'not-an-ip', 'fbp' => 'garbage' ) );
lnkup_meta_check( ! isset( $bad['client_ip_address'] ) && ! isset( $bad['fbp'] ), 'malformed IP and fbp are dropped' );
lnkup_meta_check( LNKup_Meta_User_Data::presence( $ud ) === 'ip,ua,fbp,fbc', 'presence lists parameters, not values' );

echo "Click identifiers\n";
lnkup_meta_check( LNKup_Meta_Cookies::build_fbc( 'IwAR123abc', 1700000000000 ) === 'fb.1.1700000000000.IwAR123abc', 'fbc built from fbclid' );
lnkup_meta_check( LNKup_Meta_Cookies::sanitize_fbclid( 'IwAR<script>' ) === '', 'fbclid with markup rejected' );
lnkup_meta_check( LNKup_Meta_Cookies::valid_click_cookie( 'fb.1.1700000000000.IwAR123abc' ), 'valid fbc accepted' );
lnkup_meta_check( ! LNKup_Meta_Cookies::valid_click_cookie( 'fb.1.abc.def' ), 'invalid fbc rejected' );

echo "Credential redaction\n";
$token = 'EAAG' . str_repeat( 'x1Y2', 30 );
lnkup_meta_check( false === strpos( LNKup_Meta_Redactor::redact( 'GET /events?access_token=' . $token . '&x=1', array() ), $token ), 'access_token query parameter is redacted' );
lnkup_meta_check( false === strpos( LNKup_Meta_Redactor::redact( 'Authorization: Bearer ' . $token, array() ), $token ), 'Authorization header is redacted' );
lnkup_meta_check( false === strpos( LNKup_Meta_Redactor::redact( '{"access_token":"' . $token . '"}', array() ), $token ), 'JSON access_token is redacted' );
lnkup_meta_check( false === strpos( LNKup_Meta_Redactor::redact( 'token was ' . $token, array() ), $token ), 'bare EAA token is redacted' );
lnkup_meta_check( false === strpos( LNKup_Meta_Redactor::redact( 'x secret123456 y', array( 'secret123456' ) ), 'secret123456' ), 'configured secret is redacted' );
$deep = LNKup_Meta_Redactor::redact_deep( array( 'access_token' => 'abc', 'nested' => array( 'msg' => 'access_token=zzz' ) ) );
lnkup_meta_check( '[redacted]' === $deep['access_token'] && false === strpos( $deep['nested']['msg'], 'zzz' ), 'nested arrays are redacted' );
lnkup_meta_check( LNKup_Meta_Config::mask( $token ) === 'EAA…x1Y2' && strlen( LNKup_Meta_Config::mask( $token ) ) < 12, 'mask shows only prefix and last 4' );
lnkup_meta_check( LNKup_Meta_Config::mask( '' ) === 'Not configured', 'mask of empty token' );

echo "Configuration\n";
lnkup_meta_check( LNKup_Meta_Config::ad_account_id() === 'act_405302106865918', 'ad account normalized to act_ format' );
lnkup_meta_check( LNKup_Meta_Config::dataset_id() === '2099891494063746' && LNKup_Meta_Config::pixel_id() === '2099891494063746', 'dataset and pixel default to the LNKup dataset' );
lnkup_meta_check( LNKup_Meta_Config::graph_version() === 'v27.0' && ! LNKup_Meta_Config::graph_version_outdated(), 'Graph API defaults to supported v27.0' );
$GLOBALS['lnkup_test_options'][ LNKup_Meta_Config::OPTION ] = array( 'graph_version' => 'v19.0' );
lnkup_meta_check( LNKup_Meta_Config::graph_version_outdated(), 'v19.0 flagged as outdated' );
$GLOBALS['lnkup_test_options'][ LNKup_Meta_Config::OPTION ] = array( 'graph_version' => 'latest' );
lnkup_meta_check( LNKup_Meta_Config::graph_version() === 'v27.0', 'malformed version falls back to default' );
$GLOBALS['lnkup_test_options'][ LNKup_Meta_Config::OPTION ] = array();
lnkup_meta_check( ! LNKup_Meta_Config::capi_enabled() && ! LNKup_Meta_Config::pixel_enabled(), 'everything defaults to off' );
lnkup_meta_check( LNKup_Meta_Config::capi_block_reason() === 'capi_disabled', 'CAPI blocked while disabled' );
putenv( 'META_CAPI_ENABLED=true' );
lnkup_meta_check( LNKup_Meta_Config::capi_block_reason() === 'token_missing', 'enabled without token is blocked' );
putenv( 'META_CAPI_ACCESS_TOKEN=' . $token );
lnkup_meta_check( LNKup_Meta_Config::capi_block_reason() === '' , 'production host with token may send' );
lnkup_meta_check( in_array( $token, LNKup_Meta_Config::secrets(), true ) && LNKup_Meta_Config::secret_source( 'META_CAPI_ACCESS_TOKEN' ) === 'environment', 'token read from environment, never an option' );
lnkup_meta_check( LNKup_Meta_Config::dataset_quality_token() === $token, 'Dataset Quality reuses the CAPI token when none is set' );
putenv( 'META_TEST_MODE=true' );
lnkup_meta_check( LNKup_Meta_Config::capi_block_reason() === 'test_code_missing', 'test mode requires a test event code' );
putenv( 'META_TEST_EVENT_CODE=TEST123' );
lnkup_meta_check( LNKup_Meta_Config::capi_block_reason() === '' && LNKup_Meta_Config::test_event_code() === 'TEST123', 'test mode with code may send' );
putenv( 'META_TEST_MODE=false' );
$GLOBALS['lnkup_test_filters']['lnkup_meta_is_production'] = static function () {
	return false;
};
lnkup_meta_check( LNKup_Meta_Config::capi_block_reason() === 'non_production', 'staging never sends production events' );
unset( $GLOBALS['lnkup_test_filters']['lnkup_meta_is_production'] );
foreach ( array( 'META_CAPI_ENABLED', 'META_CAPI_ACCESS_TOKEN', 'META_TEST_MODE', 'META_TEST_EVENT_CODE' ) as $env ) {
	putenv( $env );
}

echo "Deduplication keys and event IDs\n";
lnkup_meta_check( LNKup_Conversion_Registry::dedupe_key( 'CompleteRegistration', 'user', 7, '', 0, 'e' ) === 'CompleteRegistration:user:7', 'registration dedupes per user' );
lnkup_meta_check( LNKup_Conversion_Registry::dedupe_key( 'Purchase', 'entity', 7, 'payment', 99, 'e' ) === 'Purchase:payment:99', 'purchase dedupes per payment (webhook retries collapse)' );
lnkup_meta_check( LNKup_Conversion_Registry::dedupe_key( 'Purchase', 'entity', 8, 'payment', 99, 'f' ) === 'Purchase:payment:99', 'same payment, different request, same key' );
lnkup_meta_check( LNKup_Conversion_Registry::dedupe_key( 'AddToWishlist', 'item', 7, 'event', 5, 'e' ) === 'AddToWishlist:event:5:u7', 'wishlist dedupes per user and item' );
lnkup_meta_check( LNKup_Conversion_Registry::dedupe_key( 'ViewContent', 'none', 0, '', 0, 'abc' ) === 'ViewContent:evt:abc', 'anonymous events dedupe on event_id' );
lnkup_meta_check( LNKup_Conversion_Registry::is_uuid( LNKup_Conversion_Registry::uuid() ), 'generated event IDs are UUIDs' );
lnkup_meta_check( ! LNKup_Conversion_Registry::is_uuid( "1' OR 1=1" ), 'non-UUID event IDs rejected' );
$defs = LNKup_Conversion_Registry::definitions();
lnkup_meta_check( ! isset( $defs['AddToCart'] ) && ! isset( $defs['Subscribe'] ) && ! isset( $defs['StartTrial'] ), 'no events without a real LNKup action' );
lnkup_meta_check( empty( $defs['PaymentRefunded']['server'] ) && empty( $defs['PageView']['server'] ), 'refunds internal-only; PageView browser-only' );
lnkup_meta_check( empty( $defs['OrganizerFirstApplicationReceived']['browser'] ), 'passive organizer milestones are server-only' );

echo "Custom data allow-list\n";
$custom = LNKup_Conversion_Registry::sanitize_custom_data(
	array(
		'content_ids'      => array( '12', 'x', 0 ),
		'value'            => '200.456',
		'currency'         => 'usd',
		'answers'          => 'private application answer',
		'document_url'     => 'https://secret',
		'contents'         => array( array( 'id' => 12, 'quantity' => 0 ), 'bad' ),
		'content_name'     => '<b>Booth</b>',
	)
);
lnkup_meta_check( array( '12' ) === $custom['content_ids'], 'content_ids keep only valid IDs' );
lnkup_meta_check( 200.46 === $custom['value'] && 'USD' === $custom['currency'], 'value rounded, currency uppercased' );
lnkup_meta_check( ! isset( $custom['answers'] ) && ! isset( $custom['document_url'] ), 'unlisted fields (answers, documents) are dropped' );
lnkup_meta_check( array( array( 'id' => '12', 'quantity' => 1 ) ) === $custom['contents'], 'contents normalized' );
lnkup_meta_check( 'Booth' === $custom['content_name'], 'markup stripped from names' );

echo "Retry policy\n";
lnkup_meta_check( 60 === LNKup_Meta_Dispatcher::backoff( 1 ) && 300 === LNKup_Meta_Dispatcher::backoff( 2 ), 'exponential backoff starts at 1m, 5m' );
lnkup_meta_check( 43200 === LNKup_Meta_Dispatcher::backoff( 9 ), 'backoff caps at 12h' );
lnkup_meta_check( 900 === LNKup_Meta_Dispatcher::backoff( 1, true ), 'rate-limited retries wait at least 15 minutes' );
lnkup_meta_check( 3600 === LNKup_Meta_Dispatcher::backoff( 1, true, 3600 ), 'Retry-After hint is honored' );
lnkup_meta_check( LNKup_Meta_Dispatcher::MAX_AGE < 7 * DAY_IN_SECONDS, 'events expire before Meta’s 7-day limit' );

echo "Consent\n";
$_SERVER['HTTP_SEC_GPC'] = '1';
lnkup_meta_check( 'denied' === LNKup_Tracking_Consent::state( 0, true ), 'Global Privacy Control refuses tracking' );
lnkup_meta_check( 'granted' === LNKup_Tracking_Consent::state( 0, false ), 'GPC applies only to the request that sent it' );
unset( $_SERVER['HTTP_SEC_GPC'] );
$_COOKIE[ LNKup_Tracking_Consent::OPTOUT_COOKIE ] = '1';
lnkup_meta_check( 'denied' === LNKup_Tracking_Consent::state( 0, true ), 'opt-out cookie refuses tracking' );
unset( $_COOKIE[ LNKup_Tracking_Consent::OPTOUT_COOKIE ] );
$GLOBALS['lnkup_test_user_meta'][9][ LNKup_Tracking_Consent::USER_META ] = '1';
lnkup_meta_check( 'denied' === LNKup_Tracking_Consent::state( 9, false ), 'account opt-out applies to server-side events (e.g. webhooks)' );
lnkup_meta_check( 'granted' === LNKup_Tracking_Consent::state( 10, true ), 'default is granted (US opt-out model)' );
$GLOBALS['lnkup_test_filters']['lnkup_meta_tracking_allowed'] = static function () {
	return false;
};
lnkup_meta_check( 'denied' === LNKup_Tracking_Consent::state( 10, true ), 'consent platform can deny through the filter' );
unset( $GLOBALS['lnkup_test_filters']['lnkup_meta_tracking_allowed'] );

echo "Attribution\n";
$touch = LNKup_Acquisition_Attribution::sanitize_touch(
	array(
		'utm_source'   => 'meta',
		'utm_campaign' => '<script>x</script>Houston Food',
		'utm_content'  => 'https://evil.example/redirect',
		'campaign_id'  => '120211\'; DROP',
		'fbclid'       => 'IwAR_valid-123',
		'email'        => 'a@b.com',
		'landing'      => '/sign-up/?role=vendor&token=secret',
		'referrer'     => 'l.facebook.com',
		'ts'           => time(),
	)
);
lnkup_meta_check( 'meta' === $touch['utm_source'] && false === strpos( $touch['utm_campaign'], '<' ), 'campaign labels sanitized' );
lnkup_meta_check( ! isset( $touch['utm_content'] ), 'URL-shaped values rejected' );
lnkup_meta_check( '120211DROP' === $touch['campaign_id'], 'IDs keep only safe characters' );
lnkup_meta_check( ! isset( $touch['email'] ), 'unknown keys (personal data) dropped' );
lnkup_meta_check( '/sign-up/' === $touch['landing'], 'landing path keeps no query string' );
lnkup_meta_check( LNKup_Acquisition_Attribution::is_campaign( $touch ), 'campaign touch detected' );
lnkup_meta_check( ! LNKup_Acquisition_Attribution::is_campaign( array( 'landing' => '/' ) ), 'direct visit is not a campaign' );
lnkup_meta_check( LNKup_Acquisition_Attribution::is_internal_referrer( 'checkout.stripe.com' ) && LNKup_Acquisition_Attribution::is_internal_referrer( 'www.thelnkup.com' ), 'Stripe and own site are not acquisition sources' );
lnkup_meta_check( ! LNKup_Acquisition_Attribution::is_internal_referrer( 'l.facebook.com' ), 'Facebook referrer counts as a touch' );
lnkup_meta_check( array() === LNKup_Acquisition_Attribution::sanitize_touch( 'not-an-array' ), 'malformed cookie ignored' );

echo "Search term privacy\n";
lnkup_meta_check( 'food truck houston' === LNKup_Meta_REST::sanitize_search( 'food truck houston' ), 'normal search kept' );
lnkup_meta_check( '' === LNKup_Meta_REST::sanitize_search( 'jane@example.com' ), 'email-like search omitted' );
lnkup_meta_check( '' === LNKup_Meta_REST::sanitize_search( 'call 704-555-0199' ), 'phone-like search omitted' );

echo "Dataset Quality parsing (official sample shapes)\n";
$metrics = LNKup_Meta_Dataset_Quality::extract(
	array(
		'event_name'          => 'CompleteRegistration',
		'event_match_quality' => array(
			'composite_score'    => 7.2,
			'match_key_feedback' => array( array( 'identifier' => 'email', 'coverage' => array( 'percentage' => 100 ) ), array( 'identifier' => 'ip_address', 'coverage' => array( 'percentage' => 99.9 ) ) ),
		),
		'event_coverage'      => array( 'percentage' => 34.1, 'goal_percentage' => 75 ),
		'dedupe_key_feedback' => array( array( 'dedupe_key' => 'event_id', 'overall_browser_coverage_from_dedupe_key' => array( 'percentage' => 14.8 ) ) ),
		'data_freshness'      => array( 'upload_frequency' => 'real_time' ),
	)
);
lnkup_meta_check( 7.2 === $metrics['emq_score'][0], 'EMQ composite score stored' );
lnkup_meta_check( 99.9 === $metrics['key_ip_address'][0] && 100.0 === $metrics['key_email'][0], 'match key coverage stored' );
lnkup_meta_check( 34.1 === $metrics['event_coverage'][0] && 14.8 === $metrics['dedupe_event_id_overall'][0], 'coverage and dedup feedback stored' );
lnkup_meta_check( null === $metrics['freshness'][0] && 'real_time' === $metrics['freshness'][1], 'freshness stored as text, not a fake number' );
lnkup_meta_check( ! isset( $metrics['acr'] ) && ! isset( $metrics['delay_2h_pct'] ), 'metrics Meta did not return are absent (unavailable), not zero' );
lnkup_meta_check( array() === LNKup_Meta_Dataset_Quality::extract( array( 'event_name' => 'PageView' ) ), 'event with no metrics yields nothing' );

printf( "\n%d checks, %d failures\n", $checks, $failures );
exit( $failures > 0 ? 1 : 0 );
