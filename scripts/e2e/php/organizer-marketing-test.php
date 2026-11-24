<?php
/**
 * Phase 4: organizer Marketing toolkit — permissions, ownership, context, links, performance
 * suggestions, list embeds, local QR (no third-party QR service anywhere).
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/organizer-marketing-test.php
 */

require_once __DIR__ . '/_marketing_helpers.php';
lnkup_m_flags( array( 'seo_index_policy', 'tracking_links', 'organizer_marketing' ) );
global $wpdb;

$org    = lnkup_t_user( 'lnkup_organizer' );
$other  = lnkup_t_user( 'lnkup_organizer' );
$vendor = lnkup_t_user( 'lnkup_vendor' );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $wpdb, $org, $other ) {
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Marketing_Tables::links() . ' WHERE owner_user_id IN (%d,%d)', $org, $other ) ) as $id ) { // phpcs:ignore
		$wpdb->delete( LNKup_Marketing_Tables::link_daily(), array( 'link_id' => (int) $id ) );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Marketing_Tables::links() . ' WHERE owner_user_id IN (%d,%d)', $org, $other ) ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_lnkup\_embed\_list\_%' OR option_name LIKE '\_transient\_timeout\_lnkup\_embed\_list\_%'" ); // phpcs:ignore
};
$mine   = lnkup_m_event( array( 'organizer_user_id' => $org, 'event_status' => 'live' ) );
$theirs = lnkup_m_event( array( 'organizer_user_id' => $other ) );
update_post_meta( $mine['opportunity'], '_lnkup_application_deadline', gmdate( 'Y-m-d', strtotime( '+4 days' ) ) );
update_post_meta( $theirs['opportunity'], '_lnkup_application_deadline', gmdate( 'Y-m-d', strtotime( '+20 days' ) ) );

/**
 * Run a REST request as a user.
 */
function lnkup_om_req( int $user, string $method, string $route, array $params = array() ): WP_REST_Response {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	return rest_ensure_response( rest_do_request( $req ) );
}

echo "Local QR\n";
$svg = LNKup_QR::svg( 'https://example.test/l/abc123/' );
lnkup_t_assert( 0 === strpos( $svg, '<svg' ) && false !== strpos( $svg, '<path' ), 'QR SVG generated locally' );
lnkup_t_assert( 0 === strpos( LNKup_QR::data_uri( 'CHK-8842' ), 'data:image/svg+xml;base64,' ), 'QR data URI for <img src>' );
lnkup_t_same( '', LNKup_QR::svg( str_repeat( 'x', 1001 ) ), 'oversized payload refused' );
$hits = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( LNKUP_PLUGIN_DIR ) ) as $file ) {
	if ( $file->isFile() && preg_match( '/\.(php|js)$/', $file->getFilename() ) && false !== strpos( (string) file_get_contents( $file->getPathname() ), 'api.qrserver.com' ) ) { // phpcs:ignore
		$hits[] = str_replace( LNKUP_PLUGIN_DIR, '', $file->getPathname() );
	}
}
lnkup_t_same( array(), $hits, 'no plugin code calls api.qrserver.com' );
lnkup_t_assert( 0 === strpos( LNKup_App_Promo::qr_url( 'vendor' ), 'data:image/svg+xml' ), 'app promo QR is local' );

echo "Permissions\n";
lnkup_t_assert( in_array( lnkup_om_req( $vendor, 'GET', '/lnkup/v1/organizer/marketing/context' )->get_status(), array( 401, 403 ), true ), 'vendors cannot open organizer marketing' );
lnkup_t_assert( in_array( lnkup_om_req( 0, 'GET', '/lnkup/v1/organizer/marketing/context' )->get_status(), array( 401, 403 ), true ), 'guests cannot open organizer marketing' );
lnkup_t_same( 'marketing', lnkup_organizer_dashboard_view( 'marketing' ), 'view allowlisted while the flag is on' );
LNKup_Marketing_Flags::override( 'organizer_marketing', false );
lnkup_t_same( 'home', lnkup_organizer_dashboard_view( 'marketing' ), 'view falls back to home when the flag is off' );
lnkup_t_assert( in_array( lnkup_om_req( $org, 'GET', '/lnkup/v1/organizer/marketing/context' )->get_status(), array( 401, 403 ), true ), 'flag off -> endpoints closed' );
LNKup_Marketing_Flags::override( 'organizer_marketing', true );

