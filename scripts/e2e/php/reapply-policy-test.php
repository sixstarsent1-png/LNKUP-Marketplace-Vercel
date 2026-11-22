<?php
/**
 * Phase 5 integration test: REST apply with organizer questions and idempotency, withdraw and
 * re-apply, the "declined" re-apply policy (organizer allow, new invite, material change), and
 * the same rule in drafts, tour multi-apply, tour detail and matching.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/reapply-policy-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Re-apply policy\n";

$GLOBALS['lnkup_rp_settings'] = get_option( 'lnkup_settings', array() );
update_option(
	'lnkup_settings',
	array_merge(
		is_array( $GLOBALS['lnkup_rp_settings'] ) ? $GLOBALS['lnkup_rp_settings'] : array(),
		array(
			'matching_enabled'                  => true,
			'matching_vendor_discovery_enabled' => true,
			'matching_tours_enabled'            => true,
			'tour_multi_apply_enabled'          => true,
			'apply_wizard_enabled'              => true,
			'platform_prelaunch_mode'           => false,
			'match_invite_vendor_weekly_cap'    => 50,
		)
	),
	false
);

LNKup_Match_Categories::seed_groups();
$food            = term_exists( 'Food Vendor', 'lnkup_vendor_category' );
$GLOBALS['food'] = (int) ( is_array( $food ) ? $food['term_id'] : $food );

/**
 * Published Charlotte event with one open food opportunity.
 *
 * @return array{0:int,1:int} Event and opportunity IDs.
 */
function lnkup_rp_event( int $organizer ): array {
	$event = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'draft', 'post_title' => 'Reapply test ' . wp_generate_password( 4, false ), 'post_author' => $organizer ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $event );
	foreach ( array( 'organizer_user_id' => $organizer, 'city' => 'Charlotte', 'state' => 'NC', 'lat' => '35.23', 'lng' => '-80.84', 'event_date' => wp_date( 'Y-m-d', time() + 60 * DAY_IN_SECONDS ), 'event_status' => 'live' ) as $key => $value ) {
		update_post_meta( $event, '_lnkup_' . $key, $value );
	}
	wp_update_post( array( 'ID' => $event, 'post_status' => 'publish' ) );
	$opp = wp_insert_post( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'publish', 'post_title' => 'Reapply booth', 'post_author' => $organizer ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $opp );
	foreach ( array( 'event_id' => $event, 'organizer_user_id' => $organizer, 'opportunity_status' => 'open', 'spots_total' => 10, 'spots_remaining' => 10, 'vendor_fee' => 100, 'required_documents' => 'none', 'application_deadline' => wp_date( 'Y-m-d', time() + 50 * DAY_IN_SECONDS ) ) as $key => $value ) {
		update_post_meta( $opp, '_lnkup_' . $key, $value );
	}
	wp_set_object_terms( $opp, array( $GLOBALS['food'] ), 'lnkup_vendor_category' );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	return array( (int) $event, (int) $opp );
}

/**
 * Charlotte food vendor with a discoverable profile.
 *
 * @return array{0:int,1:int} User and profile IDs.
 */
function lnkup_rp_vendor(): array {
	$user    = lnkup_t_user( 'lnkup_vendor' );
	$profile = wp_insert_post( array( 'post_type' => 'lnkup_vendor_profile', 'post_status' => 'publish', 'post_title' => 'Reapply vendor ' . $user ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $profile );
	foreach ( array( 'owner_user_id' => $user, 'business_name' => 'Reapply vendor ' . $user, 'city' => 'Charlotte', 'state' => 'NC', 'geo_lat' => '35.23', 'geo_lng' => '-80.84' ) as $key => $value ) {
		update_post_meta( $profile, '_lnkup_' . $key, $value );
	}
	update_post_meta( $profile, LNKup_Match_Vendor_Index::GEO_KEY_META, 'charlotte|nc|' );
	wp_set_object_terms( $profile, array( $GLOBALS['food'] ), 'lnkup_vendor_category' );
	LNKup_Match_REST::apply_preferences( $profile, array( 'primary_category' => $GLOBALS['food'], 'cities_served' => array( 'Charlotte' ) ) );
	return array( $user, (int) $profile );
}

/**
 * POST /applications/apply-with-profile as the current user.
 *
 * @return WP_REST_Response
 */
function lnkup_rp_rest_apply( int $opp, array $body = array(), string $key = '' ) {
	$req = new WP_REST_Request( 'POST', '/lnkup/v1/applications/apply-with-profile' );
	$req->set_header( 'content-type', 'application/json' );
	if ( '' !== $key ) {
		$req->set_header( 'Idempotency-Key', $key );
	}
	$req->set_body( wp_json_encode( array_merge( array( 'opportunity_id' => $opp, 'selected_category' => 'Food Vendor', 'accepted_terms' => true ), $body ) ) );
	$res  = rest_do_request( $req );
	$data = $res->get_data();
	if ( is_array( $data ) && ! empty( $data['application_id'] ) ) {
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $data['application_id'] );
	}
	return $res;
}

