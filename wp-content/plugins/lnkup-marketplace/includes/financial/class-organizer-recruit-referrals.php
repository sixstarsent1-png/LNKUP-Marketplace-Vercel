<?php
/**
 * Organizer recruitment referrals — 12-month share of platform fees.
 *
 * Program: organizer_recruit
 * Referrers: vendors and organizers. Recruits: outside organizers.
 * Reward: percent of LNKup platform take (vendor fee + organizer commission)
 * for every paid booth transaction while the referral window is active.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Organizer recruit referral program.
 */
class LNKup_Organizer_Recruit_Referrals {

	const PROGRAM     = 'organizer_recruit';
	const COOKIE      = 'lnkup_org_ref';
	const QUERY_PARAM = 'org_ref';
	const META_EXPIRES = '_lnkup_org_recruit_expires_at';
	const EVENT_TYPE  = 'organizer_recruit_fee_share';

	/**
	 * Bootstrap hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_migrate_schema' ), 4 );
		add_action( 'init', array( __CLASS__, 'capture_cookie' ), 5 );
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 25, 1 );
		add_action( 'set_user_role', array( __CLASS__, 'on_set_user_role' ), 20, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'on_add_user_role' ), 20, 2 );
		add_action( 'wp_login', array( __CLASS__, 'on_wp_login' ), 20, 2 );
		add_action( 'lnkup_payment_completed', array( __CLASS__, 'on_payment_completed' ), 25, 1 );
	}

	/**
	 * Settings helpers.
	 */
	public static function is_enabled(): bool {
		$settings = get_option( 'lnkup_settings', array() );
		if ( isset( $settings['organizer_recruit_enabled'] ) ) {
			return ! empty( $settings['organizer_recruit_enabled'] );
		}
		return true;
	}

	/**
	 * Commission percent of platform take (default 20).
	 */
	public static function commission_percent(): float {
		$settings = get_option( 'lnkup_settings', array() );
		$pct      = isset( $settings['organizer_recruit_percent'] )
			? (float) $settings['organizer_recruit_percent']
			: 20.0;
		return max( 0.0, min( 100.0, $pct ) );
	}

	/**
	 * Attribution window in months (default 12).
	 */
	public static function window_months(): int {
		$settings = get_option( 'lnkup_settings', array() );
		$months   = isset( $settings['organizer_recruit_months'] )
			? (int) $settings['organizer_recruit_months']
			: 12;
		return max( 1, min( 36, $months ) );
	}