echo "Context\n";
$ctx = lnkup_om_req( $org, 'GET', '/lnkup/v1/organizer/marketing/context' );
lnkup_t_same( 200, $ctx->get_status(), 'organizer opens marketing context' );
$ids = wp_list_pluck( $ctx->get_data()['events'], 'id' );
lnkup_t_assert( in_array( $mine['event'], $ids, true ) && ! in_array( $theirs['event'], $ids, true ), 'context lists only the organizer\'s own events' );
$ev = null;
foreach ( $ctx->get_data()['events'] as $e ) {
	if ( $e['id'] === $mine['event'] ) {
		$ev = $e;
	}
}
lnkup_t_assert( $ev && 10 === $ev['open_spots'] && '' !== $ev['deadline'], 'event payload uses real open spots and deadline' );
$blob = wp_json_encode( $ctx->get_data() );
lnkup_t_assert( false === strpos( $blob, 'venue_address' ) && false === strpos( $blob, (string) get_userdata( $org )->user_email ), 'context carries no private address or email' );
foreach ( $ctx->get_data()['copy'] as $key => $tpl ) {
	preg_match_all( '/\{(\w+)\}/', $tpl['text'], $m );
	if ( array_diff( $m[1], array( 'title', 'date', 'city', 'where', 'link', 'categories', 'deadline', 'organizer' ) ) ) {
		lnkup_t_assert( false, "copy template {$key} uses only known placeholders" );
	}
}
lnkup_t_assert( true, 'copy templates use only known placeholders' );

echo "Links via REST\n";
$res = lnkup_om_req( $org, 'POST', '/lnkup/v1/organizer/marketing/links', array( 'target_type' => 'event', 'target_id' => $mine['event'], 'channel' => 'instagram', 'label' => 'Bio' ) );
lnkup_t_same( 200, $res->get_status(), 'organizer creates link for own event' );
$link = $res->get_data()['link'];
lnkup_t_assert( isset( $link['url'] ) && ! isset( $link['owner_user_id'] ) && ! isset( $link['ref_code'] ), 'link response omits internal owner/referral columns' );
lnkup_t_same( 403, lnkup_om_req( $org, 'POST', '/lnkup/v1/organizer/marketing/links', array( 'target_type' => 'event', 'target_id' => $theirs['event'], 'channel' => 'instagram' ) )->get_status(), 'cannot create links for another organizer\'s event' );
lnkup_t_same( 403, lnkup_om_req( $org, 'GET', '/lnkup/v1/organizer/marketing/links', array( 'event_id' => $theirs['event'] ) )->get_status(), 'cannot list links for another organizer\'s event' );
lnkup_t_same( 404, lnkup_om_req( $other, 'POST', '/lnkup/v1/organizer/marketing/links/' . $link['id'], array( 'status' => 'paused' ) )->get_status(), 'another organizer cannot pause the link' );
lnkup_t_same( 'paused', lnkup_om_req( $org, 'POST', '/lnkup/v1/organizer/marketing/links/' . $link['id'], array( 'status' => 'paused' ) )->get_data()['link']['status'], 'owner pauses link' );
lnkup_om_req( $org, 'POST', '/lnkup/v1/organizer/marketing/links/' . $link['id'], array( 'status' => 'active' ) );
lnkup_t_same( 1, count( lnkup_om_req( $org, 'GET', '/lnkup/v1/organizer/marketing/links', array( 'event_id' => $mine['event'] ) )->get_data()['links'] ), 'links filtered to the selected event' );

