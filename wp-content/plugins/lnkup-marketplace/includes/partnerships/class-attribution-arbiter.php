<?php
/**
 * One attribution decision per referred account, one commission per payment.
 *
 * Policy (approved): the earliest verified referral wins, across every program. When two
 * different referrers attribute the same account inside the conflict window, both claims are
 * held and an admin picks the winner. A payment pays the organizer-side winner first, then the
 * vendor-side winner, and only one `exclusive` rule pays per payment.
 *
 * Modes: `off` (no checks), `shadow` (log what would be blocked, never block), `enforce`.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Attribution_Arbiter {

	const CRON_HOOK     = 'lnkup_partner_attribution_backfill';
	const BACKFILL_DONE = 'lnkup_partner_attribution_backfilled';
	const BATCH         = 200;

	const WINNING    = 'winning';
	const SUPERSEDED = 'superseded';
	const CONFLICT   = 'conflict';

	public static function init(): void {
		add_action( 'user_register', array( __CLASS__, 'resolve_user' ), 99, 1 );
		add_action( 'lnkup_organizer_recruit_attributed', array( __CLASS__, 'resolve_user' ), 20, 1 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_backfill' ) );
		add_action( 'init', array( __CLASS__, 'schedule_backfill' ), 30 );
	}

	public static function mode(): string {
		$mode = sanitize_key( (string) LNKup_Partnerships::setting( 'partnerships_arbiter_mode', 'shadow' ) );
		return in_array( $mode, array( 'off', 'shadow', 'enforce' ), true ) ? $mode : 'shadow';
	}

	public static function conflict_window_hours(): int {
		return max( 0, min( 720, (int) LNKup_Partnerships::setting( 'partnerships_conflict_window_hours', 72 ) ) );
	}

	// ------------------------------------------------------------------ Per-account decision.

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function referrals_for_user( int $user_id ): array {
		global $wpdb;
		$table = LNKup_Treasury_Tables::referrals_table();
		if ( $user_id <= 0 || ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE referred_user_id = %d ORDER BY created_at ASC, id ASC", $user_id ), ARRAY_A );
	}

	/**
	 * Decide and persist attribution_status for every referral row of a referred account.
	 *
	 * @param int|string $user_id Referred user.
	 * @return array<int, string> referral_id => status.
	 */
	public static function resolve_user( $user_id ): array {
		$user_id = absint( $user_id );
		if ( ! LNKup_Partner_Tables::ready() ) {
			return array();
		}
		$rows = self::referrals_for_user( $user_id );
		if ( ! $rows ) {
			return array();
		}
		$decision = self::decide( $user_id, $rows );
		global $wpdb;
		$table = LNKup_Treasury_Tables::referrals_table();
		foreach ( $rows as $row ) {
			$id     = (int) $row['id'];
			$status = $decision[ $id ] ?? self::SUPERSEDED;
			if ( ( $row['attribution_status'] ?? '' ) !== $status ) {
				$wpdb->update( $table, array( 'attribution_status' => $status ), array( 'id' => $id ) );
			}
		}
		return $decision;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Referral rows, oldest first.
	 * @return array<int, string>
	 */
	private static function decide( int $user_id, array $rows ): array {
		$out    = array();
		$review = LNKup_Partner_Reviews::get_by_key( self::conflict_key( $user_id ) );
		if ( $review && 'resolved' === $review['status'] && (int) $review['winner_referral_id'] ) {
			foreach ( $rows as $row ) {
				$out[ (int) $row['id'] ] = (int) $row['id'] === (int) $review['winner_referral_id'] ? self::WINNING : self::SUPERSEDED;
			}
			return $out;
		}

		$first    = $rows[0];
		$first_ts = (int) strtotime( (string) $first['created_at'] );
		$window   = self::conflict_window_hours() * HOUR_IN_SECONDS;
		$rivals   = array();
		foreach ( array_slice( $rows, 1 ) as $row ) {
			$ts       = (int) strtotime( (string) $row['created_at'] );
			$same_ref = (int) $row['referrer_user_id'] === (int) $first['referrer_user_id'];
			if ( $window > 0 && ! $same_ref && $ts - $first_ts <= $window ) {
				$rivals[] = $row;
			}
		}

		if ( ! $rivals ) {
			foreach ( $rows as $i => $row ) {
				$out[ (int) $row['id'] ] = 0 === $i ? self::WINNING : self::SUPERSEDED;
			}
			return $out;
		}

		$claimants = array_merge( array( $first ), $rivals );
		$ids       = array_map( static fn( $r ) => (int) $r['id'], $claimants );
		foreach ( $rows as $row ) {
			$out[ (int) $row['id'] ] = in_array( (int) $row['id'], $ids, true ) ? self::CONFLICT : self::SUPERSEDED;
		}
		LNKup_Partner_Reviews::open(
			'attribution_conflict',
			self::conflict_key( $user_id ),
			array(
				'referred_user_id' => $user_id,
				'referral_ids'     => $ids,
				'payload'          => array(
					'programs' => array_values( array_unique( array_map( static fn( $r ) => (string) $r['program'], $claimants ) ) ),
				),
			)
		);
		return $out;
	}

	private static function conflict_key( int $user_id ): string {
		return 'conflict:' . $user_id;
	}

	/**
	 * @param array<string, mixed> $referral Referral row.
	 */
	public static function status_for( array $referral ): string {
		$decision = self::resolve_user( (int) $referral['referred_user_id'] );
		return $decision[ (int) $referral['id'] ] ?? self::WINNING;
	}

	// ------------------------------------------------------------------ Per-payment decision.

	/**
	 * @return array{organizer_id: int, vendor_id: int}
	 */
	public static function payment_parties( int $payment_id ): array {
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$event_id     = (int) get_post_meta( $payment_id, '_lnkup_event_id', true );
		if ( ! $organizer_id && $event_id ) {
			$organizer_id = (int) get_post_field( 'post_author', $event_id );
		}
		return array(
			'organizer_id' => $organizer_id,
			'vendor_id'    => (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ),
		);
	}

	/**
	 * Whether a referral can still earn on new payments under its own program terms.
	 *
	 * @param array<string, mixed> $row Referral row.
	 */
	public static function is_live( array $row ): bool {
		$program = (string) ( $row['program'] ?? '' );
		if ( LNKup_Partner_Programs::is_account_program( $program ) ) {
			$cid = LNKup_Commission_Rules::connector_for_referral( $row );
			return $cid && LNKup_Connector::get_active( $cid ) && LNKup_Connector_Rewards::terms( $cid )['enabled'] && LNKup_Connector_Rewards::within_reward_window( $row, $cid );
		}
		switch ( $program ) {
			case LNKup_Partner_Programs::ORGANIZER_RECRUIT:
				$active = LNKup_Organizer_Recruit_Referrals::get_active_referral_for_user( (int) $row['referred_user_id'] );
				return LNKup_Organizer_Recruit_Referrals::is_enabled() && $active && 'active' === ( $active['status'] ?? '' );
			default:
				return 'converted' !== ( $row['status'] ?? '' );
		}
	}

	/**
	 * Winning referral for a payment: organizer side first, then vendor side.
	 */
	public static function winner_for_payment( int $payment_id ): int {
		$parties = self::payment_parties( $payment_id );
		foreach ( array( $parties['organizer_id'], $parties['vendor_id'] ) as $uid ) {
			if ( ! $uid ) {
				continue;
			}
			$decision = self::resolve_user( $uid );
			foreach ( self::referrals_for_user( $uid ) as $row ) {
				$status = $decision[ (int) $row['id'] ] ?? '';
				if ( self::WINNING === $status && self::is_live( $row ) && ! self::is_stackable( $row ) ) {
					return (int) $row['id'];
				}
			}
		}
		return 0;
	}

	/**
	 * @param array<string, mixed> $row Referral row.
	 */
	private static function is_stackable( array $row ): bool {
		$rule = LNKup_Commission_Rules::rule_for_referral( $row );
		return $rule && 'payment_stackable' === ( $rule['stacking_policy'] ?? '' );
	}

	/**
	 * Gate a reward before a program handler writes it.
	 *
	 * @return array{allow: bool, reason: string}
	 */
	public static function may_reward( int $referral_id, int $payment_id = 0 ): array {
		$mode = self::mode();
		if ( 'off' === $mode || ! LNKup_Partner_Tables::ready() ) {
			return array( 'allow' => true, 'reason' => 'arbiter_off' );
		}
		$referral = self::referral( $referral_id );
		if ( ! $referral ) {
			return array( 'allow' => true, 'reason' => 'unknown_referral' );
		}
		$reason = '';
		$status = self::status_for( $referral );
		if ( self::SUPERSEDED === $status ) {
			$reason = 'superseded';
		} elseif ( self::CONFLICT === $status ) {
			$reason = 'conflict';
			$review = LNKup_Partner_Reviews::get_by_key( self::conflict_key( (int) $referral['referred_user_id'] ) );
			if ( $review && $payment_id ) {
				LNKup_Partner_Reviews::merge_payload( (int) $review['id'], array( 'deferred_payments' => array( $payment_id ) ) );
			}
		} elseif ( $payment_id && ! self::is_stackable( $referral ) ) {
			$winner = self::winner_for_payment( $payment_id );
			if ( $winner && $winner !== $referral_id ) {
				$reason = 'payment_winner_other';
			}
		}

		if ( '' === $reason ) {
			return array( 'allow' => true, 'reason' => 'winner' );
		}
		LNKup_Financial_Audit::log(
			'shadow' === $mode ? 'partner_arbiter_shadow_block' : 'partner_arbiter_blocked',
			'referral',
			$referral_id,
			array( 'reason' => $reason, 'payment_id' => $payment_id, 'program' => (string) $referral['program'] )
		);
		return array( 'allow' => 'shadow' === $mode, 'reason' => $reason );
	}

	public static function allows( int $referral_id, int $payment_id = 0 ): bool {
		return self::may_reward( $referral_id, $payment_id )['allow'];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function referral( int $referral_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Treasury_Tables::referrals_table() . ' WHERE id = %d', $referral_id ), ARRAY_A );
		return $row ?: null;
	}

	// ------------------------------------------------------------------ Conflict resolution.

	/**
	 * Admin picks the winning claim; payments deferred during the conflict are replayed for it.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function resolve_conflict( int $review_id, int $winner_referral_id, int $actor = 0, string $notes = '' ) {
		$review = LNKup_Partner_Reviews::get( $review_id );
		if ( ! $review || 'attribution_conflict' !== $review['type'] ) {
			return new WP_Error( 'lnkup_review_not_found', __( 'Review not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $winner_referral_id, $review['referral_ids'], true ) ) {
			return new WP_Error( 'lnkup_review_winner', __( 'Pick one of the competing referrals.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! LNKup_Partner_Reviews::close( $review_id, 'winner_selected', $winner_referral_id, $notes, $actor ) ) {
			return new WP_Error( 'lnkup_review_state', __( 'This review was already resolved.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		self::resolve_user( (int) $review['referred_user_id'] );
		$winner   = self::referral( $winner_referral_id );
		$replayed = array();
		foreach ( (array) ( $review['payload']['deferred_payments'] ?? array() ) as $payment_id ) {
			$payment_id = absint( $payment_id );
			if ( $winner && $payment_id && self::replay_payment( $winner, $payment_id ) ) {
				$replayed[] = $payment_id;
			}
		}
		LNKup_Financial_Audit::log( 'partner_attribution_resolved', 'referral', $winner_referral_id, array( 'review_id' => $review_id, 'replayed_payments' => $replayed ) );
		return array(
			'review_id'          => $review_id,
			'winner_referral_id' => $winner_referral_id,
			'replayed_payments'  => $replayed,
		);
	}

	/**
	 * Re-run only the idempotent reward step of the winning program for one payment.
	 *
	 * @param array<string, mixed> $referral Winning referral row.
	 */
	public static function replay_payment( array $referral, int $payment_id ): bool {
		$status = sanitize_key( (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) );
		if ( in_array( $status, array( 'refunded', 'failed', 'cancelled' ), true ) ) {
			return false;
		}
		if ( LNKup_Partner_Programs::is_account_program( (string) $referral['program'] ) ) {
			$cid     = LNKup_Commission_Rules::connector_for_referral( $referral );
			$parties = self::payment_parties( $payment_id );
			return $cid && LNKup_Connector_Rewards::maybe_queue_paid_booking_reward( $cid, (int) $referral['id'], $payment_id, (int) $referral['referred_user_id'] === $parties['organizer_id'] ) > 0;
		}
		switch ( (string) $referral['program'] ) {
			case LNKup_Partner_Programs::ORGANIZER_RECRUIT:
				return LNKup_Organizer_Recruit_Referrals::reward_payment_for_referral( $referral, $payment_id ) > 0;
			default:
				if ( 'converted' === ( $referral['status'] ?? '' ) ) {
					return false;
				}
				$reward_id = LNKup_Referral_Credits::issue_reward( (int) $referral['referrer_user_id'], 'first_invoice_paid', (int) $referral['id'], $payment_id );
				if ( $reward_id ) {
					LNKup_Referral_Credits::mark_converted( (int) $referral['id'] );
				}
				return $reward_id > 0;
		}
	}

	// ------------------------------------------------------------------ Backfill.

	public static function schedule_backfill(): void {
		if ( get_option( self::BACKFILL_DONE ) || ! LNKup_Partner_Tables::ready() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Bounded batch: stamp attribution_status on accounts that have never been resolved.
	 * Historical reward rows are never modified.
	 */
	public static function run_backfill(): int {
		global $wpdb;
		if ( ! LNKup_Partner_Tables::ready() ) {
			return 0;
		}
		$table = LNKup_Treasury_Tables::referrals_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$users = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT referred_user_id FROM {$table} WHERE attribution_status = '' AND referred_user_id > 0 LIMIT %d", self::BATCH ) );
		foreach ( (array) $users as $uid ) {
			self::resolve_user( (int) $uid );
		}
		if ( count( (array) $users ) < self::BATCH ) {
			update_option( self::BACKFILL_DONE, current_time( 'mysql' ), false );
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
		return count( (array) $users );
	}
}
