<?php
/**
 * Phase 6 integration test: matching alerts delivered through lnkup-communications.
 *
 * Covers match targeting, send-time revalidation, tour grouping, the weekly summary,
 * preferences/unsubscribe, promotional caps, core delegation, paged saved-search scans,
 * organizer recruitment updates and observed attribution.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/matching-alerts-test.php
 *
 * @package LNKup_Communications
 */

require __DIR__ . '/_comms_helpers.php';

global $wpdb;
echo "Matching alerts\n";

$GLOBALS['lnkup_ma_settings'] = get_option( 'lnkup_settings', array() );
update_option(
	'lnkup_settings',
	array_merge(
		is_array( $GLOBALS['lnkup_ma_settings'] ) ? $GLOBALS['lnkup_ma_settings'] : array(),
		array(
			'matching_enabled'                     => true,
			'matching_vendor_discovery_enabled'    => true,
			'matching_organizer_discovery_enabled' => true,
			'matching_tours_enabled'               => true,
			'platform_prelaunch_mode'              => false,
		)
	),
	false
);
lnkup_ct_all_flows();

LNKup_Match_Categories::seed_groups();
$food            = term_exists( 'Food Vendor', 'lnkup_vendor_category' );
$GLOBALS['food'] = (int) ( is_array( $food ) ? $food['term_id'] : $food );
$GLOBALS['cities'] = array(
	'Charlotte' => array( 'NC', 35.23, -80.84 ),
	'Atlanta'   => array( 'GA', 33.75, -84.39 ),
	'Nashville' => array( 'TN', 36.16, -86.78 ),
	'Raleigh'   => array( 'NC', 35.78, -78.64 ),
	'Dallas'    => array( 'TX', 32.78, -96.80 ),
	'Denver'    => array( 'CO', 39.74, -104.99 ),
	'Miami'     => array( 'FL', 25.76, -80.19 ),
);

/**
 * Published event with one open food opportunity, indexed and matched.
 *
 * @return array{0:int,1:int} Event and opportunity IDs.
 */
function lnkup_ma_event( int $organizer, string $city, int $days_out = 60 ): array {
	list( $state, $lat, $lng ) = $GLOBALS['cities'][ $city ];
	$event = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'draft', 'post_title' => 'Alert test ' . $city . ' ' . wp_generate_password( 4, false ), 'post_author' => $organizer ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $event );
	foreach ( array( 'organizer_user_id' => $organizer, 'city' => $city, 'state' => $state, 'lat' => (string) $lat, 'lng' => (string) $lng, 'event_date' => wp_date( 'Y-m-d', time() + $days_out * DAY_IN_SECONDS ), 'event_status' => 'live' ) as $key => $value ) {
		update_post_meta( $event, '_lnkup_' . $key, $value );
	}
	wp_update_post( array( 'ID' => $event, 'post_status' => 'publish' ) );
	$opp = wp_insert_post( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'publish', 'post_title' => 'Alert booth', 'post_author' => $organizer ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $opp );
	foreach ( array( 'event_id' => $event, 'organizer_user_id' => $organizer, 'opportunity_status' => 'open', 'spots_total' => 10, 'spots_remaining' => 10, 'vendor_fee' => 100, 'required_documents' => 'none', 'application_deadline' => wp_date( 'Y-m-d', time() + max( 1, $days_out - 3 ) * DAY_IN_SECONDS ) ) as $key => $value ) {
		update_post_meta( $opp, '_lnkup_' . $key, $value );
	}
	wp_set_object_terms( $opp, array( $GLOBALS['food'] ), 'lnkup_vendor_category' );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	return array( (int) $event, (int) $opp );
}

/**
 * Food vendor at a city centroid with immediate opportunity emails.
 *
 * @return array{0:int,1:int} User and profile IDs.
 */
