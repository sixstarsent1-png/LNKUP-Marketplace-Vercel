<?php
/**
 * Phase 6: external demand → organizer leads, claim notifications + funnel, acceptance share
 * card, connector Share & Track, partner landing pages.
 *
 * Mail is captured and push stubbed (_comms_helpers.php). All fixtures are removed.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/distribution-test.php
 */

require __DIR__ . '/_comms_helpers.php';
require_once __DIR__ . '/_marketing_helpers.php';
global $wpdb;
lnkup_ct_all_flows();
lnkup_m_flags( array( 'tracking_links', 'seo_index_policy' ) );
$run          = strtolower( wp_generate_password( 6, false ) );
$growth_saved = get_option( LNKup_Org_Growth::SETTINGS_KEY, null );
LNKup_Org_Growth::update_settings( array( 'organizer_growth_enabled' => true ) );
$ext_table = LNKup_External_Events_Tables::events_table();
$int_table = LNKup_External_Events_Tables::interest_table();
$ext_ids   = array();
$lead_ids  = array();
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $wpdb, &$ext_ids, &$lead_ids, $ext_table, $int_table, $growth_saved ) {
	foreach ( $ext_ids as $id ) {
		$wpdb->delete( $ext_table, array( 'id' => $id ) );
		$wpdb->delete( $int_table, array( 'external_event_id' => $id ) );
	}
	foreach ( array_unique( $lead_ids ) as $lead ) {
		$wpdb->delete( LNKup_Org_Leads::table(), array( 'id' => $lead ) );
		$wpdb->delete( LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACTIVITY ), array( 'lead_id' => $lead ) );
		$wpdb->delete( LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::TAGS ), array( 'lead_id' => $lead ) );
	}
	null === $growth_saved ? delete_option( LNKup_Org_Growth::SETTINGS_KEY ) : update_option( LNKup_Org_Growth::SETTINGS_KEY, $growth_saved );
};

/**
 * External event fixture.
 */
function lnkup_dt_external( string $run, string $email, string $suffix ): int {
	global $wpdb;
	$now = current_time( 'mysql' );
	$wpdb->insert( // phpcs:ignore
		LNKup_External_Events_Tables::events_table(),
		array( 'source_platform' => 'eventbrite', 'source_event_id' => 'mos' . $run . $suffix, 'source_url' => 'https://www.example.test/e/mos-' . $run . $suffix, 'title' => 'MOS Street Fair ' . $suffix, 'description_excerpt' => '', 'image_url' => '', 'start_datetime' => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ), 'city' => 'Charlotte', 'state' => 'NC', 'venue_name' => '', 'organizer_name' => 'MOS Fair Co ' . $suffix, 'organizer_url' => 'https://mosfair-' . $run . '.example.test', 'organizer_email' => $email, 'application_url' => '', 'organizer_instagram' => '', 'discovery_query' => '', 'event_type' => '', 'workflow_status' => 'published', 'claim_status' => 'unclaimed', 'vendor_interest_count' => 0, 'created_at' => $now, 'updated_at' => $now )
	);
	return (int) $wpdb->insert_id;
}

