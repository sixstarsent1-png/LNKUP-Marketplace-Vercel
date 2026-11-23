<?php
/**
 * Organizer vendor directory (/crm/vendors discover): card-only profiles, html=0, short cache,
 * failure as 503 JSON, opt-out privacy; and /match/organizer/status for Find Vendors gating.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/vendor-directory-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

echo "Vendor directory\n";

$org    = lnkup_t_user( 'lnkup_organizer' );
$tag    = 'VdirT' . wp_generate_password( 6, false );
$posts  = array();

$make_vendor = static function ( string $name, array $meta = array() ) use ( &$posts ): array {
	$user_id = lnkup_t_user( 'lnkup_vendor' );
	$id      = wp_insert_post(
		array(
			'post_type'   => 'lnkup_vendor_profile',
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);
	update_post_meta( $id, '_lnkup_owner_user_id', $user_id );
	update_post_meta( $id, '_lnkup_business_name', $name );
	update_post_meta( $id, '_lnkup_city', 'Charlotte' );
	update_post_meta( $id, '_lnkup_state', 'NC' );
	update_post_meta( $id, '_lnkup_contact_email', strtolower( $name ) . '@example.test' );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	$posts[] = $id;
	return array( (int) $id, $user_id );
};

$rest = static function ( int $user, string $method, string $route, array $query = array() ) {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, '/lnkup/v1' . $route );
	foreach ( $query as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$res = rest_do_request( $req );
	return array( $res->get_status(), $res->get_data() );
};

list( $visible_id, $visible_user ) = $make_vendor( $tag . 'Visible' );
list( $hidden_id )                 = $make_vendor( $tag . 'Hidden', array( '_lnkup_discoverable' => '0' ) );

// --------------------------------------------------------------- Card payload.
echo "\n[discover, html=0]\n";
list( $status, $data ) = $rest( $org, 'GET', '/crm/vendors', array( 'scope' => 'discover', 'q' => $tag, 'html' => 0 ) );
lnkup_t_same( 200, $status, 'discover returns 200' );
lnkup_t_assert( ! array_key_exists( 'html', (array) $data ), 'html=0 omits server-rendered html' );
$ids = array_map( 'intval', wp_list_pluck( (array) ( $data['items'] ?? array() ), 'profile_id' ) );
lnkup_t_assert( in_array( $visible_id, $ids, true ), 'discoverable vendor is listed' );
lnkup_t_assert( ! in_array( $hidden_id, $ids, true ), 'vendor who opted out of discovery is not listed' );

$card = array();
foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
	if ( (int) $item['profile_id'] === $visible_id ) {
		$card = $item;
	}
}
foreach ( array( 'business_name', 'vendor_user_id', 'category', 'city', 'state', 'logo', 'verified', 'completed_events', 'review_count', 'is_favorite', 'indicators', 'badges' ) as $field ) {
	lnkup_t_assert( array_key_exists( $field, $card ), "card has {$field}" );
}
lnkup_t_assert( ! array_key_exists( 'vendor_score', $card ), 'internal score stays hidden from organizers (passport projection)' );
lnkup_t_assert( ! array_key_exists( 'avg_rating', $card ), 'no rating shown below the review minimum' );
lnkup_t_same( $tag . 'Visible', $card['business_name'] ?? '', 'card business name' );
lnkup_t_same( $visible_user, (int) ( $card['vendor_user_id'] ?? 0 ), 'card vendor user id (needed for invites)' );
lnkup_t_assert( ! array_key_exists( 'email', $card ) && ! array_key_exists( 'phone', $card ), 'card never carries contact details' );
lnkup_t_assert( ! array_key_exists( 'past_events', $card ) && ! array_key_exists( 'recent_notes', $card ), 'card skips per-organizer history and notes' );
lnkup_t_assert( isset( $card['indicators']['experience']['confirmed_events'] ), 'card has experience indicator for sorting' );

echo "\n[discover, dashboard default]\n";
list( $status, $data ) = $rest( $org, 'GET', '/crm/vendors', array( 'scope' => 'discover', 'q' => $tag ) );
lnkup_t_same( 200, $status, 'dashboard request returns 200' );
lnkup_t_assert( is_string( $data['html'] ?? null ) && false !== strpos( $data['html'], $tag . 'Visible' ), 'dashboard still gets rendered cards' );

// --------------------------------------------------------------- Cache + invalidation.
echo "\n[cache]\n";
lnkup_t_same( false, (bool) ( $card['is_favorite'] ?? false ), 'not a favorite yet' );
list( $status ) = $rest( $org, 'POST', '/crm/vendors/' . $visible_id . '/favorite' );
lnkup_t_same( 200, $status, 'favorite toggled' );
list( , $data ) = $rest( $org, 'GET', '/crm/vendors', array( 'scope' => 'discover', 'q' => $tag, 'html' => 0 ) );
$fav = false;
foreach ( (array) $data['items'] as $item ) {
	if ( (int) $item['profile_id'] === $visible_id ) {
		$fav = (bool) $item['is_favorite'];
	}
}
lnkup_t_assert( $fav, 'favorite shows right away (cache cleared on toggle)' );

$throw = static function ( $profile, $context ) {
	if ( 'crm_card' === $context ) {
		throw new RuntimeException( 'forced directory failure' );
	}
	return $profile;
};
add_filter( 'lnkup_vendor_profile_data', $throw, 10, 2 );
list( $status ) = $rest( $org, 'GET', '/crm/vendors', array( 'scope' => 'discover', 'q' => $tag, 'html' => 0 ) );
lnkup_t_same( 200, $status, 'repeat request within the cache window is served from cache' );

LNKup_Vendor_Directory::flush_discover_cache( $org );
echo "\n[failure]\n";
list( $status, $data ) = $rest( $org, 'GET', '/crm/vendors', array( 'scope' => 'discover', 'q' => $tag, 'html' => 0 ) );
remove_filter( 'lnkup_vendor_profile_data', $throw, 10 );
lnkup_t_same( 503, $status, 'a failure returns 503' );
lnkup_t_same( 'lnkup_directory_unavailable', $data['code'] ?? '', 'friendly error code' );
lnkup_t_assert( false === strpos( (string) ( $data['message'] ?? '' ), 'forced' ), 'technical message is not shown' );

// --------------------------------------------------------------- Score sort.
echo "\n[score sort]\n";
global $wpdb;
list( $low_id )  = $make_vendor( $tag . 'Low', array( '_lnkup_reputation_score' => '20' ) );
list( $high_id ) = $make_vendor( $tag . 'High', array( '_lnkup_reputation_score' => '90' ) );
$wpdb->last_error = '';
$query = lnkup_get_discoverable_vendors( array( 'q' => $tag, 'sort' => 'score', 'organizer_id' => $org ) );
lnkup_t_same( '', $wpdb->last_error, 'score sort runs without a SQL error' );
$order = array_map( 'intval', wp_list_pluck( $query['posts'], 'ID' ) );
lnkup_t_same( $high_id, $order[0] ?? 0, 'highest reputation score comes first' );
lnkup_t_same( array( $high_id, $low_id, $visible_id ), $order, 'order is 90, 20, then unscored' );
lnkup_t_assert( ! in_array( $hidden_id, $order, true ), 'opt-out vendor stays hidden with score sort' );
lnkup_t_same( 3, (int) $query['total'], 'total counts each discoverable vendor once' );

// --------------------------------------------------------------- Find Vendors status.
echo "\n[match organizer status]\n";
list( $status, $data ) = $rest( $org, 'GET', '/match/organizer/status' );
lnkup_t_same( 200, $status, 'organizer can read Find Vendors status' );
lnkup_t_same( LNKup_Match_Settings::enabled( 'matching_organizer_discovery_enabled', $org ), $data['find_vendors_enabled'] ?? null, 'status mirrors the flag + audience' );
list( $status ) = $rest( $visible_user, 'GET', '/match/organizer/status' );
lnkup_t_assert( in_array( $status, array( 401, 403 ), true ), 'vendors cannot read organizer status' );

// --------------------------------------------------------------- Cleanup.
global $wpdb;
$wpdb->delete( LNKup_Organizer_CRM_Tables::rel_table(), array( 'organizer_user_id' => $org ) );
delete_user_meta( $org, LNKup_Vendor_Directory::CACHE_GEN_META );
foreach ( $posts as $pid ) {
	wp_delete_post( $pid, true );
}
lnkup_t_finish();
