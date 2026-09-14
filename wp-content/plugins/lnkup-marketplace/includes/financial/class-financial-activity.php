<?php
/**
 * Unified financial activity feed.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Activity {

	/**
	 * Record activity event.
	 *
	 * @param string $type    Event type slug.
	 * @param array  $context user_id, role, entity_type, entity_id, amount, message, metadata.
	 */
	public static function record( $type, $context = array() ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::financial_activity_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'activity_type'   => sanitize_key( $type ),
				'user_id'         => absint( $context['user_id'] ?? get_current_user_id() ),
				'role_type'       => sanitize_key( $context['role'] ?? 'vendor' ),
				'entity_type'     => sanitize_key( $context['entity_type'] ?? '' ),
				'entity_id'       => absint( $context['entity_id'] ?? 0 ),
				'amount'          => isset( $context['amount'] ) ? (float) $context['amount'] : null,
				'message'         => sanitize_text_field( $context['message'] ?? self::default_message( $type, $context ) ),
				'metadata'        => ! empty( $context['metadata'] ) ? wp_json_encode( $context['metadata'] ) : null,
				'created_at'      => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * List activity for user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    vendor|organizer.
	 * @param int    $limit   Limit.
	 */
	public static function list_for_user( $user_id, $role = 'vendor', $limit = 25 ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::financial_activity_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND role_type = %s ORDER BY created_at DESC LIMIT %d",
				absint( $user_id ),
				sanitize_key( $role ),
				min( 100, max( 1, absint( $limit ) ) )
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map( array( __CLASS__, 'format' ), $rows );
	}

	/**
	 * @param array $row DB row.
	 */
	public static function format( $row ) {
		$meta = ! empty( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : array();
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$type   = (string) ( $row['activity_type'] ?? '' );
		$status = sanitize_key( (string) ( $meta['status'] ?? '' ) );
		if ( ! $status ) {
			if ( in_array( $type, array( 'payment_settled', 'payment_received', 'refund_completed', 'organizer_paid', 'packet_unlocked', 'receipt_generated' ), true ) ) {
				$status = 'completed';
			} elseif ( in_array( $type, array( 'invoice_created', 'refund_requested', 'reminder_sent' ), true ) ) {
				$status = 'pending';
			}
		}

		$event_id   = absint( $meta['event_id'] ?? 0 );
		$event_name = '';
		if ( ! $event_id && 'payment' === ( $row['entity_type'] ?? '' ) && ! empty( $row['entity_id'] ) ) {
			$event_id = absint( get_post_meta( (int) $row['entity_id'], '_lnkup_event_id', true ) );
		}
		if ( $event_id ) {
			$event_name = get_the_title( $event_id );
		}

		$label = ! empty( $meta['label'] ) ? (string) $meta['label'] : (string) ( $row['message'] ?? '' );
		if ( 'payment_settled' === $type && ( ! $label || false !== stripos( $label, 'destination' ) || false !== stripos( $label, 'organizer net' ) ) ) {
			$label = __( 'Booth payment settled', 'lnkup-marketplace' );
		}

		$amount = ( null !== $row['amount'] && '' !== $row['amount'] ) ? (float) $row['amount'] : null;
		if ( ( null === $amount || $amount <= 0 ) && ! empty( $row['message'] ) && preg_match( '/\$\s*([0-9]+(?:\.[0-9]{1,2})?)/', (string) $row['message'], $m ) ) {
			$amount = (float) $m[1];
		}

		return array(
			'id'          => (int) $row['id'],
			'type'        => $type,
			'label'       => $label,
			'message'     => $row['message'],
			'amount'      => $amount,
			'status'      => $status ?: null,
			'event_id'    => $event_id ?: null,
			'event_name'  => $event_name ?: null,
			'entity_type' => $row['entity_type'],
			'entity_id'   => (int) $row['entity_id'],
			'metadata'    => $meta,
			'created_at'  => $row['created_at'],
		);
	}

	/**
	 * @param string $type    Type slug.
	 * @param array  $context Context.
	 */
	private static function default_message( $type, $context ) {
		$messages = array(
			'invoice_created'    => __( 'Invoice created', 'lnkup-marketplace' ),
			'payment_received'   => __( 'Payment received', 'lnkup-marketplace' ),
			'refund_requested'   => __( 'Refund requested', 'lnkup-marketplace' ),
			'refund_completed'   => __( 'Refund completed', 'lnkup-marketplace' ),
			'packet_unlocked'    => __( 'Packet unlocked', 'lnkup-marketplace' ),
			'receipt_generated'  => __( 'Receipt generated', 'lnkup-marketplace' ),
			'organizer_paid'     => __( 'Organizer payout recorded', 'lnkup-marketplace' ),
			'reminder_sent'      => __( 'Payment reminder sent', 'lnkup-marketplace' ),
		);
		return $messages[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );
	}

	public static function init_hooks() {
		add_action( 'lnkup_invoice_created', function ( $payment_id, $invoice_id ) {
			$vendor_id = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
			$org_id    = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
			$amount    = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
			self::record( 'invoice_created', array( 'user_id' => $vendor_id, 'role' => 'vendor', 'entity_type' => 'invoice', 'entity_id' => $invoice_id, 'amount' => $amount ) );
			self::record( 'invoice_created', array( 'user_id' => $org_id, 'role' => 'organizer', 'entity_type' => 'invoice', 'entity_id' => $invoice_id, 'amount' => $amount ) );
		}, 10, 2 );
		add_action( 'lnkup_payment_completed', function ( $payment_id ) {
			$vendor_id = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
			$org_id    = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
			$amount    = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
			self::record( 'payment_received', array( 'user_id' => $vendor_id, 'role' => 'vendor', 'entity_type' => 'payment', 'entity_id' => $payment_id, 'amount' => $amount ) );
			self::record( 'payment_received', array( 'user_id' => $org_id, 'role' => 'organizer', 'entity_type' => 'payment', 'entity_id' => $payment_id, 'amount' => $amount ) );
		} );
		add_action( 'lnkup_refund_completed', function ( $refund_id ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Financial_Tables::refunds_table() . ' WHERE id = %d', $refund_id ), ARRAY_A );
			if ( $row ) {
				self::record( 'refund_completed', array( 'user_id' => (int) $row['vendor_user_id'], 'role' => 'vendor', 'entity_type' => 'refund', 'entity_id' => $refund_id, 'amount' => (float) $row['amount'] ) );
			}
		} );
	}
}
