<?php
/**
 * Event Creation OS 3.0 — import foundation: SSRF-safe fetching, provider registry, canonical
 * URLs, structured-data parsing, external sources and ownership conflicts.
 * No real network requests are made (HTTP is mocked); all fixtures are removed.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/event-import-test.php
 */

require_once __DIR__ . '/_marketing_helpers.php';
global $wpdb;

LNKup_Event_OS_Flags::override( 'event_builder_v3', true );
LNKup_Event_Import_Tables::install();

$fake_dns = array(
	'ok.lnkup-test.example'     => array( '93.184.216.34' ),
	'v6.lnkup-test.example'     => array( '2606:4700::1111' ),
	'rebind.lnkup-test.example' => array( '93.184.216.34', '10.0.0.7' ),
	'inner.lnkup-test.example'  => array( '192.168.1.20' ),
	'meta.lnkup-test.example'   => array( '169.254.169.254' ),
	'v6bad.lnkup-test.example'  => array( '::ffff:127.0.0.1' ),
	'www.eventbrite.com'        => array( '93.184.216.34' ),
	'posh.vip'                  => array( '93.184.216.34' ),
	'img.lnkup-test.example'    => array( '93.184.216.34' ),
);
add_filter( 'lnkup_safe_fetch_resolve', static fn( $ips, $host ) => $fake_dns[ $host ] ?? ( preg_match( '/\.lnkup-test\.example$/', $host ) ? array() : $ips ), 10, 2 );
add_filter( 'lnkup_safe_fetch_rate', static fn() => 1000 );

$http_log = array();
$routes   = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$http_log, &$routes ) {
		$http_log[] = array( 'url' => $url, 'args' => $args );
		foreach ( $routes as $prefix => $response ) {
			if ( 0 === strpos( $url, $prefix ) ) {
				return array( 'headers' => $response['headers'], 'body' => $response['body'] ?? '', 'response' => array( 'code' => $response['code'], 'message' => '' ), 'cookies' => array(), 'filename' => null );
			}
		}
		return new WP_Error( 'unmocked', 'Unmocked request: ' . $url );
	},
	10,
	3
);

$org   = lnkup_t_user( 'lnkup_organizer' );
$other = lnkup_t_user( 'lnkup_organizer' );
$date  = gmdate( 'Y-m-d', strtotime( '+40 days' ) );

echo "SSRF: blocked addresses\n";
$blocked = array(
	'http://127.0.0.1/', 'http://127.1/', 'http://localhost/', 'http://LOCALHOST./admin', 'http://[::1]/', 'http://0.0.0.0/', 'http://[::]/',
	'http://169.254.169.254/latest/meta-data/', 'http://metadata.google.internal/computeMetadata/v1/', 'http://10.0.0.1/', 'http://172.16.5.4/',
	'http://192.168.0.1/', 'http://100.64.0.1/', 'http://198.18.0.1/', 'http://224.0.0.1/', 'http://[fe80::1]/', 'http://[fc00::1]/', 'http://[fd12:3456::1]/',
	'http://[::ffff:127.0.0.1]/', 'http://[::ffff:7f00:1]/', 'http://[::ffff:a9fe:a9fe]/', 'http://[64:ff9b::a00:1]/', 'http://2130706433/', 'http://0x7f.0.0.1/', 'http://017700000001/',
	'http://printer.local/', 'http://db.internal/', 'http://inner.lnkup-test.example/', 'http://meta.lnkup-test.example/', 'http://v6bad.lnkup-test.example/',
	'http://rebind.lnkup-test.example/', 'ftp://ok.lnkup-test.example/', 'file:///etc/passwd', 'gopher://ok.lnkup-test.example/', 'javascript:alert(1)',
	'http://user:pass@ok.lnkup-test.example/', 'http://ok.lnkup-test.example:8080/', 'https://ok.lnkup-test.example:22/', 'http://nxdomain.lnkup-test.example/', '//ok.lnkup-test.example/', 'not a url',
);
$leaks = array();
foreach ( $blocked as $u ) {
	if ( ! is_wp_error( LNKup_Safe_Fetch::validate( $u ) ) ) {
		$leaks[] = $u;
	}
}
lnkup_t_same( array(), $leaks, 'every internal, reserved, rebinding, non-http, credentialed or non-standard-port URL is rejected (' . count( $blocked ) . ' cases)' );
lnkup_t_assert( ! is_wp_error( LNKup_Safe_Fetch::validate( 'https://ok.lnkup-test.example/e/1' ) ), 'public https host is allowed' );
lnkup_t_assert( ! is_wp_error( LNKup_Safe_Fetch::validate( 'http://v6.lnkup-test.example/' ) ), 'public IPv6-only host is allowed' );
lnkup_t_same( array( true, true, false, false, false ), array( LNKup_Safe_Fetch::is_public_ip( '8.8.8.8' ), LNKup_Safe_Fetch::is_public_ip( '2606:4700::1111' ), LNKup_Safe_Fetch::is_public_ip( '::ffff:10.1.2.3' ), LNKup_Safe_Fetch::is_public_ip( '100.127.255.255' ), LNKup_Safe_Fetch::is_public_ip( '203.0.113.9' ) ), 'IP classification (public, IPv6, mapped private, CGNAT, documentation range)' );

