<?php
/**
 * Idempotently create an active City Connector login for Playwright (local wp-env only).
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/partnerships-seed-connector.php
 * Then: PLAYWRIGHT_CONNECTOR_USER=pw_connector PLAYWRIGHT_CONNECTOR_PASS=pw-connector-pass
 *
 * @package LNKup_Marketplace
 */

if ( 'local' !== wp_get_environment_type() ) {
	echo "Refusing to seed outside a local environment.\n";
	exit( 1 );
}

global $wpdb;
$login = 'pw_connector';
$pass  = 'pw-connector-pass';
$user  = get_user_by( 'login', $login );
if ( ! $user ) {
	$id   = wp_insert_user( array( 'user_login' => $login, 'user_email' => 'pw_connector@example.test', 'user_pass' => $pass, 'role' => 'lnkup_connector', 'display_name' => 'PW Connector' ) );
	$user = get_user_by( 'id', (int) $id );
} else {
	wp_set_password( $pass, $user->ID );
	$user->add_role( 'lnkup_connector' );
}
$table     = LNKup_Connector_Tables::connectors_table();
$connector = LNKup_Connector::get_by_user_id( (int) $user->ID );
$now       = current_time( 'mysql' );
if ( ! $connector ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->insert( $table, array( 'user_id' => (int) $user->ID, 'display_name' => 'PW Connector', 'email' => 'pw_connector@example.test', 'city' => 'Charlotte', 'state' => 'NC', 'market_slug' => 'charlotte', 'status' => 'active', 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
	$connector = LNKup_Connector::get( (int) $wpdb->insert_id );
} else {
	$wpdb->update( $table, array( 'status' => 'active' ), array( 'id' => (int) $connector['id'] ) );
}
LNKup_Connector::generate_code( (int) $connector['id'] );
echo 'connector_id=' . (int) $connector['id'] . "\nSEEDED\n";
