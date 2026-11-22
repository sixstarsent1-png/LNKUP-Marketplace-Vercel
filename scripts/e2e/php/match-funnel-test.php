<?php
/**
 * Phases 8-9: recommendation funnel (client events, impressions, clicks, attribution) and quality report.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/match-funnel-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Match funnel\n";

$GLOBALS['lnkup_mf_settings'] = get_option( 'lnkup_settings', array() );
update_option(
	'lnkup_settings',
	array_merge(
		(array) $GLOBALS['lnkup_mf_settings'],
		array(
			'matching_enabled'                  => true,
			'matching_vendor_discovery_enabled' => true,
			'platform_prelaunch_mode'           => false,
		)
	),
	false
);
LNKup_Match_Categories::seed_groups();
$food            = term_exists( 'Food Vendor', 'lnkup_vendor_category' );
$GLOBALS['food'] = (int) ( is_array( $food ) ? $food['term_id'] : $food );
$table           = LNKup_Marketplace_Analytics::table_name();

/**
 * Charlotte food opportunity, indexed.
 */
function lnkup_mf_opp( int $organizer ): int {
	$event = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'draft', 'post_title' => 'Funnel test ' . wp_generate_password( 4, false ), 'post_author' => $organizer ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $event );
	foreach ( array( 'organizer_user_id' => $organizer, 'city' => 'Charlotte', 'state' => 'NC', 'lat' => '35.23', 'lng' => '-80.84', 'event_date' => wp_date( 'Y-m-d', time() + 60 * DAY_IN_SECONDS ) ) as $key => $value ) {
		update_post_meta( $event, '_lnkup_' . $key, $value );
	}
	wp_update_post( array( 'ID' => $event, 'post_status' => 'publish' ) );
	$opp = wp_insert_post( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'publish', 'post_title' => 'Funnel booth', 'post_author' => $organizer ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $opp );
	foreach ( array( 'event_id' => $event, 'organizer_user_id' => $organizer, 'opportunity_status' => 'open', 'spots_total' => 10, 'spots_remaining' => 10, 'vendor_fee' => 100, 'required_documents' => 'none', 'application_deadline' => wp_date( 'Y-m-d', time() + 50 * DAY_IN_SECONDS ) ) as $key => $value ) {
		update_post_meta( $opp, '_lnkup_' . $key, $value );
	}
	wp_set_object_terms( $opp, array( $GLOBALS['food'] ), 'lnkup_vendor_category' );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	return (int) $opp;
}

/**
 * POST /marketplace/track/event as the current user.
 */
function lnkup_mf_track( array $body ) {
	$req = new WP_REST_Request( 'POST', '/lnkup/v1/marketplace/track/event' );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_body( wp_json_encode( $body ) );
	return rest_do_request( $req );
}

/**
 * Analytics rows of one type for a user.
 */
function lnkup_mf_rows( string $type, int $user ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Marketplace_Analytics::table_name() . ' WHERE event_type = %s AND user_id = %d ORDER BY id ASC', $type, $user ), ARRAY_A );
}

