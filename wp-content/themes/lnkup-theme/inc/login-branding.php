<?php
/**
 * WordPress login / lost-password branding.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue LNKup styles on core auth screens.
 */
function lnkup_theme_login_enqueue() {
	wp_enqueue_style(
		'lnkup-login-fonts',
		'https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap',
		array(),
		null
	);
	wp_enqueue_style(
		'lnkup-login',
		LNKUP_THEME_URI . '/assets/css/login.css',
		array( 'lnkup-login-fonts', 'login' ),
		LNKUP_THEME_VERSION
	);
}
add_action( 'login_enqueue_scripts', 'lnkup_theme_login_enqueue' );

/**
 * Send the login logo to the homepage.
 */
function lnkup_theme_login_headerurl() {
	return home_url( '/' );
}
add_filter( 'login_headerurl', 'lnkup_theme_login_headerurl' );

/**
 * Login logo accessible name.
 */
function lnkup_theme_login_headertext() {
	return 'LNKup';
}
add_filter( 'login_headertext', 'lnkup_theme_login_headertext' );

/**
 * Short LNKup copy on lost-password and reset screens.
 *
 * @param string $message Existing message HTML.
 * @return string
 */
function lnkup_theme_login_message( $message ) {
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'lostpassword' === $action ) {
		return '<p class="lnkup-login-message">' . esc_html__( 'Enter your account email and we will send a reset link.', 'lnkup-theme' ) . '</p>' . $message;
	}
	if ( 'rp' === $action || 'resetpass' === $action ) {
		return '<p class="lnkup-login-message">' . esc_html__( 'Choose a new password for your LNKup account.', 'lnkup-theme' ) . '</p>' . $message;
	}
	return $message;
}
add_filter( 'login_message', 'lnkup_theme_login_message' );
