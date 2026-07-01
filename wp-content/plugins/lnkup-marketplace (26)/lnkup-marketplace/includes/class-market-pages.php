<?php
/**
 * City market landing pages.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Market_Pages {

	const OPTION_KEY   = 'lnkup_city_markets';
	const QUERY_VAR    = 'lnkup_market_slug';
	const REWRITE_BASE = 'markets';

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'prepare_market_context' ), 1 );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_shortcode( 'lnkup_market_page', array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Whether market pages are enabled.
	 */
	public static function is_enabled() {
		$settings = lnkup_get_settings();
		return ! isset( $settings['market_pages_enabled'] ) || ! empty( $settings['market_pages_enabled'] );
	}

	/**
	 * Default seed markets.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function default_markets() {
		$settings = lnkup_get_settings();
		$vendor_goal    = (int) ( $settings['launch_goal_vendors'] ?? 4000 );
		$organizer_goal = (int) ( $settings['launch_goal_organizers'] ?? 500 );

		$cities = array(
			array( 'slug' => 'charlotte', 'city' => 'Charlotte', 'state' => 'NC', 'region' => 'Carolinas', 'hero_image' => 'rodeo.svg' ),
			array( 'slug' => 'atlanta', 'city' => 'Atlanta', 'state' => 'GA', 'region' => 'Southeast', 'hero_image' => 'block-party.svg' ),
			array( 'slug' => 'dallas', 'city' => 'Dallas', 'state' => 'TX', 'region' => 'Texas', 'hero_image' => 'block-party.svg' ),
			array( 'slug' => 'houston', 'city' => 'Houston', 'state' => 'TX', 'region' => 'Texas', 'hero_image' => 'block-party.svg' ),
			array( 'slug' => 'raleigh', 'city' => 'Raleigh', 'state' => 'NC', 'region' => 'Carolinas', 'hero_image' => 'rodeo.svg' ),
			array( 'slug' => 'greensboro', 'city' => 'Greensboro', 'state' => 'NC', 'region' => 'Carolinas', 'hero_image' => 'rodeo.svg' ),
			array( 'slug' => 'miami', 'city' => 'Miami', 'state' => 'FL', 'region' => 'Florida', 'hero_image' => 'sunset-miami.svg' ),
			array( 'slug' => 'washington-dc', 'city' => 'Washington', 'state' => 'DC', 'region' => 'Mid-Atlantic', 'display_name' => 'Washington DC', 'hero_image' => 'block-party.svg' ),
			array( 'slug' => 'new-york', 'city' => 'New York', 'state' => 'NY', 'region' => 'Northeast', 'hero_image' => 'block-party.svg' ),
			array( 'slug' => 'los-angeles', 'city' => 'Los Angeles', 'state' => 'CA', 'region' => 'West Coast', 'hero_image' => 'sunset-miami.svg' ),
		);

		$markets = array();
		foreach ( $cities as $row ) {
			$slug = $row['slug'];
			$name = $row['display_name'] ?? $row['city'];
			$markets[ $slug ] = array(
				'slug'                       => $slug,
				'city'                       => $row['city'],
				'state'                      => $row['state'],
				'display_name'               => $name,
				'region'                     => $row['region'],
				'launch_status'              => 'prelaunch',
				'vendor_goal'                => $vendor_goal,
				'organizer_goal'             => $organizer_goal,
				'hero_headline'              => '',
				'hero_description'           => '',
				'featured_category_slugs'    => array(),
				'featured_event_type_slugs'  => array(),
				'seo_title'                  => '',
				'seo_description'            => '',
				'og_image'                   => '',
				'hero_image'                 => $row['hero_image'],
				'enabled'                    => true,
				'indexable'                  => true,
				'connector_cta_url'          => '',
			);
		}
		return $markets;
	}

	/**
	 * Seed markets on upgrade if missing.
	 */
	public static function maybe_seed_markets() {
		$existing = get_option( self::OPTION_KEY, null );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			return;
		}
		update_option( self::OPTION_KEY, self::default_markets(), false );
	}

	/**
	 * Get all markets from option.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_all_markets() {
		$markets = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $markets ) || empty( $markets ) ) {
			$markets = self::default_markets();
		}
		return $markets;
	}

	/**
	 * Save markets option.
	 *
	 * @param array $markets Markets.
	 */
	public static function save_markets( $markets ) {
		update_option( self::OPTION_KEY, $markets, false );
	}

	/**
	 * Register rewrite rules.
	 */
	public static function register_rewrite_rules() {
		if ( ! self::is_enabled() ) {
			return;
		}
		add_rewrite_rule(
			'^' . self::REWRITE_BASE . '/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Register query var.
	 *
	 * @param array $vars Vars.
	 */
	public static function register_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Market page URL.
	 *
	 * @param string $slug Slug.
	 */
	public static function market_url( $slug ) {
		return home_url( '/' . self::REWRITE_BASE . '/' . sanitize_title( $slug ) . '/' );
	}

	/**
	 * Get market by slug.
	 *
	 * @param string $slug Slug.
	 */
	public static function get_market_by_slug( $slug, $require_enabled = true ) {
		$slug = sanitize_title( (string) $slug );
		if ( ! $slug ) {
			return null;
		}
		$markets = self::get_all_markets();
		if ( empty( $markets[ $slug ] ) ) {
			return null;
		}
		if ( $require_enabled && empty( $markets[ $slug ]['enabled'] ) ) {
			return null;
		}
		return self::normalize_market( $markets[ $slug ] );
	}

	/**
	 * Find market by city and state.
	 *
	 * @param string $city  City.
	 * @param string $state State.
	 */
	public static function find_by_city_state( $city, $state = '' ) {
		$city  = trim( (string) $city );
		$state = strtoupper( trim( (string) $state ) );
		if ( ! $city ) {
			return null;
		}
		foreach ( self::get_all_markets() as $market ) {
			if ( empty( $market['enabled'] ) ) {
				continue;
			}
			if ( strcasecmp( (string) ( $market['city'] ?? '' ), $city ) !== 0 ) {
				continue;
			}
			if ( $state && strtoupper( (string) ( $market['state'] ?? '' ) ) !== $state ) {
				continue;
			}
			return self::normalize_market( $market );
		}
		return null;
	}

	/**
	 * Normalize market record.
	 *
	 * @param array $market Market.
	 */
	private static function normalize_market( $market ) {
		$settings = lnkup_get_settings();
		$market['slug']          = sanitize_title( (string) ( $market['slug'] ?? '' ) );
		$market['city']          = sanitize_text_field( (string) ( $market['city'] ?? '' ) );
		$market['state']         = strtoupper( sanitize_text_field( (string) ( $market['state'] ?? '' ) ) );
		$market['display_name']  = sanitize_text_field( (string) ( $market['display_name'] ?? $market['city'] ) );
		$market['launch_status'] = sanitize_key( (string) ( $market['launch_status'] ?? 'prelaunch' ) );
		$market['vendor_goal']   = max( 0, (int) ( $market['vendor_goal'] ?? ( $settings['launch_goal_vendors'] ?? 4000 ) ) );
		$market['organizer_goal']= max( 0, (int) ( $market['organizer_goal'] ?? ( $settings['launch_goal_organizers'] ?? 500 ) ) );
		$market['enabled']       = ! empty( $market['enabled'] );
		$market['indexable']     = ! isset( $market['indexable'] ) || ! empty( $market['indexable'] );
		$market['url']           = self::market_url( $market['slug'] );
		$market['opportunities_url'] = add_query_arg(
			array(
				'city'  => $market['city'],
				'state' => $market['state'],
			),
			lnkup_page_url_helper( 'opportunities' )
		);
		return $market;
	}

	/**
	 * Full page payload for template.
	 *
	 * @param string $slug Slug.
	 */
	public static function get_market_page_data( $slug ) {
		$market = self::get_market_by_slug( $slug );
		if ( ! $market ) {
			return null;
		}

		$stats = self::get_market_stats( $slug );
		$opps  = self::get_market_opportunities( $slug, 6 );

		$launch_label = function_exists( 'lnkup_get_launch_date_label' ) ? lnkup_get_launch_date_label() : '';
		$marketplace_open = lnkup_can_browse_marketplace();

		$headline = $market['hero_headline'] ?: sprintf(
			/* translators: %s: city name */
			__( 'Find vendor opportunities in %s', 'lnkup-marketplace' ),
			$market['display_name']
		);

		$description = $market['hero_description'] ?: sprintf(
			/* translators: %s: city name */
			__( 'LNKup connects vendors with festivals, markets, rodeos, pop-ups, conferences, and live events across %s.', 'lnkup-marketplace' ),
			$market['display_name']
		);

		if ( ! $market['seo_title'] ) {
			$market['seo_title'] = sprintf(
				/* translators: %s: city name */
				__( 'Vendor Opportunities in %s | LNKup', 'lnkup-marketplace' ),
				$market['display_name']
			);
		}
		if ( ! $market['seo_description'] ) {
			$market['seo_description'] = sprintf(
				/* translators: 1: city name, 2: state */
				__( 'Find vendor opportunities in %1$s, %2$s. LNKup helps food vendors, retail brands, beauty vendors, and mobile businesses apply to local events for free.', 'lnkup-marketplace' ),
				$market['display_name'],
				$market['state']
			);
		}

		return array(
			'market'            => $market,
			'stats'             => $stats,
			'opportunities'     => $opps['items'] ?? array(),
			'opportunity_total' => $opps['total'] ?? 0,
			'categories'        => self::get_featured_categories( $market ),
			'event_types'       => self::get_featured_event_types( $market ),
			'launch_label'      => $launch_label,
			'marketplace_open'  => $marketplace_open,
			'headline'          => $headline,
			'description'       => $description,
			'status_label'      => self::status_label( $market['launch_status'] ),
			'vendor_signup_url' => self::get_market_signup_url( $slug, 'vendor' ),
			'organizer_signup_url' => self::get_market_signup_url( $slug, 'organizer' ),
			'connector_url'     => self::get_connector_url( $market ),
			'organizer_create_url' => lnkup_page_url_helper( 'organizer-dashboard' ) . '#create',
		);
	}

	/**
	 * Human-readable launch status.
	 *
	 * @param string $status Status key.
	 */
	public static function status_label( $status ) {
		$labels = array(
			'prelaunch'             => __( 'Launching soon', 'lnkup-marketplace' ),
			'recruiting_vendors'    => __( 'Recruiting vendors', 'lnkup-marketplace' ),
			'recruiting_organizers' => __( 'Organizers needed', 'lnkup-marketplace' ),
			'beta'                  => __( 'Beta market', 'lnkup-marketplace' ),
			'active'                => __( 'Active market', 'lnkup-marketplace' ),
			'paused'                => __( 'Paused', 'lnkup-marketplace' ),
		);
		return $labels[ $status ] ?? $labels['prelaunch'];
	}

	/**
	 * Real stats for a market (no fabricated counts).
	 *
	 * @param string $slug Slug.
	 */
	public static function get_market_stats( $slug ) {
		$market = self::get_market_by_slug( $slug );
		if ( ! $market ) {
			return array();
		}

		global $wpdb;
		$table = LNKup_Marketplace_Index::table_name();
		$city_like = '%' . $wpdb->esc_like( $market['city'] ) . '%';
		$opp_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE city LIKE %s AND UPPER(state) = %s",
				$city_like,
				$market['state']
			)
		);

		$vendor_signups = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT u.ID) FROM {$wpdb->users} u
				INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = %s AND um.meta_value = %s
				INNER JOIN {$wpdb->usermeta} cap ON u.ID = cap.user_id AND cap.meta_key = %s AND cap.meta_value LIKE %s",
				'_lnkup_market_interest',
				$market['slug'],
				$wpdb->prefix . 'capabilities',
				'%lnkup_vendor%'
			)
		);

		$organizer_signups = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT u.ID) FROM {$wpdb->users} u
				INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = %s AND um.meta_value = %s
				INNER JOIN {$wpdb->usermeta} cap ON u.ID = cap.user_id AND cap.meta_key = %s AND cap.meta_value LIKE %s",
				'_lnkup_market_interest',
				$market['slug'],
				$wpdb->prefix . 'capabilities',
				'%lnkup_organizer%'
			)
		);

		return array(
			'public_opportunities' => $opp_count,
			'vendor_signups'       => $vendor_signups > 0 ? $vendor_signups : null,
			'organizer_signups'    => $organizer_signups > 0 ? $organizer_signups : null,
			'vendor_goal'          => $market['vendor_goal'],
			'organizer_goal'       => $market['organizer_goal'],
		);
	}

	/**
	 * Public opportunities in market.
	 *
	 * @param string $slug  Slug.
	 * @param int    $limit Limit.
	 */
	public static function get_market_opportunities( $slug, $limit = 6 ) {
		$market = self::get_market_by_slug( $slug );
		if ( ! $market || ! class_exists( 'LNKup_Discovery' ) ) {
			return array( 'items' => array(), 'total' => 0 );
		}

		$result = LNKup_Discovery::search(
			array(
				'city'     => $market['city'],
				'state'    => $market['state'],
				'per_page' => max( 1, min( 12, (int) $limit ) ),
				'sort'     => 'soonest',
				'has_spots'=> true,
			)
		);

		return array(
			'items' => $result['items'] ?? array(),
			'total' => (int) ( $result['total'] ?? 0 ),
		);
	}

	/**
	 * Featured vendor categories for market page.
	 *
	 * @param array $market Market.
	 */
	private static function get_featured_categories( $market ) {
		$slugs = array_filter( (array) ( $market['featured_category_slugs'] ?? array() ) );
		$terms = get_terms(
			array(
				'taxonomy'   => 'lnkup_vendor_category',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		if ( $slugs ) {
			$out = array();
			foreach ( $terms as $term ) {
				if ( in_array( $term->slug, $slugs, true ) ) {
					$out[] = $term;
				}
			}
			if ( $out ) {
				return $out;
			}
		}

		$defaults = array( 'food-vendor', 'food-truck', 'dessert', 'clothing', 'beauty', 'jewelry', 'photo-booth', 'art', 'wellness', 'services' );
		$out      = array();
		foreach ( $terms as $term ) {
			if ( in_array( $term->slug, $defaults, true ) || count( $out ) < 11 ) {
				$out[] = $term;
			}
			if ( count( $out ) >= 11 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Featured event types for market page.
	 *
	 * @param array $market Market.
	 */
	private static function get_featured_event_types( $market ) {
		$slugs = array_filter( (array) ( $market['featured_event_type_slugs'] ?? array() ) );
		$terms = get_terms(
			array(
				'taxonomy'   => 'lnkup_event_type',
				'hide_empty' => false,
				'parent'     => 0,
			)
		);
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		if ( $slugs ) {
			$out = array();
			foreach ( $terms as $term ) {
				if ( in_array( $term->slug, $slugs, true ) ) {
					$out[] = $term;
				}
			}
			if ( $out ) {
				return $out;
			}
		}

		$names = array( 'Festivals', 'Markets & Pop-Ups', 'Rodeos', 'Block Parties', 'Homecomings', 'Conferences', 'Food Festivals', 'Cultural Events', 'Campus Events', 'Day Parties' );
		$out   = array();
		foreach ( $terms as $term ) {
			if ( in_array( $term->name, $names, true ) ) {
				$out[] = $term;
			}
		}
		if ( ! $out ) {
			$out = array_slice( $terms, 0, 10 );
		}
		return $out;
	}

	/**
	 * Sign-up URL with market context.
	 *
	 * @param string $slug Slug.
	 * @param string $role vendor|organizer.
	 */
	public static function get_market_signup_url( $slug, $role = 'vendor' ) {
		$role = 'organizer' === $role ? 'organizer' : 'vendor';
		return add_query_arg(
			array(
				'role'   => $role,
				'market' => sanitize_title( $slug ),
			),
			lnkup_registration_url()
		);
	}

	/**
	 * Connector interest URL.
	 *
	 * @param array $market Market.
	 */
	private static function get_connector_url( $market ) {
		if ( ! empty( $market['connector_cta_url'] ) ) {
			return esc_url_raw( $market['connector_cta_url'] );
		}
		$interest_url = function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'connector-interest' ) : home_url( '/connector-interest/' );
		return add_query_arg( 'market', $market['slug'] ?? '', $interest_url );
	}

	/**
	 * Current market slug from query.
	 */
	public static function current_slug() {
		return sanitize_title( (string) get_query_var( self::QUERY_VAR ) );
	}

	/**
	 * Whether request is a market page.
	 */
	public static function is_market_request() {
		return self::is_enabled() && (bool) self::current_slug();
	}

	/**
	 * Set global context for theme.
	 *
	 * @param array|null $data Page data.
	 */
	public static function set_context( $data ) {
		$GLOBALS['lnkup_market_context'] = $data;
	}

	/**
	 * Get global context.
	 */
	public static function get_context() {
		return isset( $GLOBALS['lnkup_market_context'] ) ? $GLOBALS['lnkup_market_context'] : null;
	}

	/**
	 * Set market context before template load (SEO + assets).
	 */
	public static function prepare_market_context() {
		if ( ! self::is_market_request() ) {
			return;
		}
		$slug = self::current_slug();
		$data = self::get_market_page_data( $slug );
		if ( ! $data ) {
			return;
		}
		self::set_context( $data );
	}

	/**
	 * Load theme template.
	 *
	 * @param string $template Template.
	 */
	public static function template_include( $template ) {
		if ( ! self::is_market_request() ) {
			return $template;
		}

		$slug = self::current_slug();
		$data = self::get_context();
		if ( ! $data ) {
			$data = self::get_market_page_data( $slug );
		}
		if ( ! $data ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return get_404_template();
		}

		self::set_context( $data );

		$theme_template = locate_template( 'page-templates/market-landing.php' );
		if ( $theme_template ) {
			return $theme_template;
		}

		return $template;
	}

	/**
	 * Enqueue market page assets.
	 */
	public static function enqueue_assets() {
		if ( ! self::is_market_request() ) {
			return;
		}

		wp_enqueue_style(
			'lnkup-market-pages',
			LNKUP_PLUGIN_URL . 'public/css/market-pages.css',
			array( 'lnkup-components' ),
			LNKUP_VERSION
		);

		wp_enqueue_script(
			'lnkup-market-landing',
			LNKUP_PLUGIN_URL . 'public/js/market-landing.js',
			array(),
			LNKUP_VERSION,
			true
		);

		$ctx = self::get_context();
		$slug = self::current_slug();
		if ( ! $ctx && $slug ) {
			$ctx = self::get_market_page_data( $slug );
		}
		wp_localize_script(
			'lnkup-market-landing',
			'lnkupMarketPage',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'lnkup/v1/marketplace/track/event' ) ),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
				'marketSlug'   => $ctx['market']['slug'] ?? $slug,
				'launchStatus' => $ctx['market']['launch_status'] ?? '',
			)
		);
	}

	/**
	 * Shortcode fallback.
	 *
	 * @param array $atts Atts.
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'slug' => '' ), $atts, 'lnkup_market_page' );
		$data = self::get_market_page_data( $atts['slug'] );
		if ( ! $data ) {
			return '';
		}
		self::set_context( $data );
		ob_start();
		$tpl = locate_template( 'page-templates/market-landing.php' );
		if ( $tpl ) {
			include $tpl;
		}
		return (string) ob_get_clean();
	}

	/**
	 * Indexable market URLs for sitemap.
	 *
	 * @return array<int, array{loc:string,mod:string}>
	 */
	public static function get_sitemap_urls() {
		$urls = array();
		foreach ( self::get_all_markets() as $market ) {
			if ( empty( $market['enabled'] ) ) {
				continue;
			}
			$market = self::normalize_market( $market );
			if ( 'paused' === $market['launch_status'] || ! $market['indexable'] ) {
				continue;
			}
			$urls[] = array(
				'loc' => $market['url'],
				'mod' => gmdate( 'c' ),
			);
		}
		return $urls;
	}
}
