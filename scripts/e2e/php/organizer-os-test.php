<?php
/**
 * Organizer Management OS Wave 1: integrity fixes, team access for reviewers, the review
 * workspace (queue scoping, scoring, bulk preview/apply) and waitlist offers.
 *
 * No Stripe calls: payments are settled through LNKup_Payments::mark_paid() like the
 * Booking & Application OS suite. Local wp-env only; settings are restored on exit.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/organizer-os-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Organizer Management OS\n";

LNKup_Organizer_OS_Tables::maybe_upgrade();
$original_settings = get_option( 'lnkup_settings', array() );

/**
 * @param array $flags Settings overrides.
 */
function lnkup_o_flags( array $flags ): void {
	$settings = get_option( 'lnkup_settings', array() );
	update_option( 'lnkup_settings', array_merge( is_array( $settings ) ? $settings : array(), $flags ), false );
}

/**
 * @param string $type  Post type.
 * @param array  $meta  Meta.
 * @param string $title Title.
 */
function lnkup_o_post( string $type, array $meta = array(), string $title = '' ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title ?: 'OOS test ' . $type, 'meta_input' => $meta ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

/**
 * @param int $organizer Organizer.
 * @param int $spots     Capacity.
 * @return int[] [event, opportunity]
 */
function lnkup_o_event( int $organizer, int $spots = 5 ): array {
	$event = lnkup_o_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+45 days' ) ), '_lnkup_city' => 'Atlanta', '_lnkup_state' => 'GA' ), 'OOS Event ' . wp_generate_password( 4, false ) );
	$opp   = lnkup_o_post(
		'lnkup_opportunity',
		array(
			'_lnkup_event_id'             => $event,
			'_lnkup_organizer_user_id'    => $organizer,
			'_lnkup_opportunity_status'   => 'open',
			'_lnkup_vendor_fee'           => '120',
			'_lnkup_spots_total'          => $spots,
			'_lnkup_spots_remaining'      => $spots,
			'_lnkup_required_documents'   => 'none',
			'_lnkup_application_deadline' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
		),
		'Booth'
	);
	LNKup_Opportunity_Inventory::recompute( $opp );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	return array( $event, $opp );
}

/**
 * @param string $category Vendor category term name.
 */
function lnkup_o_vendor( string $category = 'Retail' ): int {
	$user    = lnkup_t_user( 'lnkup_vendor' );
	$profile = lnkup_o_post( 'lnkup_vendor_profile', array( '_lnkup_owner_user_id' => $user, '_lnkup_business_name' => 'OOS Vendor ' . $user ), 'OOS Vendor ' . $user );
	wp_set_object_terms( $profile, array( $category ), 'lnkup_vendor_category' );
	$GLOBALS['lnkup_o_categories'][ $user ] = $category;
	return $user;
}

/**
 * @param int $vendor Vendor.
 * @param int $opp    Opportunity.
 * @return int Application ID.
 */
function lnkup_o_apply( int $vendor, int $opp ): int {
	wp_set_current_user( $vendor );
	$result = LNKup_Applications::submit_with_profile( $vendor, $opp, array( 'accepted_terms' => true, 'selected_category' => $GLOBALS['lnkup_o_categories'][ $vendor ] ?? 'General' ) );
	wp_set_current_user( 0 );
	if ( is_wp_error( $result ) ) {
		echo '  (apply failed: ' . $result->get_error_message() . ")\n";
		return 0;
	}
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $result );
	return (int) $result;
}

/**
 * @param int    $user   Acting user.
 * @param string $method Method.
 * @param string $route  Route.
 * @param array  $body   JSON body.
 */
function lnkup_o_rest( int $user, string $method, string $route, array $body = array() ) {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$req->set_query_params( $body );
	} elseif ( $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	wp_set_current_user( 0 );
	return $res;
}

/**
 * Put a team member on an organization through the real invite-accept path.
 *
 * @param int    $owner  Owner.
 * @param int    $member Member.
 * @param string $role   Role.
 * @param string $scope  all|tour|event.
 * @param int    $scope_id Scope ID.
 */
