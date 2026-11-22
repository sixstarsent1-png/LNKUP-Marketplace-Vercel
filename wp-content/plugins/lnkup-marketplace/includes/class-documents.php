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
	const PRIVATE_SUBDIR   = 'lnkup-private';
	const PRIVATE_META     = '_lnkup_private_document';
	const MAX_UPLOAD_BYTES = 10485760;
	const BACKFILL_OPTION  = 'lnkup_private_document_backfill_v1';

	public static function init() {
		add_action( 'updated_post_meta', array( __CLASS__, 'on_verification_status_updated' ), 10, 4 );
		add_filter( 'rest_attachment_query', array( __CLASS__, 'exclude_private_from_media_rest' ) );
		add_filter( 'rest_prepare_attachment', array( __CLASS__, 'block_private_attachment_rest' ), 10, 2 );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'exclude_private_from_media_rest' ) );
		add_action( 'template_redirect', array( __CLASS__, 'block_private_attachment_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_backfill_private_flags' ) );
	}

	/**
	 * Whether an attachment holds a vendor compliance document.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function is_private_attachment( $attachment_id ) {
		return (bool) get_post_meta( (int) $attachment_id, self::PRIVATE_META, true );
	}

	/**
	 * Keep vendor documents out of wp/v2/media and the media modal listing.
	 *
	 * @param array $args WP_Query args.
	 */
	public static function exclude_private_from_media_rest( $args ) {
		if ( LNKup_Capabilities::user_has_manage_all() ) {
			return $args;
		}
		$meta_query   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$meta_query[] = array(
			'key'     => self::PRIVATE_META,
			'compare' => 'NOT EXISTS',
		);
		$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $args;
	}

	/**
	 * Deny single-item wp/v2/media reads of vendor documents.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_Post          $post     Attachment.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function block_private_attachment_rest( $response, $post ) {
		if ( $post && self::is_private_attachment( $post->ID ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return $response;
	}

	/**
	 * 404 the public attachment page for vendor documents.
	 */
	public static function block_private_attachment_page() {
		if ( ! is_attachment() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( $id && self::is_private_attachment( $id ) && ! LNKup_Capabilities::user_has_manage_all() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * One-time flag of attachments already referenced by lnkup_document posts.
	 * Files stay at their current path; moving them is a separate migration.
	 */
	public static function maybe_backfill_private_flags() {
		if ( get_option( self::BACKFILL_OPTION ) ) {
			return;
		}
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_type = %s",
				'_lnkup_attachment_id',
				'lnkup_document'
			)
		);
		foreach ( (array) $ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( $attachment_id ) {
				update_post_meta( $attachment_id, self::PRIVATE_META, 1 );
			}
		}
		update_option( self::BACKFILL_OPTION, time(), false );
	}

	/**
	 * Route document uploads into a directory that denies direct web access.
	 *
	 * @param array $dirs wp_upload_dir() values.
	 */
	public static function private_upload_dir( $dirs ) {
		$sub            = '/' . self::PRIVATE_SUBDIR . ( $dirs['subdir'] ?? '' );
		$dirs['path']   = $dirs['basedir'] . $sub;
		$dirs['url']    = $dirs['baseurl'] . $sub;
		$dirs['subdir'] = $sub;
		self::ensure_private_dir_guard( $dirs['basedir'] . '/' . self::PRIVATE_SUBDIR );
		return $dirs;
	}

	/**
	 * Write deny rules (Apache .htaccess + index.php) into the private root.
	 *
	 * @param string $root Absolute directory path.
	 */
	private static function ensure_private_dir_guard( $root ) {
		if ( ! wp_mkdir_p( $root ) ) {
			return;
		}
		$htaccess = $root . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		$index = $root . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
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
		if ( empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_OK ) ) {
			return new WP_Error( 'no_file', __( 'No file uploaded.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$type = sanitize_key( (string) $type );
		if ( ! array_key_exists( $type, self::document_types() ) ) {
			return new WP_Error( 'invalid_type', __( 'Choose a document type.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_UPLOAD_BYTES ) {
			return new WP_Error( 'file_too_large', __( 'Documents must be under 10 MB.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$mimes = LNKup_Security::allowed_mimes();
		$check = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( (string) ( $file['name'] ?? '' ) ), $mimes );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], $mimes, true ) ) {
			return new WP_Error( 'invalid_file', __( 'Upload a PDF, JPG, or PNG file.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		add_filter( 'upload_dir', array( __CLASS__, 'private_upload_dir' ) );
		$attachment_id = media_handle_upload(
			'lnkup_document',
			0,
			array( 'post_author' => (int) $user_id ),
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);
		remove_filter( 'upload_dir', array( __CLASS__, 'private_upload_dir' ) );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		update_post_meta( $attachment_id, self::PRIVATE_META, 1 );

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
		do_action( 'lnkup_document_uploaded', (int) $doc_id, (int) $user_id );

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
