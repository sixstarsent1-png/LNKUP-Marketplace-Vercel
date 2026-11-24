<?php
/**
 * Phase 3: attribution params, tracking links, analytics taxonomy + retention, Lead conversion,
 * ad platforms (gating, payloads, dispatch with HTTP intercepted), revenue attribution.
 *
 * No request leaves this machine: pre_http_request answers every outbound call.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/attribution-test.php
 */

require_once __DIR__ . '/_marketing_helpers.php';
lnkup_m_flags( array( 'seo_index_policy', 'tracking_links' ) );
global $wpdb;

$sent = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$sent ) {
		$sent[] = array( 'url' => $url, 'args' => $args );
		return array( 'headers' => array(), 'body' => '{"code":0}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
	},
	10,
	3
);

echo "Attribution params\n";
$t = LNKup_Acquisition_Attribution::sanitize_touch(
	array(
		'utm_source' => 'instagram',
		'gclid'      => 'Cj0K<script>Qw',
		'ttclid'     => 'E.C.P.abc',
		'ref'        => 'CONN-42!!',
		'lnk'        => 'ab12cd',
		'utm_id'     => 'cmp_9',
		'landing'    => '/markets/charlotte/food/',
		'device'     => 'mobile',
		'evil'       => 'x',
	)
);
lnkup_t_same( 'Cj0KscriptQw', $t['gclid'] ?? '', 'gclid stripped to safe characters' );
lnkup_t_same( 'CONN-42', $t['ref'] ?? '', 'referral code sanitized' );
lnkup_t_same( 'charlotte', $t['market'] ?? '', 'acquisition market derived from /markets/ landing' );
lnkup_t_same( 'mobile', $t['device'] ?? '', 'device class kept' );
lnkup_t_assert( ! isset( $t['evil'] ), 'unknown keys dropped' );
lnkup_t_same( '', LNKup_Acquisition_Attribution::sanitize_touch( array( 'device' => 'fridge' ) )['device'] ?? '', 'unknown device dropped' );
lnkup_t_assert( ! LNKup_Acquisition_Attribution::is_campaign( LNKup_Acquisition_Attribution::sanitize_touch( array( 'market' => 'charlotte', 'device' => 'desktop' ) ) ), 'market/device alone is not a campaign (internal links never overwrite last touch)' );
lnkup_t_assert( LNKup_Acquisition_Attribution::is_campaign( array( 'ttclid' => 'x' ) ), 'ttclid visit is a campaign touch' );
$vendor = lnkup_t_user( 'lnkup_vendor' );
update_user_meta( $vendor, LNKup_Acquisition_Attribution::META_LAST, $t );
$snap = LNKup_Acquisition_Attribution::snapshot( $vendor );
lnkup_t_assert( 1 === ( $snap['lt']['gclid'] ?? null ) && 1 === ( $snap['lt']['ttclid'] ?? null ), 'conversion snapshot keeps click IDs as flags only' );
lnkup_t_assert( 'ab12cd' === ( $snap['lt']['lnk'] ?? '' ) && 'charlotte' === ( $snap['lt']['market'] ?? '' ), 'snapshot keeps link code and market' );
LNKup_Acquisition_Attribution::on_registered( $vendor, 'lnkup_vendor', array( 'attribution' => array( 'utm_source' => 'app_store', 'device' => 'app', 'lnk' => 'appl1nk' ), 'channel' => 'app' ) );
$reg = get_user_meta( $vendor, LNKup_Acquisition_Attribution::META_REG, true );
lnkup_t_assert( is_array( $reg ) && 'app' === ( $reg['device'] ?? '' ) && 'appl1nk' === ( $reg['lnk'] ?? '' ), 'app registration payload stored (device + link code)' );

