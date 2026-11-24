<?php
/**
 * Vendor invitations: scheduled expiry, vendor listing, respond by ID, ownership and REST access.
 *
 * @package LNKup_Marketplace
 */

require_once __DIR__ . '/_comms_helpers.php';

$organizer = lnkup_ct_user( 'lnkup_organizer' );
$vendor    = lnkup_ct_user( 'lnkup_vendor' );
$stranger  = lnkup_ct_user( 'lnkup_vendor' );
lnkup_ct_profile( $vendor );
lnkup_ct_profile( $stranger );

$fired = array();
add_action(
	'lnkup_vendor_invite_status_changed',
	static function ( $invite_id, $a = '', $b = null ) use ( &$fired ) {
		$fired[] = array( (int) $invite_id, null !== $b ? (string) $b : (string) $a );
	},
	10,
	3
);

/**
 * Create a silent invite to a fresh event.
 */
function lnkup_ie_invite( int $organizer, int $vendor ): int {
	$event  = lnkup_ct_event( $organizer );
	$result = LNKup_Vendor_Invites::create( $organizer, $vendor, array( 'event_id' => $event['event'], 'opportunity_id' => $event['opp'], 'silent' => true ) );
	if ( is_wp_error( $result ) ) {
		echo '  (invite failed: ' . esc_html( $result->get_error_message() ) . ")\n";
		return 0;
	}
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $result['invite_id'] );
	return (int) $result['invite_id'];
}

echo "Scheduled expiry\n";
$overdue = lnkup_ie_invite( $organizer, $vendor );
$fresh   = lnkup_ie_invite( $organizer, $vendor );
lnkup_t_assert( $overdue > 0 && $fresh > 0, 'two invites created' );
update_post_meta( $overdue, '_lnkup_expires_at', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
lnkup_t_assert( (bool) wp_next_scheduled( LNKup_Vendor_Invites::EXPIRY_CRON ), 'expiry sweep is scheduled' );
$fired = array();
$count = LNKup_Vendor_Invites::expire_overdue();
lnkup_t_assert( $count >= 1, 'sweep expired at least one invite' );
lnkup_t_same( 'expired', get_post_meta( $overdue, '_lnkup_status', true ), 'overdue invite is now expired' );
lnkup_t_same( 'sent', get_post_meta( $fresh, '_lnkup_status', true ), 'unexpired invite untouched' );
lnkup_t_assert( in_array( array( $overdue, 'expired' ), $fired, true ), 'status-changed hook fired with the new status' );
lnkup_t_same( 0, LNKup_Vendor_Invites::expire_overdue() >= 1 && 'expired' !== get_post_meta( $fresh, '_lnkup_status', true ) ? 1 : 0, 'second sweep does not touch open invites' );

echo "Vendor listing\n";
$open_ids = wp_list_pluck( LNKup_Vendor_Invites::get_for_vendor( $vendor, 'open' ), 'id' );
lnkup_t_assert( in_array( $fresh, $open_ids, true ) && ! in_array( $overdue, $open_ids, true ), 'open scope lists only open invites' );
$all = LNKup_Vendor_Invites::get_for_vendor( $vendor, 'all' );
lnkup_t_assert( in_array( $overdue, wp_list_pluck( $all, 'id' ), true ), 'all scope includes expired invites' );
$row = current( array_filter( $all, static function ( $i ) use ( $fresh ) { return $i['id'] === $fresh; } ) );
lnkup_t_assert( is_array( $row ) && true === $row['can_respond'] && '' !== $row['event_title'], 'invite rows carry event details and can_respond' );
lnkup_t_assert( ! isset( $row['token'] ), 'invite token is never exposed in the vendor list' );
lnkup_t_same( array(), LNKup_Vendor_Invites::get_for_vendor( $stranger, 'all' ), 'another vendor sees none of these invites' );

echo "Respond by ID\n";
$denied = LNKup_Vendor_Invites::respond_by_id( $fresh, true, $stranger );
lnkup_t_assert( is_wp_error( $denied ) && 404 === ( $denied->get_error_data()['status'] ?? 0 ), 'another vendor cannot respond (404, no existence leak)' );
$accepted = LNKup_Vendor_Invites::respond_by_id( $fresh, true, $vendor );
lnkup_t_assert( is_array( $accepted ) && ! empty( $accepted['success'] ), 'invited vendor accepts' );
lnkup_t_same( 'accepted', get_post_meta( $fresh, '_lnkup_status', true ), 'status stored as accepted' );
$again = LNKup_Vendor_Invites::respond_by_id( $fresh, false, $vendor );
lnkup_t_assert( is_wp_error( $again ) && 'invite_closed' === $again->get_error_code(), 'cannot respond twice' );
$expired_reply = LNKup_Vendor_Invites::respond_by_id( $overdue, true, $vendor );
lnkup_t_assert( is_wp_error( $expired_reply ), 'cannot accept an expired invite' );

echo "REST\n";
$server = rest_get_server();
lnkup_t_assert( isset( $server->get_routes()['/lnkup/v1/vendor/invites'] ), 'GET /vendor/invites registered' );
wp_set_current_user( $organizer );
$response = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/vendor/invites' ) );
lnkup_t_assert( in_array( $response->get_status(), array( 401, 403 ), true ), 'organizers cannot read vendor invites' );
wp_set_current_user( $vendor );
$response = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/vendor/invites' ) );
lnkup_t_same( 200, $response->get_status(), 'vendor reads own invites' );
$declinable = lnkup_ie_invite( $organizer, $vendor );
$request    = new WP_REST_Request( 'POST', '/lnkup/v1/vendor/invites/' . $declinable . '/respond' );
$request->set_body_params( array( 'accept' => false ) );
$response = rest_do_request( $request );
lnkup_t_same( 200, $response->get_status(), 'vendor declines through REST' );
lnkup_t_same( 'declined', get_post_meta( $declinable, '_lnkup_status', true ), 'decline stored' );
wp_set_current_user( $stranger );
$request = new WP_REST_Request( 'POST', '/lnkup/v1/vendor/invites/' . $overdue . '/respond' );
$request->set_body_params( array( 'accept' => true ) );
lnkup_t_same( 404, rest_do_request( $request )->get_status(), 'other vendor gets 404 through REST' );
wp_set_current_user( 0 );

lnkup_ct_finish();
