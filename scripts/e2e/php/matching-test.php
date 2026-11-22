<?php
/**
 * Matching & Discovery OS integration test: vendor matching, organizer recruitment,
 * privacy, invite guardrails, tours, and discovery radius pagination.
 *
 * Builds its own fixtures (unique users/events) and removes them on exit.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/matching-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Matching & discovery\n";

$original_settings = get_option( 'lnkup_settings', array() );
$settings          = is_array( $original_settings ) ? $original_settings : array();
update_option(
	'lnkup_settings',
	array_merge(
		$settings,
		array(
			'matching_enabled'                     => true,
			'matching_vendor_discovery_enabled'    => true,
			'matching_organizer_discovery_enabled' => true,
			'matching_tours_enabled'               => true,
			'match_invite_vendor_weekly_cap'       => 5,
			'platform_prelaunch_mode'              => false,
		)
	),
	false
);

LNKup_Match_Categories::seed_groups();
$food    = term_exists( 'Food Vendor', 'lnkup_vendor_category' );
$food_id = (int) ( is_array( $food ) ? $food['term_id'] : $food );
$cloth   = term_exists( 'Clothing', 'lnkup_vendor_category' );
$cloth_id = (int) ( is_array( $cloth ) ? $cloth['term_id'] : $cloth );
lnkup_t_assert( $food_id > 0 && $cloth_id > 0, 'seed categories exist' );

$cities = array(
	'Charlotte' => array( 'NC', 35.23, -80.84 ),
	'Atlanta'   => array( 'GA', 33.75, -84.39 ),
	'Nashville' => array( 'TN', 36.16, -86.78 ),
	'Raleigh'   => array( 'NC', 35.78, -78.64 ),
	'Dallas'    => array( 'TX', 32.78, -96.80 ),
	'Denver'    => array( 'CO', 39.74, -104.99 ),
	'Miami'     => array( 'FL', 25.76, -80.19 ),
);
// eval-file runs inside a function scope; helpers read fixtures from $GLOBALS.
$GLOBALS['lnkup_m_cities'] = $cities;
$GLOBALS['food_id']        = $food_id;

/**
 * Published event + one open opportunity, indexed.
 */
function lnkup_m_event( int $organizer, string $city, array $opts = array() ): array {
	$cities = $GLOBALS['lnkup_m_cities'];
	list( $state, $lat, $lng ) = $cities[ $city ];
	$event = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'draft', 'post_title' => 'Match test ' . $city . ' ' . wp_generate_password( 4, false ), 'post_author' => $organizer ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $event );
	update_post_meta( $event, '_lnkup_organizer_user_id', $organizer );
	update_post_meta( $event, '_lnkup_city', $city );
	update_post_meta( $event, '_lnkup_state', $state );
	update_post_meta( $event, '_lnkup_lat', (string) $lat );
	update_post_meta( $event, '_lnkup_lng', (string) $lng );
	update_post_meta( $event, '_lnkup_event_date', $opts['date'] ?? gmdate( 'Y-m-d', strtotime( '+60 days' ) ) );
	wp_update_post( array( 'ID' => $event, 'post_status' => 'publish' ) );

	$opp = wp_insert_post( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'publish', 'post_title' => 'Booth', 'post_author' => $organizer ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $opp );
	update_post_meta( $opp, '_lnkup_event_id', $event );
	update_post_meta( $opp, '_lnkup_organizer_user_id', $organizer );
	update_post_meta( $opp, '_lnkup_opportunity_status', 'open' );
	update_post_meta( $opp, '_lnkup_spots_total', 10 );
	update_post_meta( $opp, '_lnkup_spots_remaining', $opts['spots'] ?? 10 );
	update_post_meta( $opp, '_lnkup_vendor_fee', 100 );
	update_post_meta( $opp, '_lnkup_required_documents', 'none' );
	update_post_meta( $opp, '_lnkup_application_deadline', $opts['deadline'] ?? gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );
	wp_set_object_terms( $opp, array( (int) ( $opts['category'] ?? $GLOBALS['food_id'] ) ), 'lnkup_vendor_category' );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	return array( (int) $event, (int) $opp );
}

