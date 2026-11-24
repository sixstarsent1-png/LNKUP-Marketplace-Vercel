<?php
/**
 * Event Creation OS 3.0 — server-side timings for the hot paths (autosave, report, preview,
 * recurrence preview, URL import with mocked HTTP, bulk job). Fixtures are removed.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/event-builder-perf.php
 */

require_once __DIR__ . '/_marketing_helpers.php';

foreach ( array( 'event_builder_v3', 'event_import_v3', 'event_import_bulk', 'portfolio_builder_v3' ) as $flag ) {
	LNKup_Event_OS_Flags::override( $flag, true );
}
add_filter( 'lnkup_safe_fetch_resolve', static fn( $ips, $host ) => preg_match( '/perf\.lnkup-test\.example$/', $host ) ? array( '93.184.216.34' ) : $ips, 10, 2 );
add_filter( 'lnkup_safe_fetch_rate', static fn() => 10000 );
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'perf.lnkup-test.example' ) ) {
			return $pre;
		}
		$ld = array( '@type' => 'Event', 'name' => 'Perf Fest', 'startDate' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ) . 'T10:00', 'location' => array( '@type' => 'Place', 'name' => 'Lot', 'address' => array( 'addressLocality' => 'Charlotte', 'addressRegion' => 'NC' ) ) );
		return array( 'headers' => array( 'content-type' => 'text/html' ), 'body' => '<script type="application/ld+json">' . wp_json_encode( $ld ) . '</script>', 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);

$org = lnkup_t_user( 'lnkup_organizer' );
wp_set_current_user( $org );

/**
 * Time a callable (ms, median of $runs).
 */
function lnkup_perf( string $label, callable $fn, int $runs = 5 ): void {
	$times = array();
	for ( $i = 0; $i < $runs; $i++ ) {
		$t = microtime( true );
		$fn( $i );
		$times[] = ( microtime( true ) - $t ) * 1000;
	}
	sort( $times );
	printf( "  %-46s median %6.1f ms  max %6.1f ms  (n=%d)\n", $label, $times[ (int) floor( count( $times ) / 2 ) ], max( $times ), $runs );
}

/**
 * REST call as the current user.
 *
 * @return mixed
 */
function lnkup_perf_rest( string $method, string $route, array $body = array() ) {
	$req = new WP_REST_Request( $method, '/lnkup/v1' . $route );
	if ( 'GET' === $method ) {
		$req->set_query_params( $body );
	} else {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
	}
	return rest_do_request( $req )->get_data();
}

echo "Builder\n";
$draft = null;
lnkup_perf(
	'create draft',
	static function () use ( &$draft ) {
		$draft = lnkup_perf_rest( 'POST', '/organizer/builder/drafts', array( 'method' => 'single' ) );
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $draft['draft']['id'] );
	}
);
$id  = (int) $draft['draft']['id'];
$rev = (int) $draft['draft']['revision'];
$opts = array();
for ( $i = 0; $i < 12; $i++ ) {
	$opts[] = array( 'key' => 'o' . $i, 'name' => 'Option ' . $i, 'category' => 'Retail', 'fee' => 50 + $i, 'spots' => 10 );
}
lnkup_perf(
	'autosave (12 options, validation + fees)',
	static function ( $i ) use ( $id, &$rev, $opts ) {
		$out = lnkup_perf_rest( 'PATCH', '/organizer/builder/drafts/' . $id, array( 'revision' => $rev, 'patch' => array( 'event' => array( 'name' => 'Perf ' . $i, 'date' => gmdate( 'Y-m-d', strtotime( '+40 days' ) ), 'city' => 'Charlotte', 'state' => 'NC' ), 'options' => $opts ) ) );
		$rev = (int) $out['draft']['revision'];
	},
	10
);
lnkup_perf( 'preview (vendor view)', static fn() => lnkup_perf_rest( 'GET', '/organizer/builder/drafts/' . $id . '/preview', array( 'mode' => 'vendor' ) ) );
$state_opts = LNKup_Event_Builder_Schema::normalize( array( 'options' => $opts ) )['options'];
lnkup_perf( 'fee preview (12 options)', static fn() => LNKup_Event_Builder_Validator::fee_preview( $state_opts, $org ) );

