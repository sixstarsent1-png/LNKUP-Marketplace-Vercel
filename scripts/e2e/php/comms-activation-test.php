<?php
/**
 * Organizer activation follow-ups (LNKup_Comms_Auto_Activation): flag, windows, shared
 * cooldown, send-time cancellation, payouts gating, drafts, first booking, attribution.
 *
 * @package LNKup_Communications
 */

require_once __DIR__ . '/_comms_helpers.php';

global $wpdb;
$messages_table = LNKup_Comms_Schema::table( 'messages' );
$max_before     = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$messages_table}" ); // phpcs:ignore WordPress.DB

$GLOBALS['lnkup_ct_connect'] = false;
$connect_filter              = static function ( $value ) {
	$value = is_array( $value ) ? $value : array();
	if ( $GLOBALS['lnkup_ct_connect'] ) {
		$value['stripe_connect_enabled'] = 1;
	}
	return $value;
};
add_filter( 'option_lnkup_settings', $connect_filter );
add_filter( 'default_option_lnkup_settings', $connect_filter );

/**
 * Organizer fixture without the welcome / onboarding messages its registration queues.
 */
function lnkup_cat_org(): int {
	$id = lnkup_ct_user( 'lnkup_organizer' );
	LNKup_Comms_Engine::purge_user( $id );
	return $id;
}

/**
 * Backdate a user's registration.
 */
function lnkup_cat_registered( int $user_id, int $days ): void {
	global $wpdb;
	$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), array( 'ID' => $user_id ) ); // phpcs:ignore WordPress.DB
	clean_user_cache( $user_id );
	LNKup_Organizer_Activation::flush( $user_id );
}

/**
 * Backdate every message of a user by N days.
 */
function lnkup_cat_age_messages( int $user_id, int $days ): void {
	global $wpdb;
	$table = LNKup_Comms_Schema::table( 'messages' );
	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at = DATE_SUB(created_at, INTERVAL %d DAY) WHERE recipient_user_id = %d", $days, $user_id ) ); // phpcs:ignore WordPress.DB
}

/**
 * Complete an organizer's organization stage.
 */
function lnkup_cat_profile( int $user_id ): void {
	update_user_meta( $user_id, '_lnkup_organizer_public_name', 'Activation Test Org ' . $user_id );
	update_user_meta( $user_id, '_lnkup_organizer_city', 'Atlanta' );
	update_user_meta( $user_id, '_lnkup_organizer_state', 'GA' );
	LNKup_Organizer_Activation::flush( $user_id );
}

lnkup_ct_all_flows();
lnkup_ct_settings( array( 'activation_cooldown_days' => 3, 'draft_unpublished_days' => 3 ) );

echo "Settings\n";
lnkup_t_assert( array_key_exists( 'flow_activation', LNKup_Comms_Settings::flags() ), 'flow_activation flag registered' );
lnkup_t_same( 5, LNKup_Comms_Settings::int( 'no_applications_days' ), 'no-applications delay configurable (default 5 days)' );
foreach ( array( 'o_setup_profile', 'o_setup_payouts', 'o_publish_draft', 'o_first_booking' ) as $trigger ) {
	lnkup_t_assert( null !== LNKup_Comms_Registry::get( $trigger ) && null !== LNKup_Comms_Templates::get( $trigger ), $trigger . ' has a trigger and a template' );
}

echo "Flag off sends nothing\n";
$org = lnkup_cat_org();
lnkup_cat_registered( $org, 1 );
lnkup_ct_settings( array( 'flow_activation' => false ) );
LNKup_Comms_Auto_Activation::organizer( $org );
lnkup_t_same( 0, count( lnkup_ct_messages_for( $org ) ), 'no activation nudge while the flag is off' );
lnkup_ct_settings( array( 'flow_activation' => true ) );

echo "Profile nudge: day 1, cooldown, day 7, then stop\n";
LNKup_Comms_Auto_Activation::organizer( $org );
$sent = lnkup_ct_messages_for( $org, 'o_setup_profile' );
lnkup_t_same( 1, count( $sent ), 'day-1 profile nudge queued' );
$ctx = json_decode( (string) ( $sent[0]['context'] ?? '' ), true );
lnkup_t_same( 'organization', $ctx['activation_stage'] ?? '', 'stage snapshot stored for attribution' );
lnkup_t_assert( false !== strpos( (string) ( $ctx['missing_items'] ?? '' ), 'City and state' ), 'missing items come from the activation model' );
LNKup_Comms_Auto_Activation::organizer( $org );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $org ) ), 'same-day rerun sends nothing (once only + cooldown)' );
lnkup_t_assert( LNKup_Comms_Auto_Activation::in_cooldown( $org ), 'organizer in the shared cooldown' );
lnkup_cat_age_messages( $org, 6 );
lnkup_cat_registered( $org, 7 );
LNKup_Comms_Auto_Activation::organizer( $org );
lnkup_t_same( 2, count( lnkup_ct_messages_for( $org, 'o_setup_profile' ) ), 'day-7 follow-up queued' );
lnkup_cat_age_messages( $org, 6 );
LNKup_Comms_Auto_Activation::organizer( $org );
lnkup_t_same( 2, count( lnkup_ct_messages_for( $org, 'o_setup_profile' ) ), 'no third profile nudge' );

