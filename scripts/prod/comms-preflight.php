<?php
/**
 * READ-ONLY production preflight for the lnkup-communications rollout.
 *
 * Writes nothing: no options, no tables, no cron changes. Safe to run on production.
 *
 * Run over SSH without uploading the file (WP-CLI reads it from STDIN):
 *   ssh -p 18765 -i ~/.ssh/lnkup_siteground USER@ssh.thelnkup.com \
 *     "cd ~/www/thelnkup.com/public_html && wp eval-file -" < scripts/prod/comms-preflight.php
 *
 * Local dry run (wp-env, PowerShell):
 *   Get-Content -Raw scripts/prod/comms-preflight.php | npx @wordpress/env run cli wp eval-file -
 *
 * Output: one line per check, prefixed [OK], [WARN] or [FAIL], then a summary.
 *
 * @package LNKup_Communications
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with: wp eval-file comms-preflight.php\n";
	return;
}

// wp eval-file runs this inside a function, so shared state lives in $GLOBALS explicitly.
$GLOBALS['lnkup_pf'] = array( 'ok' => 0, 'warn' => 0, 'fail' => 0 );

/**
 * Print one check result.
 *
 * @param string $level  ok|warn|fail.
 * @param string $label  Check name.
 * @param string $detail Detail.
 */
function lnkup_pf_line( string $level, string $label, string $detail ): void {
	$GLOBALS['lnkup_pf'][ $level ]++;
	printf( "[%s] %s: %s\n", strtoupper( $level ), $label, $detail );
}

/**
 * Whether a table exists (read-only SHOW TABLES).
 */
function lnkup_pf_table_exists( string $table ): bool {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB
}

global $wpdb;
$wpdb->suppress_errors( true );

echo "LNKup Communications preflight — " . esc_url_raw( home_url( '/' ) ) . ' — ' . gmdate( 'Y-m-d H:i' ) . " UTC\n\n";

// ---------------------------------------------------------------- Platform.
echo "== Platform\n";
$wp_version = get_bloginfo( 'version' );
lnkup_pf_line( version_compare( $wp_version, '6.9', '>=' ) ? 'ok' : 'fail', 'WordPress', $wp_version . ( version_compare( $wp_version, '6.9', '>=' ) ? '' : ' — bundled Action Scheduler 4.2.0 requires 6.9+. Update WordPress or pin Action Scheduler 3.9.x before activating.' ) );
lnkup_pf_line( version_compare( PHP_VERSION, '8.0', '>=' ) ? 'ok' : ( version_compare( PHP_VERSION, '7.4', '>=' ) ? 'warn' : 'fail' ), 'PHP', PHP_VERSION . ' (plugin 7.4+, theme 8.0+)' );
lnkup_pf_line( 'ok', 'Table prefix', $wpdb->prefix );
lnkup_pf_line( 'ok', 'Memory limit', (string) ini_get( 'memory_limit' ) . ' / WP_MAX_MEMORY_LIMIT ' . ( defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : 'default' ) );

// ---------------------------------------------------------------- Error display (production must not print errors).
echo "\n== Error display\n";
$display = (string) ini_get( 'display_errors' );
$debug_display = defined( 'WP_DEBUG_DISPLAY' ) ? WP_DEBUG_DISPLAY : true;
$debug         = defined( 'WP_DEBUG' ) && WP_DEBUG;
if ( $debug && $debug_display ) {
	lnkup_pf_line( 'fail', 'WP_DEBUG_DISPLAY', 'WP_DEBUG is on and errors are printed to visitors (leaks DB name/prefix, breaks redirects). Set WP_DEBUG_DISPLAY false and WP_DEBUG_LOG true.' );
} else {
	lnkup_pf_line( 'ok', 'WP_DEBUG_DISPLAY', $debug ? 'WP_DEBUG on, display off' : 'WP_DEBUG off' );
}
lnkup_pf_line( in_array( strtolower( $display ), array( '1', 'on', 'stdout' ), true ) ? 'warn' : 'ok', 'php display_errors', '' !== $display ? $display : 'off' );

