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
		$event_id     = wp_insert_post(
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
		update_post_meta( $event_id, '_lnkup_status', 'published' );

		$gallery_raw = $input['gallery_ids'] ?? $input['gallery'] ?? '';
		if ( $gallery_raw || ! empty( $input['featured_image_id'] ) ) {
			lnkup_save_post_gallery( $event_id, $gallery_raw );
			if ( ! empty( $input['featured_image_id'] ) ) {
				update_post_meta( $event_id, '_lnkup_featured_image_id', absint( $input['featured_image_id'] ) );
			}
		}

		if ( ! empty( $input['event_type'] ) ) {
			wp_set_object_terms( $event_id, sanitize_text_field( (string) $input['event_type'] ), 'lnkup_event_type' );
		}

		if ( class_exists( 'LNKup_Geocoder' ) ) {
			LNKup_Geocoder::geocode_event( $event_id );
		}

		$dash_url = lnkup_page_url_helper( 'organizer-dashboard' );

		return array(
			'event_id'       => (int) $event_id,
			'event_title'    => $name,
			'event_date'     => isset( $input['event_date'] ) ? sanitize_text_field( (string) $input['event_date'] ) : '',
			'event_city'     => isset( $input['city'] ) ? sanitize_text_field( (string) $input['city'] ) : '',
			'view_event_url' => $dash_url . '#events',
			'onboarding'     => true,
		);
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
		if ( ! $blocked ) {
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
		if ( ! empty( $input['vendor_categories'] ) && is_array( $input['vendor_categories'] ) ) {
			wp_set_object_terms( $opp_id, array_map( 'sanitize_text_field', $input['vendor_categories'] ), 'lnkup_vendor_category' );
			return;
		}
		if ( ! empty( $input['categories'] ) && is_array( $input['categories'] ) ) {
			wp_set_object_terms( $opp_id, array_map( 'sanitize_text_field', $input['categories'] ), 'lnkup_vendor_category' );
			return;
		}
		if ( ! empty( $input['vendor_category'] ) ) {
			wp_set_object_terms( $opp_id, sanitize_text_field( (string) $input['vendor_category'] ), 'lnkup_vendor_category' );
		}
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
			? __( 'Opportunity created. It will appear in Find Events.', 'lnkup-marketplace' )
			: __( 'Opportunity created but could not be indexed. Try rebuilding the marketplace index in LNKup Settings.', 'lnkup-marketplace' );

		if ( $idempotent ) {
			$message = __( 'Opportunity created. It will appear in Find Events.', 'lnkup-marketplace' );
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
			'view_event_url'          => $dash_url . '#events',
			'manage_applications_url' => $dash_url . '#applications',
			'invite_vendors_url'      => add_query_arg(
				array(
					'invite_event' => $event_id,
					'opp'          => $opp_id,
				),
				$dash_url . '#vendors'
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
	public static function update_opportunity_pricing( int $opp_id, $vendor_fee, $spots_total, int $organizer_id ) {
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

		$old_total     = (int) get_post_meta( $opp_id, '_lnkup_spots_total', true );
		$old_remaining = (int) get_post_meta( $opp_id, '_lnkup_spots_remaining', true );
		$filled        = max( 0, $old_total - $old_remaining );

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

		$new_remaining = max( 0, $spots - $filled );

		update_post_meta( $opp_id, '_lnkup_vendor_fee', $fee );
		update_post_meta( $opp_id, '_lnkup_spots_total', $spots );
		update_post_meta( $opp_id, '_lnkup_spots_remaining', $new_remaining );

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
			'spots_remaining' => $new_remaining,
			'indexed'         => $indexed,
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
	public static function update_event_from_request( int $event_id, array $input, int $organizer_id ) {
		$event = get_post( $event_id );
		if ( ! $event || 'lnkup_event' !== $event->post_type ) {
			return new WP_Error( 'invalid_event', __( 'Invalid event.', 'lnkup-marketplace' ) );
		}
		if ( ! LNKup_Capabilities::user_owns_post( $event_id, $organizer_id, '_lnkup_organizer_user_id' )
			&& ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot edit this event.', 'lnkup-marketplace' ) );
		}

		$update = array( 'ID' => $event_id );
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

		$gallery_raw = $input['gallery_ids'] ?? $input['gallery'] ?? null;
		if ( null !== $gallery_raw || ! empty( $input['featured_image_id'] ) ) {
			lnkup_save_post_gallery( $event_id, $gallery_raw ?? '' );
			if ( ! empty( $input['featured_image_id'] ) ) {
				update_post_meta( $event_id, '_lnkup_featured_image_id', absint( $input['featured_image_id'] ) );
			}
		}

		if ( ! empty( $input['event_type'] ) ) {
			wp_set_object_terms( $event_id, sanitize_text_field( (string) $input['event_type'] ), 'lnkup_event_type' );
		}

		if ( class_exists( 'LNKup_Geocoder' ) ) {
			LNKup_Geocoder::geocode_event( $event_id );
		}

		$dash_url = lnkup_page_url_helper( 'organizer-dashboard' );

		return array(
			'message'        => __( 'Event updated.', 'lnkup-marketplace' ),
			'event_id'       => $event_id,
			'event_title'    => get_the_title( $event_id ),
			'event_date'     => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
			'event_city'     => (string) get_post_meta( $event_id, '_lnkup_city', true ),
			'view_event_url' => $dash_url . '#events',
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
		} elseif ( ! empty( $input['fee_category'] ) && is_array( $input['fee_category'] ) ) {
			$cats   = $input['fee_category'];
			$fees   = isset( $input['fee_amount'] ) && is_array( $input['fee_amount'] ) ? $input['fee_amount'] : array();
			$spots  = isset( $input['fee_spots'] ) && is_array( $input['fee_spots'] ) ? $input['fee_spots'] : array();
			$count  = count( $cats );
			for ( $i = 0; $i < $count; $i++ ) {
				$cat = sanitize_text_field( (string) $cats[ $i ] );
				if ( '' === $cat ) {
					continue;
				}
				$rows[] = array(
					'categories'       => array( $cat ),
					'vendor_category'  => $cat,
					'vendor_fee'       => $fees[ $i ] ?? 250,
					'spots_total'      => $spots[ $i ] ?? 10,
					'opportunity_title'=> $cat,
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

			$fee = isset( $row['vendor_fee'] ) ? LNKup_Security::sanitize_money( $row['vendor_fee'] ) : 250.0;
			if ( $fee < 0 ) {
				return new WP_Error( 'invalid_fee', __( 'Vendor fee cannot be negative.', 'lnkup-marketplace' ) );
			}
			$spots = isset( $row['spots_total'] ) ? absint( $row['spots_total'] ) : 10;
			if ( $spots < 1 ) {
				return new WP_Error( 'invalid_spots', __( 'At least one vendor spot is required.', 'lnkup-marketplace' ) );
			}

			$title = isset( $row['opportunity_title'] ) ? sanitize_text_field( (string) $row['opportunity_title'] ) : '';
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
				/* translators: %d: number of vendor type listings */
				__( 'Published %d vendor type listings with separate booth prices.', 'lnkup-marketplace' ),
				count( $created )
			)
			: ( $payload['message'] ?? __( 'Vendor opportunity published.', 'lnkup-marketplace' ) );

		return $payload;
	}
}
