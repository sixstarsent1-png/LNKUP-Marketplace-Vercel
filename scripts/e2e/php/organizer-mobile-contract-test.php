<?php
/**
 * Organizer Mobile OS 2.0 contract tests.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/organizer-mobile-contract-test.php
 *
 * Builds throwaway organizers, staff, a vendor, an event with bookings in every check-in state,
 * payments and invites; asserts the mobile routes, permissions and money rules; removes
 * everything on exit. Never creates a payout or contacts Stripe.
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

/**
 * Dispatch a REST request as a user.
 *
 * @param int    $user_id User.
 * @param string $method  Method.
 * @param string $path    Path under lnkup/v1.
 * @param array  $params  Params.
 * @return array{0:int,1:mixed}
 */
function om_rest( int $user_id, string $method, string $path, array $params = array() ): array {
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( $method, '/lnkup/v1' . $path );
	foreach ( $params as $k => $v ) {
		$request->set_param( $k, $v );
	}
	$response = rest_do_request( $request );
	$data     = rest_get_server()->response_to_data( $response, false );
	return array( (int) $response->get_status(), $data );
}

/**
 * Throwaway post with meta (keys without the _lnkup_ prefix).
 *
 * @param string $type Post type.
 * @param array  $meta Meta.
 * @param string $title Title.
 */
function om_post( string $type, array $meta, string $title = 'Organizer mobile test' ): int {
	$id = (int) wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title ) );
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, '_lnkup_' . $k, $v );
	}
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $id );
	return $id;
}

global $wpdb;

$org_a    = lnkup_t_user( 'lnkup_organizer' );
$org_b    = lnkup_t_user( 'lnkup_organizer' );
$vendor   = lnkup_t_user( 'lnkup_vendor' );
$vendor2  = lnkup_t_user( 'lnkup_vendor' );
$parking  = lnkup_t_user( 'lnkup_vendor' );
$finance  = lnkup_t_user( 'lnkup_vendor' );
$outsider = lnkup_t_user( 'lnkup_vendor' );

$date  = gmdate( 'Y-m-d', strtotime( '+10 days' ) );
$event = om_post( 'lnkup_event', array( 'organizer_user_id' => $org_a, 'event_date' => $date, 'city' => 'Orlando', 'state' => 'FL' ), 'OM Test Night Market' );
$opp   = om_post( 'lnkup_opportunity', array( 'organizer_user_id' => $org_a, 'event_id' => $event, 'vendor_fee' => 100, 'capacity' => 10 ), 'OM Test Booth' );

$profile = om_post( 'lnkup_vendor_profile', array( 'owner_user_id' => $vendor, 'business_name' => 'OM Test Tacos' ), 'OM Test Tacos' );
$app     = static function ( string $status, array $extra = array() ) use ( $event, $opp, $org_a, $vendor, $profile ) {
	return om_post(
		'lnkup_application',
		array_merge(
			array(
				'event_id'           => $event,
				'opportunity_id'     => $opp,
				'organizer_user_id'  => $org_a,
				'vendor_user_id'     => $vendor,
				'vendor_profile_id'  => $profile,
				'application_status' => $status,
			),
			$extra
		)
	);
};
$app_paid       = $app( 'paid' );
$app_checked_in = $app( 'paid', array( 'checked_in_at' => current_time( 'mysql' ) ) );
$app_unpaid     = $app( 'payment_required' );
$app_rejected   = $app( 'rejected' );

// Payments: one open invoice, one refunded (never a receivable), one paid.
lnkup_t_payment( array( 'event_id' => $event, 'application_id' => $app_unpaid, 'payment_status' => 'pending', 'amount' => 100, 'booth_amount' => 100 ) );
lnkup_t_payment( array( 'event_id' => $event, 'application_id' => $app_rejected, 'payment_status' => 'refunded', 'amount' => 50, 'booth_amount' => 50 ) );

LNKup_Event_Staff::assign( $event, $parking, 'parking' );
LNKup_Event_Staff::assign( $event, $finance, 'finance' );

