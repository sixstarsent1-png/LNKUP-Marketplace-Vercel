<?php
/**
 * Organizer Portfolio OS: tours, recurring series, stop overrides, bulk pricing, templates,
 * multi-stop applications, background jobs, permissions and event-level financial reporting.
 *
 * No Stripe calls: payments are settled through LNKup_Payments::mark_paid(). Local wp-env only;
 * settings are restored and every fixture is removed on exit.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/portfolio-os-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Organizer Portfolio OS\n";

LNKup_Portfolio_Tables::maybe_upgrade();
$original_settings = get_option( 'lnkup_settings', array() );
register_shutdown_function(
	static function () use ( $original_settings ) {
		update_option( 'lnkup_settings', $original_settings, false );
	}
);

/**
 * @param array $flags Settings overrides.
 */
function lnkup_p_flags( array $flags ): void {
	$settings = get_option( 'lnkup_settings', array() );
	update_option( 'lnkup_settings', array_merge( is_array( $settings ) ? $settings : array(), $flags ), false );
}

/**
 * @param int    $user   Acting user.
 * @param string $method HTTP method.
 * @param string $route  Route.
 * @param array  $body   Body / query.
 * @param array  $headers Headers.
 */
function lnkup_p_rest( int $user, string $method, string $route, array $body = array(), array $headers = array() ) {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$req->set_query_params( $body );
	} elseif ( $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	foreach ( $headers as $k => $v ) {
		$req->set_header( $k, $v );
	}
	$res = rest_do_request( $req );
	wp_set_current_user( 0 );
	return $res;
}

/**
 * Queue a tour and everything created under it for cleanup.
 *
 * @param int $tour_id Tour.
 */
function lnkup_p_track_tour( int $tour_id ): void {
	global $wpdb;
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $tour_id );
	$events = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_lnkup_client_token_hash' AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'lnkup_event') AND post_id IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_lnkup_organizer_user_id' AND meta_value = %s)", (string) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true ) ) ) );
	foreach ( $events as $event_id ) {
		foreach ( array( 'lnkup_opportunity', 'lnkup_application', 'lnkup_payment' ) as $type ) {
			foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_lnkup_event_id', 'meta_value' => (string) $event_id ) ) as $child ) {
				$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $child );
			}
		}
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $event_id );
	}
}

/**
 * @param string $category Vendor category.
 * @return int Vendor user ID.
 */
function lnkup_p_vendor( string $category ): int {
	$user    = lnkup_t_user( 'lnkup_vendor' );
	$profile = wp_insert_post( array( 'post_type' => 'lnkup_vendor_profile', 'post_status' => 'publish', 'post_title' => 'Portfolio Vendor ' . $user, 'meta_input' => array( '_lnkup_owner_user_id' => $user, '_lnkup_business_name' => 'Portfolio Vendor ' . $user ) ) );
	wp_set_object_terms( $profile, array( $category ), 'lnkup_vendor_category' );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $profile );
	return $user;
}

$organizer = lnkup_t_user( 'lnkup_organizer' );
$other_org = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_p_vendor( 'Food' );

// ---------------------------------------------------------------- flag gating
lnkup_p_flags( array( 'portfolio_os_enabled' => false, 'portfolio_os_user_ids' => '' ) );
lnkup_t_same( 404, lnkup_p_rest( $organizer, 'GET', '/lnkup/v1/portfolio/overview' )->get_status(), 'flag off: portfolio routes return 404' );
lnkup_p_flags( array( 'portfolio_os_user_ids' => (string) $organizer ) );
lnkup_t_same( 200, lnkup_p_rest( $organizer, 'GET', '/lnkup/v1/portfolio/overview' )->get_status(), 'allowlisted organizer gets the portfolio while the global flag is off' );
lnkup_t_same( 404, lnkup_p_rest( $other_org, 'GET', '/lnkup/v1/portfolio/overview' )->get_status(), 'organizers not on the allowlist still get 404' );
lnkup_p_flags( array( 'portfolio_os_enabled' => true, 'matching_enabled' => true, 'matching_tours_enabled' => true, 'tour_multi_apply_enabled' => true ) );
lnkup_t_same( 403, lnkup_p_rest( $vendor, 'GET', '/lnkup/v1/portfolio/overview' )->get_status(), 'vendors cannot use organizer portfolio routes' );

