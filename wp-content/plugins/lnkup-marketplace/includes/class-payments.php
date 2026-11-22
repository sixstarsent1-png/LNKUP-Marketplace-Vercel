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
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		// A payment voided by an earlier rejection/withdrawal can never be collected; a re-approved
		// booking gets a fresh payment row instead of sitting in payment_required forever (B-5).
		if ( $existing && '' !== (string) get_post_meta( (int) $existing[0], '_lnkup_voided_at', true ) ) {
			LNKup_Financial_Audit::log( 'payment_replaced_after_void', 'payment', (int) $existing[0], array( 'application_id' => (int) $app_id ) );
			$existing = array();
		}
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
		$vendor_user  = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$category     = self::fee_category_for_application( (int) $app_id, $vendor_user );
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
			),
			true
		);
		if ( is_wp_error( $payment_id ) || ! $payment_id ) {
			LNKup_Financial_Audit::log( 'payment_create_failed', 'application', (int) $app_id, array( 'error' => is_wp_error( $payment_id ) ? $payment_id->get_error_message() : 'insert_failed' ) );
			return 0;
		}

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
			if ( $intent_id && 'processing' === $status ) {
				update_post_meta( $payment_id, '_lnkup_stripe_payment_intent', sanitize_text_field( $intent_id ) );
			}
			return true;
		}
		if ( ! self::is_collectible( $payment_id ) ) {
			return false;
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
	 * Whether the vendor may start a new charge (checkout, Payment Element, hosted session).
	 *
	 * Processing (ACH in flight), paid, refunded, disputed and voided payments are not payable.
	 *
	 * @param int $payment_id Payment ID.
	 */
	public static function is_payable( $payment_id ) {
		$status = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( '' !== (string) get_post_meta( $payment_id, '_lnkup_voided_at', true ) ) {
			return false;
		}
		return in_array( $status, array( 'pending', 'partially_paid', 'failed', '' ), true );
	}

	/**
	 * Whether a charge Stripe reports as succeeded may still be applied to this payment.
	 *
	 * Refunded, disputed, chargeback-lost and voided payments can never become paid again.
	 *
	 * @param int $payment_id Payment ID.
	 */
	public static function is_collectible( $payment_id ) {
		$status = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
		if ( '' !== (string) get_post_meta( $payment_id, '_lnkup_voided_at', true ) ) {
			return false;
		}
		return in_array( $status, array( 'pending', 'processing', 'partially_paid', 'failed', '' ), true );
	}

	/**
	 * A declined (failed, not voided) payment becomes pending again when the vendor retries.
	 *
	 * @param int $payment_id Payment ID.
	 */
	public static function reopen_if_declined( $payment_id ) {
		if ( 'failed' !== (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) || '' !== (string) get_post_meta( $payment_id, '_lnkup_voided_at', true ) ) {
			return false;
		}
		update_post_meta( $payment_id, '_lnkup_payment_status', 'pending' );
		LNKup_Financial_Audit::log( 'payment_reopened_after_decline', 'payment', $payment_id, array() );
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
		if ( ! self::is_collectible( $payment_id ) ) {
			LNKup_Financial_Audit::log( 'mark_paid_refused', 'payment', $payment_id, array( 'status' => (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ), 'intent_id' => (string) $intent_id ) );
			return false;
		}

		update_post_meta( $payment_id, '_lnkup_payment_status', 'paid' );
		if ( '' === (string) get_post_meta( $payment_id, '_lnkup_paid_at', true ) ) {
			update_post_meta( $payment_id, '_lnkup_paid_at', current_time( 'mysql' ) );
		}
		if ( $intent_id ) {
			update_post_meta( $payment_id, '_lnkup_stripe_payment_intent', sanitize_text_field( $intent_id ) );
		}

		$app_id = (int) get_post_meta( $payment_id, '_lnkup_application_id', true );
		if ( $app_id ) {
			$booked = LNKup_Applications::transition( $app_id, 'paid', array( 'source' => 'payment' ) );
			if ( is_wp_error( $booked ) ) {
				// Money landed after the booking closed (rejected / withdrawn / expired). Keep the
				// vendor un-booked and surface it for an admin refund decision (B-7).
				update_post_meta( $payment_id, '_lnkup_needs_review', 'paid_after_close' );
				LNKup_Financial_Audit::log(
					'paid_after_close',
					'payment',
					$payment_id,
					array(
						'application_id'     => $app_id,
						'application_status' => (string) get_post_meta( $app_id, '_lnkup_application_status', true ),
					)
				);
				do_action( 'lnkup_payment_needs_review', $payment_id, 'paid_after_close' );
			}
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
			if ( $deposit && 'paid' !== $deposit['status'] ) {
				// The booking is now fully paid: record only what the deposit row is still missing.
				LNKup_Deposits::mark_paid( (int) $deposit['id'], max( 0, (float) $deposit['total_amount'] - (float) $deposit['paid_amount'] ) );
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
				// Newest first: a re-approved booking replaces its voided payment row.
				'orderby'        => 'ID',
				'order'          => 'DESC',
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
		$fees = self::pending_fee_preview( $payment_id );
		if ( null === $fees ) {
			return false;
		}
		self::apply_fee_breakdown_to_payment( $payment_id, $fees );
		if ( class_exists( 'LNKup_Transactions' ) ) {
			LNKup_Transactions::sync_extended_fields( $payment_id );
		}
		return true;
	}

	/**
	 * Fee breakdown a pending payment would have under current settings, without writing anything.
	 *
	 * @param int $payment_id Payment post ID.
	 * @return array|null Fee engine breakdown, or null when the payment is not pending.
	 */
	public static function pending_fee_preview( $payment_id ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id || 'lnkup_payment' !== get_post_type( $payment_id ) ) {
			return null;
		}
		if ( 'pending' !== get_post_meta( $payment_id, '_lnkup_payment_status', true ) ) {
			return null;
		}
		return LNKup_Fee_Engine::calculate(
			array(
				'vendor_fee'     => (float) get_post_meta( $payment_id, '_lnkup_vendor_spot_fee', true ),
				'organizer_id'   => (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true ),
				'event_id'       => (int) get_post_meta( $payment_id, '_lnkup_event_id', true ),
				'category'       => self::fee_category_for_payment( $payment_id ),
				'discount'       => (float) get_post_meta( $payment_id, '_lnkup_discount_amount', true ),
				'payment_method' => (string) get_post_meta( $payment_id, '_lnkup_payment_method', true ) ?: 'card',
				// _lnkup_total_amount stays gross of credit; stored_amount_due() subtracts credit once.
				'credit'         => 0,
			)
		);
	}

	/**
	 * Category slug used for scoped fee rules: the category chosen on the application,
	 * falling back to the vendor profile's first category.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function fee_category_for_payment( $payment_id ) {
		return self::fee_category_for_application(
			(int) get_post_meta( $payment_id, '_lnkup_application_id', true ),
			(int) get_post_meta( $payment_id, '_lnkup_vendor_user_id', true )
		);
	}

	/**
	 * @param int $app_id      Application ID.
	 * @param int $vendor_user Vendor user ID.
	 */
	public static function fee_category_for_application( $app_id, $vendor_user ) {
		if ( $app_id ) {
			$chosen = (string) get_post_meta( $app_id, '_lnkup_selected_category', true );
			if ( '' !== $chosen ) {
				$term = get_term_by( 'name', $chosen, 'lnkup_vendor_category' );
				return $term && ! is_wp_error( $term ) ? $term->slug : sanitize_title( $chosen );
			}
		}
		$profile_id  = (int) lnkup_get_vendor_profile_id( $vendor_user );
		if ( $profile_id ) {
			$terms = wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'slugs' ) );
			return is_array( $terms ) && $terms ? (string) $terms[0] : '';
		}
		return '';
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
	 * @param int  $payment_id Payment post ID.
	 * @param bool $live       Also read the PaymentIntent status from Stripe (single-payment views only).
	 */
	public static function get_audit( $payment_id, $live = false ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id || 'lnkup_payment' !== get_post_type( $payment_id ) ) {
			return null;
		}

		$opp_id   = (int) get_post_meta( $payment_id, '_lnkup_opportunity_id', true );
		$opp_fee  = $opp_id ? (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ) : 0;
		$spot_fee = (float) get_post_meta( $payment_id, '_lnkup_vendor_spot_fee', true );
		$breakdown = class_exists( 'LNKup_Fee_Engine' ) ? LNKup_Fee_Engine::settlement_breakdown( $payment_id ) : array();
		$ledger    = class_exists( 'LNKup_Organizer_Wallet' ) && LNKup_Organizer_Wallet::has_wallet_entry_for_payment( $payment_id );
		$settled   = class_exists( 'LNKup_Financial_Service' ) && LNKup_Financial_Service::is_settled( $payment_id );
		$stored_checkout_fee = (float) get_post_meta( $payment_id, '_lnkup_vendor_checkout_fee', true );
		$organizer_id        = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$event_id            = (int) get_post_meta( $payment_id, '_lnkup_event_id', true );
		$current_checkout_fee = class_exists( 'LNKup_Fee_Engine' )
			? LNKup_Fee_Engine::resolve_vendor_checkout_fee( $organizer_id, $event_id )
			: 0;
		$payment_status = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
		$stripe_intent  = (string) get_post_meta( $payment_id, '_lnkup_stripe_payment_intent', true );
		$stripe_status  = $live && $stripe_intent && class_exists( 'LNKup_Financial_Service' )
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
				|| ( 'paid' === $payment_status && ! $settled ),
			'settled'                        => $settled,
			'settled_at'                     => (string) get_post_meta( $payment_id, '_lnkup_settled_at', true ),
			'paid_at'                        => (string) get_post_meta( $payment_id, '_lnkup_paid_at', true ),
			'stripe_charge_id'               => (string) get_post_meta( $payment_id, '_lnkup_stripe_charge_id', true ),
			'stripe_transfer_id'             => (string) get_post_meta( $payment_id, '_lnkup_stripe_transfer_id', true ),
			'stripe_balance_transaction_id'  => (string) get_post_meta( $payment_id, '_lnkup_stripe_balance_transaction_id', true ),
			'fee_breakdown_source'           => (string) ( $breakdown['breakdown_source'] ?? '' ),
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
