<?php
/**
 * Event Creation OS 3.0 — bulk import: CSV/ICS parsing, import jobs (review → drafts only),
 * and the credential-gated Eventbrite connection. HTTP is mocked; fixtures are removed.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/event-import-bulk-test.php
 */

require_once __DIR__ . '/_marketing_helpers.php';
global $wpdb;

LNKup_Event_Import_Tables::install();
foreach ( array( 'event_builder_v3', 'event_import_v3', 'event_import_bulk' ) as $flag ) {
	LNKup_Event_OS_Flags::override( $flag, true );
}
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () {
	foreach ( array( 'event_builder_v3', 'event_import_v3', 'event_import_bulk', 'eventbrite_oauth' ) as $flag ) {
		LNKup_Event_OS_Flags::override( $flag, null );
	}
};

add_filter( 'lnkup_safe_fetch_resolve', static fn( $ips, $host ) => preg_match( '/(^|\.)bulk-test\.example$/', $host ) ? ( 0 === strpos( $host, 'private.' ) ? array( '10.0.0.9' ) : array( '93.184.216.34' ) ) : $ips, 10, 2 );
add_filter( 'lnkup_safe_fetch_rate', static fn() => 1000 );
$date   = gmdate( 'Y-m-d', strtotime( '+35 days' ) );
$routes = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$routes ) {
		foreach ( $routes as $prefix => $r ) {
			if ( 0 === strpos( $url, $prefix ) ) {
				$body = is_callable( $r['body'] ) ? ( $r['body'] )( $url, $args ) : $r['body'];
				return array( 'headers' => array( 'content-type' => $r['type'] ?? 'text/html' ), 'body' => $body, 'response' => array( 'code' => $r['code'] ?? 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
			}
		}
		return $pre;
	},
	10,
	3
);
$event_html = static fn( string $name, string $city ) => '<script type="application/ld+json">' . wp_json_encode( array( '@type' => 'Event', 'name' => $name, 'startDate' => $GLOBALS['date'] . 'T11:00', 'location' => array( '@type' => 'Place', 'name' => 'Main Lot', 'address' => array( 'addressLocality' => $city, 'addressRegion' => 'NC' ) ) ) ) . '</script>';
$GLOBALS['date'] = $date;
$routes['https://a.bulk-test.example/'] = array( 'body' => $event_html( 'Spring Makers Market', 'Durham' ) );
$routes['https://b.bulk-test.example/'] = array( 'body' => $event_html( 'Taco Fest', 'Raleigh' ) );

$org   = lnkup_t_user( 'lnkup_organizer' );
$other = lnkup_t_user( 'lnkup_organizer' );

/**
 * REST call as a user against lnkup/v1/organizer/import.
 *
 * @return array{status:int,data:mixed}
 */
function lnkup_bulk_rest( string $method, string $route, array $body, int $user ): array {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, '/lnkup/v1/organizer/import' . $route );
	if ( 'GET' === $method ) {
		$req->set_query_params( $body );
	} else {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	wp_set_current_user( 0 );
	return array( 'status' => $res->get_status(), 'data' => $res->get_data() );
}

/**
 * Remove a job, its items and any drafts it created.
 */
function lnkup_bulk_cleanup_job( int $job_id ): void {
	$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $job_id ) {
		global $wpdb;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT draft_id FROM ' . LNKup_Event_Import_Tables::items() . ' WHERE job_id = %d AND draft_id > 0', $job_id ) ) as $d ) {
			wp_delete_post( (int) $d, true );
		}
		$wpdb->delete( LNKup_Event_Import_Tables::items(), array( 'job_id' => $job_id ) );
		$wpdb->delete( LNKup_Event_Import_Tables::jobs(), array( 'id' => $job_id ) );
	};
}

echo "CSV parsing\n";
$future = gmdate( 'm/d/Y', strtotime( '+50 days' ) );
$csv    = "\xEF\xBB\xBFEvent Name,Date,Start Time,City,State,Ticket Link,Notes\n"
	. "Night Bazaar,{$date},6pm,Charlotte,North Carolina,https://posh.vip/e/night-bazaar?utm_source=x,\n"
	. "\"Fall Fest, Downtown\",{$future},10:30,Greensboro,NC,,\"=HYPERLINK(\"\"x\"\")\"\n"
	. "Mystery Market,next saturday,,Asheville,NC,,\n"
	. ",,,,,,\n";
