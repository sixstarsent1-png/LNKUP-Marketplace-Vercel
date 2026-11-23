<?php
/**
 * Organization team members reach exactly the events and tours their membership covers, CRM
 * views read the owner's records for those events, tour money is hidden from roles without
 * view_financials, and the tour summary carries per-stop operations and data warnings.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/team-scope-rollup-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Team scope and tour rollup\n";

$saved_settings = get_option( 'lnkup_settings', array() );
$settings       = (array) $saved_settings;
$settings['trust_org_teams']       = true;
$settings['portfolio_os_enabled']  = true;
$settings['organizer_ops_v2_enabled'] = true;
$settings['organizer_document_review_enabled'] = false;
update_option( 'lnkup_settings', $settings, false );

/**
 * Published post removed at the end of the run.
 *
 * @param string $type Post type.
 * @param array  $meta Meta.
 */
function lnkup_ts_post( string $type, array $meta = array() ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Team scope ' . $type, 'meta_input' => $meta ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

/**
 * Active organization membership.
 */
function lnkup_ts_member( int $owner, int $user, string $role, string $scope, int $scope_id = 0 ): void {
	global $wpdb;
	$wpdb->insert(
		LNKup_Trust_Tables::org_members_table(),
		array( 'org_owner_id' => $owner, 'user_id' => $user, 'role' => $role, 'scope_type' => $scope, 'scope_id' => $scope_id, 'status' => 'active', 'invited_by' => $owner, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) )
	);
	$GLOBALS['lnkup_ts_members'][] = (int) $wpdb->insert_id;
}

/**
 * REST status as a user.
 */
function lnkup_ts_status( int $user, string $method, string $route, array $body = array() ): array {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, $route );
	if ( $body ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	return array( $res->get_status(), $res->get_data() );
}

$owner = lnkup_t_user( 'lnkup_organizer' );
$other = lnkup_t_user( 'lnkup_organizer' );
$tour  = lnkup_ts_post( 'lnkup_tour', array( '_lnkup_organizer_user_id' => $owner, '_lnkup_tour_type' => 'tour' ) );
$stop1 = lnkup_ts_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $owner, '_lnkup_tour_id' => $tour, '_lnkup_city' => 'Charlotte', '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ) ) );
$stop2 = lnkup_ts_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $owner, '_lnkup_tour_id' => $tour, '_lnkup_city' => 'Atlanta' ) );
$solo  = lnkup_ts_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $owner, '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+40 days' ) ) ) );
$opp1  = lnkup_ts_post( 'lnkup_opportunity', array( '_lnkup_event_id' => $stop1, '_lnkup_organizer_user_id' => $owner, '_lnkup_opportunity_status' => 'open', '_lnkup_vendor_fee' => '120', '_lnkup_spots_total' => 4, '_lnkup_required_documents' => 'none' ) );
$opp3  = lnkup_ts_post( 'lnkup_opportunity', array( '_lnkup_event_id' => $solo, '_lnkup_organizer_user_id' => $owner, '_lnkup_opportunity_status' => 'open', '_lnkup_vendor_fee' => '60', '_lnkup_spots_total' => 4, '_lnkup_required_documents' => 'none' ) );
$vendor = lnkup_t_user( 'lnkup_vendor' );
$profile = lnkup_ts_post( 'lnkup_vendor_profile', array( '_lnkup_owner_user_id' => $vendor, '_lnkup_business_name' => 'Team Scope Vendor' ) );
$app_stop = lnkup_ts_post( 'lnkup_application', array( '_lnkup_event_id' => $stop1, '_lnkup_opportunity_id' => $opp1, '_lnkup_organizer_user_id' => $owner, '_lnkup_vendor_user_id' => $vendor, '_lnkup_vendor_profile_id' => $profile, '_lnkup_application_status' => 'paid' ) );
$app_solo = lnkup_ts_post( 'lnkup_application', array( '_lnkup_event_id' => $solo, '_lnkup_opportunity_id' => $opp3, '_lnkup_organizer_user_id' => $owner, '_lnkup_vendor_user_id' => $vendor, '_lnkup_vendor_profile_id' => $profile, '_lnkup_application_status' => 'submitted' ) );

$reviewer_one = lnkup_t_user( 'subscriber' );
$ops_one      = lnkup_t_user( 'subscriber' );
$finance_tour = lnkup_t_user( 'subscriber' );
$ops_tour     = lnkup_t_user( 'subscriber' );
lnkup_ts_member( $owner, $reviewer_one, 'reviewer', 'event', $solo );
lnkup_ts_member( $owner, $ops_one, 'ops_staff', 'event', $stop1 );
lnkup_ts_member( $owner, $finance_tour, 'finance', 'tour', $tour );
lnkup_ts_member( $owner, $ops_tour, 'ops_staff', 'tour', $tour );

