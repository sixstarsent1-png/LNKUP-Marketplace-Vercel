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
			'submitted', 'under_review', 'approved', 'payment_required',
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
		$allowed = array( 'pending', 'paid', 'failed', 'refunded', 'disputed' );
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
			wp_die( esc_html__( 'Security check failed.', 'lnkup-marketplace' ), 403 );
		}
	}
}
