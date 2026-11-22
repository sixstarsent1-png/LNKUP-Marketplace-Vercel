<?php
/**
 * Posh.vip URL import provider (HTML / JSON-LD parse).
 *
 * Posh has no public REST API or native RSS — page scrape is the viable path.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Source_Posh implements LNKup_External_Source_Provider_Interface {

	const USER_AGENT = 'LNKup-Marketplace/12.19 WordPress';

	/**
	 * Whether host is a Posh domain.
	 *
	 * @param string $host Hostname.
	 */
	public static function is_allowed_host( string $host ): bool {
		$host = strtolower( preg_replace( '/^www\./', '', $host ) );
		return in_array( $host, array( 'posh.vip', 'posh.com' ), true );
	}

	public function slug(): string {
		return 'posh';
	}

	public function label(): string {
		return __( 'Posh', 'lnkup-marketplace' );
	}

	public function supports_url_import(): bool {
		return true;
	}

	public function supports_discovery(): bool {
		return false;
	}

	/**
	 * @param array<string, mixed> $context Unused.
	 * @return array<int, string>|WP_Error
	 */
	public function discover_event_urls( array $context ) {
		return new WP_Error( 'unsupported', __( 'Posh discovery is not available.', 'lnkup-marketplace' ) );
	}

	/**
	 * @param string $url Posh event URL.
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

		$fields = self::extract_from_json_ld( $html );
		if ( empty( $fields['title'] ) ) {
			$fields = self::merge_field_sets( $fields, self::extract_from_meta( $html ) );
		}
		if ( empty( $fields['image_url'] ) ) {
			$fields['image_url'] = self::extract_meta_image( $html );
		}

		if ( empty( $fields['title'] ) ) {
			return new WP_Error(
				'parse_failed',
				__( 'Could not extract event data from this Posh page. Paste details manually.', 'lnkup-marketplace' )
			);
		}

		$slug = self::extract_event_slug( $normalized );
		$full_description = wp_strip_all_tags( (string) ( $fields['description_excerpt'] ?? '' ) );
		$event_type       = LNKup_External_Events::map_event_type( '', (string) $fields['title'] );

		return array(
			'source_platform'     => 'posh',
			'source_url'          => $normalized,
			'source_event_id'     => $slug,
			'title'               => sanitize_text_field( (string) $fields['title'] ),
			'description'         => $full_description,
			'description_excerpt' => LNKup_External_Events::sanitize_excerpt( $full_description ),
			'image_url'           => esc_url_raw( (string) ( $fields['image_url'] ?? '' ) ),
			'start_datetime'      => $fields['start_datetime'] ?? null,
			'end_datetime'        => $fields['end_datetime'] ?? null,
			'city'                => sanitize_text_field( (string) ( $fields['city'] ?? '' ) ),
			'state'               => sanitize_text_field( (string) ( $fields['state'] ?? '' ) ),
			'venue_name'          => sanitize_text_field( (string) ( $fields['venue_name'] ?? '' ) ),
			'organizer_name'      => sanitize_text_field( (string) ( $fields['organizer_name'] ?? '' ) ),
			'organizer_email'     => '',
			'event_type'          => sanitize_text_field( $event_type ),
			'workflow_status'     => LNKup_External_Events::WORKFLOW_PENDING_REVIEW,
			'_preview_confidence' => array(
				'title' => ! empty( $fields['title'] ) ? 'high' : 'low',
				'image' => ! empty( $fields['image_url'] ) ? 'high' : 'low',
				'date'  => ! empty( $fields['start_datetime'] ) ? 'high' : 'low',
			),
		);
	}

	/**
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
			return new WP_Error( 'invalid_url', __( 'Invalid Posh URL.', 'lnkup-marketplace' ) );
		}

		$host = strtolower( (string) $parts['host'] );
		if ( ! self::is_allowed_host( $host ) ) {
			return new WP_Error( 'invalid_host', __( 'Only posh.vip event URLs are supported.', 'lnkup-marketplace' ) );
		}

		$path = (string) ( $parts['path'] ?? '' );
		if ( ! preg_match( '#^/e/[A-Za-z0-9\-_%]+#', $path ) ) {
			return new WP_Error(
				'invalid_path',
				__( 'Paste a direct Posh event page URL (path must include /e/).', 'lnkup-marketplace' )
			);
		}

		$host_clean = preg_replace( '/^www\./', '', $host );
		$clean      = 'https://' . $host_clean . $path;
		return untrailingslashit( $clean );
	}

	/**
	 * @param string $url Normalized URL.
	 */
	public static function extract_event_slug( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#/e/([^/]+)#', $path, $m ) ) {
			return sanitize_title( rawurldecode( $m[1] ) );
		}
		return '';
	}

	/**
	 * @param string $url Normalized event URL.
	 * @return string|WP_Error
	 */
	public static function fetch_event_html( string $url ) {
		$response = wp_safe_remote_get(
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
			return new WP_Error(
				'fetch_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not reach Posh: %s', 'lnkup-marketplace' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return new WP_Error( 'event_not_found', __( 'Posh event not found at this URL.', 'lnkup-marketplace' ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'fetch_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Posh returned HTTP %d.', 'lnkup-marketplace' ),
					$code
				)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			return new WP_Error( 'fetch_failed', __( 'Posh returned an empty page.', 'lnkup-marketplace' ) );
		}

		return $body;
	}

	/**
	 * @param string $html Page HTML.
	 * @return array<string, mixed>
	 */
	public static function extract_from_json_ld( string $html ): array {
		if ( ! preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
			return array();
		}

		foreach ( $matches[1] as $raw ) {
			$data = json_decode( html_entity_decode( trim( $raw ), ENT_QUOTES | ENT_HTML5 ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$nodes = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : array( $data );
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$type = $node['@type'] ?? '';
				if ( is_array( $type ) ) {
					$type = implode( ' ', $type );
				}
				if ( ! is_string( $type ) || false === stripos( $type, 'Event' ) ) {
					continue;
				}
				return self::map_json_ld_event( $node );
			}
		}

		return array();
	}

	/**
	 * @param array<string, mixed> $event JSON-LD Event node.
	 * @return array<string, mixed>
	 */
	protected static function map_json_ld_event( array $event ): array {
		$location = is_array( $event['location'] ?? null ) ? $event['location'] : array();
		$address  = is_array( $location['address'] ?? null ) ? $location['address'] : array();
		$image    = '';
		if ( ! empty( $event['image'] ) ) {
			if ( is_string( $event['image'] ) ) {
				$image = $event['image'];
			} elseif ( is_array( $event['image'] ) ) {
				$first = $event['image'][0] ?? $event['image']['url'] ?? '';
				$image = is_string( $first ) ? $first : ( is_array( $first ) ? (string) ( $first['url'] ?? '' ) : '' );
			}
		}

		return array(
			'title'               => (string) ( $event['name'] ?? '' ),
			'description_excerpt' => (string) ( $event['description'] ?? '' ),
			'start_datetime'      => self::parse_iso_datetime( (string) ( $event['startDate'] ?? '' ) ),
			'end_datetime'        => self::parse_iso_datetime( (string) ( $event['endDate'] ?? '' ) ),
			'image_url'           => esc_url_raw( $image ),
			'venue_name'          => (string) ( $location['name'] ?? '' ),
			'city'                => (string) ( $address['addressLocality'] ?? '' ),
			'state'               => (string) ( $address['addressRegion'] ?? '' ),
			'organizer_name'      => '',
		);
	}

	/**
	 * Meta / Open Graph fallbacks.
	 *
	 * @param string $html Page HTML.
	 * @return array<string, mixed>
	 */
	public static function extract_from_meta( string $html ): array {
		$fields = array(
			'title'               => '',
			'description_excerpt' => '',
			'start_datetime'      => null,
			'image_url'           => '',
			'venue_name'          => '',
			'city'                => '',
			'state'               => '',
		);

		if ( preg_match( '#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)#i', $html, $m )
			|| preg_match( '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title#i', $html, $m ) ) {
			$fields['title'] = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
		} elseif ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			$fields['title'] = wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 ) );
		}

		if ( preg_match( '#<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)#i', $html, $m )
			|| preg_match( '#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)#i', $html, $m ) ) {
			$fields['description_excerpt'] = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
		}

		$fields['image_url'] = self::extract_meta_image( $html );

		return $fields;
	}

	/**
	 * @param string $html Page HTML.
	 */
	public static function extract_meta_image( string $html ): string {
		$raw = '';
		if ( preg_match( '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)#i', $html, $m ) ) {
			$raw = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
		} elseif ( preg_match( '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image#i', $html, $m ) ) {
			$raw = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
		}
		return esc_url_raw( $raw );
	}

	/**
	 * @param array<string, mixed> $primary  Primary.
	 * @param array<string, mixed> $fallback Fallback.
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
	 * @param string $value ISO datetime.
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
