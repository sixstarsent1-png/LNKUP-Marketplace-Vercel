<?php
/**
 * Plugin Name: LNKup Marketplace
 * Plugin URI: https://lnkup.com
 * Description: Custom marketplace functionality for LNKup — vendor opportunities, applications, payments, and event management.
 * Version: 12.29.0
 * Author: LNKup
 * Author URI: https://lnkup.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lnkup-marketplace
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

define( 'LNKUP_VERSION', '12.29.0' );
define( 'LNKUP_PLUGIN_FILE', __FILE__ );
define( 'LNKUP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LNKUP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LNKUP_MIN_PHP', '7.4' );

function lnkup_php_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				esc_html__( 'LNKup Marketplace requires PHP %1$s or higher. You are running PHP %2$s.', 'lnkup-marketplace' ),
				esc_html( LNKUP_MIN_PHP ),
				esc_html( PHP_VERSION )
			);
			?>
		</p>
	</div>
	<?php
}

if ( version_compare( PHP_VERSION, LNKUP_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', 'lnkup_php_version_notice' );
	return;
}

function lnkup_require( $relative_path ) {
	$path = LNKUP_PLUGIN_DIR . $relative_path;
	if ( ! file_exists( $path ) ) {
		add_action(
			'admin_notices',
			function () use ( $relative_path ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html( sprintf( __( 'LNKup Marketplace is missing a required file: %s', 'lnkup-marketplace' ), $relative_path ) )
				);
			}
		);
		return false;
	}
	require_once $path;
	return true;
}

$lnkup_core_files = array(
	'includes/class-security.php',
	'includes/class-roles.php',
	'includes/class-post-types.php',
	'includes/class-taxonomies.php',
	'includes/class-meta.php',
	'includes/class-capabilities.php',
	'includes/class-feature-flags.php',
	'includes/class-activator.php',
	'includes/class-applications.php',
	'includes/class-opportunity-inventory.php',
	'includes/class-apply-with-profile-view.php',
	'includes/class-application-questions.php',
	'includes/class-application-drafts.php',
	'includes/class-application-groups.php',
	'includes/class-applications-rest.php',
	'includes/class-payments.php',
	'includes/class-packets.php',
	'includes/class-packets-rest.php',
	'includes/class-vendor-event-pass-view.php',
	'includes/class-stripe.php',
	'includes/class-documents.php',
	'includes/class-documents-rest.php',
	'includes/class-vendor-profile-rest.php',
	'includes/class-profile-media.php',
	'includes/class-organizer-profile-rest.php',
	'includes/class-app-links.php',
	'includes/class-notifications.php',
	'includes/class-user-mail.php',
	'includes/class-activation-emails.php',
	'includes/class-push-tables.php',
	'includes/class-push-service.php',
	'includes/class-push-rest.php',
	'includes/class-inbox-tables.php',
	'includes/class-inbox-service.php',
	'includes/class-inbox-rest.php',
	'includes/class-lifecycle-hooks.php',
	'includes/class-event-status.php',
	'includes/class-mobile-auth.php',
	'includes/class-auth-rest.php',
	'includes/class-account-deletion.php',
	'includes/class-account-rest.php',
	'includes/class-organizer-onboarding.php',
	'includes/class-organizer-launch-rest.php',
	'includes/class-organizer-launch-checklist.php',
	'includes/class-organizer-activation.php',
	'includes/class-public-embed.php',
	'includes/class-organizer-setups.php',
	'includes/class-vendor-getting-started.php',
	'includes/class-app-promo.php',
	'includes/class-knowledge-base.php',
	'includes/class-dashboard-tour.php',
	'includes/class-ajax.php',
	'includes/class-geocoder.php',
	'includes/class-trending.php',
	'includes/class-marketplace-index.php',
	'includes/class-discovery.php',
	'includes/class-vendor-personalization.php',
	'includes/class-saved-searches.php',
	'includes/class-recommendations.php',
	'includes/class-collections.php',
	'includes/class-market-pages.php',
	'includes/class-connector-tables.php',
	'includes/class-connector.php',
	'includes/class-connector-attribution.php',
	'includes/class-connector-tracking.php',
	'includes/class-connector-rewards.php',
	'includes/class-connector-ranks.php',
	'includes/class-connector-studio.php',
	'includes/class-connector-rest.php',
	'admin/class-connector-content-admin.php',
	'includes/class-organizer-recruit-rest.php',
	'includes/class-organizer-recruit-render.php',
	'includes/class-marketplace-analytics.php',
	'includes/class-marketplace-cron.php',
	'includes/class-marketplace-rest.php',
	'includes/class-organizer-crm-tables.php',
	'includes/class-organizer-crm.php',
	'includes/class-vendor-readiness.php',
	'includes/class-vendor-timeline.php',
	'includes/class-vendor-crm-profile.php',
	'includes/class-vendor-directory.php',
	'includes/class-vendor-notes.php',
	'includes/class-vendor-tags.php',
	'includes/class-vendor-lists.php',
	'includes/class-vendor-import-tables.php',
	'includes/class-vendor-import.php',
	'includes/class-vendor-flags.php',
	'includes/class-vendor-invites.php',
	'includes/class-outreach-tables.php',
	'includes/class-outreach.php',
	'includes/class-external-events-tables.php',
	'includes/class-search-ingest-tables.php',
	'includes/class-search-client-interface.php',
	'includes/class-search-client-google-cse.php',
	'includes/class-search-query-combinator.php',
	'includes/class-page-extractor.php',
	'includes/class-external-events-timeline.php',
	'includes/class-external-events-demand.php',
	'includes/class-external-events-demand-score.php',
	'includes/class-external-events-recommendations.php',
	'includes/class-external-events-dedup.php',
	'includes/class-external-events-followup.php',
	'includes/class-external-events-cron.php',
	'includes/class-external-events-sync.php',
	'includes/class-external-events-analytics.php',
	'includes/external-sources/interface-external-source-provider.php',
	'includes/external-sources/class-external-source-manual.php',
	'includes/external-sources/class-external-source-csv.php',
	'includes/external-sources/class-external-source-eventbrite.php',
	'includes/external-sources/class-external-source-posh.php',
	'includes/external-sources/class-external-source-rss.php',
	'includes/external-sources/class-external-source-google.php',
	'includes/external-sources/class-external-sources.php',
	'includes/class-external-events.php',
	'includes/class-external-events-import.php',
	'includes/class-external-events-rest.php',
	'includes/class-outreach-cron.php',
	'includes/class-event-roster.php',
	'includes/class-organizer-crm-rest.php',
	'includes/class-reputation-tables.php',
	'includes/class-reliability-metrics.php',
	'includes/class-reputation-score.php',
	'includes/class-vendor-reputation.php',
	'includes/class-vendor-reviews.php',
	'includes/class-vendor-badges.php',
	'includes/class-completed-events.php',
	'includes/class-reputation-render.php',
	'includes/class-reputation-rest.php',
	'includes/helpers.php',
	'includes/class-verification.php',
	'includes/class-verification-rest.php',
	'includes/class-seo.php',
	'includes/class-ui-copy.php',
	'includes/class-vendor-dashboard-data.php',
	'public/class-vendor-dashboard-views.php',
	'public/class-organizer-dashboard-views.php',
	'public/class-shortcodes.php',
	'public/class-registration.php',
	'admin/class-admin.php',
	'admin/class-market-pages-admin.php',
	'admin/class-connector-admin.php',
	'admin/class-outreach-admin.php',
	'admin/class-external-events-admin.php',
	'admin/class-organizer-crm-admin.php',
	'admin/class-reputation-admin.php',
	'admin/class-verification-admin.php',
	'includes/class-operations-tables.php',
	'includes/class-organizer-os-tables.php',
	'includes/class-pass-tokens.php',
	'includes/class-application-review.php',
	'includes/class-waitlist-offers.php',
	'includes/class-application-review-rest.php',
	'includes/class-operations-log.php',
	'includes/class-event-lifecycle.php',
	'includes/class-event-operations.php',
	'includes/class-booths.php',
	'includes/class-parking.php',
	'includes/class-loadin-schedule.php',
	'includes/class-checkins.php',
	'includes/class-support-requests.php',
	'includes/class-incidents.php',
	'includes/class-broadcasts.php',
	'includes/class-event-staff.php',
	'includes/class-ops-analytics.php',
	'includes/class-operations-render.php',
	'includes/class-operations-rest.php',
	'admin/class-operations-admin.php',
	'includes/class-intelligence-tables.php',
	'includes/class-intelligence-snapshots.php',
	'includes/class-intelligence-cron.php',
	'includes/class-event-score.php',
	'includes/class-opportunity-score.php',
	'includes/class-funnel-analytics.php',
	'includes/class-market-trends.php',
	'includes/class-revenue-intelligence.php',
	'includes/class-vendor-reliability-intel.php',
	'includes/class-risk-engine.php',
	'includes/class-recommended-actions.php',
	'includes/class-predictive-placeholders.php',
	'includes/class-post-event-report.php',
	'includes/class-organizer-intelligence.php',
	'includes/class-vendor-intelligence.php',
	'includes/class-intelligence-render.php',
	'includes/class-intelligence-rest.php',
	'admin/class-intelligence-admin.php',
	'includes/class-map-tables.php',
	'includes/class-map-provider.php',
	'includes/class-map-provider-leaflet.php',
	'includes/class-map-provider-static.php',
	'includes/class-maps.php',
	'includes/class-map-elements.php',
	'includes/class-parking-zones.php',
	'includes/class-map-routes.php',
	'includes/class-map-templates.php',
	'includes/class-map-sharing.php',
	'includes/class-map-insights.php',
	'includes/class-map-render.php',
	'includes/class-map-rest.php',
	'admin/class-map-admin.php',
	'includes/class-financial-tables.php',
	'includes/class-financial-audit.php',
	'includes/financial/interface-payment-provider.php',
	'includes/class-payment-provider.php',
	'includes/financial/bootstrap.php',
	'includes/class-stripe-provider.php',
	'includes/class-manual-provider.php',
	'includes/class-transactions.php',
	'includes/class-invoices.php',
	'includes/class-receipts.php',
	'includes/class-wallet.php',
	'includes/class-credits.php',
	'includes/class-refunds.php',
	'includes/class-payouts.php',
	'includes/class-financial-disputes.php',
	'includes/class-installments.php',
	'includes/class-deposits.php',
	'includes/class-promo-codes.php',
	'includes/class-financial-reports.php',
	'includes/class-fraud-flags.php',
	'includes/class-payment-methods.php',
	'includes/class-tax-profiles.php',
	'includes/class-financial.php',
	'includes/class-financial-render.php',
	'includes/class-financial-rest.php',
	'includes/class-financial-cron.php',
	'admin/class-financial-admin.php',
	'admin/class-financial-connect-admin.php',
	'admin/class-financial-reconciliation-admin.php',
	'includes/trust/class-trust-tables.php',
	'includes/trust/class-trust.php',
	'includes/trust/class-audit-log.php',
	'includes/trust/class-trust-cases.php',
	'includes/trust/class-event-authenticity.php',
	'includes/trust/class-event-claims.php',
	'includes/trust/class-organizer-verification.php',
	'includes/trust/class-org-teams.php',
	'includes/class-org-workspace.php',
	'includes/trust/class-trust-rest.php',
	'admin/class-trust-safety-admin.php',
	'includes/class-growth-tables.php',
	'includes/class-growth-kpis.php',
	'includes/class-growth-snapshots.php',
	'includes/class-growth-analytics.php',
	'includes/class-growth-match.php',
	'includes/class-growth-profile-strength.php',
	'includes/class-vendor-profile-form.php',
	'includes/class-growth-goals.php',
	'includes/class-growth-recommendations.php',
	'includes/class-growth-benchmarks.php',
	'includes/class-growth-roi.php',
	'includes/class-growth-relationships.php',
	'includes/class-growth-journey.php',
	'includes/class-growth.php',
	'includes/class-growth-render.php',
	'includes/class-growth-rest.php',
	'includes/class-growth-cron.php',
	'admin/class-growth-admin.php',
	'includes/weather/interface-weather-provider.php',
	'includes/weather/class-weather-provider-openweather.php',
	'includes/class-weather-tables.php',
	'includes/class-weather-risk-engine.php',
	'includes/class-weather-service.php',
	'includes/class-weather-rest.php',
	'includes/class-weather-cron.php',
	'includes/class-weather-render.php',
	'includes/matching/class-match-tables.php',
	'includes/matching/class-match-settings.php',
	'includes/matching/class-match-engine.php',
	'includes/matching/class-match-categories.php',
	'includes/matching/class-match-vendor-index.php',
	'includes/matching/class-match-service.php',
	'includes/matching/class-match-cron.php',
	'includes/matching/class-match-rest.php',
	'includes/matching/class-recruitment.php',
	'includes/matching/class-recruitment-rest.php',
	'includes/matching/class-tours.php',
	'includes/matching/class-tours-rest.php',
	'admin/class-match-admin.php',
	'includes/organizer-growth/class-org-growth-tables.php',
	'includes/organizer-growth/class-org-growth.php',
	'includes/organizer-growth/class-org-lead-pipeline.php',
	'includes/organizer-growth/class-org-lead-dedupe.php',
	'includes/organizer-growth/class-contact-suppression.php',
	'includes/organizer-growth/class-org-leads.php',
	'includes/organizer-growth/class-org-lead-scoring.php',
	'includes/organizer-growth/class-org-lead-lifecycle.php',
	'includes/organizer-growth/class-org-lead-tasks.php',
	'includes/organizer-growth/class-org-lead-import.php',
	'includes/organizer-growth/class-org-growth-metrics.php',
	'includes/organizer-growth/class-org-outreach.php',
	'includes/organizer-growth/class-org-growth-rest.php',
	'admin/class-organizer-growth-admin.php',
	'includes/partnerships/class-partner-tables.php',
	'includes/partnerships/class-partner-programs.php',
	'includes/partnerships/class-commission-rules.php',
	'includes/partnerships/class-partner-reviews.php',
	'includes/partnerships/class-attribution-arbiter.php',
	'includes/partnerships/class-partnerships.php',
	'includes/partnerships/class-partner-organizations.php',
	'includes/partnerships/class-partner-applications.php',
	'includes/partnerships/class-partner-public.php',
	'includes/partnerships/class-partnerships-rest.php',
	'admin/class-partnerships-admin.php',
);

foreach ( $lnkup_core_files as $file ) {
	if ( ! lnkup_require( $file ) ) {
		return;
	}
}

register_activation_hook( __FILE__, array( 'LNKup_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LNKup_Activator', 'deactivate' ) );

function lnkup_marketplace_init() {
	LNKup_Roles::init();
	LNKup_Post_Types::init();
	LNKup_Taxonomies::init();
	LNKup_Meta::init();
	LNKup_Capabilities::init();
	LNKup_Marketplace_Index::init();
	LNKup_Marketplace_Analytics::init();
	LNKup_Marketplace_Cron::init();
	LNKup_Activation_Emails::init();
	LNKup_Market_Pages::init();
	LNKup_Market_Pages::maybe_seed_markets();
	LNKup_Connector::init();
	LNKup_Connector_Ranks::init();
	LNKup_Connector_Studio::init();
	LNKup_Connector_REST::init();
	if ( class_exists( 'LNKup_Connector_Content_Admin' ) ) {
		LNKup_Connector_Content_Admin::init();
	}
	LNKup_Organizer_Recruit_REST::init();
	LNKup_Connector_Admin::init();
	LNKup_Marketplace_REST::init();
	LNKup_Applications_REST::init();
	LNKup_Application_Drafts::init();
	LNKup_Application_Groups::init();
	LNKup_Applications::init();
	LNKup_Documents_REST::init();
	LNKup_Vendor_Profile_REST::init();
	LNKup_Organizer_Profile_REST::init();
	LNKup_App_Links::init();
	LNKup_Packets_REST::init();
	LNKup_Push_REST::init();
	LNKup_App_Promo::init();
	LNKup_Inbox_REST::init();
	LNKup_Lifecycle_Hooks::init();
	LNKup_Event_Status::init();
	LNKup_Mobile_Auth::init();
	LNKup_Auth_REST::init();
	LNKup_Account_Deletion::init();
	LNKup_Account_REST::init();
	LNKup_Organizer_CRM::init();
	LNKup_Organizer_CRM_REST::init();
	LNKup_Organizer_Launch_REST::init();
	LNKup_Organizer_Activation::init();
	LNKup_Public_Embed::init();
	LNKup_Organizer_Setups::init();
	LNKup_Organizer_CRM_Admin::init();
	LNKup_Vendor_Reputation::init();
	LNKup_Reputation_REST::init();
	LNKup_Reputation_Admin::init();
	LNKup_Verification::init();
	LNKup_Verification_REST::init();
	LNKup_Verification_Admin::init();
	LNKup_Event_Lifecycle::init();
	LNKup_Operations_REST::init();
	LNKup_Organizer_OS_Tables::init();
	LNKup_Pass_Tokens::init();
	LNKup_Application_Review::init();
	LNKup_Application_Review_REST::init();
	LNKup_Waitlist_Offers::init();
	LNKup_Operations_Admin::init();
	LNKup_Intelligence_Cron::init();
	LNKup_Intelligence_REST::init();
	LNKup_Intelligence_Admin::init();
	LNKup_Map_REST::init();
	LNKup_Map_Admin::init();
	LNKup_Financial_REST::init();
	LNKup_Financial_Cron::init();
	LNKup_Financial_Admin::init();
	LNKup_Financial_Connect_Admin::init();
	LNKup_Financial_Reconciliation_Admin::init();
	LNKup_Trust::init();
	LNKup_Org_Workspace::init();
	LNKup_Trust_Safety_Admin::init();
	LNKup_Financial_Service::init();
	LNKup_Invoices::init();
	LNKup_Growth_REST::init();
	LNKup_Growth_Cron::init();
	LNKup_Growth_Admin::init();
	LNKup_Weather_REST::init();
	LNKup_Weather_Cron::init();
	LNKup_Match_Categories::init();
	LNKup_Match_Vendor_Index::init();
	LNKup_Match_Cron::init();
	LNKup_Match_REST::init();
	LNKup_Recruitment::init();
	LNKup_Recruitment_REST::init();
	LNKup_Tours::init();
	LNKup_Tours_REST::init();
	LNKup_Match_Admin::init();
	LNKup_Shortcodes::init();
	LNKup_Registration::init();
	LNKup_Outreach_Tables::maybe_upgrade();
	LNKup_Outreach::init();
	LNKup_Outreach_Cron::init();
	LNKup_Outreach_Admin::init();
	LNKup_Org_Growth::init();
	LNKup_Organizer_Growth_Admin::init();
	LNKup_Partnerships::init();
	LNKup_Partnerships_REST::init();
	LNKup_Partnerships_Admin::init();
	LNKup_External_Events::init();
	LNKup_External_Events_REST::init();
	LNKup_External_Events_Admin::init();
	LNKup_External_Events_Cron::init();
	LNKup_Admin::init();
	if ( class_exists( 'LNKup_Market_Pages_Admin' ) ) {
		LNKup_Market_Pages_Admin::init();
	}
	LNKup_Ajax::init();
	if ( class_exists( 'LNKup_User_Mail' ) ) {
		LNKup_User_Mail::init();
	}
	LNKup_Documents::init();
	LNKup_Opportunity_Inventory::init();
	LNKup_Notifications::init();
	LNKup_Stripe::init();
	LNKup_SEO::init();
	LNKup_Activator::maybe_repair_dashboard_pages();
	add_action( 'admin_notices', array( 'LNKup_Activator', 'maybe_dashboard_template_notice' ) );
	add_action( 'template_redirect', 'lnkup_redirect_find_events_alias', 1 );
}
add_action( 'plugins_loaded', 'lnkup_marketplace_init' );

/**
 * Run DB migrations for v2.
 */
