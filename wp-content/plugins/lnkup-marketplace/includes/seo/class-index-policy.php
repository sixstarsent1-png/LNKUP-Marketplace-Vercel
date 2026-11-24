<?php
/**
 * Single decision point for whether a public URL may be indexed and listed in sitemaps.
 *
 * Every SEO surface (robots meta in Rank Math and fallback mode, Rank Math sitemap exclusion, the
 * core sitemap provider, diagnostics) asks this class, so an event never becomes indexable just
 * because a record exists. Decisions are computed from authoritative records; nothing is stored.
 *
 * Event lifecycle:
 * - upcoming + complete + bookable                 index, sitemap
 * - sold out (still upcoming)                      index, sitemap
 * - postponed                                      index while it has an upcoming date
 * - cancelled                                      noindex,follow (page stays reachable)
 * - past <= PAST_FOLLOW_DAYS                       noindex,follow, not in sitemap
 * - past >  PAST_FOLLOW_DAYS                       noindex,follow, not in sitemap
 * - unclaimed external / disputed / restricted     noindex,follow
 * - incomplete (no title, city or date)            noindex,follow
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Index_Policy {

	const PAST_FOLLOW_DAYS = 90;

	/** Authenticity statuses that must never be indexed. */
	const BLOCKED_AUTHENTICITY = array( 'unclaimed_external', 'claim_pending', 'disputed', 'restricted' );

	/** @var array<string,array<string,mixed>> */
	private static array $memo = array();

	public static function active(): bool {
		return class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'seo_index_policy' );
	}

	/**
	 * @return array{index:bool,sitemap:bool,reason:string}
	 */
	private static function decision( bool $index, bool $sitemap, string $reason ): array {
		return array( 'index' => $index, 'sitemap' => $index && $sitemap, 'reason' => $reason );
	}

	/**
	 * @return array{index:bool,sitemap:bool,reason:string}
	 */
	public static function event( int $event_id ): array {
		$key = 'e' . $event_id;
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		self::$memo[ $key ] = self::compute_event( $event_id );
		return self::$memo[ $key ];
	}

	/**
	 * @return array{index:bool,sitemap:bool,reason:string}
	 */
	private static function compute_event( int $event_id ): array {
		$post = $event_id ? get_post( $event_id ) : null;
		if ( ! $post || 'lnkup_event' !== $post->post_type || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return self::decision( false, false, 'not_public' );
		}
		if ( function_exists( 'lnkup_is_marketplace_open' ) && ! lnkup_is_marketplace_open() ) {
			return self::decision( false, false, 'prelaunch' );
		}
		$status = (string) get_post_meta( $event_id, '_lnkup_event_status', true );
		if ( in_array( $status, array( 'cancelled', 'canceled' ), true ) ) {
			return self::decision( false, false, 'cancelled' );
		}
		if ( class_exists( 'LNKup_Event_Authenticity' ) && in_array( LNKup_Event_Authenticity::get_status( $event_id ), self::BLOCKED_AUTHENTICITY, true ) ) {
			return self::decision( false, false, 'authenticity' );
		}
		if ( class_exists( 'LNKup_Organizer_Onboarding' ) && LNKup_Organizer_Onboarding::event_is_unclaimed_external( $event_id ) ) {
			return self::decision( false, false, 'unclaimed_external' );
		}
		$title = trim( (string) $post->post_title );
		$city  = trim( (string) get_post_meta( $event_id, '_lnkup_city', true ) );
		$date  = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		if ( '' === $title || '' === $city || 'Location TBA' === $city || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $date ) ) {
			return self::decision( false, false, 'incomplete' );
		}
		$end   = (string) get_post_meta( $event_id, '_lnkup_event_end_date', true );
		$last  = preg_match( '/^\d{4}-\d{2}-\d{2}/', $end ) && $end > $date ? substr( $end, 0, 10 ) : substr( $date, 0, 10 );
		$today = current_time( 'Y-m-d' );
		if ( $last < $today ) {
			return self::decision( false, false, 'past' );
		}
		if ( 'postponed' === $status ) {
			return self::decision( true, true, 'postponed' );
		}
		return self::decision( true, true, 'upcoming' );
	}

	/**
	 * Whether a past event page should still pass link equity (noindex,follow) or drop entirely.
	 */
	public static function past_event_recent( int $event_id ): bool {
		$date = substr( (string) get_post_meta( $event_id, '_lnkup_event_date', true ), 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		return strtotime( $date ) >= strtotime( current_time( 'Y-m-d' ) . ' -' . self::PAST_FOLLOW_DAYS . ' days' );
	}

	/**
	 * Open, bookable events in a city (marketplace index; published posts only).
	 */
	public static function open_events_in_city( string $city, string $state = '' ): int {
		$key = 'c' . strtolower( $city ) . '|' . strtoupper( $state );
		if ( isset( self::$memo[ $key ] ) ) {
			return (int) self::$memo[ $key ]['n'];
		}
		$n = 0;
		if ( '' !== trim( $city ) && class_exists( 'LNKup_Marketplace_Index' ) ) {
			global $wpdb;
			$table = LNKup_Marketplace_Index::table_name();
			$today = current_time( 'Y-m-d' );
			$sql   = "SELECT COUNT(DISTINCT idx.event_id) FROM {$table} idx
				INNER JOIN {$wpdb->posts} ev ON ev.ID = idx.event_id AND ev.post_status = 'publish'
				WHERE idx.city = %s AND idx.spots_remaining > 0 AND ( idx.event_date IS NULL OR idx.event_date >= %s )";
			$args  = array( $city, $today );
			if ( '' !== $state ) {
				$sql   .= ' AND UPPER(idx.state) = %s';
				$args[] = strtoupper( $state );
			}
			$n = (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		self::$memo[ $key ] = array( 'n' => $n );
		return $n;
	}

	/**
	 * City market page: enabled, indexable, not paused, and real value (open events or
	 * market-specific copy written by staff).
	 *
	 * @param array<string,mixed> $market Normalized market.
	 * @return array{index:bool,sitemap:bool,reason:string}
	 */
	public static function market( array $market ): array {
		if ( empty( $market['enabled'] ) ) {
			return self::decision( false, false, 'disabled' );
		}
		if ( empty( $market['indexable'] ) || 'paused' === ( $market['launch_status'] ?? '' ) || 'inactive' === ( $market['status'] ?? '' ) ) {
			return self::decision( false, false, 'not_indexable' );
		}
		$open = self::open_events_in_city( (string) ( $market['city'] ?? '' ), (string) ( $market['state'] ?? '' ) );
		if ( $open > 0 ) {
			return self::decision( true, true, 'inventory' );
		}
		$custom = trim( (string) ( $market['content'] ?? '' ) );
		if ( strlen( wp_strip_all_tags( $custom ) ) >= 300 ) {
			return self::decision( true, true, 'unique_content' );
		}
		return self::decision( false, false, 'thin' );
	}

	/**
	 * Public tour/series page: published, Portfolio OS on for its owner, at least one upcoming stop.
	 *
	 * @return array{index:bool,sitemap:bool,reason:string}
	 */
	public static function tour( int $tour_id ): array {
		$post = $tour_id ? get_post( $tour_id ) : null;
		if ( ! $post || 'lnkup_tour' !== $post->post_type || 'publish' !== $post->post_status ) {
			return self::decision( false, false, 'not_public' );
		}
		$owner = (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true );
		if ( class_exists( 'LNKup_Feature_Flags' ) && ! LNKup_Feature_Flags::portfolio( $owner ) ) {
			return self::decision( false, false, 'portfolio_off' );
		}
		if ( ! class_exists( 'LNKup_Tours' ) || ! class_exists( 'LNKup_Portfolio_Tours' ) ) {
			return self::decision( false, false, 'unavailable' );
		}
		foreach ( LNKup_Tours::stop_event_ids( $tour_id ) as $event_id ) {
			if ( 'published' === LNKup_Portfolio_Tours::stop_state( (int) $event_id ) ) {
				return self::decision( true, true, 'upcoming_stop' );
			}
		}
		return self::decision( false, false, 'no_upcoming_stop' );
	}

	/**
	 * Organizer public profile: organizer role, a public name and at least one published event.
	 *
	 * @return array{index:bool,sitemap:bool,reason:string}
	 */
	public static function organizer( int $user_id ): array {
		$user = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $user || ! in_array( 'lnkup_organizer', (array) $user->roles, true ) ) {
			return self::decision( false, false, 'not_organizer' );
		}
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_organizer_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $events ? self::decision( true, true, 'has_events' ) : self::decision( false, false, 'no_events' );
	}

	/**
	 * Vendor Passport: only public Passports.
	 *
	 * @return array{index:bool,sitemap:bool,reason:string}
	 */
	public static function vendor( int $profile_id ): array {
		$public = class_exists( 'LNKup_Passport_Privacy' ) && LNKup_Passport_Privacy::is_public( $profile_id );
		return $public ? self::decision( true, true, 'public' ) : self::decision( false, false, 'private' );
	}

	/**
	 * Robots decision for the current request, or null when the policy has no opinion.
	 *
	 * @return array{index:bool,sitemap:bool,reason:string}|null
	 */
	public static function current(): ?array {
		if ( is_singular( 'lnkup_event' ) ) {
			return self::event( (int) get_queried_object_id() );
		}
		if ( is_singular( 'lnkup_opportunity' ) ) {
			return self::decision( false, false, 'opportunity_single' );
		}
		if ( is_tax( array( 'lnkup_vendor_category', 'lnkup_event_type', 'lnkup_market' ) ) ) {
			return self::decision( false, false, 'taxonomy_archive' );
		}
		if ( class_exists( 'LNKup_Portfolio_Public' ) && '' !== (string) get_query_var( LNKup_Portfolio_Public::QUERY_VAR ) ) {
			$tour_id = LNKup_Portfolio_Public::tour_id_from_slug( (string) get_query_var( LNKup_Portfolio_Public::QUERY_VAR ) );
			return self::tour( $tour_id );
		}
		if ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::is_market_request() ) {
			$market = LNKup_Market_Pages::get_market_by_slug( LNKup_Market_Pages::current_slug(), false );
			return is_array( $market ) ? self::market( $market ) : self::decision( false, false, 'unknown_market' );
		}
		$org = self::current_organizer_id();
		if ( $org > 0 ) {
			return self::organizer( $org );
		}
		if ( is_page( self::app_page_slugs() ) ) {
			return self::decision( false, false, 'app_page' );
		}
		return apply_filters( 'lnkup_index_policy_current', null );
	}

	/**
	 * App and account pages that are never indexed (in addition to lnkup_seo_noindex_page_slugs()).
	 *
	 * @return string[]
	 */
	public static function app_page_slugs(): array {
		return array( 'notification-settings', 'event-communications', 'notification-center', 'checkout', 'log-in', 'vendor-dashboard', 'organizer-dashboard', 'connector-dashboard', 'vendor-profile', 'favorites' );
	}

	/**
	 * Organizer user ID for an organizer profile request (pretty URL or legacy ?organizer=).
	 */
	public static function current_organizer_id(): int {
		if ( ! is_page( 'organizers' ) || ! class_exists( 'LNKup_Organizer_Onboarding' ) ) {
			return 0;
		}
		$slug = (string) get_query_var( 'lnkup_org_slug' );
		if ( '' === $slug && isset( $_GET['organizer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$slug = sanitize_title( wp_unslash( (string) $_GET['organizer'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return '' === $slug ? 0 : LNKup_Organizer_Onboarding::organizer_id_from_public_slug( $slug );
	}

	/**
	 * Clears request memo (tests and long-running CLI).
	 */
	public static function flush(): void {
		self::$memo = array();
	}
}
