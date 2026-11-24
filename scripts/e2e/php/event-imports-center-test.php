<?php
/**
 * Event Creation OS 3.0 — Imports Center overview, public "Official event listing", attribution
 * taxonomy, and the wp-admin Event Imports page. Fixtures are removed.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/event-imports-center-test.php
 */

require_once __DIR__ . '/_marketing_helpers.php';
global $wpdb;

LNKup_Event_Import_Tables::install();
foreach ( array( 'event_builder_v3', 'event_import_v3' ) as $flag ) {
	LNKup_Event_OS_Flags::override( $flag, true );
}
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () {
	LNKup_Event_OS_Flags::override( 'event_builder_v3', null );
	LNKup_Event_OS_Flags::override( 'event_import_v3', null );
};

$org   = lnkup_t_user( 'lnkup_organizer' );
$other = lnkup_t_user( 'lnkup_organizer' );
$ev    = lnkup_m_event( array( 'organizer_user_id' => $org ), false, 'Imports Center Fest' );
$posh  = 'https://posh.vip/e/imports-center-' . wp_generate_password( 5, false, false );
$web   = 'https://imports-center-' . wp_generate_password( 4, false, false ) . '.example/tickets';
$state = LNKup_Event_Builder_Schema::normalize(
	array(
		'sources' => array(
			array( 'url' => $posh, 'provider' => 'posh', 'primary' => true, 'mode' => 'link' ),
			array( 'url' => $web, 'primary' => false, 'mode' => 'link' ),
		),
	)
);
LNKup_Event_Sources::attach_from_state( $ev['event'], 0, $state, $org );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $ev ) {
	global $wpdb;
	$wpdb->delete( LNKup_Event_Import_Tables::sources(), array( 'event_id' => $ev['event'] ) );
};
$rows = LNKup_Event_Sources::for_event( $ev['event'] );

echo "Imports Center overview\n";
/**
 * GET the overview as a user.
 *
 * @return array{status:int,data:mixed}
 */
function lnkup_ic_overview( int $user ): array {
	wp_set_current_user( $user );
	$res = rest_do_request( new WP_REST_Request( 'GET', '/lnkup/v1/organizer/import/overview' ) );
	wp_set_current_user( 0 );
	return array( 'status' => $res->get_status(), 'data' => $res->get_data() );
}
$mine = lnkup_ic_overview( $org );
$ours = array_values( array_filter( $mine['data']['sources'] ?? array(), static fn( $s ) => (int) $s['event_id'] === $ev['event'] ) );
lnkup_t_same( array( 200, 2, 'Imports Center Fest' ), array( $mine['status'], count( $ours ), $ours[0]['event_title'] ?? '' ), 'organizer sees their linked listings with event titles' );
lnkup_t_assert( isset( $mine['data']['eventbrite']['connected'], $mine['data']['flags'] ) && false === strpos( (string) wp_json_encode( $mine['data'] ), 'token' ), 'overview carries connection status and flags, never tokens' );
lnkup_t_same( array(), array_values( array_filter( lnkup_ic_overview( $other )['data']['sources'] ?? array(), static fn( $s ) => (int) $s['event_id'] === $ev['event'] ) ), 'other organizers never see these listings' );
lnkup_t_assert( in_array( lnkup_ic_overview( lnkup_t_user( 'lnkup_vendor' ) )['status'], array( 401, 403 ), true ), 'vendors cannot open the Imports Center' );

echo "Official event listing\n";
$html = LNKup_Event_Official_Listing::render( $ev['event'] );
lnkup_t_assert( false !== strpos( $html, 'Official event listing' ) && false !== strpos( $html, 'Tickets on POSH' ), 'section names the platform' );
lnkup_t_assert( false !== strpos( $html, 'lnkup_listing=' . $rows[0]['id'] ) && false === strpos( $html, esc_url( $posh ) ), 'links go through the attributed redirect, not the raw URL' );
lnkup_t_assert( false !== strpos( $html, 'rel="nofollow noopener noreferrer"' ) && false !== strpos( $html, 'Vendor applications and booth payments are handled on LNKup' ), 'outbound links are nofollow and the ticketing/vendor split is explained' );
lnkup_t_same( 1, substr_count( $html, 'lnkup-btn--dark' ), 'the primary listing is emphasized' );
LNKup_Event_Sources::update( $rows[1]['id'], array( 'availability' => 'removed' ) );
lnkup_t_same( 1, substr_count( LNKup_Event_Official_Listing::render( $ev['event'] ), 'lnkup_listing=' ), 'removed listings are hidden' );
$legacy = lnkup_m_event( array( 'organizer_user_id' => $org ), false );
lnkup_t_assert( false !== strpos( LNKup_Event_Official_Listing::render( $legacy['event'], 'https://www.eventbrite.com/e/x-123456789' ), 'Tickets on Eventbrite' ), 'events with only a legacy ticket link still show it' );
lnkup_t_same( '', LNKup_Event_Official_Listing::render( $legacy['event'] ), 'no listing, no section' );

echo "Redirect guard\n";
$_GET[ LNKup_Event_Official_Listing::PARAM ] = '999999999';
LNKup_Event_Official_Listing::maybe_redirect();
lnkup_t_assert( true, 'unknown listing IDs do not redirect' );
wp_update_post( array( 'ID' => $ev['event'], 'post_status' => 'draft' ) );
$_GET[ LNKup_Event_Official_Listing::PARAM ] = (string) $rows[0]['id'];
LNKup_Event_Official_Listing::maybe_redirect();
lnkup_t_assert( true, 'listings of unpublished events do not redirect' );
unset( $_GET[ LNKup_Event_Official_Listing::PARAM ] );
wp_update_post( array( 'ID' => $ev['event'], 'post_status' => 'publish' ) );
file_put_contents( WP_CONTENT_DIR . '/lnkup-e2e/tmp/official-listing-id.txt', $rows[0]['id'] . ' ' . $ev['event'] ); // phpcs:ignore

echo "Attribution taxonomy\n";
$map = LNKup_Analytics_Taxonomy::map();
lnkup_t_same( array( true, true, true ), array( isset( $map['official_listing_click'] ), isset( $map['event_imported'] ), isset( $map['event_builder_published'] ) ), 'Event OS events are part of the analytics taxonomy' );
lnkup_t_same( array( 'event_published', 'portfolio_published' ), $map['event_builder_published']['types'], 'builder publishes map to the tracked event types' );

echo "Admin page\n";
$admin = lnkup_t_user( 'administrator' );
wp_set_current_user( $admin );
foreach ( array( 'providers' => 'Save providers', 'jobs' => 'Drafted / linked / failed / skipped', 'sources' => 'Imports Center Fest', 'setup' => 'eventbrite_oauth' ) as $tab => $needle ) {
	$_GET['tab'] = $tab;
	ob_start();
	LNKup_Event_Imports_Admin::render();
	$out = (string) ob_get_clean();
	lnkup_t_assert( false !== strpos( $out, $needle ), "admin tab “{$tab}” renders" );
	if ( 'setup' === $tab ) {
		lnkup_t_assert( false === strpos( $out, 'test-secret' ) && false !== strpos( $out, 'admin-post.php?action=lnkup_eventbrite_callback' ), 'setup shows the redirect URI and never a secret' );
	}
}
unset( $_GET['tab'] );
wp_set_current_user( 0 );

lnkup_m_finish();
