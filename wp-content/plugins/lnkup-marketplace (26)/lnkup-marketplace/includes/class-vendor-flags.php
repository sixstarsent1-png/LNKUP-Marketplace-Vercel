<?php
/**
 * Organizer vendor risk flags.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Flags {

	/**
	 * Flag type catalog.
	 */
	public static function types() {
		return array(
			'no_show'           => __( 'No-show', 'lnkup-marketplace' ),
			'late_arrival'      => __( 'Late arrival', 'lnkup-marketplace' ),
			'payment_issue'     => __( 'Payment issue', 'lnkup-marketplace' ),
			'missing_documents' => __( 'Missing documents', 'lnkup-marketplace' ),
			'bad_communication' => __( 'Bad communication', 'lnkup-marketplace' ),
			'rule_violation'    => __( 'Rule violation', 'lnkup-marketplace' ),
			'refund_issue'      => __( 'Refund issue', 'lnkup-marketplace' ),
			'chargeback'        => __( 'Chargeback', 'lnkup-marketplace' ),
			'organizer_report'  => __( 'Organizer reported issue', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Add flag.
	 */
	public static function add( $organizer_id, $vendor_id, $type, $args = array() ) {
		if ( ! LNKup_Capabilities::organizer_can_manage_vendor( $organizer_id, $vendor_id ) ) {
			return new WP_Error( 'forbidden', __( 'Connect with this vendor first.', 'lnkup-marketplace' ) );
		}
		$types = self::types();
		$type = sanitize_key( $type );
		if ( ! isset( $types[ $type ] ) ) {
			return new WP_Error( 'invalid', __( 'Invalid flag type.', 'lnkup-marketplace' ) );
		}
		$flag_id = wp_insert_post(
			array(
				'post_type'   => 'lnkup_vendor_flag',
				'post_status' => 'publish',
				'post_title'  => $types[ $type ],
				'post_author' => $organizer_id,
			)
		);
		update_post_meta( $flag_id, '_lnkup_organizer_user_id', $organizer_id );
		update_post_meta( $flag_id, '_lnkup_vendor_user_id', $vendor_id );
		update_post_meta( $flag_id, '_lnkup_flag_type', $type );
		update_post_meta( $flag_id, '_lnkup_event_id', absint( $args['event_id'] ?? 0 ) );
		update_post_meta( $flag_id, '_lnkup_application_id', absint( $args['application_id'] ?? 0 ) );
		update_post_meta( $flag_id, '_lnkup_admin_visible', ! empty( $args['admin_visible'] ) ? 1 : 0 );
		if ( ! empty( $args['note'] ) ) {
			update_post_meta( $flag_id, '_lnkup_flag_note', sanitize_textarea_field( $args['note'] ) );
		}
		LNKup_Organizer_CRM::log_activity(
			'flag_added',
			$organizer_id,
			$vendor_id,
			array(
				'event_id'       => absint( $args['event_id'] ?? 0 ),
				'application_id' => absint( $args['application_id'] ?? 0 ),
				'payload'        => array( 'type' => $type, 'flag_id' => $flag_id ),
			)
		);
		return $flag_id;
	}

	/**
	 * Get flags for vendor from organizer.
	 */
	public static function get_for_vendor( $organizer_id, $vendor_id ) {
		$flags = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_flag',
				'posts_per_page' => 20,
				'meta_query'     => array(
					array( 'key' => '_lnkup_organizer_user_id', 'value' => $organizer_id ),
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor_id ),
				),
			)
		);
		$types = self::types();
		$items = array();
		foreach ( $flags as $flag ) {
			$type = get_post_meta( $flag->ID, '_lnkup_flag_type', true );
			$items[] = array(
				'id'    => $flag->ID,
				'type'  => $type,
				'label' => $types[ $type ] ?? $type,
				'note'  => get_post_meta( $flag->ID, '_lnkup_flag_note', true ),
				'date'  => $flag->post_date,
			);
		}
		return $items;
	}

	/**
	 * Count flags for vendor.
	 */
	public static function count_for_vendor( $organizer_id, $vendor_id ) {
		return count( self::get_for_vendor( $organizer_id, $vendor_id ) );
	}

	/**
	 * Admin-visible flags marketplace-wide.
	 */
	public static function get_admin_flags( $limit = 50 ) {
		$flags = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_flag',
				'posts_per_page' => $limit,
				'meta_key'       => '_lnkup_admin_visible',
				'meta_value'     => '1',
			)
		);
		$types = self::types();
		$items = array();
		foreach ( $flags as $flag ) {
			$type = get_post_meta( $flag->ID, '_lnkup_flag_type', true );
			$vendor_id = (int) get_post_meta( $flag->ID, '_lnkup_vendor_user_id', true );
			$vendor = get_userdata( $vendor_id );
			$items[] = array(
				'id'           => $flag->ID,
				'type'         => $type,
				'label'        => $types[ $type ] ?? $type,
				'vendor_name'  => $vendor ? $vendor->display_name : '',
				'organizer_id' => (int) get_post_meta( $flag->ID, '_lnkup_organizer_user_id', true ),
				'date'         => $flag->post_date,
			);
		}
		return $items;
	}
}
