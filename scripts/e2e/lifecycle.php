<?php
/**
 * Cross-role booking lifecycle on wp-env, through the real services:
 * create → publish → apply → request info → respond → approve → capacity guard → pay (simulation)
 * → packet → booth → check-in → refund → cancel → inventory release.
 *
 * Usage: npx wp-env run cli wp eval-file wp-content/lnkup-e2e/lifecycle.php
 * Creates its own event and cleans up afterwards.
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'lifecycle.php only runs on the local wp-env environment.' );
}

$uid = static function ( $login ) {
	$user = get_user_by( 'login', $login );
	return $user ? (int) $user->ID : 0;
};
$org      = $uid( 'e2e_org_a' );
$vendor_b = $uid( 'e2e_vendor_b' );
$vendor_a = $uid( 'e2e_vendor_a' );

$results = array();
$step    = static function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %-62s %s\n", $ok ? 'PASS' : 'FAIL', $name, $detail );
};
$code = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() . ': ' . $r->get_error_message() : ( is_scalar( $r ) ? (string) $r : wp_json_encode( $r ) );
};
$status = static function ( $app_id ) {
	return (string) get_post_meta( $app_id, '_lnkup_application_status', true );
};

global $wpdb;
$created_posts = array();

wp_set_current_user( $org );
$event = LNKup_Organizer_Onboarding::create_event_from_request(
	array(
		'event_name'   => 'E2E Lifecycle ' . wp_generate_password( 4, false ),
		'event_date'   => gmdate( 'Y-m-d', strtotime( '+60 days' ) ),
		'city'         => 'Charlotte',
		'state'        => 'NC',
		'client_token' => 'life-' . wp_generate_password( 8, false ),
	)
);
$event_id = is_array( $event ) ? (int) $event['event_id'] : 0;
$created_posts[] = $event_id;
$step( '1 organizer creates an event', $event_id > 0, $code( $event_id ?: $event ) );

$opps   = LNKup_Organizer_Onboarding::create_opportunities_for_event(
	$event_id,
	array( 'opportunities' => array( array( 'vendor_category' => 'Food Vendor', 'vendor_fee' => 90, 'spots_total' => 1 ) ) ),
	$org
);
$opp_id = is_array( $opps ) ? (int) ( $opps['opportunity_id'] ?? 0 ) : 0;
$created_posts[] = $opp_id;
$step( '2 organizer publishes a 1-spot vendor slot', $opp_id > 0 && 'open' === get_post_meta( $opp_id, '_lnkup_opportunity_status', true ), $code( $opp_id ?: $opps ) );

wp_set_current_user( $vendor_b );
$app = LNKup_Applications::submit_with_profile( $vendor_b, $opp_id, array( 'selected_category' => 'Food Vendor', 'accepted_terms' => true, 'application_message' => 'Lifecycle apply' ) );
$app = is_array( $app ) ? (int) ( $app['application_id'] ?? 0 ) : (int) $app;
$created_posts[] = $app;
$step( '3 vendor applies with profile', $app > 0 && 'submitted' === $status( $app ), $status( $app ) );

wp_set_current_user( $vendor_a );
$app2 = LNKup_Applications::submit_with_profile( $vendor_a, $opp_id, array( 'selected_category' => 'Food Vendor', 'accepted_terms' => true, 'application_message' => 'Second vendor' ) );
$app2 = is_array( $app2 ) ? (int) ( $app2['application_id'] ?? 0 ) : ( is_wp_error( $app2 ) ? 0 : (int) $app2 );
$created_posts[] = $app2;
$step( '3b a second vendor applies while the spot is open', $app2 > 0, $status( $app2 ) );

wp_set_current_user( $org );
$ask = LNKup_Applications::request_info( $app, 'Can you send a photo of your setup?', array( 'Product photos' ), $org );
$step( '4 organizer requests more information', is_array( $ask ) && 'info_requested' === $status( $app ), $status( $app ) );

wp_set_current_user( $vendor_b );
$reply = LNKup_Applications::respond_info( $app, 'Uploaded to my profile gallery.', $vendor_b );
$step( '5 vendor responds; request returns to review', is_array( $reply ) && 'under_review' === $status( $app ), $status( $app ) );

wp_set_current_user( $org );
$approve = LNKup_Applications::handle_organizer_action( $app, 'approve', $org );
$payment = LNKup_Payments::get_for_application( $app );
$step( '6 organizer approves; payment created, spot held', true === $approve && 'payment_required' === $status( $app ) && $payment && 0 === (int) get_post_meta( $opp_id, '_lnkup_spots_remaining', true ), $status( $app ) . ' remaining=' . get_post_meta( $opp_id, '_lnkup_spots_remaining', true ) );
if ( $payment ) {
	$created_posts[] = (int) $payment->ID;
}

$late_vendor = wp_insert_user( array( 'user_login' => 'e2e_life_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'life' . wp_rand() . '@example.test', 'role' => 'lnkup_vendor' ) );
$late_profile = wp_insert_post( array( 'post_type' => 'lnkup_vendor_profile', 'post_status' => 'publish', 'post_title' => 'E2E Late Vendor', 'post_author' => $late_vendor ) );
update_post_meta( $late_profile, '_lnkup_owner_user_id', $late_vendor );
update_post_meta( $late_profile, '_lnkup_business_name', 'E2E Late Vendor' );
update_user_meta( $late_vendor, '_lnkup_vendor_profile_id', $late_profile );
wp_set_object_terms( $late_profile, array( 'Food Vendor' ), 'lnkup_vendor_category' );
$created_posts[] = $late_profile;
wp_set_current_user( $late_vendor );
$late = LNKup_Applications::submit_with_profile( $late_vendor, $opp_id, array( 'selected_category' => 'Food Vendor', 'accepted_terms' => true, 'application_message' => 'Too late' ) );
$step( '6b new applications are refused once the slot is full', is_wp_error( $late ) && 'full' === $late->get_error_code(), $code( $late ) );
if ( ! is_wp_error( $late ) ) {
	$created_posts[] = is_array( $late ) ? (int) ( $late['application_id'] ?? 0 ) : (int) $late;
}
wp_set_current_user( $org );
$full = LNKup_Applications::handle_organizer_action( $app2, 'approve', $org );
$step( '7 second approval on the full slot is refused', is_wp_error( $full ) && 'opportunity_full' === $full->get_error_code() && ! LNKup_Payments::get_for_application( $app2 ), $code( $full ) );

LNKup_Payments::mark_paid( (int) $payment->ID, 'sim_lifecycle_' . $app );
$step( '8 vendor pays (simulation); booking confirmed', 'paid' === $status( $app ) && 'paid' === get_post_meta( $payment->ID, '_lnkup_payment_status', true ), $status( $app ) );
$step( '9 packet unlocks for the paid vendor', LNKup_Packets::is_pass_unlocked_for_application( $app ), LNKup_Packets::lifecycle( $event_id )['stage'] );

$booths = LNKup_Operations_Tables::booths_table();
$wpdb->insert( $booths, array( 'event_id' => $event_id, 'booth_number' => 'L-1', 'booth_type' => 'standard', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$booth_id = (int) $wpdb->insert_id;
$assigned = LNKup_Booths::assign( $booth_id, $app, $event_id );
$step( '10 organizer assigns a booth; pass shows it', true === $assigned && 'L-1' === get_post_meta( $app, '_lnkup_booth_placeholder', true ), $code( $assigned ) );

$checkin = LNKup_Checkins::record( $app, 'checked_in', 'manual', $event_id );
$again   = LNKup_Checkins::record( $app, 'checked_in', 'qr', $event_id );
$step( '11 check-in records once; rescan says already checked in', ! empty( $checkin['success'] ) && 'already_checked_in' === ( $again['reason'] ?? '' ), (string) ( $checkin['reason'] ?? '' ) . ' / ' . (string) ( $again['reason'] ?? '' ) );

$refund_id = LNKup_Refunds::request( (int) $payment->ID, 0, 'Lifecycle refund', 'full' );
$refunded  = LNKup_Refunds::approve( (int) $refund_id, $org );
$step( '12 full refund through the Financial Service', true === $refunded && 'refunded' === get_post_meta( $payment->ID, '_lnkup_payment_status', true ), $code( $refunded ) );

$cancel = LNKup_Applications::transition( $app, 'cancelled', array( 'reason' => 'refunded' ) );
$step( '13 cancelling the booking releases the spot', true === $cancel && 1 === (int) get_post_meta( $opp_id, '_lnkup_spots_remaining', true ), 'remaining=' . get_post_meta( $opp_id, '_lnkup_spots_remaining', true ) );

$reapprove = LNKup_Applications::handle_organizer_action( $app2, 'approve', $org );
$step( '14 the waiting vendor can now be approved', true === $reapprove && 'payment_required' === $status( $app2 ), $code( $reapprove ) );
$payment2 = LNKup_Payments::get_for_application( $app2 );
if ( $payment2 ) {
	$created_posts[] = (int) $payment2->ID;
}

// Cleanup.
$wpdb->delete( $booths, array( 'id' => $booth_id ) );
$wpdb->delete( LNKup_Financial_Tables::refunds_table(), array( 'payment_post_id' => (int) $payment->ID ) );
$wpdb->delete( LNKup_Operations_Tables::checkins_table(), array( 'event_id' => $event_id ) );
foreach ( get_posts( array( 'post_type' => 'lnkup_packet', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_lnkup_event_id', 'meta_value' => $event_id ) ) as $packet_id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
	$created_posts[] = (int) $packet_id;
}
foreach ( array_filter( $created_posts ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}
wp_set_current_user( 0 );
if ( ! empty( $late_vendor ) && ! is_wp_error( $late_vendor ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( (int) $late_vendor );
}

$passed = count( array_filter( $results ) );
printf( "\n%d/%d lifecycle steps passed\n", $passed, count( $results ) );
