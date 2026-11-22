<?php
/**
 * My Bookings: buckets, counts, paging, filters, ownership, evidence-only timeline, and
 * payment amounts that match the payment record.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/booking-center-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

echo "My Bookings\n";

/**
 * Published post removed at the end of the run.
 *
 * @param string $type Post type.
 * @param array  $meta Meta.
 */
function lnkup_bc_post( string $type, array $meta = array() ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Bookings test ' . $type, 'meta_input' => $meta ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

/**
 * Event in a city + open opportunity.
 *
 * @return int[] [event, opportunity]
 */
function lnkup_bc_event( int $organizer, string $city ): array {
	$event = lnkup_bc_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_city' => $city, '_lnkup_state' => 'TX', '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+40 days' ) ) ) );
	$opp   = lnkup_bc_post( 'lnkup_opportunity', array( '_lnkup_event_id' => $event, '_lnkup_organizer_user_id' => $organizer, '_lnkup_opportunity_status' => 'open', '_lnkup_vendor_fee' => '150', '_lnkup_spots_total' => 10, '_lnkup_spots_remaining' => 10, '_lnkup_application_deadline' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ) ) );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	return array( $event, $opp );
}

/**
 * Vendor with a profile.
 */
function lnkup_bc_vendor(): int {
	$user = lnkup_t_user( 'lnkup_vendor' );
	lnkup_bc_post( 'lnkup_vendor_profile', array( '_lnkup_owner_user_id' => $user, '_lnkup_business_name' => 'Bookings Vendor ' . $user ) );
	return $user;
}

/**
 * Submit as the vendor.
 */
