<?php
/**
 * Refunded-fee backfill through the repair framework: dry run writes nothing, apply fixes
 * partial, full and lost-chargeback rows, a second dry run is clean, and wallets/ledgers are
 * untouched (reporting-only repair).
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/financial-fee-backfill-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Refunded-fee backfill\n";

$organizer = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );
$admin     = lnkup_t_user( 'administrator' );
$fees      = LNKup_Treasury_Tables::platform_fees_table();
wp_set_current_user( $admin );

/**
 * A booking refunded "before 12.25": meta says refunded, fee row still says 0 refunded.
 */
$legacy = static function ( string $status, float $refund, float $credit = 0.0 ) use ( $organizer, $vendor ): int {
	$pid = lnkup_t_payment( array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'total_amount' => '100.00', 'payment_status' => $status, 'refund_amount' => $refund, 'credit_refund_amount' => $credit ) );
	LNKup_Platform_Fees::record( $pid, array( 'gross_amount' => 100, 'lnkup_platform_fee' => 10 ) );
	return $pid;
};
$p_partial = $legacy( 'partially_refunded', 25 );
$p_full    = $legacy( 'refunded', 60, 40 );
$p_cb      = $legacy( 'chargeback_lost', 0 );
$p_clean   = $legacy( 'paid', 0 );
$ids       = array( $p_partial, $p_full, $p_cb, $p_clean );

lnkup_t_assert( null !== LNKup_Platform_Fees::refunded_fee_gap( $p_partial ), 'legacy partial refund has a fee gap' );
lnkup_t_assert( null === LNKup_Platform_Fees::refunded_fee_gap( $p_clean ), 'unrefunded booking has no gap' );
$candidates = LNKup_Platform_Fees::backfill_candidates();
lnkup_t_same( 3, count( $candidates[ $organizer ] ?? array() ), 'all-organizers scan finds the three refunded bookings' );
$report_issues = array();
foreach ( LNKup_Financial_Reconciliation::organizer_report( $organizer, array( 'stripe' => false ) )['payments'] as $row ) {
	if ( (int) $row['id'] === $p_full ) {
		$report_issues = (array) $row['issues'];
	}
}
lnkup_t_assert( in_array( 'refunded_fee_not_recorded', $report_issues, true ), 'reconciliation reports refunded_fee_not_recorded' );

$fingerprint = lnkup_t_financial_fingerprint( $organizer, $ids );
$fee_rows    = static function () use ( $wpdb, $fees, $ids ): array {
	return $wpdb->get_results( 'SELECT payment_post_id, refunded_platform_fee FROM ' . $fees . ' WHERE payment_post_id IN (' . implode( ',', $ids ) . ') ORDER BY payment_post_id', ARRAY_A ); // phpcs:ignore
};
$before = $fee_rows();

$dry   = LNKup_Financial_Repair::dry_run( $organizer, false );
$items = array_values( array_filter( $dry['items'], static fn( $i ) => 'sync_refunded_fee' === $i['type'] ) );
lnkup_t_same( 3, count( $items ), 'dry run plans three sync_refunded_fee items' );
lnkup_t_same( 0, array_sum( wp_list_pluck( $items, 'ledger_effect_cents' ) ), 'items move no ledger money' );
lnkup_t_same( $before, $fee_rows(), 'dry run writes nothing' );

$res = LNKup_Financial_Repair::apply( $organizer, $dry['confirmation_token'] );
lnkup_t_assert( ! is_wp_error( $res ), 'apply with token succeeds' );
$applied = array_filter( (array) ( $res['results'] ?? array() ), static fn( $r ) => 'sync_refunded_fee' === $r['type'] && 'applied' === $r['status'] );
lnkup_t_same( 3, count( $applied ), 'three fee rows fixed' );
lnkup_t_same( 7.5, LNKup_Platform_Fees::net_fee_for_payment( $p_partial ), '25% refund leaves $7.50 earned' );
lnkup_t_same( 0.0, LNKup_Platform_Fees::net_fee_for_payment( $p_full ), 'card + credit full refund leaves nothing earned' );
lnkup_t_same( 0.0, LNKup_Platform_Fees::net_fee_for_payment( $p_cb ), 'lost chargeback forfeits the fee' );
lnkup_t_same( 10.0, LNKup_Platform_Fees::net_fee_for_payment( $p_clean ), 'unrefunded booking untouched' );
lnkup_t_same( $fingerprint, lnkup_t_financial_fingerprint( $organizer, $ids ), 'wallets, ledger and payment meta unchanged' );

$again = LNKup_Financial_Repair::dry_run( $organizer, false );
lnkup_t_same( 0, count( array_filter( $again['items'], static fn( $i ) => 'sync_refunded_fee' === $i['type'] ) ), 'second dry run is clean' );
lnkup_t_same( array(), LNKup_Platform_Fees::backfill_candidates()[ $organizer ] ?? array(), 'organizer no longer listed for backfill' );

foreach ( $ids as $pid ) {
	$wpdb->delete( $fees, array( 'payment_post_id' => $pid ) );
}
lnkup_t_finish();
