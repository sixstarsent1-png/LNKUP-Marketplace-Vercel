<?php
/**
 * Read-only check after a REAL Stripe test-mode booking (acceptance test 13).
 * Follow docs/finance/STRIPE_TEST_MODE_RUNBOOK.md, then run with the payment post ID and the
 * stage you just completed:
 *
 *   wp eval-file wp-content/lnkup-e2e/php/org-growth-stripe-check.php <payment_id> paid|partial|full
 *
 * Writes nothing. Exits non-zero when an expectation fails.
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
$payment_id = absint( $args[0] ?? 0 );
$stage      = sanitize_key( (string) ( $args[1] ?? 'paid' ) );
if ( ! $payment_id || 'lnkup_payment' !== get_post_type( $payment_id ) ) {
	echo "Usage: wp eval-file .../org-growth-stripe-check.php <payment_id> paid|partial|full\n";
	exit( 1 );
}
echo "Stripe test-mode check — payment #{$payment_id} ({$stage})\n";

$status  = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
$intent  = (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
$total   = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
$refund  = (float) get_post_meta( $payment_id, '_lnkup_refund_amount', true );
$org     = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
$lead_id = $org ? LNKup_Org_Leads::lead_for_user( $org ) : 0;
echo "  info status={$status} total={$total} refunded={$refund} intent=" . ( '' !== $intent ? substr( $intent, 0, 8 ) . '…' : '(none)' ) . " organizer=#{$org} lead=#{$lead_id}\n";

lnkup_t_assert( 0 === strpos( $intent, 'pi_' ), 'payment has a real Stripe PaymentIntent' );
$secret = (string) ( lnkup_get_settings()['stripe_secret_key'] ?? '' );
lnkup_t_assert( '' !== $secret && ! preg_match( '/^(sk|rk)_live_/', $secret ), 'site uses Stripe TEST keys, not live keys' );

// Webhooks for this intent: each Stripe event logged once and processed.
$hooks = $wpdb->get_results( $wpdb->prepare( 'SELECT event_type, status, COUNT(*) AS n FROM ' . LNKup_Financial_Tables::webhook_events_table() . ' WHERE payload LIKE %s GROUP BY event_type, status', '%' . $wpdb->esc_like( $intent ) . '%' ), ARRAY_A ); // phpcs:ignore
foreach ( (array) $hooks as $h ) {
	echo "  info webhook {$h['event_type']} {$h['status']} ×{$h['n']}\n";
}
$unprocessed = array_filter( (array) $hooks, static fn( $h ) => 'processed' !== $h['status'] && 'skipped' !== $h['status'] );
lnkup_t_same( array(), array_values( $unprocessed ), 'every webhook for this payment was processed' );

// Settlement happened exactly once.
$fee_rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Treasury_Tables::platform_fees_table() . ' WHERE payment_post_id = %d', $payment_id ) ); // phpcs:ignore
lnkup_t_same( 1, $fee_rows, 'one platform-fee row (settled once, even after webhook replays)' );
$received = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::payment_timeline_table() . " WHERE payment_post_id = %d AND step = 'payment_received'", $payment_id ) ); // phpcs:ignore
lnkup_t_assert( $received <= 1, "payment_received recorded at most once ({$received})" );

$gross = (int) round( $total * 100 );
$fee   = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT lnkup_platform_fee FROM ' . LNKup_Treasury_Tables::platform_fees_table() . ' WHERE payment_post_id = %d ORDER BY id ASC LIMIT 1', $payment_id ) ); // phpcs:ignore
$net   = LNKup_Platform_Fees::net_fee_for_payment( $payment_id );
$expect_net = $gross > 0 ? round( $fee * ( 1 - min( 1, LNKup_Platform_Fees::returned_cents( $payment_id ) / $gross ) ), 2 ) : 0.0;
lnkup_t_same( $expect_net, $net, 'net platform fee matches the refunded share' );
lnkup_t_same( null, LNKup_Platform_Fees::refunded_fee_gap( $payment_id ), 'no refunded-fee backfill needed' );

switch ( $stage ) {
	case 'paid':
		lnkup_t_same( 'paid', $status, 'WordPress shows the booking paid' );
		break;
	case 'partial':
		lnkup_t_same( 'partially_refunded', $status, 'Dashboard partial refund reached WordPress' );
		lnkup_t_assert( $net > 0 && $net < $fee, 'part of the fee is still earned' );
		break;
	case 'full':
		lnkup_t_same( 'refunded', $status, 'Dashboard full refund reached WordPress' );
		lnkup_t_same( 0.0, $net, 'no fee earned on a fully refunded booking' );
		break;
}
lnkup_t_same( LNKup_Platform_Fees::returned_cents( $payment_id ), (int) get_post_meta( $payment_id, '_lnkup_refund_sync_announced_cents', true ), 'refund progress announced exactly up to the refunded amount' );

// Rewards: no reward stays payable on refunded money; nothing reversed twice.
$rewards = $wpdb->get_results( $wpdb->prepare( 'SELECT id, program, amount, reversed_amount, reward_status FROM ' . LNKup_Treasury_Tables::referral_rewards_table() . ' WHERE payment_id = %d', $payment_id ), ARRAY_A ); // phpcs:ignore
foreach ( (array) $rewards as $r ) {
	echo "  info reward #{$r['id']} {$r['program']} amount={$r['amount']} reversed={$r['reversed_amount']} status={$r['reward_status']}\n";
	lnkup_t_assert( (float) $r['reversed_amount'] <= (float) $r['amount'] + 0.001, "reward #{$r['id']} never reversed more than its amount" );
	if ( 'full' === $stage && 'organizer_recruit' === $r['program'] ) {
		lnkup_t_same( 'reversed', $r['reward_status'], "organizer-recruit reward #{$r['id']} fully reversed" );
	}
	if ( 'full' === $stage && (int) $r['id'] && 'city_connector' === $r['program'] ) {
		lnkup_t_assert( in_array( $r['reward_status'], array( 'reversed', 'paid' ), true ), "connector reward #{$r['id']} reversed (or flagged if already paid)" );
	}
}

// Organizer Growth milestone follows the money.
if ( $lead_id ) {
	$ms = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::MILESTONES ) . " WHERE lead_id = %d AND milestone = 'first_paid_booking'", $lead_id ) ); // phpcs:ignore
	$paid_left = count( LNKup_Org_Lead_Lifecycle::paid_payments( LNKup_Org_Leads::linked_user_ids( $lead_id ) ) );
	echo "  info first_paid_booking milestone={$ms} paid bookings left={$paid_left}\n";
	lnkup_t_same( $paid_left > 0 ? 'active' : 'voided', $ms, 'paid-booking milestone matches the paid bookings that remain' );
} else {
	echo "  info organizer is not linked to an Organizer Growth lead — milestone checks skipped\n";
}

$GLOBALS['lnkup_test_cleanup'] = array();
lnkup_t_finish();
