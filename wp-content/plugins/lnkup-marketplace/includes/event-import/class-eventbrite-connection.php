<?php
/**
 * Eventbrite account connection (OAuth 2.0, documented v3 API only).
 *
 * Available only when the `eventbrite_oauth` flag is on and LNKup's app credentials are defined
 * in wp-config (LNKUP_EVENTBRITE_CLIENT_ID / LNKUP_EVENTBRITE_CLIENT_SECRET). Otherwise the UI
 * shows "Not connected" and URL import still works. Tokens are encrypted at rest and never leave
 * the server. Reads: /users/me/, /users/me/organizations/, /organizations/{id}/events/, /events/{id}/.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Eventbrite_Connection {

	const PROVIDER  = 'eventbrite';
	const API       = 'https://www.eventbriteapi.com/v3';
	const AUTHORIZE = 'https://www.eventbrite.com/oauth/authorize';
	const TOKEN     = 'https://www.eventbrite.com/oauth/token';
	const ACTION    = 'lnkup_eventbrite_callback';

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'callback' ) );
	}

	public static function configured(): bool {
		return defined( 'LNKUP_EVENTBRITE_CLIENT_ID' ) && '' !== (string) LNKUP_EVENTBRITE_CLIENT_ID
			&& defined( 'LNKUP_EVENTBRITE_CLIENT_SECRET' ) && '' !== (string) LNKUP_EVENTBRITE_CLIENT_SECRET;
	}

	public static function available(): bool {
		return self::configured() && LNKup_Event_OS_Flags::enabled( 'eventbrite_oauth' ) && function_exists( 'sodium_crypto_secretbox' );
	}

	public static function redirect_uri(): string {
		return add_query_arg( 'action', self::ACTION, admin_url( 'admin-post.php' ) );
	}

	/**
	 * @return string|WP_Error
	 */
	public static function connect_url( int $user_id ) {
		if ( ! self::available() ) {
			return new WP_Error( 'lnkup_eventbrite_unavailable', __( 'Eventbrite account import is not available yet. Paste event links instead.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$state = wp_generate_password( 32, false, false );
		set_transient( 'lnkup_eb_oauth_' . $user_id, $state, 15 * MINUTE_IN_SECONDS );
		return add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => rawurlencode( (string) LNKUP_EVENTBRITE_CLIENT_ID ),
				'redirect_uri'  => rawurlencode( self::redirect_uri() ),
				'state'         => $state,
			),
			self::AUTHORIZE
		);
	}

	/**
	 * OAuth redirect target. Only completes a connection the signed-in organizer started.
	 */
	public static function callback(): void {
		$user = get_current_user_id();
		$back = static function ( string $result ) {
			$args = array( 'method' => 'bulk', 'tab' => 'eventbrite', 'eventbrite' => $result );
			$url  = function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'create', $args ) : add_query_arg( array_merge( array( 'view' => 'create' ), $args ), home_url( '/organizer-dashboard/' ) );
			wp_safe_redirect( $url );
			exit;
		};
		if ( ! $user || ! LNKup_Event_Builder_REST::can_use() || ! self::available() ) {
			$back( 'unavailable' );
		}
		$expected = (string) get_transient( 'lnkup_eb_oauth_' . $user );
		delete_transient( 'lnkup_eb_oauth_' . $user );
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $expected || ( '' !== $state && ! hash_equals( $expected, $state ) ) || '' === $code ) {
			$back( 'denied' );
		}
		$res = wp_safe_remote_post(
			self::TOKEN,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'client_id'     => (string) LNKUP_EVENTBRITE_CLIENT_ID,
					'client_secret' => (string) LNKUP_EVENTBRITE_CLIENT_SECRET,
					'code'          => $code,
					'redirect_uri'  => self::redirect_uri(),
				),
			)
		);
		$data  = is_wp_error( $res ) ? array() : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$token = is_array( $data ) ? (string) ( $data['access_token'] ?? '' ) : '';
		if ( '' === $token ) {
			$back( 'failed' );
		}
		self::store( $user, $token, '' );
		$me = self::api( $user, '/users/me/' );
		if ( ! is_wp_error( $me ) ) {
			global $wpdb;
			$label = (string) ( $me['name'] ?? ( $me['emails'][0]['email'] ?? '' ) );
			$wpdb->update( LNKup_Event_Import_Tables::connections(), array( 'account_label' => mb_substr( sanitize_text_field( $label ), 0, 190 ), 'external_org_id' => sanitize_text_field( (string) ( $me['id'] ?? '' ) ) ), array( 'organizer_id' => $user, 'provider' => self::PROVIDER ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		LNKup_Event_Builder_Publisher::track( 'provider_connected', LNKup_Event_Builder_Schema::normalize( array( 'method' => 'import', 'import' => array( 'provider' => self::PROVIDER ) ) ), 0, $user );
		$back( 'connected' );
	}

	/**
	 * @return array{available:bool,configured:bool,connected:bool,account_label:string,status:string,last_sync_at:?string}
	 */
	public static function status( int $user_id ): array {
		$row = self::row( $user_id );
		return array(
			'available'     => self::available(),
			'configured'    => self::configured(),
			'connected'     => (bool) $row && 'active' === $row['status'],
			'account_label' => $row ? (string) $row['account_label'] : '',
			'status'        => $row ? (string) $row['status'] : 'not_connected',
			'last_sync_at'  => $row && $row['last_sync_at'] ? mysql_to_rfc3339( (string) $row['last_sync_at'] ) : null,
		);
	}

	public static function disconnect( int $user_id ): void {
		global $wpdb;
		$wpdb->delete( LNKup_Event_Import_Tables::connections(), array( 'organizer_id' => $user_id, 'provider' => self::PROVIDER ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		delete_transient( 'lnkup_eb_events_' . $user_id );
	}

	/**
	 * Upcoming events across the account's organizations (cached 5 minutes).
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function events( int $user_id, bool $refresh = false ) {
		$cached = $refresh ? false : get_transient( 'lnkup_eb_events_' . $user_id );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$orgs = self::api( $user_id, '/users/me/organizations/' );
		if ( is_wp_error( $orgs ) ) {
			return $orgs;
		}
		$events = array();
		foreach ( array_slice( (array) ( $orgs['organizations'] ?? array() ), 0, 5 ) as $org ) {
			$page = 1;
			do {
				$res = self::api( $user_id, '/organizations/' . rawurlencode( (string) $org['id'] ) . '/events/', array( 'status' => 'live,started,draft', 'time_filter' => 'current_future', 'expand' => 'venue', 'page_size' => 50, 'page' => $page ) );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				foreach ( (array) ( $res['events'] ?? array() ) as $ev ) {
					$events[] = self::map_event( $ev, (string) ( $org['name'] ?? '' ) );
				}
				++$page;
			} while ( ! empty( $res['pagination']['has_more_items'] ) && $page <= 4 );
		}
		usort( $events, static fn( $a, $b ) => strcmp( $a['date'] . $a['start_time'], $b['date'] . $b['start_time'] ) );
		global $wpdb;
		$wpdb->update( LNKup_Event_Import_Tables::connections(), array( 'last_sync_at' => current_time( 'mysql', true ) ), array( 'organizer_id' => $user_id, 'provider' => self::PROVIDER ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		set_transient( 'lnkup_eb_events_' . $user_id, $events, 5 * MINUTE_IN_SECONDS );
		return $events;
	}

	/**
	 * Reviewable preview for one account event (re-read from the account, never from the client).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function event_preview( int $user_id, string $event_id ): ?array {
		$events = self::events( $user_id );
		if ( is_wp_error( $events ) ) {
			return null;
		}
		foreach ( $events as $ev ) {
			if ( (string) $ev['id'] !== $event_id ) {
				continue;
			}
			$fields = array_intersect_key( $ev, array_flip( array( 'name', 'date', 'end_date', 'start_time', 'end_time', 'timezone', 'venue_name', 'address', 'city', 'state', 'zip', 'description', 'image_url' ) ) );
			$out    = LNKup_Import_Jobs::preview_from_fields( $fields, 'eventbrite', (string) $ev['url'], $user_id, $ev['online'] ? array( __( 'This is an online event on Eventbrite. Vendor booths need a physical location.', 'lnkup-marketplace' ) ) : array() );
			if ( is_wp_error( $out ) ) {
				return null;
			}
			$out['provider']    = array( 'key' => 'eventbrite', 'label' => 'Eventbrite', 'level' => 'A', 'limitations' => '' );
			$out['provenance']  = array_fill_keys( array_keys( $out['fields'] ), 'account' );
			$out['external_id'] = (string) $ev['id'];
			$out['source_mode'] = 'account';
			return $out;
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $ev Eventbrite event (expand=venue).
	 * @return array<string,mixed>
	 */
	public static function map_event( array $ev, string $org_name = '' ): array {
		$start = (string) ( $ev['start']['local'] ?? '' );
		$end   = (string) ( $ev['end']['local'] ?? '' );
		$venue = is_array( $ev['venue'] ?? null ) ? $ev['venue'] : array();
		$addr  = is_array( $venue['address'] ?? null ) ? $venue['address'] : array();
		$tz    = (string) ( $ev['start']['timezone'] ?? '' );
		return array(
			'id'          => (string) ( $ev['id'] ?? '' ),
			'name'        => mb_substr( sanitize_text_field( (string) ( $ev['name']['text'] ?? '' ) ), 0, 140 ),
			'url'         => esc_url_raw( (string) ( $ev['url'] ?? '' ) ),
			'status'      => sanitize_key( (string) ( $ev['status'] ?? '' ) ),
			'organization' => sanitize_text_field( $org_name ),
			'date'        => substr( $start, 0, 10 ),
			'start_time'  => substr( $start, 11, 5 ),
			'end_date'    => substr( $end, 0, 10 ) !== substr( $start, 0, 10 ) ? substr( $end, 0, 10 ) : '',
			'end_time'    => substr( $end, 11, 5 ),
			'timezone'    => in_array( $tz, timezone_identifiers_list(), true ) ? $tz : '',
			'venue_name'  => mb_substr( sanitize_text_field( (string) ( $venue['name'] ?? '' ) ), 0, 120 ),
			'address'     => mb_substr( sanitize_text_field( (string) ( $addr['address_1'] ?? '' ) ), 0, 160 ),
			'city'        => mb_substr( sanitize_text_field( (string) ( $addr['city'] ?? '' ) ), 0, 80 ),
			'state'       => strtoupper( mb_substr( sanitize_text_field( (string) ( $addr['region'] ?? '' ) ), 0, 40 ) ),
			'zip'         => mb_substr( sanitize_text_field( (string) ( $addr['postal_code'] ?? '' ) ), 0, 12 ),
			'description' => mb_substr( sanitize_textarea_field( (string) ( $ev['description']['text'] ?? $ev['summary'] ?? '' ) ), 0, 5000 ),
			'image_url'   => esc_url_raw( (string) ( $ev['logo']['url'] ?? '' ) ),
			'online'      => ! empty( $ev['online_event'] ),
			'series'      => ! empty( $ev['is_series'] ) || ! empty( $ev['series_id'] ),
		);
	}

	/**
	 * Authorized GET against the fixed Eventbrite API host.
	 *
	 * @param array<string,mixed> $query Query args.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function api( int $user_id, string $path, array $query = array() ) {
		$row = self::row( $user_id );
		if ( ! $row || 'active' !== $row['status'] ) {
			return new WP_Error( 'lnkup_eventbrite_disconnected', __( 'Connect your Eventbrite account first.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$token = self::decrypt( (string) $row['token'] );
		if ( '' === $token ) {
			return new WP_Error( 'lnkup_eventbrite_disconnected', __( 'Reconnect your Eventbrite account.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$res = wp_safe_remote_get( add_query_arg( $query, self::API . $path ), array( 'timeout' => 12, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'lnkup_eventbrite_unreachable', __( 'Eventbrite did not respond. Try again in a moment.', 'lnkup-marketplace' ), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 401 === $code || 403 === $code ) {
			global $wpdb;
			$wpdb->update( LNKup_Event_Import_Tables::connections(), array( 'status' => 'expired' ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return new WP_Error( 'lnkup_eventbrite_expired', __( 'Your Eventbrite connection expired. Reconnect to continue.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( 429 === $code ) {
			return new WP_Error( 'lnkup_eventbrite_rate', __( 'Eventbrite is limiting requests right now. Try again in a few minutes.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new WP_Error( 'lnkup_eventbrite_error', __( 'Eventbrite returned an error. Try again later.', 'lnkup-marketplace' ), array( 'status' => 502 ) );
		}
		return $data;
	}

	public static function store( int $user_id, string $token, string $refresh ): void {
		global $wpdb;
		$table = LNKup_Event_Import_Tables::connections();
		$now   = current_time( 'mysql', true );
		$data  = array( 'token' => self::encrypt( $token ), 'refresh_token' => '' !== $refresh ? self::encrypt( $refresh ) : '', 'status' => 'active', 'updated_at' => $now );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE organizer_id = %d AND provider = %s", $user_id, self::PROVIDER ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$wpdb->insert( $table, array_merge( $data, array( 'organizer_id' => $user_id, 'provider' => self::PROVIDER, 'created_at' => $now ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}
		delete_transient( 'lnkup_eb_events_' . $user_id );
	}

	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function decrypt( string $stored ): string {
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		return false === $plain ? '' : $plain;
	}

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|lnkup-provider-tokens', true );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function row( int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Event_Import_Tables::connections() . ' WHERE organizer_id = %d AND provider = %s', $user_id, self::PROVIDER ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}
}