function lnkup_ma_vendor( string $city, array $cities_served = array() ): array {
	list( $state, $lat, $lng ) = $GLOBALS['cities'][ $city ];
	$user    = lnkup_ct_user( 'lnkup_vendor' );
	$profile = wp_insert_post( array( 'post_type' => 'lnkup_vendor_profile', 'post_status' => 'publish', 'post_title' => 'Alert vendor ' . $user ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $profile );
	foreach ( array( 'owner_user_id' => $user, 'business_name' => 'Alert vendor ' . $user, 'city' => $city, 'state' => $state, 'geo_lat' => (string) $lat, 'geo_lng' => (string) $lng ) as $key => $value ) {
		update_post_meta( $profile, '_lnkup_' . $key, $value );
	}
	update_post_meta( $profile, LNKup_Match_Vendor_Index::GEO_KEY_META, strtolower( $city . '|' . $state . '|' ) );
	wp_set_object_terms( $profile, array( $GLOBALS['food'] ), 'lnkup_vendor_category' );
	LNKup_Match_REST::apply_preferences( $profile, array( 'primary_category' => $GLOBALS['food'], 'cities_served' => $cities_served ? $cities_served : array( $city ) ) );
	LNKup_Comms_Preferences::update( $user, array( 'categories' => array( array( 'key' => 'opportunities', 'email' => 'immediate', 'push' => true ) ) ) );
	return array( $user, (int) $profile );
}

/**
 * Match an opportunity and run the discovery fan-out synchronously.
 */
function lnkup_ma_discover( int $opp ): void {
	LNKup_Match_Service::compute_for_opportunity( $opp );
	LNKup_Comms_Auto_Discovery::process( $opp, 1 );
}

/**
 * Deliver a message now and return its final row.
 */
function lnkup_ma_deliver( int $message_id ): array {
	LNKup_Comms_Engine::deliver( $message_id );
	return lnkup_ct_message( $message_id );
}

/**
 * Message IDs for a vendor and opportunity.
 */
function lnkup_ma_ids( int $user, int $opp, string $trigger = 'v_opportunity_match' ): array {
	return array_values(
		array_map(
			'intval',
			wp_list_pluck(
			array_filter(
				lnkup_ct_messages_for( $user, $trigger ),
				static function ( $row ) use ( $opp ) {
					return (int) $row['opportunity_id'] === $opp;
				}
			),
				'id'
			)
		)
	);
}

$org = lnkup_ct_user( 'lnkup_organizer' );

echo "Targeting and send-time revalidation\n";
list( $v_clt, $p_clt ) = lnkup_ma_vendor( 'Charlotte' );
list( $v_atl )         = lnkup_ma_vendor( 'Atlanta' );
list( , $o1 )          = lnkup_ma_event( $org, 'Charlotte' );
lnkup_ma_discover( $o1 );
$m1 = lnkup_ma_ids( $v_clt, $o1 );
lnkup_t_same( 1, count( $m1 ), 'matching Charlotte vendor gets one match alert' );
lnkup_t_same( array(), lnkup_ma_ids( $v_atl, $o1 ), 'out-of-market Atlanta vendor gets nothing' );
lnkup_ma_discover( $o1 );
lnkup_t_same( 1, count( lnkup_ma_ids( $v_clt, $o1 ) ), 're-running discovery does not duplicate the alert' );

$mail_before = count( $GLOBALS['lnkup_ct_mail'] );
$row         = lnkup_ma_deliver( $m1[0] ?? 0 );
lnkup_t_same( 'sent', $row['status'] ?? '', 'match alert is delivered' );
$mail = $GLOBALS['lnkup_ct_mail'][ $mail_before ] ?? array();
lnkup_t_assert( false !== strpos( (string) ( $mail['message'] ?? '' ), 'Why it matches' ), 'email explains why it matches' );

list( , $o2 ) = lnkup_ma_event( $org, 'Charlotte' );
lnkup_ma_discover( $o2 );
$m2  = lnkup_ma_ids( $v_clt, $o2 );
lnkup_t_same( 1, count( $m2 ), 'second alert queued before the vendor applies' );
$app = lnkup_ct_post( 'lnkup_application', 'Alert test application', array( 'vendor_user_id' => $v_clt, 'organizer_user_id' => $org, 'opportunity_id' => $o2, 'event_id' => (int) get_post_meta( $o2, '_lnkup_event_id', true ), 'application_status' => 'submitted' ) );
$row = lnkup_ma_deliver( $m2[0] ?? 0 );
lnkup_t_same( 'suppressed', $row['status'] ?? '', 'nothing is sent after the vendor applies' );

list( , $o3 ) = lnkup_ma_event( $org, 'Charlotte' );
lnkup_ma_discover( $o3 );
$m3 = lnkup_ma_ids( $v_clt, $o3 );
lnkup_t_same( 1, count( $m3 ), 'third alert queued before the opportunity fills' );
update_post_meta( $o3, '_lnkup_spots_remaining', 0 );
update_post_meta( $o3, '_lnkup_opportunity_status', 'filled' );
LNKup_Marketplace_Index::sync_opportunity( $o3 );
$row = lnkup_ma_deliver( $m3[0] ?? 0 );
lnkup_t_same( 'suppressed', $row['status'] ?? '', 'nothing is sent after the opportunity fills' );

echo "Tours\n";
$stops = array();
foreach ( array_keys( $GLOBALS['cities'] ) as $city ) {
	$stops[ $city ] = lnkup_ma_event( $org, $city );
}
$tour = LNKup_Tours::save( $org, array( 'title' => 'Alert test tour', 'type' => 'tour', 'event_ids' => wp_list_pluck( array_values( $stops ), 0 ) ) );
lnkup_t_assert( ! is_wp_error( $tour ), 'seven-stop tour saved' );
$tour_id = is_wp_error( $tour ) ? 0 : (int) $tour['id'];
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $tour_id );
list( $v_tour, $p_tour ) = lnkup_ma_vendor( 'Atlanta', array( 'Atlanta', 'Charlotte', 'Nashville' ) );
foreach ( $stops as $stop ) {
	LNKup_Marketplace_Index::sync_opportunity( $stop[1] );
	lnkup_ma_discover( $stop[1] );
}
LNKup_Comms_Auto_Matching::process_tour( $tour_id, 1 );
$tour_msgs = lnkup_ct_messages_for( $v_tour, 'v_tour_match' );
$stop_msgs = array_filter(
	lnkup_ct_messages_for( $v_tour, 'v_opportunity_match' ),
	static function ( $row ) use ( $stops ) {
		return in_array( (int) $row['opportunity_id'], wp_list_pluck( array_values( $stops ), 1 ), true );
	}
);
lnkup_t_same( 1, count( $tour_msgs ), 'a seven-stop tour produces one message per vendor' );
lnkup_t_same( 0, count( $stop_msgs ), 'no per-stop match alerts for tour stops' );
$tour_ctx = json_decode( (string) ( $tour_msgs[0]['context'] ?? '' ), true );
lnkup_t_same( 3, count( (array) ( $tour_ctx['_items'] ?? array() ) ), 'tour alert lists only the three matching stops' );
LNKup_Comms_Auto_Matching::process_tour( $tour_id, 1 );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $v_tour, 'v_tour_match' ) ), 're-running the tour job sends nothing new' );
$row = lnkup_ma_deliver( (int) ( $tour_msgs[0]['id'] ?? 0 ) );
lnkup_t_same( 'sent', $row['status'] ?? '', 'tour alert delivers' );

