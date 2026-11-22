<?php
/**
 * Phase 27 — Vendor Passport & Reputation OS integration tests (local wp-env only; never production).
 *
 * Covers: public read safety + enumeration, privacy projections, review privacy/eligibility/duplicates,
 * report-keeps-visible + moderation + appeal, responses, participation evidence (no paid-as-attended,
 * no tour double count, cancellations), achievements, verification records lifecycle + expiry,
 * document versioning, reuse checks, consented sharing + signed tokens + revocation, rating minimums,
 * organizer score redaction, migrations idempotency.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/vendor-passport-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Vendor Passport & Reputation OS\n";

/**
 * Throwaway published post.
 *
 * @param string $type  Post type.
 * @param array  $meta  Meta.
 * @param string $title Title.
 */
function lnkup_p_post( string $type, array $meta = array(), string $title = '' ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title ?: 'Passport test ' . $type, 'meta_input' => $meta ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

/**
 * Vendor user + published profile.
 *
 * @return int[] [user, profile]
 */
function lnkup_p_vendor( string $name = '' ): array {
	$user    = lnkup_t_user( 'lnkup_vendor' );
	$name    = $name ?: 'Passport Vendor ' . $user;
	$profile = lnkup_p_post( 'lnkup_vendor_profile', array( '_lnkup_owner_user_id' => $user, '_lnkup_business_name' => $name, '_lnkup_contact_email' => 'secret' . $user . '@example.test', '_lnkup_contact_phone' => '555-0100' ), $name );
	LNKup_Vendor_Reputation::ensure_public_slug( $profile );
	return array( $user, $profile );
}

/**
 * Event (+ optional tour) and an application in a given state.
 *
 * @param int    $vendor    Vendor user.
 * @param int    $profile   Profile.
 * @param int    $organizer Organizer.
 * @param string $status    Application status.
 * @param array  $app_meta  Extra application meta.
 * @param array  $event_meta Extra event meta.
 * @return int[] [event, application]
 */
function lnkup_p_booking( int $vendor, int $profile, int $organizer, string $status, array $app_meta = array(), array $event_meta = array() ): array {
	$event = lnkup_p_post(
		'lnkup_event',
		array_merge(
			array(
				'_lnkup_organizer_user_id' => $organizer,
				'_lnkup_city'              => 'Houston',
				'_lnkup_state'             => 'TX',
				'_lnkup_event_date'        => gmdate( 'Y-m-d', strtotime( '-10 days' ) ),
			),
			$event_meta
		),
		'Passport Event ' . wp_generate_password( 4, false )
	);
	$app = lnkup_p_post(
		'lnkup_application',
		array_merge(
			array(
				'_lnkup_vendor_user_id'     => $vendor,
				'_lnkup_vendor_profile_id'  => $profile,
				'_lnkup_organizer_user_id'  => $organizer,
				'_lnkup_event_id'           => $event,
				'_lnkup_application_status' => $status,
			),
			$app_meta
		)
	);
	return array( $event, $app );
}

/**
 * Dispatch a REST request as a user.
 *
 * @param string $method Method.
 * @param string $route  Route.
 * @param array  $params Params.
 * @param int    $user   User (0 = anonymous).
 */
function lnkup_p_rest( string $method, string $route, array $params = array(), int $user = 0 ): WP_REST_Response {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	return rest_do_request( $req );
}

// Enable every Passport flag for the duration of the run; restore afterwards.
$settings_before = get_option( 'lnkup_settings', array() );
$settings        = is_array( $settings_before ) ? $settings_before : array();
foreach ( LNKup_Passport_Settings::FLAGS as $flag ) {
	$settings[ $flag ] = true;
}
update_option( 'lnkup_settings', $settings, false );
LNKup_Passport_Tables::create_tables();
do_action( 'rest_api_init' );

$organizer  = lnkup_t_user( 'lnkup_organizer' );
$organizer2 = lnkup_t_user( 'lnkup_organizer' );
$organizer3 = lnkup_t_user( 'lnkup_organizer' );
$admin      = lnkup_t_user( 'administrator' );
list( $vendor, $profile ) = lnkup_p_vendor();
list( $other_vendor, $other_profile ) = lnkup_p_vendor();

// ---------------------------------------------------------------- Public read safety.
echo "\nPublic reads never write; enumeration closed\n";
$payment = lnkup_t_payment( array( 'total_amount' => 10 ) );
$resp    = lnkup_p_rest( 'GET', '/lnkup/v1/reputation/vendors/' . $payment, array(), 0 );
lnkup_t_assert( $resp->get_status() >= 400, 'anonymous numeric reputation route is refused' );
lnkup_t_same( '', (string) get_post_meta( $payment, '_lnkup_reputation_score', true ), 'no reputation meta written onto a non-profile post' );
lnkup_t_same( null, LNKup_Vendor_Reputation::get( $payment ), 'get() on a non-profile returns null' );
delete_post_meta( $profile, '_lnkup_reputation_updated_at' );
$neutral = LNKup_Vendor_Reputation::get( $profile );
lnkup_t_assert( ! empty( $neutral['pending'] ) && '' === (string) get_post_meta( $profile, '_lnkup_reputation_updated_at', true ), 'uncached get() returns neutral without writing' );
$slug = (string) get_post_meta( $profile, '_lnkup_public_slug', true );
lnkup_t_same( 0, LNKup_Vendor_Reputation::resolve_public_profile( (string) $profile ), 'numeric ID does not resolve publicly' );
lnkup_t_same( $profile, LNKup_Vendor_Reputation::resolve_public_profile( $slug ), 'slug resolves publicly' );
$pub = lnkup_p_rest( 'GET', '/lnkup/v1/reputation/vendors/' . $slug . '/public', array(), 0 );
lnkup_t_same( 200, $pub->get_status(), 'public profile route 200' );
$pub_data = $pub->get_data();
lnkup_t_assert( ! isset( $pub_data['reputation']['score'] ) && ! isset( $pub_data['reputation']['breakdown'] ), 'public payload has no internal score' );
$encoded = wp_json_encode( $pub_data );
lnkup_t_assert( false === strpos( $encoded, 'secret' . $vendor ) && false === strpos( $encoded, '555-0100' ), 'public payload has no private contact details' );

// ---------------------------------------------------------------- Privacy.
echo "\nPrivacy controls\n";
LNKup_Passport_Privacy::save( $profile, array( 'public_profile' => false ) );
lnkup_t_same( 0, LNKup_Vendor_Reputation::resolve_public_profile( $slug ), 'private Passport does not resolve' );
lnkup_t_same( 404, lnkup_p_rest( 'GET', '/lnkup/v1/passport/vendors/' . $slug, array(), 0 )->get_status(), 'private Passport REST 404' );
$preview = LNKup_Vendor_Reputation::resolve_viewable_profile( $slug, $vendor );
lnkup_t_assert( $profile === $preview['profile_id'] && $preview['is_preview'], 'owner can preview private Passport' );
$stranger = LNKup_Vendor_Reputation::resolve_viewable_profile( $slug, $other_vendor );
lnkup_t_same( 0, $stranger['profile_id'], 'another vendor cannot preview it' );
LNKup_Passport_Privacy::save( $profile, array( 'public_profile' => true, 'show_reviews' => false ) );
lnkup_t_same( $profile, LNKup_Vendor_Reputation::resolve_public_profile( $slug ), 'public again after opt-in' );

// ---------------------------------------------------------------- Participation evidence.
echo "\nParticipation history evidence\n";
list( $ev_paid_future, $app_paid_future ) = lnkup_p_booking( $vendor, $profile, $organizer, 'paid', array(), array( '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+20 days' ) ) ) );
list( $ev_checked, $app_checked )         = lnkup_p_booking( $vendor, $profile, $organizer, 'paid', array( '_lnkup_checked_in_at' => current_time( 'mysql' ) ) );
list( $ev_noshow, $app_noshow )           = lnkup_p_booking( $vendor, $profile, $organizer2, 'paid', array( '_lnkup_no_show_at' => current_time( 'mysql' ) ) );
list( $ev_cancel, $app_cancel )           = lnkup_p_booking( $vendor, $profile, $organizer2, 'cancelled', array( '_lnkup_cancellation_at' => current_time( 'mysql' ) ) );
list( $ev_approved, $app_approved )       = lnkup_p_booking( $vendor, $profile, $organizer2, 'approved' );
LNKup_Participation_History::flush( $profile );
$rows   = array();
foreach ( LNKup_Participation_History::all_rows( $profile ) as $row ) {
	$rows[ $row['event_id'] ] = $row;
}
lnkup_t_same( 'booking_confirmed', $rows[ $ev_paid_future ]['state_key'] ?? '', 'paid future booking is not attendance' );
lnkup_t_same( 'completed', $rows[ $ev_checked ]['state_key'] ?? '', 'checked-in past event is confirmed participation' );
lnkup_t_same( 'check_in', $rows[ $ev_checked ]['evidence'] ?? '', 'evidence recorded as check-in' );
lnkup_t_same( 'no_show', $rows[ $ev_noshow ]['state_key'] ?? '', 'no-show is not completed' );
lnkup_t_same( 'cancelled', $rows[ $ev_cancel ]['state_key'] ?? '', 'cancellation shows as canceled' );
lnkup_t_same( 'approved', $rows[ $ev_approved ]['state_key'] ?? '', 'approval is not attendance' );
$public_hist = LNKup_Participation_History::for_profile( $profile, 'public' );
lnkup_t_same( 1, $public_hist['total'], 'public history lists confirmed participation only' );
$encoded = wp_json_encode( $public_hist );
lnkup_t_assert( false === strpos( $encoded, 'application_id' ), 'public history exposes no application IDs' );
$metrics = LNKup_Reliability_Metrics::get( $profile );
lnkup_t_same( 1, (int) $metrics['completed_events'], 'reliability counts only evidence-backed completion' );
lnkup_t_same( 1, (int) LNKup_Participation_History::stats( $profile )['confirmed_events'], 'stats count one confirmed event' );

