<?php
/**
 * Preferences, legacy push sync, digests, quiet hours, unsubscribe and suppression.
 *
 * @package LNKup_Communications
 */

require_once __DIR__ . '/_comms_helpers.php';

lnkup_ct_all_flows();
$vendor    = lnkup_ct_user( 'lnkup_vendor' );
$organizer = lnkup_ct_user( 'lnkup_organizer' );
$profile   = lnkup_ct_profile( $vendor );
$event     = lnkup_ct_event( $organizer );

echo "Defaults and role scoping\n";
$prefs = LNKup_Comms_Preferences::get( $vendor );
$keys  = wp_list_pluck( $prefs['categories'], 'key' );
lnkup_t_assert( in_array( 'opportunities', $keys, true ) && ! in_array( 'recruitment', $keys, true ), 'vendors see vendor categories only' );
lnkup_t_same( 'weekly', LNKup_Comms_Preferences::email_mode( $vendor, 'opportunities' ), 'opportunity emails default to a weekly digest' );
lnkup_t_same( 'immediate', LNKup_Comms_Preferences::email_mode( $vendor, 'payments' ), 'payment emails default to immediate' );

echo "Update + locked categories\n";
LNKup_Comms_Preferences::update( $vendor, array( 'categories' => array( array( 'key' => 'account', 'email' => 'off', 'push' => 'off' ), array( 'key' => 'payments', 'email' => 'daily', 'push' => 'off' ), array( 'key' => 'recruitment', 'email' => 'off' ) ) ) );
lnkup_t_same( 'immediate', LNKup_Comms_Preferences::email_mode( $vendor, 'account' ), 'account & security cannot be turned off' );
lnkup_t_same( 'daily', LNKup_Comms_Preferences::email_mode( $vendor, 'payments' ), 'payments switched to daily digest' );
lnkup_t_assert( ! LNKup_Comms_Preferences::push_on( $vendor, 'payments' ), 'payments push off' );
$legacy = LNKup_Push_Service::get_user_categories( $vendor );
lnkup_t_assert( false === $legacy['payments'], 'legacy core push category mirrors the change' );

echo "Legacy /push/preferences writes sync back\n";
LNKup_Push_Service::set_user_categories( $vendor, array( 'discovery' => false ) );
lnkup_t_assert( ! LNKup_Comms_Preferences::push_on( $vendor, 'opportunities' ), 'old app toggle reaches the new preferences' );

echo "Digest diversion\n";
$app = lnkup_ct_application( $vendor, $organizer, $event, 'paid', $profile );
lnkup_ct_payment( $app, $vendor, $organizer, 'paid' );
$receipt = LNKup_Comms_Engine::dispatch( 'v_refund_completed', $vendor, array( 'application_id' => $app ), array( 'force' => true ) );
lnkup_t_same( 0, $receipt, 'refund notice is not valid for a paid (unrefunded) payment' );
$booking = LNKup_Comms_Engine::dispatch( 'v_booking_confirmed', $vendor, array( 'application_id' => $app ), array( 'priority' => 'normal' ) );
$GLOBALS['lnkup_ct_mail'] = array();
LNKup_Comms_Engine::deliver( $booking );
$email = lnkup_ct_deliveries( $booking, 'email' );
lnkup_t_same( 'digest_pending', $email[0]['status'] ?? '', 'normal-priority payment email held for the daily digest' );
lnkup_t_same( 0, count( $GLOBALS['lnkup_ct_mail'] ), 'no immediate email sent' );
LNKup_Comms_Auto_Digests::email_digests( 'daily' );
$digests = lnkup_ct_messages_for( $vendor, 'digest_email' );
lnkup_t_same( 1, count( $digests ), 'one digest message created' );
LNKup_Comms_Engine::deliver( (int) $digests[0]['id'] );
lnkup_t_same( 1, count( $GLOBALS['lnkup_ct_mail'] ), 'digest email delivered once' );
lnkup_t_same( 'digested', lnkup_ct_deliveries( $booking, 'email' )[0]['status'] ?? '', 'held email marked digested' );

