<?php
/**
 * Settlement side effects found in production: repeated settlement must not multiply platform
 * fee / reserve / escrow rows; reserves on destination charges must never credit the wallet;
 * destination ledger mirrors must never count as platform money owed on Cash Out.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/financial-side-effects-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Settlement side effects\n";

$organizer = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );
$base      = array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'vendor_spot_fee' => '230.00', 'total_amount' => '267.83', 'payment_status' => 'paid', 'paid_at' => current_time( 'mysql' ) );

$settings = get_option( 'lnkup_settings', array() );
update_option( 'lnkup_settings', array_merge( (array) $settings, array( 'reserve_percent' => 5, 'escrow_enabled' => true ) ) );

/**
 * Rows in a treasury table for a payment.
 *
 * @param string $table Table.
 * @param int    $pid   Payment.
 */
function lnkup_t_rows_for( $table, $pid ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE payment_post_id = %d", $pid ) );
}

$dest = lnkup_t_payment( array_merge( $base, array( 'stripe_destination_account' => 'acct_test_dest', 'stripe_charge_amount_cents' => 26783, 'application_fee_cents' => 2999 ) ) );
$plat = lnkup_t_payment( $base );

for ( $i = 0; $i < 3; $i++ ) {
	LNKup_Financial_Service::settle_treasury( $dest );
	LNKup_Financial_Service::settle_treasury( $plat );
}

lnkup_t_same( 1, lnkup_t_rows_for( LNKup_Treasury_Tables::platform_fees_table(), $dest ), 'repeated settlement keeps one platform fee row' );
lnkup_t_same( 0, lnkup_t_rows_for( LNKup_Treasury_Tables::reserve_holds_table(), $dest ), 'destination charge gets no reserve hold' );
lnkup_t_same( 1, lnkup_t_rows_for( LNKup_Treasury_Tables::reserve_holds_table(), $plat ), 'platform charge gets exactly one reserve hold' );
lnkup_t_same( 1, lnkup_t_rows_for( LNKup_Treasury_Tables::escrow_holds_table(), $plat ), 'platform charge gets exactly one escrow hold' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Treasury_Tables::organizer_wallet_entries_table() . " WHERE payment_post_id = %d AND entry_type = 'connect_settlement'", $dest ) ), 'destination mirror recorded once' );

lnkup_t_same( 0.0, LNKup_Organizer_Wallet::platform_claim( $organizer ), 'destination mirror is not platform money owed' );
lnkup_t_same( 0, LNKup_Stripe_Connect::organizer_ledger_claim_cents( $organizer ), 'recovery claim excludes destination mirrors' );
lnkup_t_assert( LNKup_Organizer_Wallet::sum_connect_settlement( $organizer ) > 0, 'LNKup settled still shows the mirror for audit' );

// Legacy duplicates: extra reserve holds on both charge types, all expired.
$reserves = LNKup_Treasury_Tables::reserve_holds_table();
foreach ( array( $dest, $dest, $plat ) as $pid ) {
	$wpdb->insert( $reserves, array( 'organizer_user_id' => $organizer, 'payment_post_id' => $pid, 'amount' => 11.5, 'status' => 'active', 'expires_at' => '2026-01-01 00:00:00', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
}
$wpdb->query( $wpdb->prepare( "UPDATE {$reserves} SET expires_at = '2026-01-01 00:00:00' WHERE payment_post_id IN (%d, %d)", $dest, $plat ) );
$before = (float) LNKup_Organizer_Wallet::get_row( $organizer )['available_balance'];
LNKup_Reserves::release_expired();
LNKup_Reserves::release_expired();
$after = (float) LNKup_Organizer_Wallet::get_row( $organizer )['available_balance'];
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Treasury_Tables::organizer_wallet_entries_table() . " WHERE payment_post_id = %d AND source_type = 'reserve_release'", $plat ) ), 'platform payment reserve released once despite duplicate holds' );
lnkup_t_same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Treasury_Tables::organizer_wallet_entries_table() . " WHERE payment_post_id = %d AND source_type = 'reserve_release'", $dest ) ), 'destination reserves never credit the wallet' );
lnkup_t_assert( $after - $before > 0 && $after - $before < 25, 'wallet grew by one platform reserve only' );
lnkup_t_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reserves} WHERE payment_post_id IN (%d, %d) AND status = 'active'", $dest, $plat ) ), 'all expired holds are closed' );

update_option( 'lnkup_settings', $settings );
foreach ( array( LNKup_Treasury_Tables::platform_fees_table(), $reserves, LNKup_Treasury_Tables::escrow_holds_table() ) as $table ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE payment_post_id IN (%d, %d)", $dest, $plat ) );
}
lnkup_t_finish();
