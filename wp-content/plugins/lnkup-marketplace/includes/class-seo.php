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
	$icon = get_site_icon_url( 512 );
	if ( is_string( $icon ) && lnkup_seo_is_raster_url( $icon ) ) {
		return $icon;
	}
	return '';
}

/**
 * Query flags that are tracking, not content.
 *
 * @return string[]
 */
function lnkup_seo_tracking_params() {
	return array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'fbclid', 'gclid', 'gbraid', 'wbraid', 'mc_cid', 'mc_eid' );
}

/**
 * Find Events filters. Pagination and profile selectors are not filters.
 *
 * @return string[]
 */
function lnkup_seo_filter_params() {
	return array(
		'q',
		'city',
		'state',
		'date_preset',
		'attendance_bucket',
		'fee_bucket',
		'environment',
		'sort',
		'category',
		'categories',
		'event_types',
		'markets',
		'fee_free',
		'has_spots',
		'verified_organizer',
		'closing_soon',
		'newly_added',
		'show_closed',
		'high_demand',
		'search',
	);
}

/**
 * Whether this Find Events request is a filtered combination.
 */
function lnkup_seo_request_has_filters() {
	if ( ! is_page( 'opportunities' ) ) {
		return false;
	}
	foreach ( lnkup_seo_filter_params() as $key ) {
		if ( ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			continue;
		}
		$value = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_array( $value ) ) {
			$value = array_filter( $value );
		}
		if ( ! empty( $value ) ) {
			return true;
		}
	}
	return false;
}

/**
 * JPEG, PNG, or WebP URL.
 *
 * @param string $url Image URL.
 */
function lnkup_seo_is_raster_url( $url ) {
	$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
	$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true );
}

/**
 * Attachment URL when the file is a shareable raster.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size          Image size.
 */
function lnkup_seo_raster_attachment_url( $attachment_id, $size = 'large' ) {
	$attachment_id = absint( $attachment_id );
	if ( $attachment_id < 1 ) {
		return '';
	}
	$mime = (string) get_post_mime_type( $attachment_id );
	if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $attachment_id, $size );
	return $url ? $url : '';
}

/**
 * Canonical for the current public URL without tracking parameters.
 */
function lnkup_seo_public_canonical() {
	if ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_market_request() ) {
		return (string) apply_filters( 'lnkup_seo_market_canonical', LNKup_Market_Pages::market_url( LNKup_Market_Pages::current_slug() ) );
	}
	if ( class_exists( 'LNKup_Passport_Public' ) ) {
		$vendor_slug = LNKup_Passport_Public::current_slug();
		if ( '' !== $vendor_slug && LNKup_Vendor_Reputation::resolve_public_profile( $vendor_slug ) ) {
			return LNKup_Passport_Public::url_for_slug( $vendor_slug );
		}
	}
	$event_id = isset( $_GET['event'] ) ? absint( wp_unslash( $_GET['event'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $event_id > 0 && ( is_page() || is_singular() ) ) {
		$event = get_post( $event_id );
		if ( $event && 'lnkup_event' === $event->post_type && 'publish' === $event->post_status ) {
			$permalink = get_permalink( $event );
			if ( $permalink ) {
				return $permalink;
			}
		}
	}
	$base = '';
	if ( is_front_page() ) {
		$base = home_url( '/' );
	} elseif ( is_singular() || is_page() ) {
		$base = (string) get_permalink( get_queried_object_id() );
	}
	if ( '' === $base ) {
		return '';
	}
	$allowed = array_merge( lnkup_seo_filter_params(), array( 'page', 'vendor', 'organizer' ) );
	$keep    = array();
	foreach ( $allowed as $key ) {
		if ( ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			continue;
		}
		$value = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_array( $value ) ) {
			$value = array_values( array_filter( array_map( 'sanitize_text_field', $value ) ) );
			if ( $value ) {
				$keep[ $key ] = $value;
			}
			continue;
		}
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) {
			continue;
		}
		if ( 'page' === $key && absint( $value ) < 2 ) {
			continue;
		}
		if ( in_array( $key, array( 'vendor', 'organizer' ), true ) ) {
			$value = sanitize_title( $value );
			if ( '' === $value ) {
				continue;
			}
		}
		$keep[ $key ] = 'page' === $key ? absint( $value ) : $value;
	}
	return $keep ? add_query_arg( $keep, $base ) : $base;
}

/**
 * Slugs for pages that should never be indexed.
 *
 * @return string[]
 */
