<?php
/**
 * Money contract v1: integer cents, sources, unavailable-vs-zero, payout gating, and the
 * contract appearing on every organizer money endpoint (web + mobile read the same block).
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/financial-contract-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Money contract v1\n";

$organizer = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );

lnkup_t_payment( array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'payment_status' => 'paid', 'total_amount' => '230.00', 'paid_at' => current_time( 'mysql' ) ) );
lnkup_t_payment( array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'payment_status' => 'paid', 'total_amount' => '19.99', 'paid_at' => current_time( 'mysql' ) ) );
lnkup_t_payment( array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'payment_status' => 'pending', 'total_amount' => '50.00' ) );
LNKup_Organizer_Wallet::add_entry( $organizer, 120.5, 'available', array( 'source_type' => 'test_seed' ) );

// Platform treasury organizer (no Connect account).
$money = LNKup_Financial_Service::organizer_money_contract( $organizer );
lnkup_t_same( 1, $money['version'], 'contract version is 1' );
lnkup_t_same( 24999, $money['metrics']['collected']['cents'], 'collected is the sum of paid payments in cents' );
lnkup_t_same( 'wordpress_payments', $money['metrics']['collected']['source'], 'collected is sourced from WordPress payments' );
lnkup_t_same( 12050, $money['metrics']['available']['cents'], 'treasury available comes from the ledger in cents' );
lnkup_t_same( 'ledger', $money['metrics']['available']['source'], 'treasury available source is ledger' );
lnkup_t_same( 'manual', $money['payouts']['state'], 'marketplace without Connect pays organizers through LNKup' );
lnkup_t_same( true, $money['payouts']['can_cash_out'], 'treasury balance can be cashed out (mirrors server cashout rule)' );
lnkup_t_same( 'lnkup', $money['payouts']['method'], 'payout method is LNKup' );

$settings_before = get_option( 'lnkup_settings', array() );
update_option( 'lnkup_settings', array_merge( (array) $settings_before, array( 'stripe_connect_enabled' => true, 'stripe_secret_key' => 'sk_test_placeholder_not_called' ) ) );
$not_started = LNKup_Financial_Service::organizer_money_contract( $organizer );
update_option( 'lnkup_settings', $settings_before );
lnkup_t_same( 'not_connected', $not_started['payouts']['state'], 'Connect enabled but not set up shows Set up payouts' );
lnkup_t_same( true, $not_started['payouts']['can_cash_out'], 'platform-held balance still cashes out before Connect setup' );

$empty_org   = lnkup_t_user( 'lnkup_organizer' );
$empty_money = LNKup_Financial_Service::organizer_money_contract( $empty_org );
lnkup_t_same( false, $empty_money['payouts']['can_cash_out'], 'nothing to cash out with a zero balance' );
lnkup_t_same( 'no_available_balance', $empty_money['payouts']['cash_out_reason'], 'zero balance reason is no_available_balance' );
foreach ( $money['metrics'] as $key => $m ) {
	lnkup_t_assert( is_int( $m['cents'] ) || null === $m['cents'], "metric {$key} is integer cents or null" );
}

// Parity: the shared fixture that web and mobile tests consume matches what the server emits.
$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/money-contract-v1.json' ), true );
lnkup_t_same( array_keys( $fixture ), array_keys( $money ), 'contract top-level keys match the shared parity fixture' );
lnkup_t_same( array_keys( $fixture['metrics'] ), array_keys( $money['metrics'] ), 'contract metric keys match the shared parity fixture' );
lnkup_t_same( array_keys( $fixture['payouts'] ), array_keys( $money['payouts'] ), 'payout keys match the shared parity fixture' );
lnkup_t_same( array_keys( $fixture['metrics']['available'] ), array_keys( $money['metrics']['available'] ), 'metric shape matches the shared parity fixture' );

// Destination organizer whose Stripe balance cannot be read: unavailable, never $0.
$settings = get_option( 'lnkup_settings', array() );
update_option( 'lnkup_settings', array_merge( (array) $settings, array( 'stripe_connect_enabled' => true ) ) );
$wpdb->insert(
	LNKup_Financial_Tables::connected_accounts_table(),
	array(
		'user_id'           => $organizer,
		'role_type'         => 'organizer',
		'stripe_account_id' => 'acct_test_unreachable',
		'payouts_enabled'   => 1,
		'charges_enabled'   => 1,
		'details_submitted' => 1,
		'created_at'        => current_time( 'mysql' ),
		'updated_at'        => current_time( 'mysql' ),
	)
);
$dest = LNKup_Financial_Service::organizer_money_contract( $organizer );
lnkup_t_same( 'destination', $dest['settlement_mode'], 'Connect-ready organizer settles by destination' );
lnkup_t_same( 'unavailable', $dest['metrics']['available']['status'], 'unreadable Stripe available is unavailable' );
lnkup_t_same( null, $dest['metrics']['available']['cents'], 'unavailable available has null cents, not 0' );
lnkup_t_same( null, $dest['metrics']['pending']['cents'], 'unavailable pending has null cents, not 0' );
lnkup_t_same( 'unavailable', $dest['metrics']['paid_out']['status'], 'unreadable Stripe payouts are unavailable' );
lnkup_t_same( 'active', $dest['payouts']['state'], 'ready account shows payouts active' );
lnkup_t_same( false, $dest['payouts']['can_cash_out'], 'cash out disabled while balance is unavailable' );
lnkup_t_same( 'balance_unavailable', $dest['payouts']['cash_out_reason'], 'cash out reason is balance_unavailable' );
lnkup_t_same( 24999, $dest['metrics']['collected']['cents'], 'collected unchanged by settlement mode' );

wp_set_current_user( $organizer );
foreach ( array( '/lnkup/v1/financial/dashboard', '/lnkup/v1/financial/organizer-wallet', '/lnkup/v1/financial/organizer-balances' ) as $route ) {
	$req = new WP_REST_Request( 'GET', $route );
	$req->set_param( 'role', 'organizer' );
	$data = rest_do_request( $req )->get_data();
	lnkup_t_same( 1, $data['money']['version'] ?? null, "{$route} includes money contract" );
	lnkup_t_same( $dest['metrics']['collected']['cents'], $data['money']['metrics']['collected']['cents'] ?? null, "{$route} collected matches contract" );
	$available = $data['money']['metrics']['available'] ?? array();
	lnkup_t_assert( array_key_exists( 'cents', $available ) && null === $available['cents'] && 'unavailable' === $available['status'], "{$route} keeps unavailable as null over REST" );
}

wp_set_current_user( $vendor );
$req = new WP_REST_Request( 'GET', '/lnkup/v1/financial/dashboard' );
$req->set_param( 'role', 'vendor' );
$vendor_money = rest_do_request( $req )->get_data()['money'] ?? array();
lnkup_t_same( 24999, $vendor_money['metrics']['paid']['cents'] ?? null, 'vendor paid total in cents' );
lnkup_t_same( 5000, $vendor_money['metrics']['outstanding']['cents'] ?? null, 'vendor outstanding total in cents' );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/financial/organizer-balances' ) );
lnkup_t_same( 403, $res->get_status(), 'vendor cannot read organizer balances' );
wp_set_current_user( 0 );

$wpdb->delete( LNKup_Financial_Tables::connected_accounts_table(), array( 'user_id' => $organizer ) );
update_option( 'lnkup_settings', $settings );
delete_transient( 'lnkup_connect_payouts_' . $organizer );

lnkup_t_finish();
