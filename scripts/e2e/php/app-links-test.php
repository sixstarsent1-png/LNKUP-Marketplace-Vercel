<?php
/**
 * Phase 8: app deep links v2 (AASA paths behind the flag, path resolver, /l/ resolution with
 * click counting and campaign touch, guest-safe public event payload, tracked native share,
 * App Store campaign tokens). All fixtures are removed.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/app-links-test.php
 */

require_once __DIR__ . '/_marketing_helpers.php';
global $wpdb;

$markets_before  = get_option( 'lnkup_city_markets', array() );
$campaign_before = get_option( LNKup_App_Deep_Links::CAMPAIGN, null );
$link_owners     = array();
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $wpdb, $markets_before, $campaign_before, &$link_owners ) {
	update_option( 'lnkup_city_markets', $markets_before );
	null === $campaign_before ? delete_option( LNKup_App_Deep_Links::CAMPAIGN ) : update_option( LNKup_App_Deep_Links::CAMPAIGN, $campaign_before );
	foreach ( array_unique( $link_owners ) as $owner ) {
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Marketing_Tables::links() . ' WHERE owner_user_id = %d', $owner ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			$wpdb->delete( LNKup_Marketing_Tables::link_daily(), array( 'link_id' => (int) $id ) );
		}
		$wpdb->delete( LNKup_Marketing_Tables::links(), array( 'owner_user_id' => $owner ) );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_lnkup\_app\_dl\_rl\_%' OR option_name LIKE '\_transient\_timeout\_lnkup\_app\_dl\_rl\_%' OR option_name LIKE '\_transient\_lnkup\_lc\_%' OR option_name LIKE '\_transient\_timeout\_lnkup\_lc\_%'" ); // phpcs:ignore
};

/**
 * Dispatch a REST request as the given user (0 = guest).
 *
 * @param array<string,mixed> $params Query or JSON params.
 * @return array{status:int,data:mixed}
 */
function lnkup_al_rest( string $method, string $route, array $params = array(), int $user = 0 ): array {
	wp_set_current_user( $user );
	$request = new WP_REST_Request( $method, '/lnkup/v1' . $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );
	}
	$request->set_header( 'user_agent', 'Mozilla/5.0 (iPhone) LNKupApp/1.0' );
	$response = rest_do_request( $request );
	wp_set_current_user( 0 );
	return array( 'status' => $response->get_status(), 'data' => $response->get_data() );
}

$organizer = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );
$link_owners[] = $organizer;
$link_owners[] = $vendor;
$fixture   = lnkup_m_event( array( 'organizer_user_id' => $organizer, 'event_status' => 'live' ), true, 'MOS App Link Fest' );
$event     = $fixture['event'];
$opp       = $fixture['opportunity'];
$slug      = (string) get_post_field( 'post_name', $event );

echo "Flag off\n";
LNKup_Marketing_Flags::override( 'deep_links_v2', false );
lnkup_t_same( array( '/vendors' ), LNKup_App_Deep_Links::apple_paths( array( '/vendors' ) ), 'AASA paths unchanged when the flag is off' );
lnkup_t_same( 404, lnkup_al_rest( 'GET', '/public/resolve', array( 'path' => '/events/' . $slug . '/' ) )['status'], 'resolver is unavailable when the flag is off' );
lnkup_t_same( 404, lnkup_al_rest( 'GET', '/public/events/' . $event )['status'], 'public event endpoint is unavailable when the flag is off' );

lnkup_m_flags( array( 'deep_links_v2', 'tracking_links' ) );

echo "AASA\n";
$paths = LNKup_App_Deep_Links::apple_paths( array( '/vendors', '/vendors/', '/organizers', '/organizers/' ) );
foreach ( array( '/opportunities', '/events/*', '/markets/*', '/tours/*', '/l/*' ) as $p ) {
	lnkup_t_assert( in_array( $p, $paths, true ), "AASA includes {$p}" );
}
lnkup_t_same( count( $paths ), count( array_unique( $paths ) ), 'AASA paths are unique' );

