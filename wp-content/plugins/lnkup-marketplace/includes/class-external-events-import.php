<?php
/**
 * External events CSV import.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Events_Import {

	/**
	 * CSV column aliases.
	 *
	 * @return array<string, string>
	 */
	public static function header_aliases(): array {
		return array(
			'title'               => 'title',
			'event title'         => 'title',
			'source_url'          => 'source_url',
			'url'                 => 'source_url',
			'link'                => 'source_url',
			'date'                => 'date',
			'event_date'          => 'date',
			'start_date'          => 'date',
			'city'                => 'city',
			'state'               => 'state',
			'venue'               => 'venue',
			'venue_name'          => 'venue',
			'organizer_name'      => 'organizer_name',
			'organizer'           => 'organizer_name',
			'organizer_url'       => 'organizer_url',
			'image_url'           => 'image_url',
			'image'               => 'image_url',
			'event_type'          => 'event_type',
			'type'                => 'event_type',
			'organizer_email'     => 'organizer_email',
			'email'               => 'organizer_email',
			'description_excerpt' => 'description_excerpt',
			'description'         => 'description_excerpt',
			'source_event_id'     => 'source_event_id',
		);
	}

	/**
	 * Parse CSV string into preview rows.
	 *
	 * @param string $csv Raw CSV content.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function preview_csv( string $csv ) {
		$rows = self::parse_csv( $csv );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$preview = array(
			'new'       => array(),
			'duplicate' => array(),
			'errors'    => array(),
		);

		foreach ( $rows as $index => $row ) {
			$mapped = self::map_row( $row );
			if ( is_wp_error( $mapped ) ) {
				$preview['errors'][] = array(
					'row'     => $index + 2,
					'message' => $mapped->get_error_message(),
				);
				continue;
			}
			$dup = LNKup_External_Events::find_duplicate(
				(string) ( $mapped['source_url'] ?? '' ),
				(string) ( $mapped['source_event_id'] ?? '' ),
				(string) ( $mapped['source_platform'] ?? 'eventbrite' )
			);
			if ( $dup ) {
				$preview['duplicate'][] = array(
					'row'         => $index + 2,
					'title'       => $mapped['title'] ?? '',
					'existing_id' => (int) $dup['id'],
				);
				continue;
			}
			$preview['new'][] = array(
				'row'  => $index + 2,
				'data' => $mapped,
			);
		}

		return $preview;
	}

	/**
	 * Import CSV rows (skip duplicates).
	 *
	 * @param string $csv Raw CSV.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function import_csv( string $csv ) {
		$preview = self::preview_csv( $csv );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$created = 0;
		$skipped = count( $preview['duplicate'] );
		$failed  = count( $preview['errors'] );

		foreach ( $preview['new'] as $entry ) {
			$result = LNKup_External_Events::create( $entry['data'] );
			if ( is_wp_error( $result ) ) {
				++$failed;
				continue;
			}
			++$created;
		}

		return array(
			'created' => $created,
			'skipped' => $skipped,
			'failed'  => $failed,
		);
	}

	/**
	 * @param string $csv CSV content.
	 * @return array<int, array<string, string>>|WP_Error
	 */
	protected static function parse_csv( string $csv ) {
		$csv = trim( $csv );
		if ( '' === $csv ) {
			return new WP_Error( 'empty', __( 'CSV is empty.', 'lnkup-marketplace' ) );
		}

		$lines = preg_split( '/\r\n|\r|\n/', $csv );
		if ( ! $lines || count( $lines ) < 2 ) {
			return new WP_Error( 'invalid', __( 'CSV must include a header row and at least one data row.', 'lnkup-marketplace' ) );
		}

		$headers = str_getcsv( array_shift( $lines ) );
		$headers = array_map(
			static function ( $h ) {
				return strtolower( trim( (string) $h ) );
			},
			$headers
		);

		$rows = array();
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = str_getcsv( $line );
			$row   = array();
			foreach ( $headers as $i => $header ) {
				$row[ $header ] = isset( $cells[ $i ] ) ? trim( (string) $cells[ $i ] ) : '';
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * @param array<string, string> $row Raw CSV row keyed by header.
	 * @return array<string, mixed>|WP_Error
	 */
	protected static function map_row( array $row ) {
		$aliases = self::header_aliases();
		$mapped  = array(
			'source_platform' => 'eventbrite',
		);

		foreach ( $row as $header => $value ) {
			$key = $aliases[ strtolower( $header ) ] ?? null;
			if ( $key ) {
				$mapped[ $key ] = $value;
			}
		}

		if ( empty( $mapped['title'] ) ) {
			return new WP_Error( 'missing_title', __( 'Title is required.', 'lnkup-marketplace' ) );
		}
		if ( empty( $mapped['source_url'] ) ) {
			return new WP_Error( 'missing_url', __( 'Source URL is required.', 'lnkup-marketplace' ) );
		}

		if ( ! empty( $mapped['date'] ) ) {
			$mapped['start_datetime'] = $mapped['date'];
		}
		if ( ! empty( $mapped['venue'] ) ) {
			$mapped['venue_name'] = $mapped['venue'];
		}

		return $mapped;
	}
}
