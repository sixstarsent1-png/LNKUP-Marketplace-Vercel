<?php
/**
 * Manual provider loader (implementation in includes/financial/).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LNKup_Manual_Provider', false ) ) {
	require_once LNKUP_PLUGIN_DIR . 'includes/financial/class-manual-provider.php';
}
