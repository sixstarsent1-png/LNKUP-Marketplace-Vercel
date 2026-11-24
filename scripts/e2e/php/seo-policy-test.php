<?php
/**
 * Phase 1: index policy, schema, sitemaps, URLs, diagnostics.
 *
 * Run: wp eval-file wp-content/lnkup-e2e/php/seo-policy-test.php
 */

require_once __DIR__ . '/_marketing_helpers.php';
lnkup_m_flags( array( 'seo_index_policy' ) );

echo "Index policy\n";
$up = lnkup_m_event();
lnkup_t_same( 'upcoming', LNKup_Index_Policy::event( $up['event'] )['reason'], 'upcoming complete event is indexable' );
lnkup_t_assert( LNKup_Index_Policy::event( $up['event'] )['sitemap'], 'upcoming event in sitemap' );

$past = lnkup_m_event( array( 'event_date' => gmdate( 'Y-m-d', strtotime( '-10 days' ) ) ) );
$d    = LNKup_Index_Policy::event( $past['event'] );
lnkup_t_assert( ! $d['index'] && ! $d['sitemap'] && 'past' === $d['reason'], 'past event noindex and out of sitemap' );
lnkup_t_assert( LNKup_Index_Policy::past_event_recent( $past['event'] ), 'recent past event flagged as recent' );

$multi = lnkup_m_event( array( 'event_date' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ), 'event_end_date' => gmdate( 'Y-m-d', strtotime( '+1 day' ) ) ) );
lnkup_t_assert( LNKup_Index_Policy::event( $multi['event'] )['index'], 'multi-day event still running stays indexable' );

$cancel = lnkup_m_event( array( 'event_status' => 'cancelled' ) );
lnkup_t_same( 'cancelled', LNKup_Index_Policy::event( $cancel['event'] )['reason'], 'cancelled event noindex' );

$post = lnkup_m_event( array( 'event_status' => 'postponed' ) );
lnkup_t_assert( LNKup_Index_Policy::event( $post['event'] )['index'], 'postponed event with upcoming date stays indexable' );

$noc = lnkup_m_event( array( 'city' => '' ) );
lnkup_t_same( 'incomplete', LNKup_Index_Policy::event( $noc['event'] )['reason'], 'event without city is incomplete' );

$auth = lnkup_m_event();
update_post_meta( $auth['event'], '_lnkup_authenticity_status', 'disputed' );
LNKup_Index_Policy::flush();
lnkup_t_same( 'authenticity', LNKup_Index_Policy::event( $auth['event'] )['reason'], 'disputed authenticity noindex' );

$draft = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'draft', 'post_title' => 'MOS draft' ) );
$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $draft );
lnkup_t_same( 'not_public', LNKup_Index_Policy::event( (int) $draft )['reason'], 'draft event not public' );

echo "Market policy\n";
$market_live = array( 'enabled' => 1, 'indexable' => 1, 'launch_status' => 'active', 'city' => 'Charlotte', 'state' => 'NC' );
lnkup_t_assert( LNKup_Index_Policy::market( $market_live )['index'], 'market with open events indexable' );
$market_thin = array( 'enabled' => 1, 'indexable' => 1, 'launch_status' => 'prelaunch', 'city' => 'Nowhereville', 'state' => 'ZZ' );
lnkup_t_same( 'thin', LNKup_Index_Policy::market( $market_thin )['reason'], 'empty market without content is thin' );
$market_thin['content'] = str_repeat( 'Local vendor guide for Nowhereville markets and permits. ', 8 );
lnkup_t_assert( LNKup_Index_Policy::market( $market_thin )['index'], 'empty market with unique content indexable' );
lnkup_t_assert( ! LNKup_Index_Policy::market( array_merge( $market_live, array( 'launch_status' => 'paused' ) ) )['index'], 'paused market noindex' );

echo "Organizer policy\n";
$org = (int) get_post_meta( $up['event'], '_lnkup_organizer_user_id', true );
lnkup_t_assert( LNKup_Index_Policy::organizer( $org )['index'], 'organizer with a published event indexable' );
lnkup_t_assert( ! LNKup_Index_Policy::organizer( lnkup_t_user( 'lnkup_organizer' ) )['index'], 'organizer without events noindex' );
lnkup_t_assert( ! LNKup_Index_Policy::organizer( lnkup_t_user( 'lnkup_vendor' ) )['index'], 'vendor user is not an organizer profile' );

echo "Schema\n";
$schema = LNKup_SEO_Schema::event( $up['event'] );
lnkup_t_assert( is_array( $schema ) && 1 === preg_match( '/T10:00:00[+-]\d{2}:\d{2}$/', (string) $schema['startDate'] ), 'startDate has time and offset' );
lnkup_t_assert( isset( $schema['endDate'] ) && false !== strpos( (string) $schema['endDate'], 'T18:00' ), 'endDate from end time' );
lnkup_t_assert( ! isset( $schema['offers'] ), 'no offers (booth fee is not admission)' );
lnkup_t_same( 'Test Park', $schema['location']['name'] ?? '', 'public venue name in location' );
lnkup_t_same( '100 Test St', $schema['location']['address']['streetAddress'] ?? '', 'public street address' );
lnkup_t_assert( ! empty( $schema['organizer']['name'] ), 'organizer included' );