echo "SSRF: redirects, limits and content types\n";
$routes = array(
	'https://ok.lnkup-test.example/to-internal' => array( 'code' => 302, 'headers' => array( 'location' => 'http://127.0.0.1:80/admin' ) ),
	'https://ok.lnkup-test.example/to-rebind'   => array( 'code' => 301, 'headers' => array( 'location' => 'https://rebind.lnkup-test.example/x' ) ),
	'https://ok.lnkup-test.example/loop'        => array( 'code' => 302, 'headers' => array( 'location' => '/loop' ) ),
	'https://ok.lnkup-test.example/binary'      => array( 'code' => 200, 'headers' => array( 'content-type' => 'application/octet-stream' ), 'body' => 'x' ),
	'https://ok.lnkup-test.example/gone'        => array( 'code' => 404, 'headers' => array( 'content-type' => 'text/html' ) ),
	'https://ok.lnkup-test.example/hop'         => array( 'code' => 302, 'headers' => array( 'location' => '/final' ) ),
	'https://ok.lnkup-test.example/final'       => array( 'code' => 200, 'headers' => array( 'content-type' => 'text/html; charset=utf-8' ), 'body' => '<html><title>Final</title></html>' ),
);
$http_log = array();
$r        = LNKup_Safe_Fetch::get( 'https://ok.lnkup-test.example/to-internal' );
lnkup_t_same( 'lnkup_fetch_private', is_wp_error( $r ) ? $r->get_error_code() : 'ok', 'redirect to loopback is refused' );
lnkup_t_same( 1, count( $http_log ), 'the internal redirect target is never requested' );
lnkup_t_same( array( 0, LNKup_Safe_Fetch::MAX_BYTES, LNKup_Safe_Fetch::TIMEOUT ), array( $http_log[0]['args']['redirection'] ?? -1, $http_log[0]['args']['limit_response_size'] ?? -1, $http_log[0]['args']['timeout'] ?? -1 ), 'automatic redirects are off; size and timeout are bounded' );
$r = LNKup_Safe_Fetch::get( 'https://ok.lnkup-test.example/to-rebind' );
lnkup_t_same( 'lnkup_fetch_private', is_wp_error( $r ) ? $r->get_error_code() : 'ok', 'redirect to a host that resolves to a private address is refused' );
$r = LNKup_Safe_Fetch::get( 'https://ok.lnkup-test.example/loop' );
lnkup_t_same( 'lnkup_fetch_redirects', is_wp_error( $r ) ? $r->get_error_code() : 'ok', 'redirect loops stop after ' . LNKup_Safe_Fetch::MAX_REDIRECTS . ' hops' );
$r = LNKup_Safe_Fetch::get( 'https://ok.lnkup-test.example/binary' );
lnkup_t_same( 'lnkup_fetch_type', is_wp_error( $r ) ? $r->get_error_code() : 'ok', 'non-HTML responses are refused' );
$r = LNKup_Safe_Fetch::get( 'https://ok.lnkup-test.example/gone' );
lnkup_t_same( 'lnkup_fetch_status', is_wp_error( $r ) ? $r->get_error_code() : 'ok', 'missing pages return a friendly error' );
$r = LNKup_Safe_Fetch::get( 'https://ok.lnkup-test.example/hop' );
lnkup_t_same( 'https://ok.lnkup-test.example/final', is_wp_error( $r ) ? $r->get_error_message() : $r['url'], 'safe relative redirects are followed and re-validated' );
lnkup_t_same( 'lnkup_fetch_private', ( static function () {
	$e = LNKup_Page_Extractor::fetch_html( 'http://10.1.1.1/' );
	return is_wp_error( $e ) ? $e->get_error_code() : 'ok';
} )(), 'the existing page extractor now refuses private addresses' );
remove_all_filters( 'lnkup_safe_fetch_rate' );
add_filter( 'lnkup_safe_fetch_rate', static fn() => 2 );
wp_set_current_user( $org );
delete_transient( 'lnkup_safe_fetch_' . $org );
LNKup_Safe_Fetch::get( 'https://ok.lnkup-test.example/final' );
LNKup_Safe_Fetch::get( 'https://ok.lnkup-test.example/final' );
$r = LNKup_Safe_Fetch::get( 'https://ok.lnkup-test.example/final' );
lnkup_t_same( 'lnkup_fetch_rate', is_wp_error( $r ) ? $r->get_error_code() : 'ok', 'per-user fetch rate limit applies' );
delete_transient( 'lnkup_safe_fetch_' . $org );
wp_set_current_user( 0 );
remove_all_filters( 'lnkup_safe_fetch_rate' );
add_filter( 'lnkup_safe_fetch_rate', static fn() => 1000 );

