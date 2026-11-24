<?php
/**
 * Marketing campaign + segment API (lnkup/v1/comms/marketing/*) for the Marketing Command Center.
 *
 * Every route requires lnkup_manage_marketing (or manage_options). Previews return counts only,
 * never recipient names or emails. Test sends reach configured internal test accounts only.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Marketing_REST {

	const NS = 'lnkup/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function permission(): bool {
		return LNKup_Comms_Campaign_Workflow::can_manage( get_current_user_id() );
	}

	public static function routes(): void {
		$perm = array( __CLASS__, 'permission' );
		$base = '/comms/marketing';
		register_rest_route(
			self::NS,
			$base . '/segments',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'segments' ), 'permission_callback' => $perm ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_segment' ), 'permission_callback' => $perm ),
			)
		);
		register_rest_route( self::NS, $base . '/segments/preview', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'preview_segment' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, $base . '/segments/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_segment' ), 'permission_callback' => $perm ) );
		register_rest_route(
			self::NS,
			$base . '/campaigns',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'campaigns' ), 'permission_callback' => $perm ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_campaign' ), 'permission_callback' => $perm ),
			)
		);
		register_rest_route(
			self::NS,
			$base . '/campaigns/(?P<id>\d+)',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'campaign' ), 'permission_callback' => $perm ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'update_campaign' ), 'permission_callback' => $perm ),
			)
		);
		register_rest_route( self::NS, $base . '/campaigns/(?P<id>\d+)/(?P<action>submit|approve|reject|pause|resume|cancel|archive|test|preview)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'action' ), 'permission_callback' => $perm ) );
	}

	/**
	 * Counts only (the admin preview's sample names are removed).
	 */
	private static function counts( array $preview ): array {
		unset( $preview['sample'] );
		return $preview;
	}

	public static function segments(): WP_REST_Response {
		return rest_ensure_response( array( 'segments' => LNKup_Comms_Segments::list( false ), 'filters' => array_keys( LNKup_Comms_Audiences::clean_filters( array() ) ) ) );
	}

	public static function save_segment( WP_REST_Request $request ) {
		$id   = absint( $request['id'] ?? 0 );
		$body = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		if ( $id && 'archived' === ( $body['status'] ?? '' ) ) {
			LNKup_Comms_Segments::archive( $id );
			return rest_ensure_response( array( 'archived' => true ) );
		}
		$result = LNKup_Comms_Segments::save( $body, get_current_user_id(), $id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'segment' => LNKup_Comms_Segments::get( (int) $result ) ) );
	}

	public static function preview_segment( WP_REST_Request $request ): WP_REST_Response {
		$body     = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		$filters  = LNKup_Comms_Audiences::clean_filters( (array) ( $body['rules'] ?? array() ) );
		$category = sanitize_key( (string) ( $body['category'] ?? 'product_news' ) );
		return rest_ensure_response( self::counts( LNKup_Comms_Audiences::segment_preview( $filters, $category, array( 'inbox', 'email', 'push' ) ) ) );
	}

	/**
	 * Campaign fields for the UI.
	 */
	private static function shape( array $row ): array {
		$labels = LNKup_Comms_Campaign_Workflow::statuses();
		return array(
			'id'             => (int) $row['id'],
			'name'           => (string) $row['name'],
			'status'         => (string) $row['status'],
			'status_label'   => $labels[ $row['status'] ] ?? (string) $row['status'],
			'role'           => (string) $row['audience_role'],
			'category'       => (string) $row['category'],
			'channels'       => explode( ',', (string) $row['channels'] ),
			'content'        => (array) $row['content'],
			'filters'        => LNKup_Comms_Campaign_Workflow::filters_for( $row ),
			'segment_id'     => (int) ( $row['segment_id'] ?? 0 ),
			'meta'           => LNKup_Comms_Campaign_Workflow::meta( $row ),
			'created_by'     => (int) $row['created_by'],
			'submitted_by'   => (int) ( $row['submitted_by'] ?? 0 ),
			'approved_by'    => (int) ( $row['approved_by'] ?? 0 ),
			'approved_count' => (int) ( $row['approved_count'] ?? 0 ),
			'scheduled_at'   => (string) ( $row['scheduled_at'] ?? '' ),
			'started_at'     => (string) ( $row['started_at'] ?? '' ),
			'completed_at'   => (string) ( $row['completed_at'] ?? '' ),
			'total_eligible' => (int) $row['total_eligible'],
			'total_queued'   => (int) $row['total_queued'],
			'total_excluded' => (int) $row['total_excluded'],
		);
	}

	public static function campaigns(): WP_REST_Response {
		return rest_ensure_response( array( 'campaigns' => array_map( array( __CLASS__, 'shape' ), LNKup_Comms_Campaigns::list( 'admin', 0, 100 ) ), 'statuses' => LNKup_Comms_Campaign_Workflow::statuses(), 'workflow' => LNKup_Comms_Campaign_Workflow::active() ) );
	}

	public static function campaign( WP_REST_Request $request ) {
		$row = LNKup_Comms_Campaigns::get( (int) $request['id'] );
		if ( ! $row || 'admin' !== $row['campaign_type'] ) {
			return new WP_Error( 'lnkup_comms_campaign', __( 'Campaign not found.', 'lnkup-communications' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'campaign' => self::shape( $row ), 'results' => LNKup_Comms_Campaign_Workflow::results( (int) $row['id'] ) ) );
	}

	public static function create_campaign( WP_REST_Request $request ) {
		$body = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		$id   = LNKup_Comms_Campaign_Workflow::create( $body, get_current_user_id() );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response( array( 'campaign' => self::shape( (array) LNKup_Comms_Campaigns::get( (int) $id ) ) ) );
	}

	public static function update_campaign( WP_REST_Request $request ) {
		$body   = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		$result = LNKup_Comms_Campaign_Workflow::update_draft( (int) $request['id'], $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'campaign' => self::shape( (array) LNKup_Comms_Campaigns::get( (int) $request['id'] ) ) ) );
	}

	public static function action( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$user = get_current_user_id();
		$body = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		$row  = LNKup_Comms_Campaigns::get( $id );
		if ( ! $row || 'admin' !== $row['campaign_type'] ) {
			return new WP_Error( 'lnkup_comms_campaign', __( 'Campaign not found.', 'lnkup-communications' ), array( 'status' => 404 ) );
		}
		switch ( (string) $request['action'] ) {
			case 'submit':
				$result = LNKup_Comms_Campaign_Workflow::submit( $id, $user );
				break;
			case 'approve':
				$when   = ! empty( $body['scheduled_at'] ) ? (int) strtotime( (string) $body['scheduled_at'] ) : 0;
				$result = LNKup_Comms_Campaign_Workflow::approve( $id, $user, max( 0, $when ) );
				break;
			case 'reject':
				$result = LNKup_Comms_Campaign_Workflow::reject( $id, $user, (string) ( $body['note'] ?? '' ) );
				break;
			case 'pause':
				LNKup_Comms_Campaigns::pause( $id );
				$result = true;
				break;
			case 'resume':
				$result = 'paused' === $row['status'] ? LNKup_Comms_Campaigns::start( $id, (int) $row['total_eligible'] ) : new WP_Error( 'lnkup_comms_campaign', __( 'Only paused campaigns can resume.', 'lnkup-communications' ), array( 'status' => 409 ) );
				break;
			case 'cancel':
				LNKup_Comms_Campaigns::cancel( $id );
				$result = true;
				break;
			case 'archive':
				$result = LNKup_Comms_Campaign_Workflow::archive( $id );
				break;
			case 'test':
				$result = LNKup_Comms_Campaigns::test_send( $id, $user );
				break;
			default:
				$result = self::counts( (array) LNKup_Comms_Campaigns::preview( $id ) );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'result' => $result, 'campaign' => self::shape( (array) LNKup_Comms_Campaigns::get( $id ) ) ) );
	}
}
