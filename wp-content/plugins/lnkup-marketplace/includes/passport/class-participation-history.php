<?php
/**
 * Evidence-based vendor participation history, one row per event occurrence.
 *
 * Milestones come only from canonical records (application status, check-in / no-show metadata).
 * An approval or payment is never presented as attendance.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Participation_History {

	const CACHE_PREFIX = 'lnkup_ph_';
	const CACHE_TTL    = 12 * HOUR_IN_SECONDS;

	/** Milestone rank (higher = further along). Terminal negatives are handled separately. */
	const RANK = array(
		'applied'                 => 1,
		'waitlisted'              => 1,
		'not_selected'            => 1,
		'approved'                => 2,
		'booking_confirmed'       => 3,
		'checked_in'              => 4,
		'completed'               => 5,
	);

	const APP_META_KEYS = array( '_lnkup_checked_in_at', '_lnkup_no_show_at', '_lnkup_cancellation_at', '_lnkup_event_completed_at', '_lnkup_application_status' );

	public static function init(): void {
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'flush_for_application' ), 5, 1 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
	}

	public static function state_labels(): array {
		return array(
			'applied'           => __( 'Applied', 'lnkup-marketplace' ),
			'waitlisted'        => __( 'Waitlisted', 'lnkup-marketplace' ),
			'not_selected'      => __( 'Not selected', 'lnkup-marketplace' ),
			'approved'          => __( 'Approved', 'lnkup-marketplace' ),
			'booking_confirmed' => __( 'Booking confirmed', 'lnkup-marketplace' ),
			'checked_in'        => __( 'Checked in', 'lnkup-marketplace' ),
			'completed'         => __( 'Participation confirmed', 'lnkup-marketplace' ),
			'cancelled'         => __( 'Canceled', 'lnkup-marketplace' ),
			'no_show'           => __( 'No-show recorded', 'lnkup-marketplace' ),
		);
	}

	/**
	 * @param int    $meta_id  Unused.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 */
	public static function on_meta( $meta_id, $post_id, $meta_key ): void {
		unset( $meta_id );
		if ( in_array( $meta_key, self::APP_META_KEYS, true ) && 'lnkup_application' === get_post_type( (int) $post_id ) ) {
			self::flush_for_application( (int) $post_id );
		}
	}

	public static function flush_for_application( $application_id ): void {
		$profile_id = (int) get_post_meta( (int) $application_id, '_lnkup_vendor_profile_id', true );
		if ( ! $profile_id ) {
			$profile_id = (int) lnkup_get_vendor_profile_id( (int) get_post_meta( (int) $application_id, '_lnkup_vendor_user_id', true ) );
		}
		if ( $profile_id ) {
			self::flush( $profile_id );
		}
	}

	public static function flush( int $profile_id ): void {
		delete_transient( self::CACHE_PREFIX . $profile_id );
		if ( class_exists( 'LNKup_Passport_Projection' ) ) {
			LNKup_Passport_Projection::flush_public_cache( $profile_id );
		}
	}

	/**
	 * All rows for a profile (owner view, unfiltered), newest event first. Cached.
	 *
	 * @param int $profile_id Profile ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function all_rows( int $profile_id ): array {
		$cached = get_transient( self::CACHE_PREFIX . $profile_id );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$vendor_id = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		if ( ! $vendor_id ) {
			return array();
		}
		$app_ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => 2000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_vendor_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $vendor_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( ! $app_ids ) {
			set_transient( self::CACHE_PREFIX . $profile_id, array(), self::CACHE_TTL );
			return array();
		}
		update_meta_cache( 'post', $app_ids );

		$by_event = array();
		foreach ( $app_ids as $app_id ) {
			$app_id   = (int) $app_id;
			$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
			if ( ! $event_id ) {
				$opp_id   = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
				$event_id = $opp_id ? (int) get_post_meta( $opp_id, '_lnkup_event_id', true ) : 0;
			}
			if ( ! $event_id ) {
				continue;
			}
			$milestone = self::milestone_for_application( $app_id );
			if ( ! isset( $by_event[ $event_id ] ) || self::better( $milestone, $by_event[ $event_id ] ) ) {
				$by_event[ $event_id ] = $milestone;
			}
		}
		if ( ! $by_event ) {
			set_transient( self::CACHE_PREFIX . $profile_id, array(), self::CACHE_TTL );
			return array();
		}
		$event_ids = array_keys( $by_event );
		_prime_post_caches( $event_ids, false, true );

		$tour_titles = array();
		$rows        = array();
		foreach ( $by_event as $event_id => $m ) {
			$event = get_post( $event_id );
			if ( ! $event || 'lnkup_event' !== $event->post_type ) {
				continue;
			}
			$tour_id = (int) get_post_meta( $event_id, '_lnkup_tour_id', true );
			if ( $tour_id && ! isset( $tour_titles[ $tour_id ] ) ) {
				$tour_titles[ $tour_id ] = lnkup_plain_title( $tour_id );
			}
			$organizer_id = (int) get_post_meta( $m['application_id'], '_lnkup_organizer_user_id', true ) ?: (int) $event->post_author;
			$organizer    = get_userdata( $organizer_id );
			$opp_id       = (int) get_post_meta( $m['application_id'], '_lnkup_opportunity_id', true );
			$rows[]       = array(
				'event_id'        => $event_id,
				'application_id'  => $m['application_id'],
				'event_title'     => $event->post_title,
				'event_date'      => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
				'city'            => (string) get_post_meta( $event_id, '_lnkup_city', true ),
				'state'           => (string) get_post_meta( $event_id, '_lnkup_state', true ),
				'organizer_id'    => $organizer_id,
				'organizer_name'  => $organizer ? $organizer->display_name : '',
				'tour_id'         => $tour_id,
				'tour_title'      => $tour_id ? (string) $tour_titles[ $tour_id ] : '',
				'tour_stop_order' => (int) get_post_meta( $event_id, '_lnkup_tour_stop_order', true ),
				'category'        => (string) get_post_meta( $m['application_id'], '_lnkup_selected_category', true ),
				'booth_type'      => $opp_id ? (string) get_post_meta( $opp_id, '_lnkup_booth_type_key', true ) : '',
				'state_key'       => $m['state'],
				'state_label'     => self::state_labels()[ $m['state'] ] ?? $m['state'],
				'milestones'      => $m['milestones'],
				'evidence'        => $m['evidence'],
				'confirmed'       => 'completed' === $m['state'],
				'checked_in'      => ! empty( $m['milestones']['checked_in'] ),
				'review_id'       => (int) get_post_meta( $m['application_id'], '_lnkup_review_id', true ),
				'event_url'       => (string) get_permalink( $event_id ),
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( (string) $b['event_date'], (string) $a['event_date'] );
			}
		);
		set_transient( self::CACHE_PREFIX . $profile_id, $rows, self::CACHE_TTL );
		return $rows;
	}

	/**
	 * Context-filtered, paginated history.
	 *
	 * - owner: everything.
	 * - organizer: confirmed participation platform-wide (respecting vendor privacy) + every row at that organizer's events.
	 * - public: confirmed participation only, honoring show_history and hidden_event_ids.
	 *
	 * @param int    $profile_id   Profile ID.
	 * @param string $context      owner|organizer|public.
	 * @param int    $viewer_id    Organizer user ID for organizer context.
	 * @param int    $page         1-based page.
	 * @param int    $per_page     Rows per page.
	 * @return array{items:array,total:int,page:int,per_page:int,tours:array,years:array}
	 */
	public static function for_profile( int $profile_id, string $context = 'public', int $viewer_id = 0, int $page = 1, int $per_page = 20 ): array {
		$rows    = self::all_rows( $profile_id );
		$privacy = class_exists( 'LNKup_Passport_Privacy' ) ? LNKup_Passport_Privacy::get( $profile_id ) : array( 'show_history' => true, 'hidden_event_ids' => array() );
		$hidden  = array_map( 'intval', (array) ( $privacy['hidden_event_ids'] ?? array() ) );

		$filtered = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $context, $viewer_id, $privacy, $hidden ) {
					if ( 'owner' === $context ) {
						return true;
					}
					$own_event = 'organizer' === $context && $viewer_id && (int) $row['organizer_id'] === $viewer_id;
					if ( $own_event ) {
						return true;
					}
					if ( empty( $privacy['show_history'] ) || in_array( (int) $row['event_id'], $hidden, true ) ) {
						return false;
					}
					return ! empty( $row['confirmed'] );
				}
			)
		);

		$items = array_map(
			static function ( $row ) use ( $context, $viewer_id ) {
				return self::shape_row( $row, $context, $viewer_id );
			},
			$filtered
		);

		$tours = array();
		$years = array();
		foreach ( $filtered as $row ) {
			if ( $row['tour_id'] && ! empty( $row['confirmed'] ) ) {
				$tours[ $row['tour_id'] ] = array(
					'tour_id'         => (int) $row['tour_id'],
					'title'           => $row['tour_title'],
					'confirmed_stops' => ( $tours[ $row['tour_id'] ]['confirmed_stops'] ?? 0 ) + 1,
				);
			}
			$year = substr( (string) $row['event_date'], 0, 4 );
			if ( '' !== $year && ctype_digit( $year ) ) {
				$years[ $year ] = ( $years[ $year ] ?? 0 ) + 1;
			}
		}
		krsort( $years );

		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );
		return array(
			'items'    => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
			'total'    => count( $items ),
			'page'     => $page,
			'per_page' => $per_page,
			'tours'    => array_values( $tours ),
			'years'    => $years,
		);
	}

	/**
	 * Aggregate stats from confirmed participation only (used for achievements and the Experience indicator).
	 *
	 * Each event occurrence counts once; tour stops are separate events and are never aggregated twice.
	 *
	 * @param int $profile_id Profile ID.
	 * @return array<string,int>
	 */
	public static function stats( int $profile_id ): array {
		$confirmed   = 0;
		$checked_in  = 0;
		$orgs        = array();
		$cities      = array();
		$tour_counts = array();
		$dates       = array();
		foreach ( self::all_rows( $profile_id ) as $row ) {
			if ( ! empty( $row['checked_in'] ) ) {
				++$checked_in;
			}
			if ( empty( $row['confirmed'] ) ) {
				continue;
			}
			++$confirmed;
			if ( $row['organizer_id'] ) {
				$orgs[ $row['organizer_id'] ] = ( $orgs[ $row['organizer_id'] ] ?? 0 ) + 1;
			}
			$city = strtolower( trim( $row['city'] . '|' . $row['state'] ) );
			if ( '|' !== $city ) {
				$cities[ $city ] = true;
			}
			if ( $row['tour_id'] ) {
				$tour_counts[ $row['tour_id'] ] = ( $tour_counts[ $row['tour_id'] ] ?? 0 ) + 1;
			}
			$ts = strtotime( (string) $row['event_date'] );
			if ( false !== $ts ) {
				$dates[] = $ts;
			}
		}
		return array(
			'confirmed_events'      => $confirmed,
			'checked_in_events'     => $checked_in,
			'distinct_organizers'   => count( $orgs ),
			'repeat_organizers'     => count( array_filter( $orgs, static fn( $n ) => $n >= 2 ) ),
			'distinct_cities'       => count( $cities ),
			'max_stops_in_one_tour' => $tour_counts ? max( $tour_counts ) : 0,
			'active_span_days'      => count( $dates ) >= 2 ? (int) floor( ( max( $dates ) - min( $dates ) ) / DAY_IN_SECONDS ) : 0,
		);
	}

	/**
	 * Milestones for one application.
	 *
	 * @param int $app_id Application ID.
	 * @return array{application_id:int,state:string,milestones:array<string,string>,evidence:string,rank:int}
	 */
	public static function milestone_for_application( int $app_id ): array {
		$status     = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		$submitted  = (string) get_post_meta( $app_id, '_lnkup_submitted_at', true );
		$approved   = (string) get_post_meta( $app_id, '_lnkup_approved_at', true );
		$paid       = (string) get_post_meta( $app_id, '_lnkup_paid_at', true );
		$checked_in = (string) get_post_meta( $app_id, '_lnkup_checked_in_at', true );
		$no_show    = (string) get_post_meta( $app_id, '_lnkup_no_show_at', true );
		$cancelled  = (string) get_post_meta( $app_id, '_lnkup_cancellation_at', true );

		$milestones = array( 'applied' => $submitted ? $submitted : (string) get_post_field( 'post_date', $app_id ) );
		if ( $approved || in_array( $status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) ) {
			$milestones['approved'] = $approved;
		}
		if ( in_array( $status, array( 'paid', 'completed' ), true ) || $paid ) {
			$milestones['booking_confirmed'] = $paid;
		}
		if ( $checked_in ) {
			$milestones['checked_in'] = $checked_in;
		}

		$evidence = '';
		if ( $no_show ) {
			$state                 = 'no_show';
			$milestones['no_show'] = $no_show;
		} elseif ( $cancelled || 'cancelled' === $status ) {
			$state                   = 'cancelled';
			$milestones['cancelled'] = $cancelled;
		} elseif ( LNKup_Reliability_Metrics::is_completed_participation( $app_id, $status, '' !== $checked_in, false ) ) {
			$state                   = 'completed';
			$evidence                = '' !== $checked_in ? 'check_in' : 'paid_booking_event_ended';
			$milestones['completed'] = (string) get_post_meta( $app_id, '_lnkup_event_completed_at', true );
		} elseif ( $checked_in ) {
			$state = 'checked_in';
		} elseif ( isset( $milestones['booking_confirmed'] ) ) {
			$state = 'booking_confirmed';
		} elseif ( isset( $milestones['approved'] ) ) {
			$state = 'approved';
		} elseif ( 'rejected' === $status ) {
			$state = 'not_selected';
		} elseif ( 'waitlisted' === $status ) {
			$state = 'waitlisted';
		} else {
			$state = 'applied';
		}

		return array(
			'application_id' => $app_id,
			'state'          => $state,
			'milestones'     => $milestones,
			'evidence'       => $evidence,
			'rank'           => self::RANK[ $state ] ?? 0,
		);
	}

	/**
	 * Prefer the most advanced positive milestone for an event with several applications.
	 *
	 * @param array $candidate New.
	 * @param array $current   Existing.
	 */
	private static function better( array $candidate, array $current ): bool {
		return (int) $candidate['rank'] > (int) $current['rank'];
	}

	/**
	 * Strip fields the viewer must not see.
	 *
	 * @param array  $row       Full row.
	 * @param string $context   Context.
	 * @param int    $viewer_id Viewer.
	 * @return array<string,mixed>
	 */
	private static function shape_row( array $row, string $context, int $viewer_id ): array {
		$shaped = array(
			'event_id'        => $row['event_id'],
			'event_title'     => $row['event_title'],
			'event_date'      => $row['event_date'],
			'city'            => $row['city'],
			'state'           => $row['state'],
			'organizer_name'  => $row['organizer_name'],
			'tour_id'         => $row['tour_id'],
			'tour_title'      => $row['tour_title'],
			'tour_stop_order' => $row['tour_stop_order'],
			'category'        => $row['category'],
			'status'          => $row['state_key'],
			'status_label'    => $row['state_label'],
			'confirmed'       => $row['confirmed'],
			'evidence'        => $row['evidence'],
			'event_url'       => $row['event_url'],
		);
		if ( ! empty( $row['confirmed'] ) ) {
			$shaped['booth_type'] = $row['booth_type'];
		}
		$own = 'organizer' === $context && $viewer_id && (int) $row['organizer_id'] === $viewer_id;
		if ( 'owner' === $context || $own ) {
			$shaped['application_id'] = $row['application_id'];
			$shaped['milestones']     = $row['milestones'];
			$shaped['review_id']      = $row['review_id'];
			$shaped['booth_type']     = $row['booth_type'];
			$shaped['is_own_event']   = $own;
		}
		return $shaped;
	}
}