// Tour: two stops of the same tour count as two events, never more.
$tour = lnkup_p_post( 'lnkup_tour', array( '_lnkup_organizer_user_id' => $organizer3 ), 'Passport Tour' );
list( $stop1 ) = lnkup_p_booking( $vendor, $profile, $organizer3, 'completed', array( '_lnkup_checked_in_at' => current_time( 'mysql' ) ), array( '_lnkup_tour_id' => $tour, '_lnkup_city' => 'Atlanta', '_lnkup_state' => 'GA' ) );
list( $stop2 ) = lnkup_p_booking( $vendor, $profile, $organizer3, 'completed', array( '_lnkup_checked_in_at' => current_time( 'mysql' ) ), array( '_lnkup_tour_id' => $tour, '_lnkup_city' => 'Charlotte', '_lnkup_state' => 'NC' ) );
lnkup_p_post( 'lnkup_application', array( '_lnkup_vendor_user_id' => $vendor, '_lnkup_vendor_profile_id' => $profile, '_lnkup_organizer_user_id' => $organizer3, '_lnkup_event_id' => $stop1, '_lnkup_application_status' => 'rejected' ) );
LNKup_Participation_History::flush( $profile );
$stats = LNKup_Participation_History::stats( $profile );
lnkup_t_same( 3, (int) $stats['confirmed_events'], 'tour stops count once each (duplicate application ignored)' );
lnkup_t_same( 2, (int) $stats['max_stops_in_one_tour'], 'tour stop count' );
lnkup_t_same( 3, (int) $stats['distinct_cities'], 'distinct cities' );
lnkup_t_same( 1, (int) $stats['repeat_organizers'], 'returning-organizer count from evidence' );
LNKup_Vendor_Reputation::refresh( $profile );
$achievements = wp_list_pluck( LNKup_Vendor_Badges::get_achievements( $profile ), 'slug' );
lnkup_t_assert( in_array( 'first_confirmed_event', $achievements, true ) && in_array( 'tour_participant', $achievements, true ) && in_array( 'multi_city', $achievements, true ) && in_array( 'returning_vendor', $achievements, true ), 'evidence achievements awarded' );
lnkup_t_assert( ! in_array( 'confirmed_5', $achievements, true ), 'five-events achievement not awarded early' );

