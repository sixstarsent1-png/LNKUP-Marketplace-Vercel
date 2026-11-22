<?php
/**
 * Immutable financial audit log.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Audit {

	/**
	 * Prefix marking a hashed (not raw) value in the legacy ip_address column.
	 */
	const IP_HASH_PREFIX = 'h:';

	/**
	 * Append audit record.
	 *
	 * @param string $action      Action slug.
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Entity ID.
	 * @param array  $args        Optional amount, metadata, actor.
	 */
	public static function log( $action, $entity_type, $entity_id, $args = array() ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::audit_table();
		$now   = current_time( 'mysql' );
		if ( empty( $args['metadata'] ) ) {
			// Callers pass context (breakdowns, sources, reasons) as top-level keys; keep it.
			$extra = array_diff_key( (array) $args, array_flip( array( 'amount', 'actor_user_id', 'metadata' ) ) );
			if ( $extra ) {
				$args['metadata'] = $extra;
			}
		}
		$actor   = isset( $args['actor_user_id'] ) ? absint( $args['actor_user_id'] ) : get_current_user_id();
		$ip_hash = class_exists( 'LNKup_Trust' ) ? LNKup_Trust::ip_hash() : '';
		$wpdb->insert(
			$table,
			array(
				'action'        => sanitize_key( $action ),
				'actor_user_id' => $actor,
				'entity_type'   => sanitize_key( $entity_type ),
				'entity_id'     => absint( $entity_id ),
				'amount'        => isset( $args['amount'] ) ? round( (float) $args['amount'], 2 ) : null,
				'metadata'      => ! empty( $args['metadata'] ) ? wp_json_encode( $args['metadata'] ) : null,
				'ip_address'    => self::short_hash( $ip_hash ),
				'created_at'    => $now,
			),
			array( '%s', '%d', '%s', '%d', '%f', '%s', '%s', '%s' )
		);

		if ( class_exists( 'LNKup_Audit_Log' ) ) {
			LNKup_Audit_Log::log(
				'fin_' . sanitize_key( $action ),
				$entity_type,
				$entity_id,
				array(
					'actor_user_id' => $actor,
					'new'           => array(
						'amount'   => isset( $args['amount'] ) ? round( (float) $args['amount'], 2 ) : null,
						'metadata' => $args['metadata'] ?? null,
					),
					'ip_hash'       => $ip_hash,
				)
			);
		}
	}

	/**
	 * Fit a keyed IP hash into the legacy varchar(45) column.
	 *
	 * @param string $ip_hash Full hex HMAC.
	 */
	public static function short_hash( $ip_hash ) {
		return '' === (string) $ip_hash ? '' : self::IP_HASH_PREFIX . substr( (string) $ip_hash, 0, 40 );
	}

	/**
	 * One-time migration: replace stored raw IP addresses with keyed hashes.
	 *
	 * @param int $batch Rows per run.
	 * @return int Rows updated this run.
	 */
	public static function migrate_hash_ip_addresses( $batch = 500 ) {
		global $wpdb;
		if ( get_option( 'lnkup_fin_audit_ip_hashed_v1' ) || ! class_exists( 'LNKup_Trust' ) ) {
			return 0;
		}
		$table = LNKup_Financial_Tables::audit_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, ip_address FROM {$table} WHERE ip_address <> '' AND ip_address NOT LIKE %s LIMIT %d",
				$wpdb->esc_like( self::IP_HASH_PREFIX ) . '%',
				absint( $batch )
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'ip_address' => self::short_hash( LNKup_Trust::ip_hash( (string) $row['ip_address'] ) ) ),
				array( 'id' => (int) $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);
		}
		if ( count( (array) $rows ) < $batch ) {
			update_option( 'lnkup_fin_audit_ip_hashed_v1', current_time( 'mysql', true ), false );
		}
		return count( (array) $rows );
	}

	/**
	 * List audit entries.
	 *
	 * @param array $args Query args.
	 */
	public static function list_entries( $args = array() ) {
		global $wpdb;
		$table  = LNKup_Financial_Tables::audit_table();
		$limit  = min( 100, max( 1, absint( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, absint( $args['offset'] ?? 0 ) );
		$where  = array( '1=1' );
		$vals   = array();

		if ( ! empty( $args['entity_type'] ) ) {
			$where[] = 'entity_type = %s';
			$vals[]  = sanitize_key( $args['entity_type'] );
		}
		if ( ! empty( $args['entity_id'] ) ) {
			$where[] = 'entity_id = %d';
			$vals[]  = absint( $args['entity_id'] );
		}
		if ( ! empty( $args['action'] ) ) {
			$where[] = 'action = %s';
			$vals[]  = sanitize_key( $args['action'] );
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$vals[] = $limit;
		$vals[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A );
	}
}
