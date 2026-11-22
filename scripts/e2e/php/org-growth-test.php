<?php
/**
 * Organizer Acquisition OS acceptance tests (server side, real WordPress on wp-env).
 *
 * Covers: import + dry run + rollback, dedupe + merge, connector assignment, pipeline guards,
 * outreach logging, campaign safeguards, unsubscribe/suppression, attributed registration,
 * milestones from real lifecycle hooks, multi-event org counted once, refund voiding the paid
 * milestone, idempotent retries, and REST access control.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/org-growth-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Organizer Growth acceptance\n";

$run      = strtolower( wp_generate_password( 6, false ) );
$prefix   = 'OGT ' . $run;
$saved    = get_option( LNKup_Org_Growth::SETTINGS_KEY, null );
$lead_ids = array();
$posts    = array();
$campaign_ids = array();
$import_ids   = array();
$emails       = array();

$admin  = lnkup_t_user( 'administrator' );
$admin2 = lnkup_t_user( 'administrator' );
wp_set_current_user( $admin );

LNKup_Org_Growth::update_settings(
	array(
		'organizer_growth_enabled'            => true,
		'organizer_growth_outreach_enabled'   => true,
		'org_growth_sender_postal_address'    => "LNKup Test\n1 Main St, Charlotte NC 28202",
		'org_growth_approval_threshold'       => 2,
		'org_growth_outreach_dedupe_days'     => 14,
		'org_growth_contact_stale_days'       => 180,
		'org_growth_activation_paid_bookings' => 1,
		'org_growth_campaign_daily_cap'       => 50,
	)
);

$t = static fn( string $s ): string => LNKup_Org_Growth_Tables::table( $s );

// --------------------------------------------------------------- 1. Import (dry run, run, history).
echo "\n[1] import\n";
$existing = LNKup_Org_Leads::create(
	array( 'name' => $prefix . ' Existing Market Co', 'email' => "existing-{$run}@marketco-{$run}.test", 'city' => 'Atlanta', 'state' => 'GA', 'source' => 'manual' ),
	array( 'actor' => $admin )
);
lnkup_t_assert( is_int( $existing ), 'pre-existing lead created' );
$lead_ids[] = (int) $existing;

$today = gmdate( 'Y-m-d' );
$csv   = "Organizer name,Public email,Website,Instagram,City,State,Event frequency,Event categories,Estimated booths,Application process,Next event date,Known events,Verification date,Tags\n";
$csv  .= "\"{$prefix} Queen City Makers Market\",hello@qcm-{$run}.test,https://qcm-{$run}.test,@qcm{$run},Charlotte,NC,Monthly,\"Food, Art\",80,Google Form,2027-03-01,\"Spring Market; Fall Market\",{$today},\"nc,recurring\"\n";
$csv  .= "\"{$prefix} QCM Duplicate Row\",info@qcm-{$run}.test,,,Charlotte,NC,,,,,,,{$today},\n";
$csv  .= "\"{$prefix} Peach Street Fest\",,,@peach{$run},Savannah,GA,Annual,Music,30,,,,{$today},ga\n";
$csv  .= ",nobody-{$run}@example.test,,,Durham,NC,,,,,,,,\n";
$csv  .= "\"{$prefix} Existing Again\",existing-{$run}@marketco-{$run}.test,,,Atlanta,GA,,,,,,,{$today},\n";
$csv  .= "\"{$prefix} Bull City Night Market\",team@bcnm-{$run}.test,bcnm-{$run}.test,,Durham,NC,Weekly,Food,40,Email us,,,{$today},nc\n";
$file = wp_tempnam( 'og-import.csv' );
file_put_contents( $file, $csv ); // phpcs:ignore

$staged = LNKup_Org_Lead_Import::stage_file( $file, 'organizers.csv', array( 'source_label' => 'NC/GA research ' . $run, 'verified_at' => $today ), $admin );
lnkup_t_assert( ! is_wp_error( $staged ), 'CSV staged' );
$import_id    = (int) $staged['import']['id'];
$import_ids[] = $import_id;
lnkup_t_same( 6, (int) $staged['import']['total_rows'], 'six data rows staged' );
lnkup_t_same( 'name', $staged['mapping']['Organizer name'], 'header "Organizer name" auto-maps to name' );
lnkup_t_same( 'email', $staged['mapping']['Public email'], 'header "Public email" auto-maps to email' );
lnkup_t_same( 'source_verified_at', $staged['mapping']['Verification date'], 'verification date auto-maps' );

$before_leads = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Org_Leads::table() ); // phpcs:ignore
$preview      = LNKup_Org_Lead_Import::preview( $import_id, $staged['mapping'] );
lnkup_t_assert( ! is_wp_error( $preview ), 'dry run succeeds' );
lnkup_t_same( $before_leads, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Org_Leads::table() ), 'dry run writes no leads' ); // phpcs:ignore
lnkup_t_same( 3, $preview['summary']['would_create'], 'dry run: 3 new leads' );
lnkup_t_same( 1, $preview['summary']['duplicate_in_file'], 'dry run: same-domain row flagged as in-file duplicate' );
lnkup_t_same( 1, $preview['summary']['duplicate'], 'dry run: row matching an existing lead flagged' );
lnkup_t_same( 1, $preview['summary']['error'], 'dry run: row without a name is an error' );

lnkup_t_assert( is_wp_error( LNKup_Org_Lead_Import::run( 999999 ) ), 'running an unknown import is refused' );
$ran = LNKup_Org_Lead_Import::run( $import_id );
lnkup_t_assert( ! is_wp_error( $ran ) && 'completed' === $ran['status'], 'import completes (' . ( is_array( $ran ) ? $ran['status'] : 'error' ) . ')' );
lnkup_t_assert( is_wp_error( LNKup_Org_Lead_Import::run( $import_id ) ), 'an import cannot run twice' );
$imp = LNKup_Org_Lead_Import::get( $import_id );
lnkup_t_same( 3, $imp['created_count'], 'three leads created' );
lnkup_t_same( 2, $imp['duplicate_count'], 'in-file and existing-lead duplicates skipped' );
lnkup_t_same( 1, $imp['error_count'], 'nameless row recorded as error' );
$created = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Org_Leads::table() . ' WHERE import_id = %d ORDER BY id', $import_id ) ) ); // phpcs:ignore
$lead_ids = array_merge( $lead_ids, $created );

$qcm = LNKup_Org_Leads::get_row( $created[0] );
lnkup_t_same( 'qcm-' . $run . '.test', $qcm['domain'], 'website normalized to domain' );
lnkup_t_same( 'qcm' . $run, $qcm['instagram'], 'Instagram handle normalized' );
lnkup_t_same( 'monthly', $qcm['event_frequency'], 'frequency normalized' );
lnkup_t_same( 'discovered', $qcm['stage'], 'imported lead with contact starts in Discovered' );
lnkup_t_assert( (int) $qcm['score'] > 0 && '' !== $qcm['tier'], 'imported lead scored (' . $qcm['score'] . ' / ' . $qcm['tier'] . ')' );
$expl = json_decode( (string) $qcm['score_explanation'], true );
lnkup_t_assert( is_array( $expl ) && count( $expl ) === count( LNKup_Org_Lead_Scoring::FACTORS ), 'score explanation lists every factor' );
lnkup_t_same( array( 'nc', 'recurring' ), LNKup_Org_Leads::get_tags( $created[0] ), 'tags imported' );
lnkup_t_same( 2, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( LNKup_Org_Growth_Tables::LEAD_EVENTS ) . ' WHERE lead_id = %d', $created[0] ) ), 'known events imported' ); // phpcs:ignore
$peach = null;
foreach ( $created as $cid ) {
	$row = LNKup_Org_Leads::get_row( $cid );
	if ( false !== strpos( $row['name'], 'Peach' ) ) {
		$peach = $row;
	}
}
lnkup_t_same( 'discovered', $peach['stage'] ?? '', 'Instagram-only lead still counts as having a contact' );
lnkup_t_same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS ) . ' WHERE lead_id IN (' . implode( ',', $created ) . ')' ), 'importing never queues outreach' ); // phpcs:ignore
lnkup_t_assert( (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $t( LNKup_Org_Growth_Tables::ACTIVITY ) . " WHERE lead_id = %d AND type = 'import_matched'", (int) $existing ) ), 'existing lead records that the import found it again' ); // phpcs:ignore

// Rollback: a worked lead is kept, untouched leads are removed.
$csv2 = "Organizer name,Public email,City,State\n\"{$prefix} Rollback A\",ra@rba-{$run}.test,Raleigh,NC\n\"{$prefix} Rollback B\",rb@rbb-{$run}.test,Raleigh,NC\n";
file_put_contents( $file, $csv2 ); // phpcs:ignore
$staged2 = LNKup_Org_Lead_Import::stage_file( $file, 'rollback.csv', array( 'verified_at' => $today ), $admin );
$rid     = (int) $staged2['import']['id'];
$import_ids[] = $rid;
LNKup_Org_Lead_Import::preview( $rid, $staged2['mapping'] );
LNKup_Org_Lead_Import::run( $rid );
$rb_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Org_Leads::table() . ' WHERE import_id = %d ORDER BY id', $rid ) ) ); // phpcs:ignore
LNKup_Org_Leads::record_contact( $rb_ids[0], array( 'channel' => 'phone', 'summary' => 'Called' ), $admin );
$rolled = LNKup_Org_Lead_Import::rollback( $rid, $admin );
lnkup_t_same( 1, $rolled['removed'], 'rollback removes the untouched lead' );
lnkup_t_same( array( $rb_ids[0] ), $rolled['kept'], 'rollback keeps the lead someone already worked' );
lnkup_t_assert( null === LNKup_Org_Leads::get_row( $rb_ids[1] ), 'rolled-back lead is gone' );
$lead_ids[] = $rb_ids[0];

// --------------------------------------------------------------- 2. Dedupe + merge.
echo "\n[2] dedupe\n";
$dup = LNKup_Org_Leads::create( array( 'name' => 'Different Name', 'email' => 'HELLO@qcm-' . $run . '.test' ), array( 'actor' => $admin ) );
lnkup_t_assert( is_wp_error( $dup ) && 'lnkup_lead_duplicate' === $dup->get_error_code(), 'same email (any case) is a hard duplicate' );
$dup = LNKup_Org_Leads::create( array( 'name' => 'Other Co', 'website' => 'www.qcm-' . $run . '.test/about' ), array( 'actor' => $admin ) );
lnkup_t_assert( is_wp_error( $dup ) && 'domain' === ( $dup->get_error_data()['reason'] ?? '' ), 'same website domain is a hard duplicate' );
$gmail_a = LNKup_Org_Leads::create( array( 'name' => $prefix . ' Gmail One', 'email' => "one{$run}@gmail.com" ), array( 'actor' => $admin ) );
$gmail_b = LNKup_Org_Leads::create( array( 'name' => $prefix . ' Gmail Two', 'email' => "two{$run}@gmail.com" ), array( 'actor' => $admin ) );
lnkup_t_assert( is_int( $gmail_a ) && is_int( $gmail_b ), 'two businesses on gmail.com are not merged by domain' );
$lead_ids[] = (int) $gmail_a;
$lead_ids[] = (int) $gmail_b;
$similar = LNKup_Org_Leads::create( array( 'name' => $prefix . ' Queen City Makers Market LLC', 'state' => 'NC', 'phone' => '704-555-0100' ), array( 'actor' => $admin ) );
lnkup_t_assert( is_int( $similar ), 'similar name is allowed (never auto-merged)' );
$lead_ids[] = (int) $similar;
lnkup_t_same( $created[0], (int) LNKup_Org_Leads::get_row( (int) $similar )['possible_duplicate_of'], 'similar name flagged as possible duplicate for review' );
LNKup_Org_Leads::log_activity( (int) $similar, 'note', array( 'actor' => $admin, 'summary' => 'Note on the duplicate' ) );
$merged = LNKup_Org_Lead_Dedupe::merge( (int) $similar, $created[0], $admin );
lnkup_t_assert( true === $merged, 'manual merge succeeds' );
lnkup_t_same( $created[0], (int) LNKup_Org_Leads::get_row( (int) $similar )['merged_into_id'], 'merged lead points at the survivor' );
lnkup_t_same( '704-555-0100', LNKup_Org_Leads::get_row( $created[0] )['phone'], 'blank fields filled from the merged lead' );
lnkup_t_assert( (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $t( LNKup_Org_Growth_Tables::ACTIVITY ) . " WHERE lead_id = %d AND summary = 'Note on the duplicate'", $created[0] ) ), 'history moves to the survivor' ); // phpcs:ignore
lnkup_t_assert( is_wp_error( LNKup_Org_Lead_Dedupe::merge( (int) $similar, $created[0], $admin ) ), 'a merged lead cannot be merged again' );

// --------------------------------------------------------------- 3. Assign City Connector + owner.
echo "\n[3] assignment\n";
$wpdb->insert( LNKup_Connector_Tables::connectors_table(), array( 'display_name' => $prefix . ' Connector', 'email' => "cc-{$run}@example.test", 'city' => 'Charlotte', 'state' => 'NC', 'status' => 'active', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$connector_id = (int) $wpdb->insert_id;
$updated      = LNKup_Org_Leads::update( $created[0], array( 'assigned_connector_id' => $connector_id, 'assigned_user_id' => $admin2 ), $admin );
lnkup_t_same( $connector_id, $updated['assigned_connector_id'] ?? null, 'lead assigned to City Connector' );
lnkup_t_same( $admin2, $updated['assigned_user_id'] ?? null, 'lead assigned to a teammate' );
lnkup_t_same( 2, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( LNKup_Org_Growth_Tables::ACTIVITY ) . " WHERE lead_id = %d AND type = 'assigned'", $created[0] ) ), 'both assignments logged' ); // phpcs:ignore
$bulk = LNKup_Org_Leads::bulk( array( $gmail_a, $gmail_b ), 'assign_user', $admin, $admin );
lnkup_t_same( 2, $bulk['updated'], 'bulk assignment' );

// --------------------------------------------------------------- 4. Pipeline guards.
echo "\n[4] pipeline\n";
$moved = LNKup_Org_Leads::move( $created[0], 'qualified', '', $admin2 );
lnkup_t_same( 'qualified', $moved['stage'] ?? '', 'assignee moves lead to Qualified' );
$bad = LNKup_Org_Leads::move( $created[0], 'first_paid_booking', '', $admin2 );
lnkup_t_assert( is_wp_error( $bad ) && 'lnkup_stage_milestone_only' === $bad->get_error_code(), 'cannot hand-set First Paid Booking' );
$bad = LNKup_Org_Leads::move( $created[0], 'registered', '', $admin2 );
lnkup_t_assert( is_wp_error( $bad ) && 'lnkup_stage_needs_account' === $bad->get_error_code(), 'cannot mark Registered without a linked account' );
$bulk = LNKup_Org_Leads::bulk( array( $gmail_a ), 'stage', 'activated', $admin );
lnkup_t_same( 0, $bulk['updated'], 'bulk move into a milestone stage is refused' );

// --------------------------------------------------------------- 5. Outreach recorded accurately.
echo "\n[5] outreach logging\n";
$contact = LNKup_Org_Leads::record_contact( $created[0], array( 'channel' => 'instagram_dm', 'outcome' => 'replied', 'summary' => 'Replied to our DM', 'next_follow_up_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ), $admin2 );
lnkup_t_assert( is_int( $contact ), 'Instagram DM logged' );
$row = LNKup_Org_Leads::get_row( $created[0] );
lnkup_t_same( 'responded', $row['stage'], 'reply moves the lead to Responded' );
lnkup_t_assert( ! empty( $row['last_contacted_at'] ) && ! empty( $row['next_follow_up_at'] ), 'last contact and next follow-up recorded' );
$task = LNKup_Org_Lead_Tasks::create( $created[0], array( 'type' => 'meeting', 'due_at' => gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ) ), $admin2 );
lnkup_t_assert( ! is_wp_error( $task ) && 'open' === $task['status'], 'meeting scheduled as a task' );
lnkup_t_same( 'meeting_scheduled', LNKup_Org_Leads::get_row( $created[0] )['stage'], 'meeting moves the lead to Meeting Scheduled' );
lnkup_t_same( 'done', LNKup_Org_Lead_Tasks::complete( (int) $task['id'], $admin2 )['status'] ?? '', 'task completed' );

// --------------------------------------------------------------- 6. Campaign safeguards + unsubscribe.
echo "\n[6] campaigns + unsubscribe\n";
$fresh = array();
foreach ( array( 'alpha', 'bravo', 'charlie' ) as $n ) {
	$email      = "{$n}@{$n}-{$run}.test";
	$id         = LNKup_Org_Leads::create( array( 'name' => "{$prefix} " . ucfirst( $n ) . ' Expo', 'email' => $email, 'city' => 'Charlotte', 'state' => 'NC', 'source_verified_at' => $today ), array( 'actor' => $admin ) );
	$fresh[]    = (int) $id;
	$lead_ids[] = (int) $id;
	$emails[]   = $email;
}
$stale = LNKup_Org_Leads::create( array( 'name' => "{$prefix} Stale Co", 'email' => "old@stale-{$run}.test", 'source_verified_at' => '2024-01-01' ), array( 'actor' => $admin ) );
$lead_ids[] = (int) $stale;
$camp = LNKup_Org_Outreach::create_campaign( array( 'name' => $prefix . ' big', 'template_key' => 'first_intro', 'lead_ids' => array_merge( $fresh, array( (int) $stale, $created[0] ) ) ), $admin );
lnkup_t_assert( ! is_wp_error( $camp ), 'campaign created as draft' );
$campaign_ids[] = (int) $camp['campaign']['id'];
lnkup_t_same( 3, $camp['eligible'], 'three verified leads eligible' );
lnkup_t_same( 1, $camp['skipped']['contact_not_verified'] ?? 0, 'stale contact data excluded' );
lnkup_t_same( 1, $camp['skipped']['contacted_recently'] ?? 0, 'recently contacted lead excluded (dedupe window)' );
lnkup_t_same( 'pending', $camp['campaign']['approval_status'], 'campaign above threshold needs approval' );
$q = LNKup_Outreach::queue_campaign( (int) $camp['campaign']['id'] );
lnkup_t_assert( is_wp_error( $q ) && 'lnkup_campaign_needs_approval' === $q->get_error_code(), 'unapproved campaign cannot be queued' );
$self = LNKup_Org_Outreach::approve( (int) $camp['campaign']['id'], $admin );
lnkup_t_assert( is_wp_error( $self ) && 'lnkup_campaign_self_approve' === $self->get_error_code(), 'creator cannot approve their own large campaign' );
lnkup_t_assert( ! is_wp_error( LNKup_Org_Outreach::approve( (int) $camp['campaign']['id'], $admin2 ) ), 'second admin approves' );

$sent_mail = array();
$capture   = static function ( $null, $atts ) use ( &$sent_mail ) {
	$sent_mail[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $capture, 10, 2 );
lnkup_t_assert( true === LNKup_Outreach::queue_campaign( (int) $camp['campaign']['id'] ), 'approved campaign queued' );
$cap_before = (int) LNKup_Outreach::get_campaign( (int) $camp['campaign']['id'] )['daily_cap'];
LNKup_Outreach::update_campaign( (int) $camp['campaign']['id'], array( 'daily_cap' => 2 ) );
delete_transient( 'lnkup_outreach_sent_hour_' . wp_date( 'Y-m-d-H' ) );
LNKup_Outreach::process_batch( 50 );
LNKup_Outreach::process_batch( 50 );
lnkup_t_same( 2, count( $sent_mail ), 'per-campaign daily cap stops at 2 sends' );
LNKup_Outreach::update_campaign( (int) $camp['campaign']['id'], array( 'daily_cap' => $cap_before ) );
LNKup_Outreach::process_batch( 50 );
lnkup_t_same( 3, count( $sent_mail ), 'remaining recipient sent after cap is raised' );
remove_filter( 'pre_wp_mail', $capture, 10 );

$mail    = $sent_mail[0];
$headers = implode( "\n", (array) $mail['headers'] );
lnkup_t_assert( false !== strpos( $headers, 'List-Unsubscribe: <' ) && false !== strpos( $headers, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' ), 'List-Unsubscribe headers present' );
lnkup_t_assert( false !== strpos( (string) $mail['message'], '1 Main St' ), 'postal address in footer' );
lnkup_t_assert( false !== strpos( (string) $mail['message'], 'lnkup_org_unsub=' ), 'unsubscribe link in body' );
lnkup_t_assert( false !== strpos( (string) $mail['message'], 'outreach=' ), 'tracked signup link in body' );
lnkup_t_assert( false === strpos( (string) $mail['message'], '{{' ), 'no unfilled merge fields' );
$alpha = LNKup_Org_Leads::get_row( $fresh[0] );
lnkup_t_same( 'contacted', $alpha['stage'], 'sent email moves lead to Contacted' );
lnkup_t_assert( (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $t( LNKup_Org_Growth_Tables::ACTIVITY ) . " WHERE lead_id = %d AND type = 'contact' AND channel = 'email' AND outcome = 'sent'", $fresh[0] ) ), 'email send recorded in history' ); // phpcs:ignore

// Unsubscribe.
$rec_table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
$rec_id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$rec_table} WHERE campaign_id = %d AND lead_id = %d", (int) $camp['campaign']['id'], $fresh[1] ) ); // phpcs:ignore
lnkup_t_assert( ! LNKup_Org_Outreach::apply_unsubscribe( 'r' . $rec_id . '.forged' )['ok'], 'forged unsubscribe token rejected' );
$unsub = LNKup_Org_Outreach::apply_unsubscribe( LNKup_Org_Outreach::unsubscribe_token( $rec_id ) );
lnkup_t_assert( $unsub['ok'], 'signed unsubscribe link works' );
lnkup_t_assert( LNKup_Org_Outreach::apply_unsubscribe( LNKup_Org_Outreach::unsubscribe_token( $rec_id ) )['ok'], 'unsubscribe is idempotent' );
lnkup_t_same( 'unsubscribe', LNKup_Contact_Suppression::check_email( $emails[1] ), 'address is suppressed' );
lnkup_t_same( 'unsubscribe', LNKup_Contact_Suppression::check_email( strtoupper( $emails[1] ) ), 'suppression ignores case' );
$hash_only = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Contact_Suppression::table() . ' WHERE value_hash = %s', hash( 'sha256', $emails[1] ) ) ); // phpcs:ignore
lnkup_t_same( 1, $hash_only, 'suppression stores only the hash' );
$wpdb->update( LNKup_Org_Leads::table(), array( 'last_contacted_at' => null ), array( 'id' => $fresh[1] ) );
$camp2 = LNKup_Org_Outreach::create_campaign( array( 'name' => $prefix . ' again', 'template_key' => 'first_intro', 'lead_ids' => array( $fresh[1] ) ), $admin );
$campaign_ids[] = (int) $camp2['campaign']['id'];
lnkup_t_same( 0, $camp2['eligible'], 'unsubscribed lead is not eligible for a new campaign' );
lnkup_t_same( 1, $camp2['skipped']['suppressed_unsubscribe'] ?? 0, 'skip reason recorded as unsubscribe' );
$removed = LNKup_Contact_Suppression::remove_email( $emails[1], $admin );
lnkup_t_assert( is_wp_error( $removed ), 'admins cannot remove an unsubscribe' );
$manual = LNKup_Org_Leads::record_contact( $fresh[1], array( 'channel' => 'email', 'summary' => 'Sent manually' ), $admin );
lnkup_t_assert( is_wp_error( $manual ) && 'lnkup_lead_suppressed' === $manual->get_error_code(), 'manual email to a suppressed contact is refused' );
$dnc = LNKup_Org_Leads::move( $fresh[2], '', 'do_not_contact', $admin );
lnkup_t_assert( LNKup_Contact_Suppression::is_lead_suppressed( LNKup_Org_Leads::get_row( $fresh[2] ) ), 'Do Not Contact suppresses the lead' );
lnkup_t_same( 'do_not_contact', LNKup_Contact_Suppression::check_email( $emails[2] ), 'Do Not Contact suppresses the email' );

// Missing facts → fallback, never invented copy.
$render = LNKup_Org_Outreach::render( 'active_applications', LNKup_Org_Leads::get_row( $fresh[0] ), array( 'signup_url' => 'https://x.test' ) );
lnkup_t_same( 'first_intro', $render['template_key'] ?? '', 'template needing an unknown event falls back to the simple intro' );

// --------------------------------------------------------------- 7–8. Attributed registration links the account.
echo "\n[7] attributed registration\n";
$alpha_rec = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$rec_table} WHERE campaign_id = %d AND lead_id = %d", (int) $camp['campaign']['id'], $fresh[0] ), ARRAY_A ); // phpcs:ignore
$_POST['lnkup_outreach_token'] = $alpha_rec['token'];
$org_user = wp_insert_user( array( 'user_login' => 'ogt_org_' . $run, 'user_email' => "founder-{$run}@personal.test", 'user_pass' => wp_generate_password(), 'role' => 'lnkup_organizer' ) );
unset( $_POST['lnkup_outreach_token'] );
$GLOBALS['lnkup_test_cleanup'][] = array( 'user', (int) $org_user );
lnkup_t_same( $fresh[0], LNKup_Org_Leads::lead_for_user( (int) $org_user ), 'signup through the outreach link attaches to the lead (different email)' );
lnkup_t_same( 'outreach_token', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT link_method FROM ' . $t( LNKup_Org_Growth_Tables::ACCOUNTS ) . ' WHERE user_id = %d', (int) $org_user ) ), 'link method recorded as outreach token' ); // phpcs:ignore
lnkup_t_same( 'registered', LNKup_Org_Leads::get_row( $fresh[0] )['stage'], 'lead moves to Registered' );
lnkup_t_assert( LNKup_Org_Lead_Lifecycle::has( $fresh[0], 'registered' ), 'registered milestone recorded' );
lnkup_t_same( 'registered', (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$rec_table} WHERE id = %d", (int) $alpha_rec['id'] ) ), 'campaign recipient marked registered' ); // phpcs:ignore
$other = LNKup_Org_Lead_Lifecycle::link( $fresh[1], (int) $org_user, 'manual', $admin );
lnkup_t_assert( is_wp_error( $other ) && 'lnkup_account_linked_elsewhere' === $other->get_error_code(), 'an account cannot belong to two organizations' );
$vendor = lnkup_t_user( 'lnkup_vendor' );
lnkup_t_assert( is_wp_error( LNKup_Org_Lead_Lifecycle::link( $fresh[1], $vendor, 'manual', $admin ) ), 'vendor accounts cannot be linked to organizer leads' );

// Domain match is a suggestion, not a link.
$corp_lead = LNKup_Org_Leads::create( array( 'name' => "{$prefix} Corp Events", 'email' => "events@corp-{$run}.test" ), array( 'actor' => $admin ) );
$lead_ids[] = (int) $corp_lead;
$corp_user  = wp_insert_user( array( 'user_login' => 'ogt_corp_' . $run, 'user_email' => "jane@corp-{$run}.test", 'user_pass' => wp_generate_password(), 'role' => 'lnkup_organizer' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'user', (int) $corp_user );
lnkup_t_same( 0, LNKup_Org_Leads::lead_for_user( (int) $corp_user ), 'same business domain does not auto-link' );
lnkup_t_assert( (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $t( LNKup_Org_Growth_Tables::ACTIVITY ) . " WHERE lead_id = %d AND type = 'account_suggested'", (int) $corp_lead ) ), 'domain match recorded as a suggestion' ); // phpcs:ignore

// --------------------------------------------------------------- 10–14. Milestones from real lifecycle activity.
echo "\n[10-14] milestones\n";
$event_a = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => $prefix . ' Spring Market', 'post_author' => (int) $org_user ) );
$posts[] = $event_a;
update_post_meta( $event_a, '_lnkup_organizer_user_id', (int) $org_user );
lnkup_t_assert( ! LNKup_Org_Lead_Lifecycle::has( $fresh[0], 'first_event_published' ), 'a draft event is not a published milestone' );
update_post_meta( $event_a, '_lnkup_event_status', 'live' );
lnkup_t_assert( LNKup_Org_Lead_Lifecycle::has( $fresh[0], 'first_event_published' ), 'event going live records First Event Published' );
lnkup_t_same( 'first_event_published', LNKup_Org_Leads::get_row( $fresh[0] )['stage'], 'stage advanced automatically' );
update_post_meta( $event_a, '_lnkup_event_status', 'draft' );
update_post_meta( $event_a, '_lnkup_event_status', 'live' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( LNKup_Org_Growth_Tables::MILESTONES ) . " WHERE lead_id = %d AND milestone = 'first_event_published'", $fresh[0] ) ), 'going live again does not duplicate the milestone' ); // phpcs:ignore

$app = wp_insert_post( array( 'post_type' => 'lnkup_application', 'post_status' => 'publish', 'post_title' => $prefix . ' app' ) );
$posts[] = $app;
update_post_meta( $app, '_lnkup_organizer_user_id', (int) $org_user );
update_post_meta( $app, '_lnkup_event_id', $event_a );
do_action( 'lnkup_application_submitted', $app );
lnkup_t_assert( LNKup_Org_Lead_Lifecycle::has( $fresh[0], 'first_application' ), 'vendor application records First Application' );

$payment = lnkup_t_payment( array( 'organizer_user_id' => (int) $org_user, 'vendor_user_id' => $vendor, 'event_id' => $event_a, 'total_amount' => '150.00', 'payment_status' => 'pending' ) );
do_action( 'lnkup_payment_completed', $payment );
lnkup_t_assert( ! LNKup_Org_Lead_Lifecycle::has( $fresh[0], 'first_paid_booking' ), 'an unpaid payment does not count as a paid booking' );
update_post_meta( $payment, '_lnkup_payment_status', 'paid' );
LNKup_Platform_Fees::record( $payment, array( 'gross_amount' => 150, 'lnkup_platform_fee' => 14.99, 'organizer_net' => 135.01 ) );
do_action( 'lnkup_payment_completed', $payment );
do_action( 'lnkup_payment_completed', $payment );
lnkup_t_assert( LNKup_Org_Lead_Lifecycle::has( $fresh[0], 'first_paid_booking' ), 'verified paid booking records First Paid Booking' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( LNKup_Org_Growth_Tables::MILESTONES ) . " WHERE lead_id = %d AND milestone = 'first_paid_booking'", $fresh[0] ) ), 'webhook retries do not duplicate the milestone' ); // phpcs:ignore
lnkup_t_assert( LNKup_Org_Lead_Lifecycle::has( $fresh[0], 'activated' ), 'activation threshold (1 paid booking in this test) reached' );
lnkup_t_same( 'activated', LNKup_Org_Leads::get_row( $fresh[0] )['stage'], 'stage is Activated' );
$bad = LNKup_Org_Leads::move( $fresh[0], 'qualified', '', $admin );
lnkup_t_assert( is_wp_error( $bad ) && 'lnkup_stage_backwards' === $bad->get_error_code(), 'verified stage cannot be moved backwards by hand' );

// 17–18. Second event → repeat organizer; still one organization.
$event_b = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => $prefix . ' Fall Market', 'post_author' => (int) $org_user ) );
$posts[] = $event_b;
update_post_meta( $event_b, '_lnkup_organizer_user_id', (int) $org_user );
update_post_meta( $event_b, '_lnkup_event_status', 'live' );
lnkup_t_assert( LNKup_Org_Lead_Lifecycle::has( $fresh[0], 'repeat_organizer' ), 'second live event records Repeat Organizer' );
$overview = LNKup_Org_Growth_Metrics::overview();
$mine     = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT lead_id) FROM ' . $t( LNKup_Org_Growth_Tables::MILESTONES ) . " WHERE milestone = 'first_event_published' AND lead_id IN (" . implode( ',', array_map( 'intval', $lead_ids ) ) . ')' ); // phpcs:ignore
lnkup_t_same( 1, $mine, 'two events from one organizer count as one published organization' );
lnkup_t_assert( $overview['kpis']['net_platform_fees'] >= 14.99, 'overview reports net platform fees from real fee rows' );
lnkup_t_assert( isset( $overview['unavailable']['cost_per_acquired_organizer'] ), 'acquisition cost reported as unavailable (no spend records)' );

// House affiliation.
LNKup_Org_Growth::update_settings( array( 'org_growth_house_organizer_ids' => (string) $org_user ) );
lnkup_t_assert( (bool) (int) LNKup_Org_Leads::get_row( $fresh[0] )['is_house_affiliated'], 'house organizer IDs flag the organization' );
$house = LNKup_Org_Growth_Metrics::overview( array( 'affiliation' => 'house' ) );
lnkup_t_assert( $house['kpis']['published'] >= 1, 'house-affiliated organizers reported separately' );
LNKup_Org_Growth::update_settings( array( 'org_growth_house_organizer_ids' => '' ) );

// 16. Full refund voids the paid milestone and the earned fee.
echo "\n[16] refund\n";
update_post_meta( $payment, '_lnkup_refund_amount', 150 );
LNKup_Refunds::sync_payment_refund_status( $payment );
LNKup_Refunds::sync_payment_refund_status( $payment );
lnkup_t_same( 'voided', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . $t( LNKup_Org_Growth_Tables::MILESTONES ) . " WHERE lead_id = %d AND milestone = 'first_paid_booking'", $fresh[0] ) ), 'full refund voids First Paid Booking' ); // phpcs:ignore
lnkup_t_same( 0.0, LNKup_Platform_Fees::net_fee_for_payment( $payment ), 'refunded booking earns no net platform fee' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( LNKup_Org_Growth_Tables::ACTIVITY ) . " WHERE lead_id = %d AND type = 'milestone_voided' AND summary LIKE %s", $fresh[0], '%first_paid_booking%' ) ), 'repeat refund sync voids only once' ); // phpcs:ignore

// --------------------------------------------------------------- 19. Access control.
echo "\n[19] access\n";
$organizer2 = lnkup_t_user( 'lnkup_organizer' );
$connector_user = lnkup_t_user( 'lnkup_connector' );
foreach ( array( 'organizer' => $organizer2, 'connector' => $connector_user, 'vendor' => $vendor, 'anonymous' => 0 ) as $who => $uid ) {
	wp_set_current_user( $uid );
	$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/growth/leads' ) );
	lnkup_t_assert( in_array( $res->get_status(), array( 401, 403 ), true ), "{$who} cannot list leads ({$res->get_status()})" );
	$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/growth/leads/' . $fresh[0] ) );
	lnkup_t_assert( in_array( $res->get_status(), array( 401, 403 ), true ), "{$who} cannot read a lead ({$res->get_status()})" );
}
wp_set_current_user( $admin );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/growth/leads' ) );
lnkup_t_same( 200, $res->get_status(), 'admin lists leads over REST' );
$req = new WP_REST_Request( 'POST', '/lnkup/v1/growth/leads/' . $fresh[0] . '/stage' );
$req->set_body_params( array( 'stage' => 'first_paid_booking' ) );
$res = rest_do_request( $req );
lnkup_t_same( 409, $res->get_status(), 'REST refuses milestone-only stage with 409 JSON' );
wp_set_current_user( 0 );
$req = new WP_REST_Request( 'POST', '/lnkup/v1/growth/unsubscribe' );
$req->set_param( 'token', 'garbage' );
lnkup_t_same( 400, rest_do_request( $req )->get_status(), 'public unsubscribe rejects a bad token' );
wp_set_current_user( $admin );
LNKup_Org_Growth::update_settings( array( 'organizer_growth_enabled' => false ) );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/growth/leads' ) );
lnkup_t_same( 403, $res->get_status(), 'feature flag off blocks the CRM' );
lnkup_t_same( 200, rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/growth/bootstrap' ) )->get_status(), 'settings stay reachable with the flag off' );

// --------------------------------------------------------------- Cleanup.
$lead_ids = array_values( array_unique( array_merge( $lead_ids, array( (int) $similar ) ) ) );
$in       = implode( ',', array_map( 'intval', $lead_ids ) );
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( array( LNKup_Org_Growth_Tables::ACTIVITY, LNKup_Org_Growth_Tables::TASKS, LNKup_Org_Growth_Tables::TAGS, LNKup_Org_Growth_Tables::LEAD_EVENTS, LNKup_Org_Growth_Tables::ACCOUNTS, LNKup_Org_Growth_Tables::MILESTONES ) as $suffix ) {
	$wpdb->query( 'DELETE FROM ' . $t( $suffix ) . " WHERE lead_id IN ({$in})" );
}
$wpdb->query( 'DELETE FROM ' . LNKup_Org_Leads::table() . " WHERE id IN ({$in}) OR name LIKE '" . esc_sql( $prefix ) . "%'" );
foreach ( $import_ids as $iid ) {
	$wpdb->delete( LNKup_Org_Lead_Import::rows_table(), array( 'import_id' => $iid ) );
	$wpdb->delete( LNKup_Org_Lead_Import::imports_table(), array( 'id' => $iid ) );
}
foreach ( $campaign_ids as $cid ) {
	$wpdb->delete( $rec_table, array( 'campaign_id' => $cid ) );
	$wpdb->delete( LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::CAMPAIGNS ), array( 'id' => $cid ) );
}
$wpdb->query( 'DELETE FROM ' . LNKup_Contact_Suppression::table() . " WHERE lead_id IN ({$in})" );
$wpdb->delete( LNKup_Connector_Tables::connectors_table(), array( 'id' => $connector_id ) );
$wpdb->delete( LNKup_Treasury_Tables::platform_fees_table(), array( 'payment_post_id' => $payment ) );
// phpcs:enable
foreach ( $posts as $pid ) {
	wp_delete_post( $pid, true );
}
if ( null === $saved ) {
	delete_option( LNKup_Org_Growth::SETTINGS_KEY );
} else {
	update_option( LNKup_Org_Growth::SETTINGS_KEY, $saved, false );
}
@unlink( $file ); // phpcs:ignore
LNKup_Org_Growth_Metrics::flush_cache();

lnkup_t_finish();
