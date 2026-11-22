<?php
// Temporary diagnostic: which route groups are registered and is the category taxonomy present.
$routes = array_keys( rest_get_server()->get_routes() );
foreach ( array( '/lnkup/v1/financial/dashboard', '/lnkup/v1/trust', '/lnkup/v1/applications/apply-with-profile' ) as $prefix ) {
	echo $prefix . ': ' . count( array_filter( $routes, static function ( $r ) use ( $prefix ) {
		return 0 === strpos( $r, $prefix );
	} ) ) . "\n";
}
$terms = get_terms( array( 'taxonomy' => 'lnkup_vendor_category', 'hide_empty' => false, 'number' => 1, 'fields' => 'names' ) );
echo 'terms: ' . ( is_wp_error( $terms ) ? $terms->get_error_code() . ' ' . $terms->get_error_message() : 'ok ' . count( $terms ) ) . "\n";
echo 'financial class: ' . ( class_exists( 'LNKup_Financial_REST' ) ? 'yes' : 'no' ) . "\n";
