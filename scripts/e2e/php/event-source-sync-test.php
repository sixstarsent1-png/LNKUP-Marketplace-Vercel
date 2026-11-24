<?php
/**
 * Event Creation OS 3.0 — external sources panel, listing sync with change review, Edit 2.0
 * application of accepted changes, and the Eventbrite webhook receiver. HTTP is mocked; no
 * emails or pushes are sent; fixtures are removed.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/event-source-sync-test.php
 */

require_once __DIR__ . '/_marketing_helpers.php';
global $wpdb;

LNKup_Event_Import_Tables::install();
foreach ( array( 'event_builder_v3', 'event_import_v3', 'event_source_sync' ) as $flag ) {
	LNKup_Event_OS_Flags::override( $flag, true );
}
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () {
	foreach ( array( 'event_builder_v3', 'event_import_v3', 'event_source_sync' ) as $flag ) {
		LNKup_Event_OS_Flags::override( $flag, null );
	}
	delete_option( LNKup_Event_Sources_REST::WEBHOOKS );
	wp_clear_scheduled_hook( LNKup_Event_Source_Sync::CRON );
};
add_filter( 'lnkup_safe_fetch_resolve', static fn( $ips, $host ) => 'sync.lnkup-test.example' === $host ? array( '93.184.216.34' ) : $ips, 10, 2 );
add_filter( 'lnkup_safe_fetch_rate', static fn() => 1000 );

$date    = gmdate( 'Y-m-d', strtotime( '+45 days' ) );
$listing = array( 'name' => 'Riverfront Night Market', 'start' => '17:00', 'venue' => 'River Lot', 'desc' => 'Evening market by the river with food trucks and makers.', 'code' => 200 );
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$listing, $date ) {
		if ( 0 !== strpos( $url, 'https://sync.lnkup-test.example/' ) ) {
			return $pre;
		}
		$ld = array( '@type' => 'Event', 'name' => $listing['name'], 'startDate' => $date . 'T' . $listing['start'], 'description' => $listing['desc'], 'location' => array( '@type' => 'Place', 'name' => $listing['venue'], 'address' => array( 'streetAddress' => '1 River Rd', 'addressLocality' => 'Charlotte', 'addressRegion' => 'NC' ) ) );
		return array( 'headers' => array( 'content-type' => 'text/html' ), 'body' => '<script type="application/ld+json">' . wp_json_encode( $ld ) . '</script>', 'response' => array( 'code' => $listing['code'], 'message' => '' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);

/**
 * REST call as a user.
 *
 * @return array{status:int,data:mixed}
 */
function lnkup_sync_rest( string $method, string $route, array $body, int $user ): array {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, '/lnkup/v1' . $route );
	if ( 'GET' !== $method ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	wp_set_current_user( 0 );
	$data = $res->get_data();
	if ( is_array( $data ) && isset( $data['draft']['id'] ) ) {
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $data['draft']['id'] );
	}
	return array( 'status' => $res->get_status(), 'data' => $data );
}

$org   = lnkup_t_user( 'lnkup_organizer' );
$other = lnkup_t_user( 'lnkup_organizer' );
$url   = 'https://sync.lnkup-test.example/e/riverfront-' . wp_generate_password( 5, false, false );

echo "Import and publish\n";
$dr = lnkup_sync_rest( 'POST', '/organizer/import/draft', array( 'url' => $url ), $org )['data'];
$id = (int) $dr['draft']['id'];
$p  = lnkup_sync_rest( 'PATCH', '/organizer/builder/drafts/' . $id, array( 'revision' => $dr['draft']['revision'], 'patch' => array( 'event' => array( 'location_confirmed' => true ), 'options' => array( array( 'key' => 'r', 'name' => 'Retail', 'category' => 'Retail', 'fee' => 50, 'spots' => 10 ) ), 'publish' => array( 'attest' => true ) ) ), $org )['data'];
lnkup_t_same( true, $p['report']['publishable'] ?? false, 'imported draft is publishable after options and confirmation' );
$pub      = lnkup_sync_rest( 'POST', '/organizer/builder/drafts/' . $id . '/publish', array(), $org )['data'];
$event_id = (int) ( $pub['event_id'] ?? 0 );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $event_id );
foreach ( LNKup_Event_Builder_Editor::opportunities( $event_id ) as $opp ) {
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $opp );
}
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $event_id ) {
	global $wpdb;
	$wpdb->delete( LNKup_Event_Import_Tables::sources(), array( 'event_id' => $event_id ) );
};
$src = LNKup_Event_Sources::for_event( $event_id )[0] ?? array();
lnkup_t_same( array( 'import', 'Riverfront Night Market', '17:00' ), array( $src['mode'] ?? '', $src['provenance']['values']['name'] ?? '', $src['provenance']['values']['start_time'] ?? '' ), 'source keeps a snapshot of the imported values' );
$sid = (int) $src['id'];