echo "Tracking links\n";
$org   = lnkup_t_user( 'lnkup_organizer' );
$other = lnkup_t_user( 'lnkup_organizer' );
$ev    = lnkup_m_event( array( 'organizer_user_id' => $org ) );
$theirs = lnkup_m_event( array( 'organizer_user_id' => $other ) );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $wpdb, $org, $other ) {
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Marketing_Tables::links() . ' WHERE owner_user_id IN (%d,%d)', $org, $other ) ); // phpcs:ignore
	foreach ( $ids as $id ) {
		$wpdb->delete( LNKup_Marketing_Tables::link_daily(), array( 'link_id' => (int) $id ) );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Marketing_Tables::links() . ' WHERE owner_user_id IN (%d,%d)', $org, $other ) ); // phpcs:ignore
};
$link = LNKup_Tracking_Links::create( array( 'owner_user_id' => $org, 'owner_type' => 'organizer', 'target_type' => 'event', 'target_id' => $ev['event'], 'channel' => 'instagram', 'campaign' => 'spring-call' ) );
lnkup_t_assert( is_array( $link ) && preg_match( '/^[a-z0-9]{6,16}$/', (string) $link['code'] ), 'organizer creates a link to own event' );
lnkup_t_assert( is_wp_error( LNKup_Tracking_Links::create( array( 'owner_user_id' => $org, 'target_type' => 'event', 'target_id' => $theirs['event'] ) ) ), 'cannot link to another organizer\'s event' );
lnkup_t_assert( is_wp_error( LNKup_Tracking_Links::create( array( 'owner_user_id' => $org, 'target_type' => 'url', 'target_key' => 'https://evil.example' ) ) ), 'arbitrary URL targets impossible' );
lnkup_t_assert( is_wp_error( LNKup_Tracking_Links::create( array( 'owner_user_id' => $org, 'target_type' => 'market', 'target_key' => 'no-such-market' ) ) ), 'unknown market rejected' );
$mlink = LNKup_Tracking_Links::create( array( 'owner_user_id' => $org, 'target_type' => 'market', 'target_key' => 'charlotte', 'channel' => 'qr' ) );
lnkup_t_assert( is_array( $mlink ), 'link to public market page allowed' );
$home  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$dest  = LNKup_Tracking_Links::target_url( $link );
lnkup_t_assert( $home === wp_parse_url( $dest, PHP_URL_HOST ) && false !== strpos( $dest, '/events/' ), 'redirect target is the internal event URL' );
wp_update_post( array( 'ID' => $ev['event'], 'post_status' => 'draft' ) );
$fallback = LNKup_Tracking_Links::target_url( $link );
lnkup_t_assert( false === strpos( $fallback, '/events/' ) && $home === wp_parse_url( $fallback, PHP_URL_HOST ), 'unpublished target falls back to the public feed (still internal)' );
wp_update_post( array( 'ID' => $ev['event'], 'post_status' => 'publish' ) );

$ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';
LNKup_Tracking_Links::count_click( $link, $ua, '198.51.100.9' );
LNKup_Tracking_Links::count_click( $link, $ua, '198.51.100.9' );
LNKup_Tracking_Links::count_click( $link, $ua, '198.51.100.10' );
LNKup_Tracking_Links::count_click( $link, 'facebookexternalhit/1.1', '198.51.100.11' );
LNKup_Tracking_Links::count_click( $link, '', '198.51.100.12' );
$day = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Marketing_Tables::link_daily() . ' WHERE link_id = %d', (int) $link['id'] ), ARRAY_A ); // phpcs:ignore
lnkup_t_same( 3, (int) $day['clicks'], 'human clicks counted' );
lnkup_t_same( 2, (int) $day['unique_clicks'], 'repeat click from same browser de-duplicated' );
lnkup_t_same( 2, (int) $day['bot_clicks'], 'link preview bots and empty UAs counted as bots only' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_lnkup\_lc\_%' OR option_name LIKE '\_transient\_timeout\_lnkup\_lc\_%'" ); // phpcs:ignore
$touch = LNKup_Tracking_Links::touch_for( $link );
lnkup_t_assert( $link['code'] === $touch['lnk'] && 'instagram' === $touch['utm_source'] && 'tracked_link' === $touch['utm_medium'] && 'spring-call' === $touch['utm_campaign'], 'click touch: link code + channel/campaign defaults' );
LNKup_Tracking_Links::on_conversion( array( 'event_name' => 'CompleteRegistration', 'attribution_json' => wp_json_encode( array( 'lt' => array( 'lnk' => $link['code'] ) ) ) ) );
LNKup_Tracking_Links::on_conversion( array( 'event_name' => 'SubmitApplication', 'attribution_json' => wp_json_encode( array( 'ft' => array( 'lnk' => $link['code'] ) ) ) ) );
LNKup_Tracking_Links::on_conversion( array( 'event_name' => 'PageView', 'attribution_json' => wp_json_encode( array( 'lt' => array( 'lnk' => $link['code'] ) ) ) ) );
$list = LNKup_Tracking_Links::list_for_owner( $org );
$row  = null;
foreach ( $list as $item ) {
	if ( (int) $item['id'] === (int) $link['id'] ) {
		$row = $item;
	}
}
lnkup_t_assert( $row && 1 === $row['signups'] && 1 === $row['applications'] && 0 === $row['bookings'], 'signup + application credited to the link (first or last touch)' );
lnkup_t_assert( is_wp_error( LNKup_Tracking_Links::set_status( (int) $link['id'], 'paused', $other ) ), 'another organizer cannot change the link' );
lnkup_t_same( 'paused', LNKup_Tracking_Links::set_status( (int) $link['id'], 'paused', $org )['status'] ?? '', 'owner pauses link' );
lnkup_t_same( array(), LNKup_Tracking_Links::list_for_owner( $other ), 'owners only list their own links' );

echo "Analytics taxonomy\n";
$map = LNKup_Analytics_Taxonomy::map();
foreach ( array( 'opportunity_viewed', 'opportunity_shared', 'opportunity_saved', 'vendor_signup_completed', 'vendor_profile_completed', 'application_started', 'application_submitted', 'application_approved', 'booking_confirmed', 'vendor_payment_succeeded', 'organizer_signup_completed', 'organizer_event_created', 'organizer_event_published', 'organizer_first_booking', 'external_event_interest', 'external_event_claimed', 'referral_conversion' ) as $spec ) {
	if ( ! isset( $map[ $spec ] ) ) {
		lnkup_t_assert( false, "spec event {$spec} mapped" );
	}
}
lnkup_t_assert( true, 'all 17 specification events are mapped to existing data' );
wp_set_current_user( $vendor );
$eid = LNKup_Analytics_Taxonomy::emit( 'mos_contract_test', array( 'k' => 'v' ), $vendor );
$raw = $wpdb->get_row( 'SELECT * FROM ' . LNKup_Marketplace_Analytics::table_name() . " WHERE event_type = 'mos_contract_test' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore
$data = json_decode( (string) $raw['event_data'], true );
lnkup_t_assert( 1 === $data['_v'] && $eid === $data['event_id'] && ! empty( $data['env'] ), 'emit adds versioned contract (_v, event_id, env)' );
LNKup_Analytics_Taxonomy::emit( 'mos_contract_anon', array(), 0, 0, true );
lnkup_t_same( '0', (string) $wpdb->get_var( 'SELECT user_id FROM ' . LNKup_Marketplace_Analytics::table_name() . " WHERE event_type = 'mos_contract_anon' ORDER BY id DESC LIMIT 1" ), 'anonymous signal stores no user even when signed in' ); // phpcs:ignore
wp_set_current_user( 0 );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $wpdb ) {
	$wpdb->query( 'DELETE FROM ' . LNKup_Marketplace_Analytics::table_name() . " WHERE event_type IN ('mos_contract_test','mos_contract_anon','mos_retention_evt')" ); // phpcs:ignore
	$wpdb->query( 'DELETE FROM ' . LNKup_Marketing_Tables::analytics_daily() . " WHERE event_type = 'mos_retention_evt'" ); // phpcs:ignore
};

echo "Lead conversion\n";
lnkup_t_assert( null !== LNKup_Conversion_Registry::get( 'Lead' ), 'Lead registered in the conversion registry' );
LNKup_Marketplace_Analytics::track( 'external_event_interest_submitted', array( 'external_event_id' => 987001 ), $vendor );
$lead = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Meta_Tables::conversions() . " WHERE event_name = 'Lead' AND user_id = %d AND entity_type = 'external_event' AND entity_id = 987001", $vendor ), ARRAY_A ); // phpcs:ignore
lnkup_t_assert( is_array( $lead ), 'external event interest records Lead' );
LNKup_Marketplace_Analytics::track( 'external_event_interest_submitted', array( 'external_event_id' => 987001 ), $vendor );
lnkup_t_same( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Meta_Tables::conversions() . " WHERE event_name = 'Lead' AND user_id = %d AND entity_id = 987001", $vendor ) ), 'Lead de-duplicated per vendor and event' ); // phpcs:ignore
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $wpdb, $vendor ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Meta_Tables::conversions() . ' WHERE user_id = %d', $vendor ) ); // phpcs:ignore
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Marketplace_Analytics::table_name() . ' WHERE user_id = %d', $vendor ) ); // phpcs:ignore
};

