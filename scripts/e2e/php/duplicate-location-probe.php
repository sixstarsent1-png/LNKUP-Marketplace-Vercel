<?php
/**
 * Probe: a duplicated event in a new city keeps none of the source venue location.
 *
 * Usage: wp eval-file scripts/e2e/php/duplicate-location-probe.php
 * Creates one copy, checks it, then deletes only the copy it created.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

$org    = get_user_by( 'login', 'e2e_org_a' );
$source = get_posts(
	array(
		'post_type'      => 'lnkup_event',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) ( $org ? $org->ID : 0 ) ),
			array( 'key' => '_lnkup_city', 'value' => 'Houston' ),
		),
	)
);
if ( ! $org || ! $source ) {
	WP_CLI::error( 'Seeded Houston event for e2e_org_a not found. Run scripts/e2e/seed.php.' );
}
$source = (int) $source[0];
$fails  = 0;
$check  = static function ( $ok, $label ) use ( &$fails ) {
	if ( $ok ) {
		WP_CLI::log( 'PASS ' . $label );
	} else {
		$fails++;
		WP_CLI::log( 'FAIL ' . $label );
	}
};

$missing = LNKup_Organizer_Onboarding::duplicate_event( $source, array( 'event_name' => 'E2E Greensboro Copy', 'event_date' => '2027-10-02' ), (int) $org->ID );
$check( is_wp_error( $missing ) && 'missing_location' === $missing->get_error_code(), 'duplicate without city/state is rejected' );

$copy = LNKup_Organizer_Onboarding::duplicate_event(
	$source,
	array(
		'event_name' => 'E2E Greensboro Copy',
		'event_date' => '2027-10-02',
		'city'       => 'Greensboro',
		'state'      => 'NC',
	),
	(int) $org->ID
);
$check( ! is_wp_error( $copy ) && ! empty( $copy['event_id'] ), 'duplicate with a new city succeeds' );
if ( ! is_wp_error( $copy ) && ! empty( $copy['event_id'] ) ) {
	$id = (int) $copy['event_id'];
	$check( 'Greensboro' === get_post_meta( $id, '_lnkup_city', true ), 'city is Greensboro' );
	$check( 'NC' === strtoupper( (string) get_post_meta( $id, '_lnkup_state', true ) ), 'state is NC' );
	foreach ( array( '_lnkup_address_line1', '_lnkup_zip', '_lnkup_market', '_lnkup_venue_name', '_lnkup_venue_address' ) as $key ) {
		$src = (string) get_post_meta( $source, $key, true );
		$new = (string) get_post_meta( $id, $key, true );
		$check( '' === $src || $src !== $new, $key . ' not inherited from source (' . ( '' === $new ? 'empty' : $new ) . ')' );
	}
	$src_lat = (string) get_post_meta( $source, '_lnkup_lat', true );
	$check( '' === $src_lat || $src_lat !== (string) get_post_meta( $id, '_lnkup_lat', true ), 'latitude not inherited from source' );
	$check( false === stripos( LNKup_Organizer_Presentation::place( $id ), 'Houston' ), 'display place is not Houston (' . LNKup_Organizer_Presentation::place( $id ) . ')' );
	foreach ( (array) ( $copy['opportunity_ids'] ?? array() ) as $opp_id ) {
		wp_delete_post( (int) $opp_id, true );
	}
	wp_delete_post( $id, true );
}

$same = LNKup_Organizer_Onboarding::duplicate_event( $source, array( 'event_name' => 'E2E Houston Copy', 'event_date' => '2027-10-09', 'keep_location' => 1 ), (int) $org->ID );
$check( ! is_wp_error( $same ) && 'Houston' === get_post_meta( (int) ( $same['event_id'] ?? 0 ), '_lnkup_city', true ), 'keep_location copies the source city on purpose' );
if ( ! is_wp_error( $same ) && ! empty( $same['event_id'] ) ) {
	foreach ( (array) ( $same['opportunity_ids'] ?? array() ) as $opp_id ) {
		wp_delete_post( (int) $opp_id, true );
	}
	wp_delete_post( (int) $same['event_id'], true );
}

$fails ? WP_CLI::error( $fails . ' check(s) failed.' ) : WP_CLI::success( 'Duplicate location probe passed.' );
