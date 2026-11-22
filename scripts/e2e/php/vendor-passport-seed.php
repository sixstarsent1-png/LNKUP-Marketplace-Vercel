<?php
/**
 * Seed one public demo Passport for local HTTP/Playwright checks (wp-env only; never production).
 * Prints the public slug.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/vendor-passport-seed.php
 *
 * @package LNKup_Marketplace
 */

$settings = get_option( 'lnkup_settings', array() );
$settings = is_array( $settings ) ? $settings : array();
foreach ( LNKup_Passport_Settings::FLAGS as $flag ) {
	$settings[ $flag ] = true;
}
update_option( 'lnkup_settings', $settings, false );

$user = username_exists( 'passport_demo' );
if ( ! $user ) {
	$user = wp_insert_user(
		array(
			'user_login' => 'passport_demo',
			'user_email' => 'passport_demo@example.test',
			'user_pass'  => 'Passw0rd!demo',
			'role'       => 'lnkup_vendor',
		)
	);
}
$profile = (int) lnkup_get_vendor_profile_id( (int) $user );
if ( ! $profile ) {
	$profile = (int) wp_insert_post(
		array(
			'post_type'    => 'lnkup_vendor_profile',
			'post_status'  => 'publish',
			'post_title'   => 'Demo Tacos',
			'post_content' => 'Street tacos for festivals and night markets.',
			'meta_input'   => array(
				'_lnkup_owner_user_id'  => (int) $user,
				'_lnkup_business_name'  => 'Demo Tacos',
				'_lnkup_city'           => 'Houston',
				'_lnkup_state'          => 'TX',
				'_lnkup_contact_email'  => 'private-demo@example.test',
				'_lnkup_contact_phone'  => '555-0199',
			),
		)
	);
}
LNKup_Passport_Privacy::save( $profile, array( 'public_profile' => true ) );
$slug = LNKup_Vendor_Reputation::ensure_public_slug( $profile );
LNKup_Passport_Public::register_rewrite();
flush_rewrite_rules( false );
echo 'SLUG=' . $slug . "\n";
