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

		register_rest_route(
			self::NS,
			'/vendor/profile/logo',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload_logo' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
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
					'categories'    => array(),
					'logo_url'      => '',
					'logo_attachment_id' => 0,
				)
			);
		}

		$post = get_post( $profile_id );
		$categories = wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}
		$logo_id = (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true );
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
				'categories'    => array_values( $categories ),
				'logo_attachment_id' => $logo_id,
				'logo_url'      => $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '',
				'public_slug'   => $slug,
				'public_url'    => $slug
					? add_query_arg( 'vendor', $slug, lnkup_page_url_helper( 'vendors' ) )
					: '',
				'matching'      => class_exists( 'LNKup_Match_REST' ) ? LNKup_Match_REST::preferences_payload( (int) $profile_id ) : null,
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
		if ( class_exists( 'LNKup_Location_Service' ) && ( array_key_exists( 'city', $body ) || array_key_exists( 'state', $body ) ) ) {
			$old_city  = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_city', true ) : '';
			$old_state = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_state', true ) : '';
			$city      = array_key_exists( 'city', $body ) ? (string) $body['city'] : $old_city;
			$state     = array_key_exists( 'state', $body ) ? (string) $body['state'] : $old_state;
			if ( 0 !== strcasecmp( trim( $city ), $old_city ) || 0 !== strcasecmp( trim( $state ), $old_state ) ) {
				$location = LNKup_Location_Service::canonical_profile_location( $city, $state );
				if ( is_wp_error( $location ) ) {
					return $location;
				}
				$body['city']  = $location['city'];
				$body['state'] = $location['state'];
			}
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

		if ( array_key_exists( 'categories', $body ) ) {
			self::save_categories( (int) $profile_id, $body['categories'] );
		}
		if ( array_key_exists( 'logo_attachment_id', $body ) ) {
			$logo_id      = absint( $body['logo_attachment_id'] );
			$current_logo = (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true );
			if ( $logo_id && LNKup_Security::filter_owned_images( array( $logo_id ), get_current_user_id(), array( $current_logo ) ) ) {
				update_post_meta( $profile_id, '_lnkup_logo_attachment_id', $logo_id );
			}
		}

		if ( class_exists( 'LNKup_Vendor_Reputation' ) ) {
			LNKup_Vendor_Reputation::ensure_public_slug( $profile_id );
			LNKup_Vendor_Reputation::refresh( $profile_id );
		}

		if ( class_exists( 'LNKup_Match_REST' ) ) {
			$applied = LNKup_Match_REST::apply_preferences( (int) $profile_id, isset( $body['matching'] ) && is_array( $body['matching'] ) ? $body['matching'] : array() );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
		}

		do_action( 'lnkup_vendor_profile_saved', (int) $user_id, (int) $profile_id, 'rest' );

		return rest_ensure_response(
			array(
				'success'    => true,
				'profile_id' => (int) $profile_id,
				'message'    => __( 'Profile saved.', 'lnkup-marketplace' ),
			)
		);
	}

	/**
	 * POST multipart logo. Stores the attachment on the vendor profile.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function upload_logo( WP_REST_Request $request ) {
		if ( ! current_user_can( 'lnkup_manage_own_profile' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$user_id    = get_current_user_id();
		$profile_id = function_exists( 'lnkup_get_vendor_profile_id' ) ? (int) lnkup_get_vendor_profile_id( $user_id ) : 0;
		if ( ! $profile_id ) {
			return new WP_Error( 'validation_error', __( 'Save your business name before adding a logo.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$file = LNKup_Profile_Media::file_from_request( $request );
		if ( ! $file ) {
			return new WP_Error( 'no_file', __( 'Choose an image to upload.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$attachment_id = LNKup_Profile_Media::store_image( $file, $profile_id );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		update_post_meta( $profile_id, '_lnkup_logo_attachment_id', $attachment_id );
		return rest_ensure_response(
			array(
				'success'            => true,
				'logo_attachment_id' => (int) $attachment_id,
				'logo_url'           => (string) wp_get_attachment_image_url( $attachment_id, 'medium' ),
			)
		);
	}

	/**
	 * Replace vendor category terms with the submitted names.
	 *
	 * @param int          $profile_id Profile post ID.
	 * @param mixed        $raw        Category names.
	 */
	private static function save_categories( int $profile_id, $raw ) {
		$cats = $raw;
		if ( is_string( $cats ) ) {
			$cats = array_map( 'trim', explode( ',', $cats ) );
		}
		if ( ! is_array( $cats ) ) {
			$cats = array();
		}
		$names = array();
		foreach ( $cats as $cat ) {
			$name = sanitize_text_field( (string) $cat );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		wp_set_object_terms( $profile_id, $names, 'lnkup_vendor_category' );
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
