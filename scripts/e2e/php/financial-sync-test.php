<?php
/**
 * Payment → ledger sync guarantees: exactly-once settlement, settle recovery, historical
 * cutoff, out-of-order webhooks, pure GET reads, fee snapshots, and payout restores.
 *
 * Uses simulated (sim_) intents only; never calls Stripe.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/financial-sync-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Financial sync guarantees\n";

$organizer = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );
$entries   = LNKup_Treasury_Tables::organizer_wallet_entries_table();

/**
 * Settlement ledger rows for a payment.
 *
 * @param int $payment_id Payment.
 */
function lnkup_t_settlement_rows( $payment_id ) {
	global $wpdb;
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . LNKup_Treasury_Tables::organizer_wallet_entries_table() . " WHERE payment_post_id = %d AND entry_type IN ('credit','connect_settlement') AND source_type = 'payment'",
			$payment_id
		)
	);
}

$base = array(
	'organizer_user_id' => $organizer,
	'vendor_user_id'    => $vendor,
	'vendor_spot_fee'   => '200.00',
	'total_amount'      => '217.99',
	'payment_status'    => 'pending',
);

// 1. Finalizing the same payment twice settles once.
$p1    = lnkup_t_payment( $base );
$first = LNKup_Financial_Service::finalize_payment( $p1, 'sim_first', array( 'source' => 'test' ) );
$again = LNKup_Financial_Service::finalize_payment( $p1, 'sim_first', array( 'source' => 'test_replay' ) );
lnkup_t_assert( ! is_wp_error( $first ) && ! empty( $first['success'] ), 'first finalize succeeds' );
lnkup_t_assert( ! empty( $again['already_paid'] ), 'replayed finalize reports already_paid' );
lnkup_t_same( 1, lnkup_t_settlement_rows( $p1 ), 'one settlement row after two finalizes' );
lnkup_t_assert( '' !== (string) get_post_meta( $p1, '_lnkup_settled_at', true ), 'settled_at marker stamped' );

$wallet_before = LNKup_Organizer_Wallet::get_row( $organizer );
LNKup_Organizer_Wallet::credit_from_payment( $p1, LNKup_Fee_Engine::settlement_breakdown( $p1 ) );
lnkup_t_same( 1, lnkup_t_settlement_rows( $p1 ), 'direct duplicate credit is ignored' );
lnkup_t_same( $wallet_before, LNKup_Organizer_Wallet::get_row( $organizer ), 'duplicate credit did not move wallet balances' );

$key_row = $wpdb->get_var( $wpdb->prepare( "SELECT idempotency_key FROM {$entries} WHERE payment_post_id = %d", $p1 ) );
lnkup_t_same( LNKup_Organizer_Wallet::payment_key( $p1, 'credit' ), $key_row, 'settlement row carries its idempotency key' );

// 2. Paid-but-unsettled payment (crash between mark_paid and settle) is recovered once.
$p2 = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'paid', 'paid_at' => current_time( 'mysql' ), 'stripe_payment_intent' => 'sim_crash' ) ) );
$r2 = LNKup_Financial_Service::reconcile_payment( $p2, 'sim_crash' );
lnkup_t_same( 'settlement_backfilled', $r2['action'] ?? null, 'recent unsettled paid payment is settled by reconcile' );
$r2b = LNKup_Financial_Service::reconcile_payment( $p2, 'sim_crash' );
lnkup_t_same( 'already_settled', $r2b['action'] ?? null, 'second reconcile is a no-op' );
lnkup_t_same( 1, lnkup_t_settlement_rows( $p2 ), 'recovered payment has exactly one settlement row' );

// 3. Historical gaps are left for the approval-gated repair tool.
$p3 = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'paid', 'paid_at' => '2026-01-05 12:00:00', 'stripe_payment_intent' => 'sim_old' ) ) );
$r3 = LNKup_Financial_Service::reconcile_payment( $p3, 'sim_old' );
lnkup_t_same( 'already_settled', $r3['action'] ?? null, 'historical unsettled payment is not auto-settled' );
lnkup_t_same( 0, lnkup_t_settlement_rows( $p3 ), 'historical payment still has no ledger row' );
$sync = LNKup_Financial_Service::sync_organizer( $organizer );
lnkup_t_assert( ! is_wp_error( $sync ), 'organizer sync runs' );
lnkup_t_same( 0, lnkup_t_settlement_rows( $p3 ), 'organizer sync does not touch historical payment' );

