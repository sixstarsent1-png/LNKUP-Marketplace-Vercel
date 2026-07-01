<?php
/**
 * Organizer private vendor notes.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Notes {

	/**
	 * Create note.
	 */
	public static function create( $organizer_id, $vendor_id, $content, $event_id = 0, $pinned = false ) {
		if ( ! LNKup_Capabilities::organizer_can_manage_vendor( $organizer_id, $vendor_id ) ) {
			return new WP_Error( 'forbidden', __( 'Connect with this vendor first.', 'lnkup-marketplace' ) );
		}
		$note_id = wp_insert_post(
			array(
				'post_type'    => 'lnkup_vendor_note',
				'post_status'  => 'publish',
				'post_title'   => wp_trim_words( $content, 8, '…' ),
				'post_content' => sanitize_textarea_field( $content ),
				'post_author'  => $organizer_id,
			)
		);
		if ( is_wp_error( $note_id ) ) {
			return $note_id;
		}
		update_post_meta( $note_id, '_lnkup_organizer_user_id', $organizer_id );
		update_post_meta( $note_id, '_lnkup_vendor_user_id', $vendor_id );
		update_post_meta( $note_id, '_lnkup_event_id', absint( $event_id ) );
		update_post_meta( $note_id, '_lnkup_is_pinned', $pinned ? 1 : 0 );
		LNKup_Organizer_CRM::log_activity(
			'note_added',
			$organizer_id,
			$vendor_id,
			array( 'event_id' => $event_id, 'payload' => array( 'note_id' => $note_id ) )
		);
		return $note_id;
	}

	/**
	 * Update note.
	 */
	public static function update( $note_id, $organizer_id, $content, $pinned = null ) {
		if ( ! LNKup_Capabilities::organizer_owns_crm_object( $note_id, $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		wp_update_post(
			array(
				'ID'           => $note_id,
				'post_content' => sanitize_textarea_field( $content ),
				'post_title'   => wp_trim_words( $content, 8, '…' ),
			)
		);
		if ( null !== $pinned ) {
			update_post_meta( $note_id, '_lnkup_is_pinned', $pinned ? 1 : 0 );
		}
		return true;
	}

	/**
	 * Delete note.
	 */
	public static function delete( $note_id, $organizer_id ) {
		if ( ! LNKup_Capabilities::organizer_owns_crm_object( $note_id, $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		wp_trash_post( $note_id );
		return true;
	}

	/**
	 * Get notes for vendor.
	 */
	public static function get_for_vendor( $organizer_id, $vendor_id, $limit = 20 ) {
		$notes = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_note',
				'posts_per_page' => $limit,
				'meta_query'     => array(
					array( 'key' => '_lnkup_organizer_user_id', 'value' => $organizer_id ),
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor_id ),
				),
				'orderby'        => 'meta_value_num date',
				'meta_key'       => '_lnkup_is_pinned',
				'order'          => 'DESC',
			)
		);
		$items = array();
		foreach ( $notes as $note ) {
			$author = get_userdata( (int) $note->post_author );
			$event_id = (int) get_post_meta( $note->ID, '_lnkup_event_id', true );
			$event = $event_id ? get_post( $event_id ) : null;
			$items[] = array(
				'id'         => $note->ID,
				'content'    => $note->post_content,
				'pinned'     => (bool) get_post_meta( $note->ID, '_lnkup_is_pinned', true ),
				'event_id'   => $event_id,
				'event_title'=> $event ? $event->post_title : '',
				'author'     => $author ? $author->display_name : '',
				'created_at' => $note->post_date,
			);
		}
		return $items;
	}
}
