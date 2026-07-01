<?php
/**
 * Post meta registration and admin meta boxes.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Meta {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_boxes' ), 10, 2 );
	}

	/**
	 * Auth callback for registered post meta.
	 */
	public static function auth_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Register post meta.
	 */
	public static function register_meta() {
		$meta_fields = self::get_meta_schema();
		foreach ( $meta_fields as $post_type => $fields ) {
			foreach ( $fields as $key => $config ) {
				try {
					$registered = register_post_meta(
						$post_type,
						$key,
						array_merge(
							array(
								'single'        => true,
								'show_in_rest'  => true,
								'auth_callback' => array( __CLASS__, 'auth_edit_posts' ),
							),
							$config
						)
					);
					if ( false === $registered ) {
						error_log( sprintf( 'LNKup: failed to register meta %s for %s', $key, $post_type ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				} catch ( Throwable $e ) {
					error_log( sprintf( 'LNKup meta registration error (%s/%s): %s', $post_type, $key, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}
	}

	/**
	 * Meta schema per post type.
	 */
	public static function get_meta_schema() {
		return array(
			'lnkup_vendor_profile' => array(
				'_lnkup_business_name'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_owner_user_id'       => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_website'             => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
				'_lnkup_instagram'           => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_tiktok'              => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_contact_email'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ),
				'_lnkup_contact_phone'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_city'                => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_state'               => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_setup_type'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_space_needed'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_power_needed'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_water_needed'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_years_in_business'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_verification_status' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_verification_admin_notes' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_owner_name'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_cities_served'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_states_served'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_products_services'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_gallery_ids'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_logo_attachment_id'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_cover_image_id'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_food_truck_size'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_has_generator'       => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				'_lnkup_indoor_outdoor_preference' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_willing_to_travel'   => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				'_lnkup_service_radius_miles'=> array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_availability_notes'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_preferred_fee_min'   => array( 'type' => 'number', 'sanitize_callback' => array( 'LNKup_Security', 'sanitize_money' ) ),
				'_lnkup_preferred_fee_max'   => array( 'type' => 'number', 'sanitize_callback' => array( 'LNKup_Security', 'sanitize_money' ) ),
				'_lnkup_tent_size'           => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_trailer_size'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_public_slug'           => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_title' ),
				'_lnkup_reputation_score'     => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_reputation_label'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_avg_rating'           => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_review_count'         => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_reputation_breakdown' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_reputation_updated_at'=> array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_reputation_score_override' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			),
			'lnkup_event' => array(
				'_lnkup_organizer_user_id'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_event_date'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_start_time'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_end_time'            => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_city'                => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_state'               => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_venue_name'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_venue_address'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_venue_hidden'        => array(
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
				'_lnkup_expected_attendance' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_ticketing_platform'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_ticketing_url'       => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
				'_lnkup_status'              => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_lat'                 => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_lng'                 => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_indoor'              => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				'_lnkup_outdoor'             => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				'_lnkup_power_available'     => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				'_lnkup_water_available'     => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				'_lnkup_food_truck_allowed'  => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				'_lnkup_tent_allowed'        => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				'_lnkup_generator_allowed'   => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				'_lnkup_free_vendor_parking' => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				'_lnkup_featured_image_id'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_tags'                => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_ops_stage'           => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_ops_stage_updated_at'=> array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_venue_map_attachment_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_address_line1'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_address_line2'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_zip'                 => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_country'             => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_neighborhood'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_market'              => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_region'              => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_indoor_outdoor'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_venue_type'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_parking_notes'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_loadin_entrance'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_vendor_gate'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_geocode_status'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_geocode_manual_override' => array(
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
				'_lnkup_emergency_contacts'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_weather_placeholder' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_start_time'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			),
			'lnkup_opportunity' => array(
				'_lnkup_event_id'            => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_spots_total'         => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_spots_remaining'     => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_vendor_fee'          => array( 'type' => 'string', 'sanitize_callback' => array( 'LNKup_Security', 'sanitize_money' ) ),
				'_lnkup_lnkup_fee'           => array( 'type' => 'string', 'sanitize_callback' => array( 'LNKup_Security', 'sanitize_money' ) ),
				'_lnkup_application_deadline' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_required_documents'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_document_mode'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_organizer_notes'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_opportunity_status'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_instant_approval'    => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				'_lnkup_view_count'          => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_application_count'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_load_in_time'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_invite_only'         => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
			),
			'lnkup_application' => array(
				'_lnkup_vendor_profile_id'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_vendor_user_id'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_opportunity_id'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_event_id'            => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_organizer_user_id'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_application_status'  => array( 'type' => 'string', 'sanitize_callback' => array( 'LNKup_Security', 'sanitize_application_status' ) ),
				'_lnkup_submitted_at'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_approved_at'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_payment_due_at'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_paid_at'             => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_rejection_reason'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_internal_notes'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_checked_in_at'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_no_show_at'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_booth_placeholder'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_readiness_status'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_organizer_reviewed_at' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_event_completed_at'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_review_id'           => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_late_arrival_at'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_cancellation_at'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_ops_status'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_readiness_percent'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_selected_category'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_setup_notes'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_profile_snapshot'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_documents_snapshot'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_documents_missing'   => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
			),
			'lnkup_vendor_note' => array(
				'_lnkup_organizer_user_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_vendor_user_id'    => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_event_id'          => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_is_pinned'         => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
			),
			'lnkup_vendor_list' => array(
				'_lnkup_organizer_user_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_list_color'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_hex_color' ),
			),
			'lnkup_vendor_invite' => array(
				'_lnkup_organizer_user_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_vendor_user_id'    => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_opportunity_id'    => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_event_id'          => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_status'            => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_token'             => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_expires_at'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_type'              => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_invite_message'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
			'lnkup_vendor_flag' => array(
				'_lnkup_organizer_user_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_vendor_user_id'    => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_flag_type'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_event_id'          => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_application_id'    => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_admin_visible'     => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				'_lnkup_flag_note'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
			'lnkup_review' => array(
				'_lnkup_organizer_user_id'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_vendor_user_id'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_vendor_profile_id'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_application_id'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_event_id'            => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_overall_rating'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_category_ratings'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_public_text'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_private_note'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_internal_note'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_status'              => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_late_arrival'        => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				'_lnkup_issue_flagged'       => array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
			),
			'lnkup_dispute' => array(
				'_lnkup_review_id'           => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_vendor_user_id'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_reason'              => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_status'              => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_admin_resolution'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
			'lnkup_document' => array(
				'_lnkup_vendor_user_id'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_vendor_profile_id'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_document_type'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_attachment_id'       => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_expiration_date'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_verification_status' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_admin_notes'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
			'lnkup_payment' => array(
				'_lnkup_application_id'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_vendor_user_id'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_organizer_user_id'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_event_id'            => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_opportunity_id'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_vendor_spot_fee'     => array( 'type' => 'string', 'sanitize_callback' => array( 'LNKup_Security', 'sanitize_money' ) ),
				'_lnkup_lnkup_fee'           => array( 'type' => 'string', 'sanitize_callback' => array( 'LNKup_Security', 'sanitize_money' ) ),
				'_lnkup_processing_fee'      => array( 'type' => 'string', 'sanitize_callback' => array( 'LNKup_Security', 'sanitize_money' ) ),
				'_lnkup_total_amount'        => array( 'type' => 'string', 'sanitize_callback' => array( 'LNKup_Security', 'sanitize_money' ) ),
				'_lnkup_payment_status'      => array( 'type' => 'string', 'sanitize_callback' => array( 'LNKup_Security', 'sanitize_payment_status' ) ),
				'_lnkup_stripe_payment_intent' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_stripe_transfer_id'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_paid_at'             => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			),
			'lnkup_packet' => array(
				'_lnkup_event_id'            => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_opportunity_id'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_application_id'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_vendor_user_id'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_load_in_time'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_load_out_time'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_booth_assignment'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_vendor_rules'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_parking_info'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_venue_address'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_map_attachment_id'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'_lnkup_qr_pass'             => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_packet_locked'       => array(
					'type'              => 'boolean',
					'default'           => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
				'_lnkup_day_of_contact'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'_lnkup_prohibited_items'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_setup_restrictions'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_refund_policy'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_rain_plan'           => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'_lnkup_heat_plan'           => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
		);
	}

	/**
	 * Add admin meta boxes.
	 */
	public static function add_meta_boxes() {
		$boxes = array(
			'lnkup_event'       => __( 'Event Details', 'lnkup-marketplace' ),
			'lnkup_opportunity' => __( 'Opportunity Details', 'lnkup-marketplace' ),
			'lnkup_application' => __( 'Application Details', 'lnkup-marketplace' ),
		);
		foreach ( $boxes as $post_type => $title ) {
			add_meta_box(
				'lnkup_' . $post_type . '_meta',
				$title,
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render generic meta box.
	 *
	 * @param WP_Post $post Post object.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'lnkup_save_meta', 'lnkup_meta_nonce' );
		$schema = self::get_meta_schema();
		if ( empty( $schema[ $post->post_type ] ) ) {
			return;
		}
		echo '<table class="form-table"><tbody>';
		foreach ( $schema[ $post->post_type ] as $key => $config ) {
			$value = get_post_meta( $post->ID, $key, true );
			$label = str_replace( array( '_lnkup_', '_' ), array( '', ' ' ), $key );
			echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( ucwords( $label ) ) . '</label></th>';
			echo '<td><input type="text" class="widefat" name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" /></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Save meta boxes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_meta_boxes( $post_id, $post ) {
		if ( ! isset( $_POST['lnkup_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lnkup_meta_nonce'] ) ), 'lnkup_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$schema = self::get_meta_schema();
		if ( empty( $schema[ $post->post_type ] ) ) {
			return;
		}
		foreach ( $schema[ $post->post_type ] as $key => $config ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$raw = wp_unslash( $_POST[ $key ] );
			$sanitize = $config['sanitize_callback'] ?? 'sanitize_text_field';
			$value = is_callable( $sanitize ) ? call_user_func( $sanitize, $raw ) : sanitize_text_field( $raw );
			update_post_meta( $post_id, $key, $value );
		}
	}
}
