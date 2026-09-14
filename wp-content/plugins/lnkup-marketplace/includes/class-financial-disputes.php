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

		if ( self::has_open_for_payment( $payment_id ) ) {
			return new WP_Error( 'dispute_exists', __( 'An open dispute already exists for this payment.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
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
				'dispute_type'      => 'payment',
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
		if ( $payment_id <= 0 || self::has_open_for_payment( $payment_id ) ) {
			return 0;
		}
		$result = self::open(
			$payment_id,
			$reason ?: __( 'Card network chargeback opened.', 'lnkup-marketplace' ),
			0,
			array( 'force' => true )
		);
		return is_wp_error( $result ) ? 0 : (int) $result;
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

		$payment_id = (int) $dispute['payment_post_id'];
		$amount     = self::payment_amount( $payment_id );
		$status     = '';

		if ( self::OUTCOME_DENIED === $outcome ) {
			$status = self::STATUS_RESOLVED_DENIED;
			update_post_meta( $payment_id, '_lnkup_payment_status', 'paid' );
		} elseif ( self::OUTCOME_APPROVED_REFUND === $outcome ) {
			$status = self::STATUS_RESOLVED_REFUNDED;
			// Refund rules require payment status "paid"; dispute flow sets "disputed".
			update_post_meta( $payment_id, '_lnkup_payment_status', 'paid' );
			$refund_id = LNKup_Refunds::request(
				$payment_id,
				$amount,
				$note ?: ( $dispute['reason'] ?: __( 'Dispute approved — refund', 'lnkup-marketplace' ) ),
				'full'
			);
			if ( is_wp_error( $refund_id ) ) {
				update_post_meta( $payment_id, '_lnkup_payment_status', 'disputed' );
				return $refund_id;
			}
			$approved = LNKup_Refunds::approve( (int) $refund_id, $resolver );
			if ( is_wp_error( $approved ) ) {
				update_post_meta( $payment_id, '_lnkup_payment_status', 'disputed' );
				return $approved;
			}
		} else {
			$status    = self::STATUS_RESOLVED_CREDIT;
			$vendor_id = (int) $dispute['vendor_user_id'];
			if ( $vendor_id <= 0 || $amount <= 0 ) {
				return new WP_Error( 'invalid_credit', __( 'Could not grant credit for this payment.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			LNKup_Credits::grant( $vendor_id, $amount, 'dispute', 'dispute', $dispute_id );
			update_post_meta( $payment_id, '_lnkup_payment_status', 'refunded' );
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

		global $wpdb;
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
