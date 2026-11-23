<?php
/**
 * Matching Rules form: blank threshold boxes (never saved) must not block turning flags on
 * or overwrite thresholds with 0/1.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/match-settings-blank-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

echo "Matching settings: blank thresholds\n";

$admin = lnkup_t_user( 'administrator' );
wp_set_current_user( $admin );

$original = get_option( 'lnkup_settings', array() );
$settings = is_array( $original ) ? $original : array();
foreach ( LNKup_Match_Settings::keys() as $key ) {
	unset( $settings[ $key ] );
}
update_option( 'lnkup_settings', $settings, false );

// Match a real wp-admin save: admin_init registers the main Settings sanitizer on lnkup_settings.
LNKup_Admin::register_settings();

// What the form posts on a fresh site: flags checked, weights prefilled, thresholds blank.
$post = array(
	'matching_enabled'                     => '1',
	'matching_organizer_discovery_enabled' => '1',
	'match_min_score'                      => '',
	'match_invite_vendor_weekly_cap'       => '',
	'match_invite_org_daily_cap'           => '',
	'match_invite_batch_max'               => '',
);
foreach ( LNKup_Match_Settings::WEIGHT_KEYS as $factor => $key ) {
	$post[ $key ] = (string) LNKup_Match_Settings::DEFAULT_WEIGHTS[ $factor ];
}

$result = LNKup_Match_Settings::save( LNKup_Match_Admin::input_from_post( $post ), '' );
lnkup_t_assert( ! is_wp_error( $result ), 'turning flags on with blank thresholds saves without a note' . ( is_wp_error( $result ) ? ' (' . $result->get_error_message() . ')' : '' ) );
lnkup_t_assert( LNKup_Match_Settings::active( 'matching_organizer_discovery_enabled' ), 'Find Vendors is switched on' );
lnkup_t_same( 40, LNKup_Match_Settings::min_score(), 'minimum score keeps its default' );
lnkup_t_same( 5, LNKup_Match_Settings::vendor_weekly_invite_cap(), 'vendor weekly cap keeps its default' );
lnkup_t_same( 100, LNKup_Match_Settings::organizer_daily_invite_cap(), 'organizer daily cap keeps its default' );
lnkup_t_same( 50, LNKup_Match_Settings::batch_max(), 'batch max keeps its default' );

$post['match_min_score'] = '55';
$result = LNKup_Match_Settings::save( LNKup_Match_Admin::input_from_post( $post ), '' );
lnkup_t_assert( is_wp_error( $result ) && 'lnkup_match_note_required' === $result->get_error_code(), 'a real ranking change still needs a note' );

remove_all_filters( 'sanitize_option_lnkup_settings' );
update_option( 'lnkup_settings', $original, false );
lnkup_t_finish();
