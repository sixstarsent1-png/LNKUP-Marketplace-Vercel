<?php
/**
 * Organizer acquisition analytics + spend ledger (wp-env only).
 *
 * Fixtures live in a 2019 window with per-run sources so seeded data never mixes in.
 * Covers: cohorts and time-to medians, voided milestones excluded, conversion by dimension,
 * cost per acquisition with no costs / spend only / commissions only / both, reversed and
 * pending commissions excluded, house vs independent split, audited void, at-risk organizers,
 * CSV export formula guard, and REST access control.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/org-growth-analytics-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Organizer Growth analytics\n";

$run      = strtolower( wp_generate_password( 6, false ) );
$saved    = get_option( LNKup_Org_Growth::SETTINGS_KEY, null );
$admin    = lnkup_t_user( 'administrator' );
$lead_ids = array();
$spend    = array();
$ref_ids  = array();
$rew_ids  = array();
$posts    = array();
wp_set_current_user( $admin );
LNKup_Org_Growth::update_settings( array( 'organizer_growth_enabled' => true ) );

$t        = static fn( string $s ): string => LNKup_Org_Growth_Tables::table( $s );
$ms       = $t( LNKup_Org_Growth_Tables::MILESTONES );
$accounts = $t( LNKup_Org_Growth_Tables::ACCOUNTS );
$rewards  = LNKup_Treasury_Tables::referral_rewards_table();
$refs     = LNKup_Treasury_Tables::referrals_table();
$window   = array( 'from' => '2019-01-01', 'to' => '2019-03-31' );
$src      = static fn( string $k ): string => 'ogat' . $run . $k;

/**
 * Lead created on 2019-01-02 with milestones (`status` defaults to active) and a linked organizer.
 *
 * @param array<string, string|array{0:string,1:string}> $milestones milestone => date or [date, status].
 */
