<?php
/**
 * Vendor supply intelligence (Phase 6) on wp-env.
 *
 * Covers: based / reach (cities served, states served, radius) / active definitions, inactive and
 * non-discoverable vendors, national travellers, category rows, demand from open opportunities,
 * saved-search demand, open leads, signals, 7-day delta, idempotent rebuild, lead scoring using
 * active vendors in reach, lead drawer card, connector territory scoping + small-count masking,
 * CSV formula guard, REST access and rebuild rate limit.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/org-supply-intel-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Vendor supply intelligence\n";

$run      = strtolower( wp_generate_password( 5, false, false ) );
$saved    = get_option( LNKup_Org_Growth::SETTINGS_KEY, null );
$admin    = lnkup_t_user( 'administrator' );
$snap     = LNKup_Org_Supply_Intel::table();
$vindex   = LNKup_Match_Tables::vendor_index_table();
$mindex   = LNKup_Marketplace_Index::table_name();
$ocats    = LNKup_Match_Tables::opportunity_categories_table();
$city_a   = 'Ogsia' . $run;
$city_b   = 'Ogsib' . $run;
$key_a    = LNKup_Org_Supply_Intel::key( $city_a, 'NC' );
$key_b    = LNKup_Org_Supply_Intel::key( $city_b, 'NC' );
$profiles = array();
$opp_ids  = array();
$lead_ids = array();
wp_set_current_user( $admin );
LNKup_Org_Growth::update_settings(
	array(
		'organizer_growth_enabled'             => true,
		'org_growth_supply_min_active_vendors' => 3,
		'org_growth_supply_surplus_ratio'      => 3,
	)
);
delete_option( LNKup_Org_Supply_Intel::REBUILD_OPTION );

$term = wp_insert_term( 'OGSI ' . $run . ' Food', 'lnkup_vendor_category' );
$term_id = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
lnkup_t_assert( $term_id > 0, 'test vendor category created' );

/**
 * Indexed vendor profile.
 *
 * @param array<string, mixed> $meta Meta without the _lnkup_ prefix.
 */
