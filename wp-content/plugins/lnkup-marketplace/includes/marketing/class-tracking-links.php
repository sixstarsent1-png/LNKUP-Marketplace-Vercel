<?php
/**
 * Tracked short links /l/{code} (flag tracking_links).
 *
 * A link stores a target by type + ID (event, opportunity, tour, organizer) or key (market slug,
 * category label, signup role); the redirect URL is resolved at click time from that record, so
 * /l/ can never redirect off-site (no open redirect) and unpublished targets fall back to the
 * public feed. Clicks are counted per day; bots are counted separately and repeat clicks from the
 * same browser on the same day are de-duplicated with a salted hash (no IP is stored).
 *
 * A click records a first-party campaign touch (LNKup_Acquisition_Attribution) and forwards the
 * owner's existing referral code (?ref= / ?org_ref=) so the referral and City Connector programs
 * keep validating and crediting exactly as before. Signups, applications and bookings are counted
 * from the conversion registry rows whose attribution carries the link code.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Tracking_Links {

	const QUERY_VAR = 'lnkup_link';
	const TARGETS   = array( 'event', 'opportunity', 'tour', 'organizer', 'market', 'category', 'signup', 'feed', 'home' );
	const OWNERS    = array( 'organizer', 'connector', 'partner', 'vendor', 'staff' );
	const CHANNELS  = array( 'instagram', 'facebook', 'tiktok', 'x', 'linkedin', 'email', 'sms', 'whatsapp', 'qr', 'print', 'website', 'partner', 'other' );
	const STATUSES  = array( 'active', 'paused', 'archived' );
	const MAX_PER_OWNER = 500;
	const BOT_PATTERN   = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|quora link|whatsapp|telegrambot|discordbot|skypeuripreview|vkshare|pinterest|bitlybot|headless|lighthouse|python|curl|wget|httpclient|okhttp|go-http|java\//i';

	/** Conversion registry names counted per link. */
	const CONVERSIONS = array(
		'CompleteRegistration' => 'signups',
		'SubmitApplication'    => 'applications',
		'Purchase'             => 'bookings',
	);

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'rewrites' ), 22 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 0 );
		add_action( 'lnkup_conversion_recorded', array( __CLASS__, 'on_conversion' ), 20, 1 );
	}

	public static function active(): bool {
		return class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'tracking_links' );
	}

	public static function rewrites(): void {
		if ( self::active() ) {
			add_rewrite_rule( '^l/([A-Za-z0-9]{4,16})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		}
	}

	/**
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function url( string $code ): string {
		return home_url( '/l/' . rawurlencode( $code ) . '/' );
	}

	/* ------------------------------------------------------------ Records */

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Marketing_Tables::links() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get_by_code( string $code ): ?array {
		global $wpdb;
		$code = (string) preg_replace( '/[^A-Za-z0-9]/', '', $code );
		if ( '' === $code ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Marketing_Tables::links() . ' WHERE code = %s', $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : null;
	}

	/**
	 * Whether a user may point a link at this target. Public discovery targets are open to any
	 * link owner; event, opportunity, tour and organizer-profile targets need ownership.
	 */
	public static function can_target( int $user_id, string $type, int $target_id, string $key = '' ): bool {
		if ( $user_id < 1 || ! in_array( $type, self::TARGETS, true ) ) {
			return false;
		}
		if ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return '' !== self::resolve_url( $type, $target_id, $key, false );
		}
		switch ( $type ) {
			case 'event':
				return 'publish' === get_post_status( $target_id ) && ( self::can_promote_event( $target_id, $user_id ) || ( class_exists( 'LNKup_Distribution' ) && LNKup_Distribution::vendor_booked_event( $user_id, $target_id ) ) );
			case 'opportunity':
				$event = (int) get_post_meta( $target_id, '_lnkup_event_id', true );
				return $event > 0 && 'publish' === get_post_status( $target_id ) && self::can_promote_event( $event, $user_id );
			case 'tour':
				return 'publish' === get_post_status( $target_id ) && class_exists( 'LNKup_Tours' ) && LNKup_Tours::owns( $target_id, $user_id );
			case 'organizer':
				return $target_id === $user_id && '' !== self::resolve_url( 'organizer', $target_id, '', false );
			default:
				return '' !== self::resolve_url( $type, $target_id, $key, false );
		}
	}

	/**
	 * Event owner, or a team member with the event-level `broadcast` capability.
	 */
	public static function can_promote_event( int $event_id, int $user_id ): bool {
		if ( ! class_exists( 'LNKup_Capabilities' ) || 'lnkup_event' !== get_post_type( $event_id ) ) {
			return false;
		}
		return LNKup_Capabilities::organizer_owns_event( $event_id, $user_id ) || LNKup_Capabilities::staff_can( $event_id, 'broadcast', $user_id );
	}

	/**
	 * Create a link. Args: owner_user_id, owner_type, target_type, target_id, target_key, label,
	 * channel, campaign, utm_source, utm_medium, utm_campaign, utm_content, forward_referral.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function create( array $args ) {
		global $wpdb;
		$owner = absint( $args['owner_user_id'] ?? 0 );
		$type  = sanitize_key( (string) ( $args['target_type'] ?? '' ) );
		$tid   = absint( $args['target_id'] ?? 0 );
		$key   = self::sanitize_key_for( $type, (string) ( $args['target_key'] ?? '' ) );
		if ( ! self::can_target( $owner, $type, $tid, $key ) ) {
			return new WP_Error( 'lnkup_link_target', __( 'You can only create links to your own published events, tours and profile, or to public LNKup pages.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Marketing_Tables::links() . " WHERE owner_user_id = %d AND status <> 'archived'", $owner ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( $count >= self::MAX_PER_OWNER ) {
			return new WP_Error( 'lnkup_link_limit', __( 'Archive some links before creating more.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$owner_type = in_array( $args['owner_type'] ?? '', self::OWNERS, true ) ? (string) $args['owner_type'] : 'organizer';
		$channel    = in_array( $args['channel'] ?? '', self::CHANNELS, true ) ? (string) $args['channel'] : 'other';
		$text       = static fn( $v, int $len = 100 ): string => substr( sanitize_text_field( (string) $v ), 0, $len );
		$ref        = ! empty( $args['forward_referral'] ) ? self::referral_for( $owner, $owner_type, $type, $key ) : array( '', '' );
		$now        = current_time( 'mysql', true );
		$row        = array(
			'code'          => self::new_code(),
			'target_type'   => $type,
			'target_id'     => $tid,
			'target_key'    => $key,
			'owner_user_id' => $owner,
			'owner_type'    => $owner_type,
			'owner_ref_id'  => absint( $args['owner_ref_id'] ?? 0 ),
			'label'         => $text( $args['label'] ?? '', 120 ),
			'channel'       => $channel,
			'campaign'      => $text( $args['campaign'] ?? '' ),
			'utm_source'    => $text( $args['utm_source'] ?? '' ),
			'utm_medium'    => $text( $args['utm_medium'] ?? '' ),
			'utm_campaign'  => $text( $args['utm_campaign'] ?? '' ),
			'utm_content'   => $text( $args['utm_content'] ?? '' ),
			'ref_param'     => $ref[0],
			'ref_code'      => $ref[1],
			'status'        => 'active',
			'created_at'    => $now,
			'updated_at'    => $now,
		);
		if ( ! $wpdb->insert( LNKup_Marketing_Tables::links(), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'lnkup_link_save', __( 'The link could not be saved. Please try again.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		$row['id'] = (int) $wpdb->insert_id;
		return $row;
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function set_status( int $id, string $status, int $user_id ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row || ! self::user_owns( $row, $user_id ) ) {
			return new WP_Error( 'lnkup_link_not_found', __( 'Link not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'lnkup_link_status', __( 'Unknown status.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$wpdb->update( LNKup_Marketing_Tables::links(), array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) self::get( $id );
	}

	/**
	 * @param array<string,mixed> $row Link.
	 */
	public static function user_owns( array $row, int $user_id ): bool {
		return $user_id > 0 && ( (int) $row['owner_user_id'] === $user_id || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all( $user_id ) ) );
	}

	/**
	 * Links owned by a user, newest first, with lifetime totals.
	 *
	 * @param array<string,mixed> $args status, target_type, target_id, limit.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_for_owner( int $user_id, array $args = array() ): array {
		global $wpdb;
		$where  = array( 'l.owner_user_id = %d' );
		$params = array( $user_id );
		$status = (string) ( $args['status'] ?? '' );
		if ( in_array( $status, self::STATUSES, true ) ) {
			$where[]  = 'l.status = %s';
			$params[] = $status;
		} else {
			$where[] = "l.status <> 'archived'";
		}
		if ( ! empty( $args['target_type'] ) && in_array( $args['target_type'], self::TARGETS, true ) ) {
			$where[]  = 'l.target_type = %s';
			$params[] = (string) $args['target_type'];
		}
		if ( ! empty( $args['target_id'] ) ) {
			$where[]  = 'l.target_id = %d';
			$params[] = absint( $args['target_id'] );
		}
		$params[] = max( 1, min( 200, (int) ( $args['limit'] ?? 100 ) ) );
		$sql      = 'SELECT l.*, COALESCE(SUM(d.clicks),0) AS clicks, COALESCE(SUM(d.unique_clicks),0) AS unique_clicks, COALESCE(SUM(d.signups),0) AS signups, COALESCE(SUM(d.applications),0) AS applications, COALESCE(SUM(d.bookings),0) AS bookings
			FROM ' . LNKup_Marketing_Tables::links() . ' l LEFT JOIN ' . LNKup_Marketing_Tables::link_daily() . ' d ON d.link_id = l.id
			WHERE ' . implode( ' AND ', $where ) . ' GROUP BY l.id ORDER BY l.id DESC LIMIT %d';
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as &$row ) {
			$row['url']        = self::url( (string) $row['code'] );
			$row['target_url'] = self::target_url( $row );
			foreach ( array( 'clicks', 'unique_clicks', 'signups', 'applications', 'bookings' ) as $n ) {
				$row[ $n ] = (int) $row[ $n ];
			}
		}
		return $rows;
	}

	/**
	 * Daily series for links (owner-scoped by the caller).
	 *
	 * @param int[] $link_ids Links.
	 * @return array<int,array<string,int|string>>
	 */
	public static function daily( array $link_ids, string $from, string $to ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $link_ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = 'SELECT day, SUM(clicks) clicks, SUM(unique_clicks) unique_clicks, SUM(signups) signups, SUM(applications) applications, SUM(bookings) bookings FROM ' . LNKup_Marketing_Tables::link_daily() . " WHERE link_id IN ({$in}) AND day BETWEEN %s AND %s GROUP BY day ORDER BY day";
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $ids, array( $from, $to ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ------------------------------------------------------------ Targets */

	private static function sanitize_key_for( string $type, string $key ): string {
		switch ( $type ) {
			case 'market':
				return sanitize_title( $key );
			case 'category':
				return substr( sanitize_text_field( $key ), 0, 100 );
			case 'signup':
				return in_array( $key, array( 'vendor', 'organizer' ), true ) ? $key : 'vendor';
			default:
				return '';
		}
	}

	/**
	 * Internal URL for a target, or '' when it is not public. With $fallback, unpublished
	 * targets resolve to the public feed instead of ''.
	 */
	public static function resolve_url( string $type, int $id, string $key, bool $fallback = true ): string {
		$url  = '';
		$feed = function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'opportunities' ) : home_url( '/opportunities/' );
		switch ( $type ) {
			case 'event':
				if ( 'lnkup_event' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
					$url = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::event_public_url( $id ) : (string) get_permalink( $id );
				}
				break;
			case 'opportunity':
				$event = (int) get_post_meta( $id, '_lnkup_event_id', true );
				if ( 'lnkup_opportunity' === get_post_type( $id ) && 'publish' === get_post_status( $id ) && 'publish' === get_post_status( $event ) ) {
					$base = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::event_public_url( $event ) : (string) get_permalink( $event );
					$url  = $base ? $base . '#opp-' . $id : '';
				}
				break;
			case 'tour':
				if ( 'lnkup_tour' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
					$url = (string) get_permalink( $id );
				}
				break;
			case 'organizer':
				$user = get_userdata( $id );
				if ( $user && class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer( $id ) && class_exists( 'LNKup_Organizer_Onboarding' ) ) {
					$url = LNKup_Organizer_Onboarding::organizer_public_url( $id );
				}
				break;
			case 'market':
				if ( class_exists( 'LNKup_Market_Pages' ) && LNKup_Market_Pages::get_market_by_slug( $key ) ) {
					$url = LNKup_Market_Pages::market_url( $key );
				}
				break;
			case 'category':
				if ( '' !== $key && function_exists( 'lnkup_canonical_vendor_categories' ) && in_array( $key, lnkup_canonical_vendor_categories(), true ) ) {
					$url = add_query_arg( 'categories', $key, $feed );
				}
				break;
			case 'signup':
				$base = function_exists( 'lnkup_registration_url' ) ? lnkup_registration_url() : wp_registration_url();
				$url  = add_query_arg( 'role', 'organizer' === $key ? 'organizer' : 'vendor', $base );
				break;
			case 'feed':
				$url = $feed;
				break;
			case 'home':
				$url = home_url( '/' );
				break;
		}
		if ( '' !== $url && ! wp_validate_redirect( $url, '' ) ) {
			$url = '';
		}
		if ( '' === $url && $fallback ) {
			$url = in_array( $type, array( 'signup', 'home' ), true ) ? home_url( '/' ) : $feed;
		}
		return $url;
	}

	/**
	 * Final redirect for a link: target + forwarded referral code.
	 *
	 * @param array<string,mixed> $row Link.
	 */
	public static function target_url( array $row ): string {
		$url = self::resolve_url( (string) $row['target_type'], (int) $row['target_id'], (string) $row['target_key'] );
		if ( '' !== (string) $row['ref_param'] && '' !== (string) $row['ref_code'] && in_array( $row['ref_param'], array( 'ref', 'org_ref' ), true ) ) {
			$hash = '';
			if ( false !== strpos( $url, '#' ) ) {
				list( $url, $hash ) = explode( '#', $url, 2 );
				$hash = '#' . $hash;
			}
			$url = add_query_arg( (string) $row['ref_param'], rawurlencode( (string) $row['ref_code'] ), $url ) . $hash;
		}
		return $url;
	}

	/**
	 * Existing referral code to forward, as [param, code]. Never creates program enrollment.
	 *
	 * @return string[]
	 */
	private static function referral_for( int $owner, string $owner_type, string $type, string $key ): array {
		if ( in_array( $owner_type, array( 'connector', 'partner' ), true ) && class_exists( 'LNKup_Connector' ) ) {
			$connector = LNKup_Connector::get_by_user_id( $owner );
			$code      = $connector ? LNKup_Connector::get_code_for_connector( (int) $connector['id'] ) : null;
			return $code && ! empty( $code['code'] ) ? array( 'ref', (string) $code['code'] ) : array( '', '' );
		}
		if ( 'signup' === $type && 'organizer' === $key && class_exists( 'LNKup_Organizer_Recruit_Referrals' ) && LNKup_Organizer_Recruit_Referrals::is_enabled() ) {
			$code = LNKup_Organizer_Recruit_Referrals::get_or_create_code( $owner );
			return $code && ! empty( $code['code'] ) ? array( 'org_ref', (string) $code['code'] ) : array( '', '' );
		}
		return array( '', '' );
	}

	private static function new_code(): string {
		global $wpdb;
		for ( $i = 0; $i < 8; $i++ ) {
			$code = strtolower( wp_generate_password( 8, false, false ) );
			$code = (string) preg_replace( '/[^a-z0-9]/', '', $code );
			if ( strlen( $code ) >= 6 && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Marketing_Tables::links() . ' WHERE code = %s', $code ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				return $code;
			}
		}
		return substr( md5( uniqid( '', true ) ), 0, 10 );
	}

	/* ------------------------------------------------------------ Clicks */

	public static function is_bot( string $ua ): bool {
		return '' === trim( $ua ) || (bool) preg_match( self::BOT_PATTERN, $ua );
	}

	public static function handle(): void {
		$code = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $code ) {
			return;
		}
		$row = self::active() ? self::get_by_code( $code ) : null;
		if ( ! $row || 'active' !== $row['status'] ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		self::count_click( $row, $ua, $ip );
		if ( ! self::is_bot( $ua ) && class_exists( 'LNKup_Acquisition_Attribution' ) ) {
			LNKup_Acquisition_Attribution::record_touch( self::touch_for( $row ) );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		wp_safe_redirect( self::target_url( $row ), 302, 'LNKup' );
		exit;
	}

	/**
	 * Attribution touch for a link click.
	 *
	 * @param array<string,mixed> $row Link.
	 * @return array<string,mixed>
	 */
	public static function touch_for( array $row ): array {
		$touch = array(
			'utm_source'   => '' !== (string) $row['utm_source'] ? $row['utm_source'] : ( '' !== (string) $row['channel'] ? $row['channel'] : 'lnkup' ),
			'utm_medium'   => '' !== (string) $row['utm_medium'] ? $row['utm_medium'] : 'tracked_link',
			'utm_campaign' => '' !== (string) $row['utm_campaign'] ? $row['utm_campaign'] : ( '' !== (string) $row['campaign'] ? $row['campaign'] : (string) $row['code'] ),
			'utm_content'  => $row['utm_content'],
			'lnk'          => $row['code'],
			'landing'      => (string) wp_parse_url( self::resolve_url( (string) $row['target_type'], (int) $row['target_id'], (string) $row['target_key'] ), PHP_URL_PATH ),
		);
		if ( 'market' === $row['target_type'] ) {
			$touch['market'] = $row['target_key'];
		}
		if ( '' !== (string) $row['ref_param'] ) {
			$touch[ (string) $row['ref_param'] ] = $row['ref_code'];
		}
		return array_filter( $touch, static fn( $v ) => '' !== (string) $v );
	}

	/**
	 * @param array<string,mixed> $row Link.
	 */
	public static function count_click( array $row, string $ua, string $ip ): void {
		global $wpdb;
		$day   = current_time( 'Y-m-d' );
		$table = LNKup_Marketing_Tables::link_daily();
		if ( self::is_bot( $ua ) ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (link_id, day, bot_clicks) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE bot_clicks = bot_clicks + 1", (int) $row['id'], $day ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}
		$hash   = 'lnkup_lc_' . substr( hash_hmac( 'sha256', (int) $row['id'] . '|' . $ip . '|' . $ua . '|' . $day, wp_salt( 'nonce' ) ), 0, 32 );
		$unique = false === get_transient( $hash );
		if ( $unique ) {
			set_transient( $hash, 1, DAY_IN_SECONDS );
		}
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (link_id, day, clicks, unique_clicks) VALUES (%d, %s, 1, %d) ON DUPLICATE KEY UPDATE clicks = clicks + 1, unique_clicks = unique_clicks + VALUES(unique_clicks)", (int) $row['id'], $day, $unique ? 1 : 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count signups / applications / bookings for the link in the conversion's attribution
	 * (last touch first, then first touch).
	 *
	 * @param array<string,mixed> $row Conversion row.
	 */
	public static function on_conversion( $row ): void {
		global $wpdb;
		$name = is_array( $row ) ? (string) ( $row['event_name'] ?? '' ) : '';
		if ( ! isset( self::CONVERSIONS[ $name ] ) || empty( $row['attribution_json'] ) ) {
			return;
		}
		$attr = json_decode( (string) $row['attribution_json'], true );
		$code = is_array( $attr ) ? (string) ( $attr['lt']['lnk'] ?? $attr['ft']['lnk'] ?? '' ) : '';
		$link = '' !== $code ? self::get_by_code( $code ) : null;
		if ( ! $link ) {
			return;
		}
		$col   = self::CONVERSIONS[ $name ];
		$table = LNKup_Marketing_Tables::link_daily();
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (link_id, day, {$col}) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE {$col} = {$col} + 1", (int) $link['id'], current_time( 'Y-m-d' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