function lnkup_maybe_upgrade_db() {
	if ( get_option( 'lnkup_db_version' ) === LNKUP_VERSION ) {
		return;
	}

	$creators = array(
		array( 'LNKup_Marketplace_Index', 'create_tables' ),
		array( 'LNKup_Marketplace_Analytics', 'create_tables' ),
		array( 'LNKup_Organizer_CRM_Tables', 'create_tables' ),
		array( 'LNKup_Reputation_Tables', 'create_tables' ),
		array( 'LNKup_Operations_Tables', 'create_tables' ),
		array( 'LNKup_Intelligence_Tables', 'create_tables' ),
		array( 'LNKup_Map_Tables', 'create_tables' ),
		array( 'LNKup_Financial_Tables', 'create_tables' ),
		array( 'LNKup_Growth_Tables', 'create_tables' ),
		array( 'LNKup_Weather_Tables', 'create_tables' ),
		array( 'LNKup_Outreach_Tables', 'create_tables' ),
		array( 'LNKup_External_Events_Tables', 'create_tables' ),
		array( 'LNKup_Search_Ingest_Tables', 'create_tables' ),
		array( 'LNKup_Vendor_Import_Tables', 'create_tables' ),
		array( 'LNKup_Connector_Tables', 'create_tables' ),
		array( 'LNKup_Push_Tables', 'create_tables' ),
		array( 'LNKup_Inbox_Tables', 'create_tables' ),
		array( 'LNKup_Match_Tables', 'create_tables' ),
		array( 'LNKup_Org_Growth_Tables', 'create_tables' ),
		array( 'LNKup_Trust_Tables', 'create_tables' ),
	);

	foreach ( $creators as $creator ) {
		try {
			call_user_func( $creator );
		} catch ( Throwable $table_error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup table migration failed (' . $creator[0] . '): ' . $table_error->getMessage() );
		}
	}

	try {
		LNKup_Activator::create_pages();
	} catch ( Throwable $page_error ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'LNKup page migration failed: ' . $page_error->getMessage() );
	}

	// Payments paid before this moment are only repaired through the approval-gated repair tool.
	if ( '' === (string) get_option( 'lnkup_fin_auto_recovery_since', '' ) ) {
		add_option( 'lnkup_fin_auto_recovery_since', current_time( 'mysql' ), '', false );
	}

	LNKup_Marketplace_Cron::schedule();
	LNKup_Intelligence_Cron::schedule();
	LNKup_Financial_Cron::schedule();
	LNKup_Growth_Cron::schedule();
	LNKup_Weather_Cron::schedule();
	LNKup_External_Events_Cron::schedule();
	LNKup_Match_Cron::schedule();

	// Taxonomies are registered after this hook; seeding runs later on init.
	update_option( 'lnkup_match_seed_pending', LNKUP_VERSION, false );
	LNKup_Match_Cron::queue_full_rebuild();

	if ( get_option( 'lnkup_taxonomy_seed_version' ) !== LNKUP_VERSION ) {
		try {
			LNKup_Taxonomies::seed_terms();
			update_option( 'lnkup_taxonomy_seed_version', LNKUP_VERSION );
		} catch ( Throwable $taxonomy_error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup taxonomy seed failed: ' . $taxonomy_error->getMessage() );
		}
	}

	if ( class_exists( 'LNKup_Taxonomies' ) ) {
		try {
			LNKup_Taxonomies::maybe_upgrade_event_type_hierarchy();
		} catch ( Throwable $event_type_error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup event type hierarchy upgrade failed: ' . $event_type_error->getMessage() );
		}
	}

	if ( class_exists( 'LNKup_Market_Pages' ) ) {
		LNKup_Market_Pages::maybe_seed_markets();
		LNKup_Market_Pages::register_rewrite_rules();
		flush_rewrite_rules( false );
	}

	if ( class_exists( 'LNKup_Activator' ) ) {
		try {
			LNKup_Activator::migrate_document_modes();
		} catch ( Throwable $doc_mode_error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup document mode migration failed: ' . $doc_mode_error->getMessage() );
		}
	}

	try {
		LNKup_Roles::sync_role_capabilities();
	} catch ( Throwable $role_error ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'LNKup role capability sync failed: ' . $role_error->getMessage() );
	}

	if ( class_exists( 'LNKup_Connector' ) ) {
		try {
			LNKup_Connector::restore_elevated_roles_stripped_by_approval();
		} catch ( Throwable $connector_role_error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup connector admin role restore failed: ' . $connector_role_error->getMessage() );
		}
	}

	update_option( 'lnkup_db_version', LNKUP_VERSION );
	update_option( 'lnkup_needs_deferred_upgrade', LNKUP_VERSION );
}
add_action( 'init', 'lnkup_maybe_upgrade_db', 4 );

