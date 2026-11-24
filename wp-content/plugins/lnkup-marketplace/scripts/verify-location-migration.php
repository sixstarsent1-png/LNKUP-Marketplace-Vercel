<?php
/**
 * Migration round trip on disposable test events (run with wp eval-file in a test stack only).
 *
 * Creates three events (formatting fix, invalid state with agreeing coordinates, ambiguous city),
 * runs a dry run, an apply run and a rollback, and checks each step. Deletes its events at the end.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

$fail = 0;
$check = static function ( bool $ok, string $label ) use ( &$fail ) {
	$fail += $ok ? 0 : 1;
	printf( "%s  %s\n", $ok ? 'PASS' : 'FAIL', $label );
};

$make = static function ( string $title, string $city, string $state, array $meta = array() ): int {
	$id = (int) wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'draft', 'post_title' => $title ) );
	update_post_meta( $id, '_lnkup_city', $city );
	update_post_meta( $id, '_lnkup_state', $state );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
};

$fmt  = $make( 'LOCTEST formatting', 'charlotte', 'North Carolina' );
$ge   = $make( 'LOCTEST atlanta ge', 'Atlanta', 'GE', array( '_lnkup_lat' => '33.749', '_lnkup_lng' => '-84.388', '_lnkup_geocode_manual_override' => 1 ) );
$amb  = $make( 'LOCTEST columbus', 'Columbus', '' );
$ids  = array( $fmt, $ge, $amb );

$proposals = array();
foreach ( LNKup_Location_Migration::scan( array( 'entity' => 'event', 'ids' => $ids ) ) as $p ) {
	if ( in_array( (int) $p['entity_id'], $ids, true ) ) {
		$proposals[ (int) $p['entity_id'] ] = $p;
	}
}
$check( isset( $proposals[ $fmt ] ) && ! $proposals[ $fmt ]['needs_review'] && 'Charlotte' === $proposals[ $fmt ]['proposed']['city'], 'formatting fix is automatic' );
$check( isset( $proposals[ $ge ] ) && ! $proposals[ $ge ]['needs_review'] && 'GA' === $proposals[ $ge ]['proposed']['state'], 'Atlanta, GE with Atlanta coordinates is automatic' );
$check( isset( $proposals[ $amb ] ) && $proposals[ $amb ]['needs_review'], 'Columbus without a state waits for review' );

$dry = LNKup_Location_Migration::run( false, array( 'entity' => 'event', 'ids' => $ids ) );
$check( 'GE' === get_post_meta( $ge, '_lnkup_state', true ), 'dry run changes nothing (' . $dry['run_id'] . ')' );

$applied = LNKup_Location_Migration::run( true, array( 'entity' => 'event', 'ids' => $ids ) );
$check( 'GA' === get_post_meta( $ge, '_lnkup_state', true ) && 'NC' === get_post_meta( $fmt, '_lnkup_state', true ), 'apply fixes safe rows (' . $applied['run_id'] . ')' );
$check( '' === (string) get_post_meta( $amb, '_lnkup_state', true ), 'ambiguous record untouched' );
$check( 'atlanta' === get_post_meta( $ge, '_lnkup_market_slug', true ), 'corrected event joins the Atlanta market' );

$queued = LNKup_Location_Migration::list_rows( array( 'status' => 'pending_review', 'entity_type' => 'event', 'limit' => 500 ) );
$row    = null;
foreach ( $queued as $q ) {
	if ( (int) $q['entity_id'] === $amb ) {
		$row = $q;
	}
}
$check( null !== $row, 'ambiguous record is in the review queue' );
if ( $row ) {
	$confirm = LNKup_Location_Migration::confirm( (int) $row['id'], 'Columbus', 'GA', 1 );
	$check( true === $confirm && 'GA' === get_post_meta( $amb, '_lnkup_state', true ), 'admin confirmation applies the chosen city' );
}

$again = LNKup_Location_Migration::run( true, array( 'entity' => 'event', 'ids' => $ids ) );
$touch = 0;
foreach ( LNKup_Location_Migration::list_rows( array( 'run_id' => $again['run_id'], 'limit' => 500 ) ) as $r ) {
	$touch += in_array( (int) $r['entity_id'], array( $fmt, $ge ), true ) ? 1 : 0;
}
$check( 0 === $touch, 'second apply is idempotent for fixed rows' );

$rolled = LNKup_Location_Migration::rollback( $applied['run_id'] );
$check( 'GE' === get_post_meta( $ge, '_lnkup_state', true ) && 'North Carolina' === get_post_meta( $fmt, '_lnkup_state', true ), 'rollback restores originals (' . $rolled['restored'] . ' restored)' );

foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}
global $wpdb;
$wpdb->query( "DELETE FROM " . LNKup_Location_Migration::table() . " WHERE original LIKE '%LOCTEST%'" ); // phpcs:ignore WordPress.DB
echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASSED\n";
