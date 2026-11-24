<?php
/**
 * Saved audience segments: a named JSON rule set (LNKup_Comms_Audiences filters), never a copy of
 * user IDs. Membership is evaluated when a campaign previews or sends.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Segments {

	public static function table(): string {
		return LNKup_Comms_Schema::table( 'segments' );
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['rules'] = LNKup_Comms_Audiences::clean_filters( (array) ( json_decode( (string) $row['rules'], true ) ?: array() ) );
		return $row;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function list( bool $include_archived = false ): array {
		global $wpdb;
		$where = $include_archived ? '1=1' : "status = 'active'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY id DESC LIMIT 200", ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['rules'] = LNKup_Comms_Audiences::clean_filters( (array) ( json_decode( (string) $row['rules'], true ) ?: array() ) );
		}
		return $rows;
	}

	/**
	 * Create or update. Rules are sanitized through the audience filter allowlist.
	 *
	 * @return int|WP_Error
	 */
	public static function save( array $input, int $user_id, int $id = 0 ) {
		global $wpdb;
		$name = mb_substr( sanitize_text_field( (string) ( $input['name'] ?? '' ) ), 0, 191 );
		if ( '' === $name ) {
			return new WP_Error( 'lnkup_comms_segment', __( 'Give the segment a name.', 'lnkup-communications' ), array( 'status' => 400 ) );
		}
		$rules = LNKup_Comms_Audiences::clean_filters( (array) ( $input['rules'] ?? array() ) );
		$now   = LNKup_Comms_Schema::now();
		$data  = array(
			'name'       => $name,
			'role'       => $rules['role'],
			'rules'      => wp_json_encode( $rules ),
			'updated_by' => $user_id,
			'updated_at' => $now,
		);
		if ( $id > 0 ) {
			if ( ! self::get( $id ) ) {
				return new WP_Error( 'lnkup_comms_segment', __( 'Segment not found.', 'lnkup-communications' ), array( 'status' => 404 ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( self::table(), $data, array( 'id' => $id ) );
			return $id;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( self::table(), array_merge( $data, array( 'status' => 'active', 'created_by' => $user_id, 'created_at' => $now ) ) );
		return (int) $wpdb->insert_id;
	}

	public static function archive( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( self::table(), array( 'status' => 'archived', 'updated_at' => LNKup_Comms_Schema::now() ), array( 'id' => $id ) );
	}
}