// ---------------------------------------------------------------- tour creation
$res = lnkup_p_rest(
	$organizer,
	'POST',
	'/lnkup/v1/portfolio/tours',
	array(
		'title'       => 'Portfolio Test Rodeo Tour ' . wp_generate_password( 4, false ),
		'type'        => 'tour',
		'season'      => '2027',
		'description' => 'Southern rodeo festival tour.',
		'eligibility' => 'Food and retail vendors.',
	)
);
lnkup_t_same( 201, $res->get_status(), 'tour created' );
$tour    = $res->get_data();
$tour_id = (int) ( $tour['id'] ?? 0 );
lnkup_t_same( 'draft', $tour['status'] ?? '', 'new tour starts as a draft' );

$defaults = lnkup_p_rest(
	$organizer,
	'PUT',
	"/lnkup/v1/portfolio/tours/{$tour_id}/defaults",
	array(
		'booth_types'          => array(
			array( 'key' => 'standard', 'label' => 'Standard Booth', 'category' => 'Food', 'price' => 200, 'capacity' => 10 ),
			array( 'key' => 'premium', 'label' => 'Premium Booth', 'category' => 'Food', 'price' => 400, 'capacity' => 4 ),
		),
		'questions'            => array( array( 'id' => 'menu', 'label' => 'What will you serve?', 'type' => 'text', 'required' => false ) ),
		'deadline_days_before' => 10,
	)
);
lnkup_t_same( 200, $defaults->get_status(), 'tour defaults saved once for every stop' );
lnkup_t_same( 2, count( $defaults->get_data()['booth_types'] ?? array() ), 'two booth types in defaults' );

$base  = strtotime( '+120 days' );
$day   = static function ( $offset ) use ( $base ) {
	return gmdate( 'Y-m-d', $base + $offset * DAY_IN_SECONDS );
};
$stops = array(
	array( 'key' => 'hou', 'city' => 'Houston', 'state' => 'TX', 'venue_name' => 'BH Ranch', 'venue_address' => '1 Ranch Rd', 'event_date' => $day( 0 ), 'timezone' => 'America/Chicago' ),
	array( 'key' => 'jax', 'city' => 'Jacksonville', 'state' => 'FL', 'venue_name' => '', 'venue_address' => '', 'event_date' => $day( 35 ), 'timezone' => 'America/New_York' ),
	array( 'key' => 'atl', 'city' => 'Atlanta', 'state' => 'GA', 'venue_name' => 'Smith Family Ranch', 'venue_address' => '2 Farm Ln', 'event_date' => $day( 77 ), 'timezone' => 'America/New_York' ),
	array( 'key' => 'nas', 'city' => 'Nashville', 'state' => 'TN', 'venue_name' => 'Retreat at Hope Farm', 'venue_address' => '3 Hope Rd', 'event_date' => $day( 98 ), 'timezone' => 'America/Chicago' ),
	array( 'key' => 'clt', 'city' => 'Charlotte', 'state' => 'NC', 'venue_name' => 'Route 29 Pavilion', 'venue_address' => '4 Route 29', 'event_date' => $day( 119 ), 'timezone' => 'America/New_York' ),
	array( 'key' => 'ral', 'city' => 'Raleigh', 'state' => 'NC', 'venue_name' => 'Gooch Arena', 'venue_address' => '5 Arena Way', 'event_date' => $day( 133 ), 'timezone' => 'America/New_York' ),
	array( 'key' => 'vab', 'city' => 'Virginia Beach', 'state' => 'VA', 'venue_name' => 'Virginia Beach Sportsplex', 'venue_address' => '6 Sports Blvd', 'event_date' => $day( 140 ), 'timezone' => 'America/New_York' ),
);
$added = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/stops", array( 'stops' => $stops ), array( 'Idempotency-Key' => 'stops-' . $tour_id . '-a' ) );
lnkup_t_same( 200, $added->get_status(), 'seven stops added in one request' );
lnkup_t_same( 7, (int) ( $added->get_data()['result']['created'] ?? 0 ), 'seven draft stops created' );
$again = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/stops", array( 'stops' => $stops ), array( 'Idempotency-Key' => 'stops-' . $tour_id . '-b' ) );
lnkup_t_same( 7, (int) ( $again->get_data()['result']['existing'] ?? 0 ), 'resubmitting the same table returns existing stops (no duplicates)' );
lnkup_t_same( 7, count( LNKup_Tours::stop_event_ids( $tour_id ) ), 'tour still has exactly seven stops' );
lnkup_p_track_tour( $tour_id );

