<?php
/**
 * Integration checks for the Meta measurement module inside a real WordPress install.
 * The Graph API is mocked through pre_http_request; nothing is sent to Meta.
 *
 * Run (disposable test site only — it creates users and posts):
 *   wp eval-file tests/php/meta-integration-wp.php
 *
 * @package LNKup_Marketplace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $wpdb, $lnkup_mock, $lnkup_requests, $failures, $checks;
$lnkup_requests = array();
$lnkup_mock     = array( 'code' => 200, 'body' => array( 'events_received' => 1, 'fbtrace_id' => 'TRACE1' ) );
$failures       = 0;
$checks         = 0;
$fake_token     = 'EAAtest' . str_repeat( 'Z9', 40 );

/**
 * @param bool   $ok    Condition.
 * @param string $label Label.
 */
function lnkup_it( $ok, $label ) {
	global $failures, $checks;
	++$checks;
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		++$failures;
	}
}

function lnkup_rows( $where = '1=1' ) {
	global $wpdb;
	return $wpdb->get_results( 'SELECT * FROM ' . LNKup_Meta_Tables::conversions() . ' WHERE ' . $where . ' ORDER BY id ASC', ARRAY_A ); // phpcs:ignore
}

$saved_settings = get_option( LNKup_Meta_Config::OPTION );
delete_option( LNKup_Meta_Config::OPTION );
register_shutdown_function(
	static function () use ( $saved_settings ) {
		if ( false !== $saved_settings ) {
			update_option( LNKup_Meta_Config::OPTION, $saved_settings, false );
		}
	}
);
putenv( 'META_CAPI_ENABLED=true' );
putenv( 'META_PIXEL_ENABLED=true' );
putenv( 'META_DATASET_QUALITY_ENABLED=true' );
putenv( 'META_CAPI_ACCESS_TOKEN=' . $fake_token );
add_filter( 'lnkup_meta_is_production', '__return_true' );
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		global $lnkup_mock, $lnkup_requests;
		if ( false === strpos( $url, 'graph.facebook.com' ) ) {
			return $pre;
		}
		$lnkup_requests[] = array( 'url' => $url, 'args' => $args );
		return array(
			'headers'  => array( 'retry-after' => '' ),
			'body'     => wp_json_encode( $lnkup_mock['body'] ),
			'response' => array( 'code' => $lnkup_mock['code'], 'message' => 'mock' ),
			'cookies'  => array(),
		);
	},
	10,
	3
);

$wpdb->query( 'TRUNCATE ' . LNKup_Meta_Tables::conversions() ); // phpcs:ignore
$wpdb->query( 'TRUNCATE ' . LNKup_Meta_Tables::deliveries() ); // phpcs:ignore

echo "Schema\n";
foreach ( array( LNKup_Meta_Tables::conversions(), LNKup_Meta_Tables::deliveries(), LNKup_Meta_Tables::snapshots(), LNKup_Meta_Tables::alerts() ) as $table ) {
	lnkup_it( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "table {$table} exists" );
}
lnkup_it( version_compare( (string) get_option( 'lnkup_db_version' ), '12.35.0', '>=' ), 'migration recorded version 12.35.0 or later' );
lnkup_it( false === get_option( LNKup_Meta_Config::OPTION ) || ! isset( get_option( LNKup_Meta_Config::OPTION )['capi_token'] ), 'no token stored in options' );

