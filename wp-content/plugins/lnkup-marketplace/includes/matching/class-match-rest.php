<?php
/**
 * Matching REST routes (lnkup/v1/match/*, lnkup/v1/admin/match/*).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Match_REST {

	const NS = 'lnkup/v1';

	const VENDOR_SECTIONS = array( 'recommended', 'preferred_markets', 'new', 'deadlines', 'previous_organizers', 'tours', 'series' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/match/vendor/recommendations',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'vendor_recommendations' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				'args'                => array(
					'section'  => array(
						'type'    => 'string',
						'enum'    => self::VENDOR_SECTIONS,
						'default' => 'recommended',
					),
					'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 24, 'default' => 12 ),
					'html'     => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/match/vendor/opportunities/(?P<id>\d+)/explain',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'vendor_explain' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/match/vendor/preferences',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_preferences' ),
					'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_preferences' ),
					'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/match/organizer/events/(?P<event_id>\d+)/vendors',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'organizer_event_vendors' ),
				'permission_callback' => array( __CLASS__, 'organizer_event_permission' ),
				'args'                => array(
					'opportunity_id'  => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
					'category'        => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
					'city'            => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'state'           => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'verified'        => array( 'type' => 'boolean', 'default' => false ),
					'radius'          => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 1000, 'default' => 0 ),
					'exclude_applied' => array( 'type' => 'boolean', 'default' => true ),
					'stage'           => array(
						'type'    => 'string',
						'enum'    => array_merge( array( '' ), LNKup_Recruitment::STAGES ),
						'default' => '',
					),
					'section'         => array(
						'type'    => 'string',
						'enum'    => LNKup_Match_Service::ORGANIZER_SECTIONS,
						'default' => 'recommended',
					),
					'page'            => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					'per_page'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 48, 'default' => 24 ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/match/organizer/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'organizer_status' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/match/diagnose',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_diagnose' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
				'args'                => array(
					'profile_id'     => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
					'opportunity_id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/match/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_health' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */

	public static function vendor_permission() {
		$user_id = get_current_user_id();
		return $user_id && ( LNKup_Roles::is_vendor( $user_id ) || LNKup_Capabilities::user_has_manage_all( $user_id ) );
	}

	public static function organizer_permission() {
		$user_id = get_current_user_id();
		return $user_id && ( LNKup_Roles::is_organizer( $user_id ) || current_user_can( 'lnkup_review_applications' ) || LNKup_Capabilities::user_has_manage_all( $user_id ) );
	}

	public static function organizer_event_permission( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		return self::organizer_permission() && LNKup_Capabilities::organizer_owns_event( absint( $request['event_id'] ), $user_id );
	}

	/**
	 * Whether organizer Find Vendors is on for the current user (flag + rollout audience).
	 * Lets the apps hide recruit entry points instead of linking to a disabled screen.
	 */
	public static function organizer_status() {
		return rest_ensure_response(
			array(
				'find_vendors_enabled' => LNKup_Match_Settings::enabled( 'matching_organizer_discovery_enabled' ),
			)
		);
	}

	public static function admin_permission() {
		return LNKup_Capabilities::user_has_manage_all() || current_user_can( 'lnkup_manage_settings' );
	}

	/* ------------------------------------------------------------------ */

	public static function vendor_recommendations( WP_REST_Request $request ) {
		$section = sanitize_key( $request->get_param( 'section' ) );
		if ( ! LNKup_Match_Settings::enabled( 'matching_vendor_discovery_enabled' ) || ( in_array( $section, array( 'tours', 'series' ), true ) && ! LNKup_Match_Settings::enabled( 'matching_tours_enabled' ) ) ) {
			return rest_ensure_response( self::disabled_payload( $section ) );
		}
		try {
			$result = LNKup_Match_Service::vendor_recommendations(
				get_current_user_id(),
				array(
					'section'  => $section,
					'page'     => (int) $request->get_param( 'page' ),
					'per_page' => (int) $request->get_param( 'per_page' ),
				)
			);
		} catch ( Throwable $e ) {
			return self::failure( $e );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['items'] ) ) {
			LNKup_Match_Funnel::impression( get_current_user_id(), $section, $result['items'] );
			if ( ! in_array( $section, array( 'tours', 'series' ), true ) && rest_sanitize_boolean( $request->get_param( 'html' ) ) ) {
				$result['html'] = lnkup_render_opportunity_cards_batch( $result['items'] );
			}
		}
		$result['enabled'] = true;
		return rest_ensure_response( $result );
	}

	public static function vendor_explain( WP_REST_Request $request ) {
		if ( ! LNKup_Match_Settings::enabled() ) {
			return rest_ensure_response( array_merge( LNKup_Growth_Match::empty_score(), array( 'enabled' => false ) ) );
		}
		try {
			$result = LNKup_Match_Service::explain_for_vendor( get_current_user_id(), absint( $request['id'] ) );
		} catch ( Throwable $e ) {
			return self::failure( $e );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! lnkup_vendor_can_discover_opportunity( get_current_user_id(), absint( $request['id'] ) ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'We could not find that opportunity.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$result['enabled'] = true;
		return rest_ensure_response( $result );
	}

	/**
	 * Matching preferences with option lists for the editor.
	 */
	public static function get_preferences() {
		$profile_id = (int) lnkup_get_vendor_profile_id( get_current_user_id() );
		if ( ! $profile_id ) {
			return rest_ensure_response( array( 'needs_profile' => true ) );
		}
		return rest_ensure_response( self::preferences_payload( $profile_id ) );
	}

	/**
	 * Save matching preferences (partial updates allowed).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function save_preferences( WP_REST_Request $request ) {
		$profile_id = (int) lnkup_get_vendor_profile_id( get_current_user_id() );
		if ( ! $profile_id || ! LNKup_Capabilities::vendor_owns_profile( $profile_id ) ) {
			return new WP_Error( 'lnkup_needs_profile', __( 'Create your vendor profile first.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$body   = $request->get_json_params() ?: $request->get_body_params();
		$result = self::apply_preferences( $profile_id, is_array( $body ) ? $body : array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array_merge( array( 'success' => true ), self::preferences_payload( $profile_id ) ) );
	}

	/**
	 * Validate and persist matching preference fields onto the canonical profile.
	 * Shared by REST, the dashboard AJAX save, and mobile.
	 *
	 * @param int   $profile_id Profile ID.
	 * @param array $input      Raw input.
	 * @return true|WP_Error
	 */
	public static function apply_preferences( $profile_id, array $input ) {
		$profile_id = (int) $profile_id;

		if ( array_key_exists( 'categories', $input ) ) {
			$term_ids = array_values( array_filter( array_map( 'absint', (array) $input['categories'] ) ) );
			foreach ( $term_ids as $term_id ) {
				if ( ! term_exists( $term_id, LNKup_Match_Categories::TAXONOMY ) ) {
					return new WP_Error( 'lnkup_invalid_category', __( 'Choose categories from the list.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
				}
			}
			wp_set_object_terms( $profile_id, array_slice( $term_ids, 0, 6 ), LNKup_Match_Categories::TAXONOMY );
		}
		if ( array_key_exists( 'primary_category', $input ) ) {
			$primary = absint( $input['primary_category'] );
			if ( $primary && ! has_term( $primary, LNKup_Match_Categories::TAXONOMY, $profile_id ) ) {
				return new WP_Error( 'lnkup_invalid_category', __( 'Your primary category must be one of your categories.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			update_post_meta( $profile_id, '_lnkup_primary_category', $primary );
		}
		foreach ( array( 'city' => '_lnkup_city', 'zip' => '_lnkup_zip' ) as $key => $meta ) {
			if ( array_key_exists( $key, $input ) ) {
				update_post_meta( $profile_id, $meta, substr( sanitize_text_field( (string) $input[ $key ] ), 0, 'zip' === $key ? 10 : 100 ) );
			}
		}
		if ( array_key_exists( 'state', $input ) ) {
			$state = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $input['state'] ) );
			update_post_meta( $profile_id, '_lnkup_state', substr( $state, 0, 2 ) );
		}
		if ( array_key_exists( 'cities_served', $input ) ) {
			$cities = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_text_field', self::to_list( $input['cities_served'] ) ) ) ) ), 0, 25 );
			update_post_meta( $profile_id, '_lnkup_cities_served', wp_json_encode( $cities ) );
		}
		if ( array_key_exists( 'states_served', $input ) ) {
			$states = array();
			foreach ( self::to_list( $input['states_served'] ) as $state ) {
				$state = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $state ) );
				if ( 2 === strlen( $state ) ) {
					$states[] = $state;
				}
			}
			update_post_meta( $profile_id, '_lnkup_states_served', wp_json_encode( array_values( array_unique( $states ) ) ) );
		}
		if ( array_key_exists( 'radius_miles', $input ) ) {
			update_post_meta( $profile_id, '_lnkup_service_radius_miles', min( 1000, absint( $input['radius_miles'] ) ) );
		}
		if ( array_key_exists( 'travel_national', $input ) ) {
			update_post_meta( $profile_id, '_lnkup_willing_to_travel_national', rest_sanitize_boolean( $input['travel_national'] ) ? '1' : '' );
		}
		if ( array_key_exists( 'event_types', $input ) ) {
			$names = array();
			foreach ( self::to_list( $input['event_types'] ) as $value ) {
				$term = is_numeric( $value ) ? get_term( (int) $value, 'lnkup_event_type' ) : get_term_by( 'name', sanitize_text_field( (string) $value ), 'lnkup_event_type' );
				if ( $term && ! is_wp_error( $term ) ) {
					$names[] = $term->name;
				}
			}
			update_post_meta( $profile_id, '_lnkup_preferred_event_types', wp_json_encode( array_values( array_unique( $names ) ) ) );
		}
		if ( array_key_exists( 'available_months', $input ) ) {
			$months = array_values( array_unique( array_filter( array_map( 'absint', self::to_list( $input['available_months'] ) ), array( 'LNKup_Match_Vendor_Index', 'valid_month' ) ) ) );
			sort( $months );
			update_post_meta( $profile_id, '_lnkup_available_months', wp_json_encode( $months ) );
		}
		if ( array_key_exists( 'blackout_dates', $input ) ) {
			$dates = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_text_field', self::to_list( $input['blackout_dates'] ) ), array( 'LNKup_Match_Vendor_Index', 'valid_date' ) ) ) ), 0, 60 );
			update_post_meta( $profile_id, '_lnkup_blackout_dates', wp_json_encode( $dates ) );
		}
		if ( array_key_exists( 'fee_max', $input ) ) {
			$fee = '' === (string) $input['fee_max'] || null === $input['fee_max'] ? '' : max( 0, (float) $input['fee_max'] );
			if ( '' === $fee ) {
				delete_post_meta( $profile_id, '_lnkup_preferred_fee_max' );
			} else {
				update_post_meta( $profile_id, '_lnkup_preferred_fee_max', $fee );
			}
		}
		if ( array_key_exists( 'env_pref', $input ) ) {
			$env = sanitize_key( (string) $input['env_pref'] );
			update_post_meta( $profile_id, '_lnkup_indoor_outdoor_preference', in_array( $env, array( 'indoor', 'outdoor', 'both' ), true ) ? $env : '' );
		}
		foreach ( array( 'discoverable' => '_lnkup_discoverable', 'accept_invites' => '_lnkup_accept_invites' ) as $key => $meta ) {
			if ( array_key_exists( $key, $input ) ) {
				update_post_meta( $profile_id, $meta, rest_sanitize_boolean( $input[ $key ] ) ? '1' : '0' );
			}
		}

		LNKup_Match_Vendor_Index::sync( $profile_id );
		return true;
	}

	/**
	 * Preferences + completeness + option lists.
	 *
	 * @param int $profile_id Profile ID.
	 */
	public static function preferences_payload( $profile_id ) {
		$prefs        = LNKup_Match_Vendor_Index::preferences( $profile_id );
		$completeness = LNKup_Match_Vendor_Index::completeness( $prefs );
		$categories   = get_terms( array( 'taxonomy' => LNKup_Match_Categories::TAXONOMY, 'hide_empty' => false ) );
		$event_types  = get_terms( array( 'taxonomy' => 'lnkup_event_type', 'hide_empty' => false, 'parent' => 0 ) );
		$missing      = array(
			'categories'  => __( 'Add your vendor categories', 'lnkup-marketplace' ),
			'location'    => __( 'Add your home city and state', 'lnkup-marketplace' ),
			'markets'     => __( 'Choose the cities or states you serve', 'lnkup-marketplace' ),
			'radius'      => __( 'Set how far you will travel', 'lnkup-marketplace' ),
			'event_types' => __( 'Pick the event types you like', 'lnkup-marketplace' ),
			'months'      => __( 'Choose the months you are available', 'lnkup-marketplace' ),
			'budget'      => __( 'Set a maximum booth fee', 'lnkup-marketplace' ),
			'environment' => __( 'Indoor, outdoor, or both?', 'lnkup-marketplace' ),
		);
		return array(
			'profile_id'   => (int) $profile_id,
			'preferences'  => array(
				'categories'       => array_map( 'intval', wp_list_pluck( $prefs['categories'], 'term_id' ) ),
				'primary_category' => (int) $prefs['primary_category'],
				'city'             => $prefs['city'],
				'state'            => $prefs['state'],
				'zip'              => $prefs['zip'],
				'cities_served'    => $prefs['cities_served'],
				'states_served'    => $prefs['states_served'],
				'radius_miles'     => (int) $prefs['radius_miles'],
				'travel_national'  => (bool) $prefs['travel_national'],
				'event_types'      => $prefs['event_types'],
				'available_months' => $prefs['available_months'],
				'blackout_dates'   => $prefs['blackout_dates'],
				'fee_max'          => $prefs['fee_max'],
				'env_pref'         => $prefs['env_pref'],
				'discoverable'     => (bool) $prefs['discoverable'],
				'accept_invites'   => (bool) $prefs['accept_invites'],
			),
			'completeness' => array(
				'score'   => $completeness['score'],
				'missing' => array_map(
					static function ( $key ) use ( $missing ) {
						return array( 'key' => $key, 'label' => $missing[ $key ] ?? $key );
					},
					$completeness['missing']
				),
			),
			'options'      => array(
				'categories'  => is_wp_error( $categories ) ? array() : array_map( static function ( $t ) {
					return array( 'id' => (int) $t->term_id, 'name' => $t->name );
				}, $categories ),
				'event_types' => is_wp_error( $event_types ) ? array() : array_map( static function ( $t ) {
					return array( 'id' => (int) $t->term_id, 'name' => $t->name );
				}, $event_types ),
			),
		);
	}

	public static function organizer_event_vendors( WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		if ( ! LNKup_Match_Settings::enabled( 'matching_organizer_discovery_enabled' ) ) {
			return rest_ensure_response( array_merge( self::disabled_payload( 'vendors' ), array( 'event_id' => $event_id ) ) );
		}
		try {
			$result = LNKup_Match_Service::event_vendor_candidates(
				get_current_user_id(),
				$event_id,
				array(
					'opportunity_id'  => (int) $request->get_param( 'opportunity_id' ),
					'category'        => (int) $request->get_param( 'category' ),
					'city'            => (string) $request->get_param( 'city' ),
					'state'           => (string) $request->get_param( 'state' ),
					'verified'        => (bool) $request->get_param( 'verified' ),
					'radius'          => (int) $request->get_param( 'radius' ),
					'exclude_applied' => (bool) $request->get_param( 'exclude_applied' ),
					'stage'           => (string) $request->get_param( 'stage' ),
					'section'         => (string) $request->get_param( 'section' ),
					'page'            => (int) $request->get_param( 'page' ),
					'per_page'        => (int) $request->get_param( 'per_page' ),
				)
			);
		} catch ( Throwable $e ) {
			return self::failure( $e );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		LNKup_Marketplace_Analytics::track( 'recruit_view', array( 'event_id' => $event_id, 'count' => count( $result['items'] ) ), get_current_user_id() );
		$result['enabled'] = true;
		return rest_ensure_response( $result );
	}

	public static function admin_diagnose( WP_REST_Request $request ) {
		$result = LNKup_Match_Service::diagnose( absint( $request['profile_id'] ), absint( $request['opportunity_id'] ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function admin_health() {
		return rest_ensure_response( LNKup_Match_Cron::health() );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Safe JSON when a feature flag is off.
	 *
	 * @param string $section Section.
	 */
	public static function disabled_payload( $section ) {
		return array(
			'enabled' => false,
			'section' => $section,
			'items'   => array(),
			'total'   => 0,
			'page'    => 1,
			'pages'   => 0,
		);
	}

	private static function failure( Throwable $e ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'LNKup matching request failed: ' . $e->getMessage() );
		return new WP_Error( 'lnkup_match_unavailable', __( 'Matches are temporarily unavailable. Please try again.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
	}

	/**
	 * Accept arrays or comma-separated strings.
	 *
	 * @param mixed $value Raw.
	 * @return array
	 */
	private static function to_list( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		$value = trim( (string) $value );
		return '' === $value ? array() : array_map( 'trim', explode( ',', $value ) );
	}
}