$by_key = LNKup_Portfolio_Tours::stops_by_key( LNKup_Tours::stop_event_ids( $tour_id ) );
foreach ( $by_key as $event_id ) {
	lnkup_t_assert( 'draft' === get_post_status( $event_id ), 'stop ' . $event_id . ' is an unpublished draft' );
}
lnkup_t_same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Marketplace_Index::table_name() . ' WHERE event_id IN (' . implode( ',', $by_key ) . ')' ), 'draft stops are not in the marketplace index' );
$jax_ready = LNKup_Portfolio_Tours::readiness( $tour_id, $by_key['jax'] );
lnkup_t_assert( ! $jax_ready['ready'] && in_array( 'venue_name', wp_list_pluck( $jax_ready['missing'], 'field' ), true ), 'stop without a confirmed venue is not ready (nothing invented)' );

// Atlanta price override on the draft stop.
$atl = lnkup_p_rest( $organizer, 'PATCH', "/lnkup/v1/portfolio/tours/{$tour_id}/stops/{$by_key['atl']}", array( 'overrides' => array( 'booth_types' => array( 'standard' => array( 'price' => 250 ) ) ) ) );
lnkup_t_same( 200, $atl->get_status(), 'Atlanta override saved' );
$atl_cfg = LNKup_Portfolio_Config::resolve_for_stop( $tour_id, $by_key['atl'] );
lnkup_t_same( 250.0, (float) $atl_cfg['booth_types'][0]['price'], 'Atlanta resolves to the override price' );
lnkup_t_same( 200.0, (float) LNKup_Portfolio_Config::resolve_for_stop( $tour_id, $by_key['hou'] )['booth_types'][0]['price'], 'Houston keeps the default price' );
LNKup_Portfolio_Config::save_defaults( $tour_id, array_merge( LNKup_Portfolio_Config::get_defaults( $tour_id ), array( 'booth_types' => array( array( 'key' => 'standard', 'label' => 'Standard Booth', 'category' => 'Food', 'price' => 225, 'capacity' => 10 ), array( 'key' => 'premium', 'label' => 'Premium Booth', 'category' => 'Food', 'price' => 400, 'capacity' => 4 ) ) ) ) );
lnkup_t_same( 250.0, (float) LNKup_Portfolio_Config::resolve_for_stop( $tour_id, $by_key['atl'] )['booth_types'][0]['price'], 'changing the tour default does not overwrite the Atlanta override' );
lnkup_t_same( 225.0, (float) LNKup_Portfolio_Config::resolve_for_stop( $tour_id, $by_key['hou'] )['booth_types'][0]['price'], 'non-overridden draft stop picks up the new default' );

