<?php
/**
 * Publishing an event is safe to retry: one event and one set of vendor slots per client token,
 * concurrent row creation waits on a lock, and organizer drafts stay out of public wp/v2 lists.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/launch-publish-idempotency-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Launch publish idempotency\n";

$org    = lnkup_t_user( 'lnkup_organizer' );
$cat    = (string) ( get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false, 'number' => 1, 'fields' => 'names' ) )[0] ?? 'Food' );
$future = gmdate( 'Y-m-d', time() + 40 * DAY_IN_SECONDS );
$dead   = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );
$posts  = array();

/**
 * Dispatch a REST request as a user.
 *
 * @param int    $user   User ID (0 = logged out).
 * @param string $method HTTP method.
 * @param string $route  Full route.
 * @param array  $body   JSON body.
 * @return array{0:int,1:mixed}
 */
function lnkup_lp_rest( int $user, string $method, string $route, array $body = array() ): array {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, $route );
	if ( $body ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	if ( 'GET' === $method ) {
		$req->set_param( 'per_page', 100 );
	}
	$res = rest_do_request( $req );
	return array( $res->get_status(), $res->get_data() );
}

$body = static function ( string $name, string $token, array $extra = array() ) use ( $cat, $future, $dead ): array {
	return array_merge(
		array(
			'event_name'           => $name,
			'event_date'           => $future,
			'city'                 => 'Charlotte',
			'state'                => 'NC',
			'application_deadline' => $dead,
			'client_token'         => $token,
			'opportunities'        => array(
				array( 'opportunity_title' => 'Food row', 'categories' => array( $cat ), 'vendor_fee' => 120, 'spots_total' => 4 ),
				array( 'opportunity_title' => 'Retail row', 'categories' => array( $cat ), 'vendor_fee' => 80, 'spots_total' => 6 ),
			),
		),
		$extra
	);
};

$slots_for = static function ( int $event_id ): array {
	return array_map(
		'intval',
		get_posts( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_lnkup_event_id', 'meta_value' => $event_id ) ) // phpcs:ignore WordPress.DB.SlowDBQuery
	);
};

echo "Retried publish\n";
$token = 'launch_' . wp_generate_password( 24, false );
list( $code, $first ) = lnkup_lp_rest( $org, 'POST', '/lnkup/v1/organizer/launch/publish', $body( 'Idempotency Night Market', $token ) );
$event_id = (int) ( $first['event_id'] ?? 0 );
$posts[]  = $event_id;
lnkup_t_same( 200, $code, 'first publish succeeds' );
lnkup_t_same( 2, count( $slots_for( $event_id ) ), 'first publish creates both vendor slots' );
list( $code, $second ) = lnkup_lp_rest( $org, 'POST', '/lnkup/v1/organizer/launch/publish', $body( 'Idempotency Night Market', $token ) );
lnkup_t_same( 200, $code, 'retried publish succeeds' );
lnkup_t_same( $event_id, (int) ( $second['event_id'] ?? 0 ), 'retry returns the same event' );
lnkup_t_same( $slots_for( $event_id ), $slots_for( (int) ( $second['event_id'] ?? 0 ) ), 'retry returns the same slots' );
lnkup_t_same( 2, count( $slots_for( $event_id ) ), 'retry adds no vendor slots' );
$posts = array_merge( $posts, $slots_for( $event_id ) );
$token_events = get_posts( array( 'post_type' => 'lnkup_event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_lnkup_client_token_hash', 'meta_value' => md5( $token ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
lnkup_t_same( 1, count( $token_events ), 'one event exists for the token' );

echo "Concurrent slot creation\n";
$row_token = 'launch_' . wp_generate_password( 24, false ) . '_r0';
$lock      = 'lnkup_opp_' . $org . '_' . md5( $row_token );
$other     = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
lnkup_t_same( 1, (int) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ), 'a second connection holds the slot lock' );
$row     = array( 'client_token' => $row_token, 'opportunity_title' => 'Locked row', 'categories' => array( $cat ), 'vendor_fee' => 50, 'spots_total' => 2 );
$started = microtime( true );
$busy    = LNKup_Organizer_Onboarding::create_opportunity_for_event( $event_id, $row, $org );
lnkup_t_assert( is_wp_error( $busy ) && 'busy' === $busy->get_error_code(), 'creation waits on the lock and then reports busy instead of inserting' );
lnkup_t_assert( microtime( true ) - $started >= 9, 'creation waited for the other request' );
lnkup_t_same( 2, count( $slots_for( $event_id ) ), 'no slot was written while the lock was held' );
$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
$made  = LNKup_Organizer_Onboarding::create_opportunity_for_event( $event_id, $row, $org );
$again = LNKup_Organizer_Onboarding::create_opportunity_for_event( $event_id, $row, $org );
lnkup_t_assert( ! is_wp_error( $made ) && ! is_wp_error( $again ), 'creation succeeds once the lock is free' );
lnkup_t_same( (int) ( $made['opportunity_id'] ?? 0 ), (int) ( $again['opportunity_id'] ?? -1 ), 'the same row token returns the same slot' );
lnkup_t_same( 3, count( $slots_for( $event_id ) ), 'exactly one slot added for the row token' );
$posts = array_merge( $posts, $slots_for( $event_id ) );

echo "Draft visibility\n";
list( $code, $draft ) = lnkup_lp_rest( $org, 'POST', '/lnkup/v1/organizer/launch/publish', $body( 'Unannounced Draft Fair', 'launch_' . wp_generate_password( 24, false ), array( 'save_mode' => 'draft', 'opportunities' => array() ) ) );
$draft_id = (int) ( $draft['event_id'] ?? 0 );
$posts[]  = $draft_id;
lnkup_t_same( 'draft', (string) get_post_meta( $draft_id, '_lnkup_event_status', true ), 'draft save leaves the event in draft' );
list( , $list ) = lnkup_lp_rest( 0, 'GET', '/wp/v2/lnkup_event' );
$listed         = array_map( 'intval', wp_list_pluck( (array) $list, 'id' ) );
lnkup_t_assert( ! in_array( $draft_id, $listed, true ), 'a draft event is not in the public wp/v2 list' );
lnkup_t_assert( in_array( $event_id, $listed, true ), 'a published event is still in the public wp/v2 list' );
lnkup_t_same( 404, lnkup_lp_rest( 0, 'GET', '/wp/v2/lnkup_event/' . $draft_id )[0], 'a logged-out single GET of a draft is 404' );
lnkup_t_same( 404, lnkup_lp_rest( lnkup_t_user( 'lnkup_vendor' ), 'GET', '/wp/v2/lnkup_event/' . $draft_id )[0], 'a vendor single GET of a draft is 404' );
lnkup_t_same( 200, lnkup_lp_rest( $org, 'GET', '/wp/v2/lnkup_event/' . $draft_id )[0], 'the owner can still read the draft' );
lnkup_t_same( 200, lnkup_lp_rest( 0, 'GET', '/wp/v2/lnkup_event/' . $event_id )[0], 'a published event is still readable' );

wp_set_current_user( 0 );
foreach ( array_unique( array_filter( $posts ) ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}
lnkup_t_finish();
