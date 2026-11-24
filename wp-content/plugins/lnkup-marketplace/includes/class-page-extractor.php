<?php
/**
 * Generic HTML / JSON-LD extractor for discovered event pages.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Page_Extractor {

	const USER_AGENT   = 'LNKup-Marketplace/12.19 WordPress';
	const MAX_HTML_LEN = 1500000;

	/**
	 * Tracking query keys to strip before dedup.
	 *
	 * @return array<int, string>
	 */
	public static function tracking_query_keys(): array {
		return array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'fbclid', 'gclid', 'mc_eid', 'mc_cid', '_ga' );
	}

	/**
	 * Hosts that are never fetched as event pages.
	 *
	 * @return array<int, string>
	 */
	public static function skip_hosts(): array {
		return array(
			'reddit.com',
			'pinterest.com',
			'pinterest.co.uk',
			'yelp.com',
			'wikipedia.org',
			'yellowpages.com',
			'google.com',
			'googleusercontent.com',
			'webcache.googleusercontent.com',
			'bing.com',
			'linkedin.com',
			'tiktok.com',
			'twitter.com',
			'x.com',
		);
	}

	/**
	 * News hosts skipped unless the snippet mentions vendor applications.
	 *
	 * @return array<int, string>
	 */
	public static function news_hosts(): array {
		return array(
			'nytimes.com',
			'washingtonpost.com',
			'cnn.com',
			'foxnews.com',
			'usatoday.com',
			'apnews.com',
			'reuters.com',
			'bloomberg.com',
			'forbes.com',
			'businessinsider.com',
			'huffpost.com',
			'yahoo.com',
		);
	}

	/**
	 * Normalize a URL for dedup (strip tracking, lowercase host, drop trailing slash).
	 *
	 * @param string $url Raw URL.
	 */
	public static function normalize_url( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5 );
		if ( '' === $url ) {
			return '';
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( class_exists( 'LNKup_External_Source_Eventbrite' ) && LNKup_External_Source_Eventbrite::is_allowed_host( $host ) ) {
			$normalized = LNKup_External_Source_Eventbrite::normalize_discovered_url( $url );
			return is_string( $normalized ) ? $normalized : '';
		}
		if ( class_exists( 'LNKup_External_Source_Posh' ) && method_exists( 'LNKup_External_Source_Posh', 'normalize_url' ) && LNKup_External_Source_Posh::is_allowed_host( $host ) ) {
			$normalized = LNKup_External_Source_Posh::normalize_url( $url );
			return is_wp_error( $normalized ) ? '' : (string) $normalized;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$path = rtrim( $path, '/' );
		if ( '' === $path ) {
			$path = '';
		}

		$query = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $params );
			foreach ( self::tracking_query_keys() as $key ) {
				unset( $params[ $key ] );
			}
			if ( $params ) {
				$query = '?' . http_build_query( $params );
			}
		}

		return 'https://' . $host . $path . $query;
	}

	/**
	 * Whether a candidate URL should be skipped.
	 *
	 * @param string $url     URL.
	 * @param string $snippet Optional SERP snippet.
	 */
	public static function should_skip_url( string $url, string $snippet = '' ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		if ( '' === $host ) {
			return true;
		}

		if ( str_contains( $host, 'facebook.com' ) && str_contains( $path, '/groups' ) ) {
			return true;
		}

		foreach ( self::skip_hosts() as $blocked ) {
			if ( $host === $blocked || str_ends_with( $host, '.' . $blocked ) ) {
				return true;
			}
		}

		foreach ( self::news_hosts() as $news ) {
			if ( $host === $news || str_ends_with( $host, '.' . $news ) ) {
				return ! self::snippet_has_apply_language( $snippet );
			}
		}

		return false;
	}

	/**
	 * Fetch HTML for a candidate event page.
	 *
	 * @param string $url Page URL.
	 * @return string|WP_Error
	 */
	public static function fetch_html( string $url ) {
		$url = self::normalize_url( $url );
		if ( '' === $url ) {
			return new WP_Error( 'invalid_url', __( 'Invalid page URL.', 'lnkup-marketplace' ) );
		}
		if ( self::should_skip_url( $url ) ) {
			return new WP_Error( 'skipped_host', __( 'This host is not imported from Google Search.', 'lnkup-marketplace' ) );
		}

		if ( class_exists( 'LNKup_Safe_Fetch' ) ) {
			$fetched = LNKup_Safe_Fetch::get( $url, array( 'html', 'text/plain' ) );
			if ( is_wp_error( $fetched ) ) {
				return $fetched;
			}
			return substr( $fetched['body'], 0, self::MAX_HTML_LEN );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'user-agent'  => self::USER_AGENT,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'fetch_failed',
				sprintf(
					/* translators: %d: HTTP status */
					__( 'Could not fetch page (%d).', 'lnkup-marketplace' ),
					$code
				)
			);
		}

		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( '' !== $content_type && ! str_contains( $content_type, 'html' ) && ! str_contains( $content_type, 'text/plain' ) ) {
			return new WP_Error( 'not_html', __( 'Remote response was not HTML.', 'lnkup-marketplace' ) );
		}

		$html = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $html ) > self::MAX_HTML_LEN ) {
			$html = substr( $html, 0, self::MAX_HTML_LEN );
		}

		return $html;
	}

	/**
	 * Extract event fields from HTML.
	 *
	 * @param string               $html       Page HTML.
	 * @param string               $source_url Canonical listing URL.
	 * @param array<string, mixed> $context    City/state fallback.
	 * @return array<string, mixed>
	 */
	public static function extract( string $html, string $source_url, array $context = array() ): array {
		$json_ld = self::extract_from_json_ld( $html );
		$meta    = self::extract_from_meta( $html );
		$fields  = self::merge_field_sets( $json_ld, $meta );

		if ( empty( $fields['title'] ) ) {
			$fields['title'] = self::extract_title( $html );
		}

		if ( empty( $fields['start_datetime'] ) ) {
			$fields['start_datetime'] = self::extract_date_from_text( $html );
		}

		if ( empty( $fields['city'] ) && ! empty( $context['city'] ) ) {
			$fields['city'] = sanitize_text_field( (string) $context['city'] );
		}
		if ( empty( $fields['state'] ) && ! empty( $context['state'] ) ) {
			$fields['state'] = sanitize_text_field( (string) $context['state'] );
		}

		$fields['source_url']          = self::normalize_url( $source_url ) ?: esc_url_raw( $source_url );
		$fields['application_url']     = self::extract_application_url( $html, (string) $fields['source_url'] );
		$fields['organizer_instagram'] = self::extract_instagram( $html );

		$haystack = wp_strip_all_tags( $html );
		if ( empty( $fields['organizer_email'] ) && class_exists( 'LNKup_External_Events' ) ) {
			$fields['organizer_email'] = LNKup_External_Events::extract_email_from_text( $haystack );
		}

		$title = (string) ( $fields['title'] ?? '' );
		$hint  = (string) ( $fields['_category_hint'] ?? '' );
		if ( class_exists( 'LNKup_External_Events' ) ) {
			$fields['event_type'] = LNKup_External_Events::map_event_type( $hint, $title );
		}

		$description = (string) ( $fields['description'] ?? $fields['description_excerpt'] ?? '' );
		if ( class_exists( 'LNKup_External_Events' ) ) {
			$fields['description_excerpt'] = LNKup_External_Events::sanitize_excerpt( $description );
		} else {
			$fields['description_excerpt'] = substr( wp_strip_all_tags( $description ), 0, 280 );
		}

		return $fields;
	}

	/**
	 * First apply / vendor / booth / registration href.
	 *
	 * @param string $html       HTML.
	 * @param string $source_url Listing URL (excluded).
	 */
	public static function extract_application_url( string $html, string $source_url = '' ): string {
		if ( ! preg_match_all( '#<a[^>]+href=["\']([^"\']+)#i', $html, $matches ) ) {
			return '';
		}

		$source_norm = self::normalize_url( $source_url );
		foreach ( $matches[1] as $raw ) {
			$href = html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5 );
			if ( ! preg_match( '/apply|vendor|booth|registration/i', $href ) ) {
				continue;
			}
			if ( 0 === strpos( $href, '/' ) ) {
				$base = wp_parse_url( $source_url );
				if ( is_array( $base ) && ! empty( $base['host'] ) ) {
					$href = 'https://' . $base['host'] . $href;
				}
			}
			$clean = esc_url_raw( $href );
			if ( '' === $clean || ! str_starts_with( strtolower( $clean ), 'http' ) ) {
				continue;
			}
			if ( $source_norm && self::normalize_url( $clean ) === $source_norm ) {
				continue;
			}
			return $clean;
		}

		return '';
	}

	/**
	 * First Instagram profile URL.
	 *
	 * @param string $html HTML.
	 */
	public static function extract_instagram( string $html ): string {
		if ( preg_match( '#https?://(?:www\.)?instagram\.com/([A-Za-z0-9._]+)/?#i', $html, $m ) ) {
			$handle = sanitize_text_field( $m[1] );
			if ( in_array( strtolower( $handle ), array( 'p', 'reel', 'reels', 'stories', 'explore' ), true ) ) {
				return '';
			}
			return 'https://www.instagram.com/' . $handle;
		}
		return '';
	}

	/**
	 * @param string $html HTML.
	 * @return array<string, mixed>
	 */
	protected static function extract_from_json_ld( string $html ): array {
		$fields = array();
		if ( ! preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
			return $fields;
		}

		foreach ( $matches[1] as $json ) {
			$data = json_decode( html_entity_decode( trim( (string) $json ), ENT_QUOTES | ENT_HTML5 ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$event = self::find_event_node( $data );
			if ( ! $event ) {
				continue;
			}

			if ( ! empty( $event['name'] ) ) {
				$fields['title'] = sanitize_text_field( (string) $event['name'] );
			}
			if ( ! empty( $event['description'] ) ) {
				$fields['description'] = wp_strip_all_tags( (string) $event['description'] );
			}
			if ( ! empty( $event['startDate'] ) ) {
				$fields['start_datetime'] = sanitize_text_field( (string) $event['startDate'] );
			}
			if ( ! empty( $event['endDate'] ) ) {
				$fields['end_datetime'] = sanitize_text_field( (string) $event['endDate'] );
			}
			if ( ! empty( $event['image'] ) ) {
				$fields['image_url'] = self::resolve_image( $event['image'] );
			}

			$location = $event['location'] ?? null;
			if ( is_array( $location ) ) {
				if ( ! empty( $location['name'] ) ) {
					$fields['venue_name'] = sanitize_text_field( (string) $location['name'] );
				}
				$address = $location['address'] ?? null;
				if ( is_array( $address ) ) {
					if ( ! empty( $address['addressLocality'] ) ) {
						$fields['city'] = sanitize_text_field( (string) $address['addressLocality'] );
					}
					if ( ! empty( $address['addressRegion'] ) ) {
						$fields['state'] = sanitize_text_field( (string) $address['addressRegion'] );
					}
				}
			}

			$organizer = $event['organizer'] ?? null;
			if ( is_array( $organizer ) ) {
				if ( ! empty( $organizer['name'] ) ) {
					$fields['organizer_name'] = sanitize_text_field( (string) $organizer['name'] );
				}
				if ( ! empty( $organizer['url'] ) ) {
					$fields['organizer_url'] = esc_url_raw( (string) $organizer['url'] );
				}
				if ( ! empty( $organizer['email'] ) ) {
					$fields['organizer_email'] = sanitize_email( (string) $organizer['email'] );
				}
			}

			if ( ! empty( $event['@type'] ) ) {
				$fields['_category_hint'] = is_array( $event['@type'] ) ? implode( ' ', $event['@type'] ) : (string) $event['@type'];
			}

			if ( ! empty( $fields['title'] ) ) {
				break;
			}
		}

		return $fields;
	}

	/**
	 * @param mixed $node JSON-LD node.
	 * @return array<string, mixed>|null
	 */
	protected static function find_event_node( $node ): ?array {
		if ( ! is_array( $node ) ) {
			return null;
		}
		if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
			foreach ( $node['@graph'] as $child ) {
				$found = self::find_event_node( $child );
				if ( $found ) {
					return $found;
				}
			}
		}
		if ( self::is_list_array( $node ) ) {
			foreach ( $node as $child ) {
				$found = self::find_event_node( $child );
				if ( $found ) {
					return $found;
				}
			}
			return null;
		}

		$type = $node['@type'] ?? '';
		if ( is_array( $type ) ) {
			$type = implode( ' ', $type );
		}
		if ( is_string( $type ) && preg_match( '/event/i', $type ) ) {
			return $node;
		}
		return null;
	}

	/**
	 * @param string $html HTML.
	 * @return array<string, mixed>
	 */
	protected static function extract_from_meta( string $html ): array {
		$fields = array();
		if ( preg_match( '#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)#i', $html, $m ) ) {
			$fields['title'] = sanitize_text_field( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 ) );
		}
		if ( preg_match( '#<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)#i', $html, $m ) ) {
			$fields['description'] = sanitize_text_field( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 ) );
		}
		if ( preg_match( '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)#i', $html, $m ) ) {
			$fields['image_url'] = esc_url_raw( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 ) );
		}
		return $fields;
	}

	/**
	 * @param string $html HTML.
	 */
	protected static function extract_title( string $html ): string {
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			return sanitize_text_field( wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 ) ) );
		}
		return '';
	}

	/**
	 * Obvious date from page text.
	 *
	 * @param string $html HTML.
	 */
	protected static function extract_date_from_text( string $html ): string {
		$text = wp_strip_all_tags( $html );
		if ( preg_match( '/\b((?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+20\d{2})\b/i', $text, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/\b(20\d{2}-\d{2}-\d{2})\b/', $text, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * @param mixed $raw Image field.
	 */
	protected static function resolve_image( $raw ): string {
		if ( is_string( $raw ) ) {
			return esc_url_raw( $raw );
		}
		if ( is_array( $raw ) ) {
			if ( ! empty( $raw['url'] ) ) {
				return esc_url_raw( (string) $raw['url'] );
			}
			if ( ! empty( $raw[0] ) && is_string( $raw[0] ) ) {
				return esc_url_raw( $raw[0] );
			}
		}
		return '';
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
	 * PHP 7.4-safe list check.
	 *
	 * @param array<mixed> $node Node.
	 */
	protected static function is_list_array( array $node ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $node );
		}
		$i = 0;
		foreach ( $node as $key => $_value ) {
			if ( $key !== $i ) {
				return false;
			}
			++$i;
		}
		return true;
	}

	/**
	 * @param string $snippet SERP snippet.
	 */
	protected static function snippet_has_apply_language( string $snippet ): bool {
		$snippet = strtolower( $snippet );
		if ( '' === $snippet ) {
			return false;
		}
		foreach ( array( 'vendors wanted', 'vendor application', 'become a vendor', 'booth application', 'food vendors wanted', 'vendor registration' ) as $term ) {
			if ( str_contains( $snippet, $term ) ) {
				return true;
			}
		}
		return false;
	}
}