/**
 * One-time: open marketplace for launch day 2026-08-02 + seed app release settings.
 */
function lnkup_maybe_apply_launch_day_20260802() {
	if ( get_option( 'lnkup_launch_day_20260802_applied' ) ) {
		return;
	}

	$settings = get_option( 'lnkup_settings', array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$settings['platform_prelaunch_mode'] = false;
	$settings['platform_launch_date']    = '2026-08-02';
	if ( empty( $settings['app_release_datetime'] ) ) {
		$settings['app_release_datetime'] = '2026-08-16 12:00:00';
	}
	if ( empty( $settings['app_release_tz'] ) ) {
		$settings['app_release_tz'] = 'America/New_York';
	}

	update_option( 'lnkup_settings', $settings, false );
	update_option( 'lnkup_launch_day_20260802_applied', '1', false );
}
add_action( 'init', 'lnkup_maybe_apply_launch_day_20260802', 5 );

/**
 * Heavy migrations (index rebuild, backfills) run once in wp-admin.
 */
function lnkup_maybe_deferred_upgrade() {
	LNKup_Activator::maybe_run_deferred_upgrade();
	if ( class_exists( 'LNKup_Fee_Engine' ) ) {
		LNKup_Fee_Engine::migrate_fee_configuration();
	}
	if ( class_exists( 'LNKup_Payments' ) ) {
		LNKup_Payments::backfill_pending_payment_fees();
	}
	if ( class_exists( 'LNKup_Financial_Audit' ) && method_exists( 'LNKup_Financial_Audit', 'migrate_hash_ip_addresses' ) ) {
		LNKup_Financial_Audit::migrate_hash_ip_addresses();
	}
}
add_action( 'admin_init', 'lnkup_maybe_deferred_upgrade', 5 );

/**
 * One-time cleanup for invalid DATE values in marketplace index.
 */
function lnkup_maybe_cleanup_marketplace_index_dates() {
	if ( get_option( 'lnkup_index_deadline_null_v1' ) ) {
		return;
	}
	if ( ! class_exists( 'LNKup_Marketplace_Index' ) ) {
		return;
	}
	try {
		LNKup_Marketplace_Index::cleanup_invalid_dates();
		update_option( 'lnkup_index_deadline_null_v1', 1 );
	} catch ( Throwable $cleanup_error ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'LNKup index date cleanup failed: ' . $cleanup_error->getMessage() );
	}
}
add_action( 'init', 'lnkup_maybe_cleanup_marketplace_index_dates', 5 );