echo "Cooldown is shared with o_onboarding\n";
$org_b = lnkup_cat_org();
lnkup_cat_registered( $org_b, 2 );
$wpdb->insert( $messages_table, array( 'trigger_key' => 'o_onboarding', 'recipient_user_id' => $org_b, 'recipient_role' => 'organizer', 'category' => 'onboarding', 'priority' => 'normal', 'template_key' => 'o_onboarding', 'context' => '{}', 'status' => 'sent', 'dedupe_key' => 'cat-onb-' . $org_b, 'cancel_key' => 'user:' . $org_b, 'scheduled_at' => LNKup_Comms_Schema::now(), 'created_at' => LNKup_Comms_Schema::now(), 'updated_at' => LNKup_Comms_Schema::now() ) ); // phpcs:ignore WordPress.DB
LNKup_Comms_Auto_Activation::organizer( $org_b );
lnkup_t_same( 0, count( lnkup_ct_messages_for( $org_b, 'o_setup_profile' ) ), 'recent onboarding email blocks the activation nudge' );

echo "Cancelled at send time when the stage is already complete\n";
$org_c = lnkup_cat_org();
lnkup_cat_registered( $org_c, 1 );
LNKup_Comms_Auto_Activation::organizer( $org_c );
$queued = lnkup_ct_messages_for( $org_c, 'o_setup_profile' );
lnkup_t_same( 1, count( $queued ), 'nudge queued' );
lnkup_cat_profile( $org_c );
LNKup_Comms_Engine::deliver( (int) $queued[0]['id'] );
lnkup_t_same( 'suppressed', lnkup_ct_message( (int) $queued[0]['id'] )['status'] ?? '', 'completed stage → message not sent' );
lnkup_t_same( 0, count( $GLOBALS['lnkup_ct_mail'] ), 'no email for a stale nudge' );

echo "Payout nudge\n";
$org_p = lnkup_cat_org();
lnkup_cat_profile( $org_p );
lnkup_cat_registered( $org_p, 2 );
lnkup_ct_event( $org_p );
LNKup_Organizer_Activation::flush( $org_p );
LNKup_Comms_Auto_Activation::organizer( $org_p );
lnkup_t_same( 0, count( lnkup_ct_messages_for( $org_p, 'o_setup_payouts' ) ), 'Connect off → LNKup pays out, no payout nudge' );
$GLOBALS['lnkup_ct_connect'] = true;
LNKup_Organizer_Activation::flush( $org_p );
lnkup_t_same( 'not_started', LNKup_Comms_Context::activation_stage_status( $org_p, 'payouts' ), 'Connect on, no account → payouts incomplete' );
LNKup_Comms_Auto_Activation::organizer( $org_p );
$payout_msgs = lnkup_ct_messages_for( $org_p, 'o_setup_payouts' );
lnkup_t_same( 1, count( $payout_msgs ), 'published event + no payouts → payout nudge' );

$org_q = lnkup_cat_org();
lnkup_cat_profile( $org_q );
lnkup_cat_registered( $org_q, 2 );
lnkup_ct_event( $org_q );
$wpdb->insert( $messages_table, array( 'trigger_key' => 'o_payout_setup', 'recipient_user_id' => $org_q, 'recipient_role' => 'organizer', 'category' => 'payments', 'priority' => 'normal', 'template_key' => 'o_payout_setup', 'context' => '{}', 'status' => 'sent', 'dedupe_key' => 'cat-pay-' . $org_q, 'cancel_key' => 'user:' . $org_q, 'scheduled_at' => LNKup_Comms_Schema::now(), 'created_at' => LNKup_Comms_Schema::now( -2 * HOUR_IN_SECONDS ), 'updated_at' => LNKup_Comms_Schema::now() ) ); // phpcs:ignore WordPress.DB
LNKup_Comms_Auto_Activation::organizer( $org_q );
lnkup_t_same( 0, count( lnkup_ct_messages_for( $org_q, 'o_setup_payouts' ) ), 'recent payout-setup email → no duplicate nudge' );

$org_r = lnkup_cat_org();
lnkup_cat_profile( $org_r );
lnkup_cat_registered( $org_r, 2 );
LNKup_Comms_Auto_Activation::organizer( $org_r );
lnkup_t_same( 0, count( lnkup_ct_messages_for( $org_r, 'o_setup_payouts' ) ), 'no event and no approved vendor → payouts not pushed yet' );
$GLOBALS['lnkup_ct_connect'] = false;