/**
 * Vendor with a profile at a city centroid (no geocoding calls).
 */
function lnkup_m_vendor( string $city, int $category, array $prefs = array() ): array {
	$cities = $GLOBALS['lnkup_m_cities'];
	$user = lnkup_t_user( 'lnkup_vendor' );
	list( $state, $lat, $lng ) = $cities[ $city ];
	$profile = wp_insert_post( array( 'post_type' => 'lnkup_vendor_profile', 'post_status' => 'publish', 'post_title' => 'Match vendor ' . $city . ' ' . $user ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $profile );
	update_post_meta( $profile, '_lnkup_owner_user_id', $user );
	update_post_meta( $profile, '_lnkup_business_name', 'Match vendor ' . $user );
	update_post_meta( $profile, '_lnkup_contact_email', 'private' . $user . '@example.test' );
	update_post_meta( $profile, '_lnkup_contact_phone', '555-0100' );
	update_post_meta( $profile, '_lnkup_city', $city );
	update_post_meta( $profile, '_lnkup_state', $state );
	update_post_meta( $profile, LNKup_Match_Vendor_Index::GEO_KEY_META, strtolower( $city . '|' . $state . '|' ) );
	update_post_meta( $profile, '_lnkup_geo_lat', (string) $lat );
	update_post_meta( $profile, '_lnkup_geo_lng', (string) $lng );
	wp_set_object_terms( $profile, array( $category ), 'lnkup_vendor_category' );
	LNKup_Match_REST::apply_preferences( $profile, array_merge( array( 'primary_category' => $category, 'cities_served' => array( $city ) ), $prefs ) );
	return array( $user, (int) $profile );
}

/**
 * Opportunity IDs recommended to a vendor (recomputed first).
 */
function lnkup_m_recommended( int $user, int $profile, string $section = 'recommended' ): array {
	LNKup_Match_Service::compute_for_vendor( $profile );
	wp_set_current_user( $user );
	$result = LNKup_Match_Service::vendor_recommendations( $user, array( 'section' => $section, 'per_page' => 24 ) );
	return is_wp_error( $result ) ? array() : array_map( 'intval', wp_list_pluck( $result['items'], 'opportunity_id' ) );
}

$org_a = lnkup_t_user( 'lnkup_organizer' );
$org_b = lnkup_t_user( 'lnkup_organizer' );

list( $e_clt, $o_clt )         = lnkup_m_event( $org_a, 'Charlotte' );
list( , $o_clothing )          = lnkup_m_event( $org_a, 'Charlotte', array( 'category' => $cloth_id ) );
list( , $o_expired )           = lnkup_m_event( $org_a, 'Charlotte', array( 'deadline' => gmdate( 'Y-m-d', strtotime( '-2 days' ) ) ) );
list( , $o_full )              = lnkup_m_event( $org_a, 'Charlotte', array( 'spots' => 0 ) );
list( , $o_atl )               = lnkup_m_event( $org_a, 'Atlanta' );
list( $e_b, $o_b )             = lnkup_m_event( $org_b, 'Charlotte' );

list( $v_user, $v_profile )       = lnkup_m_vendor( 'Charlotte', $food_id );
list( $hidden_user, $hidden_prof ) = lnkup_m_vendor( 'Charlotte', $food_id, array( 'discoverable' => false ) );
list( , $cloth_prof )             = lnkup_m_vendor( 'Charlotte', $cloth_id );

echo "Vendor matching\n";
$recs = lnkup_m_recommended( $v_user, $v_profile );
lnkup_t_assert( in_array( $o_clt, $recs, true ), 'Charlotte food vendor sees the Charlotte food event' );
lnkup_t_assert( ! in_array( $o_clothing, $recs, true ), 'unrelated mandatory category is excluded' );
lnkup_t_assert( ! in_array( $o_expired, $recs, true ), 'expired deadline is excluded' );
lnkup_t_assert( ! in_array( $o_full, $recs, true ), 'full opportunity is not recommended' );
lnkup_t_assert( ! in_array( $o_atl, $recs, true ), 'out-of-market Atlanta event is not recommended' );

$first = null;
foreach ( LNKup_Match_Service::vendor_recommendations( $v_user, array( 'section' => 'recommended' ) )['items'] as $item ) {
	if ( (int) $item['opportunity_id'] === $o_clt ) {
		$first = $item;
	}
}
lnkup_t_assert( isset( $first['match']['band'] ) && ! isset( $first['match']['score'] ), 'vendors see a match band, never a raw score' );
lnkup_t_assert( in_array( 'In Charlotte, one of your selected markets', (array) ( $first['match']['reasons'] ?? array() ), true ), 'explanation cites the selected market' );

LNKup_Match_REST::apply_preferences( $v_profile, array( 'cities_served' => array( 'Charlotte', 'Atlanta' ) ) );
lnkup_t_assert( in_array( $o_atl, lnkup_m_recommended( $v_user, $v_profile ), true ), 'adding Atlanta to preferred cities updates recommendations' );
lnkup_t_assert( in_array( $o_clt, lnkup_m_recommended( $v_user, $v_profile, 'preferred_markets' ), true ), 'preferred markets section lists Charlotte' );

LNKup_Vendor_Personalization::toggle_favorite( $v_user, 'opportunities', $o_clt );
lnkup_t_assert( LNKup_Vendor_Personalization::is_favorite( $v_user, 'opportunities', $o_clt ), 'vendor saves the event' );

echo "Organizer recruitment and privacy\n";
foreach ( array( $o_clt, $o_atl, $o_b ) as $opp ) {
	LNKup_Match_Service::compute_for_opportunity( $opp );
}
wp_set_current_user( $org_a );
$cands = LNKup_Match_Service::event_vendor_candidates( $org_a, $e_clt, array( 'exclude_applied' => true ) );
$ids   = array_map( 'intval', wp_list_pluck( $cands['items'], 'profile_id' ) );
lnkup_t_assert( in_array( $v_profile, $ids, true ), 'matching discoverable vendor is a candidate' );
lnkup_t_assert( ! in_array( $hidden_prof, $ids, true ), 'opted-out vendor is hidden from unrelated organizer' );
lnkup_t_assert( ! in_array( $cloth_prof, $ids, true ), 'wrong-category vendor is not a candidate' );
$card = $cands['items'][ array_search( $v_profile, $ids, true ) ] ?? array();
lnkup_t_assert( ! array_key_exists( 'email', $card ) && ! array_key_exists( 'phone', $card ), 'candidate card has no private contact fields' );
lnkup_t_assert( false === ( $card['can_view_contact'] ?? null ), 'contact is locked before any relationship' );

$crm = LNKup_Vendor_CRM_Profile::get( $v_profile, $org_b );
lnkup_t_same( '', $crm['email'], 'unrelated organizer CRM view hides email' );
lnkup_t_same( array(), $crm['documents'], 'unrelated organizer CRM view hides compliance documents' );

$discover = lnkup_get_discoverable_vendors( array( 'per_page' => 48, 'organizer_id' => $org_b, 'q' => 'Match vendor' ) );
lnkup_t_assert( ! in_array( $hidden_prof, array_map( 'intval', wp_list_pluck( $discover['posts'], 'ID' ) ), true ), 'directory discover scope respects opt-out' );

$req = new WP_REST_Request( 'GET', '/lnkup/v1/match/organizer/events/' . $e_clt . '/vendors' );
wp_set_current_user( $org_b );
lnkup_t_same( 403, rest_do_request( $req )->get_status(), 'another organizer cannot list candidates for this event' );
wp_set_current_user( $org_a );
lnkup_t_same( 200, rest_do_request( $req )->get_status(), 'owner lists candidates' );

$preview = LNKup_Recruitment::preview( $org_a, $e_clt, $o_clt, array( $v_profile, $hidden_prof ), 'Hi!' );
$supp    = array_column( $preview['suppressed'], 'reason', 'profile_id' );
lnkup_t_same( array( $v_profile ), array_map( 'intval', wp_list_pluck( $preview['recipients'], 'profile_id' ) ), 'preview keeps the eligible vendor' );
lnkup_t_same( 'not_discoverable', $supp[ $hidden_prof ] ?? null, 'preview suppresses the opted-out vendor with a reason' );

$job = LNKup_Recruitment::create_job( $org_a, $e_clt, $o_clt, array( $v_profile ), 'Hi!' );
lnkup_t_assert( ! is_wp_error( $job ) && 1 === $job['total'], 'batch invite job queued' );
LNKup_Recruitment::process_job( $job['id'] );
$job = LNKup_Recruitment::job( $org_a, $job['id'] );
lnkup_t_same( 'complete', $job['status'], 'batch job completes' );
lnkup_t_same( 1, $job['sent'], 'batch job sent one invite' );
$stages = LNKup_Recruitment::stages_for_event( $org_a, $e_clt, array( $v_profile ) );
lnkup_t_same( 'invited', $stages[ $v_profile ] ?? null, 'pipeline marks vendor invited (not booked)' );
lnkup_t_same( 'already_invited', LNKup_Recruitment::invite_block_reason( $org_a, $e_clt, $o_clt, $v_profile ), 'repeat invite is suppressed' );
lnkup_t_same( false, LNKup_Capabilities::organizer_can_view_vendor_contact( $org_a, $v_profile ), 'a sent invite alone does not unlock contact details' );
$job_other = LNKup_Recruitment::job( $org_b, $job['id'] );
lnkup_t_assert( is_wp_error( $job_other ), 'another organizer cannot read the invite job' );

update_option( 'lnkup_settings', array_merge( get_option( 'lnkup_settings' ), array( 'match_invite_vendor_weekly_cap' => 1 ) ), false );
lnkup_t_same( 'vendor_weekly_cap', LNKup_Recruitment::invite_block_reason( $org_b, $e_b, $o_b, $v_profile ), 'vendor weekly invite cap blocks a second cold organizer' );
$direct = LNKup_Vendor_Invites::create( $org_b, $v_user, array( 'event_id' => $e_b, 'opportunity_id' => $o_b ) );
lnkup_t_assert( is_wp_error( $direct ) && 'invite_rate_limited' === $direct->get_error_code(), 'direct invite API enforces the same cap' );
update_option( 'lnkup_settings', array_merge( get_option( 'lnkup_settings' ), array( 'match_invite_vendor_weekly_cap' => 5 ) ), false );

echo "Apply\n";
wp_set_current_user( $v_user );
$app = LNKup_Applications::submit_with_profile( $v_user, $o_clt, array( 'accepted_terms' => 1, 'selected_category' => 'Food Vendor' ) );
lnkup_t_assert( ! is_wp_error( $app ), 'vendor applies' . ( is_wp_error( $app ) ? ' (' . $app->get_error_code() . ')' : '' ) );
if ( ! is_wp_error( $app ) ) {
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $app );
}
$dup = LNKup_Applications::submit_with_profile( $v_user, $o_clt, array( 'accepted_terms' => 1, 'selected_category' => 'Food Vendor' ) );
lnkup_t_assert( is_wp_error( $dup ), 'duplicate application is prevented' );
lnkup_t_assert( ! in_array( $o_clt, lnkup_m_recommended( $v_user, $v_profile ), true ), 'applied opportunity leaves recommendations' );
$stages = LNKup_Recruitment::stages_for_event( $org_a, $e_clt, array( $v_profile ) );
lnkup_t_same( 'applied', $stages[ $v_profile ] ?? null, 'pipeline advances to applied' );
lnkup_t_same( true, LNKup_Capabilities::organizer_can_view_vendor_contact( $org_a, $v_profile ), 'applying unlocks contact for that organizer' );
lnkup_t_same( false, LNKup_Capabilities::organizer_can_view_vendor_contact( $org_b, $v_profile ), 'other organizers stay locked out' );

