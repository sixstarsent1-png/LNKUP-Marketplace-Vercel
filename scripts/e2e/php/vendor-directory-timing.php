<?php
/**
 * Timing: full CRM profile vs. directory card for one discover page (24 vendors).
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/vendor-directory-timing.php
 *
 * @package LNKup_Marketplace
 */

$org = (int) ( get_users( array( 'role' => 'lnkup_organizer', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
wp_set_current_user( $org );
$query = lnkup_get_discoverable_vendors( array( 'per_page' => 24, 'organizer_id' => $org ) );
$ids   = wp_list_pluck( $query['posts'], 'ID' );
printf( "organizer %d, %d vendors on the page (%d discoverable)\n", $org, count( $ids ), $query['total'] );

foreach ( array( 'get', 'get_card' ) as $method ) {
	wp_cache_flush();
	global $wpdb;
	$queries = $wpdb->num_queries;
	$start   = microtime( true );
	foreach ( $ids as $id ) {
		LNKup_Vendor_CRM_Profile::$method( $id, $org );
	}
	printf( "%-9s %6.0f ms  %5d queries\n", $method, ( microtime( true ) - $start ) * 1000, $wpdb->num_queries - $queries );
}

wp_cache_flush();
LNKup_Vendor_Directory::flush_discover_cache( $org );
$start = microtime( true );
LNKup_Vendor_Directory::search( $org, array( 'scope' => 'discover', 'html' => 0, 'per_page' => 12 ) );
printf( "app request (12, html=0) cold: %.0f ms\n", ( microtime( true ) - $start ) * 1000 );
$start = microtime( true );
LNKup_Vendor_Directory::search( $org, array( 'scope' => 'discover', 'html' => 0, 'per_page' => 12 ) );
printf( "app request repeat (cached):  %.0f ms\n", ( microtime( true ) - $start ) * 1000 );