echo "Provider registry\n";
$d = static fn( string $u ) => LNKup_Import_Provider_Registry::detect( $u )['key'];
lnkup_t_same( array( 'eventbrite', 'eventbrite', 'posh', 'luma', 'website', 'website', 'facebook' ), array( $d( 'https://www.eventbrite.com/e/fest-tickets-123456789' ), $d( 'https://charlotte.eventbrite.com/e/x-1234567' ), $d( 'https://posh.vip/e/summer-fest' ), $d( 'https://lu.ma/abcd1234' ), $d( 'https://evil-eventbrite.com/e/1' ), $d( 'https://eventbrite.com.attacker.example/e/1' ), $d( 'https://www.facebook.com/events/1/' ) ), 'providers are detected by exact domain, never by look-alike hosts' );
lnkup_t_same( array( 'A', 'B', 'C' ), array( LNKup_Import_Provider_Registry::all()['eventbrite']['level'], LNKup_Import_Provider_Registry::all()['posh']['level'], LNKup_Import_Provider_Registry::all()['eventeny']['level'] ), 'capability levels follow the documented provider matrix' );
lnkup_t_same( false, LNKup_Import_Provider_Registry::all()['facebook']['url_import'], 'sign-in walled providers are link-only' );
LNKup_Import_Provider_Registry::set_disabled( array( 'posh' ) );
$r = LNKup_Event_Import_Service::preview( 'https://posh.vip/e/summer-fest', $org );
lnkup_t_same( 'lnkup_import_disabled', is_wp_error( $r ) ? $r->get_error_code() : 'ok', 'admins can turn a provider off' );
LNKup_Import_Provider_Registry::set_disabled( array() );
lnkup_t_assert( ! preg_match( '/secret|token|client_id/i', (string) wp_json_encode( LNKup_Import_Provider_Registry::public_list() ) ), 'public provider list carries no credentials' );

echo "Canonical URLs\n";
lnkup_t_same( 'https://eventbrite.com/e/fest-tickets-123456789', LNKup_Event_Sources::canonical_url( 'http://www.Eventbrite.com/e/fest-tickets-123456789/?aff=ebdssbdestsearch&utm_source=ig&fbclid=abc#tickets' ), 'tracking, affiliate, fragment, www and trailing slash are removed' );
lnkup_t_same( 'https://posh.vip/e/fest?code=VIP', LNKup_Event_Sources::canonical_url( 'https://posh.vip/e/fest?utm_medium=x&code=VIP' ), 'meaningful query parameters are kept' );
lnkup_t_same( array( '123456789', 'summer-fest' ), array( LNKup_Event_Sources::external_id( 'https://www.eventbrite.com/e/fest-tickets-123456789', 'eventbrite' ), LNKup_Event_Sources::external_id( 'https://posh.vip/e/summer-fest', 'posh' ) ), 'public provider event IDs are read from URLs' );

