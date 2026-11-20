<?php
/**
 * Organizer vendor CSV import service.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Import {

	const STATUS_UPLOADED  = 'uploaded';
	const STATUS_PREVIEWED = 'previewed';
	const STATUS_COMPLETED = 'completed';
	const STATUS_FAILED    = 'failed';

	/**
	 * Canonical import fields.
	 *
	 * @return string[]
	 */
	public static function canonical_fields() {
		return array(
			'business_name',
			'contact_name',
			'email',
			'phone',
			'vendor_category',
			'instagram',
			'tiktok',
			'website',
			'city',
			'state',
			'products_services',
			'notes',
			'tags',
			'status',
			'paid_status',
			'booth_fee',
			'booth_number',
			'event_name',
			'opportunity_name',
			'application_status',
			'documents_status',
			'coi_status',
			'health_permit_status',
			'business_license_status',
			'w9_status',
			'setup_type',
			'tent_size',
			'food_truck_size',
			'power_needs',
			'water_needs',
		);
	}

	/**
	 * Header alias map for auto-detection.
	 *
	 * @return array<string, string[]>
	 */
	public static function header_aliases() {
		return array(
			'business_name'       => array( 'business', 'business_name', 'company', 'vendor', 'vendor_name', 'brand' ),
			'contact_name'        => array( 'name', 'contact', 'contact_name', 'owner', 'owner_name' ),
			'email'               => array( 'email', 'email_address', 'contact_email' ),
			'phone'               => array( 'phone', 'phone_number', 'mobile', 'contact_phone' ),
			'vendor_category'     => array( 'category', 'vendor_category', 'type', 'business_type' ),
			'instagram'           => array( 'instagram', 'ig', 'instagram_handle' ),
			'tiktok'              => array( 'tiktok', 'tiktok_handle' ),
			'website'             => array( 'website', 'url', 'site' ),
			'city'                => array( 'city', 'market' ),
			'state'               => array( 'state', 'st' ),
			'products_services'   => array( 'products', 'services', 'products_services', 'menu' ),
			'notes'               => array( 'notes', 'internal_notes', 'comments' ),
			'tags'                => array( 'tags', 'labels' ),
			'booth_fee'           => array( 'booth_fee', 'fee', 'vendor_fee' ),
			'booth_number'        => array( 'booth_number', 'booth', 'booth_no' ),
			'setup_type'          => array( 'setup_type', 'setup' ),
			'tent_size'           => array( 'tent_size', 'tent' ),
			'food_truck_size'     => array( 'food_truck_size', 'truck_size' ),
			'power_needs'         => array( 'power_needs', 'power' ),
			'water_needs'         => array( 'water_needs', 'water' ),
		);
	}

	/**
	 * Max rows per import.
	 */
	public static function max_rows() {
		$settings = lnkup_get_settings();
		$max      = isset( $settings['crm_import_max_rows_organizer'] ) ? (int) $settings['crm_import_max_rows_organizer'] : 500;
		return max( 1, min( 2000, $max ) );
	}

	/**
	 * Max upload size in bytes.
	 */
	public static function max_file_bytes() {
		$settings = lnkup_get_settings();
		$mb       = isset( $settings['crm_import_max_file_size_mb'] ) ? (float) $settings['crm_import_max_file_size_mb'] : 5;
		return (int) ( max( 1, min( 20, $mb ) ) * 1024 * 1024 );
	}

	/**
	 * CSV template headers.
	 */
	public static function template_headers() {
		return array(
			'Business Name',
			'Contact Name',
			'Email',
			'Phone',
			'Category',
			'Instagram',
			'TikTok',
			'Website',
			'City',
			'State',
			'Products/Services',
			'Setup Type',
			'Tent Size',
			'Power Needs',
			'Water Needs',
			'Notes',
			'Tags',
		);
	}

	/**
	 * Generate CSV template content.
	 */
	public static function template_csv() {
		$headers = self::template_headers();
		$out     = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, $headers );
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $csv ?: '';
	}

	/**
	 * Normalize email.
	 *
	 * @param string $email Email.
	 */
	public static function normalize_email( $email ) {
		$email = strtolower( trim( sanitize_email( (string) $email ) ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Normalize phone.
	 *
	 * @param string $phone Phone.
	 */
	public static function normalize_phone( $phone ) {
		return preg_replace( '/[^\d+]/', '', (string) $phone );
	}

	/**
	 * Guess column mapping from CSV headers.
	 *
	 * @param array $headers Headers.
	 */
	public static function guess_column_mapping( $headers ) {
		$mapping = array();
		$aliases = self::header_aliases();
		foreach ( (array) $headers as $index => $header ) {
			$norm = strtolower( preg_replace( '/[^a-z0-9]+/', '_', trim( (string) $header ) ) );
			$norm = trim( $norm, '_' );
			foreach ( $aliases as $field => $list ) {
				if ( in_array( $norm, $list, true ) ) {
					$mapping[ $field ] = (int) $index;
					break;
				}
			}
		}
		return $mapping;
	}

	/**
	 * Parse CSV file into headers and rows.
	 *
	 * @param string $path File path.
	 */
	public static function parse_csv_file( $path ) {
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'file_missing', __( 'Import file not found.', 'lnkup-marketplace' ) );
		}

		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return new WP_Error( 'file_read', __( 'Could not read import file.', 'lnkup-marketplace' ) );
		}

		$headers = fgetcsv( $handle );
		if ( ! $headers || ! is_array( $headers ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'no_headers', __( 'CSV must include a header row.', 'lnkup-marketplace' ) );
		}

		if ( isset( $headers[0] ) ) {
			$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $headers[0] );
		}
		$headers = array_map(
			function ( $h ) {
				return sanitize_text_field( (string) $h );
			},
			$headers
		);

		$rows    = array();
		$max     = self::max_rows();
		$line_no = 1;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			++$line_no;
			if ( self::row_is_empty( $row ) ) {
				continue;
			}
			$rows[] = array(
				'line' => $line_no,
				'raw'  => $row,
			);
			if ( count( $rows ) >= $max ) {
				break;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * Whether CSV row is empty.
	 *
	 * @param array $row Row.
	 */
	private static function row_is_empty( $row ) {
		foreach ( (array) $row as $cell ) {
			if ( '' !== trim( (string) $cell ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Map raw row to canonical fields.
	 *
	 * @param array $raw_row Raw CSV row.
	 * @param array $mapping Field => column index.
	 */
	public static function sanitize_row( $raw_row, $mapping ) {
		$data = array();
		foreach ( self::canonical_fields() as $field ) {
			$idx = isset( $mapping[ $field ] ) ? (int) $mapping[ $field ] : -1;
			$val = ( $idx >= 0 && isset( $raw_row[ $idx ] ) ) ? trim( (string) $raw_row[ $idx ] ) : '';
			if ( 'email' === $field ) {
				$data[ $field ] = self::normalize_email( $val );
			} elseif ( 'phone' === $field ) {
				$data[ $field ] = self::normalize_phone( $val );
			} else {
				$data[ $field ] = sanitize_text_field( $val );
			}
		}
		return $data;
	}

	/**
	 * Validate mapped rows.
	 *
	 * @param array $rows    Parsed rows with raw.
	 * @param array $mapping Column mapping.
	 * @param array $options Import options.
	 */
	public static function validate_rows( $rows, $mapping, $options = array() ) {
		$send_invites = ! empty( $options['send_invites'] );
		$results      = array(
			'ready'          => array(),
			'duplicate'      => array(),
			'invalid'        => array(),
			'missing_email'  => array(),
			'existing_user'  => array(),
			'prospect'       => array(),
		);

		$seen_emails   = array();
		$seen_business = array();
		$organizer_id  = (int) ( $options['organizer_id'] ?? get_current_user_id() );

		foreach ( (array) $rows as $entry ) {
			$row   = self::sanitize_row( $entry['raw'], $mapping );
			$line  = (int) ( $entry['line'] ?? 0 );
			$item  = array( 'line' => $line, 'data' => $row );

			if ( empty( $row['business_name'] ) && empty( $row['contact_name'] ) ) {
				$results['invalid'][] = array_merge( $item, array( 'reason' => __( 'Business or contact name required.', 'lnkup-marketplace' ) ) );
				continue;
			}

			if ( $send_invites && empty( $row['email'] ) ) {
				$results['invalid'][] = array_merge( $item, array( 'reason' => __( 'Email required to send invites.', 'lnkup-marketplace' ) ) );
				continue;
			}

			if ( empty( $row['email'] ) ) {
				$results['missing_email'][] = $item;
			}

			if ( $row['email'] ) {
				if ( isset( $seen_emails[ $row['email'] ] ) ) {
					$results['duplicate'][] = array_merge( $item, array( 'reason' => __( 'Duplicate email in file.', 'lnkup-marketplace' ) ) );
					continue;
				}
				$seen_emails[ $row['email'] ] = true;

				$existing_user = self::find_existing_vendor_user_by_email( $row['email'] );
				if ( $existing_user ) {
					$results['existing_user'][] = array_merge( $item, array( 'user_id' => $existing_user ) );
					continue;
				}

				$prospect = self::find_existing_prospect( $organizer_id, $row['email'], '' );
				if ( $prospect ) {
					$results['duplicate'][] = array_merge( $item, array( 'reason' => __( 'Already in your imported vendors.', 'lnkup-marketplace' ), 'prospect_id' => (int) $prospect['id'] ) );
					continue;
				}
			} elseif ( $row['business_name'] ) {
				$bkey = strtolower( $row['business_name'] );
				if ( isset( $seen_business[ $bkey ] ) ) {
					$results['duplicate'][] = array_merge( $item, array( 'reason' => __( 'Duplicate business name in file.', 'lnkup-marketplace' ) ) );
					continue;
				}
				$seen_business[ $bkey ] = true;
				$prospect = self::find_existing_prospect( $organizer_id, '', $row['business_name'] );
				if ( $prospect ) {
					$results['duplicate'][] = array_merge( $item, array( 'reason' => __( 'Business already imported.', 'lnkup-marketplace' ), 'prospect_id' => (int) $prospect['id'] ) );
					continue;
				}
			}

			if ( empty( $row['email'] ) ) {
				$results['prospect'][] = $item;
			} else {
				$results['ready'][] = $item;
			}
		}

		return $results;
	}

	/**
	 * Find vendor user by email.
	 *
	 * @param string $email Email.
	 */
	public static function find_existing_vendor_user_by_email( $email ) {
		$email = self::normalize_email( $email );
		if ( ! $email ) {
			return 0;
		}
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return 0;
		}
		if ( LNKup_Roles::is_vendor( $user->ID ) || LNKup_Capabilities::user_has_manage_all( $user->ID ) ) {
			return (int) $user->ID;
		}
		return 0;
	}

	/**
	 * Find existing prospect.
	 *
	 * @param int    $organizer_id  Organizer ID.
	 * @param string $email         Email.
	 * @param string $business_name Business name.
	 */
	public static function find_existing_prospect( $organizer_id, $email = '', $business_name = '' ) {
		global $wpdb;
		$table = LNKup_Vendor_Import_Tables::prospect_table();
		$email = self::normalize_email( $email );
		if ( $email ) {
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE organizer_user_id = %d AND normalized_email = %s AND linked_user_id = 0 LIMIT 1",
					absint( $organizer_id ),
					$email
				),
				ARRAY_A
			);
		}
		if ( $business_name ) {
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE organizer_user_id = %d AND business_name = %s AND normalized_email = '' AND linked_user_id = 0 LIMIT 1",
					absint( $organizer_id ),
					sanitize_text_field( $business_name )
				),
				ARRAY_A
			);
		}
		return null;
	}

	/**
	 * Find CRM relationship.
	 *
	 * @param int $organizer_id Organizer ID.
	 * @param int $vendor_id    Vendor user ID.
	 */
	public static function find_existing_crm_relationship( $organizer_id, $vendor_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . LNKup_Organizer_CRM_Tables::rel_table() . ' WHERE organizer_user_id = %d AND vendor_user_id = %d LIMIT 1',
				absint( $organizer_id ),
				absint( $vendor_id )
			),
			ARRAY_A
		);
	}

	/**
	 * Store uploaded CSV and create import job.
	 *
	 * @param int   $organizer_id Organizer ID.
	 * @param array $file         $_FILES entry.
	 */
	public static function handle_upload( $organizer_id, $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', __( 'No file uploaded.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		if ( ! empty( $file['size'] ) && (int) $file['size'] > self::max_file_bytes() ) {
			return new WP_Error( 'file_too_large', __( 'File exceeds the maximum import size.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			return new WP_Error( 'invalid_type', __( 'Only CSV files are supported.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$upload_dir = wp_upload_dir();
		$dest_dir   = trailingslashit( $upload_dir['basedir'] ) . 'lnkup-imports/' . absint( $organizer_id );
		if ( ! wp_mkdir_p( $dest_dir ) ) {
			return new WP_Error( 'upload_dir', __( 'Could not create import directory.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}

		$filename = wp_generate_uuid4() . '.csv';
		$dest     = $dest_dir . '/' . $filename;
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'move_failed', __( 'Could not save uploaded file.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}

		$parsed = self::parse_csv_file( $dest );
		if ( is_wp_error( $parsed ) ) {
			wp_delete_file( $dest );
			return $parsed;
		}

		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			LNKup_Vendor_Import_Tables::job_table(),
			array(
				'organizer_user_id'  => absint( $organizer_id ),
				'file_path'          => $dest,
				'original_filename'  => sanitize_file_name( $file['name'] ),
				'headers_json'       => wp_json_encode( $parsed['headers'] ),
				'row_count'          => count( $parsed['rows'] ),
				'status'             => self::STATUS_UPLOADED,
				'created_at'         => $now,
				'expires_at'         => gmdate( 'Y-m-d H:i:s', strtotime( '+24 hours' ) ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		$job_id = (int) $wpdb->insert_id;
		$sample = array_slice( $parsed['rows'], 0, 5 );
		$sample_rows = array();
		foreach ( $sample as $s ) {
			$sample_rows[] = $s['raw'];
		}

		LNKup_Vendor_Import::log_import_activity(
			'import_uploaded',
			$organizer_id,
			array( 'import_id' => $job_id, 'rows' => count( $parsed['rows'] ) )
		);

		return array(
			'import_id'    => $job_id,
			'headers'      => $parsed['headers'],
			'sample_rows'  => $sample_rows,
			'row_count'    => count( $parsed['rows'] ),
			'suggested_mapping' => self::guess_column_mapping( $parsed['headers'] ),
		);
	}

	/**
	 * Get import job owned by organizer.
	 *
	 * @param int $job_id       Job ID.
	 * @param int $organizer_id Organizer ID.
	 */
	public static function get_job( $job_id, $organizer_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . LNKup_Vendor_Import_Tables::job_table() . ' WHERE id = %d AND organizer_user_id = %d LIMIT 1',
				absint( $job_id ),
				absint( $organizer_id )
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Preview import.
	 *
	 * @param int   $organizer_id Organizer ID.
	 * @param int   $job_id       Job ID.
	 * @param array $mapping      Column mapping.
	 * @param array $options      Options.
	 */
	public static function preview_import( $organizer_id, $job_id, $mapping, $options = array() ) {
		$job = self::get_job( $job_id, $organizer_id );
		if ( ! $job ) {
			return new WP_Error( 'not_found', __( 'Import not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}

		$parsed = self::parse_csv_file( $job['file_path'] );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$mapping = self::sanitize_mapping( $mapping );
		$options['organizer_id'] = $organizer_id;
		$validation = self::validate_rows( $parsed['rows'], $mapping, $options );

		global $wpdb;
		$wpdb->update(
			LNKup_Vendor_Import_Tables::job_table(),
			array(
				'mapping_json' => wp_json_encode( $mapping ),
				'options_json' => wp_json_encode( $options ),
				'status'       => self::STATUS_PREVIEWED,
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		LNKup_Vendor_Import::log_import_activity(
			'import_previewed',
			$organizer_id,
			array( 'import_id' => $job_id )
		);

		return array(
			'import_id'  => $job_id,
			'total'      => count( $parsed['rows'] ),
			'ready'      => count( $validation['ready'] ) + count( $validation['prospect'] ) + count( $validation['existing_user'] ),
			'duplicates' => count( $validation['duplicate'] ),
			'invalid'    => count( $validation['invalid'] ),
			'missing_email' => count( $validation['missing_email'] ),
			'existing_users' => count( $validation['existing_user'] ),
			'prospects'  => count( $validation['prospect'] ) + count( array_filter( $validation['ready'], function ( $r ) {
				return empty( $r['data']['email'] );
			} ) ),
			'validation' => $validation,
		);
	}

	/**
	 * Sanitize mapping keys.
	 *
	 * @param array $mapping Mapping.
	 */
	public static function sanitize_mapping( $mapping ) {
		$clean = array();
		$allowed = self::canonical_fields();
		foreach ( (array) $mapping as $field => $index ) {
			$field = sanitize_key( (string) $field );
			if ( in_array( $field, $allowed, true ) && '' !== $index && null !== $index && 'ignore' !== $index ) {
				$clean[ $field ] = (int) $index;
			}
		}
		return $clean;
	}

	/**
	 * Run import.
	 *
	 * @param int   $organizer_id Organizer ID.
	 * @param int   $job_id       Job ID.
	 * @param array $mapping      Mapping.
	 * @param array $options      Options.
	 */
	public static function run_import( $organizer_id, $job_id, $mapping, $options = array() ) {
		$job = self::get_job( $job_id, $organizer_id );
		if ( ! $job ) {
			return new WP_Error( 'not_found', __( 'Import not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}

		$mapping = self::sanitize_mapping( $mapping );
		$options = wp_parse_args(
			(array) $options,
			array(
				'duplicate_mode'   => 'skip',
				'send_invites'     => false,
				'confirm_invites'  => false,
				'list_id'          => 0,
				'new_list_name'    => '',
				'tags'             => array(),
				'event_id'         => 0,
				'opportunity_id'   => 0,
				'invite_message'   => '',
			)
		);

		if ( ! empty( $options['send_invites'] ) && empty( $options['confirm_invites'] ) ) {
			return new WP_Error( 'confirm_required', __( 'Confirm invite sending before import.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$settings = lnkup_get_settings();
		if ( ! empty( $options['send_invites'] ) && empty( $settings['crm_import_allow_invite'] ) ) {
			return new WP_Error( 'invites_disabled', __( 'Invites during import are disabled.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		$parsed = self::parse_csv_file( $job['file_path'] );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$list_id = absint( $options['list_id'] );
		if ( ! empty( $options['new_list_name'] ) ) {
			$created = LNKup_Vendor_Lists::create( $organizer_id, $options['new_list_name'] );
			if ( ! is_wp_error( $created ) ) {
				$list_id = (int) $created;
			}
		}

		if ( $list_id && ! LNKup_Capabilities::organizer_owns_crm_object( $list_id, $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not own this vendor list.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		$event_id = absint( $options['event_id'] );
		if ( $event_id && ! LNKup_Capabilities::organizer_owns_event( $event_id, $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not own this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		$opp_id = absint( $options['opportunity_id'] );
		if ( $opp_id ) {
			$opp_event = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
			if ( $opp_event && ! LNKup_Capabilities::organizer_owns_event( $opp_event, $organizer_id ) ) {
				return new WP_Error( 'forbidden', __( 'You do not own this opportunity.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
		}

		$summary = array(
			'imported'            => 0,
			'updated'             => 0,
			'skipped_duplicate'   => 0,
			'skipped_invalid'     => 0,
			'existing_user_linked'=> 0,
			'crm_record_created'  => 0,
			'invited'             => 0,
			'invite_failed'       => 0,
			'added_to_list'       => 0,
			'tagged'              => 0,
			'rows'                => array(),
		);

		$outreach_lines = array();
		$duplicate_mode = sanitize_key( (string) $options['duplicate_mode'] );
		$options['organizer_id'] = $organizer_id;
		$validation = self::validate_rows( $parsed['rows'], $mapping, $options );

		$process = array_merge(
			$validation['ready'],
			$validation['existing_user'],
			$validation['prospect'],
			$validation['missing_email']
		);

		foreach ( $process as $entry ) {
			$row  = $entry['data'];
			$line = (int) $entry['line'];
			$user_id = isset( $entry['user_id'] ) ? (int) $entry['user_id'] : self::find_existing_vendor_user_by_email( $row['email'] ?? '' );

			if ( $user_id ) {
				$result = self::import_existing_user_row( $organizer_id, $user_id, $row, $list_id, $options, $duplicate_mode, $summary );
				$summary['rows'][] = array( 'line' => $line, 'status' => $result );
				continue;
			}

			$existing_prospect = self::find_existing_prospect( $organizer_id, $row['email'] ?? '', $row['business_name'] ?? '' );
			if ( $existing_prospect && 'skip' === $duplicate_mode ) {
				if ( $list_id && (int) $existing_prospect['id'] ) {
					self::add_prospect_to_list( $list_id, (int) $existing_prospect['id'], $organizer_id );
					++$summary['added_to_list'];
				}
				++$summary['skipped_duplicate'];
				$summary['rows'][] = array( 'line' => $line, 'status' => 'skipped_duplicate' );
				continue;
			}

			if ( empty( $row['business_name'] ) && empty( $row['contact_name'] ) ) {
				++$summary['skipped_invalid'];
				$summary['rows'][] = array( 'line' => $line, 'status' => 'skipped_invalid' );
				continue;
			}

			$prospect_id = self::upsert_prospect( $organizer_id, $job_id, $row, $existing_prospect, 'update' === $duplicate_mode );
			if ( ! $prospect_id ) {
				++$summary['skipped_invalid'];
				$summary['rows'][] = array( 'line' => $line, 'status' => 'skipped_invalid' );
				continue;
			}

			if ( $existing_prospect && 'update' === $duplicate_mode ) {
				++$summary['updated'];
			} else {
				++$summary['crm_record_created'];
				++$summary['imported'];
			}

			if ( $list_id ) {
				self::add_prospect_to_list( $list_id, $prospect_id, $organizer_id );
				++$summary['added_to_list'];
			}

			if ( ! empty( $row['email'] ) && ! empty( $options['send_invites'] ) ) {
				$name = $row['contact_name'] ?: $row['business_name'];
				$outreach_lines[] = $row['email'] . ',' . $name;
			}

			$summary['rows'][] = array( 'line' => $line, 'status' => 'crm_record_created', 'prospect_id' => $prospect_id );
		}

		foreach ( $validation['duplicate'] as $dup ) {
			++$summary['skipped_duplicate'];
			$summary['rows'][] = array( 'line' => (int) $dup['line'], 'status' => 'skipped_duplicate' );
		}
		foreach ( $validation['invalid'] as $inv ) {
			++$summary['skipped_invalid'];
			$summary['rows'][] = array( 'line' => (int) $inv['line'], 'status' => 'skipped_invalid' );
		}

		$campaign_id = 0;
		if ( ! empty( $options['send_invites'] ) && $outreach_lines ) {
			$campaign_id = self::queue_outreach_invites( $organizer_id, $outreach_lines, $options, $job_id );
			if ( is_wp_error( $campaign_id ) ) {
				$summary['invite_error'] = $campaign_id->get_error_message();
			} else {
				$summary['invited'] = count( $outreach_lines );
			}
		}

		global $wpdb;
		$wpdb->update(
			LNKup_Vendor_Import_Tables::job_table(),
			array(
				'status'       => self::STATUS_COMPLETED,
				'summary_json' => wp_json_encode( $summary ),
				'mapping_json' => wp_json_encode( $mapping ),
				'options_json' => wp_json_encode( $options ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		self::cleanup_job_file( $job );

		LNKup_Vendor_Import::log_import_activity(
			'import_completed',
			$organizer_id,
			array( 'import_id' => $job_id, 'summary' => $summary )
		);

		return array(
			'import_id'   => $job_id,
			'summary'     => $summary,
			'list_id'     => $list_id,
			'campaign_id' => $campaign_id,
		);
	}

	/**
	 * Import row for existing vendor user.
	 */
	private static function import_existing_user_row( $organizer_id, $user_id, $row, $list_id, $options, $duplicate_mode, &$summary ) {
		$rel_existed = (bool) self::find_existing_crm_relationship( $organizer_id, $user_id );
		LNKup_Organizer_CRM::ensure_relationship( $organizer_id, $user_id );
		if ( $rel_existed ) {
			++$summary['existing_user_linked'];
		} else {
			++$summary['imported'];
		}

		$profile_id = lnkup_get_vendor_profile_id( $user_id );
		if ( $list_id && $profile_id ) {
			LNKup_Vendor_Lists::add_vendor( $list_id, $organizer_id, $profile_id );
			++$summary['added_to_list'];
		}

		$tags = self::parse_tags( $row['tags'] ?? '', $options['tags'] ?? array() );
		foreach ( $tags as $tag ) {
			$slug = sanitize_key( $tag );
			if ( $slug ) {
				LNKup_Vendor_Tags::add( $organizer_id, $user_id, $slug, $tag );
				++$summary['tagged'];
			}
		}

		if ( 'update' === $duplicate_mode && ! empty( $row['notes'] ) && class_exists( 'LNKup_Vendor_Notes' ) ) {
			LNKup_Vendor_Notes::create( $organizer_id, $user_id, $row['notes'] );
			++$summary['updated'];
		}

		if ( ! empty( $options['send_invites'] ) && ! empty( $options['confirm_invites'] ) ) {
			$opp_id = absint( $options['opportunity_id'] );
			$evt_id = absint( $options['event_id'] );
			$invite = LNKup_Vendor_Invites::create(
				$organizer_id,
				$user_id,
				array(
					'event_id'       => $evt_id,
					'opportunity_id' => $opp_id,
					'message'        => sanitize_textarea_field( $options['invite_message'] ?? '' ),
					'type'           => 'apply',
				)
			);
			if ( is_wp_error( $invite ) ) {
				++$summary['invite_failed'];
				return 'invite_failed';
			}
			++$summary['invited'];
			LNKup_Organizer_CRM::log_activity(
				'vendor_invited_from_import',
				$organizer_id,
				$user_id,
				array( 'payload' => array( 'invite_id' => $invite['invite_id'] ?? 0 ) )
			);
		}

		LNKup_Organizer_CRM::log_activity( 'vendor_imported', $organizer_id, $user_id, array( 'payload' => array( 'source' => 'csv' ) ) );
		return $rel_existed ? 'existing_user_linked' : 'imported';
	}

	/**
	 * Parse tags from CSV and options.
	 *
	 * @param string $csv_tags CSV tags.
	 * @param array  $extra    Extra tags.
	 */
	private static function parse_tags( $csv_tags, $extra = array() ) {
		$tags = array();
		if ( $csv_tags ) {
			$parts = preg_split( '/[,;]/', $csv_tags );
			foreach ( (array) $parts as $p ) {
				$p = trim( $p );
				if ( $p ) {
					$tags[] = $p;
				}
			}
		}
		foreach ( (array) $extra as $t ) {
			$t = trim( (string) $t );
			if ( $t ) {
				$tags[] = $t;
			}
		}
		return array_unique( $tags );
	}

	/**
	 * Upsert prospect row.
	 */
	private static function upsert_prospect( $organizer_id, $job_id, $row, $existing, $update ) {
		global $wpdb;
		$table = LNKup_Vendor_Import_Tables::prospect_table();
		$now   = current_time( 'mysql' );
		$meta  = array();
		foreach ( self::canonical_fields() as $field ) {
			if ( in_array( $field, array( 'business_name', 'contact_name', 'email', 'phone', 'vendor_category', 'city', 'state' ), true ) ) {
				continue;
			}
			if ( ! empty( $row[ $field ] ) ) {
				$meta[ $field ] = $row[ $field ];
			}
		}

		$data = array(
			'organizer_user_id' => absint( $organizer_id ),
			'normalized_email'  => self::normalize_email( $row['email'] ?? '' ),
			'business_name'     => sanitize_text_field( $row['business_name'] ?? '' ),
			'contact_name'      => sanitize_text_field( $row['contact_name'] ?? '' ),
			'phone'             => self::normalize_phone( $row['phone'] ?? '' ),
			'city'              => sanitize_text_field( $row['city'] ?? '' ),
			'state'             => sanitize_text_field( $row['state'] ?? '' ),
			'vendor_category'   => sanitize_text_field( $row['vendor_category'] ?? '' ),
			'meta_json'         => wp_json_encode( $meta ),
			'import_job_id'     => absint( $job_id ),
			'updated_at'        => $now,
		);

		if ( $existing && $update ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ), null, array( '%d' ) );
			return (int) $existing['id'];
		}

		if ( $existing ) {
			return (int) $existing['id'];
		}

		$data['created_at'] = $now;
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Add prospect to list junction.
	 */
	public static function add_prospect_to_list( $list_id, $prospect_id, $organizer_id ) {
		if ( ! LNKup_Capabilities::organizer_owns_crm_object( $list_id, $organizer_id ) ) {
			return false;
		}
		global $wpdb;
		$wpdb->replace(
			LNKup_Vendor_Import_Tables::list_prospect_table(),
			array(
				'list_id'     => absint( $list_id ),
				'prospect_id' => absint( $prospect_id ),
				'added_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s' )
		);
		return true;
	}

	/**
	 * Queue outreach campaign for prospects.
	 */
	private static function queue_outreach_invites( $organizer_id, $lines, $options, $job_id ) {
		if ( ! class_exists( 'LNKup_Outreach' ) ) {
			return new WP_Error( 'unavailable', __( 'Outreach unavailable.', 'lnkup-marketplace' ) );
		}

		$name = sprintf(
			/* translators: %d: import job id */
			__( 'Import invite #%d', 'lnkup-marketplace' ),
			$job_id
		);

		$campaign_id = LNKup_Outreach::create_campaign(
			array(
				'name'           => $name,
				'target_role'    => 'lnkup_vendor',
				'message'        => sanitize_textarea_field( $options['invite_message'] ?? '' ),
				'source'         => 'organizer',
				'created_by'     => $organizer_id,
				'event_id'       => absint( $options['event_id'] ?? 0 ),
				'opportunity_id' => absint( $options['opportunity_id'] ?? 0 ),
			)
		);
		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}

		$imported = LNKup_Outreach::import_recipients( $campaign_id, $lines );
		if ( is_wp_error( $imported ) ) {
			return $imported;
		}

		$queued = LNKup_Outreach::queue_campaign( $campaign_id );
		if ( is_wp_error( $queued ) ) {
			return $queued;
		}

		self::link_outreach_recipients_to_prospects( $campaign_id, $organizer_id );

		return $campaign_id;
	}

	/**
	 * Store outreach recipient IDs on matching prospects.
	 *
	 * @param int $campaign_id  Campaign ID.
	 * @param int $organizer_id Organizer user ID.
	 */
	private static function link_outreach_recipients_to_prospects( $campaign_id, $organizer_id ) {
		global $wpdb;
		$rec_table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		$pro_table = LNKup_Vendor_Import_Tables::prospect_table();
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, email FROM {$rec_table} WHERE campaign_id = %d",
				absint( $campaign_id )
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$email = self::normalize_email( $row['email'] ?? '' );
			if ( ! $email ) {
				continue;
			}
			$wpdb->update(
				$pro_table,
				array(
					'outreach_recipient_id' => absint( $row['id'] ),
					'updated_at'            => current_time( 'mysql' ),
				),
				array(
					'organizer_user_id' => absint( $organizer_id ),
					'normalized_email'  => $email,
					'linked_user_id'    => 0,
				),
				array( '%d', '%s' ),
				array( '%d', '%s', '%d' )
			);
		}
	}

	/**
	 * List prospects for organizer.
	 *
	 * @param int   $organizer_id Organizer ID.
	 * @param array $args         Query args.
	 */
	public static function list_prospects( $organizer_id, $args = array() ) {
		global $wpdb;
		$table = LNKup_Vendor_Import_Tables::prospect_table();
		$page  = max( 1, absint( $args['page'] ?? 1 ) );
		$per   = min( 48, max( 12, absint( $args['per_page'] ?? 24 ) ) );
		$offset = ( $page - 1 ) * $per;

		$where = $wpdb->prepare( 'organizer_user_id = %d AND linked_user_id = 0', absint( $organizer_id ) );
		if ( ! empty( $args['q'] ) ) {
			$like = '%' . $wpdb->esc_like( sanitize_text_field( $args['q'] ) ) . '%';
			$where .= $wpdb->prepare( ' AND (business_name LIKE %s OR contact_name LIKE %s OR normalized_email LIKE %s)', $like, $like, $like );
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
				$per,
				$offset
			),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::format_prospect_card( $row );
		}

		return array(
			'items' => $items,
			'total' => $total,
			'page'  => $page,
			'pages' => (int) ceil( max( 1, $total ) / $per ),
		);
	}

	/**
	 * Format prospect for API/card.
	 *
	 * @param array $row DB row.
	 */
	public static function format_prospect_card( $row ) {
		$meta = json_decode( $row['meta_json'] ?? '{}', true );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		$name = $row['business_name'] ?: $row['contact_name'];
		return array(
			'id'              => (int) $row['id'],
			'record_type'     => 'prospect',
			'name'            => $name,
			'business_name'   => $row['business_name'],
			'contact_name'    => $row['contact_name'],
			'email'           => $row['normalized_email'],
			'phone'           => $row['phone'],
			'city'            => $row['city'],
			'state'           => $row['state'],
			'category'        => $row['vendor_category'],
			'badge'           => __( 'Imported prospect', 'lnkup-marketplace' ),
			'meta'            => $meta,
			'import_job_id'   => (int) $row['import_job_id'],
			'updated_at'      => $row['updated_at'],
		);
	}

	/**
	 * Link prospect on user registration.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $email        Email.
	 * @param int    $organizer_id Organizer from campaign.
	 */
	public static function link_prospect_on_registration( $user_id, $email, $organizer_id = 0 ) {
		global $wpdb;
		$table = LNKup_Vendor_Import_Tables::prospect_table();
		$email = self::normalize_email( $email );
		if ( ! $email ) {
			return;
		}

		$where = 'normalized_email = %s AND linked_user_id = 0';
		$params = array( $email );
		if ( $organizer_id ) {
			$where .= ' AND organizer_user_id = %d';
			$params[] = absint( $organizer_id );
		}

		$prospects = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where}", $params ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		foreach ( (array) $prospects as $prospect ) {
			$org_id = (int) $prospect['organizer_user_id'];
			LNKup_Organizer_CRM::ensure_relationship( $org_id, $user_id );
			$update = array(
				'linked_user_id' => absint( $user_id ),
				'updated_at'     => current_time( 'mysql' ),
			);
			if ( func_num_args() >= 4 && func_get_arg( 3 ) ) {
				$update['outreach_recipient_id'] = absint( func_get_arg( 3 ) );
			}
			$wpdb->update(
				$table,
				$update,
				array( 'id' => (int) $prospect['id'] ),
				null,
				array( '%d' )
			);

			$profile_id = lnkup_get_vendor_profile_id( $user_id );
			if ( $profile_id ) {
				self::migrate_prospect_lists( (int) $prospect['id'], $profile_id, $org_id );
			}

			LNKup_Organizer_CRM::log_activity(
				'vendor_import_linked',
				$org_id,
				$user_id,
				array( 'payload' => array( 'prospect_id' => (int) $prospect['id'] ) )
			);
		}
	}

	/**
	 * Migrate prospect list memberships to vendor profile lists.
	 */
	public static function migrate_prospect_lists( $prospect_id, $profile_id, $organizer_id ) {
		global $wpdb;
		$junction = LNKup_Vendor_Import_Tables::list_prospect_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT list_id FROM {$junction} WHERE prospect_id = %d", absint( $prospect_id ) ),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			LNKup_Vendor_Lists::add_vendor( (int) $row['list_id'], $organizer_id, $profile_id );
		}
	}

	/**
	 * Delete temp file for completed job.
	 *
	 * @param array $job Job row.
	 */
	public static function cleanup_job_file( $job ) {
		if ( ! empty( $job['file_path'] ) && file_exists( $job['file_path'] ) ) {
			wp_delete_file( $job['file_path'] );
		}
	}

	/**
	 * Log organizer-level import activity (no vendor user).
	 *
	 * @param string $type         Activity type.
	 * @param int    $organizer_id Organizer ID.
	 * @param array  $payload      Payload.
	 */
	public static function log_import_activity( $type, $organizer_id, $payload = array() ) {
		global $wpdb;
		$wpdb->insert(
			LNKup_Organizer_CRM_Tables::activity_table(),
			array(
				'organizer_user_id' => absint( $organizer_id ),
				'vendor_user_id'    => 0,
				'event_id'          => 0,
				'application_id'    => 0,
				'activity_type'     => sanitize_key( $type ),
				'payload'           => wp_json_encode( $payload ),
				'actor_user_id'     => get_current_user_id(),
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Human-readable field labels for mapping UI.
	 *
	 * @return array<string, string>
	 */
	public static function field_labels() {
		return array(
			'business_name'     => __( 'Business name', 'lnkup-marketplace' ),
			'contact_name'      => __( 'Contact name', 'lnkup-marketplace' ),
			'email'             => __( 'Email', 'lnkup-marketplace' ),
			'phone'             => __( 'Phone', 'lnkup-marketplace' ),
			'vendor_category'   => __( 'Category', 'lnkup-marketplace' ),
			'instagram'         => __( 'Instagram', 'lnkup-marketplace' ),
			'tiktok'            => __( 'TikTok', 'lnkup-marketplace' ),
			'website'           => __( 'Website', 'lnkup-marketplace' ),
			'city'              => __( 'City', 'lnkup-marketplace' ),
			'state'             => __( 'State', 'lnkup-marketplace' ),
			'products_services' => __( 'Products / services', 'lnkup-marketplace' ),
			'notes'             => __( 'Notes', 'lnkup-marketplace' ),
			'tags'              => __( 'Tags', 'lnkup-marketplace' ),
			'booth_fee'         => __( 'Booth fee', 'lnkup-marketplace' ),
			'booth_number'      => __( 'Booth number', 'lnkup-marketplace' ),
			'setup_type'        => __( 'Setup type', 'lnkup-marketplace' ),
			'tent_size'         => __( 'Tent size', 'lnkup-marketplace' ),
			'food_truck_size'   => __( 'Food truck size', 'lnkup-marketplace' ),
			'power_needs'       => __( 'Power needs', 'lnkup-marketplace' ),
			'water_needs'       => __( 'Water needs', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Render prospect card HTML for CRM directory.
	 *
	 * @param array $card Prospect card data.
	 */
	public static function render_prospect_card_html( $card ) {
		$name = $card['business_name'] ?: $card['contact_name'];
		ob_start();
		?>
		<article class="lnkup-crm-card lnkup-crm-card--prospect" data-prospect-id="<?php echo esc_attr( (string) ( $card['id'] ?? 0 ) ); ?>" data-testid="lnkup-prospect-card">
			<span class="lnkup-crm-card__badge lnkup-crm-card__badge--prospect"><?php echo esc_html( $card['badge'] ?? __( 'Imported prospect', 'lnkup-marketplace' ) ); ?></span>
			<h3 class="lnkup-crm-card__title"><?php echo esc_html( $name ); ?></h3>
			<?php if ( ! empty( $card['email'] ) ) : ?>
				<p class="lnkup-muted lnkup-crm-card__email"><?php echo esc_html( $card['email'] ); ?></p>
			<?php endif; ?>
			<ul class="lnkup-crm-card__meta">
				<?php if ( ! empty( $card['category'] ) ) : ?>
					<li><?php echo esc_html( $card['category'] ); ?></li>
				<?php endif; ?>
				<?php if ( ! empty( $card['city'] ) ) : ?>
					<li><?php echo esc_html( trim( $card['city'] . ( ! empty( $card['state'] ) ? ', ' . $card['state'] : '' ) ) ); ?></li>
				<?php endif; ?>
			</ul>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Cleanup expired import jobs (cron).
	 */
	public static function cleanup_expired_jobs() {
		global $wpdb;
		$table = LNKup_Vendor_Import_Tables::job_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE expires_at < %s AND status != %s",
				current_time( 'mysql' ),
				self::STATUS_COMPLETED
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $job ) {
			self::cleanup_job_file( $job );
			$wpdb->delete( $table, array( 'id' => (int) $job['id'] ), array( '%d' ) );
		}
	}
}