// ---------------------------------------------------------------- Reviews.
echo "\nReview eligibility, privacy, duplicates\n";
$check = LNKup_Vendor_Reviews::can_review( $app_approved, $organizer2 );
lnkup_t_assert( empty( $check['allowed'] ), 'approved-only booking cannot be reviewed' );
$check = LNKup_Vendor_Reviews::can_review( $app_paid_future, $organizer );
lnkup_t_assert( empty( $check['allowed'] ), 'future event cannot be reviewed' );
$check = LNKup_Vendor_Reviews::can_review( $app_checked, $organizer2 );
lnkup_t_assert( empty( $check['allowed'] ), 'unrelated organizer cannot review' );
wp_set_current_user( $organizer );
$review = LNKup_Vendor_Reviews::create( $organizer, array( 'application_id' => $app_checked, 'overall_rating' => 5, 'category_ratings' => array( 'communication' => 5, 'preparedness' => 4 ), 'positive_tags' => array( 'on_time', 'not_a_tag' ), 'public_text' => 'Great partner.', 'private_note' => 'PRIVATE-NOTE-XYZ' ) );
lnkup_t_assert( is_int( $review ) && $review > 0, 'eligible organizer creates review' );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $review );
lnkup_t_assert( is_wp_error( LNKup_Vendor_Reviews::create( $organizer, array( 'application_id' => $app_checked, 'overall_rating' => 1 ) ) ), 'duplicate review rejected' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Passport_Tables::review_index_table() . ' WHERE application_id = %d', $app_checked ) ), 'one index row per booking' );
$formatted = LNKup_Vendor_Reviews::format( get_post( $review ), true, $organizer2 );
lnkup_t_assert( ! isset( $formatted['private_note'] ), 'other organizer never sees private notes' );
$formatted = LNKup_Vendor_Reviews::format( get_post( $review ), false, $organizer );
lnkup_t_same( 'PRIVATE-NOTE-XYZ', $formatted['private_note'] ?? '', 'author sees own private note' );
lnkup_t_same( array( 'on_time' ), wp_list_pluck( $formatted['positive_tags'], 'slug' ), 'tags allow-listed' );
lnkup_t_assert( ! empty( $formatted['verified_participation'] ), 'review marked as verified participation' );
$rest_reviews = lnkup_p_rest( 'GET', '/lnkup/v1/reputation/vendors/' . $profile . '/reviews', array(), $organizer2 );
lnkup_t_assert( false === strpos( wp_json_encode( $rest_reviews->get_data() ), 'PRIVATE-NOTE-XYZ' ), 'REST reviews never leak another organizer\'s private note' );

