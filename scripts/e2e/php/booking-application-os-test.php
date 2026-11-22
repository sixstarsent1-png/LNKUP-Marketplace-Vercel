<?php
/**
 * Booking & Application OS foundation: integrity fixes (B-1…B-18), server-side drafts,
 * organizer questions, multi-stop tour applications.
 *
 * No Stripe calls. Webhook events are exercised through the log/claim layer and handlers that
 * only read/write WordPress records.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/booking-application-os-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Booking & Application OS\n";

/**
 * Published post removed at the end of the run.
 *
 * @param string $type Post type.
 * @param array  $meta Meta.
 * @param string $title Title.
 */
function lnkup_b_post( string $type, array $meta = array(), string $title = '' ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title ?: 'BAOS test ' . $type, 'meta_input' => $meta ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

/**
 * Event + one open opportunity (indexed).
 *
 * @param int   $organizer Organizer.
 * @param array $opp_meta  Opportunity meta overrides.
 * @param array $event_meta Event meta overrides.
 * @return int[] [event, opportunity]
 */
function lnkup_b_event( int $organizer, array $opp_meta = array(), array $event_meta = array() ): array {
	$event = lnkup_b_post(
		'lnkup_event',
		array_merge(
			array(
				'_lnkup_organizer_user_id' => $organizer,
				'_lnkup_city'              => 'Houston',
				'_lnkup_state'             => 'TX',
				'_lnkup_event_date'        => gmdate( 'Y-m-d', strtotime( '+60 days' ) ),
			),
			$event_meta
		)
	);
	$opp = lnkup_b_post(
		'lnkup_opportunity',
		array_merge(
			array(
				'_lnkup_event_id'             => $event,
				'_lnkup_organizer_user_id'    => $organizer,
				'_lnkup_opportunity_status'   => 'open',
				'_lnkup_vendor_fee'           => '150',
				'_lnkup_spots_total'          => 5,
				'_lnkup_spots_remaining'      => 5,
				'_lnkup_required_documents'   => 'none',
				'_lnkup_application_deadline' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
			),
			$opp_meta
		),
		'Booth'
	);
	LNKup_Opportunity_Inventory::recompute( $opp );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	return array( $event, $opp );
}

/**
 * Vendor user with a published profile.
 */
function lnkup_b_vendor(): int {
	$user    = lnkup_t_user( 'lnkup_vendor' );
	$profile = lnkup_b_post( 'lnkup_vendor_profile', array( '_lnkup_owner_user_id' => $user, '_lnkup_business_name' => 'BAOS Vendor ' . $user, '_lnkup_contact_email' => 'v' . $user . '@example.test' ), 'BAOS Vendor ' . $user );
	return $user;
}

/**
 * Submit as a vendor.
 *
 * @param int   $vendor Vendor.
 * @param int   $opp    Opportunity.
 * @param array $extra  Extra args.
 * @return int|WP_Error
 */
function lnkup_b_apply( int $vendor, int $opp, array $extra = array() ) {
	wp_set_current_user( $vendor );
	$result = LNKup_Applications::submit_with_profile( $vendor, $opp, array_merge( array( 'accepted_terms' => true, 'selected_category' => 'General' ), $extra ) );
	if ( ! is_wp_error( $result ) ) {
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $result );
	}
	return $result;
}

$organizer = lnkup_t_user( 'lnkup_organizer' );
$admin     = lnkup_t_user( 'administrator' );

