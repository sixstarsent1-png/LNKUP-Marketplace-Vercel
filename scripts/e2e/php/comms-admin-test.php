<?php
/**
 * Admin command center: template drafts / publish / restore with validation and history,
 * per-user timeline (audited), suppression list removal (note required, audited),
 * settings audit, holdout comparison and admin-only REST access.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/comms-admin-test.php
 *
 * @package LNKup_Communications
 */

require __DIR__ . '/_comms_helpers.php';

global $wpdb;
lnkup_ct_all_flows();
$admin  = lnkup_ct_user( 'administrator' );
$vendor = lnkup_ct_user( 'lnkup_vendor' );
lnkup_ct_profile( $vendor );
wp_set_current_user( $admin );
$key = 'v_application_submitted';

/**
 * Communications audit rows recorded for an action since the test started.
 */
function lnkup_ca_audits( string $action ): int {
	if ( ! class_exists( 'LNKup_Audit_Log' ) ) {
		return -1;
	}
	return count( LNKup_Audit_Log::list_entries( array( 'entity_type' => 'communications', 'action' => $action, 'limit' => 200 ) ) );
}

echo "Template drafts and versions\n";
$before_versions = count( LNKup_Comms_Templates::versions( $key ) );
$bad             = LNKup_Comms_Templates::save_draft( $key, array( 'subject' => 'Hi {{password}}' ), $admin );
lnkup_t_assert( is_wp_error( $bad ) && 'lnkup_comms_template_vars' === $bad->get_error_code(), 'draft with an unknown variable is rejected' );
$draft_id = LNKup_Comms_Templates::save_draft( $key, array( 'subject' => 'Draft subject for {{event_name}}' ), $admin );
lnkup_t_assert( is_int( $draft_id ) && $draft_id > 0, 'valid draft saved' );
lnkup_t_assert( false === strpos( (string) LNKup_Comms_Templates::get( $key )['subject'], 'Draft subject' ), 'drafts are never used for sending' );
LNKup_Comms_Templates::save_draft( $key, array( 'subject' => 'Second draft {{event_name}}' ), $admin );
lnkup_t_same( 'Second draft {{event_name}}', LNKup_Comms_Templates::draft( $key )['fields']['subject'] ?? '', 'one working draft per template' );
$published_audits = lnkup_ca_audits( 'comms_template_published' );
lnkup_t_same( true, LNKup_Comms_Templates::publish_draft( $key, $admin ), 'draft published' );
lnkup_t_same( 'Second draft {{event_name}}', LNKup_Comms_Templates::get( $key )['subject'], 'published draft is live' );
lnkup_t_same( null, LNKup_Comms_Templates::draft( $key ), 'draft cleared after publishing' );
if ( $published_audits >= 0 ) {
	lnkup_t_same( $published_audits + 1, lnkup_ca_audits( 'comms_template_published' ), 'publishing is audit-logged' );
}
LNKup_Comms_Templates::save_override( $key, array( 'subject' => 'Third {{event_name}}' ), $admin );
$history = LNKup_Comms_Templates::versions( $key );
lnkup_t_same( $before_versions + 2, count( $history ), 'each publish adds a version' );
lnkup_t_same( 'published', $history[0]['status'], 'newest version is published' );
lnkup_t_same( 'archived', $history[1]['status'], 'previous version archived' );
lnkup_t_same( true, LNKup_Comms_Templates::restore_version( (int) $history[1]['id'], $admin ), 'archived version restored' );
lnkup_t_same( 'Second draft {{event_name}}', LNKup_Comms_Templates::get( $key )['subject'], 'restored content is live' );
lnkup_t_assert( false !== strpos( (string) LNKup_Comms_Templates::versions( $key )[0]['note'], 'Restored' ), 'restore recorded as a new version' );
LNKup_Comms_Templates::reset_override( $key, $admin );
lnkup_t_assert( false === strpos( (string) LNKup_Comms_Templates::get( $key )['subject'], 'Second draft' ), 'reset returns to the default' );
$wpdb->delete( LNKup_Comms_Schema::table( 'template_versions' ), array( 'template_key' => $key ) );

