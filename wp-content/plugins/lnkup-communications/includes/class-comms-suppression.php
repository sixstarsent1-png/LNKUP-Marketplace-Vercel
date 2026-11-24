<?php
/**
 * Unsubscribe links (RFC 8058 one-click), suppression list and provider
 * bounce/complaint webhooks.
 *
 * Emails are stored only as SHA-256 hashes in the suppression table.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Suppression {

	const QUERY_VAR = 'lnkup_unsubscribe';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_handle_unsubscribe' ), 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function hash_email( string $email ): string {
		return hash( 'sha256', strtolower( trim( $email ) ) );
	}

	// ------------------------------------------------------------------ Tokens.

	private static function sign( string $payload ): string {
		return substr( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . 'lnkup_comms_unsub' ), 0, 32 );
	}

	/**
	 * @param int    $user_id User.
	 * @param string $scope   'marketing' or a category key.
	 */
	public static function token( int $user_id, string $scope ): string {
		$payload = $user_id . '.' . sanitize_key( $scope );
		return rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ) . '.' . self::sign( $payload );
	}

	/**
	 * @return array{user_id:int,scope:string}|null
	 */
	public static function parse_token( string $token ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$decoded = base64_decode( strtr( $parts[0], '-_', '+/' ), true );
		if ( false === $decoded || ! hash_equals( self::sign( $decoded ), $parts[1] ) ) {
			return null;
		}
		$bits = explode( '.', $decoded );
		if ( 2 !== count( $bits ) || ! ctype_digit( $bits[0] ) ) {
			return null;
		}
		return array(
			'user_id' => (int) $bits[0],
			'scope'   => sanitize_key( $bits[1] ),
		);
	}

	public static function unsubscribe_url( int $user_id, string $scope ): string {
		return add_query_arg( self::QUERY_VAR, self::token( $user_id, $scope ), home_url( '/' ) );
	}

	// ------------------------------------------------------------------ Unsubscribe handling.

	public static function maybe_handle_unsubscribe(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is HMAC-signed; nonces cannot work from email clients.
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token  = sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) );
		$parsed = self::parse_token( $token );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( ! $parsed || ! get_userdata( $parsed['user_id'] ) ) {
			self::render_page( __( 'This unsubscribe link is not valid.', 'lnkup-communications' ), '' );
		}

		// RFC 8058: mail clients POST "List-Unsubscribe=One-Click" with no user interaction.
		if ( 'POST' === $method ) {
			self::apply( $parsed['user_id'], $parsed['scope'] );
			status_header( 200 );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['confirm'] ) ) {
			self::apply( $parsed['user_id'], $parsed['scope'] );
			$cat   = LNKup_Comms_Registry::category( $parsed['scope'] );
			$label = $cat ? (string) $cat['label'] : __( 'LNKup marketing emails', 'lnkup-communications' );
			/* translators: %s: category label */
			self::render_page( sprintf( __( 'You are unsubscribed from: %s.', 'lnkup-communications' ), $label ), self::preferences_url() );
		}

		$confirm = add_query_arg( array( self::QUERY_VAR => $token, 'confirm' => 1 ), home_url( '/' ) );
		self::render_page( __( 'Stop receiving these emails?', 'lnkup-communications' ), self::preferences_url(), $confirm );
	}

	public static function preferences_url(): string {
		$page = function_exists( 'lnkup_page_url_helper' ) ? (string) lnkup_page_url_helper( 'notification-settings' ) : '';
		return '' !== $page ? $page : home_url( '/notification-settings/' );
	}

	/**
	 * @param string $message     Message.
	 * @param string $prefs_url   Settings URL.
	 * @param string $confirm_url Confirmation URL (shows a button when set).
	 */
	private static function render_page( string $message, string $prefs_url, string $confirm_url = '' ): void {
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html__( 'Email preferences — LNKup', 'lnkup-communications' ) . '</title></head>';
		echo '<body style="margin:0;background:#f4f4f2;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1a1b1f;"><main style="max-width:480px;margin:64px auto;padding:32px;background:#fff;border-radius:16px;text-align:center;">';
		echo '<p style="font-size:20px;font-weight:800;margin:0 0 16px;">LNK<span style="color:#8bb81f;">up</span></p>';
		echo '<h1 style="font-size:20px;margin:0 0 20px;">' . esc_html( $message ) . '</h1>';
		if ( '' !== $confirm_url ) {
			echo '<p><a href="' . esc_url( $confirm_url ) . '" style="display:inline-block;padding:12px 24px;background:#c8f542;color:#0b0b0c;border-radius:10px;font-weight:700;text-decoration:none;">' . esc_html__( 'Unsubscribe', 'lnkup-communications' ) . '</a></p>';
		}
		if ( '' !== $prefs_url ) {
			echo '<p style="margin-top:20px;font-size:14px;"><a href="' . esc_url( $prefs_url ) . '" style="color:#1a1b1f;">' . esc_html__( 'Manage all notification settings', 'lnkup-communications' ) . '</a></p>';
		}
		echo '<p style="margin-top:20px;font-size:13px;color:#6b6c72;">' . esc_html__( 'Account, payment and event-operation emails are still sent when they affect a booking.', 'lnkup-communications' ) . '</p>';
		echo '</main></body></html>';
		exit;
	}

	/**
	 * Apply an unsubscribe: preference row + suppression entry.
	 */
	public static function apply( int $user_id, string $scope ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$categories = array();
		if ( 'marketing' === $scope ) {
			foreach ( LNKup_Comms_Registry::categories() as $key => $cat ) {
				if ( ! empty( $cat['promotional'] ) ) {
					$categories[] = array( 'key' => $key, 'email' => 'off' );
				}
			}
		} elseif ( LNKup_Comms_Registry::category( $scope ) ) {
			$categories[] = array( 'key' => $scope, 'email' => 'off' );
		}
		if ( $categories ) {
			LNKup_Comms_Preferences::update( $user_id, array( 'categories' => $categories ) );
		}
		self::add( (string) $user->user_email, 'marketing' === $scope ? 'marketing' : 'category:' . $scope, 'unsubscribe' );
		LNKup_Comms_Analytics::bump( 'unsubscribed', $scope );
		do_action( 'lnkup_comms_unsubscribed', $user_id, $scope );
	}

	public static function add( string $email, string $scope, string $reason ): void {
		global $wpdb;
		if ( ! is_email( $email ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->replace(
			LNKup_Comms_Schema::table( 'suppressions' ),
			array(
				'email_hash' => self::hash_email( $email ),
				'scope'      => substr( $scope, 0, 40 ),
				'reason'     => sanitize_key( $reason ),
				'created_at' => LNKup_Comms_Schema::now(),
			)
		);
	}

	/**
	 * @return string '' when sendable, otherwise the suppression reason.
	 */
	public static function check_email( string $email, string $category, bool $promotional ): string {
		global $wpdb;
		$table  = LNKup_Comms_Schema::table( 'suppressions' );
		$scopes = array( 'all', 'category:' . $category );
		if ( $promotional ) {
			$scopes[] = 'marketing';
		}
		$placeholders = implode( ',', array_fill( 0, count( $scopes ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$reason = $wpdb->get_var( $wpdb->prepare( "SELECT reason FROM {$table} WHERE email_hash = %s AND scope IN ({$placeholders}) LIMIT 1", array_merge( array( self::hash_email( $email ) ), $scopes ) ) );
		$result = is_string( $reason ) && '' !== $reason ? 'suppressed_' . $reason : '';
		/**
		 * Other suppression lists (organizer outreach) can block an address for every sender.
		 *
		 * @param string $result      '' when sendable, otherwise the reason.
		 * @param string $email       Address.
		 * @param string $category    Preference category.
		 * @param bool   $promotional Promotional message.
		 */
		return (string) apply_filters( 'lnkup_comms_email_suppressed', $result, $email, $category, $promotional );
	}

	// ------------------------------------------------------------------ Provider webhooks.

	public static function register_routes(): void {
		register_rest_route(
			'lnkup/v1',
			'/comms/email-webhook/(?P<provider>[a-z]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => array( __CLASS__, 'verify_webhook' ),
			)
		);
	}

	/**
	 * Shared-secret check (header or ?key=). Rejected when no secret is configured.
	 */
	public static function verify_webhook( WP_REST_Request $request ): bool {
		$secret = (string) LNKup_Comms_Settings::get( 'email_webhook_secret' );
		if ( '' === $secret ) {
			return false;
		}
		$given = (string) ( $request->get_header( 'x_lnkup_webhook_secret' ) ?: $request->get_param( 'key' ) );
		return '' !== $given && hash_equals( $secret, $given );
	}

	public static function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		$provider = sanitize_key( (string) $request['provider'] );
		$body     = $request->get_json_params();
		$events   = self::normalize_events( $provider, is_array( $body ) ? $body : array() );
		$handled  = 0;
		foreach ( $events as $event ) {
			if ( 'bounce' === $event['type'] ) {
				self::add( $event['email'], 'all', 'bounce' );
			} elseif ( 'complaint' === $event['type'] ) {
				self::add( $event['email'], 'marketing', 'complaint' );
			}
			if ( $event['message_id'] > 0 ) {
				LNKup_Comms_Engine::record_email_event( $event['message_id'], $event['type'] );
			}
			LNKup_Comms_Analytics::bump( 'email_' . $event['type'], $provider );
			++$handled;
		}
		return new WP_REST_Response( array( 'received' => $handled ), 200 );
	}

	/**
	 * Map common provider payload shapes to {email, type: bounce|complaint|delivered, message_id}.
	 */
	public static function normalize_events( string $provider, array $body ): array {
		$out  = array();
		$push = static function ( string $email, string $type, int $message_id = 0 ) use ( &$out ): void {
			if ( is_email( $email ) && in_array( $type, array( 'bounce', 'complaint', 'delivered' ), true ) ) {
				$out[] = array( 'email' => $email, 'type' => $type, 'message_id' => $message_id );
			}
		};
		switch ( $provider ) {
			case 'postmark':
				$record = (string) ( $body['RecordType'] ?? '' );
				$hard   = in_array( (string) ( $body['Type'] ?? '' ), array( 'HardBounce', 'BadEmailAddress', 'ManuallyDeactivated' ), true );
				$id     = (int) ( $body['Metadata']['lnkup_message_id'] ?? 0 );
				if ( 'Bounce' === $record && $hard ) {
					$push( (string) ( $body['Email'] ?? '' ), 'bounce', $id );
				} elseif ( 'SpamComplaint' === $record ) {
					$push( (string) ( $body['Email'] ?? '' ), 'complaint', $id );
				} elseif ( 'Delivery' === $record ) {
					$push( (string) ( $body['Recipient'] ?? '' ), 'delivered', $id );
				}
				break;
			case 'sendgrid':
				foreach ( $body as $event ) {
					if ( ! is_array( $event ) ) {
						continue;
					}
					$map = array( 'bounce' => 'bounce', 'dropped' => 'bounce', 'spamreport' => 'complaint', 'delivered' => 'delivered' );
					$type = $map[ (string) ( $event['event'] ?? '' ) ] ?? '';
					$push( (string) ( $event['email'] ?? '' ), $type, (int) ( $event['lnkup_message_id'] ?? 0 ) );
				}
				break;
			case 'mailgun':
				$data  = is_array( $body['event-data'] ?? null ) ? $body['event-data'] : array();
				$event = (string) ( $data['event'] ?? '' );
				$id    = (int) ( $data['user-variables']['lnkup_message_id'] ?? 0 );
				if ( 'failed' === $event && 'permanent' === ( $data['severity'] ?? '' ) ) {
					$push( (string) ( $data['recipient'] ?? '' ), 'bounce', $id );
				} elseif ( 'complained' === $event ) {
					$push( (string) ( $data['recipient'] ?? '' ), 'complaint', $id );
				} elseif ( 'delivered' === $event ) {
					$push( (string) ( $data['recipient'] ?? '' ), 'delivered', $id );
				}
				break;
			case 'ses':
				$message = isset( $body['Message'] ) && is_string( $body['Message'] ) ? json_decode( $body['Message'], true ) : $body;
				$message = is_array( $message ) ? $message : array();
				$type    = (string) ( $message['notificationType'] ?? $message['eventType'] ?? '' );
				if ( 'Bounce' === $type && 'Permanent' === ( $message['bounce']['bounceType'] ?? '' ) ) {
					foreach ( (array) ( $message['bounce']['bouncedRecipients'] ?? array() ) as $r ) {
						$push( (string) ( $r['emailAddress'] ?? '' ), 'bounce' );
					}
				} elseif ( 'Complaint' === $type ) {
					foreach ( (array) ( $message['complaint']['complainedRecipients'] ?? array() ) as $r ) {
						$push( (string) ( $r['emailAddress'] ?? '' ), 'complaint' );
					}
				}
				break;
			default:
				$events = isset( $body['email'] ) ? array( $body ) : $body;
				foreach ( $events as $event ) {
					if ( is_array( $event ) ) {
						$push( (string) ( $event['email'] ?? '' ), sanitize_key( (string) ( $event['type'] ?? '' ) ), (int) ( $event['message_id'] ?? 0 ) );
					}
				}
		}
		return $out;
	}
}
