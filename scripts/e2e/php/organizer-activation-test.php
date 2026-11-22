<?php
/**
 * Phase 4 — organizer activation model, Get started data, share links, public embed card,
 * saved setups, duplicate copying questions, Comms next-step parity, import consent.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/organizer-activation-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Organizer activation\n";

$org    = lnkup_t_user( 'lnkup_organizer' );
$other  = lnkup_t_user( 'lnkup_organizer' );
$vendor = lnkup_t_user( 'lnkup_vendor' );
$admin  = lnkup_t_user( 'administrator' );
$posts  = array();
$cat    = (string) ( get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false, 'number' => 1, 'fields' => 'names' ) )[0] ?? 'Food' );
$future = gmdate( 'Y-m-d', time() + 40 * DAY_IN_SECONDS );
$dead   = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );

$status_of = static function ( array $data ): array {
	return array_column( $data['stages'], 'status', 'key' );
};
$rest = static function ( int $user, string $method, string $route, array $body = array(), array $query = array() ) {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, '/lnkup/v1' . $route );
	foreach ( $query as $k => $v ) {
		$req->set_param( $k, $v );
	}
	if ( $body ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	return array( $res->get_status(), $res->get_data() );
};
$publish_body = static function ( string $name, array $extra = array() ) use ( $future, $dead, $cat ): array {
	return array_merge(
		array(
			'event_name'           => $name,
			'event_date'           => $future,
			'city'                 => 'Charlotte',
			'state'                => 'NC',
			'categories'           => array( $cat ),
			'vendor_fee'           => 0,
			'spots_total'          => 5,
			'application_deadline' => $dead,
			'client_token'         => wp_generate_uuid4(),
		),
		$extra
	);
};

// --------------------------------------------------------------- Fresh organizer.
echo "\n[fresh organizer]\n";
lnkup_t_assert( ! LNKup_Stripe_Connect::is_enabled(), 'wp-env runs with Stripe Connect off' );
$a = LNKup_Organizer_Activation::for_user( $org, true );
lnkup_t_same( LNKup_Organizer_Activation::STAGES, array_column( $a['stages'], 'key' ), 'six stages in order' );
lnkup_t_same( 'not_started', $status_of( $a )['organization'], 'organization not started' );
lnkup_t_same( 'complete', $status_of( $a )['payouts'], 'payouts count as done when LNKup pays out (Connect off)' );
lnkup_t_same( 'not_started', $status_of( $a )['first_event'], 'no event yet' );
lnkup_t_same( 17, $a['percent'], '1 of 6 stages = 17%' );
lnkup_t_same( 'organization', $a['next_action']['stage'] ?? '', 'next action is organization setup' );
lnkup_t_same( array( 'Organization name', 'City and state' ), $a['next_action']['missing'] ?? array(), 'missing requirements listed (logo/bio only recommended)' );
lnkup_t_same( 'first_event', LNKup_Organizer_Activation::comms_next_step( $org )['key'], 'Comms next step agrees: create first event' );
if ( class_exists( 'LNKup_Comms_Context' ) ) {
	lnkup_t_same( 'first_event', LNKup_Comms_Context::organizer_next_step( $org )['key'], 'Comms plugin uses the activation model' );
}

$connect_on = static function ( $value ) {
	return is_array( $value ) ? array_merge( $value, array( 'stripe_connect_enabled' => true ) ) : $value;
};
add_filter( 'option_lnkup_settings', $connect_on );
$a = LNKup_Organizer_Activation::for_user( $org, true );
lnkup_t_same( 'not_started', $status_of( $a )['payouts'], 'with Connect on, payouts not set up yet' );
lnkup_t_same( 'not_connected', $a['stages'][1]['payout_state'], 'payout state uses friendly Financial-tab states' );
lnkup_t_same( 'Set up payouts', $a['stages'][1]['items'][0]['label'], 'friendly payout label (no Stripe jargon)' );
remove_filter( 'option_lnkup_settings', $connect_on );

update_user_meta( $org, '_lnkup_organizer_public_name', 'Activation Test Markets' );
update_user_meta( $org, '_lnkup_organizer_city', 'Charlotte' );
update_user_meta( $org, '_lnkup_organizer_state', 'NC' );
$a = LNKup_Organizer_Activation::for_user( $org );
lnkup_t_same( 'complete', $status_of( $a )['organization'], 'profile meta change clears the cache and completes organization' );

// --------------------------------------------------------------- Publish (REST) + share links.
echo "\n[publish + share]\n";
list( $code, $pub ) = $rest( $org, 'POST', '/organizer/launch/publish', $publish_body( 'Activation Test Night Market' ) );
lnkup_t_same( 200, $code, 'organizer publishes a free-booth event over REST' );
$event_id = (int) ( $pub['event_id'] ?? 0 );
$opp_id   = (int) ( $pub['opportunity_id'] ?? 0 );
$posts    = array_merge( $posts, array( $event_id ), (array) ( $pub['opportunity_ids'] ?? array( $opp_id ) ) );
lnkup_t_assert( false !== strpos( (string) ( $pub['application_url'] ?? '' ), 'apply=' . $opp_id ), 'publish returns the direct ?apply= link, not a search' );
lnkup_t_assert( '' !== (string) ( $pub['find_events_url'] ?? '' ), 'find_events_url kept for older clients' );
lnkup_t_assert( '' !== (string) ( $pub['organizer_url'] ?? '' ) && '' !== (string) ( $pub['event_url'] ?? '' ), 'event and organizer URLs returned' );
lnkup_t_same( 'live', (string) get_post_meta( $event_id, '_lnkup_event_status', true ), 'event is live' );

$a = LNKup_Organizer_Activation::for_user( $org );
lnkup_t_same( 'complete', $status_of( $a )['first_event'], 'free booth ($0) still completes the pricing requirement' );
lnkup_t_same( 'complete', $status_of( $a )['publication'], 'publication complete' );
lnkup_t_assert( false !== strpos( (string) ( $a['share']['application_url'] ?? '' ), 'apply=' . $opp_id ), 'activation returns the shareable apply link' );
lnkup_t_same( 'first_applications', $a['current_stage'], 'now waiting for first applications' );
lnkup_t_same( 100, $a['progress_to_first_event'], 'progress to first published event = 100%' );
lnkup_t_same( '', LNKup_Organizer_Activation::comms_next_step( $org )['key'], 'Comms has no setup nudge left (Connect off)' );

$check = LNKup_Organizer_Launch_Checklist::get_checklist( $org );
foreach ( array( 'completed', 'total', 'percent', 'all_complete', 'dismissed', 'show', 'show_welcome', 'items', 'primary_event_id', 'primary_opportunity_id' ) as $key ) {
	lnkup_t_assert( array_key_exists( $key, $check ), "checklist still returns {$key}" );
}
lnkup_t_same( array( 'create_event', 'vendor_categories', 'booth_fee', 'vendor_packet', 'payout_account', 'invite_vendors', 'review_applications', 'approve_vendor', 'collect_payment' ), array_column( $check['items'], 'key' ), 'checklist keeps its nine items' );
lnkup_t_same( 'complete', array_column( $check['items'], 'status', 'key' )['booth_fee'], 'free-booth bug fixed: booth fee complete at $0' );

// Multi-event: a newer draft event does not hide the live one.
$draft = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => 'Activation Test Draft', 'post_author' => $org ) );
$posts[] = $draft;
update_post_meta( $draft, '_lnkup_organizer_user_id', $org );
update_post_meta( $draft, '_lnkup_event_status', 'draft' );
$a = LNKup_Organizer_Activation::for_user( $org, true );
lnkup_t_same( 'complete', $status_of( $a )['publication'], 'a newer draft event does not undo publication' );
lnkup_t_same( $event_id, (int) $a['share']['event_id'], 'share link still points at the live event' );
lnkup_t_same( $event_id, (int) LNKup_Organizer_Launch_Checklist::get_checklist( $org )['primary_event_id'], 'checklist primary event is the live one, not the oldest/newest' );

// --------------------------------------------------------------- Applications + paid booking.
echo "\n[applications + booking]\n";
$app = wp_insert_post( array( 'post_type' => 'lnkup_application', 'post_status' => 'publish', 'post_title' => 'Activation Test App' ) );
$posts[] = $app;
update_post_meta( $app, '_lnkup_organizer_user_id', $org );
update_post_meta( $app, '_lnkup_event_id', $event_id );
update_post_meta( $app, '_lnkup_opportunity_id', $opp_id );
update_post_meta( $app, '_lnkup_vendor_user_id', $vendor );
update_post_meta( $app, '_lnkup_application_status', 'submitted' );
do_action( 'lnkup_application_submitted', $app );
$a = LNKup_Organizer_Activation::for_user( $org );
lnkup_t_same( 'in_progress', $status_of( $a )['first_applications'], 'received but not reviewed = in progress' );
update_post_meta( $app, '_lnkup_application_status', 'approved' );
LNKup_Organizer_Activation::flush( $org );
$a = LNKup_Organizer_Activation::for_user( $org );
lnkup_t_same( 'complete', $status_of( $a )['first_applications'], 'reviewed application completes the stage' );
lnkup_t_same( 'in_progress', $status_of( $a )['first_paid_booking'], 'approved vendor, not yet paid' );
$pay = lnkup_t_payment( array( 'organizer_user_id' => $org, 'vendor_user_id' => $vendor, 'event_id' => $event_id, 'total_amount' => '50.00', 'payment_status' => 'paid' ) );
do_action( 'lnkup_payment_completed', $pay );
$a = LNKup_Organizer_Activation::for_user( $org );
lnkup_t_same( 'complete', $status_of( $a )['first_paid_booking'], 'paid booking completes setup' );
lnkup_t_assert( $a['is_activated'] && null === $a['next_action'], 'organizer fully activated' );

// --------------------------------------------------------------- REST access.
echo "\n[access]\n";
lnkup_t_same( 200, $rest( $org, 'GET', '/organizer/activation' )[0], 'organizer reads own activation' );
lnkup_t_same( 403, $rest( $other, 'GET', '/organizer/activation', array(), array( 'organizer_id' => $org ) )[0], "organizer cannot read another organizer's activation" );
lnkup_t_same( 403, $rest( $vendor, 'GET', '/organizer/activation' )[0], 'vendors have no activation' );
lnkup_t_same( 401, $rest( 0, 'GET', '/organizer/activation' )[0], 'anonymous gets 401' );
lnkup_t_same( 200, $rest( $admin, 'GET', '/organizer/activation', array(), array( 'organizer_id' => $org ) )[0], 'growth admin can read any organizer' );

// --------------------------------------------------------------- Public embed card.
echo "\n[public card]\n";
list( $code, $card ) = $rest( 0, 'GET', '/public/opportunities/' . $opp_id . '/card' );
lnkup_t_same( 200, $code, 'anonymous can load the public card for an open slot' );
lnkup_t_same( array( 'id', 'title', 'event_title', 'event_date', 'city', 'state', 'vendor_fee', 'spots_left', 'deadline', 'organizer_name', 'organizer_slug', 'apply_url' ), array_keys( (array) ( $card['data'] ?? array() ) ), 'card exposes only public listing fields' );
lnkup_t_same( 'Activation Test Markets', $card['data']['organizer_name'] ?? '', 'card uses the organization name' );
update_post_meta( $opp_id, '_lnkup_invite_only', 1 );
lnkup_t_same( 404, $rest( 0, 'GET', '/public/opportunities/' . $opp_id . '/card' )[0], 'invite-only slot is hidden' );
delete_post_meta( $opp_id, '_lnkup_invite_only' );
lnkup_t_same( 404, $rest( 0, 'GET', '/public/opportunities/' . $draft . '/card' )[0], 'non-opportunity / draft IDs return 404' );
lnkup_t_assert( file_exists( LNKUP_PLUGIN_DIR . 'public/js/embed.js' ), 'embed.js ships with the plugin' );

// --------------------------------------------------------------- Saved setups + questions.
echo "\n[setups]\n";
$questions = LNKup_Application_Questions::save_for_opportunity( $opp_id, array( array( 'label' => 'Do you bring a "10x10" tent?', 'type' => 'text', 'required' => true ) ), $org, true );
lnkup_t_assert( ! is_wp_error( $questions ), 'questions saved on the original slot' );
$setup = LNKup_Organizer_Setups::save_from_event( $org, $event_id, 'Monthly night market' );
lnkup_t_assert( ! is_wp_error( $setup ) && 1 === $setup['slot_count'], 'setup saved from the event' );
lnkup_t_same( 1, $setup['slots'][0]['question_count'] ?? 0, 'setup captured the application question' );
lnkup_t_assert( is_wp_error( LNKup_Organizer_Setups::save_from_event( $other, $event_id, 'steal' ) ), "organizers cannot save setups from someone else's event" );
lnkup_t_same( 404, $rest( $other, 'GET', '/organizer/setups/' . $setup['id'] )[0], "another organizer cannot read this setup" );
list( $code, $list ) = $rest( $org, 'GET', '/organizer/setups' );
lnkup_t_same( 1, count( $list['items'] ?? array() ), 'setup listed for its owner' );

list( $code, $pub2 ) = $rest( $org, 'POST', '/organizer/launch/publish', $publish_body( 'Activation Test Market II', array( 'setup_id' => $setup['id'], 'vendor_fee' => 999, 'categories' => array( 'Beauty' ) ) ) );
lnkup_t_same( 200, $code, 'publishing with setup_id succeeds' );
$opp2  = (int) ( $pub2['opportunity_id'] ?? 0 );
$posts = array_merge( $posts, array( (int) ( $pub2['event_id'] ?? 0 ) ), (array) ( $pub2['opportunity_ids'] ?? array( $opp2 ) ) );
lnkup_t_same( 0.0, (float) get_post_meta( $opp2, '_lnkup_vendor_fee', true ), 'setup booth price used (submitted $999 ignored)' );
lnkup_t_same( array( $cat ), wp_get_object_terms( $opp2, 'lnkup_vendor_category', array( 'fields' => 'names' ) ), 'setup categories used' );
lnkup_t_same( 'Do you bring a "10x10" tent?', LNKup_Application_Questions::get_for_opportunity( $opp2 )[0]['label'] ?? '', 'application questions copied (quotes intact)' );
lnkup_t_assert( (bool) get_post_meta( $opp2, '_lnkup_documents_required_to_apply', true ), 'documents-required gate copied' );

$dup = LNKup_Organizer_Onboarding::duplicate_event( $event_id, array( 'event_date' => $future, 'event_name' => 'Activation Test Duplicate' ), $org );
lnkup_t_assert( ! is_wp_error( $dup ), 'event duplicated' );
$dup_opps = is_wp_error( $dup ) ? array() : array_map( 'intval', (array) ( $dup['opportunity_ids'] ?? array() ) );
$posts    = array_merge( $posts, array( is_wp_error( $dup ) ? 0 : (int) $dup['event_id'] ), $dup_opps );
lnkup_t_same( 'Do you bring a "10x10" tent?', $dup_opps ? ( LNKup_Application_Questions::get_for_opportunity( $dup_opps[0] )[0]['label'] ?? '' ) : '', 'duplicate_event copies application questions' );
lnkup_t_same( 200, $rest( $org, 'DELETE', '/organizer/setups/' . $setup['id'] )[0], 'setup deleted' );

// --------------------------------------------------------------- Import consent.
echo "\n[import consent]\n";
$file = wp_tempnam( 'consent.csv' );
file_put_contents( $file, "business_name,email\nConsent Test Vendor,consent-vendor@example.test\n" ); // phpcs:ignore
$wpdb->insert(
	LNKup_Vendor_Import_Tables::job_table(),
	array(
		'organizer_user_id' => $org,
		'file_path'         => $file,
		'original_filename' => 'consent.csv',
		'headers_json'      => wp_json_encode( array( 'business_name', 'email' ) ),
		'row_count'         => 1,
		'status'            => 'uploaded',
		'created_at'        => current_time( 'mysql' ),
		'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
	)
);
$job = (int) $wpdb->insert_id;
$res = LNKup_Vendor_Import::run_import( $org, $job, array( 'business_name' => 'business_name', 'email' => 'email' ), array( 'send_invites' => true, 'confirm_invites' => true ) );
lnkup_t_assert( is_wp_error( $res ) && 'consent_required' === $res->get_error_code(), 'sending invites without a consent basis is refused' );
$res = LNKup_Vendor_Import::run_import( $org, $job, array( 'business_name' => 'business_name', 'email' => 'email' ), array( 'send_invites' => true, 'confirm_invites' => true, 'consent_basis' => 'because' ) );
lnkup_t_assert( is_wp_error( $res ) && 'consent_required' === $res->get_error_code(), 'an unknown consent basis is refused' );
lnkup_t_assert( in_array( 'consent_basis', array_map( 'strval', (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . LNKup_Vendor_Import_Tables::job_table() ) ), true ), 'import job table has consent_basis' ); // phpcs:ignore
$wpdb->delete( LNKup_Vendor_Import_Tables::job_table(), array( 'id' => $job ) );
@unlink( $file ); // phpcs:ignore

// --------------------------------------------------------------- Cleanup.
foreach ( array_filter( array_unique( array_map( 'intval', $posts ) ) ) as $pid ) {
	wp_delete_post( $pid, true );
}
foreach ( array( $org, $other ) as $uid ) {
	delete_user_meta( $uid, LNKup_Organizer_Setups::META );
	LNKup_Organizer_Activation::flush( $uid );
}
lnkup_t_finish();