echo "Weekly summary\n";
lnkup_t_same( 0, LNKup_Comms_Auto_Matching::weekly_for_vendor( $v_clt ), 'vendor who heard this week is skipped' );
list( $v_new, $p_new ) = lnkup_ma_vendor( 'Charlotte' );
LNKup_Match_Service::compute_for_vendor( $p_new );
$weekly = LNKup_Comms_Auto_Matching::weekly_for_vendor( $v_new );
lnkup_t_assert( $weekly > 0, 'quiet vendor gets a weekly summary' );
lnkup_t_same( 0, LNKup_Comms_Auto_Matching::weekly_for_vendor( $v_new ), 'weekly summary is sent once' );
list( $v_off ) = lnkup_ma_vendor( 'Charlotte' );
LNKup_Comms_Preferences::update( $v_off, array( 'categories' => array( array( 'key' => 'opportunities', 'email' => 'off', 'push' => true ) ) ) );
lnkup_t_same( 0, LNKup_Comms_Auto_Matching::weekly_for_vendor( $v_off ), 'weekly summary honors email off' );

echo "Preferences, unsubscribe and caps\n";
list( $v_unsub ) = lnkup_ma_vendor( 'Charlotte' );
LNKup_Comms_Suppression::apply( $v_unsub, 'opportunities' );
list( , $o4 ) = lnkup_ma_event( $org, 'Charlotte' );
lnkup_ma_discover( $o4 );
$m4 = lnkup_ma_ids( $v_unsub, $o4 );
lnkup_t_same( 1, count( $m4 ), 'unsubscribed vendor still gets the inbox alert queued' );
lnkup_ma_deliver( $m4[0] ?? 0 );
$email = lnkup_ct_deliveries( $m4[0] ?? 0, 'email' );
lnkup_t_same( 'preference_off', $email[0]['suppression_reason'] ?? ( $email[0]['error'] ?? '' ), 'unsubscribe suppresses opportunity emails' );

