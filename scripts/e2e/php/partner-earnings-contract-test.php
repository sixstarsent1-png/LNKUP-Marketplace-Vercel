<?php
/**
 * Partner earnings contract: balances cover every commission (not only the 50 newest), each
 * state lands in its own bucket at its net amount, estimated stays separate from payable money,
 * the response carries the fields the partner app reads, and payout eligibility uses approved
 * commissions only.
 *
 * Run: npx -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/partner-earnings-contract-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Partner earnings contract\n";

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$rewards    = LNKup_Treasury_Tables::referral_rewards_table();
$codes      = LNKup_Treasury_Tables::referral_codes_table();
$connectors = LNKup_Connector_Tables::connectors_table();
$orig       = get_option( 'lnkup_settings' );
$settings   = (array) $orig;
$settings['connector_rewards_enabled'] = true;
update_option( 'lnkup_settings', $settings );

$made = array();

/**
 * Active City Connector with a code. Returns [connector_id, user_id].
 */
$make_connector = static function () use ( $wpdb, $connectors, &$made ): array {
	$user = lnkup_t_user( 'lnkup_connector' );
	$now  = current_time( 'mysql' );
	$wpdb->insert( $connectors, array( 'user_id' => $user, 'display_name' => 'Contract Connector ' . $user, 'email' => 'pc' . $user . '@example.test', 'city' => 'Charlotte', 'state' => 'NC', 'market_slug' => 'charlotte', 'status' => 'active', 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
	$cid    = (int) $wpdb->insert_id;
	$made[] = $cid;
	LNKup_Connector::generate_code( $cid );
	return array( $cid, $user );
};

/**
 * Insert one commission row.
 */
$reward = static function ( int $cid, float $amount, string $status, array $extra = array() ) use ( $wpdb, $rewards ): int {
	$wpdb->insert(
		$rewards,
		array_merge(
			array(
				'connector_id'  => $cid,
				'event_type'    => 'connector_booking_fee',
				'amount'        => $amount,
				'reward_status' => $status,
				'status'        => $status,
				'program'       => 'city_connector',
				'created_at'    => current_time( 'mysql' ),
				'available_at'  => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS ),
			),
			$extra
		)
	);
	return (int) $wpdb->insert_id;
};

list( $cid, $user ) = $make_connector();
$old = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 90 * DAY_IN_SECONDS );
for ( $i = 0; $i < 60; $i++ ) {
	$reward( $cid, 1.0, 'approved', array( 'created_at' => $old ) );
}
$reward( $cid, 10.0, 'pending', array( 'available_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 10 * DAY_IN_SECONDS ) ) );
$reward( $cid, 20.0, 'pending' );
$reward( $cid, 5.0, 'on_hold' );
$reward( $cid, 40.0, 'paid', array( 'paid_at' => current_time( 'mysql' ) ) );
$reward( $cid, 15.0, 'reversed' );
$partial = $reward( $cid, 30.0, 'approved', array( 'reversed_amount' => 10.0 ) );

$expected = array( 'pending_hold' => 10.0, 'awaiting_approval' => 20.0, 'on_hold' => 5.0, 'approved' => 80.0, 'paid' => 40.0, 'reversed' => 25.0 );

$balances = LNKup_Connector_Rewards::balances_for_connector( $cid );
foreach ( $expected as $key => $amount ) {
	lnkup_t_same( $amount, (float) $balances[ $key ], "balance {$key} covers all 66 commissions" );
}
$counts = LNKup_Connector_Rewards::count_by_connector( $cid );
lnkup_t_same( 61, (int) $counts['approved'], 'approved count covers rows beyond the newest 50' );
lnkup_t_same( 2, (int) $counts['pending'], 'pending count (hold + awaiting approval)' );

wp_set_current_user( $user );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/connector/earnings' ) );
lnkup_t_same( 200, $res->get_status(), 'connector reads earnings' );
$earn = (array) $res->get_data();
foreach ( $expected as $key => $amount ) {
	lnkup_t_same( $amount, (float) ( $earn['balances'][ $key ] ?? -1 ), "earnings REST {$key}" );
}
lnkup_t_assert( array_key_exists( 'estimated', (array) $earn['balances'] ), 'estimated is returned as its own field' );
lnkup_t_assert( isset( $earn['terms'], $earn['next_payout'] ), 'terms and next payout returned' );
$ledger = (array) ( $earn['ledger'] ?? array() );
lnkup_t_assert( count( $ledger ) <= 50 && count( $ledger ) > 0, 'ledger list stays capped' );
$fields_ok = true;
foreach ( $ledger as $row ) {
	foreach ( array( 'id', 'event_type', 'net_amount', 'display_status', 'related_name', 'created_at', 'available_at', 'paid_at' ) as $field ) {
		if ( ! array_key_exists( $field, (array) $row ) ) {
			$fields_ok = false;
		}
	}
}
lnkup_t_assert( $fields_ok, 'every ledger row has the fields the partner app reads' );
$statuses = array_unique( array_column( $ledger, 'display_status' ) );
foreach ( array( 'pending_hold', 'awaiting_approval', 'on_hold', 'paid', 'reversed', 'approved' ) as $status ) {
	lnkup_t_assert( in_array( $status, $statuses, true ), "ledger shows {$status}" );
}
$partial_row = current( array_filter( $ledger, static fn( $r ) => (int) $r['id'] === $partial ) );
lnkup_t_same( 20.0, (float) ( $partial_row['net_amount'] ?? 0 ), 'partially reversed commission shows its net amount' );
lnkup_t_same( 'approved', $partial_row['display_status'] ?? '', 'and keeps its approved status' );

$res  = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/connector/dashboard' ) );
$dash = (array) $res->get_data();
lnkup_t_same( 200, $res->get_status(), 'connector reads the dashboard' );
lnkup_t_same( 80.0, (float) ( $dash['earnings']['approved'] ?? -1 ), 'dashboard approved matches earnings' );
lnkup_t_same( 10.0, (float) ( $dash['earnings']['pending_hold'] ?? -1 ), 'dashboard pending hold matches earnings' );
lnkup_t_assert( array_key_exists( 'estimated', (array) ( $dash['earnings'] ?? array() ) ), 'dashboard estimate is separate' );

/**
 * Find the payout_eligible launch step anywhere in the onboarding payload.
 */
$find_step = static function ( $node ) use ( &$find_step ) {
	if ( is_array( $node ) ) {
		if ( ( $node['id'] ?? '' ) === 'payout_eligible' ) {
			return $node;
		}
		foreach ( $node as $child ) {
			$hit = $find_step( $child );
			if ( null !== $hit ) {
				return $hit;
			}
		}
	}
	return null;
};
$min = (float) LNKup_Connector_Rewards::terms()['minimum_payout'];
$step = $find_step( LNKup_Connector::get_onboarding( $cid ) );
lnkup_t_assert( null !== $step, 'launch plan has a payout step' );
lnkup_t_same( 80.0 >= $min, (bool) ( $step['done'] ?? false ), 'payout step reflects approved commissions' );

list( $cid2 ) = $make_connector();
$reward( $cid2, max( 100.0, $min * 3 ), 'pending', array( 'available_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 10 * DAY_IN_SECONDS ) ) );
$reward( $cid2, max( 100.0, $min * 3 ), 'pending' );
$step2 = $find_step( LNKup_Connector::get_onboarding( $cid2 ) );
lnkup_t_same( false, (bool) ( $step2['done'] ?? true ), 'held and unapproved commissions do not make a partner payout-eligible' );

$res  = rest_do_request( ( static function () { $r = new WP_REST_Request( 'GET', '/lnkup/v1/connector/earnings' ); $r->set_param( 'page', 2 ); return $r; } )() );
$pg2  = (array) $res->get_data();
lnkup_t_same( 66, (int) ( $earn['ledger_total'] ?? 0 ), 'ledger total counts every commission' );
lnkup_t_same( true, (bool) ( $earn['ledger_has_more'] ?? false ), 'first page says older commissions exist' );
lnkup_t_same( 16, count( (array) ( $pg2['ledger'] ?? array() ) ), 'second page has the remaining 16' );
lnkup_t_same( false, (bool) ( $pg2['ledger_has_more'] ?? true ), 'second page is the last' );
lnkup_t_same( array(), array_intersect( array_column( $ledger, 'id' ), array_column( (array) $pg2['ledger'], 'id' ) ), 'pages do not overlap' );
lnkup_t_same( $earn['balances'], $pg2['balances'], 'balances do not change with the page' );
lnkup_t_same( $earn['next_payout'], $pg2['next_payout'], 'next payout does not change with the page' );

wp_set_current_user( lnkup_t_user( 'lnkup_vendor' ) );
lnkup_t_same( 403, rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/connector/earnings' ) )->get_status(), 'vendors cannot read connector earnings' );

echo "\n[tour attribution]\n";
$posts     = array();
$tour      = wp_insert_post( array( 'post_type' => 'lnkup_tour', 'post_status' => 'publish', 'post_title' => 'Contract Tour' ) );
$stop_a    = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => 'Contract Stop A' ) );
$stop_b    = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => 'Contract Stop B' ) );
$elsewhere = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => 'Contract Other Event' ) );
update_post_meta( $stop_a, '_lnkup_tour_id', $tour );
update_post_meta( $stop_b, '_lnkup_tour_id', $tour );
array_push( $posts, $tour, $stop_a, $stop_b, $elsewhere );
$payment = static function ( int $event, string $status, float $booth ) use ( &$posts ): int {
	$id = wp_insert_post( array( 'post_type' => 'lnkup_payment', 'post_status' => 'publish', 'post_title' => 'Contract Payment' ) );
	update_post_meta( $id, '_lnkup_event_id', $event );
	update_post_meta( $id, '_lnkup_payment_status', $status );
	update_post_meta( $id, '_lnkup_vendor_spot_fee', $booth );
	$posts[] = $id;
	return $id;
};
$p1 = $payment( $stop_a, 'paid', 200.0 );
$p2 = $payment( $stop_a, 'paid', 150.0 );
$p3 = $payment( $stop_b, 'refunded', 100.0 );
$p4 = $payment( $stop_b, 'pending', 90.0 );
$p5 = $payment( $elsewhere, 'paid', 80.0 );
list( $cid3 ) = $make_connector();
$credit_user  = lnkup_t_user( 'lnkup_vendor' );
$reward( $cid3, 20.0, 'approved', array( 'payment_id' => $p1, 'event_id' => $stop_a ) );
$reward( 0, 5.0, 'paid', array( 'payment_id' => $p1, 'event_id' => $stop_a, 'user_id' => $credit_user, 'program' => 'vendor_referral', 'event_type' => 'vendor_referral_credit' ) );
$reward( $cid3, 10.0, 'reversed', array( 'payment_id' => $p3, 'event_id' => $stop_b ) );
$reward( $cid3, 9.0, 'pending', array( 'payment_id' => $p4, 'event_id' => $stop_b ) );
$reward( $cid3, 8.0, 'approved', array( 'payment_id' => $p5, 'event_id' => $elsewhere ) );

