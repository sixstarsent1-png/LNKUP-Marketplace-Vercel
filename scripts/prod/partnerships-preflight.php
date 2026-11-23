<?php
/**
 * READ-ONLY production preflight for the Partnership OS rollout (docs/PARTNERSHIP_ROLLOUT.md).
 *
 * Writes nothing: no options, transients, tables or cron changes. Safe to run on production at any
 * stage, including before the plugin is deployed (it reports what is missing instead of erroring).
 *
 * Run over SSH without uploading the file (WP-CLI reads it from STDIN):
 *   ssh -p 18765 -i ~/.ssh/lnkup_siteground USER@ssh.thelnkup.com \
 *     "cd ~/www/thelnkup.com/public_html && wp eval-file -" < scripts/prod/partnerships-preflight.php
 *
 * Local dry run (wp-env, PowerShell):
 *   Get-Content -Raw scripts/prod/partnerships-preflight.php | npx @wordpress/env run cli wp eval-file -
 *
 * Output: one line per check, prefixed [OK], [WARN], [FAIL] or [INFO], the stage checklist, then a summary.
 *
 * @package LNKup_Marketplace
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run with: wp eval-file partnerships-preflight.php\n";
	return;
}

// wp eval-file runs this inside a function, so shared state lives in $GLOBALS explicitly.
$GLOBALS['lnkup_ppf'] = array( 'ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0 );

/**
 * Print one check result.
 *
 * @param string $level  ok|warn|fail|info.
 * @param string $label  Check name.
 * @param string $detail Detail.
 */
function lnkup_ppf_line( string $level, string $label, string $detail ): void {
	$level = isset( $GLOBALS['lnkup_ppf'][ $level ] ) ? $level : 'info';
	$GLOBALS['lnkup_ppf'][ $level ]++;
	printf( "[%s] %s: %s\n", strtoupper( $level ), $label, $detail );
}

global $wpdb;
$wpdb->suppress_errors( true );

echo 'LNKup Partnership OS preflight — ' . esc_url_raw( home_url( '/' ) ) . ' — ' . gmdate( 'Y-m-d H:i' ) . " UTC\n\n";

// ---------------------------------------------------------------- Platform.
echo "== Platform\n";
$wp_version = get_bloginfo( 'version' );
lnkup_ppf_line( version_compare( $wp_version, '6.0', '>=' ) ? 'ok' : 'fail', 'WordPress', $wp_version );
lnkup_ppf_line( version_compare( PHP_VERSION, '8.0', '>=' ) ? 'ok' : ( version_compare( PHP_VERSION, '7.4', '>=' ) ? 'warn' : 'fail' ), 'PHP', PHP_VERSION . ' (plugin 7.4+, theme 8.0+)' );
$debug         = defined( 'WP_DEBUG' ) && WP_DEBUG;
$debug_display = defined( 'WP_DEBUG_DISPLAY' ) ? WP_DEBUG_DISPLAY : true;
lnkup_ppf_line( $debug && $debug_display ? 'fail' : 'ok', 'Error display', $debug && $debug_display ? 'WP_DEBUG prints errors to visitors and into REST JSON. Set WP_DEBUG_DISPLAY false.' : ( $debug ? 'WP_DEBUG on, display off' : 'WP_DEBUG off' ) );
$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
lnkup_ppf_line( $cron_off ? 'ok' : 'warn', 'Background jobs', $cron_off ? 'DISABLE_WP_CRON true (server cron expected); the hourly attribution backfill depends on it' : 'WP-Cron runs only on traffic; the hourly attribution backfill may lag on quiet days' );

