<?php
/**
 * Market sub-pages (flag market_category_pages):
 * - /markets/{city}/{category}/  live events in one vendor category
 * - /markets/{city}/this-week/   events in the next 14 days (shareable local digest)
 *
 * Unknown categories 404. Pages render for any known category, but are indexable and listed in
 * sitemaps only when LNKup_Market_Registry::category_eligible() (or the digest threshold) passes,
 * so the city × category matrix is never mass-generated.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Market_Discovery {

	const SUB_VAR      = 'lnkup_market_sub';
	const DIGEST_SLUG  = 'this-week';
	const DIGEST_DAYS  = 14;
	const DIGEST_MIN   = 3;

	/** @var array<string,mixed>|null|false */
	private static $sub = false;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'rewrites' ), 21 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'prepare' ), 3 );
		add_filter( 'lnkup_seo_context_copy_pre', array( __CLASS__, 'seo_copy' ) );
		add_filter( 'lnkup_seo_market_canonical', array( __CLASS__, 'canonical' ) );
		add_filter( 'lnkup_sitemap_subtypes', array( __CLASS__, 'sitemap_subtypes' ) );
		add_filter( 'lnkup_sitemap_entries', array( __CLASS__, 'sitemap_entries' ), 10, 2 );
	}

	/**
	 * SEO title and description for sub-pages, through LNKup_SEO (Rank Math or fallback).
	 *
	 * @param array<string,string>|null $copy Pre-filtered copy.
	 * @return array<string,string>|null
	 */
	public static function seo_copy( $copy ) {
		$sub = self::sub();
		if ( ! $sub ) {
			return $copy;
		}
		return array(
			'title'       => wp_strip_all_tags( (string) $sub['title'] ) . ' | LNKup',
			'description' => self::description_text(),
		);
	}

	public static function active(): bool {
		return class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'market_category_pages' );
	}

	public static function rewrites(): void {
		if ( ! self::active() || ! class_exists( 'LNKup_Market_Pages' ) || ! LNKup_Market_Pages::is_enabled() ) {
			return;
		}
		add_rewrite_rule( '^markets/([^/]+)/([a-z0-9-]{2,60})/?$', 'index.php?' . LNKup_Market_Pages::QUERY_VAR . '=$matches[1]&' . self::SUB_VAR . '=$matches[2]', 'top' );
	}

	/**
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::SUB_VAR;
		return $vars;
	}

	/**
	 * Category label for a slug from the canonical vendor category list.
	 */
	public static function category_label( string $slug ): string {
		if ( ! function_exists( 'lnkup_canonical_vendor_categories' ) ) {
			return '';
		}
		foreach ( lnkup_canonical_vendor_categories() as $label ) {
			if ( sanitize_title( $label ) === $slug ) {
				return (string) $label;
			}
		}
		return '';
	}

	/**
	 * Resolved sub-page for the current request, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function sub(): ?array {
		if ( false !== self::$sub ) {
			return self::$sub;
		}
		self::$sub = null;
		$raw = sanitize_title( (string) get_query_var( self::SUB_VAR ) );
		if ( '' === $raw || ! self::active() || ! class_exists( 'LNKup_Market_Pages' ) || ! LNKup_Market_Pages::is_market_request() ) {
			return null;
		}
		$market = LNKup_Market_Pages::get_market_by_slug( LNKup_Market_Pages::current_slug() );
		if ( ! $market ) {
			return null;
		}
		self::$sub = self::build( $market, $raw );
		return self::$sub;
	}

	/**
	 * @param array<string,mixed> $market Market.
	 * @return array<string,mixed>|null
	 */
	public static function build( array $market, string $raw ): ?array {
		if ( self::DIGEST_SLUG === $raw ) {
			$cards = LNKup_Market_Registry::cards( $market, array( 'date_to' => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . self::DIGEST_DAYS . ' days' ) ), 'limit' => 24 ) );
			return array(
				'type'     => 'digest',
				'slug'     => self::DIGEST_SLUG,
				'label'    => __( 'This week', 'lnkup-marketplace' ),
				'url'      => LNKup_Market_Registry::digest_url( (string) $market['slug'] ),
				'cards'    => $cards,
				'eligible' => self::active() && count( $cards ) >= self::DIGEST_MIN && LNKup_Index_Policy::market( $market )['index'],
				/* translators: %s: market name */
				'title'    => sprintf( __( 'Vendor opportunities in %s in the next two weeks', 'lnkup-marketplace' ), $market['display_name'] ),
			);
		}
		$label = self::category_label( $raw );
		if ( '' === $label ) {
			return null;
		}
		$terms = array( $label );
		foreach ( LNKup_Market_Registry::categories( $market ) as $cat ) {
			if ( $cat['slug'] === $raw ) {
				$terms = $cat['terms'] ? $cat['terms'] : $terms;
			}
		}
		$cards = LNKup_Market_Registry::cards( $market, array( 'terms' => $terms, 'limit' => 24 ) );
		return array(
			'type'     => 'category',
			'slug'     => $raw,
			'label'    => $label,
			'url'      => LNKup_Market_Registry::category_url( (string) $market['slug'], $raw ),
			'cards'    => $cards,
			'eligible' => LNKup_Market_Registry::category_eligible( $market, $raw, count( $cards ) ),
			/* translators: 1: category, 2: market */
			'title'    => sprintf( __( '%1$s vendor opportunities in %2$s', 'lnkup-marketplace' ), $label, $market['display_name'] ),
		);
	}

	/**
	 * Unknown sub-pages 404; known ones extend the market page context.
	 */
	public static function prepare(): void {
		$raw = (string) get_query_var( self::SUB_VAR );
		if ( '' === $raw || ! class_exists( 'LNKup_Market_Pages' ) || ! LNKup_Market_Pages::is_market_request() ) {
			return;
		}
		$sub = self::sub();
		if ( null === $sub ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			LNKup_Market_Pages::set_context( null );
			return;
		}
		$ctx = LNKup_Market_Pages::get_context();
		if ( is_array( $ctx ) ) {
			$ctx['sub'] = $sub;
			LNKup_Market_Pages::set_context( $ctx );
		}
	}

	/**
	 * Index decision for the current sub-page (used by LNKup_Index_Policy::current()).
	 *
	 * @return array{index:bool,sitemap:bool,reason:string}|null
	 */
	public static function decision(): ?array {
		$sub = self::sub();
		if ( null === $sub ) {
			return null;
		}
		return $sub['eligible']
			? array( 'index' => true, 'sitemap' => true, 'reason' => 'market_' . $sub['type'] )
			: array( 'index' => false, 'sitemap' => false, 'reason' => 'market_' . $sub['type'] . '_thin' );
	}

	public static function description_text(): string {
		$sub = self::sub();
		$ctx = class_exists( 'LNKup_Market_Pages' ) ? LNKup_Market_Pages::get_context() : null;
		if ( ! $sub || ! is_array( $ctx ) ) {
			return '';
		}
		$count = count( (array) $sub['cards'] );
		$name  = (string) $ctx['market']['display_name'];
		if ( 'digest' === $sub['type'] ) {
			/* translators: 1: count, 2: market */
			return sprintf( _n( '%1$d event in %2$s is taking vendor applications in the next two weeks. See booth details and apply free on LNKup.', '%1$d events in %2$s are taking vendor applications in the next two weeks. See booth details and apply free on LNKup.', $count, 'lnkup-marketplace' ), $count, $name );
		}
		/* translators: 1: count, 2: category, 3: market */
		return sprintf( _n( '%1$d upcoming event in %3$s wants %2$s vendors. See dates, requirements and apply free on LNKup.', '%1$d upcoming events in %3$s want %2$s vendors. See dates, requirements and apply free on LNKup.', $count, 'lnkup-marketplace' ), $count, (string) $sub['label'], $name );
	}

	/**
	 * @param string $canonical Market canonical.
	 */
	public static function canonical( $canonical ) {
		$sub = self::sub();
		return $sub ? (string) $sub['url'] : $canonical;
	}

	/**
	 * Breadcrumb + ItemList graph for the current sub-page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function graph(): array {
		$sub = self::sub();
		$ctx = LNKup_Market_Pages::get_context();
		if ( ! $sub || ! is_array( $ctx ) || ! $sub['eligible'] ) {
			return array();
		}
		$items = array();
		foreach ( array_values( (array) $sub['cards'] ) as $i => $card ) {
			$items[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'url' => (string) $card['url'], 'name' => (string) $card['title'] );
		}
		$market = $ctx['market'];
		$out    = array(
			LNKup_SEO_Schema::breadcrumbs(
				array(
					array( __( 'Home', 'lnkup-marketplace' ), home_url( '/' ) ),
					array( (string) $market['display_name'], LNKup_Market_Pages::market_url( (string) $market['slug'] ) ),
					array( (string) $sub['label'], (string) $sub['url'] ),
				)
			),
		);
		if ( $items ) {
			$out[] = array( '@type' => 'ItemList', 'name' => (string) $sub['title'], 'itemListElement' => $items );
		}
		return $out;
	}

	/**
	 * @param string[] $subtypes Subtypes.
	 * @return string[]
	 */
	public static function sitemap_subtypes( $subtypes ): array {
		$subtypes   = is_array( $subtypes ) ? $subtypes : array();
		if ( self::active() ) {
			$subtypes[] = 'market_pages';
		}
		return $subtypes;
	}

	/**
	 * @param array<int,array<string,string>> $entries Entries.
	 * @param string                          $subtype Subtype.
	 * @return array<int,array<string,string>>
	 */
	public static function sitemap_entries( $entries, $subtype ) {
		if ( 'market_pages' !== $subtype || ! self::active() || ! class_exists( 'LNKup_Market_Pages' ) ) {
			return $entries;
		}
		$entries = is_array( $entries ) ? $entries : array();
		foreach ( LNKup_Market_Pages::get_all_markets() as $slug => $raw ) {
			$market = LNKup_Market_Pages::get_market_by_slug( (string) ( is_array( $raw ) && ! empty( $raw['slug'] ) ? $raw['slug'] : $slug ) );
			if ( ! $market || ! LNKup_Index_Policy::market( $market )['index'] ) {
				continue;
			}
			$lastmod = LNKup_Sitemaps::market_lastmod( $market );
			foreach ( LNKup_Market_Registry::categories( $market ) as $cat ) {
				if ( $cat['eligible'] ) {
					$entries[] = array( 'loc' => $cat['url'], 'lastmod' => $lastmod );
				}
			}
			$digest = self::build( $market, self::DIGEST_SLUG );
			if ( $digest && $digest['eligible'] ) {
				$entries[] = array( 'loc' => $digest['url'], 'lastmod' => $lastmod );
			}
		}
		return $entries;
	}
}
