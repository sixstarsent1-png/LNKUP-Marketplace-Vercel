<?php
/**
 * Phases 8-9: versioned matching rules, preview (simulator) and revert.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/match-tuning-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Matching tuning\n";

$GLOBALS['lnkup_mt_settings'] = get_option( 'lnkup_settings', array() );
$GLOBALS['lnkup_mt_versions'] = get_option( LNKup_Match_Settings::VERSIONS_OPTION, null );
$GLOBALS['lnkup_mt_version']  = get_option( LNKup_Match_Settings::VERSION_OPTION, null );
$GLOBALS['lnkup_mt_log']      = get_option( 'lnkup_match_settings_log', null );
$GLOBALS['lnkup_mt_backfill'] = get_option( 'lnkup_match_backfill_page', null );
update_option(
	'lnkup_settings',
	array_merge(
		(array) $GLOBALS['lnkup_mt_settings'],
		array(
			'matching_enabled'                  => true,
			'matching_vendor_discovery_enabled' => true,
			'matching_tours_enabled'            => true,
			'match_min_score'                   => 40,
			'platform_prelaunch_mode'           => false,
		)
	),
	false
);
delete_option( LNKup_Match_Settings::VERSIONS_OPTION );
delete_option( LNKup_Match_Settings::VERSION_OPTION );

LNKup_Match_Categories::seed_groups();
$food            = term_exists( 'Food Vendor', 'lnkup_vendor_category' );
$GLOBALS['food'] = (int) ( is_array( $food ) ? $food['term_id'] : $food );

/**
 * Charlotte food opportunity, indexed.
 */
function lnkup_mt_opp( int $organizer ): int {
	$event = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'draft', 'post_title' => 'Tuning test ' . wp_generate_password( 4, false ), 'post_author' => $organizer ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $event );
	// Concord, about 18 miles from the vendor: inside the radius but not a perfect geography score.
	foreach ( array( 'organizer_user_id' => $organizer, 'city' => 'Concord', 'state' => 'NC', 'lat' => '35.41', 'lng' => '-80.58', 'event_date' => wp_date( 'Y-m-d', time() + 60 * DAY_IN_SECONDS ) ) as $key => $value ) {
		update_post_meta( $event, '_lnkup_' . $key, $value );
	}
	wp_update_post( array( 'ID' => $event, 'post_status' => 'publish' ) );
	$opp = wp_insert_post( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'publish', 'post_title' => 'Tuning booth', 'post_author' => $organizer ) );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $opp );
	foreach ( array( 'event_id' => $event, 'organizer_user_id' => $organizer, 'opportunity_status' => 'open', 'spots_total' => 10, 'spots_remaining' => 10, 'vendor_fee' => 100, 'required_documents' => 'none', 'application_deadline' => wp_date( 'Y-m-d', time() + 50 * DAY_IN_SECONDS ) ) as $key => $value ) {
		update_post_meta( $opp, '_lnkup_' . $key, $value );
	}
	wp_set_object_terms( $opp, array( $GLOBALS['food'] ), 'lnkup_vendor_category' );
	LNKup_Marketplace_Index::sync_opportunity( $opp );
	return (int) $opp;
}

