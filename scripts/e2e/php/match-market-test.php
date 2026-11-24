<?php
/**
 * Phase 7: geographic supply and demand (match market snapshot, Organizer Growth feed, Market Pages).
 *
 * Covers: search events carry the city; snapshot counts (thin, unmatched, matched, idle, searches,
 * zero-result) and signals; idempotent rebuild; admin REST access, CSV formula guard and rebuild
 * throttle; market_need lead-scoring factor; market_signal lead filter; research tasks (off by
 * default, baseline on first run, deduplicated); lead drawer and connector figures; Market Pages
 * suggestions, explicit apply with log, and suggested new markets.
 *
 * Run: docker exec <cli> wp eval-file wp-content/lnkup-e2e/php/match-market-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Match market (supply and demand)\n";

$GLOBALS['mm'] = array(
	'run'          => strtolower( wp_generate_password( 5, false, false ) ),
	'org_settings' => get_option( LNKup_Org_Growth::SETTINGS_KEY, null ),
	'need_state'   => get_option( LNKup_Org_Market_Need::STATE_OPTION, null ),
	'markets'      => get_option( LNKup_Market_Pages::OPTION_KEY, null ),
	'status_log'   => get_option( LNKup_Match_Market::STATUS_LOG_OPTION, null ),
	'profiles'     => array(),
	'opps'         => array(),
	'leads'        => array(),
);
$run    = $GLOBALS['mm']['run'];
$admin  = lnkup_t_user( 'administrator' );
$mindex = LNKup_Marketplace_Index::table_name();
$ocats  = LNKup_Match_Tables::opportunity_categories_table();
$snap   = LNKup_Match_Market::table();
$events = LNKup_Marketplace_Analytics::table_name();
$city_t = 'Mmktt' . $run;
$city_i = 'Mmkti' . $run;
$city_u = 'Mmktu' . $run;
$key_t  = LNKup_Match_Market::key( $city_t, 'NC' );
$key_i  = LNKup_Match_Market::key( $city_i, 'NC' );
$key_u  = LNKup_Match_Market::key( $city_u, 'NC' );
wp_set_current_user( $admin );
LNKup_Org_Growth::update_settings( array( 'organizer_growth_enabled' => true ) );
delete_option( LNKup_Match_Market::REBUILD_OPTION );

$term    = wp_insert_term( 'MMKT ' . $run . ' Food', 'lnkup_vendor_category' );
$term_id = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
lnkup_t_assert( $term_id > 0, 'test vendor category created' );
$GLOBALS['mm']['term'] = $term_id;

/**
 * Active, discoverable vendor profile in the matching vendor index.
 */
