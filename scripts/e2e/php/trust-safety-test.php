<?php
/**
 * Trust, Safety & Compliance OS (Stages 1-3): audit chain, cases, event claims,
 * publish gate, organization teams, organizer verification, REST permissions.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/trust-safety-test.php
 * Local wp-env only; never production. Settings are restored on exit.
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;

echo "Trust & Safety\n";

LNKup_Trust_Tables::maybe_upgrade();
LNKup_Trust_Tables::create_tables();

$original_settings = get_option( 'lnkup_settings', array() );
$created_cases     = array();
$created_external  = array();

/**
 * Override trust flags for the rest of the run.
 *
 * @param array $flags Settings.
 */
function lnkup_t_trust_flags( array $flags ): void {
	$settings = get_option( 'lnkup_settings', array() );
	update_option( 'lnkup_settings', array_merge( is_array( $settings ) ? $settings : array(), $flags ), false );
}

/**
 * REST call as a user.
 *
 * @param int    $user   User.
 * @param string $method Method.
 * @param string $route  Route.
 * @param array  $body   JSON body.
 */
function lnkup_t_rest_as( int $user, string $method, string $route, array $body = array() ) {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, $route );
	if ( $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	wp_set_current_user( 0 );
	return $res;
}

/**
 * External discovered event + claim token for an email.
 *
 * @param string $email Invited email.
 * @return array{0:int,1:string} External ID, raw token.
 */
function lnkup_t_external_event( string $email ): array {
	global $wpdb;
	$now = current_time( 'mysql' );
	$wpdb->insert(
		LNKup_External_Events_Tables::events_table(),
		array(
			'source_platform' => 'manual',
			'source_url'      => 'https://example.test/trust-' . wp_generate_password( 8, false ),
			'title'           => 'Trust Test Festival ' . wp_generate_password( 4, false ),
			'start_datetime'  => gmdate( 'Y-m-d H:i:s', strtotime( '+40 days' ) ),
			'city'            => 'Atlanta',
			'state'           => 'GA',
			'workflow_status' => 'published',
			'claim_status'    => 'unclaimed',
			'created_at'      => $now,
			'updated_at'      => $now,
		)
	);
	$id = (int) $wpdb->insert_id;
	$GLOBALS['lnkup_trust_external'][] = $id;
	return array( $id, (string) LNKup_External_Events::generate_claim_token( $id, $email ) );
}

$GLOBALS['lnkup_trust_external'] = array();

$admin = lnkup_t_user( 'administrator' );
( new WP_User( $admin ) )->add_cap( 'lnkup_manage_trust' );
$org_a  = lnkup_t_user( 'lnkup_organizer' );
$org_b  = lnkup_t_user( 'lnkup_organizer' );
$member = lnkup_t_user( 'lnkup_organizer' );
$vendor = lnkup_t_user( 'lnkup_vendor' );

/* ---------- tables ---------- */
foreach ( array( 'audit_table', 'cases_table', 'case_events_table', 'org_members_table', 'org_invites_table', 'claim_evidence_table' ) as $fn ) {
	$table = LNKup_Trust_Tables::$fn();
	lnkup_t_same( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "table exists: {$table}" );
}

/* ---------- audit chain ---------- */
$a1 = LNKup_Audit_Log::log( 'trust_test_one', 'test', 1, array( 'new' => array( 'card_number' => '4242', 'ok' => 1 ) ) );
$a2 = LNKup_Audit_Log::log( 'trust_test_two', 'test', 2, array( 'reason' => 'second' ) );
lnkup_t_assert( $a1 > 0 && $a2 > $a1, 'audit rows written in order' );
$row1 = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Trust_Tables::audit_table() . ' WHERE id = %d', $a1 ), ARRAY_A );
lnkup_t_assert( false === strpos( (string) $row1['new_state'], '4242' ), 'sensitive keys are redacted' );
lnkup_t_assert( 64 === strlen( (string) $row1['ip_hash'] ) || '' === (string) $row1['ip_hash'], 'IP stored only as a hash' );
$row2 = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Trust_Tables::audit_table() . ' WHERE id = %d', $a2 ), ARRAY_A );
lnkup_t_same( $row1['row_hash'], $row2['prev_hash'], 'row links to previous hash' );
lnkup_t_assert( LNKup_Audit_Log::verify_chain( 5 )['ok'], 'chain verifies' );
$wpdb->update( LNKup_Trust_Tables::audit_table(), array( 'reason' => 'tampered' ), array( 'id' => $a2 ) );
$broken = LNKup_Audit_Log::verify_chain( 5 );
lnkup_t_assert( ! $broken['ok'] && (int) $broken['broken_id'] === $a2, 'tampering is detected at the edited row' );
$wpdb->update( LNKup_Trust_Tables::audit_table(), array( 'reason' => 'second' ), array( 'id' => $a2 ) );
lnkup_t_assert( LNKup_Audit_Log::verify_chain( 5 )['ok'], 'chain verifies again after restore' );

