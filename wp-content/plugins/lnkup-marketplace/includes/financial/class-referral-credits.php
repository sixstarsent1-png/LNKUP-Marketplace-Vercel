<?php
/**
 * Referral codes, rewards, and non-cashout credits.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Referral_Credits {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'capture_ref_cookie' ), 5 );
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 20, 1 );
		add_action( 'lnkup_payment_completed', array( __CLASS__, 'on_payment_completed' ), 20, 1 );
	}

	/**
	 * Store referral code from ?ref= query param.
	 */
	public static function capture_ref_cookie() {
		if ( empty( $_GET['ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$code = sanitize_text_field( wp_unslash( $_GET['ref'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $code ) {
			return;
		}
		if ( class_exists( 'LNKup_Connector' ) ) {
			$row = LNKup_Connector::resolve_code( $code );
			if ( $row && LNKup_Connector::is_account_program( (string) ( $row['program'] ?? '' ) ) ) {
				return;
			}
		}
		if ( class_exists( 'LNKup_Organizer_Recruit_Referrals' ) ) {
			$org = LNKup_Organizer_Recruit_Referrals::resolve_code( $code );
			if ( $org ) {
				return;
			}
		}
		setcookie(
			'lnkup_ref',
			$code,
			time() + ( 30 * DAY_IN_SECONDS ),
			COOKIEPATH,
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);
		$_COOKIE['lnkup_ref'] = $code; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Get or create referral code for user.
	 *
	 * @param int $user_id User ID.
	 */
	public static function get_or_create_code( $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_codes_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d LIMIT 1", absint( $user_id ) ), ARRAY_A );
		if ( $row ) {
			return $row;
		}
		$code = self::generate_code( $user_id );
		$now  = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'user_id'    => absint( $user_id ),
				'code'       => $code,
				'role_type'  => 'vendor',
				'is_active'  => 1,
				'created_at' => $now,
			)
		);
		if ( ! $wpdb->insert_id ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $wpdb->insert_id ), ARRAY_A );
	}

	/**
	 * Generate unique referral code.
	 *
	 * @param int $user_id User ID.
	 */
	private static function generate_code( $user_id ) {
		return 'LNK' . strtoupper( substr( md5( $user_id . wp_salt() ), 0, 8 ) );
	}

	/**
	 * Record referral when new user signs up with code.
	 *
	 * @param int $user_id New user ID.
	 */
	public static function on_user_register( $user_id ) {
		$ref_code = isset( $_COOKIE['lnkup_ref'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['lnkup_ref'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $ref_code ) {
			return;
		}
		if ( class_exists( 'LNKup_Connector' ) ) {
			$row = LNKup_Connector::resolve_code( $ref_code );
			if ( $row && LNKup_Connector::is_account_program( (string) ( $row['program'] ?? '' ) ) ) {
				return;
			}
		}
		self::record_referral( $ref_code, $user_id );
	}

	/**
	 * Award credits on first payment.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function on_payment_completed( $payment_id ) {
		$vendor_id = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		if ( ! $vendor_id ) {
			return;
		}
		global $wpdb;
		$ref_table = LNKup_Treasury_Tables::referrals_table();
		if ( ! LNKup_Financial_Tables::table_exists( $ref_table ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$referral = $wpdb->get_row(
			// Connector and organizer-recruit referrals share this table and pay through their own programs.
			$wpdb->prepare( "SELECT * FROM {$ref_table} WHERE referred_user_id = %d AND program = 'vendor_referral' LIMIT 1", $vendor_id ),
			ARRAY_A
		);
		if ( ! $referral || 'converted' === ( $referral['status'] ?? '' ) ) {
			return;
		}
		$paid_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				INNER JOIN {$wpdb->postmeta} ps ON ps.post_id = p.ID AND ps.meta_key = '_lnkup_payment_status' AND ps.meta_value = 'paid'
				WHERE pm.meta_key = '_lnkup_vendor_user_id' AND pm.meta_value = %d AND p.post_type = 'lnkup_payment'",
				$vendor_id
			)
		);
		if ( $paid_count > 1 ) {
			return;
		}
		if ( class_exists( 'LNKup_Attribution_Arbiter' ) && ! LNKup_Attribution_Arbiter::allows( (int) $referral['id'], (int) $payment_id ) ) {
			return;
		}
		self::issue_reward( (int) $referral['referrer_user_id'], 'first_invoice_paid', (int) $referral['id'], $payment_id );
		self::mark_converted( (int) $referral['id'] );
	}

	/**
	 * Mark a vendor referral converted after its one-time credit.
	 *
	 * @param int $referral_id Referral row ID.
	 */
	public static function mark_converted( $referral_id ) {
		global $wpdb;
		$wpdb->update(
			LNKup_Treasury_Tables::referrals_table(),
			array(
				'status'       => 'converted',
				'converted_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $referral_id ) )
		);
	}

	/**
	 * Record referral relationship.
	 *
	 * @param string $code        Referral code.
	 * @param int    $referred_id Referred user ID.
	 */
	public static function record_referral( $code, $referred_id ) {
		global $wpdb;
		$codes_table = LNKup_Treasury_Tables::referral_codes_table();
		$ref_table   = LNKup_Treasury_Tables::referrals_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$code_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$codes_table} WHERE code = %s AND is_active = 1 LIMIT 1", $code ),
			ARRAY_A
		);
		if ( ! $code_row || (int) $code_row['user_id'] === $referred_id ) {
			return false;
		}
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$ref_table} WHERE referred_user_id = %d LIMIT 1", absint( $referred_id ) )
		);
		if ( $existing ) {
			return (int) $existing;
		}
		$wpdb->insert(
			$ref_table,
			array(
				'referrer_user_id' => (int) $code_row['user_id'],
				'referred_user_id' => absint( $referred_id ),
				'referral_code_id' => (int) $code_row['id'],
				'status'           => 'pending',
				'created_at'       => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Issue referral reward credit.
	 *
	 * @param int    $user_id     Referrer user ID.
	 * @param string $event_type  Event type.
	 * @param int    $referral_id Referral row ID.
	 * @param int    $payment_id  Payment ID.
	 */
	public static function issue_reward( $user_id, $event_type, $referral_id = 0, $payment_id = 0 ) {
		$amount = self::resolve_reward_amount( $event_type );
		if ( $amount <= 0 ) {
			return 0;
		}
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		if ( $referral_id ) {
			$dup = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE referral_id = %d AND event_type = %s LIMIT 1",
					$referral_id,
					sanitize_key( $event_type )
				)
			);
			if ( $dup ) {
				return (int) $dup;
			}
		}
		$now = current_time( 'mysql' );
		$row = array(
			'referral_id' => absint( $referral_id ),
			'user_id'     => absint( $user_id ),
			'event_type'  => sanitize_key( $event_type ),
			'amount'      => $amount,
			'status'      => 'available',
			'created_at'  => $now,
		);
		if ( class_exists( 'LNKup_Partner_Tables' ) && LNKup_Partner_Tables::ready() ) {
			$rule = LNKup_Commission_Rules::active_rule( LNKup_Partner_Programs::VENDOR_REFERRAL );
			$row += array(
				'program'         => LNKup_Partner_Programs::VENDOR_REFERRAL,
				'payment_id'      => absint( $payment_id ),
				'reward_status'   => 'paid',
				'paid_at'         => $now,
				'rule_id'         => $rule ? (int) $rule['id'] : 0,
				'rule_version'    => $rule ? (int) $rule['version'] : 0,
				'basis'           => 'fixed',
				'idempotency_key' => LNKup_Partner_Tables::reward_key( LNKup_Partner_Programs::VENDOR_REFERRAL, absint( $user_id ), absint( $referral_id ), absint( $payment_id ), (string) $event_type ),
			);
		}
		$wpdb->insert( $table, $row );
		$reward_id = (int) $wpdb->insert_id;
		if ( class_exists( 'LNKup_Credits' ) && $reward_id ) {
			LNKup_Credits::grant( $user_id, $amount, 'referral', 'referral_reward', $reward_id );
		}
		return $reward_id;
	}

	/**
	 * Resolve reward amount from credit rules.
	 *
	 * @param string $event_type Event type.
	 */
	private static function resolve_reward_amount( $event_type ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::credit_rules_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 10.0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rule = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_type = %s AND status = 'active' LIMIT 1", sanitize_key( $event_type ) ),
			ARRAY_A
		);
		return $rule ? (float) ( $rule['reward_amount'] ?? 0 ) : 10.0;
	}

	/**
	 * List referrals for user.
	 *
	 * @param int $user_id User ID.
	 */
	public static function list_for_user( $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referrals_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE referrer_user_id = %d ORDER BY created_at DESC LIMIT 50", absint( $user_id ) ),
			ARRAY_A
		);
		foreach ( $rows as &$row ) {
			$uid = (int) ( $row['referred_user_id'] ?? 0 );
			$user = $uid ? get_userdata( $uid ) : false;
			$row['referred_name'] = $user ? $user->display_name : '';
		}
		unset( $row );
		return $rows;
	}

	/**
	 * List rewards for user.
	 *
	 * @param int $user_id User ID.
	 */
	public static function list_rewards( $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 50", absint( $user_id ) ),
			ARRAY_A
		);
	}
}
