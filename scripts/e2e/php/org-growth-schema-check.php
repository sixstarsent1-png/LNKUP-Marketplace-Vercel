<?php
/**
 * Organizer Growth schema + migration check.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/org-growth-schema-check.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Organizer Growth schema\n";

lnkup_t_same( LNKUP_VERSION, get_option( 'lnkup_db_version' ), 'plugin migration ran for this version' );
lnkup_t_same( LNKup_Org_Growth_Tables::SCHEMA_VERSION, get_option( LNKup_Org_Growth_Tables::SCHEMA_OPTION ), 'growth schema version recorded' );

foreach ( array( 'LEADS', 'LEAD_EVENTS', 'ACCOUNTS', 'ACTIVITY', 'TASKS', 'TAGS', 'VIEWS', 'IMPORTS', 'IMPORT_ROWS', 'MILESTONES', 'PARTNERS', 'TEMPLATES', 'SUPPRESSION' ) as $const ) {
	lnkup_t_assert( LNKup_Org_Growth_Tables::exists( constant( 'LNKup_Org_Growth_Tables::' . $const ) ), 'table ' . constant( 'LNKup_Org_Growth_Tables::' . $const ) . ' exists' );
}

$cols = static function ( string $table ): array {
	global $wpdb;
	return array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ); // phpcs:ignore
};
lnkup_t_assert( in_array( 'refunded_platform_fee', $cols( LNKup_Treasury_Tables::platform_fees_table() ), true ), 'platform_fees.refunded_platform_fee added' );
lnkup_t_assert( in_array( 'reversed_amount', $cols( LNKup_Treasury_Tables::referral_rewards_table() ), true ), 'referral_rewards.reversed_amount added' );
$rec = $cols( LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS ) );
foreach ( array( 'lead_id', 'template_key', 'skip_reason' ) as $c ) {
	lnkup_t_assert( in_array( $c, $rec, true ), "outreach_recipients.{$c} added" );
}
$cam = $cols( LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::CAMPAIGNS ) );
foreach ( array( 'template_key', 'approval_status', 'approved_by', 'daily_cap' ) as $c ) {
	lnkup_t_assert( in_array( $c, $cam, true ), "outreach_campaigns.{$c} added" );
}

LNKup_Org_Outreach::maybe_seed_templates();
lnkup_t_same( 12, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Org_Outreach::templates_table() . ' WHERE is_seeded = 1' ), '12 outreach templates seeded' ); // phpcs:ignore

lnkup_t_assert( get_role( 'administrator' )->has_cap( LNKup_Org_Growth::CAP ), 'administrator has lnkup_manage_organizer_growth' );
lnkup_t_assert( get_role( 'lnkup_admin' ) && get_role( 'lnkup_admin' )->has_cap( LNKup_Org_Growth::CAP ), 'lnkup_admin has lnkup_manage_organizer_growth' );
lnkup_t_assert( ! get_role( 'lnkup_connector' )->has_cap( LNKup_Org_Growth::CAP ), 'connectors cannot manage the lead CRM' );
lnkup_t_assert( ! get_role( 'lnkup_organizer' )->has_cap( LNKup_Org_Growth::CAP ), 'organizers cannot manage the lead CRM' );

$routes = rest_get_server()->get_routes();
$growth = array_filter( array_keys( $routes ), static fn( $r ) => 0 === strpos( $r, '/lnkup/v1/growth' ) );
lnkup_t_assert( count( $growth ) >= 35, 'growth REST routes registered (' . count( $growth ) . ')' );
$missing = array();
foreach ( $growth as $route ) {
	foreach ( $routes[ $route ] as $handler ) {
		if ( empty( $handler['permission_callback'] ) ) {
			$missing[] = $route;
		}
	}
}
lnkup_t_same( array(), $missing, 'every growth route has a permission_callback' );

$readiness = LNKup_Org_Growth::readiness();
lnkup_t_same( array( 'postal_address', 'house_ids', 'comms', 'cron', 'fee_backfill', 'outreach_global' ), wp_list_pluck( $readiness, 'key' ), 'readiness panel lists the six go-live checks' );

lnkup_t_finish();
