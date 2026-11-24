<?php
/**
 * Event Creation OS 3.0 — builder drafts, autosave revisions, validation, publish, edit,
 * duplication and templates. All fixtures are removed.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/event-builder-test.php
 */

require_once __DIR__ . '/_marketing_helpers.php';
global $wpdb;

LNKup_Event_OS_Flags::override( 'event_builder_v3', true );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () {
	foreach ( get_posts( array( 'post_type' => LNKup_Event_Builder_Drafts::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_lnkup_test_run', 'meta_value' => '1' ) ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	LNKup_Event_OS_Flags::override( 'event_builder_v3', null );
};

/**
 * REST call as a user.
 *
 * @return array{status:int,data:mixed}
 */
function lnkup_eb_rest( string $method, string $route, array $body = array(), int $user = 0 ): array {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, '/lnkup/v1/organizer/builder' . $route );
	if ( 'GET' === $method ) {
		$req->set_query_params( $body );
	} else {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	wp_set_current_user( 0 );
	$data = $res->get_data();
	if ( is_array( $data ) && isset( $data['draft']['id'] ) ) {
		update_post_meta( (int) $data['draft']['id'], '_lnkup_test_run', '1' );
	}
	return array( 'status' => $res->get_status(), 'data' => $data );
}

/**
 * Track published events for cleanup.
 */
function lnkup_eb_track_event( int $event_id ): void {
	if ( ! $event_id ) {
		return;
	}
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $event_id );
	foreach ( LNKup_Event_Builder_Editor::opportunities( $event_id ) as $opp ) {
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $opp );
	}
}

$org   = lnkup_t_user( 'lnkup_organizer' );
$other = lnkup_t_user( 'lnkup_organizer' );
$vend  = lnkup_t_user( 'lnkup_vendor' );
$date  = gmdate( 'Y-m-d', strtotime( '+30 days' ) );

echo "Access\n";
LNKup_Event_OS_Flags::override( 'event_builder_v3', false );
lnkup_t_assert( in_array( lnkup_eb_rest( 'GET', '/drafts', array(), $org )['status'], array( 401, 403 ), true ), 'builder is off without the flag' );
LNKup_Event_OS_Flags::override( 'event_builder_v3', true );
lnkup_t_assert( in_array( lnkup_eb_rest( 'GET', '/drafts', array(), $vend )['status'], array( 401, 403 ), true ), 'vendors cannot use the builder' );
lnkup_t_assert( in_array( lnkup_eb_rest( 'GET', '/drafts', array(), 0 )['status'], array( 401, 403 ), true ), 'guests cannot use the builder' );

echo "Drafts and autosave\n";
$r     = lnkup_eb_rest( 'POST', '/drafts', array( 'method' => 'single', 'quick' => true ), $org );
$draft = $r['data']['draft'] ?? array();
lnkup_t_same( 201, $r['status'], 'draft created' );
lnkup_t_same( 1, $draft['revision'] ?? 0, 'first revision is 1' );
lnkup_t_same( false, $r['data']['report']['publishable'] ?? null, 'empty draft is not publishable' );
lnkup_t_assert( ( $r['data']['report']['progress'] ?? 100 ) < 100, 'empty draft progress is below 100' );
$id = (int) $draft['id'];
lnkup_t_same( LNKup_Event_Builder_Drafts::POST_TYPE, get_post_type( $id ), 'draft is a private draft post, not an event' );
lnkup_t_assert( ! in_array( $id, get_posts( array( 'post_type' => 'lnkup_event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ), true ), 'draft never appears in event queries' );
lnkup_t_same( 404, lnkup_eb_rest( 'GET', '/drafts/' . $id, array(), $other )['status'], 'another organizer cannot open the draft' );

$patch = array(
	'event'   => array( 'name' => 'Builder Test Fest', 'date' => $date, 'start_time' => '10:00', 'end_time' => '18:00', 'city' => 'Charlotte', 'state' => 'NC', 'venue_name' => 'Test Park', 'description' => str_repeat( 'Food, retail and art vendors. ', 4 ), 'attendance' => array( 'mode' => 'range', 'min' => 2000, 'max' => 4000 ) ),
	'options' => array(
		array( 'key' => 'ft', 'name' => 'Food Truck — Standard', 'category' => 'Food Trucks', 'fee' => 150, 'spots' => 8, 'width_ft' => 10, 'depth_ft' => 20, 'amenities' => array( 'electricity', 'bogus' ) ),
		array( 'key' => 'rt', 'name' => 'Retail', 'category' => 'Retail', 'fee' => 0, 'spots' => 12 ),
	),
	'applications' => array(
		'mode'      => 'review',
		'questions' => array( array( 'id' => 'power', 'label' => 'Need power?', 'type' => 'yesno', 'required' => true ), array( 'id' => 'len', 'label' => 'Truck length', 'type' => 'number', 'scope' => 'Food Trucks' ) ),
		'documents' => array( 'mode' => 'custom', 'items' => array( array( 'key' => 'health_permit', 'timing' => 'before_approval' ), array( 'key' => 'nonsense' ) ) ),
	),
);
$s = lnkup_eb_rest( 'PATCH', '/drafts/' . $id, array( 'revision' => 1, 'patch' => $patch ), $org );
lnkup_t_same( 200, $s['status'], 'autosave accepted with the current revision' );
lnkup_t_same( 2, $s['data']['draft']['revision'] ?? 0, 'revision increments' );
lnkup_t_same( array( 'electricity' ), $s['data']['draft']['state']['options'][0]['amenities'] ?? null, 'unknown amenities are dropped' );
lnkup_t_same( array( 'health_permit' ), wp_list_pluck( $s['data']['draft']['state']['applications']['documents']['items'] ?? array(), 'key' ), 'unknown documents are dropped' );
$stale = lnkup_eb_rest( 'PATCH', '/drafts/' . $id, array( 'revision' => 1, 'patch' => array( 'event' => array( 'name' => 'Overwrite' ) ) ), $org );
lnkup_t_same( 409, $stale['status'], 'stale revision is rejected (no silent overwrite)' );
lnkup_t_same( 'Builder Test Fest', LNKup_Event_Builder_Drafts::get( $id )['state']['event']['name'], 'newer work is kept' );
$sum = $s['data']['report']['summary'] ?? array();
lnkup_t_same( array( 2, 20, 12, 1200.0 ), array( $sum['types'] ?? 0, $sum['spaces'] ?? 0, $sum['free_spaces'] ?? 0, (float) ( $sum['max_base_fees'] ?? 0 ) ), 'inventory summary counts types, spaces, free spaces and maximum base fees' );
$fees = $s['data']['fees'] ?? array();
$calc = LNKup_Fee_Engine::calculate( array( 'vendor_fee' => 150, 'organizer_id' => $org, 'category' => 'Food Trucks' ) );
lnkup_t_same( (float) $calc['booth_reservation_total'], (float) ( $fees[0]['vendor_total'] ?? -1 ), 'fee preview matches the Fee Engine' );
lnkup_t_same( true, $fees[1]['free'] ?? false, 'free option has no fees' );

echo "Validation\n";
$v = static fn( array $patch ) => LNKup_Event_Builder_Validator::report( LNKup_Event_Builder_Schema::merge( LNKup_Event_Builder_Drafts::get( $id )['state'], $patch ), $org );
$check = static function ( array $report, string $key ) {
	foreach ( $report['checks'] as $c ) {
		if ( $c['key'] === $key ) {
			return $c;
		}
	}
	return null;
};
lnkup_t_same( false, $check( $v( array( 'event' => array( 'date' => '2020-01-01' ) ) ), 'date_future' )['ok'] ?? null, 'past date blocks publishing' );
lnkup_t_same( true, $check( $v( array( 'event' => array( 'start_time' => '20:00', 'end_time' => '02:00' ) ) ), 'date_order' )['ok'] ?? null, 'overnight event without end date is valid' );
lnkup_t_same( false, $check( $v( array( 'event' => array( 'end_date' => $date, 'start_time' => '20:00', 'end_time' => '02:00' ) ) ), 'date_order' )['ok'] ?? null, 'same-day end before start is invalid' );
lnkup_t_same( 'confirm', $v( array( 'event' => array( 'city' => 'Atlanta', 'state' => 'GE' ) ) )['location']['state'] ?? '', 'malformed state needs confirmation' );
lnkup_t_same( false, $check( $v( array( 'event' => array( 'name' => 'Rodeo: Houston', 'city' => 'Charlotte', 'state' => 'NC' ) ) ), 'geo_conflict' )['ok'] ?? null, 'name mentioning another market city is flagged' );
lnkup_t_same( false, $check( $v( array( 'event' => array( 'name' => 'Fest | Six Stars | Sat' ) ) ), 'name_clean' )['ok'] ?? null, 'metadata-stuffed names are flagged' );
lnkup_t_same( false, $check( $v( array( 'options' => array( array( 'key' => 'zero', 'category' => 'Retail', 'spots' => 0 ) ) ) ), 'option_zero' )['ok'] ?? null, 'options need spaces' );
$rep = $v( array() );
lnkup_t_same( true, $rep['publishable'], 'complete draft is publishable' );
lnkup_t_same( 100, $rep['progress'], 'progress reaches 100 only when publishable' );
lnkup_t_assert( null !== $check( $rep, 'payouts' ), 'paid options show the payout readiness item (advisory)' );

echo "Preview\n";
$guest = lnkup_eb_rest( 'GET', '/drafts/' . $id . '/preview', array( 'mode' => 'guest' ), $org )['data'];
$vendorp = lnkup_eb_rest( 'GET', '/drafts/' . $id . '/preview', array( 'mode' => 'vendor' ), $org )['data'];
lnkup_t_same( array( true, true ), array( array_key_exists( 'price', $guest['options'][0] ) && null === $guest['options'][0]['price'], array_key_exists( 'starting', $guest ) && null === $guest['starting'] ), 'guest preview hides prices' );
lnkup_t_same( 150.0, (float) ( $vendorp['options'][0]['price'] ?? 0 ), 'vendor preview shows prices' );
lnkup_t_assert( false !== strpos( (string) ( $vendorp['attendance'] ?? '' ), 'estimate' ), 'attendance is labeled as an organizer estimate' );

echo "Publish\n";
lnkup_eb_rest( 'PATCH', '/drafts/' . $id, array( 'revision' => 2, 'patch' => array( 'sources' => array( array( 'url' => 'https://posh.vip/e/builder-test' ) ) ) ), $org );
$p = lnkup_eb_rest( 'POST', '/drafts/' . $id . '/publish', array(), $org );
lnkup_t_same( 422, $p['status'], 'an external link requires the organizer attestation' );
lnkup_eb_rest( 'PATCH', '/drafts/' . $id, array( 'revision' => 3, 'patch' => array( 'publish' => array( 'attest' => true ) ) ), $org );
$p     = lnkup_eb_rest( 'POST', '/drafts/' . $id . '/publish', array(), $org );
$event = (int) ( $p['data']['event_id'] ?? 0 );
lnkup_eb_track_event( $event );
lnkup_t_same( 200, $p['status'], 'publish succeeds' );
lnkup_t_same( 'publish', get_post_status( $event ), 'event is live' );
$opps = LNKup_Event_Builder_Editor::opportunities( $event );
lnkup_t_same( 2, count( $opps ), 'two vendor options created' );
lnkup_t_same( array( 150.0, 8 ), array( (float) get_post_meta( $opps[0], '_lnkup_vendor_fee', true ), (int) get_post_meta( $opps[0], '_lnkup_spots_total', true ) ), 'price and spaces stored on the option' );
$q0 = LNKup_Application_Questions::get_for_opportunity( $opps[0] );
$q1 = LNKup_Application_Questions::get_for_opportunity( $opps[1] );
lnkup_t_same( array( 2, 1 ), array( count( $q0 ), count( $q1 ) ), 'category-scoped question only on the matching option' );
lnkup_t_same( array( 'Yes', 'No' ), $q0[0]['options'] ?? null, 'yes/no maps to a choice question' );
lnkup_t_same( 'health_permit', (string) get_post_meta( $opps[0], '_lnkup_required_documents', true ), 'required documents saved' );
lnkup_t_same( 1, (int) get_post_meta( $opps[0], LNKup_Application_Questions::DOCS_GATE, true ), 'documents due before approval gate applying' );
lnkup_t_same( array( 10, 20 ), array( (int) get_post_meta( $opps[0], '_lnkup_booth_width_ft', true ), (int) get_post_meta( $opps[0], '_lnkup_booth_depth_ft', true ) ), 'booth dimensions saved' );
lnkup_t_same( 'https://posh.vip/e/builder-test', (string) get_post_meta( $event, '_lnkup_ticketing_url', true ), 'primary external link kept' );
lnkup_t_same( '2000', (string) get_post_meta( $event, '_lnkup_expected_attendance', true ), 'range stores the conservative minimum' );
lnkup_t_same( 'trash', get_post_status( $id ), 'draft leaves the draft portfolio after publishing' );
$again = LNKup_Event_Builder_Publisher::publish( $id, $org );
lnkup_t_same( array( $event, true ), array( (int) ( $again['event_id'] ?? 0 ), (bool) ( $again['idempotent'] ?? false ) ), 'repeat publish returns the same event' );

echo "Edit 2.0\n";
$e    = lnkup_eb_rest( 'POST', '/events/' . $event . '/edit', array(), $org );
$eid  = (int) ( $e['data']['draft']['id'] ?? 0 );
lnkup_t_same( $event, (int) ( $e['data']['draft']['source_event'] ?? 0 ), 'edit opens a draft for the event' );
lnkup_t_same( 'Builder Test Fest', $e['data']['draft']['state']['event']['name'] ?? '', 'edit draft loads live values' );
lnkup_t_same( 403, lnkup_eb_rest( 'POST', '/events/' . $event . '/edit', array(), $other )['status'], 'another organizer cannot edit the event' );
$app = wp_insert_post( array( 'post_type' => 'lnkup_application', 'post_status' => 'publish', 'post_title' => 'EB booked' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $app );
update_post_meta( $app, '_lnkup_opportunity_id', $opps[0] );
update_post_meta( $app, '_lnkup_application_status', 'paid' );
$opts = $e['data']['draft']['state']['options'];
$opts[0]['fee'] = 175;
lnkup_eb_rest( 'PATCH', '/drafts/' . $eid, array( 'revision' => (int) $e['data']['draft']['revision'], 'patch' => array( 'event' => array( 'venue_name' => 'New Park' ), 'options' => $opts ) ), $org );
$review = lnkup_eb_rest( 'GET', '/drafts/' . $eid . '/review', array(), $org )['data'];
lnkup_t_same( array( true, 1 ), array( $review['material'] ?? false, $review['booked'] ?? 0 ), 'venue and price changes are material with one booked vendor' );
lnkup_t_same( 409, lnkup_eb_rest( 'POST', '/drafts/' . $eid . '/publish', array(), $org )['status'], 'material change with booked vendors needs confirmation' );
lnkup_t_same( 'Test Park', (string) get_post_meta( $event, '_lnkup_venue_name', true ), 'live event unchanged until confirmed' );
$ok = lnkup_eb_rest( 'POST', '/drafts/' . $eid . '/publish', array( 'confirm' => true ), $org );
lnkup_t_same( 200, $ok['status'], 'confirmed edit applies' );
lnkup_t_same( array( 'New Park', 175.0 ), array( (string) get_post_meta( $event, '_lnkup_venue_name', true ), (float) get_post_meta( $opps[0], '_lnkup_vendor_fee', true ) ), 'venue and price updated' );
lnkup_t_same( 'paid', (string) get_post_meta( $app, '_lnkup_application_status', true ), 'existing booking untouched' );

echo "Duplication 2.0\n";
$d = lnkup_eb_rest( 'POST', '/drafts', array( 'method' => 'duplicate', 'duplicate_event' => $event, 'components' => array( 'options', 'questions', 'description' ) ), $org );
$ds = $d['data']['draft']['state'] ?? array();
lnkup_t_same( 201, $d['status'], 'duplicate creates a draft, not a live event' );
lnkup_t_same( array( '', array(), 0 ), array( $ds['event']['date'] ?? 'x', $ds['sources'] ?? 'x', (int) ( $ds['options'][0]['opportunity_id'] ?? -1 ) ), 'date, external links and option IDs are cleared' );
lnkup_t_same( 0, (int) ( $ds['event']['featured_image_id'] ?? -1 ), 'unselected parts (images) are not copied' );
lnkup_t_same( false, $check( LNKup_Event_Builder_Validator::report( $ds, $org ), 'confirm_location' )['ok'] ?? null, 'copies must confirm their location' );
lnkup_t_same( 403, lnkup_eb_rest( 'POST', '/drafts', array( 'duplicate_event' => $event ), $other )['status'], 'cannot duplicate another organizer\'s event' );

echo "Templates\n";
$t = lnkup_eb_rest( 'POST', '/drafts', array( 'method' => 'template', 'template_id' => 'starter:food_truck_fest' ), $org );
lnkup_t_same( array( 'Food Trucks', 0, 0.0 ), array( $t['data']['draft']['state']['options'][0]['category'] ?? '', $t['data']['draft']['state']['options'][0]['spots'] ?? -1, (float) ( $t['data']['draft']['state']['options'][0]['fee'] ?? -1 ) ), 'starters give structure with no prices or capacity' );
$saved = lnkup_eb_rest( 'POST', '/library/templates', array( 'name' => 'EB Template', 'event_id' => $event ), $org );
$tid   = (int) ( $saved['data']['id'] ?? 0 );
lnkup_t_assert( $tid > 0, 'event saved as a versioned template' );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $tid, $org ) {
	LNKup_Portfolio_Templates::delete( $tid, $org );
};
$fromt = lnkup_eb_rest( 'POST', '/drafts', array( 'method' => 'template', 'template_id' => (string) $tid ), $org );
lnkup_t_same( array( $tid, 1 ), array( (int) ( $fromt['data']['draft']['state']['template']['id'] ?? 0 ), (int) ( $fromt['data']['draft']['state']['template']['version'] ?? 0 ) ), 'draft records the template version it came from' );
lnkup_t_same( 403, lnkup_eb_rest( 'POST', '/library/templates', array( 'name' => 'Steal', 'event_id' => $event ), $other )['status'], 'cannot save another organizer\'s event as a template' );

echo "Delete\n";
$x = lnkup_eb_rest( 'POST', '/drafts', array( 'method' => 'single' ), $org );
lnkup_t_same( true, lnkup_eb_rest( 'DELETE', '/drafts/' . (int) $x['data']['draft']['id'], array(), $org )['data']['deleted'] ?? false, 'drafts can be deleted' );

lnkup_m_finish();
