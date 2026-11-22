<?php
/**
 * Refunds and chargebacks reverse the organizer's share exactly once: card and credit refunds,
 * cumulative partial refunds, lost/won chargebacks, admin-only chargeback resolution, and
 * reserves that are used rather than paid out after a refund.
 *
 * No Stripe calls: refunds are recorded through the same meta + sync path the webhook uses.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/financial-refund-dispute-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Refunds and chargebacks\n";

$entries   = LNKup_Treasury_Tables::organizer_wallet_entries_table();
$organizer = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );
$admin     = lnkup_t_user( 'administrator' );
$base      = array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'total_amount' => '200.00', 'payment_status' => 'paid', 'paid_at' => current_time( 'mysql' ) );

/**
 * Sum of refund/chargeback debits for a payment, in cents.
 *
 * @param int $pid Payment.
 */
function lnkup_t_reversed( $pid ) {
	global $wpdb;
	return (int) round( (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . LNKup_Treasury_Tables::organizer_wallet_entries_table() . " WHERE payment_post_id = %d AND entry_type = 'debit' AND (source_type LIKE 'refund%%' OR source_type LIKE 'chargeback%%')", $pid ) ) * 100 );
}

// 1. Platform-settled payment: organizer credited $180; vendor refunded in two halves.
$p1 = lnkup_t_payment( $base );
LNKup_Organizer_Wallet::add_entry( $organizer, 180, 'available', array( 'source_type' => 'payment', 'payment_post_id' => $p1, 'idempotency_key' => LNKup_Organizer_Wallet::payment_key( $p1, 'credit' ) ) );
update_post_meta( $p1, '_lnkup_refund_amount', 100 );
LNKup_Refunds::sync_payment_refund_status( $p1 );
lnkup_t_same( 'partially_refunded', get_post_meta( $p1, '_lnkup_payment_status', true ), 'half refund → partially_refunded' );
lnkup_t_same( 9000, lnkup_t_reversed( $p1 ), 'half refund reverses half the organizer share' );
LNKup_Refunds::sync_payment_refund_status( $p1 );
lnkup_t_same( 9000, lnkup_t_reversed( $p1 ), 'replayed refund webhook does not reverse twice' );
update_post_meta( $p1, '_lnkup_refund_amount', 200 );
LNKup_Refunds::sync_payment_refund_status( $p1 );
lnkup_t_same( 'refunded', get_post_meta( $p1, '_lnkup_payment_status', true ), 'full refund → refunded' );
lnkup_t_same( 18000, lnkup_t_reversed( $p1 ), 'full refund reverses the whole share (cumulative, not double)' );
lnkup_t_same( 0.0, (float) LNKup_Organizer_Wallet::get_row( $organizer )['available_balance'], 'organizer can no longer cash out refunded money' );

// 2. Credit refund also reverses the organizer share.
$p2 = lnkup_t_payment( $base );
LNKup_Organizer_Wallet::add_entry( $organizer, 180, 'available', array( 'source_type' => 'payment', 'payment_post_id' => $p2, 'idempotency_key' => LNKup_Organizer_Wallet::payment_key( $p2, 'credit' ) ) );
update_post_meta( $p2, '_lnkup_credit_refund_amount', 50 );
LNKup_Refunds::sync_payment_refund_status( $p2 );
lnkup_t_same( 4500, lnkup_t_reversed( $p2 ), 'credit refund of 25% reverses 25% of the share' );

// 3. Destination charge: refund mirrors the debit; credit refunds need recovery from Connect.
$p3 = lnkup_t_payment( array_merge( $base, array( 'stripe_destination_account' => 'acct_test_dest' ) ) );
LNKup_Organizer_Wallet::record_connect_settlement( $organizer, $p3, array( 'organizer_net' => 180 ) );
update_post_meta( $p3, '_lnkup_credit_refund_amount', 200 );
LNKup_Refunds::sync_payment_refund_status( $p3 );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$entries} WHERE payment_post_id = %d AND source_type = 'refund_destination'", $p3 ), ARRAY_A );
lnkup_t_same( '180.00', $row['amount'] ?? null, 'destination refund debit equals the organizer share' );
lnkup_t_assert( false !== strpos( (string) ( $row['metadata'] ?? '' ), '"needs_recovery":true' ), 'credit refund on destination charge is flagged for recovery' );
lnkup_t_same( 0.0, LNKup_Organizer_Wallet::platform_claim( $organizer ), 'destination refund never touches the platform claim' );