/* ---------- cases ---------- */
$case_args = array( 'case_type' => 'fraud_flag', 'severity' => 'review', 'subject_type' => 'user', 'subject_id' => $vendor, 'signal_key' => 'trust_test', 'summary' => 'test' );
$c1        = LNKup_Trust_Cases::open( $case_args );
$c2        = LNKup_Trust_Cases::open( array_merge( $case_args, array( 'severity' => 'elevated' ) ) );
$created_cases[] = $c1;
lnkup_t_same( $c1, $c2, 'repeated signal reuses the open case' );
lnkup_t_same( 'elevated', LNKup_Trust_Cases::get( $c1 )['severity'], 'repeat signal can raise severity' );
lnkup_t_assert( 0 === strpos( LNKup_Trust_Cases::get( $c1 )['case_ref'], 'TS-' ), 'case gets a TS- reference' );
lnkup_t_assert( is_wp_error( LNKup_Trust_Cases::transition( $c1, 'resolved', '' ) ), 'closing without a note is refused' );
lnkup_t_assert( is_wp_error( LNKup_Trust_Cases::transition( $c1, 'bogus', 'note here' ) ), 'unknown transition is refused' );
lnkup_t_same( true, LNKup_Trust_Cases::transition( $c1, 'dismissed_false_positive', 'Test false positive.', $admin ), 'reviewer can dismiss a false positive' );
lnkup_t_assert( count( LNKup_Trust_Cases::timeline( $c1 ) ) >= 3, 'timeline records opened, repeat and close' );

/* ---------- REST permissions ---------- */
lnkup_t_same( 403, lnkup_t_rest_as( $org_a, 'GET', '/lnkup/v1/trust/cases' )->get_status(), 'organizer cannot read the case queue' );
lnkup_t_same( 403, lnkup_t_rest_as( $vendor, 'GET', '/lnkup/v1/trust/overview' )->get_status(), 'vendor cannot read the trust overview' );
lnkup_t_same( 200, lnkup_t_rest_as( $admin, 'GET', '/lnkup/v1/trust/cases' )->get_status(), 'trust reviewer reads the case queue' );
lnkup_t_same( 403, lnkup_t_rest_as( $vendor, 'GET', '/lnkup/v1/organizer/verification' )->get_status(), 'vendor cannot use organizer verification' );

/* ---------- claims: log mode, mismatch ---------- */
lnkup_t_trust_flags( array( 'trust_claim_review_mode' => 'log', 'trust_publish_gate' => false, 'trust_org_teams' => false ) );
list( $ext1, $tok1 ) = lnkup_t_external_event( 'owner@festival-trust.test' );
wp_set_current_user( $org_a );
$claim1 = LNKup_External_Events::claim( $ext1, $org_a, $tok1 );
wp_set_current_user( 0 );
lnkup_t_assert( is_array( $claim1 ) && ! empty( $claim1['event_id'] ), 'log mode: mismatched claim still creates the event' );
$event1 = is_array( $claim1 ) ? (int) $claim1['event_id'] : 0;
if ( $event1 ) {
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $event1 );
}
lnkup_t_same( 'claim_pending', LNKup_Event_Authenticity::get_status( $event1 ), 'claimed event starts as claim_pending' );
lnkup_t_assert( LNKup_Event_Authenticity::is_bookable( $event1 ), 'gate off: claim_pending listing stays bookable' );
$mismatch = LNKup_Trust_Cases::query( array( 'case_type' => 'event_claim_mismatch', 'subject_id' => $event1 ) );
lnkup_t_same( 1, (int) $mismatch['total'], 'mismatch opened a case' );
$created_cases = array_merge( $created_cases, wp_list_pluck( $mismatch['items'], 'id' ) );