$org     = lnkup_t_user( 'lnkup_organizer' );
$vendor  = lnkup_t_user( 'lnkup_vendor' );
$profile = wp_insert_post( array( 'post_type' => 'lnkup_vendor_profile', 'post_status' => 'publish', 'post_title' => 'Tuning vendor' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $profile );
foreach ( array( 'owner_user_id' => $vendor, 'business_name' => 'Tuning vendor', 'city' => 'Charlotte', 'state' => 'NC', 'geo_lat' => '35.23', 'geo_lng' => '-80.84' ) as $key => $value ) {
	update_post_meta( $profile, '_lnkup_' . $key, $value );
}
update_post_meta( $profile, LNKup_Match_Vendor_Index::GEO_KEY_META, 'charlotte|nc|' );
wp_set_object_terms( $profile, array( $GLOBALS['food'] ), 'lnkup_vendor_category' );
LNKup_Match_REST::apply_preferences( $profile, array( 'primary_category' => $GLOBALS['food'], 'cities_served' => array( 'Charlotte' ) ) );
$opp = lnkup_mt_opp( $org );
LNKup_Match_Service::compute_for_vendor( $profile );
$results = LNKup_Match_Tables::results_table();

echo "Rules versions\n";
$v0      = LNKup_Match_Settings::rules_version();
$no_note = LNKup_Match_Settings::save( array( 'match_weight_geo' => 30 ) );
lnkup_t_assert( is_wp_error( $no_note ) && 'lnkup_match_note_required' === $no_note->get_error_code(), 'a ranking change without a note is refused' );
lnkup_t_same( 25, LNKup_Match_Settings::weights()['geo'], 'and nothing was saved' );

delete_option( 'lnkup_match_backfill_page' );
$flag_only = LNKup_Match_Settings::save( array( 'matching_tours_enabled' => 0, 'match_invite_batch_max' => 30 ) );
lnkup_t_assert( ! is_wp_error( $flag_only ), 'flag and cap changes need no note' );
lnkup_t_same( $v0, LNKup_Match_Settings::rules_version(), 'flag and cap changes do not create a rules version' );
lnkup_t_same( false, get_option( 'lnkup_match_backfill_page' ), 'flag and cap changes do not queue a full rebuild' );
lnkup_t_same( true, LNKup_Match_Settings::active( 'matching_vendor_discovery_enabled' ), 'flags missing from the input keep their value' );

$saved = LNKup_Match_Settings::save( array( 'match_weight_geo' => 30 ), 'Geography matters more' );
lnkup_t_assert( ! is_wp_error( $saved ), 'ranking change with a note is saved' );
$v1 = LNKup_Match_Settings::rules_version();
lnkup_t_same( $v0 + 1, $v1, 'a new rules version is created' );
lnkup_t_same( 1, (int) get_option( 'lnkup_match_backfill_page' ), 'a ranking change queues a full rebuild' );
$versions = LNKup_Match_Settings::versions();
lnkup_t_same( array( $v1, $v0 ), array_map( 'intval', wp_list_pluck( array_slice( $versions, 0, 2 ), 'version' ) ), 'history keeps the new version and the previous settings' );
lnkup_t_same( 'Geography matters more', $versions[0]['note'], 'the note is stored' );
lnkup_t_same( 25, (int) $versions[1]['settings']['match_weight_geo'], 'the previous version keeps the old weight' );

LNKup_Match_Service::compute_for_vendor( $profile );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
lnkup_t_same( $v1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT rules_version FROM {$results} WHERE profile_id = %d AND opportunity_id = %d", $profile, $opp ) ), 'results record the rules version that produced them' );

echo "Preview\n";
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$snapshot = static function () use ( $wpdb, $results ) {
	return $wpdb->get_var( "SELECT CONCAT(COUNT(*), '|', COALESCE(MAX(computed_at), ''), '|', COALESCE(SUM(score), 0)) FROM {$results}" );
};
// phpcs:enable
$before   = $snapshot();
$settings = LNKup_Match_Settings::current_rules();
$strict   = LNKup_Match_Simulator::preview( array( 'match_min_score' => 100 ), 2000 );
lnkup_t_assert( empty( $strict['error'] ), 'preview runs' );
lnkup_t_assert( $strict['vendors'] > 0 && $strict['pairs'] > 0, 'preview scored a sample (' . $strict['vendors'] . ' vendors, ' . $strict['pairs'] . ' pairs)' );
lnkup_t_assert( $strict['before']['recommended'] > 0, 'current rules recommend something in the sample' );
lnkup_t_assert( $strict['after']['recommended'] < $strict['before']['recommended'], 'a minimum score of 100 recommends fewer pairs' );
lnkup_t_assert( count( $strict['lost'] ) > 0 && isset( $strict['lost'][0]['reason'] ), 'examples of lost matches come with a reason' );
$loose = LNKup_Match_Simulator::preview( array( 'match_min_score' => 0 ), 2000 );
lnkup_t_assert( $loose['after']['recommended'] >= $loose['before']['recommended'], 'a minimum score of 0 never recommends fewer pairs' );
lnkup_t_assert( ! empty( $loose['factor_contribution'] ), 'factor contribution is reported' );
lnkup_t_same( $before, $snapshot(), 'preview writes nothing to cached results' );
lnkup_t_same( $settings, LNKup_Match_Settings::current_rules(), 'preview does not change settings' );
lnkup_t_same( $v1, LNKup_Match_Settings::rules_version(), 'preview does not create a version' );

echo "Revert\n";
$reverted = LNKup_Match_Settings::revert( $v0, 'Back to baseline' );
lnkup_t_assert( ! is_wp_error( $reverted ), 'revert succeeds' );
lnkup_t_same( 25, LNKup_Match_Settings::weights()['geo'], 'weights return to the old version' );
$latest = LNKup_Match_Settings::versions()[0];
lnkup_t_same( array( $v1 + 1, $v0 ), array( (int) $latest['version'], (int) ( $latest['reverted_from'] ?? -1 ) ), 'revert is recorded as a new version that points at the old one' );
lnkup_t_assert( 0 === strpos( $latest['note'], 'Revert to v' . $v0 ), 'revert note names the version' );
lnkup_t_assert( is_wp_error( LNKup_Match_Settings::revert( 9999 ) ), 'reverting to a missing version is refused' );

echo "Admin screens render\n";
$admin = lnkup_t_user( 'administrator' );
wp_set_current_user( $admin );
$user = wp_get_current_user();
$user->add_cap( 'lnkup_manage_all' );
foreach ( array( 'overview', 'rules', 'rollout' ) as $tab ) {
	$_GET['tab'] = $tab;
	ob_start();
	try {
		LNKup_Match_Admin::render_page();
		$html = (string) ob_get_clean();
	} catch ( Throwable $e ) {
		ob_end_clean();
		$html = 'EXCEPTION ' . $e->getMessage();
	}
	lnkup_t_assert( false === strpos( $html, 'EXCEPTION' ) && false === stripos( $html, 'Fatal' ) && strlen( $html ) > 500, 'Marketplace Intelligence "' . $tab . '" tab renders' );
}
unset( $_GET['tab'] );

update_option( 'lnkup_settings', $GLOBALS['lnkup_mt_settings'], false );
foreach ( array( LNKup_Match_Settings::VERSIONS_OPTION => 'lnkup_mt_versions', LNKup_Match_Settings::VERSION_OPTION => 'lnkup_mt_version', 'lnkup_match_settings_log' => 'lnkup_mt_log', 'lnkup_match_backfill_page' => 'lnkup_mt_backfill' ) as $option => $saved_key ) {
	if ( null === $GLOBALS[ $saved_key ] ) {
		delete_option( $option );
	} else {
		update_option( $option, $GLOBALS[ $saved_key ], false );
	}
}
lnkup_t_finish();
