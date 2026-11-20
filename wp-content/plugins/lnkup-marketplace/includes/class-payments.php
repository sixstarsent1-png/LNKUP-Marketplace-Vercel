<?php
/**
 * Payment records and fee logic.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Payments {

	/**
	 * Create payment from approved application.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function create_from_application( $app_id ) {
		global $wpdb;
		$lock = 'lnkup_payment_app_' . absint( $app_id );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 0;
		}
		try {
			return self::create_from_application_locked( $app_id );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Body of create_from_application; caller holds the per-application lock so the
	 * existing-payment check and insert cannot interleave.
	 *
	 * @param int $app_id Application ID.
	 */
	private static function create_from_application_locked( $app_id ) {
		$existing = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_application_id',
				'meta_value'     => $app_id,
				'fields'         => 'ids',
			)
		);
		if ( $existing ) {
			$payment_id = (int) $existing[0];
			$app_id_int = (int) $app_id;
			if ( $app_id_int && ! LNKup_Invoices::get_by_application( $app_id_int ) ) {
				$invoice_id = LNKup_Invoices::create_from_payment( $payment_id );
				if ( $invoice_id ) {
					do_action( 'lnkup_invoice_created', $payment_id, $invoice_id );
				}
			}
			return $payment_id;
		}

		$opp_id       = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$vendor_fee   = (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true );
		$organizer_id = (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true );
		$event_id     = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$category     = '';
		$vendor_user  = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$profile_id   = (int) lnkup_get_vendor_profile_id( $vendor_user );
		if ( $profile_id ) {
			$terms = wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'slugs' ) );
			$category = is_array( $terms ) && $terms ? $terms[0] : '';
		}
		$fees = LNKup_Fee_Engine::calculate( array(
			'vendor_fee'   => $vendor_fee,
			'organizer_id' => $organizer_id,
			'event_id'     => $event_id,
			'category'     => $category,
		) );

		$payment_id = wp_insert_post(
			array(
				'post_title'  => sprintf( __( 'Payment for Application #%d', 'lnkup-marketplace' ), $app_id ),
				'post_type'   => 'lnkup_payment',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $payment_id, '_lnkup_application_id', $app_id );
		update_post_meta( $payment_id, '_lnkup_vendor_user_id', (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ) );
		update_post_meta( $payment_id, '_lnkup_organizer_user_id', (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true ) );
		update_post_meta( $payment_id, '_lnkup_event_id', (int) get_post_meta( $app_id, '_lnkup_event_id', true ) );
		update_post_meta( $payment_id, '_lnkup_opportunity_id', $opp_id );
		update_post_meta( $payment_id, '_lnkup_vendor_spot_fee', $vendor_fee );
		update_post_meta( $payment_id, '_lnkup_payment_status', 'pending' );
		self::apply_fee_breakdown_to_payment( $payment_id, $fees );

		$total = (float) ( $fees['total'] ?? 0 );

		LNKup_Transactions::record_from_payment_post( $payment_id, 'payment_created' );
		$invoice_id = LNKup_Invoices::create_from_payment( $payment_id );
		if ( $invoice_id ) {
			do_action( 'lnkup_invoice_created', $payment_id, $invoice_id );
		}
		do_action( 'lnkup_application_approved', $app_id );

		$app_id_for_fin = (int) get_post_meta( $payment_id, '_lnkup_application_id', true );
		if ( $app_id_for_fin ) {
			$opp_deposit = get_post_meta( $opp_id, '_lnkup_deposit_required', true );
			if ( $opp_deposit ) {
				LNKup_Deposits::create_for_application( $app_id_for_fin );
			}
			$plan_type = get_post_meta( $opp_id, '_lnkup_installment_plan', true );
			if ( $plan_type && 'full' !== $plan_type ) {
				LNKup_Installments::create_plan( $app_id_for_fin, $plan_type, $total );
			}
			$deposit_amt = LNKup_Deposits::checkout_amount( $app_id_for_fin );
			if ( $deposit_amt > 0 ) {
				update_post_meta( $payment_id, '_lnkup_installment_amount', $deposit_amt );
			} else {
				LNKup_Installments::apply_to_payment( $payment_id, $app_id_for_fin );
			}
		}

		if ( LNKup_Financial_Service::get_checkout_amount( $payment_id ) > 0 ) {
			LNKup_Notifications::send_payment_required( $app_id, $payment_id );
		}

		return $payment_id;
	}

	/**
	 * Find payment post ID by Stripe PaymentIntent ID.
	 *
	 * @param string $intent_id Stripe PaymentIntent ID.
	 */
	public static function get_id_by_stripe_intent( $intent_id ) {
		$intent_id = sanitize_text_field( (string) $intent_id );
		if ( '' === $intent_id ) {
			return 0;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_stripe_payment_intent',
				'meta_value'     => $intent_id,
			)
		);
		return $posts ? (int) $posts[0] : 0;
	}

	/**
	 * Mark ACH/async payment as processing — does not unlock packets or mark application paid.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $intent_id  Stripe intent ID.
	 */
	public static function mark_processing( $payment_id, $intent_id = '' ) {
		$status = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( in_array( $status, array( 'paid', 'processing' ), true ) ) {
			if ( $intent_id ) {
				update_post_meta( $payment_id, '_lnkup_stripe_payment_intent', sanitize_text_field( $intent_id ) );
			}
			return true;
		}

		update_post_meta( $payment_id, '_lnkup_payment_status', 'processing' );
		update_post_meta( $payment_id, '_lnkup_processing_at', current_time( 'mysql' ) );
		if ( $intent_id ) {
			update_post_meta( $payment_id, '_lnkup_stripe_payment_intent', sanitize_text_field( $intent_id ) );
		}

		LNKup_Transactions::record_from_payment_post( $payment_id, 'payment_processing' );
		return true;
	}

	/**
	 * Mark payment as paid.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $intent_id  Stripe intent ID.
	 */
	public static function mark_paid( $payment_id, $intent_id = '' ) {
		if ( 'paid' === get_post_meta( $payment_id, '_lnkup_payment_status', true ) ) {
			return true;
		}

		update_post_meta( $payment_id, '_lnkup_payment_status', 'paid' );
		update_post_meta( $payment_id, '_lnkup_paid_at', current_time( 'mysql' ) );
		if ( $intent_id ) {
			update_post_meta( $payment_id, '_lnkup_stripe_payment_intent', sanitize_text_field( $intent_id ) );
		}

		$app_id = (int) get_post_meta( $payment_id, '_lnkup_application_id', true );
		if ( $app_id ) {
			LNKup_Applications::transition( $app_id, 'paid' );
		}

		LNKup_Notifications::send_organizer_vendor_paid( $payment_id );

		LNKup_Transactions::record_from_payment_post( $payment_id, 'payment_completed' );
		LNKup_Transactions::sync_extended_fields( $payment_id );
		LNKup_Receipts::issue_for_payment( $payment_id );
		$app_id_paid = (int) get_post_meta( $payment_id, '_lnkup_application_id', true );
		if ( $app_id_paid ) {
			$invoice = LNKup_Invoices::get_by_application( $app_id_paid );
			if ( $invoice ) {
				LNKup_Invoices::mark_paid( (int) $invoice['id'] );
			}
			$installment_id = (int) get_post_meta( $payment_id, '_lnkup_installment_id', true );
			if ( $installment_id ) {
				LNKup_Installments::mark_paid( $installment_id, $payment_id );
			}
			$deposit = LNKup_Deposits::get_for_application( $app_id_paid );
			if ( $deposit ) {
				LNKup_Deposits::mark_paid( (int) $deposit['id'], (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ) );
			}
		}
		delete_transient( 'lnkup_fin_market_summary' );

		return true;
	}

	/**
	 * Get payment for application.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function get_for_application( $app_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_application_id',
				'meta_value'     => $app_id,
			)
		);
		return $posts[0] ?? null;
	}

	/**
	 * Persist fee breakdown fields on a payment post.
	 *
	 * @param int   $payment_id Payment post ID.
	 * @param array $fees       Fee engine breakdown.
	 */
	public static function apply_fee_breakdown_to_payment( $payment_id, $fees ) {
		update_post_meta( $payment_id, '_lnkup_vendor_checkout_fee', (float) ( $fees['vendor_checkout_fee'] ?? 0 ) );
		update_post_meta( $payment_id, '_lnkup_organizer_commission', (float) ( $fees['organizer_commission'] ?? 0 ) );
		update_post_meta( $payment_id, '_lnkup_lnkup_fee', (float) ( $fees['platform_fee'] ?? 0 ) );
		update_post_meta( $payment_id, '_lnkup_processing_fee', (float) ( $fees['processing_fee'] ?? 0 ) );
		update_post_meta( $payment_id, '_lnkup_tax_amount', (float) ( $fees['tax_amount'] ?? 0 ) );
		update_post_meta( $payment_id, '_lnkup_total_amount', (float) ( $fees['total'] ?? 0 ) );
	}

	/**
	 * Recalculate and persist fees for a pending payment from current settings.
	 *
	 * @param int $payment_id Payment post ID.
	 * @return bool True when fees were refreshed.
	 */
	public static function refresh_pending_fees( $payment_id ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id || 'lnkup_payment' !== get_post_type( $payment_id ) ) {
			return false;
		}
		if ( 'pending' !== get_post_meta( $payment_id, '_lnkup_payment_status', true ) ) {
			return false;
		}

		$vendor_fee   = (float) get_post_meta( $payment_id, '_lnkup_vendor_spot_fee', true );
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$event_id     = (int) get_post_meta( $payment_id, '_lnkup_event_id', true );
		$discount     = (float) get_post_meta( $payment_id, '_lnkup_discount_amount', true );
		$credit       = (float) get_post_meta( $payment_id, '_lnkup_credit_applied', true );
		$category     = '';
		$vendor_user  = (int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true );
		$profile_id   = (int) lnkup_get_vendor_profile_id( $vendor_user );
		if ( $profile_id ) {
			$terms = wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'slugs' ) );
			$category = is_array( $terms ) && $terms ? $terms[0] : '';
		}

		$fees = LNKup_Fee_Engine::calculate(
			array(
				'vendor_fee'   => $vendor_fee,
				'organizer_id' => $organizer_id,
				'event_id'     => $event_id,
				'category'     => $category,
				'discount'     => $discount,
				'credit'       => $credit,
			)
		);
		self::apply_fee_breakdown_to_payment( $payment_id, $fees );
		if ( class_exists( 'LNKup_Transactions' ) ) {
			LNKup_Transactions::sync_extended_fields( $payment_id );
		}
		return true;
	}

	/**
	 * Recalculate all pending payments (settings change or upgrade).
	 */
	public static function refresh_all_pending_fees() {
		$payments = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'any',
				'meta_key'       => '_lnkup_payment_status',
				'meta_value'     => 'pending',
			)
		);
		foreach ( $payments as $payment_id ) {
			self::refresh_pending_fees( (int) $payment_id );
		}
	}

	/**
	 * Recalculate pending payments with dual-fee model (one-time upgrade).
	 */
	public static function backfill_pending_payment_fees() {
		if ( get_option( 'lnkup_dual_fee_pending_backfill' ) === LNKUP_VERSION ) {
			return;
		}
		self::refresh_all_pending_fees();
		update_option( 'lnkup_dual_fee_pending_backfill', LNKUP_VERSION );
	}

	/**
	 * Read-only payment audit for organizer revenue debugging.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function get_audit( $payment_id ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id || 'lnkup_payment' !== get_post_type( $payment_id ) ) {
			return null;
		}

		$opp_id   = (int) get_post_meta( $payment_id, '_lnkup_opportunity_id', true );
		$opp_fee  = $opp_id ? (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ) : 0;
		$spot_fee = (float) get_post_meta( $payment_id, '_lnkup_vendor_spot_fee', true );
		$breakdown = class_exists( 'LNKup_Fee_Engine' ) ? LNKup_Fee_Engine::calculate_for_payment( $payment_id ) : array();
		$ledger    = class_exists( 'LNKup_Organizer_Wallet' ) && LNKup_Organizer_Wallet::has_connect_settlement_for_payment( $payment_id );
		$stored_checkout_fee = (float) get_post_meta( $payment_id, '_lnkup_vendor_checkout_fee', true );
		$organizer_id        = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$event_id            = (int) get_post_meta( $payment_id, '_lnkup_event_id', true );
		$current_checkout_fee = class_exists( 'LNKup_Fee_Engine' )
			? LNKup_Fee_Engine::resolve_vendor_checkout_fee( $organizer_id, $event_id )
			: 0;
		$payment_status = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
		$stripe_intent  = (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
		$stripe_status  = $stripe_intent && class_exists( 'LNKup_Financial_Service' )
			? LNKup_Financial_Service::stripe_intent_status( $stripe_intent )
			: '';
		$fee_mismatch   = 'pending' === $payment_status && abs( $stored_checkout_fee - $current_checkout_fee ) > 0.01;
		$settings       = lnkup_get_settings();
		$total_charged  = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
		$platform_fee   = (float) get_post_meta( $payment_id, '_lnkup_lnkup_fee', true );
		$app_fee_cents  = (int) get_post_meta( $payment_id, '_lnkup_application_fee_cents', true );
		if ( $app_fee_cents <= 0 && $platform_fee > 0 ) {
			$app_fee_cents = (int) round( $platform_fee * 100 );
		}
		$charge_cents = (int) get_post_meta( $payment_id, '_lnkup_stripe_charge_amount_cents', true );
		if ( $charge_cents <= 0 && $total_charged > 0 ) {
			$charge_cents = (int) round( $total_charged * 100 );
		}
		$dest_amount = (float) get_post_meta( $payment_id, '_lnkup_stripe_destination_amount', true );
		if ( $dest_amount <= 0 && $charge_cents > 0 && $app_fee_cents >= 0 ) {
			$dest_amount = round( max( 0, $charge_cents - $app_fee_cents ) / 100, 2 );
		}
		$expected_app_fee = (float) ( $breakdown['platform_fee'] ?? 0 );
		$expected_net     = (float) ( $breakdown['organizer_net'] ?? 0 );

		return array(
			'booth_fee_stored'               => $spot_fee,
			'booth_fee_opportunity'          => $opp_fee,
			'booth_fee_mismatch'             => $opp_id > 0 && abs( $spot_fee - $opp_fee ) > 0.01,
			'total_charged'                  => $total_charged,
			'platform_fee'                   => $platform_fee,
			'vendor_checkout_fee'            => $stored_checkout_fee,
			'vendor_checkout_fee_current'    => round( $current_checkout_fee, 2 ),
			'vendor_checkout_fee_mismatch'   => $fee_mismatch,
			'organizer_commission'           => (float) get_post_meta( $payment_id, '_lnkup_organizer_commission', true ),
			'processing_fee'                 => (float) get_post_meta( $payment_id, '_lnkup_processing_fee', true ),
			'organizer_net'                  => $expected_net,
			'settlement_mode'                => (string) get_post_meta( $payment_id, '_lnkup_settlement_mode', true ),
			'stripe_payment_intent'          => $stripe_intent,
			'stripe_intent_status'           => $stripe_status,
			'can_reconcile'                  => ( 'paid' !== $payment_status && $stripe_intent )
				|| ( 'paid' === $payment_status && ! $ledger && 'destination' === (string) get_post_meta( $payment_id, '_lnkup_settlement_mode', true ) ),
			'application_fee_cents'          => $app_fee_cents,
			'application_fee_dollars'        => round( $app_fee_cents / 100, 2 ),
			'expected_application_fee'       => round( $expected_app_fee, 2 ),
			'application_fee_matches_engine' => abs( ( $app_fee_cents / 100 ) - $expected_app_fee ) < 0.02,
			'destination_account'            => (string) get_post_meta( $payment_id, '_lnkup_stripe_destination_account', true ),
			'destination_amount'             => $dest_amount,
			'expected_destination_amount'    => $charge_cents > 0
				? round( max( 0, $charge_cents - $app_fee_cents ) / 100, 2 )
				: round( max( 0, $total_charged - $platform_fee ), 2 ),
			'stripe_charge_cents'            => $charge_cents,
			'stripe_dashboard'               => array(
				'pi_amount_cents'           => $charge_cents,
				'application_fee_cents'     => $app_fee_cents,
				'transfer_data_destination' => (string) get_post_meta( $payment_id, '_lnkup_stripe_destination_account', true ),
				'destination_gross_dollars' => $dest_amount,
				'organizer_product_net'     => $expected_net,
				'note'                      => 'Compare pi_amount_cents and application_fee_cents to the PaymentIntent in Stripe Dashboard. Destination gross includes estimated processing; organizer_product_net is booth minus commission.',
			),
			'ledger_connect_settlement'      => $ledger,
			'payment_status'                 => $payment_status,
			'fee_payer_mode'                 => (string) ( $settings['fee_payer_mode'] ?? 'vendor' ),
			'fee_breakdown'                  => $breakdown,
		);
	}
}
