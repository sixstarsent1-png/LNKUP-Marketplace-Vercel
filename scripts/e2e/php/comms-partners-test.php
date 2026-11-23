<?php
/**
 * Partner and referral notifications (lnkup-communications + Partnership OS), wp-env only.
 *
 * - flow_partners off sends nothing; partner role recognized; another partner never receives it
 * - referral registered: delivered once; superseded/conflicted referrals suppressed
 * - commission recorded: reversal cancels; bypassed reversal suppressed at send; duplicate hook = one message
 * - commission approved; daily payout grouping; suspended partner suppressed
 * - lead stage/outcome notify only the assigned partner
 * - referral-credit inbox row for a vendor referrer
 * - applicant received/declined emails, suppressed address skipped; admin alerts
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/comms-partners-test.php
 *
 * @package LNKup_Communications
 */

require_once __DIR__ . '/_comms_helpers.php';

global $wpdb;
echo "Partner and referral notifications\n";

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$refs       = LNKup_Treasury_Tables::referrals_table();
$rewards    = LNKup_Treasury_Tables::referral_rewards_table();
$codes      = LNKup_Treasury_Tables::referral_codes_table();
$connectors = LNKup_Connector_Tables::connectors_table();
$tag        = strtolower( wp_generate_password( 6, false ) );
$orig       = get_option( 'lnkup_settings' );
$cleanup    = array( 'connectors' => array(), 'refs' => array(), 'rewards' => array(), 'leads' => array() );

$settings                              = (array) $orig;
$settings['connector_rewards_enabled'] = true;
update_option( 'lnkup_settings', $settings );

$admin = lnkup_ct_user( 'administrator' );
wp_set_current_user( $admin );
add_filter( 'lnkup_comms_partner_admin_recipients', static fn() => array( $admin ) );

$account = static function ( string $program = 'city_connector' ) use ( $wpdb, $connectors, &$cleanup ): array {
	$role = 'city_connector' === $program ? 'lnkup_connector' : 'lnkup_partner';
	$user = lnkup_ct_user( $role );
	$now  = current_time( 'mysql' );
	$wpdb->insert( $connectors, array( 'user_id' => $user, 'program' => $program, 'display_name' => 'PW Partner ' . $user, 'email' => 'pw-partner-' . $user . '@example.test', 'status' => 'active', 'approved_at' => '2025-01-01 00:00:00', 'created_at' => $now, 'updated_at' => $now ) );
	$cid                   = (int) $wpdb->insert_id;
	$cleanup['connectors'][] = $cid;
	LNKup_Connector::generate_code( $cid );
	return array( 'id' => $cid, 'user_id' => $user, 'code_id' => (int) LNKup_Connector::get_code_for_connector( $cid )['id'] );
};
$referral = static function ( array $acct, int $referred, array $over = array() ) use ( $wpdb, $refs, &$cleanup ): int {
	$wpdb->insert( $refs, array_merge( array( 'referrer_user_id' => $acct['user_id'], 'referred_user_id' => $referred, 'referral_code_id' => $acct['code_id'], 'program' => 'city_connector', 'target_role' => 'lnkup_organizer', 'status' => 'registered', 'attribution_status' => 'winning', 'created_at' => current_time( 'mysql' ) ), $over ) );
	$cleanup['refs'][] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
};
$reward = static function ( array $row ) use ( $wpdb, $rewards, &$cleanup ): int {
	$wpdb->insert( $rewards, array_merge( array( 'event_type' => 'connector_booking_fee', 'status' => 'pending', 'reward_status' => 'pending', 'created_at' => current_time( 'mysql' ), 'program' => 'city_connector' ), $row ) );
	$cleanup['rewards'][] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
};
$due = static function ( int $message_id ) use ( $wpdb ): void {
	$wpdb->update( LNKup_Comms_Schema::table( 'messages' ), array( 'scheduled_at' => LNKup_Comms_Schema::now( -60 ) ), array( 'id' => $message_id ) );
};
$partner_inbox = static function ( int $message_id ) use ( $wpdb ): array {
	return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Inbox_Tables::index_table() . ' WHERE client_id = %s', 'comms-' . $message_id ), ARRAY_A );
};
$last = static function ( int $user, string $trigger ): array {
	$rows = lnkup_ct_messages_for( $user, $trigger );
	return $rows ? (array) end( $rows ) : array();
};

