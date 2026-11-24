<?php
/**
 * Renders the Location Health admin page as an administrator and prints a short summary.
 * Run with: wp eval-file scripts/verify-location-health-render.php (test stacks).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) ( $admins[0] ?? 1 ) );
ob_start();
LNKup_Location_Health_Admin::render();
$html = (string) ob_get_clean();
printf( "rendered %d bytes, testid present: %s\n", strlen( $html ), false !== strpos( $html, 'lnkup-location-health' ) ? 'yes' : 'no' );
echo wp_json_encode( LNKup_Location_Health_Admin::summary( true ) ), "\n";
echo 'duplicate markets: ', wp_json_encode( LNKup_Location_Health_Admin::duplicate_markets() ), "\n";
