<?php
/**
 * Payment dispute center (financial, not reputation).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vendor/organizer mediation disputes and Stripe chargeback rows.
 */
class LNKup_Financial_Disputes {

	const OPEN_STATUSES = array( 'open', 'pending', 'under_review' );

	const OUTCOME_DENIED          = 'denied';
	const OUTCOME_APPROVED_REFUND = 'approved_refund';
	const OUTCOME_APPROVED_CREDIT = 'approved_credit';

	const STATUS_RESOLVED_DENIED   = 'resolved_denied';
	const STATUS_RESOLVED_REFUNDED = 'resolved_refunded';
	const STATUS_RESOLVED_CREDIT   = 'resolved_credit';

	/**
	 * Open a mediation dispute on a paid payment.
	 *
	 * @param int    $payment_id Payment CPT ID.
	 * @param string $reason     Vendor reason.
	 * @param int    $opened_by  User ID (0 = system / chargeback).
	 * @param array  $opts       Optional. force=true skips paid-status check (chargebacks).
	 * @return int|WP_Error Dispute ID or error.
	 */
	public static function open( $payment_id, $reason, $opened_by = 0, $opts = array() ) {
		$payment_id = absint( $payment_id );
		$reason     = sanitize_textarea_field( (string) $reason );
		$force      = ! empty( $opts['force'] );

		$payment = get_post( $payment_id );
		if ( ! $payment || 'lnkup_payment' !== $payment->post_type ) {
			return new WP_Error( 'invalid_payment', __( 'Invalid payment.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		if ( strlen( $reason ) < 10 ) {
			return new WP_Error( 'invalid_reason', __( 'Please explain why you are disputing this charge (at least 10 characters).', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$status = sanitize_key( (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) );
		if ( ! $force ) {
			if ( 'paid' !== $status ) {
				return new WP_Error( 'not_eligible', __( 'Only paid charges can be disputed.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			if ( 'refunded' === $status ) {
				return new WP_Error( 'already_refunded', __( 'This payment was already refunded.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
		}

		// A bank chargeback is recorded even while a vendor mediation dispute is open.
		if ( ! ( $force && absint( $opened_by ) <= 0 ) && self::has_open_for_payment( $payment_id ) ) {
			return new WP_Error( 'dispute_exists', __( 'An open dispute already exists for this payment.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( 'disputed' !== $status ) {
			update_post_meta( $payment_id, '_lnkup_status_before_dispute', $status ?: 'paid' );
		}

		$txn   = class_exists( 'LNKup_Transactions' ) ? LNKup_Transactions::get_by_payment_post( $payment_id ) : null;
		$now   = current_time( 'mysql' );
		$table = LNKup_Financial_Tables::disputes_table();
		global $wpdb;

		$opened_by = absint( $opened_by );
		if ( $opened_by <= 0 && ! $force ) {
			$opened_by = get_current_user_id();
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'transaction_id'    => $txn ? (int) $txn['id'] : 0,
				'payment_post_id'   => $payment_id,
				'dispute_type'      => ( $force && $opened_by <= 0 ) ? 'chargeback' : 'payment',
				'opened_by'         => $opened_by,
				'vendor_user_id'    => (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ),
				'organizer_user_id' => (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true ),
				'reason'            => $reason,
				'status'            => 'open',
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', __( 'Could not open dispute.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}

		$dispute_id = (int) $wpdb->insert_id;
		update_post_meta( $payment_id, '_lnkup_payment_status', 'disputed' );
		LNKup_Financial_Audit::log( 'dispute_opened', 'dispute', $dispute_id );
		if ( class_exists( 'LNKup_Fraud_Flags' ) ) {
			LNKup_Fraud_Flags::check_dispute_patterns( (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ) );
		}
		do_action( 'lnkup_dispute_updated', $dispute_id );
		return $dispute_id;
	}

	/**
	 * Open dispute from Stripe chargeback webhook.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $reason     Dispute reason.
	 * @return int Dispute ID or 0.
	 */
	public static function open_from_chargeback( $payment_id, $reason = '' ) {
		$payment_id = absint( $payment_id );
		if ( $payment_id <= 0 || self::has_open_chargeback( $payment_id ) ) {
			return 0;
		}
		$result = self::open(
			$payment_id,
			$reason ?: __( 'Card network chargeback opened.', 'lnkup-marketplace' ),
			0,
			array( 'force' => true )
		);
		if ( is_wp_error( $result ) ) {
			return 0;
		}
		if ( class_exists( 'LNKup_Trust_Cases' ) ) {
			LNKup_Trust_Cases::open(
				array(
					'case_type'      => 'chargeback',
					'severity'       => 'elevated',
					'subject_type'   => 'payment',
					'subject_id'     => $payment_id,
					'payment_id'     => $payment_id,
					'event_id'       => (int) get_post_meta( $payment_id, '_lnkup_event_id', true ),
					'application_id' => (int) get_post_meta( $payment_id, '_lnkup_application_id', true ),
					'signal_key'     => 'chargeback_' . $payment_id,
					'summary'        => sanitize_text_field( $reason ?: 'Card network chargeback opened.' ),
					'evidence'       => array(
						array(
							'source'      => 'stripe_webhook',
							'dispute_row' => (int) $result,
							'at'          => current_time( 'mysql', true ),
						),
					),
					'next_step'      => __( 'Check the evidence deadline in the Stripe Dashboard and gather booking, acceptance and check-in records.', 'lnkup-marketplace' ),
				)
			);
		}
		return (int) $result;
	}

	/**
	 * Whether a dispute row was opened by the card network (Stripe chargeback), not a vendor.
	 *
	 * @param array $dispute Dispute row.
	 */
	public static function is_chargeback( $dispute ) {
		return 0 === (int) ( $dispute['opened_by'] ?? 0 );
	}

	/**
	 * @param int $payment_id Payment ID.
	 */
	public static function has_open_chargeback( $payment_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return false;
		}
		$in = "'" . implode( "','", array_map( 'esc_sql', self::OPEN_STATUSES ) ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE payment_post_id = %d AND opened_by = 0 AND status IN ({$in})", absint( $payment_id ) ) ) > 0;
	}

	/**
	 * Payment status to return to when a dispute ends without a refund.
	 *
	 * @param int $payment_id Payment ID.
	 */
	private static function status_before_dispute( $payment_id ) {
		$before = sanitize_key( (string) get_post_meta( $payment_id, '_lnkup_status_before_dispute', true ) );
		return in_array( $before, array( 'paid', 'partially_refunded', 'refunded' ), true ) ? $before : 'paid';
	}

	/**
	 * Close open chargeback rows for a payment after Stripe decides the dispute.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $outcome    won|lost.
	 */
	public static function close_chargeback( $payment_id, $outcome ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return;
		}
		$in     = "'" . implode( "','", array_map( 'esc_sql', self::OPEN_STATUSES ) ) . "'";
		$status = 'won' === $outcome ? self::STATUS_RESOLVED_DENIED : self::STATUS_RESOLVED_REFUNDED;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, resolution_note = %s, updated_at = %s WHERE payment_post_id = %d AND opened_by = 0 AND status IN ({$in})",
				$status,
				'won' === $outcome ? 'Chargeback won at the card network.' : 'Chargeback lost at the card network.',
				current_time( 'mysql' ),
				absint( $payment_id )
			)
		);
		if ( (int) $wpdb->rows_affected > 0 ) {
			do_action( 'lnkup_chargeback_closed', absint( $payment_id ), 'won' === $outcome ? 'won' : 'lost' );
		}
	}

	/**
	 * @param int $payment_id Payment ID.
	 */
	public static function has_open_for_payment( $payment_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return false;
		}
		$in = "'" . implode( "','", array_map( 'esc_sql', self::OPEN_STATUSES ) ) . "'";
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE payment_post_id = %d AND status IN ({$in})",
				absint( $payment_id )
			)
		) > 0;
	}

	/**
	 * @param int $dispute_id Dispute row ID.
	 * @return array|null
	 */
	public static function get( $dispute_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $dispute_id ) ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param int    $dispute_id Dispute ID.
	 * @param string $evidence   Evidence text.
	 * @param int    $user_id    Author.
	 * @return true|WP_Error
	 */
	public static function respond( $dispute_id, $evidence, $user_id = 0 ) {
		global $wpdb;
		$table   = LNKup_Financial_Tables::disputes_table();
		$dispute = self::get( $dispute_id );
		if ( ! $dispute ) {
			return new WP_Error( 'invalid', __( 'Dispute not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $dispute['status'], self::OPEN_STATUSES, true ) ) {
			return new WP_Error( 'closed', __( 'This dispute is already resolved.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$evidence = sanitize_textarea_field( (string) $evidence );
		if ( strlen( $evidence ) < 3 ) {
			return new WP_Error( 'invalid_evidence', __( 'Please add a short note.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$existing = ! empty( $dispute['evidence'] ) ? json_decode( $dispute['evidence'], true ) : array();
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$existing[] = array(
			'user_id' => $user_id ?: get_current_user_id(),
			'message' => $evidence,
			'at'      => current_time( 'mysql' ),
		);
		$wpdb->update(
			$table,
			array(
				'evidence'   => wp_json_encode( $existing ),
				'status'     => 'pending',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $dispute_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		LNKup_Financial_Audit::log( 'dispute_responded', 'dispute', absint( $dispute_id ) );
		do_action( 'lnkup_dispute_updated', absint( $dispute_id ) );
		return true;
	}

	/**
	 * Resolve a dispute with an outcome that may move money.
	 *
	 * @param int    $dispute_id  Dispute ID.
	 * @param string $outcome     denied|approved_refund|approved_credit.
	 * @param string $note        Resolution note (required for denied).
	 * @param int    $resolver_id Resolver user ID.
	 * @return true|WP_Error
	 */
	public static function resolve( $dispute_id, $outcome, $note = '', $resolver_id = 0 ) {
		$dispute_id = absint( $dispute_id );
		$outcome    = sanitize_key( (string) $outcome );
		$note       = sanitize_textarea_field( (string) $note );
		$resolver   = $resolver_id ?: get_current_user_id();

		$dispute = self::get( $dispute_id );
		if ( ! $dispute ) {
			return new WP_Error( 'invalid', __( 'Dispute not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $dispute['status'], self::OPEN_STATUSES, true ) ) {
			return new WP_Error( 'closed', __( 'This dispute is already resolved.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		$allowed = array( self::OUTCOME_DENIED, self::OUTCOME_APPROVED_REFUND, self::OUTCOME_APPROVED_CREDIT );
		if ( ! in_array( $outcome, $allowed, true ) ) {
			return new WP_Error( 'invalid_outcome', __( 'Invalid resolution outcome.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		if ( self::OUTCOME_DENIED === $outcome && strlen( $note ) < 3 ) {
			return new WP_Error( 'note_required', __( 'Please add a short note when denying a dispute.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		// Bank chargebacks (opened by the card network) are decided by Stripe and LNKup admins only,
		// and never settled with platform credit: the vendor already got the money back.
		if ( self::is_chargeback( $dispute ) ) {
			if ( ! LNKup_Capabilities::user_has_manage_all( $resolver ) ) {
				return new WP_Error( 'chargeback_admin_only', __( 'Card chargebacks are handled by LNKup support.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
			if ( self::OUTCOME_APPROVED_CREDIT === $outcome ) {
				return new WP_Error( 'chargeback_no_credit', __( 'A chargeback cannot be resolved with credit.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
		}

		$payment_id = (int) $dispute['payment_post_id'];
		$amount     = self::payment_amount( $payment_id );
		$status     = '';

		// Claim the dispute first so a double click or second admin cannot resolve (and pay) twice.
		global $wpdb;
		$dispute_table = LNKup_Financial_Tables::disputes_table();
		$open_in       = "'" . implode( "','", array_map( 'esc_sql', self::OPEN_STATUSES ) ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$dispute_table} SET status = 'resolving', updated_at = %s WHERE id = %d AND status IN ({$open_in})", current_time( 'mysql' ), $dispute_id ) );
		if ( 1 !== (int) $claimed ) {
			return new WP_Error( 'closed', __( 'This dispute is already being resolved.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$reopen = static function () use ( $wpdb, $dispute_table, $dispute_id, $dispute ) {
			$wpdb->update( $dispute_table, array( 'status' => $dispute['status'] ), array( 'id' => $dispute_id ) );
		};
		$before = self::status_before_dispute( $payment_id );

		if ( self::OUTCOME_DENIED === $outcome ) {
			$status = self::STATUS_RESOLVED_DENIED;
			update_post_meta( $payment_id, '_lnkup_payment_status', $before );
		} elseif ( self::OUTCOME_APPROVED_REFUND === $outcome ) {
			$status = self::STATUS_RESOLVED_REFUNDED;
			// Refund rules need the pre-dispute status (paid / partially_refunded), not "disputed".
			update_post_meta( $payment_id, '_lnkup_payment_status', $before );
			$refund_id = LNKup_Refunds::request(
				$payment_id,
				$amount,
				$note ?: ( $dispute['reason'] ?: __( 'Dispute approved — refund', 'lnkup-marketplace' ) ),
				'full'
			);
			if ( is_wp_error( $refund_id ) ) {
				update_post_meta( $payment_id, '_lnkup_payment_status', 'disputed' );
				$reopen();
				return $refund_id;
			}
			$approved = LNKup_Refunds::approve( (int) $refund_id, $resolver );
			if ( is_wp_error( $approved ) ) {
				update_post_meta( $payment_id, '_lnkup_payment_status', 'disputed' );
				$reopen();
				return $approved;
			}
		} else {
			$status    = self::STATUS_RESOLVED_CREDIT;
			$vendor_id = (int) $dispute['vendor_user_id'];
			// Credit only what has not already been returned to the vendor.
			$returned  = (float) get_post_meta( $payment_id, '_lnkup_refund_amount', true ) + LNKup_Refunds::credited_amount( $payment_id );
			$amount    = max( 0, round( $amount - $returned, 2 ) );
			if ( $vendor_id <= 0 || $amount <= 0 ) {
				$reopen();
				return new WP_Error( 'invalid_credit', __( 'Could not grant credit for this payment.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			LNKup_Credits::grant( $vendor_id, $amount, 'dispute', 'dispute', $dispute_id );
			// Recorded as a credit refund so the organizer's share is reversed like any refund.
			update_post_meta( $payment_id, '_lnkup_credit_refund_amount', round( LNKup_Refunds::credited_amount( $payment_id ) + $amount, 2 ) );
			update_post_meta( $payment_id, '_lnkup_payment_status', $before );
			LNKup_Refunds::sync_payment_refund_status( $payment_id );
			if ( class_exists( 'LNKup_Transactions' ) ) {
				LNKup_Transactions::create(
					array(
						'type'              => 'credit',
						'status'            => 'completed',
						'amount'            => $amount,
						'vendor_user_id'    => $vendor_id,
						'organizer_user_id' => (int) $dispute['organizer_user_id'],
						'payment_post_id'   => $payment_id,
						'provider_ref'      => 'dispute_' . $dispute_id,
					)
				);
			}
		}

		$table = LNKup_Financial_Tables::disputes_table();
		$wpdb->update(
			$table,
			array(
				'status'          => $status,
				'resolution_note' => $note,
				'resolved_by'     => absint( $resolver ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $dispute_id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		LNKup_Financial_Audit::log(
			'dispute_resolved',
			'dispute',
			$dispute_id,
			array(
				'metadata' => array(
					'outcome' => $outcome,
					'status'  => $status,
				),
			)
		);
		do_action( 'lnkup_dispute_updated', $dispute_id );
		return true;
	}

	/**
	 * @param int    $user_id User ID.
	 * @param string $role    vendor|organizer.
	 * @param int    $limit   Max rows.
	 * @return array
	 */
	public static function list_for_user( $user_id, $role = 'vendor', $limit = 25 ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		$col = 'vendor' === $role ? 'vendor_user_id' : 'organizer_user_id';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$col} = %d ORDER BY created_at DESC LIMIT %d",
				absint( $user_id ),
				min( 50, absint( $limit ) )
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * @param int $user_id Organizer user ID.
	 */
	public static function open_count_for_organizer( $user_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}
		$in = "'" . implode( "','", array_map( 'esc_sql', self::OPEN_STATUSES ) ) . "'";
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE organizer_user_id = %d AND status IN ({$in})",
				absint( $user_id )
			)
		);
	}

	/**
	 * @return int
	 */
	public static function open_count_all() {
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}
		$in = "'" . implode( "','", array_map( 'esc_sql', self::OPEN_STATUSES ) ) . "'";
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ({$in})" );
	}

	/**
	 * @param int $payment_id Payment ID.
	 * @return float
	 */
	public static function payment_amount( $payment_id ) {
		if ( class_exists( 'LNKup_Financial' ) && method_exists( 'LNKup_Financial', 'payment_booth_amount' ) ) {
			$amt = (float) LNKup_Financial::payment_booth_amount( $payment_id );
			if ( $amt > 0 ) {
				return round( $amt, 2 );
			}
		}
		foreach ( array( '_lnkup_total_amount', '_lnkup_amount', '_lnkup_vendor_spot_fee', '_lnkup_total' ) as $key ) {
			$amount = (float) get_post_meta( $payment_id, $key, true );
			if ( $amount > 0 ) {
				return round( $amount, 2 );
			}
		}
		return 0.0;
	}

	/**
	 * @param array|null $row Dispute row.
	 * @return array|null
	 */
	public static function format( $row ) {
		if ( ! $row || ! is_array( $row ) ) {
			return null;
		}
		$payment_id = (int) $row['payment_post_id'];
		$evidence   = ! empty( $row['evidence'] ) ? json_decode( $row['evidence'], true ) : array();
		if ( ! is_array( $evidence ) ) {
			$evidence = array();
		}
		$event_id    = (int) get_post_meta( $payment_id, '_lnkup_event_id', true );
		$event_title = $event_id ? get_the_title( $event_id ) : '';
		$opened_by   = (int) ( $row['opened_by'] ?? 0 );
		$is_open     = in_array( $row['status'], self::OPEN_STATUSES, true );

		return array(
			'id'                => (int) $row['id'],
			'payment_post_id'   => $payment_id,
			'payment_id'        => $payment_id,
			'transaction_id'    => (int) ( $row['transaction_id'] ?? 0 ),
			'vendor_user_id'    => (int) ( $row['vendor_user_id'] ?? 0 ),
			'organizer_user_id' => (int) ( $row['organizer_user_id'] ?? 0 ),
			'reason'            => (string) ( $row['reason'] ?? '' ),
			'status'            => (string) ( $row['status'] ?? '' ),
			'is_open'           => $is_open,
			'amount'            => self::payment_amount( $payment_id ),
			'currency'          => 'USD',
			'event_title'       => $event_title ?: '',
			'payment_status'    => sanitize_key( (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) ),
			'evidence'          => $evidence,
			'evidence_count'    => count( $evidence ),
			'resolution_note'   => (string) ( $row['resolution_note'] ?? '' ),
			'resolved_by'       => (int) ( $row['resolved_by'] ?? 0 ),
			'opened_by'         => $opened_by,
			'source'            => ( 0 === $opened_by ) ? 'stripe_chargeback' : 'vendor',
			'created_at'        => (string) ( $row['created_at'] ?? '' ),
			'updated_at'        => (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
