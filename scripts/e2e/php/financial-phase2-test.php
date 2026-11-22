<?php
/**
 * Phase 2 money rules: destination application fee (processing fee to LNKup, promo funding),
 * organizer promo scope, cumulative refund cap, refund approval threshold, stuck refund requeue,
 * collected vs pending revenue, escrow split, dispute resolution, subscription guards,
 * cashout token replay, legacy payout completion, balance currency and Stripe key mode.
 *
 * No Stripe calls are made.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/financial-phase2-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Phase 2 money rules\n";

$organizer = lnkup_t_user( 'lnkup_organizer' );
$vendor    = lnkup_t_user( 'lnkup_vendor' );
$admin     = lnkup_t_user( 'administrator' );
$settings  = get_option( 'lnkup_settings', array() );

// 1. Destination application fee: LNKup keeps everything except the organizer's share.
$p1        = lnkup_t_payment( array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'vendor_spot_fee' => 100, 'payment_status' => 'pending' ) );
$bd        = LNKup_Fee_Engine::calculate_for_payment( $p1 );
$share     = (int) round( ( $bd['organizer_net'] + $bd['tax_amount'] ) * 100 );
$gross     = (int) round( ( $bd['booth_reservation_total'] + $bd['processing_fee'] + $bd['tax_amount'] ) * 100 );
update_post_meta( $p1, '_lnkup_total_amount', $gross / 100 );
$fee       = LNKup_Fee_Engine::destination_application_fee_cents( $p1, $gross, $bd );
lnkup_t_same( $gross - $share, $fee, 'application fee = charge − organizer share' );
lnkup_t_assert( $fee > (int) round( $bd['platform_fee'] * 100 ), 'application fee includes the vendor-paid processing fee' );
$half = (int) floor( $gross / 2 );
lnkup_t_same( $half - (int) round( $share * ( $half / $gross ) ), LNKup_Fee_Engine::destination_application_fee_cents( $p1, $half, $bd ), 'deposit gets a proportional organizer share' );

update_post_meta( $p1, '_lnkup_discount_amount', 10 );
update_post_meta( $p1, '_lnkup_promo_funded_by', 'organizer' );
update_post_meta( $p1, '_lnkup_total_amount', ( $gross - 1000 ) / 100 );
lnkup_t_same( $fee, LNKup_Fee_Engine::destination_application_fee_cents( $p1, $gross - 1000, $bd ), 'organizer promo comes out of the organizer share' );
update_post_meta( $p1, '_lnkup_promo_funded_by', 'lnkup' );
lnkup_t_same( $fee - 1000, LNKup_Fee_Engine::destination_application_fee_cents( $p1, $gross - 1000, $bd ), 'marketplace promo is funded by LNKup' );

update_option( 'lnkup_settings', array_merge( $settings, array( 'processing_fee_payee' => 'organizer' ) ) );
lnkup_t_same( (int) round( $bd['platform_fee'] * 100 ), LNKup_Fee_Engine::destination_application_fee_cents( $p1, $gross, $bd ), 'processing_fee_payee=organizer restores the legacy fee' );
update_option( 'lnkup_settings', $settings );

// 2. Organizer promo codes only apply to that organizer's bookings.
$code = 'P2T' . strtoupper( wp_generate_password( 6, false, false ) );
$promo_id = LNKup_Promo_Codes::create( array( 'code' => $code, 'discount_value' => 10, 'scope' => 'organizer', 'organizer_user_id' => $organizer, 'usage_limit' => 1 ) );
lnkup_t_assert( is_wp_error( LNKup_Promo_Codes::validate( $code, $vendor ) ), 'organizer promo without booking context is rejected' );
lnkup_t_assert( is_wp_error( LNKup_Promo_Codes::validate( $code, $vendor, array( 'organizer_user_id' => $admin ) ) ), 'organizer promo on another organizer is rejected' );
lnkup_t_assert( is_array( LNKup_Promo_Codes::validate( $code, $vendor, array( 'organizer_user_id' => $organizer ) ) ), 'organizer promo valid on its own organizer' );
$p2 = lnkup_t_payment( array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'total_amount' => 50 ) );
lnkup_t_same( true, LNKup_Promo_Codes::redeem( $code, $vendor, $p2, 5 ), 'redeem succeeds once' );
lnkup_t_same( 'organizer', get_post_meta( $p2, '_lnkup_promo_funded_by', true ), 'redeem records who funds the discount' );
$p2b = lnkup_t_payment( array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'total_amount' => 50 ) );
lnkup_t_assert( is_wp_error( LNKup_Promo_Codes::redeem( $code, $vendor, $p2b, 5 ) ), 'usage limit holds' );

// 3. Refund amount counts card and credit refunds already given back.
$p3 = lnkup_t_payment( array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'total_amount' => 100, 'payment_status' => 'partially_refunded', 'refund_amount' => 30, 'credit_refund_amount' => 20 ) );
$calc = LNKup_Refund_Rules::calculate( $p3, 'full' );
lnkup_t_same( 50.0, (float) $calc['amount'], 'full refund only returns what is left after card and credit refunds' );
update_post_meta( $p3, '_lnkup_refund_amount', 80 );
lnkup_t_assert( is_wp_error( LNKup_Refund_Rules::calculate( $p3, 'full' ) ), 'nothing left to refund once card + credit cover the booking' );

// 4. Refunds above the approval threshold need an admin.
$refunds = LNKup_Financial_Tables::refunds_table();
$p4      = lnkup_t_payment( array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'total_amount' => 5000, 'payment_status' => 'paid' ) );
$wpdb->insert( $refunds, array( 'payment_post_id' => $p4, 'vendor_user_id' => $vendor, 'organizer_user_id' => $organizer, 'refund_type' => 'credit', 'amount' => 5000, 'status' => 'pending', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$r4  = (int) $wpdb->insert_id;
$res = LNKup_Refunds::approve( $r4, $organizer );
lnkup_t_assert( is_wp_error( $res ) && 'refund_needs_approval' === $res->get_error_code(), 'organizer cannot approve a refund above the threshold' );
lnkup_t_same( 'pending', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$refunds} WHERE id = %d", $r4 ) ), 'refund goes back to pending for an admin' );

// 5. Stuck processing refunds: requeued inside Stripe's idempotency window, flagged after it.
$now = current_time( 'timestamp' );
$wpdb->insert( $refunds, array( 'payment_post_id' => $p4, 'refund_type' => 'full', 'amount' => 1, 'status' => 'processing', 'created_at' => current_time( 'mysql' ), 'updated_at' => gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS ) ) );
$r5a = (int) $wpdb->insert_id;
$wpdb->insert( $refunds, array( 'payment_post_id' => $p4, 'refund_type' => 'full', 'amount' => 1, 'status' => 'processing', 'created_at' => current_time( 'mysql' ), 'updated_at' => gmdate( 'Y-m-d H:i:s', $now - 2 * DAY_IN_SECONDS ) ) );
$r5b = (int) $wpdb->insert_id;
$wpdb->insert( $refunds, array( 'payment_post_id' => $p4, 'refund_type' => 'full', 'amount' => 1, 'status' => 'processing', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$r5c = (int) $wpdb->insert_id;
LNKup_Refunds::requeue_stuck_processing();
$st = static function ( $id ) use ( $wpdb, $refunds ) {
	return $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$refunds} WHERE id = %d", $id ) );
};
lnkup_t_same( 'pending', $st( $r5a ), 'refund stuck for an hour is requeued' );
lnkup_t_same( 'processing', $st( $r5b ), 'refund stuck past the idempotency window is left for an admin' );
lnkup_t_same( 'processing', $st( $r5c ), 'refund still in flight is not touched' );
delete_transient( 'lnkup_refund_stuck_' . $r5b );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$refunds} WHERE payment_post_id = %d", $p4 ) );

// 6. Collected revenue counts only money actually received.
$p6a = lnkup_t_payment( array( 'total_amount' => 100, 'payment_status' => 'partially_paid', 'amount_paid_cents' => 3000 ) );
$p6b = lnkup_t_payment( array( 'total_amount' => 100, 'payment_status' => 'pending' ) );
$p6c = lnkup_t_payment( array( 'total_amount' => 100, 'payment_status' => 'partially_refunded' ) );
lnkup_t_same( 30.0, (float) LNKup_Financial::collected_amount( $p6a ), 'deposit counts what was paid' );
lnkup_t_same( 0.0, (float) LNKup_Financial::collected_amount( $p6b ), 'pending payment collects nothing' );
lnkup_t_same( 100.0, (float) LNKup_Financial::collected_amount( $p6c ), 'partially refunded payment was still collected' );

// 7. Dispute resolution restores the pre-dispute status and only resolves once.
$p7 = lnkup_t_payment( array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'total_amount' => 100, 'payment_status' => 'partially_refunded', 'refund_amount' => 20 ) );
$d7 = LNKup_Financial_Disputes::open( $p7, 'Booth was not as described', $admin, array( 'force' => true ) );
lnkup_t_assert( $d7 > 0, 'dispute opened on a partially refunded payment' );
lnkup_t_same( true, LNKup_Financial_Disputes::resolve( $d7, 'denied', 'Booth matched listing', $admin ), 'dispute denied' );
lnkup_t_same( 'partially_refunded', get_post_meta( $p7, '_lnkup_payment_status', true ), 'denied dispute restores partially_refunded, not paid' );
lnkup_t_assert( is_wp_error( LNKup_Financial_Disputes::resolve( $d7, 'denied', 'again', $admin ) ), 'second resolve is rejected' );

$p7b = lnkup_t_payment( array( 'organizer_user_id' => $organizer, 'vendor_user_id' => $vendor, 'total_amount' => 100, 'payment_status' => 'partially_refunded', 'refund_amount' => 40 ) );
$d7b = LNKup_Financial_Disputes::open( $p7b, 'Booth was not as described', $admin, array( 'force' => true ) );
LNKup_Financial_Disputes::resolve( $d7b, 'approved_credit', '', $admin );
lnkup_t_same( 60.0, (float) LNKup_Refunds::credited_amount( $p7b ), 'credit outcome only credits what was not already refunded' );
lnkup_t_same( 'refunded', get_post_meta( $p7b, '_lnkup_payment_status', true ), 'card + credit now cover the whole booking' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Financial_Tables::disputes_table() . ' WHERE payment_post_id IN (%d, %d)', $p7, $p7b ) );

// 8. Subscriptions: one live subscription per user; no free activation of paid plans; no revival.
$plans = LNKup_Treasury_Tables::subscription_plans_table();
$subs  = LNKup_Treasury_Tables::subscriptions_table();
$slug  = 'p2t-' . strtolower( wp_generate_password( 6, false, false ) );
$wpdb->insert( $plans, array( 'slug' => $slug, 'name' => 'P2 test', 'price_monthly' => 0, 'status' => 'active', 'created_at' => current_time( 'mysql' ) ) );
$free_plan = (int) $wpdb->insert_id;
$wpdb->insert( $plans, array( 'slug' => $slug . '-paid', 'name' => 'P2 paid', 'price_monthly' => 29, 'status' => 'active', 'created_at' => current_time( 'mysql' ) ) );
$paid_plan = (int) $wpdb->insert_id;
update_option( 'lnkup_settings', array_merge( $settings, array( 'stripe_billing_enabled' => 0 ) ) );
lnkup_t_assert( (int) LNKup_Subscriptions::subscribe( $vendor, $free_plan ) > 0, 'first subscription created' );
$dup = LNKup_Subscriptions::subscribe( $vendor, $free_plan );
lnkup_t_assert( is_wp_error( $dup ) && 'already_subscribed' === $dup->get_error_code(), 'second live subscription is rejected' );
update_option( 'lnkup_settings', array_merge( $settings, array( 'stripe_billing_enabled' => 1 ) ) );
$res = LNKup_Subscriptions::subscribe( $organizer, $paid_plan );
lnkup_t_assert( is_wp_error( $res ) && 'plan_not_billable' === $res->get_error_code(), 'paid plan without a Stripe price is not activated for free' );
update_option( 'lnkup_settings', $settings );

$ref = 'sub_test_' . wp_generate_password( 8, false );
$wpdb->insert( $subs, array( 'user_id' => $admin, 'plan_id' => $free_plan, 'status' => 'cancelled', 'provider' => 'stripe', 'provider_ref' => $ref, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$obj = array( 'id' => $ref, 'status' => 'active', 'metadata' => array( 'wp_user_id' => (string) $admin, 'plan_id' => (string) $free_plan ) );
LNKup_Subscriptions::sync_from_stripe_event( $obj, 'customer.subscription.updated' );
LNKup_Subscriptions::sync_from_stripe_event( $obj, 'customer.subscription.updated' );
lnkup_t_same( 'cancelled', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$subs} WHERE provider_ref = %s", $ref ) ), 'late update does not revive a cancelled subscription' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$subs} WHERE provider_ref = %s", $ref ) ), 'replayed events never insert duplicate rows' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$subs} WHERE plan_id IN (%d, %d)", $free_plan, $paid_plan ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$plans} WHERE id IN (%d, %d)", $free_plan, $paid_plan ) );

// 9. Cashout replay reports the original outcome.
$payouts = LNKup_Treasury_Tables::organizer_payouts_table();
$token   = wp_generate_uuid4();
$wpdb->insert( $payouts, array( 'user_id' => $organizer, 'amount' => 10, 'fee_amount' => 0, 'net_amount' => 10, 'payout_type' => 'standard', 'status' => 'failed', 'provider' => 'manual', 'metadata' => wp_json_encode( array( 'client_token' => $token ) ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$res = LNKup_Organizer_Payouts::request_cashout( $organizer, 10, 'standard', $token );
lnkup_t_assert( is_wp_error( $res ) && 'failed' === ( $res->get_error_data()['payout_status'] ?? '' ), 'replayed token of a failed cashout reports the failure' );
$wpdb->delete( $payouts, array( 'user_id' => $organizer ) );

// 10. Legacy payouts complete once.
$legacy = LNKup_Financial_Tables::payouts_table();
$wpdb->insert( $legacy, array( 'organizer_user_id' => $organizer, 'net_amount' => 25, 'status' => 'scheduled', 'provider' => 'manual', 'created_at' => current_time( 'mysql' ) ) );
$lp = (int) $wpdb->insert_id;
lnkup_t_same( true, LNKup_Payouts::mark_completed( $lp, 'tr_test_1' ), 'legacy payout completes' );
lnkup_t_same( false, LNKup_Payouts::mark_completed( $lp, 'tr_test_2' ), 'second completion is refused' );
lnkup_t_same( 'tr_test_1', $wpdb->get_var( $wpdb->prepare( "SELECT provider_ref FROM {$legacy} WHERE id = %d", $lp ) ), 'original reference kept' );
$wpdb->delete( $legacy, array( 'id' => $lp ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Financial_Tables::transactions_table() . " WHERE provider_ref IN ('tr_test_1','tr_test_2') AND organizer_user_id = %d", $organizer ) );

// 11. Balances never present another currency as platform currency; key mode detection.
$client = new LNKup_Stripe_Client( 'sk_test_x' );
$norm   = new ReflectionMethod( 'LNKup_Stripe_Client', 'normalize_balance_payload' );
$norm->setAccessible( true );
$out = $norm->invoke( $client, array( 'available' => array( array( 'currency' => 'cad', 'amount' => 5000 ) ), 'pending' => array() ) );
lnkup_t_same( 0, $out['available_cents'], 'CAD-only balance is not shown as USD' );
lnkup_t_same( array( 'cad' => 5000 ), $out['other_currency_cents'], 'other currencies reported separately' );
lnkup_t_same( 'test', $client->key_mode(), 'sk_test key is test mode' );
lnkup_t_same( 'live', ( new LNKup_Stripe_Client( 'rk_live_x' ) )->key_mode(), 'rk_live key is live mode' );

lnkup_t_finish();