echo "Check-in summary populations\n";
list( $status, $body ) = om_rest( $org_a, 'GET', "/operations/events/{$event}/checkin-summary" );
lnkup_t_same( 200, $status, 'owner can read check-in summary' );
lnkup_t_same( 3, $body['booked'] ?? null, 'booked counts held + confirmed bookings, not rejected' );
lnkup_t_same( 2, $body['expected'] ?? null, 'expected arrivals exclude unpaid bookings' );
lnkup_t_same( 1, $body['checked_in'] ?? null, 'checked in' );
lnkup_t_same( 1, $body['waiting'] ?? null, 'waiting' );
lnkup_t_same( 1, $body['blocked']['payment_pending'] ?? null, 'unpaid booking is blocked, not an arrival' );
lnkup_t_same( 50, $body['arrival_pct'] ?? null, 'arrival percent uses eligible vendors as the denominator' );
lnkup_t_same( 3, count( (array) ( $body['rows'] ?? array() ) ), 'lookup roster lists every booked vendor' );

list( $status ) = om_rest( $org_b, 'GET', "/operations/events/{$event}/checkin-summary" );
lnkup_t_same( 403, $status, 'another organizer cannot read the check-in summary' );
list( $status ) = om_rest( $parking, 'GET', "/operations/events/{$event}/checkin-summary" );
lnkup_t_same( 200, $status, 'parking staff (check_in) can read the check-in summary' );
list( $status ) = om_rest( $finance, 'GET', "/operations/events/{$event}/checkin-summary" );
lnkup_t_same( 403, $status, 'finance staff (no check_in) cannot read the check-in summary' );
list( $status ) = om_rest( $parking, 'GET', "/crm/events/{$event}/roster" );
lnkup_t_same( 200, $status, 'assigned check-in staff can open the CRM roster' );
list( $status ) = om_rest( $outsider, 'GET', "/crm/events/{$event}/roster" );
lnkup_t_same( 403, $status, 'unassigned user cannot open the CRM roster' );

echo "Operations summary\n";
list( $status, $body ) = om_rest( $org_a, 'GET', "/organizer/events/{$event}/operations-summary" );
lnkup_t_same( 200, $status, 'owner reads operations summary' );
lnkup_t_same( 'pre_event', $body['mode'] ?? null, 'future event is in pre-event mode' );
lnkup_t_assert( isset( $body['event']['money'] ), 'owner sees event money' );
lnkup_t_assert( ! isset( $body['event']['url'] ), 'no web dashboard URL in the mobile event' );
lnkup_t_same( 2, $body['checkin']['expected'] ?? null, 'operations summary shares check-in populations' );
lnkup_t_assert( ! isset( $body['checkin']['rows'] ), 'operations summary omits the roster rows' );
list( $status, $body ) = om_rest( $parking, 'GET', "/organizer/events/{$event}/operations-summary" );
lnkup_t_same( 200, $status, 'staff reads operations summary' );
lnkup_t_assert( ! isset( $body['event']['money'] ), 'parking staff do not see event money' );
lnkup_t_same( 'parking', $body['my_role'] ?? null, 'staff role reported' );
lnkup_t_same( false, $body['can']['edit_ops'] ?? null, 'parking staff cannot edit operations' );
list( $status, $body ) = om_rest( $finance, 'GET', "/organizer/events/{$event}/operations-summary" );
lnkup_t_assert( isset( $body['event']['money'] ), 'finance staff see event money' );
list( $status ) = om_rest( $org_b, 'GET', "/organizer/events/{$event}/operations-summary" );
lnkup_t_same( 403, $status, 'another organizer cannot read operations summary' );
list( $status, $body ) = om_rest( $parking, 'GET', "/organizer/events/{$event}/summary" );
lnkup_t_same( 200, $status, 'staff can read the event summary' );
lnkup_t_assert( ! isset( $body['money'] ), 'event summary hides money from staff without view_financials' );
list( $status ) = om_rest( $outsider, 'GET', "/organizer/events/{$event}/summary" );
lnkup_t_same( 403, $status, 'unassigned user cannot read the event summary' );

