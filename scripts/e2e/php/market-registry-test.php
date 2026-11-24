<?php
/**
 * Phase 2: market registry, computed status, alerts, demand, city+category/digest eligibility,
 * event page notices, follow tour, OG images.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/market-registry-test.php
 */

require_once __DIR__ . '/_marketing_helpers.php';
lnkup_m_flags( array( 'seo_index_policy', 'market_category_pages' ) );

$markets_before = get_option( 'lnkup_city_markets', array() );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $markets_before ) {
	update_option( 'lnkup_city_markets', $markets_before );
};
$markets                    = LNKup_Market_Pages::get_all_markets();
$markets['mos-testville']   = array(
	'slug'          => 'mos-testville',
	'city'          => 'Mostestville',
	'state'         => 'NC',
	'display_name'  => 'Mostestville',
	'launch_status' => 'prelaunch',
	'enabled'       => true,
	'indexable'     => true,
);
$markets['mos-nearby']      = array(
	'slug'          => 'mos-nearby',
	'city'          => 'Mosnearby',
	'state'         => 'NC',
	'display_name'  => 'Mosnearby',
	'launch_status' => 'prelaunch',
	'enabled'       => true,
	'indexable'     => true,
);
LNKup_Market_Pages::save_markets( $markets );

echo "Registry defaults\n";
$m = LNKup_Market_Pages::get_market_by_slug( 'mos-testville' );
lnkup_t_assert( is_array( $m ) && array_key_exists( 'lat', $m ) && null === $m['lat'] && array() === $m['nearby'] && '' === $m['content'], 'legacy market gets registry defaults' );
lnkup_t_same( 'prelaunch', $m['status'], 'no events and prelaunch launch status -> prelaunch' );
lnkup_t_same( 0, LNKup_Market_Registry::health( $m )['open_events'], 'health counts zero open events' );

echo "Computed status\n";
$e1 = lnkup_m_event( array( 'city' => 'Mostestville' ) );
LNKup_Market_Registry::flush();
lnkup_t_same( 'emerging', LNKup_Market_Registry::status( LNKup_Market_Registry::with_defaults( $m ) ), 'one open event -> emerging' );
$e2 = lnkup_m_event( array( 'city' => 'Mostestville' ) );
$e3 = lnkup_m_event( array( 'city' => 'Mostestville' ) );
LNKup_Market_Registry::flush();
lnkup_t_same( 'active', LNKup_Market_Registry::status( LNKup_Market_Registry::with_defaults( $m ) ), 'three open events -> active' );
lnkup_t_same( 3, LNKup_Market_Registry::health( $m )['open_events'], 'health counts three open events' );
lnkup_t_same( 'prelaunch', LNKup_Market_Registry::status( array_merge( LNKup_Market_Registry::with_defaults( $m ), array( 'status_override' => 'prelaunch' ) ) ), 'admin override wins' );
lnkup_t_same( 'inactive', LNKup_Market_Registry::status( array_merge( LNKup_Market_Registry::with_defaults( $m ), array( 'enabled' => false ) ) ), 'disabled market -> inactive' );
lnkup_t_same( '', LNKup_Market_Registry::with_defaults( array( 'status_override' => 'bogus' ) )['status_override'], 'invalid override discarded' );
$past = lnkup_m_event( array( 'city' => 'Mostestville', 'event_date' => gmdate( 'Y-m-d', strtotime( '-3 days' ) ) ) );
LNKup_Market_Registry::flush();
lnkup_t_same( 3, LNKup_Market_Registry::health( $m )['open_events'], 'past events not counted' );

