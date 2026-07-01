<?php
/**
 * Vendor completed event history (event resume).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Completed_Events {

	/**
	 * Get completed events for vendor profile.
	 *
	 * @param int    $profile_id Profile ID.
	 * @param string $context    public|crm|vendor.
	 */
	public static function get_for_vendor( $profile_id, $context = 'public' ) {
		$profile_id = absint( $profile_id );
		$vendor_id    = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		if ( ! $vendor_id ) {
			return array();
		}

		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_vendor_user_id',
				'meta_value'     => $vendor_id,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $apps as $app ) {
			$status = get_post_meta( $app->ID, '_lnkup_application_status', true );
			$checked_in = get_post_meta( $app->ID, '_lnkup_checked_in_at', true );
			if ( ! in_array( $status, array( 'paid', 'completed' ), true ) && ! $checked_in ) {
				continue;
			}

			$event_id = (int) get_post_meta( $app->ID, '_lnkup_event_id', true );
			$event    = $event_id ? get_post( $event_id ) : null;
			$organizer_id = (int) get_post_meta( $app->ID, '_lnkup_organizer_user_id', true );
			$org = get_userdata( $organizer_id );
			$review_id = (int) get_post_meta( $app->ID, '_lnkup_review_id', true );
			$rating = $review_id ? (int) get_post_meta( $review_id, '_lnkup_overall_rating', true ) : 0;

			$rebooked = false;
			if ( class_exists( 'LNKup_Vendor_Invites' ) ) {
				$invites = get_posts(
					array(
						'post_type'      => 'lnkup_vendor_invite',
						'posts_per_page' => 1,
						'meta_query'     => array(
							array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor_id ),
							array( 'key' => '_lnkup_organizer_user_id', 'value' => $organizer_id ),
							array( 'key' => '_lnkup_status', 'value' => 'accepted' ),
						),
					)
				);
				$rebooked = ! empty( $invites );
			}

			$categories = $event_id ? wp_get_post_terms( $event_id, 'lnkup_event_type', array( 'fields' => 'names' ) ) : array();
			if ( is_wp_error( $categories ) ) {
				$categories = array();
			}

			$row = array(
				'application_id' => $app->ID,
				'event_id'       => $event_id,
				'event_name'     => $event ? $event->post_title : '',
				'date'           => $event_id ? get_post_meta( $event_id, '_lnkup_event_date', true ) : '',
				'city'           => $event_id ? get_post_meta( $event_id, '_lnkup_city', true ) : '',
				'state'          => $event_id ? get_post_meta( $event_id, '_lnkup_state', true ) : '',
				'category'       => $categories,
				'attendance'     => $event_id ? (int) get_post_meta( $event_id, '_lnkup_expected_attendance', true ) : 0,
				'payment_status' => $status,
				'review_status'  => $review_id ? 'reviewed' : 'pending',
				'rating'         => $rating,
				'rebooked'       => $rebooked,
				'verified'       => (bool) $checked_in || 'completed' === $status,
			);

			if ( 'public' === $context ) {
				$row['organizer'] = $org ? $org->display_name : __( 'Event Organizer', 'lnkup-marketplace' );
			} else {
				$row['organizer_id']   = $organizer_id;
				$row['organizer_name'] = $org ? $org->display_name : '';
			}

			$items[] = $row;
		}

		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);

		return $items;
	}
}