$lead = static function ( string $name, string $source, array $milestones, bool $house = false ) use ( $wpdb, $ms, $accounts, $admin, &$lead_ids, $run ): array {
	$id = LNKup_Org_Leads::create( array( 'name' => $name, 'email' => "hello@" . sanitize_key( $name ) . "-{$run}.test", 'city' => 'Charlotte', 'state' => 'NC', 'source' => $source ), array( 'actor' => $admin ) );
	if ( ! is_int( $id ) || $id <= 0 ) {
		echo '  FAIL fixture lead not created: ' . ( is_wp_error( $id ) ? $id->get_error_message() : 'unknown' ) . "\n";
		exit( 1 );
	}
	$lead_ids[] = $id;
	$wpdb->update( LNKup_Org_Leads::table(), array( 'created_at' => '2019-01-02 09:00:00', 'is_house_affiliated' => $house ? 1 : 0, 'source' => $source ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
	$user = lnkup_t_user( 'lnkup_organizer' );
	$wpdb->insert( $accounts, array( 'lead_id' => $id, 'user_id' => $user, 'link_method' => 'test', 'status' => 'linked', 'linked_by' => $admin, 'created_at' => '2019-01-02 09:00:00' ) ); // phpcs:ignore WordPress.DB
	foreach ( $milestones as $key => $value ) {
		list( $date, $status ) = is_array( $value ) ? $value : array( $value, 'active' );
		$wpdb->insert( $ms, array( 'lead_id' => $id, 'milestone' => $key, 'user_id' => $user, 'status' => $status, 'achieved_at' => $date . ' 12:00:00' ) ); // phpcs:ignore WordPress.DB
	}
	return array( 'id' => $id, 'user' => $user );
};

/**
 * Organizer-referral reward for a referred organizer.
 */
$reward = static function ( int $referred, float $amount, string $status, float $reversed = 0.0 ) use ( $wpdb, $refs, $rewards, $admin, &$ref_ids, &$rew_ids ): void {
	$wpdb->insert( $refs, array( 'referrer_user_id' => $admin, 'referred_user_id' => $referred, 'program' => 'organizer_recruit', 'status' => 'qualified', 'created_at' => '2019-01-20 10:00:00' ) ); // phpcs:ignore WordPress.DB
	$ref_ids[] = (int) $wpdb->insert_id;
	$wpdb->insert( $rewards, array( 'user_id' => $admin, 'referral_id' => (int) $wpdb->insert_id, 'event_type' => 'organizer_recruit_fee', 'amount' => $amount, 'reversed_amount' => $reversed, 'status' => 'available', 'reward_status' => $status, 'program' => 'organizer_recruit', 'created_at' => '2019-01-20 10:00:00' ) ); // phpcs:ignore WordPress.DB
	$rew_ids[] = (int) $wpdb->insert_id;
};

// A: published d10, paid d20, activated d30. B: published d30. C (house): published d2.
// D: registered only. E: paid booking refunded (voided). G: registered before the window,
// activated, with a live event (publishing it records a real milestone today).
$a = $lead( '=OGAT ' . $run . ' Alpha Market', $src( 'a' ), array( 'registered' => '2019-01-05', 'first_event_published' => '2019-01-15', 'first_paid_booking' => '2019-01-25', 'activated' => '2019-02-04' ) );
$b = $lead( 'OGAT ' . $run . ' Bravo Fest', $src( 'b' ), array( 'registered' => '2019-01-10', 'first_event_published' => '2019-02-09' ) );
$c = $lead( 'OGAT ' . $run . ' Six Stars Charlie', $src( 'c' ), array( 'registered' => '2019-02-01', 'first_event_published' => '2019-02-03' ), true );
$d = $lead( 'OGAT ' . $run . ' Delta Bazaar', $src( 'd' ), array( 'registered' => '2019-01-20' ) );
$e = $lead( 'OGAT ' . $run . ' Echo Night', $src( 'b' ), array( 'registered' => '2019-01-12', 'first_paid_booking' => array( '2019-01-30', 'voided' ) ) );
$g = $lead( 'OGAT ' . $run . ' Golf Fair', $src( 'g' ), array( 'registered' => '2018-12-20', 'activated' => '2019-01-28' ) );
$live = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => 'OGAT live event' ) );
update_post_meta( $live, '_lnkup_organizer_user_id', $g['user'] );
update_post_meta( $live, '_lnkup_event_status', 'live' );
update_post_meta( $live, '_lnkup_event_date', gmdate( 'Y-m-d', time() + 20 * DAY_IN_SECONDS ) );
$posts[] = (int) $live;
LNKup_Org_Growth_Metrics::flush_cache();

// --------------------------------------------------------------- Cohorts + time to milestones.
echo "\n[cohorts]\n";
$cohorts = array();
foreach ( LNKup_Org_Growth_Analytics::cohorts( $window ) as $row ) {
	$cohorts[ $row['cohort'] ] = $row;
}
lnkup_t_same( 4, $cohorts['2019-01']['registered'] ?? null, 'January cohort = 4 registered organizations' );
lnkup_t_same( 1, $cohorts['2019-02']['registered'] ?? null, 'February cohort = 1' );
lnkup_t_same( 2, $cohorts['2019-01']['milestones']['first_event_published']['d30']['count'] ?? null, 'two January organizations published within 30 days' );
lnkup_t_same( 1, $cohorts['2019-01']['milestones']['first_paid_booking']['overall']['count'] ?? null, 'refunded (voided) paid booking is not counted' );
lnkup_t_same( 25.0, $cohorts['2019-01']['milestones']['first_paid_booking']['overall']['percent'] ?? null, 'percent is out of the cohort size' );
lnkup_t_assert( ! isset( $cohorts['2018-12'] ), 'registrations before the window are excluded' );

