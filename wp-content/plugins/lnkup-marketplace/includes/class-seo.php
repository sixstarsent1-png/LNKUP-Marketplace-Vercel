<?php
/**
 * Rank Math SEO integration for LNKup Marketplace.
 *
 * Rank Math is not bundled — install via wp-admin on each environment.
 * Setup: enable sitemap, leave homepage title/description empty for LNKup filters,
 * submit /sitemap_index.xml to Search Console after launch.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether Rank Math is active.
 */
function lnkup_seo_is_rankmath_active() {
	return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false );
}

/**
 * Default OG image (theme block-party illustration).
 */
function lnkup_seo_default_og_image() {
	$ver = defined( 'LNKUP_THEME_VERSION' ) ? LNKUP_THEME_VERSION : LNKUP_VERSION;
	$url = get_theme_file_uri( 'assets/images/events/block-party.svg' );
	return add_query_arg( 'ver', $ver, $url );
}

/**
 * Slugs for pages that should never be indexed.
 *
 * @return string[]
 */
function lnkup_seo_noindex_page_slugs() {
	return array( 'vendor-dashboard', 'organizer-dashboard', 'vendor-profile', 'favorites' );
}

/**
 * Whether the current request should be noindexed.
 */
function lnkup_seo_should_noindex() {
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post ) {
			$private_types = array(
				'lnkup_application',
				'lnkup_document',
				'lnkup_payment',
				'lnkup_packet',
				'lnkup_vendor_profile',
				'lnkup_vendor_note',
				'lnkup_vendor_list',
				'lnkup_vendor_invite',
				'lnkup_vendor_flag',
				'lnkup_review',
				'lnkup_dispute',
			);
			if ( in_array( $post->post_type, $private_types, true ) ) {
				return true;
			}
			if ( ! lnkup_is_marketplace_open() && in_array( $post->post_type, array( 'lnkup_opportunity', 'lnkup_event' ), true ) ) {
				return true;
			}
		}
	}

	if ( is_page( lnkup_seo_noindex_page_slugs() ) ) {
		return true;
	}

	if ( ! lnkup_is_marketplace_open() && is_page( 'opportunities' ) ) {
		return true;
	}

	if ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_market_request() ) {
		$market = LNKup_Market_Pages::get_market_by_slug( LNKup_Market_Pages::current_slug() );
		if ( $market && ( 'paused' === $market['launch_status'] || ! $market['indexable'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether Rank Math post meta overrides programmatic SEO.
 *
 * @param int $post_id Post ID.
 */
function lnkup_seo_has_manual_override( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return false;
	}
	$title = get_post_meta( $post_id, 'rank_math_title', true );
	$desc  = get_post_meta( $post_id, 'rank_math_description', true );
	return ( is_string( $title ) && '' !== trim( $title ) ) || ( is_string( $desc ) && '' !== trim( $desc ) );
}

/**
 * Build title + description for an opportunity post.
 *
 * @param int $post_id Opportunity or event post ID.
 * @return array{title:string,description:string}|null
 */
function lnkup_seo_opportunity_copy( $post_id ) {
	$post_id = (int) $post_id;
	$post    = get_post( $post_id );
	if ( ! $post ) {
		return null;
	}

	$opp_post = $post;
	if ( 'lnkup_event' === $post->post_type ) {
		$linked = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $post_id,
			)
		);
		if ( ! $linked ) {
			return null;
		}
		$opp_post = $linked[0];
	} elseif ( 'lnkup_opportunity' !== $post->post_type ) {
		return null;
	}

	$opp = lnkup_format_opportunity( $opp_post );
	$name = $opp['name'] ?? $opp_post->post_title;
	$city = trim( (string) ( $opp['city'] ?? '' ) );
	$state = trim( (string) ( $opp['state'] ?? '' ) );
	$location = $city;
	if ( $state ) {
		$location = $location ? $location . ', ' . $state : $state;
	}

	$spots = (int) ( $opp['spots_remaining'] ?? 0 );
	$fee   = (float) ( $opp['vendor_fee'] ?? 0 );
	$fee_label = $fee > 0 ? lnkup_currency( $fee ) : __( 'Free', 'lnkup-marketplace' );

	$title = $location
		? sprintf(
			/* translators: 1: event name, 2: city/state */
			__( '%1$s — %2$s | LNKup', 'lnkup-marketplace' ),
			$name,
			$location
		)
		: sprintf(
			/* translators: %s: event name */
			__( '%s | LNKup', 'lnkup-marketplace' ),
			$name
		);

	$description = sprintf(
		/* translators: 1: event name, 2: location, 3: spots remaining, 4: fee */
		__( 'Apply for a vendor booth at %1$s%2$s. %3$d spots left · %4$s vendor fee. Free to apply on LNKup.', 'lnkup-marketplace' ),
		$name,
		$location ? ' in ' . $location : '',
		max( 0, $spots ),
		$fee_label
	);

	return array(
		'title'       => $title,
		'description' => $description,
	);
}

/**
 * Programmatic copy for the current front-end context.
 *
 * @return array{title?:string,description?:string}|null
 */
function lnkup_seo_get_context_copy() {
	if ( is_front_page() ) {
		$launch = function_exists( 'lnkup_get_launch_date_label' ) ? lnkup_get_launch_date_label() : '';
		$desc   = __( 'LNKup is the vendor opportunity marketplace for live events — festivals, markets, rodeos, and block parties. Free to join and apply. Pay only when accepted.', 'lnkup-marketplace' );
		if ( $launch && ! lnkup_is_marketplace_open() ) {
			$desc .= ' ' . sprintf(
				/* translators: %s: launch date label */
				__( 'Full marketplace opens %s.', 'lnkup-marketplace' ),
				$launch
			);
		}
		return array(
			'title'       => __( 'Find Your Next Event | LNKup', 'lnkup-marketplace' ),
			'description' => $desc,
		);
	}

	if ( is_page( 'sign-up' ) ) {
		return array(
			'title'       => __( 'Sign Up Free | LNKup', 'lnkup-marketplace' ),
			'description' => __( 'Create your free LNKup account as a vendor or event organizer. Build your profile, browse events, and apply today.', 'lnkup-marketplace' ),
		);
	}

	if ( is_page( 'opportunities' ) && lnkup_is_marketplace_open() ) {
		return array(
			'title'       => __( 'Find Vendor Events | LNKup', 'lnkup-marketplace' ),
			'description' => __( 'Browse vendor opportunities at festivals, markets, rodeos, and cultural events. Filter by city, date, attendance, and vendor fee.', 'lnkup-marketplace' ),
		);
	}

	if ( is_page( 'vendors' ) && ! empty( $_GET['vendor'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slug = sanitize_text_field( wp_unslash( $_GET['vendor'] ) );
		$profile_id = class_exists( 'LNKup_Vendor_Reputation' ) ? LNKup_Vendor_Reputation::resolve_profile( $slug ) : 0;
		if ( $profile_id ) {
			$data = LNKup_Vendor_CRM_Profile::get_public( $profile_id );
			if ( $data ) {
				$location = trim( ( $data['city'] ?? '' ) . ( ! empty( $data['state'] ) ? ', ' . $data['state'] : '' ) );
				$score    = (int) ( $data['reputation']['score'] ?? 0 );
				return array(
					'title'       => sprintf(
						/* translators: 1: business name, 2: location */
						__( '%1$s — Vendor Profile%2$s | LNKup', 'lnkup-marketplace' ),
						$data['business_name'],
						$location ? ' · ' . $location : ''
					),
					'description' => wp_trim_words(
						$data['description'] ?: sprintf(
							/* translators: 1: business name, 2: score */
							__( 'Book %1$s on LNKup. LNKup Score %2$d/100. View reviews, event history, and reliability on the vendor marketplace.', 'lnkup-marketplace' ),
							$data['business_name'],
							$score
						),
						28,
						'…'
					),
				);
			}
		}
	}

	if ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_market_request() ) {
		$data = LNKup_Market_Pages::get_context();
		if ( ! $data ) {
			$data = LNKup_Market_Pages::get_market_page_data( LNKup_Market_Pages::current_slug() );
		}
		if ( $data && ! empty( $data['market'] ) ) {
			return array(
				'title'       => (string) ( $data['market']['seo_title'] ?? $data['headline'] ?? '' ),
				'description' => (string) ( $data['market']['seo_description'] ?? $data['description'] ?? '' ),
			);
		}
	}

	if ( is_singular( array( 'lnkup_opportunity', 'lnkup_event' ) ) ) {
		return lnkup_seo_opportunity_copy( get_queried_object_id() );
	}

	return null;
}

/**
 * Rank Math integration.
 */
class LNKup_SEO {

	/**
	 * Init hooks.
	 */
	public static function init() {
		if ( lnkup_seo_is_rankmath_active() ) {
			add_filter( 'rank_math/frontend/title', array( __CLASS__, 'filter_title' ) );
			add_filter( 'rank_math/frontend/description', array( __CLASS__, 'filter_description' ) );
			add_filter( 'rank_math/opengraph/facebook/og_title', array( __CLASS__, 'filter_title' ) );
			add_filter( 'rank_math/opengraph/facebook/og_description', array( __CLASS__, 'filter_description' ) );
			add_filter( 'rank_math/opengraph/facebook/og_image', array( __CLASS__, 'filter_og_image' ) );
			add_filter( 'rank_math/frontend/robots', array( __CLASS__, 'filter_robots' ) );
			add_filter( 'rank_math/json_ld', array( __CLASS__, 'filter_json_ld' ), 99, 2 );
			add_filter( 'rank_math/sitemap/urls', array( __CLASS__, 'append_market_sitemap_urls' ), 10, 2 );
			add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'filter_canonical' ) );
			add_filter( 'rank_math/sitemap/exclude_post', array( __CLASS__, 'exclude_sitemap_post' ), 10, 2 );
			return;
		}

		add_action( 'admin_notices', array( __CLASS__, 'rankmath_missing_notice' ) );
	}

	/**
	 * Admin notice when Rank Math is not installed.
	 */
	public static function rankmath_missing_notice() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'toplevel_page_lnkup-marketplace' ), true ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), 'lnkup_seo_notice_dismissed', true ) ) {
			return;
		}
		$install_url = admin_url( 'plugin-install.php?s=rank+math&tab=search&type=term' );
		printf(
			'<div class="notice notice-info is-dismissible" data-lnkup-seo-notice="1"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'LNKup SEO filters work best with Rank Math SEO installed.', 'lnkup-marketplace' ),
			esc_url( $install_url ),
			esc_html__( 'Install Rank Math', 'lnkup-marketplace' )
		);
	}

	/**
	 * Filter document title.
	 *
	 * @param string $title Current title.
	 */
	public static function filter_title( $title ) {
		if ( is_singular() && lnkup_seo_has_manual_override( get_queried_object_id() ) ) {
			return $title;
		}
		$copy = lnkup_seo_get_context_copy();
		return ! empty( $copy['title'] ) ? $copy['title'] : $title;
	}

	/**
	 * Filter meta description.
	 *
	 * @param string $description Current description.
	 */
	public static function filter_description( $description ) {
		if ( is_singular() && lnkup_seo_has_manual_override( get_queried_object_id() ) ) {
			return $description;
		}
		$copy = lnkup_seo_get_context_copy();
		return ! empty( $copy['description'] ) ? $copy['description'] : $description;
	}

	/**
	 * Filter OG image when none set.
	 *
	 * @param string $image Image URL.
	 */
	public static function filter_og_image( $image ) {
		if ( is_string( $image ) && '' !== trim( $image ) ) {
			return $image;
		}
		if ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_market_request() ) {
			$data = LNKup_Market_Pages::get_context();
			if ( ! $data ) {
				$data = LNKup_Market_Pages::get_market_page_data( LNKup_Market_Pages::current_slug() );
			}
			if ( $data && ! empty( $data['market']['og_image'] ) ) {
				return esc_url_raw( $data['market']['og_image'] );
			}
			if ( $data && ! empty( $data['market']['hero_image'] ) ) {
				$ver = defined( 'LNKUP_THEME_VERSION' ) ? LNKUP_THEME_VERSION : LNKUP_VERSION;
				$path = 'assets/images/events/' . sanitize_file_name( (string) $data['market']['hero_image'] );
				return add_query_arg( 'ver', $ver, get_theme_file_uri( $path ) );
			}
		}
		if ( is_page( 'vendors' ) && ! empty( $_GET['vendor'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$slug       = sanitize_text_field( wp_unslash( $_GET['vendor'] ) );
			$profile_id = class_exists( 'LNKup_Vendor_Reputation' ) ? LNKup_Vendor_Reputation::resolve_profile( $slug ) : 0;
			if ( $profile_id ) {
				$data = LNKup_Vendor_CRM_Profile::get_public( $profile_id );
				if ( $data && ! empty( $data['cover'] ) ) {
					return esc_url_raw( $data['cover'] );
				}
				if ( $data && ! empty( $data['logo'] ) ) {
					return esc_url_raw( $data['logo'] );
				}
			}
		}
		if ( is_singular( array( 'lnkup_opportunity', 'lnkup_event' ) ) ) {
			$opp = lnkup_seo_opportunity_copy( get_queried_object_id() );
			if ( $opp ) {
				$post = get_post( get_queried_object_id() );
				$opp_post = $post;
				if ( $post && 'lnkup_event' === $post->post_type ) {
					$linked = get_posts(
						array(
							'post_type'      => 'lnkup_opportunity',
							'posts_per_page' => 1,
							'meta_key'       => '_lnkup_event_id',
							'meta_value'     => $post->ID,
						)
					);
					$opp_post = $linked[0] ?? null;
				}
				if ( $opp_post ) {
					$formatted = lnkup_format_opportunity( $opp_post );
					$illus     = lnkup_opportunity_illustration_url( $formatted );
					if ( $illus ) {
						return $illus;
					}
				}
			}
		}
		return lnkup_seo_default_og_image();
	}

	/**
	 * Filter robots directives.
	 *
	 * @param array $robots Robots array.
	 */
	public static function filter_robots( $robots ) {
		if ( ! lnkup_seo_should_noindex() ) {
			return $robots;
		}
		if ( ! is_array( $robots ) ) {
			$robots = array();
		}
		$robots['index']  = 'noindex';
		$robots['follow'] = 'nofollow';
		return $robots;
	}

	/**
	 * Append Event schema for opportunity singles.
	 *
	 * @param array  $data   Schema data.
	 * @param object $jsonld JsonLD instance.
	 */
	public static function filter_json_ld( $data, $jsonld ) {
		unset( $jsonld );

		if ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_market_request() ) {
			$page_data = LNKup_Market_Pages::get_context();
			if ( ! $page_data ) {
				$page_data = LNKup_Market_Pages::get_market_page_data( LNKup_Market_Pages::current_slug() );
			}
			if ( $page_data && ! empty( $page_data['market'] ) ) {
				if ( ! isset( $data['@graph'] ) || ! is_array( $data['@graph'] ) ) {
					$data['@graph'] = array();
				}
				$market = $page_data['market'];
				$data['@graph'][] = array(
					'@type'       => 'WebPage',
					'@id'         => $market['url'] . '#webpage',
					'url'         => $market['url'],
					'name'        => $page_data['market']['seo_title'] ?? $page_data['headline'],
					'description' => $page_data['market']['seo_description'] ?? $page_data['description'],
					'isPartOf'    => array(
						'@type' => 'WebSite',
						'name'  => 'LNKup',
						'url'   => home_url( '/' ),
					),
					'publisher'   => array(
						'@type' => 'Organization',
						'name'  => 'LNKup',
						'url'   => home_url( '/' ),
					),
				);

				$items = array();
				foreach ( (array) ( $page_data['opportunities'] ?? array() ) as $i => $opp ) {
					$name = $opp['name'] ?? '';
					$url  = $opp['permalink'] ?? $opp['url'] ?? '';
					if ( ! $url && ! empty( $opp['opportunity_id'] ) ) {
						$url = get_permalink( (int) $opp['opportunity_id'] );
					}
					if ( ! $url && ! empty( $opp['id'] ) ) {
						$url = get_permalink( (int) $opp['id'] );
					}
					if ( ! $name || ! $url ) {
						continue;
					}
					$items[] = array(
						'@type'    => 'ListItem',
						'position' => $i + 1,
						'url'      => $url,
						'name'     => $name,
					);
				}
				if ( $items ) {
					$data['@graph'][] = array(
						'@type'           => 'ItemList',
						'name'            => sprintf(
							/* translators: %s: city name */
							__( 'Vendor opportunities in %s', 'lnkup-marketplace' ),
							$market['display_name']
						),
						'itemListElement' => $items,
					);
				}
				return $data;
			}
		}

		if ( ! lnkup_is_marketplace_open() || ! is_singular( array( 'lnkup_opportunity', 'lnkup_event' ) ) ) {
			return $data;
		}

		$post_id = get_queried_object_id();
		$copy    = lnkup_seo_opportunity_copy( $post_id );
		if ( ! $copy ) {
			return $data;
		}

		$post = get_post( $post_id );
		$opp_post = $post;
		if ( 'lnkup_event' === $post->post_type ) {
			$linked = get_posts(
				array(
					'post_type'      => 'lnkup_opportunity',
					'posts_per_page' => 1,
					'meta_key'       => '_lnkup_event_id',
					'meta_value'     => $post_id,
				)
			);
			$opp_post = $linked[0] ?? null;
		}
		if ( ! $opp_post ) {
			return $data;
		}

		$opp  = lnkup_format_opportunity( $opp_post );
		$fee  = (float) ( $opp['vendor_fee'] ?? 0 );
		$date = ! empty( $opp['date'] ) ? $opp['date'] : '';

		$event_schema = array(
			'@type'       => 'Event',
			'name'        => $opp['name'] ?? $opp_post->post_title,
			'description' => $copy['description'],
			'url'         => get_permalink( $opp_post ),
			'eventStatus' => 'https://schema.org/EventScheduled',
		);

		if ( $date ) {
			$event_schema['startDate'] = $date;
		}

		$city  = trim( (string) ( $opp['city'] ?? '' ) );
		$state = trim( (string) ( $opp['state'] ?? '' ) );
		if ( $city || $state ) {
			$event_schema['location'] = array(
				'@type'   => 'Place',
				'name'    => $city ?: $state,
				'address' => array(
					'@type'           => 'PostalAddress',
					'addressLocality' => $city,
					'addressRegion'   => $state,
					'addressCountry'  => 'US',
				),
			);
		}

		if ( $fee > 0 ) {
			$event_schema['offers'] = array(
				'@type'         => 'Offer',
				'price'         => (string) $fee,
				'priceCurrency' => 'USD',
				'availability'  => ( (int) ( $opp['spots_remaining'] ?? 0 ) ) > 0
					? 'https://schema.org/InStock'
					: 'https://schema.org/SoldOut',
				'url'           => get_permalink( $opp_post ),
			);
		}

		if ( ! isset( $data['@graph'] ) || ! is_array( $data['@graph'] ) ) {
			$data['@graph'] = array();
		}
		$data['@graph'][] = $event_schema;

		return $data;
	}

	/**
	 * Exclude posts from Rank Math sitemap.
	 *
	 * @param bool $exclude   Whether to exclude.
	 * @param int  $post_id   Post ID.
	 */
	public static function exclude_sitemap_post( $exclude, $post_id ) {
		if ( $exclude ) {
			return true;
		}

		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return $exclude;
		}

		$private_types = array(
			'lnkup_application',
			'lnkup_document',
			'lnkup_payment',
			'lnkup_packet',
			'lnkup_vendor_profile',
			'lnkup_vendor_note',
			'lnkup_vendor_list',
			'lnkup_vendor_invite',
			'lnkup_vendor_flag',
			'lnkup_review',
			'lnkup_dispute',
		);
		if ( in_array( $post->post_type, $private_types, true ) ) {
			return true;
		}

		if ( in_array( $post->post_type, array( 'lnkup_opportunity', 'lnkup_event' ), true ) ) {
			if ( ! lnkup_is_marketplace_open() ) {
				return true;
			}
			if ( 'lnkup_opportunity' === $post->post_type ) {
				$formatted = lnkup_format_opportunity( $post );
				if ( ! lnkup_passes_homepage_curation( $formatted ) ) {
					return true;
				}
			}
		}

		return $exclude;
	}

	/**
	 * Canonical URL for virtual market pages.
	 *
	 * @param string $canonical Canonical URL.
	 */
	public static function filter_canonical( $canonical ) {
		if ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_market_request() ) {
			return LNKup_Market_Pages::market_url( LNKup_Market_Pages::current_slug() );
		}
		return $canonical;
	}

	/**
	 * Inject enabled city market URLs into Rank Math sitemap.
	 *
	 * @param array  $urls Sitemap URLs.
	 * @param string $type Sitemap type.
	 */
	public static function append_market_sitemap_urls( $urls, $type ) {
		if ( 'page' !== $type || ! class_exists( 'LNKup_Market_Pages' ) ) {
			return $urls;
		}
		foreach ( LNKup_Market_Pages::get_sitemap_urls() as $entry ) {
			$urls[] = array(
				'loc' => $entry['loc'],
				'mod' => $entry['mod'],
			);
		}
		return $urls;
	}
}
