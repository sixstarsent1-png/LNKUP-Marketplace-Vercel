<?php
/**
 * Unit tests for LNKup_Match_Engine (pure matching rules).
 *
 * Run: php tests/php/match-engine-test.php
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$lnkup_plugin_dir = getenv( 'LNKUP_PLUGIN_DIR' ) ?: dirname( __DIR__, 2 ) . '/wp-content/plugins/lnkup-marketplace';
require_once $lnkup_plugin_dir . '/includes/matching/class-match-engine.php';

$failures = 0;
$checks   = 0;

/**
 * Record one expectation.
 *
 * @param bool   $condition Expected truthy.
 * @param string $label     Description.
 */
function lnkup_match_check( $condition, $label ) {
	global $failures, $checks;
	++$checks;
	if ( $condition ) {
		echo "  ok   {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL {$label}\n";
}

/**
 * Charlotte food vendor fixture.
 *
 * @param array $overrides Field overrides.
 */
function lnkup_match_vendor( array $overrides = array() ) {
	return array_merge(
		array(
			'profile_id'             => 10,
			'user_id'                => 100,
			'active'                 => true,
			'discoverable'           => true,
			'categories'             => array( array( 'term_id' => 1, 'name' => 'Food Vendor', 'group' => 'food' ) ),
			'primary_category'       => 1,
			'city'                   => 'Charlotte',
			'state'                  => 'NC',
			'lat'                    => 35.23,
			'lng'                    => -80.84,
			'radius_miles'           => 100,
			'travel_national'        => false,
			'cities_served'          => array( 'Charlotte' ),
			'states_served'          => array( 'NC' ),
			'event_types'            => array(),
			'fee_max'                => null,
			'env_pref'               => '',
			'available_months'       => array(),
			'blackout_dates'         => array(),
			'booked_dates'           => array(),
			'approved_documents'     => array(),
			'past_organizers'        => array(),
			'favorite_organizers'    => array(),
			'favorite_opportunities' => array(),
			'applied_opportunities'  => array(),
			'invited_opportunities'  => array(),
			'related_organizers'     => array(),
		),
		$overrides
	);
}

/**
 * Charlotte food opportunity fixture.
 *
 * @param array $overrides Field overrides.
 */
function lnkup_match_opp( array $overrides = array() ) {
	return array_merge(
		array(
			'opportunity_id'     => 500,
			'event_id'           => 400,
			'organizer_user_id'  => 7,
			'tour_id'            => 0,
			'status'             => 'open',
			'event_published'    => true,
			'event_date'         => '2027-05-01',
			'deadline'           => '2027-04-01',
			'spots_remaining'    => 10,
			'invite_only'        => false,
			'categories'         => array( array( 'term_id' => 1, 'name' => 'Food Vendor', 'group' => 'food' ) ),
			'city'               => 'Charlotte',
			'state'              => 'NC',
			'lat'                => 35.22,
			'lng'                => -80.84,
			'vendor_fee'         => 150,
			'indoor'             => false,
			'outdoor'            => true,
			'power_available'    => true,
			'water_available'    => false,
			'food_truck_allowed' => true,
			'event_types'        => array( 'Festival' ),
			'required_documents' => array(),
			'trending_score'     => 0,
		),
		$overrides
	);
}

$weights = array( 'category' => 30, 'geo' => 25, 'requirements' => 20, 'preferences' => 10, 'dates' => 10, 'signals' => 5 );
$ctx     = array( 'today' => '2026-11-22', 'side' => 'vendor', 'min_score' => 40 );

echo "Hard eligibility\n";
$base = LNKup_Match_Engine::evaluate( lnkup_match_vendor(), lnkup_match_opp(), $weights, $ctx );
lnkup_match_check( $base['eligible'] && $base['recommended'], 'Charlotte food vendor matches Charlotte food event' );
lnkup_match_check( $base['score'] >= 90, 'strong score for primary category in home market (' . $base['score'] . ')' );

$expired = LNKup_Match_Engine::eligibility( lnkup_match_vendor(), lnkup_match_opp( array( 'deadline' => '2026-11-01' ) ), $ctx );
lnkup_match_check( in_array( 'deadline_passed', $expired['blocks'], true ), 'expired deadline is blocked' );

$past = LNKup_Match_Engine::eligibility( lnkup_match_vendor(), lnkup_match_opp( array( 'event_date' => '2026-10-01', 'deadline' => null ) ), $ctx );
lnkup_match_check( in_array( 'event_past', $past['blocks'], true ), 'past event is blocked' );

$full = LNKup_Match_Engine::eligibility( lnkup_match_vendor(), lnkup_match_opp( array( 'spots_remaining' => 0 ) ), $ctx );
lnkup_match_check( in_array( 'no_inventory', $full['blocks'], true ), 'full opportunity is blocked' );

$closed = LNKup_Match_Engine::eligibility( lnkup_match_vendor(), lnkup_match_opp( array( 'status' => 'closed' ) ), $ctx );
lnkup_match_check( in_array( 'opportunity_closed', $closed['blocks'], true ), 'closed opportunity is blocked' );

$draft = LNKup_Match_Engine::eligibility( lnkup_match_vendor(), lnkup_match_opp( array( 'event_published' => false ) ), $ctx );
lnkup_match_check( in_array( 'event_unpublished', $draft['blocks'], true ), 'unpublished event is blocked' );

$invite_only = lnkup_match_opp( array( 'invite_only' => true ) );
$no_invite   = LNKup_Match_Engine::eligibility( lnkup_match_vendor(), $invite_only, $ctx );
lnkup_match_check( in_array( 'invite_required', $no_invite['blocks'], true ), 'invite-only requires an invite' );
$invited = LNKup_Match_Engine::eligibility( lnkup_match_vendor( array( 'invited_opportunities' => array( 500 ) ) ), $invite_only, $ctx );
lnkup_match_check( $invited['eligible'], 'invited vendor can see invite-only opportunity' );

$apparel_opp = lnkup_match_opp( array( 'categories' => array( array( 'term_id' => 9, 'name' => 'Apparel', 'group' => 'retail' ) ) ) );
$mismatch    = LNKup_Match_Engine::evaluate( lnkup_match_vendor(), $apparel_opp, $weights, $ctx );
lnkup_match_check( ! $mismatch['eligible'] && in_array( 'category_mismatch', $mismatch['blocks'], true ), 'unrelated category is excluded' );
lnkup_match_check( 0 === $mismatch['score'], 'blocked pair is never scored' );

$applied = LNKup_Match_Engine::eligibility( lnkup_match_vendor( array( 'applied_opportunities' => array( 500 ) ) ), lnkup_match_opp(), $ctx );
lnkup_match_check( in_array( 'already_applied', $applied['blocks'], true ), 'already-applied vendor is excluded' );

$inactive = LNKup_Match_Engine::eligibility( lnkup_match_vendor( array( 'active' => false ) ), lnkup_match_opp(), $ctx );
lnkup_match_check( in_array( 'vendor_inactive', $inactive['blocks'], true ), 'deactivated vendor is excluded' );

$org_ctx = array_merge( $ctx, array( 'side' => 'organizer' ) );
$hidden  = LNKup_Match_Engine::eligibility( lnkup_match_vendor( array( 'discoverable' => false ) ), lnkup_match_opp(), $org_ctx );
lnkup_match_check( in_array( 'not_discoverable', $hidden['blocks'], true ), 'opted-out vendor hidden from unrelated organizer' );
$related = LNKup_Match_Engine::eligibility( lnkup_match_vendor( array( 'discoverable' => false, 'related_organizers' => array( 7 ) ) ), lnkup_match_opp(), $org_ctx );
lnkup_match_check( $related['eligible'], 'opted-out vendor still visible to organizer they worked with' );
$vendor_side_hidden = LNKup_Match_Engine::eligibility( lnkup_match_vendor( array( 'discoverable' => false ) ), lnkup_match_opp(), $ctx );
lnkup_match_check( $vendor_side_hidden['eligible'], 'discoverability does not limit what the vendor sees' );

echo "Categories\n";
$group_opp = lnkup_match_opp( array( 'categories' => array( array( 'term_id' => 2, 'name' => 'Food Truck', 'group' => 'food' ) ) ) );
$group     = LNKup_Match_Engine::evaluate( lnkup_match_vendor(), $group_opp, $weights, $ctx );
lnkup_match_check( $group['eligible'] && 'group' === $group['factors']['category']['kind'], 'same category group counts as a match' );
lnkup_match_check( $group['score'] < $base['score'], 'group match ranks below exact match' );

$open = LNKup_Match_Engine::evaluate( lnkup_match_vendor(), lnkup_match_opp( array( 'categories' => array() ) ), $weights, $ctx );
lnkup_match_check( $open['eligible'] && 'open' === $open['factors']['category']['kind'], 'opportunity without categories is open to all' );

$no_cats = LNKup_Match_Engine::evaluate( lnkup_match_vendor( array( 'categories' => array(), 'primary_category' => 0 ) ), lnkup_match_opp(), $weights, $ctx );
lnkup_match_check( $no_cats['eligible'] && null === $no_cats['factors']['category']['score'], 'vendor without categories is not blocked; factor has no data' );
lnkup_match_check( $no_cats['confidence'] < 100, 'missing categories lowers confidence' );

echo "Geography\n";
$atl  = lnkup_match_opp( array( 'city' => 'Atlanta', 'state' => 'GA', 'lat' => 33.75, 'lng' => -84.39 ) );
$away = LNKup_Match_Engine::evaluate( lnkup_match_vendor(), $atl, $weights, $ctx );
lnkup_match_check( 0.0 === $away['factors']['geo']['score'], 'out-of-market event gets zero geography' );
lnkup_match_check( ! $away['recommended'] && in_array( 'outside_market', $away['suppressed'], true ), 'out-of-market event is not recommended (no alerts for unrelated stops)' );
$index_ctx = array_merge( $ctx, array( 'side' => 'index' ) );
$index_inv = LNKup_Match_Engine::eligibility( lnkup_match_vendor( array( 'discoverable' => false ) ), lnkup_match_opp( array( 'invite_only' => true ) ), $index_ctx );
lnkup_match_check( $index_inv['eligible'], 'cached index side defers invite and discoverability checks to read time' );
$prefers_atl = LNKup_Match_Engine::evaluate( lnkup_match_vendor( array( 'cities_served' => array( 'Charlotte', 'Atlanta' ) ) ), $atl, $weights, $ctx );
lnkup_match_check( 1.0 === $prefers_atl['factors']['geo']['score'], 'changing preferred cities updates the match' );
lnkup_match_check( $prefers_atl['score'] > $away['score'], 'preferred market outranks out-of-market' );

$near = LNKup_Match_Engine::evaluate( lnkup_match_vendor( array( 'cities_served' => array() ) ), lnkup_match_opp( array( 'city' => 'Concord', 'lat' => 35.41, 'lng' => -80.58 ) ), $weights, $ctx );
lnkup_match_check( 'within_radius' === $near['factors']['geo']['kind'] && $near['factors']['geo']['score'] > 0.5, 'nearby city scores within radius' );

$national = LNKup_Match_Engine::evaluate( lnkup_match_vendor( array( 'travel_national' => true ) ), $atl, $weights, $ctx );
lnkup_match_check( 0.4 === $national['factors']['geo']['score'], 'national travel gives partial geography' );

$bad_coords = LNKup_Match_Engine::evaluate( lnkup_match_vendor( array( 'lat' => 999, 'lng' => 0 ) ), lnkup_match_opp( array( 'city' => 'Concord', 'lat' => 0, 'lng' => 0 ) ), $weights, $ctx );
lnkup_match_check( 'within_radius' !== $bad_coords['factors']['geo']['kind'], 'invalid coordinates are ignored' );

$no_loc = LNKup_Match_Engine::evaluate(
	lnkup_match_vendor( array( 'city' => '', 'state' => '', 'lat' => null, 'lng' => null, 'cities_served' => array(), 'states_served' => array() ) ),
	lnkup_match_opp(),
	$weights,
	$ctx
);
lnkup_match_check( $no_loc['eligible'] && null === $no_loc['factors']['geo']['score'], 'vendor without location is not penalized to zero' );

echo "Cold start and fairness\n";
lnkup_match_check( null === $base['factors']['signals']['score'], 'no history means no signals factor (dropped, not zero)' );
$history = LNKup_Match_Engine::evaluate( lnkup_match_vendor( array( 'past_organizers' => array( 7 ) ) ), lnkup_match_opp(), $weights, $ctx );
lnkup_match_check( abs( $history['score'] - $base['score'] ) <= 5, 'returning vendor does not massively outrank a new vendor' );

$tie_a = array( 'score' => 80, 'event_date' => '2027-01-01', 'trending_score' => 1 );
$tie_b = array( 'score' => 80, 'event_date' => '2027-01-01', 'trending_score' => 99 );
$tie_c = array( 'score' => 81, 'event_date' => '2027-06-01', 'trending_score' => 0 );
$rows  = array( $tie_a, $tie_b, $tie_c );
usort( $rows, array( 'LNKup_Match_Engine', 'compare' ) );
lnkup_match_check( 81 === $rows[0]['score'], 'relevance outranks popularity' );
lnkup_match_check( 99 === (int) $rows[1]['trending_score'], 'trending only breaks exact ties' );

echo "Preferences and dates\n";
$budget = LNKup_Match_Engine::evaluate( lnkup_match_vendor( array( 'fee_max' => 100 ) ), lnkup_match_opp( array( 'vendor_fee' => 300 ) ), $weights, $ctx );
lnkup_match_check( 0.0 === $budget['factors']['preferences']['score'], 'fee far above budget scores zero preferences' );
$booked = LNKup_Match_Engine::evaluate( lnkup_match_vendor( array( 'booked_dates' => array( '2027-05-01' ) ) ), lnkup_match_opp(), $weights, $ctx );
lnkup_match_check( 0.0 === $booked['factors']['dates']['score'], 'existing paid booking on the date lowers availability' );

echo "Explanations\n";
$codes = array_column( $base['reasons'], 'code' );
lnkup_match_check( in_array( 'category', $codes, true ) && in_array( 'preferred_city', $codes, true ), 'reasons come from triggered factors' );
lnkup_match_check( ! in_array( 'previous_organizer', $codes, true ), 'no reason without supporting data' );
lnkup_match_check( 'strong' === LNKup_Match_Engine::band( 80 ) && 'possible' === LNKup_Match_Engine::band( 40 ), 'score bands' );

echo "\n{$checks} checks, {$failures} failures\n";
exit( $failures > 0 ? 1 : 0 );