$p = LNKup_Import_Jobs::parse_csv( $csv );
lnkup_t_same( array( 'Event Name', 'Date', 'Start Time', 'City', 'State', 'Ticket Link', 'Notes' ), $p['headers'] ?? null, 'header row read (BOM stripped)' );
lnkup_t_same( 3, count( $p['rows'] ?? array() ), 'blank rows are skipped; quoted commas stay in one cell' );
lnkup_t_same( array( 0 => 'name', 1 => 'date', 2 => 'start_time', 3 => 'city', 4 => 'state', 5 => 'url' ), $p['suggested'] ?? null, 'columns are mapped from common header names' );
$in = LNKup_Import_Jobs::rows_to_inputs( $p['rows'], $p['suggested'] );
lnkup_t_same( array( 'Night Bazaar', $date, '18:00', 'NC' ), array( $in[0]['fields']['name'] ?? '', $in[0]['fields']['date'] ?? '', $in[0]['fields']['start_time'] ?? '', $in[0]['fields']['state'] ?? '' ), '12-hour times and state names are normalized' );
lnkup_t_same( array( 'Fall Fest, Downtown', gmdate( 'Y-m-d', strtotime( '+50 days' ) ), '10:30' ), array( $in[1]['fields']['name'] ?? '', $in[1]['fields']['date'] ?? '', $in[1]['fields']['start_time'] ?? '' ), 'US month/day/year dates are read' );
lnkup_t_same( array( false, 1 ), array( isset( $in[2]['fields']['date'] ), count( $in[2]['notes'] ) ), 'vague dates ("next saturday") are never guessed — they are flagged' );
lnkup_t_assert( ! isset( $in[1]['fields']['notes'] ) && false === strpos( (string) wp_json_encode( $in ), 'HYPERLINK' ), 'unmapped columns (including formulas) are ignored' );
lnkup_t_same( 'Market', ( LNKup_Import_Jobs::parse_csv( "Title;Date\nMarket;2027-01-01\n" )['rows'][0][0] ?? '' ), 'semicolon-delimited files work' );
lnkup_t_assert( is_wp_error( LNKup_Import_Jobs::parse_csv( 'only,a,header' ) ), 'a header without rows is rejected' );
lnkup_t_same( array( '2027-03-05', '2027-03-05', '' ), array( LNKup_Import_Jobs::parse_date( 'Mar 5, 2027' ), LNKup_Import_Jobs::parse_date( 'Friday, March 5th 2027' ), LNKup_Import_Jobs::parse_date( '05/03' ) ), 'explicit written dates parse; partial dates do not' );

echo "ICS parsing\n";
$y   = gmdate( 'Y', strtotime( '+1 year' ) );
$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
	. "BEGIN:VEVENT\r\nUID:a1@test\r\nSUMMARY:Sunday Market\\, Uptown\r\nDTSTART;TZID=America/New_York:{$y}0607T100000\r\nDTEND;TZID=America/New_York:{$y}0607T150000\r\nLOCATION:Romare Bearden Park\\, 300 S Church St\\, Charlotte\\, NC 28202\r\nDESCRIPTION:Local makers\\nand food\r\n  trucks.\r\nRRULE:FREQ=WEEKLY;BYDAY=SU\r\nURL:https://a.bulk-test.example/sunday\r\nEND:VEVENT\r\n"
	. "BEGIN:VEVENT\r\nSUMMARY:Holiday Fair\r\nDTSTART;VALUE=DATE:{$y}1205\r\nDTEND;VALUE=DATE:{$y}1207\r\nEND:VEVENT\r\n"
	. "BEGIN:VEVENT\r\nSUMMARY:Cancelled thing\r\nSTATUS:CANCELLED\r\nDTSTART:{$y}0101T120000Z\r\nEND:VEVENT\r\n"
	. "BEGIN:VEVENT\r\nSUMMARY:Old event\r\nDTSTART:20200101T120000Z\r\nEND:VEVENT\r\n"
	. "END:VCALENDAR\r\n";
$ev = LNKup_Import_Jobs::parse_ics( $ics );
lnkup_t_same( 2, is_array( $ev ) ? count( $ev ) : -1, 'cancelled and past events are skipped' );
$f = $ev[0]['fields'] ?? array();
lnkup_t_same( array( 'Sunday Market, Uptown', "{$y}-06-07", '10:00', '15:00', 'America/New_York', 'Charlotte', 'NC', 'Romare Bearden Park' ), array( $f['name'] ?? '', $f['date'] ?? '', $f['start_time'] ?? '', $f['end_time'] ?? '', $f['timezone'] ?? '', $f['city'] ?? '', $f['state'] ?? '', $f['venue_name'] ?? '' ), 'escaped text, TZID local time, venue, city and state are read' );
lnkup_t_same( "Local makers\nand food trucks.", $f['description'] ?? '', 'folded lines are joined' );
lnkup_t_same( array( 1, 'https://a.bulk-test.example/sunday' ), array( count( $ev[0]['notes'] ), $ev[0]['url'] ), 'repeating events are noted (not expanded); URL kept as a link' );
lnkup_t_same( array( "{$y}-12-05", "{$y}-12-06" ), array( $ev[1]['fields']['date'] ?? '', $ev[1]['fields']['end_date'] ?? '' ), 'all-day multi-day events use an inclusive end date' );
lnkup_t_assert( is_wp_error( LNKup_Import_Jobs::parse_ics( 'hello' ) ), 'non-calendar files are rejected' );

