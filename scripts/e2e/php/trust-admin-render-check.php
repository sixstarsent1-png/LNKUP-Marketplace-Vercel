<?php
/**
 * Render every Trust & Safety admin tab and the organizer Team view without errors.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/trust-admin-render-check.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

echo "Trust admin render\n";

$admin = lnkup_t_user( 'administrator' );
( new WP_User( $admin ) )->add_cap( 'lnkup_manage_trust' );
wp_set_current_user( $admin );

foreach ( array( 'overview', 'cases', 'organizers', 'events', 'audit', 'settings' ) as $tab ) {
	$_GET['tab'] = $tab;
	ob_start();
	LNKup_Trust_Safety_Admin::render_page();
	$html = (string) ob_get_clean();
	lnkup_t_assert( false !== strpos( $html, 'lnkup-admin-trust-safety' ) && false === stripos( $html, 'fatal error' ) && false === stripos( $html, 'warning:' ), "admin tab renders: {$tab}" );
}
unset( $_GET['tab'] );

$organizer = lnkup_t_user( 'lnkup_organizer' );
wp_set_current_user( $organizer );
$html = LNKup_Organizer_Dashboard_Views::render( 'team' );
lnkup_t_assert( false !== strpos( $html, 'lnkup-org-verify' ) && false !== strpos( $html, 'lnkup-org-team-list' ), 'organizer Team & Trust view renders both panels' );
lnkup_t_same( 'team', lnkup_organizer_dashboard_view( 'team' ), 'team view is allowlisted' );

$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/organizer/verification' ) );
lnkup_t_same( 200, $res->get_status(), 'organizer reads own verification' );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/org/team' ) );
lnkup_t_same( 200, $res->get_status(), 'organizer reads own team' );
lnkup_t_same( false, $res->get_data()['enabled'] ?? null, 'team access reports disabled by default' );

wp_set_current_user( 0 );
lnkup_t_finish();
