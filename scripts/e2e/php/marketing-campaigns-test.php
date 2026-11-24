<?php
/**
 * Phase 5: marketing role, suppression bridge, saved segments, new audience filters, campaign
 * approval workflow + scheduling, exclusions, experiments, REST permissions.
 *
 * Mail is captured and push is stubbed by _comms_helpers.php; every audience is limited to
 * fixture vendors in a fixture market, so no real user can be selected.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/marketing-campaigns-test.php
 */

require __DIR__ . '/_comms_helpers.php';
require_once __DIR__ . '/_marketing_helpers.php';
global $wpdb;
lnkup_ct_all_flows();
lnkup_m_flags( array( 'campaign_scheduling' ) );

$markets_before = get_option( 'lnkup_city_markets', array() );
$markets        = LNKup_Market_Pages::get_all_markets();
$markets['mos-campville'] = array( 'slug' => 'mos-campville', 'city' => 'Moscampville', 'state' => 'NC', 'display_name' => 'Moscampville', 'launch_status' => 'prelaunch', 'enabled' => true, 'indexable' => true );
LNKup_Market_Pages::save_markets( $markets );
$campaign_ids = array();
$segment_ids  = array();

echo "Marketing role\n";
LNKup_Marketing_Role::maybe_install();
$role = get_role( 'lnkup_marketing' );
lnkup_t_assert( $role && array( 'read' => true, 'lnkup_manage_marketing' => true ) === $role->capabilities, 'lnkup_marketing role has only read + lnkup_manage_marketing' );
$mkt  = lnkup_ct_user( 'lnkup_marketing' );
$mkt2 = lnkup_ct_user( 'lnkup_marketing' );
foreach ( array( 'manage_options', 'lnkup_manage_all', 'edit_users', 'list_users', 'lnkup_manage_settings', 'edit_posts', 'upload_files' ) as $cap ) {
	if ( user_can( $mkt, $cap ) ) {
		lnkup_t_assert( false, "marketing staff lack {$cap}" );
	}
}
lnkup_t_assert( true, 'marketing staff have no financial, document, user or settings capabilities' );
lnkup_t_assert( user_can( lnkup_ct_user( 'administrator' ), 'lnkup_manage_marketing' ), 'administrators can manage marketing' );
lnkup_t_assert( LNKup_Meta_Config::is_excluded_user( $mkt ), 'marketing staff excluded from ad measurement' );

