<?php
/**
 * Configurable marketplace fee engine.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Fee_Engine {

	const PROCESSING_RATE = 0.029;
	const PROCESSING_FLAT = 0.30;
	const ACH_PROCESSING_RATE = 0.008;
	const ACH_PROCESSING_CAP  = 5.00;

	/**
	 * Calculate fee breakdown for a booking.
	 *
	 * @param array $context vendor_fee, organizer_id, event_id, category, opportunity_id, payment_method.
	 * @return array
	 */
	public static function calculate( $context ) {
		$vendor_fee   = max( 0, (float) ( $context['vendor_fee'] ?? 0 ) );
		$organizer_id = absint( $context['organizer_id'] ?? 0 );
		$event_id     = absint( $context['event_id'] ?? 0 );
		$category     = sanitize_key( $context['category'] ?? '' );
		$discount     = max( 0, (float) ( $context['discount'] ?? 0 ) );
		$credit       = max( 0, (float) ( $context['credit'] ?? 0 ) );
		$method       = sanitize_key( $context['payment_method'] ?? 'card' );

		$vendor_checkout_fee  = self::resolve_vendor_checkout_fee( $organizer_id, $event_id, $category );
		// Free booths: no marketplace checkout fee (docs / product rule).
		if ( $vendor_fee <= 0 ) {
			$vendor_checkout_fee = 0.0;
		}
		$organizer_commission = self::resolve_organizer_commission( $vendor_fee, $organizer_id, $event_id, $category );
		// fee_payer_mode "organizer": the vendor is not charged the checkout fee; LNKup takes it
		// from the organizer's share as extra commission. Total LNKup fee is unchanged.
		if ( 'organizer' === ( lnkup_get_settings()['fee_payer_mode'] ?? 'vendor' ) && $vendor_checkout_fee > 0 ) {
			$organizer_commission = round( $organizer_commission + $vendor_checkout_fee, 2 );
			$vendor_checkout_fee  = 0.0;
		}
		$platform_fee         = round( $vendor_checkout_fee + $organizer_commission, 2 );
		$subtotal             = round( $vendor_fee + $vendor_checkout_fee, 2 );
		$processing           = self::resolve_processing_fee( $subtotal, $method );
		$tax_rate             = self::resolve_tax_rate( $organizer_id, $event_id );
		$taxable              = max( 0, $subtotal - $discount );
		$tax_amount           = round( $taxable * $tax_rate, 2 );
		$total                = max( 0, round( $subtotal + $processing + $tax_amount - $discount - $credit, 2 ) );
		$organizer_net        = max( 0, round( $vendor_fee - $organizer_commission, 2 ) );

		return array(
			'vendor_fee'              => round( $vendor_fee, 2 ),
			'vendor_checkout_fee'     => round( $vendor_checkout_fee, 2 ),
			'organizer_commission'    => round( $organizer_commission, 2 ),
			'platform_fee'            => $platform_fee,
			'booth_reservation_total' => $subtotal,
			'organizer_net'           => $organizer_net,
			'processing_fee'          => $processing,
			'tax_amount'              => $tax_amount,
			'tax_rate'                => $tax_rate,
			'discount'                => round( $discount, 2 ),
			'credit'                  => round( $credit, 2 ),
			'subtotal'                => $subtotal,
			'total'                   => $total,
			'payment_method'          => $method,
		);
	}

	/**
	 * Card vs ACH processing estimate when ACH flag is on.
	 *
	 * @param float  $subtotal Subtotal.
	 * @param string $method   card|ach|us_bank_account.
	 */
	public static function resolve_processing_fee( $subtotal, $method = 'card' ) {
		$subtotal = (float) $subtotal;
		if ( $subtotal <= 0 ) {
			return 0.0;
		}
		$settings = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		$is_ach   = in_array( $method, array( 'ach', 'us_bank_account' ), true );
		if ( $is_ach && ! empty( $settings['ach_enabled'] ) ) {
			return min( round( $subtotal * self::ACH_PROCESSING_RATE, 2 ), self::ACH_PROCESSING_CAP );
		}
		return round( $subtotal * self::PROCESSING_RATE + self::PROCESSING_FLAT, 2 );
	}

	/**
	 * Flat fee charged to vendor at checkout (bundled with booth).
	 *
	 * @param int    $organizer_id Organizer user ID.
	 * @param int    $event_id     Event ID.
	 * @param string $category     Vendor category slug.
	 */
	public static function resolve_vendor_checkout_fee( $organizer_id = 0, $event_id = 0, $category = '' ) {
		$from_settings = self::resolve_vendor_checkout_fee_from_settings();
		$rule          = self::match_scoped_flat_rule( $organizer_id, $event_id, $category );
		if ( $rule ) {
			return self::apply_rule( $rule, 0 );
		}
		return $from_settings;
	}

	/**
	 * Vendor checkout flat fee from wp_options (canonical admin setting).
	 */
	public static function resolve_vendor_checkout_fee_from_settings() {
		$settings = lnkup_get_settings();
		if ( isset( $settings['vendor_checkout_fee'] ) && '' !== (string) $settings['vendor_checkout_fee'] ) {
			return round( (float) $settings['vendor_checkout_fee'], 2 );
		}
		$legacy = (float) ( $settings['application_fee_flat'] ?? $settings['default_fee'] ?? 5.99 );
		return round( $legacy, 2 );
	}

	/**
	 * Keep legacy fee keys aligned with vendor_checkout_fee.
	 *
	 * @param array $settings Settings array (partial or full).
	 * @return array
	 */
	public static function sync_fee_setting_aliases( array $settings ) {
		$fee = null;
		if ( isset( $settings['vendor_checkout_fee'] ) && '' !== (string) $settings['vendor_checkout_fee'] ) {
			$fee = round( (float) $settings['vendor_checkout_fee'], 2 );
		} elseif ( isset( $settings['application_fee_flat'] ) && '' !== (string) $settings['application_fee_flat'] ) {
			$fee = round( (float) $settings['application_fee_flat'], 2 );
		} elseif ( isset( $settings['default_fee'] ) && '' !== (string) $settings['default_fee'] ) {
			$fee = round( (float) $settings['default_fee'], 2 );
		}
		if ( null === $fee ) {
			$fee = 5.99;
		}
		$settings['vendor_checkout_fee']  = $fee;
		$settings['application_fee_flat'] = $fee;
		$settings['default_fee']          = $fee;
		return $settings;
	}

	/**
	 * One-time migration: align stored fee keys and retire global flat SQL rules.
	 */
	public static function migrate_fee_configuration() {
		if ( get_option( 'lnkup_fee_settings_migrated_v2' ) === LNKUP_VERSION ) {
			return;
		}
		$stored = get_option( 'lnkup_settings', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$stored = self::sync_fee_setting_aliases( $stored );
		update_option( 'lnkup_settings', $stored );

		global $wpdb;
		$table = LNKup_Financial_Tables::fee_rules_table();
		if ( LNKup_Financial_Tables::table_exists( $table ) ) {
			$wpdb->query(
				"UPDATE {$table} SET status = 'inactive' WHERE status = 'active' AND fee_type = 'flat' AND scope_type = 'global'"
			);
		}
		self::migrate_global_commission_rule();

		update_option( 'lnkup_fee_settings_migrated_v2', LNKUP_VERSION );
	}

	/**
	 * B-12: the seeded global `percent` rule silently overrode organizer_commission_percent, so
	 * admin changes to that setting did nothing. Copy the rule's value into the setting first
	 * (effective fees do not change), then retire the global rule so the setting is authoritative.
	 * Scoped (organizer / event / category) rules keep precedence over the setting.
	 *
	 * @return array{migrated:bool, from:float|null, to:float|null}
	 */
	public static function migrate_global_commission_rule() {
		global $wpdb;
		$out   = array( 'migrated' => false, 'from' => null, 'to' => null );
		$table = LNKup_Financial_Tables::fee_rules_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return $out;
		}
		$rule = $wpdb->get_row( "SELECT * FROM {$table} WHERE status = 'active' AND fee_type = 'percent' AND scope_type = 'global' ORDER BY priority ASC, id ASC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rule ) {
			return $out;
		}
		$stored  = get_option( 'lnkup_settings', array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$current = isset( $stored['organizer_commission_percent'] ) ? (float) $stored['organizer_commission_percent'] : 6.0;
		$value   = (float) $rule['fee_value'];
		$stored['organizer_commission_percent'] = $value;
		update_option( 'lnkup_settings', $stored );
		$wpdb->query( "UPDATE {$table} SET status = 'inactive' WHERE status = 'active' AND fee_type = 'percent' AND scope_type = 'global'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( class_exists( 'LNKup_Financial_Audit' ) ) {
			LNKup_Financial_Audit::log(
				'fee_global_commission_rule_retired',
				'fee_rule',
				(int) $rule['id'],
				array(
					'rule_percent'             => $value,
					'previous_setting_percent' => $current,
					'note'                     => 'Effective commission unchanged; organizer_commission_percent is now authoritative.',
				)
			);
		}
		return array( 'migrated' => true, 'from' => $current, 'to' => $value );
	}

	/**
	 * Commission on booth price deducted from organizer revenue.
	 *
	 * @param float  $vendor_fee   Booth price.
	 * @param int    $organizer_id Organizer user ID.
	 * @param int    $event_id     Event ID.
	 * @param string $category     Vendor category slug.
	 */
	public static function resolve_organizer_commission( $vendor_fee, $organizer_id = 0, $event_id = 0, $category = '' ) {
		$rule = self::match_commission_rule( $organizer_id, $event_id, $category );
		if ( $rule ) {
			return self::apply_rule( $rule, $vendor_fee );
		}
		$settings = lnkup_get_settings();
		$pct      = (float) ( $settings['organizer_commission_percent'] ?? 6 );
		return round( max( 0, $vendor_fee ) * ( $pct / 100 ), 2 );
	}

	/**
	 * Total marketplace revenue (Stripe application_fee).
	 *
	 * @param float  $vendor_fee   Booth price.
	 * @param int    $organizer_id Organizer user ID.
	 * @param int    $event_id     Event ID.
	 * @param string $category     Vendor category slug.
	 */
	public static function resolve_platform_fee( $vendor_fee, $organizer_id = 0, $event_id = 0, $category = '' ) {
		return round(
			self::resolve_vendor_checkout_fee( $organizer_id, $event_id, $category )
			+ self::resolve_organizer_commission( $vendor_fee, $organizer_id, $event_id, $category ),
			2
		);
	}

	/**
	 * @param int    $organizer_id Organizer ID.
	 * @param int    $event_id     Event ID.
	 * @param string $category     Category slug.
	 */
	private static function match_commission_rule( $organizer_id, $event_id, $category ) {
		// Scoped (organizer / event / category) rules always beat a global rule, whatever the priority.
		$rule = self::match_rule_by_types( $organizer_id, $event_id, $category, array( 'percent', 'hybrid' ), true );
		if ( $rule ) {
			return $rule;
		}
		// An active global rule still applies until migrate_global_commission_rule() retires it,
		// so effective fees never change between deploy and migration.
		return self::match_rule_by_types( $organizer_id, $event_id, $category, array( 'percent', 'hybrid' ) );
	}

	/**
	 * Scoped flat override for vendor checkout fee (never global — use settings for that).
	 *
	 * @param int    $organizer_id Organizer ID.
	 * @param int    $event_id     Event ID.
	 * @param string $category     Category slug.
	 */
	private static function match_scoped_flat_rule( $organizer_id, $event_id, $category ) {
		$rule = self::match_rule_by_types( $organizer_id, $event_id, $category, array( 'flat' ) );
		if ( ! $rule ) {
			return null;
		}
		$scope = $rule['scope_type'] ?? 'global';
		if ( 'global' === $scope ) {
			return null;
		}
		return $rule;
	}

	/**
	 * @param int    $organizer_id Organizer ID.
	 * @param int    $event_id     Event ID.
	 * @param string $category     Category slug.
	 * @param array  $fee_types    Allowed fee_type values.
	 * @param bool   $scoped_only  Skip global rules (settings own the global default).
	 */
	private static function match_rule_by_types( $organizer_id, $event_id, $category, array $fee_types, $scoped_only = false ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::fee_rules_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return null;
		}
		$rules = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE status = 'active' ORDER BY priority ASC, id ASC",
			ARRAY_A
		);
		foreach ( $rules as $rule ) {
			if ( ! in_array( $rule['fee_type'] ?? '', $fee_types, true ) ) {
				continue;
			}
			$scope    = $rule['scope_type'] ?? 'global';
			$scope_id = $rule['scope_id'] ?? '';
			if ( $scoped_only && 'global' === $scope ) {
				continue;
			}
			if ( 'organizer' === $scope && (int) $scope_id !== $organizer_id ) {
				continue;
			}
			if ( 'event' === $scope && (int) $scope_id !== $event_id ) {
				continue;
			}
			if ( 'category' === $scope && $scope_id !== $category ) {
				continue;
			}
			return $rule;
		}
		return null;
	}

	/**
	 * @param array $rule       Rule row.
	 * @param float $vendor_fee Base amount.
	 */
	private static function apply_rule( $rule, $vendor_fee ) {
		$type  = $rule['fee_type'] ?? 'flat';
		$value = (float) ( $rule['fee_value'] ?? 0 );
		$min   = isset( $rule['fee_min'] ) ? (float) $rule['fee_min'] : null;
		$max   = isset( $rule['fee_max'] ) ? (float) $rule['fee_max'] : null;
		$fee   = 0;

		switch ( $type ) {
			case 'percent':
				$fee = round( $vendor_fee * ( $value / 100 ), 2 );
				break;
			case 'hybrid':
				$fee = round( (float) ( $rule['fee_flat'] ?? 0 ) + $vendor_fee * ( $value / 100 ), 2 );
				break;
			default:
				$fee = round( $value, 2 );
		}
		if ( null !== $min ) {
			$fee = max( $fee, $min );
		}
		if ( null !== $max ) {
			$fee = min( $fee, $max );
		}
		return $fee;
	}

	/**
	 * @param int $organizer_id Organizer ID.
	 * @param int $event_id     Event ID.
	 */
	private static function resolve_tax_rate( $organizer_id, $event_id ) {
		$profile = LNKup_Tax_Profiles::get_or_create( $organizer_id );
		return (float) ( $profile['sales_tax_rate'] ?? 0 );
	}

	/**
	 * Full treasury breakdown for a completed payment.
	 *
	 * @param int $payment_id Payment post ID.
	 * @return array
	 */
	public static function calculate_for_payment( $payment_id ) {
		$vendor_fee   = (float) get_post_meta( $payment_id, '_lnkup_vendor_spot_fee', true );
		$organizer_id = (int) get_post_meta( $payment_id, '_lnkup_organizer_user_id', true );
		$event_id     = (int) get_post_meta( $payment_id, '_lnkup_event_id', true );
		$discount     = (float) get_post_meta( $payment_id, '_lnkup_discount_amount', true );
		$credit       = (float) get_post_meta( $payment_id, '_lnkup_credit_applied', true );
		$gross        = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );

		$base = self::calculate( array(
			'vendor_fee'     => $vendor_fee,
			'organizer_id'   => $organizer_id,
			'event_id'       => $event_id,
			'category'       => class_exists( 'LNKup_Payments' ) ? LNKup_Payments::fee_category_for_payment( $payment_id ) : '',
			'payment_method' => (string) get_post_meta( $payment_id, '_lnkup_payment_method', true ) ?: 'card',
			'discount'       => $discount,
			'credit'         => $credit,
		) );

		$platform_fee      = (float) ( $base['platform_fee'] ?? 0 );
		$organizer_net     = (float) ( $base['organizer_net'] ?? 0 );
		$settings          = lnkup_get_settings();
		$reserve_pct       = (float) ( $settings['reserve_percent'] ?? 0 );
		$reserve_amt       = round( $organizer_net * ( $reserve_pct / 100 ), 2 );
		$payout_eligible   = max( 0, round( $organizer_net - $reserve_amt, 2 ) );
		$stripe_fee_est    = round( $gross * self::PROCESSING_RATE + self::PROCESSING_FLAT, 2 );
		$fee_payer         = $settings['fee_payer_mode'] ?? 'vendor';

		return array(
			'gross_amount'             => round( $gross, 2 ),
			'vendor_fee'               => round( $vendor_fee, 2 ),
			'vendor_checkout_fee'      => (float) ( $base['vendor_checkout_fee'] ?? 0 ),
			'organizer_commission'     => (float) ( $base['organizer_commission'] ?? 0 ),
			'booth_reservation_total'  => (float) ( $base['booth_reservation_total'] ?? 0 ),
			'organizer_net'            => $organizer_net,
			'lnkup_platform_fee'       => $platform_fee,
			'platform_fee'             => $platform_fee,
			'processing_fee'           => (float) ( $base['processing_fee'] ?? 0 ),
			'stripe_fee'               => $stripe_fee_est,
			'tax_amount'               => (float) ( $base['tax_amount'] ?? 0 ),
			'discount_amount'          => round( $discount, 2 ),
			'credit_amount'            => round( $credit, 2 ),
			'reserve_amount'           => $reserve_amt,
			'reserve_hold'             => $reserve_amt,
			'payout_eligible_amount'   => $payout_eligible,
			'fee_payer_mode'           => $fee_payer,
			'fee_rule_id'              => 0,
			'credits_applied'          => round( $credit, 2 ),
		);
	}

	/**
	 * application_fee_amount for a destination charge: everything except the organizer's share.
	 *
	 * Organizer share = booth − commission + tax − organizer-funded promo discount, the same net a
	 * platform-settled booking credits. LNKup therefore keeps the vendor-paid processing fee (it
	 * pays Stripe's fee on destination charges) and funds platform credits and marketplace promo
	 * codes. Deposits/installments get a proportional share. Setting processing_fee_payee =
	 * "organizer" restores the previous rule (application fee = platform fee only).
	 *
	 * @param int   $payment_id   Payment post ID.
	 * @param int   $amount_cents Amount being charged now.
	 * @param array $breakdown    calculate_for_payment() breakdown.
	 * @return int Cents.
	 */
	public static function destination_application_fee_cents( $payment_id, $amount_cents, $breakdown ) {
		$amount_cents = (int) $amount_cents;
		if ( 'organizer' === ( lnkup_get_settings()['processing_fee_payee'] ?? 'platform' ) ) {
			return (int) round( (float) ( $breakdown['platform_fee'] ?? 0 ) * 100 );
		}
		$share = (float) ( $breakdown['organizer_net'] ?? 0 ) + (float) ( $breakdown['tax_amount'] ?? 0 );
		$promo = (float) get_post_meta( $payment_id, '_lnkup_discount_amount', true );
		if ( $promo > 0 && 'organizer' === (string) get_post_meta( $payment_id, '_lnkup_promo_funded_by', true ) ) {
			$share -= $promo;
		}
		$share_cents = max( 0, (int) round( $share * 100 ) );

		$total_cents  = (int) round( (float) get_post_meta( $payment_id, '_lnkup_total_amount', true ) * 100 );
		$credit_cents = (int) round( (float) get_post_meta( $payment_id, '_lnkup_credit_applied', true ) * 100 );
		$booking_due  = max( 1, $total_cents - $credit_cents - (int) get_post_meta( $payment_id, '_lnkup_amount_paid_cents', true ) );
		if ( $amount_cents < $booking_due ) {
			$share_cents = (int) round( $share_cents * ( $amount_cents / $booking_due ) );
		}
		return max( 0, min( $amount_cents - 1, $amount_cents - $share_cents ) );
	}

	/**
	 * Fee breakdown for settling an already-charged payment.
	 *
	 * Starts from the charge-time snapshot (what was sent to Stripe), overlays Stripe's
	 * actual destination amounts when known, and only falls back to today's fee settings
	 * for legacy payments without a snapshot.
	 *
	 * @param int $payment_id Payment post ID.
	 */
	public static function settlement_breakdown( $payment_id ) {
		$fresh    = self::calculate_for_payment( $payment_id );
		$snapshot = json_decode( (string) get_post_meta( $payment_id, '_lnkup_fee_breakdown', true ), true );
		$has_snap = is_array( $snapshot ) && isset( $snapshot['platform_fee'] );
		$out      = $has_snap ? array_merge( $fresh, $snapshot ) : $fresh;
		$source   = $has_snap ? 'charge_snapshot' : 'calculated';

		$charge_cents = (int) get_post_meta( $payment_id, '_lnkup_stripe_charge_amount_cents', true );
		$fee_cents    = (int) get_post_meta( $payment_id, '_lnkup_application_fee_cents', true );
		$destination  = (string) get_post_meta( $payment_id, '_lnkup_stripe_destination_account', true );
		if ( '' !== $destination && $charge_cents > 0 && $fee_cents >= 0 && $fee_cents < $charge_cents ) {
			$out['gross_amount']       = round( $charge_cents / 100, 2 );
			$out['platform_fee']       = round( $fee_cents / 100, 2 );
			$out['lnkup_platform_fee'] = $out['platform_fee'];
			$out['organizer_net']      = round( ( $charge_cents - $fee_cents ) / 100, 2 );
			$source                    = 'stripe';
		}

		if ( ! $has_snap || ! isset( $snapshot['reserve_amount'] ) ) {
			$settings              = lnkup_get_settings();
			$out['reserve_amount'] = round( (float) $out['organizer_net'] * ( (float) ( $settings['reserve_percent'] ?? 0 ) / 100 ), 2 );
			$out['reserve_hold']   = $out['reserve_amount'];
		}
		$out['payout_eligible_amount'] = max( 0, round( (float) $out['organizer_net'] - (float) $out['reserve_amount'], 2 ) );
		$out['breakdown_source']       = $source;
		return $out;
	}

	/**
	 * No-op: the global commission default lives in lnkup_settings.organizer_commission_percent.
	 * Seeding a global percent rule here shadowed that setting (B-12). Kept for callers.
	 */
	public static function seed_defaults() {
	}
}