function lnkup_mm_vendor( string $city, int $term ): int {
	$user = lnkup_t_user( 'lnkup_vendor' );
	$pid  = (int) wp_insert_post( array( 'post_type' => 'lnkup_vendor_profile', 'post_status' => 'publish', 'post_title' => 'MMKT vendor ' . $user ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $pid );
	$GLOBALS['mm']['profiles'][]     = $pid;
	foreach ( array( 'owner_user_id' => $user, 'city' => $city, 'state' => 'NC' ) as $k => $v ) {
		update_post_meta( $pid, '_lnkup_' . $k, $v );
	}
	wp_set_object_terms( $pid, array( $term ), 'lnkup_vendor_category' );
	LNKup_Match_Vendor_Index::sync( $pid );
	return $pid;
}

/**
 * Open booth row in the marketplace index with a category.
 */
function lnkup_mm_opp( string $city, int $term ): int {
	global $wpdb;
	$id = 980000000 + wp_rand( 1, 9999999 );
	$wpdb->insert( LNKup_Marketplace_Index::table_name(), array( 'opportunity_id' => $id, 'event_id' => 0, 'organizer_user_id' => 0, 'title' => 'MMKT opp', 'city' => $city, 'state' => 'NC', 'spots_total' => 5, 'spots_remaining' => 5, 'event_date' => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
	$wpdb->insert( LNKup_Match_Tables::opportunity_categories_table(), array( 'opportunity_id' => $id, 'term_id' => $term, 'group_key' => '' ) ); // phpcs:ignore WordPress.DB
	$GLOBALS['mm']['opps'][] = $id;
	return $id;
}

/**
 * Raw search analytics event (first page unless given).
 */
function lnkup_mm_search( string $type, array $data ): void {
	LNKup_Marketplace_Analytics::track( $type, array_merge( array( 'q' => '', 'categories' => array(), 'page' => 1 ), $data ) );
}

/**
 * Today's snapshot row for a city (term 0 unless given).
 */
function lnkup_mm_row( string $key, int $term = 0 ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Match_Market::table() . ' WHERE snapshot_date = %s AND city_key = %s AND term_id = %d', current_time( 'Y-m-d' ), $key, $term ), ARRAY_A );
}

// City T: two booths, one with a single recommended vendor (thin supply).
$vt   = lnkup_mm_vendor( $city_t, $term_id );
$opp1 = lnkup_mm_opp( $city_t, $term_id );
lnkup_mm_opp( $city_t, $term_id );
$wpdb->insert( LNKup_Match_Tables::results_table(), array( 'profile_id' => $vt, 'opportunity_id' => $opp1, 'eligible' => 1, 'score' => 80, 'computed_at' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
// City I: five vendors and no booths (idle supply).
for ( $i = 0; $i < 5; $i++ ) {
	lnkup_mm_vendor( $city_i, $term_id );
}
// City U: vendors search but nothing is listed (unmet search).
lnkup_mm_search( 'search', array( 'city' => $city_u, 'state' => '', 'total' => 2 ) );
lnkup_mm_search( 'search_no_results', array( 'city' => $city_u, 'state' => 'NC', 'categories' => array( 'MMKT ' . $run . ' Food' ) ) );
lnkup_mm_search( 'search_no_results', array( 'city' => $city_u, 'state' => 'NC' ) );
lnkup_mm_search( 'search_no_results', array( 'city' => $city_u, 'state' => 'NC', 'page' => 2 ) );
lnkup_mm_search( 'search_no_results', array( 'city' => '=Mmktq' . $run, 'state' => 'NC' ) );

echo "\n[search events]\n";
$req = new WP_REST_Request( 'GET', '/lnkup/v1/marketplace/search' );
$req->set_param( 'city', $city_u );
$req->set_param( 'state', 'NC' );
lnkup_t_same( 200, rest_do_request( $req )->get_status(), 'marketplace search runs' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$last = $wpdb->get_row( $wpdb->prepare( "SELECT event_type, event_data FROM {$events} WHERE event_type IN ('search','search_no_results') AND event_data LIKE %s ORDER BY id DESC LIMIT 1", '%' . $wpdb->esc_like( $city_u ) . '%' ), ARRAY_A );
$data = json_decode( (string) ( $last['event_data'] ?? '' ), true );
lnkup_t_same( 'search_no_results', $last['event_type'] ?? '', 'a city search with no booths is recorded as zero-result' );
lnkup_t_same( array( $city_u, 'NC', 1 ), array( $data['city'] ?? '', $data['state'] ?? '', (int) ( $data['page'] ?? 0 ) ), 'search event carries city, state and page' );
$loc = LNKup_Marketplace_REST::search_location( array( 'city' => 'X', 'state' => 'nc', 'categories' => array( (string) $term_id, 'nope' ), 'page' => 3 ) );
lnkup_t_same( array( 'MMKT ' . $run . ' Food', 'nope' ), $loc['categories'], 'category IDs are stored as names, unknown values kept as typed' );

echo "\n[snapshot]\n";
$built = LNKup_Match_Market::build_snapshot();
lnkup_t_assert( is_array( $built ) && $built['cities'] >= 3, 'snapshot built' );
$t = lnkup_mm_row( $key_t );
lnkup_t_same( array( 2, 2, 1, 1, 0, 1 ), array( (int) $t['open_opportunities'], (int) $t['opps_thin'], (int) $t['opps_unmatched'], (int) $t['matched_vendors'], (int) $t['idle_vendors'], (int) $t['recommended_pairs'] ), 'city T: 2 open, 2 thin, 1 unmatched, 1 matched, 0 idle, 1 pair' );
lnkup_t_same( 2, (int) ( lnkup_mm_row( $key_t, $term_id )['open_opportunities'] ?? 0 ), 'city T category row counts its booths' );
lnkup_t_same( 'thin_supply', LNKup_Match_Market::signal( $t ), 'city T signal: thin supply' );
$iv = lnkup_mm_row( $key_i );
lnkup_t_same( array( 0, 5 ), array( (int) $iv['open_opportunities'], (int) $iv['idle_vendors'] ), 'city I: 5 idle vendors, no booths' );
lnkup_t_same( 'idle_supply', LNKup_Match_Market::signal( $iv ), 'city I signal: idle supply' );
$u = lnkup_mm_row( $key_u );
lnkup_t_same( array( 4, 3 ), array( (int) $u['searches_30d'], (int) $u['zero_result_searches_30d'] ), 'city U: 4 searches (incl. one without state), 3 found nothing; page 2 ignored' );
lnkup_t_same( 1, (int) ( lnkup_mm_row( $key_u, $term_id )['zero_result_searches_30d'] ?? 0 ), 'searched category is credited to its term row' );
lnkup_t_same( 'unmet_search', LNKup_Match_Market::signal( $u ), 'city U signal: unmet search' );
$count = static fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$snap} WHERE snapshot_date = %s AND city_key LIKE %s", current_time( 'Y-m-d' ), '%' . $wpdb->esc_like( $run ) . '%' ) ); // phpcs:ignore WordPress.DB
$before = $count();
LNKup_Match_Market::build_snapshot();
lnkup_t_same( $before, $count(), 'rebuilding replaces today’s rows (idempotent)' );

echo "\n[admin REST]\n";
$vendor_user = (int) get_post_meta( $vt, '_lnkup_owner_user_id', true );
foreach ( array( 'vendor' => $vendor_user, 'anonymous' => 0 ) as $who => $uid ) {
	wp_set_current_user( $uid );
	foreach ( array( array( 'GET', '/lnkup/v1/match/admin/market' ), array( 'GET', '/lnkup/v1/match/admin/market/city' ), array( 'GET', '/lnkup/v1/match/admin/market/export' ), array( 'POST', '/lnkup/v1/match/admin/market/rebuild' ) ) as $route ) {
		$status = rest_do_request( new WP_REST_Request( $route[0], $route[1] ) )->get_status();
		lnkup_t_assert( in_array( $status, array( 401, 403 ), true ), "{$who} blocked from {$route[0]} {$route[1]} ({$status})" );
	}
}
wp_set_current_user( $admin );
$req = new WP_REST_Request( 'GET', '/lnkup/v1/match/admin/market' );
$req->set_param( 'state', 'NC' );
$req->set_param( 'signal', 'thin_supply' );
$res = rest_do_request( $req );
lnkup_t_assert( 200 === $res->get_status() && in_array( $key_t, wp_list_pluck( $res->get_data()['items'], 'city_key' ), true ), 'admin lists cities filtered by signal' );
$req = new WP_REST_Request( 'GET', '/lnkup/v1/match/admin/market/city' );
$req->set_param( 'key', $key_t );
$detail = rest_do_request( $req )->get_data();
lnkup_t_same( 2, count( (array) ( $detail['thin_booths'] ?? array() ) ), 'city detail lists the thin booths' );
lnkup_t_assert( false !== strpos( LNKup_Match_Market::export_csv( array( 'state' => 'NC' ) ), "'=Mmktq" . $run ), 'CSV export neutralises formula-looking city names' );
lnkup_t_same( 200, rest_do_request( new WP_REST_Request( 'POST', '/lnkup/v1/match/admin/market/rebuild' ) )->get_status(), 'admin rebuilds the snapshot' );
lnkup_t_same( 429, rest_do_request( new WP_REST_Request( 'POST', '/lnkup/v1/match/admin/market/rebuild' ) )->get_status(), 'rebuild limited to once per 10 minutes' );

echo "\n[organizer growth]\n";
LNKup_Org_Market_Need::flush();
foreach ( array( 'i' => $city_i, 't' => $city_t, 'x' => 'Mmktx' . $run ) as $tag => $city ) {
	$id = LNKup_Org_Leads::create( array( 'name' => 'MMKT ' . $run . ' ' . $tag, 'email' => "hello@mmkt{$tag}-{$run}.test", 'city' => $city, 'state' => 'NC', 'source' => 'manual' ), array( 'actor' => $admin ) );
	lnkup_t_assert( is_int( $id ), "lead {$tag} created" );
	$GLOBALS['mm']['leads'][ $tag ] = is_int( $id ) ? $id : 0;
}
$leads = $GLOBALS['mm']['leads'];
lnkup_t_same( LNKup_Org_Market_Need::NEED_ORGANIZERS, LNKup_Org_Market_Need::cities()[ $key_i ]['need'] ?? '', 'city I needs organizers' );
lnkup_t_same( LNKup_Org_Market_Need::NEED_VENDORS, LNKup_Org_Market_Need::cities()[ $key_t ]['need'] ?? '', 'city T needs vendors' );
$points = static function ( int $lead_id ): array {
	$eval = LNKup_Org_Lead_Scoring::evaluate( (array) LNKup_Org_Leads::get_row( $lead_id ) );
	$row  = array_values( array_filter( $eval['explanation'], static fn( $e ) => 'market_need' === $e['factor'] ) );
	return $row[0] ?? array();
};
$need_i = $points( $leads['i'] );
lnkup_t_same( 10, $need_i['points'] ?? null, 'lead in a city that needs organizers gets full market_need points' );
lnkup_t_assert( false !== strpos( (string) ( $need_i['detail'] ?? '' ), '5 matching vendors with nothing to apply to' ), 'market_need explanation shows the counts' );
lnkup_t_same( 0, $points( $leads['t'] )['points'] ?? null, 'lead in a city that needs vendors gets no market_need points' );
lnkup_t_same( 'No supply data for this city yet', $points( $leads['x'] )['detail'] ?? '', 'lead in an unknown city explains why' );

$filter = static fn( string $need ) => array_map( 'intval', wp_list_pluck( LNKup_Org_Leads::query( array( 'search' => 'MMKT ' . $run, 'market_signal' => $need, 'per_page' => 50 ) )['items'], 'id' ) );
lnkup_t_same( array( $leads['i'] ), $filter( 'need_organizers' ), 'market_signal=need_organizers returns only the city I lead' );
lnkup_t_same( array( $leads['t'] ), $filter( 'need_vendors' ), 'market_signal=need_vendors returns only the city T lead' );
lnkup_t_same( 3, count( $filter( 'bogus' ) ), 'unknown market_signal values are ignored' );
LNKup_Org_Market_Need::ensure_saved_view();
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
lnkup_t_assert( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::VIEWS ) . ' WHERE is_shared = 1 AND filters LIKE %s', '%need_organizers%' ) ) >= 1, 'shared saved view for markets that need organizers exists' );

