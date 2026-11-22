<?php
/**
 * Tour-wide expressions of interest and invitations to specific stops.
 *
 * Interest is a signal only: it is never an application, approval or booking. Organizers turn it
 * into action by inviting the vendor to chosen stops through LNKup_Vendor_Invites, after which the
 * vendor applies to each stop normally.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Portfolio_Interest {

	const MAX_INVITES_PER_REQUEST = 200;

	/**
	 * Record (or update) a vendor's interest in a published tour.
	 *
	 * @param int    $tour_id Tour.
	 * @param int    $user_id Vendor.
	 * @param string $note    Optional note.
	 * @return array|WP_Error
	 */
	public static function express( $tour_id, $user_id, $note = '' ) {
		if ( 'lnkup_tour' !== get_post_type( $tour_id ) || 'publish' !== get_post_status( $tour_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Tour not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! LNKup_Roles::is_vendor( $user_id ) || ! lnkup_get_vendor_profile_id( $user_id ) ) {
			return new WP_Error( 'lnkup_vendor_profile_required', __( 'Create your vendor profile first.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		global $wpdb;
		$table = LNKup_Portfolio_Tables::interest_table();
		$now   = current_time( 'mysql' );
		$note  = mb_substr( sanitize_textarea_field( (string) $note ), 0, 500 );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (tour_id, vendor_user_id, status, note, created_at, updated_at) VALUES (%d, %d, 'interested', %s, %s, %s)
				ON DUPLICATE KEY UPDATE status = 'interested', note = VALUES(note), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $tour_id ),
				absint( $user_id ),
				$note,
				$now,
				$now
			)
		);
		$organizer = (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true );
		if ( $organizer && class_exists( 'LNKup_Organizer_CRM' ) ) {
			LNKup_Organizer_CRM::ensure_relationship( $organizer, (int) $user_id );
		}
		do_action( 'lnkup_tour_interest_expressed', (int) $tour_id, (int) $user_id );
		return self::status_for( $tour_id, $user_id );
	}

	/**
	 * @param int $tour_id Tour.
	 * @param int $user_id Vendor.
	 */
	public static function withdraw( $tour_id, $user_id ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			LNKup_Portfolio_Tables::interest_table(),
			array( 'status' => 'withdrawn', 'updated_at' => current_time( 'mysql' ) ),
			array( 'tour_id' => absint( $tour_id ), 'vendor_user_id' => absint( $user_id ) ),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);
		return self::status_for( $tour_id, $user_id );
	}

	/**
	 * @param int $tour_id Tour.
	 * @param int $user_id Vendor.
	 */
	public static function status_for( $tour_id, $user_id ) {
		global $wpdb;
		$table  = LNKup_Portfolio_Tables::interest_table();
		$status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE tour_id = %d AND vendor_user_id = %d", absint( $tour_id ), absint( $user_id ) ) ); // phpcs:ignore WordPress.DB
		return array(
			'tour_id'    => (int) $tour_id,
			'interested' => 'interested' === $status,
			'status'     => '' !== $status ? $status : 'none',
		);
	}

	/**
	 * Interested vendors for the organizer, with the stops each one already applied to.
	 *
	 * @param int $tour_id Tour.
	 */
	public static function list_for_tour( $tour_id ) {
		global $wpdb;
		$table = LNKup_Portfolio_Tables::interest_table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE tour_id = %d AND status = 'interested' ORDER BY updated_at DESC LIMIT 500", absint( $tour_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$stops = LNKup_Tours::stop_event_ids( $tour_id );
		$items = array();
		foreach ( $rows as $row ) {
			$vendor_id  = (int) $row['vendor_user_id'];
			$profile_id = (int) lnkup_get_vendor_profile_id( $vendor_id );
			$applied    = array();
			if ( $stops ) {
				$applied = array_map(
					'intval',
					array_unique(
						array_map(
							static function ( $app_id ) {
								return (int) get_post_meta( $app_id, '_lnkup_event_id', true );
							},
							get_posts(
								array(
									'post_type'      => 'lnkup_application',
									'post_status'    => 'publish',
									'posts_per_page' => 100,
									'fields'         => 'ids',
									'no_found_rows'  => true,
									'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
										array( 'key' => '_lnkup_vendor_user_id', 'value' => (string) $vendor_id ),
										array( 'key' => '_lnkup_event_id', 'value' => array_map( 'strval', $stops ), 'compare' => 'IN' ),
									),
								)
							)
						)
					)
				);
			}
			$items[] = array(
				'vendor_user_id'    => $vendor_id,
				'vendor_profile_id' => $profile_id,
				'business_name'     => $profile_id ? get_the_title( $profile_id ) : '',
				'note'              => (string) $row['note'],
				'since'             => (string) $row['created_at'],
				'applied_event_ids' => array_values( $applied ),
			);
		}
		return $items;
	}

	/**
	 * Invite vendors to selected stops (e.g. a Houston vendor to Atlanta). Each invite is a normal
	 * LNKup_Vendor_Invites record; nothing is approved or charged.
	 *
	 * @param int    $tour_id      Tour.
	 * @param int[]  $vendor_ids   Vendor user IDs.
	 * @param int[]  $event_ids    Stops.
	 * @param string $message      Optional note.
	 * @param int    $organizer_id Owner.
	 * @return array|WP_Error
	 */
	public static function invite( $tour_id, array $vendor_ids, array $event_ids, $message, $organizer_id ) {
		$vendor_ids = array_values( array_unique( array_filter( array_map( 'absint', $vendor_ids ) ) ) );
		$event_ids  = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );
		if ( ! $vendor_ids || ! $event_ids ) {
			return new WP_Error( 'lnkup_invite_missing', __( 'Choose vendors and at least one stop.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( count( $vendor_ids ) * count( $event_ids ) > self::MAX_INVITES_PER_REQUEST ) {
			return new WP_Error( 'lnkup_too_many_invites', sprintf( __( 'Send up to %d invites at a time.', 'lnkup-marketplace' ), self::MAX_INVITES_PER_REQUEST ), array( 'status' => 400 ) );
		}
		foreach ( $event_ids as $event_id ) {
			if ( ! LNKup_Capabilities::event_is_tour_stop( $tour_id, $event_id ) || 'published' !== LNKup_Portfolio_Tours::stop_state( $event_id ) ) {
				return new WP_Error( 'lnkup_stop_not_open', sprintf( __( '"%s" is not a live stop on this tour.', 'lnkup-marketplace' ), get_the_title( $event_id ) ), array( 'status' => 400 ) );
			}
		}
		$results = array();
		foreach ( $vendor_ids as $vendor_id ) {
			foreach ( $event_ids as $event_id ) {
				$created   = LNKup_Vendor_Invites::create(
					(int) $organizer_id,
					$vendor_id,
					array(
						'event_id' => $event_id,
						'type'     => 'apply',
						'message'  => (string) $message,
					)
				);
				$results[] = array(
					'vendor_user_id' => $vendor_id,
					'event_id'       => $event_id,
					'result'         => is_wp_error( $created ) ? ( 'invite_exists' === $created->get_error_code() ? 'already_invited' : 'failed' ) : 'invited',
					'message'        => is_wp_error( $created ) ? $created->get_error_message() : '',
				);
			}
		}
		$tally = array_count_values( wp_list_pluck( $results, 'result' ) );
		LNKup_Portfolio_Audit::log( 'tour', $tour_id, 'vendors_invited', $tally, $organizer_id );
		return array(
			'results' => $results,
			'invited' => (int) ( $tally['invited'] ?? 0 ),
			'already' => (int) ( $tally['already_invited'] ?? 0 ),
			'failed'  => (int) ( $tally['failed'] ?? 0 ),
		);
	}
}
