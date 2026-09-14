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
	}

	/**
	 * GET vendor documents list with compliance summary.
	 */
	public static function vendor_documents() {
		$user_id    = get_current_user_id();
		$types      = LNKup_Documents::document_types();
		$compliance = LNKup_Documents::compliance_summary( $user_id );
		$docs       = LNKup_Documents::get_for_vendor( $user_id );
		$by_type    = array();

		foreach ( $docs as $doc ) {
			$type = (string) get_post_meta( $doc->ID, '_lnkup_document_type', true );
			if ( ! isset( $by_type[ $type ] ) ) {
				$by_type[ $type ] = $doc;
			}
		}

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
		$result = LNKup_Documents::upload( $_FILES['lnkup_document'], get_current_user_id(), $type, $exp );
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

		$attachment_id = (int) get_post_meta( $doc_id, '_lnkup_attachment_id', true );
		$file          = $attachment_id ? get_attached_file( $attachment_id ) : '';
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'not_found', __( 'File not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}

		$mime     = get_post_mime_type( $attachment_id ) ?: 'application/octet-stream';
		$filename = basename( $file );

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $file ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file );
		exit;
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
			if ( $expires && strtotime( $expires ) < time() ) {
				return 'expired';
			}
			if ( $expires && strtotime( $expires ) <= strtotime( '+30 days' ) ) {
				return 'expired';
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
