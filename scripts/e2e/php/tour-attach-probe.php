<?php
/**
 * Probe: attaching an existing event to a tour keeps its ID, URL and applications, is audited,
 * refuses another organizer's event, and is reversed with detach.
 *
 * Usage: wp eval-file scripts/e2e/php/tour-attach-probe.php
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

$org   = get_user_by( 'login', 'e2e_org_a' );
$org_b = get_user_by( 'login', 'e2e_org_b' );
if ( ! $org ) {
	WP_CLI::error( 'e2e_org_a not found. Run scripts/e2e/seed.php.' );
}
wp_set_current_user( $org->ID );
$tour = get_posts( array( 'post_type' => 'lnkup_tour', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_lnkup_organizer_user_id', 'meta_value' => (string) $org->ID ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
if ( ! $tour ) {
	WP_CLI::error( 'No tour for e2e_org_a.' );
}
$tour_id = (int) $tour[0];
$event   = 0;
foreach ( LNKup_Organizer_Presentation::event_ids( (int) $org->ID ) as $candidate ) {
	if ( ! (int) get_post_meta( $candidate, '_lnkup_tour_id', true ) ) {
		$event = $candidate;
		break;
	}
}
if ( ! $event ) {
	WP_CLI::error( 'No free event to attach.' );
}
$fails = 0;
$check = static function ( $ok, $label ) use ( &$fails ) {
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $label );
	if ( ! $ok ) {
		$fails++;
	}
};

$before_url  = get_permalink( $event );
$before_apps = LNKup_Organizer_Presentation::status_counts( array( $event ) )[ $event ] ?? array();

$result = LNKup_Portfolio_Tours::attach_events( $tour_id, array( $event ), (int) $org->ID );
$check( ! is_wp_error( $result ) && in_array( $event, (array) ( $result['attached'] ?? array() ), true ), 'event attached' );
$check( $tour_id === (int) get_post_meta( $event, '_lnkup_tour_id', true ), 'tour linkage meta set' );
$check( 'lnkup_event' === get_post_type( $event ) && get_permalink( $event ) === $before_url, 'event ID and URL unchanged' );
$after_apps = LNKup_Organizer_Presentation::status_counts( array( $event ) )[ $event ] ?? array();
$check( $before_apps == $after_apps, 'application counts unchanged' ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual

global $wpdb;
$audit_table = class_exists( 'LNKup_Portfolio_Tables' ) && method_exists( 'LNKup_Portfolio_Tables', 'audit_table' ) ? LNKup_Portfolio_Tables::audit_table() : '';
if ( $audit_table ) {
	$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit_table} WHERE action = %s AND object_id = %d", 'stop_attached', $event ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( $found > 0, 'audit entry written' );
}

$again = LNKup_Portfolio_Tours::attach_events( $tour_id, array( $event ), (int) $org->ID );
$check( ! is_wp_error( $again ) && ! $again['attached'] && 'already_in_tour' === ( $again['skipped'][0]['reason'] ?? '' ), 'attaching twice is a no-op' );

if ( $org_b ) {
	$b_event = LNKup_Organizer_Presentation::event_ids( (int) $org_b->ID );
	if ( $b_event ) {
		$foreign = LNKup_Portfolio_Tours::attach_events( $tour_id, array( (int) $b_event[0] ), (int) $org->ID );
		$check( is_wp_error( $foreign ) && 'lnkup_forbidden' === $foreign->get_error_code(), "another organizer's event is refused" );
	}
}

$detached = LNKup_Portfolio_Tours::detach_stop( $tour_id, $event, (int) $org->ID );
$check( ! is_wp_error( $detached ) && ! (int) get_post_meta( $event, '_lnkup_tour_id', true ), 'detach restores the event' );

$fails ? WP_CLI::error( $fails . ' check(s) failed.' ) : WP_CLI::success( 'Tour attach probe passed.' );
