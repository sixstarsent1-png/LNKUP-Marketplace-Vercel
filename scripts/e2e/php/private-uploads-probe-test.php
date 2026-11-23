<?php
/**
 * Private uploads protection gate for Document Vault sharing and the private document migration.
 *
 * Uses faked HTTP responses for the probe request; no real hosting is contacted.
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/private-uploads-probe-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

echo "Private uploads probe\n";

$saved_settings = get_option( 'lnkup_settings', array() );
$saved_probe    = get_option( LNKup_Private_Uploads_Probe::OPTION, null );
$saved_migrate  = get_option( LNKup_Document_Migration::ENABLED_OPT, null );

/**
 * Answer the probe request with a fixed outcome and remember the probe file path it targeted.
 *
 * @param string $mode exposed | forbidden | error.
 */
function lnkup_pu_fake( string $mode ): void {
	remove_all_filters( 'pre_http_request' );
	add_filter(
		'pre_http_request',
		static function ( $pre, $args, $url ) use ( $mode ) {
			if ( false === strpos( (string) $url, LNKup_Documents::PRIVATE_SUBDIR . '/.lnkup-probe-' ) ) {
				return $pre;
			}
			$dirs = wp_upload_dir( null, false );
			$file = trailingslashit( $dirs['basedir'] ) . LNKup_Documents::PRIVATE_SUBDIR . '/' . basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$GLOBALS['lnkup_pu_seen'] = $file;
			if ( 'error' === $mode ) {
				return new WP_Error( 'http_request_failed', 'cURL error 7: connection refused' );
			}
			$body = 'exposed' === $mode && file_exists( $file ) ? (string) file_get_contents( $file ) : '<h1>Forbidden</h1>'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array( 'code' => 'exposed' === $mode ? 200 : 403, 'message' => '' ),
				'cookies'  => array(),
				'filename' => null,
			);
		},
		10,
		3
	);
}

/**
 * Write Passport settings directly, as seeds and migrations do.
 *
 * @param bool $sharing Document Vault sharing value.
 */
function lnkup_pu_settings( bool $sharing ): void {
	$settings                           = (array) get_option( 'lnkup_settings', array() );
	$settings['passport_enabled']       = true;
	$settings['document_vault_sharing'] = $sharing;
	update_option( 'lnkup_settings', $settings, false );
}

delete_option( LNKup_Private_Uploads_Probe::OPTION );
delete_option( LNKup_Document_Migration::ENABLED_OPT );

echo "Exposed folder\n";
lnkup_pu_fake( 'exposed' );
$result = LNKup_Private_Uploads_Probe::run();
lnkup_t_same( 'exposed', $result['status'], 'a publicly served token is reported as exposed' );
lnkup_t_assert( ! empty( $GLOBALS['lnkup_pu_seen'] ) && ! file_exists( $GLOBALS['lnkup_pu_seen'] ), 'the probe file is deleted after the check' );
lnkup_pu_settings( true );
lnkup_t_same( false, LNKup_Passport_Settings::enabled( 'document_vault_sharing' ), 'sharing reads as off while the folder is exposed' );
lnkup_pu_settings( false );
$after = LNKup_Passport_Settings::save( array( 'passport_enabled' => true, 'document_vault_sharing' => true ) );
lnkup_t_same( false, $after['document_vault_sharing'], 'saving cannot switch sharing on' );
lnkup_t_same( true, LNKup_Passport_Settings::$sharing_blocked, 'the refusal is reported to the admin screen' );
lnkup_t_same( false, LNKup_Document_Migration::enable(), 'the migration refuses to start' );
lnkup_t_same( false, (bool) get_option( LNKup_Document_Migration::ENABLED_OPT ), 'the migration flag stays off' );

echo "Unreachable probe\n";
lnkup_pu_fake( 'error' );
lnkup_pu_settings( false );
$after = LNKup_Passport_Settings::save( array( 'passport_enabled' => true, 'document_vault_sharing' => true ) );
lnkup_t_same( 'unknown', LNKup_Private_Uploads_Probe::last()['status'], 'a failed request is not treated as protected' );
lnkup_t_same( false, $after['document_vault_sharing'], 'sharing stays off when protection is not verified' );
lnkup_t_same( false, LNKup_Document_Migration::enable(), 'the migration stays off when protection is not verified' );

echo "Protected folder\n";
lnkup_pu_fake( 'forbidden' );
lnkup_pu_settings( false );
$after = LNKup_Passport_Settings::save( array( 'passport_enabled' => true, 'document_vault_sharing' => true ) );
lnkup_t_same( 'protected', LNKup_Private_Uploads_Probe::last()['status'], 'a 403 without the token is protected' );
lnkup_t_same( true, $after['document_vault_sharing'], 'sharing can be switched on once the folder is protected' );
lnkup_t_same( false, LNKup_Passport_Settings::$sharing_blocked, 'no refusal is reported' );
lnkup_t_same( true, LNKup_Document_Migration::enable(), 'the migration starts once the folder is protected' );
LNKup_Document_Migration::disable();

echo "Hosting regresses after rollout\n";
lnkup_pu_fake( 'exposed' );
update_option( LNKup_Document_Migration::ENABLED_OPT, 1, false );
LNKup_Document_Migration::run_scheduled_batch();
lnkup_t_same( false, (bool) get_option( LNKup_Document_Migration::ENABLED_OPT ), 'a scheduled batch stops itself when the folder becomes exposed' );
lnkup_t_same( false, LNKup_Passport_Settings::enabled( 'document_vault_sharing' ), 'sharing switches off at runtime when the folder becomes exposed' );

remove_all_filters( 'pre_http_request' );
update_option( 'lnkup_settings', $saved_settings, false );
foreach ( array( LNKup_Private_Uploads_Probe::OPTION => $saved_probe, LNKup_Document_Migration::ENABLED_OPT => $saved_migrate ) as $option => $value ) {
	if ( null === $value ) {
		delete_option( $option );
	} else {
		update_option( $option, $value, false );
	}
}
lnkup_t_finish();