echo "Home summary and events\n";
list( $status, $body ) = om_rest( $org_a, 'GET', '/organizer/home-summary' );
lnkup_t_same( 200, $status, 'home summary loads' );
lnkup_t_assert( isset( $body['setup']['percent'], $body['queue'], $body['money'], $body['events']['counts'], $body['kpis']['awaiting_review'], $body['events']['upcoming'] ), 'home summary shape' );
lnkup_t_assert( is_array( $body['recruitment'] ?? null ), 'home summary lists recruitment gaps' );
lnkup_t_same( 2, (int) ( $body['kpis']['confirmed']['value'] ?? -1 ), 'confirmed vendors KPI counts paid bookings at upcoming events' );
lnkup_t_assert( ! isset( $body['onboarding'] ), 'one setup model only' );
lnkup_t_same( 1, $body['events']['counts']['total'] ?? null, 'home counts only this organizer\'s events' );
lnkup_t_same( $event, (int) ( $body['events']['next']['id'] ?? 0 ), 'next event is the upcoming event' );
lnkup_t_assert( array_key_exists( 'media', (array) ( $body['events']['next'] ?? array() ) ), 'next event carries media' );
list( $status ) = om_rest( $vendor, 'GET', '/organizer/home-summary' );
lnkup_t_same( 403, $status, 'vendors cannot read organizer home' );

list( $status, $body ) = om_rest( $org_a, 'GET', '/organizer/mobile/events', array( 'q' => 'night market' ) );
lnkup_t_same( 1, count( (array) ( $body['events'] ?? array() ) ), 'search matches the event title' );
list( $status, $body ) = om_rest( $org_a, 'GET', '/organizer/mobile/events', array( 'q' => 'no-such-event-xyz' ) );
lnkup_t_same( 0, count( (array) ( $body['events'] ?? array() ) ), 'search excludes non-matching events' );
lnkup_t_same( 1, $body['counts']['all'] ?? null, 'filter counts ignore the search' );
list( $status, $body ) = om_rest( $org_b, 'GET', '/organizer/mobile/events' );
lnkup_t_same( 0, count( (array) ( $body['events'] ?? array() ) ), 'another organizer sees none of these events' );

echo "Application review\n";
list( $status, $body ) = om_rest( $org_a, 'GET', "/organizer/applications/{$app_unpaid}/review" );
lnkup_t_same( 200, $status, 'owner reads the review read model' );
lnkup_t_same( false, (bool) ( $body['actions']['approve'] ?? true ), 'an approved, unpaid booking offers no approve action' );
lnkup_t_assert( '' !== (string) ( $body['approve_note'] ?? '' ), 'approval consequence is explained' );
list( $status ) = om_rest( $org_b, 'GET', "/organizer/applications/{$app_unpaid}/review" );
lnkup_t_same( 403, $status, 'another organizer cannot read the application' );
list( $status ) = om_rest( $parking, 'GET', "/organizer/applications/{$app_unpaid}/review" );
lnkup_t_same( 403, $status, 'event staff cannot read application decisions' );
list( $status, $body ) = om_rest( $org_a, 'GET', "/organizer/events/{$event}/operations-summary" );
lnkup_t_assert( false === strpos( (string) wp_json_encode( $body['readiness'] ?? array() ), 'vendor-dashboard' ), 'readiness rows carry no vendor dashboard URLs' );
$owners = wp_list_pluck( (array) ( $body['tasks'] ?? array() ), 'owner', 'key' );
lnkup_t_same( 'vendor', $owners['payments'] ?? null, 'payment collection is the vendor\'s step' );

echo "Event score factors\n";
list( $status, $body ) = om_rest( $org_a, 'GET', "/organizer/events/{$event}/score" );
lnkup_t_same( 200, $status, 'owner reads score' );
$weights = array_sum( array_map( static fn( $f ) => (int) $f['weight_pct'], (array) ( $body['factors'] ?? array() ) ) );
lnkup_t_assert( $weights >= 98 && $weights <= 102, 'factor weights add up to about 100%' );
lnkup_t_assert( '' !== (string) ( $body['factors'][0]['explanation'] ?? '' ), 'factors are explained' );
list( $status ) = om_rest( $parking, 'GET', "/organizer/events/{$event}/score" );
lnkup_t_same( 403, $status, 'staff without view_intelligence cannot read the score' );