// ---------------------------------------------------------------- Plugins.
echo "\n== Plugins\n";
if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$active  = (array) get_option( 'active_plugins', array() );
$plugins = get_plugins();
$market  = 'lnkup-marketplace/lnkup-marketplace.php';
$comms   = 'lnkup-communications/lnkup-communications.php';
lnkup_pf_line( in_array( $market, $active, true ) ? 'ok' : 'fail', 'lnkup-marketplace', in_array( $market, $active, true ) ? 'active ' . ( defined( 'LNKUP_VERSION' ) ? LNKUP_VERSION : (string) ( $plugins[ $market ]['Version'] ?? '?' ) ) : 'NOT active' );
if ( isset( $plugins[ $comms ] ) ) {
	lnkup_pf_line( 'ok', 'lnkup-communications', ( in_array( $comms, $active, true ) ? 'active ' : 'installed, inactive ' ) . (string) $plugins[ $comms ]['Version'] );
} else {
	lnkup_pf_line( 'warn', 'lnkup-communications', 'not installed yet (expected before the first deploy)' );
}
lnkup_pf_line( has_filter( 'lnkup_pre_send_notification' ) !== false || method_exists( 'LNKup_Notifications', 'delegated' ) ? 'ok' : 'warn', 'Core delegation seam', method_exists( 'LNKup_Notifications', 'delegated' ) ? 'LNKup_Notifications::delegated() present' : 'missing — deploy lnkup-marketplace with the seam edits first' );
lnkup_pf_line( class_exists( 'LNKup_Lifecycle_Hooks' ) ? 'ok' : 'warn', 'Lifecycle hooks', class_exists( 'LNKup_Lifecycle_Hooks' ) ? 'present' : 'missing — deploy lnkup-marketplace first' );

// Other Action Scheduler copies (WooCommerce, etc.).
$as_plugins = array();
foreach ( $active as $file ) {
	$dir = WP_PLUGIN_DIR . '/' . dirname( $file );
	if ( 'lnkup-communications' !== dirname( $file ) && ( is_dir( $dir . '/vendor/woocommerce/action-scheduler' ) || is_dir( $dir . '/packages/action-scheduler' ) || is_dir( $dir . '/lib/action-scheduler' ) || 'action-scheduler' === dirname( $file ) ) ) {
		$as_plugins[] = dirname( $file );
	}
}
$as_loaded = class_exists( 'ActionScheduler_Versions' ) ? implode( ', ', array_keys( (array) ActionScheduler_Versions::instance()->get_versions() ) ) : '';
lnkup_pf_line( $as_plugins ? 'warn' : 'ok', 'Other Action Scheduler copies', $as_plugins ? implode( ', ', $as_plugins ) . ' (the newest registered version wins; registered: ' . ( '' !== $as_loaded ? $as_loaded : 'n/a' ) . ')' : 'none' . ( '' !== $as_loaded ? ' (registered: ' . $as_loaded . ')' : '' ) );

// Rank Math analytics table (seen missing on production).
if ( defined( 'RANK_MATH_VERSION' ) ) {
	$rm_table = $wpdb->prefix . 'rank_math_analytics_objects';
	$rm_on    = class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'is_module_active' ) ? \RankMath\Helper::is_module_active( 'analytics' ) : null;
	if ( false !== $rm_on && ! lnkup_pf_table_exists( $rm_table ) ) {
		lnkup_pf_line( 'warn', 'Rank Math Analytics', $rm_table . ' missing. Rank Math → Status & Tools → Database Tools → Re-create Missing Database Tables, or turn the Analytics module off.' );
	} else {
		lnkup_pf_line( 'ok', 'Rank Math Analytics', false === $rm_on ? 'module off' : 'tables present' );
	}
}

