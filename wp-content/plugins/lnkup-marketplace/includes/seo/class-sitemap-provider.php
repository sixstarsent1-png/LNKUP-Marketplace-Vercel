<?php
/**
 * Core WordPress sitemap provider for marketplace URLs (wp-sitemap-lnkup-{subtype}-{page}.xml).
 * Loaded only when the core sitemap system initializes.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Sitemap_Provider extends WP_Sitemaps_Provider {

	public function __construct() {
		$this->name        = 'lnkup';
		$this->object_type = 'lnkup';
	}

	/**
	 * @return array<string,object>
	 */
	public function get_object_subtypes() {
		$out = array();
		foreach ( LNKup_Sitemaps::subtypes() as $subtype ) {
			$out[ $subtype ] = (object) array( 'name' => $subtype );
		}
		return $out;
	}

	/**
	 * @param int    $page_num       Page.
	 * @param string $object_subtype Subtype.
	 * @return array<int,array<string,string>>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$list = array();
		foreach ( LNKup_Sitemaps::entries( (string) $object_subtype, (int) $page_num ) as $entry ) {
			$row = array( 'loc' => $entry['loc'] );
			if ( '' !== $entry['lastmod'] ) {
				$row['lastmod'] = $entry['lastmod'];
			}
			$list[] = $row;
		}
		return $list;
	}

	/**
	 * @param string $object_subtype Subtype.
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		return LNKup_Sitemaps::max_pages( (string) $object_subtype );
	}
}
