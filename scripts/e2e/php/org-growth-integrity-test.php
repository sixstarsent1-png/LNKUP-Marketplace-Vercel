<?php
/**
 * Stage 0 money-integrity tests for the Organizer Acquisition OS.
 *
 * - vendor-referral credits only pay the vendor_referral program
 * - organizer-recruit fee fallback reads the JSON fee snapshot
 * - every refund path announces `lnkup_payment_refund_synced` once per increase
 * - net platform fees drop with refunds and lost chargebacks
 * - organizer-recruit rewards are clawed back proportionally (spent credit flagged)
 * - unpaid connector rewards are reversed; paid ones flagged for review exactly once
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/org-growth-integrity-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Organizer Growth money integrity\n";

$rewards  = LNKup_Treasury_Tables::referral_rewards_table();
$refs     = LNKup_Treasury_Tables::referrals_table();
$fees     = LNKup_Treasury_Tables::platform_fees_table();
$credits  = LNKup_Financial_Tables::credits_table();
$admin    = lnkup_t_user( 'administrator' );
$org      = lnkup_t_user( 'lnkup_organizer' );
$vendor_a = lnkup_t_user( 'lnkup_vendor' );
$vendor_b = lnkup_t_user( 'lnkup_vendor' );
$referrer = lnkup_t_user( 'lnkup_vendor' );
$ref_rows = array();
$rew_rows = array();
$payments = array();
wp_set_current_user( $admin );

$announced = array();
add_action(
	'lnkup_payment_refund_synced',
	static function ( $pid, $cents, $full ) use ( &$announced ) {
		$announced[] = array( (int) $pid, (int) $cents, (bool) $full );
	},
	1,
	3
);

$paid_payment = static function ( int $vendor, float $total, float $fee ) use ( $org, &$payments ): int {
	$pid = lnkup_t_payment( array( 'organizer_user_id' => $org, 'vendor_user_id' => $vendor, 'total_amount' => number_format( $total, 2, '.', '' ), 'payment_status' => 'paid' ) );
	LNKup_Platform_Fees::record( $pid, array( 'gross_amount' => $total, 'lnkup_platform_fee' => $fee ) );
	$payments[] = $pid;
	return $pid;
};

// --------------------------------------------------------------- Program filter.
echo "\n[program filter]\n";
$now = current_time( 'mysql' );
$wpdb->insert( $refs, array( 'referrer_user_id' => $referrer, 'referred_user_id' => $vendor_a, 'program' => 'city_connector', 'status' => 'pending', 'created_at' => $now ) );
$ref_rows[] = (int) $wpdb->insert_id;
$wpdb->insert( $refs, array( 'referrer_user_id' => $referrer, 'referred_user_id' => $vendor_b, 'program' => 'vendor_referral', 'status' => 'pending', 'created_at' => $now ) );
$ref_rows[] = (int) $wpdb->insert_id;
$p_a = $paid_payment( $vendor_a, 100, 0 );
$p_b = $paid_payment( $vendor_b, 100, 0 );
LNKup_Referral_Credits::on_payment_completed( $p_a );
LNKup_Referral_Credits::on_payment_completed( $p_b );
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
lnkup_t_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rewards} WHERE referral_id = %d AND event_type = 'first_invoice_paid'", $ref_rows[0] ) ), 'connector-referred vendor does not trigger a vendor-referral credit' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rewards} WHERE referral_id = %d AND event_type = 'first_invoice_paid'", $ref_rows[1] ) ), 'vendor-referral program still pays its own referrals' );
lnkup_t_same( 'pending', (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$refs} WHERE id = %d", $ref_rows[0] ) ), 'connector referral row is left untouched' );
$rew_rows = array_merge( $rew_rows, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$rewards} WHERE referral_id IN (%d, %d)", $ref_rows[0], $ref_rows[1] ) ) ) );

// --------------------------------------------------------------- Fee fallback.
echo "\n[fee fallback]\n";
$p_json = lnkup_t_payment( array( 'fee_breakdown' => wp_json_encode( array( 'lnkup_platform_fee' => 7.5, 'platform_fee' => 7.5 ) ) ) );
$payments[] = $p_json;
lnkup_t_same( 7.5, LNKup_Organizer_Recruit_Referrals::resolve_platform_fee_for_payment( $p_json ), 'JSON fee snapshot is read' );

// --------------------------------------------------------------- Net fees + announcement idempotency.
echo "\n[net fees]\n";
$p_net = $paid_payment( $vendor_a, 100, 10 );
lnkup_t_same( 10.0, LNKup_Platform_Fees::net_fee_for_payment( $p_net ), 'net fee before refund' );
update_post_meta( $p_net, '_lnkup_refund_amount', 40 );
LNKup_Refunds::sync_payment_refund_status( $p_net );
LNKup_Refunds::sync_payment_refund_status( $p_net );
$mine = array_values( array_filter( $announced, static fn( $a ) => $a[0] === $p_net ) );
lnkup_t_same( 1, count( $mine ), 'partial refund announced once despite repeated syncs' );
lnkup_t_same( array( $p_net, 4000, false ), $mine[0] ?? null, 'announcement carries cumulative cents and partial flag' );
lnkup_t_same( 6.0, LNKup_Platform_Fees::net_fee_for_payment( $p_net ), '40% refund leaves 60% of the fee' );
update_post_meta( $p_net, '_lnkup_refund_amount', 100 );
LNKup_Refunds::sync_payment_refund_status( $p_net );
$mine = array_values( array_filter( $announced, static fn( $a ) => $a[0] === $p_net ) );
lnkup_t_same( 2, count( $mine ), 'larger refund announced again' );
lnkup_t_same( true, $mine[1][2] ?? null, 'full refund flagged' );
lnkup_t_same( 0.0, LNKup_Platform_Fees::net_fee_for_payment( $p_net ), 'full refund leaves no earned fee' );
$sum_before = LNKup_Platform_Fees::sum_platform_fees();
lnkup_t_assert( LNKup_Platform_Fees::sum_net_platform_fees() <= $sum_before, 'net total never exceeds gross total' );

$p_cb = $paid_payment( $vendor_a, 80, 8 );
do_action( 'lnkup_payment_chargeback_lost', $p_cb, 8000 );
lnkup_t_same( 0.0, LNKup_Platform_Fees::net_fee_for_payment( $p_cb ), 'lost chargeback forfeits the whole fee' );

// --------------------------------------------------------------- Organizer-recruit clawback.
echo "\n[organizer recruit]\n";
$p_or   = $paid_payment( $vendor_a, 200, 20 );
$insert = static function ( array $row ) use ( $wpdb, $rewards, &$rew_rows ): int {
	$wpdb->insert( $rewards, array_merge( array( 'created_at' => current_time( 'mysql' ) ), $row ) );
	$rew_rows[] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
};
$or_reward = $insert( array( 'user_id' => $referrer, 'event_type' => 'organizer_recruit_fee', 'amount' => 4.00, 'status' => 'available', 'reward_status' => 'paid', 'payment_id' => $p_or, 'program' => 'organizer_recruit' ) );
$credit_id = (int) LNKup_Credits::grant( $referrer, 4.00, 'referral', 'organizer_recruit', $or_reward );
$wpdb->update( $rewards, array( 'credit_id' => $credit_id ), array( 'id' => $or_reward ) );
$balance0  = LNKup_Wallet::get_balance( $referrer, 'vendor' );

update_post_meta( $p_or, '_lnkup_refund_amount', 100 );
LNKup_Refunds::sync_payment_refund_status( $p_or );
LNKup_Refunds::sync_payment_refund_status( $p_or );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$rewards} WHERE id = %d", $or_reward ), ARRAY_A );
lnkup_t_same( '2.00', (string) $row['reversed_amount'], '50% refund reverses 50% of the reward' );
lnkup_t_same( 'partially_reversed', (string) $row['reward_status'], 'reward marked partially reversed' );
lnkup_t_same( '2.00', (string) $wpdb->get_var( $wpdb->prepare( "SELECT remaining FROM {$credits} WHERE id = %d", $credit_id ) ), 'unspent credit revoked by the reversed amount' );
lnkup_t_same( round( $balance0 - 2.0, 2 ), round( LNKup_Wallet::get_balance( $referrer, 'vendor' ), 2 ), 'wallet debited once despite repeated syncs' );
update_post_meta( $p_or, '_lnkup_refund_amount', 200 );
LNKup_Refunds::sync_payment_refund_status( $p_or );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$rewards} WHERE id = %d", $or_reward ), ARRAY_A );
lnkup_t_same( '4.00', (string) $row['reversed_amount'], 'full refund reverses the rest' );
lnkup_t_same( 'reversed', (string) $row['reward_status'], 'reward marked reversed' );
lnkup_t_same( 'revoked', (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$credits} WHERE id = %d", $credit_id ) ), 'credit fully revoked' );

$p_spent   = $paid_payment( $vendor_a, 50, 5 );
$sp_reward = $insert( array( 'user_id' => $referrer, 'event_type' => 'organizer_recruit_fee', 'amount' => 1.00, 'status' => 'available', 'reward_status' => 'paid', 'payment_id' => $p_spent, 'program' => 'organizer_recruit' ) );
$sp_credit = (int) LNKup_Credits::grant( $referrer, 1.00, 'referral', 'organizer_recruit', $sp_reward );
$wpdb->update( $credits, array( 'remaining' => 0, 'status' => 'used' ), array( 'id' => $sp_credit ) );
$wpdb->update( $rewards, array( 'credit_id' => $sp_credit ), array( 'id' => $sp_reward ) );
update_post_meta( $p_spent, '_lnkup_refund_amount', 50 );
LNKup_Refunds::sync_payment_refund_status( $p_spent );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::audit_table() . " WHERE action = 'organizer_recruit_clawback_review' AND entity_id = %d", $sp_reward ) ), 'spent credit is flagged for admin clawback review' );

// --------------------------------------------------------------- Connector rewards.
echo "\n[connector rewards]\n";
$p_cc     = $paid_payment( $vendor_a, 120, 12 );
$cc_open  = $insert( array( 'user_id' => $referrer, 'connector_id' => 999001, 'event_type' => 'connector_booking_fee', 'amount' => 12.00, 'status' => 'pending', 'reward_status' => 'pending', 'payment_id' => $p_cc, 'program' => 'city_connector' ) );
$cc_paid  = $insert( array( 'user_id' => $referrer, 'connector_id' => 999002, 'event_type' => 'connector_booking_fee', 'amount' => 12.00, 'status' => 'paid', 'reward_status' => 'paid', 'payment_id' => $p_cc, 'program' => 'city_connector' ) );
update_post_meta( $p_cc, '_lnkup_refund_amount', 30 );
LNKup_Refunds::sync_payment_refund_status( $p_cc );
update_post_meta( $p_cc, '_lnkup_refund_amount', 120 );
LNKup_Refunds::sync_payment_refund_status( $p_cc );
lnkup_t_same( 'reversed', (string) $wpdb->get_var( $wpdb->prepare( "SELECT reward_status FROM {$rewards} WHERE id = %d", $cc_open ) ), 'unpaid connector reward reversed on a Dashboard-style refund' );
lnkup_t_same( 'paid', (string) $wpdb->get_var( $wpdb->prepare( "SELECT reward_status FROM {$rewards} WHERE id = %d", $cc_paid ) ), 'paid connector reward is not silently changed' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::audit_table() . " WHERE action = 'connector_reward_clawback_review' AND entity_id = %d", $cc_paid ) ), 'paid reward flagged for clawback exactly once across two refund syncs' );

// --------------------------------------------------------------- Admin credit-refund path uses the same hook.
echo "\n[admin refund path]\n";
$p_admin = $paid_payment( $vendor_a, 60, 6 );
$req     = LNKup_Refunds::request( $p_admin, 60, 'Integrity test', 'credit' );
lnkup_t_assert( ! is_wp_error( $req ), 'credit refund requested' . ( is_wp_error( $req ) ? ' (' . $req->get_error_message() . ')' : '' ) );
if ( ! is_wp_error( $req ) ) {
	$ok = LNKup_Refunds::approve( (int) $req, $admin );
	lnkup_t_assert( true === $ok, 'credit refund approved' . ( is_wp_error( $ok ) ? ' (' . $ok->get_error_message() . ')' : '' ) );
	$mine = array_values( array_filter( $announced, static fn( $a ) => $a[0] === $p_admin ) );
	lnkup_t_same( 1, count( $mine ), 'admin refund path announces through the same hook' );
	lnkup_t_same( 0.0, LNKup_Platform_Fees::net_fee_for_payment( $p_admin ), 'admin full credit refund zeroes the earned fee' );
	$wpdb->delete( LNKup_Financial_Tables::refunds_table(), array( 'id' => (int) $req ) );
}

// --------------------------------------------------------------- Cleanup.
foreach ( $payments as $pid ) {
	$wpdb->delete( $fees, array( 'payment_post_id' => $pid ) );
}
foreach ( $rew_rows as $rid ) {
	$wpdb->delete( $rewards, array( 'id' => $rid ) );
}
foreach ( $ref_rows as $rid ) {
	$wpdb->delete( $refs, array( 'id' => $rid ) );
}
foreach ( array( $referrer, $vendor_a, $vendor_b ) as $uid ) {
	$wpdb->delete( $credits, array( 'user_id' => $uid ) );
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Financial_Tables::wallets_table() . ' WHERE user_id = %d', $uid ) ) as $wallet_id ) {
		$wpdb->delete( LNKup_Financial_Tables::wallet_entries_table(), array( 'wallet_id' => (int) $wallet_id ) );
	}
	$wpdb->delete( LNKup_Financial_Tables::wallets_table(), array( 'user_id' => $uid ) );
}
// phpcs:enable

lnkup_t_finish();
