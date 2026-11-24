<?php
/**
 * Event vendor-slot fulfillment: holds follow inventory rules (submitted never fills),
 * category roll-up, invites, deadline actions, cache invalidation and REST access.
 *
 * @package LNKup_Marketplace
 */

require_once __DIR__ . '/_comms_helpers.php';

$organizer = lnkup_ct_user( 'lnkup_organizer' );
$other_org = lnkup_ct_user( 'lnkup_organizer' );
$vendor    = lnkup_ct_user( 'lnkup_vendor' );
$food      = wp_insert_term( 'Fulfillment Food ' . wp_generate_password( 4, false ), 'lnkup_vendor_category' );
$clothing  = wp_insert_term( 'Fulfillment Clothing ' . wp_generate_password( 4, false ), 'lnkup_vendor_category' );
$food_id   = (int) $food['term_id'];
$cloth_id  = (int) $clothing['term_id'];

$event = lnkup_ct_event( $organizer, 30 );
$opp_a = $event['opp'];
update_post_meta( $opp_a, '_lnkup_spots_total', 5 );
wp_set_object_terms( $opp_a, array( $food_id ), 'lnkup_vendor_category' );
$opp_b = lnkup_ct_post( 'lnkup_opportunity', 'Clothing Booth', array( 'event_id' => $event['event'], 'organizer_user_id' => $organizer, 'opportunity_status' => 'open', 'spots_total' => 3, 'spots_remaining' => 3, 'application_deadline' => wp_date( 'Y-m-d', time() + 20 * DAY_IN_SECONDS ) ) );
wp_set_object_terms( $opp_b, array( $cloth_id ), 'lnkup_vendor_category' );
$event_b = array( 'event' => $event['event'], 'opp' => $opp_b );

foreach ( array( 'paid', 'paid', 'payment_required', 'submitted', 'under_review' ) as $status ) {
	lnkup_ct_application( $vendor, $organizer, $event, $status );
}
lnkup_ct_application( $vendor, $organizer, $event_b, 'waitlisted' );

echo "Totals follow inventory rules\n";
$f = LNKup_Event_Fulfillment::for_event( $event['event'], true );
lnkup_t_same( 8, $f['totals']['capacity'], 'capacity across slots' );
lnkup_t_same( 3, $f['totals']['held'], 'approved + paid hold spots' );
lnkup_t_same( 2, $f['totals']['paid'], 'paid count' );
lnkup_t_same( 1, $f['totals']['awaiting_payment'], 'approved but unpaid' );
lnkup_t_same( 5, $f['totals']['remaining'], 'remaining spots (submitted applications never fill a slot)' );
lnkup_t_same( 2, $f['totals']['pending_review'], 'pending review' );
lnkup_t_same( 1, $f['totals']['waitlisted'], 'waitlisted' );
lnkup_t_same( 0.375, $f['fill_rate'], 'fill rate = held / capacity' );

echo "Category roll-up\n";
$by_label = array();
foreach ( $f['categories'] as $cat ) {
	$by_label[ $cat['label'] ] = $cat;
}
lnkup_t_same( 2, $by_label[ get_term( $food_id )->name ]['remaining'] ?? null, 'food slots remaining' );
lnkup_t_same( 3, $by_label[ get_term( $cloth_id )->name ]['remaining'] ?? null, 'clothing slots remaining' );

echo "Actions\n";
$keys = wp_list_pluck( $f['actions'], 'key' );
lnkup_t_same( 'review_applications', $keys[0] ?? '', 'pending review is the top action' );
lnkup_t_assert( in_array( 'awaiting_payment', $keys, true ), 'unpaid approvals surfaced' );
lnkup_t_assert( ! in_array( 'deadline', $keys, true ), 'no deadline nudge while the deadline is far away' );
update_post_meta( $opp_a, '_lnkup_application_deadline', wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) );
$f = LNKup_Event_Fulfillment::for_event( $event['event'], true );
lnkup_t_assert( in_array( 'deadline', wp_list_pluck( $f['actions'], 'key' ), true ), 'deadline nudge within 7 days while spots remain' );
lnkup_t_same( 3, $f['days_to_deadline'], 'days to the nearest open deadline' );

