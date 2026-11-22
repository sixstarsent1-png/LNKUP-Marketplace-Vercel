<?php
/**
 * Writers that run concurrently (index rebuilds, category sync, badge awards, deferred upgrade)
 * must never fail on duplicate keys, and badge re-awarding must respect admin revocations.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/upsert-race-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Duplicate-safe writers\n";

/**
 * Create a published post that is removed at the end of the run.
 *
 * @param string $type Post type.
 * @param array  $meta Meta key => value.
 */
function lnkup_t_post( string $type, array $meta = array() ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Race test ' . $type, 'meta_input' => $meta ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

$organizer = lnkup_t_user( 'lnkup_organizer' );
$admin     = lnkup_t_user( 'administrator' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );

// 1. Marketplace index upsert.
$event = lnkup_t_post( 'lnkup_event', array( '_lnkup_organizer_user_id' => $organizer, '_lnkup_lat' => '35.2', '_lnkup_lng' => '-80.8', '_lnkup_city' => 'Charlotte' ) );
$opp   = lnkup_t_post( 'lnkup_opportunity', array( '_lnkup_event_id' => $event, '_lnkup_opportunity_status' => 'open', '_lnkup_vendor_fee' => '250', '_lnkup_spots_total' => 10 ) );
$index = LNKup_Marketplace_Index::table_name();
$wpdb->last_error = '';
LNKup_Marketplace_Index::sync_opportunity( $opp );
LNKup_Marketplace_Index::sync_opportunity( $opp );
lnkup_t_same( '', $wpdb->last_error, 'repeated index sync raises no database error' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$index} WHERE opportunity_id = %d", $opp ) ), 'one index row per opportunity' );
update_post_meta( $opp, '_lnkup_vendor_fee', '300' );
LNKup_Marketplace_Index::sync_opportunity( $opp );
lnkup_t_same( '300.00', $wpdb->get_var( $wpdb->prepare( "SELECT vendor_fee FROM {$index} WHERE opportunity_id = %d", $opp ) ), 'upsert updates existing row' );
delete_post_meta( $event, '_lnkup_lat' );
delete_post_meta( $event, '_lnkup_lng' );
LNKup_Marketplace_Index::sync_opportunity( $opp );
lnkup_t_same( null, $wpdb->get_var( $wpdb->prepare( "SELECT lat FROM {$index} WHERE opportunity_id = %d", $opp ) ), 'missing coordinates stored as NULL, not 0' );
lnkup_t_same( '', $wpdb->last_error, 'NULL columns raise no database error' );

// 2. Rebuild is skipped while another rebuild holds the lock.
$lock = 'lnkup_marketplace_index_rebuild';
$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$held  = (int) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
lnkup_t_same( 0, $held, 'index rebuild lock is exclusive across connections' );
$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
$other->query( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
lnkup_t_same( 0, LNKup_Marketplace_Index::rebuild_all(), 'rebuild skips while another process rebuilds' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$index} WHERE opportunity_id = %d", $opp ) ), 'skipped rebuild did not truncate the index' );
$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );

// 3. Match category sync.
if ( class_exists( 'LNKup_Match_Categories' ) ) {
	$term = wp_insert_term( 'Race Test Category ' . wp_generate_password( 4, false ), LNKup_Match_Categories::TAXONOMY );
	$tid  = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
	wp_set_object_terms( $opp, array( $tid ), LNKup_Match_Categories::TAXONOMY );
	$cats = LNKup_Match_Tables::opportunity_categories_table();
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$cats} (opportunity_id, term_id, group_key) VALUES (%d, %d, 'other')", $opp, $tid ) );
	$wpdb->last_error = '';
	LNKup_Match_Categories::sync_opportunity( $opp );
	LNKup_Match_Categories::sync_opportunity( $opp );
	lnkup_t_same( '', $wpdb->last_error, 'repeated category sync raises no database error' );
	lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$cats} WHERE opportunity_id = %d AND term_id = %d", $opp, $tid ) ), 'one category row per pair' );
	$wpdb->delete( $cats, array( 'opportunity_id' => $opp ) );
	wp_delete_term( $tid, LNKup_Match_Categories::TAXONOMY );
}

// 4. Badges: idempotent, reinstated after automatic revocation, never after an admin's.
$profile = lnkup_t_post( 'lnkup_vendor_profile', array( '_lnkup_owner_user_id' => $vendor ) );
$badges  = LNKup_Reputation_Tables::badge_table();
$wpdb->last_error = '';
$first  = LNKup_Vendor_Badges::award( $profile, 'profile_complete' );
$second = LNKup_Vendor_Badges::award( $profile, 'profile_complete' );
lnkup_t_assert( $first > 0 && $first === $second, 'awarding twice returns the same badge' );
lnkup_t_same( '', $wpdb->last_error, 'repeat award raises no database error' );

LNKup_Vendor_Badges::revoke( $profile, 'profile_complete', $vendor );
lnkup_t_same( $first, LNKup_Vendor_Badges::award( $profile, 'profile_complete' ), 'automatically revoked badge can be earned again' );
lnkup_t_same( null, $wpdb->get_var( $wpdb->prepare( "SELECT revoked_at FROM {$badges} WHERE id = %d", $first ) ), 'reinstated badge is active' );

LNKup_Vendor_Badges::revoke( $profile, 'profile_complete', $admin );
lnkup_t_same( false, LNKup_Vendor_Badges::award( $profile, 'profile_complete' ), 'admin-revoked badge is not re-awarded' );
lnkup_t_assert( null !== $wpdb->get_var( $wpdb->prepare( "SELECT revoked_at FROM {$badges} WHERE id = %d", $first ) ), 'admin revocation stands' );
lnkup_t_same( '', $wpdb->last_error, 'no database errors across award/revoke' );
$wpdb->delete( $badges, array( 'vendor_profile_id' => $profile ) );

// 5. Deferred upgrade never runs in background requests, nor for another version.
set_current_screen( 'dashboard' );
wp_set_current_user( $admin );
update_option( 'lnkup_needs_deferred_upgrade', LNKUP_VERSION );
add_filter( 'wp_doing_ajax', '__return_true' );
LNKup_Activator::maybe_run_deferred_upgrade();
remove_filter( 'wp_doing_ajax', '__return_true' );
lnkup_t_same( LNKUP_VERSION, get_option( 'lnkup_needs_deferred_upgrade' ), 'deferred upgrade skipped during AJAX' );
update_option( 'lnkup_needs_deferred_upgrade', '0.0.1' );
LNKup_Activator::run_deferred_upgrade_locked();
lnkup_t_same( '0.0.1', get_option( 'lnkup_needs_deferred_upgrade' ), 'locked runner re-checks the version' );
delete_option( 'lnkup_needs_deferred_upgrade' );
wp_set_current_user( 0 );

$wpdb->delete( $index, array( 'opportunity_id' => $opp ) );
lnkup_t_finish();
