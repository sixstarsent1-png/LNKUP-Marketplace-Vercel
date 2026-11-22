<?php
/**
 * Partner program validity rules and links.
 *
 * Every rule re-reads the canonical lnkup-marketplace rows (partner accounts, referrals, reward
 * ledger, leads, reviews) at send time, so a commission that was reversed, held or lost an
 * attribution conflict after it was scheduled is never announced as earned.
 *
 * Partner context keys are namespaced (`connector_id`, `referral_id`, `reward_id`,
 * `partner_application_id`, `lead_id`, `review_id`) so they never collide with the booking keys
 * that LNKup_Comms_Context::build() resolves (application, payment, event).
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Partners {

	const RULES = array(
		'partner_account_active',
		'partner_referral_winning',
		'partner_reward_live',
		'partner_reward_approved',
		'partner_payout_recorded',
		'partner_lead_owned',
		'partner_application_pending',
		'partner_review_open',
		'referrer_credit_live',
	);

	const LINKS = array( 'partner_dashboard', 'partner_earnings', 'partner_referrals', 'partner_leads', 'referral_invites', 'partner_admin' );

	public static function available(): bool {
		return class_exists( 'LNKup_Connector' ) && class_exists( 'LNKup_Treasury_Tables' );
	}

	public static function handles_rule( string $rule ): bool {
		return in_array( $rule, self::RULES, true );
	}

	public static function handles_link( string $link ): bool {
		return in_array( $link, self::LINKS, true );
	}

	// ------------------------------------------------------------------ Validity.

	/**
	 * @return bool True when the message is still accurate.
	 */
	public static function valid( string $rule, array $ctx ): bool {
		if ( ! self::available() ) {
			return false;
		}
		switch ( $rule ) {
			case 'partner_account_active':
				return self::account_active( (int) ( $ctx['connector_id'] ?? 0 ) );
			case 'partner_referral_winning':
				return self::referral_winning( (int) ( $ctx['referral_id'] ?? 0 ) );
			case 'partner_reward_live':
				$reward = self::reward( (int) ( $ctx['reward_id'] ?? 0 ) );
				return $reward
					&& in_array( (string) $reward['reward_status'], array( 'pending', 'approved' ), true )
					&& self::net( $reward ) > 0
					&& self::referral_winning( (int) $reward['referral_id'] );
			case 'partner_reward_approved':
				$reward = self::reward( (int) ( $ctx['reward_id'] ?? 0 ) );
				return $reward && 'approved' === (string) $reward['reward_status'] && self::net( $reward ) > 0;
			case 'partner_payout_recorded':
				return self::paid_count( (int) ( $ctx['connector_id'] ?? 0 ), (string) ( $ctx['paid_date'] ?? '' ) ) > 0;
			case 'partner_lead_owned':
				return self::lead_owned( (int) ( $ctx['lead_id'] ?? 0 ), (int) ( $ctx['connector_id'] ?? 0 ) );
			case 'partner_application_pending':
				$row = LNKup_Connector::get( (int) ( $ctx['partner_application_id'] ?? 0 ) );
				return $row && in_array( (string) $row['status'], array( 'pending', 'under_review', 'info_needed' ), true );
			case 'partner_review_open':
				$review = class_exists( 'LNKup_Partner_Reviews' ) ? LNKup_Partner_Reviews::get( (int) ( $ctx['review_id'] ?? 0 ) ) : null;
				return $review && 'open' === (string) $review['status'];
			case 'referrer_credit_live':
				return self::credit_count( (int) ( $ctx['referrer_user_id'] ?? 0 ), (string) ( $ctx['credit_date'] ?? '' ) ) > 0;
		}
		return true;
	}

	private static function account_active( int $connector_id ): bool {
		$row = $connector_id ? LNKup_Connector::get( $connector_id ) : null;
		return $row && 'active' === (string) $row['status'];
	}

	/**
	 * Winning, or not yet resolved by the arbiter. Superseded and conflicted claims never notify.
	 */
	public static function referral_winning( int $referral_id ): bool {
		global $wpdb;
		if ( ! $referral_id ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Treasury_Tables::referrals_table() . ' WHERE id = %d', $referral_id ), ARRAY_A );
		if ( ! $row ) {
			return false;
		}
		return in_array( (string) ( $row['attribution_status'] ?? '' ), array( '', 'winning' ), true );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function reward( int $reward_id ): ?array {
		global $wpdb;
		if ( ! $reward_id ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Treasury_Tables::referral_rewards_table() . ' WHERE id = %d', $reward_id ), ARRAY_A );
		return $row ?: null;
	}

	public static function net( array $reward ): float {
		return round( max( 0.0, (float) $reward['amount'] - (float) ( $reward['reversed_amount'] ?? 0 ) ), 2 );
	}

	private static function paid_count( int $connector_id, string $date ): int {
		global $wpdb;
		if ( ! $connector_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Treasury_Tables::referral_rewards_table() . " WHERE connector_id = %d AND reward_status = 'paid' AND paid_at >= %s AND paid_at < %s", $connector_id, $date . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) ) . ' 00:00:00' ) );
	}

	private static function credit_count( int $user_id, string $date ): int {
		global $wpdb;
		if ( ! $user_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Treasury_Tables::referral_rewards_table() . " WHERE user_id = %d AND program = 'organizer_recruit' AND connector_id = 0 AND reward_status NOT IN ('reversed') AND amount > reversed_amount AND created_at >= %s AND created_at < %s", $user_id, $date . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) ) . ' 00:00:00' ) );
	}

	public static function lead_owned( int $lead_id, int $connector_id ): bool {
		global $wpdb;
		if ( ! $lead_id || ! $connector_id || ! class_exists( 'LNKup_Org_Leads' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT assigned_connector_id, merged_into_id FROM ' . LNKup_Org_Leads::table() . ' WHERE id = %d', $lead_id ), ARRAY_A );
		return $row && 0 === (int) $row['merged_into_id'] && (int) $row['assigned_connector_id'] === $connector_id;
	}

	// ------------------------------------------------------------------ Links.

	public static function web_url( string $link, array $ctx, string $role ): string {
		$dashboard = function_exists( 'lnkup_page_url_helper' ) ? (string) lnkup_page_url_helper( 'connector-dashboard' ) : home_url( '/connector-dashboard/' );
		switch ( $link ) {
			case 'partner_admin':
				$tab = in_array( (string) ( $ctx['admin_tab'] ?? '' ), array( 'applications', 'reviews' ), true ) ? (string) $ctx['admin_tab'] : 'overview';
				return admin_url( 'admin.php?page=lnkup-partnerships&tab=' . $tab );
			case 'partner_earnings':
				return add_query_arg( 'view', 'earnings', $dashboard );
			case 'partner_referrals':
				return add_query_arg( 'view', 'referrals', $dashboard );
			case 'partner_leads':
				return add_query_arg( 'view', 'leads', $dashboard );
			case 'referral_invites':
				if ( 'organizer' === $role ) {
					$org = function_exists( 'lnkup_page_url_helper' ) ? (string) lnkup_page_url_helper( 'organizer-dashboard' ) : home_url( '/organizer-dashboard/' );
					return $org . '#referrals';
				}
				$vendor = function_exists( 'lnkup_page_url_helper' ) ? (string) lnkup_page_url_helper( 'vendor-dashboard' ) : home_url( '/vendor-dashboard/' );
				return add_query_arg( 'view', 'referrals', $vendor );
			default:
				return $dashboard;
		}
	}

	/** Partner home routes in the mobile app's `(partner)` tab group. */
	const PARTNER_ROUTES = array(
		'partner_dashboard' => '/(partner)',
		'partner_referrals' => '/(partner)/referrals',
		'partner_leads'     => '/(partner)/leads',
		'partner_earnings'  => '/(partner)/earnings',
	);

	/**
	 * App routes. Partner messages open the Partner home; referral invites open the vendor or
	 * organizer Referral Invites screen. Admin notices are email only.
	 */
	public static function deep_link( string $link, array $ctx, string $role ): string {
		if ( isset( self::PARTNER_ROUTES[ $link ] ) ) {
			return self::PARTNER_ROUTES[ $link ];
		}
		if ( 'referral_invites' === $link ) {
			return 'organizer' === $role ? '/(organizer)/referrals' : '/(vendor)/referrals';
		}
		return 'organizer' === $role ? '/(organizer)/notifications' : '/(vendor)/notifications';
	}

	// ------------------------------------------------------------------ Recipients.

	/**
	 * LNKup admins who receive partner operations alerts.
	 *
	 * @return int[]
	 */
	public static function admin_recipients(): array {
		$ids = get_users(
			array(
				'role__in' => array( 'administrator', 'lnkup_admin' ),
				'fields'   => 'ID',
				'number'   => 20,
				'orderby'  => 'ID',
			)
		);
		/**
		 * Filter who receives partner operations alerts.
		 *
		 * @param int[] $ids Admin user IDs.
		 */
		return array_values( array_map( 'intval', (array) apply_filters( 'lnkup_comms_partner_admin_recipients', (array) $ids ) ) );
	}
}
