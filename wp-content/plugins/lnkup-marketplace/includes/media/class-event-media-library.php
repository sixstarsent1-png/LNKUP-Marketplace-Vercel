<?php
/**
 * LNKup event-type artwork library.
 *
 * Original LNKup-owned editorial artwork (no text, logos or event claims) in
 * public/images/event-types/{group}-{n}.{webp,jpg}. Artwork is chosen from the event's
 * lnkup_event_type terms (never vendor categories), falling back to the parent term group.
 * The variation is stable per event so the same event always shows the same art.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Media_Library {

	const DIR        = 'public/images/event-types/';
	const VARIATIONS = 2;
	const WIDTH      = 1280;
	const HEIGHT     = 720;

	/** Art groups with a background color used behind contained flyers/logos. */
	const GROUPS = array(
		'festival'      => array( 'label' => 'Festival', 'bg' => '#2b1d14', 'accent' => '#f5a524' ),
		'food-festival' => array( 'label' => 'Food festival', 'bg' => '#1f1410', 'accent' => '#ff7a1a' ),
		'music'         => array( 'label' => 'Music', 'bg' => '#160f24', 'accent' => '#b46bff' ),
		'cultural'      => array( 'label' => 'Cultural', 'bg' => '#261012', 'accent' => '#e2b13c' ),
		'rodeo'         => array( 'label' => 'Rodeo & western', 'bg' => '#24170e', 'accent' => '#d98c3a' ),
		'holiday'       => array( 'label' => 'Holiday', 'bg' => '#0f1f17', 'accent' => '#d94a3a' ),
		'conference'    => array( 'label' => 'Conference & networking', 'bg' => '#0e1726', 'accent' => '#5aa9ff' ),
		'trade-show'    => array( 'label' => 'Trade show & expo', 'bg' => '#101a22', 'accent' => '#36c2b4' ),
		'market'        => array( 'label' => 'Market & pop-up', 'bg' => '#17201a', 'accent' => '#c6f24e' ),
		'block-party'   => array( 'label' => 'Community', 'bg' => '#251a10', 'accent' => '#ffb020' ),
		'gala'          => array( 'label' => 'Gala & fundraiser', 'bg' => '#140f16', 'accent' => '#d8b46a' ),
		'sports'        => array( 'label' => 'Sports & campus', 'bg' => '#0d1730', 'accent' => '#4f8bff' ),
		'art-wellness'  => array( 'label' => 'Art, beauty & wellness', 'bg' => '#221a17', 'accent' => '#e8a898' ),
	);

	/** lnkup_event_type term name (lowercase) => group. Parents map their whole branch. */
	const TERM_GROUPS = array(
		// Festivals.
		'festivals'         => 'festival',
		'festival'          => 'festival',
		'street fair'       => 'festival',
		'food festival'     => 'food-festival',
		'music event'       => 'music',
		'cultural event'    => 'cultural',
		'rodeo'             => 'rodeo',
		'holiday event'     => 'holiday',
		// Conferences.
		'conferences'       => 'conference',
		'conference'        => 'conference',
		'speaking event'    => 'conference',
		'networking event'  => 'conference',
		'workshop'          => 'conference',
		'trade show'        => 'trade-show',
		'expo'              => 'trade-show',
		// Markets.
		'markets & pop-ups' => 'market',
		'market'            => 'market',
		'vendor market'     => 'market',
		'pop-up'            => 'market',
		// Community.
		'community events'  => 'block-party',
		'block party'       => 'block-party',
		'community event'   => 'block-party',
		'family event'      => 'block-party',
		'launch event'      => 'block-party',
		'fundraiser'        => 'gala',
		// Campus.
		'campus events'     => 'sports',
		'college event'     => 'sports',
		'hbcu event'        => 'sports',
		'homecoming'        => 'sports',
		'greek event'       => 'block-party',
		'campus event'      => 'sports',
		// Nightlife.
		'nightlife & parties' => 'music',
		'nightlife event'   => 'music',
		'day party'         => 'music',
		// Corporate.
		'corporate & brand' => 'conference',
		'brand activation'  => 'trade-show',
		'corporate event'   => 'conference',
		'business event'    => 'conference',
		'private event'     => 'gala',
		// Sports & wellness.
		'sports & wellness' => 'sports',
		'sports event'      => 'sports',
		'wellness event'    => 'art-wellness',
		// Arts.
		'arts & culture'    => 'art-wellness',
		'fashion event'     => 'market',
		'beauty event'      => 'art-wellness',
		'art event'         => 'art-wellness',
	);

	/** Keywords in titles, used only when an event has no usable event type. */
	const TITLE_HINTS = array(
		'gala'       => 'gala',
		'rodeo'      => 'rodeo',
		'western'    => 'rodeo',
		'r&b'        => 'music',
		'music'      => 'music',
		'concert'    => 'music',
		'jazz'       => 'music',
		'food'       => 'food-festival',
		'taste'      => 'food-festival',
		'bbq'        => 'food-festival',
		'market'     => 'market',
		'pop-up'     => 'market',
		'pop up'     => 'market',
		'bazaar'     => 'market',
		'holiday'    => 'holiday',
		'christmas'  => 'holiday',
		'conference' => 'conference',
		'summit'     => 'conference',
		'networking' => 'conference',
		'expo'       => 'trade-show',
		'trade show' => 'trade-show',
		'homecoming' => 'sports',
		'alumni'     => 'sports',
		'tailgate'   => 'sports',
		'picnic'     => 'block-party',
		'block party' => 'block-party',
		'cookout'    => 'block-party',
		'fundraiser' => 'gala',
		'wellness'   => 'art-wellness',
		'beauty'     => 'art-wellness',
		'art'        => 'art-wellness',
		'cultural'   => 'cultural',
		'heritage'   => 'cultural',
		'festival'   => 'festival',
		'fest'       => 'festival',
	);

	/** Used for palettes when no group matches. */
	const NEUTRAL = array( 'bg' => '#111111', 'accent' => '#c6f24e' );

	/**
	 * Group for an event's terms (child term first, then parent), else title keywords, else ''.
	 * '' means "no relevant art"; the resolver then uses the generated SVG cover.
	 *
	 * @param array<int,array{slug:string,name:string,parent:int}> $types Event type terms.
	 * @param string                                               $title Event title.
	 */
	public static function group_for( array $types, string $title = '' ): string {
		foreach ( $types as $type ) {
			$name = strtolower( trim( (string) ( $type['name'] ?? '' ) ) );
			if ( isset( self::TERM_GROUPS[ $name ] ) ) {
				return self::TERM_GROUPS[ $name ];
			}
		}
		foreach ( $types as $type ) {
			$parent = (int) ( $type['parent'] ?? 0 );
			if ( $parent && function_exists( 'get_term' ) ) {
				$term = get_term( $parent, 'lnkup_event_type' );
				$name = $term && ! is_wp_error( $term ) ? strtolower( (string) $term->name ) : '';
				if ( isset( self::TERM_GROUPS[ $name ] ) ) {
					return self::TERM_GROUPS[ $name ];
				}
			}
		}
		$haystack = ' ' . strtolower( $title ) . ' ';
		foreach ( self::TITLE_HINTS as $needle => $group ) {
			if ( preg_match( '/(?<![a-z])' . preg_quote( $needle, '/' ) . '(?![a-z])/', $haystack ) ) {
				return $group;
			}
		}
		return '';
	}

	/**
	 * Stable variation (1-based) for an event.
	 *
	 * @param int $seed Event ID or other stable seed.
	 */
	public static function variation( int $seed ): int {
		return ( abs( crc32( 'lnkup-art-' . $seed ) ) % self::VARIATIONS ) + 1;
	}

	private static function base_url(): string {
		return defined( 'LNKUP_PLUGIN_URL' ) ? LNKUP_PLUGIN_URL . self::DIR : '/' . self::DIR;
	}

	/**
	 * @param string $group Group.
	 * @param int    $n     Variation.
	 * @param string $ext   webp|jpg.
	 */
	public static function file_url( string $group, int $n, string $ext ): string {
		$ver = defined( 'LNKUP_VERSION' ) ? '?ver=' . rawurlencode( LNKUP_VERSION ) : '';
		return self::base_url() . $group . '-' . $n . '.' . $ext . $ver;
	}

	/**
	 * Background/accent colors for contained flyers and logos of this event.
	 *
	 * @param int $event_id Event.
	 * @return array{bg:string,accent:string}
	 */
	public static function palette_for_event( int $event_id ): array {
		$terms = get_the_terms( $event_id, 'lnkup_event_type' );
		$types = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$types[] = array( 'slug' => (string) $term->slug, 'name' => (string) $term->name, 'parent' => (int) $term->parent );
			}
		}
		$key = self::group_for( $types, (string) get_the_title( $event_id ) );
		return isset( self::GROUPS[ $key ] ) ? array( 'bg' => self::GROUPS[ $key ]['bg'], 'accent' => self::GROUPS[ $key ]['accent'] ) : self::NEUTRAL;
	}

	/**
	 * Artwork media for an event.
	 *
	 * @param int                                                  $event_id Event.
	 * @param array<int,array{slug:string,name:string,parent:int}> $types    Event type terms.
	 * @param string                                               $title    Event title.
	 * @return array<string,mixed>|null
	 */
	public static function media_for( int $event_id, array $types, string $title ): ?array {
		return self::build( self::group_for( $types, $title ), self::variation( $event_id ), $title );
	}

	/**
	 * Artwork for rows without an event post (external listings).
	 *
	 * @param int                 $seed  Seed.
	 * @param array<string,mixed> $hints title, event_type.
	 * @return array<string,mixed>|null
	 */
	public static function media_for_hints( int $seed, array $hints ): ?array {
		$types = array();
		if ( ! empty( $hints['event_type'] ) ) {
			$types[] = array( 'slug' => '', 'name' => (string) $hints['event_type'], 'parent' => 0 );
		}
		return self::build( self::group_for( $types, (string) ( $hints['title'] ?? '' ) ), self::variation( $seed ), (string) ( $hints['title'] ?? '' ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	/**
	 * Palette for a group key ('' = neutral). Used by the SVG cover.
	 *
	 * @param string $group Group.
	 * @return array{bg:string,accent:string,label:string}
	 */
	public static function palette( string $group ): array {
		if ( isset( self::GROUPS[ $group ] ) ) {
			return self::GROUPS[ $group ];
		}
		return array_merge( self::NEUTRAL, array( 'label' => __( 'Vendor event', 'lnkup-marketplace' ) ) );
	}

	private static function build( string $group, int $n, string $title ): ?array {
		if ( ! isset( self::GROUPS[ $group ] ) ) {
			return null;
		}
		if ( defined( 'LNKUP_PLUGIN_DIR' ) && ! file_exists( LNKUP_PLUGIN_DIR . self::DIR . $group . '-' . $n . '.webp' ) ) {
			return null;
		}
		$webp  = self::file_url( $group, $n, 'webp' );
		$jpg   = self::file_url( $group, $n, 'jpg' );
		$label = self::GROUPS[ $group ]['label'];
		$title = trim( $title );
		return LNKup_Event_Media::shape(
			array(
				'source'       => 'category_art',
				'url'          => $webp,
				'thumb_url'    => $webp,
				'medium_url'   => $webp,
				'large_url'    => $webp,
				'width'        => self::WIDTH,
				'height'       => self::HEIGHT,
				'aspect_ratio' => round( self::WIDTH / self::HEIGHT, 3 ),
				'presentation' => 'art',
				'fit'          => 'cover',
				'background'   => self::GROUPS[ $group ]['bg'],
				/* translators: 1: event type label, 2: event title */
				'alt'          => '' !== $title ? sprintf( __( '%1$s artwork for %2$s', 'lnkup-marketplace' ), $label, $title ) : sprintf( __( '%s event artwork', 'lnkup-marketplace' ), $label ),
				'og_url'       => $jpg,
			)
		);
	}

	/**
	 * Every group and its variations (for the Media Health admin).
	 *
	 * @return array<string,array{label:string,urls:string[]}>
	 */
	public static function catalog(): array {
		$out = array();
		foreach ( self::GROUPS as $group => $meta ) {
			$urls = array();
			for ( $n = 1; $n <= self::VARIATIONS; $n++ ) {
				$urls[] = self::file_url( $group, $n, 'webp' );
			}
			$out[ $group ] = array( 'label' => $meta['label'], 'urls' => $urls );
		}
		return $out;
	}
}