$tt = LNKup_Org_Growth_Analytics::time_to_milestones( $window );
lnkup_t_same( 3, $tt['first_event_published']['sample'], 'three organizations published' );
lnkup_t_same( 10.0, $tt['first_event_published']['median'], 'median days to publish = 10' );
lnkup_t_same( 20.0, $tt['first_event_published']['p75'], 'p75 days to publish = 20' );
lnkup_t_same( 20.0, $tt['first_paid_booking']['median'], 'median days to first paid booking = 20' );
$ind = LNKup_Org_Growth_Analytics::time_to_milestones( array_merge( $window, array( 'affiliation' => 'independent' ) ) );
lnkup_t_same( 2, $ind['first_event_published']['sample'], 'independent view excludes the house organizer' );
$house = LNKup_Org_Growth_Analytics::time_to_milestones( array_merge( $window, array( 'affiliation' => 'house' ) ) );
lnkup_t_same( 1, $house['first_event_published']['sample'], 'house view shows only the house organizer' );
lnkup_t_same( array(), LNKup_Org_Growth_Analytics::cohorts( array( 'from' => '2018-01-01', 'to' => '2018-01-31' ) ), 'empty window → no cohorts (no invented rows)' );

// --------------------------------------------------------------- Conversion by dimension.
echo "\n[conversion]\n";
$conv = array();
foreach ( LNKup_Org_Growth_Analytics::by_dimension( 'source', $window )['rows'] as $row ) {
	$conv[ $row['value'] ] = $row;
}
lnkup_t_same( 2, $conv[ $src( 'b' ) ]['leads'] ?? null, 'source b has two leads' );
lnkup_t_same( 0, $conv[ $src( 'b' ) ]['paid'] ?? null, 'voided paid milestone not counted in conversion' );
lnkup_t_same( 100.0, $conv[ $src( 'a' ) ]['registered_to_activated'] ?? null, 'source a: registered → activated 100%' );
$aff = array();
foreach ( LNKup_Org_Growth_Analytics::by_dimension( 'affiliation', $window )['rows'] as $row ) {
	$aff[ $row['value'] ] = $row;
}
lnkup_t_assert( isset( $aff['house'] ) && 1 <= $aff['house']['leads'], 'affiliation dimension separates house organizers' );
lnkup_t_same( 1, LNKup_Org_Growth_Analytics::productivity( $window )['organizations'], 'productivity covers organizations with an active paid booking only' );

// --------------------------------------------------------------- Cost per acquisition.
echo "\n[cost]\n";
$cac_rows = static function ( array $args ) use ( $window ): array {
	$out = array();
	foreach ( LNKup_Org_Growth_Analytics::cac( array_merge( $window, $args ) )['channels'] as $row ) {
		$out[ $row['channel'] ] = $row;
	}
	return $out;
};
$rows = $cac_rows( array() );
lnkup_t_same( 'unavailable', $rows[ $src( 'd' ) ]['status'] ?? null, 'no spend and no commissions → unavailable' );
lnkup_t_assert( isset( $rows[ $src( 'd' ) ] ) && null === $rows[ $src( 'd' ) ]['cost_per_registered_cents'], 'unavailable channel shows no cost per acquisition' );

$bad = LNKup_Org_Growth_Spend::add( array( 'spent_on' => '2019-01-15', 'channel' => $src( 'a' ), 'amount' => '0' ), $admin );
lnkup_t_assert( is_wp_error( $bad ), 'zero spend refused' );
lnkup_t_assert( is_wp_error( LNKup_Org_Growth_Spend::add( array( 'spent_on' => '', 'channel' => $src( 'a' ), 'amount' => '10' ), $admin ) ), 'spend without a date refused' );
$s1 = LNKup_Org_Growth_Spend::add( array( 'spent_on' => '2019-01-15', 'channel' => $src( 'a' ), 'amount' => '$300.00', 'campaign' => 'Flyers' ), $admin );
lnkup_t_assert( is_array( $s1 ) && 30000 === $s1['amount_cents'], 'spend entered in dollars stored in cents' );
$spend[] = (int) ( $s1['id'] ?? 0 );
$rows = $cac_rows( array() );
lnkup_t_same( 'complete', $rows[ $src( 'a' ) ]['status'] ?? null, 'spend only → complete' );
lnkup_t_same( 30000, $rows[ $src( 'a' ) ]['cost_per_registered_cents'] ?? null, '$300 / 1 registered' );
lnkup_t_same( 30000, $rows[ $src( 'a' ) ]['cost_per_activated_cents'] ?? null, '$300 / 1 activated' );

