<?php
/**
 * Keep LNKup titles stored as plain text.
 *
 * For users without unfiltered_html (organizers, vendors), core kses stores "R&B" as "R&amp;B",
 * which then leaks into every API that reads post_title. Only that ampersand encoding is undone:
 * tags are already stripped by kses, and every HTML output escapes titles.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Plain_Titles {

	const POST_TYPES = array( 'lnkup_event', 'lnkup_opportunity', 'lnkup_vendor_profile', 'lnkup_tour' );

	const MIGRATION_OPTION = 'lnkup_plain_titles_migrated';

	public static function init() {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'filter_post_data' ), 20 );
		add_action( 'lnkup_plain_titles_migrate', array( __CLASS__, 'migrate' ) );
	}

	/**
	 * @param array $data Slashed post data about to be saved.
	 * @return array
	 */
	public static function filter_post_data( $data ) {
		if ( isset( $data['post_type'], $data['post_title'] ) && in_array( $data['post_type'], self::POST_TYPES, true ) ) {
			$data['post_title'] = self::unescape_ampersands( $data['post_title'] );
		}
		return $data;
	}

	/**
	 * "&amp;" (and its numeric forms) back to "&". Other entities are left alone.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function unescape_ampersands( $title ) {
		return str_replace( array( '&amp;', '&#038;', '&#38;' ), '&', (string) $title );
	}

	/**
	 * One-time cleanup of titles saved before the filter existed, then a marketplace index rebuild
	 * (its title column holds copies).
	 *
	 * @param bool $force Run even if already done.
	 * @return int Rows updated.
	 */
	public static function migrate( $force = false ) {
		global $wpdb;
		if ( ! $force && get_option( self::MIGRATION_OPTION ) ) {
			return 0;
		}
		$types        = self::POST_TYPES;
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ids          = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND ( post_title LIKE %s OR post_title LIKE %s OR post_title LIKE %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $types, array( '%&amp;%', '%&#038;%', '%&#38;%' ) )
			)
		);
		$updated = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$title = (string) get_post_field( 'post_title', $id, 'raw' );
			$plain = self::unescape_ampersands( $title );
			if ( $plain !== $title ) {
				$wpdb->update( $wpdb->posts, array( 'post_title' => $plain ), array( 'ID' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $id );
				++$updated;
			}
		}
		if ( $updated && class_exists( 'LNKup_Marketplace_Index' ) ) {
			LNKup_Marketplace_Index::rebuild_all();
		}
		update_option( self::MIGRATION_OPTION, current_time( 'mysql' ), false );
		return $updated;
	}
}