echo "Permissions\n";
lnkup_t_same( 403, lnkup_sync_rest( 'GET', '/organizer/events/' . $event_id . '/sources', array(), $other )['status'], 'other organizers cannot see the sources panel' );
lnkup_t_same( 403, lnkup_sync_rest( 'POST', '/organizer/events/' . $event_id . '/sources/' . $sid . '/check', array(), lnkup_t_user( 'lnkup_vendor' ) )['status'], 'vendors cannot trigger checks' );
$list = lnkup_sync_rest( 'GET', '/organizer/events/' . $event_id . '/sources', array(), $org )['data'];
lnkup_t_same( array( 1, true, true ), array( count( $list['sources'] ), $list['sources'][0]['is_primary'], $list['sync_enabled'] ), 'organizer sees one primary listing with sync on' );

echo "Organizer edit, then the listing changes\n";
$edit = LNKup_Event_Builder_Editor::start( $event_id, $org );
LNKup_Event_Builder_Drafts::save( (int) $edit['id'], array( 'event' => array( 'description' => 'Our own words about the market, written by the organizer.' ) ), (int) $edit['revision'] );
$applied = LNKup_Event_Builder_Editor::apply( (int) $edit['id'], $org, true );
lnkup_t_assert( ! is_wp_error( $applied ), 'organizer edit saved through Edit 2.0' );
lnkup_t_same( 'import', LNKup_Event_Sources::get( $sid )['mode'], 'editing the event keeps the source in import mode (sync continues)' );
$listing = array_merge( $listing, array( 'start' => '18:00', 'venue' => 'River Pavilion', 'desc' => 'New listing text from the platform.' ) );
$chk     = lnkup_sync_rest( 'POST', '/organizer/events/' . $event_id . '/sources/' . $sid . '/check', array(), $org );
$changes = array_column( $chk['data']['source']['pending_changes']['changes'] ?? array(), null, 'field' );
lnkup_t_same( 200, $chk['status'], 'manual check runs' );
lnkup_t_same( array( '17:00', '18:00', false, true ), array( $changes['start_time']['current'] ?? '', $changes['start_time']['incoming'] ?? '', $changes['start_time']['edited'] ?? null, $changes['start_time']['material'] ?? null ), 'time change proposed (material, not edited by organizer)' );
lnkup_t_same( true, $changes['description']['edited'] ?? null, 'organizer-edited description is flagged, never overwritten automatically' );
lnkup_t_same( '17:00', LNKup_Event_Builder_Editor::state_from_event( $event_id )['event']['start_time'], 'nothing changes on the event until the organizer accepts' );
lnkup_t_same( 429, lnkup_sync_rest( 'POST', '/organizer/events/' . $event_id . '/sources/' . $sid . '/check', array(), $org )['status'], 'manual checks have a cool-off' );

echo "Apply selected changes\n";
$ap = lnkup_sync_rest( 'POST', '/organizer/events/' . $event_id . '/sources/' . $sid . '/apply', array( 'fields' => array( 'start_time' ) ), $org );
lnkup_t_same( array( 200, array( 'start_time' ), 2 ), array( $ap['status'], $ap['data']['applied'] ?? null, $ap['data']['remaining'] ?? null ), 'only the chosen change is applied' );
lnkup_t_same( '18:00', LNKup_Event_Builder_Editor::state_from_event( $event_id )['event']['start_time'], 'event time updated through Edit 2.0' );
lnkup_t_same( 'Our own words about the market, written by the organizer.', LNKup_Event_Builder_Editor::state_from_event( $event_id )['event']['description'], 'organizer description is untouched' );

