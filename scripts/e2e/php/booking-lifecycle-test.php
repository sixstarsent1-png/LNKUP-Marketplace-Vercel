<?php
/**
 * One booking end to end through the real services: apply, approve, pay (test-mode finalize),
 * invoice and receipt agree with the payment, the pass unlocks, staff check the vendor in at the
 * assigned event only, a replayed payment changes nothing, and a full refund locks the pass,
 * cancels the booking and frees the slot exactly once.
 *
 * No Stripe calls: payment is finalized with a simulated intent; the refund is recorded the way
 * the charge.refunded webhook records it.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/booking-lifecycle-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Booking lifecycle\n";

/**
 * Published post removed at the end of the run.
 *
 * @param string $type Post type.
 * @param array  $meta Meta.
 */
function lnkup_bl_post( string $type, array $meta = array() ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Lifecycle ' . $type, 'meta_input' => $meta ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

$organizer = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );
$event     = lnkup_bl_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_city' => 'Charlotte', '_lnkup_state' => 'NC', '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+20 days' ) ) ) );
$other     = lnkup_bl_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_event_date' => gmdate( 'Y-m-d', strtotime( '+21 days' ) ) ) );
$opp       = lnkup_bl_post(
	'lnkup_opportunity',
	array(
		'_lnkup_event_id'             => $event,
		'_lnkup_organizer_user_id'    => $organizer,
		'_lnkup_opportunity_status'   => 'open',
		'_lnkup_vendor_fee'           => '100',
		'_lnkup_spots_total'          => 1,
		'_lnkup_required_documents'   => 'none',
		'_lnkup_application_deadline' => gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
	)
);
LNKup_Opportunity_Inventory::recompute( $opp );
LNKup_Marketplace_Index::sync_opportunity( $opp );
lnkup_bl_post( 'lnkup_vendor_profile', array( '_lnkup_owner_user_id' => $vendor, '_lnkup_business_name' => 'Lifecycle Vendor ' . $vendor ) );

echo "Apply and approve\n";
wp_set_current_user( $vendor );
$app = LNKup_Applications::submit_with_profile( $vendor, $opp, array( 'accepted_terms' => true, 'selected_category' => 'General' ) );
lnkup_t_assert( ! is_wp_error( $app ), 'vendor applies' );
$app                             = (int) $app;
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $app );
wp_set_current_user( $organizer );
$approved = LNKup_Applications::handle_organizer_action( $app, 'approve', $organizer );
lnkup_t_assert( ! is_wp_error( $approved ), 'organizer approves' );
$status = (string) get_post_meta( $app, '_lnkup_application_status', true );
lnkup_t_assert( in_array( $status, array( 'approved', 'payment_required' ), true ), "approved booking waits on payment (status {$status})" );
$payment = LNKup_Payments::get_for_application( $app );
lnkup_t_assert( (bool) $payment, 'approval creates a payment record' );
$pid = $payment ? (int) $payment->ID : 0;
lnkup_t_assert( 'paid' !== (string) get_post_meta( $pid, '_lnkup_payment_status', true ), 'approved is not paid' );
lnkup_t_same( null, LNKup_Receipts::get_by_payment( $pid ), 'no receipt before payment' );
lnkup_t_same( 0, ( LNKup_Opportunity_Inventory::capacity( $opp ) - LNKup_Opportunity_Inventory::holds( $opp ) ), 'the approved booking holds the only slot' );

echo "Pay\n";
$total = (float) get_post_meta( $pid, '_lnkup_total_amount', true );
lnkup_t_assert( $total > 0, 'payment has a server-computed total' );
$paid = LNKup_Financial_Service::finalize_payment( $pid, 'sim_lifecycle_' . $pid, array( 'source' => 'test' ) );
lnkup_t_assert( ! is_wp_error( $paid ) && ! empty( $paid['success'] ), 'test-mode finalize confirms the payment' );
lnkup_t_same( 'paid', (string) get_post_meta( $app, '_lnkup_application_status', true ), 'confirmed payment marks the booking paid' );
$replay = LNKup_Financial_Service::finalize_payment( $pid, 'sim_lifecycle_' . $pid, array( 'source' => 'test_replay' ) );
lnkup_t_assert( ! empty( $replay['already_paid'] ), 'a replayed confirmation reports already paid' );
$settlements = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Treasury_Tables::organizer_wallet_entries_table() . " WHERE payment_post_id = %d AND source_type = 'payment'", $pid ) );
lnkup_t_same( 1, $settlements, 'the payment settles into the organizer ledger once' );