$a        = $account();
$b        = $account();
$referred = lnkup_ct_user( 'lnkup_organizer' );

// --------------------------------------------------------------- Flag.
echo "\n[flag]\n";
lnkup_ct_settings( array( 'flow_partners' => false ) );
$r_off = $referral( $a, $referred );
do_action( 'lnkup_partner_referral_attributed', $r_off, $a['id'], $referred, 'city_connector' );
lnkup_t_same( 0, count( lnkup_ct_messages_for( $a['user_id'], 'p_referral_registered' ) ), 'flow_partners off sends nothing' );
lnkup_ct_all_flows();
lnkup_t_assert( LNKup_Comms_Settings::enabled( 'flow_partners' ), 'flow_partners flag exists and can be enabled' );

// --------------------------------------------------------------- Role and ownership.
echo "\n[role]\n";
lnkup_t_assert( in_array( 'partner', LNKup_Comms_Preferences::user_roles( $a['user_id'] ), true ), 'active City Connector has the partner role' );
lnkup_t_assert( ! in_array( 'partner', LNKup_Comms_Preferences::user_roles( $referred ), true ), 'organizer without a partner account does not' );
lnkup_t_same( array( 'all' ), LNKup_Comms_Registry::tabs( 'partner' ), 'partners have a single inbox tab' );

// --------------------------------------------------------------- Referral registered.
echo "\n[referral registered]\n";
$r1 = $referral( $a, $referred );
do_action( 'lnkup_partner_referral_attributed', $r1, $a['id'], $referred, 'city_connector' );
$msg = $last( $a['user_id'], 'p_referral_registered' );
lnkup_t_assert( ! empty( $msg['id'] ), 'referral registered message scheduled' );
lnkup_t_assert( strtotime( $msg['scheduled_at'] . ' UTC' ) > time(), 'scheduled after a short delay so the arbiter can resolve conflicts' );
lnkup_t_same( 0, LNKup_Comms_Engine::dispatch( 'p_referral_registered', $b['user_id'], array( 'connector_id' => $a['id'], 'referral_id' => $r1, 'referred_name' => 'X', 'referred_role' => 'an organizer' ), array( 'window' => 'steal' ) ), 'another partner can never receive this partner\'s message' );
do_action( 'lnkup_partner_referral_attributed', $r1, $a['id'], $referred, 'city_connector' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $a['user_id'], 'p_referral_registered' ) ), 'duplicate hook gives one message' );
$GLOBALS['lnkup_ct_mail'] = array();
$due( (int) $msg['id'] );
LNKup_Comms_Engine::deliver( (int) $msg['id'] );
lnkup_t_same( 'sent', lnkup_ct_message( (int) $msg['id'] )['status'], 'delivered by email' );
$mail = (string) ( $GLOBALS['lnkup_ct_mail'][0]['message'] ?? '' );
lnkup_t_assert( false !== strpos( $mail, get_userdata( $referred )->display_name ) && false === strpos( $mail, '{{' ), 'email names the referral with no unresolved placeholders' );
lnkup_t_assert( false !== strpos( $mail, 'does not earn a commission' ), 'copy says registration alone earns nothing' );
foreach ( array( 'p_application_approved', 'p_referral_registered', 'p_referral_milestone', 'p_commission_recorded', 'p_commission_approved', 'p_payout_recorded', 'p_lead_update' ) as $pk ) {
	$channels = (array) ( LNKup_Comms_Registry::get( $pk )['channels'] ?? array() );
	lnkup_t_assert( in_array( 'inbox', $channels, true ) && in_array( 'push', $channels, true ) && in_array( 'email', $channels, true ), $pk . ' goes to email, inbox and push' );
}
$inbox_row = $partner_inbox( (int) $msg['id'] );
lnkup_t_same( 'partner', (string) ( $inbox_row['role'] ?? '' ), 'partner inbox row is scoped to the partner role, not shown in vendor/organizer inboxes' );
lnkup_t_same( '/(partner)/referrals', (string) ( json_decode( (string) ( $inbox_row['payload'] ?? '' ), true )['deepLink'] ?? '' ), 'inbox row opens the Partner Referrals screen' );
lnkup_t_same( '/(partner)/earnings', LNKup_Comms_Partners::deep_link( 'partner_earnings', array(), 'partner' ), 'commission notices deep link to Partner Earnings' );