echo "Invites\n";
foreach ( array( 'sent', 'sent', 'accepted' ) as $state ) {
	$invite = lnkup_ct_post( 'lnkup_vendor_invite', 'Fulfillment invite', array( 'event_id' => $event['event'], 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'status' => $state ) );
}
$invited_app = lnkup_ct_application( $vendor, $organizer, $event_b, 'submitted' );
update_post_meta( $invited_app, '_lnkup_invite_id', $invite );
$f = LNKup_Event_Fulfillment::for_event( $event['event'], true );
lnkup_t_same( 2, $f['invites']['by_status']['sent'], 'sent invites counted' );
lnkup_t_same( 1, $f['invites']['applications'], 'applications that came from invites' );

echo "Cache\n";
$cached = LNKup_Event_Fulfillment::for_event( $event['event'] );
$unpaid = lnkup_ct_application( $vendor, $organizer, $event_b, 'submitted' );
update_post_meta( $unpaid, '_lnkup_application_status', 'approved' );
lnkup_t_same( $cached['totals']['held'], LNKup_Event_Fulfillment::for_event( $event['event'] )['totals']['held'], 'cached read until a relevant write' );
do_action( 'lnkup_application_status_changed', $unpaid, 'submitted', 'approved' );
lnkup_t_same( $cached['totals']['held'] + 1, LNKup_Event_Fulfillment::for_event( $event['event'] )['totals']['held'], 'status change flushes the cache' );

echo "Full and past events\n";
$full = lnkup_ct_event( $organizer, 10 );
update_post_meta( $full['opp'], '_lnkup_spots_total', 1 );
lnkup_ct_application( $vendor, $organizer, $full, 'paid' );
$ff = LNKup_Event_Fulfillment::for_event( $full['event'], true );
lnkup_t_same( 0, $ff['totals']['remaining'], 'no spots remain' );
lnkup_t_same( array( 'full' ), wp_list_pluck( $ff['actions'], 'key' ), 'only the "filled" status is shown' );
$past = lnkup_ct_event( $organizer, -3 );
lnkup_t_same( array(), LNKup_Event_Fulfillment::for_event( $past['event'], true )['actions'], 'past events have no actions' );
$empty = lnkup_ct_post( 'lnkup_event', 'No slots', array( 'organizer_user_id' => $organizer, 'event_date' => wp_date( 'Y-m-d', time() + 9 * DAY_IN_SECONDS ), 'event_status' => 'live' ) );
lnkup_t_same( 'add_opportunity', LNKup_Event_Fulfillment::for_event( $empty, true )['actions'][0]['key'] ?? '', 'event without slots asks for an opportunity' );

echo "REST access\n";
$path = '/lnkup/v1/organizer/events/' . $event['event'] . '/fulfillment';
wp_set_current_user( $organizer );
$res = rest_do_request( new WP_REST_Request( 'GET', $path ) );
lnkup_t_same( 200, $res->get_status(), 'owner reads fulfillment' );
lnkup_t_assert( isset( $res->get_data()['totals']['remaining'] ), 'payload has totals' );
wp_set_current_user( $other_org );
lnkup_t_assert( in_array( rest_do_request( new WP_REST_Request( 'GET', $path ) )->get_status(), array( 401, 403 ), true ), 'another organizer is refused' );
wp_set_current_user( $vendor );
lnkup_t_assert( in_array( rest_do_request( new WP_REST_Request( 'GET', $path ) )->get_status(), array( 401, 403 ), true ), 'vendors are refused' );
wp_set_current_user( 0 );

foreach ( array( $food_id, $cloth_id ) as $term_id ) {
	wp_delete_term( $term_id, 'lnkup_vendor_category' );
}
lnkup_ct_finish();
