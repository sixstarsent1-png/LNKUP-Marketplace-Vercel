<?php
/**
 * Event media audit and backfill (WP-CLI) plus the shared reporting used by Media Health.
 *
 *   wp lnkup media audit [--status=publish] [--format=table|json] [--event=<id>]
 *   wp lnkup media backfill [--batch=100] [--dry-run] [--restart]
 *
 * Backfill only computes and caches the resolved media (_lnkup_media_resolved) and re-syncs the
 * marketplace index. It never deletes, replaces or reassigns uploaded images, and never changes
 * dates, pricing, applications, ownership, inventory or financial records.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Media_CLI {

	const CURSOR_OPTION = 'lnkup_media_backfill_cursor';
	/** Events the launch audit asked about by name. */
	const SPOTLIGHT = '/she rose anyway|dirty south rodeo/i';

	public static function init(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'lnkup media', 'LNKup_Event_Media_CLI_Command' );
		}
	}

	/**
	 * Event IDs after a cursor, oldest first.
	 *
	 * @param string $status   Post status (publish|any).
	 * @param int    $after_id Cursor.
	 * @param int    $limit    Batch size.
	 * @return int[]
	 */
	public static function event_ids( string $status, int $after_id, int $limit ): array {
		global $wpdb;
		$statuses = 'any' === $status ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : array( 'publish' );
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'lnkup_event' AND post_status IN ({$in}) AND ID > %d ORDER BY ID ASC LIMIT %d", array_merge( $statuses, array( $after_id, $limit ) ) ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Resolve every event (dry: nothing is written) and summarize.
	 *
	 * @param string $status Post status.
	 * @param bool   $write  Persist the cache (backfill) instead of a pure dry run.
	 * @return array{total:int,by_source:array<string,int>,by_health:array<string,int>,spotlight:array<int,array<string,mixed>>}
	 */
	public static function summarize( string $status = 'publish', bool $write = false ): array {
		$by_source = array_fill_keys( LNKup_Event_Media::SOURCES, 0 );
		$by_health = array( 'ok' => 0, 'broken' => 0, 'needs_artwork' => 0 );
		$spotlight = array();
		$total     = 0;
		$cursor    = 0;
		do {
			$ids = self::event_ids( $status, $cursor, 200 );
			LNKup_Event_Media::prime( $ids );
			foreach ( $ids as $id ) {
				$cursor = $id;
				++$total;
				$explain = LNKup_Event_Media::explain( $id );
				$media   = $write ? LNKup_Event_Media::resolve( $id ) : $explain['media'];
				$source  = (string) $media['source'];
				$by_source[ $source ] = ( $by_source[ $source ] ?? 0 ) + 1;
				$health = LNKup_Event_Media::health( $id, $media );
				++$by_health[ $health ];
				$title = (string) get_the_title( $id );
				if ( preg_match( self::SPOTLIGHT, $title ) ) {
					$spotlight[] = self::spotlight_row( $id, $title, $explain, $health );
				}
			}
		} while ( count( $ids ) === 200 );
		return array( 'total' => $total, 'by_source' => array_filter( $by_source ), 'by_health' => $by_health, 'spotlight' => $spotlight );
	}

	/**
	 * Every stored media reference for one event and why each candidate was or was not used.
	 *
	 * @param int                 $id      Event.
	 * @param string              $title   Title.
	 * @param array<string,mixed> $explain LNKup_Event_Media::explain().
	 * @param string              $health  Health.
	 * @return array<string,mixed>
	 */
	public static function spotlight_row( int $id, string $title, array $explain, string $health ): array {
		$in     = $explain['inputs'];
		$checks = array();
		foreach ( array( 'override', 'event_cover', 'event_thumbnail', 'flyer' ) as $key ) {
			$aid = (int) $in[ $key ];
			$checks[ $key ] = $aid ? ( null === LNKup_Event_Media::valid_attachment( $aid ) ? "#{$aid} invalid (missing file, not an image, or private)" : "#{$aid} valid" ) : 'not set';
		}
		$checks['gallery']         = $in['gallery'] ? implode( ',', (array) $in['gallery'] ) : 'empty';
		$checks['tour']            = $in['tour_id'] ? '#' . $in['tour_id'] . ' cover ' . ( $in['tour_cover'] ? '#' . $in['tour_cover'] : 'none' ) : 'not in a published tour';
		$checks['organizer_logo']  = $in['organizer_logo'] ? '#' . $in['organizer_logo'] : 'none';
		$checks['organizer_cover'] = $in['organizer_cover'] ? '#' . $in['organizer_cover'] : 'none';
		$checks['external_image']  = $in['external_id'] ? 'external #' . $in['external_id'] : 'not imported';
		$checks['event_types']     = implode( ', ', wp_list_pluck( (array) $in['event_types'], 'name' ) ) ?: 'none';
		return array(
			'event_id' => $id,
			'title'    => $title,
			'source'   => (string) $explain['media']['source'],
			'health'   => $health,
			'checks'   => $checks,
			'trace'    => implode( ' > ', array_map( static function ( $t ) { return $t['source'] . ':' . $t['result']; }, $explain['trace'] ) ),
		);
	}

	/**
	 * Resolve + cache a batch and re-sync its opportunities. Idempotent.
	 *
	 * @param int[] $ids Events.
	 * @return array<string,int> Count per source.
	 */
	public static function backfill_batch( array $ids ): array {
		LNKup_Event_Media::prime( $ids );
		LNKup_Event_Media::forget( $ids );
		$counts = array();
		foreach ( $ids as $id ) {
			$source            = (string) LNKup_Event_Media::resolve( (int) $id )['source'];
			$counts[ $source ] = ( $counts[ $source ] ?? 0 ) + 1;
		}
		LNKup_Event_Media::schedule_reindex_for_events( $ids );
		return $counts;
	}
}

