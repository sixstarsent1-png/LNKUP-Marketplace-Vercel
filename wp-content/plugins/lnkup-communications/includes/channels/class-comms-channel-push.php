<?php
/**
 * Expo push channel with tickets, receipts and invalid-token cleanup.
 *
 * Status vocabulary (never overstated):
 * - provider_accepted: Expo issued a ticket. NOT proof the phone received it.
 * - delivered:         Expo receipt "ok" — handed to APNs/FCM. Still not proof of display.
 * - failed:            Expo or the platform rejected it.
 *
 * Payloads carry only IDs, a route and safe text — never amounts or payment details.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Channel_Push {

	const SEND_URL    = 'https://exp.host/--/api/v2/push/send';
	const RECEIPT_URL = 'https://exp.host/--/api/v2/push/getReceipts';

	public static function init(): void {
		add_action( 'lnkup_comms_push_receipts', array( __CLASS__, 'check_receipts' ) );
	}

	private static function devices_table(): string {
		return LNKup_Push_Tables::table_name( LNKup_Push_Tables::DEVICES );
	}

	/**
	 * Active devices for a user (deduplicated by token).
	 *
	 * @return array<int, array{id:int,token:string,platform:string}>
	 */
	public static function devices( int $user_id ): array {
		global $wpdb;
		$table = self::devices_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, token, platform FROM {$table} WHERE user_id = %d AND enabled = 1 ORDER BY updated_at DESC LIMIT 10", $user_id ), ARRAY_A );
		$seen = array();
		$out  = array();
		foreach ( $rows as $row ) {
			$token = (string) $row['token'];
			if ( isset( $seen[ $token ] ) || ! self::looks_like_expo_token( $token ) ) {
				continue;
			}
			$seen[ $token ] = true;
			$out[]          = array(
				'id'       => (int) $row['id'],
				'token'    => $token,
				'platform' => (string) $row['platform'],
			);
		}
		return $out;
	}

	public static function looks_like_expo_token( string $token ): bool {
		return (bool) preg_match( '/^(ExponentPushToken|ExpoPushToken)\[[^\]]+\]$/', $token );
	}

	public static function disable_device( int $device_id, string $reason ): void {
		global $wpdb;
		if ( $device_id < 1 ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( self::devices_table(), array( 'enabled' => 0, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $device_id ) );
		LNKup_Comms_Analytics::bump( 'push_device_disabled', $reason );
	}

	private static function android_channel( array $message ): string {
		if ( ! LNKup_Comms_Settings::enabled( 'android_channels' ) ) {
			return 'default';
		}
		if ( 'promotional' === $message['priority'] ) {
			return 'opportunities';
		}
		if ( in_array( (string) $message['category'], array( 'event_ops', 'announcements' ), true ) ) {
			return 'operations';
		}
		return 'transactional';
	}

	/**
	 * Send to every active device. Records one delivery row per device.
	 *
	 * @return array{status:string,error:string,retry:bool}
	 */
	public static function send( array $message, array $def, array $rendered, array $links, array $ctx ): array {
		$user_id = (int) $message['recipient_user_id'];
		$devices = self::devices( $user_id );
		if ( array() === $devices ) {
			return array( 'status' => 'suppressed', 'error' => 'no_active_device', 'retry' => false );
		}
		$badge   = 0;
		$counts  = LNKup_Inbox_Service::counts( $user_id );
		$badge   = (int) ( $counts['unread'] ?? 0 );
		$payload = array();
		foreach ( $devices as $device ) {
			$item = array(
				'to'       => $device['token'],
				'title'    => (string) $rendered['push_title'],
				'body'     => (string) $rendered['push_body'],
				'sound'    => 'default',
				'priority' => in_array( (string) $message['priority'], array( 'critical', 'high' ), true ) ? 'high' : 'default',
				'data'     => array(
					'type'           => (string) $def['type'],
					'href'           => (string) $links['deep_link'],
					'notificationId' => (string) $message['id'],
					'category'       => (string) $message['category'],
					'applicationId'  => (string) ( (int) $ctx['application_id'] ?: '' ),
					'eventId'        => (string) ( (int) $ctx['event_id'] ?: '' ),
					'opportunityId'  => (string) ( (int) $ctx['opportunity_id'] ?: '' ),
					'tourId'         => (string) ( (int) ( $ctx['tour_id'] ?? 0 ) ?: '' ),
				),
			);
			if ( 'ios' === $device['platform'] ) {
				$item['badge'] = $badge;
			}
			if ( 'android' === $device['platform'] ) {
				$item['channelId'] = self::android_channel( $message );
			}
			$payload[] = $item;
		}

		$response = self::post( self::SEND_URL, $payload );
		if ( is_wp_error( $response ) ) {
			foreach ( $devices as $device ) {
				LNKup_Comms_Engine::record_delivery( (int) $message['id'], 'push', 'failed', '', (int) $device['id'], $response->get_error_message() );
			}
			return array( 'status' => 'failed', 'error' => 'transport:' . $response->get_error_code(), 'retry' => true );
		}

		$tickets  = is_array( $response['data'] ?? null ) ? $response['data'] : array();
		$accepted = 0;
		foreach ( $devices as $index => $device ) {
			$ticket = is_array( $tickets[ $index ] ?? null ) ? $tickets[ $index ] : array();
			if ( 'ok' === ( $ticket['status'] ?? '' ) ) {
				++$accepted;
				LNKup_Comms_Engine::record_delivery( (int) $message['id'], 'push', 'provider_accepted', (string) ( $ticket['id'] ?? '' ), (int) $device['id'] );
				continue;
			}
			$error = (string) ( $ticket['details']['error'] ?? $ticket['message'] ?? 'unknown' );
			if ( 'DeviceNotRegistered' === $error ) {
				self::disable_device( (int) $device['id'], $error );
			}
			LNKup_Comms_Engine::record_delivery( (int) $message['id'], 'push', 'failed', '', (int) $device['id'], $error );
		}
		if ( $accepted > 0 ) {
			return array( 'status' => 'provider_accepted', 'error' => '', 'retry' => false );
		}
		return array( 'status' => 'failed', 'error' => 'all_devices_rejected', 'retry' => false );
	}

	/**
	 * @param string $url  Endpoint.
	 * @param array  $body JSON body.
	 * @return array|WP_Error Decoded JSON.
	 */
	private static function post( string $url, array $body ) {
		$headers  = array(
			'Accept'          => 'application/json',
			'Accept-Encoding' => 'gzip, deflate',
			'Content-Type'    => 'application/json',
		);
		$settings = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		$token    = trim( (string) ( $settings['expo_push_access_token'] ?? '' ) );
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code || $code >= 500 ) {
			return new WP_Error( 'expo_unavailable', 'HTTP ' . $code );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'expo_bad_response', 'HTTP ' . $code );
		}
		if ( $code >= 400 ) {
			$first = is_array( $decoded['errors'][0] ?? null ) ? $decoded['errors'][0] : array();
			return new WP_Error( 'expo_rejected', (string) ( $first['code'] ?? 'HTTP ' . $code ) );
		}
		return $decoded;
	}

	/**
	 * Poll Expo receipts for tickets 15m–24h old.
	 */
	public static function check_receipts(): void {
		global $wpdb;
		$table = LNKup_Comms_Schema::table( 'deliveries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, provider_ref, device_id FROM {$table} WHERE channel = 'push' AND status = 'provider_accepted' AND provider_ref <> '' AND created_at < %s AND created_at > %s ORDER BY id ASC LIMIT 1000",
				LNKup_Comms_Schema::now( -15 * MINUTE_IN_SECONDS ),
				LNKup_Comms_Schema::now( -DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		if ( array() === $rows ) {
			return;
		}
		$by_ref = array();
		foreach ( $rows as $row ) {
			$by_ref[ (string) $row['provider_ref'] ] = $row;
		}
		foreach ( array_chunk( array_keys( $by_ref ), 300 ) as $chunk ) {
			$response = self::post( self::RECEIPT_URL, array( 'ids' => $chunk ) );
			if ( is_wp_error( $response ) ) {
				return;
			}
			$receipts = is_array( $response['data'] ?? null ) ? $response['data'] : array();
			foreach ( $receipts as $ticket_id => $receipt ) {
				$row = $by_ref[ (string) $ticket_id ] ?? null;
				if ( ! $row || ! is_array( $receipt ) ) {
					continue;
				}
				if ( 'ok' === ( $receipt['status'] ?? '' ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->update( $table, array( 'status' => 'delivered', 'updated_at' => LNKup_Comms_Schema::now() ), array( 'id' => (int) $row['id'] ) );
					continue;
				}
				$error = (string) ( $receipt['details']['error'] ?? $receipt['message'] ?? 'receipt_error' );
				if ( 'DeviceNotRegistered' === $error ) {
					self::disable_device( (int) $row['device_id'], $error );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update( $table, array( 'status' => 'failed', 'last_error' => substr( $error, 0, 255 ), 'updated_at' => LNKup_Comms_Schema::now() ), array( 'id' => (int) $row['id'] ) );
			}
		}
	}
}