function lnkup_seo_noindex_page_slugs() {
	return array( 'vendor-dashboard', 'organizer-dashboard', 'connector-dashboard', 'vendor-profile', 'favorites', 'log-in' );
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

	if ( class_exists( 'LNKup_Index_Policy' ) && LNKup_Index_Policy::active() ) {
		$decision = LNKup_Index_Policy::current();
		if ( null !== $decision && ! $decision['index'] ) {
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

	// Same copy for crawlers, guests and social previews: booth pricing and inventory stay
	// behind sign-in (LNKup_Access_Policy), so they never appear in meta or Open Graph tags.
	$description = sprintf(
		/* translators: 1: event name, 2: location */
		__( 'Vendor applications for %1$s%2$s. See booth options and apply free on LNKup.', 'lnkup-marketplace' ),
		$name,
		$location ? ' in ' . $location : ''
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
	$pre = apply_filters( 'lnkup_seo_context_copy_pre', null );
	if ( is_array( $pre ) ) {
		return $pre;
	}
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

	if ( is_page( 'organizers' ) && ! empty( $_GET['organizer'] ) && class_exists( 'LNKup_Organizer_Onboarding' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slug    = sanitize_title( wp_unslash( $_GET['organizer'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = LNKup_Organizer_Onboarding::organizer_id_from_public_slug( $slug );
		if ( $user_id ) {
			$name = (string) get_user_meta( $user_id, '_lnkup_organizer_public_name', true );
			if ( '' === $name ) {
				$user = get_userdata( $user_id );
				$name = $user ? $user->display_name : '';
			}
			$city  = trim( (string) get_user_meta( $user_id, '_lnkup_organizer_city', true ) );
			$state = trim( (string) get_user_meta( $user_id, '_lnkup_organizer_state', true ) );
			$where = $city;
			if ( $state ) {
				$where = $where ? $where . ', ' . $state : $state;
			}
			$bio = (string) get_user_meta( $user_id, '_lnkup_organizer_bio', true );
			if ( '' === trim( $bio ) ) {
				$bio = (string) get_user_meta( $user_id, '_lnkup_organizer_tagline', true );
			}
			if ( '' === trim( $bio ) ) {
				$bio = sprintf(
					/* translators: %s: organizer name */
					__( 'Events from %s on LNKup. See upcoming vendor opportunities and past events.', 'lnkup-marketplace' ),
					$name
				);
			}
			return array(
				'title'       => sprintf(
					/* translators: 1: organizer name, 2: location */
					__( '%1$s — Organizer%2$s | LNKup', 'lnkup-marketplace' ),
					$name,
					$where ? ' · ' . $where : ''
				),
				'description' => wp_trim_words( $bio, 28, '…' ),
			);
		}
	}

	$vendor_slug = class_exists( 'LNKup_Passport_Public' ) ? LNKup_Passport_Public::current_slug() : '';
	if ( '' !== $vendor_slug ) {
		$profile_id = LNKup_Vendor_Reputation::resolve_public_profile( $vendor_slug );
		if ( $profile_id ) {
			$data = LNKup_Vendor_CRM_Profile::get_public( $profile_id );
			if ( $data ) {
				$location = trim( ( $data['city'] ?? '' ) . ( ! empty( $data['state'] ) ? ', ' . $data['state'] : '' ) );
				return array(
					'title'       => sprintf(
						/* translators: 1: business name, 2: location */
						__( '%1$s — Vendor Profile%2$s | LNKup', 'lnkup-marketplace' ),
						$data['business_name'],
						$location ? ' · ' . $location : ''
					),
					'description' => wp_trim_words(
						$data['description'] ?: sprintf(
							/* translators: %s: business name */
							__( 'Book %s on LNKup. View their portfolio, verified event history, and organizer reviews on the vendor marketplace.', 'lnkup-marketplace' ),
							$data['business_name']
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
		$copy = lnkup_seo_opportunity_copy( get_queried_object_id() );
		if ( $copy && class_exists( 'LNKup_Index_Policy' ) && LNKup_Index_Policy::active() && is_singular( 'lnkup_event' ) ) {
			$reason = LNKup_Index_Policy::event( get_queried_object_id() )['reason'];
			$name   = function_exists( 'lnkup_plain_title' ) ? lnkup_plain_title( get_queried_object_id() ) : get_the_title();
			if ( 'cancelled' === $reason ) {
				/* translators: %s: event name */
				$copy['description'] = sprintf( __( '%s has been cancelled. See upcoming events from this organizer and other vendor opportunities nearby on LNKup.', 'lnkup-marketplace' ), $name );
			} elseif ( 'past' === $reason ) {
				/* translators: %s: event name */
				$copy['description'] = sprintf( __( '%s has ended. See upcoming events from this organizer and other vendor opportunities on LNKup.', 'lnkup-marketplace' ), $name );
			} elseif ( 'postponed' === $reason ) {
				/* translators: %s: event name */
				$copy['description'] = sprintf( __( '%s has been postponed. Follow the organizer on LNKup for the new date and vendor applications.', 'lnkup-marketplace' ), $name );
			}
		}
		return $copy;
	}

	if ( class_exists( 'LNKup_Portfolio_Public' ) && '' !== (string) get_query_var( LNKup_Portfolio_Public::QUERY_VAR ) && class_exists( 'LNKup_Index_Policy' ) && LNKup_Index_Policy::active() ) {
		$tour_id = LNKup_Portfolio_Public::tour_id_from_slug( (string) get_query_var( LNKup_Portfolio_Public::QUERY_VAR ) );
		if ( $tour_id ) {
			$type  = 'tour' === get_post_meta( $tour_id, '_lnkup_tour_type', true ) ? __( 'Tour', 'lnkup-marketplace' ) : __( 'Series', 'lnkup-marketplace' );
			$about = trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $tour_id ) ) );
			return array(
				/* translators: 1: tour name, 2: Tour or Series */
				'title'       => sprintf( __( '%1$s — %2$s Vendor Opportunities | LNKup', 'lnkup-marketplace' ), get_the_title( $tour_id ), $type ),
				'description' => '' !== $about
					? wp_trim_words( $about, 28, '…' )
					/* translators: %s: tour name */
					: sprintf( __( 'Vendor opportunities at every stop of %s. See dates, cities and booth availability, then apply on LNKup.', 'lnkup-marketplace' ), get_the_title( $tour_id ) ),
			);
		}
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
			add_filter( 'get_canonical_url', array( __CLASS__, 'filter_wp_canonical' ), 10, 2 );
			add_filter( 'rank_math/sitemap/exclude_post', array( __CLASS__, 'exclude_sitemap_post' ), 10, 2 );
			return;
		}

		add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_title' ) );
		add_filter( 'get_canonical_url', array( __CLASS__, 'filter_wp_canonical' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'filter_wp_robots' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_fallback_meta' ), 1 );
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
			if ( lnkup_seo_is_rankmath_active() ) {
				return $title;
			}
			$manual = get_post_meta( get_queried_object_id(), 'rank_math_title', true );
			if ( is_string( $manual ) && '' !== trim( $manual ) ) {
				return $manual;
			}
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
			if ( lnkup_seo_is_rankmath_active() ) {
				return $description;
			}
			$manual = get_post_meta( get_queried_object_id(), 'rank_math_description', true );
			if ( is_string( $manual ) && '' !== trim( $manual ) ) {
				return $manual;
			}
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
		$generated = (string) apply_filters( 'lnkup_seo_og_image_pre', '' );
		if ( '' !== $generated ) {
			return $generated;
		}
		if ( is_string( $image ) && lnkup_seo_is_raster_url( $image ) ) {
			return $image;
		}
		$chosen = '';
		if ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_market_request() ) {
			$data = LNKup_Market_Pages::get_context();
			if ( ! $data ) {
				$data = LNKup_Market_Pages::get_market_page_data( LNKup_Market_Pages::current_slug() );
			}
			if ( $data && ! empty( $data['market']['og_image'] ) && lnkup_seo_is_raster_url( $data['market']['og_image'] ) ) {
				$chosen = esc_url_raw( $data['market']['og_image'] );
			}
		}
		if ( '' === $chosen && is_page( 'organizers' ) && ! empty( $_GET['organizer'] ) && class_exists( 'LNKup_Organizer_Onboarding' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$user_id = LNKup_Organizer_Onboarding::organizer_id_from_public_slug( sanitize_title( wp_unslash( $_GET['organizer'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $user_id ) {
				$chosen = lnkup_seo_raster_attachment_url( (int) get_user_meta( $user_id, '_lnkup_organizer_cover_id', true ) );
				if ( '' === $chosen ) {
					$chosen = lnkup_seo_raster_attachment_url( (int) get_user_meta( $user_id, '_lnkup_organizer_logo_id', true ) );
				}
			}
		}
		$vendor_slug = class_exists( 'LNKup_Passport_Public' ) ? LNKup_Passport_Public::current_slug() : '';
		if ( '' === $chosen && '' !== $vendor_slug ) {
			$profile_id = LNKup_Vendor_Reputation::resolve_public_profile( $vendor_slug );
			if ( $profile_id ) {
				$chosen = lnkup_seo_raster_attachment_url( (int) get_post_meta( $profile_id, '_lnkup_cover_image_id', true ) );
				if ( '' === $chosen ) {
					$chosen = lnkup_seo_raster_attachment_url( (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true ) );
				}
			}
		}
		if ( '' === $chosen && is_singular( array( 'lnkup_opportunity', 'lnkup_event' ) ) ) {
			$post_id  = get_queried_object_id();
			$event_id = 'lnkup_event' === get_post_type( $post_id ) ? $post_id : (int) get_post_meta( $post_id, '_lnkup_event_id', true );
			if ( $event_id && class_exists( 'LNKup_Event_Media' ) ) {
				// og:image must be raster: uploads use the full image; logos and generated covers use event-type JPG art.
				$og     = (string) LNKup_Event_Media::resolve( $event_id )['og_url'];
				$chosen = function_exists( 'lnkup_seo_is_raster_url' ) && lnkup_seo_is_raster_url( $og ) ? $og : '';
			} else {
				$image_id = $event_id ? (int) get_post_meta( $event_id, '_lnkup_featured_image_id', true ) : 0;
				if ( ! $image_id && $event_id ) {
					$image_id = (int) get_post_thumbnail_id( $event_id );
				}
				$chosen = lnkup_seo_raster_attachment_url( $image_id );
			}
		}
		if ( '' === $chosen ) {
			$chosen = lnkup_seo_default_og_image();
		}
		return $chosen;
	}

	/**
	 * Filter robots directives.
	 *
	 * @param array $robots Robots array.
	 */
	public static function filter_robots( $robots ) {
		$directives = is_array( $robots ) ? $robots : array();
		if ( lnkup_seo_request_has_filters() && ! lnkup_seo_should_noindex() ) {
			$directives['index']  = 'noindex';
			$directives['follow'] = 'follow';
			return $directives;
		}
		if ( ! lnkup_seo_should_noindex() ) {
			return $robots;
		}
		$directives['index']  = 'noindex';
		$directives['follow'] = self::policy_follows() ? 'follow' : 'nofollow';
		return $directives;
	}

	/**
	 * Policy-driven noindex pages (past/cancelled events, thin markets…) still pass link equity.
	 */
	private static function policy_follows(): bool {
		if ( ! class_exists( 'LNKup_Index_Policy' ) || ! LNKup_Index_Policy::active() ) {
			return false;
		}
		$decision = LNKup_Index_Policy::current();
		return null !== $decision && ! $decision['index'] && ! in_array( $decision['reason'], array( 'app_page', 'not_public' ), true );
	}

	/**
	 * Fallback mode: feed our robots decision into core's single robots tag (no duplicate tag).
	 *
	 * @param array<string,bool|string> $robots Core robots.
	 * @return array<string,bool|string>
	 */
	public static function filter_wp_robots( $robots ) {
		$robots     = is_array( $robots ) ? $robots : array();
		$directives = self::filter_robots( array() );
		if ( ! empty( $directives['index'] ) && 'noindex' === $directives['index'] ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
			if ( 'nofollow' === ( $directives['follow'] ?? '' ) ) {
				$robots['nofollow'] = true;
				unset( $robots['follow'] );
			} else {
				$robots['follow'] = true;
			}
		}
		return $robots;
	}

	/**
	 * Titles, descriptions, robots, canonical, and Open Graph when Rank Math is inactive.
	 */
	public static function print_fallback_meta() {
		if ( is_admin() ) {
			return;
		}
		$description = self::filter_description( '' );
		if ( $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
		$fallback_url = is_singular() ? (string) get_permalink( get_queried_object_id() ) : home_url( '/' );
		$canonical    = self::filter_canonical( $fallback_url );
		if ( $canonical ) {
			$market_page = class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_market_request();
			$tour_page   = class_exists( 'LNKup_Portfolio_Public' ) && '' !== (string) get_query_var( LNKup_Portfolio_Public::QUERY_VAR );
			if ( ! is_singular() && ( $market_page || $tour_page ) ) {
				if ( $tour_page && ! $market_page ) {
					$tour_id   = LNKup_Portfolio_Public::tour_id_from_slug( (string) get_query_var( LNKup_Portfolio_Public::QUERY_VAR ) );
					$canonical = $tour_id ? LNKup_Portfolio_Public::tour_url( $tour_id ) : $canonical;
				}
				echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
			}
			echo '<meta property="og:url" content="' . esc_url( $canonical ) . '" />' . "\n";
		}
		$title = self::filter_title( '' );
		if ( $title ) {
			echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		}
		if ( $description ) {
			echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
		echo '<meta property="og:type" content="website" />' . "\n";
		$image = self::filter_og_image( '' );
		if ( $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
		}
		$data = self::filter_json_ld(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array(),
			),
			null
		);
		if ( ! empty( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $data ) . '</script>' . "\n";
		}
	}

	/**
	 * Append Event schema for opportunity singles.
	 *
	 * @param array  $data   Schema data.
	 * @param object $jsonld JsonLD instance.
	 */
	public static function filter_json_ld( $data, $jsonld ) {
		if ( ! class_exists( 'LNKup_Index_Policy' ) || ! LNKup_Index_Policy::active() ) {
			return self::legacy_json_ld( $data, $jsonld );
		}
		if ( ! isset( $data['@graph'] ) || ! is_array( $data['@graph'] ) ) {
			$data['@graph'] = array();
		}
		if ( ! is_singular( array( 'lnkup_event', 'lnkup_opportunity' ) ) ) {
			$data = self::legacy_json_ld( $data, $jsonld );
		}
		foreach ( LNKup_SEO_Schema::current_graph() as $piece ) {
			$data['@graph'][] = $piece;
		}
		return $data;
	}

	/**
	 * Schema before the index policy (market WebPage/ItemList, basic Event).
	 *
	 * @param array  $data   Schema data.
	 * @param object $jsonld JsonLD instance.
	 */
	private static function legacy_json_ld( $data, $jsonld ) {
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
					if ( ! $url && ! empty( $opp['event_id'] ) && class_exists( 'LNKup_Organizer_Onboarding' ) ) {
						$url = LNKup_Organizer_Onboarding::event_public_url( (int) $opp['event_id'] );
					}
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
		$date = ! empty( $opp['date'] ) ? $opp['date'] : '';

		$event_id     = (int) ( $opp['event_id'] ?? 0 );
		$stored       = $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_status', true ) : '';
		$event_status = in_array( $stored, array( 'cancelled', 'canceled' ), true )
			? 'https://schema.org/EventCancelled'
			: 'https://schema.org/EventScheduled';
		$event_schema = array(
			'@type'       => 'Event',
			'name'        => $opp['name'] ?? $opp_post->post_title,
			'description' => $copy['description'],
			'url'         => get_permalink( $event_id ? $event_id : $opp_post ),
			'eventStatus' => $event_status,
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

		if ( 'page' === $post->post_type && in_array( $post->post_name, lnkup_seo_noindex_page_slugs(), true ) ) {
			return true;
		}

		if ( class_exists( 'LNKup_Index_Policy' ) && LNKup_Index_Policy::active() ) {
			if ( 'page' === $post->post_type && in_array( $post->post_name, LNKup_Index_Policy::app_page_slugs(), true ) ) {
				return true;
			}
			if ( 'lnkup_opportunity' === $post->post_type ) {
				return true;
			}
			if ( 'lnkup_event' === $post->post_type ) {
				return ! LNKup_Index_Policy::event( (int) $post->ID )['sitemap'];
			}
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
	 * Core canonical filter. Rank Math prints its own tag from filter_canonical().
	 *
	 * @param string  $canonical Canonical URL.
	 * @param WP_Post $post      Queried post.
	 */
	public static function filter_wp_canonical( $canonical, $post ) {
		unset( $post );
		return self::filter_canonical( $canonical );
	}

	/**
	 * Canonical URL for public marketplace screens.
	 *
	 * @param string $canonical Canonical URL.
	 */
	public static function filter_canonical( $canonical ) {
		if ( is_singular() ) {
			$manual = get_post_meta( get_queried_object_id(), 'rank_math_canonical_url', true );
			if ( is_string( $manual ) && '' !== trim( $manual ) ) {
				return $manual;
			}
		}
		$managed = ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_market_request() )
			|| is_page( array( 'opportunities', 'vendors', 'organizers' ) )
			|| is_singular( array( 'lnkup_event', 'lnkup_opportunity' ) );
		if ( ! $managed ) {
			foreach ( lnkup_seo_tracking_params() as $key ) {
				if ( ! empty( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$managed = true;
					break;
				}
			}
		}
		if ( ! $managed ) {
			return $canonical;
		}
		$public = lnkup_seo_public_canonical();
		return $public ? $public : $canonical;
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
		if ( class_exists( 'LNKup_Index_Policy' ) && LNKup_Index_Policy::active() ) {
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
