<?php
/**
 * Partnership OS go-live readiness (local wp-env only).
 *
 * - report shape: checks, five stages, shadow breakdown, integrity
 * - report( false ) writes nothing (options, transients, audit rows unchanged)
 * - shadow blocks counted by reason and program
 * - stage gates: fresh shadow blocked, evidence ready, stale review / integrity / off mode block,
 *   enforce done, comms gated on enforce (or the explicit shadow filter), mobile gate
 * - integrity violation in the ledger fails the check and blocks enforce
 * - cache flushed on settings save; REST admin-only; admin tab renders
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/partnerships-readiness-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Partnership OS go-live readiness\n";

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$audit        = LNKup_Financial_Tables::audit_table();
$rewards      = LNKup_Treasury_Tables::referral_rewards_table();
$tag          = strtolower( wp_generate_password( 6, false ) );
$orig_partner = get_option( LNKup_Partnerships::SETTINGS_OPTION, null );
$cleanup      = array( 'rewards' => array() );

$admin  = lnkup_t_user( 'administrator' );
$vendor = lnkup_t_user( 'lnkup_vendor' );
wp_set_current_user( $admin );
LNKup_Partnership_Readiness::flush();

// --------------------------------------------------------------- Shape.
echo "\n[shape]\n";
$r = LNKup_Partnership_Readiness::report( false );
lnkup_t_assert( isset( $r['checks'], $r['stages'], $r['shadow'], $r['integrity'], $r['mode'], $r['generated_at'] ), 'report has checks, stages, shadow, integrity, mode and time' );
lnkup_t_same( array( 'deployed', 'enforce', 'programs', 'communications', 'mobile' ), wp_list_pluck( $r['stages'], 'key' ), 'five stages in runbook order' );
$levels = array_unique( wp_list_pluck( $r['checks'], 'level' ) );
lnkup_t_assert( array() === array_diff( $levels, array( 'ok', 'warn', 'fail', 'info' ) ), 'every check uses ok/warn/fail/info' );
lnkup_t_assert( in_array( 'payouts', wp_list_pluck( $r['checks'], 'key' ), true ), 'payouts check states manual-only payouts' );
lnkup_t_same( 'done', $r['stages'][0]['status'], 'deployed stage is done on a migrated install' );

// --------------------------------------------------------------- No writes.
echo "\n[no writes]\n";
$snapshot = static function () use ( $wpdb, $audit ): string {
	return implode(
		'|',
		array(
			(string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ),
			(string) $wpdb->get_var( "SELECT MAX(option_id) FROM {$wpdb->options}" ),
			(string) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit}" ),
			(string) $wpdb->get_var( "SELECT MAX(id) FROM {$audit}" ),
		)
	);
};
LNKup_Partnership_Readiness::flush();
$before = $snapshot();
LNKup_Partnership_Readiness::report( false );
LNKup_Partnership_Readiness::report( false );
lnkup_t_same( $before, $snapshot(), 'report( false ) leaves options and audit rows unchanged' );
lnkup_t_assert( false === get_transient( LNKup_Partnership_Readiness::CACHE_KEY ), 'report( false ) never writes the cache' );

LNKup_Partnership_Readiness::report();
lnkup_t_assert( is_array( get_transient( LNKup_Partnership_Readiness::CACHE_KEY ) ), 'report() caches for the admin tab' );
LNKup_Partnerships::update_settings( LNKup_Partnerships::settings() );
lnkup_t_assert( false === get_transient( LNKup_Partnership_Readiness::CACHE_KEY ), 'saving partnership settings flushes the cache' );

// --------------------------------------------------------------- Shadow breakdown.
echo "\n[shadow evidence]\n";
$set_mode = static function ( string $mode ): void {
	update_option( LNKup_Partnerships::SETTINGS_OPTION, array_merge( LNKup_Partnerships::settings(), array( 'partnerships_arbiter_mode' => $mode ) ), false );
};
$set_mode( 'shadow' );
$base = LNKup_Partnership_Readiness::shadow_breakdown();
foreach ( array( 'conflict', 'conflict', 'superseded' ) as $reason ) {
	LNKup_Financial_Audit::log( 'partner_arbiter_shadow_block', 'referral', 1, array( 'reason' => 'pw' . $tag . $reason, 'program' => 'pw' . $tag ) );
}
LNKup_Financial_Audit::log( 'partner_arbiter_blocked', 'referral', 1, array( 'reason' => 'pw' . $tag . 'conflict', 'program' => 'pw' . $tag ) );
$s = LNKup_Partnership_Readiness::shadow_breakdown();
lnkup_t_same( $base['total_7d'] + 3, $s['total_7d'], 'shadow blocks counted for the last 7 days' );
lnkup_t_same( $base['blocked_30d'] + 1, $s['blocked_30d'], 'enforced blocks counted separately' );
lnkup_t_same( 2, (int) ( $s['by_reason'][ 'pw' . $tag . 'conflict' ] ?? 0 ), 'breakdown by reason covers only the current mode (shadow)' );
lnkup_t_same( 1, (int) ( $s['by_reason'][ 'pw' . $tag . 'superseded' ] ?? 0 ), 'each reason counted' );
lnkup_t_same( 3, (int) ( $s['by_program'][ 'pw' . $tag ] ?? 0 ), 'breakdown by program' );
lnkup_t_same( $s['total_30d'], array_sum( $s['by_reason'] ), 'reason breakdown adds up to the 30-day shadow total' );
$set_mode( 'enforce' );
$e = LNKup_Partnership_Readiness::shadow_breakdown();
lnkup_t_same( 1, (int) ( $e['by_reason'][ 'pw' . $tag . 'conflict' ] ?? 0 ), 'in enforce mode the breakdown shows real blocks' );
$set_mode( 'shadow' );

// --------------------------------------------------------------- Stage gates (synthetic inputs).
echo "\n[gates]\n";
$t     = array( 'shadow_days' => 14, 'stale_review_days' => 7 );
$clean = array( 'approved_early' => 0, 'negative_net' => 0, 'duplicate_keys' => 0, 'paid_without_ref' => 0 );
$ok    = array( array( 'key' => 'schema', 'stage' => 'deployed', 'level' => 'ok', 'label' => 'Schema', 'detail' => '', 'link' => '' ) );
$ctx   = static function ( array $over = array() ): array {
	return array_merge(
		array(
			'mode'          => 'shadow',
			'mode_days'     => 20,
			'backfill_done' => true,
			'stale_review'  => false,
			'comms'         => array( 'active' => true, 'flow_partners' => false, 'push_ready' => true, 'version' => '1.2.0' ),
			'mobile_gate'   => true,
			'missing_pages' => array(),
		),
		$over
	);
};
$stage = static function ( array $stages, string $key ): array {
	foreach ( $stages as $s ) {
		if ( $s['key'] === $key ) {
			return $s;
		}
	}
	return array();
};

$fresh = LNKup_Partnership_Readiness::stages( $ok, $ctx( array( 'mode_days' => 0 ) ), $clean, $t );
lnkup_t_same( 'blocked', $stage( $fresh, 'enforce' )['status'], 'a fresh shadow install is blocked for enforce' );
lnkup_t_assert( false !== strpos( implode( ' ', $stage( $fresh, 'enforce' )['blockers'] ), '0 of 14 days' ), 'blocker says how many shadow days are left' );
$unknown = LNKup_Partnership_Readiness::stages( $ok, $ctx( array( 'mode_days' => -1 ) ), $clean, $t );
lnkup_t_assert( false !== strpos( implode( ' ', $stage( $unknown, 'enforce' )['blockers'] ), 'unknown' ), 'unknown shadow start date blocks enforce' );

$ready = LNKup_Partnership_Readiness::stages( $ok, $ctx(), $clean, $t );
lnkup_t_same( 'ready', $stage( $ready, 'enforce' )['status'], '20 days of shadow, backfill done, no stale reviews, clean ledger: ready for enforce' );
lnkup_t_same( 'blocked', $stage( $ready, 'programs' )['status'], 'programs wait for enforce' );
lnkup_t_same( 'blocked', $stage( $ready, 'communications' )['status'], 'partner notifications wait for enforce' );

lnkup_t_same( 'blocked', $stage( LNKup_Partnership_Readiness::stages( $ok, $ctx( array( 'stale_review' => true ) ), $clean, $t ), 'enforce' )['status'], 'a stale conflict review blocks enforce' );
lnkup_t_same( 'blocked', $stage( LNKup_Partnership_Readiness::stages( $ok, $ctx( array( 'backfill_done' => false ) ), $clean, $t ), 'enforce' )['status'], 'an unfinished backfill blocks enforce' );
lnkup_t_same( 'blocked', $stage( LNKup_Partnership_Readiness::stages( $ok, $ctx( array( 'mode' => 'off' ) ), $clean, $t ), 'enforce' )['status'], 'arbiter off must go through shadow first' );
foreach ( array( 'approved_early', 'negative_net', 'duplicate_keys' ) as $key ) {
	lnkup_t_same( 'blocked', $stage( LNKup_Partnership_Readiness::stages( $ok, $ctx(), array_merge( $clean, array( $key => 1 ) ), $t ), 'enforce' )['status'], $key . ' blocks enforce' );
}
lnkup_t_same( 'ready', $stage( LNKup_Partnership_Readiness::stages( $ok, $ctx(), array_merge( $clean, array( 'paid_without_ref' => 3 ) ), $t ), 'enforce' )['status'], 'legacy payouts without a reference only warn' );

$enforced = LNKup_Partnership_Readiness::stages( $ok, $ctx( array( 'mode' => 'enforce', 'mode_days' => 1 ) ), $clean, $t );
lnkup_t_same( 'done', $stage( $enforced, 'enforce' )['status'], 'enforce mode marks the stage done' );
lnkup_t_same( 'ready', $stage( $enforced, 'programs' )['status'], 'programs ready once enforcing' );
lnkup_t_same( 'ready', $stage( $enforced, 'communications' )['status'], 'notifications ready once enforcing' );
$comms_on = LNKup_Partnership_Readiness::stages( $ok, $ctx( array( 'mode' => 'enforce', 'comms' => array( 'active' => true, 'flow_partners' => true ) ) ), $clean, $t );
lnkup_t_same( 'done', $stage( $comms_on, 'communications' )['status'], 'flow_partners on marks notifications done' );
$missing_pages = LNKup_Partnership_Readiness::stages( $ok, $ctx( array( 'mode' => 'enforce', 'missing_pages' => array( 'affiliate-program' ) ) ), $clean, $t );
lnkup_t_same( 'blocked', $stage( $missing_pages, 'programs' )['status'], 'unpublished partner pages block opening programs' );

add_filter( 'lnkup_partnership_readiness_allow_comms_in_shadow', '__return_true' );
lnkup_t_same( 'ready', $stage( LNKup_Partnership_Readiness::stages( $ok, $ctx(), $clean, $t ), 'communications' )['status'], 'explicitly accepting shadow mode unblocks notifications' );
remove_filter( 'lnkup_partnership_readiness_allow_comms_in_shadow', '__return_true' );
$failing = array_merge( $ok, array( array( 'key' => 'comms_plugin', 'stage' => 'communications', 'level' => 'fail', 'label' => 'Communications plugin', 'detail' => '', 'link' => '' ) ) );
lnkup_t_same( 'blocked', $stage( LNKup_Partnership_Readiness::stages( $failing, $ctx( array( 'mode' => 'enforce' ) ), $clean, $t ), 'communications' )['status'], 'a failing check blocks notifications' );
lnkup_t_same( 'blocked', $stage( LNKup_Partnership_Readiness::stages( $ok, $ctx( array( 'mobile_gate' => false ) ), $clean, $t ), 'mobile' )['status'], 'mobile waits for the partner sign-in gate' );
$not_deployed = LNKup_Partnership_Readiness::stages( array( array( 'key' => 'schema', 'stage' => 'deployed', 'level' => 'fail', 'label' => 'Partnership schema', 'detail' => '', 'link' => '' ) ), array(), $clean, $t );
lnkup_t_same( array( 'blocked', 'blocked', 'blocked', 'blocked', 'blocked' ), wp_list_pluck( $not_deployed, 'status' ), 'every stage blocked before migration' );

add_filter( 'lnkup_partnership_readiness_thresholds', static fn() => array( 'shadow_days' => 30, 'stale_review_days' => 2 ) );
lnkup_t_same( array( 'shadow_days' => 30, 'stale_review_days' => 2 ), LNKup_Partnership_Readiness::thresholds(), 'thresholds are filterable' );
remove_all_filters( 'lnkup_partnership_readiness_thresholds' );

// --------------------------------------------------------------- Real data: mode and integrity.
echo "\n[live data]\n";
LNKup_Partnerships::update_settings( array_merge( LNKup_Partnerships::settings(), array( 'partnerships_arbiter_mode' => 'enforce' ) ) );
$live = LNKup_Partnership_Readiness::report( false );
lnkup_t_same( 'enforce', $live['mode'], 'report reflects the saved arbiter mode' );
$mode_check = array_values( wp_list_filter( $live['checks'], array( 'key' => 'arbiter_mode' ) ) )[0] ?? array();
lnkup_t_assert( false !== strpos( (string) ( $mode_check['detail'] ?? '' ), 'enforce for 0 days' ), 'mode start date comes from the settings audit row' );
LNKup_Partnerships::update_settings( array_merge( LNKup_Partnerships::settings(), array( 'partnerships_arbiter_mode' => 'shadow' ) ) );

$cc = lnkup_t_user( 'lnkup_connector' );
$wpdb->insert(
	$rewards,
	array(
		'user_id'         => $cc,
		'connector_id'    => 999000 + wp_rand( 1, 999 ),
		'event_type'      => 'connector_booking_fee',
		'amount'          => 10,
		'status'          => 'approved',
		'reward_status'   => 'approved',
		'program'         => 'city_connector',
		'available_at'    => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
		'approved_at'     => current_time( 'mysql' ),
		'created_at'      => current_time( 'mysql' ),
		'idempotency_key' => 'pw-readiness-' . $tag,
	)
);
$cleanup['rewards'][] = (int) $wpdb->insert_id;
$bad   = LNKup_Partnership_Readiness::report( false );
$check = array_values( wp_list_filter( $bad['checks'], array( 'key' => 'integrity_approved_early' ) ) )[0] ?? array();
lnkup_t_same( 'fail', (string) ( $check['level'] ?? '' ), 'a commission approved before its hold ended fails the integrity check' );
lnkup_t_assert( $bad['integrity']['approved_early'] >= 1, 'and is counted' );
lnkup_t_assert( false !== strpos( implode( ' ', $stage( $bad['stages'], 'enforce' )['blockers'] ), 'before their hold ended' ), 'and blocks enforce' );

// --------------------------------------------------------------- REST and admin.
echo "\n[rest and admin]\n";
wp_set_current_user( $vendor );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/partners/readiness' ) );
lnkup_t_assert( in_array( $res->get_status(), array( 401, 403 ), true ), 'vendors cannot read readiness' );
wp_set_current_user( $cc );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/partners/readiness' ) );
lnkup_t_assert( in_array( $res->get_status(), array( 401, 403 ), true ), 'partners cannot read readiness' );
wp_set_current_user( $admin );
$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/partners/readiness' ) );
lnkup_t_same( 200, $res->get_status(), 'admins can' );
lnkup_t_same( 5, count( (array) ( $res->get_data()['stages'] ?? array() ) ), 'REST returns the stage checklist' );

$render = new ReflectionMethod( 'LNKup_Partnerships_Admin', 'render_readiness' );
$render->setAccessible( true );
ob_start();
$render->invoke( null );
$html = (string) ob_get_clean();
lnkup_t_assert( false !== strpos( $html, 'lnkup-readiness-stages' ) && false !== strpos( $html, 'lnkup-readiness-checks' ), 'admin tab renders stages and checks' );
lnkup_t_assert( false !== strpos( $html, 'name="lnkup_partners_action" value="readiness_refresh"' ) && false !== strpos( $html, '_wpnonce' ), 'refresh is a nonce-protected POST' );
lnkup_t_assert( false === strpos( $html, 'partnerships_arbiter_mode' ), 'the tab has no mode switch (read-only)' );
lnkup_t_assert( in_array( 'readiness', LNKup_Partnerships_Admin::TABS, true ), 'readiness is a registered tab' );

// --------------------------------------------------------------- Cleanup.
foreach ( $cleanup['rewards'] as $id ) {
	$wpdb->delete( $rewards, array( 'id' => $id ) );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$audit} WHERE action IN ('partner_arbiter_shadow_block','partner_arbiter_blocked') AND metadata LIKE %s", '%pw' . $wpdb->esc_like( $tag ) . '%' ) );
if ( null === $orig_partner ) {
	delete_option( LNKup_Partnerships::SETTINGS_OPTION );
} else {
	update_option( LNKup_Partnerships::SETTINGS_OPTION, $orig_partner, false );
}
LNKup_Partnership_Readiness::flush();
// phpcs:enable

lnkup_t_finish();