// ---------------------------------------------------------------- B-1 invoice verify token.
echo "\nInvoice verification (B-1)\n";
$invoices = LNKup_Financial_Tables::invoices_table();
$token    = wp_generate_password( 24, false );
$wpdb->insert( $invoices, array( 'invoice_number' => 'INV-BAOS' . wp_generate_password( 4, false ), 'status' => 'sent', 'total_amount' => 10, 'subtotal' => 10, 'metadata' => wp_json_encode( array( 'verify_token' => $token ) ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$inv_id = (int) $wpdb->insert_id;
lnkup_t_same( null, LNKup_Invoices::verify_by_token( '%' ), 'wildcard token matches nothing' );
lnkup_t_same( null, LNKup_Invoices::verify_by_token( 'verify' ), 'substring token matches nothing' );
lnkup_t_same( null, LNKup_Invoices::verify_by_token( substr( $token, 0, 12 ) ), 'partial token matches nothing' );
lnkup_t_same( $inv_id, (int) ( LNKup_Invoices::verify_by_token( $token )['id'] ?? 0 ), 'exact token finds the invoice' );
$wpdb->delete( $invoices, array( 'id' => $inv_id ) );

// ---------------------------------------------------------------- B-2 / B-4 webhooks.
echo "\nWebhooks (B-2, B-4)\n";
$req = new WP_REST_Request( 'POST', '/lnkup/v1/stripe-webhook' );
$req->set_body( wp_json_encode( array( 'id' => 'evt_unsigned_' . wp_generate_password( 6, false ), 'type' => 'payment_intent.succeeded', 'data' => array( 'object' => array() ) ) ) );
$resp = LNKup_Financial_Service::handle_webhook( $req );
lnkup_t_same( 400, $resp->get_status(), 'unsigned webhook is rejected' );

$evt     = array( 'id' => 'evt_baos_' . wp_generate_password( 8, false ), 'type' => 'customer.created', 'data' => array( 'object' => array() ) );
$log_a   = LNKup_Stripe_Webhooks::log_event( $evt );
$log_b   = LNKup_Stripe_Webhooks::log_event( $evt );
lnkup_t_assert( $log_a > 0 && $log_a === $log_b, 'duplicate delivery resolves to the same log row' );
lnkup_t_assert( LNKup_Stripe_Webhooks::claim_event( $log_a ), 'first delivery claims the event' );
lnkup_t_assert( ! LNKup_Stripe_Webhooks::claim_event( $log_b ), 'concurrent duplicate cannot claim it again' );
$wpdb->delete( LNKup_Financial_Tables::webhook_events_table(), array( 'id' => $log_a ) );
lnkup_t_same( 0, LNKup_Stripe_Webhooks::log_event( array( 'type' => 'x' ) ), 'events without an id are not logged' );

// ---------------------------------------------------------------- Eligibility (B-14, B-15, B-16).
echo "\nEligibility\n";
list( $ev_unl, $opp_unl ) = lnkup_b_event( $organizer, array( '_lnkup_spots_total' => 0, '_lnkup_spots_remaining' => 0 ) );
$card = lnkup_get_opportunity_card_data( $opp_unl );
lnkup_t_assert( ! empty( $card['is_open'] ) && ! empty( $card['spots_unlimited'] ), 'capacity 0 is unlimited and open (B-14)' );
$v_unl = lnkup_b_vendor();
$a_unl = lnkup_b_apply( $v_unl, $opp_unl );
lnkup_t_assert( is_int( $a_unl ) && $a_unl > 0, 'vendor can apply to an unlimited slot' );

list( $ev_inv, $opp_inv ) = lnkup_b_event( $organizer, array( '_lnkup_invite_only' => 1 ) );
$v_inv = lnkup_b_vendor();
$blocked = lnkup_b_apply( $v_inv, $opp_inv );
lnkup_t_same( 'invite_only', is_wp_error( $blocked ) ? $blocked->get_error_code() : 'none', 'invite-only slot blocks uninvited vendors (B-15)' );
wp_set_current_user( 0 );
lnkup_t_assert( is_wp_error( LNKup_Applications::build_apply_context( $opp_inv ) ), 'invite-only apply context is hidden from logged-out visitors' );

list( $ev_re, $opp_re ) = lnkup_b_event( $organizer );
$v_re  = lnkup_b_vendor();
$a_re1 = lnkup_b_apply( $v_re, $opp_re );
lnkup_t_same( 'duplicate', is_wp_error( $dup = lnkup_b_apply( $v_re, $opp_re ) ) ? $dup->get_error_code() : 'none', 'active application blocks a duplicate' );
LNKup_Applications::withdraw( $a_re1, $v_re );
$a_re2 = lnkup_b_apply( $v_re, $opp_re );
lnkup_t_assert( is_int( $a_re2 ) && $a_re2 !== $a_re1, 'vendor can re-apply after withdrawing (B-16)' );
$idem_a = lnkup_b_apply( lnkup_b_vendor(), $opp_re, array( 'idempotency_key' => 'idem-baos-1' ) );
$idem_v = (int) get_post_meta( $idem_a, '_lnkup_vendor_user_id', true );
lnkup_t_same( $idem_a, lnkup_b_apply( $idem_v, $opp_re, array( 'idempotency_key' => 'idem-baos-1' ) ), 'same idempotency key returns the same application' );
lnkup_t_assert( count( LNKup_Applications::status_history( $a_re2 ) ) >= 1, 'submit is recorded in the status history' );

// ---------------------------------------------------------------- Approval / payment (B-5, B-6, B-7, B-17).
echo "\nApproval and payment\n";
list( $ev_p, $opp_p ) = lnkup_b_event( $organizer );
$v_p = lnkup_b_vendor();
$app = lnkup_b_apply( $v_p, $opp_p );
wp_set_current_user( $organizer );
lnkup_t_same( true, LNKup_Applications::transition( $app, 'approved' ), 'organizer approves' );
lnkup_t_same( 'payment_required', get_post_meta( $app, '_lnkup_application_status', true ), 'approval moves to payment_required' );
lnkup_t_assert( '' !== (string) get_post_meta( $app, '_lnkup_decided_at', true ), '_lnkup_decided_at is written (B-17)' );
$hist = wp_list_pluck( LNKup_Applications::status_history( $app ), 'to' );
lnkup_t_assert( in_array( 'approved', $hist, true ) && in_array( 'payment_required', $hist, true ), 'history keeps the approved step before payment_required' );
$pay1 = LNKup_Payments::get_for_application( $app );
lnkup_t_assert( $pay1 && LNKup_Payments::is_payable( $pay1->ID ), 'approval creates a payable payment' );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $pay1->ID );
$items = LNKup_Invoices::get_items( (int) ( LNKup_Invoices::get_by_application( $app )['id'] ?? 0 ) );
lnkup_t_same( round( (float) get_post_meta( $pay1->ID, '_lnkup_total_amount', true ), 2 ), round( array_sum( array_map( 'floatval', wp_list_pluck( $items, 'line_total' ) ) ), 2 ), 'invoice line items add up to the payment total (B-11)' );

LNKup_Applications::transition( $app, 'rejected', array( 'reason' => 'test' ) );
lnkup_t_assert( '' !== (string) get_post_meta( $pay1->ID, '_lnkup_voided_at', true ), 'rejection voids the pending payment' );
lnkup_t_same( 'void', LNKup_Invoices::get_by_application( $app )['status'] ?? '', 'rejection voids the invoice' );
LNKup_Applications::transition( $app, 'under_review' );
LNKup_Applications::transition( $app, 'approved' );
$pay2 = LNKup_Payments::get_for_application( $app );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $pay2->ID );
lnkup_t_assert( $pay2 && (int) $pay2->ID !== (int) $pay1->ID && LNKup_Payments::is_payable( $pay2->ID ), 're-approval gets a fresh payable payment (B-5)' );

// Paid after rejection must not rebook (B-7).
LNKup_Applications::transition( $app, 'rejected', array( 'reason' => 'late' ) );
update_post_meta( $pay2->ID, '_lnkup_payment_status', 'processing' );
delete_post_meta( $pay2->ID, '_lnkup_voided_at' );
LNKup_Payments::mark_paid( $pay2->ID, 'pi_baos_late' );
lnkup_t_same( 'rejected', get_post_meta( $app, '_lnkup_application_status', true ), 'late payment does not rebook a rejected vendor (B-7)' );
lnkup_t_same( 'paid_after_close', get_post_meta( $pay2->ID, '_lnkup_needs_review', true ), 'late payment is flagged for admin review' );

// Capacity: last spot.
list( $ev_c, $opp_c ) = lnkup_b_event( $organizer, array( '_lnkup_spots_total' => 1, '_lnkup_spots_remaining' => 1, '_lnkup_vendor_fee' => '0' ) );
$c1 = lnkup_b_apply( lnkup_b_vendor(), $opp_c );
$c2 = lnkup_b_apply( lnkup_b_vendor(), $opp_c );
wp_set_current_user( $organizer );
LNKup_Applications::transition( $c1, 'approved' );
$second = LNKup_Applications::transition( $c2, 'approved' );
lnkup_t_same( 'opportunity_full', is_wp_error( $second ) ? $second->get_error_code() : 'approved', 'the last spot cannot be approved twice' );
lnkup_t_same( 'submitted', get_post_meta( $c2, '_lnkup_application_status', true ), 'the losing application is untouched' );
foreach ( array( $c1, $c2 ) as $cid ) {
	$cp = LNKup_Payments::get_for_application( $cid );
	if ( $cp ) {
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $cp->ID );
	}
}

