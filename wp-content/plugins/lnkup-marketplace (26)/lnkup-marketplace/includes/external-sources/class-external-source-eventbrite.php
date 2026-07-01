<?php
/**
 * Eventbrite URL import provider (HTML JSON-LD parse, admin-triggered).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Source_Eventbrite implements LNKup_External_Source_Provider_Interface {

	const USER_AGENT = 'LNKup-Marketplace/12.14 WordPress';

	/**
	 * Whether host is an Eventbrite domain.
	 *
	 * @param string $host Hostname.
	 */
	public static function is_allowed_host( string $host ): bool {
		$host = strtolower( preg_replace( '/^www\./', '', $host ) );
		return '' !== $host && str_contains( $host, 'eventbrite' );
	}

	/**
	 * Allowed hosts for URL import (legacy helper).
	 *
	 * @return array<int, string>
	 */
	public static function allowed_hosts(): array {
		return array(
			'eventbrite.com',
			'www.eventbrite.com',
			'eventbrite.co.uk',
			'www.eventbrite.co.uk',
			'eventbrite.ca',
			'eventbrite.com.au',
		);
	}

	public function slug(): string {
		return 'eventbrite';
	}

	public function label(): string {
		return __( 'Eventbrite', 'lnkup-marketplace' );
	}

	public function supports_url_import(): bool {
		return true;
	}

	public function supports_discovery(): bool {
		return true;
	}

	/**
	 * Launch market slug → Eventbrite browse path segments.
	 *
	 * @return array<string, array{state_slug: string, city_slug: string}>
	 */
	public static function market_browse_url_map(): array {
		return array(
			'charlotte'     => array( 'state_slug' => 'north-carolina', 'city_slug' => 'charlotte' ),
			'atlanta'       => array( 'state_slug' => 'georgia', 'city_slug' => 'atlanta' ),
			'dallas'        => array( 'state_slug' => 'texas', 'city_slug' => 'dallas' ),
			'houston'       => array( 'state_slug' => 'texas', 'city_slug' => 'houston' ),
			'raleigh'       => array( 'state_slug' => 'north-carolina', 'city_slug' => 'raleigh' ),
			'greensboro'    => array( 'state_slug' => 'north-carolina', 'city_slug' => 'greensboro' ),
			'miami'         => array( 'state_slug' => 'florida', 'city_slug' => 'miami' ),
			'washington-dc' => array( 'state_slug' => 'district-of-columbia', 'city_slug' => 'washington' ),
			'new-york'      => array( 'state_slug' => 'new-york', 'city_slug' => 'new-york' ),
			'los-angeles'   => array( 'state_slug' => 'california', 'city_slug' => 'los-angeles' ),
		);
	}

	/**
	 * Build Eventbrite city browse URL.
	 *
	 * @param array{state_slug: string, city_slug: string} $segments Path segments.
	 */
	public static function build_browse_url( array $segments ): string {
		$state = sanitize_title( (string) ( $segments['state_slug'] ?? '' ) );
		$city  = sanitize_title( (string) ( $segments['city_slug'] ?? '' ) );
		return 'https://www.eventbrite.com/d/united-states--' . $state . '--' . $city . '/events/';
	}

	/**
	 * Discover event detail URLs from a launch market browse page.
	 *
	 * @param array<string, mixed> $context Discovery context.
	 * @return array<int, string>|WP_Error
	 */
	public function discover_event_urls( array $context ) {
		$market_slug = sanitize_key( (string) ( $context['market_slug'] ?? '' ) );
		$map         = self::market_browse_url_map();
		if ( ! isset( $map[ $market_slug ] ) ) {
			return new WP_Error(
				'no_mapping',
				sprintf(
					/* translators: %s: market slug */
					__( 'No Eventbrite browse mapping for market "%s".', 'lnkup-marketplace' ),
					$market_slug
				)
			);
		}

		$browse_url = self::build_browse_url( $map[ $market_slug ] );
		$html       = self::fetch_event_html( $browse_url );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$urls = self::extract_event_urls_from_browse_html( $html );
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Extract normalized Eventbrite event URLs from a browse/listing page.
	 *
	 * @param string $html Browse page HTML.
	 * @return array<int, string>
	 */
	public static function extract_event_urls_from_browse_html( string $html ): array {
		$urls = array();

		if ( preg_match( '#<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			$data = json_decode( html_entity_decode( trim( $m[1] ), ENT_QUOTES | ENT_HTML5 ), true );
			if ( is_array( $data ) ) {
				self::collect_event_urls_from_node( $data, $urls );
			}
		}

		if ( preg_match_all( '#https?://(?:www\.)?eventbrite\.[^"\']+/e/[^"\']+-tickets-\d+#i', $html, $matches ) ) {
			foreach ( $matches[0] as $raw ) {
				$normalized = self::normalize_discovered_url( $raw );
				if ( $normalized ) {
					$urls[] = $normalized;
				}
			}
		}

		if ( preg_match_all( '#href=["\'](/e/[^"\']+-tickets-\d+)["\']#i', $html, $rel_matches ) ) {
			foreach ( $rel_matches[1] as $path ) {
				$normalized = self::normalize_discovered_url( 'https://www.eventbrite.com' . $path );
				if ( $normalized ) {
					$urls[] = $normalized;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Recursively collect event URLs from JSON nodes.
	 *
	 * @param mixed                $node Current node.
	 * @param array<int, string>   $urls URL accumulator.
	 */
	protected static function collect_event_urls_from_node( $node, array &$urls ): void {
		if ( is_string( $node ) ) {
			if ( preg_match( '#https?://(?:www\.)?eventbrite\.[^/]+/e/#i', $node ) || preg_match( '#^/e/.+-tickets-\d+#', $node ) ) {
				$normalized = self::normalize_discovered_url( 0 === strpos( $node, '/e/' ) ? 'https://www.eventbrite.com' . $node : $node );
				if ( $normalized ) {
					$urls[] = $normalized;
				}
			}
			return;
		}

		if ( ! is_array( $node ) ) {
			return;
		}

		foreach ( array( 'url', 'event_url', 'public_url', 'canonical_url' ) as $key ) {
			if ( ! empty( $node[ $key ] ) && is_string( $node[ $key ] ) ) {
				$normalized = self::normalize_discovered_url( $node[ $key ] );
				if ( $normalized ) {
					$urls[] = $normalized;
				}
			}
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) || is_string( $value ) ) {
				self::collect_event_urls_from_node( $value, $urls );
			}
		}
	}

	/**
	 * Normalize a discovered event URL for import/dedup.
	 *
	 * @param string $url Raw URL.
	 */
	public static function normalize_discovered_url( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5 );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '/e/' ) ) {
			$url = 'https://www.eventbrite.com' . $url;
		}
		$normalized = self::normalize_url( $url );
		if ( is_wp_error( $normalized ) ) {
			return '';
		}
		return $normalized;
	}

	/**
	 * Whether a URL looks like an event detail page (not a real image).
	 *
	 * @param string $url URL to inspect.
	 */
	public static function is_event_page_url( string $url ): bool {
		$url = strtolower( trim( $url ) );
		if ( '' === $url ) {
			return false;
		}
		return (bool) preg_match( '#eventbrite\.[^/]+/e/#', $url );
	}

	/**
	 * Normalize Eventbrite / Next.js image URLs to absolute https CDN links.
	 *
	 * @param string $url      Raw image URL.
	 * @param string $base_url Base site for root-relative paths.
	 */
	public static function normalize_image_url( string $url, string $base_url = 'https://www.eventbrite.com' ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5 ) );
		if ( '' === $url ) {
			return '';
		}

		if ( str_contains( $url, '_next/image' ) ) {
			$query = wp_parse_url( $url, PHP_URL_QUERY );
			if ( is_string( $query ) && '' !== $query ) {
				parse_str( $query, $params );
				if ( ! empty( $params['url'] ) && is_string( $params['url'] ) ) {
					$url = rawurldecode( $params['url'] );
				}
			}
		}

		if ( str_starts_with( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			if ( str_contains( $url, '_next/image' ) || preg_match( '#^/e/#', $url ) ) {
				return '';
			}
			$url = rtrim( $base_url, '/' ) . $url;
		}

		if ( str_starts_with( strtolower( $url ), 'http://' ) ) {
			$url = 'https://' . substr( $url, 7 );
		}

		return trim( $url );
	}

	/**
	 * Sanitize image URL — reject event page links masquerading as images.
	 *
	 * @param string $url Raw image URL.
	 */
	public static function sanitize_image_url( string $url ): string {
		$url = self::normalize_image_url( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( self::is_event_page_url( $url ) ) {
			return '';
		}
		if ( str_contains( strtolower( $url ), '_next/image' ) ) {
			return '';
		}
		if ( ! str_starts_with( strtolower( $url ), 'https://' ) ) {
			return '';
		}
		$clean = esc_url_raw( $url );
		if ( '' === $clean || ! str_starts_with( strtolower( $clean ), 'https://' ) ) {
			return '';
		}
		if ( str_contains( strtolower( $clean ), 'cdn.evbuc.com' ) ) {
			return $clean;
		}
		if ( preg_match( '#\.(jpg|jpeg|png|gif|webp|avif)(\?|$)#i', $clean ) ) {
			return $clean;
		}
		if ( preg_match( '#/(images|media|uploads|assets)/#i', $clean ) ) {
			return $clean;
		}
		return '';
	}

	/**
	 * @param string $url Eventbrite event URL.
	 * @return array<string, mixed>|WP_Error
	 */
	public function parse_url( string $url ) {
		$normalized = self::normalize_url( $url );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$html = self::fetch_event_html( $normalized );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$json_ld = self::extract_from_json_ld( $html );
		$next    = self::extract_from_next_data( $html );
		$fields  = self::merge_field_sets( $json_ld, $next );

		if ( empty( $fields['image_url'] ) ) {
			$fields['image_url'] = self::extract_meta_image( $html );
		}
		$fields['image_url'] = self::sanitize_image_url( (string) ( $fields['image_url'] ?? '' ) );

		if ( empty( $fields['title'] ) ) {
			return new WP_Error(
				'parse_failed',
				__( 'Could not extract event data from this Eventbrite page.', 'lnkup-marketplace' )
			);
		}

		$event_id = self::extract_event_id( $normalized );
		if ( ! $event_id && ! empty( $fields['source_event_id'] ) ) {
			$event_id = (string) $fields['source_event_id'];
		}

		$category_hint = (string) ( $fields['_category_hint'] ?? '' );
		$event_type    = LNKup_External_Events::map_event_type( $category_hint, (string) $fields['title'] );
		$organizer_email = '';
		if ( ! empty( $fields['organizer_email'] ) && is_email( (string) $fields['organizer_email'] ) ) {
			$organizer_email = sanitize_email( (string) $fields['organizer_email'] );
		}
		if ( ! $organizer_email && ! empty( $fields['description_excerpt'] ) ) {
			$organizer_email = LNKup_External_Events::extract_email_from_text( (string) $fields['description_excerpt'] );
		}

		$full_description = wp_strip_all_tags( (string) ( $fields['description_excerpt'] ?? '' ) );
		if ( ! $organizer_email && '' !== $full_description ) {
			$organizer_email = LNKup_External_Events::extract_email_from_text( $full_description );
		}

		return array(
			'source_platform'     => 'eventbrite',
			'source_url'          => $normalized,
			'source_event_id'     => $event_id,
			'title'               => sanitize_text_field( (string) $fields['title'] ),
			'description'         => $full_description,
			'description_excerpt'   => LNKup_External_Events::sanitize_excerpt( $full_description ),
			'image_url'           => self::sanitize_image_url( (string) ( $fields['image_url'] ?? '' ) ),
			'start_datetime'      => $fields['start_datetime'] ?? null,
			'end_datetime'        => $fields['end_datetime'] ?? null,
			'city'                => sanitize_text_field( (string) ( $fields['city'] ?? '' ) ),
			'state'               => sanitize_text_field( (string) ( $fields['state'] ?? '' ) ),
			'venue_name'          => sanitize_text_field( (string) ( $fields['venue_name'] ?? '' ) ),
			'organizer_name'      => sanitize_text_field( (string) ( $fields['organizer_name'] ?? '' ) ),
			'organizer_email'     => $organizer_email,
			'event_type'          => sanitize_text_field( $event_type ),
			'workflow_status'     => LNKup_External_Events::WORKFLOW_PENDING_REVIEW,
			'_category_hint'      => $category_hint,
			'_preview_confidence' => array(
				'title'           => ! empty( $fields['title'] ) ? 'high' : 'low',
				'image'           => ! empty( $fields['image_url'] ) ? 'high' : 'low',
				'date'            => ! empty( $fields['start_datetime'] ) ? 'high' : 'low',
				'event_type'      => $event_type ? 'high' : 'low',
				'organizer_email' => $organizer_email ? 'high' : 'low',
			),
		);
	}

	/**
	 * Merge parser field sets; later values fill empty slots only.
	 *
	 * @param array<string, mixed> $primary  Primary fields.
	 * @param array<string, mixed> $fallback Fallback fields.
	 * @return array<string, mixed>
	 */
	protected static function merge_field_sets( array $primary, array $fallback ): array {
		$merged = $primary;
		foreach ( $fallback as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			if ( empty( $merged[ $key ] ) ) {
				$merged[ $key ] = $value;
			}
		}
		return $merged;
	}

	/**
	 * Extract og:image or twitter:image from HTML.
	 *
	 * @param string $html Page HTML.
	 */
	public static function extract_meta_image( string $html ): string {
		$raw = '';
		if ( preg_match( '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)#i', $html, $m ) ) {
			$raw = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
		} elseif ( preg_match( '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image#i', $html, $m ) ) {
			$raw = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
		} elseif ( preg_match( '#<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)#i', $html, $m ) ) {
			$raw = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
		}
		return self::sanitize_image_url( $raw );
	}

	/**
	 * Resolve image URL from JSON-LD / NEXT_DATA image shapes.
	 *
	 * @param mixed $raw Image field value.
	 */
	protected static function resolve_image_value( $raw ): string {
		if ( is_string( $raw ) && '' !== $raw ) {
			return self::sanitize_image_url( $raw );
		}
		if ( ! is_array( $raw ) ) {
			return '';
		}
		if ( ! empty( $raw['contentUrl'] ) && is_string( $raw['contentUrl'] ) ) {
			return self::sanitize_image_url( $raw['contentUrl'] );
		}
		if ( ! empty( $raw['url'] ) && is_string( $raw['url'] ) ) {
			return self::sanitize_image_url( $raw['url'] );
		}
		if ( ! empty( $raw['original']['url'] ) && is_string( $raw['original']['url'] ) ) {
			return self::sanitize_image_url( $raw['original']['url'] );
		}
		if ( isset( $raw[0] ) ) {
			return self::resolve_image_value( $raw[0] );
		}
		return '';
	}

	/**
	 * Build category hint string from Eventbrite event payload.
	 *
	 * @param array<string, mixed> $event Event node.
	 */
	protected static function extract_category_hint( array $event ): string {
		$parts = array();
		foreach ( array( 'category', 'subcategory', 'format' ) as $key ) {
			if ( empty( $event[ $key ] ) ) {
				continue;
			}
			$node = $event[ $key ];
			if ( is_string( $node ) ) {
				$parts[] = $node;
				continue;
			}
			if ( is_array( $node ) ) {
				$label = (string) ( $node['name'] ?? $node['short_name'] ?? $node['name_localized'] ?? '' );
				if ( is_array( $label ) ) {
					$label = (string) ( $label['text'] ?? '' );
				}
				if ( $label ) {
					$parts[] = $label;
				}
			}
		}
		return trim( implode( ' ', $parts ) );
	}

	/**
	 * Best-effort organizer email from Eventbrite payload.
	 *
	 * @param array<string, mixed> $organizer Organizer node.
	 */
	protected static function extract_organizer_email( array $organizer ): string {
		foreach ( array( 'email', 'contact_email', 'organizer_email' ) as $key ) {
			if ( ! empty( $organizer[ $key ] ) && is_email( (string) $organizer[ $key ] ) ) {
				return sanitize_email( (string) $organizer[ $key ] );
			}
		}
		return '';
	}

	/**
	 * Resolve organizer email from organizer node or event description text.
	 *
	 * @param array<string, mixed> $organizer   Organizer node.
	 * @param string               $description Event description text.
	 */
	protected static function resolve_organizer_email( array $organizer, string $description = '' ): string {
		$email = self::extract_organizer_email( $organizer );
		if ( $email ) {
			return $email;
		}
		return LNKup_External_Events::extract_email_from_text( $description );
	}

	/**
	 * Normalize and validate an Eventbrite event URL.
	 *
	 * @param string $url Raw URL.
	 * @return string|WP_Error
	 */
	public static function normalize_url( string $url ) {
		$url = esc_url_raw( trim( $url ) );
		if ( '' === $url ) {
			return new WP_Error( 'missing_url', __( 'URL is required.', 'lnkup-marketplace' ) );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'invalid_url', __( 'Invalid Eventbrite URL.', 'lnkup-marketplace' ) );
		}

		$host = strtolower( (string) $parts['host'] );
		if ( ! self::is_allowed_host( $host ) ) {
			return new WP_Error( 'invalid_host', __( 'Only Eventbrite URLs are supported in V2.', 'lnkup-marketplace' ) );
		}

		$path = (string) ( $parts['path'] ?? '' );
		if ( ! preg_match( '#^/e/#', $path ) && ! preg_match( '#^/events/#', $path ) ) {
			return new WP_Error(
				'invalid_path',
				__( 'Paste a direct Eventbrite event page URL (path must include /e/).', 'lnkup-marketplace' )
			);
		}

		$scheme = 'https';
		$clean  = $scheme . '://' . $host . $path;
		return untrailingslashit( $clean );
	}

	/**
	 * Fetch public event page HTML.
	 *
	 * @param string $url Normalized event URL.
	 * @return string|WP_Error
	 */
	public static function fetch_event_html( string $url ) {
		$last_error = null;
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 12,
					'redirection' => 5,
					'user-agent'  => self::USER_AGENT,
					'headers'     => array(
						'Accept' => 'text/html,application/xhtml+xml',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = new WP_Error(
					'fetch_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Could not reach Eventbrite: %s', 'lnkup-marketplace' ),
						$response->get_error_message()
					)
				);
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 404 === $code ) {
				return new WP_Error( 'event_not_found', __( 'Eventbrite event not found at this URL.', 'lnkup-marketplace' ) );
			}
			if ( $code < 200 || $code >= 300 ) {
				$last_error = new WP_Error(
					'fetch_failed',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Eventbrite returned HTTP %d.', 'lnkup-marketplace' ),
						$code
					)
				);
				continue;
			}

			$body = wp_remote_retrieve_body( $response );
			if ( '' === trim( $body ) ) {
				$last_error = new WP_Error( 'empty_body', __( 'Eventbrite returned an empty page.', 'lnkup-marketplace' ) );
				continue;
			}

			return $body;
		}

		return $last_error instanceof WP_Error ? $last_error : new WP_Error( 'fetch_failed', __( 'Could not reach Eventbrite.', 'lnkup-marketplace' ) );
	}

	/**
	 * Extract event ID from URL path.
	 *
	 * @param string $url Event URL.
	 */
	public static function extract_event_id( string $url ): string {
		if ( preg_match( '#/(?:e|events)/(?:[^/?]*-)?(\d{8,})(?:/|\?|$)#', $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Parse JSON-LD Event blocks from HTML.
	 *
	 * @param string $html Page HTML.
	 * @return array<string, mixed>
	 */
	public static function extract_from_json_ld( string $html ): array {
		$out = array();
		if ( ! preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
			return $out;
		}

		foreach ( $matches[1] as $raw ) {
			$decoded = json_decode( html_entity_decode( trim( $raw ), ENT_QUOTES | ENT_HTML5 ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$events = self::collect_json_ld_events( $decoded );
			foreach ( $events as $event ) {
				$mapped = self::map_json_ld_event( $event );
				if ( ! empty( $mapped['title'] ) ) {
					return $mapped;
				}
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $node JSON-LD node.
	 * @return array<int, array<string, mixed>>
	 */
	protected static function collect_json_ld_events( array $node ): array {
		$events = array();
		if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
			foreach ( $node['@graph'] as $item ) {
				if ( is_array( $item ) ) {
					$events = array_merge( $events, self::collect_json_ld_events( $item ) );
				}
			}
			return $events;
		}

		$type = $node['@type'] ?? '';
		if ( is_array( $type ) ) {
			$type = implode( ' ', $type );
		}
		if ( is_string( $type ) && false !== stripos( $type, 'Event' ) ) {
			$events[] = $node;
		}

		return $events;
	}

	/**
	 * @param array<string, mixed> $event JSON-LD Event node.
	 * @return array<string, mixed>
	 */
	protected static function map_json_ld_event( array $event ): array {
		$location  = is_array( $event['location'] ?? null ) ? $event['location'] : array();
		$address   = is_array( $location['address'] ?? null ) ? $location['address'] : array();
		$organizer = is_array( $event['organizer'] ?? null ) ? $event['organizer'] : array();
		$image     = self::resolve_image_value( $event['image'] ?? null );
		$desc      = (string) ( $event['description'] ?? '' );

		return array(
			'title'               => (string) ( $event['name'] ?? '' ),
			'description_excerpt'   => $desc,
			'start_datetime'      => self::parse_iso_datetime( (string) ( $event['startDate'] ?? '' ) ),
			'end_datetime'        => self::parse_iso_datetime( (string) ( $event['endDate'] ?? '' ) ),
			'image_url'           => $image,
			'venue_name'          => (string) ( $location['name'] ?? '' ),
			'city'                => (string) ( $address['addressLocality'] ?? '' ),
			'state'               => (string) ( $address['addressRegion'] ?? '' ),
			'organizer_name'      => (string) ( $organizer['name'] ?? '' ),
			'organizer_email'     => self::resolve_organizer_email( $organizer, $desc ),
			'_category_hint'      => (string) ( $event['eventAttendanceMode'] ?? '' ),
		);
	}

	/**
	 * Fallback: parse __NEXT_DATA__ payload from event page.
	 *
	 * @param string $html Page HTML.
	 * @return array<string, mixed>
	 */
	public static function extract_from_next_data( string $html ): array {
		if ( ! preg_match( '#<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			return array();
		}

		$data = json_decode( html_entity_decode( trim( $m[1] ), ENT_QUOTES | ENT_HTML5 ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$event = self::find_event_payload( $data );
		if ( ! is_array( $event ) || empty( $event ) ) {
			return array();
		}

		$name = '';
		if ( isset( $event['name'] ) ) {
			$name = is_array( $event['name'] ) ? (string) ( $event['name']['text'] ?? $event['name']['html'] ?? '' ) : (string) $event['name'];
		}

		$desc = '';
		if ( isset( $event['summary'] ) ) {
			$desc = is_array( $event['summary'] ) ? (string) ( $event['summary']['text'] ?? '' ) : (string) $event['summary'];
		} elseif ( isset( $event['description'] ) ) {
			$desc = is_array( $event['description'] ) ? (string) ( $event['description']['text'] ?? '' ) : (string) $event['description'];
		}

		$venue = is_array( $event['venue'] ?? null ) ? $event['venue'] : array();
		$addr  = is_array( $venue['address'] ?? null ) ? $venue['address'] : array();
		$org   = is_array( $event['organizer'] ?? null ) ? $event['organizer'] : array();

		$image = self::resolve_image_value( $event['image'] ?? null );
		if ( '' === $image ) {
			$image = self::resolve_image_value( $event['logo'] ?? null );
		}

		$org_name = '';
		if ( isset( $org['name'] ) ) {
			$org_name = is_array( $org['name'] ) ? (string) ( $org['name']['text'] ?? '' ) : (string) $org['name'];
		}

		return array(
			'source_event_id'     => (string) ( $event['id'] ?? $event['event_id'] ?? '' ),
			'title'               => wp_strip_all_tags( $name ),
			'description_excerpt' => wp_strip_all_tags( $desc ),
			'start_datetime'      => self::parse_iso_datetime( (string) ( $event['start_date'] ?? $event['startDate'] ?? '' ) ),
			'end_datetime'        => self::parse_iso_datetime( (string) ( $event['end_date'] ?? $event['endDate'] ?? '' ) ),
			'image_url'           => $image,
			'venue_name'          => (string) ( $venue['name'] ?? '' ),
			'city'                => (string) ( $addr['city'] ?? $addr['addressLocality'] ?? '' ),
			'state'               => (string) ( $addr['region'] ?? $addr['addressRegion'] ?? '' ),
			'organizer_name'      => wp_strip_all_tags( $org_name ),
			'organizer_email'     => self::resolve_organizer_email( $org, wp_strip_all_tags( $desc ) ),
			'_category_hint'      => self::extract_category_hint( $event ),
		);
	}

	/**
	 * Recursively locate an event-like array in NEXT_DATA.
	 *
	 * @param mixed $node Current node.
	 * @return array<string, mixed>|null
	 */
	protected static function find_event_payload( $node ): ?array {
		if ( ! is_array( $node ) ) {
			return null;
		}

		if ( isset( $node['id'], $node['name'] ) && ( isset( $node['start_date'] ) || isset( $node['startDate'] ) || isset( $node['venue'] ) ) ) {
			return $node;
		}

		if ( isset( $node['event'] ) && is_array( $node['event'] ) ) {
			$found = self::find_event_payload( $node['event'] );
			if ( $found ) {
				return $found;
			}
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$found = self::find_event_payload( $value );
				if ( $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * @param string $value ISO datetime string.
	 */
	protected static function parse_iso_datetime( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		if ( ! $ts ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
