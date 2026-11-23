<?php
/**
 * Recurring series: bounded occurrence generation and per-occurrence changes.
 *
 * A rule never creates an open-ended set of events. Every generation is capped at MAX_OCCURRENCES
 * and requires an end date or a count. Dates are calendar dates in the series' own time zone with
 * local start/end times, so a 10:00 market stays at 10:00 local time across daylight-saving changes;
 * previews include the UTC instant so organizers can verify it.
 *
 * Each occurrence is a normal draft stop (LNKup_Portfolio_Tours::create_stop) keyed "o{Ymd}", so
 * generating twice never duplicates a date. Skipping, moving or cancelling touches one occurrence.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Portfolio_Recurrence {

	const MAX_OCCURRENCES = 52;
	const FREQUENCIES     = array( 'weekly', 'biweekly', 'monthly_weekday', 'monthly_date', 'custom' );

	/**
	 * Validate a recurrence rule.
	 *
	 * @param array $raw Raw rule.
	 * @return array|WP_Error
	 */
	public static function sanitize_rule( array $raw ) {
		$frequency = sanitize_key( (string) ( $raw['frequency'] ?? '' ) );
		if ( ! in_array( $frequency, self::FREQUENCIES, true ) ) {
			return new WP_Error( 'lnkup_invalid_frequency', __( 'Choose how often the event repeats.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$timezone = sanitize_text_field( (string) ( $raw['timezone'] ?? '' ) );
		if ( ! LNKup_Portfolio_Tours::valid_timezone( $timezone ) ) {
			return new WP_Error( 'lnkup_invalid_timezone', __( 'Choose the local time zone.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$times = array();
		foreach ( array( 'start_time', 'end_time' ) as $field ) {
			$value = sanitize_text_field( (string) ( $raw[ $field ] ?? '' ) );
			if ( '' !== $value && ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
				return new WP_Error( 'lnkup_invalid_time', __( 'Use 24-hour times like 10:00.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			$times[ $field ] = $value;
		}
		$dates_in = static function ( $list ) {
			return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $list ), array( 'LNKup_Portfolio_Config', 'valid_date' ) ) ) );
		};
		$rule = array(
			'frequency'     => $frequency,
			'timezone'      => $timezone,
			'start_time'    => $times['start_time'],
			'end_time'      => $times['end_time'],
			'start_date'    => sanitize_text_field( (string) ( $raw['start_date'] ?? '' ) ),
			'end_date'      => sanitize_text_field( (string) ( $raw['end_date'] ?? '' ) ),
			'count'         => absint( $raw['count'] ?? 0 ),
			'weekdays'      => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $raw['weekdays'] ?? array() ) ), static function ( $d ) { return $d >= 0 && $d <= 6; } ) ) ),
			'nth'           => (int) ( $raw['nth'] ?? 0 ),
			'weekday'       => isset( $raw['weekday'] ) ? (int) $raw['weekday'] : -1,
			'exclude_dates' => $dates_in( $raw['exclude_dates'] ?? array() ),
			'custom_dates'  => $dates_in( $raw['custom_dates'] ?? array() ),
			'stop'          => array(
				'title'         => mb_substr( sanitize_text_field( (string) ( $raw['stop']['title'] ?? '' ) ), 0, 200 ),
				'city'          => mb_substr( sanitize_text_field( (string) ( $raw['stop']['city'] ?? '' ) ), 0, 120 ),
				'state'         => mb_substr( sanitize_text_field( (string) ( $raw['stop']['state'] ?? '' ) ), 0, 60 ),
				'venue_name'    => mb_substr( sanitize_text_field( (string) ( $raw['stop']['venue_name'] ?? '' ) ), 0, 200 ),
				'venue_address' => mb_substr( sanitize_textarea_field( (string) ( $raw['stop']['venue_address'] ?? '' ) ), 0, 500 ),
			),
		);
		if ( 'custom' === $frequency ) {
			if ( ! $rule['custom_dates'] ) {
				return new WP_Error( 'lnkup_no_dates', __( 'Add at least one date.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			if ( count( $rule['custom_dates'] ) > self::MAX_OCCURRENCES ) {
				return new WP_Error( 'lnkup_too_many_dates', sprintf( __( 'Add up to %d dates at a time.', 'lnkup-marketplace' ), self::MAX_OCCURRENCES ), array( 'status' => 400 ) );
			}
			sort( $rule['custom_dates'] );
			return $rule;
		}
		if ( ! LNKup_Portfolio_Config::valid_date( $rule['start_date'] ) ) {
			return new WP_Error( 'lnkup_invalid_start', __( 'Choose the first date.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( '' !== $rule['end_date'] && ( ! LNKup_Portfolio_Config::valid_date( $rule['end_date'] ) || $rule['end_date'] < $rule['start_date'] ) ) {
			return new WP_Error( 'lnkup_invalid_end', __( 'The end date must be after the first date.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( '' === $rule['end_date'] && ! $rule['count'] ) {
			return new WP_Error( 'lnkup_unbounded', __( 'Choose an end date or how many dates to create.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( $rule['count'] > self::MAX_OCCURRENCES ) {
			return new WP_Error( 'lnkup_too_many_dates', sprintf( __( 'Create up to %d dates at a time. You can generate more later.', 'lnkup-marketplace' ), self::MAX_OCCURRENCES ), array( 'status' => 400 ) );
		}
		if ( in_array( $frequency, array( 'weekly', 'biweekly' ), true ) && ! $rule['weekdays'] ) {
			$rule['weekdays'] = array( (int) gmdate( 'w', strtotime( $rule['start_date'] ) ) );
		}
		if ( 'monthly_weekday' === $frequency ) {
			$start = new DateTimeImmutable( $rule['start_date'] );
			if ( $rule['weekday'] < 0 || $rule['weekday'] > 6 ) {
				$rule['weekday'] = (int) $start->format( 'w' );
			}
			if ( ! in_array( $rule['nth'], array( 1, 2, 3, 4, -1 ), true ) ) {
				$rule['nth'] = (int) ceil( (int) $start->format( 'j' ) / 7 );
				$rule['nth'] = $rule['nth'] > 4 ? -1 : $rule['nth'];
			}
		}
		return $rule;
	}

	/**
	 * Occurrence dates for a rule (bounded). Excluded dates are returned flagged, not dropped,
	 * so the preview can show them.
	 *
	 * @param array $rule Clean rule.
	 * @return array<int,array{date:string,key:string,excluded:bool,starts_at_utc:string,ends_at_utc:string}>
	 */
	public static function occurrences( array $rule ) {
		$dates = 'custom' === $rule['frequency'] ? $rule['custom_dates'] : self::rule_dates( $rule );
		$tz    = new DateTimeZone( $rule['timezone'] );
		$utc   = new DateTimeZone( 'UTC' );
		$out   = array();
		foreach ( $dates as $date ) {
			$out[] = array(
				'date'          => $date,
				'key'           => 'o' . str_replace( '-', '', $date ),
				'excluded'      => in_array( $date, $rule['exclude_dates'], true ),
				'starts_at_utc' => $rule['start_time'] ? ( new DateTimeImmutable( $date . ' ' . $rule['start_time'], $tz ) )->setTimezone( $utc )->format( 'c' ) : '',
				'ends_at_utc'   => $rule['end_time'] ? ( new DateTimeImmutable( $date . ' ' . $rule['end_time'], $tz ) )->setTimezone( $utc )->format( 'c' ) : '',
			);
		}
		return $out;
	}

	/**
	 * @param array $rule Clean, non-custom rule.
	 * @return string[] Y-m-d dates.
	 */
	private static function rule_dates( array $rule ) {
		$dates  = array();
		$cursor = new DateTimeImmutable( $rule['start_date'] );
		$end    = '' !== $rule['end_date'] ? $rule['end_date'] : $cursor->modify( '+3 years' )->format( 'Y-m-d' );
		$limit  = $rule['count'] ? min( $rule['count'], self::MAX_OCCURRENCES ) : self::MAX_OCCURRENCES;
		$guard  = 0;

		if ( in_array( $rule['frequency'], array( 'weekly', 'biweekly' ), true ) ) {
			$step       = 'biweekly' === $rule['frequency'] ? 2 : 1;
			$week_start = $cursor->modify( '-' . (int) $cursor->format( 'w' ) . ' days' );
			while ( count( $dates ) < $limit && $guard++ < 400 ) {
				foreach ( $rule['weekdays'] as $dow ) {
					$day = $week_start->modify( '+' . $dow . ' days' )->format( 'Y-m-d' );
					if ( $day >= $rule['start_date'] && $day <= $end && count( $dates ) < $limit ) {
						$dates[] = $day;
					}
				}
				$week_start = $week_start->modify( '+' . ( 7 * $step ) . ' days' );
				if ( $week_start->format( 'Y-m-d' ) > $end ) {
					break;
				}
			}
			sort( $dates );
			return array_slice( $dates, 0, $limit );
		}

		$month = $cursor->modify( 'first day of this month' );
		$day_n = (int) $cursor->format( 'j' );
		while ( count( $dates ) < $limit && $guard++ < 120 ) {
			if ( 'monthly_date' === $rule['frequency'] ) {
				$candidate = $day_n <= (int) $month->format( 't' ) ? $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $day_n ) : null;
			} else {
				$candidate = self::nth_weekday( $month, $rule['nth'], $rule['weekday'] );
			}
			if ( $candidate ) {
				$day = $candidate->format( 'Y-m-d' );
				if ( $day > $end ) {
					break;
				}
				if ( $day >= $rule['start_date'] ) {
					$dates[] = $day;
				}
			}
			$month = $month->modify( 'first day of next month' );
		}
		return $dates;
	}

	/**
	 * The nth weekday of a month (nth = -1 for the last one).
	 *
	 * @param DateTimeImmutable $month First day of the month.
	 * @param int               $nth   1-4 or -1.
	 * @param int               $dow   0 = Sunday.
	 */
	private static function nth_weekday( DateTimeImmutable $month, $nth, $dow ) {
		if ( -1 === $nth ) {
			$last = $month->modify( 'last day of this month' );
			$back = ( (int) $last->format( 'w' ) - $dow + 7 ) % 7;
			return $last->modify( '-' . $back . ' days' );
		}
		$offset = ( $dow - (int) $month->format( 'w' ) + 7 ) % 7;
		$day    = $month->modify( '+' . ( $offset + 7 * ( $nth - 1 ) ) . ' days' );
		return $day->format( 'n' ) === $month->format( 'n' ) ? $day : null;
	}

	/**
	 * Preview without writing anything.
	 *
	 * @param int   $tour_id Tour.
	 * @param array $raw     Raw rule.
	 * @return array|WP_Error
	 */
	public static function preview( $tour_id, array $raw ) {
		$rule = self::sanitize_rule( $raw );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}
		$existing = LNKup_Portfolio_Tours::stops_by_key( LNKup_Tours::stop_event_ids( $tour_id ) );
		$items    = array();
		foreach ( self::occurrences( $rule ) as $occ ) {
			$occ['exists'] = isset( $existing[ $occ['key'] ] );
			$items[]       = $occ;
		}
		return array(
			'rule'        => $rule,
			'occurrences' => $items,
			'new_count'   => count(
				array_filter(
					$items,
					static function ( $o ) {
						return ! $o['excluded'] && ! $o['exists'];
					}
				)
			),
			'capped'      => count( $items ) >= self::MAX_OCCURRENCES,
		);
	}

	/**
	 * Save the rule and create missing draft occurrences (background job when large).
	 *
	 * @param int    $tour_id         Tour.
	 * @param array  $raw             Raw rule.
	 * @param string $idempotency_key Request key.
	 * @return array|WP_Error Job / inline result.
	 */
	public static function generate( $tour_id, array $raw, $idempotency_key ) {
		$preview = self::preview( $tour_id, $raw );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$rule = $preview['rule'];
		update_post_meta( $tour_id, '_lnkup_recurrence_rule', LNKup_Meta::json_for_meta( $rule ) );
		$rows = array();
		foreach ( $preview['occurrences'] as $occ ) {
			if ( $occ['excluded'] || $occ['exists'] ) {
				continue;
			}
			$rows[] = array_merge(
				$rule['stop'],
				array(
					'key'        => $occ['key'],
					'event_date' => $occ['date'],
					'start_time' => $rule['start_time'],
					'end_time'   => $rule['end_time'],
					'timezone'   => $rule['timezone'],
					'title'      => '' !== $rule['stop']['title']
						? $rule['stop']['title'] . ' — ' . wp_date( 'M j, Y', strtotime( $occ['date'] . ' 12:00:00' ) )
						: lnkup_plain_title( $tour_id ) . ' — ' . wp_date( 'M j, Y', strtotime( $occ['date'] . ' 12:00:00' ) ),
				)
			);
		}
		LNKup_Portfolio_Audit::log( 'tour', $tour_id, 'recurrence_generated', array( 'frequency' => $rule['frequency'], 'new' => count( $rows ) ), (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true ) );
		if ( ! $rows ) {
			return array( 'mode' => 'inline', 'result' => array( 'results' => array(), 'created' => 0, 'existing' => 0, 'failed' => 0, 'warnings' => array() ) );
		}
		return LNKup_Portfolio_Jobs::add_stops( $tour_id, $rows, $idempotency_key );
	}

	/**
	 * Skip one occurrence. Only allowed while no vendor holds an active application; otherwise the
	 * organizer must cancel it so vendors are told.
	 *
	 * @param int  $tour_id      Tour.
	 * @param int  $event_id     Occurrence.
	 * @param int  $organizer_id Owner.
	 * @param bool $undo         Restore a skipped occurrence as a draft.
	 * @return array|WP_Error
	 */
	public static function skip( $tour_id, $event_id, $organizer_id, $undo = false ) {
		if ( $undo ) {
			if ( 'skipped' !== get_post_meta( $event_id, '_lnkup_occurrence_state', true ) ) {
				return new WP_Error( 'lnkup_not_skipped', __( 'This date is not skipped.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
			}
			update_post_meta( $event_id, '_lnkup_occurrence_state', 'scheduled' );
			LNKup_Portfolio_Audit::log( 'event', $event_id, 'occurrence_restored', array(), $organizer_id );
			return LNKup_Portfolio_Tours::format_stop( $tour_id, $event_id );
		}
		if ( self::has_active_applications( $event_id ) ) {
			return new WP_Error( 'lnkup_occurrence_has_vendors', __( 'Vendors have applied to this date. Cancel it instead so they are notified.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$state = LNKup_Portfolio_Tours::stop_state( $event_id );
		if ( in_array( $state, array( 'past', 'cancelled' ), true ) ) {
			return new WP_Error( 'lnkup_occurrence_closed', __( 'This date already happened or was cancelled.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( 'publish' === get_post_status( $event_id ) ) {
			foreach ( LNKup_Organizer_Onboarding::event_slot_posts( (int) $event_id ) as $opp ) {
				LNKup_Organizer_Onboarding::set_opportunity_listing_status( (int) $opp->ID, 'closed', (int) $organizer_id );
			}
			wp_update_post( array( 'ID' => $event_id, 'post_status' => 'draft' ) );
		}
		update_post_meta( $event_id, '_lnkup_occurrence_state', 'skipped' );
		LNKup_Portfolio_Audit::log( 'event', $event_id, 'occurrence_skipped', array(), $organizer_id );
		return LNKup_Portfolio_Tours::format_stop( $tour_id, $event_id );
	}

	/**
	 * Move one occurrence (date, time or venue). Booked vendors are notified by
	 * update_event_from_request(); other occurrences are untouched.
	 *
	 * @param int   $tour_id      Tour.
	 * @param int   $event_id     Occurrence.
	 * @param array $changes      event_date, start_time, end_time, venue_name, venue_address, city, state.
	 * @param int   $organizer_id Owner.
	 */
	public static function move( $tour_id, $event_id, array $changes, $organizer_id ) {
		$allowed = array_intersect_key( $changes, array_flip( array( 'event_date', 'start_time', 'end_time', 'venue_name', 'venue_address', 'city', 'state' ) ) );
		if ( ! $allowed ) {
			return new WP_Error( 'lnkup_nothing_to_change', __( 'Choose a new date, time or venue.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( in_array( LNKup_Portfolio_Tours::stop_state( $event_id ), array( 'past', 'cancelled' ), true ) ) {
			return new WP_Error( 'lnkup_occurrence_closed', __( 'This date already happened or was cancelled.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$updated = LNKup_Portfolio_Tours::update_stop( $tour_id, $event_id, $allowed, $organizer_id );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		update_post_meta( $event_id, '_lnkup_occurrence_state', 'moved' );
		LNKup_Portfolio_Audit::log( 'event', $event_id, 'occurrence_moved', array( 'fields' => array_keys( $allowed ) ), $organizer_id );
		return LNKup_Portfolio_Tours::format_stop( $tour_id, $event_id );
	}

	/**
	 * Replace the remaining schedule from a date onward. Only untouched future draft dates without
	 * applications are removed; live, moved or booked dates are kept and reported.
	 *
	 * @param int    $tour_id         Tour.
	 * @param array  $raw             New rule.
	 * @param string $from_date       First date affected (default today).
	 * @param int    $organizer_id    Owner.
	 * @param string $idempotency_key Request key.
	 */
	public static function change_remaining( $tour_id, array $raw, $from_date, $organizer_id, $idempotency_key ) {
		$from_date = LNKup_Portfolio_Config::valid_date( (string) $from_date ) ? (string) $from_date : current_time( 'Y-m-d' );
		$raw['start_date'] = isset( $raw['start_date'] ) && (string) $raw['start_date'] > $from_date ? (string) $raw['start_date'] : $from_date;
		if ( isset( $raw['custom_dates'] ) ) {
			$raw['custom_dates'] = array_values(
				array_filter(
					(array) $raw['custom_dates'],
					static function ( $date ) use ( $from_date ) {
						return (string) $date >= $from_date;
					}
				)
			);
		}
		$rule = self::sanitize_rule( $raw );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}
		$removed = array();
		$kept    = array();
		foreach ( LNKup_Tours::stop_event_ids( $tour_id ) as $event_id ) {
			$date = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
			if ( $date < $from_date ) {
				continue;
			}
			$untouched = 'publish' !== get_post_status( $event_id )
				&& in_array( (string) get_post_meta( $event_id, '_lnkup_occurrence_state', true ), array( '', 'scheduled' ), true )
				&& ! LNKup_Portfolio_Tours::stop_has_applications( $event_id );
			if ( ! $untouched ) {
				$kept[] = (int) $event_id;
				continue;
			}
			$done = LNKup_Portfolio_Tours::delete_stop( $tour_id, $event_id, $organizer_id );
			if ( is_wp_error( $done ) ) {
				$kept[] = (int) $event_id;
			} else {
				$removed[] = (int) $event_id;
			}
		}
		$generated = self::generate( $tour_id, $rule, $idempotency_key );
		if ( is_wp_error( $generated ) ) {
			return $generated;
		}
		return array(
			'removed'   => $removed,
			'kept'      => $kept,
			'generated' => $generated,
		);
	}

	/**
	 * @param int $event_id Occurrence.
	 */
	public static function has_active_applications( $event_id ) {
		return (bool) get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_event_id', 'value' => (string) absint( $event_id ) ),
					array( 'key' => '_lnkup_application_status', 'value' => LNKup_Portfolio_Tours::ACTIVE_APP_STATUSES, 'compare' => 'IN' ),
				),
			)
		);
	}
}
