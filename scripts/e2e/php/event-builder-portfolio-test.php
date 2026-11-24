<?php
/**
 * Event Creation OS 3.0 — recurring events, tours and series from the builder, published through
 * the existing Portfolio services. All tours, dates, booth listings and drafts are removed.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/event-builder-portfolio-test.php
 */

require_once __DIR__ . '/_marketing_helpers.php';
global $wpdb;

LNKup_Event_OS_Flags::override( 'event_builder_v3', true );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () {
	LNKup_Event_OS_Flags::override( 'event_builder_v3', null );
	LNKup_Event_OS_Flags::override( 'portfolio_builder_v3', null );
};

/**
 * REST call against the builder as a user.
 *
 * @return array{status:int,data:mixed}
 */
function lnkup_pf_rest( string $method, string $route, array $body, int $user ): array {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, '/lnkup/v1/organizer/builder' . $route );
	if ( 'GET' === $method ) {
		$req->set_query_params( $body );
	} else {
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

/**
 * Remove a tour with its stops and their booth listings.
 */
function lnkup_pf_cleanup_tour( int $tour_id ): void {
	$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $tour_id ) {
		global $wpdb;
		foreach ( LNKup_Tours::stop_event_ids( $tour_id ) as $event_id ) {
			foreach ( LNKup_Organizer_Onboarding::event_slot_posts( (int) $event_id ) as $opp ) {
				if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
					$wpdb->delete( LNKup_Marketplace_Index::table_name(), array( 'opportunity_id' => (int) $opp->ID ) );
				}
				wp_delete_post( (int) $opp->ID, true );
			}
			wp_delete_post( (int) $event_id, true );
		}
		wp_delete_post( $tour_id, true );
	};
}

/**
 * Create a builder draft and apply a patch.
 *
 * @param array<string,mixed> $patch State patch.
 * @return array<string,mixed> Draft payload after the patch.
 */
function lnkup_pf_draft( int $user, string $method, array $patch ): array {
	$c = lnkup_pf_rest( 'POST', '/drafts', array( 'method' => $method ), $user );
	$d = $c['data']['draft'];
	return lnkup_pf_rest( 'PATCH', '/drafts/' . $d['id'], array( 'revision' => $d['revision'], 'patch' => $patch ), $user )['data'];
}

$org   = lnkup_t_user( 'lnkup_organizer' );
$other = lnkup_t_user( 'lnkup_organizer' );
$sat   = gmdate( 'Y-m-d', strtotime( 'next saturday', strtotime( '+14 days' ) ) );
$base  = array(
	'event'        => array( 'name' => 'Saturday Makers Market', 'start_time' => '09:00', 'end_time' => '14:00', 'timezone' => 'America/New_York', 'city' => 'Charlotte', 'state' => 'NC', 'venue_name' => 'Atherton Mill', 'venue_address' => '2140 South Blvd', 'description' => str_repeat( 'Local makers, farmers and food trucks every week. ', 3 ) ),
	'options'      => array(
		array( 'key' => 'craft', 'name' => 'Craft table', 'category' => 'Retail', 'fee' => 40, 'spots' => 20 ),
		array( 'key' => 'truck', 'name' => 'Food truck', 'category' => 'Food Trucks', 'fee' => 75, 'spots' => 4 ),
	),
	'applications' => array( 'questions' => array( array( 'id' => 'tent', 'label' => 'Do you bring a tent?', 'type' => 'yesno', 'required' => true ) ) ),
	'payments'     => array( 'deadline' => array( 'mode' => 'relative', 'days_before' => 3 ) ),
);

echo "Recurring schedule\n";
$exclude = gmdate( 'Y-m-d', strtotime( $sat . ' +7 days' ) );
$patch   = array_merge(
	$base,
	array(
		'event'     => array_merge( $base['event'], array( 'date' => $sat ) ),
		'portfolio' => array( 'recurrence' => array( 'frequency' => 'weekly', 'weekdays' => array( 'sat' ), 'start' => $sat, 'count' => 4, 'exclude' => array( $exclude ) ) ),
	)
);
LNKup_Event_OS_Flags::override( 'portfolio_builder_v3', false );
$d   = lnkup_pf_draft( $org, 'recurring', $patch );
$chk = array_column( $d['report']['checks'], 'ok', 'key' );
lnkup_t_same( false, $chk['portfolio_flag'] ?? null, 'multi-date publishing is gated by portfolio_builder_v3' );
LNKup_Event_OS_Flags::override( 'portfolio_builder_v3', true );
$id    = (int) $d['draft']['id'];
$dates = lnkup_pf_rest( 'GET', '/drafts/' . $id . '/dates', array(), $org )['data'];
lnkup_t_same( array( 4, 3, $sat ), array( count( $dates['occurrences'] ?? array() ), $dates['count'] ?? 0, $dates['first'] ?? '' ), 'weekly schedule previews 4 dates, 1 skipped' );
lnkup_t_same( true, $dates['occurrences'][1]['excluded'] ?? null, 'skipped date is shown as excluded' );
lnkup_t_assert( false !== strpos( (string) ( $dates['deadline'] ?? '' ), '3 days' ), 'relative application deadline is described per date' );
$report = lnkup_pf_rest( 'GET', '/drafts/' . $id, array(), $org )['data']['report'];
lnkup_t_same( true, $report['publishable'], 'recurring draft is publishable' );
lnkup_t_same( 404, lnkup_pf_rest( 'GET', '/drafts/' . $id . '/dates', array(), $other )['status'], 'other organizers cannot preview the schedule' );

