<?php
/**
 * Organizer onboarding v2 e2e setup (run after seed.php). Local wp-env only.
 *
 * - Turns on organizer_onboarding_v2_enabled and open registration.
 * - (Re)creates e2e_org_onb: an organizer with no events and a fresh guided-setup state,
 *   on the Portfolio OS allowlist so choosing "Multi-city tour" opens the tour wizard.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/seed-onboarding.php
 *
 * @package LNKup_Marketplace
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Refusing to seed: WP_ENVIRONMENT_TYPE is not local.' );
}

$password = 'lnkup-e2e-Passw0rd!';
$login    = 'e2e_org_onb';
$user     = get_user_by( 'login', $login );
if ( ! $user ) {
	$id   = wp_create_user( $login, $password, $login . '@example.test' );
	$user = get_user_by( 'id', $id );
}
wp_set_password( $password, $user->ID );
$user->set_role( 'lnkup_organizer' );
wp_update_user( array( 'ID' => $user->ID, 'display_name' => 'E2E Onboarding Organizer' ) );

foreach ( get_posts( array( 'post_type' => array( 'lnkup_event', 'lnkup_tour' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_lnkup_organizer_user_id', 'meta_value' => (string) $user->ID ) ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}
foreach ( array( '_lnkup_onboarding_state', '_lnkup_organizer_public_name', '_lnkup_organizer_city', '_lnkup_organizer_state', '_lnkup_organizer_category' ) as $meta ) {
	delete_user_meta( $user->ID, $meta );
}
update_user_meta( $user->ID, '_lnkup_organizer_public_name', 'E2E Onboarding Productions' );
update_user_meta( $user->ID, '_lnkup_organizer_city', 'Houston' );
update_user_meta( $user->ID, '_lnkup_organizer_state', 'TX' );

update_option( 'users_can_register', 1 );
$settings = get_option( 'lnkup_settings', array() );
$settings = is_array( $settings ) ? $settings : array();
$allow    = array_filter( array_map( 'absint', explode( ',', (string) ( $settings['portfolio_os_user_ids'] ?? '' ) ) ) );
$allow[]  = (int) $user->ID;
$settings['organizer_onboarding_v2_enabled'] = true;
$settings['portfolio_os_user_ids']           = implode( ',', array_unique( $allow ) );
update_option( 'lnkup_settings', $settings, false );
LNKup_Organizer_Activation::flush( (int) $user->ID );

echo 'Onboarding v2 on; e2e_org_onb (#' . (int) $user->ID . ") reset.\n";
