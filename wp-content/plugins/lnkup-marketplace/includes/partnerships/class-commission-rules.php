<?php
/**
 * Versioned commission rules.
 *
 * Rules are immutable once published: changing terms means creating and publishing a new
 * version. Referrals and connectors are locked to the rule in force when they were attributed
 * or approved, so historical obligations are never recalculated with a newer rate.
 *
 * Rules with `source = settings` are the v1 rows seeded from the pre-existing lnkup_settings
 * keys. They keep reading those keys so the legacy settings screen behaves exactly as before.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Commission_Rules {

	const BASES    = array( 'booth_gmv', 'platform_fee', 'fixed' );
	const STACKING = array( 'exclusive', 'payment_stackable' );
	const LEGACY_FROM = '2000-01-01 00:00:00';

	public static function table(): string {
		return LNKup_Partner_Tables::table( LNKup_Partner_Tables::RULES );
	}

	// ------------------------------------------------------------------ Seeding.

	/**
	 * Seed v1 rules mirroring today's production terms and grandfather existing connectors.
	 */
	public static function seed(): void {
		$s = lnkup_get_settings();

		if ( ! self::latest_version( LNKup_Partner_Programs::CITY_CONNECTOR ) ) {
			$v1 = self::insert(
				array(
					'program'           => LNKup_Partner_Programs::CITY_CONNECTOR,
					'version'           => 1,
					'name'              => __( 'Legacy City Connector terms', 'lnkup-marketplace' ),
					'source'            => 'settings',
					'basis'             => 'booth_gmv',
					'rate'              => (float) ( $s['connector_booking_share_percent'] ?? 10 ),
					'cap'               => (float) ( $s['connector_booking_share_cap'] ?? 0 ),
					'term_months'       => (int) ( $s['connector_reward_term_months'] ?? 12 ),
					'hold_days'         => (int) ( $s['connector_payout_hold_days'] ?? 30 ),
					'qualifying_event'  => 'paid_booking',
					'stacking_policy'   => 'exclusive',
					'requires_approval' => 1,
					'agreement_ref'     => 'legacy_settings',
					'status'            => 'active',
					'effective_from'    => self::LEGACY_FROM,
				)
			);
			self::insert(
				array(
					'program'           => LNKup_Partner_Programs::CITY_CONNECTOR,
					'version'           => 2,
					'name'              => __( 'City Connector — share of LNKup fees', 'lnkup-marketplace' ),
					'source'            => 'custom',
					'basis'             => 'platform_fee',
					'rate'              => 0,
					'term_months'       => (int) ( $s['connector_reward_term_months'] ?? 12 ),
					'hold_days'         => (int) ( $s['connector_payout_hold_days'] ?? 30 ),
					'qualifying_event'  => 'paid_booking',
					'stacking_policy'   => 'exclusive',
					'requires_approval' => 1,
					'status'            => 'draft',
				)
			);
			if ( $v1 && class_exists( 'LNKup_Connector_Tables' ) ) {
				global $wpdb;
				$connectors = LNKup_Connector_Tables::connectors_table();
				// Approved connectors are grandfathered; pending applicants lock a rule when approved.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$connectors} SET commission_rule_id = %d WHERE commission_rule_id = 0 AND (status IN ('active','paused') OR approved_at IS NOT NULL)", $v1 ) );
			}
		}

		if ( ! self::latest_version( LNKup_Partner_Programs::ORGANIZER_RECRUIT ) ) {
			self::insert(
				array(
					'program'           => LNKup_Partner_Programs::ORGANIZER_RECRUIT,
					'version'           => 1,
					'name'              => __( 'Referral invites — share of LNKup fees', 'lnkup-marketplace' ),
					'source'            => 'settings',
					'basis'             => 'platform_fee',
					'rate'              => (float) ( $s['organizer_recruit_percent'] ?? 20 ),
					'term_months'       => (int) ( $s['organizer_recruit_months'] ?? 12 ),
					'hold_days'         => 0,
					'qualifying_event'  => 'paid_booking',
					'stacking_policy'   => 'exclusive',
					'requires_approval' => 0,
					'agreement_ref'     => 'legacy_settings',
					'status'            => 'active',
					'effective_from'    => self::LEGACY_FROM,
				)
			);
		}

		foreach ( array( LNKup_Partner_Programs::AFFILIATE, LNKup_Partner_Programs::STRATEGIC_PARTNER ) as $program ) {
			if ( self::latest_version( $program ) ) {
				continue;
			}
			// Draft with no rate: referrals are tracked, nothing is earned until an admin publishes terms.
			self::insert(
				array(
					'program'           => $program,
					'version'           => 1,
					'name'              => LNKup_Partner_Programs::AFFILIATE === $program ? __( 'Affiliate — share of LNKup fees', 'lnkup-marketplace' ) : __( 'Strategic partner — share of LNKup fees', 'lnkup-marketplace' ),
					'source'            => 'custom',
					'basis'             => 'platform_fee',
					'rate'              => 0,
					'term_months'       => 12,
					'hold_days'         => 30,
					'qualifying_event'  => 'paid_booking',
					'stacking_policy'   => 'exclusive',
					'requires_approval' => 1,
					'status'            => 'draft',
				)
			);
		}

		if ( ! self::latest_version( LNKup_Partner_Programs::VENDOR_REFERRAL ) ) {
			self::insert(
				array(
					'program'           => LNKup_Partner_Programs::VENDOR_REFERRAL,
					'version'           => 1,
					'name'              => __( 'Vendor referral credit', 'lnkup-marketplace' ),
					'source'            => 'settings',
					'basis'             => 'fixed',
					'fixed_amount'      => self::vendor_referral_credit_amount(),
					'term_months'       => 0,
					'qualifying_event'  => 'first_invoice_paid',
					'target_role'       => 'vendor',
					'stacking_policy'   => 'payment_stackable',
					'requires_approval' => 0,
					'agreement_ref'     => 'legacy_credit_rules',
					'status'            => 'active',
					'effective_from'    => self::LEGACY_FROM,
				)
			);
		}
	}

	private static function vendor_referral_credit_amount(): float {
		global $wpdb;
		$table = LNKup_Treasury_Tables::credit_rules_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 10.0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$amount = $wpdb->get_var( $wpdb->prepare( "SELECT reward_amount FROM {$table} WHERE event_type = %s AND status = 'active' LIMIT 1", 'first_invoice_paid' ) );
		return null === $amount ? 10.0 : (float) $amount;
	}

	/**
	 * @param array<string, mixed> $row Columns.
	 */
	private static function insert( array $row ): int {
		global $wpdb;
		$row = array_merge(
			array(
				'created_by' => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
			),
			$row
		);
		$wpdb->insert( self::table(), $row );
		return (int) $wpdb->insert_id;
	}

	// ------------------------------------------------------------------ Lookup.

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $rule_id ): ?array {
		global $wpdb;
		if ( $rule_id <= 0 || ! LNKup_Partner_Tables::exists( LNKup_Partner_Tables::RULES ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $rule_id ), ARRAY_A );
		return $row ? self::resolved( $row ) : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_rules( string $program = '' ): array {
		global $wpdb;
		if ( ! LNKup_Partner_Tables::exists( LNKup_Partner_Tables::RULES ) ) {
			return array();
		}
		if ( $program ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE program = %s ORDER BY version DESC', sanitize_key( $program ) ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY program ASC, version DESC', ARRAY_A );
		}
		return array_map( array( __CLASS__, 'present' ), (array) $rows );
	}

	public static function latest_version( string $program ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(version) FROM ' . self::table() . ' WHERE program = %s', $program ) );
	}

	/**
	 * Rule in force for a program at a point in time (published rules only).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function active_rule( string $program, string $at = '' ): ?array {
		global $wpdb;
		if ( ! LNKup_Partner_Tables::exists( LNKup_Partner_Tables::RULES ) ) {
			return null;
		}
		$at = $at ? $at : current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE program = %s AND status IN ('active','superseded')
				AND effective_from IS NOT NULL AND effective_from <= %s AND (effective_to IS NULL OR effective_to > %s)
				ORDER BY effective_from DESC, version DESC LIMIT 1",
				sanitize_key( $program ),
				$at,
				$at
			),
			ARRAY_A
		);
		return $row ? self::resolved( $row ) : null;
	}

	/**
	 * Rule a connector earns under. Locked on first resolution.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function rule_for_connector( int $connector_id ): ?array {
		$connector = class_exists( 'LNKup_Connector' ) ? LNKup_Connector::get( $connector_id ) : null;
		if ( ! $connector ) {
			return null;
		}
		$program = LNKup_Connector::program_of( $connector );
		// A strategic organization's agreement rule takes precedence for its contacts.
		$agreement = LNKup_Partner_Programs::STRATEGIC_PARTNER === $program ? LNKup_Connector::agreement_rule_id( $connector ) : 0;
		if ( $agreement ) {
			$rule = self::get( $agreement );
			if ( $rule ) {
				return $rule;
			}
		}
		$locked = (int) ( $connector['commission_rule_id'] ?? 0 );
		if ( $locked ) {
			$rule = self::get( $locked );
			if ( $rule && ( 'draft' !== $rule['status'] || LNKup_Partner_Programs::CITY_CONNECTOR === $program ) ) {
				return $rule;
			}
		}
		$anchor = (string) ( $connector['approved_at'] ?? '' );
		if ( ! $anchor ) {
			$anchor = (string) ( $connector['created_at'] ?? '' );
		}
		$rule = self::active_rule( $program, $anchor ?: current_time( 'mysql' ) );
		if ( ! $rule && LNKup_Partner_Programs::CITY_CONNECTOR !== $program ) {
			// Accounts approved while terms were still a draft lock the first rule published after them.
			$rule = self::active_rule( $program );
		}
		if ( $rule && 'active' === ( $connector['status'] ?? '' ) && array_key_exists( 'commission_rule_id', $connector ) ) {
			global $wpdb;
			$wpdb->update( LNKup_Connector_Tables::connectors_table(), array( 'commission_rule_id' => (int) $rule['id'] ), array( 'id' => $connector_id ) );
		}
		return $rule;
	}

	/**
	 * Rule a referral row earns under. Locked on first resolution.
	 *
	 * @param array<string, mixed> $referral Referral row.
	 * @return array<string, mixed>|null
	 */
	public static function rule_for_referral( array $referral ): ?array {
		$locked = (int) ( $referral['rule_id'] ?? 0 );
		if ( $locked ) {
			$rule = self::get( $locked );
			if ( $rule ) {
				return $rule;
			}
		}
		$program = (string) ( $referral['program'] ?? '' );
		$rule    = null;
		if ( LNKup_Partner_Programs::is_account_program( $program ) ) {
			$connector_id = self::connector_for_referral( $referral );
			$rule         = $connector_id ? self::rule_for_connector( $connector_id ) : null;
		}
		if ( ! $rule && ( ! LNKup_Partner_Programs::is_account_program( $program ) || LNKup_Partner_Programs::CITY_CONNECTOR === $program ) ) {
			$rule = self::active_rule( $program ?: LNKup_Partner_Programs::VENDOR_REFERRAL, (string) ( $referral['created_at'] ?? '' ) );
		}
		if ( $rule && 'draft' === $rule['status'] ) {
			return $rule;
		}
		if ( $rule && ! empty( $referral['id'] ) && array_key_exists( 'rule_id', $referral ) ) {
			global $wpdb;
			$wpdb->update( LNKup_Treasury_Tables::referrals_table(), array( 'rule_id' => (int) $rule['id'] ), array( 'id' => (int) $referral['id'] ) );
		}
		return $rule;
	}

	/**
	 * @param array<string, mixed> $referral Referral row.
	 */
	public static function connector_for_referral( array $referral ): int {
		global $wpdb;
		$code_id = (int) ( $referral['referral_code_id'] ?? 0 );
		if ( $code_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT connector_id FROM ' . LNKup_Treasury_Tables::referral_codes_table() . ' WHERE id = %d', $code_id ) );
			if ( $cid ) {
				return $cid;
			}
		}
		return (int) get_user_meta( (int) ( $referral['referred_user_id'] ?? 0 ), '_lnkup_referred_by_connector_id', true );
	}

	/**
	 * Overlay live legacy settings onto settings-sourced v1 rules.
	 *
	 * @param array<string, mixed> $row Rule row.
	 * @return array<string, mixed>
	 */
	public static function resolved( array $row ): array {
		if ( 'settings' !== ( $row['source'] ?? '' ) ) {
			return $row;
		}
		$s = lnkup_get_settings();
		switch ( (string) $row['program'] ) {
			case LNKup_Partner_Programs::CITY_CONNECTOR:
				$row['rate']        = max( 0, min( 100, (float) ( $s['connector_booking_share_percent'] ?? $row['rate'] ) ) );
				$row['cap']         = max( 0, (float) ( $s['connector_booking_share_cap'] ?? $row['cap'] ) );
				$row['term_months'] = max( 1, min( 36, (int) ( $s['connector_reward_term_months'] ?? $row['term_months'] ) ) );
				$row['hold_days']   = max( 0, min( 180, (int) ( $s['connector_payout_hold_days'] ?? $row['hold_days'] ) ) );
				break;
			case LNKup_Partner_Programs::ORGANIZER_RECRUIT:
				if ( class_exists( 'LNKup_Organizer_Recruit_Referrals' ) ) {
					$row['rate']        = LNKup_Organizer_Recruit_Referrals::commission_percent();
					$row['term_months'] = LNKup_Organizer_Recruit_Referrals::window_months();
				}
				break;
		}
		return $row;
	}

	// ------------------------------------------------------------------ Calculation.

	/**
	 * Commission for one completed payment under a rule.
	 *
	 * @param array<string, mixed> $rule Rule.
	 * @return array{amount: float, basis: string, basis_amount: float, rule_id: int, rule_version: int}
	 */
	public static function calculate( array $rule, int $payment_id ): array {
		$basis = (string) ( $rule['basis'] ?? 'platform_fee' );
		$base  = 0.0;
		if ( 'booth_gmv' === $basis ) {
			$base = class_exists( 'LNKup_Connector_Rewards' ) ? LNKup_Connector_Rewards::booth_gmv( $payment_id ) : 0.0;
		} elseif ( 'platform_fee' === $basis ) {
			$base = self::platform_fee_for_payment( $payment_id );
		}
		return array(
			'amount'       => self::amount_from_base( $rule, $base ),
			'basis'        => $basis,
			'basis_amount' => round( $base, 2 ),
			'rule_id'      => (int) ( $rule['id'] ?? 0 ),
			'rule_version' => (int) ( $rule['version'] ?? 0 ),
		);
	}

	/**
	 * @param array<string, mixed> $rule Rule.
	 */
	public static function amount_from_base( array $rule, float $base ): float {
		if ( 'fixed' === ( $rule['basis'] ?? '' ) ) {
			return round( max( 0.0, (float) $rule['fixed_amount'] ), 2 );
		}
		$rate = max( 0.0, min( 100.0, (float) ( $rule['rate'] ?? 0 ) ) );
		if ( $base <= 0 || $rate <= 0 ) {
			return 0.0;
		}
		$amount = round( $base * ( $rate / 100 ), 2 );
		$cap    = (float) ( $rule['cap'] ?? 0 );
		return $cap > 0 ? min( $amount, $cap ) : $amount;
	}

	/**
	 * LNKup's take on a payment. Uses the same resolver the referral-invite program and its
	 * reversals already use, falling back to the platform fee ledger.
	 */
	public static function platform_fee_for_payment( int $payment_id ): float {
		if ( class_exists( 'LNKup_Organizer_Recruit_Referrals' ) ) {
			$fee = LNKup_Organizer_Recruit_Referrals::resolve_platform_fee_for_payment( $payment_id );
			if ( $fee > 0 ) {
				return $fee;
			}
		}
		global $wpdb;
		$table = LNKup_Treasury_Tables::platform_fees_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0.0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$fee = $wpdb->get_var( $wpdb->prepare( "SELECT lnkup_platform_fee FROM {$table} WHERE payment_post_id = %d ORDER BY id DESC LIMIT 1", $payment_id ) );
		return round( max( 0.0, (float) $fee ), 2 );
	}

	/**
	 * Planner estimate for a booth price (never payable).
	 *
	 * @param array<string, mixed> $rule Rule.
	 */
	public static function estimate_per_booking( array $rule, float $booth_price ): float {
		$basis = (string) ( $rule['basis'] ?? '' );
		if ( 'booth_gmv' === $basis ) {
			return self::amount_from_base( $rule, $booth_price );
		}
		if ( 'platform_fee' === $basis ) {
			$fee = class_exists( 'LNKup_Fee_Engine' ) ? (float) LNKup_Fee_Engine::resolve_platform_fee( $booth_price ) : 0.0;
			return self::amount_from_base( $rule, $fee );
		}
		return self::amount_from_base( $rule, 0.0 );
	}

	/**
	 * Plain-language description of a rule's basis.
	 *
	 * @param array<string, mixed>|null $rule Rule.
	 */
	public static function basis_label( ?array $rule ): string {
		if ( ! $rule ) {
			return '';
		}
		$rate = rtrim( rtrim( number_format( (float) $rule['rate'], 2 ), '0' ), '.' );
		switch ( (string) $rule['basis'] ) {
			case 'booth_gmv':
				/* translators: %s: percent */
				return sprintf( __( '%s%% of the booth price on qualifying bookings', 'lnkup-marketplace' ), $rate );
			case 'platform_fee':
				/* translators: %s: percent */
				return sprintf( __( '%s%% of LNKup fees on qualifying bookings', 'lnkup-marketplace' ), $rate );
			default:
				/* translators: %s: amount */
				return sprintf( __( '%s per qualifying milestone', 'lnkup-marketplace' ), '$' . number_format( (float) $rule['fixed_amount'], 2 ) );
		}
	}

	/**
	 * Public shape for REST / admin.
	 *
	 * @param array<string, mixed> $row Rule row.
	 * @return array<string, mixed>
	 */
	public static function present( array $row ): array {
		$row = self::resolved( $row );
		return array(
			'id'                => (int) $row['id'],
			'program'           => (string) $row['program'],
			'program_label'     => LNKup_Partner_Programs::label( (string) $row['program'] ),
			'version'           => (int) $row['version'],
			'name'              => (string) $row['name'],
			'source'            => (string) $row['source'],
			'basis'             => (string) $row['basis'],
			'basis_label'       => self::basis_label( $row ),
			'rate'              => (float) $row['rate'],
			'fixed_amount'      => (float) $row['fixed_amount'],
			'cap'               => (float) $row['cap'],
			'term_months'       => (int) $row['term_months'],
			'hold_days'         => (int) $row['hold_days'],
			'qualifying_event'  => (string) $row['qualifying_event'],
			'target_role'       => (string) $row['target_role'],
			'stacking_policy'   => (string) $row['stacking_policy'],
			'requires_approval' => (bool) (int) $row['requires_approval'],
			'agreement_ref'     => (string) $row['agreement_ref'],
			'status'            => (string) $row['status'],
			'effective_from'    => (string) ( $row['effective_from'] ?? '' ),
			'effective_to'      => (string) ( $row['effective_to'] ?? '' ),
			'created_at'        => (string) $row['created_at'],
		);
	}

	// ------------------------------------------------------------------ Versioning.

	/**
	 * Create a draft version. Published rows are never edited.
	 *
	 * @param array<string, mixed> $input Raw fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_version( string $program, array $input, int $actor = 0 ) {
		$program = sanitize_key( $program );
		if ( ! LNKup_Partner_Programs::get( $program ) ) {
			return new WP_Error( 'lnkup_rule_program', __( 'Unknown partner program.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$basis = sanitize_key( (string) ( $input['basis'] ?? 'platform_fee' ) );
		if ( ! in_array( $basis, self::BASES, true ) ) {
			return new WP_Error( 'lnkup_rule_basis', __( 'Choose a booth price, LNKup fee or fixed basis.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$stacking = sanitize_key( (string) ( $input['stacking_policy'] ?? 'exclusive' ) );
		$row      = array(
			'program'           => $program,
			'version'           => self::latest_version( $program ) + 1,
			'name'              => substr( sanitize_text_field( (string) ( $input['name'] ?? '' ) ), 0, 100 ),
			'source'            => 'custom',
			'basis'             => $basis,
			'rate'              => max( 0, min( 100, (float) ( $input['rate'] ?? 0 ) ) ),
			'fixed_amount'      => max( 0, (float) ( $input['fixed_amount'] ?? 0 ) ),
			'cap'               => max( 0, (float) ( $input['cap'] ?? 0 ) ),
			'term_months'       => max( 0, min( 60, (int) ( $input['term_months'] ?? 12 ) ) ),
			'hold_days'         => max( 0, min( 180, (int) ( $input['hold_days'] ?? 30 ) ) ),
			'qualifying_event'  => sanitize_key( (string) ( $input['qualifying_event'] ?? 'paid_booking' ) ) ?: 'paid_booking',
			'target_role'       => in_array( (string) ( $input['target_role'] ?? '' ), array( '', 'organizer', 'vendor' ), true ) ? (string) ( $input['target_role'] ?? '' ) : '',
			'stacking_policy'   => in_array( $stacking, self::STACKING, true ) ? $stacking : 'exclusive',
			'requires_approval' => empty( $input['requires_approval'] ) ? 0 : 1,
			'agreement_ref'     => substr( sanitize_text_field( (string) ( $input['agreement_ref'] ?? '' ) ), 0, 191 ),
			'status'            => 'draft',
			'created_by'        => $actor ?: get_current_user_id(),
		);
		$id = self::insert( $row );
		if ( ! $id ) {
			return new WP_Error( 'lnkup_rule_save', __( 'The rule could not be saved.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		LNKup_Financial_Audit::log( 'commission_rule_created', 'commission_rule', $id, array( 'program' => $program, 'version' => $row['version'], 'basis' => $basis, 'rate' => $row['rate'] ) );
		return self::present( (array) self::get( $id ) );
	}

	/**
	 * Publish a draft. The previously active version is superseded but keeps paying anyone locked to it.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function publish( int $rule_id, int $actor = 0 ) {
		global $wpdb;
		$rule = self::get( $rule_id );
		if ( ! $rule || 'draft' !== $rule['status'] ) {
			return new WP_Error( 'lnkup_rule_state', __( 'Only draft rules can be published.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$valued = 'fixed' === $rule['basis'] ? (float) $rule['fixed_amount'] > 0 : (float) $rule['rate'] > 0;
		if ( ! $valued ) {
			return new WP_Error( 'lnkup_rule_value', __( 'Set a rate or fixed amount before publishing.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$now   = current_time( 'mysql' );
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'superseded', effective_to = %s WHERE program = %s AND status = 'active'", $now, $rule['program'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'active', effective_from = %s WHERE id = %d AND status = 'draft'", $now, $rule_id ) );
		if ( ! $updated ) {
			return new WP_Error( 'lnkup_rule_state', __( 'The rule changed before it could be published.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		LNKup_Financial_Audit::log( 'commission_rule_published', 'commission_rule', $rule_id, array( 'program' => $rule['program'], 'version' => (int) $rule['version'], 'actor_user_id' => $actor ?: get_current_user_id() ) );
		return self::present( (array) self::get( $rule_id ) );
	}
}