$org     = lnkup_t_user( 'lnkup_organizer' );
$vendor  = lnkup_t_user( 'lnkup_vendor' );
$profile = wp_insert_post( array( 'post_type' => 'lnkup_vendor_profile', 'post_status' => 'publish', 'post_title' => 'Funnel vendor' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $profile );
foreach ( array( 'owner_user_id' => $vendor, 'business_name' => 'Funnel vendor', 'city' => 'Charlotte', 'state' => 'NC', 'geo_lat' => '35.23', 'geo_lng' => '-80.84' ) as $key => $value ) {
	update_post_meta( $profile, '_lnkup_' . $key, $value );
}
update_post_meta( $profile, LNKup_Match_Vendor_Index::GEO_KEY_META, 'charlotte|nc|' );
wp_set_object_terms( $profile, array( $GLOBALS['food'] ), 'lnkup_vendor_category' );
LNKup_Match_REST::apply_preferences( $profile, array( 'primary_category' => $GLOBALS['food'], 'cities_served' => array( 'Charlotte' ) ) );
$o1 = lnkup_mf_opp( $org );
$o2 = lnkup_mf_opp( $org );
LNKup_Match_Service::compute_for_vendor( $profile );

echo "Client event guard\n";
wp_set_current_user( 0 );
lnkup_t_same( 202, lnkup_mf_track( array( 'event_type' => 'totally_made_up' ) )->get_status(), 'unknown event types are ignored (202)' );
lnkup_t_same( 'unknown_event', lnkup_mf_track( array( 'event_type' => 'match_impression' ) )->get_data()['ignored'] ?? null, 'clients cannot send server-recorded events' );
lnkup_t_same( 'sign_in_required', lnkup_mf_track( array( 'event_type' => 'match_click', 'opportunity_id' => $o1 ) )->get_data()['ignored'] ?? null, 'match clicks need a signed-in user' );
lnkup_t_same( 200, lnkup_mf_track( array( 'event_type' => 'market_page_view', 'data' => array( 'market_slug' => 'x' ) ) )->get_status(), 'known client events are still recorded anonymously' );

echo "Impressions and clicks\n";
wp_set_current_user( $vendor );
$req = new WP_REST_Request( 'GET', '/lnkup/v1/match/vendor/recommendations' );
$req->set_param( 'section', 'recommended' );
$res = rest_do_request( $req );
lnkup_t_same( 200, $res->get_status(), 'recommendations load' );
$impressions = lnkup_mf_rows( 'match_impression', $vendor );
$imp         = $impressions ? json_decode( (string) end( $impressions )['event_data'], true ) : array();
$shown       = array_map( 'intval', wp_list_pluck( (array) ( $imp['items'] ?? array() ), 'opportunity_id' ) );
lnkup_t_assert( in_array( $o1, $shown, true ) && in_array( $o2, $shown, true ), 'impression records which opportunities were shown' );
lnkup_t_assert( ! empty( $imp['items'][0]['band'] ) && 1 === (int) $imp['items'][0]['position'], 'impression items carry band and position' );
lnkup_t_same( LNKup_Match_Settings::rules_version(), (int) ( $imp['rules_version'] ?? -1 ), 'impression carries the rules version' );

$click = lnkup_mf_track( array( 'event_type' => 'recommendation_clicked', 'opportunity_id' => $o1, 'data' => array( 'section' => 'recommended', 'position' => 1, 'email' => 'x@y.z', 'nested' => array( 1 ) ) ) );
lnkup_t_same( 200, $click->get_status(), 'legacy recommendation_clicked is accepted' );
$clicks = lnkup_mf_rows( 'match_click', $vendor );
lnkup_t_same( 1, count( $clicks ), 'and stored as match_click' );
$click_data = json_decode( (string) ( $clicks[0]['event_data'] ?? '' ), true );
lnkup_t_same( array( 'recommended', 1 ), array( $click_data['section'] ?? '', (int) ( $click_data['position'] ?? 0 ) ), 'click keeps section and position' );
lnkup_t_assert( ! isset( $click_data['email'] ) && ! isset( $click_data['nested'] ), 'click data is reduced to known fields' );
lnkup_t_assert( '' !== (string) ( $click_data['band'] ?? '' ), 'click band is filled from the cached match' );

echo "Attribution\n";
$app_for = static function ( int $opp ) use ( $vendor, $org, $profile ) {
	$app = wp_insert_post( array( 'post_type' => 'lnkup_application', 'post_status' => 'publish', 'post_title' => 'Funnel application' ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $app );
	foreach ( array( 'vendor_user_id' => $vendor, 'organizer_user_id' => $org, 'opportunity_id' => $opp, 'vendor_profile_id' => $profile, 'application_status' => 'submitted' ) as $key => $value ) {
		update_post_meta( $app, '_lnkup_' . $key, $value );
	}
	do_action( 'lnkup_application_submitted', $app );
	return (int) $app;
};
$a1   = $app_for( $o1 );
$a2   = $app_for( $o2 );
$attr = array();
foreach ( lnkup_mf_rows( 'match_rec_application', $vendor ) as $row ) {
	$attr[ (int) $row['opportunity_id'] ] = json_decode( (string) $row['event_data'], true );
}
lnkup_t_same( 'click', $attr[ $o1 ]['via'] ?? null, 'application after a click is attributed via click' );
lnkup_t_same( 'impression', $attr[ $o2 ]['via'] ?? null, 'application after only seeing it is attributed via impression' );
lnkup_t_same( $a2, (int) ( $attr[ $o2 ]['application_id'] ?? 0 ), 'attribution links the application' );
update_post_meta( $a1, '_lnkup_application_status', 'paid' );

echo "Quality report\n";
LNKup_Match_Quality::flush();
$report = LNKup_Match_Quality::report( 30 );
lnkup_t_assert( $report['totals']['impressions'] >= 2 && $report['totals']['clicks'] >= 1 && $report['totals']['applications'] >= 2, 'report totals count impressions, clicks and applications' );
lnkup_t_assert( $report['totals']['paid'] >= 1 && $report['totals']['approved'] >= 1, 'paid applications count as approved and paid' );
lnkup_t_assert( null !== $report['totals']['ctr'], 'CTR is computed' );
lnkup_t_assert( isset( $report['by_section']['recommended'] ), 'report breaks down by section' );
lnkup_t_assert( isset( $report['by_rules_version'][ (string) LNKup_Match_Settings::rules_version() ] ), 'report breaks down by rules version' );
lnkup_t_same( 'all', $report['cohort']['audience'], 'cohort split reports the vendor audience' );
$filtered = LNKup_Match_Quality::report( 30, array( 'rules_version' => 987654 ) );
lnkup_t_same( 0, $filtered['totals']['impressions'], 'filtering by an unused rules version shows nothing' );

echo "Throttle\n";
$who = 'u' . $vendor;
set_transient( 'lnkup_evt_rate_' . substr( md5( $who . gmdate( 'YmdHi' ) ), 0, 20 ), LNKup_Match_Funnel::RATE_PER_MINUTE, MINUTE_IN_SECONDS );
lnkup_t_same( 429, lnkup_mf_track( array( 'event_type' => 'apply_clicked' ) )->get_status(), 'more than the per-minute limit is refused' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d OR (event_type = 'market_page_view' AND event_data LIKE %s)", $vendor, '%"market_slug":"x"%' ) );
LNKup_Match_Quality::flush();
update_option( 'lnkup_settings', $GLOBALS['lnkup_mf_settings'], false );
lnkup_t_finish();