$pub = lnkup_pf_rest( 'POST', '/drafts/' . $id . '/publish', array(), $org )['data'];
$tid = (int) ( $pub['tour_id'] ?? 0 );
lnkup_pf_cleanup_tour( $tid );
lnkup_t_same( array( true, 'recurring', 3 ), array( $pub['portfolio'] ?? false, $pub['type'] ?? '', $pub['dates'] ?? 0 ), 'publishing creates one recurring series with 3 dates' );
lnkup_t_same( 3, $pub['live'] ?? 0, 'all dates are published' );
$stops = LNKup_Tours::stop_event_ids( $tid );
usort( $stops, static fn( $a, $b ) => strcmp( (string) get_post_meta( (int) $a, '_lnkup_event_date', true ), (string) get_post_meta( (int) $b, '_lnkup_event_date', true ) ) );
$s0    = (int) $stops[0];
$opps  = LNKup_Organizer_Onboarding::event_slot_posts( $s0 );
lnkup_t_same( array( 2, 'Charlotte', $sat ), array( count( $opps ), (string) get_post_meta( $s0, '_lnkup_city', true ), (string) get_post_meta( $s0, '_lnkup_event_date', true ) ), 'each date is its own event with both booth types' );
$fees = array_map( static fn( $o ) => (float) get_post_meta( $o->ID, '_lnkup_vendor_fee', true ), $opps );
sort( $fees );
lnkup_t_same( array( 40.0, 75.0 ), $fees, 'booth prices come from the shared vendor options' );
lnkup_t_same( gmdate( 'Y-m-d', strtotime( $sat . ' -3 days' ) ), (string) get_post_meta( (int) $opps[0]->ID, '_lnkup_application_deadline', true ), 'deadline is relative to each date' );
lnkup_t_same( array( 'Saturday Makers Market', 1 ), array( lnkup_plain_title( $tid ), count( LNKup_Portfolio_Config::get_defaults( $tid )['questions'] ) ), 'series keeps the name and the application questions' );
lnkup_t_assert( ! in_array( $exclude, array_map( static fn( $e ) => (string) get_post_meta( (int) $e, '_lnkup_event_date', true ), $stops ), true ), 'excluded date was not created' );
lnkup_t_same( 'trash', get_post_status( $id ), 'draft is closed after publishing' );
$again = LNKup_Event_Builder_Portfolio::publish( $id, $org );
lnkup_t_same( array( $tid, true ), array( (int) ( $again['tour_id'] ?? 0 ), $again['idempotent'] ?? false ), 'publishing twice returns the same series' );

echo "Recurring as drafts\n";
$d2  = lnkup_pf_draft( $org, 'recurring', array_merge( $patch, array( 'portfolio' => array( 'publish_mode' => 'drafts', 'recurrence' => array( 'frequency' => 'monthly_weekday', 'start' => $sat, 'count' => 3 ) ) ) ) );
$p2  = lnkup_pf_rest( 'POST', '/drafts/' . $d2['draft']['id'] . '/publish', array(), $org )['data'];
lnkup_pf_cleanup_tour( (int) ( $p2['tour_id'] ?? 0 ) );
lnkup_t_same( array( 3, 0 ), array( $p2['dates'] ?? 0, $p2['live'] ?? -1 ), '"save dates as drafts" creates dates without publishing' );
$m = array_map( static fn( $e ) => (int) gmdate( 'j', strtotime( (string) get_post_meta( (int) $e, '_lnkup_event_date', true ) ) ), LNKup_Tours::stop_event_ids( (int) $p2['tour_id'] ) );
lnkup_t_assert( count( array_unique( array_map( static fn( $day ) => (int) ceil( $day / 7 ), $m ) ) ) <= 2, 'monthly dates follow the same week-of-month' );
$sched = lnkup_pf_draft( $org, 'recurring', array_merge( $patch, array( 'publish' => array( 'when' => 'schedule', 'at' => gmdate( 'Y-m-d\TH:i', strtotime( '+2 days' ) ) ) ) ) );
lnkup_t_same( false, array_column( $sched['report']['checks'], 'ok', 'key' )['portfolio_schedule'] ?? null, 'scheduled publishing is refused for multi-date events' );
$bad = lnkup_pf_draft( $org, 'recurring', array_merge( $patch, array( 'portfolio' => array( 'recurrence' => array( 'frequency' => 'weekly', 'start' => $sat ) ) ) ) );
lnkup_t_same( false, array_column( $bad['report']['checks'], 'ok', 'key' )['schedule_rule'] ?? null, 'a schedule without an end or count is not publishable' );