	/**
	 * Ensure reward columns exist on older installs.
	 */
	public static function maybe_migrate_schema(): void {
		$flag = 'lnkup_org_recruit_schema_v1';
		if ( get_option( $flag ) ) {
			return;
		}
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return;
		}
		$cols = $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cols = array_map( 'strtolower', (array) $cols );
		$alters = array();
		if ( ! in_array( 'payment_id', $cols, true ) ) {
			$alters[] = 'ADD COLUMN payment_id bigint(20) unsigned NOT NULL DEFAULT 0';
		}
		if ( ! in_array( 'event_id', $cols, true ) ) {
			$alters[] = 'ADD COLUMN event_id bigint(20) unsigned NOT NULL DEFAULT 0';
		}
		if ( ! in_array( 'program', $cols, true ) ) {
			$alters[] = "ADD COLUMN program varchar(32) NOT NULL DEFAULT ''";
		}
		foreach ( $alters as $sql ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} {$sql}" );
		}
		update_option( $flag, 1, false );
	}

	/**
	 * Capture ?org_ref= into cookie.
	 */
	public static function capture_cookie(): void {
		if ( empty( $_GET[ self::QUERY_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$code = sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $code ) {
			return;
		}
		$row = self::resolve_code( $code );
		if ( ! $row ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$code,
			time() + ( 30 * DAY_IN_SECONDS ),
			COOKIEPATH ? COOKIEPATH : '/',
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);
		$_COOKIE[ self::COOKIE ] = $code; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Cookie / POST code helper.
	 */
	public static function current_invite_code(): string {
		if ( ! empty( $_POST['lnkup_org_ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return sanitize_text_field( wp_unslash( (string) $_POST['lnkup_org_ref'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( ! empty( $_GET[ self::QUERY_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		return '';
	}

	/**
	 * Resolve active organizer_recruit code row.
	 *
	 * @param string $code Code.
	 * @return array<string, mixed>|null
	 */
	public static function resolve_code( string $code ) {
		$code = sanitize_text_field( $code );
		if ( ! $code ) {
			return null;
		}
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_codes_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE code = %s AND is_active = 1 AND program = %s LIMIT 1",
				$code,
				self::PROGRAM
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Public resolve payload.
	 *
	 * @param string $code Code.
	 * @return array<string, mixed>|null
	 */
	public static function resolve_code_public( string $code ) {
		$row = self::resolve_code( $code );
		if ( ! $row ) {
			return null;
		}
		$referrer = get_userdata( (int) ( $row['user_id'] ?? 0 ) );
		return array(
			'valid'         => true,
			'program'       => self::PROGRAM,
			'referrer_name' => $referrer ? $referrer->display_name : '',
			'months'        => self::window_months(),
			'percent'       => self::commission_percent(),
		);
	}

	/**
	 * Get or create share code for vendor/organizer.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_or_create_code( int $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! self::user_can_refer( $user_id ) ) {
			return null;
		}
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_codes_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND program = %s LIMIT 1",
				$user_id,
				self::PROGRAM
			),
			ARRAY_A
		);
		if ( $row ) {
			return $row;
		}
		$code = self::generate_code( $user_id );
		$role = LNKup_Roles::is_organizer( $user_id ) ? 'organizer' : 'vendor';
		$now  = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'code'       => $code,
				'role_type'  => $role,
				'program'    => self::PROGRAM,
				'is_active'  => 1,
				'created_at' => $now,
			)
		);
		if ( ! $wpdb->insert_id ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $wpdb->insert_id ),
			ARRAY_A
		);
	}

	/**
	 * @param int $user_id User ID.
	 */
	private static function generate_code( int $user_id ): string {
		return 'ORG' . strtoupper( substr( md5( 'org_recruit_' . $user_id . wp_salt() ), 0, 8 ) );
	}

	/**
	 * Share URL for a code (organizer interest by default for backwards compatibility).
	 *
	 * @param string $code     Code.
	 * @param string $interest organizer|vendor.
	 */
	public static function share_url( string $code, string $interest = 'organizer' ): string {
		$interest = in_array( $interest, array( 'organizer', 'vendor' ), true ) ? $interest : 'organizer';
		$signup   = function_exists( 'lnkup_registration_url' )
			? lnkup_registration_url()
			: ( function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'sign-up' ) : '' );
		if ( ! $signup ) {
			$signup = home_url( '/sign-up/' );
		}
		return add_query_arg(
			array(
				self::QUERY_PARAM => $code,
				'interest'        => $interest,
			),
			$signup
		);
	}

	/**
	 * Vendors and organizers may refer.
	 *
	 * @param int $user_id User ID.
	 */
	public static function user_can_refer( int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return true;
		}
		return LNKup_Roles::is_vendor( $user_id ) || LNKup_Roles::is_organizer( $user_id );
	}

	/**
	 * Attribute on register when organizer or vendor role is already present.
	 *
	 * @param int $user_id New user.
	 */
	public static function on_user_register( int $user_id ): void {
		$code = self::current_invite_code();
		if ( ! $code ) {
			return;
		}
		update_user_meta( $user_id, '_lnkup_pending_org_ref', $code );
		$interest = self::current_invite_interest();
		if ( $interest ) {
			update_user_meta( $user_id, '_lnkup_pending_org_ref_interest', $interest );
		}
		self::try_attribute_from_pending( $user_id );
	}

	/**
	 * Interest from invite link (organizer|vendor).
	 */
	public static function current_invite_interest(): string {
		if ( ! empty( $_POST['lnkup_signup_interest'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$interest = sanitize_key( wp_unslash( (string) $_POST['lnkup_signup_interest'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( in_array( $interest, array( 'organizer', 'vendor' ), true ) ) {
				return $interest;
			}
		}
		if ( ! empty( $_GET['interest'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$interest = sanitize_key( wp_unslash( (string) $_GET['interest'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $interest, array( 'organizer', 'vendor' ), true ) ) {
				return $interest;
			}
		}
		return '';
	}

	/**
	 * @param int    $user_id User ID.
	 * @param string $role    Role.
	 * @param array  $old     Old roles.
	 */
	public static function on_set_user_role( $user_id, $role, $old = array() ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( 'lnkup_organizer' === $role ) {
			self::try_attribute_from_pending( (int) $user_id, 'organizer' );
			return;
		}
		if ( 'lnkup_vendor' === $role ) {
			self::try_attribute_from_pending( (int) $user_id, 'vendor' );
		}
	}

	/**
	 * @param int    $user_id User ID.
	 * @param string $role    Role.
	 */
	public static function on_add_user_role( $user_id, $role ): void {
		if ( 'lnkup_organizer' === $role ) {
			self::try_attribute_from_pending( (int) $user_id, 'organizer' );
			return;
		}
		if ( 'lnkup_vendor' === $role ) {
			self::try_attribute_from_pending( (int) $user_id, 'vendor' );
		}
	}

	/**
	 * Backfill attribution on login when pending invite meta remains.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       User object.
	 */
	public static function on_wp_login( $user_login, $user ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$user_id = (int) $user->ID;
		if ( ! $user_id || ! get_user_meta( $user_id, '_lnkup_pending_org_ref', true ) ) {
			return;
		}
		self::try_attribute_from_pending( $user_id );
	}

	/**
	 * Use pending meta or cookie when user becomes organizer or vendor.
	 *
	 * @param int    $user_id            User ID.
	 * @param string $preferred_interest Optional role just granted (organizer|vendor).
	 */
	public static function try_attribute_from_pending( int $user_id, string $preferred_interest = '' ): void {
		$code = (string) get_user_meta( $user_id, '_lnkup_pending_org_ref', true );
		if ( ! $code ) {
			$code = self::current_invite_code();
		}
		if ( ! $code ) {
			return;
		}
		$interest = (string) get_user_meta( $user_id, '_lnkup_pending_org_ref_interest', true );
		if ( ! $interest ) {
			$interest = self::current_invite_interest();
		}
		if ( in_array( $preferred_interest, array( 'organizer', 'vendor' ), true ) ) {
			// Prefer the marketplace role just granted when invite interest mismatched.
			$interest = $preferred_interest;
		}
		if ( ! $interest ) {
			if ( LNKup_Roles::is_organizer( $user_id ) ) {
				$interest = 'organizer';
			} elseif ( LNKup_Roles::is_vendor( $user_id ) ) {
				$interest = 'vendor';
			}
		}
		self::attribute_user( $user_id, $code, $interest ?: 'organizer' );
	}

	/**
	 * Bind referred user (organizer or vendor) to referrer for window_months.
	 *
	 * @param int    $user_id  Referred user ID.
	 * @param string $code     Invite code.
	 * @param string $interest organizer|vendor.
	 * @return int|false Referral row ID.
	 */
	public static function attribute_user( int $user_id, string $code, string $interest = 'organizer' ) {
		if ( ! self::is_enabled() ) {
			return false;
		}
		$interest = in_array( $interest, array( 'organizer', 'vendor' ), true ) ? $interest : 'organizer';
		$code_row = self::resolve_code( $code );
		if ( ! $code_row ) {
			return false;
		}
		$referrer_id = (int) ( $code_row['user_id'] ?? 0 );
		if ( ! $referrer_id || $referrer_id === $user_id ) {
			return false;
		}

		$is_organizer = LNKup_Roles::is_organizer( $user_id );
		$is_vendor    = LNKup_Roles::is_vendor( $user_id );
		if ( ! $is_organizer && ! $is_vendor ) {
			update_user_meta( $user_id, '_lnkup_pending_org_ref', $code );
			update_user_meta( $user_id, '_lnkup_pending_org_ref_interest', $interest );
			return false;
		}
		// Attribute to the role they actually have (avoid forever-pending on interest mismatch).
		if ( $is_organizer && ! $is_vendor ) {
			$interest = 'organizer';
		} elseif ( $is_vendor && ! $is_organizer ) {
			$interest = 'vendor';
		}

		global $wpdb;
		$ref_table = LNKup_Treasury_Tables::referrals_table();
		if ( ! LNKup_Financial_Tables::table_exists( $ref_table ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$ref_table} WHERE referred_user_id = %d AND program = %s LIMIT 1",
				$user_id,
				self::PROGRAM
			),
			ARRAY_A
		);
		if ( $existing ) {
			return (int) $existing['id'];
		}

		$now        = current_time( 'mysql' );
		$expires_ts = strtotime( '+' . self::window_months() . ' months', current_time( 'timestamp' ) );
		$expires    = gmdate( 'Y-m-d H:i:s', $expires_ts );
		$wpdb->insert(
			$ref_table,
			array(
				'referrer_user_id' => $referrer_id,
				'referred_user_id' => $user_id,
				'referral_code_id' => (int) $code_row['id'],
				'program'          => self::PROGRAM,
				'target_role'      => $interest,
				'status'           => 'active',
				'reward_status'    => 'earning',
				'created_at'       => $now,
				'converted_at'     => $now,
			)
		);
		$referral_id = (int) $wpdb->insert_id;
		if ( ! $referral_id ) {
			return false;
		}

		update_user_meta( $user_id, self::META_EXPIRES, $expires );
		update_user_meta( $user_id, '_lnkup_org_recruit_referrer_id', $referrer_id );
		update_user_meta( $user_id, '_lnkup_org_recruit_referral_id', $referral_id );
		update_user_meta( $user_id, '_lnkup_org_recruit_target_role', $interest );
		delete_user_meta( $user_id, '_lnkup_pending_org_ref' );
		delete_user_meta( $user_id, '_lnkup_pending_org_ref_interest' );

		/**
		 * Fires when a user is attributed to a recruit referral.
		 *
		 * @param int    $user_id     Referred user ID.
		 * @param int    $referrer_id Referrer user ID.
		 * @param int    $referral_id Referral row ID.
		 * @param string $interest    organizer|vendor.
		 */
		do_action( 'lnkup_organizer_recruit_attributed', $user_id, $referrer_id, $referral_id, $interest );

		return $referral_id;
	}

	/**
	 * Back-compat alias.
	 *
	 * @param int    $organizer_id Organizer user ID.
	 * @param string $code         Invite code.
	 * @return int|false
	 */
	public static function attribute_organizer( int $organizer_id, string $code ) {
		return self::attribute_user( $organizer_id, $code, 'organizer' );
	}

	/**
	 * Active referral for a referred user (within window).
	 *
	 * @param int $user_id Referred user ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_active_referral_for_user( int $user_id ) {
		global $wpdb;
		$ref_table = LNKup_Treasury_Tables::referrals_table();
		if ( ! LNKup_Financial_Tables::table_exists( $ref_table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$ref_table} WHERE referred_user_id = %d AND program = %s LIMIT 1",
				$user_id,
				self::PROGRAM
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$expires = (string) get_user_meta( $user_id, self::META_EXPIRES, true );
		if ( ! $expires ) {
			$created = strtotime( (string) ( $row['created_at'] ?? '' ) );
			$expires = $created
				? gmdate( 'Y-m-d H:i:s', strtotime( '+' . self::window_months() . ' months', $created ) )
				: '';
		}
		$row['expires_at']  = $expires;
		$row['target_role'] = $row['target_role'] ?: (string) get_user_meta( $user_id, '_lnkup_org_recruit_target_role', true );
		if ( $expires && strtotime( $expires ) < time() ) {
			$row['status'] = 'expired';
			return $row;
		}
		$row['status'] = 'active';
		return $row;
	}

	/**
	 * Active referral for an organizer (within window).
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_active_referral_for_organizer( int $organizer_id ) {
		return self::get_active_referral_for_user( $organizer_id );
	}

	/**
	 * Issue reward on paid booth payment (referred organizer events or referred vendor pays).
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function on_payment_completed( $payment_id ): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		$payment_id = absint( $payment_id );
		if ( ! $payment_id ) {
			return;
		}

		$event_id     = (int) get_post_meta( $payment_id, '_lnkup_event_id', true );
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$vendor_id    = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		if ( ! $organizer_id && $event_id ) {
			$organizer_id = (int) get_post_field( 'post_author', $event_id );
		}

		$referral = null;
		// Prefer referred-organizer path when the event organizer was recruited.
		if ( $organizer_id ) {
			$org_ref = self::get_active_referral_for_user( $organizer_id );
			if ( $org_ref && 'active' === ( $org_ref['status'] ?? '' ) && 'vendor' !== ( $org_ref['target_role'] ?? '' ) ) {
				$referral = $org_ref;
			}
		}
		// Referred vendor path: paying vendor was recruited.
		if ( ! $referral && $vendor_id ) {
			$vendor_ref = self::get_active_referral_for_user( $vendor_id );
			if ( $vendor_ref && 'active' === ( $vendor_ref['status'] ?? '' ) && 'organizer' !== ( $vendor_ref['target_role'] ?? '' ) ) {
				$referral = $vendor_ref;
			}
		}
		// Dual-role edge: target_role empty — allow either match.
		if ( ! $referral && $organizer_id ) {
			$org_ref = self::get_active_referral_for_user( $organizer_id );
			if ( $org_ref && 'active' === ( $org_ref['status'] ?? '' ) ) {
				$referral = $org_ref;
			}
		}
		if ( ! $referral && $vendor_id ) {
			$vendor_ref = self::get_active_referral_for_user( $vendor_id );
			if ( $vendor_ref && 'active' === ( $vendor_ref['status'] ?? '' ) ) {
				$referral = $vendor_ref;
			}
		}

		if ( ! $referral || 'active' !== ( $referral['status'] ?? '' ) ) {
			return;
		}

		self::reward_payment_for_referral( $referral, $payment_id );
	}

	/**
	 * Calculate and issue the fee-share reward for one referral + payment (idempotent).
	 *
	 * @param array<string, mixed> $referral Referral row.
	 * @param int                  $payment_id Payment ID.
	 * @return int Reward ID, or 0 when nothing was issued.
	 */
	public static function reward_payment_for_referral( array $referral, int $payment_id ): int {
		if ( ! self::is_enabled() ) {
			return 0;
		}
		$rule = class_exists( 'LNKup_Commission_Rules' ) ? LNKup_Commission_Rules::rule_for_referral( $referral ) : null;
		if ( $rule ) {
			$calc         = LNKup_Commission_Rules::calculate( $rule, $payment_id );
			$platform_fee = $calc['basis_amount'];
			$amount       = $calc['amount'];
		} else {
			$platform_fee = self::resolve_platform_fee_for_payment( $payment_id );
			$amount       = round( $platform_fee * ( self::commission_percent() / 100.0 ), 2 );
			$calc         = array( 'rule_id' => 0, 'rule_version' => 0, 'basis' => 'platform_fee' );
		}
		if ( $platform_fee <= 0 || $amount <= 0 ) {
			return 0;
		}
		if ( class_exists( 'LNKup_Attribution_Arbiter' ) && ! LNKup_Attribution_Arbiter::allows( (int) $referral['id'], $payment_id ) ) {
			return 0;
		}

		return self::issue_reward(
			(int) $referral['referrer_user_id'],
			(int) $referral['id'],
			$payment_id,
			(int) get_post_meta( $payment_id, '_lnkup_event_id', true ),
			$amount,
			$platform_fee,
			$calc
		);
	}

	/**
	 * Platform take for a payment (dollars).
	 *
	 * @param int $payment_id Payment ID.
	 */
	public static function resolve_platform_fee_for_payment( int $payment_id ): float {
		$cents = (int) get_post_meta( $payment_id, '_lnkup_application_fee_cents', true );
		if ( $cents > 0 ) {
			return round( $cents / 100, 2 );
		}
		$meta = get_post_meta( $payment_id, '_lnkup_platform_fee', true );
		if ( '' !== $meta && null !== $meta ) {
			return round( (float) $meta, 2 );
		}
		$breakdown = get_post_meta( $payment_id, '_lnkup_fee_breakdown', true );
		if ( is_string( $breakdown ) && '' !== $breakdown ) {
			$breakdown = json_decode( $breakdown, true );
		}
		if ( is_array( $breakdown ) ) {
			if ( isset( $breakdown['lnkup_platform_fee'] ) ) {
				return round( (float) $breakdown['lnkup_platform_fee'], 2 );
			}
			if ( isset( $breakdown['platform_fee'] ) ) {
				return round( (float) $breakdown['platform_fee'], 2 );
			}
		}
		return 0.0;
	}

	/**
	 * Grant wallet credit (idempotent per payment).
	 *
	 * @param int   $referrer_id  Referrer.
	 * @param int   $referral_id  Referral row.
	 * @param int   $payment_id   Payment.
	 * @param int   $event_id     Event.
	 * @param float $amount       Credit amount.
	 * @param float $platform_fee Base platform fee.
	 * @param array $calc         Optional rule stamp (rule_id, rule_version, basis).
	 */
	public static function issue_reward( int $referrer_id, int $referral_id, int $payment_id, int $event_id, float $amount, float $platform_fee, array $calc = array() ): int {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$dup = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE referral_id = %d AND payment_id = %d LIMIT 1",
				$referral_id,
				$payment_id
			)
		);
		if ( $dup ) {
			return (int) $dup;
		}

		$now = current_time( 'mysql' );
		$row = array(
			'referral_id'   => $referral_id,
			'user_id'       => $referrer_id,
			'event_type'    => self::EVENT_TYPE,
			'amount'        => $amount,
			'status'        => 'available',
			'reward_status' => 'paid',
			'payment_id'    => $payment_id,
			'event_id'      => $event_id,
			'program'       => self::PROGRAM,
			'created_at'    => $now,
			'available_at'  => $now,
			'paid_at'       => $now,
		);
		if ( class_exists( 'LNKup_Partner_Tables' ) && LNKup_Partner_Tables::ready() ) {
			$row += array(
				'rule_id'         => (int) ( $calc['rule_id'] ?? 0 ),
				'rule_version'    => (int) ( $calc['rule_version'] ?? 0 ),
				'basis'           => (string) ( $calc['basis'] ?? 'platform_fee' ),
				'basis_amount'    => round( $platform_fee, 2 ),
				'idempotency_key' => LNKup_Partner_Tables::reward_key( self::PROGRAM, $referrer_id, $referral_id, $payment_id, self::EVENT_TYPE ),
			);
		}
		$wpdb->insert( $table, $row );
		$reward_id = (int) $wpdb->insert_id;
		if ( ! $reward_id ) {
			return 0;
		}

		$credit_id = 0;
		if ( class_exists( 'LNKup_Credits' ) ) {
			$credit_id = (int) LNKup_Credits::grant(
				$referrer_id,
				$amount,
				'referral',
				'organizer_recruit',
				$reward_id
			);
			if ( $credit_id ) {
				$wpdb->update(
					$table,
					array( 'credit_id' => $credit_id ),
					array( 'id' => $reward_id )
				);
			}
		}

		$ref_table = LNKup_Treasury_Tables::referrals_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$ref_table} SET attributed_revenue = attributed_revenue + %f WHERE id = %d",
				$platform_fee,
				$referral_id
			)
		);

		do_action( 'lnkup_partner_commission_recorded', $reward_id, self::PROGRAM, $referral_id, $payment_id );

		return $reward_id;
	}

	/**
	 * Dashboard / REST snapshot for current referrer.
	 *
	 * @param int $user_id Referrer user ID.
	 * @return array<string, mixed>
	 */
	public static function dashboard_for_user( int $user_id ): array {
		$code_row = self::get_or_create_code( $user_id );
		$code     = $code_row['code'] ?? '';
		$percent  = self::commission_percent();
		$months   = self::window_months();

		global $wpdb;
		$ref_table = LNKup_Treasury_Tables::referrals_table();
		$rr_table  = LNKup_Treasury_Tables::referral_rewards_table();
		$referrals = array();
		$rewards   = array();
		$total     = 0.0;

		if ( LNKup_Financial_Tables::table_exists( $ref_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$ref_table} WHERE referrer_user_id = %d AND program = %s ORDER BY created_at DESC LIMIT 100",
					$user_id,
					self::PROGRAM
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$org_id  = (int) ( $row['referred_user_id'] ?? 0 );
				$org     = $org_id ? get_userdata( $org_id ) : false;
				$expires = (string) get_user_meta( $org_id, self::META_EXPIRES, true );
				if ( ! $expires && ! empty( $row['created_at'] ) ) {
					$expires = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $months . ' months', strtotime( (string) $row['created_at'] ) ) );
				}
				if ( $expires ) {
					$active = strtotime( $expires ) >= time();
				} else {
					$active = 'active' === (string) ( $row['status'] ?? '' );
				}
				$earned = 0.0;
				if ( LNKup_Financial_Tables::table_exists( $rr_table ) ) {
					$earned = (float) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COALESCE(SUM(amount), 0) FROM {$rr_table} WHERE referral_id = %d AND program = %s",
							(int) $row['id'],
							self::PROGRAM
						)
					);
				}
				$earned = round( $earned, 2 );
				if ( ! $active ) {
					$lifecycle = 'ended';
				} elseif ( $earned > 0 ) {
					$lifecycle = 'earning';
				} else {
					$lifecycle = 'awaiting_payment';
				}
				$referrals[] = array(
					'id'                 => (int) $row['id'],
					'referred_user_id'   => $org_id,
					'referred_name'      => $org ? $org->display_name : __( 'Member', 'lnkup-marketplace' ),
					'target_role'        => (string) ( $row['target_role'] ?? get_user_meta( $org_id, '_lnkup_org_recruit_target_role', true ) ?: 'organizer' ),
					'status'             => $active ? 'active' : 'expired',
					'lifecycle'          => $lifecycle,
					'expires_at'         => $expires,
					'earned_total'       => $earned,
					'attributed_revenue' => (float) ( $row['attributed_revenue'] ?? 0 ),
					'created_at'         => $row['created_at'] ?? '',
				);
			}
		}

		if ( LNKup_Financial_Tables::table_exists( $rr_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rewards = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$rr_table} WHERE user_id = %d AND program = %s ORDER BY created_at DESC LIMIT 50",
					$user_id,
					self::PROGRAM
				),
				ARRAY_A
			);
			$total = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(amount), 0) FROM {$rr_table} WHERE user_id = %d AND program = %s",
					$user_id,
					self::PROGRAM
				)
			);
		}

		$headline = sprintf(
			/* translators: 1: percent, 2: months */
			__( 'Invite organizers or vendors — earn %1$s%% of LNKup fees for %2$d months', 'lnkup-marketplace' ),
			rtrim( rtrim( number_format( $percent, 2 ), '0' ), '.' ),
			$months
		);

		$share_organizer = $code ? self::share_url( $code, 'organizer' ) : '';
		$share_vendor    = $code ? self::share_url( $code, 'vendor' ) : '';

		return array(
			'enabled'             => self::is_enabled(),
			'program'             => self::PROGRAM,
			'code'                => $code,
			'share_url'           => $share_organizer,
			'share_url_organizer' => $share_organizer,
			'share_url_vendor'    => $share_vendor,
			'percent'             => $percent,
			'months'              => $months,
			'headline'            => $headline,
			'message'             => __( 'Share an invite link. When they pay booth fees (as a vendor) or collect booth fees on their events (as an organizer), you earn a cut of LNKup’s vendor fee and organizer fee for up to a year.', 'lnkup-marketplace' ),
			'referrals'           => $referrals,
			'rewards'             => $rewards,
			'earnings_total'      => round( $total, 2 ),
			'active_count'        => count( array_filter( $referrals, static function ( $r ) {
				return 'active' === ( $r['status'] ?? '' );
			} ) ),
		);
	}
}