echo "Structured data parsing\n";
$jsonld = array(
	'@context' => 'https://schema.org',
	'@graph'   => array(
		array( '@type' => 'Organization', 'name' => 'Queen City Events' ),
		array(
			'@type'                    => 'Festival',
			'name'                     => 'Queen City Night Market Tickets | Eventbrite',
			'startDate'                => $date . 'T17:00:00-05:00',
			'endDate'                  => $date . 'T23:00:00-05:00',
			'description'              => '<p>Food trucks, makers &amp; live music.</p><script>alert(1)</script>',
			'image'                    => array( 'https://img.lnkup-test.example/flyer.jpg' ),
			'eventStatus'              => 'https://schema.org/EventScheduled',
			'maximumAttendeeCapacity'  => 5000,
			'offers'                   => array( '@type' => 'Offer', 'price' => '25.00', 'priceCurrency' => 'USD' ),
			'location'                 => array( '@type' => 'Place', 'name' => 'Camp North End', 'address' => array( 'streetAddress' => '300 Camp Rd', 'addressLocality' => 'Charlotte', 'addressRegion' => 'North Carolina', 'postalCode' => '28206', 'addressCountry' => 'US' ) ),
			'organizer'                => array( '@type' => 'Organization', 'name' => 'Queen City Events' ),
		),
	),
);
$html   = '<html><head><title>Ignore me</title><meta property="og:image" content="https://img.lnkup-test.example/og.jpg"><script type="application/ld+json">' . wp_json_encode( $jsonld ) . '</script></head><body>Doors 5pm</body></html>';
$norm   = LNKup_Event_Import_Service::normalize( LNKup_Event_Import_Service::parse_html( $html, 'https://www.eventbrite.com/e/queen-city-night-market-tickets-987654321' ), 'https://www.eventbrite.com/e/queen-city-night-market-tickets-987654321', 'eventbrite' );
$f      = $norm['fields'];
lnkup_t_same( 'complete', $norm['status'], 'full structured listing imports as complete' );
lnkup_t_same( array( 'Queen City Night Market', $date, '17:00', '23:00', 'Camp North End', 'Charlotte', 'NC', '28206' ), array( $f['name'] ?? '', $f['date'] ?? '', $f['start_time'] ?? '', $f['end_time'] ?? '', $f['venue_name'] ?? '', $f['city'] ?? '', $f['state'] ?? '', $f['zip'] ?? '' ), 'name is cleaned; local date/time, venue, city, state code and ZIP are read' );
lnkup_t_same( 'Food trucks, makers & live music.', $f['description'] ?? '', 'description is plain text with scripts removed' );
lnkup_t_same( 'https://img.lnkup-test.example/flyer.jpg', $f['image_url'] ?? '', 'structured image wins over Open Graph' );
lnkup_t_assert( ! preg_match( '/price|capacity|5000|25\.00|fee|spots/i', (string) wp_json_encode( $f ) ), 'attendee ticket prices and attendee capacity are never imported' );
lnkup_t_same( 'structured', $norm['provenance']['name'] ?? '', 'fields carry provenance' );
lnkup_t_same( '987654321', $norm['external_id'], 'provider event ID recorded' );

$og   = LNKup_Event_Import_Service::normalize( LNKup_Event_Import_Service::parse_html( '<html><head><meta property="og:title" content="Makers Bazaar"><meta content="A bazaar." property="og:description"></head><body>Saturday, December 5, 2026 at 10am</body></html>', 'https://ok.lnkup-test.example/bazaar' ), 'https://ok.lnkup-test.example/bazaar', 'website' );
lnkup_t_same( array( 'partial', 'Makers Bazaar', false ), array( $og['status'], $og['fields']['name'] ?? '', isset( $og['fields']['date'] ) ), 'Open Graph only → partial; dates are never guessed from page text' );
lnkup_t_assert( in_array( 'date', wp_list_pluck( $og['missing'], 'field' ), true ), 'missing fields are reported for the organizer to fill' );
$none = LNKup_Event_Import_Service::normalize( LNKup_Event_Import_Service::parse_html( '<html><body>hello</body></html>', 'https://ok.lnkup-test.example/x' ), 'https://ok.lnkup-test.example/x', 'website' );
lnkup_t_same( array( 'nothing', array() ), array( $none['status'], $none['fields'] ), 'pages without event data import nothing (no fabrication)' );

