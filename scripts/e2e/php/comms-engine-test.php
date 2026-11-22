<?php
/**
 * Engine: dedupe, delivery, inbox, email headers, validity re-checks, cancellation,
 * frequency caps, quiet hours, global pause, retries, expiry.
 *
 * @package LNKup_Communications
 */

require_once __DIR__ . '/_comms_helpers.php';

global $wpdb;
lnkup_ct_all_flows();
// Engine mechanics run on the legacy matcher; matching-alerts-test.php covers the core match engine.
$GLOBALS['lnkup_ce_settings'] = get_option( 'lnkup_settings', array() );
update_option( 'lnkup_settings', array_merge( (array) $GLOBALS['lnkup_ce_settings'], array( 'matching_enabled' => false ) ), false );

$vendor    = lnkup_ct_user( 'lnkup_vendor' );
$organizer = lnkup_ct_user( 'lnkup_organizer' );
$profile   = lnkup_ct_profile( $vendor );
$event     = lnkup_ct_event( $organizer );
$app       = lnkup_ct_application( $vendor, $organizer, $event, 'submitted', $profile );

echo "Dispatch + dedupe\n";
$id = LNKup_Comms_Engine::dispatch( 'v_application_submitted', $vendor, array( 'application_id' => $app ) );
lnkup_t_assert( $id > 0, 'message recorded' );
lnkup_t_same( 0, LNKup_Comms_Engine::dispatch( 'v_application_submitted', $vendor, array( 'application_id' => $app ) ), 'duplicate dispatch is ignored' );
lnkup_t_same( 'scheduled', lnkup_ct_message( $id )['status'], 'message waits for the worker (never sent inline)' );

echo "Delivery\n";
$GLOBALS['lnkup_ct_mail'] = array();
LNKup_Comms_Engine::deliver( $id );
$msg = lnkup_ct_message( $id );
lnkup_t_same( 'sent', $msg['status'], 'delivered' );
lnkup_t_assert( '' !== $msg['title'], 'rendered title stored for history' );
$inbox = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Inbox_Tables::index_table() . ' WHERE user_id = %d AND client_id = %s', $vendor, 'comms-' . $id ), ARRAY_A );
lnkup_t_assert( is_array( $inbox ) && 'application_submitted' === $inbox['notification_type'], 'one inbox row written' );
$payload = json_decode( (string) ( $inbox['payload'] ?? '' ), true );
lnkup_t_same( '/booking/' . $app, $payload['deepLink'] ?? '', 'inbox row carries the in-app deep link' );
lnkup_t_same( 1, count( $GLOBALS['lnkup_ct_mail'] ), 'one email handed to wp_mail' );
$headers = implode( "\n", (array) ( $GLOBALS['lnkup_ct_mail'][0]['headers'] ?? array() ) );
lnkup_t_assert( false === strpos( $headers, 'List-Unsubscribe:' ), 'transactional email has no List-Unsubscribe' );
lnkup_t_assert( false === strpos( (string) $GLOBALS['lnkup_ct_mail'][0]['message'], '{{' ), 'no unresolved placeholders in email' );
LNKup_Comms_Engine::deliver( $id );
lnkup_t_same( 1, count( lnkup_ct_deliveries( $id, 'email' ) ), 're-running the worker does not resend' );

