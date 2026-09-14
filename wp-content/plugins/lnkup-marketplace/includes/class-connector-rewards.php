<?php
/**
 * City Connector reward tracking (manual by default).
 *
 * Booking rewards are a configurable share of booth GMV, organizer-primary,
 * with a hold period and admin approve / mark-paid. No Stripe payout here.
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
		add_action( 'lnkup_refund_completed', array( __CLASS__, 'on_refund_completed' ), 20, 1 );
	}

	/**
	 * Connector reward settings (legacy keys + program terms).
	 */
	public static function settings() {
		$terms = self::terms();
		return array(
			'enabled'                    => $terms['enabled'],
			'mode'                       => $terms['mode'],
			'vendor_signup_credit'       => $terms['vendor_signup_credit'],
			'organizer_10_booking_bonus' => (float) ( lnkup_get_settings()['connector_organizer_10_booking_bonus'] ?? 0 ),
		);
	}

	/**
	 * Configurable program terms for REST, calculator, and queue logic.
	 */
	public static function terms() {
		$s = lnkup_get_settings();
		$cap = (float) ( $s['connector_booking_share_cap'] ?? 0 );
		return array(
			'enabled'                   => ! empty( $s['connector_rewards_enabled'] ),
			'mode'                      => $s['connector_reward_mode'] ?? 'manual',
			'share_percent'             => max( 0, min( 100, (float) ( $s['connector_booking_share_percent'] ?? 10 ) ) ),
			'share_cap'                 => max( 0, $cap ),
			'term_months'               => max( 1, min( 36, (int) ( $s['connector_reward_term_months'] ?? 12 ) ) ),
			'hold_days'                 => max( 0, min( 180, (int) ( $s['connector_payout_hold_days'] ?? 30 ) ) ),
			'minimum_payout'            => max( 0, (float) ( $s['connector_minimum_payout'] ?? 50 ) ),
			'vendor_signup_credit'      => (float) ( $s['connector_vendor_signup_credit'] ?? 0 ),
			'vendor_referrals_rewarded' => ! empty( $s['connector_vendor_referrals_rewarded'] ),
			'terms_url'                 => esc_url_raw( (string) ( $s['connector_terms_url'] ?? '' ) ),
			'support_url'               => esc_url_raw( (string) ( $s['connector_support_url'] ?? '' ) ),
		);
	}

	/**
	 * Booth GMV stored on a payment (not LNKup platform take).
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function booth_gmv( $payment_id ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id ) {
			return 0.0;
		}
		$gmv = (float) get_post_meta( $payment_id, '_lnkup_vendor_spot_fee', true );
		if ( $gmv <= 0 ) {
			$gmv = (float) get_post_meta( $payment_id, '_lnkup_vendor_fee', true );
		}
		return max( 0.0, $gmv );
	}

	/**
	 * Commission dollars for a booth GMV amount using configured terms.
	 *
	 * @param float $booth_gmv Booth price.
	 */
	public static function amount_from_gmv( $booth_gmv ) {
		$terms = self::terms();
		$booth_gmv = max( 0.0, (float) $booth_gmv );
		if ( $booth_gmv <= 0 || $terms['share_percent'] <= 0 ) {
			return 0.0;
		}
		$amount = round( $booth_gmv * ( $terms['share_percent'] / 100 ), 2 );
		if ( $terms['share_cap'] > 0 ) {
			$amount = min( $amount, $terms['share_cap'] );
		}
		return $amount;
	}

	/**
	 * Commission for a completed payment.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function calculate_booking_amount( $payment_id ) {
		return self::amount_from_gmv( self::booth_gmv( $payment_id ) );
	}

	/**
	 * Estimate earnings from planner inputs (backend source of truth).
	 *
	 * @param float $booth_price    Average booth price.
	 * @param int   $paid_bookings  Expected paid bookings per organizer.
	 * @param int   $organizers     Active organizers.
	 */
	public static function estimate_earnings( $booth_price, $paid_bookings, $organizers = 1 ) {
		$booth_price   = max( 0.0, (float) $booth_price );
		$paid_bookings = max( 0, (int) $paid_bookings );
		$organizers    = max( 1, (int) $organizers );
		$per_booking   = self::amount_from_gmv( $booth_price );
		$gmv           = round( $booth_price * $paid_bookings * $organizers, 2 );
		$earnings      = round( $per_booking * $paid_bookings * $organizers, 2 );
		return array(
			'gmv'           => $gmv,
			'earnings'      => $earnings,
			'per_organizer' => round( $earnings / $organizers, 2 ),
			'per_booking'   => $per_booking,
			'is_estimate'   => true,
		);
	}

	/**
	 * Whether the referral is still inside the introductory term.
	 *
	 * Window starts at first completed booking (`first_payment_at`).
	 * An empty first_payment_at means this payment opens the window.
	 *
	 * @param array $referral Referral row.
	 */
	public static function within_reward_window( $referral ) {
		$terms  = self::terms();
		$months = (int) $terms['term_months'];
		$anchor = (string) ( $referral['first_payment_at'] ?? '' );
		if ( ! $anchor ) {
			return true;
		}
		$start = strtotime( $anchor );
		if ( ! $start ) {
			return false;
		}
		return current_time( 'timestamp' ) <= strtotime( '+' . $months . ' months', $start );
	}

	/**
	 * Remaining term label for a referral.
	 *
	 * @param array $referral Referral row.
	 */
	public static function remaining_term_label( $referral ) {
		$terms  = self::terms();
		$months = (int) $terms['term_months'];
		$anchor = (string) ( $referral['first_payment_at'] ?? '' );
		if ( ! $anchor ) {
			return sprintf(
				/* translators: %d months */
				__( '%d months after first booking', 'lnkup-marketplace' ),
				$months
			);
		}
		$end = strtotime( '+' . $months . ' months', strtotime( $anchor ) );
		if ( ! $end ) {
			return '';
		}
		if ( current_time( 'timestamp' ) > $end ) {
			return __( 'Expired', 'lnkup-marketplace' );
		}
		return sprintf(
			/* translators: %s date */
			__( 'Through %s', 'lnkup-marketplace' ),
			date_i18n( get_option( 'date_format' ), $end )
		);
	}

	/**
	 * Map a reward row to a connector-facing status.
	 *
	 * @param array $row Reward row.
	 */
	public static function display_status( $row ) {
		$status = sanitize_key( (string) ( $row['reward_status'] ?? 'pending' ) );
		if ( in_array( $status, array( 'paid', 'approved', 'reversed', 'expired' ), true ) ) {
			return $status;
		}
		if ( 'pending' === $status ) {
			$available = strtotime( (string) ( $row['available_at'] ?? '' ) );
			if ( $available && current_time( 'timestamp' ) < $available ) {
				return 'pending_hold';
			}
			return 'awaiting_approval';
		}
		return $status ? $status : 'pending';
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
	 * Queue paid booking reward as a share of booth GMV, once per payment.
	 *
	 * @param int  $connector_id Connector ID.
	 * @param int  $referral_id  Referral ID.
	 * @param int  $payment_id   Payment ID.
	 * @param bool $is_organizer Whether the winning referral is an organizer.
	 */
	public static function maybe_queue_paid_booking_reward( $connector_id, $referral_id, $payment_id, $is_organizer = true ) {
		$connector_id = absint( $connector_id );
		$referral_id  = absint( $referral_id );
		$payment_id   = absint( $payment_id );
		if ( ! $connector_id || ! $referral_id || ! $payment_id ) {
			return 0;
		}
		$terms = self::terms();
		if ( ! $terms['enabled'] ) {
			return 0;
		}
		if ( ! $is_organizer && ! $terms['vendor_referrals_rewarded'] ) {
			return 0;
		}
		$pay_status = sanitize_key( (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) );
		if ( in_array( $pay_status, array( 'refunded', 'failed', 'cancelled' ), true ) ) {
			return 0;
		}
		$amount = self::calculate_booking_amount( $payment_id );
		if ( $amount <= 0 ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ref = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, created_at, first_payment_at FROM ' . LNKup_Treasury_Tables::referrals_table() . ' WHERE id = %d',
				$referral_id
			),
			ARRAY_A
		);
		if ( ! $ref || ! self::within_reward_window( $ref ) ) {
			return 0;
		}
		return self::insert_pending_reward( $connector_id, $referral_id, 'connector_booking_fee', $amount, $payment_id );
	}

	/**
	 * Queue organizer opportunity reward placeholder.
	 *
	 * @param int $connector_id Connector ID.
	 * @param int $referral_id  Referral ID.
	 */
	public static function maybe_queue_organizer_opportunity_reward( $connector_id, $referral_id ) {
		unset( $connector_id, $referral_id );
	}

	/**
	 * Insert pending reward row.
	 *
	 * @param int    $connector_id Connector ID.
	 * @param int    $referral_id  Referral ID.
	 * @param string $event_type   Event type.
	 * @param float  $amount       Amount.
	 * @param int    $source_id    Optional payment ID.
	 */
	public static function insert_pending_reward( $connector_id, $referral_id, $event_type, $amount, $source_id = 0 ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		$connector = LNKup_Connector::get( $connector_id );
		if ( ! $connector ) {
			return 0;
		}
		$source_id = absint( $source_id );
		if ( $source_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$dup = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE connector_id = %d AND payment_id = %d LIMIT 1",
					absint( $connector_id ),
					$source_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$dup = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE referral_id = %d AND event_type = %s LIMIT 1",
					absint( $referral_id ),
					sanitize_key( $event_type )
				)
			);
		}
		if ( $dup ) {
			return (int) $dup;
		}
		$terms = self::terms();
		$hold  = (int) $terms['hold_days'];
		$row   = array(
			'referral_id'   => absint( $referral_id ),
			'user_id'       => (int) ( $connector['user_id'] ?? 0 ),
			'connector_id'  => absint( $connector_id ),
			'event_type'    => sanitize_key( $event_type ),
			'amount'        => (float) $amount,
			'status'        => 'pending',
			'reward_status' => 'pending',
			'created_at'    => current_time( 'mysql' ),
			'program'       => LNKup_Connector::PROGRAM,
		);
		if ( $source_id > 0 ) {
			$row['payment_id']    = $source_id;
			$row['available_at']  = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $hold * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		}
		$wpdb->insert( $table, $row );
		$reward_id = (int) $wpdb->insert_id;
		if ( $reward_id ) {
			$wpdb->update(
				LNKup_Treasury_Tables::referrals_table(),
				array( 'reward_status' => 'pending' ),
				array( 'id' => absint( $referral_id ) )
			);
			LNKup_Connector::record_event( $connector_id, $referral_id, 0, 'reward_pending' );
			if ( class_exists( 'LNKup_Financial_Audit' ) ) {
				LNKup_Financial_Audit::log( 'connector_reward_queued', 'connector_reward', $reward_id, array( 'amount' => (float) $amount, 'payment_id' => $source_id ) );
			}
		}
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
		LNKup_Connector::record_event( (int) ( $row['connector_id'] ?? 0 ), (int) ( $row['referral_id'] ?? 0 ), 0, 'reward_approved' );
		if ( 0 === strpos( (string) ( $row['event_type'] ?? '' ), 'connector_rank_bonus_' ) && class_exists( 'LNKup_Connector_Ranks' ) ) {
			LNKup_Connector_Ranks::record_rank_event( (int) ( $row['connector_id'] ?? 0 ), 'rank_bonus_approved', array( 'reward_id' => absint( $reward_id ) ) );
		}
		if ( class_exists( 'LNKup_Financial_Audit' ) ) {
			LNKup_Financial_Audit::log( 'connector_reward_approved', 'connector_reward', absint( $reward_id ), array( 'amount' => (float) ( $row['amount'] ?? 0 ) ) );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $reward_id ) ), ARRAY_A );
		$wpdb->update(
			$table,
			array(
				'reward_status' => 'paid',
				'paid_at'       => current_time( 'mysql' ),
			),
			array( 'id' => absint( $reward_id ) )
		);
		if ( $row ) {
			LNKup_Connector::record_event( (int) ( $row['connector_id'] ?? 0 ), (int) ( $row['referral_id'] ?? 0 ), 0, 'reward_paid' );
			if ( 0 === strpos( (string) ( $row['event_type'] ?? '' ), 'connector_rank_bonus_' ) && class_exists( 'LNKup_Connector_Ranks' ) ) {
				LNKup_Connector_Ranks::record_rank_event( (int) ( $row['connector_id'] ?? 0 ), 'rank_bonus_paid', array( 'reward_id' => absint( $reward_id ) ) );
			}
			if ( class_exists( 'LNKup_Financial_Audit' ) ) {
				LNKup_Financial_Audit::log( 'connector_reward_paid', 'connector_reward', absint( $reward_id ), array( 'amount' => (float) ( $row['amount'] ?? 0 ) ) );
			}
		}
		return true;
	}

	/**
	 * Reverse unpaid rewards when a payment is refunded.
	 *
	 * @param int $refund_id Refund row ID.
	 */
	public static function on_refund_completed( $refund_id ) {
		global $wpdb;
		$refund_id = absint( $refund_id );
		if ( ! $refund_id || ! class_exists( 'LNKup_Financial_Tables' ) ) {
			return;
		}
		$refunds = LNKup_Financial_Tables::refunds_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$refund = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$refunds} WHERE id = %d", $refund_id ), ARRAY_A );
		$payment_id = (int) ( $refund['payment_post_id'] ?? 0 );
		if ( ! $payment_id ) {
			return;
		}
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE payment_id = %d AND connector_id > 0",
				$payment_id
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$status = (string) ( $row['reward_status'] ?? '' );
			if ( 'reversed' === $status ) {
				continue;
			}
			if ( 'paid' === $status ) {
				LNKup_Connector::record_event( (int) $row['connector_id'], (int) $row['referral_id'], 0, 'reward_adjustment' );
				if ( class_exists( 'LNKup_Financial_Audit' ) ) {
					LNKup_Financial_Audit::log( 'connector_reward_paid_refunded', 'connector_reward', (int) $row['id'], array( 'refund_id' => $refund_id ) );
				}
				continue;
			}
			$wpdb->update(
				$table,
				array( 'reward_status' => 'reversed' ),
				array( 'id' => (int) $row['id'] )
			);
			LNKup_Connector::record_event( (int) $row['connector_id'], (int) $row['referral_id'], 0, 'reward_reversed' );
			if ( class_exists( 'LNKup_Financial_Audit' ) ) {
				LNKup_Financial_Audit::log( 'connector_reward_reversed', 'connector_reward', (int) $row['id'], array( 'refund_id' => $refund_id ) );
			}
		}
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
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_type, amount, reward_status, payment_id, available_at, created_at, approved_at, paid_at, referral_id FROM {$table} WHERE connector_id = %d ORDER BY created_at DESC LIMIT 50",
				absint( $connector_id )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$row['display_status'] = self::display_status( $row );
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Split balances by display status. Estimated is never included.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function balances_for_connector( $connector_id ) {
		$balances = array(
			'pending_hold'      => 0.0,
			'awaiting_approval' => 0.0,
			'approved'          => 0.0,
			'paid'              => 0.0,
			'reversed'          => 0.0,
			'expired'           => 0.0,
		);
		foreach ( self::list_for_connector( $connector_id ) as $row ) {
			$key = $row['display_status'];
			if ( isset( $balances[ $key ] ) ) {
				$balances[ $key ] += (float) $row['amount'];
			}
		}
		return $balances;
	}

	/**
	 * Count unique payments already queued as rewards for a connector.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function count_unique_payments( $connector_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT payment_id) FROM {$table} WHERE connector_id = %d AND payment_id > 0 AND reward_status != 'reversed'",
				absint( $connector_id )
			)
		);
	}

	/**
	 * Count rewards by status for connector.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function count_by_connector( $connector_id ) {
		$balances = self::balances_for_connector( $connector_id );
		$pending  = 0;
		$approved = 0;
		foreach ( self::list_for_connector( $connector_id ) as $row ) {
			if ( in_array( $row['display_status'], array( 'pending_hold', 'awaiting_approval' ), true ) ) {
				++$pending;
			}
			if ( 'approved' === $row['display_status'] ) {
				++$approved;
			}
		}
		return array(
			'pending'           => $pending,
			'approved'          => $approved,
			'pending_hold'      => $balances['pending_hold'],
			'awaiting_approval' => $balances['awaiting_approval'],
			'approved_amount'   => $balances['approved'],
			'paid'              => $balances['paid'],
			'reversed'          => $balances['reversed'],
		);
	}
}