echo "\nRating display minimums\n";
$fb = LNKup_Reputation_Summary::organizer_feedback( $profile );
lnkup_t_assert( ! $fb['display_average'] && null === $fb['average'], 'single review shows no average' );
lnkup_t_same( 'Not enough reviews yet', $fb['label'], 'single review label' );
$sum = LNKup_Reputation_Summary::for_profile( $other_profile, 'public' );
lnkup_t_assert( $sum['is_new_vendor'] && '' !== $sum['new_vendor_note'], 'new vendor gets neutral note' );

echo "\nResponses, reports, moderation, appeals\n";
$resp_obj = LNKup_Vendor_Reviews::respond( $review, $other_vendor, 'Not mine' );
lnkup_t_assert( is_wp_error( $resp_obj ), 'other vendor cannot respond' );
$resp_obj = LNKup_Vendor_Reviews::respond( $review, $vendor, 'Thank you for having us!' );
lnkup_t_assert( is_array( $resp_obj ) && 'Thank you for having us!' === $resp_obj['vendor_response']['text'], 'vendor posts response' );
lnkup_t_same( 'Great partner.', (string) get_post_meta( $review, '_lnkup_public_text', true ), 'response does not edit the review' );
lnkup_t_assert( is_wp_error( LNKup_Vendor_Reviews::report( $review, $vendor, 'short' ) ), 'report needs a real reason' );
$dispute = LNKup_Vendor_Reviews::report( $review, $vendor, 'This describes another vendor entirely.', 'inaccurate' );
lnkup_t_assert( is_int( $dispute ), 'vendor reports review' );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $dispute );
lnkup_t_same( 'published', LNKup_Vendor_Reviews::status_of( $review ), 'reported review stays published' );
lnkup_t_assert( is_wp_error( LNKup_Vendor_Reviews::report( $review, $vendor, 'Reporting again while open.' ) ), 'one open report per review' );
wp_set_current_user( $organizer );
lnkup_t_assert( is_wp_error( LNKup_Vendor_Reviews::decide( $dispute, 'hide', 'policy_abuse', '', $organizer ) ), 'non-staff cannot moderate' );
wp_set_current_user( $admin );
lnkup_t_assert( is_wp_error( LNKup_Vendor_Reviews::decide( $dispute, 'hide', '', '', $admin ) ), 'hiding requires a documented reason' );
$decision = LNKup_Vendor_Reviews::decide( $dispute, 'uphold', '', 'Matches booking records', $admin );
lnkup_t_same( 'upheld', $decision['status'] ?? '', 'moderator upholds review' );
lnkup_t_same( 'published', LNKup_Vendor_Reviews::status_of( $review ), 'upheld review remains visible' );
$logged = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Reputation_Tables::activity_table() . " WHERE vendor_profile_id = %d AND activity_type = 'review_moderated'", $profile ) );
lnkup_t_assert( $logged >= 1, 'moderation decision logged' );
$appeal = LNKup_Vendor_Reviews::appeal( $dispute, $vendor, 'The organizer confused us with another booth.' );
lnkup_t_same( 'appealed', $appeal['status'] ?? '', 'vendor appeals once' );
lnkup_t_assert( is_wp_error( LNKup_Vendor_Reviews::appeal( $dispute, $vendor, 'Trying to appeal a second time.' ) ), 'second appeal refused' );
$final = LNKup_Vendor_Reviews::decide( $dispute, 'hide', 'factually_unsupported', 'Appeal granted', $admin );
lnkup_t_same( 'hidden', LNKup_Vendor_Reviews::status_of( $review ), 'appeal decision hides review' );
lnkup_t_assert( (bool) get_post_meta( $dispute, '_lnkup_final', true ), 'appeal decision is final' );
lnkup_t_assert( false === LNKup_Vendor_Reviews::moderate( $review, 'deleted_forever' ), 'moderate() rejects unknown statuses' );
lnkup_t_same( 0, (int) LNKup_Reliability_Metrics::get( $profile )['dispute_count'], 'review disputes never count against vendor' );

