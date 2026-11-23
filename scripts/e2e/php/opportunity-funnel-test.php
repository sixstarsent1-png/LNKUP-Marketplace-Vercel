<?php
/**
 * Marketplace funnel from records: view → apply start → submitted → approved → paid → checked in,
 * split by house and independent organizers, with test accounts excluded everywhere.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/opportunity-funnel-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Opportunity funnel\n";

$saved_core   = get_option( 'lnkup_settings', array() );
$saved_growth = get_option( LNKup_Org_Growth::SETTINGS_KEY, null );

/**
 * Published post removed at the end of the run.
 */
function lnkup_of_post( string $type, array $meta = array() ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Funnel ' . $type, 'meta_input' => $meta ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

/**
 * Event with one open booth for an organizer.
 *
 * @return int[] [event, opportunity]
 */
function lnkup_of_booth( int $organizer, int $spots = 5 ): array {
	$event = lnkup_of_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_city' => 'Raleigh', '_lnkup_state' => 'NC', '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+25 days' ) ) ) );
	$opp   = lnkup_of_post( 'lnkup_opportunity', array( '_lnkup_event_id' => $event, '_lnkup_organizer_user_id' => $organizer, '_lnkup_opportunity_status' => 'open', '_lnkup_vendor_fee' => '75', '_lnkup_spots_total' => $spots, '_lnkup_required_documents' => 'none', '_lnkup_application_deadline' => gmdate( 'Y-m-d', strtotime( '+15 days' ) ) ) );
	LNKup_Opportunity_Inventory::recompute( $opp );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	return array( $event, $opp );
}

/**
 * Vendor with a profile.
 */
function lnkup_of_vendor(): int {
	$vendor = lnkup_t_user( 'lnkup_vendor' );
	lnkup_of_post( 'lnkup_vendor_profile', array( '_lnkup_owner_user_id' => $vendor, '_lnkup_business_name' => 'Funnel Vendor ' . $vendor ) );
	return $vendor;
}

/**
 * Take a vendor as far as $until: view, draft, submit, approve, pay, checkin.
 *
 * @return int Application ID (0 when stopped before submit).
 */
function lnkup_of_journey( int $vendor, int $organizer, int $opp, int $event, string $until ): int {
	LNKup_Marketplace_Analytics::track( 'view', array(), $vendor, $opp );
	if ( 'view' === $until ) {
		return 0;
	}
	wp_set_current_user( $vendor );
	LNKup_Application_Drafts::upsert( $vendor, $opp, array( 'step' => 'review', 'fields' => array( 'selected_category' => 'General' ) ) );
	if ( 'draft' === $until ) {
		return 0;
	}
	$draft  = LNKup_Application_Drafts::get_for_opportunity( $vendor, $opp );
	$result = LNKup_Application_Drafts::finalize( (int) $draft['id'], $vendor, array( 'accepted_terms' => true ) );
	$app    = is_wp_error( $result ) ? 0 : (int) $result['application_id'];
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $app );
	if ( 'submit' === $until || ! $app ) {
		return $app;
	}
	wp_set_current_user( $organizer );
	LNKup_Applications::handle_organizer_action( $app, 'approve', $organizer );
	if ( 'approve' === $until ) {
		return $app;
	}
	$payment = LNKup_Payments::get_for_application( $app );
	if ( $payment ) {
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $payment->ID );
		LNKup_Financial_Service::finalize_payment( (int) $payment->ID, 'sim_funnel_' . $payment->ID, array( 'source' => 'test' ) );
	}
	if ( 'pay' === $until ) {
		return $app;
	}
	LNKup_Checkins::record( $app, 'checked_in', 'manual', $event );
	return $app;
}

$house_org = lnkup_t_user( 'lnkup_organizer' );
$indie_org = lnkup_t_user( 'lnkup_organizer' );
$test_org  = lnkup_t_user( 'lnkup_organizer' );
LNKup_Org_Growth::update_settings( array( 'org_growth_house_organizer_ids' => (string) $house_org ) );

$core = (array) $saved_core;
$core[ LNKup_Analytics_Exclusions::SETTING ] = (string) $test_org;
update_option( 'lnkup_settings', $core, false );
LNKup_Analytics_Exclusions::flush();

list( $h_event, $h_opp ) = lnkup_of_booth( $house_org );
list( $i_event, $i_opp ) = lnkup_of_booth( $indie_org );
list( $i_event2, $i_opp2 ) = lnkup_of_booth( $indie_org );
list( $t_event, $t_opp ) = lnkup_of_booth( $test_org );