$reward( $b['user'], 50.00, 'approved', 10.00 );
$reward( $e['user'], 100.00, 'reversed', 100.00 );
$reward( $e['user'], 25.00, 'pending' );
LNKup_Org_Growth_Metrics::flush_cache();
$rows = $cac_rows( array() );
lnkup_t_same( 'commissions_only', $rows[ $src( 'b' ) ]['status'] ?? null, 'commissions only → labelled as such' );
lnkup_t_same( 4000, $rows[ $src( 'b' ) ]['commission_cents'] ?? null, 'commission net of partial reversal; reversed and pending rewards excluded' );
lnkup_t_same( 2000, $rows[ $src( 'b' ) ]['cost_per_registered_cents'] ?? null, '$40 / 2 registered' );
lnkup_t_assert( isset( $rows[ $src( 'b' ) ] ) && null === $rows[ $src( 'b' ) ]['cost_per_activated_cents'], 'no activated organizations → no cost per activated' );

$reward( $a['user'], 20.00, 'paid' );
LNKup_Org_Growth_Metrics::flush_cache();
$rows = $cac_rows( array() );
lnkup_t_same( 32000, $rows[ $src( 'a' ) ]['cost_cents'] ?? null, 'spend + commissions combined' );
lnkup_t_same( 'complete', $rows[ $src( 'a' ) ]['status'] ?? null, 'both → complete' );

$house_rows = $cac_rows( array( 'affiliation' => 'house' ) );
lnkup_t_same( 0, $house_rows[ $src( 'b' ) ]['commission_cents'] ?? 0, 'house view excludes commissions for independent organizers' );
lnkup_t_same( 1, $house_rows[ $src( 'c' ) ]['registered'] ?? null, 'house view counts the house organizer' );
lnkup_t_same( 0, $house_rows[ $src( 'a' ) ]['registered'] ?? 0, 'house view does not count independent registrations' );

lnkup_t_assert( is_wp_error( LNKup_Org_Growth_Spend::void( (int) $s1['id'], '', $admin ) ), 'void requires a reason' );
$voided = LNKup_Org_Growth_Spend::void( (int) $s1['id'], 'Duplicate invoice', $admin );
lnkup_t_assert( is_array( $voided ) && $voided['voided'], 'spend voided' );
lnkup_t_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::audit_table() . " WHERE action = 'org_growth_spend_voided' AND entity_id = %d", (int) $s1['id'] ) ), 'void is audited' ); // phpcs:ignore WordPress.DB
lnkup_t_assert( null !== LNKup_Org_Growth_Spend::get( (int) $s1['id'] ), 'voided entry kept (never deleted)' );
$rows = $cac_rows( array() );
lnkup_t_same( 'commissions_only', $rows[ $src( 'a' ) ]['status'] ?? null, 'voided spend no longer counts' );

$file = wp_tempnam( 'ogat-spend.csv' );
file_put_contents( $file, "Date,Channel,Amount,Campaign\n2019-02-01," . $src( 'd' ) . ",45.50,Radio\n2019-02-02,,10,Bad row\n" ); // phpcs:ignore
$imported = LNKup_Org_Growth_Spend::import_file( $file, $admin );
@unlink( $file ); // phpcs:ignore
lnkup_t_same( 1, $imported['added'] ?? null, 'CSV import adds valid rows' );
lnkup_t_same( 1, count( $imported['errors'] ?? array() ), 'CSV import reports the row without a channel' );
foreach ( LNKup_Org_Growth_Spend::entries( array( 'channel' => $src( 'd' ), 'include_voided' => true ) ) as $row ) {
	$spend[] = (int) $row['id'];
}
lnkup_t_same( 4550, $cac_rows( array() )[ $src( 'd' ) ]['cost_per_registered_cents'] ?? null, 'imported spend gives source d a cost' );