echo "External demand -> organizer leads\n";
$v1  = lnkup_ct_user( 'lnkup_vendor' );
$v2  = lnkup_ct_user( 'lnkup_vendor' );
$ext = lnkup_dt_external( $run, 'hello@mosfair-' . $run . '.example.test', 'a' );
$ext_ids[] = $ext;
foreach ( array( $v1, $v2 ) as $v ) {
	$wpdb->insert( $int_table, array( 'external_event_id' => $ext, 'vendor_user_id' => $v, 'vendor_profile_id' => 0, 'status' => 'submitted', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
	$wpdb->query( $wpdb->prepare( "UPDATE {$ext_table} SET vendor_interest_count = vendor_interest_count + 1 WHERE id = %d", $ext ) ); // phpcs:ignore
	LNKup_Marketplace_Analytics::track( 'external_event_interest_submitted', array( 'external_event_id' => $ext ), $v );
}
$lead = LNKup_Distribution::lead_for_external( $ext );
$lead_ids[] = $lead;
$row  = LNKup_Org_Leads::get_row( $lead );
lnkup_t_assert( $lead > 0 && 'external_interest' === $row['source'] && 'external_event:' . $ext === $row['source_detail'], 'vendor interest creates an organizer lead (source external_interest)' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Org_Leads::table() . ' WHERE source_detail = %s', 'external_event:' . $ext ) ), 'second request updates the same lead (no duplicate)' ); // phpcs:ignore
$acts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACTIVITY ) . " WHERE lead_id = %d AND type = 'external_interest'", $lead ) ); // phpcs:ignore
lnkup_t_same( 2, $acts, 'each vendor request logged as lead activity with the real demand count' );
$ext2 = lnkup_dt_external( $run, 'hello@mosfair-' . $run . '.example.test', 'b' );
$ext_ids[] = $ext2;
$lead2 = LNKup_Distribution::upsert_lead( $ext2 );
$lead_ids[] = $lead2;
lnkup_t_same( $lead, $lead2, 'same organizer email on another event links to the existing lead' );
LNKup_Org_Growth::update_settings( array( 'organizer_growth_enabled' => false ) );
lnkup_t_same( 0, LNKup_Distribution::upsert_lead( $ext ), 'no leads are written while the organizer growth module is off' );
LNKup_Org_Growth::update_settings( array( 'organizer_growth_enabled' => true ) );

echo "Claim notifications\n";
$org   = lnkup_ct_user( 'lnkup_organizer' );
$event = lnkup_m_event( array( 'organizer_user_id' => $org ) );
$wpdb->update( $ext_table, array( 'claim_status' => 'claimed', 'claimed_event_id' => $event['event'], 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $ext ) ); // phpcs:ignore
lnkup_t_same( 2, LNKup_Distribution::notify_claim( $ext, $event['event'] ), 'both interested vendors notified when the organizer claims' );
lnkup_t_same( 0, LNKup_Distribution::notify_claim( $ext, $event['event'] ), 'repeat claim processing sends nothing (dedupe)' );
lnkup_t_same( 0, LNKup_Distribution::sweep_claims(), 'daily sweep is idempotent' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $v1, 'v_external_event_claimed' ) ), 'vendor has exactly one claim message' );
$app = wp_insert_post( array( 'post_type' => 'lnkup_application', 'post_status' => 'publish', 'post_title' => 'MOS app' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $app );
foreach ( array( 'vendor_user_id' => $v1, 'event_id' => $event['event'], 'opportunity_id' => $event['opportunity'], 'application_status' => 'paid' ) as $k => $v ) {
	update_post_meta( $app, '_lnkup_' . $k, $v );
}
$funnel = LNKup_Distribution::claim_funnel( $ext );
lnkup_t_assert( 2 === $funnel['interested'] && 2 === $funnel['notified'] && 1 === $funnel['applied'] && 1 === $funnel['booked'], 'claim funnel: interested 2 → notified 2 → applied 1 → booked 1' );

echo "Acceptance share card\n";
$html = LNKup_Distribution::acceptance_card( (int) $app, $v1 );
lnkup_t_assert( false !== strpos( $html, 'lnkup-acceptance-share' ) && false === strpos( $html, (string) get_userdata( $v1 )->user_email ), 'paid booking shows the opt-in share card (no personal data)' );
lnkup_t_same( '', LNKup_Distribution::acceptance_card( (int) $app, $v2 ), 'another vendor never sees the card' );
wp_set_current_user( $v1 );
lnkup_t_same( '', LNKup_Distribution::acceptance_card_for_request( (int) $app ), 'card is not rendered in booking lists' );
$_GET['app'] = (string) $app;
lnkup_t_assert( '' !== LNKup_Distribution::acceptance_card_for_request( (int) $app ), 'card is rendered on that booking\'s detail page' );
unset( $_GET['app'] );
wp_set_current_user( 0 );
update_post_meta( $app, '_lnkup_application_status', 'submitted' );
lnkup_t_same( '', LNKup_Distribution::acceptance_card( (int) $app, $v1 ), 'pending applications are not shareable' );
update_post_meta( $app, '_lnkup_application_status', 'paid' );
wp_set_current_user( $v1 );
$req = new WP_REST_Request( 'POST', '/lnkup/v1/vendor/acceptance-link' );
$req->set_param( 'application_id', (int) $app );
$first  = rest_do_request( $req );
$second = rest_do_request( $req );
lnkup_t_assert( 200 === $first->get_status() && false !== strpos( (string) $first->get_data()['url'], '/l/' ), 'vendor gets a tracked link to the event' );
lnkup_t_same( $first->get_data()['url'], $second->get_data()['url'], 'the same link is reused' );
wp_set_current_user( $v2 );
lnkup_t_same( 403, rest_do_request( $req )->get_status(), 'other vendors cannot create links for this booking' );
wp_set_current_user( 0 );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $wpdb, $v1 ) {
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Marketing_Tables::links() . ' WHERE owner_user_id = %d', $v1 ) ) as $id ) { // phpcs:ignore
		$wpdb->delete( LNKup_Marketing_Tables::link_daily(), array( 'link_id' => (int) $id ) );
	}
	$wpdb->delete( LNKup_Marketing_Tables::links(), array( 'owner_user_id' => $v1 ) );
};