// ---------------------------------------------------------------- Refund closes the booking (B-8, B-9, B-10).
echo "\nRefunds and credits\n";
list( $ev_r, $opp_r ) = lnkup_b_event( $organizer );
$v_r   = lnkup_b_vendor();
$app_r = lnkup_b_apply( $v_r, $opp_r );
wp_set_current_user( $organizer );
LNKup_Applications::transition( $app_r, 'approved' );
$pay_r = LNKup_Payments::get_for_application( $app_r );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $pay_r->ID );
LNKup_Payments::mark_paid( $pay_r->ID, 'pi_baos_paid' );
lnkup_t_same( 'paid', get_post_meta( $app_r, '_lnkup_application_status', true ), 'paid payment books the vendor' );
lnkup_t_assert( LNKup_Packets::is_pass_unlocked_for_application( $app_r ), 'pass unlocks after payment' );
update_post_meta( $pay_r->ID, '_lnkup_refund_amount', (float) get_post_meta( $pay_r->ID, '_lnkup_total_amount', true ) );
LNKup_Refunds::sync_payment_refund_status( $pay_r->ID );
lnkup_t_same( 'cancelled', get_post_meta( $app_r, '_lnkup_application_status', true ), 'full refund cancels the booking (B-8)' );
lnkup_t_assert( ! LNKup_Packets::is_pass_unlocked_for_application( $app_r ), 'full refund locks the event pass' );
lnkup_t_same( 'refunded', LNKup_Invoices::get_by_application( $app_r )['status'] ?? '', 'invoice shows refunded' );
lnkup_t_assert( '' !== (string) get_post_meta( $pay_r->ID, '_lnkup_paid_at', true ), 'original payment history is kept' );

