<?php
/**
 * Engagement journeys: invitation responses, new vendors near an event (public cards only),
 * deadline open-spots, events without slots, expiring invites, unfinished applications,
 * inactivity segments, first-event thanks, invitation inbox tab, and stale suppression.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/comms-engagement-test.php
 *
 * @package LNKup_Communications
 */

require __DIR__ . '/_comms_helpers.php';

global $wpdb;
$GLOBALS['lnkup_ce2_settings'] = get_option( 'lnkup_settings', array() );
update_option(
	'lnkup_settings',
	array_merge(
		is_array( $GLOBALS['lnkup_ce2_settings'] ) ? $GLOBALS['lnkup_ce2_settings'] : array(),
		array( 'matching_enabled' => true, 'matching_vendor_discovery_enabled' => true, 'matching_organizer_discovery_enabled' => true, 'platform_prelaunch_mode' => false )
	),
	false
);
lnkup_ct_all_flows();
LNKup_Match_Categories::seed_groups();
$food            = term_exists( 'Food Vendor', 'lnkup_vendor_category' );
$GLOBALS['food'] = (int) ( is_array( $food ) ? $food['term_id'] : $food );

/**
 * Live Charlotte event with one open food slot, indexed.
 *
 * @return array{event:int,opp:int}
 */
function lnkup_ce2_event( int $organizer, int $days_out = 40, int $deadline_days = 30 ): array {
	$event = lnkup_ct_post( 'lnkup_event', 'Engagement Fest ' . wp_generate_password( 4, false ), array( 'organizer_user_id' => $organizer, 'city' => 'Charlotte', 'state' => 'NC', 'lat' => '35.23', 'lng' => '-80.84', 'event_date' => wp_date( 'Y-m-d', time() + $days_out * DAY_IN_SECONDS ), 'event_status' => 'live' ) );
	$opp   = lnkup_ct_post( 'lnkup_opportunity', 'Food booth', array( 'event_id' => $event, 'organizer_user_id' => $organizer, 'opportunity_status' => 'open', 'spots_total' => 6, 'spots_remaining' => 6, 'vendor_fee' => 80, 'required_documents' => 'none', 'application_deadline' => wp_date( 'Y-m-d', time() + $deadline_days * DAY_IN_SECONDS ) ) );
	wp_set_object_terms( $opp, array( $GLOBALS['food'] ), 'lnkup_vendor_category' );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	return array( 'event' => $event, 'opp' => $opp );
}

/**
 * Discoverable Charlotte food vendor.
 *
 * @return array{0:int,1:int}
 */
function lnkup_ce2_vendor( string $name ): array {
	$user    = lnkup_ct_user( 'lnkup_vendor' );
	$profile = lnkup_ct_profile( $user, 'Charlotte', 'NC' );
	update_post_meta( $profile, '_lnkup_business_name', $name );
	update_post_meta( $profile, '_lnkup_contact_email', 'private' . $user . '@example.test' );
	update_post_meta( $profile, '_lnkup_geo_lat', '35.23' );
	update_post_meta( $profile, '_lnkup_geo_lng', '-80.84' );
	update_post_meta( $profile, LNKup_Match_Vendor_Index::GEO_KEY_META, 'charlotte|nc|' );
	wp_set_object_terms( $profile, array( $GLOBALS['food'] ), 'lnkup_vendor_category' );
	LNKup_Match_REST::apply_preferences( $profile, array( 'primary_category' => $GLOBALS['food'], 'cities_served' => array( 'Charlotte' ) ) );
	return array( $user, $profile );
}

/**
 * Messages for one trigger and object.
 */
function lnkup_ce2_msgs( int $user, string $trigger, string $column = '', int $value = 0 ): array {
	return array_values(
		array_filter(
			lnkup_ct_messages_for( $user, $trigger ),
			static function ( $row ) use ( $column, $value ) {
				return '' === $column || (int) $row[ $column ] === $value;
			}
		)
	);
}

$org = lnkup_ct_user( 'lnkup_organizer' );

