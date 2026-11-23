<?php
/**
 * lnkup_settings writes in an admin request: the main Settings sanitizer (registered on admin_init)
 * must not drop keys saved by module pages (Matching Rules, Passport, Trust), and must still
 * sanitize the main LNKup Settings form.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/settings-sanitize-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

echo "lnkup_settings sanitizer\n";

$admin = lnkup_t_user( 'administrator' );
wp_set_current_user( $admin );

$original = get_option( 'lnkup_settings', array() );
$base     = is_array( $original ) ? $original : array();
foreach ( array_merge( LNKup_Match_Settings::keys(), LNKup_Passport_Settings::FLAGS ) as $key ) {
	unset( $base[ $key ] );
}
foreach ( array( 'trust_publish_gate', 'trust_org_teams', 'test_mode' ) as $key ) {
	unset( $base[ $key ] );
}
update_option( 'lnkup_settings', $base, false );

// What admin_init does on every admin-post.php request (the path the Save buttons use).
LNKup_Admin::register_settings();
lnkup_t_assert( (bool) has_filter( 'sanitize_option_lnkup_settings' ), 'main Settings sanitizer is registered, as in wp-admin' );

echo "\n[module saves]\n";
$result = LNKup_Match_Settings::save( array( 'matching_enabled' => 1, 'matching_organizer_discovery_enabled' => 1 ) );
lnkup_t_assert( ! is_wp_error( $result ), 'Matching Rules save succeeds' );
lnkup_t_assert( LNKup_Match_Settings::active( 'matching_organizer_discovery_enabled' ), 'Matching Rules switches stay on after save' );
$stored = get_option( 'lnkup_settings' );
lnkup_t_same( true, $stored['matching_enabled'] ?? null, 'master switch stored' );

LNKup_Passport_Settings::save( array( 'review_responses' => 1 ) );
$stored = get_option( 'lnkup_settings' );
lnkup_t_same( true, $stored['review_responses'] ?? null, 'Passport settings save keeps its flag' );
lnkup_t_same( true, $stored['matching_enabled'] ?? null, 'Passport save does not undo matching flags' );

// Same write as LNKup_Trust_Safety_Admin::handle_settings() (which redirects, so it is mirrored here).
$settings                       = get_option( 'lnkup_settings', array() );
$settings['trust_publish_gate'] = true;
$settings['trust_org_teams']    = true;
update_option( 'lnkup_settings', $settings, false );
$stored = get_option( 'lnkup_settings' );
lnkup_t_same( true, $stored['trust_publish_gate'] ?? null, 'Trust & Safety settings keep their values' );

echo "\n[main Settings form]\n";
$_POST['option_page'] = 'lnkup_settings_group';
$posted               = array_merge( get_option( 'lnkup_settings', array() ), array( 'test_mode' => '1', 'support_email' => 'ops@example.test' ) );
update_option( 'lnkup_settings', $posted, false );
$stored = get_option( 'lnkup_settings' );
lnkup_t_same( true, $stored['test_mode'] ?? null, 'checked box saved through the settings form' );
lnkup_t_same( 'ops@example.test', $stored['support_email'] ?? null, 'settings form fields are saved' );

$posted = get_option( 'lnkup_settings', array() );
unset( $posted['test_mode'] );
$posted['support_email'] = 'not an email';
update_option( 'lnkup_settings', $posted, false );
$stored = get_option( 'lnkup_settings' );
lnkup_t_same( false, $stored['test_mode'] ?? null, 'unchecked box is cleared by the settings form sanitizer' );
lnkup_t_same( '', $stored['support_email'] ?? null, 'settings form still sanitizes input' );
lnkup_t_same( true, $stored['matching_enabled'] ?? null, 'settings form save keeps matching flags' );
unset( $_POST['option_page'] );

remove_all_filters( 'sanitize_option_lnkup_settings' );
update_option( 'lnkup_settings', $original, false );
lnkup_t_finish();