$opp_id = LNKup_Event_Builder_Editor::opportunities( $event_id )[0];
$app    = wp_insert_post( array( 'post_type' => 'lnkup_application', 'post_status' => 'publish', 'post_title' => 'Sync booked vendor' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $app );
update_post_meta( $app, '_lnkup_opportunity_id', $opp_id );
update_post_meta( $app, '_lnkup_event_id', $event_id );
update_post_meta( $app, '_lnkup_application_status', 'approved' );
$need = lnkup_sync_rest( 'POST', '/organizer/events/' . $event_id . '/sources/' . $sid . '/apply', array( 'fields' => array( 'venue_name' ) ), $org );
lnkup_t_same( 409, $need['status'], 'a venue change with booked vendors needs confirmation' );
lnkup_t_same( 'River Lot', LNKup_Event_Builder_Editor::state_from_event( $event_id )['event']['venue_name'], 'venue unchanged until confirmed' );
lnkup_t_same( 0, count( get_posts( array( 'post_type' => LNKup_Event_Builder_Drafts::POST_TYPE, 'post_status' => 'draft', 'fields' => 'ids', 'meta_key' => LNKup_Event_Builder_Drafts::SOURCE_META, 'meta_value' => $event_id ) ) ), 'refused applies leave no stray edit drafts' );
$conf = lnkup_sync_rest( 'POST', '/organizer/events/' . $event_id . '/sources/' . $sid . '/apply', array( 'fields' => array( 'venue_name' ), 'confirm' => true ), $org );
lnkup_t_same( array( 200, 'River Pavilion' ), array( $conf['status'], LNKup_Event_Builder_Editor::state_from_event( $event_id )['event']['venue_name'] ), 'confirmed venue change applied' );

echo "Dismiss and re-check\n";
lnkup_sync_rest( 'POST', '/organizer/events/' . $event_id . '/sources/' . $sid . '/dismiss', array(), $org );
lnkup_t_same( null, LNKup_Event_Sources::get( $sid )['pending_changes'], 'dismiss clears pending changes' );
delete_transient( 'lnkup_source_check_' . $sid );
$again = LNKup_Event_Source_Sync::check( $sid );
lnkup_t_same( null, $again['pending_changes'], 'an unchanged listing proposes nothing after review (organizer kept own description)' );
$listing['code'] = 404;
delete_transient( 'lnkup_import_' . md5( LNKup_Event_Sources::canonical_url( $url ) ) );
LNKup_Event_Source_Sync::check( $sid );
lnkup_t_same( 'removed', LNKup_Event_Sources::get( $sid )['availability'], 'a removed listing is marked, nothing else changes' );

echo "Webhooks\n";
$hook = static function ( array $payload ): array {
	$req = new WP_REST_Request( 'POST', '/lnkup/v1/webhooks/eventbrite' );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_body( (string) wp_json_encode( $payload ) );
	$res = rest_do_request( $req );
	return array( 'status' => $res->get_status(), 'data' => $res->get_data() );
};
$wpdb->update( LNKup_Event_Import_Tables::sources(), array( 'provider' => 'eventbrite', 'external_event_id' => '424242', 'availability' => 'available' ), array( 'id' => $sid ) );
lnkup_t_same( 0, $hook( array( 'config' => array( 'webhook_id' => 'nope' ), 'api_url' => 'https://www.eventbriteapi.com/v3/events/424242/' ) )['data']['queued'], 'unregistered webhook IDs are ignored' );
update_option( LNKup_Event_Sources_REST::WEBHOOKS, array( 'wh1' => $org ), false );
lnkup_t_same( 0, $hook( array( 'config' => array( 'webhook_id' => 'wh1' ), 'api_url' => 'http://169.254.169.254/v3/events/424242/' ) )['data']['queued'], 'payload URLs off the Eventbrite API host are ignored (never fetched)' );
update_option( LNKup_Event_Sources_REST::WEBHOOKS, array( 'wh1' => $other ), false );
lnkup_t_same( 0, $hook( array( 'config' => array( 'webhook_id' => 'wh1' ), 'api_url' => 'https://www.eventbriteapi.com/v3/events/424242/' ) )['data']['queued'], 'a webhook only reaches the organizer who registered it' );
update_option( LNKup_Event_Sources_REST::WEBHOOKS, array( 'wh1' => $org ), false );
$ok = $hook( array( 'config' => array( 'webhook_id' => 'wh1' ), 'api_url' => 'https://www.eventbriteapi.com/v3/events/424242/' ) );
lnkup_t_same( array( 202, 1, true ), array( $ok['status'], $ok['data']['queued'], (bool) wp_next_scheduled( LNKup_Event_Sources_REST::ONE_HOOK, array( $sid ) ) ), 'a valid notification queues a background re-check' );
wp_clear_scheduled_hook( LNKup_Event_Sources_REST::ONE_HOOK, array( $sid ) );

echo "Flags and removal\n";
LNKup_Event_OS_Flags::override( 'event_source_sync', false );
delete_transient( 'lnkup_source_check_' . $sid );
lnkup_t_same( 409, lnkup_sync_rest( 'POST', '/organizer/events/' . $event_id . '/sources/' . $sid . '/check', array(), $org )['status'], 'checks are off without event_source_sync' );
LNKup_Event_OS_Flags::override( 'event_source_sync', true );
$rm = lnkup_sync_rest( 'DELETE', '/organizer/events/' . $event_id . '/sources/' . $sid, array(), $org );
lnkup_t_same( array( 200, 0, '' ), array( $rm['status'], count( $rm['data']['sources'] ?? array( 1 ) ), (string) get_post_meta( $event_id, '_lnkup_ticketing_url', true ) ), 'removing the listing unlinks it and clears the ticket link' );

lnkup_m_finish();
