<?php
/**
 * Read-only financial reconciliation diagnostics (admin only).
 *
 * Compares WordPress payment records, the organizer wallet ledger, webhook
 * history and Stripe (GET only) for one organizer. Nothing in this class may
 * write: it never calls get_balances(), reconcile_*(), finalize_payment() or any
 * Stripe create/update endpoint.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Reconciliation {

	const NS = 'lnkup/v1';

	/** Ledger rows that represent money arriving for a payment. */
	const SETTLEMENT_ENTRY_TYPES = array( 'credit', 'connect_settlement' );

	/** Max Stripe search pages (100 PaymentIntents each) per report. */
	const MAX_STRIPE_PAGES = 10;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/admin/financial/reconcile',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_reconcile' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
				'args'                => array(
					'organizer_id' => array( 'type' => 'integer', 'required' => false ),
					'from'         => array( 'type' => 'string', 'required' => false ),
					'to'           => array( 'type' => 'string', 'required' => false ),
					'stripe'       => array( 'type' => 'boolean', 'required' => false, 'default' => true ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/financial/ledger-audit/(?P<organizer_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_ledger_audit' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	public static function permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_reconcile( $request ) {
		$organizer_id = absint( $request->get_param( 'organizer_id' ) );
		if ( ! $organizer_id ) {
			return rest_ensure_response( self::platform_summary() );
		}
		return rest_ensure_response(
			self::organizer_report(
				$organizer_id,
				array(
					'from'   => (string) $request->get_param( 'from' ),
					'to'     => (string) $request->get_param( 'to' ),
					'stripe' => (bool) $request->get_param( 'stripe' ),
				)
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_ledger_audit( $request ) {
		$organizer_id = absint( $request['organizer_id'] );
		if ( ! $organizer_id || ! get_userdata( $organizer_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Organizer not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( self::ledger_audit( $organizer_id ) );
	}

	/**
	 * One row per organizer with wallet or paid payments: headline mismatch counts.
	 */
	public static function platform_summary() {
		global $wpdb;
		$entries = LNKup_Treasury_Tables::organizer_wallet_entries_table();
		$has_ledger = LNKup_Financial_Tables::table_exists( $entries );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$organizers = $wpdb->get_col(
			"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'lnkup_payment'
			WHERE pm.meta_key = '_lnkup_organizer_user_id' AND pm.meta_value <> '' AND pm.meta_value <> '0'"
		);

		$rows = array();
		foreach ( (array) $organizers as $organizer_id ) {
			$organizer_id = absint( $organizer_id );
			if ( ! $organizer_id ) {
				continue;
			}
			$payments = self::load_payments( $organizer_id, array() );
			$ledger   = $has_ledger ? self::load_ledger( $organizer_id ) : array();
			$by_pay   = self::group_ledger_by_payment( $ledger );
			$issues   = array();
			$paid     = 0;
			$collected = 0;
			foreach ( $payments as $payment ) {
				if ( 'paid' === $payment['status'] ) {
					++$paid;
					$collected += $payment['total_cents'];
				}
				foreach ( self::classify_payment( $payment, $by_pay[ $payment['id'] ] ?? array(), null ) as $code ) {
					$issues[ $code ] = ( $issues[ $code ] ?? 0 ) + 1;
				}
			}
			$rows[] = array(
				'organizer_id'    => $organizer_id,
				'display_name'    => self::user_label( $organizer_id ),
				'payments'        => count( $payments ),
				'paid'            => $paid,
				'collected_cents' => $collected,
				'ledger_rows'     => count( $ledger ),
				'issues'          => $issues,
				'issue_total'     => array_sum( $issues ),
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['issue_total'] <=> $a['issue_total'];
			}
		);

		return array(
			'generated_at' => gmdate( 'c' ),
			'read_only'    => true,
			'organizers'   => $rows,
			'schema'       => self::schema_health(),
			'webhooks'     => self::webhook_summary( '', '' ),
			'hourly'       => get_option( 'lnkup_fin_reconcile_health', null ),
		);
	}

	/**
	 * Full per-organizer reconciliation.
	 *
	 * @param int   $organizer_id Organizer user ID.
	 * @param array $args         from, to (Y-m-d), stripe (bool).
	 */
	public static function organizer_report( $organizer_id, $args = array() ) {
		$organizer_id = absint( $organizer_id );
		$range        = self::normalize_range( $args );
		$payments     = self::load_payments( $organizer_id, $range );
		$ledger       = self::load_ledger( $organizer_id );
		$by_pay       = self::group_ledger_by_payment( $ledger );
		$account      = class_exists( 'LNKup_Stripe_Connect' ) ? LNKup_Stripe_Connect::get_account( $organizer_id, 'organizer' ) : null;
		$account_id   = (string) ( $account['stripe_account_id'] ?? '' );

		$stripe = array( 'enabled' => false );
		$stripe_by_payment = array();
		if ( ! empty( $args['stripe'] ) ) {
			$stripe            = self::stripe_snapshot( $organizer_id, $account_id, $payments, $range );
			$stripe_by_payment = $stripe['by_payment'] ?? array();
			unset( $stripe['by_payment'] );
		}

		$webhook_index = self::webhook_index( $range );
		$settle_runs   = self::audit_counts_by_payment( wp_list_pluck( $payments, 'id' ) );
		$side_effects  = self::side_effect_counts( wp_list_pluck( $payments, 'id' ) );
		$rows          = array();
		$issue_counts  = array();
		foreach ( $payments as $payment ) {
			$pi       = $stripe_by_payment[ $payment['id'] ] ?? null;
			$entries  = $by_pay[ $payment['id'] ] ?? array();
			$issues   = self::classify_payment( $payment, $entries, $pi );
			$runs     = $settle_runs[ $payment['id'] ] ?? array();
			if ( ( $runs['treasury_settled'] ?? 0 ) > 1 ) {
				$issues[] = 'settled_repeatedly';
			}
			$effects = $side_effects[ $payment['id'] ] ?? array();
			if ( ( $effects['platform_fees'] ?? 0 ) > 1 ) {
				$issues[] = 'duplicate_platform_fee_rows';
			}
			if ( ( $effects['reserve_holds'] ?? 0 ) > 1 || ( '' !== $payment['destination'] && ( $effects['reserve_holds'] ?? 0 ) > 0 ) ) {
				$issues[] = 'unbacked_reserve_holds';
			}
			if ( ( $effects['escrow_holds_live'] ?? $effects['escrow_holds'] ?? 0 ) > 1 ) {
				$issues[] = 'duplicate_escrow_holds';
			}
			$runs = array_merge( $runs, array( 'side_effects' => $effects ) );
			foreach ( $issues as $code ) {
				$issue_counts[ $code ] = ( $issue_counts[ $code ] ?? 0 ) + 1;
			}
			$rows[] = array_merge(
				$payment,
				array(
					'ledger'   => array_map( array( __CLASS__, 'ledger_summary_row' ), $entries ),
					'stripe'   => $pi,
					'webhooks' => self::webhooks_for_payment( $webhook_index, $payment ),
					'audit'    => $runs,
					'issues'   => $issues,
				)
			);
		}

		$wallet     = self::wallet_row( $organizer_id );
		$migrations = self::migration_audit( $organizer_id, $ledger );
		if ( $migrations['unrecorded_cents'] > 0 ) {
			$issue_counts['migration_zeroed_without_ledger'] = count( $migrations['unrecorded'] );
		}

		return array(
			'generated_at'   => gmdate( 'c' ),
			'read_only'      => true,
			'organizer'      => array(
				'id'                   => $organizer_id,
				'display_name'         => self::user_label( $organizer_id ),
				'connect_account'      => self::mask_id( $account_id ),
				'connect_local'        => $account ? array(
					'charges_enabled' => ! empty( $account['charges_enabled'] ),
					'payouts_enabled' => ! empty( $account['payouts_enabled'] ),
					'disabled_reason' => (string) ( $account['disabled_reason'] ?? '' ),
					'status'          => LNKup_Stripe_Connect::map_account_status( $account ),
				) : null,
				'settlement_mode_now'  => class_exists( 'LNKup_Stripe_Connect' ) ? LNKup_Stripe_Connect::settlement_mode_for_organizer( $organizer_id ) : 'platform_treasury',
			),
			'range'          => $range,
			'totals'         => self::totals( $payments, $ledger, $wallet, $stripe ),
			'issue_counts'   => $issue_counts,
			'payments'       => $rows,
			'orphan_ledger'  => array_values( array_map( array( __CLASS__, 'ledger_summary_row' ), $by_pay[0] ?? array() ) ),
			'same_amount_same_day' => self::same_amount_clusters( $ledger ),
			'stripe'         => $stripe,
			'wallet_row'     => $wallet,
			'migrations'     => $migrations,
			'schema'         => self::schema_health(),
			'webhooks'       => self::webhook_summary( $range['from'], $range['to'] ),
		);
	}

	/**
	 * Ledger schema facts that silently break inserts (column widths, idempotency column).
	 */
	public static function schema_health() {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_wallet_entries_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array( 'ledger_table' => false );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$col  = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'entry_type'", ARRAY_A );
		$type = strtolower( (string) ( $col['Type'] ?? '' ) );
		$len  = preg_match( '/varchar\((\d+)\)/', $type, $m ) ? (int) $m[1] : 0;
		return array(
			'ledger_table'                 => true,
			'entry_type_column'            => $type,
			'connect_settlement_fits'      => $len >= strlen( 'connect_settlement' ),
			'idempotency_column'           => class_exists( 'LNKup_Organizer_Wallet' ) && LNKup_Organizer_Wallet::has_idempotency_column(),
			'db_version'                   => (string) get_option( 'lnkup_db_version' ),
			'auto_recovery_since'          => (string) get_option( 'lnkup_fin_auto_recovery_since', '' ),
		);
	}

	/**
	 * Treasury → Connect migrations from the audit log vs. ledger rows that should mirror them.
	 *
	 * A migration zeroes wallet available_balance; before 12.22 its ledger insert could fail
	 * (entry_type too narrow), leaving money with no ledger counterpart.
	 *
	 * @param int   $organizer_id Organizer.
	 * @param array $ledger       Ledger rows.
	 */
	private static function migration_audit( $organizer_id, $ledger ) {
		global $wpdb;
		$audit = LNKup_Financial_Tables::audit_table();
		$rows  = array();
		if ( LNKup_Financial_Tables::table_exists( $audit ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, amount, created_at FROM {$audit} WHERE action = %s AND entity_type = 'wallet' AND entity_id = %d ORDER BY id ASC",
					'treasury_migrated_to_connect_settlement',
					absint( $organizer_id )
				),
				ARRAY_A
			);
		}
		$recorded = array();
		foreach ( $ledger as $entry ) {
			if ( 'treasury_migration' === $entry['source_type'] ) {
				$recorded[] = self::to_cents( $entry['amount'] );
			}
		}
		$unrecorded = array();
		foreach ( $rows as $row ) {
			$cents = self::to_cents( $row['amount'] );
			$match = array_search( $cents, $recorded, true );
			if ( false !== $match ) {
				unset( $recorded[ $match ] );
				continue;
			}
			$unrecorded[] = array(
				'audit_id'     => (int) $row['id'],
				'amount_cents' => $cents,
				'created_at'   => (string) $row['created_at'],
			);
		}
		return array(
			'audit_events'     => count( $rows ),
			'unrecorded'       => $unrecorded,
			'unrecorded_cents' => array_sum( wp_list_pluck( $unrecorded, 'amount_cents' ) ),
		);
	}

	/**
	 * Rows settlement writes outside the ledger (platform fees, reserves, escrow), per payment.
	 *
	 * @param int[] $payment_ids Payment IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private static function side_effect_counts( $payment_ids ) {
		global $wpdb;
		$payment_ids = array_filter( array_map( 'absint', (array) $payment_ids ) );
		if ( ! $payment_ids ) {
			return array();
		}
		$in     = implode( ',', $payment_ids );
		$out    = array();
		$tables = array(
			'platform_fees' => LNKup_Treasury_Tables::platform_fees_table(),
			'reserve_holds' => LNKup_Treasury_Tables::reserve_holds_table(),
			'escrow_holds'  => LNKup_Treasury_Tables::escrow_holds_table(),
		);
		foreach ( $tables as $key => $table ) {
			if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
				continue;
			}
			$amount_col = 'platform_fees' === $key ? 'lnkup_platform_fee' : 'amount';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results(
				"SELECT payment_post_id, COUNT(*) AS n, SUM({$amount_col}) AS total" . ( 'platform_fees' === $key ? '' : ", GROUP_CONCAT(DISTINCT status) AS statuses, SUM(status NOT IN ('void','cancelled')) AS live" ) . "
				FROM {$table} WHERE payment_post_id IN ({$in}) GROUP BY payment_post_id",
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$pid                         = (int) $row['payment_post_id'];
				$out[ $pid ][ $key ]         = (int) $row['n'];
				$out[ $pid ][ $key . '_cents' ] = self::to_cents( $row['total'] );
				if ( isset( $row['statuses'] ) ) {
					$out[ $pid ][ $key . '_statuses' ] = (string) $row['statuses'];
					$out[ $pid ][ $key . '_live' ]     = (int) $row['live'];
				}
			}
		}
		return $out;
	}

	/**
	 * Settlement / finalize audit counts per payment.
	 *
	 * @param int[] $payment_ids Payment IDs.
	 * @return array<int, array<string, int>>
	 */
	private static function audit_counts_by_payment( $payment_ids ) {
		global $wpdb;
		$payment_ids = array_filter( array_map( 'absint', (array) $payment_ids ) );
		$audit       = LNKup_Financial_Tables::audit_table();
		if ( ! $payment_ids || ! LNKup_Financial_Tables::table_exists( $audit ) ) {
			return array();
		}
		$in = implode( ',', $payment_ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			"SELECT entity_id, action, COUNT(*) AS n FROM {$audit}
			WHERE entity_type = 'payment' AND entity_id IN ({$in})
			AND action IN ('treasury_settled','finalize_completed','finalize_skipped','settlement_recovered','payment_failed_ignored')
			GROUP BY entity_id, action",
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['entity_id'] ][ $row['action'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Ledger-centric audit: every wallet entry, its payment, duplicates, and recomputed sums.
	 *
	 * @param int $organizer_id Organizer user ID.
	 */
	public static function ledger_audit( $organizer_id ) {
		$organizer_id = absint( $organizer_id );
		$ledger       = self::load_ledger( $organizer_id );
		$by_pay       = self::group_ledger_by_payment( $ledger );
		$rows         = array();
		foreach ( $ledger as $entry ) {
			$payment_id = (int) $entry['payment_post_id'];
			$siblings   = $payment_id ? self::settlement_rows( $by_pay[ $payment_id ] ?? array() ) : array();
			$row        = self::ledger_summary_row( $entry );
			$row['payment_status']      = $payment_id ? (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) : '';
			$row['payment_exists']      = $payment_id ? ( 'lnkup_payment' === get_post_type( $payment_id ) ) : null;
			$row['payment_total_cents'] = $payment_id ? self::to_cents( get_post_meta( $payment_id, '_lnkup_total_amount', true ) ) : 0;
			$row['payment_intent']      = $payment_id ? (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true ) : '';
			$row['settlement_siblings'] = count( $siblings );
			$row['is_duplicate']        = count( $siblings ) > 1 && in_array( $entry['entry_type'], self::SETTLEMENT_ENTRY_TYPES, true )
				&& (int) $siblings[0]['id'] !== (int) $entry['id'];
			$rows[] = $row;
		}

		$sums = array();
		foreach ( $ledger as $entry ) {
			$key          = $entry['entry_type'] . ':' . ( $entry['source_type'] ?: '-' );
			$sums[ $key ] = ( $sums[ $key ] ?? 0 ) + self::to_cents( $entry['amount'] );
		}

		return array(
			'generated_at' => gmdate( 'c' ),
			'read_only'    => true,
			'organizer_id' => $organizer_id,
			'wallet_row'   => self::wallet_row( $organizer_id ),
			'sums_cents'   => $sums,
			'entries'      => $rows,
			'duplicates'   => array_values(
				array_filter(
					$rows,
					static function ( $r ) {
						return $r['is_duplicate'];
					}
				)
			),
			'same_amount_same_day' => self::same_amount_clusters( $ledger ),
		);
	}

	/**
	 * Pure classification of one payment against its ledger rows and Stripe PaymentIntent.
	 *
	 * @param array      $payment Normalized payment (see load_payments()).
	 * @param array      $entries Ledger rows for this payment.
	 * @param array|null $pi      Normalized Stripe PaymentIntent or null when not checked.
	 * @return string[] Issue codes.
	 */
	public static function classify_payment( array $payment, array $entries, $pi ) {
		$issues      = array();
		$is_paid     = 'paid' === $payment['status'];
		$settlements = self::settlement_rows( $entries );

		if ( $is_paid && ! $settlements && $payment['total_cents'] > 0 ) {
			$issues[] = 'paid_missing_ledger';
		}
		if ( count( $settlements ) > 1 ) {
			$issues[] = 'duplicate_ledger';
		}
		if ( ! $is_paid && $settlements && 'refunded' !== $payment['status'] ) {
			$issues[] = 'ledger_on_unpaid_payment';
		}
		if ( $is_paid && '' === $payment['paid_at'] ) {
			$issues[] = 'paid_missing_paid_at';
		}
		if ( $is_paid && $payment['total_cents'] > 0 && '' === $payment['intent_id'] && '' === $payment['session_id'] ) {
			$issues[] = 'paid_missing_stripe_reference';
		}
		$installment = (int) ( $payment['installment_cents'] ?? 0 );
		if ( $is_paid && $installment > 0 && $installment < $payment['total_cents'] ) {
			$issues[] = 'partial_marked_paid';
		}
		$refunded = (int) ( $payment['refund_cents'] ?? 0 );
		if ( $is_paid && $refunded > 0 ) {
			$issues[] = 'repaid_after_refund';
		}
		if ( $refunded > 0 && $settlements ) {
			$debited = false;
			foreach ( $entries as $row ) {
				if ( 'debit' === $row['entry_type'] && 0 === strpos( (string) $row['source_type'], 'refund' ) ) {
					$debited = true;
				}
			}
			if ( ! $debited ) {
				$issues[] = 'refund_not_debited';
			}
		}
		if ( $is_paid && ! empty( $payment['voided'] ) ) {
			$issues[] = 'voided_but_paid';
		}
		if ( ! empty( $payment['extra_charges'] ) ) {
			$issues[] = 'extra_succeeded_charge';
		}
		if ( ! empty( $payment['needs_review'] ) ) {
			$issues[] = 'charge_' . sanitize_key( $payment['needs_review'] );
		}
		$credit = (int) ( $payment['credit_cents'] ?? 0 );
		if ( $is_paid && $credit > 0 && '' === $payment['intent_id'] && $payment['total_cents'] > 0 ) {
			$issues[] = 'credit_undercharged';
		}

		foreach ( $settlements as $row ) {
			$type = (string) $row['entry_type'];
			if ( '' !== $payment['destination'] && 'credit' === $type && 'payment' === $row['source_type'] ) {
				$issues[] = 'treasury_credit_on_destination_charge';
				break;
			}
		}

		if ( is_array( $pi ) && empty( $pi['error'] ) ) {
			$succeeded = 'succeeded' === $pi['status'];
			if ( $succeeded && ! $is_paid && 'refunded' !== $payment['status'] ) {
				$issues[] = 'stripe_succeeded_wp_not_paid';
			}
			if ( $is_paid && ! $succeeded ) {
				$issues[] = 'wp_paid_stripe_not_succeeded';
			}
			if ( $succeeded && $payment['total_cents'] > 0 && (int) $pi['amount_received'] !== $payment['total_cents'] ) {
				$issues[] = 'amount_mismatch';
			}
			if ( (string) $pi['destination'] !== $payment['destination'] ) {
				$issues[] = 'destination_mismatch';
			}
			if ( $succeeded && '' !== (string) $pi['destination'] && $payment['app_fee_cents'] > 0 && (int) $pi['application_fee_amount'] !== $payment['app_fee_cents'] ) {
				$issues[] = 'application_fee_mismatch';
			}
			if ( (int) $pi['amount_refunded'] > 0 && ! in_array( $payment['status'], array( 'refunded', 'partially_refunded' ), true ) ) {
				$issues[] = 'refunded_in_stripe_not_wp';
			}
			if ( $succeeded && $credit > 0 && (int) $pi['amount_received'] < $payment['total_cents'] ) {
				$issues[] = 'credit_undercharged';
			}
			if ( (int) ( $pi['succeeded_for_payment'] ?? 1 ) > 1 ) {
				$issues[] = 'extra_succeeded_charge';
			}
			if ( $succeeded && ! empty( $payment['voided'] ) ) {
				$issues[] = 'charge_on_voided_payment';
			}
			if ( in_array( (string) ( $pi['dispute_status'] ?? '' ), array( 'lost' ), true ) || ! empty( $pi['funds_withdrawn'] ) ) {
				$issues[] = 'lost_chargeback';
			}
		}

		if ( ! empty( $payment['id'] ) && class_exists( 'LNKup_Platform_Fees' ) && null !== LNKup_Platform_Fees::refunded_fee_gap( (int) $payment['id'] ) ) {
			$issues[] = 'refunded_fee_not_recorded';
		}

		return array_values( array_unique( $issues ) );
	}

	/**
	 * Clusters of ledger rows with the same amount on the same day (not proof of duplicates).
	 *
	 * @param array $ledger Ledger rows.
	 */
	public static function same_amount_clusters( array $ledger ) {
		$groups = array();
		foreach ( $ledger as $row ) {
			if ( ! in_array( $row['entry_type'], self::SETTLEMENT_ENTRY_TYPES, true ) ) {
				continue;
			}
			$key = substr( (string) $row['created_at'], 0, 10 ) . '|' . self::to_cents( $row['amount'] ) . '|' . $row['entry_type'];
			$groups[ $key ][] = $row;
		}
		$out = array();
		foreach ( $groups as $key => $rows ) {
			if ( count( $rows ) < 2 ) {
				continue;
			}
			list( $day, $cents, $type ) = explode( '|', $key );
			$payment_ids = array_map( 'intval', wp_list_pluck( $rows, 'payment_post_id' ) );
			$distinct    = array_values( array_unique( $payment_ids ) );
			$out[]       = array(
				'day'                 => $day,
				'amount_cents'        => (int) $cents,
				'entry_type'          => $type,
				'entry_ids'           => array_map( 'intval', wp_list_pluck( $rows, 'id' ) ),
				'payment_ids'         => $payment_ids,
				'distinct_payments'   => count( $distinct ),
				'verdict'             => count( $distinct ) === count( $rows ) && ! in_array( 0, $payment_ids, true )
					? 'distinct_payments'
					: 'possible_duplicate',
			);
		}
		return $out;
	}

	/**
	 * Load organizer payments with all Stripe references (direct reads only).
	 *
	 * @param int   $organizer_id Organizer user ID.
	 * @param array $range        from/to Y-m-d (inclusive) or empty.
	 */
	private static function load_payments( $organizer_id, $range ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_lnkup_organizer_user_id',
						'value' => (string) absint( $organizer_id ),
					),
				),
			)
		);

		$rows = array();
		foreach ( (array) $ids as $id ) {
			$id      = (int) $id;
			$paid_at = (string) get_post_meta( $id, '_lnkup_paid_at', true );
			$created = (string) get_post_field( 'post_date', $id );
			$anchor  = substr( '' !== $paid_at ? $paid_at : $created, 0, 10 );
			if ( ! empty( $range['from'] ) && $anchor < $range['from'] ) {
				continue;
			}
			if ( ! empty( $range['to'] ) && $anchor > $range['to'] ) {
				continue;
			}
			$total = get_post_meta( $id, '_lnkup_total_amount', true );
			if ( '' === (string) $total ) {
				$total = get_post_meta( $id, '_lnkup_amount', true );
			}
			$rows[] = array(
				'id'              => $id,
				'title'           => get_the_title( $id ),
				'status'          => (string) get_post_meta( $id, '_lnkup_payment_status', true ),
				'created_at'      => $created,
				'paid_at'         => $paid_at,
				'event_id'        => (int) get_post_meta( $id, '_lnkup_event_id', true ),
				'application_id'  => (int) get_post_meta( $id, '_lnkup_application_id', true ),
				'vendor_id'       => (int) get_post_meta( $id, '_lnkup_vendor_user_id', true ),
				'total_cents'     => self::to_cents( $total ),
				'amount_cents'    => self::to_cents( get_post_meta( $id, '_lnkup_amount', true ) ),
				'app_fee_cents'   => (int) get_post_meta( $id, '_lnkup_application_fee_cents', true ),
				'intent_id'       => (string) get_post_meta( $id, '_lnkup_stripe_payment_intent', true ),
				'session_id'      => (string) get_post_meta( $id, '_lnkup_stripe_checkout_session', true ),
				'charge_id'       => (string) get_post_meta( $id, '_lnkup_stripe_charge_id', true ),
				'transfer_id'     => (string) get_post_meta( $id, '_lnkup_stripe_transfer_id', true ),
				'settlement_mode' => (string) get_post_meta( $id, '_lnkup_settlement_mode', true ),
				'destination'     => (string) get_post_meta( $id, '_lnkup_stripe_destination_account', true ),
				'destination_cents' => self::to_cents( get_post_meta( $id, '_lnkup_stripe_destination_amount', true ) ),
				'credit_cents'      => self::to_cents( get_post_meta( $id, '_lnkup_credit_applied', true ) ),
				'refund_cents'      => self::to_cents( get_post_meta( $id, '_lnkup_refund_amount', true ) ),
				'installment_cents' => self::to_cents( get_post_meta( $id, '_lnkup_installment_amount', true ) ),
				'voided'            => '' !== (string) get_post_meta( $id, '_lnkup_voided_at', true ),
				'extra_charges'     => array_keys( is_array( get_post_meta( $id, '_lnkup_extra_charges', true ) ) ? get_post_meta( $id, '_lnkup_extra_charges', true ) : array() ),
				'needs_review'      => (string) get_post_meta( $id, '_lnkup_needs_review', true ),
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $b['paid_at'] ?: $b['created_at'], $a['paid_at'] ?: $a['created_at'] );
			}
		);
		return $rows;
	}

	/**
	 * @param int $organizer_id Organizer user ID.
	 */
	private static function load_ledger( $organizer_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_wallet_entries_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC", absint( $organizer_id ) ),
			ARRAY_A
		);
	}

	/**
	 * @param int $organizer_id Organizer user ID.
	 */
	private static function wallet_row( $organizer_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_wallets_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", absint( $organizer_id ) ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$out = array();
		foreach ( array( 'available_balance', 'pending_balance', 'processing_balance', 'lifetime_paid_out', 'reserve_hold' ) as $field ) {
			$out[ $field . '_cents' ] = self::to_cents( $row[ $field ] );
		}
		$out['updated_at'] = $row['updated_at'];
		return $out;
	}

	/**
	 * @param array $ledger Ledger rows.
	 * @return array<int, array> Keyed by payment_post_id (0 = no payment link).
	 */
	private static function group_ledger_by_payment( array $ledger ) {
		$out = array();
		foreach ( $ledger as $row ) {
			$out[ (int) $row['payment_post_id'] ][] = $row;
		}
		return $out;
	}

	/**
	 * @param array $entries Ledger rows for one payment.
	 */
	private static function settlement_rows( array $entries ) {
		$offset = array();
		foreach ( $entries as $row ) {
			if ( in_array( $row['source_type'], array( 'reconciliation_offset', 'reconciliation_offset_connect' ), true ) ) {
				$offset[ (int) $row['source_id'] ] = true;
			}
		}
		return array_values(
			array_filter(
				$entries,
				static function ( $row ) use ( $offset ) {
					return in_array( $row['entry_type'], self::SETTLEMENT_ENTRY_TYPES, true )
						&& in_array( $row['source_type'], array( 'payment', '' ), true )
						&& ! isset( $offset[ (int) $row['id'] ] );
				}
			)
		);
	}

	/**
	 * @param array $row Ledger row.
	 */
	private static function ledger_summary_row( $row ) {
		$meta = json_decode( (string) ( $row['metadata'] ?? '' ), true );
		return array(
			'id'              => (int) $row['id'],
			'entry_type'      => (string) $row['entry_type'],
			'source_type'     => (string) $row['source_type'],
			'source_id'       => (int) $row['source_id'],
			'payment_post_id' => (int) $row['payment_post_id'],
			'payout_id'       => (int) $row['payout_id'],
			'amount_cents'    => self::to_cents( $row['amount'] ),
			'note'            => (string) $row['note'],
			'created_at'      => (string) $row['created_at'],
			'recovery'        => is_array( $meta ) && ( ! empty( $meta['recovery_backfill'] ) || ! empty( $meta['migrated_from_treasury'] ) ),
		);
	}

	/**
	 * Totals in cents from each source so the UI figures can be traced.
	 *
	 * @param array      $payments Payments.
	 * @param array      $ledger   Ledger rows.
	 * @param array|null $wallet   Wallet row.
	 * @param array      $stripe   Stripe snapshot.
	 */
	private static function totals( $payments, $ledger, $wallet, $stripe ) {
		$paid_cents = 0;
		$paid_count = 0;
		foreach ( $payments as $payment ) {
			if ( 'paid' === $payment['status'] ) {
				$paid_cents += $payment['total_cents'];
				++$paid_count;
			}
		}
		$by_type = array();
		foreach ( $ledger as $row ) {
			$by_type[ $row['entry_type'] ] = ( $by_type[ $row['entry_type'] ] ?? 0 ) + self::to_cents( $row['amount'] );
		}
		$payout_debits = 0;
		foreach ( $ledger as $row ) {
			if ( 'debit' === $row['entry_type'] && 'payout' === $row['source_type'] ) {
				$payout_debits += self::to_cents( $row['amount'] );
			}
		}
		return array(
			'wp_paid_count'                 => $paid_count,
			'wp_paid_gross_cents'           => $paid_cents,
			'ledger_by_type_cents'          => $by_type,
			'ledger_connect_settlement_net_cents' => max( 0, ( $by_type['connect_settlement'] ?? 0 ) - $payout_debits ),
			'wallet_row'                    => $wallet,
			'stripe_succeeded_count'        => $stripe['succeeded_count'] ?? null,
			'stripe_succeeded_gross_cents'  => $stripe['succeeded_gross_cents'] ?? null,
			'stripe_connect_balance'        => $stripe['connect_balance'] ?? null,
		);
	}

	/**
	 * Stripe GET-only snapshot for an organizer.
	 *
	 * @param int    $organizer_id Organizer.
	 * @param string $account_id   Connected account ID.
	 * @param array  $payments     WP payments.
	 * @param array  $range        Range.
	 */
	private static function stripe_snapshot( $organizer_id, $account_id, $payments, $range ) {
		$client = new LNKup_Stripe_Client();
		if ( ! $client->is_available() ) {
			return array( 'enabled' => false, 'error' => 'stripe_not_configured' );
		}

		$intents = array();
		$errors  = array();
		$page    = null;
		for ( $i = 0; $i < self::MAX_STRIPE_PAGES; $i++ ) {
			$params = array(
				'query'  => "metadata['organizer_user_id']:'" . absint( $organizer_id ) . "'",
				'limit'  => 100,
				'expand' => array( 'data.latest_charge.balance_transaction' ),
			);
			if ( $page ) {
				$params['page'] = $page;
			}
			$result = $client->read( 'payment_intents/search', $params );
			if ( is_wp_error( $result ) ) {
				$errors[] = 'search: ' . $result->get_error_message();
				break;
			}
			foreach ( (array) ( $result['data'] ?? array() ) as $pi ) {
				$intents[ (string) $pi['id'] ] = self::normalize_intent( $pi );
			}
			if ( empty( $result['has_more'] ) || empty( $result['next_page'] ) ) {
				break;
			}
			$page = (string) $result['next_page'];
		}

		// Intents stored on WP payments but absent from search (indexing lag or metadata gaps).
		foreach ( $payments as $payment ) {
			$pi_id = $payment['intent_id'];
			if ( 0 !== strpos( $pi_id, 'pi_' ) || isset( $intents[ $pi_id ] ) ) {
				continue;
			}
			$result = $client->read( 'payment_intents/' . $pi_id, array( 'expand' => array( 'latest_charge.balance_transaction' ) ) );
			$intents[ $pi_id ] = is_wp_error( $result )
				? array( 'id' => $pi_id, 'error' => $result->get_error_message() )
				: self::normalize_intent( $result );
		}

		$succeeded_per_payment = array();
		foreach ( $intents as $pi_id => $pi ) {
			if ( 'succeeded' === ( $pi['status'] ?? '' ) && ! empty( $pi['payment_post_id'] ) ) {
				$succeeded_per_payment[ (int) $pi['payment_post_id'] ] = ( $succeeded_per_payment[ (int) $pi['payment_post_id'] ] ?? 0 ) + 1;
			}
			if ( ! empty( $pi['disputed'] ) && '' !== (string) ( $pi['charge_id'] ?? '' ) ) {
				$disputes = $client->read( 'disputes', array( 'charge' => $pi['charge_id'], 'limit' => 10 ) );
				if ( ! is_wp_error( $disputes ) ) {
					foreach ( (array) ( $disputes['data'] ?? array() ) as $dispute ) {
						$intents[ $pi_id ]['dispute_status'] = (string) ( $dispute['status'] ?? '' );
						$intents[ $pi_id ]['funds_withdrawn'] = ! empty( $dispute['balance_transactions'] ) && 'won' !== ( $dispute['status'] ?? '' );
					}
				}
			}
		}
		foreach ( $intents as $pi_id => $pi ) {
			$intents[ $pi_id ]['succeeded_for_payment'] = $succeeded_per_payment[ (int) ( $pi['payment_post_id'] ?? 0 ) ] ?? 0;
		}

		$by_payment     = array();
		$wp_ids         = wp_list_pluck( $payments, 'id' );
		$wp_intents     = array_filter( wp_list_pluck( $payments, 'intent_id' ) );
		$not_in_wp      = array();
		$succeeded      = 0;
		$succeeded_cents = 0;
		foreach ( $intents as $pi_id => $pi ) {
			$payment_id = (int) ( $pi['payment_post_id'] ?? 0 );
			$matched    = array_search( $pi_id, $wp_intents, true );
			if ( false !== $matched ) {
				$payment_id = (int) $payments[ $matched ]['id'];
			}
			if ( ! empty( $pi['status'] ) && 'succeeded' === $pi['status'] ) {
				$anchor = substr( (string) $pi['created_at'], 0, 10 );
				if ( ( empty( $range['from'] ) || $anchor >= $range['from'] ) && ( empty( $range['to'] ) || $anchor <= $range['to'] ) ) {
					++$succeeded;
					$succeeded_cents += (int) $pi['amount_received'];
				}
			}
			if ( $payment_id && in_array( $payment_id, $wp_ids, true ) ) {
				// Prefer the intent WordPress stored; otherwise the latest succeeded one.
				$existing = $by_payment[ $payment_id ] ?? null;
				if ( ! $existing || $pi_id === ( $payments[ $matched ]['intent_id'] ?? '' ) || ( 'succeeded' === ( $pi['status'] ?? '' ) && 'succeeded' !== ( $existing['status'] ?? '' ) ) ) {
					$by_payment[ $payment_id ] = $pi;
				}
			} elseif ( 'succeeded' === ( $pi['status'] ?? '' ) ) {
				$not_in_wp[] = $pi;
			}
		}

		$snapshot = array(
			'enabled'               => true,
			'intents_found'         => count( $intents ),
			'succeeded_count'       => $succeeded,
			'succeeded_gross_cents' => $succeeded_cents,
			'succeeded_not_in_wp'   => array_values( $not_in_wp ),
			'errors'                => $errors,
			'by_payment'            => $by_payment,
		);

		if ( '' !== $account_id ) {
			$snapshot['connect_account'] = self::connect_account_snapshot( $client, $account_id );
			$snapshot['connect_balance'] = self::connect_balance_snapshot( $client, $account_id );
			$snapshot['connect_payouts'] = self::connect_payouts_snapshot( $client, $account_id );
		}

		return $snapshot;
	}

	/**
	 * @param array $pi Raw Stripe PaymentIntent.
	 */
	private static function normalize_intent( $pi ) {
		$charge = is_array( $pi['latest_charge'] ?? null ) ? $pi['latest_charge'] : array();
		$bt     = is_array( $charge['balance_transaction'] ?? null ) ? $charge['balance_transaction'] : array();
		return array(
			'id'                     => (string) ( $pi['id'] ?? '' ),
			'status'                 => (string) ( $pi['status'] ?? '' ),
			'created_at'             => gmdate( 'Y-m-d H:i:s', (int) ( $pi['created'] ?? 0 ) ),
			'livemode'               => ! empty( $pi['livemode'] ),
			'amount'                 => (int) ( $pi['amount'] ?? 0 ),
			'amount_received'        => (int) ( $pi['amount_received'] ?? 0 ),
			'application_fee_amount' => (int) ( $pi['application_fee_amount'] ?? 0 ),
			'destination'            => (string) ( $pi['transfer_data']['destination'] ?? '' ),
			'payment_post_id'        => absint( $pi['metadata']['payment_post_id'] ?? 0 ),
			'charge_id'              => (string) ( $charge['id'] ?? ( is_string( $pi['latest_charge'] ?? null ) ? $pi['latest_charge'] : '' ) ),
			'transfer_id'            => (string) ( is_array( $charge['transfer'] ?? null ) ? ( $charge['transfer']['id'] ?? '' ) : ( $charge['transfer'] ?? '' ) ),
			'amount_refunded'        => (int) ( $charge['amount_refunded'] ?? 0 ),
			'disputed'               => ! empty( $charge['disputed'] ),
			'balance_transaction'    => $bt ? array(
				'id'           => (string) ( $bt['id'] ?? '' ),
				'fee'          => (int) ( $bt['fee'] ?? 0 ),
				'net'          => (int) ( $bt['net'] ?? 0 ),
				'status'       => (string) ( $bt['status'] ?? '' ),
				'available_on' => gmdate( 'Y-m-d', (int) ( $bt['available_on'] ?? 0 ) ),
			) : null,
		);
	}

	/**
	 * @param LNKup_Stripe_Client $client     Client.
	 * @param string              $account_id Connected account.
	 */
	private static function connect_account_snapshot( $client, $account_id ) {
		$acct = $client->read( 'accounts/' . $account_id );
		if ( is_wp_error( $acct ) ) {
			return array( 'error' => $acct->get_error_message() );
		}
		return array(
			'charges_enabled'      => ! empty( $acct['charges_enabled'] ),
			'payouts_enabled'      => ! empty( $acct['payouts_enabled'] ),
			'details_submitted'    => ! empty( $acct['details_submitted'] ),
			'disabled_reason'      => (string) ( $acct['requirements']['disabled_reason'] ?? '' ),
			'currently_due_count'  => count( (array) ( $acct['requirements']['currently_due'] ?? array() ) ),
			'past_due_count'       => count( (array) ( $acct['requirements']['past_due'] ?? array() ) ),
			'payout_schedule'      => (string) ( $acct['settings']['payouts']['schedule']['interval'] ?? '' ),
		);
	}

	/**
	 * @param LNKup_Stripe_Client $client     Client.
	 * @param string              $account_id Connected account.
	 */
	private static function connect_balance_snapshot( $client, $account_id ) {
		$balance = $client->read( 'balance', array(), $account_id );
		if ( is_wp_error( $balance ) ) {
			return array( 'error' => $balance->get_error_message() );
		}
		$sum = static function ( $list ) {
			$total = 0;
			foreach ( (array) $list as $row ) {
				if ( 'usd' === ( $row['currency'] ?? '' ) ) {
					$total += (int) ( $row['amount'] ?? 0 );
				}
			}
			return $total;
		};
		return array(
			'available_cents' => $sum( $balance['available'] ?? array() ),
			'pending_cents'   => $sum( $balance['pending'] ?? array() ),
			'synced_at'       => gmdate( 'c' ),
		);
	}

	/**
	 * @param LNKup_Stripe_Client $client     Client.
	 * @param string              $account_id Connected account.
	 */
	private static function connect_payouts_snapshot( $client, $account_id ) {
		$list = $client->read( 'payouts', array( 'limit' => 100 ), $account_id );
		if ( is_wp_error( $list ) ) {
			return array( 'error' => $list->get_error_message() );
		}
		$paid  = 0;
		$rows  = array();
		foreach ( (array) ( $list['data'] ?? array() ) as $payout ) {
			if ( 'paid' === ( $payout['status'] ?? '' ) ) {
				$paid += (int) $payout['amount'];
			}
			$rows[] = array(
				'id'           => (string) $payout['id'],
				'amount_cents' => (int) $payout['amount'],
				'status'       => (string) $payout['status'],
				'automatic'    => ! empty( $payout['automatic'] ),
				'arrival_date' => gmdate( 'Y-m-d', (int) ( $payout['arrival_date'] ?? 0 ) ),
				'lnkup_payout_id' => absint( $payout['metadata']['payout_id'] ?? 0 ),
			);
		}
		return array(
			'paid_total_cents' => $paid,
			'has_more'         => ! empty( $list['has_more'] ),
			'payouts'          => $rows,
		);
	}

	/**
	 * Webhook payloads indexed by the PaymentIntent / payment ID they mention.
	 *
	 * @param array $range Range.
	 */
	private static function webhook_index( $range ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::webhook_events_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		$from = ! empty( $range['from'] ) ? gmdate( 'Y-m-d 00:00:00', strtotime( $range['from'] . ' -2 days' ) ) : '1970-01-01 00:00:00';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_id, event_type, status, attempts, error_message, processed_at, created_at, payload
				FROM {$table} WHERE created_at >= %s AND ( event_type LIKE %s OR event_type LIKE %s OR event_type LIKE %s )
				ORDER BY id DESC LIMIT 3000",
				$from,
				'payment_intent.%',
				'checkout.session.%',
				'charge.%'
			),
			ARRAY_A
		);
		$index = array();
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			$object  = $payload['data']['object'] ?? array();
			$keys    = array_filter(
				array(
					(string) ( $object['id'] ?? '' ),
					is_string( $object['payment_intent'] ?? null ) ? $object['payment_intent'] : '',
					'post:' . absint( $object['metadata']['payment_post_id'] ?? ( $object['client_reference_id'] ?? 0 ) ),
				)
			);
			unset( $row['payload'] );
			foreach ( $keys as $key ) {
				if ( 'post:0' !== $key ) {
					$index[ $key ][] = $row;
				}
			}
		}
		return $index;
	}

	/**
	 * @param array $index   Webhook index.
	 * @param array $payment Payment.
	 */
	private static function webhooks_for_payment( $index, $payment ) {
		$rows = array();
		foreach ( array( $payment['intent_id'], $payment['session_id'], 'post:' . $payment['id'] ) as $key ) {
			if ( $key && isset( $index[ $key ] ) ) {
				foreach ( $index[ $key ] as $row ) {
					$rows[ $row['event_id'] ] = $row;
				}
			}
		}
		return array_values( $rows );
	}

	/**
	 * Webhook event counts and failures.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	public static function webhook_summary( $from, $to ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::webhook_events_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array( 'available' => false );
		}
		$from = $from ? $from . ' 00:00:00' : '1970-01-01 00:00:00';
		$to   = $to ? $to . ' 23:59:59' : '2999-12-31 23:59:59';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_type, status, COUNT(*) AS n FROM {$table} WHERE created_at BETWEEN %s AND %s GROUP BY event_type, status",
				$from,
				$to
			),
			ARRAY_A
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$failed = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_id, event_type, status, attempts, error_message, created_at FROM {$table}
				WHERE status NOT IN ('processed','ignored') AND created_at BETWEEN %s AND %s ORDER BY id DESC LIMIT 100",
				$from,
				$to
			),
			ARRAY_A
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$last = $wpdb->get_var( "SELECT MAX(created_at) FROM {$table}" );
		return array(
			'available'     => true,
			'counts'        => $counts,
			'not_processed' => $failed,
			'last_received' => $last,
		);
	}

	/**
	 * @param array $args from/to.
	 */
	private static function normalize_range( $args ) {
		$clean = static function ( $value ) {
			$value = substr( sanitize_text_field( (string) $value ), 0, 10 );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};
		return array(
			'from' => $clean( $args['from'] ?? '' ),
			'to'   => $clean( $args['to'] ?? '' ),
		);
	}

	/**
	 * Decimal dollars (string|float) to integer cents without float drift.
	 *
	 * @param mixed $amount Amount in dollars.
	 */
	public static function to_cents( $amount ) {
		if ( '' === $amount || null === $amount || false === $amount ) {
			return 0;
		}
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * @param string $id Stripe ID.
	 */
	private static function mask_id( $id ) {
		$id = (string) $id;
		if ( strlen( $id ) <= 10 ) {
			return $id;
		}
		return substr( $id, 0, 5 ) . '…' . substr( $id, -4 );
	}

	/**
	 * @param int $user_id User.
	 */
	private static function user_label( $user_id ) {
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : '';
	}
}