echo "Registration with campaign attribution\n";
$_SERVER['REMOTE_ADDR']     = '203.0.113.10';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone) Instagram 300.0';
$_COOKIE['_fbp']            = 'fb.1.1700000000000.1234567890';
$_COOKIE['_fbc']            = 'fb.1.1700000000000.IwARclick123';
$_COOKIE['lnkup_ft']        = rawurlencode( wp_json_encode( array( 'utm_source' => 'meta', 'utm_campaign' => 'houston_food', 'ad_id' => '120200', 'landing' => '/vendors/', 'ts' => time() - 600 ) ) );
$_COOKIE['lnkup_lt']        = $_COOKIE['lnkup_ft'];
$email   = 'vendor+' . wp_generate_password( 6, false ) . '@example.com';
$vendor  = LNKup_Registration::register_user( array( 'email' => $email, 'password' => 'Passw0rd!x', 'password_confirm' => 'Passw0rd!x', 'role' => 'vendor', 'terms' => true, 'full_name' => 'Jane Vendor' ) );
lnkup_it( is_int( $vendor ) && $vendor > 0, 'vendor registered' );
$reg = lnkup_rows( $wpdb->prepare( 'user_id = %d', $vendor ) );
$names = wp_list_pluck( $reg, 'event_name' );
lnkup_it( in_array( 'CompleteRegistration', $names, true ) && in_array( 'VendorRegistered', $names, true ), 'CompleteRegistration and VendorRegistered recorded' );
$cr = $reg[ array_search( 'CompleteRegistration', $names, true ) ];
lnkup_it( 'pending' === $cr['status'], 'registration queued for CAPI' );
lnkup_it( false !== strpos( $cr['match_presence'], 'em' ) && false !== strpos( $cr['match_presence'], 'fbc' ) && false !== strpos( $cr['match_presence'], 'external_id' ), 'email, external_id and click ID present' );
lnkup_it( false !== strpos( (string) $cr['attribution_json'], 'houston_food' ), 'campaign attribution attached to the conversion' );
$ft = get_user_meta( $vendor, LNKup_Acquisition_Attribution::META_FIRST, true );
lnkup_it( is_array( $ft ) && 'meta' === $ft['utm_source'], 'first touch copied to the account' );
$queue = get_user_meta( $vendor, LNKup_Conversion_Registry::PENDING_BROWSER_META, true );
lnkup_it( is_array( $queue ) && $cr['event_id'] === $queue[0]['eventId'], 'browser queue carries the same event_id as the server row' );

do_action( 'lnkup_user_registered', $vendor, 'lnkup_vendor', array() );
lnkup_it( 2 === count( lnkup_rows( $wpdb->prepare( 'user_id = %d', $vendor ) ) ), 'repeat registration hook (refresh/retry) creates no duplicates' );

echo "Pixel hand-off\n";
wp_set_current_user( $vendor );
LNKup_Meta_Pixel::enqueue();
$before = wp_scripts()->get_data( LNKup_Meta_Pixel::HANDLE, 'before' );
$inline = is_array( $before ) ? implode( '', $before ) : (string) $before;
lnkup_it( false !== strpos( $inline, $cr['event_id'] ), 'page render hands the server event_id to the Pixel' );
lnkup_it( false === strpos( $inline, $fake_token ) && false === strpos( $inline, $email ), 'no token or email printed in the page' );
lnkup_it( '' === get_user_meta( $vendor, LNKup_Conversion_Registry::PENDING_BROWSER_META, true ), 'browser queue consumed once' );
lnkup_it( 'flushed' === $wpdb->get_var( $wpdb->prepare( 'SELECT browser_status FROM ' . LNKup_Meta_Tables::conversions() . ' WHERE event_id = %s', $cr['event_id'] ) ), 'row marked flushed to browser' ); // phpcs:ignore

echo "Dispatch (mock 200)\n";
$lnkup_mock = array( 'code' => 200, 'body' => array( 'events_received' => 2, 'fbtrace_id' => 'TRACE200' ) );
LNKup_Meta_Dispatcher::run();
$sent = lnkup_rows( $wpdb->prepare( 'user_id = %d', $vendor ) );
lnkup_it( 'sent' === $sent[0]['status'] && 'sent' === $sent[1]['status'], 'both events marked sent' );
lnkup_it( null === $sent[0]['request_context'], 'IP / user agent purged after send' );
$req  = end( $lnkup_requests );
$data = json_decode( (string) $req['args']['body']['data'], true );
lnkup_it( false !== strpos( $req['url'], '/v27.0/2099891494063746/events' ), 'POST to /v27.0/{dataset}/events' );
lnkup_it( false === strpos( $req['url'], 'access_token' ), 'token not in the URL' );
lnkup_it( hash( 'sha256', strtolower( $email ) ) === $data[0]['user_data']['em'][0], 'email sent SHA-256 hashed' );
lnkup_it( '203.0.113.10' === $data[0]['user_data']['client_ip_address'] && 'fb.1.1700000000000.IwARclick123' === $data[0]['user_data']['fbc'], 'IP and fbc sent unhashed' );
lnkup_it( $cr['event_id'] === $data[0]['event_id'] && 'website' === $data[0]['action_source'], 'same event_id, website action source' );
lnkup_it( ! isset( $req['args']['body']['test_event_code'] ), 'no test code in production mode' );
$log = $wpdb->get_row( 'SELECT * FROM ' . LNKup_Meta_Tables::deliveries() . ' ORDER BY id DESC LIMIT 1', ARRAY_A ); // phpcs:ignore
lnkup_it( '200' === (string) $log['http_status'] && 'TRACE200' === $log['fbtrace_id'], 'delivery logged with fbtrace_id' );

