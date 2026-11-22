<?php
/**
 * Vendor Passport REST API (lnkup/v1/passport/*).
 *
 * Owner routes act on the signed-in vendor's own profile. Public routes resolve by slug only.
 * Organizer routes return the organizer projection; contact data stays relationship-gated.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Passport_REST {

	const NS = 'lnkup/v1';

	const MAX_IMAGE_BYTES = 8388608;

	const TEXT_FIELDS = array(
		'business_name'     => 'text',
		'description'       => 'textarea',
		'products_services' => 'textarea',
		'city'              => 'text',
		'state'             => 'text',
		'website'           => 'url',
		'instagram'         => 'text',
		'tiktok'            => 'text',
		'facebook'          => 'text',
		'owner_name'        => 'text',
		'contact_email'     => 'email',
		'contact_phone'     => 'text',
		'setup_type'        => 'text',
		'tent_size'         => 'text',
		'trailer_size'      => 'text',
		'food_truck_size'   => 'text',
		'power_needed'      => 'text',
		'water_needed'      => 'text',
		'space_needed'      => 'text',
		'availability_notes' => 'textarea',
	);

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$owner = array( __CLASS__, 'owner_permission' );

		register_rest_route(
			self::NS,
			'/passport/me',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_me' ), 'permission_callback' => $owner ),
				array( 'methods' => 'PATCH,POST', 'callback' => array( __CLASS__, 'patch_me' ), 'permission_callback' => $owner ),
			)
		);
		register_rest_route(
			self::NS,
			'/passport/me/portfolio',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_portfolio' ), 'permission_callback' => $owner ),
				array( 'methods' => 'PUT,POST', 'callback' => array( __CLASS__, 'put_portfolio' ), 'permission_callback' => $owner ),
			)
		);
		register_rest_route(
			self::NS,
			'/passport/me/portfolio/upload',
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'upload_portfolio_image' ), 'permission_callback' => $owner )
		);
		register_rest_route(
			self::NS,
			'/passport/me/portfolio/(?P<attachment_id>\d+)',
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_portfolio_item' ), 'permission_callback' => $owner )
		);
		register_rest_route(
			self::NS,
			'/passport/me/privacy',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_privacy' ), 'permission_callback' => $owner ),
				array( 'methods' => 'PATCH,POST', 'callback' => array( __CLASS__, 'patch_privacy' ), 'permission_callback' => $owner ),
			)
		);
		register_rest_route(
			self::NS,
			'/passport/me/history',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_my_history' ), 'permission_callback' => $owner )
		);
		register_rest_route(
			self::NS,
			'/passport/me/reviews',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_my_reviews' ), 'permission_callback' => $owner )
		);
		register_rest_route(
			self::NS,
			'/passport/me/verification',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_my_verification' ), 'permission_callback' => $owner ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'submit_verification' ), 'permission_callback' => $owner ),
			)
		);

		register_rest_route(
			self::NS,
			'/passport/vendors/(?P<slug>[a-z0-9][a-z0-9\-]{0,190})',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_public' ), 'permission_callback' => '__return_true' )
		);
		register_rest_route(
			self::NS,
			'/passport/vendors/(?P<slug>[a-z0-9][a-z0-9\-]{0,190})/history',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_public_history' ), 'permission_callback' => '__return_true' )
		);
		register_rest_route(
			self::NS,
			'/passport/vendors/(?P<slug>[a-z0-9][a-z0-9\-]{0,190})/reviews',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_public_reviews' ), 'permission_callback' => '__return_true' )
		);
		register_rest_route(
			self::NS,
			'/passport/vendors/(?P<profile_id>\d+)/organizer',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_organizer_view' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) )
		);
		register_rest_route(
			self::NS,
			'/passport/vendors/(?P<profile_id>\d+)/invite-tour',
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'invite_to_tour' ), 'permission_callback' => array( __CLASS__, 'organizer_permission' ) )
		);
	}

	public static function invite_to_tour( WP_REST_Request $request ) {
		$profile_id = absint( $request['profile_id'] );
		if ( 'lnkup_vendor_profile' !== get_post_type( $profile_id ) ) {
			return new WP_Error( 'not_found', __( 'This vendor is not available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$body   = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		$result = LNKup_Vendor_Invites::create_for_tour(
			get_current_user_id(),
			(int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true ),
			absint( $body['tour_id'] ?? 0 ),
			(array) ( $body['event_ids'] ?? array() ),
			array( 'message' => sanitize_textarea_field( (string) ( $body['message'] ?? '' ) ) )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/* ------------------------------------------------------------------ permissions */

	public static function owner_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Sign in to manage your Vendor Passport.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'lnkup_manage_own_profile' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			return new WP_Error( 'rest_forbidden', __( 'Vendor access required.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function organizer_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Sign in to view vendor Passports.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		return current_user_can( 'lnkup_review_applications' ) || LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * Feature-flag gate for Passport routes (Stage 27B). Returns a JSON 404 when off.
	 */
	private static function disabled() {
		if ( LNKup_Passport_Settings::enabled( 'passport_enabled' ) ) {
			return null;
		}
		return new WP_Error( 'passport_disabled', __( 'Vendor Passport is not enabled yet.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
	}

	/**
	 * The signed-in vendor's profile ID (staff may pass ?profile_id= to act on behalf of a vendor).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int|WP_Error
	 */
	private static function my_profile_id( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$asked   = absint( $request->get_param( 'profile_id' ) );
		if ( $asked && LNKup_Capabilities::user_has_manage_all( $user_id ) && 'lnkup_vendor_profile' === get_post_type( $asked ) ) {
			return $asked;
		}
		$profile_id = (int) lnkup_get_vendor_profile_id( $user_id );
		if ( ! $profile_id ) {
			return new WP_Error( 'no_profile', __( 'Create your vendor profile first.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return $profile_id;
	}

	private static function rate_limited( string $bucket ): bool {
		if ( ! class_exists( 'LNKup_Trust' ) ) {
			return false;
		}
		return ! LNKup_Trust::rate_limit( $bucket . '_' . LNKup_Trust::ip_hash(), LNKup_Passport_Settings::int( 'passport_public_rate_per_minute' ), MINUTE_IN_SECONDS );
	}

	/* ------------------------------------------------------------------ owner */

	public static function get_me( WP_REST_Request $request ) {
		$off = self::disabled();
		if ( $off ) {
			return $off;
		}
		$profile_id = self::my_profile_id( $request );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		if ( ! get_post_meta( $profile_id, '_lnkup_reputation_updated_at', true ) ) {
			LNKup_Vendor_Reputation::refresh( $profile_id );
		}
		LNKup_Vendor_Reputation::ensure_public_slug( $profile_id );
		return rest_ensure_response( LNKup_Passport_Projection::owner_view( $profile_id ) );
	}

	public static function patch_me( WP_REST_Request $request ) {
		$off = self::disabled();
		if ( $off ) {
			return $off;
		}
		$profile_id = self::my_profile_id( $request );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		$input  = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		$result = self::apply_profile_fields( $profile_id, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		LNKup_Vendor_Reputation::refresh( $profile_id );
		return rest_ensure_response(
			array(
				'success'  => true,
				'updated'  => $result,
				'strength' => LNKup_Growth_Profile_Strength::calculate( $profile_id ),
			)
		);
	}

	/**
	 * Validate and apply Passport profile fields. Only keys present in $input are touched,
	 * so unrelated assets (logo, gallery) are never cleared by a partial save.
	 *
	 * @param int   $profile_id Profile ID.
	 * @param array $input      Raw input.
	 * @return string[]|WP_Error Updated keys.
	 */
	public static function apply_profile_fields( int $profile_id, array $input ) {
		$errors  = array();
		$updated = array();
		$owner   = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		$actor   = get_current_user_id() ?: $owner;

		foreach ( self::TEXT_FIELDS as $key => $kind ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$raw = is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
			switch ( $kind ) {
				case 'textarea':
					$value = sanitize_textarea_field( $raw );
					break;
				case 'url':
					$value = '' === trim( $raw ) ? '' : esc_url_raw( trim( $raw ), array( 'http', 'https' ) );
					if ( '' !== trim( $raw ) && '' === $value ) {
						$errors[ $key ] = __( 'Enter a valid website address starting with http:// or https://.', 'lnkup-marketplace' );
						continue 2;
					}
					break;
				case 'email':
					$value = sanitize_email( $raw );
					if ( '' !== trim( $raw ) && ! is_email( $value ) ) {
						$errors[ $key ] = __( 'Enter a valid email address.', 'lnkup-marketplace' );
						continue 2;
					}
					break;
				default:
					$value = sanitize_text_field( $raw );
			}
			if ( 'business_name' === $key ) {
				if ( '' === trim( $value ) ) {
					$errors[ $key ] = __( 'Business name is required.', 'lnkup-marketplace' );
					continue;
				}
				$value = mb_substr( $value, 0, 120 );
				wp_update_post( array( 'ID' => $profile_id, 'post_title' => $value ) );
			}
			if ( 'description' === $key ) {
				if ( mb_strlen( $value ) > 5000 ) {
					$errors[ $key ] = __( 'Keep your description under 5,000 characters.', 'lnkup-marketplace' );
					continue;
				}
				wp_update_post( array( 'ID' => $profile_id, 'post_content' => $value ) );
				$updated[] = $key;
				continue;
			}
			if ( 'state' === $key ) {
				$value = strtoupper( mb_substr( $value, 0, 2 ) );
			}
			update_post_meta( $profile_id, '_lnkup_' . $key, $value );
			$updated[] = $key;
		}

		foreach ( array( 'cities_served', 'states_served', 'preferred_booth_types', 'preferred_event_types' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$list = is_array( $input[ $key ] ) ? $input[ $key ] : explode( ',', (string) $input[ $key ] );
			$list = array_slice( array_values( array_unique( array_filter( array_map( static fn( $v ) => sanitize_text_field( trim( (string) $v ) ), $list ) ) ) ), 0, 50 );
			update_post_meta( $profile_id, '_lnkup_' . $key, wp_json_encode( $list ) );
			$updated[] = $key;
		}

		if ( array_key_exists( 'capabilities', $input ) ) {
			$allowed = LNKup_Passport_Projection::capability_keys();
			$caps    = array_values( array_intersect( array_map( 'sanitize_key', (array) $input['capabilities'] ), $allowed ) );
			update_post_meta( $profile_id, '_lnkup_capabilities', wp_json_encode( array_values( array_unique( $caps ) ) ) );
			$updated[] = 'capabilities';
		}

		$term_ids = null;
		if ( array_key_exists( 'categories', $input ) ) {
			$term_ids = self::resolve_category_terms( (array) $input['categories'] );
			if ( ! $term_ids ) {
				$errors['categories'] = __( 'Choose at least one category.', 'lnkup-marketplace' );
			} else {
				wp_set_object_terms( $profile_id, $term_ids, 'lnkup_vendor_category' );
				$updated[] = 'categories';
			}
		}
		if ( array_key_exists( 'primary_category', $input ) ) {
			$primary = absint( $input['primary_category'] );
			$assigned = wp_get_object_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'ids' ) );
			if ( $primary && ! is_wp_error( $assigned ) && in_array( $primary, array_map( 'intval', $assigned ), true ) ) {
				update_post_meta( $profile_id, '_lnkup_primary_category', $primary );
				$updated[] = 'primary_category';
			} elseif ( $primary ) {
				$errors['primary_category'] = __( 'Your primary category must be one of your categories.', 'lnkup-marketplace' );
			}
		}
		if ( array_key_exists( 'secondary_categories', $input ) ) {
			$secondary = self::resolve_category_terms( (array) $input['secondary_categories'] );
			update_post_meta( $profile_id, '_lnkup_secondary_categories', wp_json_encode( array_slice( $secondary, 0, 5 ) ) );
			$updated[] = 'secondary_categories';
		}
		if ( array_key_exists( 'years_in_business', $input ) ) {
			update_post_meta( $profile_id, '_lnkup_years_in_business', min( 150, absint( $input['years_in_business'] ) ) );
			$updated[] = 'years_in_business';
		}

		foreach ( array( 'logo_attachment_id' => '_lnkup_logo_attachment_id', 'cover_image_id' => '_lnkup_cover_image_id' ) as $key => $meta ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$aid     = absint( $input[ $key ] );
			$current = (int) get_post_meta( $profile_id, $meta, true );
			if ( ! $aid ) {
				update_post_meta( $profile_id, $meta, 0 );
				$updated[] = $key;
			} elseif ( LNKup_Security::filter_owned_images( array( $aid ), $actor, array( $current ) ) ) {
				update_post_meta( $profile_id, $meta, $aid );
				if ( 'logo_attachment_id' === $key ) {
					set_post_thumbnail( $profile_id, $aid );
				}
				$updated[] = $key;
			} else {
				$errors[ $key ] = __( 'That image is not available for your profile.', 'lnkup-marketplace' );
			}
		}

		if ( $updated ) {
			update_post_meta( $profile_id, '_lnkup_business_name', (string) ( get_post_meta( $profile_id, '_lnkup_business_name', true ) ?: get_the_title( $profile_id ) ) );
			LNKup_Passport_Projection::flush_public_cache( $profile_id );
			LNKup_Vendor_Reputation::log_activity( 'profile_updated', $profile_id, array( 'payload' => array( 'fields' => $updated ) ) );
			do_action( 'lnkup_vendor_passport_updated', $profile_id, $updated );
		}
		if ( $errors ) {
			return new WP_Error( 'invalid_fields', __( 'Some fields need attention.', 'lnkup-marketplace' ), array( 'status' => 400, 'fields' => $errors, 'updated' => $updated ) );
		}
		return $updated;
	}

	/**
	 * Map category IDs/slugs/names to existing term IDs (never creates terms).
	 *
	 * @param array $raw Raw values.
	 * @return int[]
	 */
	private static function resolve_category_terms( array $raw ): array {
		$ids = array();
		foreach ( array_slice( $raw, 0, 10 ) as $value ) {
			$term = null;
			if ( is_numeric( $value ) ) {
				$term = get_term( (int) $value, 'lnkup_vendor_category' );
			} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
				$term = get_term_by( 'slug', sanitize_title( $value ), 'lnkup_vendor_category' ) ?: get_term_by( 'name', sanitize_text_field( $value ), 'lnkup_vendor_category' );
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	public static function get_portfolio( WP_REST_Request $request ) {
		$profile_id = self::my_profile_id( $request );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		return rest_ensure_response(
			array(
				'items' => LNKup_Passport_Projection::portfolio( $profile_id ),
				'tags'  => LNKup_Passport_Projection::portfolio_tags(),
				'max'   => LNKup_Passport_Projection::PORTFOLIO_MAX,
			)
		);
	}

	public static function put_portfolio( WP_REST_Request $request ) {
		$off = self::disabled();
		if ( $off ) {
			return $off;
		}
		$profile_id = self::my_profile_id( $request );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		$body  = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		$items = isset( $body['items'] ) && is_array( $body['items'] ) ? $body['items'] : array();
		$saved = LNKup_Passport_Projection::save_portfolio( $profile_id, get_current_user_id(), $items );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return rest_ensure_response( array( 'items' => $saved ) );
	}

	public static function upload_portfolio_image( WP_REST_Request $request ) {
		$off = self::disabled();
		if ( $off ) {
			return $off;
		}
		$profile_id = self::my_profile_id( $request );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		if ( count( LNKup_Passport_Projection::portfolio_raw( $profile_id ) ) >= LNKup_Passport_Projection::PORTFOLIO_MAX ) {
			return new WP_Error( 'portfolio_full', __( 'Your portfolio is full. Remove a photo to add another.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( class_exists( 'LNKup_Trust' ) && ! LNKup_Trust::rate_limit( 'portfolio_upload_' . get_current_user_id(), 40, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many uploads in the last hour. Try again later.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$file = LNKup_Profile_Media::file_from_request( $request );
		if ( ! $file ) {
			return new WP_Error( 'no_file', __( 'Choose an image to upload.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_IMAGE_BYTES ) {
			return new WP_Error( 'file_too_large', __( 'Images must be under 8 MB.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$attachment_id = LNKup_Profile_Media::store_image( $file, $profile_id );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		update_post_meta( $attachment_id, '_lnkup_profile_image', 1 );
		$items   = LNKup_Passport_Projection::portfolio_raw( $profile_id );
		$items[] = array(
			'attachment_id' => $attachment_id,
			'caption'       => sanitize_text_field( (string) $request->get_param( 'caption' ) ),
			'alt'           => sanitize_text_field( (string) $request->get_param( 'alt' ) ),
			'tag'           => sanitize_key( (string) $request->get_param( 'tag' ) ),
			'is_cover'      => false,
			'event_id'      => absint( $request->get_param( 'event_id' ) ),
		);
		$saved = LNKup_Passport_Projection::save_portfolio( $profile_id, get_current_user_id(), $items );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return rest_ensure_response( array( 'attachment_id' => $attachment_id, 'items' => $saved ) );
	}

	public static function delete_portfolio_item( WP_REST_Request $request ) {
		$profile_id = self::my_profile_id( $request );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		$aid   = absint( $request['attachment_id'] );
		$items = array_values( array_filter( LNKup_Passport_Projection::portfolio_raw( $profile_id ), static fn( $i ) => (int) $i['attachment_id'] !== $aid ) );
		$saved = LNKup_Passport_Projection::save_portfolio( $profile_id, get_current_user_id(), $items );
		return is_wp_error( $saved ) ? $saved : rest_ensure_response( array( 'items' => $saved ) );
	}

	public static function get_privacy( WP_REST_Request $request ) {
		$profile_id = self::my_profile_id( $request );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		return rest_ensure_response( array( 'privacy' => LNKup_Passport_Privacy::get( $profile_id ), 'is_public' => LNKup_Passport_Privacy::is_public( $profile_id ) ) );
	}

	public static function patch_privacy( WP_REST_Request $request ) {
		$off = self::disabled();
		if ( $off ) {
			return $off;
		}
		$profile_id = self::my_profile_id( $request );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		$input = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		if ( isset( $input['hidden_event_ids'] ) ) {
			$own_events = wp_list_pluck( LNKup_Participation_History::all_rows( $profile_id ), 'event_id' );
			$input['hidden_event_ids'] = array_values( array_intersect( array_map( 'absint', (array) $input['hidden_event_ids'] ), array_map( 'intval', $own_events ) ) );
		}
		$prefs = LNKup_Passport_Privacy::save( $profile_id, $input );
		LNKup_Passport_Projection::flush_public_cache( $profile_id );
		LNKup_Vendor_Reputation::log_activity( 'privacy_updated', $profile_id, array( 'payload' => array( 'public_profile' => $prefs['public_profile'] ) ) );
		return rest_ensure_response( array( 'privacy' => $prefs, 'is_public' => LNKup_Passport_Privacy::is_public( $profile_id ) ) );
	}

	public static function get_my_history( WP_REST_Request $request ) {
		$profile_id = self::my_profile_id( $request );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		return rest_ensure_response(
			LNKup_Participation_History::for_profile( $profile_id, 'owner', 0, max( 1, (int) $request->get_param( 'page' ) ), (int) ( $request->get_param( 'per_page' ) ?: 20 ) )
			+ array( 'stats' => LNKup_Participation_History::stats( $profile_id ) )
		);
	}

	public static function get_my_reviews( WP_REST_Request $request ) {
		$profile_id = self::my_profile_id( $request );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		$per_page = max( 1, min( 50, (int) ( $request->get_param( 'per_page' ) ?: 10 ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$items    = LNKup_Vendor_Reviews::get_for_vendor( $profile_id, $per_page, false, get_current_user_id(), ( $page - 1 ) * $per_page );
		foreach ( $items as &$item ) {
			$item['disputes']    = LNKup_Vendor_Reviews::disputes_for_review( (int) $item['id'] );
			$item['can_respond'] = LNKup_Passport_Settings::enabled( 'review_responses' );
			$item['can_report']  = ! LNKup_Vendor_Reviews::open_dispute_for_review( (int) $item['id'] );
		}
		return rest_ensure_response(
			array(
				'items'          => $items,
				'total'          => LNKup_Vendor_Reviews::count_for_vendor( $profile_id ),
				'page'           => $page,
				'per_page'       => $per_page,
				'summary'        => LNKup_Reputation_Summary::organizer_feedback( $profile_id ),
				'report_reasons' => LNKup_Vendor_Reviews::report_reasons(),
			)
		);
	}

	public static function get_my_verification( WP_REST_Request $request ) {
		$profile_id = self::my_profile_id( $request );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		return rest_ensure_response(
			array(
				'records'   => LNKup_Verification_Records::current_all( $profile_id ),
				'summary'   => LNKup_Reputation_Summary::verification( $profile_id ),
				'types'     => LNKup_Verification_Records::type_labels(),
				'can_submit' => LNKup_Passport_Settings::enabled( 'passport_verification_v2' ),
			)
		);
	}

	public static function submit_verification( WP_REST_Request $request ) {
		if ( ! LNKup_Passport_Settings::enabled( 'passport_verification_v2' ) ) {
			return new WP_Error( 'feature_disabled', __( 'Verification requests open soon.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$profile_id = self::my_profile_id( $request );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		$result = LNKup_Verification_Records::submit(
			$profile_id,
			sanitize_key( (string) $request->get_param( 'type' ) ),
			sanitize_textarea_field( (string) $request->get_param( 'note' ) ),
			absint( $request->get_param( 'document_id' ) )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'record_id' => $result, 'records' => LNKup_Verification_Records::current_all( $profile_id ) ) );
	}

	/* ------------------------------------------------------------------ public */

	public static function get_public( WP_REST_Request $request ) {
		$off = self::disabled();
		if ( $off ) {
			return $off;
		}
		if ( self::rate_limited( 'passport_pub' ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Try again in a minute.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$profile_id = LNKup_Vendor_Reputation::resolve_public_profile( (string) $request['slug'] );
		$view       = $profile_id ? LNKup_Passport_Projection::public_view( $profile_id ) : null;
		if ( ! $view ) {
			return new WP_Error( 'not_found', __( 'This vendor profile is private or does not exist.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$response = rest_ensure_response( $view );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	public static function get_public_history( WP_REST_Request $request ) {
		$off = self::disabled();
		if ( $off ) {
			return $off;
		}
		if ( self::rate_limited( 'passport_pub' ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Try again in a minute.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$profile_id = LNKup_Vendor_Reputation::resolve_public_profile( (string) $request['slug'] );
		if ( ! $profile_id ) {
			return new WP_Error( 'not_found', __( 'This vendor profile is private or does not exist.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( LNKup_Participation_History::for_profile( $profile_id, 'public', 0, max( 1, (int) $request->get_param( 'page' ) ), 20 ) );
	}

	public static function get_public_reviews( WP_REST_Request $request ) {
		$off = self::disabled();
		if ( $off ) {
			return $off;
		}
		if ( self::rate_limited( 'passport_pub' ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Try again in a minute.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$profile_id = LNKup_Vendor_Reputation::resolve_public_profile( (string) $request['slug'] );
		if ( ! $profile_id ) {
			return new WP_Error( 'not_found', __( 'This vendor profile is private or does not exist.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$privacy = LNKup_Passport_Privacy::get( $profile_id );
		if ( empty( $privacy['show_reviews'] ) ) {
			return rest_ensure_response( array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => 10, 'hidden' => true ) );
		}
		$page = max( 1, (int) $request->get_param( 'page' ) );
		return rest_ensure_response(
			array(
				'items'    => LNKup_Vendor_Reviews::get_for_vendor( $profile_id, 10, false, 0, ( $page - 1 ) * 10 ),
				'total'    => LNKup_Vendor_Reviews::count_for_vendor( $profile_id ),
				'page'     => $page,
				'per_page' => 10,
				'summary'  => LNKup_Reputation_Summary::organizer_feedback( $profile_id ),
			)
		);
	}

	/* ------------------------------------------------------------------ organizer */

	public static function get_organizer_view( WP_REST_Request $request ) {
		$view = LNKup_Passport_Projection::organizer_view( absint( $request['profile_id'] ), get_current_user_id(), absint( $request->get_param( 'opportunity_id' ) ) );
		if ( ! $view ) {
			return new WP_Error( 'not_found', __( 'This vendor is not available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $view );
	}
}