echo "Invitation responses\n";
list( $v_inv, $p_inv ) = lnkup_ce2_vendor( 'Invite Response Tacos' );
$ev     = lnkup_ce2_event( $org );
$invite = LNKup_Vendor_Invites::create( $org, $v_inv, array( 'event_id' => $ev['event'], 'opportunity_id' => $ev['opp'], 'silent' => true ) );
$inv_id = is_array( $invite ) ? (int) $invite['invite_id'] : 0;
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $inv_id );
LNKup_Vendor_Invites::respond_by_id( $inv_id, true, $v_inv );
$resp = lnkup_ce2_msgs( $org, 'o_invite_response' );
lnkup_t_same( 1, count( $resp ), 'one organizer message even though the status hook fires twice' );
lnkup_t_same( 'o_invite_response:accepted', $resp[0]['template_key'] ?? '', 'accepted variant' );
LNKup_Comms_Engine::deliver( (int) ( $resp[0]['id'] ?? 0 ) );
lnkup_t_same( 'sent', lnkup_ct_message( (int) $resp[0]['id'] )['status'], 'response delivered' );
lnkup_t_same( 'invitations', LNKup_Comms_Registry::tab_for_type( 'organizer_invite_response', 'organizer' ), 'response lands in the Invitations tab' );
lnkup_t_same( 'invitations', LNKup_Comms_Registry::tab_for_type( 'discovery_invite', 'vendor' ), 'vendor invites land in the Invitations tab' );
lnkup_t_assert( in_array( 'invitations', LNKup_Comms_Registry::tabs( 'vendor' ), true ) && in_array( 'account', LNKup_Comms_Registry::tabs( 'organizer' ), true ), 'role tabs include Invitations and Account' );

echo "Expiring invitations\n";
$ev2     = lnkup_ce2_event( $org );
$invite2 = LNKup_Vendor_Invites::create( $org, $v_inv, array( 'event_id' => $ev2['event'], 'opportunity_id' => $ev2['opp'], 'silent' => true ) );
$inv2    = is_array( $invite2 ) ? (int) $invite2['invite_id'] : 0;
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $inv2 );
update_post_meta( $inv2, '_lnkup_expires_at', gmdate( 'Y-m-d H:i:s', time() + (int) ( 2.5 * DAY_IN_SECONDS ) ) );
LNKup_Comms_Auto_Engagement::expiring_invites();
LNKup_Comms_Auto_Engagement::expiring_invites();
$exp = lnkup_ce2_msgs( $v_inv, 'v_invite_expiring' );
lnkup_t_same( 1, count( $exp ), 'one expiring reminder, not repeated' );
LNKup_Vendor_Invites::respond_by_id( $inv2, false, $v_inv );
LNKup_Comms_Engine::deliver( (int) ( $exp[0]['id'] ?? 0 ) );
$row = lnkup_ct_message( (int) ( $exp[0]['id'] ?? 0 ) );
lnkup_t_assert( in_array( $row['status'], array( 'suppressed', 'cancelled' ), true ), 'reminder dropped once the vendor answered' );
lnkup_t_same( '/(vendor)/invitations', LNKup_Comms_Context::deep_link( 'vendor_invites', LNKup_Comms_Context::build( array() ), 'vendor' ), 'deep link opens the invitations screen' );

echo "New vendors near your event\n";
$near = lnkup_ce2_event( $org );
LNKup_Comms_Auto_Engagement::nearby_for_event( $near['event'] );
lnkup_t_assert( (int) get_post_meta( $near['event'], LNKup_Comms_Auto_Engagement::NEARBY_SINCE, true ) > 0, 'first run only records a baseline' );
lnkup_t_same( array(), lnkup_ce2_msgs( $org, 'o_new_vendors_nearby', 'event_id', $near['event'] ), 'no alert on the baseline run' );
update_post_meta( $near['event'], LNKup_Comms_Auto_Engagement::NEARBY_SINCE, time() - HOUR_IN_SECONDS );
$new_vendors = array();
foreach ( array( 'Nearby Bakery', 'Nearby Grill', 'Nearby Juice' ) as $name ) {
	$new_vendors[] = lnkup_ce2_vendor( $name );
}
LNKup_Match_Service::compute_for_opportunity( $near['opp'] );
LNKup_Match_Service::flush_related( $org );
$mid  = LNKup_Comms_Auto_Engagement::nearby_for_event( $near['event'] );
lnkup_t_assert( $mid > 0, 'three new matching vendors trigger an alert' );
$msg  = lnkup_ct_message( $mid );
$ctx  = json_decode( (string) $msg['context'], true );
$json = (string) wp_json_encode( $ctx['_items'] ?? array() );
lnkup_t_assert( count( (array) ( $ctx['_items'] ?? array() ) ) >= 1, 'email carries vendor cards' );
lnkup_t_assert( false !== strpos( $json, 'Nearby' ), 'cards show business names' );
lnkup_t_assert( false === strpos( $json, '@example.test' ) && false === strpos( $json, 'private' ), 'cards never include private contact details' );
$GLOBALS['lnkup_ct_mail'] = array();
LNKup_Comms_Engine::deliver( $mid );
lnkup_t_assert( false !== strpos( (string) ( $GLOBALS['lnkup_ct_mail'][0]['message'] ?? '' ), 'Invite to event' ), 'email renders an invite action per vendor' );
lnkup_t_same( 0, LNKup_Comms_Auto_Engagement::nearby_for_event( $near['event'] ), 'cooldown blocks a second alert for 72 hours' );