// Independent organizer: one vendor through check-in, one stops at each earlier step.
$repeat = lnkup_of_vendor();
lnkup_of_journey( $repeat, $indie_org, $i_opp, $i_event, 'checkin' );
lnkup_of_journey( lnkup_of_vendor(), $indie_org, $i_opp, $i_event, 'pay' );
lnkup_of_journey( lnkup_of_vendor(), $indie_org, $i_opp, $i_event, 'approve' );
lnkup_of_journey( lnkup_of_vendor(), $indie_org, $i_opp, $i_event, 'submit' );
lnkup_of_journey( lnkup_of_vendor(), $indie_org, $i_opp, $i_event, 'draft' );
lnkup_of_journey( lnkup_of_vendor(), $indie_org, $i_opp, $i_event, 'view' );
lnkup_of_journey( $repeat, $indie_org, $i_opp2, $i_event2, 'pay' );
// House organizer: one paid booking.
lnkup_of_journey( lnkup_of_vendor(), $house_org, $h_opp, $h_event, 'pay' );
// Test vendor on the independent booth and a test organizer's booth: never counted.
$tester = lnkup_of_vendor();
update_user_meta( $tester, LNKup_Analytics_Exclusions::USER_META, '1' );
LNKup_Analytics_Exclusions::flush();
lnkup_of_journey( $tester, $indie_org, $i_opp, $i_event, 'checkin' );
lnkup_of_journey( lnkup_of_vendor(), $test_org, $t_opp, $t_event, 'checkin' );

$today  = current_time( 'Y-m-d' );
$report = LNKup_Opportunity_Funnel::report( array( 'from' => $today, 'to' => $today, 'organizer_id' => $indie_org ) );
$steps  = $report['scope']['steps'];
echo "Independent organizer funnel\n";
lnkup_t_same( 7, $steps['views'], 'views count every visit except the test vendor (six vendors on one booth, one on the other)' );
lnkup_t_same( 6, $steps['apply_started'], 'apply starts = saved drafts' );
lnkup_t_same( 5, $steps['submitted'], 'submitted applications' );
lnkup_t_same( 4, $steps['approved'], 'approved applications' );
lnkup_t_same( 3, $steps['paid'], 'paid from payment records' );
lnkup_t_same( 1, $steps['checked_in'], 'checked in' );
lnkup_t_same( 0.25, $report['scope']['drop_off']['paid'], 'drop-off from approved to paid is reported' );
lnkup_t_same( array( 'vendors_paid' => 2, 'repeat_vendors' => 1 ), $report['scope']['repeat_vendors'], 'one vendor paid at two events' );
lnkup_t_same( 1, $report['scope']['days_to_first_paid']['organizers_with_paid'], 'time to first paid booking is measured' );
lnkup_t_assert( null !== $report['scope']['days_to_first_paid']['median_days'], 'median days is a number' );
lnkup_t_same( 5, $report['scope']['slots']['filled'], 'filled slots are every held booking (approved or paid), including a test account occupying a real slot' );
lnkup_t_same( 5, $report['scope']['slots']['open'], 'open slots are the rest of the capacity' );
foreach ( array( 'views', 'apply_started', 'submitted', 'approved', 'paid', 'checked_in' ) as $key ) {
	lnkup_t_assert( '' !== (string) ( $report['definitions'][ $key ] ?? '' ), "the report defines {$key}" );
}

echo "Segments and exclusions\n";
$all = LNKup_Opportunity_Funnel::report( array( 'from' => $today, 'to' => $today ) );
lnkup_t_same( true, $all['house_configured'], 'house organizers are configured' );
lnkup_t_same( 1, $all['house']['steps']['paid'], 'the house organizer shows its own paid booking' );
lnkup_t_assert( $all['independent']['steps']['paid'] >= 3, 'independent organizers include this one' );
$test_report = LNKup_Opportunity_Funnel::report( array( 'from' => $today, 'to' => $today, 'organizer_id' => $test_org ) );
lnkup_t_same( 0, $test_report['scope']['steps']['submitted'], 'an excluded organizer reports nothing' );
lnkup_t_same( 0, (int) $test_report['scope']['organizers'], 'and is not in scope' );

echo "Access\n";
wp_set_current_user( $indie_org );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/admin/analytics/funnel' ) );
lnkup_t_same( 403, $res->get_status(), 'organizers cannot read platform funnel data' );
$admin = lnkup_t_user( 'administrator' );
wp_set_current_user( $admin );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/admin/analytics/funnel' ) );
lnkup_t_same( 200, $res->get_status(), 'admins can' );

update_option( 'lnkup_settings', $saved_core, false );
if ( null === $saved_growth ) {
	delete_option( LNKup_Org_Growth::SETTINGS_KEY );
} else {
	update_option( LNKup_Org_Growth::SETTINGS_KEY, $saved_growth, false );
}
LNKup_Analytics_Exclusions::flush();
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Marketplace_Analytics::table_name() . ' WHERE opportunity_id IN (%d, %d, %d, %d)', $h_opp, $i_opp, $i_opp2, $t_opp ) );
wp_set_current_user( 0 );
lnkup_t_finish();
