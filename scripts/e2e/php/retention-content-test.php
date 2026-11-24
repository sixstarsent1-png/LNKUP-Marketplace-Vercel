<?php
/**
 * Phase 7: market alerts + tour stop messages (deduplicated), consent records, resources hub
 * (outline drafts, guide URLs, editorial checklist), organizer cohort interest.
 *
 * Mail is captured and push stubbed (_comms_helpers.php). All fixtures are removed.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/retention-content-test.php
 */

require __DIR__ . '/_comms_helpers.php';
require_once __DIR__ . '/_marketing_helpers.php';
global $wpdb;
lnkup_ct_all_flows();
lnkup_m_flags( array( 'resources_hub', 'tracking_links' ) );
$run            = strtolower( wp_generate_password( 6, false ) );
$markets_before = get_option( 'lnkup_city_markets', array() );
$growth_saved   = get_option( LNKup_Org_Growth::SETTINGS_KEY, null );
$seeded_before  = get_option( LNKup_Resources_Hub::SEEDED, null );
$cohorts_before = get_option( LNKup_Cohort_Landings::OPTION, null );
$lead_ids       = array();
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $wpdb, $markets_before, $growth_saved, $seeded_before, $cohorts_before, &$lead_ids ) {
	update_option( 'lnkup_city_markets', $markets_before );
	null === $growth_saved ? delete_option( LNKup_Org_Growth::SETTINGS_KEY ) : update_option( LNKup_Org_Growth::SETTINGS_KEY, $growth_saved );
	foreach ( (array) get_option( LNKup_Resources_Hub::SEEDED, array() ) as $id ) {
		if ( is_numeric( $id ) && ! in_array( (int) $id, (array) $seeded_before, true ) ) {
			wp_delete_post( (int) $id, true );
		}
	}
	null === $seeded_before ? delete_option( LNKup_Resources_Hub::SEEDED ) : update_option( LNKup_Resources_Hub::SEEDED, $seeded_before );
	null === $cohorts_before ? delete_option( LNKup_Cohort_Landings::OPTION ) : update_option( LNKup_Cohort_Landings::OPTION, $cohorts_before );
	foreach ( array_unique( $lead_ids ) as $lead ) {
		$wpdb->delete( LNKup_Org_Leads::table(), array( 'id' => $lead ) );
		$wpdb->delete( LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACTIVITY ), array( 'lead_id' => $lead ) );
		$wpdb->delete( LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::TAGS ), array( 'lead_id' => $lead ) );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_lnkup\_cohort\_rl\_%' OR option_name LIKE '\_transient\_timeout\_lnkup\_cohort\_rl\_%'" ); // phpcs:ignore
};
$markets                     = LNKup_Market_Pages::get_all_markets();
$markets['mos-alertville']   = array( 'slug' => 'mos-alertville', 'city' => 'Mosalertville', 'state' => 'NC', 'display_name' => 'Mosalertville', 'launch_status' => 'prelaunch', 'enabled' => true, 'indexable' => true );
LNKup_Market_Pages::save_markets( $markets );

/**
 * Publish an opportunity the way the dashboard does: draft with meta, then publish.
 */
function lnkup_rt_publish_opp( int $event_id, string $category_label ): int {
	$opp = wp_insert_post( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'draft', 'post_title' => $category_label . ' slot' ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $opp );
	foreach ( array( 'event_id' => $event_id, 'opportunity_status' => 'open', 'vendor_fee' => 50, 'spots_total' => 5, 'spots_remaining' => 5, 'application_deadline' => gmdate( 'Y-m-d', strtotime( '+10 days' ) ) ) as $k => $v ) {
		update_post_meta( $opp, '_lnkup_' . $k, $v );
	}
	foreach ( get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false ) ) as $term ) {
		if ( $category_label === lnkup_canonical_category_label( $term->name ) ) {
			wp_set_object_terms( $opp, array( (int) $term->term_id ), 'lnkup_vendor_category' );
			break;
		}
	}
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	wp_publish_post( $opp );
	return (int) $opp;
}