$drawer = LNKup_Org_Supply_Intel::for_lead( (array) LNKup_Org_Leads::get_row( $leads['i'] ) );
lnkup_t_same( array( 'idle_supply', 5 ), array( $drawer['market']['signal'] ?? '', (int) ( $drawer['market']['idle_vendors'] ?? -1 ) ), 'lead drawer supply card carries the match figures' );
LNKup_Org_Supply_Intel::build_snapshot();
$scoped = LNKup_Org_Supply_Intel::for_territories( array( array( 'scope_type' => 'city', 'value' => $city_t . ', NC' ) ) );
lnkup_t_same( array( 0, 2, 0 ), array( $scoped['items'][0]['idle_vendors'] ?? null, $scoped['items'][0]['thin_booths'] ?? null, $scoped['items'][0]['zero_result_searches'] ?? null ), 'connector figures: idle vendors, thin booths and failed searches for their city' );
lnkup_t_same( '<3', LNKup_Org_Supply_Intel::mask( 2 ), 'small vendor counts are masked for connectors' );

echo "\n[research tasks]\n";
$tasks_for = static fn( int $lead_id ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Org_Lead_Tasks::table() . ' WHERE lead_id = %d', $lead_id ) ); // phpcs:ignore WordPress.DB
lnkup_t_assert( ! LNKup_Org_Growth::setting( LNKup_Org_Market_Need::TASK_SETTING ), 'research tasks are off by default' );
$others = array_values( array_diff( LNKup_Org_Market_Need::city_keys( LNKup_Org_Market_Need::NEED_ORGANIZERS ), array( $key_i ) ) );
update_option( LNKup_Org_Market_Need::STATE_OPTION, $others, false );
lnkup_t_same( 0, LNKup_Org_Market_Need::daily(), 'setting off: no tasks when a city starts needing organizers' );
delete_option( LNKup_Org_Market_Need::STATE_OPTION );
LNKup_Org_Growth::update_settings( array( LNKup_Org_Market_Need::TASK_SETTING => true ) );
lnkup_t_same( 0, LNKup_Org_Market_Need::daily(), 'first run only records a baseline' );
update_option( LNKup_Org_Market_Need::STATE_OPTION, $others, false );
lnkup_t_same( 1, LNKup_Org_Market_Need::daily(), 'setting on: one research task when city I starts needing organizers' );
lnkup_t_same( 1, $tasks_for( $leads['i'] ), 'task is on the city I lead' );
lnkup_t_same( 0, LNKup_Org_Market_Need::create_tasks( array( $key_i ) ), 'no second task for the same lead this month' );
lnkup_t_same( 0, $tasks_for( $leads['t'] ), 'leads in other cities get no task' );

