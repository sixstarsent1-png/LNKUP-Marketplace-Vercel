<?php
/**
 * Organizer Portfolio OS: tour / series structure and stop lifecycle.
 *
 * Extends the existing lnkup_tour model (LNKup_Tours). Every stop or occurrence is an ordinary
 * lnkup_event created through LNKup_Organizer_Onboarding::create_event_from_request() with the client
 * token "tour{ID}_{stop key}", so retries never create duplicates. Draft stops are stored with
 * post_status "draft", which keeps them out of the marketplace index and public pages. Publishing a
 * stop validates readiness, publishes the event and creates one lnkup_opportunity per enabled booth
 * type. Applications, inventory, payments, packets and check-ins always stay on the stop.
 *
 * Deleting is never cascading: stops with applications can only be cancelled, a tour with
 * applications can only be archived, and detaching a stop keeps the event.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Portfolio_Tours {

	const ARCHIVED       = 'archived';
	const ACTIVE_APP_STATUSES = array( 'submitted', 'under_review', 'info_requested', 'approved', 'payment_required', 'waitlisted', 'paid', 'completed' );

	/**
	 * Create a tour / series with portfolio fields. Starts as a draft.
	 *
	 * @param int   $organizer_id Owner.
	 * @param array $data         title, description, type, brand_id, season, category, eligibility, website, cover_image_id.
	 * @return array|WP_Error Tour detail.
	 */
	public static function create( $organizer_id, array $data ) {
		$saved = LNKup_Tours::save(
			$organizer_id,
			array(
				'title'       => (string) ( $data['title'] ?? '' ),
				'description' => (string) ( $data['description'] ?? '' ),
				'type'        => (string) ( $data['type'] ?? 'tour' ),
				'status'      => 'draft',
			)
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$tour_id = (int) $saved['id'];
		wp_update_post(
			array(
				'ID'        => $tour_id,
				'post_name' => wp_unique_post_slug( sanitize_title( (string) $data['title'] ), $tour_id, 'publish', 'lnkup_tour', 0 ),
			)
		);
		$meta = self::save_portfolio_meta( $tour_id, $organizer_id, $data );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		LNKup_Portfolio_Audit::log( 'tour', $tour_id, 'tour_created', array( 'type' => $saved['type'] ), $organizer_id );
		return self::detail( $tour_id );
	}

	/**
	 * Update tour identity fields (PATCH semantics). Stops are managed separately.
	 *
	 * @param int   $tour_id      Tour.
	 * @param int   $organizer_id Owner.
	 * @param array $data         Fields present in the request.
	 * @return array|WP_Error
	 */
	public static function update( $tour_id, $organizer_id, array $data ) {
		$base = array_intersect_key( $data, array_flip( array( 'title', 'description', 'type' ) ) );
		if ( $base ) {
			$saved = LNKup_Tours::save( $organizer_id, $base, $tour_id );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}
		$meta = self::save_portfolio_meta( $tour_id, $organizer_id, $data );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		LNKup_Portfolio_Audit::log( 'tour', $tour_id, 'tour_updated', array( 'fields' => array_keys( $data ) ), $organizer_id );
		return self::detail( $tour_id );
	}

	/**
	 * @param int   $tour_id      Tour.
	 * @param int   $organizer_id Owner.
	 * @param array $data         Raw fields.
	 * @return true|WP_Error
	 */
	private static function save_portfolio_meta( $tour_id, $organizer_id, array $data ) {
		if ( array_key_exists( 'brand_id', $data ) ) {
			$brand_id = absint( $data['brand_id'] );
			if ( $brand_id && ! LNKup_Portfolio_Brands::owns( $brand_id, $organizer_id ) ) {
				return new WP_Error( 'lnkup_forbidden', __( 'Choose one of your own brands.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
			update_post_meta( $tour_id, '_lnkup_brand_id', $brand_id );
		}
		$text = array(
			'season'      => array( '_lnkup_season', 'sanitize_text_field', 60 ),
			'category'    => array( '_lnkup_tour_category', 'sanitize_text_field', 120 ),
			'eligibility' => array( '_lnkup_eligibility', 'sanitize_textarea_field', 2000 ),
		);
		foreach ( $text as $field => $cfg ) {
			if ( array_key_exists( $field, $data ) ) {
				update_post_meta( $tour_id, $cfg[0], mb_substr( call_user_func( $cfg[1], (string) $data[ $field ] ), 0, $cfg[2] ) );
			}
		}
		if ( array_key_exists( 'website', $data ) ) {
			update_post_meta( $tour_id, '_lnkup_tour_website', esc_url_raw( (string) $data['website'] ) );
		}
		if ( array_key_exists( 'cover_image_id', $data ) ) {
			$att = absint( $data['cover_image_id'] );
			if ( ! $att ) {
				delete_post_thumbnail( $tour_id );
			} elseif ( 'attachment' === get_post_type( $att ) && ( (int) get_post_field( 'post_author', $att ) === (int) $organizer_id || LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) ) {
				set_post_thumbnail( $tour_id, $att );
			} else {
				return new WP_Error( 'lnkup_forbidden', __( 'Upload the cover image from your own account.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
		}
		return true;
	}

	/**
	 * Tours / series for the portfolio list.
	 *
	 * @param int   $organizer_id Owner.
	 * @param array $filters      type, brand_id, status (active|archived|all).
	 */
	public static function list_for_organizer( $organizer_id, array $filters = array() ) {
		$meta_query = array(
			array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) absint( $organizer_id ) ),
		);
		$type = sanitize_key( (string) ( $filters['type'] ?? '' ) );
		if ( in_array( $type, LNKup_Tours::TYPES, true ) ) {
			$meta_query[] = array( 'key' => '_lnkup_tour_type', 'value' => $type );
		}
		$brand = absint( $filters['brand_id'] ?? 0 );
		if ( $brand ) {
			$meta_query[] = array( 'key' => '_lnkup_brand_id', 'value' => (string) $brand );
		}
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_tour',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$status = sanitize_key( (string) ( $filters['status'] ?? 'active' ) );
		$items  = array();
		foreach ( $ids as $tour_id ) {
			$archived = self::ARCHIVED === get_post_meta( $tour_id, '_lnkup_tour_status', true );
			if ( ( 'active' === $status && $archived ) || ( 'archived' === $status && ! $archived ) ) {
				continue;
			}
			$items[] = self::card( (int) $tour_id );
		}
		return $items;
	}

	/**
	 * Compact tour card: counts by stop state and the next upcoming date.
	 *
	 * @param int $tour_id Tour.
	 */
	public static function card( $tour_id ) {
		$counts = array( 'draft' => 0, 'published' => 0, 'past' => 0, 'cancelled' => 0, 'skipped' => 0, 'postponed' => 0 );
		$next   = '';
		$today  = current_time( 'Y-m-d' );
		$stops  = LNKup_Tours::stop_event_ids( $tour_id );
		foreach ( $stops as $event_id ) {
			$state = self::stop_state( $event_id );
			$counts[ $state ] = ( $counts[ $state ] ?? 0 ) + 1;
			$date = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
			if ( in_array( $state, array( 'draft', 'published' ), true ) && $date >= $today && ( '' === $next || $date < $next ) ) {
				$next = $date;
			}
		}
		$brand_id = (int) get_post_meta( $tour_id, '_lnkup_brand_id', true );
		return array(
			'id'          => (int) $tour_id,
			'title'       => lnkup_plain_title( $tour_id ),
			'type'        => (string) ( get_post_meta( $tour_id, '_lnkup_tour_type', true ) ?: 'tour' ),
			'status'      => self::tour_status( $tour_id ),
			'season'      => (string) get_post_meta( $tour_id, '_lnkup_season', true ),
			'brand'       => $brand_id ? array( 'id' => $brand_id, 'name' => lnkup_plain_title( $brand_id ) ) : null,
			'cover_url'   => (string) get_the_post_thumbnail_url( $tour_id, 'medium_large' ),
			'stop_count'  => count( $stops ),
			'counts'      => $counts,
			'next_date'   => $next,
			'public_url'  => 'publish' === get_post_status( $tour_id ) ? LNKup_Portfolio_Public::tour_url( $tour_id ) : '',
		);
	}

	/**
	 * draft | active | archived.
	 *
	 * @param int $tour_id Tour.
	 */
	public static function tour_status( $tour_id ) {
		if ( self::ARCHIVED === get_post_meta( $tour_id, '_lnkup_tour_status', true ) ) {
			return self::ARCHIVED;
		}
		return 'publish' === get_post_status( $tour_id ) ? 'active' : 'draft';
	}

	/**
	 * Full organizer view of a tour: identity, defaults, recurrence rule and every stop.
	 *
	 * @param int $tour_id Tour.
	 * @return array|WP_Error
	 */
	public static function detail( $tour_id ) {
		$post = get_post( $tour_id );
		if ( ! $post || 'lnkup_tour' !== $post->post_type ) {
			return new WP_Error( 'lnkup_not_found', __( 'Tour not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$stops = array();
		foreach ( LNKup_Tours::stop_event_ids( $tour_id ) as $event_id ) {
			$stops[] = self::format_stop( $tour_id, $event_id );
		}
		usort(
			$stops,
			static function ( $a, $b ) {
				return array( $a['order'], $a['event_date'] ?: '9999' ) <=> array( $b['order'], $b['event_date'] ?: '9999' );
			}
		);
		$rule = json_decode( (string) get_post_meta( $tour_id, '_lnkup_recurrence_rule', true ), true );
		return array_merge(
			self::card( $tour_id ),
			array(
				'description'         => (string) $post->post_content,
				'category'            => (string) get_post_meta( $tour_id, '_lnkup_tour_category', true ),
				'eligibility'         => (string) get_post_meta( $tour_id, '_lnkup_eligibility', true ),
				'website'             => (string) get_post_meta( $tour_id, '_lnkup_tour_website', true ),
				'cover_image_id'      => (int) get_post_thumbnail_id( $tour_id ),
				'defaults'            => LNKup_Portfolio_Config::get_defaults( $tour_id ),
				'recurrence_rule'     => is_array( $rule ) ? $rule : null,
				'template_version_id' => (int) get_post_meta( $tour_id, '_lnkup_template_version_id', true ),
				'stops'               => $stops,
				'max_stops'           => LNKup_Tours::MAX_STOPS,
			)
		);
	}

	/**
	 * Stop lifecycle state.
	 *
	 * @param int $event_id Stop.
	 * @return string draft|published|past|cancelled|postponed|skipped
	 */
	public static function stop_state( $event_id ) {
		if ( 'skipped' === get_post_meta( $event_id, '_lnkup_occurrence_state', true ) ) {
			return 'skipped';
		}
		$status = (string) get_post_meta( $event_id, '_lnkup_event_status', true );
		if ( in_array( $status, array( 'cancelled', 'postponed' ), true ) ) {
			return $status;
		}
		if ( 'publish' !== get_post_status( $event_id ) ) {
			return 'draft';
		}
		$date = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		return LNKup_Portfolio_Config::valid_date( $date ) && $date < current_time( 'Y-m-d' ) ? 'past' : 'published';
	}

	/**
	 * Organizer-facing stop row.
	 *
	 * @param int $tour_id  Tour.
	 * @param int $event_id Stop.
	 */
	public static function format_stop( $tour_id, $event_id ) {
		$resolved = LNKup_Portfolio_Config::resolve_for_stop( $tour_id, $event_id );
		$opps     = array();
		$apps     = 0;
		$spots    = 0;
		$held     = 0;
		foreach ( LNKup_Organizer_Onboarding::event_slot_posts( (int) $event_id ) as $opp ) {
			$total  = (int) get_post_meta( $opp->ID, '_lnkup_spots_total', true );
			$hold   = LNKup_Opportunity_Inventory::holds( $opp->ID );
			$count  = (int) get_post_meta( $opp->ID, '_lnkup_application_count', true );
			$spots += $total;
			$held  += $hold;
			$apps  += $count;
			$opps[] = array(
				'opportunity_id' => (int) $opp->ID,
				'title'          => $opp->post_title,
				'booth_type_key' => (string) get_post_meta( $opp->ID, '_lnkup_booth_type_key', true ),
				'vendor_fee'     => (float) get_post_meta( $opp->ID, '_lnkup_vendor_fee', true ),
				'spots_total'    => $total,
				'spots_held'     => $hold,
				'status'         => (string) get_post_meta( $opp->ID, '_lnkup_opportunity_status', true ),
			);
		}
		return array(
			'event_id'         => (int) $event_id,
			'key'              => (string) get_post_meta( $event_id, '_lnkup_occurrence_key', true ),
			'title'            => lnkup_plain_title( $event_id ),
			'order'            => (int) get_post_meta( $event_id, '_lnkup_tour_stop_order', true ),
			'event_date'       => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
			'start_time'       => (string) get_post_meta( $event_id, '_lnkup_start_time', true ),
			'end_time'         => (string) get_post_meta( $event_id, '_lnkup_end_time', true ),
			'timezone'         => self::stop_timezone( $event_id ),
			'city'             => (string) get_post_meta( $event_id, '_lnkup_city', true ),
			'state_code'       => (string) get_post_meta( $event_id, '_lnkup_state', true ),
			'venue_name'       => (string) get_post_meta( $event_id, '_lnkup_venue_name', true ),
			'venue_address'    => (string) get_post_meta( $event_id, '_lnkup_venue_address', true ),
			'expected_attendance' => (int) get_post_meta( $event_id, '_lnkup_expected_attendance', true ),
			'state'            => self::stop_state( $event_id ),
			'readiness'        => self::readiness( $tour_id, $event_id ),
			'config'           => $resolved,
			'opportunities'    => $opps,
			'application_count'=> $apps,
			'spots_total'      => $spots,
			'spots_held'       => $held,
			'manage_url'       => add_query_arg( 'event', (int) $event_id, lnkup_page_url_helper( 'organizer-dashboard' ) ),
		) + self::stop_figures( (int) $event_id );
	}

	/**
	 * Confirmed vendors and event-scope money for one stop (LNKup_Organizer_Presentation).
	 *
	 * @param int $event_id Stop.
	 * @return array{confirmed:int,collected_gross:float,outstanding:float}
	 */
	private static function stop_figures( $event_id ) {
		if ( ! class_exists( 'LNKup_Organizer_Presentation' ) ) {
			return array( 'confirmed' => 0, 'collected_gross' => 0.0, 'outstanding' => 0.0 );
		}
		$counts = LNKup_Organizer_Presentation::status_counts( array( $event_id ) )[ $event_id ] ?? array();
		$money  = LNKup_Organizer_Presentation::event_money( $event_id );
		return array(
			'confirmed'       => (int) ( $counts['paid'] ?? 0 ) + (int) ( $counts['completed'] ?? 0 ),
			'collected_gross' => (float) $money['collected_gross'],
			'outstanding'     => (float) $money['outstanding'],
		);
	}

	/**
	 * @param int $event_id Stop.
	 */
	public static function stop_timezone( $event_id ) {
		$tz = (string) get_post_meta( $event_id, '_lnkup_timezone', true );
		return self::valid_timezone( $tz ) ? $tz : wp_timezone_string();
	}

	/**
	 * @param string $tz Candidate IANA timezone.
	 */
	public static function valid_timezone( $tz ) {
		return is_string( $tz ) && '' !== $tz && in_array( $tz, DateTimeZone::listIdentifiers(), true );
	}

	/**
	 * What a stop still needs before it can be published. Nothing is invented: a stop without a
	 * confirmed venue stays a draft.
	 *
	 * @param int $tour_id  Tour.
	 * @param int $event_id Stop.
	 * @return array{ready:bool,missing:array<int,array{field:string,message:string}>}
	 */
	public static function readiness( $tour_id, $event_id ) {
		$missing = array();
		$add     = static function ( $field, $message ) use ( &$missing ) {
			$missing[] = array( 'field' => $field, 'message' => $message );
		};
		$state = self::stop_state( $event_id );
		if ( in_array( $state, array( 'cancelled', 'postponed', 'skipped' ), true ) ) {
			$add( 'state', __( 'This stop is cancelled or skipped.', 'lnkup-marketplace' ) );
		}
		$date  = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$today = current_time( 'Y-m-d' );
		if ( ! LNKup_Portfolio_Config::valid_date( $date ) ) {
			$add( 'event_date', __( 'Add the event date.', 'lnkup-marketplace' ) );
		} elseif ( $date < $today ) {
			$add( 'event_date', __( 'The event date has passed.', 'lnkup-marketplace' ) );
		}
		if ( '' === trim( (string) get_post_meta( $event_id, '_lnkup_venue_name', true ) ) ) {
			$add( 'venue_name', __( 'Add the confirmed venue.', 'lnkup-marketplace' ) );
		}
		if ( '' === trim( (string) get_post_meta( $event_id, '_lnkup_venue_address', true ) ) ) {
			$add( 'venue_address', __( 'Add the venue address.', 'lnkup-marketplace' ) );
		}
		if ( '' === trim( (string) get_post_meta( $event_id, '_lnkup_city', true ) ) || '' === trim( (string) get_post_meta( $event_id, '_lnkup_state', true ) ) ) {
			$add( 'city', __( 'Add the city and state.', 'lnkup-marketplace' ) );
		}
		if ( ! self::valid_timezone( (string) get_post_meta( $event_id, '_lnkup_timezone', true ) ) ) {
			$add( 'timezone', __( 'Choose the local time zone.', 'lnkup-marketplace' ) );
		}
		if ( 'publish' !== get_post_status( $event_id ) ) {
			$resolved = LNKup_Portfolio_Config::resolve_for_stop( $tour_id, $event_id );
			$enabled  = array_filter(
				$resolved['booth_types'],
				static function ( $row ) {
					return $row['enabled'] && $row['capacity'] >= 1;
				}
			);
			if ( ! $enabled ) {
				$add( 'booth_types', __( 'Offer at least one booth type with available booths.', 'lnkup-marketplace' ) );
			}
			$deadline = $resolved['deadline'];
			if ( ! LNKup_Portfolio_Config::valid_date( $deadline ) ) {
				$add( 'deadline', __( 'Set an application deadline.', 'lnkup-marketplace' ) );
			} elseif ( $deadline < $today ) {
				$add( 'deadline', __( 'The application deadline has passed. Pick a later deadline.', 'lnkup-marketplace' ) );
			} elseif ( LNKup_Portfolio_Config::valid_date( $date ) && $deadline > $date ) {
				$add( 'deadline', __( 'The application deadline must be on or before the event date.', 'lnkup-marketplace' ) );
			}
		}
		return array(
			'ready'   => ! $missing,
			'missing' => $missing,
		);
	}

	/**
	 * Validate one stop row from the wizard table, CSV paste or recurrence generator.
	 *
	 * @param array $row   Raw row.
	 * @param int   $index Row index (for messages).
	 * @return array|WP_Error Clean row.
	 */
	public static function sanitize_stop_row( array $row, $index = 0 ) {
		$clean = array(
			'title'               => mb_substr( sanitize_text_field( (string) ( $row['title'] ?? '' ) ), 0, 200 ),
			'city'                => mb_substr( sanitize_text_field( (string) ( $row['city'] ?? '' ) ), 0, 120 ),
			'state'               => mb_substr( sanitize_text_field( (string) ( $row['state'] ?? ( $row['state_code'] ?? '' ) ) ), 0, 60 ),
			'venue_name'          => mb_substr( sanitize_text_field( (string) ( $row['venue_name'] ?? '' ) ), 0, 200 ),
			'venue_address'       => mb_substr( sanitize_textarea_field( (string) ( $row['venue_address'] ?? '' ) ), 0, 500 ),
			'event_date'          => sanitize_text_field( (string) ( $row['event_date'] ?? '' ) ),
			'start_time'          => sanitize_text_field( (string) ( $row['start_time'] ?? '' ) ),
			'end_time'            => sanitize_text_field( (string) ( $row['end_time'] ?? '' ) ),
			'timezone'            => sanitize_text_field( (string) ( $row['timezone'] ?? '' ) ),
			'expected_attendance' => absint( $row['expected_attendance'] ?? 0 ),
			'key'                 => sanitize_key( (string) ( $row['key'] ?? '' ) ),
		);
		$label = sprintf( __( 'Stop %d', 'lnkup-marketplace' ), $index + 1 );
		if ( '' !== $clean['event_date'] && ! LNKup_Portfolio_Config::valid_date( $clean['event_date'] ) ) {
			return new WP_Error( 'lnkup_invalid_date', sprintf( __( '%s: use a real date (YYYY-MM-DD).', 'lnkup-marketplace' ), $label ), array( 'status' => 400, 'row' => $index ) );
		}
		foreach ( array( 'start_time', 'end_time' ) as $field ) {
			if ( '' !== $clean[ $field ] && ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $clean[ $field ] ) ) {
				return new WP_Error( 'lnkup_invalid_time', sprintf( __( '%s: use 24-hour times like 14:30.', 'lnkup-marketplace' ), $label ), array( 'status' => 400, 'row' => $index ) );
			}
		}
		if ( '' !== $clean['timezone'] && ! self::valid_timezone( $clean['timezone'] ) ) {
			return new WP_Error( 'lnkup_invalid_timezone', sprintf( __( '%s: choose a time zone from the list.', 'lnkup-marketplace' ), $label ), array( 'status' => 400, 'row' => $index ) );
		}
		if ( '' === $clean['key'] ) {
			$clean['key'] = 's' . substr( md5( strtolower( $clean['city'] . '|' . $clean['event_date'] . '|' . $clean['venue_name'] . '|' . $index ) ), 0, 12 );
		}
		return $clean;
	}

	/**
	 * Create draft stops. Each row is idempotent through its stop key, so re-submitting the same
	 * table (double click, refresh, retry after a partial failure) returns the existing stops.
	 *
	 * Runs as the tour owner: create_event_from_request() assigns the current user as organizer.
	 *
	 * @param int   $tour_id Tour.
	 * @param array $rows    Raw rows.
	 * @return array{results:array,created:int,existing:int,failed:int}|WP_Error
	 */
	public static function add_stops( $tour_id, array $rows ) {
		$owner = (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true );
		if ( get_current_user_id() !== $owner ) {
			return new WP_Error( 'lnkup_forbidden', __( 'Only the tour owner can add stops.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$existing = LNKup_Tours::stop_event_ids( $tour_id );
		if ( count( $existing ) + count( $rows ) > LNKup_Tours::MAX_STOPS ) {
			$by_key = self::stops_by_key( $existing );
			$new    = 0;
			foreach ( $rows as $i => $row ) {
				$clean = is_array( $row ) ? self::sanitize_stop_row( $row, $i ) : null;
				if ( is_array( $clean ) && ! isset( $by_key[ $clean['key'] ] ) ) {
					++$new;
				}
			}
			if ( count( $existing ) + $new > LNKup_Tours::MAX_STOPS ) {
				return new WP_Error( 'lnkup_too_many_stops', sprintf( __( 'A tour can have up to %d stops.', 'lnkup-marketplace' ), LNKup_Tours::MAX_STOPS ), array( 'status' => 400 ) );
			}
		}
		$results = array();
		$order   = count( $existing );
		foreach ( array_values( $rows ) as $i => $row ) {
			$results[] = self::create_stop( $tour_id, is_array( $row ) ? $row : array(), $i, ++$order );
		}
		$tally = array_count_values( wp_list_pluck( $results, 'result' ) );
		return array(
			'results'  => $results,
			'created'  => (int) ( $tally['created'] ?? 0 ),
			'existing' => (int) ( $tally['existing'] ?? 0 ),
			'failed'   => (int) ( $tally['failed'] ?? 0 ),
			'warnings' => self::date_overlaps( $tour_id ),
		);
	}

	/**
	 * Create one draft stop; never throws, always returns a result row.
	 *
	 * @param int   $tour_id Tour.
	 * @param array $row     Raw row.
	 * @param int   $index   Row index.
	 * @param int   $order   Stop order.
	 */
	public static function create_stop( $tour_id, array $row, $index, $order ) {
		$clean = self::sanitize_stop_row( $row, $index );
		if ( is_wp_error( $clean ) ) {
			return array( 'index' => $index, 'result' => 'failed', 'message' => $clean->get_error_message() );
		}
		$title = '' !== $clean['title'] ? $clean['title'] : trim( lnkup_plain_title( $tour_id ) . ( '' !== $clean['city'] ? ' — ' . $clean['city'] : '' ) );
		try {
			$created = LNKup_Organizer_Onboarding::create_event_from_request(
				array(
					'event_name'          => $title,
					'description'         => (string) get_post_field( 'post_content', $tour_id ),
					'event_date'          => $clean['event_date'],
					'start_time'          => $clean['start_time'],
					'end_time'            => $clean['end_time'],
					'city'                => $clean['city'],
					'state'               => $clean['state'],
					'venue_name'          => $clean['venue_name'],
					'venue_address'       => $clean['venue_address'],
					'expected_attendance' => $clean['expected_attendance'] ?: '',
					'event_type'          => (string) get_post_meta( $tour_id, '_lnkup_tour_category', true ),
					'client_token'        => 'tour' . (int) $tour_id . '_' . $clean['key'],
				)
			);
		} catch ( Throwable $e ) {
			$created = new WP_Error( 'lnkup_stop_failed', __( 'We could not create this stop. Try again.', 'lnkup-marketplace' ) );
		}
		if ( is_wp_error( $created ) ) {
			return array( 'index' => $index, 'key' => $clean['key'], 'result' => 'failed', 'message' => $created->get_error_message() );
		}
		$event_id = (int) $created['event_id'];
		if ( empty( $created['idempotent'] ) ) {
			wp_update_post( array( 'ID' => $event_id, 'post_status' => 'draft' ) );
			update_post_meta( $event_id, '_lnkup_tour_id', (int) $tour_id );
			update_post_meta( $event_id, '_lnkup_tour_stop_order', (int) $order );
			update_post_meta( $event_id, '_lnkup_occurrence_key', $clean['key'] );
			update_post_meta( $event_id, '_lnkup_occurrence_state', 'scheduled' );
			update_post_meta( $event_id, '_lnkup_timezone', self::valid_timezone( $clean['timezone'] ) ? $clean['timezone'] : '' );
			if ( ! empty( $row['overrides'] ) && is_array( $row['overrides'] ) ) {
				LNKup_Portfolio_Config::save_overrides( $event_id, $row['overrides'] );
			}
			$template_version = (int) get_post_meta( $tour_id, '_lnkup_template_version_id', true );
			if ( $template_version ) {
				update_post_meta( $event_id, '_lnkup_template_version_id', $template_version );
			}
			LNKup_Portfolio_Audit::log( 'event', $event_id, 'stop_created', array( 'tour_id' => (int) $tour_id, 'key' => $clean['key'] ), (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true ) );
			do_action( 'lnkup_portfolio_stop_created', $event_id, (int) $tour_id, $row );
		}
		return array(
			'index'    => $index,
			'key'      => $clean['key'],
			'event_id' => $event_id,
			'result'   => empty( $created['idempotent'] ) ? 'created' : 'existing',
		);
	}

	/**
	 * @param int[] $event_ids Stops.
	 * @return array<string,int> key => event ID.
	 */
	public static function stops_by_key( array $event_ids ) {
		$out = array();
		foreach ( $event_ids as $event_id ) {
			$key = (string) get_post_meta( $event_id, '_lnkup_occurrence_key', true );
			if ( '' !== $key ) {
				$out[ $key ] = (int) $event_id;
			}
		}
		return $out;
	}

	/**
	 * Dates shared by more than one active stop (a warning, not an error: a tour can run two
	 * cities on one weekend).
	 *
	 * @param int $tour_id Tour.
	 */
	public static function date_overlaps( $tour_id ) {
		$dates = array();
		foreach ( LNKup_Tours::stop_event_ids( $tour_id ) as $event_id ) {
			if ( in_array( self::stop_state( $event_id ), array( 'cancelled', 'skipped' ), true ) ) {
				continue;
			}
			$date = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
			if ( '' !== $date ) {
				$dates[ $date ][] = (int) $event_id;
			}
		}
		$warnings = array();
		foreach ( $dates as $date => $ids ) {
			if ( count( $ids ) > 1 ) {
				$warnings[] = array(
					'code'      => 'overlapping_dates',
					'date'      => $date,
					'event_ids' => $ids,
					'message'   => sprintf( __( '%1$d stops are on %2$s.', 'lnkup-marketplace' ), count( $ids ), $date ),
				);
			}
		}
		return $warnings;
	}

	/**
	 * Edit one stop. Logistics edits on a published stop go through update_event_from_request(),
	 * which notifies booked vendors; fees and payments are never touched here.
	 *
	 * @param int   $tour_id      Tour.
	 * @param int   $event_id     Stop.
	 * @param array $data         Fields to change, plus optional overrides.
	 * @param int   $organizer_id Owner.
	 * @return array|WP_Error Updated stop.
	 */
	public static function update_stop( $tour_id, $event_id, array $data, $organizer_id ) {
		$fields = array( 'title', 'event_date', 'start_time', 'end_time', 'city', 'state', 'venue_name', 'venue_address', 'expected_attendance', 'timezone' );
		$present = array_intersect_key( $data, array_flip( $fields ) );
		if ( $present ) {
			$check = self::sanitize_stop_row( array_merge( array( 'key' => 'x' ), $present ) );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$input = array();
			foreach ( $present as $field => $unused ) {
				if ( 'timezone' === $field ) {
					continue;
				}
				$input[ 'title' === $field ? 'event_name' : $field ] = $check[ $field ];
			}
			if ( $input ) {
				$updated = LNKup_Organizer_Onboarding::update_event_from_request( (int) $event_id, $input, (int) $organizer_id );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
			}
			if ( array_key_exists( 'timezone', $present ) ) {
				update_post_meta( $event_id, '_lnkup_timezone', $check['timezone'] );
			}
			if ( isset( $present['event_date'] ) && 'publish' === get_post_status( $event_id ) && 'scheduled' === get_post_meta( $event_id, '_lnkup_occurrence_state', true ) ) {
				update_post_meta( $event_id, '_lnkup_occurrence_state', 'moved' );
			}
		}
		if ( isset( $data['overrides'] ) && is_array( $data['overrides'] ) ) {
			if ( 'publish' === get_post_status( $event_id ) ) {
				return new WP_Error( 'lnkup_stop_live', __( 'This stop is live. Change booth prices with the bulk editor or on the event page so vendors who already paid keep their terms.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
			}
			LNKup_Portfolio_Config::save_overrides( $event_id, $data['overrides'] );
		}
		LNKup_Portfolio_Audit::log( 'event', $event_id, 'stop_updated', array( 'fields' => array_keys( $data ) ), $organizer_id );
		return self::format_stop( $tour_id, $event_id );
	}

	/**
	 * Whether a stop has any application other than abandoned drafts.
	 *
	 * @param int $event_id Stop.
	 */
	public static function stop_has_applications( $event_id ) {
		return (bool) get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => array( 'publish', 'pending', 'private', 'draft' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) absint( $event_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	/**
	 * Remove a draft stop that never had applications.
	 *
	 * @param int $tour_id      Tour.
	 * @param int $event_id     Stop.
	 * @param int $organizer_id Owner.
	 * @return array|WP_Error
	 */
	public static function delete_stop( $tour_id, $event_id, $organizer_id ) {
		if ( 'publish' === get_post_status( $event_id ) ) {
			return new WP_Error( 'lnkup_stop_live', __( 'Live stops cannot be removed. Cancel the stop instead so vendors are told.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( self::stop_has_applications( $event_id ) ) {
			return new WP_Error( 'lnkup_stop_has_applications', __( 'This stop has vendor applications. Cancel it instead.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		foreach ( LNKup_Organizer_Onboarding::event_slot_posts( (int) $event_id ) as $opp ) {
			$removed = LNKup_Organizer_Onboarding::delete_opportunity_for_event( (int) $opp->ID, (int) $organizer_id );
			if ( is_wp_error( $removed ) ) {
				return $removed;
			}
		}
		delete_post_meta( $event_id, '_lnkup_tour_id' );
		wp_trash_post( $event_id );
		LNKup_Portfolio_Audit::log( 'event', $event_id, 'stop_deleted', array( 'tour_id' => (int) $tour_id ), $organizer_id );
		return array( 'event_id' => (int) $event_id, 'deleted' => true );
	}

	/**
	 * Take a stop out of the tour. The event, its applications and payments stay as they are.
	 *
	 * @param int $tour_id      Tour.
	 * @param int $event_id     Stop.
	 * @param int $organizer_id Owner.
	 */
	public static function detach_stop( $tour_id, $event_id, $organizer_id ) {
		$remaining = array_values( array_diff( LNKup_Tours::stop_event_ids( $tour_id ), array( (int) $event_id ) ) );
		$saved     = LNKup_Tours::save( $organizer_id, array( 'event_ids' => self::ordered( $remaining ) ), $tour_id );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		LNKup_Portfolio_Audit::log( 'event', $event_id, 'stop_detached', array( 'tour_id' => (int) $tour_id ), $organizer_id );
		return array( 'event_id' => (int) $event_id, 'detached' => true );
	}

	/**
	 * Add existing events to a tour as stops. Only the tour linkage and stop order change: the
	 * events keep their IDs, URLs, applications and payments. Each attach is audited and can be
	 * reversed with detach_stop().
	 *
	 * @param int   $tour_id      Tour.
	 * @param int[] $event_ids    Events to attach.
	 * @param int   $organizer_id Owner.
	 * @return array|WP_Error
	 */
	public static function attach_events( $tour_id, array $event_ids, $organizer_id ) {
		$tour_id   = (int) $tour_id;
		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );
		if ( ! $event_ids ) {
			return new WP_Error( 'lnkup_no_events', __( 'Choose at least one event to add.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$current = LNKup_Tours::stop_event_ids( $tour_id );
		$new     = array();
		$skipped = array();
		foreach ( $event_ids as $event_id ) {
			if ( 'lnkup_event' !== get_post_type( $event_id ) || ! LNKup_Capabilities::organizer_owns_event( $event_id, (int) $organizer_id ) ) {
				return new WP_Error( 'lnkup_forbidden', __( 'You can only add your own events to a tour.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
			if ( in_array( $event_id, $current, true ) ) {
				$skipped[] = array( 'event_id' => $event_id, 'reason' => 'already_in_tour' );
				continue;
			}
			$other = (int) get_post_meta( $event_id, '_lnkup_tour_id', true );
			if ( $other && $other !== $tour_id && 'lnkup_tour' === get_post_type( $other ) ) {
				return new WP_Error(
					'lnkup_event_in_tour',
					/* translators: %s: event title */
					sprintf( __( '"%s" already belongs to another tour. Remove it there first.', 'lnkup-marketplace' ), lnkup_plain_title( $event_id ) ),
					array( 'status' => 409 )
				);
			}
			$new[] = $event_id;
		}
		if ( count( $current ) + count( $new ) > LNKup_Tours::MAX_STOPS ) {
			return new WP_Error( 'lnkup_too_many_stops', sprintf( /* translators: %d: max stops */ __( 'A tour can have up to %d stops.', 'lnkup-marketplace' ), LNKup_Tours::MAX_STOPS ), array( 'status' => 400 ) );
		}
		if ( $new ) {
			usort(
				$new,
				static function ( $a, $b ) {
					return strcmp( (string) get_post_meta( $a, '_lnkup_event_date', true ), (string) get_post_meta( $b, '_lnkup_event_date', true ) );
				}
			);
			$saved = LNKup_Tours::save( (int) $organizer_id, array( 'event_ids' => array_merge( self::ordered( $current ), $new ) ), $tour_id );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			foreach ( $new as $event_id ) {
				LNKup_Portfolio_Audit::log( 'event', $event_id, 'stop_attached', array( 'tour_id' => $tour_id ), (int) $organizer_id );
			}
		}
		return array(
			'tour_id'  => $tour_id,
			'attached' => $new,
			'skipped'  => $skipped,
			'stops'    => self::ordered( LNKup_Tours::stop_event_ids( $tour_id ) ),
			'warnings' => self::date_overlaps( $tour_id ),
		);
	}

	/**
	 * @param int[] $event_ids Stops.
	 * @return int[] Sorted by current stop order.
	 */
	private static function ordered( array $event_ids ) {
		usort(
			$event_ids,
			static function ( $a, $b ) {
				return (int) get_post_meta( $a, '_lnkup_tour_stop_order', true ) <=> (int) get_post_meta( $b, '_lnkup_tour_stop_order', true );
			}
		);
		return $event_ids;
	}

	/**
	 * Reorder stops. The list must contain exactly the tour's current stops.
	 *
	 * @param int   $tour_id      Tour.
	 * @param int[] $event_ids    New order.
	 * @param int   $organizer_id Owner.
	 * @return array|WP_Error
	 */
	public static function reorder( $tour_id, array $event_ids, $organizer_id ) {
		$event_ids = array_values( array_unique( array_map( 'absint', $event_ids ) ) );
		$current   = LNKup_Tours::stop_event_ids( $tour_id );
		sort( $current );
		$check = $event_ids;
		sort( $check );
		if ( $check !== $current ) {
			return new WP_Error( 'lnkup_reorder_mismatch', __( 'The stop list changed. Refresh and try again.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		foreach ( $event_ids as $i => $event_id ) {
			update_post_meta( $event_id, '_lnkup_tour_stop_order', $i + 1 );
		}
		LNKup_Portfolio_Audit::log( 'tour', $tour_id, 'stops_reordered', array( 'order' => $event_ids ), $organizer_id );
		return array( 'order' => $event_ids );
	}

	/**
	 * Copy a stop's logistics and booth overrides into a new draft stop (never its applications,
	 * approvals, payments or check-ins).
	 *
	 * @param int    $tour_id      Tour.
	 * @param int    $event_id     Source stop.
	 * @param string $client_token Request token (makes retries idempotent).
	 * @param array  $changes      Optional field changes for the copy (e.g. event_date, city).
	 * @return array|WP_Error Result row.
	 */
	public static function duplicate_stop( $tour_id, $event_id, $client_token, array $changes = array() ) {
		$token = sanitize_key( $client_token );
		if ( strlen( $token ) < 8 ) {
			return new WP_Error( 'lnkup_client_token_required', __( 'Missing request key. Refresh and try again.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$source = self::format_stop( $tour_id, $event_id );
		$row    = array_merge(
			array(
				'title'               => $source['title'],
				'city'                => $source['city'],
				'state'               => $source['state_code'],
				'venue_name'          => $source['venue_name'],
				'venue_address'       => $source['venue_address'],
				'event_date'          => $source['event_date'],
				'start_time'          => $source['start_time'],
				'end_time'            => $source['end_time'],
				'timezone'            => (string) get_post_meta( $event_id, '_lnkup_timezone', true ),
				'expected_attendance' => $source['expected_attendance'],
				'overrides'           => LNKup_Portfolio_Config::get_overrides( $event_id ),
			),
			array_intersect_key( $changes, array_flip( array( 'title', 'city', 'state', 'venue_name', 'venue_address', 'event_date', 'start_time', 'end_time', 'timezone' ) ) ),
			array( 'key' => 'd' . substr( md5( $token ), 0, 14 ) )
		);
		$result = self::add_stops( $tour_id, array( $row ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $result['results'][0];
	}

	/**
	 * Publish selected stops that are ready. Each stop is independent: a stop that is not ready
	 * is reported with what it needs and the others still publish.
	 *
	 * @param int   $tour_id      Tour.
	 * @param int[] $event_ids    Stops to publish (empty = every draft stop).
	 * @param int   $organizer_id Owner.
	 * @return array{results:array,published:int,not_ready:int,failed:int}
	 */
	public static function publish_stops( $tour_id, array $event_ids, $organizer_id ) {
		$stops     = LNKup_Tours::stop_event_ids( $tour_id );
		$event_ids = $event_ids ? array_values( array_unique( array_map( 'absint', $event_ids ) ) ) : $stops;
		$results   = array();
		foreach ( $event_ids as $event_id ) {
			if ( ! in_array( $event_id, $stops, true ) || ! LNKup_Capabilities::event_is_tour_stop( $tour_id, $event_id ) ) {
				$results[] = array( 'event_id' => $event_id, 'result' => 'failed', 'message' => __( 'This stop is not part of the tour.', 'lnkup-marketplace' ) );
				continue;
			}
			$results[] = self::publish_stop( $tour_id, $event_id, $organizer_id );
		}
		$tally = array_count_values( wp_list_pluck( $results, 'result' ) );
		if ( ! empty( $tally['published'] ) && 'draft' === self::tour_status( $tour_id ) ) {
			wp_update_post( array( 'ID' => $tour_id, 'post_status' => 'publish' ) );
			LNKup_Portfolio_Audit::log( 'tour', $tour_id, 'tour_published', array(), $organizer_id );
		}
		return array(
			'results'   => $results,
			'published' => (int) ( $tally['published'] ?? 0 ) + (int) ( $tally['already_published'] ?? 0 ),
			'not_ready' => (int) ( $tally['not_ready'] ?? 0 ),
			'failed'    => (int) ( $tally['failed'] ?? 0 ),
		);
	}

	/**
	 * @param int $tour_id      Tour.
	 * @param int $event_id     Stop.
	 * @param int $organizer_id Owner.
	 */
	private static function publish_stop( $tour_id, $event_id, $organizer_id ) {
		$base = array(
			'event_id' => (int) $event_id,
			'title'    => lnkup_plain_title( $event_id ),
			'city'     => (string) get_post_meta( $event_id, '_lnkup_city', true ),
		);
		if ( 'publish' === get_post_status( $event_id ) ) {
			return array_merge( $base, array( 'result' => 'already_published' ) );
		}
		$ready = self::readiness( $tour_id, $event_id );
		if ( ! $ready['ready'] ) {
			return array_merge( $base, array( 'result' => 'not_ready', 'missing' => $ready['missing'] ) );
		}

		global $wpdb;
		$lock = 'lnkup_pubstop_' . (int) $event_id;
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return array_merge( $base, array( 'result' => 'failed', 'message' => __( 'This stop is already being published.', 'lnkup-marketplace' ) ) );
		}
		try {
			$resolved = LNKup_Portfolio_Config::resolve_for_stop( $tour_id, $event_id );
			$saved    = wp_update_post( array( 'ID' => $event_id, 'post_status' => 'publish' ), true );
			if ( is_wp_error( $saved ) ) {
				return array_merge( $base, array( 'result' => 'failed', 'message' => __( 'We could not publish this stop.', 'lnkup-marketplace' ) ) );
			}
			$existing = LNKup_Portfolio_Config::stop_opportunities( $event_id );
			$created  = array();
			$errors   = array();
			foreach ( $resolved['booth_types'] as $booth ) {
				if ( ! $booth['enabled'] || $booth['capacity'] < 1 ) {
					continue;
				}
				if ( isset( $existing[ $booth['key'] ] ) ) {
					LNKup_Organizer_Onboarding::set_opportunity_listing_status( $existing[ $booth['key'] ], 'open', (int) $organizer_id );
					$created[] = $existing[ $booth['key'] ];
					continue;
				}
				$opp = LNKup_Organizer_Onboarding::create_opportunity_for_event(
					(int) $event_id,
					array(
						'opportunity_title'    => $booth['label'],
						'vendor_fee'           => $booth['price'],
						'spots_total'          => $booth['capacity'],
						'application_deadline' => $resolved['deadline'],
						'vendor_categories'    => array( '' !== $booth['category'] ? $booth['category'] : $booth['label'] ),
						'document_mode'        => $resolved['document_mode'],
						'required_documents'   => $resolved['required_documents'],
						'description'          => $resolved['vendor_description'],
						'client_token'         => 'tour' . (int) $tour_id . '_e' . (int) $event_id . '_b' . $booth['key'],
					),
					(int) $organizer_id
				);
				if ( is_wp_error( $opp ) ) {
					$errors[] = $booth['label'] . ': ' . $opp->get_error_message();
					continue;
				}
				$opp_id = (int) $opp['opportunity_id'];
				update_post_meta( $opp_id, '_lnkup_booth_type_key', $booth['key'] );
				if ( $resolved['questions'] ) {
					LNKup_Application_Questions::save_for_opportunity( $opp_id, $resolved['questions'], (int) $organizer_id );
				}
				$created[] = $opp_id;
			}
			if ( ! $created ) {
				wp_update_post( array( 'ID' => $event_id, 'post_status' => 'draft' ) );
				return array_merge( $base, array( 'result' => 'failed', 'message' => $errors ? implode( ' ', $errors ) : __( 'No booth types could be published.', 'lnkup-marketplace' ) ) );
			}
			LNKup_Organizer_Onboarding::sync_event_listing_status( (int) $event_id );
			LNKup_Portfolio_Audit::log( 'event', $event_id, 'stop_published', array( 'opportunities' => $created, 'errors' => $errors ), $organizer_id );
			do_action( 'lnkup_tour_stop_published', (int) $tour_id, (int) $event_id );
			return array_merge(
				$base,
				array(
					'result'          => 'published',
					'opportunity_ids' => $created,
					'warnings'        => $errors,
				)
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Cancel selected future stops through the existing event status flow (vendors are notified
	 * and slots close). Refunds stay in the Financial OS. Draft stops are simply marked cancelled.
	 *
	 * @param int    $tour_id      Tour.
	 * @param int[]  $event_ids    Stops.
	 * @param string $note         Message for vendors.
	 * @param int    $organizer_id Owner.
	 */
	public static function cancel_stops( $tour_id, array $event_ids, $note, $organizer_id ) {
		$stops   = LNKup_Tours::stop_event_ids( $tour_id );
		$results = array();
		foreach ( array_unique( array_map( 'absint', $event_ids ) ) as $event_id ) {
			if ( ! in_array( $event_id, $stops, true ) ) {
				$results[] = array( 'event_id' => $event_id, 'result' => 'failed', 'message' => __( 'This stop is not part of the tour.', 'lnkup-marketplace' ) );
				continue;
			}
			$state = self::stop_state( $event_id );
			if ( in_array( $state, array( 'cancelled', 'past' ), true ) ) {
				$results[] = array( 'event_id' => $event_id, 'result' => 'skipped', 'message' => 'past' === $state ? __( 'This stop already happened.', 'lnkup-marketplace' ) : __( 'Already cancelled.', 'lnkup-marketplace' ) );
				continue;
			}
			if ( 'draft' === $state || 'skipped' === $state ) {
				update_post_meta( $event_id, '_lnkup_event_status', 'cancelled' );
				update_post_meta( $event_id, '_lnkup_occurrence_state', 'cancelled' );
				$results[] = array( 'event_id' => $event_id, 'result' => 'cancelled' );
			} else {
				$done = LNKup_Event_Status::set_status( $event_id, 'cancelled', (string) $note, $organizer_id );
				if ( is_wp_error( $done ) ) {
					$results[] = array( 'event_id' => $event_id, 'result' => 'failed', 'message' => $done->get_error_message() );
					continue;
				}
				update_post_meta( $event_id, '_lnkup_occurrence_state', 'cancelled' );
				$results[] = array( 'event_id' => $event_id, 'result' => 'cancelled' );
			}
			LNKup_Portfolio_Audit::log( 'event', $event_id, 'stop_cancelled', array( 'tour_id' => (int) $tour_id ), $organizer_id );
		}
		return array( 'results' => $results );
	}

	/**
	 * Archive (or restore) a tour. Archiving hides the public tour page; every stop, application,
	 * booking and payment stays available for reporting.
	 *
	 * @param int  $tour_id      Tour.
	 * @param bool $archive      True = archive, false = restore.
	 * @param int  $organizer_id Owner.
	 */
	public static function set_archived( $tour_id, $archive, $organizer_id ) {
		if ( $archive ) {
			update_post_meta( $tour_id, '_lnkup_tour_status', self::ARCHIVED );
			wp_update_post( array( 'ID' => $tour_id, 'post_status' => 'draft' ) );
		} else {
			delete_post_meta( $tour_id, '_lnkup_tour_status' );
			$has_live = false;
			foreach ( LNKup_Tours::stop_event_ids( $tour_id ) as $event_id ) {
				$has_live = $has_live || 'published' === self::stop_state( $event_id );
			}
			if ( $has_live ) {
				wp_update_post( array( 'ID' => $tour_id, 'post_status' => 'publish' ) );
			}
		}
		LNKup_Portfolio_Audit::log( 'tour', $tour_id, $archive ? 'tour_archived' : 'tour_restored', array(), $organizer_id );
		return self::detail( $tour_id );
	}

	/**
	 * Delete a tour that never received applications. Stops are detached and kept as standalone
	 * events; nothing else is removed.
	 *
	 * @param int $tour_id      Tour.
	 * @param int $organizer_id Owner.
	 */
	public static function delete_tour( $tour_id, $organizer_id ) {
		$stops = LNKup_Tours::stop_event_ids( $tour_id );
		foreach ( $stops as $event_id ) {
			if ( self::stop_has_applications( $event_id ) ) {
				return new WP_Error( 'lnkup_tour_has_applications', __( 'This tour has vendor applications, so it can only be archived. Past and booked stops stay available for reporting.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
			}
		}
		foreach ( $stops as $event_id ) {
			delete_post_meta( $event_id, '_lnkup_tour_id' );
			delete_post_meta( $event_id, '_lnkup_tour_stop_order' );
		}
		wp_trash_post( $tour_id );
		LNKup_Portfolio_Audit::log( 'tour', $tour_id, 'tour_deleted', array( 'detached_stops' => $stops ), $organizer_id );
		return array( 'tour_id' => (int) $tour_id, 'deleted' => true, 'detached_stops' => $stops );
	}

	/**
	 * Organizer event list across the portfolio (single events, tour stops, series dates).
	 *
	 * @param int   $organizer_id Owner.
	 * @param array $filters      kind (all|single|tour|series|recurring), tour_id, brand_id, when (upcoming|past|all), page, per_page.
	 */
	public static function portfolio_events( $organizer_id, array $filters ) {
		$per_page   = min( 50, max( 1, absint( $filters['per_page'] ?? 20 ) ) );
		$page       = max( 1, absint( $filters['page'] ?? 1 ) );
		$today      = current_time( 'Y-m-d' );
		$meta_query = array(
			'relation' => 'AND',
			array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) absint( $organizer_id ) ),
		);
		$kind    = sanitize_key( (string) ( $filters['kind'] ?? 'all' ) );
		$tour_id = absint( $filters['tour_id'] ?? 0 );
		$brand   = absint( $filters['brand_id'] ?? 0 );
		if ( $tour_id ) {
			$meta_query[] = array( 'key' => '_lnkup_tour_id', 'value' => (string) $tour_id );
		} elseif ( 'single' === $kind ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array( 'key' => '_lnkup_tour_id', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_lnkup_tour_id', 'value' => '0' ),
			);
		} elseif ( in_array( $kind, LNKup_Tours::TYPES, true ) || $brand ) {
			$tour_ids = wp_list_pluck( self::list_for_organizer( $organizer_id, array( 'type' => in_array( $kind, LNKup_Tours::TYPES, true ) ? $kind : '', 'brand_id' => $brand, 'status' => 'all' ) ), 'id' );
			$meta_query[] = array( 'key' => '_lnkup_tour_id', 'value' => $tour_ids ? array_map( 'strval', $tour_ids ) : array( '-1' ), 'compare' => 'IN' );
		}
		$when = sanitize_key( (string) ( $filters['when'] ?? 'all' ) );
		if ( 'upcoming' === $when ) {
			$meta_query[] = array( 'key' => '_lnkup_event_date', 'value' => $today, 'compare' => '>=' );
		} elseif ( 'past' === $when ) {
			$meta_query[] = array( 'key' => '_lnkup_event_date', 'value' => $today, 'compare' => '<' );
		}
		$query = new WP_Query(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'meta_key'       => '_lnkup_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value',
				'order'          => 'past' === $when ? 'DESC' : 'ASC',
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$event_tour = (int) get_post_meta( $post->ID, '_lnkup_tour_id', true );
			$items[]    = array(
				'event_id'   => (int) $post->ID,
				'title'      => $post->post_title,
				'event_date' => (string) get_post_meta( $post->ID, '_lnkup_event_date', true ),
				'city'       => (string) get_post_meta( $post->ID, '_lnkup_city', true ),
				'state_code' => (string) get_post_meta( $post->ID, '_lnkup_state', true ),
				'state'      => self::stop_state( $post->ID ),
				'tour'       => $event_tour && 'lnkup_tour' === get_post_type( $event_tour ) ? array( 'id' => $event_tour, 'title' => lnkup_plain_title( $event_tour ), 'type' => (string) get_post_meta( $event_tour, '_lnkup_tour_type', true ) ) : null,
				'manage_url' => add_query_arg( 'event', (int) $post->ID, lnkup_page_url_helper( 'organizer-dashboard' ) ),
			);
		}
		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'page'  => $page,
		);
	}
}
