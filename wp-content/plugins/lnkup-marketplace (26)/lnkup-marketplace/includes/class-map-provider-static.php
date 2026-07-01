<?php
/**
 * Static schematic map fallback (no tile API).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Map_Provider_Static implements LNKup_Map_Provider_Interface {

	public function slug() {
		return 'static';
	}

	public function is_available() {
		return true;
	}

	public function config() {
		return array(
			'schematic' => true,
			'gridCols'  => 12,
			'gridRows'  => 8,
		);
	}
}