echo "Unpublished draft\n";
$org_d  = lnkup_cat_org();
$draft  = lnkup_ct_event( $org_d );
update_post_meta( $draft['event'], '_lnkup_event_status', 'draft' );
$old    = gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS );
$wpdb->update( $wpdb->posts, array( 'post_date' => get_date_from_gmt( $old ), 'post_date_gmt' => $old ), array( 'ID' => $draft['event'] ) ); // phpcs:ignore WordPress.DB
clean_post_cache( $draft['event'] );
$fresh  = lnkup_ct_event( $org_d );
update_post_meta( $fresh['event'], '_lnkup_event_status', 'draft' );
LNKup_Comms_Auto_Activation::drafts();
$draft_msgs = lnkup_ct_messages_for( $org_d, 'o_publish_draft' );
lnkup_t_same( 1, count( $draft_msgs ), 'only the draft older than draft_unpublished_days is nudged' );
lnkup_t_same( $draft['event'], (int) ( $draft_msgs[0]['event_id'] ?? 0 ), 'nudge points at the stale draft' );
lnkup_cat_age_messages( $org_d, 10 );
LNKup_Comms_Auto_Activation::drafts();
lnkup_t_same( 1, count( lnkup_ct_messages_for( $org_d, 'o_publish_draft' ) ), 'once per event' );
update_post_meta( $draft['event'], '_lnkup_event_status', 'live' );
LNKup_Comms_Engine::deliver( (int) $draft_msgs[0]['id'] );
lnkup_t_same( 'suppressed', lnkup_ct_message( (int) $draft_msgs[0]['id'] )['status'] ?? '', 'published before send → cancelled' );

echo "First paid booking (once)\n";
$org_f  = lnkup_cat_org();
$vendor = lnkup_ct_user( 'lnkup_vendor' );
$v2     = lnkup_ct_user( 'lnkup_vendor' );
$ev     = lnkup_ct_event( $org_f );
$app    = lnkup_ct_application( $vendor, $org_f, $ev, 'paid', lnkup_ct_profile( $vendor ) );
$pay    = lnkup_ct_payment( $app, $vendor, $org_f, 'paid' );
update_post_meta( $pay, '_lnkup_event_id', $ev['event'] );
LNKup_Organizer_Activation::flush( $org_f );
LNKup_Comms_Auto_Activation::on_payment_completed( $pay );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $org_f, 'o_first_booking' ) ), 'first paid booking celebrated' );
$app2 = lnkup_ct_application( $v2, $org_f, $ev, 'paid', lnkup_ct_profile( $v2 ) );
$pay2 = lnkup_ct_payment( $app2, $v2, $org_f, 'paid' );
LNKup_Organizer_Activation::flush( $org_f );
LNKup_Comms_Auto_Activation::on_payment_completed( $pay2 );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $org_f, 'o_first_booking' ) ), 'second booking does not repeat it' );
$pend = lnkup_ct_payment( $app2, $v2, lnkup_ct_user( 'lnkup_organizer' ), 'pending' );
LNKup_Comms_Auto_Activation::on_payment_completed( $pend );
lnkup_t_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages_table} WHERE trigger_key = 'o_first_booking' AND payment_id = %d", $pend ) ), 'unpaid payment never triggers it' ); // phpcs:ignore WordPress.DB

echo "Attribution\n";
$since    = LNKup_Comms_Schema::now( -DAY_IN_SECONDS );
$window   = 7 * DAY_IN_SECONDS;
$baseline = LNKup_Comms_Analytics::activation_attributed( 'o_setup_payouts', $since, $window );
$mid      = (int) $payout_msgs[0]['id'];
$wpdb->update( $messages_table, array( 'status' => 'sent', 'sent_at' => LNKup_Comms_Schema::now( -HOUR_IN_SECONDS ) ), array( 'id' => $mid ) ); // phpcs:ignore WordPress.DB
delete_user_meta( $org_p, '_lnkup_activation_done_payouts' );
lnkup_t_same( $baseline, LNKup_Comms_Analytics::activation_attributed( 'o_setup_payouts', $since, $window ), 'stage not completed → not attributed' );
update_user_meta( $org_p, '_lnkup_activation_done_payouts', gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) );
lnkup_t_same( $baseline, LNKup_Comms_Analytics::activation_attributed( 'o_setup_payouts', $since, $window ), 'completed before the message → not attributed' );
update_user_meta( $org_p, '_lnkup_activation_done_payouts', gmdate( 'Y-m-d H:i:s' ) );
lnkup_t_same( $baseline + 1, LNKup_Comms_Analytics::activation_attributed( 'o_setup_payouts', $since, $window ), 'completed after the message → attributed' );
lnkup_cat_profile( $org );
LNKup_Organizer_Activation::for_user( $org, true );
lnkup_t_assert( '' !== (string) get_user_meta( $org, '_lnkup_activation_done_organization', true ), 'activation model records first completion time' );
lnkup_t_same( 'organizer_activation', LNKup_Comms_Analytics::flow_group( 'o_setup_payouts' ), 'grouped under organizer activation' );

remove_filter( 'option_lnkup_settings', $connect_filter );
remove_filter( 'default_option_lnkup_settings', $connect_filter );
// Daily scans may have queued nudges for unrelated local accounts; never leave them behind.
$keep = implode( ',', array_map( 'intval', $GLOBALS['lnkup_ct_users'] ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$messages_table} WHERE id > %d AND recipient_user_id NOT IN ({$keep})", $max_before ) ); // phpcs:ignore WordPress.DB
lnkup_ct_finish();