// Cross-organizer protection.
lnkup_t_same( 404, lnkup_p_rest( $other_org, 'GET', "/lnkup/v1/portfolio/tours/{$tour_id}" )->get_status(), 'another organizer cannot read the tour' );
lnkup_t_same( 404, lnkup_p_rest( $other_org, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/publish", array( 'all' => true ) )->get_status(), 'another organizer cannot publish stops' );
$foreign = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'draft', 'post_title' => 'Foreign event', 'meta_input' => array( '_lnkup_organizer_user_id' => $other_org, '_lnkup_tour_id' => $tour_id ) ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $foreign );
lnkup_t_same( 404, lnkup_p_rest( $organizer, 'PATCH', "/lnkup/v1/portfolio/tours/{$tour_id}/stops/{$foreign}", array( 'city' => 'X' ) )->get_status(), 'a forged stop owned by another organizer is rejected' );
delete_post_meta( $foreign, '_lnkup_tour_id' );

// ---------------------------------------------------------------- publish only ready stops
$pub = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/publish", array( 'all' => true ) );
lnkup_t_same( 200, $pub->get_status(), 'publish request accepted' );
lnkup_t_same( 6, (int) ( $pub->get_data()['published'] ?? 0 ), 'six ready stops published' );
lnkup_t_same( 1, (int) ( $pub->get_data()['not_ready'] ?? 0 ), 'Jacksonville (no venue) stays a draft' );
lnkup_t_same( 'draft', get_post_status( $by_key['jax'] ), 'Jacksonville event is still unpublished' );
lnkup_t_same( 'publish', get_post_status( $tour_id ), 'tour goes live with its first published stop' );
$atl_opps = LNKup_Portfolio_Config::stop_opportunities( $by_key['atl'] );
lnkup_t_same( 250.0, (float) get_post_meta( $atl_opps['standard'], '_lnkup_vendor_fee', true ), 'Atlanta standard booth listing uses the override price' );
lnkup_t_same( 225.0, (float) get_post_meta( LNKup_Portfolio_Config::stop_opportunities( $by_key['hou'] )['standard'], '_lnkup_vendor_fee', true ), 'Houston standard booth listing uses the default price' );
lnkup_t_same( 1, count( LNKup_Application_Questions::get_for_opportunity( $atl_opps['standard'] ) ), 'shared application question copied to each booth listing' );
lnkup_t_same( (int) $tour_id, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT tour_id FROM ' . LNKup_Marketplace_Index::table_name() . ' WHERE opportunity_id = %d', $atl_opps['standard'] ) ), 'published stop is indexed under the tour' );
$repub = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/publish", array( 'event_ids' => array( $by_key['atl'] ) ) );
lnkup_t_same( 2, count( LNKup_Organizer_Onboarding::event_slot_posts( $by_key['atl'] ) ), 'publishing twice does not duplicate booth listings' );
lnkup_t_same( 'already_published', $repub->get_data()['results'][0]['result'] ?? '', 'second publish reports already published' );

// ---------------------------------------------------------------- multi-stop application
wp_set_current_user( $vendor );
$selections = array();
foreach ( array( 'hou', 'atl', 'clt' ) as $k ) {
	$selections[] = array( 'event_id' => $by_key[ $k ], 'opportunity_id' => LNKup_Portfolio_Config::stop_opportunities( $by_key[ $k ] )['standard'] );
}
$group = LNKup_Application_Groups::submit( $tour_id, $vendor, array( 'group_id' => 'grp' . wp_generate_password( 10, false, false ), 'accepted_terms' => true, 'selections' => $selections, 'shared' => array( 'selected_category' => 'Food' ) ) );
wp_set_current_user( 0 );
lnkup_t_assert( ! is_wp_error( $group ), 'multi-stop application submitted' );
lnkup_t_same( 3, (int) ( $group['summary']['submitted'] ?? 0 ), 'three stop-specific applications created' );
lnkup_p_track_tour( $tour_id );
$apps = wp_list_pluck( $group['results'] ?? array(), 'application_id' );
lnkup_t_same( 3, count( array_unique( array_map( 'intval', $apps ) ) ), 'each stop has its own application record' );

$bulk = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/applications/bulk", array( 'application_ids' => array( $apps[0], $apps[1] ), 'action' => 'approve' ) );
lnkup_t_same( 2, (int) ( $bulk->get_data()['ok'] ?? 0 ), 'two stops approved from the tour dashboard' );
$rej = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/applications/bulk", array( 'application_ids' => array( $apps[2] ), 'action' => 'reject' ) );
lnkup_t_same( 1, (int) ( $rej->get_data()['ok'] ?? 0 ), 'third stop rejected' );
lnkup_t_same( 'rejected', (string) get_post_meta( $apps[2], '_lnkup_application_status', true ), 'rejecting Charlotte leaves it rejected' );
lnkup_t_same( 'payment_required', (string) get_post_meta( $apps[0], '_lnkup_application_status', true ), 'approving one stop does not touch the others' );
$cross = lnkup_p_rest( $other_org, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/applications/bulk", array( 'application_ids' => array( $apps[2] ), 'action' => 'approve' ) );
lnkup_t_same( 404, $cross->get_status(), 'another organizer cannot bulk-approve tour applications' );

$p1 = LNKup_Payments::get_for_application( $apps[0] );
$p2 = LNKup_Payments::get_for_application( $apps[1] );
lnkup_t_assert( $p1 && $p2 && $p1->ID !== $p2->ID, 'each approved stop has its own payment record' );
lnkup_t_same( $by_key['hou'], (int) get_post_meta( $p1->ID, '_lnkup_event_id', true ), 'Houston payment is tied to the Houston event' );
lnkup_t_same( $by_key['atl'], (int) get_post_meta( $p2->ID, '_lnkup_event_id', true ), 'Atlanta payment is tied to the Atlanta event' );
LNKup_Payments::mark_paid( $p1->ID );
LNKup_Payments::mark_paid( $p2->ID );
lnkup_p_track_tour( $tour_id );

$summary = lnkup_p_rest( $organizer, 'GET', "/lnkup/v1/portfolio/tours/{$tour_id}/summary" )->get_data();
lnkup_t_same( 2, (int) $summary['money']['paid_bookings'], 'tour reports two verified paid bookings' );
lnkup_t_assert( ! empty( $summary['money']['reconciled'] ), 'tour totals equal the sum of stop totals' );
$expected = round( LNKup_Financial::event_collected_revenue( $by_key['hou'] ) + LNKup_Financial::event_collected_revenue( $by_key['atl'] ) + LNKup_Financial::event_collected_revenue( $by_key['clt'] ), 2 );
lnkup_t_same( $expected, (float) $summary['money']['collected'], 'tour collected equals the Financial OS per-event revenue' );
lnkup_t_assert( (float) $summary['money']['collected'] > 0, 'collected revenue is positive after payment' );
lnkup_t_same( 3, (int) $summary['applications']['total'], 'tour reports three applications' );
lnkup_t_same( 1, (int) $summary['applications']['rejected'], 'tour reports one rejection' );

// ---------------------------------------------------------------- bulk pricing on live stops
$preview = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/bulk/preview", array( 'booth_type_key' => 'standard', 'price' => 300, 'event_ids' => array( $by_key['hou'], $by_key['nas'], $by_key['jax'] ) ) )->get_data();
$outcomes = array();
foreach ( $preview['rows'] as $row ) {
	$outcomes[ $row['event_id'] ] = $row['outcome'];
}
lnkup_t_same( 'confirm', $outcomes[ $by_key['hou'] ] ?? '', 'live stop needs explicit confirmation' );
lnkup_t_same( 'change', $outcomes[ $by_key['jax'] ] ?? '', 'draft stop changes without confirmation' );
$apply = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/bulk/apply", array( 'booth_type_key' => 'standard', 'price' => 300, 'event_ids' => array( $by_key['hou'], $by_key['jax'] ) ) )->get_data();
lnkup_t_same( 225.0, (float) get_post_meta( LNKup_Portfolio_Config::stop_opportunities( $by_key['hou'] )['standard'], '_lnkup_vendor_fee', true ), 'without confirmation the live Houston price is unchanged' );
lnkup_t_same( 300.0, (float) LNKup_Portfolio_Config::resolve_for_stop( $tour_id, $by_key['jax'] )['booth_types'][0]['price'], 'draft Jacksonville price updated' );
$paid_before = (string) get_post_meta( $p1->ID, '_lnkup_total_amount', true );
lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/bulk/apply", array( 'booth_type_key' => 'standard', 'price' => 300, 'event_ids' => array( $by_key['hou'] ), 'confirm_published' => true ) );
lnkup_t_same( 300.0, (float) get_post_meta( LNKup_Portfolio_Config::stop_opportunities( $by_key['hou'] )['standard'], '_lnkup_vendor_fee', true ), 'confirmed bulk edit updates the live Houston listing' );
lnkup_t_same( $paid_before, (string) get_post_meta( $p1->ID, '_lnkup_total_amount', true ), 'completed Houston payment keeps its original amount' );
$low = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/bulk/preview", array( 'booth_type_key' => 'standard', 'capacity' => 1, 'event_ids' => array( $by_key['hou'] ) ) )->get_data();
lnkup_t_same( 1, (int) $low['rows'][0]['filled'], 'preview reports the booths already held at Houston' );
lnkup_t_same( 1, (int) $low['rows'][0]['paid_bookings'], 'preview reports paid bookings that keep their price' );
$lower = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/bulk/preview", array( 'booth_type_key' => 'premium', 'capacity' => 2, 'event_ids' => array( $by_key['clt'] ) ) )->get_data();
lnkup_t_same( 'confirm', $lower['rows'][0]['outcome'] ?? '', 'live premium capacity change previews as needing confirmation' );

// ---------------------------------------------------------------- deletion safety
lnkup_t_same( 409, lnkup_p_rest( $organizer, 'DELETE', "/lnkup/v1/portfolio/tours/{$tour_id}" )->get_status(), 'a tour with applications cannot be deleted' );
lnkup_t_same( 409, lnkup_p_rest( $organizer, 'DELETE', "/lnkup/v1/portfolio/tours/{$tour_id}/stops/{$by_key['hou']}" )->get_status(), 'a live stop cannot be deleted' );
$cancel = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/cancel", array( 'event_ids' => array( $by_key['vab'] ), 'note' => 'Venue unavailable' ) );
lnkup_t_same( 'cancelled', $cancel->get_data()['results'][0]['result'] ?? '', 'live stop cancelled through the event status flow' );
lnkup_t_same( 'cancelled', LNKup_Portfolio_Tours::stop_state( $by_key['vab'] ), 'Virginia Beach is cancelled' );
lnkup_t_same( 'publish', get_post_status( $by_key['vab'] ), 'cancelled stop keeps its event record' );
$archived = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/archive", array( 'archived' => true ) )->get_data();
lnkup_t_same( 'archived', $archived['status'] ?? '', 'tour archived' );
lnkup_t_same( 7, count( LNKup_Tours::stop_event_ids( $tour_id ) ), 'archiving keeps every stop' );
lnkup_t_same( 'paid', (string) get_post_meta( $apps[0], '_lnkup_application_status', true ), 'archiving keeps paid bookings' );
lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/archive", array( 'archived' => false ) );

// ---------------------------------------------------------------- public page data
$public = LNKup_Portfolio_Public::data( $tour_id, 0 );
$cities = wp_list_pluck( $public['stops'], 'city' );
lnkup_t_assert( ! in_array( 'Jacksonville', $cities, true ), 'public tour page hides the draft stop' );
lnkup_t_assert( ! in_array( 'Virginia Beach', $cities, true ), 'public tour page hides the cancelled stop' );
lnkup_t_assert( in_array( 'Atlanta', $cities, true ), 'public tour page lists published stops' );
lnkup_t_same( $tour_id, LNKup_Portfolio_Public::tour_id_from_slug( get_post_field( 'post_name', $tour_id ) ), 'tour resolves from its public slug' );
lnkup_t_same( 200, lnkup_p_rest( 0, 'GET', '/lnkup/v1/portfolio/public/tours/' . get_post_field( 'post_name', $tour_id ) )->get_status(), 'public tour REST is readable without login' );

// ---------------------------------------------------------------- interest + invites
lnkup_t_assert( ! empty( lnkup_p_rest( $vendor, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/interest/me" )->get_data()['interested'] ), 'vendor registers tour-wide interest' );
$interest = lnkup_p_rest( $organizer, 'GET', "/lnkup/v1/portfolio/tours/{$tour_id}/interest" )->get_data();
lnkup_t_same( 1, count( $interest['items'] ), 'organizer sees the interested vendor' );
lnkup_t_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_lnkup_vendor_user_id' AND meta_value = %s AND post_id IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_lnkup_event_id' AND meta_value = %s)", (string) $vendor, (string) $by_key['nas'] ) ), 'interest does not create an application' );
$invite = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/invite", array( 'vendor_user_ids' => array( $vendor ), 'event_ids' => array( $by_key['nas'] ) ) )->get_data();
lnkup_t_same( 1, (int) ( $invite['invited'] ?? 0 ), 'returning vendor invited to Nashville' );
foreach ( get_posts( array( 'post_type' => 'lnkup_vendor_invite', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'author' => $organizer ) ) as $inv ) {
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $inv );
}

// ---------------------------------------------------------------- templates
$tpl = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$tour_id}/save-as-template", array( 'name' => 'Rodeo setup' ) )->get_data();
$tpl_id = (int) ( $tpl['id'] ?? 0 );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $tpl_id );
lnkup_t_same( 1, (int) ( $tpl['version'] ?? 0 ), 'template saved as version 1' );
$v1 = (int) $tpl['version_id'];
$tpl2 = lnkup_p_rest( $organizer, 'PATCH', "/lnkup/v1/portfolio/templates/{$tpl_id}", array( 'config' => array_merge( $tpl['config'], array( 'deadline_days_before' => 21 ) ) ) )->get_data();
lnkup_t_same( 2, (int) $tpl2['version'], 'editing the config writes version 2' );
lnkup_t_same( 10, (int) LNKup_Portfolio_Templates::get_version( $v1 )['config']['deadline_days_before'], 'version 1 snapshot is unchanged' );
lnkup_t_same( 404, lnkup_p_rest( $other_org, 'PATCH', "/lnkup/v1/portfolio/templates/{$tpl_id}", array( 'name' => 'Hijack' ) )->get_status(), 'another organizer cannot edit the template' );
lnkup_t_assert( ! array_intersect( array( 'payments', 'applications', 'approvals', 'checkins' ), array_keys( $tpl2['config'] ) ), 'template config carries no payments, approvals or check-ins' );

// ---------------------------------------------------------------- recurring series
$series = lnkup_p_rest( $organizer, 'POST', '/lnkup/v1/portfolio/tours', array( 'title' => 'Portfolio Monthly Market ' . wp_generate_password( 4, false ), 'type' => 'recurring', 'template_id' => $tpl_id ) )->get_data();
$series_id = (int) $series['id'];
lnkup_t_same( 21, (int) $series['defaults']['deadline_days_before'], 'new series starts from the template\'s current version' );
$rule = array(
	'frequency'  => 'monthly_weekday',
	'start_date' => gmdate( 'Y-m-d', strtotime( 'first saturday of ' . gmdate( 'F Y', strtotime( 'first day of +2 months' ) ) ) ),
	'count'      => 6,
	'start_time' => '10:00',
	'end_time'   => '16:00',
	'timezone'   => 'America/New_York',
	'stop'       => array( 'city' => 'Durham', 'state' => 'NC', 'venue_name' => 'Market Hall', 'venue_address' => '7 Main St' ),
);
$prev = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$series_id}/recurrence/preview", array( 'rule' => $rule ) )->get_data();
lnkup_t_same( 6, count( $prev['occurrences'] ?? array() ), 'preview shows six monthly dates' );
lnkup_t_same( 0, count( LNKup_Tours::stop_event_ids( $series_id ) ), 'preview creates nothing' );
foreach ( $prev['occurrences'] as $occ ) {
	lnkup_t_same( '6', gmdate( 'w', strtotime( $occ['date'] ) ), 'occurrence ' . $occ['date'] . ' is a Saturday' );
}
$gen = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$series_id}/recurrence/generate", array( 'rule' => $rule ), array( 'Idempotency-Key' => 'gen-' . $series_id ) )->get_data();
lnkup_t_same( 6, (int) ( $gen['result']['created'] ?? 0 ), 'six occurrences generated' );
lnkup_p_track_tour( $series_id );
$gen2 = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$series_id}/recurrence/generate", array( 'rule' => $rule ), array( 'Idempotency-Key' => 'gen2-' . $series_id ) )->get_data();
lnkup_t_same( 0, (int) ( $gen2['result']['created'] ?? 0 ), 'generating again does not duplicate dates' );
$occ_ids = LNKup_Portfolio_Tours::stops_by_key( LNKup_Tours::stop_event_ids( $series_id ) );
ksort( $occ_ids );
$occ_ids = array_values( $occ_ids );
$skip = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$series_id}/occurrences/{$occ_ids[2]}/skip" );
lnkup_t_same( 'skipped', $skip->get_data()['state'] ?? '', 'one month skipped' );
$move = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$series_id}/occurrences/{$occ_ids[3]}/move", array( 'changes' => array( 'venue_name' => 'Riverside Park', 'venue_address' => '9 River Rd' ) ) );
lnkup_t_same( 'Riverside Park', $move->get_data()['venue_name'] ?? '', 'one occurrence moved to a new venue' );
lnkup_t_same( 'Market Hall', (string) get_post_meta( $occ_ids[4], '_lnkup_venue_name', true ), 'other occurrences keep their venue' );
$spub = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$series_id}/publish", array( 'all' => true ) )->get_data();
lnkup_t_same( 5, (int) $spub['published'], 'five occurrences published (skipped one stays out)' );
$occ_opps = array();
foreach ( $occ_ids as $oid ) {
	$occ_opps[ $oid ] = LNKup_Portfolio_Config::stop_opportunities( $oid );
}
lnkup_t_assert( empty( $occ_opps[ $occ_ids[2] ] ), 'skipped occurrence has no booth listings' );
lnkup_t_assert( $occ_opps[ $occ_ids[0] ]['standard'] !== $occ_opps[ $occ_ids[1] ]['standard'], 'each occurrence has its own inventory' );
lnkup_p_track_tour( $series_id );

