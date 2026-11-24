<?php
/**
 * Policy layer: default caps, weekly push cap, per-category caps, cross-channel
 * cooldown and measurement holdouts. Transactional messages are never capped.
 *
 * @package LNKup_Communications
 */

require_once __DIR__ . '/_comms_helpers.php';

lnkup_ct_all_flows();
$GLOBALS['lnkup_cp_settings'] = get_option( 'lnkup_settings', array() );
update_option( 'lnkup_settings', array_merge( (array) $GLOBALS['lnkup_cp_settings'], array( 'matching_enabled' => false ) ), false );

/**
 * Dispatch + deliver one promotional opportunity alert on a fresh opportunity.
 *
 * @return array{id:int,push:string,inbox:string}
 */
function lnkup_cp_promo( int $vendor, int $organizer ): array {
	$extra = lnkup_ct_event( $organizer );
	$mid   = LNKup_Comms_Engine::dispatch( 'v_opportunity_match', $vendor, array( 'opportunity_id' => $extra['opp'], 'vendor_user_id' => $vendor ) );
	if ( $mid ) {
		LNKup_Comms_Engine::deliver( $mid );
	}
	$push  = $mid ? lnkup_ct_deliveries( $mid, 'push' ) : array();
	$inbox = $mid ? lnkup_ct_deliveries( $mid, 'inbox' ) : array();
	return array(
		'id'    => (int) $mid,
		'push'  => $push ? $push[0]['status'] . ':' . $push[0]['suppression_reason'] : 'none',
		'inbox' => $inbox ? (string) $inbox[0]['status'] : 'none',
	);
}

/**
 * Vendor with a push device who wants opportunity pushes but no opportunity email.
 */
function lnkup_cp_vendor(): int {
	$vendor = lnkup_ct_user( 'lnkup_vendor' );
	lnkup_ct_profile( $vendor );
	lnkup_ct_device( $vendor );
	LNKup_Comms_Preferences::update( $vendor, array( 'categories' => array( array( 'key' => 'opportunities', 'email' => 'off', 'push' => 'on' ) ) ) );
	return $vendor;
}

$organizer = lnkup_ct_user( 'lnkup_organizer' );

echo "Defaults\n";
$defaults = LNKup_Comms_Settings::defaults();
lnkup_t_same( 1, $defaults['cap_promo_push_daily'], '1 promotional push per day' );
lnkup_t_same( 3, $defaults['cap_promo_push_weekly'], '3 promotional pushes per week' );
lnkup_t_same( 2, $defaults['cap_promo_email_weekly'], '2 promotional emails per week' );
lnkup_t_assert( $defaults['cooldown_hours'] > 0, 'cross-channel cooldown on by default' );
lnkup_t_same( 0, $defaults['holdout_percent'], 'holdout off by default' );

echo "Weekly push cap\n";
lnkup_ct_settings( array( 'cap_promo_push_daily' => 10, 'cap_promo_push_weekly' => 2, 'cooldown_hours' => 0 ) );
$vendor = lnkup_cp_vendor();
$states = array();
for ( $i = 0; $i < 3; $i++ ) {
	$states[] = lnkup_cp_promo( $vendor, $organizer );
}
lnkup_t_same( 'provider_accepted:', $states[1]['push'], 'second push within the weekly cap' );
lnkup_t_same( 'suppressed:frequency_cap', $states[2]['push'], 'third push blocked by the weekly cap' );
lnkup_t_same( 'delivered', $states[2]['inbox'], 'capped message still lands in the inbox' );

echo "Per-category cap\n";
lnkup_ct_settings( array( 'cap_promo_push_daily' => 10, 'cap_promo_push_weekly' => 10, 'cap_category_push_weekly' => 1, 'cooldown_hours' => 0 ) );
$vendor = lnkup_cp_vendor();
$first  = lnkup_cp_promo( $vendor, $organizer );
$second = lnkup_cp_promo( $vendor, $organizer );
lnkup_t_same( 'provider_accepted:', $first['push'], 'first opportunity push sent' );
lnkup_t_same( 'suppressed:frequency_cap_category', $second['push'], 'second opportunity push blocked by the category cap' );
lnkup_ct_settings( array( 'cap_category_push_weekly' => 0 ) );

