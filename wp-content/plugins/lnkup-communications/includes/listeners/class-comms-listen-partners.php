<?php
/**
 * Partner program and referral-invite notifications.
 *
 * Sources are lnkup-marketplace server hooks only: partner application transitions, referral
 * attribution and milestones, reward-ledger writes (recorded / approved / marked paid /
 * reversed), Organizer Growth lead stage changes and partner reviews. Core decides what
 * happened; this listener decides who hears about it. Validity rules in LNKup_Comms_Partners
 * re-read the ledger at send time, so a reversed, held or conflicted commission is never sent.
 *
 * Messages carry only the program, a role word, the referred account's display name (already
 * visible on the partner dashboard) and the partner's own commission amount.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Listen_Partners {

	const COMMISSION_DELAY = 15 * MINUTE_IN_SECONDS;
	const GROUP_DELAY      = 30 * MINUTE_IN_SECONDS;
	const REFERRAL_DELAY   = 5 * MINUTE_IN_SECONDS;

	public static function init(): void {
		add_action( 'lnkup_partner_application_submitted', array( __CLASS__, 'on_application_submitted' ), 20, 2 );
		add_action( 'lnkup_partner_application_transitioned', array( __CLASS__, 'on_application_transitioned' ), 20, 4 );
		add_action( 'lnkup_partner_referral_attributed', array( __CLASS__, 'on_referral_attributed' ), 20, 4 );
		add_action( 'lnkup_organizer_recruit_attributed', array( __CLASS__, 'on_invite_attributed' ), 30, 4 );
		add_action( 'lnkup_partner_referral_milestone', array( __CLASS__, 'on_milestone' ), 20, 2 );
		add_action( 'lnkup_partner_commission_recorded', array( __CLASS__, 'on_commission_recorded' ), 20, 4 );
		add_action( 'lnkup_partner_commission_approved', array( __CLASS__, 'on_commission_approved' ), 20, 1 );
		add_action( 'lnkup_partner_commission_paid', array( __CLASS__, 'on_commission_paid' ), 20, 1 );
		add_action( 'lnkup_referral_rewards_reversed', array( __CLASS__, 'on_rewards_reversed' ), 20, 1 );
		add_action( 'lnkup_org_lead_stage_changed', array( __CLASS__, 'on_lead_stage' ), 20, 4 );
		add_action( 'lnkup_org_lead_outcome_changed', array( __CLASS__, 'on_lead_outcome' ), 20, 3 );
		add_action( 'lnkup_partner_review_opened', array( __CLASS__, 'on_review_opened' ), 20, 2 );
	}

	// ------------------------------------------------------------------ Helpers.

	private static function ready(): bool {
		return LNKup_Comms_Partners::available();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function account( int $connector_id ): ?array {
		$row = $connector_id ? LNKup_Connector::get( $connector_id ) : null;
		return $row && (int) $row['user_id'] ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function referral( int $referral_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Treasury_Tables::referrals_table() . ' WHERE id = %d', $referral_id ), ARRAY_A );
		return $row ?: null;
	}

	private static function connector_for_referral( array $referral ): int {
		if ( class_exists( 'LNKup_Commission_Rules' ) ) {
			return (int) LNKup_Commission_Rules::connector_for_referral( $referral );
		}
		return (int) get_user_meta( (int) $referral['referred_user_id'], '_lnkup_referred_by_connector_id', true );
	}

	private static function display_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : '';
	}

	/**
	 * "an organizer" / "a vendor" from a stored target role.
	 */
	private static function role_phrase( string $target_role ): string {
		return false !== strpos( $target_role, 'organizer' ) ? __( 'an organizer', 'lnkup-communications' ) : __( 'a vendor', 'lnkup-communications' );
	}

	private static function program_label( string $program ): string {
		return class_exists( 'LNKup_Partner_Programs' ) ? LNKup_Partner_Programs::label( $program ) : ucwords( str_replace( '_', ' ', $program ) );
	}

	/**
	 * Inbox role for a referral-invite referrer (vendor or organizer).
	 */
	private static function referrer_role( int $user_id ): string {
		$roles = LNKup_Comms_Preferences::user_roles( $user_id );
		if ( in_array( 'vendor', $roles, true ) && ! in_array( 'organizer', $roles, true ) ) {
			return 'vendor';
		}
		return in_array( 'organizer', $roles, true ) ? 'organizer' : 'vendor';
	}

	private static function money( float $amount ): string {
		return '$' . number_format( $amount, 2 );
	}

	private static function today(): string {
		return current_time( 'Y-m-d' );
	}

	// ------------------------------------------------------------------ Applications.

	public static function on_application_submitted( $application_id, $program ): void {
		if ( ! self::ready() ) {
			return;
		}
		$row = LNKup_Connector::get( absint( $application_id ) );
		if ( ! $row ) {
			return;
		}
		foreach ( LNKup_Comms_Partners::admin_recipients() as $admin_id ) {
			LNKup_Comms_Engine::dispatch(
				'a_partner_application',
				$admin_id,
				array(
					'partner_application_id' => (int) $row['id'],
					'applicant_name'         => (string) ( $row['organization'] ?: $row['display_name'] ),
					'program_label'          => self::program_label( (string) $program ),
					'admin_tab'              => 'applications',
				),
				array( 'window' => 'app' . (int) $row['id'], 'source' => 'partner_application' )
			);
		}
	}

	public static function on_application_transitioned( $application_id, $action, $from, $to ): void {
		unset( $from );
		if ( ! self::ready() || 'approve' !== (string) $action || 'active' !== (string) $to ) {
			return;
		}
		$row = self::account( absint( $application_id ) );
		if ( ! $row ) {
			return;
		}
		$code = LNKup_Connector::get_code_for_connector( (int) $row['id'] );
		LNKup_Comms_Engine::dispatch(
			'p_application_approved',
			(int) $row['user_id'],
			array(
				'connector_id'  => (int) $row['id'],
				'program_label' => self::program_label( LNKup_Connector::program_of( $row ) ),
				'referral_code' => (string) ( $code['code'] ?? '' ),
			),
			array( 'window' => 'approved' . (int) $row['id'], 'source' => 'partner_application' )
		);
	}

	// ------------------------------------------------------------------ Referrals.

	public static function on_referral_attributed( $referral_id, $connector_id, $user_id, $program ): void {
		unset( $program );
		if ( ! self::ready() ) {
			return;
		}
		$account  = self::account( absint( $connector_id ) );
		$referral = self::referral( absint( $referral_id ) );
		if ( ! $account || ! $referral ) {
			return;
		}
		LNKup_Comms_Engine::dispatch(
			'p_referral_registered',
			(int) $account['user_id'],
			array(
				'connector_id'  => (int) $account['id'],
				'referral_id'   => (int) $referral['id'],
				'referred_name' => self::display_name( absint( $user_id ) ),
				'referred_role' => self::role_phrase( (string) $referral['target_role'] ),
			),
			// Short delay so the attribution arbiter has resolved conflicts before validity runs.
			array( 'window' => 'ref' . (int) $referral['id'], 'at' => time() + self::REFERRAL_DELAY, 'source' => 'partner_referral' )
		);
	}

	public static function on_invite_attributed( $user_id, $referrer_id, $referral_id, $interest ): void {
		if ( ! self::ready() || ! absint( $referrer_id ) ) {
			return;
		}
		$referrer = absint( $referrer_id );
		LNKup_Comms_Engine::dispatch(
			'r_invite_joined',
			$referrer,
			array(
				'referral_id'   => absint( $referral_id ),
				'referred_name' => self::display_name( absint( $user_id ) ),
				'referred_role' => self::role_phrase( (string) $interest ),
			),
			array( 'role' => self::referrer_role( $referrer ), 'window' => 'inv' . absint( $referral_id ), 'at' => time() + self::REFERRAL_DELAY, 'source' => 'referral_invite' )
		);
	}

	public static function on_milestone( $referral_id, $column ): void {
		if ( ! self::ready() ) {
			return;
		}
		$referral = self::referral( absint( $referral_id ) );
		if ( ! $referral || ! LNKup_Connector::is_account_program( (string) $referral['program'] ) ) {
			return;
		}
		$variant = 'first_event_at' === $column ? 'first_event' : ( 'first_payment_at' === $column ? 'first_paid_booking' : '' );
		if ( '' === $variant || ( 'first_event' === $variant && false === strpos( (string) $referral['target_role'], 'organizer' ) ) ) {
			return;
		}
		$account = self::account( self::connector_for_referral( $referral ) );
		if ( ! $account ) {
			return;
		}
		LNKup_Comms_Engine::dispatch(
			'p_referral_milestone',
			(int) $account['user_id'],
			array(
				'connector_id'  => (int) $account['id'],
				'referral_id'   => (int) $referral['id'],
				'referred_name' => self::display_name( (int) $referral['referred_user_id'] ),
			),
			array( 'variant' => $variant, 'window' => 'ms' . (int) $referral['id'] . $variant, 'source' => 'partner_milestone' )
		);
	}

	// ------------------------------------------------------------------ Commissions.

	public static function on_commission_recorded( $reward_id, $program, $referral_id, $payment_id ): void {
		if ( ! self::ready() ) {
			return;
		}
		$reward = LNKup_Comms_Partners::reward( absint( $reward_id ) );
		if ( ! $reward ) {
			return;
		}
		if ( (int) $reward['connector_id'] > 0 ) {
			$account  = self::account( (int) $reward['connector_id'] );
			$referral = self::referral( absint( $referral_id ) );
			if ( ! $account ) {
				return;
			}
			LNKup_Comms_Engine::dispatch(
				'p_commission_recorded',
				(int) $account['user_id'],
				array(
					'connector_id'      => (int) $account['id'],
					'reward_id'         => (int) $reward['id'],
					'referral_id'       => absint( $referral_id ),
					'commission_amount' => self::money( LNKup_Comms_Partners::net( $reward ) ),
					'referred_name'     => $referral ? self::display_name( (int) $referral['referred_user_id'] ) : '',
				),
				array(
					'window'     => 'rw' . (int) $reward['id'],
					'at'         => time() + self::COMMISSION_DELAY,
					'cancel_key' => 'partner_reward:' . absint( $payment_id ),
					'source'     => 'partner_commission',
				)
			);
			return;
		}
		if ( 'organizer_recruit' === (string) $program ) {
			$user = (int) $reward['user_id'];
			LNKup_Comms_Engine::dispatch(
				'r_credit_earned',
				$user,
				array(
					'referrer_user_id' => $user,
					'credit_date'      => self::today(),
				),
				// One grouped message per referrer per day; validity re-counts live credits at send time.
				array( 'role' => self::referrer_role( $user ), 'window' => 'cr' . str_replace( '-', '', self::today() ), 'at' => time() + self::GROUP_DELAY, 'source' => 'referral_credit' )
			);
		}
	}

	public static function on_commission_approved( $reward_id ): void {
		if ( ! self::ready() ) {
			return;
		}
		$reward  = LNKup_Comms_Partners::reward( absint( $reward_id ) );
		$account = $reward ? self::account( (int) $reward['connector_id'] ) : null;
		if ( ! $account ) {
			return;
		}
		LNKup_Comms_Engine::dispatch(
			'p_commission_approved',
			(int) $account['user_id'],
			array(
				'connector_id'      => (int) $account['id'],
				'reward_id'         => (int) $reward['id'],
				'commission_amount' => self::money( LNKup_Comms_Partners::net( $reward ) ),
			),
			array( 'window' => 'ap' . (int) $reward['id'], 'source' => 'partner_commission' )
		);
	}

	public static function on_commission_paid( $reward_id ): void {
		if ( ! self::ready() ) {
			return;
		}
		$reward  = LNKup_Comms_Partners::reward( absint( $reward_id ) );
		$account = $reward ? self::account( (int) $reward['connector_id'] ) : null;
		if ( ! $account ) {
			return;
		}
		LNKup_Comms_Engine::dispatch(
			'p_payout_recorded',
			(int) $account['user_id'],
			array(
				'connector_id' => (int) $account['id'],
				'paid_date'    => self::today(),
			),
			// Admins usually record several payouts at once: one grouped message per partner per day.
			array( 'window' => 'paid_' . str_replace( '-', '', self::today() ), 'at' => time() + self::GROUP_DELAY, 'source' => 'partner_payout' )
		);
	}

	public static function on_rewards_reversed( $payment_id ): void {
		if ( absint( $payment_id ) ) {
			LNKup_Comms_Engine::cancel( 'partner_reward:' . absint( $payment_id ), array( 'p_commission_recorded' ), 'reward_reversed' );
		}
	}

	// ------------------------------------------------------------------ Leads.

	/**
	 * @return array<string, mixed>|null Lead row with an active assigned partner account.
	 */
	private static function lead_with_partner( int $lead_id ): ?array {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Org_Leads' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$lead = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, assigned_connector_id, referrer_user_id FROM ' . LNKup_Org_Leads::table() . ' WHERE id = %d', $lead_id ), ARRAY_A );
		if ( ! $lead || ! (int) $lead['assigned_connector_id'] ) {
			return null;
		}
		$lead['account'] = self::account( (int) $lead['assigned_connector_id'] );
		return $lead['account'] ? $lead : null;
	}

	private static function dispatch_lead( array $lead, string $variant, string $stage, string $stage_label ): void {
		LNKup_Comms_Engine::dispatch(
			'p_lead_update',
			(int) $lead['account']['user_id'],
			array(
				'connector_id'     => (int) $lead['account']['id'],
				'lead_id'          => (int) $lead['id'],
				'lead_name'        => (string) $lead['name'],
				'lead_stage_label' => $stage_label,
			),
			array( 'variant' => $variant, 'window' => 'lead' . (int) $lead['id'] . '_' . sanitize_key( $stage ), 'source' => 'partner_lead' )
		);
	}

	public static function on_lead_stage( $lead_id, $from, $to, $automatic ): void {
		unset( $from, $automatic );
		if ( ! self::ready() ) {
			return;
		}
		$to      = (string) $to;
		$variant = 'registered' === $to ? 'registered' : ( in_array( $to, array( 'first_event_published', 'first_paid_booking', 'activated', 'repeat_organizer' ), true ) ? 'activated' : '' );
		if ( '' === $variant ) {
			return;
		}
		$lead = self::lead_with_partner( absint( $lead_id ) );
		if ( ! $lead ) {
			return;
		}
		$label = class_exists( 'LNKup_Org_Lead_Pipeline' ) ? ( LNKup_Org_Lead_Pipeline::STAGES[ $to ] ?? $to ) : $to;
		self::dispatch_lead( $lead, $variant, $to, (string) $label );
	}

	public static function on_lead_outcome( $lead_id, $from, $to ): void {
		unset( $from );
		if ( ! self::ready() || ! in_array( (string) $to, array( 'not_interested', 'unqualified', 'archived', 'unresponsive' ), true ) ) {
			return;
		}
		$lead = self::lead_with_partner( absint( $lead_id ) );
		if ( $lead ) {
			self::dispatch_lead( $lead, 'closed', 'closed', '' );
		}
	}

	// ------------------------------------------------------------------ Reviews.

	public static function on_review_opened( $review_id, $type ): void {
		if ( ! self::ready() ) {
			return;
		}
		$labels = array(
			'attribution_conflict' => __( 'referral attribution conflict', 'lnkup-communications' ),
			'clawback'             => __( 'paid commission clawback', 'lnkup-communications' ),
			'manual_adjustment'    => __( 'manual commission adjustment', 'lnkup-communications' ),
		);
		foreach ( LNKup_Comms_Partners::admin_recipients() as $admin_id ) {
			LNKup_Comms_Engine::dispatch(
				'a_partner_review',
				$admin_id,
				array(
					'review_id'    => absint( $review_id ),
					'review_label' => (string) ( $labels[ (string) $type ] ?? __( 'partner review', 'lnkup-communications' ) ),
					'admin_tab'    => 'reviews',
				),
				array( 'window' => 'rev' . absint( $review_id ), 'source' => 'partner_review' )
			);
		}
	}
}
