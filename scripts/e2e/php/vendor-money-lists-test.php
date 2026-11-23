<?php
/**
 * Vendor money contract counts every payment state once, and invoice / receipt lists page
 * through all rows with the most recently paid first.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/vendor-money-lists-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Vendor money contract and lists\n";

$vendor    = lnkup_t_user( 'lnkup_vendor' );
$organizer = lnkup_t_user( 'lnkup_organizer' );
$base      = array( 'vendor_user_id' => $vendor, 'organizer_user_id' => $organizer );

echo "Contract buckets\n";
lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'paid', 'total_amount' => '100.00' ) ) );
lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'disputed', 'total_amount' => '40.00' ) ) );
lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'pending', 'total_amount' => '25.00' ) ) );
lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'processing', 'total_amount' => '60.00' ) ) );
lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'partially_paid', 'total_amount' => '200.00', 'amount_paid_cents' => 5000 ) ) );
lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'partially_refunded', 'total_amount' => '80.00', 'refund_amount' => '30.00' ) ) );
lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'refunded', 'total_amount' => '70.00', 'refund_amount' => '70.00' ) ) );

$money   = LNKup_Financial_Service::vendor_money_contract( $vendor );
$metrics = $money['metrics'];
lnkup_t_same( 10000 + 4000 + 5000 + 5000, $metrics['paid']['cents'], 'paid = paid + disputed + deposit paid + kept part of a partial refund' );
lnkup_t_same( 4, $metrics['paid']['count'], 'four payments contribute to paid' );
lnkup_t_same( 2500 + 15000, $metrics['outstanding']['cents'], 'outstanding = pending + unpaid deposit balance' );
lnkup_t_same( 6000, $metrics['processing']['cents'], 'processing ACH counted' );
lnkup_t_same( 3000 + 7000, $metrics['refunded']['cents'], 'refunded = partial refund + full refund' );
lnkup_t_same( 10000 + 4000 + 5000 + 8000 + 7000, $metrics['paid']['cents'] + $metrics['refunded']['cents'], 'paid + refunded equals everything the vendor actually paid' );
foreach ( $metrics as $key => $metric ) {
	lnkup_t_assert( is_int( $metric['cents'] ), "{$key} is integer cents" );
}
$empty = LNKup_Financial_Service::vendor_money_contract( lnkup_t_user( 'lnkup_vendor' ) );
lnkup_t_same( 0, $empty['metrics']['paid']['cents'], 'a new vendor has a confirmed $0, not a missing value' );
lnkup_t_same( 'ok', $empty['metrics']['paid']['status'], 'a confirmed $0 is reported as ok' );

echo "Invoice ordering and paging\n";
$invoices = LNKup_Financial_Tables::invoices_table();
$ids      = array();
for ( $i = 0; $i < 30; $i++ ) {
	$at = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * ( 60 - $i ) );
	$wpdb->insert( $invoices, array( 'invoice_number' => 'INV-PG' . wp_generate_password( 6, false ), 'vendor_user_id' => $vendor, 'organizer_user_id' => $organizer, 'status' => 'sent', 'total_amount' => 10, 'subtotal' => 10, 'created_at' => $at, 'updated_at' => $at ) );
	$ids[] = (int) $wpdb->insert_id;
}
$oldest = $ids[0];
LNKup_Invoices::mark_paid( $oldest );
$first = LNKup_Invoices::list_for_user( $organizer, 'organizer', array( 'per_page' => 10, 'page' => 1 ) );
lnkup_t_same( $oldest, (int) ( $first[0]['id'] ?? 0 ), 'an old invoice paid today is first, not buried on page three' );
$all = array();
for ( $page = 1; $page <= 4; $page++ ) {
	$rows = LNKup_Invoices::list_for_user( $organizer, 'organizer', array( 'per_page' => 10, 'page' => $page ) );
	$all  = array_merge( $all, wp_list_pluck( $rows, 'id' ) );
}
lnkup_t_same( 30, count( array_unique( array_map( 'intval', $all ) ) ), 'three pages return all 30 invoices once each' );
lnkup_t_same( 25, count( LNKup_Invoices::list_for_user( $organizer, 'organizer' ) ), 'older clients still get 25 by default' );

wp_set_current_user( $organizer );
$req = new WP_REST_Request( 'GET', '/lnkup/v1/financial/invoices' );
$req->set_param( 'role', 'organizer' );
$req->set_param( 'per_page', 10 );
$req->set_param( 'page', 3 );
$res = rest_do_request( $req );
lnkup_t_same( 200, $res->get_status(), 'paged invoices route answers 200' );
lnkup_t_same( '30', (string) ( $res->get_headers()['X-WP-Total'] ?? '' ), 'X-WP-Total reports every invoice' );
lnkup_t_same( '3', (string) ( $res->get_headers()['X-WP-TotalPages'] ?? '' ), 'X-WP-TotalPages reports the page count' );
lnkup_t_same( 10, count( (array) $res->get_data() ), 'page three is full' );

echo "Receipt paging\n";
$receipts = LNKup_Financial_Tables::receipts_table();
for ( $i = 0; $i < 27; $i++ ) {
	$wpdb->insert( $receipts, array( 'receipt_number' => 'RCT-PG' . wp_generate_password( 6, false ), 'vendor_user_id' => $vendor, 'organizer_user_id' => $organizer, 'amount' => 10, 'payment_method' => 'card', 'status' => 'paid', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS * $i ) ) );
}
lnkup_t_same( 27, LNKup_Receipts::count_for_user( $vendor ), 'receipts are counted' );
lnkup_t_same( 2, count( LNKup_Receipts::list_for_user( $vendor, 25, 2 ) ), 'the second page holds the receipts past 25' );
wp_set_current_user( $vendor );
$req = new WP_REST_Request( 'GET', '/lnkup/v1/financial/receipts' );
$req->set_param( 'per_page', 100 );
$res = rest_do_request( $req );
lnkup_t_same( 27, count( (array) $res->get_data() ), 'a 100-row page returns every receipt' );
lnkup_t_same( '27', (string) ( $res->get_headers()['X-WP-Total'] ?? '' ), 'receipts route reports X-WP-Total' );

$wpdb->query( $wpdb->prepare( "DELETE FROM {$invoices} WHERE organizer_user_id = %d", $organizer ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$receipts} WHERE vendor_user_id = %d", $vendor ) );
wp_set_current_user( 0 );
lnkup_t_finish();