$online = LNKup_Event_Import_Service::normalize( LNKup_Event_Import_Service::parse_html( '<script type="application/ld+json">' . wp_json_encode( array( '@type' => 'Event', 'name' => 'Webinar', 'startDate' => $date, 'eventStatus' => 'EventPostponed', 'location' => array( '@type' => 'VirtualLocation', 'url' => 'https://zoom.example' ) ) ) . '</script>', 'https://ok.lnkup-test.example/w' ), 'https://ok.lnkup-test.example/w', 'website' );
lnkup_t_same( 2, count( $online['warnings'] ), 'online and postponed listings are flagged' );
lnkup_t_assert( ! isset( $online['fields']['city'] ), 'virtual locations are not turned into a city' );

$series = array();
foreach ( array( '+70 days', '+42 days', '+56 days' ) as $offset ) {
	$series[] = array( '@type' => 'Event', 'name' => 'Sunday Market', 'startDate' => gmdate( 'Y-m-d', strtotime( $offset ) ) . 'T10:00', 'location' => array( '@type' => 'Place', 'address' => array( 'addressLocality' => 'Raleigh', 'addressRegion' => 'NC' ) ) );
}
$multi = LNKup_Event_Import_Service::normalize( LNKup_Event_Import_Service::parse_html( '<script type="application/ld+json">' . wp_json_encode( $series ) . '</script>', 'https://ok.lnkup-test.example/s' ), 'https://ok.lnkup-test.example/s', 'website' );
lnkup_t_same( array( gmdate( 'Y-m-d', strtotime( '+42 days' ) ), gmdate( 'Y-m-d', strtotime( '+56 days' ) ), gmdate( 'Y-m-d', strtotime( '+70 days' ) ) ), wp_list_pluck( $multi['occurrences'], 'date' ), 'multiple dates are offered as sorted occurrences' );

echo "Preview → draft\n";
$routes['https://www.eventbrite.com/e/queen-city-night-market-tickets-987654321'] = array( 'code' => 200, 'headers' => array( 'content-type' => 'text/html' ), 'body' => $html );
delete_transient( 'lnkup_import_' . md5( LNKup_Event_Sources::canonical_url( 'https://www.eventbrite.com/e/queen-city-night-market-tickets-987654321' ) ) );
$url     = 'https://www.eventbrite.com/e/queen-city-night-market-tickets-987654321?aff=share';
$preview = LNKup_Event_Import_Service::preview( $url, $org );
lnkup_t_same( array( 'complete', 'eventbrite', 'none' ), array( $preview['status'] ?? '', $preview['provider']['key'] ?? '', $preview['relation']['state'] ?? '' ), 'preview fetches safely, detects the provider and finds no conflict' );
$patch = LNKup_Event_Import_Service::to_state_patch( $preview, array( 'name', 'date', 'start_time', 'end_time', 'venue_name', 'city', 'state' ) );
$state = LNKup_Event_Builder_Schema::merge( LNKup_Event_Builder_Schema::normalize( array() ), $patch );
lnkup_t_same( array( 'Queen City Night Market', 'Charlotte', 'NC', '', 'import', true ), array( $state['event']['name'], $state['event']['city'], $state['event']['state'], $state['event']['description'], $state['sources'][0]['mode'] ?? '', $state['sources'][0]['primary'] ?? false ), 'only kept fields are applied; the listing becomes the primary import source' );
lnkup_t_same( 'imported', $state['provenance']['event.name'] ?? '', 'draft provenance marks imported fields' );
lnkup_t_same( false, $state['event']['location_confirmed'], 'imported location still needs organizer confirmation' );
lnkup_t_same( array(), $state['options'], 'no booth options are invented from ticket data' );
$report = LNKup_Event_Builder_Validator::report( $state, $org );
$attest = array_values( array_filter( $report['checks'], static fn( $c ) => 'attest' === $c['key'] ) );
lnkup_t_same( false, $attest[0]['ok'] ?? null, 'publishing an import requires the ownership attestation' );