list( $v_cap ) = lnkup_ma_vendor( 'Charlotte' );
$cap_mail      = array();
for ( $i = 0; $i < 4; $i++ ) {
	list( , $opp ) = lnkup_ma_event( $org, 'Charlotte' );
	lnkup_ma_discover( $opp );
	$ids = lnkup_ma_ids( $v_cap, $opp );
	lnkup_ma_deliver( $ids[0] ?? 0 );
	$deliv      = lnkup_ct_deliveries( $ids[0] ?? 0, 'email' );
	$cap_mail[] = (string) ( $deliv[0]['status'] ?? '' );
}
lnkup_t_same( 3, count( array_keys( $cap_mail, 'provider_accepted', true ) ) + count( array_keys( $cap_mail, 'delivered', true ) ), 'three promotional emails go out in a week' );
lnkup_t_same( 'suppressed', $cap_mail[3], 'the fourth is held back by the weekly cap' );

echo "Delegation\n";
$search_items = array( array( 'title' => 'Alert test fair', 'meta' => 'Charlotte', 'url' => home_url( '/' ) ) );
LNKup_Notifications::send_saved_search_match( $v_new, 'Charlotte food', 1, $search_items, array(), 'ss_test' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $v_new, 'v_saved_search_match' ) ), 'saved-search match is delegated to comms when the flow is on' );
lnkup_ct_settings( array( 'flow_discovery' => false ) );
LNKup_Notifications::send_saved_search_match( $v_new, 'Charlotte food', 1, $search_items, array(), 'ss_test2' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $v_new, 'v_saved_search_match' ) ), 'core sends saved-search mail itself when the flow is off' );
lnkup_ct_settings( array( 'flow_discovery' => true ) );

list( , $o5 ) = lnkup_ma_event( $org, 'Charlotte', 5 );
LNKup_Notifications::send_favorite_deadline_reminder( $v_new, 'Alert booth', wp_date( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ), '48h', $o5 );
lnkup_t_same( 1, count( lnkup_ma_ids( $v_new, $o5, 'v_saved_deadline' ) ), 'favorite deadline reminder is delegated with the opportunity' );

