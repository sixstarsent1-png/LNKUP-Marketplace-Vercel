<?php
/**
 * Smoke test: every Communications admin tab renders for an administrator without errors.
 *
 * @package LNKup_Communications
 */

require __DIR__ . '/_comms_helpers.php';

$admin = lnkup_ct_user( 'administrator' );
wp_set_current_user( $admin );
foreach ( array( 'overview', 'automations', 'templates', 'campaigns', 'logs', 'timeline', 'suppressions', 'analytics', 'settings', 'health' ) as $tab ) {
	$_GET = array( 'tab' => $tab, 'template' => 'o_new_vendors_nearby', 'who' => (string) $admin );
	ob_start();
	LNKup_Comms_Admin::render();
	$html = (string) ob_get_clean();
	lnkup_t_assert( strlen( $html ) > 500 && false === strpos( $html, 'could not be loaded' ), $tab . ' tab renders' );
}
$_GET = array();
wp_set_current_user( 0 );
lnkup_ct_finish();
