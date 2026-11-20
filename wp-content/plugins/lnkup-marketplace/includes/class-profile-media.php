<?php
/**
 * Store a profile logo uploaded from the mobile app.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Profile_Media {

	/**
	 * Create an image attachment from a REST file param.
	 *
	 * @param array<string, mixed> $file      $_FILES-shaped array.
	 * @param int                  $parent_id Optional parent post.
	 * @return int|WP_Error Attachment ID.
	 */
	public static function store_image( array $file, int $parent_id = 0 ) {
		if ( empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', __( 'Choose an image to upload.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$checked = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) ( $file['name'] ?? 'logo.jpg' ) );
		$ext     = isset( $checked['ext'] ) ? (string) $checked['ext'] : '';
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
			return new WP_Error( 'invalid_type', __( 'Upload a JPG, PNG, GIF, or WebP image.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_sideload( $file, $parent_id );
		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( $attachment_id->get_error_code(), $attachment_id->get_error_message(), array( 'status' => 400 ) );
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error( 'invalid_type', __( 'Upload a JPG, PNG, GIF, or WebP image.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		return (int) $attachment_id;
	}

	/**
	 * First uploaded file from a REST request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|null
	 */
	public static function file_from_request( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = $files['file'] ?? $files['logo'] ?? null;
		return is_array( $file ) ? $file : null;
	}
}
