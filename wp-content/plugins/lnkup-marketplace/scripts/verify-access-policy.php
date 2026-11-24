<?php
/**
 * Role matrix for LNKup_Access_Policy (run with wp eval-file in a test stack only).
 *
 * Creates an owner organizer, an unrelated organizer, a vendor, an approved vendor, one event with
 * a hidden venue and three slots (published, invite-only, draft), then checks what each viewer
 * receives from wp/v2, the index serializer, the public event page HTML, SEO copy and apply context.
 * Everything it creates is deleted at the end.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

$fail  = 0;
$check = static function ( bool $ok, string $label ) use ( &$fail ) {
	$fail += $ok ? 0 : 1;
	printf( "%s  %s\n", $ok ? 'PASS' : 'FAIL', $label );
};
$stamp = (string) time();
$user  = static function ( string $role, string $tag ) use ( $stamp ): int {
	$id = wp_insert_user( array( 'user_login' => "acc_{$tag}_{$stamp}", 'user_pass' => wp_generate_password(), 'user_email' => "acc_{$tag}_{$stamp}@example.test", 'role' => $role ) );
	if ( is_wp_error( $id ) ) {
		echo 'user error: ', $id->get_error_message(), "\n";
		return 0;
	}
	return (int) $id;
};

$owner    = $user( 'lnkup_organizer', 'owner' );
$other    = $user( 'lnkup_organizer', 'other' );
$vendor   = $user( 'lnkup_vendor', 'vendor' );
$approved = $user( 'lnkup_vendor', 'approved' );
$admins   = get_users( array( 'role__in' => array( 'administrator', 'lnkup_admin' ), 'number' => 1, 'fields' => 'ID' ) );
$admin    = $admins ? (int) $admins[0] : $user( 'lnkup_admin', 'admin' );
$made_admin = ! $admins;

$event = (int) wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => "ACC Test Fair {$stamp}", 'post_author' => $owner ) );
foreach ( array(
	'_lnkup_organizer_user_id' => $owner,
	'_lnkup_city'              => 'Atlanta',
	'_lnkup_state'             => 'GA',
	'_lnkup_venue_name'        => 'Secret Warehouse',
	'_lnkup_venue_address'     => '123 Hidden St',
	'_lnkup_venue_hidden'      => 1,
	'_lnkup_vendor_gate'       => 'Gate 7 code 4455',
	'_lnkup_parking_notes'     => 'Park in lot C',
	'_lnkup_event_status'      => 'live',
	'_lnkup_event_date'        => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
	'_lnkup_lat'               => '33.7490',
	'_lnkup_lng'               => '-84.3880',
) as $key => $value ) {
	update_post_meta( $event, $key, $value );
}
$slot = static function ( string $title, string $status, bool $invite ) use ( $event ): int {
	$id = (int) wp_insert_post( array( 'post_type' => 'lnkup_opportunity', 'post_status' => $status, 'post_title' => $title ) );
	foreach ( array( '_lnkup_event_id' => $event, '_lnkup_vendor_fee' => '275', '_lnkup_lnkup_fee' => '12', '_lnkup_spots_total' => 20, '_lnkup_spots_remaining' => 9, '_lnkup_required_documents' => 'health_permit', '_lnkup_organizer_notes' => 'INTERNAL NOTE', '_lnkup_opportunity_status' => 'open', '_lnkup_invite_only' => $invite ? 1 : 0, '_lnkup_application_deadline' => gmdate( 'Y-m-d', strtotime( '+20 days' ) ) ) as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
};
$public_slot = $slot( 'ACC Food Booth', 'publish', false );
$invite_slot = $slot( 'ACC VIP Invite Booth', 'publish', true );
$draft_slot  = $slot( 'ACC Draft Booth', 'draft', false );
$app         = (int) wp_insert_post( array( 'post_type' => 'lnkup_application', 'post_status' => 'publish', 'post_title' => 'ACC app' ) );
update_post_meta( $app, '_lnkup_vendor_user_id', $approved );
update_post_meta( $app, '_lnkup_event_id', $event );
update_post_meta( $app, '_lnkup_opportunity_id', $public_slot );
update_post_meta( $app, '_lnkup_application_status', 'paid' );
LNKup_Marketplace_Index::sync_opportunity( $public_slot );
LNKup_Marketplace_Index::sync_opportunity( $invite_slot );

// wp/v2 only carries meta when a post type supports custom-fields (these do not today), so each
// check looks at both the full response JSON and the policy's allowlist for that viewer.
$rest_meta = static function ( string $route ): array {
	rest_get_server();
	$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
	$data     = $response->get_data();
	$json     = (string) wp_json_encode( $data );
	$meta     = is_array( $data['meta'] ?? null ) ? $data['meta'] : array();
	foreach ( array( '123 Hidden St' => '_lnkup_venue_address', 'Gate 7' => '_lnkup_vendor_gate', 'INTERNAL NOTE' => '_lnkup_organizer_notes', 'Park in lot C' => '_lnkup_parking_notes' ) as $needle => $key ) {
		if ( false !== strpos( $json, $needle ) ) {
			$meta[ $key ] = true;
		}
	}
	return $meta;
};
$allowed = static function ( string $type, int $event_id ): array {
	return (array) LNKup_Access_Policy::allowed_rest_meta( $type, $event_id );
};

// ---- Guest.
wp_set_current_user( 0 );
$check( LNKup_Access_Policy::GUEST === LNKup_Access_Policy::viewer_context( $event ), 'guest context' );
$meta = $rest_meta( "/wp/v2/lnkup_event/{$event}" );
if ( getenv( 'ACC_DEBUG' ) ) {
	echo 'guest event meta keys: ', implode( ',', array_keys( $meta ) ), "\n";
}
$check( ! isset( $meta['_lnkup_venue_address'] ) && ! isset( $meta['_lnkup_vendor_gate'] ) && in_array( '_lnkup_city', $allowed( 'lnkup_event', $event ), true ) && ! in_array( '_lnkup_venue_address', $allowed( 'lnkup_event', $event ), true ), 'guest wp/v2 event: city only, no address or gate' );
$meta = $rest_meta( "/wp/v2/lnkup_opportunity/{$public_slot}" );
$check( ! isset( $meta['_lnkup_vendor_fee'] ) && ! isset( $meta['_lnkup_organizer_notes'] ) && ! isset( $meta['_lnkup_lnkup_fee'] ), 'guest wp/v2 slot: no fee, notes or LNKup fee' );
$row  = LNKup_Marketplace_Index::get_row( $public_slot );
$item = $row ? lnkup_format_index_row( $row, true ) : array();
$check( $item && null === $item['vendor_fee'] && null === $item['spots_remaining'] && empty( $item['application_count'] ) && '' === $item['venue_name'] && ! empty( $item['access']['gated'] ), 'guest index row: fee/spots/venue withheld, gated' );
$search = LNKup_Discovery::search( array( 'q' => "ACC Test Fair {$stamp}", 'force_like' => true ) );
$found  = wp_list_pluck( (array) ( $search['items'] ?? array() ), 'opportunity_id' );
$check( ! in_array( $invite_slot, array_map( 'intval', $found ), true ), 'guest search excludes invite-only slot' );
$html = LNKup_Shortcodes::render_public_event( $event );
$check( false === strpos( $html, '275' ) && false === strpos( $html, 'Health Permit' ) && false === strpos( $html, 'VIP Invite' ) && false === strpos( $html, 'Draft Booth' ), 'guest event page: no price, documents, invite-only or draft slot' );
$check( false !== strpos( $html, 'lnkup-guest-gate' ) && false === strpos( $html, 'Secret Warehouse' ) && false === strpos( $html, 'Gate 7' ), 'guest event page: sign-in gate, hidden venue stays hidden' );
$copy = lnkup_seo_opportunity_copy( $event );
$check( is_array( $copy ) && false === strpos( (string) $copy['description'], '$' ) && false === stripos( (string) $copy['description'], 'spots' ), 'SEO description has no fee or spots' );
$ctx = LNKup_Applications::build_apply_context( $public_slot, 0 );
$check( is_array( $ctx ) && array() === $ctx['questions'] && ! empty( $ctx['access']['gated'] ) && null === $ctx['opportunity']['vendor_fee'], 'guest apply context: gated, no questions or fee' );
$draft_ctx = LNKup_Applications::build_apply_context( $draft_slot, 0 );
$check( is_wp_error( $draft_ctx ), 'draft slot apply context is not available publicly' );
$cta = LNKup_Access_Policy::guest_cta( 'apply', 'https://evil.example.com/steal' );
$check( false === strpos( $cta['sign_in_url'], 'evil.example.com' ), 'CTA ignores off-site return URLs' );

// ---- Vendor (member).
wp_set_current_user( $vendor );
$check( LNKup_Access_Policy::MEMBER === LNKup_Access_Policy::viewer_context( $event ), 'vendor is a member, not a participant' );
$meta = $rest_meta( "/wp/v2/lnkup_opportunity/{$public_slot}" );
if ( getenv( 'ACC_DEBUG' ) ) {
	echo 'vendor slot meta keys: ', implode( ',', array_keys( $meta ) ), "\n";
}
$vendor_keys = $allowed( 'lnkup_opportunity', $event );
$check( in_array( '_lnkup_vendor_fee', $vendor_keys, true ) && ! in_array( '_lnkup_organizer_notes', $vendor_keys, true ) && ! in_array( '_lnkup_lnkup_fee', $vendor_keys, true ) && ! isset( $meta['_lnkup_organizer_notes'] ), 'vendor wp/v2 slot: fee yes, internal notes no' );
$meta = $rest_meta( "/wp/v2/lnkup_event/{$event}" );
$check( ! isset( $meta['_lnkup_venue_address'] ) && ! isset( $meta['_lnkup_vendor_gate'] ), 'vendor: hidden venue and gate stay hidden' );
$item = lnkup_format_index_row( LNKup_Marketplace_Index::get_row( $public_slot ), true );
$check( 275.0 === (float) $item['vendor_fee'] && empty( $item['access']['gated'] ), 'vendor index row has pricing' );
$html = LNKup_Shortcodes::render_public_event( $event );
$check( false !== strpos( $html, '275' ) && false === strpos( $html, 'Draft Booth' ), 'vendor event page shows price, never draft slots' );

// ---- Unrelated organizer.
wp_set_current_user( $other );
$check( LNKup_Access_Policy::MEMBER === LNKup_Access_Policy::viewer_context( $event ), 'unrelated organizer has no owner access' );
$meta = $rest_meta( "/wp/v2/lnkup_opportunity/{$public_slot}" );
$check( ! isset( $meta['_lnkup_organizer_notes'] ), 'unrelated organizer cannot read organizer notes' );

// ---- Approved vendor.
wp_set_current_user( $approved );
$check( LNKup_Access_Policy::PARTICIPANT === LNKup_Access_Policy::viewer_context( $event ), 'paid vendor is a participant' );
$participant_keys = $allowed( 'lnkup_event', $event );
$check( in_array( '_lnkup_vendor_gate', $participant_keys, true ) && in_array( '_lnkup_venue_address', $participant_keys, true ), 'participant sees gate and venue address' );

// ---- Owner and admin.
wp_set_current_user( $owner );
$check( LNKup_Access_Policy::OWNER === LNKup_Access_Policy::viewer_context( $event ), 'event owner context' );
$html = LNKup_Shortcodes::render_public_event( $event );
$check( false !== strpos( $html, 'Draft Booth' ), 'owner still sees draft slots on own event' );
wp_set_current_user( $admin );
$check( LNKup_Access_Policy::ADMIN === LNKup_Access_Policy::viewer_context( $event ), 'administrator context' );

// ---- Cleanup.
wp_set_current_user( $admin );
foreach ( array( $public_slot, $invite_slot, $draft_slot ) as $id ) {
	LNKup_Marketplace_Index::delete_row( $id );
	wp_delete_post( $id, true );
}
wp_delete_post( $app, true );
wp_delete_post( $event, true );
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array_merge( array( $owner, $other, $vendor, $approved ), $made_admin ? array( $admin ) : array() ) as $id ) {
	wp_delete_user( $id );
}
echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASSED\n";
