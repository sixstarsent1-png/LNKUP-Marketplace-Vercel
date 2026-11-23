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
		return array(
			'id'              => (int) $row['id'],
			'receipt_number'  => $row['receipt_number'],
			'amount'          => (float) $row['amount'],
			'payment_method'  => $row['payment_method'],
			'status'          => $row['status'],
			'payment_post_id' => (int) $row['payment_post_id'],
			'created_at'      => $row['created_at'],
			'event_title'     => $event_id ? lnkup_plain_title( $event_id ) : '',
			'view_url'        => LNKup_Signed_Links::sign_url( rest_url( 'lnkup/v1/financial/receipts/' . (int) $row['id'] . '/view' ), 'receipt', (int) $row['id'], (int) $row['vendor_user_id'] ),
			'metadata'        => $meta,
		);
	}

	/**
	 * Printable receipt page.
	 *
	 * @param array $row Receipt row.
	 */
	public static function render_html( $row ) {
		$formatted = self::format( $row );
		$title     = $formatted['event_title'] ? $formatted['event_title'] : $formatted['receipt_number'];
		ob_start();
		?>
		<!DOCTYPE html>
		<html><head><meta charset="utf-8"><title><?php echo esc_html( $formatted['receipt_number'] ); ?></title>
		<style>
			body{font-family:system-ui,-apple-system,sans-serif;color:#171717;max-width:640px;margin:2rem auto;padding:1.5rem}
			h1{font-size:1.5rem;margin:0 0 .25rem}
			p{margin:.35rem 0;color:#444}
			.total{margin-top:1.5rem;font-size:1.25rem;font-weight:700;color:#171717}
		</style></head><body>
		<p>LNKup receipt</p>
		<h1><?php echo esc_html( $title ); ?></h1>
		<p><?php echo esc_html( $formatted['receipt_number'] ); ?></p>
		<p><?php echo esc_html( $formatted['payment_method'] ); ?> · <?php echo esc_html( mysql2date( 'M j, Y', $formatted['created_at'] ) ); ?></p>
		<p class="total"><?php echo esc_html( function_exists( 'lnkup_currency' ) ? lnkup_currency( $formatted['amount'] ) : (string) $formatted['amount'] ); ?></p>
		</body></html>
		<?php
		return ob_get_clean();
	}
}
