<?php
/**
 * Immutable financial audit log.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Audit {

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
		$wpdb->insert(
			$table,
			array(
				'action'        => sanitize_key( $action ),
				'actor_user_id' => isset( $args['actor_user_id'] ) ? absint( $args['actor_user_id'] ) : get_current_user_id(),
				'entity_type'   => sanitize_key( $entity_type ),
				'entity_id'     => absint( $entity_id ),
				'amount'        => isset( $args['amount'] ) ? round( (float) $args['amount'], 2 ) : null,
				'metadata'      => ! empty( $args['metadata'] ) ? wp_json_encode( $args['metadata'] ) : null,
				'ip_address'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				'created_at'    => $now,
			),
			array( '%s', '%d', '%s', '%d', '%f', '%s', '%s', '%s' )
		);
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
