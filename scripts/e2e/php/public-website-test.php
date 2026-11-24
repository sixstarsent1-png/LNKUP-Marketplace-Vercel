<?php
/**
 * Public Website 5.0 checks (discovery data, validation, pricing, campaign variants, landing
 * settings). Read-only except for options it restores before exiting.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/public-website-test.php
 */

$failures = 0;
$checks   = 0;
$check    = static function ( $label, $ok ) use ( &$failures, &$checks ) {
	++$checks;
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
};

LNKup_Public_Discovery::flush();
$snap = LNKup_Public_Discovery::snapshot();
$check( 'snapshot has categories, cities, city_index, tours, stats', isset( $snap['categories'], $snap['cities'], $snap['city_index'], $snap['tours'], $snap['stats'] ) );
$check( 'stats are integers', array() === array_filter( $snap['stats'], static fn( $v ) => ! is_int( $v ) ) );
$check( 'cities capped at ' . LNKup_Public_Discovery::MAX_CITIES, count( $snap['cities'] ) <= LNKup_Public_Discovery::MAX_CITIES );
$check( 'snapshot cached in transient', is_array( get_transient( LNKup_Public_Discovery::CACHE_KEY ) ) );

global $wpdb;
$table     = LNKup_Marketplace_Index::table_name();
$today     = current_time( 'Y-m-d' );
$raw_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} idx INNER JOIN {$wpdb->posts} o ON o.ID = idx.opportunity_id AND o.post_status = 'publish' INNER JOIN {$wpdb->posts} e ON e.ID = idx.event_id AND e.post_status = 'publish' WHERE idx.spots_remaining > 0 AND ( idx.event_date IS NULL OR idx.event_date >= %s ) AND ( idx.deadline IS NULL OR idx.deadline >= %s )", $today, $today ) ); // phpcs:ignore
$check( 'open_opportunities equals published open index rows (' . $raw_count . ')', $raw_count === $snap['stats']['open_opportunities'] );
$orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} idx LEFT JOIN {$wpdb->posts} o ON o.ID = idx.opportunity_id WHERE o.ID IS NULL" ); // phpcs:ignore
echo "INFO index rows without an opportunity post: {$orphans}\n";
$card_ids = wp_list_pluck( LNKup_Public_Discovery::event_cards( 'upcoming', 12 ), 'opportunity_id' );
$check( 'cards never point at deleted opportunities', array() === array_filter( $card_ids, static fn( $id ) => 'publish' !== get_post_status( (int) $id ) ) );

$vendors = LNKup_Public_Discovery::featured_vendors( 12 );
$private = array( 'email', 'phone', 'contact_email', 'contact_phone', 'owner_user_id', 'user_id', 'address' );
$leak    = false;
foreach ( $vendors as $vendor ) {
	if ( array_intersect( $private, array_keys( $vendor ) ) || ! LNKup_Passport_Privacy::is_public( (int) $vendor['id'] ) ) {
		$leak = true;
	}
}
$check( 'featured vendors are public Passports without contact fields', ! $leak );

$unknown = LNKup_Public_Discovery_REST::validated_context( '<script>alert(1)</script>', 'ZZ', '"onmouseover=' );
$check( 'unknown city/category dropped', array() === $unknown );
if ( $snap['city_index'] ) {
	$known = $snap['city_index'][0];
	$ctx   = LNKup_Public_Discovery_REST::validated_context( strtoupper( $known['city'] ), '', '' );
	$check( 'known city accepted case-insensitively', ( $ctx['city'] ?? '' ) === $known['city'] );
}
if ( $snap['categories'] ) {
	$cat = $snap['categories'][0];
	$ctx = LNKup_Public_Discovery_REST::validated_context( '', '', $cat['slug'] );
	$check( 'known category accepted by slug', ( $ctx['category_label'] ?? '' ) === $cat['label'] );
	$cards = LNKup_Public_Discovery::event_cards( 'upcoming', 3, array( 'categories' => $cat['label'] ) );
	$check( 'category cards returned for live category', count( $cards ) > 0 );
}
$check( 'unknown category yields no cards', array() === LNKup_Public_Discovery::event_cards( 'upcoming', 3, array( 'categories' => 'Not A Category' ) ) );

$cards = LNKup_Public_Discovery::event_cards( 'upcoming', 6 );
$bad   = array_filter( $cards, static fn( $c ) => (int) $c['spots'] < 1 || '' === (string) $c['url'] || '' === (string) $c['title'] );
$check( 'every card has open spots, a URL and a title', count( $cards ) > 0 && ! $bad );
$check( 'event_card ignores full events', null === LNKup_Public_Discovery::event_card( array( array( 'event_id' => 0, 'name' => 'Full', 'spots_remaining' => 0, 'vendor_fee' => 50 ) ) ) );

