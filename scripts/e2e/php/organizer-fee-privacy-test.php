<?php
/**
 * Organizers never see LNKup platform fee amounts: money is reported after fees and fee fields are
 * stripped from organizer /financial/* responses. Admin and vendor views keep them.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/organizer-fee-privacy-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Organizer fee privacy\n";

$org    = lnkup_t_user( 'lnkup_organizer' );
$vendor = lnkup_t_user( 'lnkup_vendor' );
$admin  = lnkup_t_user( 'administrator' );

$event_id = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => 'Fee Privacy Fest', 'post_author' => $org ) );
update_post_meta( $event_id, '_lnkup_organizer_user_id', $org );

$payment_id = lnkup_t_payment(
	array(
		'organizer_user_id' => $org,
		'vendor_user_id'    => $vendor,
		'event_id'          => $event_id,
		'total_amount'      => 100,
		'payment_status'    => 'paid',
		'lnkup_fee'         => 6,
		'paid_at'           => current_time( 'mysql' ),
	)
);

$tx_table = LNKup_Financial_Tables::transactions_table();
$wpdb->insert(
	$tx_table,
	array(
		'public_id'         => 'txfp' . wp_generate_password( 8, false ),
		'type'              => 'payment',
		'status'            => 'paid',
		'amount'            => 100,
		'platform_fee'      => 6,
		'vendor_user_id'    => $vendor,
		'organizer_user_id' => $org,
		'event_id'          => $event_id,
		'payment_post_id'   => $payment_id,
		'metadata'          => wp_json_encode( array( 'platform_fee' => 6, 'note' => 'kept' ) ),
		'created_at'        => current_time( 'mysql' ),
	)
);
$tx_id = (int) $wpdb->insert_id;

$hidden = LNKup_Organizer_Fee_Privacy::HIDDEN_KEYS;

$find_hidden = static function ( $data, $path = '' ) use ( &$find_hidden, $hidden ) {
	$found = array();
	if ( ! is_array( $data ) ) {
		return $found;
	}
	foreach ( $data as $key => $value ) {
		if ( is_string( $key ) && in_array( $key, $hidden, true ) ) {
			$found[] = $path . '.' . $key;
		}
		if ( is_array( $value ) ) {
			if ( isset( $value['key'] ) && in_array( $value['key'], array( 'gross', 'fees' ), true ) ) {
				$found[] = $path . '.' . $key . '[key=' . $value['key'] . ']';
			}
			$found = array_merge( $found, $find_hidden( $value, $path . '.' . $key ) );
		}
	}
	return $found;
};

$get = static function ( $user, $route, $query = array() ) {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( 'GET', '/lnkup/v1' . $route );
	foreach ( $query as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$res = rest_do_request( $req );
	return array( $res->get_status(), rest_get_server()->response_to_data( $res, false ) );
};

// --------------------------------------------------------------- Organizer.
echo "\n[organizer responses]\n";
$routes = array(
	array( '/financial/dashboard', array( 'role' => 'organizer' ) ),
	array( '/financial/organizer-balances', array() ),
	array( '/financial/events/' . $event_id . '/financials', array() ),
	array( '/financial/transactions', array( 'role' => 'organizer' ) ),
	array( '/financial/reports/organizer', array( 'role' => 'organizer' ) ),
);
$payloads = array();
foreach ( $routes as $route ) {
	list( $status, $data ) = $get( $org, $route[0], $route[1] );
	$payloads[ $route[0] ] = $data;
	lnkup_t_assert( 200 === $status || 404 === $status, "{$route[0]} answers ({$status})" );
	$leaks = $find_hidden( $data );
	lnkup_t_same( array(), array_slice( $leaks, 0, 5 ), "{$route[0]} has no fee fields" );
}

$dash = $payloads['/financial/dashboard'];
$keys = array_column( (array) ( $dash['money_categories'] ?? array() ), 'key' );
lnkup_t_assert( ! in_array( 'gross', $keys, true ) && ! in_array( 'fees', $keys, true ), 'money categories have no Gross collected or Platform fees' );
$net = array_values( array_filter( (array) ( $dash['money_categories'] ?? array() ), static function ( $row ) {
	return 'net' === ( $row['key'] ?? '' );
} ) );
lnkup_t_same( 'Earnings', $net[0]['label'] ?? '', 'net category is labelled Earnings' );
lnkup_t_same( 94.0, (float) ( $net[0]['amount'] ?? 0 ), 'earnings are after the $6 platform fee' );
lnkup_t_same( 9400, (int) ( $dash['money']['metrics']['collected']['cents'] ?? 0 ), 'metrics.collected is after fees' );
lnkup_t_same( 9400, (int) ( $dash['money']['metrics']['net_earnings']['cents'] ?? 0 ), 'metrics.net_earnings is after fees' );
lnkup_t_same( 94.0, (float) ( $dash['gross_revenue'] ?? 0 ), 'dashboard totals are after fees' );

$event = $payloads[ '/financial/events/' . $event_id . '/financials' ];
lnkup_t_same( 94.0, (float) ( $event['collected_revenue'] ?? 0 ), 'per-event collected is after fees' );
$timeline = (array) ( $event['timeline'] ?? array() );
lnkup_t_assert( ! empty( $timeline ), 'event timeline still lists the payment' );
lnkup_t_same( 'kept', $timeline[0]['metadata']['note'] ?? '', 'non-fee metadata is kept' );

$balances = $payloads['/financial/organizer-balances'];
lnkup_t_same( 9400, (int) ( $balances['money']['metrics']['collected']['cents'] ?? 0 ), 'organizer-balances money is after fees' );

// --------------------------------------------------------------- Admin and vendor.
echo "\n[admin and vendor]\n";
lnkup_t_assert( ! LNKup_Organizer_Fee_Privacy::is_organizer_request( ( static function () use ( $admin ) {
	wp_set_current_user( $admin );
	return new WP_REST_Request( 'GET', '/lnkup/v1/financial/dashboard' );
} )() ), 'admins are not filtered' );
wp_set_current_user( $admin );
$raw = LNKup_Financial_Reports::organizer_report( $org );
lnkup_t_same( 6.0, (float) ( $raw['platform_fees'] ?? 0 ), 'the raw report (tax, admin) still has platform fees' );
lnkup_t_same( 100.0, (float) ( $raw['gross_revenue'] ?? 0 ), 'the raw report keeps gross' );
$audit = LNKup_Payments::get_audit( $payment_id );
lnkup_t_assert( array_key_exists( 'platform_fee', (array) $audit ), 'admin payment audit keeps the fee breakdown' );

wp_set_current_user( $vendor );
$vendor_req = new WP_REST_Request( 'GET', '/lnkup/v1/financial/dashboard' );
$vendor_req->set_param( 'role', 'vendor' );
lnkup_t_assert( ! LNKup_Organizer_Fee_Privacy::is_organizer_request( $vendor_req ), 'vendor requests are not filtered' );

// --------------------------------------------------------------- Cleanup.
wp_set_current_user( 0 );
$wpdb->delete( $tx_table, array( 'id' => $tx_id ) );
wp_delete_post( $event_id, true );
lnkup_t_finish();