echo "Market alerts\n";
$vendor = lnkup_ct_user( 'lnkup_vendor' );
lnkup_ct_profile( $vendor, 'Mosalertville', 'NC' );
$a1 = LNKup_Market_Registry::create_alert( $vendor, 'mos-alertville', 'food' );
$a2 = LNKup_Market_Registry::create_alert( $vendor, 'mos-alertville' );
lnkup_t_assert( ! empty( $a1['created'] ) && ! empty( $a2['created'] ), 'two market alerts (Food + everything) created' );
lnkup_t_same( 2, count( (array) get_user_meta( $vendor, LNKup_Lifecycle::ALERTS_META, true ) ), 'both registered as market alerts' );
$event = lnkup_m_event( array( 'city' => 'Mosalertville', 'state' => 'NC' ), false );
$opp   = lnkup_rt_publish_opp( $event['event'], 'Food' );
$msgs  = lnkup_ct_messages_for( $vendor, 'v_market_alert' );
lnkup_t_same( 1, count( $msgs ), 'one message for a new matching opportunity, even with two matching alerts' );
lnkup_t_same( $opp, (int) ( $msgs[0]['opportunity_id'] ?? 0 ), 'message points at the new opportunity' );
lnkup_t_assert( LNKup_Saved_Searches::instant_alert_sent_today( $vendor, (string) $a1['id'], $opp ) && LNKup_Saved_Searches::instant_alert_sent_today( $vendor, (string) $a2['id'], $opp ), 'legacy instant alerts marked sent (no duplicate email)' );
LNKup_Lifecycle::on_opportunity_published( $opp );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $vendor, 'v_market_alert' ) ), 're-running publish does not send again' );
$elsewhere = lnkup_m_event( array( 'city' => 'Elsewhereville', 'state' => 'NC' ), false );
lnkup_rt_publish_opp( $elsewhere['event'], 'Food' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $vendor, 'v_market_alert' ) ), 'opportunities in other cities do not alert' );
$retail = lnkup_rt_publish_opp( $event['event'], 'Retail' );
lnkup_t_same( 2, count( lnkup_ct_messages_for( $vendor, 'v_market_alert' ) ), 'the "everything" alert catches other categories' );
$applied = wp_insert_post( array( 'post_type' => 'lnkup_application', 'post_status' => 'publish', 'post_title' => 'MOS applied' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $applied );
LNKup_Saved_Searches::update( $vendor, (string) $a2['id'], array( 'status' => 'paused' ) );
$paused_opp = lnkup_rt_publish_opp( $event['event'], 'Retail' );
lnkup_t_same( 2, count( lnkup_ct_messages_for( $vendor, 'v_market_alert' ) ), 'paused alerts send nothing' );

echo "Followed tours\n";
$follower = lnkup_ct_user( 'lnkup_vendor' );
$tour     = wp_insert_post( array( 'post_type' => 'lnkup_tour', 'post_status' => 'publish', 'post_title' => 'MOS Tour ' . $run ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $tour );
update_post_meta( $tour, '_lnkup_organizer_user_id', lnkup_t_user( 'lnkup_organizer' ) );
LNKup_Vendor_Personalization::toggle_favorite( $follower, 'tours', (int) $tour );
$stop = lnkup_m_event( array( 'city' => 'Mosalertville' ), false );
update_post_meta( $stop['event'], '_lnkup_tour_id', (int) $tour );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $follower, 'v_followed_tour_new_stop' ) ), 'follower told about the new stop' );
update_post_meta( $stop['event'], '_lnkup_tour_id', (int) $tour );
LNKup_Lifecycle::notify_tour_stop( $stop['event'], (int) $tour );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $follower, 'v_followed_tour_new_stop' ) ), 'one message per stop' );
$past = lnkup_m_event( array( 'event_date' => gmdate( 'Y-m-d', strtotime( '-3 days' ) ) ), false );
update_post_meta( $past['event'], '_lnkup_tour_id', (int) $tour );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $follower, 'v_followed_tour_new_stop' ) ), 'past stops are not announced' );
lnkup_t_same( 0, count( lnkup_ct_messages_for( $vendor, 'v_followed_tour_new_stop' ) ), 'non-followers get nothing' );