function lnkup_o_member( int $owner, int $member, string $role, string $scope = 'all', int $scope_id = 0 ): void {
	global $wpdb;
	$raw = 'oostoken' . wp_generate_password( 24, false );
	$wpdb->insert(
		LNKup_Trust_Tables::org_invites_table(),
		array(
			'org_owner_id' => $owner,
			'email'        => strtolower( get_userdata( $member )->user_email ),
			'role'         => $role,
			'scope_type'   => $scope,
			'scope_id'     => $scope_id,
			'token_hash'   => hash( 'sha256', $raw ),
			'status'       => 'pending',
			'invited_by'   => $owner,
			'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			'created_at'   => current_time( 'mysql', true ),
		)
	);
	LNKup_Org_Teams::accept( $raw, $member );
}

$organizer = lnkup_t_user( 'lnkup_organizer' );
$other_org = lnkup_t_user( 'lnkup_organizer' );
list( $event, $opp )             = lnkup_o_event( $organizer, 2 );
list( $other_event, $other_opp ) = lnkup_o_event( $other_org, 5 );

/* ---------- integrity fixes ---------- */
echo "\nIntegrity\n";
$vendor_a = lnkup_o_vendor( 'Food Truck' );
$vendor_b = lnkup_o_vendor( 'Retail' );
$app_a    = lnkup_o_apply( $vendor_a, $opp );
$app_b    = lnkup_o_apply( $vendor_b, $opp );
lnkup_t_assert( $app_a > 0 && $app_b > 0, 'fixtures: two applications submitted' );

lnkup_t_assert( ! LNKup_Capabilities::user_owns_post( $app_a, $vendor_a, '_lnkup_organizer_user_id' ), 'vendor is not the organizer of their own application' );
lnkup_t_assert( LNKup_Capabilities::user_owns_post( $app_a, $organizer, '_lnkup_organizer_user_id' ), 'organizer owns the application by organizer key' );
lnkup_t_assert( is_wp_error( LNKup_Applications::handle_organizer_action( $app_a, 'approve', $vendor_a ) ), 'vendor cannot approve their own application' );

$booth = LNKup_Booths::save( $event, array( 'booth_number' => 'T1' ) );
lnkup_t_assert( is_int( $booth ) && $booth > 0, 'booth created' );
lnkup_t_assert( is_wp_error( LNKup_Booths::save( $event, array( 'id' => $booth, 'booth_number' => 'T1', 'application_id' => $app_a ) ) ), 'booth save cannot assign an unapproved vendor' );
LNKup_Applications::transition( $app_a, 'approved' );
lnkup_t_same( true, LNKup_Booths::assign( $booth, $app_a, $event ), 'approved vendor assigned through assign()' );
LNKup_Booths::save( $event, array( 'id' => $booth, 'booth_number' => 'T1', 'notes' => 'edited' ) );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT application_id, notes FROM ' . LNKup_Operations_Tables::booths_table() . ' WHERE id = %d', $booth ), ARRAY_A );
lnkup_t_same( array( (string) $app_a, 'edited' ), array( (string) $row['application_id'], (string) $row['notes'] ), 'editing a booth keeps its assigned vendor' );

update_post_meta( $app_a, '_lnkup_application_status', 'paid' );
update_post_meta( $app_b, '_lnkup_application_status', 'paid' );
$trucks = LNKup_Broadcasts::resolve_audience( $event, 'food_trucks' );
$retail = LNKup_Broadcasts::resolve_audience( $event, 'retail' );
lnkup_t_same( array( $vendor_a ), $trucks, 'food truck audience only includes food trucks' );
lnkup_t_same( array( $vendor_b ), $retail, 'retail audience only includes retail vendors' );
update_post_meta( $app_a, '_lnkup_application_status', 'payment_required' );
update_post_meta( $app_b, '_lnkup_application_status', 'submitted' );
LNKup_Opportunity_Inventory::recompute( $opp );

lnkup_t_assert( LNKup_Documents::is_expired( gmdate( 'Y-m-d', strtotime( '-2 days' ) ) ), 'a past expiration date is expired' );
lnkup_t_assert( ! LNKup_Documents::is_expired( gmdate( 'Y-m-d', strtotime( '+2 days' ) ) ), 'a future expiration date is not expired' );
lnkup_t_assert( ! LNKup_Documents::is_expired( '' ), 'no expiration date is not expired' );