echo "Quiet hours window math\n";
update_user_meta( $vendor, LNKup_Comms_Preferences::META_TZ, 'UTC' );
LNKup_Comms_Preferences::update( $vendor, array( 'quietHours' => array( 'enabled' => true, 'start' => '22:00', 'end' => '08:00' ) ) );
$at_23 = strtotime( gmdate( 'Y-m-d' ) . ' 23:00:00 UTC' );
$at_12 = strtotime( gmdate( 'Y-m-d' ) . ' 12:00:00 UTC' );
lnkup_t_same( 9 * HOUR_IN_SECONDS, LNKup_Comms_Preferences::quiet_seconds_remaining( $vendor, $at_23 ), 'overnight window: 23:00 → 9h until 08:00' );
lnkup_t_same( 0, LNKup_Comms_Preferences::quiet_seconds_remaining( $vendor, $at_12 ), 'noon is outside quiet hours' );

echo "Unsubscribe tokens + suppression\n";
$token = LNKup_Comms_Suppression::token( $vendor, 'marketing' );
lnkup_t_same( array( 'user_id' => $vendor, 'scope' => 'marketing' ), LNKup_Comms_Suppression::parse_token( $token ), 'token round-trips' );
lnkup_t_same( null, LNKup_Comms_Suppression::parse_token( str_replace( '.', 'x.', $token ) ), 'tampered token rejected' );
$forged = rtrim( strtr( base64_encode( $organizer . '.marketing' ), '+/', '-_' ), '=' ) . '.' . explode( '.', $token )[1];
lnkup_t_same( null, LNKup_Comms_Suppression::parse_token( $forged ), 'signature from another user cannot be reused' );
LNKup_Comms_Suppression::apply( $vendor, 'marketing' );
$email_addr = get_userdata( $vendor )->user_email;
lnkup_t_same( 'off', LNKup_Comms_Preferences::email_mode( $vendor, 'opportunities' ), 'marketing unsubscribe turns promotional email off' );
lnkup_t_assert( '' !== LNKup_Comms_Suppression::check_email( $email_addr, 'opportunities', true ), 'promotional email suppressed' );
lnkup_t_same( '', LNKup_Comms_Suppression::check_email( $email_addr, 'payments', false ), 'transactional email still allowed' );

echo "Provider webhooks\n";
$events = LNKup_Comms_Suppression::normalize_events( 'sendgrid', array( array( 'event' => 'bounce', 'email' => 'bounce@example.test' ), array( 'event' => 'spamreport', 'email' => 'spam@example.test' ), array( 'event' => 'open', 'email' => 'x@example.test' ) ) );
lnkup_t_same( array( 'bounce', 'complaint' ), wp_list_pluck( $events, 'type' ), 'SendGrid events normalized (opens ignored)' );
$events = LNKup_Comms_Suppression::normalize_events( 'postmark', array( 'RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Email' => 'soft@example.test' ) );
lnkup_t_same( array(), $events, 'soft bounces do not suppress' );
LNKup_Comms_Settings::update( array( 'email_webhook_secret' => '' ) );
$req = new WP_REST_Request( 'POST', '/lnkup/v1/comms/email-webhook/generic' );
lnkup_t_assert( ! LNKup_Comms_Suppression::verify_webhook( $req ), 'webhook rejected when no secret is configured' );
LNKup_Comms_Settings::update( array( 'email_webhook_secret' => 'test-secret' ) );
$req->set_header( 'X-LNKup-Webhook-Secret', 'wrong' );
lnkup_t_assert( ! LNKup_Comms_Suppression::verify_webhook( $req ), 'wrong secret rejected' );
$req->set_header( 'X-LNKup-Webhook-Secret', 'test-secret' );
lnkup_t_assert( LNKup_Comms_Suppression::verify_webhook( $req ), 'correct secret accepted' );

global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Comms_Schema::table( 'suppressions' ) . ' WHERE email_hash IN (%s, %s, %s)', LNKup_Comms_Suppression::hash_email( $email_addr ), LNKup_Comms_Suppression::hash_email( 'bounce@example.test' ), LNKup_Comms_Suppression::hash_email( 'spam@example.test' ) ) ); // phpcs:ignore WordPress.DB
lnkup_ct_finish();
