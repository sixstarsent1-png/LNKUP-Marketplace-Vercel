<?php
/**
 * Google Custom Search JSON API client.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Search_Client_Google_Cse implements LNKup_Search_Client_Interface {

	const ENDPOINT = 'https://www.googleapis.com/customsearch/v1';

	/**
	 * @var string
	 */
	protected $api_key;

	/**
	 * @var string
	 */
	protected $cx;

	/**
	 * @param string $api_key API key.
	 * @param string $cx      Programmable Search Engine ID.
	 */
	public function __construct( string $api_key, string $cx ) {
		$this->api_key = trim( $api_key );
		$this->cx      = trim( $cx );
	}

	/**
	 * Build from plugin settings.
	 */
	public static function from_settings(): self {
		$settings = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		return new self(
			(string) ( $settings['google_cse_api_key'] ?? '' ),
			(string) ( $settings['google_cse_cx'] ?? '' )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key && '' !== $this->cx;
	}

	/**
	 * {@inheritDoc}
	 */
	public function search( string $query, int $start = 1 ) {
		$query = trim( $query );
		if ( '' === $query ) {
			return new WP_Error( 'empty_query', __( 'Search query is empty.', 'lnkup-marketplace' ) );
		}
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Google Custom Search is not configured.', 'lnkup-marketplace' ) );
		}

		$start = max( 1, min( 91, $start ) );
		$url   = add_query_arg(
			array(
				'key'   => $this->api_key,
				'cx'    => $this->cx,
				'q'     => $query,
				'num'   => 10,
				'start' => $start,
			),
			self::ENDPOINT
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'LNKup-Marketplace/' . ( defined( 'LNKUP_VERSION' ) ? LNKUP_VERSION : '12' ) . ' WordPress',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 429 === $code || 403 === $code ) {
			$message = __( 'Google Search quota exceeded. Try again tomorrow or raise the daily budget.', 'lnkup-marketplace' );
			if ( is_array( $data ) && ! empty( $data['error']['message'] ) ) {
				$message = sanitize_text_field( (string) $data['error']['message'] );
			}
			return new WP_Error( 'quota', $message, array( 'status' => $code ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'search_failed',
				sprintf(
					/* translators: %d: HTTP status */
					__( 'Google Search request failed (%d).', 'lnkup-marketplace' ),
					$code
				),
				array( 'status' => $code )
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_json', __( 'Google Search returned invalid JSON.', 'lnkup-marketplace' ) );
		}

		$items = array();
		foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$link = esc_url_raw( (string) ( $item['link'] ?? '' ) );
			if ( '' === $link ) {
				continue;
			}
			$items[] = array(
				'url'     => $link,
				'title'   => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'snippet' => sanitize_text_field( (string) ( $item['snippet'] ?? '' ) ),
			);
		}

		return $items;
	}
}
