<?php
/**
 * REST: lnkup/v1/organizer/import — preview a listing, start a draft from it, fill an existing
 * draft, or link the listing to an event the organizer already runs on LNKup.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Import_REST {

	const NS   = 'lnkup/v1';
	const BASE = '/organizer/import';

	const FIELDS = array( 'name', 'date', 'end_date', 'start_time', 'end_time', 'timezone', 'venue_name', 'address', 'city', 'state', 'zip', 'description', 'image_url' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function can_import(): bool {
		return LNKup_Event_Builder_REST::can_use() && LNKup_Event_OS_Flags::enabled( 'event_import_v3' );
	}

	public static function can_import_draft( WP_REST_Request $request ) {
		if ( ! self::can_import() ) {
			return false;
		}
		return LNKup_Event_Builder_REST::can_use_draft( $request );
	}

	public static function routes(): void {
		$base = array( 'permission_callback' => array( __CLASS__, 'can_import' ) );
		register_rest_route( self::NS, self::BASE . '/providers', array_merge( $base, array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'providers' ) ) ) );
		register_rest_route( self::NS, self::BASE . '/preview', array_merge( $base, array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'preview' ) ) ) );
		register_rest_route( self::NS, self::BASE . '/draft', array_merge( $base, array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_draft' ) ) ) );
		register_rest_route( self::NS, self::BASE . '/associate', array_merge( $base, array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'associate' ) ) ) );
		register_rest_route(
			self::NS,
			self::BASE . '/drafts/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'import_into_draft' ),
				'permission_callback' => array( __CLASS__, 'can_import_draft' ),
			)
		);
		self::bulk_routes();
	}

	public static function can_bulk(): bool {
		return self::can_import() && LNKup_Event_OS_Flags::enabled( 'event_import_bulk' );
	}

	public static function can_job( WP_REST_Request $request ): bool {
		return self::can_bulk() && LNKup_Import_Jobs::owns( (int) $request['id'], get_current_user_id() );
	}

	public static function bulk_routes(): void {
		$bulk = array( 'permission_callback' => array( __CLASS__, 'can_bulk' ) );
		$job  = array( 'permission_callback' => array( __CLASS__, 'can_job' ) );
		register_rest_route( self::NS, self::BASE . '/bulk/csv/parse', array_merge( $bulk, array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'csv_parse' ) ) ) );
		register_rest_route(
			self::NS,
			self::BASE . '/bulk/jobs',
			array(
				array_merge( $bulk, array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_jobs' ) ) ),
				array_merge( $bulk, array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_job' ) ) ),
			)
		);
		register_rest_route( self::NS, self::BASE . '/bulk/jobs/(?P<id>\d+)', array_merge( $job, array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_job' ) ) ) );
		foreach ( array( 'apply', 'retry', 'cancel' ) as $action ) {
			register_rest_route( self::NS, self::BASE . '/bulk/jobs/(?P<id>\d+)/' . $action, array_merge( $job, array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'job_' . $action ) ) ) );
		}
		register_rest_route( self::NS, self::BASE . '/eventbrite', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'eventbrite_status' ), 'permission_callback' => array( __CLASS__, 'can_import' ) ) );
		register_rest_route( self::NS, self::BASE . '/eventbrite/events', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'eventbrite_events' ), 'permission_callback' => array( __CLASS__, 'can_import' ) ) );
		register_rest_route( self::NS, self::BASE . '/eventbrite/disconnect', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'eventbrite_disconnect' ), 'permission_callback' => array( __CLASS__, 'can_import' ) ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function csv_parse( WP_REST_Request $request ) {
		$parsed = LNKup_Import_Jobs::parse_csv( (string) ( self::body( $request )['csv'] ?? '' ) );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		return rest_ensure_response( array( 'headers' => $parsed['headers'], 'sample' => array_slice( $parsed['rows'], 0, 5 ), 'total' => count( $parsed['rows'] ), 'suggested' => (object) $parsed['suggested'], 'fields' => LNKup_Import_Jobs::CSV_FIELDS, 'max' => LNKup_Import_Jobs::MAX_ROWS ) );
	}

	/**
	 * Body by method: urls {urls[]} · csv {csv, mapping{col:field}, file_name} · ics {ics, file_name, include_past}
	 * · eventbrite {ids[]}.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_job( WP_REST_Request $request ) {
		$body   = self::body( $request );
		$user   = get_current_user_id();
		$method = sanitize_key( (string) ( $body['method'] ?? '' ) );
		$file   = (string) ( $body['file_name'] ?? '' );
		switch ( $method ) {
			case 'urls':
				$raw    = is_array( $body['urls'] ?? null ) ? $body['urls'] : preg_split( '/[\s,]+/', (string) ( $body['urls'] ?? '' ) );
				$inputs = array();
				foreach ( (array) $raw as $u ) {
					$u = esc_url_raw( trim( (string) $u ), array( 'http', 'https' ) );
					if ( '' !== $u ) {
						$inputs[] = array( 'url' => $u );
					}
				}
				break;
			case 'csv':
				$parsed = LNKup_Import_Jobs::parse_csv( (string) ( $body['csv'] ?? '' ) );
				if ( is_wp_error( $parsed ) ) {
					return $parsed;
				}
				$mapping = (array) ( $body['mapping'] ?? $parsed['suggested'] );
				if ( ! in_array( 'name', $mapping, true ) ) {
					return new WP_Error( 'lnkup_import_mapping', __( 'Choose which column holds the event name.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
				}
				$inputs = LNKup_Import_Jobs::rows_to_inputs( $parsed['rows'], $mapping );
				break;
			case 'ics':
				$inputs = LNKup_Import_Jobs::parse_ics( (string) ( $body['ics'] ?? '' ), empty( $body['include_past'] ) );
				if ( is_wp_error( $inputs ) ) {
					return $inputs;
				}
				if ( ! $inputs ) {
					return new WP_Error( 'lnkup_import_empty', __( 'No upcoming events were found in that calendar file.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
				}
				break;
			case 'eventbrite':
				if ( ! LNKup_Eventbrite_Connection::available() ) {
					return new WP_Error( 'lnkup_eventbrite_unavailable', __( 'Eventbrite account import is not available.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
				}
				$inputs = array_map( static fn( $id ) => array( 'id' => preg_replace( '/[^0-9]/', '', (string) $id ) ), array_slice( (array) ( $body['ids'] ?? array() ), 0, LNKup_Import_Jobs::MAX_ROWS ) );
				break;
			default:
				return new WP_Error( 'lnkup_import_method', __( 'Unsupported import type.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$job = LNKup_Import_Jobs::create( $user, $method, $inputs, array(), $file );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		LNKup_Import_Jobs::process( (int) $job['id'] );
		$response = rest_ensure_response( LNKup_Import_Jobs::get( (int) $job['id'] ) );
		$response->set_status( 201 );
		return $response;
	}

	public static function list_jobs(): WP_REST_Response {
		return rest_ensure_response( array( 'jobs' => LNKup_Import_Jobs::list_for( get_current_user_id() ) ) );
	}

	public static function get_job( WP_REST_Request $request ): WP_REST_Response {
		$id  = (int) $request['id'];
		$job = LNKup_Import_Jobs::get( $id, false );
		if ( $job && in_array( $job['status'], array( 'queued', 'processing' ), true ) ) {
			LNKup_Import_Jobs::process( $id );
		}
		return rest_ensure_response( LNKup_Import_Jobs::get( $id ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function job_apply( WP_REST_Request $request ) {
		$decisions = array();
		foreach ( (array) ( self::body( $request )['decisions'] ?? array() ) as $item => $choice ) {
			$choice = (string) $choice;
			if ( in_array( $choice, array( 'draft', 'skip' ), true ) || preg_match( '/^link:\d+$/', $choice ) ) {
				$decisions[ (int) $item ] = $choice;
			}
		}
		$out = LNKup_Import_Jobs::apply( (int) $request['id'], get_current_user_id(), $decisions );
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	public static function job_retry( WP_REST_Request $request ): WP_REST_Response {
		LNKup_Import_Jobs::retry( (int) $request['id'] );
		LNKup_Import_Jobs::process( (int) $request['id'] );
		return rest_ensure_response( LNKup_Import_Jobs::get( (int) $request['id'] ) );
	}

	public static function job_cancel( WP_REST_Request $request ): WP_REST_Response {
		LNKup_Import_Jobs::cancel( (int) $request['id'] );
		return rest_ensure_response( LNKup_Import_Jobs::get( (int) $request['id'] ) );
	}

	public static function eventbrite_status(): WP_REST_Response {
		$user   = get_current_user_id();
		$status = LNKup_Eventbrite_Connection::status( $user );
		if ( $status['available'] && ! $status['connected'] ) {
			$url = LNKup_Eventbrite_Connection::connect_url( $user );
			$status['connect_url'] = is_wp_error( $url ) ? '' : $url;
		}
		return rest_ensure_response( $status );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function eventbrite_events( WP_REST_Request $request ) {
		if ( ! LNKup_Eventbrite_Connection::available() ) {
			return new WP_Error( 'lnkup_eventbrite_unavailable', __( 'Eventbrite account import is not available yet. Paste event links instead.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$user   = get_current_user_id();
		$events = LNKup_Eventbrite_Connection::events( $user, ! empty( $request['refresh'] ) );
		if ( is_wp_error( $events ) ) {
			return $events;
		}
		foreach ( $events as &$ev ) {
			$ev['relation'] = LNKup_Event_Sources::relation( (string) $ev['url'], $user )['state'];
		}
		unset( $ev );
		return rest_ensure_response( array( 'events' => $events ) );
	}

	public static function eventbrite_disconnect(): WP_REST_Response {
		LNKup_Eventbrite_Connection::disconnect( get_current_user_id() );
		return rest_ensure_response( array( 'disconnected' => true ) );
	}

	public static function providers(): WP_REST_Response {
		return rest_ensure_response( array( 'providers' => LNKup_Import_Provider_Registry::public_list() ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function preview( WP_REST_Request $request ) {
		$body    = self::body( $request );
		$user    = get_current_user_id();
		$preview = LNKup_Event_Import_Service::preview( (string) ( $body['url'] ?? '' ), $user, empty( $body['refresh'] ) );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		self::track( 'import_previewed', (string) $preview['provider']['key'], 0, $user );
		return rest_ensure_response( self::present( $preview ) );
	}

	/**
	 * New draft from a listing. Body: { url, keep?: string[], use_image?: bool, quick?: bool }.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_draft( WP_REST_Request $request ) {
		$body    = self::body( $request );
		$user    = get_current_user_id();
		$preview = LNKup_Event_Import_Service::preview( (string) ( $body['url'] ?? '' ), $user );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$keep = isset( $body['keep'] ) && is_array( $body['keep'] ) ? array_values( array_intersect( self::FIELDS, array_map( 'sanitize_key', $body['keep'] ) ) ) : null;
		$occ  = isset( $body['occurrence'] ) ? (int) $body['occurrence'] : -1;
		if ( $occ >= 0 && isset( $preview['occurrences'][ $occ ] ) ) {
			unset( $preview['fields']['end_date'] );
			foreach ( array( 'date', 'start_time', 'end_time', 'venue_name', 'city', 'state' ) as $k ) {
				if ( '' !== (string) $preview['occurrences'][ $occ ][ $k ] ) {
					$preview['fields'][ $k ] = $preview['occurrences'][ $occ ][ $k ];
				}
			}
		}
		$patch = LNKup_Event_Import_Service::to_state_patch( $preview, $keep );
		$state = array_merge( $patch, array( 'method' => 'import', 'quick' => ! empty( $body['quick'] ) ) );

		$warnings = array();
		$image    = (string) ( $preview['fields']['image_url'] ?? '' );
		if ( ! empty( $body['use_image'] ) && '' !== $image && ( null === $keep || in_array( 'image_url', $keep, true ) ) ) {
			$att = LNKup_Event_Import_Service::sideload_image( $image, $user );
			if ( is_wp_error( $att ) ) {
				$warnings[] = __( 'We could not copy the event image. Upload one in the builder.', 'lnkup-marketplace' );
			} else {
				$state['event']['featured_image_id']        = $att;
				$state['provenance']['event.featured_image_id'] = 'imported';
			}
		}
		$draft = LNKup_Event_Builder_Drafts::create( $user, $state );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		LNKup_Event_Builder_Publisher::track( 'event_imported', $draft['state'], 0, $user );
		$out             = LNKup_Event_Builder_REST::draft_payload( $draft );
		$out['warnings'] = array_merge( $preview['warnings'], $warnings );
		$response        = rest_ensure_response( $out );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Fill an existing draft. Only empty fields are filled unless the organizer lists a field in
	 * `replace`. Body: { url, keep?: string[], replace?: string[], revision, use_image?: bool }.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function import_into_draft( WP_REST_Request $request ) {
		$body  = self::body( $request );
		$user  = get_current_user_id();
		$draft = LNKup_Event_Builder_Drafts::get( (int) $request['id'] );
		if ( ! $draft ) {
			return new WP_Error( 'lnkup_builder_missing', __( 'This draft no longer exists.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$preview = LNKup_Event_Import_Service::preview( (string) ( $body['url'] ?? '' ), $user );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$keep    = isset( $body['keep'] ) && is_array( $body['keep'] ) ? array_values( array_intersect( self::FIELDS, array_map( 'sanitize_key', $body['keep'] ) ) ) : self::FIELDS;
		$replace = array_map( 'sanitize_key', (array) ( $body['replace'] ?? array() ) );
		$patch   = LNKup_Event_Import_Service::to_state_patch( $preview, $keep );
		$current = $draft['state']['event'];
		$skipped = array();
		foreach ( array_keys( $patch['event'] ) as $key ) {
			if ( 'location_confirmed' === $key ) {
				continue;
			}
			$field = array_search( $key, array( 'address' => 'venue_address', 'zip' => 'postal_code' ), true ) ?: $key;
			$has   = isset( $current[ $key ] ) && '' !== (string) $current[ $key ] && $current[ $key ] !== $patch['event'][ $key ];
			if ( $has && ! in_array( (string) $field, $replace, true ) ) {
				unset( $patch['event'][ $key ], $patch['provenance'][ 'event.' . $key ] );
				$skipped[] = $field;
			}
		}
		if ( ! array_intersect( array( 'city', 'state' ), array_keys( $patch['event'] ) ) ) {
			unset( $patch['event']['location_confirmed'] );
		}
		$sources = $draft['state']['sources'];
		$known   = array_map( array( 'LNKup_Event_Sources', 'url_hash' ), wp_list_pluck( $sources, 'url' ) );
		if ( ! in_array( LNKup_Event_Sources::url_hash( (string) $preview['url'] ), $known, true ) ) {
			$new            = $patch['sources'][0];
			$new['primary'] = ! $sources;
			$sources[]      = $new;
		}
		$patch['sources']    = $sources;
		$patch['provenance'] = array_merge( $draft['state']['provenance'], $patch['provenance'] );
		if ( ! empty( $body['use_image'] ) && empty( $current['featured_image_id'] ) && ! empty( $preview['fields']['image_url'] ) && in_array( 'image_url', $keep, true ) ) {
			$att = LNKup_Event_Import_Service::sideload_image( (string) $preview['fields']['image_url'], $user );
			if ( ! is_wp_error( $att ) ) {
				$patch['event']['featured_image_id']            = $att;
				$patch['provenance']['event.featured_image_id'] = 'imported';
			}
		}
		$saved = LNKup_Event_Builder_Drafts::save( (int) $draft['id'], $patch, (int) ( $body['revision'] ?? $draft['revision'] ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$out             = LNKup_Event_Builder_REST::draft_payload( $saved );
		$out['skipped']  = $skipped;
		$out['warnings'] = $preview['warnings'];
		return rest_ensure_response( $out );
	}

	/**
	 * Link a listing to an event the organizer already runs on LNKup (no new event).
	 * Body: { url, event_id }.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function associate( WP_REST_Request $request ) {
		$body     = self::body( $request );
		$user     = get_current_user_id();
		$event_id = absint( $body['event_id'] ?? 0 );
		$url      = esc_url_raw( trim( (string) ( $body['url'] ?? '' ) ), array( 'http', 'https' ) );
		if ( ! $event_id || ! LNKup_Event_Builder_Editor::can_edit_event( $event_id, $user ) ) {
			return new WP_Error( 'lnkup_import_forbidden', __( 'You can only link listings to your own events.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( '' === $url || is_wp_error( LNKup_Safe_Fetch::validate( $url ) ) ) {
			return new WP_Error( 'lnkup_import_url', __( 'Paste a full event link that starts with https://', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$owner = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) ?: $user;
		$rel   = LNKup_Event_Sources::relation( $url, $owner, $event_id );
		if ( in_array( $rel['state'], array( 'other', 'claimed_other' ), true ) ) {
			return new WP_Error( 'lnkup_import_conflict', $rel['message'], array( 'status' => 409 ) );
		}
		if ( 'own' === $rel['state'] ) {
			return new WP_Error( 'lnkup_import_conflict', __( 'This listing is already linked to another of your events. Remove it there first.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$provider = (string) LNKup_Import_Provider_Registry::detect( $url )['key'];
		$sources  = array();
		foreach ( LNKup_Event_Sources::for_event( $event_id ) as $s ) {
			$sources[] = array( 'url' => $s['url'], 'provider' => $s['provider'], 'primary' => $s['is_primary'], 'mode' => $s['mode'] );
		}
		$sources[] = array( 'url' => $url, 'provider' => 'website' === $provider ? '' : $provider, 'primary' => ! $sources, 'mode' => 'link' );
		$state     = LNKup_Event_Builder_Schema::normalize( array( 'sources' => $sources ) );
		LNKup_Event_Sources::attach_from_state( $event_id, 0, $state, $user );
		if ( ! get_post_meta( $event_id, '_lnkup_ticketing_url', true ) ) {
			update_post_meta( $event_id, '_lnkup_ticketing_url', $url );
		}
		self::track( 'import_associated', $provider, $event_id, $user );
		return rest_ensure_response(
			array(
				'event_id' => $event_id,
				'title'    => get_the_title( $event_id ),
				'sources'  => LNKup_Event_Sources::for_event( $event_id ),
				'message'  => __( 'Listing linked. Vendors will see it as an official event listing.', 'lnkup-marketplace' ),
				'edit_url' => self::edit_url( $event_id ),
			)
		);
	}

	/**
	 * Client-safe preview (no raw HTML, no internal IDs of other organizers' events).
	 *
	 * @param array<string,mixed> $preview Preview.
	 * @return array<string,mixed>
	 */
	public static function present( array $preview ): array {
		$rel = $preview['relation'];
		if ( 'own' === $rel['state'] || 'own_listing' === $rel['state'] ) {
			$rel['event_title'] = get_the_title( (int) $rel['event_id'] );
			$rel['edit_url']    = self::edit_url( (int) $rel['event_id'] );
		} else {
			$rel['event_id'] = 0;
		}
		unset( $rel['external_id'] );
		$preview['relation'] = $rel;
		return $preview;
	}

	public static function edit_url( int $event_id ): string {
		return function_exists( 'lnkup_organizer_view_url' )
			? (string) lnkup_organizer_view_url( 'create', array( 'edit' => $event_id ) )
			: add_query_arg( array( 'view' => 'create', 'edit' => $event_id ), home_url( '/organizer-dashboard/' ) );
	}

	private static function track( string $type, string $provider, int $event_id, int $user ): void {
		LNKup_Event_Builder_Publisher::track( $type, LNKup_Event_Builder_Schema::normalize( array( 'method' => 'import', 'import' => array( 'provider' => $provider ) ) ), $event_id, $user );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : (array) $request->get_body_params();
	}
}
