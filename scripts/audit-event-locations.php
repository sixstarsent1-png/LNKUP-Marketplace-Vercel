<?php
/**
 * Dry-run audit: duplicated events that may still carry their source event's location.
 *
 * Flags an event when it was created by duplicate_event() (`_lnkup_duplicated_from`),
 * its stored city/state match the source, and its title names a different known city.
 * Read-only. Prints a report and never writes. Fix flagged events one at a time through
 * the event edit form (?event=ID&edit=1).
 *
 * Usage: wp eval-file scripts/audit-event-locations.php [--format=csv] [--fix-markets]
 *
 * Also reports copies whose city differs from their source but still carry the source event's
 * `lnkup_market` terms (copied by duplicate_event before the clone fix). `--fix-markets` removes
 * only those stale terms; the market itself comes from the validated location (`_lnkup_market_slug`).
 * It never infers a city from a title and never edits city, state or coordinates.
 *
 * No strict_types declaration: wp eval-file evaluates this file, where the declaration is not allowed.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

$lnkup_audit_format = in_array( '--format=csv', (array) ( $args ?? array() ), true ) ? 'csv' : 'table';

$lnkup_audit_events = get_posts(
	array(
		'post_type'      => 'lnkup_event',
		'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);

$lnkup_audit_cities = array();
foreach ( $lnkup_audit_events as $lnkup_audit_id ) {
	$lnkup_audit_city = trim( (string) get_post_meta( $lnkup_audit_id, '_lnkup_city', true ) );
	if ( strlen( $lnkup_audit_city ) >= 4 ) {
		$lnkup_audit_cities[ strtolower( $lnkup_audit_city ) ] = $lnkup_audit_city;
	}
}

$lnkup_audit_rows = array();
foreach ( $lnkup_audit_events as $lnkup_audit_id ) {
	$lnkup_audit_source = (int) get_post_meta( $lnkup_audit_id, '_lnkup_duplicated_from', true );
	if ( ! $lnkup_audit_source ) {
		continue;
	}
	$lnkup_audit_city   = trim( (string) get_post_meta( $lnkup_audit_id, '_lnkup_city', true ) );
	$lnkup_audit_state  = trim( (string) get_post_meta( $lnkup_audit_id, '_lnkup_state', true ) );
	$lnkup_audit_scity  = trim( (string) get_post_meta( $lnkup_audit_source, '_lnkup_city', true ) );
	$lnkup_audit_sstate = trim( (string) get_post_meta( $lnkup_audit_source, '_lnkup_state', true ) );
	if ( 0 !== strcasecmp( $lnkup_audit_city, $lnkup_audit_scity ) || 0 !== strcasecmp( $lnkup_audit_state, $lnkup_audit_sstate ) ) {
		continue;
	}
	$lnkup_audit_title = (string) get_the_title( $lnkup_audit_id );
	foreach ( $lnkup_audit_cities as $lnkup_audit_key => $lnkup_audit_name ) {
		if ( $lnkup_audit_key === strtolower( $lnkup_audit_city ) ) {
			continue;
		}
		if ( preg_match( '/\b' . preg_quote( $lnkup_audit_key, '/' ) . '\b/i', $lnkup_audit_title ) ) {
			$lnkup_audit_rows[] = array(
				'event_id'      => $lnkup_audit_id,
				'title'         => $lnkup_audit_title,
				'stored_place'  => trim( $lnkup_audit_city . ', ' . $lnkup_audit_state, ', ' ),
				'title_city'    => $lnkup_audit_name,
				'source_event'  => $lnkup_audit_source,
				'organizer_id'  => (int) get_post_meta( $lnkup_audit_id, '_lnkup_organizer_user_id', true ),
				'event_date'    => (string) get_post_meta( $lnkup_audit_id, '_lnkup_event_date', true ),
				'edit_url'      => add_query_arg( array( 'event' => $lnkup_audit_id, 'edit' => 1 ), function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'organizer-dashboard' ) : home_url( '/organizer-dashboard/' ) ),
			);
			break;
		}
	}
}

$lnkup_audit_out = defined( 'WP_CLI' ) && WP_CLI ? 'WP_CLI' : '';

$lnkup_audit_fix_markets = in_array( '--fix-markets', (array) ( $args ?? array() ), true );
$lnkup_audit_markets     = array();
foreach ( $lnkup_audit_events as $lnkup_audit_id ) {
	$lnkup_audit_source = (int) get_post_meta( $lnkup_audit_id, '_lnkup_duplicated_from', true );
	if ( ! $lnkup_audit_source ) {
		continue;
	}
	$lnkup_audit_moved = 0 !== strcasecmp( trim( (string) get_post_meta( $lnkup_audit_id, '_lnkup_city', true ) ), trim( (string) get_post_meta( $lnkup_audit_source, '_lnkup_city', true ) ) )
		|| 0 !== strcasecmp( trim( (string) get_post_meta( $lnkup_audit_id, '_lnkup_state', true ) ), trim( (string) get_post_meta( $lnkup_audit_source, '_lnkup_state', true ) ) );
	if ( ! $lnkup_audit_moved ) {
		continue;
	}
	$lnkup_audit_own = wp_get_object_terms( $lnkup_audit_id, 'lnkup_market', array( 'fields' => 'ids' ) );
	$lnkup_audit_src = wp_get_object_terms( $lnkup_audit_source, 'lnkup_market', array( 'fields' => 'ids' ) );
	if ( is_wp_error( $lnkup_audit_own ) || is_wp_error( $lnkup_audit_src ) ) {
		continue;
	}
	$lnkup_audit_stale = array_values( array_intersect( array_map( 'intval', $lnkup_audit_own ), array_map( 'intval', $lnkup_audit_src ) ) );
	if ( ! $lnkup_audit_stale ) {
		continue;
	}
	if ( $lnkup_audit_fix_markets ) {
		wp_remove_object_terms( $lnkup_audit_id, $lnkup_audit_stale, 'lnkup_market' );
	}
	$lnkup_audit_markets[] = array(
		'event_id'      => $lnkup_audit_id,
		'place'         => trim( get_post_meta( $lnkup_audit_id, '_lnkup_city', true ) . ', ' . get_post_meta( $lnkup_audit_id, '_lnkup_state', true ), ', ' ),
		'stale_markets' => implode( ',', $lnkup_audit_stale ),
		'source_event'  => $lnkup_audit_source,
		'action'        => $lnkup_audit_fix_markets ? 'removed' : 'dry-run',
	);
}
if ( $lnkup_audit_markets ) {
	if ( 'WP_CLI' === $lnkup_audit_out ) {
		WP_CLI\Utils\format_items( $lnkup_audit_format, $lnkup_audit_markets, array_keys( $lnkup_audit_markets[0] ) );
		WP_CLI::warning( sprintf( '%d copied event(s) carry their source market. %s', count( $lnkup_audit_markets ), $lnkup_audit_fix_markets ? 'Stale market terms removed.' : 'Dry run; re-run with --fix-markets to remove them.' ) );
	} else {
		foreach ( $lnkup_audit_markets as $lnkup_audit_row ) {
			echo esc_html( implode( "\t", $lnkup_audit_row ) ) . "\n";
		}
	}
}

if ( ! $lnkup_audit_rows ) {
	$lnkup_audit_msg = sprintf( 'Checked %d events. No duplicated event looks like it kept its source location. Nothing to change.', count( $lnkup_audit_events ) );
	'WP_CLI' === $lnkup_audit_out ? WP_CLI::success( $lnkup_audit_msg ) : print( $lnkup_audit_msg . "\n" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return;
}

if ( 'WP_CLI' === $lnkup_audit_out ) {
	WP_CLI\Utils\format_items( $lnkup_audit_format, $lnkup_audit_rows, array_keys( $lnkup_audit_rows[0] ) );
	WP_CLI::warning( sprintf( '%d event(s) flagged. Dry run only — no data was changed. Correct each event explicitly from its edit_url.', count( $lnkup_audit_rows ) ) );
	return;
}
foreach ( $lnkup_audit_rows as $lnkup_audit_row ) {
	echo esc_html( implode( "\t", $lnkup_audit_row ) ) . "\n";
}
