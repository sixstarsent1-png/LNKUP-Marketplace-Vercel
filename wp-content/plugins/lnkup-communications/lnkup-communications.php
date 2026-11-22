<?php
/**
 * Plugin Name: LNKup Communications
 * Description: Communications & Engagement OS for LNKup Marketplace — push, email, in-app inbox, automations, campaigns and delivery health. Requires LNKup Marketplace.
 * Version: 1.1.0
 * Author: LNKup
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: lnkup-marketplace
 * Text Domain: lnkup-communications
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'LNKUP_COMMS_VERSION', '1.1.0' );
define( 'LNKUP_COMMS_DB_VERSION', '1.0.0' );
define( 'LNKUP_COMMS_FILE', __FILE__ );
define( 'LNKUP_COMMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LNKUP_COMMS_URL', plugin_dir_url( __FILE__ ) );

// Action Scheduler must load before plugins_loaded so it can register its own version.
if ( file_exists( LNKUP_COMMS_DIR . 'lib/action-scheduler/action-scheduler.php' ) ) {
	require_once LNKUP_COMMS_DIR . 'lib/action-scheduler/action-scheduler.php';
}

/**
 * Files loaded once the marketplace plugin is confirmed active.
 */
function lnkup_comms_files(): array {
	return array(
		'includes/class-comms-settings.php',
		'includes/class-comms-schema.php',
		'includes/class-comms-registry.php',
		'includes/class-comms-context.php',
		'includes/class-comms-templates.php',
		'includes/class-comms-preferences.php',
		'includes/class-comms-frequency.php',
		'includes/class-comms-suppression.php',
		'includes/class-comms-links.php',
		'includes/class-comms-queue.php',
		'includes/channels/class-comms-channel-inbox.php',
		'includes/channels/class-comms-channel-push.php',
		'includes/channels/class-comms-channel-email.php',
		'includes/class-comms-engine.php',
		'includes/class-comms-delegation.php',
		'includes/class-comms-matching.php',
		'includes/class-comms-audiences.php',
		'includes/class-comms-next-steps.php',
		'includes/class-comms-analytics.php',
		'includes/class-comms-health.php',
		'includes/listeners/class-comms-listen-applications.php',
		'includes/listeners/class-comms-listen-drafts.php',
		'includes/listeners/class-comms-listen-payments.php',
		'includes/listeners/class-comms-listen-documents.php',
		'includes/listeners/class-comms-listen-events.php',
		'includes/listeners/class-comms-listen-operations.php',
		'includes/listeners/class-comms-listen-account.php',
		'includes/automations/class-comms-automations.php',
		'includes/automations/class-comms-auto-onboarding.php',
		'includes/automations/class-comms-auto-discovery.php',
		'includes/automations/class-comms-auto-countdown.php',
		'includes/automations/class-comms-auto-digests.php',
		'includes/automations/class-comms-auto-retention.php',
		'includes/automations/class-comms-auto-matching.php',
		'includes/class-comms-campaigns.php',
		'includes/rest/class-comms-rest.php',
		'includes/rest/class-comms-organizer-rest.php',
		'includes/rest/class-comms-admin-rest.php',
		'public/class-comms-shortcodes.php',
		'admin/class-comms-admin.php',
		'includes/class-comms-cli.php',
	);
}

/**
 * Boot after lnkup-marketplace (priority 10) has initialised.
 */
function lnkup_comms_boot(): void {
	if ( ! defined( 'LNKUP_VERSION' ) || ! class_exists( 'LNKup_Notifications' ) || ! class_exists( 'LNKup_Inbox_Service' ) ) {
		add_action( 'admin_notices', 'lnkup_comms_missing_dependency_notice' );
		return;
	}
	foreach ( lnkup_comms_files() as $file ) {
		$path = LNKUP_COMMS_DIR . $file;
		if ( ! file_exists( $path ) ) {
			add_action(
				'admin_notices',
				static function () use ( $file ): void {
					echo '<div class="notice notice-error"><p>' . esc_html( sprintf( 'LNKup Communications is missing %s and has been paused.', $file ) ) . '</p></div>';
				}
			);
			return;
		}
		require_once $path;
	}
	LNKup_Comms_Schema::maybe_upgrade();
	LNKup_Comms_Queue::init();
	LNKup_Comms_Engine::init();
	LNKup_Comms_Delegation::init();
	LNKup_Comms_Channel_Push::init();
	LNKup_Comms_Channel_Email::init();
	LNKup_Comms_Suppression::init();
	LNKup_Comms_Links::init();
	LNKup_Comms_Listen_Applications::init();
	LNKup_Comms_Listen_Drafts::init();
	LNKup_Comms_Listen_Payments::init();
	LNKup_Comms_Listen_Documents::init();
	LNKup_Comms_Listen_Events::init();
	LNKup_Comms_Listen_Operations::init();
	LNKup_Comms_Listen_Account::init();
	LNKup_Comms_Automations::init();
	LNKup_Comms_Campaigns::init();
	LNKup_Comms_REST::init();
	LNKup_Comms_Organizer_REST::init();
	LNKup_Comms_Admin_REST::init();
	LNKup_Comms_Shortcodes::init();
	if ( is_admin() ) {
		LNKup_Comms_Admin::init();
	}
	LNKup_Comms_CLI::register();
	do_action( 'lnkup_comms_loaded' );
}
add_action( 'plugins_loaded', 'lnkup_comms_boot', 20 );

function lnkup_comms_missing_dependency_notice(): void {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'LNKup Communications requires the LNKup Marketplace plugin to be active.', 'lnkup-communications' ) . '</p></div>';
}

register_activation_hook(
	__FILE__,
	static function (): void {
		update_option( 'lnkup_comms_needs_upgrade', 1, false );
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'lnkup_comms_hourly', 'lnkup_comms_daily', 'lnkup_comms_weekly', 'lnkup_comms_push_receipts' ) as $hook ) {
				as_unschedule_all_actions( $hook, array(), 'lnkup-comms' );
			}
		}
	}
);
