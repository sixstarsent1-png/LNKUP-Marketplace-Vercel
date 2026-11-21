<?php
/**
 * Hourly reconciliation: picks up recent unsettled payments, records health, skips while
 * another run holds the lock, and ignores payments older than the auto-recovery cutoff.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/financial-cron-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Hourly reconciliation\n";

$organizer = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );
$base      = array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'vendor_spot_fee' => '200.00', 'total_amount' => '217.99', 'payment_status' => 'paid' );

$recent = lnkup_t_payment( array_merge( $base, array( 'paid_at' => current_time( 'mysql' ), 'stripe_payment_intent' => 'sim_hourly' ) ) );
$old    = lnkup_t_payment( array_merge( $base, array( 'paid_at' => '2026-01-02 10:00:00', 'stripe_payment_intent' => 'sim_hourly_old' ) ) );

$queue = LNKup_Financial_Cron::users_needing_sync();
lnkup_t_assert( in_array( array( 'user_id' => $organizer, 'role' => 'organizer' ), $queue, true ), 'organizer with a recent unsettled payment is queued' );

$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$other->get_var( "SELECT GET_LOCK('lnkup_fin_hourly', 0)" );
$skipped = LNKup_Financial_Cron::run_hourly();
$other->get_var( "SELECT RELEASE_LOCK('lnkup_fin_hourly')" );
lnkup_t_same( true, $skipped['skipped'], 'run is skipped while another run holds the lock' );
lnkup_t_assert( '' === (string) get_post_meta( $recent, '_lnkup_settled_at', true ), 'skipped run changed nothing' );

$run = LNKup_Financial_Cron::run_hourly();
lnkup_t_same( false, $run['skipped'], 'run proceeds once the lock is free' );
lnkup_t_assert( in_array( 'organizer:' . $organizer, $run['synced'], true ), 'organizer was synced' );
lnkup_t_assert( '' !== (string) get_post_meta( $recent, '_lnkup_settled_at', true ), 'recent unsettled payment is settled by the hourly run' );
lnkup_t_assert( '' === (string) get_post_meta( $old, '_lnkup_settled_at', true ), 'payment older than the cutoff is left for approved repair' );
lnkup_t_same( $run['ran_at'], get_option( LNKup_Financial_Cron::HEALTH_OPTION )['ran_at'] ?? null, 'health option records the run' );

$queue = LNKup_Financial_Cron::users_needing_sync();
lnkup_t_assert( ! in_array( array( 'user_id' => $organizer, 'role' => 'organizer' ), $queue, true ), 'settled organizer leaves the queue' );

lnkup_t_finish();