$referred2 = lnkup_ct_user( 'lnkup_organizer' );
$r_conf    = $referral( $a, $referred2, array( 'attribution_status' => 'conflict' ) );
lnkup_t_same( 0, LNKup_Comms_Engine::dispatch( 'p_referral_registered', $a['user_id'], array( 'connector_id' => $a['id'], 'referral_id' => $r_conf, 'referred_name' => 'Y', 'referred_role' => 'an organizer' ), array( 'window' => 'c' ) ), 'conflicted referral is not announced' );
$referred3 = lnkup_ct_user( 'lnkup_organizer' );
$r_sup     = $referral( $a, $referred3 );
do_action( 'lnkup_partner_referral_attributed', $r_sup, $a['id'], $referred3, 'city_connector' );
$sup = $last( $a['user_id'], 'p_referral_registered' );
$wpdb->update( $refs, array( 'attribution_status' => 'superseded' ), array( 'id' => $r_sup ) );
$due( (int) $sup['id'] );
LNKup_Comms_Engine::deliver( (int) $sup['id'] );
lnkup_t_same( 'suppressed', lnkup_ct_message( (int) $sup['id'] )['status'], 'referral superseded before send is suppressed' );

// --------------------------------------------------------------- Milestones.
echo "\n[milestones]\n";
do_action( 'lnkup_partner_referral_milestone', $r1, 'first_event_at' );
$ms = $last( $a['user_id'], 'p_referral_milestone' );
lnkup_t_same( 'p_referral_milestone:first_event', (string) ( $ms['template_key'] ?? '' ), 'first-event milestone uses its own template' );

// --------------------------------------------------------------- Commissions.
echo "\n[commissions]\n";
$pay1 = lnkup_t_payment( array( 'payment_status' => 'paid' ) );
$rw1  = $reward( array( 'referral_id' => $r1, 'user_id' => $a['user_id'], 'connector_id' => $a['id'], 'amount' => 12.50, 'payment_id' => $pay1 ) );
do_action( 'lnkup_partner_commission_recorded', $rw1, 'city_connector', $r1, $pay1 );
do_action( 'lnkup_partner_commission_recorded', $rw1, 'city_connector', $r1, $pay1 );
$cm = lnkup_ct_messages_for( $a['user_id'], 'p_commission_recorded' );
lnkup_t_same( 1, count( $cm ), 'duplicate ledger hook gives one commission message' );
lnkup_t_assert( strtotime( $cm[0]['scheduled_at'] . ' UTC' ) >= time() + 14 * MINUTE_IN_SECONDS, 'commission notice waits 15 minutes' );
do_action( 'lnkup_referral_rewards_reversed', $pay1, 1.0, 'refund' );
lnkup_t_same( 'cancelled', lnkup_ct_message( (int) $cm[0]['id'] )['status'], 'refund before send cancels the commission notice' );

$pay2 = lnkup_t_payment( array( 'payment_status' => 'paid' ) );
$rw2  = $reward( array( 'referral_id' => $r1, 'user_id' => $a['user_id'], 'connector_id' => $a['id'], 'amount' => 20.00, 'payment_id' => $pay2 ) );
do_action( 'lnkup_partner_commission_recorded', $rw2, 'city_connector', $r1, $pay2 );
$c2 = $last( $a['user_id'], 'p_commission_recorded' );
$wpdb->update( $rewards, array( 'reward_status' => 'reversed', 'reversed_amount' => 20.00 ), array( 'id' => $rw2 ) );
$due( (int) $c2['id'] );
LNKup_Comms_Engine::deliver( (int) $c2['id'] );
lnkup_t_same( 'suppressed', lnkup_ct_message( (int) $c2['id'] )['status'], 'reward reversed without hooks is suppressed at send time' );

