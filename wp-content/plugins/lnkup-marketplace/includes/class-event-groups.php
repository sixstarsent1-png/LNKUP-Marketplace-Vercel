<?php
/**
 * Event-grouped discovery contract (GroupedEventSummary).
 *
 * Opportunities stay independent records with their own fee, capacity, deadline and rules.
 * This class only groups already-formatted opportunity cards under their canonical event so
 * clients show one card per event. Every client (web, Next.js, native) should use it instead
 * of grouping locally.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Groups {

	/** Upper bound of index rows scanned to paginate by event. */
	const MAX_SCAN = 2000;

	/**
	 * Group key: the event when known, otherwise the opportunity itself.
	 *
	 * @param array<string,mixed> $item Opportunity card.
	 */
	public static function key( array $item ): string {
		$event_id = (int) ( $item['event_id'] ?? 0 );
		$opp_id   = (int) ( $item['opportunity_id'] ?? $item['id'] ?? 0 );
		return $event_id > 0 ? 'e' . $event_id : 'o' . $opp_id;
	}

	/**
	 * Group opportunity cards into event summaries, keeping first-seen order.
	 *
	 * @param array<int,array<string,mixed>> $items Opportunity cards.
	 * @return array<int,array<string,mixed>>
	 */
	public static function group( array $items ): array {
		$out = array();
		foreach ( lnkup_group_opportunities_by_event( $items ) as $slots ) {
			$summary = self::summarize( $slots );
			if ( $summary ) {
				$out[] = $summary;
			}
		}
		return $out;
	}

	/**
	 * One option (vendor slot) inside an event summary.
	 *
	 * @param array<string,mixed> $item Opportunity card.
	 * @return array<string,mixed>
	 */
	public static function option( array $item ): array {
		$gated      = ! empty( $item['access']['gated'] );
		$categories = array_values( array_filter( array_map( 'strval', (array) ( $item['categories'] ?? array() ) ) ) );
		$title      = trim( (string) ( $item['opportunity_title'] ?? '' ) );
		if ( '' === $title || 0 === strcasecmp( $title, (string) ( $item['name'] ?? '' ) ) ) {
			$title = $categories ? implode( ' · ', $categories ) : __( 'Vendor space', 'lnkup-marketplace' );
		}
		$unlimited = array_key_exists( 'spots_unlimited', $item ) ? $item['spots_unlimited'] : (int) ( $item['total_spots'] ?? 0 ) <= 0;
		$deadline  = (string) ( $item['deadline'] ?? '' );
		return array(
			'opportunity_id'     => (int) ( $item['opportunity_id'] ?? $item['id'] ?? 0 ),
			'title'              => $title,
			'categories'         => $categories,
			'vendor_fee'         => $gated ? null : ( isset( $item['vendor_fee'] ) ? (float) $item['vendor_fee'] : null ),
			'fee_label'          => $gated ? null : ( $item['fee_label'] ?? null ),
			'spots_remaining'    => $gated ? null : ( isset( $item['spots_remaining'] ) ? (int) $item['spots_remaining'] : null ),
			'total_spots'        => $gated ? null : ( isset( $item['total_spots'] ) ? (int) $item['total_spots'] : null ),
			'spots_unlimited'    => $gated ? null : (bool) $unlimited,
			'is_open'            => (bool) ( $item['is_open'] ?? false ),
			'deadline'           => '' !== $deadline ? $deadline : null,
			'deadline_label'     => (string) ( $item['deadline_label'] ?? '' ),
			'application_status' => (string) ( $item['application_status'] ?? '' ),
			'can_apply_again'    => (bool) ( $item['can_apply_again'] ?? false ),
			'instant_approval'   => (bool) ( $item['instant_approval'] ?? false ),
			'document_badges'    => array_values( (array) ( $item['document_badges'] ?? array() ) ),
			'power_available'    => (bool) ( $item['power_available'] ?? false ),
			'water_available'    => (bool) ( $item['water_available'] ?? false ),
			'indoor'             => (bool) ( $item['indoor'] ?? false ),
			'outdoor'            => (bool) ( $item['outdoor'] ?? false ),
			'load_in_time'       => (string) ( $item['load_in_time'] ?? '' ),
		);
	}

	/**
	 * Organizer identity for a summary (public business name before the account name).
	 *
	 * @param int                 $event_id Event ID.
	 * @param array<string,mixed> $first    First opportunity card.
	 * @return array{id:int,name:string,verified:bool}
	 */
	private static function organizer( int $event_id, array $first ): array {
		$org_id = (int) ( $first['organizer_id'] ?? 0 );
		$name   = $event_id && class_exists( 'LNKup_Vendor_Booking_Contract' ) ? LNKup_Vendor_Booking_Contract::organizer_name( $event_id ) : '';
		if ( '' === $name ) {
			$name = (string) ( $first['organizer_name'] ?? '' );
		}
		return array(
			'id'       => $org_id,
			'name'     => $name,
			'verified' => (bool) ( $first['organizer_verified'] ?? false ),
		);
	}

	/**
	 * Summary for one event from its opportunity cards.
	 *
	 * @param array<int,array<string,mixed>> $slots Opportunity cards for one event.
	 * @return array<string,mixed>
	 */
	public static function summarize( array $slots ): array {
		$slots = array_values( array_filter( $slots, 'is_array' ) );
		if ( ! $slots ) {
			return array();
		}
		$first    = $slots[0];
		$event_id = (int) ( $first['event_id'] ?? 0 );
		$event    = $event_id ? get_post( $event_id ) : null;
		$date     = (string) ( $first['date'] ?? '' );
		$venue    = (string) ( $first['venue_name'] ?? '' );
		$org      = self::organizer( $event_id, $first );

		$title = $event instanceof WP_Post && function_exists( 'lnkup_plain_title' ) ? lnkup_plain_title( $event ) : (string) ( $first['event_name'] ?? $first['name'] ?? '' );
		if ( function_exists( 'lnkup_display_event_title' ) ) {
			$title = lnkup_display_event_title( $title, $date, $venue, $org['name'] );
		}

		$options       = array_map( array( __CLASS__, 'option' ), $slots );
		$gated         = ! empty( $first['access']['gated'] );
		$open          = array_values( array_filter( $options, static function ( $o ) { return ! empty( $o['is_open'] ); } ) );
		$priced_pool   = $open ? $open : $options;
		$fees          = array();
		foreach ( $priced_pool as $o ) {
			if ( null !== $o['vendor_fee'] && $o['vendor_fee'] > 0 ) {
				$fees[] = (float) $o['vendor_fee'];
			}
		}
		$categories = array();
		foreach ( $options as $o ) {
			foreach ( $o['categories'] as $cat ) {
				$categories[ strtolower( $cat ) ] = $cat;
			}
		}
		$full       = 0;
		$open_spots = 0;
		$unlimited  = false;
		foreach ( $options as $o ) {
			if ( $gated ) {
				break;
			}
			if ( $o['spots_unlimited'] ) {
				$unlimited = $unlimited || $o['is_open'];
				continue;
			}
			if ( (int) $o['spots_remaining'] <= 0 ) {
				++$full;
			} elseif ( $o['is_open'] ) {
				$open_spots += (int) $o['spots_remaining'];
			}
		}
		$deadlines = array();
		foreach ( $open as $o ) {
			if ( ! empty( $o['deadline'] ) ) {
				$deadlines[] = (string) $o['deadline'];
			}
		}
		sort( $deadlines );
		$distance = null;
		$status   = '';
		$band     = null;
		$indoor   = false;
		$outdoor  = false;
		foreach ( $slots as $s ) {
			if ( isset( $s['distance_miles'] ) && ( null === $distance || $s['distance_miles'] < $distance ) ) {
				$distance = (float) $s['distance_miles'];
			}
			if ( '' === $status && ! empty( $s['application_status'] ) ) {
				$status = (string) $s['application_status'];
			}
			if ( null === $band && ! empty( $s['match_band'] ) ) {
				$band = $s['match_band'];
			}
			$indoor  = $indoor || ! empty( $s['indoor'] );
			$outdoor = $outdoor || ! empty( $s['outdoor'] );
		}
		$primary = $open ? (int) $open[0]['opportunity_id'] : (int) $options[0]['opportunity_id'];

		return array(
			'item_type'            => 'event_group',
			'key'                  => self::key( $first ),
			'event_id'             => $event_id,
			'opportunity_id'       => $primary,
			'opportunity_ids'      => array_map( 'intval', wp_list_pluck( $options, 'opportunity_id' ) ),
			'title'                => $title,
			'date'                 => '' !== $date ? $date : null,
			'end_date'             => $event_id ? ( (string) get_post_meta( $event_id, '_lnkup_event_end_date', true ) ?: null ) : null,
			'start_time'           => $event_id ? ( (string) get_post_meta( $event_id, '_lnkup_start_time', true ) ?: null ) : null,
			'city'                 => function_exists( 'lnkup_display_label' ) ? lnkup_display_label( (string) ( $first['city'] ?? '' ) ) : (string) ( $first['city'] ?? '' ),
			'state'                => function_exists( 'lnkup_display_state' ) ? lnkup_display_state( (string) ( $first['state'] ?? '' ) ) : (string) ( $first['state'] ?? '' ),
			'place'                => function_exists( 'lnkup_display_place' ) ? lnkup_display_place( (string) ( $first['city'] ?? '' ), (string) ( $first['state'] ?? '' ) ) : '',
			'venue_name'           => $venue,
			'lat'                  => $first['lat'] ?? null,
			'lng'                  => $first['lng'] ?? null,
			'organizer'            => $org,
			'event_types'          => array_values( (array) ( $first['event_types'] ?? array() ) ),
			'attendance'           => ! empty( $first['attendance'] ) ? (int) $first['attendance'] : null,
			'attendance_source'    => ! empty( $first['attendance'] ) ? 'organizer' : null,
			'media'                => $first['media'] ?? null,
			'featured_image'       => (string) ( $first['featured_image'] ?? '' ),
			'category_fallback_url'=> (string) ( $first['category_fallback_url'] ?? '' ),
			'image_alt'            => (string) ( $first['image_alt'] ?? $title ),
			'listing_status'       => $first['listing_status'] ?? null,
			'badges'               => array_values( (array) ( $first['badges'] ?? array() ) ),
			'gated'                => $gated,
			'from_price'           => $gated || ! $fees ? null : min( $fees ),
			'price_max'            => $gated || ! $fees ? null : max( $fees ),
			'categories'           => array_values( $categories ),
			'availability'         => array(
				'total_options' => count( $options ),
				'open_options'  => count( $open ),
				'full_options'  => $gated ? null : $full,
				'open_spots'    => $gated ? null : $open_spots,
				'has_unlimited' => $gated ? null : $unlimited,
			),
			'deadline'             => $deadlines ? $deadlines[0] : null,
			'environment'          => array( 'indoor' => $indoor, 'outdoor' => $outdoor ),
			'distance_miles'       => $distance,
			'match_band'           => $band,
			'application_status'   => $status,
			'options'              => $options,
		);
	}

	/**
	 * Full event with every listed option, including full or closed ones, for the event screen.
	 *
	 * @param int $event_id Event ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function event_detail( int $event_id ) {
		global $wpdb;
		if ( $event_id < 1 || 'lnkup_event' !== get_post_type( $event_id ) || 'publish' !== get_post_status( $event_id ) ) {
			return new WP_Error( 'lnkup_event_not_found', __( 'Event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$table = LNKup_Marketplace_Index::table_name();
		$where = $wpdb->prepare( "{$table}.event_id = %d", $event_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( class_exists( 'LNKup_Access_Policy' ) && LNKup_Access_Policy::exclude_invite_only() ) {
			$where .= ' AND ' . LNKup_Access_Policy::invite_only_exclusion_sql( $table );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY vendor_fee ASC, opportunity_id ASC LIMIT 50", ARRAY_A );
		if ( class_exists( 'LNKup_Event_Media' ) ) {
			LNKup_Event_Media::prime( array( $event_id ) );
		}
		$items   = array();
		$indexed = array();
		foreach ( (array) $rows as $row ) {
			$item = lnkup_get_opportunity_card_data( (int) $row['opportunity_id'], $row );
			if ( $item ) {
				$items[]                                   = $item;
				$indexed[ (int) $row['opportunity_id'] ] = true;
			}
		}
		// Full and closed options are not in the marketplace index; list them as not open so a
		// vendor still sees the option they applied to and why it can't take new applications.
		foreach ( self::unlisted_options( $event_id, $indexed ) as $item ) {
			$items[] = $item;
		}
		if ( ! $items ) {
			return new WP_Error( 'lnkup_event_not_found', __( 'This event has no vendor opportunities listed right now.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$summary = self::summarize( $items );
		if ( ! $summary ) {
			return new WP_Error( 'lnkup_event_not_found', __( 'Event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$event = get_post( $event_id );
		$body  = $event instanceof WP_Post ? trim( wp_strip_all_tags( (string) $event->post_content ) ) : '';
		if ( '' === $body ) {
			$body = (string) ( $items[0]['description'] ?? '' );
		}
		$summary['description']   = $body;
		$summary['end_time']      = (string) get_post_meta( $event_id, '_lnkup_end_time', true ) ?: null;
		$summary['venue_address'] = get_post_meta( $event_id, '_lnkup_venue_hidden', true ) ? '' : (string) get_post_meta( $event_id, '_lnkup_venue_address', true );
		$summary['event_state']   = class_exists( 'LNKup_Vendor_Booking_Contract' ) ? LNKup_Vendor_Booking_Contract::event_state( $event_id ) : 'unknown';
		$summary['tour_id']       = (int) get_post_meta( $event_id, '_lnkup_tour_id', true );
		return $summary;
	}

	/**
	 * Published options of an event that the index does not hold (full, closed or past deadline),
	 * shaped like opportunity cards with `is_open` false. Invite-only options stay hidden from
	 * vendors who cannot see them in search.
	 *
	 * @param int               $event_id Event ID.
	 * @param array<int,bool>   $indexed  Opportunity IDs already listed.
	 * @return array<int,array<string,mixed>>
	 */
	private static function unlisted_options( int $event_id, array $indexed ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$gated    = class_exists( 'LNKup_Access_Policy' ) && LNKup_Access_Policy::is_guest();
		$statuses = function_exists( 'lnkup_current_vendor_application_statuses' ) ? lnkup_current_vendor_application_statuses() : array();
		$out      = array();
		foreach ( $ids as $opp_id ) {
			$opp_id = (int) $opp_id;
			if ( isset( $indexed[ $opp_id ] ) ) {
				continue;
			}
			$invite_only = (bool) get_post_meta( $opp_id, '_lnkup_invite_only', true );
			if ( $invite_only && LNKup_Access_Policy::exclude_invite_only() && ! isset( $statuses[ $opp_id ] ) ) {
				continue;
			}
			$terms      = wp_get_post_terms( $opp_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			$total      = (int) get_post_meta( $opp_id, '_lnkup_spots_total', true );
			$remaining  = (int) get_post_meta( $opp_id, '_lnkup_spots_remaining', true );
			$deadline   = (string) get_post_meta( $opp_id, '_lnkup_application_deadline', true );
			$item       = array(
				'opportunity_id'     => $opp_id,
				'id'                 => $opp_id,
				'event_id'           => $event_id,
				'name'               => (string) get_the_title( $event_id ),
				'opportunity_title'  => function_exists( 'lnkup_plain_title' ) ? lnkup_plain_title( $opp_id ) : (string) get_the_title( $opp_id ),
				'categories'         => is_wp_error( $terms ) ? array() : array_values( (array) $terms ),
				'vendor_fee'         => $gated ? null : (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ),
				'total_spots'        => $gated ? null : $total,
				'spots_remaining'    => $gated ? null : max( 0, $remaining ),
				'spots_unlimited'    => $gated ? null : $total <= 0,
				'is_open'            => false,
				'deadline'           => '' !== $deadline ? $deadline : null,
				'application_status' => (string) ( $statuses[ $opp_id ] ?? '' ),
				'date'               => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
				'city'               => (string) get_post_meta( $event_id, '_lnkup_city', true ),
				'state'              => (string) get_post_meta( $event_id, '_lnkup_state', true ),
				'venue_name'         => get_post_meta( $event_id, '_lnkup_venue_hidden', true ) ? '' : (string) get_post_meta( $event_id, '_lnkup_venue_name', true ),
				'organizer_id'       => (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ),
				'access'             => array( 'gated' => $gated ),
			);
			if ( class_exists( 'LNKup_Event_Media' ) ) {
				$media = LNKup_Event_Media::resolve( $event_id, $opp_id );
				if ( $media ) {
					$item['media']          = LNKup_Event_Media::to_rest( $media );
					$item['featured_image'] = (string) ( $item['media']['medium_url'] ?? $item['media']['url'] ?? '' );
				}
			}
			$out[] = $item;
		}
		return $out;
	}
}