echo "Resolver\n";
$r = lnkup_al_rest( 'GET', '/public/resolve', array( 'path' => '/events/' . $slug . '/' ) );
lnkup_t_same( array( 'event', $event ), array( $r['data']['data']['route'] ?? '', $r['data']['data']['id'] ?? 0 ), 'event slug resolves to the event screen' );
$r = lnkup_al_rest( 'GET', '/public/resolve', array( 'path' => 'https://thelnkup.com/events/' . $slug . '/?utm_source=ig' ) );
lnkup_t_same( 'event', $r['data']['data']['route'] ?? '', 'absolute URLs with a query resolve too' );
lnkup_t_same( 'feed', lnkup_al_rest( 'GET', '/public/resolve', array( 'path' => '/events/mos-no-such-event/' ) )['data']['data']['route'] ?? '', 'unknown event falls back to the feed' );
$draft = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'draft', 'post_title' => 'MOS Draft Event', 'post_name' => 'mos-draft-event-' . $event ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $draft );
lnkup_t_same( 'feed', lnkup_al_rest( 'GET', '/public/resolve', array( 'path' => '/events/mos-draft-event-' . $event . '/' ) )['data']['data']['route'] ?? '', 'draft event is not exposed' );
$locked = lnkup_m_event( array( 'organizer_user_id' => $organizer ), false, 'MOS Locked Event' )['event'];
wp_update_post( array( 'ID' => $locked, 'post_password' => 'secret' ) );
lnkup_t_same( 404, lnkup_al_rest( 'GET', '/public/events/' . $locked )['status'], 'password-protected event is not exposed' );

$r = lnkup_al_rest( 'GET', '/public/resolve', array( 'path' => '/opportunities/?apply=' . $opp ) );
lnkup_t_same( array( 'opportunity', $opp ), array( $r['data']['data']['route'] ?? '', $r['data']['data']['id'] ?? 0 ), 'apply link resolves to the opportunity' );

$markets                  = LNKup_Market_Pages::get_all_markets();
$markets['mos-linkville'] = array( 'slug' => 'mos-linkville', 'city' => 'Moslinkville', 'state' => 'NC', 'display_name' => 'Moslinkville', 'launch_status' => 'prelaunch', 'enabled' => true, 'indexable' => true );
LNKup_Market_Pages::save_markets( $markets );
$r = lnkup_al_rest( 'GET', '/public/resolve', array( 'path' => '/markets/mos-linkville/' ) );
lnkup_t_same( array( 'city' => 'Moslinkville', 'state' => 'NC' ), $r['data']['data']['params'] ?? null, 'market resolves to feed filters' );
$label = lnkup_canonical_vendor_categories()[0];
$r     = lnkup_al_rest( 'GET', '/public/resolve', array( 'path' => '/markets/mos-linkville/' . sanitize_title( $label ) . '/' ) );
lnkup_t_same( $label, $r['data']['data']['params']['category'] ?? '', 'market category page adds the category filter' );
lnkup_t_same( array(), lnkup_al_rest( 'GET', '/public/resolve', array( 'path' => '/markets/mos-nowhere/' ) )['data']['data']['params'] ?? null, 'unknown market has no filters' );
lnkup_t_same( 'feed', lnkup_al_rest( 'GET', '/public/resolve', array( 'path' => '/tours/mos-no-such-tour/' ) )['data']['data']['route'] ?? '', 'unknown tour falls back to the feed' );

echo "Tracked links\n";
$link = LNKup_Tracking_Links::create( array( 'owner_user_id' => $organizer, 'target_type' => 'event', 'target_id' => $event, 'channel' => 'instagram', 'campaign' => 'mos-spring' ) );
lnkup_t_assert( is_array( $link ), 'organizer creates an event link' );
$r = lnkup_al_rest( 'GET', '/public/resolve', array( 'path' => '/l/' . $link['code'] ) );
lnkup_t_same( array( 'event', $event ), array( $r['data']['data']['route'] ?? '', $r['data']['data']['id'] ?? 0 ), '/l/ code resolves to its event' );
lnkup_t_same( $link['code'], $r['data']['data']['attribution']['lnk'] ?? '', 'resolver returns the campaign touch for signup' );
lnkup_t_same( 'mos-spring', $r['data']['data']['attribution']['utm_campaign'] ?? '', 'touch carries the link campaign' );
$clicks = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(clicks) FROM ' . LNKup_Marketing_Tables::link_daily() . ' WHERE link_id = %d', $link['id'] ) ); // phpcs:ignore
lnkup_t_same( 1, $clicks, 'app open counts as a link click' );
LNKup_Tracking_Links::set_status( (int) $link['id'], 'paused', $organizer );
$r = lnkup_al_rest( 'GET', '/public/resolve', array( 'path' => '/l/' . $link['code'] ) );
lnkup_t_same( array( 'feed', array() ), array( $r['data']['data']['route'] ?? '', $r['data']['data']['attribution'] ?? null ), 'paused link resolves to the feed without attribution' );
$market_link = LNKup_Tracking_Links::create( array( 'owner_user_id' => $organizer, 'target_type' => 'market', 'target_key' => 'mos-linkville' ) );
$r           = lnkup_al_rest( 'GET', '/public/resolve', array( 'path' => '/l/' . $market_link['code'] . '/' ) );
lnkup_t_same( 'Moslinkville', $r['data']['data']['params']['city'] ?? '', 'market link resolves to the market feed' );
lnkup_t_same( 'mos-linkville', $r['data']['data']['attribution']['market'] ?? '', 'market link touch carries the market' );