echo "Cross-channel cooldown\n";
lnkup_ct_settings( array( 'cap_promo_push_daily' => 10, 'cap_promo_push_weekly' => 10, 'cooldown_hours' => 4 ) );
$vendor  = lnkup_cp_vendor();
$profile = (int) lnkup_get_vendor_profile_id( $vendor );
$event   = lnkup_ct_event( $organizer );
$app     = lnkup_ct_application( $vendor, $organizer, $event, 'payment_required', $profile );
lnkup_ct_payment( $app, $vendor, $organizer, 'pending' );
$approved = LNKup_Comms_Engine::dispatch( 'v_application_approved', $vendor, array( 'application_id' => $app ), array( 'variant' => 'payment' ) );
LNKup_Comms_Engine::deliver( $approved );
$approved_push = lnkup_ct_deliveries( $approved, 'push' );
lnkup_t_same( 'provider_accepted', $approved_push[0]['status'] ?? '', 'high-priority approval push is never capped' );
$promo = lnkup_cp_promo( $vendor, $organizer );
lnkup_t_same( 'suppressed:cooldown_cross_channel', $promo['push'], 'promotional push held back right after an important message' );
lnkup_t_same( 'delivered', $promo['inbox'], 'held-back promotion still appears in the inbox' );
lnkup_ct_settings( array( 'cooldown_hours' => 0 ) );
$later = lnkup_cp_promo( $vendor, $organizer );
lnkup_t_same( 'provider_accepted:', $later['push'], 'cooldown off: promotional push sent' );

echo "Holdouts\n";
lnkup_ct_settings( array( 'holdout_percent' => 50, 'holdout_triggers' => array( 'v_opportunity_match' ), 'cooldown_hours' => 0, 'cap_promo_push_daily' => 10, 'cap_promo_push_weekly' => 10 ) );
$held = 0;
$kept = 0;
for ( $i = 0; $i < 40 && ( ! $held || ! $kept ); $i++ ) {
	$candidate = lnkup_ct_user( 'lnkup_vendor' );
	if ( LNKup_Comms_Settings::holdout_bucket( $candidate ) < 50 ) {
		$held = $held ?: $candidate;
	} else {
		$kept = $kept ?: $candidate;
	}
}
lnkup_t_assert( $held > 0 && $kept > 0, 'found users on both sides of the holdout' );
lnkup_ct_profile( $held );
lnkup_ct_profile( $kept );
$h_event = lnkup_ct_event( $organizer );
lnkup_t_same( 0, LNKup_Comms_Engine::dispatch( 'v_opportunity_match', $held, array( 'opportunity_id' => $h_event['opp'], 'vendor_user_id' => $held ) ), 'held-out user is not queued' );
$h_rows = lnkup_ct_messages_for( $held, 'v_opportunity_match' );
lnkup_t_same( 'suppressed', $h_rows[0]['status'] ?? '', 'held-out message recorded for analytics' );
lnkup_t_same( 'holdout', $h_rows[0]['suppression_reason'] ?? '', 'recorded with the holdout reason' );
lnkup_t_same( 0, count( lnkup_ct_deliveries( (int) ( $h_rows[0]['id'] ?? 0 ) ) ), 'held-out message has no deliveries on any channel' );
lnkup_t_assert( LNKup_Comms_Engine::dispatch( 'v_opportunity_match', $kept, array( 'opportunity_id' => $h_event['opp'], 'vendor_user_id' => $kept ) ) > 0, 'user outside the holdout is queued normally' );
lnkup_t_assert( ! LNKup_Comms_Settings::in_holdout( 'v_application_approved', $held, 'high', 'applications' ), 'transactional triggers are never held out' );
lnkup_t_assert( ! LNKup_Comms_Settings::in_holdout( 'v_weekly_matches', $held, 'promotional', 'opportunities' ), 'triggers not selected are never held out' );
lnkup_ct_settings( array( 'holdout_percent' => 90 ) );
lnkup_t_assert( ! LNKup_Comms_Settings::in_holdout( 'v_opportunity_match', $kept, 'promotional', 'opportunities' ), 'holdout percent is capped at 50%' );

update_option( 'lnkup_settings', $GLOBALS['lnkup_cp_settings'], false );
lnkup_ct_finish();
