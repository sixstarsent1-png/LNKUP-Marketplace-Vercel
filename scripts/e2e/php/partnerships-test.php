<?php
/**
 * Partnership OS foundation integration tests (local wp-env only).
 *
 * - schema: tables, additive columns, unique idempotency index, seeded v1 rules
 * - rules: GMV vs platform-fee basis, drafts, publish/supersede, historical lookup
 * - connector ledger: rule stamping, hold gate, atomic approve / mark-paid, hold/release
 * - proportional reversals for unpaid rewards; one clawback review for paid rewards
 * - attribution arbiter: earliest wins, conflict review + resolution, payment-level winner, shadow mode
 * - connector leads: submit, duplicate privacy, per-connector visibility
 * - territories and REST permissions
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/partnerships-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Partnership OS foundation\n";

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$refs       = LNKup_Treasury_Tables::referrals_table();
$rewards    = LNKup_Treasury_Tables::referral_rewards_table();
$codes      = LNKup_Treasury_Tables::referral_codes_table();
$connectors = LNKup_Connector_Tables::connectors_table();
$rules_t    = LNKup_Commission_Rules::table();
$programs_t = LNKup_Partner_Tables::table( LNKup_Partner_Tables::PROGRAMS );
$reviews_t  = LNKup_Partner_Reviews::table();
$terr_t     = LNKup_Partner_Tables::table( LNKup_Partner_Tables::TERRITORIES );

$orig_settings  = get_option( 'lnkup_settings' );
$orig_partner   = get_option( LNKup_Partnerships::SETTINGS_OPTION, null );
$cleanup        = array( 'refs' => array(), 'rewards' => array(), 'codes' => array(), 'connectors' => array(), 'rules' => array(), 'reviews' => array(), 'leads' => array(), 'territories' => array() );

$set_mode = static function ( string $mode, int $window = 72 ): void {
	update_option( LNKup_Partnerships::SETTINGS_OPTION, array( 'partnerships_arbiter_mode' => $mode, 'partnerships_conflict_window_hours' => $window, 'connector_leads_enabled' => true ), false );
};
$settings = (array) $orig_settings;
$settings['connector_rewards_enabled']           = true;
$settings['connector_vendor_referrals_rewarded'] = false;
update_option( 'lnkup_settings', $settings );

$admin = lnkup_t_user( 'administrator' );
wp_set_current_user( $admin );

$make_connector = static function ( string $approved_at ) use ( $wpdb, $connectors, &$cleanup ): array {
	$user = lnkup_t_user( 'lnkup_connector' );
	$now  = current_time( 'mysql' );
	$wpdb->insert( $connectors, array( 'user_id' => $user, 'display_name' => 'Test Connector ' . $user, 'email' => 'cc' . $user . '@example.test', 'city' => 'Charlotte', 'state' => 'NC', 'market_slug' => 'charlotte', 'status' => 'active', 'approved_at' => $approved_at, 'created_at' => $approved_at, 'updated_at' => $now ) );
	$cid = (int) $wpdb->insert_id;
	$cleanup['connectors'][] = $cid;
	LNKup_Connector::generate_code( $cid );
	$code = LNKup_Connector::get_code_for_connector( $cid );
	$cleanup['codes'][] = (int) ( $code['id'] ?? 0 );
	return array( 'id' => $cid, 'user_id' => $user, 'code_id' => (int) ( $code['id'] ?? 0 ), 'code' => (string) ( $code['code'] ?? '' ) );
};
$referral = static function ( array $row ) use ( $wpdb, $refs, &$cleanup ): int {
	$wpdb->insert( $refs, array_merge( array( 'status' => 'registered', 'created_at' => current_time( 'mysql' ) ), $row ) );
	$cleanup['refs'][] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
};
$track_rewards = static function ( int $payment_id ) use ( $wpdb, $rewards, &$cleanup ): void {
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$rewards} WHERE payment_id = %d", $payment_id ) ) as $id ) {
		$cleanup['rewards'][] = (int) $id;
	}
};

// --------------------------------------------------------------- Schema.
echo "\n[schema]\n";
LNKup_Partner_Tables::maybe_upgrade();
lnkup_t_assert( LNKup_Partner_Tables::ready(), 'schema version recorded' );
foreach ( array( LNKup_Partner_Tables::PROGRAMS, LNKup_Partner_Tables::RULES, LNKup_Partner_Tables::TERRITORIES, LNKup_Partner_Tables::REVIEWS ) as $t ) {
	lnkup_t_assert( LNKup_Partner_Tables::exists( $t ), "table {$t} exists" );
}
foreach ( array( 'reversed_amount', 'rule_id', 'rule_version', 'basis', 'basis_amount', 'payout_reference', 'hold_reason', 'held_from_status', 'idempotency_key' ) as $col ) {
	lnkup_t_assert( (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$rewards} LIKE %s", $col ) ), "rewards.{$col} exists" );
}
lnkup_t_assert( (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$refs} LIKE %s", 'attribution_status' ) ), 'referrals.attribution_status exists' );
lnkup_t_assert( (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$connectors} LIKE %s", 'commission_rule_id' ) ), 'connectors.commission_rule_id exists' );
$idx = $wpdb->get_row( "SHOW INDEX FROM {$rewards} WHERE Key_name = 'idempotency_key'", ARRAY_A );
lnkup_t_same( '0', (string) ( $idx['Non_unique'] ?? '' ), 'idempotency_key is a unique index' );
$col = $wpdb->get_row( "SHOW COLUMNS FROM {$rewards} LIKE 'idempotency_key'", ARRAY_A );
lnkup_t_same( 'YES', (string) ( $col['Null'] ?? '' ), 'idempotency_key is nullable so key-less inserts never collide' );
lnkup_t_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rewards} WHERE idempotency_key = ''" ), 'no reward row left with an empty key' );

$cc_v1 = LNKup_Commission_Rules::active_rule( 'city_connector' );
lnkup_t_same( 'booth_gmv', (string) ( $cc_v1['basis'] ?? '' ), 'city connector v1 is a booth GMV rule' );
lnkup_t_same( 1, (int) ( $cc_v1['version'] ?? 0 ), 'city connector active rule is v1 until v2 is published' );
lnkup_t_same( 10.0, (float) ( $cc_v1['rate'] ?? 0 ), 'v1 follows the existing 10% setting' );
$cc_v2 = $wpdb->get_row( "SELECT * FROM {$rules_t} WHERE program = 'city_connector' AND version = 2", ARRAY_A );
lnkup_t_same( 'draft', (string) ( $cc_v2['status'] ?? '' ), 'city connector v2 (platform fee) seeded as draft' );
lnkup_t_same( 'platform_fee', (string) ( LNKup_Commission_Rules::active_rule( 'organizer_recruit' )['basis'] ?? '' ), 'organizer recruit v1 is a platform-fee rule' );
lnkup_t_same( 'payment_stackable', (string) ( LNKup_Commission_Rules::active_rule( 'vendor_referral' )['stacking_policy'] ?? '' ), 'vendor referral credit may stack on a payment' );

// --------------------------------------------------------------- Rules.
echo "\n[rules]\n";
$pay_rule = lnkup_t_payment( array( 'vendor_spot_fee' => 250, 'application_fee_cents' => 2000, 'payment_status' => 'paid' ) );
$calc = LNKup_Commission_Rules::calculate( $cc_v1, $pay_rule );
lnkup_t_same( 25.0, $calc['amount'], '10% of a $250 booth is $25 under the legacy rule' );
$calc = LNKup_Commission_Rules::calculate( array( 'basis' => 'platform_fee', 'rate' => 25, 'cap' => 0 ), $pay_rule );
lnkup_t_same( 5.0, $calc['amount'], '25% of a $20 LNKup fee is $5' );
lnkup_t_same( 20.0, $calc['basis_amount'], 'platform-fee basis reads the application fee' );
lnkup_t_same( 3.0, LNKup_Commission_Rules::amount_from_base( array( 'basis' => 'platform_fee', 'rate' => 50, 'cap' => 3 ), 20.0 ), 'cap clips the amount' );

$wpdb->insert( $programs_t, array( 'slug' => 'zz_test_prog', 'name' => 'Test', 'status' => 'active', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$test_prog_id = (int) $wpdb->insert_id;
$d1 = LNKup_Commission_Rules::create_version( 'zz_test_prog', array( 'name' => 'A', 'basis' => 'platform_fee', 'rate' => 0 ), $admin );
lnkup_t_assert( is_array( $d1 ) && 'draft' === $d1['status'], 'draft created' );
$cleanup['rules'][] = (int) ( $d1['id'] ?? 0 );
$bad = LNKup_Commission_Rules::publish( (int) $d1['id'], $admin );
lnkup_t_same( 'lnkup_rule_value', is_wp_error( $bad ) ? $bad->get_error_code() : '', 'a rule without a rate cannot be published' );
$d2 = LNKup_Commission_Rules::create_version( 'zz_test_prog', array( 'name' => 'B', 'basis' => 'platform_fee', 'rate' => 15 ), $admin );
$cleanup['rules'][] = (int) ( $d2['id'] ?? 0 );
lnkup_t_same( 2, (int) $d2['version'], 'versions increment' );
$wpdb->update( $rules_t, array( 'status' => 'active', 'effective_from' => '2020-01-01 00:00:00' ), array( 'id' => (int) $d1['id'] ) );
$wpdb->update( $rules_t, array( 'rate' => 10 ), array( 'id' => (int) $d1['id'] ) );
$pub = LNKup_Commission_Rules::publish( (int) $d2['id'], $admin );
lnkup_t_assert( is_array( $pub ) && 'active' === $pub['status'], 'v2 published' );
lnkup_t_same( 'superseded', (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$rules_t} WHERE id = %d", (int) $d1['id'] ) ), 'previous version superseded, not deleted' );
lnkup_t_same( (int) $d1['id'], (int) ( LNKup_Commission_Rules::active_rule( 'zz_test_prog', '2021-06-01 00:00:00' )['id'] ?? 0 ), 'historical lookup returns the rule in force at that time' );
lnkup_t_same( (int) $d2['id'], (int) ( LNKup_Commission_Rules::active_rule( 'zz_test_prog' )['id'] ?? 0 ), 'current lookup returns the new rule' );
$again = LNKup_Commission_Rules::publish( (int) $d2['id'], $admin );
lnkup_t_assert( is_wp_error( $again ), 'publishing twice is rejected' );

// --------------------------------------------------------------- Connector ledger.
echo "\n[connector ledger]\n";
$set_mode( 'enforce' );
$cc    = $make_connector( '2025-01-01 00:00:00' );
$org   = lnkup_t_user( 'lnkup_organizer' );
$vend  = lnkup_t_user( 'lnkup_vendor' );
update_user_meta( $org, '_lnkup_referred_by_connector_id', $cc['id'] );
$cc_ref = $referral( array( 'referrer_user_id' => $cc['user_id'], 'referred_user_id' => $org, 'referral_code_id' => $cc['code_id'], 'program' => 'city_connector', 'target_role' => 'lnkup_organizer', 'created_at' => '2026-01-01 00:00:00' ) );
$rule = LNKup_Connector_Rewards::rule_for( $cc['id'] );
lnkup_t_same( (int) $cc_v1['id'], (int) ( $rule['id'] ?? 0 ), 'connector approved before v2 is locked to v1' );
lnkup_t_same( (int) $cc_v1['id'], (int) $wpdb->get_var( $wpdb->prepare( "SELECT commission_rule_id FROM {$connectors} WHERE id = %d", $cc['id'] ) ), 'rule lock persisted on the connector' );

$p1 = lnkup_t_payment( array( 'organizer_user_id' => $org, 'vendor_user_id' => $vend, 'vendor_spot_fee' => 200, 'application_fee_cents' => 1500, 'payment_status' => 'paid' ) );
$r1 = LNKup_Connector_Rewards::maybe_queue_paid_booking_reward( $cc['id'], $cc_ref, $p1, true );
$track_rewards( $p1 );
lnkup_t_assert( $r1 > 0, 'reward queued for the winning connector referral' );
$row = LNKup_Connector_Rewards::get_reward( $r1 );
lnkup_t_same( '20.00', (string) $row['amount'], '10% of a $200 booth' );
lnkup_t_same( 'booth_gmv', (string) $row['basis'], 'basis stamped' );
lnkup_t_same( '200.00', (string) $row['basis_amount'], 'basis amount stamped' );
lnkup_t_same( 1, (int) $row['rule_version'], 'rule version stamped' );
lnkup_t_assert( '' !== (string) $row['idempotency_key'], 'idempotency key stamped' );
lnkup_t_same( $r1, LNKup_Connector_Rewards::maybe_queue_paid_booking_reward( $cc['id'], $cc_ref, $p1, true ), 'second queue for the same payment returns the same row' );

$early = LNKup_Connector_Rewards::approve_reward( $r1 );
lnkup_t_same( 'lnkup_reward_on_hold', is_wp_error( $early ) ? $early->get_error_code() : '', 'cannot approve during the hold period' );
lnkup_t_assert( is_wp_error( LNKup_Connector_Rewards::mark_paid( $r1, 'X' ) ), 'cannot mark a pending reward paid' );
$wpdb->update( $rewards, array( 'available_at' => '2020-01-01 00:00:00' ), array( 'id' => $r1 ) );
lnkup_t_same( true, LNKup_Connector_Rewards::hold( $r1, 'test' ), 'pending reward can be held' );
lnkup_t_assert( is_wp_error( LNKup_Connector_Rewards::approve_reward( $r1 ) ), 'held reward cannot be approved' );
lnkup_t_same( true, LNKup_Connector_Rewards::release( $r1 ), 'held reward released' );
lnkup_t_same( 'pending', (string) LNKup_Connector_Rewards::get_reward( $r1 )['reward_status'], 'release restores the previous status' );
lnkup_t_same( true, LNKup_Connector_Rewards::approve_reward( $r1 ), 'approve after hold' );
lnkup_t_assert( is_wp_error( LNKup_Connector_Rewards::approve_reward( $r1 ) ), 'second approve rejected' );
lnkup_t_same( true, LNKup_Connector_Rewards::mark_paid( $r1, 'CHK-1001' ), 'mark paid once' );
$second = LNKup_Connector_Rewards::mark_paid( $r1, 'CHK-1002' );
lnkup_t_same( 'lnkup_reward_state', is_wp_error( $second ) ? $second->get_error_code() : '', 'second mark-paid rejected' );
lnkup_t_same( 'CHK-1001', (string) LNKup_Connector_Rewards::get_reward( $r1 )['payout_reference'], 'payout reference kept from the first payout' );

echo "\n[proportional reversal]\n";
$p2 = lnkup_t_payment( array( 'organizer_user_id' => $org, 'vendor_user_id' => $vend, 'vendor_spot_fee' => 100, 'payment_status' => 'paid' ) );
$r2 = LNKup_Connector_Rewards::maybe_queue_paid_booking_reward( $cc['id'], $cc_ref, $p2, true );
$track_rewards( $p2 );
LNKup_Connector_Rewards::reverse_for_payment( $p2, 'refund', 0.25 );
$row = LNKup_Connector_Rewards::get_reward( $r2 );
lnkup_t_same( '2.50', (string) $row['reversed_amount'], '25% refund reverses 25% of an unpaid reward' );
lnkup_t_same( 'pending', (string) $row['reward_status'], 'partially reversed reward keeps its status' );
lnkup_t_same( 7.5, LNKup_Connector_Rewards::net_amount( $row ), 'net amount is what is left' );
LNKup_Connector_Rewards::reverse_for_payment( $p2, 'refund', 0.25 );
lnkup_t_same( '2.50', (string) LNKup_Connector_Rewards::get_reward( $r2 )['reversed_amount'], 'repeated sync at the same ratio changes nothing' );
LNKup_Connector_Rewards::reverse_for_payment( $p2, 'refund', 1.0 );
lnkup_t_same( 'reversed', (string) LNKup_Connector_Rewards::get_reward( $r2 )['reward_status'], 'full refund reverses the rest' );

LNKup_Connector_Rewards::reverse_for_payment( $p1, 'refund', 0.5 );
LNKup_Connector_Rewards::reverse_for_payment( $p1, 'refund', 0.75 );
lnkup_t_same( 'paid', (string) LNKup_Connector_Rewards::get_reward( $r1 )['reward_status'], 'paid reward is never silently changed' );
$claw = LNKup_Partner_Reviews::get_by_key( 'clawback:' . $r1 );
if ( $claw ) {
	$cleanup['reviews'][] = (int) $claw['id'];
}
lnkup_t_same( 15.0, (float) ( $claw['amount'] ?? 0 ), 'one clawback review, raised to 75% of the paid reward' );

$bal = LNKup_Connector_Rewards::balances_for_connector( $cc['id'] );
lnkup_t_same( 20.0, $bal['paid'], 'paid balance is the paid reward' );
lnkup_t_same( 10.0, $bal['reversed'], 'reversed balance counts the reversed reward once' );

// --------------------------------------------------------------- Arbiter.
echo "\n[arbiter: per account]\n";
$recruiter = lnkup_t_user( 'lnkup_organizer' );
$contested = lnkup_t_user( 'lnkup_organizer' );
$a1 = $referral( array( 'referrer_user_id' => $cc['user_id'], 'referred_user_id' => $contested, 'referral_code_id' => $cc['code_id'], 'program' => 'city_connector', 'target_role' => 'lnkup_organizer', 'created_at' => '2026-03-01 10:00:00' ) );
$a2 = $referral( array( 'referrer_user_id' => $recruiter, 'referred_user_id' => $contested, 'program' => 'organizer_recruit', 'target_role' => 'organizer', 'status' => 'active', 'created_at' => '2026-03-01 12:00:00' ) );
$decision = LNKup_Attribution_Arbiter::resolve_user( $contested );
lnkup_t_same( 'conflict', $decision[ $a1 ] ?? '', 'first claim inside the window is a conflict' );
lnkup_t_same( 'conflict', $decision[ $a2 ] ?? '', 'second claim inside the window is a conflict' );
$review = LNKup_Partner_Reviews::get_by_key( 'conflict:' . $contested );
lnkup_t_assert( (bool) $review, 'conflict review opened' );
if ( $review ) {
	$cleanup['reviews'][] = (int) $review['id'];
}
LNKup_Attribution_Arbiter::resolve_user( $contested );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reviews_t} WHERE referred_user_id = %d", $contested ) ), 'resolving again does not open a second review' );
$gate = LNKup_Attribution_Arbiter::may_reward( $a2, $p1 );
lnkup_t_same( array( 'allow' => false, 'reason' => 'conflict' ), $gate, 'enforce mode holds a conflicted claim' );
$set_mode( 'shadow' );
lnkup_t_same( true, LNKup_Attribution_Arbiter::may_reward( $a2, $p1 )['allow'], 'shadow mode logs but allows' );
$set_mode( 'enforce' );
$resolved = LNKup_Attribution_Arbiter::resolve_conflict( (int) $review['id'], $a2, $admin, 'Organizer intro confirmed' );
lnkup_t_assert( is_array( $resolved ), 'conflict resolved' );
lnkup_t_same( 'winning', (string) $wpdb->get_var( $wpdb->prepare( "SELECT attribution_status FROM {$refs} WHERE id = %d", $a2 ) ), 'chosen referral wins' );
lnkup_t_same( 'superseded', (string) $wpdb->get_var( $wpdb->prepare( "SELECT attribution_status FROM {$refs} WHERE id = %d", $a1 ) ), 'other claim superseded' );
lnkup_t_assert( is_wp_error( LNKup_Attribution_Arbiter::resolve_conflict( (int) $review['id'], $a1, $admin ) ), 'a resolved conflict cannot be re-decided' );

$late_user = lnkup_t_user( 'lnkup_organizer' );
$l1 = $referral( array( 'referrer_user_id' => $cc['user_id'], 'referred_user_id' => $late_user, 'referral_code_id' => $cc['code_id'], 'program' => 'city_connector', 'target_role' => 'lnkup_organizer', 'created_at' => '2026-03-01 10:00:00' ) );
$l2 = $referral( array( 'referrer_user_id' => $recruiter, 'referred_user_id' => $late_user, 'program' => 'organizer_recruit', 'target_role' => 'organizer', 'status' => 'active', 'created_at' => '2026-03-10 10:00:00' ) );
$decision = LNKup_Attribution_Arbiter::resolve_user( $late_user );
lnkup_t_same( array( $l1 => 'winning', $l2 => 'superseded' ), $decision, 'outside the window the earliest referral wins' );
lnkup_t_same( 'superseded', LNKup_Attribution_Arbiter::may_reward( $l2, 0 )['reason'], 'superseded referral cannot earn' );

echo "\n[arbiter: per payment]\n";
$pv_vendor = lnkup_t_user( 'lnkup_vendor' );
$pv_ref    = $referral( array( 'referrer_user_id' => $recruiter, 'referred_user_id' => $pv_vendor, 'program' => 'organizer_recruit', 'target_role' => 'vendor', 'status' => 'active' ) );
$p3        = lnkup_t_payment( array( 'organizer_user_id' => $org, 'vendor_user_id' => $pv_vendor, 'vendor_spot_fee' => 150, 'application_fee_cents' => 1200, 'payment_status' => 'paid' ) );
lnkup_t_same( $cc_ref, LNKup_Attribution_Arbiter::winner_for_payment( $p3 ), 'organizer-side referral wins the payment' );
lnkup_t_same( 'payment_winner_other', LNKup_Attribution_Arbiter::may_reward( $pv_ref, $p3 )['reason'], 'vendor-side exclusive referral is blocked on the same payment' );
$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rewards} WHERE payment_id = %d", $p3 ) );
do_action( 'lnkup_payment_completed', $p3 );
$track_rewards( $p3 );
$after = $wpdb->get_results( $wpdb->prepare( "SELECT program, connector_id FROM {$rewards} WHERE payment_id = %d", $p3 ), ARRAY_A );
lnkup_t_same( 0, $before, 'no rewards before completion' );
lnkup_t_same( 1, count( $after ), 'exactly one commission for a payment with two referred parties' );
lnkup_t_same( 'city_connector', (string) ( $after[0]['program'] ?? '' ), 'and it goes to the organizer-side connector' );
do_action( 'lnkup_payment_completed', $p3 );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rewards} WHERE payment_id = %d", $p3 ) ), 'duplicate completion webhook adds nothing' );

// --------------------------------------------------------------- Connector leads.
echo "\n[connector leads]\n";
$cc_row = LNKup_Connector::get( $cc['id'] );
$tag    = strtolower( wp_generate_password( 6, false ) );
$sub    = LNKup_Partnerships::submit_connector_lead( $cc_row, array( 'name' => 'Queen City Night Market ' . $tag, 'website' => 'https://qcnm-' . $tag . '.example', 'city' => 'Charlotte', 'state' => 'NC', 'event_type' => 'Night market', 'reason' => 'Monthly market' ) );
lnkup_t_same( 'submitted', is_array( $sub ) ? $sub['status'] : ( is_wp_error( $sub ) ? $sub->get_error_message() : '' ), 'connector lead submitted' );
$lead_id = (int) ( $sub['lead']['id'] ?? 0 );
if ( $lead_id ) {
	$cleanup['leads'][] = $lead_id;
	$lead = LNKup_Org_Leads::get_row( $lead_id );
	lnkup_t_same( (int) $cc['id'], (int) $lead['assigned_connector_id'], 'lead assigned to the submitting connector' );
	lnkup_t_same( 'city_connector', (string) $lead['source'], 'lead source is city_connector' );
	lnkup_t_same( (int) $cc['user_id'], (int) $lead['referrer_user_id'], 'lead remembers the referrer' );
}
$dup = LNKup_Partnerships::submit_connector_lead( $cc_row, array( 'name' => 'Different Name', 'website' => 'https://qcnm-' . $tag . '.example/about' ) );
lnkup_t_same( 'already_in_pipeline', is_array( $dup ) ? $dup['status'] : '', 'duplicate website returns already_in_pipeline' );
lnkup_t_assert( is_array( $dup ) && ! isset( $dup['lead'] ), 'duplicate response reveals nothing about the existing lead' );
$existing_email = get_userdata( $org )->user_email;
$acct = LNKup_Partnerships::submit_connector_lead( $cc_row, array( 'name' => 'Existing Org', 'email' => $existing_email ) );
lnkup_t_same( 'already_in_pipeline', is_array( $acct ) ? $acct['status'] : '', 'existing LNKup account returns already_in_pipeline' );
$missing = LNKup_Partnerships::submit_connector_lead( $cc_row, array( 'name' => 'No contact' ) );
lnkup_t_same( 'lnkup_lead_contact_required', is_wp_error( $missing ) ? $missing->get_error_code() : '', 'a public contact route is required' );
$mine = LNKup_Partnerships::connector_leads( $cc_row );
lnkup_t_assert( in_array( $lead_id, array_map( static fn( $l ) => (int) $l['id'], $mine['items'] ), true ), 'connector sees their lead' );
lnkup_t_assert( ! isset( $mine['items'][0]['email'] ) && ! isset( $mine['items'][0]['score'] ), 'connector lead shape has no contact data or score' );
$other    = $make_connector( '2025-01-01 00:00:00' );
$theirs   = LNKup_Partnerships::connector_leads( LNKup_Connector::get( $other['id'] ) );
lnkup_t_assert( ! in_array( $lead_id, array_map( static fn( $l ) => (int) $l['id'], $theirs['items'] ), true ), 'another connector cannot see it' );

// --------------------------------------------------------------- Territories.
echo "\n[territories]\n";
$t1 = LNKup_Partnerships::add_territory( $cc['id'], 'state', 'nc', $admin );
$t2 = LNKup_Partnerships::add_territory( $cc['id'], 'state', 'NC', $admin );
$cleanup['territories'][] = (int) ( $t1['id'] ?? 0 );
lnkup_t_same( 'NC', (string) ( $t1['value'] ?? '' ), 'state normalized' );
lnkup_t_same( (int) ( $t1['id'] ?? 0 ), (int) ( $t2['id'] ?? -1 ), 'duplicate territory is idempotent' );
lnkup_t_assert( is_wp_error( LNKup_Partnerships::add_territory( $cc['id'], 'planet', 'Mars', $admin ) ), 'unknown scope rejected' );
lnkup_t_same( 1, count( LNKup_Partnerships::territories( $cc['id'] ) ), 'one territory listed' );

// --------------------------------------------------------------- REST permissions.
echo "\n[rest]\n";
$server = rest_get_server();
$call   = static function ( string $method, string $route, array $body = array() ): int {
	$req = new WP_REST_Request( $method, $route );
	if ( $body ) {
		$req->set_body_params( $body );
	}
	return (int) rest_do_request( $req )->get_status();
};
$routes = $server->get_routes();
foreach ( array( '/lnkup/v1/partners/overview', '/lnkup/v1/partners/ledger', '/lnkup/v1/partners/rules', '/lnkup/v1/partners/reviews', '/lnkup/v1/partners/territories', '/lnkup/v1/connector/leads', '/lnkup/v1/connector/territories' ) as $r ) {
	lnkup_t_assert( isset( $routes[ $r ] ), "route {$r} registered" );
}
wp_set_current_user( 0 );
lnkup_t_same( 401, $call( 'GET', '/lnkup/v1/partners/overview' ), 'anonymous cannot read the overview' );
lnkup_t_same( 401, $call( 'GET', '/lnkup/v1/connector/leads' ), 'anonymous cannot read connector leads' );
wp_set_current_user( $cc['user_id'] );
lnkup_t_same( 403, $call( 'GET', '/lnkup/v1/partners/ledger' ), 'connector cannot read the admin ledger' );
lnkup_t_same( 403, $call( 'POST', '/lnkup/v1/partners/ledger/' . $r1 . '/mark-paid' ), 'connector cannot mark rewards paid' );
lnkup_t_same( 200, $call( 'GET', '/lnkup/v1/connector/leads' ), 'connector reads their own leads' );
lnkup_t_same( 200, $call( 'GET', '/lnkup/v1/connector/territories' ), 'connector reads their territories' );
wp_set_current_user( $vend );
lnkup_t_same( 403, $call( 'GET', '/lnkup/v1/connector/leads' ), 'vendor cannot use connector lead routes' );
wp_set_current_user( $admin );
lnkup_t_same( 200, $call( 'GET', '/lnkup/v1/partners/overview' ), 'admin reads the overview' );
lnkup_t_same( 409, $call( 'POST', '/lnkup/v1/partners/ledger/' . $r1 . '/mark-paid' ), 'admin mark-paid on a paid reward returns 409' );

// --------------------------------------------------------------- Cleanup.
LNKup_Partnerships::flush_overview();
foreach ( $cleanup['rewards'] as $id ) {
	$wpdb->delete( $rewards, array( 'id' => $id ) );
}
foreach ( $cleanup['refs'] as $id ) {
	$wpdb->delete( $refs, array( 'id' => $id ) );
}
foreach ( array_filter( $cleanup['codes'] ) as $id ) {
	$wpdb->delete( $codes, array( 'id' => $id ) );
}
foreach ( $cleanup['connectors'] as $id ) {
	$wpdb->delete( $connectors, array( 'id' => $id ) );
	$wpdb->delete( LNKup_Connector_Tables::events_table(), array( 'connector_id' => $id ) );
}
foreach ( array_filter( $cleanup['rules'] ) as $id ) {
	$wpdb->delete( $rules_t, array( 'id' => $id ) );
}
$wpdb->delete( $programs_t, array( 'id' => $test_prog_id ) );
foreach ( array_filter( $cleanup['reviews'] ) as $id ) {
	$wpdb->delete( $reviews_t, array( 'id' => $id ) );
}
foreach ( array_filter( $cleanup['territories'] ) as $id ) {
	$wpdb->delete( $terr_t, array( 'id' => $id ) );
}
foreach ( $cleanup['leads'] as $id ) {
	$wpdb->delete( LNKup_Org_Leads::table(), array( 'id' => $id ) );
	$wpdb->delete( LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACTIVITY ), array( 'lead_id' => $id ) );
}
update_option( 'lnkup_settings', $orig_settings );
if ( null === $orig_partner ) {
	delete_option( LNKup_Partnerships::SETTINGS_OPTION );
} else {
	update_option( LNKup_Partnerships::SETTINGS_OPTION, $orig_partner, false );
}
// phpcs:enable

lnkup_t_finish();
