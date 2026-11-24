<?php
/**
 * Receipt generation.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Receipts {

	public static function generate_number() {
		return 'RCP-' . strtoupper( wp_generate_password( 8, false, false ) );
	}

	public static function issue_for_payment( $payment_id ) {
		$existing = self::get_by_payment( $payment_id );
		if ( $existing ) {
			return (int) $existing['id'];
		}
		$txn_id = LNKup_Transactions::record_from_payment_post( $payment_id, 'payment_completed' );
		global $wpdb;
		$table = LNKup_Financial_Tables::receipts_table();
		$now   = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'receipt_number'    => self::generate_number(),
				'transaction_id'  => $txn_id,
				'payment_post_id' => $payment_id,
				'vendor_user_id'    => (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ),
				'organizer_user_id' => (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true ),
				'amount'            => (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ),
				'payment_method'    => LNKup_Payment_Provider::get_active_provider_id(),
				'status'            => 'issued',
				'metadata'          => wp_json_encode( array(
					'event_id'       => (int) get_post_meta( $payment_id, '_lnkup_event_id', true ),
					'application_id' => (int) get_post_meta( $payment_id, '_lnkup_application_id', true ),
				) ),
				'created_at'        => $now,
			)
		);
		$receipt_id = (int) $wpdb->insert_id;
		LNKup_Financial_Audit::log( 'receipt_issued', 'receipt', $receipt_id, array(
			'amount' => (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ),
		) );
		do_action( 'lnkup_receipt_issued', $receipt_id, $payment_id );
		return $receipt_id;
	}

	public static function get_by_payment( $payment_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . LNKup_Financial_Tables::receipts_table() . ' WHERE payment_post_id = %d LIMIT 1', absint( $payment_id ) ),
			ARRAY_A
		);
	}

	public static function list_for_user( $user_id, $limit = 25 ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::receipts_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE vendor_user_id = %d ORDER BY created_at DESC LIMIT %d",
				$user_id,
				min( 50, max( 1, absint( $limit ) ) )
			),
			ARRAY_A
		);
	}

	public static function format( $row ) {
		if ( ! $row ) {
			return null;
		}
		$meta     = ! empty( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : array();
		$meta     = is_array( $meta ) ? $meta : array();
		$event_id = (int) ( $meta['event_id'] ?? 0 );
		$app_id   = (int) ( $meta['application_id'] ?? 0 );
		$context  = self::event_context( $event_id, $app_id );
		$pay_id   = (int) $row['payment_post_id'];
		$pay      = '';
		if ( $pay_id ) {
			$pay = 'lnkup_payment' === get_post_type( $pay_id ) ? (string) get_post_meta( $pay_id, '_lnkup_payment_status', true ) : 'removed';
		}
		return array(
			'id'              => (int) $row['id'],
			'receipt_number'  => $row['receipt_number'],
			'amount'          => (float) $row['amount'],
			'payment_method'  => $row['payment_method'],
			'status'          => $row['status'],
			'document_status' => (string) $row['status'],
			// A receipt is only issued for a completed payment; refunds and disputes come from the payment record.
			'payment_status'  => '' !== $pay && 'removed' !== $pay ? $pay : 'paid',
			'payment_post_id' => $pay_id,
			'application_id'  => $app_id,
			'created_at'      => $row['created_at'],
			'event_id'        => $context['event_id'],
			'event_title'     => $context['title'],
			'event_date'      => $context['date'],
			'place'           => $context['place'],
			'organizer_name'  => $context['organizer_name'],
			'event_resolution'=> $context['source'],
			'view_url'        => LNKup_Signed_Links::sign_url( rest_url( 'lnkup/v1/financial/receipts/' . (int) $row['id'] . '/view' ), 'receipt', (int) $row['id'], (int) $row['vendor_user_id'] ),
			'pdf_available'   => class_exists( '\Dompdf\Dompdf' ),
			'metadata'        => $meta,
		);
	}

	/**
	 * Event identity for a financial document: live event, the application's resolved event, then its snapshot.
	 *
	 * @param int $event_id Event recorded on the document.
	 * @param int $app_id   Application recorded on the document.
	 * @return array{event_id:int,title:string,date:string,place:string,organizer_name:string,source:string}
	 */
	public static function event_context( $event_id, $app_id ) {
		$event_id = (int) $event_id;
		$app_id   = (int) $app_id;
		$snapshot = array();
		$source   = 'live';
		if ( ( ! $event_id || 'lnkup_event' !== get_post_type( $event_id ) ) && $app_id && class_exists( 'LNKup_Vendor_Booking_Contract' ) ) {
			$resolved = LNKup_Vendor_Booking_Contract::resolve_event( $app_id );
			$event_id = (int) $resolved['event_id'];
			$snapshot = $resolved['snapshot'];
			$source   = $resolved['source'];
		}
		if ( $event_id && 'lnkup_event' === get_post_type( $event_id ) ) {
			return array(
				'event_id'       => $event_id,
				'title'          => class_exists( 'LNKup_Vendor_Presentation' ) ? LNKup_Vendor_Presentation::customer_event_title( $event_id ) : lnkup_plain_title( $event_id ),
				'date'           => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
				'place'          => function_exists( 'lnkup_display_place' ) ? lnkup_display_place( (string) get_post_meta( $event_id, '_lnkup_city', true ), (string) get_post_meta( $event_id, '_lnkup_state', true ) ) : '',
				'organizer_name' => class_exists( 'LNKup_Vendor_Booking_Contract' ) ? LNKup_Vendor_Booking_Contract::organizer_name( $event_id ) : '',
				'source'         => $source,
			);
		}
		if ( $snapshot ) {
			return array(
				'event_id'       => 0,
				'title'          => (string) ( $snapshot['title'] ?? '' ),
				'date'           => (string) ( $snapshot['date'] ?? '' ),
				'place'          => function_exists( 'lnkup_display_place' ) ? lnkup_display_place( (string) ( $snapshot['city'] ?? '' ), (string) ( $snapshot['state'] ?? '' ) ) : '',
				'organizer_name' => (string) ( $snapshot['organizer_name'] ?? '' ),
				'source'         => 'snapshot',
			);
		}
		return array(
			'event_id'       => 0,
			'title'          => '',
			'date'           => '',
			'place'          => '',
			'organizer_name' => '',
			'source'         => $app_id || $event_id ? 'unrecoverable' : 'none',
		);
	}

	/**
	 * Printable receipt page.
	 *
	 * @param array $row Receipt row.
	 */
	public static function render_html( $row ) {
		$formatted = self::format( $row );
		$context   = self::document_context( $row, $formatted );
		$money     = function_exists( 'lnkup_currency' ) ? 'lnkup_currency' : null;
		$format_money = function ( $amount ) use ( $money ) {
			return $money ? $money( $amount ) : (string) $amount;
		};
		ob_start();
		?>
		<!DOCTYPE html>
		<html lang="en"><head><meta charset="utf-8"><title><?php echo esc_html( $formatted['receipt_number'] ); ?></title>
		<style>
			*{box-sizing:border-box}
			body{font-family:Inter,system-ui,-apple-system,sans-serif;color:#141414;background:#f6f5f1;margin:0;padding:32px 16px;font-size:14px;line-height:1.5}
			.sheet{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e7e5df;border-radius:14px;overflow:hidden}
			.bar{display:flex;justify-content:space-between;align-items:center;padding:20px 32px;background:#121212;color:#fff}
			.brand{font-weight:800;font-size:18px;letter-spacing:.02em}
			.brand span{color:#b7f04a}
			.paid{display:inline-block;padding:4px 12px;border-radius:999px;background:#b7f04a;color:#141414;font-size:12px;font-weight:700}
			.body{padding:32px}
			.eyebrow{margin:0;color:#62626b;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase}
			h1{font-size:24px;line-height:1.25;margin:6px 0 4px}
			.meta{margin:0;color:#3f3f46}
			.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 24px;margin:24px 0;padding:16px 0;border-top:1px solid #e7e5df;border-bottom:1px solid #e7e5df}
			.grid dt{color:#62626b;font-size:12px}
			.grid dd{margin:2px 0 0;font-weight:600}
			table{width:100%;border-collapse:collapse}
			th,td{text-align:left;padding:10px 0;border-bottom:1px solid #eee;vertical-align:top}
			thead th{color:#62626b;font-size:12px;font-weight:600}
			td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
			tfoot td,tfoot th{border-bottom:0;border-top:2px solid #141414;font-size:16px;font-weight:700}
			.muted{color:#62626b;font-size:12px}
			.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:24px}
			.actions a{display:inline-block;padding:8px 14px;border:1px solid #d6d3cb;border-radius:10px;color:#141414;font-weight:600;text-decoration:none}
			.actions a.primary{background:#b7f04a;border-color:#b7f04a}
			.foot{padding:16px 32px;background:#faf9f6;color:#62626b;font-size:12px}
			@media (max-width:560px){.grid{grid-template-columns:1fr}.body,.bar,.foot{padding-left:20px;padding-right:20px}}
			@media print { body{background:#fff;padding:0} .actions{display:none} .sheet{border:0;border-radius:0} .bar{background:#fff;color:#141414;border-bottom:2px solid #141414} }
		</style></head><body>
		<article class="sheet">
			<header class="bar">
				<span class="brand">LNK<span>up</span></span>
				<span class="paid"><?php echo esc_html( 'issued' === ( $formatted['status'] ?? '' ) || '' === (string) ( $formatted['status'] ?? '' ) ? __( 'Paid', 'lnkup-marketplace' ) : ucwords( str_replace( '_', ' ', (string) $formatted['status'] ) ) ); ?></span>
			</header>
			<div class="body">
				<p class="eyebrow"><?php esc_html_e( 'Payment receipt', 'lnkup-marketplace' ); ?></p>
				<h1><?php echo esc_html( $context['heading'] ); ?></h1>
				<?php if ( $context['lines'] ) : ?>
					<p class="meta"><?php echo esc_html( implode( ' · ', $context['lines'] ) ); ?></p>
				<?php endif; ?>
				<dl class="grid">
					<div><dt><?php esc_html_e( 'Receipt number', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( $formatted['receipt_number'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Date paid', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( mysql2date( 'M j, Y', $formatted['created_at'] ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Paid to', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( $context['payee'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Payment method', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( $context['method'] ); ?></dd></div>
					<?php if ( '' !== $context['booth'] ) : ?>
						<div><dt><?php esc_html_e( 'Booth', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( $context['booth'] ); ?></dd></div>
					<?php endif; ?>
					<?php if ( '' !== $context['reference'] ) : ?>
						<div><dt><?php esc_html_e( 'Transaction reference', 'lnkup-marketplace' ); ?></dt><dd><?php echo esc_html( $context['reference'] ); ?></dd></div>
					<?php endif; ?>
				</dl>
				<table>
					<thead><tr><th><?php esc_html_e( 'Description', 'lnkup-marketplace' ); ?></th><th class="num"><?php esc_html_e( 'Amount', 'lnkup-marketplace' ); ?></th></tr></thead>
					<tbody>
					<?php if ( $context['items'] ) : ?>
						<?php foreach ( $context['items'] as $item ) : ?>
							<tr><td><?php echo esc_html( $item['description'] ); ?></td><td class="num"><?php echo esc_html( $format_money( $item['amount'] ) ); ?></td></tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td><?php esc_html_e( 'Recorded payment total', 'lnkup-marketplace' ); ?><br><span class="muted"><?php esc_html_e( 'Itemized fees were not stored for this payment.', 'lnkup-marketplace' ); ?></span></td><td class="num"><?php echo esc_html( $format_money( $formatted['amount'] ) ); ?></td></tr>
					<?php endif; ?>
					</tbody>
					<tfoot><tr><th><?php esc_html_e( 'Total paid (USD)', 'lnkup-marketplace' ); ?></th><td class="num"><?php echo esc_html( $format_money( $formatted['amount'] ) ); ?></td></tr></tfoot>
				</table>
				<p class="muted"><?php esc_html_e( 'This receipt shows the amount recorded when the payment was completed. It is not recalculated with today’s fees.', 'lnkup-marketplace' ); ?></p>
				<p class="actions">
					<a class="primary" href="javascript:window.print()"><?php esc_html_e( 'Print', 'lnkup-marketplace' ); ?></a>
					<?php if ( ! empty( $context['pdf_url'] ) ) : ?>
						<a href="<?php echo esc_url( $context['pdf_url'] ); ?>"><?php esc_html_e( 'Download PDF', 'lnkup-marketplace' ); ?></a>
					<?php endif; ?>
					<?php if ( ! empty( $context['booking_url'] ) ) : ?>
						<a href="<?php echo esc_url( $context['booking_url'] ); ?>"><?php esc_html_e( 'View booking', 'lnkup-marketplace' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
			<footer class="foot"><?php esc_html_e( 'Questions about this payment? Contact the organizer from your booking, or reach LNKup support from Help in your dashboard.', 'lnkup-marketplace' ); ?></footer>
		</article>
		</body></html>
		<?php
		return ob_get_clean();
	}

	/**
	 * PDF binary for a receipt, using the same HTML snapshot.
	 *
	 * @param array $row Receipt row.
	 * @return array|WP_Error
	 */
	public static function render_pdf( $row ) {
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			return new WP_Error( 'lnkup_pdf_unavailable', __( 'PDF library is not installed.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		try {
			$dompdf = new \Dompdf\Dompdf( array( 'isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true ) );
			$dompdf->loadHtml( self::render_html( $row ) );
			$dompdf->setPaper( 'letter', 'portrait' );
			$dompdf->render();
			$formatted = self::format( $row );
			return array(
				'binary'   => $dompdf->output(),
				'filename' => sanitize_file_name( ( $formatted['receipt_number'] ?? 'receipt' ) . '.pdf' ),
			);
		} catch ( Throwable $e ) {
			return new WP_Error( 'lnkup_pdf_failed', __( 'Could not generate the receipt PDF.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * Event, place, vendor, and invoice lines already stored.
	 *
	 * @param array $row       Receipt row.
	 * @param array $formatted Formatted receipt.
	 * @return array{heading:string,lines:array<int,string>,items:array<int,array{description:string,amount:float}>}
	 */
	private static function document_context( $row, $formatted ) {
		$heading  = (string) ( $formatted['event_title'] ?? '' );
		if ( '' === $heading || __( 'Event details unavailable', 'lnkup-marketplace' ) === $heading ) {
			$heading = __( 'Payment receipt', 'lnkup-marketplace' );
		}
		$lines = array();
		$date  = (string) ( $formatted['event_date'] ?? '' );
		$place = (string) ( $formatted['place'] ?? '' );
		if ( $date ) {
			$lines[] = mysql2date( 'M j, Y', $date );
		}
		if ( $place ) {
			$lines[] = $place;
		}
		$vendor_id = (int) ( $row['vendor_user_id'] ?? 0 );
		if ( $vendor_id && function_exists( 'lnkup_get_vendor_profile_id' ) ) {
			$profile_id = (int) lnkup_get_vendor_profile_id( $vendor_id );
			$name       = $profile_id ? (string) get_post_meta( $profile_id, '_lnkup_business_name', true ) : '';
			if ( '' !== $name ) {
				$lines[] = $name;
			}
		}
		$items = array();
		$payment_id = (int) ( $formatted['payment_post_id'] ?? 0 );
		if ( $payment_id && class_exists( 'LNKup_Invoices' ) ) {
			$invoice = LNKup_Invoices::get_by_payment( $payment_id );
			if ( is_array( $invoice ) && ! empty( $invoice['id'] ) ) {
				foreach ( (array) LNKup_Invoices::get_items( (int) $invoice['id'] ) as $item ) {
					$items[] = array(
						'description' => (string) ( $item['description'] ?? '' ),
						'amount'      => (float) ( $item['line_total'] ?? 0 ),
					);
				}
			}
		}
		$pdf_url = '';
		if ( class_exists( 'LNKup_Signed_Links' ) && class_exists( '\Dompdf\Dompdf' ) && ! empty( $row['id'] ) ) {
			$pdf_url = LNKup_Signed_Links::sign_url( rest_url( 'lnkup/v1/financial/receipts/' . (int) $row['id'] . '/pdf' ), 'receipt', (int) $row['id'], (int) ( $row['vendor_user_id'] ?? 0 ) );
		}
		$organizer_id = (int) ( $row['organizer_user_id'] ?? 0 );
		$organizer    = $organizer_id ? (string) get_user_meta( $organizer_id, '_lnkup_organizer_public_name', true ) : '';
		if ( '' === $organizer && $organizer_id ) {
			$org_user  = get_userdata( $organizer_id );
			$organizer = $org_user ? (string) $org_user->display_name : '';
		}
		$payee = '' !== $organizer
			/* translators: %s: organizer name */
			? sprintf( __( '%s (collected through LNKup)', 'lnkup-marketplace' ), $organizer )
			: __( 'LNKup', 'lnkup-marketplace' );

		$provider = strtolower( trim( (string) ( $formatted['payment_method'] ?? '' ) ) );
		$methods  = array(
			'stripe' => __( 'Card, processed by Stripe', 'lnkup-marketplace' ),
			'manual' => __( 'Recorded by LNKup', 'lnkup-marketplace' ),
			'free'   => __( 'No payment required', 'lnkup-marketplace' ),
		);
		$method = $methods[ $provider ] ?? ( '' !== $provider ? ucwords( str_replace( '_', ' ', $provider ) ) : __( 'Payment method on file', 'lnkup-marketplace' ) );

		$app_id = (int) ( $formatted['metadata']['application_id'] ?? 0 );
		$opp_id = $app_id ? (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true ) : 0;

		$reference = '';
		if ( ! empty( $row['transaction_id'] ) && class_exists( 'LNKup_Financial_Tables' ) ) {
			global $wpdb;
			$reference = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( 'SELECT public_id FROM ' . LNKup_Financial_Tables::transactions_table() . ' WHERE id = %d', (int) $row['transaction_id'] ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		return array(
			'heading'     => $heading,
			'lines'       => $lines,
			'items'       => $items,
			'pdf_url'     => $pdf_url,
			'payee'       => $payee,
			'method'      => $method,
			'booth'       => $opp_id && function_exists( 'lnkup_plain_title' ) ? lnkup_plain_title( $opp_id ) : '',
			'reference'   => $reference,
			'booking_url' => $app_id ? add_query_arg( array( 'view' => 'applications', 'app' => $app_id ), lnkup_page_url_helper( 'vendor-dashboard' ) ) : '',
		);
	}
}
