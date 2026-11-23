<?php
/**
 * After setup, organizers still get one next step from their own records (applicants waiting,
 * a draft event to finish, then a category nobody has applied for), and unfilled categories are
 * listed per upcoming booth.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/organizer-next-step-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

echo "Organizer next step\n";

/**
 * Published post removed at the end of the run.
 */
function lnkup_ns_post( string $type, array $meta = array(), string $title = '' ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title ?: 'Next step ' . $type, 'meta_input' => $meta ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

$organizer = lnkup_t_user( 'lnkup_organizer' );
$event     = lnkup_ns_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_event_status' => 'live', '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ) ), 'Riverfront Night Market' );
$opp       = lnkup_ns_post( 'lnkup_opportunity', array( '_lnkup_event_id' => $event, '_lnkup_organizer_user_id' => $organizer, '_lnkup_opportunity_status' => 'open', '_lnkup_vendor_fee' => '50', '_lnkup_spots_total' => 6, '_lnkup_application_deadline' => gmdate( 'Y-m-d', strtotime( '+20 days' ) ) ) );
$food      = wp_insert_term( 'Next Step Food ' . wp_generate_password( 4, false ), 'lnkup_vendor_category' );
$art       = wp_insert_term( 'Next Step Art ' . wp_generate_password( 4, false ), 'lnkup_vendor_category' );
$food_name = get_term( (int) $food['term_id'] )->name;
$art_name  = get_term( (int) $art['term_id'] )->name;
wp_set_object_terms( $opp, array( (int) $food['term_id'], (int) $art['term_id'] ), 'lnkup_vendor_category' );
$vendor = lnkup_t_user( 'lnkup_vendor' );
$app    = lnkup_ns_post( 'lnkup_application', array( '_lnkup_event_id' => $event, '_lnkup_opportunity_id' => $opp, '_lnkup_organizer_user_id' => $organizer, '_lnkup_vendor_user_id' => $vendor, '_lnkup_application_status' => 'submitted', '_lnkup_selected_category' => $food_name ) );

$data = LNKup_Organizer_Activation::for_user( $organizer, true );
$gaps = wp_list_pluck( (array) $data['unfilled_categories'], 'category' );
lnkup_t_assert( in_array( $art_name, $gaps, true ), 'a category with no applicants is listed as unfilled' );
lnkup_t_assert( ! in_array( $food_name, $gaps, true ), 'a category with an applicant is not' );
lnkup_t_assert( null !== $data['next_step'], 'there is always a next step while work is waiting' );

// Once setup is complete the next step comes from ongoing work, in priority order.
$built = LNKup_Organizer_Activation::build( array_merge( LNKup_Organizer_Activation::facts( $organizer ), array() ) );
if ( ! $built['is_activated'] ) {
	lnkup_t_same( $built['next_action'], $built['next_step'], 'during setup the next step is the setup step' );
}
$facts                 = LNKup_Organizer_Activation::facts( $organizer );
$facts['profile']      = array_merge( $facts['profile'], array( 'has_name' => true, 'city' => 'Raleigh', 'state' => 'NC' ) );
$facts['payout']       = array_merge( $facts['payout'], array( 'ready' => true ) );
$facts['paid_count']   = 1;
$facts['app_statuses'] = array( 'submitted', 'paid' );
$done                  = LNKup_Organizer_Activation::build( $facts );
lnkup_t_same( null, $done['next_action'], 'an activated organizer has no setup step left' );
lnkup_t_same( 'review_applications', $done['next_step']['stage'] ?? '', 'waiting applicants come first after setup' );

$facts['app_statuses'] = array( 'paid' );
$draft                 = lnkup_ns_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_event_status' => 'draft', '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+60 days' ) ) ), 'Winter Pop-Up' );
$facts['events']       = array_merge( (array) $facts['events'], array( get_post( $draft ) ) );
$draft_step            = LNKup_Organizer_Activation::build( $facts );
lnkup_t_same( 'publish_draft', $draft_step['next_step']['stage'] ?? '', 'then a draft event to finish' );
lnkup_t_assert( false !== strpos( (string) $draft_step['next_step']['url'], 'event=' . $draft ), 'the step opens that draft' );

$facts['events'] = array( get_post( $event ) );
$fill            = LNKup_Organizer_Activation::build( $facts );
lnkup_t_same( 'fill_category', $fill['next_step']['stage'] ?? '', 'then a category nobody has applied for' );
lnkup_t_assert( false !== strpos( (string) $fill['next_step']['title'], $art_name ), 'the step names the category' );

wp_delete_term( (int) $food['term_id'], 'lnkup_vendor_category' );
wp_delete_term( (int) $art['term_id'], 'lnkup_vendor_category' );
lnkup_t_finish();