$pay3 = lnkup_t_payment( array( 'payment_status' => 'paid' ) );
$rw3  = $reward( array( 'referral_id' => $r1, 'user_id' => $a['user_id'], 'connector_id' => $a['id'], 'amount' => 30.00, 'payment_id' => $pay3 ) );
do_action( 'lnkup_partner_commission_recorded', $rw3, 'city_connector', $r1, $pay3 );
$c3 = $last( $a['user_id'], 'p_commission_recorded' );
$wpdb->update( $refs, array( 'attribution_status' => 'conflict' ), array( 'id' => $r1 ) );
$due( (int) $c3['id'] );
LNKup_Comms_Engine::deliver( (int) $c3['id'] );
lnkup_t_same( 'suppressed', lnkup_ct_message( (int) $c3['id'] )['status'], 'commission on a referral that went into conflict is suppressed' );
$wpdb->update( $refs, array( 'attribution_status' => 'winning' ), array( 'id' => $r1 ) );

$pay4 = lnkup_t_payment( array( 'payment_status' => 'paid' ) );
$rw4  = $reward( array( 'referral_id' => $r1, 'user_id' => $a['user_id'], 'connector_id' => $a['id'], 'amount' => 15.00, 'payment_id' => $pay4 ) );
do_action( 'lnkup_partner_commission_recorded', $rw4, 'city_connector', $r1, $pay4 );
$c4 = $last( $a['user_id'], 'p_commission_recorded' );
$GLOBALS['lnkup_ct_mail'] = array();
$due( (int) $c4['id'] );
LNKup_Comms_Engine::deliver( (int) $c4['id'] );
lnkup_t_same( 'sent', lnkup_ct_message( (int) $c4['id'] )['status'], 'live commission notice sent' );
$mail = (string) ( $GLOBALS['lnkup_ct_mail'][0]['message'] ?? '' );
lnkup_t_assert( false !== strpos( $mail, '$15.00' ) && false !== strpos( $mail, 'LNKup reviews it' ), 'commission email states the amount and the hold/review' );
lnkup_t_assert( false !== strpos( (string) ( $partner_inbox( (int) $c4['id'] )['message'] ?? '' ), '$15.00' ), 'commission inbox row states the amount' );
foreach ( array( 'p_commission_recorded', 'p_commission_approved', 'p_payout_recorded' ) as $tk ) {
	$rendered = LNKup_Comms_Templates::render( $tk, array( 'commission_amount' => '$15.00', 'referred_name' => 'Org' ), 'partner' );
	lnkup_t_assert( ! is_wp_error( $rendered ) && false === strpos( $rendered['push_body'] . $rendered['push_title'], '$' ), $tk . ' push text never contains an amount' );
}

$wpdb->update( $rewards, array( 'reward_status' => 'approved' ), array( 'id' => $rw4 ) );
do_action( 'lnkup_partner_commission_approved', $rw4 );
$ap = $last( $a['user_id'], 'p_commission_approved' );
lnkup_t_assert( ! empty( $ap['id'] ), 'approval message scheduled' );

echo "\n[payout grouping]\n";
$paid_ids = array();
for ( $i = 0; $i < 3; $i++ ) {
	$pay        = lnkup_t_payment( array( 'payment_status' => 'paid' ) );
	$paid_ids[] = $reward( array( 'referral_id' => $r1, 'user_id' => $a['user_id'], 'connector_id' => $a['id'], 'amount' => 10, 'payment_id' => $pay, 'reward_status' => 'paid', 'paid_at' => current_time( 'mysql' ) ) );
}
foreach ( $paid_ids as $rid ) {
	do_action( 'lnkup_partner_commission_paid', $rid );
}
$po = lnkup_ct_messages_for( $a['user_id'], 'p_payout_recorded' );
lnkup_t_same( 1, count( $po ), 'three payouts recorded in one day give one message' );
$GLOBALS['lnkup_ct_mail'] = array();
$due( (int) $po[0]['id'] );
LNKup_Comms_Engine::deliver( (int) $po[0]['id'] );
$mail = (string) ( $GLOBALS['lnkup_ct_mail'][0]['message'] ?? '' );
lnkup_t_assert( false !== strpos( $mail, 'recorded a payout' ) && false === strpos( $mail, 'bank' ), 'payout copy says "recorded", not "sent to your bank"' );