/**
 * One-time fee settings migration (runs on frontend and admin).
 */
function lnkup_maybe_migrate_fee_settings() {
	if ( class_exists( 'LNKup_Fee_Engine' ) ) {
		LNKup_Fee_Engine::migrate_fee_configuration();
	}
}
add_action( 'init', 'lnkup_maybe_migrate_fee_settings', 3 );

function lnkup_get_settings() {
	$defaults = array(
		'default_fee'           => 5.99,
		'stripe_public_key'     => '',
		'stripe_secret_key'     => '',
		'test_mode'             => true,
		'default_packet_lock'   => true,
		'default_documents'     => array( 'certificate_of_insurance', 'business_license' ),
		'notify_vendor'         => true,
		'notify_organizer'      => true,
		'notify_admin'          => true,
		'expo_push_access_token'=> '',
		'support_email'         => get_option( 'admin_email' ),
		'marketplace_phase'     => '1a',
		'map_provider'          => 'leaflet',
		'mapbox_token'          => '',
		'google_maps_key'       => '',
		'payment_provider'      => 'stripe',
		'stripe_webhook_secret' => '',
		'stripe_connect_webhook_secret' => '',
		'stripe_connect_enabled'       => false,
		'connect_platform_country'     => 'US',
		'connect_platform_currency'    => 'usd',
		'application_fee_type'         => 'flat',
		'application_fee_percent'      => 0,
		'application_fee_flat'         => 5.99,
		'vendor_checkout_fee'          => 5.99,
		'organizer_commission_percent' => 6,
		'connect_express_enabled'      => true,
		'connect_instant_payouts_enabled' => false,
		'connect_manual_fallback'      => true,
		'connect_payout_schedule'      => 'manual',
		'connect_return_url'           => '',
		'connect_refresh_url'          => '',
		'fee_payer_mode'               => 'vendor',
		'stripe_billing_enabled'       => false,
		'escrow_enabled'        => true,
		'escrow_hold_percent'   => 100,
		'escrow_release_days'   => 7,
		'reserve_percent'       => 5,
		'reserve_hold_days'     => 30,
		'ach_enabled'                  => false,
		'apple_pay_enabled'            => false,
		'google_pay_enabled'           => false,
		'weather_intelligence_enabled' => true,
		'openweather_api_key'          => '',
		'openweather_api_mode'         => 'current_forecast',
		'weather_units'                => 'imperial',
		'weather_refresh_minutes'      => 30,
		'weather_historical_enabled'   => false,
		'weather_vendor_packet_note'   => true,
		'platform_launch_date'              => '2026-08-02',
		'platform_prelaunch_mode'           => false,
		'platform_prelaunch_admin_preview'  => true,
		'app_release_datetime'              => '2026-08-16 12:00:00',
		'app_release_tz'                    => 'America/New_York',
		'app_store_url'                     => 'https://apps.apple.com/app/id6795865859',
		'app_prompts_enabled'               => true,
		'launch_goal_vendors'          => 4000,
		'launch_goal_organizers'       => 500,
		'outreach_emails_enabled'      => true,
		'outreach_organizer_max_per_campaign' => 100,
		'outreach_admin_max_per_campaign'     => 500,
		'outreach_token_ttl_days'      => 30,
		'outreach_rate_limit_enabled'       => true,
		'outreach_hourly_limit'             => 600,
		'outreach_daily_limit'              => 1500,
		'outreach_batch_size'               => 20,
		'outreach_batch_interval_seconds'   => 180,
		'outreach_resume_time'              => '08:00',
		'saved_search_alerts_enabled'       => true,
		'saved_search_limit_per_vendor'     => 10,
		'saved_search_default_frequency'    => 'daily',
		'saved_search_max_matches_per_email'=> 5,
		'deadline_reminder_favorites'       => true,
		'activation_emails_enabled'         => true,
		'activation_daily_batch'            => 100,
		'crm_import_max_rows_organizer'     => 500,
		'crm_import_max_file_size_mb'       => 5,
		'crm_import_allow_invite'           => true,
		'market_pages_enabled'            => true,
		'connector_rewards_enabled'       => false,
		'connector_reward_mode'           => 'manual',
		'connector_vendor_signup_credit'  => 0,
		'connector_vendor_paid_booking_credit' => 10,
		'connector_organizer_first_booking_credit' => 0,
		'connector_organizer_10_booking_bonus' => 0,
		'connector_booking_share_percent' => 10,
		'connector_booking_share_cap'     => 0,
		'connector_reward_term_months'    => 12,
		'connector_payout_hold_days'      => 30,
		'connector_minimum_payout'        => 50,
		'connector_vendor_referrals_rewarded' => false,
		'connector_terms_url'             => '',
		'connector_support_url'           => '',
		'connector_ranks_enabled'         => true,
		'connector_leaderboard_enabled'   => true,
		'connector_rank_celebration_enabled' => true,
		'connector_rank_bonus_requires_approval' => true,
		'connector_founding_cutoff'       => '',
		'connector_rank_market_builder_activated' => 3,
		'connector_rank_market_builder_bookings' => 15,
		'connector_rank_market_builder_gmv' => 3000,
		'connector_rank_market_builder_bonus' => 100,
		'connector_rank_city_captain_activated' => 10,
		'connector_rank_city_captain_bookings' => 60,
		'connector_rank_city_captain_gmv' => 15000,
		'connector_rank_city_captain_recurring' => 3,
		'connector_rank_city_captain_bonus' => 250,
		'connector_rank_city_director_activated' => 25,
		'connector_rank_city_director_bookings' => 200,
		'connector_rank_city_director_gmv' => 50000,
		'connector_rank_city_director_recurring' => 8,
		'connector_rank_city_director_bonus' => 500,
		'connector_rank_regional_partner_activated' => 50,
		'connector_rank_regional_partner_bookings' => 500,
		'connector_rank_regional_partner_gmv' => 125000,
		'connector_rank_regional_partner_recurring' => 15,
		'connector_rank_regional_partner_cities' => 2,
		'connector_rank_regional_partner_bonus' => 1000,
		'external_sync_enabled'             => false,
		'external_sync_frequency'           => 'daily',
		'external_sync_market_slugs'        => array(),
		'external_sync_max_new_per_run'     => 25,
		'external_sync_max_refresh_per_run' => 50,
		'external_sync_refresh_after_days'  => 7,
		'external_sync_keywords'            => '',
		'external_sync_vendor_filter_enabled' => true,
		'external_sync_vendor_keywords'     => '',
		'external_sync_max_fetch_seconds'   => 300,
		'external_sync_max_candidates_per_market' => 35,
		'external_sync_google_enabled'            => false,
		'google_cse_api_key'                      => '',
		'google_cse_cx'                           => '',
		'google_search_templates'                 => '',
		'google_search_daily_budget'              => 100,
		'google_search_queries_per_run'           => 10,
		'matching_enabled'                        => false,
		'matching_vendor_discovery_enabled'       => false,
		'matching_organizer_discovery_enabled'    => false,
		'matching_tours_enabled'                  => false,
		'apply_wizard_enabled'                    => false,
		'tour_multi_apply_enabled'                => false,
		'application_review_v2_enabled'           => false,
		'waitlist_offers_enabled'                 => false,
		'match_weight_category'                   => 30,
		'match_weight_geo'                        => 25,
		'match_weight_requirements'               => 20,
		'match_weight_preferences'                => 10,
		'match_weight_dates'                      => 10,
		'match_weight_signals'                    => 5,
		'match_min_score'                         => 40,
		'match_invite_vendor_weekly_cap'          => 5,
		'match_invite_org_daily_cap'              => 100,
		'match_invite_batch_max'                  => 50,
		'organizer_growth_enabled'                => false,
		'organizer_growth_outreach_enabled'       => false,
		'org_growth_house_organizer_ids'          => '',
		'org_growth_outreach_dedupe_days'         => 14,
		'org_growth_campaign_daily_cap'           => 50,
		'org_growth_approval_threshold'           => 25,
		'org_growth_contact_stale_days'           => 180,
		'org_growth_sender_name'                  => '',
		'org_growth_sender_postal_address'        => '',
		'org_growth_import_max_rows'              => 5000,
		'org_growth_activation_paid_bookings'     => 3,
		'trust_claim_review_mode'                 => 'log',
		'trust_publish_gate'                      => false,
		'trust_auto_promote_claims'               => false,
		'trust_org_teams'                         => false,
	);
	return wp_parse_args( get_option( 'lnkup_settings', array() ), $defaults );
}

/**
 * Current marketplace rollout phase: 1a, 1b, or 1c.
 */
function lnkup_get_marketplace_phase() {
	$settings = lnkup_get_settings();
	$phase    = isset( $settings['marketplace_phase'] ) ? strtolower( (string) $settings['marketplace_phase'] ) : '1a';
	if ( ! in_array( $phase, array( '1a', '1b', '1c' ), true ) ) {
		return '1a';
	}
	return $phase;
}

/**
 * Whether marketplace phase is at least the given level.
 *
 * @param string $min Minimum phase (1a|1b|1c).
 */
function lnkup_marketplace_phase_at_least( $min ) {
	$order  = array( '1a' => 1, '1b' => 2, '1c' => 3 );
	$current = lnkup_get_marketplace_phase();
	$min     = strtolower( (string) $min );
	return ( $order[ $current ] ?? 1 ) >= ( $order[ $min ] ?? 1 );
}
