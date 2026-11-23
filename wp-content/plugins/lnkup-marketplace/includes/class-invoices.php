<?php
/**
 * Invoice management.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Invoices {

	const STATUSES = array( 'draft', 'sent', 'pending', 'paid', 'partially_paid', 'overdue', 'cancelled', 'refunded', 'void' );

	public static function init() {
		add_action( 'lnkup_application_approved', array( __CLASS__, 'on_application_approved' ), 15, 1 );
	}

	/**
	 * Auto-create invoice when application is approved.
	 *
	 * @param int $app_id Application post ID.
	 */
	public static function on_application_approved( $app_id ) {
		$payment_id = (int) get_post_meta( $app_id, '_lnkup_payment_id', true );
		if ( ! $payment_id ) {
			$payments = get_posts( array(
				'post_type'      => 'lnkup_payment',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_application_id',
				'meta_value'     => absint( $app_id ),
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
			) );
			$payment_id = $payments ? (int) $payments[0] : 0;
		}
		if ( ! $payment_id ) {
			return;
		}
		// LNKup_Payments creates the invoice and fires lnkup_invoice_created( $payment_id, $invoice_id ).
		$invoice_id = (int) ( self::get_by_payment( $payment_id )['id'] ?? 0 );
		if ( ! $invoice_id ) {
			$invoice_id = self::create_from_payment( $payment_id );
			if ( $invoice_id ) {
				do_action( 'lnkup_invoice_created', $payment_id, $invoice_id );
			}
		}
		$invoice = $invoice_id ? self::get( $invoice_id ) : null;
		if ( ! $invoice || (float) $invoice['total_amount'] <= 0 || self::meta_flag( $invoice, 'emailed_at' ) ) {
			return;
		}
		if ( self::email_invoice( $invoice ) ) {
			self::set_meta_flag( $invoice_id, 'emailed_at', current_time( 'mysql' ) );
		}
	}

	/**
	 * Latest invoice for a payment post (matched through its application).
	 *
	 * @param int $payment_id Payment post ID.
	 * @return array|null
	 */
	public static function get_by_payment( $payment_id ) {
		$app_id = (int) get_post_meta( absint( $payment_id ), '_lnkup_application_id', true );
		return $app_id ? self::get_by_application( $app_id ) : null;
	}

	/**
	 * @param array  $invoice Invoice row.
	 * @param string $key     Metadata key.
	 * @return mixed
	 */
	private static function meta_flag( $invoice, $key ) {
		$meta = ! empty( $invoice['metadata'] ) ? json_decode( (string) $invoice['metadata'], true ) : array();
		return is_array( $meta ) ? ( $meta[ $key ] ?? null ) : null;
	}

	/**
	 * Merge one key into the invoice metadata JSON.
	 *
	 * @param int    $invoice_id Invoice ID.
	 * @param string $key        Metadata key.
	 * @param mixed  $value      Value.
	 */
	private static function set_meta_flag( $invoice_id, $key, $value ) {
		global $wpdb;
		$invoice = self::get( $invoice_id );
		if ( ! $invoice ) {
			return;
		}
		$meta         = ! empty( $invoice['metadata'] ) ? json_decode( (string) $invoice['metadata'], true ) : array();
		$meta         = is_array( $meta ) ? $meta : array();
		$meta[ $key ] = $value;
		$wpdb->update( LNKup_Financial_Tables::invoices_table(), array( 'metadata' => wp_json_encode( $meta ) ), array( 'id' => absint( $invoice_id ) ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Close an unpaid invoice whose booking left the payment flow (rejected, withdrawn, expired).
	 *
	 * @param int    $application_id Application ID.
	 * @param string $reason         Audit reason.
	 */
	public static function mark_void_for_application( $application_id, $reason = '' ) {
		global $wpdb;
		$invoice = self::get_by_application( $application_id );
		if ( ! $invoice || in_array( $invoice['status'], array( 'paid', 'partially_paid', 'refunded', 'void', 'cancelled' ), true ) ) {
			return false;
		}
		$wpdb->update( LNKup_Financial_Tables::invoices_table(), array( 'status' => 'void', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $invoice['id'] ), array( '%s', '%s' ), array( '%d' ) );
		LNKup_Financial_Audit::log( 'invoice_voided', 'invoice', (int) $invoice['id'], array( 'reason' => sanitize_key( $reason ), 'application_id' => (int) $application_id ) );
		return true;
	}

	/**
	 * Reflect a refund on the invoice; paid_amount keeps the original collected amount for history.
	 *
	 * @param int  $application_id Application ID.
	 * @param bool $full           Full refund.
	 */
	public static function mark_refunded_for_application( $application_id, $full ) {
		global $wpdb;
		$invoice = self::get_by_application( $application_id );
		if ( ! $invoice || ! in_array( $invoice['status'], array( 'paid', 'partially_paid', 'refunded' ), true ) ) {
			return false;
		}
		$status = $full ? 'refunded' : (string) $invoice['status'];
		$wpdb->update( LNKup_Financial_Tables::invoices_table(), array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $invoice['id'] ), array( '%s', '%s' ), array( '%d' ) );
		self::set_meta_flag( (int) $invoice['id'], $full ? 'refunded_at' : 'partially_refunded_at', current_time( 'mysql' ) );
		LNKup_Financial_Audit::log( $full ? 'invoice_refunded' : 'invoice_partially_refunded', 'invoice', (int) $invoice['id'], array( 'application_id' => (int) $application_id ) );
		return true;
	}

	/**
	 * Vendor-facing line items: only what the vendor pays, summing to the payment total.
	 *
	 * @param int $payment_id Payment post ID.
	 * @return array<int, array{0:string,1:float}>
	 */
	public static function line_items_for_payment( $payment_id ) {
		$items = array(
			array( __( 'Booth fee', 'lnkup-marketplace' ), (float) get_post_meta( $payment_id, '_lnkup_vendor_spot_fee', true ) ),
			array( __( 'LNKup checkout fee', 'lnkup-marketplace' ), (float) get_post_meta( $payment_id, '_lnkup_vendor_checkout_fee', true ) ),
			array( __( 'Processing fee', 'lnkup-marketplace' ), (float) get_post_meta( $payment_id, '_lnkup_processing_fee', true ) ),
			array( __( 'Sales tax', 'lnkup-marketplace' ), (float) get_post_meta( $payment_id, '_lnkup_tax_amount', true ) ),
			array( __( 'Discount', 'lnkup-marketplace' ), -1 * (float) get_post_meta( $payment_id, '_lnkup_discount_amount', true ) ),
		);
		$items = array_values(
			array_filter(
				$items,
				static function ( $row ) {
					return abs( $row[1] ) >= 0.005;
				}
			)
		);
		$sum    = array_sum( array_column( $items, 1 ) );
		$total  = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
		$credit = (float) get_post_meta( $payment_id, '_lnkup_credit_applied', true );
		if ( $credit > 0 && abs( ( $sum - $credit ) - $total ) < 0.01 ) {
			$items[] = array( __( 'Credit applied', 'lnkup-marketplace' ), -1 * $credit );
		}
		return $items;
	}

	public static function generate_number() {
		return 'INV-' . strtoupper( wp_generate_password( 8, false, false ) );
	}

	public static function create_from_payment( $payment_id ) {
		$payment = get_post( $payment_id );
		if ( ! $payment ) {
			return 0;
		}
		$app_id = (int) get_post_meta( $payment_id, '_lnkup_application_id', true );
		if ( $app_id ) {
			$existing = self::get_by_application( $app_id );
			// A voided invoice belongs to a replaced payment; the new payment gets its own invoice.
			if ( $existing && ! in_array( $existing['status'], array( 'void', 'cancelled' ), true ) ) {
				return (int) $existing['id'];
			}
		}
		global $wpdb;
		$table = LNKup_Financial_Tables::invoices_table();
		$now   = current_time( 'mysql' );
		$total = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
		$wpdb->insert(
			$table,
			array(
				'invoice_number'    => self::generate_number(),
				'vendor_user_id'    => (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ),
				'organizer_user_id' => (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true ),
				'event_id'          => (int) get_post_meta( $payment_id, '_lnkup_event_id', true ),
				'opportunity_id'    => (int) get_post_meta( $payment_id, '_lnkup_opportunity_id', true ),
				'application_id'    => (int) get_post_meta( $payment_id, '_lnkup_application_id', true ),
				'status'            => 'pending',
				'issue_date'        => gmdate( 'Y-m-d' ),
				'due_date'          => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
				'subtotal'          => $total,
				'total_amount'      => $total,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$invoice_id = (int) $wpdb->insert_id;
		$verify_token = wp_generate_password( 24, false );
		$wpdb->update(
			$table,
			array(
				'status'   => 'sent',
				'metadata' => wp_json_encode( array( 'verify_token' => $verify_token ) ),
			),
			array( 'id' => $invoice_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		foreach ( self::line_items_for_payment( $payment_id ) as $row ) {
			self::add_item( $invoice_id, $row[0], $row[1] );
		}
		LNKup_Financial_Audit::log( 'invoice_generated', 'invoice', $invoice_id, array( 'amount' => $total ) );
		return $invoice_id;
	}

	public static function add_item( $invoice_id, $description, $amount ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::invoice_items_table();
		$wpdb->insert(
			$table,
			array(
				'invoice_id'  => absint( $invoice_id ),
				'description' => sanitize_text_field( $description ),
				'quantity'    => 1,
				'unit_amount' => $amount,
				'line_total'  => $amount,
			)
		);
	}

	public static function mark_paid( $invoice_id, $amount = null ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::invoices_table();
		$inv   = self::get( $invoice_id );
		if ( ! $inv ) {
			return false;
		}
		$paid = null !== $amount ? (float) $amount : (float) $inv['total_amount'];
		$status = $paid >= (float) $inv['total_amount'] ? 'paid' : 'partially_paid';
		$wpdb->update(
			$table,
			array( 'status' => $status, 'paid_amount' => $paid, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $invoice_id ),
			array( '%s', '%f', '%s' ),
			array( '%d' )
		);
		LNKup_Financial_Audit::log( 'invoice_paid', 'invoice', $invoice_id, array( 'amount' => $paid ) );
		return true;
	}

	public static function get_by_application( $application_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . LNKup_Financial_Tables::invoices_table() . ' WHERE application_id = %d ORDER BY id DESC LIMIT 1', absint( $application_id ) ),
			ARRAY_A
		);
	}

	public static function get( $invoice_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . LNKup_Financial_Tables::invoices_table() . ' WHERE id = %d', absint( $invoice_id ) ),
			ARRAY_A
		);
	}

	/**
	 * Invoices for a vendor or organizer, most recently changed first (an invoice is touched when
	 * it is paid or refunded, so a newly paid older booking moves to the top).
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    vendor|organizer.
	 * @param array  $args    per_page (or legacy limit, max 100), page (1-based).
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_for_user( $user_id, $role = 'vendor', $args = array() ) {
		global $wpdb;
		$table    = LNKup_Financial_Tables::invoices_table();
		$col      = 'vendor' === $role ? 'vendor_user_id' : 'organizer_user_id';
		$per_page = min( 100, max( 1, absint( $args['per_page'] ?? ( $args['limit'] ?? 25 ) ) ) );
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$col} = %d ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);
	}

	public static function count_for_user( $user_id, $role = 'vendor' ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::invoices_table();
		$col   = 'vendor' === $role ? 'vendor_user_id' : 'organizer_user_id';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$col} = %d", $user_id ) );
	}

	public static function format( $row ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'             => (int) $row['id'],
			'invoice_number' => $row['invoice_number'],
			'status'         => $row['status'],
			'total_amount'   => (float) $row['total_amount'],
			'paid_amount'    => (float) $row['paid_amount'],
			'due_date'       => $row['due_date'],
			'event_id'       => (int) $row['event_id'],
			'event_title'    => (int) $row['event_id'] ? get_the_title( (int) $row['event_id'] ) : '',
			'issue_date'     => $row['issue_date'],
			'created_at'     => $row['created_at'],
			'pdf_url'        => rest_url( 'lnkup/v1/financial/invoices/' . (int) $row['id'] . '/pdf' ),
		);
	}

	public static function get_items( $invoice_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . LNKup_Financial_Tables::invoice_items_table() . ' WHERE invoice_id = %d ORDER BY sort_order ASC, id ASC', absint( $invoice_id ) ),
			ARRAY_A
		);
	}

	public static function format_detail( $row ) {
		$detail = self::format( $row );
		$detail['items'] = array_map( function ( $item ) {
			return array(
				'description' => $item['description'],
				'amount'      => (float) $item['line_total'],
			);
		}, self::get_items( (int) $row['id'] ) );
		$meta = ! empty( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : array();
		$detail['verify_url'] = ! empty( $meta['verify_token'] )
			? add_query_arg( 'lnkup_verify_invoice', $meta['verify_token'], home_url( '/' ) )
			: '';
		$detail['pdf_url'] = rest_url( 'lnkup/v1/financial/invoices/' . (int) $row['id'] . '/pdf' );
		return $detail;
	}

	public static function render_html( $invoice ) {
		$items   = self::get_items( (int) $invoice['id'] );
		$event   = (int) $invoice['event_id'] ? get_the_title( (int) $invoice['event_id'] ) : '';
		$vendor  = get_userdata( (int) $invoice['vendor_user_id'] );
		$org     = get_userdata( (int) $invoice['organizer_user_id'] );
		$meta    = ! empty( $invoice['metadata'] ) ? json_decode( $invoice['metadata'], true ) : array();
		$verify  = $meta['verify_token'] ?? '';
		$verify_url = $verify ? add_query_arg( 'lnkup_verify_invoice', $verify, home_url( '/' ) ) : '';

		ob_start();
		?>
		<!DOCTYPE html>
		<html><head><meta charset="utf-8"><title><?php echo esc_html( $invoice['invoice_number'] ); ?></title>
		<style>
			body{font-family:system-ui,-apple-system,sans-serif;color:#111;max-width:720px;margin:2rem auto;padding:1.5rem}
			.header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #a3e635;padding-bottom:1rem}
			.brand{font-size:1.5rem;font-weight:800}
			table{width:100%;border-collapse:collapse;margin:1.5rem 0}
			th,td{padding:.75rem;text-align:left;border-bottom:1px solid #e5e5e5}
			.total{font-size:1.25rem;font-weight:700;text-align:right}
			.qr{margin-top:1rem}
		</style></head><body>
		<div class="header">
			<div><div class="brand">LNKup</div><p>Marketplace Invoice</p></div>
			<div style="text-align:right">
				<strong><?php echo esc_html( $invoice['invoice_number'] ); ?></strong><br>
				<?php echo esc_html( $invoice['issue_date'] ); ?><br>
				Due: <?php echo esc_html( $invoice['due_date'] ); ?>
			</div>
		</div>
		<p><strong>Event:</strong> <?php echo esc_html( $event ); ?></p>
		<p><strong>Vendor:</strong> <?php echo esc_html( $vendor ? $vendor->display_name : '' ); ?></p>
		<p><strong>Organizer:</strong> <?php echo esc_html( $org ? $org->display_name : '' ); ?></p>
		<table><thead><tr><th>Description</th><th>Amount</th></tr></thead><tbody>
		<?php foreach ( $items as $item ) : ?>
			<tr><td><?php echo esc_html( $item['description'] ); ?></td><td><?php echo esc_html( lnkup_currency( $item['line_total'] ) ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<p class="total">Total: <?php echo esc_html( lnkup_currency( $invoice['total_amount'] ) ); ?></p>
		<p>Status: <?php echo esc_html( ucfirst( $invoice['status'] ) ); ?></p>
		<?php if ( $verify_url ) : ?><div class="qr"><p class="lnkup-muted"><?php esc_html_e( 'Verify this invoice at:', 'lnkup-marketplace' ); ?><br><?php echo esc_html( $verify_url ); ?></p></div><?php endif; ?>
		</body></html>
		<?php
		return ob_get_clean();
	}

	public static function email_invoice( $invoice ) {
		if ( is_array( $invoice ) && LNKup_Notifications::delegated( 'invoice_email', array( $invoice ) ) ) {
			return true;
		}
		$vendor = get_userdata( (int) $invoice['vendor_user_id'] );
		if ( ! $vendor ) {
			return false;
		}
		$subject = sprintf( __( 'Invoice %s from LNKup', 'lnkup-marketplace' ), $invoice['invoice_number'] );
		$body    = self::render_html( $invoice );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		return wp_mail( $vendor->user_email, $subject, $body, $headers );
	}

	/**
	 * Find an invoice by its exact verification token. Tokens are 24 alphanumeric characters.
	 *
	 * @param string $token Token from the verify link.
	 * @return array|null
	 */
	public static function verify_by_token( $token ) {
		global $wpdb;
		$token = (string) $token;
		if ( ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $token ) ) {
			return null;
		}
		$table  = LNKup_Financial_Tables::invoices_table();
		$needle = '%' . $wpdb->esc_like( '"verify_token":"' . $token . '"' ) . '%';
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE metadata LIKE %s LIMIT 2", $needle ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			$meta = json_decode( (string) $row['metadata'], true );
			if ( is_array( $meta ) && isset( $meta['verify_token'] ) && hash_equals( (string) $meta['verify_token'], $token ) ) {
				return $row;
			}
		}
		return null;
	}
}
