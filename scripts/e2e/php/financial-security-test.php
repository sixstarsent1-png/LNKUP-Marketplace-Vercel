<?php
/**
 * Cross-user isolation on money endpoints: another organizer or vendor cannot read or act on
 * someone else's payments, ledger, sync, or the admin reconciliation tools.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/financial-security-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

echo "Financial isolation\n";

$org_a    = lnkup_t_user( 'lnkup_organizer' );
$org_b    = lnkup_t_user( 'lnkup_organizer' );
$vendor_a = lnkup_t_user( 'lnkup_vendor' );
$vendor_b = lnkup_t_user( 'lnkup_vendor' );

$payment = lnkup_t_payment(
	array(
		'organizer_user_id'     => $org_a,
		'vendor_user_id'        => $vendor_a,
		'total_amount'          => '230.00',
		'payment_status'        => 'paid',
		'paid_at'               => current_time( 'mysql' ),
		'stripe_payment_intent' => 'sim_isolation',
	)
);
lnkup_t_ledger_row( $org_a, array( 'amount' => 230, 'payment_post_id' => $payment, 'source_id' => $payment ) );

/**
 * Status code for a REST call as the given user.
 *
 * @param int    $user   User ID.
 * @param string $method Method.
 * @param string $route  Route.
 * @param array  $params Params.
 */
function lnkup_t_status_as( $user, $method, $route, $params = array() ) {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$res = rest_do_request( $req );
	wp_set_current_user( 0 );
	return $res;
}

lnkup_t_same( 403, lnkup_t_status_as( $org_b, 'GET', "/lnkup/v1/financial/payments/{$payment}/audit" )->get_status(), 'another organizer cannot read the payment audit' );
lnkup_t_same( 403, lnkup_t_status_as( $vendor_b, 'GET', "/lnkup/v1/financial/payments/{$payment}/audit" )->get_status(), 'another vendor cannot read the payment audit' );
lnkup_t_same( 403, lnkup_t_status_as( $org_b, 'POST', "/lnkup/v1/financial/payments/{$payment}/reconcile" )->get_status(), 'another organizer cannot reconcile the payment' );
lnkup_t_same( 403, lnkup_t_status_as( $vendor_b, 'GET', "/lnkup/v1/financial/checkout/{$payment}/summary" )->get_status(), 'another vendor cannot open the checkout summary' );
lnkup_t_same( 200, lnkup_t_status_as( $org_a, 'GET', "/lnkup/v1/financial/payments/{$payment}/audit" )->get_status(), 'owning organizer can read the payment audit' );

$entries_b = lnkup_t_status_as( $org_b, 'GET', '/lnkup/v1/financial/organizer-wallet/entries' )->get_data();
lnkup_t_same( array(), $entries_b, 'another organizer sees none of the ledger rows' );

$tx_b = lnkup_t_status_as( $org_b, 'GET', '/lnkup/v1/financial/transactions', array( 'role' => 'organizer' ) )->get_data();
lnkup_t_assert( ! in_array( $payment, wp_list_pluck( (array) $tx_b, 'payment_post_id' ), true ), 'another organizer does not see the transaction' );

$money_b = lnkup_t_status_as( $org_b, 'GET', '/lnkup/v1/financial/organizer-balances' )->get_data();
lnkup_t_same( 0, $money_b['money']['metrics']['collected']['cents'] ?? null, "another organizer's contract excludes the payment" );

lnkup_t_same( 403, lnkup_t_status_as( $vendor_a, 'GET', '/lnkup/v1/financial/organizer-wallet' )->get_status(), 'vendor cannot read organizer wallet' );
lnkup_t_same( 401, lnkup_t_status_as( 0, 'POST', '/lnkup/v1/financial/sync' )->get_status(), 'anonymous cannot trigger sync' );
lnkup_t_same( 403, lnkup_t_status_as( $org_a, 'GET', '/lnkup/v1/admin/financial/repair-runs' )->get_status(), 'organizer cannot list repair runs' );
lnkup_t_same( 403, lnkup_t_status_as( $org_a, 'GET', '/lnkup/v1/admin/financial/reconcile', array( 'organizer_id' => $org_a ) )->get_status(), 'organizer cannot read admin reconciliation' );

lnkup_t_finish();
