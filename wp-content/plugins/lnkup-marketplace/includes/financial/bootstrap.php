<?php
/**
 * Financial module bootstrap.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

$lnkup_financial_dir = LNKUP_PLUGIN_DIR . 'includes/financial/';

if ( file_exists( LNKUP_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once LNKUP_PLUGIN_DIR . 'vendor/autoload.php';
}

$lnkup_financial_files = array(
	'class-treasury-tables.php',
	'class-stripe-client.php',
	'class-fee-engine.php',
	'class-financial-status.php',
	'class-payment-timeline.php',
	'class-financial-activity.php',
	'class-stripe-connect.php',
	'class-platform-fees.php',
	'class-organizer-wallet.php',
	'class-organizer-payouts.php',
	'class-escrow.php',
	'class-reserves.php',
	'class-referral-credits.php',
	'class-organizer-recruit-referrals.php',
	'class-sponsor-payments.php',
	'class-subscriptions.php',
	'class-premium-listings.php',
	'class-tax-center.php',
	'class-budget-center.php',
	'class-financial-health-score.php',
	'class-approval-workflows.php',
	'class-refund-rules.php',
	'class-insurance-marketplace.php',
	'class-financing.php',
	'class-manual-provider.php',
	'class-stripe-provider.php',
	'class-stripe-webhooks.php',
	'class-checkout.php',
	'class-financial-service.php',
	'class-financial-reconciliation.php',
	'class-financial-repair.php',
);

foreach ( $lnkup_financial_files as $file ) {
	$path = $lnkup_financial_dir . $file;
	if ( file_exists( $path ) ) {
		require_once $path;
	}
}

if ( class_exists( 'LNKup_Escrow' ) ) {
	LNKup_Escrow::init();
}
if ( class_exists( 'LNKup_Referral_Credits' ) ) {
	LNKup_Referral_Credits::init();
}
if ( class_exists( 'LNKup_Organizer_Recruit_Referrals' ) ) {
	LNKup_Organizer_Recruit_Referrals::init();
}
if ( class_exists( 'LNKup_Financial_Health_Score' ) ) {
	LNKup_Financial_Health_Score::init();
}
if ( class_exists( 'LNKup_Stripe_Connect' ) ) {
	LNKup_Stripe_Connect::init();
}
if ( class_exists( 'LNKup_Financial_Reconciliation' ) ) {
	LNKup_Financial_Reconciliation::init();
}
if ( class_exists( 'LNKup_Financial_Repair' ) ) {
	LNKup_Financial_Repair::init();
}