$pc = lnkup_t_payment( array( 'vendor_user_id' => $v_r, 'organizer_user_id' => $organizer, 'total_amount' => '100', 'payment_status' => 'pending', 'credit_applied' => '30' ) );
$before = LNKup_Credits::available_total( $v_r );
LNKup_Credits::restore_for_payment( $pc, 'payment_voided' );
LNKup_Credits::restore_for_payment( $pc, 'payment_voided' );
lnkup_t_same( round( $before + 30, 2 ), LNKup_Credits::available_total( $v_r ), 'voided payment returns applied credit exactly once (B-10)' );
lnkup_t_same( 70.0, LNKup_Refunds::card_collected( $pc ), 'card refund capacity excludes wallet credit (B-9)' );
$wpdb->delete( LNKup_Financial_Tables::credits_table(), array( 'user_id' => $v_r ) );

// ---------------------------------------------------------------- Read-only summary (B-3).
echo "\nCheckout summary (B-3)\n";
list( $ev_s, $opp_s ) = lnkup_b_event( $organizer );
$app_s = lnkup_b_apply( lnkup_b_vendor(), $opp_s );
wp_set_current_user( $organizer );
LNKup_Applications::transition( $app_s, 'approved' );
$pay_s = LNKup_Payments::get_for_application( $app_s );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $pay_s->ID );
update_post_meta( $pay_s->ID, '_lnkup_processing_fee', 0 );
update_post_meta( $pay_s->ID, '_lnkup_stripe_payment_intent', 'pi_baos_summary' );
$fp_before = lnkup_t_financial_fingerprint( $organizer, array( (int) $pay_s->ID ) );
$summary   = LNKup_Checkout::get_summary( (int) $pay_s->ID );
lnkup_t_same( $fp_before, lnkup_t_financial_fingerprint( $organizer, array( (int) $pay_s->ID ) ), 'GET summary writes nothing, even with stale fees and an intent' );
lnkup_t_assert( (float) $summary['breakdown']['processing_fee'] > 0, 'summary shows the fee prepare() will charge' );

