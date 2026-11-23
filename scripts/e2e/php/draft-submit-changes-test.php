<?php
/**
 * A booth that changes while the vendor is applying (deadline passes, last spot taken, organizer
 * closes it) is refused at submit with a specific code and a 4xx status, the draft stays open, and
 * the same draft submits once the organizer reopens.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/draft-submit-changes-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

echo "Draft submit after booth changes\n";

/**
 * Published post removed at the end of the run.
 *
 * @param string $type Post type.
 * @param array  $meta Meta.
 */
function lnkup_dc_post( string $type, array $meta = array() ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Draft change ' . $type, 'meta_input' => $meta ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

/**
 * Vendor with a published profile and an open draft on a fresh booth.
 *
 * @param int   $organizer Organizer.
 * @param array $opp_meta  Opportunity meta overrides.
 * @return int[] [vendor, opportunity]
 */
function lnkup_dc_booth_with_draft( int $organizer, array $opp_meta = array() ): array {
	$event = lnkup_dc_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_city' => 'Houston', '_lnkup_state' => 'TX', '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+60 days' ) ) ) );
	$opp   = lnkup_dc_post(
		'lnkup_opportunity',
		array_merge(
			array(
				'_lnkup_event_id'             => $event,
				'_lnkup_organizer_user_id'    => $organizer,
				'_lnkup_opportunity_status'   => 'open',
				'_lnkup_vendor_fee'           => '90',
				'_lnkup_spots_total'          => 3,
				'_lnkup_spots_remaining'      => 3,
				'_lnkup_required_documents'   => 'none',
				'_lnkup_application_deadline' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
			),
			$opp_meta
		)
	);
	LNKup_Opportunity_Inventory::recompute( $opp );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	$vendor = lnkup_t_user( 'lnkup_vendor' );
	lnkup_dc_post( 'lnkup_vendor_profile', array( '_lnkup_owner_user_id' => $vendor, '_lnkup_business_name' => 'Draft Change Vendor ' . $vendor ) );
	wp_set_current_user( $vendor );
	LNKup_Application_Drafts::upsert( $vendor, $opp, array( 'step' => 'review', 'fields' => array( 'selected_category' => 'General' ) ) );
	return array( $vendor, $opp );
}

/**
 * POST the draft submit route as the vendor.
 *
 * @param int $vendor Vendor.
 * @param int $opp    Opportunity.
 * @return array{0:int,1:string,2:int} [status, code, application_id]
 */
function lnkup_dc_submit( int $vendor, int $opp ): array {
	wp_set_current_user( $vendor );
	$req = new WP_REST_Request( 'POST', '/lnkup/v1/applications/drafts/' . $opp . '/submit' );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_header( 'idempotency_key', 'dc' . wp_generate_password( 16, false ) );
	$req->set_body( wp_json_encode( array( 'accepted_terms' => true ) ) );
	$res  = rest_do_request( $req );
	$data = (array) $res->get_data();
	if ( ! empty( $data['application_id'] ) ) {
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $data['application_id'] );
	}
	return array( $res->get_status(), (string) ( $data['code'] ?? '' ), (int) ( $data['application_id'] ?? 0 ) );
}

$organizer = lnkup_t_user( 'lnkup_organizer' );

$cases = array(
	'deadline' => array(
		'change' => static function ( int $opp ) {
			update_post_meta( $opp, '_lnkup_application_deadline', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
		},
		'reopen' => static function ( int $opp ) {
			update_post_meta( $opp, '_lnkup_application_deadline', gmdate( 'Y-m-d', strtotime( '+10 days' ) ) );
		},
	),
	'closed'   => array(
		'change' => static function ( int $opp ) {
			update_post_meta( $opp, '_lnkup_opportunity_status', 'closed' );
		},
		'reopen' => static function ( int $opp ) {
			update_post_meta( $opp, '_lnkup_opportunity_status', 'open' );
		},
	),
	'full'     => array(
		'change' => static function ( int $opp ) {
			update_post_meta( $opp, '_lnkup_spots_total', 1 );
			$other = lnkup_t_user( 'lnkup_vendor' );
			lnkup_dc_post( 'lnkup_vendor_profile', array( '_lnkup_owner_user_id' => $other, '_lnkup_business_name' => 'Draft Change Rival ' . $other ) );
			wp_set_current_user( $other );
			$taken = LNKup_Applications::submit_with_profile( $other, $opp, array( 'accepted_terms' => true, 'selected_category' => 'General' ) );
			if ( ! is_wp_error( $taken ) ) {
				$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $taken );
				LNKup_Applications::transition( (int) $taken, 'approved' );
			}
		},
		'reopen' => static function ( int $opp ) {
			update_post_meta( $opp, '_lnkup_spots_total', 3 );
		},
	),
);

foreach ( $cases as $expected => $case ) {
	list( $vendor, $opp ) = lnkup_dc_booth_with_draft( $organizer );
	// Organizer edits save the post, which resyncs inventory and the marketplace index.
	$case['change']( $opp );
	LNKup_Opportunity_Inventory::recompute( $opp );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	list( $status, $code ) = lnkup_dc_submit( $vendor, $opp );
	lnkup_t_same( $expected, $code, "{$expected}: submit is refused with the {$expected} code" );
	lnkup_t_assert( $status >= 400 && $status < 500, "{$expected}: refusal is a 4xx, never a 500 (got {$status})" );
	lnkup_t_assert( null !== LNKup_Application_Drafts::get_for_opportunity( $vendor, $opp ), "{$expected}: the draft stays open" );
	lnkup_t_same( 0, count( lnkup_get_applications_for_user( $vendor, 'vendor' ) ), "{$expected}: no application is created" );
	$case['reopen']( $opp );
	LNKup_Opportunity_Inventory::recompute( $opp );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	list( $status, , $app_id ) = lnkup_dc_submit( $vendor, $opp );
	lnkup_t_same( 200, $status, "{$expected}: the same draft submits after the organizer reopens" );
	lnkup_t_assert( $app_id > 0, "{$expected}: an application is created on resubmit" );
}

wp_set_current_user( 0 );
lnkup_t_finish();
