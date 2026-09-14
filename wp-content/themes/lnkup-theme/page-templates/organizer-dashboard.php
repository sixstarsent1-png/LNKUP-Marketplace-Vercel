<?php
/**
 * Organizer dashboard page template.
 *
 * Template Name: Organizer Dashboard
 *
 * @package LNKup_Theme
 */

lnkup_require_roles( array( 'lnkup_organizer', 'lnkup_admin', 'administrator' ) );

get_header();
lnkup_dashboard_shell_open( 'organizer', lnkup_dashboard_organizer_title() );
echo do_shortcode( '[lnkup_organizer_dashboard]' );
lnkup_dashboard_shell_close();
get_footer();
