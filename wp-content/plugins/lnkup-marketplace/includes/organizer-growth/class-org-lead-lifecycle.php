<?php
/**
 * Lead ↔ organizer account linking and verified milestones.
 *
 * Milestones are written only from authoritative marketplace hooks (event went live,
 * application submitted, payment completed) or a backfill over the same records, and are
 * unique per (lead, milestone). A CRM edit can never create one.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Lead_Lifecycle {

	const MILESTONES = array( 'registered', 'first_event_published', 'first_application', 'first_paid_booking', 'activated', 'repeat_organizer' );

	const PAID_STATUSES = array( 'paid', 'partially_refunded' );

	public static function init(): void {
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 40, 1 );
		add_action( 'set_user_role', array( __CLASS__, 'on_set_user_role' ), 40, 3 );
		add_action( 'lnkup_event_first_live', array( __CLASS__, 'on_event_first_live' ), 20, 2 );
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'on_application_submitted' ), 40, 1 );
		add_action( 'lnkup_payment_completed', array( __CLASS__, 'on_payment_completed' ), 40, 1 );
		add_action( 'lnkup_referral_rewards_reversed', array( __CLASS__, 'on_payment_reversed' ), 40, 1 );
		add_action( 'lnkup_organizer_onboarding_updated', array( __CLASS__, 'on_onboarding_updated' ), 20, 3 );
		add_action( 'lnkup_organizer_onboarding_completed', array( __CLASS__, 'on_onboarding_completed' ), 20, 1 );
	}

	// ------------------------------------------------------------------ Account linking.

	private static function is_organizer_user( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return $user && in_array( 'lnkup_organizer', (array) $user->roles, true );
	}

	public static function on_user_register( $user_id ): void {
		$user_id = (int) $user_id;
		if ( self::is_organizer_user( $user_id ) ) {
			self::auto_link( $user_id );
		}
	}

	/**
	 * Role changes on existing accounts. A brand-new user (no previous roles) is handled on
	 * user_register instead, after the outreach token has been recorded against them.
	 *
	 * @param int|string $user_id   User.
	 * @param string     $role      New role.
	 * @param string[]   $old_roles Previous roles.
	 */
	public static function on_set_user_role( $user_id, $role, $old_roles = array() ): void {
		if ( 'lnkup_organizer' === $role && ! empty( $old_roles ) ) {
			self::auto_link( (int) $user_id );
		}
	}

	/**
	 * Link a new organizer to a lead using, in order: outreach token, referral code, verified
	 * email. A shared business domain is only recorded as a suggestion for an admin to confirm.
	 */
	public static function auto_link( int $user_id ): int {
		global $wpdb;
		if ( ! LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::LEADS ) || LNKup_Org_Leads::lead_for_user( $user_id ) ) {
			return 0;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return 0;
		}

		if ( class_exists( 'LNKup_Outreach_Tables' ) ) {
			$rec = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::RECIPIENTS );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$lead_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT lead_id FROM {$rec} WHERE user_id = %d AND lead_id > 0 ORDER BY registered_at DESC LIMIT 1", $user_id ) );
			if ( $lead_id && self::lead_is_open( $lead_id ) ) {
				$linked = self::link( $lead_id, $user_id, 'outreach_token', 0 );
				if ( ! is_wp_error( $linked ) ) {
					return $lead_id;
				}
			}
		}

		if ( class_exists( 'LNKup_Treasury_Tables' ) ) {
			$refs  = LNKup_Treasury_Tables::referrals_table();
			$codes = LNKup_Treasury_Tables::referral_codes_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$code = (string) $wpdb->get_var( $wpdb->prepare( "SELECT c.code FROM {$refs} r INNER JOIN {$codes} c ON c.id = r.referral_code_id WHERE r.referred_user_id = %d ORDER BY r.id ASC LIMIT 1", $user_id ) );
			if ( '' !== $code ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lead_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Org_Leads::table() . " WHERE referral_code = %s AND merged_into_id = 0 AND outcome NOT IN ('duplicate') ORDER BY id ASC LIMIT 1", $code ) );
				if ( $lead_id ) {
					$linked = self::link( $lead_id, $user_id, 'referral_code', 0 );
					if ( ! is_wp_error( $linked ) ) {
						return $lead_id;
					}
				}
			}
		}

		$email = LNKup_Org_Lead_Dedupe::normalize_email( (string) $user->user_email );
		if ( '' !== $email ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$lead_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Org_Leads::table() . ' WHERE email = %s AND merged_into_id = 0 ORDER BY id ASC LIMIT 1', $email ) );
			if ( $lead_id ) {
				$linked = self::link( $lead_id, $user_id, 'email_match', 0 );
				if ( ! is_wp_error( $linked ) ) {
					return $lead_id;
				}
			}
			$domain = LNKup_Org_Lead_Dedupe::domain_from_email( $email );
			if ( '' !== $domain ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$suggested = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Org_Leads::table() . ' WHERE domain = %s AND merged_into_id = 0 ORDER BY id ASC LIMIT 1', $domain ) );
				if ( $suggested ) {
					LNKup_Org_Leads::log_activity(
						$suggested,
						'account_suggested',
						array(
							'summary' => sprintf( /* translators: %s: email */ __( 'New organizer account %s shares this business domain — confirm before linking', 'lnkup-marketplace' ), $email ),
							'payload' => array( 'user_id' => $user_id ),
						)
					);
				}
			}
		}
		return 0;
	}

	private static function lead_is_open( int $lead_id ): bool {
		$lead = LNKup_Org_Leads::get_row( $lead_id );
		return $lead && ! (int) $lead['merged_into_id'];
	}

	/**
	 * When a lead is created for a business that already has an organizer account (same email).
	 */
	public static function match_existing_account( int $lead_id ): void {
		$lead = LNKup_Org_Leads::get_row( $lead_id );
		if ( ! $lead || '' === (string) $lead['email'] ) {
			return;
		}
		$user = get_user_by( 'email', (string) $lead['email'] );
		if ( $user && self::is_organizer_user( (int) $user->ID ) && ! LNKup_Org_Leads::lead_for_user( (int) $user->ID ) ) {
			self::link( $lead_id, (int) $user->ID, 'email_match', 0 );
		}
	}

	/**
	 * @return true|WP_Error
	 */
	public static function link( int $lead_id, int $user_id, string $method, int $actor ) {
		global $wpdb;
		$lead = LNKup_Org_Leads::get_row( $lead_id );
		if ( ! $lead || (int) $lead['merged_into_id'] ) {
			return new WP_Error( 'lnkup_lead_not_found', __( 'Lead not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'lnkup_user_not_found', __( 'That LNKup account does not exist.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! self::is_organizer_user( $user_id ) ) {
			return new WP_Error( 'lnkup_not_organizer', __( 'Only organizer accounts can be linked to an organizer lead.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$existing = LNKup_Org_Leads::lead_for_user( $user_id );
		if ( $existing && $existing !== $lead_id ) {
			return new WP_Error( 'lnkup_account_linked_elsewhere', __( 'This account already belongs to another organization lead.', 'lnkup-marketplace' ), array( 'status' => 409, 'lead_id' => $existing ) );
		}
		if ( $existing === $lead_id ) {
			return true;
		}
		$table = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACCOUNTS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (lead_id, user_id, link_method, status, linked_by, created_at) VALUES (%d, %d, %s, 'linked', %d, %s)",
				$lead_id,
				$user_id,
				sanitize_key( $method ),
				$actor,
				LNKup_Org_Growth::now()
			)
		);
		if ( 1 !== (int) $inserted ) {
			return new WP_Error( 'lnkup_account_linked_elsewhere', __( 'This account already belongs to another organization lead.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		LNKup_Org_Leads::log_activity(
			$lead_id,
			'account_linked',
			array(
				'actor'   => $actor,
				'summary' => sprintf( /* translators: 1: user name, 2: method */ __( 'Linked organizer account %1$s (%2$s)', 'lnkup-marketplace' ), $user->display_name, str_replace( '_', ' ', $method ) ),
				'payload' => array(
					'user_id' => $user_id,
					'method'  => $method,
				),
			)
		);
		if ( in_array( $user_id, LNKup_Org_Growth::house_organizer_ids(), true ) ) {
			$wpdb->update( LNKup_Org_Leads::table(), array( 'is_house_affiliated' => 1 ), array( 'id' => $lead_id ) );
		}
		self::record( $lead_id, 'registered', $user_id, $user_id, (string) $user->user_registered );
		self::backfill( $lead_id );
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function unlink( int $lead_id, int $user_id, int $actor ) {
		global $wpdb;
		$deleted = $wpdb->delete( LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACCOUNTS ), array( 'lead_id' => $lead_id, 'user_id' => $user_id ) );
		if ( ! $deleted ) {
			return new WP_Error( 'lnkup_account_not_linked', __( 'That account is not linked to this lead.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$wpdb->delete( LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::MILESTONES ), array( 'lead_id' => $lead_id ) );
		LNKup_Org_Leads::log_activity( $lead_id, 'account_unlinked', array( 'actor' => $actor, 'summary' => sprintf( /* translators: %d: user id */ __( 'Unlinked account #%d', 'lnkup-marketplace' ), $user_id ), 'payload' => array( 'user_id' => $user_id ) ) );
		LNKup_Org_Leads::refresh_house_flags();
		if ( LNKup_Org_Leads::linked_user_ids( $lead_id ) ) {
			foreach ( LNKup_Org_Leads::linked_user_ids( $lead_id ) as $uid ) {
				$user = get_userdata( $uid );
				self::record( $lead_id, 'registered', $uid, $uid, $user ? (string) $user->user_registered : '' );
			}
			self::backfill( $lead_id );
		}
		LNKup_Org_Growth_Metrics::flush_cache();
		return true;
	}

	// ------------------------------------------------------------------ Milestones.

	/**
	 * Insert a milestone once; advances the stage forward when newly recorded.
	 */
	public static function record( int $lead_id, string $milestone, int $user_id, int $source_object_id, string $achieved_at = '' ): bool {
		global $wpdb;
		if ( ! in_array( $milestone, self::MILESTONES, true ) ) {
			return false;
		}
		$when  = '' !== $achieved_at && strtotime( $achieved_at ) ? $achieved_at : LNKup_Org_Growth::now();
		$table = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::MILESTONES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (lead_id, milestone, user_id, source_object_id, status, achieved_at) VALUES (%d, %s, %d, %d, 'active', %s)",
				$lead_id,
				$milestone,
				$user_id,
				$source_object_id,
				$when
			)
		);
		if ( 1 !== $inserted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$reactivated = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'active', source_object_id = %d WHERE lead_id = %d AND milestone = %s AND status <> 'active'", $source_object_id, $lead_id, $milestone ) );
			if ( 1 !== $reactivated ) {
				return false;
			}
		}
		$stage = LNKup_Org_Lead_Pipeline::MILESTONE_TO_STAGE[ $milestone ] ?? '';
		if ( '' !== $stage ) {
			LNKup_Org_Leads::advance_to( $lead_id, $stage, $milestone );
		}
		LNKup_Org_Leads::log_activity(
			$lead_id,
			'milestone',
			array(
				'summary' => sprintf( /* translators: %s: milestone */ __( 'Milestone reached: %s', 'lnkup-marketplace' ), LNKup_Org_Lead_Pipeline::STAGES[ $stage ] ?? $milestone ),
				'payload' => array(
					'milestone' => $milestone,
					'object_id' => $source_object_id,
				),
			)
		);
		LNKup_Org_Growth_Metrics::flush_cache();
		return true;
	}

	/**
	 * Whether the lead holds an active milestone.
	 */
	public static function has( int $lead_id, string $milestone ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::MILESTONES ) . " WHERE lead_id = %d AND milestone = %s AND status = 'active'", $lead_id, $milestone ) );
	}

	public static function on_event_first_live( $event_id, $organizer_id ): void {
		$lead_id = LNKup_Org_Leads::lead_for_user( (int) $organizer_id );
		if ( ! $lead_id ) {
			return;
		}
		self::record( $lead_id, 'first_event_published', (int) $organizer_id, (int) $event_id );
		self::evaluate_repeat( $lead_id );
	}

	/**
	 * Guided setup: note what the organizer runs on their lead. Activity only — lead stages and
	 * milestones still come from real events and payments (a 12-stop tour is one organizer).
	 *
	 * @param int   $user_id Organizer.
	 * @param array $state   New onboarding state.
	 * @param array $before  Previous state.
	 */
	public static function on_onboarding_updated( $user_id, $state, $before ): void {
		$formats = (array) ( $state['formats'] ?? array() );
		if ( ! $formats || $formats === (array) ( $before['formats'] ?? array() ) ) {
			return;
		}
		$lead_id = LNKup_Org_Leads::lead_for_user( (int) $user_id );
		if ( $lead_id ) {
			LNKup_Org_Leads::log_activity(
				$lead_id,
				'onboarding_format_selected',
				array(
					'channel' => 'product',
					'summary' => sprintf( 'Organizes: %s', implode( ', ', array_map( 'sanitize_key', $formats ) ) ),
					'payload' => array( 'formats' => array_values( $formats ) ),
					'actor'   => (int) $user_id,
				)
			);
		}
	}

	/**
	 * @param int $user_id Organizer.
	 */
	public static function on_onboarding_completed( $user_id ): void {
		$lead_id = LNKup_Org_Leads::lead_for_user( (int) $user_id );
		if ( $lead_id ) {
			LNKup_Org_Leads::log_activity(
				$lead_id,
				'onboarding_completed',
				array(
					'channel' => 'product',
					'summary' => 'Finished guided setup (profile, first event, payouts, published)',
					'actor'   => (int) $user_id,
				)
			);
		}
	}

	public static function on_application_submitted( $app_id ): void {
		$app_id       = (int) $app_id;
		$organizer_id = (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true );
		$lead_id      = $organizer_id ? LNKup_Org_Leads::lead_for_user( $organizer_id ) : 0;
		if ( $lead_id ) {
			self::record( $lead_id, 'first_application', $organizer_id, $app_id );
		}
	}

	public static function on_payment_completed( $payment_id ): void {
		$payment_id = (int) $payment_id;
		if ( ! in_array( (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ), self::PAID_STATUSES, true ) ) {
			return;
		}
		$organizer_id = self::organizer_for_payment( $payment_id );
		$lead_id      = $organizer_id ? LNKup_Org_Leads::lead_for_user( $organizer_id ) : 0;
		if ( ! $lead_id ) {
			return;
		}
		self::record( $lead_id, 'first_paid_booking', $organizer_id, $payment_id );
		self::evaluate_activation( $lead_id );
	}

	/**
	 * A refund or lost chargeback may leave the organization with no paid bookings.
	 */
	public static function on_payment_reversed( $payment_id ): void {
		$organizer_id = self::organizer_for_payment( (int) $payment_id );
		$lead_id      = $organizer_id ? LNKup_Org_Leads::lead_for_user( $organizer_id ) : 0;
		if ( $lead_id ) {
			self::reconcile_paid_milestones( $lead_id );
		}
	}

	private static function organizer_for_payment( int $payment_id ): int {
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		if ( ! $organizer_id ) {
			$event_id     = (int) get_post_meta( $payment_id, '_lnkup_event_id', true );
			$organizer_id = $event_id ? (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) : 0;
		}
		return $organizer_id;
	}

	/**
	 * Paid (not fully refunded / charged back) bookings across the organization's accounts.
	 *
	 * @param int[] $user_ids Organizer users.
	 * @return array<int, array{id:int,vendor:int,date:string}>
	 */
	public static function paid_payments( array $user_ids ): array {
		global $wpdb;
		$user_ids = array_values( array_filter( array_map( 'intval', $user_ids ) ) );
		if ( ! $user_ids ) {
			return array();
		}
		$in = implode( ',', $user_ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_date, v.meta_value AS vendor FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_organizer_user_id' AND o.meta_value IN ({$in})
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_payment_status' AND s.meta_value IN ('paid','partially_refunded')
			LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_lnkup_vendor_user_id'
			WHERE p.post_type = 'lnkup_payment' ORDER BY p.post_date ASC LIMIT 5000",
			ARRAY_A
		);
		return array_map(
			static fn( $r ) => array(
				'id'     => (int) $r['ID'],
				'vendor' => (int) $r['vendor'],
				'date'   => (string) $r['post_date'],
			),
			(array) $rows
		);
	}

	/**
	 * Events of the organization that have gone live.
	 *
	 * @param int[] $user_ids Organizer users.
	 * @return array<int, array{id:int,date:string}>
	 */
	public static function live_events( array $user_ids ): array {
		global $wpdb;
		$user_ids = array_values( array_filter( array_map( 'intval', $user_ids ) ) );
		if ( ! $user_ids ) {
			return array();
		}
		$in = implode( ',', $user_ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT p.ID, COALESCE(f.meta_value, p.post_date) AS live_at FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_organizer_user_id' AND o.meta_value IN ({$in})
			LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_lnkup_first_published_at'
			LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_event_status'
			WHERE p.post_type = 'lnkup_event' AND p.post_status = 'publish' AND ( f.meta_value IS NOT NULL OR s.meta_value IN ('live','completed','archived') )
			ORDER BY live_at ASC LIMIT 2000",
			ARRAY_A
		);
		return array_map(
			static fn( $r ) => array(
				'id'   => (int) $r['ID'],
				'date' => (string) $r['live_at'],
			),
			(array) $rows
		);
	}

	public static function evaluate_activation( int $lead_id ): void {
		$needed = max( 1, (int) LNKup_Org_Growth::setting( 'org_growth_activation_paid_bookings' ) );
		$paid   = self::paid_payments( LNKup_Org_Leads::linked_user_ids( $lead_id ) );
		if ( count( $paid ) >= $needed ) {
			$last = $paid[ $needed - 1 ];
			self::record( $lead_id, 'activated', 0, $last['id'], $last['date'] );
		}
	}

	public static function evaluate_repeat( int $lead_id ): void {
		$events = self::live_events( LNKup_Org_Leads::linked_user_ids( $lead_id ) );
		if ( count( $events ) >= 2 ) {
			self::record( $lead_id, 'repeat_organizer', 0, $events[1]['id'], $events[1]['date'] );
		}
	}

	/**
	 * Void payment milestones that no longer have a paid booking behind them.
	 */
	public static function reconcile_paid_milestones( int $lead_id ): void {
		global $wpdb;
		$paid   = self::paid_payments( LNKup_Org_Leads::linked_user_ids( $lead_id ) );
		$needed = max( 1, (int) LNKup_Org_Growth::setting( 'org_growth_activation_paid_bookings' ) );
		$table  = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::MILESTONES );
		$void   = array();
		if ( ! $paid ) {
			$void[] = 'first_paid_booking';
		}
		if ( count( $paid ) < $needed ) {
			$void[] = 'activated';
		}
		foreach ( $void as $milestone ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'voided' WHERE lead_id = %d AND milestone = %s AND status = 'active'", $lead_id, $milestone ) );
			if ( $changed ) {
				LNKup_Org_Leads::log_activity(
					$lead_id,
					'milestone_voided',
					array(
						'summary' => sprintf( /* translators: %s: milestone */ __( 'Milestone voided after refund: %s', 'lnkup-marketplace' ), $milestone ),
						'payload' => array( 'milestone' => $milestone ),
					)
				);
			}
		}
		if ( $void ) {
			LNKup_Org_Growth_Metrics::flush_cache();
		}
	}

	/**
	 * Derive milestones from existing marketplace records (after linking or merging).
	 */
	public static function backfill( int $lead_id ): void {
		global $wpdb;
		$users = LNKup_Org_Leads::linked_user_ids( $lead_id );
		if ( ! $users ) {
			return;
		}
		$events = self::live_events( $users );
		if ( $events ) {
			$first = $events[0];
			self::record( $lead_id, 'first_event_published', (int) get_post_meta( $first['id'], '_lnkup_organizer_user_id', true ), $first['id'], $first['date'] );
			self::evaluate_repeat( $lead_id );
		}
		$in = implode( ',', array_map( 'intval', $users ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$app = $wpdb->get_row(
			"SELECT p.ID, p.post_date, o.meta_value AS organizer FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_organizer_user_id' AND o.meta_value IN ({$in})
			WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft') ORDER BY p.post_date ASC LIMIT 1",
			ARRAY_A
		);
		if ( $app ) {
			self::record( $lead_id, 'first_application', (int) $app['organizer'], (int) $app['ID'], (string) $app['post_date'] );
		}
		$paid = self::paid_payments( $users );
		if ( $paid ) {
			self::record( $lead_id, 'first_paid_booking', self::organizer_for_payment( $paid[0]['id'] ), $paid[0]['id'], $paid[0]['date'] );
			self::evaluate_activation( $lead_id );
		}
	}
}
