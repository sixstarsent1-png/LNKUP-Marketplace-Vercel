<?php
/**
 * Enable the Organizer Portfolio OS for the seeded e2e organizer only (run after seed.php).
 * The global flag stays off so other organizers keep the existing experience.
 * Local wp-env only.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/seed-portfolio.php
 *
 * @package LNKup_Marketplace
 */

$organizer = get_user_by( 'login', 'e2e_org_a' );
if ( ! $organizer ) {
	echo "Run seed.php first (e2e_org_a missing).\n";
	return;
}
LNKup_Portfolio_Tables::maybe_upgrade();
$settings = get_option( 'lnkup_settings', array() );
$settings = is_array( $settings ) ? $settings : array();
$settings['portfolio_os_enabled']     = false;
$settings['portfolio_os_user_ids']    = (string) (int) $organizer->ID;
$settings['matching_enabled']         = true;
$settings['matching_tours_enabled']   = true;
$settings['tour_multi_apply_enabled'] = true;
update_option( 'lnkup_settings', $settings, false );
echo 'Portfolio OS enabled for e2e_org_a (#' . (int) $organizer->ID . ") only.\n";
