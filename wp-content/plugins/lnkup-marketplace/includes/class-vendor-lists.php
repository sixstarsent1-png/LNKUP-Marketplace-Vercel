<?php
/**
 * Organizer saved vendor lists.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Lists {

	/**
	 * Create list.
	 */
	public static function create( $organizer_id, $name, $color = '' ) {
		$list_id = wp_insert_post(
			array(
				'post_type'   => 'lnkup_vendor_list',
				'post_status' => 'publish',
				'post_title'  => sanitize_text_field( $name ),
				'post_author' => $organizer_id,
			)
		);
		if ( is_wp_error( $list_id ) ) {
			return $list_id;
		}
		update_post_meta( $list_id, '_lnkup_organizer_user_id', $organizer_id );
		update_post_meta( $list_id, '_lnkup_list_color', sanitize_hex_color( $color ) ?: '#c8ff00' );
		return $list_id;
	}

	/**
	 * Rename list.
	 */
	public static function rename( $list_id, $organizer_id, $name ) {
		if ( ! LNKup_Capabilities::organizer_owns_crm_object( $list_id, $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		wp_update_post( array( 'ID' => $list_id, 'post_title' => sanitize_text_field( $name ) ) );
		return true;
	}

	/**
	 * Delete list.
	 */
	public static function delete( $list_id, $organizer_id ) {
		if ( ! LNKup_Capabilities::organizer_owns_crm_object( $list_id, $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		global $wpdb;
		$wpdb->delete( LNKup_Organizer_CRM_Tables::list_item_table(), array( 'list_id' => $list_id ), array( '%d' ) );
		wp_trash_post( $list_id );
		return true;
	}

	/**
	 * Get lists for organizer.
	 */
	public static function get_for_organizer( $organizer_id ) {
		$lists = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_list',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
			)
		);
		$items = array();
		foreach ( $lists as $list ) {
			$items[] = array(
				'id'    => $list->ID,
				'name'  => $list->post_title,
				'color' => get_post_meta( $list->ID, '_lnkup_list_color', true ),
				'count' => self::count_vendors( $list->ID ),
			);
		}
		return $items;
	}

	/**
	 * Count vendors in list.
	 */
	public static function count_vendors( $list_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . LNKup_Organizer_CRM_Tables::list_item_table() . ' WHERE list_id = %d',
				$list_id
			)
		);
	}

	/**
	 * Add vendor to list.
	 */
	public static function add_vendor( $list_id, $organizer_id, $profile_id ) {
		if ( ! LNKup_Capabilities::organizer_owns_crm_object( $list_id, $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$profile_id = absint( $profile_id );
		if ( 'lnkup_vendor_profile' !== get_post_type( $profile_id ) || 'publish' !== get_post_status( $profile_id ) ) {
			return new WP_Error( 'invalid_vendor', __( 'That vendor profile is not available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$vendor_id = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		if ( ! $vendor_id ) {
			return new WP_Error( 'invalid_vendor', __( 'That vendor profile is not available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$discoverable = get_post_meta( $profile_id, '_lnkup_discoverable', true );
		if ( '0' === (string) $discoverable
			&& ! LNKup_Capabilities::user_has_manage_all( $organizer_id )
			&& ! in_array( $vendor_id, LNKup_Match_Service::related_vendor_users( $organizer_id ), true ) ) {
			return new WP_Error( 'invalid_vendor', __( 'That vendor profile is not available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		LNKup_Organizer_CRM::ensure_relationship( $organizer_id, $vendor_id, $profile_id );
		global $wpdb;
		$wpdb->replace(
			LNKup_Organizer_CRM_Tables::list_item_table(),
			array(
				'list_id'           => absint( $list_id ),
				'vendor_profile_id' => absint( $profile_id ),
				'added_at'          => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s' )
		);
		return true;
	}

	/**
	 * Remove vendor from list.
	 */
	public static function remove_vendor( $list_id, $organizer_id, $profile_id ) {
		if ( ! LNKup_Capabilities::organizer_owns_crm_object( $list_id, $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		global $wpdb;
		$wpdb->delete(
			LNKup_Organizer_CRM_Tables::list_item_table(),
			array(
				'list_id'           => $list_id,
				'vendor_profile_id' => $profile_id,
			),
			array( '%d', '%d' )
		);
		return true;
	}

	/**
	 * Get vendors in list.
	 */
	public static function get_vendors( $list_id, $organizer_id ) {
		if ( ! LNKup_Capabilities::organizer_owns_crm_object( $list_id, $organizer_id ) ) {
			return array();
		}
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT vendor_profile_id FROM " . LNKup_Organizer_CRM_Tables::list_item_table() . ' WHERE list_id = %d ORDER BY added_at DESC',
				$list_id
			)
		);
		$items = array();
		foreach ( (array) $rows as $pid ) {
			$p = LNKup_Vendor_CRM_Profile::get( (int) $pid, $organizer_id );
			if ( $p ) {
				$items[] = $p;
			}
		}
		return $items;
	}
}
