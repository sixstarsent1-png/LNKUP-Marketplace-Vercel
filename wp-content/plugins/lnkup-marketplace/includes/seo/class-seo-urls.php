<?php
/**
 * Canonical public URLs (flag seo_index_policy):
 * - events live at /events/{slug}/; /lnkup_event/{slug}/ 301s there
 * - opportunity singles 301 to their event (#opp-{id})
 * - organizer profiles live at /organizers/{slug}/; ?organizer={slug} 301s there
 * - tours and series answer only on their canonical prefix (/tours/ or /series/)
 * Rewrite rules are flushed once whenever the URL configuration signature changes.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_SEO_URLs {

	const EVENT_BASE     = 'events';
	const ORG_QUERY_VAR  = 'lnkup_org_slug';
	const LEGACY_VAR     = 'lnkup_legacy_event';
	const SIGNATURE_OPT  = 'lnkup_seo_rewrite_signature';
	const VERSION        = '3';

	public static function init(): void {
		add_filter( 'register_post_type_args', array( __CLASS__, 'event_args' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'rewrites' ), 20 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 999 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'parse_request', array( __CLASS__, 'map_organizer_slug' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirects' ), 1 );
		add_filter( 'lnkup_organizer_public_url', array( __CLASS__, 'organizer_url' ), 10, 3 );
		add_filter( 'get_canonical_url', array( __CLASS__, 'canonical' ), 30 );
		add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'canonical' ), 30 );
		add_action( 'lnkup_marketing_flag_changed', array( __CLASS__, 'on_flag_changed' ) );
	}

	private static function active(): bool {
		return class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'seo_index_policy' );
	}

	/**
	 * @param array<string,mixed> $args Post type args.
	 * @param string              $type Post type.
	 * @return array<string,mixed>
	 */
	public static function event_args( $args, $type ) {
		if ( 'lnkup_event' === $type && self::active() ) {
			$args['rewrite'] = array( 'slug' => self::EVENT_BASE, 'with_front' => false );
		}
		return $args;
	}

	public static function rewrites(): void {
		if ( ! self::active() ) {
			return;
		}
		add_rewrite_rule( '^lnkup_event/([^/]+)/?$', 'index.php?lnkup_event=$matches[1]&' . self::LEGACY_VAR . '=1', 'top' );
		add_rewrite_rule( '^organizers/([a-z0-9][a-z0-9-]{0,190})/?$', 'index.php?pagename=organizers&' . self::ORG_QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::ORG_QUERY_VAR;
		$vars[] = self::LEGACY_VAR;
		return $vars;
	}

	/**
	 * Signature of everything that changes rewrite rules owned by SEO and market pages.
	 */
	public static function signature(): string {
		$markets = class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_enabled() ? '1' : '0';
		$cats    = class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'market_category_pages' ) ? '1' : '0';
		$hub     = class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'resources_hub' ) ? '1' : '0';
		$links   = class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'tracking_links' ) ? '1' : '0';
		return self::VERSION . '|' . ( self::active() ? '1' : '0' ) . '|m' . $markets . '|c' . $cats . '|h' . $hub . '|l' . $links;
	}

	/**
	 * Flush once per configuration change. Also fixes the historic /markets/ 404 after deploys.
	 */
	public static function maybe_flush(): void {
		$sig = self::signature();
		if ( get_option( self::SIGNATURE_OPT ) === $sig ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::SIGNATURE_OPT, $sig, false );
	}

	public static function on_flag_changed(): void {
		delete_option( self::SIGNATURE_OPT );
	}

	/**
	 * Existing organizer page code reads $_GET['organizer']; feed it from the pretty URL.
	 *
	 * @param WP $wp Request.
	 */
	public static function map_organizer_slug( $wp ): void {
		if ( ! empty( $wp->query_vars[ self::ORG_QUERY_VAR ] ) && empty( $_GET['organizer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$_GET['organizer'] = sanitize_title( (string) $wp->query_vars[ self::ORG_QUERY_VAR ] );
		}
	}

	public static function organizer_pretty_url( string $slug ): string {
		$slug = sanitize_title( $slug );
		return '' === $slug ? '' : home_url( '/organizers/' . $slug . '/' );
	}

	/**
	 * @param string $url     Current URL.
	 * @param int    $user_id Organizer.
	 * @param string $slug    Public slug.
	 */
	public static function organizer_url( $url, $user_id, $slug ) {
		unset( $user_id );
		return self::active() && get_option( 'permalink_structure' ) ? self::organizer_pretty_url( (string) $slug ) : $url;
	}

	public static function redirects(): void {
		if ( ! self::active() || is_admin() || ! get_option( 'permalink_structure' ) ) {
			return;
		}
		$keep = self::kept_query();

		if ( get_query_var( self::LEGACY_VAR ) && is_singular( 'lnkup_event' ) ) {
			self::go( (string) get_permalink( get_queried_object_id() ), $keep );
		}

		if ( is_singular( 'lnkup_opportunity' ) ) {
			$opp_id   = (int) get_queried_object_id();
			$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
			if ( $event_id && 'publish' === get_post_status( $event_id ) ) {
				self::go( (string) get_permalink( $event_id ), $keep, 'opp-' . $opp_id );
			}
		}

		if ( is_page( 'organizers' ) && '' === (string) get_query_var( self::ORG_QUERY_VAR ) && ! empty( $_GET['organizer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$slug = sanitize_title( wp_unslash( (string) $_GET['organizer'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' !== $slug && class_exists( 'LNKup_Organizer_Onboarding' ) && LNKup_Organizer_Onboarding::organizer_id_from_public_slug( $slug ) ) {
				unset( $keep['organizer'] );
				self::go( self::organizer_pretty_url( $slug ), $keep );
			}
		}

		if ( class_exists( 'LNKup_Portfolio_Public' ) ) {
			$tour_slug = (string) get_query_var( LNKup_Portfolio_Public::QUERY_VAR );
			if ( '' !== $tour_slug ) {
				$tour_id = LNKup_Portfolio_Public::tour_id_from_slug( $tour_slug );
				$target  = $tour_id ? LNKup_Portfolio_Public::tour_url( $tour_id ) : '';
				$path    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
				if ( '' !== $target && trailingslashit( $path ) !== trailingslashit( (string) wp_parse_url( $target, PHP_URL_PATH ) ) ) {
					self::go( $target, $keep );
				}
			}
		}
	}

	/**
	 * Query args worth carrying through a redirect (campaign and referral context).
	 *
	 * @return array<string,string>
	 */
	private static function kept_query(): array {
		$keep = array();
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$key = sanitize_key( (string) $key );
			if ( is_scalar( $value ) && ( 0 === strpos( $key, 'utm_' ) || in_array( $key, array( 'ref', 'org_ref', 'cc', 'partner', 'fbclid', 'gclid', 'ttclid', 'lnk', 'apply', 'organizer' ), true ) ) ) {
				$keep[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}
		return $keep;
	}

	/**
	 * @param array<string,string> $keep Query args.
	 */
	private static function go( string $target, array $keep, string $fragment = '' ): void {
		if ( '' === $target ) {
			return;
		}
		$url = $keep ? add_query_arg( array_map( 'rawurlencode', $keep ), $target ) : $target;
		if ( '' !== $fragment ) {
			$url .= '#' . $fragment;
		}
		wp_safe_redirect( $url, 301, 'LNKup' );
		exit;
	}

	/**
	 * Organizer profiles canonicalize to the pretty URL (never the query form).
	 *
	 * @param string $canonical Canonical.
	 */
	public static function canonical( $canonical ) {
		if ( ! self::active() || ! class_exists( 'LNKup_Index_Policy' ) ) {
			return $canonical;
		}
		$org = LNKup_Index_Policy::current_organizer_id();
		if ( $org > 0 && class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			return self::organizer_pretty_url( LNKup_Organizer_Onboarding::organizer_public_slug( $org ) );
		}
		return $canonical;
	}
}
