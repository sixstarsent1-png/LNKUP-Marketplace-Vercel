<?php
/**
 * Document upload and verification.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Documents {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'updated_post_meta', array( __CLASS__, 'on_verification_status_updated' ), 10, 4 );
	}

	/**
	 * Refresh vendor reputation when document verification changes.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function on_verification_status_updated( $meta_id, $post_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );
		if ( '_lnkup_verification_status' !== $meta_key ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'lnkup_document' !== $post->post_type ) {
			return;
		}
		$profile_id = (int) get_post_meta( $post_id, '_lnkup_vendor_profile_id', true );
		if ( ! $profile_id ) {
			$user_id = (int) get_post_meta( $post_id, '_lnkup_vendor_user_id', true );
			if ( $user_id ) {
				$profile_id = (int) lnkup_get_vendor_profile_id( $user_id );
			}
		}
		if ( ! $profile_id || ! class_exists( 'LNKup_Vendor_Reputation' ) ) {
			return;
		}
		LNKup_Vendor_Reputation::refresh( $profile_id );
		if ( class_exists( 'LNKup_Vendor_Badges' ) ) {
			LNKup_Vendor_Badges::evaluate_auto( $profile_id );
		}
	}

	/**
	 * Document types.
	 */
	public static function document_types() {
		return array(
			'certificate_of_insurance' => __( 'Certificate of Insurance', 'lnkup-marketplace' ),
			'health_permit'            => __( 'Health Permit', 'lnkup-marketplace' ),
			'business_license'         => __( 'Business License', 'lnkup-marketplace' ),
			'w9'                       => __( 'W9', 'lnkup-marketplace' ),
			'sales_tax_certificate'    => __( 'Sales Tax Certificate', 'lnkup-marketplace' ),
			'food_handler_permit'      => __( 'Food Handler Permit', 'lnkup-marketplace' ),
			'temporary_event_permit'   => __( 'Temporary Event Permit', 'lnkup-marketplace' ),
			'other'                    => __( 'Other', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Upload document.
	 *
	 * @param array $file       $_FILES entry.
	 * @param int   $user_id    User ID.
	 * @param string $type      Document type.
	 */
	public static function upload( $file, $user_id, $type, $expiration = '' ) {
		if ( empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', __( 'No file uploaded.', 'lnkup-marketplace' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		add_filter( 'upload_mimes', array( 'LNKup_Security', 'filter_upload_mimes' ) );
		$attachment_id = media_handle_upload( 'lnkup_document', 0 );
		remove_filter( 'upload_mimes', array( 'LNKup_Security', 'filter_upload_mimes' ) );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$profile_id = lnkup_get_vendor_profile_id( $user_id );
		$types      = self::document_types();
		$label      = $types[ $type ] ?? $type;

		$doc_id = wp_insert_post(
			array(
				'post_title'  => $label . ' — ' . wp_get_current_user()->display_name,
				'post_type'   => 'lnkup_document',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $doc_id, '_lnkup_vendor_user_id', $user_id );
		update_post_meta( $doc_id, '_lnkup_vendor_profile_id', $profile_id );
		update_post_meta( $doc_id, '_lnkup_document_type', sanitize_text_field( $type ) );
		update_post_meta( $doc_id, '_lnkup_attachment_id', $attachment_id );
		update_post_meta( $doc_id, '_lnkup_expiration_date', sanitize_text_field( $expiration ) );
		update_post_meta( $doc_id, '_lnkup_verification_status', 'pending' );

		LNKup_Notifications::send_document_pending_admin( $doc_id );

		return $doc_id;
	}

	/**
	 * Get documents for vendor.
	 *
	 * @param int $user_id User ID.
	 */
	public static function get_for_vendor( $user_id ) {
		return get_posts(
			array(
				'post_type'      => 'lnkup_document',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_vendor_user_id',
				'meta_value'     => $user_id,
			)
		);
	}

	/**
	 * Compliance summary for vendor dashboard.
	 *
	 * @param int $user_id User ID.
	 */
	public static function compliance_summary( $user_id ) {
		$core_types = array(
			'certificate_of_insurance',
			'health_permit',
			'business_license',
			'w9',
			'sales_tax_certificate',
		);
		$types      = self::document_types();
		$docs       = self::get_for_vendor( $user_id );
		$by_type    = array();

		foreach ( $docs as $doc ) {
			$type = get_post_meta( $doc->ID, '_lnkup_document_type', true );
			if ( ! isset( $by_type[ $type ] ) ) {
				$by_type[ $type ] = $doc;
			}
		}

		$verified = 0;
		$attention = 0;
		$items    = array();

		foreach ( $core_types as $type_key ) {
			$label  = $types[ $type_key ] ?? $type_key;
			$doc    = $by_type[ $type_key ] ?? null;
			$status = 'missing';
			$doc_id = 0;

			if ( $doc ) {
				$doc_id     = $doc->ID;
				$ver_status = get_post_meta( $doc->ID, '_lnkup_verification_status', true );
				$expires    = get_post_meta( $doc->ID, '_lnkup_expiration_date', true );
				if ( 'approved' === $ver_status ) {
					if ( $expires && strtotime( $expires ) <= strtotime( '+30 days' ) ) {
						$status = 'expiring';
						$attention++;
					} else {
						$status = 'verified';
						$verified++;
					}
				} else {
					$status = 'pending';
					$attention++;
				}
			} else {
				$attention++;
			}

			$items[ $type_key ] = array(
				'label'      => $label,
				'status'     => $status,
				'doc_id'     => $doc_id,
				'expires'    => $doc ? get_post_meta( $doc->ID, '_lnkup_expiration_date', true ) : '',
				'ver_status' => $doc ? get_post_meta( $doc->ID, '_lnkup_verification_status', true ) : '',
			);
		}

		return array(
			'total'           => count( $core_types ),
			'verified'        => $verified,
			'attention_count' => $attention,
			'items'           => $items,
		);
	}

	/**
	 * Secure download URL.
	 *
	 * @param int $doc_id Document ID.
	 */
	public static function download_url( $doc_id ) {
		return add_query_arg(
			array(
				'action'   => 'lnkup_download_document',
				'doc_id'   => $doc_id,
				'lnkup_nonce' => wp_create_nonce( 'lnkup_download_' . $doc_id ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Handle secure download.
	 */
	public static function handle_download() {
		$doc_id = isset( $_GET['doc_id'] ) ? absint( $_GET['doc_id'] ) : 0;
		LNKup_Security::verify_nonce( 'lnkup_download_' . $doc_id );

		$vendor_id    = (int) get_post_meta( $doc_id, '_lnkup_vendor_user_id', true );
		$current_user = get_current_user_id();

		if ( $current_user !== $vendor_id && ! current_user_can( 'lnkup_verify_documents' ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			wp_die( esc_html__( 'Access denied.', 'lnkup-marketplace' ), 403 );
		}

		$attachment_id = (int) get_post_meta( $doc_id, '_lnkup_attachment_id', true );
		$file          = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			wp_die( esc_html__( 'File not found.', 'lnkup-marketplace' ), 404 );
		}

		$mime = get_post_mime_type( $attachment_id );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
