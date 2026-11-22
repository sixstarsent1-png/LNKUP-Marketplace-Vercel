<?php
/**
 * Documents REST API for vendor headless app.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Documents_REST {

	const NS = 'lnkup/v1';

	/**
	 * Init routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/documents/vendor',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'vendor_documents' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/documents/upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload_document' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/documents/(?P<id>\d+)/file',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'download_file' ),
				'permission_callback' => array( __CLASS__, 'document_file_permission' ),
				'args'                => array(
					'id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/documents/vault',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'vault' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/documents/reusable',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'reusable' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				'args'                => array(
					'opportunity_id' => array( 'required' => true, 'type' => 'integer' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/documents/shares',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_shares' ),
					'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_share' ),
					'permission_callback' => array( __CLASS__, 'vendor_permission' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/documents/shares/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'revoke_share' ),
				'permission_callback' => array( __CLASS__, 'vendor_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/documents/shares/(?P<id>\d+)/file',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'shared_file' ),
				'permission_callback' => array( __CLASS__, 'shared_file_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/applications/(?P<id>\d+)/shared-documents',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'application_shared_documents' ),
				'permission_callback' => array( __CLASS__, 'application_organizer_permission' ),
			)
		);
	}

	/**
	 * Sharing is part of the Document Vault rollout flag.
	 */
	private static function sharing_disabled() {
		if ( class_exists( 'LNKup_Passport_Settings' ) && LNKup_Passport_Settings::enabled( 'document_vault_sharing' ) ) {
			return null;
		}
		return new WP_Error( 'feature_disabled', __( 'Document sharing is not enabled yet.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
	}

	/**
	 * GET vault: every document group with versions and shares.
	 */
	public static function vault() {
		$user_id = get_current_user_id();
		return rest_ensure_response(
			array(
				'groups'        => LNKup_Document_Vault::vault( $user_id ),
				'types'         => LNKup_Documents::document_types(),
				'compliance'    => LNKup_Documents::compliance_summary( $user_id ),
				'sharing'       => null === self::sharing_disabled(),
				'reminder_days' => LNKup_Passport_Settings::reminder_days(),
			)
		);
	}

	/**
	 * GET reusable documents for an opportunity (suggestions only).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function reusable( WP_REST_Request $request ) {
		$opp_id = absint( $request['opportunity_id'] );
		if ( 'lnkup_opportunity' !== get_post_type( $opp_id ) ) {
			return new WP_Error( 'not_found', __( 'Opportunity not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$profile_id = (int) lnkup_get_vendor_profile_id( get_current_user_id() );
		if ( ! $profile_id ) {
			return new WP_Error( 'no_profile', __( 'Create your vendor profile first.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( LNKup_Document_Vault::find_reusable( $profile_id, $opp_id ) );
	}

	public static function list_shares() {
		$profile_id = (int) lnkup_get_vendor_profile_id( get_current_user_id() );
		return rest_ensure_response( array( 'items' => $profile_id ? LNKup_Document_Vault::shares_for_profile( $profile_id ) : array() ) );
	}

	/**
	 * POST share: vendor consents to share one document version for one application.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function create_share( WP_REST_Request $request ) {
		$off = self::sharing_disabled();
		if ( $off ) {
			return $off;
		}
		$body = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		if ( empty( $body['consent'] ) ) {
			return new WP_Error( 'consent_required', __( 'Confirm that you want to share this document with the organizer.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$user_id    = get_current_user_id();
		$profile_id = (int) lnkup_get_vendor_profile_id( $user_id );
		$result     = LNKup_Document_Vault::share( $profile_id, absint( $body['document_id'] ?? 0 ), absint( $body['application_id'] ?? 0 ), $user_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'share_id' => (int) $result, 'share' => LNKup_Document_Vault::shares_for_profile( $profile_id )[0] ?? null ) );
	}

	/**
	 * DELETE share: revoke organizer access.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function revoke_share( WP_REST_Request $request ) {
		$result = LNKup_Document_Vault::revoke_share( absint( $request['id'] ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Organizer download: signed, short-lived token bound to the share and the signed-in organizer.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function shared_file_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Sign in to view shared documents.', 'lnkup-marketplace' ), array( 'status' => 401 ) );
		}
		$share   = LNKup_Document_Vault::get_share( absint( $request['id'] ) );
		$user_id = get_current_user_id();
		if ( ! $share || ! LNKup_Document_Vault::can_access_share( $share, $user_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'This document is no longer shared with you.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( ! LNKup_Capabilities::user_has_manage_all( $user_id ) && ! LNKup_Document_Vault::verify_token( (int) $share['id'], $user_id, absint( $request->get_param( 'expires' ) ), (string) $request->get_param( 'token' ) ) ) {
			return new WP_Error( 'rest_forbidden', __( 'This download link has expired. Open the application again to get a new link.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function shared_file( WP_REST_Request $request ) {
		$share = LNKup_Document_Vault::get_share( absint( $request['id'] ) );
		if ( ! $share ) {
			return new WP_Error( 'not_found', __( 'Document not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return LNKup_Document_Vault::stream( (int) $share['document_id'], 'organizer_share', array( 'share_id' => (int) $share['id'], 'organizer_user_id' => get_current_user_id() ) );
	}

	/**
	 * Organizer (event owner) or staff for the application.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function application_organizer_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$app_id  = absint( $request['id'] );
		$user_id = get_current_user_id();
		if ( 'lnkup_application' !== get_post_type( $app_id ) ) {
			return new WP_Error( 'not_found', __( 'Application not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return LNKup_Capabilities::user_has_manage_all( $user_id ) || (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true ) === $user_id;
	}

	public static function application_shared_documents( WP_REST_Request $request ) {
		$app_id     = absint( $request['id'] );
		$user_id    = get_current_user_id();
		$organizer  = LNKup_Capabilities::user_has_manage_all( $user_id ) ? (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true ) : $user_id;
		$opp_id     = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true ) ?: (int) lnkup_get_vendor_profile_id( (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ) );
		$items      = LNKup_Document_Vault::shares_for_application( $organizer, $app_id );
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			foreach ( $items as &$item ) {
				$item['download_url'] = LNKup_Document_Vault::signed_url( (int) $item['id'], $user_id );
			}
		}
		return rest_ensure_response(
			array(
				'items'        => $items,
				'requirements' => $profile_id && $opp_id ? LNKup_Document_Vault::find_reusable( $profile_id, $opp_id ) : null,
				'note'         => __( 'Shared documents are provided by the vendor for this event only. Review them against your event requirements.', 'lnkup-marketplace' ),
			)
		);
	}

	/**
	 * GET vendor documents list with compliance summary.
	 */
	public static function vendor_documents() {
		$user_id    = get_current_user_id();
		$types      = LNKup_Documents::document_types();
		$compliance = LNKup_Documents::compliance_summary( $user_id );
		$by_type    = LNKup_Documents::current_by_type( $user_id );

		$core_types = array(
			'certificate_of_insurance',
			'health_permit',
			'business_license',
			'w9',
			'sales_tax_certificate',
		);

		$items = array();
		foreach ( $types as $type_key => $label ) {
			$doc         = $by_type[ $type_key ] ?? null;
			$status      = 'missing';
			$doc_id      = 0;
			$expires     = '';
			$ver_status  = '';
			$file_name   = '';
			$download    = '';
			$uploaded_at = '';
			$rejection   = '';

			if ( $doc ) {
				$doc_id     = (int) $doc->ID;
				$ver_status = (string) get_post_meta( $doc->ID, '_lnkup_verification_status', true );
				$expires    = (string) get_post_meta( $doc->ID, '_lnkup_expiration_date', true );
				$rejection  = (string) get_post_meta( $doc->ID, '_lnkup_rejection_reason', true );
				$uploaded_at = get_post_time( 'c', true, $doc );
				$attachment_id = (int) get_post_meta( $doc->ID, '_lnkup_attachment_id', true );
				if ( $attachment_id ) {
					$file_name = basename( (string) get_attached_file( $attachment_id ) );
					$download  = rest_url( self::NS . '/documents/' . $doc_id . '/file' );
				}
				$status = self::map_document_status( $ver_status, $expires );
			}

			$items[] = array(
				'id'               => $doc_id,
				'type'             => $type_key,
				'label'            => $label,
				'status'           => $status,
				'verification'     => $ver_status,
				'required'         => in_array( $type_key, $core_types, true ),
				'file_name'        => $file_name,
				'uploaded_at'      => $uploaded_at,
				'expires_at'       => $expires,
				'rejection_reason' => $rejection,
				'download_url'     => $download,
			);
		}

		return rest_ensure_response(
			array(
				'items'      => $items,
				'compliance' => $compliance,
				'total'      => count( $items ),
			)
		);
	}

	/**
	 * POST multipart document upload.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function upload_document( WP_REST_Request $request ) {
		if ( ! current_user_can( 'lnkup_manage_own_documents' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		$params = $request->get_params();
		$type   = sanitize_text_field( (string) ( $params['document_type'] ?? '' ) );
		$exp    = sanitize_text_field( (string) ( $params['expiration_date'] ?? '' ) );
		$files  = $request->get_file_params();
		$file   = $files['file'] ?? $files['lnkup_document'] ?? null;

		if ( empty( $file ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', __( 'No file selected.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$allowed_types = array_keys( LNKup_Documents::document_types() );
		if ( ! in_array( $type, $allowed_types, true ) ) {
			return new WP_Error( 'invalid_type', __( 'Invalid document type.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$_FILES['lnkup_document'] = $file;
		$result = LNKup_Documents::upload(
			$_FILES['lnkup_document'],
			get_current_user_id(),
			$type,
			$exp,
			array(
				'replaces'        => absint( $params['replaces'] ?? 0 ),
				'display_name'    => (string) ( $params['display_name'] ?? '' ),
				'insured_name'    => (string) ( $params['insured_name'] ?? '' ),
				'coverage_amount' => (string) ( $params['coverage_amount'] ?? '' ),
			)
		);
		unset( $_FILES['lnkup_document'] );

		if ( is_wp_error( $result ) ) {
			$status = 400;
			$data   = $result->get_error_data();
			if ( is_array( $data ) && ! empty( $data['status'] ) ) {
				$status = (int) $data['status'];
			}
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}

		return rest_ensure_response(
			array(
				'success'     => true,
				'document_id' => (int) $result,
				'message'     => __( 'Document uploaded.', 'lnkup-marketplace' ),
			)
		);
	}

	/**
	 * Stream document file for authenticated Basic-auth / Application Password clients.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function download_file( WP_REST_Request $request ) {
		$doc_id = absint( $request['id'] );
		$post   = get_post( $doc_id );
		if ( ! $post || 'lnkup_document' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Document not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return LNKup_Document_Vault::stream( $doc_id, 'rest' );
	}

	/**
	 * Permission: document owner, document verifier, or manage-all.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function document_file_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$doc_id    = absint( $request['id'] );
		$vendor_id = (int) get_post_meta( $doc_id, '_lnkup_vendor_user_id', true );
		$user_id   = get_current_user_id();
		if ( $user_id === $vendor_id ) {
			return true;
		}
		return current_user_can( 'lnkup_verify_documents' ) || LNKup_Capabilities::user_has_manage_all();
	}

	/**
	 * Map verification + expiration to API status.
	 *
	 * @param string $ver_status Verification status meta.
	 * @param string $expires    Expiration date.
	 */
	private static function map_document_status( $ver_status, $expires ) {
		if ( 'rejected' === $ver_status ) {
			return 'rejected';
		}
		if ( 'approved' === $ver_status ) {
			if ( LNKup_Documents::is_expired( (string) $expires ) ) {
				return 'expired';
			}
			if ( $expires && strtotime( $expires ) <= strtotime( '+30 days' ) ) {
				return 'expiring';
			}
			return 'approved';
		}
		if ( 'pending' === $ver_status || $ver_status ) {
			return 'pending';
		}
		return 'unknown';
	}

	/**
	 * Vendor permission callback.
	 */
	public static function vendor_permission() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return LNKup_Roles::is_vendor() || LNKup_Capabilities::user_has_manage_all();
	}
}