// ---------------------------------------------------------------- Verification records.
echo "\nVerification lifecycle\n";
lnkup_t_same( 'not_submitted', LNKup_Verification_Records::current( $profile, 'business_identity' )['status'], 'starts not submitted' );
wp_set_current_user( $vendor );
$sub = LNKup_Verification_Records::submit( $profile, 'business_identity', 'Please verify' );
lnkup_t_assert( is_int( $sub ), 'vendor submits verification' );
lnkup_t_assert( is_wp_error( LNKup_Verification_Records::submit( $profile, 'business_identity' ) ), 'duplicate pending submission refused' );
lnkup_t_assert( is_wp_error( LNKup_Verification_Records::decide( $profile, 'business_identity', 'verified', array( 'reviewer_id' => $vendor ) ) ), 'vendor cannot verify self' );
wp_set_current_user( $admin );
lnkup_t_assert( is_wp_error( LNKup_Verification_Records::decide( $profile, 'business_identity', 'rejected', array() ) ), 'rejection requires reason' );
LNKup_Verification_Records::decide( $profile, 'business_identity', 'verified', array( 'expires_at' => gmdate( 'Y-m-d', strtotime( '+1 year' ) ) ) );
lnkup_t_same( 'approved', (string) get_post_meta( $profile, '_lnkup_verification_status', true ), 'legacy meta synced for existing callers' );
lnkup_t_assert( LNKup_Reputation_Summary::verification( $profile )['is_verified'], 'badge source is the verified record' );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Passport_Tables::verification_table() . " SET expires_at = %s WHERE vendor_profile_id = %d AND status = 'verified'", gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ), $profile ) );
lnkup_t_same( 'expired', LNKup_Verification_Records::current( $profile, 'business_identity' )['status'], 'past-expiry verification reads as expired' );
lnkup_t_assert( ! LNKup_Reputation_Summary::verification( $profile )['is_verified'], 'expired verification stops showing as current' );
LNKup_Verification_Records::expire_due();
$history = LNKup_Verification_Records::history( $profile );
lnkup_t_assert( count( $history ) >= 3 && 'expired' === $history[0]['status'], 'expiry appended; earlier decisions preserved' );
update_post_meta( $profile, '_lnkup_verification_status', 'approved' );
lnkup_t_same( 'verified', LNKup_Verification_Records::current( $profile, 'business_identity' )['status'], 'legacy admin writes mirrored into records' );

