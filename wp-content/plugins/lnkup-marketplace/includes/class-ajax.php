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
		add_action( 'wp_ajax_lnkup_create_event', array( __CLASS__, 'handle_create_event' ) );
		add_action( 'wp_ajax_lnkup_create_opportunity', array( __CLASS__, 'handle_create_opportunity' ) );
		add_action( 'wp_ajax_lnkup_download_document', array( 'LNKup_Documents', 'handle_download' ) );
		add_action( 'wp_ajax_lnkup_export_vendors', array( __CLASS__, 'handle_export_vendors' ) );
		add_action( 'wp_ajax_lnkup_event_intelligence', array( __CLASS__, 'handle_event_intelligence' ) );
		add_action( 'wp_ajax_lnkup_filter_opportunities', array( __CLASS__, 'handle_filter_opportunities' ) );
		add_action( 'wp_ajax_nopriv_lnkup_filter_opportunities', array( __CLASS__, 'handle_filter_opportunities' ) );
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

		$key = 'lnkup_apply_' . get_current_user_id();
		if ( get_transient( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Please wait before submitting again.', 'lnkup-marketplace' ) ) );
		}
		set_transient( $key, 1, 30 );

		$result = LNKup_Applications::submit_with_profile(
			get_current_user_id(),
			$opp_id,
			array(
				'application_message' => $message,
				'selected_category'   => $category,
				'setup_notes'         => $setup_notes,
				'accepted_terms'      => $accepted,
			)
		);
		if ( is_wp_error( $result ) ) {
			$payload = array( 'message' => $result->get_error_message() );
			$data    = $result->get_error_data();
			if ( is_array( $data ) && ! empty( $data['existing_application_id'] ) ) {
				$payload['existing_application_id'] = (int) $data['existing_application_id'];
				$payload['status']                  = $data['status'] ?? '';
			}
			wp_send_json_error( $payload );
		}

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
			'post_content' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
		);

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
		if ( isset( $_POST['logo_attachment_id'] ) ) {
			$logo_id = absint( $_POST['logo_attachment_id'] );
			update_post_meta( $profile_id, '_lnkup_logo_attachment_id', $logo_id );
			if ( $logo_id ) {
				set_post_thumbnail( $profile_id, $logo_id );
			}
		}
		if ( isset( $_POST['cover_image_id'] ) ) {
			update_post_meta( $profile_id, '_lnkup_cover_image_id', absint( $_POST['cover_image_id'] ) );
		}
		if ( isset( $_POST['gallery_ids'] ) && is_array( $_POST['gallery_ids'] ) ) {
			$gallery = array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['gallery_ids'] ) ) ) );
			update_post_meta( $profile_id, '_lnkup_gallery_ids', wp_json_encode( $gallery ) );
		}
		if ( isset( $_POST['cities_served'] ) ) {
			$cities = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['cities_served'] ) ) ) ) );
			update_post_meta( $profile_id, '_lnkup_cities_served', wp_json_encode( $cities ) );
		}
		if ( isset( $_POST['states_served'] ) ) {
			$states = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['states_served'] ) ) ) ) );
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
	 * Handle event creation/import.
	 */
	public static function handle_create_event() {
		LNKup_Security::verify_nonce( 'lnkup_event' );
		LNKup_Capabilities::require_cap( 'lnkup_manage_own_events' );

		$result = LNKup_Organizer_Onboarding::create_event_from_request( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$result['message'] = __( 'Event created. You can add vendor opportunities anytime.', 'lnkup-marketplace' );

		wp_send_json_success( $result );
	}

	/**
	 * Handle opportunity creation.
	 */
	public static function handle_create_opportunity() {
		LNKup_Security::verify_nonce( 'lnkup_opportunity' );
		LNKup_Capabilities::require_cap( 'lnkup_manage_own_opportunities' );

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$result   = LNKup_Organizer_Onboarding::create_opportunity_for_event(
			$event_id,
			wp_unslash( $_POST ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			get_current_user_id()
		);

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

		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array( 'key' => '_lnkup_event_id', 'value' => $event_id ),
					array( 'key' => '_lnkup_application_status', 'value' => array( 'paid', 'approved', 'payment_required' ), 'compare' => 'IN' ),
				),
			)
		);

		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="vendors-event-' . $event_id . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Vendor', 'Status', 'Submitted', 'Paid' ) );
		foreach ( $apps as $app ) {
			$vendor_id = (int) get_post_meta( $app->ID, '_lnkup_vendor_user_id', true );
			$user      = get_userdata( $vendor_id );
			fputcsv(
				$out,
				array(
					$user ? $user->display_name : '',
					get_post_meta( $app->ID, '_lnkup_application_status', true ),
					get_post_meta( $app->ID, '_lnkup_submitted_at', true ),
					get_post_meta( $app->ID, '_lnkup_paid_at', true ),
				)
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * Event intelligence summary for organizer dashboard (AJAX fallback).
	 */
	public static function handle_event_intelligence() {
		LNKup_Security::verify_nonce( 'lnkup_action' );
		if (
			! current_user_can( 'lnkup_review_applications' )
			&& ! ( class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer() )
			&& ! current_user_can( 'manage_options' )
			&& ! LNKup_Capabilities::user_has_manage_all()
		) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lnkup-marketplace' ) ) );
		}
		wp_send_json_success( lnkup_get_event_intelligence_summary( get_current_user_id() ) );
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
}