/**
 * Backdate an application's decision so later stamps are strictly newer (second resolution), and
 * clear the fixture's own creation-time change stamps, which would otherwise look newer.
 */
function lnkup_rp_backdate_decision( int $app_id ): void {
	update_post_meta( $app_id, '_lnkup_decided_at', wp_date( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS ) );
	$opp_id = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
	delete_post_meta( $opp_id, LNKup_Applications::MATERIAL_CHANGE_META );
	delete_post_meta( (int) get_post_meta( $opp_id, '_lnkup_event_id', true ), LNKup_Applications::MATERIAL_CHANGE_META );
}

$org   = lnkup_t_user( 'lnkup_organizer' );
$other = lnkup_t_user( 'lnkup_organizer' );
list( $vendor, $profile ) = lnkup_rp_vendor();
list( $event, $opp )      = lnkup_rp_event( $org );
delete_transient( 'lnkup_apply_' . $vendor . '_' . $opp );

echo "REST apply with organizer questions\n";
LNKup_Application_Questions::save_for_opportunity( $opp, array( array( 'id' => 'q_power', 'label' => 'Do you need power?', 'type' => 'select', 'required' => true, 'options' => array( 'Yes', 'No' ) ) ), $org );
wp_set_current_user( $vendor );
$ctx = LNKup_Applications::build_apply_context( $opp, $vendor );
lnkup_t_same( 'q_power', $ctx['questions'][0]['id'] ?? null, 'apply-context lists the organizer question' );
lnkup_t_same( true, $ctx['features']['drafts'] ?? null, 'apply-context reports drafts on' );
$res = lnkup_rp_rest_apply( $opp );
lnkup_t_same( 422, $res->get_status(), 'REST apply without the required answer is rejected (422)' );
lnkup_t_same( array( 'q_power' ), $res->get_data()['data']['fields'] ?? null, 'the missing question is named' );
$res = lnkup_rp_rest_apply( $opp, array( 'answers' => array( 'q_power' => 'Yes' ) ), 'rpkey-first-0001' );
lnkup_t_same( 200, $res->get_status(), 'REST apply with answers succeeds' );
$app1 = (int) ( $res->get_data()['application_id'] ?? 0 );
lnkup_t_same( 'Yes', json_decode( (string) get_post_meta( $app1, '_lnkup_application_answers', true ), true )[0]['value'] ?? null, 'answer stored on the application' );
$replay = lnkup_rp_rest_apply( $opp, array( 'answers' => array( 'q_power' => 'Yes' ) ), 'rpkey-first-0001' );
lnkup_t_same( $app1, (int) ( $replay->get_data()['application_id'] ?? 0 ), 'retry with the same idempotency key returns the same application' );
$dup = lnkup_rp_rest_apply( $opp, array( 'answers' => array( 'q_power' => 'Yes' ) ), 'rpkey-other-0002' );
lnkup_t_assert( in_array( $dup->get_status(), array( 409, 429 ), true ), 'a different request for the same booth is refused' );