echo "Deadline open spots\n";
$dl = lnkup_ce2_event( $org, 20, 7 );
LNKup_Comms_Auto_Engagement::deadline_spots();
$dlm = lnkup_ce2_msgs( $org, 'o_open_spots', 'event_id', $dl['event'] );
lnkup_t_same( 'o_open_spots:deadline', $dlm[0]['template_key'] ?? '', 'deadline variant sent 7 days before applications close' );
lnkup_t_assert( false !== strpos( (string) ( $dlm[0]['context'] ?? '' ), 'category_summary' ), 'category breakdown included' );

echo "Published event without slots\n";
$bare = lnkup_ct_post( 'lnkup_event', 'Bare Event', array( 'organizer_user_id' => $org, 'event_date' => wp_date( 'Y-m-d', time() + 20 * DAY_IN_SECONDS ), 'event_status' => 'draft' ) );
$wpdb->update( $wpdb->posts, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'ID' => $bare ) );
clean_post_cache( $bare );
LNKup_Comms_Auto_Engagement::events_without_slots();
$ns = lnkup_ce2_msgs( $org, 'o_event_no_slots', 'event_id', $bare );
lnkup_t_same( 1, count( $ns ), 'organizer is asked to add vendor opportunities' );
lnkup_ct_post( 'lnkup_opportunity', 'Now with a slot', array( 'event_id' => $bare, 'organizer_user_id' => $org, 'opportunity_status' => 'open' ) );
LNKup_Comms_Engine::deliver( (int) ( $ns[0]['id'] ?? 0 ) );
lnkup_t_same( 'suppressed', lnkup_ct_message( (int) ( $ns[0]['id'] ?? 0 ) )['status'], 'queued reminder suppressed once a slot exists' );
lnkup_t_same( array(), lnkup_ce2_msgs( $org, 'o_event_no_slots', 'event_id', $ev['event'] ), 'events with slots are never nagged' );