// 4. Recovered platform claim: refund reduces what Cash Out may transfer.
$organizer2 = lnkup_t_user( 'lnkup_organizer' );
$p4 = lnkup_t_payment( array_merge( $base, array( 'organizer_user_id' => $organizer2 ) ) );
$w2 = LNKup_Organizer_Wallet::get_or_create( $organizer2 );
LNKup_Organizer_Wallet::insert_entry( array( 'wallet_id' => (int) $w2['id'], 'user_id' => $organizer2, 'entry_type' => 'connect_settlement', 'amount' => 180, 'source_type' => 'payment', 'payment_post_id' => $p4, 'metadata' => wp_json_encode( array( 'settlement_mode' => 'platform_treasury', 'recovery_backfill' => true ) ) ) );
lnkup_t_same( 180.0, LNKup_Organizer_Wallet::platform_claim( $organizer2 ), 'recovered claim counts toward Cash Out' );
update_post_meta( $p4, '_lnkup_refund_amount', 200 );
LNKup_Refunds::sync_payment_refund_status( $p4 );
lnkup_t_same( 0.0, LNKup_Organizer_Wallet::platform_claim( $organizer2 ), 'refund removes the recovered claim' );

// 5. Lost chargeback reverses once; won reinstates.
$p5 = lnkup_t_payment( array_merge( $base, array( 'stripe_payment_intent' => 'pi_test_cb', 'payment_status' => 'disputed' ) ) );
LNKup_Organizer_Wallet::add_entry( $organizer2, 180, 'available', array( 'source_type' => 'payment', 'payment_post_id' => $p5, 'idempotency_key' => LNKup_Organizer_Wallet::payment_key( $p5, 'credit' ) ) );
$closed = array( 'id' => 'evt_test_' . wp_generate_password( 6, false ), 'type' => 'charge.dispute.closed', 'data' => array( 'object' => array( 'id' => 'dp_test', 'payment_intent' => 'pi_test_cb', 'status' => 'lost', 'amount' => 20000 ) ) );
LNKup_Stripe_Webhooks::process_event( $closed );
LNKup_Stripe_Webhooks::process_event( array_merge( $closed, array( 'id' => 'evt_test_' . wp_generate_password( 6, false ) ) ) );
lnkup_t_same( 'chargeback_lost', get_post_meta( $p5, '_lnkup_payment_status', true ), 'lost chargeback sets chargeback_lost' );
lnkup_t_same( 18000, lnkup_t_reversed( $p5 ), 'lost chargeback reverses the share once' );
$res = LNKup_Financial_Service::finalize_payment( $p5, 'pi_test_cb', array( 'source' => 'test' ) );
lnkup_t_assert( ! empty( $res['refused'] ), 'chargeback_lost payment cannot be marked paid again' );
$won = array( 'id' => 'evt_test_' . wp_generate_password( 6, false ), 'type' => 'charge.dispute.closed', 'data' => array( 'object' => array( 'id' => 'dp_test', 'payment_intent' => 'pi_test_cb', 'status' => 'won', 'amount' => 20000 ) ) );
LNKup_Stripe_Webhooks::process_event( $won );
lnkup_t_same( 'paid', get_post_meta( $p5, '_lnkup_payment_status', true ), 'won chargeback restores paid' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$entries} WHERE payment_post_id = %d AND source_type = 'chargeback_reinstated'", $p5 ) ), 'won chargeback reinstates the share once' );

// 6. Bank chargebacks are admin-only and never resolved with credit.
$p6 = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'disputed', 'organizer_user_id' => $organizer ) ) );
$dispute_id = LNKup_Financial_Disputes::open_from_chargeback( $p6, 'fraudulent' );
lnkup_t_assert( $dispute_id > 0, 'chargeback dispute row recorded' );
$res = LNKup_Financial_Disputes::resolve( $dispute_id, 'denied', 'Organizer says it was fine', $organizer );
lnkup_t_assert( is_wp_error( $res ) && 'chargeback_admin_only' === $res->get_error_code(), 'organizer cannot resolve a bank chargeback' );
$res = LNKup_Financial_Disputes::resolve( $dispute_id, 'approved_credit', '', $admin );
lnkup_t_assert( is_wp_error( $res ) && 'chargeback_no_credit' === $res->get_error_code(), 'chargeback cannot be resolved with credit, even by admin' );

// 7. Reserve on a refunded payment is used, not paid out.
$reserves = LNKup_Treasury_Tables::reserve_holds_table();
$wpdb->insert( $reserves, array( 'organizer_user_id' => $organizer, 'payment_post_id' => $p1, 'amount' => 9, 'status' => 'active', 'expires_at' => '2026-01-01 00:00:00', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$before = (float) LNKup_Organizer_Wallet::get_row( $organizer )['available_balance'];
LNKup_Reserves::release_expired();
lnkup_t_same( $before, (float) LNKup_Organizer_Wallet::get_row( $organizer )['available_balance'], 'refunded payment reserve does not credit the organizer' );
lnkup_t_same( 'used', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$reserves} WHERE payment_post_id = %d", $p1 ) ), 'reserve marked used' );

$wpdb->delete( $reserves, array( 'payment_post_id' => $p1 ) );
$wpdb->delete( LNKup_Financial_Tables::disputes_table(), array( 'payment_post_id' => $p6 ) );
lnkup_t_finish();
