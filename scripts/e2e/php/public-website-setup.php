<?php
/**
 * Public Website 5.0 local setup: landing pages, rewrites, fresh discovery caches, one disabled +
 * one live campaign variant, and (local environments only) two test accounts with @example.test
 * addresses. Touches no orders or payments.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/public-website-setup.php
 */

wp_set_current_user( 1 );
delete_option( LNKup_Campaign_Landings::PAGES_OPTION );
LNKup_Campaign_Landings::maybe_create_pages();
LNKup_Public_Discovery::flush();
LNKup_Public_Discovery::flush_vendors();

$variants = get_option( LNKup_Campaign_Landings::OPTION, array() );
$variants = is_array( $variants ) ? $variants : array();
$variants['e2e-food-trucks'] = LNKup_Campaign_Landings::sanitize(
	array(
		'slug'      => 'e2e-food-trucks',
		'audience'  => 'vendor',
		'enabled'   => 1,
		'label'     => 'E2E food trucks',
		'eyebrow'   => 'For food vendors',
		'headline'  => 'Book more food truck events',
		'subhead'   => 'Find events that want food trucks and apply free.',
		'cta_label' => 'Join free and apply',
		'category'  => 'Food',
	)
);
$variants['e2e-off'] = LNKup_Campaign_Landings::sanitize(
	array(
		'slug'     => 'e2e-off',
		'audience' => 'organizer',
		'enabled'  => 0,
		'headline' => 'Disabled variant',
	)
);
update_option( LNKup_Campaign_Landings::OPTION, $variants, false );

if ( 'local' === wp_get_environment_type() ) {
	foreach ( array( 'lp5-vendor' => 'lnkup_vendor', 'lp5-organizer' => 'lnkup_organizer' ) as $login => $role ) {
		$user = get_user_by( 'login', $login );
		if ( ! $user ) {
			$id = wp_insert_user(
				array(
					'user_login' => $login,
					'user_email' => $login . '@example.test',
					'user_pass'  => 'Lp5-e2e-pass!',
					'first_name' => 'Lp5',
					'role'       => $role,
				)
			);
			echo esc_html( $login ) . ': ' . ( is_wp_error( $id ) ? $id->get_error_message() : 'created' ) . "\n";
		}
	}
}

foreach ( array( 'for-organizers', 'for-vendors' ) as $slug ) {
	$page = get_page_by_path( $slug );
	echo esc_html( $slug ) . ': ' . ( $page ? $page->ID . ' ' . get_page_template_slug( $page ) : 'missing' ) . "\n";
}
$snapshot = LNKup_Public_Discovery::snapshot();
echo 'categories=' . count( $snapshot['categories'] ) . ' cities=' . count( $snapshot['city_index'] ) . ' open_events=' . (int) $snapshot['stats']['open_events'] . "\n";
