<?php
/**
 * Treasury platform database tables.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Treasury_Tables {

	public static function organizer_wallets_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_organizer_wallets';
	}

	public static function organizer_wallet_entries_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_organizer_wallet_entries';
	}

	public static function organizer_payout_accounts_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_organizer_payout_accounts';
	}

	public static function organizer_payouts_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_organizer_payouts';
	}

	public static function platform_fees_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_platform_fees';
	}

	public static function escrow_holds_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_escrow_holds';
	}

	public static function reserve_holds_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_reserve_holds';
	}

	public static function referral_codes_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_referral_codes';
	}

	public static function referrals_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_referrals';
	}

	public static function referral_rewards_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_referral_rewards';
	}

	public static function credit_rules_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_credit_rules';
	}

	public static function sponsor_invoices_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_sponsor_invoices';
	}

	public static function sponsor_payments_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_sponsor_payments';
	}

	public static function subscription_plans_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_subscription_plans';
	}

	public static function subscriptions_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_subscriptions';
	}

	public static function premium_listings_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_premium_listings';
	}

	public static function tax_documents_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_tax_documents';
	}

	public static function tax_exports_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_tax_exports';
	}

	public static function financial_reports_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_financial_reports';
	}

	public static function event_budgets_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_event_budgets';
	}

	public static function event_budget_items_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_event_budget_items';
	}

	public static function cashflow_forecasts_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_cashflow_forecasts';
	}

	public static function financing_offers_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_financing_offers';
	}

	public static function insurance_offers_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_insurance_offers';
	}

	public static function financial_approvals_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_financial_approvals';
	}

	public static function refund_rules_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_refund_rules';
	}

	public static function financial_health_scores_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_financial_health_scores';
	}

	/**
	 * Create treasury tables via dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$ow = self::organizer_wallets_table();
		dbDelta(
			"CREATE TABLE {$ow} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				available_balance decimal(14,2) NOT NULL DEFAULT 0.00,
				pending_balance decimal(14,2) NOT NULL DEFAULT 0.00,
				processing_balance decimal(14,2) NOT NULL DEFAULT 0.00,
				lifetime_paid_out decimal(14,2) NOT NULL DEFAULT 0.00,
				reserve_hold decimal(14,2) NOT NULL DEFAULT 0.00,
				currency varchar(3) NOT NULL DEFAULT 'USD',
				updated_at datetime NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY user_id (user_id)
			) {$charset};"
		);

		$owe = self::organizer_wallet_entries_table();
		dbDelta(
			"CREATE TABLE {$owe} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				wallet_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				entry_type varchar(32) NOT NULL DEFAULT 'credit',
				amount decimal(14,2) NOT NULL DEFAULT 0.00,
				balance_after decimal(14,2) NOT NULL DEFAULT 0.00,
				source_type varchar(32) NOT NULL DEFAULT '',
				source_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payment_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payout_id bigint(20) unsigned NOT NULL DEFAULT 0,
				note varchar(255) NOT NULL DEFAULT '',
				metadata longtext,
				idempotency_key varchar(96) DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY wallet_id (wallet_id),
				KEY user_id (user_id),
				KEY source (source_type, source_id),
				KEY payment_post_id (payment_post_id),
				UNIQUE KEY idempotency_key (idempotency_key)
			) {$charset};"
		);

		$opa = self::organizer_payout_accounts_table();
		dbDelta(
			"CREATE TABLE {$opa} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				account_type varchar(32) NOT NULL DEFAULT 'stripe_connect',
				external_id varchar(128) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT 'pending',
				is_default tinyint(1) NOT NULL DEFAULT 1,
				metadata longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id)
			) {$charset};"
		);

		$op = self::organizer_payouts_table();
		dbDelta(
			"CREATE TABLE {$op} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				amount decimal(14,2) NOT NULL DEFAULT 0.00,
				fee_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				net_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				currency varchar(3) NOT NULL DEFAULT 'USD',
				payout_type varchar(32) NOT NULL DEFAULT 'standard',
				status varchar(32) NOT NULL DEFAULT 'requested',
				provider varchar(32) NOT NULL DEFAULT 'manual',
				provider_ref varchar(128) NOT NULL DEFAULT '',
				scheduled_at datetime DEFAULT NULL,
				paid_at datetime DEFAULT NULL,
				failure_reason text,
				metadata longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id),
				KEY status (status)
			) {$charset};"
		);

		$pf = self::platform_fees_table();
		dbDelta(
			"CREATE TABLE {$pf} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				payment_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				transaction_id bigint(20) unsigned NOT NULL DEFAULT 0,
				gross_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				vendor_fee decimal(14,2) NOT NULL DEFAULT 0.00,
				organizer_net decimal(14,2) NOT NULL DEFAULT 0.00,
				lnkup_platform_fee decimal(14,2) NOT NULL DEFAULT 0.00,
				refunded_platform_fee decimal(14,2) NOT NULL DEFAULT 0.00,
				processing_fee decimal(14,2) NOT NULL DEFAULT 0.00,
				tax_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				discount_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				credit_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				reserve_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				payout_eligible_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				fee_rule_id bigint(20) unsigned NOT NULL DEFAULT 0,
				metadata longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY payment_post_id (payment_post_id)
			) {$charset};"
		);

		$eh = self::escrow_holds_table();
		dbDelta(
			"CREATE TABLE {$eh} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				payment_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				amount decimal(14,2) NOT NULL DEFAULT 0.00,
				released_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				status varchar(32) NOT NULL DEFAULT 'held',
				hold_reason varchar(64) NOT NULL DEFAULT '',
				release_at datetime DEFAULT NULL,
				released_at datetime DEFAULT NULL,
				metadata longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY payment_post_id (payment_post_id),
				KEY status (status)
			) {$charset};"
		);

		$rh = self::reserve_holds_table();
		dbDelta(
			"CREATE TABLE {$rh} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payment_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				amount decimal(14,2) NOT NULL DEFAULT 0.00,
				used_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				status varchar(32) NOT NULL DEFAULT 'active',
				expires_at datetime DEFAULT NULL,
				released_at datetime DEFAULT NULL,
				metadata longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY organizer_user_id (organizer_user_id),
				KEY event_id (event_id)
			) {$charset};"
		);

		$rc = self::referral_codes_table();
		dbDelta(
			"CREATE TABLE {$rc} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				code varchar(32) NOT NULL DEFAULT '',
				role_type varchar(16) NOT NULL DEFAULT 'vendor',
				program varchar(32) NOT NULL DEFAULT 'vendor_referral',
				market_slug varchar(64) NOT NULL DEFAULT '',
				connector_id bigint(20) unsigned NOT NULL DEFAULT 0,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY code (code),
				KEY user_id (user_id),
				KEY connector_id (connector_id)
			) {$charset};"
		);

		$ref = self::referrals_table();
		dbDelta(
			"CREATE TABLE {$ref} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				referrer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				referred_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				referral_code_id bigint(20) unsigned NOT NULL DEFAULT 0,
				program varchar(32) NOT NULL DEFAULT 'vendor_referral',
				market_slug varchar(64) NOT NULL DEFAULT '',
				target_role varchar(16) NOT NULL DEFAULT '',
				source_url varchar(255) NOT NULL DEFAULT '',
				signup_source varchar(32) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT 'pending',
				reward_status varchar(32) NOT NULL DEFAULT 'none',
				attributed_revenue decimal(14,2) NOT NULL DEFAULT 0.00,
				profile_completed_at datetime DEFAULT NULL,
				first_application_at datetime DEFAULT NULL,
				first_payment_at datetime DEFAULT NULL,
				first_event_at datetime DEFAULT NULL,
				first_opportunity_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				converted_at datetime DEFAULT NULL,
				PRIMARY KEY (id),
				KEY referrer_user_id (referrer_user_id),
				KEY referred_user_id (referred_user_id),
				KEY program (program)
			) {$charset};"
		);

		$rr = self::referral_rewards_table();
		dbDelta(
			"CREATE TABLE {$rr} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				referral_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				connector_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_type varchar(64) NOT NULL DEFAULT '',
				amount decimal(14,2) NOT NULL DEFAULT 0.00,
				reversed_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				status varchar(32) NOT NULL DEFAULT 'pending',
				reward_status varchar(32) NOT NULL DEFAULT 'pending',
				credit_id bigint(20) unsigned NOT NULL DEFAULT 0,
				approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				approved_at datetime DEFAULT NULL,
				paid_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				available_at datetime DEFAULT NULL,
				payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				program varchar(32) NOT NULL DEFAULT '',
				PRIMARY KEY (id),
				KEY user_id (user_id),
				KEY connector_id (connector_id),
				KEY status (status),
				KEY payment_referral (payment_id, referral_id),
				KEY program (program)
			) {$charset};"
		);

		$cr = self::credit_rules_table();
		dbDelta(
			"CREATE TABLE {$cr} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_type varchar(64) NOT NULL DEFAULT '',
				role_type varchar(16) NOT NULL DEFAULT 'vendor',
				reward_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				reward_percent decimal(6,2) NOT NULL DEFAULT 0.00,
				delay_days int NOT NULL DEFAULT 0,
				status varchar(16) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY event_type (event_type)
			) {$charset};"
		);

		$si = self::sponsor_invoices_table();
		dbDelta(
			"CREATE TABLE {$si} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				invoice_number varchar(32) NOT NULL DEFAULT '',
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				sponsor_name varchar(255) NOT NULL DEFAULT '',
				sponsor_email varchar(255) NOT NULL DEFAULT '',
				package_name varchar(128) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT 'draft',
				total_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				paid_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				due_date date DEFAULT NULL,
				notes text,
				metadata longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY invoice_number (invoice_number),
				KEY organizer_user_id (organizer_user_id)
			) {$charset};"
		);

		$sp = self::sponsor_payments_table();
		dbDelta(
			"CREATE TABLE {$sp} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				sponsor_invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
				amount decimal(14,2) NOT NULL DEFAULT 0.00,
				status varchar(32) NOT NULL DEFAULT 'pending',
				payment_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				paid_at datetime DEFAULT NULL,
				metadata longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY sponsor_invoice_id (sponsor_invoice_id)
			) {$charset};"
		);

		$spl = self::subscription_plans_table();
		dbDelta(
			"CREATE TABLE {$spl} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(64) NOT NULL DEFAULT '',
				name varchar(128) NOT NULL DEFAULT '',
				role_type varchar(16) NOT NULL DEFAULT 'vendor',
				price_monthly decimal(14,2) NOT NULL DEFAULT 0.00,
				price_yearly decimal(14,2) NOT NULL DEFAULT 0.00,
				features_json longtext,
				stripe_price_id varchar(128) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY slug (slug)
			) {$charset};"
		);

		$sub = self::subscriptions_table();
		dbDelta(
			"CREATE TABLE {$sub} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(32) NOT NULL DEFAULT 'active',
				provider varchar(32) NOT NULL DEFAULT 'manual',
				provider_ref varchar(128) NOT NULL DEFAULT '',
				current_period_start datetime DEFAULT NULL,
				current_period_end datetime DEFAULT NULL,
				cancelled_at datetime DEFAULT NULL,
				metadata longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id),
				KEY status (status)
			) {$charset};"
		);

		$pl = self::premium_listings_table();
		dbDelta(
			"CREATE TABLE {$pl} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				role_type varchar(16) NOT NULL DEFAULT 'vendor',
				listing_type varchar(64) NOT NULL DEFAULT '',
				entity_type varchar(32) NOT NULL DEFAULT '',
				entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(32) NOT NULL DEFAULT 'pending',
				amount_paid decimal(14,2) NOT NULL DEFAULT 0.00,
				starts_at datetime DEFAULT NULL,
				ends_at datetime DEFAULT NULL,
				impressions int NOT NULL DEFAULT 0,
				clicks int NOT NULL DEFAULT 0,
				conversions int NOT NULL DEFAULT 0,
				metadata longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id),
				KEY status (status)
			) {$charset};"
		);

		$td = self::tax_documents_table();
		dbDelta(
			"CREATE TABLE {$td} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				doc_type varchar(32) NOT NULL DEFAULT 'w9',
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(32) NOT NULL DEFAULT 'pending',
				year int NOT NULL DEFAULT 0,
				metadata longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id)
			) {$charset};"
		);

		$te = self::tax_exports_table();
		dbDelta(
			"CREATE TABLE {$te} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				export_type varchar(32) NOT NULL DEFAULT 'csv',
				year int NOT NULL DEFAULT 0,
				file_path varchar(255) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT 'completed',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id)
			) {$charset};"
		);

		$fr = self::financial_reports_table();
		dbDelta(
			"CREATE TABLE {$fr} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				report_type varchar(64) NOT NULL DEFAULT '',
				scope_type varchar(32) NOT NULL DEFAULT 'user',
				scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
				period_start date DEFAULT NULL,
				period_end date DEFAULT NULL,
				payload longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY report_type (report_type),
				KEY scope (scope_type, scope_id)
			) {$charset};"
		);

		$eb = self::event_budgets_table();
		dbDelta(
			"CREATE TABLE {$eb} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				projected_revenue decimal(14,2) NOT NULL DEFAULT 0.00,
				actual_revenue decimal(14,2) NOT NULL DEFAULT 0.00,
				projected_expense decimal(14,2) NOT NULL DEFAULT 0.00,
				actual_expense decimal(14,2) NOT NULL DEFAULT 0.00,
				currency varchar(3) NOT NULL DEFAULT 'USD',
				notes text,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY event_id (event_id)
			) {$charset};"
		);

		$ebi = self::event_budget_items_table();
		dbDelta(
			"CREATE TABLE {$ebi} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				budget_id bigint(20) unsigned NOT NULL DEFAULT 0,
				category varchar(64) NOT NULL DEFAULT '',
				label varchar(128) NOT NULL DEFAULT '',
				projected_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				actual_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				sort_order int NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY budget_id (budget_id)
			) {$charset};"
		);

		$cf = self::cashflow_forecasts_table();
		dbDelta(
			"CREATE TABLE {$cf} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				role_type varchar(16) NOT NULL DEFAULT 'organizer',
				forecast_date date NOT NULL,
				expected_inflow decimal(14,2) NOT NULL DEFAULT 0.00,
				expected_outflow decimal(14,2) NOT NULL DEFAULT 0.00,
				overdue_amount decimal(14,2) NOT NULL DEFAULT 0.00,
				risk_level varchar(16) NOT NULL DEFAULT 'low',
				metadata longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id),
				KEY forecast_date (forecast_date)
			) {$charset};"
		);

		$fo = self::financing_offers_table();
		dbDelta(
			"CREATE TABLE {$fo} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				role_type varchar(16) NOT NULL DEFAULT 'vendor',
				offer_type varchar(64) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT 'inquiry',
				amount_requested decimal(14,2) NOT NULL DEFAULT 0.00,
				referral_commission decimal(14,2) NOT NULL DEFAULT 0.00,
				metadata longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id)
			) {$charset};"
		);

		$io = self::insurance_offers_table();
		dbDelta(
			"CREATE TABLE {$io} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				insurance_type varchar(64) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT 'viewed',
				referral_commission decimal(14,2) NOT NULL DEFAULT 0.00,
				clicked_at datetime DEFAULT NULL,
				metadata longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id)
			) {$charset};"
		);

		$fa = self::financial_approvals_table();
		dbDelta(
			"CREATE TABLE {$fa} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				approval_type varchar(64) NOT NULL DEFAULT '',
				entity_type varchar(32) NOT NULL DEFAULT '',
				entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
				amount decimal(14,2) NOT NULL DEFAULT 0.00,
				status varchar(32) NOT NULL DEFAULT 'pending',
				reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				review_note varchar(255) NOT NULL DEFAULT '',
				metadata longtext,
				created_at datetime NOT NULL,
				reviewed_at datetime DEFAULT NULL,
				PRIMARY KEY (id),
				KEY status (status),
				KEY entity (entity_type, entity_id)
			) {$charset};"
		);

		$rrl = self::refund_rules_table();
		dbDelta(
			"CREATE TABLE {$rrl} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				rule_key varchar(64) NOT NULL DEFAULT '',
				label varchar(128) NOT NULL DEFAULT '',
				refund_type varchar(32) NOT NULL DEFAULT 'full',
				refund_percent decimal(6,2) NOT NULL DEFAULT 100.00,
				requires_approval tinyint(1) NOT NULL DEFAULT 0,
				priority int NOT NULL DEFAULT 100,
				status varchar(16) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY rule_key (rule_key)
			) {$charset};"
		);

		$fhs = self::financial_health_scores_table();
		dbDelta(
			"CREATE TABLE {$fhs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				role_type varchar(16) NOT NULL DEFAULT 'vendor',
				score int NOT NULL DEFAULT 0,
				risk_level varchar(16) NOT NULL DEFAULT 'medium',
				reasons_json longtext,
				computed_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id),
				KEY computed_at (computed_at)
			) {$charset};"
		);

		self::seed_defaults();
	}

	/**
	 * Seed default refund and credit rules.
	 */
	public static function seed_defaults() {
		global $wpdb;

		$refund_rules = self::refund_rules_table();
		if ( LNKup_Financial_Tables::table_exists( $refund_rules ) ) {
			$defaults = array(
				array( 'rejected_application', __( 'Rejected application', 'lnkup-marketplace' ), 'full', 100 ),
				array( 'organizer_cancelled', __( 'Organizer cancelled event', 'lnkup-marketplace' ), 'full', 100 ),
				array( 'vendor_cancel_early', __( 'Vendor cancel before deadline', 'lnkup-marketplace' ), 'partial', 50 ),
				array( 'vendor_cancel_late', __( 'Vendor cancel after deadline', 'lnkup-marketplace' ), 'fee-only', 0 ),
				array( 'no_show', __( 'No show', 'lnkup-marketplace' ), 'fee-only', 0 ),
				array( 'duplicate_payment', __( 'Duplicate payment', 'lnkup-marketplace' ), 'full', 100 ),
			);
			foreach ( $defaults as $row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$refund_rules} WHERE rule_key = %s", $row[0] ) );
				if ( ! $exists ) {
					$wpdb->insert(
						$refund_rules,
						array(
							'rule_key'       => $row[0],
							'label'          => $row[1],
							'refund_type'    => $row[2],
							'refund_percent' => $row[3],
							'status'         => 'active',
							'created_at'     => current_time( 'mysql' ),
						)
					);
				}
			}
		}

		$credit_rules = self::credit_rules_table();
		if ( LNKup_Financial_Tables::table_exists( $credit_rules ) ) {
			$credit_defaults = array(
				array( 'user_registered', 'vendor', 10 ),
				array( 'profile_complete', 'vendor', 15 ),
				array( 'first_invoice_paid', 'vendor', 25 ),
				array( 'first_event_created', 'organizer', 25 ),
				array( 'first_paid_vendor', 'organizer', 50 ),
			);
			foreach ( $credit_defaults as $row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$credit_rules} WHERE event_type = %s AND role_type = %s", $row[0], $row[1] ) );
				if ( ! $exists ) {
					$wpdb->insert(
						$credit_rules,
						array(
							'event_type'    => $row[0],
							'role_type'     => $row[1],
							'reward_amount' => $row[2],
							'status'        => 'active',
							'created_at'    => current_time( 'mysql' ),
						)
					);
				}
			}
		}

		$plans = self::subscription_plans_table();
		if ( LNKup_Financial_Tables::table_exists( $plans ) ) {
			$plan_defaults = array(
				array( 'vendor-free', __( 'Vendor Free', 'lnkup-marketplace' ), 'vendor', 0, 0 ),
				array( 'vendor-pro', __( 'Vendor Pro', 'lnkup-marketplace' ), 'vendor', 29, 290 ),
				array( 'organizer-free', __( 'Organizer Free', 'lnkup-marketplace' ), 'organizer', 0, 0 ),
				array( 'organizer-pro', __( 'Organizer Pro', 'lnkup-marketplace' ), 'organizer', 49, 490 ),
				array( 'agency', __( 'Agency', 'lnkup-marketplace' ), 'organizer', 99, 990 ),
				array( 'enterprise', __( 'Enterprise', 'lnkup-marketplace' ), 'organizer', 199, 1990 ),
			);
			foreach ( $plan_defaults as $row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$plans} WHERE slug = %s", $row[0] ) );
				if ( ! $exists ) {
					$wpdb->insert(
						$plans,
						array(
							'slug'          => $row[0],
							'name'          => $row[1],
							'role_type'     => $row[2],
							'price_monthly' => $row[3],
							'price_yearly'  => $row[4],
							'status'        => 'active',
							'created_at'    => current_time( 'mysql' ),
						)
					);
				}
			}
		}
	}
}