// ---------------------------------------------------------------- Fee rule migration (B-12).
echo "\nFee rule migration (B-12)\n";
$rules    = LNKup_Financial_Tables::fee_rules_table();
$settings = get_option( 'lnkup_settings', array() );
$orig_pct = $settings['organizer_commission_percent'] ?? null;
$wpdb->insert( $rules, array( 'name' => 'BAOS global', 'scope_type' => 'global', 'scope_id' => '', 'fee_type' => 'percent', 'fee_value' => 8, 'priority' => 1, 'status' => 'active', 'created_at' => current_time( 'mysql' ) ) );
$rule_id  = (int) $wpdb->insert_id;
$pre      = LNKup_Fee_Engine::resolve_organizer_commission( 100 );
$migrated = LNKup_Fee_Engine::migrate_global_commission_rule();
$post_mig = LNKup_Fee_Engine::resolve_organizer_commission( 100 );
lnkup_t_assert( $migrated['migrated'], 'global percent rule is retired' );
lnkup_t_same( $pre, $post_mig, 'effective commission is unchanged by the migration' );
lnkup_t_same( 'inactive', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$rules} WHERE id = %d", $rule_id ) ), 'rule row is kept but inactive' );
$wpdb->delete( $rules, array( 'id' => $rule_id ) );
$settings = get_option( 'lnkup_settings', array() );
if ( null === $orig_pct ) {
	unset( $settings['organizer_commission_percent'] );
} else {
	$settings['organizer_commission_percent'] = $orig_pct;
}
update_option( 'lnkup_settings', $settings );

