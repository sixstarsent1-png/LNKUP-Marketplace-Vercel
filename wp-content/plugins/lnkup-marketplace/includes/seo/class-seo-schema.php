<?php
/**
 * Schema.org graph pieces built only from what the public page shows.
 *
 * Event: real date, time and IANA timezone, end date when known, accurate status (cancelled,
 * postponed, rescheduled with previousStartDate), venue address only when the venue is public,
 * resolved event image, organizer. No `offers`: vendor booth fees are not admission prices.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_SEO_Schema {

	const PREVIOUS_START_META = '_lnkup_seo_previous_start';

	public static function init(): void {
		add_action( 'update_postmeta', array( __CLASS__, 'remember_previous_start' ), 10, 4 );
	}

	/**
	 * When an event date moves, keep the old date so the schema can say EventRescheduled.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post.
	 * @param string $meta_key   Key.
	 * @param mixed  $meta_value New value.
	 */
	public static function remember_previous_start( $meta_id, $object_id, $meta_key, $meta_value ): void {
		unset( $meta_id );
		if ( '_lnkup_event_date' !== $meta_key || 'lnkup_event' !== get_post_type( (int) $object_id ) ) {
			return;
		}
		$old = (string) get_post_meta( (int) $object_id, '_lnkup_event_date', true );
		if ( '' !== $old && $old !== (string) $meta_value && 'publish' === get_post_status( (int) $object_id ) ) {
			update_post_meta( (int) $object_id, self::PREVIOUS_START_META, $old );
		}
	}

	/**
	 * ISO 8601 date-time in the event's timezone (date only when no time is known).
	 */
	public static function iso( string $date, string $time, string $tz ): string {
		$date = substr( $date, 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}
		if ( ! preg_match( '/^\d{1,2}:\d{2}/', $time ) ) {
			return $date;
		}
		try {
			$dt = new DateTimeImmutable( $date . ' ' . substr( $time, 0, 5 ), new DateTimeZone( $tz ) );
			return $dt->format( 'c' );
		} catch ( Exception $e ) {
			return $date;
		}
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function event( int $event_id ): ?array {
		$post = get_post( $event_id );
		if ( ! $post || 'lnkup_event' !== $post->post_type ) {
			return null;
		}
		$tz    = class_exists( 'LNKup_Portfolio_Tours' ) ? LNKup_Portfolio_Tours::stop_timezone( $event_id ) : wp_timezone_string();
		$date  = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$start = self::iso( $date, (string) get_post_meta( $event_id, '_lnkup_start_time', true ), $tz );
		if ( '' === $start ) {
			return null;
		}
		$end_date = (string) get_post_meta( $event_id, '_lnkup_event_end_date', true );
		$end_date = preg_match( '/^\d{4}-\d{2}-\d{2}/', $end_date ) ? $end_date : $date;
		$end      = self::iso( $end_date, (string) get_post_meta( $event_id, '_lnkup_end_time', true ), $tz );

		$status   = (string) get_post_meta( $event_id, '_lnkup_event_status', true );
		$previous = (string) get_post_meta( $event_id, self::PREVIOUS_START_META, true );
		$schema   = array(
			'@type'               => 'Event',
			'@id'                 => get_permalink( $event_id ) . '#event',
			'name'                => function_exists( 'lnkup_plain_title' ) ? lnkup_plain_title( $post ) : get_the_title( $post ),
			'url'                 => get_permalink( $event_id ),
			'startDate'           => $start,
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'eventStatus'         => 'https://schema.org/EventScheduled',
		);
		if ( '' !== $end && $end !== $start ) {
			$schema['endDate'] = $end;
		}
		if ( in_array( $status, array( 'cancelled', 'canceled' ), true ) ) {
			$schema['eventStatus'] = 'https://schema.org/EventCancelled';
		} elseif ( 'postponed' === $status ) {
			$schema['eventStatus'] = 'https://schema.org/EventPostponed';
		} elseif ( '' !== $previous && substr( $previous, 0, 10 ) !== substr( $date, 0, 10 ) ) {
			$schema['eventStatus']       = 'https://schema.org/EventRescheduled';
			$schema['previousStartDate'] = substr( $previous, 0, 10 );
		}

		$description = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
		if ( '' === $description ) {
			$description = wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 40, '…' );
		}
		if ( '' !== $description ) {
			$schema['description'] = $description;
		}

		$city   = (string) get_post_meta( $event_id, '_lnkup_city', true );
		$state  = (string) get_post_meta( $event_id, '_lnkup_state', true );
		$hidden = (bool) get_post_meta( $event_id, '_lnkup_venue_hidden', true );
		$venue  = $hidden ? '' : trim( (string) get_post_meta( $event_id, '_lnkup_venue_name', true ) );
		$street = $hidden ? '' : trim( (string) get_post_meta( $event_id, '_lnkup_venue_address', true ) );
		$zip    = $hidden ? '' : trim( (string) get_post_meta( $event_id, '_lnkup_zip', true ) );
		$address = array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $street,
				'addressLocality' => $city,
				'addressRegion'   => $state,
				'postalCode'      => $zip,
				'addressCountry'  => 'US',
			)
		);
		$schema['location'] = array(
			'@type'   => 'Place',
			'name'    => '' !== $venue ? $venue : trim( $city . ( $state ? ', ' . $state : '' ) ),
			'address' => $address,
		);

		if ( class_exists( 'LNKup_Event_Media' ) ) {
			$media = LNKup_Event_Media::resolve( $event_id );
			$image = (string) ( $media['og_url'] ?? '' );
			if ( '' !== $image && function_exists( 'lnkup_seo_is_raster_url' ) && lnkup_seo_is_raster_url( $image ) ) {
				$schema['image'] = array( $image );
			}
		}

		$org_id = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		if ( $org_id ) {
			$name = trim( (string) get_user_meta( $org_id, '_lnkup_organizer_public_name', true ) );
			if ( '' === $name ) {
				$user = get_userdata( $org_id );
				$name = $user ? (string) $user->display_name : '';
			}
			if ( '' !== $name ) {
				$schema['organizer'] = array_filter(
					array(
						'@type' => 'Organization',
						'name'  => $name,
						'url'   => class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::organizer_public_url( $org_id ) : '',
					)
				);
			}
		}
		return $schema;
	}

	/**
	 * @param array<int,array{0:string,1:string}> $crumbs [name, url] pairs from Home onward.
	 * @return array<string,mixed>
	 */
	public static function breadcrumbs( array $crumbs ): array {
		$items = array();
		foreach ( array_values( $crumbs ) as $i => $crumb ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $crumb[0],
				'item'     => $crumb[1],
			);
		}
		return array( '@type' => 'BreadcrumbList', 'itemListElement' => $items );
	}

	/**
	 * Breadcrumb trail for an event: Home > Events > {City market} > Event.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function event_crumbs( int $event_id ): array {
		$crumbs = array( array( __( 'Home', 'lnkup-marketplace' ), home_url( '/' ) ) );
		$feed   = function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'opportunities' ) : home_url( '/opportunities/' );
		$crumbs[] = array( __( 'Events', 'lnkup-marketplace' ), $feed );
		$city   = (string) get_post_meta( $event_id, '_lnkup_city', true );
		$state  = (string) get_post_meta( $event_id, '_lnkup_state', true );
		$market = self::market_for_city( $city, $state );
		if ( $market ) {
			$crumbs[] = array( (string) $market['display_name'], LNKup_Market_Pages::market_url( (string) $market['slug'] ) );
		}
		$crumbs[] = array( function_exists( 'lnkup_plain_title' ) ? lnkup_plain_title( $event_id ) : get_the_title( $event_id ), (string) get_permalink( $event_id ) );
		return $crumbs;
	}

	/**
	 * Enabled market for a city.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function market_for_city( string $city, string $state ): ?array {
		if ( '' === $city || ! class_exists( 'LNKup_Market_Pages' ) || ! LNKup_Market_Pages::is_enabled() ) {
			return null;
		}
		foreach ( LNKup_Market_Pages::get_all_markets() as $slug => $market ) {
			if ( ! is_array( $market ) || empty( $market['enabled'] ) ) {
				continue;
			}
			if ( 0 === strcasecmp( (string) ( $market['city'] ?? '' ), $city ) && ( '' === $state || 0 === strcasecmp( (string) ( $market['state'] ?? '' ), $state ) ) ) {
				$normalized = LNKup_Market_Pages::get_market_by_slug( (string) ( $market['slug'] ?? $slug ) );
				return is_array( $normalized ) ? $normalized : null;
			}
		}
		return null;
	}

	/**
	 * ProfilePage for an organizer profile.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function organizer_graph( int $user_id ): array {
		if ( ! class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			return array();
		}
		$url  = LNKup_Organizer_Onboarding::organizer_public_url( $user_id );
		$name = trim( (string) get_user_meta( $user_id, '_lnkup_organizer_public_name', true ) );
		if ( '' === $name ) {
			$user = get_userdata( $user_id );
			$name = $user ? (string) $user->display_name : '';
		}
		if ( '' === $name ) {
			return array();
		}
		$entity = array(
			'@type' => 'Organization',
			'@id'   => $url . '#organization',
			'name'  => $name,
			'url'   => $url,
		);
		$logo = (int) get_user_meta( $user_id, '_lnkup_organizer_logo_id', true );
		if ( $logo && function_exists( 'lnkup_seo_raster_attachment_url' ) ) {
			$logo_url = lnkup_seo_raster_attachment_url( $logo, 'medium' );
			if ( '' !== $logo_url ) {
				$entity['logo'] = $logo_url;
			}
		}
		$bio = trim( (string) get_user_meta( $user_id, '_lnkup_organizer_bio', true ) );
		if ( '' !== $bio ) {
			$entity['description'] = wp_trim_words( wp_strip_all_tags( $bio ), 40, '…' );
		}
		return array(
			array(
				'@type'      => 'ProfilePage',
				'@id'        => $url . '#profile',
				'url'        => $url,
				'name'       => $name,
				'mainEntity' => array( '@id' => $url . '#organization' ),
			),
			$entity,
			self::breadcrumbs(
				array(
					array( __( 'Home', 'lnkup-marketplace' ), home_url( '/' ) ),
					array( __( 'Organizers', 'lnkup-marketplace' ), home_url( '/organizers/' ) ),
					array( $name, $url ),
				)
			),
		);
	}

	/**
	 * Graph additions for the current request (policy-aware). Returns [] when nothing applies.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function current_graph(): array {
		if ( is_singular( 'lnkup_event' ) ) {
			$event_id = (int) get_queried_object_id();
			$decision = LNKup_Index_Policy::event( $event_id );
			$status   = (string) get_post_meta( $event_id, '_lnkup_event_status', true );
			$show     = $decision['index'] || in_array( $status, array( 'cancelled', 'canceled' ), true );
			if ( ! $show ) {
				return array();
			}
			$event = self::event( $event_id );
			return $event ? array( $event, self::breadcrumbs( self::event_crumbs( $event_id ) ) ) : array();
		}
		$org = LNKup_Index_Policy::current_organizer_id();
		if ( $org > 0 && LNKup_Index_Policy::organizer( $org )['index'] ) {
			return self::organizer_graph( $org );
		}
		if ( class_exists( 'LNKup_Portfolio_Public' ) && '' !== (string) get_query_var( LNKup_Portfolio_Public::QUERY_VAR ) ) {
			$tour_id = LNKup_Portfolio_Public::tour_id_from_slug( (string) get_query_var( LNKup_Portfolio_Public::QUERY_VAR ) );
			if ( $tour_id && LNKup_Index_Policy::tour( $tour_id )['index'] ) {
				return array(
					self::breadcrumbs(
						array(
							array( __( 'Home', 'lnkup-marketplace' ), home_url( '/' ) ),
							array( get_the_title( $tour_id ), LNKup_Portfolio_Public::tour_url( $tour_id ) ),
						)
					),
				);
			}
		}
		if ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_market_request() ) {
			$market = LNKup_Market_Pages::get_market_by_slug( LNKup_Market_Pages::current_slug() );
			if ( $market ) {
				return array(
					self::breadcrumbs(
						array(
							array( __( 'Home', 'lnkup-marketplace' ), home_url( '/' ) ),
							array( (string) $market['display_name'], LNKup_Market_Pages::market_url( (string) $market['slug'] ) ),
						)
					),
				);
			}
		}
		return (array) apply_filters( 'lnkup_seo_schema_current_graph', array() );
	}
}