lnkup_t_same( 403, lnkup_o_rest( $vendor_a, 'POST', '/lnkup/v1/operations/qr/scan', array( 'token' => 'LNKUP-NOPE', 'event_id' => $event ) )->get_status(), 'QR scan: vendor is refused at the route' );
lnkup_t_same( 400, lnkup_o_rest( $organizer, 'POST', '/lnkup/v1/operations/qr/scan', array( 'token' => 'LNKUP-NOPE' ) )->get_status(), 'QR scan: event_id is required' );
lnkup_t_same( 403, lnkup_o_rest( $other_org, 'POST', '/lnkup/v1/operations/qr/scan', array( 'token' => 'LNKUP-NOPE', 'event_id' => $event ) )->get_status(), 'QR scan: another organizer is refused' );
lnkup_t_same( 404, lnkup_o_rest( $organizer, 'POST', '/lnkup/v1/operations/qr/scan', array( 'token' => 'LNKUP-NOPE', 'event_id' => $event ) )->get_status(), 'QR scan: unknown token is 404 for the owner' );

$packet = lnkup_o_post( 'lnkup_packet', array( '_lnkup_application_id' => $app_a ) );
update_post_meta( $packet, '_lnkup_qr_pass', 'LNKUP-OOS' . strtoupper( wp_generate_password( 6, false ) ) );
lnkup_t_same( $app_a, LNKup_Pass_Tokens::lookup_application( strtolower( (string) get_post_meta( $packet, '_lnkup_qr_pass', true ) ) ), 'pass token index finds the application (case-insensitive)' );
update_post_meta( $packet, '_lnkup_qr_pass', 'LNKUP-ROTATED' );
lnkup_t_same( 0, LNKup_Pass_Tokens::lookup_application( 'LNKUP-OOS' ), 'rotated token no longer matches the old value' );

lnkup_t_same( 200, lnkup_o_rest( $organizer, 'GET', "/lnkup/v1/operations/events/{$event}/broadcasts/preview", array( 'audience' => 'retail' ) )->get_status(), 'broadcast preview returns a count' );

/* ---------- feature flags off ---------- */
echo "\nFlags\n";
lnkup_o_flags( array( 'application_review_v2_enabled' => false, 'waitlist_offers_enabled' => false, 'trust_org_teams' => true ) );
lnkup_t_same( 404, lnkup_o_rest( $organizer, 'GET', '/lnkup/v1/applications/review/queue' )->get_status(), 'review queue is hidden while the flag is off' );
lnkup_o_flags( array( 'application_review_v2_enabled' => true, 'waitlist_offers_enabled' => true ) );

/* ---------- team access ---------- */
echo "\nTeam access\n";
$reviewer = lnkup_t_user( 'subscriber' );
$manager  = lnkup_t_user( 'subscriber' );
lnkup_o_member( $organizer, $reviewer, 'reviewer', 'event', $event );
lnkup_o_member( $organizer, $manager, 'vendor_manager', 'all' );
lnkup_t_assert( LNKup_Capabilities::can_access_org_workspace( $reviewer ), 'reviewer (no organizer role) can open the workspace' );
lnkup_t_assert( LNKup_Capabilities::is_team_only_member( $reviewer ), 'reviewer gets the staff workspace' );
lnkup_t_assert( LNKup_Capabilities::staff_can( $event, 'score_applications', $reviewer ), 'reviewer can score on their event' );
lnkup_t_assert( ! LNKup_Capabilities::staff_can( $event, 'manage_applications', $reviewer ), 'reviewer cannot decide applications' );
lnkup_t_assert( ! LNKup_Capabilities::staff_can( $event, 'view_financials', $reviewer ), 'reviewer cannot see financials' );
lnkup_t_assert( ! LNKup_Capabilities::staff_can( $other_event, 'review_applications', $reviewer ), 'reviewer has no access to another organization' );
lnkup_t_same( array( $event ), LNKup_Org_Teams::event_ids_for( $reviewer, 'review_applications' ), 'reviewer scope resolves to exactly their event' );
lnkup_t_assert( LNKup_Capabilities::staff_can( $event, 'manage_applications', $manager ), 'vendor manager can decide applications' );