// ---------------------------------------------------------------- Drafts.
echo "\nDrafts\n";
list( $ev_d, $opp_d ) = lnkup_b_event( $organizer );
$v_d = lnkup_b_vendor();
wp_set_current_user( $v_d );
LNKup_Application_Questions::save_for_opportunity(
	$opp_d,
	array(
		array( 'id' => 'power', 'label' => 'Do you need power?', 'type' => 'select', 'options' => array( 'Yes', 'No' ), 'required' => true ),
		array( 'id' => 'menu', 'label' => 'Menu', 'type' => 'textarea' ),
	),
	$admin
);
$draft = LNKup_Application_Drafts::upsert( $v_d, $opp_d, array( 'step' => 'business', 'fields' => array( 'selected_category' => 'General' ), 'answers' => array( 'menu' => 'Tacos "al pastor" & more' ) ) );
lnkup_t_assert( is_array( $draft ) && $draft['id'] > 0, 'draft is created' );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $draft['id'] );
lnkup_t_same( 'Tacos "al pastor" & more', $draft['answers']['menu'] ?? null, 'answers with quotes survive the round trip' );
lnkup_t_same( 0, count( lnkup_get_applications_for_user( $v_d, 'vendor' ) ), 'draft is not a submitted application' );
lnkup_t_same( array(), get_posts( array( 'post_type' => 'lnkup_application', 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => '_lnkup_opportunity_id', 'meta_value' => $opp_d ) ), 'draft is invisible to post_status=any queries' );
lnkup_t_same( 0, LNKup_Opportunity_Inventory::holds( $opp_d ), 'draft holds no inventory' );
$stale = LNKup_Application_Drafts::upsert( $v_d, $opp_d, array( 'step' => 'questions' ), $draft['revision'] - 1 );
lnkup_t_same( 'lnkup_draft_conflict', is_wp_error( $stale ) ? $stale->get_error_code() : 'none', 'stale revision from another device gets a conflict' );
$draft = LNKup_Application_Drafts::upsert( $v_d, $opp_d, array( 'step' => 'review', 'fields' => array( 'selected_category' => 'General' ), 'answers' => array( 'menu' => 'Tacos' ) ), $draft['revision'] );
lnkup_t_same( 1, count( LNKup_Application_Drafts::list_for_vendor( $v_d ) ), 'resume list shows the open draft' );
$missing = LNKup_Application_Drafts::finalize( $draft['id'], $v_d, array( 'accepted_terms' => true ) );
lnkup_t_same( 'lnkup_answers_required', is_wp_error( $missing ) ? $missing->get_error_code() : 'none', 'required organizer question blocks submit' );
LNKup_Application_Drafts::upsert( $v_d, $opp_d, array( 'step' => 'review', 'fields' => array( 'selected_category' => 'General' ), 'answers' => array( 'power' => 'Yes', 'menu' => 'Tacos' ) ) );
update_post_meta( $opp_d, '_lnkup_vendor_fee', '175' );
$changed = LNKup_Application_Drafts::finalize( $draft['id'], $v_d, array( 'accepted_terms' => true ) );
lnkup_t_same( 'lnkup_price_changed', is_wp_error( $changed ) ? $changed->get_error_code() : 'none', 'price change is shown before commitment' );
$done = LNKup_Application_Drafts::finalize( $draft['id'], $v_d, array( 'accepted_terms' => true, 'accept_current_price' => true ) );
lnkup_t_assert( is_array( $done ) && $done['application_id'] > 0, 'draft submits after accepting the new price' );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) ( $done['application_id'] ?? 0 ) );
$again = LNKup_Application_Drafts::finalize( $draft['id'], $v_d, array( 'accepted_terms' => true ) );
lnkup_t_same( $done['application_id'] ?? -1, $again['application_id'] ?? -2, 'replayed submit returns the same application' );
lnkup_t_same( 1, count( lnkup_get_applications_for_user( $v_d, 'vendor' ) ), 'exactly one application exists' );
$answers = LNKup_Application_Questions::answers_for_application( (int) $done['application_id'] );
lnkup_t_same( array( 'power', 'menu' ), wp_list_pluck( $answers, 'id' ), 'answers are frozen on the application with labels' );
lnkup_t_same( 0, count( LNKup_Application_Drafts::list_for_vendor( $v_d ) ), 'submitted draft leaves the resume list' );
lnkup_t_same( 'lnkup_forbidden', is_wp_error( $x = LNKup_Application_Drafts::upsert( $organizer, $opp_d, array() ) ) ? $x->get_error_code() : 'none', 'organizers cannot create vendor drafts' );
$other = lnkup_b_vendor();
lnkup_t_same( 'lnkup_not_found', is_wp_error( $y = LNKup_Application_Drafts::finalize( $draft['id'], $other ) ) ? $y->get_error_code() : 'none', 'another vendor cannot submit someone else\'s draft' );

