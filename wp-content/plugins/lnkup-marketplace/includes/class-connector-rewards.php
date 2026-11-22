<?php
/**
 * City Connector reward tracking (manual by default).
 *
 * Booking rewards follow the connector's locked commission rule (legacy connectors: a share of
 * booth GMV; later connectors: a share of LNKup fees once that rule is published), with a hold
 * period and admin approve / mark-paid. No Stripe payout here.
 *
 * Ledger lifecycle (reward_status): pending -> approved -> paid, with on_hold for reviews and
 * reversed once fully refunded. Partial refunds raise reversed_amount and keep the status, so
 * every balance uses the net amount (amount - reversed_amount).
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
		// Refund and chargeback reversals arrive through LNKup_Referral_Reversals for every refund path.
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
	 *
	 * @param int $connector_id Optional connector; overlays that connector's locked rule.
	 */
	public static function terms( $connector_id = 0 ) {
		$s = lnkup_get_settings();
		$cap = (float) ( $s['connector_booking_share_cap'] ?? 0 );
		$terms = array(
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
			'basis'                     => 'booth_gmv',
			'basis_label'               => '',
			'rule_id'                   => 0,
			'rule_version'              => 0,
			'program'                   => LNKup_Connector::PROGRAM,
			'terms_pending'             => false,
			'target_role'               => '',
		);
		$connector = $connector_id ? LNKup_Connector::get( (int) $connector_id ) : null;
		$program   = LNKup_Connector::program_of( $connector );
		$rule      = $connector_id ? self::rule_for( (int) $connector_id ) : null;
		if ( LNKup_Connector::PROGRAM !== $program ) {
			// Affiliate / strategic accounts earn only under a published rule; until then referrals are tracked only.
			$published                       = $rule && in_array( (string) $rule['status'], array( 'active', 'superseded' ), true );
			$terms['program']                = $program;
			$terms['enabled']                = $published;
			$terms['terms_pending']          = ! $published;
			$terms['vendor_referrals_rewarded'] = $published && in_array( (string) $rule['target_role'], array( '', 'vendor' ), true );
			$terms['vendor_signup_credit']   = 0.0;
			if ( ! $published ) {
				$terms['share_percent'] = 0.0;
				$terms['share_cap']     = 0.0;
				return $terms;
			}
		}
		if ( $rule ) {
			$terms['target_role']   = (string) $rule['target_role'];
			$terms['share_percent'] = (float) $rule['rate'];
			$terms['share_cap']     = (float) $rule['cap'];
			$terms['term_months']   = max( 1, (int) $rule['term_months'] );
			$terms['hold_days']     = max( 0, (int) $rule['hold_days'] );
			$terms['basis']         = (string) $rule['basis'];
			$terms['basis_label']   = LNKup_Commission_Rules::basis_label( $rule );
			$terms['rule_id']       = (int) $rule['id'];
			$terms['rule_version']  = (int) $rule['version'];
		}
		return $terms;
	}

	/**
	 * Locked commission rule for a connector, when the Partnership OS schema is installed.
	 *
	 * @param int $connector_id Connector ID.
	 * @return array<string, mixed>|null
	 */
	public static function rule_for( $connector_id ) {
		if ( ! class_exists( 'LNKup_Partner_Tables' ) || ! LNKup_Partner_Tables::ready() ) {
			return null;
		}
		return LNKup_Commission_Rules::rule_for_connector( absint( $connector_id ) );
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
	 * Commission dollars for a booth GMV amount using the legacy (settings) terms.
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
	 * Planner estimate per booking for a booth price under the connector's rule.
	 *
	 * @param float $booth_price  Booth price.
	 * @param int   $connector_id Connector ID (0 = legacy terms).
	 */
	public static function estimate_per_booking( $booth_price, $connector_id = 0 ) {
		if ( $connector_id && self::terms( (int) $connector_id )['terms_pending'] ) {
			return 0.0;
		}
		$rule = $connector_id ? self::rule_for( (int) $connector_id ) : null;
		if ( $rule ) {
			return LNKup_Commission_Rules::estimate_per_booking( $rule, (float) $booth_price );
		}
		return self::amount_from_gmv( $booth_price );
	}

	/**
	 * Estimate from total attributed booth GMV. Only meaningful for a GMV-based rule; null
	 * otherwise, so no estimate is shown rather than a misleading one.
	 *
	 * @param float $gmv          Attributed booth GMV.
	 * @param int   $connector_id Connector ID.
	 * @return float|null
	 */
	public static function estimate_from_gmv_total( $gmv, $connector_id = 0 ) {
		if ( $connector_id && self::terms( (int) $connector_id )['terms_pending'] ) {
			return null;
		}
		$rule = $connector_id ? self::rule_for( (int) $connector_id ) : null;
		if ( ! $rule ) {
			return self::amount_from_gmv( $gmv );
		}
		if ( 'booth_gmv' !== $rule['basis'] ) {
			return null;
		}
		return LNKup_Commission_Rules::amount_from_base( $rule, (float) $gmv );
	}

	/**
	 * Commission for a completed payment.
	 *
	 * @param int $payment_id   Payment post ID.
	 * @param int $connector_id Connector ID (0 = legacy terms).
	 * @return array{amount: float, basis: string, basis_amount: float, rule_id: int, rule_version: int, hold_days: int}
	 */
	public static function calculate_booking_amount( $payment_id, $connector_id = 0 ) {
		$rule = $connector_id ? self::rule_for( (int) $connector_id ) : null;
		if ( $connector_id && ( ! $rule || 'draft' === $rule['status'] ) && LNKup_Connector::PROGRAM !== LNKup_Connector::program_of( LNKup_Connector::get( (int) $connector_id ) ) ) {
			return array( 'amount' => 0.0, 'basis' => '', 'basis_amount' => 0.0, 'rule_id' => 0, 'rule_version' => 0, 'hold_days' => 0 );
		}
		if ( $rule ) {
			$calc              = LNKup_Commission_Rules::calculate( $rule, (int) $payment_id );
			$calc['hold_days'] = (int) $rule['hold_days'];
			return $calc;
		}
		$gmv = self::booth_gmv( $payment_id );
		return array(
			'amount'       => self::amount_from_gmv( $gmv ),
			'basis'        => 'booth_gmv',
			'basis_amount' => $gmv,
			'rule_id'      => 0,
			'rule_version' => 0,
			'hold_days'    => (int) self::terms()['hold_days'],
		);
	}

	/**
	 * Estimate earnings from planner inputs (backend source of truth).
	 *
	 * @param float $booth_price    Average booth price.
	 * @param int   $paid_bookings  Expected paid bookings per organizer.
	 * @param int   $organizers     Active organizers.
	 * @param int   $connector_id   Connector whose rule applies (0 = legacy terms).
	 */
	public static function estimate_earnings( $booth_price, $paid_bookings, $organizers = 1, $connector_id = 0 ) {
		$booth_price   = max( 0.0, (float) $booth_price );
		$paid_bookings = max( 0, (int) $paid_bookings );
		$organizers    = max( 1, (int) $organizers );
		$per_booking   = self::estimate_per_booking( $booth_price, $connector_id );
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
	 * @param array $referral     Referral row.
	 * @param int   $connector_id Connector whose rule term applies (0 = legacy terms).
	 */
	public static function within_reward_window( $referral, $connector_id = 0 ) {
		$terms  = self::terms( $connector_id );
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
		if ( in_array( $status, array( 'paid', 'approved', 'reversed', 'expired', 'on_hold' ), true ) ) {
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
	 * Net amount still owed or paid after partial reversals.
	 *
	 * @param array $row Reward row.
	 */
	public static function net_amount( $row ) {
		return round( max( 0.0, (float) ( $row['amount'] ?? 0 ) - (float) ( $row['reversed_amount'] ?? 0 ) ), 2 );
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
		if ( class_exists( 'LNKup_Attribution_Arbiter' ) && ! LNKup_Attribution_Arbiter::allows( absint( $referral_id ) ) ) {
			return;
		}
		self::insert_pending_reward( $connector_id, $referral_id, 'connector_vendor_signup', $settings['vendor_signup_credit'] );
	}

	/**
	 * Queue paid booking reward under the connector's rule, once per payment.
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
		$terms = self::terms( $connector_id );
		if ( ! $terms['enabled'] ) {
			return 0;
		}
		if ( ! $is_organizer && ! $terms['vendor_referrals_rewarded'] ) {
			return 0;
		}
		if ( $is_organizer && 'vendor' === $terms['target_role'] ) {
			return 0;
		}
		$pay_status = sanitize_key( (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) );
		if ( in_array( $pay_status, array( 'refunded', 'failed', 'cancelled' ), true ) ) {
			return 0;
		}
		$calc = self::calculate_booking_amount( $payment_id, $connector_id );
		if ( $calc['amount'] <= 0 ) {
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
		if ( ! $ref || ! self::within_reward_window( $ref, $connector_id ) ) {
			return 0;
		}
		if ( class_exists( 'LNKup_Attribution_Arbiter' ) && ! LNKup_Attribution_Arbiter::allows( $referral_id, $payment_id ) ) {
			return 0;
		}
		return self::insert_pending_reward( $connector_id, $referral_id, 'connector_booking_fee', $calc['amount'], $payment_id, $calc );
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
	 * @param array  $calc         Optional rule stamp from calculate_booking_amount().
	 */
	public static function insert_pending_reward( $connector_id, $referral_id, $event_type, $amount, $source_id = 0, $calc = array() ) {
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
		$hold    = isset( $calc['hold_days'] ) ? (int) $calc['hold_days'] : (int) self::terms()['hold_days'];
		$user_id = (int) ( $connector['user_id'] ?? 0 );
		$program = LNKup_Connector::program_of( $connector );
		$row     = array(
			'referral_id'   => absint( $referral_id ),
			'user_id'       => $user_id,
			'connector_id'  => absint( $connector_id ),
			'event_type'    => sanitize_key( $event_type ),
			'amount'        => (float) $amount,
			'status'        => 'pending',
			'reward_status' => 'pending',
			'created_at'    => current_time( 'mysql' ),
			'program'       => $program,
		);
		if ( $source_id > 0 ) {
			$row['payment_id']    = $source_id;
			$row['available_at']  = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $hold * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		}
		if ( class_exists( 'LNKup_Partner_Tables' ) && LNKup_Partner_Tables::ready() ) {
			$rule = $calc ? null : self::rule_for( (int) $connector_id );
			$row += array(
				'rule_id'         => (int) ( $calc['rule_id'] ?? ( $rule['id'] ?? 0 ) ),
				'rule_version'    => (int) ( $calc['rule_version'] ?? ( $rule['version'] ?? 0 ) ),
				'basis'           => (string) ( $calc['basis'] ?? 'fixed' ),
				'basis_amount'    => round( (float) ( $calc['basis_amount'] ?? 0 ), 2 ),
				'idempotency_key' => LNKup_Partner_Tables::reward_key( $program, $user_id, absint( $referral_id ), $source_id, (string) $event_type ),
			);
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
				LNKup_Financial_Audit::log( 'connector_reward_queued', 'connector_reward', $reward_id, array( 'amount' => (float) $amount, 'payment_id' => $source_id, 'rule_id' => (int) ( $row['rule_id'] ?? 0 ) ) );
			}
			do_action( 'lnkup_partner_commission_recorded', $reward_id, $program, absint( $referral_id ), $source_id );
		}
		return $reward_id;
	}

	/**
	 * @param int $reward_id Reward ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_reward( $reward_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $reward_id ) ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Move a reward between statuses atomically; WP_Error when another request won the race.
	 *
	 * @param int                  $reward_id Reward ID.
	 * @param string               $from      Required current reward_status.
	 * @param array<string, mixed> $set       Columns to write (must include reward_status).
	 * @return true|WP_Error
	 */
	private static function transition( $reward_id, $from, array $set ) {
		global $wpdb;
		$table   = LNKup_Treasury_Tables::referral_rewards_table();
		$assign  = array();
		$values  = array();
		foreach ( $set as $col => $val ) {
			$assign[] = '`' . sanitize_key( $col ) . '` = ' . ( is_int( $val ) ? '%d' : ( is_float( $val ) ? '%f' : '%s' ) );
			$values[] = $val;
		}
		$values[] = absint( $reward_id );
		$values[] = $from;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $assign ) . ' WHERE id = %d AND reward_status = %s AND connector_id > 0', $values ) );
		if ( 1 !== (int) $updated ) {
			return new WP_Error( 'lnkup_reward_state', __( 'This reward is not in a state that allows that action. Refresh and try again.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Admin approve reward. Requires the hold period to have ended.
	 *
	 * @param int $reward_id Reward ID.
	 * @param int $admin_id  Admin user ID.
	 */
	public static function approve_reward( $reward_id, $admin_id = 0 ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		$row   = self::get_reward( $reward_id );
		if ( ! $row || 'pending' !== ( $row['reward_status'] ?? '' ) ) {
			return new WP_Error( 'invalid_reward', __( 'Reward not found or already processed.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$available = strtotime( (string) ( $row['available_at'] ?? '' ) );
		if ( $available && current_time( 'timestamp' ) < $available ) {
			return new WP_Error( 'lnkup_reward_on_hold', __( 'This reward is still in its refund and dispute hold period.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( self::net_amount( $row ) <= 0 ) {
			return new WP_Error( 'lnkup_reward_reversed', __( 'This reward was fully reversed.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$now    = current_time( 'mysql' );
		$result = self::transition(
			$reward_id,
			'pending',
			array(
				'reward_status' => 'approved',
				'approved_by'   => absint( $admin_id ?: get_current_user_id() ),
				'approved_at'   => $now,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$settings = self::settings();
		if ( $settings['enabled'] && 'platform_credit' === $settings['mode'] && class_exists( 'LNKup_Credits' ) ) {
			$credit_id = LNKup_Credits::grant( (int) $row['user_id'], self::net_amount( $row ), 'referral', 'connector_reward', $reward_id );
			$wpdb->update( $table, array( 'credit_id' => (int) $credit_id, 'status' => 'available' ), array( 'id' => absint( $reward_id ) ) );
		}
		LNKup_Connector::record_event( (int) ( $row['connector_id'] ?? 0 ), (int) ( $row['referral_id'] ?? 0 ), 0, 'reward_approved' );
		if ( 0 === strpos( (string) ( $row['event_type'] ?? '' ), 'connector_rank_bonus_' ) && class_exists( 'LNKup_Connector_Ranks' ) ) {
			LNKup_Connector_Ranks::record_rank_event( (int) ( $row['connector_id'] ?? 0 ), 'rank_bonus_approved', array( 'reward_id' => absint( $reward_id ) ) );
		}
		if ( class_exists( 'LNKup_Financial_Audit' ) ) {
			LNKup_Financial_Audit::log( 'connector_reward_approved', 'connector_reward', absint( $reward_id ), array( 'amount' => self::net_amount( $row ) ) );
		}
		do_action( 'lnkup_partner_commission_approved', absint( $reward_id ) );
		return true;
	}

	/**
	 * Mark an approved reward paid (manual payout recorded by an admin). Only once.
	 *
	 * @param int    $reward_id        Reward ID.
	 * @param string $payout_reference Admin-entered reference (check number, transfer ID).
	 * @return true|WP_Error
	 */
	public static function mark_paid( $reward_id, $payout_reference = '' ) {
		$row = self::get_reward( $reward_id );
		if ( ! $row ) {
			return new WP_Error( 'invalid_reward', __( 'Reward not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$set = array(
			'reward_status' => 'paid',
			'paid_at'       => current_time( 'mysql' ),
		);
		if ( class_exists( 'LNKup_Partner_Tables' ) && LNKup_Partner_Tables::ready() ) {
			$set['payout_reference'] = substr( sanitize_text_field( (string) $payout_reference ), 0, 128 );
		}
		$result = self::transition( $reward_id, 'approved', $set );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		LNKup_Connector::record_event( (int) ( $row['connector_id'] ?? 0 ), (int) ( $row['referral_id'] ?? 0 ), 0, 'reward_paid' );
		if ( 0 === strpos( (string) ( $row['event_type'] ?? '' ), 'connector_rank_bonus_' ) && class_exists( 'LNKup_Connector_Ranks' ) ) {
			LNKup_Connector_Ranks::record_rank_event( (int) ( $row['connector_id'] ?? 0 ), 'rank_bonus_paid', array( 'reward_id' => absint( $reward_id ) ) );
		}
		if ( class_exists( 'LNKup_Financial_Audit' ) ) {
			LNKup_Financial_Audit::log( 'connector_reward_paid', 'connector_reward', absint( $reward_id ), array( 'amount' => self::net_amount( $row ), 'payout_reference' => (string) $payout_reference ) );
		}
		do_action( 'lnkup_partner_commission_paid', absint( $reward_id ) );
		return true;
	}

	/**
	 * Hold a pending or approved reward for review.
	 *
	 * @param int    $reward_id Reward ID.
	 * @param string $reason    Reason slug.
	 * @return true|WP_Error
	 */
	public static function hold( $reward_id, $reason = 'review' ) {
		$row  = self::get_reward( $reward_id );
		$from = (string) ( $row['reward_status'] ?? '' );
		if ( ! $row || ! in_array( $from, array( 'pending', 'approved' ), true ) ) {
			return new WP_Error( 'lnkup_reward_state', __( 'Only pending or approved rewards can be held.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$result = self::transition(
			$reward_id,
			$from,
			array(
				'reward_status'    => 'on_hold',
				'held_from_status' => $from,
				'hold_reason'      => substr( sanitize_key( (string) $reason ), 0, 64 ),
			)
		);
		if ( ! is_wp_error( $result ) ) {
			LNKup_Financial_Audit::log( 'connector_reward_held', 'connector_reward', absint( $reward_id ), array( 'reason' => $reason, 'from' => $from ) );
		}
		return $result;
	}

	/**
	 * Release a held reward back to the status it was held from.
	 *
	 * @param int $reward_id Reward ID.
	 * @return true|WP_Error
	 */
	public static function release( $reward_id ) {
		$row = self::get_reward( $reward_id );
		if ( ! $row || 'on_hold' !== ( $row['reward_status'] ?? '' ) ) {
			return new WP_Error( 'lnkup_reward_state', __( 'This reward is not on hold.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$to     = in_array( (string) $row['held_from_status'], array( 'pending', 'approved' ), true ) ? (string) $row['held_from_status'] : 'pending';
		$result = self::transition(
			$reward_id,
			'on_hold',
			array(
				'reward_status'    => $to,
				'held_from_status' => '',
				'hold_reason'      => '',
			)
		);
		if ( ! is_wp_error( $result ) ) {
			LNKup_Financial_Audit::log( 'connector_reward_released', 'connector_reward', absint( $reward_id ), array( 'to' => $to ) );
		}
		return $result;
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
		if ( $payment_id ) {
			self::reverse_for_payment( $payment_id, 'refund' );
		}
	}

	/**
	 * Reverse connector rewards in proportion to the share of the booking returned.
	 *
	 * Unpaid rewards lose that share (fully reversed at 100%). Paid rewards cannot be pulled
	 * back automatically; the owed share opens one clawback review per reward.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $why        refund|chargeback.
	 * @param float  $ratio      Cumulative share of the booking returned (0..1).
	 */
	public static function reverse_for_payment( int $payment_id, string $why = 'refund', float $ratio = 1.0 ): void {
		global $wpdb;
		$ratio = max( 0.0, min( 1.0, $ratio ) );
		if ( $payment_id <= 0 || $ratio <= 0 || ! class_exists( 'LNKup_Treasury_Tables' ) ) {
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
			$amount = (float) $row['amount'];
			$target = round( $amount * $ratio, 2 );

			if ( 'paid' === $status ) {
				self::flag_paid_clawback( $row, $payment_id, $target, $why );
				continue;
			}

			$already = (float) ( $row['reversed_amount'] ?? 0 );
			$delta   = round( $target - $already, 2 );
			if ( $delta <= 0 ) {
				continue;
			}
			$full = $target + 0.004 >= $amount;
			$set  = array( 'reversed_amount' => $target );
			if ( $full ) {
				$set['reward_status'] = 'reversed';
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( $table, $set, array( 'id' => (int) $row['id'], 'reward_status' => $status ) );
			LNKup_Connector::record_event( (int) $row['connector_id'], (int) $row['referral_id'], 0, $full ? 'reward_reversed' : 'reward_adjustment' );
			if ( class_exists( 'LNKup_Financial_Audit' ) ) {
				LNKup_Financial_Audit::log(
					$full ? 'connector_reward_reversed' : 'connector_reward_partially_reversed',
					'connector_reward',
					(int) $row['id'],
					array( 'payment_id' => $payment_id, 'reason' => $why, 'reversed' => $delta, 'ratio' => $ratio )
				);
			}
		}
	}

	/**
	 * Record the owed share of a paid reward for admin clawback review (idempotent per reward).
	 *
	 * @param array  $row        Reward row.
	 * @param int    $payment_id Payment ID.
	 * @param float  $owed       Amount owed back.
	 * @param string $why        Reason.
	 */
	private static function flag_paid_clawback( array $row, int $payment_id, float $owed, string $why ): void {
		$reward_id = (int) $row['id'];
		if ( class_exists( 'LNKup_Partner_Reviews' ) && LNKup_Partner_Tables::ready() ) {
			$key      = 'clawback:' . $reward_id;
			$existing = LNKup_Partner_Reviews::get_by_key( $key );
			if ( $existing ) {
				LNKup_Partner_Reviews::raise_amount( (int) $existing['id'], $owed );
				return;
			}
			LNKup_Partner_Reviews::open(
				'clawback',
				$key,
				array(
					'reward_id'    => $reward_id,
					'payment_id'   => $payment_id,
					'referral_ids' => array( (int) $row['referral_id'] ),
					'amount'       => $owed,
					'payload'      => array( 'reason' => $why, 'program' => (string) ( $row['program'] ?: LNKup_Connector::PROGRAM ) ),
				)
			);
		} else {
			$flag = '_lnkup_connector_clawback_flagged_' . $reward_id;
			if ( get_post_meta( $payment_id, $flag, true ) ) {
				return;
			}
			update_post_meta( $payment_id, $flag, current_time( 'mysql' ) );
		}
		LNKup_Connector::record_event( (int) $row['connector_id'], (int) $row['referral_id'], 0, 'reward_adjustment' );
		if ( class_exists( 'LNKup_Financial_Audit' ) ) {
			LNKup_Financial_Audit::log( 'connector_reward_clawback_review', 'connector_reward', $reward_id, array( 'payment_id' => $payment_id, 'reason' => $why, 'amount' => $owed ) );
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
				"SELECT * FROM {$table} WHERE connector_id = %d ORDER BY created_at DESC LIMIT 50",
				absint( $connector_id )
			),
			ARRAY_A
		);
		$keep = array( 'id', 'event_type', 'amount', 'reversed_amount', 'reward_status', 'payment_id', 'available_at', 'created_at', 'approved_at', 'paid_at', 'referral_id', 'basis', 'rule_version' );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$item                   = array_intersect_key( $row, array_flip( $keep ) );
			$item['net_amount']     = self::net_amount( $row );
			$item['display_status'] = self::display_status( $row );
			$out[]                  = $item;
		}
		return $out;
	}

	/**
	 * Split balances by display status using net amounts. Estimated is never included.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function balances_for_connector( $connector_id ) {
		$balances = array(
			'pending_hold'      => 0.0,
			'awaiting_approval' => 0.0,
			'on_hold'           => 0.0,
			'approved'          => 0.0,
			'paid'              => 0.0,
			'reversed'          => 0.0,
			'expired'           => 0.0,
		);
		foreach ( self::list_for_connector( $connector_id ) as $row ) {
			$key = $row['display_status'];
			if ( isset( $balances[ $key ] ) && 'reversed' !== $key ) {
				$balances[ $key ] += (float) $row['net_amount'];
			}
			$balances['reversed'] += (float) ( $row['reversed_amount'] ?? 0 );
			if ( 'reversed' === $key && (float) ( $row['reversed_amount'] ?? 0 ) <= 0 ) {
				$balances['reversed'] += (float) $row['amount'];
			}
		}
		foreach ( $balances as $k => $v ) {
			$balances[ $k ] = round( $v, 2 );
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
