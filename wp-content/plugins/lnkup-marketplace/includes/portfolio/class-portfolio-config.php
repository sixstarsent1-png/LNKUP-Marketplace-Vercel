<?php
/**
 * Tour / series defaults, stop-level overrides and previewed bulk pricing.
 *
 * Tour defaults (`_lnkup_tour_defaults` on lnkup_tour) describe booth types, documents,
 * application questions and the deadline offset once for every stop. A stop stores only what it
 * changes (`_lnkup_stop_overrides` on lnkup_event). Draft stops resolve defaults + overrides on read;
 * publishing a stop turns the resolved booth types into real lnkup_opportunity records, after which
 * prices and capacities change only through LNKup_Organizer_Onboarding::update_opportunity_pricing()
 * (which refuses capacities below filled spots). Existing lnkup_payment records keep the amounts
 * they were created with, so no bulk edit can change a completed or pending payment.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Portfolio_Config {

	const DEFAULTS_META     = '_lnkup_tour_defaults';
	const OVERRIDES_META    = '_lnkup_stop_overrides';
	const MAX_BOOTH_TYPES   = 12;
	const MAX_CAPACITY      = 2000;
	const DEFAULT_DEADLINE  = 7;

	/**
	 * Normalized tour defaults.
	 *
	 * @param int $tour_id Tour ID.
	 * @return array{booth_types:array,document_mode:string,required_documents:string[],questions:array,deadline_days_before:int,vendor_description:string}
	 */
	public static function get_defaults( $tour_id ) {
		$raw = json_decode( (string) get_post_meta( absint( $tour_id ), self::DEFAULTS_META, true ), true );
		$out = self::sanitize_defaults( is_array( $raw ) ? $raw : array() );
		return is_wp_error( $out ) ? self::sanitize_defaults( array() ) : $out;
	}

	/**
	 * Validate defaults (also used for template configs).
	 *
	 * @param array $raw Raw config.
	 * @return array|WP_Error
	 */
	public static function sanitize_defaults( array $raw ) {
		$booths = array();
		$keys   = array();
		foreach ( array_slice( (array) ( $raw['booth_types'] ?? array() ), 0, self::MAX_BOOTH_TYPES ) as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = trim( sanitize_text_field( (string) ( $row['label'] ?? '' ) ) );
			if ( '' === $label ) {
				continue;
			}
			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
			if ( '' === $key ) {
				$key = sanitize_key( sanitize_title( $label ) );
			}
			if ( '' === $key || isset( $keys[ $key ] ) ) {
				$key = 'booth_' . ( $i + 1 );
			}
			$keys[ $key ] = true;
			$price        = LNKup_Security::sanitize_money( $row['price'] ?? 0 );
			if ( $price < 0 ) {
				return new WP_Error( 'lnkup_invalid_price', sprintf( __( '"%s" needs a price of $0 or more.', 'lnkup-marketplace' ), $label ), array( 'status' => 400 ) );
			}
			$capacity = absint( $row['capacity'] ?? 0 );
			if ( $capacity > self::MAX_CAPACITY ) {
				return new WP_Error( 'lnkup_invalid_capacity', sprintf( __( '"%1$s" can have up to %2$d booths per stop.', 'lnkup-marketplace' ), $label, self::MAX_CAPACITY ), array( 'status' => 400 ) );
			}
			$booths[] = array(
				'key'      => $key,
				'label'    => mb_substr( $label, 0, 120 ),
				'category' => mb_substr( sanitize_text_field( (string) ( $row['category'] ?? '' ) ), 0, 120 ),
				'price'    => round( $price, 2 ),
				'capacity' => $capacity,
			);
		}

		$questions = LNKup_Application_Questions::sanitize_definitions( is_array( $raw['questions'] ?? null ) ? $raw['questions'] : array() );
		if ( is_wp_error( $questions ) ) {
			return $questions;
		}
		$mode = sanitize_key( (string) ( $raw['document_mode'] ?? 'none' ) );
		if ( ! in_array( $mode, array( 'none', 'platform_defaults', 'custom' ), true ) ) {
			$mode = 'none';
		}
		$docs = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $raw['required_documents'] ?? array() ) ) ) ) ), 0, 12 );
		$days = isset( $raw['deadline_days_before'] ) ? absint( $raw['deadline_days_before'] ) : self::DEFAULT_DEADLINE;

		return array(
			'booth_types'          => $booths,
			'document_mode'        => 'custom' === $mode && ! $docs ? 'none' : $mode,
			'required_documents'   => 'custom' === $mode ? $docs : array(),
			'questions'            => $questions,
			'deadline_days_before' => min( 365, $days ),
			'vendor_description'   => mb_substr( sanitize_textarea_field( (string) ( $raw['vendor_description'] ?? '' ) ), 0, 4000 ),
		);
	}

	/**
	 * Replace tour defaults. Only draft stops pick this up (published stops already have real
	 * booth listings), and stop overrides always win.
	 *
	 * @param int   $tour_id Tour ID.
	 * @param array $raw     Raw defaults.
	 * @return array|WP_Error Saved defaults.
	 */
	public static function save_defaults( $tour_id, array $raw ) {
		$clean = self::sanitize_defaults( $raw );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$before = self::get_defaults( $tour_id );
		update_post_meta( $tour_id, self::DEFAULTS_META, LNKup_Meta::json_for_meta( $clean ) );
		LNKup_Portfolio_Audit::log(
			'tour',
			$tour_id,
			'defaults_saved',
			array(
				'booth_types_before' => count( $before['booth_types'] ),
				'booth_types_after'  => count( $clean['booth_types'] ),
				'questions'          => count( $clean['questions'] ),
			),
			(int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true )
		);
		return $clean;
	}

	/**
	 * Stop overrides: { booth_types: { key: {price?, capacity?, enabled?} }, deadline? }.
	 *
	 * @param int $event_id Stop event ID.
	 */
	public static function get_overrides( $event_id ) {
		$raw = json_decode( (string) get_post_meta( absint( $event_id ), self::OVERRIDES_META, true ), true );
		return self::sanitize_overrides( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * @param array $raw Raw overrides.
	 */
	public static function sanitize_overrides( array $raw ) {
		$booths = array();
		foreach ( (array) ( $raw['booth_types'] ?? array() ) as $key => $row ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $row ) ) {
				continue;
			}
			$clean = array();
			if ( isset( $row['price'] ) && '' !== $row['price'] && null !== $row['price'] ) {
				$clean['price'] = max( 0, round( LNKup_Security::sanitize_money( $row['price'] ), 2 ) );
			}
			if ( isset( $row['capacity'] ) && '' !== $row['capacity'] && null !== $row['capacity'] ) {
				$clean['capacity'] = min( self::MAX_CAPACITY, absint( $row['capacity'] ) );
			}
			if ( array_key_exists( 'enabled', $row ) ) {
				$clean['enabled'] = rest_sanitize_boolean( $row['enabled'] );
			}
			if ( $clean ) {
				$booths[ $key ] = $clean;
			}
		}
		$out      = array( 'booth_types' => $booths );
		$deadline = sanitize_text_field( (string) ( $raw['deadline'] ?? '' ) );
		if ( self::valid_date( $deadline ) ) {
			$out['deadline'] = $deadline;
		}
		return $out;
	}

	/**
	 * @param int   $event_id  Stop.
	 * @param array $overrides Overrides (already sanitized or raw).
	 */
	public static function save_overrides( $event_id, array $overrides ) {
		$clean = self::sanitize_overrides( $overrides );
		update_post_meta( $event_id, self::OVERRIDES_META, LNKup_Meta::json_for_meta( $clean ) );
		return $clean;
	}

	/**
	 * Effective configuration for one stop: defaults with this stop's overrides applied.
	 *
	 * @param int $tour_id  Tour.
	 * @param int $event_id Stop.
	 * @return array{booth_types:array,deadline:string,deadline_overridden:bool,document_mode:string,required_documents:array,questions:array,vendor_description:string}
	 */
	public static function resolve_for_stop( $tour_id, $event_id ) {
		$defaults  = self::get_defaults( $tour_id );
		$overrides = self::get_overrides( $event_id );
		$booths    = array();
		foreach ( $defaults['booth_types'] as $row ) {
			$o          = $overrides['booth_types'][ $row['key'] ] ?? array();
			$overridden = array_values( array_intersect( array( 'price', 'capacity', 'enabled' ), array_keys( $o ) ) );
			$booths[]   = array_merge(
				$row,
				array(
					'price'      => array_key_exists( 'price', $o ) ? (float) $o['price'] : (float) $row['price'],
					'capacity'   => array_key_exists( 'capacity', $o ) ? (int) $o['capacity'] : (int) $row['capacity'],
					'enabled'    => array_key_exists( 'enabled', $o ) ? (bool) $o['enabled'] : true,
					'overridden' => $overridden,
				)
			);
		}
		$deadline = $overrides['deadline'] ?? '';
		if ( '' === $deadline ) {
			$date = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
			if ( self::valid_date( $date ) ) {
				$deadline = gmdate( 'Y-m-d', strtotime( $date . ' -' . (int) $defaults['deadline_days_before'] . ' days' ) );
			}
		}
		return array(
			'booth_types'         => $booths,
			'deadline'            => $deadline,
			'deadline_overridden' => isset( $overrides['deadline'] ),
			'document_mode'       => $defaults['document_mode'],
			'required_documents'  => $defaults['required_documents'],
			'questions'           => $defaults['questions'],
			'vendor_description'  => $defaults['vendor_description'],
		);
	}

	/**
	 * Live booth listings already created for a published stop, keyed by booth type key.
	 *
	 * @param int $event_id Stop.
	 * @return array<string,int> booth key => opportunity ID.
	 */
	public static function stop_opportunities( $event_id ) {
		$out = array();
		foreach ( LNKup_Organizer_Onboarding::event_slot_posts( (int) $event_id ) as $opp ) {
			$key = (string) get_post_meta( $opp->ID, '_lnkup_booth_type_key', true );
			if ( '' !== $key && ! isset( $out[ $key ] ) ) {
				$out[ $key ] = (int) $opp->ID;
			}
		}
		return $out;
	}

	/**
	 * Preview a bulk booth-type change across selected stops. Nothing is written.
	 *
	 * @param int   $tour_id Tour.
	 * @param array $change  booth_type_key, price (nullable), capacity (nullable), event_ids[].
	 * @return array|WP_Error { booth_type, rows[], counts }
	 */
	public static function bulk_preview( $tour_id, array $change ) {
		$parsed = self::parse_change( $tour_id, $change );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$rows   = array();
		$counts = array( 'change' => 0, 'confirm' => 0, 'blocked' => 0, 'unchanged' => 0 );
		$today  = current_time( 'Y-m-d' );
		foreach ( $parsed['event_ids'] as $event_id ) {
			$row = self::preview_row( $tour_id, $event_id, $parsed, $today );
			++$counts[ $row['outcome'] ];
			$rows[] = $row;
		}
		return array(
			'booth_type' => $parsed['booth'],
			'price'      => $parsed['price'],
			'capacity'   => $parsed['capacity'],
			'rows'       => $rows,
			'counts'     => $counts,
		);
	}

	/**
	 * Apply a previewed bulk change. Published stops are skipped unless confirm_published is true.
	 * Blocked stops (past, cancelled, capacity below filled booths) are never changed.
	 *
	 * @param int   $tour_id      Tour.
	 * @param array $change       Same as bulk_preview() plus confirm_published.
	 * @param int   $organizer_id Acting organizer (tour owner).
	 * @return array|WP_Error { rows[], counts }
	 */
	public static function bulk_apply( $tour_id, array $change, $organizer_id ) {
		$preview = self::bulk_preview( $tour_id, $change );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$confirm = ! empty( $change['confirm_published'] );
		$key     = $preview['booth_type']['key'];
		$results = array();
		$counts  = array( 'applied' => 0, 'skipped' => 0, 'failed' => 0 );
		foreach ( $preview['rows'] as $row ) {
			$event_id = (int) $row['event_id'];
			if ( in_array( $row['outcome'], array( 'blocked', 'unchanged' ), true ) || ( 'confirm' === $row['outcome'] && ! $confirm ) ) {
				$results[] = array_merge( $row, array( 'result' => 'skipped' ) );
				++$counts['skipped'];
				continue;
			}
			if ( 'confirm' === $row['outcome'] ) {
				$opp_id = (int) $row['opportunity_id'];
				$saved  = LNKup_Organizer_Onboarding::update_opportunity_pricing( $opp_id, $preview['price'], $preview['capacity'], (int) $organizer_id );
				if ( is_wp_error( $saved ) ) {
					$results[] = array_merge( $row, array( 'result' => 'failed', 'message' => $saved->get_error_message() ) );
					++$counts['failed'];
					continue;
				}
			}
			$overrides = self::get_overrides( $event_id );
			$entry     = $overrides['booth_types'][ $key ] ?? array();
			if ( null !== $preview['price'] ) {
				$entry['price'] = $preview['price'];
			}
			if ( null !== $preview['capacity'] ) {
				$entry['capacity'] = $preview['capacity'];
			}
			$overrides['booth_types'][ $key ] = $entry;
			self::save_overrides( $event_id, $overrides );
			LNKup_Portfolio_Audit::log(
				'event',
				$event_id,
				'bulk_pricing',
				array(
					'booth_type' => $key,
					'from'       => $row['current'],
					'to'         => $row['proposed'],
					'published'  => 'confirm' === $row['outcome'],
				),
				(int) $organizer_id
			);
			$results[] = array_merge( $row, array( 'result' => 'applied' ) );
			++$counts['applied'];
		}
		return array(
			'booth_type' => $preview['booth_type'],
			'rows'       => $results,
			'counts'     => $counts,
		);
	}

	/**
	 * @param int   $tour_id Tour.
	 * @param array $change  Raw change.
	 * @return array|WP_Error
	 */
	private static function parse_change( $tour_id, array $change ) {
		$key   = sanitize_key( (string) ( $change['booth_type_key'] ?? '' ) );
		$booth = null;
		foreach ( self::get_defaults( $tour_id )['booth_types'] as $row ) {
			if ( $row['key'] === $key ) {
				$booth = $row;
			}
		}
		if ( ! $booth ) {
			return new WP_Error( 'lnkup_unknown_booth_type', __( 'Choose one of this tour\'s booth types.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$price    = isset( $change['price'] ) && '' !== $change['price'] && null !== $change['price'] ? round( LNKup_Security::sanitize_money( $change['price'] ), 2 ) : null;
		$capacity = isset( $change['capacity'] ) && '' !== $change['capacity'] && null !== $change['capacity'] ? absint( $change['capacity'] ) : null;
		if ( null === $price && null === $capacity ) {
			return new WP_Error( 'lnkup_nothing_to_change', __( 'Enter a new price or booth count.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( null !== $price && $price < 0 ) {
			return new WP_Error( 'lnkup_invalid_price', __( 'Price cannot be negative.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( null !== $capacity && ( $capacity < 1 || $capacity > self::MAX_CAPACITY ) ) {
			return new WP_Error( 'lnkup_invalid_capacity', __( 'Booth count must be at least 1.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$stops = LNKup_Tours::stop_event_ids( $tour_id );
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $change['event_ids'] ?? array() ) ) ) ) );
		if ( ! $ids ) {
			return new WP_Error( 'lnkup_no_stops', __( 'Select at least one stop.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		foreach ( $ids as $event_id ) {
			if ( ! in_array( $event_id, $stops, true ) || ! LNKup_Capabilities::event_is_tour_stop( $tour_id, $event_id ) ) {
				return new WP_Error( 'lnkup_not_on_tour', __( 'One of the selected stops is not part of this tour.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
		}
		return array(
			'booth'     => $booth,
			'price'     => $price,
			'capacity'  => $capacity,
			'event_ids' => $ids,
		);
	}

	/**
	 * Outcome for one stop: change (draft), confirm (published, needs explicit confirmation),
	 * blocked, or unchanged.
	 *
	 * @param int    $tour_id  Tour.
	 * @param int    $event_id Stop.
	 * @param array  $parsed   Parsed change.
	 * @param string $today    Y-m-d.
	 */
	private static function preview_row( $tour_id, $event_id, array $parsed, $today ) {
		$key      = $parsed['booth']['key'];
		$resolved = self::resolve_for_stop( $tour_id, $event_id );
		$current  = null;
		foreach ( $resolved['booth_types'] as $row ) {
			if ( $row['key'] === $key ) {
				$current = $row;
			}
		}
		$state     = LNKup_Portfolio_Tours::stop_state( $event_id );
		$published = 'publish' === get_post_status( $event_id );
		$opp_id    = 0;
		$filled    = 0;
		$paid      = 0;
		$cur       = array(
			'price'    => $current ? (float) $current['price'] : null,
			'capacity' => $current ? (int) $current['capacity'] : null,
		);
		if ( $published ) {
			$opp_id = self::stop_opportunities( $event_id )[ $key ] ?? 0;
			if ( $opp_id ) {
				$cur    = array(
					'price'    => (float) get_post_meta( $opp_id, '_lnkup_vendor_fee', true ),
					'capacity' => (int) get_post_meta( $opp_id, '_lnkup_spots_total', true ),
				);
				$filled = LNKup_Opportunity_Inventory::holds( $opp_id );
				$paid   = self::paid_application_count( $opp_id );
			}
		}
		$proposed = array(
			'price'    => null !== $parsed['price'] ? $parsed['price'] : $cur['price'],
			'capacity' => null !== $parsed['capacity'] ? $parsed['capacity'] : $cur['capacity'],
		);
		$row = array(
			'event_id'       => $event_id,
			'title'          => get_the_title( $event_id ),
			'city'           => (string) get_post_meta( $event_id, '_lnkup_city', true ),
			'date'           => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
			'state'          => $state,
			'opportunity_id' => $opp_id,
			'current'        => $cur,
			'proposed'       => $proposed,
			'filled'         => $filled,
			'paid_bookings'  => $paid,
			'outcome'        => 'change',
			'reason'         => '',
		);

		$date = (string) $row['date'];
		if ( in_array( $state, array( 'cancelled', 'postponed', 'skipped' ), true ) ) {
			return array_merge( $row, array( 'outcome' => 'blocked', 'reason' => __( 'This stop is cancelled or skipped.', 'lnkup-marketplace' ) ) );
		}
		if ( self::valid_date( $date ) && $date < $today ) {
			return array_merge( $row, array( 'outcome' => 'blocked', 'reason' => __( 'This stop already happened.', 'lnkup-marketplace' ) ) );
		}
		if ( ! $current || ( ! $current['enabled'] && ! $published ) ) {
			return array_merge( $row, array( 'outcome' => 'blocked', 'reason' => __( 'This booth type is turned off for this stop.', 'lnkup-marketplace' ) ) );
		}
		if ( $published && ! $opp_id ) {
			return array_merge( $row, array( 'outcome' => 'blocked', 'reason' => __( 'This booth type was not offered when the stop was published. Add it from the event page.', 'lnkup-marketplace' ) ) );
		}
		if ( null !== $parsed['capacity'] && $parsed['capacity'] < $filled ) {
			return array_merge( $row, array( 'outcome' => 'blocked', 'reason' => sprintf( __( 'Booth count cannot go below the %d booths already held.', 'lnkup-marketplace' ), $filled ) ) );
		}
		if ( (float) $proposed['price'] === (float) $cur['price'] && (int) $proposed['capacity'] === (int) $cur['capacity'] ) {
			return array_merge( $row, array( 'outcome' => 'unchanged' ) );
		}
		if ( $published ) {
			return array_merge(
				$row,
				array(
					'outcome' => 'confirm',
					'reason'  => $paid
						? sprintf( _n( 'Live stop. %d vendor already paid and keeps the price they paid.', 'Live stop. %d vendors already paid and keep the price they paid.', $paid, 'lnkup-marketplace' ), $paid )
						: __( 'Live stop. New applicants will see the new price.', 'lnkup-marketplace' ),
				)
			);
		}
		return $row;
	}

	/**
	 * @param int $opp_id Opportunity.
	 */
	private static function paid_application_count( $opp_id ) {
		return count(
			get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => '_lnkup_opportunity_id', 'value' => (string) absint( $opp_id ) ),
						array( 'key' => '_lnkup_application_status', 'value' => array( 'paid', 'completed' ), 'compare' => 'IN' ),
					),
				)
			)
		);
	}

	/**
	 * @param string $date Candidate Y-m-d.
	 */
	public static function valid_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $date ) );
		return checkdate( $m, $d, $y );
	}
}