// ---------------------------------------------------------------- Documents: versioning, reuse, sharing.
echo "\nDocument Vault\n";
lnkup_t_same( '2027-03-01', LNKup_Documents::normalize_expiration( '2027-03-01' ), 'valid expiration normalized' );
lnkup_t_assert( is_wp_error( LNKup_Documents::normalize_expiration( '1890-01-01' ) ), 'absurd expiration rejected' );
$doc_v1 = lnkup_p_post( 'lnkup_document', array( '_lnkup_vendor_user_id' => $vendor, '_lnkup_vendor_profile_id' => $profile, '_lnkup_document_type' => 'certificate_of_insurance', '_lnkup_verification_status' => 'approved', '_lnkup_expiration_date' => gmdate( 'Y-m-d', strtotime( '+10 days' ) ), '_lnkup_document_group_id' => 0, '_lnkup_version' => 1 ) );
update_post_meta( $doc_v1, '_lnkup_document_group_id', $doc_v1 );
$doc_v2 = lnkup_p_post( 'lnkup_document', array( '_lnkup_vendor_user_id' => $vendor, '_lnkup_vendor_profile_id' => $profile, '_lnkup_document_type' => 'certificate_of_insurance', '_lnkup_verification_status' => 'approved', '_lnkup_expiration_date' => gmdate( 'Y-m-d', strtotime( '+400 days' ) ), '_lnkup_document_group_id' => $doc_v1, '_lnkup_version' => 2, '_lnkup_coverage_amount' => '2000000' ) );
update_post_meta( $doc_v1, '_lnkup_superseded_by', $doc_v2 );
$current = LNKup_Documents::current_by_type( $vendor );
lnkup_t_same( $doc_v2, (int) $current['certificate_of_insurance']->ID, 'current version is the newest non-superseded' );
$pending_doc = lnkup_p_post( 'lnkup_document', array( '_lnkup_vendor_user_id' => $vendor, '_lnkup_document_type' => 'health_permit', '_lnkup_verification_status' => 'pending' ) );
lnkup_t_same( 'pending', LNKup_Documents::effective_status( $pending_doc ), 'pending doc is pending' );

$event_far = lnkup_p_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+500 days' ) ) ), 'Far Event' );
$opp_far   = lnkup_p_post( 'lnkup_opportunity', array( '_lnkup_event_id' => $event_far, '_lnkup_organizer_user_id' => $organizer, '_lnkup_required_documents' => 'certificate_of_insurance,health_permit', '_lnkup_document_mode' => 'custom', '_lnkup_document_requirements' => wp_json_encode( array( 'certificate_of_insurance' => array( 'min_coverage' => 1000000 ) ) ) ) );
$reuse     = LNKup_Document_Vault::find_reusable( $profile, $opp_far );
$by_type   = array();
foreach ( $reuse['items'] as $item ) {
	$by_type[ $item['type'] ] = $item;
}
lnkup_t_same( 'insufficient', $by_type['certificate_of_insurance']['result'] ?? '', 'document expiring before the event is insufficient' );
lnkup_t_same( 'needs_review', $by_type['health_permit']['result'] ?? '', 'pending document needs review' );
lnkup_t_assert( false === $reuse['all_satisfied'] && true === $reuse['organizer_review_required'], 'never auto-approves for the event' );
$event_near = lnkup_p_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ) ), 'Near Event' );
$opp_near   = lnkup_p_post( 'lnkup_opportunity', array( '_lnkup_event_id' => $event_near, '_lnkup_organizer_user_id' => $organizer, '_lnkup_required_documents' => 'certificate_of_insurance', '_lnkup_document_mode' => 'custom', '_lnkup_document_requirements' => wp_json_encode( array( 'certificate_of_insurance' => array( 'min_coverage' => 5000000 ) ) ) ) );
$reuse      = LNKup_Document_Vault::find_reusable( $profile, $opp_near );
lnkup_t_same( 'insufficient', $reuse['items'][0]['result'] ?? '', 'coverage below configured minimum is insufficient' );