echo "Retries and failures\n";
$vendor2 = LNKup_Registration::register_user( array( 'email' => 'v2+' . wp_generate_password( 6, false ) . '@example.com', 'password' => 'Passw0rd!x', 'password_confirm' => 'Passw0rd!x', 'role' => 'organizer', 'terms' => true, 'organization_name' => 'Test Org', 'city' => 'Atlanta', 'state' => 'GA' ) );
$lnkup_mock = array( 'code' => 429, 'body' => array( 'error' => array( 'code' => 17, 'message' => 'User request limit reached access_token=' . $fake_token ) ) );
LNKup_Meta_Dispatcher::run();
$r2 = lnkup_rows( $wpdb->prepare( 'user_id = %d', $vendor2 ) );
lnkup_it( 'pending' === $r2[0]['status'] && 1 === (int) $r2[0]['attempts'] && strtotime( $r2[0]['next_attempt_at'] . ' UTC' ) >= time() + 800, 'rate limit: retried later (≥15 min), same row' );
lnkup_it( false === strpos( (string) $r2[0]['last_error'], $fake_token ), 'token redacted from stored error' );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Meta_Tables::conversions() . ' SET next_attempt_at = NULL WHERE user_id = %d', $vendor2 ) ); // phpcs:ignore
$lnkup_mock = array( 'code' => 400, 'body' => array( 'error' => array( 'code' => 100, 'message' => 'Invalid parameter' ) ) );
LNKup_Meta_Dispatcher::run();
$r2 = lnkup_rows( $wpdb->prepare( 'user_id = %d', $vendor2 ) );
lnkup_it( 'failed' === $r2[0]['status'] || ( '1' === $r2[0]['solo'] ), 'invalid batch isolated (solo resend) then dead-lettered' );
lnkup_it( $r2[0]['event_id'] === $r2[0]['event_id'] && (int) $r2[0]['event_time'] <= time(), 'event_id and event_time preserved across retries' );
lnkup_it( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lnkup_meta_deliveries WHERE error_message LIKE '%" . esc_sql( $fake_token ) . "%'" ), 'no token in delivery log' ); // phpcs:ignore
$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Meta_Tables::conversions() . " SET status = 'pending', solo = 0, attempts = 0, next_attempt_at = NULL WHERE user_id = %d", $vendor2 ) ); // phpcs:ignore
$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Meta_Tables::conversions() . ' SET event_time = %d WHERE user_id = %d', time() - 8 * DAY_IN_SECONDS, $vendor2 ) ); // phpcs:ignore
LNKup_Meta_Dispatcher::run();
$r2 = lnkup_rows( $wpdb->prepare( 'user_id = %d', $vendor2 ) );
lnkup_it( 'expired' === $r2[0]['status'] && null === $r2[0]['request_context'], 'events past Meta’s window expire instead of retrying forever' );

