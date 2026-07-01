<?php
/**
 * Uninstall handler.
 *
 * @package LNKup_Marketplace
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'lnkup_settings' );
delete_option( 'lnkup_pages_created' );
delete_option( 'lnkup_seeded' );
