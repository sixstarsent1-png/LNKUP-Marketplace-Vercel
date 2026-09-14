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
			switch ( $type ) {
				case 'checkout.session.completed':
					self::handle_checkout_completed( $obj );
					break;
				case 'payment_intent.succeeded':
					self::handle_payment_intent_succeeded( $obj );
					break;
				case 'payment_intent.processing':
					self::handle_payment_intent_processing( $obj );
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
				case 'transfer.created':
					LNKup_Stripe_Connect::handle_transfer_created( $obj );
					break;
				case 'transfer.failed':
					LNKup_Stripe_Connect::handle_transfer_failed( $obj );
					break;
				case 'payout.paid':
				case 'payout.created':
				case 'payout.failed':
					LNKup_Stripe_Connect::handle_payout_event( $obj, $type );
					break;
				default:
					break;
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
		$rows  = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE status = 'failed' AND attempts < 5 ORDER BY id ASC LIMIT 10",
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$event = json_decode( $row['payload'], true );
			if ( $event ) {
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
			return;
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
			LNKup_Financial_Service::mark_payment_processing(
				$payment_id,
				$intent_id,
				array(
					'source'          => 'checkout_session',
					'payment_status'  => $payment_status,
				)
			);
			return;
		}

		LNKup_Financial_Service::finalize_payment( $payment_id, $intent_id, array( 'source' => 'checkout_session' ) );
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
			LNKup_Financial_Service::mark_payment_processing(
				$payment_id,
				$intent['id'] ?? '',
				array( 'source' => 'payment_intent.processing' )
			);
		}
	}

	/**
	 * @param array $intent Payment intent.
	 */
	private static function handle_payment_intent_succeeded( $intent ) {
		$payment_id = self::resolve_payment_id( $intent );
		if ( $payment_id ) {
			LNKup_Financial_Service::backfill_stripe_payment_meta( $payment_id, $intent['id'] ?? '' );
			LNKup_Financial_Service::finalize_payment( $payment_id, $intent['id'] ?? '', array( 'source' => 'payment_intent' ) );
		}
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
		if ( $refunded >= (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ) ) {
			update_post_meta( $payment_id, '_lnkup_payment_status', 'refunded' );
		}
		if ( class_exists( 'LNKup_Stripe_Connect' ) ) {
			LNKup_Stripe_Connect::handle_destination_refund( $payment_id, $refunded );
		}
		LNKup_Transactions::sync_extended_fields( $payment_id );
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
