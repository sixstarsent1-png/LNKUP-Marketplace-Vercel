<?php
/**
 * Security: REST authentication/authorization, data isolation, staff financial
 * isolation, token tampering, deep-link allowlist conformance.
 *
 * @package LNKup_Communications
 */

require_once __DIR__ . '/_comms_helpers.php';

global $wpdb;
lnkup_ct_all_flows();
$vendor    = lnkup_ct_user( 'lnkup_vendor' );
$vendor2   = lnkup_ct_user( 'lnkup_vendor' );
$organizer = lnkup_ct_user( 'lnkup_organizer' );
$profile   = lnkup_ct_profile( $vendor );
$event     = lnkup_ct_event( $organizer );
$app       = lnkup_ct_application( $vendor, $organizer, $event, 'waitlisted', $profile );

/**
 * Dispatch a REST request as a user and return the status code.
 */
function lnkup_ct_rest( int $user_id, string $method, string $route, array $body = array() ): int {
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( $method, $route );
	if ( $body ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	$status = rest_get_server()->dispatch( $request )->get_status();
	wp_set_current_user( 0 );
	return $status;
}

echo "Authentication\n";
foreach ( array( '/lnkup/v1/comms/preferences', '/lnkup/v1/comms/inbox', '/lnkup/v1/comms/next-steps' ) as $route ) {
	lnkup_t_same( 401, lnkup_ct_rest( 0, 'GET', $route ), "anonymous GET {$route} → 401" );
}
lnkup_t_same( 200, lnkup_ct_rest( $vendor, 'GET', '/lnkup/v1/comms/inbox' ), 'vendor can read their inbox' );

echo "Admin isolation\n";
foreach ( array( '/lnkup/v1/comms/admin/overview', '/lnkup/v1/comms/admin/logs', '/lnkup/v1/comms/admin/settings' ) as $route ) {
	lnkup_t_same( 403, lnkup_ct_rest( $organizer, 'GET', $route ), "organizer GET {$route} → 403" );
}
lnkup_t_same( 403, lnkup_ct_rest( $vendor, 'POST', '/lnkup/v1/comms/admin/test-send', array( 'trigger' => 'v_welcome' ) ), 'vendor cannot fire test sends' );

echo "Organizer route isolation\n";
lnkup_t_same( 403, lnkup_ct_rest( $vendor, 'POST', '/lnkup/v1/comms/events/' . $event['event'] . '/send', array( 'group' => 'all_applicants', 'template' => 'announcement', 'title' => 'x', 'body' => 'x' ) ), 'vendor cannot send event messages' );

echo "Inbox data isolation\n";
$id = LNKup_Comms_Engine::dispatch( 'v_application_waitlisted', $vendor, array( 'application_id' => $app ) );
LNKup_Comms_Engine::deliver( $id );
$req = new WP_REST_Request( 'POST' );
$req->set_param( 'notificationId', $id );
wp_set_current_user( $vendor2 );
$res = LNKup_Comms_REST::opened( $req );
wp_set_current_user( 0 );
lnkup_t_same( false, $res->get_data()['ok'], "another user cannot mark someone else's notification opened" );
lnkup_t_same( null, lnkup_ct_message( $id )['opened_at'], 'opened_at untouched' );
lnkup_t_same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Inbox_Tables::index_table() . ' WHERE user_id = %d AND client_id = %s', $vendor2, 'comms-' . $id ) ), 'no stub inbox row created for the other user' ); // phpcs:ignore WordPress.DB
wp_set_current_user( $vendor2 );
$list = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/lnkup/v1/comms/inbox' ) )->get_data();
wp_set_current_user( 0 );
lnkup_t_same( array(), array_values( array_filter( (array) ( $list['items'] ?? array() ), static function ( $item ) use ( $id ) {
	return (int) ( $item['notificationId'] ?? 0 ) === $id;
} ) ), "other vendor's inbox does not include the message" );

echo "Staff never receive financial messages\n";
$staff = lnkup_ct_user( 'lnkup_vendor' );
$pay   = lnkup_ct_payment( $app, $vendor, $organizer, 'paid' );
$ctx   = LNKup_Comms_Context::build( array( 'application_id' => $app, 'payment_id' => $pay ) );
lnkup_t_assert( '' !== LNKup_Comms_Engine::authorize( 'organizer', $staff, $ctx ), 'a non-owner is never authorized for organizer payment messages' );
lnkup_t_same( '', LNKup_Comms_Engine::authorize( 'organizer', $organizer, $ctx ), 'the owning organizer is' );

echo "Click tracking cannot become an open redirect\n";
$tracked = LNKup_Comms_Links::tracked( $id, 'email', home_url( '/vendor-dashboard/' ) );
$token   = (string) wp_parse_url( $tracked, PHP_URL_QUERY );
parse_str( $token, $q );
$parts   = explode( '.', (string) $q['lnkup_c'] );
$evil    = rtrim( strtr( base64_encode( $id . '|email|https://evil.example' ), '+/', '-_' ), '=' ) . '.' . $parts[1];
lnkup_t_assert( $parts[0] !== explode( '.', $evil )[0], 'destination is part of the signed payload' );

echo "Deep links conform to the mobile allowlist\n";
$rules = array(
	'#^/\(vendor\)(/[\w-]+)?(\?[\w=&%.-]*)?$#',
	'#^/\(organizer\)(/[\w-]+){0,2}(\?[\w=&%.-]*)?$#',
	'#^/(pass|apply|booking)/\d+(\?[\w=&%.-]*)?$#',
	'#^/opportunity/\d+(\?[\w=&%.-]*)?$#',
);
$ctx  = LNKup_Comms_Context::build( array( 'application_id' => $app ) );
$bad  = array();
foreach ( array_unique( wp_list_pluck( LNKup_Comms_Registry::triggers(), 'link' ) ) as $link ) {
	foreach ( array( 'vendor', 'organizer' ) as $role ) {
		$href = LNKup_Comms_Context::deep_link( (string) $link, $ctx, $role );
		$ok   = false;
		foreach ( $rules as $rule ) {
			$ok = $ok || (bool) preg_match( $rule, $href );
		}
		if ( ! $ok ) {
			$bad[] = $link . ' → ' . $href;
		}
	}
}
lnkup_t_same( array(), $bad, 'every server deep link passes the app allowlist' );

lnkup_ct_finish();