// DST: 10:00 local stays 10:00 local across the March change.
$dst = LNKup_Portfolio_Recurrence::occurrences( LNKup_Portfolio_Recurrence::sanitize_rule( array( 'frequency' => 'weekly', 'weekdays' => array( 0 ), 'start_date' => '2027-03-07', 'count' => 2, 'start_time' => '10:00', 'timezone' => 'America/New_York' ) ) );
lnkup_t_same( '2027-03-07T15:00:00+00:00', $dst[0]['starts_at_utc'], 'before DST: 10:00 New York = 15:00 UTC' );
lnkup_t_same( '2027-03-14T14:00:00+00:00', $dst[1]['starts_at_utc'], 'after DST: 10:00 New York = 14:00 UTC' );
lnkup_t_assert( is_wp_error( LNKup_Portfolio_Recurrence::sanitize_rule( array( 'frequency' => 'weekly', 'start_date' => '2027-01-03', 'timezone' => 'America/New_York' ) ) ), 'open-ended rule without end date or count is rejected' );
lnkup_t_assert( is_wp_error( LNKup_Portfolio_Recurrence::sanitize_rule( array( 'frequency' => 'weekly', 'start_date' => '2027-01-03', 'count' => 500, 'timezone' => 'America/New_York' ) ) ), 'more than 52 occurrences at once is rejected' );

