<?php
/**
 * Phase 9: Marketing Command Center. Every tab renders for an administrator and for the
 * lnkup_marketing role; revenue attribution and flag changes stay with finance/admins; KPIs count
 * stored records only; CSV cells are formula-safe; Search Console says "Not connected" without
 * credentials. All fixtures are removed.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/marketing-command-center-test.php
 */

require_once __DIR__ . '/_marketing_helpers.php';
global $wpdb;

$cohorts_before = get_option( LNKup_Cohort_Landings::OPTION, null );
$conv_ids       = array();
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $wpdb, $cohorts_before, &$conv_ids ) {
	null === $cohorts_before ? delete_option( LNKup_Cohort_Landings::OPTION ) : update_option( LNKup_Cohort_Landings::OPTION, $cohorts_before );
	foreach ( $conv_ids as $id ) {
		$wpdb->delete( LNKup_Meta_Tables::conversions(), array( 'id' => $id ) );
	}
};

/**
 * Render a tab as a user and return the HTML.
 */
function lnkup_mc_render( string $tab, int $user, array $get = array() ): string {
	wp_set_current_user( $user );
	$_GET = array_merge( array( 'page' => LNKup_Marketing_Admin::SLUG, 'tab' => $tab ), $get );
	ob_start();
	LNKup_Marketing_Admin::render();
	$html = (string) ob_get_clean();
	$_GET = array();
	wp_set_current_user( 0 );
	return $html;
}

/**
 * Insert a non-test conversion row with a last-touch campaign.
 */
function lnkup_mc_conversion( string $event, string $campaign, int $is_test = 0 ): int {
	global $wpdb;
	$row = array(
		'event_id'         => wp_generate_uuid4(),
		'event_name'       => $event,
		'dedupe_key'       => 'mc-test-' . wp_generate_password( 12, false ),
		'entity_type'      => 'user',
		'entity_id'        => 0,
		'user_id'          => 0,
		'attribution_json' => wp_json_encode( array( 'lt' => array( 'utm_source' => 'mos-src', 'utm_campaign' => $campaign ) ) ),
		'is_test'          => $is_test,
		'created_at'       => current_time( 'mysql', true ),
	);
	$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . LNKup_Meta_Tables::conversions() ); // phpcs:ignore
	$wpdb->insert( LNKup_Meta_Tables::conversions(), array_intersect_key( $row, array_flip( $cols ) ) );
	return (int) $wpdb->insert_id;
}

$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 );
$mkt   = lnkup_t_user( 'lnkup_marketing' );
$vend  = lnkup_t_user( 'lnkup_vendor' );

echo "Access\n";
wp_set_current_user( $mkt );
lnkup_t_assert( LNKup_Marketing_Admin::can(), 'marketing role can open the command center' );
lnkup_t_assert( ! LNKup_Marketing_Admin::can_view_revenue(), 'marketing role has no revenue access' );
wp_set_current_user( $vend );
lnkup_t_assert( ! LNKup_Marketing_Admin::can(), 'vendors cannot open the command center' );
wp_set_current_user( $admin );
lnkup_t_assert( LNKup_Marketing_Admin::can_view_revenue(), 'administrator sees revenue attribution' );
wp_set_current_user( 0 );

echo "Tabs\n";
foreach ( array_keys( LNKup_Marketing_Admin::TABS ) as $tab ) {
	foreach ( array( 'admin' => $admin, 'marketing' => $mkt ) as $who => $user ) {
		$html = lnkup_mc_render( $tab, $user );
		lnkup_t_assert( false !== strpos( $html, 'data-testid="lnkup-marketing-admin"' ) && false === stripos( $html, 'Fatal error' ) && false === stripos( $html, 'Warning:' ), "{$tab} renders for {$who}" );
	}
}
$html = lnkup_mc_render( 'overview', $mkt );
lnkup_t_assert( false !== strpos( $html, 'needs financial access' ) && false === strpos( $html, 'Net platform fees' ), 'marketing role sees a finance-access note instead of revenue' );
$html = lnkup_mc_render( 'overview', $admin );
lnkup_t_assert( false !== strpos( $html, 'Net platform fees' ) || false !== strpos( $html, 'No paid bookings' ), 'administrator sees the revenue table' );
lnkup_t_assert( false !== strpos( lnkup_mc_render( 'partners', $admin ), 'id="partners"' ), 'tab=partners opens the partner pages section' );
lnkup_t_assert( false !== strpos( lnkup_mc_render( 'nope', $admin ), 'data-testid="lnkup-mc-kpis"' ), 'unknown tab falls back to the overview' );
if ( ! LNKup_Search_Console::connected() ) {
	lnkup_t_assert( false !== strpos( lnkup_mc_render( 'seo', $admin ), 'Not connected.' ), 'Search Console shows Not connected without credentials' );
} else {
	lnkup_t_skip( 'Search Console credentials are configured here; not-connected state not checked' );
}
lnkup_t_assert( false !== strpos( lnkup_mc_render( 'integrations', $mkt ), 'SMS' ), 'integrations lists SMS status' );
$flags_admin = lnkup_mc_render( 'diagnostics', $admin );
$flags_mkt   = lnkup_mc_render( 'diagnostics', $mkt );
lnkup_t_assert( false !== strpos( $flags_admin, 'name="flags[' ), 'administrators can toggle flags' );
lnkup_t_assert( false === strpos( $flags_mkt, 'name="flags[' ), 'marketing role sees flags read-only' );