echo "Withdraw and apply again\n";
$count_before = (int) get_post_meta( $opp, '_lnkup_application_count', true );
lnkup_t_same( true, LNKup_Applications::withdraw( $app1, $vendor ), 'vendor withdraws' );
lnkup_t_same( max( 0, $count_before - 1 ), (int) get_post_meta( $opp, '_lnkup_application_count', true ), 'application count goes down on withdraw' );
$elig = LNKup_Applications::evaluate_eligibility( $opp, $vendor );
lnkup_t_same( true, $elig['can_apply'], 'withdrawn vendor can apply again' );
lnkup_t_same( 'withdrawn', $elig['previous_application']['reason'] ?? null, 'previous application reason is withdrawn' );
lnkup_t_same( true, lnkup_application_can_apply_again( 'cancelled', $opp ), 'card flag says apply again after withdrawal' );
delete_transient( 'lnkup_apply_' . $vendor . '_' . $opp );
$res  = lnkup_rp_rest_apply( $opp, array( 'answers' => array( 'q_power' => 'No' ) ), 'rpkey-again-0003' );
lnkup_t_same( 200, $res->get_status(), 'second application after withdrawal succeeds' );
$app2 = (int) ( $res->get_data()['application_id'] ?? 0 );

echo "Declined blocks re-applying\n";
wp_set_current_user( $org );
lnkup_t_same( true, LNKup_Applications::handle_organizer_action( $app2, 'reject', $org ), 'organizer declines' );
lnkup_rp_backdate_decision( $app2 );
wp_set_current_user( $vendor );
$elig = LNKup_Applications::evaluate_eligibility( $opp, $vendor );
lnkup_t_same( 'previously_declined', $elig['block_reason'], 'declined vendor is blocked' );
delete_transient( 'lnkup_apply_' . $vendor . '_' . $opp );
$res = lnkup_rp_rest_apply( $opp, array( 'answers' => array( 'q_power' => 'No' ) ), 'rpkey-blocked-0004' );
lnkup_t_same( 409, $res->get_status(), 'REST apply after a decline returns 409' );
lnkup_t_same( 'previously_declined', $res->get_data()['code'] ?? null, 'with code previously_declined' );
$draft = LNKup_Application_Drafts::upsert( $vendor, $opp, array( 'fields' => array( 'selected_category' => 'Food Vendor' ) ) );
lnkup_t_assert( is_wp_error( $draft ) && 'previously_declined' === $draft->get_error_code(), 'drafts refuse a declined booth' );
lnkup_t_same( 'declined', LNKup_Applications::build_apply_context( $opp, $vendor )['button_state'], 'button state is declined' );
lnkup_t_same( false, lnkup_application_can_apply_again( 'rejected', $opp ), 'card flag stays off while declined' );
LNKup_Match_Service::compute_for_vendor( $profile );
lnkup_t_same( false, LNKup_Match_Service::is_eligible( $vendor, $opp ), 'matching does not recommend a declined booth' );

echo "Organizer allows re-applying\n";
wp_set_current_user( $other );
lnkup_t_assert( is_wp_error( LNKup_Applications::handle_organizer_action( $app2, 'allow_reapply', $other ) ), 'another organizer cannot re-open it' );
wp_set_current_user( $org );
lnkup_t_same( true, LNKup_Applications::handle_organizer_action( $app2, 'allow_reapply', $org ), 'owner lets the vendor re-apply' );
$state = LNKup_Applications::reapply_state( $opp, $vendor );
lnkup_t_same( array( 'allowed', 'organizer_allowed' ), array( $state['reapply'] ?? '', $state['reason'] ?? '' ), 're-apply allowed by the organizer' );
lnkup_t_same( true, LNKup_Match_Service::is_eligible( $vendor, $opp ), 'matching recommends it again' );
delete_post_meta( $app2, LNKup_Applications::REAPPLY_ALLOWED_META );

