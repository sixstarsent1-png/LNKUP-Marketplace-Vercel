<?php
/**
 * Organizer Management OS Wave 2: event tasks, event readiness, per-event document review, Overview.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/organizer-os-wave2-test.php
 * Local wp-env only; settings are restored on exit.
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Organizer Management OS — Wave 2\n";

LNKup_Organizer_OS_Tables::maybe_upgrade();
if ( class_exists( 'LNKup_Passport_Tables' ) && method_exists( 'LNKup_Passport_Tables', 'create_tables' ) ) {
	LNKup_Passport_Tables::create_tables();
}
$original_settings = get_option( 'lnkup_settings', array() );

/**
 * @param array $flags Settings overrides.
 */
function lnkup_w2_flags( array $flags ): void {
	$settings = get_option( 'lnkup_settings', array() );
	update_option( 'lnkup_settings', array_merge( is_array( $settings ) ? $settings : array(), $flags ), false );
}

/**
 * @param string $type  Post type.
 * @param array  $meta  Meta.
 * @param string $title Title.
 */
function lnkup_w2_post( string $type, array $meta = array(), string $title = '' ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title ?: 'W2 ' . $type, 'meta_input' => $meta ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

/**
 * @param int    $user   Acting user.
 * @param string $method Method.
 * @param string $route  Route.
 * @param array  $body   Body or query.
 */
function lnkup_w2_rest( int $user, string $method, string $route, array $body = array() ) {
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
 * Team member through the real invite-accept path.
 */
function lnkup_w2_member( int $owner, int $member, string $role, string $scope = 'all', int $scope_id = 0 ): void {
	global $wpdb;
	$raw = 'w2token' . wp_generate_password( 24, false );
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

lnkup_w2_flags(
	array(
		'trust_org_teams'                   => true,
		'organizer_ops_v2_enabled'          => false,
		'organizer_document_review_enabled' => false,
		'passport_enabled'                  => true,
		'document_vault_sharing'            => true,
	)
);

$organizer = lnkup_t_user( 'lnkup_organizer' );
$other_org = lnkup_t_user( 'lnkup_organizer' );
$ops_staff = lnkup_t_user( 'subscriber' );
$outsider  = lnkup_t_user( 'subscriber' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );
$profile   = lnkup_w2_post( 'lnkup_vendor_profile', array( '_lnkup_owner_user_id' => $vendor, '_lnkup_business_name' => 'W2 Vendor' ), 'W2 Vendor' );
wp_set_object_terms( $profile, array( 'Retail' ), 'lnkup_vendor_category' );

$event_date = gmdate( 'Y-m-d', strtotime( '+20 days' ) );
$event      = lnkup_w2_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_event_date' => $event_date, '_lnkup_city' => 'Austin', '_lnkup_state' => 'TX' ), 'W2 Market' );
$opp        = lnkup_w2_post(
	'lnkup_opportunity',
	array(
		'_lnkup_event_id'             => $event,
		'_lnkup_organizer_user_id'    => $organizer,
		'_lnkup_opportunity_status'   => 'open',
		'_lnkup_vendor_fee'           => '75',
		'_lnkup_spots_total'          => 3,
		'_lnkup_spots_remaining'      => 3,
		'_lnkup_document_mode'        => 'custom',
		'_lnkup_required_documents'   => 'certificate_of_insurance',
		'_lnkup_application_deadline' => gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
	),
	'Booth'
);
wp_set_object_terms( $opp, array( 'Retail' ), 'lnkup_vendor_category' );
LNKup_Opportunity_Inventory::recompute( $opp );
LNKup_Marketplace_Index::sync_opportunity( $opp );
$other_event = lnkup_w2_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $other_org, '_lnkup_event_date' => $event_date ), 'W2 Other' );

lnkup_w2_member( $organizer, $ops_staff, 'ops_staff', 'event', $event );
$vm = lnkup_t_user( 'subscriber' );
lnkup_w2_member( $organizer, $vm, 'vendor_manager', 'event', $event );

/* ---------- flags ---------- */
echo "\nFlags\n";
lnkup_t_same( 404, lnkup_w2_rest( $organizer, 'GET', "/lnkup/v1/events/{$event}/tasks" )->get_status(), 'tasks are hidden while the flag is off' );
lnkup_t_same( 404, lnkup_w2_rest( $organizer, 'GET', "/lnkup/v1/events/{$event}/documents" )->get_status(), 'document review is hidden while its flag is off' );
lnkup_w2_flags( array( 'organizer_ops_v2_enabled' => true, 'organizer_document_review_enabled' => true ) );
lnkup_t_assert( LNKup_Organizer_OS_Tables::ready( '2' ), 'schema v2 installed' );

/* ---------- tasks ---------- */
echo "\nTasks\n";
$created = lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/events/{$event}/tasks", array( 'title' => 'Print vendor map', 'assignee_user_id' => $vm, 'due_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ), 'priority' => 'high' ) );
lnkup_t_same( 200, $created->get_status(), 'owner creates a task assigned to a team member' );
$task_id = (int) ( $created->get_data()['id'] ?? 0 );
lnkup_t_assert( ! empty( $created->get_data()['overdue'] ), 'past due date reads as overdue' );
lnkup_t_same( 400, lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/events/{$event}/tasks", array( 'title' => 'X', 'assignee_user_id' => $outsider ) )->get_status(), 'cannot assign someone without access to the event' );
lnkup_t_same( 400, lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/events/{$event}/tasks", array( 'title' => '' ) )->get_status(), 'title is required' );
lnkup_t_same( 403, lnkup_w2_rest( $other_org, 'GET', "/lnkup/v1/events/{$event}/tasks" )->get_status(), 'another organizer cannot read the event tasks' );
lnkup_t_same( 404, lnkup_w2_rest( $outsider, 'PATCH', "/lnkup/v1/tasks/{$task_id}", array( 'status' => 'done' ) )->get_status(), 'an outsider cannot see the task' );
lnkup_t_same( 200, lnkup_w2_rest( $ops_staff, 'GET', "/lnkup/v1/events/{$event}/tasks" )->get_status(), 'event staff can read tasks' );
$mine = lnkup_w2_rest( $vm, 'GET', '/lnkup/v1/tasks', array( 'view' => 'mine' ) )->get_data();
lnkup_t_same( array( $task_id ), wp_list_pluck( (array) ( $mine['items'] ?? array() ), 'id' ), 'assignee sees the task in My tasks' );
lnkup_t_same( 1, (int) ( $mine['counts']['overdue'] ?? 0 ), 'overdue count for the assignee scope' );
lnkup_t_same( 403, lnkup_w2_rest( $vm, 'PATCH', "/lnkup/v1/tasks/{$task_id}", array( 'title' => 'Renamed' ) )->get_status(), 'assignee without edit_ops cannot edit task fields' );
lnkup_t_same( 'done', (string) ( lnkup_w2_rest( $vm, 'PATCH', "/lnkup/v1/tasks/{$task_id}", array( 'status' => 'done' ) )->get_data()['status'] ?? '' ), 'assignee can mark their task done' );
lnkup_t_same( 403, lnkup_w2_rest( $vm, 'GET', "/lnkup/v1/financial/events/{$event}/financials" )->get_status(), 'a task never grants financial access' );
lnkup_t_same( 'Print vendor map', (string) ( lnkup_w2_rest( $ops_staff, 'PATCH', "/lnkup/v1/tasks/{$task_id}", array( 'title' => 'Print vendor map' ) )->get_data()['title'] ?? '' ), 'operations staff (edit_ops) can edit tasks' );
lnkup_t_same( 200, lnkup_w2_rest( $ops_staff, 'POST', "/lnkup/v1/tasks/{$task_id}/comments", array( 'body' => 'Printed 40 copies' ) )->get_status(), 'event staff can comment' );

$template = lnkup_w2_rest( $organizer, 'POST', '/lnkup/v1/task-templates', array( 'name' => 'Market prep', 'items' => array( array( 'title' => 'Confirm map', 'offset_days_before_event' => 14 ), array( 'title' => 'Send arrival notes', 'offset_days_before_event' => 3 ) ) ) );
$tpl_id   = (int) ( $template->get_data()['id'] ?? 0 );
lnkup_t_same( 200, $template->get_status(), 'organizer saves a task template' );
lnkup_t_same( 403, lnkup_w2_rest( $ops_staff, 'GET', '/lnkup/v1/task-templates' )->get_status(), 'templates belong to organizer accounts' );
$applied = lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/events/{$event}/tasks/apply-template", array( 'template_id' => $tpl_id ) )->get_data();
lnkup_t_same( 2, (int) ( $applied['created'] ?? 0 ), 'template creates two tasks' );
$due_map = array();
foreach ( (array) ( $applied['tasks'] ?? array() ) as $t ) {
	$due_map[ $t['title'] ] = substr( (string) $t['due_at'], 0, 10 );
}
lnkup_t_same( gmdate( 'Y-m-d', strtotime( $event_date . ' -14 days' ) ), $due_map['Confirm map'] ?? '', 'due date counted back 14 days from the event' );
$again = lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/events/{$event}/tasks/apply-template", array( 'template_id' => $tpl_id ) )->get_data();
lnkup_t_same( array( 0, 2 ), array( (int) $again['created'], (int) $again['skipped'] ), 'applying the template twice skips existing tasks' );
lnkup_t_same( 404, lnkup_w2_rest( $other_org, 'POST', "/lnkup/v1/events/{$other_event}/tasks/apply-template", array( 'template_id' => $tpl_id ) )->get_status(), 'a template cannot be applied to another organizer\'s event' );

/* ---------- readiness ---------- */
echo "\nReadiness\n";
$ready = lnkup_w2_rest( $organizer, 'GET', "/lnkup/v1/events/{$event}/readiness" )->get_data();
$items = array();
foreach ( (array) ( $ready['items'] ?? array() ) as $item ) {
	$items[ $item['key'] ] = $item;
}
lnkup_t_same( 11, count( $items ), 'eleven checklist items' );
lnkup_t_same( 'incomplete', $items['event_details']['state'] ?? '', 'missing venue keeps event details incomplete' );
lnkup_t_same( 'verified', $items['booth_inventory']['state'] ?? '', 'capacity verifies booth inventory' );
lnkup_t_same( 'verified', $items['team_assigned']['state'] ?? '', 'a team member with access verifies team assignment' );
lnkup_t_same( 20, (int) $ready['days_until'], 'countdown in days' );
lnkup_t_same( 409, lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/events/{$event}/readiness/event_details", array( 'state' => 'confirmed' ) )->get_status(), 'a system-checked item cannot be confirmed by hand' );
$marked = lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/events/{$event}/readiness/setup_instructions_sent", array( 'state' => 'confirmed', 'note' => 'Emailed PDF' ) )->get_data();
$state  = '';
foreach ( (array) ( $marked['items'] ?? array() ) as $item ) {
	if ( 'setup_instructions_sent' === $item['key'] ) {
		$state = $item['state'];
	}
}
lnkup_t_same( 'confirmed', $state, 'a manual-only item can be confirmed' );
lnkup_t_same( 403, lnkup_w2_rest( $vm, 'POST', "/lnkup/v1/events/{$event}/readiness/booth_assignments", array( 'state' => 'not_applicable' ) )->get_status(), 'readiness marks need edit_ops' );
update_post_meta( $event, '_lnkup_venue_name', 'Republic Square' );
$after = LNKup_Event_Readiness::evaluate( $event );
lnkup_t_same( 'verified', $after['items'][0]['state'], 'cache is flushed when event details change' );

/* ---------- documents ---------- */
echo "\nDocuments\n";
wp_set_current_user( $vendor );
$app = LNKup_Applications::submit_with_profile( $vendor, $opp, array( 'accepted_terms' => true, 'selected_category' => 'Retail' ) );
wp_set_current_user( 0 );
$app = is_wp_error( $app ) ? 0 : (int) $app;
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $app );
lnkup_t_assert( $app > 0, 'fixture: vendor applied' );
LNKup_Applications::transition( $app, 'approved' );
$rows = LNKup_Organizer_Documents::requirements_for_application( $app, $organizer );
lnkup_t_same( 'missing', $rows[0]['status'] ?? '', 'required document starts missing' );
lnkup_t_same( 200, lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/applications/{$app}/documents/request", array( 'types' => array( 'certificate_of_insurance' ), 'message' => 'Need COI naming the city' ) )->get_status(), 'organizer requests the document' );
lnkup_t_same( 429, lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/applications/{$app}/documents/request", array( 'types' => array( 'certificate_of_insurance' ) ) )->get_status(), 'a second request the same day is throttled' );
lnkup_t_same( 400, lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/applications/{$app}/documents/request", array( 'types' => array( 'health_permit' ) ) )->get_status(), 'only required types can be requested' );
lnkup_t_same( 403, lnkup_w2_rest( $ops_staff, 'POST', "/lnkup/v1/applications/{$app}/documents/request", array( 'types' => array( 'certificate_of_insurance' ) ) )->get_status(), 'operations staff cannot review documents' );

$doc = lnkup_w2_post( 'lnkup_document', array( '_lnkup_vendor_user_id' => $vendor, '_lnkup_vendor_profile_id' => $profile, '_lnkup_document_type' => 'certificate_of_insurance', '_lnkup_verification_status' => 'pending', '_lnkup_expiration_date' => gmdate( 'Y-m-d', strtotime( '+200 days' ) ) ), 'W2 COI' );
$rows = LNKup_Organizer_Documents::requirements_for_application( $app, $organizer );
lnkup_t_same( '', $rows[0]['download_url'], 'no file link before the vendor shares' );
$share = LNKup_Document_Vault::share( $profile, $doc, $app, $vendor );
lnkup_t_assert( is_int( $share ) && $share > 0, 'fixture: vendor shares the document through Passport' );
$rows = LNKup_Organizer_Documents::requirements_for_application( $app, $organizer );
lnkup_t_same( 'submitted', $rows[0]['status'], 'shared document is ready for review' );
lnkup_t_assert( '' !== $rows[0]['download_url'], 'owner gets the signed Passport link' );
lnkup_t_same( '', LNKup_Organizer_Documents::requirements_for_application( $app, $ops_staff )[0]['download_url'], 'team members never get the file link' );
lnkup_t_same( 400, lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/applications/{$app}/documents/certificate_of_insurance/review", array( 'decision' => 'replacement' ) )->get_status(), 'asking for a replacement needs a reason' );
lnkup_t_same( 'accepted', (string) ( lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/applications/{$app}/documents/certificate_of_insurance/review", array( 'decision' => 'accept' ) )->get_data()['items'][0]['status'] ?? '' ), 'organizer accepts the document' );
lnkup_t_same( 'pending', (string) get_post_meta( $doc, '_lnkup_verification_status', true ), 'platform verification status is untouched' );
update_post_meta( $doc, '_lnkup_expiration_date', gmdate( 'Y-m-d', strtotime( '-3 days' ) ) );
lnkup_t_same( 'expired', LNKup_Organizer_Documents::requirements_for_application( $app, $organizer )[0]['status'], 'expiry wins over an earlier acceptance' );
lnkup_t_same( 409, lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/applications/{$app}/documents/certificate_of_insurance/review", array( 'decision' => 'accept' ) )->get_status(), 'an expired document cannot be accepted' );
lnkup_t_same( 403, lnkup_w2_rest( $vm, 'POST', "/lnkup/v1/applications/{$app}/documents/certificate_of_insurance/waive", array( 'reason' => 'Covered by venue' ) )->get_status(), 'a vendor manager cannot waive' );
lnkup_t_same( 'waived', (string) ( lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/applications/{$app}/documents/certificate_of_insurance/waive", array( 'reason' => 'Covered by venue policy' ) )->get_data()['items'][0]['status'] ?? '' ), 'owner waives the requirement' );
lnkup_t_same( 403, lnkup_w2_rest( $other_org, 'GET', "/lnkup/v1/events/{$event}/documents" )->get_status(), 'another organizer cannot read the document dashboard' );

/* ---------- overview ---------- */
echo "\nOverview\n";
lnkup_t_same( 200, lnkup_w2_rest( $organizer, 'POST', "/lnkup/v1/events/{$event}/tasks", array( 'title' => 'Book tents', 'due_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-2 days' ) ) ) )->get_status(), 'fixture: an overdue task' );
$overview = lnkup_w2_rest( $organizer, 'GET', '/lnkup/v1/organizer/overview' )->get_data();
$keys     = wp_list_pluck( (array) ( $overview['attention'] ?? array() ), 'key' );
lnkup_t_assert( in_array( 'unpaid', $keys, true ), 'overview flags the approved vendor who has not paid' );
lnkup_t_assert( in_array( 'overdue_tasks', $keys, true ), 'overview flags overdue tasks' );
lnkup_t_assert( ! in_array( 'pending_reviews', $keys, true ), 'no pending-review row when nothing is pending' );
lnkup_t_assert( ! in_array( 'readiness_' . $event, $keys, true ), 'an event 20 days out is outside the 14-day readiness window' );
lnkup_t_same( $event, (int) ( $overview['upcoming'][0]['event_id'] ?? 0 ), 'upcoming event card' );
lnkup_t_assert( (int) $overview['upcoming'][0]['readiness_percent'] < 100, 'card shows the real readiness percent' );
lnkup_t_same( 403, lnkup_w2_rest( $vendor, 'GET', '/lnkup/v1/organizer/overview' )->get_status(), 'vendors cannot read the organizer overview' );

/* ---------- pilot allowlist ---------- */
echo "\nPilot\n";
lnkup_w2_flags( array( 'organizer_ops_v2_enabled' => false, 'application_review_v2_enabled' => false, 'organizer_os_pilot_user_ids' => (string) $organizer ) );
lnkup_t_assert( LNKup_Feature_Flags::organizer_os( LNKup_Feature_Flags::ORGANIZER_OPS, $organizer ), 'pilot organizer gets the feature while the global flag is off' );
lnkup_t_assert( LNKup_Feature_Flags::organizer_os( LNKup_Feature_Flags::ORGANIZER_OPS, $vm ), 'pilot organizer\'s team member gets it too' );
lnkup_t_assert( ! LNKup_Feature_Flags::organizer_os( LNKup_Feature_Flags::ORGANIZER_OPS, $other_org ), 'other organizers do not' );
lnkup_t_same( 200, lnkup_w2_rest( $organizer, 'GET', "/lnkup/v1/events/{$event}/readiness" )->get_status(), 'pilot organizer reaches readiness' );
lnkup_t_same( 404, lnkup_w2_rest( $other_org, 'GET', "/lnkup/v1/events/{$other_event}/readiness" )->get_status(), 'non-pilot organizer still sees the feature as off' );
lnkup_t_assert( LNKup_Feature_Flags::organizer_os_anywhere( LNKup_Feature_Flags::ORGANIZER_OPS ), 'crons run while a pilot exists' );

/* ---------- reviewer email ---------- */
echo "\nReviewer email\n";
$GLOBALS['lnkup_w2_mail'] = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$GLOBALS['lnkup_w2_mail'][] = $atts;
		return true;
	},
	10,
	2
);
LNKup_Applications::transition( $app, 'cancelled', array( 'reason' => 'test' ) );
wp_set_current_user( $vendor );
$app3 = LNKup_Applications::submit_with_profile( $vendor, $opp, array( 'accepted_terms' => true, 'selected_category' => 'Retail' ) );
wp_set_current_user( 0 );
$app3 = is_wp_error( $app3 ) ? 0 : (int) $app3;
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $app3 );
$GLOBALS['lnkup_w2_mail'] = array();
lnkup_t_same( true, LNKup_Application_Review::assign_reviewer( $app3, $vm, $organizer ), 'owner assigns the vendor manager to review' );
$to = array_map(
	static function ( $mail ) {
		return is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'];
	},
	$GLOBALS['lnkup_w2_mail']
);
lnkup_t_assert( in_array( get_userdata( $vm )->user_email, $to, true ), 'the reviewer is emailed about the assignment' );

/* ---------- cleanup ---------- */
update_option( 'lnkup_settings', $original_settings, false );
foreach ( array( $organizer, $other_org ) as $uid ) {
	$wpdb->delete( LNKup_Trust_Tables::org_members_table(), array( 'org_owner_id' => $uid ) );
	$wpdb->delete( LNKup_Trust_Tables::org_invites_table(), array( 'org_owner_id' => $uid ) );
	$wpdb->delete( LNKup_Organizer_OS_Tables::task_templates_table(), array( 'owner_user_id' => $uid ) );
}
$task_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Organizer_OS_Tables::event_tasks_table() . ' WHERE event_id IN (%d, %d)', $event, $other_event ) );
foreach ( $task_ids as $tid ) {
	$wpdb->delete( LNKup_Organizer_OS_Tables::task_comments_table(), array( 'task_id' => (int) $tid ) );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Organizer_OS_Tables::event_tasks_table() . ' WHERE event_id IN (%d, %d)', $event, $other_event ) );
$wpdb->delete( LNKup_Organizer_OS_Tables::document_reviews_table(), array( 'event_id' => $event ) );
if ( $share && ! is_wp_error( $share ) ) {
	$wpdb->delete( LNKup_Passport_Tables::share_table(), array( 'id' => (int) $share ) );
}
lnkup_t_finish();
