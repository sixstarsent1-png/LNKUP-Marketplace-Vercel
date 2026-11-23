<?php
/**
 * Enable the Organizer Portfolio OS for the seeded e2e organizer only (run after seed.php).
 * The global flag stays off so other organizers keep the existing experience.
 * Local wp-env only.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/seed-portfolio.php
 *
 * @package LNKup_Marketplace
 */

$organizer = get_user_by( 'login', 'e2e_org_a' );
if ( ! $organizer ) {
	echo "Run seed.php first (e2e_org_a missing).\n";
	return;
}
LNKup_Portfolio_Tables::maybe_upgrade();
$settings = get_option( 'lnkup_settings', array() );
$settings = is_array( $settings ) ? $settings : array();
$allow    = array_filter( array_map( 'absint', explode( ',', (string) ( $settings['portfolio_os_user_ids'] ?? '' ) ) ) );
$allow[]  = (int) $organizer->ID;
$settings['portfolio_os_enabled']     = false;
$settings['portfolio_os_user_ids']    = implode( ',', array_unique( $allow ) );
$settings['matching_enabled']         = true;
$settings['matching_tours_enabled']   = true;
$settings['tour_multi_apply_enabled'] = true;
update_option( 'lnkup_settings', $settings, false );
echo 'Portfolio OS enabled for e2e_org_a (#' . (int) $organizer->ID . ") via allowlist.\n";

// A recurring series next to seed.php's multi-city tour: four weekly dates, same-time-zone rule.
wp_set_current_user( (int) $organizer->ID );
$series = get_posts( array( 'post_type' => 'lnkup_tour', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_lnkup_e2e_key', 'meta_value' => 'series:weekly-market' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
$series_id = $series ? (int) $series[0] : 0;
if ( ! $series_id ) {
	$req = new WP_REST_Request( 'POST', '/lnkup/v1/portfolio/tours' );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'title' => 'E2E Weekly Makers Market', 'type' => 'recurring' ) ) );
	$res       = rest_do_request( $req );
	$series_id = (int) ( $res->get_data()['id'] ?? 0 );
	if ( $series_id ) {
		update_post_meta( $series_id, '_lnkup_e2e_key', 'series:weekly-market' );
	}
}
if ( $series_id && ! LNKup_Tours::stop_event_ids( $series_id ) ) {
	$req = new WP_REST_Request( 'POST', "/lnkup/v1/portfolio/tours/{$series_id}/recurrence/generate" );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_header( 'idempotency_key', 'e2e-series-' . $series_id );
	$req->set_body(
		wp_json_encode(
			array(
				'rule' => array(
					'frequency'  => 'weekly',
					'weekdays'   => array( 6 ),
					'start_date' => gmdate( 'Y-m-d', strtotime( '+21 days' ) ),
					'count'      => 4,
					'start_time' => '10:00',
					'timezone'   => 'America/New_York',
					'stop'       => array( 'city' => 'Charlotte', 'state' => 'NC', 'venue_name' => 'South End Lot' ),
				),
			)
		)
	);
	$gen = rest_do_request( $req );
	echo 'Series occurrences: HTTP ' . (int) $gen->get_status() . "\n";
}
wp_set_current_user( 0 );

$env_file = __DIR__ . '/.env.e2e';
if ( $series_id && file_exists( $env_file ) ) {
	$lines   = array_filter( explode( "\n", (string) file_get_contents( $env_file ) ), static function ( $line ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return '' !== trim( $line ) && 0 !== strpos( $line, 'PLAYWRIGHT_SERIES_ID=' );
	} );
	$lines[] = 'PLAYWRIGHT_SERIES_ID=' . $series_id;
	file_put_contents( $env_file, implode( "\n", $lines ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
	echo 'PLAYWRIGHT_SERIES_ID=' . $series_id . ' (' . count( LNKup_Tours::stop_event_ids( $series_id ) ) . " dates)\n";
}