$vendor = static function ( string $role, array $meta, array $terms = array(), ?array $coords = null ) use ( &$profiles ): array {
	$user = lnkup_t_user( $role );
	$pid  = (int) wp_insert_post( array( 'post_type' => 'lnkup_vendor_profile', 'post_status' => 'publish', 'post_title' => 'OGSI vendor ' . $user ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $pid );
	$profiles[] = $pid;
	update_post_meta( $pid, '_lnkup_owner_user_id', $user );
	foreach ( $meta as $k => $v ) {
		update_post_meta( $pid, '_lnkup_' . $k, $v );
	}
	if ( $terms ) {
		wp_set_object_terms( $pid, $terms, 'lnkup_vendor_category' );
	}
	LNKup_Match_Vendor_Index::sync( $pid );
	if ( $coords ) {
		update_post_meta( $pid, '_lnkup_geo_lat', (string) $coords[0] );
		update_post_meta( $pid, '_lnkup_geo_lng', (string) $coords[1] );
		LNKup_Match_Vendor_Index::sync( $pid );
	}
	return array( 'user' => $user, 'profile' => $pid );
};

/**
 * Open opportunity row in the marketplace index (+ category).
 */
$opportunity = static function ( string $city, int $organizer, int $total, int $remaining, ?array $coords, int $term ) use ( $wpdb, $mindex, $ocats, &$opp_ids ): int {
	$id = 990000000 + wp_rand( 1, 9999999 );
	$wpdb->insert( $mindex, array( 'opportunity_id' => $id, 'event_id' => 0, 'organizer_user_id' => $organizer, 'title' => 'OGSI opp', 'city' => $city, 'state' => 'NC', 'spots_total' => $total, 'spots_remaining' => $remaining, 'event_date' => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ), 'lat' => $coords[0] ?? null, 'lng' => $coords[1] ?? null, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
	if ( $term ) {
		$wpdb->insert( $ocats, array( 'opportunity_id' => $id, 'term_id' => $term, 'group_key' => '' ) ); // phpcs:ignore WordPress.DB
	}
	$opp_ids[] = $id;
	return $id;
};

// A: v1 based + active + verified + applied; v2 based, not discoverable; v6 based, not a vendor.
// v3 lists A in cities served; v4 serves all of NC; v5 within its 30-mile radius; v7 national.
$v1 = $vendor( 'lnkup_vendor', array( 'city' => $city_a, 'state' => 'NC', 'verification_status' => 'approved' ), array( $term_id ), array( 35.20, -80.80 ) );
$v2 = $vendor( 'lnkup_vendor', array( 'city' => $city_a, 'state' => 'NC', 'discoverable' => '0' ) );
$v6 = $vendor( 'subscriber', array( 'city' => $city_a, 'state' => 'NC' ) );
$v3 = $vendor( 'lnkup_vendor', array( 'city' => 'Ogsic' . $run, 'state' => 'SC', 'cities_served' => wp_json_encode( array( $city_a . ', NC' ) ) ), array( $term_id ) );
$v4 = $vendor( 'lnkup_vendor', array( 'city' => 'Ogsid' . $run, 'state' => 'GA', 'states_served' => wp_json_encode( array( 'NC' ) ) ) );
$v5 = $vendor( 'lnkup_vendor', array( 'city' => 'Ogsie' . $run, 'state' => 'NC', 'service_radius_miles' => 30 ), array(), array( 35.30, -80.80 ) );
$v7 = $vendor( 'lnkup_vendor', array( 'city' => 'Ogsif' . $run, 'state' => 'TX', 'willing_to_travel_national' => '1' ) );
lnkup_t_same( 'inactive', (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$vindex} WHERE profile_id = %d", $v6['profile'] ) ), 'non-vendor account indexed as inactive' ); // phpcs:ignore WordPress.DB

$app = wp_insert_post( array( 'post_type' => 'lnkup_application', 'post_status' => 'publish', 'post_title' => 'OGSI app' ) );
update_post_meta( $app, '_lnkup_vendor_user_id', $v1['user'] );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $app );

$org = lnkup_t_user( 'lnkup_organizer' );
$opportunity( $city_a, $org, 10, 1, array( 35.20, -80.80 ), $term_id );
$opportunity( $city_b, $org, 5, 5, null, $term_id );

update_user_meta( $v1['user'], LNKup_Saved_Searches::META_KEY, array( array( 'id' => 'ogsi', 'name' => 'A', 'status' => 'active', 'filters' => array( 'city' => $city_a, 'categories' => array( 'OGSI ' . $run . ' Food' ) ) ) ) );

$lead_a = LNKup_Org_Leads::create( array( 'name' => 'OGSI ' . $run . ' Market', 'email' => "hello@ogsi-{$run}.test", 'city' => $city_a, 'state' => 'NC', 'event_categories' => 'OGSI ' . $run . ' Food', 'source' => 'manual' ), array( 'actor' => $admin ) );
$lead_q = LNKup_Org_Leads::create( array( 'name' => 'OGSI ' . $run . ' Formula', 'email' => "hello@ogsiq-{$run}.test", 'city' => '=Ogsiq' . $run, 'state' => 'NC', 'source' => 'manual' ), array( 'actor' => $admin ) );
lnkup_t_assert( is_int( $lead_a ) && is_int( $lead_q ), 'fixture leads created' );
$lead_ids = array_filter( array( is_int( $lead_a ) ? $lead_a : 0, is_int( $lead_q ) ? $lead_q : 0 ) );

// --------------------------------------------------------------- Build.
echo "\n[build]\n";
$built = LNKup_Org_Supply_Intel::build_snapshot();
lnkup_t_assert( is_array( $built ) && $built['cities'] >= 2, 'snapshot built' );
$row = static fn( string $key, int $term = 0 ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$snap} WHERE snapshot_date = %s AND city_key = %s AND term_id = %d", current_time( 'Y-m-d' ), $key, $term ), ARRAY_A ); // phpcs:ignore WordPress.DB
$a = $row( $key_a );
lnkup_t_same( 3, (int) ( $a['vendors_based'] ?? -1 ), 'based: three vendor profiles live in city A' );
lnkup_t_same( 6, (int) ( $a['vendors_reach'] ?? -1 ), 'reach adds cities-served, states-served and radius vendors (national traveller excluded)' );
lnkup_t_same( 4, (int) ( $a['vendors_active'] ?? -1 ), 'active excludes the non-discoverable and non-vendor profiles' );
lnkup_t_same( 1, (int) ( $a['vendors_verified'] ?? -1 ), 'verified vendors counted' );
lnkup_t_same( 1, (int) ( $a['vendors_applied_90d'] ?? -1 ), 'recent applicants counted' );
lnkup_t_same( 1, (int) ( $a['saved_search_vendors'] ?? -1 ), 'saved search without a state attaches to the city by name' );
lnkup_t_same( 1, (int) ( $a['spots_remaining'] ?? -1 ), 'open booths from the marketplace index' );
lnkup_t_same( 1, (int) ( $a['live_organizers'] ?? -1 ), 'live organizers counted' );
lnkup_t_same( 1, (int) ( $a['open_leads'] ?? -1 ), 'open organizer leads counted' );
$ax = $row( $key_a, $term_id );
lnkup_t_same( 2, (int) ( $ax['vendors_active'] ?? -1 ), 'category row: active vendors with that category' );
lnkup_t_same( 1, (int) ( $ax['saved_search_vendors'] ?? -1 ), 'category row: saved-search category matched by name' );
$b = $row( $key_b );
lnkup_t_same( 1, (int) ( $b['vendors_active'] ?? -1 ), 'city B reached only by the statewide vendor' );
$nat = $row( LNKup_Org_Supply_Intel::NATIONAL_KEY );
lnkup_t_assert( (int) ( $nat['vendors_active'] ?? 0 ) >= 1, 'national travellers reported as a separate total' );

$count_a = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$snap} WHERE snapshot_date = %s AND city_key = %s", current_time( 'Y-m-d' ), $key_a ) ); // phpcs:ignore WordPress.DB
LNKup_Org_Supply_Intel::build_snapshot();
lnkup_t_same( $count_a, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$snap} WHERE snapshot_date = %s AND city_key = %s", current_time( 'Y-m-d' ), $key_a ) ), 'rebuild on the same day replaces rows (idempotent)' ); // phpcs:ignore WordPress.DB

// --------------------------------------------------------------- Signals + list.
echo "\n[signals]\n";
lnkup_t_same( 'recruit_organizers', LNKup_Org_Supply_Intel::signal( $a ), 'A: 4 active vendors, 1 open booth → recruit organizers' );
lnkup_t_same( 'recruit_vendors', LNKup_Org_Supply_Intel::signal( $b ), 'B: 5 open booths, 1 vendor → recruit vendors' );
lnkup_t_same( 'insufficient_data', LNKup_Org_Supply_Intel::signal( array( 'vendors_active' => 1, 'spots_remaining' => 0 ) ), 'too little data is labelled, not guessed' );
lnkup_t_same( 'balanced', LNKup_Org_Supply_Intel::signal( array( 'vendors_active' => 4, 'spots_remaining' => 3 ) ), 'balanced in between' );

$old = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) ) - 8 * DAY_IN_SECONDS );
$wpdb->insert( $snap, array( 'snapshot_date' => $old, 'city_key' => $key_a, 'city' => $city_a, 'state' => 'NC', 'term_id' => 0, 'vendors_active' => 1, 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
$list = LNKup_Org_Supply_Intel::cities( array( 'state' => 'NC', 'limit' => 500 ) );
$mine = array_values( array_filter( $list['items'], static fn( $i ) => $i['city_key'] === $key_a ) );
lnkup_t_same( 3, $mine[0]['delta_active'] ?? null, '7-day change compares with the snapshot a week earlier' );
$filtered = LNKup_Org_Supply_Intel::cities( array( 'state' => 'NC', 'signal' => 'recruit_vendors', 'limit' => 500 ) );
lnkup_t_assert( in_array( $key_b, wp_list_pluck( $filtered['items'], 'city_key' ), true ) && ! in_array( $key_a, wp_list_pluck( $filtered['items'], 'city_key' ), true ), 'signal filter' );
$cat_list = LNKup_Org_Supply_Intel::cities( array( 'term_id' => $term_id, 'limit' => 500 ) );
lnkup_t_assert( isset( $cat_list['categories'][ $term_id ] ) && in_array( $key_a, wp_list_pluck( $cat_list['items'], 'city_key' ), true ), 'category filter and category list' );

$detail = LNKup_Org_Supply_Intel::city( $key_a );
lnkup_t_assert( ! is_wp_error( $detail ) && 4 === $detail['summary']['vendors_active'], 'city detail' );
lnkup_t_same( 2, count( $detail['trend'] ?? array() ), '90-day trend includes the earlier snapshot' );
lnkup_t_same( 1, count( $detail['leads'] ?? array() ), 'city detail lists open leads' );
lnkup_t_assert( is_wp_error( LNKup_Org_Supply_Intel::city( 'nowhere|zz' ) ), 'unknown city → 404 error' );

// --------------------------------------------------------------- Scoring + lead card.
echo "\n[scoring]\n";
lnkup_t_same( 4, LNKup_Org_Lead_Scoring::vendor_count_for_city( $city_a, 'NC' ), 'lead scoring counts active vendors in reach' );
lnkup_t_same( 0, LNKup_Org_Lead_Scoring::vendor_count_for_city( 'Ogsiz' . $run, 'NC' ), 'unknown city falls back to a live count' );
$eval = LNKup_Org_Lead_Scoring::evaluate( (array) LNKup_Org_Leads::get_row( (int) $lead_a ) );
$item = array_values( array_filter( (array) ( $eval['explanation'] ?? array() ), static fn( $i ) => 'vendor_market' === ( $i['factor'] ?? '' ) ) );
lnkup_t_assert( isset( $item[0] ) && false !== strpos( (string) ( $item[0]['detail'] ?? '' ), '4 active LNKup vendors' ), 'factor explains the count' );
$card = LNKup_Org_Supply_Intel::for_lead( (array) LNKup_Org_Leads::get_row( (int) $lead_a ) );
lnkup_t_same( 'OGSI ' . $run . ' Food', $card['matching'][0]['category'] ?? null, 'lead card matches the lead’s event categories' );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/growth/leads/' . (int) $lead_a ) );
lnkup_t_same( 4, $res->get_data()['supply']['vendors_active'] ?? null, 'lead REST response carries the supply card' );

// --------------------------------------------------------------- Connector.
echo "\n[connector]\n";
lnkup_t_same( '<3', LNKup_Org_Supply_Intel::mask( 2 ), 'small counts masked' );
lnkup_t_same( 0, LNKup_Org_Supply_Intel::mask( 0 ), 'zero shown as zero' );
$scoped = LNKup_Org_Supply_Intel::for_territories( array( array( 'scope_type' => 'city', 'value' => $city_a . ', NC' ) ) );
lnkup_t_same( array( $city_a . ', NC' ), wp_list_pluck( $scoped['items'], 'label' ), 'only the connector’s territory cities' );
$by_state = LNKup_Org_Supply_Intel::for_territories( array( array( 'scope_type' => 'state', 'value' => 'NC' ) ) );
$b_item   = array_values( array_filter( $by_state['items'], static fn( $i ) => $i['label'] === $city_b . ', NC' ) );
lnkup_t_same( '<3', $b_item[0]['vendors_active'] ?? null, 'state territory includes city B with its count masked' );
lnkup_t_assert( ! isset( $b_item[0]['vendors_based'] ) && ! isset( $b_item[0]['city_key'] ), 'connector payload is aggregate only' );

$connector_user = lnkup_t_user( 'lnkup_connector' );
$wpdb->insert( LNKup_Connector_Tables::connectors_table(), array( 'user_id' => $connector_user, 'display_name' => 'OGSI ' . $run, 'email' => "cc-{$run}@example.test", 'city' => $city_a, 'state' => 'NC', 'status' => 'active', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
$connector_id = (int) $wpdb->insert_id;
wp_set_current_user( $connector_user );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/connector/supply' ) );
lnkup_t_same( 200, $res->get_status(), 'connector reads supply for their cities' );
lnkup_t_assert( in_array( $city_a . ', NC', wp_list_pluck( (array) ( $res->get_data()['items'] ?? array() ), 'label' ), true ), 'connector without territories gets their home city' );
lnkup_t_assert( in_array( rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/growth/supply' ) )->get_status(), array( 401, 403 ), true ), 'connector cannot read the admin supply report' );

// --------------------------------------------------------------- Access + export + rebuild.
echo "\n[access]\n";
foreach ( array( 'organizer' => $org, 'vendor' => $v1['user'], 'anonymous' => 0 ) as $who => $uid ) {
	wp_set_current_user( $uid );
	foreach ( array( array( 'GET', '/lnkup/v1/growth/supply' ), array( 'GET', '/lnkup/v1/growth/supply/city' ), array( 'POST', '/lnkup/v1/growth/supply/rebuild' ), array( 'GET', '/lnkup/v1/growth/supply/export' ), array( 'GET', '/lnkup/v1/connector/supply' ) ) as $route ) {
		$status = rest_do_request( new WP_REST_Request( $route[0], $route[1] ) )->get_status();
		lnkup_t_assert( in_array( $status, array( 401, 403 ), true ), "{$who} blocked from {$route[0]} {$route[1]} ({$status})" );
	}
}
wp_set_current_user( $admin );
lnkup_t_same( 200, rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/growth/supply' ) )->get_status(), 'admin reads supply' );
$csv = LNKup_Org_Supply_Intel::export_csv( array( 'state' => 'NC' ) );
lnkup_t_assert( false !== strpos( $csv, "'=Ogsiq" . $run ), 'CSV export neutralises formula-looking city names' );
$first  = rest_do_request( new WP_REST_Request( 'POST', '/lnkup/v1/growth/supply/rebuild' ) );
$second = rest_do_request( new WP_REST_Request( 'POST', '/lnkup/v1/growth/supply/rebuild' ) );
lnkup_t_same( 200, $first->get_status(), 'admin rebuilds the snapshot' );
lnkup_t_same( 429, $second->get_status(), 'rebuild limited to once per 10 minutes' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::audit_table() . " WHERE action = 'org_growth_supply_rebuilt' AND created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - 600 ) ) ) >= 1 ? 1 : 0, 'rebuild audited' ); // phpcs:ignore WordPress.DB

// --------------------------------------------------------------- Cleanup.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( $opp_ids as $id ) {
	$wpdb->delete( $mindex, array( 'opportunity_id' => $id ) );
	$wpdb->delete( $ocats, array( 'opportunity_id' => $id ) );
}
foreach ( $profiles as $pid ) {
	$wpdb->delete( $vindex, array( 'profile_id' => $pid ) );
	$wpdb->delete( LNKup_Match_Tables::vendor_categories_table(), array( 'profile_id' => $pid ) );
}
if ( $lead_ids ) {
	$in = implode( ',', array_map( 'intval', $lead_ids ) );
	foreach ( array( LNKup_Org_Growth_Tables::ACTIVITY, LNKup_Org_Growth_Tables::TASKS, LNKup_Org_Growth_Tables::TAGS, LNKup_Org_Growth_Tables::ACCOUNTS, LNKup_Org_Growth_Tables::MILESTONES ) as $suffix ) {
		$wpdb->query( 'DELETE FROM ' . LNKup_Org_Growth_Tables::table( $suffix ) . " WHERE lead_id IN ({$in})" );
	}
	$wpdb->query( 'DELETE FROM ' . LNKup_Org_Leads::table() . " WHERE id IN ({$in})" );
}
$wpdb->delete( LNKup_Connector_Tables::connectors_table(), array( 'id' => $connector_id ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$snap} WHERE city_key LIKE %s", '%ogsi%' . $wpdb->esc_like( $run ) . '%' ) );
// phpcs:enable
if ( $term_id ) {
	wp_delete_term( $term_id, 'lnkup_vendor_category' );
}
if ( null === $saved ) {
	delete_option( LNKup_Org_Growth::SETTINGS_KEY );
} else {
	update_option( LNKup_Org_Growth::SETTINGS_KEY, $saved, false );
}
delete_option( LNKup_Org_Supply_Intel::REBUILD_OPTION );
lnkup_t_finish();
LNKup_Org_Supply_Intel::build_snapshot();