echo "Unfinished applications\n";
list( $v_draft ) = lnkup_ce2_vendor( 'Draft Donuts' );
$dev   = lnkup_ce2_event( $org );
$draft = lnkup_ct_post( 'lnkup_application', 'Draft', array( 'draft_vendor_user_id' => $v_draft, 'draft_opportunity_id' => $dev['opp'], 'draft_event_id' => $dev['event'], 'draft_state' => 'open', 'draft_revision' => 1 ), 'lnkup_app_draft' );
$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * HOUR_IN_SECONDS ), 'post_modified' => gmdate( 'Y-m-d H:i:s', time() - 30 * HOUR_IN_SECONDS ) ), array( 'ID' => $draft ) );
clean_post_cache( $draft );
LNKup_Comms_Auto_Engagement::drafts();
LNKup_Comms_Auto_Engagement::drafts();
$dm = lnkup_ce2_msgs( $v_draft, 'v_application_draft' );
lnkup_t_same( 1, count( $dm ), 'one 24-hour reminder for the saved application' );
lnkup_t_same( 'scheduled', $dm[0]['status'] ?? '', 'reminder queued' );
$submitted = lnkup_ct_application( $v_draft, $org, $dev, 'submitted' );
do_action( 'lnkup_application_submitted', $submitted );
lnkup_t_same( 'cancelled', lnkup_ct_message( (int) $dm[0]['id'] )['status'], 'submitting the application cancels the reminder' );
list( $v_draft2 ) = lnkup_ce2_vendor( 'Draft Dumplings' );
$draft2 = lnkup_ct_post( 'lnkup_application', 'Draft 2', array( 'draft_vendor_user_id' => $v_draft2, 'draft_opportunity_id' => $dev['opp'], 'draft_event_id' => $dev['event'], 'draft_state' => 'open', 'draft_revision' => 1 ), 'lnkup_app_draft' );
$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * HOUR_IN_SECONDS ) ), array( 'ID' => $draft2 ) );
clean_post_cache( $draft2 );
LNKup_Comms_Auto_Engagement::drafts();
$dm2 = lnkup_ce2_msgs( $v_draft2, 'v_application_draft' );
update_post_meta( $dev['opp'], '_lnkup_opportunity_status', 'closed' );
LNKup_Comms_Engine::deliver( (int) ( $dm2[0]['id'] ?? 0 ) );
lnkup_t_same( 'suppressed', lnkup_ct_message( (int) ( $dm2[0]['id'] ?? 0 ) )['status'], 'reminder suppressed when the opportunity closed' );

echo "Inactive vendors\n";
lnkup_t_same( 0, LNKup_Comms_Auto_Engagement::inactive_segment( 10 ), 'no segment before 14 days' );
lnkup_t_same( 14, LNKup_Comms_Auto_Engagement::inactive_segment( 20 ), '14-day segment' );
lnkup_t_same( 60, LNKup_Comms_Auto_Engagement::inactive_segment( 95 ), '60-day segment' );
list( $v_idle, $p_idle ) = lnkup_ce2_vendor( 'Idle Ice Cream' );
$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'ID' => $v_idle ) );
clean_user_cache( $v_idle );
$idle_event = lnkup_ce2_event( $org );
LNKup_Match_Service::compute_for_vendor( $p_idle );
$idle_mid = LNKup_Comms_Auto_Engagement::inactive_for_vendor( $v_idle );
lnkup_t_assert( $idle_mid > 0, 'vendor idle 40 days with live matches gets one message' );
lnkup_t_same( 0, LNKup_Comms_Auto_Engagement::inactive_for_vendor( $v_idle ), 'not repeated within the same segment' );
lnkup_t_assert( false !== strpos( (string) lnkup_ct_message( $idle_mid )['template_key'], 'v_inactive' ), 'reactivation message recorded' );
LNKup_Marketplace_Analytics::track( 'search', array(), $v_idle );
LNKup_Comms_Engine::deliver( $idle_mid );
lnkup_t_same( 'suppressed', lnkup_ct_message( $idle_mid )['status'], 'new activity suppresses the queued reactivation' );
list( $v_none ) = lnkup_ce2_vendor( 'No Match Candles' );
wp_set_object_terms( (int) lnkup_get_vendor_profile_id( $v_none ), array(), 'lnkup_vendor_category' );
update_post_meta( (int) lnkup_get_vendor_profile_id( $v_none ), '_lnkup_cities_served', wp_json_encode( array( 'Anchorage' ) ) );
update_post_meta( (int) lnkup_get_vendor_profile_id( $v_none ), '_lnkup_city', 'Anchorage' );
$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'ID' => $v_none ) );
clean_user_cache( $v_none );
LNKup_Match_Vendor_Index::sync( (int) lnkup_get_vendor_profile_id( $v_none ) );
LNKup_Match_Service::compute_for_vendor( (int) lnkup_get_vendor_profile_id( $v_none ) );
lnkup_t_same( 0, LNKup_Comms_Auto_Engagement::inactive_for_vendor( $v_none ), 'no "we miss you" message without a real opportunity' );

