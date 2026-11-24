<?php
/**
 * Vendor alias of the shared LNKup OS design layer.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Kept so vendor views can keep calling LNKup_Vendor_UI::*. Hooks are registered by LNKup_OS_UI.
 */
class LNKup_Vendor_UI extends LNKup_OS_UI {

	const BODY_CLASS = 'lnkup-vos';
}