function lnkup_bc_apply( int $vendor, int $opp ): int {
	wp_set_current_user( $vendor );
	$id = LNKup_Applications::submit_with_profile( $vendor, $opp, array( 'accepted_terms' => true, 'selected_category' => 'General' ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

/**
 * Approve as the organizer; returns the payment ID.
 */
function lnkup_bc_approve( int $organizer, int $app ): int {
	wp_set_current_user( $organizer );
	LNKup_Applications::transition( $app, 'approved' );
	$payment = LNKup_Payments::get_for_application( $app );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $payment->ID );
	return (int) $payment->ID;
}

$organizer = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_bc_vendor();
$other     = lnkup_bc_vendor();

list( $ev_a, $opp_a ) = lnkup_bc_event( $organizer, 'Houston' );
list( $ev_b, $opp_b ) = lnkup_bc_event( $organizer, 'Atlanta' );
list( $ev_c, $opp_c ) = lnkup_bc_event( $organizer, 'Dallas' );
list( $ev_d, $opp_d ) = lnkup_bc_event( $organizer, 'Austin' );
list( $ev_e, $opp_e ) = lnkup_bc_event( $organizer, 'Nashville' );
list( $ev_f, $opp_f ) = lnkup_bc_event( $organizer, 'Memphis' );

// A: approved, payment pending.
$app_a = lnkup_bc_apply( $vendor, $opp_a );
$pay_a = lnkup_bc_approve( $organizer, $app_a );
// B: paid, upcoming.
$app_b = lnkup_bc_apply( $vendor, $opp_b );
$pay_b = lnkup_bc_approve( $organizer, $app_b );
LNKup_Payments::mark_paid( $pay_b, 'pi_bc_b' );
// C: paid, event already happened.
$app_c = lnkup_bc_apply( $vendor, $opp_c );
$pay_c = lnkup_bc_approve( $organizer, $app_c );
LNKup_Payments::mark_paid( $pay_c, 'pi_bc_c' );
update_post_meta( $ev_c, '_lnkup_event_date', gmdate( 'Y-m-d', strtotime( '-3 days' ) ) );
// D: withdrawn.
$app_d = lnkup_bc_apply( $vendor, $opp_d );
LNKup_Applications::withdraw( $app_d, $vendor );
// E: paid then fully refunded.
$app_e = lnkup_bc_apply( $vendor, $opp_e );
$pay_e = lnkup_bc_approve( $organizer, $app_e );
LNKup_Payments::mark_paid( $pay_e, 'pi_bc_e' );
update_post_meta( $pay_e, '_lnkup_refund_amount', (float) get_post_meta( $pay_e, '_lnkup_total_amount', true ) );
LNKup_Refunds::sync_payment_refund_status( $pay_e );
// F: still in review (belongs on Requests, not Bookings).
$app_f = lnkup_bc_apply( $vendor, $opp_f );

// Buckets and counts.
$all = LNKup_Vendor_Bookings::query( $vendor, array( 'bucket' => 'all' ) );
lnkup_t_same(
	array( 'all' => 5, 'upcoming' => 2, 'pending_payment' => 1, 'confirmed' => 1, 'past' => 1, 'cancelled' => 1, 'refunded' => 1 ),
	$all['counts'],
	'bucket counts come from one aggregate query and exclude in-review requests'
);
$ids = static function ( array $result ): array {
	$out = wp_list_pluck( $result['items'], 'application_id' );
	sort( $out );
	return $out;
};
lnkup_t_same( array( $app_a ), $ids( LNKup_Vendor_Bookings::query( $vendor, array( 'bucket' => 'pending_payment' ) ) ), 'pending payment bucket' );
lnkup_t_same( array( $app_b ), $ids( LNKup_Vendor_Bookings::query( $vendor, array( 'bucket' => 'confirmed' ) ) ), 'confirmed bucket' );
lnkup_t_same( array( $app_c ), $ids( LNKup_Vendor_Bookings::query( $vendor, array( 'bucket' => 'past' ) ) ), 'past bucket' );
lnkup_t_same( array( $app_d ), $ids( LNKup_Vendor_Bookings::query( $vendor, array( 'bucket' => 'cancelled' ) ) ), 'cancelled bucket' );
lnkup_t_same( array( $app_e ), $ids( LNKup_Vendor_Bookings::query( $vendor, array( 'bucket' => 'refunded' ) ) ), 'refunded bucket' );
lnkup_t_assert( ! in_array( $app_f, wp_list_pluck( $all['items'], 'application_id' ), true ), 'in-review request is not a booking' );

// Paging and filters.
$page1 = LNKup_Vendor_Bookings::query( $vendor, array( 'bucket' => 'all', 'per_page' => 2, 'page' => 1 ) );
$page3 = LNKup_Vendor_Bookings::query( $vendor, array( 'bucket' => 'all', 'per_page' => 2, 'page' => 3 ) );
lnkup_t_same( array( 2, 3, 5 ), array( count( $page1['items'] ), $page1['pages'], $page1['total'] ), 'two per page over three pages' );
lnkup_t_same( 1, count( $page3['items'] ), 'last page holds the remainder' );
lnkup_t_same( array( $app_b ), $ids( LNKup_Vendor_Bookings::query( $vendor, array( 'bucket' => 'all', 'city' => 'Atlanta' ) ) ), 'city filter' );
lnkup_t_same( 0, LNKup_Vendor_Bookings::query( $vendor, array( 'bucket' => 'all', 'from' => gmdate( 'Y-m-d', strtotime( '+100 days' ) ) ) )['total'], 'date filter' );
lnkup_t_same( 'all', LNKup_Vendor_Bookings::normalize_args( array( 'bucket' => "'; DROP TABLE x" ) )['bucket'], 'unknown bucket falls back to all' );
lnkup_t_same( 50, LNKup_Vendor_Bookings::normalize_args( array( 'per_page' => 5000 ) )['per_page'], 'page size is capped' );

// Ownership.
lnkup_t_same( 0, LNKup_Vendor_Bookings::query( $other, array( 'bucket' => 'all' ) )['total'], 'another vendor sees none of these bookings' );
lnkup_t_same( null, LNKup_Vendor_Bookings::detail( $other, $app_b ), 'another vendor cannot open the booking' );
wp_set_current_user( $other );
lnkup_t_same( 404, rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/bookings/vendor/' . $app_b ) )->get_status(), 'REST returns 404 for someone else\'s booking' );
wp_set_current_user( $vendor );
lnkup_t_same( 200, rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/bookings/vendor/' . $app_b ) )->get_status(), 'owner can open the booking over REST' );
wp_set_current_user( 0 );
lnkup_t_same( 401, rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/bookings/vendor' ) )->get_status(), 'logged-out callers are refused' );

// Detail amounts match the payment record.
$detail_b = LNKup_Vendor_Bookings::detail( $vendor, $app_b );
lnkup_t_same( round( (float) get_post_meta( $pay_b, '_lnkup_total_amount', true ), 2 ), $detail_b['payment']['total'], 'detail total equals the payment total' );
lnkup_t_same( 'paid', $detail_b['payment']['status'], 'detail payment status is the payment record status' );
$detail_a = LNKup_Vendor_Bookings::detail( $vendor, $app_a );
lnkup_t_same( LNKup_Financial_Service::stored_amount_due( $pay_a ), $detail_a['amount_due'], 'amount due is the stored amount due' );
lnkup_t_same( 'pay', $detail_a['next_action'], 'pending booking offers Pay' );

// Timeline uses recorded evidence only.
$keys = static function ( array $timeline ): array {
	$out = array();
	foreach ( $timeline as $step ) {
		$out[ $step['key'] ] = $step;
	}
	return $out;
};
$tb = $keys( $detail_b['timeline'] );
lnkup_t_same( 'done', $tb['confirmed']['state'] ?? '', 'paid booking shows confirmed' );
lnkup_t_assert( '' !== $tb['approved']['at'], 'approval time comes from the status history' );
$te = $keys( LNKup_Vendor_Bookings::timeline( $app_e ) );
lnkup_t_same( 'stopped', $te['cancelled']['state'] ?? '', 'refunded booking ends on a stopped cancellation step' );
lnkup_t_assert( ! isset( $te['checked_in'] ), 'nothing is shown after a booking closes' );

delete_post_meta( $app_b, '_lnkup_status_history' );
$legacy = $keys( LNKup_Vendor_Bookings::timeline( $app_b ) );
lnkup_t_same( '', $legacy['review']['at'], 'legacy booking: no invented review timestamp' );
lnkup_t_same( (string) get_post_meta( $app_b, '_lnkup_approved_at', true ), $legacy['approved']['at'], 'legacy booking: approval time from stored meta only' );
lnkup_t_same( 'current', $legacy['checked_in']['state'], 'legacy booking: next step is check-in' );

wp_set_current_user( 0 );
lnkup_t_finish();