echo "\n[market pages]\n";
$suggest_i = LNKup_Match_Market::suggest_market_status( $city_i, 'NC' );
lnkup_t_same( 'recruiting_organizers', $suggest_i['status'] ?? '', 'idle supply suggests Recruiting organizers' );
lnkup_t_same( 'recruiting_vendors', LNKup_Match_Market::suggest_market_status( $city_t, 'NC' )['status'] ?? '', 'thin supply suggests Recruiting vendors' );
lnkup_t_same( null, LNKup_Match_Market::suggest_market_status( 'Mmktx' . $run, 'NC' ), 'no suggestion without data' );
$new_keys = wp_list_pluck( LNKup_Match_Market::suggested_new_markets( 500 ), 'city_key' );
lnkup_t_assert( in_array( $key_t, $new_keys, true ) && in_array( $key_u, $new_keys, true ), 'cities with matched vendors or failed searches are suggested as new markets' );
lnkup_t_assert( ! in_array( $key_i, $new_keys, true ), 'cities with neither are not suggested' );

$slug = LNKup_Market_Pages_Admin::create_market( $city_t, 'nc' );
lnkup_t_assert( is_string( $slug ), 'market page created from a suggestion' );
$market = LNKup_Market_Pages::get_all_markets()[ (string) $slug ] ?? array();
lnkup_t_same( array( 'prelaunch', false, 'NC' ), array( $market['launch_status'] ?? '', $market['enabled'] ?? null, $market['state'] ?? '' ), 'new market starts in prelaunch, disabled' );
lnkup_t_assert( is_wp_error( LNKup_Market_Pages_Admin::create_market( strtoupper( $city_t ), 'NC' ) ), 'duplicate city is refused' );
lnkup_t_assert( is_wp_error( LNKup_Market_Pages_Admin::create_market( 'Somewhere', 'North' ) ), 'state must be two letters' );
lnkup_t_assert( ! in_array( $key_t, wp_list_pluck( LNKup_Match_Market::suggested_new_markets( 500 ), 'city_key' ), true ), 'city with a market page is no longer suggested' );

