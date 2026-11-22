<?php
/**
 * Central notification engine.
 *
 * dispatch()  — records one logical message per recipient (deduplicated) and
 *               queues it. Never sends inside the calling request.
 * deliver()   — worker: re-validates canonical state, preferences, caps, quiet
 *               hours and authorization, renders, then fans out to channels.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Engine {

	const RETRY_DELAYS = array( 1 => 300, 2 => 1800, 3 => 7200, 4 => 21600 );

	public static function init(): void {
		// Delivery hooks are registered by LNKup_Comms_Queue.
	}

	// ------------------------------------------------------------------ Dispatch.

	/**
	 * Record and queue a message.
	 *
	 * @param string $trigger   Trigger key.
	 * @param int    $recipient Recipient user ID.
	 * @param array  $ctx       IDs (+ scalar extras for the template).
	 * @param array  $opts      at (UTC ts), window (dedupe suffix), cancel_key, variant,
	 *                          items, campaign_id, source, force (ignore flag), channels.
	 * @return int Message ID, or 0 when skipped/duplicate.
	 */
	public static function dispatch( string $trigger, int $recipient, array $ctx, array $opts = array() ): int {
		global $wpdb;
		$def = LNKup_Comms_Registry::get( $trigger );
		if ( ! $def || $recipient < 1 ) {
			return 0;
		}
		$flag = (string) ( $def['flag'] ?? '' );
		if ( '' !== $flag && ! LNKup_Comms_Settings::enabled( $flag ) && empty( $opts['force'] ) ) {
			return 0;
		}
		$priority = (string) ( $opts['priority'] ?? $def['priority'] );
		if ( 'critical' !== $priority && LNKup_Comms_Settings::automation_paused( $trigger ) ) {
			LNKup_Comms_Analytics::bump( 'skipped_paused', $trigger );
			return 0;
		}
		$ctx = LNKup_Comms_Context::build( $ctx );

		$role = (string) $def['role'];
		if ( 'any' === $role ) {
			$role = (string) ( $opts['role'] ?? ( LNKup_Comms_Preferences::user_roles( $recipient )[0] ?? 'vendor' ) );
		}
		$auth = self::authorize( $role, $recipient, $ctx );
		if ( '' !== $auth ) {
			LNKup_Comms_Analytics::bump( 'skipped_unauthorized', $trigger );
			return 0;
		}

		$ctx['priority'] = $priority;
		$validity        = LNKup_Comms_Context::check( (string) ( $def['validity'] ?? '' ), $ctx );
		if ( '' !== $validity ) {
			LNKup_Comms_Analytics::bump( 'skipped_invalid', $trigger );
			return 0;
		}

		$object = (int) ( $ctx['application_id'] ?: ( $ctx['event_id'] ?: ( $ctx['opportunity_id'] ?: ( $ctx['payment_id'] ?: ( $ctx['document_id'] ?: (int) ( $opts['campaign_id'] ?? 0 ) ) ) ) ) );
		$window = sanitize_key( (string) ( $opts['window'] ?? '' ) );
		$dedupe = self::key( $trigger . '|' . $recipient . '|' . $object . '|' . $window );
		$at     = isset( $opts['at'] ) ? max( time(), (int) $opts['at'] ) : time();
		$now    = LNKup_Comms_Schema::now();

		$event_key = self::key( 'evt|' . $trigger . '|' . $object . '|' . $window );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . LNKup_Comms_Schema::table( 'events' ) . ' (trigger_key, source, object_type, object_id, dedupe_key, context, created_at) VALUES (%s, %s, %s, %d, %s, %s, %s)',
				$trigger,
				substr( sanitize_key( (string) ( $opts['source'] ?? '' ) ), 0, 64 ),
				$ctx['application_id'] ? 'application' : ( $ctx['event_id'] ? 'event' : ( $ctx['opportunity_id'] ? 'opportunity' : 'user' ) ),
				$object,
				$event_key,
				wp_json_encode( self::id_context( $ctx ) ),
				$now
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$event_row = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Comms_Schema::table( 'events' ) . ' WHERE dedupe_key = %s', $event_key ) );

		$stored_ctx = self::id_context( $ctx );
		foreach ( $ctx as $key => $value ) {
			if ( ! isset( $stored_ctx[ $key ] ) && is_scalar( $value ) ) {
				$stored_ctx[ $key ] = is_string( $value ) ? substr( $value, 0, 1200 ) : $value;
			}
		}
		$stored_ctx['_variant']  = sanitize_key( (string) ( $opts['variant'] ?? '' ) );
		$stored_ctx['_items']    = is_array( $opts['items'] ?? null ) ? array_slice( $opts['items'], 0, 10 ) : array();
		$stored_ctx['_channels'] = is_array( $opts['channels'] ?? null ) ? array_values( array_intersect( (array) $opts['channels'], array( 'inbox', 'email', 'push' ) ) ) : array();
		$stored_ctx['_role']     = $role;
		$stored_ctx['_link']     = sanitize_key( (string) ( $opts['link'] ?? '' ) );
		$stored_ctx['_category'] = sanitize_key( (string) ( $opts['category'] ?? $def['category'] ) );
		// Only explicit test sends skip the send-time flag check. Core-adapter messages also use
		// `force`, but core resumes sending them once the flag is off, so they must follow the flag.
		$stored_ctx['_forced']   = ! empty( $opts['force'] ) && (bool) preg_match( '/(^|_)test$/', (string) ( $opts['source'] ?? '' ) );

		$cancel_key = (string) ( $opts['cancel_key'] ?? self::default_cancel_key( $ctx, $recipient ) );
		$category   = LNKup_Comms_Registry::category( $stored_ctx['_category'] ) ? $stored_ctx['_category'] : (string) $def['category'];
		$cat_def    = LNKup_Comms_Registry::category( $category );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . LNKup_Comms_Schema::table( 'messages' ) . ' (event_row_id, trigger_key, recipient_user_id, recipient_role, category, priority, transactional, event_id, opportunity_id, application_id, payment_id, campaign_id, template_key, context, status, dedupe_key, cancel_key, scheduled_at, expires_at, created_at, updated_at) VALUES (%d, %s, %d, %s, %s, %s, %d, %d, %d, %d, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
				$event_row,
				$trigger,
				$recipient,
				$role,
				$category,
				$priority,
				( 'promotional' !== $priority && $cat_def && empty( $cat_def['promotional'] ) ) ? 1 : 0,
				(int) $ctx['event_id'],
				(int) $ctx['opportunity_id'],
				(int) $ctx['application_id'],
				(int) $ctx['payment_id'],
				(int) ( $opts['campaign_id'] ?? 0 ),
				$trigger . ( '' !== $stored_ctx['_variant'] ? ':' . $stored_ctx['_variant'] : '' ),
				wp_json_encode( $stored_ctx ),
				'scheduled',
				$dedupe,
				substr( $cancel_key, 0, 191 ),
				gmdate( 'Y-m-d H:i:s', $at ),
				gmdate( 'Y-m-d H:i:s', $at + (int) ( $def['expires'] ?? WEEK_IN_SECONDS ) ),
				$now,
				$now
			)
		);
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return 0;
		}
		$message_id = (int) $wpdb->insert_id;
		LNKup_Comms_Queue::enqueue( $message_id, $at );
		return $message_id;
	}

	private static function key( string $raw ): string {
		return strlen( $raw ) > 180 ? substr( $raw, 0, 120 ) . '#' . md5( $raw ) : $raw;
	}

	private static function id_context( array $ctx ): array {
		$out = array();
		foreach ( array( 'application_id', 'event_id', 'opportunity_id', 'payment_id', 'document_id', 'vendor_user_id', 'organizer_user_id', 'profile_id' ) as $key ) {
			$out[ $key ] = (int) ( $ctx[ $key ] ?? 0 );
		}
		return $out;
	}

	private static function default_cancel_key( array $ctx, int $recipient ): string {
		if ( $ctx['application_id'] ) {
			return 'app:' . (int) $ctx['application_id'];
		}
		if ( $ctx['opportunity_id'] ) {
			return 'opp:' . (int) $ctx['opportunity_id'];
		}
		if ( $ctx['event_id'] ) {
			return 'event:' . (int) $ctx['event_id'];
		}
		return 'user:' . $recipient;
	}

	/**
	 * Recipient may receive messages about these records.
	 *
	 * @return string '' when authorized, otherwise a reason.
	 */
	public static function authorize( string $role, int $recipient, array $ctx ): string {
		$user = get_userdata( $recipient );
		if ( ! $user ) {
			return 'user_missing';
		}
		if ( get_user_meta( $recipient, '_lnkup_account_deletion_requested_at', true ) ) {
			return 'account_deleting';
		}
		if ( 'admin' === $role ) {
			return user_can( $user, 'manage_options' ) || LNKup_Capabilities::user_has_manage_all( $recipient ) ? '' : 'not_admin';
		}
		if ( ! in_array( $role, LNKup_Comms_Preferences::user_roles( $recipient ), true ) ) {
			return 'role_missing';
		}
		// A partner message is about one partner account; it never reaches another partner.
		if ( 'partner' === $role && (int) ( $ctx['connector_id'] ?? 0 ) && (int) ( $ctx['connector_id'] ?? 0 ) !== LNKup_Comms_Preferences::partner_account_id( $recipient ) ) {
			return 'not_partner_account_owner';
		}
		if ( 'vendor' === $role && (int) $ctx['application_id'] && (int) get_post_meta( (int) $ctx['application_id'], '_lnkup_vendor_user_id', true ) !== $recipient ) {
			return 'not_application_owner';
		}
		if ( 'vendor' === $role && (int) $ctx['document_id'] && (int) get_post_meta( (int) $ctx['document_id'], '_lnkup_vendor_user_id', true ) !== $recipient ) {
			return 'not_document_owner';
		}
		if ( 'organizer' === $role && (int) $ctx['event_id'] ) {
			$event_id = (int) $ctx['event_id'];
			if ( ! LNKup_Capabilities::organizer_owns_event( $event_id, $recipient ) && ! LNKup_Capabilities::staff_can( $event_id, 'view_roster', $recipient ) ) {
				return 'not_event_member';
			}
			// Financial messages go to the owning organizer only, never to staff.
			if ( (int) $ctx['payment_id'] && ! LNKup_Capabilities::organizer_owns_event( $event_id, $recipient ) ) {
				return 'not_event_owner';
			}
		}
		return '';
	}

	// ------------------------------------------------------------------ Worker.

	public static function deliver( $message_id ): void {
		global $wpdb;
		$message_id = absint( $message_id );
		$table      = LNKup_Comms_Schema::table( 'messages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'sending', updated_at = %s WHERE id = %d AND status = 'scheduled' AND scheduled_at <= %s", LNKup_Comms_Schema::now(), $message_id, LNKup_Comms_Schema::now( 60 ) ) );
		if ( 1 !== (int) $claimed ) {
			return;
		}
		$message = self::message( $message_id );
		if ( ! $message ) {
			return;
		}
		try {
			if ( LNKup_Comms_Settings::enabled( 'delivery_paused' ) ) {
				self::reschedule( $message, 15 * MINUTE_IN_SECONDS );
				return;
			}
			$prepared = self::prepare( $message );
			if ( is_string( $prepared ) ) {
				self::finish( $message_id, 0 === strpos( $prepared, 'render_failed' ) ? 'failed' : ( 'expired' === $prepared ? 'expired' : 'suppressed' ), $prepared );
				return;
			}
			$results = array();
			foreach ( $prepared['channels'] as $channel ) {
				$results[ $channel ] = self::send_channel( $message, $prepared, $channel, 1 );
			}
			self::finish_from_results( $message, $prepared, $results );
		} catch ( Throwable $e ) {
			self::finish( $message_id, 'failed', 'exception:' . substr( $e->getMessage(), 0, 120 ) );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup Communications delivery #' . $message_id . ' failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Re-send a single channel after a transient failure or quiet-hours deferral.
	 *
	 * @param int|string $message_id Message.
	 * @param string     $channel    Channel.
	 */
	public static function retry_channel( $message_id, $channel ): void {
		$message_id = absint( $message_id );
		$channel    = sanitize_key( (string) $channel );
		$message    = self::message( $message_id );
		if ( ! $message || ! in_array( $channel, array( 'email', 'push' ), true ) || in_array( $message['status'], array( 'cancelled', 'expired' ), true ) ) {
			return;
		}
		$attempts = self::channel_attempts( $message_id, $channel );
		if ( $attempts >= 4 ) {
			return;
		}
		$prepared = self::prepare( $message );
		if ( is_string( $prepared ) ) {
			self::record_delivery( $message_id, $channel, 'suppressed', '', 0, '', $prepared );
			return;
		}
		$result = self::send_channel( $message, $prepared, $channel, $attempts + 1 );
		if ( in_array( $result['status'], array( 'provider_accepted', 'delivered' ), true ) && 'sent' !== $message['status'] ) {
			self::finish( $message_id, 'sent', '', (string) $prepared['rendered']['push_title'] );
		}
	}

	/**
	 * Resolve everything needed to send, or return a suppression reason.
	 *
	 * @return array|string
	 */
	private static function prepare( array $message ) {
		$def = LNKup_Comms_Registry::get( (string) $message['trigger_key'] );
		if ( ! $def ) {
			return 'unknown_trigger';
		}
		if ( ! empty( $message['expires_at'] ) && strtotime( $message['expires_at'] . ' UTC' ) < time() ) {
			return 'expired';
		}
		$stored = json_decode( (string) $message['context'], true );
		$stored = is_array( $stored ) ? $stored : array();
		// A flow switched off after scheduling belongs to lnkup-marketplace again; sending would double up.
		$flag = (string) ( $def['flag'] ?? '' );
		if ( '' !== $flag && empty( $stored['_forced'] ) && ! LNKup_Comms_Settings::enabled( $flag ) ) {
			return 'flag_off:' . $flag;
		}
		$ctx    = LNKup_Comms_Context::build( array_merge( $stored, array( 'priority' => (string) $message['priority'] ) ) );
		$role   = (string) $message['recipient_role'];
		$user   = (int) $message['recipient_user_id'];

		$auth = self::authorize( $role, $user, $ctx );
		if ( '' !== $auth ) {
			return 'unauthorized:' . $auth;
		}
		$validity = LNKup_Comms_Context::check( (string) ( $def['validity'] ?? '' ), $ctx );
		if ( '' !== $validity ) {
			return $validity;
		}
		$vars     = LNKup_Comms_Context::variables( $ctx, $user );
		$template = LNKup_Comms_Templates::get( (string) $message['template_key'] );
		if ( $template ) {
			$vars = array_merge( $vars, LNKup_Comms_Context::live_variables( LNKup_Comms_Templates::placeholders( $template ), array_merge( $ctx, array( '_role' => $role ) ), $user ) );
		}
		if ( (int) $message['campaign_id'] ) {
			$vars = array_merge( $vars, LNKup_Comms_Campaigns::variables( (int) $message['campaign_id'] ) );
		}
		$rendered = LNKup_Comms_Templates::render( (string) $message['template_key'], $vars, $role, (array) ( $stored['_items'] ?? array() ) );
		if ( is_wp_error( $rendered ) ) {
			LNKup_Comms_Analytics::bump( 'render_failed', (string) $message['template_key'] );
			return 'render_failed:' . $rendered->get_error_message();
		}
		$link    = (string) ( ! empty( $stored['_link'] ) ? $stored['_link'] : $def['link'] );
		$web_url = LNKup_Comms_Context::web_url( $link, $ctx, $role );
		if ( ! in_array( $link, array( 'opportunity', 'vendor_find' ), true ) && function_exists( 'lnkup_login_url_for' ) && ! is_user_logged_in() ) {
			$web_url = (string) lnkup_login_url_for( $web_url );
		}
		$channels = (array) $def['channels'];
		if ( ! empty( $stored['_channels'] ) ) {
			$channels = array_values( array_intersect( $channels, (array) $stored['_channels'] ) );
			if ( 'campaign_message' === $message['trigger_key'] ) {
				$channels = (array) $stored['_channels'];
			}
		}
		return array(
			'def'      => $def,
			'ctx'      => $ctx,
			'rendered' => $rendered,
			'channels' => $channels,
			'links'    => array(
				'deep_link' => LNKup_Comms_Context::deep_link( $link, $ctx, $role ),
				'web_url'   => $web_url,
			),
		);
	}

	/**
	 * Apply channel rules, then send.
	 *
	 * @return array{status:string,error:string}
	 */
	private static function send_channel( array $message, array $prepared, string $channel, int $attempt ): array {
		$user     = (int) $message['recipient_user_id'];
		$priority = (string) $message['priority'];
		$category = (string) $message['category'];
		$critical = 'critical' === $priority;
		$id       = (int) $message['id'];

		if ( 'inbox' === $channel ) {
			$result = LNKup_Comms_Channel_Inbox::send( $message, $prepared['def'], $prepared['rendered'], $prepared['links'], $prepared['ctx'] );
			self::record_delivery( $id, 'inbox', $result['status'], '', 0, $result['error'] );
			return $result;
		}

		if ( 'email' === $channel ) {
			if ( ! LNKup_Comms_Settings::enabled( 'email_channel' ) ) {
				return self::suppress( $id, $channel, 'channel_disabled' );
			}
			$is_digest = in_array( (string) $message['trigger_key'], array( 'digest_email', 'o_ops_summary', 'v_weekly_matches' ), true );
			// The digest itself carries categories the user chose daily/weekly for, so it is never re-diverted.
			$mode = ( $critical || 'digest_email' === $message['trigger_key'] ) ? 'immediate' : LNKup_Comms_Preferences::email_mode( $user, $category );
			if ( 'off' === $mode ) {
				return self::suppress( $id, $channel, 'preference_off' );
			}
			if ( in_array( $mode, array( 'daily', 'weekly' ), true ) && ! in_array( $priority, array( 'critical', 'high' ), true ) && ! $is_digest ) {
				self::record_delivery( $id, 'email', 'digest_pending', $mode, 0 );
				return array( 'status' => 'digest_pending', 'error' => '' );
			}
			$cap = LNKup_Comms_Frequency::check( $user, 'email', $priority );
			if ( '' !== $cap ) {
				return self::suppress( $id, $channel, $cap );
			}
			$result = LNKup_Comms_Channel_Email::send( $message, $prepared['def'], $prepared['rendered'], $prepared['links'], $prepared['ctx'] );
		} else {
			if ( ! LNKup_Comms_Settings::enabled( 'push_channel' ) ) {
				return self::suppress( $id, $channel, 'channel_disabled' );
			}
			if ( ! LNKup_Comms_Preferences::push_on( $user, $critical ? 'account' : $category ) ) {
				return self::suppress( $id, $channel, 'preference_off' );
			}
			$cap = LNKup_Comms_Frequency::check( $user, 'push', $priority );
			if ( '' !== $cap ) {
				return self::suppress( $id, $channel, $cap );
			}
			if ( ! in_array( $priority, array( 'critical', 'high' ), true ) ) {
				$quiet = LNKup_Comms_Preferences::quiet_seconds_remaining( $user );
				if ( $quiet > 0 ) {
					self::record_delivery( $id, 'push', 'deferred', '', 0, 'quiet_hours' );
					LNKup_Comms_Queue::enqueue_retry( $id, 'push', $quiet + 60 );
					return array( 'status' => 'deferred', 'error' => 'quiet_hours' );
				}
			}
			$result = LNKup_Comms_Channel_Push::send( $message, $prepared['def'], $prepared['rendered'], $prepared['links'], $prepared['ctx'] );
		}

		if ( 'email' === $channel ) {
			self::record_delivery( $id, 'email', $result['status'], '', 0, $result['error'], 'suppressed' === $result['status'] ? $result['error'] : '' );
		} elseif ( 'suppressed' === $result['status'] ) {
			self::record_delivery( $id, 'push', 'suppressed', '', 0, '', $result['error'] );
		}
		if ( ! empty( $result['retry'] ) && isset( self::RETRY_DELAYS[ $attempt ] ) ) {
			LNKup_Comms_Queue::enqueue_retry( $id, $channel, self::RETRY_DELAYS[ $attempt ] );
		}
		return $result;
	}

	private static function suppress( int $message_id, string $channel, string $reason ): array {
		self::record_delivery( $message_id, $channel, 'suppressed', '', 0, '', $reason );
		return array( 'status' => 'suppressed', 'error' => $reason );
	}

	private static function finish_from_results( array $message, array $prepared, array $results ): void {
		$ok = false;
		$failed = false;
		$reasons = array();
		foreach ( $results as $channel => $result ) {
			if ( in_array( $result['status'], array( 'delivered', 'provider_accepted', 'digest_pending', 'deferred' ), true ) ) {
				$ok = true;
			} elseif ( 'failed' === $result['status'] ) {
				$failed = true;
			}
			if ( '' !== (string) $result['error'] ) {
				$reasons[] = $channel . ':' . $result['error'];
			}
		}
		$status = $ok ? ( $failed ? 'partial' : 'sent' ) : ( $failed ? 'failed' : 'suppressed' );
		self::finish( (int) $message['id'], $status, implode( ',', $reasons ), (string) $prepared['rendered']['push_title'] );
	}

	private static function finish( int $message_id, string $status, string $reason = '', string $title = '' ): void {
		global $wpdb;
		$data = array(
			'status'             => $status,
			'suppression_reason' => substr( $reason, 0, 64 ),
			'updated_at'         => LNKup_Comms_Schema::now(),
		);
		if ( '' !== $title ) {
			$data['title'] = substr( $title, 0, 255 );
		}
		if ( in_array( $status, array( 'sent', 'partial' ), true ) ) {
			$data['sent_at'] = LNKup_Comms_Schema::now();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( LNKup_Comms_Schema::table( 'messages' ), $data, array( 'id' => $message_id ) );
	}

	private static function reschedule( array $message, int $delay ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			LNKup_Comms_Schema::table( 'messages' ),
			array( 'status' => 'scheduled', 'scheduled_at' => LNKup_Comms_Schema::now( $delay ), 'updated_at' => LNKup_Comms_Schema::now() ),
			array( 'id' => (int) $message['id'] )
		);
		LNKup_Comms_Queue::enqueue( (int) $message['id'], time() + $delay );
	}

	// ------------------------------------------------------------------ Records.

	public static function message( int $message_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Comms_Schema::table( 'messages' ) . ' WHERE id = %d', $message_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function record_delivery( int $message_id, string $channel, string $status, string $provider_ref = '', int $device_id = 0, string $error = '', string $suppression = '' ): void {
		global $wpdb;
		$now = LNKup_Comms_Schema::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			LNKup_Comms_Schema::table( 'deliveries' ),
			array(
				'message_id'         => $message_id,
				'channel'            => $channel,
				'status'             => $status,
				'provider_ref'       => substr( $provider_ref, 0, 191 ),
				'device_id'          => $device_id,
				'attempts'           => self::channel_attempts( $message_id, $channel ) + 1,
				'last_error'         => substr( $error, 0, 255 ),
				'suppression_reason' => substr( $suppression, 0, 64 ),
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
	}

	private static function channel_attempts( int $message_id, string $channel ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Comms_Schema::table( 'deliveries' ) . " WHERE message_id = %d AND channel = %s AND status IN ('failed','deferred')", $message_id, $channel ) );
	}

	/**
	 * Provider webhook outcome for an email.
	 */
	public static function record_email_event( int $message_id, string $type ): void {
		global $wpdb;
		$map = array( 'delivered' => 'delivered', 'bounce' => 'bounced', 'complaint' => 'complained' );
		if ( ! isset( $map[ $type ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Comms_Schema::table( 'deliveries' ) . " SET status = %s, updated_at = %s WHERE message_id = %d AND channel = 'email' AND status = 'provider_accepted'", $map[ $type ], LNKup_Comms_Schema::now(), $message_id ) );
	}

	// ------------------------------------------------------------------ Cancellation.

	/**
	 * Cancel scheduled (not yet sent) messages. A key ending in "*" matches its prefix.
	 *
	 * @param string   $cancel_key Exact key, or "app:12|*" style prefix.
	 * @param string[] $triggers   Optional trigger filter.
	 * @return int Messages cancelled.
	 */
	public static function cancel( string $cancel_key, array $triggers = array(), string $reason = 'state_changed' ): int {
		global $wpdb;
		$table = LNKup_Comms_Schema::table( 'messages' );
		$where = "status = 'scheduled'";
		$args  = array();
		if ( '*' === substr( $cancel_key, -1 ) ) {
			$base   = substr( $cancel_key, 0, -1 );
			$where .= ' AND (cancel_key = %s OR cancel_key LIKE %s)';
			$args[] = rtrim( $base, '|' );
			$args[] = $wpdb->esc_like( $base ) . '%';
		} else {
			$where .= ' AND cancel_key = %s';
			$args[] = $cancel_key;
		}
		if ( $triggers ) {
			$where .= ' AND trigger_key IN (' . implode( ',', array_fill( 0, count( $triggers ), '%s' ) ) . ')';
			$args   = array_merge( $args, array_values( $triggers ) );
		}
		$args = array_merge( array( substr( 'cancelled:' . $reason, 0, 64 ), LNKup_Comms_Schema::now() ), $args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'cancelled', suppression_reason = %s, updated_at = %s WHERE {$where}", $args ) );
		return (int) $wpdb->rows_affected;
	}

	public static function cancel_for_user( int $user_id, string $reason ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Comms_Schema::table( 'messages' ) . " SET status = 'cancelled', suppression_reason = %s, updated_at = %s WHERE recipient_user_id = %d AND status = 'scheduled'", substr( 'cancelled:' . $reason, 0, 64 ), LNKup_Comms_Schema::now(), $user_id ) );
		return (int) $wpdb->rows_affected;
	}

	/**
	 * Mark a message read/opened from the inbox or a push tap. Ownership enforced.
	 */
	public static function mark_opened( int $user_id, int $message_id, bool $opened ): bool {
		global $wpdb;
		$table = LNKup_Comms_Schema::table( 'messages' );
		$col   = $opened ? 'opened_at' : 'read_at';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$col} = %s WHERE id = %d AND recipient_user_id = %d AND {$col} IS NULL", LNKup_Comms_Schema::now(), $message_id, $user_id ) );
		if ( $opened ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET read_at = %s WHERE id = %d AND recipient_user_id = %d AND read_at IS NULL", LNKup_Comms_Schema::now(), $message_id, $user_id ) );
		}
		return true;
	}

	/**
	 * Purge communication data for a user (account deletion).
	 */
	public static function purge_user( int $user_id ): void {
		global $wpdb;
		self::cancel_for_user( $user_id, 'account_deleted' );
		$messages = LNKup_Comms_Schema::table( 'messages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$messages} WHERE recipient_user_id = %d", $user_id ) );
		foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( 'DELETE FROM ' . LNKup_Comms_Schema::table( 'deliveries' ) . " WHERE message_id IN ({$in})" );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $messages, array( 'recipient_user_id' => $user_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( LNKup_Comms_Schema::table( 'clicks' ), array( 'user_id' => $user_id ) );
		if ( class_exists( 'LNKup_Inbox_Tables' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( LNKup_Inbox_Tables::index_table(), array( 'user_id' => $user_id ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( LNKup_Inbox_Tables::delivery_table(), array( 'recipient_id' => $user_id ) );
		}
		LNKup_Comms_Preferences::purge( $user_id );
	}
}
