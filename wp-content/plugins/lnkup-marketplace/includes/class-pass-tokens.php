<?php
/**
 * Indexed lookup for vendor event-pass QR tokens.
 *
 * `_lnkup_qr_pass` on the packet stays canonical; this table mirrors it by hash so a
 * scan is a primary-key read instead of an unindexed postmeta meta_value scan.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Pass_Tokens {

	const META_KEY        = '_lnkup_qr_pass';
	const BACKFILL_CRON   = 'lnkup_pass_tokens_backfill';
	const BACKFILL_OPTION = 'lnkup_pass_tokens_backfill_v1';
	const BACKFILL_BATCH  = 300;

	public static function init() {
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_written' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_written' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta_deleted' ), 10, 3 );
		add_action( self::BACKFILL_CRON, array( __CLASS__, 'backfill_batch' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_backfill' ), 20 );
	}

	/**
	 * Token comparison has always been case-insensitive (postmeta collation), so hash the upper-cased value.
	 *
	 * @param string $token Raw token.
	 */
	public static function hash( $token ) {
		return hash( 'sha256', strtoupper( trim( (string) $token ) ) );
	}

	/**
	 * Application ID for a pass token, or 0 when the index has no entry.
	 *
	 * @param string $token Raw token.
	 */
	public static function lookup_application( $token ) {
		global $wpdb;
		if ( '' === trim( (string) $token ) || ! LNKup_Organizer_OS_Tables::ready() ) {
			return 0;
		}
		$table = LNKup_Organizer_OS_Tables::pass_tokens_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT application_id, packet_id FROM {$table} WHERE token_hash = %s", self::hash( $token ) ), ARRAY_A );
		if ( ! $row ) {
			return 0;
		}
		// Guard against a stale row: the packet must still carry this token.
		$current = (string) get_post_meta( (int) $row['packet_id'], self::META_KEY, true );
		return self::hash( $current ) === self::hash( $token ) ? (int) $row['application_id'] : 0;
	}

	/**
	 * @param int   $packet_id Packet post ID.
	 * @param mixed $token     Token value.
	 */
	public static function index( $packet_id, $token ) {
		global $wpdb;
		$token = trim( (string) $token );
		if ( '' === $token || ! LNKup_Organizer_OS_Tables::ready() ) {
			return;
		}
		$app_id = (int) get_post_meta( $packet_id, '_lnkup_application_id', true );
		$table  = LNKup_Organizer_OS_Tables::pass_tokens_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE packet_id = %d", absint( $packet_id ) ) );
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'token_hash'     => self::hash( $token ),
				'application_id' => $app_id,
				'packet_id'      => absint( $packet_id ),
				'event_id'       => $app_id ? (int) get_post_meta( $app_id, '_lnkup_event_id', true ) : 0,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value.
	 */
	public static function on_meta_written( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		if ( self::META_KEY === $meta_key ) {
			self::index( (int) $object_id, $meta_value );
		}
	}

	/**
	 * @param string[] $meta_ids  Meta IDs.
	 * @param int      $object_id Post ID.
	 * @param string   $meta_key  Meta key.
	 */
	public static function on_meta_deleted( $meta_ids, $object_id, $meta_key ) {
		global $wpdb;
		unset( $meta_ids );
		if ( self::META_KEY !== $meta_key || ! LNKup_Organizer_OS_Tables::ready() ) {
			return;
		}
		$table = LNKup_Organizer_OS_Tables::pass_tokens_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE packet_id = %d", absint( $object_id ) ) );
	}

	public static function maybe_schedule_backfill() {
		if ( get_option( self::BACKFILL_OPTION ) || ! LNKup_Organizer_OS_Tables::ready() || wp_next_scheduled( self::BACKFILL_CRON ) ) {
			return;
		}
		wp_schedule_single_event( time() + 60, self::BACKFILL_CRON );
	}

	/**
	 * Index existing tokens in batches; reschedules itself until every packet is covered.
	 */
	public static function backfill_batch() {
		global $wpdb;
		$state  = get_option( self::BACKFILL_OPTION . '_cursor', 0 );
		$cursor = absint( $state );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
				self::META_KEY,
				$cursor,
				self::BACKFILL_BATCH
			),
			ARRAY_A
		);
		$last = $cursor;
		foreach ( (array) $rows as $row ) {
			self::index( (int) $row['post_id'], (string) $row['meta_value'] );
			$last = (int) $row['meta_id'];
		}
		if ( count( (array) $rows ) < self::BACKFILL_BATCH ) {
			update_option( self::BACKFILL_OPTION, array( 'at' => current_time( 'mysql' ) ), false );
			delete_option( self::BACKFILL_OPTION . '_cursor' );
			return;
		}
		update_option( self::BACKFILL_OPTION . '_cursor', $last, false );
		wp_schedule_single_event( time() + 30, self::BACKFILL_CRON );
	}
}