echo "Suppression bridge\n";
$unsub   = 'mos-unsub-' . wp_generate_password( 5, false ) . '@example.test';
$bounced = 'mos-bounce-' . wp_generate_password( 5, false ) . '@example.test';
$dnc     = 'someone@mos-dnc-' . strtolower( wp_generate_password( 5, false ) ) . '.test';
$wpdb->insert( LNKup_Contact_Suppression::table(), array( 'scope' => 'email', 'value_hash' => LNKup_Contact_Suppression::hash( $unsub ), 'reason' => 'unsubscribe', 'source' => 'mos_test', 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
$wpdb->insert( LNKup_Contact_Suppression::table(), array( 'scope' => 'email', 'value_hash' => LNKup_Contact_Suppression::hash( $bounced ), 'reason' => 'bounce', 'source' => 'mos_test', 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
$wpdb->insert( LNKup_Contact_Suppression::table(), array( 'scope' => 'domain', 'value_hash' => LNKup_Contact_Suppression::hash( substr( $dnc, strpos( $dnc, '@' ) + 1 ) ), 'reason' => 'do_not_contact', 'source' => 'mos_test', 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
lnkup_t_same( 'suppressed_outreach_unsubscribe', LNKup_Comms_Suppression::check_email( $unsub, 'product_news', true ), 'outreach unsubscribe blocks promotional comms' );
lnkup_t_same( '', LNKup_Comms_Suppression::check_email( $unsub, 'account', false ), 'outreach unsubscribe does not block transactional messages' );
lnkup_t_same( 'suppressed_outreach_bounce', LNKup_Comms_Suppression::check_email( $bounced, 'account', false ), 'outreach bounce blocks every message' );
lnkup_t_same( 'suppressed_outreach_do_not_contact', LNKup_Comms_Suppression::check_email( $dnc, 'product_news', true ), 'domain do-not-contact blocks promotional comms' );
lnkup_t_assert( '' !== LNKup_Contact_Suppression::check_email( $unsub ), 'outreach senders still see their own list' );
$wpdb->query( "DELETE FROM " . LNKup_Contact_Suppression::table() . " WHERE source = 'mos_test'" ); // phpcs:ignore

echo "Audience filters\n";
$vendors = array();
for ( $i = 0; $i < 4; $i++ ) {
	$v = lnkup_ct_user( 'lnkup_vendor' );
	lnkup_ct_profile( $v, 'Moscampville', 'NC' );
	$vendors[] = $v;
}
$outsider = lnkup_ct_user( 'lnkup_vendor' );
lnkup_ct_profile( $outsider, 'Elsewhere', 'NC' );
$base = array( 'role' => 'vendor', 'market' => 'mos-campville' );
$in   = array_values( array_filter( array_merge( $vendors, array( $outsider ) ), static fn( $u ) => LNKup_Comms_Audiences::matches( $u, LNKup_Comms_Audiences::clean_filters( $base ) ) ) );
lnkup_t_same( $vendors, $in, 'market filter resolves to the market\'s city' );
lnkup_t_assert( ! LNKup_Comms_Audiences::matches( $vendors[0], LNKup_Comms_Audiences::clean_filters( array( 'role' => 'vendor', 'market' => 'no-such-market' ) ) ), 'unknown market matches nobody' );
LNKup_Vendor_Personalization::toggle_favorite( $vendors[0], 'opportunities', 12345 );
lnkup_t_assert( LNKup_Comms_Audiences::matches( $vendors[0], LNKup_Comms_Audiences::clean_filters( $base + array( 'saved_opportunity' => 1 ) ) ) && ! LNKup_Comms_Audiences::matches( $vendors[1], LNKup_Comms_Audiences::clean_filters( $base + array( 'saved_opportunity' => 1 ) ) ), 'saved-an-opportunity filter' );
$tour = wp_insert_post( array( 'post_type' => 'lnkup_tour', 'post_status' => 'publish', 'post_title' => 'MOS Camp Tour' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $tour );
LNKup_Vendor_Personalization::toggle_favorite( $vendors[1], 'tours', (int) $tour );
lnkup_t_assert( LNKup_Comms_Audiences::matches( $vendors[1], LNKup_Comms_Audiences::clean_filters( $base + array( 'followed_tour' => $tour ) ) ) && ! LNKup_Comms_Audiences::matches( $vendors[2], LNKup_Comms_Audiences::clean_filters( $base + array( 'followed_tour' => $tour ) ) ), 'followed-tour filter' );
$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-120 days' ) ) ), array( 'ID' => $vendors[2] ) ); // phpcs:ignore
clean_user_cache( $vendors[2] );
update_user_meta( $vendors[3], '_lnkup_last_login_at', time() - DAY_IN_SECONDS );
$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-120 days' ) ) ), array( 'ID' => $vendors[3] ) ); // phpcs:ignore
clean_user_cache( $vendors[3] );
$inactive = LNKup_Comms_Audiences::clean_filters( $base + array( 'inactive_days' => 60 ) );
lnkup_t_assert( LNKup_Comms_Audiences::matches( $vendors[2], $inactive ) && ! LNKup_Comms_Audiences::matches( $vendors[3], $inactive ), 'inactive-N-days uses last login / activity, not just signup date' );
lnkup_t_assert( LNKup_Comms_Audiences::matches( $vendors[0], LNKup_Comms_Audiences::clean_filters( $base + array( 'verified' => 'no' ) ) ), 'unverified vendor matches verified=no' );

echo "Saved segments\n";
$seg = LNKup_Comms_Segments::save( array( 'name' => 'MOS Campville vendors', 'rules' => $base + array( 'user_ids' => array( 1, 2, 3 ), 'evil' => '<script>' ) ), $mkt );
$segment_ids[] = (int) $seg;
$saved = LNKup_Comms_Segments::get( (int) $seg );
lnkup_t_assert( $saved && ! isset( $saved['rules']['user_ids'] ) && ! isset( $saved['rules']['evil'] ) && 'mos-campville' === $saved['rules']['market'], 'segment stores sanitized rules, never user lists' );
lnkup_t_assert( is_wp_error( LNKup_Comms_Segments::save( array( 'name' => '' ), $mkt ) ), 'segment needs a name' );

echo "REST permissions\n";
wp_set_current_user( $vendors[0] );
lnkup_t_assert( in_array( rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/comms/marketing/campaigns' ) )->get_status(), array( 401, 403 ), true ), 'vendors cannot reach marketing campaigns' );
wp_set_current_user( $mkt );
lnkup_t_same( 200, rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/comms/marketing/campaigns' ) )->get_status(), 'marketing staff list campaigns' );
$pv = new WP_REST_Request( 'POST', '/lnkup/v1/comms/marketing/segments/preview' );
$pv->set_param( 'rules', $base );
$preview = rest_do_request( $pv )->get_data();
lnkup_t_assert( 4 === (int) $preview['recipients'] && ! isset( $preview['sample'] ), 'segment preview returns counts only (no names)' );

echo "Approval workflow\n";
$create = new WP_REST_Request( 'POST', '/lnkup/v1/comms/marketing/campaigns' );
foreach ( array( 'name' => 'MOS Campville launch', 'segment_id' => (int) $seg, 'category' => 'opportunities', 'channels' => array( 'inbox', 'email' ), 'content' => array( 'subject' => 'Base subject', 'body' => 'Base body' ), 'meta' => array( 'objective' => 'applications', 'market' => 'mos-campville', 'budget' => '25.5', 'conversion_goal' => 'SubmitApplication', 'experiment' => array( 'metric' => 'click', 'min_sample' => 5, 'variants' => array( array( 'subject' => 'Variant B subject', 'body' => 'Variant B body' ) ) ) ) ) as $k => $v ) {
	$create->set_param( $k, $v );
}
$res = rest_do_request( $create );
lnkup_t_same( 200, $res->get_status(), 'marketing staff create a draft campaign' );
$camp = $res->get_data()['campaign'];
$id   = (int) $camp['id'];
$campaign_ids[] = $id;
lnkup_t_assert( 'draft' === $camp['status'] && 'applications' === $camp['meta']['objective'] && 25.5 === (float) $camp['meta']['budget'] && 100 === $camp['meta']['experiment']['min_sample'], 'metadata saved; experiment minimum sample enforced (>=100)' );
lnkup_t_same( 'lnkup_comms_needs_approval', LNKup_Comms_Campaigns::start( $id, 4 )->get_error_code(), 'draft cannot start without approval' );
lnkup_t_same( true, LNKup_Comms_Campaign_Workflow::submit( $id, $mkt ), 'creator submits for approval' );
lnkup_t_same( 'lnkup_comms_two_person', LNKup_Comms_Campaign_Workflow::approve( $id, $mkt )->get_error_code(), 'creator cannot approve their own campaign' );
lnkup_t_same( 'lnkup_comms_forbidden', LNKup_Comms_Campaign_Workflow::approve( $id, $vendors[0] )->get_error_code(), 'users without lnkup_manage_marketing cannot approve' );
$approved = LNKup_Comms_Campaign_Workflow::approve( $id, $mkt2, time() + DAY_IN_SECONDS );
lnkup_t_assert( is_array( $approved ) && 'scheduled' === $approved['status'] && 4 === $approved['recipients'], 'second team member approves and schedules (4 recipients)' );
lnkup_t_same( 4, (int) LNKup_Comms_Campaigns::get( $id )['approved_count'], 'approved audience size recorded' );
for ( $i = 0; $i < 2; $i++ ) {
	$extra = lnkup_ct_user( 'lnkup_vendor' );
	lnkup_ct_profile( $extra, 'Moscampville', 'NC' );
	$vendors[] = $extra;
}
LNKup_Comms_Campaign_Workflow::run_scheduled( $id );
$row = LNKup_Comms_Campaigns::get( $id );
lnkup_t_assert( 'pending_approval' === $row['status'] && false !== strpos( LNKup_Comms_Campaign_Workflow::meta( $row )['review_note'], 'grew' ), 'audience grew >10% after approval -> back to approval' );
lnkup_t_same( true, LNKup_Comms_Campaign_Workflow::reject( $id, $mkt2, 'Tighten the copy' ), 'approver can send it back' );
lnkup_t_same( 'draft', LNKup_Comms_Campaigns::get( $id )['status'], 'rejected campaign returns to draft' );
LNKup_Comms_Campaign_Workflow::submit( $id, $mkt );
$go = LNKup_Comms_Campaign_Workflow::approve( $id, $mkt2 );
lnkup_t_assert( is_array( $go ) && 'running' === $go['status'], 'approve without a date starts sending' );

echo "Sending, exclusions and experiments\n";
LNKup_Comms_Campaigns::process_batch( $id );
$row  = LNKup_Comms_Campaigns::get( $id );
$msgs = $wpdb->get_results( $wpdb->prepare( 'SELECT recipient_user_id, context FROM ' . LNKup_Comms_Schema::table( 'messages' ) . ' WHERE campaign_id = %d', $id ), ARRAY_A ); // phpcs:ignore
$to   = array_map( 'intval', wp_list_pluck( $msgs, 'recipient_user_id' ) );
sort( $to );
$expect = $vendors;
sort( $expect );
lnkup_t_same( $expect, $to, 'only segment members (fixture market vendors) were messaged' );
lnkup_t_same( 'completed', $row['status'], 'campaign completed' );
$variants = array();
foreach ( $msgs as $m ) {
	$ctx                              = json_decode( (string) $m['context'], true );
	$variants[ (int) $m['recipient_user_id'] ] = (string) ( $ctx['_xv'] ?? '' );
}
$stable = true;
foreach ( $variants as $user => $key ) {
	$stable = $stable && LNKup_Comms_Campaign_Workflow::variant_for( $row, $user ) === $key;
}
lnkup_t_assert( $stable, 'variant assignment is stable per campaign and user' );
lnkup_t_same( 'Variant B subject', LNKup_Comms_Campaigns::variables( $id, 'b' )['campaign_subject'], 'variant copy rendered for variant recipients' );
lnkup_t_same( 'Base subject', LNKup_Comms_Campaigns::variables( $id, '' )['campaign_subject'], 'control keeps base copy' );
$results = LNKup_Comms_Campaign_Workflow::results( $id );
lnkup_t_same( 'insufficient_data', $results['verdict'], 'results say insufficient data below the minimum sample' );
lnkup_t_same( '', $results['leader'], 'no winner declared without enough data' );
lnkup_t_same( true, LNKup_Comms_Campaign_Workflow::archive( $id ), 'completed campaign archived' );

$second = LNKup_Comms_Campaign_Workflow::create( array( 'name' => 'MOS follow-up', 'segment_id' => (int) $seg, 'category' => 'opportunities', 'content' => array( 'subject' => 'Again', 'body' => 'Again' ), 'meta' => array( 'exclusions' => array( 'recent_campaign_days' => 7 ) ) ), $mkt );
$campaign_ids[] = (int) $second;
LNKup_Comms_Campaign_Workflow::submit( (int) $second, $mkt );
LNKup_Comms_Campaign_Workflow::approve( (int) $second, $mkt2 );
LNKup_Comms_Campaigns::process_batch( (int) $second );
$row2 = LNKup_Comms_Campaigns::get( (int) $second );
lnkup_t_assert( 0 === (int) $row2['total_queued'] && count( $vendors ) === (int) $row2['total_excluded'], 'recent-campaign exclusion skips everyone messaged in the last 7 days' );

$third = LNKup_Comms_Campaign_Workflow::create( array( 'name' => 'MOS fail', 'segment_id' => (int) $seg, 'content' => array( 'subject' => 'X', 'body' => 'X' ) ), $mkt );
$campaign_ids[] = (int) $third;
LNKup_Comms_Campaign_Workflow::submit( (int) $third, $mkt );
$wpdb->update( LNKup_Comms_Campaigns::table(), array( 'status' => 'running' ), array( 'id' => (int) $third ) ); // phpcs:ignore
$boom = static function () {
	throw new RuntimeException( 'simulated failure' );
};
add_action( 'pre_user_query', $boom );
LNKup_Comms_Campaigns::process_batch( (int) $third );
remove_action( 'pre_user_query', $boom );
lnkup_t_same( 'failed', LNKup_Comms_Campaigns::get( (int) $third )['status'], 'a crashing batch marks the campaign Failed' );

echo "Flag off keeps the old behaviour\n";
LNKup_Marketing_Flags::override( 'campaign_scheduling', false );
$legacy = LNKup_Comms_Campaigns::create_admin( array( 'name' => 'MOS legacy', 'filters' => $base, 'content' => array( 'subject' => 'L', 'body' => 'L' ) ), $mkt );
$campaign_ids[] = (int) $legacy;
lnkup_ct_settings( array( 'campaigns_enabled' => false ) );
$err = LNKup_Comms_Campaigns::start( (int) $legacy, 0 );
lnkup_t_assert( is_wp_error( $err ) && 'lnkup_comms_needs_approval' !== $err->get_error_code(), 'without the flag, drafts are not forced through approval' );

echo "\n";
wp_set_current_user( 0 );
foreach ( $campaign_ids as $cid ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'lnkup_comms_campaign_batch', array( $cid ) );
		as_unschedule_all_actions( 'lnkup_comms_campaign_scheduled', array( $cid ) );
	}
	$wpdb->delete( LNKup_Comms_Campaigns::table(), array( 'id' => $cid ) );
}
foreach ( $segment_ids as $sid ) {
	$wpdb->delete( LNKup_Comms_Segments::table(), array( 'id' => $sid ) );
}
update_option( 'lnkup_city_markets', $markets_before );
lnkup_ct_finish();
