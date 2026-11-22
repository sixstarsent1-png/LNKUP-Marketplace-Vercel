<?php
/**
 * Refund management.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Refunds {

	/** Refund types accepted from callers; `marketplace_credit` is a legacy alias of `credit`. */
	const TYPES = array( 'full', 'partial', 'credit', 'marketplace_credit' );

	/** Statuses that block a second request on the same payment. */
	const OPEN_STATUSES = array( 'pending', 'processing' );

	/**
	 * Amount already returned to the vendor as marketplace credit (card refunds live in `_lnkup_refund_amount`).
	 *
	 * @param int $payment_id Payment ID.
	 */
	public static function credited_amount( $payment_id ) {
		return (float) get_post_meta( $payment_id, '_lnkup_credit_refund_amount', true );
	}

	/**
	 * Whether a refund type returns marketplace credit instead of money to the card.
	 *
	 * @param string $type Refund type.
	 */
	public static function is_credit_type( $type ) {
		return in_array( (string) $type, array( 'credit', 'marketplace_credit' ), true );
	}

	public static function request( $payment_id, $amount, $reason, $refund_type = 'full' ) {
		$payment = get_post( $payment_id );
		if ( ! $payment || 'lnkup_payment' !== $payment->post_type ) {
			return new WP_Error( 'invalid', __( 'Invalid payment.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$refund_type = sanitize_key( (string) $refund_type );
		if ( ! in_array( $refund_type, self::TYPES, true ) ) {
			return new WP_Error( 'invalid_refund_type', __( 'Choose a full, partial, or credit refund.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return self::with_payment_lock(
			$payment_id,
			static function () use ( $payment_id, $amount, $reason, $refund_type ) {
				if ( self::open_request_for_payment( $payment_id ) ) {
					return new WP_Error( 'refund_pending', __( 'A refund for this booking is already waiting for a decision.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
				}
				return self::insert_request( $payment_id, $amount, $reason, $refund_type );
			}
		);
	}

	/**
	 * Open (pending or processing) refund row for a payment, if any.
	 *
	 * @param int $payment_id Payment ID.
	 */
	public static function open_request_for_payment( $payment_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::refunds_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE payment_post_id = %d AND status IN ('pending','processing') ORDER BY id DESC LIMIT 1", $payment_id ), ARRAY_A );
	}

	/**
	 * Serialize refund writes per payment.
	 *
	 * @param int      $payment_id Payment ID.
	 * @param callable $callback   Work.
	 * @return mixed|WP_Error
	 */
	private static function with_payment_lock( $payment_id, $callback ) {
		global $wpdb;
		$lock = 'lnkup_refund_pay_' . absint( $payment_id );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'busy', __( 'This refund is already being processed. Refresh in a moment.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		try {
			return $callback();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Insert a pending refund row after rule calculation (caller holds the payment lock).
	 */
	private static function insert_request( $payment_id, $amount, $reason, $refund_type ) {
		if ( class_exists( 'LNKup_Refund_Rules' ) ) {
			$calc = LNKup_Refund_Rules::calculate( $payment_id, $refund_type, $amount );
			if ( is_wp_error( $calc ) ) {
				return $calc;
			}
			$amount      = (float) $calc['amount'];
			$refund_type = $calc['refund_type'];
		}
		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_amount', __( 'There is nothing left to refund on this booking.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$txn = LNKup_Transactions::get_by_payment_post( $payment_id );
		global $wpdb;
		$table = LNKup_Financial_Tables::refunds_table();
		$now   = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'transaction_id'    => $txn ? (int) $txn['id'] : 0,
				'payment_post_id'   => $payment_id,
				'vendor_user_id'    => (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ),
				'organizer_user_id' => (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true ),
				'refund_type'       => sanitize_key( $refund_type ),
				'amount'            => (float) $amount,
				'reason'            => sanitize_textarea_field( $reason ),
				'status'            => 'pending',
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$refund_id = (int) $wpdb->insert_id;
		LNKup_Financial_Audit::log( 'refund_requested', 'refund', $refund_id, array( 'amount' => $amount ) );
		LNKup_Fraud_Flags::check_refund_patterns( (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ) );
		return $refund_id;
	}

	public static function approve( $refund_id, $approver_id = 0 ) {
		global $wpdb;
		$table  = LNKup_Financial_Tables::refunds_table();
		$refund = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $refund_id ), ARRAY_A );
		if ( ! $refund ) {
			return new WP_Error( 'not_found', __( 'Refund request not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return self::with_payment_lock(
			(int) $refund['payment_post_id'],
			static function () use ( $refund_id, $approver_id ) {
				return self::approve_locked( (int) $refund_id, (int) $approver_id );
			}
		);
	}

	/**
	 * Issue an approved refund exactly once. The row is claimed (pending → processing) before
	 * any money moves, so a double click or a second approver gets 409 instead of a second refund.
	 */
	private static function approve_locked( $refund_id, $approver_id ) {
		global $wpdb;
		$table   = LNKup_Financial_Tables::refunds_table();
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'processing', updated_at = %s WHERE id = %d AND status = 'pending'", current_time( 'mysql' ), $refund_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $claimed ) {
			return new WP_Error( 'refund_not_pending', __( 'This refund was already handled.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$refund     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $refund_id ), ARRAY_A );
		$payment_id = (int) $refund['payment_post_id'];
		$is_credit  = self::is_credit_type( $refund['refund_type'] );
		$total      = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
		$card_done  = (float) get_post_meta( $payment_id, '_lnkup_refund_amount', true );
		$remaining  = max( 0, round( $total - $card_done - self::credited_amount( $payment_id ), 2 ) );
		$amount     = min( (float) $refund['amount'], $remaining );
		$pay_status = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );

		if ( $amount <= 0 || ! in_array( $pay_status, array( 'paid', 'partially_refunded' ), true ) ) {
			self::set_status( $refund_id, 'failed' );
			LNKup_Financial_Audit::log( 'refund_failed', 'refund', $refund_id, array( 'reason' => 'nothing_refundable' ) );
			return new WP_Error( 'fully_refunded', __( 'There is nothing left to refund on this booking.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}

		$threshold = class_exists( 'LNKup_Approval_Workflows' ) ? (float) LNKup_Approval_Workflows::get_threshold( 'refund' ) : 500.0;
		$approver  = $approver_id ?: get_current_user_id();
		if ( $amount > $threshold && ! LNKup_Capabilities::user_has_manage_all( $approver ) ) {
			self::set_status( $refund_id, 'pending' );
			LNKup_Financial_Audit::log( 'refund_needs_admin_approval', 'refund', $refund_id, array( 'amount' => $amount, 'threshold' => $threshold ) );
			return new WP_Error( 'refund_needs_approval', __( 'Refunds this large need LNKup approval. Support has been notified.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		$provider_ref = '';
		if ( $is_credit ) {
			$granted = LNKup_Credits::grant( (int) $refund['vendor_user_id'], $amount, 'refund', 'refund', $refund_id );
			if ( is_wp_error( $granted ) || ! $granted ) {
				self::set_status( $refund_id, 'pending' );
				return new WP_Error( 'credit_failed', __( 'The credit could not be added. Try again shortly.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
			}
			update_post_meta( $payment_id, '_lnkup_credit_refund_amount', round( self::credited_amount( $payment_id ) + $amount, 2 ) );
			$provider_ref = 'credit_' . $refund_id;
		} else {
			// The card only ever paid total − wallet credit; the credit-covered part goes back to
			// the wallet instead of asking Stripe for more than it collected (B-9).
			$card_open   = max( 0, round( self::card_collected( $payment_id ) - $card_done, 2 ) );
			$card_amount = round( min( $amount, $card_open ), 2 );
			$wallet_part = round( $amount - $card_amount, 2 );
			if ( $card_amount > 0 ) {
				$result = LNKup_Financial_Service::refund( $payment_id, $card_amount );
				if ( is_wp_error( $result ) ) {
					self::set_status( $refund_id, 'pending' );
					LNKup_Financial_Audit::log( 'refund_provider_error', 'refund', $refund_id, array( 'code' => $result->get_error_code() ) );
					return new WP_Error( 'refund_failed', __( 'The refund could not be sent. Nothing was charged back; try again shortly.', 'lnkup-marketplace' ), array( 'status' => 502 ) );
				}
				$provider_ref = is_array( $result ) ? (string) ( $result['provider_ref'] ?? '' ) : '';
				update_post_meta( $payment_id, '_lnkup_refund_amount', round( $card_done + $card_amount, 2 ) );
			}
			if ( $wallet_part > 0 ) {
				$restored = LNKup_Credits::restore_for_payment( $payment_id, 'refund_credit_portion', $wallet_part );
				if ( $restored > 0 ) {
					update_post_meta( $payment_id, '_lnkup_credit_refund_amount', round( self::credited_amount( $payment_id ) + $restored, 2 ) );
					$provider_ref = trim( $provider_ref . ',credit_' . $refund_id, ',' );
				}
				$amount = round( $card_amount + $restored, 2 );
			}
			if ( $amount <= 0 ) {
				self::set_status( $refund_id, 'failed' );
				LNKup_Financial_Audit::log( 'refund_failed', 'refund', $refund_id, array( 'reason' => 'nothing_collected' ) );
				return new WP_Error( 'fully_refunded', __( 'There is nothing left to refund on this booking.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
			}
		}

		$wpdb->update(
			$table,
			array(
				'status'       => 'approved',
				'amount'       => $amount,
				'approved_by'  => $approver_id ?: get_current_user_id(),
				'provider_ref' => $provider_ref,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $refund_id ),
			array( '%s', '%f', '%d', '%s', '%s' ),
			array( '%d' )
		);
		self::sync_payment_refund_status( $payment_id );
		LNKup_Transactions::create( array(
			'type'              => 'refund',
			'status'            => 'completed',
			'amount'            => $amount,
			'vendor_user_id'    => (int) $refund['vendor_user_id'],
			'organizer_user_id' => (int) $refund['organizer_user_id'],
			'payment_post_id'   => $payment_id,
			'provider_ref'      => $provider_ref,
		) );
		LNKup_Financial_Audit::log( 'refund_issued', 'refund', $refund_id, array( 'amount' => $amount, 'type' => $refund['refund_type'] ) );
		do_action( 'lnkup_refund_completed', $refund_id );
		return true;
	}

	/**
	 * Dollars actually collected by card for a payment (excludes wallet credit).
	 *
	 * @param int $payment_id Payment ID.
	 */
	public static function card_collected( $payment_id ) {
		$total  = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
		$credit = (float) get_post_meta( $payment_id, '_lnkup_credit_applied', true );
		$cap    = max( 0, round( $total - $credit, 2 ) );
		$partials = get_post_meta( $payment_id, '_lnkup_partial_charges', true );
		$charged  = (int) get_post_meta( $payment_id, '_lnkup_stripe_charge_amount_cents', true );
		if ( $charged > 0 && ( ! is_array( $partials ) || ! $partials ) ) {
			$cap = min( $cap, round( $charged / 100, 2 ) );
		}
		return $cap;
	}

	/**
	 * Set the payment to refunded / partially_refunded from card refunds plus credit refunds.
	 *
	 * @param int $payment_id Payment ID.
	 */
	public static function sync_payment_refund_status( $payment_id ) {
		$total    = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
		$returned = (float) get_post_meta( $payment_id, '_lnkup_refund_amount', true ) + self::credited_amount( $payment_id );
		if ( $returned <= 0 ) {
			return;
		}
		update_post_meta( $payment_id, '_lnkup_payment_status', $returned + 0.004 >= $total ? 'refunded' : 'partially_refunded' );
		// The organizer gives back their share of whatever the vendor got back (card or credit).
		if ( class_exists( 'LNKup_Organizer_Wallet' ) ) {
			LNKup_Organizer_Wallet::reverse_for_refund( $payment_id );
		}
		if ( $returned + 0.004 >= $total && class_exists( 'LNKup_Escrow' ) ) {
			LNKup_Escrow::cancel_for_payment( $payment_id );
		}
		$app_id = (int) get_post_meta( $payment_id, '_lnkup_application_id', true );
		if ( $app_id ) {
			LNKup_Invoices::mark_refunded_for_application( $app_id, $returned + 0.004 >= $total );
			if ( $returned + 0.004 >= $total ) {
				self::close_booking_after_money_returned( $app_id, 'refunded' );
			}
		}
		self::announce_refund_progress( (int) $payment_id, (int) round( $returned * 100 ), $returned + 0.004 >= $total );
	}

	/**
	 * A fully refunded or lost-chargeback booking is no longer booked: lock the pass, cancel the
	 * application (which releases the inventory hold) and keep every payment record intact (B-8).
	 *
	 * @param int    $app_id Application ID.
	 * @param string $reason refunded|chargeback_lost.
	 */
	public static function close_booking_after_money_returned( $app_id, $reason ) {
		LNKup_Packets::lock_for_application( $app_id );
		$status = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		if ( in_array( $status, array( 'paid', 'payment_required', 'approved' ), true ) ) {
			update_post_meta( $app_id, '_lnkup_cancellation_reason', sanitize_key( $reason ) );
			LNKup_Applications::transition( $app_id, 'cancelled', array( 'reason' => $reason, 'source' => 'financial' ) );
		}
	}

	/**
	 * Fire `lnkup_payment_refund_synced` once per increase in the cumulative returned amount.
	 *
	 * Every refund path (admin approval, Stripe Dashboard webhook, dispute credit) lands in
	 * sync_payment_refund_status(), so reward reversals and net-fee reporting listen here
	 * instead of on `lnkup_refund_completed`, which only the admin path fires.
	 *
	 * @param int  $payment_id     Payment ID.
	 * @param int  $returned_cents Cumulative cents returned (card + credit).
	 * @param bool $is_full        Whether the booking is now fully refunded.
	 */
	private static function announce_refund_progress( $payment_id, $returned_cents, $is_full ) {
		global $wpdb;
		$lock = 'lnkup_refund_sync_' . absint( $payment_id );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		try {
			$announced = (int) get_post_meta( $payment_id, '_lnkup_refund_sync_announced_cents', true );
			if ( $returned_cents <= $announced ) {
				return;
			}
			update_post_meta( $payment_id, '_lnkup_refund_sync_announced_cents', $returned_cents );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		try {
			do_action( 'lnkup_payment_refund_synced', $payment_id, $returned_cents, (bool) $is_full );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup refund sync listener failed for payment ' . $payment_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Return refunds stranded in "processing" (request died mid-flight) to "pending" so they can be
	 * approved again. Only inside Stripe's 24h idempotency window, where a retry reuses the same
	 * Stripe refund; older rows are flagged for an admin instead of risking a second refund.
	 *
	 * @return array{requeued:int, flagged:int}
	 */
	public static function requeue_stuck_processing() {
		global $wpdb;
		$table = LNKup_Financial_Tables::refunds_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array( 'requeued' => 0, 'flagged' => 0 );
		}
		$now    = current_time( 'timestamp' );
		$stale  = gmdate( 'Y-m-d H:i:s', $now - 15 * MINUTE_IN_SECONDS );
		$expiry = gmdate( 'Y-m-d H:i:s', $now - 20 * HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, updated_at FROM {$table} WHERE status = 'processing' AND updated_at < %s LIMIT 50", $stale ), ARRAY_A );
		$out  = array( 'requeued' => 0, 'flagged' => 0 );
		foreach ( (array) $rows as $row ) {
			$id = (int) $row['id'];
			if ( $row['updated_at'] < $expiry ) {
				if ( ! get_transient( 'lnkup_refund_stuck_' . $id ) ) {
					set_transient( 'lnkup_refund_stuck_' . $id, 1, DAY_IN_SECONDS );
					LNKup_Financial_Audit::log( 'refund_stuck_needs_review', 'refund', $id, array( 'since' => $row['updated_at'] ) );
				}
				++$out['flagged'];
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$moved = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending' WHERE id = %d AND status = 'processing' AND updated_at = %s", $id, $row['updated_at'] ) );
			if ( 1 === (int) $moved ) {
				LNKup_Financial_Audit::log( 'refund_requeued', 'refund', $id, array( 'since' => $row['updated_at'] ) );
				++$out['requeued'];
			}
		}
		return $out;
	}

	public static function deny( $refund_id ) {
		global $wpdb;
		$table   = LNKup_Financial_Tables::refunds_table();
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'denied', updated_at = %s WHERE id = %d AND status = 'pending'", current_time( 'mysql' ), $refund_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $updated ) {
			return new WP_Error( 'refund_not_pending', __( 'This refund was already handled.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		LNKup_Financial_Audit::log( 'refund_denied', 'refund', $refund_id );
		return true;
	}

	/**
	 * @param int    $refund_id Refund ID.
	 * @param string $status    New status.
	 */
	private static function set_status( $refund_id, $status ) {
		global $wpdb;
		$wpdb->update( LNKup_Financial_Tables::refunds_table(), array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $refund_id ), array( '%s', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function list_for_user( $user_id, $role = 'vendor', $limit = 25 ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::refunds_table();
		$col   = 'vendor' === $role ? 'vendor_user_id' : 'organizer_user_id';
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$col} = %d ORDER BY created_at DESC LIMIT %d", $user_id, min( 50, absint( $limit ) ) ),
			ARRAY_A
		);
	}

	public static function count_for_user( $user_id, $role = 'vendor' ) {
		global $wpdb;
		$col = 'vendor' === $role ? 'vendor_user_id' : 'organizer_user_id';
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::refunds_table() . " WHERE {$col} = %d", $user_id ) );
	}

	public static function pending_count_for_organizer( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::refunds_table() . " WHERE organizer_user_id = %d AND status = 'pending'", $user_id ) );
	}

	public static function pending_count_all() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . LNKup_Financial_Tables::refunds_table() . " WHERE status = 'pending'" );
	}

	public static function format( $row ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'              => (int) $row['id'],
			'payment_post_id' => (int) $row['payment_post_id'],
			'refund_type'     => $row['refund_type'],
			'amount'          => (float) $row['amount'],
			'reason'          => $row['reason'],
			'status'          => $row['status'],
			'created_at'      => $row['created_at'],
		);
	}
}