echo "Sources and ownership\n";
$ev = lnkup_m_event( array( 'organizer_user_id' => $org, 'event_date' => $date ), false, 'Queen City Night Market' );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $ev ) {
	global $wpdb;
	$wpdb->delete( LNKup_Event_Import_Tables::sources(), array( 'event_id' => $ev['event'] ) );
};
LNKup_Event_Sources::attach_from_state( $ev['event'], 0, $state, $org );
$rows = LNKup_Event_Sources::for_event( $ev['event'] );
lnkup_t_same( array( 1, true, 'import', 'eventbrite', '987654321', 'attested' ), array( count( $rows ), $rows[0]['is_primary'] ?? false, $rows[0]['mode'] ?? '', $rows[0]['provider'] ?? '', $rows[0]['external_event_id'] ?? '', $rows[0]['ownership_state'] ?? '' ), 'published import stores one primary attested source' );
LNKup_Event_Sources::attach_from_state( $ev['event'], 0, $state, $org );
lnkup_t_same( 1, count( LNKup_Event_Sources::for_event( $ev['event'] ) ), 're-attaching is idempotent' );
lnkup_t_same( 'own', LNKup_Event_Sources::relation( 'https://eventbrite.com/e/queen-city-night-market-tickets-987654321?utm_source=x', $org )['state'], 'same listing (tracking variant) is recognized as already mine' );
lnkup_t_same( 'other', LNKup_Event_Sources::relation( $url, $other )['state'], 'another organizer importing the same listing is a conflict' );
lnkup_t_same( 'other', LNKup_Event_Sources::relation( 'https://charlotte.eventbrite.com/e/renamed-987654321', $other )['state'], 'same provider event ID under a different URL is a conflict' );
$other_state = $state;
$other_state['import']['conflict'] = '';
$rep  = LNKup_Event_Builder_Validator::report( $other_state, $other );
$own  = array_values( array_filter( $rep['checks'], static fn( $c ) => 'ownership' === $c['key'] ) );
lnkup_t_same( array( false, false ), array( $own[0]['ok'] ?? true, $rep['publishable'] ), 'server recomputes the ownership conflict even when the client clears it' );
$mine = array_values( array_filter( LNKup_Event_Builder_Validator::report( $state, $org, $ev['event'] )['checks'], static fn( $c ) => 'ownership' === $c['key'] ) );
lnkup_t_same( array(), $mine, 'editing my own event does not conflict with its own source' );
$ev2 = lnkup_m_event( array( 'organizer_user_id' => $other, 'event_date' => $date ), false );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $ev2 ) {
	global $wpdb;
	$wpdb->delete( LNKup_Event_Import_Tables::sources(), array( 'event_id' => $ev2['event'] ) );
};
LNKup_Event_Sources::attach_from_state( $ev2['event'], 0, $state, $other );
lnkup_t_same( array(), LNKup_Event_Sources::for_event( $ev2['event'] ), 'a conflicting listing is never attached to another organizer\'s event' );
$edit = $state;
$edit['sources'][] = array( 'url' => 'https://posh.vip/e/qc-night-market', 'provider' => 'posh', 'primary' => false, 'mode' => 'link', 'label' => '' );
LNKup_Event_Sources::attach_from_state( $ev['event'], 0, LNKup_Event_Builder_Schema::normalize( $edit ), $org );
lnkup_t_same( array( 'eventbrite', 'posh' ), wp_list_pluck( LNKup_Event_Sources::for_event( $ev['event'] ), 'provider' ), 'multiple official listings per event; one primary' );
LNKup_Event_Sources::attach_from_state( $ev['event'], 0, LNKup_Event_Builder_Schema::normalize( array_merge( $state, array( 'sources' => array() ) ) ), $org );
lnkup_t_same( array(), LNKup_Event_Sources::for_event( $ev['event'] ), 'removing sources in an edit removes them' );
wp_trash_post( $ev['event'] );
lnkup_t_same( 'none', LNKup_Event_Sources::relation( $url, $other )['state'], 'trashed events do not block others' );

echo "External listings\n";
if ( class_exists( 'LNKup_External_Events' ) ) {
	$ext_url = 'https://ok.lnkup-test.example/unclaimed-fest-' . wp_generate_password( 6, false, false );
	$ext     = LNKup_External_Events::create( array( 'source_url' => $ext_url, 'source_platform' => 'other', 'title' => 'Unclaimed Fest', 'workflow_status' => 'draft' ) );
	if ( is_wp_error( $ext ) ) {
		lnkup_t_skip( 'external listing fixture: ' . $ext->get_error_message() );
	} else {
		$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $ext ) {
			global $wpdb;
			$wpdb->delete( LNKup_External_Events_Tables::events_table(), array( 'id' => (int) $ext ) );
		};
		$rel = LNKup_Event_Sources::relation( $ext_url, $org );
		lnkup_t_same( 'unclaimed_listing', $rel['state'], 'an unclaimed LNKup listing is surfaced, not auto-claimed' );
		lnkup_t_same( 'unclaimed', LNKup_External_Events::get_row( (int) $ext )['claim_status'] ?? '', 'importing a public link never claims the listing' );
		$s2 = LNKup_Event_Builder_Schema::merge( LNKup_Event_Builder_Schema::normalize( array() ), array( 'sources' => array( array( 'url' => $ext_url ) ) ) );
		lnkup_t_same( '', LNKup_Event_Sources::conflict_for_state( $s2, $org ), 'unclaimed listings do not block publishing' );
	}
} else {
	lnkup_t_skip( 'external events module not loaded' );
}

