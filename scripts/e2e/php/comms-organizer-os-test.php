<?php
/**
 * Organizer OS messages go through Communications once: a waitlist offer reaches the vendor as
 * one message (not "approved" plus "a spot opened"), with or without Communications flows on;
 * reviewer and task assignments are claimed for organizer accounts and fall back to email for
 * team members Communications cannot deliver to.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/comms-organizer-os-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_comms_helpers.php';

global $wpdb;
echo "Organizer OS messages\n";

$saved_core = get_option( 'lnkup_settings', array() );
$core       = (array) $saved_core;
foreach ( array( 'application_review_v2_enabled', 'waitlist_offers_enabled', 'organizer_ops_v2_enabled', 'trust_org_teams' ) as $flag ) {
	$core[ $flag ] = true;
}
$core['organizer_os_pilot_user_ids'] = '';
update_option( 'lnkup_settings', $core, false );

$GLOBALS['lnkup_oo_mail'] = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$GLOBALS['lnkup_oo_mail'][] = array( 'to' => (array) $atts['to'], 'subject' => (string) $atts['subject'] );
		return true;
	},
	10,
	2
);

/**
 * Core emails sent to an address.
 */
function lnkup_oo_mail_to( string $email, string $needle = '' ): int {
	$n = 0;
	foreach ( $GLOBALS['lnkup_oo_mail'] as $mail ) {
		if ( in_array( $email, $mail['to'], true ) && ( '' === $needle || false !== stripos( $mail['subject'], $needle ) ) ) {
			++$n;
		}
	}
	return $n;
}

/**
 * A waitlisted application on a one-spot booth.
 *
 * @return int[] [application, vendor]
 */
function lnkup_oo_waitlisted( int $organizer ): array {
	$vendor = lnkup_ct_user( 'lnkup_vendor' );
	lnkup_ct_profile( $vendor );
	$event = lnkup_ct_event( $organizer );
	update_post_meta( $event['opp'], '_lnkup_spots_total', 1 );
	$app = lnkup_ct_application( $vendor, $organizer, $event, 'waitlisted' );
	return array( $app, $vendor, $event );
}

$organizer = lnkup_ct_user( 'lnkup_organizer' );

echo "Waitlist offer with Communications flows on\n";
lnkup_ct_all_flows();
list( $app, $vendor ) = lnkup_oo_waitlisted( $organizer );
$email                = get_userdata( $vendor )->user_email;
$offer                = LNKup_Waitlist_Offers::make_offer( $app, $organizer, 24 );
lnkup_t_assert( ! is_wp_error( $offer ), 'organizer offers the spot' );
lnkup_t_same( 'payment_required', (string) get_post_meta( $app, '_lnkup_application_status', true ), 'the vendor holds the spot until paying' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $vendor, 'v_waitlist_offer' ) ), 'the vendor gets one waitlist offer message' );
lnkup_t_same( 0, count( lnkup_ct_messages_for( $vendor, 'v_application_approved' ) ), 'no separate "approved" message' );
lnkup_t_same( 0, lnkup_oo_mail_to( $email ), 'no plain email alongside the Communications message' );

echo "Waitlist offer with Communications flows off\n";
lnkup_ct_settings( array_fill_keys( array_keys( LNKup_Comms_Settings::flags() ), false ) );
list( $app2, $vendor2 ) = lnkup_oo_waitlisted( $organizer );
$email2                 = get_userdata( $vendor2 )->user_email;
$GLOBALS['lnkup_oo_mail'] = array();
LNKup_Waitlist_Offers::make_offer( $app2, $organizer, 24 );
lnkup_t_same( 1, lnkup_oo_mail_to( $email2, 'spot opened' ), 'the vendor gets the waitlist offer with the deadline' );
lnkup_t_same( 0, lnkup_oo_mail_to( $email2, 'payment due' ), 'and no second "payment due" email asking the same thing' );
lnkup_t_same( 0, lnkup_oo_mail_to( $email2, 'approved' ), 'and no "approved" email' );
lnkup_t_same( 2, lnkup_oo_mail_to( $email2 ), 'the only other email is the invoice document' );
lnkup_t_same( 0, count( lnkup_ct_messages_for( $vendor2 ) ), 'Communications sends nothing while its flows are off' );

echo "Reviewer and task assignments\n";
lnkup_ct_all_flows();
list( $app3, , $event3 ) = lnkup_oo_waitlisted( $organizer );
update_post_meta( $app3, '_lnkup_application_status', 'submitted' );
$team_only = lnkup_ct_user( 'subscriber' );
$manager   = lnkup_ct_user( 'lnkup_organizer' );
foreach ( array( array( $team_only, 'reviewer' ), array( $manager, 'event_manager' ) ) as $member ) {
	$wpdb->insert( LNKup_Trust_Tables::org_members_table(), array( 'org_owner_id' => $organizer, 'user_id' => $member[0], 'role' => $member[1], 'scope_type' => 'all', 'scope_id' => 0, 'status' => 'active', 'invited_by' => $organizer, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	$GLOBALS['lnkup_oo_members'][] = (int) $wpdb->insert_id;
}
$GLOBALS['lnkup_oo_mail'] = array();
wp_set_current_user( $organizer );
$assigned = LNKup_Application_Review::assign_reviewer( $app3, $team_only, $organizer );
lnkup_t_assert( ! is_wp_error( $assigned ), 'a team-only reviewer is assigned' );
lnkup_t_same( 1, lnkup_oo_mail_to( get_userdata( $team_only )->user_email ), 'a member Communications cannot reach still gets the email' );
lnkup_t_same( 0, count( lnkup_ct_messages_for( $team_only ) ), 'and no undeliverable Communications message is queued' );
LNKup_Application_Review::assign_reviewer( $app3, $manager, $organizer );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $manager, 'o_review_assigned' ) ), 'an organizer-account member gets the review message through Communications' );
lnkup_t_same( 0, lnkup_oo_mail_to( get_userdata( $manager )->user_email ), 'without a duplicate plain email' );

$task = LNKup_Event_Tasks::create( (int) $event3['event'], array( 'title' => 'Mark booth lanes', 'assignee_user_id' => $manager ), $organizer );
lnkup_t_assert( ! is_wp_error( $task ), 'owner assigns an event task' );
$task_messages = lnkup_ct_messages_for( $manager, 'o_task_assigned' );
lnkup_t_same( 1, count( $task_messages ), 'the assignee gets one task message through Communications' );

foreach ( (array) ( $GLOBALS['lnkup_oo_members'] ?? array() ) as $row_id ) {
	$wpdb->delete( LNKup_Trust_Tables::org_members_table(), array( 'id' => $row_id ), array( '%d' ) );
}
update_option( 'lnkup_settings', $saved_core, false );
wp_set_current_user( 0 );
lnkup_ct_finish();
