<?php
/**
 * Toggle Event Creation OS flags on a local site.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/event-os-flags.php on event_builder_v3 event_import_v3
 *      wp eval-file wp-content/lnkup-e2e/php/event-os-flags.php off all
 */

$action = isset( $args[0] ) ? (string) $args[0] : 'status';
$flags  = array_slice( (array) $args, 1 );
if ( array( 'all' ) === $flags ) {
	$flags = array_keys( LNKup_Event_OS_Flags::FLAGS );
}
if ( in_array( $action, array( 'on', 'off' ), true ) ) {
	if ( 'local' !== wp_get_environment_type() ) {
		echo "Refusing to change flags outside a local environment.\n";
		exit( 1 );
	}
	foreach ( $flags as $flag ) {
		LNKup_Event_OS_Flags::set( (string) $flag, 'on' === $action );
	}
}
foreach ( LNKup_Event_OS_Flags::all() as $flag => $on ) {
	echo esc_html( $flag ) . '=' . ( $on ? 'on' : 'off' ) . "\n";
}