echo "Categories and eligibility\n";
foreach ( get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false ) ) as $term ) {
	if ( 'Food' === lnkup_canonical_category_label( $term->name ) ) {
		foreach ( array( $e1, $e2, $e3 ) as $fixture ) {
			wp_set_object_terms( $fixture['opportunity'], array( (int) $term->term_id ), 'lnkup_vendor_category' );
			LNKup_Marketplace_Index::sync_opportunity( $fixture['opportunity'] );
		}
		break;
	}
}
LNKup_Market_Registry::flush();
$cats = LNKup_Market_Registry::categories( $m );
lnkup_t_assert( is_array( $cats ), 'categories returns a list' );
$any  = $cats ? $cats[0] : null;
if ( $any ) {
	lnkup_t_assert( ! $any['eligible'], 'category not eligible until staff enable the pair' );
	$enabled                          = LNKup_Market_Pages::get_all_markets();
	$enabled['mos-testville']['category_pages'] = array( $any['slug'] );
	LNKup_Market_Pages::save_markets( $enabled );
	LNKup_Index_Policy::flush();
	$m2 = LNKup_Market_Pages::get_market_by_slug( 'mos-testville' );
	lnkup_t_same( 'food', $any['slug'], 'Food category counted from indexed slots' );
	lnkup_t_same( 3, $any['count'], 'category count is distinct open events' );
	lnkup_t_assert( LNKup_Market_Registry::category_eligible( $m2, 'food' ), 'enabled pair with 3 open events is eligible' );
	lnkup_t_assert( ! LNKup_Market_Registry::category_eligible( $m2, 'food', 2 ), 'below threshold not eligible' );
	$food_page = LNKup_Market_Discovery::build( $m2, 'food' );
	lnkup_t_assert( $food_page['eligible'] && 3 === count( $food_page['cards'] ), 'eligible category page lists its 3 events' );
	LNKup_Marketing_Flags::override( 'market_category_pages', false );
	lnkup_t_assert( ! LNKup_Market_Registry::category_eligible( $m2, $any['slug'] ), 'flag off -> never eligible' );
	LNKup_Marketing_Flags::override( 'market_category_pages', true );
} else {
	lnkup_t_skip( 'fixture slots have no category terms indexed' );
}
lnkup_t_same( null, LNKup_Market_Discovery::build( $m, 'not-a-category' ), 'unknown sub-page slug -> null (404)' );
$digest = LNKup_Market_Discovery::build( $m, 'this-week' );
lnkup_t_assert( is_array( $digest ) && 'digest' === $digest['type'], 'digest builds' );
foreach ( (array) $digest['cards'] as $card ) {
	lnkup_t_assert( false === strpos( (string) ( $card['title'] ?? '' ), get_the_title( $past['event'] ) ), 'digest excludes past events' );
}
$food = LNKup_Market_Discovery::build( $m, 'food' );
lnkup_t_assert( is_array( $food ) && 'category' === $food['type'] && 'Food' === $food['label'], 'canonical category sub-page builds' );
lnkup_t_assert( ! $food['eligible'], 'category page not eligible without staff opt-in' );

echo "Nearby\n";
$near = LNKup_Market_Registry::nearby( LNKup_Market_Registry::with_defaults( $m ) );
lnkup_t_assert( in_array( 'mos-nearby', wp_list_pluck( $near, 'slug' ), true ), 'same-state market listed as nearby without coordinates' );
lnkup_t_assert( ! in_array( 'mos-testville', wp_list_pluck( $near, 'slug' ), true ), 'market never lists itself' );

echo "Alerts\n";
$vendor = lnkup_t_user( 'lnkup_vendor' );
$a1     = LNKup_Market_Registry::create_alert( $vendor, 'mos-testville', 'food' );
lnkup_t_assert( is_array( $a1 ) && $a1['created'], 'alert creates a saved search' );
$a2 = LNKup_Market_Registry::create_alert( $vendor, 'mos-testville', 'food' );
lnkup_t_assert( is_array( $a2 ) && ! $a2['created'], 'duplicate alert reuses the saved search' );
$saved = LNKup_Saved_Searches::get_for_user( $vendor );
$row   = is_array( $saved ) ? reset( $saved ) : null;
lnkup_t_assert( $row && 'Mostestville' === ( $row['filters']['city'] ?? '' ) && in_array( 'Food', (array) ( $row['filters']['categories'] ?? array() ), true ), 'saved search filters city + category' );
lnkup_t_assert( is_wp_error( LNKup_Market_Registry::create_alert( $vendor, 'no-such-market' ) ), 'unknown market rejected' );

$req = new WP_REST_Request( 'POST', '/lnkup/v1/markets/mos-testville/alert' );
wp_set_current_user( 0 );
$res = rest_do_request( $req );
lnkup_t_assert( in_array( $res->get_status(), array( 401, 403 ), true ), 'alert endpoint requires login' );

