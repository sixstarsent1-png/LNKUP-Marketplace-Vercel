<?php
/**
 * Idempotently create an active City Connector and an active affiliate login for Playwright
 * (local wp-env only).
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/partnerships-seed-connector.php
 * Then: PLAYWRIGHT_CONNECTOR_USER=pw_connector PLAYWRIGHT_CONNECTOR_PASS=pw-connector-pass
 *       PLAYWRIGHT_AFFILIATE_USER=pw_affiliate PLAYWRIGHT_AFFILIATE_PASS=pw-affiliate-pass
 *
 * @package LNKup_Marketplace
 */

if ( 'local' !== wp_get_environment_type() ) {
	echo "Refusing to seed outside a local environment.\n";
	exit( 1 );
}

/**
 * Create or refresh one partner login and its active account row.
 */
function lnkup_seed_partner_account( string $login, string $pass, string $program, string $role, string $name ): int {
	global $wpdb;
	$email = $login . '@example.test';
	$user  = get_user_by( 'login', $login );
	if ( ! $user ) {
		$id   = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => $pass, 'role' => $role, 'display_name' => $name ) );
		$user = get_user_by( 'id', (int) $id );
	} else {
		wp_set_password( $pass, $user->ID );
		$user = get_user_by( 'id', $user->ID );
		$user->add_role( $role );
	}
	$table     = LNKup_Connector_Tables::connectors_table();
	$connector = LNKup_Connector::get_by_user_id( (int) $user->ID );
	$now       = current_time( 'mysql' );
	if ( ! $connector ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, array( 'user_id' => (int) $user->ID, 'program' => $program, 'display_name' => $name, 'email' => $email, 'city' => 'Charlotte', 'state' => 'NC', 'market_slug' => 'charlotte', 'status' => 'active', 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
		$connector = LNKup_Connector::get( (int) $wpdb->insert_id );
	} else {
		$wpdb->update( $table, array( 'status' => 'active', 'program' => $program ), array( 'id' => (int) $connector['id'] ) );
	}
	LNKup_Connector::generate_code( (int) $connector['id'] );
	return (int) $connector['id'];
}

echo 'connector_id=' . lnkup_seed_partner_account( 'pw_connector', 'pw-connector-pass', 'city_connector', 'lnkup_connector', 'PW Connector' ) . "\n";
echo 'affiliate_id=' . lnkup_seed_partner_account( 'pw_affiliate', 'pw-affiliate-pass', 'affiliate', 'lnkup_partner', 'PW Affiliate' ) . "\n";
echo "SEEDED\n";