echo "Tours\n";
$stops = array();
foreach ( array_keys( $cities ) as $city ) {
	$stops[ $city ] = lnkup_m_event( $org_a, $city );
}
$tour = LNKup_Tours::save( $org_a, array( 'title' => 'Match test tour', 'type' => 'tour', 'event_ids' => wp_list_pluck( array_values( $stops ), 0 ) ) );
lnkup_t_assert( ! is_wp_error( $tour ), 'organizer creates a seven-stop tour' );
$tour_id = is_wp_error( $tour ) ? 0 : (int) $tour['id'];
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $tour_id );
$foreign = LNKup_Tours::save( $org_b, array( 'title' => 'Hijack', 'event_ids' => array( $stops['Miami'][0] ) ) );
lnkup_t_assert( is_wp_error( $foreign ), 'another organizer cannot attach these events' );

list( $t_user, $t_profile ) = lnkup_m_vendor( 'Atlanta', $food_id, array( 'cities_served' => array( 'Atlanta', 'Charlotte', 'Nashville' ) ) );
LNKup_Match_Service::compute_for_vendor( $t_profile );
$detail = LNKup_Tours::detail( $tour_id, $t_user );
lnkup_t_same( 3, $detail['matching_stops'], 'vendor matches exactly three stops' );
$section = LNKup_Tours::vendor_tour_section( LNKup_Match_Service::vendor_inputs( array( LNKup_Match_Vendor_Index::get_row( $t_profile ) ) )[ $t_profile ], 'tours', 1, 12 );
lnkup_t_same( 3, $section['items'][0]['matching_stops'] ?? null, 'tour section groups the three matching stops into one card' );