echo "Promotional email\n";
$applied = LNKup_Comms_Engine::dispatch( 'v_opportunity_match', $vendor, array( 'opportunity_id' => $event['opp'], 'vendor_user_id' => $vendor ) );
lnkup_t_same( 0, $applied, 'no discovery message for an opportunity the vendor already applied to' );
$disc    = lnkup_ct_event( $organizer );
$opp_msg = LNKup_Comms_Engine::dispatch( 'v_opportunity_match', $vendor, array( 'opportunity_id' => $disc['opp'], 'vendor_user_id' => $vendor ) );
lnkup_t_assert( $opp_msg > 0, 'discovery message recorded' );
LNKup_Comms_Preferences::update( $vendor, array( 'categories' => array( array( 'key' => 'opportunities', 'email' => 'immediate', 'push' => 'off' ) ) ) );
$GLOBALS['lnkup_ct_mail'] = array();
LNKup_Comms_Engine::deliver( $opp_msg );
$headers = implode( "\n", (array) ( $GLOBALS['lnkup_ct_mail'][0]['headers'] ?? array() ) );
lnkup_t_assert( false !== strpos( $headers, 'List-Unsubscribe:' ) && false !== strpos( $headers, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' ), 'promotional email has one-click unsubscribe headers' );

echo "Validity at schedule and send time\n";
$app2    = lnkup_ct_application( $vendor, $organizer, $event, 'payment_required', $profile );
$payment = lnkup_ct_payment( $app2, $vendor, $organizer, 'pending' );
$rem     = LNKup_Comms_Engine::dispatch( 'v_payment_reminder', $vendor, array( 'application_id' => $app2, 'window_label' => 'tomorrow' ), array( 'window' => 't1', 'at' => time() + HOUR_IN_SECONDS, 'cancel_key' => 'app:' . $app2 . '|payment' ) );
lnkup_t_assert( $rem > 0, 'payment reminder scheduled while payment is due' );
update_post_meta( $payment, '_lnkup_payment_status', 'paid' );
lnkup_t_same( 'cancelled', lnkup_ct_message( $rem )['status'], 'payment status hook cancels the scheduled reminder' );

$app2b    = lnkup_ct_application( $vendor, $organizer, $event, 'payment_required', $profile );
$payment2 = lnkup_ct_payment( $app2b, $vendor, $organizer, 'pending' );
$rem2     = LNKup_Comms_Engine::dispatch( 'v_payment_reminder', $vendor, array( 'application_id' => $app2b, 'window_label' => 'tomorrow' ), array( 'window' => 't1', 'at' => time() + HOUR_IN_SECONDS ) );
// Bypass hooks entirely (e.g. a bulk SQL fix) — the send-time re-check must still catch it.
$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'paid' ), array( 'post_id' => $payment2, 'meta_key' => '_lnkup_payment_status' ) );
wp_cache_delete( $payment2, 'post_meta' );
$wpdb->update( LNKup_Comms_Schema::table( 'messages' ), array( 'scheduled_at' => LNKup_Comms_Schema::now( -60 ) ), array( 'id' => $rem2 ) );
LNKup_Comms_Engine::deliver( $rem2 );
lnkup_t_same( 'suppressed', lnkup_ct_message( $rem2 )['status'], 'reminder suppressed at send time when state changed without hooks' );
update_post_meta( $app2, '_lnkup_application_status', 'paid' );
lnkup_t_same( 0, LNKup_Comms_Engine::dispatch( 'v_payment_reminder', $vendor, array( 'application_id' => $app2, 'window_label' => 'tomorrow' ), array( 'window' => 't2' ) ), 'reminder not even scheduled once paid' );

echo "Cancellation\n";
$app3 = lnkup_ct_application( $vendor, $organizer, $event, 'payment_required', $profile );
lnkup_ct_payment( $app3, $vendor, $organizer, 'pending' );
$r1 = LNKup_Comms_Engine::dispatch( 'v_payment_reminder', $vendor, array( 'application_id' => $app3, 'window_label' => 'in 3 days' ), array( 'window' => 'd3', 'at' => time() + DAY_IN_SECONDS, 'cancel_key' => 'app:' . $app3 . '|payment' ) );
lnkup_t_same( 1, LNKup_Comms_Engine::cancel( 'app:' . $app3 . '|*' ), 'prefix cancel hits payment reminders' );
lnkup_t_same( 'cancelled', lnkup_ct_message( $r1 )['status'], 'cancelled reminder will not send' );

