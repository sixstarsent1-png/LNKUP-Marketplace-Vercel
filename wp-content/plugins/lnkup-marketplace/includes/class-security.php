<?php
/**
 * Security helpers.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Security {

	/**
	 * Allowed document MIME types.
	 */
	public static function allowed_mimes() {
		return array(
			'pdf'  => 'application/pdf',
			'jpg|jpeg' => 'image/jpeg',
			'png'  => 'image/png',
		);
	}

	/**
	 * Filter WordPress upload mimes.
	 *
	 * @param array $mimes Mimes.
	 */
	public static function filter_upload_mimes( $mimes ) {
		return array_merge( $mimes, self::allowed_mimes() );
	}

	/**
	 * Image types allowed for profile and event photos.
	 *
	 * @return array<string, string>
	 */
	public static function profile_image_mimes() {
		return array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
		);
	}

	/**
	 * Whether an attachment is an image the user uploaded. Admins may use any image.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $user_id       User ID.
	 * @return bool
	 */
	public static function user_owns_image( $attachment_id, $user_id ) {
		$attachment_id = absint( $attachment_id );
		$user_id       = absint( $user_id );
		if ( ! $attachment_id || ! $user_id || 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return false;
		}
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return true;
		}
		return (int) get_post_field( 'post_author', $attachment_id ) === $user_id;
	}

	/**
	 * Keep only image IDs the user may attach, plus any already saved (so older images keep working).
	 *
	 * @param int[] $ids      Submitted attachment IDs.
	 * @param int   $user_id  User ID.
	 * @param int[] $existing IDs already stored.
	 * @return int[]
	 */
	public static function filter_owned_images( $ids, $user_id, $existing = array() ) {
		$existing = array_map( 'absint', (array) $existing );
		return array_values(
			array_filter(
				array_map( 'absint', (array) $ids ),
				static function ( $id ) use ( $user_id, $existing ) {
					return $id && ( in_array( $id, $existing, true ) || self::user_owns_image( $id, $user_id ) );
				}
			)
		);
	}

	/**
	 * Sanitize money value.
	 *
	 * @param mixed $value Value.
	 */
	public static function sanitize_money( $value ) {
		return round( (float) $value, 2 );
	}

	/**
	 * Sanitize application status.
	 *
	 * @param string $status Status.
	 */
	public static function sanitize_application_status( $status ) {
		$allowed = array(
			'submitted', 'under_review', 'info_requested', 'approved', 'payment_required',
			'paid', 'waitlisted', 'rejected', 'completed', 'cancelled',
		);
		return in_array( $status, $allowed, true ) ? $status : 'submitted';
	}

	/**
	 * Sanitize payment status.
	 *
	 * @param string $status Status.
	 */
	public static function sanitize_payment_status( $status ) {
		$allowed = array( 'pending', 'processing', 'paid', 'partially_refunded', 'failed', 'refunded', 'disputed' );
		return in_array( $status, $allowed, true ) ? $status : 'pending';
	}

	/**
	 * Verify nonce for LNKup actions.
	 *
	 * @param string $action Action name.
	 */
	public static function verify_nonce( $action = 'lnkup_action' ) {
		$nonce = isset( $_REQUEST['lnkup_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['lnkup_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			self::deny( __( 'Your session expired. Refresh the page and try again.', 'lnkup-marketplace' ) );
		}
	}

	/**
	 * Stop a request with 403: JSON for AJAX form posts, an HTML page for browser navigations
	 * (e.g. document downloads through admin-ajax.php).
	 *
	 * @param string $message User-facing message.
	 */
	public static function deny( $message ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( wp_doing_ajax() && 'POST' === $method ) {
			wp_send_json_error( array( 'message' => $message ), 403 );
		}
		wp_die( esc_html( $message ), 403 );
	}
}
