<?php
/**
 * Organizer vendor reviews, vendor responses, disputes and moderation.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Reviews {

	const STATUS_PUBLISHED = 'published';
	const STATUS_HIDDEN    = 'hidden';
	const STATUS_DISPUTED  = 'disputed';
	const STATUS_REMOVED   = 'removed';

	const STATUSES = array( self::STATUS_PUBLISHED, self::STATUS_HIDDEN, self::STATUS_REMOVED );

	const DISPUTE_OPEN          = 'open';
	const DISPUTE_APPEALED      = 'appealed';
	const DISPUTE_UPHELD        = 'upheld';
	const DISPUTE_REVIEW_HIDDEN = 'review_hidden';
	const DISPUTE_EDIT_REQUESTED = 'edit_requested';

	const DECISIONS = array( 'uphold', 'hide', 'request_edit' );

	const APPEAL_WINDOW_DAYS = 30;

	/**
	 * Review category keys (legacy keys remain accepted; the public view maps them).
	 */
	public static function categories() {
		return array(
			'communication'      => __( 'Communication', 'lnkup-marketplace' ),
			'professionalism'    => __( 'Professionalism', 'lnkup-marketplace' ),
			'preparedness'       => __( 'Preparedness', 'lnkup-marketplace' ),
			'event_coordination' => __( 'Event coordination', 'lnkup-marketplace' ),
			'setup_readiness'    => __( 'Setup readiness', 'lnkup-marketplace' ),
			'arrival_time'       => __( 'Arrival time', 'lnkup-marketplace' ),
			'quality'            => __( 'Product/service quality', 'lnkup-marketplace' ),
			'cleanliness'        => __( 'Cleanliness', 'lnkup-marketplace' ),
			'engagement'         => __( 'Customer engagement', 'lnkup-marketplace' ),
			'would_book_again'   => __( 'Would book again', 'lnkup-marketplace' ),
		);
	}

	/**
	 * The five public categories shown on the Passport.
	 */
	public static function public_categories() {
		return array(
			'communication'      => __( 'Communication', 'lnkup-marketplace' ),
			'professionalism'    => __( 'Professionalism', 'lnkup-marketplace' ),
			'preparedness'       => __( 'Preparedness', 'lnkup-marketplace' ),
			'event_coordination' => __( 'Event coordination', 'lnkup-marketplace' ),
			'overall'            => __( 'Overall experience', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Allow-listed positive tags organizers may attach.
	 */
	public static function positive_tags() {
		return array(
			'on_time'                => __( 'On time', 'lnkup-marketplace' ),
			'great_setup'            => __( 'Great setup', 'lnkup-marketplace' ),
			'responsive'             => __( 'Responsive', 'lnkup-marketplace' ),
			'followed_guidelines'    => __( 'Followed guidelines', 'lnkup-marketplace' ),
			'easy_to_work_with'      => __( 'Easy to work with', 'lnkup-marketplace' ),
			'crowd_favorite'         => __( 'Crowd favorite', 'lnkup-marketplace' ),
			'professional_presentation' => __( 'Professional presentation', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Reasons a vendor may report a review.
	 */
	public static function report_reasons() {
		return array(
			'inaccurate'      => __( 'Factually inaccurate', 'lnkup-marketplace' ),
			'abusive'         => __( 'Abusive or a personal attack', 'lnkup-marketplace' ),
			'discriminatory'  => __( 'Discriminatory or hateful', 'lnkup-marketplace' ),
			'private_info'    => __( 'Shares private information', 'lnkup-marketplace' ),
			'retaliation'     => __( 'Retaliation or coercion', 'lnkup-marketplace' ),
			'not_my_event'    => __( 'I did not work this event', 'lnkup-marketplace' ),
			'other'           => __( 'Other', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Documented moderation reason codes for hiding a review.
	 */
	public static function moderation_reasons() {
		return array(
			'policy_abuse'      => __( 'Abusive language or personal attack', 'lnkup-marketplace' ),
			'policy_discrimination' => __( 'Discriminatory content', 'lnkup-marketplace' ),
			'policy_private_info'   => __( 'Disclosed private information', 'lnkup-marketplace' ),
			'policy_coercion'   => __( 'Coercion, extortion or retaliation', 'lnkup-marketplace' ),
			'ineligible'        => __( 'Reviewer was not eligible', 'lnkup-marketplace' ),
			'factually_unsupported' => __( 'Claims not supported by records', 'lnkup-marketplace' ),
			'duplicate'         => __( 'Duplicate review', 'lnkup-marketplace' ),
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
		$is_admin     = LNKup_Capabilities::user_has_manage_all( $organizer_id );

		if ( ! $app_id || 'lnkup_application' !== get_post_type( $app_id ) ) {
			return array( 'allowed' => false, 'reason' => __( 'Booking not found.', 'lnkup-marketplace' ) );
		}

		if ( ! LNKup_Capabilities::user_owns_post( $app_id, $organizer_id, '_lnkup_organizer_user_id' ) && ! $is_admin ) {
			return array( 'allowed' => false, 'reason' => __( 'You do not own this application.', 'lnkup-marketplace' ) );
		}

		$vendor_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		if ( $vendor_id && $vendor_id === (int) $organizer_id ) {
			return array( 'allowed' => false, 'reason' => __( 'You cannot review your own business.', 'lnkup-marketplace' ) );
		}

		if ( self::get_by_application( $app_id ) || self::index_has_application( $app_id ) ) {
			return array( 'allowed' => false, 'reason' => __( 'A review already exists for this booking.', 'lnkup-marketplace' ) );
		}

		$status     = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		$checked_in = (bool) get_post_meta( $app_id, '_lnkup_checked_in_at', true );
		if ( in_array( $status, array( 'submitted', 'under_review', 'info_requested', 'waitlisted', 'rejected', 'cancelled' ), true ) ) {
			return array( 'allowed' => false, 'reason' => __( 'Only confirmed bookings can be reviewed.', 'lnkup-marketplace' ) );
		}
		if ( ! in_array( $status, array( 'paid', 'completed' ), true ) && ! $checked_in ) {
			return array( 'allowed' => false, 'reason' => __( 'Only confirmed bookings can be reviewed.', 'lnkup-marketplace' ) );
		}

		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		if ( ! self::event_has_ended( $event_id ) && ! $is_admin ) {
			return array( 'allowed' => false, 'reason' => __( 'Reviews open after the event date.', 'lnkup-marketplace' ) );
		}

		return array(
			'allowed'                => true,
			'reason'                 => '',
			'verified_participation' => $checked_in,
		);
	}

	/**
	 * Whether the event is over (end date, else start date, before today).
	 *
	 * @param int $event_id Event ID.
	 */
	public static function event_has_ended( $event_id ) {
		$event_id = absint( $event_id );
		if ( ! $event_id ) {
			return true;
		}
		$end  = (string) get_post_meta( $event_id, '_lnkup_event_end_date', true );
		$date = '' !== $end ? $end : (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		if ( '' === $date ) {
			return true;
		}
		$ts = strtotime( $date );
		return false === $ts || $ts < strtotime( 'today' );
	}

	/**
	 * Create review. The unique application index row is claimed first so concurrent submissions cannot both succeed.
	 *
	 * @param int   $organizer_id Organizer user ID.
	 * @param array $data         Review data.
	 */
	public static function create( $organizer_id, $data ) {
		$app_id = absint( $data['application_id'] ?? 0 );
		$check  = self::can_review( $app_id, $organizer_id );
		if ( ! $check['allowed'] ) {
			return new WP_Error( 'not_allowed', $check['reason'], array( 'status' => 403 ) );
		}

		$vendor_id  = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		if ( ! $profile_id && $vendor_id ) {
			$profile_id = (int) lnkup_get_vendor_profile_id( $vendor_id );
		}
		$event_id   = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$overall    = max( 1, min( 5, absint( $data['overall_rating'] ?? 0 ) ) );
		$categories = self::sanitize_categories( $data['category_ratings'] ?? array() );
		$tags       = self::sanitize_tags( $data['positive_tags'] ?? array() );
		$verified   = ! empty( $check['verified_participation'] );

		if ( ! self::claim_index( $app_id, (int) $organizer_id, $profile_id, $event_id, $overall, $verified ) ) {
			return new WP_Error( 'duplicate_review', __( 'A review already exists for this booking.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}

		$review_id = wp_insert_post(
			array(
				'post_type'    => 'lnkup_review',
				'post_status'  => 'publish',
				/* translators: %d: application ID */
				'post_title'   => sprintf( __( 'Review — App #%d', 'lnkup-marketplace' ), $app_id ),
				'post_content' => sanitize_textarea_field( $data['public_text'] ?? '' ),
				'post_author'  => $organizer_id,
			),
			true
		);

		if ( is_wp_error( $review_id ) || ! $review_id ) {
			self::release_index( $app_id );
			return is_wp_error( $review_id ) ? $review_id : new WP_Error( 'review_failed', __( 'Could not save the review.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}

		update_post_meta( $review_id, '_lnkup_organizer_user_id', $organizer_id );
		update_post_meta( $review_id, '_lnkup_vendor_user_id', $vendor_id );
		update_post_meta( $review_id, '_lnkup_vendor_profile_id', $profile_id );
		update_post_meta( $review_id, '_lnkup_application_id', $app_id );
		update_post_meta( $review_id, '_lnkup_event_id', $event_id );
		update_post_meta( $review_id, '_lnkup_overall_rating', $overall );
		update_post_meta( $review_id, '_lnkup_category_ratings', wp_json_encode( $categories ) );
		update_post_meta( $review_id, '_lnkup_positive_tags', wp_json_encode( $tags ) );
		update_post_meta( $review_id, '_lnkup_public_text', sanitize_textarea_field( $data['public_text'] ?? '' ) );
		update_post_meta( $review_id, '_lnkup_private_note', sanitize_textarea_field( $data['private_note'] ?? '' ) );
		update_post_meta( $review_id, '_lnkup_internal_note', sanitize_textarea_field( $data['internal_note'] ?? '' ) );
		update_post_meta( $review_id, '_lnkup_status', self::STATUS_PUBLISHED );
		update_post_meta( $review_id, '_lnkup_verified_participation', $verified ? 1 : 0 );
		update_post_meta( $review_id, '_lnkup_late_arrival', ! empty( $data['late_arrival'] ) ? 1 : 0 );
		update_post_meta( $review_id, '_lnkup_issue_flagged', ! empty( $data['issue_flagged'] ) ? 1 : 0 );

		self::update_index( $app_id, array( 'review_id' => (int) $review_id ) );
		update_post_meta( $app_id, '_lnkup_review_id', $review_id );
		update_post_meta( $app_id, '_lnkup_organizer_reviewed_at', current_time( 'mysql' ) );

		do_action( 'lnkup_vendor_review_submitted', $review_id, $profile_id );
		return $review_id;
	}

	/**
	 * Update review (author within the edit window, or reopened by moderation).
	 */
	public static function update( $review_id, $organizer_id, $data ) {
		$review_id = absint( $review_id );
		if ( 'lnkup_review' !== get_post_type( $review_id ) ) {
			return new WP_Error( 'not_found', __( 'Review not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$is_admin = LNKup_Capabilities::user_has_manage_all( $organizer_id );
		if ( (int) get_post_meta( $review_id, '_lnkup_organizer_user_id', true ) !== (int) $organizer_id && ! $is_admin ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( ! $is_admin && ! self::edit_window_open( $review_id ) ) {
			return new WP_Error( 'expired', __( 'Edit window has closed.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		$before = self::history_snapshot( $review_id );

		if ( isset( $data['public_text'] ) ) {
			wp_update_post( array( 'ID' => $review_id, 'post_content' => sanitize_textarea_field( $data['public_text'] ) ) );
			update_post_meta( $review_id, '_lnkup_public_text', sanitize_textarea_field( $data['public_text'] ) );
		}
		if ( isset( $data['private_note'] ) ) {
			update_post_meta( $review_id, '_lnkup_private_note', sanitize_textarea_field( $data['private_note'] ) );
		}
		if ( isset( $data['overall_rating'] ) ) {
			$overall = max( 1, min( 5, absint( $data['overall_rating'] ) ) );
			update_post_meta( $review_id, '_lnkup_overall_rating', $overall );
			self::update_index( (int) get_post_meta( $review_id, '_lnkup_application_id', true ), array( 'overall' => $overall ) );
		}
		if ( isset( $data['category_ratings'] ) ) {
			update_post_meta( $review_id, '_lnkup_category_ratings', wp_json_encode( self::sanitize_categories( $data['category_ratings'] ) ) );
		}
		if ( isset( $data['positive_tags'] ) ) {
			update_post_meta( $review_id, '_lnkup_positive_tags', wp_json_encode( self::sanitize_tags( $data['positive_tags'] ) ) );
		}
		delete_post_meta( $review_id, '_lnkup_edit_requested' );

		$history   = self::get_history( $review_id );
		$history[] = array(
			'at'     => current_time( 'mysql' ),
			'by'     => (int) $organizer_id,
			'before' => $before,
		);
		update_post_meta( $review_id, '_lnkup_edit_history', wp_json_encode( array_slice( $history, -20 ) ) );

		$profile_id = (int) get_post_meta( $review_id, '_lnkup_vendor_profile_id', true );
		LNKup_Vendor_Reputation::log_activity( 'review_edited', $profile_id, array( 'payload' => array( 'review_id' => $review_id ) ) );
		LNKup_Vendor_Reputation::refresh( $profile_id );
		do_action( 'lnkup_vendor_review_updated', $review_id, $profile_id );
		return true;
	}

	/**
	 * Whether the author may still edit (initial window, or a window reopened by a moderator).
	 *
	 * @param int $review_id Review ID.
	 */
	public static function edit_window_open( $review_id ) {
		$post = get_post( $review_id );
		if ( ! $post ) {
			return false;
		}
		$reopened = (string) get_post_meta( $review_id, '_lnkup_edit_window_until', true );
		if ( '' !== $reopened && strtotime( $reopened ) > time() ) {
			return true;
		}
		$days = class_exists( 'LNKup_Passport_Settings' ) ? LNKup_Passport_Settings::int( 'passport_review_edit_days' ) : 7;
		return strtotime( $post->post_date ) >= strtotime( '-' . $days . ' days' );
	}

	/**
	 * Vendor posts (or edits, within the window) one public response. The original review is never modified.
	 *
	 * @param int    $review_id Review ID.
	 * @param int    $vendor_id Vendor user ID.
	 * @param string $text      Response text.
	 */
	public static function respond( $review_id, $vendor_id, $text ) {
		$review_id = absint( $review_id );
		if ( 'lnkup_review' !== get_post_type( $review_id ) ) {
			return new WP_Error( 'not_found', __( 'Review not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( (int) get_post_meta( $review_id, '_lnkup_vendor_user_id', true ) !== (int) $vendor_id ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( self::STATUS_PUBLISHED !== self::status_of( $review_id ) ) {
			return new WP_Error( 'not_published', __( 'You can only respond to published reviews.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$text = trim( sanitize_textarea_field( (string) $text ) );
		if ( strlen( $text ) < 2 || strlen( $text ) > 2000 ) {
			return new WP_Error( 'invalid_response', __( 'Responses must be between 2 and 2,000 characters.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$existing_at = (string) get_post_meta( $review_id, '_lnkup_vendor_response_at', true );
		if ( '' !== $existing_at ) {
			$days = class_exists( 'LNKup_Passport_Settings' ) ? LNKup_Passport_Settings::int( 'passport_response_edit_days' ) : 7;
			if ( strtotime( $existing_at ) < strtotime( '-' . $days . ' days' ) ) {
				return new WP_Error( 'expired', __( 'The edit window for your response has closed.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
		}
		update_post_meta( $review_id, '_lnkup_vendor_response', $text );
		update_post_meta( $review_id, '_lnkup_vendor_response_status', self::STATUS_PUBLISHED );
		if ( '' === $existing_at ) {
			update_post_meta( $review_id, '_lnkup_vendor_response_at', current_time( 'mysql' ) );
		}
		update_post_meta( $review_id, '_lnkup_vendor_response_updated_at', current_time( 'mysql' ) );

		$profile_id = (int) get_post_meta( $review_id, '_lnkup_vendor_profile_id', true );
		LNKup_Vendor_Reputation::log_activity( '' === $existing_at ? 'review_response_posted' : 'review_response_edited', $profile_id, array( 'payload' => array( 'review_id' => $review_id ) ) );
		do_action( 'lnkup_review_response_posted', $review_id, $profile_id, '' === $existing_at );
		self::flush_public_cache( $profile_id );
		return self::format( get_post( $review_id ), false, (int) $vendor_id );
	}

	/**
	 * Vendor reports a review. Opens a dispute; the review stays visible until a moderator decides.
	 *
	 * @param int    $review_id Review ID.
	 * @param int    $vendor_id Vendor user ID.
	 * @param string $reason    Free-text reason.
	 * @param string $code      Reason code from report_reasons().
	 */
	public static function report( $review_id, $vendor_id, $reason, $code = 'other' ) {
		$review_id = absint( $review_id );
		if ( 'lnkup_review' !== get_post_type( $review_id ) ) {
			return new WP_Error( 'not_found', __( 'Review not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( (int) get_post_meta( $review_id, '_lnkup_vendor_user_id', true ) !== (int) $vendor_id ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$code = sanitize_key( (string) $code );
		if ( ! isset( self::report_reasons()[ $code ] ) ) {
			$code = 'other';
		}
		$reason = trim( sanitize_textarea_field( (string) $reason ) );
		if ( strlen( $reason ) < 10 ) {
			return new WP_Error( 'reason_required', __( 'Tell our team what is wrong with this review (at least 10 characters).', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( self::open_dispute_for_review( $review_id ) ) {
			return new WP_Error( 'already_reported', __( 'This review is already being looked at by our team.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$cap = class_exists( 'LNKup_Passport_Settings' ) ? LNKup_Passport_Settings::int( 'passport_report_monthly_cap' ) : 5;
		if ( self::reports_in_last_30_days( (int) $vendor_id ) >= $cap ) {
			return new WP_Error( 'report_limit', __( 'You have reached the monthly limit for review reports. Contact support if you need help.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}

		$dispute_id = wp_insert_post(
			array(
				'post_type'    => 'lnkup_dispute',
				'post_status'  => 'publish',
				/* translators: %d: review ID */
				'post_title'   => sprintf( __( 'Dispute — Review #%d', 'lnkup-marketplace' ), $review_id ),
				'post_content' => $reason,
			),
			true
		);
		if ( is_wp_error( $dispute_id ) ) {
			return $dispute_id;
		}
		$profile_id = (int) get_post_meta( $review_id, '_lnkup_vendor_profile_id', true );
		update_post_meta( $dispute_id, '_lnkup_review_id', $review_id );
		update_post_meta( $dispute_id, '_lnkup_vendor_user_id', $vendor_id );
		update_post_meta( $dispute_id, '_lnkup_vendor_profile_id', $profile_id );
		update_post_meta( $dispute_id, '_lnkup_organizer_user_id', (int) get_post_meta( $review_id, '_lnkup_organizer_user_id', true ) );
		update_post_meta( $dispute_id, '_lnkup_dispute_kind', 'review' );
		update_post_meta( $dispute_id, '_lnkup_reason', $reason );
		update_post_meta( $dispute_id, '_lnkup_reason_code', $code );
		update_post_meta( $dispute_id, '_lnkup_status', self::DISPUTE_OPEN );
		update_post_meta( $review_id, '_lnkup_under_dispute', 1 );

		LNKup_Vendor_Reputation::log_activity(
			'dispute_opened',
			$profile_id,
			array( 'payload' => array( 'review_id' => $review_id, 'dispute_id' => $dispute_id, 'code' => $code ) )
		);
		do_action( 'lnkup_review_dispute_opened', (int) $dispute_id, $review_id, $profile_id );
		return $dispute_id;
	}

	/**
	 * Moderator decision on an open or appealed dispute.
	 *
	 * @param int    $dispute_id   Dispute ID.
	 * @param string $decision     uphold|hide|request_edit.
	 * @param string $reason_code  Moderation reason (required for hide).
	 * @param string $note         Moderator note (internal).
	 * @param int    $moderator_id Moderator user ID.
	 */
	public static function decide( $dispute_id, $decision, $reason_code = '', $note = '', $moderator_id = 0 ) {
		$dispute_id   = absint( $dispute_id );
		$moderator_id = $moderator_id ?: get_current_user_id();
		if ( ! LNKup_Capabilities::user_has_manage_all( $moderator_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( 'lnkup_dispute' !== get_post_type( $dispute_id ) ) {
			return new WP_Error( 'not_found', __( 'Dispute not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$decision = sanitize_key( (string) $decision );
		if ( ! in_array( $decision, self::DECISIONS, true ) ) {
			return new WP_Error( 'invalid_decision', __( 'Choose uphold, hide or request edit.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$current = (string) get_post_meta( $dispute_id, '_lnkup_status', true );
		if ( ! in_array( $current, array( self::DISPUTE_OPEN, self::DISPUTE_APPEALED, 'open', '' ), true ) ) {
			return new WP_Error( 'already_decided', __( 'This dispute has already been decided.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$reason_code = sanitize_key( (string) $reason_code );
		if ( 'hide' === $decision && ! isset( self::moderation_reasons()[ $reason_code ] ) ) {
			return new WP_Error( 'reason_required', __( 'Hiding a review requires a documented reason.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$review_id  = (int) get_post_meta( $dispute_id, '_lnkup_review_id', true );
		$profile_id = (int) get_post_meta( $review_id, '_lnkup_vendor_profile_id', true );
		$was_appeal = self::DISPUTE_APPEALED === $current;

		switch ( $decision ) {
			case 'hide':
				self::set_status( $review_id, self::STATUS_HIDDEN );
				update_post_meta( $review_id, '_lnkup_moderation_reason', $reason_code );
				$new_status = self::DISPUTE_REVIEW_HIDDEN;
				break;
			case 'request_edit':
				self::set_status( $review_id, self::STATUS_PUBLISHED );
				update_post_meta( $review_id, '_lnkup_edit_requested', 1 );
				update_post_meta( $review_id, '_lnkup_edit_window_until', gmdate( 'Y-m-d H:i:s', strtotime( '+14 days' ) ) );
				$new_status = self::DISPUTE_EDIT_REQUESTED;
				break;
			case 'uphold':
			default:
				self::set_status( $review_id, self::STATUS_PUBLISHED );
				delete_post_meta( $review_id, '_lnkup_moderation_reason' );
				$new_status = self::DISPUTE_UPHELD;
				break;
		}
		delete_post_meta( $review_id, '_lnkup_under_dispute' );

		update_post_meta( $dispute_id, '_lnkup_status', $new_status );
		update_post_meta( $dispute_id, '_lnkup_decision', $decision );
		update_post_meta( $dispute_id, '_lnkup_decision_reason', $reason_code );
		update_post_meta( $dispute_id, '_lnkup_admin_resolution', sanitize_textarea_field( (string) $note ) );
		update_post_meta( $dispute_id, '_lnkup_decided_by', $moderator_id );
		update_post_meta( $dispute_id, '_lnkup_decided_at', current_time( 'mysql' ) );
		if ( $was_appeal ) {
			update_post_meta( $dispute_id, '_lnkup_final', 1 );
		}

		LNKup_Vendor_Reputation::log_activity(
			'review_moderated',
			$profile_id,
			array(
				'payload' => array(
					'review_id'  => $review_id,
					'dispute_id' => $dispute_id,
					'decision'   => $decision,
					'reason'     => $reason_code,
					'appeal'     => $was_appeal,
				),
			)
		);
		LNKup_Vendor_Reputation::refresh( $profile_id );
		self::flush_public_cache( $profile_id );
		do_action( 'lnkup_review_moderated', $review_id, $decision, $dispute_id, $profile_id );
		return array( 'dispute_id' => $dispute_id, 'status' => $new_status, 'review_status' => self::status_of( $review_id ) );
	}

	/**
	 * A party to a decided dispute (vendor or reviewing organizer) asks for one final review.
	 *
	 * @param int    $dispute_id Dispute ID.
	 * @param int    $user_id    Requesting user.
	 * @param string $reason     Appeal reason.
	 */
	public static function appeal( $dispute_id, $user_id, $reason ) {
		$dispute_id = absint( $dispute_id );
		if ( 'lnkup_dispute' !== get_post_type( $dispute_id ) ) {
			return new WP_Error( 'not_found', __( 'Dispute not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$vendor_id    = (int) get_post_meta( $dispute_id, '_lnkup_vendor_user_id', true );
		$organizer_id = (int) get_post_meta( $dispute_id, '_lnkup_organizer_user_id', true );
		if ( (int) $user_id !== $vendor_id && (int) $user_id !== $organizer_id ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$status = (string) get_post_meta( $dispute_id, '_lnkup_status', true );
		if ( ! in_array( $status, array( self::DISPUTE_UPHELD, self::DISPUTE_REVIEW_HIDDEN, self::DISPUTE_EDIT_REQUESTED ), true ) ) {
			return new WP_Error( 'not_appealable', __( 'This dispute cannot be appealed right now.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( get_post_meta( $dispute_id, '_lnkup_final', true ) || get_post_meta( $dispute_id, '_lnkup_appealed_at', true ) ) {
			return new WP_Error( 'appeal_used', __( 'An appeal has already been decided for this review.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$decided_at = (string) get_post_meta( $dispute_id, '_lnkup_decided_at', true );
		if ( $decided_at && strtotime( $decided_at ) < strtotime( '-' . self::APPEAL_WINDOW_DAYS . ' days' ) ) {
			return new WP_Error( 'appeal_expired', __( 'The appeal window has closed.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$reason = trim( sanitize_textarea_field( (string) $reason ) );
		if ( strlen( $reason ) < 10 ) {
			return new WP_Error( 'reason_required', __( 'Explain why the decision should be reconsidered (at least 10 characters).', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		update_post_meta( $dispute_id, '_lnkup_status', self::DISPUTE_APPEALED );
		update_post_meta( $dispute_id, '_lnkup_appeal_reason', $reason );
		update_post_meta( $dispute_id, '_lnkup_appealed_by', (int) $user_id );
		update_post_meta( $dispute_id, '_lnkup_appealed_at', current_time( 'mysql' ) );
		$profile_id = (int) get_post_meta( $dispute_id, '_lnkup_vendor_profile_id', true );
		LNKup_Vendor_Reputation::log_activity( 'dispute_appealed', $profile_id, array( 'payload' => array( 'dispute_id' => $dispute_id, 'by' => (int) $user_id ) ) );
		return array( 'dispute_id' => $dispute_id, 'status' => self::DISPUTE_APPEALED );
	}

	/**
	 * Get review by application.
	 */
	public static function get_by_application( $application_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_review',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_application_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => absint( $application_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $posts ? self::format( $posts[0] ) : null;
	}

	/**
	 * Published reviews for vendor profile.
	 *
	 * @param int  $profile_id      Profile ID.
	 * @param int  $limit           Max rows.
	 * @param bool $include_private Legacy flag; private notes still require authorship or staff.
	 * @param int  $viewer_id       Viewer user ID (defaults to current user).
	 * @param int  $offset          Pagination offset.
	 */
	public static function get_for_vendor( $profile_id, $limit = 20, $include_private = false, $viewer_id = null, $offset = 0 ) {
		$viewer_id = null === $viewer_id ? get_current_user_id() : (int) $viewer_id;
		$query     = array(
			'post_type'      => 'lnkup_review',
			'posts_per_page' => max( 1, min( 100, (int) $limit ) ),
			'offset'         => max( 0, (int) $offset ),
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
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
			$items[] = self::format( $post, $include_private, $viewer_id );
		}
		return $items;
	}

	/**
	 * Count of published reviews for paging.
	 *
	 * @param int $profile_id Profile ID.
	 */
	public static function count_for_vendor( $profile_id ) {
		if ( self::index_ready() ) {
			global $wpdb;
			$table = LNKup_Passport_Tables::review_index_table();
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE vendor_profile_id = %d AND status = %s", absint( $profile_id ), self::STATUS_PUBLISHED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return count( self::published_ids( $profile_id ) );
	}

	/**
	 * Review summary for vendor (published reviews only).
	 */
	public static function summary_for_vendor( $profile_id ) {
		$reviews = self::published_ids( $profile_id );
		if ( ! $reviews ) {
			return array( 'avg' => 0, 'count' => 0, 'categories' => array() );
		}
		$sum        = 0;
		$cat_sums   = array();
		$cat_counts = array();
		foreach ( $reviews as $rid ) {
			$sum += (int) get_post_meta( $rid, '_lnkup_overall_rating', true );
			foreach ( self::public_category_scores( $rid ) as $k => $v ) {
				if ( null === $v ) {
					continue;
				}
				$cat_sums[ $k ]   = ( $cat_sums[ $k ] ?? 0 ) + (float) $v;
				$cat_counts[ $k ] = ( $cat_counts[ $k ] ?? 0 ) + 1;
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
	 * Published review IDs for a profile.
	 *
	 * @param int $profile_id Profile ID.
	 * @return int[]
	 */
	public static function published_ids( $profile_id ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'lnkup_review',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => '_lnkup_vendor_profile_id', 'value' => absint( $profile_id ) ),
						array( 'key' => '_lnkup_status', 'value' => self::STATUS_PUBLISHED ),
					),
				)
			)
		);
	}

	/**
	 * The five public category scores for one review (null when not rated).
	 *
	 * @param int $review_id Review ID.
	 * @return array<string,float|null>
	 */
	public static function public_category_scores( $review_id ) {
		$cats = json_decode( (string) get_post_meta( $review_id, '_lnkup_category_ratings', true ) ?: '{}', true );
		$cats = is_array( $cats ) ? $cats : array();
		$avg  = static function ( array $keys ) use ( $cats ) {
			$vals = array();
			foreach ( $keys as $key ) {
				if ( isset( $cats[ $key ] ) && (float) $cats[ $key ] > 0 ) {
					$vals[] = (float) $cats[ $key ];
				}
			}
			return $vals ? round( array_sum( $vals ) / count( $vals ), 1 ) : null;
		};
		return array(
			'communication'      => $avg( array( 'communication' ) ),
			'professionalism'    => $avg( array( 'professionalism' ) ),
			'preparedness'       => isset( $cats['preparedness'] ) ? $avg( array( 'preparedness' ) ) : $avg( array( 'setup_readiness', 'arrival_time' ) ),
			'event_coordination' => $avg( array( 'event_coordination' ) ),
			'overall'            => (float) get_post_meta( $review_id, '_lnkup_overall_rating', true ) ?: null,
		);
	}

	/**
	 * Format review for API/UI.
	 *
	 * Private and internal notes are only returned to the organizer who wrote the review, or to staff.
	 *
	 * @param WP_Post  $post            Review post.
	 * @param bool     $include_private Request private notes (still subject to authorship).
	 * @param int|null $viewer_id       Viewer user ID.
	 */
	public static function format( $post, $include_private = false, $viewer_id = null ) {
		$viewer_id    = null === $viewer_id ? get_current_user_id() : (int) $viewer_id;
		$cats         = json_decode( (string) get_post_meta( $post->ID, '_lnkup_category_ratings', true ) ?: '{}', true );
		$tags         = json_decode( (string) get_post_meta( $post->ID, '_lnkup_positive_tags', true ) ?: '[]', true );
		$event_id     = (int) get_post_meta( $post->ID, '_lnkup_event_id', true );
		$organizer_id = (int) get_post_meta( $post->ID, '_lnkup_organizer_user_id', true );
		$org          = get_userdata( $organizer_id );
		$tag_labels   = self::positive_tags();
		$response     = (string) get_post_meta( $post->ID, '_lnkup_vendor_response', true );
		$response_ok  = '' !== $response && self::STATUS_PUBLISHED === ( get_post_meta( $post->ID, '_lnkup_vendor_response_status', true ) ?: self::STATUS_PUBLISHED );
		$item         = array(
			'id'                     => $post->ID,
			'overall_rating'         => (int) get_post_meta( $post->ID, '_lnkup_overall_rating', true ),
			'category_ratings'       => is_array( $cats ) ? $cats : array(),
			'public_categories'      => self::public_category_scores( $post->ID ),
			'positive_tags'          => array_values(
				array_map(
					static function ( $slug ) use ( $tag_labels ) {
						return array( 'slug' => $slug, 'label' => $tag_labels[ $slug ] ?? $slug );
					},
					array_filter( (array) $tags, static fn( $slug ) => isset( $tag_labels[ $slug ] ) )
				)
			),
			'public_text'            => get_post_meta( $post->ID, '_lnkup_public_text', true ) ?: $post->post_content,
			'status'                 => self::status_of( $post->ID ),
			'under_review'           => (bool) get_post_meta( $post->ID, '_lnkup_under_dispute', true ),
			'verified_participation' => (bool) get_post_meta( $post->ID, '_lnkup_verified_participation', true ),
			'event_id'               => $event_id,
			'event_title'            => $event_id ? lnkup_plain_title( $event_id ) : '',
			'organizer_name'         => $org ? $org->display_name : '',
			'created_at'             => $post->post_date,
			'application_id'         => (int) get_post_meta( $post->ID, '_lnkup_application_id', true ),
			'vendor_response'        => $response_ok ? array(
				'text'       => $response,
				'created_at' => (string) get_post_meta( $post->ID, '_lnkup_vendor_response_at', true ),
				'updated_at' => (string) get_post_meta( $post->ID, '_lnkup_vendor_response_updated_at', true ),
			) : null,
		);
		$is_author = $viewer_id > 0 && $viewer_id === $organizer_id;
		$is_staff  = $viewer_id > 0 && LNKup_Capabilities::user_has_manage_all( $viewer_id );
		if ( $is_author || $is_staff ) {
			$item['private_note']  = get_post_meta( $post->ID, '_lnkup_private_note', true );
			$item['internal_note'] = get_post_meta( $post->ID, '_lnkup_internal_note', true );
			$item['can_edit']      = $is_staff || self::edit_window_open( $post->ID );
			$item['edit_requested'] = (bool) get_post_meta( $post->ID, '_lnkup_edit_requested', true );
		}
		unset( $include_private );
		return $item;
	}

	/**
	 * Admin hide/remove/publish a review (allow-listed statuses only).
	 */
	public static function moderate( $review_id, $status ) {
		$review_id = absint( $review_id );
		$status    = sanitize_key( (string) $status );
		if ( 'lnkup_review' !== get_post_type( $review_id ) || ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$previous = self::status_of( $review_id );
		self::set_status( $review_id, $status );
		$profile_id = (int) get_post_meta( $review_id, '_lnkup_vendor_profile_id', true );
		LNKup_Vendor_Reputation::log_activity(
			'review_status_changed',
			$profile_id,
			array( 'payload' => array( 'review_id' => $review_id, 'from' => $previous, 'to' => $status ) )
		);
		LNKup_Vendor_Reputation::refresh( $profile_id );
		self::flush_public_cache( $profile_id );
		return true;
	}

	/**
	 * Current normalized status (legacy "disputed" reads as published-under-review).
	 *
	 * @param int $review_id Review ID.
	 */
	public static function status_of( $review_id ) {
		$status = (string) get_post_meta( $review_id, '_lnkup_status', true );
		if ( self::STATUS_DISPUTED === $status ) {
			return self::STATUS_PUBLISHED;
		}
		return in_array( $status, self::STATUSES, true ) ? $status : self::STATUS_PUBLISHED;
	}

	/**
	 * Open (or appealed) dispute for a review.
	 *
	 * @param int $review_id Review ID.
	 */
	public static function open_dispute_for_review( $review_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_dispute',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_review_id', 'value' => absint( $review_id ) ),
					array( 'key' => '_lnkup_status', 'value' => array( self::DISPUTE_OPEN, self::DISPUTE_APPEALED ), 'compare' => 'IN' ),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Disputes for a review (newest first) for vendor/organizer status tracking.
	 *
	 * @param int $review_id Review ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function disputes_for_review( $review_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_dispute',
				'posts_per_page' => 10,
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_review_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => absint( $review_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'id'          => $post->ID,
				'status'      => (string) get_post_meta( $post->ID, '_lnkup_status', true ),
				'reason_code' => (string) get_post_meta( $post->ID, '_lnkup_reason_code', true ),
				'decision'    => (string) get_post_meta( $post->ID, '_lnkup_decision', true ),
				'decided_at'  => (string) get_post_meta( $post->ID, '_lnkup_decided_at', true ),
				'can_appeal'  => in_array( (string) get_post_meta( $post->ID, '_lnkup_status', true ), array( self::DISPUTE_UPHELD, self::DISPUTE_REVIEW_HIDDEN, self::DISPUTE_EDIT_REQUESTED ), true )
					&& ! get_post_meta( $post->ID, '_lnkup_appealed_at', true ),
				'created_at'  => $post->post_date,
			);
		}
		return $out;
	}

	/**
	 * Backfill the review index and convert legacy "disputed" (auto-hidden) reviews. Idempotent.
	 *
	 * @param int $batch Batch size.
	 * @return int Rows processed.
	 */
	public static function backfill_index( $batch = 200 ) {
		if ( ! self::index_ready() ) {
			return 0;
		}
		global $wpdb;
		$table = LNKup_Passport_Tables::review_index_table();
		$ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$table} ri ON ri.review_id = p.ID
				WHERE p.post_type = 'lnkup_review' AND ri.id IS NULL
				ORDER BY p.ID ASC LIMIT %d",
				max( 1, (int) $batch )
			)
		);
		foreach ( (array) $ids as $review_id ) {
			$review_id = (int) $review_id;
			if ( self::STATUS_DISPUTED === (string) get_post_meta( $review_id, '_lnkup_status', true ) ) {
				update_post_meta( $review_id, '_lnkup_status', self::STATUS_PUBLISHED );
				if ( self::open_dispute_for_review( $review_id ) ) {
					update_post_meta( $review_id, '_lnkup_under_dispute', 1 );
				}
			}
			$app_id   = (int) get_post_meta( $review_id, '_lnkup_application_id', true );
			$post     = get_post( $review_id );
			$app_sql  = $app_id ? (string) $app_id : 'NULL';
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (application_id, review_id, organizer_user_id, vendor_profile_id, event_id, overall, status, verified_participation, created_at) VALUES ({$app_sql}, %d, %d, %d, %d, %d, %s, %d, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$review_id,
					(int) get_post_meta( $review_id, '_lnkup_organizer_user_id', true ),
					(int) get_post_meta( $review_id, '_lnkup_vendor_profile_id', true ),
					(int) get_post_meta( $review_id, '_lnkup_event_id', true ),
					(int) get_post_meta( $review_id, '_lnkup_overall_rating', true ),
					self::status_of( $review_id ),
					$app_id && get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ? 1 : 0,
					$post ? $post->post_date : current_time( 'mysql' )
				)
			);
			if ( $app_id && get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
				update_post_meta( $review_id, '_lnkup_verified_participation', 1 );
			}
		}
		return count( (array) $ids );
	}

	/**
	 * Published reviews grouped for the reputation summary: organizer => list of overall ratings.
	 *
	 * @param int $profile_id Profile ID.
	 * @return array<int,int[]>
	 */
	public static function ratings_by_organizer( $profile_id ) {
		$out = array();
		if ( self::index_ready() ) {
			global $wpdb;
			$table = LNKup_Passport_Tables::review_index_table();
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT organizer_user_id, overall FROM {$table} WHERE vendor_profile_id = %d AND status = %s AND review_id > 0", absint( $profile_id ), self::STATUS_PUBLISHED ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$out[ (int) $row['organizer_user_id'] ][] = (int) $row['overall'];
			}
			if ( $out || ! self::published_ids( $profile_id ) ) {
				return $out;
			}
		}
		foreach ( self::published_ids( $profile_id ) as $rid ) {
			$out[ (int) get_post_meta( $rid, '_lnkup_organizer_user_id', true ) ][] = (int) get_post_meta( $rid, '_lnkup_overall_rating', true );
		}
		return $out;
	}

	/**
	 * Sanitize category ratings.
	 */
	private static function sanitize_categories( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( self::categories() as $key => $label ) {
			if ( isset( $raw[ $key ] ) ) {
				$out[ $key ] = max( 1, min( 5, absint( $raw[ $key ] ) ) );
			}
		}
		return $out;
	}

	/**
	 * Sanitize positive tags to the allow-list.
	 *
	 * @param mixed $raw Raw tags.
	 * @return string[]
	 */
	private static function sanitize_tags( $raw ) {
		$allowed = self::positive_tags();
		$tags    = array();
		foreach ( (array) $raw as $tag ) {
			$tag = sanitize_key( (string) $tag );
			if ( isset( $allowed[ $tag ] ) ) {
				$tags[] = $tag;
			}
		}
		return array_values( array_unique( $tags ) );
	}

	private static function set_status( $review_id, $status ) {
		update_post_meta( $review_id, '_lnkup_status', $status );
		self::update_index( (int) get_post_meta( $review_id, '_lnkup_application_id', true ), array( 'status' => $status ), (int) $review_id );
	}

	private static function history_snapshot( $review_id ) {
		return array(
			'overall'     => (int) get_post_meta( $review_id, '_lnkup_overall_rating', true ),
			'public_text' => (string) get_post_meta( $review_id, '_lnkup_public_text', true ),
			'categories'  => (string) get_post_meta( $review_id, '_lnkup_category_ratings', true ),
		);
	}

	private static function get_history( $review_id ) {
		$history = json_decode( (string) get_post_meta( $review_id, '_lnkup_edit_history', true ) ?: '[]', true );
		return is_array( $history ) ? $history : array();
	}

	private static function reports_in_last_30_days( int $vendor_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_dispute',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array( array( 'after' => '30 days ago' ) ),
				'meta_key'       => '_lnkup_vendor_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $vendor_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return count( $ids );
	}

	private static function index_ready() {
		return class_exists( 'LNKup_Passport_Tables' ) && LNKup_Passport_Tables::exists( LNKup_Passport_Tables::review_index_table() );
	}

	private static function index_has_application( int $app_id ) {
		if ( ! self::index_ready() ) {
			return false;
		}
		global $wpdb;
		$table = LNKup_Passport_Tables::review_index_table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE application_id = %d", $app_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Atomically claim the one-review-per-booking slot.
	 */
	private static function claim_index( int $app_id, int $organizer_id, int $profile_id, int $event_id, int $overall, bool $verified ) {
		if ( ! self::index_ready() ) {
			return true;
		}
		global $wpdb;
		$table    = LNKup_Passport_Tables::review_index_table();
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (application_id, review_id, organizer_user_id, vendor_profile_id, event_id, overall, status, verified_participation, created_at) VALUES (%d, 0, %d, %d, %d, %d, %s, %d, %s)",
				$app_id,
				$organizer_id,
				$profile_id,
				$event_id,
				$overall,
				self::STATUS_PUBLISHED,
				$verified ? 1 : 0,
				current_time( 'mysql' )
			)
		);
		return 1 === (int) $inserted;
	}

	private static function release_index( int $app_id ) {
		if ( ! self::index_ready() ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( LNKup_Passport_Tables::review_index_table(), array( 'application_id' => $app_id, 'review_id' => 0 ), array( '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function update_index( int $app_id, array $fields, int $review_id = 0 ) {
		if ( ! self::index_ready() || ( ! $app_id && ! $review_id ) ) {
			return;
		}
		global $wpdb;
		$where = $app_id ? array( 'application_id' => $app_id ) : array( 'review_id' => $review_id );
		$wpdb->update( LNKup_Passport_Tables::review_index_table(), $fields, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function flush_public_cache( int $profile_id ) {
		if ( class_exists( 'LNKup_Passport_Projection' ) ) {
			LNKup_Passport_Projection::flush_public_cache( $profile_id );
		}
	}
}