echo "Authorization\n";
$other = lnkup_ct_user( 'lnkup_vendor' );
lnkup_t_same( 0, LNKup_Comms_Engine::dispatch( 'v_application_submitted', $other, array( 'application_id' => $app ), array( 'window' => 'x' ) ), 'cannot message a vendor about someone else\'s application' );
$stranger_org = lnkup_ct_user( 'lnkup_organizer' );
lnkup_t_same( 0, LNKup_Comms_Engine::dispatch( 'o_application_received', $stranger_org, array( 'application_id' => $app ) ), 'cannot message an organizer about an event they do not own' );

echo "Frequency cap + quiet hours (push)\n";
lnkup_ct_device( $vendor );
LNKup_Comms_Preferences::update( $vendor, array( 'categories' => array( array( 'key' => 'opportunities', 'email' => 'off', 'push' => 'on' ) ) ) );
lnkup_ct_settings( array( 'cap_promo_push_daily' => 1 ) );
$sent_states = array();
for ( $i = 0; $i < 2; $i++ ) {
	$extra = lnkup_ct_event( $organizer );
	$mid   = LNKup_Comms_Engine::dispatch( 'v_opportunity_match', $vendor, array( 'opportunity_id' => $extra['opp'], 'vendor_user_id' => $vendor ) );
	LNKup_Comms_Engine::deliver( $mid );
	$push          = lnkup_ct_deliveries( $mid, 'push' );
	$sent_states[] = $push ? $push[0]['status'] . ':' . $push[0]['suppression_reason'] : 'none';
}
lnkup_t_same( 'provider_accepted:', $sent_states[0], 'first promotional push accepted' );
lnkup_t_same( 'suppressed:frequency_cap', $sent_states[1], 'second promotional push capped' );

$now   = new DateTimeImmutable( 'now', wp_timezone() );
LNKup_Comms_Preferences::update( $vendor, array( 'quietHours' => array( 'enabled' => true, 'start' => $now->modify( '-1 hour' )->format( 'H:i' ), 'end' => $now->modify( '+2 hours' )->format( 'H:i' ) ), 'timezone' => wp_timezone()->getName() ) );
lnkup_t_assert( LNKup_Comms_Preferences::quiet_seconds_remaining( $vendor ) > 0, 'recipient is in quiet hours' );
$waitlist = lnkup_ct_application( $vendor, $organizer, $event, 'waitlisted', $profile );
$qid      = LNKup_Comms_Engine::dispatch( 'v_application_waitlisted', $vendor, array( 'application_id' => $waitlist ) );
LNKup_Comms_Engine::deliver( $qid );
$push = lnkup_ct_deliveries( $qid, 'push' );
lnkup_t_same( 'deferred', $push[0]['status'] ?? '', 'normal push deferred during quiet hours' );
$cancel_app = lnkup_ct_application( $vendor, $organizer, $event, 'paid', $profile );
update_post_meta( $event['event'], '_lnkup_event_status', 'cancelled' );
$cid = LNKup_Comms_Engine::dispatch( 'v_event_cancelled', $vendor, array( 'application_id' => $cancel_app ), array( 'variant' => 'cancelled' ) );
LNKup_Comms_Engine::deliver( $cid );
$push = lnkup_ct_deliveries( $cid, 'push' );
lnkup_t_same( 'provider_accepted', $push[0]['status'] ?? '', 'critical push ignores quiet hours' );
update_post_meta( $event['event'], '_lnkup_event_status', 'live' );
LNKup_Comms_Preferences::update( $vendor, array( 'quietHours' => array( 'enabled' => false ) ) );

echo "Global pause, expiry, transient failure retry\n";
lnkup_ct_settings( array( 'delivery_paused' => true ) );
$app4 = lnkup_ct_application( $vendor, $organizer, $event, 'rejected', $profile );
$pid  = LNKup_Comms_Engine::dispatch( 'v_application_rejected', $vendor, array( 'application_id' => $app4 ) );
LNKup_Comms_Engine::deliver( $pid );
$paused = lnkup_ct_message( $pid );
lnkup_t_assert( 'scheduled' === $paused['status'] && strtotime( $paused['scheduled_at'] . ' UTC' ) > time() + 600, 'paused delivery is rescheduled, not dropped' );
lnkup_ct_settings( array( 'delivery_paused' => false ) );
$wpdb->update( LNKup_Comms_Schema::table( 'messages' ), array( 'scheduled_at' => LNKup_Comms_Schema::now( -60 ), 'expires_at' => LNKup_Comms_Schema::now( -30 ) ), array( 'id' => $pid ) );
LNKup_Comms_Engine::deliver( $pid );
lnkup_t_same( 'expired', lnkup_ct_message( $pid )['status'], 'expired message is not sent' );

