<?php
/**
 * Public discovery data for the marketing site (homepage, acquisition landings, nav menus).
 *
 * Every number comes from live marketplace records (the marketplace index and public vendor
 * Passports). Nothing is seeded, padded, rounded up, or replaced by a goal. Payloads hold only
 * public listing data, so they are safe in page caches and anonymous REST responses.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Public_Discovery {

	const CACHE_KEY         = 'lnkup_public_discovery_v1';
	const VENDORS_CACHE_KEY = 'lnkup_public_vendors_v1';
	const CACHE_TTL         = 10 * MINUTE_IN_SECONDS;
	const MAX_CITIES        = 8;
	const MAX_TOURS         = 6;
	const MAX_VENDORS       = 12;

	/** Card sets the homepage and landings may request. Keys are public; values are search args. */
	const CARD_SETS = array(
		'upcoming' => array( 'sort' => 'soonest' ),
		'weekend'  => array( 'sort' => 'soonest', 'date_preset' => 'weekend' ),
		'closing'  => array( 'sort' => 'deadline', 'closing_soon' => true ),
		'newest'   => array( 'sort' => 'newest' ),
	);

	public static function init(): void {
		add_action( 'save_post_lnkup_opportunity', array( __CLASS__, 'flush' ) );
		add_action( 'save_post_lnkup_event', array( __CLASS__, 'flush' ) );
		add_action( 'save_post_lnkup_tour', array( __CLASS__, 'flush' ) );
		add_action( 'save_post_lnkup_vendor_profile', array( __CLASS__, 'flush_vendors' ) );
		add_action( 'update_option_lnkup_city_markets', array( __CLASS__, 'flush' ) );
		add_action( 'update_option_lnkup_landing', array( __CLASS__, 'flush_vendors' ) );
	}

	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	public static function flush_vendors(): void {
		delete_transient( self::VENDORS_CACHE_KEY );
	}

	/**
	 * Categories, cities, tours and marketplace counts for open, bookable listings.
	 *
	 * @return array{categories:array<int,array<string,mixed>>,cities:array<int,array<string,mixed>>,tours:array<int,array<string,mixed>>,stats:array<string,int>,generated_at:string}
	 */
	public static function snapshot(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['stats'] ) ) {
			return $cached;
		}
		try {
			$data = self::build();
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'LNKup public discovery: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return self::empty_snapshot();
		}
		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function empty_snapshot(): array {
		return array(
			'categories'   => array(),
			'cities'       => array(),
			'city_index'   => array(),
			'tours'        => array(),
			'stats'        => array( 'open_opportunities' => 0, 'open_events' => 0, 'cities' => 0, 'organizers' => 0, 'categories' => 0 ),
			'generated_at' => gmdate( 'c' ),
		);
	}

	/**
	 * Open, bookable index rows (spots left, event and deadline not passed).
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function open_rows(): array {
		if ( ! class_exists( 'LNKup_Marketplace_Index' ) ) {
			return array();
		}
		global $wpdb;
		$table = LNKup_Marketplace_Index::table_name();
		$today = current_time( 'Y-m-d' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT idx.opportunity_id, idx.event_id, idx.organizer_user_id, idx.city, idx.state, idx.categories, idx.tour_id FROM {$table} idx
				INNER JOIN {$wpdb->posts} opp ON opp.ID = idx.opportunity_id AND opp.post_type = 'lnkup_opportunity' AND opp.post_status = 'publish'
				INNER JOIN {$wpdb->posts} ev ON ev.ID = idx.event_id AND ev.post_type = 'lnkup_event' AND ev.post_status = 'publish'
				WHERE idx.spots_remaining > 0 AND ( idx.event_date IS NULL OR idx.event_date >= %s ) AND ( idx.deadline IS NULL OR idx.deadline >= %s )",
				$today,
				$today
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function build(): array {
		$rows       = self::open_rows();
		$feed       = self::feed_url();
		$events     = array();
		$organizers = array();
		$cat_events = array();
		$cat_terms  = array();
		$city_rows  = array();
		$tour_rows  = array();

		foreach ( $rows as $row ) {
			$event_id = (int) $row['event_id'];
			$events[ $event_id ] = true;
			if ( (int) $row['organizer_user_id'] > 0 ) {
				$organizers[ (int) $row['organizer_user_id'] ] = true;
			}
			$names = json_decode( (string) $row['categories'], true );
			foreach ( is_array( $names ) ? $names : array() as $name ) {
				$label = function_exists( 'lnkup_canonical_category_label' ) ? lnkup_canonical_category_label( (string) $name ) : (string) $name;
				if ( '' !== $label ) {
					$cat_events[ $label ][ $event_id ] = true;
					$cat_terms[ $label ][ (string) $name ] = true;
				}
			}
			$city  = trim( (string) $row['city'] );
			$state = strtoupper( trim( (string) $row['state'] ) );
			if ( '' !== $city && 'Location TBA' !== $city ) {
				$key = strtolower( $city ) . '|' . $state;
				if ( ! isset( $city_rows[ $key ] ) ) {
					$city_rows[ $key ] = array( 'city' => $city, 'state' => $state, 'events' => array() );
				}
				$city_rows[ $key ]['events'][ $event_id ] = true;
			}
			if ( (int) $row['tour_id'] > 0 ) {
				$tour_rows[ (int) $row['tour_id'] ][ $event_id ] = true;
			}
		}

		$categories = array();
		foreach ( $cat_events as $label => $set ) {
			$categories[] = array(
				'label' => (string) $label,
				'slug'  => sanitize_title( (string) $label ),
				'count' => count( $set ),
				'terms' => array_keys( $cat_terms[ $label ] ?? array() ),
				'url'   => add_query_arg( 'categories', rawurlencode( (string) $label ), $feed ),
			);
		}
		usort( $categories, array( __CLASS__, 'by_count' ) );

		$cities     = array();
		$city_index = array();
		foreach ( $city_rows as $entry ) {
			$city_index[] = array(
				'city'  => $entry['city'],
				'state' => $entry['state'],
				'label' => function_exists( 'lnkup_display_place' ) ? lnkup_display_place( $entry['city'], $entry['state'] ) : $entry['city'],
			);
			$cities[] = array(
				'label'      => function_exists( 'lnkup_display_place' ) ? lnkup_display_place( $entry['city'], $entry['state'] ) : $entry['city'],
				'city'       => $entry['city'],
				'state'      => $entry['state'],
				'count'      => count( $entry['events'] ),
				'url'        => add_query_arg( array_filter( array( 'city' => $entry['city'], 'state' => $entry['state'] ) ), $feed ),
				'market_url' => self::market_url_for( $entry['city'], $entry['state'] ),
			);
		}
		usort( $cities, array( __CLASS__, 'by_count' ) );

		$tours = array();
		foreach ( $tour_rows as $tour_id => $set ) {
			$card = self::tour_card( (int) $tour_id, count( $set ) );
			if ( null !== $card ) {
				$tours[] = $card;
			}
		}
		usort( $tours, array( __CLASS__, 'by_count' ) );

		return array(
			'categories'   => $categories,
			'cities'       => array_slice( $cities, 0, self::MAX_CITIES ),
			'city_index'   => $city_index,
			'tours'        => array_slice( $tours, 0, self::MAX_TOURS ),
			'stats'        => array(
				'open_opportunities' => count( $rows ),
				'open_events'        => count( $events ),
				'cities'             => count( $city_rows ),
				'organizers'         => count( $organizers ),
				'categories'         => count( $categories ),
			),
			'generated_at' => gmdate( 'c' ),
		);
	}

	/**
	 * @param array<string,mixed> $a Row.
	 * @param array<string,mixed> $b Row.
	 */
	private static function by_count( array $a, array $b ): int {
		return ( (int) $b['count'] <=> (int) $a['count'] ) ?: strcmp( (string) $a['label'], (string) $b['label'] );
	}

	/**
	 * Every city with an open listing (validation list for untrusted city parameters).
	 *
	 * @return array<int,array{city:string,state:string,label:string}>
	 */
	public static function known_cities(): array {
		$snapshot = self::snapshot();
		return (array) ( $snapshot['city_index'] ?? array() );
	}

	/**
	 * Live category entry for a canonical label, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function category( string $label ): ?array {
		foreach ( self::snapshot()['categories'] as $entry ) {
			if ( 0 === strcasecmp( (string) $entry['label'], $label ) ) {
				return $entry;
			}
		}
		return null;
	}

	public static function feed_url(): string {
		return function_exists( 'lnkup_page_url_helper' ) ? (string) lnkup_page_url_helper( 'opportunities' ) : home_url( '/opportunities/' );
	}

	/**
	 * Enabled city market page for a city, or empty.
	 */
	private static function market_url_for( string $city, string $state ): string {
		if ( ! class_exists( 'LNKup_Market_Pages' ) || ! LNKup_Market_Pages::is_enabled() ) {
			return '';
		}
		foreach ( LNKup_Market_Pages::get_all_markets() as $slug => $market ) {
			if ( ! is_array( $market ) || empty( $market['enabled'] ) ) {
				continue;
			}
			$same_city  = 0 === strcasecmp( trim( (string) ( $market['city'] ?? '' ) ), $city );
			$same_state = '' === $state || 0 === strcasecmp( trim( (string) ( $market['state'] ?? '' ) ), $state );
			if ( $same_city && $same_state ) {
				return LNKup_Market_Pages::market_url( (string) ( $market['slug'] ?? $slug ) );
			}
		}
		return '';
	}

	/**
	 * Public tour/series card, only when its public page (or tour feed filter) is live.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function tour_card( int $tour_id, int $open_events ): ?array {
		if ( ! class_exists( 'LNKup_Tours' ) ) {
			return null;
		}
		$summary = LNKup_Tours::card_summary( $tour_id );
		if ( ! $summary ) {
			return null;
		}
		$owner = (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true );
		$url   = '';
		if ( class_exists( 'LNKup_Feature_Flags' ) && class_exists( 'LNKup_Portfolio_Public' ) && LNKup_Feature_Flags::portfolio( $owner ) ) {
			$url = LNKup_Portfolio_Public::tour_url( $tour_id );
		} elseif ( class_exists( 'LNKup_Match_Settings' ) && LNKup_Match_Settings::enabled( 'matching_tours_enabled' ) ) {
			$url = add_query_arg( 'tour_id', $tour_id, self::feed_url() );
		}
		if ( '' === $url ) {
			return null;
		}
		$image = '';
		$thumb = (int) get_post_thumbnail_id( $tour_id );
		if ( $thumb ) {
			$image = (string) wp_get_attachment_image_url( $thumb, 'medium_large' );
		}
		return array(
			'id'          => $tour_id,
			'label'       => (string) $summary['title'],
			'type'        => 'tour' === $summary['type'] ? 'tour' : 'series',
			'stop_count'  => (int) $summary['stop_count'],
			'count'       => $open_events,
			'image'       => $image,
			'image_id'    => $thumb,
			'url'         => $url,
		);
	}

	/**
	 * Vendors who made their Passport public. Admin picks win; otherwise the most recently updated
	 * public Passports that have a business name and artwork. Only public identity fields leave here.
	 *
	 * @param int $limit Max vendors.
	 * @return array<int,array<string,mixed>>
	 */
	public static function featured_vendors( int $limit = 8 ): array {
		$limit  = max( 1, min( self::MAX_VENDORS, $limit ) );
		$cached = get_transient( self::VENDORS_CACHE_KEY );
		if ( ! is_array( $cached ) ) {
			try {
				$cached = self::build_vendors();
			} catch ( Throwable $e ) {
				$cached = array();
			}
			set_transient( self::VENDORS_CACHE_KEY, $cached, self::CACHE_TTL );
		}
		return array_slice( $cached, 0, $limit );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function build_vendors(): array {
		if ( ! class_exists( 'LNKup_Passport_Privacy' ) || ! class_exists( 'LNKup_Passport_Public' ) ) {
			return array();
		}
		$landing = get_option( 'lnkup_landing', array() );
		$pinned  = is_array( $landing ) && ! empty( $landing['featured_vendor_ids'] ) ? array_map( 'absint', (array) $landing['featured_vendor_ids'] ) : array();
		$ids     = $pinned;
		if ( count( $ids ) < self::MAX_VENDORS ) {
			$recent = get_posts(
				array(
					'post_type'      => 'lnkup_vendor_profile',
					'post_status'    => 'publish',
					'posts_per_page' => 60,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'post__not_in'   => $pinned ? $pinned : array( 0 ),
				)
			);
			$ids = array_merge( $ids, array_map( 'intval', $recent ) );
		}
		$out = array();
		foreach ( $ids as $profile_id ) {
			$card = self::vendor_card( (int) $profile_id );
			if ( null !== $card ) {
				$out[] = $card;
			}
			if ( count( $out ) >= self::MAX_VENDORS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function vendor_card( int $profile_id ): ?array {
		if ( $profile_id < 1 || ! LNKup_Passport_Privacy::is_public( $profile_id ) ) {
			return null;
		}
		$name = trim( (string) get_post_meta( $profile_id, '_lnkup_business_name', true ) );
		if ( '' === $name ) {
			$name = trim( (string) get_the_title( $profile_id ) );
		}
		$logo_id  = (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true );
		$cover_id = (int) get_post_meta( $profile_id, '_lnkup_cover_image_id', true );
		if ( '' === $name || ( ! $logo_id && ! $cover_id ) ) {
			return null;
		}
		$url = LNKup_Passport_Public::url_for_profile( $profile_id );
		if ( '' === $url ) {
			return null;
		}
		$category = '';
		$primary  = (int) get_post_meta( $profile_id, '_lnkup_primary_category', true );
		$terms    = wp_get_post_terms( $profile_id, 'lnkup_vendor_category' );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( '' === $category || $primary === (int) $term->term_id ) {
					$category = function_exists( 'lnkup_canonical_category_label' ) ? lnkup_canonical_category_label( $term->name ) : $term->name;
				}
			}
		}
		$city  = (string) get_post_meta( $profile_id, '_lnkup_city', true );
		$state = (string) get_post_meta( $profile_id, '_lnkup_state', true );
		return array(
			'id'       => $profile_id,
			'name'     => $name,
			'category' => $category,
			'place'    => function_exists( 'lnkup_display_place' ) ? lnkup_display_place( $city, $state ) : trim( $city . ', ' . $state, ', ' ),
			'logo'     => $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '',
			'cover'    => $cover_id ? (string) wp_get_attachment_image_url( $cover_id, 'medium_large' ) : '',
			'cover_id' => $cover_id,
			'url'      => $url,
		);
	}

	/**
	 * Event cards for a named set (see CARD_SETS) with optional validated context filters.
	 *
	 * @param string              $set     Card set key.
	 * @param int                 $limit   Max events.
	 * @param array<string,mixed> $context Optional city, state, categories (already validated).
	 * @return array<int,array<string,mixed>>
	 */
	public static function event_cards( string $set = 'upcoming', int $limit = 6, array $context = array() ): array {
		if ( ! class_exists( 'LNKup_Discovery' ) || ! function_exists( 'lnkup_group_opportunities_by_event' ) ) {
			return array();
		}
		$args  = self::CARD_SETS[ $set ] ?? self::CARD_SETS['upcoming'];
		$limit = max( 1, min( 12, $limit ) );
		$where = array_intersect_key( $context, array_flip( array( 'city', 'state' ) ) );
		if ( ! empty( $context['categories'] ) ) {
			$entry = self::category( (string) $context['categories'] );
			if ( null === $entry ) {
				return array();
			}
			$where['categories'] = $entry['terms'] ? $entry['terms'] : array( $entry['label'] );
		}
		$args  = array_merge(
			$args,
			$where,
			array(
				'has_spots' => true,
				'per_page'  => min( 48, $limit * 4 ),
				'page'      => 1,
			)
		);
		try {
			$result = LNKup_Discovery::search( $args );
		} catch ( Throwable $e ) {
			return array();
		}
		$groups = lnkup_group_opportunities_by_event( (array) ( $result['items'] ?? array() ) );
		$cards  = array();
		foreach ( $groups as $slots ) {
			$card = self::event_card( $slots );
			if ( null !== $card ) {
				$cards[] = $card;
			}
			if ( count( $cards ) >= $limit ) {
				break;
			}
		}
		return $cards;
	}

	/**
	 * Card view-model for one event and its open vendor slots. Fee and spot values come straight
	 * from the listing; when a fee is unknown the card says so instead of guessing.
	 *
	 * @param array<int,array<string,mixed>> $slots Opportunity rows of one event.
	 * @return array<string,mixed>|null
	 */
	public static function event_card( array $slots ): ?array {
		$slots = array_values(
			array_filter(
				$slots,
				static function ( $slot ) {
					$opp_id = is_array( $slot ) ? (int) ( $slot['opportunity_id'] ?? $slot['id'] ?? 0 ) : 0;
					return $opp_id > 0 && 'publish' === get_post_status( $opp_id );
				}
			)
		);
		if ( ! $slots ) {
			return null;
		}
		$first    = $slots[0];
		$event_id = (int) ( $first['event_id'] ?? 0 );
		$event    = $event_id ? get_post( $event_id ) : null;
		if ( ! $event || 'publish' !== $event->post_status ) {
			return null;
		}
		$date     = (string) ( $first['date'] ?? '' );
		$title    = $event && function_exists( 'lnkup_plain_title' ) ? lnkup_plain_title( $event ) : (string) ( $first['event_name'] ?? $first['name'] ?? '' );
		$venue    = ( $event_id && ! get_post_meta( $event_id, '_lnkup_venue_hidden', true ) ) ? (string) get_post_meta( $event_id, '_lnkup_venue_name', true ) : '';
		$org_name = trim( (string) ( $first['organizer_name'] ?? '' ) );
		if ( function_exists( 'lnkup_display_event_title' ) ) {
			$title = lnkup_display_event_title( $title, $date, $venue, $org_name );
		}
		if ( '' === trim( $title ) ) {
			return null;
		}

		$fee_min   = null;
		$fee_max   = null;
		$spots     = 0;
		$closing   = false;
		$cats      = array();
		$apply_id  = 0;
		$gated = false;
		foreach ( $slots as $slot ) {
			if ( ! empty( $slot['access']['gated'] ) ) {
				// Guest rows carry a status instead of numbers; count open slots, never spaces.
				$gated  = true;
				$status = (string) ( $slot['opportunity_status']['key'] ?? 'open' );
				if ( ! in_array( $status, array( 'open', 'closing_soon' ), true ) ) {
					continue;
				}
				$spots++;
				$closing = $closing || 'closing_soon' === $status;
			} else {
				$left = (int) ( $slot['spots_remaining'] ?? 0 );
				$open = ! isset( $slot['is_open'] ) || ! empty( $slot['is_open'] );
				if ( ! $open || $left < 1 ) {
					continue;
				}
				$spots += $left;
			}
			if ( in_array( 'closing_soon', (array) ( $slot['badges'] ?? array() ), true ) ) {
				$closing = true;
			}
			if ( array_key_exists( 'vendor_fee', $slot ) && null !== $slot['vendor_fee'] && '' !== $slot['vendor_fee'] ) {
				$fee     = (float) $slot['vendor_fee'];
				$fee_min = null === $fee_min ? $fee : min( $fee_min, $fee );
				$fee_max = null === $fee_max ? $fee : max( $fee_max, $fee );
			}
			if ( ! $apply_id ) {
				$apply_id = (int) ( $slot['opportunity_id'] ?? $slot['id'] ?? 0 );
			}
			foreach ( (array) ( $slot['categories'] ?? array() ) as $cat ) {
				$label = function_exists( 'lnkup_canonical_category_label' ) ? lnkup_canonical_category_label( (string) $cat ) : (string) $cat;
				if ( '' !== $label ) {
					$cats[ strtolower( $label ) ] = $label;
				}
			}
		}
		if ( $spots < 1 ) {
			return null;
		}

		if ( $gated ) {
			$fee_label = null;
		} elseif ( null === $fee_min ) {
			$fee_label = __( 'See booth pricing', 'lnkup-marketplace' );
		} elseif ( $fee_min <= 0 && ( null === $fee_max || $fee_max <= 0 ) ) {
			$fee_label = __( 'Free booths', 'lnkup-marketplace' );
		} else {
			/* translators: %s: lowest booth price */
			$fee_label = sprintf( __( 'Booths from %s', 'lnkup-marketplace' ), lnkup_public_money( max( 0.0, (float) $fee_min ) ) );
		}

		$media    = array();
		$fallback = array();
		if ( function_exists( 'lnkup_card_media_for_row' ) ) {
			list( $media, $fallback ) = lnkup_card_media_for_row( $first );
		}
		$url = ( $event_id && class_exists( 'LNKup_Organizer_Onboarding' ) ) ? (string) LNKup_Organizer_Onboarding::event_public_url( $event_id ) : '';
		if ( '' === $url && $apply_id ) {
			$url = add_query_arg( 'event', $event_id ?: $apply_id, self::feed_url() );
		}
		$tour = ( $event_id && class_exists( 'LNKup_Portfolio_Public' ) ) ? LNKup_Portfolio_Public::card_tour_link( $event_id ) : null;

		return array(
			'event_id'       => $event_id,
			'opportunity_id' => $apply_id,
			'title'          => $title,
			'organizer'      => $org_name,
			'verified'       => ! empty( $first['organizer_verified'] ),
			'place'          => function_exists( 'lnkup_display_place' ) ? lnkup_display_place( (string) ( $first['city'] ?? '' ), (string) ( $first['state'] ?? '' ) ) : '',
			'date'           => $date,
			'date_label'     => $date ? date_i18n( 'D, M j', strtotime( $date ) ) : '',
			'date_iso'       => $date ? gmdate( 'Y-m-d', (int) strtotime( $date ) ) : '',
			'fee_label'      => $fee_label,
			'spots'          => $gated ? null : $spots,
			'closing_soon'   => $closing,
			'status_label'   => $closing ? __( 'Applications closing soon', 'lnkup-marketplace' ) : __( 'Vendor applications open', 'lnkup-marketplace' ),
			'access'         => $gated
				? array( 'gated' => true, 'cta' => LNKup_Access_Policy::guest_cta( 'pricing', $url ) )
				: array( 'gated' => false ),
			'categories'     => array_slice( array_values( $cats ), 0, 3 ),
			'more_categories'=> max( 0, count( $cats ) - 3 ),
			'media'          => $media,
			'media_fallback' => $fallback,
			'alt'            => function_exists( 'lnkup_opportunity_card_alt' ) ? lnkup_opportunity_card_alt( $first ) : $title,
			'url'            => $url,
			'tour'           => is_array( $tour ) ? $tour : null,
		);
	}

	/**
	 * Live pricing from the Fee Engine (never hardcoded in templates). The worked example is the
	 * Fee Engine's own calculation for a sample booth with no organizer- or event-specific rules.
	 *
	 * @return array<string,mixed>
	 */
	public static function pricing(): array {
		$settings  = function_exists( 'lnkup_get_settings' ) ? (array) lnkup_get_settings() : array();
		$fee_payer = 'organizer' === ( $settings['fee_payer_mode'] ?? 'vendor' ) ? 'organizer' : 'vendor';
		$booth     = 150.0;
		if ( ! class_exists( 'LNKup_Fee_Engine' ) ) {
			return array();
		}
		$service    = (float) LNKup_Fee_Engine::resolve_vendor_checkout_fee_from_settings();
		$commission = (float) LNKup_Fee_Engine::resolve_organizer_commission( $booth );
		$example    = LNKup_Fee_Engine::calculate( array( 'vendor_fee' => $booth ) );
		return array(
			'fee_payer'                    => $fee_payer,
			'vendor_checkout_fee'          => 'vendor' === $fee_payer ? round( max( 0.0, $service ), 2 ) : 0.0,
			'service_fee'                  => round( max( 0.0, $service ), 2 ),
			'organizer_commission_percent' => round( max( 0.0, $commission / $booth * 100 ), 2 ),
			'processing_percent'           => round( LNKup_Fee_Engine::PROCESSING_RATE * 100, 2 ),
			'processing_flat'              => (float) LNKup_Fee_Engine::PROCESSING_FLAT,
			'example'                      => array(
				'booth'              => $booth,
				'vendor_service_fee' => (float) $example['vendor_checkout_fee'],
				'processing_fee'     => (float) $example['processing_fee'],
				'tax'                => (float) $example['tax_amount'],
				'vendor_total'       => (float) $example['total'],
				'organizer_fee'      => (float) $example['organizer_commission'],
				'organizer_payout'   => (float) $example['organizer_net'],
			),
		);
	}
}
