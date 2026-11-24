<?php
/**
 * Mobile Vendor OS 2.0 backend contracts: event resolution fallback + snapshots, end-date-aware
 * event state, separated booking dimensions, receipt payment status, event-grouped discovery,
 * single-booking ownership.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/mobile-vendor-contract-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

echo "Mobile vendor contract\n";

/**
 * Published post removed at the end of the run.
 *
 * @param string $type  Post type.
 * @param array  $meta  Meta.
 * @param string $title Title.
 */
function lnkup_mv_post( string $type, array $meta = array(), string $title = '' ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title ?: 'MV test ' . $type, 'meta_input' => $meta ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

// --- event_state (pure) ---------------------------------------------------------------
$c = 'LNKup_Vendor_Booking_Contract';
lnkup_t_same( 'unknown', $c::state_for_dates( '', '', '', '2026-11-24' ), 'no date is unknown, never upcoming' );
lnkup_t_same( 'upcoming', $c::state_for_dates( '2026-12-01', '', '', '2026-11-24' ), 'future start is upcoming' );
lnkup_t_same( 'active', $c::state_for_dates( '2026-11-22', '2026-11-25', '', '2026-11-24' ), 'multi-day event in progress is active' );
lnkup_t_same( 'completed', $c::state_for_dates( '2026-08-01', '', '', '2026-11-24' ), 'past single-day event is completed' );
lnkup_t_same( 'active', $c::state_for_dates( '2026-11-24', '2026-11-20', '', '2026-11-24' ), 'end before start falls back to start date' );

// --- state dimensions ------------------------------------------------------------------
$d = $c::state_dimensions( 'payment_required', 'pending', 'completed' );
lnkup_t_same( 'overdue', $d['payment_state'], 'open balance after the event is overdue' );
lnkup_t_same( 'pending_confirmation', $d['booking_state'], 'approved unpaid booking awaits confirmation' );
$d = $c::state_dimensions( 'paid', 'paid', 'completed' );
lnkup_t_same( 'completed', $d['booking_state'], 'paid booking for a past event is completed' );
$ctx = $c::payment_context( 'payment_required', 'pending', 'completed' );
lnkup_t_assert( $ctx['past_event_invoice'] && $ctx['collectible'], 'past-event invoice stays collectible (never written off)' );
$ctx = $c::payment_context( 'cancelled', 'pending', 'upcoming' );
lnkup_t_assert( ! $ctx['collectible'], 'withdrawn application balance is not collectible' );
lnkup_t_same( 'ended', $c::credential_state( true, 'paid', 'paid', 'completed' ), 'pass for a finished event is ended' );
lnkup_t_same( 'revoked', $c::credential_state( true, 'paid', 'refunded', 'upcoming' ), 'refunded payment revokes the pass' );
lnkup_t_same( 'locked', $c::credential_state( false, 'payment_required', 'pending', 'upcoming' ), 'unpaid pass is locked' );

// --- fixtures ----------------------------------------------------------------------------
$org    = lnkup_t_user( 'lnkup_organizer' );
$vendor = lnkup_t_user( 'lnkup_vendor' );
$other  = lnkup_t_user( 'lnkup_vendor' );
update_user_meta( $org, '_lnkup_organizer_public_name', 'MV Events Co' );
$event = lnkup_mv_post(
	'lnkup_event',
	array(
		'_lnkup_organizer_user_id' => $org,
		'_lnkup_city'              => 'atlanta',
		'_lnkup_state'             => 'Ga',
		'_lnkup_event_date'        => gmdate( 'Y-m-d', strtotime( '+40 days' ) ),
	),
	'MV Grouping Festival'
);
$opps = array();
foreach ( array( array( '300', 'Art' ), array( '500', 'Clothing' ) ) as $spec ) {
	$opp = lnkup_mv_post(
		'lnkup_opportunity',
		array(
			'_lnkup_event_id'             => $event,
			'_lnkup_organizer_user_id'    => $org,
			'_lnkup_opportunity_status'   => 'open',
			'_lnkup_vendor_fee'           => $spec[0],
			'_lnkup_spots_total'          => 5,
			'_lnkup_spots_remaining'      => 5,
			'_lnkup_required_documents'   => 'none',
			'_lnkup_application_deadline' => gmdate( 'Y-m-d', strtotime( '+20 days' ) ),
		),
		'MV ' . $spec[1] . ' booth'
	);
	LNKup_Opportunity_Inventory::recompute( $opp );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	$opps[] = $opp;
}

// --- grouped discovery -----------------------------------------------------------------
$guest = LNKup_Discovery::search( array( 'q' => 'MV Grouping Festival', 'group_by' => 'event', 'per_page' => 10, 'has_spots' => true ) );
foreach ( (array) $guest['items'] as $g ) {
	if ( (int) $g['event_id'] === $event ) {
		lnkup_t_assert( null === $g['from_price'] && null === $g['options'][0]['vendor_fee'], 'guests get no price in grouped results' );
	}
}
wp_set_current_user( $vendor );
$grouped = LNKup_Discovery::search( array( 'q' => 'MV Grouping Festival', 'group_by' => 'event', 'per_page' => 10, 'has_spots' => true ) );
$mine    = array_values( array_filter( (array) $grouped['items'], static function ( $g ) use ( $event ) { return (int) $g['event_id'] === $event; } ) );
lnkup_t_assert( ! empty( $grouped['grouped'] ), 'group_by=event returns grouped payload' );
lnkup_t_same( 1, count( $mine ), 'event with two vendor options appears once' );
if ( $mine ) {
	lnkup_t_same( 2, count( $mine[0]['options'] ), 'both options kept with their own identity' );
	lnkup_t_same( 300.0, (float) $mine[0]['from_price'], 'from price is the lowest open option' );
	lnkup_t_same( 'Atlanta, GA', $mine[0]['place'], 'place normalized (Atlanta, Ga -> Atlanta, GA)' );
	lnkup_t_same( 'MV Events Co', $mine[0]['organizer']['name'], 'organizer public name, not account login' );
	lnkup_t_assert( (int) $grouped['total_options'] >= 2 && (int) $grouped['total_events'] >= 1, 'event and option totals both reported' );
}
$ungrouped = LNKup_Discovery::search( array( 'q' => 'MV Grouping Festival', 'per_page' => 10, 'has_spots' => true ) );
lnkup_t_assert( empty( $ungrouped['grouped'] ) && isset( $ungrouped['items'][0]['opportunity_id'] ), 'default search response shape unchanged' );
$detail = LNKup_Event_Groups::event_detail( $event );
lnkup_t_assert( is_array( $detail ) && 2 === count( $detail['options'] ), 'event detail lists every option' );
lnkup_t_assert( is_wp_error( LNKup_Event_Groups::event_detail( $opps[0] ) ), 'event detail rejects non-event IDs' );
update_post_meta( $opps[1], '_lnkup_opportunity_status', 'closed' );
LNKup_Marketplace_Index::sync_opportunity( $opps[1] );
$detail = LNKup_Event_Groups::event_detail( $event );
$closed = is_array( $detail ) ? array_values( array_filter( $detail['options'], static function ( $o ) use ( $opps ) { return (int) $o['opportunity_id'] === $opps[1]; } ) ) : array();
lnkup_t_assert( 1 === count( $closed ) && false === $closed[0]['is_open'], 'closed option stays listed on its event, marked not open' );
lnkup_t_same( 300.0, (float) $detail['from_price'], 'from price ignores the closed option' );
update_post_meta( $opps[1], '_lnkup_opportunity_status', 'open' );
LNKup_Marketplace_Index::sync_opportunity( $opps[1] );
wp_set_current_user( 0 );

// --- event resolution fallback + snapshot ------------------------------------------------
$app = lnkup_mv_post(
	'lnkup_application',
	array(
		'_lnkup_vendor_user_id'     => $vendor,
		'_lnkup_organizer_user_id'  => $org,
		'_lnkup_opportunity_id'     => $opps[0],
		'_lnkup_event_id'           => 0,
		'_lnkup_application_status' => 'submitted',
	),
	'Application: vendor@example.test → MV Grouping Festival'
);
$r = $c::resolve_event( $app );
lnkup_t_same( 'opportunity', $r['source'], 'missing application event falls back to the opportunity event' );
lnkup_t_same( $event, (int) $r['event_id'], 'fallback resolves the right event' );
$meta = LNKup_Vendor_Dashboard_Data::application_event_meta( get_post( $app ) );
lnkup_t_same( 'MV Grouping Festival', $meta['event_title'], 'booking shows the event title, not the placeholder' );

$c::capture_snapshot( $app );
$snap = $c::snapshot( $app );
lnkup_t_same( 'MV Grouping Festival', $snap['title'] ?? '', 'snapshot stores the event title' );

// Simulate a removed event and a removed opportunity link.
update_post_meta( $opps[0], '_lnkup_event_id', 0 );
update_post_meta( $app, '_lnkup_event_id', 999999999 );
$r = $c::resolve_event( $app );
lnkup_t_same( 'snapshot', $r['source'], 'deleted event resolves from the snapshot' );
$item = $c::item( get_post( $app ), $vendor );
lnkup_t_same( 'MV Grouping Festival', $item['event_name'], 'booking item keeps the recorded title' );
lnkup_t_same( 'snapshot', $item['event_resolution'], 'item reports snapshot resolution' );

delete_post_meta( $app, $c::SNAPSHOT_META );
$item = $c::item( get_post( $app ), $vendor );
lnkup_t_same( 'unrecoverable', $item['event_resolution'], 'no event and no snapshot is reported unrecoverable' );
lnkup_t_same( 'unknown', $item['event_state'], 'unrecoverable booking is never upcoming' );
update_post_meta( $opps[0], '_lnkup_event_id', $event );

$dry = $c::repair( false );
lnkup_t_assert( in_array( $app, $dry['unrecoverable'], true ) === false, 'repair dry-run sees the opportunity link again' );
lnkup_t_same( 999999999, (int) get_post_meta( $app, '_lnkup_event_id', true ), 'dry run writes nothing' );
$c::repair( true );
lnkup_t_same( $event, (int) get_post_meta( $app, '_lnkup_event_id', true ), 'repair relinks the application event' );

// --- single booking ownership ------------------------------------------------------------
wp_set_current_user( $other );
$req      = new WP_REST_Request( 'GET', '/lnkup/v1/applications/vendor/' . $app );
$req['id'] = $app;
$res      = LNKup_Applications_REST::vendor_application( $req );
lnkup_t_assert( is_wp_error( $res ) && 404 === ( $res->get_error_data()['status'] ?? 0 ), 'another vendor cannot read the booking' );
wp_set_current_user( $vendor );
$res = LNKup_Applications_REST::vendor_application( $req );
lnkup_t_assert( ! is_wp_error( $res ) && ! empty( $res->get_data()['timeline'] ), 'owner gets the booking with a timeline' );
$steps = $res->get_data()['timeline'];
lnkup_t_same( 'pending', $steps[4]['state'], 'check-in is not marked complete without a check-in record' );
wp_set_current_user( 0 );

// --- receipt status ----------------------------------------------------------------------
$fake = LNKup_Receipts::format(
	array(
		'id'                => 0,
		'receipt_number'    => 'RCP-TEST',
		'amount'            => 10,
		'payment_method'    => 'stripe',
		'status'            => 'issued',
		'payment_post_id'   => 0,
		'vendor_user_id'    => $vendor,
		'created_at'        => current_time( 'mysql' ),
		'metadata'          => wp_json_encode( array( 'event_id' => $event, 'application_id' => $app ) ),
	)
);
lnkup_t_same( 'paid', $fake['payment_status'], 'issued receipt reports a paid payment status' );
lnkup_t_same( 'issued', $fake['document_status'], 'document status kept separately' );
lnkup_t_same( 'MV Grouping Festival', $fake['event_title'], 'receipt carries the event title' );

foreach ( $opps as $opp ) {
	update_post_meta( $opp, '_lnkup_opportunity_status', 'closed' );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
}
lnkup_t_finish();
