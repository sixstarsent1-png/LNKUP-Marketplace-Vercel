<?php
/**
 * SEO diagnostics for the Marketing Command Center. Checks real records only; results cached
 * for an hour. Each issue: severity (error|warning|info), code, message, count, sample URLs.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_SEO_Diagnostics {

	const CACHE_KEY = 'lnkup_seo_diagnostics_v1';
	const SAMPLE    = 5;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			'lnkup/v1',
			'/marketing/seo/diagnostics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array( 'refresh' => array( 'type' => 'boolean', 'default' => false ) ),
			)
		);
	}

	public static function can_view(): bool {
		return current_user_can( 'lnkup_manage_marketing' ) || current_user_can( 'lnkup_manage_all' ) || current_user_can( 'manage_options' );
	}

	public static function rest( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( self::run( (bool) $request->get_param( 'refresh' ) ) );
	}

	/**
	 * @return array{generated_at:string,policy_active:bool,rank_math:bool,issues:array<int,array<string,mixed>>,summary:array<string,int>}
	 */
	public static function run( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$issues = array();
		try {
			$issues = array_merge( $issues, self::event_checks(), self::market_checks(), self::config_checks() );
		} catch ( Throwable $e ) {
			$issues[] = self::issue( 'error', 'diagnostics_failed', __( 'Diagnostics could not finish. Check the PHP error log.', 'lnkup-marketplace' ), 1 );
		}
		$summary = array( 'error' => 0, 'warning' => 0, 'info' => 0 );
		foreach ( $issues as $issue ) {
			++$summary[ $issue['severity'] ];
		}
		$out = array(
			'generated_at'  => gmdate( 'c' ),
			'policy_active' => LNKup_Index_Policy::active(),
			'rank_math'     => function_exists( 'lnkup_seo_is_rankmath_active' ) && lnkup_seo_is_rankmath_active(),
			'issues'        => $issues,
			'summary'       => $summary,
		);
		set_transient( self::CACHE_KEY, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * @param string[] $samples Sample URLs.
	 * @return array<string,mixed>
	 */
	private static function issue( string $severity, string $code, string $message, int $count, array $samples = array() ): array {
		return array(
			'severity' => $severity,
			'code'     => $code,
			'message'  => $message,
			'count'    => $count,
			'samples'  => array_slice( array_values( $samples ), 0, self::SAMPLE ),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function event_checks(): array {
		$issues   = array();
		$events   = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => 'publish',
				'posts_per_page' => 1000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$indexable = array();
		$no_desc   = array();
		$no_image  = array();
		$titles    = array();
		$blocked   = array();
		foreach ( $events as $event_id ) {
			$decision = LNKup_Index_Policy::event( (int) $event_id );
			if ( ! $decision['index'] ) {
				if ( 'incomplete' === $decision['reason'] ) {
					$blocked[] = (string) get_permalink( (int) $event_id );
				}
				continue;
			}
			$url         = (string) get_permalink( (int) $event_id );
			$indexable[] = $url;
			$content     = trim( wp_strip_all_tags( (string) get_post_field( 'post_content', (int) $event_id ) ) );
			if ( strlen( $content ) < 80 ) {
				$no_desc[] = $url;
			}
			if ( class_exists( 'LNKup_Event_Media' ) && empty( LNKup_Event_Media::resolve( (int) $event_id )['uploaded'] ) ) {
				$no_image[] = $url;
			}
			$key              = strtolower( trim( get_the_title( (int) $event_id ) ) . '|' . get_post_meta( (int) $event_id, '_lnkup_city', true ) );
			$titles[ $key ][] = $url;
		}
		$dupes = array();
		foreach ( $titles as $urls ) {
			if ( count( $urls ) > 1 ) {
				$dupes = array_merge( $dupes, $urls );
			}
		}
		$issues[] = self::issue( 'info', 'events_indexable', __( 'Events that are currently indexable.', 'lnkup-marketplace' ), count( $indexable ), $indexable );
		if ( $no_desc ) {
			$issues[] = self::issue( 'warning', 'events_thin_description', __( 'Indexable events with less than 80 characters of description. Ask organizers to describe the event and vendor setup.', 'lnkup-marketplace' ), count( $no_desc ), $no_desc );
		}
		if ( $no_image ) {
			$issues[] = self::issue( 'info', 'events_no_uploaded_image', __( 'Indexable events using generated or category art instead of their own image.', 'lnkup-marketplace' ), count( $no_image ), $no_image );
		}
		if ( $dupes ) {
			$issues[] = self::issue( 'warning', 'events_duplicate_titles', __( 'Indexable events sharing the same title in the same city (possible duplicates).', 'lnkup-marketplace' ), count( $dupes ), $dupes );
		}
		if ( $blocked ) {
			$issues[] = self::issue( 'info', 'events_incomplete', __( 'Published events kept out of search because the title, city or date is missing.', 'lnkup-marketplace' ), count( $blocked ), $blocked );
		}
		return $issues;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function market_checks(): array {
		if ( ! class_exists( 'LNKup_Market_Pages' ) ) {
			return array();
		}
		$thin = array();
		$live = array();
		foreach ( LNKup_Market_Pages::get_all_markets() as $slug => $raw ) {
			$market = LNKup_Market_Pages::get_market_by_slug( (string) ( is_array( $raw ) && ! empty( $raw['slug'] ) ? $raw['slug'] : $slug ), false );
			if ( ! $market || empty( $market['enabled'] ) ) {
				continue;
			}
			$decision = LNKup_Index_Policy::market( $market );
			$url      = LNKup_Market_Pages::market_url( (string) $market['slug'] );
			if ( $decision['index'] ) {
				$live[] = $url;
			} elseif ( 'thin' === $decision['reason'] ) {
				$thin[] = $url;
			}
		}
		$issues = array( self::issue( 'info', 'markets_indexable', __( 'Market pages that are indexable.', 'lnkup-marketplace' ), count( $live ), $live ) );
		if ( $thin ) {
			$issues[] = self::issue( 'info', 'markets_thin', __( 'Enabled markets kept out of search: no open events and no market-specific content yet.', 'lnkup-marketplace' ), count( $thin ), $thin );
		}
		return $issues;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function config_checks(): array {
		$issues = array();
		if ( ! LNKup_Index_Policy::active() ) {
			$issues[] = self::issue( 'warning', 'policy_off', __( 'The index policy is off: past, cancelled and unclaimed events can still be indexed. Turn on "seo_index_policy".', 'lnkup-marketplace' ), 1 );
		}
		if ( ! get_option( 'permalink_structure' ) ) {
			$issues[] = self::issue( 'error', 'plain_permalinks', __( 'Plain permalinks are active; clean URLs and redirects are disabled.', 'lnkup-marketplace' ), 1 );
		}
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			$issues[] = self::issue( 'error', 'search_engines_discouraged', __( 'Settings → Reading discourages search engines on this site.', 'lnkup-marketplace' ), 1 );
		}
		$rules    = (array) get_option( 'rewrite_rules', array() );
		$patterns = array_keys( $rules );
		$expect   = array();
		if ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_enabled() ) {
			$expect['^markets/([^/]+)/?$'] = __( 'City market pages', 'lnkup-marketplace' );
		}
		if ( LNKup_Index_Policy::active() ) {
			$expect['^organizers/([a-z0-9][a-z0-9-]{0,190})/?$'] = __( 'Organizer profile URLs', 'lnkup-marketplace' );
		}
		foreach ( $expect as $pattern => $label ) {
			if ( ! in_array( $pattern, $patterns, true ) ) {
				/* translators: %s: URL family */
				$issues[] = self::issue( 'error', 'rewrite_missing', sprintf( __( 'Rewrite rule missing for %s. Visit Settings → Permalinks and save to rebuild rules.', 'lnkup-marketplace' ), $label ), 1 );
			}
		}
		if ( function_exists( 'lnkup_seo_is_rankmath_active' ) && ! lnkup_seo_is_rankmath_active() && ! (bool) apply_filters( 'wp_sitemaps_enabled', (bool) get_option( 'blog_public' ) ) ) {
			$issues[] = self::issue( 'warning', 'no_sitemap', __( 'Neither Rank Math nor the core sitemap is enabled.', 'lnkup-marketplace' ), 1 );
		}
		return $issues;
	}
}