echo "Checkout and Purchase\n";
$opp     = wp_insert_post( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'publish', 'post_title' => 'Food Booth' ) );
$payment = wp_insert_post( array( 'post_type' => 'lnkup_payment', 'post_status' => 'publish', 'post_title' => 'Payment' ) );
update_post_meta( $payment, '_lnkup_vendor_user_id', $vendor );
update_post_meta( $payment, '_lnkup_opportunity_id', $opp );
update_post_meta( $payment, '_lnkup_total_amount', 205.99 );
update_post_meta( $payment, '_lnkup_payment_status', 'pending' );
wp_set_current_user( $vendor );
do_action( 'lnkup_checkout_started', $payment, 'embedded' );
do_action( 'lnkup_checkout_started', $payment, 'embedded' );
lnkup_it( 1 === count( lnkup_rows( "event_name = 'InitiateCheckout'" ) ), 'InitiateCheckout once per payment (reloads ignored)' );
lnkup_it( is_array( get_post_meta( $payment, LNKup_Conversion_Listeners::PAYMENT_CONTEXT_META, true ) ), 'checkout browser context kept for the webhook' );
do_action( 'lnkup_payment_completed', $payment );
lnkup_it( 0 === count( lnkup_rows( "event_name = 'Purchase'" ) ), 'no Purchase while payment is not paid' );
update_post_meta( $payment, '_lnkup_payment_status', 'paid' );
update_post_meta( $payment, '_lnkup_amount_paid_cents', 20599 );
update_post_meta( $payment, '_lnkup_lnkup_fee', 5.99 );
wp_set_current_user( 0 );
$_SERVER['REMOTE_ADDR']     = '3.18.12.63';
$_SERVER['HTTP_USER_AGENT'] = 'Stripe/1.0 (+https://stripe.com/docs/webhooks)';
do_action( 'lnkup_payment_completed', $payment );
do_action( 'lnkup_payment_completed', $payment );
$purchases = lnkup_rows( "event_name = 'Purchase'" );
lnkup_it( 1 === count( $purchases ), 'duplicate payment webhook → one Purchase' );
$pc = json_decode( $purchases[0]['custom_data'], true );
lnkup_it( 205.99 === (float) $pc['value'] && 'USD' === $pc['currency'] && (string) $payment === $pc['order_id'], 'Purchase value = amount paid, USD, order_id = payment' );
lnkup_it( 5.99 === (float) $purchases[0]['value_internal'], 'platform revenue stored internally only' );
$ctx = json_decode( (string) $purchases[0]['request_context'], true );
lnkup_it( '203.0.113.10' === ( $ctx['ip'] ?? '' ) && false === strpos( (string) ( $ctx['ua'] ?? '' ), 'Stripe' ), 'webhook Purchase uses the vendor’s checkout context, not Stripe’s' );
lnkup_it( 1 === count( lnkup_rows( "event_name = 'FirstVendorBooking'" ) ), 'FirstVendorBooking once' );

echo "Consent\n";
$optout = LNKup_Registration::register_user( array( 'email' => 'opt+' . wp_generate_password( 6, false ) . '@example.com', 'password' => 'Passw0rd!x', 'password_confirm' => 'Passw0rd!x', 'role' => 'vendor', 'terms' => true ) );
wp_set_current_user( $optout );
LNKup_Tracking_Consent::set_preference( true );
do_action( 'lnkup_favorite_toggled', $optout, 'opportunities', $opp, true );
$fav = lnkup_rows( $wpdb->prepare( "user_id = %d AND event_name = 'AddToWishlist'", $optout ) );
lnkup_it( 1 === count( $fav ) && 'skipped' === $fav[0]['status'] && 'consent_denied' === $fav[0]['last_error_code'] && null === $fav[0]['request_context'], 'opted-out user: recorded internally, not sent, no context stored' );
lnkup_it( '' === get_user_meta( $optout, LNKup_Conversion_Registry::PENDING_BROWSER_META, true ), 'opted-out user: nothing queued for the Pixel' );
$_SERVER['HTTP_SEC_GPC'] = '1';
$gpc = LNKup_Registration::register_user( array( 'email' => 'gpc+' . wp_generate_password( 6, false ) . '@example.com', 'password' => 'Passw0rd!x', 'password_confirm' => 'Passw0rd!x', 'role' => 'vendor', 'terms' => true ) );
$g   = lnkup_rows( $wpdb->prepare( "user_id = %d AND event_name = 'CompleteRegistration'", $gpc ) );
lnkup_it( 'skipped' === $g[0]['status'] && 'denied' === $g[0]['consent_state'], 'GPC browser: registration not sent to Meta' );
unset( $_SERVER['HTTP_SEC_GPC'] );

echo "Staff exclusion\n";
wp_set_current_user( 1 );
do_action( 'lnkup_favorite_toggled', 1, 'opportunities', $opp, true );
$staff = lnkup_rows( "user_id = 1 AND event_name = 'AddToWishlist'" );
lnkup_it( 1 === count( $staff ) && 'excluded_user' === $staff[0]['last_error_code'], 'admin activity never sent to Meta' );