echo "Paged saved-search scan\n";
update_user_meta( $v_new, 'lnkup_saved_searches', array( array( 'id' => 'ss_test', 'name' => 'Charlotte food', 'filters' => array( 'q' => 'food' ), 'alerts' => true ) ) );
$seen = array();
$page = 1;
do {
	$batch = LNKup_Saved_Searches::alert_user_ids( $page, 50 );
	$seen  = array_merge( $seen, $batch['ids'] );
	++$page;
} while ( $batch['has_more'] && $page < 200 );
lnkup_t_assert( in_array( $v_new, $seen, true ), 'paged scan includes the vendor with a saved search' );
lnkup_t_assert( ! in_array( $v_atl, $seen, true ), 'paged scan skips vendors without saved searches' );
lnkup_t_same( count( $seen ), count( array_unique( $seen ) ), 'no vendor appears on two pages' );

echo "Organizer recruitment updates\n";
list( $e_soon ) = lnkup_ma_event( $org, 'Charlotte', 7 );
$sent_spots     = LNKup_Comms_Auto_Matching::open_spots();
lnkup_t_assert( $sent_spots > 0, 'organizer is told about open spots 7 days out' );
$spots = array_filter(
	lnkup_ct_messages_for( $org, 'o_open_spots' ),
	static function ( $row ) use ( $e_soon ) {
		return (int) $row['event_id'] === $e_soon;
	}
);
lnkup_t_same( 1, count( $spots ), 'one open-spots reminder for the event' );
LNKup_Comms_Auto_Matching::open_spots();
lnkup_t_same( count( $spots ), count( lnkup_ct_messages_for( $org, 'o_open_spots' ) ), 'open-spots reminder is not repeated the same day' );

list( $e_new, $o_new ) = lnkup_ma_event( $org, 'Charlotte' );
LNKup_Match_Service::compute_for_opportunity( $o_new );
update_post_meta( $e_new, LNKup_Comms_Auto_Matching::NEW_MATCHES, time() - DAY_IN_SECONDS );
lnkup_t_assert( LNKup_Match_Service::discoverable_match_count( $e_new, $org, time() - DAY_IN_SECONDS ) > 0, 'newly matched vendors are counted' );
LNKup_Comms_Auto_Matching::new_matching_vendors();
$new_msgs = array_filter(
	lnkup_ct_messages_for( $org, 'o_new_matching_vendors' ),
	static function ( $row ) use ( $e_new ) {
		return (int) $row['event_id'] === $e_new;
	}
);
lnkup_t_same( 1, count( $new_msgs ), 'organizer hears about new matching vendors' );

echo "Observed attribution\n";
$wpdb->update( LNKup_Comms_Schema::table( 'messages' ), array( 'clicked_at' => LNKup_Comms_Schema::now() ), array( 'id' => $m1[0] ?? 0 ) );
$attr_app = lnkup_ct_post( 'lnkup_application', 'Alert attribution application', array( 'vendor_user_id' => $v_clt, 'organizer_user_id' => $org, 'opportunity_id' => $o1, 'event_id' => (int) get_post_meta( $o1, '_lnkup_event_id', true ), 'application_status' => 'submitted' ) );
do_action( 'lnkup_application_submitted', $attr_app );
$tracked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Marketplace_Analytics::table_name() . " WHERE event_type = 'match_alert_application' AND user_id = %d AND opportunity_id = %d", $v_clt, $o1 ) ); // phpcs:ignore WordPress.DB
lnkup_t_same( 1, $tracked, 'application after a clicked alert is recorded as observed' );
do_action( 'lnkup_application_submitted', $app );
$untracked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Marketplace_Analytics::table_name() . " WHERE event_type = 'match_alert_application' AND user_id = %d AND opportunity_id = %d", $v_clt, $o2 ) ); // phpcs:ignore WordPress.DB
lnkup_t_same( 0, $untracked, 'application without an opened alert is not attributed' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Marketplace_Analytics::table_name() . " WHERE event_type = 'match_alert_application' AND user_id = %d", $v_clt ) ); // phpcs:ignore WordPress.DB

update_option( 'lnkup_settings', $GLOBALS['lnkup_ma_settings'], false );
lnkup_ct_finish();
