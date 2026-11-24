<?php
/**
 * AJAX form handlers.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Ajax {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_lnkup_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'wp_ajax_lnkup_save_profile', array( __CLASS__, 'handle_save_profile' ) );
		add_action( 'wp_ajax_lnkup_upload_document', array( __CLASS__, 'handle_upload_document' ) );
		add_action( 'wp_ajax_lnkup_review_application', array( __CLASS__, 'handle_review_application' ) );
		add_action( 'wp_ajax_lnkup_remind_vendor_documents', array( __CLASS__, 'handle_remind_vendor_documents' ) );
		add_action( 'wp_ajax_lnkup_upload_profile_image', array( __CLASS__, 'handle_upload_profile_image' ) );
		add_action( 'wp_ajax_lnkup_create_event', array( __CLASS__, 'handle_create_event' ) );
		add_action( 'wp_ajax_lnkup_update_event', array( __CLASS__, 'handle_update_event' ) );
		add_action( 'wp_ajax_lnkup_update_organizer_profile', array( __CLASS__, 'handle_update_organizer_profile' ) );
		add_action( 'wp_ajax_lnkup_duplicate_event', array( __CLASS__, 'handle_duplicate_event' ) );
		add_action( 'wp_ajax_lnkup_create_opportunity', array( __CLASS__, 'handle_create_opportunity' ) );
		add_action( 'wp_ajax_lnkup_update_opportunity_pricing', array( __CLASS__, 'handle_update_opportunity_pricing' ) );
		add_action( 'wp_ajax_lnkup_delete_opportunity', array( __CLASS__, 'handle_delete_opportunity' ) );
		add_action( 'wp_ajax_lnkup_set_opportunity_status', array( __CLASS__, 'handle_set_opportunity_status' ) );
		add_action( 'wp_ajax_lnkup_download_document', array( 'LNKup_Documents', 'handle_download' ) );
		add_action( 'wp_ajax_lnkup_export_vendors', array( __CLASS__, 'handle_export_vendors' ) );
		add_action( 'wp_ajax_lnkup_event_intelligence', array( __CLASS__, 'handle_event_intelligence' ) );
		add_action( 'wp_ajax_lnkup_filter_opportunities', array( __CLASS__, 'handle_filter_opportunities' ) );
		add_action( 'wp_ajax_nopriv_lnkup_filter_opportunities', array( __CLASS__, 'handle_filter_opportunities' ) );
		add_action( 'wp_ajax_lnkup_dismiss_vendor_tutorial', array( __CLASS__, 'handle_dismiss_vendor_tutorial' ) );
		add_action( 'wp_ajax_lnkup_dismiss_app_promo', array( __CLASS__, 'handle_dismiss_app_promo' ) );
		add_action( 'wp_ajax_lnkup_app_promo_opt_out', array( __CLASS__, 'handle_app_promo_opt_out' ) );
		add_action( 'wp_ajax_lnkup_vendor_welcome_seen', array( __CLASS__, 'handle_vendor_welcome_seen' ) );
		add_action( 'wp_ajax_lnkup_reset_vendor_tutorial', array( __CLASS__, 'handle_reset_vendor_tutorial' ) );
		add_action( 'wp_ajax_lnkup_dismiss_organizer_tutorial', array( __CLASS__, 'handle_dismiss_organizer_tutorial' ) );
		add_action( 'wp_ajax_lnkup_reset_organizer_tutorial', array( __CLASS__, 'handle_reset_organizer_tutorial' ) );
		add_action( 'wp_ajax_lnkup_dashboard_tour_seen', array( __CLASS__, 'handle_dashboard_tour_seen' ) );
		add_action( 'wp_ajax_lnkup_reset_dashboard_tour', array( __CLASS__, 'handle_reset_dashboard_tour' ) );
	}

	/**
	 * Handle vendor application.
	 */
	public static function handle_apply() {
		if ( ! lnkup_can_browse_marketplace() ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: launch date */
						__( 'Applications open on %s. Complete your profile while you wait.', 'lnkup-marketplace' ),
						lnkup_get_launch_date_label()
					),
				)
			);
		}
		LNKup_Security::verify_nonce( 'lnkup_apply' );
		LNKup_Capabilities::require_cap( 'lnkup_submit_applications' );

		$opp_id  = isset( $_POST['opportunity_id'] ) ? absint( $_POST['opportunity_id'] ) : 0;
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$category = isset( $_POST['selected_category'] ) ? sanitize_text_field( wp_unslash( $_POST['selected_category'] ) ) : '';
		$setup_notes = isset( $_POST['setup_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['setup_notes'] ) ) : '';
		$accepted = ! empty( $_POST['accepted_terms'] );

		$key = 'lnkup_apply_' . get_current_user_id() . '_' . $opp_id;
		if ( get_transient( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Please wait before submitting again.', 'lnkup-marketplace' ) ) );
		}

		$result = LNKup_Applications::submit_with_profile(
			get_current_user_id(),
			$opp_id,
			array(
				'application_message' => $message,
				'selected_category'   => $category,
				'setup_notes'         => $setup_notes,
				'accepted_terms'      => $accepted,
				'invite_token'        => isset( $_POST['invite_token'] ) ? sanitize_text_field( wp_unslash( $_POST['invite_token'] ) ) : '',
				// Sanitized per field by LNKup_Application_Questions::validate_answers() and owned_document_ids().
				'answers'             => isset( $_POST['answers'] ) && is_array( $_POST['answers'] ) ? wp_unslash( $_POST['answers'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'document_ids'        => isset( $_POST['document_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['document_ids'] ) ) : array(),
				'idempotency_key'     => isset( $_POST['idempotency_key'] ) ? sanitize_text_field( wp_unslash( $_POST['idempotency_key'] ) ) : '',
				'share_consent'       => ! empty( $_POST['share_consent'] ),
				'share_document_ids'  => isset( $_POST['share_document_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['share_document_ids'] ) ) : array(),
			)
		);
		if ( is_wp_error( $result ) ) {
			$payload = array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() );
			$data    = $result->get_error_data();
			if ( is_array( $data ) && ! empty( $data['existing_application_id'] ) ) {
				$payload['existing_application_id'] = (int) $data['existing_application_id'];
				$payload['status']                  = $data['status'] ?? '';
			}
			wp_send_json_error( $payload );
		}
		set_transient( $key, 1, 30 );

		$context  = LNKup_Applications::build_apply_context( $opp_id, get_current_user_id() );
		$warnings = is_array( $context ) ? ( $context['warnings'] ?? array() ) : array();

		wp_send_json_success(
			array(
				'message'        => lnkup_copy( 'apply_success' ),
				'application_id' => (int) $result,
				'status'         => 'submitted',
				'warnings'       => $warnings,
			)
		);
	}

	/**
	 * Handle vendor profile save.
	 */
	public static function handle_save_profile() {
		LNKup_Security::verify_nonce( 'lnkup_profile' );
		LNKup_Capabilities::require_cap( 'lnkup_manage_own_profile' );

		$user_id    = get_current_user_id();
		$profile_id = lnkup_get_vendor_profile_id( $user_id );
		$business   = isset( $_POST['business_name'] ) ? sanitize_text_field( wp_unslash( $_POST['business_name'] ) ) : '';

		if ( ! $business ) {
			if ( ! $profile_id ) {
				wp_send_json_error( array( 'message' => __( 'Business name is required.', 'lnkup-marketplace' ) ) );
			}
			$business = (string) get_post_meta( $profile_id, '_lnkup_business_name', true );
			if ( ! $business && $profile_id ) {
				$existing = get_post( $profile_id );
				$business = $existing ? $existing->post_title : '';
			}
		}

		$data = array(
			'post_title'   => $business,
			'post_type'    => 'lnkup_vendor_profile',
			'post_status'  => 'publish',
		);
		// Section forms post only their own fields; keep the description unless this section sent it.
		if ( isset( $_POST['description'] ) || ! $profile_id ) {
			$data['post_content'] = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		}

		if ( $profile_id ) {
			$data['ID'] = $profile_id;
			$profile_id = wp_update_post( $data );
		} else {
			$profile_id = wp_insert_post( $data );
			update_post_meta( $profile_id, '_lnkup_owner_user_id', $user_id );
			update_post_meta( $profile_id, '_lnkup_verification_status', 'pending' );
		}

		$fields = array( 'website', 'instagram', 'tiktok', 'contact_email', 'contact_phone', 'city', 'state', 'setup_type', 'space_needed', 'power_needed', 'water_needed', 'owner_name', 'products_services', 'tent_size', 'trailer_size', 'food_truck_size', 'indoor_outdoor_preference', 'availability_notes' );
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$val = 'contact_email' === $field ? sanitize_email( wp_unslash( $_POST[ $field ] ) ) : ( 'availability_notes' === $field || 'products_services' === $field ? sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) : sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
				update_post_meta( $profile_id, '_lnkup_' . $field, $val );
			}
		}
		if ( class_exists( 'LNKup_Location_Service' ) && ( isset( $_POST['city'] ) || isset( $_POST['state'] ) ) ) {
			$location = LNKup_Location_Service::canonical_profile_location(
				(string) get_post_meta( $profile_id, '_lnkup_city', true ),
				(string) get_post_meta( $profile_id, '_lnkup_state', true ),
				'',
				'lenient'
			);
			if ( is_array( $location ) ) {
				update_post_meta( $profile_id, '_lnkup_city', $location['city'] );
				update_post_meta( $profile_id, '_lnkup_state', $location['state'] );
				update_post_meta( $profile_id, '_lnkup_location_status', $location['status'] );
			}
		}
		if ( isset( $_POST['willing_to_travel'] ) ) {
			update_post_meta( $profile_id, '_lnkup_willing_to_travel', ! empty( $_POST['willing_to_travel'] ) );
		}
		if ( isset( $_POST['has_generator'] ) ) {
			update_post_meta( $profile_id, '_lnkup_has_generator', ! empty( $_POST['has_generator'] ) );
		}
		if ( isset( $_POST['service_radius_miles'] ) ) {
			update_post_meta( $profile_id, '_lnkup_service_radius_miles', absint( $_POST['service_radius_miles'] ) );
		}
		if ( isset( $_POST['preferred_fee_min'] ) ) {
			update_post_meta( $profile_id, '_lnkup_preferred_fee_min', LNKup_Security::sanitize_money( wp_unslash( $_POST['preferred_fee_min'] ) ) );
		}
		if ( isset( $_POST['preferred_fee_max'] ) ) {
			update_post_meta( $profile_id, '_lnkup_preferred_fee_max', LNKup_Security::sanitize_money( wp_unslash( $_POST['preferred_fee_max'] ) ) );
		}
		$image_owner = get_current_user_id();
		if ( isset( $_POST['logo_attachment_id'] ) ) {
			$logo_id      = absint( $_POST['logo_attachment_id'] );
			$current_logo = (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true );
			if ( ! $logo_id ) {
				update_post_meta( $profile_id, '_lnkup_logo_attachment_id', 0 );
			} elseif ( LNKup_Security::filter_owned_images( array( $logo_id ), $image_owner, array( $current_logo ) ) ) {
				update_post_meta( $profile_id, '_lnkup_logo_attachment_id', $logo_id );
				set_post_thumbnail( $profile_id, $logo_id );
			}
		}
		if ( isset( $_POST['cover_image_id'] ) ) {
			$cover_id      = absint( $_POST['cover_image_id'] );
			$current_cover = (int) get_post_meta( $profile_id, '_lnkup_cover_image_id', true );
			if ( ! $cover_id ) {
				update_post_meta( $profile_id, '_lnkup_cover_image_id', 0 );
			} elseif ( LNKup_Security::filter_owned_images( array( $cover_id ), $image_owner, array( $current_cover ) ) ) {
				update_post_meta( $profile_id, '_lnkup_cover_image_id', $cover_id );
			}
		}
		if ( isset( $_POST['gallery_ids'] ) && is_array( $_POST['gallery_ids'] ) ) {
			$current_gallery = function_exists( 'lnkup_parse_gallery_ids' ) ? lnkup_parse_gallery_ids( get_post_meta( $profile_id, '_lnkup_gallery_ids', true ) ) : array();
			$gallery         = LNKup_Security::filter_owned_images( wp_unslash( $_POST['gallery_ids'] ), $image_owner, $current_gallery ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			update_post_meta( $profile_id, '_lnkup_gallery_ids', wp_json_encode( $gallery ) );
			if ( class_exists( 'LNKup_Passport_Projection' ) ) {
				LNKup_Passport_Projection::sync_portfolio_from_gallery( (int) $profile_id, $gallery );
			}
		}
		if ( class_exists( 'LNKup_Passport_REST' ) ) {
			$passport_fields = array();
			foreach ( array( 'facebook', 'capabilities', 'preferred_booth_types', 'secondary_categories', 'primary_category' ) as $key ) {
				if ( isset( $_POST[ $key ] ) ) {
					$passport_fields[ $key ] = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in apply_profile_fields().
				}
			}
			if ( $passport_fields ) {
				LNKup_Passport_REST::apply_profile_fields( (int) $profile_id, $passport_fields );
			}
		}
		if ( isset( $_POST['cities_served'] ) ) {
			$cities = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['cities_served'] ) ) ) ) );
			update_post_meta( $profile_id, '_lnkup_cities_served', wp_json_encode( $cities ) );
		}
		if ( isset( $_POST['states_served'] ) ) {
			$states = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['states_served'] ) ) ) ) );
			if ( class_exists( 'LNKup_Location_Service' ) ) {
				// Only real US states ("Georgia" and "GA" both become GA; "GE" is dropped).
				$states = array_values( array_unique( array_filter( array_map( array( 'LNKup_Location_Service', 'state_code' ), $states ) ) ) );
			}
			update_post_meta( $profile_id, '_lnkup_states_served', wp_json_encode( $states ) );
		}
		if ( isset( $_POST['years_in_business'] ) ) {
			update_post_meta( $profile_id, '_lnkup_years_in_business', absint( $_POST['years_in_business'] ) );
		}
		update_post_meta( $profile_id, '_lnkup_business_name', $business );

		if ( class_exists( 'LNKup_Vendor_Reputation' ) ) {
			LNKup_Vendor_Reputation::ensure_public_slug( $profile_id );
			LNKup_Vendor_Reputation::refresh( $profile_id );
		}

		if ( ! empty( $_POST['categories'] ) && is_array( $_POST['categories'] ) ) {
			$cats = array_map( 'sanitize_text_field', wp_unslash( $_POST['categories'] ) );
			wp_set_object_terms( $profile_id, $cats, 'lnkup_vendor_category' );
		}

		if ( class_exists( 'LNKup_Match_REST' ) ) {
			$match_prefs = isset( $_POST['match_prefs'] ) && is_array( $_POST['match_prefs'] ) ? wp_unslash( $_POST['match_prefs'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated field by field in apply_preferences().
			$applied     = LNKup_Match_REST::apply_preferences( (int) $profile_id, $match_prefs );
			if ( is_wp_error( $applied ) ) {
				wp_send_json_error( array( 'message' => $applied->get_error_message() ) );
			}
		}

		do_action( 'lnkup_vendor_profile_saved', get_current_user_id(), (int) $profile_id, 'ajax' );

		wp_send_json_success(
			array(
				'message'          => __( 'Profile saved.', 'lnkup-marketplace' ),
				'profile_id'       => $profile_id,
				'profile_strength' => class_exists( 'LNKup_Growth_Profile_Strength' )
					? LNKup_Growth_Profile_Strength::calculate( (int) $profile_id )
					: array(),
			)
		);
	}

	/**
	 * Handle document upload.
	 */
	public static function handle_upload_document() {
		LNKup_Security::verify_nonce( 'lnkup_document' );
		LNKup_Capabilities::require_cap( 'lnkup_manage_own_documents' );

		$type = isset( $_POST['document_type'] ) ? sanitize_text_field( wp_unslash( $_POST['document_type'] ) ) : '';
		$exp  = isset( $_POST['expiration_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expiration_date'] ) ) : '';

		if ( empty( $_FILES['lnkup_document'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file selected.', 'lnkup-marketplace' ) ) );
		}

		$result = LNKup_Documents::upload( $_FILES['lnkup_document'], get_current_user_id(), $type, $exp );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Document uploaded.', 'lnkup-marketplace' ) ) );
	}

	/**
	 * Handle organizer application review.
	 */
	public static function handle_review_application() {
		LNKup_Security::verify_nonce( 'lnkup_review' );
		LNKup_Capabilities::require_cap( 'lnkup_review_applications' );

		$app_id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;
		$action = isset( $_POST['review_action'] ) ? sanitize_text_field( wp_unslash( $_POST['review_action'] ) ) : '';

		$result = LNKup_Applications::handle_organizer_action( $app_id, $action, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Application updated.', 'lnkup-marketplace' ) ) );
	}

	/**
	 * Upload one logo, cover, gallery, or event photo without opening the media library.
	 * These roles have no upload_files capability on purpose: the library also holds vendor documents.
	 */
	public static function handle_upload_profile_image() {
		self::require_ajax_nonce( 'lnkup_action' );
		$user_id = get_current_user_id();
		$allowed = $user_id && ( LNKup_Roles::is_vendor( $user_id ) || LNKup_Roles::is_organizer( $user_id ) || LNKup_Capabilities::user_has_manage_all( $user_id ) );
		if ( ! $allowed ) {
			self::send_json_error( array( 'message' => __( 'Sign in as a vendor or organizer to upload images.', 'lnkup-marketplace' ) ), 403 );
		}

		$throttle_key = 'lnkup_img_upload_' . $user_id;
		$count        = (int) get_transient( $throttle_key );
		if ( $count >= 40 ) {
			self::send_json_error( array( 'message' => __( 'Too many uploads. Try again in an hour.', 'lnkup-marketplace' ) ), 429 );
		}

		$file = $_FILES['image'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			self::send_json_error( array( 'message' => __( 'Choose an image to upload.', 'lnkup-marketplace' ) ), 400 );
		}
		if ( (int) $file['size'] > 5 * MB_IN_BYTES ) {
			self::send_json_error( array( 'message' => __( 'Images must be under 5 MB.', 'lnkup-marketplace' ) ), 400 );
		}
		$mimes = LNKup_Security::profile_image_mimes();
		$check = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( (string) $file['name'] ), $mimes );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], $mimes, true ) ) {
			self::send_json_error( array( 'message' => __( 'Use a JPG, PNG, WebP, or GIF image.', 'lnkup-marketplace' ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload(
			'image',
			0,
			array( 'post_author' => $user_id ),
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);
		if ( is_wp_error( $attachment_id ) ) {
			self::send_json_error( array( 'message' => $attachment_id->get_error_message() ), 400 );
		}
		update_post_meta( $attachment_id, '_lnkup_profile_image', 1 );
		set_transient( $throttle_key, $count + 1, HOUR_IN_SECONDS );

		self::send_json_success(
			array(
				'id'    => (int) $attachment_id,
				'url'   => (string) wp_get_attachment_image_url( $attachment_id, 'large' ),
				'thumb' => (string) wp_get_attachment_image_url( $attachment_id, 'medium' ),
			)
		);
	}

	/**
	 * Organizer nudges a vendor to finish required documents. One reminder per application per day.
	 */
	public static function handle_remind_vendor_documents() {
		$nonce = isset( $_POST['lnkup_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['lnkup_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lnkup_review' ) ) {
			self::send_json_error( array( 'message' => __( 'Your session expired. Refresh and try again.', 'lnkup-marketplace' ) ), 403 );
		}
		if ( ! current_user_can( 'lnkup_review_applications' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			self::send_json_error( array( 'message' => __( 'Permission denied.', 'lnkup-marketplace' ) ), 403 );
		}

		$app_id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;
		$app    = $app_id ? get_post( $app_id ) : null;
		if ( ! $app instanceof WP_Post || 'lnkup_application' !== $app->post_type ) {
			self::send_json_error( array( 'message' => __( 'Request not found.', 'lnkup-marketplace' ) ), 404 );
		}
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$owns     = $event_id
			? LNKup_Capabilities::organizer_owns_event( $event_id )
			: LNKup_Capabilities::user_owns_post( $app_id, get_current_user_id(), '_lnkup_organizer_user_id' );
		if ( ! $owns ) {
			self::send_json_error( array( 'message' => __( 'Permission denied.', 'lnkup-marketplace' ) ), 403 );
		}

		if ( LNKup_Feature_Flags::document_review() ) {
			// Ask for exactly the documents this event still needs (per-type daily throttle inside).
			$types = array();
			foreach ( LNKup_Organizer_Documents::requirements_for_application( $app_id ) as $requirement ) {
				if ( in_array( $requirement['status'], array( 'missing', 'requested', 'expired', 'rejected', 'replacement_requested' ), true ) ) {
					$types[] = $requirement['type'];
				}
			}
			if ( ! $types ) {
				self::send_json_error( array( 'message' => __( 'No reminder needed. This vendor has no missing documents.', 'lnkup-marketplace' ) ), 400 );
			}
			$requested = LNKup_Organizer_Documents::request( $app_id, $types, '', get_current_user_id() );
			if ( is_wp_error( $requested ) ) {
				$data = $requested->get_error_data();
				self::send_json_error( array( 'message' => $requested->get_error_message(), 'code' => $requested->get_error_code() ), is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 400 );
			}
			self::send_json_success( array( 'message' => __( 'Document request sent', 'lnkup-marketplace' ) ) );
		}

		$throttle_key = 'lnkup_doc_remind_' . $app_id;
		if ( get_transient( $throttle_key ) ) {
			self::send_json_error(
				array(
					'message' => __( 'Reminder already sent today.', 'lnkup-marketplace' ),
					'code'    => 'rate_limited',
				),
				429
			);
		}

		$sent = class_exists( 'LNKup_Notifications' )
			&& LNKup_Notifications::send_documents_needed( $app_id, 'manual_' . gmdate( 'Ymd' ) );
		if ( ! $sent ) {
			self::send_json_error( array( 'message' => __( 'No reminder needed. This vendor has no missing documents.', 'lnkup-marketplace' ) ), 400 );
		}
		set_transient( $throttle_key, 1, DAY_IN_SECONDS );
		self::send_json_success( array( 'message' => __( 'Reminder sent', 'lnkup-marketplace' ) ) );
	}

	/**
	 * Clear stray output buffers before JSON AJAX responses.
	 */
	private static function clear_ajax_output_buffers() {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * Send JSON success after clearing buffers.
	 *
	 * @param mixed $data Response data.
	 */
	private static function send_json_success( $data = null ) {
		self::clear_ajax_output_buffers();
		wp_send_json_success( $data );
	}

	/**
	 * Send JSON error after clearing buffers.
	 *
	 * @param mixed $data   Response data.
	 * @param int   $status HTTP status.
	 */
	private static function send_json_error( $data = null, $status = null ) {
		self::clear_ajax_output_buffers();
		if ( null === $status ) {
			wp_send_json_error( $data );
		}
		wp_send_json_error( $data, $status );
	}

	/**
	 * Verify nonce for AJAX JSON responses (no HTML wp_die).
	 *
	 * @param string $action Nonce action.
	 * @return true|void Sends JSON error and exits on failure.
	 */
	private static function require_ajax_nonce( $action ) {
		$nonce = isset( $_REQUEST['lnkup_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['lnkup_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			self::send_json_error(
				array( 'message' => __( 'Security check failed. Refresh the page and try again.', 'lnkup-marketplace' ) ),
				403
			);
		}
		return true;
	}

	/**
	 * Require capability for AJAX JSON responses (no HTML wp_die).
	 *
	 * @param string $cap Capability.
	 * @return true|void Sends JSON error and exits on failure.
	 */
	private static function require_ajax_cap( $cap ) {
		if ( ! current_user_can( $cap ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			self::send_json_error(
				array( 'message' => __( 'Permission denied.', 'lnkup-marketplace' ) ),
				403
			);
		}
		return true;
	}

	/**
	 * Handle event creation/import.
	 */
	public static function handle_create_event() {
		self::require_ajax_nonce( 'lnkup_event' );
		self::require_ajax_cap( 'lnkup_manage_own_events' );

		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		try {
			$result = LNKup_Organizer_Onboarding::create_event_from_request( $input );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup create_event: ' . $e->getMessage() );
			}
			self::send_json_error(
				array( 'message' => __( 'Could not create the event. Please try again.', 'lnkup-marketplace' ) ),
				500
			);
		}

		if ( is_wp_error( $result ) ) {
			self::send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$is_draft  = 'draft' === sanitize_key( (string) ( $input['save_mode'] ?? '' ) );
		$open_apps = ! $is_draft && ! empty( $input['open_vendor_applications'] );
		$event_id  = isset( $result['event_id'] ) ? (int) $result['event_id'] : 0;
		if ( $is_draft ) {
			$result['draft']   = true;
			$result['message'] = __( 'Draft saved. Vendors cannot see it until you add vendor spots and publish.', 'lnkup-marketplace' );
			self::send_json_success( $result );
		}

		// Decode JSON opportunities blob from multi-fee create form.
		if ( ! empty( $input['opportunities_json'] ) && is_string( $input['opportunities_json'] ) ) {
			$decoded = json_decode( $input['opportunities_json'], true );
			if ( is_array( $decoded ) ) {
				$input['opportunities'] = $decoded;
			}
		}

		if ( $open_apps && $event_id > 0 ) {
			try {
				$opp_result = LNKup_Organizer_Onboarding::create_opportunities_for_event(
					$event_id,
					$input,
					get_current_user_id()
				);
			} catch ( Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'LNKup create_event opportunity: ' . $e->getMessage() );
				}
				$opp_result = new WP_Error(
					'opp_failed',
					__( 'Event created, but vendor opportunity could not be created. You can add it next.', 'lnkup-marketplace' )
				);
			}

			if ( is_wp_error( $opp_result ) ) {
				$data                          = $opp_result->get_error_data();
				$result['onboarding']          = true;
				$result['partial']             = true;
				$result['opportunity_created'] = false;
				$result['created_opportunities'] = is_array( $data ) ? ( $data['created_opportunities'] ?? array() ) : array();
				$result['message']             = $opp_result->get_error_message();
				self::send_json_success( $result );
			}

			$result                        = array_merge( $result, $opp_result );
			$result['onboarding']          = false;
			$result['opportunity_created'] = true;
			$result['message']             = $opp_result['message'] ?? __( 'Event published with vendor applications open.', 'lnkup-marketplace' );
			self::send_json_success( $result );
		}

		$result['message'] = __( 'Event created. You can add vendor opportunities anytime.', 'lnkup-marketplace' );

		self::send_json_success( $result );
	}

	/**
	 * Update event listing fields after create.
	 */
	public static function handle_update_event() {
		self::require_ajax_nonce( 'lnkup_event' );
		self::require_ajax_cap( 'lnkup_manage_own_events' );

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$input    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		try {
			$result = LNKup_Organizer_Onboarding::update_event_from_request(
				$event_id,
				$input,
				get_current_user_id()
			);
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup update_event: ' . $e->getMessage() );
			}
			self::send_json_error(
				array( 'message' => __( 'Could not update the event. Please try again.', 'lnkup-marketplace' ) ),
				500
			);
		}

		if ( is_wp_error( $result ) ) {
			$status = 'forbidden' === $result->get_error_code() ? 403 : 400;
			self::send_json_error( array( 'message' => $result->get_error_message() ), $status );
		}

		self::send_json_success( $result );
	}

	/**
	 * Save the organizer's public name and about text.
	 */
	public static function handle_update_organizer_profile() {
		self::require_ajax_nonce( 'lnkup_event' );
		self::require_ajax_cap( 'lnkup_manage_own_events' );

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			self::send_json_error( array( 'message' => __( 'You must be signed in.', 'lnkup-marketplace' ) ), 403 );
		}

		$name = isset( $_POST['public_name'] ) ? sanitize_text_field( wp_unslash( $_POST['public_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$bio  = isset( $_POST['bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bio'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( strlen( $name ) > 120 ) {
			self::send_json_error( array( 'message' => __( 'Organization name must be 120 characters or fewer.', 'lnkup-marketplace' ) ) );
		}

		$result = LNKup_Organizer_Onboarding::update_public_profile(
			$user_id,
			array(
				'public_name'    => $name,
				'bio'            => $bio,
				'tagline'        => isset( $_POST['tagline'] ) ? wp_unslash( $_POST['tagline'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'city'           => isset( $_POST['city'] ) ? wp_unslash( $_POST['city'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'state'          => isset( $_POST['state'] ) ? wp_unslash( $_POST['state'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'service_cities' => isset( $_POST['service_cities'] ) ? wp_unslash( $_POST['service_cities'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'website'        => isset( $_POST['website'] ) ? wp_unslash( $_POST['website'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'instagram'      => isset( $_POST['instagram'] ) ? wp_unslash( $_POST['instagram'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'tiktok'         => isset( $_POST['tiktok'] ) ? wp_unslash( $_POST['tiktok'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'logo_id'        => isset( $_POST['logo_id'] ) ? absint( $_POST['logo_id'] ) : 0,
				'cover_id'       => isset( $_POST['cover_id'] ) ? absint( $_POST['cover_id'] ) : 0,
				'public_slug'    => isset( $_POST['public_slug'] ) ? wp_unslash( $_POST['public_slug'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);
		if ( is_wp_error( $result ) ) {
			self::send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		self::send_json_success( $result );
	}

	/**
	 * Duplicate an event and its vendor slots onto a new date.
	 */
	public static function handle_duplicate_event() {
		self::require_ajax_nonce( 'lnkup_event' );
		self::require_ajax_cap( 'lnkup_manage_own_events' );

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$input    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		try {
			$result = LNKup_Organizer_Onboarding::duplicate_event(
				$event_id,
				$input,
				get_current_user_id()
			);
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup duplicate_event: ' . $e->getMessage() );
			}
			self::send_json_error(
				array( 'message' => __( 'Could not duplicate the event. Please try again.', 'lnkup-marketplace' ) ),
				500
			);
		}

		if ( is_wp_error( $result ) ) {
			$status = 'forbidden' === $result->get_error_code() ? 403 : 400;
			self::send_json_error( array( 'message' => $result->get_error_message() ), $status );
		}

		self::send_json_success( $result );
	}

	/**
	 * Update opportunity fee and spots.
	 */
	public static function handle_update_opportunity_pricing() {
		self::require_ajax_nonce( 'lnkup_opportunity' );
		self::require_ajax_cap( 'lnkup_manage_own_opportunities' );

		$opp_id = isset( $_POST['opportunity_id'] ) ? absint( $_POST['opportunity_id'] ) : 0;
		$fee    = isset( $_POST['vendor_fee'] ) ? wp_unslash( $_POST['vendor_fee'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$spots  = isset( $_POST['spots_total'] ) ? absint( $_POST['spots_total'] ) : 0;
		$title  = isset( $_POST['slot_title'] ) ? sanitize_text_field( wp_unslash( $_POST['slot_title'] ) ) : '';

		try {
			$result = LNKup_Organizer_Onboarding::update_opportunity_pricing(
				$opp_id,
				$fee,
				$spots,
				get_current_user_id(),
				$title
			);
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup update_opportunity_pricing: ' . $e->getMessage() );
			}
			self::send_json_error(
				array( 'message' => __( 'Could not update fee and spots. Please try again.', 'lnkup-marketplace' ) ),
				500
			);
		}

		if ( is_wp_error( $result ) ) {
			$status = 'forbidden' === $result->get_error_code() ? 403 : 400;
			self::send_json_error( array( 'message' => $result->get_error_message() ), $status );
		}

		self::send_json_success( $result );
	}

	/**
	 * Remove a vendor slot that has no applications.
	 */
	public static function handle_delete_opportunity() {
		self::require_ajax_nonce( 'lnkup_opportunity' );
		self::require_ajax_cap( 'lnkup_manage_own_opportunities' );

		$opp_id = isset( $_POST['opportunity_id'] ) ? absint( $_POST['opportunity_id'] ) : 0;

		try {
			$result = LNKup_Organizer_Onboarding::delete_opportunity_for_event(
				$opp_id,
				get_current_user_id()
			);
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup delete_opportunity: ' . $e->getMessage() );
			}
			self::send_json_error(
				array( 'message' => __( 'Could not remove the vendor slot. Please try again.', 'lnkup-marketplace' ) ),
				500
			);
		}

		if ( is_wp_error( $result ) ) {
			$status = 'forbidden' === $result->get_error_code() ? 403 : 400;
			self::send_json_error( array( 'message' => $result->get_error_message() ), $status );
		}

		self::send_json_success( $result );
	}

	/**
	 * Open or close a vendor slot.
	 */
	public static function handle_set_opportunity_status() {
		self::require_ajax_nonce( 'lnkup_opportunity' );
		self::require_ajax_cap( 'lnkup_manage_own_opportunities' );

		$opp_id = isset( $_POST['opportunity_id'] ) ? absint( $_POST['opportunity_id'] ) : 0;
		$status = isset( $_POST['slot_status'] ) ? sanitize_key( wp_unslash( $_POST['slot_status'] ) ) : '';

		try {
			$result = LNKup_Organizer_Onboarding::set_opportunity_listing_status(
				$opp_id,
				$status,
				get_current_user_id()
			);
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup set_opportunity_status: ' . $e->getMessage() );
			}
			self::send_json_error(
				array( 'message' => __( 'Could not update the vendor slot. Please try again.', 'lnkup-marketplace' ) ),
				500
			);
		}

		if ( is_wp_error( $result ) ) {
			$status_code = 'forbidden' === $result->get_error_code() ? 403 : 400;
			self::send_json_error( array( 'message' => $result->get_error_message() ), $status_code );
		}

		self::send_json_success( $result );
	}

	/**
	 * Handle opportunity creation.
	 */
	public static function handle_create_opportunity() {
		self::require_ajax_nonce( 'lnkup_opportunity' );
		self::require_ajax_cap( 'lnkup_manage_own_opportunities' );

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$input    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $input['opportunities_json'] ) && is_string( $input['opportunities_json'] ) ) {
			$decoded = json_decode( $input['opportunities_json'], true );
			if ( is_array( $decoded ) ) {
				$input['opportunities'] = $decoded;
			}
		}
		$multi = ( ! empty( $input['opportunities'] ) && is_array( $input['opportunities'] ) )
			|| ( ! empty( $input['fee_category'] ) && is_array( $input['fee_category'] ) )
			|| ( ! empty( $input['fee_custom'] ) && is_array( $input['fee_custom'] ) );
		try {
			if ( $multi ) {
				$result = LNKup_Organizer_Onboarding::create_opportunities_for_event(
					$event_id,
					$input,
					get_current_user_id()
				);
			} else {
				$result = LNKup_Organizer_Onboarding::create_opportunity_for_event(
					$event_id,
					$input,
					get_current_user_id()
				);
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup create_opportunity: ' . $e->getMessage() );
			}
			wp_send_json_error(
				array( 'message' => __( 'Could not create the opportunity. Please try again.', 'lnkup-marketplace' ) ),
				500
			);
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$from_onboarding = ! empty( $_POST['from_onboarding'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $from_onboarding ) {
			$result['message'] = __( 'Event created and vendor opportunity published.', 'lnkup-marketplace' );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Export vendor list CSV.
	 */
	public static function handle_export_vendors() {
		LNKup_Security::verify_nonce( 'lnkup_export' );
		LNKup_Capabilities::require_cap( 'lnkup_manage_own_events' );

		$event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
		if ( ! LNKup_Capabilities::user_owns_post( $event_id, get_current_user_id(), '_lnkup_organizer_user_id' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lnkup-marketplace' ), 403 );
		}

		$rows = LNKup_Vendor_Directory::contact_rows_for_event( $event_id );
		LNKup_Vendor_Directory::stream_contact_csv( $rows, 'vendors-event-' . $event_id . '.csv', true );
	}

	/**
	 * Event intelligence summary for organizer dashboard (AJAX fallback).
	 */
	public static function handle_event_intelligence() {
		self::require_ajax_nonce( 'lnkup_action' );
		if (
			! current_user_can( 'lnkup_review_applications' )
			&& ! ( class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer() )
			&& ! current_user_can( 'manage_options' )
			&& ! LNKup_Capabilities::user_has_manage_all()
		) {
			self::send_json_error( array( 'message' => __( 'Permission denied.', 'lnkup-marketplace' ) ), 403 );
		}
		try {
			$summary = lnkup_get_event_intelligence_summary( get_current_user_id() );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup event_intelligence: ' . $e->getMessage() );
			}
			self::send_json_error( array( 'message' => __( 'Insights are unavailable right now. Try again shortly.', 'lnkup-marketplace' ) ), 500 );
		}
		if ( is_wp_error( $summary ) ) {
			self::send_json_error( array( 'message' => $summary->get_error_message() ), 500 );
		}
		self::send_json_success( is_array( $summary ) ? $summary : array() );
	}

	/**
	 * Filter opportunities for Find Events (AJAX fallback).
	 */
	public static function handle_filter_opportunities() {
		LNKup_Security::verify_nonce( 'lnkup_action' );
		$args   = lnkup_parse_marketplace_filters( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = lnkup_get_filtered_opportunities( $args );
		wp_send_json_success(
			array(
				'html'           => $result['html'],
				'count'          => (int) $result['total'],
				'pagination'     => $result['pagination'],
				'active_filters' => $result['active_filters'],
				'empty_state'    => $result['empty_state'],
				'page'           => (int) $result['page'],
				'pages'          => (int) $result['pages'],
				'has_external'   => ! empty( $result['has_external'] ),
			)
		);
	}

	/**
	 * Dismiss vendor getting-started tutorial.
	 */
	public static function handle_dismiss_vendor_tutorial() {
		LNKup_Security::verify_nonce( 'lnkup_action' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Sign in required.', 'lnkup-marketplace' ) ), 401 );
		}
		if ( ! class_exists( 'LNKup_Vendor_Getting_Started' ) ) {
			wp_send_json_error( array( 'message' => __( 'Tutorial unavailable.', 'lnkup-marketplace' ) ), 503 );
		}
		LNKup_Vendor_Getting_Started::dismiss( get_current_user_id() );
		wp_send_json_success( array( 'dismissed' => true ) );
	}

	/**
	 * Snooze "Get the iPhone app" prompts.
	 */
	public static function handle_dismiss_app_promo() {
		LNKup_Security::verify_nonce( 'lnkup_action' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Sign in required.', 'lnkup-marketplace' ) ), 401 );
		}
		if ( ! class_exists( 'LNKup_App_Promo' ) ) {
			wp_send_json_error( array( 'message' => __( 'App suggestions unavailable.', 'lnkup-marketplace' ) ), 503 );
		}
		LNKup_App_Promo::dismiss( get_current_user_id() );
		wp_send_json_success( array( 'dismissed' => true ) );
	}

	/**
	 * Turn app suggestions on or off for the current user.
	 */
	public static function handle_app_promo_opt_out() {
		LNKup_Security::verify_nonce( 'lnkup_action' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Sign in required.', 'lnkup-marketplace' ) ), 401 );
		}
		if ( ! class_exists( 'LNKup_App_Promo' ) ) {
			wp_send_json_error( array( 'message' => __( 'App suggestions unavailable.', 'lnkup-marketplace' ) ), 503 );
		}
		$hide = ! empty( $_POST['hide'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		LNKup_App_Promo::set_opt_out( get_current_user_id(), $hide );
		wp_send_json_success( array( 'hidden' => $hide ) );
	}

	/**
	 * Mark vendor welcome popup as seen.
	 */
	public static function handle_vendor_welcome_seen() {
		LNKup_Security::verify_nonce( 'lnkup_action' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Sign in required.', 'lnkup-marketplace' ) ), 401 );
		}
		if ( ! class_exists( 'LNKup_Vendor_Getting_Started' ) ) {
			wp_send_json_error( array( 'message' => __( 'Tutorial unavailable.', 'lnkup-marketplace' ) ), 503 );
		}
		LNKup_Vendor_Getting_Started::mark_welcome_seen( get_current_user_id() );
		wp_send_json_success( array( 'welcome_seen' => true ) );
	}

	/**
	 * Re-show vendor getting-started checklist (does not reopen welcome).
	 */
	public static function handle_reset_vendor_tutorial() {
		LNKup_Security::verify_nonce( 'lnkup_action' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Sign in required.', 'lnkup-marketplace' ) ), 401 );
		}
		if ( ! class_exists( 'LNKup_Vendor_Getting_Started' ) ) {
			wp_send_json_error( array( 'message' => __( 'Tutorial unavailable.', 'lnkup-marketplace' ) ), 503 );
		}
		LNKup_Vendor_Getting_Started::reset( get_current_user_id() );
		wp_send_json_success( array( 'reset' => true ) );
	}

	/**
	 * Dismiss organizer launch checklist.
	 */
	public static function handle_dismiss_organizer_tutorial() {
		LNKup_Security::verify_nonce( 'lnkup_action' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Sign in required.', 'lnkup-marketplace' ) ), 401 );
		}
		if ( ! class_exists( 'LNKup_Organizer_Launch_Checklist' ) ) {
			wp_send_json_error( array( 'message' => __( 'Checklist unavailable.', 'lnkup-marketplace' ) ), 503 );
		}
		LNKup_Organizer_Launch_Checklist::dismiss( get_current_user_id() );
		wp_send_json_success( array( 'dismissed' => true ) );
	}

	/**
	 * Re-show organizer launch checklist (does not reopen welcome).
	 */
	public static function handle_reset_organizer_tutorial() {
		LNKup_Security::verify_nonce( 'lnkup_action' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Sign in required.', 'lnkup-marketplace' ) ), 401 );
		}
		if ( ! class_exists( 'LNKup_Organizer_Launch_Checklist' ) ) {
			wp_send_json_error( array( 'message' => __( 'Checklist unavailable.', 'lnkup-marketplace' ) ), 503 );
		}
		LNKup_Organizer_Launch_Checklist::reset( get_current_user_id() );
		wp_send_json_success( array( 'reset' => true ) );
	}

	/**
	 * Mark platform tour as seen.
	 */
	public static function handle_dashboard_tour_seen() {
		LNKup_Security::verify_nonce( 'lnkup_action' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Sign in required.', 'lnkup-marketplace' ) ), 401 );
		}
		if ( ! class_exists( 'LNKup_Dashboard_Tour' ) ) {
			wp_send_json_error( array( 'message' => __( 'Tour unavailable.', 'lnkup-marketplace' ) ), 503 );
		}
		$role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( (string) $_POST['role'] ) ) : 'vendor'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $role, array( 'vendor', 'organizer' ), true ) ) {
			$role = 'vendor';
		}
		LNKup_Dashboard_Tour::mark_seen( get_current_user_id(), $role );
		wp_send_json_success( array( 'tour_seen' => true ) );
	}

	/**
	 * Replay platform tour from Help (does not reopen welcome).
	 */
	public static function handle_reset_dashboard_tour() {
		LNKup_Security::verify_nonce( 'lnkup_action' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Sign in required.', 'lnkup-marketplace' ) ), 401 );
		}
		if ( ! class_exists( 'LNKup_Dashboard_Tour' ) ) {
			wp_send_json_error( array( 'message' => __( 'Tour unavailable.', 'lnkup-marketplace' ) ), 503 );
		}
		$role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( (string) $_POST['role'] ) ) : 'vendor'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $role, array( 'vendor', 'organizer' ), true ) ) {
			$role = 'vendor';
		}
		LNKup_Dashboard_Tour::reset( get_current_user_id(), $role );
		wp_send_json_success( array( 'reset' => true ) );
	}
}
