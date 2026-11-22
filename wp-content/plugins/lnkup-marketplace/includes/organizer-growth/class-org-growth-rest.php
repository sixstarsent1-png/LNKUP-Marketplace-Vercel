<?php
/**
 * REST API for the Organizer Acquisition OS — lnkup/v1/growth/*.
 *
 * Every route requires `lnkup_manage_organizer_growth` except the public, HMAC-token
 * unsubscribe route. While the module flag is off only bootstrap and settings respond, so
 * an admin can turn it on.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Growth_REST {

	const NS = 'lnkup/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function can_manage(): bool {
		return LNKup_Org_Growth::current_user_can_manage();
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function can_use() {
		if ( ! self::can_manage() ) {
			return false;
		}
		if ( ! LNKup_Org_Growth::is_enabled() ) {
			return new WP_Error( 'lnkup_growth_disabled', __( 'Organizer Growth is turned off. Enable it in Organizer Growth → Settings.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return true;
	}

	private static function route( string $path, array $methods_callbacks, ?callable $permission = null ): void {
		$args = array();
		foreach ( $methods_callbacks as $method => $callback ) {
			$args[] = array(
				'methods'             => $method,
				'callback'            => self::safe( $callback ),
				'permission_callback' => $permission ?? array( __CLASS__, 'can_use' ),
			);
		}
		register_rest_route( self::NS, '/growth' . $path, $args );
	}

	/**
	 * Wrap a handler so exceptions become JSON errors instead of HTML fatals.
	 */
	private static function safe( callable $callback ): callable {
		return static function ( WP_REST_Request $request ) use ( $callback ) {
			try {
				$result = $callback( $request );
			} catch ( Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup growth REST error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
				return new WP_Error( 'lnkup_growth_error', __( 'Something went wrong. Try again or contact support.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
			}
			if ( is_wp_error( $result ) ) {
				$data = $result->get_error_data();
				if ( ! is_array( $data ) || empty( $data['status'] ) ) {
					$result->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => 400 ) ) );
				}
				return $result;
			}
			return rest_ensure_response( $result );
		};
	}

	public static function register_routes(): void {
		$manage = array( __CLASS__, 'can_manage' );

		self::route( '/bootstrap', array( 'GET' => array( __CLASS__, 'bootstrap' ) ), $manage );
		self::route(
			'/settings',
			array(
				'GET'  => static fn() => array( 'settings' => LNKup_Org_Growth::settings() ),
				'POST' => static fn( WP_REST_Request $r ) => array( 'settings' => LNKup_Org_Growth::update_settings( (array) $r->get_json_params() ) ),
			),
			$manage
		);

		self::route( '/overview', array( 'GET' => static fn( WP_REST_Request $r ) => LNKup_Org_Growth_Metrics::overview( array( 'affiliation' => (string) $r->get_param( 'affiliation' ) ) ) ) );

		self::route(
			'/leads',
			array(
				'GET'  => static fn( WP_REST_Request $r ) => LNKup_Org_Leads::query( self::filters( $r ) ),
				'POST' => array( __CLASS__, 'create_lead' ),
			)
		);
		self::route( '/leads/export', array( 'GET' => array( __CLASS__, 'export_leads' ) ) );
		self::route( '/leads/bulk', array( 'POST' => array( __CLASS__, 'bulk' ) ) );
		self::route( '/leads/duplicates', array( 'GET' => static fn() => array( 'items' => LNKup_Org_Lead_Dedupe::review_queue( 100 ) ) ) );
		self::route(
			'/leads/(?P<id>\d+)',
			array(
				'GET'   => array( __CLASS__, 'get_lead' ),
				'PATCH' => array( __CLASS__, 'update_lead' ),
			)
		);
		self::route( '/leads/(?P<id>\d+)/stage', array( 'POST' => array( __CLASS__, 'move_lead' ) ) );
		self::route(
			'/leads/(?P<id>\d+)/activity',
			array(
				'GET'  => static fn( WP_REST_Request $r ) => array( 'items' => LNKup_Org_Leads::activity( (int) $r['id'], 200 ) ),
				'POST' => array( __CLASS__, 'record_contact' ),
			)
		);
		self::route(
			'/leads/(?P<id>\d+)/tasks',
			array(
				'GET'  => static fn( WP_REST_Request $r ) => array( 'items' => LNKup_Org_Lead_Tasks::for_lead( (int) $r['id'] ) ),
				'POST' => static fn( WP_REST_Request $r ) => LNKup_Org_Lead_Tasks::create( (int) $r['id'], (array) $r->get_json_params(), get_current_user_id() ),
			)
		);
		self::route( '/leads/(?P<id>\d+)/events', array( 'POST' => array( __CLASS__, 'add_event' ) ) );
		self::route(
			'/leads/(?P<id>\d+)/link-account',
			array(
				'POST'   => array( __CLASS__, 'link_account' ),
				'DELETE' => static fn( WP_REST_Request $r ) => self::then_detail( (int) $r['id'], LNKup_Org_Lead_Lifecycle::unlink( (int) $r['id'], (int) $r->get_param( 'user_id' ), get_current_user_id() ) ),
			)
		);
		self::route( '/leads/(?P<id>\d+)/merge', array( 'POST' => static fn( WP_REST_Request $r ) => self::then_detail( (int) $r->get_param( 'into' ), LNKup_Org_Lead_Dedupe::merge( (int) $r['id'], (int) $r->get_param( 'into' ), get_current_user_id() ) ) ) );
		self::route( '/leads/(?P<id>\d+)/not-duplicate', array( 'POST' => static fn( WP_REST_Request $r ) => self::then_detail( (int) $r['id'], LNKup_Org_Lead_Dedupe::dismiss( (int) $r['id'], get_current_user_id() ) ) ) );
		self::route( '/leads/(?P<id>\d+)/suppress', array( 'POST' => array( __CLASS__, 'suppress_lead' ) ) );

		self::route( '/pipeline', array( 'GET' => static fn( WP_REST_Request $r ) => array( 'columns' => LNKup_Org_Leads::pipeline( self::filters( $r ), 25 ) ) ) );
		self::route( '/tasks', array( 'GET' => static fn( WP_REST_Request $r ) => array( 'items' => LNKup_Org_Lead_Tasks::queue( array( 'assigned_user_id' => (int) $r->get_param( 'assigned_user_id' ), 'window' => (string) $r->get_param( 'window' ), 'status' => (string) $r->get_param( 'status' ) ) ) ) ) );
		self::route( '/tasks/(?P<id>\d+)/complete', array( 'POST' => static fn( WP_REST_Request $r ) => LNKup_Org_Lead_Tasks::complete( (int) $r['id'], get_current_user_id(), (string) $r->get_param( 'note' ) ) ) );
		self::route( '/accounts/search', array( 'GET' => array( __CLASS__, 'search_accounts' ) ) );

		self::route(
			'/import',
			array(
				'GET' => static fn() => array( 'items' => LNKup_Org_Lead_Import::history() ),
			)
		);
		self::route( '/import/template', array( 'GET' => static fn() => array( 'filename' => 'organizer-leads-template.csv', 'csv' => LNKup_Org_Lead_Import::template_csv() ) ) );
		self::route( '/import/upload', array( 'POST' => array( __CLASS__, 'import_upload' ) ) );
		self::route( '/import/(?P<id>\d+)', array( 'GET' => static fn( WP_REST_Request $r ) => self::import_status( (int) $r['id'] ) ) );
		self::route( '/import/(?P<id>\d+)/preview', array( 'POST' => static fn( WP_REST_Request $r ) => LNKup_Org_Lead_Import::preview( (int) $r['id'], (array) ( $r->get_param( 'mapping' ) ?: array() ) ) ) );
		self::route( '/import/(?P<id>\d+)/run', array( 'POST' => static fn( WP_REST_Request $r ) => LNKup_Org_Lead_Import::run( (int) $r['id'] ) ) );
		self::route( '/import/(?P<id>\d+)/rollback', array( 'POST' => static fn( WP_REST_Request $r ) => LNKup_Org_Lead_Import::rollback( (int) $r['id'], get_current_user_id() ) ) );

		self::route(
			'/scoring/config',
			array(
				'GET'  => static fn() => LNKup_Org_Lead_Scoring::describe(),
				'POST' => static fn( WP_REST_Request $r ) => LNKup_Org_Lead_Scoring::save_config( (array) $r->get_json_params() ),
			)
		);
		self::route(
			'/views',
			array(
				'GET'  => array( __CLASS__, 'list_views' ),
				'POST' => array( __CLASS__, 'save_view' ),
			)
		);
		self::route( '/views/(?P<id>\d+)', array( 'DELETE' => array( __CLASS__, 'delete_view' ) ) );

		// Phase 3 — outreach.
		self::route( '/templates', array( 'GET' => static fn() => array( 'items' => LNKup_Org_Outreach::list_templates(), 'merge_fields' => LNKup_Org_Outreach::MERGE_FIELDS ) ) );
		self::route( '/templates/(?P<key>[a-z0-9_]+)', array( 'PUT' => static fn( WP_REST_Request $r ) => LNKup_Org_Outreach::save_template( (string) $r['key'], (array) $r->get_json_params(), get_current_user_id() ) ) );
		self::route( '/templates/(?P<key>[a-z0-9_]+)/preview', array( 'POST' => array( __CLASS__, 'preview_template' ) ) );
		self::route(
			'/campaigns',
			array(
				'GET'  => static fn() => array( 'items' => LNKup_Org_Outreach::list_campaigns() ),
				'POST' => static fn( WP_REST_Request $r ) => LNKup_Org_Outreach::create_campaign( (array) $r->get_json_params(), get_current_user_id() ),
			)
		);
		self::route( '/campaigns/(?P<id>\d+)', array( 'GET' => array( __CLASS__, 'get_campaign' ) ) );
		self::route( '/campaigns/(?P<id>\d+)/approve', array( 'POST' => static fn( WP_REST_Request $r ) => LNKup_Org_Outreach::approve( (int) $r['id'], get_current_user_id() ) ) );
		self::route( '/campaigns/(?P<id>\d+)/queue', array( 'POST' => array( __CLASS__, 'queue_campaign' ) ) );
		self::route( '/campaigns/(?P<id>\d+)/cancel', array( 'POST' => array( __CLASS__, 'cancel_campaign' ) ) );
		self::route(
			'/suppression',
			array(
				'GET'    => static fn() => array( 'counts' => LNKup_Contact_Suppression::counts() ),
				'POST'   => array( __CLASS__, 'add_suppression' ),
				'DELETE' => static fn( WP_REST_Request $r ) => self::then_ok( LNKup_Contact_Suppression::remove_email( (string) $r->get_param( 'email' ), get_current_user_id() ) ),
			)
		);

		register_rest_route(
			self::NS,
			'/growth/unsubscribe',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'unsubscribe' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	// ------------------------------------------------------------------ Helpers.

	/**
	 * @return array<string, mixed>
	 */
	private static function filters( WP_REST_Request $r ): array {
		$keys = array( 'search', 'stage', 'outcome', 'tier', 'city', 'state', 'category', 'source', 'org_type', 'priority', 'assigned_user_id', 'assigned_connector_id', 'import_id', 'partner_id', 'parent_lead_id', 'affiliation', 'tag', 'follow_up_due', 'possible_duplicates', 'unassigned', 'ids', 'page', 'per_page', 'orderby', 'order' );
		$out  = array();
		foreach ( $keys as $key ) {
			$value = $r->get_param( $key );
			if ( null !== $value && '' !== $value ) {
				$out[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( (string) $value );
			}
		}
		return $out;
	}

	/**
	 * @param mixed $result True or WP_Error.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function then_detail( int $lead_id, $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$lead = LNKup_Org_Leads::detail( $lead_id );
		return $lead ? array( 'lead' => $lead ) : new WP_Error( 'lnkup_lead_not_found', __( 'Lead not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
	}

	/**
	 * @param mixed $result True or WP_Error.
	 * @return array<string, bool>|WP_Error
	 */
	private static function then_ok( $result ) {
		return is_wp_error( $result ) ? $result : array( 'ok' => true );
	}

	// ------------------------------------------------------------------ Handlers.

	public static function bootstrap(): array {
		return array(
			'enabled'          => LNKup_Org_Growth::is_enabled(),
			'outreach_enabled' => LNKup_Org_Growth::outreach_enabled(),
			'settings'         => LNKup_Org_Growth::settings(),
			'stages'           => LNKup_Org_Lead_Pipeline::describe(),
			'outcomes'         => LNKup_Org_Lead_Pipeline::describe_outcomes(),
			'org_types'        => LNKup_Org_Leads::ORG_TYPES,
			'frequencies'      => array_values( array_filter( LNKup_Org_Leads::FREQUENCIES ) ),
			'team'             => LNKup_Org_Growth::team_members(),
			'connectors'       => LNKup_Org_Growth::connectors(),
			'tags'             => LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::TAGS ) ? LNKup_Org_Leads::all_tags() : array(),
			'scoring'          => LNKup_Org_Lead_Scoring::describe(),
			'import_targets'   => LNKup_Org_Lead_Import::targets(),
			'current_user_id'  => get_current_user_id(),
			'readiness'        => LNKup_Org_Growth::readiness(),
		);
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_lead( WP_REST_Request $r ) {
		$body = (array) $r->get_json_params();
		$id   = LNKup_Org_Leads::create(
			$body,
			array(
				'actor'           => get_current_user_id(),
				'allow_duplicate' => ! empty( $body['allow_duplicate'] ),
				'stage'           => (string) ( $body['stage'] ?? '' ),
				'tags'            => (array) ( $body['tags'] ?? array() ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return array( 'lead' => LNKup_Org_Leads::detail( (int) $id ) );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_lead( WP_REST_Request $r ) {
		$lead = LNKup_Org_Leads::detail( (int) $r['id'] );
		return $lead ? array( 'lead' => $lead ) : new WP_Error( 'lnkup_lead_not_found', __( 'Lead not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_lead( WP_REST_Request $r ) {
		$body   = (array) $r->get_json_params();
		$result = LNKup_Org_Leads::update( (int) $r['id'], $body, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( array_key_exists( 'tags', $body ) ) {
			LNKup_Org_Leads::set_tags( (int) $r['id'], (array) $body['tags'] );
		}
		return array( 'lead' => LNKup_Org_Leads::detail( (int) $r['id'] ) );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function move_lead( WP_REST_Request $r ) {
		$result = LNKup_Org_Leads::move( (int) $r['id'], sanitize_key( (string) $r->get_param( 'stage' ) ), sanitize_key( (string) $r->get_param( 'outcome' ) ), get_current_user_id(), (string) $r->get_param( 'note' ) );
		return is_wp_error( $result ) ? $result : array( 'lead' => $result );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function record_contact( WP_REST_Request $r ) {
		$result = LNKup_Org_Leads::record_contact( (int) $r['id'], (array) $r->get_json_params(), get_current_user_id() );
		return self::then_detail( (int) $r['id'], is_wp_error( $result ) ? $result : true );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function bulk( WP_REST_Request $r ) {
		$ids = (array) $r->get_param( 'ids' );
		if ( ! $ids ) {
			return new WP_Error( 'lnkup_bulk_empty', __( 'Select at least one lead.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return LNKup_Org_Leads::bulk( $ids, sanitize_key( (string) $r->get_param( 'action' ) ), $r->get_param( 'value' ), get_current_user_id() );
	}

	public static function export_leads( WP_REST_Request $r ): array {
		$filters = self::filters( $r );
		unset( $filters['page'], $filters['per_page'] );
		LNKup_Financial_Audit::log( 'org_leads_exported', 'org_lead', 0, array( 'actor' => get_current_user_id(), 'filters' => $filters ) );
		return array(
			'filename' => 'organizer-leads-' . gmdate( 'Y-m-d' ) . '.csv',
			'csv'      => LNKup_Org_Leads::export_csv( $filters ),
		);
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function add_event( WP_REST_Request $r ) {
		global $wpdb;
		$lead_id = (int) $r['id'];
		if ( ! LNKup_Org_Leads::get_row( $lead_id ) ) {
			return new WP_Error( 'lnkup_lead_not_found', __( 'Lead not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$name = sanitize_text_field( (string) $r->get_param( 'name' ) );
		if ( '' === $name ) {
			return new WP_Error( 'lnkup_event_name', __( 'Event name is required.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$date     = strtotime( (string) $r->get_param( 'event_date' ) );
		$verified = rest_sanitize_boolean( $r->get_param( 'verified' ) );
		$wpdb->insert(
			LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::LEAD_EVENTS ),
			array(
				'lead_id'     => $lead_id,
				'name'        => substr( $name, 0, 191 ),
				'url'         => esc_url_raw( (string) $r->get_param( 'url' ) ),
				'event_date'  => $date ? gmdate( 'Y-m-d', $date ) : null,
				'city'        => sanitize_text_field( (string) $r->get_param( 'city' ) ),
				'state'       => strtoupper( sanitize_text_field( (string) $r->get_param( 'state' ) ) ),
				'category'    => sanitize_text_field( (string) $r->get_param( 'category' ) ),
				'verified_at' => $verified ? LNKup_Org_Growth::now() : null,
				'created_at'  => LNKup_Org_Growth::now(),
			)
		);
		LNKup_Org_Leads::log_activity( $lead_id, 'event_added', array( 'actor' => get_current_user_id(), 'summary' => $name ) );
		LNKup_Org_Lead_Scoring::score_lead( $lead_id );
		return array( 'lead' => LNKup_Org_Leads::detail( $lead_id ) );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function link_account( WP_REST_Request $r ) {
		$user_id = (int) $r->get_param( 'user_id' );
		if ( ! $user_id && $r->get_param( 'email' ) ) {
			$user    = get_user_by( 'email', sanitize_email( (string) $r->get_param( 'email' ) ) );
			$user_id = $user ? (int) $user->ID : 0;
		}
		if ( ! $user_id ) {
			return new WP_Error( 'lnkup_user_not_found', __( 'No LNKup account matches that email.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return self::then_detail( (int) $r['id'], LNKup_Org_Lead_Lifecycle::link( (int) $r['id'], $user_id, 'manual', get_current_user_id() ) );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function suppress_lead( WP_REST_Request $r ) {
		$reason = sanitize_key( (string) $r->get_param( 'reason' ) );
		$reason = in_array( $reason, LNKup_Contact_Suppression::REASONS, true ) ? $reason : 'manual';
		if ( 'do_not_contact' === $reason ) {
			return self::then_detail( (int) $r['id'], LNKup_Org_Leads::move( (int) $r['id'], '', 'do_not_contact', get_current_user_id(), (string) $r->get_param( 'note' ) ) );
		}
		LNKup_Contact_Suppression::suppress_lead( (int) $r['id'], $reason, get_current_user_id() );
		return self::then_detail( (int) $r['id'], true );
	}

	public static function search_accounts( WP_REST_Request $r ): array {
		$q = sanitize_text_field( (string) $r->get_param( 'q' ) );
		if ( strlen( $q ) < 2 ) {
			return array( 'items' => array() );
		}
		$users = get_users(
			array(
				'role'           => 'lnkup_organizer',
				'search'         => '*' . $q . '*',
				'search_columns' => array( 'user_email', 'display_name', 'user_login' ),
				'number'         => 10,
			)
		);
		$out = array();
		foreach ( $users as $user ) {
			$out[] = array(
				'user_id' => (int) $user->ID,
				'name'    => (string) $user->display_name,
				'email'   => (string) $user->user_email,
				'lead_id' => LNKup_Org_Leads::lead_for_user( (int) $user->ID ),
			);
		}
		return array( 'items' => $out );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function import_upload( WP_REST_Request $r ) {
		$files = $r->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new WP_Error( 'lnkup_import_no_file', __( 'Choose a CSV file to upload.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return LNKup_Org_Lead_Import::upload(
			$files['file'],
			array(
				'source_label' => (string) $r->get_param( 'source_label' ),
				'verified_at'  => (string) $r->get_param( 'verified_at' ),
			),
			get_current_user_id()
		);
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private static function import_status( int $import_id ) {
		$import = LNKup_Org_Lead_Import::get( $import_id );
		if ( ! $import ) {
			return new WP_Error( 'lnkup_import_not_found', __( 'Import not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( 'running' === $import['status'] && ! wp_next_scheduled( LNKup_Org_Lead_Import::BATCH_HOOK, array( $import_id ) ) ) {
			// Cron may be slow on low-traffic sites; polling keeps the import moving.
			LNKup_Org_Lead_Import::process_batch( $import_id );
			$import = LNKup_Org_Lead_Import::get( $import_id );
		}
		return array(
			'import' => $import,
			'rows'   => LNKup_Org_Lead_Import::row_results( $import_id ),
		);
	}

	public static function list_views(): array {
		global $wpdb;
		$table = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::VIEWS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d OR is_shared = 1 ORDER BY name ASC", get_current_user_id() ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$filters = json_decode( (string) $row['filters'], true );
			$out[]   = array(
				'id'        => (int) $row['id'],
				'name'      => (string) $row['name'],
				'filters'   => is_array( $filters ) ? $filters : array(),
				'is_shared' => (bool) (int) $row['is_shared'],
				'is_mine'   => (int) $row['user_id'] === get_current_user_id(),
			);
		}
		return array( 'items' => $out );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_view( WP_REST_Request $r ) {
		global $wpdb;
		$name = sanitize_text_field( (string) $r->get_param( 'name' ) );
		if ( '' === $name ) {
			return new WP_Error( 'lnkup_view_name', __( 'Name this view.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$filters = (array) $r->get_param( 'filters' );
		$clean   = array();
		foreach ( $filters as $k => $v ) {
			$clean[ sanitize_key( (string) $k ) ] = is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
		}
		$wpdb->insert(
			LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::VIEWS ),
			array(
				'user_id'    => get_current_user_id(),
				'name'       => substr( $name, 0, 100 ),
				'filters'    => wp_json_encode( $clean ),
				'is_shared'  => rest_sanitize_boolean( $r->get_param( 'is_shared' ) ) ? 1 : 0,
				'created_at' => LNKup_Org_Growth::now(),
			)
		);
		return self::list_views();
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_view( WP_REST_Request $r ) {
		global $wpdb;
		$deleted = $wpdb->delete( LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::VIEWS ), array( 'id' => (int) $r['id'], 'user_id' => get_current_user_id() ) );
		if ( ! $deleted ) {
			return new WP_Error( 'lnkup_view_not_found', __( 'You can only delete your own saved views.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return self::list_views();
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function preview_template( WP_REST_Request $r ) {
		$lead = LNKup_Org_Leads::get_row( (int) $r->get_param( 'lead_id' ) );
		if ( ! $lead ) {
			return new WP_Error( 'lnkup_lead_not_found', __( 'Pick a lead to preview with.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$render = LNKup_Org_Outreach::render( (string) $r['key'], $lead, array( 'signup_url' => home_url( '/sign-up/?role=organizer' ), 'unsubscribe_url' => home_url( '/?lnkup_org_unsub=preview' ), 'sender_user_id' => get_current_user_id() ) );
		if ( is_wp_error( $render ) ) {
			return $render;
		}
		return $render + array( 'eligibility' => LNKup_Org_Outreach::ineligible_reason( $lead ) );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_campaign( WP_REST_Request $r ) {
		$campaign = LNKup_Outreach::get_campaign( (int) $r['id'] );
		if ( ! $campaign || LNKup_Org_Outreach::SOURCE !== $campaign['source'] ) {
			return new WP_Error( 'lnkup_campaign_not_found', __( 'Campaign not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return array(
			'campaign'   => LNKup_Org_Outreach::format_campaign( $campaign ),
			'recipients' => LNKup_Org_Outreach::campaign_recipients( (int) $r['id'] ),
		);
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function queue_campaign( WP_REST_Request $r ) {
		$campaign = LNKup_Outreach::get_campaign( (int) $r['id'] );
		if ( ! $campaign || LNKup_Org_Outreach::SOURCE !== $campaign['source'] ) {
			return new WP_Error( 'lnkup_campaign_not_found', __( 'Campaign not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$queued = LNKup_Outreach::queue_campaign( (int) $r['id'] );
		if ( is_wp_error( $queued ) ) {
			return $queued;
		}
		return array( 'campaign' => LNKup_Org_Outreach::format_campaign( (array) LNKup_Outreach::get_campaign( (int) $r['id'] ) ) );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function cancel_campaign( WP_REST_Request $r ) {
		$campaign = LNKup_Outreach::get_campaign( (int) $r['id'] );
		if ( ! $campaign || LNKup_Org_Outreach::SOURCE !== $campaign['source'] ) {
			return new WP_Error( 'lnkup_campaign_not_found', __( 'Campaign not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$cancelled = LNKup_Outreach::cancel_campaign( (int) $r['id'] );
		if ( is_wp_error( $cancelled ) ) {
			return $cancelled;
		}
		return array( 'campaign' => LNKup_Org_Outreach::format_campaign( (array) LNKup_Outreach::get_campaign( (int) $r['id'] ) ) );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function add_suppression( WP_REST_Request $r ) {
		$reason = sanitize_key( (string) $r->get_param( 'reason' ) );
		$reason = in_array( $reason, LNKup_Contact_Suppression::REASONS, true ) ? $reason : 'manual';
		$email  = (string) $r->get_param( 'email' );
		$domain = (string) $r->get_param( 'domain' );
		$ok     = '' !== $email ? LNKup_Contact_Suppression::add_email( $email, $reason, 'admin', 0, get_current_user_id() ) : LNKup_Contact_Suppression::add_domain( $domain, $reason, 'admin', 0, get_current_user_id() );
		if ( ! $ok ) {
			return new WP_Error( 'lnkup_suppression_invalid', __( 'Enter a valid email or business domain.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return array( 'counts' => LNKup_Contact_Suppression::counts() );
	}

	public static function unsubscribe( WP_REST_Request $r ): WP_REST_Response {
		$result = LNKup_Org_Outreach::apply_unsubscribe( (string) $r->get_param( 'token' ) );
		return new WP_REST_Response(
			array(
				'success' => $result['ok'],
				'message' => $result['ok'] ? __( 'You are unsubscribed.', 'lnkup-marketplace' ) : __( 'This unsubscribe link is not valid.', 'lnkup-marketplace' ),
			),
			$result['ok'] ? 200 : 400
		);
	}
}
