<?php
/**
 * Money lists: newest paid first, pagination headers, per-booking rows, ledger references,
 * and no per-row Stripe calls.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/financial-lists-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Money lists\n";

$organizer = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );
$base      = array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'total_amount' => '230.00', 'event_id' => 999001 );

// Invoice created long ago but paid today must lead the list.
$old_paid_now = lnkup_t_payment( array_merge( $base, array( 'application_id' => 990001, 'payment_status' => 'paid', 'paid_at' => current_time( 'mysql' ) ) ) );
wp_update_post( array( 'ID' => $old_paid_now, 'post_date' => '2026-09-01 10:00:00', 'post_date_gmt' => '2026-09-01 10:00:00' ) );
$recent_unpaid = array();
for ( $i = 0; $i < 3; $i++ ) {
	$recent_unpaid[] = lnkup_t_payment( array_merge( $base, array( 'application_id' => 990010 + $i, 'payment_status' => 'pending' ) ) );
}
$second_booking = lnkup_t_payment( array_merge( $base, array( 'application_id' => 990002, 'payment_status' => 'paid', 'paid_at' => '2026-10-02 12:00:00' ) ) );

foreach ( array_merge( array( $old_paid_now, $second_booking ), $recent_unpaid ) as $pid ) {
	LNKup_Transactions::record_from_payment_post( $pid, 'payment_created' );
}
$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Financial_Tables::transactions_table() . " SET created_at = '2026-09-01 10:00:00' WHERE payment_post_id = %d", $old_paid_now ) );
foreach ( $recent_unpaid as $pid ) {
	// Unpaid invoices issued after the old invoice but before it was paid.
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Financial_Tables::transactions_table() . " SET created_at = '2026-11-01 09:00:00' WHERE payment_post_id = %d", $pid ) );
}

wp_set_current_user( $organizer );
$req = new WP_REST_Request( 'GET', '/lnkup/v1/financial/transactions' );
$req->set_param( 'role', 'organizer' );
$req->set_param( 'limit', 2 );
$res  = rest_do_request( $req );
$rows = $res->get_data();
lnkup_t_same( $old_paid_now, $rows[0]['payment_post_id'] ?? null, 'invoice paid today leads the list even though it was created first' );
lnkup_t_same( '5', $res->get_headers()['X-WP-Total'] ?? null, 'X-WP-Total counts all transactions' );
lnkup_t_same( '3', $res->get_headers()['X-WP-TotalPages'] ?? null, 'X-WP-TotalPages reflects page size' );
lnkup_t_same( 23000, $rows[0]['amount_cents'] ?? null, 'transaction exposes amount in cents' );
lnkup_t_assert( '' !== (string) ( $rows[0]['paid_at'] ?? '' ), 'transaction exposes paid_at' );
lnkup_t_same( '', $rows[0]['audit']['stripe_intent_status'] ?? 'missing', 'list rows do not call Stripe for intent status' );

$req->set_param( 'page', 3 );
$page3 = rest_do_request( $req )->get_data();
lnkup_t_same( 1, count( $page3 ), 'last page holds the remainder' );

// Two bookings at the same event stay two rows.
$display = lnkup_payment_display_rows( array( get_post( $old_paid_now ), get_post( $second_booking ) ) );
lnkup_t_same( 2, count( $display ), 'separate bookings at one event are not merged' );
$sorted = lnkup_sort_payment_rows_by_activity( $display );
lnkup_t_same( $old_paid_now, $sorted[0]['payment']->ID, 'display rows sort by paid date' );

// Ledger references and pagination.
for ( $i = 0; $i < 3; $i++ ) {
	LNKup_Organizer_Wallet::add_entry( $organizer, 10 + $i, 'available', array( 'source_type' => 'payment', 'payment_post_id' => $second_booking, 'idempotency_key' => 'test:list:' . $organizer . ':' . $i ) );
}
$req = new WP_REST_Request( 'GET', '/lnkup/v1/financial/organizer-wallet/entries' );
$req->set_param( 'per_page', 2 );
$res     = rest_do_request( $req );
$entries = $res->get_data();
lnkup_t_same( 2, count( $entries ), 'ledger page size honored' );
lnkup_t_same( '3', $res->get_headers()['X-WP-Total'] ?? null, 'ledger X-WP-Total' );
lnkup_t_same( $second_booking, $entries[0]['reference']['payment_id'] ?? null, 'ledger row references its payment' );
lnkup_t_same( '2026-10-02 12:00:00', $entries[0]['reference']['paid_at'] ?? null, 'ledger row carries the payment paid date' );
lnkup_t_assert( ! array_key_exists( 'idempotency_key', $entries[0] ), 'idempotency key is not exposed to clients' );
wp_set_current_user( 0 );

$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Financial_Tables::transactions_table() . ' WHERE organizer_user_id = %d', $organizer ) );
lnkup_t_finish();