/* ---------- review queue and workspace ---------- */
echo "\nReview workspace\n";
$other_vendor = lnkup_o_vendor( 'Retail' );
$other_app    = lnkup_o_apply( $other_vendor, $other_opp );
$queue        = lnkup_o_rest( $reviewer, 'GET', '/lnkup/v1/applications/review/queue', array( 'group' => 'pending' ) );
$queue_ids    = wp_list_pluck( (array) ( $queue->get_data()['items'] ?? array() ), 'id' );
lnkup_t_same( 200, $queue->get_status(), 'reviewer queue loads' );
lnkup_t_assert( in_array( $app_b, $queue_ids, true ), 'queue contains a pending application on the reviewer event' );
lnkup_t_assert( ! in_array( $other_app, $queue_ids, true ), 'queue excludes another organization' );
lnkup_t_same( 403, lnkup_o_rest( $reviewer, 'GET', '/lnkup/v1/applications/review/queue', array( 'event_id' => $other_event ) )->get_status(), 'filtering to an unrelated event is refused' );
lnkup_t_same( 403, lnkup_o_rest( $reviewer, 'GET', "/lnkup/v1/applications/{$other_app}/workspace" )->get_status(), 'workspace for another organization is refused' );
$ws = lnkup_o_rest( $reviewer, 'GET', "/lnkup/v1/applications/{$app_b}/workspace" );
lnkup_t_same( 200, $ws->get_status(), 'reviewer opens the workspace' );
lnkup_t_assert( in_array( 'score', (array) $ws->get_data()['actions'], true ) && ! in_array( 'approve', (array) $ws->get_data()['actions'], true ), 'reviewer actions: score but not approve' );
lnkup_t_same( 403, lnkup_o_rest( $reviewer, 'POST', "/lnkup/v1/applications/{$app_b}/decision", array( 'action' => 'approve' ) )->get_status(), 'reviewer cannot approve through the API' );

lnkup_t_same( 200, lnkup_o_rest( $organizer, 'POST', "/lnkup/v1/applications/{$app_b}/reviewers", array( 'user_id' => $reviewer ) )->get_status(), 'owner assigns the reviewer' );
lnkup_t_same( 400, lnkup_o_rest( $organizer, 'POST', "/lnkup/v1/applications/{$app_b}/reviewers", array( 'user_id' => $other_org ) )->get_status(), 'cannot assign someone without review access' );
$mine = lnkup_o_rest( $reviewer, 'GET', '/lnkup/v1/applications/review/queue', array( 'assigned' => 'me' ) );
lnkup_t_same( array( $app_b ), wp_list_pluck( (array) $mine->get_data()['items'], 'id' ), '"assigned to me" returns the assigned application' );

$rubric = LNKup_Application_Review::rubric_for_event( $event );
$scores = array();
foreach ( $rubric['criteria'] as $c ) {
	$scores[ $c['key'] ] = 4;
}
lnkup_t_same( 400, lnkup_o_rest( $reviewer, 'POST', "/lnkup/v1/applications/{$app_b}/score", array( 'scores' => array( 'category_fit' => 9 ), 'complete' => true ) )->get_status(), 'out-of-range score rejected' );
lnkup_t_same( 400, lnkup_o_rest( $reviewer, 'POST', "/lnkup/v1/applications/{$app_b}/score", array( 'scores' => array( 'category_fit' => 4 ), 'complete' => true ) )->get_status(), 'incomplete review cannot be submitted' );
$status_before = (string) get_post_meta( $app_b, '_lnkup_application_status', true );
$scored        = lnkup_o_rest( $reviewer, 'POST', "/lnkup/v1/applications/{$app_b}/score", array( 'scores' => $scores, 'recommendation' => 'approve', 'comment' => 'Strong fit', 'complete' => true ) );
lnkup_t_same( 200, $scored->get_status(), 'reviewer submits a complete score' );
lnkup_t_same( 4.0, (float) $scored->get_data()['weighted_score'], 'weighted score computed' );
lnkup_t_same( $status_before, (string) get_post_meta( $app_b, '_lnkup_application_status', true ), 'scoring never changes the application status' );
$owner_ws = lnkup_o_rest( $organizer, 'GET', "/lnkup/v1/applications/{$app_b}/workspace" )->get_data();
lnkup_t_same( 4.0, (float) ( $owner_ws['review']['average'] ?? 0 ), 'owner sees the consolidated average' );

$saved = lnkup_o_rest( $organizer, 'PUT', "/lnkup/v1/events/{$event}/rubric", array( 'criteria' => array( array( 'label' => 'Brand fit', 'weight' => 3 ), array( 'label' => 'Price point', 'weight' => 1 ) ) ) );
lnkup_t_same( array( 200, 1 ), array( $saved->get_status(), (int) ( $saved->get_data()['version'] ?? 0 ) ), 'owner saves a custom weighted rubric' );
lnkup_t_same( 403, lnkup_o_rest( $reviewer, 'PUT', "/lnkup/v1/events/{$event}/rubric", array( 'criteria' => array( 'X' ) ) )->get_status(), 'reviewer cannot change the rubric' );

