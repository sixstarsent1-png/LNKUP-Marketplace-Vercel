<?php
/**
 * Organizer Portfolio OS REST routes (lnkup/v1/portfolio/*).
 *
 * Every organizer route requires the organizer role, the portfolio_os flag for that user (404 when
 * off) and, for tour routes, ownership of the tour. Stop routes additionally verify the event is a
 * stop of that tour and belongs to the same organizer. Responses are JSON only; errors are WP_Error
 * with friendly messages.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Portfolio_REST {

	const NS = 'lnkup/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Organizer with the Portfolio OS enabled.
	 *
	 * @return true|WP_Error
	 */
	public static function organizer_permission() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'rest_forbidden', __( 'Log in to continue.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		if ( ! LNKup_Feature_Flags::portfolio( $user_id ) ) {
			return new WP_Error( 'lnkup_feature_disabled', __( 'Tours & Series is not available on your account yet.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! LNKup_Roles::is_organizer( $user_id ) && ! LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Only organizers can manage tours.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Read access to the {id} tour: the owner, or a team member whose membership covers the
	 * tour and can view the roster. Portfolio OS must be on for the tour's owner.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function tour_view_permission( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'rest_forbidden', __( 'Log in to continue.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		$tour_id = absint( $request['id'] );
		$owner   = (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true );
		if ( ! $owner || ! LNKup_Capabilities::tour_staff_can( $tour_id, 'view_roster', $user_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Tour not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! LNKup_Feature_Flags::portfolio( $owner ) ) {
			return new WP_Error( 'lnkup_feature_disabled', __( 'Tours & Series is not available on your account yet.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * Organizer who owns the {id} tour.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function tour_permission( WP_REST_Request $request ) {
		$ok = self::organizer_permission();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( ! LNKup_Capabilities::organizer_owns_tour( absint( $request['id'] ), get_current_user_id() ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Tour not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * Tour owner and {event_id} is one of its stops.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function stop_permission( WP_REST_Request $request ) {
		$ok = self::tour_permission( $request );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( ! LNKup_Capabilities::event_is_tour_stop( absint( $request['id'] ), absint( $request['event_id'] ) ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'This stop is not part of the tour.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * Logged-in vendor (tour interest).
	 *
	 * @return bool|WP_Error
	 */
	public static function vendor_permission() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'rest_forbidden', __( 'Log in to continue.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		return LNKup_Roles::is_vendor( $user_id );
	}

	public static function register_routes() {
		$id       = '/portfolio/tours/(?P<id>\d+)';
		$stop     = $id . '/stops/(?P<event_id>\d+)';
		$org      = array( __CLASS__, 'organizer_permission' );
		$owner    = array( __CLASS__, 'tour_permission' );
		$viewer   = array( __CLASS__, 'tour_view_permission' );
		$stop_ok  = array( __CLASS__, 'stop_permission' );
		$routes   = array(
			array( '/portfolio/overview', 'GET', 'overview', $org ),
			array( '/portfolio/tours', 'GET', 'list_tours', $org ),
			array( '/portfolio/tours', 'POST', 'create_tour', $org ),
			array( $id, 'GET', 'get_tour', $viewer ),
			array( $id, 'PATCH', 'update_tour', $owner ),
			array( $id, 'DELETE', 'delete_tour', $owner ),
			array( $id . '/archive', 'POST', 'archive_tour', $owner ),
			array( $id . '/cancel', 'POST', 'cancel_stops', $owner ),
			array( $id . '/stops', 'POST', 'add_stops', $owner ),
			array( $id . '/stops/reorder', 'POST', 'reorder_stops', $owner ),
			array( $stop, 'PATCH', 'update_stop', $stop_ok ),
			array( $stop, 'DELETE', 'delete_stop', $stop_ok ),
			array( $stop . '/detach', 'POST', 'detach_stop', $stop_ok ),
			array( $stop . '/duplicate', 'POST', 'duplicate_stop', $stop_ok ),
			array( $id . '/publish', 'POST', 'publish_stops', $owner ),
			array( $id . '/recurrence/preview', 'POST', 'recurrence_preview', $owner ),
			array( $id . '/recurrence/generate', 'POST', 'recurrence_generate', $owner ),
			array( $id . '/recurrence/change-remaining', 'POST', 'recurrence_change_remaining', $owner ),
			array( $id . '/occurrences/(?P<event_id>\d+)/skip', 'POST', 'occurrence_skip', $stop_ok ),
			array( $id . '/occurrences/(?P<event_id>\d+)/move', 'POST', 'occurrence_move', $stop_ok ),
			array( $id . '/defaults', 'GET', 'get_defaults', $owner ),
			array( $id . '/defaults', 'PUT', 'save_defaults', $owner ),
			array( $id . '/bulk/preview', 'POST', 'bulk_preview', $owner ),
			array( $id . '/bulk/apply', 'POST', 'bulk_apply', $owner ),
			array( $id . '/summary', 'GET', 'summary', $viewer ),
			array( $id . '/applications', 'GET', 'applications', $viewer ),
			array( $id . '/applications/bulk', 'POST', 'applications_bulk', $owner ),
			array( $id . '/interest', 'GET', 'interest_list', $owner ),
			array( $id . '/invite', 'POST', 'invite', $owner ),
			array( $id . '/audit', 'GET', 'audit', $owner ),
			array( $id . '/save-as-template', 'POST', 'save_as_template', $owner ),
			array( '/portfolio/brands', 'GET', 'list_brands', $org ),
			array( '/portfolio/brands', 'POST', 'create_brand', $org ),
			array( '/portfolio/brands/(?P<brand_id>\d+)', 'PATCH', 'update_brand', $org ),
			array( '/portfolio/templates', 'GET', 'list_templates', $org ),
			array( '/portfolio/templates', 'POST', 'create_template', $org ),
			array( '/portfolio/templates/(?P<template_id>\d+)', 'PATCH', 'update_template', $org ),
			array( '/portfolio/templates/(?P<template_id>\d+)', 'DELETE', 'delete_template', $org ),
			array( '/portfolio/templates/(?P<template_id>\d+)/duplicate', 'POST', 'duplicate_template', $org ),
			array( '/portfolio/templates/(?P<template_id>\d+)/apply', 'POST', 'apply_template', $org ),
			array( '/portfolio/events', 'GET', 'events', $org ),
			array( '/portfolio/jobs/(?P<job_id>\d+)', 'GET', 'job', $org ),
		);
		foreach ( $routes as $route ) {
			register_rest_route(
				self::NS,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( __CLASS__, $route[2] ),
					'permission_callback' => $route[3],
				)
			);
		}

		register_rest_route(
			self::NS,
			'/portfolio/public/tours/(?P<slug>[a-z0-9\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'public_tour' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			$id . '/interest/me',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'my_interest' ),
					'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'express_interest' ),
					'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'withdraw_interest' ),
					'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				),
			)
		);
	}

	/**
	 * JSON body merged over query/body params.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function body( WP_REST_Request $request ) {
		$json = $request->get_json_params();
		return array_merge( $request->get_body_params(), is_array( $json ) ? $json : array() );
	}

	/**
	 * Idempotency key from header or body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array           $body    Body.
	 */
	private static function request_key( WP_REST_Request $request, array $body ) {
		$key = (string) $request->get_header( 'idempotency_key' );
		if ( '' === $key ) {
			$key = (string) ( $body['client_token'] ?? '' );
		}
		return preg_match( '/^[A-Za-z0-9_\-]{8,64}$/', $key ) ? $key : '';
	}

	/**
	 * @param mixed $result Service result.
	 */
	private static function respond( $result ) {
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	private static function uid() {
		return get_current_user_id();
	}

	private static function tour_id( WP_REST_Request $request ) {
		return absint( $request['id'] );
	}

	/**
	 * Fields from a stop row, accepting state_code as an alias for the US state.
	 *
	 * @param array $data Raw.
	 */
	private static function stop_fields( array $data ) {
		if ( isset( $data['state_code'] ) && ! isset( $data['state'] ) ) {
			$data['state'] = $data['state_code'];
		}
		return $data;
	}

	public static function overview() {
		$user_id = self::uid();
		return rest_ensure_response(
			array(
				'tours'     => LNKup_Portfolio_Tours::list_for_organizer( $user_id, array( 'status' => 'all' ) ),
				'brands'    => LNKup_Portfolio_Brands::list_for_organizer( $user_id ),
				'templates' => LNKup_Portfolio_Templates::list_for_organizer( $user_id ),
				'timezones' => array_values( array_unique( array_merge( DateTimeZone::listIdentifiers( DateTimeZone::AMERICA ), array( 'Pacific/Honolulu', wp_timezone_string() ) ) ) ),
				'site_timezone' => wp_timezone_string(),
				'max_stops' => LNKup_Tours::MAX_STOPS,
				'multi_apply_enabled' => class_exists( 'LNKup_Application_Groups' ) && LNKup_Application_Groups::enabled(),
			)
		);
	}

	public static function list_tours( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'items' => LNKup_Portfolio_Tours::list_for_organizer(
					self::uid(),
					array(
						'type'     => (string) $request->get_param( 'type' ),
						'brand_id' => absint( $request->get_param( 'brand_id' ) ),
						'status'   => (string) ( $request->get_param( 'status' ) ?: 'active' ),
					)
				),
			)
		);
	}

	public static function create_tour( WP_REST_Request $request ) {
		$body = self::body( $request );
		$tour = LNKup_Portfolio_Tours::create( self::uid(), $body );
		if ( is_wp_error( $tour ) ) {
			return $tour;
		}
		if ( ! empty( $body['template_id'] ) && LNKup_Portfolio_Templates::owns( absint( $body['template_id'] ), self::uid() ) ) {
			LNKup_Portfolio_Templates::apply_to_tour( absint( $body['template_id'] ), (int) $tour['id'], self::uid() );
			$tour = LNKup_Portfolio_Tours::detail( (int) $tour['id'] );
		}
		$response = rest_ensure_response( $tour );
		$response->set_status( 201 );
		return $response;
	}

	public static function get_tour( WP_REST_Request $request ) {
		return self::respond( LNKup_Portfolio_Tours::detail( self::tour_id( $request ) ) );
	}

	public static function update_tour( WP_REST_Request $request ) {
		$body = array_intersect_key( self::body( $request ), array_flip( array( 'title', 'description', 'type', 'brand_id', 'season', 'category', 'eligibility', 'website', 'cover_image_id' ) ) );
		return self::respond( LNKup_Portfolio_Tours::update( self::tour_id( $request ), self::uid(), $body ) );
	}

	public static function delete_tour( WP_REST_Request $request ) {
		return self::respond( LNKup_Portfolio_Tours::delete_tour( self::tour_id( $request ), self::uid() ) );
	}

	public static function archive_tour( WP_REST_Request $request ) {
		$body = self::body( $request );
		return self::respond( LNKup_Portfolio_Tours::set_archived( self::tour_id( $request ), ! array_key_exists( 'archived', $body ) || rest_sanitize_boolean( $body['archived'] ), self::uid() ) );
	}

	public static function cancel_stops( WP_REST_Request $request ) {
		$body = self::body( $request );
		$ids  = (array) ( $body['event_ids'] ?? array() );
		if ( ! $ids ) {
			return new WP_Error( 'lnkup_no_stops', __( 'Select the stops to cancel.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return self::respond( LNKup_Portfolio_Tours::cancel_stops( self::tour_id( $request ), $ids, (string) ( $body['note'] ?? '' ), self::uid() ) );
	}

	public static function add_stops( WP_REST_Request $request ) {
		$body = self::body( $request );
		$rows = array_map( array( __CLASS__, 'stop_fields' ), array_filter( (array) ( $body['stops'] ?? array() ), 'is_array' ) );
		if ( ! $rows ) {
			return new WP_Error( 'lnkup_no_stops', __( 'Add at least one stop.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return self::respond( LNKup_Portfolio_Jobs::add_stops( self::tour_id( $request ), array_values( $rows ), self::request_key( $request, $body ) ) );
	}

	public static function reorder_stops( WP_REST_Request $request ) {
		$body = self::body( $request );
		return self::respond( LNKup_Portfolio_Tours::reorder( self::tour_id( $request ), (array) ( $body['event_ids'] ?? array() ), self::uid() ) );
	}

	public static function update_stop( WP_REST_Request $request ) {
		return self::respond( LNKup_Portfolio_Tours::update_stop( self::tour_id( $request ), absint( $request['event_id'] ), self::stop_fields( self::body( $request ) ), self::uid() ) );
	}

	public static function delete_stop( WP_REST_Request $request ) {
		return self::respond( LNKup_Portfolio_Tours::delete_stop( self::tour_id( $request ), absint( $request['event_id'] ), self::uid() ) );
	}

	public static function detach_stop( WP_REST_Request $request ) {
		return self::respond( LNKup_Portfolio_Tours::detach_stop( self::tour_id( $request ), absint( $request['event_id'] ), self::uid() ) );
	}

	public static function duplicate_stop( WP_REST_Request $request ) {
		$body = self::body( $request );
		return self::respond( LNKup_Portfolio_Tours::duplicate_stop( self::tour_id( $request ), absint( $request['event_id'] ), self::request_key( $request, $body ), self::stop_fields( (array) ( $body['changes'] ?? array() ) ) ) );
	}

	public static function publish_stops( WP_REST_Request $request ) {
		$body = self::body( $request );
		$ids  = (array) ( $body['event_ids'] ?? array() );
		if ( ! $ids && empty( $body['all'] ) ) {
			return new WP_Error( 'lnkup_no_stops', __( 'Select the stops to publish.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return self::respond( LNKup_Portfolio_Tours::publish_stops( self::tour_id( $request ), $ids, self::uid() ) );
	}

	public static function recurrence_preview( WP_REST_Request $request ) {
		return self::respond( LNKup_Portfolio_Recurrence::preview( self::tour_id( $request ), (array) ( self::body( $request )['rule'] ?? array() ) ) );
	}

	public static function recurrence_generate( WP_REST_Request $request ) {
		$body = self::body( $request );
		return self::respond( LNKup_Portfolio_Recurrence::generate( self::tour_id( $request ), (array) ( $body['rule'] ?? array() ), self::request_key( $request, $body ) ) );
	}

	public static function recurrence_change_remaining( WP_REST_Request $request ) {
		$body = self::body( $request );
		return self::respond( LNKup_Portfolio_Recurrence::change_remaining( self::tour_id( $request ), (array) ( $body['rule'] ?? array() ), (string) ( $body['from_date'] ?? '' ), self::uid(), self::request_key( $request, $body ) ) );
	}

	public static function occurrence_skip( WP_REST_Request $request ) {
		$body = self::body( $request );
		return self::respond( LNKup_Portfolio_Recurrence::skip( self::tour_id( $request ), absint( $request['event_id'] ), self::uid(), ! empty( $body['undo'] ) ) );
	}

	public static function occurrence_move( WP_REST_Request $request ) {
		return self::respond( LNKup_Portfolio_Recurrence::move( self::tour_id( $request ), absint( $request['event_id'] ), self::stop_fields( (array) ( self::body( $request )['changes'] ?? array() ) ), self::uid() ) );
	}

	public static function get_defaults( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Portfolio_Config::get_defaults( self::tour_id( $request ) ) );
	}

	public static function save_defaults( WP_REST_Request $request ) {
		$body = self::body( $request );
		return self::respond( LNKup_Portfolio_Config::save_defaults( self::tour_id( $request ), (array) ( $body['defaults'] ?? $body ) ) );
	}

	public static function bulk_preview( WP_REST_Request $request ) {
		return self::respond( LNKup_Portfolio_Config::bulk_preview( self::tour_id( $request ), self::body( $request ) ) );
	}

	public static function bulk_apply( WP_REST_Request $request ) {
		return self::respond( LNKup_Portfolio_Config::bulk_apply( self::tour_id( $request ), self::body( $request ), self::uid() ) );
	}

	public static function summary( WP_REST_Request $request ) {
		$tour_id = self::tour_id( $request );
		$summary = LNKup_Portfolio_Reporting::summary( $tour_id );
		if ( ! LNKup_Capabilities::tour_staff_can( $tour_id, 'view_financials', self::uid() ) ) {
			unset( $summary['money'] );
			foreach ( $summary['stops'] as $i => $stop ) {
				unset( $summary['stops'][ $i ]['money'] );
			}
			$summary['money_hidden'] = true;
		}
		return rest_ensure_response( $summary );
	}

	public static function applications( WP_REST_Request $request ) {
		return rest_ensure_response(
			LNKup_Portfolio_Reporting::applications(
				self::tour_id( $request ),
				array(
					'event_id' => $request->get_param( 'event_id' ),
					'status'   => $request->get_param( 'status' ),
					'category' => $request->get_param( 'category' ),
					'payment'  => $request->get_param( 'payment' ),
					'page'     => $request->get_param( 'page' ),
				)
			)
		);
	}

	public static function applications_bulk( WP_REST_Request $request ) {
		$body = self::body( $request );
		return self::respond(
			LNKup_Portfolio_Reporting::bulk_action(
				self::tour_id( $request ),
				(array) ( $body['application_ids'] ?? array() ),
				sanitize_key( (string) ( $body['action'] ?? '' ) ),
				self::uid(),
				array( 'info_message' => sanitize_textarea_field( (string) ( $body['info_message'] ?? '' ) ) )
			)
		);
	}

	public static function interest_list( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => LNKup_Portfolio_Interest::list_for_tour( self::tour_id( $request ) ) ) );
	}

	public static function invite( WP_REST_Request $request ) {
		$body = self::body( $request );
		return self::respond( LNKup_Portfolio_Interest::invite( self::tour_id( $request ), (array) ( $body['vendor_user_ids'] ?? array() ), (array) ( $body['event_ids'] ?? array() ), (string) ( $body['message'] ?? '' ), self::uid() ) );
	}

	public static function audit( WP_REST_Request $request ) {
		$tour_id = self::tour_id( $request );
		return rest_ensure_response( array( 'items' => LNKup_Portfolio_Audit::for_tour( $tour_id, LNKup_Tours::stop_event_ids( $tour_id ) ) ) );
	}

	public static function save_as_template( WP_REST_Request $request ) {
		$body = self::body( $request );
		return self::respond( LNKup_Portfolio_Templates::create_from_tour( self::uid(), self::tour_id( $request ), (string) ( $body['name'] ?? '' ) ) );
	}

	public static function list_brands() {
		return rest_ensure_response( array( 'items' => LNKup_Portfolio_Brands::list_for_organizer( self::uid() ) ) );
	}

	public static function create_brand( WP_REST_Request $request ) {
		return self::respond( LNKup_Portfolio_Brands::save( self::uid(), self::body( $request ) ) );
	}

	public static function update_brand( WP_REST_Request $request ) {
		return self::respond( LNKup_Portfolio_Brands::save( self::uid(), self::body( $request ), absint( $request['brand_id'] ) ) );
	}

	public static function list_templates() {
		return rest_ensure_response( array( 'items' => LNKup_Portfolio_Templates::list_for_organizer( self::uid() ) ) );
	}

	public static function create_template( WP_REST_Request $request ) {
		$body = self::body( $request );
		return self::respond( LNKup_Portfolio_Templates::create( self::uid(), (string) ( $body['name'] ?? '' ), (array) ( $body['config'] ?? array() ) ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return int|WP_Error Owned template ID.
	 */
	private static function owned_template( WP_REST_Request $request ) {
		$template_id = absint( $request['template_id'] );
		if ( ! LNKup_Portfolio_Templates::owns( $template_id, self::uid() ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Template not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return $template_id;
	}

	public static function update_template( WP_REST_Request $request ) {
		$template_id = self::owned_template( $request );
		return is_wp_error( $template_id ) ? $template_id : self::respond( LNKup_Portfolio_Templates::update( $template_id, self::uid(), self::body( $request ) ) );
	}

	public static function delete_template( WP_REST_Request $request ) {
		$template_id = self::owned_template( $request );
		return is_wp_error( $template_id ) ? $template_id : self::respond( LNKup_Portfolio_Templates::delete( $template_id, self::uid() ) );
	}

	public static function duplicate_template( WP_REST_Request $request ) {
		$template_id = self::owned_template( $request );
		return is_wp_error( $template_id ) ? $template_id : self::respond( LNKup_Portfolio_Templates::duplicate( $template_id, self::uid(), (string) ( self::body( $request )['name'] ?? '' ) ) );
	}

	public static function apply_template( WP_REST_Request $request ) {
		$template_id = self::owned_template( $request );
		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}
		$tour_id = absint( self::body( $request )['tour_id'] ?? 0 );
		if ( ! LNKup_Capabilities::organizer_owns_tour( $tour_id, self::uid() ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Tour not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return self::respond( LNKup_Portfolio_Templates::apply_to_tour( $template_id, $tour_id, self::uid() ) );
	}

	public static function events( WP_REST_Request $request ) {
		return rest_ensure_response(
			LNKup_Portfolio_Tours::portfolio_events(
				self::uid(),
				array(
					'kind'     => $request->get_param( 'kind' ),
					'tour_id'  => $request->get_param( 'tour_id' ),
					'brand_id' => $request->get_param( 'brand_id' ),
					'when'     => $request->get_param( 'when' ),
					'page'     => $request->get_param( 'page' ),
					'per_page' => $request->get_param( 'per_page' ),
				)
			)
		);
	}

	public static function job( WP_REST_Request $request ) {
		return self::respond( LNKup_Portfolio_Jobs::get( absint( $request['job_id'] ), self::uid() ) );
	}

	public static function public_tour( WP_REST_Request $request ) {
		$tour_id = LNKup_Portfolio_Public::tour_id_from_slug( (string) $request['slug'] );
		if ( ! $tour_id ) {
			return new WP_Error( 'lnkup_not_found', __( 'Tour not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return self::respond( LNKup_Portfolio_Public::data( $tour_id, get_current_user_id() ) );
	}

	public static function my_interest( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Portfolio_Interest::status_for( self::tour_id( $request ), self::uid() ) );
	}

	public static function express_interest( WP_REST_Request $request ) {
		return self::respond( LNKup_Portfolio_Interest::express( self::tour_id( $request ), self::uid(), (string) ( self::body( $request )['note'] ?? '' ) ) );
	}

	public static function withdraw_interest( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_Portfolio_Interest::withdraw( self::tour_id( $request ), self::uid() ) );
	}
}