// ---------------------------------------------------------------- Multi-stop tour.
echo "\nMulti-stop tour\n";
$tour = wp_insert_post( array( 'post_type' => 'lnkup_tour', 'post_status' => 'publish', 'post_title' => 'BAOS Tour' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $tour );
update_post_meta( $tour, '_lnkup_organizer_user_id', $organizer );
update_post_meta( $tour, '_lnkup_tour_type', 'tour' );
$stops = array();
foreach ( array( 'Houston', 'Atlanta', 'Charlotte', 'Nashville' ) as $i => $city ) {
	$meta = 'Charlotte' === $city ? array( '_lnkup_opportunity_status' => 'closed' ) : array();
	list( $e, $o ) = lnkup_b_event( $organizer, $meta, array( '_lnkup_city' => $city, '_lnkup_tour_id' => $tour, '_lnkup_tour_stop_order' => $i + 1 ) );
	$stops[ $city ] = array( 'event_id' => $e, 'opportunity_id' => $o );
}
list( $other_event, $other_opp ) = lnkup_b_event( $organizer );
$v_t   = lnkup_b_vendor();
$group = 'grp' . strtolower( wp_generate_password( 10, false ) );
$body  = array(
	'group_id'       => $group,
	'accepted_terms' => true,
	'selections'     => array_merge( array_values( $stops ), array( array( 'event_id' => $other_event, 'opportunity_id' => $other_opp ) ) ),
	'shared'         => array( 'application_message' => 'Touring with you' ),
);
wp_set_current_user( $v_t );
$res = LNKup_Application_Groups::submit( $tour, $v_t, $body );
$by  = array();
foreach ( $res['results'] as $row ) {
	$by[ $row['opportunity_id'] ] = $row['result'];
	if ( ! empty( $row['application_id'] ) ) {
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $row['application_id'] );
	}
}
lnkup_t_same( 'submitted', $by[ $stops['Houston']['opportunity_id'] ] ?? '', 'Houston submitted' );
lnkup_t_same( 'submitted', $by[ $stops['Atlanta']['opportunity_id'] ] ?? '', 'Atlanta submitted' );
lnkup_t_same( 'closed', $by[ $stops['Charlotte']['opportunity_id'] ] ?? '', 'closed stop reports closed, not success' );
lnkup_t_same( 'ineligible', $by[ $other_opp ] ?? '', 'a slot from another event cannot ride along' );
lnkup_t_same( 3, (int) $res['summary']['submitted'], 'three independent applications created' );
$res2 = LNKup_Application_Groups::submit( $tour, $v_t, $body );
lnkup_t_same( 0, (int) $res2['summary']['submitted'], 'retry creates no duplicates' );
lnkup_t_same( 3, (int) $res2['summary']['already'], 'retry reports already-submitted stops' );
$apps = lnkup_get_applications_for_user( $v_t, 'vendor' );
lnkup_t_same( 3, count( $apps ), 'vendor has exactly three applications' );
lnkup_t_same( array( $group ), array_values( array_unique( array_map( static function ( $a ) {
	return (string) get_post_meta( $a->ID, '_lnkup_submission_group_id', true );
}, $apps ) ) ), 'every stop application carries the group id' );
wp_set_current_user( $organizer );
$h_app = LNKup_Applications::find_active_application( $stops['Houston']['opportunity_id'], $v_t );
$a_app = LNKup_Applications::find_active_application( $stops['Atlanta']['opportunity_id'], $v_t );
LNKup_Applications::transition( $h_app, 'approved' );
LNKup_Applications::transition( $a_app, 'rejected' );
lnkup_t_same( 'payment_required', get_post_meta( $h_app, '_lnkup_application_status', true ), 'Houston approved independently' );
lnkup_t_same( 'rejected', get_post_meta( $a_app, '_lnkup_application_status', true ), 'Atlanta rejected independently' );
$hp = LNKup_Payments::get_for_application( $h_app );
if ( $hp ) {
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $hp->ID );
}
lnkup_t_same( null, LNKup_Payments::get_for_application( $a_app ), 'no payment exists for the rejected stop' );

// ---------------------------------------------------------------- Metabox (B-18).
echo "\nwp-admin metabox (B-18)\n";
wp_set_current_user( $admin );
$_POST = array(
	'lnkup_meta_nonce'          => wp_create_nonce( 'lnkup_save_meta' ),
	'_lnkup_application_status' => 'paid',
	'_lnkup_vendor_user_id'     => (string) $admin,
);
$target = LNKup_Applications::find_active_application( $stops['Nashville']['opportunity_id'], $v_t );
LNKup_Meta::save_meta_boxes( $target, get_post( $target ) );
$_POST = array();
lnkup_t_same( 'submitted', get_post_meta( $target, '_lnkup_application_status', true ), 'metabox cannot jump submitted → paid' );
lnkup_t_same( $v_t, (int) get_post_meta( $target, '_lnkup_vendor_user_id', true ), 'metabox cannot rewrite ownership' );

wp_set_current_user( 0 );
lnkup_t_finish();