echo "Consent records\n";
$c = lnkup_ct_user( 'lnkup_vendor' );
LNKup_Lifecycle::on_registered( $c, 'lnkup_vendor' );
$rec = get_user_meta( $c, LNKup_Lifecycle::CONSENT_META, true );
lnkup_t_assert( 'granted' === $rec['state'] && 'registration_default' === $rec['source'] && $rec['at'] > 0, 'registration records the default opt-out consent with source and time' );
$promo = '';
foreach ( LNKup_Comms_Registry::categories() as $key => $cat ) {
	if ( ! empty( $cat['promotional'] ) && in_array( 'vendor', (array) $cat['roles'], true ) && empty( $cat['locked'] ) ) {
		$promo = $key;
		break;
	}
}
LNKup_Comms_Preferences::update( $c, array( 'categories' => array( array( 'key' => $promo, 'email' => 'off' ) ) ) );
$rec = get_user_meta( $c, LNKup_Lifecycle::CONSENT_META, true );
lnkup_t_assert( 'withdrawn' === $rec['state'] && 'preferences' === $rec['source'], 'turning off a promotional category records a withdrawal' );
LNKup_Comms_Suppression::apply( $c, 'marketing' );
$rec = get_user_meta( $c, LNKup_Lifecycle::CONSENT_META, true );
lnkup_t_assert( 'unsubscribe_link' === $rec['source'] && 'withdrawn' === $rec['state'] && count( $rec['history'] ) >= 3, 'unsubscribe link recorded; history kept' );
lnkup_t_assert( 'market_alert' === get_user_meta( $vendor, LNKup_Lifecycle::CONSENT_META, true )['source'], 'market alert opt-in recorded' );

echo "Resources hub\n";
LNKup_Resources_Hub::setup();
lnkup_t_assert( term_exists( 'vendor-guides', 'category' ) && term_exists( 'organizer-guides', 'category' ), 'guide categories exist' );
delete_option( LNKup_Resources_Hub::SEEDED );
$drafts = LNKup_Resources_Hub::seed_outlines();
lnkup_t_same( 6, count( $drafts ), 'six outline drafts created' );
lnkup_t_same( array( 'draft' ), array_values( array_unique( array_map( 'get_post_status', $drafts ) ) ), 'outlines are drafts (nothing published)' );
lnkup_t_same( 2, count( array_filter( $drafts, static fn( $id ) => (bool) get_post_meta( $id, LNKup_Resources_Hub::LEGAL_FLAG, true ) ) ), 'permit and insurance outlines flagged for legal review' );
lnkup_t_same( array(), LNKup_Resources_Hub::seed_outlines(), 'seeding runs once' );
$author = lnkup_ct_user( 'author' );
wp_set_current_user( $author );
$guide = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'MOS guide ' . $run, 'post_content' => 'Text', 'post_category' => array( (int) get_term_by( 'slug', 'vendor-guides', 'category' )->term_id ) ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $guide );
lnkup_t_same( 'pending', get_post_status( $guide ), 'a non-admin cannot publish a guide without the review checklist' );
update_post_meta( $guide, '_lnkup_review_seo', 1 );
update_post_meta( $guide, '_lnkup_review_facts', 1 );
update_post_meta( $guide, LNKup_Resources_Hub::LEGAL_FLAG, 1 );
wp_update_post( array( 'ID' => $guide, 'post_status' => 'publish' ) );
lnkup_t_same( 'pending', get_post_status( $guide ), 'permit/insurance/tax topics also need legal review' );
update_post_meta( $guide, '_lnkup_review_legal', 1 );
wp_update_post( array( 'ID' => $guide, 'post_status' => 'publish' ) );
lnkup_t_same( 'publish', get_post_status( $guide ), 'complete checklist allows publishing' );
wp_set_current_user( 0 );
lnkup_t_assert( false !== strpos( (string) get_permalink( $guide ), '/guides/' ), 'guides live at /guides/{slug}/' );
wp_set_post_tags( $guide, array( 'Charlotte', 'Food' ) );
lnkup_t_assert( is_array( LNKup_Resources_Hub::related_cards( $guide ) ), 'related opportunities resolve from city and category tags' );
$normal = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'MOS news ' . $run ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $normal );
lnkup_t_assert( false === strpos( (string) get_permalink( $normal ), '/guides/' ), 'ordinary posts keep their normal URL' );

