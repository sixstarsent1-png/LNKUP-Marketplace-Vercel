<?php
/**
 * Verification REST API.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Verification_REST {

	const NS = 'lnkup/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/verification/vendor',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'current_vendor' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				'args'                => self::context_args(),
			)
		);

		register_rest_route(
			self::NS,
			'/verification/vendors/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'vendor_by_id' ),
				'permission_callback' => array( __CLASS__, 'vendor_view_permission' ),
				'args'                => array_merge(
					array( 'id' => array( 'required' => true, 'type' => 'integer' ) ),
					self::context_args()
				),
			)
		);

		register_rest_route(
			self::NS,
			'/verification/organizer',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'current_organizer' ),
				'permission_callback' => array( __CLASS__, 'organizer_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/verification/organizers/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'organizer_by_id' ),
				'permission_callback' => array( __CLASS__, 'organizer_view_permission' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/verification/admin/manual',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_manual' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/verification/admin/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_summary' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/verification/admin/queue',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_queue' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function context_args(): array {
		return array(
			'opportunity_id' => array( 'type' => 'integer', 'required' => false ),
			'application_id' => array( 'type' => 'integer', 'required' => false ),
		);
	}

	public static function vendor_permission(): bool {
		return is_user_logged_in() && LNKup_Roles::is_vendor( get_current_user_id() );
	}

	public static function organizer_permission(): bool {
		return is_user_logged_in() && ( LNKup_Roles::is_organizer( get_current_user_id() ) || LNKup_Capabilities::user_has_manage_all() );
	}

	public static function admin_permission(): bool {
		return LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function vendor_view_permission( $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$profile_id = absint( $request['id'] );
		$user_id    = get_current_user_id();
		if ( LNKup_Capabilities::user_has_manage_all() ) {
			return true;
		}
		if ( LNKup_Capabilities::user_owns_post( $profile_id, $user_id, '_lnkup_owner_user_id' ) ) {
			return true;
		}
		return self::organizer_can_view_vendor( $user_id, $profile_id );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function organizer_view_permission( $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$organizer_id = absint( $request['id'] );
		$user_id      = get_current_user_id();
		if ( LNKup_Capabilities::user_has_manage_all() ) {
			return true;
		}
		if ( (int) $user_id === $organizer_id && LNKup_Roles::is_organizer( $user_id ) ) {
			return true;
		}
		return self::vendor_can_view_organizer( $user_id, $organizer_id );
	}

	private static function organizer_can_view_vendor( int $organizer_id, int $profile_id ): bool {
		if ( ! LNKup_Roles::is_organizer( $organizer_id ) ) {
			return false;
		}
		$vendor_id = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		if ( ! $vendor_id ) {
			return false;
		}
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => '_lnkup_organizer_user_id', 'value' => $organizer_id ),
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor_id ),
				),
			)
		);
		return ! empty( $apps );
	}

	private static function vendor_can_view_organizer( int $vendor_id, int $organizer_id ): bool {
		if ( ! LNKup_Roles::is_vendor( $vendor_id ) ) {
			return false;
		}
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor_id ),
					array( 'key' => '_lnkup_organizer_user_id', 'value' => $organizer_id ),
				),
			)
		);
		if ( ! empty( $apps ) ) {
			return true;
		}
		return (bool) get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function current_vendor( $request ) {
		$profile_id = (int) lnkup_get_vendor_profile_id( get_current_user_id() );
		if ( ! $profile_id ) {
			return new WP_Error( 'no_profile', __( 'Vendor profile not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			LNKup_Verification::get_vendor_verification_status( $profile_id, self::build_context( $request ) )
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function vendor_by_id( $request ) {
		$profile_id = absint( $request['id'] );
		$post       = get_post( $profile_id );
		if ( ! $post || 'lnkup_vendor_profile' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Vendor profile not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$context = self::build_context( $request );
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			$context['public'] = true;
		}
		return rest_ensure_response( LNKup_Verification::get_vendor_verification_status( $profile_id, $context ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function current_organizer( $request ) {
		unset( $request );
		$organizer_id = get_current_user_id();
		return rest_ensure_response( LNKup_Verification::get_organizer_verification_status( $organizer_id ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function organizer_by_id( $request ) {
		$organizer_id = absint( $request['id'] );
		$user         = get_userdata( $organizer_id );
		if ( ! $user ) {
			return new WP_Error( 'not_found', __( 'Organizer not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$context = array( 'public' => ! LNKup_Capabilities::user_has_manage_all() );
		return rest_ensure_response( LNKup_Verification::get_organizer_verification_status( $organizer_id, $context ) );
	}

	/**
	 * Pending document queue summary for Admin Lite.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function admin_summary( $request ) {
		unset( $request );
		$pending = self::query_pending_documents( 1 );
		$queue_count = self::count_pending_documents();
		$approved_24h = self::count_documents_by_status_since( 'approved', DAY_IN_SECONDS );
		return rest_ensure_response(
			array(
				'queue_count'  => $queue_count,
				'approved_24h' => $approved_24h,
				'has_items'    => ! empty( $pending ),
			)
		);
	}

	/**
	 * Pending document queue items for Admin Lite.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function admin_queue( $request ) {
		unset( $request );
		$docs  = self::query_pending_documents( 50 );
		$types = class_exists( 'LNKup_Documents' ) ? LNKup_Documents::document_types() : array();
		$items = array();
		foreach ( $docs as $doc ) {
			$vendor_id  = (int) get_post_meta( $doc->ID, '_lnkup_vendor_user_id', true );
			$profile_id = (int) get_post_meta( $doc->ID, '_lnkup_vendor_profile_id', true );
			$vendor     = $vendor_id ? get_userdata( $vendor_id ) : false;
			$type       = (string) get_post_meta( $doc->ID, '_lnkup_document_type', true );
			$items[]    = array(
				'id'               => (int) $doc->ID,
				'document_id'      => (int) $doc->ID,
				'vendor_user_id'   => $vendor_id,
				'vendor_profile_id'=> $profile_id,
				'vendor_name'      => $vendor ? (string) $vendor->display_name : ( $vendor_id ? '#' . $vendor_id : '' ),
				'document_type'    => $type,
				'document_type_label' => (string) ( $types[ $type ] ?? $type ),
				'status'           => (string) get_post_meta( $doc->ID, '_lnkup_verification_status', true ),
				'uploaded_at'      => get_the_date( 'c', $doc ),
			);
		}
		return rest_ensure_response(
			array(
				'items' => $items,
				'total' => count( $items ),
			)
		);
	}

	/**
	 * @param int $limit Max posts.
	 * @return WP_Post[]
	 */
	private static function query_pending_documents( int $limit ): array {
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_document',
				'posts_per_page' => max( 1, $limit ),
				'meta_key'       => '_lnkup_verification_status',
				'meta_value'     => 'pending',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		return is_array( $posts ) ? $posts : array();
	}

	private static function count_pending_documents(): int {
		$query = new WP_Query(
			array(
				'post_type'      => 'lnkup_document',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_verification_status',
				'meta_value'     => 'pending',
				'no_found_rows'  => false,
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * @param string $status Document verification status.
	 * @param int    $seconds Lookback window.
	 */
	private static function count_documents_by_status_since( string $status, int $seconds ): int {
		$query = new WP_Query(
			array(
				'post_type'      => 'lnkup_document',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_verification_status',
				'meta_value'     => sanitize_key( $status ),
				'date_query'     => array(
					array(
						'after'     => gmdate( 'Y-m-d H:i:s', time() - max( 60, $seconds ) ),
						'inclusive' => true,
						'column'    => 'post_modified_gmt',
					),
				),
				'no_found_rows'  => false,
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function admin_manual( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$action = sanitize_key( (string) ( $params['action'] ?? '' ) );
		$notes  = sanitize_textarea_field( (string) ( $params['notes'] ?? '' ) );
		$reason = sanitize_text_field( (string) ( $params['reason'] ?? '' ) );

		if ( 'vendor_profile' === $action ) {
			$profile_id = absint( $params['profile_id'] ?? 0 );
			$status     = sanitize_key( (string) ( $params['status'] ?? '' ) );
			if ( ! $profile_id || ! in_array( $status, array( 'approved', 'rejected', 'pending' ), true ) ) {
				return new WP_Error( 'invalid', __( 'Invalid vendor verification payload.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			update_post_meta( $profile_id, '_lnkup_verification_status', $status );
			if ( $notes ) {
				update_post_meta( $profile_id, '_lnkup_verification_admin_notes', $notes );
			}
			LNKup_Verification::refresh_vendor( $profile_id );
			if ( class_exists( 'LNKup_Vendor_Reputation' ) ) {
				LNKup_Vendor_Reputation::log_activity(
					'approved' === $status ? 'verification_approved' : 'verification_revoked',
					$profile_id,
					array( 'payload' => array( 'status' => $status, 'reason' => $reason ) )
				);
			}
			return rest_ensure_response( array( 'success' => true, 'profile_id' => $profile_id, 'status' => $status ) );
		}

		if ( 'organizer' === $action ) {
			$organizer_id = absint( $params['organizer_id'] ?? 0 );
			$verified     = ! empty( $params['verified'] );
			if ( ! $organizer_id ) {
				return new WP_Error( 'invalid', __( 'Invalid organizer payload.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			update_user_meta( $organizer_id, 'lnkup_organizer_verified', $verified ? '1' : '' );
			if ( $notes ) {
				update_user_meta( $organizer_id, 'lnkup_organizer_verification_notes', $notes );
			}
			if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
				$opps = get_posts(
					array(
						'post_type'      => 'lnkup_opportunity',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'meta_key'       => '_lnkup_organizer_user_id',
						'meta_value'     => $organizer_id,
					)
				);
				foreach ( $opps as $opp_id ) {
					LNKup_Marketplace_Index::sync_opportunity( (int) $opp_id );
				}
			}
			return rest_ensure_response( array( 'success' => true, 'organizer_id' => $organizer_id, 'verified' => $verified ) );
		}

		if ( 'document' === $action ) {
			$doc_id = absint( $params['document_id'] ?? 0 );
			$status = sanitize_key( (string) ( $params['status'] ?? '' ) );
			if ( ! $doc_id || ! in_array( $status, array( 'approved', 'rejected', 'pending' ), true ) ) {
				return new WP_Error( 'invalid', __( 'Invalid document payload.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			$doc = get_post( $doc_id );
			if ( ! $doc || 'lnkup_document' !== $doc->post_type ) {
				return new WP_Error( 'not_found', __( 'Document not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			update_post_meta( $doc_id, '_lnkup_verification_status', $status );
			if ( $notes ) {
				update_post_meta( $doc_id, '_lnkup_admin_notes', $notes );
			}
			$profile_id = (int) get_post_meta( $doc_id, '_lnkup_vendor_profile_id', true );
			if ( $profile_id ) {
				LNKup_Verification::refresh_vendor( $profile_id );
				if ( class_exists( 'LNKup_Vendor_Reputation' ) ) {
					LNKup_Vendor_Reputation::log_activity(
						'document_reviewed',
						$profile_id,
						array( 'payload' => array( 'document_id' => $doc_id, 'status' => $status, 'reason' => $reason ) )
					);
				}
			}
			return rest_ensure_response( array( 'success' => true, 'document_id' => $doc_id, 'status' => $status ) );
		}

		return new WP_Error( 'invalid_action', __( 'Unknown verification action.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function build_context( $request ): array {
		$context = array();
		if ( ! empty( $request['opportunity_id'] ) ) {
			$context['opportunity_id'] = absint( $request['opportunity_id'] );
		}
		if ( ! empty( $request['application_id'] ) ) {
			$context['application_id'] = absint( $request['application_id'] );
		}
		return $context;
	}
}
