<?php
/**
 * Google Search ingest query queue tables.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Search_Ingest_Tables {

	const STATUS_PENDING = 'pending';
	const STATUS_RUNNING = 'running';
	const STATUS_DONE    = 'done';
	const STATUS_ERROR   = 'error';

	/**
	 * Create search ingest tables.
	 */
	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::queries_table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				query_text varchar(255) NOT NULL DEFAULT '',
				market_slug varchar(64) NOT NULL DEFAULT '',
				city varchar(100) NOT NULL DEFAULT '',
				state varchar(32) NOT NULL DEFAULT '',
				template_key varchar(64) NOT NULL DEFAULT '',
				run_date date NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'pending',
				results_found int unsigned NOT NULL DEFAULT 0,
				imported int unsigned NOT NULL DEFAULT 0,
				skipped int unsigned NOT NULL DEFAULT 0,
				last_error varchar(500) NOT NULL DEFAULT '',
				ran_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY query_run (query_text(191), run_date),
				KEY status_run (status, run_date),
				KEY market_slug (market_slug)
			) {$charset};"
		);
	}

	/**
	 * Query queue table name.
	 */
	public static function queries_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_search_ingest_queries';
	}

	/**
	 * Enqueue combinator rows for a run date (idempotent per query + date).
	 *
	 * @param array<int, array<string, mixed>> $combinations Combinator output.
	 * @param string                           $run_date     Y-m-d.
	 * @return int Rows inserted.
	 */
	public static function enqueue_combinations( array $combinations, string $run_date ): int {
		global $wpdb;

		$table = self::queries_table();
		if ( ! self::table_exists( $table ) ) {
			self::create_tables();
		}

		$now     = current_time( 'mysql' );
		$inserted = 0;

		foreach ( $combinations as $combo ) {
			$query_text = sanitize_text_field( (string) ( $combo['query_text'] ?? '' ) );
			if ( '' === $query_text ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE query_text = %s AND run_date = %s LIMIT 1",
					$query_text,
					$run_date
				)
			);
			if ( $existing ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ok = $wpdb->insert(
				$table,
				array(
					'query_text'   => $query_text,
					'market_slug'  => sanitize_key( (string) ( $combo['market_slug'] ?? '' ) ),
					'city'         => sanitize_text_field( (string) ( $combo['city'] ?? '' ) ),
					'state'        => sanitize_text_field( (string) ( $combo['state'] ?? '' ) ),
					'template_key' => sanitize_key( (string) ( $combo['template_key'] ?? '' ) ),
					'run_date'     => $run_date,
					'status'       => self::STATUS_PENDING,
					'created_at'   => $now,
					'updated_at'   => $now,
				)
			);
			if ( $ok ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Claim the next pending queries for today.
	 *
	 * @param int    $limit    Max rows.
	 * @param string $run_date Y-m-d.
	 * @return array<int, array<string, mixed>>
	 */
	public static function claim_next( int $limit, string $run_date ): array {
		global $wpdb;

		$table = self::queries_table();
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$limit = max( 1, min( 25, $limit ) );
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE run_date = %s AND status = %s
				ORDER BY id ASC
				LIMIT %d",
				$run_date,
				self::STATUS_PENDING,
				$limit
			),
			ARRAY_A
		);

		$claimed = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id < 1 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$table,
				array(
					'status'     => self::STATUS_RUNNING,
					'updated_at' => $now,
				),
				array(
					'id'     => $id,
					'status' => self::STATUS_PENDING,
				)
			);
			if ( $updated ) {
				$row['status'] = self::STATUS_RUNNING;
				$claimed[]     = $row;
			}
		}

		return $claimed;
	}

	/**
	 * Return a running row to pending (time budget / quota interrupt).
	 *
	 * @param int $id Row ID.
	 */
	public static function release_to_pending( int $id ): void {
		global $wpdb;

		$table = self::queries_table();
		if ( ! self::table_exists( $table ) || $id < 1 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'     => self::STATUS_PENDING,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Mark a query row finished.
	 *
	 * @param int                  $id     Row ID.
	 * @param string               $status done|error.
	 * @param array<string, mixed> $stats  Counters / error.
	 */
	public static function finish( int $id, string $status, array $stats = array() ): void {
		global $wpdb;

		$table = self::queries_table();
		if ( ! self::table_exists( $table ) || $id < 1 ) {
			return;
		}

		$status = sanitize_key( $status );
		if ( ! in_array( $status, array( self::STATUS_DONE, self::STATUS_ERROR ), true ) ) {
			$status = self::STATUS_DONE;
		}

		$data = array(
			'status'        => $status,
			'results_found' => max( 0, absint( $stats['results_found'] ?? 0 ) ),
			'imported'      => max( 0, absint( $stats['imported'] ?? 0 ) ),
			'skipped'       => max( 0, absint( $stats['skipped'] ?? 0 ) ),
			'last_error'    => sanitize_text_field( (string) ( $stats['last_error'] ?? '' ) ),
			'ran_at'        => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, $data, array( 'id' => $id ) );
	}

	/**
	 * @param string $table Table name.
	 */
	public static function table_exists( string $table ): bool {
		if ( class_exists( 'LNKup_External_Events_Tables' ) ) {
			return LNKup_External_Events_Tables::table_exists( $table );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