echo "Demand signals\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
$req = new WP_REST_Request( 'POST', '/lnkup/v1/markets/mos-testville/demand' );
$req->set_param( 'category', 'not-real' );
lnkup_t_same( 400, rest_do_request( $req )->get_status(), 'unknown demand category rejected' );
$req = new WP_REST_Request( 'POST', '/lnkup/v1/markets/mos-testville/demand' );
$req->set_param( 'category', 'food-trucks' );
lnkup_t_same( true, rest_do_request( $req )->get_data()['recorded'], 'demand signal recorded (anonymous)' );
lnkup_t_same( false, rest_do_request( $req )->get_data()['recorded'], 'same IP + category deduped for the day' );
lnkup_t_assert( ( LNKup_Market_Registry::demand( 'mos-testville', 1 )['food-trucks'] ?? 0 ) >= 1, 'demand aggregates per category' );
global $wpdb;
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $wpdb ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}lnkup_analytics_events WHERE event_type IN ('market_demand_signal','market_alert_created') AND event_data LIKE %s", '%mos-testville%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_lnkup\_demand\_%' OR option_name LIKE '\_transient\_timeout\_lnkup\_demand\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
};
$row = $wpdb->get_var( $wpdb->prepare( "SELECT event_data FROM {$wpdb->prefix}lnkup_analytics_events WHERE event_type = 'market_demand_signal' AND event_data LIKE %s ORDER BY id DESC LIMIT 1", '%mos-testville%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
lnkup_t_assert( $row && false === strpos( (string) $row, '203.0.113.77' ), 'demand signal stores no IP address' );

echo "Event page notices\n";
$cancel = lnkup_m_event( array( 'event_status' => 'cancelled' ) );
lnkup_t_assert( false !== strpos( LNKup_Event_Distribution::hero_notices( $cancel['event'] ), 'lnkup-event-notice-cancelled' ), 'cancelled banner' );
$postp = lnkup_m_event( array( 'event_status' => 'postponed' ) );
lnkup_t_assert( false !== strpos( LNKup_Event_Distribution::hero_notices( $postp['event'] ), 'lnkup-event-notice-postponed' ), 'postponed banner' );
$ended = lnkup_m_event( array( 'event_date' => gmdate( 'Y-m-d', strtotime( '-5 days' ) ) ) );
lnkup_t_assert( false !== strpos( LNKup_Event_Distribution::hero_notices( $ended['event'] ), 'lnkup-event-notice-ended' ), 'ended banner' );
lnkup_t_same( '', LNKup_Event_Distribution::hero_notices( $e1['event'] ), 'normal upcoming event has no notice' );
$disp = lnkup_m_event();
update_post_meta( $disp['event'], '_lnkup_authenticity_status', 'disputed' );
lnkup_t_assert( false !== strpos( LNKup_Event_Distribution::hero_notices( $disp['event'] ), 'lnkup-event-notice-authenticity' ), 'disputed listing notice' );
$ext = lnkup_m_event( array( 'organizer_user_id' => 0, 'external_event_id' => 987654321, 'external_source_url' => 'https://www.example.com/e/123' ), false );
$html = LNKup_Event_Distribution::hero_notices( $ext['event'] );
lnkup_t_assert( false !== strpos( $html, 'lnkup-event-external-disclosure' ) && false !== strpos( $html, 'example.com' ), 'external disclosure shows source domain' );
LNKup_Marketing_Flags::override( 'seo_index_policy', false );
lnkup_t_same( '', LNKup_Event_Distribution::hero_notices( $cancel['event'] ), 'flag off -> no notices' );
LNKup_Marketing_Flags::override( 'seo_index_policy', true );

$ref = new ReflectionMethod( 'LNKup_Shortcodes', 'render_related_market_events' );
$ref->setAccessible( true );
$related = (string) $ref->invoke( null, $e1['event'] );
lnkup_t_assert( false === strpos( $related, get_the_title( $past['event'] ) ), '"More events in this city" excludes past events' );

echo "Follow tour\n";
lnkup_t_assert( is_wp_error( LNKup_Event_Distribution::validate_favorite( 'bogus', 1 ) ), 'unknown favorite type rejected' );
lnkup_t_assert( true === LNKup_Event_Distribution::validate_favorite( 'events', $e1['event'] ), 'event favorite valid' );
$tour = wp_insert_post( array( 'post_type' => 'lnkup_tour', 'post_status' => 'publish', 'post_title' => 'MOS Tour' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $tour );
$draft_tour = wp_insert_post( array( 'post_type' => 'lnkup_tour', 'post_status' => 'draft', 'post_title' => 'MOS Draft Tour' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $draft_tour );
lnkup_t_assert( true === LNKup_Event_Distribution::validate_favorite( 'tours', (int) $tour ), 'published tour can be followed' );
lnkup_t_assert( is_wp_error( LNKup_Event_Distribution::validate_favorite( 'tours', (int) $draft_tour ) ), 'draft tour cannot be followed' );
wp_set_current_user( $vendor );
$req = new WP_REST_Request( 'POST', '/lnkup/v1/marketplace/favorites' );
$req->set_param( 'type', 'tours' );
$req->set_param( 'id', (int) $tour );
lnkup_t_same( true, rest_do_request( $req )->get_data()['favorited'], 'vendor follows tour via favorites REST' );
lnkup_t_assert( in_array( $vendor, LNKup_Event_Distribution::tour_followers( (int) $tour ), true ), 'tour followers lookup finds vendor' );
$req = new WP_REST_Request( 'POST', '/lnkup/v1/marketplace/favorites' );
$req->set_param( 'type', 'secret_type' );
$req->set_param( 'id', 5 );
lnkup_t_same( 400, rest_do_request( $req )->get_status(), 'favorites REST rejects unknown type' );
update_post_meta( $e1['event'], '_lnkup_tour_id', (int) $tour );
lnkup_t_assert( false !== strpos( LNKup_Event_Distribution::hero_actions( $e1['event'], 3, false ), 'lnkup-follow-tour' ), 'follow tour button on tour stop' );
lnkup_t_assert( false !== strpos( LNKup_Event_Distribution::hero_actions( $e2['event'], 0, false ), 'lnkup-event-similar-alert' ), 'similar-events alert when nothing is open' );
wp_set_current_user( 0 );

echo "OG images\n";
if ( LNKup_OG_Images::available() ) {
	$url = LNKup_OG_Images::event_url( $e1['event'] );
	lnkup_t_assert( (bool) preg_match( '#/lnkup-og/event-' . $e1['event'] . '-[a-f0-9]{12}\.png$#', $url ), 'event OG URL is signed PNG' );
	$dir  = wp_upload_dir( null, false )['basedir'] . '/lnkup-og/';
	$file = $dir . basename( $url );
	lnkup_t_assert( file_exists( $file ), 'PNG written' );
	$size = getimagesize( $file );
	lnkup_t_assert( is_array( $size ) && 1200 === $size[0] && 630 === $size[1], 'PNG is 1200x630' );
	lnkup_t_same( $url, LNKup_OG_Images::event_url( $e1['event'] ), 'cached: same inputs, same URL' );
	wp_update_post( array( 'ID' => $e1['event'], 'post_title' => 'MOS Renamed Event' ) );
	$url2 = LNKup_OG_Images::event_url( $e1['event'] );
	lnkup_t_assert( $url2 !== $url && ! file_exists( $file ), 'title change -> new signature, old PNG removed' );
	$draft = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'draft', 'post_title' => 'MOS OG draft' ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $draft );
	lnkup_t_same( '', LNKup_OG_Images::event_url( (int) $draft ), 'draft event gets no OG image' );
	$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $dir, $e1 ) {
		foreach ( (array) glob( $dir . 'event-' . $e1['event'] . '-*.png' ) as $f ) {
			wp_delete_file( (string) $f );
		}
		foreach ( (array) glob( $dir . 'market-mos-*.png' ) as $f ) {
			wp_delete_file( (string) $f );
		}
	};
	$murl = LNKup_OG_Images::market_url( LNKup_Market_Pages::get_market_by_slug( 'mos-testville' ) );
	lnkup_t_assert( (bool) preg_match( '#/lnkup-og/market-mos-testville-[a-f0-9]{12}\.png$#', $murl ), 'market OG URL' );
} else {
	lnkup_t_skip( 'GD with FreeType unavailable' );
}

echo "Admin save sanitizes registry fields\n";
lnkup_t_assert( null === LNKup_Market_Registry::with_defaults( array( 'lat' => 'abc' ) )['lat'], 'non-numeric lat discarded' );
lnkup_t_same( array( 'a-b' ), LNKup_Market_Registry::with_defaults( array( 'nearby' => array( 'A B', '' ) ) )['nearby'], 'nearby slugs sanitized' );

lnkup_m_finish();
