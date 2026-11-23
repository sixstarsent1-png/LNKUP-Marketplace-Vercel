<?php
/**
 * Organizer event + first opportunity onboarding.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Shared create logic for organizer event and opportunity flows.
 */
class LNKup_Organizer_Onboarding {

	/**
	 * Create event from sanitized request input.
	 *
	 * @param array<string, mixed> $input POST-style input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_event_from_request( array $input ) {
		$name = isset( $input['event_name'] ) ? sanitize_text_field( (string) $input['event_name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'missing_name', __( 'Event name is required.', 'lnkup-marketplace' ) );
		}

		$organizer_id = get_current_user_id();
		$client_token = isset( $input['client_token'] ) ? sanitize_text_field( (string) $input['client_token'] ) : '';
		if ( '' === $client_token ) {
			return self::insert_event_from_request( $input, $name, $organizer_id, '' );
		}

		global $wpdb;
		$lock = 'lnkup_evt_' . $organizer_id . '_' . md5( $client_token );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'busy', __( 'Your event is still being created. Refresh in a moment.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		try {
			$existing = self::event_for_client_token( $organizer_id, $client_token );
			if ( $existing ) {
				return self::event_create_response( $existing, true );
			}
			return self::insert_event_from_request( $input, $name, $organizer_id, $client_token );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Event this organizer already created for a Create Event submission token.
	 *
	 * @param int    $organizer_id Organizer user ID.
	 * @param string $client_token Form submission token.
	 */
	private static function event_for_client_token( int $organizer_id, string $client_token ): int {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_client_token_hash', 'value' => md5( $client_token ) ),
					array( 'key' => '_lnkup_organizer_user_id', 'value' => $organizer_id ),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Response for a created (or replayed) event.
	 *
	 * @param int  $event_id   Event ID.
	 * @param bool $idempotent True when an earlier submission already created it.
	 */
	private static function event_create_response( int $event_id, bool $idempotent = false ): array {
		return array(
			'event_id'       => $event_id,
			'event_title'    => get_the_title( $event_id ),
			'event_date'     => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
			'event_city'     => (string) get_post_meta( $event_id, '_lnkup_city', true ),
			'view_event_url' => add_query_arg( 'event', $event_id, lnkup_page_url_helper( 'organizer-dashboard' ) ),
			'onboarding'     => true,
			'idempotent'     => $idempotent,
		);
	}

	/**
	 * Insert the event post and its listing meta.
	 *
	 * @param array<string, mixed> $input        Input.
	 * @param string               $name         Sanitized event name.
	 * @param int                  $organizer_id Organizer user ID.
	 * @param string               $client_token Submission token ('' = none).
	 * @return array<string, mixed>|WP_Error
	 */
	private static function insert_event_from_request( array $input, string $name, int $organizer_id, string $client_token ) {
		$event_id = wp_insert_post(
			array(
				'post_title'   => $name,
				'post_type'    => 'lnkup_event',
				'post_status'  => 'publish',
				'post_content' => isset( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : '',
			),
			true
		);

		if ( is_wp_error( $event_id ) ) {
			return $event_id;
		}

		update_post_meta( $event_id, '_lnkup_organizer_user_id', $organizer_id );
		if ( '' !== $client_token ) {
			update_post_meta( $event_id, '_lnkup_client_token_hash', md5( $client_token ) );
		}
		$meta_fields = array( 'event_date', 'start_time', 'end_time', 'city', 'state', 'venue_name', 'venue_address', 'expected_attendance', 'ticketing_platform', 'ticketing_url' );
		foreach ( $meta_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$val = 'ticketing_url' === $field ? esc_url_raw( (string) $input[ $field ] ) : sanitize_text_field( (string) $input[ $field ] );
				update_post_meta( $event_id, '_lnkup_' . $field, $val );
			}
		}
		if ( ! empty( $input['ticketing_url'] ) ) {
			update_post_meta( $event_id, '_lnkup_external_source_url', esc_url_raw( (string) $input['ticketing_url'] ) );
		}
		update_post_meta( $event_id, '_lnkup_venue_hidden', ! empty( $input['venue_hidden'] ) );
		if ( isset( $input['power_available'] ) ) {
			update_post_meta( $event_id, '_lnkup_power_available', ! empty( $input['power_available'] ) );
		}
		update_post_meta( $event_id, '_lnkup_status', 'published' );
		update_post_meta( $event_id, '_lnkup_event_status', 'draft' );

		$gallery_raw = $input['gallery_ids'] ?? $input['gallery'] ?? '';
		if ( $gallery_raw || ! empty( $input['featured_image_id'] ) ) {
			lnkup_save_post_gallery( $event_id, $gallery_raw );
			self::save_event_featured_image( $event_id, $input['featured_image_id'] ?? 0 );
		}

		if ( ! empty( $input['event_type'] ) ) {
			wp_set_object_terms( $event_id, sanitize_text_field( (string) $input['event_type'] ), 'lnkup_event_type' );
		}

		if ( class_exists( 'LNKup_Geocoder' ) ) {
			LNKup_Geocoder::geocode_event( $event_id );
		}

		return self::event_create_response( (int) $event_id );
	}

	/**
	 * Create opportunity linked to an organizer-owned event.
	 *
	 * @param int                  $event_id     Event post ID.
	 * @param array<string, mixed> $input        POST-style input.
	 * @param int                  $organizer_id Organizer user ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_opportunity_for_event( int $event_id, array $input, int $organizer_id ) {
		$client_token = isset( $input['client_token'] ) ? sanitize_text_field( (string) $input['client_token'] ) : '';
		if ( '' === $client_token ) {
			return self::insert_opportunity_for_event( $event_id, $input, $organizer_id );
		}
		// Two in-flight publishes of the same draft both reach this point after the event lock is
		// released; holding a lock per row token makes the "already created?" check and the insert atomic.
		global $wpdb;
		$lock = 'lnkup_opp_' . $organizer_id . '_' . md5( $client_token );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'busy', __( 'Your vendor spots are still being created. Refresh in a moment.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		try {
			return self::insert_opportunity_for_event( $event_id, $input, $organizer_id );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Create one opportunity (or return the one an earlier request with the same token created).
	 *
	 * @param int                  $event_id     Event post ID.
	 * @param array<string, mixed> $input        Opportunity fields.
	 * @param int                  $organizer_id Organizer user ID.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function insert_opportunity_for_event( int $event_id, array $input, int $organizer_id ) {
		$event = get_post( $event_id );
		if ( ! $event || 'lnkup_event' !== $event->post_type ) {
			return new WP_Error( 'invalid_event', __( 'Invalid event.', 'lnkup-marketplace' ) );
		}
		if ( ! LNKup_Capabilities::user_owns_post( $event_id, $organizer_id, '_lnkup_organizer_user_id' ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot create opportunities for this event.', 'lnkup-marketplace' ) );
		}

		$test_block = self::should_block_opportunity_for_test( $event_id );
		if ( is_wp_error( $test_block ) ) {
			return $test_block;
		}

		$client_token = isset( $input['client_token'] ) ? sanitize_text_field( (string) $input['client_token'] ) : '';
		if ( '' !== $client_token ) {
			$cache_key = 'lnkup_opp_create_' . $organizer_id . '_' . md5( $client_token );
			$cached    = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached['opportunity_id'] ) ) {
				return self::build_opportunity_response( (int) $cached['opportunity_id'], $event, $cached['indexed'] ?? true, true );
			}
			$prior = get_posts(
				array(
					'post_type'      => 'lnkup_opportunity',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => '_lnkup_client_token_hash', 'value' => md5( $client_token ) ),
						array( 'key' => '_lnkup_event_id', 'value' => $event_id ),
					),
				)
			);
			if ( $prior ) {
				return self::build_opportunity_response( (int) $prior[0], $event, true, true );
			}
		}

		$spots = isset( $input['spots_total'] ) ? absint( $input['spots_total'] ) : 10;
		if ( $spots < 1 ) {
			return new WP_Error( 'invalid_spots', __( 'At least one vendor spot is required.', 'lnkup-marketplace' ) );
		}

		$fee = isset( $input['vendor_fee'] ) ? LNKup_Security::sanitize_money( $input['vendor_fee'] ) : 250.0;
		if ( $fee < 0 ) {
			return new WP_Error( 'invalid_fee', __( 'Vendor fee cannot be negative.', 'lnkup-marketplace' ) );
		}

		$title = isset( $input['opportunity_title'] ) ? sanitize_text_field( (string) $input['opportunity_title'] ) : '';
		if ( '' === $title ) {
			/* translators: %s: event title */
			$title = sprintf( __( 'Vendors for %s', 'lnkup-marketplace' ), $event->post_title );
		}