/* ---------- bulk ---------- */
echo "\nBulk decisions\n";
$vendor_c = lnkup_o_vendor( 'Retail' );
$app_c    = lnkup_o_apply( $vendor_c, $opp );
$preview  = lnkup_o_rest( $manager, 'POST', '/lnkup/v1/applications/bulk/preview', array( 'action' => 'waitlist', 'ids' => array( $app_b, $app_c, $other_app, $app_a ) ) )->get_data();
lnkup_t_same( array( $app_b, $app_c ), wp_list_pluck( (array) $preview['eligible'], 'id' ), 'preview: only eligible applications on permitted events' );
lnkup_t_same( 2, count( (array) $preview['ineligible'] ), 'preview: other organization and already-approved are listed as skipped' );
update_post_meta( $app_c, '_lnkup_application_status', 'rejected' );
$applied = lnkup_o_rest( $manager, 'POST', '/lnkup/v1/applications/bulk/apply', array( 'token' => $preview['token'] ) )->get_data();
lnkup_t_same( array( 'updated' => 1, 'skipped' => 1 ), $applied['summary'] ?? null, 'apply re-checks each application (one changed since preview)' );
lnkup_t_same( 'waitlisted', (string) get_post_meta( $app_b, '_lnkup_application_status', true ), 'eligible application waitlisted' );
lnkup_t_same( 409, lnkup_o_rest( $manager, 'POST', '/lnkup/v1/applications/bulk/apply', array( 'token' => $preview['token'] ) )->get_status(), 'a preview token can only be used once' );
lnkup_t_assert( (int) get_post_meta( $app_b, LNKup_Waitlist_Offers::POSITION_META, true ) > 0, 'waitlisted application gets a queue position' );

/* ---------- waitlist offers ---------- */
echo "\nWaitlist offers\n";
$vendor_e = lnkup_o_vendor( 'Retail' );
$app_e    = lnkup_o_apply( $vendor_e, $opp );
LNKup_Applications::transition( $app_e, 'waitlisted' );
$vendor_d = lnkup_o_vendor( 'Retail' );
$app_d    = lnkup_o_apply( $vendor_d, $opp );
LNKup_Applications::transition( $app_d, 'approved' );
lnkup_t_same( 0, (int) LNKup_Opportunity_Inventory::summary( $opp )['available'], 'fixture: slot is full (2 of 2 held)' );
lnkup_t_same( 409, lnkup_o_rest( $organizer, 'POST', "/lnkup/v1/applications/{$app_b}/waitlist-offer", array( 'hours' => 24 ) )->get_status(), 'offer refused while the slot is full' );
LNKup_Applications::transition( $app_d, 'cancelled', array( 'reason' => 'test' ) );
$offer = lnkup_o_rest( $organizer, 'POST', "/lnkup/v1/applications/{$app_b}/waitlist-offer", array( 'hours' => 24 ) );
lnkup_t_same( 200, $offer->get_status(), 'offer made when a spot opens' );
lnkup_t_same( 'payment_required', (string) get_post_meta( $app_b, '_lnkup_application_status', true ), 'offered vendor holds the spot (payment required)' );
$due = strtotime( (string) get_post_meta( $app_b, '_lnkup_payment_due_at', true ) . ' UTC' );
lnkup_t_assert( abs( $due - ( time() + DAY_IN_SECONDS ) ) < 120, 'payment deadline matches the offer window' );
lnkup_t_same( 409, lnkup_o_rest( $organizer, 'POST', "/lnkup/v1/applications/{$app_b}/waitlist-offer", array( 'hours' => 24 ) )->get_status(), 'second offer to the same vendor refused' );

lnkup_t_same( 409, lnkup_o_rest( $organizer, 'POST', "/lnkup/v1/applications/{$app_e}/waitlist-offer", array( 'hours' => 24 ) )->get_status(), 'no overselling: second waitlisted vendor cannot take the held spot' );