echo "First completed event\n";
list( $v_first, $p_first ) = lnkup_ce2_vendor( 'First Event Fries' );
$fe     = lnkup_ce2_event( $org );
$fe_app = lnkup_ct_application( $v_first, $org, $fe, 'completed', $p_first );
LNKup_Comms_Listen_Operations::on_event_completed( $fe_app, $fe['event'] );
$thanks = lnkup_ce2_msgs( $v_first, 'v_event_thanks' );
lnkup_t_same( 'v_event_thanks:first', $thanks[0]['template_key'] ?? '', 'first completed event gets the congratulations variant' );
$fe2     = lnkup_ce2_event( $org );
$fe_app2 = lnkup_ct_application( $v_first, $org, $fe2, 'completed', $p_first );
LNKup_Comms_Listen_Operations::on_event_completed( $fe_app2, $fe2['event'] );
$thanks = lnkup_ce2_msgs( $v_first, 'v_event_thanks', 'application_id', $fe_app2 );
lnkup_t_same( 'v_event_thanks', $thanks[0]['template_key'] ?? '', 'later events get the regular thanks' );

echo "Next steps follow completed actions\n";
list( $v_ns ) = lnkup_ce2_vendor( 'Next Step Noodles' );
$ns_ev        = lnkup_ce2_event( $org );
$ns_inv       = LNKup_Vendor_Invites::create( $org, $v_ns, array( 'event_id' => $ns_ev['event'], 'opportunity_id' => $ns_ev['opp'], 'silent' => true ) );
$ns_inv_id    = is_array( $ns_inv ) ? (int) $ns_inv['invite_id'] : 0;
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $ns_inv_id );
$ns_draft = lnkup_ct_post( 'lnkup_application', 'NS draft', array( 'draft_vendor_user_id' => $v_ns, 'draft_opportunity_id' => $ns_ev['opp'], 'draft_state' => 'open' ), 'lnkup_app_draft' );
$keys     = wp_list_pluck( LNKup_Comms_Engagement::next_steps( $v_ns, 'vendor' )['steps'], 'key' );
lnkup_t_same( 'invitations', $keys[0] ?? '', 'open invitation is the top vendor step' );
lnkup_t_assert( in_array( 'draft_' . $ns_draft, $keys, true ), 'saved application appears as a step' );
LNKup_Vendor_Invites::respond_by_id( $ns_inv_id, false, $v_ns );
$keys = wp_list_pluck( LNKup_Comms_Engagement::next_steps( $v_ns, 'vendor' )['steps'], 'key' );
lnkup_t_assert( ! in_array( 'invitations', $keys, true ), 'answered invitation leaves next steps (cache flushed)' );
$ns_app = lnkup_ct_application( $v_ns, $org, $ns_ev, 'submitted' );
do_action( 'lnkup_application_submitted', $ns_app );
$keys = wp_list_pluck( LNKup_Comms_Engagement::next_steps( $v_ns, 'vendor' )['steps'], 'key' );
lnkup_t_assert( ! in_array( 'draft_' . $ns_draft, $keys, true ), 'submitted application leaves next steps' );
$bare_org  = lnkup_ct_user( 'lnkup_organizer' );
$bare_ev   = lnkup_ct_post( 'lnkup_event', 'Slotless', array( 'organizer_user_id' => $bare_org, 'event_date' => wp_date( 'Y-m-d', time() + 9 * DAY_IN_SECONDS ), 'event_status' => 'draft' ) );
$org_steps = wp_list_pluck( LNKup_Comms_Engagement::next_steps( $bare_org, 'organizer' )['steps'], 'key' );
lnkup_t_assert( in_array( 'slots_' . $bare_ev, $org_steps, true ), 'organizer sees "add vendor opportunities" for a slot-less event' );
lnkup_ct_post( 'lnkup_opportunity', 'Slot', array( 'event_id' => $bare_ev, 'organizer_user_id' => $bare_org, 'opportunity_status' => 'open' ) );
$org_steps = wp_list_pluck( LNKup_Comms_Engagement::next_steps( $bare_org, 'organizer' )['steps'], 'key' );
lnkup_t_assert( ! in_array( 'slots_' . $bare_ev, $org_steps, true ), 'step disappears once a slot exists' );

foreach ( array( $org ) as $o ) {
	$wpdb->delete( LNKup_Match_Tables::pipeline_table(), array( 'organizer_user_id' => $o ) );
}
update_option( 'lnkup_settings', $GLOBALS['lnkup_ce2_settings'], false );
lnkup_ct_finish();