echo "REST import\n";
/**
 * REST call as a user against lnkup/v1/organizer/import.
 *
 * @return array{status:int,data:mixed}
 */
function lnkup_imp_rest( string $method, string $route, array $body, int $user ): array {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, '/lnkup/v1/organizer/import' . $route );
	if ( 'GET' !== $method ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	wp_set_current_user( 0 );
	$data = $res->get_data();
	if ( is_array( $data ) && isset( $data['draft']['id'] ) ) {
		update_post_meta( (int) $data['draft']['id'], '_lnkup_test_run', '1' );
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $data['draft']['id'] );
	}
	return array( 'status' => $res->get_status(), 'data' => $data );
}
$vendor = lnkup_t_user( 'lnkup_vendor' );
LNKup_Event_OS_Flags::override( 'event_import_v3', false );
lnkup_t_assert( in_array( lnkup_imp_rest( 'POST', '/preview', array( 'url' => $url ), $org )['status'], array( 401, 403 ), true ), 'import routes are off without event_import_v3' );
LNKup_Event_OS_Flags::override( 'event_import_v3', true );
$GLOBALS['lnkup_m_extra_cleanup'][] = static fn() => LNKup_Event_OS_Flags::override( 'event_import_v3', null );
lnkup_t_assert( in_array( lnkup_imp_rest( 'POST', '/preview', array( 'url' => $url ), $vendor )['status'], array( 401, 403 ), true ), 'vendors cannot import' );
lnkup_t_same( 400, lnkup_imp_rest( 'POST', '/preview', array( 'url' => 'http://169.254.169.254/latest/meta-data/' ), $org )['status'], 'metadata endpoint is refused through REST' );

$fresh = 'https://www.eventbrite.com/e/makers-night-tickets-555666777';
$routes[ $fresh ] = array( 'code' => 200, 'headers' => array( 'content-type' => 'text/html' ), 'body' => str_replace( array( 'queen-city-night-market-tickets-987654321', 'Queen City Night Market' ), array( 'makers-night-tickets-555666777', 'Makers Night' ), '<script type="application/ld+json">' . wp_json_encode( array_merge( array( array( '@type' => 'Event', 'name' => 'Makers Night', 'startDate' => $date . 'T18:00', 'image' => 'https://img.lnkup-test.example/m.png', 'location' => array( '@type' => 'Place', 'name' => 'The Hall', 'address' => array( 'addressLocality' => 'Raleigh', 'addressRegion' => 'NC' ) ) ) ), $series ) ) . '</script>' ) );
/**
 * Builder draft fixture for the user (removed at the end).
 *
 * @param array<string,mixed> $state Initial state.
 * @return array<string,mixed>
 */
function lnkup_eb_new_draft( int $user, array $state ): array {
	$d = LNKup_Event_Builder_Drafts::create( $user, $state );
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $d['id'] );
	return $d;
}
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
$routes['https://img.lnkup-test.example/m.png'] = array( 'code' => 200, 'headers' => array( 'content-type' => 'image/png' ), 'body' => $png );
$pv = lnkup_imp_rest( 'POST', '/preview', array( 'url' => $fresh ), $org );
lnkup_t_same( 200, $pv['status'], 'organizer preview succeeds' );
lnkup_t_assert( ! isset( $pv['data']['body'] ) && false === strpos( (string) wp_json_encode( $pv['data'] ), '<script' ), 'preview never returns raw page HTML' );
lnkup_t_same( 4, count( $pv['data']['occurrences'] ?? array() ), 'listing dates are offered' );
$pick = 1;
$want = $pv['data']['occurrences'][ $pick ] ?? array();
$dr   = lnkup_imp_rest( 'POST', '/draft', array( 'url' => $fresh, 'keep' => array( 'name', 'date', 'start_time', 'city', 'state', 'image_url' ), 'use_image' => true, 'occurrence' => $pick ), $org );
$ds   = $dr['data']['draft']['state'] ?? array();
lnkup_t_same( 201, $dr['status'], 'draft created from the import' );
lnkup_t_same( array( 'import', $want['date'] ?? 'x', '', 'Raleigh' ), array( $ds['method'] ?? '', $ds['event']['date'] ?? '', $ds['event']['venue_name'] ?? '', $ds['event']['city'] ?? '' ), 'chosen date is used; unticked fields (venue) are left empty' );
$att = (int) ( $ds['event']['featured_image_id'] ?? 0 );
lnkup_t_assert( $att > 0 && (int) get_post_meta( $att, '_lnkup_uploaded_by', true ) === $org, 'imported image is copied into the organizer\'s own media' );
if ( $att ) {
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', $att );
}
lnkup_t_same( 404, lnkup_imp_rest( 'POST', '/drafts/' . (int) ( $dr['data']['draft']['id'] ?? 0 ), array( 'url' => $fresh ), $other )['status'], 'another organizer cannot import into this draft' );