/* ---------- second organizer: conflict, no transfer ---------- */
$tok1b = (string) LNKup_External_Events::generate_claim_token( $ext1, 'other@festival-trust.test' );
wp_set_current_user( $org_b );
$claim2 = LNKup_External_Events::claim( $ext1, $org_b, $tok1b );
wp_set_current_user( 0 );
lnkup_t_assert( is_array( $claim2 ) && ! empty( $claim2['pending_review'] ) && 'disputed' === $claim2['review_state'], 'second claimant gets a disputed review, not the event' );
lnkup_t_same( $org_a, (int) get_post_meta( $event1, '_lnkup_organizer_user_id', true ), 'existing owner keeps the event' );
lnkup_t_same( 'claim_disputed', LNKup_External_Events::get_row( $ext1 )['claim_status'], 'external listing marked claim_disputed' );
$conflict = LNKup_Trust_Cases::query( array( 'case_type' => 'event_claim_conflict', 'subject_id' => $ext1 ) );
lnkup_t_same( 1, (int) $conflict['total'], 'conflict opened an elevated case' );
$created_cases = array_merge( $created_cases, wp_list_pluck( $conflict['items'], 'id' ) );

/* ---------- enforce mode: mismatch waits for review ---------- */
lnkup_t_trust_flags( array( 'trust_claim_review_mode' => 'enforce' ) );
list( $ext2, $tok2 ) = lnkup_t_external_event( 'someone-else@festival-trust.test' );
wp_set_current_user( $org_b );
$claim3 = LNKup_External_Events::claim( $ext2, $org_b, $tok2 );
wp_set_current_user( 0 );
lnkup_t_assert( is_array( $claim3 ) && ! empty( $claim3['pending_review'] ) && 0 === (int) $claim3['event_id'], 'enforce: mismatched claim creates nothing' );
$pending = LNKup_Event_Claims::list_for_review( 'pending' );
$mine    = array_values( array_filter( $pending, static function ( $r ) use ( $ext2 ) { return (int) $r['external_event_id'] === $ext2; } ) );
lnkup_t_same( 1, count( $mine ), 'enforce: claim waits in the review queue' );
if ( $mine ) {
	wp_set_current_user( $admin );
	$approved = LNKup_Event_Claims::approve( (int) $mine[0]['id'], 'Confirmed by phone with organizer.' );
	wp_set_current_user( 0 );
	lnkup_t_assert( is_array( $approved ) && $approved['event_id'] > 0, 'reviewer approval creates the event' );
	if ( is_array( $approved ) ) {
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $approved['event_id'] );
		lnkup_t_same( $org_b, (int) get_post_meta( (int) $approved['event_id'], '_lnkup_organizer_user_id', true ), 'approved event belongs to the claimant' );
		lnkup_t_same( 'organizer_controlled', LNKup_Event_Authenticity::get_status( (int) $approved['event_id'] ), 'approved event is organizer_controlled' );	}
}

