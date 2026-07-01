<?php
/**
 * Financial transaction ledger.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Transactions {

	/**
	 * Generate unique public transaction ID.
	 */
	public static function generate_public_id() {
		return 'txn_' . strtolower( wp_generate_password( 12, false, false ) );
	}

	/**
	 * Record ledger row from payment CPT.
	 *
	 * @param int    $payment_id Payment post ID.
	 * @param string $event      Event slug (payment_created|payment_completed).
	 */
	public static function record_from_payment_post( $payment_id, $event = 'payment_created' ) {
		$payment = get_post( $payment_id );
		if ( ! $payment || 'lnkup_payment' !== $payment->post_type ) {
			return 0;
		}

		$existing = self::get_by_payment_post( $payment_id );
		$status   = get_post_meta( $payment_id, '_lnkup_payment_status', true ) ?: 'pending';
		$type     = 'payment';
		$now      = current_time( 'mysql' );

		if ( $existing && 'payment_completed' !== $event ) {
			return (int) $existing['id'];
		}

		if ( $existing && 'payment_completed' === $event ) {
			global $wpdb;
			$table = LNKup_Financial_Tables::transactions_table();
			$wpdb->update(
				$table,
				array(
					'status'       => sanitize_key( $status ),
					'provider_ref' => sanitize_text_field( (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true ) ),
					'updated_at'   => $now,
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			LNKup_Financial_Audit::log( 'payment_completed', 'transaction', (int) $existing['id'], array(
				'amount'   => (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ),
				'metadata' => array( 'payment_post_id' => $payment_id ),
			) );
			return (int) $existing['id'];
		}

		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		$wpdb->insert(
			$table,
			array(
				'public_id'         => self::generate_public_id(),
				'type'              => $type,
				'status'            => sanitize_key( $status ),
				'amount'            => (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ),
				'currency'          => 'USD',
				'vendor_user_id'    => (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ),
				'organizer_user_id' => (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true ),
				'event_id'          => (int) get_post_meta( $payment_id, '_lnkup_event_id', true ),
				'opportunity_id'    => (int) get_post_meta( $payment_id, '_lnkup_opportunity_id', true ),
				'application_id'    => (int) get_post_meta( $payment_id, '_lnkup_application_id', true ),
				'payment_post_id'   => $payment_id,
				'provider'          => LNKup_Payment_Provider::get_active_provider_id(),
				'provider_ref'      => sanitize_text_field( (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true ) ),
				'metadata'          => wp_json_encode( array(
					'vendor_spot_fee' => (float) get_post_meta( $payment_id, '_lnkup_vendor_spot_fee', true ),
					'lnkup_fee'       => (float) get_post_meta( $payment_id, '_lnkup_lnkup_fee', true ),
					'processing_fee'  => (float) get_post_meta( $payment_id, '_lnkup_processing_fee', true ),
				) ),
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%s', '%s', '%f', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$txn_id = (int) $wpdb->insert_id;
		LNKup_Financial_Audit::log( $event, 'transaction', $txn_id, array(
			'amount'   => (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ),
			'metadata' => array( 'payment_post_id' => $payment_id ),
		) );
		return $txn_id;
	}

	/**
	 * Sync extended ledger columns from payment CPT meta.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function sync_extended_fields( $payment_id ) {
		$existing = self::get_by_payment_post( $payment_id );
		if ( ! $existing ) {
			self::record_from_payment_post( $payment_id, 'payment_created' );
			$existing = self::get_by_payment_post( $payment_id );
		}
		if ( ! $existing ) {
			return;
		}
		$app_id  = (int) get_post_meta( $payment_id, '_lnkup_application_id', true );
		$invoice = $app_id ? LNKup_Invoices::get_by_application( $app_id ) : null;
		$vendor_id = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		$wpdb->update(
			$table,
			array(
				'status'                => sanitize_key( get_post_meta( $payment_id, '_lnkup_payment_status', true ) ?: 'pending' ),
				'invoice_number'        => $invoice['invoice_number'] ?? '',
				'payment_method'        => LNKup_Payment_Provider::get_active_provider_id(),
				'stripe_customer_id'    => sanitize_text_field( (string) get_user_meta( $vendor_id, '_lnkup_stripe_customer_id', true ) ),
				'stripe_payment_intent' => sanitize_text_field( (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true ) ),
				'checkout_session_id'   => sanitize_text_field( (string) get_post_meta( $payment_id, '_lnkup_stripe_checkout_session', true ) ),
				'platform_fee'          => (float) get_post_meta( $payment_id, '_lnkup_lnkup_fee', true ),
				'vendor_fee'            => (float) get_post_meta( $payment_id, '_lnkup_vendor_spot_fee', true ),
				'processing_fee'        => (float) get_post_meta( $payment_id, '_lnkup_processing_fee', true ),
				'tax_amount'            => (float) get_post_meta( $payment_id, '_lnkup_tax_amount', true ),
				'discount_amount'       => (float) get_post_meta( $payment_id, '_lnkup_discount_amount', true ),
				'credit_amount'         => (float) get_post_meta( $payment_id, '_lnkup_credit_applied', true ),
				'refund_amount'         => (float) get_post_meta( $payment_id, '_lnkup_refund_amount', true ),
				'paid_at'               => get_post_meta( $payment_id, '_lnkup_paid_at', true ) ?: null,
				'provider_ref'          => sanitize_text_field( (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true ) ),
				'updated_at'            => current_time( 'mysql' ),
			),
			array( 'id' => (int) $existing['id'] ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Sync Connect settlement columns on transaction row.
	 *
	 * @param int    $payment_id Payment post ID.
	 * @param array  $breakdown  Fee breakdown.
	 * @param string $mode       Settlement mode.
	 */
	public static function sync_settlement_fields( $payment_id, $breakdown, $mode = '' ) {
		$existing = self::get_by_payment_post( $payment_id );
		if ( ! $existing ) {
			return;
		}
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$account_id   = get_post_meta( $payment_id, '_lnkup_stripe_destination_account', true );
		if ( ! $account_id && class_exists( 'LNKup_Stripe_Connect' ) ) {
			$acct = LNKup_Stripe_Connect::get_account( $organizer_id, 'organizer' );
			$account_id = $acct['stripe_account_id'] ?? '';
		}
		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		$wpdb->update(
			$table,
			array(
				'settlement_mode'             => sanitize_key( $mode ?: get_post_meta( $payment_id, '_lnkup_settlement_mode', true ) ),
				'stripe_connect_account_id'   => sanitize_text_field( (string) $account_id ),
				'gross_amount'                => (float) ( $breakdown['gross_amount'] ?? 0 ),
				'platform_fee'                => (float) ( $breakdown['platform_fee'] ?? 0 ),
				'stripe_fee'                  => (float) ( $breakdown['stripe_fee'] ?? 0 ),
				'organizer_net'               => (float) ( $breakdown['organizer_net'] ?? 0 ),
				'reserve_hold'                => (float) ( $breakdown['reserve_hold'] ?? $breakdown['reserve_amount'] ?? 0 ),
				'metadata'                    => wp_json_encode( array(
					'vendor_checkout_fee'  => (float) ( $breakdown['vendor_checkout_fee'] ?? 0 ),
					'organizer_commission' => (float) ( $breakdown['organizer_commission'] ?? 0 ),
				) ),
				'updated_at'                  => current_time( 'mysql' ),
			),
			array( 'id' => (int) $existing['id'] )
		);
	}

	/**
	 * Backfill extended transaction columns from CPT meta.
	 */
	public static function backfill_extended_columns() {
		if ( get_option( 'lnkup_fin_extended_backfilled' ) === LNKUP_VERSION ) {
			return;
		}
		$payments = get_posts( array(
			'post_type'      => 'lnkup_payment',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post_status'    => 'any',
		) );
		foreach ( $payments as $payment_id ) {
			self::sync_extended_fields( $payment_id );
		}
		update_option( 'lnkup_fin_extended_backfilled', LNKUP_VERSION );
	}

	/**
	 * Get transaction by payment post ID.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function get_by_payment_post( $payment_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE payment_post_id = %d ORDER BY id DESC LIMIT 1", absint( $payment_id ) ),
			ARRAY_A
		);
	}

	/**
	 * Create generic transaction row.
	 *
	 * @param array $data Transaction data.
	 */
	public static function create( $data ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		$now   = current_time( 'mysql' );
		$row   = wp_parse_args(
			$data,
			array(
				'public_id'         => self::generate_public_id(),
				'type'              => 'adjustment',
				'status'            => 'completed',
				'amount'            => 0,
				'currency'          => 'USD',
				'vendor_user_id'    => 0,
				'organizer_user_id' => 0,
				'event_id'          => 0,
				'opportunity_id'    => 0,
				'application_id'    => 0,
				'payment_post_id'   => 0,
				'invoice_id'        => 0,
				'provider'          => '',
				'provider_ref'      => '',
				'metadata'          => null,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		if ( is_array( $row['metadata'] ) ) {
			$row['metadata'] = wp_json_encode( $row['metadata'] );
		}
		$wpdb->insert( $table, $row );
		$txn_id = (int) $wpdb->insert_id;
		LNKup_Financial_Audit::log( 'transaction_created', 'transaction', $txn_id, array(
			'amount'   => (float) ( $data['amount'] ?? 0 ),
			'metadata' => array( 'type' => $row['type'] ),
		) );
		return $txn_id;
	}

	/**
	 * List transactions for user scope.
	 *
	 * @param array $args Query args.
	 */
	public static function list_for_user( $args = array() ) {
		global $wpdb;
		$table  = LNKup_Financial_Tables::transactions_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		$user_id = absint( $args['user_id'] ?? get_current_user_id() );
		$role    = $args['role'] ?? 'vendor';
		$limit   = min( 100, max( 1, absint( $args['limit'] ?? 25 ) ) );
		$offset  = max( 0, absint( $args['offset'] ?? 0 ) );

		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) && ! empty( $args['all'] ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset ),
				ARRAY_A
			);
			return $rows ?: array();
		}

		$col = 'vendor' === $role ? 'vendor_user_id' : 'organizer_user_id';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$col} = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Backfill transactions from existing payment CPT posts.
	 */
	public static function backfill_from_payments() {
		if ( get_option( 'lnkup_financial_backfilled' ) ) {
			return;
		}
		$payments = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'any',
			)
		);
		foreach ( $payments as $payment_id ) {
			self::record_from_payment_post( $payment_id, 'payment_created' );
			if ( 'paid' === get_post_meta( $payment_id, '_lnkup_payment_status', true ) ) {
				self::record_from_payment_post( $payment_id, 'payment_completed' );
			}
		}
		update_option( 'lnkup_financial_backfilled', LNKUP_VERSION );
	}

	/**
	 * Format transaction for API response.
	 *
	 * @param array $row DB row.
	 */
	public static function format( $row ) {
		if ( ! $row ) {
			return null;
		}
		$meta = ! empty( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : array();
		$formatted = array(
			'id'              => (int) $row['id'],
			'public_id'       => $row['public_id'],
			'type'            => $row['type'],
			'status'          => $row['status'],
			'amount'          => (float) $row['amount'],
			'currency'        => $row['currency'],
			'vendor_user_id'  => (int) $row['vendor_user_id'],
			'organizer_user_id' => (int) $row['organizer_user_id'],
			'event_id'        => (int) $row['event_id'],
			'event_title'     => (int) $row['event_id'] ? get_the_title( (int) $row['event_id'] ) : '',
			'application_id'  => (int) $row['application_id'],
			'payment_post_id' => (int) $row['payment_post_id'],
			'invoice_id'      => (int) $row['invoice_id'],
			'provider'        => $row['provider'],
			'metadata'        => is_array( $meta ) ? $meta : array(),
			'created_at'      => $row['created_at'],
		);
		if ( ! empty( $row['payment_post_id'] ) && class_exists( 'LNKup_Payments' ) ) {
			$formatted['audit'] = LNKup_Payments::get_audit( (int) $row['payment_post_id'] );
		}
		return $formatted;
	}
}
