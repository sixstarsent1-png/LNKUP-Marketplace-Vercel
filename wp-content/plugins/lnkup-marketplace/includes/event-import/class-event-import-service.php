<?php
/**
 * URL → builder draft import.
 *
 * Reads only what a page publishes as structured data (Schema.org Event JSON-LD, Open Graph,
 * <title>). Never executes scripts, never guesses dates from prose, never maps attendee ticket
 * prices or attendee capacity onto vendor booth values. Every imported field carries provenance
 * so the organizer can review it and later syncs never overwrite organizer edits.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Import_Service {

	const CACHE_TTL = 600;

	/**
	 * Fetch + parse a listing into a reviewable import preview.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function preview( string $url, int $user_id, bool $use_cache = true ) {
		$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		if ( '' === $url ) {
			return new WP_Error( 'lnkup_import_url', __( 'Paste a full event link that starts with https://', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$provider = LNKup_Import_Provider_Registry::detect( $url );
		if ( empty( $provider['enabled'] ) ) {
			return new WP_Error( 'lnkup_import_disabled', __( 'Importing from this platform is turned off right now. You can still add the link to your event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$relation = LNKup_Event_Sources::relation( $url, $user_id );
		$base     = array(
			'url'        => $url,
			'canonical'  => LNKup_Event_Sources::canonical_url( $url ),
			'provider'   => array_intersect_key( $provider, array_flip( array( 'key', 'label', 'level', 'limitations' ) ) ),
			'relation'   => $relation,
			'duplicates' => array(),
		);
		if ( empty( $provider['url_import'] ) ) {
			return array_merge( $base, self::empty_result( 'link_only', __( 'This platform does not let LNKup read event details. We\'ll link the listing; add the details yourself.', 'lnkup-marketplace' ) ) );
		}

		$cache_key = 'lnkup_import_' . md5( $base['canonical'] );
		$parsed    = $use_cache ? get_transient( $cache_key ) : false;
		if ( ! is_array( $parsed ) ) {
			$fetched = LNKup_Safe_Fetch::get( $url, array( 'html', 'xhtml' ) );
			if ( is_wp_error( $fetched ) ) {
				$status = (int) ( $fetched->get_error_data()['status'] ?? 502 );
				if ( in_array( $fetched->get_error_code(), array( 'lnkup_fetch_private', 'lnkup_fetch_host', 'lnkup_fetch_scheme', 'lnkup_fetch_port', 'lnkup_fetch_url', 'lnkup_fetch_rate', 'lnkup_fetch_dns' ), true ) ) {
					return $fetched;
				}
				return array_merge( $base, self::empty_result( 'unreachable', $fetched->get_error_message(), $status ) );
			}
			$parsed              = self::parse_html( $fetched['body'], $fetched['url'] );
			$parsed['final_url'] = $fetched['url'];
			set_transient( $cache_key, $parsed, self::CACHE_TTL );
		}
		$normalized = self::normalize( $parsed, $url, (string) $provider['key'] );
		$out        = array_merge( $base, $normalized );
		$out['duplicates'] = self::probable_duplicates( $normalized['fields'], $user_id );
		return $out;
	}

	/**
	 * Structured data from HTML (no script execution — JSON-LD blocks are parsed as data only).
	 *
	 * @return array<string,mixed>
	 */
	public static function parse_html( string $html, string $url ): array {
		$events = array();
		if ( preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			foreach ( $m[1] as $json ) {
				$data = json_decode( trim( (string) $json ), true );
				if ( ! is_array( $data ) ) {
					$data = json_decode( html_entity_decode( trim( (string) $json ), ENT_QUOTES | ENT_HTML5 ), true );
				}
				if ( is_array( $data ) ) {
					self::collect_events( $data, $events );
				}
			}
		}
		$meta = array();
		foreach ( array( 'og:title', 'og:description', 'og:image', 'og:url', 'og:site_name', 'event:start_time', 'event:end_time' ) as $prop ) {
			$value = self::meta( $html, $prop );
			if ( '' !== $value ) {
				$meta[ $prop ] = $value;
			}
		}
		$title = preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $t ) ? trim( html_entity_decode( wp_strip_all_tags( $t[1] ), ENT_QUOTES | ENT_HTML5 ) ) : '';
		return array( 'events' => array_slice( $events, 0, 60 ), 'meta' => $meta, 'title' => $title, 'source_url' => $url );
	}

	/**
	 * Builder-shaped fields + provenance + what is missing.
	 *
	 * @param array<string,mixed> $parsed parse_html() output.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $parsed, string $url, string $provider ): array {
		$events = (array) ( $parsed['events'] ?? array() );
		$meta   = (array) ( $parsed['meta'] ?? array() );
		$node   = $events[0] ?? array();
		$f      = array();
		$prov   = array();
		$warn   = array();
		$set    = static function ( string $field, $value, string $origin ) use ( &$f, &$prov ) {
			if ( '' !== $value && null !== $value && array() !== $value ) {
				$f[ $field ]    = $value;
				$prov[ $field ] = $origin;
			}
		};

		$name = self::clean_title( (string) ( $node['name'] ?? '' ) );
		$set( 'name', $name, 'structured' );
		if ( '' === $name ) {
			$set( 'name', self::clean_title( (string) ( $meta['og:title'] ?? $parsed['title'] ?? '' ) ), 'page_meta' );
		}

		$start = self::parse_datetime( (string) ( $node['startDate'] ?? $meta['event:start_time'] ?? '' ) );
		$end   = self::parse_datetime( (string) ( $node['endDate'] ?? $meta['event:end_time'] ?? '' ) );
		if ( $start ) {
			$origin = isset( $node['startDate'] ) ? 'structured' : 'page_meta';
			$set( 'date', $start['date'], $origin );
			if ( '' !== $start['time'] ) {
				$set( 'start_time', $start['time'], $origin );
			}
			if ( '' !== $start['timezone'] ) {
				$set( 'timezone', $start['timezone'], $origin );
			}
		}
		if ( $end ) {
			if ( $start && $end['date'] !== $start['date'] ) {
				$set( 'end_date', $end['date'], 'structured' );
			}
			if ( '' !== $end['time'] ) {
				$set( 'end_time', $end['time'], 'structured' );
			}
		}

		$loc = $node['location'] ?? null;
		if ( is_array( $loc ) && self::is_list( $loc ) ) {
			$loc = $loc[0] ?? null;
		}
		$online = false;
		if ( is_array( $loc ) ) {
			$type   = is_array( $loc['@type'] ?? '' ) ? implode( ' ', $loc['@type'] ) : (string) ( $loc['@type'] ?? '' );
			$online = false !== stripos( $type, 'Virtual' );
			if ( ! $online ) {
				$set( 'venue_name', self::text( $loc['name'] ?? '', 120 ), 'structured' );
				$addr = $loc['address'] ?? null;
				if ( is_array( $addr ) ) {
					$set( 'address', self::text( $addr['streetAddress'] ?? '', 160 ), 'structured' );
					$set( 'city', self::text( $addr['addressLocality'] ?? '', 80 ), 'structured' );
					$set( 'state', self::state_code( (string) ( $addr['addressRegion'] ?? '' ) ), 'structured' );
					$set( 'zip', self::text( $addr['postalCode'] ?? '', 12 ), 'structured' );
					$country = is_array( $addr['addressCountry'] ?? null ) ? (string) ( $addr['addressCountry']['name'] ?? '' ) : (string) ( $addr['addressCountry'] ?? '' );
					if ( '' !== $country && ! in_array( strtoupper( $country ), array( 'US', 'USA', 'UNITED STATES', 'UNITED STATES OF AMERICA' ), true ) ) {
						$warn[] = __( 'This event looks like it is outside the United States. LNKup currently supports US events.', 'lnkup-marketplace' );
					}
				} elseif ( is_string( $addr ) && '' !== trim( $addr ) ) {
					$set( 'address', self::text( $addr, 160 ), 'structured' );
				}
				if ( isset( $loc['geo']['latitude'], $loc['geo']['longitude'] ) && is_numeric( $loc['geo']['latitude'] ) && is_numeric( $loc['geo']['longitude'] ) ) {
					$set( 'geo', array( 'lat' => (float) $loc['geo']['latitude'], 'lng' => (float) $loc['geo']['longitude'] ), 'structured' );
				}
			}
		}
		if ( $online || 'OnlineEventAttendanceMode' === self::enum_tail( (string) ( $node['eventAttendanceMode'] ?? '' ) ) ) {
			$warn[] = __( 'This listing is an online event. Vendor booths need a physical location — add one before publishing.', 'lnkup-marketplace' );
		}

		$description = self::description( (string) ( $node['description'] ?? '' ) );
		$set( 'description', $description, 'structured' );
		if ( '' === $description ) {
			$set( 'description', self::description( (string) ( $meta['og:description'] ?? '' ) ), 'page_meta' );
		}

		$image = self::image( $node['image'] ?? '' );
		$set( 'image_url', $image, 'structured' );
		if ( '' === $image ) {
			$set( 'image_url', esc_url_raw( (string) ( $meta['og:image'] ?? '' ) ), 'page_meta' );
		}

		$org = $node['organizer'] ?? null;
		if ( is_array( $org ) && self::is_list( $org ) ) {
			$org = $org[0] ?? null;
		}
		if ( is_array( $org ) ) {
			$set( 'organizer_name', self::text( $org['name'] ?? '', 120 ), 'structured' );
		}

		$status = self::enum_tail( (string) ( $node['eventStatus'] ?? '' ) );
		if ( in_array( $status, array( 'EventCancelled', 'EventPostponed', 'EventMovedOnline' ), true ) ) {
			$warn[] = 'EventCancelled' === $status ? __( 'The source lists this event as cancelled.', 'lnkup-marketplace' ) : ( 'EventPostponed' === $status ? __( 'The source lists this event as postponed. Confirm the new date.', 'lnkup-marketplace' ) : __( 'The source says this event moved online.', 'lnkup-marketplace' ) );
		}
		if ( $start && $start['date'] < current_time( 'Y-m-d' ) ) {
			$warn[] = __( 'This event date has already passed. Choose a new date, or duplicate it as a future occurrence.', 'lnkup-marketplace' );
		}

		$occurrences = array();
		foreach ( $events as $i => $ev ) {
			$s = self::parse_datetime( (string) ( $ev['startDate'] ?? '' ) );
			if ( ! $s ) {
				continue;
			}
			$e   = self::parse_datetime( (string) ( $ev['endDate'] ?? '' ) );
			$loc = is_array( $ev['location'] ?? null ) ? $ev['location'] : array();
			$adr = is_array( $loc['address'] ?? null ) ? $loc['address'] : array();
			$occurrences[ $s['date'] . ' ' . $s['time'] . ' ' . ( $adr['addressLocality'] ?? '' ) ] = array(
				'name'       => self::clean_title( (string) ( $ev['name'] ?? '' ) ),
				'date'       => $s['date'],
				'start_time' => $s['time'],
				'end_time'   => $e ? $e['time'] : '',
				'venue_name' => self::text( $loc['name'] ?? '', 120 ),
				'city'       => self::text( $adr['addressLocality'] ?? '', 80 ),
				'state'      => self::state_code( (string) ( $adr['addressRegion'] ?? '' ) ),
				'url'        => esc_url_raw( (string) ( $ev['url'] ?? '' ) ),
			);
			unset( $i );
		}
		ksort( $occurrences );
		$occurrences = array_values( $occurrences );

		$missing = array();
		foreach ( array( 'name' => __( 'Event name', 'lnkup-marketplace' ), 'date' => __( 'Date', 'lnkup-marketplace' ), 'start_time' => __( 'Start time', 'lnkup-marketplace' ), 'city' => __( 'City', 'lnkup-marketplace' ), 'venue_name' => __( 'Venue', 'lnkup-marketplace' ), 'image_url' => __( 'Event image', 'lnkup-marketplace' ), 'description' => __( 'Description', 'lnkup-marketplace' ) ) as $k => $label ) {
			if ( ! isset( $f[ $k ] ) ) {
				$missing[] = array( 'field' => $k, 'label' => $label );
			}
		}
		$found = count( array_intersect( array_keys( $f ), array( 'name', 'date', 'city', 'venue_name', 'description', 'image_url', 'start_time' ) ) );
		$state = 0 === $found ? 'nothing' : ( $missing ? 'partial' : 'complete' );

		$ext_id = '';
		if ( isset( $node['identifier'] ) && is_scalar( $node['identifier'] ) ) {
			$ext_id = self::text( $node['identifier'], 100 );
		}
		if ( '' === $ext_id ) {
			$ext_id = LNKup_Event_Sources::external_id( $url, $provider );
		}

		$messages = array(
			'complete' => __( 'We found the event details. Review them before continuing.', 'lnkup-marketplace' ),
			'partial'  => __( 'We found some details. Fill in the rest — we never guess missing information.', 'lnkup-marketplace' ),
			'nothing'  => __( 'This page doesn\'t share event details we can read. We\'ll link the listing; add the details yourself.', 'lnkup-marketplace' ),
		);
		return array(
			'status'      => $state,
			'message'     => $messages[ $state ],
			'http_status' => 200,
			'fields'      => $f,
			'provenance'  => $prov,
			'missing'     => $missing,
			'warnings'    => array_values( array_unique( $warn ) ),
			'occurrences' => count( $occurrences ) > 1 ? array_slice( $occurrences, 0, 52 ) : array(),
			'external_id' => $ext_id,
			'final_url'   => esc_url_raw( (string) ( $parsed['final_url'] ?? $url ) ),
		);
	}

	/**
	 * Builder state patch for an import preview. Only the fields the organizer kept are applied.
	 *
	 * @param array<string,mixed> $preview preview() output.
	 * @param string[]|null       $keep    Field keys to apply (null = all found).
	 * @return array<string,mixed>
	 */
	public static function to_state_patch( array $preview, ?array $keep = null ): array {
		$fields = (array) ( $preview['fields'] ?? array() );
		if ( null !== $keep ) {
			$fields = array_intersect_key( $fields, array_flip( $keep ) );
		}
		$event = array();
		$map   = array( 'name' => 'name', 'date' => 'date', 'end_date' => 'end_date', 'start_time' => 'start_time', 'end_time' => 'end_time', 'timezone' => 'timezone', 'venue_name' => 'venue_name', 'address' => 'venue_address', 'city' => 'city', 'state' => 'state', 'zip' => 'postal_code', 'description' => 'description' );
		$prov  = array();
		foreach ( $map as $from => $to ) {
			if ( isset( $fields[ $from ] ) ) {
				$event[ $to ]            = $fields[ $from ];
				$prov[ 'event.' . $to ] = 'imported';
			}
		}
		if ( isset( $fields['city'] ) ) {
			$event['location_confirmed'] = false;
		}
		$provider = (string) ( $preview['provider']['key'] ?? '' );
		$url      = (string) ( $preview['url'] ?? '' );
		return array(
			'event'      => $event,
			'sources'    => array(
				array( 'url' => $url, 'provider' => 'website' === $provider ? '' : $provider, 'primary' => true, 'mode' => 'import' ),
			),
			'import'     => array(
				'provider'          => $provider,
				'external_event_id' => (string) ( $preview['external_id'] ?? '' ),
				'original_url'      => $url,
				'source_city'       => (string) ( $fields['city'] ?? '' ),
				'source_state'      => (string) ( $fields['state'] ?? '' ),
				'conflict'          => in_array( $preview['relation']['state'] ?? '', array( 'other', 'claimed_other' ), true ) ? (string) $preview['relation']['message'] : '',
			),
			'provenance' => $prov,
		);
	}

	/**
	 * Copy an imported image into the media library as the organizer's own upload.
	 *
	 * @return int|WP_Error Attachment ID.
	 */
	public static function sideload_image( string $image_url, int $user_id ) {
		$fetched = LNKup_Safe_Fetch::get( $image_url, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ) );
		if ( is_wp_error( $fetched ) ) {
			return $fetched;
		}
		if ( strlen( $fetched['body'] ) > 8 * MB_IN_BYTES || '' === $fetched['body'] ) {
			return new WP_Error( 'lnkup_import_image', __( 'That image is too large. Upload one under 8 MB.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$ext = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif' );
		$use = 'jpg';
		foreach ( $ext as $mime => $e ) {
			if ( false !== strpos( $fetched['content_type'], $mime ) ) {
				$use = $e;
			}
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( 'lnkup-import' );
		if ( ! $tmp ) {
			return new WP_Error( 'lnkup_import_image', __( 'We could not save that image. Upload it instead.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		file_put_contents( $tmp, $fetched['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$check = wp_check_filetype_and_ext( $tmp, 'event-image.' . $use );
		if ( empty( $check['type'] ) || 0 !== strpos( (string) $check['type'], 'image/' ) || ! @getimagesize( $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_delete_file( $tmp );
			return new WP_Error( 'lnkup_import_image', __( 'That file is not a supported image.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$prev = get_current_user_id();
		wp_set_current_user( $user_id );
		$id = media_handle_sideload( array( 'name' => 'event-image-' . wp_generate_password( 6, false ) . '.' . $use, 'tmp_name' => $tmp ), 0 );
		wp_set_current_user( $prev );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'lnkup_import_image', __( 'We could not save that image. Upload it instead.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		update_post_meta( (int) $id, '_lnkup_uploaded_by', $user_id );
		update_post_meta( (int) $id, '_lnkup_import_source', esc_url_raw( $image_url ) );
		return (int) $id;
	}

	/**
	 * The organizer's own events that look like the same event (same date + name or same date + city).
	 *
	 * @param array<string,mixed> $fields Normalized fields.
	 * @return array<int,array<string,mixed>>
	 */
	public static function probable_duplicates( array $fields, int $user_id ): array {
		if ( empty( $fields['date'] ) ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_organizer_user_id', 'value' => $user_id ),
					array( 'key' => '_lnkup_event_date', 'value' => (string) $fields['date'] ),
				),
			)
		);
		$out  = array();
		$name = strtolower( (string) ( $fields['name'] ?? '' ) );
		foreach ( $ids as $id ) {
			$title = strtolower( get_the_title( (int) $id ) );
			similar_text( $name, $title, $pct );
			$city  = strtolower( (string) get_post_meta( (int) $id, '_lnkup_city', true ) );
			$same_city = '' !== $city && $city === strtolower( (string) ( $fields['city'] ?? '' ) );
			if ( $pct >= 70 || $same_city ) {
				$out[] = array(
					'event_id' => (int) $id,
					'title'    => get_the_title( (int) $id ),
					'date'     => (string) $fields['date'],
					'city'     => (string) get_post_meta( (int) $id, '_lnkup_city', true ),
					'match'    => $pct >= 90 ? 'exact' : 'probable',
				);
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------ Helpers */

	/**
	 * @param array<mixed>             $node JSON-LD node.
	 * @param array<int,array<mixed>>  $out  Collected Event nodes.
	 */
	private static function collect_events( array $node, array &$out, int $depth = 0 ): void {
		if ( $depth > 6 || count( $out ) >= 60 ) {
			return;
		}
		if ( self::is_list( $node ) ) {
			foreach ( $node as $child ) {
				if ( is_array( $child ) ) {
					self::collect_events( $child, $out, $depth + 1 );
				}
			}
			return;
		}
		$type = is_array( $node['@type'] ?? null ) ? implode( ' ', $node['@type'] ) : (string) ( $node['@type'] ?? '' );
		if ( preg_match( '/(^|\s)(\w*Event|Festival)(\s|$)/', $type ) && ! empty( $node['startDate'] ) ) {
			$out[] = $node;
		}
		foreach ( array( '@graph', 'subEvent', 'subEvents', 'itemListElement', 'item', 'mainEntity' ) as $k ) {
			if ( isset( $node[ $k ] ) && is_array( $node[ $k ] ) ) {
				self::collect_events( $node[ $k ], $out, $depth + 1 );
			}
		}
	}

	/**
	 * @return array{date:string,time:string,timezone:string}|null
	 */
	public static function parse_datetime( string $raw ): ?array {
		$raw = trim( $raw );
		if ( '' === $raw || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?)?\s*(Z|[+-]\d{2}:?\d{2})?/', $raw, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		// Local wall-clock time is kept as published. A UTC offset does not identify a time zone
		// (-05:00 is both Eastern standard and Central daylight), so none is inferred.
		return array(
			'date'     => $m[1] . '-' . $m[2] . '-' . $m[3],
			'time'     => isset( $m[4] ) && '' !== $m[4] ? $m[4] . ':' . $m[5] : '',
			'timezone' => '',
		);
	}

	private static function clean_title( string $title ): string {
		$title = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5 );
		$title = preg_replace( '/\s*[|\-–—]\s*(Eventbrite|POSH|Posh|Luma|Eventeny|TicketLeap|Ticket Tailor|Humanitix|Tickets?)\s*$/i', '', $title );
		$title = preg_replace( '/^(Tickets?\s+(for\s+)?|Buy tickets\s*[:\-]\s*)/i', '', (string) $title );
		$title = preg_replace( '/\s+(Tickets?)\s*(,.*)?$/i', '', (string) $title );
		return self::text( $title, 140 );
	}

	private static function description( string $raw ): string {
		$text = html_entity_decode( wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />', '</p>' ), "\n", $raw ) ), ENT_QUOTES | ENT_HTML5 );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
		return trim( sanitize_textarea_field( mb_substr( (string) $text, 0, 5000 ) ) );
	}

	/**
	 * @param mixed $raw JSON-LD image value.
	 */
	private static function image( $raw ): string {
		if ( is_array( $raw ) ) {
			$raw = self::is_list( $raw ) ? ( $raw[0] ?? '' ) : ( $raw['url'] ?? $raw['contentUrl'] ?? '' );
			if ( is_array( $raw ) ) {
				$raw = $raw['url'] ?? '';
			}
		}
		return esc_url_raw( (string) $raw, array( 'http', 'https' ) );
	}

	private static function state_code( string $raw ): string {
		$raw = trim( $raw );
		if ( preg_match( '/^[A-Za-z]{2}$/', $raw ) ) {
			return strtoupper( $raw );
		}
		$code = class_exists( 'LNKup_Location_Service' ) ? LNKup_Location_Service::state_code( $raw ) : null;
		return $code ? (string) $code : self::text( $raw, 40 );
	}

	private static function meta( string $html, string $prop ): string {
		$p = preg_quote( $prop, '#' );
		if ( preg_match( '#<meta[^>]+(?:property|name)=["\']' . $p . '["\'][^>]*content=["\']([^"\']*)#i', $html, $m ) || preg_match( '#<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']' . $p . '["\']#i', $html, $m ) ) {
			return trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 ) );
		}
		return '';
	}

	private static function enum_tail( string $v ): string {
		$parts = preg_split( '#[/:]#', $v );
		return (string) end( $parts );
	}

	/**
	 * @param mixed $v Value.
	 */
	private static function text( $v, int $max ): string {
		return is_scalar( $v ) ? mb_substr( sanitize_text_field( html_entity_decode( (string) $v, ENT_QUOTES | ENT_HTML5 ) ), 0, $max ) : '';
	}

	/**
	 * @param array<mixed> $a Array.
	 */
	private static function is_list( array $a ): bool {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function empty_result( string $status, string $message, int $http = 200 ): array {
		return array( 'status' => $status, 'message' => $message, 'http_status' => $http, 'fields' => array(), 'provenance' => array(), 'missing' => array(), 'warnings' => array(), 'occurrences' => array(), 'external_id' => '', 'final_url' => '' );
	}
}
