<?php
/**
 * Organizer lead CSV import: upload → mapping preview → dry run → background run → rollback.
 *
 * Imported organizers become leads only — never accounts — and are never contacted
 * automatically. Rows are staged in `org_lead_import_rows` and processed by WP-Cron in batches.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Lead_Import {

	const BATCH_HOOK   = 'lnkup_org_lead_import_batch';
	const BATCH_SIZE   = 200;
	const PREVIEW_ROWS = 1000;
	const MAX_BYTES    = 5242880;

	/** Importable targets beyond lead columns. */
	const EXTRA_TARGETS = array( 'tags', 'known_events', 'notes' );

	/** Header aliases → target field. */
	const ALIASES = array(
		'name'                => array( 'organizer', 'organizer name', 'business', 'business name', 'company', 'organization', 'organisation', 'name', 'brand' ),
		'category'            => array( 'category', 'organization category', 'org category', 'type' ),
		'org_type'            => array( 'org type', 'organization type', 'entity type' ),
		'website'             => array( 'website', 'site', 'url', 'web' ),
		'email'               => array( 'email', 'public email', 'business email', 'contact email', 'e-mail' ),
		'phone'               => array( 'phone', 'public phone', 'business phone', 'telephone', 'phone number' ),
		'instagram'           => array( 'instagram', 'ig', 'instagram handle', 'instagram url' ),
		'facebook'            => array( 'facebook', 'fb', 'facebook url', 'facebook page' ),
		'socials'             => array( 'other socials', 'socials', 'social links', 'tiktok' ),
		'city'                => array( 'city', 'business city', 'hq city' ),
		'state'               => array( 'state', 'st', 'business state' ),
		'service_area'        => array( 'service area', 'region', 'market' ),
		'description'         => array( 'description', 'about', 'company description', 'summary' ),
		'event_frequency'     => array( 'frequency', 'event frequency', 'cadence' ),
		'cities_served'       => array( 'cities served', 'cities', 'markets served' ),
		'event_categories'    => array( 'event categories', 'event category', 'event types', 'event type' ),
		'estimated_booths'    => array( 'booths', 'estimated booths', 'vendor booths', 'vendor count', 'estimated vendors' ),
		'application_process' => array( 'application process', 'vendor application process', 'how vendors apply', 'contact method' ),
		'next_event_date'     => array( 'next event date', 'next event', 'upcoming event date', 'event date' ),
		'application_url'     => array( 'application url', 'vendor application url', 'vendor application', 'apply url', 'event url' ),
		'source_verified_at'  => array( 'verified', 'verification date', 'verified date', 'last verified', 'source verification date', 'date verified' ),
		'source_detail'       => array( 'source', 'research source', 'source url', 'found via' ),
		'priority'            => array( 'priority', 'tier' ),
		'qualification_notes' => array( 'qualification notes', 'research notes' ),
		'notes'               => array( 'notes', 'note', 'comments' ),
		'known_events'        => array( 'known events', 'events', 'event names' ),
		'tags'                => array( 'tags', 'labels' ),
	);

	public static function init(): void {
		add_action( self::BATCH_HOOK, array( __CLASS__, 'process_batch' ), 10, 1 );
	}

	public static function imports_table(): string {
		return LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::IMPORTS );
	}

	public static function rows_table(): string {
		return LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::IMPORT_ROWS );
	}

	/**
	 * @return string[]
	 */
	public static function targets(): array {
		return array_merge( array_keys( LNKup_Org_Leads::FIELDS ), self::EXTRA_TARGETS );
	}

	// ------------------------------------------------------------------ Upload.

	/**
	 * @param array<string, mixed> $file  $_FILES-style entry.
	 * @param array<string, mixed> $meta  source_label, verified_at.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function upload( array $file, array $meta, int $actor ) {
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'lnkup_import_no_file', __( 'Choose a CSV file to upload.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return self::stage_file( (string) $file['tmp_name'], (string) ( $file['name'] ?? 'import.csv' ), $meta, $actor );
	}

	/**
	 * Validate, parse and stage a CSV already on disk (uploads, WP-CLI, tests).
	 *
	 * @param array<string, mixed> $meta source_label, verified_at.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function stage_file( string $path, string $original_name, array $meta, int $actor ) {
		global $wpdb;
		$file = array(
			'tmp_name' => $path,
			'size'     => file_exists( $path ) ? (int) filesize( $path ) : 0,
		);
		$name = sanitize_file_name( '' !== $original_name ? $original_name : 'import.csv' );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'xlsx', 'xls', 'numbers', 'ods' ), true ) ) {
			return new WP_Error( 'lnkup_import_spreadsheet', __( 'Spreadsheet files are not supported yet. In Excel or Google Sheets choose File → Download → CSV, then upload that file.', 'lnkup-marketplace' ), array( 'status' => 415 ) );
		}
		if ( ! in_array( $ext, array( 'csv', 'tsv', 'txt' ), true ) ) {
			return new WP_Error( 'lnkup_import_type', __( 'Upload a .csv or .tsv file.', 'lnkup-marketplace' ), array( 'status' => 415 ) );
		}
		$size = (int) $file['size'];
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return new WP_Error( 'lnkup_import_size', __( 'The file must be smaller than 5 MB.', 'lnkup-marketplace' ), array( 'status' => 413 ) );
		}
		$parsed = self::parse_file( (string) $file['tmp_name'] );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$max = max( 1, (int) LNKup_Org_Growth::setting( 'org_growth_import_max_rows' ) );
		if ( count( $parsed['rows'] ) > $max ) {
			/* translators: %d: max rows */
			return new WP_Error( 'lnkup_import_too_many', sprintf( __( 'This file has more than %d rows. Split it into smaller files.', 'lnkup-marketplace' ), $max ), array( 'status' => 413 ) );
		}

		$verified_ts = ! empty( $meta['verified_at'] ) ? strtotime( (string) $meta['verified_at'] ) : false;
		$now         = LNKup_Org_Growth::now();
		$mapping     = self::guess_mapping( $parsed['headers'] );
		$wpdb->insert(
			self::imports_table(),
			array(
				'file_name'    => substr( $name, 0, 191 ),
				'source_label' => substr( sanitize_text_field( (string) ( $meta['source_label'] ?? '' ) ), 0, 100 ),
				'verified_at'  => $verified_ts ? gmdate( 'Y-m-d H:i:s', $verified_ts ) : null,
				'mapping'      => wp_json_encode( $mapping ),
				'status'       => 'uploaded',
				'total_rows'   => count( $parsed['rows'] ),
				'created_by'   => $actor,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		$import_id = (int) $wpdb->insert_id;
		if ( ! $import_id ) {
			return new WP_Error( 'lnkup_import_failed', __( 'The import could not be started.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		$table = self::rows_table();
		foreach ( array_chunk( $parsed['rows'], 200, true ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $index => $cells ) {
				$assoc = array();
				foreach ( $parsed['headers'] as $i => $header ) {
					$assoc[ $header ] = (string) ( $cells[ $i ] ?? '' );
				}
				$values[] = $wpdb->prepare( '(%d, %d, %s, %s)', $import_id, $index + 2, wp_json_encode( $assoc ), 'pending' );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "INSERT INTO {$table} (import_id, row_num, raw, status) VALUES " . implode( ',', $values ) );
		}
		return array(
			'import'  => self::get( $import_id ),
			'headers' => $parsed['headers'],
			'mapping' => $mapping,
			'targets' => self::targets(),
			'sample'  => array_slice( $parsed['rows'], 0, 5 ),
		);
	}

	/**
	 * @return array{headers:string[],rows:array<int,string[]>}|WP_Error
	 */
	public static function parse_file( string $path ) {
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'lnkup_import_read', __( 'The file could not be read.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$first = (string) fgets( $handle );
		$first = preg_replace( '/^\xEF\xBB\xBF/', '', $first );
		$delim = substr_count( $first, "\t" ) > substr_count( $first, ',' ) ? "\t" : ( substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',' );
		$headers = array_map( static fn( $h ) => trim( (string) $h ), str_getcsv( trim( (string) $first ), $delim ) );
		if ( count( array_filter( $headers ) ) < 1 ) {
			fclose( $handle );
			return new WP_Error( 'lnkup_import_headers', __( 'The first row must contain column headings.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$rows = array();
		while ( false !== ( $cells = fgetcsv( $handle, 0, $delim ) ) ) {
			if ( ! is_array( $cells ) || ( 1 === count( $cells ) && '' === trim( (string) $cells[0] ) ) ) {
				continue;
			}
			$rows[] = array_map( static fn( $c ) => trim( (string) $c ), $cells );
		}
		fclose( $handle );
		if ( ! $rows ) {
			return new WP_Error( 'lnkup_import_empty', __( 'The file has headings but no organizer rows.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * @param string[] $headers CSV headers.
	 * @return array<string, string> header => target ('' = ignore).
	 */
	public static function guess_mapping( array $headers ): array {
		$mapping = array();
		$used    = array();
		foreach ( $headers as $header ) {
			$norm   = strtolower( trim( preg_replace( '/[_\-]+/', ' ', $header ) ) );
			$target = '';
			foreach ( self::ALIASES as $field => $aliases ) {
				if ( isset( $used[ $field ] ) ) {
					continue;
				}
				if ( in_array( $norm, $aliases, true ) || $norm === $field ) {
					$target = $field;
					break;
				}
			}
			if ( '' !== $target ) {
				$used[ $target ] = true;
			}
			$mapping[ $header ] = $target;
		}
		return $mapping;
	}

	// ------------------------------------------------------------------ Mapping + dry run.

	/**
	 * Map one raw row to lead input.
	 *
	 * @param array<string, string> $raw     header => value.
	 * @param array<string, string> $mapping header => target.
	 * @param array<string, mixed>  $import  Import row.
	 * @return array{lead:array<string,mixed>,tags:string[],events:string[],notes:string}
	 */
	public static function map_row( array $raw, array $mapping, array $import ): array {
		$lead   = array();
		$tags   = array();
		$events = array();
		$notes  = '';
		foreach ( $mapping as $header => $target ) {
			$value = trim( (string) ( $raw[ $header ] ?? '' ) );
			if ( '' === $target || '' === $value ) {
				continue;
			}
			if ( 'tags' === $target ) {
				$tags = array_merge( $tags, LNKup_Org_Lead_Scoring::split_list( $value ) );
			} elseif ( 'known_events' === $target ) {
				$events = array_merge( $events, array_values( array_filter( array_map( 'trim', preg_split( '/[;|\n]+/', $value ) ?: array() ) ) ) );
			} elseif ( 'notes' === $target ) {
				$notes = '' === $notes ? $value : $notes . "\n" . $value;
			} elseif ( 'estimated_booths' === $target ) {
				$lead[ $target ] = (int) preg_replace( '/[^0-9]/', '', $value );
			} elseif ( 'event_frequency' === $target ) {
				$lead[ $target ] = self::normalize_frequency( $value );
			} elseif ( 'org_type' === $target ) {
				$lead[ $target ] = str_replace( array( ' ', '-' ), '_', strtolower( $value ) );
			} elseif ( isset( $lead[ $target ] ) && in_array( $target, array( 'qualification_notes', 'description' ), true ) ) {
				$lead[ $target ] .= "\n" . $value;
			} else {
				$lead[ $target ] = $value;
			}
		}
		if ( empty( $lead['source_verified_at'] ) && ! empty( $import['verified_at'] ) ) {
			$lead['source_verified_at'] = (string) $import['verified_at'];
		}
		$lead['source'] = 'import';
		if ( empty( $lead['source_campaign'] ) && ! empty( $import['source_label'] ) ) {
			$lead['source_campaign'] = (string) $import['source_label'];
		}
		if ( '' !== $notes ) {
			$lead['qualification_notes'] = trim( (string) ( $lead['qualification_notes'] ?? '' ) . "\n" . $notes );
		}
		return array(
			'lead'   => $lead,
			'tags'   => array_values( array_unique( $tags ) ),
			'events' => array_slice( array_values( array_unique( $events ) ), 0, 25 ),
			'notes'  => $notes,
		);
	}

	private static function normalize_frequency( string $value ): string {
		$v = strtolower( $value );
		$map = array(
			'weekly'    => array( 'weekly', 'every week', 'weekend' ),
			'monthly'   => array( 'monthly', 'every month' ),
			'quarterly' => array( 'quarterly' ),
			'seasonal'  => array( 'seasonal', 'season' ),
			'annual'    => array( 'annual', 'annually', 'yearly', 'once a year' ),
			'one_off'   => array( 'one-off', 'one off', 'one time', 'once', 'single' ),
			'recurring' => array( 'recurring', 'multiple', 'ongoing', 'series' ),
		);
		foreach ( $map as $key => $hints ) {
			foreach ( $hints as $hint ) {
				if ( false !== strpos( $v, $hint ) ) {
					return $key;
				}
			}
		}
		return '';
	}

	/**
	 * Save mapping and run a dry run over the staged rows (no leads written).
	 *
	 * @param array<string, string> $mapping header => target.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function preview( int $import_id, array $mapping ) {
		global $wpdb;
		$import = self::get_row( $import_id );
		if ( ! $import ) {
			return new WP_Error( 'lnkup_import_not_found', __( 'Import not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $import['status'], array( 'uploaded', 'previewed' ), true ) ) {
			return new WP_Error( 'lnkup_import_started', __( 'This import has already run.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$targets = self::targets();
		$clean   = array();
		foreach ( $mapping as $header => $target ) {
			$target                                    = sanitize_key( (string) $target );
			$clean[ sanitize_text_field( (string) $header ) ] = in_array( $target, $targets, true ) ? $target : '';
		}
		if ( ! in_array( 'name', $clean, true ) ) {
			return new WP_Error( 'lnkup_import_name_mapping', __( 'Map one column to "name" (organizer or business name).', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$wpdb->update( self::imports_table(), array( 'mapping' => wp_json_encode( $clean ), 'status' => 'previewed', 'updated_at' => LNKup_Org_Growth::now() ), array( 'id' => $import_id ) );
		$import['mapping'] = wp_json_encode( $clean );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::rows_table() . ' WHERE import_id = %d ORDER BY row_num ASC LIMIT %d', $import_id, self::PREVIEW_ROWS ), ARRAY_A );
		$summary = array(
			'would_create'       => 0,
			'duplicate'          => 0,
			'duplicate_in_file'  => 0,
			'possible_duplicate' => 0,
			'existing_account'   => 0,
			'error'              => 0,
		);
		$sample  = array();
		$seen    = array();
		foreach ( (array) $rows as $row ) {
			$result = self::evaluate_row( $row, $clean, $import, $seen );
			++$summary[ $result['status'] ];
			if ( count( $sample ) < 50 ) {
				$sample[] = $result;
			}
		}
		return array(
			'import'       => self::get( $import_id ),
			'mapping'      => $clean,
			'summary'      => $summary,
			'previewed'    => count( (array) $rows ),
			'total_rows'   => (int) $import['total_rows'],
			'sample'       => $sample,
		);
	}

	/**
	 * Classify a staged row without writing.
	 *
	 * @param array<string, mixed>  $row     Staged row.
	 * @param array<string, string> $mapping Mapping.
	 * @param array<string, mixed>  $import  Import.
	 * @param array<string, int>    $seen    In-file identity keys (by reference).
	 * @return array<string, mixed>
	 */
	private static function evaluate_row( array $row, array $mapping, array $import, array &$seen ): array {
		$raw    = json_decode( (string) $row['raw'], true );
		$mapped = self::map_row( is_array( $raw ) ? $raw : array(), $mapping, $import );
		$data   = LNKup_Org_Leads::sanitize( $mapped['lead'] );
		$out    = array(
			'row'     => (int) $row['row_num'],
			'name'    => (string) ( $data['name'] ?? '' ),
			'city'    => (string) ( $data['city'] ?? '' ),
			'state'   => (string) ( $data['state'] ?? '' ),
			'email'   => (string) ( $data['email'] ?? '' ),
			'status'  => 'would_create',
			'message' => '',
			'score'   => null,
			'tier'    => '',
		);
		if ( '' === trim( $out['name'] ) ) {
			$out['status']  = 'error';
			$out['message'] = __( 'Missing organizer name', 'lnkup-marketplace' );
			return $out;
		}
		$identity = LNKup_Org_Lead_Dedupe::identity( $data );
		foreach ( array( 'email', 'domain', 'instagram' ) as $key ) {
			$value = $identity[ $key ];
			if ( '' === $value ) {
				continue;
			}
			if ( isset( $seen[ $key . ':' . $value ] ) ) {
				$out['status']  = 'duplicate_in_file';
				/* translators: %d: row number */
				$out['message'] = sprintf( __( 'Same %1$s as row %2$d', 'lnkup-marketplace' ), $key, $seen[ $key . ':' . $value ] );
				return $out;
			}
		}
		foreach ( array( 'email', 'domain', 'instagram' ) as $key ) {
			if ( '' !== $identity[ $key ] ) {
				$seen[ $key . ':' . $identity[ $key ] ] = (int) $row['row_num'];
			}
		}
		$matches = LNKup_Org_Lead_Dedupe::find_matches( $identity, (string) ( $data['state'] ?? '' ) );
		$hard    = LNKup_Org_Lead_Dedupe::first_hard( $matches );
		if ( $hard ) {
			$existing       = LNKup_Org_Leads::get_row( $hard['lead_id'] );
			$out['status']  = 'duplicate';
			/* translators: 1: lead name, 2: reason */
			$out['message'] = sprintf( __( 'Matches existing lead "%1$s" (%2$s)', 'lnkup-marketplace' ), $existing ? (string) $existing['name'] : '#' . $hard['lead_id'], str_replace( '_', ' ', $hard['reason'] ) );
			$out['duplicate_of'] = $hard['lead_id'];
			return $out;
		}
		$eval         = LNKup_Org_Lead_Scoring::evaluate( $data + $identity, array( 'event_count' => count( $mapped['events'] ) ) );
		$out['score'] = $eval['score'];
		$out['tier']  = $eval['tier'];
		if ( '' !== $identity['email'] ) {
			$user = get_user_by( 'email', $identity['email'] );
			if ( $user && in_array( 'lnkup_organizer', (array) $user->roles, true ) ) {
				$out['status']  = 'existing_account';
				$out['message'] = __( 'Will link to the existing organizer account with this email', 'lnkup-marketplace' );
				return $out;
			}
		}
		if ( $matches ) {
			$out['status']  = 'possible_duplicate';
			$out['message'] = __( 'Similar name to an existing lead — will be flagged for review', 'lnkup-marketplace' );
		}
		return $out;
	}

	// ------------------------------------------------------------------ Run.

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run( int $import_id ) {
		global $wpdb;
		$import = self::get_row( $import_id );
		if ( ! $import ) {
			return new WP_Error( 'lnkup_import_not_found', __( 'Import not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( 'previewed' !== $import['status'] ) {
			return new WP_Error( 'lnkup_import_preview_first', __( 'Review the dry run before importing.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$claimed = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::imports_table() . " SET status = 'running', updated_at = %s WHERE id = %d AND status = 'previewed'", LNKup_Org_Growth::now(), $import_id ) );
		if ( 1 !== $claimed ) {
			return new WP_Error( 'lnkup_import_started', __( 'This import has already started.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		self::process_batch( $import_id );
		return (array) self::get( $import_id );
	}

	/**
	 * Process up to BATCH_SIZE pending rows, then reschedule.
	 *
	 * @param int|string $import_id Import ID.
	 */
	public static function process_batch( $import_id ): void {
		global $wpdb;
		$import_id = (int) $import_id;
		$import    = self::get_row( $import_id );
		if ( ! $import || 'running' !== $import['status'] ) {
			return;
		}
		$lock = 'lnkup_org_import_' . $import_id;
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		try {
			$mapping = json_decode( (string) $import['mapping'], true );
			$mapping = is_array( $mapping ) ? $mapping : array();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::rows_table() . " WHERE import_id = %d AND status = 'pending' ORDER BY row_num ASC LIMIT %d", $import_id, self::BATCH_SIZE ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				self::import_row( $row, $mapping, $import );
			}
			self::refresh_counts( $import_id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::rows_table() . " WHERE import_id = %d AND status = 'pending'", $import_id ) );
			if ( $left > 0 ) {
				wp_schedule_single_event( time() + 15, self::BATCH_HOOK, array( $import_id ) );
			} else {
				$wpdb->update( self::imports_table(), array( 'status' => 'completed', 'updated_at' => LNKup_Org_Growth::now() ), array( 'id' => $import_id ) );
				LNKup_Org_Growth_Metrics::flush_cache();
			}
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup organizer lead import ' . $import_id . ' failed: ' . $e->getMessage() );
			$wpdb->update( self::imports_table(), array( 'status' => 'failed', 'updated_at' => LNKup_Org_Growth::now() ), array( 'id' => $import_id ) );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * @param array<string, mixed>  $row     Staged row.
	 * @param array<string, string> $mapping Mapping.
	 * @param array<string, mixed>  $import  Import.
	 */
	private static function import_row( array $row, array $mapping, array $import ): void {
		global $wpdb;
		$raw    = json_decode( (string) $row['raw'], true );
		$mapped = self::map_row( is_array( $raw ) ? $raw : array(), $mapping, $import );
		$result = LNKup_Org_Leads::create(
			$mapped['lead'],
			array(
				'actor'     => (int) $import['created_by'],
				'import_id' => (int) $import['id'],
				'tags'      => $mapped['tags'],
			)
		);
		$update = array();
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( 'lnkup_lead_duplicate' === $result->get_error_code() && is_array( $data ) ) {
				$dup    = (int) ( $data['duplicate_of'] ?? 0 );
				$update = array(
					'status'       => 'duplicate',
					'duplicate_of' => $dup,
					'match_reason' => sanitize_key( (string) ( $data['reason'] ?? '' ) ),
					'message'      => __( 'Matched an existing lead — not imported', 'lnkup-marketplace' ),
				);
				if ( $dup ) {
					LNKup_Org_Leads::log_activity( $dup, 'import_matched', array( 'actor' => (int) $import['created_by'], 'summary' => sprintf( /* translators: 1: file, 2: row */ __( 'Also found in import "%1$s" (row %2$d)', 'lnkup-marketplace' ), (string) $import['file_name'], (int) $row['row_num'] ), 'payload' => array( 'import_id' => (int) $import['id'], 'raw' => $raw ) ) );
				}
			} else {
				$update = array(
					'status'  => 'error',
					'message' => substr( $result->get_error_message(), 0, 255 ),
				);
			}
		} else {
			$lead_id = (int) $result;
			if ( $mapped['events'] ) {
				$events = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::LEAD_EVENTS );
				foreach ( $mapped['events'] as $event_name ) {
					$wpdb->insert(
						$events,
						array(
							'lead_id'     => $lead_id,
							'name'        => substr( sanitize_text_field( $event_name ), 0, 191 ),
							'city'        => (string) ( $mapped['lead']['city'] ?? '' ),
							'verified_at' => $import['verified_at'] ?: null,
							'created_at'  => LNKup_Org_Growth::now(),
						)
					);
				}
				LNKup_Org_Lead_Scoring::score_lead( $lead_id );
			}
			$update = array(
				'status'  => 'created',
				'lead_id' => $lead_id,
			);
		}
		$wpdb->update( self::rows_table(), $update, array( 'id' => (int) $row['id'] ) );
	}

	private static function refresh_counts( int $import_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM ' . self::rows_table() . ' WHERE import_id = %d GROUP BY status', $import_id ), ARRAY_A );
		$by     = array();
		foreach ( (array) $counts as $c ) {
			$by[ (string) $c['status'] ] = (int) $c['n'];
		}
		$wpdb->update(
			self::imports_table(),
			array(
				'processed_rows'    => array_sum( $by ) - ( $by['pending'] ?? 0 ),
				'created_count'     => ( $by['created'] ?? 0 ),
				'duplicate_count'   => ( $by['duplicate'] ?? 0 ),
				'error_count'       => ( $by['error'] ?? 0 ),
				'rolled_back_count' => ( $by['rolled_back'] ?? 0 ),
				'updated_at'        => LNKup_Org_Growth::now(),
			),
			array( 'id' => $import_id )
		);
	}

	// ------------------------------------------------------------------ Rollback.

	/**
	 * Delete leads this import created that nobody has worked yet. Worked leads (contacts,
	 * notes, tasks, stage moves, linked accounts, merges) are kept and reported.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function rollback( int $import_id, int $actor ) {
		global $wpdb;
		$import = self::get_row( $import_id );
		if ( ! $import ) {
			return new WP_Error( 'lnkup_import_not_found', __( 'Import not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( 'running' === $import['status'] ) {
			return new WP_Error( 'lnkup_import_running', __( 'Wait for the import to finish before rolling it back.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$t        = static fn( string $s ): string => LNKup_Org_Growth_Tables::table( $s );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lead_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT lead_id FROM ' . self::rows_table() . " WHERE import_id = %d AND status = 'created' AND lead_id > 0", $import_id ) ) );
		$removed  = 0;
		$kept     = array();
		foreach ( $lead_ids as $lead_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$worked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( LNKup_Org_Growth_Tables::ACTIVITY ) . " WHERE lead_id = %d AND type NOT IN ('created','import_matched','updated')", $lead_id ) )
				+ (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( LNKup_Org_Growth_Tables::ACCOUNTS ) . ' WHERE lead_id = %d', $lead_id ) )
				+ (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( LNKup_Org_Growth_Tables::TASKS ) . ' WHERE lead_id = %d', $lead_id ) )
				+ (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( LNKup_Org_Growth_Tables::LEADS ) . ' WHERE merged_into_id = %d OR parent_lead_id = %d', $lead_id, $lead_id ) );
			if ( class_exists( 'LNKup_Outreach_Tables' ) ) {
				$worked += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS ) . ' WHERE lead_id = %d', $lead_id ) );
			}
			// phpcs:enable
			if ( $worked > 0 ) {
				$kept[] = $lead_id;
				continue;
			}
			foreach ( array( LNKup_Org_Growth_Tables::ACTIVITY, LNKup_Org_Growth_Tables::TAGS, LNKup_Org_Growth_Tables::LEAD_EVENTS, LNKup_Org_Growth_Tables::MILESTONES ) as $suffix ) {
				$wpdb->delete( $t( $suffix ), array( 'lead_id' => $lead_id ) );
			}
			$wpdb->update( $t( LNKup_Org_Growth_Tables::LEADS ), array( 'possible_duplicate_of' => 0 ), array( 'possible_duplicate_of' => $lead_id ) );
			$wpdb->delete( $t( LNKup_Org_Growth_Tables::LEADS ), array( 'id' => $lead_id ) );
			$wpdb->update( self::rows_table(), array( 'status' => 'rolled_back' ), array( 'import_id' => $import_id, 'lead_id' => $lead_id ) );
			++$removed;
		}
		$wpdb->update( self::imports_table(), array( 'status' => 'rolled_back', 'updated_at' => LNKup_Org_Growth::now() ), array( 'id' => $import_id ) );
		self::refresh_counts( $import_id );
		LNKup_Financial_Audit::log( 'org_lead_import_rolled_back', 'org_lead_import', $import_id, array( 'actor' => $actor, 'removed' => $removed, 'kept' => count( $kept ) ) );
		LNKup_Org_Growth_Metrics::flush_cache();
		return array(
			'import'  => self::get( $import_id ),
			'removed' => $removed,
			'kept'    => $kept,
		);
	}

	// ------------------------------------------------------------------ Reads.

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get_row( int $import_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::imports_table() . ' WHERE id = %d', $import_id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $import_id ): ?array {
		$row = self::get_row( $import_id );
		if ( ! $row ) {
			return null;
		}
		$user = get_userdata( (int) $row['created_by'] );
		return array(
			'id'                => (int) $row['id'],
			'file_name'         => (string) $row['file_name'],
			'source_label'      => (string) $row['source_label'],
			'verified_at'       => (string) ( $row['verified_at'] ?? '' ),
			'status'            => (string) $row['status'],
			'total_rows'        => (int) $row['total_rows'],
			'processed_rows'    => (int) $row['processed_rows'],
			'created_count'     => (int) $row['created_count'],
			'duplicate_count'   => (int) $row['duplicate_count'],
			'error_count'       => (int) $row['error_count'],
			'rolled_back_count' => (int) $row['rolled_back_count'],
			'created_by'        => $user ? (string) $user->display_name : '',
			'created_at'        => (string) $row['created_at'],
			'updated_at'        => (string) $row['updated_at'],
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function history( int $limit = 25 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::imports_table() . ' ORDER BY id DESC LIMIT %d', max( 1, min( 100, $limit ) ) ) );
		return array_values( array_filter( array_map( static fn( $id ) => self::get( (int) $id ), $ids ) ) );
	}

	/**
	 * Row-level results (errors and duplicates first).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function row_results( int $import_id, string $status = '' ): array {
		global $wpdb;
		$sql  = 'SELECT row_num, status, lead_id, duplicate_of, match_reason, message FROM ' . self::rows_table() . ' WHERE import_id = %d';
		$args = array( $import_id );
		if ( '' !== $status ) {
			$sql   .= ' AND status = %s';
			$args[] = sanitize_key( $status );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( $sql . " ORDER BY FIELD(status,'error','duplicate') DESC, row_num ASC LIMIT 500", $args ), ARRAY_A );
	}

	/**
	 * CSV template with the supported headings.
	 */
	public static function template_csv(): string {
		$headers = array( 'Organizer name', 'Category', 'Website', 'Public email', 'Public phone', 'Instagram', 'Facebook', 'City', 'State', 'Service area', 'Event frequency', 'Cities served', 'Event categories', 'Estimated booths', 'Application process', 'Next event date', 'Application URL', 'Known events', 'Priority', 'Research source', 'Verification date', 'Notes', 'Tags' );
		return implode( ',', $headers ) . "\n";
	}
}