LNKup_Match_Market::build_snapshot();
lnkup_t_same( 'prelaunch', LNKup_Market_Pages::get_all_markets()[ (string) $slug ]['launch_status'] ?? '', 'snapshots never change a market status on their own' );
$applied = LNKup_Match_Market::apply_market_suggestion( (string) $slug );
lnkup_t_same( array( 'from' => 'prelaunch', 'to' => 'recruiting_vendors' ), $applied, 'Apply sets the suggested status' );
lnkup_t_same( 'recruiting_vendors', LNKup_Market_Pages::get_all_markets()[ (string) $slug ]['launch_status'] ?? '', 'status saved' );
$log = LNKup_Match_Market::market_status_log( 1 )[0] ?? array();
lnkup_t_same( array( $slug, 'prelaunch', 'recruiting_vendors', $admin ), array( $log['slug'] ?? '', $log['from'] ?? '', $log['to'] ?? '', (int) ( $log['user_id'] ?? 0 ) ), 'status change is logged with who and why' );
lnkup_t_assert( is_wp_error( LNKup_Match_Market::apply_market_suggestion( (string) $slug ) ), 'nothing to apply once status matches the suggestion' );

// --------------------------------------------------------------- Cleanup.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( $GLOBALS['mm']['opps'] as $id ) {
	$wpdb->delete( $mindex, array( 'opportunity_id' => $id ) );
	$wpdb->delete( $ocats, array( 'opportunity_id' => $id ) );
	$wpdb->delete( LNKup_Match_Tables::results_table(), array( 'opportunity_id' => $id ) );
}
foreach ( $GLOBALS['mm']['profiles'] as $pid ) {
	$wpdb->delete( LNKup_Match_Tables::vendor_index_table(), array( 'profile_id' => $pid ) );
	$wpdb->delete( LNKup_Match_Tables::vendor_categories_table(), array( 'profile_id' => $pid ) );
	$wpdb->delete( LNKup_Match_Tables::results_table(), array( 'profile_id' => $pid ) );
}
$lead_ids = array_filter( array_map( 'intval', $GLOBALS['mm']['leads'] ) );
if ( $lead_ids ) {
	$in = implode( ',', $lead_ids );
	foreach ( array( LNKup_Org_Growth_Tables::ACTIVITY, LNKup_Org_Growth_Tables::TASKS, LNKup_Org_Growth_Tables::TAGS, LNKup_Org_Growth_Tables::ACCOUNTS, LNKup_Org_Growth_Tables::MILESTONES ) as $suffix ) {
		$wpdb->query( 'DELETE FROM ' . LNKup_Org_Growth_Tables::table( $suffix ) . " WHERE lead_id IN ({$in})" );
	}
	$wpdb->query( 'DELETE FROM ' . LNKup_Org_Leads::table() . " WHERE id IN ({$in})" );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE event_type IN ('search','search_no_results') AND event_data LIKE %s", '%mmkt%' . $wpdb->esc_like( $run ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$snap} WHERE city_key LIKE %s", '%mmkt%' . $wpdb->esc_like( $run ) . '%' ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Org_Supply_Intel::table() . ' WHERE city_key LIKE %s', '%mmkt%' . $wpdb->esc_like( $run ) . '%' ) );
// phpcs:enable
if ( $term_id ) {
	wp_delete_term( $term_id, 'lnkup_vendor_category' );
}
foreach ( array( LNKup_Org_Growth::SETTINGS_KEY => 'org_settings', LNKup_Org_Market_Need::STATE_OPTION => 'need_state', LNKup_Market_Pages::OPTION_KEY => 'markets', LNKup_Match_Market::STATUS_LOG_OPTION => 'status_log' ) as $option => $saved_key ) {
	if ( null === $GLOBALS['mm'][ $saved_key ] ) {
		delete_option( $option );
	} else {
		update_option( $option, $GLOBALS['mm'][ $saved_key ], false );
	}
}
delete_option( LNKup_Match_Market::REBUILD_OPTION );
lnkup_t_finish();
