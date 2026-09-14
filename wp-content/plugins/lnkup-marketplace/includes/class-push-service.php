<?php
/**
 * Expo push delivery service.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Push_Service {

	const META_PUSH_ENABLED = 'lnkup_push_enabled';
	const META_PUSH_CATEGORIES = 'lnkup_push_categories';
	const EXPO_PUSH_URL     = 'https://exp.host/--/api/v2/push/send';

	/**
	 * Default category toggles (all on).
	 *
	 * @return array<string, bool>
	 */
	public static function default_categories() {
		return array(
			'applications' => true,
			'payments'     => true,
			'packets'      => true,
			'discovery'    => true,
			'account'      => true,
		);
	}

	/**
	 * Whether the user wants push notifications (default true).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_push_enabled( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}
		$value = get_user_meta( $user_id, self::META_PUSH_ENABLED, true );
		if ( '' === $value || null === $value ) {
			return true;
		}
		return (bool) $value;
	}

	/**
	 * Set master push preference.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $enabled Enabled.
	 */
	public static function set_user_push_enabled( $user_id, $enabled ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return;
		}
		update_user_meta( $user_id, self::META_PUSH_ENABLED, $enabled ? 1 : 0 );
	}

	/**
	 * Get category preference map (merged with defaults).
	 *
	 * @param int $user_id User ID.
	 * @return array<string, bool>
	 */
	public static function get_user_categories( $user_id ) {
		$user_id  = absint( $user_id );
		$defaults = self::default_categories();
		if ( $user_id <= 0 ) {
			return $defaults;
		}
		$raw = get_user_meta( $user_id, self::META_PUSH_CATEGORIES, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$out = $defaults;
		foreach ( $defaults as $key => $_ ) {
			if ( array_key_exists( $key, $raw ) ) {
				$out[ $key ] = (bool) $raw[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Merge and persist category preferences.
	 *
	 * @param int   $user_id    User ID.
	 * @param array $categories Partial category map.
	 * @return array<string, bool>
	 */
	public static function set_user_categories( $user_id, $categories ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return self::default_categories();
		}
		$merged = self::get_user_categories( $user_id );
		if ( is_array( $categories ) ) {
			foreach ( self::default_categories() as $key => $_ ) {
				if ( array_key_exists( $key, $categories ) ) {
					$merged[ $key ] = (bool) $categories[ $key ];
				}
			}
		}
		update_user_meta( $user_id, self::META_PUSH_CATEGORIES, wp_json_encode( $merged ) );
		return $merged;
	}

	/**
	 * Map push data.type to preference category.
	 *
	 * @param string $type Push type.
	 * @param array  $data Full data payload (for system/href heuristics).
	 * @return string Category key.
	 */
	public static function category_for_type( $type, $data = array() ) {
		$type = (string) $type;
		$href = is_array( $data ) && isset( $data['href'] ) ? (string) $data['href'] : '';

		if ( 0 === strpos( $type, 'discovery_' ) ) {
			return 'discovery';
		}
		if ( 0 === strpos( $type, 'activation_' ) ) {
			return 'account';
		}
		if ( 0 === strpos( $type, 'application_' ) || 'payment_required' === $type || 'organizer_new_vendor_request' === $type || 'application_approved' === $type ) {
			return 'applications';
		}
		if ( in_array( $type, array( 'payment_paid', 'payment_confirmed', 'organizer_payment_received', 'organizer_vendor_paid' ), true ) ) {
			return 'payments';
		}
		if ( 'packet_unlocked' === $type ) {
			return 'packets';
		}
		if ( 'review_requested' === $type ) {
			return 'account';
		}
		if ( 'system' === $type ) {
			if ( false !== strpos( $href, '/pass/' ) ) {
				return 'packets';
			}
			return 'account';
		}
		return 'account';
	}

	/**
	 * Whether a category allows this push type for the user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    Push type.
	 * @param array  $data    Payload.
	 * @return bool
	 */
	public static function category_allows( $user_id, $type, $data = array() ) {
		$category = self::category_for_type( $type, $data );
		$cats     = self::get_user_categories( $user_id );
		return ! empty( $cats[ $category ] );
	}

	/**
	 * Register or update an Expo push token for a user.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $token       Expo push token.
	 * @param string $platform    ios|android|unknown.
	 * @param string $app_version App version string.
	 * @return true|WP_Error
	 */
	public static function register_device( $user_id, $token, $platform = 'unknown', $app_version = '' ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$token   = sanitize_text_field( $token );
		if ( $user_id <= 0 || '' === $token ) {
			return new WP_Error( 'lnkup_push_invalid', __( 'Invalid push device payload.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$platform = sanitize_key( $platform );
		if ( ! in_array( $platform, array( 'ios', 'android', 'unknown' ), true ) ) {
			$platform = 'unknown';
		}
		$app_version = sanitize_text_field( $app_version );
		$now         = current_time( 'mysql' );
		$table       = LNKup_Push_Tables::table_name( LNKup_Push_Tables::DEVICES );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, user_id FROM {$table} WHERE token = %s LIMIT 1", $token ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'user_id'     => $user_id,
					'platform'    => $platform,
					'app_version' => $app_version,
					'enabled'     => 1,
					'updated_at'  => $now,
				),
				array( 'id' => (int) $existing->id ),
				array( '%d', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'     => $user_id,
				'token'       => $token,
				'platform'    => $platform,
				'app_version' => $app_version,
				'enabled'     => 1,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'lnkup_push_register_failed', __( 'Could not register push device.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Unregister a device token for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Expo push token.
	 * @return true|WP_Error
	 */
	public static function unregister_device( $user_id, $token ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$token   = sanitize_text_field( $token );
		if ( $user_id <= 0 || '' === $token ) {
			return new WP_Error( 'lnkup_push_invalid', __( 'Invalid push device payload.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$table = LNKup_Push_Tables::table_name( LNKup_Push_Tables::DEVICES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$table,
			array(
				'user_id' => $user_id,
				'token'   => $token,
			),
			array( '%d', '%s' )
		);

		return true;
	}

	/**
	 * Notify a user via Expo Push (never throws into callers).
	 *
	 * @param int    $user_id User ID.
	 * @param string $title   Notification title.
	 * @param string $body    Notification body.
	 * @param array  $data    Extra data payload (deep links).
	 */
	public static function notify_user( $user_id, $title, $body, $data = array() ) {
		try {
			$user_id = absint( $user_id );
			if ( $user_id <= 0 || ! self::user_push_enabled( $user_id ) ) {
				return;
			}

			$data = is_array( $data ) ? $data : array();
			$type = isset( $data['type'] ) ? (string) $data['type'] : '';
			if ( $type && ! self::category_allows( $user_id, $type, $data ) ) {
				return;
			}

			$tokens = self::get_enabled_tokens( $user_id );
			if ( empty( $tokens ) ) {
				return;
			}

			$messages = array();
			foreach ( $tokens as $token ) {
				$messages[] = array(
					'to'    => $token,
					'title' => wp_strip_all_tags( (string) $title ),
					'body'  => wp_strip_all_tags( (string) $body ),
					'sound' => 'default',
					'data'  => $data,
				);
			}

			self::send_expo_messages( $messages );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup push notify_user failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Enabled Expo tokens for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public static function get_enabled_tokens( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array();
		}

		$table = LNKup_Push_Tables::table_name( LNKup_Push_Tables::DEVICES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tokens = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT token FROM {$table} WHERE user_id = %d AND enabled = 1",
				$user_id
			)
		);

		if ( ! is_array( $tokens ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $tokens ) ) );
	}

	/**
	 * POST messages to Expo Push API in chunks of 100.
	 *
	 * @param array $messages Expo message objects.
	 */
	public static function send_expo_messages( array $messages ) {
		if ( empty( $messages ) ) {
			return;
		}

		$settings = lnkup_get_settings();
		$access   = isset( $settings['expo_push_access_token'] ) ? (string) $settings['expo_push_access_token'] : '';
		$chunks   = array_chunk( $messages, 100 );

		foreach ( $chunks as $chunk ) {
			$headers = array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			);
			if ( '' !== $access ) {
				$headers['Authorization'] = 'Bearer ' . $access;
			}

			$response = wp_remote_post(
				self::EXPO_PUSH_URL,
				array(
					'timeout' => 15,
					'headers' => $headers,
					'body'    => wp_json_encode( $chunk ),
				)
			);

			if ( is_wp_error( $response ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup Expo push failed: ' . $response->get_error_message() );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup Expo push HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
			}
		}
	}
}