echo "URL jobs\n";
lnkup_t_assert( in_array( lnkup_bulk_rest( 'POST', '/bulk/jobs', array( 'method' => 'urls', 'urls' => array( 'https://a.bulk-test.example/x' ) ), lnkup_t_user( 'lnkup_vendor' ) )['status'], array( 401, 403 ), true ), 'vendors cannot bulk import' );
$r   = lnkup_bulk_rest( 'POST', '/bulk/jobs', array( 'method' => 'urls', 'urls' => "https://a.bulk-test.example/x\nhttps://a.bulk-test.example/x?utm_source=ig\nhttps://b.bulk-test.example/y\nhttps://private.bulk-test.example/z" ), $org );
$job = $r['data'];
lnkup_bulk_cleanup_job( (int) ( $job['id'] ?? 0 ) );
lnkup_t_same( array( 201, 3 ), array( $r['status'], $job['total'] ?? 0 ), 'job created; duplicate links (tracking variants) collapse' );
lnkup_t_same( array( 2, 1, 'review' ), array( $job['counts']['ready'] ?? 0, $job['counts']['failed'] ?? 0, $job['status'] ?? '' ), 'items read in a batch: public pages ready, private address failed' );
lnkup_t_same( 403, lnkup_bulk_rest( 'GET', '/bulk/jobs/' . $job['id'], array(), $other )['status'], 'another organizer cannot see the job' );
$events_before = (int) wp_count_posts( 'lnkup_event' )->publish;
$ready         = array_values( array_filter( $job['items'], static fn( $i ) => 'ready' === $i['status'] ) );
$ap            = lnkup_bulk_rest( 'POST', '/bulk/jobs/' . $job['id'] . '/apply', array( 'decisions' => array( $ready[0]['id'] => 'draft', $ready[1]['id'] => 'skip' ) ), $org );
lnkup_t_same( 1, count( $ap['data']['created'] ?? array() ), 'only chosen items become drafts' );
$draft = LNKup_Event_Builder_Drafts::get( (int) ( $ap['data']['created'][0] ?? 0 ) );
lnkup_t_same( array( 'bulk', 'Spring Makers Market', 'import' ), array( $draft['state']['method'] ?? '', $draft['state']['event']['name'] ?? '', $draft['state']['sources'][0]['mode'] ?? '' ), 'draft carries the imported details and source' );
lnkup_t_same( $events_before, (int) wp_count_posts( 'lnkup_event' )->publish, 'bulk import never publishes events' );
$again = lnkup_bulk_rest( 'POST', '/bulk/jobs/' . $job['id'] . '/apply', array( 'decisions' => array( $ready[0]['id'] => 'draft' ) ), $org );
lnkup_t_same( array(), $again['data']['created'] ?? null, 'applying again is idempotent' );
$routes['https://private.bulk-test.example/'] = array( 'body' => '' );
$rt = lnkup_bulk_rest( 'POST', '/bulk/jobs/' . $job['id'] . '/retry', array(), $org );
lnkup_t_same( 1, $rt['data']['counts']['failed'] ?? 0, 'retry re-checks failed items (private addresses still refused)' );
lnkup_t_same( 'cancelled', lnkup_bulk_rest( 'POST', '/bulk/jobs/' . $job['id'] . '/cancel', array(), $org )['data']['status'] ?? '', 'job can be cancelled' );
lnkup_t_same( 1, count( array_filter( lnkup_bulk_rest( 'GET', '/bulk/jobs', array(), $org )['data']['jobs'] ?? array(), static fn( $j ) => (int) $j['id'] === (int) $job['id'] ) ), 'jobs are listed for the organizer' );
lnkup_t_same( 400, lnkup_bulk_rest( 'POST', '/bulk/jobs', array( 'method' => 'urls', 'urls' => implode( "\n", array_map( static fn( $i ) => "https://a.bulk-test.example/{$i}", range( 1, 26 ) ) ) ), $org )['status'], 'more than 25 links is refused' );