echo "KPIs and filters\n";
$campaign   = 'mos-mc-' . wp_generate_password( 5, false );
$conv_ids[] = lnkup_mc_conversion( 'CompleteRegistration', $campaign );
$conv_ids[] = lnkup_mc_conversion( 'CompleteRegistration', $campaign );
$conv_ids[] = lnkup_mc_conversion( 'CompleteRegistration', $campaign, 1 );
$conv_ids[] = lnkup_mc_conversion( 'SubmitApplication', 'other-campaign' );
$f          = LNKup_Marketing_Admin::filters( array( 'campaign' => $campaign ) );
$conv       = LNKup_Marketing_Admin::conversions( $f );
lnkup_t_same( 2, $conv['CompleteRegistration'] ?? 0, 'campaign filter counts only matching, non-test signups' );
lnkup_t_same( 0, $conv['SubmitApplication'] ?? 0, 'other campaigns are excluded' );
$kpis = array_column( LNKup_Marketing_Admin::kpis( $f ), 'value', 'label' );
lnkup_t_same( 2, $kpis['Signups'], 'Signups KPI matches stored conversions' );
$f = LNKup_Marketing_Admin::filters( array( 'from' => '2026-02-30', 'to' => 'x' ) );
lnkup_t_same( current_time( 'Y-m-d' ), $f['to'], 'invalid dates fall back to today' );
$f = LNKup_Marketing_Admin::filters( array( 'from' => '2026-05-10', 'to' => '2026-05-01' ) );
lnkup_t_same( array( '2026-05-01', '2026-05-10' ), array( $f['from'], $f['to'] ), 'reversed range is swapped' );
$f = LNKup_Marketing_Admin::filters( array( 'campaign' => "x' OR 1=1 -- " ) );
lnkup_t_same( 0, array_sum( LNKup_Marketing_Admin::conversions( $f ) ), 'filter values are bound, not interpolated' );

echo "CSV safety\n";
lnkup_t_same( "'=HYPERLINK(1)", LNKup_Marketing_Admin::csv_cell( '=HYPERLINK(1)' ), 'formula cells are neutralized' );
lnkup_t_same( '-12.5', LNKup_Marketing_Admin::csv_cell( '-12.5' ), 'negative numbers are kept' );
lnkup_t_same( 'instagram', LNKup_Marketing_Admin::csv_cell( 'instagram' ), 'plain text is unchanged' );

echo "Diagnostics\n";
$labels = array_column( LNKup_Marketing_Admin::diagnostics(), 'label' );
foreach ( array( 'Payments with more than one Purchase conversion', 'Signups with no campaign or referral touch (30 days)', 'Campaigns stopped with an error' ) as $label ) {
	lnkup_t_assert( in_array( $label, $labels, true ), "diagnostics include: {$label}" );
}

echo "Content and cohorts\n";
$saved = LNKup_Cohort_Landings::save( array( 'slug' => 'mos-mc-cohort', 'title' => 'MC Cohort', 'speakers' => array( array( 'name' => 'Ada', 'role' => '<b>Host</b>' ) ) ) );
lnkup_t_assert( is_array( $saved ) && 'Host' === $saved['speakers'][0]['role'], 'cohort save strips markup' );
lnkup_t_assert( false !== strpos( lnkup_mc_render( 'content', $mkt ), 'MC Cohort' ), 'cohort appears on the content tab' );
$c = LNKup_Marketing_Admin::content();
lnkup_t_assert( isset( $c['counts']['publish'], $c['counts']['pending'], $c['counts']['draft'] ), 'content counts by status' );

lnkup_m_finish();