// ---------------------------------------------------------------- background job for big batches
$big = lnkup_p_rest( $organizer, 'POST', '/lnkup/v1/portfolio/tours', array( 'title' => 'Portfolio Weekly ' . wp_generate_password( 4, false ), 'type' => 'recurring' ) )->get_data();
$big_id = (int) $big['id'];
$job = lnkup_p_rest( $organizer, 'POST', "/lnkup/v1/portfolio/tours/{$big_id}/recurrence/generate", array( 'rule' => array( 'frequency' => 'weekly', 'weekdays' => array( 6 ), 'start_date' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ), 'count' => 14, 'timezone' => 'America/Chicago', 'stop' => array( 'city' => 'Austin', 'state' => 'TX' ) ) ), array( 'Idempotency-Key' => 'big-' . $big_id ) )->get_data();
lnkup_t_same( 'job', $job['mode'] ?? '', 'fourteen dates run as a background job' );
lnkup_t_same( 10, (int) $job['job']['done'], 'first chunk of ten runs immediately' );
wp_set_current_user( $organizer );
LNKup_Portfolio_Jobs::process( (int) $job['job']['id'] );
wp_set_current_user( 0 );
$status = lnkup_p_rest( $organizer, 'GET', '/lnkup/v1/portfolio/jobs/' . (int) $job['job']['id'] )->get_data();
lnkup_t_same( 'complete', $status['status'] ?? '', 'job completes on the next step' );
lnkup_t_same( 14, count( LNKup_Tours::stop_event_ids( $big_id ) ), 'all fourteen dates exist exactly once' );
lnkup_t_same( 404, lnkup_p_rest( $other_org, 'GET', '/lnkup/v1/portfolio/jobs/' . (int) $job['job']['id'] )->get_status(), 'another organizer cannot read the job' );
wp_clear_scheduled_hook( LNKup_Portfolio_Jobs::HOOK, array( (int) $job['job']['id'] ) );
lnkup_p_track_tour( $big_id );
$wpdb->delete( LNKup_Portfolio_Tables::jobs_table(), array( 'id' => (int) $job['job']['id'] ) );