echo "New invite after the decline\n";
$invite = LNKup_Vendor_Invites::create( $org, $vendor, array( 'event_id' => $event, 'opportunity_id' => $opp ) );
lnkup_t_assert( ! is_wp_error( $invite ), 'organizer invites the vendor again' . ( is_wp_error( $invite ) ? ' (' . $invite->get_error_code() . ')' : '' ) );
lnkup_t_same( 'invited_again', LNKup_Applications::reapply_state( $opp, $vendor )['reason'] ?? null, 'a newer invite re-opens the booth' );
foreach ( get_posts( array( 'post_type' => 'lnkup_vendor_invite', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_lnkup_vendor_user_id', 'meta_value' => $vendor ) ) as $invite_id ) {
	wp_delete_post( $invite_id, true );
}

echo "Material change after the decline\n";
lnkup_t_same( 'declined', LNKup_Applications::reapply_state( $opp, $vendor )['reason'] ?? null, 'blocked again once the invite is gone' );
update_post_meta( $opp, '_lnkup_vendor_fee', 80 );
lnkup_t_same( 'opportunity_changed', LNKup_Applications::reapply_state( $opp, $vendor )['reason'] ?? null, 'a fee change re-opens the booth' );
delete_post_meta( $opp, LNKup_Applications::MATERIAL_CHANGE_META );
update_post_meta( $event, '_lnkup_event_date', wp_date( 'Y-m-d', time() + 61 * DAY_IN_SECONDS ) );
lnkup_t_same( 'opportunity_changed', LNKup_Applications::reapply_state( $opp, $vendor )['reason'] ?? null, 'an event date change re-opens the booth' );
delete_post_meta( $event, LNKup_Applications::MATERIAL_CHANGE_META );
update_post_meta( $opp, '_lnkup_vendor_fee', 80 );
lnkup_t_same( 'declined', LNKup_Applications::reapply_state( $opp, $vendor )['reason'] ?? null, 'saving the same fee is not a change' );

echo "Tours\n";
list( $e2, $o2 ) = lnkup_rp_event( $org );
$tour = LNKup_Tours::save( $org, array( 'title' => 'Reapply tour', 'type' => 'tour', 'event_ids' => array( $event, $e2 ) ) );
lnkup_t_assert( ! is_wp_error( $tour ), 'two-stop tour saved' );
$tour_id = is_wp_error( $tour ) ? 0 : (int) $tour['id'];
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $tour_id );
foreach ( array( $opp, $o2 ) as $sync ) {
	LNKup_Marketplace_Index::sync_opportunity( $sync );
}
$detail = LNKup_Tours::detail( $tour_id, $vendor );
$flags  = array();
foreach ( $detail['stops'] as $stop ) {
	foreach ( $stop['opportunities'] as $row ) {
		$flags[ (int) $row['opportunity_id'] ] = $row['can_apply_again'];
	}
}
lnkup_t_same( false, $flags[ $opp ] ?? null, 'tour detail: declined stop cannot be applied to again' );
wp_set_current_user( $vendor );
$group = LNKup_Application_Groups::submit(
	$tour_id,
	$vendor,
	array(
		'group_id'       => 'rpgroup' . wp_generate_password( 8, false, false ),
		'accepted_terms' => true,
		'selections'     => array( array( 'event_id' => $event, 'opportunity_id' => $opp ), array( 'event_id' => $e2, 'opportunity_id' => $o2 ) ),
	)
);
$results = is_wp_error( $group ) ? array() : array_column( $group['results'], null, 'opportunity_id' );
foreach ( $results as $row ) {
	if ( ! empty( $row['application_id'] ) && 'submitted' === $row['result'] ) {
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $row['application_id'] );
	}
}
lnkup_t_same( array( 'ineligible', 'previously_declined' ), array( $results[ $opp ]['result'] ?? '', $results[ $opp ]['code'] ?? '' ), 'tour multi-apply skips the declined stop' );
lnkup_t_same( 'submitted', $results[ $o2 ]['result'] ?? null, 'other stop submits with the vendor default category (no category sent)' );
lnkup_t_same( 'Food Vendor', (string) get_post_meta( (int) ( $results[ $o2 ]['application_id'] ?? 0 ), '_lnkup_selected_category', true ), 'default category is the vendor match' );

update_option( 'lnkup_settings', $GLOBALS['lnkup_rp_settings'], false );
foreach ( array( $opp, $o2 ) as $cleanup_opp ) {
	delete_transient( 'lnkup_apply_' . $vendor . '_' . $cleanup_opp );
}
lnkup_t_finish();
