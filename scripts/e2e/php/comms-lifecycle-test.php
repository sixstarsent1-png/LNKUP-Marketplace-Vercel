<?php
/**
 * Core lifecycle → messages: status hooks, withdrawal, event cancellation,
 * first-vs-grouped organizer alerts, verified payment completion, check-in summaries,
 * and the meta watcher hooks added to lnkup-marketplace.
 *
 * @package LNKup_Communications
 */

require_once __DIR__ . '/_comms_helpers.php';

global $wpdb;
lnkup_ct_all_flows();

$vendor    = lnkup_ct_user( 'lnkup_vendor' );
$vendor2   = lnkup_ct_user( 'lnkup_vendor' );
$organizer = lnkup_ct_user( 'lnkup_organizer' );
$profile   = lnkup_ct_profile( $vendor );
$profile2  = lnkup_ct_profile( $vendor2 );
$event     = lnkup_ct_event( $organizer );

echo "Meta watcher hooks (lnkup-marketplace)\n";
$app     = lnkup_ct_application( $vendor, $organizer, $event, 'payment_required', $profile );
$payment = lnkup_ct_payment( $app, $vendor, $organizer, 'pending' );
$before  = did_action( 'lnkup_payment_status_changed' );
update_post_meta( $payment, '_lnkup_payment_status', 'processing' );
lnkup_t_same( $before + 1, did_action( 'lnkup_payment_status_changed' ), 'payment status change fires lnkup_payment_status_changed' );
update_post_meta( $payment, '_lnkup_payment_status', 'processing' );
lnkup_t_same( $before + 1, did_action( 'lnkup_payment_status_changed' ), 'unchanged value does not fire' );
$before = did_action( 'lnkup_opportunity_status_changed' );
update_post_meta( $event['opp'], '_lnkup_opportunity_status', 'closed' );
lnkup_t_same( $before + 1, did_action( 'lnkup_opportunity_status_changed' ), 'opportunity status change fires hook' );
update_post_meta( $event['opp'], '_lnkup_opportunity_status', 'open' );

echo "Submitted: vendor confirmation + first application immediate, then grouped\n";
$fresh = lnkup_ct_event( $organizer );
$a1    = lnkup_ct_application( $vendor, $organizer, $fresh, 'submitted', $profile );
do_action( 'lnkup_application_submitted', $a1 );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $vendor, 'v_application_submitted' ) ), 'vendor gets a submission confirmation' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $organizer, 'o_application_received' ) ), 'first application alerts the organizer immediately' );
$a2 = lnkup_ct_application( $vendor2, $organizer, $fresh, 'submitted', $profile2 );
do_action( 'lnkup_application_submitted', $a2 );
$a3 = lnkup_ct_application( $vendor2, $organizer, $fresh, 'submitted', $profile2 );
do_action( 'lnkup_application_submitted', $a3 );
$grouped = lnkup_ct_messages_for( $organizer, 'o_applications_grouped' );
lnkup_t_same( 1, count( $grouped ), 'burst of later applications becomes ONE grouped message' );
lnkup_t_assert( strtotime( $grouped[0]['scheduled_at'] . ' UTC' ) > time(), 'grouped message waits for the end of the burst window' );
update_post_meta( $a2, '_lnkup_application_status', 'rejected' );
update_post_meta( $a3, '_lnkup_application_status', 'rejected' );
update_post_meta( $a1, '_lnkup_application_status', 'under_review' );
update_post_meta( $a1, '_lnkup_application_status', 'rejected' );
$wpdb->update( LNKup_Comms_Schema::table( 'messages' ), array( 'scheduled_at' => LNKup_Comms_Schema::now( -60 ) ), array( 'id' => (int) $grouped[0]['id'] ) );
LNKup_Comms_Engine::deliver( (int) $grouped[0]['id'] );
lnkup_t_same( 'suppressed', lnkup_ct_message( (int) $grouped[0]['id'] )['status'], 'grouped alert suppressed once the queue was cleared' );