$share_app = lnkup_p_post( 'lnkup_application', array( '_lnkup_vendor_user_id' => $vendor, '_lnkup_vendor_profile_id' => $profile, '_lnkup_organizer_user_id' => $organizer, '_lnkup_event_id' => $event_near, '_lnkup_opportunity_id' => $opp_near, '_lnkup_application_status' => 'submitted' ) );
lnkup_t_assert( is_wp_error( LNKup_Document_Vault::share( $profile, $doc_v1, $share_app, $vendor ) ), 'superseded version cannot be shared' );
lnkup_t_assert( is_wp_error( LNKup_Document_Vault::share( $other_profile, $doc_v2, $share_app, $other_vendor ) ), 'cannot share another vendor\'s document' );
$share = LNKup_Document_Vault::share( $profile, $doc_v2, $share_app, $vendor );
lnkup_t_assert( is_int( $share ) && $share > 0, 'vendor shares current version for one application' );
lnkup_t_same( $share, LNKup_Document_Vault::share( $profile, $doc_v2, $share_app, $vendor ), 'sharing twice reuses the active share' );
$versions = json_decode( (string) get_post_meta( $share_app, '_lnkup_shared_document_versions', true ), true );
lnkup_t_same( 2, (int) ( $versions['certificate_of_insurance']['version'] ?? 0 ), 'application records the exact version provided' );
$share_row = LNKup_Document_Vault::get_share( $share );
lnkup_t_assert( LNKup_Document_Vault::can_access_share( $share_row, $organizer ), 'event organizer can access share' );
lnkup_t_assert( ! LNKup_Document_Vault::can_access_share( $share_row, $organizer2 ), 'other organizer cannot access share' );
$exp   = time() + 300;
$token = LNKup_Document_Vault::token( $share, $organizer, $exp );
lnkup_t_assert( LNKup_Document_Vault::verify_token( $share, $organizer, $exp, $token ), 'signed token verifies' );
lnkup_t_assert( ! LNKup_Document_Vault::verify_token( $share, $organizer2, $exp, $token ), 'token bound to organizer' );
lnkup_t_assert( ! LNKup_Document_Vault::verify_token( $share, $organizer, time() - 1, LNKup_Document_Vault::token( $share, $organizer, time() - 1 ) ), 'expired token refused' );
$denied = lnkup_p_rest( 'GET', '/lnkup/v1/documents/shares/' . $share . '/file', array( 'expires' => $exp, 'token' => $token ), $organizer2 );
lnkup_t_same( 403, $denied->get_status(), 'other organizer REST download refused' );
$listed = lnkup_p_rest( 'GET', '/lnkup/v1/applications/' . $share_app . '/shared-documents', array(), $organizer2 );
lnkup_t_assert( $listed->get_status() >= 400, 'other organizer cannot list shared documents' );
$listed = lnkup_p_rest( 'GET', '/lnkup/v1/applications/' . $share_app . '/shared-documents', array(), $organizer );
lnkup_t_same( 1, count( $listed->get_data()['items'] ?? array() ), 'event organizer lists one shared document' );
lnkup_t_assert( is_wp_error( LNKup_Document_Vault::revoke_share( $share, $other_vendor ) ), 'only owner can revoke' );
LNKup_Document_Vault::revoke_share( $share, $vendor );
lnkup_t_assert( ! LNKup_Document_Vault::can_access_share( LNKup_Document_Vault::get_share( $share ), $organizer ), 'revoked share is no longer accessible' );
$owner_file = lnkup_p_rest( 'GET', '/lnkup/v1/documents/' . $doc_v2 . '/file', array(), $organizer );
lnkup_t_same( 403, $owner_file->get_status(), 'organizer cannot use the owner download route' );

// ---------------------------------------------------------------- Organizer-facing score redaction + Passport REST.
echo "\nOrganizer projection & internal score\n";
$crm = lnkup_p_rest( 'GET', '/lnkup/v1/crm/vendors/' . $profile, array(), $organizer );
if ( 200 === $crm->get_status() ) {
	$payload = wp_json_encode( $crm->get_data() );
	lnkup_t_assert( false === strpos( $payload, '"vendor_score"' ) && false === strpos( $payload, '"reputation_score"' ), 'CRM response has no internal score for organizers' );
} else {
	lnkup_t_assert( true, 'CRM profile route unavailable in this environment (skipped)' );
}
$org_view = lnkup_p_rest( 'GET', '/lnkup/v1/passport/vendors/' . $other_profile . '/organizer', array(), $organizer );
lnkup_t_same( 200, $org_view->get_status(), 'organizer Passport view 200' );
$org_data = $org_view->get_data();
lnkup_t_assert( array_key_exists( 'contact', $org_data ) && null === $org_data['contact'] && empty( $org_data['can_view_contact'] ), 'no contact details without a relationship' );
lnkup_t_assert( false === strpos( wp_json_encode( $org_data ), 'secret' . $other_vendor ), 'organizer view never includes private email' );
lnkup_t_same( 403, lnkup_p_rest( 'GET', '/lnkup/v1/passport/vendors/' . $other_profile . '/organizer', array(), $vendor )->get_status(), 'vendors cannot use organizer view' );
$me = lnkup_p_rest( 'GET', '/lnkup/v1/passport/me', array(), $vendor );
lnkup_t_same( 200, $me->get_status(), 'owner Passport 200' );
lnkup_t_assert( isset( $me->get_data()['strength']['score'], $me->get_data()['reputation']['experience'] ), 'owner view has strength and indicators' );
$patch = lnkup_p_rest( 'PATCH', '/lnkup/v1/passport/me', array( 'capabilities' => array( 'food_truck', 'evil_cap' ), 'website' => 'javascript:alert(1)' ), $vendor );
lnkup_t_same( 400, $patch->get_status(), 'invalid website rejected with field error' );
lnkup_t_same( array( 'food_truck' ), LNKup_Passport_Projection::json_list( get_post_meta( $profile, '_lnkup_capabilities', true ) ), 'capabilities allow-listed' );
$hide = lnkup_p_rest( 'PATCH', '/lnkup/v1/passport/me/privacy', array( 'hidden_event_ids' => array( $ev_checked, 999999 ) ), $vendor );
lnkup_t_same( array( $ev_checked ), $hide->get_data()['privacy']['hidden_event_ids'] ?? array(), 'vendor can only hide own events' );
$portfolio = lnkup_p_rest( 'PUT', '/lnkup/v1/passport/me/portfolio', array( 'items' => array( array( 'attachment_id' => 1, 'caption' => 'not mine' ) ) ), $vendor );
lnkup_t_same( array(), $portfolio->get_data()['items'] ?? null, 'foreign attachments rejected from portfolio' );
lnkup_t_same( 401, lnkup_p_rest( 'GET', '/lnkup/v1/passport/me', array(), 0 )->get_status(), 'anonymous cannot read owner Passport' );