// 4. A late payment_failed event cannot overwrite a paid payment.
LNKup_Stripe_Webhooks::process_event(
	array(
		'id'   => 'evt_test_' . wp_generate_password( 8, false ),
		'type' => 'payment_intent.payment_failed',
		'data' => array( 'object' => array( 'id' => 'sim_first', 'metadata' => array( 'payment_post_id' => (string) $p1 ) ) ),
	)
);
lnkup_t_same( 'paid', get_post_meta( $p1, '_lnkup_payment_status', true ), 'out-of-order payment_failed leaves payment paid' );

$p4 = lnkup_t_payment( array_merge( $base, array( 'stripe_payment_intent' => 'pi_current' ) ) );
LNKup_Stripe_Webhooks::process_event(
	array(
		'id'   => 'evt_test_' . wp_generate_password( 8, false ),
		'type' => 'payment_intent.payment_failed',
		'data' => array( 'object' => array( 'id' => 'pi_stale_attempt', 'metadata' => array( 'payment_post_id' => (string) $p4 ) ) ),
	)
);
lnkup_t_same( 'pending', get_post_meta( $p4, '_lnkup_payment_status', true ), 'failure for a superseded PaymentIntent is ignored' );

// 5. Money GET endpoints are pure reads.
$p5 = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'paid', 'paid_at' => current_time( 'mysql' ), 'stripe_payment_intent' => 'sim_unsettled' ) ) );
$ids         = array( $p1, $p2, $p3, $p4, $p5 );
$fingerprint = lnkup_t_financial_fingerprint( $organizer, $ids );
wp_set_current_user( $organizer );
foreach ( array( '/lnkup/v1/financial/dashboard', '/lnkup/v1/financial/organizer-wallet', '/lnkup/v1/financial/organizer-balances', '/lnkup/v1/financial/transactions', '/lnkup/v1/financial/organizer-wallet/entries' ) as $route ) {
	$req = new WP_REST_Request( 'GET', $route );
	$req->set_param( 'role', 'organizer' );
	$res = rest_do_request( $req );
	lnkup_t_same( 200, $res->get_status(), "GET {$route} responds 200" );
}
lnkup_t_same( $fingerprint, lnkup_t_financial_fingerprint( $organizer, $ids ), 'money GET endpoints did not write wallet, ledger or payment meta' );
lnkup_t_same( 0, lnkup_t_settlement_rows( $p5 ), 'unsettled payment stays unsettled until a write path runs' );

$res = rest_do_request( new WP_REST_Request( 'POST', '/lnkup/v1/financial/sync' ) );
lnkup_t_same( 200, $res->get_status(), 'POST /financial/sync responds 200' );
lnkup_t_same( 1, lnkup_t_settlement_rows( $p5 ), 'explicit sync settles the recent unsettled payment' );
$res = rest_do_request( new WP_REST_Request( 'POST', '/lnkup/v1/financial/sync' ) );
lnkup_t_assert( ! empty( $res->get_data()['throttled'] ), 'repeat sync within 30s is throttled' );
wp_set_current_user( 0 );

// 6. Settlement uses the charge-time fee snapshot, not today's settings.
$snapshot = LNKup_Fee_Engine::calculate_for_payment( $p4 );
$snapshot['platform_fee']  = 17.99;
$snapshot['organizer_net'] = 200.00;
update_post_meta( $p4, '_lnkup_fee_breakdown', wp_json_encode( $snapshot ) );
$settings = get_option( 'lnkup_settings', array() );
update_option( 'lnkup_settings', array_merge( (array) $settings, array( 'vendor_checkout_fee' => 49.99 ) ) );
$settled_breakdown = LNKup_Fee_Engine::settlement_breakdown( $p4 );
update_option( 'lnkup_settings', $settings );
lnkup_t_same( 17.99, (float) $settled_breakdown['platform_fee'], 'settlement keeps the charge-time platform fee' );
lnkup_t_same( 'charge_snapshot', $settled_breakdown['breakdown_source'], 'breakdown source is the charge snapshot' );