/* ---------- publish gate ---------- */
lnkup_t_trust_flags( array( 'trust_publish_gate' => true ) );
lnkup_t_assert( ! LNKup_Event_Authenticity::is_bookable( $event1 ), 'gate on: claim_pending listing is not bookable' );
$opp = wp_insert_post( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'publish', 'post_title' => 'Trust gate booth' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $opp );
update_post_meta( $opp, '_lnkup_event_id', $event1 );
update_post_meta( $opp, '_lnkup_opportunity_status', 'open' );
$sync = LNKup_Marketplace_Index::sync_opportunity( $opp );
lnkup_t_assert( is_wp_error( $sync ) && 'authenticity_pending' === $sync->get_error_code(), 'gate on: opportunity kept out of discovery' );
$profile = wp_insert_post( array( 'post_type' => 'lnkup_vendor_profile', 'post_status' => 'publish', 'post_title' => 'Trust test vendor' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $profile );
update_post_meta( $profile, '_lnkup_owner_user_id', $vendor );
$ctx  = LNKup_Applications::build_apply_context( $opp, $vendor );
$elig = is_array( $ctx ) ? (array) ( $ctx['eligibility'] ?? array() ) : array();
lnkup_t_same( 'authenticity_pending', $elig['closed_reason'] ?? '', 'gate on: apply is refused with a reason' );
$pay = lnkup_t_payment( array( 'event_id' => $event1, 'vendor_user_id' => $vendor, 'organizer_user_id' => $org_a, 'payment_status' => 'pending', 'total_amount' => '100.00' ) );
$checkout = LNKup_Financial_Service::create_checkout( $pay );
lnkup_t_assert( is_wp_error( $checkout ) && in_array( $checkout->get_error_code(), array( 'lnkup_listing_under_review', 'payment_not_payable' ), true ), 'gate on: checkout is refused before any Stripe call' );
LNKup_Event_Authenticity::set_status( $event1, 'organizer_controlled', 'Test promotion.' );
lnkup_t_assert( LNKup_Event_Authenticity::is_bookable( $event1 ), 'promoted listing becomes bookable' );
LNKup_Event_Authenticity::set_status( $event1, 'restricted', 'Test restriction.' );
lnkup_t_trust_flags( array( 'trust_publish_gate' => false ) );
lnkup_t_assert( ! LNKup_Event_Authenticity::is_bookable( $event1 ), 'restricted listing is blocked even with the gate off' );
$legacy_event = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => 'Legacy event' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $legacy_event );
lnkup_t_same( 'organizer_controlled', LNKup_Event_Authenticity::get_status( $legacy_event ), 'events without the meta stay organizer_controlled' );

/* ---------- organization teams ---------- */
lnkup_t_trust_flags( array( 'trust_org_teams' => true ) );
$ev_a = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => 'Org A event' ) );
$ev_b = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => 'Org B event' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $ev_a );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $ev_b );
update_post_meta( $ev_a, '_lnkup_organizer_user_id', $org_a );
update_post_meta( $ev_b, '_lnkup_organizer_user_id', $org_b );

$raw   = 'trusttesttoken' . wp_generate_password( 20, false );
$wpdb->insert(
	LNKup_Trust_Tables::org_invites_table(),
	array(
		'org_owner_id' => $org_a,
		'email'        => strtolower( get_userdata( $member )->user_email ),
		'role'         => 'finance',
		'scope_type'   => 'all',
		'scope_id'     => 0,
		'token_hash'   => hash( 'sha256', $raw ),
		'status'       => 'pending',
		'invited_by'   => $org_a,
		'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
		'created_at'   => current_time( 'mysql', true ),
	)
);
lnkup_t_assert( is_wp_error( LNKup_Org_Teams::accept( $raw, $org_b ) ), 'invite cannot be accepted by a different account' );
$accepted = LNKup_Org_Teams::accept( $raw, $member );
lnkup_t_assert( is_array( $accepted ) && $accepted['member_id'] > 0, 'invitee accepts with the invited email' );
lnkup_t_assert( is_wp_error( LNKup_Org_Teams::accept( $raw, $member ) ), 'invite cannot be reused' );

lnkup_t_assert( LNKup_Capabilities::staff_can( $ev_a, 'view_financials', $member ), 'finance member reads financials on org events' );
lnkup_t_assert( ! LNKup_Capabilities::staff_can( $ev_a, 'check_in', $member ), 'finance member cannot check vendors in' );
lnkup_t_assert( ! LNKup_Capabilities::staff_can( $ev_a, 'owner', $member ), 'member never gets owner capability' );
lnkup_t_assert( ! LNKup_Capabilities::staff_can( $ev_b, 'view_financials', $member ), 'member has no access to another organization' );
lnkup_t_same( 403, lnkup_t_rest_as( $member, 'GET', "/lnkup/v1/financial/events/{$ev_b}/financials" )->get_status(), 'REST: member cannot read another organization financials' );
lnkup_t_assert( is_wp_error( LNKup_Org_Teams::invite( $org_a, 'x@example.test', 'admin', 'all', 0, $member ) ), 'non-owner cannot add an org admin' );

