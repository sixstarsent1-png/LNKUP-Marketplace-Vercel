<?php
/**
 * Verifies the 12.22 financial schema upgrade on the current site.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/financial-schema-check.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
$table = LNKup_Treasury_Tables::organizer_wallet_entries_table();

echo "Financial schema upgrade\n";
lnkup_t_same( LNKUP_VERSION, get_option( 'lnkup_db_version' ), 'DB version matches plugin version' );
lnkup_t_assert( '' !== (string) get_option( 'lnkup_fin_auto_recovery_since', '' ), 'auto-recovery cutoff is stamped' );
lnkup_t_assert( (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'idempotency_key'" ), 'ledger has idempotency_key column' );
$index = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'idempotency_key'", ARRAY_A );
lnkup_t_assert( ! empty( $index ) && '0' === (string) $index[0]['Non_unique'], 'idempotency_key index is unique' );
lnkup_t_assert( (bool) wp_next_scheduled( LNKup_Financial_Cron::HOURLY_HOOK ), 'hourly reconciliation is scheduled' );
$entry_type = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'entry_type'", ARRAY_A );
lnkup_t_same( 'varchar(32)', strtolower( (string) ( $entry_type['Type'] ?? '' ) ), 'entry_type fits connect_settlement (18 chars)' );

lnkup_t_finish();
