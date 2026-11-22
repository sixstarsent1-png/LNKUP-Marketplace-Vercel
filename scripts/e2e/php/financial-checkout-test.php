<?php
/**
 * Checkout and confirmation: credit math, closed/voided payments can never be re-paid, extra
 * charges are recorded not applied, deposits stay partially paid, webhook amount check,
 * declined payments reopen, processing payments cannot be charged again.
 *
 * Uses sim_/fake pi_ ids only; no Stripe calls (Stripe is not configured on wp-env).
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/financial-checkout-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Checkout and confirmation\n";

$organizer = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );
$base      = array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'vendor_spot_fee' => '100.00', 'payment_status' => 'pending' );

// 1. Credit is subtracted exactly once.
$p1 = lnkup_t_payment( $base );
LNKup_Payments::refresh_pending_fees( $p1 );
$gross = (float) get_post_meta( $p1, '_lnkup_total_amount', true );
update_post_meta( $p1, '_lnkup_credit_applied', 40 );
LNKup_Payments::refresh_pending_fees( $p1 );
lnkup_t_same( $gross, (float) get_post_meta( $p1, '_lnkup_total_amount', true ), 'applying credit keeps the stored total gross' );
lnkup_t_same( round( $gross - 40, 2 ), LNKup_Financial_Service::stored_amount_due( $p1 ), 'amount due = gross − credit (credit subtracted once)' );
lnkup_t_same( round( $gross - 40, 2 ), LNKup_Financial::get_checkout_amount( $p1 ), 'legacy checkout amount agrees' );

// 2. Refunded / disputed payments can never become paid again.
foreach ( array( 'refunded', 'partially_refunded', 'disputed', 'chargeback_lost' ) as $closed_status ) {
	$p = lnkup_t_payment( array_merge( $base, array( 'payment_status' => $closed_status, 'stripe_payment_intent' => 'pi_test_orig_' . $closed_status, 'refund_amount' => '10.00' ) ) );
	$r = LNKup_Financial_Service::finalize_payment( $p, 'pi_test_orig_' . $closed_status, array( 'source' => 'test' ) );
	lnkup_t_assert( ! empty( $r['refused'] ), "{$closed_status} payment refuses finalize" );
	lnkup_t_same( $closed_status, get_post_meta( $p, '_lnkup_payment_status', true ), "{$closed_status} status unchanged" );
	lnkup_t_same( '', get_post_meta( $p, '_lnkup_extra_charges', true ), "{$closed_status}: re-checking its own charge is not an extra charge" );
}
$p_ref = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'refunded', 'stripe_payment_intent' => 'pi_test_ref' ) ) );
$res   = LNKup_Financial_Service::confirm_checkout( $p_ref, 'sim_retry' );
lnkup_t_assert( is_wp_error( $res ) && 'payment_not_collectible' === $res->get_error_code(), 'vendor confirm on a refunded booking returns 409' );
lnkup_t_assert( false === LNKup_Payments::mark_paid( $p_ref ), 'mark_paid refuses a refunded payment' );
LNKup_Payments::mark_processing( $p_ref, 'pi_test_other' );
lnkup_t_same( 'refunded', get_post_meta( $p_ref, '_lnkup_payment_status', true ), 'mark_processing cannot reopen a refunded payment' );

// 3. Voided payment: a late charge is recorded for review, not applied.
$p_void = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'failed', 'voided_at' => current_time( 'mysql' ), 'stripe_payment_intent' => 'pi_test_void' ) ) );
$r      = LNKup_Financial_Service::finalize_payment( $p_void, 'pi_test_void', array( 'source' => 'test' ) );
lnkup_t_assert( ! empty( $r['refused'] ), 'voided payment refuses finalize' );
$extra = get_post_meta( $p_void, '_lnkup_extra_charges', true );
lnkup_t_same( 'charge_on_voided_payment', $extra['pi_test_void']['reason'] ?? null, 'charge on voided payment recorded for review' );
lnkup_t_same( false, LNKup_Payments::is_payable( $p_void ), 'voided payment is not payable' );
lnkup_t_same( false, LNKup_Payments::reopen_if_declined( $p_void ), 'voided payment is not reopened' );

// 4. Second successful charge on a paid payment: recorded, never overwrites the first.
$p_paid = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'paid', 'paid_at' => current_time( 'mysql' ), 'stripe_payment_intent' => 'pi_test_first', 'settled_at' => current_time( 'mysql' ) ) ) );
LNKup_Financial_Service::finalize_payment( $p_paid, 'pi_test_second', array( 'source' => 'payment_intent' ) );
$extra = get_post_meta( $p_paid, '_lnkup_extra_charges', true );
lnkup_t_same( 'second_charge_on_paid_payment', $extra['pi_test_second']['reason'] ?? null, 'second charge recorded' );
lnkup_t_same( 'pi_test_first', get_post_meta( $p_paid, '_lnkup_stripe_payment_intent', true ), 'recorded charge reference unchanged' );
LNKup_Financial_Service::finalize_payment( $p_paid, 'pi_test_second', array( 'source' => 'payment_intent' ) );
lnkup_t_same( 1, count( get_post_meta( $p_paid, '_lnkup_extra_charges', true ) ), 'extra charge recorded once' );

// 5. Deposit: booking stays partially paid (packet locked, not settled) until fully paid.
$p_dep = lnkup_t_payment( array_merge( $base, array( 'total_amount' => '200.00', 'installment_amount' => '50.00' ) ) );
$r     = LNKup_Financial_Service::finalize_payment( $p_dep, 'sim_deposit', array( 'source' => 'test' ) );
lnkup_t_assert( ! empty( $r['partial'] ), 'deposit charge is recorded as partial' );
lnkup_t_same( 'partially_paid', get_post_meta( $p_dep, '_lnkup_payment_status', true ), 'status is partially_paid' );
lnkup_t_same( 5000, (int) get_post_meta( $p_dep, '_lnkup_amount_paid_cents', true ), 'amount paid tracked in cents' );
lnkup_t_same( 150.0, (float) get_post_meta( $p_dep, '_lnkup_installment_amount', true ), 'next amount due is the balance' );
lnkup_t_same( '', (string) get_post_meta( $p_dep, '_lnkup_settled_at', true ), 'partial charge does not settle to the organizer' );
lnkup_t_same( true, LNKup_Payments::is_payable( $p_dep ), 'partially paid booking can be paid again' );
LNKup_Financial_Service::finalize_payment( $p_dep, 'sim_deposit', array( 'source' => 'test_replay' ) );
lnkup_t_same( 5000, (int) get_post_meta( $p_dep, '_lnkup_amount_paid_cents', true ), 'replayed deposit charge is not counted twice' );
$r = LNKup_Financial_Service::finalize_payment( $p_dep, 'sim_balance', array( 'source' => 'test' ) );
lnkup_t_same( 'paid', get_post_meta( $p_dep, '_lnkup_payment_status', true ), 'final charge marks the booking paid' );
lnkup_t_assert( '' !== (string) get_post_meta( $p_dep, '_lnkup_settled_at', true ), 'fully paid booking settles once' );

// 6. Webhook finalizes only when the charge covers the amount expected for it.
$p_short = lnkup_t_payment( array_merge( $base, array( 'total_amount' => '120.00' ) ) );
LNKup_Financial_Service::remember_expected_amount( $p_short, 'pi_test_short', 12000 );
LNKup_Stripe_Webhooks::process_event(
	array(
		'id'   => 'evt_test_' . wp_generate_password( 8, false ),
		'type' => 'payment_intent.succeeded',
		'data' => array( 'object' => array( 'id' => 'pi_test_short', 'amount_received' => 6000, 'currency' => 'usd', 'metadata' => array( 'payment_post_id' => (string) $p_short ) ) ),
	)
);
lnkup_t_same( 'pending', get_post_meta( $p_short, '_lnkup_payment_status', true ), 'short charge does not mark the booking paid' );
lnkup_t_same( 'amount_mismatch', get_post_meta( $p_short, '_lnkup_needs_review', true ), 'short charge flagged for review' );
LNKup_Stripe_Webhooks::process_event(
	array(
		'id'   => 'evt_test_' . wp_generate_password( 8, false ),
		'type' => 'payment_intent.succeeded',
		'data' => array( 'object' => array( 'id' => 'pi_test_short', 'amount_received' => 12000, 'currency' => 'usd', 'metadata' => array( 'payment_post_id' => (string) $p_short ) ) ),
	)
);
lnkup_t_same( 'paid', get_post_meta( $p_short, '_lnkup_payment_status', true ), 'charge covering the quoted amount finalizes' );
lnkup_t_same( false, LNKup_Financial_Service::charge_amount_ok( $p_short, 'pi_test_short', 12000, 'eur' ), 'non-USD charge rejected' );

// 7. A declined card leaves the booking payable; processing blocks a second charge.
$p_dec = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'failed' ) ) );
lnkup_t_same( true, LNKup_Payments::is_payable( $p_dec ), 'declined (failed, not voided) booking is payable' );
lnkup_t_same( true, LNKup_Payments::reopen_if_declined( $p_dec ), 'retry reopens the declined booking' );
lnkup_t_same( 'pending', get_post_meta( $p_dec, '_lnkup_payment_status', true ), 'status back to pending' );
$p_ach = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'processing' ) ) );
$res   = LNKup_Financial_Service::create_checkout( $p_ach, array( 'mode' => 'redirect' ) );
lnkup_t_assert( is_wp_error( $res ) && 409 === ( $res->get_error_data()['status'] ?? 0 ), 'hosted checkout refuses a booking whose bank payment is processing' );
$res = LNKup_Checkout::prepare( $p_ref );
lnkup_t_assert( is_wp_error( $res ), 'embedded checkout refuses a refunded booking' );

// 8. Diagnostics see the new problems.
$report = LNKup_Financial_Reconciliation::organizer_report( $organizer, array( 'stripe' => false ) );
lnkup_t_assert( ( $report['issue_counts']['extra_succeeded_charge'] ?? 0 ) >= 2, 'diagnostics count extra charges' );
lnkup_t_assert( ( $report['issue_counts']['charge_amount_mismatch'] ?? 0 ) >= 1, 'diagnostics count amount mismatches' );

lnkup_t_finish();