echo "Approval → payment reminders → paid\n";
update_post_meta( $app, '_lnkup_payment_due_at', gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) );
update_post_meta( $payment, '_lnkup_payment_status', 'pending' );
do_action( 'lnkup_application_status_changed', $app, 'submitted', 'payment_required' );
$approved = lnkup_ct_messages_for( $vendor, 'v_application_approved' );
lnkup_t_same( 1, count( $approved ), 'approval message queued' );
lnkup_t_same( 'v_application_approved:payment', $approved[0]['template_key'] ?? '', 'approval CTA is the payment step when payment is due' );
$reminders = lnkup_ct_messages_for( $vendor, 'v_payment_reminder' );
lnkup_t_same( 2, count( $reminders ), '72h and 24h payment reminders scheduled from the real deadline' );
update_post_meta( $payment, '_lnkup_payment_status', 'paid' );
update_post_meta( $app, '_lnkup_application_status', 'paid' );
// Only the comms listener: firing the real hook would run financial ledger listeners on a fixture payment.
LNKup_Comms_Listen_Payments::on_completed( $payment );
$statuses = array_unique( wp_list_pluck( lnkup_ct_messages_for( $vendor, 'v_payment_reminder' ), 'status' ) );
lnkup_t_same( array( 'cancelled' ), array_values( $statuses ), 'payment cancels every pending reminder' );
do_action( 'lnkup_application_status_changed', $app, 'payment_required', 'paid' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $vendor, 'v_booking_confirmed' ) ), 'one booking confirmation despite two paid signals' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $organizer, 'o_vendor_paid' ) ), 'organizer told once that the vendor paid' );

echo "Failed payment (verified) vs voided payment\n";
$app_f = lnkup_ct_application( $vendor2, $organizer, $event, 'payment_required', $profile2 );
$pay_f = lnkup_ct_payment( $app_f, $vendor2, $organizer, 'pending' );
update_post_meta( $pay_f, '_lnkup_payment_status', 'failed' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $vendor2, 'v_payment_failed' ) ), 'declined payment queues a failure notice' );
$app_v = lnkup_ct_application( $vendor2, $organizer, $event, 'payment_required', $profile2 );
$pay_v = lnkup_ct_payment( $app_v, $vendor2, $organizer, 'pending' );
update_post_meta( $app_v, '_lnkup_application_status', 'rejected' );
update_post_meta( $pay_v, '_lnkup_payment_status', 'failed' );
update_post_meta( $pay_v, '_lnkup_voided_at', current_time( 'mysql' ) );
$voided = array_values( array_filter( lnkup_ct_messages_for( $vendor2, 'v_payment_failed' ), static function ( $m ) use ( $app_v ) {
	return (int) $m['application_id'] === $app_v;
} ) );
lnkup_t_same( array(), $voided, 'voiding a payment for a rejected application does not send "payment failed"' );

echo "Withdrawal (new core feature)\n";
$app_w = lnkup_ct_application( $vendor, $organizer, $event, 'submitted', $profile );
$res   = LNKup_Applications::withdraw( $app_w, $vendor2 );
lnkup_t_assert( is_wp_error( $res ), 'another vendor cannot withdraw this application' );
$res = LNKup_Applications::withdraw( $app_w, $vendor );
lnkup_t_assert( true === $res, 'owner can withdraw a submitted application' );
lnkup_t_same( 'cancelled', get_post_meta( $app_w, '_lnkup_application_status', true ), 'status uses the existing "cancelled" state' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $vendor, 'v_application_withdrawn' ) ), 'vendor gets a withdrawal confirmation' );
lnkup_t_same( 0, count( lnkup_ct_messages_for( $vendor, 'v_application_cancelled' ) ), 'withdrawal is not reported as an organizer cancellation' );
$paid_app = lnkup_ct_application( $vendor, $organizer, $event, 'paid', $profile );
lnkup_t_assert( is_wp_error( LNKup_Applications::withdraw( $paid_app, $vendor ) ), 'paid bookings cannot be withdrawn (refund flow instead)' );

