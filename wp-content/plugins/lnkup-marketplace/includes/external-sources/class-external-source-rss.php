<?php
/**
 * Generic RSS / Atom feed import provider.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_External_Source_Rss implements LNKup_External_Source_Provider_Interface {

	public function slug(): string {
		return 'rss';
	}

	public function label(): string {
		return __( 'RSS / Atom feed', 'lnkup-marketplace' );
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
		return new WP_Error( 'unsupported', __( 'RSS discovery sync is not available.', 'lnkup-marketplace' ) );
	}

	/**
	 * Whether a URL looks like a feed (path/query heuristics).
	 *
	 * @param string $url URL.
	 */
	public static function looks_like_feed_url( string $url ): bool {
		$url = strtolower( trim( $url ) );
		if ( '' === $url || ! preg_match( '#^https?://#', $url ) ) {
			return false;
		}
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( preg_match( '#\.(rss|xml|atom)(\?|$)#', $path ) ) {
			return true;
		}
		if ( preg_match( '#/(feed|rss|atom)(/|$)#', $path ) ) {
			return true;
		}
		if ( preg_match( '#(^|&)(feed|format)=(rss|atom|xml)#', $query ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $url Feed URL.
	 * @return array<string, mixed>|WP_Error
	 */
	public function parse_url( string $url ) {
		$url = esc_url_raw( trim( $url ) );
		if ( '' === $url ) {
			return new WP_Error( 'missing_url', __( 'Feed URL is required.', 'lnkup-marketplace' ) );
		}

		if ( ! class_exists( 'SimplePie', false ) ) {
			include_once ABSPATH . WPINC . '/class-simplepie.php';
		}
		include_once ABSPATH . WPINC . '/feed.php';

		$feed = fetch_feed( $url );
		if ( is_wp_error( $feed ) ) {
			return new WP_Error(
				'feed_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not read feed: %s', 'lnkup-marketplace' ),
					$feed->get_error_message()
				)
			);
		}

		$max = $feed->get_item_quantity( 25 );
		if ( $max < 1 ) {
			return new WP_Error( 'empty_feed', __( 'This feed has no items.', 'lnkup-marketplace' ) );
		}

		$items = $feed->get_items( 0, $max );
		$item  = self::pick_item( $items, $url );
		if ( ! $item ) {
			return new WP_Error( 'empty_feed', __( 'Could not select an item from this feed.', 'lnkup-marketplace' ) );
		}

		$title = wp_strip_all_tags( (string) $item->get_title() );
		if ( '' === $title ) {
			return new WP_Error( 'parse_failed', __( 'Feed item is missing a title.', 'lnkup-marketplace' ) );
		}

		$desc = wp_strip_all_tags( (string) ( $item->get_description() ?: $item->get_content() ) );
		$link = esc_url_raw( (string) $item->get_permalink() );
		$date = $item->get_date( 'Y-m-d H:i:s' );
		if ( ! is_string( $date ) || '' === $date ) {
			$date = null;
		}

		$image = '';
		$enclosure = $item->get_enclosure();
		if ( $enclosure && method_exists( $enclosure, 'get_link' ) ) {
			$etype = method_exists( $enclosure, 'get_type' ) ? (string) $enclosure->get_type() : '';
			$elink = (string) $enclosure->get_link();
			if ( $elink && ( 0 === strpos( $etype, 'image/' ) || preg_match( '#\.(jpg|jpeg|png|gif|webp)(\?|$)#i', $elink ) ) ) {
				$image = esc_url_raw( $elink );
			}
		}

		$event_type = LNKup_External_Events::map_event_type( '', $title );
		$city       = '';
		$state      = '';
		$venue      = '';
		if ( preg_match( '/\b([A-Za-z .\'-]+),\s*([A-Z]{2})\b/', $desc, $m ) ) {
			$city  = sanitize_text_field( $m[1] );
			$state = sanitize_text_field( $m[2] );
		}

		return array(
			'source_platform'     => 'rss',
			'source_url'          => $link ?: $url,
			'source_event_id'     => md5( ( $link ?: $url ) . '|' . $title ),
			'title'               => sanitize_text_field( $title ),
			'description'         => $desc,
			'description_excerpt' => LNKup_External_Events::sanitize_excerpt( $desc ),
			'image_url'           => $image,
			'start_datetime'      => $date,
			'end_datetime'        => null,
			'city'                => $city,
			'state'               => $state,
			'venue_name'          => $venue,
			'organizer_name'      => sanitize_text_field( (string) $feed->get_title() ),
			'organizer_email'     => '',
			'event_type'          => sanitize_text_field( $event_type ),
			'workflow_status'     => LNKup_External_Events::WORKFLOW_PENDING_REVIEW,
			'_feed_url'           => $url,
			'_preview_confidence' => array(
				'title' => 'high',
				'date'  => $date ? 'medium' : 'low',
				'image' => $image ? 'medium' : 'low',
			),
		);
	}

	/**
	 * Prefer an upcoming item; otherwise first item. Optional ?item= index (0-based).
	 *
	 * @param array<int, SimplePie_Item> $items Feed items.
	 * @param string                     $url   Original URL (may include item query).
	 * @return SimplePie_Item|null
	 */
	protected static function pick_item( array $items, string $url ) {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $params );
		if ( isset( $params['item'] ) && is_numeric( $params['item'] ) ) {
			$idx = max( 0, (int) $params['item'] );
			return $items[ $idx ] ?? null;
		}

		$now = time();
		foreach ( $items as $item ) {
			$ts = $item->get_date( 'U' );
			if ( is_numeric( $ts ) && (int) $ts >= ( $now - DAY_IN_SECONDS ) ) {
				return $item;
			}
		}

		return $items[0] ?? null;
	}
}