// ---------------------------------------------------------------- Plugins.
echo "\n== Plugins\n";
if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$active  = (array) get_option( 'active_plugins', array() );
$plugins = get_plugins();
$market  = 'lnkup-marketplace/lnkup-marketplace.php';
$comms   = 'lnkup-communications/lnkup-communications.php';
$market_version = defined( 'LNKUP_VERSION' ) ? LNKUP_VERSION : (string) ( $plugins[ $market ]['Version'] ?? '?' );
lnkup_ppf_line( in_array( $market, $active, true ) ? 'ok' : 'fail', 'lnkup-marketplace', in_array( $market, $active, true ) ? 'active ' . $market_version : 'NOT active' );
if ( isset( $plugins[ $comms ] ) ) {
	lnkup_ppf_line( in_array( $comms, $active, true ) ? 'ok' : 'warn', 'lnkup-communications', ( in_array( $comms, $active, true ) ? 'active ' : 'installed, inactive ' ) . (string) $plugins[ $comms ]['Version'] );
} else {
	lnkup_ppf_line( 'warn', 'lnkup-communications', 'not installed (partner notices need it; stage 5)' );
}

$deployed = class_exists( 'LNKup_Partnership_Readiness' );
if ( ! $deployed ) {
	lnkup_ppf_line(
		'fail',
		'Partnership OS',
		class_exists( 'LNKup_Partner_Tables' )
			? 'plugin ' . $market_version . ' has the Partnership OS but not the readiness checks. Deploy the current build (stage 1), then re-run.'
			: 'not deployed (plugin ' . $market_version . '). Expected before stage 1.'
	);
} else {
	// ------------------------------------------------------------ Readiness (shared with wp-admin → Go-live readiness).
	try {
		$report = LNKup_Partnership_Readiness::report( false );
	} catch ( Throwable $e ) {
		$report = null;
		lnkup_ppf_line( 'fail', 'Readiness report', 'could not be built: ' . $e->getMessage() );
	}
	if ( is_array( $report ) ) {
		$stage_titles = array(
			'deployed'       => 'Deployed',
			'enforce'        => 'Shadow evidence and enforce',
			'programs'       => 'Programs',
			'communications' => 'Partner notifications',
			'mobile'         => 'Mobile',
		);
		$current = '';
		foreach ( (array) $report['checks'] as $check ) {
			if ( $check['stage'] !== $current ) {
				$current = (string) $check['stage'];
				echo "\n== " . ( $stage_titles[ $current ] ?? $current ) . "\n";
			}
			lnkup_ppf_line( (string) $check['level'], (string) $check['label'], (string) $check['detail'] );
		}

		$shadow = (array) $report['shadow'];
		if ( ! empty( $shadow['by_reason'] ) ) {
			$kind = 'partner_arbiter_blocked' === ( $shadow['action'] ?? '' ) ? 'Enforced blocks' : 'Shadow blocks';
			echo "\n== {$kind} by reason (30 days)\n";
			foreach ( (array) $shadow['by_reason'] as $reason => $n ) {
				printf( "  %-24s %d\n", $reason, (int) $n );
			}
			echo "== {$kind} by program (30 days)\n";
			foreach ( (array) $shadow['by_program'] as $program => $n ) {
				printf( "  %-24s %d\n", $program, (int) $n );
			}
		}

		echo "\n== Stages\n";
		foreach ( (array) $report['stages'] as $stage ) {
			printf( "  %-8s %s\n", strtoupper( (string) $stage['status'] ), (string) $stage['label'] );
			foreach ( (array) $stage['blockers'] as $blocker ) {
				echo '           - ' . $blocker . "\n";
			}
		}
	}
}

$wpdb->suppress_errors( false );
$lnkup_ppf = $GLOBALS['lnkup_ppf'];
printf(
	"\nSummary: %d ok, %d warn, %d fail, %d info — %s\n",
	$lnkup_ppf['ok'],
	$lnkup_ppf['warn'],
	$lnkup_ppf['fail'],
	$lnkup_ppf['info'],
	$lnkup_ppf['fail'] ? 'DO NOT advance a stage until failures are resolved.' : 'No failures. Advance only to a stage marked READY.'
);
