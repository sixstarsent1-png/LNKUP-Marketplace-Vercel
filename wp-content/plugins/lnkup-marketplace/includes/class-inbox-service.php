<?php
/**
 * Server inbox + mark-read for web and native clients.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Inbox_Service {

	const ACTION_TYPES = array(
		'payment_required',
		'payment_due_soon',
		'document_missing',
		'document_expiring',
		'review_requested',
		'organizer_new_vendor_request',
		'organizer_vendor_missing_documents',
		'organizer_event_needs_attention',
	);

	/**
	 * Persist or refresh an inbox row (idempotent on user_id + client_id).
	 *
	 * @param int   $user_id Recipient.
	 * @param array $args    client_id, type, title, message, href, role, severity, payload.
	 * @return int|WP_Error Row ID.
	 */
	public static function upsert( $user_id, $args ) {
		global $wpdb;

		$user_id   = absint( $user_id );
		$client_id = self::sanitize_client_id( $args['client_id'] ?? '' );
		if ( $user_id < 1 || '' === $client_id ) {
			return new WP_Error(
				'lnkup_inbox_invalid',
				__( 'Inbox item is missing a user or id.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}

		self::ensure_table();
		$table   = LNKup_Inbox_Tables::index_table();
		$now     = current_time( 'mysql' );
		$type    = sanitize_key( $args['type'] ?? 'system' );
		$title   = sanitize_text_field( $args['title'] ?? '' );
		$message = sanitize_textarea_field( $args['message'] ?? '' );
		$href    = esc_url_raw( $args['href'] ?? '' );
		$role    = sanitize_key( $args['role'] ?? '' );
		$severity = sanitize_key( $args['severity'] ?? 'info' );
		$action   = ! empty( $args['action_required'] ) || in_array( $type, self::ACTION_TYPES, true ) ? 1 : 0;
		$payload  = wp_json_encode( is_array( $args['payload'] ?? null ) ? $args['payload'] : array() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, read_at FROM {$table} WHERE user_id = %d AND client_id = %s",
				$user_id,
				$client_id
			),
			ARRAY_A
		);

		if ( $existing ) {
			$update = array(
				'notification_type' => $type,
				'role'              => $role,
				'title'             => $title,
				'message'           => $message,
				'href'              => $href,
				'severity'          => $severity,
				'action_required'   => $action,
				'payload'           => $payload,
				'updated_at'        => $now,
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $update, array( 'id' => (int) $existing['id'] ) );
			return (int) $existing['id'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'user_id'           => $user_id,
				'client_id'         => $client_id,
				'notification_type' => $type,
				'role'              => $role,
				'title'             => $title,
				'message'           => $message,
				'href'              => $href,
				'severity'          => $severity,
				'action_required'   => $action,
				'payload'           => $payload,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Record a push/email lifecycle event onto the inbox.
	 *
	 * @param int    $user_id User ID.
	 * @param string $title   Title.
	 * @param string $body    Body.
	 * @param array  $data    Push payload (type, href, applicationId, …).
	 */
	public static function record_from_push( $user_id, $title, $body, $data = array() ) {
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$type      = sanitize_key( $data['type'] ?? 'system' );
		$client_id = self::client_id_from_push( $type, $data, $title, $body );
		$role      = self::role_from_type( $type );
		self::upsert(
			$user_id,
			array(
				'client_id'       => $client_id,
				'type'            => $type,
				'title'           => $title,
				'message'         => $body,
				'href'            => $data['href'] ?? '',
				'role'            => $role,
				'severity'        => self::severity_from_type( $type ),
				'action_required' => in_array( $type, self::ACTION_TYPES, true ),
				'payload'         => $data,
			)
		);
	}

	/**
	 * Mark one item read (creates a read stub if the compose id was never persisted).
	 *
	 * @param int    $user_id   User ID.
	 * @param string $client_id Compose / inbox id.
	 * @return true|WP_Error
	 */
	public static function mark_read( $user_id, $client_id ) {
		global $wpdb;
		$user_id   = absint( $user_id );
		$client_id = self::sanitize_client_id( $client_id );
		if ( $user_id < 1 || '' === $client_id ) {
			return new WP_Error(
				'lnkup_inbox_invalid',
				__( 'Invalid notification.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}

		self::ensure_table();
		$table = LNKup_Inbox_Tables::index_table();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND client_id = %s",
				$user_id,
				$client_id
			)
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'read_at'    => $now,
					'updated_at' => $now,
				),
				array( 'id' => (int) $existing )
			);
			return true;
		}

		$upsert = self::upsert(
			$user_id,
			array(
				'client_id' => $client_id,
				'type'      => 'system',
				'title'     => '',
				'message'   => '',
			)
		);
		if ( is_wp_error( $upsert ) ) {
			return $upsert;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'read_at'    => $now,
				'updated_at' => $now,
			),
			array( 'id' => (int) $upsert )
		);

		return true;
	}

	/**
	 * Mark many (or all unread) items read.
	 *
	 * @param int        $user_id    User ID.
	 * @param string[]   $client_ids Optional compose ids.
	 * @param string     $role       Optional role filter when marking all.
	 * @return true|WP_Error
	 */
	public static function mark_all_read( $user_id, $client_ids = array(), $role = '' ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return new WP_Error(
				'lnkup_inbox_invalid',
				__( 'Invalid user.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}

		$ids = array();
		if ( is_array( $client_ids ) ) {
			foreach ( $client_ids as $id ) {
				$clean = self::sanitize_client_id( $id );
				if ( '' !== $clean ) {
					$ids[] = $clean;
				}
			}
		}

		if ( ! empty( $ids ) ) {
			foreach ( $ids as $id ) {
				$result = self::mark_read( $user_id, $id );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			return true;
		}

		self::ensure_table();
		$table = LNKup_Inbox_Tables::index_table();
		$now   = current_time( 'mysql' );
		$role  = sanitize_key( $role );

		if ( $role ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET read_at = %s, updated_at = %s WHERE user_id = %d AND read_at IS NULL AND role = %s",
					$now,
					$now,
					$user_id,
					$role
				)
			);
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET read_at = %s, updated_at = %s WHERE user_id = %d AND read_at IS NULL",
				$now,
				$now,
				$user_id
			)
		);

		return true;
	}

	/**
	 * Paginated inbox items.
	 *
	 * @param int   $user_id User ID.
	 * @param array $args    filter, role, page, per_page.
	 * @return array
	 */
	public static function list_items( $user_id, $args = array() ) {
		global $wpdb;
		$user_id  = absint( $user_id );
		$filter   = sanitize_key( $args['filter'] ?? 'all' );
		$role     = sanitize_key( $args['role'] ?? '' );
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, absint( $args['per_page'] ?? 50 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		self::ensure_table();
		$table = LNKup_Inbox_Tables::index_table();
		$where = array( 'user_id = %d' );
		$params = array( $user_id );

		if ( $role ) {
			$where[]  = 'role = %s';
			$params[] = $role;
		}
		if ( 'unread' === $filter ) {
			$where[] = 'read_at IS NULL';
		} elseif ( 'action' === $filter || 'action_required' === $filter ) {
			$where[] = 'action_required = 1';
			$where[] = 'read_at IS NULL';
		}
		$where[] = "(title <> '' OR notification_type <> 'system' OR read_at IS NULL)";

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[]  = $per_page;
		$params[]  = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$items = array();
		foreach ( (array) $rows as $row ) {
			$formatted = self::format_row( $row );
			if ( $formatted ) {
				$items[] = $formatted;
			}
		}

		return array(
			'items' => $items,
			'page'  => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Unread / action counts + read id map.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Optional role.
	 * @return array
	 */
	public static function counts( $user_id, $role = '' ) {
		global $wpdb;
		$user_id = absint( $user_id );
		self::ensure_table();
		$table = LNKup_Inbox_Tables::index_table();
		$role  = sanitize_key( $role );

		if ( $role ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$unread = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND read_at IS NULL AND role = %s AND title <> ''",
					$user_id,
					$role
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$action = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND read_at IS NULL AND action_required = 1 AND role = %s",
					$user_id,
					$role
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$unread = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND read_at IS NULL AND title <> ''",
					$user_id
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$action = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND read_at IS NULL AND action_required = 1",
					$user_id
				)
			);
		}

		return array(
			'unread'         => $unread,
			'actionRequired' => $action,
			'updatedAt'      => gmdate( 'c' ),
			'readIds'        => self::read_ids( $user_id ),
		);
	}

	/**
	 * Read client_ids for merging composed feeds.
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public static function read_ids( $user_id ) {
		global $wpdb;
		self::ensure_table();
		$table = LNKup_Inbox_Tables::index_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT client_id FROM {$table} WHERE user_id = %d AND read_at IS NOT NULL",
				absint( $user_id )
			)
		);
		return array_values( array_filter( array_map( 'strval', (array) $ids ) ) );
	}

	/**
	 * Format a SQL row for REST / clients.
	 *
	 * @param array $row Row.
	 * @return array|null
	 */
	public static function format_row( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$title = (string) ( $row['title'] ?? '' );
		if ( '' === $title ) {
			return null;
		}
		$payload = array();
		if ( ! empty( $row['payload'] ) ) {
			$decoded = json_decode( (string) $row['payload'], true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}
		$created = $row['created_at'] ?? '';
		$read    = $row['read_at'] ?? null;
		return array(
			'id'             => (string) $row['client_id'],
			'type'           => (string) $row['notification_type'],
			'title'          => $title,
			'message'        => (string) ( $row['message'] ?? '' ),
			'createdAt'      => $created ? gmdate( 'c', strtotime( $created ) ) : '',
			'readAt'         => $read ? gmdate( 'c', strtotime( $read ) ) : null,
			'severity'       => (string) ( $row['severity'] ?? 'info' ),
			'role'           => (string) ( $row['role'] ?? '' ),
			'href'           => (string) ( $row['href'] ?? '' ),
			'actionRequired' => ! empty( $row['action_required'] ),
			'requestId'      => isset( $payload['applicationId'] ) ? (string) $payload['applicationId'] : '',
		);
	}

	/**
	 * Stable compose-compatible id from a push payload.
	 *
	 * @param string $type  Type.
	 * @param array  $data  Payload.
	 * @param string $title Title.
	 * @param string $body  Body.
	 * @return string
	 */
	public static function client_id_from_push( $type, $data, $title, $body ) {
		if ( ! empty( $data['client_id'] ) ) {
			return self::sanitize_client_id( $data['client_id'] );
		}
		if ( ! empty( $data['id'] ) ) {
			return self::sanitize_client_id( $data['id'] );
		}

		$app = $data['applicationId'] ?? $data['application_id'] ?? $data['appId'] ?? '';
		if ( $app ) {
			$map = array(
				'organizer_new_vendor_request' => 'org-req-',
				'payment_required'             => 'pay-req-',
				'payment_paid'                 => 'pay-paid-',
				'packet_unlocked'              => 'packet-',
				'application_rejected'         => 'app-rej-',
				'application_waitlisted'       => 'app-wait-',
				'review_requested'             => 'review-req-',
			);
			$prefix = $map[ $type ] ?? ( $type . '-' );
			return self::sanitize_client_id( $prefix . $app );
		}

		return self::sanitize_client_id( $type . '-' . substr( md5( $title . '|' . $body ), 0, 10 ) );
	}

	/**
	 * @param string $type Type.
	 * @return string
	 */
	public static function role_from_type( $type ) {
		if ( 0 === strpos( $type, 'organizer_' ) || 0 === strpos( $type, 'activation_org' ) ) {
			return 'organizer';
		}
		return 'vendor';
	}

	/**
	 * @param string $type Type.
	 * @return string
	 */
	public static function severity_from_type( $type ) {
		if ( in_array( $type, array( 'payment_required', 'document_missing', 'organizer_new_vendor_request' ), true ) ) {
			return 'urgent';
		}
		if ( in_array( $type, array( 'application_rejected', 'document_expiring' ), true ) ) {
			return 'warning';
		}
		if ( in_array( $type, array( 'payment_paid', 'packet_unlocked', 'application_approved' ), true ) ) {
			return 'success';
		}
		return 'info';
	}

	/**
	 * @param string $client_id Raw id.
	 * @return string
	 */
	public static function sanitize_client_id( $client_id ) {
		$client_id = strtolower( (string) $client_id );
		$client_id = preg_replace( '/[^a-z0-9_\-]/', '', $client_id );
		return substr( (string) $client_id, 0, 120 );
	}

	/**
	 * Ensure table exists after version bump.
	 */
	private static function ensure_table() {
		static $ready = false;
		if ( $ready || ! class_exists( 'LNKup_Inbox_Tables' ) ) {
			return;
		}
		if ( get_option( 'lnkup_inbox_table_version' ) === LNKUP_VERSION ) {
			$ready = true;
			return;
		}
		LNKup_Inbox_Tables::create_tables();
		update_option( 'lnkup_inbox_table_version', LNKUP_VERSION );
		$ready = true;
	}
}
