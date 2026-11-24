<?php
/**
 * Regression cases for LNKup_Location_Service (run inside WordPress: wp eval-file).
 *
 * Prints one line per case and exits non-zero on any mismatch.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

$cases = array(
	// city, state, country, expected status, expected display (or first suggestion).
	array( 'Atlanta', 'GE', '', 'suggested', 'Atlanta, GA' ),
	array( 'Atlanta', 'GA', '', 'verified', 'Atlanta, GA' ),
	array( 'Atlanta', 'Georgia', '', 'normalized', 'Atlanta, GA' ),
	array( 'atlanta ga', '', '', 'normalized', 'Atlanta, GA' ),
	array( 'Charlotte NC', '', '', 'normalized', 'Charlotte, NC' ),
	array( 'Charlotte', 'North Carolina', '', 'normalized', 'Charlotte, NC' ),
	array( 'Houston Texas', '', '', 'normalized', 'Houston, TX' ),
	array( 'Washington D.C.', '', '', 'normalized', 'Washington, DC' ),
	array( 'Washington', 'DC', '', 'verified', 'Washington, DC' ),
	array( 'Los Angeles', 'CA', '', 'verified', 'Los Angeles, CA' ),
	array( 'Virginia Beach', 'VA', '', 'verified', 'Virginia Beach, VA' ),
	array( 'Columbus', '', '', 'ambiguous', 'Columbus, OH' ),
	array( 'Atlanta', 'ZZ', '', 'suggested', 'Atlanta, GA' ),
	array( 'Toronto', 'ON', 'Canada', 'normalized', 'Toronto, ON' ),
	array( 'Charolette', 'NC', '', 'suggested', 'Charlotte, NC' ),
	array( 'ATL', '', '', 'normalized', 'Atlanta, GA' ),
	array( 'Smallville', 'KS', '', 'normalized', 'Smallville, KS' ),
	array( 'Nowhereville', '', '', 'unresolved', 'Nowhereville' ),
	array( 'Columbus', 'GA', '', 'verified', 'Columbus, GA' ),
	array( 'Alvin tx', 'Tx', '', 'normalized', 'Alvin, TX' ),
	array( 'Birmingham Alabama', 'Al', '', 'normalized', 'Birmingham, AL' ),
	array( 'Pheonix', 'AZ', '', 'suggested', 'Phoenix, AZ' ),
	array( 'RDU', 'NC', '', 'normalized', 'RDU, NC' ),
	array( 'Winston Salem', 'NC', '', 'normalized', 'Winston-Salem, NC' ),
	array( 'Charlotte, NC, USA', '', '', 'normalized', 'Charlotte, NC' ),
);

$fail = 0;
foreach ( $cases as $case ) {
	list( $city, $state, $country, $want_status, $want_display ) = $case;
	$r       = LNKup_Location_Service::resolve( $city, $state, $country );
	$display = in_array( $r['status'], array( 'suggested', 'ambiguous' ), true ) && ! empty( $r['suggestions'] ) ? $r['suggestions'][0]['display'] : $r['display'];
	$ok      = $r['status'] === $want_status && $display === $want_display;
	$fail   += $ok ? 0 : 1;
	printf( "%s  %-28s => %-10s %-22s %s\n", $ok ? 'PASS' : 'FAIL', trim( "$city, $state $country", ', ' ), $r['status'], $display, $ok ? '' : "(want $want_status $want_display)" );
}

$strict = LNKup_Location_Service::validate_for_save( 'Atlanta', 'GE', '', array( 'mode' => 'strict' ) );
$ok     = is_wp_error( $strict ) && 422 === ( $strict->get_error_data()['status'] ?? 0 );
$fail  += $ok ? 0 : 1;
printf( "%s  strict save of Atlanta, GE returns 422\n", $ok ? 'PASS' : 'FAIL' );

$lenient = LNKup_Location_Service::validate_for_save( 'Atlanta', 'GE', '', array( 'mode' => 'lenient' ) );
$ok      = is_array( $lenient ) && 'suggested' === $lenient['status'] && 'GE' === $lenient['state_code'];
$fail   += $ok ? 0 : 1;
printf( "%s  lenient save keeps input and flags it (%s, %s)\n", $ok ? 'PASS' : 'FAIL', $lenient['status'] ?? '', $lenient['state_code'] ?? '' );

$evidence = LNKup_Location_Service::resolve( 'Atlanta', 'GE', '', array( 'lat' => 33.75, 'lng' => -84.39 ) );
$ok       = ! empty( $evidence['evidence'] );
$fail    += $ok ? 0 : 1;
printf( "%s  coordinates in Atlanta count as evidence for GA\n", $ok ? 'PASS' : 'FAIL' );

$ok    = 'atlanta' === LNKup_Location_Service::market_for( 'Marietta', 'GA' ) && 'atlanta' === LNKup_Location_Service::market_for( 'Atlanta', 'GA' ) && '' === LNKup_Location_Service::market_for( 'Marietta', 'OH' );
$fail += $ok ? 0 : 1;
printf( "%s  market_for: Marietta GA => atlanta metro, Marietta OH => none\n", $ok ? 'PASS' : 'FAIL' );

$s     = LNKup_Location_Service::suggest( 'Columbus', 5 );
$ok    = count( $s ) >= 3 && 'Columbus, OH' === $s[0]['display'] && ! in_array( 'Columbia, SC', wp_list_pluck( $s, 'display' ), true );
$fail += $ok ? 0 : 1;
printf( "%s  suggest(Columbus) lists several states, %s\n", $ok ? 'PASS' : 'FAIL', implode( ' | ', wp_list_pluck( $s, 'display' ) ) );

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASSED\n";
