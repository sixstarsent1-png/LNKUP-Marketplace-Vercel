<?php
/**
 * Moves legacy vendor document files out of public uploads into the private directory.
 *
 * Batched, idempotent, and logged. Staff start it from wp-admin after reviewing a dry run.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Document_Migration {

	const CRON_HOOK     = 'lnkup_document_private_migration';
	const ENABLED_OPT   = 'lnkup_doc_private_migration_enabled';
	const LOG_OPT       = 'lnkup_doc_private_migration_log';
	const BATCH_SIZE    = 25;

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_batch' ) );
	}

	/**
	 * Attachments referenced by lnkup_document posts whose file is outside the private directory.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function find_public_files( int $limit = 500 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID AS doc_id, pm.meta_value AS attachment_id, af.meta_value AS rel_path
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_lnkup_attachment_id'
				INNER JOIN {$wpdb->postmeta} af ON af.post_id = pm.meta_value AND af.meta_key = '_wp_attached_file'
				WHERE p.post_type = 'lnkup_document' AND af.meta_value NOT LIKE %s
				ORDER BY p.ID ASC LIMIT %d",
				$wpdb->esc_like( LNKup_Documents::PRIVATE_SUBDIR . '/' ) . '%',
				max( 1, $limit )
			),
			ARRAY_A
		);
		return array_map(
			static function ( $row ) {
				return array(
					'doc_id'        => (int) $row['doc_id'],
					'attachment_id' => (int) $row['attachment_id'],
					'rel_path'      => (string) $row['rel_path'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Count of legacy public document files remaining.
	 */
	public static function remaining(): int {
		return count( self::find_public_files( 5000 ) );
	}

	/**
	 * Start the migration. Refused unless the private directory just passed the protection probe,
	 * since moving files into a publicly served folder would not make them private.
	 *
	 * @return bool Whether the migration was started.
	 */
	public static function enable(): bool {
		if ( class_exists( 'LNKup_Private_Uploads_Probe' ) ) {
			LNKup_Private_Uploads_Probe::run();
			if ( ! LNKup_Private_Uploads_Probe::is_protected() ) {
				self::log( 'blocked', LNKup_Private_Uploads_Probe::last() );
				return false;
			}
		}
		update_option( self::ENABLED_OPT, 1, false );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
		self::log( 'enabled', array( 'remaining' => self::remaining() ) );
		return true;
	}

	public static function disable(): void {
		delete_option( self::ENABLED_OPT );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		self::log( 'disabled', array() );
	}

	public static function run_scheduled_batch(): void {
		if ( ! get_option( self::ENABLED_OPT ) ) {
			return;
		}
		if ( class_exists( 'LNKup_Private_Uploads_Probe' ) && LNKup_Private_Uploads_Probe::STATUS_EXPOSED === LNKup_Private_Uploads_Probe::run()['status'] ) {
			self::disable();
			self::log( 'blocked', LNKup_Private_Uploads_Probe::last() );
			return;
		}
		$moved = self::migrate_batch( self::BATCH_SIZE );
		if ( 0 === $moved['moved'] && 0 === $moved['failed'] ) {
			self::disable();
			self::log( 'complete', array() );
		}
	}

	/**
	 * Move one batch. Each attachment's original and generated sizes are moved; metadata paths are rewritten.
	 *
	 * @param int $batch Batch size.
	 * @return array{moved:int,failed:int}
	 */
	public static function migrate_batch( int $batch ): array {
		$uploads = wp_upload_dir( null, false );
		$base    = trailingslashit( $uploads['basedir'] );
		$moved   = 0;
		$failed  = 0;
		$failed_ids = (array) get_option( self::LOG_OPT . '_failed', array() );

		foreach ( self::find_public_files( $batch + count( $failed_ids ) ) as $row ) {
			if ( $moved + $failed >= $batch ) {
				break;
			}
			if ( in_array( $row['attachment_id'], $failed_ids, true ) ) {
				continue;
			}
			$result = self::move_attachment( $row['attachment_id'], $base );
			if ( is_wp_error( $result ) ) {
				++$failed;
				$failed_ids[] = $row['attachment_id'];
				self::log( 'move_failed', array( 'doc_id' => $row['doc_id'], 'attachment_id' => $row['attachment_id'], 'error' => $result->get_error_message() ) );
				continue;
			}
			++$moved;
			self::log( 'moved', array( 'doc_id' => $row['doc_id'], 'attachment_id' => $row['attachment_id'] ) );
		}
		update_option( self::LOG_OPT . '_failed', array_slice( array_values( array_unique( $failed_ids ) ), -500 ), false );
		return array( 'moved' => $moved, 'failed' => $failed );
	}

	/**
	 * @param int    $attachment_id Attachment ID.
	 * @param string $base          Uploads base dir with trailing slash.
	 * @return true|WP_Error
	 */
	private static function move_attachment( int $attachment_id, string $base ) {
		$rel = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $rel || 0 === strpos( $rel, LNKup_Documents::PRIVATE_SUBDIR . '/' ) ) {
			return true;
		}
		$src = $base . $rel;
		if ( ! file_exists( $src ) ) {
			return new WP_Error( 'missing_file', 'Source file not found.' );
		}
		$new_rel = LNKup_Documents::PRIVATE_SUBDIR . '/' . ltrim( $rel, '/' );
		$dest    = $base . $new_rel;
		if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
			return new WP_Error( 'mkdir_failed', 'Could not create the private directory.' );
		}
		LNKup_Documents::private_upload_dir( wp_upload_dir( null, false ) );
		if ( ! @rename( $src, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return new WP_Error( 'move_failed', 'Could not move the file.' );
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) ) {
			$old_dir = trailingslashit( dirname( $src ) );
			$new_dir = trailingslashit( dirname( $dest ) );
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
				if ( empty( $size['file'] ) || ! file_exists( $old_dir . $size['file'] ) ) {
					continue;
				}
				@rename( $old_dir . $size['file'], $new_dir . $size['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			}
			if ( ! empty( $meta['file'] ) ) {
				$meta['file'] = $new_rel;
			}
			wp_update_attachment_metadata( $attachment_id, $meta );
		}
		update_post_meta( $attachment_id, '_wp_attached_file', $new_rel );
		update_post_meta( $attachment_id, LNKup_Documents::PRIVATE_META, 1 );
		return true;
	}

	/**
	 * @param string $event   Event key.
	 * @param array  $context Context.
	 */
	private static function log( string $event, array $context ): void {
		$log   = get_option( self::LOG_OPT, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'event'   => $event,
			'context' => $context,
			'at'      => current_time( 'mysql' ),
			'by'      => get_current_user_id(),
		);
		update_option( self::LOG_OPT, array_slice( $log, -200 ), false );
	}

	/**
	 * Recent log entries (newest first).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent_log( int $limit = 20 ): array {
		$log = get_option( self::LOG_OPT, array() );
		return array_slice( array_reverse( is_array( $log ) ? $log : array() ), 0, $limit );
	}
}
