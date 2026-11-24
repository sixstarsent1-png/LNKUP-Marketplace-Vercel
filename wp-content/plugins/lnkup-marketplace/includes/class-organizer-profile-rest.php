<?php
/**
 * Organizer public profile REST for the mobile app.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Profile_REST {

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
			'/organizer/profile',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_profile' ),
					'permission_callback' => array( __CLASS__, 'organizer_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_profile' ),
					'permission_callback' => array( __CLASS__, 'organizer_permission' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/organizer/profile/logo',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload_logo' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/organizer/profile/cover',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload_cover' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/organizers/(?P<slug>[^/]+)/public',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'public_profile' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET the signed-in organizer's editable profile.
	 */
	public static function get_profile() {
		return rest_ensure_response( self::editable_payload( get_current_user_id() ) );
	}

	/**
	 * POST partial profile update. Only submitted keys are written.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function update_profile( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params() ?: $request->get_params();
		$map     = array(
			'public_name'    => '_lnkup_organizer_public_name',
			'tagline'        => '_lnkup_organizer_tagline',
			'bio'            => '_lnkup_organizer_bio',
			'city'           => '_lnkup_organizer_city',
			'state'          => '_lnkup_organizer_state',
			'service_cities' => '_lnkup_organizer_service_cities',
			'website'        => '_lnkup_organizer_website',
			'instagram'      => '_lnkup_organizer_instagram',
			'tiktok'         => '_lnkup_organizer_tiktok',
		);

		if ( array_key_exists( 'category', $body ) ) {
			$category = sanitize_title( (string) $body['category'] );
			$options  = class_exists( 'LNKup_Organizer_Onboarding_Flow' ) ? LNKup_Organizer_Onboarding_Flow::category_options() : array();
			if ( '' !== $category && ! isset( $options[ $category ] ) ) {
				return new WP_Error( 'invalid_category', __( 'Choose one of the listed event categories.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			update_user_meta( $user_id, '_lnkup_organizer_category', $category );
		}

		if ( class_exists( 'LNKup_Location_Service' ) && ( array_key_exists( 'city', $body ) || array_key_exists( 'state', $body ) ) ) {
			$city     = array_key_exists( 'city', $body ) ? (string) $body['city'] : (string) get_user_meta( $user_id, '_lnkup_organizer_city', true );
			$state    = array_key_exists( 'state', $body ) ? (string) $body['state'] : (string) get_user_meta( $user_id, '_lnkup_organizer_state', true );
			$same     = 0 === strcasecmp( trim( $city ), (string) get_user_meta( $user_id, '_lnkup_organizer_city', true ) )
				&& 0 === strcasecmp( trim( $state ), (string) get_user_meta( $user_id, '_lnkup_organizer_state', true ) );
			if ( ! $same ) {
				$location = LNKup_Location_Service::canonical_profile_location( $city, $state );
				if ( is_wp_error( $location ) ) {
					return $location;
				}
				$body['city']  = $location['city'];
				$body['state'] = $location['state'];
			}
		}

		foreach ( $map as $key => $meta_key ) {
			if ( ! array_key_exists( $key, $body ) ) {
				continue;
			}
			$value = (string) $body[ $key ];
			if ( 'bio' === $key ) {
				update_user_meta( $user_id, $meta_key, sanitize_textarea_field( $value ) );
			} elseif ( 'website' === $key ) {
				update_user_meta( $user_id, $meta_key, esc_url_raw( $value ) );
			} else {
				update_user_meta( $user_id, $meta_key, sanitize_text_field( $value ) );
			}
		}

		if ( isset( $body['public_slug'] ) && '' !== trim( (string) $body['public_slug'] ) && class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			$slug_result = LNKup_Organizer_Onboarding::set_organizer_public_slug( $user_id, (string) $body['public_slug'] );
			if ( is_wp_error( $slug_result ) ) {
				return new WP_Error( $slug_result->get_error_code(), $slug_result->get_error_message(), array( 'status' => 400 ) );
			}
		}

		do_action( 'lnkup_organizer_profile_saved', (int) $user_id, 'rest' );

		$payload             = self::editable_payload( $user_id );
		$payload['success']  = true;
		$payload['message']  = __( 'Profile saved.', 'lnkup-marketplace' );
		return rest_ensure_response( $payload );
	}

	/**
	 * POST multipart logo.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function upload_logo( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$file    = LNKup_Profile_Media::file_from_request( $request );
		if ( ! $file ) {
			return new WP_Error( 'no_file', __( 'Choose an image to upload.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$attachment_id = LNKup_Profile_Media::store_image( $file, 0 );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		update_user_meta( $user_id, '_lnkup_organizer_logo_id', (int) $attachment_id );
		return rest_ensure_response(
			array(
				'success'  => true,
				'logo_id'  => (int) $attachment_id,
				'logo_url' => (string) wp_get_attachment_image_url( $attachment_id, 'medium' ),
			)
		);
	}

	/**
	 * POST multipart cover image (shown on the public organizer page).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function upload_cover( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$file    = LNKup_Profile_Media::file_from_request( $request );
		if ( ! $file ) {
			return new WP_Error( 'no_file', __( 'Choose an image to upload.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$attachment_id = LNKup_Profile_Media::store_image( $file, 0 );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		update_user_meta( $user_id, '_lnkup_organizer_cover_id', (int) $attachment_id );
		return rest_ensure_response(
			array(
				'success'   => true,
				'cover_id'  => (int) $attachment_id,
				'cover_url' => (string) wp_get_attachment_image_url( $attachment_id, 'large' ),
			)
		);
	}

	/**
	 * Public organizer storefront JSON.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function public_profile( WP_REST_Request $request ) {
		if ( ! class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			return new WP_Error( 'not_found', __( 'Organizer not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$user_id = LNKup_Organizer_Onboarding::organizer_id_from_public_slug( (string) $request['slug'] );
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return new WP_Error( 'not_found', __( 'Organizer not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$data = self::public_payload( $user_id );
		return rest_ensure_response( $data );
	}

	/**
	 * Organizer or platform admin.
	 */
	public static function organizer_permission() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return LNKup_Roles::is_organizer() || LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * Editable profile for the signed-in organizer.
	 *
	 * @param int $user_id Organizer user ID.
	 * @return array<string, mixed>
	 */
	private static function editable_payload( int $user_id ) {
		$slug = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::organizer_public_slug( $user_id ) : '';
		$url  = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::organizer_public_url( $user_id ) : '';
		$logo_id = (int) get_user_meta( $user_id, '_lnkup_organizer_logo_id', true );
		return array(
			'public_name'    => (string) get_user_meta( $user_id, '_lnkup_organizer_public_name', true ),
			'tagline'        => (string) get_user_meta( $user_id, '_lnkup_organizer_tagline', true ),
			'bio'            => (string) get_user_meta( $user_id, '_lnkup_organizer_bio', true ),
			'city'           => (string) get_user_meta( $user_id, '_lnkup_organizer_city', true ),
			'state'          => (string) get_user_meta( $user_id, '_lnkup_organizer_state', true ),
			'service_cities' => (string) get_user_meta( $user_id, '_lnkup_organizer_service_cities', true ),
			'website'        => (string) get_user_meta( $user_id, '_lnkup_organizer_website', true ),
			'instagram'      => (string) get_user_meta( $user_id, '_lnkup_organizer_instagram', true ),
			'tiktok'         => (string) get_user_meta( $user_id, '_lnkup_organizer_tiktok', true ),
			'category'       => (string) get_user_meta( $user_id, '_lnkup_organizer_category', true ),
			'public_slug'    => $slug,
			'public_url'     => $url,
			'logo_id'        => $logo_id,
			'logo_url'       => $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '',
			'cover_id'       => (int) get_user_meta( $user_id, '_lnkup_organizer_cover_id', true ),
			'cover_url'      => (int) get_user_meta( $user_id, '_lnkup_organizer_cover_id', true ) ? (string) wp_get_attachment_image_url( (int) get_user_meta( $user_id, '_lnkup_organizer_cover_id', true ), 'large' ) : '',
		);
	}

	/**
	 * Public fields only.
	 *
	 * @param int $user_id Organizer user ID.
	 * @return array<string, mixed>
	 */
	private static function public_payload( int $user_id ) {
		$user = get_userdata( $user_id );
		$name = (string) get_user_meta( $user_id, '_lnkup_organizer_public_name', true );
		if ( '' === $name && $user ) {
			$name = $user->display_name;
		}
		$logo_id = (int) get_user_meta( $user_id, '_lnkup_organizer_logo_id', true );
		$cover_id = (int) get_user_meta( $user_id, '_lnkup_organizer_cover_id', true );
		return array(
			'business_name'  => $name,
			'tagline'        => (string) get_user_meta( $user_id, '_lnkup_organizer_tagline', true ),
			'description'    => (string) get_user_meta( $user_id, '_lnkup_organizer_bio', true ),
			'city'           => (string) get_user_meta( $user_id, '_lnkup_organizer_city', true ),
			'state'          => (string) get_user_meta( $user_id, '_lnkup_organizer_state', true ),
			'service_cities' => (string) get_user_meta( $user_id, '_lnkup_organizer_service_cities', true ),
			'website'        => (string) get_user_meta( $user_id, '_lnkup_organizer_website', true ),
			'instagram'      => (string) get_user_meta( $user_id, '_lnkup_organizer_instagram', true ),
			'logo'           => $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '',
			'cover'          => $cover_id ? (string) wp_get_attachment_image_url( $cover_id, 'large' ) : '',
			'public_slug'    => class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::organizer_public_slug( $user_id ) : '',
		);
	}
}
