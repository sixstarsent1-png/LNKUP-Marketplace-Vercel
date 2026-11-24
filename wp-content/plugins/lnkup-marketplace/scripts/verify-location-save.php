<?php
/**
 * Event save path with location validation (run with wp eval-file in a test stack only).
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
$org   = (int) wp_insert_user( array( 'user_login' => "locsave_{$stamp}", 'user_pass' => wp_generate_password(), 'user_email' => "locsave_{$stamp}@example.test", 'role' => 'lnkup_organizer' ) );
wp_set_current_user( $org );

$bad = LNKup_Organizer_Onboarding::create_event_from_request( array( 'event_name' => "LOCSAVE bad {$stamp}", 'city' => 'Atlanta', 'state' => 'GE', 'location_mode' => 'strict' ) );
$data = is_wp_error( $bad ) ? (array) $bad->get_error_data() : array();
$check( is_wp_error( $bad ) && 422 === ( $data['status'] ?? 0 ) && 'Atlanta, GA' === ( $data['location']['suggestions'][0]['display'] ?? '' ), 'strict save of Atlanta, GE: 422 with "Atlanta, GA" suggestion' );

$good = LNKup_Organizer_Onboarding::create_event_from_request( array( 'event_name' => "LOCSAVE good {$stamp}", 'city' => 'atlanta', 'state' => 'Georgia', 'location_mode' => 'strict' ) );
$id   = is_array( $good ) ? (int) $good['event_id'] : 0;
$check( $id > 0 && 'Atlanta' === get_post_meta( $id, '_lnkup_city', true ) && 'GA' === get_post_meta( $id, '_lnkup_state', true ), 'strict save of "atlanta, Georgia" stores Atlanta, GA' );
$check( $id > 0 && 'atlanta' === get_post_meta( $id, '_lnkup_market_slug', true ) && 'normalized' === get_post_meta( $id, '_lnkup_location_status', true ), 'event joins the Atlanta market with status normalized' );

$metro = LNKup_Organizer_Onboarding::create_event_from_request( array( 'event_name' => "LOCSAVE metro {$stamp}", 'city' => 'Marietta', 'state' => 'GA', 'location_mode' => 'strict' ) );
$mid   = is_array( $metro ) ? (int) $metro['event_id'] : 0;
$check( $mid > 0 && 'Marietta' === get_post_meta( $mid, '_lnkup_city', true ) && 'atlanta' === get_post_meta( $mid, '_lnkup_market_slug', true ), 'Marietta keeps its own city and joins the Atlanta metro market' );

$lenient = LNKup_Organizer_Onboarding::create_event_from_request( array( 'event_name' => "LOCSAVE import {$stamp}", 'city' => 'Atlanta', 'state' => 'GE', 'location_mode' => 'lenient' ) );
$lid     = is_array( $lenient ) ? (int) $lenient['event_id'] : 0;
$check( $lid > 0 && 'GE' === get_post_meta( $lid, '_lnkup_state', true ) && 'suggested' === get_post_meta( $lid, '_lnkup_location_status', true ), 'lenient import keeps GE and flags it for review' );

if ( $id ) {
	$edit = LNKup_Organizer_Onboarding::update_event_from_request( $id, array( 'event_name' => "LOCSAVE good renamed {$stamp}", 'city' => 'Atlanta', 'state' => 'GA', 'location_mode' => 'strict' ), $org );
	$check( is_array( $edit ), 'editing other fields with an unchanged location is never blocked' );
}

foreach ( array( $id, $mid, $lid ) as $event_id ) {
	if ( $event_id ) {
		wp_delete_post( $event_id, true );
	}
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $org );
echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASSED\n";
