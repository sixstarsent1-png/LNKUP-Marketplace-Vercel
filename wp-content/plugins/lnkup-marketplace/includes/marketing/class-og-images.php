<?php
/**
 * Social preview images (1200×630 PNG) for events and markets (flag seo_index_policy).
 *
 * Built with GD from the event's resolved artwork (LNKup_Event_Media) plus public text only: event
 * title, date and city, or market name and live open-event count. Files are cached in
 * uploads/lnkup-og/ under a signature of every input, so edits produce a new URL (no stale previews)
 * and the previous file for the same object is removed. Generated art never adds venues,
 * performers, attendance or prices.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_OG_Images {

	const VERSION = '1';
	const W       = 1200;
	const H       = 630;
	const DIR     = 'lnkup-og';

	public static function init(): void {
		add_filter( 'lnkup_seo_og_image_pre', array( __CLASS__, 'for_current_request' ) );
	}

	public static function available(): bool {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagettftext' ) && is_readable( self::font( 'bold' ) );
	}

	private static function font( string $weight ): string {
		return LNKUP_PLUGIN_DIR . 'public/fonts/' . ( 'bold' === $weight ? 'Poppins-Bold.ttf' : 'Poppins-Medium.ttf' );
	}

	/**
	 * @param string $image Current image.
	 */
	public static function for_current_request( $image ) {
		if ( '' !== (string) $image || ! class_exists( 'LNKup_Index_Policy' ) || ! LNKup_Index_Policy::active() || ! self::available() ) {
			return $image;
		}
		if ( is_singular( 'lnkup_event' ) ) {
			return self::event_url( (int) get_queried_object_id() );
		}
		if ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_market_request() ) {
			$market = LNKup_Market_Pages::get_market_by_slug( LNKup_Market_Pages::current_slug() );
			$sub    = class_exists( 'LNKup_Market_Discovery' ) ? LNKup_Market_Discovery::sub() : null;
			if ( $market && $sub ) {
				$count = count( (array) $sub['cards'] );
				$bg    = get_template_directory() . '/assets/images/marketing/hero-market-1280w.webp';
				/* translators: %d: events */
				$meta = $count > 0 ? sprintf( _n( '%d event booking vendors', '%d events booking vendors', $count, 'lnkup-marketplace' ), $count ) : __( 'Get alerts when events open', 'lnkup-marketplace' );
				return self::render( 'market-' . sanitize_title( (string) $market['slug'] ) . '-' . sanitize_title( (string) $sub['slug'] ), (string) $sub['title'], $meta, 'LNKup', is_readable( $bg ) ? $bg : '', '#0b0b0c' );
			}
			return $market ? self::market_url( $market ) : $image;
		}
		return $image;
	}

	/**
	 * @return array{dir:string,url:string}|null
	 */
	private static function storage(): ?array {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . self::DIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		return array( 'dir' => $dir, 'url' => trailingslashit( $uploads['baseurl'] ) . self::DIR );
	}

	public static function event_url( int $event_id ): string {
		$post = get_post( $event_id );
		if ( ! $post || 'lnkup_event' !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}
		$title = function_exists( 'lnkup_plain_title' ) ? lnkup_plain_title( $post ) : get_the_title( $post );
		$date  = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$city  = (string) get_post_meta( $event_id, '_lnkup_city', true );
		$state = (string) get_post_meta( $event_id, '_lnkup_state', true );
		$media = class_exists( 'LNKup_Event_Media' ) ? LNKup_Event_Media::resolve( $event_id ) : array();
		$bg    = self::local_path( (int) ( $media['attachment_id'] ?? 0 ), (string) ( $media['large_url'] ?? $media['url'] ?? '' ) );
		$meta  = array_filter(
			array(
				preg_match( '/^\d{4}-\d{2}-\d{2}/', $date ) ? date_i18n( 'D, M j, Y', strtotime( $date ) ) : '',
				trim( $city . ( $state ? ', ' . $state : '' ) ),
			)
		);
		$status = (string) get_post_meta( $event_id, '_lnkup_event_status', true );
		$chip   = in_array( $status, array( 'cancelled', 'canceled' ), true ) ? __( 'Cancelled', 'lnkup-marketplace' ) : ( 'postponed' === $status ? __( 'Postponed', 'lnkup-marketplace' ) : __( 'Vendor applications on LNKup', 'lnkup-marketplace' ) );
		return self::render( 'event-' . $event_id, $title, implode( '  ·  ', $meta ), $chip, $bg, (string) ( $media['background'] ?? '#111111' ) );
	}

	/**
	 * @param array<string,mixed> $market Market.
	 */
	public static function market_url( array $market ): string {
		$open = class_exists( 'LNKup_Index_Policy' ) ? LNKup_Index_Policy::open_events_in_city( (string) $market['city'], (string) $market['state'] ) : 0;
		/* translators: %s: market */
		$title = sprintf( __( 'Vendor opportunities in %s', 'lnkup-marketplace' ), (string) $market['display_name'] );
		$meta  = $open > 0
			/* translators: %d: open events */
			? sprintf( _n( '%d event booking vendors', '%d events booking vendors', $open, 'lnkup-marketplace' ), $open )
			: __( 'Get alerts when events open', 'lnkup-marketplace' );
		$bg    = get_template_directory() . '/assets/images/marketing/hero-market-1280w.webp';
		return self::render( 'market-' . sanitize_title( (string) $market['slug'] ), $title, $meta, 'LNKup', is_readable( $bg ) ? $bg : '', '#0b0b0c' );
	}

	/**
	 * Local file for an attachment or a same-site asset URL (remote images are never fetched).
	 */
	private static function local_path( int $attachment_id, string $url ): string {
		if ( $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			if ( $file && is_readable( $file ) ) {
				return $file;
			}
		}
		$url = strtok( $url, '?' );
		if ( ! $url ) {
			return '';
		}
		$map = array(
			content_url() => WP_CONTENT_DIR,
			site_url()    => ABSPATH,
		);
		foreach ( $map as $base => $dir ) {
			if ( 0 === strpos( $url, $base ) ) {
				$path = wp_normalize_path( untrailingslashit( $dir ) . substr( $url, strlen( $base ) ) );
				if ( is_readable( $path ) && false === strpos( $path, '..' ) ) {
					return $path;
				}
			}
		}
		return '';
	}

	/**
	 * Render (once per signature) and return the public URL.
	 */
	private static function render( string $key, string $title, string $meta, string $chip, string $bg_file, string $bg_hex ): string {
		if ( ! self::available() ) {
			return '';
		}
		$store = self::storage();
		if ( ! $store ) {
			return '';
		}
		$sig  = substr( md5( self::VERSION . '|' . $title . '|' . $meta . '|' . $chip . '|' . $bg_file . '|' . ( $bg_file && is_readable( $bg_file ) ? (string) filemtime( $bg_file ) : '' ) ), 0, 12 );
		$name = $key . '-' . $sig . '.png';
		$path = $store['dir'] . '/' . $name;
		if ( ! file_exists( $path ) ) {
			try {
				if ( ! self::draw( $path, $title, $meta, $chip, $bg_file, $bg_hex ) ) {
					return '';
				}
			} catch ( Throwable $e ) {
				return '';
			}
			foreach ( (array) glob( $store['dir'] . '/' . $key . '-*.png' ) as $old ) {
				if ( $old !== $path && is_string( $old ) ) {
					wp_delete_file( $old );
				}
			}
		}
		return $store['url'] . '/' . $name;
	}

	private static function draw( string $path, string $title, string $meta, string $chip, string $bg_file, string $bg_hex ): bool {
		$im = imagecreatetruecolor( self::W, self::H );
		if ( ! $im ) {
			return false;
		}
		list( $r, $g, $b ) = self::rgb( $bg_hex );
		imagefill( $im, 0, 0, imagecolorallocate( $im, $r, $g, $b ) );

		$src = $bg_file ? self::load( $bg_file ) : null;
		if ( $src ) {
			$sw    = imagesx( $src );
			$sh    = imagesy( $src );
			$scale = max( self::W / $sw, self::H / $sh );
			$cw    = (int) round( self::W / $scale );
			$ch    = (int) round( self::H / $scale );
			imagecopyresampled( $im, $src, 0, 0, (int) max( 0, ( $sw - $cw ) / 2 ), (int) max( 0, ( $sh - $ch ) / 2 ), self::W, self::H, $cw, $ch );
			imagedestroy( $src );
		}
		for ( $y = 0; $y < self::H; $y++ ) {
			$alpha = (int) round( 127 - min( 127, 40 + ( $y / self::H ) * 80 ) );
			imagefilledrectangle( $im, 0, $y, self::W, $y, imagecolorallocatealpha( $im, 11, 11, 12, max( 0, $alpha ) ) );
		}

		$white = imagecolorallocate( $im, 255, 255, 255 );
		$muted = imagecolorallocate( $im, 220, 222, 226 );
		$lime  = imagecolorallocate( $im, 200, 245, 66 );
		$ink   = imagecolorallocate( $im, 26, 36, 0 );
		$bold  = self::font( 'bold' );
		$med   = self::font( 'medium' );

		$box = imagettfbbox( 22, 0, $bold, $chip );
		$cw  = abs( $box[2] - $box[0] ) + 44;
		self::rounded( $im, 64, 64, 64 + $cw, 64 + 52, 26, $lime );
		imagettftext( $im, 22, 0, 86, 100, $ink, $bold, $chip );

		$lines = self::wrap( $title, $bold, 58, self::W - 128, 3 );
		$y     = self::H - 96 - ( count( $lines ) - 1 ) * 72 - ( '' !== $meta ? 54 : 0 );
		foreach ( $lines as $line ) {
			imagettftext( $im, 58, 0, 64, $y, $white, $bold, $line );
			$y += 72;
		}
		if ( '' !== $meta ) {
			imagettftext( $im, 28, 0, 64, $y - 10, $muted, $med, $meta );
		}
		imagettftext( $im, 26, 0, self::W - 190, 100, $white, $bold, 'LNKup' );

		$ok = imagepng( $im, $path, 6 );
		imagedestroy( $im );
		return (bool) $ok;
	}

	/**
	 * @return GdImage|resource|null
	 */
	private static function load( string $file ) {
		$data = @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $data ) {
			return null;
		}
		$img = @imagecreatefromstring( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $img ? $img : null;
	}

	/**
	 * @return string[]
	 */
	private static function wrap( string $text, string $font, int $size, int $max_width, int $max_lines ): array {
		$words = preg_split( '/\s+/', trim( $text ) ) ?: array();
		$lines = array();
		$line  = '';
		foreach ( $words as $word ) {
			$try = '' === $line ? $word : $line . ' ' . $word;
			$box = imagettfbbox( $size, 0, $font, $try );
			if ( abs( $box[2] - $box[0] ) > $max_width && '' !== $line ) {
				$lines[] = $line;
				$line    = $word;
				if ( count( $lines ) === $max_lines ) {
					break;
				}
			} else {
				$line = $try;
			}
		}
		if ( count( $lines ) < $max_lines && '' !== $line ) {
			$lines[] = $line;
		} elseif ( count( $lines ) === $max_lines ) {
			$lines[ $max_lines - 1 ] = rtrim( $lines[ $max_lines - 1 ], ' ,.' ) . '…';
		}
		return $lines;
	}

	/**
	 * @param GdImage|resource $im Image.
	 */
	private static function rounded( $im, int $x1, int $y1, int $x2, int $y2, int $r, int $color ): void {
		imagefilledrectangle( $im, $x1 + $r, $y1, $x2 - $r, $y2, $color );
		imagefilledrectangle( $im, $x1, $y1 + $r, $x2, $y2 - $r, $color );
		foreach ( array( array( $x1 + $r, $y1 + $r ), array( $x2 - $r, $y1 + $r ), array( $x1 + $r, $y2 - $r ), array( $x2 - $r, $y2 - $r ) ) as $c ) {
			imagefilledellipse( $im, $c[0], $c[1], $r * 2, $r * 2, $color );
		}
	}

	/**
	 * @return int[]
	 */
	private static function rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return array( 17, 17, 17 );
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}
}