echo "User timeline\n";
$organizer = lnkup_ct_user( 'lnkup_organizer' );
$event     = lnkup_ct_event( $organizer );
$app       = lnkup_ct_application( $vendor, $organizer, $event, 'submitted', (int) lnkup_get_vendor_profile_id( $vendor ) );
$mid       = LNKup_Comms_Engine::dispatch( 'v_application_submitted', $vendor, array( 'application_id' => $app ) );
LNKup_Comms_Engine::deliver( $mid );
$timeline = LNKup_Comms_Admin_Ops::user_timeline( $vendor );
lnkup_t_assert( is_array( $timeline ) && (int) $timeline['messages'][0]['id'] === $mid, 'timeline lists the message' );
lnkup_t_assert( ! empty( $timeline['messages'][0]['deliveries'] ), 'timeline includes per-channel delivery' );
lnkup_t_assert( isset( $timeline['preferences']['categories'] ), 'timeline includes preferences' );
lnkup_t_assert( false === strpos( (string) wp_json_encode( $timeline ), 'user_pass' ), 'timeline never exposes credentials' );
$viewed = lnkup_ca_audits( 'comms_timeline_viewed' );
$res    = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/comms/admin/users/' . $vendor . '/timeline' ) );
lnkup_t_same( 200, $res->get_status(), 'admin reads a timeline over REST' );
if ( $viewed >= 0 ) {
	lnkup_t_same( $viewed + 1, lnkup_ca_audits( 'comms_timeline_viewed' ), 'timeline view is audit-logged' );
}
wp_set_current_user( $vendor );
lnkup_t_assert( in_array( rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/comms/admin/users/' . $vendor . '/timeline' ) )->get_status(), array( 401, 403 ), true ), 'non-admins cannot read timelines, even their own' );
lnkup_t_assert( in_array( rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/comms/admin/suppressions' ) )->get_status(), array( 401, 403 ), true ), 'non-admins cannot read the suppression list' );
wp_set_current_user( $admin );

echo "Suppression list\n";
$email = 'suppressed-' . wp_generate_password( 6, false ) . '@example.test';
LNKup_Comms_Suppression::add( $email, 'all', 'bounce' );
$found = LNKup_Comms_Admin_Ops::suppressions( $email );
lnkup_t_same( 1, $found['total'], 'search by email finds the hashed row' );
lnkup_t_assert( false === strpos( (string) wp_json_encode( $found ), $email ), 'the list never contains the raw address' );
$no_note = LNKup_Comms_Admin_Ops::remove_suppression( (int) $found['items'][0]['id'], '' );
lnkup_t_assert( is_wp_error( $no_note ), 'removal requires a note' );
lnkup_t_same( true, LNKup_Comms_Admin_Ops::remove_suppression( (int) $found['items'][0]['id'], 'Mailbox fixed, confirmed by support' ), 'removed with a note' );
lnkup_t_same( 0, LNKup_Comms_Admin_Ops::suppressions( $email )['total'], 'row gone' );

echo "Settings audit\n";
$changes = lnkup_ca_audits( 'comms_settings_changed' );
$before  = LNKup_Comms_Settings::all();
LNKup_Comms_Admin_Ops::audit_settings( $before, LNKup_Comms_Settings::update( array( 'cooldown_hours' => 6, 'email_webhook_secret' => 'top-secret' ) ) );
if ( $changes >= 0 ) {
	lnkup_t_same( $changes + 1, lnkup_ca_audits( 'comms_settings_changed' ), 'settings change audit-logged' );
	$last = LNKup_Audit_Log::list_entries( array( 'entity_type' => 'communications', 'action' => 'comms_settings_changed', 'limit' => 1 ) );
	lnkup_t_assert( false === strpos( (string) wp_json_encode( $last ), 'top-secret' ), 'webhook secret never written to the audit log' );
}

echo "Holdout comparison\n";
lnkup_ct_settings( array( 'holdout_percent' => 50, 'holdout_triggers' => array( 'v_inactive' ) ) );
$held = 0;
$sent = 0;
for ( $i = 0; $i < 30 && ( ! $held || ! $sent ); $i++ ) {
	$u = lnkup_ct_user( 'lnkup_vendor' );
	if ( LNKup_Comms_Settings::holdout_bucket( $u ) < 50 ) {
		$held = $held ?: $u;
	} else {
		$sent = $sent ?: $u;
	}
}
foreach ( array( $held, $sent ) as $u ) {
	$wpdb->insert(
		LNKup_Comms_Schema::table( 'messages' ),
		array( 'trigger_key' => 'v_inactive', 'recipient_user_id' => $u, 'recipient_role' => 'vendor', 'category' => 'opportunities', 'priority' => 'promotional', 'template_key' => 'v_inactive', 'status' => $u === $held ? 'suppressed' : 'sent', 'suppression_reason' => $u === $held ? 'holdout' : '', 'dedupe_key' => 'holdout-test-' . $u, 'scheduled_at' => LNKup_Comms_Schema::now( -HOUR_IN_SECONDS ), 'created_at' => LNKup_Comms_Schema::now( -HOUR_IN_SECONDS ), 'updated_at' => LNKup_Comms_Schema::now() )
	);
}
lnkup_ct_application( $sent, $organizer, $event, 'submitted' );
$report = array_column( LNKup_Comms_Admin_Ops::holdout_report( 7 ), null, 'trigger' );
lnkup_t_assert( isset( $report['v_inactive'] ), 'holdout trigger appears in the comparison' );
lnkup_t_same( 1, $report['v_inactive']['treated']['converted'] ?? null, 'messaged user who applied is counted' );
lnkup_t_same( 0, $report['v_inactive']['holdout']['converted'] ?? null, 'held-out user without an application is not' );
lnkup_t_same( true, $report['v_inactive']['small_sample'] ?? null, 'small groups are flagged' );

wp_set_current_user( 0 );
lnkup_ct_finish();