$offers_table = LNKup_Organizer_OS_Tables::waitlist_offers_table();
$wpdb->query( $wpdb->prepare( "UPDATE {$offers_table} SET expires_at = %s WHERE application_id = %d AND status = 'offered'", gmdate( 'Y-m-d H:i:s', time() - 60 ), $app_b ) );
lnkup_t_same( 1, LNKup_Waitlist_Offers::expire_due_offers(), 'expired offer processed' );
lnkup_t_same( 'waitlisted', (string) get_post_meta( $app_b, '_lnkup_application_status', true ), 'unpaid vendor returns to the waitlist' );
$payment = LNKup_Payments::get_for_application( $app_b );
lnkup_t_assert( $payment && '' !== (string) get_post_meta( $payment->ID, '_lnkup_voided_at', true ), 'expired offer payment is voided' );
lnkup_t_assert( ! LNKup_Payments::mark_paid( $payment->ID ), 'a voided offer payment cannot be collected later' );
lnkup_t_same( 'waitlisted', (string) get_post_meta( $app_b, '_lnkup_application_status', true ), 'late payment attempt does not rebook the vendor' );
lnkup_t_assert( (int) get_post_meta( $app_b, LNKup_Waitlist_Offers::POSITION_META, true ) > 0, 'returned vendor keeps a waitlist position' );
lnkup_t_assert( is_wp_error( LNKup_Applications::transition( $app_e, 'payment_required' ) ), 'state machine unchanged: waitlisted cannot jump to payment_required' );
lnkup_t_assert( ! LNKup_Applications::can_transition( 'payment_required', 'waitlisted' ), 'TRANSITIONS table does not allow payment_required → waitlisted' );

$offer2 = lnkup_o_rest( $organizer, 'POST', "/lnkup/v1/applications/{$app_e}/waitlist-offer", array( 'hours' => 48 ) );
lnkup_t_same( 200, $offer2->get_status(), 'spot offered to the next vendor after expiry' );
$pay_e = LNKup_Payments::get_for_application( $app_e );
LNKup_Payments::mark_paid( $pay_e->ID );
lnkup_t_same( 'paid', (string) get_post_meta( $app_e, '_lnkup_application_status', true ), 'offered vendor pays and is confirmed' );
lnkup_t_same( 'accepted', (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$offers_table} WHERE application_id = %d ORDER BY id DESC LIMIT 1", $app_e ) ), 'offer marked accepted on payment' );
lnkup_t_same( '', (string) get_post_meta( $app_e, LNKup_Waitlist_Offers::POSITION_META, true ), 'confirmed vendor leaves the waitlist order' );

/* ---------- audit ---------- */
echo "\nAudit\n";
$audit = lnkup_o_rest( $organizer, 'GET', '/lnkup/v1/org/team/audit' );
$acts  = wp_list_pluck( (array) ( $audit->get_data()['items'] ?? array() ), 'action' );
lnkup_t_same( 200, $audit->get_status(), 'owner reads team activity' );
lnkup_t_assert( in_array( 'application_reviewer_assigned', $acts, true ) && in_array( 'waitlist_offer_created', $acts, true ) && in_array( 'application_bulk_decision', $acts, true ), 'review, bulk and offer actions are in the team log' );
lnkup_t_same( 403, lnkup_o_rest( $reviewer, 'GET', '/lnkup/v1/org/team/audit', array( 'owner_id' => $organizer ) )->get_status(), 'reviewer cannot read the team log' );

/* ---------- revocation ---------- */
echo "\nRevocation\n";
$rows = LNKup_Org_Teams::active_memberships( $organizer, $reviewer );
LNKup_Org_Teams::revoke( $organizer, (int) $rows[0]['id'], $organizer, 'test' );
lnkup_t_same( 403, lnkup_o_rest( $reviewer, 'GET', "/lnkup/v1/applications/{$app_c}/workspace" )->get_status(), 'revoked reviewer is refused on the next request' );
lnkup_t_assert( ! LNKup_Capabilities::can_access_org_workspace( $reviewer ), 'revoked reviewer loses workspace access' );

/* ---------- cleanup ---------- */
update_option( 'lnkup_settings', $original_settings, false );
foreach ( array( $organizer, $other_org ) as $uid ) {
	$wpdb->delete( LNKup_Trust_Tables::org_members_table(), array( 'org_owner_id' => $uid ) );
	$wpdb->delete( LNKup_Trust_Tables::org_invites_table(), array( 'org_owner_id' => $uid ) );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Organizer_OS_Tables::rubrics_table() . " WHERE scope_type = 'event' AND scope_id IN (%d, %d)", $event, $other_event ) );
foreach ( array( LNKup_Organizer_OS_Tables::review_assignments_table(), LNKup_Organizer_OS_Tables::reviews_table(), LNKup_Organizer_OS_Tables::waitlist_offers_table() ) as $table ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE event_id IN (%d, %d)", $event, $other_event ) );
}
$wpdb->delete( LNKup_Operations_Tables::booths_table(), array( 'event_id' => $event ) );
lnkup_t_finish();
