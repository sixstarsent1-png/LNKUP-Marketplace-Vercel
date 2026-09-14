<?php
/**
 * Google Search discovery provider for external events.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Source_Google implements LNKup_External_Source_Provider_Interface {

	/**
	 * @var LNKup_Search_Client_Interface|null
	 */
	protected $client;

	/**
	 * @param LNKup_Search_Client_Interface|null $client Optional injected client.
	 */
	public function __construct( ?LNKup_Search_Client_Interface $client = null ) {
		$this->client = $client;
	}

	public function slug(): string {
		return 'google';
	}

	public function label(): string {
		return __( 'Google Search', 'lnkup-marketplace' );
	}

	public function supports_url_import(): bool {
		return true;
	}

	public function supports_discovery(): bool {
		return true;
	}

	/**
	 * Discover candidate URLs for one combinator query.
	 *
	 * @param array<string, mixed> $context query_text required for live CSE.
	 * @return array<int, string>|WP_Error
	 */
	public function discover_event_urls( array $context ) {
		if ( ! empty( $context['urls'] ) && is_array( $context['urls'] ) ) {
			return $this->filter_urls( $context['urls'], (string) ( $context['snippet'] ?? '' ) );
		}

		$query = trim( (string) ( $context['query_text'] ?? $context['q'] ?? '' ) );
		if ( '' === $query ) {
			return new WP_Error( 'missing_query', __( 'Google discovery requires a query_text.', 'lnkup-marketplace' ) );
		}

		$client = $this->get_client();
		if ( ! $client->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Google Custom Search is not configured.', 'lnkup-marketplace' ) );
		}

		$results = $client->search( $query, 1 );
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$urls = array();
		foreach ( $results as $item ) {
			$url     = (string) ( $item['url'] ?? '' );
			$snippet = (string) ( $item['snippet'] ?? $item['title'] ?? '' );
			if ( LNKup_Page_Extractor::should_skip_url( $url, $snippet ) ) {
				continue;
			}
			$normalized = LNKup_Page_Extractor::normalize_url( $url );
			if ( '' === $normalized ) {
				continue;
			}
			$urls[] = $normalized;
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Parse a discovered landing page (delegates Eventbrite/Posh).
	 *
	 * @param string $url Source URL.
	 * @return array<string, mixed>|WP_Error
	 */
	public function parse_url( string $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( class_exists( 'LNKup_External_Source_Eventbrite' ) && LNKup_External_Source_Eventbrite::is_allowed_host( $host ) ) {
			return ( new LNKup_External_Source_Eventbrite() )->parse_url( $url );
		}
		if ( class_exists( 'LNKup_External_Source_Posh' ) && LNKup_External_Source_Posh::is_allowed_host( $host ) ) {
			return ( new LNKup_External_Source_Posh() )->parse_url( $url );
		}

		$normalized = LNKup_Page_Extractor::normalize_url( $url );
		if ( '' === $normalized ) {
			return new WP_Error( 'invalid_url', __( 'Invalid URL.', 'lnkup-marketplace' ) );
		}
		if ( LNKup_Page_Extractor::should_skip_url( $normalized ) ) {
			return new WP_Error( 'skipped_host', __( 'This host is not imported from Google Search.', 'lnkup-marketplace' ) );
		}

		$html = LNKup_Page_Extractor::fetch_html( $normalized );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$context = array();
		$fields  = LNKup_Page_Extractor::extract( $html, $normalized, $context );
		$title   = sanitize_text_field( (string) ( $fields['title'] ?? '' ) );
		if ( '' === $title ) {
			return new WP_Error( 'parse_failed', __( 'Could not extract event data from this page.', 'lnkup-marketplace' ) );
		}

		$description = (string) ( $fields['description'] ?? $fields['description_excerpt'] ?? '' );

		return array(
			'source_platform'      => 'google',
			'source_url'           => $normalized,
			'source_event_id'      => substr( md5( $normalized ), 0, 16 ),
			'title'                => $title,
			'description'          => $description,
			'description_excerpt'  => LNKup_External_Events::sanitize_excerpt( $description ),
			'image_url'            => esc_url_raw( (string) ( $fields['image_url'] ?? '' ) ),
			'start_datetime'       => $fields['start_datetime'] ?? null,
			'end_datetime'         => $fields['end_datetime'] ?? null,
			'city'                 => sanitize_text_field( (string) ( $fields['city'] ?? '' ) ),
			'state'                => sanitize_text_field( (string) ( $fields['state'] ?? '' ) ),
			'venue_name'           => sanitize_text_field( (string) ( $fields['venue_name'] ?? '' ) ),
			'organizer_name'       => sanitize_text_field( (string) ( $fields['organizer_name'] ?? '' ) ),
			'organizer_url'        => esc_url_raw( (string) ( $fields['organizer_url'] ?? '' ) ),
			'organizer_email'      => sanitize_email( (string) ( $fields['organizer_email'] ?? '' ) ),
			'organizer_instagram'  => esc_url_raw( (string) ( $fields['organizer_instagram'] ?? '' ) ),
			'application_url'      => esc_url_raw( (string) ( $fields['application_url'] ?? '' ) ),
			'event_type'           => sanitize_text_field( (string) ( $fields['event_type'] ?? '' ) ),
			'workflow_status'      => LNKup_External_Events::WORKFLOW_PENDING_REVIEW,
			'_preview_confidence'  => array(
				'title'           => 'high',
				'date'            => ! empty( $fields['start_datetime'] ) ? 'medium' : 'low',
				'organizer_email' => ! empty( $fields['organizer_email'] ) ? 'high' : 'low',
			),
		);
	}

	/**
	 * Resolve CSE client.
	 */
	protected function get_client(): LNKup_Search_Client_Interface {
		if ( $this->client ) {
			return $this->client;
		}
		$this->client = LNKup_Search_Client_Google_Cse::from_settings();
		return $this->client;
	}

	/**
	 * @param array<int, mixed> $urls    Raw URLs.
	 * @param string            $snippet Shared snippet.
	 * @return array<int, string>
	 */
	protected function filter_urls( array $urls, string $snippet ): array {
		$out = array();
		foreach ( $urls as $url ) {
			$url = (string) $url;
			if ( LNKup_Page_Extractor::should_skip_url( $url, $snippet ) ) {
				continue;
			}
			$normalized = LNKup_Page_Extractor::normalize_url( $url );
			if ( '' !== $normalized ) {
				$out[] = $normalized;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