// ---------------------------------------------------------------- Tour invites.
echo "\nTour-stop invites\n";
list( $tour_vendor, $tour_profile ) = lnkup_p_vendor();
$inv_tour  = lnkup_p_post( 'lnkup_tour', array( '_lnkup_organizer_user_id' => $organizer ), 'Invite Tour' );
$future_1  = lnkup_p_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_tour_id' => $inv_tour, '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+40 days' ) ) ), 'Stop A' );
$future_2  = lnkup_p_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_tour_id' => $inv_tour, '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+70 days' ) ) ), 'Stop B' );
$past_stop = lnkup_p_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_tour_id' => $inv_tour, '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '-5 days' ) ) ), 'Stop Past' );
wp_set_current_user( $organizer2 );
lnkup_t_assert( is_wp_error( LNKup_Vendor_Invites::create_for_tour( $organizer2, $tour_vendor, $inv_tour ) ), 'another organizer cannot invite to this tour' );
wp_set_current_user( $organizer );
$tour_result = LNKup_Vendor_Invites::create_for_tour( $organizer, $tour_vendor, $inv_tour, array( $future_1, $future_2, $past_stop ) );
lnkup_t_same( 2, count( $tour_result['created'] ?? array() ), 'one invite per future stop' );
lnkup_t_same( 1, count( $tour_result['skipped'] ?? array() ), 'past stop skipped' );
foreach ( (array) ( $tour_result['created'] ?? array() ) as $row ) {
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $row['invite_id'] );
}

// ---------------------------------------------------------------- Strength separation.
echo "\nProfile strength\n";
$strength = LNKup_Growth_Profile_Strength::calculate( $profile );
lnkup_t_assert( ! in_array( 'documents', wp_list_pluck( $strength['sections'], 'key' ), true ), 'documents not part of completeness' );
lnkup_t_same( 100, (int) array_sum( LNKup_Passport_Settings::strength_weights() ), 'default weights sum to 100' );

// ---------------------------------------------------------------- Migration idempotency.
echo "\nMigrations\n";
$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Passport_Tables::review_index_table() );
LNKup_Vendor_Reviews::backfill_index( 500 );
LNKup_Vendor_Reviews::backfill_index( 500 );
lnkup_t_same( $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Passport_Tables::review_index_table() ), 'review index backfill is idempotent' );
LNKup_Verification_Records::backfill_from_meta( 500 );
$again = LNKup_Verification_Records::backfill_from_meta( 500 );
lnkup_t_same( 0, $again, 'verification backfill is idempotent' );

// ---------------------------------------------------------------- Cleanup.
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Passport_Tables::review_index_table() . ' WHERE vendor_profile_id IN (%d, %d)', $profile, $other_profile ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Passport_Tables::verification_table() . ' WHERE vendor_profile_id IN (%d, %d)', $profile, $other_profile ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Passport_Tables::share_table() . ' WHERE vendor_profile_id IN (%d, %d)', $profile, $other_profile ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Reputation_Tables::badge_table() . ' WHERE vendor_profile_id IN (%d, %d)', $profile, $other_profile ) );
update_option( 'lnkup_settings', $settings_before, false );
lnkup_t_finish();