echo "Retention\n";
$watermark = get_option( 'lnkup_analytics_rollup_until', null );
$settings  = get_option( 'lnkup_settings', array() );
for ( $i = 0; $i < 3; $i++ ) {
	$wpdb->insert( LNKup_Marketplace_Analytics::table_name(), array( 'event_type' => 'mos_retention_evt', 'event_data' => '{}', 'user_id' => 0, 'opportunity_id' => 0, 'created_at' => '2019-01-15 10:0' . $i . ':00' ) ); // phpcs:ignore
}
update_option( 'lnkup_analytics_rollup_until', '2019-01-14', false );
$r = LNKup_Analytics_Taxonomy::run_retention( '2019-01-16' );
$rolled = (int) $wpdb->get_var( 'SELECT events FROM ' . LNKup_Marketing_Tables::analytics_daily() . " WHERE event_type = 'mos_retention_evt' AND day = '2019-01-15'" ); // phpcs:ignore
lnkup_t_assert( 1 === $r['days'] && 3 === $rolled && 0 === $r['purged'], 'old day rolled up; raw kept while purge is off' );
lnkup_t_same( 3, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Marketplace_Analytics::table_name() . " WHERE event_type = 'mos_retention_evt'" ), 'raw rows untouched without purge' ); // phpcs:ignore
LNKup_Analytics_Taxonomy::run_retention( '2019-01-16' );
lnkup_t_same( 3, (int) $wpdb->get_var( 'SELECT events FROM ' . LNKup_Marketing_Tables::analytics_daily() . " WHERE event_type = 'mos_retention_evt'" ), 're-run is idempotent' ); // phpcs:ignore
update_option( 'lnkup_settings', array_merge( (array) $settings, array( 'analytics_retention_purge' => 1 ) ) );
update_option( 'lnkup_analytics_rollup_until', '2019-01-14', false );
$r2 = LNKup_Analytics_Taxonomy::run_retention( '2019-01-16' );
lnkup_t_assert( 3 === $r2['purged'] && 3 === (int) $wpdb->get_var( 'SELECT events FROM ' . LNKup_Marketing_Tables::analytics_daily() . " WHERE event_type = 'mos_retention_evt'" ), 'purge deletes raw only after the rollup is written' ); // phpcs:ignore
update_option( 'lnkup_settings', $settings );
null === $watermark ? delete_option( 'lnkup_analytics_rollup_until' ) : update_option( 'lnkup_analytics_rollup_until', $watermark, false );

