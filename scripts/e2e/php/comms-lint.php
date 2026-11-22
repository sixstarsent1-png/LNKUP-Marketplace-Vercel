<?php
/**
 * Syntax-check lnkup-communications and the lnkup-marketplace files it touches.
 * Usage: npx @wordpress/env run cli php /var/www/html/wp-content/lnkup-e2e/php/comms-lint.php
 *
 * @package LNKup_Communications
 */

$base  = '/var/www/html/wp-content/';
$files = array();
$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base . 'plugins/lnkup-communications', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iter as $file ) {
	$path = $file->getPathname();
	if ( substr( $path, -4 ) === '.php' && false === strpos( $path, '/lib/' ) ) {
		$files[] = $path;
	}
}
foreach ( array(
	'plugins/lnkup-marketplace/includes/class-lifecycle-hooks.php',
	'plugins/lnkup-marketplace/includes/class-event-status.php',
	'plugins/lnkup-marketplace/includes/class-notifications.php',
	'plugins/lnkup-marketplace/includes/class-applications.php',
	'plugins/lnkup-marketplace/includes/class-applications-rest.php',
	'plugins/lnkup-marketplace/includes/class-documents.php',
	'plugins/lnkup-marketplace/includes/class-documents-rest.php',
	'plugins/lnkup-marketplace/includes/class-invoices.php',
	'plugins/lnkup-marketplace/includes/class-financial-cron.php',
	'plugins/lnkup-marketplace/includes/class-marketplace-cron.php',
	'plugins/lnkup-marketplace/includes/class-checkins.php',
	'plugins/lnkup-marketplace/includes/class-financial-disputes.php',
	'plugins/lnkup-marketplace/includes/class-organizer-onboarding.php',
	'plugins/lnkup-marketplace/includes/financial/class-stripe-connect.php',
	'plugins/lnkup-marketplace/includes/financial/class-organizer-payouts.php',
	'plugins/lnkup-marketplace/includes/financial/class-stripe-webhooks.php',
	'plugins/lnkup-marketplace/public/class-shortcodes.php',
	'plugins/lnkup-marketplace/lnkup-marketplace.php',
	'themes/lnkup-theme/inc/dashboard.php',
) as $rel ) {
	$files[] = $base . $rel;
}
$failed = 0;
foreach ( $files as $path ) {
	$out = shell_exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1' );
	if ( false === strpos( (string) $out, 'No syntax errors' ) ) {
		++$failed;
		echo "  FAIL {$path}\n" . $out . "\n";
	}
}
echo count( $files ) . " files checked\n";
echo $failed ? "\n{$failed} FAILED\n" : "\nALL PASSED\n";
exit( $failed ? 1 : 0 );
