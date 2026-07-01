<?php
/**
 * City Connector dashboard page template.
 *
 * Template Name: Connector Dashboard
 *
 * @package LNKup_Theme
 */

lnkup_require_roles( array( 'lnkup_connector', 'lnkup_admin', 'administrator' ) );

$connector = class_exists( 'LNKup_Connector' ) ? LNKup_Connector::get_by_user_id( get_current_user_id() ) : null;
if ( $connector && 'active' !== ( $connector['status'] ?? '' ) && ! current_user_can( 'manage_options' ) && ! in_array( 'lnkup_admin', (array) wp_get_current_user()->roles, true ) ) {
	wp_die( esc_html__( 'Your connector account is not active yet.', 'lnkup-theme' ), 403 );
}

get_header();
lnkup_dashboard_shell_open( 'connector', __( 'Connector Dashboard', 'lnkup-theme' ) );
echo do_shortcode( '[lnkup_connector_dashboard]' );
lnkup_dashboard_shell_close();
get_footer();
