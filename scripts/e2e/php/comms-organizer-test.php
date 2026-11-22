<?php
/**
 * Organizer event communications, campaigns, countdown windows, next steps, matching.
 *
 * @package LNKup_Communications
 */

require_once __DIR__ . '/_comms_helpers.php';

global $wpdb;
lnkup_ct_all_flows();
$organizer = lnkup_ct_user( 'lnkup_organizer' );
$other_org = lnkup_ct_user( 'lnkup_organizer' );
$vendor    = lnkup_ct_user( 'lnkup_vendor' );
$vendor2   = lnkup_ct_user( 'lnkup_vendor' );
$outsider  = lnkup_ct_user( 'lnkup_vendor' );
$profile   = lnkup_ct_profile( $vendor );
$profile2  = lnkup_ct_profile( $vendor2 );
lnkup_ct_profile( $outsider );
$event     = lnkup_ct_event( $organizer );
$paid      = lnkup_ct_application( $vendor, $organizer, $event, 'paid', $profile );
$pending   = lnkup_ct_application( $vendor2, $organizer, $event, 'submitted', $profile2 );

echo "Audiences\n";
$confirmed = LNKup_Comms_Audiences::event_group_members( $event['event'], 'confirmed' );
lnkup_t_same( array( $vendor ), array_keys( $confirmed ), 'confirmed group = paid vendors only' );
$all = LNKup_Comms_Audiences::event_group_members( $event['event'], 'all_applicants' );
lnkup_t_assert( isset( $all[ $vendor ], $all[ $vendor2 ] ) && ! isset( $all[ $outsider ] ), 'all applicants never includes unrelated vendors' );
update_post_meta( $paid, '_lnkup_checked_in_at', current_time( 'mysql' ) );
lnkup_t_same( array( $vendor ), array_keys( LNKup_Comms_Audiences::event_group_members( $event['event'], 'checked_in' ) ), 'checked-in group from real check-in data' );
lnkup_t_same( array(), LNKup_Comms_Audiences::event_group_members( $event['event'], 'not_checked_in' ), 'not-checked-in excludes checked-in vendors' );

echo "Permissions\n";
$req = new WP_REST_Request( 'GET', '/lnkup/v1/comms/events/' . $event['event'] . '/audiences' );
$req->set_url_params( array( 'id' => $event['event'] ) );
wp_set_current_user( $other_org );
lnkup_t_assert( true !== LNKup_Comms_Organizer_REST::can_message( $req ), 'another organizer cannot message this event' );
wp_set_current_user( $vendor );
lnkup_t_assert( true !== LNKup_Comms_Organizer_REST::can_message( $req ), 'a vendor cannot message event vendors' );
wp_set_current_user( $organizer );
lnkup_t_assert( true === LNKup_Comms_Organizer_REST::can_message( $req ), 'the owning organizer can' );
lnkup_ct_settings( array( 'organizer_broadcasts' => false ) );
lnkup_t_assert( is_wp_error( LNKup_Comms_Organizer_REST::can_message( $req ) ), 'feature flag off blocks the route' );
lnkup_ct_settings( array( 'organizer_broadcasts' => true ) );
wp_set_current_user( 0 );

echo "Send, restrictions, rate limits\n";
$bad = LNKup_Comms_Campaigns::create_event( $event['event'], $organizer, array( 'group' => 'confirmed', 'template' => 'payment_reminder', 'title' => 'Pay', 'body' => 'Pay now' ) );
lnkup_t_assert( is_wp_error( $bad ), 'payment reminders only go to approved-but-unpaid vendors' );
$ok = LNKup_Comms_Campaigns::create_event( $event['event'], $organizer, array( 'group' => 'confirmed', 'template' => 'schedule_change', 'title' => 'Gates open at 8', 'body' => 'Load-in moved to 8am.', 'channels' => array( 'inbox', 'push' ) ) );
lnkup_t_assert( is_array( $ok ) && 1 === $ok['recipients'], 'announcement queued for the confirmed vendor' );
LNKup_Comms_Campaigns::process_batch( $ok['id'] );
$msgs = lnkup_ct_messages_for( $vendor, 'v_announcement' );
lnkup_t_same( 1, count( $msgs ), 'vendor received one announcement' );
lnkup_t_same( 'high', $msgs[0]['priority'] ?? '', 'schedule changes are high priority' );
lnkup_t_same( 0, count( lnkup_ct_messages_for( $vendor2, 'v_announcement' ) ), 'pending vendor not included in the confirmed group' );
LNKup_Comms_Engine::deliver( (int) $msgs[0]['id'] );
lnkup_t_assert( false !== strpos( (string) lnkup_ct_message( (int) $msgs[0]['id'] )['title'], 'Gates open at 8' ), 'organizer title used in the message' );
$again = LNKup_Comms_Campaigns::create_event( $event['event'], $organizer, array( 'group' => 'confirmed', 'template' => 'announcement', 'title' => 'Again', 'body' => 'Again' ) );
lnkup_t_assert( is_wp_error( $again ) && 'lnkup_comms_rate_limited' === $again->get_error_code(), 'minimum interval between organizer messages enforced' );

