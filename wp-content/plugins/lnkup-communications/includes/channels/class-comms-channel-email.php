<?php
/**
 * Email channel. Uses wp_mail(), so whatever transport the site already uses
 * (SMTP plugin, provider plugin) stays in charge — no second provider is added.
 *
 * "provider_accepted" means the transport accepted the message. Delivery,
 * bounce and complaint states only change when a provider webhook says so.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Channel_Email {

	/**
	 * @var string Plain-text alternative for the message being sent.
	 */
	private static $alt_body = '';

	/**
	 * @var string Last transport error.
	 */
	private static $last_error = '';

	public static function init(): void {
		add_action( 'phpmailer_init', array( __CLASS__, 'attach_alt_body' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );
	}

	/**
	 * @param PHPMailer\PHPMailer\PHPMailer $mailer Mailer.
	 */
	public static function attach_alt_body( $mailer ): void {
		if ( '' !== self::$alt_body && is_object( $mailer ) ) {
			$mailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/**
	 * @param WP_Error $error Error.
	 */
	public static function capture_error( $error ): void {
		if ( $error instanceof WP_Error ) {
			self::$last_error = substr( $error->get_error_message(), 0, 200 );
		}
	}

	/**
	 * @return array{status:string,error:string,retry:bool}
	 */
	public static function send( array $message, array $def, array $rendered, array $links, array $ctx ): array {
		unset( $ctx );
		$user = get_userdata( (int) $message['recipient_user_id'] );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return array( 'status' => 'suppressed', 'error' => 'invalid_address', 'retry' => false );
		}
		$cat         = LNKup_Comms_Registry::category( (string) $message['category'] );
		$promotional = 'promotional' === $message['priority'] || ( $cat && ! empty( $cat['promotional'] ) );
		$suppressed  = LNKup_Comms_Suppression::check_email( (string) $user->user_email, (string) $message['category'], $promotional );
		if ( '' !== $suppressed && 'critical' !== $message['priority'] ) {
			return array( 'status' => 'suppressed', 'error' => $suppressed, 'retry' => false );
		}
		if ( 0 === strpos( $suppressed, 'suppressed_bounce' ) ) {
			return array( 'status' => 'suppressed', 'error' => $suppressed, 'retry' => false );
		}

		$transactional = ! $promotional && ! empty( $message['transactional'] );
		$unsubscribe   = $transactional ? '' : LNKup_Comms_Suppression::unsubscribe_url( (int) $user->ID, $promotional ? 'marketing' : (string) $message['category'] );
		$footer        = array(
			'prefs_url'       => LNKup_Comms_Suppression::preferences_url(),
			'unsubscribe_url' => $unsubscribe,
			'reason'          => $transactional
				? __( 'You are receiving this because it affects your LNKup account or bookings.', 'lnkup-communications' )
				: __( 'You are receiving this because of your LNKup notification settings.', 'lnkup-communications' ),
			'address'         => (string) LNKup_Comms_Settings::get( 'company_address' ),
		);
		$action_url = LNKup_Comms_Links::tracked( (int) $message['id'], 'email', (string) $links['web_url'] );
		$html       = LNKup_Comms_Templates::email_html( $rendered, $action_url, $footer );
		$text       = LNKup_Comms_Templates::email_text( $rendered, $action_url, $footer );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'X-LNKup-Message-ID: ' . (int) $message['id'],
			'X-LNKup-Category: ' . sanitize_key( (string) $message['category'] ),
		);
		if ( '' !== $unsubscribe ) {
			$headers[] = 'List-Unsubscribe: <' . esc_url_raw( $unsubscribe ) . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}

		self::$alt_body   = $text;
		self::$last_error = '';
		$sent             = wp_mail( (string) $user->user_email, (string) $rendered['subject'], $html, $headers );
		self::$alt_body   = '';

		if ( ! $sent ) {
			return array( 'status' => 'failed', 'error' => '' !== self::$last_error ? self::$last_error : 'mail_transport', 'retry' => true );
		}
		return array( 'status' => 'provider_accepted', 'error' => '', 'retry' => false );
	}

	/**
	 * Send a fully rendered message to an arbitrary internal address (admin alerts, digests).
	 */
	public static function send_raw( string $to, array $rendered, string $action_url, array $footer = array() ): bool {
		if ( ! is_email( $to ) ) {
			return false;
		}
		self::$alt_body = LNKup_Comms_Templates::email_text( $rendered, $action_url, $footer );
		$sent           = wp_mail( $to, (string) $rendered['subject'], LNKup_Comms_Templates::email_html( $rendered, $action_url, $footer ), array( 'Content-Type: text/html; charset=UTF-8' ) );
		self::$alt_body = '';
		return (bool) $sent;
	}
}