echo "Performance and suggestions\n";
$perf = lnkup_om_req( $org, 'GET', '/lnkup/v1/organizer/marketing/performance', array( 'event_id' => $mine['event'] ) );
lnkup_t_same( 200, $perf->get_status(), 'performance loads' );
$rec_ids = wp_list_pluck( $perf->get_data()['recommendations'], 'id' );
lnkup_t_assert( in_array( 'deadline_soon', $rec_ids, true ), 'deadline within 7 days with open spots -> closing-soon suggestion' );
lnkup_t_assert( ! in_array( 'no_links', $rec_ids, true ), 'no "create links" suggestion once a link exists' );
lnkup_t_assert( ! in_array( 'views_no_applications', $rec_ids, true ), 'no views suggestion without real views' );
$wpdb->update( LNKup_Marketplace_Index::table_name(), array( 'view_count' => 40 ), array( 'opportunity_id' => $mine['opportunity'] ) ); // phpcs:ignore
$perf2 = lnkup_om_req( $org, 'GET', '/lnkup/v1/organizer/marketing/performance', array( 'event_id' => $mine['event'] ) );
lnkup_t_assert( in_array( 'views_no_applications', wp_list_pluck( $perf2->get_data()['recommendations'], 'id' ), true ) && 40 === $perf2->get_data()['event']['views'], '40 real views and no applications -> suggestion' );
$theirs_perf = LNKup_Organizer_Marketing::recommendations( $theirs['event'], LNKup_Organizer_Marketing::event_metrics( $theirs['event'] ), array() );
lnkup_t_assert( ! in_array( 'deadline_soon', wp_list_pluck( $theirs_perf, 'id' ), true ) && in_array( 'no_links', wp_list_pluck( $theirs_perf, 'id' ), true ), 'deadline 20 days away -> no urgency claim' );
foreach ( $perf2->get_data()['recommendations'] as $r ) {
	if ( preg_match( '/guarantee|will (get|fill|sell)/i', $r['text'] ) ) {
		lnkup_t_assert( false, 'suggestions never promise results' );
	}
}
lnkup_t_assert( true, 'suggestions never promise results' );
lnkup_t_same( 403, lnkup_om_req( $org, 'GET', '/lnkup/v1/organizer/marketing/performance', array( 'event_id' => $theirs['event'] ) )->get_status(), 'cannot read another organizer\'s performance' );

echo "List embeds\n";
$theirs_link = LNKup_Tracking_Links::create( array( 'owner_user_id' => $other, 'target_type' => 'event', 'target_id' => $theirs['event'], 'channel' => 'website' ) );
$emb = lnkup_om_req( 0, 'GET', '/lnkup/v1/public/embeds/events/' . $mine['event'] . '/opportunities', array( 'lnk' => $link['code'] ) );
lnkup_t_same( 200, $emb->get_status(), 'public event list embed' );
$items = $emb->get_data()['data']['items'];
lnkup_t_assert( 1 === count( $items ) && false !== strpos( $items[0]['apply_url'], 'lnk=' . $link['code'] ), 'apply URL carries the organizer\'s own link code' );
lnkup_t_assert( null === $items[0]['vendor_fee'] && null === $items[0]['spots_left'], 'embed never exposes pricing or inventory' );
$emb2 = lnkup_om_req( 0, 'GET', '/lnkup/v1/public/embeds/events/' . $mine['event'] . '/opportunities', array( 'lnk' => $theirs_link['code'] ) );
lnkup_t_assert( false === strpos( wp_json_encode( $emb2->get_data() ), $theirs_link['code'] ), 'another organizer\'s link code is dropped' );
wp_update_post( array( 'ID' => $mine['event'], 'post_status' => 'draft' ) );
lnkup_t_same( 404, lnkup_om_req( 0, 'GET', '/lnkup/v1/public/embeds/events/' . $mine['event'] . '/opportunities' )->get_status(), 'draft event embed 404' );
wp_update_post( array( 'ID' => $mine['event'], 'post_status' => 'publish' ) );
wp_set_current_user( 0 );

lnkup_m_finish();
