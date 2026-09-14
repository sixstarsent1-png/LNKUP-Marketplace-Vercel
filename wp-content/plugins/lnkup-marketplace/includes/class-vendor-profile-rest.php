<?php
/**
 * Vendor profile REST (partial read/write for mobile).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Profile_REST {

	const NS = 'lnkup/v1';

	/**
	 * Init.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/vendor/profile',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_profile' ),
					'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_profile' ),
					'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				),
			)
		);
	}

	/**
	 * GET current vendor profile (editable fields).
	 */
	public static function get_profile() {
		$user_id    = get_current_user_id();
		$profile_id = function_exists( 'lnkup_get_vendor_profile_id' ) ? lnkup_get_vendor_profile_id( $user_id ) : 0;
		if ( ! $profile_id ) {
			return rest_ensure_response(
				array(
					'profile_id'    => 0,
					'business_name' => '',
					'description'   => '',
					'website'       => '',
					'instagram'     => '',
					'contact_email' => '',
					'contact_phone' => '',
					'city'          => '',
					'state'         => '',
				)
			);
		}

		$post = get_post( $profile_id );
		$slug = '';
		if ( class_exists( 'LNKup_Vendor_Reputation' ) ) {
			$slug = (string) LNKup_Vendor_Reputation::ensure_public_slug( $profile_id );
		} else {
			$slug = (string) get_post_meta( $profile_id, '_lnkup_public_slug', true );
		}
		return rest_ensure_response(
			array(
				'profile_id'    => (int) $profile_id,
				'business_name' => (string) get_post_meta( $profile_id, '_lnkup_business_name', true ) ?: ( $post ? $post->post_title : '' ),
				'description'   => $post ? (string) $post->post_content : '',
				'website'       => (string) get_post_meta( $profile_id, '_lnkup_website', true ),
				'instagram'     => (string) get_post_meta( $profile_id, '_lnkup_instagram', true ),
				'contact_email' => (string) get_post_meta( $profile_id, '_lnkup_contact_email', true ),
				'contact_phone' => (string) get_post_meta( $profile_id, '_lnkup_contact_phone', true ),
				'city'          => (string) get_post_meta( $profile_id, '_lnkup_city', true ),
				'state'         => (string) get_post_meta( $profile_id, '_lnkup_state', true ),
				'public_slug'   => $slug,
				'public_url'    => $slug
					? add_query_arg( 'vendor', $slug, lnkup_page_url_helper( 'vendors' ) )
					: '',
			)
		);
	}

	/**
	 * POST partial profile update (subset of AJAX lnkup_save_profile).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function update_profile( WP_REST_Request $request ) {
		if ( ! current_user_can( 'lnkup_manage_own_profile' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		$user_id    = get_current_user_id();
		$profile_id = function_exists( 'lnkup_get_vendor_profile_id' ) ? lnkup_get_vendor_profile_id( $user_id ) : 0;
		$body       = $request->get_json_params() ?: $request->get_params();

		$business = isset( $body['business_name'] ) ? sanitize_text_field( (string) $body['business_name'] ) : '';
		if ( ! $business && $profile_id ) {
			$business = (string) get_post_meta( $profile_id, '_lnkup_business_name', true );
			if ( ! $business ) {
				$existing = get_post( $profile_id );
				$business = $existing ? $existing->post_title : '';
			}
		}
		if ( ! $business ) {
			return new WP_Error( 'validation_error', __( 'Business name is required.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$data = array(
			'post_title'  => $business,
			'post_type'   => 'lnkup_vendor_profile',
			'post_status' => 'publish',
		);
		if ( array_key_exists( 'description', $body ) ) {
			$data['post_content'] = sanitize_textarea_field( (string) $body['description'] );
		} elseif ( $profile_id ) {
			$existing             = get_post( $profile_id );
			$data['post_content'] = $existing ? $existing->post_content : '';
		} else {
			$data['post_content'] = '';
		}

		if ( $profile_id ) {
			$data['ID'] = $profile_id;
			$profile_id = wp_update_post( $data, true );
		} else {
			$profile_id = wp_insert_post( $data, true );
			if ( ! is_wp_error( $profile_id ) ) {
				update_post_meta( $profile_id, '_lnkup_owner_user_id', $user_id );
				update_post_meta( $profile_id, '_lnkup_verification_status', 'pending' );
			}
		}

		if ( is_wp_error( $profile_id ) ) {
			return new WP_Error( $profile_id->get_error_code(), $profile_id->get_error_message(), array( 'status' => 400 ) );
		}

		$fields = array( 'website', 'instagram', 'city', 'state', 'contact_phone' );
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $body ) ) {
				update_post_meta( $profile_id, '_lnkup_' . $field, sanitize_text_field( (string) $body[ $field ] ) );
			}
		}
		if ( array_key_exists( 'contact_email', $body ) ) {
			update_post_meta( $profile_id, '_lnkup_contact_email', sanitize_email( (string) $body['contact_email'] ) );
		}
		update_post_meta( $profile_id, '_lnkup_business_name', $business );

		if ( class_exists( 'LNKup_Vendor_Reputation' ) ) {
			LNKup_Vendor_Reputation::ensure_public_slug( $profile_id );
			LNKup_Vendor_Reputation::refresh( $profile_id );
		}

		return rest_ensure_response(
			array(
				'success'    => true,
				'profile_id' => (int) $profile_id,
				'message'    => __( 'Profile saved.', 'lnkup-marketplace' ),
			)
		);
	}

	/**
	 * Vendor permission.
	 */
	public static function vendor_permission() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return LNKup_Roles::is_vendor() || LNKup_Capabilities::user_has_manage_all();
	}
}
