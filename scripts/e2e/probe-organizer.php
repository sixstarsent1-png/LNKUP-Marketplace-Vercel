<?php
/**
 * Organizer security probes (run before and after Sprint B fixes).
 *
 * Usage: npx wp-env run cli wp eval-file /var/www/html/wp-content/lnkup-e2e/probe-organizer.php
 * Prints one line per probe: PASS/FAIL, expected vs actual status.
 */

$env = array();
foreach ( file( __DIR__ . '/.env.e2e', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	list( $k, $v ) = array_pad( explode( '=', $line, 2 ), 2, '' );
	$env[ $k ] = $v;
}

$uid = static function ( $login ) {
	$user = get_user_by( 'login', $login );
	return $user ? (int) $user->ID : 0;
};

$event_a   = (int) $env['PLAYWRIGHT_ORGANIZER_EVENT_ID'];
$event_b   = (int) $env['PLAYWRIGHT_ORGANIZER_B_EVENT_ID'];
$pay_a     = (int) $env['PLAYWRIGHT_VENDOR_PENDING_PAYMENT_ID'];
$opp_food  = (int) $env['PLAYWRIGHT_CAPACITY_OPPORTUNITY_ID'];
$org_a     = $uid( 'e2e_org_a' );
$org_b     = $uid( 'e2e_org_b' );
$vendor_b  = $uid( 'e2e_vendor_b' );
$security  = $uid( 'e2e_staff_security' );
$ops       = $uid( 'e2e_staff_operations' );
$finance   = $uid( 'e2e_staff_finance' );

/**
 * Dispatch a REST request as a user and return the HTTP status.
 */
$call = static function ( $user_id, $method, $route, $body = array(), $query = array() ) {
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( $method, $route );
	if ( $body ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	foreach ( $query as $k => $v ) {
		$request->set_param( $k, $v );
	}
	$response = rest_do_request( $request );
	wp_set_current_user( 0 );
	return array( (int) $response->get_status(), $response->get_data() );
};

$results = array();
$probe   = static function ( $name, $expect_denied, $status ) use ( &$results ) {
	$denied    = $status >= 400;
	$ok        = $expect_denied ? $denied : ! $denied;
	$results[] = $ok;
	printf( "%s  %-72s expected=%s actual=%d\n", $ok ? 'PASS' : 'FAIL', $name, $expect_denied ? 'denied' : 'allowed', $status );
};

list( $s ) = $call( $org_b, 'GET', '/lnkup/v1/operations/support', array(), array( 'event_id' => $event_a ) );
$probe( 'org B reads org A support tickets', true, $s );

list( $s ) = $call( $org_a, 'GET', '/lnkup/v1/operations/support', array(), array( 'event_id' => $event_a ) );
$probe( 'org A reads own support tickets', false, $s );

list( $s ) = $call( $security, 'GET', "/lnkup/v1/financial/events/{$event_a}/financials" );
$probe( 'security staff reads event financials', true, $s );

list( $s ) = $call( $ops, 'GET', "/lnkup/v1/financial/events/{$event_a}/financials" );
$probe( 'operations staff reads event financials', true, $s );

if ( $finance && LNKup_Event_Staff::is_staff( $event_a, $finance ) ) {
	list( $s ) = $call( $finance, 'GET', "/lnkup/v1/financial/events/{$event_a}/financials" );
	$probe( 'finance staff reads event financials', false, $s );
}

list( $s ) = $call( $org_a, 'GET', "/lnkup/v1/financial/events/{$event_a}/financials" );
$probe( 'owner reads event financials', false, $s );

list( $s ) = $call( $security, 'POST', "/lnkup/v1/operations/events/{$event_a}/staff", array( 'user_id' => $org_b, 'role' => 'operations' ) );
$probe( 'security staff adds staff', true, $s );
LNKup_Event_Staff::remove( $event_a, $org_b );

list( $s ) = $call( $security, 'GET', "/lnkup/v1/crm/events/{$event_a}/roster" );
$probe( 'security staff reads roster', false, $s );

list( $s ) = $call( $org_b, 'POST', '/lnkup/v1/financial/refunds/calculate', array( 'payment_id' => $pay_a ) );
$probe( 'org B calculates refund on org A payment', true, $s );

list( $s, $data ) = $call( $org_b, 'POST', '/lnkup/v1/crm/invites', array( 'vendor_user_id' => $vendor_b, 'event_id' => $event_a, 'opportunity_id' => $opp_food ) );
$probe( 'org B invites vendor into org A opportunity', true, $s );
if ( is_array( $data ) && ! empty( $data['invite_id'] ) ) {
	wp_delete_post( (int) $data['invite_id'], true );
}

list( $s, $first ) = $call( $org_a, 'POST', '/lnkup/v1/crm/invites', array( 'vendor_user_id' => $vendor_b, 'event_id' => $event_a, 'opportunity_id' => $opp_food ) );
list( $s2, $second ) = $call( $org_a, 'POST', '/lnkup/v1/crm/invites', array( 'vendor_user_id' => $vendor_b, 'event_id' => $event_a, 'opportunity_id' => $opp_food ) );
$probe( 'duplicate active invite is refused', true, $s2 );
foreach ( array( $first, $second ) as $d ) {
	if ( is_array( $d ) && ! empty( $d['invite_id'] ) ) {
		wp_delete_post( (int) $d['invite_id'], true );
	}
}

$holds = 0;
foreach ( get_posts( array( 'post_type' => 'lnkup_application', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_lnkup_opportunity_id', 'meta_value' => $opp_food ) ) as $app_id ) {
	if ( in_array( get_post_meta( $app_id, '_lnkup_application_status', true ), array( 'approved', 'payment_required', 'paid' ), true ) ) {
		++$holds;
	}
}
$total     = (int) get_post_meta( $opp_food, '_lnkup_spots_total', true );
$remaining = (int) get_post_meta( $opp_food, '_lnkup_spots_remaining', true );
$ok        = $remaining === max( 0, $total - $holds );
$results[] = $ok;
printf( "%s  %-72s total=%d holds=%d remaining=%d\n", $ok ? 'PASS' : 'FAIL', 'spots_remaining reflects approved/paid holds', $total, $holds, $remaining );

update_post_meta( $opp_food, '_lnkup_spots_total', max( 1, $holds ) );
$temp_app = wp_insert_post( array( 'post_type' => 'lnkup_application', 'post_status' => 'publish', 'post_title' => 'E2E overbook probe' ) );
update_post_meta( $temp_app, '_lnkup_opportunity_id', $opp_food );
update_post_meta( $temp_app, '_lnkup_event_id', $event_a );
update_post_meta( $temp_app, '_lnkup_organizer_user_id', $org_a );
update_post_meta( $temp_app, '_lnkup_vendor_user_id', $vendor_b );
update_post_meta( $temp_app, '_lnkup_application_status', 'under_review' );
$over = LNKup_Applications::transition( $temp_app, 'approved' );
$ok   = is_wp_error( $over ) && 'opportunity_full' === $over->get_error_code() && 'under_review' === get_post_meta( $temp_app, '_lnkup_application_status', true ) && ! LNKup_Payments::get_for_application( $temp_app );
$results[] = $ok;
printf( "%s  %-72s result=%s\n", $ok ? 'PASS' : 'FAIL', 'approve on a full slot is refused without creating a payment', is_wp_error( $over ) ? $over->get_error_code() : wp_json_encode( $over ) );
$leaked = LNKup_Payments::get_for_application( $temp_app );
if ( $leaked ) {
	wp_delete_post( $leaked->ID, true );
}
wp_delete_post( $temp_app, true );
update_post_meta( $opp_food, '_lnkup_spots_total', $total );
LNKup_Opportunity_Inventory::recompute( $opp_food );

$check = static function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %-72s %s\n", $ok ? 'PASS' : 'FAIL', $name, $detail );
};
$code = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : wp_json_encode( $r );
};

wp_set_current_user( $org_a );
$pay = wp_insert_post( array( 'post_type' => 'lnkup_payment', 'post_status' => 'publish', 'post_title' => 'E2E refund probe' ) );
foreach ( array(
	'_lnkup_total_amount'      => 100,
	'_lnkup_payment_status'    => 'paid',
	'_lnkup_vendor_user_id'    => $vendor_b,
	'_lnkup_organizer_user_id' => $org_a,
	'_lnkup_event_id'          => $event_a,
) as $k => $v ) {
	update_post_meta( $pay, $k, $v );
}
$r1 = LNKup_Refunds::request( $pay, 30, 'probe', 'partial' );
$r2 = LNKup_Refunds::request( $pay, 30, 'probe', 'partial' );
$check( 'second open refund request on a payment is refused', is_wp_error( $r2 ) && 'refund_pending' === $r2->get_error_code(), $code( $r2 ) );
$a1 = LNKup_Refunds::approve( (int) $r1 );
$a2 = LNKup_Refunds::approve( (int) $r1 );
$check( 'double approve issues one refund', true === $a1 && is_wp_error( $a2 ) && 'refund_not_pending' === $a2->get_error_code(), $code( $a1 ) . ' / ' . $code( $a2 ) );
$check( 'partial refund marks payment partially_refunded', 'partially_refunded' === get_post_meta( $pay, '_lnkup_payment_status', true ) && 30.0 === (float) get_post_meta( $pay, '_lnkup_refund_amount', true ), get_post_meta( $pay, '_lnkup_payment_status', true ) . ' refunded=' . get_post_meta( $pay, '_lnkup_refund_amount', true ) );
$bad = LNKup_Refunds::request( $pay, 10, 'probe', 'chargeback' );
$check( 'unknown refund type is refused', is_wp_error( $bad ) && 'invalid_refund_type' === $bad->get_error_code(), $code( $bad ) );
$rc = LNKup_Refunds::request( $pay, 20, 'probe', 'credit' );
LNKup_Refunds::approve( (int) $rc );
$check( 'credit refund grants credit without a card refund', 20.0 === LNKup_Refunds::credited_amount( $pay ) && 30.0 === (float) get_post_meta( $pay, '_lnkup_refund_amount', true ), 'credited=' . LNKup_Refunds::credited_amount( $pay ) );
$rf = LNKup_Refunds::request( $pay, 0, 'probe', 'full' );
global $wpdb;
$rf_amount = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT amount FROM ' . LNKup_Financial_Tables::refunds_table() . ' WHERE id = %d', (int) $rf ) );
LNKup_Refunds::approve( (int) $rf );
$check( 'full refund is capped at the remaining balance', 50.0 === $rf_amount && 'refunded' === get_post_meta( $pay, '_lnkup_payment_status', true ), 'amount=' . $rf_amount . ' status=' . get_post_meta( $pay, '_lnkup_payment_status', true ) );
$rx = LNKup_Refunds::request( $pay, 5, 'probe', 'partial' );
$check( 'refund on a fully refunded payment is refused', is_wp_error( $rx ), $code( $rx ) );
$wpdb->delete( LNKup_Financial_Tables::refunds_table(), array( 'payment_post_id' => $pay ) );
$wpdb->delete( LNKup_Financial_Tables::credits_table(), array( 'source' => 'refund', 'user_id' => $vendor_b ) );
wp_delete_post( $pay, true );

$inv = LNKup_Vendor_Invites::create( $org_a, $vendor_b, array( 'event_id' => $event_a, 'opportunity_id' => $opp_food ) );
$tok = is_array( $inv ) ? $inv['token'] : '';
wp_set_current_user( $org_b );
$wrong = LNKup_Vendor_Invites::respond( $tok, true );
$check( 'invite respond from a different account is refused', is_wp_error( $wrong ) && 'invite_wrong_account' === $wrong->get_error_code(), $code( $wrong ) );
wp_set_current_user( $vendor_b );
$ok_resp  = LNKup_Vendor_Invites::respond( $tok, false );
$again    = LNKup_Vendor_Invites::respond( $tok, true );
$check( 'invited vendor responds once; second response refused', is_array( $ok_resp ) && is_wp_error( $again ) && 'invite_closed' === $again->get_error_code(), $code( $again ) );
wp_set_current_user( $org_a );
if ( is_array( $inv ) ) {
	wp_delete_post( (int) $inv['invite_id'], true );
}
$not_vendor = LNKup_Vendor_Invites::create( $org_a, $org_b, array( 'event_id' => $event_a ) );
$check( 'invite to a non-vendor account is refused', is_wp_error( $not_vendor ) && 'invalid_vendor' === $not_vendor->get_error_code(), $code( $not_vendor ) );

$token_in = array( 'event_name' => 'E2E idempotency probe', 'client_token' => 'probe-' . wp_generate_password( 8, false ) );
$ev1      = LNKup_Organizer_Onboarding::create_event_from_request( $token_in );
$ev2      = LNKup_Organizer_Onboarding::create_event_from_request( $token_in );
$check( 'Create Event replay with the same client_token returns the same event', is_array( $ev1 ) && is_array( $ev2 ) && $ev1['event_id'] === $ev2['event_id'] && ! empty( $ev2['idempotent'] ), ( $ev1['event_id'] ?? 0 ) . ' / ' . ( $ev2['event_id'] ?? 0 ) );
if ( is_array( $ev1 ) ) {
	wp_delete_post( (int) $ev1['event_id'], true );
}

$vendor_profile = (int) get_posts( array( 'post_type' => 'lnkup_vendor_profile', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_lnkup_owner_user_id', 'meta_value' => $vendor_b ) )[0];
$biz            = (string) get_post_meta( $vendor_profile, '_lnkup_business_name', true );
$found          = lnkup_get_discoverable_vendors( array( 'q' => $biz ) );
$check( 'discover q matches business name', in_array( $vendor_profile, wp_list_pluck( $found['posts'], 'ID' ), true ), 'q=' . $biz . ' total=' . $found['total'] );
wp_update_post( array( 'ID' => $vendor_profile, 'post_status' => 'private' ) );
$hidden = lnkup_get_discoverable_vendors( array( 'q' => $biz ) );
$check( 'discover excludes private profiles', ! in_array( $vendor_profile, wp_list_pluck( $hidden['posts'], 'ID' ), true ), 'total=' . $hidden['total'] );
wp_update_post( array( 'ID' => $vendor_profile, 'post_status' => 'publish' ) );

$old_venue = (string) get_post_meta( $event_a, '_lnkup_venue_name', true );
$edit      = LNKup_Organizer_Onboarding::update_event_from_request( $event_a, array( 'venue_name' => 'E2E Moved Venue' ), $org_a );
$same      = LNKup_Organizer_Onboarding::update_event_from_request( $event_a, array( 'venue_name' => 'E2E Moved Venue' ), $org_a );
LNKup_Organizer_Onboarding::update_event_from_request( $event_a, array( 'venue_name' => $old_venue ), $org_a );
$check( 'venue change notifies booked vendors; unchanged save does not', is_array( $edit ) && $edit['vendors_notified'] > 0 && is_array( $same ) && 0 === $same['vendors_notified'], 'notified=' . ( $edit['vendors_notified'] ?? 'err' ) . ' repeat=' . ( $same['vendors_notified'] ?? 'err' ) );

$review_app = (int) $env['PLAYWRIGHT_REVIEW_APPLICATION_ID'];
$prior      = (string) get_post_meta( $review_app, '_lnkup_application_status', true );
$prior_hist = get_post_meta( $review_app, LNKup_Applications::INFO_HISTORY_META, true );
update_post_meta( $review_app, '_lnkup_application_status', 'under_review' );
$review_vendor = (int) get_post_meta( $review_app, '_lnkup_vendor_user_id', true );
$foreign_req   = LNKup_Applications::request_info( $review_app, 'Do you have insurance?', array(), $org_b );
$check( 'another organizer cannot request info', is_wp_error( $foreign_req ) && 'forbidden' === $foreign_req->get_error_code(), $code( $foreign_req ) );
$req = LNKup_Applications::request_info( $review_app, 'Please send your insurance certificate.', array( 'Insurance certificate' ), $org_a );
$check( 'request info moves the application to info_requested', is_array( $req ) && 'info_requested' === get_post_meta( $review_app, '_lnkup_application_status', true ), (string) get_post_meta( $review_app, '_lnkup_application_status', true ) );
$wrong_vendor = LNKup_Applications::respond_info( $review_app, 'Here it is', $vendor_b === $review_vendor ? $uid( 'e2e_vendor_a' ) : $vendor_b );
$check( 'a different vendor cannot answer', is_wp_error( $wrong_vendor ), $code( $wrong_vendor ) );
$resp = LNKup_Applications::respond_info( $review_app, 'Attached to my documents.', $review_vendor );
$again = LNKup_Applications::respond_info( $review_app, 'Again', $review_vendor );
$hist  = LNKup_Applications::info_history( $review_app );
$check( 'vendor reply returns to review; history keeps both; no double reply', is_array( $resp ) && 'under_review' === get_post_meta( $review_app, '_lnkup_application_status', true ) && is_wp_error( $again ) && count( $hist ) >= 2, 'history=' . count( $hist ) . ' again=' . $code( $again ) );
update_post_meta( $review_app, '_lnkup_application_status', $prior );
if ( is_array( $prior_hist ) ) {
	update_post_meta( $review_app, LNKup_Applications::INFO_HISTORY_META, $prior_hist );
} else {
	delete_post_meta( $review_app, LNKup_Applications::INFO_HISTORY_META );
}

global $wpdb;
$booths_table = LNKup_Operations_Tables::booths_table();
$wpdb->insert( $booths_table, array( 'event_id' => $event_a, 'booth_number' => 'E2E-P1', 'booth_type' => 'standard', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$b1 = (int) $wpdb->insert_id;
$wpdb->insert( $booths_table, array( 'event_id' => $event_a, 'booth_number' => 'E2E-P2', 'booth_type' => 'standard', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$b2 = (int) $wpdb->insert_id;
$booked_app = (int) get_posts( array( 'post_type' => 'lnkup_application', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_lnkup_event_id', 'value' => (string) $event_a ), array( 'key' => '_lnkup_application_status', 'value' => array( 'payment_required', 'paid' ), 'compare' => 'IN' ) ) ) )[0];
$prior_booth = (string) get_post_meta( $booked_app, '_lnkup_booth_placeholder', true );
$a1 = LNKup_Booths::assign( $b1, $booked_app, $event_a );
$a2 = LNKup_Booths::assign( $b2, $booked_app, $event_a );
$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$booths_table} WHERE event_id = %d AND application_id = %d", $event_a, $booked_app ) );
$check( 'moving a vendor to another booth frees the old one', true === $a1 && true === $a2 && 1 === $held, 'held=' . $held );
$review_status = (string) get_post_meta( $review_app, '_lnkup_application_status', true );
$unbooked = LNKup_Booths::assign( $b1, $review_app, $event_a );
$check( 'unapproved vendor cannot get a booth', is_wp_error( $unbooked ) && 'not_booked' === $unbooked->get_error_code(), $code( $unbooked ) . ' (' . $review_status . ')' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$booths_table} WHERE id IN (%d, %d)", $b1, $b2 ) );
update_post_meta( $booked_app, '_lnkup_booth_placeholder', $prior_booth );

$dash = LNKup_Financial_Service::organizer_dashboard( $org_a );
$keys = wp_list_pluck( (array) ( $dash['money_categories'] ?? array() ), 'key' );
$check( 'financial center reports every money category', array( 'gross', 'fees', 'refunds', 'net', 'pending', 'available', 'in_transit', 'paid_out' ) === $keys, implode( ',', $keys ) );

$unpaid_app = (int) get_posts( array( 'post_type' => 'lnkup_application', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_lnkup_event_id', 'value' => (string) $event_a ), array( 'key' => '_lnkup_application_status', 'value' => 'payment_required' ) ) ) )[0];
list( $s, $d ) = $call( $org_a, 'PUT', "/lnkup/v1/operations/events/{$event_a}/vendors/{$unpaid_app}/status", array( 'status' => 'paid' ) );
$probe( 'event ops cannot set a booking to paid', true, $s );
list( $s ) = $call( $org_a, 'PUT', "/lnkup/v1/operations/events/{$event_a}/vendors/{$unpaid_app}/status", array( 'status' => 'checked_in' ) );
$probe( 'check-in of an unpaid vendor is gated', true, $s );
list( $s, $d ) = $call( $security, 'PUT', "/lnkup/v1/operations/events/{$event_a}/vendors/{$unpaid_app}/status", array( 'status' => 'checked_in', 'override' => true, 'reason' => 'Paid cash at the gate' ) );
$probe( 'security staff cannot override check-in gates', true, $s );
list( $s ) = $call( $org_a, 'PUT', "/lnkup/v1/operations/events/{$event_a}/vendors/{$unpaid_app}/status", array( 'status' => 'checked_in', 'override' => true, 'reason' => 'ok' ) );
$probe( 'override without a real reason is refused', true, $s );
list( $s, $d ) = $call( $org_a, 'PUT', "/lnkup/v1/operations/events/{$event_a}/vendors/{$unpaid_app}/status", array( 'status' => 'checked_in', 'override' => true, 'reason' => 'Paid cash at the gate' ) );
$logged = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Operations_Tables::log_table() . " WHERE event_id = %d AND application_id = %d AND activity_type = 'manual_override'", $event_a, $unpaid_app ) );
$probe( 'owner override checks in and is audited', false, $s );
$check( 'override written to the operations log', $logged > 0, 'rows=' . $logged );
list( $s2, $d2 ) = $call( $org_a, 'PUT', "/lnkup/v1/operations/events/{$event_a}/vendors/{$unpaid_app}/status", array( 'status' => 'checked_in', 'override' => true, 'reason' => 'Paid cash at the gate' ) );
$check( 'second check-in reports already checked in', 200 === $s2 && is_array( $d2 ) && 'already_checked_in' === ( $d2['reason'] ?? '' ), (string) ( $d2['reason'] ?? $s2 ) );
delete_post_meta( $unpaid_app, '_lnkup_checked_in_at' );
delete_post_meta( $unpaid_app, '_lnkup_ops_status' );
$wpdb->delete( LNKup_Operations_Tables::checkins_table(), array( 'application_id' => $unpaid_app ) );

$dup_opps   = get_posts( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_lnkup_event_id', 'meta_value' => $event_a ) );
$closed_opp = (int) $dup_opps[0];
$prior_opp  = (string) get_post_meta( $closed_opp, '_lnkup_opportunity_status', true );
update_post_meta( $closed_opp, '_lnkup_opportunity_status', 'closed' );
$dup_token = 'dup-' . wp_generate_password( 8, false );
$dup1      = LNKup_Organizer_Onboarding::duplicate_event( $event_a, array( 'event_date' => '2027-09-01', 'client_token' => $dup_token ), $org_a );
$dup2      = LNKup_Organizer_Onboarding::duplicate_event( $event_a, array( 'event_date' => '2027-09-01', 'client_token' => $dup_token ), $org_a );
update_post_meta( $closed_opp, '_lnkup_opportunity_status', $prior_opp );
$copied_apps = is_array( $dup1 ) ? count( get_posts( array( 'post_type' => array( 'lnkup_application', 'lnkup_payment', 'lnkup_packet' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_lnkup_event_id', 'meta_value' => $dup1['event_id'] ) ) ) : -1;
$closed_copy = 0;
foreach ( (array) ( $dup1['opportunity_ids'] ?? array() ) as $copy_id ) {
	if ( 'closed' === get_post_meta( $copy_id, '_lnkup_opportunity_status', true ) && get_the_title( $copy_id ) === get_the_title( $closed_opp ) ) {
		++$closed_copy;
	}
}
$check( 'duplicate event replay returns the same copy', is_array( $dup1 ) && is_array( $dup2 ) && $dup1['event_id'] === $dup2['event_id'] && ! empty( $dup2['idempotent'] ), ( $dup1['event_id'] ?? 0 ) . ' / ' . ( $dup2['event_id'] ?? 0 ) );
$check( 'duplicate keeps closed slots closed and copies no bookings', 1 === $closed_copy && 0 === $copied_apps, 'closed=' . $closed_copy . ' bookings=' . $copied_apps );
if ( is_array( $dup1 ) ) {
	foreach ( (array) $dup1['opportunity_ids'] as $copy_id ) {
		wp_delete_post( (int) $copy_id, true );
	}
	wp_delete_post( (int) $dup1['event_id'], true );
}

$funnel = LNKup_Organizer_Dashboard_Views::intel_funnel( $org_a );
$funnel_b = LNKup_Organizer_Dashboard_Views::intel_funnel( $org_b );
$a_events = wp_list_pluck( $funnel['events'], 'event_id' );
$check( 'intelligence funnel is monotonic and owner-scoped', $funnel['totals']['applications'] >= $funnel['totals']['approvals'] && $funnel['totals']['approvals'] >= $funnel['totals']['confirmed'] && ! in_array( $event_b, $a_events, true ) && ! in_array( $event_a, wp_list_pluck( $funnel_b['events'], 'event_id' ), true ), wp_json_encode( $funnel['totals'] ) );

$payouts_table = LNKup_Treasury_Tables::organizer_payouts_table();
$replay_token  = 'probe-' . wp_generate_password( 10, false );
$wpdb->insert( $payouts_table, array( 'user_id' => $org_a, 'amount' => 12, 'fee_amount' => 0, 'net_amount' => 12, 'payout_type' => 'standard', 'status' => 'requested', 'provider' => 'manual', 'metadata' => wp_json_encode( array( 'client_token' => $replay_token ) ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$seeded_payout = (int) $wpdb->insert_id;
$before_rows   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$payouts_table} WHERE user_id = %d", $org_a ) );
$replayed      = LNKup_Organizer_Payouts::request_cashout( $org_a, 12, 'standard', $replay_token );
$after_rows    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$payouts_table} WHERE user_id = %d", $org_a ) );
$check( 'cash out replay with the same token returns the original payout', $seeded_payout === $replayed && $before_rows === $after_rows, 'payout=' . $code( $replayed ) . ' rows ' . $before_rows . '→' . $after_rows );
$wpdb->delete( $payouts_table, array( 'id' => $seeded_payout ) );

$co = LNKup_Organizer_Payouts::request_cashout( $org_a, 10, 'overnight_magic' );
$check( 'cashout with unknown type is refused', is_wp_error( $co ) && 'invalid_payout_type' === $co->get_error_code(), $code( $co ) );
wp_set_current_user( 0 );

$passed = count( array_filter( $results ) );
printf( "\n%d/%d probes passed\n", $passed, count( $results ) );
