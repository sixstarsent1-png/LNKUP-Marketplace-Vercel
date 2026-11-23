<?php
/**
 * Organizer Management OS tables: QR pass token index, application review
 * assignments, rubrics and scores, and waitlist offers.
 *
 * Applications stay canonical in the lnkup_application CPT; these tables only index,
 * annotate or schedule work against them.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_OS_Tables {

	const SCHEMA_VERSION = '2';
	const SCHEMA_OPTION  = 'lnkup_organizer_os_schema';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 5 );
	}

	public static function maybe_upgrade() {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}
		try {
			self::create_tables();
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup organizer OS table migration failed: ' . $e->getMessage() );
		}
	}

	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tokens = self::pass_tokens_table();
		dbDelta(
			"CREATE TABLE {$tokens} (
				token_hash char(64) NOT NULL,
				application_id bigint(20) unsigned NOT NULL DEFAULT 0,
				packet_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (token_hash),
				KEY application_id (application_id),
				KEY packet_id (packet_id)
			) {$charset};"
		);

		$assignments = self::review_assignments_table();
		dbDelta(
			"CREATE TABLE {$assignments} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				application_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reviewer_user_id bigint(20) unsigned NOT NULL,
				assigned_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY app_reviewer (application_id, reviewer_user_id),
				KEY reviewer_event (reviewer_user_id, event_id),
				KEY event_id (event_id)
			) {$charset};"
		);

		$rubrics = self::rubrics_table();
		dbDelta(
			"CREATE TABLE {$rubrics} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scope_type varchar(20) NOT NULL DEFAULT 'event',
				scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
				criteria longtext NOT NULL,
				version int(10) unsigned NOT NULL DEFAULT 1,
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY scope (scope_type, scope_id)
			) {$charset};"
		);

		$reviews = self::reviews_table();
		dbDelta(
			"CREATE TABLE {$reviews} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				application_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reviewer_user_id bigint(20) unsigned NOT NULL,
				rubric_id bigint(20) unsigned NOT NULL DEFAULT 0,
				rubric_version int(10) unsigned NOT NULL DEFAULT 0,
				scores longtext,
				weighted_score decimal(5,2) DEFAULT NULL,
				recommendation varchar(20) NOT NULL DEFAULT '',
				comment text,
				completed_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY app_reviewer (application_id, reviewer_user_id),
				KEY event_id (event_id)
			) {$charset};"
		);

		$offers = self::waitlist_offers_table();
		dbDelta(
			"CREATE TABLE {$offers} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				application_id bigint(20) unsigned NOT NULL,
				opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'offered',
				offered_by bigint(20) unsigned NOT NULL DEFAULT 0,
				expires_at datetime NOT NULL,
				resolved_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY application_id (application_id),
				KEY status_expires (status, expires_at),
				KEY opportunity_id (opportunity_id)
			) {$charset};"
		);

		$tasks = self::event_tasks_table();
		dbDelta(
			"CREATE TABLE {$tasks} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				tour_id bigint(20) unsigned NOT NULL DEFAULT 0,
				title varchar(190) NOT NULL DEFAULT '',
				description text,
				assignee_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				due_at datetime DEFAULT NULL,
				priority varchar(10) NOT NULL DEFAULT 'normal',
				status varchar(12) NOT NULL DEFAULT 'open',
				source varchar(12) NOT NULL DEFAULT 'manual',
				template_item_key varchar(80) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				completed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				completed_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY event_status (event_id, status),
				KEY assignee_status_due (assignee_user_id, status, due_at),
				KEY due_at (due_at)
			) {$charset};"
		);

		$comments = self::task_comments_table();
		dbDelta(
			"CREATE TABLE {$comments} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				task_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				body text NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY task_id (task_id)
			) {$charset};"
		);

		$templates = self::task_templates_table();
		dbDelta(
			"CREATE TABLE {$templates} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				owner_user_id bigint(20) unsigned NOT NULL,
				name varchar(120) NOT NULL DEFAULT '',
				items longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY owner_user_id (owner_user_id)
			) {$charset};"
		);

		$doc_reviews = self::document_reviews_table();
		dbDelta(
			"CREATE TABLE {$doc_reviews} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				application_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				requirement_type varchar(60) NOT NULL,
				document_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(24) NOT NULL DEFAULT 'requested',
				reason text,
				message text,
				requested_at datetime DEFAULT NULL,
				reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				reviewed_at datetime DEFAULT NULL,
				history longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY app_type (application_id, requirement_type),
				KEY event_status (event_id, status)
			) {$charset};"
		);
	}

	public static function event_tasks_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_event_tasks';
	}

	public static function task_comments_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_event_task_comments';
	}

	public static function task_templates_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_task_templates';
	}

	public static function document_reviews_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_app_document_reviews';
	}

	public static function pass_tokens_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_pass_tokens';
	}

	public static function review_assignments_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_app_review_assignments';
	}

	public static function rubrics_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_review_rubrics';
	}

	public static function reviews_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_app_reviews';
	}

	public static function waitlist_offers_table() {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_waitlist_offers';
	}

	/**
	 * Whether the schema has been installed (features degrade to "not available" otherwise).
	 */
	public static function ready( $min_version = '1' ) {
		$installed = (string) get_option( self::SCHEMA_OPTION, '' );
		return '' !== $installed && version_compare( $installed, (string) $min_version, '>=' );
	}
}
