<?php
/**
 * One reversal path for every referral program when booking money goes back.
 *
 * Listens to `lnkup_payment_refund_synced` (admin refunds, Stripe Dashboard refunds and
 * dispute credits all pass through LNKup_Refunds::sync_payment_refund_status) and
 * `lnkup_payment_chargeback_lost`. Every step is idempotent so webhook retries and repeated
 * syncs never reverse twice.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Referral_Reversals {

	public static function init() {
		add_action( 'lnkup_payment_refund_synced', array( __CLASS__, 'on_refund_synced' ), 10, 3 );
		add_action( 'lnkup_payment_chargeback_lost', array( __CLASS__, 'on_chargeback_lost' ), 10, 1 );
	}

	/**
	 * @param int  $payment_id     Payment ID.
	 * @param int  $returned_cents Cumulative cents returned to the vendor.
	 * @param bool $is_full        Booking fully refunded.
	 */
	public static function on_refund_synced( $payment_id, $returned_cents, $is_full ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id ) {
			return;
		}
		$gross = (int) round( (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ) * 100 );
		$ratio = $is_full ? 1.0 : ( $gross > 0 ? min( 1.0, max( 0, (int) $returned_cents ) / $gross ) : 0.0 );
		self::apply( $payment_id, (int) $returned_cents, $ratio, false, 'refund' );
	}

	/**
	 * @param int $payment_id Payment ID.
	 */
	public static function on_chargeback_lost( $payment_id ) {
		$payment_id = absint( $payment_id );
		if ( $payment_id ) {
			self::apply( $payment_id, 0, 1.0, true, 'chargeback' );
		}
	}

	/**
	 * @param int    $payment_id     Payment ID.
	 * @param int    $returned_cents Cumulative cents returned.
	 * @param float  $ratio          Share of the booking that was returned (0..1).
	 * @param bool   $forfeit        Lost chargeback.
	 * @param string $why            refund|chargeback.
	 */
	private static function apply( $payment_id, $returned_cents, $ratio, $forfeit, $why ) {
		if ( class_exists( 'LNKup_Platform_Fees' ) ) {
			LNKup_Platform_Fees::sync_refunded_fee( $payment_id, $returned_cents, $forfeit );
		}
		if ( class_exists( 'LNKup_Connector_Rewards' ) ) {
			LNKup_Connector_Rewards::reverse_for_payment( (int) $payment_id, (string) $why, (float) $ratio );
		}
		if ( class_exists( 'LNKup_Connector_Ranks' ) ) {
			LNKup_Connector_Ranks::reverse_payment( $payment_id, 'refund' );
		}
		self::reverse_organizer_recruit( $payment_id, $ratio, $why );
		do_action( 'lnkup_referral_rewards_reversed', $payment_id, $ratio, $why );
	}

	/**
	 * Claw back organizer-recruit credit in proportion to the refunded share.
	 *
	 * Unspent credit is revoked from the referrer's wallet; anything already spent is
	 * logged for admin review rather than pushing the wallet negative.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param float  $ratio      Returned share (0..1).
	 * @param string $why        Reason.
	 */
	public static function reverse_organizer_recruit( $payment_id, $ratio, $why = 'refund' ) {
		global $wpdb;
		if ( $ratio <= 0 || ! class_exists( 'LNKup_Treasury_Tables' ) ) {
			return;
		}
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE payment_id = %d AND program = 'organizer_recruit'", absint( $payment_id ) ),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$reward_id = (int) $row['id'];
			$lock      = 'lnkup_orgrec_reverse_' . $reward_id;
			if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				continue;
			}
			try {
				self::reverse_one_recruit_reward( $reward_id, $ratio, $why );
			} finally {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/**
	 * @param int    $reward_id Reward row ID.
	 * @param float  $ratio     Returned share.
	 * @param string $why       Reason.
	 */
	private static function reverse_one_recruit_reward( $reward_id, $ratio, $why ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referral_rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $reward_id ), ARRAY_A );
		if ( ! $row ) {
			return;
		}
		$amount   = (float) $row['amount'];
		$reversed = (float) ( $row['reversed_amount'] ?? 0 );
		$target   = round( $amount * min( 1.0, $ratio ), 2 );
		$delta    = round( $target - $reversed, 2 );
		if ( $delta <= 0 ) {
			return;
		}

		$revoked   = self::revoke_credit( (int) $row['credit_id'], (int) $row['user_id'], $delta, $reward_id );
		$shortfall = round( $delta - $revoked, 2 );
		$new_total = round( $reversed + $delta, 2 );

		$wpdb->update(
			$table,
			array(
				'reversed_amount' => $new_total,
				'reward_status'   => $new_total + 0.004 >= $amount ? 'reversed' : 'partially_reversed',
			),
			array( 'id' => $reward_id ),
			array( '%f', '%s' ),
			array( '%d' )
		);

		$fee_share = round( (float) LNKup_Organizer_Recruit_Referrals::resolve_platform_fee_for_payment( (int) $row['payment_id'] ) * ( $delta / max( 0.01, $amount ) ), 2 );
		if ( $fee_share > 0 && ! empty( $row['referral_id'] ) ) {
			$ref_table = LNKup_Treasury_Tables::referrals_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "UPDATE {$ref_table} SET attributed_revenue = GREATEST(attributed_revenue - %f, 0) WHERE id = %d", $fee_share, (int) $row['referral_id'] ) );
		}

		LNKup_Financial_Audit::log(
			'organizer_recruit_reward_reversed',
			'referral_reward',
			$reward_id,
			array(
				'reason'     => $why,
				'reversed'   => $delta,
				'revoked'    => $revoked,
				'shortfall'  => $shortfall,
				'payment_id' => (int) $row['payment_id'],
			)
		);
		if ( $shortfall > 0 ) {
			LNKup_Financial_Audit::log( 'organizer_recruit_clawback_review', 'referral_reward', $reward_id, array( 'amount' => $shortfall ) );
		}
	}

	/**
	 * Revoke up to $amount of unspent credit. Returns the amount actually revoked.
	 *
	 * @param int   $credit_id Credit row.
	 * @param int   $user_id   Credit owner.
	 * @param float $amount    Amount to revoke.
	 * @param int   $reward_id Reward row (for the wallet ledger reference).
	 */
	private static function revoke_credit( $credit_id, $user_id, $amount, $reward_id ) {
		global $wpdb;
		if ( $credit_id <= 0 || $user_id <= 0 || ! class_exists( 'LNKup_Financial_Tables' ) ) {
			return 0.0;
		}
		$table = LNKup_Financial_Tables::credits_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$credit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $credit_id, $user_id ), ARRAY_A );
		if ( ! $credit ) {
			return 0.0;
		}
		$take = round( min( (float) $credit['remaining'], $amount ), 2 );
		if ( $take <= 0 ) {
			return 0.0;
		}
		if ( class_exists( 'LNKup_Wallet' ) ) {
			$debit = LNKup_Wallet::debit( $user_id, $take, 'referral_reversal', $reward_id, 'vendor', 'organizer_recruit' );
			if ( is_wp_error( $debit ) ) {
				return 0.0;
			}
		}
		$remaining = round( (float) $credit['remaining'] - $take, 2 );
		$wpdb->update(
			$table,
			array(
				'remaining' => $remaining,
				'status'    => $remaining <= 0 ? 'revoked' : 'active',
			),
			array( 'id' => $credit_id ),
			array( '%f', '%s' ),
			array( '%d' )
		);
		return $take;
	}
}