echo "Event cancellation (new core feature)\n";
$cancel_event = lnkup_ct_event( $organizer );
$c_app        = lnkup_ct_application( $vendor, $organizer, $cancel_event, 'paid', $profile );
$countdown    = LNKup_Comms_Engine::dispatch( 'v_countdown', $vendor, array( 'application_id' => $c_app, 'window_label' => 'in 1 week', 'tasks_line' => '' ), array( 'variant' => 'ready', 'window' => 'd7', 'at' => time() + DAY_IN_SECONDS ) );
lnkup_t_assert( is_wp_error( LNKup_Event_Status::set_status( $cancel_event['event'], 'cancelled', 'Weather', $vendor ) ) === false, 'organizer status change accepted by service' );
lnkup_t_same( 'cancelled', get_post_meta( $cancel_event['event'], '_lnkup_event_status', true ), 'event marked cancelled' );
lnkup_t_same( 'closed', get_post_meta( $cancel_event['opp'], '_lnkup_opportunity_status', true ), 'vendor slots closed' );
lnkup_t_same( 'cancelled', lnkup_ct_message( $countdown )['status'], 'scheduled countdown cancelled' );
$notice = lnkup_ct_messages_for( $vendor, 'v_event_cancelled' );
lnkup_t_same( 'critical', $notice[0]['priority'] ?? '', 'vendors get a critical cancellation notice' );
LNKup_Organizer_Onboarding::sync_event_listing_status( $cancel_event['event'] );
lnkup_t_same( 'cancelled', get_post_meta( $cancel_event['event'], '_lnkup_event_status', true ), 'listing sync does not overwrite cancellation' );
$req = new WP_REST_Request( 'POST', '/lnkup/v1/organizer/events/' . $cancel_event['event'] . '/status' );
$req->set_url_params( array( 'id' => $cancel_event['event'] ) );
wp_set_current_user( $vendor );
lnkup_t_assert( ! LNKup_Event_Status::can_manage( $req ), 'a vendor cannot cancel an organizer event via REST' );
wp_set_current_user( $organizer );
lnkup_t_assert( LNKup_Event_Status::can_manage( $req ), 'the owning organizer can' );
wp_set_current_user( 0 );

echo "Check-in summary grouping\n";
$ci_event = lnkup_ct_event( $organizer );
$ci_a     = lnkup_ct_application( $vendor, $organizer, $ci_event, 'paid', $profile );
$ci_b     = lnkup_ct_application( $vendor2, $organizer, $ci_event, 'paid', $profile2 );
do_action( 'lnkup_checkin_recorded', $ci_event['event'], $ci_a, 'checked_in' );
do_action( 'lnkup_checkin_recorded', $ci_event['event'], $ci_b, 'checked_in' );
$summaries = array_values( array_filter( lnkup_ct_messages_for( $organizer, 'o_checkin_summary' ), static function ( $m ) use ( $ci_event ) {
	return (int) $m['event_id'] === $ci_event['event'];
} ) );
lnkup_t_same( 1, count( $summaries ), 'two check-ins → one organizer summary' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $vendor2, 'v_checked_in' ) ), 'each vendor gets their own check-in confirmation' );

echo "Flag off → no messages\n";
lnkup_ct_settings( array( 'flow_documents' => false ) );
$doc = lnkup_ct_post( 'lnkup_document', 'Test doc', array( 'vendor_user_id' => $vendor, 'document_type' => 'w9', 'verification_status' => 'pending' ) );
update_post_meta( $doc, '_lnkup_verification_status', 'approved' );
lnkup_t_same( 0, count( lnkup_ct_messages_for( $vendor, 'v_document_approved' ) ), 'disabled flow sends nothing' );
lnkup_ct_settings( array( 'flow_documents' => true ) );
update_post_meta( $doc, '_lnkup_verification_status', 'rejected' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $vendor, 'v_document_rejected' ) ), 'enabled flow: rejection notice queued' );

lnkup_ct_finish();
