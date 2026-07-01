<?php
/**
 * Financial platform custom database tables.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Tables {

	public static function transactions_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_transactions';
	}

	public static function invoices_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_invoices';
	}

	public static function invoice_items_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_invoice_items';
	}

	public static function installment_plans_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_installment_plans';
	}

	public static function installments_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_installments';
	}

	public static function deposits_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_deposits';
	}

	public static function payment_methods_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_payment_methods';
	}

	public static function wallets_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_wallets';
	}

	public static function wallet_entries_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_wallet_entries';
	}

	public static function credits_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_credits';
	}

	public static function refunds_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_refunds';
	}

	public static function payouts_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_payouts';
	}

	public static function disputes_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_disputes';
	}

	public static function receipts_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_receipts';
	}

	public static function promo_codes_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_promo_codes';
	}

	public static function promo_redemptions_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_promo_redemptions';
	}

	public static function tax_profiles_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_tax_profiles';
	}

	public static function audit_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_financial_audit';
	}

	public static function fraud_flags_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_fraud_flags';
	}

	public static function webhook_events_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_webhook_events';
	}

	public static function connected_accounts_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_connected_accounts';
	}

	public static function payment_timeline_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_payment_timeline';
	}

	public static function financial_activity_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_financial_activity';
	}

	public static function fee_rules_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_fee_rules';
	}

	/**
	 * Whether a custom table exists (safe before migrations run).
	 *
	 * @param string $table Full table name.
	 */
	public static function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t = self::transactions_table();
		dbDelta(
			"CREATE TABLE {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id varchar(32) NOT NULL DEFAULT '',
				type varchar(32) NOT NULL DEFAULT 'payment',
				status varchar(32) NOT NULL DEFAULT 'pending',
				amount decimal(12,2) NOT NULL DEFAULT 0.00,
				currency varchar(3) NOT NULL DEFAULT 'USD',
				vendor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payment_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
				provider varchar(32) NOT NULL DEFAULT '',
				provider_ref varchar(128) NOT NULL DEFAULT '',
				invoice_number varchar(32) NOT NULL DEFAULT '',
				payment_method varchar(64) NOT NULL DEFAULT '',
				stripe_customer_id varchar(128) NOT NULL DEFAULT '',
				stripe_payment_intent varchar(128) NOT NULL DEFAULT '',
				checkout_session_id varchar(128) NOT NULL DEFAULT '',
				platform_fee decimal(12,2) NOT NULL DEFAULT 0.00,
				vendor_fee decimal(12,2) NOT NULL DEFAULT 0.00,
				processing_fee decimal(12,2) NOT NULL DEFAULT 0.00,
				tax_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				discount_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				credit_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				refund_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				settlement_mode varchar(32) NOT NULL DEFAULT '',
				stripe_connect_account_id varchar(128) NOT NULL DEFAULT '',
				stripe_transfer_id varchar(128) NOT NULL DEFAULT '',
				stripe_application_fee_id varchar(128) NOT NULL DEFAULT '',
				gross_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				stripe_fee decimal(12,2) NOT NULL DEFAULT 0.00,
				organizer_net decimal(12,2) NOT NULL DEFAULT 0.00,
				reserve_hold decimal(12,2) NOT NULL DEFAULT 0.00,
				paid_at datetime DEFAULT NULL,
				metadata longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY public_id (public_id),
				KEY type_status (type, status),
				KEY vendor_user_id (vendor_user_id),
				KEY organizer_user_id (organizer_user_id),
				KEY payment_post_id (payment_post_id),
				KEY stripe_payment_intent (stripe_payment_intent),
				KEY created_at (created_at)
			) {$charset};"
		);

		$inv = self::invoices_table();
		dbDelta(
			"CREATE TABLE {$inv} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				invoice_number varchar(32) NOT NULL DEFAULT '',
				vendor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(32) NOT NULL DEFAULT 'draft',
				issue_date date DEFAULT NULL,
				due_date date DEFAULT NULL,
				subtotal decimal(12,2) NOT NULL DEFAULT 0.00,
				tax_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				discount_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				credit_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				total_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				paid_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				notes text,
				metadata longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY invoice_number (invoice_number),
				KEY vendor_user_id (vendor_user_id),
				KEY organizer_user_id (organizer_user_id),
				KEY status (status)
			) {$charset};"
		);

		$items = self::invoice_items_table();
		dbDelta(
			"CREATE TABLE {$items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
				description varchar(255) NOT NULL DEFAULT '',
				quantity decimal(10,2) NOT NULL DEFAULT 1.00,
				unit_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				tax_rate decimal(6,4) NOT NULL DEFAULT 0.0000,
				line_total decimal(12,2) NOT NULL DEFAULT 0.00,
				sort_order int NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY invoice_id (invoice_id)
			) {$charset};"
		);

		$plans = self::installment_plans_table();
		dbDelta(
			"CREATE TABLE {$plans} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				plan_type varchar(32) NOT NULL DEFAULT 'full',
				total_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				schedule_json longtext,
				status varchar(32) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY application_id (application_id)
			) {$charset};"
		);

		$inst = self::installments_table();
		dbDelta(
			"CREATE TABLE {$inst} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
				sequence_num int NOT NULL DEFAULT 1,
				amount decimal(12,2) NOT NULL DEFAULT 0.00,
				due_date date DEFAULT NULL,
				status varchar(32) NOT NULL DEFAULT 'pending',
				paid_at datetime DEFAULT NULL,
				payment_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY plan_id (plan_id),
				KEY due_date (due_date),
				KEY status (status)
			) {$charset};"
		);

		$dep = self::deposits_table();
		dbDelta(
			"CREATE TABLE {$dep} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				deposit_type varchar(16) NOT NULL DEFAULT 'fixed',
				deposit_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				deposit_percent decimal(6,2) NOT NULL DEFAULT 0.00,
				total_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				paid_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				remaining_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				deadline date DEFAULT NULL,
				refund_rules text,
				status varchar(32) NOT NULL DEFAULT 'required',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY application_id (application_id)
			) {$charset};"
		);

		$pm = self::payment_methods_table();
		dbDelta(
			"CREATE TABLE {$pm} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				provider varchar(32) NOT NULL DEFAULT 'stripe',
				provider_customer_id varchar(128) NOT NULL DEFAULT '',
				provider_method_id varchar(128) NOT NULL DEFAULT '',
				method_type varchar(32) NOT NULL DEFAULT 'card',
				brand varchar(32) NOT NULL DEFAULT '',
				last4 varchar(4) NOT NULL DEFAULT '',
				exp_month tinyint NOT NULL DEFAULT 0,
				exp_year smallint NOT NULL DEFAULT 0,
				is_default tinyint(1) NOT NULL DEFAULT 0,
				is_expired tinyint(1) NOT NULL DEFAULT 0,
				label varchar(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id)
			) {$charset};"
		);

		$w = self::wallets_table();
		dbDelta(
			"CREATE TABLE {$w} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				role_type varchar(16) NOT NULL DEFAULT 'vendor',
				balance decimal(12,2) NOT NULL DEFAULT 0.00,
				currency varchar(3) NOT NULL DEFAULT 'USD',
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY user_role (user_id, role_type)
			) {$charset};"
		);

		$we = self::wallet_entries_table();
		dbDelta(
			"CREATE TABLE {$we} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				wallet_id bigint(20) unsigned NOT NULL DEFAULT 0,
				entry_type varchar(32) NOT NULL DEFAULT 'credit',
				amount decimal(12,2) NOT NULL DEFAULT 0.00,
				balance_after decimal(12,2) NOT NULL DEFAULT 0.00,
				source varchar(64) NOT NULL DEFAULT '',
				source_id bigint(20) unsigned NOT NULL DEFAULT 0,
				note varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY wallet_id (wallet_id)
			) {$charset};"
		);

		$c = self::credits_table();
		dbDelta(
			"CREATE TABLE {$c} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				credit_type varchar(32) NOT NULL DEFAULT 'promo',
				amount decimal(12,2) NOT NULL DEFAULT 0.00,
				remaining decimal(12,2) NOT NULL DEFAULT 0.00,
				source varchar(64) NOT NULL DEFAULT '',
				source_id bigint(20) unsigned NOT NULL DEFAULT 0,
				expires_at datetime DEFAULT NULL,
				status varchar(32) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id),
				KEY status (status)
			) {$charset};"
		);

		$r = self::refunds_table();
		dbDelta(
			"CREATE TABLE {$r} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				transaction_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payment_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				refund_type varchar(32) NOT NULL DEFAULT 'full',
				amount decimal(12,2) NOT NULL DEFAULT 0.00,
				reason text,
				status varchar(32) NOT NULL DEFAULT 'pending',
				approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				provider_ref varchar(128) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY status (status),
				KEY vendor_user_id (vendor_user_id)
			) {$charset};"
		);

		$p = self::payouts_table();
		dbDelta(
			"CREATE TABLE {$p} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				gross_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				platform_fee decimal(12,2) NOT NULL DEFAULT 0.00,
				net_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				status varchar(32) NOT NULL DEFAULT 'pending',
				scheduled_date date DEFAULT NULL,
				paid_at datetime DEFAULT NULL,
				provider varchar(32) NOT NULL DEFAULT 'manual',
				provider_ref varchar(128) NOT NULL DEFAULT '',
				estimated_arrival date DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY organizer_user_id (organizer_user_id),
				KEY status (status)
			) {$charset};"
		);

		$d = self::disputes_table();
		dbDelta(
			"CREATE TABLE {$d} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				transaction_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payment_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				dispute_type varchar(32) NOT NULL DEFAULT 'payment',
				opened_by bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reason text,
				status varchar(32) NOT NULL DEFAULT 'open',
				evidence longtext,
				resolution_note text,
				resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY status (status)
			) {$charset};"
		);

		$rc = self::receipts_table();
		dbDelta(
			"CREATE TABLE {$rc} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				receipt_number varchar(32) NOT NULL DEFAULT '',
				transaction_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payment_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				amount decimal(12,2) NOT NULL DEFAULT 0.00,
				payment_method varchar(64) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT 'issued',
				metadata longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY receipt_number (receipt_number),
				KEY transaction_id (transaction_id)
			) {$charset};"
		);

		$pc = self::promo_codes_table();
		dbDelta(
			"CREATE TABLE {$pc} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				code varchar(64) NOT NULL DEFAULT '',
				discount_type varchar(16) NOT NULL DEFAULT 'percent',
				discount_value decimal(12,2) NOT NULL DEFAULT 0.00,
				scope varchar(32) NOT NULL DEFAULT 'marketplace',
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				category varchar(64) NOT NULL DEFAULT '',
				usage_limit int NOT NULL DEFAULT 0,
				usage_count int NOT NULL DEFAULT 0,
				first_time_only tinyint(1) NOT NULL DEFAULT 0,
				expires_at datetime DEFAULT NULL,
				status varchar(32) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY code (code)
			) {$charset};"
		);

		$pr = self::promo_redemptions_table();
		dbDelta(
			"CREATE TABLE {$pr} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				promo_code_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payment_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				discount_amount decimal(12,2) NOT NULL DEFAULT 0.00,
				redeemed_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY promo_code_id (promo_code_id),
				KEY user_id (user_id)
			) {$charset};"
		);

		$tp = self::tax_profiles_table();
		dbDelta(
			"CREATE TABLE {$tp} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				w9_on_file tinyint(1) NOT NULL DEFAULT 0,
				w9_submitted_at datetime DEFAULT NULL,
				form_1099_eligible tinyint(1) NOT NULL DEFAULT 0,
				tax_id_last4 varchar(4) NOT NULL DEFAULT '',
				sales_tax_rate decimal(6,4) NOT NULL DEFAULT 0.0000,
				metadata longtext,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY user_id (user_id)
			) {$charset};"
		);

		$a = self::audit_table();
		dbDelta(
			"CREATE TABLE {$a} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				action varchar(64) NOT NULL DEFAULT '',
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				entity_type varchar(32) NOT NULL DEFAULT '',
				entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				amount decimal(12,2) DEFAULT NULL,
				metadata longtext,
				ip_address varchar(45) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY action (action),
				KEY entity (entity_type, entity_id),
				KEY created_at (created_at)
			) {$charset};"
		);

		$f = self::fraud_flags_table();
		dbDelta(
			"CREATE TABLE {$f} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				flag_type varchar(64) NOT NULL DEFAULT '',
				severity varchar(16) NOT NULL DEFAULT 'medium',
				details text,
				status varchar(32) NOT NULL DEFAULT 'open',
				reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY status (status),
				KEY user_id (user_id)
			) {$charset};"
		);

		$wh = self::webhook_events_table();
		dbDelta(
			"CREATE TABLE {$wh} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id varchar(128) NOT NULL DEFAULT '',
				event_type varchar(64) NOT NULL DEFAULT '',
				payload longtext,
				status varchar(32) NOT NULL DEFAULT 'pending',
				attempts tinyint NOT NULL DEFAULT 0,
				error_message text,
				processed_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY event_id (event_id),
				KEY status (status),
				KEY event_type (event_type)
			) {$charset};"
		);

		$ca = self::connected_accounts_table();
		dbDelta(
			"CREATE TABLE {$ca} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				role_type varchar(16) NOT NULL DEFAULT 'organizer',
				stripe_account_id varchar(128) NOT NULL DEFAULT '',
				verification_status varchar(32) NOT NULL DEFAULT 'pending',
				payouts_enabled tinyint(1) NOT NULL DEFAULT 0,
				charges_enabled tinyint(1) NOT NULL DEFAULT 0,
				details_submitted tinyint(1) NOT NULL DEFAULT 0,
				requirements_due longtext,
				requirements_past_due longtext,
				disabled_reason varchar(128) NOT NULL DEFAULT '',
				last_synced_at datetime DEFAULT NULL,
				metadata longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY user_role (user_id, role_type),
				KEY stripe_account_id (stripe_account_id)
			) {$charset};"
		);

		$pt = self::payment_timeline_table();
		dbDelta(
			"CREATE TABLE {$pt} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				payment_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				step varchar(64) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT 'completed',
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				notes text,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY payment_post_id (payment_post_id),
				KEY step (step)
			) {$charset};"
		);

		$fa = self::financial_activity_table();
		dbDelta(
			"CREATE TABLE {$fa} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				activity_type varchar(64) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				role_type varchar(16) NOT NULL DEFAULT 'vendor',
				entity_type varchar(32) NOT NULL DEFAULT '',
				entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				amount decimal(12,2) DEFAULT NULL,
				message varchar(255) NOT NULL DEFAULT '',
				metadata longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_role (user_id, role_type),
				KEY activity_type (activity_type),
				KEY created_at (created_at)
			) {$charset};"
		);

		$fr = self::fee_rules_table();
		dbDelta(
			"CREATE TABLE {$fr} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(128) NOT NULL DEFAULT '',
				scope_type varchar(32) NOT NULL DEFAULT 'global',
				scope_id varchar(64) NOT NULL DEFAULT '',
				fee_type varchar(16) NOT NULL DEFAULT 'flat',
				fee_value decimal(12,4) NOT NULL DEFAULT 0.0000,
				fee_flat decimal(12,2) NOT NULL DEFAULT 0.00,
				fee_min decimal(12,2) DEFAULT NULL,
				fee_max decimal(12,2) DEFAULT NULL,
				priority int NOT NULL DEFAULT 100,
				status varchar(16) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY scope (scope_type, scope_id),
				KEY status (status)
			) {$charset};"
		);

		if ( class_exists( 'LNKup_Fee_Engine' ) ) {
			LNKup_Fee_Engine::seed_defaults();
		}

		if ( class_exists( 'LNKup_Treasury_Tables' ) ) {
			LNKup_Treasury_Tables::create_tables();
		}
	}
}
