<?php
/**
 * External event discovery domain service.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Events {

	const CLAIM_UNCLAIMED      = 'unclaimed';
	const CLAIM_PENDING        = 'claim_pending';
	const CLAIM_CLAIMED        = 'claimed';
	const CLAIM_HIDDEN         = 'hidden';

	const WORKFLOW_IMPORTED        = 'imported';
	const WORKFLOW_PENDING_REVIEW  = 'pending_review';
	const WORKFLOW_PUBLISHED       = 'published';
	const WORKFLOW_HIDDEN          = 'hidden';
	const WORKFLOW_REJECTED        = 'rejected';
	const WORKFLOW_NEEDS_UPDATE    = 'needs_update';

	const INTEREST_SUBMITTED   = 'submitted';
	const INTEREST_NOTIFIED    = 'organizer_notified';
	const INTEREST_CONVERTED   = 'claimed_converted';
	const INTEREST_CLOSED      = 'closed';

	const EXCERPT_MAX          = 280;
	const TOKEN_TTL_DAYS       = 30;

	/**
	 * Init hooks.
	 */
	public static function init(): void {
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 16, 1 );
		add_action( 'wp_login', array( __CLASS__, 'on_user_login' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_email_tracking' ), 6 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_track_claim_visit' ), 7 );
	}

	/**
	 * Truncate excerpt for storage.
	 */
	public static function sanitize_excerpt( string $text ): string {
		$text = wp_strip_all_tags( $text );
		if ( strlen( $text ) > self::EXCERPT_MAX ) {
			$text = substr( $text, 0, self::EXCERPT_MAX - 1 ) . '…';
		}
		return $text;
	}

	/**
	 * Extract the first valid organizer email from free text (description, mailto links, etc.).
	 */
	public static function extract_email_from_text( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}

		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5 );
		if ( ! preg_match_all(
			'/(?:mailto:)?([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/i',
			$text,
			$matches
		) ) {
			return '';
		}

		foreach ( (array) ( $matches[1] ?? array() ) as $candidate ) {
			$email = self::sanitize_extracted_email( (string) $candidate );
			if ( $email ) {
				return $email;
			}
		}

		return '';
	}

	/**
	 * @param string $candidate Raw email match.
	 */
	protected static function sanitize_extracted_email( string $candidate ): string {
		$email = sanitize_email( trim( $candidate, " \t\n\r\0\x0B<>\"'.,;" ) );
		if ( ! is_email( $email ) ) {
			return '';
		}
		if ( self::is_blocklisted_extracted_email( $email ) ) {
			return '';
		}
		return $email;
	}

	/**
	 * @param string $email Sanitized email.
	 */
	protected static function is_blocklisted_extracted_email( string $email ): bool {
		$lower = strtolower( $email );
		$blocked_domains = array( 'eventbrite.com', 'example.com', 'evbuc.com', 'eventbrite.co.uk' );
		foreach ( $blocked_domains as $domain ) {
			if ( str_ends_with( $lower, '@' . $domain ) || str_contains( $lower, '@' . $domain ) ) {
				return true;
			}
		}

		$local = strtolower( (string) strtok( $lower, '@' ) );
		$blocked_local = array( 'noreply', 'no-reply', 'donotreply', 'do-not-reply' );
		foreach ( $blocked_local as $part ) {
			if ( str_contains( $local, $part ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Try to populate organizer_email from description fields when missing.
	 *
	 * @param array<string, mixed> $data Input or merged row data.
	 * @return array{0: array<string, mixed>, 1: bool} Updated data and whether email was extracted.
	 */
	public static function maybe_extract_organizer_email( array $data ): array {
		if ( ! empty( $data['organizer_email'] ) && is_email( (string) $data['organizer_email'] ) ) {
			return array( $data, false );
		}

		$text = (string) ( $data['description'] ?? $data['description_excerpt'] ?? '' );
		$email = self::extract_email_from_text( $text );
		if ( ! $email ) {
			return array( $data, false );
		}

		$data['organizer_email'] = $email;
		return array( $data, true );
	}

	/**
	 * Default vendor-relevance terms for external event auto sync.
	 *
	 * @return array<int, string>
	 */
	public static function default_vendor_sync_terms(): array {
		return array(
			'vendor',
			'vendors',
			'food truck',
			'food trucks',
			'food vendor',
			'booth',
			'booths',
			'pop-up',
			'popup',
			'street food',
			'artisan',
			'crafter',
			'concession',
			'market vendor',
			'mobile kitchen',
			'flea market',
		);
	}

	/**
	 * Strong vendor terms (weighted higher in title scoring).
	 *
	 * @return array<int, string>
	 */
	public static function strong_vendor_sync_terms(): array {
		return array(
			'food truck',
			'food trucks',
			'food vendor',
			'market vendor',
			'pop-up vendor',
			'popup vendor',
		);
	}

	/**
	 * Score vendor relevance from title, description, and category hints.
	 *
	 * @param string               $title         Event title.
	 * @param string               $description   Full description text.
	 * @param string               $category_hint Category or event type hint.
	 * @param array<int, string>   $terms         Vendor terms to match.
	 * @return array{score: int, matched: array<int, string>}
	 */
	public static function score_vendor_relevance( string $title, string $description, string $category_hint = '', array $terms = array() ): array {
		if ( empty( $terms ) ) {
			$terms = self::default_vendor_sync_terms();
		}

		$title_lower = strtolower( $title );
		$body_lower  = strtolower(
			trim( $title . ' ' . $description . ' ' . $category_hint )
		);
		$strong      = self::strong_vendor_sync_terms();
		$matched     = array();
		$score       = 0;
		$title_bonus = false;

		usort(
			$terms,
			static function ( $a, $b ) {
				return strlen( (string) $b ) <=> strlen( (string) $a );
			}
		);

		foreach ( $terms as $term ) {
			$needle = strtolower( trim( (string) $term ) );
			if ( '' === $needle || ! str_contains( $body_lower, $needle ) ) {
				continue;
			}
			$matched[] = (string) $term;
			$is_strong = in_array( $needle, array_map( 'strtolower', $strong ), true )
				|| in_array( (string) $term, $strong, true );

			if ( ! $title_bonus && str_contains( $title_lower, $needle ) ) {
				$score      += $is_strong ? 40 : 30;
				$title_bonus = true;
			} else {
				$score += $is_strong ? 20 : 15;
			}

			if ( $score >= 100 ) {
				$score = 100;
				break;
			}
		}

		return array(
			'score'   => min( 100, $score ),
			'matched' => array_values( array_unique( $matched ) ),
		);
	}

	/**
	 * Build searchable haystack for vendor/keyword filters from parsed event data.
	 *
	 * @param array<string, mixed> $parsed Parsed event fields.
	 */
	public static function vendor_filter_haystack( array $parsed ): string {
		return strtolower(
			trim(
				(string) ( $parsed['title'] ?? '' ) . ' ' .
				(string) ( $parsed['description'] ?? $parsed['description_excerpt'] ?? '' ) . ' ' .
				(string) ( $parsed['event_type'] ?? '' ) . ' ' .
				(string) ( $parsed['_category_hint'] ?? '' )
			)
		);
	}

	/**
	 * Map external category/title hints to a LNKup parent event type label.
	 *
	 * @param string $hint  Category or format string from import source.
	 * @param string $title Event title for keyword fallback.
	 */
	public static function map_event_type( string $hint = '', string $title = '' ): string {
		$haystack = strtolower( trim( $hint . ' ' . $title ) );
		$parents  = function_exists( 'lnkup_get_event_type_parents' ) ? lnkup_get_event_type_parents() : array();
		$names    = $parents ? wp_list_pluck( $parents, 'name' ) : array();

		foreach ( $names as $name ) {
			$lower = strtolower( (string) $name );
			if ( $hint && false !== stripos( $hint, (string) $name ) ) {
				return (string) $name;
			}
			if ( $haystack && false !== strpos( $haystack, $lower ) ) {
				return (string) $name;
			}
		}

		$keyword_map = array(
			'festival'   => 'Festivals',
			'food fest'  => 'Festivals',
			'market'     => 'Markets & Pop-Ups',
			'pop-up'     => 'Markets & Pop-Ups',
			'popup'      => 'Markets & Pop-Ups',
			'conference' => 'Conferences',
			'expo'       => 'Conferences',
			'corporate'  => 'Corporate & Brand',
			'brand'      => 'Corporate & Brand',
			'campus'     => 'Campus Events',
			'college'    => 'Campus Events',
			'university' => 'Campus Events',
			'rodeo'      => 'Festivals',
		);

		foreach ( $keyword_map as $needle => $label ) {
			if ( str_contains( $haystack, $needle ) && in_array( $label, $names, true ) ) {
				return $label;
			}
		}

		return '';
	}

	/**
	 * Merge re-fetched parser fields into an existing row (preserves admin email when set).
	 *
	 * @param int                  $id            External event ID.
	 * @param array<string, mixed> $parsed        Parser output.
	 * @param bool                 $preserve_email Keep stored organizer_email when non-empty.
	 * @return true|WP_Error
	 */
	public static function merge_refetch_fields( int $id, array $parsed, bool $preserve_email = true ) {
		$existing = self::get_row( $id );
		if ( ! $existing ) {
			return new WP_Error( 'not_found', __( 'External event not found.', 'lnkup-marketplace' ) );
		}

		unset( $parsed['_preview_confidence'], $parsed['workflow_status'], $parsed['source_platform'] );

		$merge_keys = array(
			'title',
			'description_excerpt',
			'image_url',
			'start_datetime',
			'end_datetime',
			'city',
			'state',
			'venue_name',
			'organizer_name',
			'event_type',
			'source_event_id',
			'application_url',
			'organizer_instagram',
		);

		$data = array();
		foreach ( $merge_keys as $key ) {
			if ( ! empty( $parsed[ $key ] ) ) {
				$data[ $key ] = $parsed[ $key ];
			}
		}

		if ( $preserve_email && ! empty( $existing['organizer_email'] ) && is_email( (string) $existing['organizer_email'] ) ) {
			// Keep admin-entered email.
		} elseif ( ! empty( $parsed['organizer_email'] ) && is_email( (string) $parsed['organizer_email'] ) ) {
			$data['organizer_email'] = sanitize_email( (string) $parsed['organizer_email'] );
		}

		if ( empty( $data['organizer_email'] ) || ! is_email( (string) ( $data['organizer_email'] ?? '' ) ) ) {
			$desc_text = (string) ( $parsed['description'] ?? $parsed['description_excerpt'] ?? $data['description_excerpt'] ?? $existing['description_excerpt'] ?? '' );
			$extracted = self::extract_email_from_text( $desc_text );
			if ( $extracted ) {
				$data['organizer_email'] = $extracted;
			}
		} else {
			$extracted = '';
		}

		if ( isset( $parsed['vendor_fit_score'] ) ) {
			$data['vendor_fit_score'] = min( 100, max( 0, absint( $parsed['vendor_fit_score'] ) ) );
		}

		if ( empty( $data ) ) {
			return true;
		}

		$had_email = ! empty( $existing['organizer_email'] ) && is_email( (string) $existing['organizer_email'] );

		$result = self::update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( class_exists( 'LNKup_External_Events_Timeline' ) ) {
			LNKup_External_Events_Timeline::log( $id, 'refetched', get_current_user_id() );
			if ( ! $had_email && ! empty( $data['organizer_email'] ) && is_email( (string) $data['organizer_email'] )
				&& ! empty( $extracted ) ) {
				LNKup_External_Events_Timeline::log( $id, 'organizer_email_extracted', 0, array( 'source' => 'description' ) );
			}
		}

		if ( ! $had_email && ! empty( $data['organizer_email'] ) && is_email( (string) $data['organizer_email'] ) ) {
			self::flush_pending_organizer_notifications( $id );
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $data Event fields.
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$source_url = esc_url_raw( (string) ( $data['source_url'] ?? '' ) );
		if ( '' === $source_url ) {
			return new WP_Error( 'missing_url', __( 'Source URL is required.', 'lnkup-marketplace' ) );
		}

		$dup = self::find_duplicate( $source_url, (string) ( $data['source_event_id'] ?? '' ), (string) ( $data['source_platform'] ?? 'eventbrite' ) );
		if ( $dup ) {
			return new WP_Error( 'duplicate', __( 'An external event with this source URL or ID already exists.', 'lnkup-marketplace' ), array( 'existing_id' => (int) $dup['id'] ) );
		}

		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title ) {
			return new WP_Error( 'missing_title', __( 'Title is required.', 'lnkup-marketplace' ) );
		}

		list( $data, $extracted_email ) = self::maybe_extract_organizer_email( $data );

		$now = current_time( 'mysql' );
		$row = self::normalize_row( $data );
		$row['created_at']     = $now;
		$row['updated_at']     = $now;
		$row['last_synced_at'] = $now;
		$row['claim_status']     = self::CLAIM_UNCLAIMED;
		if ( empty( $row['workflow_status'] ) ) {
			$row['workflow_status'] = self::WORKFLOW_PUBLISHED;
		}

		$table = LNKup_External_Events_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $row );
		if ( ! $wpdb->insert_id ) {
			return new WP_Error( 'db_error', __( 'Could not save external event.', 'lnkup-marketplace' ) );
		}

		$new_id = (int) $wpdb->insert_id;
		if ( class_exists( 'LNKup_External_Events_Timeline' ) ) {
			LNKup_External_Events_Timeline::log( $new_id, 'imported', get_current_user_id() );
			if ( $extracted_email ) {
				LNKup_External_Events_Timeline::log( $new_id, 'organizer_email_extracted', 0, array( 'source' => 'description' ) );
			}
			if ( self::WORKFLOW_PUBLISHED === ( $row['workflow_status'] ?? '' ) ) {
				LNKup_External_Events_Timeline::log( $new_id, 'published', get_current_user_id() );
			}
		}
		if ( $extracted_email && self::WORKFLOW_PUBLISHED === ( $row['workflow_status'] ?? '' ) ) {
			self::flush_pending_organizer_notifications( $new_id );
		}
		if ( class_exists( 'LNKup_External_Events_Dedup' ) ) {
			LNKup_External_Events_Dedup::scan( $new_id );
		}

		return $new_id;
	}

	/**
	 * @param int                  $id   Event ID.
	 * @param array<string, mixed> $data Fields to update.
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;
		$existing = self::get_row( $id );
		if ( ! $existing ) {
			return new WP_Error( 'not_found', __( 'External event not found.', 'lnkup-marketplace' ) );
		}

		$row = self::normalize_row( $data, false );
		if ( isset( $data['source_url'] ) ) {
			$new_url = esc_url_raw( (string) $data['source_url'] );
			if ( $new_url !== $existing['source_url'] ) {
				$dup = self::find_duplicate( $new_url, (string) ( $data['source_event_id'] ?? $existing['source_event_id'] ), (string) ( $data['source_platform'] ?? $existing['source_platform'] ), $id );
				if ( $dup ) {
					return new WP_Error( 'duplicate', __( 'Duplicate source URL or event ID.', 'lnkup-marketplace' ) );
				}
			}
		}

		$row['updated_at']     = current_time( 'mysql' );
		$row['last_synced_at'] = current_time( 'mysql' );

		$table = LNKup_External_Events_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, $row, array( 'id' => $id ) );
		return true;
	}

	/**
	 * @param int $id Event ID.
	 */
	public static function hide( int $id ) {
		$result = self::set_claim_status( $id, self::CLAIM_HIDDEN );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::set_workflow_status( $id, self::WORKFLOW_HIDDEN );
		if ( class_exists( 'LNKup_External_Events_Timeline' ) ) {
			LNKup_External_Events_Timeline::log( $id, 'hidden', get_current_user_id() );
		}
		return true;
	}

	/**
	 * @param int $id Event ID.
	 */
	public static function unhide( int $id ) {
		$row = self::get_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'External event not found.', 'lnkup-marketplace' ) );
		}
		if ( self::CLAIM_CLAIMED === $row['claim_status'] ) {
			return new WP_Error( 'already_claimed', __( 'Claimed events cannot be unhidden as unclaimed.', 'lnkup-marketplace' ) );
		}
		$unhide = self::set_claim_status( $id, self::CLAIM_UNCLAIMED );
		if ( is_wp_error( $unhide ) ) {
			return $unhide;
		}
		self::set_workflow_status( $id, self::WORKFLOW_PUBLISHED );
		if ( class_exists( 'LNKup_External_Events_Timeline' ) ) {
			LNKup_External_Events_Timeline::log( $id, 'published', get_current_user_id() );
		}
		return true;
	}

	/**
	 * Set workflow status.
	 *
	 * @param int    $id     Event ID.
	 * @param string $status Workflow status slug.
	 * @return true|WP_Error
	 */
	public static function set_workflow_status( int $id, string $status ) {
		global $wpdb;
		if ( ! self::get_row( $id ) ) {
			return new WP_Error( 'not_found', __( 'External event not found.', 'lnkup-marketplace' ) );
		}
		$table = LNKup_External_Events_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'workflow_status' => sanitize_key( $status ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
		return true;
	}

	/**
	 * Approve pending review → published.
	 *
	 * @param int $id Event ID.
	 * @return true|WP_Error
	 */
	public static function publish( int $id ) {
		$result = self::set_workflow_status( $id, self::WORKFLOW_PUBLISHED );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( class_exists( 'LNKup_External_Events_Timeline' ) ) {
			LNKup_External_Events_Timeline::log( $id, 'published', get_current_user_id() );
		}
		return true;
	}

	/**
	 * Reject pending review event.
	 *
	 * @param int $id Event ID.
	 * @return true|WP_Error
	 */
	public static function reject( int $id ) {
		$result = self::set_workflow_status( $id, self::WORKFLOW_REJECTED );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( class_exists( 'LNKup_External_Events_Timeline' ) ) {
			LNKup_External_Events_Timeline::log( $id, 'rejected', get_current_user_id() );
		}
		return true;
	}

	/**
	 * Manually mark claimed with optional event ID.
	 *
	 * @param int $id       External event ID.
	 * @param int $event_id LNK event post ID.
	 */
	public static function mark_claimed_manual( int $id, int $event_id = 0 ) {
		global $wpdb;
		$row = self::get_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'External event not found.', 'lnkup-marketplace' ) );
		}
		$table = LNKup_External_Events_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'claim_status'     => self::CLAIM_CLAIMED,
				'claimed_event_id' => absint( $event_id ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
		return true;
	}

	/**
	 * @param string $source_url      Source URL.
	 * @param string $source_event_id Source platform event ID.
	 * @param string $platform        Platform slug.
	 * @param int    $exclude_id      Exclude this row ID.
	 * @return array<string, mixed>|null
	 */
	public static function find_duplicate( string $source_url, string $source_event_id = '', string $platform = 'eventbrite', int $exclude_id = 0 ) {
		global $wpdb;
		$table = LNKup_External_Events_Tables::events_table();
		if ( ! self::table_exists( $table ) ) {
			return null;
		}

		if ( '' !== $source_url ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE source_url = %s AND id != %d LIMIT 1",
					esc_url_raw( $source_url ),
					$exclude_id
				),
				ARRAY_A
			);
			if ( $row ) {
				return $row;
			}
		}

		if ( '' !== $source_event_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE source_platform = %s AND source_event_id = %s AND id != %d LIMIT 1",
					sanitize_key( $platform ),
					sanitize_text_field( $source_event_id ),
					$exclude_id
				),
				ARRAY_A
			);
			if ( $row ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Public feed search.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public static function search( array $args = array() ): array {
		global $wpdb;

		$table = LNKup_External_Events_Tables::events_table();
		if ( ! self::table_exists( $table ) ) {
			return array(
				'items'   => array(),
				'total'   => 0,
				'page'    => 1,
				'per_page'=> 24,
				'pages'   => 0,
			);
		}

		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = min( 50, max( 1, absint( $args['per_page'] ?? 24 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array(
			"claim_status = 'unclaimed'",
			"workflow_status = 'published'",
			'duplicate_of_id = 0',
		);
		$values = array();

		if ( ! empty( $args['high_demand'] ) ) {
			$where[] = 'demand_score >= 50';
		}

		if ( ! empty( $args['city'] ) ) {
			$where[]  = 'city LIKE %s';
			$values[] = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['city'] ) ) . '%';
		}
		if ( ! empty( $args['state'] ) ) {
			$where[]  = 'state = %s';
			$values[] = sanitize_text_field( (string) $args['state'] );
		}
		if ( ! empty( $args['event_type'] ) ) {
			$where[]  = 'event_type LIKE %s';
			$values[] = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['event_type'] ) ) . '%';
		}
		if ( ! empty( $args['platform'] ) ) {
			$where[]  = 'source_platform = %s';
			$values[] = sanitize_key( (string) $args['platform'] );
		}
		if ( ! empty( $args['q'] ) ) {
			$like     = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['q'] ) ) . '%';
			$where[]  = '(title LIKE %s OR city LIKE %s OR venue_name LIKE %s OR event_type LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}
		self::apply_date_filters( $where, $values, $args );

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) ( $values ? $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) ) : $wpdb->get_var( $count_sql ) );

		$order = 'start_datetime ASC, id DESC';
		$sort  = sanitize_key( (string) ( $args['external_sort'] ?? '' ) );
		if ( 'high_demand' === $sort ) {
			$order = 'demand_score DESC, vendor_interest_count DESC, id DESC';
		} elseif ( 'recently_requested' === $sort ) {
			$order = 'last_interest_at DESC, id DESC';
		}

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d";
		$list_args = array_merge( $values, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_args ), ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::format_public_row( $row );
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * SQL fragment for rows ready to publish (approximate; PHP review_readiness is canonical).
	 *
	 * @return string
	 */
	public static function ready_to_publish_sql_clause(): string {
		return "(image_url != '' AND image_url IS NOT NULL
			AND LOCATE('eventbrite.com/e/', image_url) = 0
			AND LOCATE('eventbrite.com/events/', image_url) = 0
			AND LOCATE('_next/image', image_url) = 0
			AND image_url LIKE 'https://%'
			AND organizer_email != '' AND organizer_email IS NOT NULL AND organizer_email LIKE '%@%'
			AND event_type != '' AND event_type IS NOT NULL)";
	}

	/**
	 * Review readiness for admin queue (image, email, event type).
	 *
	 * @param array<string, mixed> $row Event row.
	 * @return array{ready: bool, warnings: array<int, string>}
	 */
	public static function review_readiness( array $row ): array {
		$warnings = array();
		$image    = (string) ( $row['image_url'] ?? '' );
		$email    = (string) ( $row['organizer_email'] ?? '' );
		$type     = (string) ( $row['event_type'] ?? '' );

		if ( '' === $image ) {
			$warnings[] = __( 'No image', 'lnkup-marketplace' );
		} elseif ( class_exists( 'LNKup_External_Source_Eventbrite' ) ) {
			$valid_image = LNKup_External_Source_Eventbrite::sanitize_image_url( $image );
			if ( '' === $valid_image ) {
				if ( LNKup_External_Source_Eventbrite::is_event_page_url( $image ) ) {
					$warnings[] = __( 'Image URL is an event link — use a CDN image URL', 'lnkup-marketplace' );
				} else {
					$warnings[] = __( 'Image URL must start with https:// — use Re-fetch or paste a CDN image URL', 'lnkup-marketplace' );
				}
			}
		} elseif ( ! str_starts_with( strtolower( $image ), 'https://' ) ) {
			$warnings[] = __( 'Image URL must start with https:// — use Re-fetch or paste a CDN image URL', 'lnkup-marketplace' );
		}

		if ( '' === $email || ! is_email( $email ) ) {
			$warnings[] = __( 'No organizer email — outreach disabled', 'lnkup-marketplace' );
		}

		if ( '' === $type ) {
			$warnings[] = __( 'No event type', 'lnkup-marketplace' );
		}

		return array(
			'ready'    => empty( $warnings ),
			'warnings' => $warnings,
		);
	}

	/**
	 * KPI stats for the pending review queue.
	 *
	 * @return array{pending_total: int, ready_count: int, missing_email_count: int, avg_vendor_fit: float}
	 */
	public static function review_queue_stats(): array {
		global $wpdb;
		$table = LNKup_External_Events_Tables::events_table();
		if ( ! self::table_exists( $table ) ) {
			return array(
				'pending_total'         => 0,
				'ready_count'           => 0,
				'missing_email_count'   => 0,
				'avg_vendor_fit'        => 0.0,
			);
		}

		$ready_sql = self::ready_to_publish_sql_clause();
		$pending   = self::WORKFLOW_PENDING_REVIEW;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			"SELECT
				COUNT(*) AS pending_total,
				SUM(CASE WHEN {$ready_sql} THEN 1 ELSE 0 END) AS ready_count,
				SUM(CASE WHEN (organizer_email = '' OR organizer_email IS NULL OR organizer_email NOT LIKE '%@%') THEN 1 ELSE 0 END) AS missing_email_count,
				AVG(vendor_fit_score) AS avg_vendor_fit
			FROM {$table}
			WHERE workflow_status = '" . esc_sql( $pending ) . "'",
			ARRAY_A
		);

		return array(
			'pending_total'       => (int) ( $row['pending_total'] ?? 0 ),
			'ready_count'         => (int) ( $row['ready_count'] ?? 0 ),
			'missing_email_count' => (int) ( $row['missing_email_count'] ?? 0 ),
			'avg_vendor_fit'      => round( (float) ( $row['avg_vendor_fit'] ?? 0 ), 1 ),
		);
	}

	/**
	 * Admin list with filters.
	 *
	 * @param array<string, mixed> $args Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_admin( array $args = array() ): array {
		global $wpdb;
		$table = LNKup_External_Events_Tables::events_table();
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['claim_status'] ) ) {
			$where[]  = 'claim_status = %s';
			$values[] = sanitize_key( (string) $args['claim_status'] );
		}
		if ( ! empty( $args['workflow_status'] ) ) {
			$where[]  = 'workflow_status = %s';
			$values[] = sanitize_key( (string) $args['workflow_status'] );
		}
		if ( ! empty( $args['city'] ) ) {
			$where[]  = 'city LIKE %s';
			$values[] = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['city'] ) ) . '%';
		}
		if ( ! empty( $args['platform'] ) ) {
			$where[]  = 'source_platform = %s';
			$values[] = sanitize_key( (string) $args['platform'] );
		}
		if ( ! empty( $args['event_type'] ) ) {
			$where[]  = 'event_type LIKE %s';
			$values[] = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['event_type'] ) ) . '%';
		}
		if ( ! empty( $args['needs_email'] ) ) {
			$where[]  = 'workflow_status = %s';
			$values[] = self::WORKFLOW_PUBLISHED;
			$where[]  = 'vendor_interest_count > 0';
			$where[]  = "(organizer_email = '' OR organizer_email IS NULL)";
		}
		if ( ! empty( $args['min_vendor_fit'] ) ) {
			$where[]  = 'vendor_fit_score >= %d';
			$values[] = min( 100, max( 0, absint( $args['min_vendor_fit'] ) ) );
		}
		if ( ! empty( $args['ready_to_publish'] ) ) {
			$where[] = self::ready_to_publish_sql_clause();
		}

		$order_columns = array(
			'vendor_fit_score' => 'vendor_fit_score',
			'created_at'       => 'created_at',
			'start_datetime'   => 'start_datetime',
		);
		$order_by_key    = sanitize_key( (string) ( $args['order_by'] ?? 'created_at' ) );
		$order_by        = $order_columns[ $order_by_key ] ?? 'created_at';
		$order           = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order_by} {$order} LIMIT 200";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $values ? $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * @param int $id External event ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_public( int $id ): ?array {
		$row = self::get_row( $id );
		if ( ! $row || self::CLAIM_UNCLAIMED !== $row['claim_status'] ) {
			return null;
		}
		if ( self::WORKFLOW_PUBLISHED !== ( $row['workflow_status'] ?? '' ) ) {
			return null;
		}
		if ( (int) ( $row['duplicate_of_id'] ?? 0 ) > 0 ) {
			return null;
		}
		return self::format_public_row( $row );
	}

	/**
	 * @param int $id Row ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_row( int $id ): ?array {
		global $wpdb;
		$table = LNKup_External_Events_Tables::events_table();
		if ( ! self::table_exists( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * @param int $external_id External event ID.
	 * @param int $vendor_id   Vendor user ID.
	 * @param array<string, mixed> $payload Form data.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function submit_interest( int $external_id, int $vendor_id, array $payload ) {
		global $wpdb;

		if ( ! LNKup_Roles::is_vendor( $vendor_id ) && ! LNKup_Capabilities::user_has_manage_all( $vendor_id ) ) {
			return new WP_Error( 'forbidden', __( 'Only vendors can request vendor opportunities.', 'lnkup-marketplace' ) );
		}

		$event = self::get_row( $external_id );
		if ( ! $event || self::CLAIM_UNCLAIMED !== $event['claim_status'] ) {
			return new WP_Error( 'not_available', __( 'This event is no longer available for requests.', 'lnkup-marketplace' ) );
		}
		if ( self::WORKFLOW_PUBLISHED !== ( $event['workflow_status'] ?? '' ) ) {
			return new WP_Error( 'not_available', __( 'This event is no longer available for requests.', 'lnkup-marketplace' ) );
		}

		$existing = self::get_vendor_interest( $external_id, $vendor_id );
		if ( $existing ) {
			return new WP_Error(
				'duplicate',
				lnkup_copy( 'request_interest_existing' ),
				array(
					'interest' => $existing,
					'status'   => $existing['status'],
				)
			);
		}

		$profile_id = self::vendor_profile_id( $vendor_id );
		if ( ! $profile_id ) {
			return new WP_Error( 'no_profile', __( 'Complete your vendor profile before requesting opportunities.', 'lnkup-marketplace' ) );
		}

		if ( empty( $payload['consent'] ) ) {
			return new WP_Error( 'consent_required', __( 'Please confirm you understand LNKup will notify the organizer.', 'lnkup-marketplace' ) );
		}

		$now   = current_time( 'mysql' );
		$table = LNKup_External_Events_Tables::interest_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'external_event_id' => $external_id,
				'vendor_user_id'    => $vendor_id,
				'vendor_profile_id' => $profile_id,
				'vendor_category'   => sanitize_text_field( (string) ( $payload['vendor_category'] ?? '' ) ),
				'booth_type'        => sanitize_text_field( (string) ( $payload['booth_type'] ?? '' ) ),
				'power_needs'       => sanitize_text_field( (string) ( $payload['power_needs'] ?? '' ) ),
				'message'           => sanitize_textarea_field( (string) ( $payload['message'] ?? '' ) ),
				'status'            => self::INTEREST_SUBMITTED,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);

		$interest_id = (int) $wpdb->insert_id;
		self::increment_interest_count( $external_id );

		if ( class_exists( 'LNKup_External_Events_Demand' ) ) {
			LNKup_External_Events_Demand::recalculate( $external_id );
		}
		if ( class_exists( 'LNKup_External_Events_Timeline' ) ) {
			LNKup_External_Events_Timeline::log( $external_id, 'vendor_interest', $vendor_id, array( 'interest_id' => $interest_id ) );
		}

		self::track( 'external_event_interest_submitted', $vendor_id, $external_id, array( 'interest_id' => $interest_id ) );

		$event = self::get_row( $external_id );
		$email_sent = false;
		$is_first   = 1 === (int) ( $event['vendor_interest_count'] ?? 0 );
		if ( $is_first && ! empty( $event['organizer_email'] ) && is_email( (string) $event['organizer_email'] ) ) {
			$flush = self::flush_pending_organizer_notifications( $external_id );
			$email_sent = ! empty( $flush['sent'] );
		} elseif ( $is_first && ( empty( $event['organizer_email'] ) || ! is_email( (string) $event['organizer_email'] ) ) ) {
			if ( class_exists( 'LNKup_External_Events_Timeline' ) ) {
				LNKup_External_Events_Timeline::log(
					$external_id,
					'interest_no_organizer_email',
					$vendor_id,
					array( 'interest_id' => $interest_id )
				);
			}
			if ( ! self::has_timeline_event( $external_id, 'admin_missing_email_notified' ) ) {
				$admin_sent = LNKup_Notifications::send_external_event_missing_email_admin( $event, $interest_id, $vendor_id );
				if ( $admin_sent && class_exists( 'LNKup_External_Events_Timeline' ) ) {
					LNKup_External_Events_Timeline::log(
						$external_id,
						'admin_missing_email_notified',
						$vendor_id,
						array( 'interest_id' => $interest_id )
					);
				}
			}
		}

		if ( class_exists( 'LNKup_External_Events_Followup' ) ) {
			LNKup_External_Events_Followup::maybe_queue( $external_id );
		}

		return array(
			'id'          => $interest_id,
			'email_sent'  => $email_sent,
			'message_key' => $email_sent ? 'request_interest_success' : 'request_interest_no_email',
		);
	}

	/**
	 * Send organizer outreach for interests that were never notified (missing email deferral).
	 *
	 * @param int $external_id External event ID.
	 * @return array{sent: bool, interest_count: int}
	 */
	public static function flush_pending_organizer_notifications( int $external_id ): array {
		global $wpdb;

		$result = array(
			'sent'           => false,
			'interest_count' => 0,
		);

		$event = self::get_row( $external_id );
		if ( ! $event ) {
			return $result;
		}
		if ( self::WORKFLOW_PUBLISHED !== ( $event['workflow_status'] ?? '' ) ) {
			return $result;
		}
		if ( self::CLAIM_UNCLAIMED !== ( $event['claim_status'] ?? '' ) ) {
			return $result;
		}

		$email = sanitize_email( (string) ( $event['organizer_email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return $result;
		}

		if ( (int) ( $event['followup_stage'] ?? 0 ) > 0 ) {
			return $result;
		}

		$interest_table = LNKup_External_Events_Tables::interest_table();
		if ( ! self::table_exists( $interest_table ) ) {
			return $result;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$interest_table}
				WHERE external_event_id = %d AND status = %s
				AND (organizer_email_sent_at IS NULL OR organizer_email_sent_at = '0000-00-00 00:00:00')
				ORDER BY created_at ASC",
				$external_id,
				self::INTEREST_SUBMITTED
			),
			ARRAY_A
		);

		if ( empty( $pending ) ) {
			return $result;
		}

		$count  = count( $pending );
		$oldest = $pending[0];
		$payload = array(
			'vendor_category' => (string) ( $oldest['vendor_category'] ?? '' ),
			'booth_type'      => (string) ( $oldest['booth_type'] ?? '' ),
			'power_needs'     => (string) ( $oldest['power_needs'] ?? '' ),
			'message'         => (string) ( $oldest['message'] ?? '' ),
		);
		$vendor_id = (int) ( $oldest['vendor_user_id'] ?? 0 );

		$token = self::generate_claim_token( $external_id, $email );
		if ( is_wp_error( $token ) ) {
			return $result;
		}

		$sent = LNKup_Notifications::send_external_event_vendor_interest(
			$event,
			$vendor_id,
			$payload,
			self::build_tracked_claim_url( (string) $token, 'click' ),
			$count
		);
		if ( ! $sent ) {
			return $result;
		}

		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$interest_table}
				SET status = %s, organizer_email_sent_at = %s, updated_at = %s
				WHERE external_event_id = %d AND status = %s",
				self::INTEREST_NOTIFIED,
				$now,
				$now,
				$external_id,
				self::INTEREST_SUBMITTED
			)
		);

		$events_table = LNKup_External_Events_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$events_table,
			array(
				'followup_stage'     => 1,
				'emails_sent'        => (int) ( $event['emails_sent'] ?? 0 ) + 1,
				'last_email_sent_at' => $now,
				'updated_at'         => $now,
			),
			array( 'id' => $external_id )
		);

		if ( class_exists( 'LNKup_External_Events_Timeline' ) ) {
			$timeline_type = self::has_timeline_event( $external_id, 'interest_no_organizer_email' )
				? 'organizer_notified_deferred'
				: 'organizer_notified';
			LNKup_External_Events_Timeline::log(
				$external_id,
				$timeline_type,
				$vendor_id,
				array(
					'stage'          => 1,
					'interest_count' => $count,
				)
			);
		}
		self::track( 'external_event_organizer_notified', $vendor_id, $external_id, array( 'interest_count' => $count ) );

		if ( class_exists( 'LNKup_External_Events_Followup' ) ) {
			LNKup_External_Events_Followup::maybe_queue( $external_id );
		}

		return array(
			'sent'           => true,
			'interest_count' => $count,
		);
	}

	/**
	 * Count vendor interests awaiting organizer notification.
	 *
	 * @param int $external_id External event ID.
	 */
	public static function count_pending_interests( int $external_id ): int {
		global $wpdb;
		$table = LNKup_External_Events_Tables::interest_table();
		if ( ! self::table_exists( $table ) || $external_id <= 0 ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE external_event_id = %d AND status = %s",
				$external_id,
				self::INTEREST_SUBMITTED
			)
		);
	}

	/**
	 * Whether a timeline activity type was already logged for an event.
	 *
	 * @param int    $external_id External event ID.
	 * @param string $type        Activity type.
	 */
	public static function has_timeline_event( int $external_id, string $type ): bool {
		global $wpdb;
		$table = LNKup_External_Events_Tables::activity_table();
		if ( ! LNKup_External_Events_Tables::table_exists( $table ) || $external_id <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE external_event_id = %d AND activity_type = %s LIMIT 1",
				$external_id,
				sanitize_key( $type )
			)
		);
		return ! empty( $found );
	}

	/**
	 * @param int $external_id External event ID.
	 * @param int $vendor_id   Vendor user ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_vendor_interest( int $external_id, int $vendor_id ): ?array {
		global $wpdb;
		$table = LNKup_External_Events_Tables::interest_table();
		if ( ! self::table_exists( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE external_event_id = %d AND vendor_user_id = %d LIMIT 1",
				$external_id,
				$vendor_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * @param int $external_id External event ID.
	 * @param int $vendor_id   Vendor user ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_vendor_interest_status( int $external_id, int $vendor_id ): ?array {
		return self::get_vendor_interest( $external_id, $vendor_id );
	}

	/**
	 * @param int    $external_id External event ID.
	 * @param string $email       Organizer email.
	 * @return string|WP_Error Token string.
	 */
	public static function generate_claim_token( int $external_id, string $email ) {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Invalid organizer email.', 'lnkup-marketplace' ) );
		}

		$token   = wp_generate_password( 32, false, false );
		$now     = current_time( 'mysql' );
		$expires = gmdate( 'Y-m-d H:i:s', strtotime( '+' . self::TOKEN_TTL_DAYS . ' days', strtotime( $now ) ) );

		$table = LNKup_External_Events_Tables::claim_tokens_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'external_event_id' => $external_id,
				'email'             => $email,
				'token'             => $token,
				'expires_at'        => $expires,
				'created_at'        => $now,
			)
		);

		return $token;
	}

	public static function get_token_row( string $token ): ?array {
		global $wpdb;
		$table = LNKup_External_Events_Tables::claim_tokens_table();
		if ( ! self::table_exists( $table ) || '' === $token ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s LIMIT 1", sanitize_text_field( $token ) ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Sign-up context for organizer claim links.
	 *
	 * @param string $token Claim token.
	 * @return array<string, mixed>|null
	 */
	public static function get_claim_signup_context( string $token ): ?array {
		$row = self::get_token_row( $token );
		if ( ! $row || ! empty( $row['used_at'] ) ) {
			return null;
		}
		if ( strtotime( (string) $row['expires_at'] ) < time() ) {
			return null;
		}
		$event = self::get_row( (int) $row['external_event_id'] );
		if ( ! $event || self::CLAIM_CLAIMED === $event['claim_status'] ) {
			return null;
		}
		return array(
			'token'             => $token,
			'email'             => $row['email'],
			'target_role'       => 'lnkup_organizer',
			'external_event_id' => (int) $row['external_event_id'],
			'event_title'       => $event['title'] ?? '',
			'message'           => sprintf(
				/* translators: %s: event title */
				__( 'Claim your event on LNKup: %s. Vendors have requested booth opportunities for this listing.', 'lnkup-marketplace' ),
				$event['title'] ?? __( 'External event', 'lnkup-marketplace' )
			),
		);
	}

	/**
	 * @param string $token Claim token.
	 */
	public static function build_claim_url( string $token ): string {
		return self::build_claim_landing_url( $token );
	}

	/**
	 * Premium claim landing page URL.
	 *
	 * @param string $token Claim token.
	 */
	public static function build_claim_landing_url( string $token ): string {
		$base = lnkup_page_url_helper( 'claim-event' );
		if ( ! $base ) {
			$base = home_url( '/claim-event/' );
		}
		return add_query_arg( 'token', rawurlencode( $token ), $base );
	}

	/**
	 * Claim URL with open/click tracking params.
	 *
	 * @param string $token Claim token.
	 * @param string $type  open|click.
	 */
	public static function build_tracked_claim_url( string $token, string $type = 'click' ): string {
		return add_query_arg(
			array(
				'ext_track' => sanitize_key( $type ),
				'token'     => rawurlencode( $token ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Public claim landing context from token.
	 *
	 * @param string $token Claim token.
	 * @return array<string, mixed>|null
	 */
	public static function get_claim_landing_context( string $token ): ?array {
		$row = self::get_token_row( $token );
		if ( ! $row || ! empty( $row['used_at'] ) ) {
			return null;
		}
		if ( strtotime( (string) $row['expires_at'] ) < time() ) {
			return null;
		}
		$event = self::get_row( (int) $row['external_event_id'] );
		if ( ! $event || self::CLAIM_CLAIMED === $event['claim_status'] ) {
			return null;
		}
		$demand = class_exists( 'LNKup_External_Events_Demand' )
			? LNKup_External_Events_Demand::public_dto( $event )
			: array();
		return array(
			'token'             => $token,
			'external_event_id' => (int) $row['external_event_id'],
			'event'             => self::format_public_row( $event ),
			'demand'            => $demand,
			'signup_url'        => add_query_arg(
				array(
					'role'                 => 'lnkup_organizer',
					'claim_external_event' => $token,
				),
				lnkup_page_url_helper( 'sign-up' ) ?: home_url( '/sign-up/' )
			),
		);
	}

	/**
	 * Track email open/click via signed redirect.
	 */
	public static function maybe_handle_email_tracking(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['ext_track'] ) || empty( $_GET['token'] ) ) {
			return;
		}
		$type  = sanitize_key( wp_unslash( (string) $_GET['ext_track'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $type, array( 'open', 'click' ), true ) || '' === $token ) {
			return;
		}
		if ( class_exists( 'LNKup_External_Events_Followup' ) ) {
			LNKup_External_Events_Followup::track_email( $token, $type );
		}
		if ( 'open' === $type ) {
			header( 'Content-Type: image/gif' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
			exit;
		}
		wp_safe_redirect( self::build_claim_landing_url( $token ) );
		exit;
	}

	/**
	 * @param string $token Claim token.
	 */
	public static function build_claim_url_legacy_signup( string $token ): string {
		$base = lnkup_page_url_helper( 'sign-up' );
		if ( ! $base ) {
			$base = home_url( '/sign-up/' );
		}
		return add_query_arg(
			array(
				'role'                 => 'lnkup_organizer',
				'claim_external_event' => $token,
			),
			$base
		);
	}

	/**
	 * @param int    $external_id External event ID.
	 * @param int    $organizer_id Organizer user ID.
	 * @param string $token       Claim token.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function claim( int $external_id, int $organizer_id, string $token ) {
		global $wpdb;

		if ( ! LNKup_Roles::is_organizer( $organizer_id ) && ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'Only organizers can claim events.', 'lnkup-marketplace' ) );
		}

		$event = self::get_row( $external_id );
		if ( ! $event ) {
			return new WP_Error( 'not_found', __( 'External event not found.', 'lnkup-marketplace' ) );
		}
		if ( self::CLAIM_CLAIMED === $event['claim_status'] && (int) $event['claimed_event_id'] > 0 ) {
			return array(
				'event_id'          => (int) $event['claimed_event_id'],
				'already_claimed'   => true,
				'external_event_id' => $external_id,
			);
		}

		$token_row = self::get_token_row( $token );
		if ( ! $token_row || (int) $token_row['external_event_id'] !== $external_id ) {
			return new WP_Error( 'invalid_token', __( 'Invalid or expired claim link.', 'lnkup-marketplace' ) );
		}
		if ( ! empty( $token_row['used_at'] ) ) {
			return new WP_Error( 'token_used', __( 'This claim link has already been used.', 'lnkup-marketplace' ) );
		}
		if ( strtotime( (string) $token_row['expires_at'] ) < time() ) {
			return new WP_Error( 'token_expired', __( 'This claim link has expired.', 'lnkup-marketplace' ) );
		}

		$event_id = self::create_lnk_event_from_external( $event, $organizer_id );
		if ( is_wp_error( $event_id ) ) {
			return $event_id;
		}

		$events_table = LNKup_External_Events_Tables::events_table();
		$now          = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$events_table,
			array(
				'claim_status'     => self::CLAIM_CLAIMED,
				'claimed_event_id' => (int) $event_id,
				'updated_at'       => $now,
			),
			array( 'id' => $external_id )
		);

		$tokens_table = LNKup_External_Events_Tables::claim_tokens_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$tokens_table,
			array(
				'used_at'           => $now,
				'organizer_user_id' => $organizer_id,
			),
			array( 'id' => (int) $token_row['id'] )
		);

		self::mark_interests_after_claim( $external_id, (int) $event_id );

		self::track( 'external_event_claim_completed', $organizer_id, $external_id, array( 'event_id' => $event_id ) );
		self::track( 'external_event_converted_to_lnkup_event', $organizer_id, $external_id, array( 'event_id' => $event_id ) );

		if ( class_exists( 'LNKup_External_Events_Timeline' ) ) {
			LNKup_External_Events_Timeline::log( $external_id, 'claim_completed', $organizer_id, array( 'event_id' => $event_id ) );
			LNKup_External_Events_Timeline::log( $external_id, 'converted', $organizer_id, array( 'event_id' => $event_id ) );
		}

		delete_user_meta( $organizer_id, '_lnkup_pending_external_claim' );

		return array(
			'event_id'          => (int) $event_id,
			'external_event_id' => $external_id,
			'dashboard_url'     => lnkup_organizer_view_url( 'applications' ),
		);
	}

	/**
	 * Pending vendor interests for organizer's claimed external events.
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function pending_interests_for_organizer( int $organizer_id ): array {
		global $wpdb;

		$events_table   = LNKup_External_Events_Tables::events_table();
		$interest_table = LNKup_External_Events_Tables::interest_table();
		if ( ! self::table_exists( $events_table ) || ! self::table_exists( $interest_table ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.*, e.title AS event_title, e.claimed_event_id, e.id AS external_event_id
				FROM {$interest_table} i
				INNER JOIN {$events_table} e ON e.id = i.external_event_id
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = e.claimed_event_id AND pm.meta_key = '_lnkup_organizer_user_id' AND pm.meta_value = %d
				WHERE e.claim_status = %s AND i.status IN (%s, %s)
				ORDER BY i.created_at DESC LIMIT 100",
				$organizer_id,
				self::CLAIM_CLAIMED,
				self::INTEREST_SUBMITTED,
				self::INTEREST_NOTIFIED
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$profile_id = (int) ( $row['vendor_profile_id'] ?? 0 );
			$name       = '';
			if ( $profile_id ) {
				$profile = get_post( $profile_id );
				$name    = $profile ? $profile->post_title : '';
			}
			$row['vendor_business_name'] = $name;
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Whether marketplace args should include external events.
	 *
	 * @param array<string, mixed> $args Filter args.
	 */
	public static function should_include_in_feed( array $args ): bool {
		if ( array_key_exists( 'include_external', $args ) ) {
			$val = $args['include_external'];
			if ( in_array( $val, array( false, 0, '0', 'false', 'off' ), true ) ) {
				return false;
			}
		}
		// Opportunity-only filters — hide external cards when the user narrows to LNKup booth listings.
		// has_spots is intentionally omitted: it defaults on for opportunities but external events have no spots.
		$block_keys = array(
			'fee_min', 'fee_max', 'fee_bucket', 'doc_required', 'doc_none',
			'compare', 'collection', 'almost_full', 'applications_open', 'instant_approval',
		);
		foreach ( $block_keys as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Track claim page visit.
	 */
	public static function maybe_track_claim_visit(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = '';
		if ( ! empty( $_GET['claim_external_event'] ) ) {
			$token = sanitize_text_field( wp_unslash( (string) $_GET['claim_external_event'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( ! empty( $_GET['token'] ) ) {
			$token = sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( is_user_logged_in() || '' === $token ) {
			return;
		}
		$row = self::get_token_row( $token );
		if ( ! $row || ! empty( $row['opened_at'] ) ) {
			return;
		}
		global $wpdb;
		$table = LNKup_External_Events_Tables::claim_tokens_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, array( 'opened_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row['id'] ) );
		self::track( 'external_event_claim_started', 0, (int) $row['external_event_id'] );
		if ( class_exists( 'LNKup_External_Events_Timeline' ) ) {
			LNKup_External_Events_Timeline::log( (int) $row['external_event_id'], 'claim_started', 0 );
		}
	}

	/**
	 * @param int $user_id New user ID.
	 */
	public static function on_user_register( $user_id ): void {
		self::store_pending_claim_from_request( (int) $user_id );
	}

	/**
	 * @param string  $user_login Username.
	 * @param WP_User $user       User object.
	 */
	public static function on_user_login( $user_login, $user ): void {
		self::store_pending_claim_from_request( (int) $user->ID );
	}

	/**
	 * @param int $user_id User ID.
	 */
	public static function store_pending_claim_from_request( int $user_id ): void {
		$token = '';
		if ( ! empty( $_POST['lnkup_claim_external_event_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$token = sanitize_text_field( wp_unslash( (string) $_POST['lnkup_claim_external_event_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( ! empty( $_GET['claim_external_event'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token = sanitize_text_field( wp_unslash( (string) $_GET['claim_external_event'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( ! empty( $_GET['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token = sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( '' === $token ) {
			return;
		}
		$row = self::get_token_row( $token );
		if ( ! $row ) {
			return;
		}
		update_user_meta(
			$user_id,
			'_lnkup_pending_external_claim',
			array(
				'token'             => $token,
				'external_event_id' => (int) $row['external_event_id'],
			)
		);
	}

	/**
	 * Process pending claim after organizer auth.
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @return array<string, mixed>|WP_Error|null
	 */
	public static function maybe_complete_pending_claim( int $organizer_id ) {
		$pending = get_user_meta( $organizer_id, '_lnkup_pending_external_claim', true );
		if ( ! is_array( $pending ) || empty( $pending['token'] ) || empty( $pending['external_event_id'] ) ) {
			return null;
		}
		return self::claim( (int) $pending['external_event_id'], $organizer_id, (string) $pending['token'] );
	}

	/**
	 * @param array<string, mixed> $event External event row.
	 * @param int                  $organizer_id Organizer user ID.
	 * @return int|WP_Error
	 */
	protected static function create_lnk_event_from_external( array $event, int $organizer_id ) {
		$date = '';
		if ( ! empty( $event['start_datetime'] ) ) {
			$date = gmdate( 'Y-m-d', strtotime( (string) $event['start_datetime'] ) );
		}

		$input = array(
			'event_name'  => $event['title'],
			'description' => $event['description_excerpt'],
			'event_date'  => $date,
			'city'        => $event['city'] ?? '',
			'state'       => $event['state'] ?? '',
			'venue_name'  => $event['venue_name'] ?? '',
			'event_type'  => $event['event_type'] ?? '',
		);

		$result = LNKup_Organizer_Onboarding::create_event_from_request( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$event_id = (int) ( $result['event_id'] ?? 0 );
		if ( $event_id ) {
			update_post_meta( $event_id, '_lnkup_external_event_id', (int) $event['id'] );
			if ( ! empty( $event['source_url'] ) ) {
				update_post_meta( $event_id, '_lnkup_external_source_url', esc_url_raw( (string) $event['source_url'] ) );
			}
		}
		return $event_id;
	}

	/**
	 * @param int $external_id External event ID.
	 * @param int $event_id    Claimed LNK event ID.
	 */
	protected static function mark_interests_after_claim( int $external_id, int $event_id ): void {
		global $wpdb;
		$table = LNKup_External_Events_Tables::interest_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, updated_at = %s WHERE external_event_id = %d AND status IN (%s, %s)",
				self::INTEREST_NOTIFIED,
				current_time( 'mysql' ),
				$external_id,
				self::INTEREST_SUBMITTED,
				self::INTEREST_NOTIFIED
			)
		);
	}

	protected static function increment_interest_count( int $external_id ): void {
		global $wpdb;
		$table = LNKup_External_Events_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET vendor_interest_count = vendor_interest_count + 1, updated_at = %s WHERE id = %d", current_time( 'mysql' ), $external_id ) );
	}

	/**
	 * @param int    $id     External event ID.
	 * @param string $status Claim status.
	 * @return true|WP_Error
	 */
	protected static function set_claim_status( int $id, string $status ) {
		global $wpdb;
		if ( ! self::get_row( $id ) ) {
			return new WP_Error( 'not_found', __( 'External event not found.', 'lnkup-marketplace' ) );
		}
		$table = LNKup_External_Events_Tables::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'claim_status' => $status,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
		return true;
	}

	/**
	 * @param int $vendor_id Vendor user ID.
	 */
	protected static function vendor_profile_id( int $vendor_id ): int {
		$profiles = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_profile',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_vendor_user_id',
				'meta_value'     => $vendor_id,
				'fields'         => 'ids',
			)
		);
		return ! empty( $profiles[0] ) ? (int) $profiles[0] : 0;
	}

	/**
	 * @param array<string, mixed> $row Raw DB row.
	 * @return array<string, mixed>
	 */
	protected static function format_public_row( array $row ): array {
		$date_label = '';
		if ( ! empty( $row['start_datetime'] ) ) {
			$date_label = date_i18n( get_option( 'date_format' ), strtotime( (string) $row['start_datetime'] ) );
		}
		$location = trim( ( $row['city'] ?? '' ) . ( ! empty( $row['state'] ) ? ', ' . $row['state'] : '' ) );

		$demand   = class_exists( 'LNKup_External_Events_Demand' )
			? LNKup_External_Events_Demand::public_dto( $row )
			: array();
		$badges   = class_exists( 'LNKup_External_Events_Demand_Score' )
			? LNKup_External_Events_Demand_Score::badges_for_row( $row )
			: array();
		$score    = (int) ( $demand['demand_score'] ?? $row['demand_score'] ?? 0 );
		$recs     = class_exists( 'LNKup_External_Events_Recommendations' )
			? LNKup_External_Events_Recommendations::for_event( $row )
			: array();

		$event_type = (string) ( $row['event_type'] ?? '' );
		if ( '' === $event_type ) {
			$event_type = self::map_event_type( '', (string) ( $row['title'] ?? '' ) );
		}

		return array(
			'id'                    => (int) $row['id'],
			'item_type'             => 'external_event',
			'source_platform'       => $row['source_platform'] ?? 'eventbrite',
			'source_url'            => $row['source_url'] ?? '',
			'title'                 => $row['title'] ?? '',
			'description_excerpt'   => $row['description_excerpt'] ?? '',
			'image_url'             => $row['image_url'] ?? '',
			'start_datetime'        => $row['start_datetime'] ?? '',
			'end_datetime'          => $row['end_datetime'] ?? '',
			'date_label'            => $date_label,
			'city'                  => $row['city'] ?? '',
			'state'                 => $row['state'] ?? '',
			'location_label'        => $location ?: __( 'Location TBA', 'lnkup-marketplace' ),
			'venue_name'            => $row['venue_name'] ?? '',
			'organizer_name'        => $row['organizer_name'] ?? '',
			'event_type'            => $event_type,
			'event_type_label'      => $event_type ?: __( 'Event Opportunity', 'lnkup-marketplace' ),
			'vendor_interest_count' => (int) ( $row['vendor_interest_count'] ?? 0 ),
			'vendor_fit_score'      => (int) ( $row['vendor_fit_score'] ?? 0 ),
			'sort_date'             => ! empty( $row['start_datetime'] ) ? strtotime( (string) $row['start_datetime'] ) : 0,
			'demand_score'          => $score,
			'demand_label'          => class_exists( 'LNKup_External_Events_Demand_Score' )
				? LNKup_External_Events_Demand_Score::label( $score )
				: '',
			'interest_breakdown'    => $demand['breakdown'] ?? array(),
			'recent_activity_label' => $demand['recent_activity'] ?? '',
			'badges'                => $badges,
			'recommended_categories'=> $recs,
			'interest_trend'        => (string) ( $row['interest_trend'] ?? 'stable' ),
		);
	}

	/**
	 * @param array<string, mixed> $data     Input data.
	 * @param bool                 $creating Creating new row.
	 * @return array<string, mixed>
	 */
	protected static function normalize_row( array $data, bool $creating = true ): array {
		$row = array();
		if ( $creating || isset( $data['source_platform'] ) ) {
			$row['source_platform'] = sanitize_key( (string) ( $data['source_platform'] ?? 'eventbrite' ) ) ?: 'eventbrite';
		}
		if ( $creating || isset( $data['source_event_id'] ) ) {
			$row['source_event_id'] = sanitize_text_field( (string) ( $data['source_event_id'] ?? '' ) );
		}
		if ( $creating || isset( $data['source_url'] ) ) {
			$row['source_url'] = esc_url_raw( (string) ( $data['source_url'] ?? '' ) );
		}
		if ( $creating || isset( $data['title'] ) ) {
			$row['title'] = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		}
		if ( $creating || isset( $data['description_excerpt'] ) || isset( $data['description'] ) ) {
			$excerpt = (string) ( $data['description_excerpt'] ?? $data['description'] ?? '' );
			$row['description_excerpt'] = self::sanitize_excerpt( $excerpt );
		}
		if ( $creating || isset( $data['image_url'] ) ) {
			$image = (string) ( $data['image_url'] ?? '' );
			if ( class_exists( 'LNKup_External_Source_Eventbrite' ) ) {
				$image = LNKup_External_Source_Eventbrite::sanitize_image_url( $image );
			} else {
				$image = esc_url_raw( $image );
			}
			$row['image_url'] = $image;
		}
		if ( $creating || isset( $data['start_datetime'] ) || isset( $data['date'] ) || isset( $data['event_date'] ) ) {
			$row['start_datetime'] = self::parse_datetime( (string) ( $data['start_datetime'] ?? $data['date'] ?? $data['event_date'] ?? '' ) );
		}
		if ( isset( $data['end_datetime'] ) ) {
			$row['end_datetime'] = self::parse_datetime( (string) $data['end_datetime'] );
		}
		if ( $creating || isset( $data['city'] ) ) {
			$row['city'] = sanitize_text_field( (string) ( $data['city'] ?? '' ) );
		}
		if ( $creating || isset( $data['state'] ) ) {
			$row['state'] = sanitize_text_field( (string) ( $data['state'] ?? '' ) );
		}
		if ( $creating || isset( $data['venue_name'] ) || isset( $data['venue'] ) ) {
			$row['venue_name'] = sanitize_text_field( (string) ( $data['venue_name'] ?? $data['venue'] ?? '' ) );
		}
		if ( $creating || isset( $data['organizer_name'] ) ) {
			$row['organizer_name'] = sanitize_text_field( (string) ( $data['organizer_name'] ?? '' ) );
		}
		if ( $creating || isset( $data['organizer_url'] ) ) {
			$row['organizer_url'] = esc_url_raw( (string) ( $data['organizer_url'] ?? '' ) );
		}
		if ( $creating || isset( $data['organizer_email'] ) ) {
			$row['organizer_email'] = sanitize_email( (string) ( $data['organizer_email'] ?? '' ) );
		}
		if ( $creating || isset( $data['application_url'] ) ) {
			$row['application_url'] = esc_url_raw( (string) ( $data['application_url'] ?? '' ) );
		}
		if ( $creating || isset( $data['organizer_instagram'] ) ) {
			$row['organizer_instagram'] = self::sanitize_instagram( (string) ( $data['organizer_instagram'] ?? '' ) );
		}
		if ( $creating || isset( $data['discovery_query'] ) ) {
			$row['discovery_query'] = sanitize_text_field( (string) ( $data['discovery_query'] ?? '' ) );
		}
		if ( $creating || isset( $data['event_type'] ) ) {
			$row['event_type'] = sanitize_text_field( (string) ( $data['event_type'] ?? '' ) );
		}
		if ( isset( $data['vendor_fit_score'] ) ) {
			$row['vendor_fit_score'] = min( 100, max( 0, absint( $data['vendor_fit_score'] ) ) );
		}
		if ( isset( $data['claim_status'] ) ) {
			$row['claim_status'] = sanitize_key( (string) $data['claim_status'] );
		}
		if ( $creating || isset( $data['workflow_status'] ) ) {
			$row['workflow_status'] = sanitize_key( (string) ( $data['workflow_status'] ?? self::WORKFLOW_PUBLISHED ) ) ?: self::WORKFLOW_PUBLISHED;
		}
		return $row;
	}

	/**
	 * Normalize an Instagram handle or URL.
	 *
	 * @param string $value Handle or profile URL.
	 */
	public static function sanitize_instagram( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#instagram\.com/([A-Za-z0-9._]+)#i', $value, $m ) ) {
			$handle = $m[1];
		} else {
			$handle = ltrim( $value, '@' );
		}
		$handle = sanitize_text_field( $handle );
		if ( '' === $handle || in_array( strtolower( $handle ), array( 'p', 'reel', 'reels', 'stories', 'explore' ), true ) ) {
			return '';
		}
		if ( ! preg_match( '/^[A-Za-z0-9._]+$/', $handle ) ) {
			return '';
		}
		return 'https://www.instagram.com/' . $handle;
	}

	/**
	 * @param string $value Date or datetime string.
	 */
	protected static function parse_datetime( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		if ( ! $ts ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * @param array<int, string>   $where  WHERE clauses.
	 * @param array<int, mixed>    $values Prepared values.
	 * @param array<string, mixed> $args   Filter args.
	 */
	protected static function apply_date_filters( array &$where, array &$values, array $args ): void {
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'start_datetime >= %s';
			$values[] = self::parse_datetime( (string) $args['date_from'] ) ?: gmdate( 'Y-m-d 00:00:00' );
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'start_datetime <= %s';
			$values[] = self::parse_datetime( (string) $args['date_to'] ) ?: gmdate( 'Y-m-d 23:59:59' );
		}
		if ( ! empty( $args['date_preset'] ) && empty( $args['date_from'] ) ) {
			$preset = sanitize_key( (string) $args['date_preset'] );
			if ( 'next_30' === $preset ) {
				$where[]  = 'start_datetime >= %s AND start_datetime <= %s';
				$values[] = gmdate( 'Y-m-d 00:00:00' );
				$values[] = gmdate( 'Y-m-d 23:59:59', strtotime( '+30 days' ) );
			}
		}
	}

	protected static function table_exists( string $table ): bool {
		if ( class_exists( 'LNKup_Financial_Tables' ) ) {
			return LNKup_Financial_Tables::table_exists( $table );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * @param string               $type         Event type.
	 * @param int                  $user_id      User ID.
	 * @param int                  $external_id  External event ID.
	 * @param array<string, mixed> $extra        Extra payload.
	 */
	public static function track( string $type, int $user_id, int $external_id, array $extra = array() ): void {
		if ( ! class_exists( 'LNKup_Marketplace_Analytics' ) ) {
			return;
		}
		$data = array_merge( array( 'external_event_id' => $external_id ), $extra );
		LNKup_Marketplace_Analytics::track( $type, $data, $user_id, 0 );
	}
}