echo "\n[suspension]\n";
$pay5 = lnkup_t_payment( array( 'payment_status' => 'paid' ) );
$rw5  = $reward( array( 'referral_id' => $r1, 'user_id' => $a['user_id'], 'connector_id' => $a['id'], 'amount' => 9, 'payment_id' => $pay5 ) );
do_action( 'lnkup_partner_commission_recorded', $rw5, 'city_connector', $r1, $pay5 );
$c5 = $last( $a['user_id'], 'p_commission_recorded' );
$wpdb->update( $connectors, array( 'status' => 'paused' ), array( 'id' => $a['id'] ) );
$due( (int) $c5['id'] );
LNKup_Comms_Engine::deliver( (int) $c5['id'] );
lnkup_t_same( 'suppressed', lnkup_ct_message( (int) $c5['id'] )['status'], 'suspended partner receives nothing' );
$wpdb->update( $connectors, array( 'status' => 'active' ), array( 'id' => $a['id'] ) );

// --------------------------------------------------------------- Leads.
echo "\n[leads]\n";
$lead = LNKup_Org_Leads::create( array( 'name' => "PW Comms Lead {$tag}", 'website' => "https://pw-comms-{$tag}.example", 'assigned_connector_id' => $a['id'], 'source' => 'city_connector' ), array( 'actor' => $admin ) );
$cleanup['leads'][] = is_wp_error( $lead ) ? 0 : (int) $lead;
LNKup_Org_Leads::advance_to( (int) $lead, 'registered', 'test' );
$lu = $last( $a['user_id'], 'p_lead_update' );
lnkup_t_same( 'p_lead_update:registered', (string) ( $lu['template_key'] ?? '' ), 'assigned partner hears the lead registered' );
lnkup_t_same( 0, count( lnkup_ct_messages_for( $b['user_id'], 'p_lead_update' ) ), 'other partners hear nothing about it' );
LNKup_Org_Leads::move( (int) $lead, '', 'not_interested', $admin );
lnkup_t_same( 'p_lead_update:closed', (string) ( $last( $a['user_id'], 'p_lead_update' )['template_key'] ?? '' ), 'closing the lead notifies the partner' );

// --------------------------------------------------------------- Referral credit (vendor referrer).
echo "\n[referral credit]\n";
$vendor_ref = lnkup_ct_user( 'lnkup_vendor' );
$invited    = lnkup_ct_user( 'lnkup_vendor' );
$wpdb->insert( $refs, array( 'referrer_user_id' => $vendor_ref, 'referred_user_id' => $invited, 'program' => 'organizer_recruit', 'target_role' => 'vendor', 'status' => 'active', 'attribution_status' => 'winning', 'created_at' => current_time( 'mysql' ) ) );
$inv_ref = (int) $wpdb->insert_id;
$cleanup['refs'][] = $inv_ref;
do_action( 'lnkup_organizer_recruit_attributed', $invited, $vendor_ref, $inv_ref, 'vendor' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $vendor_ref, 'r_invite_joined' ) ), 'vendor referrer hears their invite was used' );
$payc = lnkup_t_payment( array( 'payment_status' => 'paid' ) );
$rwc  = $reward( array( 'referral_id' => $inv_ref, 'user_id' => $vendor_ref, 'connector_id' => 0, 'amount' => 2.00, 'payment_id' => $payc, 'program' => 'organizer_recruit', 'event_type' => 'organizer_recruit_fee_share', 'reward_status' => 'paid', 'status' => 'available' ) );
do_action( 'lnkup_partner_commission_recorded', $rwc, 'organizer_recruit', $inv_ref, $payc );
$cr = $last( $vendor_ref, 'r_credit_earned' );
lnkup_t_same( 'vendor', (string) ( $cr['recipient_role'] ?? '' ), 'credit message addressed to the vendor role' );
$due( (int) $cr['id'] );
LNKup_Comms_Engine::deliver( (int) $cr['id'] );
$inbox = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Inbox_Tables::index_table() . ' WHERE user_id = %d AND client_id = %s', $vendor_ref, 'comms-' . (int) $cr['id'] ), ARRAY_A );
lnkup_t_assert( is_array( $inbox ) && 'referral_credit' === $inbox['notification_type'], 'referral credit lands in the vendor inbox' );
$payload = json_decode( (string) ( $inbox['payload'] ?? '' ), true );
lnkup_t_same( '/(vendor)/referrals', (string) ( $payload['deepLink'] ?? '' ), 'inbox deep link opens the vendor referrals screen' );

