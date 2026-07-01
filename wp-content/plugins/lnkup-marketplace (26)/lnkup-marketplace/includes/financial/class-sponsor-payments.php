<?php
/**
 * Sponsor invoice and payment management.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Sponsor_Payments {

	/**
	 * Generate a unique sponsor invoice number.
	 */
	private static function next_invoice_number(): string {
		return 'SP-' . strtoupper( wp_generate_password( 10, false, false ) );
	}

	/**
	 * Create sponsor invoice.
	 *
	 * @param array $data Invoice data.
	 */
	public static function create_invoice( $data ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::sponsor_invoices_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}
		$now           = current_time( 'mysql' );
		$total_amount  = round( (float) ( $data['total_amount'] ?? $data['amount'] ?? 0 ), 2 );
		$due_date      = ! empty( $data['due_date'] ) ? sanitize_text_field( $data['due_date'] ) : null;
		$due_date_sql  = $due_date ? $due_date : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'invoice_number'    => self::next_invoice_number(),
				'organizer_user_id' => absint( $data['organizer_user_id'] ?? 0 ),
				'event_id'          => absint( $data['event_id'] ?? 0 ),
				'sponsor_name'      => sanitize_text_field( $data['sponsor_name'] ?? '' ),
				'sponsor_email'     => sanitize_email( $data['sponsor_email'] ?? '' ),
				'package_name'      => sanitize_text_field( $data['package_name'] ?? '' ),
				'status'            => 'draft',
				'total_amount'      => $total_amount,
				'paid_amount'       => 0,
				'due_date'          => $due_date_sql,
				'notes'             => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * List sponsor invoices for organizer.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function list_invoices( $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::sponsor_invoices_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE organizer_user_id = %d ORDER BY created_at DESC LIMIT 50", absint( $user_id ) ),
			ARRAY_A
		);
	}

	/**
	 * Record sponsor payment.
	 *
	 * @param int   $invoice_id Invoice ID.
	 * @param float $amount     Amount paid.
	 * @param array $meta       Metadata.
	 */
	public static function record_payment( $invoice_id, $amount, $meta = array() ) {
		global $wpdb;
		$inv_table = LNKup_Treasury_Tables::sponsor_invoices_table();
		$pay_table = LNKup_Treasury_Tables::sponsor_payments_table();
		if ( ! LNKup_Financial_Tables::table_exists( $inv_table ) || ! LNKup_Financial_Tables::table_exists( $pay_table ) ) {
			return new WP_Error( 'not_available', __( 'Sponsor payments are not available.', 'lnkup-marketplace' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$invoice = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$inv_table} WHERE id = %d", absint( $invoice_id ) ), ARRAY_A );
		if ( ! $invoice ) {
			return new WP_Error( 'not_found', __( 'Sponsor invoice not found.', 'lnkup-marketplace' ) );
		}
		$now    = current_time( 'mysql' );
		$amount = round( (float) $amount, 2 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$pay_table,
			array(
				'sponsor_invoice_id' => absint( $invoice_id ),
				'amount'             => $amount,
				'status'             => 'completed',
				'payment_post_id'    => absint( $meta['payment_post_id'] ?? 0 ),
				'paid_at'            => $now,
				'metadata'           => wp_json_encode( $meta ),
				'created_at'         => $now,
			)
		);
		$paid_total = round( (float) ( $invoice['paid_amount'] ?? 0 ) + $amount, 2 );
		$new_status = $paid_total >= (float) ( $invoice['total_amount'] ?? 0 ) ? 'paid' : 'partial';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$inv_table,
			array(
				'status'      => $new_status,
				'paid_amount' => $paid_total,
				'updated_at'  => $now,
			),
			array( 'id' => $invoice_id )
		);
		if ( class_exists( 'LNKup_Organizer_Wallet' ) ) {
			LNKup_Organizer_Wallet::add_entry(
				(int) $invoice['organizer_user_id'],
				$amount,
				'available',
				array(
					'source_type' => 'sponsor_payment',
					'source_id'   => $invoice_id,
					'note'        => __( 'Sponsor payment received', 'lnkup-marketplace' ),
				)
			);
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Sum sponsor revenue for organizer.
	 *
	 * Payments link to invoices via sponsor_invoice_id; organizer_user_id lives on invoices only.
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function total_revenue( $user_id ) {
		global $wpdb;
		$pay_table = LNKup_Treasury_Tables::sponsor_payments_table();
		$inv_table = LNKup_Treasury_Tables::sponsor_invoices_table();
		if ( ! LNKup_Financial_Tables::table_exists( $pay_table ) || ! LNKup_Financial_Tables::table_exists( $inv_table ) ) {
			return 0.0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(sp.amount), 0) FROM {$pay_table} sp
				INNER JOIN {$inv_table} si ON si.id = sp.sponsor_invoice_id
				WHERE si.organizer_user_id = %d AND sp.status IN ('completed', 'paid')",
				absint( $user_id )
			)
		);
	}
}
