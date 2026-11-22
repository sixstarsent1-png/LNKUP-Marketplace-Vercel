<?php
/**
 * Public Vendor Passport routing: /vendors/{slug}, legacy ?vendor= redirects, JSON-LD.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Passport_Public {

	const QUERY_VAR      = 'lnkup_vendor_slug';
	const REWRITE_OPTION = 'lnkup_passport_rewrite_version';
	const REWRITE_VERSION = '1';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ), 11 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_legacy_query' ), 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'print_json_ld' ), 30 );
	}

	public static function register_rewrite(): void {
		add_rewrite_rule( '^vendors/([a-z0-9][a-z0-9\-]{0,190})/?$', 'index.php?pagename=vendors&' . self::QUERY_VAR . '=$matches[1]', 'top' );
		if ( get_option( self::REWRITE_OPTION ) !== self::REWRITE_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
		}
	}

	/**
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Vendor slug for the current request (pretty URL first, then legacy ?vendor=).
	 */
	public static function current_slug(): string {
		$pretty = (string) get_query_var( self::QUERY_VAR );
		if ( '' !== $pretty ) {
			return sanitize_title( $pretty );
		}
		if ( ! empty( $_GET['vendor'] ) && function_exists( 'is_page' ) && is_page( 'vendors' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_title( wp_unslash( (string) $_GET['vendor'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return '';
	}

	/**
	 * Canonical public Passport URL.
	 *
	 * @param string $slug Public slug.
	 */
	public static function url_for_slug( string $slug ): string {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return '';
		}
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( user_trailingslashit( 'vendors/' . $slug ) );
		}
		return add_query_arg( 'vendor', $slug, lnkup_page_url_helper( 'vendors' ) );
	}

	/**
	 * Public URL for a profile (empty when the profile has no slug).
	 *
	 * @param int $profile_id Vendor profile ID.
	 */
	public static function url_for_profile( int $profile_id ): string {
		$slug = (string) get_post_meta( $profile_id, '_lnkup_public_slug', true );
		if ( '' === $slug && class_exists( 'LNKup_Vendor_Reputation' ) ) {
			$slug = (string) LNKup_Vendor_Reputation::ensure_public_slug( $profile_id );
		}
		return self::url_for_slug( $slug );
	}

	/**
	 * 301 /vendors/?vendor={slug} to /vendors/{slug}. Numeric values never redirect (no ID → slug oracle).
	 */
	public static function redirect_legacy_query(): void {
		if ( empty( $_GET['vendor'] ) || '' !== (string) get_query_var( self::QUERY_VAR ) || ! is_page( 'vendors' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! get_option( 'permalink_structure' ) ) {
			return;
		}
		$raw = wp_unslash( (string) $_GET['vendor'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_numeric( $raw ) ) {
			return;
		}
		$slug = sanitize_title( $raw );
		if ( '' === $slug || ! LNKup_Vendor_Reputation::resolve_public_profile( $slug ) ) {
			return;
		}
		wp_safe_redirect( self::url_for_slug( $slug ), 301 );
		exit;
	}

	/**
	 * Private, previewed or unknown Passports are never indexed.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function robots( $robots ): array {
		$robots = is_array( $robots ) ? $robots : array();
		$slug   = self::current_slug();
		if ( '' === $slug ) {
			return $robots;
		}
		if ( ! LNKup_Vendor_Reputation::resolve_public_profile( $slug ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['index'], $robots['follow'] );
		}
		return $robots;
	}

	/**
	 * schema.org LocalBusiness JSON-LD from the public projection only.
	 */
	public static function print_json_ld(): void {
		$slug = self::current_slug();
		if ( '' === $slug || ! class_exists( 'LNKup_Passport_Projection' ) ) {
			return;
		}
		$profile_id = LNKup_Vendor_Reputation::resolve_public_profile( $slug );
		if ( ! $profile_id ) {
			return;
		}
		$data = LNKup_Passport_Projection::public_view( $profile_id );
		if ( ! $data ) {
			return;
		}
		$ld = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'LocalBusiness',
			'name'        => $data['business_name'],
			'url'         => self::url_for_slug( $slug ),
			'description' => wp_trim_words( wp_strip_all_tags( (string) $data['description'] ), 40, '…' ),
		);
		if ( ! empty( $data['logo'] ) ) {
			$ld['logo'] = $data['logo'];
		}
		if ( ! empty( $data['cover'] ) ) {
			$ld['image'] = $data['cover'];
		}
		if ( ! empty( $data['city'] ) || ! empty( $data['state'] ) ) {
			$ld['address'] = array(
				'@type'           => 'PostalAddress',
				'addressLocality' => (string) $data['city'],
				'addressRegion'   => (string) $data['state'],
			);
		}
		$same_as = array_values( array_filter( array( $data['website'] ?? '', $data['socials']['instagram_url'] ?? '', $data['socials']['facebook_url'] ?? '', $data['socials']['tiktok_url'] ?? '' ) ) );
		if ( $same_as ) {
			$ld['sameAs'] = $same_as;
		}
		$rating = $data['reputation']['organizer_feedback'] ?? array();
		if ( ! empty( $rating['display_average'] ) ) {
			$ld['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (float) $rating['average'],
				'reviewCount' => (int) $rating['count'],
				'bestRating'  => 5,
				'worstRating' => 1,
			);
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $ld, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
