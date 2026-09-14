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
			) );
			$payment_id = $payments ? (int) $payments[0] : 0;
		}
		if ( ! $payment_id ) {
			return;
		}
		$invoice_id = self::create_from_payment( $payment_id );
		if ( $invoice_id ) {
			do_action( 'lnkup_invoice_created', $invoice_id, $payment_id );
			$invoice = self::get( $invoice_id );
			if ( $invoice ) {
				self::email_invoice( $invoice );
			}
		}
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
			if ( $existing ) {
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
		self::add_item( $invoice_id, __( 'Vendor spot fee', 'lnkup-marketplace' ), (float) get_post_meta( $payment_id, '_lnkup_vendor_spot_fee', true ) );
		self::add_item( $invoice_id, __( 'LNKup platform fee', 'lnkup-marketplace' ), (float) get_post_meta( $payment_id, '_lnkup_lnkup_fee', true ) );
		self::add_item( $invoice_id, __( 'Processing fee', 'lnkup-marketplace' ), (float) get_post_meta( $payment_id, '_lnkup_processing_fee', true ) );
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

	public static function list_for_user( $user_id, $role = 'vendor', $args = array() ) {
		global $wpdb;
		$table  = LNKup_Financial_Tables::invoices_table();
		$col    = 'vendor' === $role ? 'vendor_user_id' : 'organizer_user_id';
		$limit  = min( 50, max( 1, absint( $args['limit'] ?? 25 ) ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$col} = %d ORDER BY created_at DESC LIMIT %d",
				$user_id,
				$limit
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
		$qr_url  = $verify ? 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . rawurlencode( add_query_arg( 'lnkup_verify_invoice', $verify, home_url( '/' ) ) ) : '';

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
		<?php if ( $qr_url ) : ?><div class="qr"><img src="<?php echo esc_url( $qr_url ); ?>" alt="Verify" width="120" height="120"><p class="lnkup-muted">Scan to verify invoice</p></div><?php endif; ?>
		</body></html>
		<?php
		return ob_get_clean();
	}

	public static function email_invoice( $invoice ) {
		$vendor = get_userdata( (int) $invoice['vendor_user_id'] );
		if ( ! $vendor ) {
			return false;
		}
		$subject = sprintf( __( 'Invoice %s from LNKup', 'lnkup-marketplace' ), $invoice['invoice_number'] );
		$body    = self::render_html( $invoice );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		return wp_mail( $vendor->user_email, $subject, $body, $headers );
	}

	public static function verify_by_token( $token ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::invoices_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE metadata LIKE '%" . esc_sql( $token ) . "%' LIMIT 1", ARRAY_A );
		return $rows[0] ?? null;
	}
}
