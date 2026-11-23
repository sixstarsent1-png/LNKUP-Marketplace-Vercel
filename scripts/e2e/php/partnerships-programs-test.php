<?php
/**
 * Partnership OS Stages 4–5: affiliate and strategic partner programs (local wp-env only).
 *
 * - schema 1.1.0, registry, seeded draft rules
 * - applications: validation, disclosures, honeypot, rate limit, duplicate privacy
 * - strategic organizations: find-or-create, lead linkage, no duplicates
 * - approval: lnkup_partner role, AFF- code, conditional transitions, suspend revokes the code
 * - attribution through an affiliate code; earning gated by a published rule; rule stamping
 * - agreement rule overrides the program rule and never replaces it
 * - affiliate vs connector conflict; City Connector regression (GMV rule, ranks)
 * - partner leads carry partner_id; public terms copy; REST permissions
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/partnerships-programs-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Partnership OS — affiliate and strategic partner programs\n";

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.NonceVerification

$connectors = LNKup_Connector_Tables::connectors_table();
$refs       = LNKup_Treasury_Tables::referrals_table();
$rewards    = LNKup_Treasury_Tables::referral_rewards_table();
$codes      = LNKup_Treasury_Tables::referral_codes_table();
$rules_t    = LNKup_Commission_Rules::table();
$orgs_t     = LNKup_Partner_Organizations::table();
$reviews_t  = LNKup_Partner_Reviews::table();
$tag        = strtolower( wp_generate_password( 6, false ) );
$ip         = '203.0.113.' . wp_rand( 1, 250 ) . '-' . $tag;
$orig       = get_option( 'lnkup_settings' );
$orig_ps    = get_option( LNKup_Partnerships::SETTINGS_OPTION, null );
$cleanup    = array( 'connectors' => array(), 'orgs' => array(), 'rules' => array(), 'leads' => array() );

$settings                              = (array) $orig;
$settings['connector_rewards_enabled'] = true;
update_option( 'lnkup_settings', $settings );
update_option( LNKup_Partnerships::SETTINGS_OPTION, array( 'partnerships_arbiter_mode' => 'enforce', 'partnerships_conflict_window_hours' => 72, 'connector_leads_enabled' => true ), false );

$admin = lnkup_t_user( 'administrator' );
wp_set_current_user( $admin );

$apply = static function ( string $program, array $over = array() ) use ( $tag, &$cleanup ) {
	static $n = 0;
	++$n;
	$data = array_merge(
		array(
			'name'        => "PW Applicant {$n}",
			'email'       => "pw-apply-{$tag}-{$n}@example.test",
			'city'        => 'Charlotte',
			'state'       => 'NC',
			'website'     => "https://pw-{$tag}-{$n}.example",
			'experience'  => 'Ran markets for 5 years',
			'audience'    => 'Night markets',
			'disclosures' => 1,
		),
		$over
	);
	$res = LNKup_Partner_Applications::submit( $program, $data, 'test-ip-' . $tag . '-' . $n );
	if ( is_array( $res ) && ! empty( $res['id'] ) ) {
		$cleanup['connectors'][] = (int) $res['id'];
	}
	return $res;
};
$track_rewards = static function ( int $payment_id ) use ( $wpdb, $rewards, &$cleanup ): void {
	$cleanup['reward_payments'][] = $payment_id;
};

// --------------------------------------------------------------- Schema and registry.
echo "\n[schema]\n";
LNKup_Partner_Tables::maybe_upgrade();
lnkup_t_same( LNKup_Partner_Tables::SCHEMA_VERSION, (string) get_option( LNKup_Partner_Tables::SCHEMA_OPTION ), 'current schema version recorded' );
foreach ( array( 'program', 'organization', 'website', 'partner_id', 'application', 'reviewed_by', 'reviewed_at' ) as $col ) {
	lnkup_t_assert( (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$connectors} LIKE %s", $col ) ), "connectors.{$col} exists" );
}
foreach ( array( 'category', 'domain', 'name_key', 'stage', 'commission_rule_id', 'lead_id', 'next_follow_up_at' ) as $col ) {
	lnkup_t_assert( (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$orgs_t} LIKE %s", $col ) ), "org_partners.{$col} exists" );
}
lnkup_t_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$connectors} WHERE program <> 'city_connector' AND program NOT IN ('affiliate','strategic_partner')" ), 'every existing account has a valid program' );
lnkup_t_assert( LNKup_Partner_Programs::accepts_applications( 'affiliate' ), 'affiliate program accepts applications' );
lnkup_t_assert( LNKup_Partner_Programs::accepts_applications( 'strategic_partner' ), 'strategic partner program accepts applications' );
lnkup_t_assert( ! LNKup_Partner_Programs::accepts_applications( 'city_connector' ), 'city connectors keep their own interest form' );
lnkup_t_same( 'draft', (string) $wpdb->get_var( "SELECT status FROM {$rules_t} WHERE program = 'affiliate' AND version = 1" ), 'affiliate v1 seeded as draft' );
lnkup_t_same( 'draft', (string) $wpdb->get_var( "SELECT status FROM {$rules_t} WHERE program = 'strategic_partner' AND version = 1" ), 'strategic partner v1 seeded as draft' );
lnkup_t_assert( null === LNKup_Commission_Rules::active_rule( 'affiliate' ), 'no affiliate rule in force yet' );
lnkup_t_assert( (bool) get_role( 'lnkup_partner' ), 'lnkup_partner role exists' );

// --------------------------------------------------------------- Applications.
echo "\n[applications]\n";
$bad = LNKup_Partner_Applications::submit( 'affiliate', array( 'name' => 'X' ), 'test-ip-bad-' . $tag );
lnkup_t_same( 'lnkup_apply_invalid', is_wp_error( $bad ) ? $bad->get_error_code() : '', 'missing email, city and disclosures rejected' );
$nodisc = $apply( 'affiliate', array( 'disclosures' => 0 ) );
lnkup_t_same( 'lnkup_apply_invalid', is_wp_error( $nodisc ) ? $nodisc->get_error_code() : '', 'disclosures are required' );
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$connectors}" );
$hp     = $apply( 'affiliate', array( LNKup_Partner_Applications::HONEYPOT => 'spam' ) );
lnkup_t_assert( is_array( $hp ) && ! isset( $hp['id'] ), 'honeypot submission gets a generic success' );
lnkup_t_same( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$connectors}" ), 'honeypot submission stores nothing' );
lnkup_t_same( 'lnkup_apply_program', is_wp_error( $x = LNKup_Partner_Applications::submit( 'city_connector', array(), 'ip' ) ) ? $x->get_error_code() : '', 'unknown application program rejected' );

$aff = $apply( 'affiliate' );
lnkup_t_assert( is_array( $aff ) && ! empty( $aff['id'] ), 'affiliate application stored' );
$aff_id  = (int) ( $aff['id'] ?? 0 );
$aff_row = LNKup_Connector::get( $aff_id );
lnkup_t_same( 'affiliate', (string) $aff_row['program'], 'stored as an affiliate account' );
lnkup_t_same( 'pending', (string) $aff_row['status'], 'status Submitted' );
lnkup_t_assert( '' !== (string) ( json_decode( (string) $aff_row['application'], true )['disclosures_accepted'] ?? '' ), 'disclosure acceptance time recorded' );
$dup = LNKup_Partner_Applications::submit( 'affiliate', array( 'name' => 'Again', 'email' => (string) $aff_row['email'], 'city' => 'Charlotte', 'disclosures' => 1 ), 'test-ip-dup-' . $tag );
lnkup_t_assert( is_array( $dup ) && ! isset( $dup['id'] ), 'duplicate email returns "already on file" without creating a row' );

$rl_ip = 'test-ip-rate-' . $tag;
for ( $i = 0; $i < LNKup_Partner_Applications::HOURLY_LIMIT; $i++ ) {
	LNKup_Partner_Applications::submit( 'affiliate', array( 'name' => 'Rate', 'email' => "pw-rate-{$tag}-{$i}@example.test", 'city' => 'Charlotte', 'disclosures' => 1 ), $rl_ip );
}
$cleanup['connectors'] = array_merge( $cleanup['connectors'], array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$connectors} WHERE email LIKE %s", 'pw-rate-' . $tag . '%' ) ) ) );
$limited = LNKup_Partner_Applications::submit( 'affiliate', array( 'name' => 'Rate', 'email' => "pw-rate-{$tag}-x@example.test", 'city' => 'Charlotte', 'disclosures' => 1 ), $rl_ip );
lnkup_t_same( 'lnkup_apply_rate_limited', is_wp_error( $limited ) ? $limited->get_error_code() : '', 'sixth application from one connection in an hour is rate limited' );
delete_transient( 'lnkup_papply_' . substr( hash_hmac( 'sha256', $rl_ip, wp_salt( 'nonce' ) ), 0, 32 ) );

// --------------------------------------------------------------- Strategic organizations.
echo "\n[strategic organizations]\n";
$lead_id = LNKup_Org_Leads::create( array( 'name' => "Queen City Markets {$tag}", 'website' => "https://qcm-{$tag}.example", 'city' => 'Charlotte', 'state' => 'NC' ), array( 'actor' => $admin ) );
$cleanup['leads'][] = is_wp_error( $lead_id ) ? 0 : (int) $lead_id;
$st = $apply( 'strategic_partner', array( 'organization' => "Queen City Markets {$tag}", 'website' => "https://qcm-{$tag}.example/partners", 'category' => 'market_association' ) );
$st_id   = (int) ( $st['id'] ?? 0 );
$st_row  = LNKup_Connector::get( $st_id );
$org_id  = (int) ( $st_row['partner_id'] ?? 0 );
$cleanup['orgs'][] = $org_id;
lnkup_t_assert( $org_id > 0, 'strategic application created a partner organization' );
$org = LNKup_Partner_Organizations::get( $org_id );
lnkup_t_same( 'prospect', (string) $org['stage'], 'new organization starts as a prospect' );
lnkup_t_same( (int) $lead_id, (int) $org['lead_id'], 'organization linked to the existing CRM lead, not duplicated' );
$st2 = $apply( 'strategic_partner', array( 'organization' => 'Queen City Markets Inc', 'website' => "https://www.qcm-{$tag}.example" ) );
lnkup_t_same( $org_id, (int) LNKup_Connector::get( (int) ( $st2['id'] ?? 0 ) )['partner_id'], 'second contact from the same domain joins the same organization' );
$no_org = $apply( 'strategic_partner', array( 'organization' => '' ) );
lnkup_t_same( 'lnkup_apply_invalid', is_wp_error( $no_org ) ? $no_org->get_error_code() : '', 'strategic applications require an organization' );
$upd = LNKup_Partner_Organizations::update( $org_id, array( 'stage' => 'negotiation', 'cities_served' => 'Charlotte, Raleigh' ), $admin );
lnkup_t_same( 'negotiation', is_array( $upd ) ? $upd['stage'] : '', 'stage updated' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::audit_table() . " WHERE action = 'partner_org_stage_changed' AND entity_id = %d", $org_id ) ), 'stage change audited' );
lnkup_t_assert( is_wp_error( LNKup_Partner_Organizations::update( $org_id, array( 'stage' => 'won' ), $admin ) ), 'unknown stage rejected' );
lnkup_t_assert( is_wp_error( LNKup_Partner_Organizations::update( $org_id, array( 'commission_rule_id' => (int) LNKup_Commission_Rules::active_rule( 'city_connector' )['id'] ), $admin ) ), 'non-strategic rule cannot be an agreement rule' );

// --------------------------------------------------------------- Approval.
echo "\n[approval]\n";
lnkup_t_assert( is_wp_error( LNKup_Partner_Applications::transition( $aff_id, 'suspend', $admin ) ), 'cannot suspend a pending application' );
$rev = LNKup_Partner_Applications::transition( $aff_id, 'review', $admin );
lnkup_t_same( 'under_review', is_array( $rev ) ? $rev['status'] : '', 'moved to under review' );
$ok = LNKup_Partner_Applications::transition( $aff_id, 'approve', $admin, 'Strong network' );
lnkup_t_same( 'active', is_array( $ok ) ? $ok['status'] : ( is_wp_error( $ok ) ? $ok->get_error_message() : '' ), 'affiliate approved' );
$aff_row = LNKup_Connector::get( $aff_id );
$aff_uid = (int) $aff_row['user_id'];
$GLOBALS['lnkup_test_cleanup'][] = array( 'user', $aff_uid );
lnkup_t_assert( in_array( 'lnkup_partner', (array) get_userdata( $aff_uid )->roles, true ), 'approved affiliate has the lnkup_partner role' );
lnkup_t_assert( ! in_array( 'lnkup_connector', (array) get_userdata( $aff_uid )->roles, true ), 'affiliate is not made a City Connector' );
lnkup_t_assert( LNKup_Roles::is_connector( $aff_uid ), 'partner account passes the partner permission check' );
$aff_code = LNKup_Connector::get_code_for_connector( $aff_id );
lnkup_t_assert( 0 === strpos( (string) $aff_code['code'], 'AFF-' ), 'affiliate code uses the AFF- prefix' );
lnkup_t_same( 'affiliate', (string) $aff_code['program'], 'code tagged with the affiliate program' );
lnkup_t_assert( is_wp_error( LNKup_Partner_Applications::transition( $aff_id, 'approve', $admin ) ), 'second approve rejected' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::audit_table() . " WHERE action = 'partner_application_approve' AND entity_id = %d", $aff_id ) ), 'approval audited once' );
lnkup_t_same( array( 'home', 'referrals', 'leads', 'earnings', 'resources', 'profile' ), array_keys( LNKup_Connector::dashboard_views( $aff_row ) ), 'affiliate dashboard has no ranks, studio, launch plan or cities' );
lnkup_t_same( 'home', LNKup_Connector::resolve_dashboard_view( 'ranks', $aff_row ), 'ranks view is not reachable for affiliates' );
lnkup_t_assert( LNKup_Connector_Rewards::terms( $aff_id )['terms_pending'], 'affiliate terms are pending while the rule is a draft' );

// --------------------------------------------------------------- Attribution and earning gate.
echo "\n[attribution and earning]\n";
$_GET['ref'] = (string) $aff_code['code'];
$org_user    = lnkup_t_user( 'lnkup_organizer' );
unset( $_GET['ref'] );
$aff_ref = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$refs} WHERE referred_user_id = %d", $org_user ), ARRAY_A );
lnkup_t_same( 'affiliate', (string) ( $aff_ref['program'] ?? '' ), 'signup through an affiliate code creates an affiliate referral' );
lnkup_t_same( $aff_uid, (int) ( $aff_ref['referrer_user_id'] ?? 0 ), 'referrer is the affiliate' );
lnkup_t_same( 'affiliate', (string) get_user_meta( $org_user, '_lnkup_signup_referrer_type', true ), 'signup meta records the program' );
$vendor = lnkup_t_user( 'lnkup_vendor' );
$p1     = lnkup_t_payment( array( 'organizer_user_id' => $org_user, 'vendor_user_id' => $vendor, 'vendor_spot_fee' => 200, 'application_fee_cents' => 2000, 'payment_status' => 'paid' ) );
do_action( 'lnkup_payment_completed', $p1 );
lnkup_t_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rewards} WHERE payment_id = %d", $p1 ) ), 'nothing is earned under a draft rule' );
lnkup_t_assert( null === LNKup_Connector_Rewards::estimate_from_gmv_total( 1000, $aff_id ), 'no estimate is shown while terms are pending' );

$v2 = LNKup_Commission_Rules::create_version( 'affiliate', array( 'name' => 'Test affiliate', 'basis' => 'platform_fee', 'rate' => 25, 'term_months' => 12, 'hold_days' => 30 ), $admin );
$cleanup['rules'][] = (int) ( $v2['id'] ?? 0 );
LNKup_Commission_Rules::publish( (int) $v2['id'], $admin );
lnkup_t_assert( ! LNKup_Connector_Rewards::terms( $aff_id )['terms_pending'], 'publishing the rule ends the pending state' );
$p2 = lnkup_t_payment( array( 'organizer_user_id' => $org_user, 'vendor_user_id' => $vendor, 'vendor_spot_fee' => 200, 'application_fee_cents' => 2000, 'payment_status' => 'paid' ) );
do_action( 'lnkup_payment_completed', $p2 );
$r2 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$rewards} WHERE payment_id = %d", $p2 ), ARRAY_A );
lnkup_t_same( '5.00', (string) ( $r2['amount'] ?? '' ), '25% of a $20 LNKup fee' );
lnkup_t_same( 'affiliate', (string) ( $r2['program'] ?? '' ), 'reward tagged with the affiliate program' );
lnkup_t_same( (int) $v2['id'], (int) ( $r2['rule_id'] ?? 0 ), 'reward stamped with the published rule' );
lnkup_t_same( 'platform_fee', (string) ( $r2['basis'] ?? '' ), 'basis is the LNKup fee' );
lnkup_t_same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Connector_Tables::connectors_table() . " WHERE id = %d AND rank_calculated_at IS NOT NULL", $aff_id ) ), 'no rank is calculated for an affiliate' );

// --------------------------------------------------------------- Agreement rule override.
echo "\n[agreement rule]\n";
$ok2     = LNKup_Partner_Applications::transition( $st_id, 'approve', $admin );
$st_row  = LNKup_Connector::get( $st_id );
$GLOBALS['lnkup_test_cleanup'][] = array( 'user', (int) $st_row['user_id'] );
lnkup_t_same( 'active', is_array( $ok2 ) ? $ok2['status'] : '', 'strategic contact approved' );
lnkup_t_assert( 0 === strpos( (string) LNKup_Connector::get_code_for_connector( $st_id )['code'], 'PTR-' ), 'strategic code uses the PTR- prefix' );
lnkup_t_assert( LNKup_Connector_Rewards::terms( $st_id )['terms_pending'], 'strategic terms pending with no program or agreement rule' );
$program_rule = LNKup_Commission_Rules::create_version( 'strategic_partner', array( 'name' => 'Strategic default', 'basis' => 'platform_fee', 'rate' => 10 ), $admin );
$agreement    = LNKup_Commission_Rules::create_version( 'strategic_partner', array( 'name' => 'QCM agreement', 'basis' => 'platform_fee', 'rate' => 40, 'agreement_ref' => 'QCM-2027' ), $admin );
$cleanup['rules'][] = (int) $program_rule['id'];
$cleanup['rules'][] = (int) $agreement['id'];
LNKup_Commission_Rules::publish( (int) $program_rule['id'], $admin );
LNKup_Commission_Rules::publish( (int) $agreement['id'], $admin );
lnkup_t_same( (int) $program_rule['id'], (int) ( LNKup_Commission_Rules::active_rule( 'strategic_partner' )['id'] ?? 0 ), 'publishing an agreement rule never replaces the program rule' );
lnkup_t_same( 'active', (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$rules_t} WHERE id = %d", (int) $program_rule['id'] ) ), 'program rule still active' );
LNKup_Partner_Organizations::update( $org_id, array( 'commission_rule_id' => (int) $agreement['id'] ), $admin );
lnkup_t_same( (int) $agreement['id'], (int) ( LNKup_Connector_Rewards::rule_for( $st_id )['id'] ?? 0 ), 'contact earns under the organization agreement rule' );
lnkup_t_same( 40.0, (float) LNKup_Connector_Rewards::terms( $st_id )['share_percent'], 'agreement rate applies' );

// --------------------------------------------------------------- Leads, conflict, suspension.
echo "\n[leads and conflicts]\n";
$lead = LNKup_Partnerships::submit_connector_lead( $st_row, array( 'name' => "PW Partner Lead {$tag}", 'website' => "https://pw-plead-{$tag}.example", 'city' => 'Raleigh' ) );
$plead = (int) ( $lead['lead']['id'] ?? 0 );
$cleanup['leads'][] = $plead;
$plead_row = $plead ? LNKup_Org_Leads::get_row( $plead ) : array();
lnkup_t_same( $org_id, (int) ( $plead_row['partner_id'] ?? 0 ), 'lead from a strategic contact carries partner_id' );
lnkup_t_same( 'partner', (string) ( $plead_row['source'] ?? '' ), 'lead source is partner' );
lnkup_t_same( 1, LNKup_Partner_Organizations::performance( $org_id )['leads'], 'organization performance counts the lead' );

$cc_user = lnkup_t_user( 'lnkup_connector' );
$now     = current_time( 'mysql' );
$wpdb->insert( $connectors, array( 'user_id' => $cc_user, 'display_name' => 'PW CC', 'email' => "pw-cc-{$tag}@example.test", 'status' => 'active', 'approved_at' => '2025-01-01 00:00:00', 'created_at' => $now, 'updated_at' => $now ) );
$cc_id = (int) $wpdb->insert_id;
$cleanup['connectors'][] = $cc_id;
LNKup_Connector::generate_code( $cc_id );
$cc_code   = LNKup_Connector::get_code_for_connector( $cc_id );
$contested = lnkup_t_user( 'lnkup_organizer' );
$wpdb->insert( $refs, array( 'referrer_user_id' => $cc_user, 'referred_user_id' => $contested, 'referral_code_id' => (int) $cc_code['id'], 'program' => 'city_connector', 'target_role' => 'lnkup_organizer', 'status' => 'registered', 'created_at' => '2026-04-01 10:00:00' ) );
$c1 = (int) $wpdb->insert_id;
$wpdb->insert( $refs, array( 'referrer_user_id' => $aff_uid, 'referred_user_id' => $contested, 'referral_code_id' => (int) $aff_code['id'], 'program' => 'affiliate', 'target_role' => 'lnkup_organizer', 'status' => 'registered', 'created_at' => '2026-04-01 11:00:00' ) );
$c2 = (int) $wpdb->insert_id;
$decision = LNKup_Attribution_Arbiter::resolve_user( $contested );
lnkup_t_same( array( $c1 => 'conflict', $c2 => 'conflict' ), $decision, 'affiliate and connector claims on one organizer go to review' );

echo "\n[city connector regression]\n";
$cc_org = lnkup_t_user( 'lnkup_organizer' );
update_user_meta( $cc_org, '_lnkup_referred_by_connector_id', $cc_id );
$wpdb->insert( $refs, array( 'referrer_user_id' => $cc_user, 'referred_user_id' => $cc_org, 'referral_code_id' => (int) $cc_code['id'], 'program' => 'city_connector', 'target_role' => 'lnkup_organizer', 'status' => 'registered', 'created_at' => $now ) );
$p3 = lnkup_t_payment( array( 'organizer_user_id' => $cc_org, 'vendor_user_id' => $vendor, 'vendor_spot_fee' => 250, 'application_fee_cents' => 1500, 'payment_status' => 'paid' ) );
do_action( 'lnkup_payment_completed', $p3 );
$r3 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$rewards} WHERE payment_id = %d", $p3 ), ARRAY_A );
lnkup_t_same( '25.00', (string) ( $r3['amount'] ?? '' ), 'City Connector still earns 10% of the booth price' );
lnkup_t_same( 'city_connector', (string) ( $r3['program'] ?? '' ), 'and the reward stays in the City Connector program' );
lnkup_t_assert( ! LNKup_Connector_Rewards::terms( $cc_id )['terms_pending'], 'City Connector terms are never pending' );
lnkup_t_assert( count( LNKup_Connector::dashboard_views( LNKup_Connector::get( $cc_id ) ) ) === 10, 'City Connector dashboard keeps every view' );

echo "\n[mobile sign-in]\n";
$may_use_mobile = static function ( int $user_id ): bool {
	$method = new ReflectionMethod( 'LNKup_Auth_REST', 'user_may_use_mobile' );
	$method->setAccessible( true );
	return (bool) $method->invoke( null, get_userdata( $user_id ) );
};
lnkup_t_assert( $may_use_mobile( $aff_uid ), 'active affiliate may sign in to the mobile app' );
lnkup_t_assert( $may_use_mobile( $cc_user ), 'active City Connector may sign in to the mobile app' );
lnkup_t_assert( ! $may_use_mobile( lnkup_t_user( 'lnkup_partner' ) ), 'partner role without an active account may not' );

echo "\n[suspension]\n";
$sus = LNKup_Partner_Applications::transition( $aff_id, 'suspend', $admin, 'Policy review' );
lnkup_t_same( 'paused', is_array( $sus ) ? $sus['status'] : '', 'affiliate suspended' );
lnkup_t_assert( null === LNKup_Connector::resolve_code( (string) $aff_code['code'] ), 'suspended affiliate code no longer resolves' );
lnkup_t_assert( ! LNKup_Roles::is_connector( $aff_uid ), 'suspended affiliate loses dashboard access' );
lnkup_t_assert( ! $may_use_mobile( $aff_uid ), 'suspended affiliate can no longer sign in to the mobile app' );

// --------------------------------------------------------------- Public copy and REST.
echo "\n[public and rest]\n";
$cc_terms = LNKup_Partner_Public::terms_copy( 'city_connector' );
lnkup_t_assert( $cc_terms['published'] && false !== strpos( implode( ' ', $cc_terms['lines'] ), '10% of the booth price' ), 'City Connector page shows terms from the rule' );
lnkup_t_assert( false !== strpos( implode( ' ', $cc_terms['lines'] ), 'not guaranteed' ), 'public copy states earnings are not guaranteed' );
$wpdb->update( $rules_t, array( 'status' => 'draft', 'effective_from' => null ), array( 'id' => (int) $v2['id'] ) );
$aff_terms = LNKup_Partner_Public::terms_copy( 'affiliate' );
lnkup_t_assert( ! $aff_terms['published'] && false === strpos( implode( ' ', $aff_terms['lines'] ), '%' ), 'affiliate page shows pending terms and no percentage' );
wp_set_current_user( 0 );
$req = new WP_REST_Request( 'POST', '/lnkup/v1/partners/apply' );
$req->set_header( 'Content-Type', 'application/json' );
$req->set_body( wp_json_encode( array( 'program' => 'affiliate', 'name' => 'REST Applicant', 'email' => "pw-rest-{$tag}@example.test", 'city' => 'Durham', 'disclosures' => 1 ) ) );
$res = rest_do_request( $req );
lnkup_t_same( 200, $res->get_status(), 'anonymous visitors can apply over REST' );
$cleanup['connectors'] = array_merge( $cleanup['connectors'], array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$connectors} WHERE email = %s", "pw-rest-{$tag}@example.test" ) ) ) );
lnkup_t_same( 401, rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/partners/applications' ) )->get_status(), 'anonymous cannot list applications' );
lnkup_t_same( 401, rest_do_request( new WP_REST_Request( 'POST', '/lnkup/v1/partners/applications/' . $aff_id . '/approve' ) )->get_status(), 'anonymous cannot approve' );
lnkup_t_same( 401, rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/partners/organizations' ) )->get_status(), 'anonymous cannot read partner organizations' );
wp_set_current_user( (int) $st_row['user_id'] );
lnkup_t_same( 403, rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/partners/organizations' ) )->get_status(), 'partner contact cannot read the admin organization list' );
$dash = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/connector/dashboard' ) );
lnkup_t_same( 'strategic_partner', (string) ( $dash->get_data()['program'] ?? '' ), 'dashboard reports the account program' );
lnkup_t_assert( ! in_array( 'ranks', (array) ( $dash->get_data()['views'] ?? array() ), true ), 'dashboard views exclude ranks for partners' );
wp_set_current_user( $admin );
lnkup_t_same( 200, rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/partners/applications' ) )->get_status(), 'admin lists applications' );

// --------------------------------------------------------------- Cleanup.
foreach ( array( $p1, $p2, $p3 ) as $pid ) {
	$wpdb->delete( $rewards, array( 'payment_id' => $pid ) );
}
$all_conn = array_filter( array_unique( $cleanup['connectors'] ) );
foreach ( $all_conn as $cid ) {
	$wpdb->delete( $codes, array( 'connector_id' => $cid ) );
	$wpdb->delete( LNKup_Connector_Tables::events_table(), array( 'connector_id' => $cid ) );
	$wpdb->delete( $connectors, array( 'id' => $cid ) );
}
foreach ( array( $org_user, $contested, $cc_org, $vendor ) as $uid ) {
	$wpdb->delete( $refs, array( 'referred_user_id' => $uid ) );
	$wpdb->delete( $reviews_t, array( 'referred_user_id' => $uid ) );
}
foreach ( array_filter( $cleanup['rules'] ) as $rid ) {
	$wpdb->delete( $rules_t, array( 'id' => $rid ) );
}
foreach ( array_filter( array_unique( $cleanup['orgs'] ) ) as $oid ) {
	$wpdb->delete( $orgs_t, array( 'id' => $oid ) );
}
foreach ( array_filter( $cleanup['leads'] ) as $lid ) {
	$wpdb->delete( LNKup_Org_Leads::table(), array( 'id' => $lid ) );
	$wpdb->delete( LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACTIVITY ), array( 'lead_id' => $lid ) );
}
update_option( 'lnkup_settings', $orig );
if ( null === $orig_ps ) {
	delete_option( LNKup_Partnerships::SETTINGS_OPTION );
} else {
	update_option( LNKup_Partnerships::SETTINGS_OPTION, $orig_ps, false );
}
LNKup_Partnerships::flush_overview();
// phpcs:enable

lnkup_t_finish();
