<?php
/**
 * Generated branded event cover (final fallback; needs no uploads, AI or external service).
 *
 * A self-contained SVG (no external references, all text escaped) built from the event title,
 * organizer, place and event-type palette. Written once per content hash to
 * uploads/lnkup-covers/ so web, mobile and REST share one cacheable URL.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Cover_SVG {

	const DIR       = 'lnkup-covers';
	const WIDTH     = 1280;
	const HEIGHT    = 800;
	const TEMPLATES = 3;

	/**
	 * Split a title into at most $max_lines lines of about $per_line characters; ellipsize overflow.
	 *
	 * @param string $text      Text.
	 * @param int    $per_line  Characters per line.
	 * @param int    $max_lines Max lines.
	 * @return string[]
	 */
	public static function wrap( string $text, int $per_line, int $max_lines ): array {
		$words = preg_split( '/\s+/u', trim( $text ) ) ?: array();
		$lines = array();
		$line  = '';
		foreach ( $words as $word ) {
			if ( function_exists( 'mb_strlen' ) ? mb_strlen( $word ) > $per_line : strlen( $word ) > $per_line ) {
				$word = function_exists( 'mb_substr' ) ? mb_substr( $word, 0, $per_line - 1 ) . '…' : substr( $word, 0, $per_line - 1 ) . '…';
			}
			$candidate = '' === $line ? $word : $line . ' ' . $word;
			$len       = function_exists( 'mb_strlen' ) ? mb_strlen( $candidate ) : strlen( $candidate );
			if ( $len <= $per_line ) {
				$line = $candidate;
				continue;
			}
			if ( '' !== $line ) {
				$lines[] = $line;
			}
			$line = $word;
			if ( count( $lines ) === $max_lines ) {
				break;
			}
		}
		if ( '' !== $line && count( $lines ) < $max_lines ) {
			$lines[] = $line;
		}
		$consumed = implode( ' ', $lines );
		if ( count( $lines ) === $max_lines && ( function_exists( 'mb_strlen' ) ? mb_strlen( $consumed ) : strlen( $consumed ) ) < ( function_exists( 'mb_strlen' ) ? mb_strlen( trim( $text ) ) : strlen( trim( $text ) ) ) ) {
			$last                       = $lines[ $max_lines - 1 ];
			$cut                        = function_exists( 'mb_substr' ) ? mb_substr( $last, 0, max( 1, $per_line - 1 ) ) : substr( $last, 0, max( 1, $per_line - 1 ) );
			$lines[ $max_lines - 1 ]    = rtrim( $cut, " ,.-:" ) . '…';
		}
		return $lines;
	}

	private static function esc( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Build the SVG markup.
	 *
	 * @param array{title:string,organizer:string,place:string,label:string,bg:string,accent:string,template:int} $d Data.
	 */
	public static function svg( array $d ): string {
		$w      = self::WIDTH;
		$h      = self::HEIGHT;
		$bg     = preg_match( '/^#[0-9a-f]{6}$/i', $d['bg'] ) ? $d['bg'] : '#111111';
		$accent = preg_match( '/^#[0-9a-f]{6}$/i', $d['accent'] ) ? $d['accent'] : '#c6f24e';
		$title  = '' !== trim( $d['title'] ) ? $d['title'] : 'Vendor opportunity';
		$long   = ( function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title ) ) > 34;
		$size   = $long ? 64 : 84;
		$lines  = self::wrap( $title, $long ? 26 : 20, 3 );
		$tpl    = max( 1, min( self::TEMPLATES, (int) $d['template'] ) );

		$motif = '';
		if ( 1 === $tpl ) {
			$motif = '<circle cx="1080" cy="140" r="260" fill="' . $accent . '" opacity="0.18"/><circle cx="1180" cy="660" r="180" fill="' . $accent . '" opacity="0.10"/>';
		} elseif ( 2 === $tpl ) {
			for ( $i = 0; $i < 9; $i++ ) {
				$motif .= '<rect x="' . ( 760 + $i * 60 ) . '" y="-40" width="18" height="900" transform="rotate(18 ' . ( 760 + $i * 60 ) . ' 400)" fill="' . $accent . '" opacity="' . ( 0.05 + $i * 0.015 ) . '"/>';
			}
		} else {
			for ( $r = 0; $r < 6; $r++ ) {
				for ( $c = 0; $c < 6; $c++ ) {
					$motif .= '<circle cx="' . ( 900 + $c * 64 ) . '" cy="' . ( 90 + $r * 64 ) . '" r="7" fill="' . $accent . '" opacity="0.28"/>';
				}
			}
		}

		$y0    = 400 - ( count( $lines ) - 1 ) * ( $size * 0.6 );
		$tspan = '';
		foreach ( $lines as $i => $line ) {
			$tspan .= '<tspan x="88" y="' . (int) round( $y0 + $i * $size * 1.12 ) . '">' . self::esc( $line ) . '</tspan>';
		}
		$label = self::esc( strtoupper( (string) $d['label'] ) );
		$meta  = self::esc( trim( implode( '  ·  ', array_filter( array( (string) $d['organizer'], (string) $d['place'] ) ) ) ) );
		$font  = "font-family=\"Inter, 'Helvetica Neue', Arial, sans-serif\"";

		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">'
			. '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $bg . '"/><stop offset="1" stop-color="#050505"/></linearGradient>'
			. '<radialGradient id="r" cx="0.85" cy="0.1" r="0.9"><stop offset="0" stop-color="' . $accent . '" stop-opacity="0.35"/><stop offset="1" stop-color="' . $accent . '" stop-opacity="0"/></radialGradient></defs>'
			. '<rect width="100%" height="100%" fill="url(#g)"/><rect width="100%" height="100%" fill="url(#r)"/>' . $motif
			. '<rect x="88" y="150" width="' . ( 28 + 13 * strlen( (string) $d['label'] ) ) . '" height="44" rx="22" fill="' . $accent . '"/>'
			. '<text x="102" y="180" ' . $font . ' font-size="20" font-weight="700" letter-spacing="2" fill="#0b0b0b">' . $label . '</text>'
			. '<text ' . $font . ' font-size="' . $size . '" font-weight="800" fill="#ffffff" letter-spacing="-1">' . $tspan . '</text>'
			. ( '' !== $meta ? '<text x="88" y="690" ' . $font . ' font-size="28" font-weight="500" fill="#ffffff" fill-opacity="0.78">' . $meta . '</text>' : '' )
			. '<text x="1192" y="740" text-anchor="end" ' . $font . ' font-size="22" font-weight="800" fill="' . $accent . '" letter-spacing="3">LNKUP</text>'
			. '</svg>';
	}

	/**
	 * Media for an event using the resolver inputs. Writes the file on first use per content hash.
	 *
	 * @param int                 $event_id Event.
	 * @param array<string,mixed> $in       Resolver inputs.
	 * @return array<string,mixed>|null
	 */
	public static function media_for( int $event_id, array $in ): ?array {
		$group   = class_exists( 'LNKup_Event_Media_Library' ) ? LNKup_Event_Media_Library::group_for( (array) $in['event_types'], (string) $in['title'] ) : '';
		$palette = class_exists( 'LNKup_Event_Media_Library' ) ? LNKup_Event_Media_Library::palette( $group ) : array( 'bg' => '#111111', 'accent' => '#c6f24e', 'label' => 'Vendor event' );
		$place   = function_exists( 'lnkup_display_place' ) && ( $in['city'] || $in['state'] ) ? (string) lnkup_display_place( (string) $in['city'], (string) $in['state'] ) : trim( $in['city'] . ( $in['city'] && $in['state'] ? ', ' : '' ) . $in['state'] );
		$data    = array(
			'title'     => (string) $in['title'],
			'organizer' => (string) $in['organizer_name'],
			'place'     => $place,
			'label'     => (string) $palette['label'],
			'bg'        => (string) $palette['bg'],
			'accent'    => (string) $palette['accent'],
			'template'  => ( abs( crc32( 'lnkup-cover-' . $event_id ) ) % self::TEMPLATES ) + 1,
		);
		return self::media_from_data( (string) $event_id, $data, (string) $palette['bg'], (string) $in['title'] );
	}

	/**
	 * Cover for rows without an event post (external listings).
	 *
	 * @param int                 $seed  Stable seed.
	 * @param array<string,mixed> $hints title, event_type, city, state.
	 * @return array<string,mixed>|null
	 */
	public static function media_for_hints( int $seed, array $hints ): ?array {
		$types   = ! empty( $hints['event_type'] ) ? array( array( 'slug' => '', 'name' => (string) $hints['event_type'], 'parent' => 0 ) ) : array();
		$title   = (string) ( $hints['title'] ?? '' );
		$group   = class_exists( 'LNKup_Event_Media_Library' ) ? LNKup_Event_Media_Library::group_for( $types, $title ) : '';
		$palette = class_exists( 'LNKup_Event_Media_Library' ) ? LNKup_Event_Media_Library::palette( $group ) : array( 'bg' => '#111111', 'accent' => '#c6f24e', 'label' => 'Vendor event' );
		$place   = trim( (string) ( $hints['city'] ?? '' ) . ( ! empty( $hints['city'] ) && ! empty( $hints['state'] ) ? ', ' : '' ) . (string) ( $hints['state'] ?? '' ) );
		$data    = array(
			'title'     => $title,
			'organizer' => (string) ( $hints['organizer'] ?? '' ),
			'place'     => $place,
			'label'     => (string) $palette['label'],
			'bg'        => (string) $palette['bg'],
			'accent'    => (string) $palette['accent'],
			'template'  => ( abs( crc32( 'lnkup-cover-g' . $seed ) ) % self::TEMPLATES ) + 1,
		);
		return self::media_from_data( 'g' . $seed, $data, (string) $palette['bg'], $title );
	}

	/**
	 * @param string              $key   File key.
	 * @param array<string,mixed> $data  SVG data.
	 * @param string              $bg    Background.
	 * @param string              $title Title.
	 * @return array<string,mixed>|null
	 */
	private static function media_from_data( string $key, array $data, string $bg, string $title ): ?array {
		$url = self::write( $key, $data );
		if ( '' === $url ) {
			return null;
		}
		return LNKup_Event_Media::shape(
			array(
				'source'       => 'generated',
				'url'          => $url,
				'thumb_url'    => $url,
				'medium_url'   => $url,
				'large_url'    => $url,
				'width'        => self::WIDTH,
				'height'       => self::HEIGHT,
				'aspect_ratio' => round( self::WIDTH / self::HEIGHT, 3 ),
				'presentation' => 'generated',
				'fit'          => 'cover',
				'background'   => $bg,
				/* translators: %s: event title */
				'alt'          => sprintf( __( 'Cover for %s', 'lnkup-marketplace' ), '' !== trim( $title ) ? $title : __( 'vendor event', 'lnkup-marketplace' ) ),
				'og_url'       => function_exists( 'lnkup_seo_default_og_image' ) ? (string) lnkup_seo_default_og_image() : '',
			)
		);
	}

	/**
	 * Write (once) and return the public URL, or '' when uploads are not writable.
	 *
	 * @param string              $event_id File key (event ID or "g{seed}").
	 * @param array<string,mixed> $data     SVG data.
	 */
	private static function write( string $event_id, array $data ): string {
		$event_id = (string) preg_replace( '/[^a-z0-9]/i', '', $event_id );
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		$hash = substr( md5( (string) wp_json_encode( $data ) ), 0, 10 );
		$dir  = trailingslashit( $uploads['basedir'] ) . self::DIR;
		$file = $event_id . '-' . $hash . '.svg';
		$path = $dir . '/' . $file;
		if ( ! file_exists( $path ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return '';
			}
			foreach ( (array) glob( $dir . '/' . $event_id . '-*.svg' ) as $old ) {
				if ( is_string( $old ) && is_file( $old ) ) {
					wp_delete_file( $old );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === file_put_contents( $path, self::svg( $data ) ) ) {
				return '';
			}
		}
		return trailingslashit( $uploads['baseurl'] ) . self::DIR . '/' . $file;
	}
}