$receipt = LNKup_Receipts::get_by_payment( $pid );
lnkup_t_assert( (bool) $receipt, 'paying issues a receipt' );
lnkup_t_same( (int) round( $total * 100 ), (int) round( (float) ( $receipt['amount'] ?? 0 ) * 100 ), 'receipt amount equals the payment total (cents)' );
$invoice = LNKup_Invoices::get_by_application( $app );
lnkup_t_assert( (bool) $invoice, 'the booking has an invoice' );
lnkup_t_same( (int) round( $total * 100 ), (int) round( (float) ( $invoice['total_amount'] ?? 0 ) * 100 ), 'invoice total equals the payment total (cents)' );
lnkup_t_same( 'paid', (string) ( $invoice['status'] ?? '' ), 'invoice is marked paid' );
$org_invoices = LNKup_Invoices::list_for_user( $organizer, 'organizer', array( 'limit' => 5 ) );
lnkup_t_same( (int) ( $invoice['id'] ?? 0 ), (int) ( $org_invoices[0]['id'] ?? -1 ), 'the newest paid invoice is first in the organizer view' );

echo "Pass and check-in\n";
$packets = get_posts( array( 'post_type' => 'lnkup_packet', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_lnkup_application_id', 'meta_value' => $app ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
lnkup_t_assert( (bool) $packets, 'paid booking has a pass' );
$packet = $packets ? (int) $packets[0] : 0;
foreach ( $packets as $p ) {
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $p );
}
lnkup_t_same( true, LNKup_Packets::is_unlocked( $packet ), 'the pass unlocks on payment' );
$wrong = LNKup_Checkins::record( $app, 'checked_in', 'manual', $other );
lnkup_t_assert( empty( $wrong['success'] ), 'check-in at a different event is refused' );
$in = LNKup_Checkins::record( $app, 'checked_in', 'manual', $event );
lnkup_t_assert( ! empty( $in['success'] ) && ! empty( $in['checked_in'] ), 'staff check the vendor in at the assigned event' );

echo "Full refund\n";
update_post_meta( $pid, '_lnkup_refund_amount', $total );
LNKup_Refunds::sync_payment_refund_status( $pid );
lnkup_t_same( 'refunded', (string) get_post_meta( $pid, '_lnkup_payment_status', true ), 'payment reads refunded' );
lnkup_t_same( 'cancelled', (string) get_post_meta( $app, '_lnkup_application_status', true ), 'the booking is cancelled after the money is returned' );
lnkup_t_same( false, LNKup_Packets::is_unlocked( $packet ), 'the pass is locked' );
lnkup_t_same( 1, ( LNKup_Opportunity_Inventory::capacity( $opp ) - LNKup_Opportunity_Inventory::holds( $opp ) ), 'the slot is free again' );
$debits = static function () use ( $wpdb, $pid ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Treasury_Tables::organizer_wallet_entries_table() . " WHERE payment_post_id = %d AND entry_type = 'debit' AND source_type LIKE %s", $pid, 'refund%' ) );
};
$before = $debits();
lnkup_t_assert( $before >= 1, 'the refund reverses the organizer share in the ledger' );
LNKup_Refunds::sync_payment_refund_status( $pid );
lnkup_t_same( $before, $debits(), 'a replayed refund webhook adds no ledger rows' );
lnkup_t_same( 'refunded', (string) ( LNKup_Invoices::get_by_application( $app )['status'] ?? '' ), 'the invoice reads refunded' );

wp_set_current_user( 0 );
lnkup_t_finish();