echo "CSV jobs\n";
$cj = lnkup_bulk_rest( 'POST', '/bulk/jobs', array( 'method' => 'csv', 'csv' => $csv, 'file_name' => 'events.csv', 'mapping' => array( '0' => 'name', '1' => 'date', '2' => 'start_time', '3' => 'city', '4' => 'state', '5' => 'url' ) ), $org );
lnkup_bulk_cleanup_job( (int) ( $cj['data']['id'] ?? 0 ) );
lnkup_t_same( array( 201, 3, 'events.csv' ), array( $cj['status'], $cj['data']['total'] ?? 0, $cj['data']['file_name'] ?? '' ), 'CSV job created from the mapping' );
$it = $cj['data']['items'][0]['preview'] ?? array();
lnkup_t_same( array( 'file', 'https://posh.vip/e/night-bazaar?utm_source=x', 'posh' ), array( $it['provenance']['name'] ?? '', $it['url'] ?? '', $it['provider']['key'] ?? '' ), 'CSV rows carry file provenance; link column attached (not fetched)' );
lnkup_t_same( 'partial', $cj['data']['items'][2]['preview']['status'] ?? '', 'row with an unreadable date is partial' );
lnkup_t_same( 400, lnkup_bulk_rest( 'POST', '/bulk/jobs', array( 'method' => 'csv', 'csv' => $csv, 'mapping' => array( '3' => 'city' ) ), $org )['status'], 'a mapping without an event name is refused' );
$parse = lnkup_bulk_rest( 'POST', '/bulk/csv/parse', array( 'csv' => $csv ), $org );
lnkup_t_same( array( 200, 3 ), array( $parse['status'], $parse['data']['total'] ?? 0 ), 'CSV preview endpoint returns headers and sample rows' );

echo "Eventbrite connection\n";
$st = lnkup_bulk_rest( 'GET', '/eventbrite', array(), $org );
if ( ! defined( 'LNKUP_EVENTBRITE_CLIENT_ID' ) ) {
	lnkup_t_same( array( false, false, 'not_connected' ), array( $st['data']['available'] ?? null, $st['data']['configured'] ?? null, $st['data']['status'] ?? '' ), 'without credentials the connection reports Not connected' );
	lnkup_t_same( 409, lnkup_bulk_rest( 'GET', '/eventbrite/events', array(), $org )['status'], 'event browser is unavailable without credentials' );
	lnkup_t_same( 409, lnkup_bulk_rest( 'POST', '/bulk/jobs', array( 'method' => 'eventbrite', 'ids' => array( '1' ) ), $org )['status'], 'account import jobs are refused without credentials' );
	define( 'LNKUP_EVENTBRITE_CLIENT_ID', 'test-client' );
	define( 'LNKUP_EVENTBRITE_CLIENT_SECRET', 'test-secret' );
}
LNKup_Event_OS_Flags::override( 'eventbrite_oauth', true );
$st = lnkup_bulk_rest( 'GET', '/eventbrite', array(), $org );
lnkup_t_same( true, $st['data']['available'] ?? null, 'with credentials + flag the connection is offered' );
lnkup_t_assert( 0 === strpos( (string) ( $st['data']['connect_url'] ?? '' ), LNKup_Eventbrite_Connection::AUTHORIZE ) && false !== strpos( $st['data']['connect_url'], 'state=' ) && false === strpos( (string) wp_json_encode( $st['data'] ), 'test-secret' ), 'connect URL points to Eventbrite with a state value; the client secret never leaves the server' );

