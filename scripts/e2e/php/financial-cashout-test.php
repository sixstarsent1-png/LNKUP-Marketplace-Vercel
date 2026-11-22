<?php
/**
 * Connect cashouts and payouts: platform claim accounting, owed-to-organizers split, payouts
 * paid after failure, restore bucket by provider, payout webhook ownership, debit failure stops
 * the cashout. No Stripe calls.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/financial-cashout-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Cashouts and payouts\n";

$entries  = LNKup_Treasury_Tables::organizer_wallet_entries_table();
$payouts  = LNKup_Treasury_Tables::organizer_payouts_table();
$accounts = LNKup_Financial_Tables::connected_accounts_table();
$org      = lnkup_t_user( 'lnkup_organizer' );
$wallet   = LNKup_Organizer_Wallet::get_or_create( $org );

/**
 * Insert a payout row.
 *
 * @param int    $org      Organizer.
 * @param float  $amount   Amount.
 * @param string $status   Status.
 * @param string $provider Provider.
 */
function lnkup_t_payout( $org, $amount, $status, $provider ) {
	global $wpdb;
	$wpdb->insert( LNKup_Treasury_Tables::organizer_payouts_table(), array( 'user_id' => $org, 'amount' => $amount, 'net_amount' => $amount, 'status' => $status, 'provider' => $provider, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	return (int) $wpdb->insert_id;
}

// 1. Platform claim: migrations count; cashout debits and restores do not; recovery transfers do.
LNKup_Organizer_Wallet::insert_entry( array( 'wallet_id' => (int) $wallet['id'], 'user_id' => $org, 'entry_type' => 'connect_settlement', 'amount' => 100, 'source_type' => 'treasury_migration', 'metadata' => wp_json_encode( array( 'migrated_from_treasury' => true ) ) ) );
lnkup_t_same( 100.0, LNKup_Organizer_Wallet::platform_claim( $org ), 'migrated treasury balance is platform-held' );
LNKup_Organizer_Wallet::insert_entry( array( 'wallet_id' => (int) $wallet['id'], 'user_id' => $org, 'entry_type' => 'debit', 'amount' => 40, 'source_type' => 'payout', 'payout_id' => 999001 ) );
lnkup_t_same( 100.0, LNKup_Organizer_Wallet::platform_claim( $org ), 'a cashout debit alone does not reduce platform-held money (fixes debit-before-check)' );
LNKup_Organizer_Wallet::insert_entry( array( 'wallet_id' => (int) $wallet['id'], 'user_id' => $org, 'entry_type' => 'connect_settlement', 'amount' => 40, 'source_type' => 'payout_failed', 'payout_id' => 999001 ) );
lnkup_t_same( 100.0, LNKup_Organizer_Wallet::platform_claim( $org ), 'a failed-payout restore (money back on Connect) is not platform-held' );
LNKup_Organizer_Wallet::insert_entry( array( 'wallet_id' => (int) $wallet['id'], 'user_id' => $org, 'entry_type' => 'debit', 'amount' => 60, 'source_type' => 'recovery_transfer', 'idempotency_key' => 'recovery:tr_test_1' ) );
lnkup_t_same( 40.0, LNKup_Organizer_Wallet::platform_claim( $org ), 'recovery transfer reduces the claim' );
lnkup_t_same( 0, LNKup_Organizer_Wallet::insert_entry( array( 'wallet_id' => (int) $wallet['id'], 'user_id' => $org, 'entry_type' => 'debit', 'amount' => 60, 'source_type' => 'recovery_transfer', 'idempotency_key' => 'recovery:tr_test_1' ) ), 'same transfer cannot be ledgered twice' );

// 2. Owed to organizers counts every wallet bucket and in-flight treasury cashouts.
$org2 = lnkup_t_user( 'lnkup_organizer' );
LNKup_Organizer_Wallet::add_entry( $org2, 30, 'available', array( 'source_type' => 'test' ) );
LNKup_Organizer_Wallet::add_entry( $org2, 20, 'pending', array( 'source_type' => 'test' ) );
$manual_inflight = lnkup_t_payout( $org2, 15, 'approved', 'manual' );
$connect_inflight = lnkup_t_payout( $org2, 99, 'processing', 'stripe_connect_payout' );
$owed = array();
foreach ( LNKup_Stripe_Connect::organizer_owed_breakdown() as $row ) {
	$owed[ $row['user_id'] ] = $row;
}
lnkup_t_same( 6500, $owed[ $org2 ]['owed_cents'] ?? null, 'owed = available + pending + in-flight manual cashout (Connect payouts excluded)' );
lnkup_t_same( 4000, $owed[ $org ]['owed_cents'] ?? null, 'owed includes the platform claim of a Connect organizer' );

// 3. A payout reported paid after it was failed and restored re-debits once.
$p_fail = lnkup_t_payout( $org2, 25, 'processing', 'manual' );
LNKup_Organizer_Wallet::debit( $org2, 25, array( 'source_type' => 'payout', 'source_id' => $p_fail, 'payout_id' => $p_fail ) );
LNKup_Organizer_Payouts::handle_async_failure( $p_fail, 'Bank rejected' );
$after_restore = (float) LNKup_Organizer_Wallet::get_row( $org2 )['available_balance'];
lnkup_t_same( true, LNKup_Organizer_Payouts::mark_paid( $p_fail, 'po_test_late' ), 'paid-after-failure accepted' );
lnkup_t_same( 'paid', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$payouts} WHERE id = %d", $p_fail ) ), 'payout recorded as paid' );
lnkup_t_same( round( $after_restore - 25, 2 ), (float) LNKup_Organizer_Wallet::get_row( $org2 )['available_balance'], 'restored balance taken back (no double payout)' );
LNKup_Organizer_Payouts::mark_paid( $p_fail, 'po_test_late' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$entries} WHERE idempotency_key = %s", 'payout:' . $p_fail . ':redebit' ) ), 're-debit posted once' );
lnkup_t_same( false, LNKup_Organizer_Payouts::mark_paid( lnkup_t_payout( $org2, 5, 'denied', 'manual' ) ), 'unknown statuses cannot be marked paid' );

// 4. Restore bucket follows the payout provider, not today's Connect state.
$wpdb->insert( $accounts, array( 'user_id' => $org2, 'role_type' => 'organizer', 'stripe_account_id' => 'acct_test_owner', 'payouts_enabled' => 1, 'charges_enabled' => 1, 'details_submitted' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$settings = get_option( 'lnkup_settings', array() );
update_option( 'lnkup_settings', array_merge( (array) $settings, array( 'stripe_connect_enabled' => true ) ) );
$p_manual = lnkup_t_payout( $org2, 10, 'processing', 'manual' );
$avail    = (float) LNKup_Organizer_Wallet::get_row( $org2 )['available_balance'];
LNKup_Organizer_Payouts::handle_async_failure( $p_manual, 'failed' );
lnkup_t_same( round( $avail + 10, 2 ), (float) LNKup_Organizer_Wallet::get_row( $org2 )['available_balance'], 'manual payout restores to treasury even after Connect became ready' );
update_option( 'lnkup_settings', $settings );
$p_conn = lnkup_t_payout( $org2, 12, 'processing', 'stripe_connect_payout' );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Treasury_Tables::organizer_wallets_table() . ' SET processing_balance = 12 WHERE user_id = %d', $org2 ) );
LNKup_Organizer_Payouts::handle_async_failure( $p_conn, 'failed' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$entries} WHERE payout_id = %d AND entry_type = 'connect_settlement' AND source_type = 'payout_failed'", $p_conn ) ), 'Connect payout restores to the Connect ledger even after Connect was paused' );
lnkup_t_same( 0.0, (float) LNKup_Organizer_Wallet::get_row( $org2 )['processing_balance'], 'Connect payout restore releases processing' );

// 5. Payout webhooks only count from the owner's connected account.
$p_hook = lnkup_t_payout( $org2, 8, 'processing', 'stripe_connect_payout' );
$event  = array( 'id' => 'po_test_hook', 'status' => 'paid', 'metadata' => array( 'payout_id' => (string) $p_hook ) );
LNKup_Stripe_Connect::handle_payout_event( $event, 'payout.paid', 'acct_attacker' );
lnkup_t_same( 'processing', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$payouts} WHERE id = %d", $p_hook ) ), 'payout.paid from another account is ignored' );
LNKup_Stripe_Connect::handle_payout_event( $event, 'payout.paid', '' );
lnkup_t_same( 'processing', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$payouts} WHERE id = %d", $p_hook ) ), 'payout.paid without an account is ignored' );
LNKup_Stripe_Connect::handle_payout_event( $event, 'payout.paid', 'acct_test_owner' );
lnkup_t_same( 'paid', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$payouts} WHERE id = %d", $p_hook ) ), 'payout.paid from the owner account marks it paid' );
$p_manual_hook = lnkup_t_payout( $org2, 8, 'approved', 'manual' );
LNKup_Stripe_Connect::handle_payout_event( array( 'id' => 'po_x', 'status' => 'paid', 'metadata' => array( 'payout_id' => (string) $p_manual_hook ) ), 'payout.paid', 'acct_test_owner' );
lnkup_t_same( 'approved', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$payouts} WHERE id = %d", $p_manual_hook ) ), 'a Stripe payout cannot complete a manual LNKup payout' );

// 6. Cashout stops when the ledger debit cannot be written.
$org3 = lnkup_t_user( 'lnkup_organizer' );
LNKup_Organizer_Wallet::add_entry( $org3, 50, 'available', array( 'source_type' => 'test' ) );
$next_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $payouts ) );
$w3      = LNKup_Organizer_Wallet::get_or_create( $org3 );
LNKup_Organizer_Wallet::insert_entry( array( 'wallet_id' => (int) $w3['id'], 'user_id' => $org3, 'entry_type' => 'note', 'amount' => 0, 'source_type' => 'test_block', 'idempotency_key' => 'payout:' . $next_id . ':debit' ) );
wp_set_current_user( $org3 );
$res = LNKup_Organizer_Payouts::request_cashout( $org3, 20, 'standard' );
wp_set_current_user( 0 );
lnkup_t_assert( is_wp_error( $res ), 'cashout fails when its ledger debit is refused' );
lnkup_t_same( 50.0, (float) LNKup_Organizer_Wallet::get_row( $org3 )['available_balance'], 'balance untouched' );
lnkup_t_same( 'failed', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$payouts} WHERE id = %d", $next_id ) ), 'payout row marked failed, nothing sent' );

$wpdb->query( $wpdb->prepare( "DELETE FROM {$payouts} WHERE user_id IN (%d, %d, %d)", $org, $org2, $org3 ) );
$wpdb->delete( $accounts, array( 'user_id' => $org2 ) );
lnkup_t_finish();