// ---------------------------------------------------------------- Cron.
echo "\n== Background jobs\n";
$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
lnkup_pf_line( $disabled ? 'ok' : 'warn', 'DISABLE_WP_CRON', $disabled ? 'true (expects a server cron)' : 'not set — WP-Cron only runs on traffic. Add the SiteGround cron job, then set DISABLE_WP_CRON.' );
$hourly = wp_next_scheduled( 'lnkup_financial_hourly' );
if ( $hourly ) {
	$late = time() - (int) $hourly;
	lnkup_pf_line( $late > 2 * HOUR_IN_SECONDS ? 'warn' : 'ok', 'lnkup_financial_hourly', $late > 0 ? 'overdue by ' . human_time_diff( (int) $hourly ) . ( $late > 2 * HOUR_IN_SECONDS ? ' — cron is not running reliably' : '' ) : 'next run in ' . human_time_diff( (int) $hourly ) );
} else {
	lnkup_pf_line( 'warn', 'lnkup_financial_hourly', 'not scheduled' );
}
$crons   = _get_cron_array();
$overdue = 0;
foreach ( (array) $crons as $ts => $hooks ) {
	if ( (int) $ts < time() - HOUR_IN_SECONDS ) {
		$overdue += count( (array) $hooks );
	}
}
lnkup_pf_line( $overdue > 5 ? 'warn' : 'ok', 'Overdue WP-Cron events', (string) $overdue . ' events more than 1h late' );

// ---------------------------------------------------------------- Email & push.
echo "\n== Email & push\n";
$settings = function_exists( 'lnkup_get_settings' ) ? (array) lnkup_get_settings() : array();
$mail_hooks = array();
global $wp_filter;
if ( isset( $wp_filter['phpmailer_init'] ) ) {
	foreach ( $wp_filter['phpmailer_init']->callbacks as $callbacks ) {
		foreach ( $callbacks as $cb ) {
			$fn = $cb['function'];
			if ( is_array( $fn ) ) {
				$owner = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
				if ( 0 === strpos( $owner, 'LNKup_Comms_' ) ) {
					continue; // Our plain-text alternative, not a transport.
				}
				$mail_hooks[] = $owner . '::' . $fn[1];
			} elseif ( is_string( $fn ) ) {
				$mail_hooks[] = $fn;
			} else {
				$mail_hooks[] = 'closure';
			}
		}
	}
}
$smtp_plugins = array_values( array_filter( $active, static function ( $file ) {
	return (bool) preg_match( '/smtp|mail|sendgrid|postmark|mailgun|amazon-ses|sparkpost|brevo/i', $file );
} ) );
lnkup_pf_line( $mail_hooks || $smtp_plugins ? 'ok' : 'warn', 'Mail transport', ( $smtp_plugins ? 'plugins: ' . implode( ', ', $smtp_plugins ) . '. ' : '' ) . ( $mail_hooks ? 'phpmailer_init: ' . implode( ', ', array_slice( $mail_hooks, 0, 5 ) ) : 'no SMTP hook detected — PHP mail() (SiteGround default). Verify SPF/DKIM/DMARC.' ) );
lnkup_pf_line( is_email( (string) ( $settings['support_email'] ?? '' ) ) ? 'ok' : 'warn', 'support_email', is_email( (string) ( $settings['support_email'] ?? '' ) ) ? 'set' : 'not set — health alerts fall back to admin_email' );
lnkup_pf_line( '' !== trim( (string) ( $settings['expo_push_access_token'] ?? '' ) ) ? 'ok' : 'warn', 'Expo access token', '' !== trim( (string) ( $settings['expo_push_access_token'] ?? '' ) ) ? 'set' : 'not set (required only if Expo enhanced push security is on)' );
$devices_table = $wpdb->prefix . 'lnkup_push_devices';
if ( lnkup_pf_table_exists( $devices_table ) ) {
	$devices = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(enabled = 1) AS enabled, COUNT(DISTINCT user_id) AS users, SUM(platform = 'android') AS android, SUM(platform = 'ios') AS ios FROM {$devices_table}", ARRAY_A ); // phpcs:ignore WordPress.DB
	lnkup_pf_line( 'ok', 'Push devices', sprintf( '%d enabled of %d (%d users; iOS %d, Android %d). Android needs FCM credentials in EAS to deliver.', (int) $devices['enabled'], (int) $devices['total'], (int) $devices['users'], (int) $devices['ios'], (int) $devices['android'] ) );
} else {
	lnkup_pf_line( 'warn', 'Push devices', $devices_table . ' missing' );
}

