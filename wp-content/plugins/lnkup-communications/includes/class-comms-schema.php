<?php
/**
 * Versioned schema for communications tables.
 *
 * Business records stay in lnkup-marketplace CPTs/tables. These tables only hold
 * communication state: what was triggered, for whom, through which channel, and
 * what the provider said. Message bodies are not stored.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Schema {

	const DB_OPTION = 'lnkup_comms_db_version';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_comms_' . $name;
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_OPTION ) === LNKUP_COMMS_DB_VERSION && ! get_option( 'lnkup_comms_needs_upgrade' ) ) {
			return;
		}
		try {
			self::create_tables();
			update_option( self::DB_OPTION, LNKUP_COMMS_DB_VERSION, false );
			delete_option( 'lnkup_comms_needs_upgrade' );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup Communications schema upgrade failed: ' . $e->getMessage() );
		}
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$events = self::table( 'events' );
		dbDelta(
			"CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  trigger_key varchar(64) NOT NULL DEFAULT '',
  source varchar(64) NOT NULL DEFAULT '',
  object_type varchar(32) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  dedupe_key varchar(191) NOT NULL,
  context longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY trigger_key (trigger_key),
  KEY object_lookup (object_type,object_id)
) {$charset};"
		);

		$messages = self::table( 'messages' );
		dbDelta(
			"CREATE TABLE {$messages} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_row_id bigint(20) unsigned NOT NULL DEFAULT 0,
  trigger_key varchar(64) NOT NULL DEFAULT '',
  recipient_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  recipient_role varchar(20) NOT NULL DEFAULT '',
  category varchar(32) NOT NULL DEFAULT '',
  priority varchar(16) NOT NULL DEFAULT 'normal',
  transactional tinyint(1) NOT NULL DEFAULT 0,
  event_id bigint(20) unsigned NOT NULL DEFAULT 0,
  opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
  application_id bigint(20) unsigned NOT NULL DEFAULT 0,
  payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  template_key varchar(64) NOT NULL DEFAULT '',
  title varchar(255) NOT NULL DEFAULT '',
  deep_link varchar(255) NOT NULL DEFAULT '',
  context longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'scheduled',
  suppression_reason varchar(64) NOT NULL DEFAULT '',
  dedupe_key varchar(191) NOT NULL,
  cancel_key varchar(191) NOT NULL DEFAULT '',
  scheduled_at datetime NOT NULL,
  expires_at datetime NULL,
  sent_at datetime NULL,
  read_at datetime NULL,
  opened_at datetime NULL,
  clicked_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY recipient_status (recipient_user_id,status),
  KEY status_scheduled (status,scheduled_at),
  KEY cancel_key (cancel_key),
  KEY event_id (event_id),
  KEY application_id (application_id),
  KEY campaign_id (campaign_id),
  KEY trigger_created (trigger_key,created_at)
) {$charset};"
		);

		$deliveries = self::table( 'deliveries' );
		dbDelta(
			"CREATE TABLE {$deliveries} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  channel varchar(10) NOT NULL DEFAULT '',
  status varchar(24) NOT NULL DEFAULT 'queued',
  provider_ref varchar(191) NOT NULL DEFAULT '',
  device_id bigint(20) unsigned NOT NULL DEFAULT 0,
  attempts int(11) NOT NULL DEFAULT 0,
  last_error varchar(255) NOT NULL DEFAULT '',
  suppression_reason varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY message_channel (message_id,channel),
  KEY status_updated (status,updated_at),
  KEY provider_ref (provider_ref)
) {$charset};"
		);

		$prefs = self::table( 'preferences' );
		dbDelta(
			"CREATE TABLE {$prefs} (
  user_id bigint(20) unsigned NOT NULL,
  category varchar(32) NOT NULL,
  channel varchar(10) NOT NULL,
  mode varchar(10) NOT NULL DEFAULT 'immediate',
  updated_at datetime NOT NULL,
  PRIMARY KEY  (user_id,category,channel)
) {$charset};"
		);

		$suppressions = self::table( 'suppressions' );
		dbDelta(
			"CREATE TABLE {$suppressions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email_hash char(64) NOT NULL,
  scope varchar(40) NOT NULL DEFAULT 'marketing',
  reason varchar(32) NOT NULL DEFAULT 'unsubscribe',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email_scope (email_hash,scope)
) {$charset};"
		);

		$templates = self::table( 'templates' );
		dbDelta(
			"CREATE TABLE {$templates} (
  template_key varchar(64) NOT NULL,
  fields longtext NULL,
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (template_key)
) {$charset};"
		);

		$versions = self::table( 'template_versions' );
		dbDelta(
			"CREATE TABLE {$versions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  template_key varchar(64) NOT NULL,
  fields longtext NULL,
  status varchar(16) NOT NULL DEFAULT 'draft',
  note varchar(255) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  published_at datetime NULL,
  PRIMARY KEY  (id),
  KEY key_status (template_key,status)
) {$charset};"
		);

		$campaigns = self::table( 'campaigns' );
		dbDelta(
			"CREATE TABLE {$campaigns} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_type varchar(16) NOT NULL DEFAULT 'admin',
  event_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(191) NOT NULL DEFAULT '',
  audience_role varchar(20) NOT NULL DEFAULT '',
  audience_key varchar(40) NOT NULL DEFAULT '',
  category varchar(32) NOT NULL DEFAULT 'product_news',
  channels varchar(40) NOT NULL DEFAULT 'inbox',
  filters longtext NULL,
  content longtext NULL,
  status varchar(16) NOT NULL DEFAULT 'draft',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  total_eligible int(11) NOT NULL DEFAULT 0,
  total_queued int(11) NOT NULL DEFAULT 0,
  total_excluded int(11) NOT NULL DEFAULT 0,
  cursor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  scheduled_at datetime NULL,
  started_at datetime NULL,
  completed_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type_event (campaign_type,event_id),
  KEY status (status)
) {$charset};"
		);

		$clicks = self::table( 'clicks' );
		dbDelta(
			"CREATE TABLE {$clicks} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  channel varchar(10) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY message_id (message_id),
  KEY user_created (user_id,created_at)
) {$charset};"
		);
	}

	/**
	 * UTC MySQL datetime, optionally offset. All communications tables store UTC.
	 */
	public static function now( int $offset_seconds = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offset_seconds );
	}
}
