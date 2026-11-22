<?php
/**
 * Tamper-evident audit log for sensitive actions.
 *
 * Each row stores the hash of the previous row, so editing or deleting a
 * historical row breaks the chain and is detected by verify_chain().
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Audit_Log {

	const CRON_HOOK = 'lnkup_trust_audit_verify';
	const LOCK      = 'lnkup_audit_chain';

	/**
	 * Keys whose values never reach the log.
	 */
	const SENSITIVE_KEYS = array( 'password', 'pass', 'secret', 'token', 'card', 'cvc', 'cvv', 'account_number', 'routing', 'ssn', 'tax_id', 'ein', 'iban', 'body', 'message', 'file', 'content', 'api_key', 'signature' );

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'scheduled_verify' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Append an audit record.
	 *
	 * @param string $action      Action slug (e.g. organizer_verification_approved).
	 * @param string $entity_type Entity type (user, event, payment, case, document...).
	 * @param int    $entity_id   Entity ID.
	 * @param array  $args        prev, new, reason, result, actor_user_id.
	 * @return int Row ID (0 when the table is not available).
	 */
	public static function log( $action, $entity_type, $entity_id, $args = array() ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::audit_table();
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return 0;
		}

		$row = array(
			'action'        => substr( sanitize_key( $action ), 0, 64 ),
			'actor_user_id' => isset( $args['actor_user_id'] ) ? absint( $args['actor_user_id'] ) : get_current_user_id(),
			'entity_type'   => substr( sanitize_key( $entity_type ), 0, 40 ),
			'entity_id'     => absint( $entity_id ),
			'prev_state'    => isset( $args['prev'] ) ? self::encode( $args['prev'] ) : '',
			'new_state'     => isset( $args['new'] ) ? self::encode( $args['new'] ) : '',
			'reason'        => substr( sanitize_text_field( (string) ( $args['reason'] ?? '' ) ), 0, 500 ),
			'result'        => substr( sanitize_key( (string) ( $args['result'] ?? 'success' ) ), 0, 20 ),
			'request_id'    => LNKup_Trust::request_id(),
			'ip_hash'       => array_key_exists( 'ip_hash', $args ) ? (string) $args['ip_hash'] : LNKup_Trust::ip_hash(),
			'created_at'    => current_time( 'mysql', true ),
		);

		$locked = self::acquire_lock();
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$prev_hash = (string) $wpdb->get_var( "SELECT row_hash FROM {$table} ORDER BY id DESC LIMIT 1" );

			$row['prev_hash'] = $prev_hash;
			$row['row_hash']  = self::hash_row( $row, $prev_hash );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $row );
			return (int) $wpdb->insert_id;
		} finally {
			if ( $locked ) {
				self::release_lock();
			}
		}
	}

	/**
	 * Walk the chain and report the first broken link.
	 *
	 * @param int $limit Max rows to check (newest window); 0 = all.
	 * @return array{ok: bool, checked: int, broken_id: int, reason: string}
	 */
	public static function verify_chain( $limit = 0 ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::audit_table();
		$out   = array( 'ok' => true, 'checked' => 0, 'broken_id' => 0, 'reason' => '' );
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return $out;
		}

		$start_id = 0;
		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$start_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", absint( $limit ) ) );
		}

		$prev_hash = null;
		if ( $start_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$prev_hash = (string) $wpdb->get_var( $wpdb->prepare( "SELECT row_hash FROM {$table} WHERE id = %d", $start_id ) );
		}

		$last_id = $start_id;
		$batch   = 500;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $last_id, $batch ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				++$out['checked'];
				$expected_prev = null === $prev_hash ? $row['prev_hash'] : $prev_hash;
				if ( null !== $prev_hash && $row['prev_hash'] !== $prev_hash ) {
					return array_merge( $out, array( 'ok' => false, 'broken_id' => (int) $row['id'], 'reason' => 'prev_hash_mismatch' ) );
				}
				if ( self::hash_row( $row, $expected_prev ) !== $row['row_hash'] ) {
					return array_merge( $out, array( 'ok' => false, 'broken_id' => (int) $row['id'], 'reason' => 'row_hash_mismatch' ) );
				}
				$prev_hash = $row['row_hash'];
				$last_id   = (int) $row['id'];
			}
		} while ( count( (array) $rows ) === $batch );

		return $out;
	}

	/**
	 * Daily integrity check: a broken chain opens a critical trust case.
	 */
	public static function scheduled_verify() {
		$result = self::verify_chain();
		update_option( 'lnkup_audit_chain_last_check', array_merge( $result, array( 'at' => current_time( 'mysql', true ) ) ), false );
		if ( ! $result['ok'] && class_exists( 'LNKup_Trust_Cases' ) ) {
			LNKup_Trust_Cases::open(
				array(
					'case_type'    => 'audit_integrity',
					'severity'     => 'critical',
					'subject_type' => 'audit_log',
					'subject_id'   => (int) $result['broken_id'],
					'signal_key'   => 'audit_chain_broken',
					'summary'      => sprintf( 'Audit log chain broken at row %d (%s).', (int) $result['broken_id'], $result['reason'] ),
					'next_step'    => 'Compare database backups for the affected rows and identify who had write access.',
				)
			);
		}
		return $result;
	}

	/**
	 * Paginated list for the admin console.
	 *
	 * @param array $args entity_type, entity_id, action, actor, limit, offset.
	 */
	public static function list_entries( $args = array() ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::audit_table();
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return array();
		}
		$limit  = min( 200, max( 1, absint( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, absint( $args['offset'] ?? 0 ) );
		$where  = array( '1=1' );
		$vals   = array();
		foreach ( array( 'entity_type' => '%s', 'action' => '%s' ) as $col => $fmt ) {
			if ( ! empty( $args[ $col ] ) ) {
				$where[] = "{$col} = {$fmt}";
				$vals[]  = sanitize_key( $args[ $col ] );
			}
		}
		foreach ( array( 'entity_id', 'actor_user_id' ) as $col ) {
			if ( ! empty( $args[ $col ] ) ) {
				$where[] = "{$col} = %d";
				$vals[]  = absint( $args[ $col ] );
			}
		}
		$vals[] = $limit;
		$vals[] = $offset;
		$sql    = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A );
	}

	/**
	 * Redact sensitive keys recursively and cap size.
	 *
	 * @param mixed $data Data.
	 */
	public static function scrub( $data ) {
		if ( is_object( $data ) ) {
			$data = (array) $data;
		}
		if ( ! is_array( $data ) ) {
			return is_scalar( $data ) || null === $data ? $data : '';
		}
		$out = array();
		foreach ( $data as $key => $value ) {
			$lower = strtolower( (string) $key );
			foreach ( self::SENSITIVE_KEYS as $needle ) {
				if ( false !== strpos( $lower, $needle ) ) {
					$out[ $key ] = '[redacted]';
					continue 2;
				}
			}
			$out[ $key ] = self::scrub( $value );
		}
		return $out;
	}

	private static function encode( $data ) {
		$json = (string) wp_json_encode( self::scrub( $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return strlen( $json ) > 8000 ? substr( $json, 0, 8000 ) : $json;
	}

	/**
	 * Canonical hash over immutable columns.
	 *
	 * @param array  $row       Row (DB or pending insert).
	 * @param string $prev_hash Previous row hash.
	 */
	private static function hash_row( array $row, $prev_hash ) {
		$fields = array();
		foreach ( array( 'action', 'actor_user_id', 'entity_type', 'entity_id', 'prev_state', 'new_state', 'reason', 'result', 'request_id', 'ip_hash', 'created_at' ) as $col ) {
			$fields[ $col ] = (string) ( $row[ $col ] ?? '' );
		}
		return hash( 'sha256', (string) $prev_hash . '|' . wp_json_encode( $fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function acquire_lock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', self::LOCK ) );
	}

	private static function release_lock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
	}
}