echo "Event receivables\n";
$fin = LNKup_Financial_Reports::for_event( $event );
lnkup_t_same( 100.0, (float) $fin['outstanding_receivables'], 'only the open invoice is a receivable (refunded excluded)' );
lnkup_t_same( 1, (int) $fin['outstanding_invoice_count'], 'one open invoice' );
lnkup_t_assert( array_key_exists( 'pending_revenue', $fin ), 'legacy pending_revenue kept' );

echo "Invites lifecycle\n";
$invite = LNKup_Vendor_Invites::create( $org_a, $vendor2, array( 'event_id' => $event, 'silent' => true ) );
lnkup_t_assert( is_array( $invite ) && ! empty( $invite['invite_id'] ), 'invite created' );
$invite_id = is_array( $invite ) ? (int) $invite['invite_id'] : 0;
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $invite_id );
list( $status, $body ) = om_rest( $org_a, 'GET', '/organizer/mobile/invites' );
lnkup_t_same( 200, $status, 'invites overview loads' );
lnkup_t_same( 1, (int) ( $body['counts']['awaiting'] ?? -1 ), 'one invite awaiting a response' );
list( $status ) = om_rest( $org_b, 'POST', "/crm/invites/{$invite_id}/remind" );
lnkup_t_same( 404, $status, 'another organizer cannot remind this invite' );
list( $status, $body ) = om_rest( $org_a, 'POST', "/crm/invites/{$invite_id}/remind" );
lnkup_t_same( 200, $status, 'owner can send a reminder' );
lnkup_t_same( 1, (int) ( $body['invite']['reminder_count'] ?? 0 ), 'reminder counted' );
list( $status ) = om_rest( $org_a, 'POST', "/crm/invites/{$invite_id}/remind" );
lnkup_t_same( 429, $status, 'second reminder inside the interval is refused' );
list( $status ) = om_rest( $org_b, 'POST', "/crm/invites/{$invite_id}/cancel" );
lnkup_t_same( 404, $status, 'another organizer cannot cancel this invite' );
list( $status, $body ) = om_rest( $org_a, 'POST', "/crm/invites/{$invite_id}/cancel" );
lnkup_t_same( 200, $status, 'owner can cancel' );
lnkup_t_same( 'cancelled', $body['invite']['status'] ?? null, 'invite is cancelled' );
list( $status ) = om_rest( $org_a, 'POST', "/crm/invites/{$invite_id}/cancel" );
lnkup_t_same( 409, $status, 'a cancelled invite cannot be cancelled again' );
lnkup_t_assert( 'lnkup_vendor_invite' === get_post_type( $invite_id ), 'cancelled invite is kept for history' );

echo "Self-exclusion\n";
$dual = lnkup_t_user( 'lnkup_organizer' );
( new WP_User( $dual ) )->add_role( 'lnkup_vendor' );
$own_profile = om_post( 'lnkup_vendor_profile', array( 'owner_user_id' => $dual, 'business_name' => 'OM Dual Role Shop' ), 'OM Dual Role Shop' );
$found = lnkup_get_discoverable_vendors( array( 'q' => 'OM Dual Role Shop', 'organizer_id' => $dual ) );
lnkup_t_assert( ! in_array( $own_profile, array_map( static fn( $p ) => (int) $p->ID, (array) $found['posts'] ), true ), 'organizer never discovers their own vendor profile' );
$found = lnkup_get_discoverable_vendors( array( 'q' => 'OM Dual Role Shop', 'organizer_id' => $org_a ) );
lnkup_t_assert( in_array( $own_profile, array_map( static fn( $p ) => (int) $p->ID, (array) $found['posts'] ), true ), 'other organizers still discover that profile' );
$self = LNKup_Vendor_Invites::create( $dual, $dual, array( 'silent' => true ) );
lnkup_t_same( 'self_invite', is_wp_error( $self ) ? $self->get_error_code() : '', 'self invite refused' );

