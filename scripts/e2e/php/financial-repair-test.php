<?php
/**
 * Historical repair: dry run writes nothing, apply needs the token, stale plans are refused,
 * duplicates are offset (never deleted), unverified settlements are not applied, and every
 * run is audited.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/financial-repair-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Financial repair\n";

$organizer = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );
$admin     = lnkup_t_user( 'administrator' );
$entries   = LNKup_Treasury_Tables::organizer_wallet_entries_table();
$base      = array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'total_amount' => '230.00', 'payment_status' => 'paid', 'paid_at' => '2026-10-09 09:00:00' );

$p_dup     = lnkup_t_payment( array_merge( $base, array( 'stripe_payment_intent' => 'pi_test_dup' ) ) );
$p_missing = lnkup_t_payment( array_merge( $base, array( 'stripe_payment_intent' => 'pi_test_missing' ) ) );
$keep_id   = lnkup_t_ledger_row( $organizer, array( 'amount' => 230, 'payment_post_id' => $p_dup, 'source_id' => $p_dup ) );
$dup_id    = lnkup_t_ledger_row( $organizer, array( 'amount' => 230, 'payment_post_id' => $p_dup, 'source_id' => $p_dup ) );
$wpdb->update( LNKup_Treasury_Tables::organizer_wallets_table(), array( 'available_balance' => 460 ), array( 'user_id' => $organizer ) );
LNKup_Financial_Audit::log( 'treasury_migrated_to_connect_settlement', 'wallet', $organizer, array( 'amount' => 920.00 ) );

$ids    = array( $p_dup, $p_missing );
$before = lnkup_t_financial_fingerprint( $organizer, $ids );

wp_set_current_user( $admin );
$dry = LNKup_Financial_Repair::dry_run( $organizer, false );
lnkup_t_same( $before, lnkup_t_financial_fingerprint( $organizer, $ids ), 'dry run writes no financial data' );
$by_id = array();
foreach ( $dry['items'] as $item ) {
	$by_id[ $item['id'] ] = $item;
}
lnkup_t_assert( isset( $by_id[ 'dup:' . $dup_id ] ) && ! isset( $by_id[ 'dup:' . $keep_id ] ), 'plan offsets the second settlement row and keeps the first' );
lnkup_t_same( false, $by_id[ 'settle:' . $p_missing ]['applicable'] ?? null, 'missing settlement is not applicable without Stripe verification' );
lnkup_t_assert( 1 === count( array_filter( $dry['items'], static function ( $i ) {
	return 'restore_migration_ledger_row' === $i['type'];
} ) ), 'plan restores the migration that lost its ledger row' );
lnkup_t_same( 92000 - 23000, $dry['net_effect_cents'], 'net effect = +920 migration − 230 duplicate (cents)' );

lnkup_t_assert( is_wp_error( LNKup_Financial_Repair::apply( $organizer, 'deadbeef' ) ), 'apply without a valid token is refused' );
lnkup_t_same( $before, lnkup_t_financial_fingerprint( $organizer, $ids ), 'refused apply wrote nothing' );

// Stale plan: data changes between dry run and apply.
$stale = LNKup_Financial_Repair::dry_run( $organizer, false );
$extra = lnkup_t_ledger_row( $organizer, array( 'amount' => 230, 'payment_post_id' => $p_dup, 'source_id' => $p_dup ) );
$res   = LNKup_Financial_Repair::apply( $organizer, $stale['confirmation_token'] );
lnkup_t_assert( is_wp_error( $res ) && 'lnkup_repair_stale' === $res->get_error_code(), 'apply refuses a plan that no longer matches the data' );
$wpdb->delete( $entries, array( 'id' => $extra ) );

$dry    = LNKup_Financial_Repair::dry_run( $organizer, false );
$result = LNKup_Financial_Repair::apply( $organizer, $dry['confirmation_token'] );
lnkup_t_assert( ! is_wp_error( $result ), 'apply with the dry-run token succeeds' );
$applied = wp_list_pluck( array_filter( $result['results'], static function ( $r ) {
	return 'applied' === $r['status'];
} ), 'id' );
lnkup_t_assert( in_array( 'dup:' . $dup_id, $applied, true ), 'duplicate offset applied' );
lnkup_t_assert( ! in_array( 'settle:' . $p_missing, $applied, true ), 'unverified settlement not applied' );

lnkup_t_same( '2', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$entries} WHERE id IN (%d, %d)", $keep_id, $dup_id ) ), 'no ledger rows were deleted' );
$offset = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$entries} WHERE source_type = 'reconciliation_offset' AND source_id = %d", $dup_id ), ARRAY_A );
lnkup_t_same( '230.00', $offset['amount'] ?? null, 'offset debit equals the duplicate amount' );
lnkup_t_same( 'debit', $offset['entry_type'] ?? null, 'offset is a debit' );
lnkup_t_same( 230.0, (float) LNKup_Organizer_Wallet::get_row( $organizer )['available_balance'], 'treasury available reduced by the duplicate only' );
lnkup_t_same( 920.0, LNKup_Organizer_Wallet::sum_connect_settlement( $organizer ), 'restored migration appears in LNKup settled' );

lnkup_t_assert( is_wp_error( LNKup_Financial_Repair::apply( $organizer, $dry['confirmation_token'] ) ), 'a token can only be used once' );
$again = LNKup_Financial_Repair::dry_run( $organizer, false );
lnkup_t_same( 0, count( array_filter( $again['items'], static function ( $i ) {
	return $i['applicable'];
} ) ), 'after repair only unverifiable items remain' );
$report = LNKup_Financial_Reconciliation::organizer_report( $organizer, array( 'stripe' => false ) );
lnkup_t_assert( empty( $report['issue_counts']['duplicate_ledger'] ), 'diagnostics no longer report the offset duplicate' );

$runs = get_option( LNKup_Financial_Repair::RUNS_OPTION, array() );
lnkup_t_same( $result['run_id'], $runs[0]['run_id'] ?? null, 'repair run recorded' );
lnkup_t_assert( (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Financial_Tables::audit_table() . " WHERE action = 'reconciliation_repair_run' AND entity_id = %d", $organizer ) ), 'repair run audited' );

wp_set_current_user( $vendor );
$req = new WP_REST_Request( 'POST', '/lnkup/v1/admin/financial/repair' );
$req->set_param( 'organizer_id', $organizer );
lnkup_t_same( 403, rest_do_request( $req )->get_status(), 'vendor cannot run repairs' );
wp_set_current_user( $organizer );
lnkup_t_same( 403, rest_do_request( $req )->get_status(), 'organizer cannot repair their own ledger' );
wp_set_current_user( 0 );

// Duplicate escrow holds and a refund the organizer never paid back.
wp_set_current_user( $admin );
$escrow = LNKup_Treasury_Tables::escrow_holds_table();
$p_esc  = lnkup_t_payment( array_merge( $base, array( 'stripe_payment_intent' => 'pi_test_esc' ) ) );
LNKup_Organizer_Wallet::add_entry( $organizer, 230, 'pending', array( 'source_type' => 'payment', 'payment_post_id' => $p_esc, 'idempotency_key' => LNKup_Organizer_Wallet::payment_key( $p_esc, 'credit' ) ) );
foreach ( array( 1, 2 ) as $n ) {
	$wpdb->insert( $escrow, array( 'payment_post_id' => $p_esc, 'organizer_user_id' => $organizer, 'amount' => 230, 'status' => 'held', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
}
$p_ref = lnkup_t_payment( array_merge( $base, array( 'payment_status' => 'refunded', 'refund_amount' => '267.83', 'total_amount' => '267.83' ) ) );
LNKup_Organizer_Wallet::insert_entry( array( 'wallet_id' => (int) LNKup_Organizer_Wallet::get_or_create( $organizer )['id'], 'user_id' => $organizer, 'entry_type' => 'credit', 'amount' => 230, 'source_type' => 'payment', 'payment_post_id' => $p_ref ) );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Treasury_Tables::organizer_wallets_table() . ' SET available_balance = available_balance + 230 WHERE user_id = %d', $organizer ) );

$dry2  = LNKup_Financial_Repair::dry_run( $organizer, false );
$types = wp_list_pluck( $dry2['items'], 'type', 'id' );
lnkup_t_same( 'void_duplicate_escrow_holds', $types[ 'escrow:' . $p_esc ] ?? null, 'plan voids the duplicate escrow hold' );
lnkup_t_same( 'reverse_refund_share', $types[ 'refund:' . $p_ref ] ?? null, 'plan reverses the missed refund share' );
$res2 = LNKup_Financial_Repair::apply( $organizer, $dry2['confirmation_token'] );
lnkup_t_assert( ! is_wp_error( $res2 ), 'second repair run applies' );
lnkup_t_same( array( 'held', 'void' ), $wpdb->get_col( $wpdb->prepare( "SELECT status FROM {$escrow} WHERE payment_post_id = %d ORDER BY id", $p_esc ) ), 'first hold kept, duplicate voided (not deleted)' );
lnkup_t_same( 23000, (int) round( (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$entries} WHERE payment_post_id = %d AND entry_type = 'debit' AND source_type LIKE 'refund%%'", $p_ref ) ) * 100 ), 'refund share reversed once' );
$wpdb->delete( $escrow, array( 'payment_post_id' => $p_esc ) );
wp_set_current_user( 0 );

$wpdb->delete( LNKup_Financial_Tables::audit_table(), array( 'action' => 'reconciliation_repair_run', 'entity_id' => $organizer ) );
update_option( LNKup_Financial_Repair::RUNS_OPTION, array_slice( (array) get_option( LNKup_Financial_Repair::RUNS_OPTION, array() ), 1 ), false );
lnkup_t_finish();