$bd = lnkup_eb_new_draft( $org, array( 'event' => array( 'name' => 'My Own Name', 'city' => '' ) ) );
$in = lnkup_imp_rest( 'POST', '/drafts/' . $bd['id'], array( 'url' => $fresh, 'revision' => $bd['revision'] ), $org );
lnkup_t_same( array( 200, 'My Own Name', 'Raleigh' ), array( $in['status'], $in['data']['draft']['state']['event']['name'] ?? '', $in['data']['draft']['state']['event']['city'] ?? '' ), 'importing into a draft fills empty fields and keeps organizer edits' );
lnkup_t_assert( in_array( 'name', $in['data']['skipped'] ?? array(), true ), 'kept organizer values are reported' );
$in2 = lnkup_imp_rest( 'POST', '/drafts/' . $bd['id'], array( 'url' => $fresh, 'revision' => $in['data']['draft']['revision'] ?? 0, 'replace' => array( 'name' ) ), $org );
lnkup_t_same( array( 'Makers Night', 1 ), array( $in2['data']['draft']['state']['event']['name'] ?? '', count( $in2['data']['draft']['state']['sources'] ?? array() ) ), 'explicit replace overwrites; the listing is not added twice' );
lnkup_t_same( 409, lnkup_imp_rest( 'POST', '/drafts/' . $bd['id'], array( 'url' => $fresh, 'revision' => 1 ), $org )['status'], 'stale revision is rejected' );

$mine2 = lnkup_m_event( array( 'organizer_user_id' => $org, 'event_date' => $date ), false, 'Makers Night' );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $mine2 ) {
	global $wpdb;
	$wpdb->delete( LNKup_Event_Import_Tables::sources(), array( 'event_id' => $mine2['event'] ) );
};
$pv2 = lnkup_imp_rest( 'POST', '/preview', array( 'url' => $fresh ), $org );
lnkup_t_same( array( $mine2['event'] ), wp_list_pluck( $pv2['data']['duplicates'] ?? array(), 'event_id' ), 'probable duplicate of an existing event is detected' );
lnkup_t_same( 403, lnkup_imp_rest( 'POST', '/associate', array( 'url' => $fresh, 'event_id' => $mine2['event'] ), $other )['status'], 'cannot link a listing to someone else\'s event' );
$as = lnkup_imp_rest( 'POST', '/associate', array( 'url' => $fresh, 'event_id' => $mine2['event'] ), $org );
lnkup_t_same( array( 200, 1 ), array( $as['status'], count( $as['data']['sources'] ?? array() ) ), 'listing linked to the existing event (no new event)' );
lnkup_t_same( 'own', lnkup_imp_rest( 'POST', '/preview', array( 'url' => $fresh ), $org )['data']['relation']['state'] ?? '', 'after linking, previews point to the existing event' );
$opv = lnkup_imp_rest( 'POST', '/preview', array( 'url' => $fresh ), $other )['data'];
lnkup_t_same( array( 'other', 0 ), array( $opv['relation']['state'] ?? '', $opv['relation']['event_id'] ?? -1 ), 'other organizers see a conflict without the event ID' );
lnkup_t_same( 409, lnkup_imp_rest( 'POST', '/associate', array( 'url' => $fresh, 'event_id' => lnkup_m_event( array( 'organizer_user_id' => $other ), false )['event'] ), $other )['status'], 'a listing linked to another organizer cannot be associated' );

lnkup_m_finish();