LNKup_Eventbrite_Connection::store( $org, 'tok-123', '' );
$GLOBALS['lnkup_m_extra_cleanup'][] = static fn() => LNKup_Eventbrite_Connection::disconnect( $org );
$stored = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT token FROM ' . LNKup_Event_Import_Tables::connections() . ' WHERE organizer_id = %d', $org ) );
lnkup_t_assert( '' !== $stored && false === strpos( $stored, 'tok-123' ) && 'tok-123' === LNKup_Eventbrite_Connection::decrypt( $stored ), 'tokens are encrypted at rest' );
$auth_seen = '';
$routes[ LNKup_Eventbrite_Connection::API . '/users/me/organizations/' ] = array( 'type' => 'application/json', 'body' => static function ( $u, $a ) use ( &$auth_seen ) {
	$auth_seen = $a['headers']['Authorization'] ?? '';
	return wp_json_encode( array( 'organizations' => array( array( 'id' => '77', 'name' => 'QC Events' ) ) ) );
} );
$routes[ LNKup_Eventbrite_Connection::API . '/organizations/77/events/' ] = array( 'type' => 'application/json', 'body' => wp_json_encode( array(
	'events'     => array(
		array( 'id' => '9001', 'name' => array( 'text' => 'Account Fest' ), 'url' => 'https://www.eventbrite.com/e/account-fest-tickets-9001', 'status' => 'live', 'start' => array( 'local' => $date . 'T12:00:00', 'timezone' => 'America/Chicago' ), 'end' => array( 'local' => $date . 'T20:00:00' ), 'venue' => array( 'name' => 'Fair Park', 'address' => array( 'city' => 'Dallas', 'region' => 'TX', 'postal_code' => '75210' ) ), 'ticket_classes' => array( array( 'cost' => array( 'value' => 2500 ) ) ), 'capacity' => 800 ),
		array( 'id' => '9002', 'name' => array( 'text' => 'Online Workshop' ), 'url' => 'https://www.eventbrite.com/e/online-9002', 'status' => 'live', 'online_event' => true, 'start' => array( 'local' => $date . 'T09:00:00', 'timezone' => 'America/Chicago' ), 'end' => array( 'local' => $date . 'T10:00:00' ) ),
	),
	'pagination' => array( 'has_more_items' => false ),
) ) );
$list = lnkup_bulk_rest( 'GET', '/eventbrite/events', array( 'refresh' => 1 ), $org );
lnkup_t_same( array( 200, 2, 'Bearer tok-123' ), array( $list['status'], count( $list['data']['events'] ?? array() ), $auth_seen ), 'account events are listed with the stored token' );
$first = $list['data']['events'][0] ?? array();
lnkup_t_same( array( 'Online Workshop', 'America/Chicago' ), array( $first['name'] ?? '', $first['timezone'] ?? '' ), 'events are sorted by start and keep their Eventbrite time zone' );
lnkup_t_assert( ! preg_match( '/capacity|cost|2500|800/', (string) wp_json_encode( $list['data']['events'] ) ), 'ticket prices and attendee capacity are not carried over' );
$ej = lnkup_bulk_rest( 'POST', '/bulk/jobs', array( 'method' => 'eventbrite', 'ids' => array( '9001', '9002', '1234' ) ), $org );
lnkup_bulk_cleanup_job( (int) ( $ej['data']['id'] ?? 0 ) );
$items = array_column( $ej['data']['items'] ?? array(), null, 'label' );
lnkup_t_same( array( 'ready', 'account', 'Dallas' ), array( $items['Account Fest']['status'] ?? '', $items['Account Fest']['preview']['provenance']['name'] ?? '', $items['Account Fest']['preview']['fields']['city'] ?? '' ), 'account events become reviewable items with account provenance' );
lnkup_t_same( 1, $ej['data']['counts']['failed'] ?? 0, 'IDs not in the connected account are refused (never trusted from the client)' );
lnkup_t_same( 1, count( $items['Online Workshop']['preview']['warnings'] ?? array() ), 'online Eventbrite events are flagged' );
$ea = lnkup_bulk_rest( 'POST', '/bulk/jobs/' . $ej['data']['id'] . '/apply', array( 'decisions' => array( $items['Account Fest']['id'] => 'draft', $items['Online Workshop']['id'] => 'skip' ) ), $org );
$ed = LNKup_Event_Builder_Drafts::get( (int) ( $ea['data']['created'][0] ?? 0 ) );
lnkup_t_same( array( 'account', 'America/Chicago', 'eventbrite', '9001' ), array( $ed['state']['sources'][0]['mode'] ?? '', $ed['state']['event']['timezone'] ?? '', $ed['state']['import']['provider'] ?? '', $ed['state']['import']['external_event_id'] ?? '' ), 'drafts from the account keep provider, event ID and time zone' );
$routes[ LNKup_Eventbrite_Connection::API . '/users/me/organizations/' ] = array( 'code' => 401, 'type' => 'application/json', 'body' => '{}' );
$exp = lnkup_bulk_rest( 'GET', '/eventbrite/events', array( 'refresh' => 1 ), $org );
lnkup_t_same( array( 409, 'expired' ), array( $exp['status'], LNKup_Eventbrite_Connection::status( $org )['status'] ), 'revoked tokens mark the connection expired with a reconnect message' );
lnkup_bulk_rest( 'POST', '/eventbrite/disconnect', array(), $org );
lnkup_t_same( 'not_connected', LNKup_Eventbrite_Connection::status( $org )['status'], 'disconnect removes the stored token' );

lnkup_m_finish();
