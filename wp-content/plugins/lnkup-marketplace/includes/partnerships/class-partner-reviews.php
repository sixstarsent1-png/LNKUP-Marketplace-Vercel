<?php
/**
 * Partner review queue: attribution conflicts, paid-reward clawbacks, manual adjustments.
 *
 * Rows are deduped by `dedupe_key`, so webhook retries and repeated refunds never open a
 * second review for the same subject.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Partner_Reviews {

	const TYPES = array( 'attribution_conflict', 'clawback', 'manual_adjustment' );

	public static function table(): string {
		return LNKup_Partner_Tables::table( LNKup_Partner_Tables::REVIEWS );
	}

	/**
	 * Open (or return) the review for a dedupe key.
	 *
	 * @param array<string, mixed> $fields Columns.
	 */
	public static function open( string $type, string $dedupe_key, array $fields = array() ): int {
		global $wpdb;
		if ( ! in_array( $type, self::TYPES, true ) || ! LNKup_Partner_Tables::exists( LNKup_Partner_Tables::REVIEWS ) ) {
			return 0;
		}
		$existing = self::get_by_key( $dedupe_key );
		if ( $existing ) {
			return (int) $existing['id'];
		}
		$now = current_time( 'mysql' );
		$row = array(
			'type'             => $type,
			'dedupe_key'       => substr( $dedupe_key, 0, 96 ),
			'referred_user_id' => absint( $fields['referred_user_id'] ?? 0 ),
			'referral_ids'     => substr( implode( ',', array_map( 'absint', (array) ( $fields['referral_ids'] ?? array() ) ) ), 0, 255 ),
			'reward_id'        => absint( $fields['reward_id'] ?? 0 ),
			'payment_id'       => absint( $fields['payment_id'] ?? 0 ),
			'amount'           => round( (float) ( $fields['amount'] ?? 0 ), 2 ),
			'status'           => 'open',
			'payload'          => wp_json_encode( (array) ( $fields['payload'] ?? array() ) ),
			'notes'            => sanitize_textarea_field( (string) ( $fields['notes'] ?? '' ) ),
			'created_at'       => $now,
			'updated_at'       => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! $wpdb->insert( self::table(), $row ) ) {
			$existing = self::get_by_key( $dedupe_key );
			return $existing ? (int) $existing['id'] : 0;
		}
		$id = (int) $wpdb->insert_id;
		LNKup_Financial_Audit::log( 'partner_review_opened', 'partner_review', $id, array( 'type' => $type, 'amount' => $row['amount'] ) );
		do_action( 'lnkup_partner_review_opened', $id, $type );
		return $id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get_by_key( string $key ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE dedupe_key = %s', substr( $key, 0, 96 ) ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Merge keys into a review's payload (e.g. deferred payment IDs).
	 *
	 * @param array<string, mixed> $patch Payload patch; list values are unioned.
	 */
	public static function merge_payload( int $id, array $patch ): void {
		global $wpdb;
		$review = self::get( $id );
		if ( ! $review ) {
			return;
		}
		$payload = (array) $review['payload'];
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) ) {
				$payload[ $key ] = array_values( array_unique( array_merge( (array) ( $payload[ $key ] ?? array() ), $value ) ) );
			} else {
				$payload[ $key ] = $value;
			}
		}
		$wpdb->update( self::table(), array( 'payload' => wp_json_encode( $payload ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
	}

	/**
	 * Raise the review amount to at least $amount (clawbacks grow with later partial refunds).
	 */
	public static function raise_amount( int $id, float $amount ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET amount = GREATEST(amount, %f), updated_at = %s WHERE id = %d', round( $amount, 2 ), current_time( 'mysql' ), $id ) );
	}

	/**
	 * Close a review once. Returns false when it was already closed.
	 */
	public static function close( int $id, string $resolution, int $winner_referral_id = 0, string $notes = '', int $actor = 0 ): bool {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'resolved', resolution = %s, winner_referral_id = %d, notes = %s, resolved_by = %d, resolved_at = %s, updated_at = %s WHERE id = %d AND status = 'open'",
				sanitize_key( $resolution ),
				$winner_referral_id,
				sanitize_textarea_field( $notes ),
				$actor ?: get_current_user_id(),
				$now,
				$now,
				$id
			)
		);
		if ( ! $updated ) {
			return false;
		}
		LNKup_Financial_Audit::log( 'partner_review_resolved', 'partner_review', $id, array( 'resolution' => $resolution, 'winner_referral_id' => $winner_referral_id ) );
		return true;
	}

	/**
	 * @param array<string, mixed> $args type, status, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		if ( ! LNKup_Partner_Tables::exists( LNKup_Partner_Tables::REVIEWS ) ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['type'] ) ) {
			$where[] = 'type = %s';
			$vals[]  = sanitize_key( (string) $args['type'] );
		}
		$status  = sanitize_key( (string) ( $args['status'] ?? 'open' ) );
		if ( 'all' !== $status ) {
			$where[] = 'status = %s';
			$vals[]  = $status;
		}
		$per   = max( 1, min( 100, (int) ( $args['per_page'] ?? 25 ) ) );
		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		$sql   = ' FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $sql, $vals ) ) : $wpdb->get_var( 'SELECT COUNT(*)' . $sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT *' . $sql . ' ORDER BY created_at DESC LIMIT %d OFFSET %d', array_merge( $vals, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items' => array_map( array( __CLASS__, 'decode' ), (array) $rows ),
			'total' => $total,
		);
	}

	public static function open_count(): int {
		global $wpdb;
		if ( ! LNKup_Partner_Tables::exists( LNKup_Partner_Tables::REVIEWS ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'open'" );
	}

	/**
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function decode( array $row ): array {
		$row['payload']      = json_decode( (string) ( $row['payload'] ?? '' ), true ) ?: array();
		$row['referral_ids'] = array_values( array_filter( array_map( 'absint', explode( ',', (string) ( $row['referral_ids'] ?? '' ) ) ) ) );
		$row['id']           = (int) $row['id'];
		$row['amount']       = (float) $row['amount'];
		return $row;
	}
}