// ---------------------------------------------------------------- portfolio event list
$events = lnkup_p_rest( $organizer, 'GET', '/lnkup/v1/portfolio/events', array( 'kind' => 'recurring', 'per_page' => 50 ) )->get_data();
lnkup_t_same( 20, (int) $events['total'], 'portfolio event list filters to recurring series dates' );
$touronly = lnkup_p_rest( $organizer, 'GET', '/lnkup/v1/portfolio/events', array( 'tour_id' => $tour_id ) )->get_data();
lnkup_t_same( 7, (int) $touronly['total'], 'portfolio event list filters to one tour' );

// ---------------------------------------------------------------- cleanup
$wpdb->delete( LNKup_Portfolio_Tables::interest_table(), array( 'vendor_user_id' => $vendor ) );
$wpdb->delete( LNKup_Portfolio_Tables::template_versions_table(), array( 'template_id' => $tpl_id ) );
foreach ( array( $tour_id, $series_id, $big_id ) as $t ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Portfolio_Tables::audit_table() . " WHERE (object_type = 'tour' AND object_id = %d)", $t ) );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Portfolio_Tables::audit_table() . ' WHERE organizer_user_id = %d', $organizer ) );
update_option( 'lnkup_settings', $original_settings, false );
lnkup_t_finish();
