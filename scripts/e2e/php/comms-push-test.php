<?php
/**
 * Push: multi-device fan-out, one inbox row, tickets, receipts, invalid token cleanup,
 * payload safety.
 *
 * @package LNKup_Communications
 */

require_once __DIR__ . '/_comms_helpers.php';

global $wpdb;
lnkup_ct_all_flows();
$vendor    = lnkup_ct_user( 'lnkup_vendor' );
$organizer = lnkup_ct_user( 'lnkup_organizer' );
$profile   = lnkup_ct_profile( $vendor );
$event     = lnkup_ct_event( $organizer );
$devices   = LNKup_Push_Tables::table_name( LNKup_Push_Tables::DEVICES );

echo "Multi-device\n";
$t1 = lnkup_ct_device( $vendor, 'a' );
$t2 = lnkup_ct_device( $vendor, 'b' );
LNKup_Push_Service::register_device( $vendor, $t1, 'ios', 'test' );
lnkup_t_same( 2, count( LNKup_Comms_Channel_Push::devices( $vendor ) ), 're-registering the same token does not duplicate devices' );
$app = lnkup_ct_application( $vendor, $organizer, $event, 'waitlisted', $profile );
$GLOBALS['lnkup_ct_expo']['send'] = array();
$id  = LNKup_Comms_Engine::dispatch( 'v_application_waitlisted', $vendor, array( 'application_id' => $app ) );
LNKup_Comms_Engine::deliver( $id );
$push = lnkup_ct_deliveries( $id, 'push' );
lnkup_t_same( 2, count( $push ), 'one push delivery per active device' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Inbox_Tables::index_table() . ' WHERE user_id = %d AND client_id = %s', $vendor, 'comms-' . $id ) ), 'only one logical inbox record' ); // phpcs:ignore WordPress.DB
lnkup_t_assert( 0 === strpos( (string) $push[0]['provider_ref'], 'ticket-' ), 'Expo ticket id stored' );

echo "Payload safety\n";
$sent = $GLOBALS['lnkup_ct_expo']['send'][0][0] ?? array();
lnkup_t_same( '/booking/' . $app, $sent['data']['href'] ?? '', 'push carries the in-app route' );
lnkup_t_same( (string) $id, $sent['data']['notificationId'] ?? '', 'push carries the notification id for open tracking' );
$allowed_keys = array( 'type', 'href', 'notificationId', 'category', 'applicationId', 'eventId', 'opportunityId', 'tourId' );
lnkup_t_same( array(), array_diff( array_keys( (array) ( $sent['data'] ?? array() ) ), $allowed_keys ), 'push data contains only IDs and routing' );
lnkup_t_assert( ! preg_match( '/\$\s?\d/', (string) ( $sent['body'] ?? '' ) ), 'no amounts in push body' );
lnkup_t_assert( isset( $sent['badge'] ), 'iOS badge set from unread count' );

echo "Receipts\n";
$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Comms_Schema::table( 'deliveries' ) . ' SET created_at = %s WHERE message_id = %d', LNKup_Comms_Schema::now( -20 * MINUTE_IN_SECONDS ), $id ) ); // phpcs:ignore WordPress.DB
$GLOBALS['lnkup_ct_expo']['receipt'] = 'ok';
LNKup_Comms_Channel_Push::check_receipts();
lnkup_t_same( array( 'delivered', 'delivered' ), wp_list_pluck( lnkup_ct_deliveries( $id, 'push' ), 'status' ), 'receipts "ok" mark tickets delivered (to APNs/FCM)' );

$app2 = lnkup_ct_application( $vendor, $organizer, $event, 'rejected', $profile );
$id2  = LNKup_Comms_Engine::dispatch( 'v_application_rejected', $vendor, array( 'application_id' => $app2 ) );
LNKup_Comms_Engine::deliver( $id2 );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Comms_Schema::table( 'deliveries' ) . ' SET created_at = %s WHERE message_id = %d', LNKup_Comms_Schema::now( -20 * MINUTE_IN_SECONDS ), $id2 ) ); // phpcs:ignore WordPress.DB
$GLOBALS['lnkup_ct_expo']['receipt'] = 'DeviceNotRegistered';
LNKup_Comms_Channel_Push::check_receipts();
lnkup_t_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$devices} WHERE user_id = %d AND enabled = 1", $vendor ) ), 'DeviceNotRegistered receipts disable the tokens' ); // phpcs:ignore WordPress.DB
$GLOBALS['lnkup_ct_expo']['receipt'] = 'ok';

echo "Ticket-level DeviceNotRegistered + re-registration\n";
LNKup_Push_Service::register_device( $vendor, $t1, 'ios', 'test' );
lnkup_t_same( 1, count( LNKup_Comms_Channel_Push::devices( $vendor ) ), 're-registering re-enables a disabled token' );
$GLOBALS['lnkup_ct_expo']['ticket'] = 'DeviceNotRegistered';
$app3 = lnkup_ct_application( $vendor, $organizer, $event, 'info_requested', $profile );
$id3  = LNKup_Comms_Engine::dispatch( 'v_info_requested', $vendor, array( 'application_id' => $app3 ) );
LNKup_Comms_Engine::deliver( $id3 );
lnkup_t_same( array(), LNKup_Comms_Channel_Push::devices( $vendor ), 'token disabled immediately on a DeviceNotRegistered ticket' );
$GLOBALS['lnkup_ct_expo']['ticket'] = 'ok';

echo "No device\n";
$app4 = lnkup_ct_application( $vendor, $organizer, $event, 'waitlisted', $profile );
$id4  = LNKup_Comms_Engine::dispatch( 'v_application_waitlisted', $vendor, array( 'application_id' => $app4 ), array( 'window' => 'again' ) );
LNKup_Comms_Engine::deliver( $id4 );
lnkup_t_same( 'no_active_device', lnkup_ct_deliveries( $id4, 'push' )[0]['suppression_reason'] ?? '', 'no device is a suppression, not a failure' );
lnkup_t_same( 'sent', lnkup_ct_message( $id4 )['status'], 'inbox/email still delivered' );

echo "Master push switch\n";
lnkup_ct_device( $vendor, 'c' );
LNKup_Push_Service::set_user_push_enabled( $vendor, false );
$app5 = lnkup_ct_application( $vendor, $organizer, $event, 'waitlisted', $profile );
$id5  = LNKup_Comms_Engine::dispatch( 'v_application_waitlisted', $vendor, array( 'application_id' => $app5 ), array( 'window' => 'master' ) );
LNKup_Comms_Engine::deliver( $id5 );
lnkup_t_same( 'preference_off', lnkup_ct_deliveries( $id5, 'push' )[0]['suppression_reason'] ?? '', 'master push switch respected' );
LNKup_Push_Service::set_user_push_enabled( $vendor, true );

lnkup_ct_finish();
