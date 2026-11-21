<?php
/**
 * Approval-gated historical ledger repair (admin only).
 *
 * Dry run is the default: it builds a plan from the read-only reconciliation report and
 * returns a confirmation token bound to that exact plan. Apply requires the token, refuses
 * if the data changed since the dry run, never deletes rows (duplicates get offsetting
 * debits), only settles payments Stripe confirms, and never calls Stripe write endpoints.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Repair {

	const NS = 'lnkup/v1';

	const TOKEN_TTL = 30 * MINUTE_IN_SECONDS;

	const RUNS_OPTION = 'lnkup_fin_repair_runs';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/admin/financial/repair',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_repair' ),
				'permission_callback' => array( 'LNKup_Financial_Reconciliation', 'permission' ),
				'args'                => array(
					'organizer_id'       => array( 'type' => 'integer', 'required' => true ),
					'mode'               => array( 'type' => 'string', 'enum' => array( 'dry_run', 'apply' ), 'default' => 'dry_run' ),
					'confirmation_token' => array( 'type' => 'string', 'required' => false ),
					'items'              => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'required' => false ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/financial/repair-runs',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_runs' ),
				'permission_callback' => array( 'LNKup_Financial_Reconciliation', 'permission' ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_repair( $request ) {
		$organizer_id = absint( $request->get_param( 'organizer_id' ) );
		if ( ! $organizer_id || ! get_userdata( $organizer_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Organizer not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( 'apply' !== $request->get_param( 'mode' ) ) {
			return rest_ensure_response( self::dry_run( $organizer_id ) );
		}
		$result = self::apply(
			$organizer_id,
			(string) $request->get_param( 'confirmation_token' ),
			array_map( 'sanitize_text_field', (array) $request->get_param( 'items' ) )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function rest_runs() {
		return rest_ensure_response( array_values( (array) get_option( self::RUNS_OPTION, array() ) ) );
	}

	/**
	 * Build the plan and a confirmation token. Writes nothing except the token transient.
	 *
	 * @param int  $organizer_id Organizer.
	 * @param bool $use_stripe   Verify against Stripe (GET only).
	 */
	public static function dry_run( $organizer_id, $use_stripe = true ) {
		$plan  = self::plan( $organizer_id, $use_stripe );
		$token = self::token_for( $organizer_id, $plan );
		set_transient(
			'lnkup_fin_repair_' . $token,
			array(
				'organizer_id' => $organizer_id,
				'plan_hash'    => self::plan_hash( $plan ),
				'use_stripe'   => $use_stripe,
				'created_by'   => get_current_user_id(),
			),
			self::TOKEN_TTL
		);
		$applicable = array_values( array_filter( $plan, static function ( $item ) {
			return $item['applicable'];
		} ) );
		return array(
			'mode'               => 'dry_run',
			'organizer_id'       => $organizer_id,
			'generated_at'       => gmdate( 'c' ),
			'items'              => $plan,
			'applicable_count'   => count( $applicable ),
			'net_effect_cents'   => array_sum( wp_list_pluck( $applicable, 'ledger_effect_cents' ) ),
			'confirmation_token' => $token,
			'expires_at'         => gmdate( 'c', time() + self::TOKEN_TTL ),
			'stripe_verified'    => $use_stripe,
		);
	}

	/**
	 * Apply an approved plan.
	 *
	 * @param int      $organizer_id Organizer.
	 * @param string   $token        Token from dry_run().
	 * @param string[] $only_items   Optional subset of item IDs.
	 * @return array|WP_Error
	 */
	public static function apply( $organizer_id, $token, $only_items = array() ) {
		$token  = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $token ) );
		$stored = $token ? get_transient( 'lnkup_fin_repair_' . $token ) : false;
		if ( ! is_array( $stored ) || (int) $stored['organizer_id'] !== (int) $organizer_id ) {
			return new WP_Error( 'lnkup_repair_token', __( 'Run a dry run first and apply with its confirmation token.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}

		return LNKup_Financial_Service::with_lock(
			'repair_' . absint( $organizer_id ),
			static function () use ( $organizer_id, $token, $stored, $only_items ) {
				$plan = self::plan( $organizer_id, ! empty( $stored['use_stripe'] ) );
				if ( self::plan_hash( $plan ) !== $stored['plan_hash'] ) {
					return new WP_Error( 'lnkup_repair_stale', __( 'The data changed since the dry run. Run a new dry run and review it again.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
				}
				delete_transient( 'lnkup_fin_repair_' . $token );

				$run_id  = 'run_' . gmdate( 'YmdHis' ) . '_' . wp_generate_password( 6, false, false );
				$context = array( 'run_id' => $run_id, 'actor_user_id' => get_current_user_id() );
				$results = array();
				foreach ( $plan as $item ) {
					if ( ! $item['applicable'] || ( $only_items && ! in_array( $item['id'], $only_items, true ) ) ) {
						continue;
					}
					$outcome = self::apply_item( $organizer_id, $item, $context );
					$results[] = array(
						'id'     => $item['id'],
						'type'   => $item['type'],
						'status' => is_wp_error( $outcome ) ? 'error' : ( $outcome ? 'applied' : 'skipped' ),
						'error'  => is_wp_error( $outcome ) ? $outcome->get_error_message() : '',
					);
				}

				$run = array(
					'run_id'       => $run_id,
					'organizer_id' => (int) $organizer_id,
					'actor'        => get_current_user_id(),
					'applied_at'   => current_time( 'mysql' ),
					'results'      => $results,
				);
				LNKup_Financial_Audit::log( 'reconciliation_repair_run', 'wallet', $organizer_id, array( 'metadata' => $run ) );
				$runs = (array) get_option( self::RUNS_OPTION, array() );
				array_unshift( $runs, $run );
				update_option( self::RUNS_OPTION, array_slice( $runs, 0, 50 ), false );

				return array_merge( array( 'mode' => 'apply' ), $run );
			},
			30
		);
	}

	/**
	 * Repair plan from the read-only reconciliation report.
	 *
	 * @param int  $organizer_id Organizer.
	 * @param bool $use_stripe   Verify against Stripe.
	 * @return array<int, array> Plan items.
	 */
	public static function plan( $organizer_id, $use_stripe = true ) {
		$report        = LNKup_Financial_Reconciliation::organizer_report( $organizer_id, array( 'stripe' => $use_stripe ) );
		$stripe_on     = ! empty( $report['stripe']['enabled'] );
		$items         = array();

		foreach ( $report['payments'] as $payment ) {
			$issues = (array) $payment['issues'];
			$pi     = is_array( $payment['stripe'] ) && empty( $payment['stripe']['error'] ) ? $payment['stripe'] : null;

			if ( in_array( 'duplicate_ledger', $issues, true ) ) {
				$settlements = array_values( array_filter( $payment['ledger'], static function ( $row ) {
					return in_array( $row['entry_type'], LNKup_Financial_Reconciliation::SETTLEMENT_ENTRY_TYPES, true )
						&& in_array( $row['source_type'], array( 'payment', '' ), true );
				} ) );
				foreach ( array_slice( $settlements, 1 ) as $dup ) {
					if ( self::offset_exists( $dup['id'] ) ) {
						continue;
					}
					$items[] = array(
						'id'                  => 'dup:' . $dup['id'],
						'type'                => 'offset_duplicate_settlement',
						'payment_id'          => $payment['id'],
						'entry_id'            => $dup['id'],
						'entry_type'          => $dup['entry_type'],
						'amount_cents'        => $dup['amount_cents'],
						'ledger_effect_cents' => -1 * $dup['amount_cents'],
						'applicable'          => true,
						'reason'              => sprintf( 'Payment #%d has %d settlement rows; entry #%d is offset (kept: #%d).', $payment['id'], count( $settlements ), $dup['id'], $settlements[0]['id'] ),
					);
				}
			}

			if ( in_array( 'paid_missing_ledger', $issues, true ) ) {
				$verified = $pi && 'succeeded' === $pi['status'] && (int) $pi['amount_received'] >= (int) $payment['total_cents'];
				$items[]  = array(
					'id'                  => 'settle:' . $payment['id'],
					'type'                => 'settle_missing_payment',
					'payment_id'          => $payment['id'],
					'amount_cents'        => $payment['total_cents'],
					'ledger_effect_cents' => 0,
					'applicable'          => $verified,
					'reason'              => $verified
						? 'Paid in WordPress and succeeded in Stripe, but never reached the ledger.'
						: ( $stripe_on ? 'Stripe does not confirm this charge; not settled.' : 'Needs Stripe verification (run with Stripe access).' ),
				);
			}

			if ( in_array( 'stripe_succeeded_wp_not_paid', $issues, true ) ) {
				$items[] = array(
					'id'                  => 'finalize:' . $payment['id'],
					'type'                => 'finalize_stripe_paid',
					'payment_id'          => $payment['id'],
					'intent_id'           => (string) ( $pi['id'] ?? '' ),
					'amount_cents'        => (int) ( $pi['amount_received'] ?? 0 ),
					'ledger_effect_cents' => 0,
					'applicable'          => null !== $pi && 0 === strpos( (string) ( $pi['id'] ?? '' ), 'pi_' ),
					'reason'              => 'Stripe collected this payment but WordPress still shows it unpaid.',
				);
			}
		}

		foreach ( (array) ( $report['migrations']['unrecorded'] ?? array() ) as $migration ) {
			if ( self::migration_restored( $migration['audit_id'] ) ) {
				continue;
			}
			$items[] = array(
				'id'                  => 'migration:' . $migration['audit_id'],
				'type'                => 'restore_migration_ledger_row',
				'audit_id'            => $migration['audit_id'],
				'amount_cents'        => $migration['amount_cents'],
				'ledger_effect_cents' => $migration['amount_cents'],
				'applicable'          => true,
				'reason'              => sprintf( 'Treasury balance of $%s was zeroed on %s but its ledger row failed to save.', number_format( $migration['amount_cents'] / 100, 2 ), $migration['created_at'] ),
			);
		}

		return $items;
	}

	/**
	 * @param int   $organizer_id Organizer.
	 * @param array $item         Plan item.
	 * @param array $context      Run context.
	 * @return bool|WP_Error
	 */
	private static function apply_item( $organizer_id, $item, $context ) {
		switch ( $item['type'] ) {
			case 'offset_duplicate_settlement':
				return self::offset_duplicate( $organizer_id, $item, $context );
			case 'settle_missing_payment':
				return LNKup_Financial_Service::repair_settle( $item['payment_id'], $context );
			case 'finalize_stripe_paid':
				$result = LNKup_Financial_Service::reconcile_payment( $item['payment_id'], $item['intent_id'] );
				return is_wp_error( $result ) ? $result : true;
			case 'restore_migration_ledger_row':
				return self::restore_migration( $organizer_id, $item, $context );
			default:
				return new WP_Error( 'lnkup_repair_unknown', 'Unknown repair item.' );
		}
	}

	/**
	 * Offset a duplicate settlement row with an equal debit (the original row stays).
	 *
	 * @param int   $organizer_id Organizer.
	 * @param array $item         Plan item.
	 * @param array $context      Run context.
	 */
	private static function offset_duplicate( $organizer_id, $item, $context ) {
		$amount = round( $item['amount_cents'] / 100, 2 );
		$note   = sprintf( 'Duplicate settlement offset (entry #%d, payment #%d)', $item['entry_id'], $item['payment_id'] );
		$meta   = array_merge( $context, array( 'offsets_entry_id' => $item['entry_id'] ) );
		$key    = 'repair:dup:' . $item['entry_id'];

		if ( 'connect_settlement' === $item['entry_type'] ) {
			$wallet = LNKup_Organizer_Wallet::get_or_create( $organizer_id );
			$id     = LNKup_Organizer_Wallet::insert_entry(
				array(
					'wallet_id'       => (int) $wallet['id'],
					'user_id'         => $organizer_id,
					'entry_type'      => 'debit',
					'amount'          => $amount,
					'balance_after'   => LNKup_Organizer_Wallet::sum_connect_settlement( $organizer_id ) - $amount,
					'source_type'     => 'reconciliation_offset_connect',
					'source_id'       => $item['entry_id'],
					'payment_post_id' => $item['payment_id'],
					'note'            => $note,
					'metadata'        => wp_json_encode( $meta ),
					'idempotency_key' => $key,
				)
			);
			return (bool) $id;
		}

		$row    = LNKup_Organizer_Wallet::get_row( $organizer_id );
		$bucket = ( (float) ( $row['available_balance'] ?? 0 ) >= $amount ) ? 'available' : 'pending';
		$id     = LNKup_Organizer_Wallet::add_entry(
			$organizer_id,
			$amount,
			$bucket,
			array(
				'entry_type'      => 'debit',
				'source_type'     => 'reconciliation_offset',
				'source_id'       => $item['entry_id'],
				'payment_post_id' => $item['payment_id'],
				'note'            => $note,
				'metadata'        => array_merge( $meta, array( 'bucket' => $bucket ) ),
				'idempotency_key' => $key,
			)
		);
		return (bool) $id;
	}

	/**
	 * Re-create the ledger row a treasury migration should have written.
	 *
	 * @param int   $organizer_id Organizer.
	 * @param array $item         Plan item.
	 * @param array $context      Run context.
	 */
	private static function restore_migration( $organizer_id, $item, $context ) {
		$wallet = LNKup_Organizer_Wallet::get_or_create( $organizer_id );
		$id     = LNKup_Organizer_Wallet::insert_entry(
			array(
				'wallet_id'       => (int) $wallet['id'],
				'user_id'         => $organizer_id,
				'entry_type'      => 'connect_settlement',
				'amount'          => round( $item['amount_cents'] / 100, 2 ),
				'balance_after'   => 0,
				'source_type'     => 'treasury_migration',
				'source_id'       => $item['audit_id'],
				'note'            => sprintf( 'Restored treasury migration ledger row (audit #%d)', $item['audit_id'] ),
				'metadata'        => wp_json_encode( array_merge( $context, array( 'migrated_from_treasury' => true, 'restored_from_audit' => $item['audit_id'] ) ) ),
				'idempotency_key' => 'repair:migration:' . $item['audit_id'],
			)
		);
		return (bool) $id;
	}

	/**
	 * @param int $entry_id Ledger row ID.
	 */
	private static function offset_exists( $entry_id ) {
		return self::key_exists( 'repair:dup:' . absint( $entry_id ) );
	}

	/**
	 * @param int $audit_id Audit row ID.
	 */
	private static function migration_restored( $audit_id ) {
		return self::key_exists( 'repair:migration:' . absint( $audit_id ) );
	}

	/**
	 * @param string $key Idempotency key.
	 */
	private static function key_exists( $key ) {
		global $wpdb;
		if ( ! LNKup_Organizer_Wallet::has_idempotency_column() ) {
			return false;
		}
		$table = LNKup_Treasury_Tables::organizer_wallet_entries_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE idempotency_key = %s LIMIT 1", $key ) );
	}

	/**
	 * @param array $plan Plan items.
	 */
	private static function plan_hash( $plan ) {
		return hash( 'sha256', (string) wp_json_encode( $plan ) );
	}

	/**
	 * @param int   $organizer_id Organizer.
	 * @param array $plan         Plan items.
	 */
	private static function token_for( $organizer_id, $plan ) {
		return hash_hmac( 'sha256', $organizer_id . '|' . self::plan_hash( $plan ) . '|' . microtime( true ), wp_salt( 'auth' ) );
	}
}
