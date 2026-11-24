<?php
/**
 * App deep links v2 (flag deep_links_v2).
 *
 * - Extra universal-link paths (/opportunities, /events/*, /markets/*, /tours/*, /l/*, profile slugs)
 *   are added to the AASA file only when the flag is on, so older app builds that cannot route
 *   them never receive those links.
 * - GET lnkup/v1/public/resolve maps a public web path to an app route. Slugs are resolved here so
 *   the app never guesses IDs; /l/{code} counts the click and returns the link's campaign touch so
 *   the app can send it with signup (same attribution as a web click).
 * - GET lnkup/v1/public/events/{id} is the read-only event payload for the app's public event
 *   screen: the same guest-safe fields as the public event page (no fees, inventory or private
 *   venue details).
 * - POST lnkup/v1/app/share-link returns a tracked link for native share (reused per target).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_App_Deep_Links {

	const NS          = 'lnkup/v1';
	const APP_PATHS   = array( '/opportunities', '/opportunities/', '/events/*', '/markets/*', '/tours/*', '/series/*', '/l/*', '/vendors/*', '/organizers/*' );
	const CAMPAIGN    = 'lnkup_app_campaign';
	const RATE_LIMIT  = 120;
	const SHARE_TYPES = array( 'event', 'opportunity', 'tour', 'organizer', 'market', 'signup' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'lnkup_app_links_paths', array( __CLASS__, 'apple_paths' ) );
		add_filter( 'lnkup_app_store_url', array( __CLASS__, 'store_url' ), 10, 2 );
	}

	public static function active(): bool {
		return class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'deep_links_v2' );
	}

	/**
	 * @param string[] $paths AASA paths.
	 * @return string[]
	 */
	public static function apple_paths( $paths ): array {
		$paths = is_array( $paths ) ? $paths : array();
		return self::active() ? array_values( array_unique( array_merge( $paths, self::APP_PATHS ) ) ) : $paths;
	}

	/* ------------------------------------------------------------ App Store campaign tokens */

	/**
	 * Saved App Store Connect campaign settings: pt (provider token) and mt (media type).
	 *
	 * @return array{pt:string,mt:string}
	 */
	public static function campaign(): array {
		$stored = get_option( self::CAMPAIGN, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'pt' => (string) preg_replace( '/[^0-9]/', '', (string) ( $stored['pt'] ?? '' ) ),
			'mt' => (string) preg_replace( '/[^0-9]/', '', (string) ( $stored['mt'] ?? '8' ) ),
		);
	}

	/**
	 * @param array<string,mixed> $input Raw input.
	 */
	public static function save_campaign( array $input ): void {
		$pt = substr( (string) preg_replace( '/[^0-9]/', '', (string) ( $input['pt'] ?? '' ) ), 0, 20 );
		$mt = substr( (string) preg_replace( '/[^0-9]/', '', (string) ( $input['mt'] ?? '8' ) ), 0, 3 );
		update_option( self::CAMPAIGN, array( 'pt' => $pt, 'mt' => '' !== $mt ? $mt : '8' ), false );
	}

	/**
	 * Adds pt/mt to App Store links that carry a campaign token (ct), so App Store Connect can
	 * attribute downloads to a campaign. Without a provider token the URL is unchanged.
	 *
	 * @param string $url     Store URL.
	 * @param string $context Campaign context.
	 */
	public static function store_url( $url, $context = '' ): string {
		$url      = (string) $url;
		$campaign = self::campaign();
		if ( '' === $campaign['pt'] || '' === (string) $context ) {
			return $url;
		}
		return add_query_arg( array( 'pt' => $campaign['pt'], 'mt' => $campaign['mt'] ), $url );
	}

	/* ------------------------------------------------------------ REST */

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/public/resolve',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_resolve' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'path' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/public/events/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_event' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/app/share-link',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_share_link' ),
				'permission_callback' => static fn() => is_user_logged_in(),
			)
		);
	}

	private static function rate_limited(): bool {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key  = 'lnkup_app_dl_rl_' . substr( hash( 'sha256', $ip . wp_salt( 'nonce' ) ), 0, 20 );
		$hits = (int) get_transient( $key );
		if ( $hits >= self::RATE_LIMIT ) {
			return true;
		}
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
		return false;
	}

	private static function disabled(): WP_Error {
		return new WP_Error( 'lnkup_deep_links_off', __( 'This link is not available in the app yet.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_resolve( WP_REST_Request $request ) {
		if ( ! self::active() ) {
			return self::disabled();
		}
		if ( self::rate_limited() ) {
			return new WP_Error( 'lnkup_rate_limited', __( 'Too many requests. Try again later.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$ua     = (string) $request->get_header( 'user_agent' );
		$result = self::resolve( (string) $request->get_param( 'path' ), $ua );
		$response = new WP_REST_Response( array( 'success' => true, 'data' => $result ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Map a public web path to an app route. Unknown or private targets fall back to the feed.
	 *
	 * @return array{route:string,id:int,params:array<string,string>,attribution:array<string,string>}
	 */
	public static function resolve( string $raw, string $ua = '' ): array {
		$parts = wp_parse_url( $raw );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query = array();
		if ( is_array( $parts ) && isset( $parts['query'] ) ) {
			wp_parse_str( (string) $parts['query'], $query );
		}
		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
		$out      = array( 'route' => 'feed', 'id' => 0, 'params' => array(), 'attribution' => array() );
		$first    = $segments[0] ?? '';
		$slug     = sanitize_title( (string) ( $segments[1] ?? '' ) );

		switch ( $first ) {
			case 'events':
			case 'lnkup_event':
				$event = '' !== $slug ? get_page_by_path( $slug, OBJECT, 'lnkup_event' ) : null;
				if ( $event && self::event_visible( (int) $event->ID ) ) {
					return array_merge( $out, array( 'route' => 'event', 'id' => (int) $event->ID ) );
				}
				return $out;
			case 'tours':
			case 'series':
				$tour = '' !== $slug && class_exists( 'LNKup_Portfolio_Public' ) ? (int) LNKup_Portfolio_Public::tour_id_from_slug( $slug ) : 0;
				return $tour ? array_merge( $out, array( 'route' => 'tour', 'id' => $tour ) ) : $out;
			case 'markets':
				return array_merge( $out, array( 'params' => self::market_params( $slug, sanitize_title( (string) ( $segments[2] ?? '' ) ) ) ) );
			case 'opportunities':
				$apply = absint( $query['apply'] ?? 0 );
				return $apply && class_exists( 'LNKup_Public_Embed' ) && LNKup_Public_Embed::visible( $apply ) ? array_merge( $out, array( 'route' => 'opportunity', 'id' => $apply ) ) : $out;
			case 'l':
				return self::resolve_link( (string) ( $segments[1] ?? '' ), $ua, $out );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $out Default result.
	 * @return array<string,mixed>
	 */
	private static function resolve_link( string $code, string $ua, array $out ): array {
		if ( ! class_exists( 'LNKup_Tracking_Links' ) || ! LNKup_Tracking_Links::active() ) {
			return $out;
		}
		$row = LNKup_Tracking_Links::get_by_code( $code );
		if ( ! $row || 'active' !== $row['status'] ) {
			return $out;
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		LNKup_Tracking_Links::count_click( $row, '' !== $ua ? $ua : 'LNKupApp', $ip );
		$out['attribution'] = array_map( 'strval', LNKup_Tracking_Links::touch_for( $row ) );
		$id                 = (int) $row['target_id'];
		$key                = (string) $row['target_key'];
		switch ( (string) $row['target_type'] ) {
			case 'event':
				return self::event_visible( $id ) ? array_merge( $out, array( 'route' => 'event', 'id' => $id ) ) : $out;
			case 'opportunity':
				return class_exists( 'LNKup_Public_Embed' ) && LNKup_Public_Embed::visible( $id ) ? array_merge( $out, array( 'route' => 'opportunity', 'id' => $id ) ) : $out;
			case 'tour':
				return '' !== LNKup_Tracking_Links::resolve_url( 'tour', $id, '', false ) ? array_merge( $out, array( 'route' => 'tour', 'id' => $id ) ) : $out;
			case 'organizer':
				$slug = class_exists( 'LNKup_Organizer_Onboarding' ) && '' !== LNKup_Tracking_Links::resolve_url( 'organizer', $id, '', false ) ? (string) LNKup_Organizer_Onboarding::organizer_public_slug( $id ) : '';
				return '' !== $slug ? array_merge( $out, array( 'route' => 'organizer', 'params' => array( 'organizer' => $slug ) ) ) : $out;
			case 'market':
				return array_merge( $out, array( 'params' => self::market_params( $key, '' ) ) );
			case 'category':
				$label = class_exists( 'LNKup_Market_Discovery' ) ? LNKup_Market_Discovery::category_label( sanitize_title( $key ) ) : '';
				return array_merge( $out, array( 'params' => '' !== $label ? array( 'category' => $label ) : array() ) );
			case 'signup':
				return array_merge( $out, array( 'route' => 'signup', 'params' => array( 'role' => 'organizer' === $key ? 'organizer' : 'vendor' ) ) );
		}
		return $out;
	}

	/**
	 * Feed filters for an enabled market (and optional category sub-page).
	 *
	 * @return array<string,string>
	 */
	private static function market_params( string $slug, string $sub ): array {
		$market = '' !== $slug && class_exists( 'LNKup_Market_Pages' ) ? LNKup_Market_Pages::get_market_by_slug( $slug, true ) : null;
		if ( ! is_array( $market ) || '' === (string) $market['city'] ) {
			return array();
		}
		$params = array( 'city' => (string) $market['city'], 'state' => (string) $market['state'] );
		$label  = '' !== $sub && class_exists( 'LNKup_Market_Discovery' ) ? LNKup_Market_Discovery::category_label( $sub ) : '';
		if ( '' !== $label ) {
			$params['category'] = $label;
		}
		return $params;
	}

	/**
	 * Published, not password-protected, and not held back by the authenticity review.
	 */
	public static function event_visible( int $event_id ): bool {
		$post = $event_id ? get_post( $event_id ) : null;
		if ( ! $post || 'lnkup_event' !== $post->post_type || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return false;
		}
		return ! ( class_exists( 'LNKup_Index_Policy' ) && 'authenticity' === LNKup_Index_Policy::event( $event_id )['reason'] );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_event( WP_REST_Request $request ) {
		if ( ! self::active() ) {
			return self::disabled();
		}
		if ( self::rate_limited() ) {
			return new WP_Error( 'lnkup_rate_limited', __( 'Too many requests. Try again later.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$data = self::public_event( (int) $request['id'] );
		if ( null === $data ) {
			return new WP_Error( 'lnkup_not_found', __( 'This event is not available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$response = new WP_REST_Response( array( 'success' => true, 'data' => $data ), 200 );
		$response->header( 'Cache-Control', 'public, max-age=120' );
		return $response;
	}

	/**
	 * Guest-safe event payload.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function public_event( int $event_id ): ?array {
		if ( ! self::event_visible( $event_id ) ) {
			return null;
		}
		$post   = get_post( $event_id );
		$schema = class_exists( 'LNKup_SEO_Schema' ) ? LNKup_SEO_Schema::event( $event_id ) : null;
		$status = (string) get_post_meta( $event_id, '_lnkup_event_status', true );
		$date   = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$end    = (string) get_post_meta( $event_id, '_lnkup_event_end_date', true );
		$last   = preg_match( '/^\d{4}-\d{2}-\d{2}/', $end ) && $end > $date ? substr( $end, 0, 10 ) : substr( $date, 0, 10 );
		$hidden = (bool) get_post_meta( $event_id, '_lnkup_venue_hidden', true );
		$media  = class_exists( 'LNKup_Event_Media' ) ? LNKup_Event_Media::resolve( $event_id ) : array();
		$image  = (string) ( $media['og_url'] ?? '' );
		$org_id = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		$org    = is_array( $schema ) && isset( $schema['organizer']['name'] ) ? (string) $schema['organizer']['name'] : '';

		$state = 'scheduled';
		if ( in_array( $status, array( 'cancelled', 'canceled' ), true ) ) {
			$state = 'cancelled';
		} elseif ( 'postponed' === $status ) {
			$state = 'postponed';
		} elseif ( '' !== $last && $last < current_time( 'Y-m-d' ) ) {
			$state = 'past';
		}

		$opportunities = array();
		if ( 'scheduled' === $state && class_exists( 'LNKup_Public_Embed' ) ) {
			foreach ( self::opportunity_ids( $event_id ) as $opp_id ) {
				$card = LNKup_Public_Embed::payload( $opp_id );
				if ( is_array( $card ) ) {
					$opportunities[] = array(
						'id'           => (int) $card['id'],
						'title'        => (string) $card['title'],
						'status_label' => (string) $card['status_label'],
						'deadline'     => (string) $card['deadline'],
					);
				}
			}
		}

		$external = class_exists( 'LNKup_Organizer_Onboarding' ) && LNKup_Organizer_Onboarding::event_is_unclaimed_external( $event_id );
		return array(
			'id'            => $event_id,
			'title'         => html_entity_decode( function_exists( 'lnkup_plain_title' ) ? lnkup_plain_title( $post ) : get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'           => class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::event_public_url( $event_id ) : (string) get_permalink( $event_id ),
			'start'         => is_array( $schema ) ? (string) $schema['startDate'] : '',
			'end'           => is_array( $schema ) ? (string) ( $schema['endDate'] ?? '' ) : '',
			'date'          => substr( $date, 0, 10 ),
			'state'         => $state,
			'description'   => is_array( $schema ) ? (string) ( $schema['description'] ?? '' ) : '',
			'venue'         => $hidden ? '' : trim( (string) get_post_meta( $event_id, '_lnkup_venue_name', true ) ),
			'city'          => (string) get_post_meta( $event_id, '_lnkup_city', true ),
			'state_code'    => (string) get_post_meta( $event_id, '_lnkup_state', true ),
			'image'         => '' !== $image && function_exists( 'lnkup_seo_is_raster_url' ) && lnkup_seo_is_raster_url( $image ) ? $image : '',
			'organizer'     => array(
				'name' => $external ? '' : $org,
				'slug' => ! $external && $org_id && class_exists( 'LNKup_Organizer_Onboarding' ) ? (string) LNKup_Organizer_Onboarding::organizer_public_slug( $org_id ) : '',
			),
			'external'      => $external,
			'opportunities' => $opportunities,
		);
	}

	/**
	 * @return int[]
	 */
	private static function opportunity_ids( int $event_id ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'lnkup_opportunity',
					'post_status'    => 'publish',
					'posts_per_page' => 20,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'menu_order date',
					'order'          => 'ASC',
					'meta_query'     => array( array( 'key' => '_lnkup_event_id', 'value' => $event_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
	}

	/**
	 * Tracked link for native share. Reuses the caller's active app link for the same target;
	 * falls back to the plain public URL when tracked links are off or the caller cannot target it.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_share_link( WP_REST_Request $request ) {
		$body = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		$type = sanitize_key( (string) ( $body['target_type'] ?? '' ) );
		$id   = absint( $body['target_id'] ?? 0 );
		if ( ! in_array( $type, self::SHARE_TYPES, true ) || ! class_exists( 'LNKup_Tracking_Links' ) ) {
			return new WP_Error( 'lnkup_share_target', __( 'This can not be shared.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$key = LNKup_Tracking_Links::sanitize_key_for( $type, (string) ( $body['target_key'] ?? '' ) );
		$plain = LNKup_Tracking_Links::resolve_url( $type, $id, $key, false );
		if ( '' === $plain ) {
			return new WP_Error( 'lnkup_share_target', __( 'This page is not public yet.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$user = get_current_user_id();
		if ( ! LNKup_Tracking_Links::active() || ! LNKup_Tracking_Links::can_target( $user, $type, $id, $key ) ) {
			return new WP_REST_Response( array( 'success' => true, 'data' => array( 'url' => $plain, 'tracked' => false ) ), 200 );
		}
		$row = self::existing_share_link( $user, $type, $id, $key );
		if ( ! $row ) {
			$created = LNKup_Tracking_Links::create(
				array(
					'owner_user_id'    => $user,
					'owner_type'       => self::owner_type( $user ),
					'target_type'      => $type,
					'target_id'        => $id,
					'target_key'       => $key,
					'label'            => __( 'Shared from the app', 'lnkup-marketplace' ),
					'channel'          => 'app',
					'utm_source'       => 'app_share',
					'utm_medium'       => 'share',
					'forward_referral' => true,
				)
			);
			if ( is_wp_error( $created ) ) {
				return new WP_REST_Response( array( 'success' => true, 'data' => array( 'url' => $plain, 'tracked' => false ) ), 200 );
			}
			$row = $created;
		}
		return new WP_REST_Response( array( 'success' => true, 'data' => array( 'url' => LNKup_Tracking_Links::url( (string) $row['code'] ), 'tracked' => true ) ), 200 );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function existing_share_link( int $user, string $type, int $id, string $key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Marketing_Tables::links() . " WHERE owner_user_id = %d AND channel = 'app' AND status = 'active' AND target_type = %s AND target_id = %d AND target_key = %s ORDER BY id DESC LIMIT 1", $user, $type, $id, $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : null;
	}

	private static function owner_type( int $user ): string {
		if ( class_exists( 'LNKup_Connector' ) && LNKup_Connector::get_by_user_id( $user ) ) {
			return 'connector';
		}
		if ( class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer( $user ) ) {
			return 'organizer';
		}
		return 'vendor';
	}
}
