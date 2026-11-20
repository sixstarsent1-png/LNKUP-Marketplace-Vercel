<?php
/**
 * Platform outreach campaigns (mass invite by email).
 *
 * Manual QA: wp-admin LNKup → Outreach — create draft, paste emails, queue;
 * visit /sign-up/?outreach=TOKEN; confirm readonly email + role; register and verify campaign registered count.
 * Rate limits: set low hourly/daily caps in Settings, queue 10+ recipients, confirm pause + auto-resume.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Outreach {

	const STATUS_DRAFT      = 'draft';
	const STATUS_QUEUED     = 'queued';
	const STATUS_PROCESSING = 'processing';
	const STATUS_COMPLETED  = 'completed';
	const STATUS_CANCELLED  = 'cancelled';

	const REC_PENDING          = 'pending';
	const REC_SENT             = 'sent';
	const REC_SKIPPED          = 'skipped_existing';
	const REC_FAILED           = 'failed';
	const REC_OPENED           = 'opened';
	const REC_REGISTERED       = 'registered';

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 15, 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_track_outreach_visit' ), 6 );
	}

	/**
	 * Track sign-up page visit with outreach token.
	 */
	public static function maybe_track_outreach_visit() {
		if ( is_user_logged_in() || empty( $_GET['outreach'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['outreach'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $token ) {
			self::mark_opened( $token );
		}
	}

	/**
	 * @param int $user_id New user ID.
	 */
	public static function on_user_register( $user_id ) {
		$token = '';
		if ( ! empty( $_POST['lnkup_outreach_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$token = sanitize_text_field( wp_unslash( $_POST['lnkup_outreach_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( $token ) {
			self::mark_registered( (int) $user_id, $token );
		}
	}

	/**
	 * Create a campaign.
	 *
	 * @param array $args Campaign args.
	 * @return int|WP_Error
	 */
	public static function create_campaign( $args ) {
		global $wpdb;

		$name = sanitize_text_field( $args['name'] ?? '' );
		if ( '' === $name ) {
			return new WP_Error( 'invalid_name', __( 'Campaign name is required.', 'lnkup-marketplace' ) );
		}

		$source = sanitize_key( $args['source'] ?? 'admin' );
		if ( ! in_array( $source, array( 'admin', 'organizer', 'accounts' ), true ) ) {
			$source = 'admin';
		}

		$role = sanitize_text_field( $args['target_role'] ?? 'lnkup_vendor' );
		$allowed_roles = array( 'lnkup_vendor', 'lnkup_organizer' );
		if ( 'accounts' === $source ) {
			$allowed_roles[] = 'everyone';
		}
		if ( ! in_array( $role, $allowed_roles, true ) ) {
			$role = 'lnkup_vendor';
		}

		$created_by = absint( $args['created_by'] ?? get_current_user_id() );
		$event_id   = absint( $args['event_id'] ?? 0 );
		$opp_id     = absint( $args['opportunity_id'] ?? 0 );

		if ( 'organizer' === $source ) {
			if ( ! LNKup_Roles::is_organizer( $created_by ) && ! LNKup_Capabilities::user_has_manage_all() ) {
				return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
			if ( $event_id && ! LNKup_Capabilities::organizer_owns_event( $event_id, $created_by ) ) {
				return new WP_Error( 'forbidden', __( 'You do not own this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
			$role = 'lnkup_vendor';
		} elseif ( ! LNKup_Capabilities::user_has_manage_all() && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		$now = current_time( 'mysql' );
		$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::CAMPAIGNS );

		$inserted = $wpdb->insert(
			$table,
			array(
				'name'           => $name,
				'target_role'    => $role,
				'message'        => sanitize_textarea_field( $args['message'] ?? '' ),
				'created_by'     => $created_by,
				'source'         => $source,
				'event_id'       => $event_id,
				'opportunity_id' => $opp_id,
				'status'         => self::STATUS_DRAFT,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', __( 'Could not create campaign.', 'lnkup-marketplace' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Import recipients from pasted lines or CSV rows.
	 *
	 * @param int   $campaign_id Campaign ID.
	 * @param array $lines       Raw lines.
	 * @return array|WP_Error
	 */
	public static function import_recipients( $campaign_id, $lines ) {
		global $wpdb;

		$campaign = self::get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return new WP_Error( 'not_found', __( 'Campaign not found.', 'lnkup-marketplace' ) );
		}

		if ( ! self::user_can_manage_campaign( $campaign ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		$max = self::max_recipients_for_campaign( $campaign );
		$parsed = self::parse_recipient_lines( $lines );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( count( $parsed['valid'] ) > $max ) {
			return new WP_Error(
				'limit_exceeded',
				sprintf(
					/* translators: %d: max recipients */
					__( 'Maximum %d recipients allowed for this campaign.', 'lnkup-marketplace' ),
					$max
				)
			);
		}

		$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		$now   = current_time( 'mysql' );
		$added = 0;

		foreach ( $parsed['valid'] as $row ) {
			$email = strtolower( $row['email'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE campaign_id = %d AND email = %s LIMIT 1",
					$campaign_id,
					$email
				)
			);
			if ( $exists ) {
				continue;
			}

			$token = self::generate_unique_token();
			$ok    = $wpdb->insert(
				$table,
				array(
					'campaign_id' => $campaign_id,
					'email'       => $email,
					'name'        => sanitize_text_field( $row['name'] ?? '' ),
					'token'       => $token,
					'status'      => self::REC_PENDING,
					'created_at'  => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( $ok ) {
				++$added;
			}
		}

		self::refresh_campaign_totals( $campaign_id );

		return array(
			'added'    => $added,
			'invalid'  => $parsed['invalid'],
			'duplicates_skipped' => count( $parsed['valid'] ) - $added,
		);
	}

	/**
	 * Parse email lines.
	 *
	 * @param array $lines Lines.
	 * @return array|WP_Error
	 */
	public static function parse_recipient_lines( $lines ) {
		if ( ! is_array( $lines ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $lines );
		}

		$valid   = array();
		$invalid = array();
		$seen    = array();

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}

			$email = '';
			$name  = '';

			if ( false !== strpos( $line, ',' ) ) {
				$parts = str_getcsv( $line );
				$email = sanitize_email( trim( $parts[0] ?? '' ) );
				$name  = sanitize_text_field( trim( $parts[1] ?? '' ) );
			} elseif ( false !== strpos( $line, ';' ) ) {
				$parts = array_map( 'trim', explode( ';', $line, 2 ) );
				$email = sanitize_email( $parts[0] ?? '' );
				$name  = sanitize_text_field( $parts[1] ?? '' );
			} else {
				$email = sanitize_email( $line );
			}

			if ( ! $email || ! is_email( $email ) ) {
				$invalid[] = $line;
				continue;
			}

			$key = strtolower( $email );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$valid[] = array(
				'email' => $key,
				'name'  => $name,
			);
		}

		if ( ! $valid && $invalid ) {
			return new WP_Error( 'no_valid_emails', __( 'No valid email addresses found.', 'lnkup-marketplace' ) );
		}

		return array(
			'valid'   => $valid,
			'invalid' => $invalid,
		);
	}

	/**
	 * Queue campaign for sending.
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	public static function queue_campaign( $campaign_id ) {
		$campaign = self::get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return new WP_Error( 'not_found', __( 'Campaign not found.', 'lnkup-marketplace' ) );
		}
		if ( ! self::user_can_manage_campaign( $campaign ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( (int) $campaign['total'] <= 0 ) {
			return new WP_Error( 'empty', __( 'Add at least one recipient before sending.', 'lnkup-marketplace' ) );
		}

		self::update_campaign( $campaign_id, array( 'status' => self::STATUS_QUEUED ) );
		LNKup_Outreach_Cron::schedule_processing();

		return true;
	}

	/**
	 * Process a batch of pending recipients.
	 *
	 * @param int|null $limit Batch size.
	 */
	public static function process_batch( $limit = null ) {
		global $wpdb;

		$settings = lnkup_get_settings();
		if ( empty( $settings['outreach_emails_enabled'] ) ) {
			return 0;
		}

		$rate_enabled = ! empty( $settings['outreach_rate_limit_enabled'] );
		if ( $rate_enabled ) {
			$limited = self::is_rate_limited();
			if ( 'day' === $limited ) {
				LNKup_Outreach_Cron::schedule_next_day();
				return 0;
			}
			if ( 'hour' === $limited ) {
				LNKup_Outreach_Cron::schedule_next_hour();
				return 0;
			}
		}

		$campaigns_table  = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::CAMPAIGNS );
		$recipients_table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$campaign_id = (int) $wpdb->get_var(
			"SELECT id FROM {$campaigns_table} WHERE status IN ('queued','processing') ORDER BY updated_at ASC LIMIT 1"
		);
		if ( ! $campaign_id ) {
			return 0;
		}

		$campaign = self::get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return 0;
		}

		self::update_campaign( $campaign_id, array( 'status' => self::STATUS_PROCESSING ) );

		if ( $rate_enabled && null === $limit ) {
			$batch_limit = max( 1, (int) ( $settings['outreach_batch_size'] ?? 20 ) );
			$batch_limit = min( $batch_limit, self::remaining_hourly_quota(), self::remaining_daily_quota() );
		} else {
			$batch_limit = null === $limit
				? ( 'organizer' === $campaign['source'] ? 25 : 50 )
				: max( 1, (int) $limit );
		}

		if ( $batch_limit <= 0 ) {
			if ( self::remaining_daily_quota() <= 0 ) {
				LNKup_Outreach_Cron::schedule_next_day();
			} else {
				LNKup_Outreach_Cron::schedule_next_hour();
			}
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$recipients_table} WHERE campaign_id = %d AND status = %s ORDER BY id ASC LIMIT %d",
				$campaign_id,
				self::REC_PENDING,
				$batch_limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			self::finalize_campaign_if_done( $campaign_id );
			return 0;
		}

		$processed     = 0;
		$rate_stopped  = false;
		foreach ( $rows as $row ) {
			if ( $rate_enabled ) {
				$limited = self::is_rate_limited();
				if ( 'day' === $limited ) {
					LNKup_Outreach_Cron::schedule_next_day();
					$rate_stopped = true;
					break;
				}
				if ( 'hour' === $limited ) {
					LNKup_Outreach_Cron::schedule_next_hour();
					$rate_stopped = true;
					break;
				}
			}

			$smtp_sent = self::process_recipient( $row, $campaign );
			if ( $smtp_sent && $rate_enabled ) {
				self::increment_send_counters( 1 );
			}
			++$processed;
		}

		self::refresh_campaign_totals( $campaign_id );
		self::finalize_campaign_if_done( $campaign_id );

		if ( ! $rate_stopped && self::has_pending_recipients( $campaign_id ) ) {
			if ( $rate_enabled && self::is_rate_limited() ) {
				if ( 'day' === self::is_rate_limited() ) {
					LNKup_Outreach_Cron::schedule_next_day();
				} else {
					LNKup_Outreach_Cron::schedule_next_hour();
				}
			} else {
				LNKup_Outreach_Cron::schedule_processing();
			}
		}

		return $processed;
	}

	/**
	 * Send or skip a single recipient.
	 *
	 * @param array $row      Recipient row.
	 * @param array $campaign Campaign row.
	 * @return bool True when an SMTP message was sent (counts toward rate limits).
	 */
	protected static function process_recipient( array $row, array $campaign ) {
		global $wpdb;
		$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		$now   = current_time( 'mysql' );

		if ( 'accounts' === ( $campaign['source'] ?? '' ) ) {
			return self::send_account_email( $row, $campaign );
		}

		$user = get_user_by( 'email', $row['email'] );
		if ( $user ) {
			$handled = self::handle_existing_user( $user, $row, $campaign );
			$wpdb->update(
				$table,
				array(
					'status'  => $handled['status'],
					'user_id' => (int) $user->ID,
					'sent_at' => $now,
					'error_message' => $handled['message'] ?? '',
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);
			return self::REC_SENT === $handled['status'];
		}

		$url = self::build_signup_url( $row['token'], $campaign['target_role'] );
		$sent = LNKup_Notifications::send_platform_outreach(
			$row,
			$url,
			array(
				'campaign'   => $campaign,
				'event_post' => ! empty( $campaign['event_id'] ) ? get_post( (int) $campaign['event_id'] ) : null,
			)
		);

		if ( $sent ) {
			$wpdb->update(
				$table,
				array(
					'status'  => self::REC_SENT,
					'sent_at' => $now,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return true;
		}

		$wpdb->update(
			$table,
			array(
				'status'        => self::REC_FAILED,
				'error_message' => __( 'Email delivery failed.', 'lnkup-marketplace' ),
			),
			array( 'id' => (int) $row['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return false;
	}

	/**
	 * Send a queued admin message to an existing account. No sign-up link.
	 *
	 * @param array $row      Recipient row.
	 * @param array $campaign Campaign row.
	 * @return bool True when the message was sent.
	 */
	protected static function send_account_email( array $row, array $campaign ) {
		global $wpdb;
		$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		$now   = current_time( 'mysql' );
		$email = sanitize_email( $row['email'] ?? '' );

		$subject = str_replace( array( "\r", "\n" ), '', (string) ( $campaign['name'] ?? '' ) );
		$message = (string) ( $campaign['message'] ?? '' );
		$body    = '';
		$lines   = preg_split( "/\r\n|\n|\r/", $message );
		if ( is_array( $lines ) ) {
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$body .= '<p style="margin:0 0 16px;">' . esc_html( $line ) . '</p>';
			}
		}

		$headers = array();
		$admin   = get_userdata( (int) ( $campaign['created_by'] ?? 0 ) );
		if ( $admin ) {
			$reply = sanitize_email( $admin->user_email );
			if ( $reply ) {
				$from_name = str_replace( array( "\r", "\n", '<', '>' ), '', $admin->display_name );
				$headers[] = 'Reply-To: ' . $from_name . ' <' . $reply . '>';
			}
		}

		$sent = ( '' !== $email && '' !== $subject && '' !== $body )
			? (bool) LNKup_Notifications::send( $email, $subject, $body, $headers )
			: false;

		if ( $sent ) {
			$wpdb->update(
				$table,
				array(
					'status'  => self::REC_SENT,
					'user_id' => (int) ( $row['user_id'] ?? 0 ),
					'sent_at' => $now,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			return true;
		}

		$wpdb->update(
			$table,
			array(
				'status'        => self::REC_FAILED,
				'user_id'       => (int) ( $row['user_id'] ?? 0 ),
				'error_message' => __( 'Email delivery failed.', 'lnkup-marketplace' ),
			),
			array( 'id' => (int) $row['id'] ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
		return false;
	}

	/**
	 * Queue account-email recipients without the invite-list cap.
	 *
	 * @param int                                                                 $campaign_id Campaign ID.
	 * @param array<int, array{email:string,name:string,user_id:int}>             $people      Accounts.
	 * @return int Number of rows added.
	 */
	public static function add_account_recipients( $campaign_id, array $people ) {
		global $wpdb;
		$campaign = self::get_campaign( $campaign_id );
		if ( ! $campaign || 'accounts' !== $campaign['source'] ) {
			return 0;
		}
		if ( ! self::user_can_manage_campaign( $campaign ) ) {
			return 0;
		}

		$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		$now   = current_time( 'mysql' );
		$seen  = array();
		$added = 0;

		foreach ( $people as $person ) {
			$email = sanitize_email( $person['email'] ?? '' );
			if ( ! $email ) {
				continue;
			}
			$key = strtolower( $email );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$token = self::generate_unique_token();
			$ok    = $wpdb->insert(
				$table,
				array(
					'campaign_id' => absint( $campaign_id ),
					'email'       => $email,
					'name'        => sanitize_text_field( $person['name'] ?? '' ),
					'token'       => $token,
					'status'      => self::REC_PENDING,
					'user_id'     => absint( $person['user_id'] ?? 0 ),
					'created_at'  => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
			if ( $ok ) {
				++$added;
			}
		}

		self::refresh_campaign_totals( $campaign_id );
		return $added;
	}

	/**
	 * Handle invite for an email that already has an account.
	 *
	 * @param WP_User $user     User.
	 * @param array   $row      Recipient.
	 * @param array   $campaign Campaign.
	 * @return array{status:string,message?:string}
	 */
	protected static function handle_existing_user( WP_User $user, array $row, array $campaign ) {
		if ( 'organizer' === $campaign['source'] && ! empty( $campaign['event_id'] ) && LNKup_Roles::is_vendor( $user->ID ) ) {
			$result = LNKup_Vendor_Invites::create(
				(int) $campaign['created_by'],
				(int) $user->ID,
				array(
					'event_id'       => (int) $campaign['event_id'],
					'opportunity_id' => (int) $campaign['opportunity_id'],
					'message'        => $campaign['message'] ?? '',
				)
			);
			if ( is_wp_error( $result ) ) {
				return array(
					'status'  => self::REC_FAILED,
					'message' => $result->get_error_message(),
				);
			}
			return array( 'status' => self::REC_SENT );
		}

		return array( 'status' => self::REC_SKIPPED );
	}

	/**
	 * Build tracked sign-up URL.
	 *
	 * @param string $token Token.
	 * @param string $role  Target role.
	 */
	public static function build_signup_url( $token, $role ) {
		$role_slug = 'lnkup_organizer' === $role ? 'organizer' : 'vendor';
		return add_query_arg(
			array(
				'outreach' => sanitize_text_field( $token ),
				'role'     => $role_slug,
			),
			lnkup_registration_url()
		);
	}

	/**
	 * @param string $token Token.
	 */
	public static function mark_opened( $token ) {
		global $wpdb;
		$row = self::get_recipient_by_token( $token );
		if ( ! $row || ! self::token_is_valid( $row ) ) {
			return false;
		}
		if ( in_array( $row['status'], array( self::REC_REGISTERED, self::REC_SKIPPED ), true ) ) {
			return true;
		}
		if ( in_array( $row['status'], array( self::REC_SENT, self::REC_PENDING, self::REC_OPENED ), true ) ) {
			$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
			$wpdb->update(
				$table,
				array(
					'status'    => self::REC_OPENED,
					'opened_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
		return true;
	}

	/**
	 * @param int    $user_id User ID.
	 * @param string $token   Token.
	 */
	public static function mark_registered( $user_id, $token ) {
		global $wpdb;
		$row = self::get_recipient_by_token( $token );
		if ( ! $row || ! self::token_is_valid( $row ) ) {
			return false;
		}

		$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		$wpdb->update(
			$table,
			array(
				'status'         => self::REC_REGISTERED,
				'user_id'        => absint( $user_id ),
				'registered_at'  => current_time( 'mysql' ),
			),
			array( 'id' => (int) $row['id'] ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		self::refresh_campaign_totals( (int) $row['campaign_id'] );

		$campaign = self::get_campaign( (int) $row['campaign_id'] );
		$organizer_id = $campaign ? (int) ( $campaign['created_by'] ?? 0 ) : 0;
		if ( class_exists( 'LNKup_Vendor_Import' ) ) {
			$user = get_userdata( $user_id );
			if ( $user && $user->user_email ) {
				LNKup_Vendor_Import::link_prospect_on_registration( $user_id, $user->user_email, $organizer_id, (int) $row['id'] );
			}
		}

		return true;
	}

	/**
	 * Get recipient context for sign-up form.
	 *
	 * @param string $token Token.
	 */
	public static function get_signup_context( $token ) {
		$row = self::get_recipient_by_token( $token );
		if ( ! $row || ! self::token_is_valid( $row ) ) {
			return null;
		}
		$campaign = self::get_campaign( (int) $row['campaign_id'] );
		if ( ! $campaign ) {
			return null;
		}
		return array(
			'email'       => $row['email'],
			'name'        => $row['name'],
			'token'       => $row['token'],
			'target_role' => $campaign['target_role'],
			'message'     => $campaign['message'] ?? '',
		);
	}

	/**
	 * @param string $token Token.
	 */
	public static function get_recipient_by_token( $token ) {
		global $wpdb;
		$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s LIMIT 1", sanitize_text_field( $token ) ),
			ARRAY_A
		);
	}

	/**
	 * @param array $row Recipient row.
	 */
	protected static function token_is_valid( array $row ) {
		$settings = lnkup_get_settings();
		$ttl_days = max( 1, (int) ( $settings['outreach_token_ttl_days'] ?? 30 ) );
		$created  = strtotime( $row['created_at'] ?? '' );
		if ( ! $created ) {
			return false;
		}
		return ( time() - $created ) <= ( $ttl_days * DAY_IN_SECONDS );
	}

	/**
	 * @param int $campaign_id Campaign ID.
	 */
	public static function get_campaign( $campaign_id ) {
		global $wpdb;
		$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::CAMPAIGNS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $campaign_id ) ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * @param int   $campaign_id Campaign ID.
	 * @param array $data        Fields.
	 */
	public static function update_campaign( $campaign_id, array $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::CAMPAIGNS );
		return $wpdb->update( $table, $data, array( 'id' => absint( $campaign_id ) ) );
	}

	/**
	 * Refresh aggregate counters.
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	public static function refresh_campaign_totals( $campaign_id ) {
		global $wpdb;
		$recipients = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS sent,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS skipped,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS registered
				FROM {$recipients} WHERE campaign_id = %d",
				self::REC_SENT,
				self::REC_SKIPPED,
				self::REC_FAILED,
				self::REC_REGISTERED,
				$campaign_id
			),
			ARRAY_A
		);

		self::update_campaign(
			$campaign_id,
			array(
				'total'      => (int) ( $counts['total'] ?? 0 ),
				'sent'       => (int) ( $counts['sent'] ?? 0 ),
				'skipped'    => (int) ( $counts['skipped'] ?? 0 ),
				'failed'     => (int) ( $counts['failed'] ?? 0 ),
				'registered' => (int) ( $counts['registered'] ?? 0 ),
			)
		);
	}

	/**
	 * @param int $campaign_id Campaign ID.
	 */
	protected static function has_pending_recipients( $campaign_id ) {
		global $wpdb;
		$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND status = %s",
				$campaign_id,
				self::REC_PENDING
			)
		) > 0;
	}

	/**
	 * @param int $campaign_id Campaign ID.
	 */
	protected static function finalize_campaign_if_done( $campaign_id ) {
		if ( self::has_pending_recipients( $campaign_id ) ) {
			return;
		}
		$campaign = self::get_campaign( $campaign_id );
		if ( $campaign && in_array( $campaign['status'], array( self::STATUS_QUEUED, self::STATUS_PROCESSING ), true ) ) {
			self::update_campaign( $campaign_id, array( 'status' => self::STATUS_COMPLETED ) );
		}
	}

	/**
	 * List campaigns for admin or organizer.
	 *
	 * @param array $args Query args.
	 */
	public static function list_campaigns( $args = array() ) {
		global $wpdb;
		$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::CAMPAIGNS );
		$where = array( '1=1' );
		$values = array();

		if ( ! empty( $args['created_by'] ) ) {
			$where[]  = 'created_by = %d';
			$values[] = absint( $args['created_by'] );
		}
		if ( ! empty( $args['source'] ) ) {
			$where[]  = 'source = %s';
			$values[] = sanitize_key( $args['source'] );
		}

		$limit = max( 1, min( 100, absint( $args['limit'] ?? 50 ) ) );
		$sql   = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT ' . $limit;
		if ( $values ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			return $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * @param int $campaign_id Campaign ID.
	 * @param int $limit       Limit.
	 */
	public static function list_recipients( $campaign_id, $limit = 100 ) {
		global $wpdb;
		$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, email, name, status, sent_at, opened_at, registered_at, error_message FROM {$table} WHERE campaign_id = %d ORDER BY id DESC LIMIT %d",
				absint( $campaign_id ),
				max( 1, min( 500, absint( $limit ) ) )
			),
			ARRAY_A
		);
	}

	/**
	 * Cancel queued campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	public static function cancel_campaign( $campaign_id ) {
		$campaign = self::get_campaign( $campaign_id );
		if ( ! $campaign || ! self::user_can_manage_campaign( $campaign ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		self::update_campaign( $campaign_id, array( 'status' => self::STATUS_CANCELLED ) );
		return true;
	}

	/**
	 * Retry failed recipients.
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	public static function retry_failed( $campaign_id ) {
		global $wpdb;
		$campaign = self::get_campaign( $campaign_id );
		if ( ! $campaign || ! self::user_can_manage_campaign( $campaign ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, error_message = '' WHERE campaign_id = %d AND status = %s",
				self::REC_PENDING,
				$campaign_id,
				self::REC_FAILED
			)
		);
		self::update_campaign( $campaign_id, array( 'status' => self::STATUS_QUEUED ) );
		LNKup_Outreach_Cron::schedule_processing();
		return true;
	}

	/**
	 * @param array $campaign Campaign row.
	 */
	public static function user_can_manage_campaign( array $campaign ) {
		$user_id = get_current_user_id();
		if ( LNKup_Capabilities::user_has_manage_all() || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return (int) $campaign['created_by'] === $user_id;
	}

	/**
	 * @param array $campaign Campaign row.
	 */
	protected static function max_recipients_for_campaign( array $campaign ) {
		$settings = lnkup_get_settings();
		if ( 'organizer' === $campaign['source'] ) {
			return max( 1, (int) ( $settings['outreach_organizer_max_per_campaign'] ?? 100 ) );
		}
		return max( 1, (int) ( $settings['outreach_admin_max_per_campaign'] ?? 500 ) );
	}

	/**
	 * Generate unique outreach token.
	 */
	protected static function generate_unique_token() {
		global $wpdb;
		$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
		do {
			$token = wp_generate_password( 32, false, false );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE token = %s LIMIT 1", $token ) );
		} while ( $exists );
		return $token;
	}

	/**
	 * Create, import, and queue in one step (organizer REST).
	 *
	 * @param array $args Full payload.
	 */
	public static function create_and_queue( $args ) {
		$campaign_id = self::create_campaign( $args );
		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}

		$lines = $args['recipients'] ?? $args['emails'] ?? '';
		if ( is_string( $lines ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', $lines );
		}

		$import = self::import_recipients( $campaign_id, $lines );
		if ( is_wp_error( $import ) ) {
			return $import;
		}

		$queue = self::queue_campaign( $campaign_id );
		if ( is_wp_error( $queue ) ) {
			return $queue;
		}

		return array(
			'campaign_id' => $campaign_id,
			'import'      => $import,
			'campaign'    => self::get_campaign( $campaign_id ),
		);
	}

	/**
	 * Rate limit settings subset.
	 */
	public static function get_rate_limit_settings() {
		$settings = lnkup_get_settings();
		return array(
			'enabled'        => ! empty( $settings['outreach_rate_limit_enabled'] ),
			'hourly_limit'   => max( 1, (int) ( $settings['outreach_hourly_limit'] ?? 600 ) ),
			'daily_limit'    => max( 1, (int) ( $settings['outreach_daily_limit'] ?? 1500 ) ),
			'batch_size'     => max( 1, (int) ( $settings['outreach_batch_size'] ?? 20 ) ),
			'batch_interval' => max( 60, (int) ( $settings['outreach_batch_interval_seconds'] ?? 180 ) ),
			'resume_time'    => (string) ( $settings['outreach_resume_time'] ?? '08:00' ),
		);
	}

	/**
	 * Current rate-limit status for admin UI.
	 */
	public static function get_rate_limit_status() {
		$limits = self::get_rate_limit_settings();
		$limited = $limits['enabled'] ? self::is_rate_limited() : false;
		$next_run = wp_next_scheduled( LNKup_Outreach_Cron::HOOK );

		return array(
			'enabled'       => $limits['enabled'],
			'sent_hour'     => self::get_sent_count_hour(),
			'sent_day'      => self::get_sent_count_day(),
			'hourly_limit'  => $limits['hourly_limit'],
			'daily_limit'   => $limits['daily_limit'],
			'limited'       => $limited,
			'next_run'      => $next_run ? (int) $next_run : 0,
			'next_resume_at'=> self::get_next_resume_at(),
		);
	}

	/**
	 * @return false|string 'hour', 'day', or false.
	 */
	public static function is_rate_limited() {
		$limits = self::get_rate_limit_settings();
		if ( ! $limits['enabled'] ) {
			return false;
		}
		if ( self::get_sent_count_day() >= $limits['daily_limit'] ) {
			return 'day';
		}
		if ( self::get_sent_count_hour() >= $limits['hourly_limit'] ) {
			return 'hour';
		}
		return false;
	}

	/**
	 * Remaining outreach emails allowed this hour.
	 */
	public static function remaining_hourly_quota() {
		$limits = self::get_rate_limit_settings();
		if ( ! $limits['enabled'] ) {
			return PHP_INT_MAX;
		}
		return max( 0, $limits['hourly_limit'] - self::get_sent_count_hour() );
	}

	/**
	 * Remaining outreach emails allowed today.
	 */
	public static function remaining_daily_quota() {
		$limits = self::get_rate_limit_settings();
		if ( ! $limits['enabled'] ) {
			return PHP_INT_MAX;
		}
		return max( 0, $limits['daily_limit'] - self::get_sent_count_day() );
	}

	/**
	 * Increment outreach SMTP counters after a successful send.
	 *
	 * @param int $count Number sent.
	 */
	public static function increment_send_counters( $count = 1 ) {
		$count = max( 0, (int) $count );
		if ( ! $count ) {
			return;
		}
		$hour_key = self::get_rate_period_key( 'hour' );
		$day_key  = self::get_rate_period_key( 'day' );
		set_transient( 'lnkup_outreach_sent_hour_' . $hour_key, self::get_sent_count_hour() + $count, 2 * HOUR_IN_SECONDS );
		set_transient( 'lnkup_outreach_sent_day_' . $day_key, self::get_sent_count_day() + $count, 2 * DAY_IN_SECONDS );
	}

	/**
	 * @return int
	 */
	public static function get_sent_count_hour() {
		return (int) get_transient( 'lnkup_outreach_sent_hour_' . self::get_rate_period_key( 'hour' ) );
	}

	/**
	 * @return int
	 */
	public static function get_sent_count_day() {
		return (int) get_transient( 'lnkup_outreach_sent_day_' . self::get_rate_period_key( 'day' ) );
	}

	/**
	 * Human-readable next resume time when daily/hourly cap is active.
	 */
	public static function get_next_resume_at() {
		$limited = self::is_rate_limited();
		if ( ! $limited ) {
			$next = wp_next_scheduled( LNKup_Outreach_Cron::HOOK );
			return $next ? wp_date( 'Y-m-d H:i:s', $next ) : '';
		}

		$tz = wp_timezone();
		if ( 'day' === $limited ) {
			$settings = self::get_rate_limit_settings();
			$parts    = array_map( 'intval', explode( ':', $settings['resume_time'] . ':00' ) );
			$next     = new DateTime( 'tomorrow', $tz );
			$next->setTime( max( 0, min( 23, $parts[0] ?? 8 ) ), max( 0, min( 59, $parts[1] ?? 0 ) ), 0 );
			return $next->format( 'Y-m-d H:i:s' );
		}

		$next = new DateTime( 'now', $tz );
		$next->modify( 'next hour' );
		$next->modify( '+5 minutes' );
		return $next->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @param string $period hour|day.
	 */
	protected static function get_rate_period_key( $period ) {
		$now = new DateTime( 'now', wp_timezone() );
		return 'hour' === $period ? $now->format( 'Y-m-d-H' ) : $now->format( 'Y-m-d' );
	}
}
