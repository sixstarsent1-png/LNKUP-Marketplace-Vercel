<?php
/**
 * City Connector reward tracking (manual by default).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Connector_Rewards {

	/**
	 * Init hooks.
	 */
	public static function init() {
		// Rewards are queued via static methods from attribution/tracking.
	}

	/**
	 * Connector reward settings.
	 */
	public static function settings() {
		$s = lnkup_get_settings();
		return array(
			'enabled'                      => ! empty( $s['connector_rewards_enabled'] ),
			'mode'                         => $s['connector_reward_mode'] ?? 'manual',
			'vendor_signup_credit'         => (float) ( $s['connector_vendor_signup_credit'] ?? 0 ),
			'vendor_paid_booking_credit'   => (float) ( $s['connector_vendor_paid_booking_credit'] ?? 10 ),
			'organizer_first_booking_credit' => (float) ( $s['connector_organizer_first_booking_credit'] ?? 0 ),
			'organizer_10_booking_bonus'   => (float) ( $s['connector_organizer_10_booking_bonus'] ?? 0 ),
		);
	}

	/**
	 * Queue signup reward if configured.
	 *
	 * @param int    $connector_id Connector ID.
	 * @param int    $referral_id  Referral ID.
	 * @param string $role         User role.
	 */
	public static function maybe_queue_signup_reward( $connector_id, $referral_id, $role ) {
		$settings = self::settings();
		if ( ! $settings['enabled'] || $settings['vendor_signup_credit'] <= 0 ) {
			return;
		}
		if ( false === strpos( (string) $role, 'vendor' ) ) {
			return;
		}
		self::insert_pending_reward( $connector_id, $referral_id, 'connector_vendor_signup', $settings['vendor_signup_credit'] );
	}

	/**
	 * Queue paid booking reward.
	 *
	 * @param int $connector_id Connector ID.
	 * @param int $referral_id  Referral ID.
	 * @param int $payment_id   Payment ID.
	 */
	public static function maybe_queue_paid_booking_reward( $connector_id, $referral_id, $payment_id ) {
		if ( ! $connector_id || ! $referral_id ) {
			return;
		}
		$settings = self::settings();
		if ( ! $settings['enabled'] ) {
			return;
		}
		global $wpdb;
		$ref = $wpdb->get_row( $wpdb->prepare( 'SELECT target_role FROM ' . LNKup_Treasury_Tables::referrals_table() . ' WHERE id = %d', $referral_id ), ARRAY_A );
		$is_organizer = $ref && false !== strpos( (string) ( $ref['target_role'] ?? '' ), 'organizer' );
		$amount = $is_organizer ? $settings['organizer_first_booking_credit'] : $settings['vendor_paid_booking_credit'];
		if ( $amount <= 0 ) {
			return;
		}
		$event = $is_organizer ? 'connector_organizer_first_booking' : 'connector_vendor_paid_booking';
		self::insert_pending_reward( $connector_id, $referral_id, $event, $amount, $payment_id );
	}

	/**
	 * Queue organizer opportunity reward placeholder.
	 *
	 * @param int $connector_id Connector ID.
	 * @param int $referral_id  Referral ID.
	 */
	public static function maybe_queue_organizer_opportunity_reward( $connector_id, $referral_id ) {
		// Milestone tracked; bonus rewards handled separately when bookings threshold met.
		unset( $connector_id, $referral_id );
	}

	/**
	 * Insert pending reward row.
	 *
	 * @param int    $connector_id Connector ID.
	 * @param int    $referral_id  Referral ID.
	 * @param string $event_type   Event type.
	 * @param float  $amount       Amount.
	 * @param int    $source_id    Optional source ID.
	 */
	public static function insert_pending_reward( $connector_id, $referral_id, $event_type, $amount, $source_id = 0 ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		$connector = LNKup_Connector::get( $connector_id );
		if ( ! $connector ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$dup = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE referral_id = %d AND event_type = %s LIMIT 1",
				absint( $referral_id ),
				sanitize_key( $event_type )
			)
		);
		if ( $dup ) {
			return (int) $dup;
		}
		$wpdb->insert(
			$table,
			array(
				'referral_id'    => absint( $referral_id ),
				'user_id'        => (int) ( $connector['user_id'] ?? 0 ),
				'connector_id'   => absint( $connector_id ),
				'event_type'     => sanitize_key( $event_type ),
				'amount'         => (float) $amount,
				'status'         => 'pending',
				'reward_status'  => 'pending',
				'created_at'     => current_time( 'mysql' ),
			)
		);
		$reward_id = (int) $wpdb->insert_id;
		if ( $reward_id ) {
			$wpdb->update(
				LNKup_Treasury_Tables::referrals_table(),
				array( 'reward_status' => 'pending' ),
				array( 'id' => absint( $referral_id ) )
			);
			LNKup_Connector::record_event( $connector_id, $referral_id, 0, 'reward_pending' );
		}
		unset( $source_id );
		return $reward_id;
	}

	/**
	 * Admin approve reward.
	 *
	 * @param int $reward_id Reward ID.
	 * @param int $admin_id  Admin user ID.
	 */
	public static function approve_reward( $reward_id, $admin_id = 0 ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $reward_id ) ), ARRAY_A );
		if ( ! $row || 'pending' !== ( $row['reward_status'] ?? '' ) ) {
			return new WP_Error( 'invalid_reward', __( 'Reward not found or already processed.', 'lnkup-marketplace' ) );
		}
		$now = current_time( 'mysql' );
		$wpdb->update(
			$table,
			array(
				'reward_status' => 'approved',
				'approved_by'   => absint( $admin_id ?: get_current_user_id() ),
				'approved_at'   => $now,
			),
			array( 'id' => absint( $reward_id ) )
		);
		$settings = self::settings();
		if ( $settings['enabled'] && 'platform_credit' === $settings['mode'] && class_exists( 'LNKup_Credits' ) ) {
			$credit_id = LNKup_Credits::grant( (int) $row['user_id'], (float) $row['amount'], 'referral', 'connector_reward', $reward_id );
			$wpdb->update( $table, array( 'credit_id' => (int) $credit_id, 'status' => 'available' ), array( 'id' => absint( $reward_id ) ) );
		}
		return true;
	}

	/**
	 * Mark reward paid (manual).
	 *
	 * @param int $reward_id Reward ID.
	 */
	public static function mark_paid( $reward_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		$wpdb->update(
			$table,
			array(
				'reward_status' => 'paid',
				'paid_at'       => current_time( 'mysql' ),
			),
			array( 'id' => absint( $reward_id ) )
		);
		return true;
	}

	/**
	 * List rewards for connector.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function list_for_connector( $connector_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_type, amount, reward_status, created_at, approved_at, paid_at FROM {$table} WHERE connector_id = %d ORDER BY created_at DESC LIMIT 50",
				absint( $connector_id )
			),
			ARRAY_A
		);
	}

	/**
	 * Count rewards by status for connector.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function count_by_connector( $connector_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE connector_id = %d AND reward_status = 'pending'", absint( $connector_id ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$approved = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE connector_id = %d AND reward_status = 'approved'", absint( $connector_id ) ) );
		return array( 'pending' => $pending, 'approved' => $approved );
	}
}