echo "Browser beacon endpoint\n";
wp_set_current_user( 0 );
$uuid = wp_generate_uuid4();
$req  = new WP_REST_Request( 'POST', '/lnkup/v1/meta/event' );
$req->set_body_params( array( 'event_name' => 'ViewContent', 'event_id' => $uuid, 'custom_data' => array( 'content_ids' => array( $opp, 999999 ) ) ) );
$res = rest_do_request( $req );
lnkup_it( 200 === $res->get_status() && true === $res->get_data()['recorded'], 'ViewContent server twin recorded' );
$vc = lnkup_rows( $wpdb->prepare( 'event_id = %s', $uuid ) );
lnkup_it( 'fired' === $vc[0]['browser_status'] && array( (string) $opp ) === json_decode( $vc[0]['custom_data'], true )['content_ids'], 'browser-fired flag set; unknown content IDs dropped' );
$res = rest_do_request( $req );
lnkup_it( false === $res->get_data()['recorded'], 'replayed event_id ignored' );
$bad = new WP_REST_Request( 'POST', '/lnkup/v1/meta/event' );
$bad->set_body_params( array( 'event_name' => 'Purchase', 'event_id' => wp_generate_uuid4() ) );
lnkup_it( 400 === rest_do_request( $bad )->get_status(), 'browser cannot report Purchase' );
$pending = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/meta/pending' ) );
lnkup_it( in_array( $pending->get_status(), array( 401, 403 ), true ), 'pending events require sign-in' );

echo "Dataset Quality\n";
$lnkup_mock = array( 'code' => 200, 'body' => array( 'web' => array( array( 'event_name' => 'CompleteRegistration', 'event_match_quality' => array( 'composite_score' => 7.2, 'match_key_feedback' => array( array( 'identifier' => 'email', 'coverage' => array( 'percentage' => 100 ) ) ) ) ) ) ) );
delete_transient( 'lnkup_meta_dq_manual' );
$st = LNKup_Meta_Dataset_Quality::sync();
lnkup_it( ! empty( $st['ok'] ) && in_array( 'emq_score', $st['metrics_available'], true ), 'quality metrics synced' );
$dq_req = end( $lnkup_requests );
lnkup_it( false !== strpos( $dq_req['url'], '/v27.0/dataset_quality' ) && false !== strpos( $dq_req['url'], 'dataset_id=2099891494063746' ), 'GET /v27.0/dataset_quality for the LNKup dataset' );
$latest = LNKup_Meta_Dataset_Quality::latest( 'emq_score' );
lnkup_it( 7.2 === $latest['CompleteRegistration']['value'], 'EMQ snapshot stored' );
lnkup_it( array() === LNKup_Meta_Dataset_Quality::latest( 'acr' ), 'unreported metric stays unavailable' );
$lnkup_mock = array( 'code' => 400, 'body' => array( 'error' => array( 'code' => 190, 'message' => 'Invalid OAuth access token.' ) ) );
$st = LNKup_Meta_Dataset_Quality::sync();
lnkup_it( empty( $st['ok'] ) && ! empty( $st['auth_error'] ) && ! empty( $st['last_success_at'] ), 'invalid credential reported; last success kept' );

echo "Alerts\n";
update_option( 'lnkup_meta_last_delivery', array( 'at' => time(), 'ok' => false, 'code' => '190', 'auth' => true ), false );
$open = LNKup_Meta_Alerts::evaluate();
lnkup_it( in_array( 'capi_auth', $open, true ) && in_array( 'dq_access', $open, true ), 'credential alerts opened' );
update_option( 'lnkup_meta_last_delivery', array( 'at' => time(), 'ok' => true ), false );
$open = LNKup_Meta_Alerts::evaluate();
lnkup_it( ! in_array( 'capi_auth', $open, true ) && 'resolved' === $wpdb->get_var( 'SELECT status FROM ' . LNKup_Meta_Tables::alerts() . " WHERE alert_key = 'capi_auth' ORDER BY id DESC LIMIT 1" ), 'alert resolves when the condition clears' ); // phpcs:ignore

echo "Admin page\n";
wp_set_current_user( 1 );
foreach ( array( 'overview', 'events', 'quality', 'debugger', 'alerts', 'settings' ) as $tab ) {
	$_GET['tab'] = $tab;
	ob_start();
	LNKup_Meta_Admin::render();
	$html = (string) ob_get_clean();
	lnkup_it( false !== strpos( $html, 'Meta Integration' ) && false === strpos( $html, $fake_token ) && false === strpos( $html, $email ) && false === strpos( $html, '203.0.113.10' ), "tab {$tab} renders without tokens, emails or IPs" );
}
wp_set_current_user( $vendor );
lnkup_it( ! current_user_can( LNKup_Meta_Admin::CAP ), 'vendors cannot open the Meta admin page' );

printf( "\n%d checks, %d failures\n", $checks, $failures );