echo "Connector Share & Track\n";
$cuser = lnkup_ct_user( 'lnkup_connector' );
$code  = 'MOSC' . strtoupper( $run );
$wpdb->insert( LNKup_Connector_Tables::connectors_table(), array( 'user_id' => $cuser, 'display_name' => 'MOS Connector', 'email' => 'mosc@example.test', 'market_slug' => 'charlotte', 'city' => 'Charlotte', 'state' => 'NC', 'status' => 'active', 'program' => 'city_connector', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
$connector_id = (int) $wpdb->insert_id;
$wpdb->insert( LNKup_Treasury_Tables::referral_codes_table(), array( 'user_id' => $cuser, 'connector_id' => $connector_id, 'code' => $code, 'role_type' => 'connector', 'program' => 'city_connector', 'is_active' => 1, 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $wpdb, $connector_id, $cuser ) {
	$wpdb->delete( LNKup_Connector_Tables::connectors_table(), array( 'id' => $connector_id ) );
	$wpdb->delete( LNKup_Treasury_Tables::referral_codes_table(), array( 'connector_id' => $connector_id ) );
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Marketing_Tables::links() . ' WHERE owner_user_id = %d', $cuser ) ) as $id ) { // phpcs:ignore
		$wpdb->delete( LNKup_Marketing_Tables::link_daily(), array( 'link_id' => (int) $id ) );
	}
	$wpdb->delete( LNKup_Marketing_Tables::links(), array( 'owner_user_id' => $cuser ) );
};
lnkup_t_assert( isset( LNKup_Connector::dashboard_views( LNKup_Connector::get( $connector_id ) )['marketing'] ), 'Share & Track view listed for connectors while tracked links are on' );
wp_set_current_user( $cuser );
$ctx = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/connector/marketing/context' ) );
lnkup_t_assert( 200 === $ctx->get_status() && 'charlotte' === ( $ctx->get_data()['markets'][0]['slug'] ?? '' ), 'connector context lists their own market first' );
$mk = new WP_REST_Request( 'POST', '/lnkup/v1/connector/marketing/links' );
$mk->set_param( 'target_type', 'market' );
$mk->set_param( 'target_key', 'charlotte' );
$mk->set_param( 'channel', 'instagram' );
$made = rest_do_request( $mk );
lnkup_t_assert( 200 === $made->get_status() && false !== strpos( (string) $made->get_data()['link']['target_url'], 'ref=' . $code ), 'connector link forwards their referral code (?ref=) to the existing program' );
$bad = new WP_REST_Request( 'POST', '/lnkup/v1/connector/marketing/links' );
$bad->set_param( 'target_type', 'event' );
$bad->set_param( 'target_id', $event['event'] );
lnkup_t_same( 400, rest_do_request( $bad )->get_status(), 'connectors cannot link organizers\' events directly' );
wp_set_current_user( $v1 );
lnkup_t_assert( in_array( rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/connector/marketing/context' ) )->get_status(), array( 401, 403 ), true ), 'non-connectors cannot use connector tools' );
wp_set_current_user( 0 );

echo "Partner landing pages\n";
$partners = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::PARTNERS );
$wpdb->insert( $partners, array( 'name' => 'MOS Makers Guild ' . $run, 'partner_type' => 'association', 'stage' => 'active', 'agreement_status' => 'signed', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
$approved = (int) $wpdb->insert_id;
$wpdb->insert( $partners, array( 'name' => 'MOS Prospect ' . $run, 'partner_type' => 'association', 'stage' => 'prospect', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
$prospect = (int) $wpdb->insert_id;
$landings_before = get_option( LNKup_Partner_Landings::OPTION, null );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $wpdb, $partners, $approved, $prospect, $landings_before ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$partners} WHERE id IN (%d,%d)", $approved, $prospect ) ); // phpcs:ignore
	null === $landings_before ? delete_option( LNKup_Partner_Landings::OPTION ) : update_option( LNKup_Partner_Landings::OPTION, $landings_before );
};
lnkup_t_assert( is_wp_error( LNKup_Partner_Landings::save( array( 'partner_id' => $prospect, 'slug' => 'mos-prospect' ) ) ), 'no partner page without an approved partner record' );
lnkup_t_assert( is_wp_error( LNKup_Partner_Landings::save( array( 'partner_id' => 999999999, 'slug' => 'mos-ghost' ) ) ), 'no partner page for a missing partner' );
$page = LNKup_Partner_Landings::save( array( 'partner_id' => $approved, 'slug' => 'mos-makers-' . $run, 'market' => 'charlotte', 'category' => 'Food', 'headline' => '<b>Hi</b> makers', 'enabled' => 1 ) );
lnkup_t_assert( is_array( $page ) && 'Hi makers' === $page['headline'] && 'charlotte' === $page['market'], 'approved partner page saved with sanitized copy' );
lnkup_t_assert( is_wp_error( LNKup_Partner_Landings::save( array( 'partner_id' => $prospect, 'slug' => 'mos-makers-' . $run ) ) ), 'another partner cannot take the address' );
$wpdb->update( LNKup_Connector_Tables::connectors_table(), array( 'partner_id' => $approved ), array( 'id' => $connector_id ) ); // phpcs:ignore
$built = LNKup_Partner_Landings::build( $page, LNKup_Partner_Landings::approved_partner( $approved ) );
lnkup_t_assert( false !== strpos( (string) $built['signup_url'], 'ref=' . $code ), 'partner page sign-up carries the partner account\'s referral code' );
$wpdb->update( $partners, array( 'stage' => 'closed' ), array( 'id' => $approved ) ); // phpcs:ignore
lnkup_t_same( null, LNKup_Partner_Landings::approved_partner( $approved ), 'closing the partnership takes the page down' );

echo "\n";
lnkup_m_finish_comms();

/**
 * Marketing fixture cleanup, then the comms cleanup (which exits with the result).
 */
function lnkup_m_finish_comms(): void {
	global $wpdb;
	foreach ( $GLOBALS['lnkup_test_cleanup'] as $item ) {
		if ( 'post' === $item[0] && 'lnkup_opportunity' === get_post_type( (int) $item[1] ) ) {
			$wpdb->delete( LNKup_Marketplace_Index::table_name(), array( 'opportunity_id' => (int) $item[1] ) );
		}
	}
	foreach ( (array) ( $GLOBALS['lnkup_m_extra_cleanup'] ?? array() ) as $callback ) {
		call_user_func( $callback );
	}
	lnkup_ct_finish();
}