$admin = lnkup_t_user( 'administrator' );
wp_set_current_user( $admin );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/partners/tours/' . $tour . '/attribution' ) );
lnkup_t_same( 200, $res->get_status(), 'admin reads tour attribution' );
$att = (array) $res->get_data();
lnkup_t_same( 3, (int) $att['totals']['paid_bookings'], 'paid bookings at the stops (refunded still counts, pending and other events do not)' );
lnkup_t_same( 2, (int) $att['totals']['attributed_bookings'], 'a booking with two commission rows counts once' );
lnkup_t_same( 0.6667, (float) $att['totals']['attributed_share'], 'attributed share of paid bookings' );
lnkup_t_same( 300.0, (float) $att['totals']['attributed_booth_gmv'], 'attributed booth value counts each payment once' );
lnkup_t_same( 25.0, (float) $att['totals']['commission_net'], 'net commission excludes the reversed row' );
$by_stop = array_column( (array) $att['stops'], null, 'event_id' );
lnkup_t_same( 2, (int) $by_stop[ $stop_a ]['paid_bookings'], 'stop A paid bookings' );
lnkup_t_same( 1, (int) $by_stop[ $stop_a ]['attributed_bookings'], 'stop A attributed bookings' );
lnkup_t_same( 1, (int) $by_stop[ $stop_b ]['attributed_bookings'], 'stop B attributed bookings' );
$connector_row = current( array_filter( (array) $att['partners'], static fn( $p ) => (int) $p['connector_id'] === $cid3 ) );
lnkup_t_same( 2, (int) ( $connector_row['bookings'] ?? 0 ), 'connector drove two tour bookings' );
lnkup_t_same( 20.0, (float) ( $connector_row['commission']['approved'] ?? 0 ), 'connector approved commission' );
lnkup_t_same( 10.0, (float) ( $connector_row['commission']['reversed'] ?? 0 ), 'connector reversed commission after the refund' );
lnkup_t_same( 0.0, (float) ( $connector_row['commission']['pending'] ?? -1 ), 'unpaid booking commissions are left out' );
lnkup_t_same( 2, count( (array) $att['partners'] ), 'connector and vendor credit listed separately' );

wp_set_current_user( lnkup_t_user( 'lnkup_organizer' ) );
lnkup_t_same( 403, rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/partners/tours/' . $tour . '/attribution' ) )->get_status(), 'organizers cannot read partner attribution' );
wp_set_current_user( $admin );
lnkup_t_same( 404, rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/partners/tours/' . $stop_a . '/attribution' ) )->get_status(), 'an event ID is not a tour' );

$wpdb->query( 'DELETE FROM ' . $rewards . ' WHERE payment_id IN (' . implode( ',', array( $p1, $p2, $p3, $p4, $p5 ) ) . ')' );
foreach ( $posts as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

foreach ( $made as $id ) {
	$wpdb->delete( $rewards, array( 'connector_id' => $id ) );
	$wpdb->delete( $codes, array( 'connector_id' => $id ) );
	$wpdb->delete( LNKup_Connector_Tables::events_table(), array( 'connector_id' => $id ) );
	$wpdb->delete( $connectors, array( 'id' => $id ) );
}
update_option( 'lnkup_settings', $orig );
// phpcs:enable

lnkup_t_finish();