echo "Tours and series\n";
$own    = lnkup_m_event( array( 'organizer_user_id' => $org ), false, 'Existing Raleigh Stop' );
$theirs = lnkup_m_event( array( 'organizer_user_id' => $other ), false );
$d1     = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
$d2d    = gmdate( 'Y-m-d', strtotime( '+44 days' ) );
$tour   = lnkup_pf_draft(
	$org,
	'tour',
	array_merge(
		$base,
		array(
			'event'     => array_merge( $base['event'], array( 'name' => 'Sneaker Summit', 'city' => '', 'state' => '', 'venue_name' => '' ) ),
			'portfolio' => array(
				'name'  => 'Sneaker Summit 2027',
				'stops' => array(
					array( 'key' => 'atl', 'date' => $d1, 'city' => 'Atlanta', 'state' => 'GA', 'venue_name' => 'Pullman Yards' ),
					array( 'key' => 'nva', 'date' => $d1, 'city' => 'Nashville', 'state' => 'TN' ),
					array( 'key' => 'dal', 'date' => $d2d, 'city' => 'Dallas', 'state' => 'TX', 'fee_override' => 95, 'publish' => false ),
					array( 'key' => 'hou', 'date' => $d2d, 'city' => 'Houston', 'state' => 'TX', 'skip' => true ),
					array( 'key' => 'ral', 'event_id' => $own['event'] ),
				),
			),
		)
	)
);
$tc = array_column( $tour['report']['checks'], 'ok', 'key' );
lnkup_t_same( array( false, false, false ), array( $tour['report']['publishable'], $tc['stop_venue_atl'] ?? null, $tc['stop_venue_nva'] ?? null ), 'stops published now need a venue and address' );
$stops_fixed = $tour['draft']['state']['portfolio']['stops'];
$stops_fixed[0]['venue_address'] = '225 Rogers St NE';
unset( $stops_fixed[1] );
$tour = lnkup_pf_rest( 'PATCH', '/drafts/' . $tour['draft']['id'], array( 'revision' => $tour['draft']['revision'], 'patch' => array( 'portfolio' => array( 'stops' => array_values( $stops_fixed ) ) ) ), $org )['data'];
$tc   = array_column( $tour['report']['checks'], 'ok', 'key' );
lnkup_t_same( array( true, true, false, false ), array( $tour['report']['publishable'], $tc['stops'] ?? false, isset( $tc['location'] ), isset( $tc['stop_venue_dal'] ) ), 'tour draft validates stops instead of a single location; held-back stops need no venue yet' );
$tp  = lnkup_pf_rest( 'POST', '/drafts/' . $tour['draft']['id'] . '/publish', array(), $org )['data'];
$ttd = (int) ( $tp['tour_id'] ?? 0 );
lnkup_pf_cleanup_tour( $ttd );
lnkup_t_same( array( 'tour', 'Sneaker Summit 2027', 3 ), array( $tp['type'] ?? '', $tp['title'] ?? '', $tp['dates'] ?? 0 ), 'tour created with 2 new stops + 1 existing event (skipped stop left out)' );
$by   = array();
foreach ( LNKup_Tours::stop_event_ids( $ttd ) as $e ) {
	$by[ (string) get_post_meta( (int) $e, '_lnkup_city', true ) ] = (int) $e;
}
lnkup_t_same( array( 'publish', 'draft' ), array( get_post_status( $by['Atlanta'] ?? 0 ), get_post_status( $by['Dallas'] ?? 0 ) ), 'only stops marked to publish go live' );
lnkup_t_same( 95.0, (float) ( LNKup_Portfolio_Config::get_overrides( $by['Dallas'] ?? 0 )['booth_types']['craft']['price'] ?? 0 ), 'per-stop price override is stored on that stop' );
lnkup_t_same( $ttd, (int) get_post_meta( $own['event'], '_lnkup_tour_id', true ), 'existing event joined the tour' );
lnkup_t_same( 'Sneaker Summit — Atlanta', lnkup_plain_title( $by['Atlanta'] ?? 0 ), 'tour stops are named by city' );
$GLOBALS['lnkup_m_extra_cleanup'][] = static fn() => delete_post_meta( $own['event'], '_lnkup_tour_id' );