$GLOBALS['lnkup_ct_expo']['ticket'] = 'unavailable';
$app5 = lnkup_ct_application( $vendor, $organizer, $event, 'info_requested', $profile );
$fid  = LNKup_Comms_Engine::dispatch( 'v_info_requested', $vendor, array( 'application_id' => $app5 ) );
LNKup_Comms_Engine::deliver( $fid );
$push = lnkup_ct_deliveries( $fid, 'push' );
lnkup_t_same( 'failed', $push[0]['status'] ?? '', 'Expo outage recorded as failed' );
lnkup_t_same( 'partial', lnkup_ct_message( $fid )['status'], 'inbox + email still delivered during push outage (partial)' );
lnkup_t_assert( as_has_scheduled_action( 'lnkup_comms_retry', array( $fid, 'push' ), LNKup_Comms_Queue::GROUP ), 'push retry scheduled with backoff' );
$GLOBALS['lnkup_ct_expo']['ticket'] = 'ok';
LNKup_Comms_Engine::retry_channel( $fid, 'push' );
$statuses = wp_list_pluck( lnkup_ct_deliveries( $fid, 'push' ), 'status' );
lnkup_t_assert( in_array( 'provider_accepted', $statuses, true ), 'retry succeeds once Expo recovers' );
as_unschedule_all_actions( 'lnkup_comms_retry', array( $fid, 'push' ), LNKup_Comms_Queue::GROUP );

echo "Rollback: flag turned off after scheduling\n";
$app6 = lnkup_ct_application( $vendor, $organizer, $event, 'rejected', $profile );
$q1   = LNKup_Comms_Engine::dispatch( 'v_application_rejected', $vendor, array( 'application_id' => $app6 ), array( 'window' => 'rb1' ) );
$q2   = LNKup_Comms_Engine::dispatch( 'v_application_rejected', $vendor, array( 'application_id' => $app6 ), array( 'window' => 'rb2', 'force' => true, 'source' => 'core_adapter' ) );
$q3   = LNKup_Comms_Engine::dispatch( 'v_application_rejected', $vendor, array( 'application_id' => $app6 ), array( 'window' => 'rb3', 'force' => true, 'source' => 'cli_test' ) );
lnkup_t_assert( $q1 > 0 && $q2 > 0 && $q3 > 0, 'three messages queued while the flow is on' );
lnkup_ct_settings( array( 'flow_applications' => false ) );
foreach ( array( $q1, $q2, $q3 ) as $qid ) {
	LNKup_Comms_Engine::deliver( $qid );
}
lnkup_t_same( array( 'suppressed', 'flag_off:flow_applications' ), array( lnkup_ct_message( $q1 )['status'], lnkup_ct_message( $q1 )['suppression_reason'] ), 'queued message is suppressed once its flag is off (no double send with core)' );
lnkup_t_same( 'suppressed', lnkup_ct_message( $q2 )['status'], 'forced core-adapter messages also follow the flag' );
lnkup_t_same( 'sent', lnkup_ct_message( $q3 )['status'], 'explicit test sends still deliver with the flag off' );
lnkup_ct_settings( array( 'flow_applications' => true ) );

echo "Account deletion purge\n";
LNKup_Comms_Engine::purge_user( $other );
lnkup_t_same( array(), lnkup_ct_messages_for( $other ), 'purge removes message history' );

update_option( 'lnkup_settings', $GLOBALS['lnkup_ce_settings'], false );
lnkup_ct_finish();
