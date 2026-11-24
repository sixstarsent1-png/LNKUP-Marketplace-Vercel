<?php
/**
 * Event Media Resolution Engine.
 *
 * One place decides which image represents an event on every surface (theme cards, SEO,
 * checkout, REST for the Next.js and Expo apps). Uploaded artwork always wins over inherited
 * or LNKup artwork; nothing here deletes or rewrites an uploaded image.
 *
 * Automatic priority: admin override, organizer-chosen mode, opportunity cover, event cover
 * (_lnkup_featured_image_id, then the wp-admin featured image), official flyer, first gallery
 * image, imported listing image, tour cover / tour brand logo, organizer brand cover / logo,
 * LNKup event-type artwork, generated SVG cover (always available).
 *
 * Results are cached in post meta keyed by a signature of every input, so any change to those
 * inputs (new upload, logo change, tour cover change, deleted attachment) re-resolves on the
 * next read without explicit invalidation.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Media {

	const VERSION          = 1;
	const META_RESOLVED    = '_lnkup_media_resolved';
	const META_MODE        = '_lnkup_media_mode';
	const META_OVERRIDE    = '_lnkup_media_override_id';
	const META_FLYER       = '_lnkup_flyer_id';
	const GENERATION_OPT   = 'lnkup_media_generation';
	const PORTRAIT_BELOW   = 0.9;

	/** Organizer-selectable display modes ("auto" = best available). */
	const MODES = array( 'auto', 'cover', 'flyer', 'tour', 'organizer', 'category', 'generated' );

	/** Media sources, highest priority first in automatic mode. */
	const SOURCES = array( 'override', 'opportunity_cover', 'event_cover', 'event_thumbnail', 'flyer', 'gallery', 'external', 'tour_cover', 'tour_brand', 'organizer_cover', 'organizer_logo', 'category_art', 'generated' );

	/** Sources that are the organizer's (or admin's) own artwork for this event. */
	const UPLOADED_SOURCES = array( 'override', 'opportunity_cover', 'event_cover', 'event_thumbnail', 'flyer', 'gallery', 'external' );

	/** @var array<string,array<string,mixed>> */
	private static array $memo = array();

	public static function init(): void {
		add_action( 'delete_attachment', array( __CLASS__, 'bump_generation' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'bump_generation' ) );
		add_action( 'updated_user_meta', array( __CLASS__, 'on_user_meta' ), 10, 3 );
		add_action( 'added_user_meta', array( __CLASS__, 'on_user_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ), 20 );
	}

	/**
	 * Small shared stylesheet for event media frames (loaded after theme/plugin card styles).
	 */
	public static function enqueue_styles(): void {
		if ( defined( 'LNKUP_PLUGIN_URL' ) ) {
			wp_enqueue_style( 'lnkup-event-media', LNKUP_PLUGIN_URL . 'public/css/event-media.css', array(), defined( 'LNKUP_VERSION' ) ? LNKUP_VERSION : '1' );
		}
	}

	/**
	 * Any attachment edit or deletion may change validity anywhere; signatures include this counter.
	 */
	public static function bump_generation(): void {
		update_option( self::GENERATION_OPT, (int) get_option( self::GENERATION_OPT, 0 ) + 1, false );
		self::$memo = array();
	}

	/**
	 * Organizer logo/cover changes: re-sync that organizer's marketplace index rows.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $user_id  User.
	 * @param string $meta_key Key.
	 */
	public static function on_user_meta( $meta_id, $user_id, $meta_key ): void {
		unset( $meta_id );
		if ( in_array( (string) $meta_key, array( '_lnkup_organizer_logo_id', '_lnkup_organizer_cover_id', '_lnkup_organizer_public_name' ), true ) ) {
			self::$memo = array();
			self::schedule_reindex_for_organizer( (int) $user_id );
		}
	}

	/**
	 * Image meta changes on events, opportunities and tours re-sync the index.
	 *
	 * @param int    $meta_id  Meta ID (or IDs).
	 * @param int    $post_id  Post.
	 * @param string $meta_key Key.
	 */
	public static function on_post_meta( $meta_id, $post_id, $meta_key ): void {
		unset( $meta_id );
		$watched = array( '_lnkup_featured_image_id', '_thumbnail_id', '_lnkup_gallery_ids', self::META_FLYER, self::META_MODE, self::META_OVERRIDE, '_lnkup_tour_id' );
		if ( ! in_array( (string) $meta_key, $watched, true ) ) {
			return;
		}
		self::$memo = array();
		$type = get_post_type( (int) $post_id );
		if ( 'lnkup_event' === $type ) {
			self::schedule_reindex_for_events( array( (int) $post_id ) );
		} elseif ( 'lnkup_tour' === $type ) {
			self::schedule_reindex_for_events( self::tour_event_ids( (int) $post_id ) );
		} elseif ( 'lnkup_opportunity' === $type && class_exists( 'LNKup_Marketplace_Index' ) ) {
			LNKup_Marketplace_Index::sync_opportunity( (int) $post_id );
		}
	}

	/**
	 * @param int $tour_id Tour.
	 * @return int[]
	 */
	public static function tour_event_ids( int $tour_id ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'lnkup_event',
					'post_status'    => 'any',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_lnkup_tour_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $tour_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	private static function schedule_reindex_for_organizer( int $user_id ): void {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_organizer_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		self::schedule_reindex_for_events( array_map( 'intval', $ids ) );
	}

	/**
	 * Re-sync the opportunities of these events (index stores the resolved attachment for srcset).
	 *
	 * @param int[] $event_ids Events.
	 */
	public static function schedule_reindex_for_events( array $event_ids ): void {
		if ( array() === $event_ids || ! class_exists( 'LNKup_Marketplace_Index' ) ) {
			return;
		}
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => 'any',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => '_lnkup_event_id', 'value' => array_map( 'strval', $event_ids ), 'compare' => 'IN' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
			)
		);
		foreach ( $opps as $opp_id ) {
			LNKup_Marketplace_Index::sync_opportunity( (int) $opp_id );
		}
	}

	/**
	 * Load post meta for many events (and their tours) in one query each, before rendering lists.
	 *
	 * @param int[] $event_ids Events.
	 */
	public static function prime( array $event_ids ): void {
		$event_ids = array_values( array_unique( array_filter( array_map( 'intval', $event_ids ) ) ) );
		if ( array() === $event_ids ) {
			return;
		}
		update_meta_cache( 'post', $event_ids );
		$related = array();
		$users   = array();
		foreach ( $event_ids as $id ) {
			$related[] = (int) get_post_meta( $id, '_lnkup_tour_id', true );
			$users[]   = (int) get_post_meta( $id, '_lnkup_organizer_user_id', true );
		}
		$related = array_filter( $related );
		if ( $related ) {
			update_meta_cache( 'post', $related );
		}
		$users = array_filter( $users );
		if ( $users ) {
			update_meta_cache( 'user', $users );
		}
	}

	/* ------------------------------------------------------------------ validation */

	/**
	 * Validate a local attachment as a displayable raster image.
	 *
	 * @param int $id Attachment ID.
	 * @return array{id:int,width:int,height:int,alt:string}|null
	 */
	public static function valid_attachment( int $id ): ?array {
		if ( $id < 1 ) {
			return null;
		}
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || 'private' === $post->post_status ) {
			return null;
		}
		$mime = (string) $post->post_mime_type;
		if ( 0 !== strpos( $mime, 'image/' ) || false !== strpos( $mime, 'svg' ) ) {
			return null;
		}
		$file = (string) get_attached_file( $id );
		if ( '' === $file ) {
			return null;
		}
		if ( ! file_exists( $file ) ) {
			// Media offloaded to a CDN (no local file) is still valid; anything else is broken.
			$url  = (string) wp_get_attachment_url( $id );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			if ( '' === $url || '' === $host || $host === $home ) {
				return null;
			}
		}
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();
		$ok   = (bool) apply_filters( 'lnkup_event_media_attachment_valid', true, $id );
		if ( ! $ok ) {
			return null;
		}
		return array(
			'id'     => $id,
			'width'  => (int) ( $meta['width'] ?? 0 ),
			'height' => (int) ( $meta['height'] ?? 0 ),
			'alt'    => trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
		);
	}

	/**
	 * Remote image URLs are accepted only from the existing sanitized import path (never fetched).
	 *
	 * @param string $url URL.
	 */
	private static function valid_remote_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		if ( class_exists( 'LNKup_External_Source_Eventbrite' ) ) {
			return LNKup_External_Source_Eventbrite::sanitize_image_url( $url );
		}
		return 0 === stripos( $url, 'https://' ) && preg_match( '#\.(jpe?g|png|webp|gif|avif)(\?|$)#i', $url ) ? esc_url_raw( $url ) : '';
	}

	/* ------------------------------------------------------------------ inputs */

	/**
	 * Every input that can change the result. Reads only cached meta.
	 *
	 * @return array<string,mixed>
	 */
	private static function inputs( int $event_id, int $opportunity_id ): array {
		$tour_id = (int) get_post_meta( $event_id, '_lnkup_tour_id', true );
		if ( $tour_id && ( 'lnkup_tour' !== get_post_type( $tour_id ) || 'publish' !== get_post_status( $tour_id ) ) ) {
			$tour_id = 0;
		}
		$brand_id  = $tour_id ? (int) get_post_meta( $tour_id, '_lnkup_brand_id', true ) : 0;
		$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		if ( ! $organizer ) {
			$organizer = (int) get_post_field( 'post_author', $event_id );
		}
		$gallery = function_exists( 'lnkup_parse_gallery_ids' ) ? lnkup_parse_gallery_ids( get_post_meta( $event_id, '_lnkup_gallery_ids', true ) ) : array();
		$terms   = get_the_terms( $event_id, 'lnkup_event_type' );
		$types   = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$types[] = array( 'slug' => (string) $term->slug, 'name' => (string) $term->name, 'parent' => (int) $term->parent );
			}
		}
		$external_id = (int) get_post_meta( $event_id, '_lnkup_external_event_id', true );
		return array(
			'mode'              => (string) get_post_meta( $event_id, self::META_MODE, true ),
			'override'          => (int) get_post_meta( $event_id, self::META_OVERRIDE, true ),
			'opportunity_cover' => $opportunity_id ? (int) get_post_meta( $opportunity_id, '_lnkup_featured_image_id', true ) : 0,
			'event_cover'       => (int) get_post_meta( $event_id, '_lnkup_featured_image_id', true ),
			'event_thumbnail'   => (int) get_post_meta( $event_id, '_thumbnail_id', true ),
			'flyer'             => (int) get_post_meta( $event_id, self::META_FLYER, true ),
			'gallery'           => array_slice( $gallery, 0, 6 ),
			'external_id'       => $external_id,
			'tour_id'           => $tour_id,
			'tour_cover'        => $tour_id ? (int) get_post_thumbnail_id( $tour_id ) : 0,
			'tour_title'        => $tour_id ? (string) get_the_title( $tour_id ) : '',
			'brand_logo'        => $brand_id && 'publish' === get_post_status( $brand_id ) ? (int) get_post_thumbnail_id( $brand_id ) : 0,
			'organizer_id'      => $organizer,
			'organizer_name'    => $organizer ? (string) get_user_meta( $organizer, '_lnkup_organizer_public_name', true ) : '',
			'organizer_cover'   => $organizer ? (int) get_user_meta( $organizer, '_lnkup_organizer_cover_id', true ) : 0,
			'organizer_logo'    => $organizer ? (int) get_user_meta( $organizer, '_lnkup_organizer_logo_id', true ) : 0,
			'title'             => function_exists( 'lnkup_plain_title' ) ? (string) lnkup_plain_title( get_post( $event_id ) ) : (string) get_the_title( $event_id ),
			'city'              => (string) get_post_meta( $event_id, '_lnkup_city', true ),
			'state'             => (string) get_post_meta( $event_id, '_lnkup_state', true ),
			'event_types'       => $types,
			'generation'        => (int) get_option( self::GENERATION_OPT, 0 ),
		);
	}

	private static function signature( array $inputs ): string {
		return md5( self::VERSION . '|' . ( defined( 'LNKUP_VERSION' ) ? LNKUP_VERSION : '' ) . '|' . wp_json_encode( $inputs ) );
	}

	/* ------------------------------------------------------------------ resolution */

	/**
	 * Resolved media for an event (optionally for one opportunity, whose own cover wins).
	 *
	 * @param int $event_id       Event post ID.
	 * @param int $opportunity_id Opportunity post ID.
	 * @return array<string,mixed>
	 */
	public static function resolve( int $event_id, int $opportunity_id = 0 ): array {
		if ( $event_id < 1 && $opportunity_id > 0 ) {
			$event_id = (int) get_post_meta( $opportunity_id, '_lnkup_event_id', true );
		}
		if ( $event_id < 1 || 'lnkup_event' !== get_post_type( $event_id ) ) {
			return self::generic( $opportunity_id );
		}
		$memo_key = $event_id . ':' . $opportunity_id;
		if ( isset( self::$memo[ $memo_key ] ) ) {
			return self::$memo[ $memo_key ];
		}
		$inputs = self::inputs( $event_id, $opportunity_id );
		$sig    = self::signature( $inputs );
		// Opportunity-level covers are rare; only event-level results are persisted.
		$cacheable = 0 === $inputs['opportunity_cover'];
		if ( $cacheable ) {
			$cached = json_decode( (string) get_post_meta( $event_id, self::META_RESOLVED, true ), true );
			if ( is_array( $cached ) && ( $cached['sig'] ?? '' ) === $sig && is_array( $cached['media'] ?? null ) ) {
				return self::$memo[ $memo_key ] = $cached['media'];
			}
		}
		$media = self::compute( $event_id, $inputs );
		if ( $cacheable ) {
			update_post_meta( $event_id, self::META_RESOLVED, wp_slash( (string) wp_json_encode( array( 'sig' => $sig, 'media' => $media, 'at' => time() ) ) ) );
		}
		return self::$memo[ $memo_key ] = $media;
	}

	/**
	 * Candidate order for a display mode (selected sources first, then the automatic chain).
	 *
	 * @param string $mode Mode.
	 * @return string[]
	 */
	public static function order_for_mode( string $mode ): array {
		$prefer = array(
			'cover'     => array( 'opportunity_cover', 'event_cover', 'event_thumbnail' ),
			'flyer'     => array( 'flyer' ),
			'tour'      => array( 'tour_cover', 'tour_brand' ),
			'organizer' => array( 'organizer_cover', 'organizer_logo' ),
			'category'  => array( 'category_art' ),
			'generated' => array( 'generated' ),
		);
		$first = $prefer[ $mode ] ?? array();
		return array_values( array_unique( array_merge( array( 'override' ), $first, self::SOURCES ) ) );
	}

	/**
	 * Walk the candidates until one validates. Always returns media (the generated cover cannot fail).
	 *
	 * @param int                 $event_id Event.
	 * @param array<string,mixed> $in       Inputs.
	 * @param array<int,array>    $trace    Filled with every candidate checked (for the audit CLI).
	 * @return array<string,mixed>
	 */
	public static function compute( int $event_id, array $in, array &$trace = array() ): array {
		$mode = in_array( $in['mode'], self::MODES, true ) ? $in['mode'] : 'auto';
		foreach ( self::order_for_mode( $mode ) as $source ) {
			$candidate = self::candidate( $source, $event_id, $in );
			$trace[]   = array( 'source' => $source, 'result' => null === $candidate ? 'unavailable' : 'selected' );
			if ( null !== $candidate ) {
				$candidate['mode'] = $mode;
				return $candidate;
			}
		}
		return self::generic( 0 );
	}

	/**
	 * Build media for one source, or null when that source is missing or invalid.
	 *
	 * @param string              $source   Source key.
	 * @param int                 $event_id Event.
	 * @param array<string,mixed> $in       Inputs.
	 * @return array<string,mixed>|null
	 */
	private static function candidate( string $source, int $event_id, array $in ): ?array {
		$title = (string) $in['title'];
		switch ( $source ) {
			case 'override':
			case 'opportunity_cover':
			case 'event_cover':
			case 'event_thumbnail':
			case 'flyer':
				return self::from_attachment( (int) $in[ $source ], $source, $title, $event_id );
			case 'gallery':
				foreach ( (array) $in['gallery'] as $id ) {
					$media = self::from_attachment( (int) $id, 'gallery', $title, $event_id );
					if ( null !== $media ) {
						return $media;
					}
				}
				return null;
			case 'external':
				return self::from_external( (int) $in['external_id'], $title, $event_id );
			case 'tour_cover':
				return self::from_attachment( (int) $in['tour_cover'], 'tour_cover', $in['tour_title'] ? $in['tour_title'] : $title, $event_id );
			case 'tour_brand':
				$media = self::from_attachment( (int) $in['brand_logo'], 'tour_brand', $in['tour_title'] ? $in['tour_title'] : $title, $event_id );
				return null === $media ? null : self::as_logo( $media, $event_id, $in );
			case 'organizer_cover':
				$media = self::from_attachment( (int) $in['organizer_cover'], 'organizer_cover', $title, $event_id );
				if ( null !== $media && 'flyer' === $media['presentation'] ) {
					return null;
				}
				return $media;
			case 'organizer_logo':
				$media = self::from_attachment( (int) $in['organizer_logo'], 'organizer_logo', $in['organizer_name'] ? $in['organizer_name'] : $title, $event_id );
				return null === $media ? null : self::as_logo( $media, $event_id, $in );
			case 'category_art':
				return class_exists( 'LNKup_Event_Media_Library' ) ? LNKup_Event_Media_Library::media_for( $event_id, (array) $in['event_types'], $title ) : null;
			case 'generated':
				return class_exists( 'LNKup_Event_Cover_SVG' ) ? LNKup_Event_Cover_SVG::media_for( $event_id, $in ) : null;
		}
		return null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function from_attachment( int $id, string $source, string $title, int $event_id ): ?array {
		$valid = self::valid_attachment( $id );
		if ( null === $valid ) {
			return null;
		}
		$large  = wp_get_attachment_image_src( $id, 'large' );
		$medium = wp_get_attachment_image_src( $id, 'medium_large' );
		$thumb  = wp_get_attachment_image_src( $id, 'medium' );
		$full   = wp_get_attachment_image_src( $id, 'full' );
		$url    = is_array( $large ) ? (string) $large[0] : ( is_array( $full ) ? (string) $full[0] : '' );
		if ( '' === $url ) {
			return null;
		}
		$width  = $valid['width'] ? $valid['width'] : ( is_array( $full ) ? (int) $full[1] : 0 );
		$height = $valid['height'] ? $valid['height'] : ( is_array( $full ) ? (int) $full[2] : 0 );
		$aspect = $width && $height ? round( $width / $height, 3 ) : 1.6;
		$flyer  = 'flyer' === $source || $aspect < self::PORTRAIT_BELOW;
		$alt    = '' !== $valid['alt'] ? $valid['alt'] : self::default_alt( $source, $title );
		return self::shape(
			array(
				'source'        => $source,
				'attachment_id' => $id,
				'url'           => $url,
				'thumb_url'     => is_array( $thumb ) ? (string) $thumb[0] : $url,
				'medium_url'    => is_array( $medium ) ? (string) $medium[0] : $url,
				'large_url'     => $url,
				'width'         => $width,
				'height'        => $height,
				'aspect_ratio'  => $aspect,
				'presentation'  => $flyer ? 'flyer' : 'photo',
				'fit'           => $flyer ? 'contain' : 'cover',
				'background'    => class_exists( 'LNKup_Event_Media_Library' ) ? LNKup_Event_Media_Library::palette_for_event( $event_id )['bg'] : '#111111',
				'alt'           => $alt,
				'og_url'        => is_array( $full ) ? (string) $full[0] : $url,
			)
		);
	}

	/**
	 * Logos are shown whole and centered on a light brand frame, never stretched or cropped.
	 *
	 * @param array<string,mixed> $media Media.
	 * @return array<string,mixed>
	 */
	private static function as_logo( array $media, int $event_id, array $in ): array {
		$media['presentation'] = 'logo';
		$media['fit']          = 'contain';
		$media['background']   = '#f5f4ef';
		$media['alt']          = trim( ( $in['organizer_name'] ? $in['organizer_name'] : $in['title'] ) . ' logo' );
		// Social previews of a bare logo look broken; use event-type art for og:image instead.
		if ( class_exists( 'LNKup_Event_Media_Library' ) ) {
			$art = LNKup_Event_Media_Library::media_for( $event_id, (array) $in['event_types'], (string) $in['title'] );
			if ( null !== $art ) {
				$media['og_url'] = $art['og_url'];
			}
		}
		return $media;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function from_external( int $external_id, string $title, int $event_id ): ?array {
		if ( $external_id < 1 || ! class_exists( 'LNKup_External_Events_Tables' ) ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$raw = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT image_url FROM ' . LNKup_External_Events_Tables::events_table() . ' WHERE id = %d', $external_id ) );
		$url = self::valid_remote_url( $raw );
		if ( '' === $url ) {
			return null;
		}
		return self::shape(
			array(
				'source'        => 'external',
				'attachment_id' => 0,
				'url'           => $url,
				'thumb_url'     => $url,
				'medium_url'    => $url,
				'large_url'     => $url,
				'width'         => 0,
				'height'        => 0,
				'aspect_ratio'  => 1.6,
				'presentation'  => 'photo',
				'fit'           => 'cover',
				'background'    => class_exists( 'LNKup_Event_Media_Library' ) ? LNKup_Event_Media_Library::palette_for_event( $event_id )['bg'] : '#111111',
				'alt'           => self::default_alt( 'external', $title ),
				'og_url'        => $url,
			)
		);
	}

	private static function default_alt( string $source, string $title ): string {
		$title = '' !== trim( $title ) ? trim( $title ) : __( 'Vendor event', 'lnkup-marketplace' );
		switch ( $source ) {
			case 'flyer':
				/* translators: %s: event title */
				return sprintf( __( 'Official flyer for %s', 'lnkup-marketplace' ), $title );
			case 'tour_cover':
				/* translators: %s: tour title */
				return sprintf( __( '%s tour artwork', 'lnkup-marketplace' ), $title );
			default:
				/* translators: %s: event title */
				return sprintf( __( '%s event artwork', 'lnkup-marketplace' ), $title );
		}
	}

	/**
	 * Fill every key so clients can rely on the shape.
	 *
	 * @param array<string,mixed> $media Partial media.
	 * @return array<string,mixed>
	 */
	public static function shape( array $media ): array {
		$defaults = array(
			'source'        => 'generated',
			'mode'          => 'auto',
			'attachment_id' => 0,
			'url'           => '',
			'thumb_url'     => '',
			'medium_url'    => '',
			'large_url'     => '',
			'width'         => 0,
			'height'        => 0,
			'aspect_ratio'  => 1.6,
			'presentation'  => 'generated',
			'fit'           => 'cover',
			'background'    => '#111111',
			'alt'           => '',
			'og_url'        => '',
			'uploaded'      => false,
			'version'       => self::VERSION,
		);
		$out             = array_merge( $defaults, array_intersect_key( $media, $defaults ) );
		$out['uploaded'] = in_array( $out['source'], self::UPLOADED_SOURCES, true );
		return $out;
	}

	/**
	 * Media for rows without an LNKup event (external listings, broken links).
	 *
	 * @param int                 $seed  Stable seed for the art variation.
	 * @param array<string,mixed> $hints title, event_type, categories.
	 * @return array<string,mixed>
	 */
	public static function generic( int $seed, array $hints = array() ): array {
		if ( class_exists( 'LNKup_Event_Media_Library' ) ) {
			$art = LNKup_Event_Media_Library::media_for_hints( $seed, $hints );
			if ( null !== $art ) {
				return $art;
			}
		}
		if ( class_exists( 'LNKup_Event_Cover_SVG' ) ) {
			$cover = LNKup_Event_Cover_SVG::media_for_hints( $seed, $hints );
			if ( null !== $cover ) {
				return $cover;
			}
		}
		return self::shape( array( 'source' => 'generated' ) );
	}

	/**
	 * REST/client representation (stable public subset; no internal paths).
	 *
	 * @param array<string,mixed> $media Resolved media.
	 * @return array<string,mixed>
	 */
	public static function to_rest( array $media ): array {
		return array(
			'source'        => (string) $media['source'],
			'display_mode'  => (string) $media['mode'],
			'presentation'  => (string) $media['presentation'],
			'url'           => (string) $media['url'],
			'thumbnail_url' => (string) $media['thumb_url'],
			'medium_url'    => (string) $media['medium_url'],
			'large_url'     => (string) $media['large_url'],
			'width'         => (int) $media['width'],
			'height'        => (int) $media['height'],
			'aspect_ratio'  => (float) $media['aspect_ratio'],
			'fit'           => (string) $media['fit'],
			'background'    => (string) $media['background'],
			'alt'           => (string) $media['alt'],
			'uploaded'      => (bool) $media['uploaded'],
			'version'       => (int) $media['version'],
		);
	}

	/**
	 * Locally hosted, always-loadable media used when the primary image fails in the browser:
	 * event-type art, else the generated cover.
	 *
	 * @param int $event_id Event.
	 * @return array<string,mixed>
	 */
	public static function fallback( int $event_id ): array {
		if ( $event_id < 1 || 'lnkup_event' !== get_post_type( $event_id ) ) {
			return self::generic( $event_id );
		}
		$in = self::inputs( $event_id, 0 );
		foreach ( array( 'category_art', 'generated' ) as $source ) {
			$media = self::candidate( $source, $event_id, $in );
			if ( null !== $media ) {
				return $media;
			}
		}
		return self::generic( $event_id );
	}

	/**
	 * Explain the decision for one event (audit CLI / Media Health).
	 *
	 * @param int $event_id Event.
	 * @return array{media:array,trace:array,inputs:array}
	 */
	public static function explain( int $event_id ): array {
		$inputs = self::inputs( $event_id, 0 );
		$trace  = array();
		$media  = self::compute( $event_id, $inputs, $trace );
		return array( 'media' => $media, 'trace' => $trace, 'inputs' => $inputs );
	}

	/**
	 * Change how an event's image is chosen. Never deletes or replaces uploaded artwork:
	 * the mode/override only change which existing asset is displayed. Audit-logged.
	 *
	 * @param int      $event_id    Event.
	 * @param string   $mode        One of MODES ('' leaves unchanged).
	 * @param int|null $override_id Attachment to force (0 clears, null leaves unchanged).
	 * @param string   $reason      Audit reason.
	 * @return true|WP_Error
	 */
	public static function set_display( int $event_id, string $mode, ?int $override_id = null, string $reason = '' ) {
		if ( 'lnkup_event' !== get_post_type( $event_id ) ) {
			return new WP_Error( 'lnkup_media_invalid_event', __( 'Event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( '' !== $mode && ! in_array( $mode, self::MODES, true ) ) {
			return new WP_Error( 'lnkup_media_invalid_mode', __( 'Unknown display mode.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( null !== $override_id && $override_id > 0 && null === self::valid_attachment( $override_id ) ) {
			return new WP_Error( 'lnkup_media_invalid_image', __( 'Choose a JPG, PNG, WebP or GIF image from the Media Library.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$prev = array(
			'mode'     => (string) get_post_meta( $event_id, self::META_MODE, true ),
			'override' => (int) get_post_meta( $event_id, self::META_OVERRIDE, true ),
			'source'   => (string) self::resolve( $event_id )['source'],
		);
		if ( '' !== $mode ) {
			if ( 'auto' === $mode ) {
				delete_post_meta( $event_id, self::META_MODE );
			} else {
				update_post_meta( $event_id, self::META_MODE, $mode );
			}
		}
		if ( null !== $override_id ) {
			if ( $override_id > 0 ) {
				update_post_meta( $event_id, self::META_OVERRIDE, $override_id );
			} else {
				delete_post_meta( $event_id, self::META_OVERRIDE );
			}
		}
		self::forget( array( $event_id ) );
		$new = self::resolve( $event_id );
		if ( class_exists( 'LNKup_Audit_Log' ) ) {
			LNKup_Audit_Log::log(
				'event_media_display_changed',
				'event',
				$event_id,
				array(
					'prev'   => $prev,
					'new'    => array( 'mode' => (string) get_post_meta( $event_id, self::META_MODE, true ), 'override' => (int) get_post_meta( $event_id, self::META_OVERRIDE, true ), 'source' => (string) $new['source'] ),
					'reason' => $reason,
				)
			);
		}
		self::schedule_reindex_for_events( array( $event_id ) );
		return true;
	}

	/**
	 * Health of an event's media: ok, broken (an uploaded image is set but unusable) or
	 * needs_artwork (only LNKup art / generated cover available).
	 *
	 * @param int                 $event_id Event.
	 * @param array<string,mixed> $media    Resolved media.
	 */
	public static function health( int $event_id, array $media ): string {
		$set = array_filter(
			array(
				(int) get_post_meta( $event_id, self::META_OVERRIDE, true ),
				(int) get_post_meta( $event_id, '_lnkup_featured_image_id', true ),
				(int) get_post_meta( $event_id, '_thumbnail_id', true ),
				(int) get_post_meta( $event_id, self::META_FLYER, true ),
			)
		);
		foreach ( $set as $id ) {
			if ( null === self::valid_attachment( (int) $id ) ) {
				return 'broken';
			}
		}
		return in_array( $media['source'], array( 'category_art', 'generated' ), true ) ? 'needs_artwork' : 'ok';
	}

	/**
	 * Drop the cached resolution for events (next read recomputes).
	 *
	 * @param int[] $event_ids Events.
	 */
	public static function forget( array $event_ids ): void {
		foreach ( $event_ids as $id ) {
			delete_post_meta( (int) $id, self::META_RESOLVED );
		}
		self::$memo = array();
	}
}