// ---------------------------------------------------------------- Baseline (compare after each flag).
echo "\n== Baseline: core notification volume, last 7 days (lnkup-marketplace ledger)\n";
$deliveries = $wpdb->prefix . 'lnkup_notification_deliveries';
if ( lnkup_pf_table_exists( $deliveries ) ) {
	$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS + (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) );
	$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT event_key, channel, status, COUNT(*) AS c FROM {$deliveries} WHERE created_at >= %s GROUP BY event_key, channel, status ORDER BY event_key, channel, status", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
	if ( ! $rows ) {
		echo "  (no ledger rows in the last 7 days)\n";
	}
	foreach ( $rows as $row ) {
		printf( "  %-28s %-6s %-11s %d\n", $row['event_key'], $row['channel'], $row['status'], (int) $row['c'] );
	}
	lnkup_pf_line( 'ok', 'Baseline captured', count( $rows ) . ' groups (save this output)' );
} else {
	lnkup_pf_line( 'warn', 'Baseline', $deliveries . ' missing' );
}
$inbox = $wpdb->prefix . 'lnkup_inbox_index';
if ( lnkup_pf_table_exists( $inbox ) ) {
	lnkup_pf_line( 'ok', 'Inbox rows (7d)', (string) (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$inbox} WHERE created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) ) ); // phpcs:ignore WordPress.DB
}

// ---------------------------------------------------------------- Communications state (if installed).
if ( class_exists( 'LNKup_Comms_Settings' ) ) {
	echo "\n== Communications state\n";
	$on = array_keys( array_filter( array_intersect_key( LNKup_Comms_Settings::all(), LNKup_Comms_Settings::flags() ) ) );
	lnkup_pf_line( $on ? 'warn' : 'ok', 'Flags on', $on ? implode( ', ', $on ) : 'none (core still sends everything)' );
	lnkup_pf_line( LNKup_Comms_Settings::enabled( 'delivery_paused' ) ? 'warn' : 'ok', 'Delivery paused', LNKup_Comms_Settings::enabled( 'delivery_paused' ) ? 'yes' : 'no' );
	$missing = array();
	foreach ( array( 'events', 'messages', 'deliveries', 'preferences', 'suppressions', 'templates', 'campaigns', 'clicks' ) as $t ) {
		if ( ! lnkup_pf_table_exists( $wpdb->prefix . 'lnkup_comms_' . $t ) ) {
			$missing[] = $t;
		}
	}
	lnkup_pf_line( $missing ? 'fail' : 'ok', 'Communications tables', $missing ? 'missing: ' . implode( ', ', $missing ) : 'all present' );
	lnkup_pf_line( (string) LNKup_Comms_Settings::get( 'company_address' ) !== '' ? 'ok' : 'warn', 'Sender postal address', (string) LNKup_Comms_Settings::get( 'company_address' ) !== '' ? 'set' : 'not set (needed before promotional email)' );
	lnkup_pf_line( LNKup_Comms_Settings::test_user_ids() ? 'ok' : 'warn', 'Test accounts', LNKup_Comms_Settings::test_user_ids() ? implode( ',', LNKup_Comms_Settings::test_user_ids() ) : 'none configured' );
}

$wpdb->suppress_errors( false );
$lnkup_pf = $GLOBALS['lnkup_pf'];
printf( "\nSummary: %d ok, %d warn, %d fail — %s\n", $lnkup_pf['ok'], $lnkup_pf['warn'], $lnkup_pf['fail'], $lnkup_pf['fail'] ? 'DO NOT proceed until failures are resolved.' : 'OK to proceed (review warnings).' );
