<?php
/**
 * Organizer-scoped vendor tags.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Tags {

	/**
	 * Predefined tag catalog.
	 */
	public static function catalog() {
		return array(
			'reliable'           => __( 'Reliable', 'lnkup-marketplace' ),
			'late'               => __( 'Late', 'lnkup-marketplace' ),
			'high_sales'         => __( 'High Sales', 'lnkup-marketplace' ),
			'good_setup'         => __( 'Good Setup', 'lnkup-marketplace' ),
			'needs_follow_up'    => __( 'Needs Follow-Up', 'lnkup-marketplace' ),
			'favorite'           => __( 'Favorite', 'lnkup-marketplace' ),
			'vip'                => __( 'VIP', 'lnkup-marketplace' ),
			'risky'              => __( 'Risky', 'lnkup-marketplace' ),
			'no_show'            => __( 'No-Show', 'lnkup-marketplace' ),
			'great_communication'=> __( 'Great Communication', 'lnkup-marketplace' ),
			'missing_docs'       => __( 'Missing Docs', 'lnkup-marketplace' ),
			'invite_back'        => __( 'Invite Back', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Add tag to vendor.
	 */
	public static function add( $organizer_id, $vendor_id, $slug, $label = '' ) {
		if ( ! LNKup_Capabilities::organizer_can_manage_vendor( $organizer_id, $vendor_id ) ) {
			return new WP_Error( 'forbidden', __( 'Connect with this vendor first.', 'lnkup-marketplace' ) );
		}
		global $wpdb;
		$slug = sanitize_key( $slug );
		$catalog = self::catalog();
		$label = $label ?: ( $catalog[ $slug ] ?? $slug );
		$table = LNKup_Organizer_CRM_Tables::tag_table();
		$wpdb->replace(
			$table,
			array(
				'organizer_user_id' => absint( $organizer_id ),
				'vendor_user_id'    => absint( $vendor_id ),
				'tag_slug'          => $slug,
				'tag_label'         => sanitize_text_field( $label ),
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		return true;
	}

	/**
	 * Remove tag.
	 */
	public static function remove( $organizer_id, $vendor_id, $slug ) {
		global $wpdb;
		$wpdb->delete(
			LNKup_Organizer_CRM_Tables::tag_table(),
			array(
				'organizer_user_id' => absint( $organizer_id ),
				'vendor_user_id'    => absint( $vendor_id ),
				'tag_slug'          => sanitize_key( $slug ),
			),
			array( '%d', '%d', '%s' )
		);
		return true;
	}

	/**
	 * Get tags for vendor.
	 */
	public static function get_for_vendor( $organizer_id, $vendor_id ) {
		global $wpdb;
		$table = LNKup_Organizer_CRM_Tables::tag_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tag_slug, tag_label FROM {$table} WHERE organizer_user_id = %d AND vendor_user_id = %d ORDER BY tag_label ASC",
				absint( $organizer_id ),
				absint( $vendor_id )
			),
			ARRAY_A
		);
		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'slug'  => $row['tag_slug'],
				'label' => $row['tag_label'],
			);
		}
		return $items;
	}
}