// --------------------------------------------------------------- Applicants and admins.
echo "\n[applicants and admins]\n";
$GLOBALS['lnkup_ct_mail'] = array();
$app = LNKup_Partner_Applications::submit( 'affiliate', array( 'name' => 'PW Applicant', 'email' => "pw-applicant-{$tag}@example.test", 'city' => 'Charlotte', 'disclosures' => 1 ), 'comms-test-' . $tag );
$app_id = (int) ( $app['id'] ?? 0 );
$cleanup['connectors'][] = $app_id;
$subjects = array_map( static fn( $m ) => (string) $m['subject'], $GLOBALS['lnkup_ct_mail'] );
lnkup_t_assert( in_array( 'We received your LNKup partner application', $subjects, true ), 'applicant receives a confirmation email' );
lnkup_t_same( 1, count( lnkup_ct_messages_for( $admin, 'a_partner_application' ) ), 'admins are alerted to the new application' );
$adm = $last( $admin, 'a_partner_application' );
$due( (int) $adm['id'] );
$GLOBALS['lnkup_ct_mail'] = array();
LNKup_Comms_Engine::deliver( (int) $adm['id'] );
lnkup_t_same( 'sent', lnkup_ct_message( (int) $adm['id'] )['status'], 'admin alert delivered' );
lnkup_t_assert( false !== strpos( LNKup_Comms_Context::web_url( 'partner_admin', array( 'admin_tab' => 'applications' ), 'admin' ), 'page=lnkup-partnerships&tab=applications' ), 'admin alert links to the partnerships Applications tab' );
$GLOBALS['lnkup_ct_mail'] = array();
LNKup_Partner_Applications::transition( $app_id, 'decline', $admin );
lnkup_t_assert( in_array( 'Your LNKup partner application', array_map( static fn( $m ) => (string) $m['subject'], $GLOBALS['lnkup_ct_mail'] ), true ), 'declined applicant is told' );

$suppressed_email = "pw-suppressed-{$tag}@example.test";
LNKup_Comms_Suppression::add( $suppressed_email, 'all', 'complaint' );
$GLOBALS['lnkup_ct_mail'] = array();
$app2 = LNKup_Partner_Applications::submit( 'affiliate', array( 'name' => 'PW Suppressed', 'email' => $suppressed_email, 'city' => 'Charlotte', 'disclosures' => 1 ), 'comms-test-s-' . $tag );
$cleanup['connectors'][] = (int) ( $app2['id'] ?? 0 );
lnkup_t_same( 0, count( $GLOBALS['lnkup_ct_mail'] ), 'suppressed applicant gets no email' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::audit_table() . " WHERE action = 'partner_applicant_email_skipped' AND entity_id = %d", (int) ( $app2['id'] ?? 0 ) ) ), 'skipped email is audited' );
$wpdb->delete( LNKup_Comms_Schema::table( 'suppressions' ), array( 'email_hash' => LNKup_Comms_Suppression::hash_email( $suppressed_email ) ) );

// --------------------------------------------------------------- Cleanup.
foreach ( array_filter( $cleanup['rewards'] ) as $id ) {
	$wpdb->delete( $rewards, array( 'id' => $id ) );
}
foreach ( array_filter( $cleanup['refs'] ) as $id ) {
	$wpdb->delete( $refs, array( 'id' => $id ) );
}
foreach ( array_filter( $cleanup['connectors'] ) as $id ) {
	$wpdb->delete( $codes, array( 'connector_id' => $id ) );
	$wpdb->delete( LNKup_Connector_Tables::events_table(), array( 'connector_id' => $id ) );
	$wpdb->delete( $connectors, array( 'id' => $id ) );
}
foreach ( array_filter( $cleanup['leads'] ) as $id ) {
	$wpdb->delete( LNKup_Org_Leads::table(), array( 'id' => $id ) );
	$wpdb->delete( LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACTIVITY ), array( 'lead_id' => $id ) );
}
update_option( 'lnkup_settings', $orig );
// phpcs:enable

lnkup_ct_finish();