echo "Ad platforms\n";
lnkup_t_assert( ! LNKup_Ad_Platforms::client_active( 'ga4' ) && ! LNKup_Ad_Platforms::client_active( 'tiktok' ), 'off by default (flags off, non-production host)' );
$ads_before = get_option( LNKup_Ad_Platforms::OPTION, null );
update_option( LNKup_Ad_Platforms::OPTION, array( 'ga4_measurement_id' => 'G-TEST1234', 'tiktok_pixel_id' => 'CTESTPIXEL1234', 'tiktok_test_event_code' => 'TEST123', 'gads_conversion_id' => 'AW-1234567', 'gads_labels' => array( 'Purchase' => 'abcdLabel' ) ) );
LNKup_Marketing_Flags::override( 'ga4_enabled', true );
LNKup_Marketing_Flags::override( 'tiktok_enabled', true );
lnkup_t_assert( ! LNKup_Ad_Platforms::client_active( 'ga4' ), 'flag on but non-production host and no test mode -> still off' );
update_option( LNKup_Ad_Platforms::OPTION, array_merge( (array) get_option( LNKup_Ad_Platforms::OPTION ), array( 'test_mode' => true ) ) );
putenv( 'GA4_API_SECRET=unit-test-secret' );
putenv( 'TIKTOK_ACCESS_TOKEN=unit-test-token' );
lnkup_t_assert( LNKup_Ad_Platforms::client_active( 'ga4' ) && LNKup_Ad_Platforms::server_active( 'ga4' ) && LNKup_Ad_Platforms::server_active( 'tiktok' ), 'test mode + IDs + secrets -> active' );
$status = wp_json_encode( LNKup_Ad_Platforms::status() );
lnkup_t_assert( false === strpos( $status, 'unit-test-secret' ) && false === strpos( $status, 'unit-test-token' ), 'status never exposes secrets' );
$_COOKIE['_ga'] = 'GA1.1.111222333.1700000000';
update_user_meta( $vendor, '_lnkup_acq_last_touch', array( 'ttclid' => 'E.C.P.click', 'utm_source' => 'tiktok' ) );
$conv = array( 'event_name' => 'Purchase', 'event_id' => wp_generate_uuid4(), 'user_id' => $vendor, 'role' => 'vendor', 'consent_state' => 'granted', 'source_platform' => 'server', 'event_time' => time(), 'event_source_url' => home_url( '/vendor-dashboard/' ), 'custom_data' => wp_json_encode( array( 'value' => 162.5, 'currency' => 'USD', 'content_name' => 'Secret booth name' ) ) );
LNKup_Ad_Platforms::on_conversion( $conv, LNKup_Conversion_Registry::get( 'Purchase' ) );
$out = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Marketing_Tables::ads_outbox() . ' WHERE event_id = %s', $conv['event_id'] ), ARRAY_A ); // phpcs:ignore
lnkup_t_same( 2, count( $out ), 'Purchase queued for GA4 and TikTok server events' );
$all = wp_json_encode( $out );
$user = get_userdata( $vendor );
lnkup_t_assert( false === strpos( $all, (string) $user->user_email ) && false === strpos( $all, 'Secret booth name' ) && false === strpos( $all, $user->display_name ), 'payloads carry no email, name or listing content' );
lnkup_t_assert( false !== strpos( $all, $conv['event_id'] ) && false !== strpos( $all, '111222333.1700000000' ) && false !== strpos( $all, 'E.C.P.click' ), 'payloads reuse event_id, GA client ID and ttclid' );
$denied = array_merge( $conv, array( 'event_id' => wp_generate_uuid4(), 'consent_state' => 'denied' ) );
LNKup_Ad_Platforms::on_conversion( $denied, LNKup_Conversion_Registry::get( 'Purchase' ) );
lnkup_t_same( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Marketing_Tables::ads_outbox() . ' WHERE event_id = %s', $denied['event_id'] ) ), 'refused consent -> nothing queued' ); // phpcs:ignore
$staff = lnkup_t_user( 'administrator' );
$staffc = array_merge( $conv, array( 'event_id' => wp_generate_uuid4(), 'user_id' => $staff ) );
LNKup_Ad_Platforms::on_conversion( $staffc, LNKup_Conversion_Registry::get( 'Purchase' ) );
lnkup_t_same( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Marketing_Tables::ads_outbox() . ' WHERE event_id = %s', $staffc['event_id'] ) ), 'staff accounts excluded' ); // phpcs:ignore
$reg = array_merge( $conv, array( 'event_name' => 'CompleteRegistration', 'event_id' => wp_generate_uuid4(), 'custom_data' => '{}' ) );
LNKup_Ad_Platforms::on_conversion( $reg, LNKup_Conversion_Registry::get( 'CompleteRegistration' ) );
lnkup_t_same( array( 'tiktok' ), $wpdb->get_col( $wpdb->prepare( 'SELECT platform FROM ' . LNKup_Marketing_Tables::ads_outbox() . ' WHERE event_id = %s', $reg['event_id'] ) ), 'browser-fired events go to GA4 from the browser only (no double count)' ); // phpcs:ignore
$sent   = array();
$result = LNKup_Ad_Platforms::dispatch();
lnkup_t_assert( $result['sent'] >= 3 && 0 === $result['failed'], 'dispatch sends pending rows (HTTP intercepted)' );
$ga_call = null;
$tt_call = null;
foreach ( $sent as $call ) {
	if ( false !== strpos( $call['url'], 'google-analytics.com' ) ) {
		$ga_call = $call;
	}
	if ( false !== strpos( $call['url'], 'tiktok.com' ) ) {
		$tt_call = $call;
	}
}
lnkup_t_assert( $ga_call && false !== strpos( $ga_call['url'], '/debug/mp/collect' ), 'GA4 test mode uses the validation-only debug endpoint' );
lnkup_t_assert( $tt_call && 'unit-test-token' === ( $tt_call['args']['headers']['Access-Token'] ?? '' ) && false !== strpos( (string) $tt_call['args']['body'], 'TEST123' ), 'TikTok call carries token header and test event code' );
lnkup_t_assert( 0 === count( array_filter( $sent, static fn( $c ) => false === strpos( $c['url'], 'google-analytics.com' ) && false === strpos( $c['url'], 'tiktok.com' ) ) ), 'no other outbound requests' );
wp_set_current_user( $vendor );
update_user_meta( $vendor, LNKup_Ad_Platforms::PENDING_META, array( array( 'name' => 'Lead', 'event_id' => 'evt-browser-1', 'params' => array( 'marketplace_role' => 'vendor' ), 'at' => time() ) ) );
ob_start();
LNKup_Ad_Platforms::render_tags();
$tags = (string) ob_get_clean();
lnkup_t_assert( false !== strpos( $tags, 'G-TEST1234' ) && false !== strpos( $tags, 'evt-browser-1' ) && false !== strpos( $tags, 'CTESTPIXEL1234' ), 'tags render with queued browser event id' );
lnkup_t_same( '', (string) get_user_meta( $vendor, LNKup_Ad_Platforms::PENDING_META, true ), 'pending browser events consumed once' );
$_SERVER['HTTP_SEC_GPC'] = '1';
ob_start();
LNKup_Ad_Platforms::render_tags();
lnkup_t_same( '', trim( (string) ob_get_clean() ), 'Global Privacy Control -> no tags' );
unset( $_SERVER['HTTP_SEC_GPC'] );
wp_set_current_user( 0 );
putenv( 'GA4_API_SECRET' );
putenv( 'TIKTOK_ACCESS_TOKEN' );
LNKup_Marketing_Flags::override( 'ga4_enabled', null );
LNKup_Marketing_Flags::override( 'tiktok_enabled', null );
null === $ads_before ? delete_option( LNKup_Ad_Platforms::OPTION ) : update_option( LNKup_Ad_Platforms::OPTION, $ads_before );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Marketing_Tables::ads_outbox() . ' WHERE event_id IN (%s,%s,%s,%s)', $conv['event_id'], $denied['event_id'], $staffc['event_id'], $reg['event_id'] ) ); // phpcs:ignore