// --------------------------------------------------------------- At risk.
echo "\n[at risk]\n";
$risk = wp_list_pluck( LNKup_Org_Growth_Analytics::at_risk( array() ), 'lead_id' );
lnkup_t_assert( in_array( $a['id'], $risk, true ), 'activated organizer with nothing upcoming is at risk' );
lnkup_t_assert( ! in_array( $g['id'], $risk, true ), 'activated organizer with a live event is not at risk' );
lnkup_t_assert( ! in_array( $b['id'], $risk, true ), 'never-activated organizer is not in the at-risk list' );

// --------------------------------------------------------------- Export.
echo "\n[export]\n";
$csv = LNKup_Org_Growth_Analytics::export_csv( 'at_risk', array() );
lnkup_t_assert( false !== strpos( $csv, "'=OGAT " . $run ), 'CSV export neutralises formula-looking names' );
$csv = LNKup_Org_Growth_Analytics::export_csv( 'cac', $window );
lnkup_t_assert( false !== strpos( $csv, $src( 'b' ) . ',0.00,40.00,2,0,20.00,,commissions_only' ), 'cost CSV row matches the table' );

// --------------------------------------------------------------- REST access.
echo "\n[access]\n";
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/growth/analytics' ) );
lnkup_t_same( 200, $res->get_status(), 'admin reads analytics' );
$req = new WP_REST_Request( 'POST', '/lnkup/v1/growth/spend' );
$req->set_body_params( array( 'spent_on' => '2019-03-01', 'channel' => $src( 'g' ), 'amount' => '12' ) );
$res = rest_do_request( $req );
lnkup_t_assert( in_array( $res->get_status(), array( 200, 201 ), true ), 'admin adds spend over REST' );
$spend[] = (int) ( $res->get_data()['id'] ?? $res->get_data()['item']['id'] ?? 0 );
foreach ( array( 'organizer' => lnkup_t_user( 'lnkup_organizer' ), 'connector' => lnkup_t_user( 'lnkup_connector' ), 'vendor' => lnkup_t_user( 'lnkup_vendor' ), 'anonymous' => 0 ) as $who => $uid ) {
	wp_set_current_user( $uid );
	foreach ( array( array( 'GET', '/lnkup/v1/growth/analytics' ), array( 'GET', '/lnkup/v1/growth/spend' ), array( 'POST', '/lnkup/v1/growth/spend' ), array( 'GET', '/lnkup/v1/growth/analytics/export' ) ) as $route ) {
		$res = rest_do_request( new WP_REST_Request( $route[0], $route[1] ) );
		lnkup_t_assert( in_array( $res->get_status(), array( 401, 403 ), true ), "{$who} blocked from {$route[0]} {$route[1]} ({$res->get_status()})" );
	}
}
wp_set_current_user( $admin );

// --------------------------------------------------------------- Cleanup.
$in = implode( ',', array_map( 'intval', $lead_ids ) );
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( array( LNKup_Org_Growth_Tables::ACTIVITY, LNKup_Org_Growth_Tables::TASKS, LNKup_Org_Growth_Tables::TAGS, LNKup_Org_Growth_Tables::ACCOUNTS, LNKup_Org_Growth_Tables::MILESTONES ) as $suffix ) {
	$wpdb->query( 'DELETE FROM ' . $t( $suffix ) . " WHERE lead_id IN ({$in})" );
}
$wpdb->query( 'DELETE FROM ' . LNKup_Org_Leads::table() . " WHERE id IN ({$in})" );
foreach ( array_filter( $spend ) as $sid ) {
	$wpdb->delete( LNKup_Org_Growth_Spend::table(), array( 'id' => $sid ) );
}
foreach ( $rew_ids as $rid ) {
	$wpdb->delete( $rewards, array( 'id' => $rid ) );
}
foreach ( $ref_ids as $rid ) {
	$wpdb->delete( $refs, array( 'id' => $rid ) );
}
// phpcs:enable
foreach ( $posts as $pid ) {
	wp_delete_post( $pid, true );
}
if ( null === $saved ) {
	delete_option( LNKup_Org_Growth::SETTINGS_KEY );
} else {
	update_option( LNKup_Org_Growth::SETTINGS_KEY, $saved, false );
}
LNKup_Org_Growth_Metrics::flush_cache();

lnkup_t_finish();