echo "Cash-out preview and fee setting\n";
$table  = LNKup_Treasury_Tables::organizer_payouts_table();
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
lnkup_t_same( 0.015, LNKup_Organizer_Payouts::instant_fee_rate(), 'default instant fee rate is unchanged (1.5%)' );
$settings = get_option( 'lnkup_settings', array() );
$patched  = is_array( $settings ) ? $settings : array();
$patched['organizer_instant_fee_rate'] = 0.02;
update_option( 'lnkup_settings', $patched );
lnkup_t_same( 0.02, LNKup_Organizer_Payouts::instant_fee_rate(), 'fee rate comes from settings' );
list( $status, $body ) = om_rest( $org_a, 'GET', '/financial/organizer-payouts/preview', array( 'amount' => 100, 'type' => 'instant' ) );
lnkup_t_same( 200, $status, 'preview loads' );
if ( 'lnkup' === ( $body['method'] ?? '' ) ) {
	lnkup_t_same( 2.0, (float) ( $body['fee_amount'] ?? -1 ), 'preview fee uses the configured rate' );
	lnkup_t_same( 98.0, (float) ( $body['estimated_net'] ?? -1 ), 'preview net' );
}
lnkup_t_same( false, (bool) ( $body['can_submit'] ?? true ), 'preview refuses an amount above the available balance' );
update_option( 'lnkup_settings', $settings );
list( $status ) = om_rest( $parking, 'GET', '/financial/organizer-payouts/preview', array( 'amount' => 10 ) );
lnkup_t_same( 403, $status, 'non-organizers cannot preview a cash-out' );
lnkup_t_same( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'preview never creates a payout row' ); // phpcs:ignore WordPress.DB
lnkup_t_assert( isset( LNKup_Organizer_Payouts::format( array( 'id' => 7, 'user_id' => 1, 'amount' => 1, 'fee_amount' => 0, 'net_amount' => 1, 'payout_type' => 'standard', 'status' => 'paid', 'provider' => 'manual', 'created_at' => '2026-01-01 00:00:00' ) )['reference'] ), 'payout rows carry a reference' );

echo "Clone market\n";
wp_set_object_terms( $event, 'om-test-market-orlando', 'lnkup_market' );
$copy = LNKup_Organizer_Onboarding::duplicate_event(
	$event,
	array(
		'event_name'      => 'OM Test Copy',
		'event_date'      => gmdate( 'Y-m-d', strtotime( '+40 days' ) ),
		'city'            => 'Austin',
		'state'           => 'TX',
	),
	$org_a
);
if ( is_wp_error( $copy ) ) {
	echo '  duplicate error: ' . $copy->get_error_message() . "\n";
	$copy = 0;
}
$copy_id = is_array( $copy ) ? (int) ( $copy['event_id'] ?? $copy['id'] ?? 0 ) : (int) $copy;
if ( $copy_id ) {
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $copy_id );
	foreach ( get_posts( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_lnkup_event_id', 'meta_value' => $copy_id ) ) as $copy_opp ) {
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $copy_opp );
	}
}
lnkup_t_assert( $copy_id > 0, 'event duplicated' );
$copy_terms = $copy_id ? wp_get_object_terms( $copy_id, 'lnkup_market', array( 'fields' => 'slugs' ) ) : array();
lnkup_t_assert( ! in_array( 'om-test-market-orlando', (array) $copy_terms, true ), 'a copy in a new city does not keep the source market' );
$same = LNKup_Organizer_Onboarding::duplicate_event( $event, array( 'event_name' => 'OM Test Same City', 'event_date' => gmdate( 'Y-m-d', strtotime( '+41 days' ) ), 'keep_location' => 1 ), $org_a );
$same_id = is_wp_error( $same ) ? 0 : ( is_array( $same ) ? (int) ( $same['event_id'] ?? $same['id'] ?? 0 ) : (int) $same );
if ( $same_id ) {
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $same_id );
	foreach ( get_posts( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_lnkup_event_id', 'meta_value' => $same_id ) ) as $copy_opp ) {
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $copy_opp );
	}
}
lnkup_t_assert( in_array( 'om-test-market-orlando', (array) wp_get_object_terms( $same_id, 'lnkup_market', array( 'fields' => 'slugs' ) ), true ), 'a same-city copy keeps its market' );
$term = get_term_by( 'slug', 'om-test-market-orlando', 'lnkup_market' );
if ( $term ) {
	wp_delete_term( (int) $term->term_id, 'lnkup_market' );
}

foreach ( array( $parking, $finance ) as $staff_id ) {
	LNKup_Event_Staff::remove( $event, $staff_id );
}
lnkup_t_finish();
