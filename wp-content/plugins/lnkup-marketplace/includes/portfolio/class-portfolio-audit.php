<?php
/**
 * Audit trail for portfolio changes (tour structure, publishing, bulk pricing, cancellations).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Portfolio_Audit {

	/**
	 * Record one change. Never throws; auditing must not block the change itself.
	 *
	 * @param string $object_type tour|event|opportunity|template.
	 * @param int    $object_id   Object ID.
	 * @param string $action      Action key.
	 * @param array  $details     Context (before/after values, counts).
	 * @param int    $organizer   Owning organizer.
	 */
	public static function log( $object_type, $object_id, $action, array $details = array(), $organizer = 0 ) {
		global $wpdb;
		try {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				LNKup_Portfolio_Tables::audit_table(),
				array(
					'actor_user_id'     => get_current_user_id(),
					'organizer_user_id' => absint( $organizer ),
					'object_type'       => sanitize_key( $object_type ),
					'object_id'         => absint( $object_id ),
					'action'            => sanitize_key( $action ),
					'details'           => wp_json_encode( $details ),
					'created_at'        => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
			);
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup portfolio audit failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Recent audit rows for a tour and its stops.
	 *
	 * @param int   $tour_id   Tour.
	 * @param int[] $event_ids Stop event IDs.
	 * @param int   $limit     Max rows.
	 */
	public static function for_tour( $tour_id, array $event_ids, $limit = 30 ) {
		global $wpdb;
		$table = LNKup_Portfolio_Tables::audit_table();
		$ids   = array_filter( array_map( 'absint', $event_ids ) );
		$where = $wpdb->prepare( "(object_type = 'tour' AND object_id = %d)", absint( $tour_id ) );
		if ( $ids ) {
			$where .= " OR (object_type = 'event' AND object_id IN (" . implode( ',', $ids ) . '))';
		}
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT " . absint( $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map(
			static function ( $row ) {
				$actor = get_userdata( (int) $row['actor_user_id'] );
				return array(
					'object_type' => $row['object_type'],
					'object_id'   => (int) $row['object_id'],
					'action'      => $row['action'],
					'actor'       => $actor ? $actor->display_name : '',
					'details'     => json_decode( (string) $row['details'], true ) ?: array(),
					'created_at'  => $row['created_at'],
				);
			},
			$rows
		);
	}
}