echo "Revenue attribution\n";
$fees  = LNKup_Treasury_Tables::platform_fees_table();
$rw    = LNKup_Treasury_Tables::referral_rewards_table();
$spend = $wpdb->prefix . 'lnkup_org_growth_spend';
$p1    = 990000001;
$p2    = 990000002;
$wpdb->insert( $fees, array( 'payment_post_id' => $p1, 'gross_amount' => 500, 'lnkup_platform_fee' => 25, 'refunded_platform_fee' => 0, 'created_at' => '2019-06-10 12:00:00' ) ); // phpcs:ignore
$wpdb->insert( $fees, array( 'payment_post_id' => $p1, 'gross_amount' => 500, 'lnkup_platform_fee' => 25, 'refunded_platform_fee' => 0, 'created_at' => '2019-06-10 12:00:01' ) ); // phpcs:ignore
$wpdb->insert( $fees, array( 'payment_post_id' => $p2, 'gross_amount' => 200, 'lnkup_platform_fee' => 10, 'refunded_platform_fee' => 4, 'created_at' => '2019-06-11 12:00:00' ) ); // phpcs:ignore
$wpdb->insert( $rw, array( 'payment_id' => $p1, 'amount' => 5, 'reversed_amount' => 0, 'status' => 'available', 'event_type' => 'mos_test', 'created_at' => '2019-06-10 12:00:00' ) ); // phpcs:ignore
$wpdb->insert( $spend, array( 'spent_on' => '2019-06-05', 'channel' => 'mos_ig', 'campaign' => 'mos_c', 'amount_cents' => 1000, 'created_at' => '2019-06-05 00:00:00' ) ); // phpcs:ignore
update_user_meta( $vendor, LNKup_Acquisition_Attribution::META_LAST, array( 'utm_source' => 'mos_ig', 'utm_campaign' => 'mos_c' ) );
LNKup_Conversion_Registry::record( 'Purchase', array( 'user_id' => $vendor, 'entity_type' => 'payment', 'entity_id' => $p1, 'capture_request' => false, 'custom_data' => array( 'value' => 500, 'currency' => 'USD' ) ) );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $wpdb, $fees, $rw, $spend, $p1, $p2 ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$fees} WHERE payment_post_id IN (%d,%d)", $p1, $p2 ) ); // phpcs:ignore
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$rw} WHERE payment_id IN (%d,%d)", $p1, $p2 ) ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$spend} WHERE channel = 'mos_ig'" ); // phpcs:ignore
};
$rep = LNKup_Revenue_Attribution::report( '2019-06-01', '2019-06-30', 'utm_source' );
$by  = array();
foreach ( $rep['rows'] as $r ) {
	$by[ $r['key'] ] = $r;
}
lnkup_t_assert( isset( $by['mos_ig'] ) && 1 === $by['mos_ig']['bookings'], 'duplicate fee rows counted once per payment' );
lnkup_t_assert( 500.0 === (float) $by['mos_ig']['gmv'] && 25.0 === (float) $by['mos_ig']['platform_fee_net'], 'GMV and platform fee kept separate ($500 booth != $500 revenue)' );
lnkup_t_assert( 5.0 === (float) $by['mos_ig']['commissions'] && 20.0 === (float) $by['mos_ig']['net_revenue'], 'commissions deducted from net revenue' );
lnkup_t_assert( 10.0 === (float) $by['mos_ig']['spend'] && 2.5 === (float) $by['mos_ig']['platform_fee_roas'] && 50.0 === (float) $by['mos_ig']['gmv_roas'], 'spend joined by channel; platform-fee ROAS and GMV ROAS reported separately' );
lnkup_t_assert( isset( $by['Unattributed'] ) && 6.0 === (float) $by['Unattributed']['platform_fee_net'], 'payment without attribution reported as Unattributed, net of refunded fee' );
lnkup_t_same( 'mos_c', LNKup_Revenue_Attribution::report( '2019-06-01', '2019-06-30', 'utm_campaign' )['rows'][0]['key'], 'campaign dimension' );
$csv = LNKup_Revenue_Attribution::to_csv( array( 'rows' => array( array( 'key' => '=HYPERLINK("x")', 'bookings' => 1 ) ) ) );
lnkup_t_assert( false !== strpos( $csv, "'=HYPERLINK" ), 'CSV export neutralizes formula injection' );

lnkup_m_finish();