echo "CRM routes follow the membership\n";
list( $code ) = lnkup_ts_status( $reviewer_one, 'GET', "/lnkup/v1/crm/applications/{$app_solo}/intel" );
lnkup_t_same( 200, $code, 'a one-event reviewer reads applicant intel on that event' );
list( $code ) = lnkup_ts_status( $reviewer_one, 'GET', "/lnkup/v1/crm/applications/{$app_stop}/intel" );
lnkup_t_same( 403, $code, 'the same reviewer is refused on another event' );
list( $code ) = lnkup_ts_status( $reviewer_one, 'GET', "/lnkup/v1/crm/events/{$solo}/roster" );
lnkup_t_same( 403, $code, 'a reviewer without check-in rights cannot open the event-day roster' );
list( $code ) = lnkup_ts_status( $ops_one, 'GET', "/lnkup/v1/crm/events/{$stop1}/roster" );
lnkup_t_same( 200, $code, 'one-event ops staff open the roster for their event' );
list( $code ) = lnkup_ts_status( $ops_one, 'GET', "/lnkup/v1/crm/events/{$solo}/roster" );
lnkup_t_same( 403, $code, 'one-event ops staff are refused on another event' );
list( $code ) = lnkup_ts_status( $ops_one, 'GET', '/lnkup/v1/crm/lists' );
lnkup_t_same( 403, $code, 'organization-wide CRM lists stay owner-only' );
list( $code ) = lnkup_ts_status( $other, 'GET', "/lnkup/v1/crm/applications/{$app_solo}/intel" );
lnkup_t_same( 403, $code, 'another organizer is refused' );
list( $code ) = lnkup_ts_status( $owner, 'GET', "/lnkup/v1/crm/applications/{$app_solo}/intel" );
lnkup_t_same( 200, $code, 'the owner still reads applicant intel' );

echo "Tour views follow the membership\n";
list( $code, $owner_summary ) = lnkup_ts_status( $owner, 'GET', "/lnkup/v1/portfolio/tours/{$tour}/summary" );
lnkup_t_same( 200, $code, 'the owner reads the tour summary' );
lnkup_t_assert( isset( $owner_summary['money'] ), 'the owner sees tour money' );
list( $code, $fin ) = lnkup_ts_status( $finance_tour, 'GET', "/lnkup/v1/portfolio/tours/{$tour}/summary" );
lnkup_t_same( 200, $code, 'a tour finance member reads the summary' );
lnkup_t_assert( isset( $fin['money'] ) && empty( $fin['money_hidden'] ), 'finance sees tour money' );
list( $code, $ops ) = lnkup_ts_status( $ops_tour, 'GET', "/lnkup/v1/portfolio/tours/{$tour}/summary" );
lnkup_t_same( 200, $code, 'tour ops staff read the summary' );
lnkup_t_assert( ! isset( $ops['money'] ) && ! empty( $ops['money_hidden'] ) && ! isset( $ops['stops'][0]['money'] ), 'ops staff do not see tour or stop money' );
list( $code ) = lnkup_ts_status( $ops_one, 'GET', "/lnkup/v1/portfolio/tours/{$tour}/summary" );
lnkup_t_same( 404, $code, 'an event-only member is not a tour viewer' );
list( $code ) = lnkup_ts_status( $finance_tour, 'PATCH', "/lnkup/v1/portfolio/tours/{$tour}", array( 'title' => 'Renamed by staff' ) );
lnkup_t_assert( in_array( $code, array( 403, 404 ), true ), 'team members cannot edit the tour' );
list( $code ) = lnkup_ts_status( $other, 'GET', "/lnkup/v1/portfolio/tours/{$tour}/summary" );
lnkup_t_same( 404, $code, 'another organizer cannot read the tour' );

echo "Per-stop operations and warnings\n";
$by_event = array();
foreach ( (array) ( $owner_summary['stops'] ?? array() ) as $stop ) {
	$by_event[ (int) $stop['event_id'] ] = $stop;
}
lnkup_t_same( 2, count( $by_event ), 'both stops are in the rollup' );
$s1 = $by_event[ $stop1 ] ?? array();
$s2 = $by_event[ $stop2 ] ?? array();
foreach ( array( 'readiness_percent', 'tasks_open', 'tasks_overdue', 'documents_outstanding', 'checked_in' ) as $key ) {
	lnkup_t_assert( array_key_exists( $key, (array) ( $s1['operations'] ?? array() ) ), "stop operations include {$key}" );
}
lnkup_t_assert( is_int( $s1['operations']['readiness_percent'] ?? null ), 'readiness is measured when Organizer OS is on' );
lnkup_t_assert( array_key_exists( 'documents_outstanding', (array) $s1['operations'] ) && null === $s1['operations']['documents_outstanding'], 'documents read as not measured while document review is off' );
lnkup_t_assert( in_array( 'no_event_date', (array) ( $s2['data_warnings'] ?? array() ), true ), 'a stop without a date is flagged' );
lnkup_t_assert( in_array( 'no_vendor_slots', (array) ( $s2['data_warnings'] ?? array() ), true ), 'a stop without vendor slots is flagged' );
lnkup_t_assert( in_array( 'paid_without_payment_record', (array) ( $s1['data_warnings'] ?? array() ), true ), 'a paid application with no payment record is flagged, not counted as money' );
lnkup_t_same( 0, (int) ( $s1['money']['paid_bookings'] ?? -1 ), 'money still comes only from payment records' );
lnkup_t_assert( in_array( 'stops_incomplete_data', wp_list_pluck( (array) $owner_summary['tasks'], 'key' ), true ), 'incomplete stops surface as a tour task' );

foreach ( (array) ( $GLOBALS['lnkup_ts_members'] ?? array() ) as $member_row ) {
	$wpdb->delete( LNKup_Trust_Tables::org_members_table(), array( 'id' => $member_row ), array( '%d' ) );
}
update_option( 'lnkup_settings', $saved_settings, false );
wp_set_current_user( 0 );
lnkup_t_finish();