$hidden = lnkup_m_event( array( 'venue_hidden' => 1 ) );
$hs     = LNKup_SEO_Schema::event( $hidden['event'] );
lnkup_t_assert( empty( $hs['location']['address']['streetAddress'] ) && 'Charlotte, NC' === $hs['location']['name'], 'hidden venue keeps only city' );

lnkup_t_same( 'https://schema.org/EventPostponed', LNKup_SEO_Schema::event( $post['event'] )['eventStatus'], 'postponed status' );
lnkup_t_same( 'https://schema.org/EventCancelled', LNKup_SEO_Schema::event( $cancel['event'] )['eventStatus'], 'cancelled status' );

$resched = lnkup_m_event();
update_post_meta( $resched['event'], '_lnkup_event_date', gmdate( 'Y-m-d', strtotime( '+40 days' ) ) );
$rs = LNKup_SEO_Schema::event( $resched['event'] );
lnkup_t_same( 'https://schema.org/EventRescheduled', $rs['eventStatus'], 'date change becomes EventRescheduled' );
lnkup_t_same( gmdate( 'Y-m-d', strtotime( '+20 days' ) ), $rs['previousStartDate'] ?? '', 'previousStartDate recorded' );

$crumbs = LNKup_SEO_Schema::event_crumbs( $up['event'] );
lnkup_t_assert( count( $crumbs ) >= 3 && get_permalink( $up['event'] ) === end( $crumbs )[1], 'event breadcrumbs end at the event' );

echo "Sitemaps\n";
$urls = wp_list_pluck( LNKup_Sitemaps::entries( 'events', 1 ), 'loc' );
lnkup_t_assert( in_array( get_permalink( $up['event'] ), $urls, true ), 'upcoming event in sitemap entries' );
lnkup_t_assert( ! in_array( get_permalink( $past['event'] ), $urls, true ), 'past event not in sitemap entries' );
lnkup_t_assert( ! in_array( get_permalink( $cancel['event'] ), $urls, true ), 'cancelled event not in sitemap entries' );
$entry = current( array_filter( LNKup_Sitemaps::entries( 'events', 1 ), static fn( $e ) => get_permalink( $up['event'] ) === $e['loc'] ) );
lnkup_t_assert( is_array( $entry ) && '' !== $entry['lastmod'], 'event entry has lastmod' );
$orgs = wp_list_pluck( LNKup_Sitemaps::entries( 'organizers', 1 ), 'loc' );
lnkup_t_assert( in_array( LNKup_Organizer_Onboarding::organizer_public_url( $org ), $orgs, true ), 'organizer with events in sitemap' );
lnkup_t_assert( LNKup_SEO::exclude_sitemap_post( false, $past['event'] ), 'Rank Math exclusion drops past events' );
lnkup_t_assert( LNKup_SEO::exclude_sitemap_post( false, $up['opportunity'] ), 'Rank Math exclusion drops opportunity singles' );
lnkup_t_assert( ! LNKup_SEO::exclude_sitemap_post( false, $up['event'] ), 'Rank Math keeps upcoming events' );

echo "URLs\n";
lnkup_t_same( home_url( '/organizers/' . LNKup_Organizer_Onboarding::organizer_public_slug( $org ) . '/' ), LNKup_Organizer_Onboarding::organizer_public_url( $org ), 'organizer pretty URL' );
$args = LNKup_SEO_URLs::event_args( array( 'public' => true ), 'lnkup_event' );
lnkup_t_same( 'events', $args['rewrite']['slug'] ?? '', 'event post type rewrites to /events/' );
LNKup_Marketing_Flags::override( 'seo_index_policy', false );
lnkup_t_assert( ! isset( LNKup_SEO_URLs::event_args( array(), 'lnkup_event' )['rewrite'] ), 'flag off keeps the legacy event URL' );
lnkup_t_assert( false === strpos( LNKup_Organizer_Onboarding::organizer_public_url( $org ), '/organizers/' . LNKup_Organizer_Onboarding::organizer_public_slug( $org ) . '/' ), 'flag off keeps ?organizer= URLs' );
LNKup_Marketing_Flags::override( 'seo_index_policy', true );

echo "Diagnostics\n";
$diag  = LNKup_SEO_Diagnostics::run( true );
$codes = wp_list_pluck( $diag['issues'], 'code' );
lnkup_t_assert( in_array( 'events_indexable', $codes, true ), 'diagnostics reports indexable events' );
lnkup_t_assert( ! in_array( 'policy_off', $codes, true ), 'policy on is not flagged' );

lnkup_m_finish();