		$description = isset( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : '';
		if ( '' === $description && isset( $input['notes'] ) ) {
			$description = sanitize_textarea_field( (string) $input['notes'] );
		}

		$opp_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_type'    => 'lnkup_opportunity',
				'post_status'  => 'publish',
				'post_content' => $description,
			),
			true
		);

		if ( is_wp_error( $opp_id ) ) {
			return new WP_Error(
				'opp_insert_failed',
				__( 'Event created, but opportunity could not be created. Please add it manually.', 'lnkup-marketplace' )
			);
		}

		$settings = lnkup_get_settings();
		$deadline = '';
		if ( ! empty( $input['application_deadline'] ) ) {
			$deadline = sanitize_text_field( (string) $input['application_deadline'] );
		} elseif ( ! empty( $input['deadline'] ) ) {
			$deadline = sanitize_text_field( (string) $input['deadline'] );
		}
		if ( '' === $deadline ) {
			$deadline = lnkup_default_application_deadline( $event_id );
		}

		update_post_meta( $opp_id, '_lnkup_event_id', $event_id );
		update_post_meta( $opp_id, '_lnkup_organizer_user_id', $organizer_id );
		if ( '' !== $client_token ) {
			update_post_meta( $opp_id, '_lnkup_client_token_hash', md5( $client_token ) );
		}
		update_post_meta( $opp_id, '_lnkup_spots_total', $spots );
		update_post_meta( $opp_id, '_lnkup_spots_remaining', $spots );
		update_post_meta( $opp_id, '_lnkup_vendor_fee', $fee );
		update_post_meta(
			$opp_id,
			'_lnkup_lnkup_fee',
			class_exists( 'LNKup_Fee_Engine' ) ? LNKup_Fee_Engine::resolve_vendor_checkout_fee_from_settings() : (float) ( $settings['vendor_checkout_fee'] ?? 5.99 )
		);
		update_post_meta( $opp_id, '_lnkup_application_deadline', $deadline );
		update_post_meta( $opp_id, '_lnkup_opportunity_status', 'open' );

		$event_gallery = lnkup_parse_gallery_ids( get_post_meta( $event_id, '_lnkup_gallery_ids', true ) );
		if ( $event_gallery ) {
			update_post_meta( $opp_id, '_lnkup_gallery_ids', wp_json_encode( $event_gallery ) );
		}
		$event_featured = (int) get_post_meta( $event_id, '_lnkup_featured_image_id', true );
		if ( $event_featured ) {
			update_post_meta( $opp_id, '_lnkup_featured_image_id', $event_featured );
		}

		if ( isset( $input['deposit_required'] ) ) {
			update_post_meta( $opp_id, '_lnkup_deposit_required', ! empty( $input['deposit_required'] ) );
		}
		$packet_lock = isset( $input['packet_lock'] )
			? ! empty( $input['packet_lock'] )
			: ! empty( $settings['default_packet_lock'] );
		update_post_meta( $opp_id, '_lnkup_packet_lock', $packet_lock );

		if ( ! empty( $input['organizer_notes'] ) || ! empty( $input['notes'] ) ) {
			$notes = ! empty( $input['organizer_notes'] ) ? $input['organizer_notes'] : $input['notes'];
			update_post_meta( $opp_id, '_lnkup_organizer_notes', sanitize_textarea_field( (string) $notes ) );
		}

		self::apply_environment_to_event( $event_id, $input );
		self::apply_expected_attendance_to_event( $event_id, $input );
		self::apply_required_documents( $opp_id, $input, $settings );
		self::apply_vendor_categories( $opp_id, $input );
		self::sync_event_listing_status( $event_id );

		$indexed = true;
		try {
			if ( class_exists( 'LNKup_Geocoder' ) ) {
				LNKup_Geocoder::geocode_event( $event_id );
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup create_opportunity geocode: ' . $e->getMessage() );
			}
		}

		try {
			$synced  = LNKup_Marketplace_Index::sync_opportunity( $opp_id );
			$indexed = ! is_wp_error( $synced );
		} catch ( Throwable $e ) {
			$indexed = false;
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup create_opportunity sync: ' . $e->getMessage() );
			}
		}

		try {
			if ( class_exists( 'LNKup_Event_Operations' ) ) {
				LNKup_Event_Operations::bootstrap_from_event( $event_id );
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup create_opportunity bootstrap: ' . $e->getMessage() );
			}
		}

		if ( '' !== $client_token ) {
			set_transient(
				'lnkup_opp_create_' . $organizer_id . '_' . md5( $client_token ),
				array(
					'opportunity_id' => (int) $opp_id,
					'indexed'        => $indexed,
				),
				120
			);
		}

		return self::build_opportunity_response( (int) $opp_id, $event, $indexed, false );
	}

	/**
	 * E2E test hook — block opportunity insert when header present.
	 *
	 * @param int $event_id Event ID.
	 * @return true|WP_Error
	 */
	private static function should_block_opportunity_for_test( int $event_id ) {
		$blocked = apply_filters( 'lnkup_test_block_opportunity_create', false, $event_id );
		if ( ! $blocked && defined( 'WP_DEBUG' ) && WP_DEBUG && 'production' !== wp_get_environment_type() ) {
			$header  = isset( $_SERVER['HTTP_X_LNKUP_TEST_BLOCK_OPP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_LNKUP_TEST_BLOCK_OPP'] ) ) : '';
			$blocked = '1' === $header;
		}
		if ( $blocked ) {
			return new WP_Error(
				'lnkup_test_block',
				__( 'Event created, but opportunity could not be created. Please add it manually.', 'lnkup-marketplace' )
			);
		}
		return true;
	}

	/**
	 * Apply indoor/outdoor/both to event meta.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $input    Input.
	 */
	private static function apply_environment_to_event( int $event_id, array $input ): void {
		if ( ! empty( $input['environment'] ) ) {
			$env = sanitize_key( (string) $input['environment'] );
			if ( 'indoor' === $env ) {
				update_post_meta( $event_id, '_lnkup_indoor', true );
				update_post_meta( $event_id, '_lnkup_outdoor', false );
			} elseif ( 'outdoor' === $env ) {
				update_post_meta( $event_id, '_lnkup_indoor', false );
				update_post_meta( $event_id, '_lnkup_outdoor', true );
			} elseif ( 'both' === $env ) {
				update_post_meta( $event_id, '_lnkup_indoor', true );
				update_post_meta( $event_id, '_lnkup_outdoor', true );
			}
			return;
		}
		if ( isset( $input['indoor'] ) ) {
			update_post_meta( $event_id, '_lnkup_indoor', ! empty( $input['indoor'] ) );
		}
		if ( isset( $input['outdoor'] ) ) {
			update_post_meta( $event_id, '_lnkup_outdoor', ! empty( $input['outdoor'] ) );
		}
	}

	/**
	 * Set expected attendance on event when provided.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $input    Input.
	 */
	private static function apply_expected_attendance_to_event( int $event_id, array $input ): void {
		if ( ! isset( $input['expected_attendance'] ) || '' === (string) $input['expected_attendance'] ) {
			return;
		}
		$current = get_post_meta( $event_id, '_lnkup_expected_attendance', true );
		if ( '' === (string) $current ) {
			update_post_meta( $event_id, '_lnkup_expected_attendance', absint( $input['expected_attendance'] ) );
		}
	}

	/**
	 * Required documents on opportunity.
	 *
	 * @param int                  $opp_id   Opportunity ID.
	 * @param array<string, mixed> $input    Input.
	 * @param array<string, mixed> $settings Plugin settings.
	 */
	private static function apply_required_documents( int $opp_id, array $input, array $settings ): void {
		unset( $settings );
		$mode = 'none';
		if ( ! empty( $input['document_mode'] ) ) {
			$mode = sanitize_key( (string) $input['document_mode'] );
		}
		if ( ! in_array( $mode, array( 'none', 'platform_defaults', 'custom' ), true ) ) {
			$mode = 'none';
		}
		update_post_meta( $opp_id, '_lnkup_document_mode', $mode );

		if ( 'none' === $mode ) {
			update_post_meta( $opp_id, '_lnkup_required_documents', 'none' );
			return;
		}
		if ( 'platform_defaults' === $mode ) {
			delete_post_meta( $opp_id, '_lnkup_required_documents' );
			return;
		}
		$docs = array();
		if ( ! empty( $input['required_documents'] ) && is_array( $input['required_documents'] ) ) {
			$docs = array_map( 'sanitize_text_field', $input['required_documents'] );
		}
		if ( ! empty( $docs ) ) {
			update_post_meta( $opp_id, '_lnkup_required_documents', implode( ',', $docs ) );
		} else {
			update_post_meta( $opp_id, '_lnkup_required_documents', 'none' );
			update_post_meta( $opp_id, '_lnkup_document_mode', 'none' );
		}
	}

	/**
	 * Vendor categories taxonomy.
	 *
	 * @param int                  $opp_id Opportunity ID.
	 * @param array<string, mixed> $input  Input.
	 */
	private static function apply_vendor_categories( int $opp_id, array $input ): void {
		$labels = array();
		if ( ! empty( $input['vendor_categories'] ) && is_array( $input['vendor_categories'] ) ) {
			$labels = $input['vendor_categories'];
		} elseif ( ! empty( $input['categories'] ) && is_array( $input['categories'] ) ) {
			$labels = $input['categories'];
		} elseif ( ! empty( $input['vendor_category'] ) ) {
			$labels = array( $input['vendor_category'] );
		}

		$clean = array();
		foreach ( $labels as $label ) {
			$resolved = self::resolve_vendor_category_label( (string) $label );
			if ( is_wp_error( $resolved ) || '' === $resolved ) {
				continue;
			}
			$clean[] = $resolved;
		}
		if ( $clean ) {
			wp_set_object_terms( $opp_id, $clean, 'lnkup_vendor_category' );
		}
	}

	/**
	 * Use an existing vendor category or create one from a custom slot name.
	 *
	 * @param string $label Category or custom type label.
	 * @return string|WP_Error Sanitized label, or error when the name is unusable.
	 */
	private static function resolve_vendor_category_label( string $label ) {
		$label = sanitize_text_field( trim( $label ) );
		if ( '' === $label ) {
			return '';
		}
		if ( strlen( $label ) > 80 ) {
			return new WP_Error( 'invalid_category', __( 'Custom type must be 80 characters or fewer.', 'lnkup-marketplace' ) );
		}

		$exists = term_exists( $label, 'lnkup_vendor_category' );
		if ( ! $exists ) {
			$created = wp_insert_term( $label, 'lnkup_vendor_category' );
			if ( is_wp_error( $created ) && 'term_exists' !== $created->get_error_code() ) {
				return $created;
			}
		}

		return $label;
	}

	/**
	 * Build success payload for opportunity creation.
	 *
	 * @param int      $opp_id     Opportunity ID.
	 * @param WP_Post  $event      Event post.
	 * @param bool     $indexed    Index sync ok.
	 * @param bool     $idempotent Replay from cache.
	 * @return array<string, mixed>
	 */
	private static function build_opportunity_response( int $opp_id, WP_Post $event, bool $indexed, bool $idempotent ): array {
		$dash_url = lnkup_page_url_helper( 'organizer-dashboard' );
		$event_id = (int) $event->ID;

		$message = $indexed
			? __( 'Vendor slot added. Vendors can apply in Find Events.', 'lnkup-marketplace' )
			: __( 'Vendor slot added, but it could not be indexed. Try rebuilding the marketplace index in LNKup Settings.', 'lnkup-marketplace' );

		if ( $idempotent ) {
			$message = __( 'Vendor slot added. Vendors can apply in Find Events.', 'lnkup-marketplace' );
		}

		return array(
			'message'                 => $message,
			'event_id'                => $event_id,
			'event_title'             => $event->post_title,
			'opportunity_id'          => $opp_id,
			'indexed'                 => $indexed,
			'vendor_fee'              => (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ),
			'spots_total'             => (int) get_post_meta( $opp_id, '_lnkup_spots_total', true ),
			'find_events_url'         => add_query_arg( 'q', rawurlencode( $event->post_title ), lnkup_page_url_helper( 'opportunities' ) ),
			'application_url'         => add_query_arg( 'apply', $opp_id, lnkup_page_url_helper( 'opportunities' ) ),
			'event_url'               => self::event_public_url( $event_id ),
			'organizer_url'           => self::organizer_public_url( (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) ),
			'view_event_url'          => add_query_arg( 'event', $event_id, $dash_url ),
			'manage_applications_url' => lnkup_organizer_view_url( 'applications', array( 'event_filter' => $event_id, 'filter' => 'pending' ) ),
			'invite_vendors_url'      => lnkup_organizer_view_url(
				'vendors',
				array(
					'invite_event' => $event_id,
					'opp'          => $opp_id,
				)
			),
			'operations_url'          => add_query_arg( array( 'event' => $event_id, 'ops' => 1 ), $dash_url ),
		);
	}

	/**
	 * Update vendor fee and/or spots on an organizer-owned opportunity.
	 *
	 * @param int        $opp_id       Opportunity post ID.
	 * @param float|null $vendor_fee   New fee (null = leave unchanged).
	 * @param int|null   $spots_total  New spots total (null = leave unchanged).
	 * @param int        $organizer_id Organizer user ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_opportunity_pricing( int $opp_id, $vendor_fee, $spots_total, int $organizer_id, string $title = '' ) {
		$opp = get_post( $opp_id );
		if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type ) {
			return new WP_Error( 'invalid_opportunity', __( 'Invalid opportunity.', 'lnkup-marketplace' ) );
		}

		$event_id   = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		$owns_event = $event_id > 0 && LNKup_Capabilities::user_owns_post( $event_id, $organizer_id, '_lnkup_organizer_user_id' );
		$owns_opp   = LNKup_Capabilities::user_owns_post( $opp_id, $organizer_id, '_lnkup_organizer_user_id' );
		if ( ! $owns_event && ! $owns_opp && ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot edit this opportunity.', 'lnkup-marketplace' ) );
		}

		$old_total = (int) get_post_meta( $opp_id, '_lnkup_spots_total', true );
		$filled    = LNKup_Opportunity_Inventory::holds( $opp_id );

		$fee = null === $vendor_fee
			? (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true )
			: LNKup_Security::sanitize_money( $vendor_fee );
		if ( $fee < 0 ) {
			return new WP_Error( 'invalid_fee', __( 'Vendor fee cannot be negative.', 'lnkup-marketplace' ) );
		}

		$spots = null === $spots_total ? $old_total : absint( $spots_total );
		if ( $spots < 1 ) {
			return new WP_Error( 'invalid_spots', __( 'At least one vendor spot is required.', 'lnkup-marketplace' ) );
		}
		if ( $spots < $filled ) {
			return new WP_Error(
				'spots_too_low',
				sprintf(
					/* translators: %d: number of filled spots */
					__( 'Spots cannot be less than %d already filled.', 'lnkup-marketplace' ),
					$filled
				)
			);
		}

		update_post_meta( $opp_id, '_lnkup_vendor_fee', $fee );
		update_post_meta( $opp_id, '_lnkup_spots_total', $spots );
		LNKup_Opportunity_Inventory::recompute( $opp_id );

		$title = sanitize_text_field( $title );
		if ( '' !== $title ) {
			wp_update_post(
				array(
					'ID'         => $opp_id,
					'post_title' => $title,
				)
			);
			$resolved = self::resolve_vendor_category_label( $title );
			if ( ! is_wp_error( $resolved ) && '' !== $resolved ) {
				wp_set_object_terms( $opp_id, array( $resolved ), 'lnkup_vendor_category' );
			}
		}

		$indexed = true;
		if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
			$synced  = LNKup_Marketplace_Index::sync_opportunity( $opp_id );
			$indexed = ! is_wp_error( $synced );
		}

		return array(
			'message'         => __( 'Vendor fee and spots updated.', 'lnkup-marketplace' ),
			'opportunity_id'  => $opp_id,
			'event_id'        => $event_id,
			'vendor_fee'      => $fee,
			'spots_total'     => $spots,
			'spots_remaining' => (int) get_post_meta( $opp_id, '_lnkup_spots_remaining', true ),
			'indexed'         => $indexed,
		);
	}

	/**
	 * Trash a vendor slot that has no applications.
	 *
	 * @param int $opp_id       Opportunity ID.
	 * @param int $organizer_id Organizer user ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_opportunity_for_event( int $opp_id, int $organizer_id ) {
		$opp = get_post( $opp_id );
		if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type ) {
			return new WP_Error( 'invalid_opportunity', __( 'Invalid vendor slot.', 'lnkup-marketplace' ) );
		}

		$event_id   = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		$owns_event = $event_id > 0 && LNKup_Capabilities::user_owns_post( $event_id, $organizer_id, '_lnkup_organizer_user_id' );
		$owns_opp   = LNKup_Capabilities::user_owns_post( $opp_id, $organizer_id, '_lnkup_organizer_user_id' );
		if ( ! $owns_event && ! $owns_opp && ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot remove this vendor slot.', 'lnkup-marketplace' ) );
		}

		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_opportunity_id',
				'meta_value'     => $opp_id,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			)
		);
		if ( $apps ) {
			return new WP_Error(
				'has_applications',
				__( 'This slot has vendor requests. You can change the price or spots, but not remove it.', 'lnkup-marketplace' )
			);
		}

		$trashed = wp_trash_post( $opp_id );
		if ( ! $trashed ) {
			return new WP_Error( 'delete_failed', __( 'Could not remove this vendor slot.', 'lnkup-marketplace' ) );
		}

		if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
			LNKup_Marketplace_Index::delete_row( $opp_id );
		}
		if ( $event_id > 0 ) {
			self::sync_event_listing_status( $event_id );
		}

		return array(
			'message'        => __( 'Vendor slot removed.', 'lnkup-marketplace' ),
			'opportunity_id' => $opp_id,
			'event_id'       => $event_id,
		);
	}

	/**
	 * Open or close a vendor slot. Closing keeps applications and hides the slot from Find Events.
	 *
	 * @param int    $opp_id       Opportunity ID.
	 * @param string $status       open|closed.
	 * @param int    $organizer_id Organizer user ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_opportunity_listing_status( int $opp_id, string $status, int $organizer_id ) {
		$opp = get_post( $opp_id );
		if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type ) {
			return new WP_Error( 'invalid_opportunity', __( 'Invalid vendor slot.', 'lnkup-marketplace' ) );
		}
		if ( ! in_array( $status, array( 'open', 'closed' ), true ) ) {
			return new WP_Error( 'invalid_status', __( 'Choose open or closed.', 'lnkup-marketplace' ) );
		}

		$event_id   = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		$owns_event = $event_id > 0 && LNKup_Capabilities::user_owns_post( $event_id, $organizer_id, '_lnkup_organizer_user_id' );
		$owns_opp   = LNKup_Capabilities::user_owns_post( $opp_id, $organizer_id, '_lnkup_organizer_user_id' );
		if ( ! $owns_event && ! $owns_opp && ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot change this vendor slot.', 'lnkup-marketplace' ) );
		}

		update_post_meta( $opp_id, '_lnkup_opportunity_status', $status );
		if ( 'closed' === $status && class_exists( 'LNKup_Marketplace_Index' ) ) {
			LNKup_Marketplace_Index::delete_row( $opp_id );
		} elseif ( class_exists( 'LNKup_Marketplace_Index' ) ) {
			LNKup_Marketplace_Index::sync_opportunity( $opp_id );
		}
		if ( $event_id > 0 ) {
			self::sync_event_listing_status( $event_id );
		}

		return array(
			'message'        => 'closed' === $status
				? __( 'Vendor slot closed. Existing requests stay.', 'lnkup-marketplace' )
				: __( 'Vendor slot reopened.', 'lnkup-marketplace' ),
			'opportunity_id' => $opp_id,
			'event_id'       => $event_id,
			'status'         => $status,
		);
	}

	/**
	 * Update organizer-owned event listing fields after create.
	 *
	 * @param int                  $event_id     Event post ID.
	 * @param array<string, mixed> $input        PATCH-style input.
	 * @param int                  $organizer_id Organizer user ID.
	 * @return array<string, mixed>|WP_Error
	 */
	/**
	 * Fields vendors plan around; a change after booking must reach them.
	 *
	 * @return array<string, string> meta key => label.
	 */
	private static function logistics_fields(): array {
		return array(
			'_lnkup_event_date'    => __( 'date', 'lnkup-marketplace' ),
			'_lnkup_start_time'    => __( 'start time', 'lnkup-marketplace' ),
			'_lnkup_end_time'      => __( 'end time', 'lnkup-marketplace' ),
			'_lnkup_venue_name'    => __( 'venue', 'lnkup-marketplace' ),
			'_lnkup_venue_address' => __( 'address', 'lnkup-marketplace' ),
			'_lnkup_city'          => __( 'city', 'lnkup-marketplace' ),
		);
	}

	/**
	 * @param int $event_id Event ID.
	 * @return array<string, string>
	 */
	private static function logistics_snapshot( int $event_id ): array {
		$snapshot = array();
		foreach ( array_keys( self::logistics_fields() ) as $key ) {
			$snapshot[ $key ] = trim( (string) get_post_meta( $event_id, $key, true ) );
		}
		return $snapshot;
	}

	/**
	 * After an edit: re-sync listings and notify booked vendors when logistics changed.
	 * Fees and payments are never touched here.
	 *
	 * @param int                   $event_id Event ID.
	 * @param array<string, string> $before   Snapshot taken before the edit.
	 * @return int Vendors notified.
	 */
	private static function handle_logistics_change( int $event_id, array $before ): int {
		$after   = self::logistics_snapshot( $event_id );
		$labels  = self::logistics_fields();
		$changed = array();
		foreach ( $before as $key => $value ) {
			if ( $value !== $after[ $key ] ) {
				$changed[] = $labels[ $key ];
			}
		}
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $opps as $opp_id ) {
			LNKup_Marketplace_Index::sync_opportunity( (int) $opp_id );
		}
		if ( ! $changed ) {
			return 0;
		}
		do_action( 'lnkup_event_logistics_changed', $event_id, $changed );
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_event_id', 'value' => (string) $event_id ),
					array( 'key' => '_lnkup_application_status', 'value' => array( 'approved', 'payment_required', 'paid', 'completed' ), 'compare' => 'IN' ),
				),
			)
		);
		foreach ( $apps as $app_id ) {
			LNKup_Notifications::send_event_logistics_changed( (int) $app_id, $changed );
		}
		if ( $apps && class_exists( 'LNKup_Operations_Log' ) ) {
			LNKup_Operations_Log::log( $event_id, 'logistics_changed', array( 'fields' => $changed, 'notified' => count( $apps ) ) );
		}
		return count( $apps );
	}

	public static function update_event_from_request( int $event_id, array $input, int $organizer_id ) {
		$event = get_post( $event_id );
		if ( ! $event || 'lnkup_event' !== $event->post_type ) {
			return new WP_Error( 'invalid_event', __( 'Invalid event.', 'lnkup-marketplace' ) );
		}
		if ( ! LNKup_Capabilities::user_owns_post( $event_id, $organizer_id, '_lnkup_organizer_user_id' )
			&& ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot edit this event.', 'lnkup-marketplace' ) );
		}

		$logistics_before = self::logistics_snapshot( $event_id );
		$update           = array( 'ID' => $event_id );
		if ( isset( $input['event_name'] ) || isset( $input['title'] ) ) {
			$name = isset( $input['event_name'] )
				? sanitize_text_field( (string) $input['event_name'] )
				: sanitize_text_field( (string) $input['title'] );
			if ( '' === $name ) {
				return new WP_Error( 'missing_name', __( 'Event name is required.', 'lnkup-marketplace' ) );
			}
			$update['post_title'] = $name;
		}
		if ( array_key_exists( 'description', $input ) ) {
			$update['post_content'] = sanitize_textarea_field( (string) $input['description'] );
		}
		if ( count( $update ) > 1 ) {
			$saved = wp_update_post( $update, true );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		$meta_fields = array( 'event_date', 'start_time', 'end_time', 'city', 'state', 'venue_name', 'venue_address', 'expected_attendance', 'ticketing_platform', 'ticketing_url' );
		foreach ( $meta_fields as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$val = 'ticketing_url' === $field ? esc_url_raw( (string) $input[ $field ] ) : sanitize_text_field( (string) $input[ $field ] );
			update_post_meta( $event_id, '_lnkup_' . $field, $val );
		}
		if ( ! empty( $input['ticketing_url'] ) ) {
			update_post_meta( $event_id, '_lnkup_external_source_url', esc_url_raw( (string) $input['ticketing_url'] ) );
		}
		if ( array_key_exists( 'venue_hidden', $input ) ) {
			update_post_meta( $event_id, '_lnkup_venue_hidden', ! empty( $input['venue_hidden'] ) );
		}
		$flag_fields = array( 'indoor', 'outdoor', 'power_available', 'water_available', 'food_truck_allowed', 'tent_allowed', 'generator_allowed', 'free_vendor_parking' );
		foreach ( $flag_fields as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				update_post_meta( $event_id, '_lnkup_' . $flag, ! empty( $input[ $flag ] ) );
			}
		}

		$gallery_raw = $input['gallery_ids'] ?? $input['gallery'] ?? null;
		if ( null !== $gallery_raw || ! empty( $input['featured_image_id'] ) ) {
			lnkup_save_post_gallery( $event_id, $gallery_raw ?? '' );
			self::save_event_featured_image( $event_id, $input['featured_image_id'] ?? 0 );
		}

		if ( ! empty( $input['event_type'] ) ) {
			wp_set_object_terms( $event_id, sanitize_text_field( (string) $input['event_type'] ), 'lnkup_event_type' );
		}

		if ( class_exists( 'LNKup_Geocoder' ) ) {
			LNKup_Geocoder::geocode_event( $event_id );
		}

		$notified = self::handle_logistics_change( $event_id, $logistics_before );
		$dash_url = lnkup_page_url_helper( 'organizer-dashboard' );

		return array(
			'message'        => $notified
				/* translators: %d: number of vendors */
				? sprintf( _n( 'Event updated. %d booked vendor was notified of the change.', 'Event updated. %d booked vendors were notified of the change.', $notified, 'lnkup-marketplace' ), $notified )
				: __( 'Event updated.', 'lnkup-marketplace' ),
			'vendors_notified' => $notified,
			'event_id'       => $event_id,
			'event_title'    => get_the_title( $event_id ),
			'event_date'     => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
			'event_city'     => (string) get_post_meta( $event_id, '_lnkup_city', true ),
			'view_event_url' => add_query_arg( 'event', (int) $event_id, $dash_url ),
		);
	}

	/**
	 * Normalize fee rows into opportunity create inputs (one booth price per vendor type).
	 *
	 * Accepts `opportunities` array, or legacy single `vendor_fee` + `categories`,
	 * or parallel POST arrays `fee_category[]` / `fee_amount[]` / `fee_spots[]`.
	 *
	 * @param array<string, mixed> $input Request input.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function normalize_opportunity_rows( array $input ) {
		$rows = array();

		if ( ! empty( $input['opportunities'] ) && is_array( $input['opportunities'] ) ) {
			foreach ( $input['opportunities'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$rows[] = $row;
			}
		} elseif (
			( ! empty( $input['fee_category'] ) && is_array( $input['fee_category'] ) )
			|| ( ! empty( $input['fee_custom'] ) && is_array( $input['fee_custom'] ) )
		) {
			$cats    = isset( $input['fee_category'] ) && is_array( $input['fee_category'] ) ? $input['fee_category'] : array();
			$customs = isset( $input['fee_custom'] ) && is_array( $input['fee_custom'] ) ? $input['fee_custom'] : array();
			$fees    = isset( $input['fee_amount'] ) && is_array( $input['fee_amount'] ) ? $input['fee_amount'] : array();
			$spots   = isset( $input['fee_spots'] ) && is_array( $input['fee_spots'] ) ? $input['fee_spots'] : array();
			$count   = max( count( $cats ), count( $customs ) );
			for ( $i = 0; $i < $count; $i++ ) {
				$custom = isset( $customs[ $i ] ) ? sanitize_text_field( (string) $customs[ $i ] ) : '';
				$cat    = '' !== $custom ? $custom : ( isset( $cats[ $i ] ) ? sanitize_text_field( (string) $cats[ $i ] ) : '' );
				if ( '' === $cat ) {
					continue;
				}
				$rows[] = array(
					'categories'        => array( $cat ),
					'vendor_category'   => $cat,
					'vendor_fee'        => $fees[ $i ] ?? 250,
					'spots_total'       => $spots[ $i ] ?? 10,
					'opportunity_title' => $cat,
				);
			}
		} elseif ( isset( $input['vendor_fee'] ) || isset( $input['spots_total'] ) || ! empty( $input['categories'] ) || ! empty( $input['vendor_categories'] ) ) {
			$rows[] = $input;
		}

		if ( empty( $rows ) ) {
			return new WP_Error(
				'missing_fee_rows',
				__( 'Add at least one vendor type with a booth price.', 'lnkup-marketplace' )
			);
		}

		$normalized = array();
		foreach ( $rows as $row ) {
			$categories = array();
			if ( ! empty( $row['categories'] ) && is_array( $row['categories'] ) ) {
				$categories = array_values( array_filter( array_map( 'sanitize_text_field', $row['categories'] ) ) );
			} elseif ( ! empty( $row['vendor_categories'] ) && is_array( $row['vendor_categories'] ) ) {
				$categories = array_values( array_filter( array_map( 'sanitize_text_field', $row['vendor_categories'] ) ) );
			} elseif ( ! empty( $row['vendor_category'] ) || ! empty( $row['category'] ) ) {
				$cat = sanitize_text_field( (string) ( $row['vendor_category'] ?? $row['category'] ) );
				if ( '' !== $cat ) {
					$categories = array( $cat );
				}
			}

			$resolved_categories = array();
			foreach ( $categories as $category_label ) {
				$resolved = self::resolve_vendor_category_label( (string) $category_label );
				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}
				if ( '' !== $resolved ) {
					$resolved_categories[] = $resolved;
				}
			}
			$categories = $resolved_categories;
			if ( empty( $categories ) && ( isset( $row['vendor_category'] ) || isset( $row['category'] ) || isset( $row['categories'] ) || isset( $row['vendor_categories'] ) ) ) {
				return new WP_Error( 'invalid_category', __( 'Choose a vendor type or enter a custom type.', 'lnkup-marketplace' ) );
			}

			$fee = isset( $row['vendor_fee'] ) ? LNKup_Security::sanitize_money( $row['vendor_fee'] ) : 250.0;
			if ( $fee < 0 ) {
				return new WP_Error( 'invalid_fee', __( 'Vendor fee cannot be negative.', 'lnkup-marketplace' ) );
			}
			$spots = isset( $row['spots_total'] ) ? absint( $row['spots_total'] ) : 10;
			if ( $spots < 1 ) {
				return new WP_Error( 'invalid_spots', __( 'At least one vendor spot is required.', 'lnkup-marketplace' ) );
			}

			$title = isset( $row['opportunity_title'] ) ? sanitize_text_field( (string) $row['opportunity_title'] ) : '';
			if ( ( '' === $title || ( ! empty( $row['vendor_category'] ) && $title === sanitize_text_field( (string) $row['vendor_category'] ) ) ) && ! empty( $categories[0] ) ) {
				$title = $categories[0];
			}
			if ( '' === $title && ! empty( $categories[0] ) ) {
				$title = $categories[0];
			}

			$item = $row;
			$item['categories']        = $categories;
			$item['vendor_fee']        = $fee;
			$item['spots_total']       = $spots;
			if ( '' !== $title ) {
				$item['opportunity_title'] = $title;
			}
			$normalized[] = $item;
		}

		return $normalized;
	}

	/**
	 * Create one or more priced opportunities for an event (per vendor-type booth prices).
	 *
	 * @param int                  $event_id     Event post ID.
	 * @param array<string, mixed> $input        Request with opportunities / fee rows / legacy single fee.
	 * @param int                  $organizer_id Organizer user ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_opportunities_for_event( int $event_id, array $input, int $organizer_id ) {
		if ( class_exists( 'LNKup_Organizer_Setups' ) ) {
			$input = LNKup_Organizer_Setups::apply_to_input( $input, $organizer_id );
			if ( is_wp_error( $input ) ) {
				return $input;
			}
		}
		$rows = self::normalize_opportunity_rows( $input );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$created = array();
		$first   = null;
		foreach ( $rows as $index => $row ) {
			// Shared event-level fields from parent input when missing on row.
			foreach ( array( 'application_deadline', 'indoor', 'outdoor', 'environment', 'document_mode', 'required_documents', 'organizer_notes', 'deposit_required', 'packet_lock' ) as $shared ) {
				if ( ! array_key_exists( $shared, $row ) && array_key_exists( $shared, $input ) ) {
					$row[ $shared ] = $input[ $shared ];
				}
			}
			if ( ! empty( $input['client_token'] ) && empty( $row['client_token'] ) ) {
				$row['client_token'] = sanitize_text_field( (string) $input['client_token'] ) . '_r' . $index;
			}

			$result = self::create_opportunity_for_event( $event_id, $row, $organizer_id );
			if ( is_wp_error( $result ) ) {
				if ( $created ) {
					$result->add_data(
						array(
							'status'                => 500,
							'created_opportunities' => $created,
							'failed_row'            => (int) $index,
						)
					);
				}
				return $result;
			}
			$created[] = array(
				'opportunity_id' => (int) ( $result['opportunity_id'] ?? 0 ),
				'vendor_fee'     => (float) ( $result['vendor_fee'] ?? 0 ),
				'spots_total'    => (int) ( $result['spots_total'] ?? 0 ),
				'title'          => (string) ( $result['opportunity_title'] ?? get_the_title( (int) ( $result['opportunity_id'] ?? 0 ) ) ),
			);
			if ( null === $first ) {
				$first = $result;
			}
		}

		if ( class_exists( 'LNKup_Organizer_Setups' ) ) {
			LNKup_Organizer_Setups::after_create( $rows, $created );
		}

		$payload = is_array( $first ) ? $first : array();
		$payload['opportunities']       = $created;
		$payload['opportunity_ids']     = array_map(
			static function ( $row ) {
				return (int) $row['opportunity_id'];
			},
			$created
		);
		$payload['opportunity_id']       = (int) ( $created[0]['opportunity_id'] ?? 0 );
		$payload['message']             = count( $created ) > 1
			? sprintf(
				/* translators: %d: number of vendor slots */
				__( 'Added %d vendor slots.', 'lnkup-marketplace' ),
				count( $created )
			)
			: ( $payload['message'] ?? __( 'Vendor slot added. Vendors can apply in Find Events.', 'lnkup-marketplace' ) );

		return $payload;
	}

	/**
	 * Duplicate an organizer-owned event and its vendor slots.
	 *
	 * Copies listing details, slot fees, documents, and the venue map.
	 * Does not copy applications, payments, roster, or ops booth assignments.
	 *
	 * @param int                  $source_event_id Source event ID.
	 * @param array<string, mixed> $input           event_name and event_date.
	 * @param int                  $organizer_id    Organizer user ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function duplicate_event( int $source_event_id, array $input, int $organizer_id ) {
		$source = get_post( $source_event_id );
		if ( ! $source || 'lnkup_event' !== $source->post_type ) {
			return new WP_Error( 'invalid_event', __( 'Invalid event.', 'lnkup-marketplace' ) );
		}
		if ( ! LNKup_Capabilities::user_owns_post( $source_event_id, $organizer_id, '_lnkup_organizer_user_id' )
			&& ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot duplicate this event.', 'lnkup-marketplace' ) );
		}

		$name = isset( $input['event_name'] ) ? sanitize_text_field( (string) $input['event_name'] ) : '';
		if ( '' === $name ) {
			$name = $source->post_title . ' ' . __( '(copy)', 'lnkup-marketplace' );
		}
		$date = isset( $input['event_date'] ) ? sanitize_text_field( (string) $input['event_date'] ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'missing_date', __( 'Choose a date for the duplicated event.', 'lnkup-marketplace' ) );
		}

		$client_token = isset( $input['client_token'] ) ? sanitize_text_field( (string) $input['client_token'] ) : '';
		if ( '' !== $client_token ) {
			global $wpdb;
			$lock = 'lnkup_dup_' . $organizer_id . '_' . md5( $client_token );
			if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new WP_Error( 'busy', __( 'Your copy is still being created. Refresh in a moment.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
			}
			try {
				$existing = self::event_for_client_token( $organizer_id, $client_token );
				if ( $existing ) {
					return array(
						'message'        => __( 'This event was already duplicated.', 'lnkup-marketplace' ),
						'event_id'       => $existing,
						'event_title'    => get_the_title( $existing ),
						'event_date'     => (string) get_post_meta( $existing, '_lnkup_event_date', true ),
						'idempotent'     => true,
						'view_event_url' => add_query_arg( 'event', $existing, lnkup_page_url_helper( 'organizer-dashboard' ) ),
					);
				}
				return self::insert_duplicate_event( $source, $name, $date, $input, $organizer_id, $client_token );
			} finally {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		return self::insert_duplicate_event( $source, $name, $date, $input, $organizer_id, '' );
	}

	/**
	 * Create the copy. Never copies applications, payments, check-ins, packets, or QR passes.
	 *
	 * @param WP_Post              $source       Source event.
	 * @param string               $name         New title.
	 * @param string               $date         New date (Y-m-d).
	 * @param array<string, mixed> $input        Venue overrides.
	 * @param int                  $organizer_id Organizer user ID.
	 * @param string               $client_token Submission token ('' = none).
	 * @return array<string, mixed>|WP_Error
	 */
	private static function insert_duplicate_event( WP_Post $source, string $name, string $date, array $input, int $organizer_id, string $client_token ) {
		$source_event_id = (int) $source->ID;
		$new_id          = wp_insert_post(
			array(
				'post_title'   => $name,
				'post_type'    => 'lnkup_event',
				'post_status'  => 'publish',
				'post_content' => $source->post_content,
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		$new_id = (int) $new_id;

		foreach ( self::event_meta_keys_to_copy() as $meta_key ) {
			$value = get_post_meta( $source_event_id, $meta_key, true );
			if ( '' === $value || null === $value || array() === $value ) {
				continue;
			}
			update_post_meta( $new_id, $meta_key, $value );
		}
		update_post_meta( $new_id, '_lnkup_organizer_user_id', $organizer_id );
		update_post_meta( $new_id, '_lnkup_event_date', $date );
		update_post_meta( $new_id, '_lnkup_status', 'published' );
		update_post_meta( $new_id, '_lnkup_duplicated_from', $source_event_id );
		if ( '' !== $client_token ) {
			update_post_meta( $new_id, '_lnkup_client_token_hash', md5( $client_token ) );
		}
		$venue_overrides = array(
			'city'          => '_lnkup_city',
			'state'         => '_lnkup_state',
			'venue_name'    => '_lnkup_venue_name',
			'venue_address' => '_lnkup_venue_address',
		);
		foreach ( $venue_overrides as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$value = 'venue_address' === $field
				? sanitize_textarea_field( (string) $input[ $field ] )
				: sanitize_text_field( (string) $input[ $field ] );
			update_post_meta( $new_id, $meta_key, $value );
		}

		foreach ( array( 'lnkup_event_type', 'lnkup_market' ) as $taxonomy ) {
			$term_ids = wp_get_object_terms( $source_event_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $term_ids ) && $term_ids ) {
				wp_set_object_terms( $new_id, $term_ids, $taxonomy );
			}
		}

		if ( class_exists( 'LNKup_Geocoder' ) ) {
			LNKup_Geocoder::geocode_event( $new_id );
		}

		$opportunity_ids = self::duplicate_event_opportunities( $source_event_id, $new_id, $organizer_id );
		self::sync_event_listing_status( $new_id );

		if ( class_exists( 'LNKup_Maps' ) ) {
			$map_copy = LNKup_Maps::duplicate_from_event( $source_event_id, $new_id );
			if ( is_wp_error( $map_copy ) && 'no_map' !== $map_copy->get_error_code() && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup duplicate_event map: ' . $map_copy->get_error_message() );
			}
		}

		try {
			if ( class_exists( 'LNKup_Event_Operations' ) ) {
				LNKup_Event_Operations::bootstrap_from_event( $new_id );
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'LNKup duplicate_event bootstrap: ' . $e->getMessage() );
			}
		}

		$dash_url = lnkup_page_url_helper( 'organizer-dashboard' );

		return array(
			'message'         => __( 'Event duplicated with its vendor slots. Applications and payments were not copied.', 'lnkup-marketplace' ),
			'event_id'        => $new_id,
			'event_title'     => $name,
			'event_date'      => $date,
			'opportunity_ids' => $opportunity_ids,
			'view_event_url'  => add_query_arg( 'event', (int) $new_id, $dash_url ),
		);
	}

	/**
	 * Live when the event has an open vendor slot; otherwise draft.
	 *
	 * @param int $event_id Event post ID.
	 * @return string live|draft
	 */
	public static function sync_event_listing_status( int $event_id ): string {
		$current = (string) get_post_meta( $event_id, '_lnkup_event_status', true );
		if ( class_exists( 'LNKup_Event_Status' ) && in_array( $current, LNKup_Event_Status::TERMINAL, true ) ) {
			return $current;
		}
		$status = self::event_has_open_slot( $event_id ) ? 'live' : 'draft';
		if ( $current !== $status ) {
			update_post_meta( $event_id, '_lnkup_event_status', $status );
		}
		return $status;
	}

	/**
	 * Whether the event has at least one open vendor slot.
	 *
	 * @param int $event_id Event post ID.
	 */
	public static function event_has_open_slot( int $event_id ): bool {
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		foreach ( $opps as $opp_id ) {
			$slot_status = (string) get_post_meta( (int) $opp_id, '_lnkup_opportunity_status', true );
			if ( '' === $slot_status || 'open' === $slot_status ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Public availability for an event, from its vendor slots.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{key: string, label: string}
	 */
	public static function event_public_availability( int $event_id ): array {
		$date = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		if ( $date && $date < current_time( 'Y-m-d' ) ) {
			return array(
				'key'   => 'completed',
				'label' => __( 'Event completed', 'lnkup-marketplace' ),
			);
		}

		$open_with_spots = 0;
		$low             = true;
		$any_open        = false;
		foreach ( self::event_slot_posts( $event_id ) as $opp ) {
			$status = (string) get_post_meta( $opp->ID, '_lnkup_opportunity_status', true );
			if ( '' !== $status && 'open' !== $status ) {
				continue;
			}
			$any_open = true;
			$deadline = (string) get_post_meta( $opp->ID, '_lnkup_application_deadline', true );
			if ( $deadline && $deadline < current_time( 'Y-m-d' ) ) {
				continue;
			}
			$remaining = (int) get_post_meta( $opp->ID, '_lnkup_spots_remaining', true );
			if ( $remaining > 0 ) {
				$open_with_spots++;
				if ( $remaining > 3 ) {
					$low = false;
				}
			}
		}

		if ( ! $any_open ) {
			return array(
				'key'   => 'closed',
				'label' => __( 'Applications closed', 'lnkup-marketplace' ),
			);
		}
		if ( $open_with_spots < 1 ) {
			return array(
				'key'   => 'full',
				'label' => __( 'Vendor opportunities full', 'lnkup-marketplace' ),
			);
		}
		if ( $low ) {
			return array(
				'key'   => 'limited',
				'label' => __( 'Limited availability', 'lnkup-marketplace' ),
			);
		}
		return array(
			'key'   => 'accepting',
			'label' => __( 'Accepting vendors', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Vendor-facing amenity lines. Flags mean available or allowed, not included.
	 *
	 * @param int $event_id Event post ID.
	 * @return array<int, string>
	 */
	public static function event_public_amenities( int $event_id ): array {
		$map = array(
			'_lnkup_power_available'     => __( 'Power available', 'lnkup-marketplace' ),
			'_lnkup_water_available'     => __( 'Water available', 'lnkup-marketplace' ),
			'_lnkup_food_truck_allowed'  => __( 'Food trucks allowed', 'lnkup-marketplace' ),
			'_lnkup_tent_allowed'        => __( 'Tents allowed', 'lnkup-marketplace' ),
			'_lnkup_generator_allowed'   => __( 'Generators allowed', 'lnkup-marketplace' ),
			'_lnkup_free_vendor_parking' => __( 'Free vendor parking', 'lnkup-marketplace' ),
		);
		$lines = array();
		foreach ( $map as $key => $label ) {
			if ( ! empty( get_post_meta( $event_id, $key, true ) ) ) {
				$lines[] = $label;
			}
		}
		return $lines;
	}

	/**
	 * Location fields safe to print on a public page.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{where: string, venue: string, address: string, hidden: bool, privacy: string}
	 */
	public static function event_public_location( int $event_id ): array {
		$city    = (string) get_post_meta( $event_id, '_lnkup_city', true );
		$state   = (string) get_post_meta( $event_id, '_lnkup_state', true );
		$hidden  = (bool) get_post_meta( $event_id, '_lnkup_venue_hidden', true );
		$venue   = $hidden ? '' : (string) get_post_meta( $event_id, '_lnkup_venue_name', true );
		$address = $hidden ? '' : (string) get_post_meta( $event_id, '_lnkup_venue_address', true );
		return array(
			'where'   => function_exists( 'lnkup_display_place' ) ? lnkup_display_place( $city, $state ) : trim( $city . ( $state ? ', ' . $state : '' ) ),
			'venue'   => $venue,
			'address' => $address,
			'hidden'  => $hidden,
			'privacy' => $hidden ? __( 'Exact location is shared with approved vendors.', 'lnkup-marketplace' ) : '',
		);
	}

	/**
	 * Document labels required for one vendor slot.
	 *
	 * @param int $opp_id Opportunity ID.
	 * @return array<int, string>
	 */
	public static function slot_public_documents( int $opp_id ): array {
		if ( ! function_exists( 'lnkup_get_opportunity_required_documents' ) ) {
			return array();
		}
		$types  = class_exists( 'LNKup_Documents' ) ? LNKup_Documents::document_types() : array();
		$labels = array();
		foreach ( lnkup_get_opportunity_required_documents( $opp_id ) as $slug ) {
			$labels[] = isset( $types[ $slug ] ) ? (string) $types[ $slug ] : $slug;
		}
		return $labels;
	}

	/**
	 * Whether the public page may call this organizer verified.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function organizer_is_publicly_verified( int $user_id ): bool {
		if ( $user_id < 1 || ! class_exists( 'LNKup_Verification' ) ) {
			return false;
		}
		$status = LNKup_Verification::get_organizer_verification_status( $user_id, array( 'public' => true ) );
		return ! empty( $status['verified'] );
	}

	/**
	 * Imported listing that is not yet an organizer-owned event.
	 *
	 * @param int $event_id Event post ID.
	 */
	public static function event_is_unclaimed_external( int $event_id ): bool {
		$external = (int) get_post_meta( $event_id, '_lnkup_external_event_id', true );
		$owner    = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		return $external > 0 && $owner < 1;
	}

	/**
	 * Slot posts for an event.
	 *
	 * @param int $event_id Event post ID.
	 * @return array<int, WP_Post>
	 */
	public static function event_slot_posts( int $event_id ): array {
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * Save public profile fields. Display name changes do not rewrite the slug.
	 *
	 * @param int                  $user_id Organizer user ID.
	 * @param array<string, mixed> $input   Request fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_public_profile( int $user_id, array $input ) {
		$name = isset( $input['public_name'] ) ? sanitize_text_field( (string) $input['public_name'] ) : '';
		if ( strlen( $name ) > 120 ) {
			return new WP_Error( 'name_too_long', __( 'Organization name must be 120 characters or fewer.', 'lnkup-marketplace' ) );
		}
		update_user_meta( $user_id, '_lnkup_organizer_public_name', $name );
		update_user_meta( $user_id, '_lnkup_organizer_bio', isset( $input['bio'] ) ? sanitize_textarea_field( (string) $input['bio'] ) : '' );
		update_user_meta( $user_id, '_lnkup_organizer_tagline', isset( $input['tagline'] ) ? sanitize_text_field( (string) $input['tagline'] ) : '' );
		update_user_meta( $user_id, '_lnkup_organizer_city', isset( $input['city'] ) ? sanitize_text_field( (string) $input['city'] ) : '' );
		update_user_meta( $user_id, '_lnkup_organizer_state', isset( $input['state'] ) ? sanitize_text_field( (string) $input['state'] ) : '' );
		update_user_meta( $user_id, '_lnkup_organizer_service_cities', isset( $input['service_cities'] ) ? sanitize_text_field( (string) $input['service_cities'] ) : '' );
		update_user_meta( $user_id, '_lnkup_organizer_website', isset( $input['website'] ) ? esc_url_raw( (string) $input['website'] ) : '' );
		update_user_meta( $user_id, '_lnkup_organizer_instagram', isset( $input['instagram'] ) ? sanitize_text_field( (string) $input['instagram'] ) : '' );
		update_user_meta( $user_id, '_lnkup_organizer_tiktok', isset( $input['tiktok'] ) ? sanitize_text_field( (string) $input['tiktok'] ) : '' );
		self::save_profile_image( $user_id, '_lnkup_organizer_logo_id', isset( $input['logo_id'] ) ? absint( $input['logo_id'] ) : 0 );
		self::save_profile_image( $user_id, '_lnkup_organizer_cover_id', isset( $input['cover_id'] ) ? absint( $input['cover_id'] ) : 0 );

		if ( isset( $input['public_slug'] ) && '' !== trim( (string) $input['public_slug'] ) ) {
			$slug_result = self::set_organizer_public_slug( $user_id, (string) $input['public_slug'] );
			if ( is_wp_error( $slug_result ) ) {
				return $slug_result;
			}
		}

		return array(
			'message' => __( 'Public profile updated.', 'lnkup-marketplace' ),
			'url'     => self::organizer_public_url( $user_id ),
		);
	}

	/**
	 * Store a previous slug, then save a unique new one.
	 *
	 * @param int    $user_id Organizer user ID.
	 * @param string $slug    Requested slug.
	 * @return true|WP_Error
	 */
	public static function set_organizer_public_slug( int $user_id, string $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_slug', __( 'Choose a public profile link.', 'lnkup-marketplace' ) );
		}
		if ( self::organizer_slug_taken( $slug, $user_id ) || self::organizer_alias_taken( $slug, $user_id ) ) {
			return new WP_Error( 'slug_taken', __( 'That profile link is already in use.', 'lnkup-marketplace' ) );
		}
		$current = sanitize_title( (string) get_user_meta( $user_id, '_lnkup_organizer_public_slug', true ) );
		if ( $current === $slug ) {
			return true;
		}
		if ( '' !== $current ) {
			add_user_meta( $user_id, '_lnkup_organizer_slug_alias', $current, false );
		}
		update_user_meta( $user_id, '_lnkup_organizer_public_slug', $slug );
		return true;
	}

	/**
	 * Public page for one event: details and the vendor slots for sale.
	 *
	 * @param int $event_id Event post ID.
	 */
	public static function event_public_url( int $event_id ): string {
		$permalink = get_permalink( $event_id );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			return $permalink;
		}
		$base = function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'opportunities' ) : home_url( '/opportunities/' );
		return add_query_arg( 'event', $event_id, $base );
	}

	/**
	 * Public apply URLs for open vendor slots on an event.
	 *
	 * @param int $event_id Event post ID.
	 * @return array<int, array{id: int, title: string, url: string}>
	 */
	public static function event_share_links( int $event_id ): array {
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		$base  = function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'opportunities' ) : home_url( '/opportunities/' );
		$links = array();
		foreach ( $opps as $opp ) {
			$slot_status = (string) get_post_meta( $opp->ID, '_lnkup_opportunity_status', true );
			if ( '' !== $slot_status && 'open' !== $slot_status ) {
				continue;
			}
			$links[] = array(
				'id'    => (int) $opp->ID,
				'title' => $opp->post_title,
				'url'   => add_query_arg( 'apply', (int) $opp->ID, $base ),
			);
		}
		return $links;
	}

	/**
	 * Public profile URL for an organizer.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function organizer_public_url( int $user_id ): string {
		self::ensure_organizers_page();
		$base = function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'organizers' ) : home_url( '/organizers/' );
		return add_query_arg( 'organizer', self::organizer_public_slug( $user_id ), $base );
	}

	/**
	 * Stable public slug stored on the user.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function organizer_public_slug( int $user_id ): string {
		$slug = sanitize_title( (string) get_user_meta( $user_id, '_lnkup_organizer_public_slug', true ) );
		if ( '' !== $slug && ! self::organizer_slug_taken( $slug, $user_id ) ) {
			return $slug;
		}
		$user = get_userdata( $user_id );
		$base = $user ? sanitize_title( $user->display_name ) : '';
		if ( '' === $base ) {
			$base = 'organizer';
		}
		$candidate = $base;
		$suffix    = 2;
		while ( self::organizer_slug_taken( $candidate, $user_id ) ) {
			$candidate = $base . '-' . $suffix;
			$suffix++;
			if ( $suffix > 50 ) {
				$candidate = $base . '-' . $user_id;
				break;
			}
		}
		update_user_meta( $user_id, '_lnkup_organizer_public_slug', $candidate );
		return $candidate;
	}

	/**
	 * Resolve an organizer user from a public slug.
	 *
	 * @param string $slug Public slug.
	 */
	public static function organizer_id_from_public_slug( string $slug ): int {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return 0;
		}
		$users = get_users(
			array(
				'meta_key'   => '_lnkup_organizer_public_slug',
				'meta_value' => $slug,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		if ( $users ) {
			return (int) $users[0];
		}
		$aliases = get_users(
			array(
				'meta_key'   => '_lnkup_organizer_slug_alias',
				'meta_value' => $slug,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return $aliases ? (int) $aliases[0] : 0;
	}

	/**
	 * Whether another user already owns this public slug.
	 *
	 * @param string $slug    Slug.
	 * @param int    $user_id User allowed to keep it.
	 */
	private static function organizer_slug_taken( string $slug, int $user_id ): bool {
		$users = get_users(
			array(
				'meta_key'   => '_lnkup_organizer_public_slug',
				'meta_value' => $slug,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return $users && (int) $users[0] !== $user_id;
	}

	/**
	 * Whether another organizer already keeps this slug as an old link.
	 *
	 * @param string $slug    Slug.
	 * @param int    $user_id User allowed to keep it.
	 */
	private static function organizer_alias_taken( string $slug, int $user_id ): bool {
		$users = get_users(
			array(
				'meta_key'   => '_lnkup_organizer_slug_alias',
				'meta_value' => $slug,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return $users && (int) $users[0] !== $user_id;
	}

	/**
	 * Create the organizers page once if it is missing. Does not flush rewrites.
	 */
	private static function ensure_organizers_page(): void {
		if ( get_page_by_path( 'organizers' ) ) {
			return;
		}
		if ( class_exists( 'LNKup_Activator' ) ) {
			LNKup_Activator::create_pages();
		}
	}

	/**
	 * Save a logo or cover attachment, or clear it.
	 *
	 * @param int    $user_id Organizer user ID.
	 * @param string $meta_key User meta key.
	 * @param int    $attachment_id Attachment ID. 0 removes the image.
	 */
	/**
	 * Featured event image: must be an image the organizer uploaded, or the one already set.
	 *
	 * @param int   $event_id      Event ID.
	 * @param mixed $attachment_id Submitted attachment ID.
	 */
	private static function save_event_featured_image( int $event_id, $attachment_id ): void {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return;
		}
		$current = (int) get_post_meta( $event_id, '_lnkup_featured_image_id', true );
		$actor   = get_current_user_id();
		if ( $actor && ! LNKup_Security::filter_owned_images( array( $attachment_id ), $actor, array( $current ) ) ) {
			return;
		}
		update_post_meta( $event_id, '_lnkup_featured_image_id', $attachment_id );
	}

	private static function save_profile_image( int $user_id, string $meta_key, int $attachment_id ): void {
		if ( $attachment_id < 1 ) {
			delete_user_meta( $user_id, $meta_key );
			return;
		}
		$current = (int) get_user_meta( $user_id, $meta_key, true );
		if ( ! LNKup_Security::filter_owned_images( array( $attachment_id ), $user_id, array( $current ) ) ) {
			return;
		}
		update_user_meta( $user_id, $meta_key, $attachment_id );
	}

	/**
	 * Event meta copied onto a duplicate. Ops stage and aggregates stay behind.
	 *
	 * @return array<int, string>
	 */
	private static function event_meta_keys_to_copy(): array {
		return array(
			'_lnkup_start_time',
			'_lnkup_end_time',
			'_lnkup_city',
			'_lnkup_state',
			'_lnkup_venue_name',
			'_lnkup_venue_address',
			'_lnkup_venue_hidden',
			'_lnkup_expected_attendance',
			'_lnkup_ticketing_platform',
			'_lnkup_ticketing_url',
			'_lnkup_external_source_url',
			'_lnkup_lat',
			'_lnkup_lng',
			'_lnkup_indoor',
			'_lnkup_outdoor',
			'_lnkup_power_available',
			'_lnkup_water_available',
			'_lnkup_food_truck_allowed',
			'_lnkup_tent_allowed',
			'_lnkup_generator_allowed',
			'_lnkup_free_vendor_parking',
			'_lnkup_featured_image_id',
			'_lnkup_gallery_ids',
			'_lnkup_tags',
			'_lnkup_venue_map_attachment_id',
			'_lnkup_address_line1',
			'_lnkup_address_line2',
			'_lnkup_zip',
			'_lnkup_country',
			'_lnkup_neighborhood',
			'_lnkup_market',
			'_lnkup_region',
			'_lnkup_indoor_outdoor',
			'_lnkup_venue_type',
			'_lnkup_parking_notes',
			'_lnkup_loadin_entrance',
			'_lnkup_vendor_gate',
			'_lnkup_geocode_status',
			'_lnkup_geocode_manual_override',
			'_lnkup_emergency_contacts',
			'_lnkup_weather_placeholder',
		);
	}

	/**
	 * Clone vendor slots onto the new event with full spot counts.
	 *
	 * @param int $source_event_id Source event.
	 * @param int $new_event_id    Duplicate event.
	 * @param int $organizer_id    Organizer user ID.
	 * @return array<int, int>
	 */
	private static function duplicate_event_opportunities( int $source_event_id, int $new_event_id, int $organizer_id ): array {
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $source_event_id,
			)
		);

		$created = array();
		foreach ( $opps as $opp ) {
			$new_opp = wp_insert_post(
				array(
					'post_title'   => $opp->post_title,
					'post_type'    => 'lnkup_opportunity',
					'post_status'  => 'publish',
					'post_content' => $opp->post_content,
				),
				true
			);
			if ( is_wp_error( $new_opp ) ) {
				continue;
			}
			$new_opp = (int) $new_opp;

			foreach ( self::opportunity_meta_keys_to_copy() as $meta_key ) {
				$value = get_post_meta( $opp->ID, $meta_key, true );
				if ( '' === $value || null === $value || array() === $value ) {
					continue;
				}
				// Question definitions are JSON strings; re-slash so update_post_meta() keeps escapes.
				update_post_meta( $new_opp, $meta_key, '_lnkup_application_questions' === $meta_key && is_string( $value ) ? wp_slash( $value ) : $value );
			}

			$spots = (int) get_post_meta( $opp->ID, '_lnkup_spots_total', true );
			if ( $spots < 1 ) {
				$spots = 1;
			}
			update_post_meta( $new_opp, '_lnkup_event_id', $new_event_id );
			update_post_meta( $new_opp, '_lnkup_organizer_user_id', $organizer_id );
			update_post_meta( $new_opp, '_lnkup_spots_total', $spots );
			update_post_meta( $new_opp, '_lnkup_spots_remaining', $spots );
			update_post_meta( $new_opp, '_lnkup_opportunity_status', 'closed' === (string) get_post_meta( $opp->ID, '_lnkup_opportunity_status', true ) ? 'closed' : 'open' );
			if ( function_exists( 'lnkup_default_application_deadline' ) ) {
				update_post_meta( $new_opp, '_lnkup_application_deadline', lnkup_default_application_deadline( $new_event_id ) );
			}

			$terms = wp_get_object_terms( $opp->ID, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_set_object_terms( $new_opp, $terms, 'lnkup_vendor_category' );
			}
			$markets = wp_get_object_terms( $opp->ID, 'lnkup_market', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $markets ) && $markets ) {
				wp_set_object_terms( $new_opp, $markets, 'lnkup_market' );
			}

			try {
				LNKup_Marketplace_Index::sync_opportunity( $new_opp );
			} catch ( Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'LNKup duplicate_event sync: ' . $e->getMessage() );
				}
			}

			$created[] = $new_opp;
		}

		return $created;
	}

	/**
	 * Slot meta copied onto a duplicate. Counts and remaining spots are reset by the caller.
	 *
	 * @return array<int, string>
	 */
	private static function opportunity_meta_keys_to_copy(): array {
		return array(
			'_lnkup_vendor_fee',
			'_lnkup_lnkup_fee',
			'_lnkup_document_mode',
			'_lnkup_required_documents',
			'_lnkup_organizer_notes',
			'_lnkup_deposit_required',
			'_lnkup_packet_lock',
			'_lnkup_packet_locked',
			'_lnkup_gallery_ids',
			'_lnkup_featured_image_id',
			'_lnkup_instant_approval',
			'_lnkup_invite_only',
			'_lnkup_load_in_time',
			'_lnkup_application_questions',
			'_lnkup_documents_required_to_apply',
		);
	}
}
