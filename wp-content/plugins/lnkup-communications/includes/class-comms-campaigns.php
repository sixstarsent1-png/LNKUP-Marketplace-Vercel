<?php
/**
 * Campaigns: admin segment campaigns and organizer event communications.
 *
 * Campaign rows double as the audit trail (who sent what, to which audience, when).
 * Recipients are processed in background batches with a cursor, so a campaign can
 * be paused, resumed or cancelled, and survives worker restarts.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Campaigns {

	public static function init(): void {
		add_action( 'lnkup_comms_campaign_batch', array( __CLASS__, 'process_batch' ), 10, 1 );
	}

	public static function table(): string {
		return LNKup_Comms_Schema::table( 'campaigns' );
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['filters'] = json_decode( (string) $row['filters'], true ) ?: array();
		$row['content'] = json_decode( (string) $row['content'], true ) ?: array();
		return $row;
	}

	/**
	 * Template variables for campaign messages.
	 */
	public static function variables( int $id, string $variant = '' ): array {
		static $cache = array();
		$key = $id . '|' . $variant;
		if ( ! isset( $cache[ $key ] ) ) {
			$row     = self::get( $id );
			$content = $row ? (array) $row['content'] : array();
			if ( $row && '' !== $variant && class_exists( 'LNKup_Comms_Campaign_Workflow' ) ) {
				$content = array_merge( $content, LNKup_Comms_Campaign_Workflow::variant_content( $row, $variant ) );
			}
			$cache[ $key ] = array(
				'campaign_subject'   => (string) ( $content['subject'] ?? '' ),
				'campaign_body'      => (string) ( $content['body'] ?? '' ),
				'campaign_cta'       => (string) ( $content['cta'] ?? '' ),
				'campaign_push'      => (string) ( $content['push'] ?? '' ),
				'announcement_title' => (string) ( $content['subject'] ?? '' ),
				'announcement_body'  => (string) ( $content['body'] ?? '' ),
			);
		}
		return $cache[ $key ];
	}

	/**
	 * Audience filters (saved segment rules when the campaign links one).
	 */
	private static function filters_of( array $row ): array {
		return class_exists( 'LNKup_Comms_Campaign_Workflow' ) ? LNKup_Comms_Campaign_Workflow::filters_for( $row ) : LNKup_Comms_Audiences::clean_filters( (array) $row['filters'] );
	}

	private static function clean_content( array $raw ): array {
		return array(
			'subject' => mb_substr( sanitize_text_field( (string) ( $raw['subject'] ?? '' ) ), 0, 120 ),
			'body'    => mb_substr( sanitize_textarea_field( (string) ( $raw['body'] ?? '' ) ), 0, 2000 ),
			'cta'     => mb_substr( sanitize_text_field( (string) ( $raw['cta'] ?? '' ) ), 0, 40 ),
			'push'    => mb_substr( sanitize_text_field( (string) ( $raw['push'] ?? '' ) ), 0, 178 ),
			'link'    => in_array( (string) ( $raw['link'] ?? '' ), array( 'home', 'vendor_find', 'vendor_profile', 'org_launch', 'org_vendors', 'org_home' ), true ) ? (string) $raw['link'] : 'home',
		);
	}

	private static function clean_channels( $raw ): array {
		$channels = array_values( array_intersect( array_map( 'sanitize_key', (array) $raw ), array( 'inbox', 'email', 'push' ) ) );
		if ( ! in_array( 'inbox', $channels, true ) ) {
			$channels[] = 'inbox';
		}
		return $channels;
	}

	// ------------------------------------------------------------------ Admin campaigns.

	/**
	 * @return int|WP_Error
	 */
	public static function create_admin( array $input, int $user_id ) {
		global $wpdb;
		$filters  = LNKup_Comms_Audiences::clean_filters( (array) ( $input['filters'] ?? array() ) );
		$content  = self::clean_content( (array) ( $input['content'] ?? array() ) );
		$category = in_array( (string) ( $input['category'] ?? '' ), array( 'product_news', 'opportunities', 'recruitment', 'onboarding' ), true ) ? (string) $input['category'] : 'product_news';
		if ( '' === $content['subject'] || '' === $content['body'] ) {
			return new WP_Error( 'lnkup_comms_campaign', __( 'A subject and message are required.', 'lnkup-communications' ), array( 'status' => 400 ) );
		}
		$now = LNKup_Comms_Schema::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table(),
			array(
				'campaign_type' => 'admin',
				'name'          => mb_substr( sanitize_text_field( (string) ( $input['name'] ?? $content['subject'] ) ), 0, 191 ),
				'audience_role' => $filters['role'],
				'category'      => $category,
				'channels'      => implode( ',', self::clean_channels( $input['channels'] ?? array( 'inbox', 'email' ) ) ),
				'filters'       => wp_json_encode( $filters ),
				'content'       => wp_json_encode( $content ),
				'status'        => 'draft',
				'created_by'    => $user_id,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function preview( int $id ): ?array {
		$row = self::get( $id );
		if ( ! $row ) {
			return null;
		}
		$preview = LNKup_Comms_Audiences::segment_preview( self::filters_of( $row ), (string) $row['category'], explode( ',', (string) $row['channels'] ) );
		self::update( $id, array( 'total_eligible' => (int) $preview['recipients'] ) );
		return $preview;
	}

	/**
	 * Send to internal test accounts only (configured in settings; the current admin is always allowed).
	 *
	 * @return int|WP_Error Messages queued.
	 */
	public static function test_send( int $id, int $admin_id ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'lnkup_comms_campaign', __( 'Campaign not found.', 'lnkup-communications' ), array( 'status' => 404 ) );
		}
		$recipients = array_unique( array_merge( array( $admin_id ), LNKup_Comms_Settings::test_user_ids() ) );
		$queued     = 0;
		foreach ( $recipients as $user_id ) {
			$queued += LNKup_Comms_Engine::dispatch(
				'campaign_message',
				(int) $user_id,
				array(),
				array( 'campaign_id' => $id, 'window' => 'test_' . time(), 'channels' => explode( ',', (string) $row['channels'] ), 'category' => (string) $row['category'], 'role' => (string) $row['audience_role'], 'link' => (string) ( $row['content']['link'] ?? 'home' ), 'force' => true, 'source' => 'campaign_test' )
			) > 0 ? 1 : 0;
		}
		return $queued;
	}

	/**
	 * Start sending. Large audiences require the operator to confirm the exact count.
	 *
	 * @return true|WP_Error
	 */
	public static function start( int $id, int $confirm_count ) {
		$row = self::get( $id );
		if ( ! $row || ! in_array( $row['status'], array( 'draft', 'paused', 'scheduled' ), true ) ) {
			return new WP_Error( 'lnkup_comms_campaign', __( 'This campaign cannot be started.', 'lnkup-communications' ), array( 'status' => 409 ) );
		}
		if ( 'draft' === $row['status'] && 'admin' === $row['campaign_type'] && class_exists( 'LNKup_Comms_Campaign_Workflow' ) && LNKup_Comms_Campaign_Workflow::active() ) {
			return new WP_Error( 'lnkup_comms_needs_approval', __( 'Submit this campaign for approval. A second team member approves it before it sends.', 'lnkup-communications' ), array( 'status' => 409 ) );
		}
		if ( 'admin' === $row['campaign_type'] && ! LNKup_Comms_Settings::enabled( 'campaigns_enabled' ) ) {
			return new WP_Error( 'lnkup_comms_campaign', __( 'Campaigns are disabled in Communications settings.', 'lnkup-communications' ), array( 'status' => 409 ) );
		}
		if ( 'draft' === $row['status'] && 'admin' === $row['campaign_type'] ) {
			$preview   = self::preview( $id );
			$eligible  = (int) ( $preview['recipients'] ?? 0 );
			$threshold = LNKup_Comms_Settings::int( 'campaign_confirm_threshold' );
			if ( $eligible >= $threshold && $confirm_count !== $eligible ) {
				return new WP_Error(
					'lnkup_comms_confirm',
					/* translators: %d: recipients */
					sprintf( __( 'This campaign reaches %d people. Confirm the exact recipient count to send.', 'lnkup-communications' ), $eligible ),
					array( 'status' => 409, 'eligible' => $eligible )
				);
			}
		}
		self::update( $id, array( 'status' => 'running', 'started_at' => $row['started_at'] ?: LNKup_Comms_Schema::now() ) );
		LNKup_Comms_Queue::job( 'lnkup_comms_campaign_batch', array( $id ) );
		return true;
	}

	public static function pause( int $id ): void {
		self::update_if( $id, 'running', array( 'status' => 'paused' ) );
	}

	public static function cancel( int $id ): void {
		global $wpdb;
		self::update( $id, array( 'status' => 'cancelled', 'completed_at' => LNKup_Comms_Schema::now() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Comms_Schema::table( 'messages' ) . " SET status = 'cancelled', suppression_reason = 'cancelled:campaign', updated_at = %s WHERE campaign_id = %d AND status = 'scheduled'", LNKup_Comms_Schema::now(), $id ) );
	}

	private static function update( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = LNKup_Comms_Schema::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	private static function update_if( int $id, string $status, array $data ): void {
		global $wpdb;
		$data['updated_at'] = LNKup_Comms_Schema::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( self::table(), $data, array( 'id' => $id, 'status' => $status ) );
	}

	/**
	 * Background batch. Re-reads status each run so pause/cancel take effect immediately.
	 *
	 * @param int $id Campaign.
	 */
	public static function process_batch( $id ): void {
		$id  = absint( $id );
		$row = self::get( $id );
		if ( ! $row || 'running' !== $row['status'] ) {
			return;
		}
		try {
			self::process_batch_unsafe( $id, $row );
		} catch ( Throwable $e ) {
			self::update( $id, array( 'status' => 'failed', 'completed_at' => LNKup_Comms_Schema::now() ) );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup campaign ' . $id . ' failed: ' . $e->getMessage() );
		}
	}

	private static function process_batch_unsafe( int $id, array $row ): void {
		$channels = explode( ',', (string) $row['channels'] );
		$queued   = 0;
		$excluded = 0;
		if ( 'event' === $row['campaign_type'] ) {
			$members = LNKup_Comms_Audiences::event_group_members( (int) $row['event_id'], (string) $row['audience_key'] );
			foreach ( $members as $vendor_id => $app_id ) {
				$sent = LNKup_Comms_Engine::dispatch(
					'v_announcement',
					(int) $vendor_id,
					array( 'application_id' => (int) $app_id, 'event_id' => (int) $row['event_id'] ),
					array( 'campaign_id' => $id, 'window' => 'c' . $id, 'channels' => $channels, 'priority' => (string) ( $row['content']['priority'] ?? 'normal' ), 'source' => 'event_campaign' )
				);
				$sent ? ++$queued : ++$excluded;
			}
			self::update( $id, array( 'status' => 'completed', 'total_eligible' => count( $members ), 'total_queued' => $queued, 'total_excluded' => $excluded, 'completed_at' => LNKup_Comms_Schema::now() ) );
			return;
		}

		$batch_size = max( 10, min( 500, LNKup_Comms_Settings::int( 'campaign_batch_size' ) ) );
		$filters    = self::filters_of( $row );
		$workflow   = class_exists( 'LNKup_Comms_Campaign_Workflow' );
		$batch      = LNKup_Comms_Audiences::user_batch( $filters, (int) $row['cursor_id'], $batch_size );
		$cursor     = (int) $row['cursor_id'];
		foreach ( $batch as $user_id ) {
			$cursor = $user_id;
			if ( ! LNKup_Comms_Audiences::matches( $user_id, $filters ) ) {
				continue;
			}
			if ( $workflow && ! LNKup_Comms_Campaign_Workflow::allowed_recipient( $row, $user_id ) ) {
				++$excluded;
				continue;
			}
			$sent = LNKup_Comms_Engine::dispatch(
				'campaign_message',
				$user_id,
				array(),
				array( 'campaign_id' => $id, 'window' => 'c' . $id, 'channels' => $channels, 'category' => (string) $row['category'], 'role' => (string) $row['audience_role'], 'link' => (string) ( $row['content']['link'] ?? 'home' ), 'source' => 'campaign', 'experiment_variant' => $workflow ? LNKup_Comms_Campaign_Workflow::variant_for( $row, $user_id ) : '' )
			);
			$sent ? ++$queued : ++$excluded;
		}
		$done = count( $batch ) < $batch_size;
		self::update(
			$id,
			array(
				'cursor_id'      => $cursor,
				'total_queued'   => (int) $row['total_queued'] + $queued,
				'total_excluded' => (int) $row['total_excluded'] + $excluded,
				'status'         => $done ? 'completed' : 'running',
				'completed_at'   => $done ? LNKup_Comms_Schema::now() : null,
			)
		);
		if ( ! $done ) {
			LNKup_Comms_Queue::job( 'lnkup_comms_campaign_batch', array( $id ), 30 );
		}
	}

	/**
	 * @return array<int,array>
	 */
	public static function list( string $type = 'admin', int $event_id = 0, int $limit = 50 ): array {
		global $wpdb;
		$where = $wpdb->prepare( 'campaign_type = %s', $type );
		if ( $event_id ) {
			$where .= $wpdb->prepare( ' AND event_id = %d', $event_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['filters'] = json_decode( (string) $row['filters'], true ) ?: array();
			$row['content'] = json_decode( (string) $row['content'], true ) ?: array();
		}
		return $rows;
	}

	// ------------------------------------------------------------------ Organizer event communications.

	/**
	 * Reusable event templates. Restrictions keep payment and document reminders to relevant groups.
	 */
	public static function event_templates(): array {
		return array(
			'announcement'          => array( 'label' => __( 'Event announcement', 'lnkup-communications' ), 'priority' => 'normal', 'groups' => array(), 'title' => '', 'body' => '' ),
			'setup_instructions'    => array( 'label' => __( 'Setup instructions', 'lnkup-communications' ), 'priority' => 'normal', 'groups' => array( 'confirmed', 'not_checked_in' ), 'title' => __( 'Setup instructions', 'lnkup-communications' ), 'body' => __( 'Load-in starts at [time]. Enter through [gate/entrance] and check in at the vendor tent before setting up.', 'lnkup-communications' ) ),
			'schedule_change'       => array( 'label' => __( 'Schedule or location change', 'lnkup-communications' ), 'priority' => 'high', 'groups' => array(), 'title' => __( 'Schedule update', 'lnkup-communications' ), 'body' => '' ),
			'checkin_instructions'  => array( 'label' => __( 'Check-in instructions', 'lnkup-communications' ), 'priority' => 'high', 'groups' => array( 'confirmed', 'not_checked_in' ), 'title' => __( 'Check-in is open', 'lnkup-communications' ), 'body' => __( 'Check-in is open. Show your event pass QR code at the vendor check-in point.', 'lnkup-communications' ) ),
			'document_request'      => array( 'label' => __( 'Request missing documents', 'lnkup-communications' ), 'priority' => 'normal', 'groups' => array( 'docs_incomplete' ), 'title' => __( 'Documents needed', 'lnkup-communications' ), 'body' => __( 'Please upload your required documents in LNKup so we can confirm you for check-in.', 'lnkup-communications' ) ),
			'payment_reminder'      => array( 'label' => __( 'Remind unpaid vendors', 'lnkup-communications' ), 'priority' => 'normal', 'groups' => array( 'approved_unpaid' ), 'title' => __( 'Payment reminder', 'lnkup-communications' ), 'body' => __( 'Your spot is approved. Complete payment in LNKup to confirm your booking.', 'lnkup-communications' ) ),
		);
	}

	/**
	 * Rate limits for organizer-initiated sends.
	 *
	 * @return string '' when allowed, otherwise a user-facing reason.
	 */
	public static function event_rate_limit( int $event_id ): string {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_type = 'event' AND event_id = %d AND created_at >= %s", $event_id, LNKup_Comms_Schema::now( -DAY_IN_SECONDS ) ) );
		if ( $today >= max( 1, LNKup_Comms_Settings::int( 'organizer_broadcast_daily_limit' ) ) ) {
			return __( 'You have reached the daily message limit for this event. Try again tomorrow.', 'lnkup-communications' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_type = 'event' AND event_id = %d AND created_at >= %s", $event_id, LNKup_Comms_Schema::now( -max( 1, LNKup_Comms_Settings::int( 'organizer_broadcast_min_interval' ) ) * MINUTE_IN_SECONDS ) ) );
		if ( $recent > 0 ) {
			return __( 'Please wait a few minutes before sending another message for this event.', 'lnkup-communications' );
		}
		return '';
	}

	/**
	 * @return array|WP_Error Campaign summary.
	 */
	public static function create_event( int $event_id, int $user_id, array $input ) {
		global $wpdb;
		$templates = self::event_templates();
		$template  = sanitize_key( (string) ( $input['template'] ?? 'announcement' ) );
		$group     = sanitize_key( (string) ( $input['group'] ?? '' ) );
		if ( ! isset( $templates[ $template ] ) || ! isset( LNKup_Comms_Audiences::event_groups()[ $group ] ) ) {
			return new WP_Error( 'lnkup_comms_event_send', __( 'Choose a message type and recipients.', 'lnkup-communications' ), array( 'status' => 400 ) );
		}
		if ( $templates[ $template ]['groups'] && ! in_array( $group, $templates[ $template ]['groups'], true ) ) {
			return new WP_Error( 'lnkup_comms_event_send', __( 'This message type can only be sent to the recipients it applies to.', 'lnkup-communications' ), array( 'status' => 400 ) );
		}
		$title = mb_substr( trim( sanitize_text_field( (string) ( $input['title'] ?? '' ) ) ), 0, 120 );
		$body  = mb_substr( trim( sanitize_textarea_field( (string) ( $input['body'] ?? '' ) ) ), 0, 1000 );
		if ( '' === $title || '' === $body ) {
			return new WP_Error( 'lnkup_comms_event_send', __( 'Add a title and message.', 'lnkup-communications' ), array( 'status' => 400 ) );
		}
		$limit = self::event_rate_limit( $event_id );
		if ( '' !== $limit ) {
			return new WP_Error( 'lnkup_comms_rate_limited', $limit, array( 'status' => 429 ) );
		}
		$members = LNKup_Comms_Audiences::event_group_members( $event_id, $group );
		if ( array() === $members ) {
			return new WP_Error( 'lnkup_comms_event_send', __( 'No vendors are in this group yet.', 'lnkup-communications' ), array( 'status' => 400 ) );
		}
		$now = LNKup_Comms_Schema::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table(),
			array(
				'campaign_type'  => 'event',
				'event_id'       => $event_id,
				'name'           => $templates[ $template ]['label'],
				'audience_role'  => 'vendor',
				'audience_key'   => $group,
				'category'       => 'announcements',
				'channels'       => implode( ',', self::clean_channels( $input['channels'] ?? array( 'inbox', 'email', 'push' ) ) ),
				'filters'        => wp_json_encode( array( 'group' => $group, 'template' => $template ) ),
				'content'        => wp_json_encode( array( 'subject' => $title, 'body' => $body, 'priority' => $templates[ $template ]['priority'] ) ),
				'status'         => 'running',
				'created_by'     => $user_id,
				'total_eligible' => count( $members ),
				'started_at'     => $now,
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		LNKup_Comms_Queue::job( 'lnkup_comms_campaign_batch', array( $id ) );
		if ( class_exists( 'LNKup_Operations_Log' ) ) {
			LNKup_Operations_Log::log( $event_id, 'communication_sent', array( 'actor' => $user_id, 'payload' => array( 'campaign_id' => $id, 'group' => $group, 'template' => $template ) ) );
		}
		return array( 'id' => $id, 'recipients' => count( $members ) );
	}
}