echo "Recurrence\n";
$rd = lnkup_perf_rest( 'POST', '/organizer/builder/drafts', array( 'method' => 'recurring' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $rd['draft']['id'] );
$rp = lnkup_perf_rest( 'PATCH', '/organizer/builder/drafts/' . $rd['draft']['id'], array( 'revision' => $rd['draft']['revision'], 'patch' => array( 'event' => array( 'timezone' => 'America/New_York' ), 'portfolio' => array( 'recurrence' => array( 'frequency' => 'weekly', 'weekdays' => array( 'sat' ), 'start' => gmdate( 'Y-m-d', strtotime( 'next saturday' ) ), 'count' => 52 ) ) ) ) );
lnkup_perf( 'recurrence preview (52 weekly dates)', static fn() => lnkup_perf_rest( 'GET', '/organizer/builder/drafts/' . $rd['draft']['id'] . '/dates' ) );

echo "Import (HTTP mocked)\n";
lnkup_perf( 'URL import preview (fetch + parse + dedupe)', static fn( $i ) => lnkup_perf_rest( 'POST', '/organizer/import/preview', array( 'url' => 'https://perf.lnkup-test.example/e/' . $i . '-' . wp_generate_password( 4, false, false ) ) ) );
$urls = array();
for ( $i = 0; $i < 20; $i++ ) {
	$urls[] = 'https://perf.lnkup-test.example/b/' . $i . '-' . wp_generate_password( 4, false, false );
}
$job = null;
lnkup_perf(
	'bulk job create + first batch (20 links)',
	static function () use ( $urls, &$job ) {
		$job = lnkup_perf_rest( 'POST', '/organizer/import/bulk/jobs', array( 'method' => 'urls', 'urls' => $urls ) );
	},
	1
);
$t     = microtime( true );
$loops = 0;
while ( in_array( $job['status'] ?? '', array( 'queued', 'processing' ), true ) && $loops < 20 ) {
	delete_transient( 'lnkup_import_job_lock_' . (int) $job['id'] );
	$job = lnkup_perf_rest( 'GET', '/organizer/import/bulk/jobs/' . (int) $job['id'] );
	++$loops;
}
printf( "  %-46s %6.1f ms over %d polls (status %s)\n", 'bulk job remaining batches', ( microtime( true ) - $t ) * 1000, $loops, $job['status'] ?? '?' );
global $wpdb;
$wpdb->delete( LNKup_Event_Import_Tables::items(), array( 'job_id' => (int) $job['id'] ) );
$wpdb->delete( LNKup_Event_Import_Tables::jobs(), array( 'id' => (int) $job['id'] ) );

echo "Portfolio publish\n";
$pd = lnkup_perf_rest( 'POST', '/organizer/builder/drafts', array( 'method' => 'recurring' ) );
lnkup_perf_rest(
	'PATCH',
	'/organizer/builder/drafts/' . $pd['draft']['id'],
	array(
		'revision' => $pd['draft']['revision'],
		'patch'    => array(
			'event'     => array( 'name' => 'Perf Series', 'date' => gmdate( 'Y-m-d', strtotime( 'next saturday' ) ), 'city' => 'Charlotte', 'state' => 'NC', 'venue_name' => 'Lot', 'venue_address' => '1 Main St', 'timezone' => 'America/New_York', 'location_confirmed' => true ),
			'options'   => array( array( 'key' => 'r', 'name' => 'Retail', 'category' => 'Retail', 'fee' => 40, 'spots' => 10 ) ),
			'portfolio' => array( 'recurrence' => array( 'frequency' => 'weekly', 'weekdays' => array( 'sat' ), 'start' => gmdate( 'Y-m-d', strtotime( 'next saturday' ) ), 'count' => 8 ) ),
		),
	)
);
$t   = microtime( true );
$pub = lnkup_perf_rest( 'POST', '/organizer/builder/drafts/' . $pd['draft']['id'] . '/publish' );
printf( "  %-46s %6.1f ms (%d dates, %d live)\n", 'publish recurring (8 dates, inline)', ( microtime( true ) - $t ) * 1000, (int) ( $pub['dates'] ?? 0 ), (int) ( $pub['live'] ?? 0 ) );
if ( ! empty( $pub['tour_id'] ) ) {
	foreach ( LNKup_Tours::stop_event_ids( (int) $pub['tour_id'] ) as $e ) {
		foreach ( LNKup_Organizer_Onboarding::event_slot_posts( (int) $e ) as $opp ) {
			wp_delete_post( (int) $opp->ID, true );
		}
		wp_delete_post( (int) $e, true );
	}
	wp_delete_post( (int) $pub['tour_id'], true );
}

echo "Assets\n";
foreach ( array( 'public/js/event-builder.js', 'public/css/event-builder.css' ) as $rel ) {
	$path = LNKUP_PLUGIN_DIR . $rel;
	printf( "  %-46s %6.1f KB (%.1f KB gzip)\n", $rel, filesize( $path ) / 1024, strlen( (string) gzencode( (string) file_get_contents( $path ), 9 ) ) / 1024 ); // phpcs:ignore
}

wp_set_current_user( 0 );
foreach ( array( 'event_builder_v3', 'event_import_v3', 'event_import_bulk', 'portfolio_builder_v3' ) as $flag ) {
	LNKup_Event_OS_Flags::override( $flag, null );
}
lnkup_m_finish();
