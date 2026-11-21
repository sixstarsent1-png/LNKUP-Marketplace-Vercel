<?php
/**
 * Read-only reconciliation diagnostics: classification, duplicate detection,
 * permissions, and a guarantee that reports never write.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/financial-reconciliation-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

echo "Financial reconciliation diagnostics\n";

$organizer = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );
$admin     = lnkup_t_user( 'administrator' );

$base = array(
	'organizer_user_id' => $organizer,
	'vendor_user_id'    => $vendor,
	'total_amount'      => '230.00',
	'amount'            => '217.00',
);

$p_ok        = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'paid', 'paid_at' => '2026-10-02 10:00:00', 'stripe_payment_intent' => 'pi_test_ok' ) ) );
$p_twin      = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'paid', 'paid_at' => '2026-10-02 11:00:00', 'stripe_payment_intent' => 'pi_test_twin' ) ) );
$p_dup       = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'paid', 'paid_at' => '2026-10-09 09:00:00', 'stripe_payment_intent' => 'pi_test_dup' ) ) );
$p_missing   = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'paid', 'paid_at' => '2026-11-10 09:00:00', 'stripe_payment_intent' => 'pi_test_missing' ) ) );
$p_dest      = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'paid', 'paid_at' => '2026-11-11 09:00:00', 'stripe_payment_intent' => 'pi_test_dest', 'stripe_destination_account' => 'acct_test_dest' ) ) );
$p_pending   = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'pending', 'stripe_payment_intent' => 'pi_test_pending' ) ) );

lnkup_t_ledger_row( $organizer, array( 'amount' => 230, 'payment_post_id' => $p_ok, 'source_id' => $p_ok, 'created_at' => '2026-10-02 10:00:01' ) );
lnkup_t_ledger_row( $organizer, array( 'amount' => 230, 'payment_post_id' => $p_twin, 'source_id' => $p_twin, 'created_at' => '2026-10-02 11:00:01' ) );
lnkup_t_ledger_row( $organizer, array( 'amount' => 230, 'payment_post_id' => $p_dup, 'source_id' => $p_dup, 'created_at' => '2026-10-09 09:00:01' ) );
lnkup_t_ledger_row( $organizer, array( 'amount' => 230, 'payment_post_id' => $p_dup, 'source_id' => $p_dup, 'created_at' => '2026-10-09 09:00:02' ) );
lnkup_t_ledger_row( $organizer, array( 'amount' => 230, 'payment_post_id' => $p_dest, 'source_id' => $p_dest, 'created_at' => '2026-11-11 09:00:01' ) );

$all_payments = array( $p_ok, $p_twin, $p_dup, $p_missing, $p_dest, $p_pending );
$before       = lnkup_t_financial_fingerprint( $organizer, $all_payments );

$report = LNKup_Financial_Reconciliation::organizer_report( $organizer, array( 'stripe' => false ) );
$by_id  = array();
foreach ( $report['payments'] as $row ) {
	$by_id[ $row['id'] ] = $row;
}

lnkup_t_same( true, $report['read_only'], 'report declares read_only' );
lnkup_t_same( true, $report['schema']['connect_settlement_fits'], 'schema health confirms connect_settlement fits the ledger column' );

LNKup_Financial_Audit::log( 'treasury_migrated_to_connect_settlement', 'wallet', $organizer, array( 'amount' => 920.00 ) );
$with_migration = LNKup_Financial_Reconciliation::organizer_report( $organizer, array( 'stripe' => false ) );
lnkup_t_same( 92000, $with_migration['migrations']['unrecorded_cents'], 'migration that zeroed treasury without a ledger row is detected' );
lnkup_t_same( 1, $with_migration['issue_counts']['migration_zeroed_without_ledger'] ?? 0, 'unrecorded migration counted as an issue' );
lnkup_t_same( array(), $by_id[ $p_ok ]['issues'], 'clean paid payment has no issues' );
lnkup_t_assert( in_array( 'duplicate_ledger', $by_id[ $p_dup ]['issues'], true ), 'two settlement rows for one payment flagged duplicate_ledger' );
lnkup_t_assert( in_array( 'paid_missing_ledger', $by_id[ $p_missing ]['issues'], true ), 'paid payment with no ledger flagged paid_missing_ledger' );
lnkup_t_assert( in_array( 'treasury_credit_on_destination_charge', $by_id[ $p_dest ]['issues'], true ), 'treasury credit on destination charge flagged' );
lnkup_t_same( array(), $by_id[ $p_pending ]['issues'], 'unpaid payment without ledger has no issues' );
lnkup_t_same( 5 * 23000, $report['totals']['wp_paid_gross_cents'], 'collected gross counts each paid payment once (cents)' );
$paid_order = array_values( array_filter( wp_list_pluck( $report['payments'], 'id' ), static function ( $id ) use ( $p_pending ) {
	return $id !== $p_pending;
} ) );
lnkup_t_same( array( $p_dest, $p_missing, $p_dup, $p_twin, $p_ok ), $paid_order, 'paid payments sorted by paid date, newest first' );

$clusters = array();
foreach ( $report['same_amount_same_day'] as $cluster ) {
	$clusters[ $cluster['day'] ] = $cluster;
}
lnkup_t_same( 'distinct_payments', $clusters['2026-10-02']['verdict'] ?? null, 'same amount same day on different payments is not a duplicate' );
lnkup_t_same( 'possible_duplicate', $clusters['2026-10-09']['verdict'] ?? null, 'same amount same day on one payment is a possible duplicate' );

$audit = LNKup_Financial_Reconciliation::ledger_audit( $organizer );
lnkup_t_same( 1, count( $audit['duplicates'] ), 'ledger audit reports exactly one duplicate row' );
lnkup_t_same( 5 * 23000, $audit['sums_cents']['credit:payment'] ?? null, 'ledger sums in integer cents' );

$summary = LNKup_Financial_Reconciliation::platform_summary();
$mine    = array_values( array_filter( $summary['organizers'], static function ( $r ) use ( $organizer ) {
	return $r['organizer_id'] === $organizer;
} ) );
lnkup_t_assert( ! empty( $mine ) && $mine[0]['issue_total'] >= 3, 'platform summary lists organizer with its issues' );

lnkup_t_same( $before, lnkup_t_financial_fingerprint( $organizer, $all_payments ), 'diagnostics did not modify wallet, ledger or payment meta' );

$pi_clean = array(
	'status'                 => 'succeeded',
	'amount_received'        => 23000,
	'destination'            => '',
	'application_fee_amount' => 0,
	'amount_refunded'        => 0,
);
$payment_row = array(
	'status'        => 'pending',
	'total_cents'   => 23000,
	'paid_at'       => '',
	'intent_id'     => 'pi_x',
	'session_id'    => '',
	'destination'   => '',
	'app_fee_cents' => 0,
);
lnkup_t_assert(
	in_array( 'stripe_succeeded_wp_not_paid', LNKup_Financial_Reconciliation::classify_payment( $payment_row, array(), $pi_clean ), true ),
	'Stripe succeeded but WordPress pending is flagged'
);
lnkup_t_assert(
	in_array( 'amount_mismatch', LNKup_Financial_Reconciliation::classify_payment( array_merge( $payment_row, array( 'status' => 'paid', 'paid_at' => 'x' ) ), array(), array_merge( $pi_clean, array( 'amount_received' => 22999 ) ) ), true ),
	'one-cent amount mismatch is flagged'
);
lnkup_t_same( 1999, LNKup_Financial_Reconciliation::to_cents( '19.99' ), 'to_cents avoids float drift' );

wp_set_current_user( $vendor );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/admin/financial/reconcile' ) );
lnkup_t_same( 403, $res->get_status(), 'vendor cannot read reconciliation' );
wp_set_current_user( $organizer );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/admin/financial/ledger-audit/' . $organizer ) );
lnkup_t_same( 403, $res->get_status(), 'organizer cannot read their own ledger audit' );
wp_set_current_user( 0 );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/admin/financial/reconcile' ) );
lnkup_t_same( 401, $res->get_status(), 'anonymous request is rejected' );
wp_set_current_user( $admin );
$req = new WP_REST_Request( 'GET', '/lnkup/v1/admin/financial/reconcile' );
$req->set_param( 'organizer_id', $organizer );
$req->set_param( 'stripe', false );
$res = rest_do_request( $req );
lnkup_t_same( 200, $res->get_status(), 'administrator can read reconciliation' );
lnkup_t_assert( false === strpos( (string) wp_json_encode( $res->get_data() ), 'sk_' ), 'response contains no secret key material' );
wp_set_current_user( 0 );

lnkup_t_finish();
