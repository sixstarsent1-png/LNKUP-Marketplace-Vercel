<?php
/**
 * Vendor CRM timeline.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Timeline {

	/**
	 * Activity type labels.
	 */
	public static function type_labels() {
		return array(
			'applied'            => __( 'Applied to event', 'lnkup-marketplace' ),
			'approved'           => __( 'Approved', 'lnkup-marketplace' ),
			'rejected'           => __( 'Rejected', 'lnkup-marketplace' ),
			'waitlisted'         => __( 'Waitlisted', 'lnkup-marketplace' ),
			'payment_requested'  => __( 'Payment requested', 'lnkup-marketplace' ),
			'payment_completed'  => __( 'Payment completed', 'lnkup-marketplace' ),
			'packet_unlocked'    => __( 'Packet unlocked', 'lnkup-marketplace' ),
			'document_uploaded'  => __( 'Document uploaded', 'lnkup-marketplace' ),
			'document_approved'  => __( 'Document approved', 'lnkup-marketplace' ),
			'document_rejected'  => __( 'Document rejected', 'lnkup-marketplace' ),
			'note_added'         => __( 'Organizer note added', 'lnkup-marketplace' ),
			'vendor_messaged'    => __( 'Vendor messaged', 'lnkup-marketplace' ),
			'event_completed'    => __( 'Event completed', 'lnkup-marketplace' ),
			'vendor_reviewed'    => __( 'Vendor reviewed', 'lnkup-marketplace' ),
			'no_show'            => __( 'No-show marked', 'lnkup-marketplace' ),
			'checked_in'         => __( 'Checked in', 'lnkup-marketplace' ),
			'rebooked'           => __( 'Rebooked', 'lnkup-marketplace' ),
			'invite_sent'        => __( 'Invite sent', 'lnkup-marketplace' ),
			'info_requested'     => __( 'You asked for more information', 'lnkup-marketplace' ),
			'info_responded'     => __( 'Vendor replied to your question', 'lnkup-marketplace' ),
			'invite_accepted'    => __( 'Invite accepted', 'lnkup-marketplace' ),
			'invite_declined'    => __( 'Invite declined', 'lnkup-marketplace' ),
			'flag_added'         => __( 'Risk flag added', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Get timeline for vendor from organizer perspective.
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @param int $vendor_id    Vendor user ID.
	 * @param int $limit        Limit.
	 */
	public static function get_for_vendor( $organizer_id, $vendor_id, $limit = 50 ) {
		global $wpdb;
		$table = LNKup_Organizer_CRM_Tables::activity_table();
		$labels = self::type_labels();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE organizer_user_id = %d AND vendor_user_id = %d ORDER BY created_at DESC LIMIT %d",
				absint( $organizer_id ),
				absint( $vendor_id ),
				absint( $limit )
			),
			ARRAY_A
		);
		$items = array();
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( $row['payload'] ?? '{}', true ) ?: array();
			$actor   = get_userdata( (int) $row['actor_user_id'] );
			$event   = ! empty( $row['event_id'] ) ? get_post( (int) $row['event_id'] ) : null;
			$items[] = array(
				'id'             => (int) $row['id'],
				'type'           => $row['activity_type'],
				'label'          => $labels[ $row['activity_type'] ] ?? $row['activity_type'],
				'event_id'       => (int) $row['event_id'],
				'event_title'    => $event ? $event->post_title : '',
				'application_id' => (int) $row['application_id'],
				'payload'        => $payload,
				'actor_name'     => $actor ? $actor->display_name : '',
				'created_at'     => $row['created_at'],
			);
		}
		return $items;
	}
}