// 7. Platform charge recovery credits organizer net, never gross.
$p7 = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'paid', 'paid_at' => current_time( 'mysql' ), 'stripe_destination_amount' => '217.99' ) ) );
LNKup_Organizer_Wallet::record_connect_settlement( $organizer, $p7, array( 'organizer_net' => 200.00, 'payout_eligible_amount' => 200.00 ) );
$amount = (float) $wpdb->get_var( $wpdb->prepare( "SELECT amount FROM {$entries} WHERE payment_post_id = %d AND entry_type = 'connect_settlement'", $p7 ) );
lnkup_t_same( 200.0, $amount, 'stale destination amount on a platform charge is ignored' );
LNKup_Organizer_Wallet::record_connect_settlement( $organizer, $p7, array( 'organizer_net' => 200.00 ) );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$entries} WHERE payment_post_id = %d AND entry_type = 'connect_settlement'", $p7 ) ), 'connect settlement is recorded once' );

// 8. Cancelled cashout restores the balance exactly once.
LNKup_Organizer_Wallet::add_entry( $organizer, 100, 'available', array( 'source_type' => 'test_seed', 'note' => 'seed' ) );
$wpdb->insert(
	LNKup_Treasury_Tables::organizer_payouts_table(),
	array(
		'user_id'     => $organizer,
		'amount'      => 40,
		'net_amount'  => 40,
		'status'      => 'requested',
		'provider'    => 'manual',
		'created_at'  => current_time( 'mysql' ),
		'updated_at'  => current_time( 'mysql' ),
	)
);
$payout_id = (int) $wpdb->insert_id;
LNKup_Organizer_Wallet::debit( $organizer, 40, array( 'source_type' => 'payout', 'source_id' => $payout_id, 'payout_id' => $payout_id ) );
LNKup_Organizer_Wallet::debit( $organizer, 40, array( 'source_type' => 'payout', 'source_id' => $payout_id, 'payout_id' => $payout_id ) );
$after_debit = (float) LNKup_Organizer_Wallet::get_row( $organizer )['available_balance'];
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$entries} WHERE payout_id = %d AND entry_type = 'debit'", $payout_id ) ), 'payout debit recorded once' );
lnkup_t_assert( true === LNKup_Organizer_Payouts::cancel( $payout_id, $organizer ), 'cancel succeeds' );
lnkup_t_assert( is_wp_error( LNKup_Organizer_Payouts::cancel( $payout_id, $organizer ) ), 'second cancel is refused' );
lnkup_t_same( $after_debit + 40, (float) LNKup_Organizer_Wallet::get_row( $organizer )['available_balance'], 'cancel restored the 40 once' );
lnkup_t_same( 'Cashout cancelled — balance restored', (string) $wpdb->get_var( $wpdb->prepare( "SELECT note FROM {$entries} WHERE payout_id = %d AND source_type = 'payout_cancel'", $payout_id ) ), 'restore row says cancelled, not failed' );
$wpdb->delete( LNKup_Treasury_Tables::organizer_payouts_table(), array( 'id' => $payout_id ) );

// 9. Payment lock serializes concurrent finalizers.
$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$other->get_var( "SELECT GET_LOCK('lnkup_pay_" . $p4 . "', 0)" );
$busy = LNKup_Financial_Service::with_lock( 'pay_' . $p4, '__return_true', 1 );
$other->get_var( "SELECT RELEASE_LOCK('lnkup_pay_" . $p4 . "')" );
lnkup_t_assert( is_wp_error( $busy ) && 'lnkup_busy' === $busy->get_error_code(), 'second finalizer waits and backs off while the payment lock is held' );
lnkup_t_same( true, LNKup_Financial_Service::with_lock( 'pay_' . $p4, '__return_true', 1 ), 'lock is acquirable after release' );

lnkup_t_finish();