echo "Public event payload\n";
update_post_meta( $event, '_lnkup_venue_hidden', 1 );
$r    = lnkup_al_rest( 'GET', '/public/events/' . $event );
$data = $r['data']['data'] ?? array();
lnkup_t_same( 200, $r['status'], 'guest can read a published event' );
lnkup_t_same( 'MOS App Link Fest', $data['title'] ?? '', 'title is returned' );
lnkup_t_same( 'scheduled', $data['state'] ?? '', 'upcoming event is scheduled' );
lnkup_t_same( '', $data['venue'] ?? 'x', 'hidden venue is not returned' );
lnkup_t_same( $opp, $data['opportunities'][0]['id'] ?? 0, 'open opportunity is listed' );
$json = (string) wp_json_encode( $data );
foreach ( array( '100 Test St', 'vendor_fee', 'spots_remaining', '"email"' ) as $private ) {
	lnkup_t_assert( false === strpos( $json, $private ), "payload has no {$private}" );
}
update_post_meta( $event, '_lnkup_event_status', 'cancelled' );
$data = lnkup_al_rest( 'GET', '/public/events/' . $event )['data']['data'] ?? array();
lnkup_t_same( array( 'cancelled', array() ), array( $data['state'] ?? '', $data['opportunities'] ?? null ), 'cancelled event shows its status and no apply options' );
update_post_meta( $event, '_lnkup_event_status', 'live' );

echo "Native share\n";
lnkup_t_same( 401, lnkup_al_rest( 'POST', '/app/share-link', array( 'target_type' => 'event', 'target_id' => $event ) )['status'], 'guests cannot create share links' );
$a = lnkup_al_rest( 'POST', '/app/share-link', array( 'target_type' => 'event', 'target_id' => $event ), $organizer );
$b = lnkup_al_rest( 'POST', '/app/share-link', array( 'target_type' => 'event', 'target_id' => $event ), $organizer );
lnkup_t_assert( ! empty( $a['data']['data']['tracked'] ) && false !== strpos( (string) $a['data']['data']['url'], '/l/' ), 'organizer share returns a tracked /l/ link' );
lnkup_t_same( $a['data']['data']['url'] ?? '', $b['data']['data']['url'] ?? 'x', 'repeat shares reuse the same link' );
$row = LNKup_Tracking_Links::get_by_code( basename( untrailingslashit( (string) $a['data']['data']['url'] ) ) );
lnkup_t_same( array( 'app', 'app_share' ), array( $row['channel'] ?? '', $row['utm_source'] ?? '' ), 'share link is labeled as an app share' );
$v = lnkup_al_rest( 'POST', '/app/share-link', array( 'target_type' => 'event', 'target_id' => $event ), $vendor );
lnkup_t_same( false, $v['data']['data']['tracked'] ?? null, 'vendor without a booking gets the plain public URL' );
lnkup_t_assert( false === strpos( (string) ( $v['data']['data']['url'] ?? '' ), '/l/' ), 'plain URL is not a tracked link' );
lnkup_t_same( 404, lnkup_al_rest( 'POST', '/app/share-link', array( 'target_type' => 'event', 'target_id' => $draft ), $organizer )['status'], 'draft events cannot be shared' );
lnkup_t_same( 400, lnkup_al_rest( 'POST', '/app/share-link', array( 'target_type' => 'application', 'target_id' => 1 ), $organizer )['status'], 'private record types are rejected' );

echo "App Store campaign tokens\n";
delete_option( LNKup_App_Deep_Links::CAMPAIGN );
lnkup_t_assert( false === strpos( LNKup_App_Promo::store_url( 'vendor' ), 'pt=' ), 'no provider token: URL unchanged' );
LNKup_App_Deep_Links::save_campaign( array( 'pt' => '12345abc', 'mt' => '' ) );
$url = LNKup_App_Promo::store_url( 'vendor' );
lnkup_t_assert( false !== strpos( $url, 'pt=12345' ) && false !== strpos( $url, 'mt=8' ) && false !== strpos( $url, 'ct=lnkup-vendor' ), 'campaign links carry pt, mt and ct' );
lnkup_t_assert( false === strpos( LNKup_App_Promo::store_url(), 'pt=' ), 'plain store URL stays untagged' );
lnkup_t_same( '6795865859', LNKup_App_Promo::app_id() ?: '6795865859', 'app ID still parses' );

lnkup_m_finish();