echo "Organizer cohort\n";
LNKup_Org_Growth::update_settings( array( 'organizer_growth_enabled' => true ) );
$saved = LNKup_Cohort_Landings::save( array( 'slug' => 'mos-cohort-' . $run, 'title' => 'MOS Launch Cohort', 'intro' => 'Six weeks', 'speakers' => array( array( 'name' => 'Pat Organizer', 'role' => 'Market founder', 'bio' => 'Runs a weekly market.' ), array( 'name' => '' ) ), 'enabled' => 1 ) );
lnkup_t_assert( is_array( $saved ) && 1 === count( $saved['speakers'] ), 'cohort saved with speakers from admin content (empty rows dropped)' );
$form = array( 'name' => 'Jordan', 'organization' => 'MOS Night Market ' . $run, 'email' => 'jordan-' . $run . '@example.test', 'city' => 'Charlotte', 'state' => 'NC', 'event_frequency' => 'monthly', 'consent' => 1 );
lnkup_t_same( 'lnkup_cohort_invalid', LNKup_Cohort_Landings::submit( $saved['slug'], array_merge( $form, array( 'consent' => 0 ) ) )->get_error_code(), 'consent is required' );
lnkup_t_same( 'lnkup_cohort_spam', LNKup_Cohort_Landings::submit( $saved['slug'], array_merge( $form, array( 'company_website' => 'spam' ) ) )->get_error_code(), 'honeypot blocks bots' );
$lead = LNKup_Cohort_Landings::submit( $saved['slug'], $form );
$lead_ids[] = (int) $lead;
$row = LNKup_Org_Leads::get_row( (int) $lead );
lnkup_t_assert( is_int( $lead ) && 'cohort' === $row['source'] && 'cohort:' . $saved['slug'] === $row['source_detail'], 'interest creates an organizer lead with source=cohort' );
$again = LNKup_Cohort_Landings::submit( $saved['slug'], $form );
lnkup_t_same( $lead, $again, 'a repeat request is logged on the same lead' );
set_transient( 'lnkup_cohort_rl_' . substr( hash_hmac( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? '' ) . gmdate( 'Y-m-d' ), wp_salt( 'nonce' ) ), 0, 20 ), 99, HOUR_IN_SECONDS );
lnkup_t_same( 'lnkup_rate_limited', LNKup_Cohort_Landings::submit( $saved['slug'], $form )->get_error_code(), 'rate limited per IP' );
LNKup_Org_Growth::update_settings( array( 'organizer_growth_enabled' => false ) );
lnkup_t_same( 'lnkup_cohort_closed', LNKup_Cohort_Landings::submit( $saved['slug'], $form )->get_error_code(), 'closed while the organizer growth module is off' );

echo "\n";
foreach ( $GLOBALS['lnkup_test_cleanup'] as $item ) {
	if ( 'post' === $item[0] && 'lnkup_opportunity' === get_post_type( (int) $item[1] ) ) {
		$wpdb->delete( LNKup_Marketplace_Index::table_name(), array( 'opportunity_id' => (int) $item[1] ) );
	}
}
foreach ( (array) ( $GLOBALS['lnkup_m_extra_cleanup'] ?? array() ) as $callback ) {
	call_user_func( $callback );
}
lnkup_ct_finish();