$bad_tour = lnkup_pf_draft( $org, 'series', array_merge( $base, array( 'portfolio' => array( 'stops' => array( array( 'key' => 'x', 'event_id' => $theirs['event'] ), array( 'key' => 'y', 'date' => '2020-01-01', 'city' => 'Austin', 'state' => 'TX' ) ) ) ) ) );
$bc       = array_column( $bad_tour['report']['checks'], 'ok', 'key' );
lnkup_t_same( array( false, false, false ), array( $bc['stop_x'] ?? null, $bc['stop_y'] ?? null, $bad_tour['report']['publishable'] ), 'another organizer\'s event and past dates are rejected' );
$empty = lnkup_pf_draft( $org, 'series', $base );
lnkup_t_same( false, array_column( $empty['report']['checks'], 'ok', 'key' )['stops'] ?? null, 'a series needs at least one event' );

echo "Stop candidates and per-stop listings\n";
LNKup_Event_Import_Tables::install();
$cand_own  = lnkup_m_event( array( 'organizer_user_id' => $org ), false, 'Candidate Event' );
$cand_ids  = wp_list_pluck( lnkup_pf_rest( 'GET', '/stop-candidates', array(), $org )['data']['events'] ?? array(), 'id' );
lnkup_t_assert( in_array( $cand_own['event'], $cand_ids, true ) && ! in_array( $own['event'], $cand_ids, true ) && ! in_array( $theirs['event'], $cand_ids, true ), 'candidates are my upcoming events not already in a tour' );
$posh_url  = 'https://posh.vip/e/sneaker-summit-atl-' . wp_generate_password( 5, false, false );
$mixed     = lnkup_pf_draft(
	$org,
	'series',
	array_merge(
		$base,
		array(
			'portfolio' => array(
				'stops' => array(
					array( 'key' => 'm1', 'date' => $d1, 'city' => 'Atlanta', 'state' => 'GA', 'venue_name' => 'Pullman Yards', 'venue_address' => '225 Rogers St NE', 'url' => $posh_url ),
					array( 'key' => 'm2', 'date' => $d2d, 'city' => 'Austin', 'state' => 'TX', 'venue_name' => 'Fair Market', 'venue_address' => '1100 E 5th St', 'url' => 'https://www.eventbrite.com/e/summit-austin-tickets-77788899' ),
				),
			),
		)
	)
);
$mc = array_column( $mixed['report']['checks'], 'ok', 'key' );
lnkup_t_same( array( false, false ), array( $mc['attest'] ?? null, $mixed['report']['publishable'] ), 'per-stop listings require the organizer confirmation' );
$mixed = lnkup_pf_rest( 'PATCH', '/drafts/' . $mixed['draft']['id'], array( 'revision' => $mixed['draft']['revision'], 'patch' => array( 'publish' => array( 'attest' => true ) ) ), $org )['data'];
lnkup_t_same( true, $mixed['report']['publishable'], 'confirmed mixed-provider series is publishable' );
$mp  = lnkup_pf_rest( 'POST', '/drafts/' . $mixed['draft']['id'] . '/publish', array(), $org )['data'];
$mtd = (int) ( $mp['tour_id'] ?? 0 );
lnkup_pf_cleanup_tour( $mtd );
$GLOBALS['lnkup_m_extra_cleanup'][] = static function () use ( $mtd ) {
	global $wpdb;
	foreach ( LNKup_Tours::stop_event_ids( $mtd ) as $e ) {
		$wpdb->delete( LNKup_Event_Import_Tables::sources(), array( 'event_id' => (int) $e ) );
	}
};
$providers = array();
foreach ( LNKup_Tours::stop_event_ids( $mtd ) as $e ) {
	foreach ( LNKup_Event_Sources::for_event( (int) $e ) as $src ) {
		$providers[ (string) get_post_meta( (int) $e, '_lnkup_city', true ) ] = $src['provider'];
	}
}
ksort( $providers );
lnkup_t_same( array( 'Atlanta' => 'posh', 'Austin' => 'eventbrite' ), $providers, 'each stop keeps its own official listing (mixed providers)' );
lnkup_t_same( 'Saturday Makers Market', lnkup_plain_title( LNKup_Tours::stop_event_ids( $mtd )[0] ?? 0 ), 'series events are not suffixed with the city' );
$clash = lnkup_pf_draft( $other, 'series', array_merge( $base, array( 'portfolio' => array( 'stops' => array( array( 'key' => 'c1', 'date' => $d1, 'city' => 'Atlanta', 'state' => 'GA', 'url' => $posh_url ) ) ) ) ) );
lnkup_t_same( false, array_column( $clash['report']['checks'], 'ok', 'key' )['stop_listing_c1'] ?? null, 'another organizer cannot reuse a stop listing' );

lnkup_m_finish();