echo "Countdown windows\n";
$start = new DateTimeImmutable( '@' . ( time() + 7 * DAY_IN_SECONDS - HOUR_IN_SECONDS ) );
$start = $start->setTimezone( wp_timezone() );
$win   = LNKup_Comms_Auto_Countdown::window_for( $start, array( 'd14' => 14 * DAY_IN_SECONDS, 'd7' => 7 * DAY_IN_SECONDS, 'h72' => 3 * DAY_IN_SECONDS, 'h24' => DAY_IN_SECONDS ) );
lnkup_t_same( 'd7', $win['key'] ?? '', '7-day window detected' );
$mid   = ( new DateTimeImmutable( '@' . ( time() + 10 * DAY_IN_SECONDS ) ) )->setTimezone( wp_timezone() );
lnkup_t_same( null, LNKup_Comms_Auto_Countdown::window_for( $mid, array( 'd14' => 14 * DAY_IN_SECONDS, 'd7' => 7 * DAY_IN_SECONDS ) ), 'no window between milestones' );

echo "Recurring cadence detection\n";
$monthly = array( time(), time() - 30 * DAY_IN_SECONDS, time() - 61 * DAY_IN_SECONDS );
lnkup_t_same( 'monthly', LNKup_Comms_Auto_Retention::cadence( $monthly ), 'three monthly events → monthly' );
lnkup_t_same( '', LNKup_Comms_Auto_Retention::cadence( array( time(), time() - 30 * DAY_IN_SECONDS ) ), 'two events are not enough to assume a series' );
lnkup_t_same( '', LNKup_Comms_Auto_Retention::cadence( array( time(), time() - 9 * DAY_IN_SECONDS, time() - 50 * DAY_IN_SECONDS ) ), 'irregular history is not a series' );

echo "Next steps (live state)\n";
$steps = LNKup_Comms_Next_Steps::organizer( $organizer );
$keys  = wp_list_pluck( $steps['steps'], 'key' );
lnkup_t_assert( in_array( 'pending_' . $event['event'], $keys, true ), 'organizer sees pending applications needing review' );
update_post_meta( $pending, '_lnkup_application_status', 'rejected' );
$steps = LNKup_Comms_Next_Steps::organizer( $organizer );
lnkup_t_assert( ! in_array( 'pending_' . $event['event'], wp_list_pluck( $steps['steps'], 'key' ), true ), 'cleared queue disappears immediately' );
$vsteps = LNKup_Comms_Next_Steps::vendor( $vendor );
lnkup_t_same( $paid, (int) ( $vsteps['upcoming'][0]['applicationId'] ?? 0 ), 'vendor upcoming bookings come from paid applications' );

echo "Organizer summary line uses real counts\n";
lnkup_t_assert( 0 === strpos( LNKup_Comms_Context::event_summary_line( $event['event'] ), '1 confirmed vendor' ), 'summary line counts confirmed vendors' );

echo "Admin campaign: draft → preview → test → confirm\n";
$admin = lnkup_ct_user( 'administrator' );
lnkup_ct_settings( array( 'campaign_confirm_threshold' => 1 ) );
$cid = LNKup_Comms_Campaigns::create_admin( array( 'name' => 'Test', 'filters' => array( 'role' => 'vendor', 'registered_days' => 1 ), 'content' => array( 'subject' => 'Hello', 'body' => 'Body' ), 'channels' => array( 'inbox' ) ), $admin );
lnkup_t_assert( is_int( $cid ) && $cid > 0, 'draft created' );
$preview = LNKup_Comms_Campaigns::preview( $cid );
lnkup_t_assert( $preview['recipients'] >= 3, 'preview counts real vendor accounts' );
$queued = LNKup_Comms_Campaigns::test_send( $cid, $admin );
lnkup_t_same( 1, $queued, 'test send goes only to the admin / test accounts' );
$start = LNKup_Comms_Campaigns::start( $cid, 0 );
lnkup_t_assert( is_wp_error( $start ) && 'lnkup_comms_confirm' === $start->get_error_code(), 'large campaign requires confirming the exact count' );
LNKup_Comms_Campaigns::cancel( $cid );
$wpdb->delete( LNKup_Comms_Schema::table( 'campaigns' ), array( 'id' => $cid ) ); // phpcs:ignore WordPress.DB
$wpdb->delete( LNKup_Comms_Schema::table( 'campaigns' ), array( 'id' => $ok['id'] ) ); // phpcs:ignore WordPress.DB

lnkup_ct_finish();
