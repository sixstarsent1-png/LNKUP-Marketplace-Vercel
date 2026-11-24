<?php
/**
 * Builder → Portfolio: recurring events, multi-city tours and series.
 *
 * The builder draft holds the shared setup (details, vendor options, applications, payments) and
 * the schedule or stop list. Publishing goes through the existing Portfolio services — a
 * `lnkup_tour` with defaults, recurrence generation (LNKup_Portfolio_Recurrence) or stop rows
 * (LNKup_Portfolio_Jobs), and per-stop publishing — so each date is its own LNKup event with its
 * own vendors, payments and day-of operations. No second recurrence or tour model is created.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Builder_Portfolio {

	const TOUR_META   = '_lnkup_builder_tour_id';
	const EXTRAS_META = '_lnkup_builder_stop_extras';
	const METHODS     = array( 'recurring', 'tour', 'series' );
	const DAYS        = array( 'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6 );

	public static function init(): void {
		add_action( 'lnkup_portfolio_stop_created', array( __CLASS__, 'apply_extras' ), 10, 3 );
	}

	/**
	 * The organizer's upcoming events that can join a tour or series (not already in one).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function stop_candidates( int $user_id ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'meta_value',
				'meta_key'       => '_lnkup_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_organizer_user_id', 'value' => $user_id ),
					array( 'key' => '_lnkup_event_date', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=' ),
					array(
						'relation' => 'OR',
						array( 'key' => '_lnkup_tour_id', 'compare' => 'NOT EXISTS' ),
						array( 'key' => '_lnkup_tour_id', 'value' => array( '', '0' ), 'compare' => 'IN' ),
					),
				),
			)
		);
		return array_map(
			static fn( $id ) => array(
				'id'     => (int) $id,
				'title'  => lnkup_plain_title( (int) $id ),
				'date'   => (string) get_post_meta( (int) $id, '_lnkup_event_date', true ),
				'city'   => (string) get_post_meta( (int) $id, '_lnkup_city', true ),
				'state'  => (string) get_post_meta( (int) $id, '_lnkup_state', true ),
				'venue'  => (string) get_post_meta( (int) $id, '_lnkup_venue_name', true ),
				'status' => get_post_status( (int) $id ),
			),
			$ids
		);
	}

	/**
	 * @param array<string,mixed> $state Normalized state.
	 */
	public static function is_portfolio( array $state ): bool {
		return in_array( $state['method'], self::METHODS, true );
	}

	/**
	 * Builder recurrence → LNKup_Portfolio_Recurrence raw rule.
	 *
	 * @param array<string,mixed> $state Normalized state.
	 * @return array<string,mixed>
	 */
	public static function rule( array $state ): array {
		$ev  = $state['event'];
		$rec = $state['portfolio']['recurrence'];
		return array(
			'frequency'     => $rec['frequency'],
			'timezone'      => '' !== $ev['timezone'] ? $ev['timezone'] : wp_timezone_string(),
			'start_time'    => $ev['start_time'],
			'end_time'      => $ev['end_time'],
			'start_date'    => '' !== $rec['start'] ? $rec['start'] : $ev['date'],
			'end_date'      => $rec['until'],
			'count'         => $rec['count'],
			'weekdays'      => array_values( array_map( static fn( $d ) => self::DAYS[ $d ], $rec['weekdays'] ) ),
			'exclude_dates' => $rec['exclude'],
			'custom_dates'  => $rec['dates'],
			'stop'          => array(
				'title'         => $ev['name'],
				'city'          => $ev['city'],
				'state'         => $ev['state'],
				'venue_name'    => $ev['venue_name'],
				'venue_address' => $ev['venue_address'],
			),
		);
	}

	/**
	 * Dates a recurring draft would create (nothing is written).
	 *
	 * @param array<string,mixed> $state Normalized state.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function preview( array $state, int $user_id ) {
		$rule = LNKup_Portfolio_Recurrence::sanitize_rule( self::rule( $state ) );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}
		$today = current_time( 'Y-m-d' );
		$occ   = LNKup_Portfolio_Recurrence::occurrences( $rule );
		$busy  = self::organizer_dates( $user_id, wp_list_pluck( $occ, 'date' ) );
		$out   = array();
		foreach ( $occ as $o ) {
			$out[] = array(
				'date'     => $o['date'],
				'excluded' => $o['excluded'],
				'past'     => $o['date'] < $today,
				'conflict' => $busy[ $o['date'] ] ?? '',
			);
		}
		$active = array_filter( $out, static fn( $o ) => ! $o['excluded'] && ! $o['past'] );
		$dl     = $state['payments']['deadline'];
		return array(
			'occurrences' => $out,
			'count'       => count( $active ),
			'capped'      => count( $out ) >= LNKup_Portfolio_Recurrence::MAX_OCCURRENCES,
			'first'       => $active ? reset( $active )['date'] : '',
			'last'        => $active ? end( $active )['date'] : '',
			'deadline'    => 'relative' === $dl['mode'] ? sprintf( /* translators: %d: days */ _n( 'Applications close %d day before each date', 'Applications close %d days before each date', (int) $dl['days_before'], 'lnkup-marketplace' ), (int) $dl['days_before'] ) : __( 'Applications close a week before each date', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Extra publish checks for portfolio drafts.
	 *
	 * @param array<string,mixed> $state Normalized state.
	 * @return array<int,array{key:string,step:string,ok:bool,label:string,message:string}>
	 */
	public static function checks( array $state, int $user_id ): array {
		$out = array();
		if ( ! LNKup_Event_OS_Flags::enabled( 'portfolio_builder_v3' ) ) {
			$out[] = array( 'key' => 'portfolio_flag', 'step' => 'publish', 'ok' => false, 'label' => __( 'Multi-date publishing', 'lnkup-marketplace' ), 'message' => __( 'Multi-date events are not turned on for your account yet.', 'lnkup-marketplace' ) );
		}
		if ( 'schedule' === $state['publish']['when'] ) {
			$out[] = array( 'key' => 'portfolio_schedule', 'step' => 'publish', 'ok' => false, 'label' => __( 'Publish timing', 'lnkup-marketplace' ), 'message' => __( 'Multi-date events publish now or stay as drafts. Choose "Save dates as drafts" to publish later.', 'lnkup-marketplace' ) );
		}
		$publish_now = 'all' === $state['portfolio']['publish_mode'];
		$venue_msg   = __( 'Dates published now need a venue name and street address. Add them, or save the dates as drafts.', 'lnkup-marketplace' );
		if ( 'recurring' === $state['method'] ) {
			$preview = self::preview( $state, $user_id );
			$ok      = ! is_wp_error( $preview ) && $preview['count'] > 0;
			$out[]   = array( 'key' => 'schedule_rule', 'step' => 'info', 'ok' => $ok, 'label' => __( 'Schedule', 'lnkup-marketplace' ), 'message' => is_wp_error( $preview ) ? $preview->get_error_message() : __( 'The schedule has no upcoming dates.', 'lnkup-marketplace' ) );
			if ( $publish_now ) {
				$out[] = array( 'key' => 'portfolio_venue', 'step' => 'info', 'ok' => '' !== $state['event']['venue_name'] && '' !== $state['event']['venue_address'], 'label' => __( 'Venue and address', 'lnkup-marketplace' ), 'message' => $venue_msg );
			}
			return $out;
		}
		$stops = array_values( array_filter( $state['portfolio']['stops'], static fn( $s ) => ! $s['skip'] ) );
		if ( ! $state['sources'] && array_filter( wp_list_pluck( $stops, 'url' ) ) ) {
			$out[] = array( 'key' => 'attest', 'step' => 'publish', 'ok' => (bool) $state['publish']['attest'], 'label' => __( 'You organize these events', 'lnkup-marketplace' ), 'message' => __( 'Confirm that you organize or are authorized to manage vendors for these events.', 'lnkup-marketplace' ) );
		}
		$out[] = array( 'key' => 'stops', 'step' => 'info', 'ok' => count( $stops ) > 0, 'label' => 'tour' === $state['method'] ? __( 'Tour stops', 'lnkup-marketplace' ) : __( 'Series events', 'lnkup-marketplace' ), 'message' => __( 'Add at least one date and city.', 'lnkup-marketplace' ) );
		$today = current_time( 'Y-m-d' );
		foreach ( $stops as $i => $s ) {
			/* translators: %d: stop number */
			$label = '' !== $s['city'] ? $s['city'] . ( '' !== $s['date'] ? ' · ' . $s['date'] : '' ) : sprintf( __( 'Stop %d', 'lnkup-marketplace' ), $i + 1 );
			if ( $s['event_id'] ) {
				$ok    = LNKup_Capabilities::organizer_owns_event( $s['event_id'], $user_id ) && ! (int) get_post_meta( $s['event_id'], '_lnkup_tour_id', true );
				$out[] = array( 'key' => 'stop_' . $s['key'], 'step' => 'info', 'ok' => $ok, 'label' => $label, 'message' => __( 'Only your own events that are not already in a tour or series can be added.', 'lnkup-marketplace' ) );
				continue;
			}
			$ok    = '' !== $s['date'] && $s['date'] >= $today && '' !== $s['city'] && (bool) preg_match( '/^[A-Z]{2}$/', $s['state'] );
			$out[] = array( 'key' => 'stop_' . $s['key'], 'step' => 'info', 'ok' => $ok, 'label' => $label, 'message' => __( 'Each stop needs an upcoming date, a city and a state.', 'lnkup-marketplace' ) );
			if ( $ok && $publish_now && $s['publish'] ) {
				$out[] = array( 'key' => 'stop_venue_' . $s['key'], 'step' => 'info', 'ok' => '' !== $s['venue_name'] && '' !== $s['venue_address'], 'label' => $label, 'message' => $venue_msg );
			}
			if ( '' !== $s['url'] && class_exists( 'LNKup_Event_Sources' ) ) {
				$rel = LNKup_Event_Sources::relation( $s['url'], $user_id );
				if ( in_array( $rel['state'], array( 'other', 'claimed_other', 'own' ), true ) ) {
					$out[] = array( 'key' => 'stop_listing_' . $s['key'], 'step' => 'info', 'ok' => false, 'label' => $label, 'message' => 'own' === $rel['state'] ? __( 'This listing is already linked to another of your events.', 'lnkup-marketplace' ) : $rel['message'] );
				}
			}
		}
		return $out;
	}

	/**
	 * Shared vendor setup → tour defaults (LNKup_Portfolio_Config shape).
	 *
	 * @param array<string,mixed> $state Normalized state.
	 * @return array<string,mixed>
	 */
	public static function defaults( array $state ): array {
		$booths = array();
		foreach ( $state['options'] as $o ) {
			$booths[] = array( 'key' => $o['key'], 'label' => '' !== $o['name'] ? $o['name'] : $o['category'], 'category' => $o['category'], 'price' => $o['fee'], 'capacity' => $o['spots'] );
		}
		$questions = LNKup_Event_Builder_Publisher::questions_for(
			array_map( static fn( $q ) => array_merge( $q, array( 'scope' => 'all' ) ), $state['applications']['questions'] ),
			array( 'key' => '', 'category' => '' )
		);
		$docs = $state['applications']['documents'];
		$dl   = $state['payments']['deadline'];
		$out  = array(
			'booth_types'        => $booths,
			'questions'          => $questions,
			'document_mode'      => $docs['mode'],
			'required_documents' => wp_list_pluck( $docs['items'], 'key' ),
			'vendor_description' => $state['event']['description'],
		);
		if ( 'relative' === $dl['mode'] ) {
			$out['deadline_days_before'] = (int) $dl['days_before'];
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function publish( int $draft_id, int $user_id ) {
		$existing = (int) get_post_meta( $draft_id, self::TOUR_META, true );
		if ( $existing && 'lnkup_tour' === get_post_type( $existing ) ) {
			return self::response( $existing, array( 'mode' => 'inline' ), array(), true );
		}
		$draft = LNKup_Event_Builder_Drafts::get( $draft_id );
		if ( ! $draft ) {
			return new WP_Error( 'lnkup_builder_missing', __( 'This draft no longer exists.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$state  = LNKup_Event_Builder_Publisher::own_images( $draft['state'], $user_id );
		$report = LNKup_Event_Builder_Validator::report( $state, $user_id );
		if ( ! $report['publishable'] ) {
			return new WP_Error( 'lnkup_builder_not_ready', __( 'Finish the required items before publishing.', 'lnkup-marketplace' ), array( 'status' => 422, 'checks' => array_values( array_filter( $report['checks'], static fn( $c ) => 'required' === $c['level'] && ! $c['ok'] ) ) ) );
		}
		$ev      = $state['event'];
		$primary = '';
		foreach ( $state['sources'] as $s ) {
			if ( $s['primary'] ) {
				$primary = $s['url'];
			}
		}
		$previous = get_current_user_id();
		wp_set_current_user( $user_id );
		try {
			$tour = LNKup_Portfolio_Tours::create(
				$user_id,
				array_filter(
					array(
						'title'          => '' !== $state['portfolio']['name'] ? $state['portfolio']['name'] : $ev['name'],
						'description'    => $ev['description'],
						'type'           => $state['method'],
						'brand_id'       => $ev['brand_id'],
						'category'       => $ev['event_type'],
						'website'        => $primary,
						'cover_image_id' => $ev['featured_image_id'] ?: $ev['flyer_id'],
					),
					static fn( $v ) => '' !== $v && 0 !== $v
				)
			);
			if ( is_wp_error( $tour ) ) {
				return $tour;
			}
			$tour_id = (int) $tour['id'];
			update_post_meta( $draft_id, self::TOUR_META, $tour_id );
			update_post_meta(
				$tour_id,
				self::EXTRAS_META,
				wp_json_encode(
					array(
						'draft_id'          => $draft_id,
						'featured_image_id' => (int) $ev['featured_image_id'],
						'flyer_id'          => (int) $ev['flyer_id'],
						'format'            => $ev['format'],
						'attendance'        => $ev['attendance'],
						'power_available'   => (bool) $ev['power_available'],
					)
				)
			);
			$saved = LNKup_Portfolio_Config::save_defaults( $tour_id, self::defaults( $state ) );
			if ( is_wp_error( $saved ) ) {
				LNKup_Portfolio_Tours::delete_tour( $tour_id, $user_id );
				delete_post_meta( $draft_id, self::TOUR_META );
				return $saved;
			}
			$key = 'builder' . $draft_id;
			if ( 'recurring' === $state['method'] ) {
				$generated = LNKup_Portfolio_Recurrence::generate( $tour_id, self::rule( $state ), $key );
			} else {
				$generated = self::add_stops( $tour_id, $state, $user_id, $key );
			}
			if ( is_wp_error( $generated ) ) {
				return $generated;
			}
			$published = array();
			if ( 'all' === $state['portfolio']['publish_mode'] && 'now' === $state['publish']['when'] && 'inline' === $generated['mode'] ) {
				$ids       = self::stops_to_publish( $tour_id, $state );
				$published = $ids ? LNKup_Portfolio_Tours::publish_stops( $tour_id, $ids, $user_id ) : array();
			}
			LNKup_Event_Builder_Drafts::mark_published( $draft_id, 0 );
			LNKup_Event_Builder_Publisher::track( 'portfolio_published', $state, 0, $user_id );
			return self::response( $tour_id, $generated, $published, false );
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * Tour / series stops: new rows through the stop job, existing events attached.
	 *
	 * @param array<string,mixed> $state Normalized state.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function add_stops( int $tour_id, array $state, int $user_id, string $key ) {
		$ev   = $state['event'];
		$rows = array();
		$own  = array();
		foreach ( $state['portfolio']['stops'] as $s ) {
			if ( $s['skip'] ) {
				continue;
			}
			if ( $s['event_id'] ) {
				$own[] = $s['event_id'];
				continue;
			}
			$overrides = array();
			foreach ( $state['options'] as $o ) {
				$entry = array();
				if ( null !== $s['fee_override'] ) {
					$entry['price'] = $s['fee_override'];
				}
				if ( null !== $s['spots_override'] ) {
					$entry['capacity'] = $s['spots_override'];
				}
				if ( $entry ) {
					$overrides[ $o['key'] ] = $entry;
				}
			}
			$rows[] = array(
				'key'           => $s['key'],
				'title'         => $ev['name'] . ( 'tour' === $state['method'] && '' !== $s['city'] ? ' — ' . $s['city'] : '' ),
				'city'          => $s['city'],
				'state'         => $s['state'],
				'venue_name'    => $s['venue_name'],
				'venue_address' => $s['venue_address'],
				'event_date'    => $s['date'],
				'start_time'    => '' !== $s['start_time'] ? $s['start_time'] : $ev['start_time'],
				'end_time'      => '' !== $s['end_time'] ? $s['end_time'] : $ev['end_time'],
				'timezone'      => '' !== $s['timezone'] ? $s['timezone'] : $ev['timezone'],
				'overrides'     => $overrides ? array( 'booth_types' => $overrides ) : array(),
				'source_url'    => $s['url'],
			);
		}
		if ( $own ) {
			$attached = LNKup_Portfolio_Tours::attach_events( $tour_id, $own, $user_id );
			if ( is_wp_error( $attached ) ) {
				return $attached;
			}
		}
		if ( ! $rows ) {
			return array( 'mode' => 'inline', 'result' => array( 'created' => 0, 'existing' => count( $own ), 'failed' => 0, 'results' => array() ) );
		}
		return LNKup_Portfolio_Jobs::add_stops( $tour_id, $rows, $key );
	}

	/**
	 * Stops the organizer chose to publish now (tours/series can hold some back).
	 *
	 * @param array<string,mixed> $state Normalized state.
	 * @return int[]
	 */
	private static function stops_to_publish( int $tour_id, array $state ): array {
		$all = LNKup_Tours::stop_event_ids( $tour_id );
		if ( 'recurring' === $state['method'] ) {
			return $all;
		}
		$by_key = LNKup_Portfolio_Tours::stops_by_key( $all );
		$ids    = array();
		foreach ( $state['portfolio']['stops'] as $s ) {
			if ( $s['skip'] || ! $s['publish'] ) {
				continue;
			}
			$id = $s['event_id'] ?: ( $by_key[ $s['key'] ] ?? 0 );
			if ( $id && in_array( (int) $id, $all, true ) && 'publish' !== get_post_status( (int) $id ) ) {
				$ids[] = (int) $id;
			}
		}
		return $ids;
	}

	/**
	 * Copy the builder's per-event extras onto each new stop (inline or background job).
	 */
	public static function apply_extras( int $event_id, int $tour_id, $row = array() ): void {
		$extras = json_decode( (string) get_post_meta( $tour_id, self::EXTRAS_META, true ), true );
		if ( ! is_array( $extras ) ) {
			return;
		}
		$url = is_array( $row ) ? esc_url_raw( (string) ( $row['source_url'] ?? '' ), array( 'http', 'https' ) ) : '';
		if ( '' !== $url && class_exists( 'LNKup_Event_Sources' ) ) {
			$owner = (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true );
			LNKup_Event_Sources::attach_from_state( $event_id, 0, LNKup_Event_Builder_Schema::normalize( array( 'sources' => array( array( 'url' => $url, 'primary' => true ) ) ) ), $owner );
			update_post_meta( $event_id, '_lnkup_ticketing_url', $url );
			update_post_meta( $event_id, '_lnkup_ticketing_platform', (string) ( class_exists( 'LNKup_Import_Provider_Registry' ) ? LNKup_Import_Provider_Registry::detect( $url )['key'] : 'other' ) );
		}
		if ( ! empty( $extras['featured_image_id'] ) ) {
			set_post_thumbnail( $event_id, (int) $extras['featured_image_id'] );
		}
		if ( ! empty( $extras['flyer_id'] ) ) {
			update_post_meta( $event_id, '_lnkup_flyer_id', (int) $extras['flyer_id'] );
		}
		update_post_meta( $event_id, '_lnkup_event_format', sanitize_key( (string) ( $extras['format'] ?? '' ) ) );
		$att = (array) ( $extras['attendance'] ?? array() );
		update_post_meta( $event_id, '_lnkup_attendance_mode', sanitize_key( (string) ( $att['mode'] ?? 'unknown' ) ) );
		if ( 'range' === ( $att['mode'] ?? '' ) ) {
			update_post_meta( $event_id, '_lnkup_attendance_min', absint( $att['min'] ?? 0 ) );
			update_post_meta( $event_id, '_lnkup_attendance_max', absint( $att['max'] ?? 0 ) );
		}
		if ( ! empty( $extras['power_available'] ) ) {
			update_post_meta( $event_id, '_lnkup_power_available', 1 );
		}
		update_post_meta( $event_id, '_lnkup_builder_draft_id', absint( $extras['draft_id'] ?? 0 ) );
	}

	/**
	 * @param array<string,mixed> $generated Recurrence/stop job result.
	 * @param array<string,mixed> $published publish_stops() result.
	 * @return array<string,mixed>
	 */
	private static function response( int $tour_id, array $generated, array $published, bool $idempotent ): array {
		$stops = LNKup_Tours::stop_event_ids( $tour_id );
		$live  = count( array_filter( $stops, static fn( $id ) => 'publish' === get_post_status( $id ) ) );
		$url   = function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'tours', array( 'tour' => $tour_id ) ) : home_url( '/organizer-dashboard/?view=tours&tour=' . $tour_id );
		return array(
			'portfolio'  => true,
			'published'  => $live > 0,
			'idempotent' => $idempotent,
			'tour_id'    => $tour_id,
			'type'       => (string) get_post_meta( $tour_id, '_lnkup_tour_type', true ),
			'title'      => lnkup_plain_title( $tour_id ),
			'dates'      => count( $stops ),
			'live'       => $live,
			'not_ready'  => (int) ( $published['not_ready'] ?? 0 ),
			'background' => 'job' === ( $generated['mode'] ?? '' ),
			'tour_url'   => $url,
			'public_url' => $live && 'publish' === get_post_status( $tour_id ) ? (string) get_permalink( $tour_id ) : '',
		);
	}

	/**
	 * Dates this organizer already has events on (title by date).
	 *
	 * @param string[] $dates Dates.
	 * @return array<string,string>
	 */
	private static function organizer_dates( int $user_id, array $dates ): array {
		if ( ! $dates ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'future' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_organizer_user_id', 'value' => $user_id ),
					array( 'key' => '_lnkup_event_date', 'value' => array_values( array_unique( $dates ) ), 'compare' => 'IN' ),
				),
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$out[ (string) get_post_meta( (int) $id, '_lnkup_event_date', true ) ] = lnkup_plain_title( (int) $id );
		}
		return $out;
	}
}
