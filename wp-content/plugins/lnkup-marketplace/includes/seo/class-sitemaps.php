<?php
/**
 * Sitemap coverage for marketplace URLs (flag seo_index_policy).
 *
 * One entry builder feeds both the core WordPress sitemap (provider "lnkup") and Rank Math (page
 * sitemap URLs). Every URL passes LNKup_Index_Policy; lastmod comes from the real record (event or
 * tour modified time, latest change to a market's listings). Core sitemaps also stop listing users,
 * marketplace taxonomies, opportunity singles and app/account pages.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Sitemaps {

	const PER_PAGE = 2000;

	const SUBTYPES = array( 'events', 'markets', 'tours', 'organizers', 'vendors' );

	public static function init(): void {
		add_action( 'wp_sitemaps_init', array( __CLASS__, 'register_provider' ) );
		add_filter( 'wp_sitemaps_post_types', array( __CLASS__, 'core_post_types' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( __CLASS__, 'core_taxonomies' ) );
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'core_providers' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'core_page_args' ), 10, 2 );
		add_filter( 'rank_math/sitemap/exclude_taxonomy', array( __CLASS__, 'rank_math_exclude_taxonomy' ), 10, 2 );
		add_filter( 'rank_math/sitemap/urls', array( __CLASS__, 'rank_math_urls' ), 20, 2 );
		add_action( 'update_option_lnkup_city_markets', array( __CLASS__, 'touch_markets' ) );
	}

	public static function touch_markets(): void {
		update_option( 'lnkup_city_markets_updated_at', time(), false );
	}

	private static function active(): bool {
		return LNKup_Index_Policy::active();
	}

	/**
	 * @return string[]
	 */
	public static function subtypes(): array {
		return array_values( array_unique( array_merge( self::SUBTYPES, (array) apply_filters( 'lnkup_sitemap_subtypes', array() ) ) ) );
	}

	/**
	 * @param WP_Sitemaps $sitemaps Sitemaps server.
	 */
	public static function register_provider( $sitemaps ): void {
		if ( ! self::active() || ! class_exists( 'WP_Sitemaps_Provider' ) ) {
			return;
		}
		require_once __DIR__ . '/class-sitemap-provider.php';
		$sitemaps->registry->add_provider( 'lnkup', new LNKup_Sitemap_Provider() );
	}

	/**
	 * @param array<string,WP_Post_Type> $types Types.
	 * @return array<string,WP_Post_Type>
	 */
	public static function core_post_types( $types ) {
		if ( self::active() ) {
			unset( $types['lnkup_event'], $types['lnkup_opportunity'] );
		}
		return $types;
	}

	/**
	 * @param array<string,WP_Taxonomy> $taxonomies Taxonomies.
	 * @return array<string,WP_Taxonomy>
	 */
	public static function core_taxonomies( $taxonomies ) {
		if ( self::active() ) {
			unset( $taxonomies['lnkup_vendor_category'], $taxonomies['lnkup_event_type'], $taxonomies['lnkup_market'] );
		}
		return $taxonomies;
	}

	/**
	 * @param WP_Sitemaps_Provider|false $provider Provider.
	 * @param string                     $name     Name.
	 * @return WP_Sitemaps_Provider|false
	 */
	public static function core_providers( $provider, $name ) {
		return ( self::active() && 'users' === $name ) ? false : $provider;
	}

	/**
	 * @param array<string,mixed> $args      Query args.
	 * @param string              $post_type Post type.
	 * @return array<string,mixed>
	 */
	public static function core_page_args( $args, $post_type ) {
		if ( ! self::active() || 'page' !== $post_type ) {
			return $args;
		}
		$exclude = self::excluded_page_ids();
		if ( $exclude ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), $exclude );
		}
		return $args;
	}

	/**
	 * @return int[]
	 */
	public static function excluded_page_ids(): array {
		$slugs = array_unique( array_merge( function_exists( 'lnkup_seo_noindex_page_slugs' ) ? lnkup_seo_noindex_page_slugs() : array(), LNKup_Index_Policy::app_page_slugs() ) );
		$ids   = array();
		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page ) {
				$ids[] = (int) $page->ID;
			}
		}
		return $ids;
	}

	/**
	 * @param bool   $exclude  Exclude.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function rank_math_exclude_taxonomy( $exclude, $taxonomy ) {
		return ( self::active() && in_array( $taxonomy, array( 'lnkup_vendor_category', 'lnkup_event_type', 'lnkup_market' ), true ) ) ? true : $exclude;
	}

	/**
	 * Rank Math page sitemap gets markets (real lastmod), tours, organizers and vendors.
	 * Events stay in Rank Math's own lnkup_event sitemap, filtered by exclude_sitemap_post.
	 *
	 * @param array<int,array<string,string>> $urls URLs.
	 * @param string                          $type Sitemap type.
	 * @return array<int,array<string,string>>
	 */
	public static function rank_math_urls( $urls, $type ) {
		if ( ! self::active() || 'page' !== $type ) {
			return $urls;
		}
		$known = array();
		foreach ( (array) $urls as $row ) {
			$known[ (string) ( $row['loc'] ?? '' ) ] = true;
		}
		foreach ( self::subtypes() as $subtype ) {
			if ( 'events' === $subtype ) {
				continue;
			}
			for ( $page = 1; $page <= self::max_pages( $subtype ); $page++ ) {
				foreach ( self::entries( $subtype, $page ) as $entry ) {
					if ( isset( $known[ $entry['loc'] ] ) ) {
						continue;
					}
					$urls[] = array( 'loc' => $entry['loc'], 'mod' => $entry['lastmod'] );
				}
			}
		}
		return $urls;
	}

	private static function gmt_iso( string $mysql_gmt ): string {
		$ts = strtotime( $mysql_gmt . ' UTC' );
		return $ts ? gmdate( 'c', $ts ) : '';
	}

	/**
	 * Upcoming published event IDs (newest end of the indexable window), one page.
	 *
	 * @return int[]
	 */
	private static function event_ids( int $page, bool $count_only = false ) {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		$base  = "FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_lnkup_event_date'
			LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_lnkup_event_end_date'
			WHERE p.post_type = 'lnkup_event' AND p.post_status = 'publish' AND p.post_password = ''
			AND ( d.meta_value >= %s OR e.meta_value >= %s )";
		if ( $count_only ) {
			return array( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) {$base}", $today, $today ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		$offset = max( 0, $page - 1 ) * self::PER_PAGE;
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID {$base} ORDER BY d.meta_value ASC, p.ID ASC LIMIT %d OFFSET %d", $today, $today, self::PER_PAGE, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Organizer user IDs with at least one published event.
	 *
	 * @return int[]
	 */
	private static function organizer_ids(): array {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT DISTINCT CAST(m.meta_value AS UNSIGNED) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'lnkup_event' AND p.post_status = 'publish' WHERE m.meta_key = '_lnkup_organizer_user_id' AND m.meta_value > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $ids );
	}

	public static function max_pages( string $subtype ): int {
		if ( 'events' === $subtype ) {
			$count = (int) self::event_ids( 1, true )[0];
			return max( 1, (int) ceil( $count / self::PER_PAGE ) );
		}
		return (int) apply_filters( 'lnkup_sitemap_max_pages', 1, $subtype );
	}

	/**
	 * Sitemap entries for a subtype page.
	 *
	 * @return array<int,array{loc:string,lastmod:string}>
	 */
	public static function entries( string $subtype, int $page = 1 ): array {
		$out = array();
		switch ( $subtype ) {
			case 'events':
				foreach ( self::event_ids( $page ) as $event_id ) {
					if ( ! LNKup_Index_Policy::event( $event_id )['sitemap'] ) {
						continue;
					}
					$out[] = array( 'loc' => (string) get_permalink( $event_id ), 'lastmod' => self::gmt_iso( (string) get_post_field( 'post_modified_gmt', $event_id ) ) );
				}
				break;
			case 'markets':
				if ( ! class_exists( 'LNKup_Market_Pages' ) || ! LNKup_Market_Pages::is_enabled() ) {
					break;
				}
				foreach ( LNKup_Market_Pages::get_all_markets() as $slug => $raw ) {
					$market = LNKup_Market_Pages::get_market_by_slug( (string) ( is_array( $raw ) && ! empty( $raw['slug'] ) ? $raw['slug'] : $slug ) );
					if ( ! $market || ! LNKup_Index_Policy::market( $market )['sitemap'] ) {
						continue;
					}
					$out[] = array( 'loc' => LNKup_Market_Pages::market_url( (string) $market['slug'] ), 'lastmod' => self::market_lastmod( $market ) );
				}
				break;
			case 'tours':
				if ( ! class_exists( 'LNKup_Portfolio_Public' ) ) {
					break;
				}
				$tours = get_posts( array( 'post_type' => 'lnkup_tour', 'post_status' => 'publish', 'posts_per_page' => 500, 'fields' => 'ids', 'no_found_rows' => true ) );
				foreach ( $tours as $tour_id ) {
					if ( ! LNKup_Index_Policy::tour( (int) $tour_id )['sitemap'] ) {
						continue;
					}
					$out[] = array( 'loc' => LNKup_Portfolio_Public::tour_url( (int) $tour_id ), 'lastmod' => self::gmt_iso( (string) get_post_field( 'post_modified_gmt', (int) $tour_id ) ) );
				}
				break;
			case 'organizers':
				if ( ! class_exists( 'LNKup_Organizer_Onboarding' ) ) {
					break;
				}
				foreach ( array_slice( self::organizer_ids(), 0, 5000 ) as $user_id ) {
					if ( ! LNKup_Index_Policy::organizer( $user_id )['sitemap'] ) {
						continue;
					}
					$out[] = array( 'loc' => LNKup_Organizer_Onboarding::organizer_public_url( $user_id ), 'lastmod' => self::organizer_lastmod( $user_id ) );
				}
				break;
			case 'vendors':
				if ( ! class_exists( 'LNKup_Passport_Public' ) ) {
					break;
				}
				$profiles = get_posts(
					array(
						'post_type'      => 'lnkup_vendor_profile',
						'post_status'    => 'publish',
						'posts_per_page' => 5000,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_key'       => '_lnkup_public_slug', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_compare'   => 'EXISTS',
					)
				);
				foreach ( $profiles as $profile_id ) {
					if ( ! LNKup_Index_Policy::vendor( (int) $profile_id )['sitemap'] ) {
						continue;
					}
					$url = LNKup_Passport_Public::url_for_profile( (int) $profile_id );
					if ( '' !== $url ) {
						$out[] = array( 'loc' => $url, 'lastmod' => self::gmt_iso( (string) get_post_field( 'post_modified_gmt', (int) $profile_id ) ) );
					}
				}
				break;
			default:
				$out = (array) apply_filters( 'lnkup_sitemap_entries', array(), $subtype, $page );
		}
		return array_values( array_filter( $out, static fn( $e ) => '' !== $e['loc'] ) );
	}

	/**
	 * Latest change among a market's listings (falls back to the market option's edit time).
	 *
	 * @param array<string,mixed> $market Market.
	 */
	public static function market_lastmod( array $market ): string {
		global $wpdb;
		if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
			$table = LNKup_Marketplace_Index::table_name();
			$max   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(updated_at) FROM {$table} WHERE city = %s", (string) $market['city'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			if ( '' !== $max ) {
				$ts = strtotime( $max );
				if ( $ts ) {
					return gmdate( 'c', $ts );
				}
			}
		}
		$edited = (int) get_option( 'lnkup_city_markets_updated_at', 0 );
		return $edited ? gmdate( 'c', $edited ) : '';
	}

	private static function organizer_lastmod( int $user_id ): string {
		global $wpdb;
		$max = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(p.post_modified_gmt) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_lnkup_organizer_user_id' AND m.meta_value = %s WHERE p.post_type = 'lnkup_event' AND p.post_status = 'publish'", (string) $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return '' !== $max ? self::gmt_iso( $max ) : '';
	}
}
