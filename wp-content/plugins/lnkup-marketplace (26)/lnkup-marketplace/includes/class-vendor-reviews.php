<?php
/**
 * Organizer vendor reviews.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Reviews {

	const STATUS_PUBLISHED = 'published';
	const STATUS_HIDDEN    = 'hidden';
	const STATUS_DISPUTED  = 'disputed';
	const STATUS_REMOVED   = 'removed';

	/**
	 * Review category keys.
	 */
	public static function categories() {
		return array(
			'professionalism'    => __( 'Professionalism', 'lnkup-marketplace' ),
			'communication'      => __( 'Communication', 'lnkup-marketplace' ),
			'setup_readiness'    => __( 'Setup readiness', 'lnkup-marketplace' ),
			'arrival_time'       => __( 'Arrival time', 'lnkup-marketplace' ),
			'quality'            => __( 'Product/service quality', 'lnkup-marketplace' ),
			'cleanliness'        => __( 'Cleanliness', 'lnkup-marketplace' ),
			'engagement'         => __( 'Customer engagement', 'lnkup-marketplace' ),
			'would_book_again'   => __( 'Would book again', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Check if organizer can review application.
	 *
	 * @param int $application_id Application ID.
	 * @param int $organizer_id   Organizer user ID.
	 */
	public static function can_review( $application_id, $organizer_id = 0 ) {
		$organizer_id = $organizer_id ?: get_current_user_id();
		$app_id       = absint( $application_id );

		if ( ! LNKup_Capabilities::user_owns_post( $app_id, $organizer_id, '_lnkup_organizer_user_id' ) && ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
			return array( 'allowed' => false, 'reason' => __( 'You do not own this application.', 'lnkup-marketplace' ) );
		}

		if ( self::get_by_application( $app_id ) ) {
			return array( 'allowed' => false, 'reason' => __( 'A review already exists for this booking.', 'lnkup-marketplace' ) );
		}

		$status = get_post_meta( $app_id, '_lnkup_application_status', true );
		if ( ! in_array( $status, array( 'paid', 'completed' ), true ) && ! get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
			return array( 'allowed' => false, 'reason' => __( 'Only completed bookings can be reviewed.', 'lnkup-marketplace' ) );
		}

		if ( in_array( $status, array( 'submitted', 'waitlisted', 'rejected' ), true ) && ! get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
			return array( 'allowed' => false, 'reason' => __( 'Rejected or waitlisted vendors cannot be reviewed.', 'lnkup-marketplace' ) );
		}

		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$event_date = $event_id ? get_post_meta( $event_id, '_lnkup_event_date', true ) : '';
		if ( $event_date && strtotime( $event_date ) > strtotime( 'today' ) && ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
			return array( 'allowed' => false, 'reason' => __( 'Reviews open after the event date.', 'lnkup-marketplace' ) );
		}

		return array( 'allowed' => true, 'reason' => '' );
	}

	/**
	 * Create review.
	 *
	 * @param int   $organizer_id Organizer user ID.
	 * @param array $data         Review data.
	 */
	public static function create( $organizer_id, $data ) {
		$app_id = absint( $data['application_id'] ?? 0 );
		$check  = self::can_review( $app_id, $organizer_id );
		if ( ! $check['allowed'] ) {
			return new WP_Error( 'not_allowed', $check['reason'] );
		}

		$vendor_id   = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$profile_id  = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		$event_id    = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$overall     = max( 1, min( 5, absint( $data['overall_rating'] ?? 0 ) ) );
		$categories  = self::sanitize_categories( $data['category_ratings'] ?? array() );

		$review_id = wp_insert_post(
			array(
				'post_type'    => 'lnkup_review',
				'post_status'  => 'publish',
				'post_title'   => sprintf( __( 'Review — App #%d', 'lnkup-marketplace' ), $app_id ),
				'post_content' => sanitize_textarea_field( $data['public_text'] ?? '' ),
				'post_author'  => $organizer_id,
			)
		);

		if ( is_wp_error( $review_id ) ) {
			return $review_id;
		}

		update_post_meta( $review_id, '_lnkup_organizer_user_id', $organizer_id );
		update_post_meta( $review_id, '_lnkup_vendor_user_id', $vendor_id );
		update_post_meta( $review_id, '_lnkup_vendor_profile_id', $profile_id );
		update_post_meta( $review_id, '_lnkup_application_id', $app_id );
		update_post_meta( $review_id, '_lnkup_event_id', $event_id );
		update_post_meta( $review_id, '_lnkup_overall_rating', $overall );
		update_post_meta( $review_id, '_lnkup_category_ratings', wp_json_encode( $categories ) );
		update_post_meta( $review_id, '_lnkup_public_text', sanitize_textarea_field( $data['public_text'] ?? '' ) );
		update_post_meta( $review_id, '_lnkup_private_note', sanitize_textarea_field( $data['private_note'] ?? '' ) );
		update_post_meta( $review_id, '_lnkup_internal_note', sanitize_textarea_field( $data['internal_note'] ?? '' ) );
		update_post_meta( $review_id, '_lnkup_status', self::STATUS_PUBLISHED );
		update_post_meta( $review_id, '_lnkup_late_arrival', ! empty( $data['late_arrival'] ) ? 1 : 0 );
		update_post_meta( $review_id, '_lnkup_issue_flagged', ! empty( $data['issue_flagged'] ) ? 1 : 0 );

		update_post_meta( $app_id, '_lnkup_review_id', $review_id );
		update_post_meta( $app_id, '_lnkup_organizer_reviewed_at', current_time( 'mysql' ) );

		do_action( 'lnkup_vendor_review_submitted', $review_id, $profile_id );
		return $review_id;
	}

	/**
	 * Update review (organizer, within 7 days).
	 */
	public static function update( $review_id, $organizer_id, $data ) {
		$review_id = absint( $review_id );
		if ( (int) get_post_meta( $review_id, '_lnkup_organizer_user_id', true ) !== (int) $organizer_id && ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		$post = get_post( $review_id );
		if ( ! $post || strtotime( $post->post_date ) < strtotime( '-7 days' ) && ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
			return new WP_Error( 'expired', __( 'Edit window has closed.', 'lnkup-marketplace' ) );
		}

		if ( isset( $data['public_text'] ) ) {
			wp_update_post( array( 'ID' => $review_id, 'post_content' => sanitize_textarea_field( $data['public_text'] ) ) );
			update_post_meta( $review_id, '_lnkup_public_text', sanitize_textarea_field( $data['public_text'] ) );
		}
		if ( isset( $data['private_note'] ) ) {
			update_post_meta( $review_id, '_lnkup_private_note', sanitize_textarea_field( $data['private_note'] ) );
		}
		if ( isset( $data['overall_rating'] ) ) {
			update_post_meta( $review_id, '_lnkup_overall_rating', max( 1, min( 5, absint( $data['overall_rating'] ) ) ) );
		}
		if ( isset( $data['category_ratings'] ) ) {
			update_post_meta( $review_id, '_lnkup_category_ratings', wp_json_encode( self::sanitize_categories( $data['category_ratings'] ) ) );
		}

		$profile_id = (int) get_post_meta( $review_id, '_lnkup_vendor_profile_id', true );
		LNKup_Vendor_Reputation::refresh( $profile_id );
		return true;
	}

	/**
	 * Vendor reports review — opens dispute.
	 */
	public static function report( $review_id, $vendor_id, $reason ) {
		$review_id = absint( $review_id );
		if ( (int) get_post_meta( $review_id, '_lnkup_vendor_user_id', true ) !== (int) $vendor_id ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ) );
		}
		update_post_meta( $review_id, '_lnkup_status', self::STATUS_DISPUTED );

		$dispute_id = wp_insert_post(
			array(
				'post_type'   => 'lnkup_dispute',
				'post_status' => 'publish',
				'post_title'  => sprintf( __( 'Dispute — Review #%d', 'lnkup-marketplace' ), $review_id ),
				'post_content'=> sanitize_textarea_field( $reason ),
			)
		);
		update_post_meta( $dispute_id, '_lnkup_review_id', $review_id );
		update_post_meta( $dispute_id, '_lnkup_vendor_user_id', $vendor_id );
		update_post_meta( $dispute_id, '_lnkup_reason', sanitize_textarea_field( $reason ) );
		update_post_meta( $dispute_id, '_lnkup_status', 'open' );

		$profile_id = (int) get_post_meta( $review_id, '_lnkup_vendor_profile_id', true );
		LNKup_Vendor_Reputation::log_activity(
			'dispute_opened',
			$profile_id,
			array( 'payload' => array( 'review_id' => $review_id, 'dispute_id' => $dispute_id ) )
		);
		return $dispute_id;
	}

	/**
	 * Get review by application.
	 */
	public static function get_by_application( $application_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_review',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_application_id',
				'meta_value'     => absint( $application_id ),
			)
		);
		return $posts ? self::format( $posts[0] ) : null;
	}

	/**
	 * Published reviews for vendor profile.
	 */
	public static function get_for_vendor( $profile_id, $limit = 20, $include_private = false ) {
		$query = array(
			'post_type'      => 'lnkup_review',
			'posts_per_page' => $limit,
			'meta_query'     => array(
				array(
					'key'   => '_lnkup_vendor_profile_id',
					'value' => absint( $profile_id ),
				),
				array(
					'key'     => '_lnkup_status',
					'value'   => self::STATUS_PUBLISHED,
					'compare' => '=',
				),
			),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$posts = get_posts( $query );
		$items = array();
		foreach ( $posts as $post ) {
			$items[] = self::format( $post, $include_private );
		}
		return $items;
	}

	/**
	 * Review summary for vendor.
	 */
	public static function summary_for_vendor( $profile_id ) {
		$reviews = get_posts(
			array(
				'post_type'      => 'lnkup_review',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => '_lnkup_vendor_profile_id', 'value' => absint( $profile_id ) ),
					array( 'key' => '_lnkup_status', 'value' => self::STATUS_PUBLISHED ),
				),
			)
		);
		if ( ! $reviews ) {
			return array( 'avg' => 0, 'count' => 0, 'categories' => array() );
		}
		$sum = 0;
		$cat_sums = array();
		$cat_counts = array();
		foreach ( $reviews as $rid ) {
			$r = (int) get_post_meta( $rid, '_lnkup_overall_rating', true );
			$sum += $r;
			$cats = json_decode( get_post_meta( $rid, '_lnkup_category_ratings', true ) ?: '{}', true );
			if ( is_array( $cats ) ) {
				foreach ( $cats as $k => $v ) {
					$cat_sums[ $k ] = ( $cat_sums[ $k ] ?? 0 ) + (float) $v;
					$cat_counts[ $k ] = ( $cat_counts[ $k ] ?? 0 ) + 1;
				}
			}
		}
		$cat_avg = array();
		foreach ( $cat_sums as $k => $v ) {
			$cat_avg[ $k ] = round( $v / max( 1, $cat_counts[ $k ] ), 1 );
		}
		return array(
			'avg'        => round( $sum / count( $reviews ), 1 ),
			'count'      => count( $reviews ),
			'categories' => $cat_avg,
		);
	}

	/**
	 * Format review for API/UI.
	 */
	public static function format( $post, $include_private = false ) {
		$cats = json_decode( get_post_meta( $post->ID, '_lnkup_category_ratings', true ) ?: '{}', true );
		$event_id = (int) get_post_meta( $post->ID, '_lnkup_event_id', true );
		$organizer_id = (int) get_post_meta( $post->ID, '_lnkup_organizer_user_id', true );
		$org = get_userdata( $organizer_id );
		$item = array(
			'id'              => $post->ID,
			'overall_rating'  => (int) get_post_meta( $post->ID, '_lnkup_overall_rating', true ),
			'category_ratings'=> is_array( $cats ) ? $cats : array(),
			'public_text'     => get_post_meta( $post->ID, '_lnkup_public_text', true ) ?: $post->post_content,
			'status'          => get_post_meta( $post->ID, '_lnkup_status', true ),
			'event_id'        => $event_id,
			'event_title'     => $event_id ? get_the_title( $event_id ) : '',
			'organizer_name'  => $org ? $org->display_name : '',
			'created_at'      => $post->post_date,
			'application_id'  => (int) get_post_meta( $post->ID, '_lnkup_application_id', true ),
		);
		if ( $include_private ) {
			$item['private_note'] = get_post_meta( $post->ID, '_lnkup_private_note', true );
			$item['internal_note'] = get_post_meta( $post->ID, '_lnkup_internal_note', true );
		}
		return $item;
	}

	/**
	 * Sanitize category ratings.
	 */
	private static function sanitize_categories( $raw ) {
		$out = array();
		foreach ( self::categories() as $key => $label ) {
			if ( isset( $raw[ $key ] ) ) {
				$out[ $key ] = max( 1, min( 5, absint( $raw[ $key ] ) ) );
			}
		}
		return $out;
	}

	/**
	 * Admin hide/remove review.
	 */
	public static function moderate( $review_id, $status ) {
		update_post_meta( absint( $review_id ), '_lnkup_status', sanitize_key( $status ) );
		$profile_id = (int) get_post_meta( $review_id, '_lnkup_vendor_profile_id', true );
		LNKup_Vendor_Reputation::refresh( $profile_id );
		return true;
	}
}