$pricing = LNKup_Public_Discovery::pricing();
$engine  = LNKup_Fee_Engine::calculate( array( 'vendor_fee' => 150.0 ) );
$check( 'pricing example matches Fee Engine total', abs( $pricing['example']['vendor_total'] - (float) $engine['total'] ) < 0.001 );
$check( 'pricing example matches Fee Engine organizer net', abs( $pricing['example']['organizer_payout'] - (float) $engine['organizer_net'] ) < 0.001 );
$check( 'commission percent derived from Fee Engine', abs( $pricing['organizer_commission_percent'] - LNKup_Fee_Engine::resolve_organizer_commission( 150.0 ) / 1.5 ) < 0.01 );

$routes = rest_get_server()->get_routes();
foreach ( array( '/lnkup/v1/public/discovery', '/lnkup/v1/public/discovery/cards', '/lnkup/v1/public/vendors/featured' ) as $route ) {
	$has_cb = isset( $routes[ $route ] ) && ! empty( $routes[ $route ][0]['permission_callback'] );
	$check( "route {$route} registered with permission_callback", $has_cb );
}
$request = new WP_REST_Request( 'GET', '/lnkup/v1/public/discovery/cards' );
$request->set_param( 'set', 'nope' );
$response = rest_do_request( $request );
$check( 'cards endpoint rejects unknown set (400)', 400 === $response->get_status() );
$request = new WP_REST_Request( 'GET', '/lnkup/v1/public/discovery/cards' );
$request->set_param( 'city', '<b>x</b>' );
$response = rest_do_request( $request );
$data     = $response->get_data();
$check( 'cards endpoint drops invalid city from context', 200 === $response->get_status() && empty( $data['context']['city'] ) );

$bad_slug = LNKup_Campaign_Landings::sanitize( array( 'slug' => '' ) );
$check( 'variant without slug rejected', is_wp_error( $bad_slug ) );
$variant = LNKup_Campaign_Landings::sanitize( array( 'slug' => 'Houston Trucks!', 'audience' => 'hacker', 'state' => 'tx<', 'headline' => '<img src=x onerror=alert(1)>Hi' ) );
$check( 'variant slug normalized', 'houston-trucks' === $variant['slug'] );
$check( 'variant audience falls back to vendor', 'vendor' === $variant['audience'] );
$check( 'variant state sanitized', 'TX' === $variant['state'] );
$check( 'variant headline stripped of HTML', false === strpos( $variant['headline'], '<' ) );
$check( 'variants disabled by default', 0 === $variant['enabled'] );

$clean = LNKup_Theme_Landing_Settings::sanitize(
	array(
		'org_walkthrough_url' => 'http://insecure.example.com/book',
		'announce_expires'    => 'tomorrow',
		'featured_vendor_ids' => '12, abc, 12, 7',
		'testimonials'        => array( array( 'quote' => 'Great', 'name' => 'A', 'verified' => '' ), array( 'quote' => '' ) ),
		'logos'               => array( array( 'name' => 'No image' ) ),
	)
);
$check( 'walkthrough link must be https', '' === $clean['org_walkthrough_url'] );
$check( 'announcement expiry must be a date', '' === $clean['announce_expires'] );
$check( 'pinned vendor IDs de-duplicated integers', array( 12, 7 ) === $clean['featured_vendor_ids'] );
$check( 'empty testimonials dropped, unverified stays unverified', 1 === count( $clean['testimonials'] ) && 0 === $clean['testimonials'][0]['verified'] );
$check( 'logos need an image', array() === $clean['logos'] );

$saved_option = get_option( LNKup_Theme_Landing_Settings::OPTION, null );
$saved_flag   = get_option( 'lnkup_landing_retired_copy_v2', null );
update_option( LNKup_Theme_Landing_Settings::OPTION, array( 'footer_blurb' => 'Join thousands of vendors growing their business at the best live events in the country.', 'use_custom_stats' => 1, 'stats' => array( array( 'value' => '20,000+' ) ) ), false );
delete_option( 'lnkup_landing_retired_copy_v2' );
LNKup_Theme_Landing_Settings::retire_unverified_copy();
$after = get_option( LNKup_Theme_Landing_Settings::OPTION );
$check( 'retired traction claim and custom stats removed', ! isset( $after['footer_blurb'] ) && ! isset( $after['use_custom_stats'] ) && ! isset( $after['stats'] ) );
if ( null === $saved_option ) {
	delete_option( LNKup_Theme_Landing_Settings::OPTION );
} else {
	update_option( LNKup_Theme_Landing_Settings::OPTION, $saved_option, false );
}
if ( null === $saved_flag ) {
	update_option( 'lnkup_landing_retired_copy_v2', '1', false );
}

echo "\n{$checks} checks, {$failures} failures\n";
if ( $failures ) {
	exit( 1 );
}
