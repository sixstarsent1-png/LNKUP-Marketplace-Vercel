<?php
/**
 * Phases 8-9: staged audience rollout shared by marketplace and communications flags.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/rollout-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_comms_helpers.php';

global $wpdb;
echo "Staged rollout\n";

$GLOBALS['lnkup_ro_settings'] = get_option( 'lnkup_settings', array() );
$GLOBALS['lnkup_ro_rollout']  = get_option( LNKup_Rollout::OPTION, null );
$GLOBALS['lnkup_ro_log']      = get_option( LNKup_Rollout::LOG_OPTION, null );
update_option(
	'lnkup_settings',
	array_merge(
		(array) $GLOBALS['lnkup_ro_settings'],
		array(
			'matching_enabled'                  => true,
			'matching_vendor_discovery_enabled' => true,
			'matching_tours_enabled'            => true,
			'apply_wizard_enabled'              => true,
			'platform_prelaunch_mode'           => false,
		)
	),
	false
);
delete_option( LNKup_Rollout::OPTION );
lnkup_ct_all_flows();

$in    = lnkup_ct_user( 'lnkup_vendor' );
$out   = lnkup_ct_user( 'lnkup_vendor' );
$admin = lnkup_ct_user( 'administrator' );
$org   = lnkup_ct_user( 'lnkup_organizer' );
lnkup_ct_profile( $in );
lnkup_ct_profile( $out );
$flag = 'matching_vendor_discovery_enabled';

echo "Defaults and buckets\n";
lnkup_t_same( 'all', LNKup_Rollout::config( $flag )['audience'], 'no rollout entry means everyone' );
lnkup_t_same( true, LNKup_Match_Settings::enabled( $flag, $out ), 'without an audience every vendor sees the feature' );
lnkup_t_same( LNKup_Rollout::bucket( 'matching_vendor', $in ), LNKup_Rollout::bucket( 'matching_vendor', $in ), 'buckets are stable' );
$hits = 0;
for ( $user = 1; $user <= 2000; $user++ ) {
	$hits += LNKup_Rollout::bucket( 'matching_vendor', $user ) < 25 ? 1 : 0;
}
lnkup_t_assert( $hits > 400 && $hits < 600, 'a 25% bucket holds roughly a quarter of users (' . $hits . '/2000)' );

echo "Cohort audience\n";
LNKup_Rollout::set( $flag, array( 'stage' => 'internal', 'user_ids' => array( $in ) ), 'rollout test' );
lnkup_t_same( true, LNKup_Match_Settings::enabled( $flag, $in ), 'allowlisted vendor is in' );
lnkup_t_same( false, LNKup_Match_Settings::enabled( $flag, $out ), 'other vendor is out at the internal stage' );
lnkup_t_same( true, LNKup_Match_Settings::enabled( $flag, $admin ), 'admins are always in' );
lnkup_t_same( true, LNKup_Match_Settings::enabled( $flag, 0 ), 'system context (cron, background jobs) stays active' );
lnkup_t_same( true, LNKup_Match_Settings::active( $flag ), 'feature stays active for compute while ramping' );
lnkup_t_same( true, LNKup_Match_Settings::enabled( 'matching_tours_enabled', $out ), 'other flags are unaffected' );

wp_set_current_user( $out );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/match/vendor/recommendations' ) );
lnkup_t_same( false, $res->get_data()['enabled'] ?? null, 'REST: vendor outside the audience gets enabled:false' );
wp_set_current_user( $in );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/match/vendor/recommendations' ) );
lnkup_t_same( true, (bool) ( $res->get_data()['enabled'] ?? false ) || 200 === $res->get_status(), 'REST: allowlisted vendor is served' );
wp_set_current_user( 0 );
lnkup_t_same( true, LNKup_Rollout::in_audience( $flag ), 'signed-out CLI / cron work counts as system' );
add_filter( 'lnkup_rollout_system_context', '__return_false' );
lnkup_t_same( false, LNKup_Rollout::in_audience( $flag ), 'anonymous web visitors wait until everyone' );
remove_filter( 'lnkup_rollout_system_context', '__return_false' );

$bucket_out = LNKup_Rollout::bucket( 'matching_vendor', $out );
LNKup_Rollout::set( $flag, array( 'percent' => $bucket_out + 1 ), 'widen' );
lnkup_t_same( true, LNKup_Match_Settings::enabled( $flag, $out ), 'raising the percentage past the bucket lets the vendor in' );
LNKup_Rollout::set( $flag, array( 'percent' => $bucket_out ), 'narrow' );
lnkup_t_same( false, LNKup_Match_Settings::enabled( $flag, $out ), 'lowering it below the bucket takes them out again' );

echo "Apply flags\n";
LNKup_Rollout::set( 'apply_wizard_enabled', array( 'stage' => 'internal', 'user_ids' => array( $in ) ), 'rollout test' );
$opp_event = lnkup_ct_event( $org );
lnkup_t_same( true, LNKup_Applications::build_apply_context( $opp_event['opp'], $in )['features']['drafts'], 'apply drafts on for the allowlisted vendor' );
lnkup_t_same( false, LNKup_Applications::build_apply_context( $opp_event['opp'], $out )['features']['drafts'], 'apply drafts off for the rest' );

echo "Communications\n";
LNKup_Rollout::set( 'flow_discovery', array( 'stage' => 'internal', 'user_ids' => array( $in ) ), 'rollout test' );
$ctx = static function ( $user ) {
	return array( 'vendor_user_id' => $user, 'search_name' => 'Rollout test', 'count' => '1' );
};
$msg_in  = LNKup_Comms_Engine::dispatch( 'v_saved_search_match', $in, $ctx( $in ), array( 'window' => 'ro1' ) );
$msg_out = LNKup_Comms_Engine::dispatch( 'v_saved_search_match', $out, $ctx( $out ), array( 'window' => 'ro1' ) );
lnkup_t_assert( $msg_in > 0, 'cohort vendor gets the discovery message' );
lnkup_t_same( 0, $msg_out, 'vendor outside the cohort gets nothing from Communications' );
LNKup_Rollout::set( 'flow_discovery', array( 'user_ids' => array() ), 'narrow' );
LNKup_Comms_Engine::deliver( $msg_in );
$row = lnkup_ct_message( $msg_in );
lnkup_t_same( array( 'suppressed', 'cohort_excluded' ), array( $row['status'] ?? '', $row['suppression_reason'] ?? '' ), 'audience narrowed after scheduling: suppressed at send time' );
$sent = LNKup_Notifications::send_saved_search_match( $out, 'Rollout test', 1, array( array( 'opportunity_id' => $opp_event['opp'], 'name' => 'Booth' ) ), array(), 'ro' );
lnkup_t_assert( true !== $sent || ! lnkup_ct_messages_for( $out, 'v_saved_search_match' ), 'outside the cohort the core send is not claimed' );
lnkup_t_same( array(), lnkup_ct_messages_for( $out, 'v_saved_search_match' ), 'no Communications message for the excluded vendor' );

echo "Stage gates\n";
LNKup_Rollout::set( $flag, array( 'stage' => 'internal' ), 'reset' );
$blocked = LNKup_Rollout::advance( $flag, 'p5', 'too soon' );
lnkup_t_assert( is_wp_error( $blocked ) && 'lnkup_rollout_gates' === $blocked->get_error_code(), 'advancing before 3 days at the stage is blocked' );
lnkup_t_assert( is_wp_error( LNKup_Rollout::advance( $flag, 'p5', '', true ) ), 'override needs a note' );
$forced = LNKup_Rollout::advance( $flag, 'p5', 'pilot sign-off', true );
lnkup_t_assert( ! is_wp_error( $forced ) && 5 === $forced['percent'], 'override with a note advances to 5%' );
lnkup_t_assert( 0 === strpos( LNKup_Rollout::history( $flag )[0]['note'], '[override:' ), 'history records the override and failing checks' );
lnkup_t_assert( ! is_wp_error( LNKup_Rollout::advance( $flag, 'internal', 'roll back' ) ), 'narrowing never needs checks' );
lnkup_t_assert( is_wp_error( LNKup_Rollout::set( 'not_a_flag', array( 'percent' => 5 ) ) ), 'unknown flags are refused' );

echo "CLI\n";
$cli = WP_CLI::runcommand( 'lnkup rollout set ' . $flag . ' --audience=all --note=cli-test', array( 'return' => 'all', 'exit_error' => false ) );
lnkup_t_same( 0, (int) $cli->return_code, 'wp lnkup rollout set succeeds' );
wp_cache_delete( LNKup_Rollout::OPTION, 'options' ); // The command ran in a child process.
lnkup_t_same( 'all', LNKup_Rollout::config( $flag )['audience'], 'CLI change is applied' );
$status = WP_CLI::runcommand( 'lnkup rollout status --skip-plugins=hello', array( 'return' => 'all', 'exit_error' => false ) );
lnkup_t_assert( false !== strpos( (string) $status->stdout, 'flow_discovery' ), 'wp lnkup rollout status lists comms flows' );

update_option( 'lnkup_settings', $GLOBALS['lnkup_ro_settings'], false );
foreach ( array( LNKup_Rollout::OPTION => 'lnkup_ro_rollout', LNKup_Rollout::LOG_OPTION => 'lnkup_ro_log' ) as $option => $saved ) {
	if ( null === $GLOBALS[ $saved ] ) {
		delete_option( $option );
	} else {
		update_option( $option, $GLOBALS[ $saved ], false );
	}
}
lnkup_ct_finish();