/**
 * `wp lnkup media` commands (only these two methods become subcommands).
 */
class LNKup_Event_Media_CLI_Command {

	/**
	 * Dry-run report of how every event's image would be resolved. Writes nothing.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : publish (default) or any.
	 *
	 * [--event=<id>]
	 * : Explain one event in detail.
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @param array<int,string>    $args  Positional.
	 * @param array<string,string> $assoc Options.
	 */
	public function audit( $args, $assoc ): void {
		unset( $args );
		$format = (string) ( $assoc['format'] ?? 'table' );
		if ( ! empty( $assoc['event'] ) ) {
			$id      = absint( $assoc['event'] );
			$explain = LNKup_Event_Media::explain( $id );
			$row     = LNKup_Event_Media_CLI::spotlight_row( $id, (string) get_the_title( $id ), $explain, LNKup_Event_Media::health( $id, $explain['media'] ) );
			WP_CLI::line( (string) wp_json_encode( $row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$summary = LNKup_Event_Media_CLI::summarize( (string) ( $assoc['status'] ?? 'publish' ), false );
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		WP_CLI::line( sprintf( 'Events: %d (dry run, nothing written)', $summary['total'] ) );
		$rows = array();
		foreach ( $summary['by_source'] as $source => $count ) {
			$rows[] = array( 'source' => $source, 'events' => $count );
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'source', 'events' ) );
		WP_CLI::line( sprintf( 'Health: ok %d, broken %d, needs artwork %d', $summary['by_health']['ok'], $summary['by_health']['broken'], $summary['by_health']['needs_artwork'] ) );
		foreach ( $summary['spotlight'] as $row ) {
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( '#%d %s -> %s (%s)', $row['event_id'], $row['title'], $row['source'], $row['health'] ) );
			foreach ( $row['checks'] as $key => $value ) {
				WP_CLI::line( sprintf( '    %-16s %s', $key, $value ) );
			}
			WP_CLI::line( '    trace            ' . $row['trace'] );
		}
	}

	/**
	 * Resolve and cache media for events in resumable batches, then re-sync the index.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : Events per batch (default 100).
	 *
	 * [--dry-run]
	 * : Report only.
	 *
	 * [--restart]
	 * : Start from the first event instead of the saved cursor.
	 *
	 * @param array<int,string>    $args  Positional.
	 * @param array<string,string> $assoc Options.
	 */
	public function backfill( $args, $assoc ): void {
		unset( $args );
		if ( ! empty( $assoc['dry-run'] ) ) {
			$this->audit( array(), array( 'format' => 'table' ) );
			return;
		}
		$batch  = max( 10, min( 500, absint( $assoc['batch'] ?? 100 ) ) );
		$cursor = ! empty( $assoc['restart'] ) ? 0 : (int) get_option( LNKup_Event_Media_CLI::CURSOR_OPTION, 0 );
		$done   = 0;
		$counts = array();
		while ( true ) {
			$ids = LNKup_Event_Media_CLI::event_ids( 'any', $cursor, $batch );
			if ( array() === $ids ) {
				delete_option( LNKup_Event_Media_CLI::CURSOR_OPTION );
				break;
			}
			$result = LNKup_Event_Media_CLI::backfill_batch( $ids );
			foreach ( $result as $source => $n ) {
				$counts[ $source ] = ( $counts[ $source ] ?? 0 ) + $n;
			}
			$cursor = (int) end( $ids );
			$done  += count( $ids );
			update_option( LNKup_Event_Media_CLI::CURSOR_OPTION, $cursor, false );
			WP_CLI::log( sprintf( 'Processed %d events (cursor #%d)', $done, $cursor ) );
		}
		foreach ( $counts as $source => $n ) {
			WP_CLI::line( sprintf( '  %-18s %d', $source, $n ) );
		}
		WP_CLI::success( sprintf( 'Resolved media for %d events. No uploaded images were changed.', $done ) );
	}
}
