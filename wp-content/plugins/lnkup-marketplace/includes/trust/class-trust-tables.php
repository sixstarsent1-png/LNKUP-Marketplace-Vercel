<?php
/**
 * Trust, Safety & Compliance OS tables.
 *
 * Additive only: cases, tamper-evident audit log, organization teams, and
 * event claim evidence. Core business records stay in CPTs / post meta.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Trust_Tables {

	/**
	 * Independent of LNKUP_VERSION so trust tables install even when another
	 * release already advanced lnkup_db_version.
	 */
	const SCHEMA_VERSION = '1';
	const SCHEMA_OPTION  = 'lnkup_trust_db_version';

	public static function maybe_upgrade() {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}
		try {
			self::create_tables();
			foreach ( array( 'administrator', 'lnkup_admin' ) as $role_slug ) {
				$role = get_role( $role_slug );
				if ( $role ) {
					$role->add_cap( 'lnkup_manage_trust' );
				}
			}
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup trust table migration failed: ' . $e->getMessage() );
		}
	}

	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$audit = self::audit_table();
		dbDelta(
			"CREATE TABLE {$audit} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				action varchar(64) NOT NULL DEFAULT '',
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				entity_type varchar(40) NOT NULL DEFAULT '',
				entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				prev_state longtext,
				new_state longtext,
				reason varchar(500) NOT NULL DEFAULT '',
				result varchar(20) NOT NULL DEFAULT 'success',
				request_id varchar(40) NOT NULL DEFAULT '',
				ip_hash varchar(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				prev_hash char(64) NOT NULL DEFAULT '',
				row_hash char(64) NOT NULL DEFAULT '',
				PRIMARY KEY (id),
				KEY action (action),
				KEY entity (entity_type, entity_id),
				KEY actor_user_id (actor_user_id),
				KEY created_at (created_at)
			) {$charset};"
		);

		$cases = self::cases_table();
		dbDelta(
			"CREATE TABLE {$cases} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				case_ref varchar(24) NOT NULL DEFAULT '',
				case_type varchar(48) NOT NULL DEFAULT '',
				severity varchar(16) NOT NULL DEFAULT 'review',
				status varchar(24) NOT NULL DEFAULT 'open',
				subject_type varchar(32) NOT NULL DEFAULT '',
				subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				signal_key varchar(120) NOT NULL DEFAULT '',
				summary varchar(500) NOT NULL DEFAULT '',
				evidence longtext,
				assigned_to bigint(20) unsigned NOT NULL DEFAULT 0,
				next_step varchar(255) NOT NULL DEFAULT '',
				opened_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				resolved_at datetime NULL DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY case_ref (case_ref),
				KEY status_severity (status, severity),
				KEY subject (subject_type, subject_id),
				KEY event_id (event_id),
				KEY signal_key (signal_key),
				KEY case_type (case_type)
			) {$charset};"
		);

		$case_events = self::case_events_table();
		dbDelta(
			"CREATE TABLE {$case_events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				case_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_type varchar(32) NOT NULL DEFAULT '',
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				from_status varchar(24) NOT NULL DEFAULT '',
				to_status varchar(24) NOT NULL DEFAULT '',
				note text,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY case_id (case_id)
			) {$charset};"
		);

		$members = self::org_members_table();
		dbDelta(
			"CREATE TABLE {$members} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				org_owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				role varchar(24) NOT NULL DEFAULT '',
				scope_type varchar(12) NOT NULL DEFAULT 'all',
				scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(16) NOT NULL DEFAULT 'active',
				invited_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				revoked_at datetime NULL DEFAULT NULL,
				PRIMARY KEY (id),
				KEY org_owner_id (org_owner_id),
				KEY user_status (user_id, status)
			) {$charset};"
		);

		$invites = self::org_invites_table();
		dbDelta(
			"CREATE TABLE {$invites} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				org_owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(190) NOT NULL DEFAULT '',
				role varchar(24) NOT NULL DEFAULT '',
				scope_type varchar(12) NOT NULL DEFAULT 'all',
				scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
				token_hash char(64) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'pending',
				invited_by bigint(20) unsigned NOT NULL DEFAULT 0,
				accepted_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY token_hash (token_hash),
				KEY org_owner_id (org_owner_id),
				KEY email (email)
			) {$charset};"
		);

		$claims = self::claim_evidence_table();
		dbDelta(
			"CREATE TABLE {$claims} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				external_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				organizer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				token_id bigint(20) unsigned NOT NULL DEFAULT 0,
				match_type varchar(16) NOT NULL DEFAULT 'none',
				status varchar(16) NOT NULL DEFAULT 'pending',
				evidence longtext,
				case_id bigint(20) unsigned NOT NULL DEFAULT 0,
				decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
				decided_at datetime NULL DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY external_event_id (external_event_id),
				KEY event_id (event_id),
				KEY organizer_status (organizer_user_id, status)
			) {$charset};"
		);
	}

	public static function audit_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_audit_log';
	}

	public static function cases_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_trust_cases';
	}

	public static function case_events_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_trust_case_events';
	}

	public static function org_members_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_org_members';
	}

	public static function org_invites_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_org_invites';
	}

	public static function claim_evidence_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_event_claim_evidence';
	}

	/**
	 * Cached existence check so services degrade safely before the migration runs.
	 *
	 * @param string $table Table name.
	 */
	public static function exists( $table ) {
		static $cache = array();
		if ( ! isset( $cache[ $table ] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cache[ $table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		}
		return $cache[ $table ];
	}
}
