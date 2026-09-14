<?php
/**
 * External events REST API.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Events_REST {

	const NS = 'lnkup/v1';

	/**
	 * Init routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes(): void {
		$public = array( 'permission_callback' => '__return_true' );
		$vendor = array( 'permission_callback' => array( __CLASS__, 'vendor_permission' ) );
		$org    = array( 'permission_callback' => array( __CLASS__, 'organizer_permission' ) );
		$admin  = array( 'permission_callback' => array( __CLASS__, 'admin_permission' ) );

		register_rest_route(
			self::NS,
			'/external-events/search',
			array_merge(
				$public,
				array(
					'methods'  => 'GET',
					'callback' => array( __CLASS__, 'search' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/(?P<id>\d+)',
			array_merge(
				$public,
				array(
					'methods'  => 'GET',
					'callback' => array( __CLASS__, 'get_item' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/(?P<id>\d+)/interest',
			array_merge(
				$vendor,
				array(
					'methods'  => 'POST',
					'callback' => array( __CLASS__, 'submit_interest' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/(?P<id>\d+)/claim',
			array_merge(
				$org,
				array(
					'methods'  => 'POST',
					'callback' => array( __CLASS__, 'claim' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/organizer/pending-interests',
			array_merge(
				$org,
				array(
					'methods'  => 'GET',
					'callback' => array( __CLASS__, 'pending_interests' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/organizer/import-url',
			array_merge(
				$org,
				array(
					'methods'  => 'POST',
					'callback' => array( __CLASS__, 'organizer_import_url' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/admin/import',
			array_merge(
				$admin,
				array(
					'methods'  => 'POST',
					'callback' => array( __CLASS__, 'admin_import' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/admin/hide',
			array_merge(
				$admin,
				array(
					'methods'  => 'POST',
					'callback' => array( __CLASS__, 'admin_hide' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/admin/unhide',
			array_merge(
				$admin,
				array(
					'methods'  => 'POST',
					'callback' => array( __CLASS__, 'admin_unhide' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/track/view',
			array_merge(
				$public,
				array(
					'methods'  => 'POST',
					'callback' => array( __CLASS__, 'track_view' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/dashboard',
			array_merge(
				$admin,
				array(
					'methods'  => 'GET',
					'callback' => array( __CLASS__, 'dashboard' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/admin/queue',
			array_merge(
				$admin,
				array(
					'methods'  => 'GET',
					'callback' => array( __CLASS__, 'admin_queue' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/admin/(?P<id>\d+)',
			array_merge(
				$admin,
				array(
					'methods'  => 'GET',
					'callback' => array( __CLASS__, 'admin_detail' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/(?P<id>\d+)/analytics',
			array_merge(
				$admin,
				array(
					'methods'  => 'GET',
					'callback' => array( __CLASS__, 'event_analytics' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/providers',
			array_merge(
				$admin,
				array(
					'methods'  => 'GET',
					'callback' => array( __CLASS__, 'providers' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/demand',
			array_merge(
				$public,
				array(
					'methods'  => 'GET',
					'callback' => array( __CLASS__, 'public_demand' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/(?P<id>\d+)/notify',
			array_merge(
				$admin,
				array(
					'methods'  => 'POST',
					'callback' => array( __CLASS__, 'manual_notify' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/(?P<id>\d+)/merge',
			array_merge(
				$admin,
				array(
					'methods'  => 'POST',
					'callback' => array( __CLASS__, 'merge' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/import-url',
			array_merge(
				$admin,
				array(
					'methods'  => 'POST',
					'callback' => array( __CLASS__, 'import_url' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/provider-sync',
			array_merge(
				$admin,
				array(
					'methods'  => 'POST',
					'callback' => array( __CLASS__, 'provider_sync' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/(?P<id>\d+)/timeline',
			array_merge(
				$admin,
				array(
					'methods'  => 'GET',
					'callback' => array( __CLASS__, 'timeline' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/(?P<id>\d+)/recommendations',
			array_merge(
				$public,
				array(
					'methods'  => 'GET',
					'callback' => array( __CLASS__, 'recommendations' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/(?P<id>\d+)/publish',
			array_merge(
				$admin,
				array(
					'methods'  => 'POST',
					'callback' => array( __CLASS__, 'publish' ),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/external-events/(?P<id>\d+)/reject',
			array_merge(
				$admin,
				array(
					'methods'  => 'POST',
					'callback' => array( __CLASS__, 'reject' ),
				)
			)
		);
	}

	public static function vendor_permission(): bool {
		return is_user_logged_in() && ( LNKup_Roles::is_vendor() || LNKup_Capabilities::user_has_manage_all() );
	}

	public static function organizer_permission(): bool {
		return is_user_logged_in() && ( LNKup_Roles::is_organizer() || LNKup_Capabilities::user_has_manage_all() );
	}

	public static function admin_permission(): bool {
		return LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function search( WP_REST_Request $request ) {
		$args   = self::parse_args( $request );
		$result = LNKup_External_Events::search( $args );
		$result['html'] = lnkup_render_external_event_cards_batch( $result['items'] );
		return rest_ensure_response( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function get_item( WP_REST_Request $request ) {
		$id  = absint( $request['id'] );
		$row = LNKup_External_Events::get_public( $id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'External event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $row );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function submit_interest( WP_REST_Request $request ) {
		$id     = absint( $request['id'] );
		$params = $request->get_json_params() ?: array();
		$result = LNKup_External_Events::submit_interest( $id, get_current_user_id(), $params );
		if ( is_wp_error( $result ) ) {
			$status = 'duplicate' === $result->get_error_code() ? 409 : 400;
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}
		$message_key = $result['message_key'] ?? 'request_interest_success';
		$result['message'] = lnkup_copy( $message_key );
		return rest_ensure_response( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function claim( WP_REST_Request $request ) {
		$id     = absint( $request['id'] );
		$params = $request->get_json_params() ?: array();
		$token  = sanitize_text_field( (string) ( $params['token'] ?? '' ) );
		if ( '' === $token ) {
			$pending = get_user_meta( get_current_user_id(), '_lnkup_pending_external_claim', true );
			if ( is_array( $pending ) && ! empty( $pending['token'] ) ) {
				$token = (string) $pending['token'];
			}
		}
		if ( '' === $token ) {
			return new WP_Error( 'missing_token', __( 'Claim token is required.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$result = LNKup_External_Events::claim( $id, get_current_user_id(), $token );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		$result['message'] = lnkup_copy( 'organizer_claim_success' );
		return rest_ensure_response( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function pending_interests( WP_REST_Request $request ) {
		$items = LNKup_External_Events::pending_interests_for_organizer( get_current_user_id() );
		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function admin_import( WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: array();
		if ( ! empty( $params['csv'] ) ) {
			$result = LNKup_External_Events_Import::import_csv( (string) $params['csv'] );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
			}
			return rest_ensure_response( $result );
		}
		if ( ! empty( $params['preview_csv'] ) ) {
			$preview = LNKup_External_Events_Import::preview_csv( (string) $params['preview_csv'] );
			if ( is_wp_error( $preview ) ) {
				return new WP_Error( $preview->get_error_code(), $preview->get_error_message(), array( 'status' => 400 ) );
			}
			return rest_ensure_response( $preview );
		}
		$id = LNKup_External_Events::create( $params );
		if ( is_wp_error( $id ) ) {
			return new WP_Error( $id->get_error_code(), $id->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'id' => $id ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function admin_hide( WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: array();
		$id     = absint( $params['id'] ?? 0 );
		$result = LNKup_External_Events::hide( $id );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function admin_unhide( WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: array();
		$id     = absint( $params['id'] ?? 0 );
		$result = LNKup_External_Events::unhide( $id );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function track_view( WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: array();
		$id     = absint( $params['external_event_id'] ?? 0 );
		if ( $id ) {
			LNKup_External_Events::track( 'external_event_view', get_current_user_id(), $id );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	protected static function parse_args( WP_REST_Request $request ): array {
		return array(
			'q'               => sanitize_text_field( (string) $request->get_param( 'q' ) ),
			'city'            => sanitize_text_field( (string) $request->get_param( 'city' ) ),
			'state'           => sanitize_text_field( (string) $request->get_param( 'state' ) ),
			'event_type'      => sanitize_text_field( (string) $request->get_param( 'event_type' ) ),
			'platform'        => sanitize_key( (string) $request->get_param( 'platform' ) ),
			'page'            => absint( $request->get_param( 'page' ) ?: 1 ),
			'per_page'        => absint( $request->get_param( 'per_page' ) ?: 24 ),
			'date_from'       => sanitize_text_field( (string) $request->get_param( 'date_from' ) ),
			'date_to'         => sanitize_text_field( (string) $request->get_param( 'date_to' ) ),
			'date_preset'     => sanitize_key( (string) $request->get_param( 'date_preset' ) ),
			'high_demand'     => rest_sanitize_boolean( $request->get_param( 'high_demand' ) ),
			'external_sort'   => sanitize_key( (string) $request->get_param( 'external_sort' ) ),
			'include_external'=> $request->get_param( 'include_external' ),
		);
	}

	public static function dashboard( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_External_Events_Analytics::dashboard() );
	}

	/**
	 * Admin review queue (pending_review by default).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function admin_queue( WP_REST_Request $request ) {
		$status = sanitize_key( (string) ( $request->get_param( 'workflow_status' ) ?: LNKup_External_Events::WORKFLOW_PENDING_REVIEW ) );
		$rows   = LNKup_External_Events::list_admin(
			array(
				'workflow_status' => $status,
				'order_by'        => 'created_at',
				'order'           => 'DESC',
			)
		);
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::format_admin_queue_item( $row );
		}
		return rest_ensure_response(
			array(
				'items'  => $items,
				'total'  => count( $items ),
				'status' => $status,
			)
		);
	}

	/**
	 * Admin detail for one external event (includes unpublished).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_detail( WP_REST_Request $request ) {
		$id  = absint( $request['id'] );
		$row = LNKup_External_Events::get_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'External event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$item      = self::format_admin_queue_item( $row );
		$analytics = LNKup_External_Events_Analytics::for_event( $id );
		return rest_ensure_response(
			array(
				'item'      => $item,
				'analytics' => $analytics ? $analytics : null,
			)
		);
	}

	/**
	 * @param array<string, mixed> $row DB row.
	 * @return array<string, mixed>
	 */
	private static function format_admin_queue_item( array $row ): array {
		return array(
			'id'                    => (int) ( $row['id'] ?? 0 ),
			'title'                 => (string) ( $row['title'] ?? '' ),
			'city'                  => (string) ( $row['city'] ?? '' ),
			'state'                 => (string) ( $row['state'] ?? '' ),
			'source_platform'       => (string) ( $row['source_platform'] ?? '' ),
			'event_type'            => (string) ( $row['event_type'] ?? '' ),
			'workflow_status'       => (string) ( $row['workflow_status'] ?? '' ),
			'claim_status'          => (string) ( $row['claim_status'] ?? '' ),
			'start_datetime'        => (string) ( $row['start_datetime'] ?? '' ),
			'vendor_interest_count' => (int) ( $row['vendor_interest_count'] ?? 0 ),
			'vendor_fit_score'      => (int) ( $row['vendor_fit_score'] ?? 0 ),
			'organizer_email'       => (string) ( $row['organizer_email'] ?? '' ),
			'application_url'       => (string) ( $row['application_url'] ?? '' ),
			'organizer_instagram'   => (string) ( $row['organizer_instagram'] ?? '' ),
			'discovery_query'       => (string) ( $row['discovery_query'] ?? '' ),
			'source_url'            => (string) ( $row['source_url'] ?? '' ),
			'created_at'            => (string) ( $row['created_at'] ?? '' ),
			'updated_at'            => (string) ( $row['updated_at'] ?? '' ),
		);
	}

	public static function event_analytics( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$data = LNKup_External_Events_Analytics::for_event( $id );
		if ( empty( $data ) ) {
			return new WP_Error( 'not_found', __( 'External event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $data );
	}

	public static function providers( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'providers' => LNKup_External_Sources::list_for_admin() ) );
	}

	public static function public_demand( WP_REST_Request $request ) {
		return rest_ensure_response( LNKup_External_Events_Analytics::public_demand_stats() );
	}

	public static function manual_notify( WP_REST_Request $request ) {
		$id    = absint( $request['id'] );
		$event = LNKup_External_Events::get_row( $id );
		if ( ! $event ) {
			return new WP_Error( 'not_found', __( 'External event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$stage = max( 1, min( 4, absint( $request->get_param( 'stage' ) ?: 1 ) ) );
		$sent  = LNKup_External_Events_Followup::send_stage( $id, $stage );
		if ( ! $sent ) {
			return new WP_Error( 'send_failed', __( 'Could not send notification.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'stage' => $stage ) );
	}

	public static function merge( WP_REST_Request $request ) {
		$id     = absint( $request['id'] );
		$params = $request->get_json_params() ?: array();
		$target = absint( $params['target_id'] ?? 0 );
		if ( ! $target ) {
			return new WP_Error( 'missing_target', __( 'Target event ID is required.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$result = LNKup_External_Events_Dedup::merge( $id, $target );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'target_id' => $target ) );
	}

	public static function import_url( WP_REST_Request $request ) {
		$params  = $request->get_json_params() ?: array();
		$url     = esc_url_raw( (string) ( $params['url'] ?? '' ) );
		$preview = ! empty( $params['preview'] );

		$provider = LNKup_External_Sources::detect_from_url( $url );
		if ( ! $provider ) {
			return new WP_Error( 'unsupported', __( 'URL provider not supported. Use Eventbrite, Posh, or an RSS/Atom feed URL.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$data = $provider->parse_url( $url );
		if ( is_wp_error( $data ) ) {
			return new WP_Error( $data->get_error_code(), $data->get_error_message(), array( 'status' => 400 ) );
		}

		if ( $preview ) {
			return rest_ensure_response( array( 'preview' => $data ) );
		}

		unset( $data['_preview_confidence'], $data['_feed_url'], $data['_category_hint'] );
		$id = LNKup_External_Events::create( $data );
		if ( is_wp_error( $id ) ) {
			return new WP_Error( $id->get_error_code(), $id->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'id' => $id, 'workflow_status' => LNKup_External_Events::WORKFLOW_PENDING_REVIEW ) );
	}

	/**
	 * Organizer Create Event import — preview fields only (does not create admin review rows).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function organizer_import_url( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$lock    = 'lnkup_org_import_' . $user_id;
		if ( get_transient( $lock ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Please wait a few seconds before importing again.', 'lnkup-marketplace' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $lock, 1, 3 );

		$params = $request->get_json_params() ?: array();
		$url    = esc_url_raw( (string) ( $params['url'] ?? '' ) );
		if ( '' === $url ) {
			return new WP_Error( 'missing_url', __( 'URL is required.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$provider = LNKup_External_Sources::detect_from_url( $url );
		if ( ! $provider || ! $provider->supports_url_import() ) {
			return new WP_Error(
				'unsupported',
				__( 'Unsupported URL. Paste an Eventbrite event link, Posh event link (/e/…), or an RSS/Atom feed URL.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}

		$data = $provider->parse_url( $url );
		if ( is_wp_error( $data ) ) {
			return new WP_Error( $data->get_error_code(), $data->get_error_message(), array( 'status' => 400 ) );
		}

		$platform = sanitize_key( (string) ( $data['source_platform'] ?? $provider->slug() ) );
		$label_map = array(
			'eventbrite' => 'Eventbrite',
			'posh'       => 'Posh',
			'rss'        => 'RSS',
		);

		return rest_ensure_response(
			array(
				'preview'           => true,
				'source_platform'   => $platform,
				'ticketing_platform'=> $label_map[ $platform ] ?? ucfirst( $platform ),
				'source_url'        => (string) ( $data['source_url'] ?? $url ),
				'title'             => (string) ( $data['title'] ?? '' ),
				'description'       => (string) ( $data['description'] ?? $data['description_excerpt'] ?? '' ),
				'image_url'         => (string) ( $data['image_url'] ?? '' ),
				'start_datetime'    => $data['start_datetime'] ?? null,
				'end_datetime'      => $data['end_datetime'] ?? null,
				'city'              => (string) ( $data['city'] ?? '' ),
				'state'             => (string) ( $data['state'] ?? '' ),
				'venue_name'        => (string) ( $data['venue_name'] ?? '' ),
				'organizer_name'    => (string) ( $data['organizer_name'] ?? '' ),
				'_preview_confidence' => $data['_preview_confidence'] ?? array(),
			)
		);
	}

	public static function provider_sync( WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: array();
		if ( ! empty( $params['url'] ) ) {
			$request->set_param( 'preview', empty( $params['save'] ) );
			return self::import_url( $request );
		}

		if ( ! class_exists( 'LNKup_External_Events_Sync' ) ) {
			return new WP_Error( 'unavailable', __( 'Auto sync is unavailable.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}

		$mode = sanitize_key( (string) ( $params['mode'] ?? LNKup_External_Events_Sync::MODE_FULL ) );
		if ( ! in_array( $mode, array( LNKup_External_Events_Sync::MODE_FULL, LNKup_External_Events_Sync::MODE_DISCOVERY, LNKup_External_Events_Sync::MODE_REFRESH, LNKup_External_Events_Sync::MODE_GOOGLE ), true ) ) {
			$mode = LNKup_External_Events_Sync::MODE_FULL;
		}
		$market = sanitize_key( (string) ( $params['market'] ?? '' ) );

		if ( LNKup_External_Events_Sync::MODE_GOOGLE === $mode ) {
			$summary = LNKup_External_Events_Sync::run( $mode, $market );
		} elseif ( LNKup_External_Events_Sync::MODE_REFRESH === $mode ) {
			$summary = LNKup_External_Events_Sync::run( $mode );
		} elseif ( '' !== $market ) {
			$discovery = LNKup_External_Events_Sync::run_discovery_batch( LNKup_External_Events_Sync::get_config(), $market );
			$summary   = array_merge(
				array(
					'mode'       => $mode,
					'started_at' => current_time( 'mysql' ),
					'discovered' => 0,
					'imported'   => 0,
					'refreshed'  => 0,
					'skipped'    => 0,
					'errors'     => 0,
				),
				$discovery
			);
			if ( LNKup_External_Events_Sync::MODE_FULL === $mode ) {
				$refresh = LNKup_External_Events_Sync::run_refresh_batch( LNKup_External_Events_Sync::get_config() );
				foreach ( array( 'refreshed', 'skipped', 'errors' ) as $key ) {
					$summary[ $key ] = (int) ( $summary[ $key ] ?? 0 ) + (int) ( $refresh[ $key ] ?? 0 );
				}
			}
			LNKup_External_Events_Sync::record_run( $summary );
		} else {
			$summary = LNKup_External_Events_Sync::run( $mode );
		}

		return rest_ensure_response( $summary );
	}

	public static function timeline( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		if ( ! LNKup_External_Events::get_row( $id ) ) {
			return new WP_Error( 'not_found', __( 'External event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'items' => LNKup_External_Events_Timeline::get_for_event( $id ) ) );
	}

	public static function recommendations( WP_REST_Request $request ) {
		$id   = absint( $request['id'] );
		$cats = LNKup_External_Events_Recommendations::for_external_id( $id );
		return rest_ensure_response( array( 'categories' => $cats ) );
	}

	public static function publish( WP_REST_Request $request ) {
		$id     = absint( $request['id'] );
		$result = LNKup_External_Events::publish( $id );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function reject( WP_REST_Request $request ) {
		$id     = absint( $request['id'] );
		$result = LNKup_External_Events::reject( $id );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}
}
