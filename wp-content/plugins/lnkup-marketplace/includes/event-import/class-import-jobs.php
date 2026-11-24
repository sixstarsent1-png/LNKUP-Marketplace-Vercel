<?php
/**
 * Bulk import jobs: several links, a CSV, a calendar (ICS) file, or Eventbrite account events.
 *
 * Items are read in small batches (Action Scheduler when present, otherwise WP-Cron; status
 * polling also advances a batch under a lock so jobs finish where cron is unreliable). Every item
 * ends as a reviewable preview. Nothing is created until the organizer chooses — and imports only
 * ever create private drafts, never published events.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Import_Jobs {

	const HOOK       = 'lnkup_import_job_process';
	const BATCH      = 4;
	const MAX_URLS   = 25;
	const MAX_ROWS   = 100;
	const MAX_BYTES  = 1048576;
	const METHODS    = array( 'urls', 'csv', 'ics', 'eventbrite' );
	const CSV_FIELDS = array( 'name', 'date', 'end_date', 'start_time', 'end_time', 'venue_name', 'address', 'city', 'state', 'zip', 'description', 'url' );

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'process' ), 10, 1 );
	}

	/* ------------------------------------------------------------ Create */

	/**
	 * @param array<int,array<string,mixed>> $inputs Item inputs (url or normalized fields).
	 * @param array<string,mixed>            $options Job options.
	 * @return array<string,mixed>|WP_Error Job.
	 */
	public static function create( int $user_id, string $method, array $inputs, array $options = array(), string $file_name = '' ) {
		global $wpdb;
		if ( ! in_array( $method, self::METHODS, true ) ) {
			return new WP_Error( 'lnkup_import_method', __( 'Unsupported import type.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$max  = 'urls' === $method ? self::MAX_URLS : self::MAX_ROWS;
		$seen = array();
		$rows = array();
		foreach ( $inputs as $input ) {
			$key = sha1( 'urls' === $method ? LNKup_Event_Sources::canonical_url( (string) ( $input['url'] ?? '' ) ) : (string) wp_json_encode( $input ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$rows[]       = array( $key, $input );
		}
		if ( ! $rows ) {
			return new WP_Error( 'lnkup_import_empty', __( 'Nothing to import. Add at least one event.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( count( $rows ) > $max ) {
			/* translators: %d: maximum number of events per import */
			return new WP_Error( 'lnkup_import_too_many', sprintf( __( 'Import up to %d events at a time.', 'lnkup-marketplace' ), $max ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			LNKup_Event_Import_Tables::jobs(),
			array(
				'organizer_id' => $user_id,
				'user_id'      => $user_id,
				'method'       => $method,
				'status'       => 'queued',
				'file_name'    => sanitize_file_name( $file_name ),
				'total_items'  => count( $rows ),
				'options'      => wp_json_encode( $options ),
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		$job_id = (int) $wpdb->insert_id;
		if ( ! $job_id ) {
			return new WP_Error( 'lnkup_import_db', __( 'We could not start the import. Try again.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		foreach ( $rows as $r ) {
			$wpdb->insert( LNKup_Event_Import_Tables::items(), array( 'job_id' => $job_id, 'row_key' => $r[0], 'input' => wp_json_encode( $r[1] ), 'status' => 'pending', 'updated_at' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}
		self::schedule( $job_id );
		return self::get( $job_id );
	}

	public static function schedule( int $job_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $job_id ), 'lnkup' );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK, array( $job_id ) ) ) {
			wp_schedule_single_event( time(), self::HOOK, array( $job_id ) );
		}
	}

	/* ------------------------------------------------------------ Process */

	/**
	 * Read one batch of pending items. Safe to call concurrently (per-job lock).
	 */
	public static function process( int $job_id ): void {
		global $wpdb;
		$job = self::row( $job_id );
		if ( ! $job || in_array( $job['status'], array( 'cancelled', 'completed' ), true ) ) {
			return;
		}
		$lock = 'lnkup_import_job_lock_' . $job_id;
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 60 );
		$items = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Event_Import_Tables::items() . " WHERE job_id = %d AND status = 'pending' ORDER BY id ASC LIMIT %d", $job_id, self::BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prev  = get_current_user_id();
		wp_set_current_user( (int) $job['user_id'] );
		self::set_status( $job_id, 'processing' );
		foreach ( (array) $items as $item ) {
			$input   = json_decode( (string) $item['input'], true ) ?: array();
			$preview = self::preview_item( $job, $input );
			$fields  = array( 'attempts' => (int) $item['attempts'] + 1, 'updated_at' => current_time( 'mysql', true ) );
			if ( is_wp_error( $preview ) ) {
				$fields['status'] = 'failed';
				$fields['error']  = mb_substr( $preview->get_error_message(), 0, 250 );
			} else {
				$rel              = (string) ( $preview['relation']['state'] ?? 'none' );
				$fields['status'] = in_array( $rel, array( 'other', 'claimed_other' ), true ) ? 'conflict' : ( in_array( $rel, array( 'own', 'own_listing' ), true ) || ! empty( $preview['duplicates'] ) ? 'duplicate' : 'ready' );
				$fields['action'] = 'ready' === $fields['status'] ? 'draft' : 'skip';
				$fields['preview'] = wp_json_encode( LNKup_Event_Import_REST::present( $preview ) );
				$fields['error']  = '';
			}
			$wpdb->update( LNKup_Event_Import_Tables::items(), $fields, array( 'id' => (int) $item['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		wp_set_current_user( $prev );
		$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Event_Import_Tables::items() . " WHERE job_id = %d AND status = 'pending'", $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::set_status( $job_id, $left ? 'processing' : 'review' );
		delete_transient( $lock );
		if ( $left ) {
			self::schedule( $job_id );
		}
	}

	/**
	 * @param array<string,mixed> $job   Job row.
	 * @param array<string,mixed> $input Item input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function preview_item( array $job, array $input ) {
		$user = (int) $job['user_id'];
		if ( 'urls' === $job['method'] ) {
			return LNKup_Event_Import_Service::preview( (string) ( $input['url'] ?? '' ), $user );
		}
		if ( 'eventbrite' === $job['method'] ) {
			$preview = class_exists( 'LNKup_Eventbrite_Connection' ) ? LNKup_Eventbrite_Connection::event_preview( $user, (string) ( $input['id'] ?? '' ) ) : null;
			return $preview ?: new WP_Error( 'lnkup_import_eventbrite', __( 'This Eventbrite event is no longer available to your account.', 'lnkup-marketplace' ) );
		}
		return self::preview_from_fields( (array) ( $input['fields'] ?? array() ), (string) $job['method'], (string) ( $input['url'] ?? '' ), $user, (array) ( $input['notes'] ?? array() ) );
	}

	/**
	 * Preview for rows that already carry their fields (CSV, ICS). A URL column is attached as
	 * a link only — it is not fetched.
	 *
	 * @param array<string,mixed> $fields Normalized fields.
	 * @param string[]            $notes  Row notes.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function preview_from_fields( array $fields, string $method, string $url, int $user, array $notes = array() ) {
		$fields = array_filter( $fields, static fn( $v ) => '' !== $v && null !== $v );
		if ( empty( $fields['name'] ) ) {
			return new WP_Error( 'lnkup_import_row', __( 'Missing event name.', 'lnkup-marketplace' ) );
		}
		$prov    = array_fill_keys( array_keys( $fields ), 'file' );
		$url     = '' !== $url && ! is_wp_error( LNKup_Safe_Fetch::validate( $url ) ) ? esc_url_raw( $url ) : '';
		$missing = array();
		foreach ( array( 'date' => __( 'Date', 'lnkup-marketplace' ), 'city' => __( 'City', 'lnkup-marketplace' ), 'start_time' => __( 'Start time', 'lnkup-marketplace' ) ) as $k => $label ) {
			if ( empty( $fields[ $k ] ) ) {
				$missing[] = array( 'field' => $k, 'label' => $label );
			}
		}
		$warnings = $notes;
		if ( ! empty( $fields['date'] ) && $fields['date'] < current_time( 'Y-m-d' ) ) {
			$warnings[] = __( 'This date has already passed.', 'lnkup-marketplace' );
		}
		$provider = '' !== $url ? LNKup_Import_Provider_Registry::detect( $url ) : LNKup_Import_Provider_Registry::all()[ $method ];
		return array(
			'url'         => $url,
			'canonical'   => '' !== $url ? LNKup_Event_Sources::canonical_url( $url ) : '',
			'provider'    => array_intersect_key( $provider, array_flip( array( 'key', 'label', 'level', 'limitations' ) ) ),
			'relation'    => '' !== $url ? LNKup_Event_Sources::relation( $url, $user ) : array( 'state' => 'none', 'event_id' => 0, 'message' => '', 'external_id' => 0 ),
			'duplicates'  => LNKup_Event_Import_Service::probable_duplicates( $fields, $user ),
			'status'      => $missing ? 'partial' : 'complete',
			'message'     => '',
			'http_status' => 200,
			'fields'      => $fields,
			'provenance'  => $prov,
			'missing'     => $missing,
			'warnings'    => $warnings,
			'occurrences' => array(),
			'external_id' => (string) ( $fields['external_id'] ?? '' ),
			'final_url'   => $url,
		);
	}

	/* ------------------------------------------------------------ Review + apply */

	/**
	 * Create drafts (or links) for the chosen items. Idempotent per item.
	 *
	 * @param array<int|string,string> $decisions item_id => draft|skip|link:{event_id}
	 * @return array<string,mixed>|WP_Error
	 */
	public static function apply( int $job_id, int $user_id, array $decisions ) {
		global $wpdb;
		$job = self::row( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'lnkup_import_missing', __( 'This import no longer exists.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$items   = self::items( $job_id );
		$created = array();
		$errors  = array();
		foreach ( $items as $item ) {
			$choice = (string) ( $decisions[ $item['id'] ] ?? $item['action'] );
			if ( $item['draft_id'] || $item['event_id'] || ! in_array( $item['status'], array( 'ready', 'duplicate' ), true ) ) {
				continue;
			}
			$fields = array( 'action' => sanitize_key( strtok( $choice, ':' ) ), 'updated_at' => current_time( 'mysql', true ) );
			if ( 0 === strpos( $choice, 'link:' ) && '' !== (string) $item['preview']['url'] ) {
				$event_id = (int) substr( $choice, 5 );
				$req      = new WP_REST_Request( 'POST', '/lnkup/v1/organizer/import/associate' );
				$req->set_body_params( array( 'url' => $item['preview']['url'], 'event_id' => $event_id ) );
				$res = LNKup_Event_Import_REST::associate( $req );
				if ( is_wp_error( $res ) ) {
					$errors[ $item['id'] ] = $res->get_error_message();
				} else {
					$fields['event_id'] = $event_id;
					$fields['status']   = 'linked';
				}
			} elseif ( 'draft' === $choice ) {
				if ( 'duplicate' === $item['status'] && in_array( $item['preview']['relation']['state'] ?? '', array( 'own', 'own_listing' ), true ) ) {
					$errors[ $item['id'] ] = __( 'Already on LNKup — open the existing event instead.', 'lnkup-marketplace' );
					continue;
				}
				$state = array_merge( LNKup_Event_Import_Service::to_state_patch( $item['preview'] ), array( 'method' => 'bulk' ) );
				if ( 'account' === ( $item['preview']['source_mode'] ?? '' ) && ! empty( $state['sources'][0] ) ) {
					$state['sources'][0]['mode'] = 'account';
				}
				$draft = LNKup_Event_Builder_Drafts::create( $user_id, $state );
				if ( is_wp_error( $draft ) ) {
					$errors[ $item['id'] ] = $draft->get_error_message();
					continue;
				}
				$fields['draft_id'] = (int) $draft['id'];
				$fields['status']   = 'drafted';
				$created[]          = (int) $draft['id'];
			} else {
				$fields['status'] = 'skipped';
			}
			$wpdb->update( LNKup_Event_Import_Tables::items(), $fields, array( 'id' => (int) $item['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		self::recount( $job_id );
		$open = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Event_Import_Tables::items() . " WHERE job_id = %d AND status IN ('pending','ready','duplicate')", $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::set_status( $job_id, $open ? 'review' : 'completed' );
		if ( $created ) {
			LNKup_Event_Builder_Publisher::track( 'bulk_import_applied', LNKup_Event_Builder_Schema::normalize( array( 'method' => 'bulk', 'import' => array( 'provider' => (string) $job['method'] ) ) ), 0, $user_id );
		}
		return array( 'job' => self::get( $job_id ), 'created' => $created, 'errors' => $errors );
	}

	public static function retry( int $job_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Event_Import_Tables::items() . " SET status = 'pending', error = '' WHERE job_id = %d AND status = 'failed'", $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::set_status( $job_id, 'queued' );
		self::schedule( $job_id );
	}

	public static function cancel( int $job_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Event_Import_Tables::items() . " SET status = 'skipped' WHERE job_id = %d AND status IN ('pending','ready','duplicate','failed','conflict')", $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::recount( $job_id );
		self::set_status( $job_id, 'cancelled' );
	}

	/* ------------------------------------------------------------ Read */

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get( int $job_id, bool $with_items = true ): ?array {
		$job = self::row( $job_id );
		if ( ! $job ) {
			return null;
		}
		$counts = array( 'pending' => 0, 'ready' => 0, 'duplicate' => 0, 'conflict' => 0, 'failed' => 0, 'drafted' => 0, 'linked' => 0, 'skipped' => 0 );
		$items  = self::items( $job_id );
		foreach ( $items as $i ) {
			$counts[ $i['status'] ] = ( $counts[ $i['status'] ] ?? 0 ) + 1;
		}
		$out = array(
			'id'         => (int) $job['id'],
			'method'     => (string) $job['method'],
			'status'     => (string) $job['status'],
			'file_name'  => (string) $job['file_name'],
			'total'      => (int) $job['total_items'],
			'counts'     => $counts,
			'progress'   => $job['total_items'] ? (int) round( 100 * ( (int) $job['total_items'] - $counts['pending'] ) / (int) $job['total_items'] ) : 100,
			'created_at' => mysql_to_rfc3339( (string) $job['created_at'] ),
			'updated_at' => mysql_to_rfc3339( (string) $job['updated_at'] ),
		);
		if ( $with_items ) {
			$out['items'] = array_map(
				static fn( $i ) => array(
					'id'       => (int) $i['id'],
					'status'   => $i['status'],
					'action'   => $i['action'],
					'error'    => $i['error'],
					'draft_id' => (int) $i['draft_id'],
					'event_id' => (int) $i['event_id'],
					'label'    => (string) ( $i['preview']['fields']['name'] ?? $i['input']['fields']['name'] ?? $i['input']['url'] ?? $i['input']['title'] ?? '' ),
					'preview'  => $i['preview'] ?: null,
				),
				$items
			);
		}
		return $out;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_for( int $user_id, int $limit = 20 ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Event_Import_Tables::jobs() . ' WHERE organizer_id = %d ORDER BY id DESC LIMIT %d', $user_id, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_values( array_filter( array_map( static fn( $id ) => self::get( (int) $id, false ), (array) $ids ) ) );
	}

	public static function owns( int $job_id, int $user_id ): bool {
		$job = self::row( $job_id );
		return $job && ( (int) $job['organizer_id'] === $user_id || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all( $user_id ) ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function row( int $job_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Event_Import_Tables::jobs() . ' WHERE id = %d', $job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function items( int $job_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Event_Import_Tables::items() . ' WHERE job_id = %d ORDER BY id ASC', $job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as &$r ) {
			$r['input']   = json_decode( (string) $r['input'], true ) ?: array();
			$r['preview'] = $r['preview'] ? ( json_decode( (string) $r['preview'], true ) ?: array() ) : array();
		}
		unset( $r );
		return $rows;
	}

	private static function set_status( int $job_id, string $status ): void {
		global $wpdb;
		$wpdb->update( LNKup_Event_Import_Tables::jobs(), array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private static function recount( int $job_id ): void {
		global $wpdb;
		$c = array( 'drafted' => 0, 'linked' => 0, 'failed' => 0, 'skipped' => 0 );
		foreach ( self::items( $job_id ) as $i ) {
			if ( isset( $c[ $i['status'] ] ) ) {
				++$c[ $i['status'] ];
			}
		}
		$wpdb->update( LNKup_Event_Import_Tables::jobs(), array( 'created_count' => $c['drafted'], 'linked_count' => $c['linked'], 'failed_count' => $c['failed'], 'skipped_count' => $c['skipped'] ), array( 'id' => $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/* ------------------------------------------------------------ Parsers */

	/**
	 * Parse CSV text into header + rows (no formulas evaluated; values are plain text).
	 *
	 * @return array{headers:string[],rows:array<int,string[]>,suggested:array<int,string>}|WP_Error
	 */
	public static function parse_csv( string $csv ) {
		if ( strlen( $csv ) > self::MAX_BYTES ) {
			return new WP_Error( 'lnkup_import_file', __( 'That file is larger than 1 MB. Split it into smaller files.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$csv = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$fh  = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $fh, (string) $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $fh );
		$first = (string) strtok( (string) $csv, "\n" );
		$delim = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ( substr_count( $first, "\t" ) > substr_count( $first, ',' ) ? "\t" : ',' );
		$rows  = array();
		while ( false !== ( $r = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) ) {
			if ( array( null ) === $r || ! array_filter( $r, static fn( $v ) => '' !== trim( (string) $v ) ) ) {
				continue;
			}
			$rows[] = array_map( static fn( $v ) => trim( wp_check_invalid_utf8( (string) $v, true ) ), $r );
			if ( count( $rows ) > self::MAX_ROWS + 1 ) {
				break;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( count( $rows ) < 2 ) {
			return new WP_Error( 'lnkup_import_file', __( 'The file needs a header row and at least one event.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$headers = array_map( static fn( $h ) => mb_substr( sanitize_text_field( $h ), 0, 60 ), array_shift( $rows ) );
		return array( 'headers' => $headers, 'rows' => array_slice( $rows, 0, self::MAX_ROWS + 1 ), 'suggested' => self::suggest_mapping( $headers ) );
	}

	/**
	 * @param string[] $headers Header row.
	 * @return array<int,string> column index => field
	 */
	public static function suggest_mapping( array $headers ): array {
		$aliases = array(
			'name'        => '/^(event\s*)?(name|title)$|event$/i',
			'date'        => '/^(start\s*)?date$|^event date$|^when$/i',
			'end_date'    => '/^end\s*date$/i',
			'start_time'  => '/^(start\s*time|starts|time|doors)$/i',
			'end_time'    => '/^(end\s*time|ends)$/i',
			'venue_name'  => '/^(venue|location name|place)$/i',
			'address'     => '/^(address|street|street address)$/i',
			'city'        => '/^city$/i',
			'state'       => '/^(state|region|province)$/i',
			'zip'         => '/^(zip|zip code|postal code|postcode)$/i',
			'description' => '/^(description|details|about|summary)$/i',
			'url'         => '/^(url|link|event link|ticket link|tickets|website)$/i',
		);
		$out  = array();
		$used = array();
		foreach ( $headers as $i => $h ) {
			foreach ( $aliases as $field => $re ) {
				if ( ! isset( $used[ $field ] ) && preg_match( $re, trim( $h ) ) ) {
					$out[ $i ]      = $field;
					$used[ $field ] = true;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Rows → item inputs using the organizer's column mapping. Unparseable dates are left empty
	 * (and noted) rather than guessed.
	 *
	 * @param array<int,string[]> $rows    Data rows.
	 * @param array<int|string,string> $mapping column index => field
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows_to_inputs( array $rows, array $mapping ): array {
		$mapping = array_filter( array_map( 'sanitize_key', $mapping ), static fn( $f ) => in_array( $f, self::CSV_FIELDS, true ) );
		$inputs  = array();
		foreach ( $rows as $row ) {
			$fields = array();
			$notes  = array();
			$url    = '';
			foreach ( $mapping as $col => $field ) {
				$val = trim( (string) ( $row[ (int) $col ] ?? '' ) );
				if ( '' === $val ) {
					continue;
				}
				if ( 'url' === $field ) {
					$url = esc_url_raw( $val, array( 'http', 'https' ) );
				} elseif ( in_array( $field, array( 'date', 'end_date' ), true ) ) {
					$d = self::parse_date( $val );
					if ( $d ) {
						$fields[ $field ] = $d;
					} else {
						/* translators: %s: the date text from the file */
						$notes[] = sprintf( __( 'Date “%s” was not recognized — add it in the builder.', 'lnkup-marketplace' ), mb_substr( $val, 0, 30 ) );
					}
				} elseif ( in_array( $field, array( 'start_time', 'end_time' ), true ) ) {
					$t = self::parse_time( $val );
					if ( '' !== $t ) {
						$fields[ $field ] = $t;
					}
				} elseif ( 'description' === $field ) {
					$fields[ $field ] = mb_substr( sanitize_textarea_field( $val ), 0, 5000 );
				} elseif ( 'state' === $field ) {
					$code             = class_exists( 'LNKup_Location_Service' ) ? LNKup_Location_Service::state_code( $val ) : null;
					$fields[ $field ] = $code ? (string) $code : mb_substr( sanitize_text_field( $val ), 0, 40 );
				} else {
					$fields[ $field ] = mb_substr( sanitize_text_field( $val ), 0, 'name' === $field ? 140 : 160 );
				}
			}
			$inputs[] = array( 'fields' => $fields, 'url' => $url, 'notes' => $notes );
		}
		return $inputs;
	}

	/**
	 * Explicit, unambiguous formats only: 2026-12-05, 12/05/2026 (US), Dec 5 2026, December 5, 2026.
	 */
	public static function parse_date( string $raw ): string {
		$raw = trim( $raw );
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $raw, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return sprintf( '%04d-%02d-%02d', $m[1], $m[2], $m[3] );
		}
		if ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw, $m ) && checkdate( (int) $m[1], (int) $m[2], (int) $m[3] ) ) {
			return sprintf( '%04d-%02d-%02d', $m[3], $m[1], $m[2] );
		}
		if ( preg_match( '/^(?:[A-Za-z]{3,9},?\s+)?([A-Za-z]{3,9})\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})$/', $raw, $m ) ) {
			$ts = strtotime( $m[1] . ' ' . $m[2] . ' ' . $m[3] );
			if ( $ts ) {
				return gmdate( 'Y-m-d', $ts );
			}
		}
		return '';
	}

	public static function parse_time( string $raw ): string {
		if ( preg_match( '/^(\d{1,2})(?::(\d{2}))?\s*([ap])\.?m\.?$/i', trim( $raw ), $m ) ) {
			$hour = (int) $m[1] % 12 + ( 'p' === strtolower( $m[3] ) ? 12 : 0 );
			return sprintf( '%02d:%02d', $hour, (int) ( $m[2] ?? 0 ) );
		}
		if ( preg_match( '/^(\d{1,2}):(\d{2})/', trim( $raw ), $m ) && (int) $m[1] < 24 && (int) $m[2] < 60 ) {
			return sprintf( '%02d:%02d', $m[1], $m[2] );
		}
		return '';
	}

	/**
	 * ICS (RFC 5545) VEVENTs → item inputs. Local times keep their TZID; UTC times are converted
	 * to the site time zone. Recurrence rules are noted, not expanded.
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function parse_ics( string $ics, bool $future_only = true ) {
		if ( strlen( $ics ) > self::MAX_BYTES ) {
			return new WP_Error( 'lnkup_import_file', __( 'That file is larger than 1 MB.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( false === stripos( $ics, 'BEGIN:VCALENDAR' ) ) {
			return new WP_Error( 'lnkup_import_file', __( 'That is not a calendar (.ics) file.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$ics    = preg_replace( "/\r?\n[ \t]/", '', str_replace( "\r\n", "\n", $ics ) );
		$inputs = array();
		$today  = current_time( 'Y-m-d' );
		if ( ! preg_match_all( '/BEGIN:VEVENT\n(.*?)\nEND:VEVENT/s', (string) $ics, $blocks ) ) {
			return new WP_Error( 'lnkup_import_file', __( 'No events were found in that calendar file.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		foreach ( $blocks[1] as $block ) {
			$props = array();
			foreach ( explode( "\n", $block ) as $line ) {
				if ( ! preg_match( '/^([A-Z-]+)((?:;[^:]*)?):(.*)$/', $line, $m ) ) {
					continue;
				}
				$props[ $m[1] ] = array( 'params' => $m[2], 'value' => $m[3] );
			}
			if ( isset( $props['STATUS'] ) && 'CANCELLED' === strtoupper( $props['STATUS']['value'] ) ) {
				continue;
			}
			$start = isset( $props['DTSTART'] ) ? self::ics_datetime( $props['DTSTART'] ) : null;
			if ( ! $start || ( $future_only && $start['date'] < $today ) ) {
				continue;
			}
			$end    = isset( $props['DTEND'] ) ? self::ics_datetime( $props['DTEND'] ) : null;
			$text   = static fn( string $k ) => isset( $props[ $k ] ) ? str_replace( array( '\\n', '\\N', '\\,', '\\;', '\\\\' ), array( "\n", "\n", ',', ';', '\\' ), $props[ $k ]['value'] ) : '';
			$fields = array( 'name' => mb_substr( sanitize_text_field( $text( 'SUMMARY' ) ), 0, 140 ), 'date' => $start['date'], 'start_time' => $start['time'] );
			if ( $start['tz'] ) {
				$fields['timezone'] = $start['tz'];
			}
			if ( $end ) {
				if ( $end['all_day'] ) {
					$last = gmdate( 'Y-m-d', strtotime( $end['date'] . ' -1 day' ) );
					if ( $last > $start['date'] ) {
						$fields['end_date'] = $last;
					}
				} else {
					$fields['end_time'] = $end['time'];
					if ( $end['date'] > $start['date'] && $end['time'] > '06:00' ) {
						$fields['end_date'] = $end['date'];
					}
				}
			}
			$loc = $text( 'LOCATION' );
			if ( '' !== $loc ) {
				$parts = array_map( 'trim', explode( ',', $loc ) );
				$fields['venue_name'] = mb_substr( sanitize_text_field( $parts[0] ), 0, 120 );
				if ( count( $parts ) >= 3 && preg_match( '/^([A-Z]{2})(?:\s+\d{5})?/', $parts[ count( $parts ) - 1 ] . '', $sm ) ) {
					$fields['state'] = $sm[1];
					$fields['city']  = mb_substr( sanitize_text_field( $parts[ count( $parts ) - 2 ] ), 0, 80 );
				} elseif ( count( $parts ) >= 3 && preg_match( '/^(USA|United States)$/i', end( $parts ) ) && preg_match( '/^([A-Z]{2})(?:\s+\d{5})?$/', $parts[ count( $parts ) - 2 ], $sm ) ) {
					$fields['state'] = $sm[1];
					$fields['city']  = mb_substr( sanitize_text_field( $parts[ count( $parts ) - 3 ] ), 0, 80 );
				}
			}
			$desc = $text( 'DESCRIPTION' );
			if ( '' !== $desc ) {
				$fields['description'] = mb_substr( sanitize_textarea_field( $desc ), 0, 5000 );
			}
			$notes = isset( $props['RRULE'] ) ? array( __( 'This calendar event repeats. Import it once, then set up the other dates as a recurring event.', 'lnkup-marketplace' ) ) : array();
			$url   = isset( $props['URL'] ) ? esc_url_raw( $props['URL']['value'], array( 'http', 'https' ) ) : '';
			$inputs[] = array( 'fields' => array_filter( $fields ), 'url' => $url, 'notes' => $notes, 'uid' => mb_substr( sanitize_text_field( $text( 'UID' ) ), 0, 100 ) );
			if ( count( $inputs ) >= self::MAX_ROWS ) {
				break;
			}
		}
		return $inputs;
	}

	/**
	 * @param array{params:string,value:string} $prop DTSTART/DTEND.
	 * @return array{date:string,time:string,tz:string,all_day:bool}|null
	 */
	private static function ics_datetime( array $prop ): ?array {
		$v = trim( $prop['value'] );
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $v, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? array( 'date' => "{$m[1]}-{$m[2]}-{$m[3]}", 'time' => '', 'tz' => '', 'all_day' => true ) : null;
		}
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})\d{0,2}(Z?)$/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		$tz = preg_match( '/TZID=("?)([^;:"]+)\1/', $prop['params'], $t ) && in_array( $t[2], timezone_identifiers_list(), true ) ? $t[2] : '';
		if ( 'Z' === $m[6] ) {
			$zone = wp_timezone();
			$dt   = new DateTimeImmutable( "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:00", new DateTimeZone( 'UTC' ) );
			$dt   = $dt->setTimezone( $zone );
			return array( 'date' => $dt->format( 'Y-m-d' ), 'time' => $dt->format( 'H:i' ), 'tz' => in_array( $zone->getName(), timezone_identifiers_list(), true ) ? $zone->getName() : '', 'all_day' => false );
		}
		return array( 'date' => "{$m[1]}-{$m[2]}-{$m[3]}", 'time' => "{$m[4]}:{$m[5]}", 'tz' => $tz, 'all_day' => false );
	}
}