update_post_meta( $stops['Charlotte'][1], '_lnkup_spots_remaining', 0 );
LNKup_Marketplace_Index::sync_opportunity( $stops['Charlotte'][1] );
LNKup_Match_Service::compute_for_vendor( $t_profile );
$detail   = LNKup_Tours::detail( $tour_id, $t_user );
$by_city  = array_column( $detail['stops'], null, 'city' );
lnkup_t_same( false, $by_city['Charlotte']['accepting'], 'the full stop stops accepting vendors' );
lnkup_t_same( true, $by_city['Atlanta']['accepting'] && $by_city['Nashville']['accepting'], 'other stops stay independently available' );
lnkup_t_same( 2, $detail['matching_stops'], 'matching stops drop to two' );

echo "Discovery radius pagination\n";
$page1 = LNKup_Discovery::search( array( 'lat' => 35.23, 'lng' => -80.84, 'radius' => 25, 'per_page' => 2, 'page' => 1, 'q' => 'match test' ) );
$all   = LNKup_Discovery::search( array( 'lat' => 35.23, 'lng' => -80.84, 'radius' => 25, 'per_page' => 48, 'page' => 1, 'q' => 'match test' ) );
lnkup_t_same( count( $all['items'] ), $page1['total'], 'radius total counts only in-radius rows' );
lnkup_t_same( (int) ceil( $page1['total'] / 2 ), $page1['pages'], 'radius pages are consistent' );
$outside = array_filter( $all['items'], static function ( $item ) {
	return isset( $item['distance_miles'] ) && $item['distance_miles'] > 25;
} );
lnkup_t_same( 0, count( $outside ), 'no result lies outside the radius' );

// Cleanup derived rows for fixture organizers.
foreach ( array( $org_a, $org_b ) as $org ) {
	$wpdb->delete( LNKup_Match_Tables::pipeline_table(), array( 'organizer_user_id' => $org ) );
	$wpdb->delete( LNKup_Match_Tables::jobs_table(), array( 'organizer_user_id' => $org ) );
	foreach ( get_posts( array( 'post_type' => 'lnkup_vendor_invite', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_lnkup_organizer_user_id', 'meta_value' => $org ) ) as $invite ) {
		wp_delete_post( $invite, true );
	}
}
update_option( 'lnkup_settings', $original_settings, false );
lnkup_t_finish();
