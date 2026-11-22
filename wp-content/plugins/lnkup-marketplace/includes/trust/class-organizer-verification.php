<?php
/**
 * Organizer verification lifecycle and domain control.
 *
 * `lnkup_organizer_verification_status` (user meta) replaces the boolean
 * `lnkup_organizer_verified`, which is kept in sync for existing readers.
 *
 * Indicators are separate on purpose:
 * - payout_connected: Stripe accepted the payout account (identity checks by Stripe for payments only).
 * - domain_verified:  the organizer proved control of a website domain (email code or DNS TXT).
 * - identity_reviewed: an LNKup reviewer approved the organization.
 * None of them means LNKup guarantees an event.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Verification {

	const META_STATUS          = 'lnkup_organizer_verification_status';
	const META_LEGACY          = 'lnkup_organizer_verified';
	const META_DOMAIN          = 'lnkup_org_verified_domain';
	const META_DOMAIN_PENDING  = 'lnkup_org_domain_pending';
	const META_CONTACT_VERIFIED = 'lnkup_org_contact_email_verified';
	const META_REQUEST         = 'lnkup_org_verification_request';

	const NOT_STARTED             = 'not_started';
	const PENDING_REVIEW          = 'pending_review';
	const INFO_REQUIRED           = 'info_required';
	const VERIFIED                = 'verified';
	const REVERIFICATION_REQUIRED = 'reverification_required';
	const DECLINED                = 'declined';
	const RESTRICTED              = 'restricted';

	const STATUSES = array( self::NOT_STARTED, self::PENDING_REVIEW, self::INFO_REQUIRED, self::VERIFIED, self::REVERIFICATION_REQUIRED, self::DECLINED, self::RESTRICTED );

	/**
	 * Admin decisions → resulting status.
	 */
	const DECISIONS = array(
		'approve'      => self::VERIFIED,
		'request_info' => self::INFO_REQUIRED,
		'decline'      => self::DECLINED,
		'restrict'     => self::RESTRICTED,
		'reverify'     => self::REVERIFICATION_REQUIRED,
	);

	const DOMAIN_CODE_TTL      = 1800;
	const DOMAIN_ATTEMPTS_HOUR = 8;

	/**
	 * True while set_status() writes the legacy meta, so the sync hook ignores our own write.
	 *
	 * @var bool
	 */
	private static $writing_legacy = false;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_backfill' ) );
		add_action( 'added_user_meta', array( __CLASS__, 'on_legacy_meta' ), 10, 4 );
		add_action( 'updated_user_meta', array( __CLASS__, 'on_legacy_meta' ), 10, 4 );
	}

	/**
	 * Older admin tools still toggle `lnkup_organizer_verified`; mirror that into the status.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $user_id    User.
	 * @param string $meta_key   Key.
	 * @param mixed  $meta_value Value.
	 */
	public static function on_legacy_meta( $meta_id, $user_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		if ( self::META_LEGACY !== $meta_key || self::$writing_legacy ) {
			return;
		}
		$target = ! empty( $meta_value ) ? self::VERIFIED : self::NOT_STARTED;
		$prev   = self::get_status( $user_id );
		if ( ! empty( $meta_value ) && self::VERIFIED === $prev ) {
			return;
		}
		if ( empty( $meta_value ) && self::VERIFIED !== $prev ) {
			return;
		}
		self::set_status( absint( $user_id ), $target, 'Changed from the legacy verification toggle.', get_current_user_id() );
	}

	/**
	 * Current status, falling back to the legacy boolean for un-migrated accounts.
	 *
	 * @param int $user_id Organizer.
	 */
	public static function get_status( $user_id ) {
		$status = (string) get_user_meta( absint( $user_id ), self::META_STATUS, true );
		if ( in_array( $status, self::STATUSES, true ) ) {
			return $status;
		}
		return get_user_meta( absint( $user_id ), self::META_LEGACY, true ) ? self::VERIFIED : self::NOT_STARTED;
	}

	public static function is_verified( $user_id ) {
		return self::VERIFIED === self::get_status( $user_id );
	}

	public static function verified_domain( $user_id ) {
		return (string) get_user_meta( absint( $user_id ), self::META_DOMAIN, true );
	}

	public static function status_labels() {
		return array(
			self::NOT_STARTED             => __( 'Not started', 'lnkup-marketplace' ),
			self::PENDING_REVIEW          => __( 'Pending review', 'lnkup-marketplace' ),
			self::INFO_REQUIRED           => __( 'More information needed', 'lnkup-marketplace' ),
			self::VERIFIED                => __( 'Verified by LNKup', 'lnkup-marketplace' ),
			self::REVERIFICATION_REQUIRED => __( 'Reverification needed', 'lnkup-marketplace' ),
			self::DECLINED                => __( 'Not verified', 'lnkup-marketplace' ),
			self::RESTRICTED              => __( 'Restricted', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Separate trust indicators. Public callers only get booleans that are safe to show.
	 *
	 * @param int  $user_id   Organizer.
	 * @param bool $is_public Public context.
	 */
	public static function indicators( $user_id, $is_public = false ) {
		$user_id = absint( $user_id );
		$payout  = class_exists( 'LNKup_Stripe_Connect' ) && LNKup_Stripe_Connect::is_enabled() && LNKup_Stripe_Connect::is_connect_ready( $user_id );
		$out     = array(
			'payout_connected'  => $payout,
			'domain_verified'   => '' !== self::verified_domain( $user_id ),
			'identity_reviewed' => self::is_verified( $user_id ),
		);
		if ( $is_public ) {
			return $out;
		}
		$status = self::get_status( $user_id );
		return array_merge(
			$out,
			array(
				'status'                 => $status,
				'status_label'           => self::status_labels()[ $status ],
				'verified_domain'        => self::verified_domain( $user_id ),
				'contact_email_verified' => (bool) get_user_meta( $user_id, self::META_CONTACT_VERIFIED, true ),
				'domain_pending'         => self::pending_domain_summary( $user_id ),
				'request'                => self::request_summary( $user_id ),
			)
		);
	}

	/**
	 * Organizer asks LNKup to review the organization.
	 *
	 * @param int   $user_id Organizer.
	 * @param array $data    organization_name, website, role_at_org, notes.
	 * @return array|WP_Error
	 */
	public static function request_review( $user_id, array $data ) {
		$user_id = absint( $user_id );
		$status  = self::get_status( $user_id );
		if ( in_array( $status, array( self::VERIFIED, self::PENDING_REVIEW, self::RESTRICTED ), true ) ) {
			return new WP_Error( 'lnkup_verification_not_requestable', __( 'Your verification is already in progress or complete.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( ! LNKup_Trust::rate_limit( 'org_verify_req_' . $user_id, 3, DAY_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', __( 'You can submit up to three verification requests per day.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$request = array(
			'organization_name' => substr( sanitize_text_field( (string) ( $data['organization_name'] ?? '' ) ), 0, 190 ),
			'website'           => esc_url_raw( (string) ( $data['website'] ?? '' ) ),
			'role_at_org'       => substr( sanitize_text_field( (string) ( $data['role_at_org'] ?? '' ) ), 0, 120 ),
			'notes'             => substr( sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ), 0, 2000 ),
			'submitted_at'      => current_time( 'mysql', true ),
		);
		if ( '' === $request['organization_name'] ) {
			return new WP_Error( 'lnkup_verification_name_required', __( 'Add your organization name.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		update_user_meta( $user_id, self::META_REQUEST, $request );
		self::set_status( $user_id, self::PENDING_REVIEW, 'Organizer requested review.', $user_id );
		LNKup_Trust_Cases::open(
			array(
				'case_type'    => 'organizer_verification',
				'severity'     => 'low',
				'subject_type' => 'user',
				'subject_id'   => $user_id,
				'signal_key'   => 'verification_request',
				'summary'      => sprintf( 'Organizer #%d requested verification for "%s".', $user_id, $request['organization_name'] ),
				'evidence'     => array(
					array(
						'source'            => 'organizer_request',
						'website_domain'    => LNKup_Trust::domain_of( $request['website'] ),
						'verified_domain'   => self::verified_domain( $user_id ),
						'payout_connected'  => self::indicators( $user_id, true )['payout_connected'],
						'at'                => $request['submitted_at'],
					),
				),
				'opened_by'    => $user_id,
				'next_step'    => __( 'Check the website, domain control, and public presence. Do not ask for identity documents Stripe already collects.', 'lnkup-marketplace' ),
			)
		);
		return self::indicators( $user_id );
	}

	/**
	 * Admin decision on an organizer.
	 *
	 * @param int    $user_id  Organizer.
	 * @param string $decision approve|request_info|decline|restrict|reverify.
	 * @param string $note     Reviewer note; for request_info / decline it is sent to the organizer.
	 * @param int    $actor    Reviewer.
	 * @return array|WP_Error
	 */
	public static function decide( $user_id, $decision, $note, $actor ) {
		$user_id = absint( $user_id );
		if ( ! LNKup_Roles::is_organizer( $user_id ) ) {
			return new WP_Error( 'lnkup_not_organizer', __( 'That account is not an organizer.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! isset( self::DECISIONS[ $decision ] ) ) {
			return new WP_Error( 'lnkup_bad_decision', __( 'Unknown decision.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$note = trim( sanitize_textarea_field( (string) $note ) );
		if ( mb_strlen( $note ) < 5 ) {
			return new WP_Error( 'lnkup_note_required', __( 'Add a short reason for this decision.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$status = self::DECISIONS[ $decision ];
		self::set_status( $user_id, $status, $note, $actor );
		update_user_meta( $user_id, 'lnkup_organizer_verification_notes', $note );

		global $wpdb;
		$table = LNKup_Trust_Tables::cases_table();
		if ( LNKup_Trust_Tables::exists( $table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$case_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE case_type = 'organizer_verification' AND subject_type = 'user' AND subject_id = %d AND status NOT IN ('resolved','dismissed_false_positive') ORDER BY id DESC LIMIT 1", $user_id ) );
			if ( $case_id ) {
				if ( in_array( $decision, array( 'request_info' ), true ) ) {
					LNKup_Trust_Cases::transition( $case_id, 'info_requested', $note, $actor );
				} else {
					LNKup_Trust_Cases::resolve( $case_id, 'resolved', ucfirst( str_replace( '_', ' ', $decision ) ) . ': ' . $note, $actor );
				}
			}
		}

		$messages = array(
			'approve'      => array( __( 'Your organization is verified on LNKup', 'lnkup-marketplace' ), __( 'LNKup reviewed your organization and marked it verified. Vendors will see the Verified Organizer badge on your profile.', 'lnkup-marketplace' ) ),
			'request_info' => array( __( 'LNKup needs a bit more information', 'lnkup-marketplace' ), __( 'To finish verifying your organization, we need more information:', 'lnkup-marketplace' ) . "\n\n" . $note ),
			'decline'      => array( __( 'Update on your LNKup verification', 'lnkup-marketplace' ), __( 'We could not verify your organization at this time:', 'lnkup-marketplace' ) . "\n\n" . $note . "\n\n" . __( 'You can still publish events. Reply to support if you want another review.', 'lnkup-marketplace' ) ),
			'reverify'     => array( __( 'Please confirm your organization details', 'lnkup-marketplace' ), __( 'Something about your organization changed, so we need you to confirm your details again from the organizer dashboard.', 'lnkup-marketplace' ) ),
			'restrict'     => array( __( 'Your LNKup organizer account needs attention', 'lnkup-marketplace' ), __( 'Some features on your organizer account are paused while LNKup reviews it. Contact support for details.', 'lnkup-marketplace' ) ),
		);
		LNKup_Trust::notify_user( $user_id, $messages[ $decision ][0], $messages[ $decision ][1], 'trust', lnkup_organizer_view_url( 'home' ) );
		return self::indicators( $user_id );
	}

	/**
	 * Start domain verification.
	 *
	 * @param int    $user_id Organizer.
	 * @param string $domain  Domain or website URL.
	 * @param string $method  email|dns.
	 * @param string $email   For method=email: an address at the domain.
	 * @return array|WP_Error Instructions.
	 */
	public static function start_domain( $user_id, $domain, $method, $email = '' ) {
		$user_id = absint( $user_id );
		$domain  = LNKup_Trust::domain_of( $domain );
		if ( '' === $domain ) {
			return new WP_Error( 'lnkup_domain_invalid', __( 'Enter your website domain, for example myfestival.com.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( LNKup_Trust::is_free_mail_domain( $domain ) ) {
			return new WP_Error( 'lnkup_domain_free_mail', __( 'Use your organization\'s own domain, not a free email provider.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! LNKup_Trust::rate_limit( 'org_domain_start_' . $user_id, 5, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many attempts. Try again in an hour.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}

		if ( 'email' === $method ) {
			$email = sanitize_email( $email );
			if ( ! is_email( $email ) || LNKup_Trust::domain_of( $email ) !== $domain ) {
				return new WP_Error( 'lnkup_domain_email_mismatch', __( 'Use an email address at the same domain, for example events@myfestival.com.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			$code = (string) wp_rand( 100000, 999999 );
			update_user_meta(
				$user_id,
				self::META_DOMAIN_PENDING,
				array(
					'domain'     => $domain,
					'method'     => 'email',
					'email'      => strtolower( $email ),
					'code_hash'  => wp_hash_password( $code ),
					'expires_at' => time() + self::DOMAIN_CODE_TTL,
				)
			);
			if ( class_exists( 'LNKup_Notifications' ) ) {
				LNKup_Notifications::send(
					$email,
					__( 'Your LNKup domain verification code', 'lnkup-marketplace' ),
					wpautop(
						esc_html(
							sprintf(
								/* translators: 1: code, 2: domain */
								__( 'Your LNKup verification code is %1$s. Enter it on your organizer dashboard to confirm you manage %2$s. The code expires in 30 minutes. If you did not request this, ignore this email.', 'lnkup-marketplace' ),
								$code,
								$domain
							)
						)
					)
				);
			}
			LNKup_Audit_Log::log( 'org_domain_verification_started', 'user', $user_id, array( 'new' => array( 'domain' => $domain, 'method' => 'email' ) ) );
			return array( 'domain' => $domain, 'method' => 'email', 'sent_to' => self::mask( $email ) );
		}

		if ( 'dns' === $method ) {
			$token = strtolower( wp_generate_password( 24, false, false ) );
			update_user_meta(
				$user_id,
				self::META_DOMAIN_PENDING,
				array(
					'domain'     => $domain,
					'method'     => 'dns',
					'token'      => $token,
					'expires_at' => time() + 7 * DAY_IN_SECONDS,
				)
			);
			LNKup_Audit_Log::log( 'org_domain_verification_started', 'user', $user_id, array( 'new' => array( 'domain' => $domain, 'method' => 'dns' ) ) );
			return array(
				'domain'   => $domain,
				'method'   => 'dns',
				'record'   => array( 'type' => 'TXT', 'host' => $domain, 'value' => 'lnkup-verify=' . $token ),
				'help'     => __( 'Add this TXT record at your domain provider, wait a few minutes, then select Check.', 'lnkup-marketplace' ),
			);
		}

		return new WP_Error( 'lnkup_domain_method', __( 'Choose email or DNS verification.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
	}

	/**
	 * Confirm domain control with the emailed code or the DNS record.
	 *
	 * @param int    $user_id Organizer.
	 * @param string $code    Email code (method=email).
	 * @return array|WP_Error
	 */
	public static function confirm_domain( $user_id, $code = '' ) {
		$user_id = absint( $user_id );
		$pending = get_user_meta( $user_id, self::META_DOMAIN_PENDING, true );
		if ( ! is_array( $pending ) || empty( $pending['domain'] ) ) {
			return new WP_Error( 'lnkup_domain_not_started', __( 'Start domain verification first.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( (int) ( $pending['expires_at'] ?? 0 ) < time() ) {
			delete_user_meta( $user_id, self::META_DOMAIN_PENDING );
			return new WP_Error( 'lnkup_domain_expired', __( 'This verification expired. Start again.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! LNKup_Trust::rate_limit( 'org_domain_confirm_' . $user_id, self::DOMAIN_ATTEMPTS_HOUR, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many attempts. Try again in an hour.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}

		$ok = false;
		if ( 'email' === $pending['method'] ) {
			$code = preg_replace( '/\D/', '', (string) $code );
			$ok   = '' !== $code && wp_check_password( $code, (string) $pending['code_hash'] );
		} elseif ( 'dns' === $pending['method'] ) {
			$ok = self::dns_has_token( (string) $pending['domain'], (string) $pending['token'] );
		}

		if ( ! $ok ) {
			LNKup_Audit_Log::log( 'org_domain_verification_failed', 'user', $user_id, array( 'new' => array( 'domain' => $pending['domain'], 'method' => $pending['method'] ), 'result' => 'failure' ) );
			return new WP_Error(
				'lnkup_domain_not_confirmed',
				'dns' === $pending['method']
					? __( 'We could not find the TXT record yet. DNS changes can take up to an hour.', 'lnkup-marketplace' )
					: __( 'That code is not correct.', 'lnkup-marketplace' ),
				array( 'status' => 400 )
			);
		}

		update_user_meta( $user_id, self::META_DOMAIN, (string) $pending['domain'] );
		if ( 'email' === $pending['method'] ) {
			update_user_meta( $user_id, self::META_CONTACT_VERIFIED, (string) $pending['email'] );
		}
		delete_user_meta( $user_id, self::META_DOMAIN_PENDING );
		LNKup_Audit_Log::log( 'org_domain_verified', 'user', $user_id, array( 'new' => array( 'domain' => $pending['domain'], 'method' => $pending['method'] ) ) );
		return self::indicators( $user_id );
	}

	/**
	 * One-time: migrate the legacy boolean into the status meta. Never downgrades anyone.
	 */
	public static function maybe_backfill() {
		if ( get_option( 'lnkup_org_verification_backfill_v1' ) ) {
			return;
		}
		$ids = get_users(
			array(
				'meta_key'   => self::META_LEGACY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ID',
				'number'     => 500,
			)
		);
		foreach ( $ids as $id ) {
			if ( '' === (string) get_user_meta( (int) $id, self::META_STATUS, true ) ) {
				update_user_meta( (int) $id, self::META_STATUS, self::VERIFIED );
			}
		}
		if ( count( $ids ) < 500 ) {
			update_option( 'lnkup_org_verification_backfill_v1', current_time( 'mysql', true ), false );
		}
	}

	/**
	 * Organizers waiting for review.
	 *
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public static function review_queue( $limit = 50 ) {
		$users = get_users(
			array(
				'meta_key'     => self::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => array( self::PENDING_REVIEW, self::INFO_REQUIRED, self::REVERIFICATION_REQUIRED ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => 'IN',
				'number'       => absint( $limit ),
			)
		);
		$out = array();
		foreach ( $users as $user ) {
			$out[] = array_merge(
				array(
					'user_id' => (int) $user->ID,
					'name'    => $user->display_name,
					'email'   => $user->user_email,
				),
				self::indicators( (int) $user->ID )
			);
		}
		return $out;
	}

	public static function count_pending() {
		$query = new WP_User_Query(
			array(
				'meta_key'     => self::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => array( self::PENDING_REVIEW, self::REVERIFICATION_REQUIRED ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => 'IN',
				'number'       => 1,
				'count_total'  => true,
				'fields'       => 'ID',
			)
		);
		return (int) $query->get_total();
	}

	private static function set_status( $user_id, $status, $reason, $actor ) {
		$prev = self::get_status( $user_id );
		update_user_meta( $user_id, self::META_STATUS, $status );
		self::$writing_legacy = true;
		try {
			if ( self::VERIFIED === $status ) {
				update_user_meta( $user_id, self::META_LEGACY, '1' );
			} else {
				delete_user_meta( $user_id, self::META_LEGACY );
			}
		} finally {
			self::$writing_legacy = false;
		}
		LNKup_Audit_Log::log(
			'organizer_verification_' . $status,
			'user',
			$user_id,
			array(
				'actor_user_id' => absint( $actor ),
				'prev'          => array( 'status' => $prev ),
				'new'           => array( 'status' => $status ),
				'reason'        => $reason,
			)
		);
		do_action( 'lnkup_organizer_verification_changed', $user_id, $prev, $status );
	}

	/**
	 * DNS TXT lookup only; never fetches the organizer's website (no SSRF surface).
	 *
	 * @param string $domain Domain.
	 * @param string $token  Expected token.
	 */
	private static function dns_has_token( $domain, $token ) {
		if ( ! function_exists( 'dns_get_record' ) || '' === $token ) {
			return false;
		}
		$records = @dns_get_record( $domain, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( (array) $records as $record ) {
			$txt = (string) ( $record['txt'] ?? implode( '', (array) ( $record['entries'] ?? array() ) ) );
			if ( hash_equals( 'lnkup-verify=' . $token, trim( $txt ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function pending_domain_summary( $user_id ) {
		$pending = get_user_meta( $user_id, self::META_DOMAIN_PENDING, true );
		if ( ! is_array( $pending ) || empty( $pending['domain'] ) || (int) ( $pending['expires_at'] ?? 0 ) < time() ) {
			return null;
		}
		$out = array( 'domain' => (string) $pending['domain'], 'method' => (string) $pending['method'] );
		if ( 'dns' === $pending['method'] ) {
			$out['record'] = array( 'type' => 'TXT', 'host' => (string) $pending['domain'], 'value' => 'lnkup-verify=' . $pending['token'] );
		} else {
			$out['sent_to'] = self::mask( (string) $pending['email'] );
		}
		return $out;
	}

	private static function request_summary( $user_id ) {
		$request = get_user_meta( $user_id, self::META_REQUEST, true );
		return is_array( $request ) ? $request : null;
	}

	private static function mask( $email ) {
		$at = strpos( (string) $email, '@' );
		return false === $at ? '' : substr( (string) $email, 0, 1 ) . '***' . substr( (string) $email, $at );
	}
}