$member_rows = LNKup_Org_Teams::active_memberships( $org_a, $member );
lnkup_t_same( true, LNKup_Org_Teams::revoke( $org_a, (int) $member_rows[0]['id'], $org_a, 'test' ), 'owner revokes the member' );
lnkup_t_assert( ! LNKup_Capabilities::staff_can( $ev_a, 'view_financials', $member ), 'revoked member loses access immediately' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Trust_Tables::org_members_table() . " WHERE org_owner_id = %d AND user_id = %d AND status = 'revoked'", $org_a, $member ) ), 'membership history is kept' );

lnkup_t_trust_flags( array( 'trust_org_teams' => false ) );

/* ---------- organizer verification ---------- */
lnkup_t_same( 'not_started', LNKup_Organizer_Verification::get_status( $org_a ), 'new organizer starts not_started' );
update_user_meta( $org_a, 'lnkup_organizer_verified', '1' );
lnkup_t_same( 'verified', LNKup_Organizer_Verification::get_status( $org_a ), 'legacy toggle syncs to verified' );
$decided = LNKup_Organizer_Verification::decide( $org_a, 'reverify', 'Payout account changed.', $admin );
lnkup_t_same( 'reverification_required', is_array( $decided ) ? $decided['status'] : '', 'reviewer can require reverification' );
lnkup_t_same( '', (string) get_user_meta( $org_a, 'lnkup_organizer_verified', true ), 'legacy flag cleared when not verified' );
$req = LNKup_Organizer_Verification::request_review( $org_a, array( 'organization_name' => 'Trust Test Org', 'website' => 'https://festival-trust.test' ) );
lnkup_t_same( 'pending_review', is_array( $req ) ? $req['status'] : '', 'organizer can request review' );
lnkup_t_assert( is_wp_error( LNKup_Organizer_Verification::start_domain( $org_a, 'gmail.com', 'dns' ) ), 'free mail domains are rejected' );
lnkup_t_assert( is_wp_error( LNKup_Organizer_Verification::start_domain( $org_a, 'festival-trust.test', 'email', 'me@other.test' ) ), 'email method requires an address at the domain' );
$public = LNKup_Organizer_Verification::indicators( $org_a, true );
lnkup_t_same( array( 'payout_connected', 'domain_verified', 'identity_reviewed' ), array_keys( $public ), 'public indicators expose only three booleans' );
$vq = LNKup_Trust_Cases::query( array( 'case_type' => 'organizer_verification', 'subject_id' => $org_a, 'status' => 'all' ) );
$created_cases = array_merge( $created_cases, wp_list_pluck( $vq['items'], 'id' ) );

/* ---------- cleanup ---------- */
update_option( 'lnkup_settings', $original_settings, false );
foreach ( array_filter( array_unique( array_map( 'intval', $created_cases ) ) ) as $case_id ) {
	$wpdb->delete( LNKup_Trust_Tables::case_events_table(), array( 'case_id' => $case_id ) );
	$wpdb->delete( LNKup_Trust_Tables::cases_table(), array( 'id' => $case_id ) );
}
foreach ( $GLOBALS['lnkup_trust_external'] as $ext_id ) {
	$wpdb->delete( LNKup_External_Events_Tables::events_table(), array( 'id' => $ext_id ) );
	$wpdb->delete( LNKup_External_Events_Tables::claim_tokens_table(), array( 'external_event_id' => $ext_id ) );
	$wpdb->delete( LNKup_Trust_Tables::claim_evidence_table(), array( 'external_event_id' => $ext_id ) );
}
foreach ( array( $org_a, $org_b, $member ) as $uid ) {
	$wpdb->delete( LNKup_Trust_Tables::org_members_table(), array( 'org_owner_id' => $uid ) );
	$wpdb->delete( LNKup_Trust_Tables::org_invites_table(), array( 'org_owner_id' => $uid ) );
}
lnkup_t_finish();
