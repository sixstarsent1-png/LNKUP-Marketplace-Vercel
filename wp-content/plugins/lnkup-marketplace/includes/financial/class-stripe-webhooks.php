<?php
/**
 * Stripe webhook event router.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Stripe_Webhooks {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( LNKup_Financial_Cron::HOOK, array( __CLASS__, 'retry_failed' ), 20 );
	}

	public static function register_route() {
		register_rest_route(
			'lnkup/v1',
			'/stripe-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function handle( $request ) {
		return LNKup_Financial_Service::handle_webhook( $request );
	}

	/**
	 * Process stored webhook event.
	 *
	 * @param array $event Stripe event array.
	 * @param int   $log_id Webhook log row ID.
	 */
	public static function process_event( $event, $log_id = 0 ) {
		$type = $event['type'] ?? '';
		$obj  = $event['data']['object'] ?? array();

		try {
			$result = null;
			switch ( $type ) {
				case 'checkout.session.completed':
				case 'checkout.session.async_payment_succeeded':
					$result = self::handle_checkout_completed( $obj );
					break;
				case 'checkout.session.async_payment_failed':
					self::handle_checkout_async_failed( $obj );
					break;
				case 'payment_intent.succeeded':
					$result = self::handle_payment_intent_succeeded( $obj );
					break;
				case 'payment_intent.processing':
					$result = self::handle_payment_intent_processing( $obj );
					break;
				case 'payment_intent.payment_failed':
					self::handle_payment_intent_failed( $obj );
					break;
				case 'charge.refunded':
					self::handle_charge_refunded( $obj );
					break;
				case 'charge.dispute.created':
					self::handle_dispute_created( $obj );
					break;
				case 'customer.subscription.created':
				case 'customer.subscription.updated':
				case 'customer.subscription.deleted':
					self::handle_subscription_event( $obj, $type );
					break;
				case 'account.updated':
					LNKup_Stripe_Connect::handle_account_updated( $obj );
					break;
				case 'account.application.deauthorized':
					LNKup_Stripe_Connect::handle_account_deauthorized( $obj, (string) ( $event['account'] ?? '' ) );
					break;
				case 'transfer.created':
					LNKup_Stripe_Connect::handle_transfer_created( $obj );
					break;
				case 'transfer.reversed':
					LNKup_Stripe_Connect::handle_transfer_reversed( $obj );
					break;
				case 'payout.paid':
				case 'payout.created':
				case 'payout.updated':
				case 'payout.failed':
				case 'payout.canceled':
					LNKup_Stripe_Connect::handle_payout_event( $obj, $type, (string) ( $event['account'] ?? '' ) );
					break;
				case 'charge.dispute.funds_withdrawn':
				case 'charge.dispute.funds_reinstated':
				case 'charge.dispute.closed':
					self::handle_dispute_money( $obj, $type );
					break;
				default:
					break;
			}
			if ( is_wp_error( $result ) ) {
				self::mark_failed( $log_id, $result->get_error_message() );
				return $result;
			}
			self::mark_processed( $log_id );
			return true;
		} catch ( Throwable $e ) {
			self::mark_failed( $log_id, $e->getMessage() );
			return new WP_Error( 'webhook_processing_failed', $e->getMessage() );
		}
	}

	/**
	 * Log webhook payload.
	 *
	 * @param array $event Event data.
	 */
	public static function log_event( $event ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::webhook_events_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}
		$event_id = sanitize_text_field( $event['id'] ?? '' );
		if ( $event_id ) {
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %s", $event_id )
			);
			if ( $exists ) {
				return (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM {$table} WHERE event_id = %s LIMIT 1", $event_id )
				);
			}
		}
		$wpdb->insert(
			$table,
			array(
				'event_id'    => $event_id,
				'event_type'  => sanitize_text_field( $event['type'] ?? '' ),
				'payload'     => wp_json_encode( $event ),
				'status'      => 'pending',
				'attempts'    => 0,
				'created_at'  => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Whether a logged webhook event was already processed.
	 *
	 * @param int $log_id Webhook log row ID.
	 */
	public static function is_event_processed( $log_id ) {
		if ( ! $log_id ) {
			return false;
		}
		global $wpdb;
		$table = LNKup_Financial_Tables::webhook_events_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return false;
		}
		$status = $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $log_id )
		);
		return 'processed' === $status;
	}

	public static function retry_failed() {
		global $wpdb;
		$table = LNKup_Financial_Tables::webhook_events_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return;
		}
		// Pending rows older than 15 minutes were interrupted mid-processing (e.g. a fatal error).
		$stale_before = wp_date( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE attempts < 5 AND ( status = 'failed' OR ( status = 'pending' AND created_at < %s ) ) ORDER BY id ASC LIMIT 10",
				$stale_before
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}
		foreach ( $rows as $row ) {
			if ( 'pending' === $row['status'] ) {
				// Count the attempt up front so an event that keeps crashing stops after 5 tries.
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", (int) $row['id'] ) );
			}
			$event = json_decode( $row['payload'], true );
			if ( is_array( $event ) ) {
				self::process_event( $event, (int) $row['id'] );
			}
		}
	}

	/**
	 * @param array $session Checkout session.
	 */
	private static function handle_checkout_completed( $session ) {
		$payment_id = isset( $session['client_reference_id'] ) ? absint( $session['client_reference_id'] ) : 0;
		if ( ! $payment_id && ! empty( $session['metadata']['payment_post_id'] ) ) {
			$payment_id = absint( $session['metadata']['payment_post_id'] );
		}
		if ( ! $payment_id ) {
			return null;
		}

		$intent_raw = $session['payment_intent'] ?? '';
		$intent_id  = '';
		if ( is_string( $intent_raw ) ) {
			$intent_id = $intent_raw;
		} elseif ( is_array( $intent_raw ) && ! empty( $intent_raw['id'] ) ) {
			$intent_id = (string) $intent_raw['id'];
		}

		if ( $intent_id ) {
			LNKup_Financial_Service::backfill_stripe_payment_meta( $payment_id, $intent_id );
		}

		// ACH / delayed methods: session.completed can fire before funds clear.
		// Only finalize when Stripe reports payment_status=paid; otherwise wait for payment_intent.succeeded.
		$payment_status = isset( $session['payment_status'] ) ? (string) $session['payment_status'] : '';
		if ( 'paid' !== $payment_status ) {
			return LNKup_Financial_Service::mark_payment_processing(
				$payment_id,
				$intent_id,
				array(
					'source'          => 'checkout_session',
					'payment_status'  => $payment_status,
				)
			);
		}

		if ( self::is_short_charge( $payment_id, (string) ( $session['id'] ?? '' ), (int) ( $session['amount_total'] ?? 0 ), (string) ( $session['currency'] ?? 'usd' ) ) ) {
			return null;
		}
		return LNKup_Financial_Service::finalize_payment( $payment_id, $intent_id, array( 'source' => 'checkout_session' ) );
	}

	/**
	 * A succeeded charge that does not cover what this booking expected is flagged, not finalized.
	 * Paid or closed payments skip the check: finalize records those as extra charges.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $ref        PaymentIntent or Checkout Session ID.
	 * @param int    $received   Cents collected.
	 * @param string $currency   Currency.
	 */
	private static function is_short_charge( $payment_id, $ref, $received, $currency ) {
		if ( 'paid' === (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) || ! LNKup_Payments::is_collectible( $payment_id ) ) {
			return false;
		}
		if ( LNKup_Financial_Service::charge_amount_ok( $payment_id, $ref, $received, $currency ) ) {
			return false;
		}
		LNKup_Financial_Service::flag_amount_mismatch( $payment_id, $ref, $received );
		return true;
	}

	/**
	 * Delayed Checkout payment (ACH) failed after session completion.
	 *
	 * @param array $session Checkout session.
	 */
	private static function handle_checkout_async_failed( $session ) {
		$payment_id = absint( $session['client_reference_id'] ?? ( $session['metadata']['payment_post_id'] ?? 0 ) );
		if ( ! $payment_id ) {
			return;
		}
		$intent_id = is_string( $session['payment_intent'] ?? null ) ? $session['payment_intent'] : (string) ( $session['payment_intent']['id'] ?? '' );
		self::handle_payment_intent_failed(
			array(
				'id'       => $intent_id,
				'metadata' => array( 'payment_post_id' => (string) $payment_id ),
			)
		);
	}

	/**
	 * ACH / delayed PaymentIntent — record processing without finalizing.
	 *
	 * @param array $intent Payment intent.
	 */
	private static function handle_payment_intent_processing( $intent ) {
		$payment_id = self::resolve_payment_id( $intent );
		if ( $payment_id ) {
			LNKup_Financial_Service::backfill_stripe_payment_meta( $payment_id, $intent['id'] ?? '' );
			return LNKup_Financial_Service::mark_payment_processing(
				$payment_id,
				$intent['id'] ?? '',
				array( 'source' => 'payment_intent.processing' )
			);
		}
		return null;
	}

	/**
	 * @param array $intent Payment intent.
	 */
	private static function handle_payment_intent_succeeded( $intent ) {
		$payment_id = self::resolve_payment_id( $intent );
		if ( $payment_id ) {
			$intent_id = (string) ( $intent['id'] ?? '' );
			$received  = (int) ( $intent['amount_received'] ?? $intent['amount'] ?? 0 );
			if ( self::is_short_charge( $payment_id, $intent_id, $received, (string) ( $intent['currency'] ?? 'usd' ) ) ) {
				return null;
			}
			return LNKup_Financial_Service::finalize_payment( $payment_id, $intent_id, array( 'source' => 'payment_intent' ) );
		}
		return null;
	}

	/**
	 * Sync Stripe Billing subscription into local subscription row.
	 *
	 * @param array  $subscription Stripe subscription object.
	 * @param string $type         Event type.
	 */
	private static function handle_subscription_event( $subscription, $type ) {
		if ( class_exists( 'LNKup_Subscriptions' ) ) {
			LNKup_Subscriptions::sync_from_stripe_event( $subscription, $type );
		}
		LNKup_Financial_Audit::log(
			'subscription_event',
			'subscription',
			0,
			array(
				'type'         => $type,
				'provider_ref' => sanitize_text_field( $subscription['id'] ?? '' ),
				'status'       => sanitize_key( $subscription['status'] ?? '' ),
			)
		);
	}

	/**
	 * @param array $intent Payment intent.
	 */
	private static function handle_payment_intent_failed( $intent ) {
		$payment_id = self::resolve_payment_id( $intent );
		if ( $payment_id ) {
			// A failed attempt must never overwrite a completed payment (events can arrive out of
			// order, and a retry may succeed on a newer PaymentIntent).
			$status = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
			$stored = (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
			if ( in_array( $status, array( 'paid', 'refunded', 'partially_refunded', 'partially_paid', 'disputed', 'chargeback_lost' ), true ) || ( '' !== $stored && $stored !== (string) ( $intent['id'] ?? '' ) ) ) {
				LNKup_Financial_Audit::log( 'payment_failed_ignored', 'payment', $payment_id, array( 'status' => $status, 'intent_id' => (string) ( $intent['id'] ?? '' ) ) );
				return;
			}
			update_post_meta( $payment_id, '_lnkup_payment_status', 'failed' );
			LNKup_Transactions::record_from_payment_post( $payment_id, 'payment_failed' );
			LNKup_Financial_Activity::record( 'payment_failed', array(
				'user_id' => (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true ),
				'role'    => 'vendor',
				'entity_type' => 'payment',
				'entity_id' => $payment_id,
			) );
		}
	}

	/**
	 * @param array $charge Charge object.
	 */
	private static function handle_charge_refunded( $charge ) {
		$payment_id = self::payment_from_intent( $charge['payment_intent'] ?? '' );
		if ( ! $payment_id ) {
			return;
		}
		$refunded = (float) ( ( $charge['amount_refunded'] ?? 0 ) / 100 );
		update_post_meta( $payment_id, '_lnkup_refund_amount', $refunded );
		LNKup_Refunds::sync_payment_refund_status( $payment_id );
		if ( class_exists( 'LNKup_Stripe_Connect' ) ) {
			LNKup_Stripe_Connect::handle_destination_refund( $payment_id, $refunded );
		}
		LNKup_Transactions::sync_extended_fields( $payment_id );
		do_action( 'lnkup_refund_synced', $payment_id, $refunded );
	}

	/**
	 * Chargeback money movements. Stripe pulls the disputed amount from LNKup when funds are
	 * withdrawn; the organizer's share is reversed only once the dispute is lost, and reinstated
	 * if it is won.
	 *
	 * @param array  $dispute Dispute object.
	 * @param string $type    Event type.
	 */
	private static function handle_dispute_money( $dispute, $type ) {
		$payment_id = self::payment_from_intent( (string) ( $dispute['payment_intent'] ?? '' ) );
		$status     = sanitize_key( (string) ( $dispute['status'] ?? '' ) );
		$amount     = (int) ( $dispute['amount'] ?? 0 );
		LNKup_Financial_Audit::log(
			'dispute_' . str_replace( 'charge.dispute.', '', $type ),
			'payment',
			$payment_id,
			array(
				'dispute_id' => sanitize_text_field( (string) ( $dispute['id'] ?? '' ) ),
				'status'     => $status,
				'amount'     => $amount / 100,
			)
		);
		if ( ! $payment_id ) {
			return;
		}
		if ( 'charge.dispute.funds_withdrawn' === $type ) {
			update_post_meta( $payment_id, '_lnkup_dispute_funds_withdrawn_cents', $amount );
			return;
		}
		if ( 'charge.dispute.funds_reinstated' === $type ) {
			delete_post_meta( $payment_id, '_lnkup_dispute_funds_withdrawn_cents' );
			return;
		}
		if ( 'lost' === $status ) {
			update_post_meta( $payment_id, '_lnkup_payment_status', 'chargeback_lost' );
			LNKup_Organizer_Wallet::reverse_for_chargeback( $payment_id, $amount );
			LNKup_Financial_Disputes::close_chargeback( $payment_id, 'lost' );
		} elseif ( 'won' === $status ) {
			if ( in_array( (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ), array( 'disputed', 'chargeback_lost' ), true ) ) {
				update_post_meta( $payment_id, '_lnkup_payment_status', 'paid' );
			}
			LNKup_Organizer_Wallet::reinstate_chargeback( $payment_id );
			LNKup_Financial_Disputes::close_chargeback( $payment_id, 'won' );
		}
	}

	/**
	 * @param array $dispute Dispute object.
	 */
	private static function handle_dispute_created( $dispute ) {
		$payment_id = self::payment_from_intent( $dispute['payment_intent'] ?? '' );
		if ( $payment_id ) {
			LNKup_Financial_Disputes::open_from_chargeback( $payment_id, $dispute['reason'] ?? '' );
		}
	}

	/**
	 * @param array $obj Stripe object with metadata.
	 */
	private static function resolve_payment_id( $obj ) {
		if ( ! empty( $obj['metadata']['payment_post_id'] ) ) {
			return absint( $obj['metadata']['payment_post_id'] );
		}
		return self::payment_from_intent( $obj['id'] ?? ( $obj['payment_intent'] ?? '' ) );
	}

	/**
	 * @param string $intent_id Intent ID.
	 */
	private static function payment_from_intent( $intent_id ) {
		if ( ! $intent_id ) {
			return 0;
		}
		$posts = get_posts( array(
			'post_type'      => 'lnkup_payment',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_lnkup_stripe_payment_intent',
			'meta_value'     => $intent_id,
		) );
		return $posts[0] ?? 0;
	}

	/**
	 * @param int $log_id Log ID.
	 */
	private static function mark_processed( $log_id ) {
		if ( ! $log_id ) {
			return;
		}
		global $wpdb;
		$wpdb->update(
			LNKup_Financial_Tables::webhook_events_table(),
			array(
				'status'       => 'processed',
				'processed_at' => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $log_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param int    $log_id Log ID.
	 * @param string $error  Error message.
	 */
	private static function mark_failed( $log_id, $error ) {
		if ( ! $log_id ) {
			return;
		}
		global $wpdb;
		$table = LNKup_Financial_Tables::webhook_events_table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', attempts = attempts + 1, error_message = %s, updated_at = %s WHERE id = %d",
				sanitize_text_field( $error ),
				current_time( 'mysql' ),
				$log_id
			)
		);
	}
}
