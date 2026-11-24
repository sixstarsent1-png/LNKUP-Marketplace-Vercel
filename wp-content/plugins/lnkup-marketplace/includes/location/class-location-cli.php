<?php
/**
 * WP-CLI: wp lnkup location audit|fix|rollback|runs.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Location_CLI {

	public static function init(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'lnkup location', 'LNKup_Location_CLI_Command' );
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI_Command' ) ) {

	/**
	 * Geographic data integrity: audit, dry-run, safe fixes and rollback.
	 */
	class LNKup_Location_CLI_Command extends WP_CLI_Command {

		/**
		 * List records whose location is not canonical. Read-only.
		 *
		 * ## OPTIONS
		 *
		 * [--entity=<entity>]
		 * : event, vendor_profile, organizer, market or external_event.
		 *
		 * [--format=<format>]
		 * : table, json, csv or summary.
		 * ---
		 * default: table
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp lnkup location audit --entity=event
		 *     wp lnkup location audit --format=summary
		 *
		 * @param array<int, string>    $args  Positional.
		 * @param array<string, string> $assoc Options.
		 */
		public function audit( $args, $assoc ): void {
			$rows = LNKup_Location_Migration::scan( array( 'entity' => (string) ( $assoc['entity'] ?? '' ) ) );
			$this->output( $rows, (string) ( $assoc['format'] ?? 'table' ) );
		}

		/**
		 * Record a dry run, or apply safe corrections and queue the rest for review.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Record proposals only (default when --apply is absent).
		 *
		 * [--apply]
		 * : Apply deterministic corrections; uncertain ones go to the Location Health review queue.
		 *
		 * [--entity=<entity>]
		 * : Limit to one entity type.
		 *
		 * [--yes]
		 * : Skip the confirmation prompt for --apply.
		 *
		 * ## EXAMPLES
		 *
		 *     wp lnkup location fix --dry-run
		 *     wp lnkup location fix --apply --entity=event
		 *
		 * @param array<int, string>    $args  Positional.
		 * @param array<string, string> $assoc Options.
		 */
		public function fix( $args, $assoc ): void {
			$apply = ! empty( $assoc['apply'] ) && empty( $assoc['dry-run'] );
			if ( $apply ) {
				WP_CLI::confirm( 'Apply safe location corrections? Uncertain records are queued, never changed.', $assoc );
			}
			$result = LNKup_Location_Migration::run( $apply, array( 'entity' => (string) ( $assoc['entity'] ?? '' ) ), 0 );
			WP_CLI::log( sprintf( 'Run %s: %d proposals, %d applied, %d need review%s.', $result['run_id'], $result['total'], $result['applied'], $result['review'], $result['dry_run'] ? ' (dry run, nothing changed)' : '' ) );
			$rows = LNKup_Location_Migration::list_rows( array( 'run_id' => $result['run_id'], 'limit' => 500 ) );
			$this->output( array_map( array( $this, 'row_view' ), $rows ), 'table' );
			WP_CLI::success( $result['dry_run'] ? 'Dry run recorded.' : 'Safe corrections applied. Roll back with: wp lnkup location rollback --run=' . $result['run_id'] );
		}

		/**
		 * Restore original values for one apply run (skips records edited since).
		 *
		 * ## OPTIONS
		 *
		 * --run=<run_id>
		 * : Run ID printed by "fix --apply".
		 *
		 * [--yes]
		 * : Skip confirmation.
		 *
		 * @param array<int, string>    $args  Positional.
		 * @param array<string, string> $assoc Options.
		 */
		public function rollback( $args, $assoc ): void {
			$run = sanitize_text_field( (string) ( $assoc['run'] ?? '' ) );
			if ( '' === $run ) {
				WP_CLI::error( 'Pass --run=<run_id>.' );
			}
			WP_CLI::confirm( "Restore original locations for run {$run}?", $assoc );
			$result = LNKup_Location_Migration::rollback( $run, 0 );
			WP_CLI::success( sprintf( 'Restored %d records; skipped %d edited since the run.', $result['restored'], $result['skipped'] ) );
		}

		/**
		 * Migration row counts by status.
		 *
		 * @param array<int, string>    $args  Positional.
		 * @param array<string, string> $assoc Options.
		 */
		public function runs( $args, $assoc ): void {
			$counts = LNKup_Location_Migration::counts();
			$rows   = array();
			foreach ( $counts as $status => $n ) {
				$rows[] = array( 'status' => $status, 'rows' => $n );
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'rows' ) );
		}

		/**
		 * @param array<string, mixed> $row DB row.
		 * @return array<string, mixed>
		 */
		private function row_view( array $row ): array {
			$original = json_decode( (string) $row['original'], true ) ?: array();
			$proposed = json_decode( (string) $row['proposed'], true ) ?: array();
			return array(
				'entity_type'  => $row['entity_type'],
				'entity_id'    => $row['entity_id'],
				'title'        => $original['title'] ?? '',
				'original'     => trim( ( $original['city'] ?? '' ) . ', ' . ( $original['state'] ?? '' ), ', ' ),
				'proposed'     => isset( $proposed['city'] ) ? $proposed['city'] . ', ' . ( $proposed['state'] ?? '' ) : self::suggestion_list( $proposed ),
				'confidence'   => $row['confidence'],
				'reason'       => $row['reason'],
				'related'      => $row['related_count'],
				'needs_review' => $row['needs_review'] ? 'yes' : 'no',
				'status'       => $row['status'],
			);
		}

		/**
		 * @param array<string, mixed> $proposed Proposed payload.
		 */
		private static function suggestion_list( array $proposed ): string {
			$list = array();
			foreach ( (array) ( $proposed['suggestions'] ?? array() ) as $s ) {
				$list[] = ( $s['city'] ?? '' ) . ', ' . ( $s['state'] ?? '' );
			}
			return $list ? 'choose: ' . implode( ' | ', array_slice( $list, 0, 3 ) ) : '(no match)';
		}

		/**
		 * @param array<int, array<string, mixed>> $rows   Rows (scan or view shape).
		 * @param string                           $format Format.
		 */
		private function output( array $rows, string $format ): void {
			$view = array();
			foreach ( $rows as $row ) {
				if ( isset( $row['status'] ) && isset( $row['needs_review'] ) && is_string( $row['needs_review'] ) ) {
					$view[] = $row;
					continue;
				}
				$proposed = (array) ( $row['proposed'] ?? array() );
				$view[]   = array(
					'entity_type'  => $row['entity_type'],
					'entity_id'    => $row['entity_id'],
					'title'        => $row['title'] ?? '',
					'original'     => trim( ( $row['original']['city'] ?? '' ) . ', ' . ( $row['original']['state'] ?? '' ), ', ' ),
					'proposed'     => isset( $proposed['city'] ) ? $proposed['city'] . ', ' . ( $proposed['state'] ?? '' ) : self::suggestion_list( $proposed ),
					'confidence'   => $row['confidence'],
					'reason'       => $row['reason'],
					'related'      => $row['related_count'],
					'needs_review' => ! empty( $row['needs_review'] ) ? 'yes' : 'no',
					'status'       => '',
				);
			}
			if ( 'summary' === $format ) {
				$by = array();
				foreach ( $view as $v ) {
					$key        = $v['entity_type'] . ' / ' . $v['confidence'] . ' / review=' . $v['needs_review'];
					$by[ $key ] = ( $by[ $key ] ?? 0 ) + 1;
				}
				$rows = array();
				foreach ( $by as $key => $n ) {
					$rows[] = array( 'group' => $key, 'records' => $n );
				}
				WP_CLI\Utils\format_items( 'table', $rows, array( 'group', 'records' ) );
				return;
			}
			if ( ! $view ) {
				WP_CLI::success( 'Every location is canonical.' );
				return;
			}
			WP_CLI\Utils\format_items( in_array( $format, array( 'json', 'csv' ), true ) ? $format : 'table', $view, array( 'entity_type', 'entity_id', 'title', 'original', 'proposed', 'confidence', 'reason', 'related', 'needs_review', 'status' ) );
		}
	}
}
